<?php
/**
 * API: Incarca categoriile (tblGrp) si produsele (tblProd) cu pretul din tblProd.PV
 * Include Poz, BackColor, FontColor, Bold conform structurii originale Access
 * Produsele cu BackColor = 0 au garantat FontColor alb (#ffffff)
 */
require_once __DIR__ . '/db.php';

$conn = getDBConnection();

// Functie auxiliara pentru transformarea culorii Windows COLORREF (0x00BBGGRR) in Hex (#RRGGBB)
function winColorToHex($colorVal) {
    if ($colorVal === null || $colorVal === '') return null;
    $val = (int)$colorVal;
    $r = $val & 0xFF;
    $g = ($val >> 8) & 0xFF;
    $b = ($val >> 16) & 0xFF;
    return sprintf("#%02x%02x%02x", $r, $g, $b);
}

// Cautare produse dupa Denumire (optional: menu.php?search=...)
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
if ($search !== '') {
    // Escape caractere wildcard din LIKE
    $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
    $pattern = '%' . $esc . '%';

    $sqlSearch = "SELECT TOP 200 p.ProdID, p.NrGrp, p.Denumire, p.KP, p.Poz,
                         p.BackColor, p.FontColor, p.Bold,
                         COALESCE(p.PV, 0) as Pret
                  FROM tblProd p
                  WHERE p.Denumire LIKE ? ESCAPE '\\'
                  ORDER BY p.Denumire";
    $stmtSearch = sqlsrv_query($conn, $sqlSearch, [$pattern]);

    $results = [];
    if ($stmtSearch) {
        while ($s = sqlsrv_fetch_array($stmtSearch, SQLSRV_FETCH_ASSOC)) {
            $rawBg = ($s['BackColor'] !== null && $s['BackColor'] !== '') ? (int)$s['BackColor'] : null;
            $rawFg = ($s['FontColor'] !== null && $s['FontColor'] !== '') ? (int)$s['FontColor'] : null;

            $bgHex = null;
            $fgHex = null;
            if ($rawBg !== null) {
                $bgHex = winColorToHex($rawBg);
                if ($rawBg === 0) {
                    $fgHex = "#ffffff";
                }
            }
            if ($fgHex === null && $rawFg !== null && $rawFg > 0) {
                $fgHex = winColorToHex($rawFg);
            }

            $results[] = [
                "ProdID" => (int)$s['ProdID'],
                "Denumire" => trim($s['Denumire']),
                "Pret" => (float)$s['Pret'],
                "KP" => (int)$s['KP'],
                "Poz" => (int)$s['Poz'],
                "rawBackColor" => $rawBg,
                "BackColor" => $bgHex,
                "FontColor" => $fgHex,
                "Bold" => ($s['Bold'] == -1 || $s['Bold'] == 1)
            ];
        }
    }

    sendJsonResponse([
        "status" => "success",
        "query" => $search,
        "searchProducts" => $results
    ]);
}

// 1. Incarcam grupurile din tblGrp
$sqlGrp = "SELECT NrGrp, Denumire, BackColor, FontColor, FontSize, Bold, FontType, Poz, NrKp 
           FROM tblGrp 
           WHERE Denumire IS NOT NULL AND LTRIM(RTRIM(Denumire)) <> ''
           ORDER BY Poz, NrGrp";
$stmtGrp = sqlsrv_query($conn, $sqlGrp);

$groups = [];
while ($row = sqlsrv_fetch_array($stmtGrp, SQLSRV_FETCH_ASSOC)) {
    $isBold = ($row['Bold'] == -1 || $row['Bold'] == 1);
    $bg = ($row['BackColor'] !== null && $row['BackColor'] !== '') ? (int)$row['BackColor'] : null;
    $fg = ($row['FontColor'] !== null && $row['FontColor'] !== '') ? (int)$row['FontColor'] : null;

    $bgHex = null;
    $fgHex = null;

    if ($bg !== null) {
        $bgHex = winColorToHex($bg);
        if ($bg === 0) {
            $fgHex = "#ffffff";
        }
    }
    if ($fgHex === null && $fg !== null) {
        $fgHex = winColorToHex($fg);
    }

    $groups[] = [
        "NrGrp" => (int)$row['NrGrp'],
        "Denumire" => trim($row['Denumire']),
        "Poz" => (int)$row['Poz'],
        "BackColor" => $bgHex,
        "FontColor" => $fgHex,
        "FontSize" => ($row['FontSize'] !== null && $row['FontSize'] !== '') ? (int)$row['FontSize'] : null,
        "FontType" => $row['FontType'] !== null ? trim($row['FontType']) : '',
        "Bold" => $isBold
    ];
}

// 2. Incarcam produsele din tblProd cu preturile din tblProd.PV
$sqlProd = "SELECT p.ProdID, p.NrGrp, p.Denumire, p.BarCod, p.Poz, p.KP,
                   p.BackColor, p.FontColor, p.Bold, p.FontSize, p.FontType, p.MZ,
                   COALESCE(p.PV, 0) as Pret
            FROM tblProd p
            WHERE p.Denumire IS NOT NULL AND LTRIM(RTRIM(p.Denumire)) <> ''
            ORDER BY p.NrGrp, p.Poz, p.Denumire";
$stmtProd = sqlsrv_query($conn, $sqlProd);

$productsByGroup = [];
while ($p = sqlsrv_fetch_array($stmtProd, SQLSRV_FETCH_ASSOC)) {
    $grpId = (int)$p['NrGrp'];
    if (!isset($productsByGroup[$grpId])) {
        $productsByGroup[$grpId] = [];
    }

    $isBold = ($p['Bold'] == -1 || $p['Bold'] == 1);

    $rawBg = ($p['BackColor'] !== null && $p['BackColor'] !== '') ? (int)$p['BackColor'] : null;
    $rawFg = ($p['FontColor'] !== null && $p['FontColor'] !== '') ? (int)$p['FontColor'] : null;

    $bgHex = null;
    $fgHex = null;

    if ($rawBg !== null) {
        $bgHex = winColorToHex($rawBg);
        // Regula: daca BackColor este 0 (negru), FontColor devine alb (#ffffff)
        if ($rawBg === 0) {
            $fgHex = "#ffffff";
        }
    }

    if ($fgHex === null && $rawFg !== null && $rawFg > 0) {
        $fgHex = winColorToHex($rawFg);
    }

    $productsByGroup[$grpId][] = [
        "ProdID" => (int)$p['ProdID'],
        "Denumire" => trim($p['Denumire']),
        "BarCod" => trim($p['BarCod'] ?? ''),
        "Pret" => (float)$p['Pret'],
        "KP" => (int)$p['KP'],
        "Poz" => (int)$p['Poz'],
        "FontSize" => ($p['FontSize'] !== null && $p['FontSize'] !== '') ? (int)$p['FontSize'] : null,
        "FontType" => $p['FontType'] !== null ? trim($p['FontType']) : '',
        "rawBackColor" => $rawBg,
        "BackColor" => $bgHex,
        "FontColor" => $fgHex,
        "Bold" => $isBold,
        "MZ" => (int)($p['MZ'] ?? 0)
    ];
}

// 3. Incarcam modurile de preparare din tblMesaj (ordine alfabetica)
$sqlMsg = "SELECT NrMesaj, Mesaj
           FROM tblMesaj
           WHERE Mesaj IS NOT NULL AND LTRIM(RTRIM(Mesaj)) <> ''
           ORDER BY Mesaj";
$stmtMsg = sqlsrv_query($conn, $sqlMsg);

$messages = [];
if ($stmtMsg) {
    while ($m = sqlsrv_fetch_array($stmtMsg, SQLSRV_FETCH_ASSOC)) {
        $messages[] = [
            "NrMesaj" => (int)$m['NrMesaj'],
            "Mesaj" => trim($m['Mesaj'])
        ];
    }
}

// 4. Setarea MeniulZilei din tblSet (cheie/valoare)
$meniulZilei = 0;
$stmtMz = sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'MeniulZilei'");
if ($stmtMz) {
    $mz = sqlsrv_fetch_array($stmtMz, SQLSRV_FETCH_ASSOC);
    $meniulZilei = ($mz && (int)trim($mz['Value']) === 1) ? 1 : 0;
}

// 4b. Modul de logare a ospatarilor (tblSet.Mod_logare): 1 = ramane logat
//     pe toata sesiunea, 0 = delogare automata la iesirea de pe masa.
$modLogare = 1;
$stmtML = sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'Mod_logare'");
if ($stmtML) {
    $ml = sqlsrv_fetch_array($stmtML, SQLSRV_FETCH_ASSOC);
    $modLogare = ($ml && (int)trim($ml['Value']) === 0) ? 0 : 1;
}

// 4c. Tipul de vanzare (tblSet.TipVanz): 1 = FastFood, altfel Restaurant.
//     In FastFood nu se afiseaza ecranul de mese, comanda nu pleaca la sectie
//     si nu se tipareste nota de plata (doar bonul fiscal).
$tipVanz = "restaurant";
$stmtTV = sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'TipVanz'");
if ($stmtTV) {
    $tv = sqlsrv_fetch_array($stmtTV, SQLSRV_FETCH_ASSOC);
    if ($tv && (int)trim((string)$tv['Value']) === 1) {
        $tipVanz = "fastfood";
    }
}

// 4d. Cantitatea maxima admisa pe o linie de nota (tblSet.CantMax).
//     Protejeaza impotriva tastarii gresite a unei cantitati uriașe.
//     Implicit 1000 cand setarea lipseste sau este invalida.
$cantMax = 1000;
$stmtCM = sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'CantMax'");
if ($stmtCM) {
    $cm = sqlsrv_fetch_array($stmtCM, SQLSRV_FETCH_ASSOC);
    if ($cm && trim((string)$cm['Value']) !== '') {
        $cmVal = (float)str_replace(',', '.', trim((string)$cm['Value']));
        if ($cmVal > 0) { $cantMax = $cmVal; }
    }
}

// 4e. Numarul de zecimale pentru cantitate (tblSet.NrZecCant): 0, 1 sau 2.
//     Implicit 1 (o zecimala) cand setarea lipseste sau este invalida.
$nrZecCant = 1;
$stmtNZ = sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'NrZecCant'");
if ($stmtNZ) {
    $nz = sqlsrv_fetch_array($stmtNZ, SQLSRV_FETCH_ASSOC);
    if ($nz && trim((string)$nz['Value']) !== '') {
        $nzVal = (int)trim((string)$nz['Value']);
        if ($nzVal >= 0 && $nzVal <= 2) { $nrZecCant = $nzVal; }
    }
}

// 4f. Discountul permis/interzis (tblSet.RED): 1 = DA (permis), 0 = NU.
//     Implicit 1 cand setarea lipseste sau este invalida.
$red = 1;
$stmtRed = sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'RED'");
if ($stmtRed) {
    $rr = sqlsrv_fetch_array($stmtRed, SQLSRV_FETCH_ASSOC);
    if ($rr && trim((string)$rr['Value']) !== '') {
        $red = ((int)trim((string)$rr['Value']) === 0) ? 0 : 1;
    }
}

// 4g. Parola de discount (tblParola.ParolaDiscount): 1 daca este setata (deci
//     discountul cere parola), 0 daca nu. Valoarea parolei NU se trimite.
$parolaDiscount = 0;
$stmtPD = sqlsrv_query($conn, "SELECT TOP 1 ParolaDiscount FROM tblParola");
if ($stmtPD) {
    $pd = sqlsrv_fetch_array($stmtPD, SQLSRV_FETCH_ASSOC);
    if ($pd && trim((string)($pd['ParolaDiscount'] ?? '')) !== '') { $parolaDiscount = 1; }
}

// 4h. Parola de stornare (tblParola.ParolaStornare): 1 daca este setata (deci
//     anularea liniilor trimise cere parola si motiv), 0 daca nu. Valoarea NU se trimite.
$parolaStornare = 0;
$stmtPS = sqlsrv_query($conn, "SELECT TOP 1 ParolaStornare FROM tblParola");
if ($stmtPS) {
    $ps = sqlsrv_fetch_array($stmtPS, SQLSRV_FETCH_ASSOC);
    if ($ps && trim((string)($ps['ParolaStornare'] ?? '')) !== '') { $parolaStornare = 1; }
}

// 5. Date de contact pentru footer-ul ecranului mese (tblSet cheie/valoare)
$distrRand1 = '';
$distrRand2 = '';
$stmtDr = sqlsrv_query($conn, "SELECT Setting, Value FROM tblSet WHERE Setting IN ('DistrRand1','DistrRand2')");
if ($stmtDr) {
    while ($dr = sqlsrv_fetch_array($stmtDr, SQLSRV_FETCH_ASSOC)) {
        $key = strtolower(trim($dr['Setting']));
        if ($key === 'distrrand1') {
            $distrRand1 = trim($dr['Value'] ?? '');
        } elseif ($key === 'distrrand2') {
            $distrRand2 = trim($dr['Value'] ?? '');
        }
    }
}

sendJsonResponse([
    "status" => "success",
    "groups" => $groups,
    "productsByGroup" => $productsByGroup,
    "messages" => $messages,
    "meniulZilei" => $meniulZilei,
    "modLogare" => $modLogare,
    "tipVanz" => $tipVanz,
    "cantMax" => $cantMax,
    "nrZecCant" => $nrZecCant,
    "red" => $red,
    "parolaDiscount" => $parolaDiscount,
    "parolaStornare" => $parolaStornare,
    "distrRand1" => $distrRand1,
    "distrRand2" => $distrRand2
]);
