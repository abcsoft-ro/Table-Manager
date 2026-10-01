<?php
/**
 * API: controlul serviciilor locale Python (print-service, sync-service).
 *
 * GET  ?action=status -> starea fiecarui serviciu (online/offline)
 * POST {action:'start'|'stop'|'restart', service:'print'|'sync'|'all'}
 *
 * Pornirea se face prin lansatoarele .vbs ascunse (fara fereastra de consola),
 * oprirea prin ruta POST /shutdown a serviciului (oprire linistita).
 */
require_once __DIR__ . '/db.php';

$SERVICES_ROOT = dirname(__DIR__);

$SERVICES_DEF = [
    'print' => [
        'label' => 'print-service',
        'dir'   => 'print-service',
        'vbs'   => 'start-print-service-hidden.vbs',
        'port'  => 8756,
    ],
    'sync' => [
        'label' => 'sync-service',
        'dir'   => 'sync-service',
        'vbs'   => 'start-sync-service-hidden.vbs',
        'port'  => 8757,
    ],
];

/**
 * Host + port pentru un serviciu, citite din config.json (cu fallback).
 */
function servicesEndpoint($root, $def) {
    $host = '127.0.0.1';
    $port = (int)$def['port'];
    $cfgFile = $root . '/' . $def['dir'] . '/config.json';
    if (is_file($cfgFile)) {
        $cfg = json_decode((string)@file_get_contents($cfgFile), true);
        if (is_array($cfg)) {
            if (!empty($cfg['http_host'])) { $host = trim((string)$cfg['http_host']); }
            if (!empty($cfg['http_port'])) { $port = (int)$cfg['http_port']; }
        }
    }
    return [$host, $port];
}

function servicesHealthUrl($root, $def) {
    list($host, $port) = servicesEndpoint($root, $def);
    return "http://$host:$port/health";
}

/**
 * Cerere HTTP catre serviciile locale. Foloseste cURL daca exista, altfel
 * stream-urile PHP. Intoarce [body, cod, eroare].
 */
function servicesHttp($url, $method = 'GET', $timeout = 3) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => ['Content-Length: 0'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return [$body === false ? null : $body, $code, $err];
    }

    $ctx = stream_context_create(['http' => [
        'method'        => $method,
        'timeout'       => $timeout,
        'ignore_errors' => true,
        'header'        => "Content-Length: 0\r\n",
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (!empty($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $code = (int)$m[1];
            }
        }
    }
    if ($body === false) {
        $e = error_get_last();
        return [null, $code, $e ? $e['message'] : 'eroare'];
    }
    return [$body, $code, null];
}

function servicesHealthInfo($root, $def) {
    list($body, $code) = servicesHttp(servicesHealthUrl($root, $def), 'GET', 2);
    if ($body === null || $code < 200 || $code >= 300) {
        return null;
    }
    $json = json_decode($body, true);
    return (is_array($json) && (($json['status'] ?? '') === 'ok')) ? $json : null;
}

function servicesIsUp($healthUrl) {
    list($body, $code) = servicesHttp($healthUrl, 'GET', 2);
    if ($body === null || $code < 200 || $code >= 300) {
        return false;
    }
    $json = json_decode($body, true);
    return is_array($json) && (($json['status'] ?? '') === 'ok');
}

function servicesStatus($root, $defs) {
    $out = [];
    foreach ($defs as $key => $def) {
        list($host, $port) = servicesEndpoint($root, $def);
        $out[$key] = [
            'label' => $def['label'],
            'host'  => $host,
            'port'  => $port,
            'up'    => servicesIsUp("http://$host:$port/health"),
        ];
    }
    return $out;
}

/**
 * Porneste serviciul prin lansatorul .vbs ascuns. Intoarce null sau eroare.
 */
function servicesStart($root, $def) {
    if (stripos(PHP_OS, 'WIN') !== 0 || !function_exists('exec')) {
        return "Pornirea serviciilor este disponibila doar pe Windows.";
    }
    $vbs = $root . '/' . $def['dir'] . '/' . $def['vbs'];
    if (!is_file($vbs)) {
        return "Lipseste lansatorul " . $def['vbs'] . ".";
    }
    // //B = batch mode (fara dialoguri de eroare); .vbs-ul lanseaza .bat-ul ascuns.
    @exec('wscript.exe //B ' . escapeshellarg($vbs));
    return null;
}

/**
 * Opreste serviciul: intai linistit prin /shutdown, apoi, daca tot raspunde,
 * forteaza procesul (dupa PID-ul raportat de /health). Intoarce eroare sau null.
 */
function servicesStopService($root, $def) {
    $info = servicesHealthInfo($root, $def);
    list($host, $port) = servicesEndpoint($root, $def);
    servicesHttp("http://$host:$port/shutdown", 'POST', 3);

    if (servicesWait($root, $def, false, 12)) {
        return null;
    }
    // Fallback: forcam procesul (PHP ruleaza ca acelasi utilizator ca serviciul).
    if (is_array($info) && !empty($info['pid']) && stripos(PHP_OS, 'WIN') === 0 && function_exists('exec')) {
        @exec('taskkill /F /PID ' . (int)$info['pid'] . ' 2>&1');
        servicesWait($root, $def, false, 5);
    }
    return servicesIsUp(servicesHealthUrl($root, $def)) ? "nu s-a oprit" : null;
}

/**
 * Asteapta pana cand starea serviciului devine cea dorita.
 */
function servicesWait($root, $def, $wantUp, $seconds = 12) {
    list($host, $port) = servicesEndpoint($root, $def);
    $url = "http://$host:$port/health";
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        if (servicesIsUp($url) === $wantUp) {
            return true;
        }
        usleep(400000);
    }
    return false;
}

// ------------------------------------------------------------------- GET
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = strtolower(trim((string)($_GET['action'] ?? 'status')));
    if ($action !== 'status') {
        sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
    }
    sendJsonResponse([
        "status"   => "success",
        "services" => servicesStatus($SERVICES_ROOT, $SERVICES_DEF),
    ]);
}

// ------------------------------------------------------------------- POST
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

$action  = strtolower(trim((string)($input['action'] ?? '')));
$service = strtolower(trim((string)($input['service'] ?? 'all')));

if (!in_array($action, ['start', 'stop', 'restart'], true)) {
    sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}
if ($service !== 'all' && !isset($SERVICES_DEF[$service])) {
    sendJsonResponse(["status" => "error", "message" => "Serviciu necunoscut"], 400);
}

$targets = ($service === 'all') ? array_keys($SERVICES_DEF) : [$service];
$errors = [];

foreach ($targets as $key) {
    $def = $SERVICES_DEF[$key];
    $up = servicesIsUp(servicesHealthUrl($SERVICES_ROOT, $def));

    if ($action === 'restart' || ($action === 'stop' && $up)) {
        if ($up) {
            $err = servicesStopService($SERVICES_ROOT, $def);
            if ($err !== null) {
                $errors[] = $def['label'] . ": " . $err;
            }
        }
    }

    if ($action === 'start' || $action === 'restart') {
        // Idempotent: nu pornim o a doua instanta daca serviciul e deja sus
        // (pe Windows SO_REUSEADDR ar permite doua procese pe acelasi port).
        if (servicesIsUp(servicesHealthUrl($SERVICES_ROOT, $def))) {
            continue;
        }
        $err = servicesStart($SERVICES_ROOT, $def);
        if ($err !== null) {
            $errors[] = $def['label'] . ": " . $err;
            continue;
        }
        if (!servicesWait($SERVICES_ROOT, $def, true, 15)) {
            $errors[] = $def['label'] . ": nu a pornit in timp util";
        }
    }
}

$verb = ['start' => 'pornite', 'stop' => 'oprite', 'restart' => 'repornite'][$action];
$message = "Servicii " . $verb . ".";
if (!empty($errors)) {
    $message .= " Atentie: " . implode("; ", $errors) . ".";
}

sendJsonResponse([
    "status"   => empty($errors) ? "success" : "error",
    "message"  => $message,
    "errors"   => $errors,
    "services" => servicesStatus($SERVICES_ROOT, $SERVICES_DEF),
]);
