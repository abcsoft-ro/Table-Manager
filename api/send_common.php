<?php
/**
 * send_common.php
 * Coada durabila de export catre serverul extern (temp_Send_Sql).
 *
 * La inchiderea unei note, aplicatia construieste comanda SQL catre procedura
 * externa EmitBon_Ext_NOU (Restaurant) / EmitBon_Ext_2 (FastFood) si o scrie in
 * temp_Send_Sql, in aceeasi tranzactie cu inchiderea bonului. Serviciul Python
 * (sync-service) ridica randurile cu Preluat = 0 prin api/send_queue.php, se
 * conecteaza la serverul/baza din tblConectare, executa procedura si marcheaza
 * Preluat = 1. Daca serverul extern este oprit, randul ramane in coada si este
 * reincercat cu backoff - intentia de export nu se pierde.
 */
require_once __DIR__ . '/db.php';

// Adresa serviciului local de sincronizare (sync-service/server.py).
if (!defined('SYNC_SERVICE_BASE')) {
    define('SYNC_SERVICE_BASE', 'http://127.0.0.1:8757');
}

/**
 * Creeaza tabela temp_Send_Sql daca nu exista si, pe instalari vechi, o
 * completeaza cu coloanele de coada care lipsesc (deploy fara pasi manuali).
 *
 * In productie tabela poate exista deja intr-o forma legacy
 * (Id, str_sql, Data, Ora, Preluat), asa ca adaugam doar ce lipseste.
 *
 * @param resource $conn
 */
function ensureSendSqlTable($conn) {
    static $done = false;
    if ($done) { return; }

    $sqlTable = "
    IF OBJECT_ID('dbo.temp_Send_Sql','U') IS NULL
    BEGIN
        CREATE TABLE dbo.temp_Send_Sql (
            Id          INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
            DocID       INT           NULL,
            str_sql     NVARCHAR(MAX) NULL,
            Preluat     BIT           NOT NULL CONSTRAINT DF_temp_Send_Sql_Preluat DEFAULT (0),
            Stare       NVARCHAR(10)  NOT NULL CONSTRAINT DF_temp_Send_Sql_Stare DEFAULT ('pending'),
            Attempts    INT           NOT NULL CONSTRAINT DF_temp_Send_Sql_Attempts DEFAULT (0),
            NextAttempt DATETIME2     NULL,
            LastError   NVARCHAR(500) NULL,
            CreatedAt   DATETIME2     NOT NULL CONSTRAINT DF_temp_Send_Sql_Created DEFAULT (SYSDATETIME()),
            SentAt      DATETIME2     NULL,
            RaspunsCasa NVARCHAR(MAX) NULL,
            Scop        NVARCHAR(20)  NULL,
            Rezultat    NVARCHAR(MAX) NULL
        );
        CREATE INDEX IX_temp_Send_Sql_Preluat ON dbo.temp_Send_Sql (Preluat, Stare, NextAttempt) INCLUDE (Id);
    END";
    // QueryTimeout: o migrare blocata de un lock sa nu tina cererea pana la
    // max_execution_time (altfel browserul primeste un NetworkError).
    @sqlsrv_query($conn, $sqlTable, null, ['QueryTimeout' => 15]);

    // Completeaza o tabela legacy cu coloanele care lipsesc.
    $columns = [
        'DocID'       => "INT NULL",
        'Stare'       => "NVARCHAR(10) NOT NULL CONSTRAINT DF_temp_Send_Sql_Stare DEFAULT ('pending')",
        'Attempts'    => "INT NOT NULL CONSTRAINT DF_temp_Send_Sql_Attempts DEFAULT (0)",
        'NextAttempt' => "DATETIME2 NULL",
        'LastError'   => "NVARCHAR(500) NULL",
        'CreatedAt'   => "DATETIME2 NULL",
        'SentAt'      => "DATETIME2 NULL",
        'RaspunsCasa' => "NVARCHAR(MAX) NULL",
        'Scop'        => "NVARCHAR(20) NULL",
        'Rezultat'    => "NVARCHAR(MAX) NULL",
    ];
    foreach ($columns as $col => $def) {
        @sqlsrv_query(
            $conn,
            "IF COL_LENGTH('dbo.temp_Send_Sql','$col') IS NULL
                 ALTER TABLE dbo.temp_Send_Sql ADD $col $def",
            null,
            ['QueryTimeout' => 15]
        );
    }

    @sqlsrv_query(
        $conn,
        "IF NOT EXISTS (SELECT 1 FROM sys.indexes
                        WHERE name = 'IX_temp_Send_Sql_Preluat'
                          AND object_id = OBJECT_ID('dbo.temp_Send_Sql'))
             CREATE INDEX IX_temp_Send_Sql_Preluat
                 ON dbo.temp_Send_Sql (Preluat, Stare, NextAttempt) INCLUDE (Id)",
        null,
        ['QueryTimeout' => 15]
    );

    $done = true;
}

/**
 * Citeste o setare numerica din tblSet. Intoarce $default cand lipseste/goala.
 *
 * @param resource $conn
 * @param string $setting
 */
function getTblSetInt($conn, $setting, $default = 0) {
    $stmt = sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = ?", [$setting]);
    $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    if (!$row) { return $default; }
    $v = trim((string)($row['Value'] ?? ''));
    return ($v === '') ? $default : (int)$v;
}

/**
 * Formateaza un numar pentru sirul de vanzari (punct zecimal, fara zerouri
 * inutile), ca sa fie sigur indiferent de setarile locale ale serverului.
 *
 * @param mixed $v
 */
function sendFmtNum($v) {
    $s = number_format((float)$v, 4, '.', '');
    $s = rtrim(rtrim($s, '0'), '.');
    if ($s === '' || $s === '-') { return '0'; }
    return $s;
}

/**
 * Literal NVARCHAR escapat pentru sirul SQL (ex. N'...' cu '' pentru quotes).
 *
 * @param string $s
 */
function sendSqlStr($s) {
    return "N'" . str_replace("'", "''", (string)$s) . "'";
}

/**
 * Literal numeric pentru sirul SQL: sirul dat daca e numeric, altfel 0.
 *
 * @param mixed $v
 */
function sendSqlNum($v) {
    if (is_bool($v)) { return $v ? '1' : '0'; }
    $s = trim((string)$v);
    return is_numeric($s) ? $s : '0';
}

/**
 * Datele de conectare la serverul extern (tblConectare ID = 1), pregatite
 * pentru driver-ul Python (server cu instanta inclusa, baza, user, parola, TC).
 *
 * @param resource $conn
 */
function getConectareDb($conn) {
    $stmt = sqlsrv_query(
        $conn,
        "SELECT TOP 1 ServerIP, ServerName, DataBaseName, UserName, Password, TC
         FROM tblConectare WHERE ID = 1"
    );
    $r = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;

    $server = trim((string)($r['ServerIP'] ?? ''));
    $instance = trim((string)($r['ServerName'] ?? ''));
    $merged = ($instance !== '' && strcasecmp($instance, $server) !== 0)
        ? $server . '\\' . $instance
        : $server;

    return [
        "server" => $merged,
        "database" => trim((string)($r['DataBaseName'] ?? '')),
        "user" => trim((string)($r['UserName'] ?? '')),
        "password" => (string)($r['Password'] ?? ''),
        "tc" => ((int)($r['TC'] ?? 0) === 1)
    ];
}

/**
 * Construieste comanda SQL EXEC pentru o nota, in coada temp_Send_Sql.
 *
 * sVanzare1 = "ProdID:Cant:PV:PVC:Tvac;" pentru fiecare linie de produs
 *             (modurile de preparare, ProdID = 0, sunt ignorate).
 * sFP       = "FPID:Suma;" din trelDocIDFpID.
 * Procedura difera dupa TipVanz: Restaurant -> EmitBon_Ext_NOU,
 * FastFood -> EmitBon_Ext_2.
 *
 * $nrBonOverride / $nrZOverride = numerele reale din raspunsul casei de marcat
 * (cand AsteaptaRaspunsCasa = 1). $raspunsCasa = continutul brut al fisierului
 * .nrb, pastrat in temp_Send_Sql.RaspunsCasa pentru audit / afisare si trimis ca
 * ultim parametru al procedurii EmitBon_Ext_* (NULL la exportul clasic).
 *
 * Se apeleaza in tranzactia de inchidere a bonului. Intoarce Id-ul randului,
 * null cand exportul este dezactivat (tblSet.Server <> 1) sau false la eroare.
 *
 * @param resource $conn
 * @param int $docId
 * @param array|null $bon
 */
function enqueueBillSendSql($conn, $docId, $bon, $plati = [], $nrBonOverride = null, $nrZOverride = null, $raspunsCasa = null) {
    // Exportul catre server este activat din tblSet.Server (1 = DA).
    if (getTblSetInt($conn, 'Server', 0) !== 1) {
        return null;
    }

    $docId = (int)$docId;
    if ($docId <= 0) {
        return false;
    }

    ensureSendSqlTable($conn);

    // sVanzare1: liniile de produs, in ordinea notei.
    $stmtArt = sqlsrv_query(
        $conn,
        "SELECT ProdID, Cant, PV, PVC, TVAc
         FROM tblNoteD
         WHERE DocID = ? AND ProdID > 0
         ORDER BY OraComanda, ECRID",
        [$docId]
    );
    if ($stmtArt === false) { return false; }

    $sVanzare = '';
    while ($r = sqlsrv_fetch_array($stmtArt, SQLSRV_FETCH_ASSOC)) {
        $pv = (float)$r['PV'];
        $pvc = ($r['PVC'] !== null) ? (float)$r['PVC'] : $pv;
        $sVanzare .= (int)$r['ProdID'] . ':'
            . sendFmtNum($r['Cant']) . ':'
            . sendFmtNum($pv) . ':'
            . sendFmtNum($pvc) . ':'
            . (int)($r['TVAc'] ?? 0) . ';';
    }

    // sFP: formele de plata ale notei (scrise mai sus in aceeasi tranzactie).
    $stmtFp = sqlsrv_query(
        $conn,
        "SELECT FPID, Suma FROM trelDocIDFpID WHERE DocID = ? ORDER BY Id",
        [$docId]
    );
    if ($stmtFp === false) { return false; }
    $sFp = '';
    while ($r = sqlsrv_fetch_array($stmtFp, SQLSRV_FETCH_ASSOC)) {
        $sFp .= (int)$r['FPID'] . ':' . sendFmtNum($r['Suma']) . ';';
    }

    // Totalul notei (valoarea neta, dupa discount).
    $stmtTot = sqlsrv_query(
        $conn,
        "SELECT COALESCE(SUM(Cant * PV), 0) AS Total FROM tblNoteD WHERE DocID = ?",
        [$docId]
    );
    $totRow = $stmtTot ? sqlsrv_fetch_array($stmtTot, SQLSRV_FETCH_ASSOC) : null;
    $total = $totRow ? (float)$totRow['Total'] : 0.0;

    // Urmatorul numar Z (cel care va fi atribuit acestei sesiuni la inchiderea Z).
    // Cand casa de marcat confirma bonul (AsteaptaRaspunsCasa = 1), numarul Z si
    // numarul bonului se preiau din raspunsul casei (.nrb): astfel exportul
    // foloseste exact Z/NR reale ale registrului fiscal.
    $nrZ = 0;
    $stmtZ = sqlsrv_query($conn, "SELECT ISNULL(MAX(NrZ), 0) + 1 AS NrZ FROM tblNrZ");
    $zRow = $stmtZ ? sqlsrv_fetch_array($stmtZ, SQLSRV_FETCH_ASSOC) : null;
    if ($zRow) { $nrZ = (int)$zRow['NrZ']; }

    $statieId = getTblSetInt($conn, 'NrLogic', 0);
    $magId = getTblSetInt($conn, 'MagID', 0);
    $nrBon = (int)($bon['NrDoc'] ?? 0);

    // Valorile reale din raspunsul casei de marcat au prioritate (daca exista).
    if ($nrBonOverride !== null && (int)$nrBonOverride > 0) { $nrBon = (int)$nrBonOverride; }
    if ($nrZOverride !== null && (int)$nrZOverride > 0) { $nrZ = (int)$nrZOverride; }

    // Data/ora pe serverul local. Procedurile EmitBon_Ext_* declara @Data char(10)
    // si fac SET DATEFORMAT dmy, deci data trebuie data ca dd/mm/yyyy (stil 103),
    // altfel conversia varchar->datetime da out-of-range.
    $stmtNow = sqlsrv_query($conn, "SELECT CONVERT(varchar(10), GETDATE(), 103) AS D,
                                           CONVERT(varchar(8), GETDATE(), 108) AS T");
    $nowRow = $stmtNow ? sqlsrv_fetch_array($stmtNow, SQLSRV_FETCH_ASSOC) : null;
    $sData = $nowRow ? trim((string)$nowRow['D']) : date('Y-m-d');
    $sOra = $nowRow ? trim((string)$nowRow['T']) : date('H:i:s');

    // Procedura difera dupa TipVanz (1 = FastFood). Interogare locala, ca
    // send_common.php sa nu depinda de functiile din order_action.php.
    $tipVanz = getTblSetInt($conn, 'TipVanz', 0);
    $proc = ($tipVanz === 1) ? 'EmitBon_Ext_2' : 'EmitBon_Ext_NOU';

    // Continutul brut al fisierului de confirmare .nrb al casei de marcat (cand
    // AsteaptaRaspunsCasa = 1). null cand nu exista raspuns (export clasic).
    $raspunsCasaVal = ($raspunsCasa === null || trim((string)$raspunsCasa) === '')
        ? null : (string)$raspunsCasa;

    $strSql = "Execute " . $proc . " "
        . sendSqlStr($sData) . ','
        . sendSqlStr($sOra) . ','
        . sendSqlNum($statieId) . ','
        . sendSqlStr($sFp) . ','
        . sendSqlStr($sVanzare) . ','
        . sendSqlNum($nrBon) . ','
        . sendSqlNum($magId) . ','
        . sendSqlNum($nrZ) . ','
        . sendSqlNum($bon['NrOp'] ?? 0) . ','
        . sendSqlNum($total) . ','
        . sendSqlNum($bon['ClientID'] ?? 0) . ','
        . sendSqlNum($statieId) . ','
        // Ultimul parametru: raspunsul brut al casei de marcat (NULL la exportul clasic).
        . ($raspunsCasaVal === null ? 'NULL' : sendSqlStr($raspunsCasaVal)) . ';';

    $sql = "INSERT INTO temp_Send_Sql (DocID, str_sql, Preluat, Stare, Attempts, CreatedAt, RaspunsCasa)
            VALUES (?, ?, 0, 'pending', 0, SYSDATETIME(), ?); SELECT SCOPE_IDENTITY() AS Id;";
    $stmt = sqlsrv_query($conn, $sql, [$docId, $strSql, $raspunsCasaVal]);
    if ($stmt === false) { return false; }

    sqlsrv_next_result($stmt);
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return $row ? (int)$row['Id'] : false;
}

/**
 * Adauga in temp_Send_Sql o comanda SQL oarecare (de ex. apelul procedurii
 * GenFactPoS pentru generarea facturii pe server). `Scop` permite separarea
 * acestor comenzi de exportul clasic de bonuri. Intoarce Id-ul randului sau
 * false la eroare.
 *
 * @param resource $conn
 * @param int|null $docId
 * @param string $strSql
 * @param string $scop
 */
function enqueueRawSendSql($conn, $docId, $strSql, $scop = 'export') {
    ensureSendSqlTable($conn);
    $sql = "INSERT INTO temp_Send_Sql (DocID, str_sql, Preluat, Stare, Attempts, CreatedAt, Scop)
            VALUES (?, ?, 0, 'pending', 0, SYSDATETIME(), ?); SELECT SCOPE_IDENTITY() AS Id;";
    $stmt = sqlsrv_query($conn, $sql, [
        ($docId === null ? null : (int)$docId),
        (string)$strSql,
        (string)$scop
    ]);
    if ($stmt === false) { return false; }
    sqlsrv_next_result($stmt);
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return $row ? (int)$row['Id'] : false;
}

/**
 * Formateaza o data venita din sqlsrv (ReturnDatesAsStrings = true) ca string.
 *
 * @param mixed $value
 */
function sendQueueDateToString($value) {
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

/**
 * Trezeste imediat worker-ul de sincronizare (best-effort).
 */
function wakeSyncService() {
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 1, 'ignore_errors' => true]
    ]);
    @file_get_contents(SYNC_SERVICE_BASE . '/wake', false, $ctx);
}
