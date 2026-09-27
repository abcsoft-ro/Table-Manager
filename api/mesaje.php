<?php
/**
 * API: Moduri preparare (tblMesaj: NrMesaj, Mesaj)
 * GET  -> lista modurilor
 * POST -> actiuni: insert / update / delete
 */
require_once __DIR__ . '/db.php';

$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = [];
    $stmt = sqlsrv_query($conn, "SELECT NrMesaj, Mesaj FROM tblMesaj ORDER BY CAST(NrMesaj AS INT)");
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = [
                "NrMesaj" => (int)$r['NrMesaj'],
                "Mesaj" => trim($r['Mesaj'])
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
        $nrMesaj = (int)($input['NrMesaj'] ?? 0);
        $mesaj = trim($input['Mesaj'] ?? '');
        if ($nrMesaj <= 0) {
            sendJsonResponse(["status" => "error", "message" => "NrMesaj invalid"], 400);
        }
        if ($mesaj === '') {
            sendJsonResponse(["status" => "error", "message" => "Mesajul nu poate fi gol"], 400);
        }
        $stmt = sqlsrv_query($conn, "INSERT INTO tblMesaj (NrMesaj, Mesaj) VALUES (?, ?)", [$nrMesaj, $mesaj]);
        if (!$stmt) {
            sendJsonResponse(["status" => "error", "message" => "Eroare inserare (posibil NrMesaj duplicat): " . sqlsrv_errors()[0]['message']], 500);
        }
        sendJsonResponse(["status" => "success", "message" => "Mod de preparare adaugat"]);
        break;

    case 'update':
        $nrMesaj = (int)($input['NrMesaj'] ?? 0);
        $mesaj = trim($input['Mesaj'] ?? '');
        if ($nrMesaj <= 0) {
            sendJsonResponse(["status" => "error", "message" => "NrMesaj invalid"], 400);
        }
        if ($mesaj === '') {
            sendJsonResponse(["status" => "error", "message" => "Mesajul nu poate fi gol"], 400);
        }
        $stmt = sqlsrv_query($conn, "UPDATE tblMesaj SET Mesaj = ? WHERE NrMesaj = ?", [$mesaj, $nrMesaj]);
        if (!$stmt) {
            sendJsonResponse(["status" => "error", "message" => "Eroare actualizare: " . sqlsrv_errors()[0]['message']], 500);
        }
        sendJsonResponse(["status" => "success", "message" => "Mod de preparare actualizat"]);
        break;

    case 'delete':
        $nrMesaj = (int)($input['NrMesaj'] ?? 0);
        if ($nrMesaj <= 0) {
            sendJsonResponse(["status" => "error", "message" => "NrMesaj invalid"], 400);
        }
        $stmt = sqlsrv_query($conn, "DELETE FROM tblMesaj WHERE NrMesaj = ?", [$nrMesaj]);
        if (!$stmt) {
            sendJsonResponse(["status" => "error", "message" => "Eroare stergere: " . sqlsrv_errors()[0]['message']], 500);
        }
        sendJsonResponse(["status" => "success", "message" => "Mod de preparare sters"]);
        break;

    default:
        sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}
