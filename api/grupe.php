<?php
/**
 * API: Programare grupe (tblGrp)
 * GET  -> lista grupelor (cu culori ca int + hex)
 * POST -> actiuni: insert / update  (NU se permite stergerea)
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/colors.php';

function tblGrpIntOrNull($v) {
    $t = trim((string)$v);
    if ($t === '') return null;
    return (int)$t;
}

$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = [];
    $stmt = sqlsrv_query($conn, "SELECT NrGrp, Denumire, BackColor, FontColor, FontSize, Bold, FontType, Poz, Tint, NrKp
                                 FROM tblGrp ORDER BY Poz, NrGrp");
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $bg = tblGrpIntOrNull($r['BackColor'] ?? '');
            $fg = tblGrpIntOrNull($r['FontColor'] ?? '');
            $rows[] = [
                "NrGrp" => (int)$r['NrGrp'],
                "Denumire" => trim($r['Denumire'] ?? ''),
                "BackColor" => $bg,
                "BackHex" => winColorToHex($bg),
                "FontColor" => $fg,
                "FontHex" => winColorToHex($fg),
                "FontSize" => tblGrpIntOrNull($r['FontSize'] ?? ''),
                "Bold" => (bool)($r['Bold'] == -1 || $r['Bold'] == 1),
                "FontType" => trim($r['FontType'] ?? ''),
                "Poz" => tblGrpIntOrNull($r['Poz'] ?? ''),
                "Tint" => (bool)($r['Tint'] == -1 || $r['Tint'] == 1),
                "NrKp" => tblGrpIntOrNull($r['NrKp'] ?? '')
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
$nrGrp = (int)($input['NrGrp'] ?? 0);

switch ($action) {
    case 'insert':
    case 'update':
        $denumire = trim($input['Denumire'] ?? '');
        $bg = tblGrpIntOrNull($input['BackColor'] ?? '');
        $fg = tblGrpIntOrNull($input['FontColor'] ?? '');
        $fontSize = tblGrpIntOrNull($input['FontSize'] ?? '');
        $fontType = trim($input['FontType'] ?? '');
        $poz = tblGrpIntOrNull($input['Poz'] ?? '');
        $bold = !empty($input['Bold']) ? 1 : 0;

        if ($nrGrp <= 0) {
            sendJsonResponse(["status" => "error", "message" => "NrGrp invalid"], 400);
        }
        if ($denumire === '' || mb_strlen($denumire) > 50) {
            sendJsonResponse(["status" => "error", "message" => "Denumire obligatorie, maxim 50 de caractere"], 400);
        }
        if ($poz !== null && ($poz < 1 || $poz > 50)) {
            sendJsonResponse(["status" => "error", "message" => "Poz trebuie sa fie intre 1 si 50"], 400);
        }

        if ($action === 'insert') {
            $stmt = sqlsrv_query(
                $conn,
                "INSERT INTO tblGrp (NrGrp, Denumire, BackColor, FontColor, FontSize, Bold, FontType, Poz, Tint, NrKp)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NULL)",
                [$nrGrp, $denumire, $bg, $fg, $fontSize, $bold, ($fontType === '' ? null : $fontType), $poz]
            );
            if (!$stmt) {
                sendJsonResponse(["status" => "error", "message" => "Eroare inserare (posibil NrGrp duplicat): " . sqlsrv_errors()[0]['message']], 500);
            }
            sendJsonResponse(["status" => "success", "message" => "Grupa adaugata"]);
        } else {
            // Actualizam doar coloanele editabile; Tint si NrKp raman neschimbate
            $stmt = sqlsrv_query(
                $conn,
                "UPDATE tblGrp SET Denumire = ?, BackColor = ?, FontColor = ?, FontSize = ?, Bold = ?, FontType = ?, Poz = ?
                 WHERE NrGrp = ?",
                [$denumire, $bg, $fg, $fontSize, $bold, ($fontType === '' ? null : $fontType), $poz, $nrGrp]
            );
            if (!$stmt) {
                sendJsonResponse(["status" => "error", "message" => "Eroare actualizare grupa: " . sqlsrv_errors()[0]['message']], 500);
            }
            sendJsonResponse(["status" => "success", "message" => "Grupa actualizata"]);
        }
        break;

    default:
        sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}
