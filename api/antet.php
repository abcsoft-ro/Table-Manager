<?php
/**
 * API: Antet (tblAntet) - doar linia cu Seria = '0001'
 * GET  -> datele antetului
 * POST -> actiune: update
 */
require_once __DIR__ . '/db.php';

$conn = getDBConnection();

$SERIA = '0001';

$CAMPURI = [
    'Nume'         => 40,
    'Denumire2'    => 40,
    'Adresa'       => 40,
    'CodFiscF'     => 40,
    'RegCom'       => 22,
    'Judet'        => 22,
    'Cont'         => 22,
    'Banca'        => 40,
    'Oras'         => 50,
    'CodPostal'    => 10,
    'PersContact'  => 100,
    'email'        => 50,
    'website'      => 50,
    'telefon'      => 50,
    'CaleDateLogo' => 255,
    'QRCodeText'   => 255
];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sql = "SELECT Nume, Denumire2, Adresa, CodFiscF, RegCom, Judet, Cont, Banca,
                   Oras, CodPostal, PersContact, email, website, telefon,
                   PlatitorTVA, CaleDateLogo, QRCodeText, SizeModeLogo
            FROM tblAntet WHERE Seria = ?";
    $stmt = sqlsrv_query($conn, $sql, [$SERIA]);
    if (!$stmt) {
        sendJsonResponse(["status" => "error", "message" => "Eroare citire antet: " . sqlsrv_errors()[0]['message']], 500);
    }
    $r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if (!$r) {
        sendJsonResponse(["status" => "error", "message" => "Nu exista linia de antet cu Seria '0001'"], 404);
    }

    $row = [];
    foreach ($CAMPURI as $col => $len) {
        $row[$col] = trim($r[$col] ?? '');
    }
    $row['PlatitorTVA'] = (int)$r['PlatitorTVA'];
    $row['SizeModeLogo'] = $r['SizeModeLogo'] !== null ? (int)$r['SizeModeLogo'] : 0;

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
            if (mb_strlen($val) > $len) {
                sendJsonResponse(["status" => "error", "message" => "$col depaseste $len caractere"], 400);
            }
            $values[$col] = $val;
        }

        $platitor = (int)($input['PlatitorTVA'] ?? 0) ? 1 : 0;
        $sizeMode = (int)($input['SizeModeLogo'] ?? 0);

        $sql = "UPDATE tblAntet SET
                    Nume = ?, Denumire2 = ?, Adresa = ?, CodFiscF = ?, RegCom = ?,
                    Judet = ?, Cont = ?, Banca = ?, Oras = ?, CodPostal = ?,
                    PersContact = ?, email = ?, website = ?, telefon = ?,
                    PlatitorTVA = ?, CaleDateLogo = ?, QRCodeText = ?, SizeModeLogo = ?
                WHERE Seria = ?";
        $params = [
            $values['Nume'], $values['Denumire2'], $values['Adresa'], $values['CodFiscF'], $values['RegCom'],
            $values['Judet'], $values['Cont'], $values['Banca'], $values['Oras'], $values['CodPostal'],
            $values['PersContact'], $values['email'], $values['website'], $values['telefon'],
            $platitor, $values['CaleDateLogo'], $values['QRCodeText'], $sizeMode, $SERIA
        ];
        $stmt = sqlsrv_query($conn, $sql, $params);
        if (!$stmt) {
            sendJsonResponse(["status" => "error", "message" => "Eroare actualizare antet: " . sqlsrv_errors()[0]['message']], 500);
        }
        sendJsonResponse(["status" => "success", "message" => "Antet actualizat"]);
        break;

    default:
        sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}
