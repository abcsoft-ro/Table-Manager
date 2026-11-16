<?php
/**
 * TableManager 2.1.3 - harness de test (ruleaza cu php.exe CLI).
 *
 * NU se serveste prin web (tests/.htaccess). Folosit de scripturile run_*.php.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

define('ROOT_DIR', dirname(__DIR__));
define('BASE_URL', 'http://127.0.0.1/rual');
if (is_file(ROOT_DIR . '/api/db.php')) {
    require_once ROOT_DIR . '/api/db.php';
}

$GLOBALS['__results'] = [];

function test($name, $cond, $detail = '') {
    $ok = (bool)$cond;
    $GLOBALS['__results'][] = ['name' => $name, 'ok' => $ok, 'detail' => (string)$detail];
    printf("[%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $name, (!$ok && $detail !== '') ? ('  -> ' . $detail) : '');
    return $ok;
}
function info($m) { echo "  . $m\n"; }
function section($t) { echo "\n=== $t ===\n"; }
function summary($title) {
    $pass = 0; $fail = 0;
    foreach ($GLOBALS['__results'] as $x) { $x['ok'] ? $pass++ : $fail++; }
    printf("\n--- %s: %d pass, %d fail (total %d) ---\n", $title, $pass, $fail, count($GLOBALS['__results']));
    if ($fail) {
        echo "FAILED:\n";
        foreach ($GLOBALS['__results'] as $x) { if (!$x['ok']) echo "  - {$x['name']}  {$x['detail']}\n"; }
    }
    // cod de iesire 1 daca exista esecuri, ca run-all sa poata agrega
    $GLOBALS['__exit'] = $fail > 0 ? 1 : 0;
    return $fail;
}

function db() { return getDBConnection(); }
function q($sql, $p = []) {
    $s = sqlsrv_query(db(), $sql, $p);
    if ($s === false) { $e = sqlsrv_errors(); throw new RuntimeException($e[0]['message']); }
    return $s;
}
function rows($sql, $p = []) {
    $s = q($sql, $p); $o = [];
    while ($r = sqlsrv_fetch_array($s, SQLSRV_FETCH_ASSOC)) { $o[] = $r; }
    return $o;
}
function q1($sql, $p = []) { $r = rows($sql, $p); return $r ? $r[0] : null; }
function cell($sql, $p = []) { $r = q1($sql, $p); return $r ? array_values($r)[0] : null; }

function http($method, $path, $body = null, $headers = [], $timeout = 30) {
    $ch = curl_init(BASE_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) {
        if (is_array($body)) { $body = json_encode($body, JSON_UNESCAPED_UNICODE); $headers[] = 'Content-Type: application/json'; }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$resp, 'json' => json_decode((string)$resp, true), 'err' => $err];
}
function jget($p, $h = []) { return http('GET', $p, null, $h); }
function jpost($p, $d, $h = []) { return http('POST', $p, $d, $h); }

/** id-ul masinii de test (o masa libera, aleasa la runtime) */
function testConfig() {
    static $c = null;
    if ($c === null) {
        $set = [];
        foreach (rows("SELECT Setting, Value FROM tblSet") as $r) { $set[$r['Setting']] = $r['Value']; }
        $c = $set;
    }
    return $c;
}
function cfg($k, $d = null) { $s = testConfig(); return array_key_exists($k, $s) ? $s[$k] : $d; }

/* ----------------------------------------------------------------------------
 * Config de tiparire (print-service/config.json): copie de siguranta inainte de
 * testele D/F (care muta tintele pe `preview`).
 *
 * Fisierele de test se restaureaza singure la final, dar daca un run este
 * intrerupt (Ctrl+C / timeout / proces omorat) `finally` nu apuca sa ruleze si
 * statia ramane cu imprimantele pe `preview`. De aceea copia se scrie pe disc
 * INAINTE de modificare si este restaurata automat la urmatorul run.
 * ------------------------------------------------------------------------- */
function printConfigFilePath() { return ROOT_DIR . '/print-service/config.json'; }
function printConfigBackupPath() { return ROOT_DIR . '/logs/print_config_before_tests.json'; }

/** Salveaza configul real o singura data (nu suprascrie o copie existenta). */
function printConfigBackup() {
    $f = printConfigFilePath();
    $b = printConfigBackupPath();
    if (is_file($b) || !is_file($f)) { return false; }
    @mkdir(dirname($b), 0777, true);
    $ok = @copy($f, $b);
    if ($ok) { info('backup config tiparire: ' . basename($b)); }
    return $ok;
}

/** Restaureaza configul salvat de un run anterior intrerupt (daca exista). */
function printConfigRestoreBackup() {
    $f = printConfigFilePath();
    $b = printConfigBackupPath();
    if (!is_file($b) || !is_file($f)) { return false; }
    $tmp = $f . '.restore';
    $ok = @copy($b, $tmp) && @rename($tmp, $f);
    if (!$ok) { @unlink($tmp); return false; }
    @unlink($b);
    info('config tiparire restaurat din backup (run anterior intrerupt)');
    return true;
}

/** Copia nu mai este necesara dupa o restaurare reusita. */
function printConfigDropBackup() { @unlink(printConfigBackupPath()); }

/**
 * Scrie configul de tiparire. Merge prin serviciu (ca sa valideze si sa faca
 * hot-reload); daca serviciul e oprit, scrie direct fisierul, altfel o
 * restaurare dupa un test ar fi pierduta exact cand serviciul nu raspunde.
 */
function printConfigSave($c) {
    if (!is_array($c)) { return false; }
    $res = jpost('/api/print_config.php', ['action' => 'save', 'config' => $c]);
    $ok = ($res['code'] === 200 && (($res['json']['status'] ?? '') === 'success'));
    if ($ok) { return true; }
    // Doar cand serviciul nu raspunde incercam scrierea directa; o eroare de
    // validare de la serviciu nu trebuie ocolita.
    if ($res['code'] !== 0 && $res['code'] < 500) { return false; }
    $f = printConfigFilePath();
    $tmp = $f . '.tmp';
    $json = json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($tmp, $json) === false) { return false; }
    if (!@rename($tmp, $f)) { @unlink($tmp); return false; }
    info('config tiparire scris direct in fisier (serviciul nu raspunde)');
    return true;
}

/* ----------------------------------------------------------------------------
 * Simulator POS bancar (fazele C2/C3): porneste un script Python local si
 * asteapta portul. `posBancaMockStop` omoara si copilul (python.exe), nu doar
 * cmd.exe prin care trece proc_open pe Windows.
 * ------------------------------------------------------------------------- */
function posBancaMockStart($py, $script, $port, array $args = [], $log = null) {
    $log = $log ?: (sys_get_temp_dir() . '\\pos_banca_mock_' . (int)$port . '.log');
    @unlink($log);
    $parts = ['"' . $py . '"', '"' . $script . '"', '--port', (string)$port];
    foreach ($args as $arg) { $parts[] = $arg; }
    $cmd = implode(' ', $parts);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $log, 'a'],
        2 => ['file', $log, 'a'],
    ];
    $proc = @proc_open($cmd, $descriptors, $pipes, dirname($script), null);
    if (is_resource($proc) && isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    $ok = false;
    $deadline = microtime(true) + 12;
    while (microtime(true) < $deadline) {
        $fp = @fsockopen('127.0.0.1', (int)$port, $errno, $errstr, 0.3);
        if ($fp) { fclose($fp); $ok = true; break; }
        usleep(150000);
    }
    return [$proc, $ok, $log];
}

function posBancaMockStop($proc) {
    if (!is_resource($proc)) { return; }
    $status = @proc_get_status($proc);
    $pid = (int)($status['pid'] ?? 0);
    if ($pid > 0) {
        @exec('taskkill /F /T /PID ' . $pid . ' >NUL 2>&1');
    }
    @proc_terminate($proc);
    @proc_close($proc);
}
