USE [DataRON_CIORB]
GO
/****** Object:  StoredProcedure [dbo].[EmitBon_Ext_3] ******/
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
-- =============================================
-- Author:		Florian L. Stefanescu
-- Create date: 01/08/2017
-- Last Update: 01/08/2019
-- Last Edit:	14/10/2026
-- Description: Procesare date exportate de catre POS-uri.
--   sVanzare = "ProdID:Cant:PV:PV1:TVAc:MagID;" pentru fiecare linie de produs.
--   @MagID-ul din fiecare linie este folosit la operatiile de stoc
--   (ScadGest_Ext / ScadCompR_Ext) si pentru determinarea StatieID.
--   Daca o linie nu are MagID, se foloseste @MagID-ul bonului.
-- =============================================

-- Codul fiscal al clientului se arhiveaza in tblBon.CodFiscBon. Coloana trebuie
-- sa existe inainte de (re)crearea procedurii, care o refera.
IF COL_LENGTH('dbo.tblBon','CodFiscBon') IS NULL
	ALTER TABLE dbo.tblBon ADD CodFiscBon nvarchar(50) NULL;
GO

CREATE OR ALTER PROCEDURE [dbo].[EmitBon_Ext_3]
	@Data as char(10),
	@Ora as char(8),
	@CasaID as int,
	@sFP as char(1000),
	@sVanzare as char(2200),
	@NrBon as int,
	@MagID as int,
	@NrZ as int,
	@CasierID as int,
	@TotalB as float,
	@ClientID as int,
	@StatieID as int = 100,
	@CodFiscBon nvarchar(50) = NULL,
	@RaspunsCasa nvarchar(max) = ''
AS
BEGIN
	SET NOCOUNT ON;
	SET DATEFORMAT dmy

	Declare @Suma as float		----suma platita
	Declare @FP as int			----forma de plata
	Declare @sFP1 as char(50)
	Declare @sVanzare1 as char(100)
	Declare @ProdID as int
	Declare @Cant as float
	Declare @PV as float
	Declare @PV1 as float
	Declare @lDocID as int
	Declare @ECRID as int
	Declare @Tvac as int
	Declare @TipProdus as int
	Declare @R as bit
	Declare @ValProcent as float
	Declare @sReteta as nvarchar(1000), @sReteta1 as nvarchar(100)
	Declare @MagIDLinie as int
	Declare @ProdIDReteta as int, @TVAReteta as int
	Declare @Stoc int = 0			-- ciorbarie: nu se tine stocul la materii prime

	Declare @Numerar as Decimal(8,2) = 0
	Declare @Card as Decimal(8,2) = 0
	Declare @CEC as Decimal(8,2) = 0
	Declare @TICHET as Decimal(8,2) = 0
	Declare @Protocol as Decimal(8,2) = 0

	Declare @x as int
	Declare @y as int

	---------------------------------Extrag formele de plata din sirul sFP----------------------------------------
	begin transaction
	While (len(@sFP) <> 0)
	Begin
		Select @x = Charindex(';', @sFP)
		Select @sFP1 = Left(@sFP, @x - 1)
		Select @sFP = substring(@sFP, @x + 1, len(@sFP) - @x)
		Select @FP = Left(@sFP1, Charindex(':', @sFP1) - 1)
		Select @Suma = Substring(@sFP1, Charindex(':', @sFP1) + 1, len(@sFP1) - Charindex(':', @sFP1))

		if @FP = 0 Select @Numerar = @Suma
		if @FP = 1 Select @Card = @Suma
		if @FP = 2 Select @CEC = @Suma
		if @FP = 3 Select @TICHET = @Suma
		if @FP = 4 Select @Protocol = @Suma

		---------------START elimin transmisile duble------------------------
		SELECT Top 1 @lDocID = DocID from tblBon
			where RepID = @CasaID and MagID = @MagID and NrDoc = @NrBon and TIB = @FP
			  and CONVERT(VARCHAR(10), Data, 3) = @Data and Ora = @Ora
			order by DocID desc;
		select @lDocID = isnull(@lDocID, 0)
		if @lDocID > 0 begin
			commit transaction
			Select @lDocID
			return
		end
		-------------------------------------END TRANSMISII DUBLE-------------------------

		Insert into tblBon (NrDoc,Data,RepID,MagID,NrOp,NrZ,TotalB,SC,TIB,Ora,ClientID,StatieID,CodFiscBon)
		values(@NrBon,@data,@CasaID,@MagID,@CasierID,@NrZ,@TotalB,@Suma,@FP,@Ora,@ClientID,@StatieID,@CodFiscBon)
	end

	SELECT Top 1 @lDocID = DocID from tblBon where RepID = @CasaID and MagID = @MagID Order by DocID Desc;
	Update tblBon set TVAA = @Numerar, TVAB = @Card, TVAD = @CEC, TVAE = @TICHET, TVAF = @Protocol where DocID = @lDocID

	Select @ValProcent = 0
	-------------------extrag articolele vandute din sirul sVanzare------------------------
	While (len(@sVanzare) <> 0)
	Begin
		Select @x = Charindex(';', @sVanzare)				--caut primul delimitator
		Select @sVanzare1 = Left(@sVanzare, @x - 1)			--extrag primul element
		Select @sVanzare = substring(@sVanzare, @x + 1, len(@sVanzare) - @x)	--elimin primul element

		-- ProdID
		Select @y = Charindex(':', @sVanzare1)
		Select @ProdID = Left(@sVanzare1, @y - 1)
		Select @sVanzare1 = substring(@sVanzare1, @y + 1, len(@sVanzare1) - @y)
		-- Cant
		Select @y = Charindex(':', @sVanzare1)
		Select @Cant = Left(@sVanzare1, @y - 1)
		Select @sVanzare1 = substring(@sVanzare1, @y + 1, len(@sVanzare1) - @y)
		-- PV
		Select @y = Charindex(':', @sVanzare1)
		Select @PV = Left(@sVanzare1, @y - 1)
		Select @sVanzare1 = substring(@sVanzare1, @y + 1, len(@sVanzare1) - @y)
		-- PV1
		Select @y = Charindex(':', @sVanzare1)
		Select @PV1 = Left(@sVanzare1, @y - 1)
		Select @sVanzare1 = substring(@sVanzare1, @y + 1, len(@sVanzare1) - @y)
		-- TVAc (al 5-lea camp).
		Select @y = Charindex(':', @sVanzare1)
		if @y > 0 begin
			Select @Tvac = Left(@sVanzare1, @y - 1)
			Select @sVanzare1 = substring(@sVanzare1, @y + 1, len(@sVanzare1) - @y)
		end else begin
			Select @Tvac = @sVanzare1
			Select @sVanzare1 = ''
		end

		-- MagID (al 6-lea camp) + meniu/reteta. Blocul de mai jos este parserul
		-- vechi pastrat identic, cu primul camp citit = MagID (in vechiul parser
		-- acelasi bloc citea TVAc). Daca dupa MagID urmeaza alte campuri, ele sunt
		-- cele de meniu/reteta (ProdIDReteta + 3 campuri + TVAReteta) si se scriu
		-- in tempECR_Meniu. Daca MagID lipseste, se foloseste cel al bonului.
		Select @y = Charindex(':', @sVanzare1)
		if @y > 0 begin
			Select @MagIDLinie = Left(@sVanzare1, @y - 1)
			Select @sVanzare1 = substring(@sVanzare1, @y + 5, len(@sVanzare1) - @y + 5)
			SELECT @y = Charindex(':', @sVanzare1)
			Select @ProdIDReteta = Left(@sVanzare1, @y - 1)
			Select @sVanzare1 = substring(@sVanzare1, @y + 1, len(@sVanzare1) - @y + 1)
			SELECT @y = Charindex(':', @sVanzare1)
			Select @sVanzare1 = substring(@sVanzare1, @y + 1, len(@sVanzare1) - @y + 1)
			SELECT @y = Charindex(':', @sVanzare1)
			Select @sVanzare1 = substring(@sVanzare1, @y + 1, len(@sVanzare1) - @y + 1)
			SELECT @y = Charindex(':', @sVanzare1)
			Select @sVanzare1 = substring(@sVanzare1, @y + 1, len(@sVanzare1) - @y + 1)
			SELECT @y = Charindex(':', @sVanzare1)
			SELECT @TVAReteta = @sVanzare1
		end else begin
			Select @MagIDLinie = @sVanzare1
			Select @ProdIDReteta = 0
		end
		if @MagIDLinie IS NULL Or @MagIDLinie = 0 Select @MagIDLinie = @MagID

		Select @TipProdus = TipProdus from tblProd where ProdID = @ProdID
		Select @R = R from tblProd where ProdID = @ProdID

		Insert into tempECR(DocID,ProdID,MagID,Cant,Val,Tvac,Cont,NrErr,ValPr)
		Values(@lDocID,@ProdID,@MagIDLinie,@Cant,Round(@cant*@PV1,4),@tvac,'371',1,Round(@cant*(@PV-@PV1),4))

		Select Top 1 @ECRID = ECRID from tempECR where DocID = @lDocID ORDER BY ECRID Desc;

		-- Meniu: memorez structura meniului numai daca exista (compatibil cu vechea aplicatie).
		if @ProdIDReteta <> 0
		BEGIN
			-- cantitatea este aceeasi cu cea a meniului
			INSERT INTO tempECR_Meniu (ECRID, ProdID, Cantitate, Valoare, TVAC, ProdIDMeniu)
			Values(@ECRID, @ProdIDReteta, @Cant, 0, @TVAReteta, @ProdID)
		END

		Select @ValProcent = Round(@ValProcent + @cant*(@PV1 - @PV),4)

		-- StatieID se determina per magazie (MagID-ul liniei). Pentru francize
		-- (StatieID lipsa/0) nu ne intereseaza stocul.
		Select @StatieID = NULL
		Select @StatieID = StatieID from tblMag where MagID = @MagIDLinie
		if @StatieID > 0 begin
			if @R = 1 begin
				-- produsul este reteta
				if @tipProdus = 1
					Exec ScadGest_Ext @ECRID,@NrBon,@lDocID,@MagIDLinie,@ProdID,@Cant,@PV,@data,'ecr','e',@tvac,@NrZ,1,1,'ecr',@StatieID,@PV1
				else if @tipProdus = 0 and @Stoc = 1 begin
					-- reteta fara stoc; la ciorbarie nu se tine stocul la materii prime, @Stoc=0
					Select @sReteta = ''
					Exec DesfaReteta @ProdID, @sReteta output
					IF @ProdIDReteta <> 0
					BEGIN
						Exec DesfaReteta @ProdIDReteta, @sReteta1 output
						SELECT @sReteta = cast(@sReteta as nvarchar(1000)) + Cast(@sReteta1 as nvarchar(1000))
					END
					Exec ScadCompR_Ext @sReteta,@ECRID,@NrBon,@lDocID,@MagIDLinie,@ProdID,@Cant,@PV,@data,'ecr','e',@tvac,@NrZ,0,1,'ecr',@StatieID,@PV1
				end
			end else begin
				-- produs simplu (tipProdus=1); tipProdus=2 = serviciu, fara stoc
				if @tipProdus = 1
					Exec ScadGest_Ext @ECRID,@NrBon,@lDocID,@MagIDLinie,@ProdID,@Cant,@PV,@data,'ecr','e',@tvac,@NrZ,1,0,'ecr',@StatieID,@PV1
			end
		end
	end

	commit transaction
END
GO
