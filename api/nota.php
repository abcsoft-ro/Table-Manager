<?php
/**
 * API: Nota (tblAntet) - liniile de header/footer H1-H3 si F1-F2, plus
 * footer-ul suplimentar pentru proforma P1-P2 (valabil doar la proforma).
 * GET  -> lista liniilor
 * POST -> actiune: update (batch, in tranzactie)
 */
require_once __DIR__ . '/db.php';

$conn = getDBConnection();

$SERII = ['H1', 'H2', 'H3', 'F1', 'F2', 'P1', 'P2'];

/**
 * Creeaza liniile de footer suplimentare, valabile doar pentru nota proforma
 * (P1, P2), daca nu exista deja in tblAntet.
 */
function ensureProformaFooter($conn) {
    foreach (['P1', 'P2'] as $s) {
        @sqlsrv_query(
            $conn,
            "IF NOT EXISTS (SELECT 1 FROM tblAntet WHERE Seria = ?)
                 INSERT INTO tblAntet (Seria, Nume, NumeFont, Size, Bold) VALUES (?, '', 'A', 0, 0)",
            [$s, $s]
        );
    }
}

ensureProformaFooter($conn);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = [];
    $sql = "SELECT Seria, Nume, NumeFont, Size, Bold
            FROM tblAntet
            WHERE Seria IN ('H1','H2','H3','F1','F2','P1','P2')";
    $stmt = sqlsrv_query($conn, $sql);
    if (!$stmt) {
        sendJsonResponse(["status" => "error", "message" => "Eroare citire nota: " . sqlsrv_errors()[0]['message']], 500);
    }
    $found = [];
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $found[trim($r['Seria'])] = [
            "Seria"    => trim($r['Seria']),
            "Nume"     => trim($r['Nume'] ?? ''),
            "NumeFont" => trim($r['NumeFont'] ?? ''),
            "Size"     => $r['Size'] !== null ? (int)$r['Size'] : 0,
            "Bold"     => (int)$r['Bold']
        ];
    }
    foreach ($SERII as $s) {
        $rows[] = $found[$s] ?? ["Seria" => $s, "Nume" => "", "NumeFont" => "", "Size" => 0, "Bold" => 0];
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
    case 'update':
        $incoming = $input['rows'] ?? [];
        if (!is_array($incoming)) {
            sendJsonResponse(["status" => "error", "message" => "Date invalide"], 400);
        }

        $bySeria = [];
        foreach ($incoming as $r) {
            $s = trim((string)($r['Seria'] ?? ''));
            if (in_array($s, $SERII, true)) {
                $bySeria[$s] = $r;
            }
        }

        sqlsrv_begin_transaction($conn);
        foreach ($SERII as $s) {
            if (!isset($bySeria[$s])) {
                continue;
            }
            $r = $bySeria[$s];
            $nume = trim((string)($r['Nume'] ?? ''));
            $font = trim((string)($r['NumeFont'] ?? ''));
            $size = (int)($r['Size'] ?? 0);
            $bold = (int)($r['Bold'] ?? 0) ? 1 : 0;

            if (mb_strlen($nume) > 40) {
                sqlsrv_rollback($conn);
                sendJsonResponse(["status" => "error", "message" => "Nume prea lung pentru $s (max 40)"], 400);
            }
            if (mb_strlen($font) > 50) {
                sqlsrv_rollback($conn);
                sendJsonResponse(["status" => "error", "message" => "NumeFont prea lung pentru $s (max 50)"], 400);
            }

            $stmt = sqlsrv_query($conn,
                "UPDATE tblAntet SET Nume = ?, NumeFont = ?, Size = ?, Bold = ? WHERE Seria = ?",
                [$nume, $font, $size, $bold, $s]);
            if (!$stmt) {
                $err = sqlsrv_errors()[0]['message'];
                sqlsrv_rollback($conn);
                sendJsonResponse(["status" => "error", "message" => "Eroare actualizare $s: " . $err], 500);
            }
        }
        sqlsrv_commit($conn);
        sendJsonResponse(["status" => "success", "message" => "Nota actualizata"]);
        break;

    default:
        sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}
