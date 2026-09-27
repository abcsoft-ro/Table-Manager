<?php
/**
 * API: Parole (tblParola) - un singur rand (Id = 1)
 * GET  -> parolele configurate
 * POST -> actiune: update
 *
 * Convenție: câmp gol = parola nu este cerută.
 */
require_once __DIR__ . '/db.php';

$conn = getDBConnection();

$ID = 1;

$CAMPURI = [
    'ParolaProgramare' => 20,
    'ParolaRapoarte'   => 20,
    'ParolaStornare'   => 20,
    'ParolaDiscount'   => 20,
    'ParolaExit'       => 20
];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sql = "SELECT Id, ParolaProgramare, ParolaRapoarte, ParolaStornare, ParolaDiscount, ParolaExit
            FROM tblParola WHERE Id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$ID]);
    if (!$stmt) {
        sendJsonResponse(["status" => "error", "message" => "Eroare citire parole: " . sqlsrv_errors()[0]['message']], 500);
    }
    $r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if (!$r) {
        sendJsonResponse(["status" => "error", "message" => "Nu exista randul de parole (Id=1)"], 404);
    }

    $row = ["Id" => (int)$r['Id']];
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
            if ($val !== '' && !ctype_digit($val)) {
                sendJsonResponse(["status" => "error", "message" => "$col: parola trebuie sa contina doar cifre"], 400);
            }
            if (strlen($val) > $len) {
                sendJsonResponse(["status" => "error", "message" => "$col depaseste $len caractere"], 400);
            }
            $values[$col] = ($val === '' ? null : $val);
        }

        $sql = "UPDATE tblParola SET
                    ParolaProgramare = ?, ParolaRapoarte = ?, ParolaStornare = ?,
                    ParolaDiscount = ?, ParolaExit = ?
                WHERE Id = ?";
        $params = [
            $values['ParolaProgramare'], $values['ParolaRapoarte'], $values['ParolaStornare'],
            $values['ParolaDiscount'], $values['ParolaExit'], $ID
        ];
        $stmt = sqlsrv_query($conn, $sql, $params);
        if (!$stmt) {
            sendJsonResponse(["status" => "error", "message" => "Eroare actualizare parole: " . sqlsrv_errors()[0]['message']], 500);
        }
        sendJsonResponse(["status" => "success", "message" => "Parole actualizate"]);
        break;

    default:
        sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}
