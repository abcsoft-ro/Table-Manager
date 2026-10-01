-- ============================================================================
-- TableManager 2.1C - date de referinta (seed) pentru o instalare noua
-- Generat automat dintr-un script local de dezvoltare, apoi sanitizat.
-- NU contine date reale: parolele, credențialele si datele firmei sunt demo.
-- Se ruleaza dupa db/schema.sql, pe o baza de date goala.
-- ============================================================================

USE [Rual];
GO
SET NOCOUNT ON;
GO

------------------------------------------------------------ tblTVA (3 randuri)
DELETE FROM dbo.[tblTVA];
GO
INSERT INTO dbo.[tblTVA] ([Nr_TVA], [Cota]) VALUES
(1, 21),
(2, 11),
(3, 0);
GO

------------------------------------------------------------ tblSectii (2 randuri)
DELETE FROM dbo.[tblSectii];
GO
INSERT INTO dbo.[tblSectii] ([Sectie], [Denumire], [Status]) VALUES
(1, N'BAR', 1),
(2, N'BUCATARIE', 1);
GO

------------------------------------------------------------ tblFP (6 randuri)
DELETE FROM dbo.[tblFP];
GO
INSERT INTO dbo.[tblFP] ([FPID], [Denumire], [Status], [Poz]) VALUES
(0, N'Numerar', 1, 2),
(1, N'CARD', 1, 1),
(2, N'CEC', 1, 3),
(3, N'TICHET', 1, 4),
(4, N'OP', 1, 5),
(5, N'VOUCHER', 1, 6);
GO

------------------------------------------------------------ tblKP (2 randuri)
DELETE FROM dbo.[tblKP];
GO
INSERT INTO dbo.[tblKP] ([NrLogic], [Serie], [Stare], [Nume], [MagID], [CaleTSC], [raport], [DenumirePrinter]) VALUES
(1, N'05', 1, N'BAR', 1, NULL, N'80rapCmdSectie', N'Imprimanta demo'),
(2, N'06', 1, N'BUCATARIE', 2, NULL, N'80rapCmdSectie', N'Imprimanta demo');
GO

------------------------------------------------------------ tblSet (37 randuri)
DELETE FROM dbo.[tblSet];
GO
INSERT INTO dbo.[tblSet] ([Setting], [Value], [Descriere], [Grup]) VALUES
(N'AfiseajClient', N'1', N'Afisaj client activ: 1 = DA, 0 = NU.', N'Afiseaj'),
(N'BaudRateAfisaj', N'9600', NULL, N'Afiseaj'),
(N'CaleDriverAfiseajClient', N'', N'Calea catre driverul/executabilul afisajului client.', N'Afiseaj'),
(N'CaleDriverCantar', N'', N'Calea catre driverul/executabilul cantarului electronic.', N'Cantar'),
(N'CaleDriverPoSbanca', N'', N'Calea catre driverul/executabilul POS-ului bancar.', N'PoSbanca'),
(N'CaleFisierComenziAfiseajClient', N'', N'Folderul in care se scriu fisierele de comenzi catre afisajul client.', N'Afiseaj'),
(N'CaleFisierComenziECR', N'C:\POS\fiscal\bonuri', N'Folderul in care se scriu fisierele de comenzi catre casa de marcat fiscala.', N'Casa'),
(N'CaleFisierRaspunsCantar', N'', N'Folderul in care cantarul electronic scrie fisierele de raspuns.', N'Cantar'),
(N'CaleFisierRaspunsECR', N'C:\POS\fiscal\raspuns', N'Folderul in care casa de marcat fiscala scrie fisierele de raspuns.', N'Casa'),
(N'CaleFisierRaspunsPoSbanca', N'', N'Fisierul in care POS-ul bancar scrie raspunsul.', N'PoSbanca'),
(N'Cantar', N'0', N'Cantar electronic activ: 1 = DA, 0 = NU.', N'Cantar'),
(N'ConsumerKey', N'', NULL, N'web'),
(N'ConsumerSecret', N'', NULL, N'web'),
(N'Delay_cantar', N'650', NULL, N'Cantar'),
(N'DistrRand1', N'demo.local', N'Linia 1 de contact (dupa sigla) in footer-ul ecranului Mese', N'General'),
(N'DistrRand2', N'Suport demo', N'Linia 2 de contact (dupa sigla) in footer-ul ecranului Mese', N'General'),
(N'MagID', N'11', N'ID Magazie (gestiune) import grupe, produse, preturi server', N'General'),
(N'MeniulZilei', N'1', N'1-Meniul zilei activ 0-Meniul zilei inactiv', N'General'),
(N'Mod_logare', N'1', N'Modul de logare a ospatarilor: 1 = ospatarul ramane logat pe toata durata sesiunii; 0 = delogare automata la iesirea de pe masa (reautentificare obligatorie).', N'General'),
(N'NrBon', N'1000', NULL, N'General'),
(N'NrBonCmd', N'54982', NULL, N'General'),
(N'NrLogic', N'1', NULL, N'General'),
(N'NrZecCant', N'1', NULL, N'General'),
(N'ODBC_TimeOut', N'6', NULL, N'ODBC'),
(N'OraSfarsitPromo', NULL, NULL, N'General'),
(N'OraStartPromo', NULL, NULL, N'General'),
(N'ParolaDiscount', N'1', NULL, N'General'),
(N'PortComAfiseaj', N'5', NULL, N'Afiseaj'),
(N'PoSbanca', N'0', N'POS bancar activ: 1 = DA, 0 = NU.', N'PoSbanca'),
(N'RED', N'1', NULL, N'General'),
(N'Retea', N'0', NULL, N'General'),
(N'Server', N'1', N'0 = aplicatia functioneaza independent; 1 = aplicatia incarca grupele si produsele de pe un server extern, aplicatia exporta vanzarile catre serverul extern prin serviciul python sync-service.', N'ODBC'),
(N'SiteUrlApi', N'', NULL, N'web'),
(N'Storno_parola', N'0', NULL, N'General'),
(N'TipCasaMarcat', N'Datecs', N'Tipul casei de marcat fiscale: Datecs, FiscalNet sau Tremol.', N'Casa'),
(N'TipVanz', N'0', N'Tipul de vanzare: 0 = Restaurant (ecran de mese, comanda la sectie, nota de plata); 1 = FastFood (fara ecran de mese, comanda nu pleaca la sectie, doar bon fiscal).', N'General'),
(N'TransferMasa', N'1', NULL, N'General');
GO

------------------------------------------------------------ tblParola (1 randuri)
DELETE FROM dbo.[tblParola];
GO
INSERT INTO dbo.[tblParola] ([Id], [ParolaProgramare], [ParolaRapoarte], [ParolaStornare], [ParolaDiscount], [ParolaExit], [ParolaUpdate]) VALUES
(1, N'', N'', N'', N'', N'', N'');
GO

------------------------------------------------------------ tblOsp (5 randuri)
DELETE FROM dbo.[tblOsp];
GO
INSERT INTO dbo.[tblOsp] ([NrOsp], [Nume], [Expl], [Parola], [Blocat]) VALUES
(N'10', N'Ospatar 10', N'Casier', N'1', 0),
(N'11', N'Ospatar 11', N'Sef Restaurant', N'1', 0),
(N'12', N'Ospatar 12', N'manager productie', N'1', 0),
(N'18', N'Ospatar 18', N'OP. FACTURARE DT', N'1', 0),
(N'8', N'Ospatar 8', N'Casier', N'1', 0);
GO

------------------------------------------------------------ tblAntet (8 randuri)
DELETE FROM dbo.[tblAntet];
GO
INSERT INTO dbo.[tblAntet] ([Nume], [Adresa], [Seria], [NumeFont], [Size], [CodFiscF], [RegCom], [Judet], [Cont], [Banca], [Denumire2], [Bold], [VersiuneDB], [PlatitorTVA], [CaleDateLogo], [QRCodeText], [SizeModeLogo], [Oras], [CodPostal], [PersContact], [email], [website], [telefon]) VALUES
(N'RESTAURANT DEMO', N'Strada Exemplu nr. 1', N'0001', N'A', 10, N'RO00000000', N'J00/0000/2024', N'BUCURESTI', N'RO00BANK000000000000', N'BANCA DEMO', N'DEMO SRL', 0, N'TableManager 2.1C', 1, N'', N'', 2, N'Bucuresti', N'010101', N'', N'contact@example.com', N'https://example.com', N''),
(N'Va multumim!', NULL, N'F1', N'A', 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, N'TableManager 2.1C', 1, N'', NULL, 3, NULL, NULL, NULL, NULL, NULL, NULL),
(N'Va mai asteptam!', NULL, N'F2', N'A', 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, N'TableManager 2.1C', 1, N'', NULL, 3, NULL, NULL, NULL, NULL, NULL, NULL),
(N'RESTAURANT DEMO', NULL, N'H1', N'A', 17, NULL, NULL, NULL, NULL, NULL, NULL, 1, N'TableManager 2.1C', 1, N'', NULL, 3, NULL, NULL, NULL, NULL, NULL, NULL),
(N'Strada Exemplu nr. 1', NULL, N'H2', N'A', 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, N'TableManager 2.1C', 1, N'', NULL, 3, NULL, NULL, NULL, NULL, NULL, NULL),
(N'Telefon: 0000.000.000', NULL, N'H3', N'A', 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, N'TableManager 2.1C', 1, N'', NULL, 3, NULL, NULL, NULL, NULL, NULL, NULL),
(N'', NULL, N'P1', N'A', 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, N'TableManager 2.1C', 1, N'', NULL, 3, NULL, NULL, NULL, NULL, NULL, NULL),
(N'', NULL, N'P2', N'A', 0, NULL, NULL, NULL, NULL, NULL, NULL, 0, N'TableManager 2.1C', 1, N'', NULL, 3, NULL, NULL, NULL, NULL, NULL, NULL);
GO

------------------------------------------------------------ tblMese (80 randuri)
DELETE FROM dbo.[tblMese];
GO
INSERT INTO dbo.[tblMese] ([NrMasa], [BackColor], [ForeColor], [Bold], [Afisez], [NrPOS], [Obs]) VALUES
(1, 0, 255, 1, 1, NULL, NULL),
(2, 0, 255, 1, 1, NULL, NULL),
(3, 0, 255, 1, 1, NULL, NULL),
(4, 0, 255, 1, 1, NULL, NULL),
(5, 0, 255, 1, 1, NULL, NULL),
(6, 0, 255, 1, 1, NULL, NULL),
(7, 0, 255, 1, 1, NULL, NULL),
(8, 0, 255, 1, 1, NULL, NULL),
(9, 0, 255, 1, 1, NULL, NULL),
(11, 0, 255, 1, 1, NULL, NULL),
(12, 0, 255, 1, 1, NULL, NULL),
(13, 0, 255, 1, 1, NULL, NULL),
(14, 0, 255, 1, 1, NULL, NULL),
(15, 0, 255, 1, 1, NULL, NULL),
(16, 0, 255, 1, 1, NULL, NULL),
(17, 0, 255, 1, 1, NULL, NULL),
(18, 0, 255, 1, 1, NULL, NULL),
(19, 0, 255, 1, 0, NULL, NULL),
(20, 0, 255, 1, 0, NULL, NULL),
(21, 16711680, 255, 1, 1, NULL, NULL),
(22, 16711680, 255, 1, 1, NULL, NULL),
(23, 16711680, 255, 1, 1, NULL, NULL),
(24, 16711680, 255, 1, 1, NULL, NULL),
(25, 16711680, 255, 1, 1, NULL, NULL),
(26, 16711680, 255, 1, 1, NULL, NULL),
(27, 16711680, 16777215, 1, 0, NULL, NULL),
(28, 16711680, 255, 1, 0, NULL, NULL),
(29, 16711680, 255, 1, 0, NULL, NULL),
(30, 16711680, 255, 1, 0, NULL, NULL),
(31, 16711680, 255, 1, 1, NULL, NULL),
(32, 16711680, 255, 1, 1, NULL, NULL),
(33, 16711680, 255, 1, 1, NULL, NULL),
(34, 16711680, 255, 1, 1, NULL, NULL),
(35, 16711680, 255, 1, 1, NULL, NULL),
(36, 16711680, 255, 1, 1, NULL, NULL),
(37, 16711680, 255, 1, 1, NULL, NULL),
(38, 16711680, 255, 1, 1, NULL, NULL),
(39, 16711680, 255, 1, 1, NULL, NULL),
(40, 16711680, 255, 1, 1, NULL, NULL),
(41, 65280, 255, 1, 1, NULL, NULL),
(42, 65280, 255, 1, 1, NULL, NULL),
(43, 65280, 255, 1, 1, NULL, NULL),
(44, 65280, 255, 1, 1, NULL, NULL),
(45, 65280, 255, 1, 1, NULL, NULL),
(46, 65280, 255, 1, 1, NULL, NULL),
(47, 65280, 255, 1, 1, NULL, NULL),
(48, 65280, 255, 1, 1, NULL, NULL),
(49, 65280, 255, 1, 1, NULL, NULL),
(50, 65280, 255, 1, 1, NULL, NULL),
(51, 65280, 255, 1, 1, NULL, NULL),
(52, 65280, 255, 1, 1, NULL, NULL),
(53, 65280, 255, 1, 1, NULL, NULL),
(54, 65280, 255, 1, 1, NULL, NULL),
(55, 65280, 255, 1, 1, NULL, N''),
(56, 65280, 255, 1, 1, NULL, N''),
(57, 65280, 255, 1, 1, NULL, N''),
(58, 65280, 255, 1, 1, NULL, NULL),
(59, 65280, 255, 1, 1, NULL, NULL),
(60, 65280, 255, 1, 1, NULL, NULL),
(61, -2147483633, 0, 1, 1, NULL, NULL),
(62, -2147483633, 0, 1, 1, NULL, NULL),
(63, -2147483633, 0, 1, 1, NULL, NULL),
(64, -2147483633, 0, 1, 1, NULL, NULL),
(65, -2147483633, 0, 1, 1, NULL, NULL),
(66, -2147483633, 0, 1, 1, NULL, NULL),
(67, -2147483633, 0, 1, 1, NULL, NULL),
(68, -2147483633, 0, 1, 1, NULL, NULL),
(69, -2147483633, 0, 1, 1, NULL, NULL),
(70, -2147483633, 0, 1, 1, NULL, NULL),
(71, -2147483633, 0, 1, 1, NULL, NULL),
(72, -2147483633, 0, 1, 1, NULL, NULL),
(73, -2147483633, 0, 1, 1, NULL, NULL),
(74, -2147483633, 0, 1, 1, NULL, NULL),
(75, -2147483633, 0, 1, 1, NULL, NULL),
(76, -2147483633, 0, 1, 1, NULL, NULL),
(77, -2147483633, 0, 1, 1, NULL, NULL),
(78, -2147483633, 0, 1, 1, NULL, NULL),
(79, -2147483633, 0, 1, 1, NULL, N''),
(80, -2147483633, 16777215, 1, 1, NULL, N'Protocol'),
(10, 0, 16777215, 0, 1, NULL, NULL);
GO

------------------------------------------------------------ tblMesaj (25 randuri)
DELETE FROM dbo.[tblMesaj];
GO
INSERT INTO dbo.[tblMesaj] ([Nrmesaj], [Mesaj]) VALUES
(N'1', N'FARA CEAPA'),
(N'10', N'FARA PIPER'),
(N'11', N'FARA SARE'),
(N'12', N'LA ACEIASI MASA'),
(N'13', N'FARA VERDEATA'),
(N'14', N'PUTIN PICANT'),
(N'15', N'FARA NIMIC'),
(N'2', N'MEDIU'),
(N'21', N'FOARTE PICANT'),
(N'22', N'NEPICANT'),
(N'23', N'PACHET'),
(N'24', N'FARA CIUPERCI'),
(N'3', N'FIERBINTE'),
(N'4', N'CALDUT'),
(N'5', N'MAI  CRUDA'),
(N'51', N'Mediu rare'),
(N'52', N'RARE'),
(N'53', N'Cartofi'),
(N'54', N'Orzzo'),
(N'55', N'DOAR CU SALATA'),
(N'56', N'FARA CARTOFI'),
(N'6', N'IMPREUNA'),
(N'7', N'ANDANTE'),
(N'8', N'BINE FACUT'),
(N'9', N'FARA CONDIMENTE');
GO

------------------------------------------------------------ tblConectare (1 randuri)
DELETE FROM dbo.[tblConectare];
GO
INSERT INTO dbo.[tblConectare] ([ServerIP], [ServerName], [DataBaseName], [UserName], [Password], [TC], [ODBC_connect_string]) VALUES
(N'', N'', N'', N'', N'', 0, N'');
GO

------------------------------------------------------------ tblGrp (meniu demo)
DELETE FROM dbo.[tblGrp];
GO
INSERT INTO dbo.[tblGrp] ([NrGrp],[Denumire],[BackColor],[FontColor],[FontSize],[Bold],[FontType],[Tint],[Poz],[NrKp]) VALUES
(1, N'Supe', 15267280, 0, 14, 0, N'Arial', 0, 1, 2),
(2, N'Feluri principale', 16769728, 0, 14, 0, N'Arial', 0, 2, 2),
(3, N'Garnituri', 16773808, 0, 14, 0, N'Arial', 0, 3, 2),
(4, N'Salate', 14217424, 0, 14, 0, N'Arial', 0, 4, 2),
(5, N'Deserturi', 15981040, 0, 14, 0, N'Arial', 0, 5, 2),
(6, N'Bauturi racoritoare', 14215416, 0, 14, 0, N'Arial', 0, 6, 1),
(7, N'Cafea', 15259848, 0, 14, 0, N'Arial', 0, 7, 1),
(8, N'Bere', 16312504, 0, 14, 0, N'Arial', 0, 8, 1);
GO

------------------------------------------------------------ tblProd (meniu demo)
DELETE FROM dbo.[tblProd];
GO
INSERT INTO dbo.[tblProd] ([ProdID],[BarCod],[Denumire],[UM],[NrGrp],[KP],[Bold],[Poz],[Sectie],[PV],[Nr_TVA],[MZ]) VALUES
(101, N'2800000001017', N'Ciorba de burta', N'buc', 1, 2, 0, 1, 2, 22.00, 1, 0),
(102, N'2800000001024', N'Supa crema de legume', N'buc', 1, 2, 0, 2, 2, 18.00, 1, 0),
(201, N'2800000002014', N'Snitel de pui cu cartofi', N'buc', 2, 2, 0, 1, 2, 38.00, 1, 1),
(202, N'2800000002021', N'Cordon bleu de pui', N'buc', 2, 2, 0, 2, 2, 40.00, 1, 0),
(203, N'2800000002038', N'Pasta carbonara', N'buc', 2, 2, 0, 3, 2, 35.00, 1, 0),
(204, N'2800000002045', N'Burger de vita', N'buc', 2, 2, 0, 4, 2, 42.00, 1, 1),
(301, N'2800000003011', N'Cartofi prajiti', N'buc', 3, 2, 0, 1, 2, 12.00, 1, 0),
(302, N'2800000003028', N'Orez cu legume', N'buc', 3, 2, 0, 2, 2, 12.00, 1, 0),
(401, N'2800000004018', N'Salata greceasca', N'buc', 4, 2, 0, 1, 2, 26.00, 1, 0),
(402, N'2800000004025', N'Salata Caesar', N'buc', 4, 2, 0, 2, 2, 30.00, 1, 0),
(501, N'2800000005015', N'Tiramisu', N'buc', 5, 2, 0, 1, 2, 22.00, 1, 0),
(502, N'2800000005022', N'Papanasi', N'buc', 5, 2, 0, 2, 2, 24.00, 1, 0),
(601, N'2800000006012', N'Coca-Cola', N'buc', 6, 1, 0, 1, 1, 10.00, 1, 0),
(602, N'2800000006029', N'Apa minerala', N'buc', 6, 1, 0, 2, 1, 8.00, 2, 0),
(701, N'2800000007019', N'Espresso', N'buc', 7, 1, 0, 1, 1, 9.00, 1, 0),
(702, N'2800000007026', N'Cappuccino', N'buc', 7, 1, 0, 2, 1, 13.00, 1, 0),
(801, N'2800000008016', N'Bere la halba', N'buc', 8, 1, 0, 1, 1, 12.00, 1, 0),
(802, N'2800000008023', N'Bere la sticla', N'buc', 8, 1, 0, 2, 1, 14.00, 1, 0);
GO
