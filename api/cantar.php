<?php
/**
 * API: citirea cantarului electronic.
 *
 * Cand tblSet.Cantar = 1, butonul QTY din POS apeleaza aceasta actiune: lanseaza
 * driverul configurat in tblSet.CaleDriverCantar (ex. un .bat), sterge fisierul
 * de raspuns anterior (ca sa nu se citeasca o cantarire veche), asteapta
 * tblSet.Delay_cantar milisecunde si citeste valoarea din
 * tblSet.CaleFisierRaspunsCantar.
 *
 * Actiuni:
 *   POST {action:'read'} -> {status, enabled, value|null, noResponse, message}
 */

require_once __DIR__ . '/db.php';

/**
 * Citeste o setare din tblSet (cheie/valoare). Implicit $default cand lipseste.
 *
 * @param resource $conn
 * @param string $setting
 * @param string $default
 */
function cantarSetting($conn, $setting, $default = '') {
    $stmt = @sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = ?", [$setting]);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row) { return trim((string)$row['Value']); }
    }
    return $default;
}

/**
 * Rezolva calea driverului: un .bat/.cmd/.exe este folosit ca atare; orice altceva
 * este tratat ca folder (fara nume implicit cunoscut -> '').
 *
 * @param string $path
 * @return string
 */
function cantarResolveDriverPath($path) {
    $path = trim((string)$path);
    if ($path === '') { return ''; }
    if (preg_match('/\.(bat|cmd|exe)$/i', $path)) { return $path; }
    return '';
}

/**
 * Calea fisierului de raspuns. Daca setarea e completata se foloseste (folder ->
 * se adauga numele implicit raspuns_cantar.txt), altfel langa driver.
 *
 * @param string $override
 * @param string $driverPath
 * @return string
 */
function cantarResponsePath($override, $driverPath) {
    $override = trim((string)$override);
    if ($override !== '') {
        if (is_dir($override) || preg_match('/[\\\\\/]$/', $override)) {
            return rtrim($override, "\\/") . '\\raspuns_cantar.txt';
        }
        return $override;
    }
    $dir = dirname($driverPath);
    return rtrim($dir, "\\/") . '\\raspuns_cantar.txt';
}

/**
 * Extrage primul numar din continutul fisierului de raspuns (accepta virgula sau
 * punct zecimal). Intoarce float sau null daca nu exista niciun numar.
 *
 * @param string $content
 * @return float|null
 */
function cantarParseValue($content) {
    $content = str_replace(["\r", "\n", ','], [' ', ' ', '.'], (string)$content);
    if (preg_match('/-?\d+(?:\.\d+)?/', $content, $m)) {
        return (float)$m[0];
    }
    return null;
}

/**
 * Gaseste folderul unei instalari Python (python.exe). Apache ruleaza ca
 * LocalSystem si nu mosteneste PATH-ul utilizatorului, deci un driver .bat nu
 * gaseste "python". Preferam .venv al proiectului (vezi requirements.txt).
 *
 * @return string
 */
function cantarFindPythonDir() {
    if (stripos(PHP_OS, 'WIN') !== 0) { return ''; }
    $venvPy = __DIR__ . '/../.venv/Scripts/python.exe';
    if (is_file($venvPy)) {
        $dir = realpath(dirname($venvPy));
        if ($dir !== false) {
            $dir = str_replace('/', '\\', $dir);
            cantarEnsurePyShim($dir);
            return $dir;
        }
    }
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
    rsort($dirs);
    foreach ($dirs as $d) {
        if (is_file($d . '\\python.exe')) { return $d; }
    }
    return '';
}

/**
 * Launcher `py.bat` in folderul Python dat (unele .bat-uri prefera launcher-ul
 * global `py`, care poate sa nu aiba Python inregistrat).
 *
 * @param string $dir
 */
function cantarEnsurePyShim($dir) {
    $shim = rtrim($dir, '\\/') . '\\py.bat';
    if (is_file($shim)) { return; }
    @file_put_contents($shim, "@echo off\r\n\"%~dp0python.exe\" %*\r\n");
}

/**
 * Environment-ul procesului copil, cu folderul Python adaugat la PATH.
 *
 * @param string $pythonDir
 * @return array|null
 */
function cantarChildEnv($pythonDir) {
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
 * Raspuns unitar pentru cantar.
 *
 * @param resource $conn
 * @param bool $enabled
 * @param float|null $value
 * @param bool $noResponse
 * @param string $message
 * @return void
 */
function cantarRespond($enabled, $value, $noResponse, $message) {
    sendJsonResponse([
        "status" => "success",
        "enabled" => (bool)$enabled,
        "value" => ($value === null ? null : (float)$value),
        "noResponse" => (bool)$noResponse,
        "message" => (string)$message
    ]);
}

$conn = getDBConnection();

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input || !is_array($input)) { $input = $_POST; }
$action = $input['action'] ?? '';

if ($action !== 'read') {
    sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}

if (cantarSetting($conn, 'Cantar', '0') !== '1') {
    cantarRespond(false, null, false, '');
}

$driverRaw = cantarSetting($conn, 'CaleDriverCantar', '');
$driverPath = cantarResolveDriverPath($driverRaw);
if ($driverPath === '' || !is_file($driverPath)) {
    cantarRespond(true, null, true,
        "Cantarul electronic nu raspunde: driverul nu este configurat corect (CaleDriverCantar).");
}

$respPath = cantarResponsePath(cantarSetting($conn, 'CaleFisierRaspunsCantar', ''), $driverPath);
$delayMs = (int)cantarSetting($conn, 'Delay_cantar', '650');
if ($delayMs < 0) { $delayMs = 0; }
if ($delayMs > 15000) { $delayMs = 15000; }

// Stergem raspunsul anterior ca sa nu citim o cantarire veche.
@unlink($respPath);

set_time_limit(0);
ignore_user_abort(true);

$driverLog = __DIR__ . '/../logs/cantar_driver.log';
@mkdir(dirname($driverLog), 0777, true);

$cmd = '"' . $driverPath . '"';
$pythonDir = cantarFindPythonDir();
$childEnv = ($pythonDir !== '') ? cantarChildEnv($pythonDir) : null;
$childCwd = dirname($driverPath);
@file_put_contents(
    $driverLog,
    '[' . date('Y-m-d H:i:s') . '] cmd=' . $cmd
        . ' | pythonDir=' . ($pythonDir !== '' ? $pythonDir : '(none)')
        . ' | cwd=' . $childCwd . ' | raspuns=' . $respPath . "\n"
);

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', $driverLog, 'a'],
    2 => ['file', $driverLog, 'a']
];
$proc = @proc_open($cmd, $descriptors, $pipes, $childCwd, $childEnv);
if (!is_resource($proc)) {
    cantarRespond(true, null, true,
        "Cantarul electronic nu raspunde: nu pot lansa driverul (" . $driverPath . ").");
}
if (isset($pipes[0]) && is_resource($pipes[0])) { fclose($pipes[0]); }

// Asteptam pana la Delay_cantar, citind imediat ce raspunsul apare.
$waited = 0;
while ($waited < $delayMs) {
    usleep(100000);
    $waited += 100;
    if (is_file($respPath) && @filesize($respPath) > 0) { break; }
}
// Mic ragaz suplimentar daca fisierul tocmai se scrie.
if (!(is_file($respPath) && @filesize($respPath) > 0)) {
    for ($i = 0; $i < 5; $i++) {
        usleep(100000);
        if (is_file($respPath) && @filesize($respPath) > 0) { break; }
    }
}

$status = @proc_get_status($proc);
if (is_array($status) && !empty($status['running'])) { @proc_terminate($proc); }
@proc_close($proc);

$content = is_file($respPath) ? @file_get_contents($respPath) : '';
$value = cantarParseValue($content);

if ($value === null || $value <= 0) {
    cantarRespond(true, null, true, 'Cantarul electronic nu raspunde.');
}

cantarRespond(true, $value, false, '');
