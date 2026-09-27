<?php
/**
 * API: Sectii de tiparire (tblKP) - catalogul logic al imprimantelor de sectie.
 *
 * NrLogic = identificatorul folosit de tblProd.KP si in coada de tiparire.
 * Nume    = eticheta afisata (bon de sectie, coada, editorul de produse).
 * Stare   = 1 activa / 0 inactiva.
 * Destinatia fizica (IP/port/imprimanta Windows) NU se tine aici, ci in
 * print-service/config.json (editat din Setari > Imprimante).
 *
 * GET  -> lista sectiilor (+ usedCount = cate produse le folosesc)
 * POST -> actiuni: insert / update / delete
 */
require_once __DIR__ . '/db.php';

$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Cate produse folosesc fiecare NrLogic (tblProd.KP).
    $used = [];
    $stmtU = sqlsrv_query($conn, "SELECT KP, COUNT(*) AS c FROM tblProd WHERE KP IS NOT NULL AND KP > 0 GROUP BY KP");
    if ($stmtU) {
        while ($u = sqlsrv_fetch_array($stmtU, SQLSRV_FETCH_ASSOC)) {
            $used[(int)$u['KP']] = (int)$u['c'];
        }
    }

    $rows = [];
    $stmt = sqlsrv_query($conn, "SELECT NrLogic, Stare, Nume, DenumirePrinter FROM tblKP ORDER BY NrLogic");
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $nr = (int)$r['NrLogic'];
            $rows[] = [
                "NrLogic" => $nr,
                "Stare" => (bool)$r['Stare'],
                "Nume" => trim($r['Nume'] ?? ''),
                "DenumirePrinter" => trim($r['DenumirePrinter'] ?? ''),
                "usedCount" => $used[$nr] ?? 0
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

        $nume = trim($input['Nume'] ?? '');
        $denPrinter = trim($input['DenumirePrinter'] ?? '');
        $stare = !empty($input['Stare']) ? 1 : 0;
        if ($nume === '') {
            sendJsonResponse(["status" => "error", "message" => "Numele sectiei este obligatoriu"], 400);
        }

        $params = [($nume === '' ? null : $nume), $stare, ($denPrinter === '' ? null : $denPrinter)];

        if ($action === 'insert') {
            $stmt = sqlsrv_query(
                $conn,
                "INSERT INTO tblKP (NrLogic, Serie, Stare, Nume, MagID, CaleTSC, raport, DenumirePrinter)
                 VALUES (?, NULL, ?, ?, NULL, NULL, NULL, ?)",
                array_merge([$nrLogic], $params)
            );
            if (!$stmt) {
                sendJsonResponse(["status" => "error", "message" => "Eroare inserare sectie (posibil NrLogic duplicat): " . sqlsrv_errors()[0]['message']], 500);
            }
            sendJsonResponse(["status" => "success", "message" => "Sectia a fost adaugata"]);
        } else {
            $stmt = sqlsrv_query(
                $conn,
                "UPDATE tblKP SET Nume = ?, Stare = ?, DenumirePrinter = ? WHERE NrLogic = ?",
                array_merge($params, [$nrLogic])
            );
            if (!$stmt) {
                sendJsonResponse(["status" => "error", "message" => "Eroare actualizare sectie: " . sqlsrv_errors()[0]['message']], 500);
            }
            sendJsonResponse(["status" => "success", "message" => "Sectia a fost actualizata"]);
        }
        break;

    case 'delete':
        if ($nrLogic < 1) {
            sendJsonResponse(["status" => "error", "message" => "NrLogic invalid"], 400);
        }
        $stmtC = sqlsrv_query($conn, "SELECT COUNT(*) AS c FROM tblProd WHERE KP = ?", [$nrLogic]);
        $cnt = $stmtC ? (int)sqlsrv_fetch_array($stmtC, SQLSRV_FETCH_ASSOC)['c'] : 0;
        if ($cnt > 0) {
            sendJsonResponse([
                "status" => "error",
                "message" => "Sectia are $cnt produse atasate; mutati-le pe alta sectie inainte de stergere."
            ], 409);
        }
        $stmt = sqlsrv_query($conn, "DELETE FROM tblKP WHERE NrLogic = ?", [$nrLogic]);
        if (!$stmt) {
            sendJsonResponse(["status" => "error", "message" => "Eroare stergere sectie: " . sqlsrv_errors()[0]['message']], 500);
        }
        sendJsonResponse(["status" => "success", "message" => "Sectia a fost stearsa"]);
        break;

    default:
        sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}
