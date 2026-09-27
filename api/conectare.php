<?php
/**
 * API: Conectare server (tblConectare) - un singur rand (ID = 1)
 * GET  -> datele de conectare
 * POST -> actiune: update
 */
require_once __DIR__ . '/db.php';

$conn = getDBConnection();

$ID = 1;

$CAMPURI = [
    'ServerIP'            => 50,
    'ServerName'          => 50,
    'DataBaseName'        => 50,
    'UserName'            => 50,
    'Password'            => 50,
    'ODBC_connect_string' => 255
];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sql = "SELECT ID, ServerIP, ServerName, DataBaseName, UserName, Password, TC, ODBC_connect_string
            FROM tblConectare WHERE ID = ?";
    $stmt = sqlsrv_query($conn, $sql, [$ID]);
    if (!$stmt) {
        sendJsonResponse(["status" => "error", "message" => "Eroare citire conectare: " . sqlsrv_errors()[0]['message']], 500);
    }
    $r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if (!$r) {
        sendJsonResponse(["status" => "error", "message" => "Nu exista randul de conectare (ID=1)"], 404);
    }

    $row = ["ID" => (int)$r['ID'], "TC" => (int)$r['TC']];
    foreach ($CAMPURI as $col => $len) {
        $row[$col] = trim($r[$col] ?? '');
    }
    sendJsonResponse(["status" => "success", "row" => $row]);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input || !is_array($input)) {
    $input = $_POST;
}

$action = $input['action'] ?? '';

switch ($action) {
    case 'update':
        $values = [];
        foreach ($CAMPURI as $col => $len) {
            $val = trim((string)($input[$col] ?? ''));
            if (mb_strlen($val) > $len) {
                sendJsonResponse(["status" => "error", "message" => "$col depaseste $len caractere"], 400);
            }
            $values[$col] = $val;
        }
        $tc = (int)($input['TC'] ?? 0) ? 1 : 0;

        $sql = "UPDATE tblConectare SET
                    ServerIP = ?, ServerName = ?, DataBaseName = ?, UserName = ?,
                    Password = ?, TC = ?, ODBC_connect_string = ?
                WHERE ID = ?";
        $params = [
            $values['ServerIP'], $values['ServerName'], $values['DataBaseName'], $values['UserName'],
            $values['Password'], $tc, $values['ODBC_connect_string'], $ID
        ];
        $stmt = sqlsrv_query($conn, $sql, $params);
        if (!$stmt) {
            sendJsonResponse(["status" => "error", "message" => "Eroare actualizare conectare: " . sqlsrv_errors()[0]['message']], 500);
        }
        sendJsonResponse(["status" => "success", "message" => "Conectare server actualizata"]);
        break;

    case 'test':
        // Testeaza conexiunea folosind valorile trimise din formular (chiar si
        // nesalvate), dupa aceeasi logica precum sync_products.php.
        $server   = trim((string)($input['ServerIP'] ?? ''));
        $instance = trim((string)($input['ServerName'] ?? ''));
        $database = trim((string)($input['DataBaseName'] ?? ''));
        $user     = trim((string)($input['UserName'] ?? ''));
        $pass     = (string)($input['Password'] ?? '');
        $tc       = (int)($input['TC'] ?? 0) ? true : false;

        $srv = ($instance !== '' && strcasecmp($instance, $server) !== 0)
            ? $server . '\\' . $instance
            : $server;

        if ($srv === '') {
            sendJsonResponse(["status" => "error", "message" => "ServerIP este obligatoriu"], 400);
        }

        $ci = [
            "CharacterSet" => "UTF-8",
            "LoginTimeout" => 5
        ];
        if ($database !== '') {
            $ci["Database"] = $database;
        }
        if (!$tc) {
            $ci["UID"] = $user;
            $ci["PWD"] = $pass;
        }

        $testConn = sqlsrv_connect($srv, $ci);
        if ($testConn) {
            sqlsrv_close($testConn);
            $target = $srv . ($database !== '' ? ' / ' . $database : '');
            sendJsonResponse(["status" => "success", "message" => "Conexiune reusita la " . $target]);
        }

        $err = sqlsrv_errors();
        $errMsg = $err ? $err[0]['message'] : 'eroare necunoscuta';
        sendJsonResponse(["status" => "error", "message" => "Conexiune esuata la " . $srv . ": " . $errMsg], 500);
        break;

    default:
        sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}
