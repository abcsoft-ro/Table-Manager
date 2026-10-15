-- ============================================================================
-- TableManager 2.0.0 - schema bazei de date
-- Generat automat dintr-un script local de dezvoltare (nu editati manual).
-- Contine doar tabelele si procedurile folosite de aplicatie.
-- Numele bazei de date (Rual) este un implicit, configurabil la generare cu
-- --db=<nume>; aplicatia foloseste numele din api/db.local.php.
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

------------------------------------------------------------ tblMapKP
-- Mapare sectii de tiparire (KP) server -> local, folosita la import (ImportProd).
IF OBJECT_ID(N'dbo.tblMapKP', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[tblMapKP] (
    [KPServer] int NOT NULL,
    [KPLocal] int NOT NULL
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'PK_tblMapKP')
ALTER TABLE dbo.[tblMapKP] ADD CONSTRAINT [PK_tblMapKP] PRIMARY KEY CLUSTERED ([KPServer]);
GO

------------------------------------------------------------ tblMapSectii
-- Mapare sectii (tblSectii) server -> local, folosita la import (ImportProd).
IF OBJECT_ID(N'dbo.tblMapSectii', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[tblMapSectii] (
    [SectieServer] int NOT NULL,
    [SectieLocal] int NOT NULL
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'PK_tblMapSectii')
ALTER TABLE dbo.[tblMapSectii] ADD CONSTRAINT [PK_tblMapSectii] PRIMARY KEY CLUSTERED ([SectieServer]);
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
    [Nume] nvarchar(255) NULL,
    [Stare] bit NOT NULL
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
    [ParolaExit] nvarchar(20) NULL,
    [ParolaUpdate] nvarchar(20) NULL
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
    [Poz] int NULL
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
    [MZ] int NULL CONSTRAINT [DF__tblProd__MZ__69C6B1F5] DEFAULT ((0)),
    [MagID] int NULL
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
    [MotivDiscount] nvarchar(50) NULL,
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
    [OrderID] int NULL,
    [CodFiscBon] nvarchar(50) NULL,
    [RaspunsCasa] nvarchar(max) NULL
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
    [ClientID] nvarchar(50) NULL,
    [CodFiscBon] nvarchar(50) NULL,
    [RaspunsCasa] nvarchar(max) NULL
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

------------------------------------------------------------ temp_Send_Sql
-- Coada durabila de export catre serverul extern (tblConectare): fiecare
-- inchidere de nota scrie aici comanda EXEC EmitBon_Ext_NOU (Restaurant) sau
-- EmitBon_Ext_2 (FastFood). Serviciul Python (sync-service) ridica randurile
-- cu Preluat = 0, executa str_sql pe serverul extern si pune Preluat = 1.
IF OBJECT_ID(N'dbo.temp_Send_Sql', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[temp_Send_Sql] (
    [Id] int IDENTITY(1,1) NOT NULL,
    [DocID] int NULL,
    [str_sql] nvarchar(MAX) NULL,
    [Preluat] bit NOT NULL CONSTRAINT [DF_temp_Send_Sql_Preluat] DEFAULT ((0)),
    [Stare] nvarchar(10) NOT NULL CONSTRAINT [DF_temp_Send_Sql_Stare] DEFAULT ('pending'),
    [Attempts] int NOT NULL CONSTRAINT [DF_temp_Send_Sql_Attempts] DEFAULT ((0)),
    [NextAttempt] datetime2(7) NULL,
    [LastError] nvarchar(500) NULL,
    [CreatedAt] datetime2(7) NOT NULL CONSTRAINT [DF_temp_Send_Sql_Created] DEFAULT (sysdatetime()),
    [SentAt] datetime2(7) NULL,
    [RaspunsCasa] nvarchar(max) NULL,
    [Scop] nvarchar(20) NULL,
    [Rezultat] nvarchar(max) NULL
);
END
GO
IF COL_LENGTH('dbo.temp_Send_Sql','Scop') IS NULL
    ALTER TABLE dbo.[temp_Send_Sql] ADD [Scop] nvarchar(20) NULL;
GO
IF COL_LENGTH('dbo.temp_Send_Sql','Rezultat') IS NULL
    ALTER TABLE dbo.[temp_Send_Sql] ADD [Rezultat] nvarchar(max) NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'PK_temp_Send_Sql')
ALTER TABLE dbo.[temp_Send_Sql] ADD CONSTRAINT [PK_temp_Send_Sql] PRIMARY KEY CLUSTERED ([Id]);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_temp_Send_Sql_Preluat' AND object_id = OBJECT_ID(N'dbo.temp_Send_Sql'))
CREATE NONCLUSTERED INDEX [IX_temp_Send_Sql_Preluat] ON dbo.[temp_Send_Sql] ([Preluat],[Stare],[NextAttempt]) INCLUDE ([Id]);
GO

------------------------------------------------------------ tblFacturaDoc
-- Leaga nota locala (DocID) de factura generata pe server (GenFactPoS), ca
-- factura sa fie generata o singura data per nota; printurile ulterioare
-- refolosesc seria si numarul.
IF OBJECT_ID(N'dbo.tblFacturaDoc', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[tblFacturaDoc] (
    [DocID] int NOT NULL,
    [ServerDocID] int NULL,
    [Serie] nvarchar(10) NULL,
    [NrDocFisc] int NULL,
    [QueueId] int NULL,
    [Stare] nvarchar(12) NOT NULL CONSTRAINT [DF_tblFacturaDoc_Stare] DEFAULT ('generating'),
    [LastError] nvarchar(500) NULL,
    [ClientCIF] nvarchar(18) NULL,
    [ClientVAT] int NULL,
    [ClientDen] nvarchar(150) NULL,
    [ClientAdresa] nvarchar(250) NULL,
    [ClientJudet] nvarchar(50) NULL,
    [ClientOras] nvarchar(100) NULL,
    [ClientRegCom] nvarchar(15) NULL,
    [CreatedAt] datetime2(7) NOT NULL CONSTRAINT [DF_tblFacturaDoc_Created] DEFAULT (sysdatetime()),
    [UpdatedAt] datetime2(7) NULL
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'PK_tblFacturaDoc')
    ALTER TABLE dbo.[tblFacturaDoc] ADD CONSTRAINT [PK_tblFacturaDoc] PRIMARY KEY CLUSTERED ([DocID]);
GO

------------------------------------------------------------ tblPosBancaLog
-- Audit al comunicatiei cu POS-ul bancar (terminalul de card): fiecare apelare
-- a driverului din tblSet.CaleDriverPoSbanca la inchiderea unei note scrie un
-- rand aici (suma, TrxId, STATUS, MESAJ, EMITE_BON). Actiune = decizia finala a
-- utilizatorului la refuz: 'aprobat' / 'ignorat' / 'anulat' (NULL cat timp nu s-a
-- decis); 'config' marcheaza o eroare de configurare a driverului.
IF OBJECT_ID(N'dbo.tblPosBancaLog', N'U') IS NULL
BEGIN
CREATE TABLE dbo.[tblPosBancaLog] (
    [Id] int IDENTITY(1,1) NOT NULL,
    [DocID] int NULL,
    [NrDoc] int NULL,
    [Suma] decimal(18,2) NULL,
    [TrxId] nvarchar(50) NULL,
    [Status] nvarchar(50) NULL,
    [Mesaj] nvarchar(255) NULL,
    [EmiteBon] bit NULL,
    [Actiune] nvarchar(20) NULL,
    [CreatedAt] datetime2(7) NOT NULL CONSTRAINT [DF_tblPosBancaLog_Created] DEFAULT (sysdatetime()),
    [ResolvedAt] datetime2(7) NULL
);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'PK_tblPosBancaLog')
ALTER TABLE dbo.[tblPosBancaLog] ADD CONSTRAINT [PK_tblPosBancaLog] PRIMARY KEY CLUSTERED ([Id]);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_tblPosBancaLog_DocID' AND object_id = OBJECT_ID(N'dbo.tblPosBancaLog'))
CREATE NONCLUSTERED INDEX [IX_tblPosBancaLog_DocID] ON dbo.[tblPosBancaLog] ([DocID],[CreatedAt]);
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
             NrMasa, Stare, NrPrs, DocIDP, ClientID, CodFiscBon, RaspunsCasa)
        SELECT
             DocID, NrDoc, Data, RepID, ContR, MagID, TipDoc, NrOp, @nr, TotalB,
             SC, TIB, Ora, TVAA, TVAB, TVAC, TVAD, TVAE, TVAF, TVAG, TVAH,
             NrMasa, Stare, NrPrs, NULL, ClientID, CodFiscBon, RaspunsCasa
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
-- Last modified: 14.10.2026
-- Version: Import din una sau mai multe magazii (tblSet.MagID = '1,2'); la suprapuneri castiga primul MagID din lista

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
		EXECUTE usp_GetErrorInfo;
		IF (XACT_STATE()) <> 0
			ROLLBACK TRANSACTION;
	THROW;
	END CATCH
	--SELECT @ipServer, @p2
	
END
GO

