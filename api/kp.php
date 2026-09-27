<?php
/**
 * API: Imprimante sectii (tblKP)
 * GET  -> lista imprimantelor
 * POST -> actiuni: insert / update / delete
 */
require_once __DIR__ . '/db.php';

$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = [];
    $stmt = sqlsrv_query($conn, "SELECT NrLogic, Serie, Stare, Nume, MagID, CaleTSC, raport, DenumirePrinter
                                 FROM tblKP ORDER BY NrLogic");
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = [
                "NrLogic" => (int)$r['NrLogic'],
                "Serie" => trim($r['Serie'] ?? ''),
                "Stare" => (bool)$r['Stare'],
                "Nume" => trim($r['Nume'] ?? ''),
                "MagID" => $r['MagID'] === null ? null : (int)$r['MagID'],
                "CaleTSC" => trim($r['CaleTSC'] ?? ''),
                "raport" => trim($r['raport'] ?? ''),
                "DenumirePrinter" => trim($r['DenumirePrinter'] ?? '')
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
$nrLogic = (int)($input['NrLogic'] ?? 0);

switch ($action) {
    case 'insert':
    case 'update':
        if ($nrLogic < 1 || $nrLogic > 255) {
            sendJsonResponse(["status" => "error", "message" => "NrLogic trebuie sa fie intre 1 si 255"], 400);
        }

        $serie = trim($input['Serie'] ?? '');
        $nume = trim($input['Nume'] ?? '');
        $caleTSC = trim($input['CaleTSC'] ?? '');
        $raport = trim($input['raport'] ?? '');
        $denPrinter = trim($input['DenumirePrinter'] ?? '');
        $magIdRaw = trim($input['MagID'] ?? '');
        $magId = ($magIdRaw === '') ? null : (int)$magIdRaw;
        $stare = !empty($input['Stare']) ? 1 : 0;

        $params = [
            ($serie === '' ? null : $serie),
            $stare,
            ($nume === '' ? null : $nume),
            $magId,
            ($caleTSC === '' ? null : $caleTSC),
            ($raport === '' ? null : $raport),
            ($denPrinter === '' ? null : $denPrinter)
        ];

        if ($action === 'insert') {
            $stmt = sqlsrv_query(
                $conn,
                "INSERT INTO tblKP (NrLogic, Serie, Stare, Nume, MagID, CaleTSC, raport, DenumirePrinter)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                array_merge([$nrLogic], $params)
            );
            if (!$stmt) {
                sendJsonResponse(["status" => "error", "message" => "Eroare inserare imprimanta (posibil NrLogic duplicat): " . sqlsrv_errors()[0]['message']], 500);
            }
            sendJsonResponse(["status" => "success", "message" => "Imprimanta adaugata"]);
        } else {
            $stmt = sqlsrv_query(
                $conn,
                "UPDATE tblKP SET Serie = ?, Stare = ?, Nume = ?, MagID = ?, CaleTSC = ?, raport = ?, DenumirePrinter = ?
                 WHERE NrLogic = ?",
                array_merge($params, [$nrLogic])
            );
            if (!$stmt) {
                sendJsonResponse(["status" => "error", "message" => "Eroare actualizare imprimanta: " . sqlsrv_errors()[0]['message']], 500);
            }
            sendJsonResponse(["status" => "success", "message" => "Imprimanta actualizata"]);
        }
        break;

    case 'delete':
        if ($nrLogic < 1) {
            sendJsonResponse(["status" => "error", "message" => "NrLogic invalid"], 400);
        }
        $stmt = sqlsrv_query($conn, "DELETE FROM tblKP WHERE NrLogic = ?", [$nrLogic]);
        if (!$stmt) {
            sendJsonResponse(["status" => "error", "message" => "Eroare stergere imprimanta: " . sqlsrv_errors()[0]['message']], 500);
        }
        sendJsonResponse(["status" => "success", "message" => "Imprimanta stearsa"]);
        break;

    default:
        sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}
