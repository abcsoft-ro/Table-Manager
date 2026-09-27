<?php
/**
 * API: Setari POS (tblSet: Setting, Value, Descriere, Grup)
 * GET  -> lista setarilor
 * POST -> actiune: update (batch de {Setting: Value})
 * Doar coloana Value este editabila.
 */
require_once __DIR__ . '/db.php';

/**
 * Descrierea canonica a setarii TipVanz. O scriem in tblSet la nevoie, ca sa
 * fie afisata corect in ecranul Setari si pe instalatiile existente.
 */
function ensureSetariMeta($conn) {
    static $done = false;
    if ($done) { return; }
    $desc = "Tipul de vanzare: 0 = Restaurant (ecran de mese, comanda la sectie, nota de plata); 1 = FastFood (fara ecran de mese, comanda nu pleaca la sectie, doar bon fiscal).";
    @sqlsrv_query(
        $conn,
        "UPDATE tblSet SET Descriere = ? WHERE Setting = 'TipVanz' AND (Descriere IS NULL OR Descriere <> ?)",
        [$desc, $desc]
    );
    $done = true;
}

$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    ensureSetariMeta($conn);
    $rows = [];
    $stmt = sqlsrv_query($conn, "SELECT Setting, Value, Descriere, Grup FROM tblSet ORDER BY Grup, Setting");
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = [
                "Setting" => trim($r['Setting'] ?? ''),
                "Value" => $r['Value'],
                "Descriere" => $r['Descriere'],
                "Grup" => trim($r['Grup'] ?? '')
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

if ($action === 'update') {
    $values = $input['values'] ?? [];
    if (!is_array($values)) {
        sendJsonResponse(["status" => "error", "message" => "Date invalide"], 400);
    }

    sqlsrv_begin_transaction($conn);
    foreach ($values as $setting => $value) {
        $setting = trim((string)$setting);
        if ($setting === '') { continue; }
        $stmt = sqlsrv_query($conn, "UPDATE tblSet SET Value = ? WHERE Setting = ?", [$value, $setting]);
        if ($stmt === false) {
            sqlsrv_rollback($conn);
            sendJsonResponse(["status" => "error", "message" => "Eroare salvare setare: " . sqlsrv_errors()[0]['message']], 500);
        }
    }
    sqlsrv_commit($conn);

    sendJsonResponse(["status" => "success", "message" => "Setarile au fost salvate"]);
}

sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
