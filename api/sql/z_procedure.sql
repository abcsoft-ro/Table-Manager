/*
 * Procedura stocata pentru inchiderea Z (golirea zilei de lucru).
 *
 * Efectueaza, intr-o SINGURA tranzactie (totul sau nimic):
 *   1. verifica ca toate mesele sunt inchise (niciun bon cu Stare <> 'I');
 *   2. calculeaza urmatorul numar Z = ISNULL(MAX(tblNrZ.NrZ), 0) + 1;
 *   3. scrie randul de Z in tblNrZ (NrZ, NRNota = ultimul NrDoc, Data, Ora);
 *   4. arhiveaza tblBonCurent -> tblBon (stampilat cu NrZ curent);
 *   5. arhiveaza tblNoteD -> tempECR;
 *   6. goleste tblNoteD si tblBonCurent;
 *   7. reseteaza contorul bonurilor de sectie tblSet.NrBon la 0.
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

        -- Reset contorul bonurilor de sectie (numerotarea reporneste de la 1).
        IF EXISTS (SELECT 1 FROM tblSet WHERE Setting = 'NrBon')
            UPDATE tblSet SET Value = '0' WHERE Setting = 'NrBon';

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT @nr AS NrZ;
END
