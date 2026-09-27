<?php
/**
 * API: Cote TVA (tblTVA: Nr_TVA, Cota)
 * GET  -> lista cotelor
 * POST -> actiuni: insert / update / delete
 */
require_once __DIR__ . '/db.php';

$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = [];
    $stmt = sqlsrv_query($conn, "SELECT Nr_TVA, Cota FROM tblTVA ORDER BY Nr_TVA");
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = [
                "Nr_TVA" => (int)$r['Nr_TVA'],
                "Cota" => (float)$r['Cota']
            ];
        }
    }
    sendJsonResponse(["status" => "success", "rows" => $rows]);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input || !is_array($input)) {
    $input = $_POST;
}

$action = $input['action'] ?? '';

switch ($action) {
    case 'insert':
        $nrTva = (int)($input['Nr_TVA'] ?? 0);
        $cota = (float)($input['Cota'] ?? 0);
        if ($nrTva <= 0) {
            sendJsonResponse(["status" => "error", "message" => "Nr_TVA invalid"], 400);
        }
        if ($cota < 0) {
            sendJsonResponse(["status" => "error", "message" => "Cota nu poate fi negativa"], 400);
        }
        $stmt = sqlsrv_query($conn, "INSERT INTO tblTVA (Nr_TVA, Cota) VALUES (?, ?)", [$nrTva, $cota]);
        if (!$stmt) {
            sendJsonResponse(["status" => "error", "message" => "Eroare inserare TVA (posibil Nr_TVA duplicat): " . sqlsrv_errors()[0]['message']], 500);
        }
        sendJsonResponse(["status" => "success", "message" => "Cota TVA adaugata"]);
        break;

    case 'update':
        $nrTva = (int)($input['Nr_TVA'] ?? 0);
        $cota = (float)($input['Cota'] ?? 0);
        if ($nrTva <= 0) {
            sendJsonResponse(["status" => "error", "message" => "Nr_TVA invalid"], 400);
        }
        if ($cota < 0) {
            sendJsonResponse(["status" => "error", "message" => "Cota nu poate fi negativa"], 400);
        }
        $stmt = sqlsrv_query($conn, "UPDATE tblTVA SET Cota = ? WHERE Nr_TVA = ?", [$cota, $nrTva]);
        if (!$stmt) {
            sendJsonResponse(["status" => "error", "message" => "Eroare actualizare TVA: " . sqlsrv_errors()[0]['message']], 500);
        }
        sendJsonResponse(["status" => "success", "message" => "Cota TVA actualizata"]);
        break;

    case 'delete':
        $nrTva = (int)($input['Nr_TVA'] ?? 0);
        if ($nrTva <= 0) {
            sendJsonResponse(["status" => "error", "message" => "Nr_TVA invalid"], 400);
        }
        $stmt = sqlsrv_query($conn, "DELETE FROM tblTVA WHERE Nr_TVA = ?", [$nrTva]);
        if (!$stmt) {
            sendJsonResponse(["status" => "error", "message" => "Eroare stergere TVA: " . sqlsrv_errors()[0]['message']], 500);
        }
        sendJsonResponse(["status" => "success", "message" => "Cota TVA stearsa"]);
        break;

    default:
        sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}
