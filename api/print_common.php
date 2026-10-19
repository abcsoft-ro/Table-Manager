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
 *
 * @param resource $conn
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
 *
 * @param resource $conn
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
 *
 * @param resource $conn
 */
function ensureBillDiscountMotiveColumn($conn) {
    static $done = false;
    if ($done) { return; }
    @sqlsrv_query(
        $conn,
        "IF COL_LENGTH('dbo.tblBonCurent','MotivDiscount') IS NULL
             ALTER TABLE dbo.tblBonCurent ADD MotivDiscount nvarchar(50) NULL",
        null,
        ['QueryTimeout' => 15]
    );
    $done = true;
}

/**
 * Adauga coloana CodFiscBon (codul fiscal al clientului, afisat pe bonul
 * fiscal) in tblBonCurent si tblBon daca lipseste (deploy fara pasi manuali).
 * In tblBonCurent tine codul notei deschise/inchise, iar Z-ul il arhiveaza in
 * tblBon.CodFiscBon.
 *
 * @param resource $conn
 */
function ensureCodFiscBonColumns($conn) {
    static $done = false;
    if ($done) { return; }
    @sqlsrv_query(
        $conn,
        "IF COL_LENGTH('dbo.tblBonCurent','CodFiscBon') IS NULL
             ALTER TABLE dbo.tblBonCurent ADD CodFiscBon nvarchar(50) NULL;
         IF COL_LENGTH('dbo.tblBon','CodFiscBon') IS NULL
             ALTER TABLE dbo.tblBon ADD CodFiscBon nvarchar(50) NULL",
        null,
        ['QueryTimeout' => 15]
    );
    $done = true;
}

/**
 * Adauga coloana RaspunsCasa (continutul brut al raspunsului casei de marcat,
 * cand tblSet.AsteaptaRaspunsCasa = 1) in tblBonCurent si tblBon daca lipseste.
 * Aceeasi valoare ca temp_Send_Sql.RaspunsCasa; Z-ul o arhiveaza in tblBon.
 *
 * @param resource $conn
 */
function ensureRaspunsCasaColumns($conn) {
    static $done = false;
    if ($done) { return; }
    @sqlsrv_query(
        $conn,
        "IF COL_LENGTH('dbo.tblBonCurent','RaspunsCasa') IS NULL
             ALTER TABLE dbo.tblBonCurent ADD RaspunsCasa nvarchar(max) NULL;
         IF COL_LENGTH('dbo.tblBon','RaspunsCasa') IS NULL
             ALTER TABLE dbo.tblBon ADD RaspunsCasa nvarchar(max) NULL",
        null,
        ['QueryTimeout' => 15]
    );
    $done = true;
}

/**
 * Adauga coloanele folosite de fluxul de comanda daca lipsesc (deploy fara
 * pasi manuali pe instalatiile vechi). In special tblProd.MagID, citita de
 * add_product la selectarea produsului: fara ea, query-ul esueaza si
 * sqlsrv_fetch_array(false) arunca un TypeError (raspuns HTML in loc de JSON).
 *
 * @param resource $conn
 */
function ensureOrderSchema($conn) {
    static $done = false;
    if ($done) { return; }
    @sqlsrv_query(
        $conn,
        "IF COL_LENGTH('dbo.tblProd','MagID') IS NULL
             ALTER TABLE dbo.tblProd ADD MagID int NULL;
         IF COL_LENGTH('dbo.tblNoteD','Magid') IS NULL
             ALTER TABLE dbo.tblNoteD ADD Magid smallint NULL;
         IF COL_LENGTH('dbo.tblNoteD','StornoRef') IS NULL
             ALTER TABLE dbo.tblNoteD ADD StornoRef int NULL;
         IF COL_LENGTH('dbo.tblNoteD','Comment') IS NULL
             ALTER TABLE dbo.tblNoteD ADD [Comment] nvarchar(50) NULL;
         IF COL_LENGTH('dbo.tblBonCurent','MagID') IS NULL
             ALTER TABLE dbo.tblBonCurent ADD MagID int NULL",
        null,
        ['QueryTimeout' => 15]
    );
    $done = true;
}

/**
 * Descrierile setarilor de evidentiere a discountului/stornarii pe bonul fiscal.
 * Sunt definite o singura data aici, ca setari.php si order_action.php sa foloseasca
 * acelasi text (afisat in Setari → Casa Marcat).
 *
 * @return array
 */
function fiscalEvidSettingDescriptions() {
    return [
        'EvidDiscBonFisc' => "Afiseaza discountul pe bonul fiscal, pe fiecare linie de produs (produsul la pret de catalog, urmat de comanda de discount): 1 = DA, 0 = NU (implicit). Se refera numai la discount; stornarea se seteaza separat (EvidStornoBonFisc).",
        'EvidStornoBonFisc' => "Afiseaza liniile de stornare pe bonul fiscal: 1 = DA, 0 = NU (implicit). Se refera numai la stornare; discountul se seteaza separat (EvidDiscBonFisc)."
    ];
}

/**
 * Migreaza vechea setare unica BonFiscalDiscStorno in cele doua setari separate
 * (EvidDiscBonFisc = discount pe fiecare linie, EvidStornoBonFisc = linii de
 * storno). Daca vechea setare era DA, ambele noi pornesc pe DA (comportamentul
 * de dinainte se pastreaza); apoi randul vechi se sterge. Idempotent, ruleaza o
 * singura data pe request si nu suprascrie valorile deja setate de utilizator.
 *
 * @param resource $conn
 */
function ensureFiscalEvidSettings($conn) {
    static $done = false;
    if ($done) { return; }
    $done = true;

    $chk = @sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'BonFiscalDiscStorno'");
    $row = $chk ? sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC) : null;
    $legacy = ($row && (int)trim((string)$row['Value']) === 1) ? '1' : '0';

    foreach (fiscalEvidSettingDescriptions() as $setting => $desc) {
        $stmt = @sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = ?", [$setting]);
        $exists = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
        if (!$exists) {
            @sqlsrv_query(
                $conn,
                "INSERT INTO tblSet (Setting, Value, Descriere, Grup) VALUES (?, ?, ?, 'Casa')",
                [$setting, $legacy, $desc]
            );
        } else {
            @sqlsrv_query(
                $conn,
                "UPDATE tblSet SET Descriere = ? WHERE Setting = ? AND (Descriere IS NULL OR Descriere <> ?)",
                [$desc, $setting, $desc]
            );
        }
    }
    @sqlsrv_query($conn, "DELETE FROM tblSet WHERE Setting = 'BonFiscalDiscStorno'");
}

/**
 * Adauga coloana tblParola.ParolaUpdate daca lipseste (deploy fara pasi
 * manuali). Parola care protejeaza modulul de actualizare din GitHub.
 *
 * @param resource $conn
 */
function ensureParolaUpdateColumn($conn) {
    static $done = false;
    if ($done) { return; }
    @sqlsrv_query(
        $conn,
        "IF COL_LENGTH('dbo.tblParola','ParolaUpdate') IS NULL
             ALTER TABLE dbo.tblParola ADD ParolaUpdate nvarchar(20) NULL",
        null,
        ['QueryTimeout' => 15]
    );
    $done = true;
}

/**
 * Creeaza tabela tblPrintQueue daca nu exista (deploy fara pasi manuali).
 *
 * @param resource $conn
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
    @sqlsrv_query($conn, $sqlTable, null, ['QueryTimeout' => 15]);

    $done = true;
}

/**
 * Adauga un job in coada. Intoarce JobID-ul nou sau false la eroare.
 *
 * @param resource $conn
 * @param string $tip
 * @param int $refDocId
 * @param int $printerNr
 * @param array $payload
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
 *
 * @param mixed $value
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
