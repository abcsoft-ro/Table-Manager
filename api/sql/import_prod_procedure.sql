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
 *   - SET XACT_ABORT ON + CATCH fara usp_GetErrorInfo, ca eroarea reala sa fie
 *     raportata (nu 3930 de la COMMIT / de la INSERT-ul in ERRORLOG).
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
	IF  EXISTS (SELECT srv.name FROM sys.servers srv WHERE srv.server_id != 0 AND srv.name =@IP) EXEC master.dbo.sp_dropserver @server=@IP, @droplogins='droplogins'
	/*
	EXEC sp_addlinkedserver @server=@IP
	EXEC sp_addlinkedsrvlogin @IP, 'false', NULL, @user, @PassWord
	*/
	IF NOT EXISTS (SELECT 1 FROM sys.servers WHERE server_id <> 0 AND name = @IP)
		EXEC master.dbo.sp_addlinkedserver @server = @IP, @srvproduct = N'SQL Server';

	-- Mapping explicit: fara el SQL Server foloseste contextul contului serviciului
	-- (login anonim pe serverul remote), iar tabelele nu sunt vizibile si
	-- interogarile esueaza cu 7314 ("does not contain the table ...").
	IF @windowsAuth = 1
		EXEC master.dbo.sp_addlinkedsrvlogin @rmtsrvname = @IP, @useself = 'TRUE', @locallogin = NULL;
	ELSE
		EXEC master.dbo.sp_addlinkedsrvlogin @rmtsrvname = @IP, @useself = 'FALSE', @locallogin = NULL,
			@rmtuser = @user, @rmtpassword = @PassWord;

	EXEC master.dbo.sp_serveroption @server = @IP, @optname = 'data access', @optvalue = 'true';
	BEGIN TRY
		BEGIN TRANSACTION
		/*
		Delete From tblFP
		
		EXECUTE('INSERT INTO tblFP
		( FPID, Denumire, Status, Poz) SELECT CAST(tblTIB.TIB AS int), tblTIB.Denumire, 1, CAST(tblTIB.TIB AS int) + 1 
		FROM ['+@IP+'].'+ @Database + '.dbo.tblTIB')
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
		FROM ['+@IP+'].'+ @Database + '.dbo.tblGrp g
			-- tblPozGrupe NU se filtreaza dupa MagID: se ia cate un rand per grupa
			-- (cel cu MagID cel mai mic), ca layout-ul de grupe sa fie disponibil
			-- indiferent de magazia aleasa.
			LEFT JOIN (
				SELECT pg0.*, ROW_NUMBER() OVER (PARTITION BY pg0.NrGrp ORDER BY pg0.MagID) AS rn
				FROM ['+@IP+'].'+ @Database + '.dbo.tblPozGrupe pg0
			) pg ON g.NrGrp = pg.NrGrp AND pg.rn = 1
			INNER JOIN ['+@IP+'].' + @Database + '.dbo.tblProd p ON g.NrGrp = p.NrGrp
			INNER JOIN (
				SELECT pv0.*, ROW_NUMBER() OVER (PARTITION BY pv0.ProdID ORDER BY ' + @OrderPv + ') AS rn
				FROM ['+@IP+'].' + @Database + '.dbo.tblPV pv0
				WHERE pv0.MagID IN ' + @MagFilter + '
			) pv ON p.ProdID = pv.ProdID AND pv.rn = 1
					WHERE p.ProdID > 9 AND p.KP > 0 AND pv.Poz Between 1 And 50
						AND (g.Poz Between 1 And 50 OR pg.Poz Between 1 And 50)')
		
		/*
		Delete  from tblOsp
		EXECUTE('INSERT INTO tblOsp
		( Parola, Nume, Blocat, Expl, NrOsp )
		SELECT UserID, Nume, Blocat, Functia, UtilizatorId
		FROM ['+@IP+'].' + @Database + '.dbo.tblUtilizatori')
		*/
		--Start Meniul Zilei
		
		--End Meniul Zilei
		Delete  from tblProd 
		EXECUTE('INSERT INTO tblProd
			( ProdID, BarCod, Denumire, UM, IRP, IIVCN, DataUM, NrGrp, KP, BackColor, FontColor, FontSize, Bold, Poz, FontType, PV, Nr_TVA, MZ, MagID, Sectie )
		SELECT p.ProdID, p.BarCod, p.Denumire, p.UM, p.IRP, TRY_CAST(p.IIVCN AS int), p.DataUM, p.NrGrp, COALESCE(mk.KPLocal, p.KP),
		pv.BackColor, pv.FontColor, p.FontSize, pv.Bold, pv.Poz, p.FontType, pv.PV,
		CASE p.TVA WHEN 21 THEN 1 WHEN 11 THEN 2 ELSE 3 END, pv.Flag, pv.MagID, COALESCE(ms.SectieLocal, p.Sectie)
		FROM ['+@IP+'].' + @Database + '.dbo.tblProd p
		INNER JOIN (
			SELECT pv0.*, ROW_NUMBER() OVER (PARTITION BY pv0.ProdID ORDER BY ' + @OrderPv + ') AS rn
			FROM ['+@IP+'].' + @Database + '.dbo.tblPV pv0
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
		FROM ['+@IP+'].' + @Database + '.dbo.tblProd p
		INNER JOIN (
			SELECT pv0.*, ROW_NUMBER() OVER (PARTITION BY pv0.ProdID ORDER BY ' + @OrderPv + ') AS rn
			FROM ['+@IP+'].' + @Database + '.dbo.tblPV pv0
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
		FROM ['+@IP+'].' + @Database + '.dbo.tblRep WHERE tblRep.Tip=''c''')

		DELETE FROM trelRepProd

		EXECUTE('INSERT INTO trelRepProd([Id],[RepID] ,[ProdID] ,[PVNet] ,[PV] )
		SELECT [Id],[RepID] ,[ProdID] ,[PVNet] ,[PV]
		FROM ['+@IP+'].' + @Database + '.dbo.trelRepProd')
		
		TRUNCATE TABLE  trelRepCarduri

		EXECUTE('INSERT INTO trelRepCarduri([Id],[RepID] ,[CodCard] )
		SELECT [Id],[RepID] ,[CodCard] 
		FROM ['+@IP+'].' + @Database + '.dbo.trelRepCarduri')
		*/

		SELECT	'Return_Value' = @return_value
		COMMIT TRANSACTION
	END TRY
	BEGIN CATCH
		-- Eroare: anulam intotdeauna tranzactia, ca sa nu comitem stergeri partiale
		-- (fara EXECUTE usp_GetErrorInfo: INSERT-ul lui in ERRORLOG ar pica
		--  primul cu 3930 intr-o tranzactie "doomed" si ar ascunde cauza)
		IF (XACT_STATE()) <> 0
			ROLLBACK TRANSACTION;
	THROW;
	END CATCH
	--SELECT @ipServer, @p2
	
END
