<?php
/**
 * API: Operatii pe comanda (Adaugare produs, modificare cantitate, cook, inchidere)
 * Compatibil MSSQL Rual (ECRID si DocID coloane IDENTITY)
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/print_common.php';

/**
 * Tipul de vanzare configurat in tblSet (TipVanz): "fastfood" cand valoarea
 * este 1, altfel "restaurant". In modul FastFood comanda nu se mai trimite la
 * sectie, iar la inchiderea notei se tipareste doar bonul fiscal (fara nota).
 */
function getTipVanz($conn) {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = "restaurant";
    $stmt = sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'TipVanz'");
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row && (int)trim((string)$row['Value']) === 1) {
            $cache = "fastfood";
        }
    }
    return $cache;
}

/**
 * Cantitatea maxima admisa pe o linie de nota (tblSet.CantMax). Implicit 1000
 * cand setarea lipseste sau este invalida. Protejeaza impotriva tastarii gresite.
 */
function getCantMax($conn) {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = 1000.0;
    $stmt = sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'CantMax'");
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row && trim((string)$row['Value']) !== '') {
            $v = (float)str_replace(',', '.', trim((string)$row['Value']));
            if ($v > 0) { $cache = $v; }
        }
    }
    return $cache;
}

/**
 * Numarul de zecimale pentru cantitate (tblSet.NrZecCant): 0, 1 sau 2.
 * Implicit 1 cand setarea lipseste sau este invalida.
 */
function getNrZecCant($conn) {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = 1;
    $stmt = sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'NrZecCant'");
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row && trim((string)$row['Value']) !== '') {
            $v = (int)trim((string)$row['Value']);
            if ($v >= 0 && $v <= 2) { $cache = $v; }
        }
    }
    return $cache;
}

/**
 * Discountul permis/interzis (tblSet.RED): 1 = permis, 0 = interzis.
 * Implicit 1 cand setarea lipseste sau este invalida.
 */
function getRed($conn) {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = 1;
    $stmt = sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'RED'");
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row && trim((string)$row['Value']) !== '') {
            $cache = ((int)trim((string)$row['Value']) === 0) ? 0 : 1;
        }
    }
    return $cache;
}

/**
 * Parola de discount din tblParola.ParolaDiscount (string; '' = nu se cere).
 */
function getDiscountParola($conn) {
    $stmt = @sqlsrv_query($conn, "SELECT TOP 1 ParolaDiscount FROM tblParola");
    $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    return $row ? trim((string)($row['ParolaDiscount'] ?? '')) : '';
}

/**
 * Repartizeaza o suma (in centi) pe o lista de ponderi (in centi), folosind
 * metoda celui mai mare rest, astfel incat suma alocata sa fie exact $amount
 * si nicio alocare sa nu depaseasca ponderea corespunzatoare.
 */
function distributeDiscountCents($amount, $weights) {
    $result = [];
    foreach ($weights as $k => $w) { $result[$k] = 0; }

    $sumW = 0;
    foreach ($weights as $w) { $sumW += $w; }
    if ($amount <= 0 || $sumW <= 0) { return $result; }
    if ($amount > $sumW) { $amount = $sumW; }

    $allocated = 0;
    $fractions = [];
    foreach ($weights as $k => $w) {
        $exact = $amount * $w / $sumW;
        $base = (int)floor($exact);
        $result[$k] = $base;
        $allocated += $base;
        $fractions[$k] = $exact - $base;
    }

    $remainder = $amount - $allocated;
    arsort($fractions);
    foreach ($fractions as $k => $f) {
        if ($remainder <= 0) { break; }
        $result[$k]++;
        $remainder--;
    }
    return $result;
}

/**
 * Trimite la bucatarie liniile notei inca netrimise (Preluat = 0).
 *
 * Liniile se grupeaza pe imprimanta destinației (tblProd.KP -> tblKP.NrLogic);
 * modurile de preparare se ataseaza produsului care le precede. Pentru fiecare
 * imprimanta se scrie un job in coada durabila (tblPrintQueue), apoi liniile
 * se marcheaza Preluat = 1 - totul intr-o singura tranzactie, ca sa nu se poata
 * pierde o comanda daca serviciul de tiparire e oprit.
 *
 * Intoarce ["jobs" => nr joburi, "lines" => nr linii, "error" => mesaj|null].
 */
function printKitchen($conn, $docId) {
    $docId = (int)$docId;
    if ($docId <= 0) {
        return ["jobs" => 0, "lines" => 0, "error" => "DocID invalid"];
    }

    // Mod FastFood: comanda nu se trimite la sectie (nimic nu se marcheaza Preluat).
    if (getTipVanz($conn) === "fastfood") {
        return ["jobs" => 0, "lines" => 0, "error" => null];
    }

    ensurePrintQueueTable($conn);

    // 1. Datele bonului (masa, numar, ospatar, ora)
    $sqlBon = "SELECT TOP 1 b.DocID, b.NrDoc, b.NrMasa, b.Ora, b.Data,
                      COALESCE(o.Nume, 'CASIER 1') AS NumeCasier
               FROM tblBonCurent b
               LEFT JOIN tblOsp o ON b.NrOp = o.NrOsp
               WHERE b.DocID = ?";
    $stmtBon = sqlsrv_query($conn, $sqlBon, [$docId]);
    $bon = $stmtBon ? sqlsrv_fetch_array($stmtBon, SQLSRV_FETCH_ASSOC) : null;
    if (!$bon) {
        return ["jobs" => 0, "lines" => 0, "error" => "Nota nu a fost gasita"];
    }

    $dataOraStr = "";
    $oraRaw = $bon['Ora'] ? $bon['Ora'] : $bon['Data'];
    if ($oraRaw) {
        try {
            $dt = new DateTime((string)$oraRaw);
            $dataOraStr = $dt->format('d-M-y   H:i');
        } catch (Exception $e) {
            $dataOraStr = (string)$oraRaw;
        }
    }

    // 2. Numele imprimantelor (tblKP)
    $printerNames = [];
    $stmtKp = sqlsrv_query($conn, "SELECT NrLogic, Nume, DenumirePrinter FROM tblKP");
    if ($stmtKp) {
        while ($p = sqlsrv_fetch_array($stmtKp, SQLSRV_FETCH_ASSOC)) {
            $nume = trim((string)($p['Nume'] ?? ''));
            if ($nume === '') { $nume = trim((string)($p['DenumirePrinter'] ?? '')); }
            $printerNames[(int)$p['NrLogic']] = $nume;
        }
    }

    // 3. Liniile inca netrimise, in ordinea notei
    $sqlArt = "SELECT d.ECRID, d.ProdID, d.Cant,
                      COALESCE(p.Denumire, d.Descriere, 'Produs #' + CAST(d.ProdID AS VARCHAR)) AS Denumire,
                      COALESCE(p.KP, 0) AS KP
               FROM tblNoteD d
               LEFT JOIN tblProd p ON d.ProdID = p.ProdID
               WHERE d.DocID = ? AND d.Preluat = 0
               ORDER BY d.OraComanda, d.ECRID";
    $stmtArt = sqlsrv_query($conn, $sqlArt, [$docId]);
    if ($stmtArt === false) {
        return ["jobs" => 0, "lines" => 0, "error" => "Eroare citire linii: " . sqlsrv_errors()[0]['message']];
    }

    $groups = [];
    $currentKp = null;
    $lineCount = 0;
    while ($r = sqlsrv_fetch_array($stmtArt, SQLSRV_FETCH_ASSOC)) {
        $prodId = (int)$r['ProdID'];
        $cant = (float)$r['Cant'];
        $isModifier = ($prodId === 0 && $cant <= 0.0);

        if ($isModifier) {
            // Modurile se ataseaza ultimului produs din acelasi printer
            if ($currentKp !== null && !empty($groups[$currentKp])) {
                $idx = count($groups[$currentKp]) - 1;
                $groups[$currentKp][$idx]['mods'][] = trim((string)$r['Denumire']);
            }
            continue;
        }

        $kp = (int)$r['KP'];
        $currentKp = $kp;
        $groups[$kp][] = [
            "cant" => $cant,
            "denumire" => trim((string)$r['Denumire']),
            "storno" => ($cant < 0.0),
            "mods" => []
        ];
        $lineCount++;
    }

    if ($lineCount === 0) {
        return ["jobs" => 0, "lines" => 0, "error" => null];
    }

    // 4. Scriem joburile si marcam liniile Preluat = 1, atomic.
    //    Numarul de bon de sectie vine din contorul dedicat tblSet.NrBon si este
    //    acelasi pentru toate imprimantele acestei marcari.
    sqlsrv_begin_transaction($conn);

    $nrBon = nextNrBon($conn);
    if ($nrBon === null) {
        sqlsrv_rollback($conn);
        return ["jobs" => 0, "lines" => 0, "error" => "Eroare contor bon sectie (tblSet.NrBon)"];
    }

    $jobs = 0;
    foreach ($groups as $kp => $lines) {
        if (empty($lines)) { continue; }
        $payload = [
            "title" => "BON COMANDA",
            "masa" => (int)$bon['NrMasa'],
            "casier" => trim((string)$bon['NumeCasier']),
            "dataOra" => $dataOraStr,
            "nrDoc" => (int)$bon['NrDoc'],
            "nrBon" => (int)$nrBon,
            "printerNr" => (int)$kp,
            "printerName" => $printerNames[(int)$kp] ?? "",
            "lines" => $lines
        ];
        $jobId = enqueuePrintJob($conn, 'kitchen', $docId, (int)$kp, $payload);
        if ($jobId === false) {
            sqlsrv_rollback($conn);
            return ["jobs" => 0, "lines" => 0, "error" => "Eroare inscriere in coada: " . sqlsrv_errors()[0]['message']];
        }
        $jobs++;
    }

    $upd = sqlsrv_query($conn, "UPDATE tblNoteD SET Preluat = 1 WHERE DocID = ? AND Preluat = 0", [$docId]);
    if ($upd === false) {
        sqlsrv_rollback($conn);
        return ["jobs" => 0, "lines" => 0, "error" => "Eroare marcare Preluat: " . sqlsrv_errors()[0]['message']];
    }

    sqlsrv_commit($conn);
    wakePrintService();

    return ["jobs" => $jobs, "lines" => $lineCount, "nrBon" => $nrBon, "error" => null];
}

/**
 * Inchide nota (bonul) identificata prin DocID. Primeste DocID-ul notei si
 * lista de plati, un array asociativ FPID => valoare (ex. [0 => 100, 1 => 200]
 * = 100 lei numerar + 200 lei card).
 * Daca nota este goala (fara nicio linie), o sterge din tblBonCurent; altfel o
 * marcheaza ca inchisa (Stare = 'I').
 * Nota: coloana TipDoc (nvarchar(3)) nu este scrisa - nu incape numele metodei
 * de plata si nu este folosita nicaieri.
 * Returneaza un array cu status/mesaj/docId (cheia 'http' = codul HTTP dorit).
 */
function closeBill($conn, $docId, $plati = []) {
    $docId = (int)$docId;
    if (!is_array($plati)) { $plati = []; }

    $sqlBon = "SELECT TOP 1 b.DocID, b.NrDoc, b.NrMasa, b.Ora, b.Data,
                      COALESCE(o.Nume, 'CASIER 1') AS NumeCasier
               FROM tblBonCurent b
               LEFT JOIN tblOsp o ON b.NrOp = o.NrOsp
               WHERE b.DocID = ? AND b.Stare = 'D'";
    $stmtBon = sqlsrv_query($conn, $sqlBon, [$docId]);
    $bon = $stmtBon ? sqlsrv_fetch_array($stmtBon, SQLSRV_FETCH_ASSOC) : null;
    if (!$bon) {
        return ["status" => "error", "message" => "Nu exista o nota deschisa cu acest DocID", "http" => 404];
    }

    // Nota goala (fara nicio linie/produs): nu are sens sa o inchidem,
    // o stergem complet din tblBonCurent.
    $stmtCount = sqlsrv_query($conn, "SELECT COUNT(*) AS c FROM tblNoteD WHERE DocID = ?", [$docId]);
    $cnt = $stmtCount ? sqlsrv_fetch_array($stmtCount, SQLSRV_FETCH_ASSOC) : null;
    if ($cnt && (int)$cnt['c'] === 0) {
        $del = sqlsrv_query($conn, "DELETE FROM tblBonCurent WHERE DocID = ?", [$docId]);
        if ($del === false) {
            return ["status" => "error", "message" => "Eroare stergere nota: " . sqlsrv_errors()[0]['message'], "http" => 500];
        }
        return ["status" => "success", "message" => "Nota goala a fost stearsa", "docId" => $docId, "sters" => true];
    }

    // Inchidem nota si salvam platile (forme de plata + sume) in trelDocIDFpID,
    // totul intr-o singura tranzactie.
    ensurePrintQueueTable($conn);
    sqlsrv_begin_transaction($conn);

    // Marcam bonul ca inchis (Stare = 'I')
    $sqlClose = "UPDATE tblBonCurent SET Stare = 'I', Ora = GETDATE() WHERE DocID = ?";
    $upd = sqlsrv_query($conn, $sqlClose, [$docId]);
    if ($upd === false) {
        sqlsrv_rollback($conn);
        return ["status" => "error", "message" => "Eroare inchidere nota: " . sqlsrv_errors()[0]['message'], "http" => 500];
    }

    // Salvam formele de plata si sumele aferente (FPID => suma)
    sqlsrv_query($conn, "DELETE FROM trelDocIDFpID WHERE DocID = ?", [$docId]);
    foreach ($plati as $fpid => $suma) {
        $ins = sqlsrv_query(
            $conn,
            "INSERT INTO trelDocIDFpID (DocID, FPID, Suma) VALUES (?, ?, ?)",
            [$docId, (int)$fpid, (float)$suma]
        );
        if ($ins === false) {
            sqlsrv_rollback($conn);
            return ["status" => "error", "message" => "Eroare salvare plati: " . sqlsrv_errors()[0]['message'], "http" => 500];
        }
    }

    // Scriem in coada de tiparire nota de plata (nefiscala) + fisierul fiscal.
    // Totul in aceeasi tranzactie cu inchiderea notei: intentia de tiparire nu
    // se poate pierde chiar daca serviciul de tiparire / imprimanta e oprita.
    $printResult = enqueueBillPrintJobs($conn, $docId, $bon, $plati);
    if ($printResult === false) {
        sqlsrv_rollback($conn);
        return ["status" => "error", "message" => "Eroare inscriere in coada de tiparire: " . sqlsrv_errors()[0]['message'], "http" => 500];
    }

    sqlsrv_commit($conn);
    wakePrintService();

    return ["status" => "success", "message" => "Bonul a fost inchis", "docId" => $docId, "print" => $printResult];
}

/**
 * Construieste datele de tiparire pentru o nota: payload-ul notei (nefiscale)
 * si liniile fiscale. $title difera intre nota de plata ("NOTA DE PLATA") si
 * proforma ("NOTA PROFORMA"). Intoarce array sau false la eroare.
 */
function buildBillPrintData($conn, $docId, $bon, $plati, $title) {
    // 1. Randurile de header/footer ale notei (tblAntet): H1-H3 = antet, F1-F2 = subsol.
    //    Pastram si fontul/size/bold ca sa fie respectate la tiparire.
    $headerLines = [];
    $footerLines = [];
    $stmtHF = sqlsrv_query($conn, "SELECT Seria, Nume, NumeFont, Size, Bold
                                   FROM tblAntet WHERE Seria IN ('H1','H2','H3','F1','F2')");
    if ($stmtHF) {
        $tmp = [];
        while ($h = sqlsrv_fetch_array($stmtHF, SQLSRV_FETCH_ASSOC)) {
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
        foreach (['H1', 'H2', 'H3'] as $k) { if (!empty($tmp[$k])) { $headerLines[] = $tmp[$k]; } }
        foreach (['F1', 'F2'] as $k) { if (!empty($tmp[$k])) { $footerLines[] = $tmp[$k]; } }
    }

    // 2. Liniile notei (fara modurile de preparare)
    $items = [];
    $fiscalItems = [];
    $subtotal = 0.0;  // valoare de catalog (PVC), inainte de discount
    $total = 0.0;     // valoare neta (PV, dupa discount)
    $tvaByCota = [];

    $sqlArt = "SELECT d.ProdID, d.Cant, d.PV, d.PVC, d.TVAc, d.Descriere, d.[Comment],
                      COALESCE(p.Denumire, d.Descriere, 'Produs #' + CAST(d.ProdID AS VARCHAR)) AS Denumire
               FROM tblNoteD d
               LEFT JOIN tblProd p ON d.ProdID = p.ProdID
               WHERE d.DocID = ?
               ORDER BY d.OraComanda, d.ECRID";
    $stmtArt = sqlsrv_query($conn, $sqlArt, [$docId]);
    if ($stmtArt === false) { return false; }

    while ($r = sqlsrv_fetch_array($stmtArt, SQLSRV_FETCH_ASSOC)) {
        $prodId = (int)$r['ProdID'];
        $cant = (float)$r['Cant'];
        if ($prodId === 0 && $cant <= 0.0) { continue; } // mod de preparare

        $pv = (float)$r['PV'];
        $pvc = ($r['PVC'] !== null) ? (float)$r['PVC'] : $pv;
        $cota = (float)($r['TVAc'] ?? 0);
        $val = $cant * $pv;
        $valOrig = $cant * $pvc;

        $subtotal += $valOrig;
        $total += $val;

        $tva = $val - ($val / (1 + $cota / 100));
        if (!isset($tvaByCota[$cota])) { $tvaByCota[$cota] = 0.0; }
        $tvaByCota[$cota] += $tva;

        $den = trim((string)$r['Denumire']);
        // O linie are discount "pe produs" (nu pe subtotal) daca are [Comment]
        // (motivul discountului de linie). Doar acestea se evidentiaza pe produs.
        $discountLinie = (trim((string)($r['Comment'] ?? '')) !== '');
        $items[] = [
            "cant" => $cant,
            "denumire" => $den,
            "valoare" => round($val, 2),
            "valoareOriginala" => round($valOrig, 2),
            "tva" => $cota,
            "discountLinie" => $discountLinie
        ];
        $fiscalItems[] = ["cant" => $cant, "denumire" => $den, "pret" => $pv, "cota" => $cota];
    }

    // 3. Formele de plata (FPID -> denumire)
    $fpNames = [];
    $stmtFp = sqlsrv_query($conn, "SELECT FPID, Denumire FROM tblFP");
    if ($stmtFp) {
        while ($f = sqlsrv_fetch_array($stmtFp, SQLSRV_FETCH_ASSOC)) {
            $fpNames[(int)$f['FPID']] = trim((string)($f['Denumire'] ?? ''));
        }
    }
    $platiArr = [];
    foreach ($plati as $fpid => $suma) {
        $id = (int)$fpid;
        $platiArr[] = [
            "fpid" => $id,
            "denumire" => ($fpNames[$id] !== '' ? $fpNames[$id] : ("Forma " . $id)),
            "suma" => (float)$suma
        ];
    }

    // 4. Data/ora bonului
    $dataOraStr = "";
    $oraRaw = !empty($bon['Ora']) ? $bon['Ora'] : ($bon['Data'] ?? null);
    if ($oraRaw) {
        try {
            $dt = new DateTime((string)$oraRaw);
            $dataOraStr = $dt->format('d-M-y   H:i');
        } catch (Exception $e) {
            $dataOraStr = (string)$oraRaw;
        }
    }

    $tvaList = [];
    foreach ($tvaByCota as $cota => $suma) {
        $tvaList[] = ["cota" => $cota, "suma" => round($suma, 2)];
    }

    // 5. Payload nota (nefiscala; titlul difera la proforma)
    $notaPayload = [
        "title" => $title,
        "masa" => (int)$bon['NrMasa'],
        "casier" => trim((string)$bon['NumeCasier']),
        "dataOra" => $dataOraStr,
        "nrDoc" => (int)$bon['NrDoc'],
        "items" => $items,
        "subtotal" => round($subtotal, 2),
        "reducere" => round(max(0, $subtotal - $total), 2),
        "tva" => $tvaList,
        "total" => round($total, 2),
        "plati" => $platiArr,
        "headerLines" => $headerLines,
        "footerLines" => $footerLines
    ];

    return [
        "nota" => $notaPayload,
        "fiscalItems" => $fiscalItems,
        "plati" => $platiArr
    ];
}

/**
 * Scrie in coada de tiparire nota de plata (nefiscala) si fisierul fiscal al
 * casei de marcat. Ruleaza in tranzactia apelantului (closeBill) si nu face
 * commit. Intoarce array-ul cu JobID-uri sau false la eroare.
 */
function enqueueBillPrintJobs($conn, $docId, $bon, $plati) {
    $fastfood = (getTipVanz($conn) === "fastfood");

    $data = buildBillPrintData($conn, $docId, $bon, $plati, "NOTA DE PLATA");
    if ($data === false) { return false; }

    // Mod FastFood: nota de plata nu se tipareste; ramane doar bonul fiscal.
    $notaJob = null;
    if (!$fastfood) {
        $notaJob = enqueuePrintJob($conn, 'nota', $docId, null, $data['nota']);
        if ($notaJob === false) { return false; }
    }

    $fiscalLines = buildFiscalText($data['fiscalItems'], $data['plati']);
    $fiscalJob = enqueuePrintJob($conn, 'fiscal', $docId, null, [
        "lines" => $fiscalLines,
        "filename" => "doc" . (int)$docId . "_" . date('YmdHis') . ".txt"
    ]);
    if ($fiscalJob === false) { return false; }

    return ["nota" => $notaJob, "fiscal" => $fiscalJob];
}

/**
 * Grupa de TVA pentru casa de marcat (Datecs): 1 = 19%, 2 = 9%, 3 = 5%.
 * Extensibila pe masura ce se configureaza casa reala.
 */
function fiscalTvaGroup($cota) {
    if (abs($cota - 19) < 0.01) { return 1; }
    if (abs($cota - 9) < 0.01) { return 2; }
    if (abs($cota - 5) < 0.01) { return 3; }
    return 1;
}

/**
 * Codul de plata pentru casa de marcat, dedus din denumirea formei de plata.
 */
function fiscalPaymentCode($denumire) {
    $d = mb_strtolower((string)$denumire);
    if (mb_strpos($d, 'card') !== false) { return 2; }
    if (mb_strpos($d, 'ticket') !== false || mb_strpos($d, 'bon') !== false) { return 3; }
    if (mb_strpos($d, 'numerar') !== false || mb_strpos($d, 'cash') !== false) { return 1; }
    return 1;
}

/**
 * Construieste liniile de text pentru driver-ul casei de marcat.
 * Format de referinta Datecs / FiscalNet:
 *   S,Denumire,Pret,Cantitate,Departament,GrupaTVA,1,0
 *   T,CodPlata,Suma
 */
function buildFiscalText($items, $platiArr) {
    $lines = [];
    foreach ($items as $it) {
        $cant = (float)$it['cant'];
        if (abs($cant) < 0.0001) { continue; }
        $den = mb_substr(trim((string)$it['denumire']), 0, 32);
        $den = str_replace([",", "\r", "\n"], [" ", "", ""], $den);
        $pret = number_format((float)$it['pret'], 2, '.', '');
        $q = number_format($cant, 3, '.', '');
        $grupa = fiscalTvaGroup((float)$it['cota']);
        $lines[] = "S,{$den},{$pret},{$q},1,{$grupa},1,0";
    }
    foreach ($platiArr as $p) {
        $cod = fiscalPaymentCode($p['denumire']);
        $suma = number_format((float)$p['suma'], 2, '.', '');
        $lines[] = "T,{$cod},{$suma}";
    }
    return $lines;
}

$conn = getDBConnection();
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input || !is_array($input)) {
    $input = $_POST;
}

$action = $input['action'] ?? '';
$nrMasa = (int)($input['nrMasa'] ?? 1);
$nrOp = (int)($input['nrOp'] ?? 8); // Default CASIER 1

switch ($action) {
    case 'add_product':
        $prodId = (int)($input['prodId'] ?? 0);
        $cantitate = (float)($input['cantitate'] ?? 1.0);

        if ($prodId <= 0) {
            sendJsonResponse(["status" => "error", "message" => "ProdID invalid"], 400);
        }
        if ($cantitate > getCantMax($conn)) {
            $cmTxt = rtrim(rtrim(number_format(getCantMax($conn), 2, '.', ''), '0'), '.');
            sendJsonResponse(["status" => "error", "message" => "Cantitatea maxima admisa este " . $cmTxt], 400);
        }
        // Rotunjim la numarul de zecimale configurat (tblSet.NrZecCant).
        $cantitate = round($cantitate, getNrZecCant($conn));
        if ($cantitate <= 0) {
            sendJsonResponse(["status" => "error", "message" => "Cantitatea trebuie sa fie mai mare decat 0"], 400);
        }

        // 1. Obtinem datele, pretul si cota de TVA a produsului.
        //    Pretul: tblProd.PV. Cota TVA: via tblProd.Nr_TVA -> tblTVA.Cota.
        $sqlP = "SELECT p.ProdID, p.Denumire, p.NrGrp, COALESCE(p.PV, 0) as Pret,
                        COALESCE(tv.Cota, 0) as CotaTVA
                 FROM tblProd p
                 LEFT JOIN tblTVA tv ON p.Nr_TVA = tv.Nr_TVA
                 WHERE p.ProdID = ?";
        $stmtP = sqlsrv_query($conn, $sqlP, [$prodId]);
        $prod = sqlsrv_fetch_array($stmtP, SQLSRV_FETCH_ASSOC);

        if (!$prod) {
            sendJsonResponse(["status" => "error", "message" => "Produsul nu a fost gasit in baza de date"], 404);
        }

        $pret = (float)$prod['Pret'];
        $cotaTva = (float)$prod['CotaTVA'];
        $denumire = trim($prod['Denumire']);
        $nrGrp = (int)$prod['NrGrp'];

        // 2. Cautam bonul deschis (Stare = 'D') pentru aceasta masa
        $sqlBon = "SELECT TOP 1 DocID, NrOp FROM tblBonCurent WHERE NrMasa = ? AND Stare = 'D' ORDER BY DocID DESC";
        $stmtBon = sqlsrv_query($conn, $sqlBon, [$nrMasa]);
        $bon = sqlsrv_fetch_array($stmtBon, SQLSRV_FETCH_ASSOC);

        if (!$bon) {
            // Cream un bon nou in tblBonCurent (DocID este IDENTITY - auto-increment)
            $stmtMaxNr = sqlsrv_query($conn, "SELECT COALESCE(MAX(NrDoc), 0) + 1 as NextNrDoc FROM tblBonCurent");
            $maxRow = sqlsrv_fetch_array($stmtMaxNr, SQLSRV_FETCH_ASSOC);
            $nextNrDoc = (int)$maxRow['NextNrDoc'];

            $sqlInsertBon = "INSERT INTO tblBonCurent (NrDoc, Data, Ora, NrMasa, NrOp, TotalB, Stare, MagID) 
                             VALUES (?, GETDATE(), GETDATE(), ?, ?, 0, 'D', 11);
                             SELECT SCOPE_IDENTITY() AS DocID;";
            $stmtNewBon = sqlsrv_query($conn, $sqlInsertBon, [$nextNrDoc, $nrMasa, $nrOp]);
            if (!$stmtNewBon) {
                sendJsonResponse(["status" => "error", "message" => "Eroare creare bon: " . sqlsrv_errors()[0]['message']], 500);
            }
            sqlsrv_next_result($stmtNewBon);
            $rowId = sqlsrv_fetch_array($stmtNewBon, SQLSRV_FETCH_ASSOC);
            $docId = (int)$rowId['DocID'];
        } else {
            // Un ospatar nu poate opera pe masa deschisa de alt ospatar.
            if ($bon['NrOp'] !== null && (int)$bon['NrOp'] !== $nrOp) {
                sendJsonResponse(["status" => "error", "message" => "Masa este deschisa de alt ospatar."], 403);
            }
            $docId = (int)$bon['DocID'];
        }

        // 3. Inseram intotdeauna un rand NOU pe nota (ECRID este IDENTITY, deci il omitem!)
        //    Nu actualizam niciodata un rand existent al aceluiasi produs.
        //    PVC = pretul de catalog (pentru undo la discount), PV = pretul curent al liniei.
        $sqlInsertNote = "INSERT INTO tblNoteD (DocID, ProdID, Cant, PV, PVC, TVAc, OraComanda, Magid, H, Descriere, NrGrp, Preluat, Preluat1) 
                          VALUES (?, ?, ?, ?, ?, ?, GETDATE(), 11, 0, ?, ?, 0, 0)";
        $stmtIns = sqlsrv_query($conn, $sqlInsertNote, [$docId, $prodId, $cantitate, $pret, $pret, $cotaTva, $denumire, $nrGrp]);
        if (!$stmtIns) {
            sendJsonResponse(["status" => "error", "message" => "Eroare inserare in tblNoteD: " . sqlsrv_errors()[0]['message']], 500);
        }

        // 4. Recalculam TotalB in tblBonCurent (valoarea randului = Cant * PV)
        $sqlRecalc = "UPDATE tblBonCurent 
                      SET TotalB = COALESCE((SELECT SUM(Cant * PV) FROM tblNoteD WHERE DocID = ?), 0) 
                      WHERE DocID = ?";
        sqlsrv_query($conn, $sqlRecalc, [$docId, $docId]);

        sendJsonResponse([
            "status" => "success", 
            "message" => "Produsul a fost adaugat cu succes in baza de date", 
            "docId" => $docId,
            "nrMasa" => $nrMasa,
            "prodId" => $prodId,
            "denumire" => $denumire
        ]);
        break;

    case 'toggle_cook':
        $ecrId = (int)($input['ecrId'] ?? 0);
        $cook = !empty($input['cook']) ? 1 : 0;
        $sqlCook = "UPDATE tblNoteD SET H = ? WHERE ECRID = ?";
        sqlsrv_query($conn, $sqlCook, [$cook, $ecrId]);
        sendJsonResponse(["status" => "success"]);
        break;

    case 'add_mod':
        $nrMesaj = (int)($input['nrMesaj'] ?? 0);
        if ($nrMesaj <= 0) {
            sendJsonResponse(["status" => "error", "message" => "NrMesaj invalid"], 400);
        }

        // Cautam bonul deschis pe masa
        $sqlBon = "SELECT TOP 1 DocID FROM tblBonCurent WHERE NrMasa = ? AND Stare = 'D' ORDER BY DocID DESC";
        $stmtBon = sqlsrv_query($conn, $sqlBon, [$nrMasa]);
        $bon = sqlsrv_fetch_array($stmtBon, SQLSRV_FETCH_ASSOC);

        if (!$bon) {
            sendJsonResponse(["status" => "error", "message" => "Nu exista o nota deschisa pe aceasta masa"], 404);
        }
        $docId = (int)$bon['DocID'];

        // Textul modului il citim din tblMesaj (nu ne bazam pe client)
        $sqlMsg = "SELECT Mesaj FROM tblMesaj WHERE NrMesaj = ?";
        $stmtMsg = sqlsrv_query($conn, $sqlMsg, [$nrMesaj]);
        $msg = sqlsrv_fetch_array($stmtMsg, SQLSRV_FETCH_ASSOC);
        if (!$msg || !trim($msg['Mesaj'])) {
            sendJsonResponse(["status" => "error", "message" => "Modul de preparare nu a fost gasit in tblMesaj"], 404);
        }
        $textMesaj = trim($msg['Mesaj']);

        // Rând de mod de preparare: Cant = 0, ProdID = 0, NrGrp = NrMesaj, textul in Descriere
        $sqlIns = "INSERT INTO tblNoteD (DocID, ProdID, Cant, PV, PVC, TVAc, OraComanda, Magid, H, Descriere, NrGrp, Preluat, Preluat1) 
                   VALUES (?, 0, 0, 0, 0, 0, GETDATE(), 11, 0, ?, ?, 0, 0)";
        $stmtIns = sqlsrv_query($conn, $sqlIns, [$docId, $textMesaj, $nrMesaj]);
        if (!$stmtIns) {
            sendJsonResponse(["status" => "error", "message" => "Eroare inserare mod preparare: " . sqlsrv_errors()[0]['message']], 500);
        }

        sendJsonResponse(["status" => "success", "message" => "Mod de preparare adaugat", "docId" => $docId]);
        break;

    case 'delete_mod':
        $ecrId = (int)($input['ecrId'] ?? 0);
        if ($ecrId <= 0) {
            sendJsonResponse(["status" => "error", "message" => "ECRID invalid"], 400);
        }

        $sqlBon = "SELECT TOP 1 DocID FROM tblBonCurent WHERE NrMasa = ? AND Stare = 'D' ORDER BY DocID DESC";
        $stmtBon = sqlsrv_query($conn, $sqlBon, [$nrMasa]);
        $bon = sqlsrv_fetch_array($stmtBon, SQLSRV_FETCH_ASSOC);

        if (!$bon) {
            sendJsonResponse(["status" => "error", "message" => "Nu exista o nota deschisa pe aceasta masa"], 404);
        }
        $docId = (int)$bon['DocID'];

        // Doar randurile de mod (Cant <= 0) pot fi sterse astfel, nu si produsele
        $sqlDel = "DELETE FROM tblNoteD WHERE ECRID = ? AND DocID = ? AND Cant <= 0";
        $stmtDel = sqlsrv_query($conn, $sqlDel, [$ecrId, $docId]);
        if (!$stmtDel) {
            sendJsonResponse(["status" => "error", "message" => "Eroare stergere mod: " . sqlsrv_errors()[0]['message']], 500);
        }
        if (sqlsrv_rows_affected($stmtDel) === 0) {
            sendJsonResponse(["status" => "error", "message" => "Modul de preparare nu a fost gasit"], 404);
        }

        // Recalculam TotalB (valoarea randului = Cant * PV; modurile au Cant = 0)
        $sqlRecalc = "UPDATE tblBonCurent 
                      SET TotalB = COALESCE((SELECT SUM(Cant * PV) FROM tblNoteD WHERE DocID = ?), 0) 
                      WHERE DocID = ?";
        sqlsrv_query($conn, $sqlRecalc, [$docId, $docId]);

        sendJsonResponse(["status" => "success", "message" => "Modul de preparare a fost sters", "docId" => $docId]);
        break;

    case 'update_qty':
        $ecrId = (int)($input['ecrId'] ?? 0);
        $cantitate = (float)($input['cantitate'] ?? 0);
        // Rotunjim la numarul de zecimale configurat (tblSet.NrZecCant).
        $cantitate = round($cantitate, getNrZecCant($conn));

        if ($ecrId <= 0) {
            sendJsonResponse(["status" => "error", "message" => "ECRID invalid"], 400);
        }
        if ($cantitate <= 0) {
            sendJsonResponse(["status" => "error", "message" => "Cantitatea trebuie sa fie mai mare decat 0"], 400);
        }
        if ($cantitate > getCantMax($conn)) {
            $cmTxt = rtrim(rtrim(number_format(getCantMax($conn), 2, '.', ''), '0'), '.');
            sendJsonResponse(["status" => "error", "message" => "Cantitatea maxima admisa este " . $cmTxt], 400);
        }

        $sqlBon = "SELECT TOP 1 DocID FROM tblBonCurent WHERE NrMasa = ? AND Stare = 'D' ORDER BY DocID DESC";
        $stmtBon = sqlsrv_query($conn, $sqlBon, [$nrMasa]);
        $bon = sqlsrv_fetch_array($stmtBon, SQLSRV_FETCH_ASSOC);

        if (!$bon) {
            sendJsonResponse(["status" => "error", "message" => "Nu exista o nota deschisa pe aceasta masa"], 404);
        }
        $docId = (int)$bon['DocID'];

        // Citim randul existent (doar produse, Cant > 0)
        $sqlRow = "SELECT d.ECRID, d.Cant, d.PV, d.ProdID, d.Preluat
                   FROM tblNoteD d
                   WHERE d.ECRID = ? AND d.DocID = ? AND d.Cant > 0";
        $stmtRow = sqlsrv_query($conn, $sqlRow, [$ecrId, $docId]);
        $row = sqlsrv_fetch_array($stmtRow, SQLSRV_FETCH_ASSOC);

        if (!$row) {
            sendJsonResponse(["status" => "error", "message" => "Produsul nu a fost gasit pe nota"], 404);
        }

        // Nu permitem modificarea cantitatii unui produs deja trimis la sectie
        if ((int)$row['Preluat'] === 1) {
            sendJsonResponse(["status" => "error", "message" => "Produsul a fost trimis la sectie; cantitatea nu mai poate fi modificata"], 400);
        }

        // Pretul unitar: PV-ul de pe nota, apoi pretul din catalog (tblProd.PV)
        $unitPrice = (float)$row['PV'];
        if ($unitPrice <= 0) {
            $sqlP = "SELECT COALESCE(p.PV, 0) as Pret
                     FROM tblProd p
                     WHERE p.ProdID = ?";
            $stmtP = sqlsrv_query($conn, $sqlP, [(int)$row['ProdID']]);
            $p = sqlsrv_fetch_array($stmtP, SQLSRV_FETCH_ASSOC);
            $unitPrice = $p ? (float)$p['Pret'] : 0;
        }

        $sqlUpd = "UPDATE tblNoteD SET Cant = ?, PV = ? WHERE ECRID = ? AND DocID = ? AND Cant > 0";
        $stmtUpd = sqlsrv_query($conn, $sqlUpd, [$cantitate, $unitPrice, $ecrId, $docId]);
        if (!$stmtUpd) {
            sendJsonResponse(["status" => "error", "message" => "Eroare actualizare cantitate: " . sqlsrv_errors()[0]['message']], 500);
        }
        if (sqlsrv_rows_affected($stmtUpd) === 0) {
            sendJsonResponse(["status" => "error", "message" => "Produsul nu a fost gasit pe nota"], 404);
        }

        // Recalculam TotalB (valoarea randului = Cant * PV)
        $sqlRecalc = "UPDATE tblBonCurent 
                      SET TotalB = COALESCE((SELECT SUM(Cant * PV) FROM tblNoteD WHERE DocID = ?), 0) 
                      WHERE DocID = ?";
        sqlsrv_query($conn, $sqlRecalc, [$docId, $docId]);

        sendJsonResponse(["status" => "success", "message" => "Cantitate actualizata", "docId" => $docId]);
        break;

    case 'close_bill':
        $docId = (int)($input['docId'] ?? 0);
        $plati = $input['plati'] ?? [];
        $result = closeBill($conn, $docId, $plati);
        $http = $result['http'] ?? 200;
        unset($result['http']);
        sendJsonResponse($result, $http);
        break;

    case 'print_proforma':
        // Tipareste o nota proforma (nefiscala) pentru nota deschisa: acelasi
        // continut ca nota de plata, doar titlul difera. Nu inchide nota.
        $docId = (int)($input['docId'] ?? 0);
        if ($docId <= 0) {
            sendJsonResponse(["status" => "error", "message" => "DocID invalid"], 400);
        }

        ensurePrintQueueTable($conn);

        $sqlBon = "SELECT TOP 1 b.DocID, b.NrDoc, b.NrMasa, b.Ora, b.Data,
                          COALESCE(o.Nume, 'CASIER 1') AS NumeCasier
                   FROM tblBonCurent b
                   LEFT JOIN tblOsp o ON b.NrOp = o.NrOsp
                   WHERE b.DocID = ? AND b.Stare = 'D'";
        $stmtBon = sqlsrv_query($conn, $sqlBon, [$docId]);
        $bon = $stmtBon ? sqlsrv_fetch_array($stmtBon, SQLSRV_FETCH_ASSOC) : null;
        if (!$bon) {
            sendJsonResponse(["status" => "error", "message" => "Nu exista o nota deschisa cu acest DocID"], 404);
        }

        // Nota este inca deschisa, deci nu are plati inregistrate.
        $data = buildBillPrintData($conn, $docId, $bon, [], "NOTA PROFORMA");
        if ($data === false) {
            sendJsonResponse(["status" => "error", "message" => "Eroare construire nota proforma"], 500);
        }

        $jobId = enqueuePrintJob($conn, 'proforma', $docId, null, $data['nota']);
        if ($jobId === false) {
            sendJsonResponse(["status" => "error", "message" => "Eroare inscriere in coada: " . sqlsrv_errors()[0]['message']], 500);
        }

        wakePrintService();
        sendJsonResponse([
            "status" => "success",
            "message" => "Nota proforma a fost trimisa la tiparire",
            "docId" => $docId,
            "job" => $jobId
        ]);
        break;

    case 'authenticate':
        $parola = trim($input['parola'] ?? '');

        // 1. Parole POS (tblParola): programare / rapoarte
        $stmtP = @sqlsrv_query($conn, "SELECT TOP 1 ParolaProgramare, ParolaRapoarte FROM tblParola");
        $p = $stmtP ? sqlsrv_fetch_array($stmtP, SQLSRV_FETCH_ASSOC) : null;
        $prgParola = $p ? trim((string)$p['ParolaProgramare']) : '';
        $rapParola = $p ? trim((string)$p['ParolaRapoarte']) : '';

        if ($prgParola !== '' && $prgParola === $parola) {
            sendJsonResponse([
                "status" => "success",
                "programare" => true,
                "message" => "Acces programare"
            ]);
        }

        if ($rapParola !== '' && $rapParola === $parola) {
            sendJsonResponse([
                "status" => "success",
                "rapoarte" => true,
                "message" => "Acces rapoarte"
            ]);
        }

        // 2. Altfel, autentificare ospatar (tblOsp)
        $sqlOsp = "SELECT NrOsp, Nume, Expl FROM tblOsp WHERE Parola = ? AND Blocat = 0";
        $stmtOsp = sqlsrv_query($conn, $sqlOsp, [$parola]);
        $osp = sqlsrv_fetch_array($stmtOsp, SQLSRV_FETCH_ASSOC);

        if ($osp) {
            sendJsonResponse([
                "status" => "success",
                "nrOsp" => (int)$osp['NrOsp'],
                "nume" => trim($osp['Nume']),
                "rol" => trim($osp['Expl'])
            ]);
        } else {
            sendJsonResponse(["status" => "error", "message" => "Parola incorecta!"], 401);
        }
        break;

    case 'transfer_products':
        $destNrMasa = (int)($input['destNrMasa'] ?? 0);
        $ecrIds = isset($input['ecrIds']) && is_array($input['ecrIds'])
            ? array_values(array_unique(array_map('intval', $input['ecrIds'])))
            : [];
        $ecrIds = array_values(array_filter($ecrIds, function ($e) { return $e > 0; }));

        if ($destNrMasa <= 0 || $destNrMasa > 80) {
            sendJsonResponse(["status" => "error", "message" => "Numar masa destinatie invalid"], 400);
        }
        if ($destNrMasa === $nrMasa) {
            sendJsonResponse(["status" => "error", "message" => "Masa destinatie trebuie sa fie diferita de masa sursa"], 400);
        }
        if (empty($ecrIds)) {
            sendJsonResponse(["status" => "error", "message" => "Selecteaza macar un produs de transferat"], 400);
        }
        $selMap = array_flip($ecrIds);

        // Bonul deschis de pe masa sursa
        $sqlBon = "SELECT TOP 1 DocID, NrOp FROM tblBonCurent WHERE NrMasa = ? AND Stare = 'D' ORDER BY DocID DESC";
        $stmtBon = sqlsrv_query($conn, $sqlBon, [$nrMasa]);
        $bon = sqlsrv_fetch_array($stmtBon, SQLSRV_FETCH_ASSOC);
        if (!$bon) {
            sendJsonResponse(["status" => "error", "message" => "Nu exista o nota deschisa pe masa sursa"], 404);
        }
        if ($bon['NrOp'] !== null && (int)$bon['NrOp'] !== $nrOp) {
            sendJsonResponse(["status" => "error", "message" => "Masa sursa este deschisa de alt ospatar."], 403);
        }
        $srcDoc = (int)$bon['DocID'];

        // Bonul destinatie: existent, altfel il deschidem automat
        $stmtDest = sqlsrv_query($conn, $sqlBon, [$destNrMasa]);
        $dest = sqlsrv_fetch_array($stmtDest, SQLSRV_FETCH_ASSOC);
        if ($dest) {
            if ($dest['NrOp'] !== null && (int)$dest['NrOp'] !== $nrOp) {
                sendJsonResponse(["status" => "error", "message" => "Masa destinatie este deschisa de alt ospatar."], 403);
            }
            $destDoc = (int)$dest['DocID'];
        } else {
            $stmtMaxNr = sqlsrv_query($conn, "SELECT COALESCE(MAX(NrDoc), 0) + 1 as NextNrDoc FROM tblBonCurent");
            $maxRow = sqlsrv_fetch_array($stmtMaxNr, SQLSRV_FETCH_ASSOC);
            $nextNrDoc = (int)$maxRow['NextNrDoc'];

            $sqlInsertBon = "INSERT INTO tblBonCurent (NrDoc, Data, Ora, NrMasa, NrOp, TotalB, Stare, MagID) 
                             VALUES (?, GETDATE(), GETDATE(), ?, ?, 0, 'D', 11);
                             SELECT SCOPE_IDENTITY() AS DocID;";
            $stmtNewBon = sqlsrv_query($conn, $sqlInsertBon, [$nextNrDoc, $destNrMasa, $nrOp]);
            if (!$stmtNewBon) {
                sendJsonResponse(["status" => "error", "message" => "Eroare deschidere nota destinatie: " . sqlsrv_errors()[0]['message']], 500);
            }
            sqlsrv_next_result($stmtNewBon);
            $rowId = sqlsrv_fetch_array($stmtNewBon, SQLSRV_FETCH_ASSOC);
            $destDoc = (int)$rowId['DocID'];
        }

        // Selectam liniile din sursa (ordine ECRID). O linie de produs selectata
        // se muta impreuna cu modurile de preparare (Cant<=0) care ii urmeaza.
        $sqlRows = "SELECT ECRID, Cant FROM tblNoteD WHERE DocID = ? ORDER BY ECRID";
        $stmtRows = sqlsrv_query($conn, $sqlRows, [$srcDoc]);

        $moveEcrIds = [];
        $movedProds = 0;
        $moving = false;
        while ($r = sqlsrv_fetch_array($stmtRows, SQLSRV_FETCH_ASSOC)) {
            $isProd = (float)$r['Cant'] > 0.0;
            if ($isProd) {
                $moving = isset($selMap[(int)$r['ECRID']]);
                if ($moving) {
                    $moveEcrIds[] = (int)$r['ECRID'];
                    $movedProds++;
                }
            } else if ($moving) {
                $moveEcrIds[] = (int)$r['ECRID'];
            }
        }

        if (empty($moveEcrIds)) {
            sendJsonResponse(["status" => "error", "message" => "Produsele selectate nu au fost gasite pe nota sursa"], 404);
        }

        // Mutam liniile la nota destinatie si le "timpam" cu momentul actual,
        // astfel incat nota destinatie (ordonata dupa OraComanda) sa le arate la final
        $placeholders = implode(',', array_fill(0, count($moveEcrIds), '?'));
        $params = [$destDoc];
        foreach ($moveEcrIds as $eid) { $params[] = $eid; }
        $params[] = $srcDoc;
        $sqlMove = "UPDATE tblNoteD SET DocID = ?, OraComanda = GETDATE() WHERE ECRID IN ($placeholders) AND DocID = ?";
        $stmtMove = sqlsrv_query($conn, $sqlMove, $params);
        if (!$stmtMove) {
            sendJsonResponse(["status" => "error", "message" => "Eroare transfer produse: " . sqlsrv_errors()[0]['message']], 500);
        }

        // Recalculam TotalB la destinatie si la sursa (valoarea randului = Cant * PV)
        $sqlRecalc = "UPDATE tblBonCurent 
                      SET TotalB = COALESCE((SELECT SUM(Cant * PV) FROM tblNoteD WHERE DocID = ?), 0) 
                      WHERE DocID = ?";
        sqlsrv_query($conn, $sqlRecalc, [$destDoc, $destDoc]);
        sqlsrv_query($conn, $sqlRecalc, [$srcDoc, $srcDoc]);

        // Daca sursa nu mai are niciun produs, inchidem nota (masa devine libera)
        $sqlCount = "SELECT COUNT(*) AS c FROM tblNoteD WHERE DocID = ? AND Cant > 0";
        $stmtCount = sqlsrv_query($conn, $sqlCount, [$srcDoc]);
        $cnt = sqlsrv_fetch_array($stmtCount, SQLSRV_FETCH_ASSOC);
        if ((int)$cnt['c'] === 0) {
            $sqlClose = "UPDATE tblBonCurent SET Stare = 'I', Ora = GETDATE() WHERE DocID = ?";
            sqlsrv_query($conn, $sqlClose, [$srcDoc]);
        }

        sendJsonResponse([
            "status" => "success",
            "message" => "Produse transferate pe masa " . $destNrMasa,
            "moved" => $movedProds,
            "destNrMasa" => $destNrMasa
        ]);
        break;

    case 'verify_discount_parola':
        // Verifica parola de discount (tblParola.ParolaDiscount). Daca nu este
        // setata, orice parola este acceptata (discountul nu cere parola).
        $parolaDisc = getDiscountParola($conn);
        if ($parolaDisc === '') {
            sendJsonResponse(["status" => "success", "required" => false]);
        }
        $parolaIn = trim((string)($input['parola'] ?? ''));
        if ($parolaIn !== '' && $parolaIn === $parolaDisc) {
            sendJsonResponse(["status" => "success", "required" => true]);
        }
        // Intoarcem si `required` ca clientul sa stie ca trebuie sa ceara parola
        // (chiar daca nu a trimis inca una sau flag-ul local e invechit).
        sendJsonResponse(["status" => "error", "required" => true, "message" => "Parola de discount incorecta."], 401);
        break;

    case 'apply_discount':
        // Discountul poate fi interzis din setari (tblSet.RED = 0).
        if (getRed($conn) === 0) {
            sendJsonResponse(["status" => "error", "message" => "Discountul nu este permis."], 403);
        }

        // Daca este configurata o parola de discount, o cerem si aici (protectie
        // la apeluri directe ale API-ului, nu doar in UI).
        $parolaDisc = getDiscountParola($conn);
        if ($parolaDisc !== '') {
            $parolaIn = trim((string)($input['parola'] ?? ''));
            if ($parolaIn !== $parolaDisc) {
                sendJsonResponse(["status" => "error", "required" => true, "message" => "Parola de discount incorecta."], 401);
            }
        }

        $scope = $input['scope'] ?? 'bill';
        $mode = $input['mode'] ?? 'percent';
        $value = (float)($input['value'] ?? 0);
        $ecrId = (int)($input['ecrId'] ?? 0);
        $motiv = isset($input['motiv']) ? trim((string)$input['motiv']) : '';
        if ($motiv === '') { $motiv = null; }

        if (!in_array($scope, ['line', 'bill'], true)) {
            sendJsonResponse(["status" => "error", "message" => "Scop discount invalid"], 400);
        }
        if (!in_array($mode, ['percent', 'valoric'], true)) {
            sendJsonResponse(["status" => "error", "message" => "Tip discount invalid"], 400);
        }
        if ($value <= 0) {
            sendJsonResponse(["status" => "error", "message" => "Valoarea discountului trebuie sa fie mai mare decat 0"], 400);
        }

        // Bonul deschis de pe masa
        $sqlBon = "SELECT TOP 1 DocID FROM tblBonCurent WHERE NrMasa = ? AND Stare = 'D' ORDER BY DocID DESC";
        $stmtBon = sqlsrv_query($conn, $sqlBon, [$nrMasa]);
        $bon = sqlsrv_fetch_array($stmtBon, SQLSRV_FETCH_ASSOC);
        if (!$bon) {
            sendJsonResponse(["status" => "error", "message" => "Nu exista o nota deschisa pe aceasta masa"], 404);
        }
        $docId = (int)$bon['DocID'];

        // Cantitatea deja stornata pe fiecare linie (pentru baza neta a discountului)
        $sqlStorn = "SELECT StornoRef, COALESCE(SUM(-Cant), 0) AS s
                     FROM tblNoteD
                     WHERE DocID = ? AND Cant < 0 AND StornoRef IS NOT NULL
                     GROUP BY StornoRef";
        $stmtStorn = sqlsrv_query($conn, $sqlStorn, [$docId]);
        $stornMap = [];
        if ($stmtStorn) {
            while ($s = sqlsrv_fetch_array($stmtStorn, SQLSRV_FETCH_ASSOC)) {
                $stornMap[(int)$s['StornoRef']] = (float)$s['s'];
            }
        }

        // Liniile de produs (Cant > 0). Pretul de referinta este cel de catalog (PVC).
        // Pentru repartizarea discountului folosim valoarea NETA (dupa liniile de storno),
        // ca discountul sa fie impartit corect pe cotele de TVA.
        $sqlLines = "SELECT ECRID, Cant, COALESCE(TVAc, 0) AS Cota, COALESCE(PVC, PV) AS PretCatalog
                     FROM tblNoteD
                     WHERE DocID = ? AND Cant > 0
                     ORDER BY ECRID";
        $stmtLines = sqlsrv_query($conn, $sqlLines, [$docId]);
        if (!$stmtLines) {
            sendJsonResponse(["status" => "error", "message" => "Eroare citire linii: " . sqlsrv_errors()[0]['message']], 500);
        }

        $lines = [];
        $totalC = 0;
        while ($l = sqlsrv_fetch_array($stmtLines, SQLSRV_FETCH_ASSOC)) {
            $cant = (float)$l['Cant'];
            $pc = (float)$l['PretCatalog'];
            $ecrIdLinie = (int)$l['ECRID'];
            $valC = (int)round($cant * $pc * 100); // valoarea bruta a liniei (Cant * PVC)

            $stornat = isset($stornMap[$ecrIdLinie]) ? $stornMap[$ecrIdLinie] : 0.0;
            $netCant = $cant - $stornat;
            if ($netCant < 0) { $netCant = 0.0; }
            $netValC = (int)round($netCant * $pc * 100); // ponderea neta folosita la repartizare

            $lines[] = [
                'ecrId' => $ecrIdLinie,
                'cant' => $cant,
                'cota' => (int)$l['Cota'],
                'valC' => $valC,
                'netValC' => $netValC
            ];
            $totalC += $netValC;
        }
        if (empty($lines) || $totalC <= 0) {
            sendJsonResponse(["status" => "error", "message" => "Nota nu are produse active pe care sa se aplice discount"], 400);
        }

        // Identificam linia tinta (doar pentru discount pe produs)
        $target = null;
        if ($scope === 'line') {
            foreach ($lines as $l) {
                if ($l['ecrId'] === $ecrId) { $target = $l; break; }
            }
            if (!$target) {
                sendJsonResponse(["status" => "error", "message" => "Produsul selectat nu a fost gasit pe nota"], 404);
            }
        }

        // Discount total, in centi, raportat la baza NETA a scopului ales
        $baseC = ($scope === 'line') ? $target['netValC'] : $totalC;
        if ($baseC <= 0) {
            sendJsonResponse(["status" => "error", "message" => "Linia nu mai are cantitate activa (a fost anulata)"], 400);
        }
        if ($mode === 'percent') {
            if ($value > 100) {
                sendJsonResponse(["status" => "error", "message" => "Discountul procentual nu poate depasi 100%"], 400);
            }
            $discC = (int)round($baseC * $value / 100);
        } else {
            $discC = (int)round($value * 100);
        }
        if ($discC > $baseC - 1) { $discC = $baseC - 1; }
        if ($discC <= 0) {
            sendJsonResponse(["status" => "error", "message" => "Valoarea discountului este prea mare"], 400);
        }

        // Discountul PE PRODUS se aplica independent pe linia tinta: mai multe
        // linii pot avea discounturi diferite (ex. 25% pe un produs, 30% pe altul)
        // si aplicarea pe o linie NU le reseteaza pe celelalte.
        // Discountul PE SUBTOTAL este unic pe nota: reseteaza toate liniile la
        // pretul de catalog, apoi il repartizeaza proportional.
        // Doar discountul PE PRODUS marcheaza linia cu [Comment] (motivul), ca sa
        // fie afisat sub produsul redus; cel pe subtotal se arata doar ca total.
        $lineComment = null;
        if ($scope === 'line') {
            $lineComment = ($motiv !== null && $motiv !== '') ? $motiv : 'Discount produs';
        } else {
            $sqlReset = "UPDATE tblNoteD SET PV = COALESCE(PVC, PV), [Comment] = NULL WHERE DocID = ? AND Cant > 0";
            if (!sqlsrv_query($conn, $sqlReset, [$docId])) {
                sendJsonResponse(["status" => "error", "message" => "Eroare resetare discount: " . sqlsrv_errors()[0]['message']], 500);
            }
        }

        // Repartizam discountul pe fiecare linie, in centi
        $perLine = [];
        if ($scope === 'line') {
            $perLine[$target['ecrId']] = $discC;
        } else {
            // Repartizare pe cote de TVA: mai intai pe grupe (dupa Cota),
            // apoi in interiorul fiecarei grupe pe liniile componente.
            $groups = [];
            $groupWeights = [];
            foreach ($lines as $l) {
                $cota = $l['cota'];
                $groups[$cota][] = $l;
                if (!isset($groupWeights[$cota])) { $groupWeights[$cota] = 0; }
                $groupWeights[$cota] += $l['netValC'];
            }

            $groupDisc = distributeDiscountCents($discC, $groupWeights);
            foreach ($groups as $cota => $gl) {
                $lineWeights = [];
                foreach ($gl as $l) { $lineWeights[$l['ecrId']] = $l['netValC']; }
                $lineDisc = distributeDiscountCents($groupDisc[$cota], $lineWeights);
                foreach ($lineDisc as $eid => $dc) { $perLine[$eid] = $dc; }
            }
        }

        // Scriem noile preturi (PV) si motivul discountului (Comment).
        // Discountul se scade din valoarea BRUTA a liniei (care pastreaza toata
        // cantitatea); liniile de storno ramanand sa scada pretul intreg.
        // La discountul PE PRODUS modificam doar linia tinta; celelalte linii
        // isi pastreaza discounturile existente.
        $subtotalNou = $totalC / 100;
        foreach ($lines as $l) {
            if ($scope === 'line' && $l['ecrId'] !== $target['ecrId']) {
                continue;
            }
            $dc = isset($perLine[$l['ecrId']]) ? $perLine[$l['ecrId']] : 0;
            $valNouC = $l['valC'] - $dc;
            $newPV = ($valNouC / 100) / $l['cant'];

            if ($dc > 0) {
                $sqlUpd = "UPDATE tblNoteD SET PV = ?, [Comment] = ? WHERE ECRID = ? AND DocID = ?";
                $ok = sqlsrv_query($conn, $sqlUpd, [$newPV, $lineComment, $l['ecrId'], $docId]);
            } else {
                $sqlUpd = "UPDATE tblNoteD SET PV = ?, [Comment] = NULL WHERE ECRID = ? AND DocID = ?";
                $ok = sqlsrv_query($conn, $sqlUpd, [$newPV, $l['ecrId'], $docId]);
            }
            if (!$ok) {
                sendJsonResponse(["status" => "error", "message" => "Eroare aplicare discount: " . sqlsrv_errors()[0]['message']], 500);
            }
        }

        // Recalculam TotalB (valoarea randului = Cant * PV)
        $sqlRecalc = "UPDATE tblBonCurent 
                      SET TotalB = COALESCE((SELECT SUM(Cant * PV) FROM tblNoteD WHERE DocID = ?), 0) 
                      WHERE DocID = ?";
        sqlsrv_query($conn, $sqlRecalc, [$docId, $docId]);

        // Totalul real al notei (include liniile de storno)
        $totalNou = 0.0;
        $stmtTot = sqlsrv_query($conn, "SELECT COALESCE(SUM(Cant * PV), 0) AS t FROM tblNoteD WHERE DocID = ?", [$docId]);
        if ($stmtTot) {
            $totalNou = (float)sqlsrv_fetch_array($stmtTot, SQLSRV_FETCH_ASSOC)['t'];
        }

        sendJsonResponse([
            "status" => "success",
            "message" => "Discount aplicat",
            "docId" => $docId,
            "scope" => $scope,
            "subtotal" => round($subtotalNou, 2),
            "reducere" => round($subtotalNou - $totalNou, 2),
            "total" => round($totalNou, 2)
        ]);
        break;

    case 'void_line':
        $ecrId = (int)($input['ecrId'] ?? 0);
        $cantitate = (float)($input['cantitate'] ?? 0);
        $motiv = isset($input['motiv']) ? trim((string)$input['motiv']) : '';
        $parola = isset($input['parola']) ? trim((string)$input['parola']) : '';

        if ($ecrId <= 0) {
            sendJsonResponse(["status" => "error", "message" => "ECRID invalid"], 400);
        }
        if ($cantitate <= 0) {
            sendJsonResponse(["status" => "error", "message" => "Cantitatea de anulat trebuie sa fie mai mare decat 0"], 400);
        }

        // Bonul deschis de pe masa
        $sqlBon = "SELECT TOP 1 DocID FROM tblBonCurent WHERE NrMasa = ? AND Stare = 'D' ORDER BY DocID DESC";
        $stmtBon = sqlsrv_query($conn, $sqlBon, [$nrMasa]);
        $bon = sqlsrv_fetch_array($stmtBon, SQLSRV_FETCH_ASSOC);
        if (!$bon) {
            sendJsonResponse(["status" => "error", "message" => "Nu exista o nota deschisa pe aceasta masa"], 404);
        }
        $docId = (int)$bon['DocID'];

        // Linia selectata: linie de produs (Cant > 0) sau linie de storno (Cant < 0)
        $sqlRow = "SELECT ECRID, ProdID, Cant, PV, PVC, TVAc, Descriere, NrGrp, Preluat, [Comment], StornoRef
                   FROM tblNoteD
                   WHERE ECRID = ? AND DocID = ?";
        $stmtRow = sqlsrv_query($conn, $sqlRow, [$ecrId, $docId]);
        $row = sqlsrv_fetch_array($stmtRow, SQLSRV_FETCH_ASSOC);
        if (!$row) {
            sendJsonResponse(["status" => "error", "message" => "Linia selectata nu a fost gasita pe nota"], 404);
        }

        // ---- Revocarea unei anulari: se sterge linia de storno (doar daca nu a fost marcata) ----
        if ((float)$row['Cant'] < 0) {
            if ((int)$row['Preluat'] === 1) {
                sendJsonResponse(["status" => "error", "message" => "Storno-ul a fost deja trimis la bucatarie si nu mai poate fi anulat"], 400);
            }

            $refEcr = (int)($row['StornoRef'] ?? 0);
            $qtyStorn = -(float)$row['Cant'];

            // Restabilim discountul liniei originale proportional cu cantitatea reintrata
            if ($refEcr > 0) {
                $stmtOrig = sqlsrv_query($conn, "SELECT ECRID, Cant, PV, PVC FROM tblNoteD WHERE ECRID = ? AND DocID = ? AND Cant > 0", [$refEcr, $docId]);
                $orig = $stmtOrig ? sqlsrv_fetch_array($stmtOrig, SQLSRV_FETCH_ASSOC) : null;
                if ($orig) {
                    $cantO = (float)$orig['Cant'];
                    $pvcO = ($orig['PVC'] !== null) ? (float)$orig['PVC'] : (float)$orig['PV'];
                    $pvO = (float)$orig['PV'];
                    $discCurrent = $cantO * ($pvcO - $pvO);

                    if ($discCurrent > 0.005) {
                        $stmtSum = sqlsrv_query($conn, "SELECT COALESCE(SUM(-Cant),0) AS s FROM tblNoteD WHERE DocID = ? AND Cant < 0 AND StornoRef = ?", [$docId, $refEcr]);
                        $sumRow = $stmtSum ? sqlsrv_fetch_array($stmtSum, SQLSRV_FETCH_ASSOC) : null;
                        $totalStorn = (float)($sumRow['s'] ?? 0);

                        $netCurrent = ($cantO - $totalStorn) * $pvcO;
                        $netNew = ($cantO - ($totalStorn - $qtyStorn)) * $pvcO;
                        $discNew = ($netCurrent > 0) ? $discCurrent * $netNew / $netCurrent : 0;
                        if ($discNew > $netNew) { $discNew = $netNew; }
                        if ($discNew < 0) { $discNew = 0; }
                        $pvNew = ($cantO > 0) ? ($pvcO - $discNew / $cantO) : $pvcO;
                        if ($pvNew < 0) { $pvNew = 0; }

                        if ($discNew > 0.005) {
                            sqlsrv_query($conn, "UPDATE tblNoteD SET PV = ? WHERE ECRID = ? AND DocID = ?", [$pvNew, $refEcr, $docId]);
                        } else {
                            sqlsrv_query($conn, "UPDATE tblNoteD SET PV = COALESCE(PVC, PV), [Comment] = NULL WHERE ECRID = ? AND DocID = ?", [$refEcr, $docId]);
                        }
                    }
                }
            }

            $okDel = sqlsrv_query($conn, "DELETE FROM tblNoteD WHERE ECRID = ? AND DocID = ? AND Cant < 0", [$ecrId, $docId]);
            if (!$okDel) {
                sendJsonResponse(["status" => "error", "message" => "Eroare stergere storno: " . sqlsrv_errors()[0]['message']], 500);
            }

            $sqlRecalc = "UPDATE tblBonCurent 
                          SET TotalB = COALESCE((SELECT SUM(Cant * PV) FROM tblNoteD WHERE DocID = ?), 0) 
                          WHERE DocID = ?";
            sqlsrv_query($conn, $sqlRecalc, [$docId, $docId]);

            sendJsonResponse([
                "status" => "success",
                "message" => "Anularea a fost revocata (storno sters)",
                "mod" => "unstorno",
                "cantitate" => $qtyStorn,
                "docId" => $docId
            ]);
        }

        $cantLinie = (float)$row['Cant'];
        $trimis = ((int)$row['Preluat'] === 1);

        // Pentru liniile deja trimise (Preluat = 1) cerem parola de manager si motivul
        if ($trimis) {
            if ($motiv === '') {
                sendJsonResponse(["status" => "error", "message" => "Selectati motivul anularii"], 400);
            }
            if ($parola === '') {
                sendJsonResponse(["status" => "error", "message" => "Introduceti parola de manager"], 401);
            }
            $stmtPrg = @sqlsrv_query($conn, "SELECT TOP 1 ParolaStornare FROM tblParola");
            $prg = $stmtPrg ? sqlsrv_fetch_array($stmtPrg, SQLSRV_FETCH_ASSOC) : null;
            $parolaOk = $prg && isset($prg['ParolaStornare']) && trim($prg['ParolaStornare']) !== '' && trim($prg['ParolaStornare']) === $parola;
            if (!$parolaOk) {
                sendJsonResponse(["status" => "error", "message" => "Parola de stornare incorecta"], 401);
            }
        } else {
            $motiv = null;
        }

        // Cat s-a stornat deja pentru aceasta linie (liniile de storno au Cant < 0)
        $sqlStorn = "SELECT COALESCE(SUM(-Cant), 0) AS s FROM tblNoteD WHERE DocID = ? AND Cant < 0 AND StornoRef = ?";
        $stmtStorn = sqlsrv_query($conn, $sqlStorn, [$docId, $ecrId]);
        $storn = sqlsrv_fetch_array($stmtStorn, SQLSRV_FETCH_ASSOC);
        $dejaStornat = (float)($storn['s'] ?? 0);

        $ramas = $cantLinie - $dejaStornat;
        if ($ramas <= 0.0001) {
            sendJsonResponse(["status" => "error", "message" => "Linia a fost deja anulata integral"], 400);
        }
        if ($cantitate > $ramas + 0.0001) {
            $fmt = function ($n) { return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.'); };
            sendJsonResponse([
                "status" => "error",
                "message" => "Nu puteti anula mai mult de " . $fmt($ramas) . " (din care " . $fmt($dejaStornat) . " deja anulat)"
            ], 400);
        }

        if (!$trimis) {
            // Netrimis: se sterge definitiv (integral) sau se scade cantitatea
            if ($cantitate >= $ramas - 0.0001) {
                // La stergerea integrala se sterg si modurile de preparare care
                // apartin de aceasta linie (randuri Cant<=0, ProdID=0, care o
                // urmeaza in ordinea notei pana la urmatorul rand de produs).
                $sqlAll = "SELECT ECRID, ProdID, Cant FROM tblNoteD WHERE DocID = ? ORDER BY OraComanda, ECRID";
                $stmtAll = sqlsrv_query($conn, $sqlAll, [$docId]);
                $modsToDelete = [];
                $foundLine = false;
                if ($stmtAll) {
                    while ($r = sqlsrv_fetch_array($stmtAll, SQLSRV_FETCH_ASSOC)) {
                        $rEcr = (int)$r['ECRID'];
                        $rProd = (int)$r['ProdID'];
                        $rCant = (float)$r['Cant'];
                        if (!$foundLine) {
                            if ($rEcr === $ecrId) { $foundLine = true; }
                            continue;
                        }
                        if ($rProd === 0 && $rCant <= 0.0) {
                            $modsToDelete[] = $rEcr;
                        } else {
                            break;
                        }
                    }
                }
                if (!empty($modsToDelete)) {
                    $ph = implode(',', array_fill(0, count($modsToDelete), '?'));
                    $params = $modsToDelete;
                    $params[] = $docId;
                    sqlsrv_query($conn, "DELETE FROM tblNoteD WHERE ECRID IN ($ph) AND DocID = ?", $params);
                }

                $ok = sqlsrv_query($conn, "DELETE FROM tblNoteD WHERE ECRID = ? AND DocID = ? AND Cant > 0", [$ecrId, $docId]);
                $mod = 'delete';
            } else {
                $ok = sqlsrv_query($conn, "UPDATE tblNoteD SET Cant = Cant - ? WHERE ECRID = ? AND DocID = ? AND Cant > 0", [$cantitate, $ecrId, $docId]);
                $mod = 'reduce';
            }
            if (!$ok) {
                sendJsonResponse(["status" => "error", "message" => "Eroare anulare linie: " . sqlsrv_errors()[0]['message']], 500);
            }
        } else {
            // Trimis: NU se sterge; se adauga o linie de storno (Cant negativ) legata
            // de linia originala prin StornoRef. Linia de storno se scrie la pretul de
            // catalog (PVC), iar discountul de pe linia originala se reduce PROPORTIONAL
            // cu cantitatea ramasa, ca totalul sa nu poata deveni negativ.
            $pvc = ($row['PVC'] !== null) ? (float)$row['PVC'] : (float)$row['PV'];
            $pvLinie = (float)$row['PV'];

            $discBeforeC = (int)round($cantLinie * ($pvc - $pvLinie) * 100);
            $netBeforeC = (int)round(($cantLinie - $dejaStornat) * $pvc * 100);
            $netAfterC = (int)round(($cantLinie - ($dejaStornat + $cantitate)) * $pvc * 100);
            if ($netAfterC < 0) { $netAfterC = 0; }

            $discAfterC = ($netBeforeC > 0) ? (int)round($discBeforeC * $netAfterC / $netBeforeC) : 0;
            if ($discAfterC > $netAfterC) { $discAfterC = $netAfterC; }
            if ($discAfterC < 0) { $discAfterC = 0; }

            $newPV = ($cantLinie > 0) ? ($pvc - ($discAfterC / 100) / $cantLinie) : $pvc;
            if ($newPV < 0) { $newPV = 0; }

            // Linia de storno: Cant negativ, PV = PVC (pretul intreg de catalog)
            $sqlIns = "INSERT INTO tblNoteD (DocID, ProdID, Cant, PV, PVC, TVAc, OraComanda, Magid, H, Descriere, NrGrp, Preluat, Preluat1, StornoRef, [Comment])
                       VALUES (?, ?, ?, ?, ?, ?, GETDATE(), 11, 0, ?, ?, 0, 0, ?, ?)";
            $stmtIns = sqlsrv_query($conn, $sqlIns, [
                $docId,
                (int)$row['ProdID'],
                -$cantitate,
                $pvc,
                $pvc,
                (int)($row['TVAc'] ?? 0),
                trim((string)$row['Descriere']),
                (int)$row['NrGrp'],
                $ecrId,
                $motiv
            ]);
            if (!$stmtIns) {
                sendJsonResponse(["status" => "error", "message" => "Eroare inserare storno: " . sqlsrv_errors()[0]['message']], 500);
            }

            // Actualizam pretul liniei originale la discountul ramas proportional.
            if ($discAfterC > 0) {
                $okUpd = sqlsrv_query($conn, "UPDATE tblNoteD SET PV = ? WHERE ECRID = ? AND DocID = ?", [$newPV, $ecrId, $docId]);
            } else {
                $okUpd = sqlsrv_query($conn, "UPDATE tblNoteD SET PV = COALESCE(PVC, PV), [Comment] = NULL WHERE ECRID = ? AND DocID = ?", [$ecrId, $docId]);
            }
            if (!$okUpd) {
                sendJsonResponse(["status" => "error", "message" => "Eroare actualizare discount dupa storno: " . sqlsrv_errors()[0]['message']], 500);
            }

            $mod = 'storno';
        }

        // Recalculam TotalB (valoarea randului = Cant * PV)
        $sqlRecalc = "UPDATE tblBonCurent 
                      SET TotalB = COALESCE((SELECT SUM(Cant * PV) FROM tblNoteD WHERE DocID = ?), 0) 
                      WHERE DocID = ?";
        sqlsrv_query($conn, $sqlRecalc, [$docId, $docId]);

        sendJsonResponse([
            "status" => "success",
            "message" => ($mod === 'storno')
                ? "Articol stornat (nota de anulare pentru bucatarie)"
                : "Articol anulat",
            "mod" => $mod,
            "trimis" => $trimis,
            "cantitate" => $cantitate,
            "docId" => $docId
        ]);
        break;

    case 'print_kitchen':
        // Trimite la bucatarie liniile netrimise ale notei indicate prin DocID.
        $docId = (int)($input['docId'] ?? 0);
        if ($docId <= 0) {
            sendJsonResponse(["status" => "error", "message" => "DocID invalid"], 400);
        }

        // Mod FastFood: nu se trimite nimic la sectie.
        if (getTipVanz($conn) === "fastfood") {
            sendJsonResponse([
                "status" => "success",
                "message" => "Mod FastFood: comanda nu se trimite la sectie",
                "docId" => $docId,
                "jobs" => 0,
                "marcate" => 0
            ]);
        }

        $result = printKitchen($conn, $docId);
        if ($result['error'] !== null) {
            sendJsonResponse(["status" => "error", "message" => "Eroare trimitere la bucatarie: " . $result['error']], 500);
        }

        $message = ($result['jobs'] > 0)
            ? "Comanda (bon " . ($result['nrBon'] ?? '-') . ") a fost trimisa la bucatarie (" . $result['jobs'] . " bon(uri))"
            : "Nu exista linii netrimise";

        sendJsonResponse([
            "status" => "success",
            "message" => $message,
            "docId" => $docId,
            "jobs" => $result['jobs'],
            "marcate" => $result['lines'],
            "nrBon" => $result['nrBon'] ?? null
        ]);
        break;

    case 'mark_sent':
        // Marcheaza comanda ca trimisa la bucatarie/bar: Preluat = 1 pe toate
        // liniile notei (produse, moduri si linii de storno).
        $sqlBon = "SELECT TOP 1 DocID FROM tblBonCurent WHERE NrMasa = ? AND Stare = 'D' ORDER BY DocID DESC";
        $stmtBon = sqlsrv_query($conn, $sqlBon, [$nrMasa]);
        $bon = sqlsrv_fetch_array($stmtBon, SQLSRV_FETCH_ASSOC);
        if (!$bon) {
            sendJsonResponse(["status" => "error", "message" => "Nu exista o nota deschisa pe aceasta masa"], 404);
        }
        $docId = (int)$bon['DocID'];

        $sqlMark = "UPDATE tblNoteD SET Preluat = 1 WHERE DocID = ?";
        $stmtMark = sqlsrv_query($conn, $sqlMark, [$docId]);
        if (!$stmtMark) {
            sendJsonResponse(["status" => "error", "message" => "Eroare marcare: " . sqlsrv_errors()[0]['message']], 500);
        }

        sendJsonResponse([
            "status" => "success",
            "message" => "Comanda a fost trimisa la bucatarie",
            "docId" => $docId,
            "marcate" => sqlsrv_rows_affected($stmtMark)
        ]);
        break;

    default:
        sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}
