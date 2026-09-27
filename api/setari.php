<?php
/**
 * API: Setari POS (tblSet: Setting, Value, Descriere, Grup)
 * GET  -> lista setarilor
 * POST -> actiune: update (batch de {Setting: Value})
 * Doar coloana Value este editabila.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/print_common.php';

/** Tipul de vanzare din tblSet.TipVanz: "fastfood" cand Value = 1, altfel "restaurant". */
function setariTipVanz($conn) {
    $stmt = @sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'TipVanz'");
    $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    return ($row && (int)trim((string)$row['Value']) === 1) ? "fastfood" : "restaurant";
}

/** Numarul de bonuri deschise (mese neinchise) din tblBonCurent. */
function setariOpenBills($conn) {
    $stmt = @sqlsrv_query($conn, "SELECT COUNT(*) AS c FROM tblBonCurent WHERE Stare IS NULL OR Stare <> 'I'");
    $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    return (int)($row['c'] ?? 0);
}

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

    // Cantitatea maxima admisa pe o linie de nota; creata automat cu 1000 daca lipseste.
    $cantMaxDesc = "Cantitatea maxima admisa pentru un produs pe nota (protejeaza impotriva tastarii gresite). Implicit 1000.";
    $chk = @sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'CantMax'");
    $has = $chk ? sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC) : null;
    if (!$has) {
        @sqlsrv_query(
            $conn,
            "INSERT INTO tblSet (Setting, Value, Descriere, Grup) VALUES ('CantMax', '1000', ?, 'General')",
            [$cantMaxDesc]
        );
    } else {
        @sqlsrv_query(
            $conn,
            "UPDATE tblSet SET Descriere = ? WHERE Setting = 'CantMax' AND (Descriere IS NULL OR Descriere <> ?)",
            [$cantMaxDesc, $cantMaxDesc]
        );
    }

    // Contorul dedicat al bonurilor de sectie (nr. de pe bon), documentat in Descriere.
    ensureNrBonSetting($conn);

    // Numarul de zecimale pentru cantitate (0, 1 sau 2).
    $nrZecDesc = "Numarul de zecimale pentru cantitatea produselor: 0, 1 sau 2. Se aplica la afisarea pe nota si la introducerea cantitatii (0 = doar numere intregi).";
    $chkNZ = @sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'NrZecCant'");
    $hasNZ = $chkNZ ? sqlsrv_fetch_array($chkNZ, SQLSRV_FETCH_ASSOC) : null;
    if (!$hasNZ) {
        @sqlsrv_query(
            $conn,
            "INSERT INTO tblSet (Setting, Value, Descriere, Grup) VALUES ('NrZecCant', '1', ?, 'General')",
            [$nrZecDesc]
        );
    } else {
        @sqlsrv_query(
            $conn,
            "UPDATE tblSet SET Descriere = ? WHERE Setting = 'NrZecCant' AND (Descriere IS NULL OR Descriere <> ?)",
            [$nrZecDesc, $nrZecDesc]
        );
    }

    // Discountul permis/interzis (RED): 1 = DA, 0 = NU.
    $redDesc = "Permite sau interzice discountul: 1 = DA (butonul Discount este afisat), 0 = NU (butonul Discount este ascuns pe desktop si pe tableta).";
    $chkRed = @sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'RED'");
    $hasRed = $chkRed ? sqlsrv_fetch_array($chkRed, SQLSRV_FETCH_ASSOC) : null;
    if (!$hasRed) {
        @sqlsrv_query(
            $conn,
            "INSERT INTO tblSet (Setting, Value, Descriere, Grup) VALUES ('RED', '1', ?, 'General')",
            [$redDesc]
        );
    } else {
        @sqlsrv_query(
            $conn,
            "UPDATE tblSet SET Descriere = ? WHERE Setting = 'RED' AND (Descriere IS NULL OR Descriere <> ?)",
            [$redDesc, $redDesc]
        );
    }

    // Chei legacy, fara efect in aplicatie: se sterg din tblSet daca exista
    // (nu mai apar nici in ecranul Setari).
    @sqlsrv_query(
        $conn,
        "DELETE FROM tblSet WHERE Setting IN ('NrBonCmd', 'ParolaDiscount', 'Storno_parola', 'Retea')"
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
    // In modul Restaurant, numarul de zecimale poate fi modificat doar daca
    // toate mesele sunt inchise (in FastFood nu exista ecran de mese).
    $tipVanz = setariTipVanz($conn);
    $openBills = setariOpenBills($conn);
    sendJsonResponse([
        "status" => "success",
        "rows" => $rows,
        "tipVanz" => $tipVanz,
        "openBills" => $openBills,
        "canEditNrZecCant" => ($tipVanz === "fastfood" || $openBills === 0)
    ]);
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

    // In modul Restaurant, zecimalele cantitatii se pot modifica doar daca
    // toate mesele sunt inchise (in FastFood nu exista ecran de mese).
    if (array_key_exists('NrZecCant', $values)
        && setariTipVanz($conn) !== "fastfood"
        && setariOpenBills($conn) > 0) {
        sendJsonResponse([
            "status" => "error",
            "message" => "Numarul de zecimale poate fi modificat doar cand toate mesele sunt inchise."
        ], 400);
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
