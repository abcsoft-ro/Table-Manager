<?php
/**
 * TableManager 2.0.0 - harness de test (ruleaza cu php.exe CLI).
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
