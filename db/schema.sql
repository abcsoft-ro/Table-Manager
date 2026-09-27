-- ============================================================================
-- TableManager 2.1C - schema bazei de date
-- Generat automat dintr-un script local de dezvoltare (nu editati manual).
-- Contine doar tabelele si procedurile folosite de aplicatie.
-- ============================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

IF DB_ID(N'Rual') IS NULL
BEGIN
    CREATE DATABASE [Rual];
END
GO
USE [Rual];
GO

------------------------------------------------------------ tblTVA
IF OBJECT_ID(N'dbo.tblTVA', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[tblTVA] (
    [Nr_TVA] tinyint NOT NULL,
    [Cota] int NULL
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'PK_tblTVA')
ALTER TABLE dbo.[tblTVA] ADD CONSTRAINT [PK_tblTVA] PRIMARY KEY CLUSTERED ([Nr_TVA]);
GO

------------------------------------------------------------ tblSectii
IF OBJECT_ID(N'dbo.tblSectii', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[tblSectii] (
    [Sectie] int NOT NULL,
    [Denumire] nvarchar(10) NULL,
    [Status] int NULL
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'PK_tblSectii')
ALTER TABLE dbo.[tblSectii] ADD CONSTRAINT [PK_tblSectii] PRIMARY KEY CLUSTERED ([Sectie]);
GO

------------------------------------------------------------ tblFP
IF OBJECT_ID(N'dbo.tblFP', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[tblFP] (
    [FPID] int NOT NULL,
    [Denumire] nvarchar(10) NULL,
    [Status] int NULL,
    [Poz] int NULL
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'PK_tblFP')
ALTER TABLE dbo.[tblFP] ADD CONSTRAINT [PK_tblFP] PRIMARY KEY CLUSTERED ([FPID]);
GO

------------------------------------------------------------ tblKP
IF OBJECT_ID(N'dbo.tblKP', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[tblKP] (
    [NrLogic] tinyint NOT NULL,
    [Serie] nvarchar(4) NULL,
    [Stare] bit NOT NULL,
    [Nume] nvarchar(255) NULL,
    [MagID] int NULL,
    [CaleTSC] nvarchar(255) NULL,
    [raport] nvarchar(100) NULL,
    [DenumirePrinter] nvarchar(255) NULL
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'PK_tblKP')
ALTER TABLE dbo.[tblKP] ADD CONSTRAINT [PK_tblKP] PRIMARY KEY CLUSTERED ([NrLogic]);
GO

------------------------------------------------------------ tblSet
IF OBJECT_ID(N'dbo.tblSet', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[tblSet] (
    [Setting] varchar(100) NOT NULL,
    [Value] nvarchar(MAX) NULL,
    [Descriere] nvarchar(255) NULL,
    [Grup] nvarchar(50) NULL
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'PK_tblSet1')
ALTER TABLE dbo.[tblSet] ADD CONSTRAINT [PK_tblSet1] PRIMARY KEY CLUSTERED ([Setting]);
GO

------------------------------------------------------------ tblParola
IF OBJECT_ID(N'dbo.tblParola', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[tblParola] (
    [Id] int NOT NULL,
    [ParolaProgramare] nvarchar(20) NULL,
    [ParolaRapoarte] nvarchar(20) NULL,
    [ParolaStornare] nvarchar(20) NULL,
    [ParolaDiscount] nvarchar(20) NULL,
    [ParolaExit] nvarchar(20) NULL
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'PK__tblParol__3214EC07EDD6CD50')
ALTER TABLE dbo.[tblParola] ADD CONSTRAINT [PK__tblParol__3214EC07EDD6CD50] PRIMARY KEY CLUSTERED ([Id]);
GO

------------------------------------------------------------ tblOsp
IF OBJECT_ID(N'dbo.tblOsp', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[tblOsp] (
    [NrOsp] nvarchar(2) NOT NULL,
    [Nume] nvarchar(50) NULL,
    [Expl] nvarchar(50) NULL,
    [Parola] nvarchar(50) NULL,
    [Blocat] bit NOT NULL
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'PK_tblOsp')
ALTER TABLE dbo.[tblOsp] ADD CONSTRAINT [PK_tblOsp] PRIMARY KEY CLUSTERED ([NrOsp]);
GO

------------------------------------------------------------ tblAntet
IF OBJECT_ID(N'dbo.tblAntet', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[tblAntet] (
    [Nume] nvarchar(40) NULL,
    [Adresa] nvarchar(40) NULL,
    [Seria] nvarchar(4) NOT NULL,
    [NumeFont] nvarchar(50) NULL,
    [Size] int NULL,
    [CodFiscF] nvarchar(40) NULL,
    [RegCom] nvarchar(22) NULL,
    [Judet] nvarchar(22) NULL,
    [Cont] nvarchar(22) NULL,
    [Banca] nvarchar(40) NULL,
    [Denumire2] nvarchar(40) NULL,
    [Bold] bit NULL,
    [VersiuneDB] nvarchar(255) NULL,
    [PlatitorTVA] int NULL CONSTRAINT [DF__tblAntet__Platit__44CA3770] DEFAULT ((1)),
    [CaleDateLogo] nvarchar(255) NULL,
    [QRCodeText] nvarchar(255) NULL,
    [SizeModeLogo] int NULL,
    [Oras] nvarchar(50) NULL,
    [CodPostal] nvarchar(10) NULL,
    [PersContact] nvarchar(100) NULL,
    [email] nvarchar(50) NULL,
    [website] nvarchar(50) NULL,
    [telefon] nvarchar(50) NULL
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'pk_tblAntet_id')
ALTER TABLE dbo.[tblAntet] ADD CONSTRAINT [pk_tblAntet_id] PRIMARY KEY CLUSTERED ([Seria]);
GO

------------------------------------------------------------ tblMese
IF OBJECT_ID(N'dbo.tblMese', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[tblMese] (
    [MasaID] int IDENTITY(1,1) NOT NULL,
    [NrMasa] int NULL,
    [BackColor] int NULL,
    [ForeColor] int NULL,
    [Bold] bit NOT NULL,
    [Afisez] bit NOT NULL,
    [NrPOS] int NULL,
    [Obs] nvarchar(10) NULL
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'PK_tblMese')
ALTER TABLE dbo.[tblMese] ADD CONSTRAINT [PK_tblMese] PRIMARY KEY CLUSTERED ([MasaID]);
GO

------------------------------------------------------------ tblMesaj
IF OBJECT_ID(N'dbo.tblMesaj', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[tblMesaj] (
    [Nrmesaj] nvarchar(2) NOT NULL,
    [Mesaj] nvarchar(22) NULL
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'PK_tblMesaj')
ALTER TABLE dbo.[tblMesaj] ADD CONSTRAINT [PK_tblMesaj] PRIMARY KEY CLUSTERED ([Nrmesaj]);
GO

------------------------------------------------------------ tblGrp
IF OBJECT_ID(N'dbo.tblGrp', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[tblGrp] (
    [NrGrp] int NOT NULL,
    [Denumire] nvarchar(50) NULL,
    [BackColor] int NULL,
    [FontColor] int NULL,
    [FontSize] int NULL,
    [Bold] bit NOT NULL,
    [FontType] nvarchar(50) NULL,
    [Tint] bit NOT NULL,
    [Poz] int NULL,
    [NrKp] int NULL
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'PK_tblGrp')
ALTER TABLE dbo.[tblGrp] ADD CONSTRAINT [PK_tblGrp] PRIMARY KEY CLUSTERED ([NrGrp]);
GO

------------------------------------------------------------ tblProd
IF OBJECT_ID(N'dbo.tblProd', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[tblProd] (
    [ProdID] int NOT NULL,
    [BarCod] nvarchar(15) NOT NULL,
    [Denumire] nvarchar(32) NULL,
    [UM] nvarchar(3) NOT NULL,
    [IRP] int NULL,
    [IIVCN] int NULL,
    [DataUM] datetime NULL,
    [NrGrp] int NOT NULL,
    [KP] tinyint NULL,
    [BackColor] int NULL,
    [FontColor] int NULL,
    [FontSize] int NULL,
    [Bold] int NULL,
    [Poz] int NULL,
    [FontType] nvarchar(50) NULL,
    [Imagine] nvarchar(255) NULL,
    [Sectie] int NULL CONSTRAINT [DF__tblProd__Sectie__4C364F0E] DEFAULT ((1)),
    [PV] float NULL CONSTRAINT [DF__tblProd__PV__4D2A7347] DEFAULT ((0)),
    [Nr_TVA] int NULL CONSTRAINT [DF__tblProd__Nr_TVA__4E1E9780] DEFAULT ((1)),
    [MZ] int NULL CONSTRAINT [DF__tblProd__MZ__69C6B1F5] DEFAULT ((0))
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'PK_tblProd')
ALTER TABLE dbo.[tblProd] ADD CONSTRAINT [PK_tblProd] PRIMARY KEY CLUSTERED ([ProdID]);
GO

------------------------------------------------------------ tblBonCurent
IF OBJECT_ID(N'dbo.tblBonCurent', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[tblBonCurent] (
    [DocID] int IDENTITY(1,1) NOT NULL,
    [NrDoc] int NOT NULL CONSTRAINT [DF_tblBonCurent_NrDoc] DEFAULT ((0)),
    [Data] datetime NOT NULL CONSTRAINT [DF_tblBonCurent_Data] DEFAULT (getdate()),
    [RepID] nvarchar(6) NULL,
    [ContR] nvarchar(6) NULL,
    [MagID] int NULL CONSTRAINT [DF_tblBonCurent_MagID] DEFAULT ((0)),
    [TipDoc] nvarchar(3) NULL,
    [NrOp] nvarchar(6) NULL,
    [NrZ] int NULL CONSTRAINT [DF_tblBonCurent_NrZ] DEFAULT ((0)),
    [TotalB] float NULL CONSTRAINT [DF_tblBonCurent_TotalB] DEFAULT ((0)),
    [SC] float NULL CONSTRAINT [DF_tblBonCurent_SC] DEFAULT ((0)),
    [TIB] nvarchar(1) NULL,
    [Ora] datetime NULL CONSTRAINT [DF_tblBonCurent_Ora] DEFAULT (getdate()),
    [TVAA] int NULL CONSTRAINT [DF_tblBonCurent_TVAA] DEFAULT ((0)),
    [TVAB] int NULL CONSTRAINT [DF_tblBonCurent_TVAB] DEFAULT ((0)),
    [TVAC] int NULL CONSTRAINT [DF_tblBonCurent_TVAC] DEFAULT ((0)),
    [TVAD] int NULL CONSTRAINT [DF_tblBonCurent_TVAD] DEFAULT ((0)),
    [TVAE] int NULL CONSTRAINT [DF_tblBonCurent_TVAE] DEFAULT ((0)),
    [TVAF] int NULL CONSTRAINT [DF_tblBonCurent_TVAF] DEFAULT ((0)),
    [TVAG] int NULL CONSTRAINT [DF_tblBonCurent_TVAG] DEFAULT ((0)),
    [TVAH] int NULL,
    [NrMasa] smallint NULL,
    [Stare] nvarchar(2) NULL CONSTRAINT [DF_tblBonCurent_Stare] DEFAULT ('D'),
    [NrPrs] smallint NOT NULL CONSTRAINT [DF_tblBonCurent_NrPrs] DEFAULT ((1)),
    [Preluat] bit NOT NULL CONSTRAINT [DF_tblBonCurent_Preluat] DEFAULT ((0)),
    [ClientID] nvarchar(50) NULL,
    [BonTableta] int NULL CONSTRAINT [DF_BonTableta_BonCurent] DEFAULT ((0)),
    [StatusBonTableta] int NULL CONSTRAINT [DF__tblBonCur__Statu__43D61337] DEFAULT ((0)),
    [StatieID] int NULL,
    [OrderID] int NULL
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'PK_tblBonCurent')
ALTER TABLE dbo.[tblBonCurent] ADD CONSTRAINT [PK_tblBonCurent] PRIMARY KEY CLUSTERED ([DocID]);
GO

------------------------------------------------------------ tblNoteD
IF OBJECT_ID(N'dbo.tblNoteD', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[tblNoteD] (
    [ECRID] int IDENTITY(1,1) NOT NULL,
    [DocID] int NULL CONSTRAINT [DF_tblNoteD_DocID] DEFAULT ((0)),
    [ProdID] int NULL CONSTRAINT [DF_tblNoteD_ProdID] DEFAULT ((0)),
    [Cant] float NULL CONSTRAINT [DF_tblNoteD_Cant] DEFAULT ((1)),
    [Tvac] int NULL CONSTRAINT [DF_tblNoteD_Tvac] DEFAULT ((0)),
    [cm] nvarchar(2) NULL,
    [Magid] smallint NULL CONSTRAINT [DF_tblNoteD_Magid] DEFAULT ((0)),
    [CantKP] float NULL CONSTRAINT [DF_tblNoteD_CantKP] DEFAULT ((1)),
    [H] bit NOT NULL CONSTRAINT [DF_tblNoteD_H] DEFAULT ((0)),
    [OraComanda] datetime NULL CONSTRAINT [DF_tblNoteD_OraComanda] DEFAULT (getdate()),
    [Preluat] bit NOT NULL CONSTRAINT [DF_tblNoteD_Preluat] DEFAULT ((0)),
    [NrErr] int NULL CONSTRAINT [DF_tblNoteD_NrErr] DEFAULT ((0)),
    [Descriere] nvarchar(255) NULL,
    [Preluat1] bit NOT NULL CONSTRAINT [DF_tblNoteD_Preluat1] DEFAULT ((0)),
    [BonTableta] int NULL CONSTRAINT [DF_BonTableta_NoteD] DEFAULT ((0)),
    [NrGrp] int NULL,
    [PV] float NULL CONSTRAINT [DF__tblNoteD__PV__7849DB76] DEFAULT ((0)),
    [PVC] float NULL CONSTRAINT [DF__tblNoteD__PVC__51EF2864] DEFAULT ((0)),
    [Comment] nvarchar(50) NULL,
    [StornoRef] int NULL
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'PK_tblNoteD')
ALTER TABLE dbo.[tblNoteD] ADD CONSTRAINT [PK_tblNoteD] PRIMARY KEY CLUSTERED ([ECRID]);
GO

------------------------------------------------------------ tblBon
IF OBJECT_ID(N'dbo.tblBon', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[tblBon] (
    [s_Generation] int NULL,
    [DocID] int NOT NULL,
    [NrDoc] int NOT NULL,
    [Data] datetime NOT NULL,
    [RepID] nvarchar(6) NULL,
    [ContR] nvarchar(6) NULL,
    [MagID] int NULL,
    [s_GUID] uniqueidentifier NULL,
    [TipDoc] nvarchar(3) NULL,
    [NrOp] nvarchar(6) NULL,
    [NrZ] int NULL,
    [TotalB] float NULL,
    [s_Lineage] image NULL,
    [SC] float NULL,
    [TIB] nvarchar(1) NULL,
    [Ora] datetime NULL,
    [TVAA] int NULL,
    [TVAB] int NULL,
    [TVAC] int NULL,
    [TVAD] int NULL,
    [TVAE] int NULL,
    [TVAF] int NULL,
    [TVAG] int NULL,
    [TVAH] int NULL,
    [NrMasa] smallint NULL,
    [Stare] nvarchar(2) NULL,
    [NrPrs] smallint NULL,
    [DocIDP] int NULL,
    [ClientID] nvarchar(50) NULL
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'PK_tblBon')
ALTER TABLE dbo.[tblBon] ADD CONSTRAINT [PK_tblBon] PRIMARY KEY CLUSTERED ([DocID]);
GO

------------------------------------------------------------ tempECR
IF OBJECT_ID(N'dbo.tempECR', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[tempECR] (
    [ECRID] int IDENTITY(1,1) NOT NULL,
    [DocID] int NULL,
    [ProdID] int NULL,
    [Cant] float NULL,
    [Val] float NULL,
    [Tvac] int NULL,
    [Cont] nvarchar(6) NULL,
    [cm] nvarchar(2) NULL,
    [Procent] int NULL,
    [ValPr] float NULL,
    [NrErr] int NULL,
    [Magid] smallint NULL,
    [CantKP] float NULL,
    [PretRaft] float NULL,
    [Preluat] bit NOT NULL CONSTRAINT [DF_tempECR_Preluat] DEFAULT ((0)),
    [Denumire] nvarchar(32) NULL,
    [PV] float NULL CONSTRAINT [DF__tempECR__PV__50FB042B] DEFAULT ((0)),
    [PVC] float NULL,
    [Comment] nvarchar(50) NULL,
    [StornoRef] int NULL
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'PK_tempECR')
ALTER TABLE dbo.[tempECR] ADD CONSTRAINT [PK_tempECR] PRIMARY KEY CLUSTERED ([ECRID]);
GO

------------------------------------------------------------ tblNrZ
IF OBJECT_ID(N'dbo.tblNrZ', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[tblNrZ] (
    [NrZ] int NULL,
    [NRNota] int NULL,
    [Data] datetime NULL,
    [Ora] datetime NULL,
    [Id] int IDENTITY(1,1) NOT NULL
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'pk_tblNrZ_id')
ALTER TABLE dbo.[tblNrZ] ADD CONSTRAINT [pk_tblNrZ_id] PRIMARY KEY CLUSTERED ([Id]);
GO

------------------------------------------------------------ trelDocIDFpID
IF OBJECT_ID(N'dbo.trelDocIDFpID', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[trelDocIDFpID] (
    [Id] int IDENTITY(1,1) NOT NULL,
    [DocID] int NOT NULL,
    [FPID] int NOT NULL,
    [Suma] float NULL
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'PK__trelDocI__3214EC071FC7AB3D')
ALTER TABLE dbo.[trelDocIDFpID] ADD CONSTRAINT [PK__trelDocI__3214EC071FC7AB3D] PRIMARY KEY CLUSTERED ([Id]);
GO

------------------------------------------------------------ trelArhZDocIDFpID
IF OBJECT_ID(N'dbo.trelArhZDocIDFpID', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[trelArhZDocIDFpID] (
    [Id] int IDENTITY(1,1) NOT NULL,
    [DocID] int NOT NULL,
    [FPID] int NOT NULL,
    [FPDenumire] nvarchar(10) NULL,
    [Suma] float NULL
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'PK__trelArhZ__3214EC0765CF2229')
ALTER TABLE dbo.[trelArhZDocIDFpID] ADD CONSTRAINT [PK__trelArhZ__3214EC0765CF2229] PRIMARY KEY CLUSTERED ([Id]);
GO

------------------------------------------------------------ tblPrintQueue
IF OBJECT_ID(N'dbo.tblPrintQueue', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[tblPrintQueue] (
    [JobID] int IDENTITY(1,1) NOT NULL,
    [Tip] nvarchar(10) NOT NULL,
    [RefDocID] int NULL,
    [PrinterNr] int NULL,
    [Payload] nvarchar(MAX) NULL,
    [Stare] nvarchar(10) NOT NULL CONSTRAINT [DF_tblPrintQueue_Stare] DEFAULT ('pending'),
    [Attempts] int NOT NULL CONSTRAINT [DF_tblPrintQueue_Attempts] DEFAULT ((0)),
    [NextAttempt] datetime2(7) NULL,
    [LastError] nvarchar(255) NULL,
    [CreatedAt] datetime2(7) NOT NULL CONSTRAINT [DF_tblPrintQueue_Created] DEFAULT (sysdatetime()),
    [PrintedAt] datetime2(7) NULL
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'PK__tblPrint__056690E2B0F5AE6C')
ALTER TABLE dbo.[tblPrintQueue] ADD CONSTRAINT [PK__tblPrint__056690E2B0F5AE6C] PRIMARY KEY CLUSTERED ([JobID]);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_tblPrintQueue_Stare' AND object_id = OBJECT_ID(N'dbo.tblPrintQueue'))
CREATE NONCLUSTERED INDEX [IX_tblPrintQueue_Stare] ON dbo.[tblPrintQueue] ([Stare],[NextAttempt]) INCLUDE ([JobID]);
GO

------------------------------------------------------------ tblConectare
IF OBJECT_ID(N'dbo.tblConectare', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[tblConectare] (
    [ID] int IDENTITY(1,1) NOT NULL,
    [ServerIP] nvarchar(50) NULL,
    [ServerName] nvarchar(50) NULL,
    [DataBaseName] nvarchar(50) NULL,
    [UserName] nvarchar(50) NULL,
    [Password] nvarchar(50) NULL,
    [TC] bit NOT NULL,
    [ODBC_connect_string] nvarchar(255) NULL
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'PK_tblConectare')
ALTER TABLE dbo.[tblConectare] ADD CONSTRAINT [PK_tblConectare] PRIMARY KEY CLUSTERED ([ID]);
GO

------------------------------------------------------------ ERRORLOG
IF OBJECT_ID(N'dbo.ERRORLOG', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[ERRORLOG] (
    [errorid] float NULL,
    [Errordate] datetime NULL,
    [log_ERROR_NUMBER] float NULL,
    [log_ERROR_SEVERITY] float NULL,
    [log_ERROR_STATE] float NULL,
    [log_ERROR_LINE] float NULL,
    [log_ERROR_PROCEDURE] nvarchar(255) NULL,
    [log_ERROR_MESSAGE] nvarchar(MAX) NULL
);
END
GO

------------------------------------------------------------ chei straine
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_tblNoteD_tblBonCurent')
ALTER TABLE dbo.[tblNoteD] WITH CHECK ADD CONSTRAINT [FK_tblNoteD_tblBonCurent] FOREIGN KEY ([DocID]) REFERENCES dbo.[tblBonCurent] ([DocID]) ON DELETE CASCADE ON UPDATE CASCADE;
GO
IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_trelDocIDFpID_tblBonCurent')
ALTER TABLE dbo.[trelDocIDFpID] WITH CHECK ADD CONSTRAINT [FK_trelDocIDFpID_tblBonCurent] FOREIGN KEY ([DocID]) REFERENCES dbo.[tblBonCurent] ([DocID]) ON DELETE CASCADE ON UPDATE CASCADE;
GO

------------------------------------------------------------ proceduri stocate
SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
CREATE OR ALTER PROCEDURE [dbo].[usp_GetErrorInfo]
AS
    SELECT 
         ERROR_NUMBER() AS ErrorNumber
        ,ERROR_SEVERITY() AS ErrorSeverity
        ,ERROR_STATE() AS ErrorState
        ,ERROR_LINE () AS ErrorLine
        ,ERROR_PROCEDURE() AS ErrorProcedure
        ,ERROR_MESSAGE() AS ErrorMessage;
        
        INSERT INTO ERRORLOG(ErrorDate,log_ERROR_NUMBER,log_ERROR_SEVERITY,log_ERROR_STATE,log_ERROR_LINE,log_ERROR_PROCEDURE,log_ERROR_MESSAGE)
					VALUES (GETDATE(),ERROR_NUMBER(), ERROR_SEVERITY(),ERROR_STATE(),ERROR_LINE (),ERROR_PROCEDURE(),ERROR_MESSAGE())
GO

SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
/*
 * Procedura stocata pentru inchiderea Z (golirea zilei de lucru).
 *
 * Efectueaza, intr-o SINGURA tranzactie (totul sau nimic):
 *   1. verifica ca toate mesele sunt inchise (niciun bon cu Stare <> 'I');
 *   2. calculeaza urmatorul numar Z = ISNULL(MAX(tblNrZ.NrZ), 0) + 1;
 *   3. scrie randul de Z in tblNrZ (NrZ, NRNota = ultimul NrDoc, Data, Ora);
 *   4. arhiveaza tblBonCurent -> tblBon (stampilat cu NrZ curent);
 *   5. arhiveaza tblNoteD -> tempECR;
 *   6. goleste tblNoteD si tblBonCurent.
 *
 * La orice eroare se face ROLLBACK si eroarea este propagata (THROW), deci
 * operatia nu se poate efectua partial. Intoarce noul NrZ ca resultset.
 *
 * Se creeaza/actualizeaza automat din PHP (api/rapoarte.php), dar poate fi
 * rulata si manual in SSMS (a se rula ca un singur batch).
 */
CREATE OR ALTER PROCEDURE dbo.RealizeazaZ
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @nr INT;

    BEGIN TRY
        BEGIN TRANSACTION;

        -- Toate mesele trebuie sa fie inchise. Blocam tabelul pe durata tranzactiei.
        IF EXISTS (SELECT 1 FROM tblBonCurent WITH (UPDLOCK, HOLDLOCK)
                   WHERE Stare IS NULL OR Stare <> 'I')
        BEGIN
            RAISERROR('Raportul Z nu poate fi efectuat: exista mese deschise.', 16, 1);
        END

        -- Urmatorul numar Z.
        SELECT @nr = ISNULL(MAX(NrZ), 0) + 1
        FROM tblNrZ WITH (UPDLOCK, HOLDLOCK);

        -- Numarul ultimei note (audit / continuitate numerotare).
        DECLARE @nrNota INT = ISNULL((SELECT MAX(NrDoc) FROM tblBonCurent), 0);

        INSERT INTO tblNrZ (NrZ, NRNota, Data, Ora)
        VALUES (@nr, @nrNota, GETDATE(), GETDATE());

        -- Arhivare bonuri in tblBon (NrZ = Z-ul curent).
        INSERT INTO tblBon
            (DocID, NrDoc, Data, RepID, ContR, MagID, TipDoc, NrOp, NrZ, TotalB,
             SC, TIB, Ora, TVAA, TVAB, TVAC, TVAD, TVAE, TVAF, TVAG, TVAH,
             NrMasa, Stare, NrPrs, DocIDP, ClientID)
        SELECT
             DocID, NrDoc, Data, RepID, ContR, MagID, TipDoc, NrOp, @nr, TotalB,
             SC, TIB, Ora, TVAA, TVAB, TVAC, TVAD, TVAE, TVAF, TVAG, TVAH,
             NrMasa, Stare, NrPrs, NULL, ClientID
        FROM tblBonCurent;

        -- Arhivare linii in tempECR. ECRID este IDENTITY in tempECR (se genereaza
        -- automat). Campurile legacy care nu mai exista in tblNoteD
        -- (Val, Cont, Procent, ValPr) sunt derivate sau NULL.
        INSERT INTO tempECR
            (DocID, ProdID, Cant, Val, Tvac, Cont, cm, Procent, ValPr,
             NrErr, Magid, CantKP, PretRaft, Preluat, Denumire, PV, PVC, Comment, StornoRef)
        SELECT
             d.DocID, d.ProdID, d.Cant, d.Cant * d.PV, d.Tvac, NULL, d.cm, NULL, NULL,
             d.NrErr, d.Magid, d.CantKP, d.PVC, d.Preluat,
             LEFT(COALESCE(p.Denumire, d.Descriere), 32),
             d.PV, d.PVC, d.Comment, d.StornoRef
        FROM tblNoteD d
        LEFT JOIN tblProd p ON d.ProdID = p.ProdID;

        -- Platile zilei: se arhiveaza in trelArhZDocIDFpID, apoi se sterg
        -- (trelDocIDFpID are FK catre tblBonCurent, deci trebuie golite inainte).
        INSERT INTO trelArhZDocIDFpID (DocID, FPID, FPDenumire, Suma)
        SELECT p.DocID, p.FPID, LEFT(COALESCE(f.Denumire, ''), 10), p.Suma
        FROM trelDocIDFpID p
        LEFT JOIN tblFP f ON p.FPID = f.FPID
        WHERE p.DocID IN (SELECT DocID FROM tblBonCurent);

        DELETE FROM trelDocIDFpID
        WHERE DocID IN (SELECT DocID FROM tblBonCurent);

        -- Golire.
        DELETE FROM tblNoteD;
        DELETE FROM tblBonCurent;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT @nr AS NrZ;
END
GO

SET ANSI_NULLS ON
GO
SET QUOTED_IDENTIFIER ON
GO
-- =============================================
-- Author:		Florian L.Stefanescu
-- Create date: 31/07/2018
-- Description:	Import produse StocManager DataRON
-- Last Author: Adrian Zeldea
-- Last modified: 17.09.2026
-- Version: Ciorbarie - restaurant - import TIB default si grupe conform tabel relationare

-- =============================================
CREATE OR ALTER PROCEDURE [dbo].[ImportProd] 
	-- Add the parameters for the stored procedure here
	@IP nvarchar(50) = 'localhost', 
	@user nvarchar(10)='sa',
	@PassWord nvarchar(10)
	
AS
BEGIN
	-- SET NOCOUNT ON added to prevent extra result sets from
	-- interfering with SELECT statements.
	SET NOCOUNT ON;

    -- Insert statements for procedure here
	Declare @Database as nvarchar(50)
	Declare @StatieID as int=1
	SELECT @Database = 'SourceDB'
    DECLARE	@return_value int=0
    Declare @MagID int=0
    --Select @MagID=MagID from tblMag
	Select @MagID = CAST(Value AS int) From tblSet Where Setting = 'MagID'
    Select @StatieID = CAST(Value AS int) From tblSet Where Setting = 'NrLogic'
    
	Select @Database = DataBaseName FROM tblConectare WHERE ID=1
	IF  EXISTS (SELECT srv.name FROM sys.servers srv WHERE srv.server_id != 0 AND srv.name =@IP) EXEC master.dbo.sp_dropserver @server=@IP, @droplogins='droplogins'
	/*
	EXEC sp_addlinkedserver @server=@IP
	EXEC sp_addlinkedsrvlogin @IP, 'false', NULL, @user, @PassWord
	*/
	IF NOT EXISTS (SELECT 1 FROM sys.servers WHERE name = @IP)
	BEGIN
		EXEC sp_addlinkedserver @server=@IP
		EXEC sp_addlinkedsrvlogin @IP, 'false', NULL, @user, @PassWord
	END
	ELSE
	BEGIN
		-- Optionally refresh login mapping if needed
		EXEC sp_addlinkedsrvlogin @IP, 'false', NULL, @user, @PassWord
	END
	BEGIN TRY
		BEGIN TRANSACTION
		/*
		Delete From tblFP
		
		EXECUTE('INSERT INTO tblFP
		( FPID, Denumire, Status, Poz) SELECT CAST(tblTIB.TIB AS int), tblTIB.Denumire, 1, CAST(tblTIB.TIB AS int) + 1 
		FROM ['+@IP+'].'+ @Database + '.dbo.tblTIB')
		*/

		delete from tblGrp
		
		EXECUTE('INSERT INTO tblGrp
		( NrGrp, Denumire, BackColor, Bold, FontColor, FontSize, FontType, Poz, Tint )
		SELECT DISTINCT tblGrp.NrGrp, tblGrp.Denumire, tblPozGrupe.BackColor, ISNULL(tblPozGrupe.Bold,0), tblPozGrupe.FontColor, tblPozGrupe.FontSize, tblPozGrupe.FontType, tblPozGrupe.Poz, ISNULL(tblPozGrupe.Tint,1) 
		FROM ['+@IP+'].'+ @Database + '.dbo.tblGrp
			INNER JOIN  ['+@IP+'].'+ @Database + '.dbo.tblPozGrupe ON tblGrp.NrGrp = tblPozGrupe.NrGrp and tblPozGrupe.MagID = ' + @MagID + '
			INNER JOIN ['+@IP+'].' + @Database + '.dbo.tblProd 
			INNER JOIN ['+@IP+'].' + @Database + '.dbo.tblPV ON tblProd.ProdID = tblPV.ProdID AND tblPV.MagID = ' + @MagID + '
			ON tblGrp.NrGrp = tblProd.NrGrp
					WHERE tblPozGrupe.Poz Between 1 And 50 AND tblProd.KP > 0 AND tblPV.Poz > 0')
		
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
			( ProdID, BarCod, Denumire, UM, IRP, IIVCN, DataUM, NrGrp, KP, BackColor, FontColor, FontSize, Bold, Poz, FontType, PV, Nr_TVA, MZ )
		SELECT tblProd.ProdID, tblProd.BarCod, tblProd.Denumire, tblProd.UM, tblProd.IRP, TRY_CAST(tblProd.IIVCN AS int), tblProd.DataUM, tblProd.NrGrp, tblProd.KP, 
		tblPV.BackColor, tblPV.FontColor, tblProd.FontSize, tblPV.Bold, tblPV.Poz, tblProd.FontType, tblPV.PV,
		CASE tblProd.TVA WHEN 21 THEN 1 WHEN 11 THEN 2 ELSE 3 END, tblPV.Flag
		FROM ['+@IP+'].' + @Database + '.dbo.tblProd INNER JOIN ['+@IP+'].' + @Database + '.dbo.tblPV ON tblProd.ProdID = tblPV.ProdID
		WHERE tblProd.ProdID>9 AND tblProd.KP>0 AND tblPV.Poz<=50 and tblPV.Poz > 0 AND tblPV.MagID = ' + @MagID)

		EXECUTE('INSERT INTO tblProd
			( ProdID, BarCod, Denumire, UM, IRP, IIVCN, DataUM, NrGrp, KP, BackColor, FontColor, FontSize, Bold, Poz, FontType, PV, Nr_TVA )
		SELECT tblProd.ProdID, tblProd.BarCod1, tblProd.Denumire, tblProd.UM, tblProd.IRP, TRY_CAST(tblProd.IIVCN AS int), tblProd.DataUM, tblProd.NrGrp, tblProd.KP, 
		tblPV.BackColor, tblPV.FontColor, tblProd.FontSize, tblPV.Bold, tblPV.Poz, tblProd.FontType, tblPV.PV,
		CASE tblProd.TVA WHEN 21 THEN 1 WHEN 11 THEN 2 ELSE 3 END
		FROM ['+@IP+'].' + @Database + '.dbo.tblProd INNER JOIN ['+@IP+'].' + @Database + '.dbo.tblPV ON tblProd.ProdID = tblPV.ProdID
		WHERE tblProd.BarCod1=''REDUCERE'' AND tblPV.MagID = ' + @MagID)


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
		EXECUTE usp_GetErrorInfo;
		IF (XACT_STATE()) <> 0
			ROLLBACK TRANSACTION;
	THROW;
	END CATCH
	--SELECT @ipServer, @p2
	
END
GO

