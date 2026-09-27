<?php
/**
 * API: Configurare imprimante (config.json al print-service).
 *
 * Ecranul Setari > Imprimante vorbeste cu acest endpoint, care este un proxy
 * catre serviciul local de tiparire (server.py). Serviciul Python este cel care
 * detine config.json: il valideaza, il scrie atomic si il reincarca la cald.
 *
 * GET  ?action=config           -> configul curent + stare serviciu
 * GET  ?action=printers         -> lista imprimantelor Windows (daca pywin32)
 * POST {action:'save', config}  -> scrie config.json (via serviciu)
 * POST {action:'test', which|target} -> imprimare de proba
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/print_common.php';

/** GET catre serviciul local de tiparire. Intoarce array sau null daca e jos. */
function printServiceGet($path, $timeout = 4) {
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true]
    ]);
    $body = @file_get_contents(PRINT_SERVICE_BASE . $path, false, $ctx);
    if ($body === false) { return null; }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/** POST JSON catre serviciul local de tiparire. Intoarce array sau null. */
function printServicePost($path, $payload, $timeout = 10) {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout' => $timeout,
            'ignore_errors' => true
        ]
    ]);
    $body = @file_get_contents(PRINT_SERVICE_BASE . $path, false, $ctx);
    if ($body === false) { return null; }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'config';

    if ($action === 'printers') {
        $res = printServiceGet('/printers');
        if ($res === null) {
            sendJsonResponse(["status" => "error", "message" => "Serviciul de tiparire nu raspunde.", "available" => false, "printers" => []], 200);
        }
        sendJsonResponse(["status" => "success", "available" => !empty($res['available']), "printers" => $res['printers'] ?? []]);
    }

    $res = printServiceGet('/config');
    if ($res === null) {
        sendJsonResponse(["status" => "error", "message" => "Serviciul de tiparire nu raspunde.", "service_up" => false, "config" => null], 200);
    }
    sendJsonResponse(["status" => "success", "service_up" => true, "config" => $res['config'] ?? null]);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input || !is_array($input)) { $input = $_POST; }
$action = $input['action'] ?? '';

if ($action === 'save') {
    $config = $input['config'] ?? [];
    if (!is_array($config)) {
        sendJsonResponse(["status" => "error", "message" => "Date de configurare invalide"], 400);
    }
    $res = printServicePost('/config', ['config' => $config]);
    if ($res === null) {
        sendJsonResponse(["status" => "error", "message" => "Serviciul de tiparire nu raspunde; configuratia nu a fost salvata."], 502);
    }
    if (($res['status'] ?? '') !== 'success') {
        sendJsonResponse(["status" => "error", "message" => $res['message'] ?? "Eroare la salvare"], 400);
    }
    sendJsonResponse(["status" => "success", "message" => $res['message'] ?? "Configurare salvata", "config" => $res['config'] ?? null]);
}

if ($action === 'test') {
    $payload = [];
    if (isset($input['which'])) { $payload['which'] = $input['which']; }
    if (isset($input['target']) && is_array($input['target'])) { $payload['target'] = $input['target']; }
    if (!$payload) {
        sendJsonResponse(["status" => "error", "message" => "Tinta de test lipseste"], 400);
    }
    $res = printServicePost('/test', $payload, 15);
    if ($res === null) {
        sendJsonResponse(["status" => "error", "message" => "Serviciul de tiparire nu raspunde."], 502);
    }
    $code = (($res['status'] ?? '') === 'success') ? 200 : 400;
    sendJsonResponse([
        "status" => $res['status'] ?? 'error',
        "message" => $res['message'] ?? "Eroare test"
    ], $code);
}

sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
