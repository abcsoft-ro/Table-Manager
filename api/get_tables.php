<?php
/**
 * API: Returneaza starea meselor (1 - 80)
 * Verifica in tblMese si tblBonCurent (Stare = 'D')
 */
require_once __DIR__ . '/db.php';

$conn = getDBConnection();

// Incarcam configuratia meselor din tblMese
$sqlMese = "SELECT MasaID, NrMasa, BackColor, ForeColor, Bold, Afisez, Obs FROM tblMese ORDER BY NrMasa";
$stmtMese = sqlsrv_query($conn, $sqlMese);

$meseConfig = [];
if ($stmtMese) {
    while ($m = sqlsrv_fetch_array($stmtMese, SQLSRV_FETCH_ASSOC)) {
        $meseConfig[(int)$m['NrMasa']] = $m;
    }
}

// Incarcam bonurile deschise (Stare = 'D')
$sqlActive = "SELECT b.NrMasa, b.DocID, b.TotalB, b.NrOp, o.Nume as NumeCasier 
              FROM tblBonCurent b 
              LEFT JOIN tblOsp o ON b.NrOp = o.NrOsp 
              WHERE b.Stare = 'D'";
$stmtActive = sqlsrv_query($conn, $sqlActive);

$activeBills = [];
if ($stmtActive) {
    while ($b = sqlsrv_fetch_array($stmtActive, SQLSRV_FETCH_ASSOC)) {
        $activeBills[(int)$b['NrMasa']] = [
            "docId" => (int)$b['DocID'],
            "total" => (float)$b['TotalB'],
            "nrOp" => $b['NrOp'] === null ? null : (int)$b['NrOp'],
            "casier" => $b['NumeCasier'] ? trim($b['NumeCasier']) : "CASIER"
        ];
    }
}

// Construim lista celor 80 de mese
$tables = [];
for ($i = 1; $i <= 80; $i++) {
    $occupied = isset($activeBills[$i]);
    $cfg = $meseConfig[$i] ?? null;

    $hasCfg = isset($meseConfig[$i]);
    $tables[] = [
        "nrMasa" => $i,
        "isOccupied" => $occupied,
        // O pozitie este o masa reala doar daca are config in tblMese sau daca are un bon deschis
        "present" => $hasCfg || $occupied,
        "label" => $i === 80 ? "80 Protocol" : (string)$i,
        "casier" => $occupied ? $activeBills[$i]['casier'] : null,
        "nrOp" => $occupied ? $activeBills[$i]['nrOp'] : null,
        "total" => $occupied ? $activeBills[$i]['total'] : 0,
        "docId" => $occupied ? $activeBills[$i]['docId'] : null,
        "hasConfig" => $hasCfg,
        "backColor" => $hasCfg && $meseConfig[$i]['BackColor'] !== null ? (int)$meseConfig[$i]['BackColor'] : null,
        "foreColor" => $hasCfg && $meseConfig[$i]['ForeColor'] !== null ? (int)$meseConfig[$i]['ForeColor'] : null,
        "bold" => $hasCfg ? (bool)$meseConfig[$i]['Bold'] : false,
        "afisez" => $hasCfg ? (bool)$meseConfig[$i]['Afisez'] : true
    ];
}

sendJsonResponse([
    "status" => "success",
    "tables" => $tables
]);
