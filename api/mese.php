<?php
/**
 * API: Programare mese (tblMese)
 * GET  -> lista meselor (mapat pe pozitiile 1..80 dupa NrMasa)
 * POST -> actiuni: insert / update / delete
 */
require_once __DIR__ . '/db.php';

function tblMeseWinColorToHex($v) {
    if ($v === null || $v === '') return null;
    $val = (int)$v;
    $r = $val & 0xFF;
    $g = ($val >> 8) & 0xFF;
    $b = ($val >> 16) & 0xFF;
    return sprintf("#%02x%02x%02x", $r, $g, $b);
}

function tblMeseIntOrNull($v) {
    $t = trim((string)$v);
    if ($t === '') return null;
    return (int)$t;
}

$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = [];
    $stmt = sqlsrv_query($conn, "SELECT MasaID, NrMasa, BackColor, ForeColor, Bold, Afisez, NrPOS, Obs
                                 FROM tblMese ORDER BY NrMasa");
    if ($stmt) {
        while ($m = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $bg = tblMeseIntOrNull($m['BackColor'] ?? '');
            $fg = tblMeseIntOrNull($m['ForeColor'] ?? '');
            $rows[] = [
                "MasaID" => (int)$m['MasaID'],
                "NrMasa" => (int)$m['NrMasa'],
                "BackColor" => $bg,
                "BackHex" => tblMeseWinColorToHex($bg),
                "ForeColor" => $fg,
                "ForeHex" => tblMeseWinColorToHex($fg),
                "Bold" => (bool)$m['Bold'],
                "Afisez" => (bool)$m['Afisez'],
                "NrPOS" => tblMeseIntOrNull($m['NrPOS'] ?? ''),
                "Obs" => trim($m['Obs'] ?? '')
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
$nrMasa = (int)($input['NrMasa'] ?? 0);
$masaId = (int)($input['MasaID'] ?? 0);

switch ($action) {
    case 'insert':
    case 'update':
        if ($nrMasa < 1 || $nrMasa > 80) {
            sendJsonResponse(["status" => "error", "message" => "NrMasa trebuie sa fie intre 1 si 80"], 400);
        }
        $bg = tblMeseIntOrNull($input['BackColor'] ?? '');
        $fg = tblMeseIntOrNull($input['ForeColor'] ?? '');
        $bold = !empty($input['Bold']) ? 1 : 0;
        $afisez = !empty($input['Afisez']) ? 1 : 0;
        $nrPos = tblMeseIntOrNull($input['NrPOS'] ?? '');
        $obs = trim($input['Obs'] ?? '');

        if ($action === 'insert') {
            $dup = sqlsrv_query($conn, "SELECT MasaID FROM tblMese WHERE NrMasa = ?", [$nrMasa]);
            if ($dup && sqlsrv_fetch_array($dup, SQLSRV_FETCH_ASSOC)) {
                sendJsonResponse(["status" => "error", "message" => "Exista deja o masa cu NrMasa " . $nrMasa . ". Folositi Salveaza (actualizare)."], 400);
            }
            $stmt = sqlsrv_query(
                $conn,
                "INSERT INTO tblMese (NrMasa, BackColor, ForeColor, Bold, Afisez, NrPOS, Obs)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$nrMasa, $bg, $fg, $bold, $afisez, $nrPos, ($obs === '' ? null : $obs)]
            );
            if (!$stmt) {
                sendJsonResponse(["status" => "error", "message" => "Eroare inserare masa: " . sqlsrv_errors()[0]['message']], 500);
            }
            sendJsonResponse(["status" => "success", "message" => "Masa " . $nrMasa . " adaugata"]);
        } else {
            if ($masaId <= 0) {
                sendJsonResponse(["status" => "error", "message" => "MasaID invalid pentru actualizare"], 400);
            }
            $stmt = sqlsrv_query(
                $conn,
                "UPDATE tblMese SET BackColor = ?, ForeColor = ?, Bold = ?, Afisez = ?, NrPOS = ?, Obs = ?
                 WHERE MasaID = ? AND NrMasa = ?",
                [$bg, $fg, $bold, $afisez, $nrPos, ($obs === '' ? null : $obs), $masaId, $nrMasa]
            );
            if (!$stmt) {
                sendJsonResponse(["status" => "error", "message" => "Eroare actualizare masa: " . sqlsrv_errors()[0]['message']], 500);
            }
            sendJsonResponse(["status" => "success", "message" => "Masa " . $nrMasa . " actualizata"]);
        }
        break;

    case 'delete':
        if ($masaId <= 0) {
            sendJsonResponse(["status" => "error", "message" => "MasaID invalid"], 400);
        }
        $stmt = sqlsrv_query($conn, "DELETE FROM tblMese WHERE MasaID = ?", [$masaId]);
        if (!$stmt) {
            sendJsonResponse(["status" => "error", "message" => "Eroare stergere masa: " . sqlsrv_errors()[0]['message']], 500);
        }
        sendJsonResponse(["status" => "success", "message" => "Masa stearsa"]);
        break;

    default:
        sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}
