<?php
/**
 * print_common.php
 * Functii comune pentru coada durabila de tiparire (tblPrintQueue).
 *
 * Coada este singurul mecanism prin care aplicatia trimite ceva la tiparire:
 * PHP scrie un rand (in aceeasi tranzactie cu actiunea de business), iar
 * serviciul Python (print-service) il ridica prin api/print_queue.php si il
 * livreaza imprimantei. La esec, jobul ramane in coada si se reincearca.
 */
require_once __DIR__ . '/db.php';

// Adresa serviciului local de tiparire (print-service/server.py).
if (!defined('PRINT_SERVICE_BASE')) {
    define('PRINT_SERVICE_BASE', 'http://127.0.0.1:8756');
}

/**
 * Descrierea canonica a setarii tblSet.NrBon (contorul bonurilor de sectie).
 * Folosita atat la crearea randului, cat si pentru documentarea in Setari.
 */
function nrBonDescription() {
    return "Contorul bonurilor de sectie (bucatarie/bar): numarul de pe urmatorul bon trimis la sectie. Creste automat la fiecare Marcare si se reseteaza la raportul Z (reporneste de la 1). Editabila doar pentru reinitializare.";
}

/**
 * Creeaza setarea tblSet.NrBon (contor pornit de la 0) daca lipseste si
 * completeaza Descrierea. Randul apare astfel in ecranul Setari.
 */
function ensureNrBonSetting($conn) {
    @sqlsrv_query(
        $conn,
        "IF NOT EXISTS (SELECT 1 FROM tblSet WHERE Setting = 'NrBon')
             INSERT INTO tblSet (Setting, Value, Descriere, Grup) VALUES ('NrBon', '0', ?, 'General')
         ELSE
             UPDATE tblSet SET Descriere = ? WHERE Setting = 'NrBon' AND (Descriere IS NULL OR Descriere <> ?)",
        [nrBonDescription(), nrBonDescription(), nrBonDescription()]
    );
}

/**
 * Incrementeaza atomic contorul bonurilor de sectie si intoarce noua valoare.
 * Se apeleaza in interiorul tranzactiei de tiparire, ca un esec sa nu consume
 * numarul (la ROLLBACK revine si contorul).
 */
function nextNrBon($conn) {
    ensureNrBonSetting($conn);

    $stmt = sqlsrv_query($conn, "SELECT Value FROM tblSet WITH (UPDLOCK, HOLDLOCK) WHERE Setting = 'NrBon'");
    $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    $next = ((int)($row['Value'] ?? 0)) + 1;

    $upd = sqlsrv_query($conn, "UPDATE tblSet SET Value = ? WHERE Setting = 'NrBon'", [(string)$next]);
    if ($upd === false) { return null; }

    return $next;
}

/**
 * Adauga coloana tblBonCurent.MotivDiscount daca lipseste (deploy fara pasi
 * manuali). Retine motivul discountului aplicat pe toata nota, distinct de
 * motivul discountului pe linie (care sta in tblNoteD.[Comment]).
 */
function ensureBillDiscountMotiveColumn($conn) {
    static $done = false;
    if ($done) { return; }
    @sqlsrv_query(
        $conn,
        "IF COL_LENGTH('dbo.tblBonCurent','MotivDiscount') IS NULL
             ALTER TABLE dbo.tblBonCurent ADD MotivDiscount nvarchar(50) NULL"
    );
    $done = true;
}

/**
 * Adauga coloana tblParola.ParolaUpdate daca lipseste (deploy fara pasi
 * manuali). Parola care protejeaza modulul de actualizare din GitHub.
 */
function ensureParolaUpdateColumn($conn) {
    static $done = false;
    if ($done) { return; }
    @sqlsrv_query(
        $conn,
        "IF COL_LENGTH('dbo.tblParola','ParolaUpdate') IS NULL
             ALTER TABLE dbo.tblParola ADD ParolaUpdate nvarchar(20) NULL"
    );
    $done = true;
}

/**
 * Creeaza tabela tblPrintQueue daca nu exista (deploy fara pasi manuali).
 */
function ensurePrintQueueTable($conn) {
    static $done = false;
    if ($done) { return; }

    $sqlTable = "
    IF OBJECT_ID('dbo.tblPrintQueue','U') IS NULL
    BEGIN
        CREATE TABLE dbo.tblPrintQueue (
            JobID       INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
            Tip         NVARCHAR(10)  NOT NULL,
            RefDocID    INT           NULL,
            PrinterNr   INT           NULL,
            Payload     NVARCHAR(MAX) NULL,
            Stare       NVARCHAR(10)  NOT NULL CONSTRAINT DF_tblPrintQueue_Stare DEFAULT ('pending'),
            Attempts    INT           NOT NULL CONSTRAINT DF_tblPrintQueue_Attempts DEFAULT (0),
            NextAttempt DATETIME2     NULL,
            LastError   NVARCHAR(255) NULL,
            CreatedAt   DATETIME2     NOT NULL CONSTRAINT DF_tblPrintQueue_Created DEFAULT (SYSDATETIME()),
            PrintedAt   DATETIME2     NULL
        );
        CREATE INDEX IX_tblPrintQueue_Stare ON dbo.tblPrintQueue (Stare, NextAttempt) INCLUDE (JobID);
    END";
    @sqlsrv_query($conn, $sqlTable);

    $done = true;
}

/**
 * Adauga un job in coada. Intoarce JobID-ul nou sau false la eroare.
 */
function enqueuePrintJob($conn, $tip, $refDocId, $printerNr, $payload) {
    ensurePrintQueueTable($conn);

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) { $json = '{}'; }

    $sql = "INSERT INTO tblPrintQueue (Tip, RefDocID, PrinterNr, Payload, Stare, Attempts)
            VALUES (?, ?, ?, ?, 'pending', 0); SELECT SCOPE_IDENTITY() AS JobID;";
    $stmt = sqlsrv_query($conn, $sql, [
        $tip,
        ($refDocId === null ? null : (int)$refDocId),
        ($printerNr === null ? null : (int)$printerNr),
        $json
    ]);
    if ($stmt === false) { return false; }

    sqlsrv_next_result($stmt);
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return $row ? (int)$row['JobID'] : false;
}

/**
 * Trezeste imediat worker-ul Python (best-effort, nu blocheaza daca lipseste).
 */
function wakePrintService() {
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 1, 'ignore_errors' => true]
    ]);
    @file_get_contents(PRINT_SERVICE_BASE . '/wake', false, $ctx);
}

/**
 * Formateaza o data venita din sqlsrv (ReturnDatesAsStrings = true) ca string.
 */
function printQueueDateToString($value) {
    if ($value === null || $value === '') { return null; }
    if ($value instanceof DateTime) {
        return $value->format('d-m-Y H:i:s');
    }
    try {
        $dt = new DateTime((string)$value);
        return $dt->format('d-m-Y H:i:s');
    } catch (Exception $e) {
        return (string)$value;
    }
}
