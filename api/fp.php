<?php
/**
 * API: Forme de plata (tblFP: FPID, Denumire, Status)
 * GET  -> lista formelor
 * POST -> actiuni: insert / update / delete
 */
require_once __DIR__ . '/db.php';

$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = [];
    $stmt = sqlsrv_query($conn, "SELECT FPID, Denumire, Status, Poz FROM tblFP ORDER BY ISNULL(Poz, 9999), FPID");
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = [
                "FPID" => (int)$r['FPID'],
                "Denumire" => trim($r['Denumire'] ?? ''),
                "Status" => (int)$r['Status'],
                "Poz" => $r['Poz'] !== null ? (int)$r['Poz'] : null
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
$fpid = (int)($input['FPID'] ?? 0);

switch ($action) {
    case 'insert':
    case 'update':
        $denumire = trim($input['Denumire'] ?? '');
        $status = (int)($input['Status'] ?? 0);
        $poz = (isset($input['Poz']) && $input['Poz'] !== '' && $input['Poz'] !== null) ? (int)$input['Poz'] : null;
        if ($fpid < 0) {
            sendJsonResponse(["status" => "error", "message" => "FPID invalid"], 400);
        }
        if ($denumire === '' || strlen($denumire) > 10) {
            sendJsonResponse(["status" => "error", "message" => "Denumire obligatorie, maxim 10 caractere"], 400);
        }

        if ($action === 'insert') {
            $stmt = sqlsrv_query($conn, "INSERT INTO tblFP (FPID, Denumire, Status, Poz) VALUES (?, ?, ?, ?)", [$fpid, $denumire, $status, $poz]);
            if (!$stmt) {
                sendJsonResponse(["status" => "error", "message" => "Eroare inserare (posibil FPID duplicat): " . sqlsrv_errors()[0]['message']], 500);
            }
            sendJsonResponse(["status" => "success", "message" => "Forma de plata adaugata"]);
        } else {
            $stmt = sqlsrv_query($conn, "UPDATE tblFP SET Denumire = ?, Status = ?, Poz = ? WHERE FPID = ?", [$denumire, $status, $poz, $fpid]);
            if (!$stmt) {
                sendJsonResponse(["status" => "error", "message" => "Eroare actualizare: " . sqlsrv_errors()[0]['message']], 500);
            }
            sendJsonResponse(["status" => "success", "message" => "Forma de plata actualizata"]);
        }
        break;

    case 'delete':
        if ($fpid < 0) {
            sendJsonResponse(["status" => "error", "message" => "FPID invalid"], 400);
        }
        $stmt = sqlsrv_query($conn, "DELETE FROM tblFP WHERE FPID = ?", [$fpid]);
        if (!$stmt) {
            sendJsonResponse(["status" => "error", "message" => "Eroare stergere: " . sqlsrv_errors()[0]['message']], 500);
        }
        sendJsonResponse(["status" => "success", "message" => "Forma de plata stearsa"]);
        break;

    default:
        sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}
