<?php
/**
 * API: Rapoarte (X / Z)
 *  GET  ?tab=X&tip=PLU   -> datele raportului (agregate)
 *  POST {action:'print', tab, tip} -> enqueue un job de tiparire 'raport'
 *
 * Sunt implementate rapoartele X - PLU (grupate pe sectii) si X - Grupe
 * (grupate pe grupele de produse). Datele vin din tblNoteD (sesiunea curenta,
 * golita la raportul Z).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/print_common.php';

/**
 * Construieste datele raportului PLU, grupate pe sectii, cu subtotal pe sectie.
 * Interogarea de baza este cea data pentru raportul PLU.
 */
function buildPluReport($conn) {
    $sql = "SELECT s.Denumire AS Sectiune, p.Sectie AS SectieId, d.ProdID AS ProdID, p.Denumire AS Produs,
                   ROUND(SUM(d.Cant), 2) AS Cant,
                   ROUND(SUM(d.Cant * d.PV), 2) AS Valoare
            FROM tblNoteD d
            INNER JOIN tblBonCurent b ON d.DocID = b.DocID
            INNER JOIN tblProd p ON d.ProdID = p.ProdID
            INNER JOIN tblSectii s ON p.Sectie = s.Sectie
            WHERE b.Stare = 'I'
            GROUP BY p.Sectie, s.Denumire, d.ProdID, p.Denumire
            ORDER BY s.Denumire, p.Denumire";
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        return null;
    }

    $sections = [];
    $totalCant = 0.0;
    $totalValoare = 0.0;

    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $key = (int)$r['SectieId'];
        if (!isset($sections[$key])) {
            $sections[$key] = [
                "sectie" => trim((string)$r['Sectiune']),
                "rows" => [],
                "subtotalCant" => 0.0,
                "subtotalValoare" => 0.0
            ];
        }
        $cant = (float)$r['Cant'];
        $valoare = (float)$r['Valoare'];

        $sections[$key]["rows"][] = [
            "prodId" => (int)$r['ProdID'],
            "produs" => trim((string)$r['Produs']),
            "cant" => round($cant, 2),
            "valoare" => round($valoare, 2)
        ];
        $sections[$key]["subtotalCant"] += $cant;
        $sections[$key]["subtotalValoare"] += $valoare;
        $totalCant += $cant;
        $totalValoare += $valoare;
    }

    foreach ($sections as $k => $sec) {
        $sections[$k]["subtotalCant"] = round($sec["subtotalCant"], 2);
        $sections[$k]["subtotalValoare"] = round($sec["subtotalValoare"], 2);
    }

    return [
        "sectii" => array_values($sections),
        "totalCant" => round($totalCant, 2),
        "totalValoare" => round($totalValoare, 2)
    ];
}

/**
 * Construieste datele raportului Grupe: incasarile din tblNoteD agregate pe
 * grupele de produse (tblGrp), listate in ordine alfabetica.
 */
function buildGrupeReport($conn) {
    $sql = "SELECT g.NrGrp AS GrupaId, g.Denumire AS Grupa,
                   ROUND(SUM(d.Cant * d.PV), 2) AS Valoare
            FROM tblNoteD d
            INNER JOIN tblBonCurent b ON d.DocID = b.DocID
            INNER JOIN tblProd p ON d.ProdID = p.ProdID
            INNER JOIN tblGrp g ON p.NrGrp = g.NrGrp
            WHERE b.Stare = 'I'
            GROUP BY g.NrGrp, g.Denumire
            ORDER BY g.Denumire";
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        return null;
    }

    $groups = [];
    $totalValoare = 0.0;

    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $valoare = (float)$r['Valoare'];

        $groups[] = [
            "grupaId" => (int)$r['GrupaId'],
            "grupa" => trim((string)$r['Grupa']),
            "valoare" => round($valoare, 2)
        ];
        $totalValoare += $valoare;
    }

    return [
        "grupe" => $groups,
        "totalValoare" => round($totalValoare, 2)
    ];
}

/**
 * Construieste datele raportului Sectii: incasarile din tblNoteD agregate pe
 * sectiile de produse (tblSectii), listate in ordine alfabetica.
 */
function buildSectiiReport($conn) {
    $sql = "SELECT s.Sectie AS SectieId, s.Denumire AS Sectie,
                   ROUND(SUM(d.Cant * d.PV), 2) AS Valoare
            FROM tblNoteD d
            INNER JOIN tblBonCurent b ON d.DocID = b.DocID
            INNER JOIN tblProd p ON d.ProdID = p.ProdID
            INNER JOIN tblSectii s ON p.Sectie = s.Sectie
            WHERE b.Stare = 'I'
            GROUP BY s.Sectie, s.Denumire
            ORDER BY s.Denumire";
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        return null;
    }

    $sections = [];
    $totalValoare = 0.0;

    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $valoare = (float)$r['Valoare'];

        $sections[] = [
            "sectieId" => (int)$r['SectieId'],
            "sectie" => trim((string)$r['Sectie']),
            "valoare" => round($valoare, 2)
        ];
        $totalValoare += $valoare;
    }

    return [
        "sectii" => $sections,
        "totalValoare" => round($totalValoare, 2)
    ];
}

/**
 * Construieste datele raportului Casieri: pentru fiecare casier (tblBonCurent.NrOp
 * -> tblOsp.NrOsp) numarul de bonuri, incasarile pe forme de plata (trelDocIDFpID
 * -> tblFP), reducerea acordata si valoarea stornarilor, calculate din tblNoteD.
 */
function buildCasieriReport($conn) {
    $sql = "SELECT b.NrOp AS NrOp, COALESCE(o.Nume, 'CASIER 1') AS Casier,
                   COUNT(DISTINCT d.DocID) AS NrBonuri,
                   ROUND(SUM(d.Cant * d.PV), 2) AS Total,
                   ROUND(SUM(CASE WHEN d.Cant > 0 THEN d.Cant * (COALESCE(d.PVC, d.PV) - d.PV) ELSE 0 END), 2) AS Reducere,
                   ROUND(SUM(CASE WHEN d.Cant < 0 THEN -d.Cant * d.PV ELSE 0 END), 2) AS Stornari
            FROM tblNoteD d
            INNER JOIN tblBonCurent b ON d.DocID = b.DocID
            LEFT JOIN tblOsp o ON b.NrOp = o.NrOsp
            WHERE b.Stare = 'I'
            GROUP BY b.NrOp, o.Nume
            ORDER BY o.Nume";
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        return null;
    }

    $casieri = [];
    $order = [];
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $key = (string)$r['NrOp'];
        $casieri[$key] = [
            "casier" => trim((string)$r['Casier']),
            "nrBonuri" => (int)$r['NrBonuri'],
            "reducere" => round((float)$r['Reducere'], 2),
            "stornari" => round((float)$r['Stornari'], 2),
            "plati" => [],
            "total" => round((float)$r['Total'], 2)
        ];
        $order[] = $key;
    }

    // Incasarile pe forme de plata, pentru bonurile din sesiunea curenta.
    $sqlPlati = "SELECT b.NrOp AS NrOp, f.FPID AS FPID, f.Denumire AS Denumire,
                        ROUND(SUM(p.Suma), 2) AS Suma
                 FROM trelDocIDFpID p
                 INNER JOIN tblBonCurent b ON p.DocID = b.DocID
                 INNER JOIN tblFP f ON p.FPID = f.FPID
                 WHERE b.Stare = 'I'
                   AND p.DocID IN (SELECT DISTINCT DocID FROM tblNoteD)
                 GROUP BY b.NrOp, f.FPID, f.Denumire, f.Poz
                 ORDER BY f.Poz, f.Denumire";
    $stmtPlati = sqlsrv_query($conn, $sqlPlati);
    if ($stmtPlati === false) {
        return null;
    }

    $totalPlati = [];
    $totalPlatiOrder = [];
    while ($r = sqlsrv_fetch_array($stmtPlati, SQLSRV_FETCH_ASSOC)) {
        $key = (string)$r['NrOp'];
        $suma = round((float)$r['Suma'], 2);
        if (!isset($casieri[$key])) {
            continue;
        }
        $casieri[$key]["plati"][] = [
            "fpid" => (int)$r['FPID'],
            "denumire" => trim((string)$r['Denumire']),
            "suma" => $suma
        ];

        $fpKey = (string)$r['FPID'];
        if (!isset($totalPlati[$fpKey])) {
            $totalPlati[$fpKey] = ["denumire" => trim((string)$r['Denumire']), "suma" => 0.0];
            $totalPlatiOrder[] = $fpKey;
        }
        $totalPlati[$fpKey]["suma"] += $suma;
    }

    $totalBonuri = 0;
    $totalReducere = 0.0;
    $totalStornari = 0.0;
    $totalValoare = 0.0;
    $out = [];
    foreach ($order as $key) {
        $c = $casieri[$key];
        $c["total"] = round($c["total"], 2);
        $totalBonuri += $c["nrBonuri"];
        $totalReducere += $c["reducere"];
        $totalStornari += $c["stornari"];
        $totalValoare += $c["total"];
        $out[] = $c;
    }

    $totalPlatiOut = [];
    foreach ($totalPlatiOrder as $fpKey) {
        $totalPlatiOut[] = [
            "fpid" => (int)$fpKey,
            "denumire" => $totalPlati[$fpKey]["denumire"],
            "suma" => round($totalPlati[$fpKey]["suma"], 2)
        ];
    }

    return [
        "casieri" => $out,
        "totalBonuri" => $totalBonuri,
        "totalReducere" => round($totalReducere, 2),
        "totalStornari" => round($totalStornari, 2),
        "totalValoare" => round($totalValoare, 2),
        "totalPlati" => $totalPlatiOut
    ];
}

/**
 * Construieste datele raportului General: total general defalcat pe forme de
 * plata, TVA defalcat pe cote, total bonuri, discount, stornari si lista notelor.
 */
function buildGeneralReport($conn) {
    // 1. Totaluri generale (doar bonuri inchise).
    $sqlTot = "SELECT COUNT(DISTINCT d.DocID) AS NrBonuri,
                      ROUND(SUM(d.Cant * d.PV), 2) AS Total,
                      ROUND(SUM(CASE WHEN d.Cant > 0 THEN d.Cant * (COALESCE(d.PVC, d.PV) - d.PV) ELSE 0 END), 2) AS Discount,
                      ROUND(SUM(CASE WHEN d.Cant < 0 THEN -d.Cant * d.PV ELSE 0 END), 2) AS Stornari
               FROM tblNoteD d
               INNER JOIN tblBonCurent b ON d.DocID = b.DocID
               WHERE b.Stare = 'I'";
    $stmtTot = sqlsrv_query($conn, $sqlTot);
    if ($stmtTot === false) {
        return null;
    }
    $tot = sqlsrv_fetch_array($stmtTot, SQLSRV_FETCH_ASSOC);
    $totalBonuri = (int)($tot['NrBonuri'] ?? 0);
    $totalValoare = round((float)($tot['Total'] ?? 0), 2);
    $totalDiscount = round((float)($tot['Discount'] ?? 0), 2);
    $totalStornari = round((float)($tot['Stornari'] ?? 0), 2);

    // 2. TVA defalcat pe cote (PV include TVA).
    $sqlTva = "SELECT COALESCE(d.TVAc, 0) AS Cota,
                      ROUND(SUM(d.Cant * d.PV), 2) AS Total
               FROM tblNoteD d
               INNER JOIN tblBonCurent b ON d.DocID = b.DocID
               WHERE b.Stare = 'I'
               GROUP BY COALESCE(d.TVAc, 0)
               ORDER BY COALESCE(d.TVAc, 0)";
    $stmtTva = sqlsrv_query($conn, $sqlTva);
    if ($stmtTva === false) {
        return null;
    }
    $tva = [];
    while ($r = sqlsrv_fetch_array($stmtTva, SQLSRV_FETCH_ASSOC)) {
        $cota = (float)$r['Cota'];
        $totalCota = round((float)$r['Total'], 2);
        $baza = ($cota > 0) ? $totalCota / (1 + $cota / 100) : $totalCota;
        $tva[] = [
            "cota" => $cota,
            "total" => $totalCota,
            "baza" => round($baza, 2),
            "suma" => round($totalCota - $baza, 2)
        ];
    }

    // 3. Total general defalcat pe forme de plata (doar bonuri inchise din sesiune).
    $sqlPlati = "SELECT f.FPID AS FPID, f.Denumire AS Denumire,
                        ROUND(SUM(p.Suma), 2) AS Suma
                 FROM trelDocIDFpID p
                 INNER JOIN tblBonCurent b ON p.DocID = b.DocID
                 INNER JOIN tblFP f ON p.FPID = f.FPID
                 WHERE b.Stare = 'I'
                   AND p.DocID IN (SELECT DISTINCT DocID FROM tblNoteD)
                 GROUP BY f.FPID, f.Denumire, f.Poz
                 ORDER BY f.Poz, f.Denumire";
    $stmtPlati = sqlsrv_query($conn, $sqlPlati);
    if ($stmtPlati === false) {
        return null;
    }
    $plati = [];
    while ($r = sqlsrv_fetch_array($stmtPlati, SQLSRV_FETCH_ASSOC)) {
        $plati[] = [
            "fpid" => (int)$r['FPID'],
            "denumire" => trim((string)$r['Denumire']),
            "suma" => round((float)$r['Suma'], 2)
        ];
    }

    // 4. Lista notelor: NrNota, NrMasa, Ospatar, TotalNota.
    $sqlNote = "SELECT b.NrDoc AS NrNota, b.NrMasa AS NrMasa,
                       COALESCE(o.Nume, 'CASIER 1') AS Ospatar,
                       ROUND(SUM(d.Cant * d.PV), 2) AS TotalNota
                FROM tblNoteD d
                INNER JOIN tblBonCurent b ON d.DocID = b.DocID
                LEFT JOIN tblOsp o ON b.NrOp = o.NrOsp
                WHERE b.Stare = 'I'
                GROUP BY b.DocID, b.NrDoc, b.NrMasa, o.Nume
                ORDER BY b.NrDoc";
    $stmtNote = sqlsrv_query($conn, $sqlNote);
    if ($stmtNote === false) {
        return null;
    }
    $note = [];
    while ($r = sqlsrv_fetch_array($stmtNote, SQLSRV_FETCH_ASSOC)) {
        $note[] = [
            "nrNota" => (int)$r['NrNota'],
            "nrMasa" => (int)$r['NrMasa'],
            "ospatar" => trim((string)$r['Ospatar']),
            "total" => round((float)$r['TotalNota'], 2)
        ];
    }

    return [
        "totalBonuri" => $totalBonuri,
        "totalDiscount" => $totalDiscount,
        "totalStornari" => $totalStornari,
        "totalValoare" => $totalValoare,
        "plati" => $plati,
        "tva" => $tva,
        "note" => $note
    ];
}

/**
 * Aliniaza stanga/dreapta pe latimea bonului (aproximare pe lungime caractere).
 */
function reportLr($left, $right, $width = 42) {
    $left = (string)$left;
    $right = (string)$right;
    $space = $width - strlen($left) - strlen($right);
    if ($space < 1) {
        $maxLeft = $width - strlen($right) - 1;
        $left = $maxLeft > 0 ? substr($left, 0, $maxLeft) : '';
        $space = max(1, $width - strlen($left) - strlen($right));
    }
    return $left . str_repeat(' ', $space) . $right;
}

/**
 * Construieste liniile de text pentru un raport grupat (sectii / grupe).
 * $labelKey este cheia din fiecare grupa ce contine denumirea (sectie/grupa).
 */
function buildGroupedReportLines($groups, $generat, $labelKey) {
    $width = 42;
    $lines = [];
    $lines[] = "Generat: " . $generat;
    $lines[] = str_repeat('-', $width);

    foreach ($groups as $grp) {
        $label = $grp[$labelKey];
        $lines[] = strtoupper($label);
        foreach ($grp["rows"] as $row) {
            $cant = number_format($row["cant"], 2, '.', '');
            $val = number_format($row["valoare"], 2, '.', '');
            $right = str_pad($cant, 8, ' ', STR_PAD_LEFT) . str_pad($val, 10, ' ', STR_PAD_LEFT);
            $lines[] = reportLr($row["produs"], $right, $width);
        }
        $subCant = number_format($grp["subtotalCant"], 2, '.', '');
        $subVal = number_format($grp["subtotalValoare"], 2, '.', '');
        $subRight = str_pad($subCant, 8, ' ', STR_PAD_LEFT) . str_pad($subVal, 10, ' ', STR_PAD_LEFT);
        $lines[] = reportLr("Subtotal " . $label, $subRight, $width);
        $lines[] = str_repeat('-', $width);
    }

    $tCant = 0.0;
    $tVal = 0.0;
    foreach ($groups as $grp) {
        $tCant += $grp["subtotalCant"];
        $tVal += $grp["subtotalValoare"];
    }
    $tCantStr = number_format($tCant, 2, '.', '');
    $tValStr = number_format($tVal, 2, '.', '');
    $tRight = str_pad($tCantStr, 8, ' ', STR_PAD_LEFT) . str_pad($tValStr, 10, ' ', STR_PAD_LEFT);
    $lines[] = ["text" => reportLr("TOTAL", $tRight, $width), "bold" => true];

    return $lines;
}

/**
 * Construieste liniile de text pentru tiparirea raportului PLU.
 */
function buildPluReportLines($data, $generat) {
    return buildGroupedReportLines($data["sectii"], $generat, "sectie");
}

/**
 * Construieste liniile de text pentru un raport de tip lista (grupe / sectii):
 * doar denumire + valoare, in ordinea primita.
 */
function buildSimpleReportLines($items, $labelKey, $totalValoare, $generat) {
    $width = 42;
    $lines = [];
    $lines[] = "Generat: " . $generat;
    $lines[] = str_repeat('-', $width);

    foreach ($items as $it) {
        $val = number_format($it["valoare"], 2, '.', '');
        $right = str_pad($val, 12, ' ', STR_PAD_LEFT);
        $lines[] = reportLr($it[$labelKey], $right, $width);
    }

    $lines[] = str_repeat('-', $width);
    $tVal = number_format($totalValoare, 2, '.', '');
    $tRight = str_pad($tVal, 12, ' ', STR_PAD_LEFT);
    $lines[] = ["text" => reportLr("TOTAL", $tRight, $width), "bold" => true];

    return $lines;
}

/**
 * Construieste liniile de text pentru tiparirea raportului Grupe (doar grupe,
 * in ordine alfabetica, fara detalierea produselor).
 */
function buildGrupeReportLines($data, $generat) {
    return buildSimpleReportLines($data["grupe"], "grupa", $data["totalValoare"], $generat);
}

/**
 * Construieste liniile de text pentru tiparirea raportului Sectii (doar sectii,
 * in ordine alfabetica, fara detalierea produselor).
 */
function buildSectiiReportLines($data, $generat) {
    return buildSimpleReportLines($data["sectii"], "sectie", $data["totalValoare"], $generat);
}

/**
 * Construieste liniile de text pentru raportul Casieri (bonuri, forme de plata,
 * reducere si stornari pentru fiecare casier).
 */
function buildCasieriReportLines($data, $generat) {
    $width = 42;
    $lines = [];
    $lines[] = "Generat: " . $generat;
    $lines[] = str_repeat('-', $width);

    foreach ($data["casieri"] as $c) {
        $lines[] = strtoupper($c["casier"]);
        $lines[] = reportLr("  Bonuri:", (string)$c["nrBonuri"], $width);
        $lines[] = reportLr("  Reducere:", number_format($c["reducere"], 2, '.', ''), $width);
        $lines[] = reportLr("  Stornari:", number_format($c["stornari"], 2, '.', ''), $width);
        foreach ($c["plati"] as $p) {
            $lines[] = reportLr("  " . $p["denumire"], number_format($p["suma"], 2, '.', ''), $width);
        }
        $lines[] = reportLr("  Total casier", number_format($c["total"], 2, '.', ''), $width);
        $lines[] = str_repeat('-', $width);
    }

    $lines[] = strtoupper("TOTAL");
    $lines[] = reportLr("  Bonuri:", (string)$data["totalBonuri"], $width);
    $lines[] = reportLr("  Reducere:", number_format($data["totalReducere"], 2, '.', ''), $width);
    $lines[] = reportLr("  Stornari:", number_format($data["totalStornari"], 2, '.', ''), $width);
    foreach ($data["totalPlati"] as $p) {
        $lines[] = reportLr("  " . $p["denumire"], number_format($p["suma"], 2, '.', ''), $width);
    }
    $lines[] = ["text" => reportLr("  TOTAL", number_format($data["totalValoare"], 2, '.', ''), $width), "bold" => true];

    return $lines;
}

/**
 * Construieste liniile de text pentru raportul General: totaluri, forme de plata,
 * TVA pe cote si lista notelor.
 */
function buildGeneralReportLines($data, $generat) {
    $width = 42;
    $lines = [];
    $lines[] = "Generat: " . $generat;
    $lines[] = str_repeat('-', $width);

    $lines[] = reportLr("Bonuri:", (string)$data["totalBonuri"], $width);
    $lines[] = reportLr("Discount:", number_format($data["totalDiscount"], 2, '.', ''), $width);
    $lines[] = reportLr("Stornari:", number_format($data["totalStornari"], 2, '.', ''), $width);

    $lines[] = str_repeat('-', $width);
    $lines[] = "Forme de plata:";
    foreach ($data["plati"] as $p) {
        $lines[] = reportLr("  " . $p["denumire"], number_format($p["suma"], 2, '.', ''), $width);
    }

    $lines[] = str_repeat('-', $width);
    $lines[] = "TVA pe cote:";
    foreach ($data["tva"] as $t) {
        $cota = rtrim(rtrim(number_format($t["cota"], 2, '.', ''), '0'), '.');
        $lines[] = "  Cota " . $cota . "%";
        $lines[] = reportLr("    Baza", number_format($t["baza"], 2, '.', ''), $width);
        $lines[] = reportLr("    TVA", number_format($t["suma"], 2, '.', ''), $width);
    }

    $lines[] = str_repeat('-', $width);
    $lines[] = "Lista note:";
    // Cap de tabel: NrNota(7) NrMasa(7) Ospatar(16) Total(12) = 42 caractere.
    $lines[] = str_pad("NrNota", 7)
             . str_pad("NrMasa", 7)
             . str_pad("Ospatar", 16)
             . str_pad("Total", 12, ' ', STR_PAD_LEFT);
    $lines[] = str_repeat('-', $width);
    foreach ($data["note"] as $n) {
        $lines[] = str_pad((string)$n["nrNota"], 7)
                 . str_pad((string)$n["nrMasa"], 7)
                 . str_pad(substr($n["ospatar"], 0, 16), 16)
                 . str_pad(number_format($n["total"], 2, '.', ''), 12, ' ', STR_PAD_LEFT);
    }

    $lines[] = str_repeat('-', $width);
    $lines[] = ["text" => reportLr("TOTAL", number_format($data["totalValoare"], 2, '.', ''), $width), "bold" => true];

    return $lines;
}

/**
 * Titlul raportului.
 */
function reportTitle($tab, $tip) {
    return "RAPORT " . strtoupper($tab) . " - " . strtoupper($tip);
}

/**
 * Antetul rapoartelor = liniile H1-H2 din tblAntet, cu font/size/bold pastrate
 * pentru tiparire (aceeasi conventie ca la nota).
 */
function buildReportHeaderLines($conn) {
    $headerLines = [];
    $stmt = sqlsrv_query($conn, "SELECT Seria, Nume, NumeFont, Size, Bold
                                 FROM tblAntet WHERE Seria IN ('H1','H2')");
    if ($stmt) {
        $tmp = [];
        while ($h = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $nume = trim((string)($h['Nume'] ?? ''));
            if ($nume === '') { continue; }
            $sizeRaw = $h['Size'];
            $tmp[trim($h['Seria'])] = [
                "text" => $nume,
                "font" => trim((string)($h['NumeFont'] ?? '')),
                "size" => ($sizeRaw === null || $sizeRaw === '') ? 0 : (int)$sizeRaw,
                "bold" => ((int)$h['Bold'] !== 0)
            ];
        }
        foreach (['H1', 'H2'] as $k) { if (!empty($tmp[$k])) { $headerLines[] = $tmp[$k]; } }
    }
    return $headerLines;
}

/**
 * Construieste datele unui raport dupa tip.
 * Intoarce ['key' => cheia listei de grupe, 'data' => datele] sau null la eroare.
 * Intoarce false daca tipul nu este inca implementat.
 */
function buildReportData($conn, $tip) {
    if ($tip === 'PLU') {
        $d = buildPluReport($conn);
        return $d === null ? null : ["key" => "sectii", "data" => $d];
    }
    if ($tip === 'GRUPE') {
        $d = buildGrupeReport($conn);
        return $d === null ? null : ["key" => "grupe", "data" => $d];
    }
    if ($tip === 'SECTII') {
        $d = buildSectiiReport($conn);
        return $d === null ? null : ["key" => "sectii", "data" => $d];
    }
    if ($tip === 'CASIERI') {
        $d = buildCasieriReport($conn);
        return $d === null ? null : ["key" => "casieri", "data" => $d];
    }
    if ($tip === 'GENERAL') {
        $d = buildGeneralReport($conn);
        return $d === null ? null : ["key" => "note", "data" => $d];
    }
    return false;
}

/**
 * Se asigura ca procedura stocata dbo.RealizeazaZ exista (o creeaza din
 * api/sql/z_procedure.sql daca lipseste). Procedura face arhivarea + golirea
 * Z atomic (o singura tranzactie cu rollback).
 */
function ensureZProcedure($conn) {
    $stmt = sqlsrv_query($conn, "SELECT OBJECT_ID('dbo.RealizeazaZ', 'P') AS OID");
    if ($stmt !== false) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if (!empty($row['OID'])) {
            return true;
        }
    }
    $file = __DIR__ . '/sql/z_procedure.sql';
    if (!is_readable($file)) {
        return false;
    }
    $sql = file_get_contents($file);
    // CREATE OR ALTER PROCEDURE trebuie sa fie prima instructiune din batch;
    // comentariile din capul fisierului sunt permise.
    $res = sqlsrv_query($conn, $sql);
    return ($res !== false);
}

/**
 * Verifica daca toate mesele sunt inchise (niciun bon cu Stare <> 'I').
 * Intoarce ['allowed' => bool, 'message' => string, 'nextZ' => int].
 */
function checkZAllowed($conn) {
    $open = 0;
    $stmt = sqlsrv_query($conn, "SELECT COUNT(*) AS c FROM tblBonCurent WHERE Stare IS NULL OR Stare <> 'I'");
    if ($stmt !== false) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $open = (int)($row['c'] ?? 0);
    }
    $nextZ = 1;
    $stmtZ = sqlsrv_query($conn, "SELECT ISNULL(MAX(NrZ), 0) + 1 AS NextZ FROM tblNrZ");
    if ($stmtZ !== false) {
        $rowZ = sqlsrv_fetch_array($stmtZ, SQLSRV_FETCH_ASSOC);
        $nextZ = (int)($rowZ['NextZ'] ?? 1);
    }
    return [
        "allowed" => ($open === 0),
        "message" => ($open === 0)
            ? "Toate mesele sunt inchise. Se poate efectua raportul Z."
            : "Raportul Z nu poate fi efectuat: exista mese deschise.",
        "nextZ" => $nextZ
    ];
}

/**
 * Construieste liniile raportului Z: General Z urmat de rapoartele bifate
 * (PLU / Grupe / Sectii / Casieri). Datele trebuie citite INAINTE de golire.
 */
function buildZReportLines($conn, $generat, $tipuri) {
    $width = 42;
    $general = buildGeneralReport($conn);
    if ($general === null) {
        return null;
    }
    $lines = buildGeneralReportLines($general, $generat);

    foreach ($tipuri as $tip) {
        $built = buildReportData($conn, $tip);
        if ($built === false || $built === null) {
            continue;
        }
        if ($tip === 'PLU') {
            $body = buildPluReportLines($built["data"], $generat);
        } elseif ($tip === 'GRUPE') {
            $body = buildGrupeReportLines($built["data"], $generat);
        } elseif ($tip === 'SECTII') {
            $body = buildSectiiReportLines($built["data"], $generat);
        } elseif ($tip === 'CASIERI') {
            $body = buildCasieriReportLines($built["data"], $generat);
        } else {
            continue;
        }
        // Scoatem primele doua linii (Generat + separator) din corpul sub-raportului.
        $body = array_slice($body, 2);
        $lines[] = str_repeat('=', $width);
        $lines[] = ["text" => "RAPORT Z - " . $tip, "bold" => true];
        $lines[] = str_repeat('=', $width);
        $lines = array_merge($lines, $body);
    }

    return $lines;
}

$conn = getDBConnection();

$isGet = ($_SERVER['REQUEST_METHOD'] === 'GET');

if ($isGet) {
    // Verificare stare Z (tab=Z&check=1): mese inchise + urmatorul NrZ.
    if (strtoupper(trim($_GET['tab'] ?? '')) === 'Z' && !empty($_GET['check'])) {
        $info = checkZAllowed($conn);
        sendJsonResponse(["status" => "success"] + $info);
    }

    $tab = strtoupper(trim($_GET['tab'] ?? 'X'));
    $tip = strtoupper(trim($_GET['tip'] ?? 'PLU'));

    $built = buildReportData($conn, $tip);
    if ($built === false) {
        sendJsonResponse(["status" => "error", "message" => "Raportul $tip nu este inca implementat"], 501);
    }
    if ($built === null) {
        sendJsonResponse(["status" => "error", "message" => "Eroare interogare raport: " . sqlsrv_errors()[0]['message']], 500);
    }

    $response = [
        "status" => "success",
        "tab" => $tab,
        "tip" => $tip,
        "title" => reportTitle($tab, $tip),
        "generat" => date('d-m-Y H:i')
    ];
    // Toate campurile raportului (lista + totaluri) ajung in raspuns.
    foreach ($built["data"] as $k => $v) {
        $response[$k] = $v;
    }
    sendJsonResponse($response);
}

// ---- POST ---------------------------------------------------------------
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input || !is_array($input)) {
    $input = $_POST;
}

$action = $input['action'] ?? '';

if ($action === 'print') {
    $tab = strtoupper(trim($input['tab'] ?? 'X'));
    $tip = strtoupper(trim($input['tip'] ?? 'PLU'));

    $built = buildReportData($conn, $tip);
    if ($built === false) {
        sendJsonResponse(["status" => "error", "message" => "Raportul $tip nu este inca implementat"], 501);
    }
    if ($built === null) {
        sendJsonResponse(["status" => "error", "message" => "Eroare interogare raport: " . sqlsrv_errors()[0]['message']], 500);
    }

    $title = reportTitle($tab, $tip);
    $generat = date('d-m-Y H:i');
    if ($tip === 'GRUPE') {
        $lines = buildGrupeReportLines($built["data"], $generat);
    } elseif ($tip === 'SECTII') {
        $lines = buildSectiiReportLines($built["data"], $generat);
    } elseif ($tip === 'CASIERI') {
        $lines = buildCasieriReportLines($built["data"], $generat);
    } elseif ($tip === 'GENERAL') {
        $lines = buildGeneralReportLines($built["data"], $generat);
    } else {
        $lines = buildPluReportLines($built["data"], $generat);
    }

    ensurePrintQueueTable($conn);
    $jobId = enqueuePrintJob($conn, 'raport', null, null, [
        "title" => $title,
        "generat" => $generat,
        "headerLines" => buildReportHeaderLines($conn),
        "lines" => $lines
    ]);
    if ($jobId === false) {
        sendJsonResponse(["status" => "error", "message" => "Eroare inscriere in coada: " . sqlsrv_errors()[0]['message']], 500);
    }

    wakePrintService();
    sendJsonResponse(["status" => "success", "message" => "Raport trimis la tiparire", "job" => $jobId]);
}

if ($action === 'z') {
    $valid = ['PLU', 'GRUPE', 'SECTII', 'CASIERI'];
    $tipuri = [];
    foreach ((array)($input['tipuri'] ?? []) as $t) {
        $t = strtoupper(trim((string)$t));
        if (in_array($t, $valid, true) && !in_array($t, $tipuri, true)) {
            $tipuri[] = $t;
        }
    }

    if (!ensureZProcedure($conn)) {
        $err = sqlsrv_errors();
        sendJsonResponse(["status" => "error", "message" => "Nu pot crea procedura stocata dbo.RealizeazaZ: " . ($err[0]['message'] ?? 'eroare necunoscuta')], 500);
    }

    $info = checkZAllowed($conn);
    if (!$info["allowed"]) {
        sendJsonResponse(["status" => "error", "message" => $info["message"]], 400);
    }

    // Raportul Z se construieste din datele curente, INAINTE de golire.
    $generat = date('d-m-Y H:i');
    $lines = buildZReportLines($conn, $generat, $tipuri);
    if ($lines === null) {
        $err = sqlsrv_errors();
        sendJsonResponse(["status" => "error", "message" => "Eroare la generarea raportului Z: " . ($err[0]['message'] ?? 'eroare necunoscuta')], 500);
    }

    // Arhivare + golire atomica (procedura stocata: totul sau nimic).
    $stmt = sqlsrv_query($conn, "{CALL dbo.RealizeazaZ}");
    if ($stmt === false) {
        $err = sqlsrv_errors();
        sendJsonResponse(["status" => "error", "message" => "Raportul Z a esuat (rollback, datele au ramas intacte): " . ($err[0]['message'] ?? 'eroare necunoscuta')], 500);
    }
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $nrZ = (int)($row['NrZ'] ?? 0);

    // Tiparirea raportului Z, dupa golire (datele sunt deja in memorie).
    ensurePrintQueueTable($conn);
    $jobId = enqueuePrintJob($conn, 'raport', null, null, [
        "title" => "RAPORT Z - GENERAL",
        "generat" => $generat,
        "headerLines" => buildReportHeaderLines($conn),
        "lines" => $lines
    ]);
    wakePrintService();

    $message = "Raport Z nr. " . $nrZ . " efectuat.";
    if ($jobId === false) {
        $message .= " Atentie: raportul nu a putut fi trimis la tiparire.";
    }
    sendJsonResponse(["status" => "success", "message" => $message, "nrZ" => $nrZ, "job" => $jobId]);
}

sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
