/*
 * Procedura stocata ImportProd (import produse/grupe de pe serverul extern).
 *
 * SURSA UNICA: fisierul acesta este aplicat automat (CREATE OR ALTER) de
 * aplicatie inainte de fiecare import - vezi ensureImportProdProcedure() din
 * api/import_common.php si import_server_grp_prod.py. Poate fi rulat si manual
 * in SSMS, ca un singur batch.
 *
 * Fata de versiunea veche:
 *   - @user / @PassWord sunt nvarchar(128): parola nu mai este trunchiata la 10
 *     caractere de catre parametrul procedurii (linked server login esuat);
 *   - linked server-ul primeste mapping EXPLICIT (user/parola, sau Windows auth
 *     cand @windowsAuth = 1) si "data access" = true - altfel conexiunea e
 *     anonima si tabelele din baza remote nu sunt vizibile (eroare 7314);
 *   - linked server-ul se creeaza incercand providerii moderni (MSOLEDBSQL19,
 *     apoi MSOLEDBSQL) cu provider string-ul
 *     Encrypt=no;TrustServerCertificate=Yes, verificati printr-o interogare de
 *     proba; acestia valideaza implicit certificatul serverului remote, care
 *     este self-signed -> 7412 "Client unable to establish connection" /
 *     "certificate chain was issued by an authority that is not trusted". Daca niciun driver modern nu este disponibil se foloseste forma
 *     clasica (providerul implicit al instantei). Providerul folosit se
 *     intoarce in rezultat ca ls_provider;
 *   - SET XACT_ABORT ON + CATCH fara usp_GetErrorInfo, ca eroarea reala sa fie
 *     raportata (nu 3930 de la COMMIT / de la INSERT-ul in ERRORLOG);
 *   - daca @IP este chiar instanta locala (ex. statia POS1, care gazduieste
 *     DataRON), NU se creeaza linked server - SQL Server refuza un linked
 *     server cu numele serverului local (eroarea 15028) - si se citeste
 *     direct din [<DataBaseName>].dbo.*.
 */
CREATE OR ALTER PROCEDURE [dbo].[ImportProd] 
	-- Add the parameters for the stored procedure here
	@IP nvarchar(128) = 'localhost',
	@user nvarchar(128) = 'sa',
	@PassWord nvarchar(128) = N'',
	@windowsAuth bit = 0
	
AS
BEGIN
	-- SET NOCOUNT ON added to prevent extra result sets from
	-- interfering with SELECT statements.
	SET NOCOUNT ON;
	-- O eroare anuleaza tranzactia (nu o lasa in stare "doomed", care la COMMIT
	-- iese ca eroare 3930 in loc de cauza reala).
	SET XACT_ABORT ON;

	-- Tabele de mapare server -> local (create daca lipsesc). Sunt folosite in
	-- SQL-ul dinamic de mai jos pentru a traduce tblProd.KP si tblProd.Sectie
	-- din valorile serverului in valorile locale (fallback: valoarea serverului).
	IF OBJECT_ID('dbo.tblMapKP','U') IS NULL
		CREATE TABLE dbo.tblMapKP (KPServer int NOT NULL CONSTRAINT PK_tblMapKP PRIMARY KEY, KPLocal int NOT NULL);
	IF OBJECT_ID('dbo.tblMapSectii','U') IS NULL
		CREATE TABLE dbo.tblMapSectii (SectieServer int NOT NULL CONSTRAINT PK_tblMapSectii PRIMARY KEY, SectieLocal int NOT NULL);

    -- Insert statements for procedure here
	Declare @Database as nvarchar(50)
	Declare @StatieID as int=1
	-- Varianta de linked server folosita (intoarsa in rezultat ca ls_provider /
	-- ls_provstr si inclusa in mesajul de eroare). Ramane NULL cand sursa este
	-- chiar instanta locala (nu se creeaza linked server).
	Declare @lsProvider nvarchar(128) = NULL
	Declare @lsProvstr nvarchar(400) = NULL
	SELECT @Database = 'SourceDB'
    DECLARE	@return_value int=0
    -- MagID poate contine una sau mai multe magazii separate prin virgula (ex. '1,2').
    -- Se filtreaza importul cu IN (@MagFilter), iar la suprapuneri castiga primul
    -- MagID din lista (vezi ordonarea prin CHARINDEX de mai jos).
    Declare @MagIDRaw nvarchar(200) = ''
    Declare @MagList  nvarchar(400) = ''
    Declare @MagFilter nvarchar(420) = ''
    Declare @i int = 1
    Declare @ch nchar(1)
	Select @MagIDRaw = LTRIM(RTRIM(ISNULL(Value,''))) From tblSet Where Setting = 'MagID'
    While @i <= LEN(@MagIDRaw)
    Begin
        Set @ch = SUBSTRING(@MagIDRaw, @i, 1)
        If @ch Like '[0-9]' Or @ch = ',' Set @MagList = @MagList + @ch
        Set @i = @i + 1
    End
    While CHARINDEX(',,', @MagList) > 0 Set @MagList = REPLACE(@MagList, ',,', ',')
    While LEN(@MagList) > 0 And LEFT(@MagList, 1) = ',' Set @MagList = SUBSTRING(@MagList, 2, LEN(@MagList))
    While LEN(@MagList) > 0 And RIGHT(@MagList, 1) = ',' Set @MagList = LEFT(@MagList, LEN(@MagList) - 1)
    If @MagList = '' Set @MagList = '0'
    Set @MagFilter = '(' + @MagList + ')'
    -- Ordonare pentru deduplicarea produselor: castiga MagID-ul cel mai din
    -- stanga in lista. tblPozGrupe nu se filtreaza dupa MagID, deci nu are nevoie
    -- de aceasta ordonare (foloseste MagID crescator).
    Declare @Q char(1) = CHAR(39)
    Declare @MagCsv nvarchar(402) = ',' + @MagList + ','
    Declare @OrderPv nvarchar(300) = 'CHARINDEX(' + @Q + ',' + @Q + ' + CAST(pv0.MagID AS varchar(10)) + ' + @Q + ',' + @Q + ', ' + @Q + @MagCsv + @Q + ')'
    Select @StatieID = CAST(Value AS int) From tblSet Where Setting = 'NrLogic'
    
	Select @Database = DataBaseName FROM tblConectare WHERE ID=1

	-- Sursa poate fi chiar instanta locala (pe statia care gazduieste baza
	-- importata, ex. POS1 cu DataRON). Atunci NU se creeaza linked server:
	-- SQL Server refuza un linked server cu numele serverului local (eroarea
	-- 15028 "The server ... already exists"), iar citirea se face direct din
	-- [<DataBaseName>].dbo.*.
	Set @IP = LTRIM(RTRIM(@IP))
	Declare @isLocal bit = 0
	Declare @Prefix nvarchar(300)
	Declare @localName nvarchar(128) = CAST(SERVERPROPERTY('ServerName') AS nvarchar(128))
	Declare @localMachine nvarchar(128) = CAST(SERVERPROPERTY('MachineName') AS nvarchar(128))
	Declare @localInstance nvarchar(128) = CAST(SERVERPROPERTY('InstanceName') AS nvarchar(128))
	IF @IP = @localName
	   OR @IP = @localMachine
	   OR @IP IN ('localhost', '.', '(local)')
	   OR (@localInstance IS NOT NULL AND @IP = @localMachine + N'\' + @localInstance)
		Set @isLocal = 1

	IF @isLocal = 1
		Set @Prefix = QUOTENAME(@Database) + '.'
	ELSE
	BEGIN
		IF EXISTS (SELECT 1 FROM sys.servers WHERE server_id <> 0 AND name = @IP)
			EXEC master.dbo.sp_dropserver @server=@IP, @droplogins='droplogins'

		-- Linked server-ul se creeaza incercand, in ordine, providerii moderni
		-- (OLE DB Driver 19, apoi 18) si, in final, forma clasica (providerul
		-- implicit al instantei, ex. SQLNCLI/SQLOLEDB). La providerii moderni
		-- trimitem si un provider string: acestia au Encrypt=Mandatory implicit
		-- si valideaza certificatul serverului remote, care este self-signed ->
		-- 7412 "Client unable to establish connection" / SSL Provider "The
		-- certificate chain was issued by an authority that is not trusted".
		-- Provider string-ul se poate trimite DOAR in forma generica: cu
		-- @srvproduct = 'SQL Server' SQL Server raspunde 15428 "You cannot specify
		-- a provider or any properties for product 'SQL Server'". Optiunile sunt
		-- aceleasi ca la restul aplicatiei (import_server_grp_prod.py,
		-- sync-service): certificat nevalidat, criptare doar daca serverul o cere.
		-- Se foloseste Encrypt=no (nu Encrypt=Optional): driverul 18 accepta doar
		-- no/yes, iar driverul 19 mapeaza no -> Optional, deci comportamentul
		-- dorit (Optional) este acelasi pe ambele generatii.
		-- Fiecare varianta este verificata printr-o interogare de proba; daca
		-- providerul nu este instalat/incarcabil (7399/7403/7302/7303) se trece
		-- mai departe, altfel varianta este pastrata (eventuala eroare reala -
		-- login, retea - apare la import, cu mesajul ei). Ultima varianta este
		-- forma clasica, adica exact comportamentul de dinainte, folosita si cand
		-- niciun driver modern nu este disponibil (acolo nu exista problema
		-- certificatului). Providerul folosit se intoarce in rezultat (ls_provider)
		-- si ajunge in logul importului.
		Declare @lsForms table (Id int identity(1,1) primary key, Provider nvarchar(128) NULL)
		Insert Into @lsForms (Provider) Values (N'MSOLEDBSQL19'), (N'MSOLEDBSQL'), (NULL)

		Declare @lsIdx int = 0
		Declare @lsTry nvarchar(128) = NULL
		Declare @probeSql nvarchar(600)
		Declare @probeErr int
		Declare @probeMsg nvarchar(4000)

		While 1 = 1
		Begin
			Set @lsIdx = @lsIdx + 1
			Select @lsTry = Provider From @lsForms Where Id = @lsIdx
			If @@ROWCOUNT = 0 Break

			If EXISTS (SELECT 1 FROM sys.servers WHERE server_id <> 0 And name = @IP)
				EXEC master.dbo.sp_dropserver @server=@IP, @droplogins='droplogins'

			If @lsTry Is NULL
				EXEC master.dbo.sp_addlinkedserver @server = @IP, @srvproduct = N'SQL Server';
			Else
				EXEC master.dbo.sp_addlinkedserver @server = @IP, @srvproduct = N'',
					@provider = @lsTry, @datasrc = @IP,
					@provstr = N'Encrypt=no;TrustServerCertificate=Yes';

			-- Mapping explicit: fara el SQL Server foloseste contextul contului
			-- serviciului (login anonim pe serverul remote), iar tabelele nu sunt
			-- vizibile si interogarile esueaza cu 7314 ("does not contain the
			-- table ...").
			If @windowsAuth = 1
				EXEC master.dbo.sp_addlinkedsrvlogin @rmtsrvname = @IP, @useself = 'TRUE', @locallogin = NULL;
			Else
				EXEC master.dbo.sp_addlinkedsrvlogin @rmtsrvname = @IP, @useself = 'FALSE', @locallogin = NULL,
					@rmtuser = @user, @rmtpassword = @PassWord;

			-- "data access" trebuie activat inainte de proba (OPENQUERY e o
			-- interogare distribuita).
			EXEC master.dbo.sp_serveroption @server = @IP, @optname = 'data access', @optvalue = 'true';

			Set @probeErr = NULL
			Set @probeMsg = NULL
			Begin Try
				Set @probeSql = N'SELECT 1 AS ok FROM OPENQUERY(' + QUOTENAME(@IP) + N', ''SELECT 1 AS ok'')';
				Exec (@probeSql);
			End Try
			Begin Catch
				Set @probeErr = ERROR_NUMBER()
				Set @probeMsg = ERROR_MESSAGE()
			End Catch

			-- Providerul nu poate fi incarcat sau nu accepta provider string-ul:
			-- trecem la varianta urmatoare (ultima este forma clasica).
			If (@probeErr Is Not Null And @probeErr In (7399, 7403, 7302, 7303))
			   Or @probeMsg Like '%Invalid value specified for connection string attribute%'
				Continue

			Set @lsProvider = @lsTry
			If @lsTry Is Not Null
				Set @lsProvstr = N'Encrypt=no;TrustServerCertificate=Yes'
			Break
		End

		-- Niciun driver modern acceptat: linked server-ul a ramas in forma
		-- clasica (providerul implicit al instantei).
		If @lsProvider Is Null Set @lsProvider = N'(provider implicit)'

		Set @Prefix = QUOTENAME(@IP) + '.' + QUOTENAME(@Database) + '.'
	END
	BEGIN TRY
		BEGIN TRANSACTION
		/*
		Delete From tblFP
		
		EXECUTE('INSERT INTO tblFP
		( FPID, Denumire, Status, Poz) SELECT CAST(tblTIB.TIB AS int), tblTIB.Denumire, 1, CAST(tblTIB.TIB AS int) + 1 
		FROM ' + @Prefix + 'dbo.tblTIB')
		*/

		delete from tblGrp
		
		-- O grupa se importa daca are cel putin un produs eligibil (MagID-urile
		-- alese, ProdID > 9, KP > 0, Poz intre 1 si 50) si are o pozitie de meniu
		-- intre 1 si 50. Prioritate: tblGrp; daca grupa nu e eligibila in tblGrp
		-- (Poz in afara intervalului 1..50), se cauta in tblPozGrupe; daca nu e
		-- eligibila in niciuna, grupa nu se importa.
		EXECUTE('INSERT INTO tblGrp
		( NrGrp, Denumire, BackColor, Bold, FontColor, FontSize, FontType, Poz, Tint )
		SELECT DISTINCT g.NrGrp, g.Denumire,
			CASE WHEN g.Poz Between 1 And 50 THEN g.BackColor ELSE pg.BackColor END,
			CASE WHEN g.Poz Between 1 And 50 THEN ISNULL(g.Bold,0) ELSE ISNULL(pg.Bold,0) END,
			CASE WHEN g.Poz Between 1 And 50 THEN g.FontColor ELSE pg.FontColor END,
			CASE WHEN g.Poz Between 1 And 50 THEN g.FontSize ELSE pg.FontSize END,
			CASE WHEN g.Poz Between 1 And 50 THEN g.FontType ELSE pg.FontType END,
			CASE WHEN g.Poz Between 1 And 50 THEN g.Poz ELSE pg.Poz END,
			CASE WHEN g.Poz Between 1 And 50 THEN ISNULL(g.Tint,1) ELSE ISNULL(pg.Tint,1) END
		FROM ' + @Prefix + 'dbo.tblGrp g
			-- tblPozGrupe NU se filtreaza dupa MagID: se ia cate un rand per grupa
			-- (cel cu MagID cel mai mic), ca layout-ul de grupe sa fie disponibil
			-- indiferent de magazia aleasa.
			LEFT JOIN (
				SELECT pg0.*, ROW_NUMBER() OVER (PARTITION BY pg0.NrGrp ORDER BY pg0.MagID) AS rn
				FROM ' + @Prefix + 'dbo.tblPozGrupe pg0
			) pg ON g.NrGrp = pg.NrGrp AND pg.rn = 1
			INNER JOIN ' + @Prefix + 'dbo.tblProd p ON g.NrGrp = p.NrGrp
			INNER JOIN (
				SELECT pv0.*, ROW_NUMBER() OVER (PARTITION BY pv0.ProdID ORDER BY ' + @OrderPv + ') AS rn
				FROM ' + @Prefix + 'dbo.tblPV pv0
				WHERE pv0.MagID IN ' + @MagFilter + '
			) pv ON p.ProdID = pv.ProdID AND pv.rn = 1
					WHERE p.ProdID > 9 AND p.KP > 0 AND pv.Poz Between 1 And 50
						AND (g.Poz Between 1 And 50 OR pg.Poz Between 1 And 50)')
		
		/*
		Delete  from tblOsp
		EXECUTE('INSERT INTO tblOsp
		( Parola, Nume, Blocat, Expl, NrOsp )
		SELECT UserID, Nume, Blocat, Functia, UtilizatorId
		FROM ' + @Prefix + 'dbo.tblUtilizatori')
		*/
		--Start Meniul Zilei
		
		--End Meniul Zilei
		Delete  from tblProd 
		EXECUTE('INSERT INTO tblProd
			( ProdID, BarCod, Denumire, UM, IRP, IIVCN, DataUM, NrGrp, KP, BackColor, FontColor, FontSize, Bold, Poz, FontType, PV, Nr_TVA, MZ, MagID, Sectie )
		SELECT p.ProdID, p.BarCod, p.Denumire, p.UM, p.IRP, TRY_CAST(p.IIVCN AS int), p.DataUM, p.NrGrp, COALESCE(mk.KPLocal, p.KP),
		pv.BackColor, pv.FontColor, p.FontSize, pv.Bold, pv.Poz, p.FontType, pv.PV,
		CASE p.TVA WHEN 21 THEN 1 WHEN 11 THEN 2 ELSE 3 END, pv.Flag, pv.MagID, COALESCE(ms.SectieLocal, p.Sectie)
		FROM ' + @Prefix + 'dbo.tblProd p
		INNER JOIN (
			SELECT pv0.*, ROW_NUMBER() OVER (PARTITION BY pv0.ProdID ORDER BY ' + @OrderPv + ') AS rn
			FROM ' + @Prefix + 'dbo.tblPV pv0
			WHERE pv0.MagID IN ' + @MagFilter + '
		) pv ON p.ProdID = pv.ProdID AND pv.rn = 1
		LEFT JOIN dbo.tblMapKP mk ON mk.KPServer = p.KP
		LEFT JOIN dbo.tblMapSectii ms ON ms.SectieServer = p.Sectie
		WHERE p.ProdID>9 AND p.KP>0 AND pv.Poz<=50 and pv.Poz > 0')

		EXECUTE('INSERT INTO tblProd
			( ProdID, BarCod, Denumire, UM, IRP, IIVCN, DataUM, NrGrp, KP, BackColor, FontColor, FontSize, Bold, Poz, FontType, PV, Nr_TVA, MagID, Sectie )
		SELECT p.ProdID, p.BarCod1, p.Denumire, p.UM, p.IRP, TRY_CAST(p.IIVCN AS int), p.DataUM, p.NrGrp, COALESCE(mk.KPLocal, p.KP),
		pv.BackColor, pv.FontColor, p.FontSize, pv.Bold, pv.Poz, p.FontType, pv.PV,
		CASE p.TVA WHEN 21 THEN 1 WHEN 11 THEN 2 ELSE 3 END, pv.MagID, COALESCE(ms.SectieLocal, p.Sectie)
		FROM ' + @Prefix + 'dbo.tblProd p
		INNER JOIN (
			SELECT pv0.*, ROW_NUMBER() OVER (PARTITION BY pv0.ProdID ORDER BY ' + @OrderPv + ') AS rn
			FROM ' + @Prefix + 'dbo.tblPV pv0
			WHERE pv0.MagID IN ' + @MagFilter + '
		) pv ON p.ProdID = pv.ProdID AND pv.rn = 1
		LEFT JOIN dbo.tblMapKP mk ON mk.KPServer = p.KP
		LEFT JOIN dbo.tblMapSectii ms ON ms.SectieServer = p.Sectie
		WHERE p.BarCod1=''REDUCERE'' ')


		/*
		DELETE FROM tblRep

		EXECUTE('INSERT INTO tblRep([RepID],[Denumire] ,[Tip] ,[Codf] ,[Localit] ,[Tel1],[Tel2],
      [Denumire2],[Judetul] ,[Contul],[Banca],[PersContact] ,[ValTarif]  ,[DelegatID] ,[DataFiscalizare],[Contract],[SoldI] ,[DataSI] ,[DataScd] ,[NrGrp] ,[NumePrenume] ,[Seria] ,[Nr] ,[Eliberat],
		[Transport] ,[NrAuto] ,[CNP] ,[CodFiscal] ,[email] ,[password] ,[zile_scadenta] ,[CodSaga] ,[UserID] ,[DataAdaugare] ,[Status] ,[Tip_juridic] )
			SELECT [RepID],[Denumire] ,[Tip] ,[Codf] ,[Localit] ,[Tel1],[Tel2],
      [Denumire2],[Judetul] ,[Contul],[Banca],[PersContact] ,[ValTarif]  ,[DelegatID] ,[DataFiscalizare],[Contract],[SoldI] ,[DataSI] ,[DataScd] ,[NrGrp] ,[NumePrenume] ,[Seria] ,[Nr] ,[Eliberat],
		[Transport] ,[NrAuto] ,[CNP] ,[CodFiscal] ,[email] ,[password] ,[zile_scadenta] ,[CodSaga] ,[UserID] ,[DataAdaugare] ,[Status] ,[Tip_juridic]  
		FROM ' + @Prefix + 'dbo.tblRep WHERE tblRep.Tip=''c''')

		DELETE FROM trelRepProd

		EXECUTE('INSERT INTO trelRepProd([Id],[RepID] ,[ProdID] ,[PVNet] ,[PV] )
		SELECT [Id],[RepID] ,[ProdID] ,[PVNet] ,[PV]
		FROM ' + @Prefix + 'dbo.trelRepProd')
		
		TRUNCATE TABLE  trelRepCarduri

		EXECUTE('INSERT INTO trelRepCarduri([Id],[RepID] ,[CodCard] )
		SELECT [Id],[RepID] ,[CodCard] 
		FROM ' + @Prefix + 'dbo.trelRepCarduri')
		*/

		SELECT	'Return_Value' = @return_value,
			'ls_provider' = IsNull(@lsProvider, N'(instanta locala)'),
			'ls_provstr' = @lsProvstr
		COMMIT TRANSACTION
	END TRY
	BEGIN CATCH
		-- Eroare: anulam intotdeauna tranzactia, ca sa nu comitem stergeri partiale
		-- (fara EXECUTE usp_GetErrorInfo: INSERT-ul lui in ERRORLOG ar pica
		--  primul cu 3930 intr-o tranzactie "doomed" si ar ascunde cauza)
		Declare @catchMsg nvarchar(2048) = ERROR_MESSAGE()
		IF (XACT_STATE()) <> 0
			ROLLBACK TRANSACTION;
		-- Punem in mesaj si varianta de linked server folosita, ca diagnoza sa
		-- fie posibila direct din logul importului (logs/import_server_grp_prod.log
		-- sau mesajul din Sincronizare produse).
		IF @lsProvider Is Not Null
			Set @catchMsg = N'Linked server: ' + @lsProvider
				+ IsNull(N' (' + @lsProvstr + N')', N'')
				+ N'. Eroare: ' + @catchMsg
		;THROW 50000, @catchMsg, 1;
	END CATCH
	--SELECT @ipServer, @p2
	
END
