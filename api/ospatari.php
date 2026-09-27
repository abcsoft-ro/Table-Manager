<?php
/**
 * API: Ospatari (tblOsp: NrOsp, Nume, Expl, Parola, Blocat)
 * GET  -> lista ospatarilor
 * POST -> actiuni: insert / update / delete
 */
require_once __DIR__ . '/db.php';

$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = [];
    $stmt = sqlsrv_query($conn, "SELECT NrOsp, Nume, Expl, Parola, Blocat
                                 FROM tblOsp ORDER BY CAST(NrOsp AS INT)");
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = [
                "NrOsp" => trim($r['NrOsp']),
                "Nume" => trim($r['Nume'] ?? ''),
                "Expl" => trim($r['Expl'] ?? ''),
                "Parola" => trim($r['Parola'] ?? ''),
                "Blocat" => (bool)$r['Blocat']
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
$nrOsp = trim($input['NrOsp'] ?? '');

switch ($action) {
    case 'insert':
        if ($nrOsp === '' || strlen($nrOsp) > 2) {
            sendJsonResponse(["status" => "error", "message" => "NrOsp este obligatoriu (maxim 2 caractere)"], 400);
        }
        $nume = trim($input['Nume'] ?? '');
        $expl = trim($input['Expl'] ?? '');
        $parola = trim($input['Parola'] ?? '');
        $blocat = !empty($input['Blocat']) ? 1 : 0;

        $stmt = sqlsrv_query(
            $conn,
            "INSERT INTO tblOsp (NrOsp, Nume, Expl, Parola, Blocat) VALUES (?, ?, ?, ?, ?)",
            [$nrOsp, ($nume === '' ? null : $nume), ($expl === '' ? null : $expl), ($parola === '' ? null : $parola), $blocat]
        );
        if (!$stmt) {
            sendJsonResponse(["status" => "error", "message" => "Eroare inserare ospatar (posibil NrOsp duplicat): " . sqlsrv_errors()[0]['message']], 500);
        }
        sendJsonResponse(["status" => "success", "message" => "Ospatar adaugat"]);
        break;

    case 'update':
        if ($nrOsp === '' || strlen($nrOsp) > 2) {
            sendJsonResponse(["status" => "error", "message" => "NrOsp este obligatoriu (maxim 2 caractere)"], 400);
        }
        $nume = trim($input['Nume'] ?? '');
        $expl = trim($input['Expl'] ?? '');
        $parola = trim($input['Parola'] ?? '');
        $blocat = !empty($input['Blocat']) ? 1 : 0;

        $stmt = sqlsrv_query(
            $conn,
            "UPDATE tblOsp SET Nume = ?, Expl = ?, Parola = ?, Blocat = ? WHERE NrOsp = ?",
            [($nume === '' ? null : $nume), ($expl === '' ? null : $expl), ($parola === '' ? null : $parola), $blocat, $nrOsp]
        );
        if (!$stmt) {
            sendJsonResponse(["status" => "error", "message" => "Eroare actualizare ospatar: " . sqlsrv_errors()[0]['message']], 500);
        }
        sendJsonResponse(["status" => "success", "message" => "Ospatar actualizat"]);
        break;

    case 'delete':
        if ($nrOsp === '') {
            sendJsonResponse(["status" => "error", "message" => "NrOsp invalid"], 400);
        }
        $stmt = sqlsrv_query($conn, "DELETE FROM tblOsp WHERE NrOsp = ?", [$nrOsp]);
        if (!$stmt) {
            sendJsonResponse(["status" => "error", "message" => "Eroare stergere ospatar: " . sqlsrv_errors()[0]['message']], 500);
        }
        sendJsonResponse(["status" => "success", "message" => "Ospatar sters"]);
        break;

    default:
        sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}
