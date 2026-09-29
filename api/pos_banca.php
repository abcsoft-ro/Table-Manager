<?php
/**
 * API: comunicarea cu POS-ul bancar (terminalul de card) la inchiderea notei.
 *
 * Reproduce fluxul din vechea aplicatie VBA (SendtoPoS): cand nota are o plata
 * pe card (FPID = 1) si tblSet.PoSbanca = 1, se lanseaza driverul configurat in
 * tblSet.CaleDriverPoSbanca, asteptand fisierul de rezultat (tblSet.
 * CaleFisierRaspunsPoSbanca, implicit rezultat_ecr.txt langa driver). Fisierul
 * de rezultat este de forma:
 *
 *     STATUS=OK|ERROR
 *     MESAJ=text
 *     TRX_ID=...
 *     EMITE_BON=DA|NU
 *
 * Apelurile catre terminal se persista in tblPosBancaLog (audit), iar decizia
 * finala a utilizatorului (aprobat / ignorat / anulat) este scrisa de acelasi
 * endpoint prin actiunea "resolve".
 *
 * Actiuni:
 *   POST {action:'pay', amount, docId}          -> ruleaza driverul
 *   POST {action:'resolve', docId, actiune}     -> marcheaza decizia finala
 */

require_once __DIR__ . '/db.php';

/**
 * Citeste o setare din tblSet (cheie/valoare). Implicit $default cand lipseste.
 */
function posBancaSetting($conn, $setting, $default = '') {
    $stmt = sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = ?", [$setting]);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row) { return trim((string)$row['Value']); }
    }
    return $default;
}

/**
 * Creeaza tabela de audit daca nu exista.
 */
function ensurePosBancaLogTable($conn) {
    $sql = "IF OBJECT_ID('dbo.tblPosBancaLog','U') IS NULL
            CREATE TABLE dbo.tblPosBancaLog (
                Id INT IDENTITY(1,1) PRIMARY KEY,
                DocID INT NULL,
                NrDoc INT NULL,
                Suma DECIMAL(18,2) NULL,
                TrxId NVARCHAR(50) NULL,
                Status NVARCHAR(50) NULL,
                Mesaj NVARCHAR(255) NULL,
                EmiteBon BIT NULL,
                Actiune NVARCHAR(20) NULL,
                CreatedAt DATETIME NOT NULL DEFAULT (GETDATE()),
                ResolvedAt DATETIME NULL
            )";
    @sqlsrv_query($conn, $sql);
}

/**
 * Rezolva calea executabilului: daca $path este chiar .bat/.cmd/.exe se foloseste
 * ca atare, altfel se considera folder si se adauga numele implicit.
 */
function posBancaResolveBatPath($path) {
    $path = trim((string)$path);
    if ($path === '') { return ''; }
    if (preg_match('/\.(bat|cmd|exe)$/i', $path)) { return $path; }
    return rtrim($path, "\\/") . '\\vanzare.bat';
}

/**
 * Calea fisierului de rezultat. Daca setarea e completata se foloseste (folder
 * -> se adauga numele implicit), altfel folderul driverului + rezultat_ecr.txt.
 */
function posBancaResultPathFor($batPath, $override) {
    $override = trim((string)$override);
    if ($override !== '') {
        if (is_dir($override) || preg_match('/[\\\\\/]$/', $override)) {
            return rtrim($override, "\\/") . '\\rezultat_ecr.txt';
        }
        return $override;
    }
    $dir = dirname($batPath);
    return rtrim($dir, "\\/") . '\\rezultat_ecr.txt';
}

function posBancaFormatAmount($v) {
    return number_format((float)$v, 2, '.', '');
}

function posBancaNewTrxId() {
    return 'TX' . date('YmdHis');
}

function posBancaResultReady($path) {
    return is_file($path) && filesize($path) > 0;
}

/**
 * Gaseste folderul unei instalari Python (python.exe). Apache ruleaza ca
 * LocalSystem si nu mosteneste PATH-ul utilizatorului, deci driverul .bat nu
 * gaseste "python" (desi merge lansat manual). Cautam instalari uzuale si le
 * adaugam la PATH-ul procesului copil.
 */
function posBancaFindPythonDir() {
    if (stripos(PHP_OS, 'WIN') !== 0) { return ''; }
    $patterns = [
        'C:\\Users\\*\\AppData\\Local\\Programs\\Python\\Python3*',
        'C:\\Python3*',
        'C:\\Program Files\\Python3*',
        'C:\\Program Files (x86)\\Python3*',
    ];
    $dirs = [];
    foreach ($patterns as $pat) {
        foreach ((array)glob($pat, GLOB_ONLYDIR) as $d) { $dirs[] = $d; }
    }
    // Preferam ultima versiune (sortate descrescator dupa nume).
    rsort($dirs);
    foreach ($dirs as $d) {
        if (is_file($d . '\\python.exe')) { return $d; }
    }
    return '';
}

/**
 * Construieste environment-ul procesului copil cu folderul Python adaugat la
 * PATH. Intoarce null (mostenire) cand nu putem citi environment-ul curent.
 */
function posBancaChildEnv($pythonDir) {
    $env = getenv();
    if (!is_array($env)) { return null; }
    if ($pythonDir !== '') {
        $key = isset($env['Path']) ? 'Path' : (isset($env['PATH']) ? 'PATH' : 'Path');
        $cur = isset($env[$key]) ? (string)$env[$key] : '';
        if (stripos($cur, $pythonDir) === false) {
            $env[$key] = $pythonDir . ';' . $cur;
        }
    }
    return $env;
}

/**
 * Parseaza fisierul de rezultat (linii CHEIE=VALOARE, case-insensitive).
 */
function posBancaParseResult($content) {
    $out = [];
    $content = str_replace(["\r\n", "\r"], "\n", (string)$content);
    foreach (explode("\n", $content) as $line) {
        if (strpos($line, '=') === false) { continue; }
        list($key, $val) = explode('=', $line, 2);
        $key = strtoupper(trim($key));
        if ($key === '') { continue; }
        $out[$key] = trim($val);
    }
    return $out;
}

/**
 * Persista o incercare de tranzactie. Returneaza Id-ul randului inserat.
 */
function posBancaLogAttempt($conn, $docId, $nrDoc, $suma, $trxId, $status, $mesaj, $emiteBon, $actiune) {
    $sql = "INSERT INTO tblPosBancaLog (DocID, NrDoc, Suma, TrxId, Status, Mesaj, EmiteBon, Actiune, CreatedAt)
            OUTPUT INSERTED.Id
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, GETDATE())";
    $stmt = sqlsrv_query($conn, $sql, [
        $docId !== null ? (int)$docId : null,
        $nrDoc !== null ? (int)$nrDoc : null,
        (float)$suma,
        $trxId,
        $status,
        mb_substr((string)$mesaj, 0, 255),
        $emiteBon ? 1 : 0,
        $actiune
    ]);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row) { return (int)$row['Id']; }
    }
    return null;
}

/** Numarul notei (tblBonCurent.NrDoc) pentru un DocID, sau null. */
function posBancaNrDoc($conn, $docId) {
    if ((int)$docId <= 0) { return null; }
    $stmt = sqlsrv_query($conn, "SELECT TOP 1 NrDoc FROM tblBonCurent WHERE DocID = ?", [(int)$docId]);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row) { return (int)$row['NrDoc']; }
    }
    return null;
}

$conn = getDBConnection();

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input || !is_array($input)) { $input = $_POST; }
$action = $input['action'] ?? '';

if ($action === 'resolve') {
    // Decizia finala a utilizatorului pentru incercarile inca nerezolvate ale notei.
    $docId = (int)($input['docId'] ?? 0);
    $actiune = trim((string)($input['actiune'] ?? ''));
    $allowed = ['aprobat', 'ignorat', 'anulat'];
    if ($docId <= 0 || !in_array($actiune, $allowed, true)) {
        sendJsonResponse(["status" => "error", "message" => "Parametri invalizi"], 400);
    }
    ensurePosBancaLogTable($conn);
    $stmt = sqlsrv_query(
        $conn,
        "UPDATE tblPosBancaLog SET Actiune = ?, ResolvedAt = GETDATE()
         WHERE DocID = ? AND Actiune IS NULL",
        [$actiune, $docId]
    );
    if ($stmt === false) {
        sendJsonResponse(["status" => "error", "message" => "Eroare salvare decizie"], 500);
    }
    sendJsonResponse(["status" => "success", "actiune" => $actiune]);
}

if ($action !== 'pay') {
    sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}

$amount = (float)str_replace(',', '.', (string)($input['amount'] ?? 0));
$docId = (int)($input['docId'] ?? 0);

$posBanca = posBancaSetting($conn, 'PoSbanca', '0');

// Fara POS bancar configurat sau fara suma pe card: se emite bonul fiscal direct.
if ($posBanca !== '1') {
    sendJsonResponse([
        "status" => "success",
        "enabled" => false,
        "emiteBon" => true,
        "posStatus" => "",
        "posMessage" => "",
        "trxId" => "",
        "logId" => null
    ]);
}
if ($amount <= 0) {
    sendJsonResponse([
        "status" => "success",
        "enabled" => false,
        "emiteBon" => true,
        "posStatus" => "",
        "posMessage" => "",
        "trxId" => "",
        "logId" => null
    ]);
}

ensurePosBancaLogTable($conn);
$nrDoc = posBancaNrDoc($conn, $docId);

// Incercarile anterioare inca nerezolvate sunt reluate (utilizatorul a ales Retry).
@sqlsrv_query(
    $conn,
    "UPDATE tblPosBancaLog SET Actiune = 'reincercat', ResolvedAt = GETDATE()
     WHERE DocID = ? AND Actiune IS NULL",
    [$docId]
);

$driverRaw = posBancaSetting($conn, 'CaleDriverPoSbanca', '');
$batPath = posBancaResolveBatPath($driverRaw);

// Eroare de configurare: ca in VBA, se emite bonul fiscal, dar se raporteaza eroarea.
if ($batPath === '' || !is_file($batPath)) {
    $msg = "Nu gasesc executabilul PoS: " . $driverRaw;
    $logId = posBancaLogAttempt($conn, $docId, $nrDoc, $amount, '', 'ERROR', $msg, true, 'config');
    sendJsonResponse([
        "status" => "success",
        "enabled" => true,
        "emiteBon" => true,
        "configError" => true,
        "posStatus" => "ERROR",
        "posMessage" => $msg,
        "trxId" => "",
        "logId" => $logId
    ]);
}

$resultPath = posBancaResultPathFor($batPath, posBancaSetting($conn, 'CaleFisierRaspunsPoSbanca', ''));
$txId = posBancaNewTrxId();
$amountStr = posBancaFormatAmount($amount);

// Sterge rezultatul anterior ca sa nu citim unul vechi.
@unlink($resultPath);

set_time_limit(0);
ignore_user_abort(true);

$devNull = (stripos(PHP_OS, 'WIN') === 0) ? 'NUL' : '/dev/null';
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', $devNull, 'a'],
    2 => ['file', $devNull, 'a']
];
$cmd = '"' . $batPath . '" ' . $amountStr . ' ' . $txId;
$pythonDir = posBancaFindPythonDir();
$childEnv = ($pythonDir !== '') ? posBancaChildEnv($pythonDir) : null;
$childCwd = dirname($batPath);
$proc = @proc_open($cmd, $descriptors, $pipes, $childCwd, $childEnv);
if (!is_resource($proc)) {
    $msg = "Nu pot lansa driverul PoS: " . $batPath;
    $logId = posBancaLogAttempt($conn, $docId, $nrDoc, $amount, $txId, 'ERROR', $msg, true, 'config');
    sendJsonResponse([
        "status" => "success",
        "enabled" => true,
        "emiteBon" => true,
        "configError" => true,
        "posStatus" => "ERROR",
        "posMessage" => $msg,
        "trxId" => $txId,
        "logId" => $logId
    ]);
}
if (isset($pipes[0]) && is_resource($pipes[0])) { fclose($pipes[0]); }

// Asteapta fisierul de rezultat (bat-ul poate lansa procesul asincron).
$timeoutMs = 190000;
$waited = 0;
while (!posBancaResultReady($resultPath)) {
    usleep(250000);
    $waited += 250;
    if ($waited >= $timeoutMs) { break; }
}

if (is_resource($proc)) { @proc_close($proc); }

if (!posBancaResultReady($resultPath)) {
    $msg = "PoS nu a returnat rezultat in timp util.";
    $logId = posBancaLogAttempt($conn, $docId, $nrDoc, $amount, $txId, 'TIMEOUT', $msg, false, null);
    sendJsonResponse([
        "status" => "success",
        "enabled" => true,
        "emiteBon" => false,
        "posStatus" => "ERROR",
        "posMessage" => $msg,
        "trxId" => $txId,
        "logId" => $logId
    ]);
}

usleep(200000);
$content = @file_get_contents($resultPath);
$fields = posBancaParseResult($content);

$posStatus = $fields['STATUS'] ?? '';
$posMessage = $fields['MESAJ'] ?? '';
$resultTrxId = $fields['TRX_ID'] ?? '';
if ($resultTrxId !== '') { $txId = $resultTrxId; }
$emiteBon = (strtoupper($fields['EMITE_BON'] ?? '') === 'DA');

$logId = posBancaLogAttempt(
    $conn,
    $docId,
    $nrDoc,
    $amount,
    $txId,
    $posStatus !== '' ? $posStatus : ($emiteBon ? 'OK' : 'ERROR'),
    $posMessage,
    $emiteBon,
    $emiteBon ? 'aprobat' : null
);

sendJsonResponse([
    "status" => "success",
    "enabled" => true,
    "emiteBon" => $emiteBon,
    "posStatus" => $posStatus,
    "posMessage" => $posMessage,
    "trxId" => $txId,
    "logId" => $logId
]);
