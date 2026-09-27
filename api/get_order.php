<?php
/**
 * API: Incarca nota de plata curenta pentru o masa specifica
 * Parametri GET: masa (int, ex: 1)
 */
require_once __DIR__ . '/db.php';

$conn = getDBConnection();

$nrMasa = isset($_GET['masa']) ? (int)$_GET['masa'] : 1;

// Cautam ultimul bon deschis (Stare = 'D') pentru aceasta masa
$sqlBon = "SELECT TOP 1 b.DocID, b.NrDoc, b.Data, b.Ora, b.NrOp, b.TotalB, b.Stare, b.NrMasa, o.Nume as NumeCasier
           FROM tblBonCurent b
           LEFT JOIN tblOsp o ON b.NrOp = o.NrOsp
           WHERE b.NrMasa = ? AND b.Stare = 'D'
           ORDER BY b.DocID DESC";

$params = [$nrMasa];
$stmtBon = sqlsrv_query($conn, $sqlBon, $params);

if ($stmtBon === false) {
    sendJsonResponse(["status" => "error", "message" => sqlsrv_errors()[0]['message']], 500);
}

$bon = sqlsrv_fetch_array($stmtBon, SQLSRV_FETCH_ASSOC);

if (!$bon) {
    // Masa este libera / nu are comanda deschisa
    sendJsonResponse([
        "status" => "success",
        "hasOrder" => false,
        "nrMasa" => $nrMasa,
        "docId" => null,
        "nrOp" => null,
        "casier" => "CASIER 1",
        "total" => 0,
        "subtotal" => 0,
        "reducere" => 0,
        "tva" => 0,
        "articole" => []
    ]);
}

$docId = (int)$bon['DocID'];

// Incarcam articolele din tblNoteD pentru acest DocID
$sqlArt = "SELECT d.ECRID, d.DocID, d.ProdID, d.Cant, d.PV, d.PVC, d.TVAc, d.Descriere, d.OraComanda, d.H, d.NrGrp, d.[Comment], d.Preluat, d.StornoRef,
                  COALESCE(p.Denumire, d.Descriere, 'Produs #' + CAST(d.ProdID AS VARCHAR)) as Denumire
           FROM tblNoteD d
           LEFT JOIN tblProd p ON d.ProdID = p.ProdID
           WHERE d.DocID = ?
           ORDER BY d.OraComanda, d.ECRID";

$stmtArt = sqlsrv_query($conn, $sqlArt, [$docId]);

$articole = [];
$subtotalOriginal = 0;
$subtotalNet = 0;
$currentIdx = -1;
$prodIdxByEcr = [];

while ($art = sqlsrv_fetch_array($stmtArt, SQLSRV_FETCH_ASSOC)) {
    $cant = (float)$art['Cant'];
    $prodId = (int)$art['ProdID'];
    $pv = (float)$art['PV'];
    // Pretul de catalog (PVC) pastreaza valoarea initiala, pentru a putea
    // afisa reducerea si pentru a putea anula discountul.
    $pvc = ($art['PVC'] !== null) ? (float)$art['PVC'] : $pv;
    // Valoarea randului este intotdeauna Cant * PV (coloana Val a fost eliminata)
    $val = $cant * $pv;
    $valOriginal = $cant * $pvc;

    // Randurile de "mod de preparare" au ProdID = 0 si Cant <= 0: se afiseaza
    // sub produsul care le precede si nu afecteaza totalul notei.
    $isModifier = ($prodId === 0 && $cant <= 0.0);
    if ($isModifier) {
        if ($currentIdx >= 0) {
            $articole[$currentIdx]['mods'][] = [
                "ecrId" => (int)$art['ECRID'],
                "nrGrp" => (int)$art['NrGrp'],
                "text" => trim($art['Descriere'])
            ];
        }
        continue;
    }

    // Linie de storno/anulare: produs real cu Cant negativ (valoare negativa)
    $isStorno = ($cant < 0.0);
    if ($isStorno) {
        $subtotalOriginal += $valOriginal;
        $subtotalNet += $val;
        $articole[] = [
            "ecrId" => (int)$art['ECRID'],
            "prodId" => $prodId,
            "denumire" => trim($art['Denumire']),
            "cantitate" => $cant,
            "pretUnitar" => (float)$pv,
            "pvc" => (float)$pvc,
            "valoare" => (float)$val,
            "valoareOriginala" => (float)$valOriginal,
            "comment" => trim((string)($art['Comment'] ?? '')),
            "storno" => true,
            "stornoRef" => (int)($art['StornoRef'] ?? 0),
            "preluat" => ((int)$art['Preluat'] === 1),
            "cook" => false,
            "tva" => (int)($art['TVAc'] ?? 9),
            "mods" => []
        ];
        $currentIdx = count($articole) - 1;
        continue;
    }

    // Linie de produs normala
    $subtotalOriginal += $valOriginal;
    $subtotalNet += $val;

    $articole[] = [
        "ecrId" => (int)$art['ECRID'],
        "prodId" => $prodId,
        "denumire" => trim($art['Denumire']),
        "cantitate" => $cant,
        "pretUnitar" => (float)$pv,
        "pvc" => (float)$pvc,
        "valoare" => (float)$val,
        "valoareOriginala" => (float)$valOriginal,
        "comment" => trim((string)($art['Comment'] ?? '')),
        "storno" => false,
        "stornoRef" => 0,
        "preluat" => ((int)$art['Preluat'] === 1),
        "stornat" => 0.0,
        "ramas" => $cant,
        "cook" => (bool)$art['H'],
        "tva" => (int)($art['TVAc'] ?? 9),
        "mods" => []
    ];
    $currentIdx = count($articole) - 1;
    $prodIdxByEcr[(int)$art['ECRID']] = $currentIdx;
}

// Cantitatea deja stornata pentru fiecare linie de produs (StornoRef -> original)
if (!empty($prodIdxByEcr)) {
    $sqlStorn = "SELECT StornoRef, COALESCE(SUM(-Cant), 0) AS s
                 FROM tblNoteD
                 WHERE DocID = ? AND Cant < 0 AND StornoRef IS NOT NULL
                 GROUP BY StornoRef";
    $stmtStorn = sqlsrv_query($conn, $sqlStorn, [$docId]);
    if ($stmtStorn) {
        while ($s = sqlsrv_fetch_array($stmtStorn, SQLSRV_FETCH_ASSOC)) {
            $ref = (int)$s['StornoRef'];
            if (isset($prodIdxByEcr[$ref])) {
                $i = $prodIdxByEcr[$ref];
                $stornat = (float)$s['s'];
                $articole[$i]['stornat'] = $stornat;
                $articole[$i]['ramas'] = $articole[$i]['cantitate'] - $stornat;
            }
        }
    }
}

// Formatare data/ora
$dataOraStr = "";
if (!empty($bon['Ora'])) {
    $dt = new DateTime($bon['Ora']);
    $dataOraStr = $dt->format('d-M-y   H:i');
} else if (!empty($bon['Data'])) {
    $dt = new DateTime($bon['Data']);
    $dataOraStr = $dt->format('d-M-y') . "   17:39";
}

sendJsonResponse([
    "status" => "success",
    "hasOrder" => true,
    "docId" => $docId,
    "nrDoc" => (int)$bon['NrDoc'],
    "nrMasa" => $nrMasa,
    "nrOp" => $bon['NrOp'] === null ? null : (int)$bon['NrOp'],
    "casier" => $bon['NumeCasier'] ? trim($bon['NumeCasier']) : "CASIER 1",
    "dataOra" => $dataOraStr,
    "subtotal" => (float)$subtotalOriginal,
    "total" => (float)$subtotalNet,
    "reducere" => (float)($subtotalOriginal - $subtotalNet),
    "articole" => $articole
]);
