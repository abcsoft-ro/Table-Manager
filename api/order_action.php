<?php
/**
 * API: Operatii pe comanda (Adaugare produs, modificare cantitate, cook, inchidere)
 * Compatibil MSSQL (ECRID si DocID coloane IDENTITY)
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/print_common.php';
require_once __DIR__ . '/send_common.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/app_events.php';

/**
 * Tipul de vanzare configurat in tblSet (TipVanz): "fastfood" cand valoarea
 * este 1, altfel "restaurant". In modul FastFood comanda nu se mai trimite la
 * sectie, iar la inchiderea notei se tipareste doar bonul fiscal (fara nota).
 *
 * @param resource $conn
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
 * Tipul casei de marcat fiscale (tblSet.TipCasaMarcat): "fiscalwire", "fiscalnet",
 * "fisco" sau "fprwin". Implicit "fiscalwire" cand setarea lipseste sau este
 * necunoscuta. "fisco" si "fprwin" sunt inca in curs de implementare (se comporta
 * ca FiscalWire). Determina formatul fisierului de comenzi scris pentru casa de marcat.
 *
 * @param resource $conn
 */
function getTipCasaMarcat($conn) {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = "fiscalwire";
    $stmt = sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'TipCasaMarcat'");
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row) {
            $v = mb_strtolower(trim((string)$row['Value']));
            if ($v === 'fiscalnet') { $cache = "fiscalnet"; }
            elseif ($v === 'fisco' || $v === 'tremol') { $cache = "fisco"; }
            elseif ($v === 'fprwin') { $cache = "fprwin"; }
        }
    }
    return $cache;
}

/**
 * Afisarea discountului/stornarii pe bonul fiscal (tblSet.BonFiscalDiscStorno):
 * 1 = DA (se scriu comenzile de discount/majorare si liniile de storno),
 * 0 = NU (implicit; bonul contine doar cantitatile si preturile nete, fara
 * discount si fara stornare). Se aplica fisierului casei de marcat.
 *
 * @param resource $conn
 */
function getBonFiscalDiscStorno($conn) {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = 0;
    $stmt = sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'BonFiscalDiscStorno'");
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row && (int)trim((string)$row['Value']) === 1) { $cache = 1; }
    }
    return $cache;
}

/**
 * Folderul monitorizat de driverul casei de marcat (tblSet.CaleFisierComenziECR).
 * Fisierul de comenzi trebuie copiat aici ca sa fie procesat (FiscalNet,
 * FiscalWire sau alt driver).
 *
 * @param resource $conn
 */
function getCaleFisierComenziECR($conn) {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = '';
    $stmt = sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'CaleFisierComenziECR'");
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row) { $cache = trim((string)$row['Value']); }
    }
    return $cache;
}

/**
 * Folderul in care casa de marcat scrie fisierele de raspuns
 * (tblSet.CaleFisierRaspunsECR). Folosit la asteptarea confirmarii bonului.
 *
 * @param resource $conn
 */
function getCaleFisierRaspunsECR($conn) {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = '';
    $stmt = sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'CaleFisierRaspunsECR'");
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row) { $cache = trim((string)$row['Value']); }
    }
    return $cache;
}

/**
 * Daca POS-ul asteapta raspunsul casei de marcat la inchiderea notei
 * (tblSet.AsteaptaRaspunsCasa): 1 = DA, 0 = NU (implicit).
 *
 * @param resource $conn
 */
function getAsteaptaRaspunsCasa($conn) {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = 0;
    $stmt = @sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'AsteaptaRaspunsCasa'");
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row && trim((string)$row['Value']) === '1') { $cache = 1; }
    }
    return $cache;
}

/**
 * Durata maxima de asteptare a raspunsului casei de marcat
 * (tblSet.AsteptareCasaSec). Implicit 30 secunde cand lipseste/invalid.
 *
 * @param resource $conn
 */
function getAsteptareCasaSec($conn) {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = 30;
    $stmt = @sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'AsteptareCasaSec'");
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row && trim((string)$row['Value']) !== '') {
            $v = (int)trim((string)$row['Value']);
            if ($v > 0) { $cache = $v; }
        }
    }
    return $cache;
}

/**
 * Cantitatea maxima admisa pe o linie de nota (tblSet.CantMax). Implicit 1000
 * cand setarea lipseste sau este invalida. Protejeaza impotriva tastarii gresite.
 *
 * @param resource $conn
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
 * Magazia (gestiunea) implicita a unei linii de nota: prima valoare din
 * tblSet.MagID (poate fi o lista '1,2' -> primul intreg). Folosita cand
 * produsul nu are o gestiune proprie (tblProd.MagID) sau la nivelul bonului.
 *
 * @param resource $conn
 */
function getMagIdDefault($conn) {
    return getTblSetInt($conn, 'MagID', 0);
}

/**
 * Magazia unei linii pentru un produs: tblProd.MagID daca exista, altfel
 * magazia implicita (prima din lista tblSet.MagID).
 *
 * @param resource $conn
 * @param mixed $prodMagID
 */
function getLineMagId($conn, $prodMagID) {
    if ($prodMagID !== null && $prodMagID !== '') {
        return (int)$prodMagID;
    }
    return getMagIdDefault($conn);
}

/**
 * Numarul de zecimale pentru cantitate (tblSet.NrZecCant): 0, 1 sau 2.
 * Implicit 1 cand setarea lipseste sau este invalida.
 *
 * @param resource $conn
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
 *
 * @param resource $conn
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
 * Tipul discountului (tblSet.TipDiscount): 0 = complet, 1 = doar procent la
 * subtotal, 2 = procent fix la subtotal. Implicit 0 cand lipseste/invalid.
 *
 * @param resource $conn
 */
function getTipDiscount($conn) {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = 0;
    $stmt = @sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'TipDiscount'");
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
 * Procentul fix de discount (tblSet.ProcentDiscountFix) folosit cand
 * TipDiscount = 2. Implicit 10, limitat la 0..100.
 *
 * @param resource $conn
 */
function getProcentDiscountFix($conn) {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = 10.0;
    $stmt = @sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'ProcentDiscountFix'");
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row && trim((string)$row['Value']) !== '') {
            $v = (float)str_replace(',', '.', trim((string)$row['Value']));
            if ($v >= 0 && $v <= 100) { $cache = $v; }
        }
    }
    return $cache;
}

/**
 * Parola de discount din tblParola.ParolaDiscount (string; '' = nu se cere).
 *
 * @param resource $conn
 */
function getDiscountParola($conn) {
    $stmt = @sqlsrv_query($conn, "SELECT TOP 1 ParolaDiscount FROM tblParola");
    $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    return $row ? trim((string)($row['ParolaDiscount'] ?? '')) : '';
}

/**
 * Parola de stornare din tblParola.ParolaStornare (string; '' = nu se cere).
 * Cand este goala, anularea liniilor trimise nu cere nici parola, nici motiv.
 *
 * @param resource $conn
 */
function getStornoParola($conn) {
    $stmt = @sqlsrv_query($conn, "SELECT TOP 1 ParolaStornare FROM tblParola");
    $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    return $row ? trim((string)($row['ParolaStornare'] ?? '')) : '';
}

/**
 * Repartizeaza o suma (in centi) pe o lista de ponderi (in centi), folosind
 * metoda celui mai mare rest, astfel incat suma alocata sa fie exact $amount
 * si nicio alocare sa nu depaseasca ponderea corespunzatoare.
 *
 * @param int $amount
 * @param array $weights
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
 * Cumuleaza pozitiile identice ale unui bon de sectie: acelasi produs
 * (ProdID) si aceleasi moduri de preparare. Cantitatile se aduna, iar o
 * pozitie anulata integral (cantitate neta 0) dispare. Se pastreaza ordinea
 * primei aparitii.
 *
 * @param array $lines
 */
function cumuleazaLiniiBucatarie($lines) {
    $byKey = [];
    foreach ($lines as $ln) {
        $mods = array_values(array_filter(
            array_map('strval', $ln['mods'] ?? []),
            function ($m) { return $m !== ''; }
        ));
        $key = ((int)($ln['prodId'] ?? 0)) . "\x1f" . mb_strtolower(implode("\x1e", $mods));
        if (!isset($byKey[$key])) {
            $byKey[$key] = [
                "cant" => (float)$ln['cant'],
                "denumire" => $ln['denumire'],
                "mods" => $mods
            ];
        } else {
            $byKey[$key]["cant"] += (float)$ln['cant'];
        }
    }

    $out = [];
    foreach ($byKey as $ln) {
        $cant = $ln["cant"];
        if (abs($cant) < 0.0001) { continue; } // anulat integral
        $out[] = [
            "cant" => $cant,
            "denumire" => $ln["denumire"],
            "storno" => ($cant < 0.0),
            "mods" => $ln["mods"]
        ];
    }
    return $out;
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
 *
 * @param resource $conn
 * @param int $docId
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
    $stmtKp = sqlsrv_query($conn, "SELECT NrLogic, Nume FROM tblKP");
    if ($stmtKp) {
        while ($p = sqlsrv_fetch_array($stmtKp, SQLSRV_FETCH_ASSOC)) {
            $printerNames[(int)$p['NrLogic']] = trim((string)($p['Nume'] ?? ''));
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
            "prodId" => $prodId,
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

    // Pozitiile identice se cumuleaza pe fiecare imprimanta (ca pe nota):
    // acelasi produs + aceleasi moduri -> o singura linie cu cantitatea insumata.
    $printedLines = 0;
    foreach ($groups as $kp => $linesG) {
        $groups[$kp] = cumuleazaLiniiBucatarie($linesG);
        $printedLines += count($groups[$kp]);
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

    return ["jobs" => $jobs, "lines" => $printedLines, "nrBon" => $nrBon, "error" => null];
}

/**
 * Numele resursei de blocare (application lock) pentru inchiderea unei note.
 * Este per DocID, ca inchiderile aceleiasi note sa fie serializate.
 *
 * @param int $docId
 */
function closeLockResource($docId) {
    return 'rual_closebill_' . (int)$docId;
}

/**
 * Ia un application lock exclusiv (Session) pe nota, fara asteptare
 * (@LockTimeout = 0). Intoarce true daca a fost obtinut, false altfel
 * (inclusiv la eroare de executie). Folosit ca sa nu se poata rula doua
 * inchideri concurente ale aceleiasi note (dublu-click, doua ferestre).
 *
 * @param resource $conn
 * @param int $docId
 */
function acquireCloseLock($conn, $docId) {
    $sql = "DECLARE @res INT;
            EXEC @res = sp_getapplock @Resource = ?, @LockMode = 'Exclusive',
                                       @LockOwner = 'Session', @LockTimeout = 0;
            SELECT @res AS res;";
    $stmt = sqlsrv_query($conn, $sql, [closeLockResource($docId)]);
    if ($stmt === false) { return false; }
    $res = null;
    // Consumam toate seturile de rezultate, ca conexiunea sa rămână libera
    // pentru interogarile urmatoare.
    do {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row !== null && array_key_exists('res', $row)) {
            $res = (int)$row['res'];
        }
    } while (sqlsrv_next_result($stmt));
    return ($res !== null && $res >= 0);
}

/**
 * Elibereaza application lock-ul luat de acquireCloseLock pe nota.
 *
 * @param resource $conn
 * @param int $docId
 */
function releaseCloseLock($conn, $docId) {
    $stmt = @sqlsrv_query(
        $conn,
        "EXEC sp_releaseapplock @Resource = ?, @LockOwner = 'Session';",
        [closeLockResource($docId)]
    );
    if ($stmt !== false) {
        while (sqlsrv_next_result($stmt)) { /* dreneaza rezultatele */ }
    }
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
 *
 * @param resource $conn
 * @param int $docId
 */
function closeBill($conn, $docId, $plati = [], $cui = '', $opts = []) {
    $docId = (int)$docId;

    // Serializam inchiderile aceleiasi note. Daca o alta cerere inchide deja
    // nota (dublu-click rapid / alta fereastra), nu o lasam sa emita un al
    // doilea bon fiscal sau sa re-taxeze terminalul bancar: raspundem imediat.
    if (!acquireCloseLock($conn, $docId)) {
        return [
            "status" => "error",
            "message" => "Nota se inchide deja",
            "http" => 409,
            "alreadyClosing" => true
        ];
    }
    try {
        return closeBillLocked($conn, $docId, $plati, $cui, $opts);
    } finally {
        releaseCloseLock($conn, $docId);
    }
}

/**
 * Corpul efectiv al inchiderii notei, apelat sub application lock-ul luat de
 * closeBill(). Vezi closeBill() pentru descriere.
 *
 * @param resource $conn
 * @param int $docId
 */
function closeBillLocked($conn, $docId, $plati = [], $cui = '', $opts = []) {
    $docId = (int)$docId;
    if (!is_array($plati)) { $plati = []; }
    if (!is_array($opts)) { $opts = []; }
    $cui = normalizeCui($cui);
    // Codul fiscal e optional, dar daca e completat trebuie sa fie valid.
    if ($cui !== '' && !validateCui($cui)) {
        return ["status" => "error", "message" => "Cod fiscal (CUI) invalid: " . $cui, "http" => 400];
    }

    $sqlBon = "SELECT TOP 1 b.DocID, b.NrDoc, b.NrMasa, b.Ora, b.Data, b.TotalB, b.NrOp, b.ClientID,
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

    // Asteptarea raspunsului casei de marcat (cand tblSet.AsteaptaRaspunsCasa = 1
    // si tipul casei este FiscalWire sau FiscalNet): scriem fisierul de comenzi
    // si asteptam confirmarea INAINTE de tranzactia de inchidere, ca sa nu tinem
    // lock-uri DB in timpul asteptarii. Daca registrul nu raspunde / refuza / da
    // eroare, nota rămâne deschisa si nu se scrie nimic in cozi.
    $fiscalInfo = null;
    $skipFiscal = !empty($opts['skipFiscal']);
    $tipCasa = getTipCasaMarcat($conn);
    if (!$skipFiscal
        && getAsteaptaRaspunsCasa($conn) === 1
        && in_array($tipCasa, ['fiscalwire', 'fiscalnet'], true)) {
        $data = buildBillPrintData($conn, $docId, $bon, $plati, "NOTA DE PLATA");
        if ($data === false) {
            return ["status" => "error", "message" => "Eroare construire date bon fiscal", "http" => 500];
        }
        $fiscalItems = getBonFiscalDiscStorno($conn) ? $data['fiscalItems'] : $data['fiscalItemsNet'];
        $fiscalLines = buildFiscalText($fiscalItems, $data['plati'], $tipCasa, getTvaNrMap($conn), $cui);
        $wait = fiscalSendAndWait($conn, $docId, $bon, $tipCasa, $fiscalLines);
        if (!$wait['ok']) {
            return ["status" => "error", "message" => $wait['message'], "http" => 409];
        }
        $skipFiscal = true;
        $opts['fiscalNr'] = $wait['nr'];
        $opts['fiscalZ'] = $wait['z'];
        $opts['fiscalBf'] = $wait['bf'];
        $opts['fiscalRaw'] = (string)($wait['raw'] ?? '');
        $fiscalInfo = ["z" => $wait['z'], "nr" => $wait['nr'], "bf" => $wait['bf'], "comanda" => $wait['comanda']];
    }

    // Inchidem nota si salvam platile (forme de plata + sume) in trelDocIDFpID,
    // totul intr-o singura tranzactie. Tabelele de coada se asigura inainte de
    // tranzactie, ca un ALTER/CREATE sa nu afecteze business-ul.
    ensurePrintQueueTable($conn);
    ensureSendSqlTable($conn);
    ensureCodFiscBonColumns($conn);
    ensureRaspunsCasaColumns($conn);
    sqlsrv_begin_transaction($conn);

    // Marcam bonul ca inchis (Stare = 'I'). Conditia Stare = 'D' face
    // operatiunea idempotenta: daca nota a fost inchisa intre timp (sub lock
    // ar fi imposibil, dar pastram plasa de siguranta), nu mai raportam succes
    // cu tiparire/export si nu emitem nimic dublu. Codul fiscal al clientului
    // (daca a fost introdus) si raspunsul casei de marcat (cand
    // AsteaptaRaspunsCasa = 1) se salveaza odata cu inchiderea; o valoare goala
    // nu sterge una deja salvata pe nota.
    $raspunsCasa = array_key_exists('fiscalRaw', $opts) ? (string)$opts['fiscalRaw'] : '';
    $sqlClose = "UPDATE tblBonCurent SET Stare = 'I', Ora = GETDATE(),
                        CodFiscBon = COALESCE(NULLIF(?, ''), CodFiscBon),
                        RaspunsCasa = COALESCE(NULLIF(?, ''), RaspunsCasa)
                 WHERE DocID = ? AND Stare = 'D'";
    $upd = sqlsrv_query($conn, $sqlClose, [$cui, $raspunsCasa, $docId]);
    if ($upd === false) {
        sqlsrv_rollback($conn);
        return ["status" => "error", "message" => "Eroare inchidere nota: " . sqlsrv_errors()[0]['message'], "http" => 500];
    }
    if (sqlsrv_rows_affected($upd) === 0) {
        sqlsrv_rollback($conn);
        return ["status" => "success", "message" => "Nota era deja inchisa", "docId" => $docId, "alreadyClosed" => true];
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
    $printResult = enqueueBillPrintJobs($conn, $docId, $bon, $plati, $cui, $skipFiscal);
    if ($printResult === false) {
        $printErr = sqlsrv_errors();
        sqlsrv_rollback($conn);
        $printMsg = $printErr ? $printErr[0]['message'] : 'eroare necunoscuta';
        return ["status" => "error", "message" => "Eroare inscriere in coada de tiparire: " . $printMsg, "http" => 500];
    }

    // Scriem, tot in aceeasi tranzactie, comanda de export catre serverul extern
    // (temp_Send_Sql, Preluat = 0). Serviciul Python (sync-service) o executa pe
    // serverul din tblConectare si marcheaza Preluat = 1.
    $sendResult = enqueueBillSendSql(
        $conn,
        $docId,
        $bon,
        $plati,
        array_key_exists('fiscalNr', $opts) ? $opts['fiscalNr'] : null,
        array_key_exists('fiscalZ', $opts) ? $opts['fiscalZ'] : null,
        array_key_exists('fiscalRaw', $opts) ? $opts['fiscalRaw'] : null
    );
    if ($sendResult === false) {
        $sendErr = sqlsrv_errors();
        sqlsrv_rollback($conn);
        $sendMsg = $sendErr ? $sendErr[0]['message'] : 'eroare necunoscuta';
        return ["status" => "error", "message" => "Eroare inscriere coada server: " . $sendMsg, "http" => 500];
    }

    sqlsrv_commit($conn);
    wakePrintService();
    if ($sendResult !== null) {
        wakeSyncService();
    }

    return [
        "status" => "success",
        "message" => "Bonul a fost inchis",
        "docId" => $docId,
        "print" => $printResult,
        "send" => $sendResult,
        "fiscal" => $fiscalInfo
    ];
}

/**
 * Construieste datele de tiparire pentru o nota: payload-ul notei (nefiscale)
 * si liniile fiscale. $title difera intre nota de plata ("NOTA DE PLATA") si
 * proforma ("NOTA PROFORMA"). Intoarce array sau false la eroare.
 *
 * @param resource $conn
 * @param int $docId
 * @param array|null $bon
 * @param array $plati
 * @param string $title
 */
function buildBillPrintData($conn, $docId, $bon, $plati, $title, $isProforma = false) {
    // 1. Randurile de header/footer ale notei (tblAntet): H1-H3 = antet, F1-F2 = subsol.
    //    Proforma mai are si P1-P2 (footer suplimentar, valabil doar pentru proforma).
    //    Pastram si fontul/size/bold ca sa fie respectate la tiparire.
    $headerLines = [];
    $footerLines = [];
    $stmtHF = sqlsrv_query($conn, "SELECT Seria, Nume, NumeFont, Size, Bold
                                   FROM tblAntet WHERE Seria IN ('H1','H2','H3','F1','F2','P1','P2')");
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
        // Footer suplimentar doar pentru proforma.
        if ($isProforma) {
            foreach (['P1', 'P2'] as $k) { if (!empty($tmp[$k])) { $footerLines[] = $tmp[$k]; } }
        }
    }

    // 2. Liniile notei (fara modurile de preparare). Liniile identice (acelasi
    //    produs) se cumuleaza: cantitatile si valorile se aduna, iar randurile
    //    de storno (Cant negativ) se scad din linia produsului, deci nu mai apar
    //    ca pozitii separate pe nota.
    $items = [];
    $itemsByKey = [];
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
        $cotaKey = (string)$cota;
        if (!isset($tvaByCota[$cotaKey])) { $tvaByCota[$cotaKey] = 0.0; }
        $tvaByCota[$cotaKey] += $tva;

        $den = trim((string)$r['Denumire']);
        // O linie are discount "pe produs" (nu pe subtotal) daca are [Comment]
        // (motivul discountului de linie). Doar acestea se evidentiaza pe produs.
        $discountLinie = (trim((string)($r['Comment'] ?? '')) !== '');

        // Cumulare pe produs (cheie = ProdID). Storno (Cant negativ) se scade.
        $key = ($prodId > 0) ? ('p' . $prodId) : ('n' . mb_strtolower($den));
        if (!isset($itemsByKey[$key])) {
            $itemsByKey[$key] = [
                "cant" => $cant,
                "denumire" => $den,
                "valoare" => $val,
                "valoareOriginala" => $valOrig,
                "tva" => $cota,
                "discountLinie" => $discountLinie
            ];
        } else {
            $itemsByKey[$key]["cant"] += $cant;
            $itemsByKey[$key]["valoare"] += $val;
            $itemsByKey[$key]["valoareOriginala"] += $valOrig;
            if ($discountLinie) { $itemsByKey[$key]["discountLinie"] = true; }
        }

        $fiscalItems[] = [
            "cant" => $cant,
            "denumire" => $den,
            "pret" => $pv,
            "pvc" => $pvc,
            "cota" => $cota,
            "discountLinie" => $discountLinie
        ];
    }

    // Liniile cumulate (sarim peste produsele anulate integral -> cantitate 0).
    $fiscalItemsNet = [];
    foreach ($itemsByKey as $it) {
        if (abs($it["cant"]) < 0.0001) { continue; }
        $it["cant"] = round($it["cant"], 3);
        $it["valoare"] = round($it["valoare"], 2);
        $it["valoareOriginala"] = round($it["valoareOriginala"], 2);
        $items[] = $it;

        // Varianta "fara discount si fara stornare" pentru casa de marcat:
        // o singura linie per produs, cu cantitatea neta (storno deja scazut)
        // si pretul net (discountul inclus in pret, fara comanda separata).
        $pretNet = ($it["cant"] != 0.0) ? ($it["valoare"] / $it["cant"]) : 0.0;
        $fiscalItemsNet[] = [
            "cant" => $it["cant"],
            "denumire" => $it["denumire"],
            "pret" => $pretNet,
            "pvc" => $pretNet,
            "cota" => $it["tva"],
            "discountLinie" => false
        ];
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
        $tvaList[] = ["cota" => (float)$cota, "suma" => round($suma, 2)];
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
        "fiscalItemsNet" => $fiscalItemsNet,
        "plati" => $platiArr
    ];
}

/**
 * Scrie in coada de tiparire nota de plata (nefiscala) si fisierul fiscal al
 * casei de marcat. Ruleaza in tranzactia apelantului (closeBill) si nu face
 * commit. Intoarce array-ul cu JobID-uri sau false la eroare.
 *
 * @param resource $conn
 * @param int $docId
 * @param array|null $bon
 * @param array $plati
 */
function enqueueBillPrintJobs($conn, $docId, $bon, $plati, $cui = '', $skipFiscal = false) {
    $fastfood = (getTipVanz($conn) === "fastfood");

    $data = buildBillPrintData($conn, $docId, $bon, $plati, "NOTA DE PLATA");
    if ($data === false) { return false; }

    // Mod FastFood: nota de plata nu se tipareste; ramane doar bonul fiscal.
    $notaJob = null;
    if (!$fastfood) {
        $notaJob = enqueuePrintJob($conn, 'nota', $docId, null, $data['nota']);
        if ($notaJob === false) { return false; }
    }

    // Bonul fiscal a fost deja emis direct de casa de marcat (cand
    // AsteaptaRaspunsCasa = 1): nu se mai pune in coada un job fiscal.
    if ($skipFiscal) {
        return ["nota" => $notaJob, "fiscal" => null];
    }

    // Implicit bonul fiscal nu arata discountul si nici stornarea: se folosesc
    // cantitatile/preturile nete (fara comenzi de discount, fara linii de
    // storno). Setarea tblSet.BonFiscalDiscStorno = 1 reactiveaza modul detaliat.
    $tipCasa = getTipCasaMarcat($conn);
    $fiscalItems = getBonFiscalDiscStorno($conn) ? $data['fiscalItems'] : $data['fiscalItemsNet'];
    $fiscalLines = buildFiscalText($fiscalItems, $data['plati'], $tipCasa, getTvaNrMap($conn), $cui);
    // FiscalWire (si Fisco/fprwin, inca neimplementate) folosesc fisiere .inp, FiscalNet .txt.
    $fiscalExt = ($tipCasa === 'fiscalnet') ? '.txt' : '.inp';
    $fiscalPayload = [
        "lines" => $fiscalLines,
        "filename" => "doc" . (int)$docId . "_" . date('YmdHis') . $fiscalExt
    ];
    // Folderul monitorizat de driver (tblSet.CaleFisierComenziECR): serviciul de
    // tiparire copiaza fisierul de comenzi si acolo, ca sa fie procesat.
    $caleComenzi = getCaleFisierComenziECR($conn);
    if ($caleComenzi !== '') { $fiscalPayload["copy_dir"] = $caleComenzi; }
    $fiscalJob = enqueuePrintJob($conn, 'fiscal', $docId, null, $fiscalPayload);
    if ($fiscalJob === false) { return false; }

    return ["nota" => $notaJob, "fiscal" => $fiscalJob];
}

/**
 * Harta cotei de TVA -> Nr_TVA pentru casa de marcat (tblTVA). In procedura
 * veche: Nr_TVA = DLookup("Nr_TVA","tblTVA","Cota=" & TVAc). Ex. la aceasta
 * instalare: 1 = 21%, 2 = 11%, 3 = 0%.
 *
 * @param resource $conn
 */
function getTvaNrMap($conn) {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = [];
    $stmt = sqlsrv_query($conn, "SELECT Nr_TVA, Cota FROM tblTVA");
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $cache[(string)(int)round((float)$r['Cota'])] = (int)$r['Nr_TVA'];
        }
    }
    return $cache;
}

/**
 * Nr_TVA-ul casei de marcat pentru o cota de TVA. Fallback 1 (ca Nz(...,1)
 * din procedura veche) cand cota nu exista in tblTVA.
 *
 * @param array $tvaMap
 * @param float $cota
 */
function tvaNrFor($tvaMap, $cota) {
    $k = (string)(int)round((float)$cota);
    return isset($tvaMap[$k]) ? (int)$tvaMap[$k] : 1;
}

/**
 * Codul de plata pentru casa de marcat (Datecs). Se foloseste direct FPID-ul
 * din tblFP (transmis in $platiArr), care este enumerarea aplicatiei
 * (0 = Numerar, 1 = Card, 2 = CEC, 3 = Tichet, 4 = OP, 5 = Voucher).
 * Fallback pe denumire doar cand FPID lipseste sau nu e numeric.
 */
function fiscalPaymentCode(array $plata) {
    $fpid = $plata['fpid'] ?? null;
    if ($fpid !== null && $fpid !== '' && is_numeric($fpid) && (int)$fpid >= 0) {
        return (int)$fpid;
    }
    return fiscalPaymentCodeByName((string)($plata['denumire'] ?? ''));
}

/**
 * Rezerva: codul de plata dedus din denumirea formei de plata, folosit doar
 * cand FPID-ul lipseste.
 *
 * @param string $denumire
 */
function fiscalPaymentCodeByName($denumire) {
    $d = mb_strtolower((string)$denumire);
    if (mb_strpos($d, 'card') !== false) { return 2; }
    if (mb_strpos($d, 'ticket') !== false || mb_strpos($d, 'bon') !== false) { return 3; }
    if (mb_strpos($d, 'numerar') !== false || mb_strpos($d, 'cash') !== false) { return 1; }
    return 1;
}

/**
 * Normalizeaza codul fiscal al clientului (CUI): majuscule, fara spatii si
 * fara alte caractere decat litere/cifre (ex. "ro 12 345" -> "RO12345").
 * Intoarce '' cand nu ramane nimic valid.
 *
 * @param string $raw
 */
function normalizeCui($raw) {
    $t = strtoupper(trim((string)$raw));
    $t = preg_replace('/[^A-Z0-9]/', '', $t);
    return mb_substr($t, 0, 14);
}

/**
 * Valideaza codul fiscal (CUI/CIF) prin cifra de control (algoritmul ANAF),
 * ignorand prefixul RO si caracterele non-numerice. Intoarce true/false.
 *
 * @param string $cui
 */
function validateCui($cui) {
    $cui = preg_replace('/[^0-9]/', '', (string)$cui);
    if (strlen($cui) < 2 || strlen($cui) > 10) { return false; }

    $cifraControlOriginala = (int)substr($cui, -1);
    $cuiFaraControl = substr($cui, 0, -1);

    $cheie = [7, 5, 3, 2, 1, 7, 5, 3, 2];
    $cheieAjustata = array_slice($cheie, -strlen($cuiFaraControl));

    $suma = 0;
    $len = strlen($cuiFaraControl);
    for ($i = 0; $i < $len; $i++) {
        $suma += (int)$cuiFaraControl[$i] * $cheieAjustata[$i];
    }

    $rest = ($suma * 10) % 11;
    $cifraControlCalculata = ($rest === 10) ? 0 : $rest;

    return $cifraControlCalculata === $cifraControlOriginala;
}

/**
 * Curata un text pentru fisierul de comenzi Datecs (.inp): elimina separatorul
 * ";" si caracterele de linie noua, il trece in majuscule si il limiteaza la
 * $len caractere. NU se completeaza cu spatii (procedura veche folosea doar
 * Left(UCase(...), N), fara padding), ca fisierul sa coincida cu cel real.
 *
 * @param string $text
 */
function fiscalDatecsText($text, $len = 32) {
    $t = trim((string)$text);
    $t = str_replace(["\r", "\n", ";", ","], [" ", " ", " ", " "], $t);
    return mb_substr(mb_strtoupper($t), 0, $len);
}

/**
 * Construieste fisierul de comenzi Datecs in formatul .inp folosit de
 * FiscalWire (preluat din procedura veche SendtoDatecs):
 *   H,1,______,_,__;                                  (antet)
 *   S,1,______,_,__;DEN;PRET;CANT;1;1;NrTVA;0;0;      (vanzare; storno = cant negativa)
 *   C,1,______,_,__;1;PROCENT;;;;                    (discount pe linie)
 *   T,1,______,_,__;COD_PLATA;;;;;                    (o forma de plata, fara suma)
 *   T,1,______,_,__;COD_PLATA;SUMA;;;;                (mai multe forme, cu suma)
 * (linia F de subsol era comentata in procedura veche, deci nu se scrie)
 * PRET este pretul de catalog (PVC) cu 2 zecimale, CANT are 3 zecimale, iar
 * discountul se scrie ca procent simplu (ex. 5.00 = 5%), conform manualului
 * FiscalWire (pg. 22), nu procent * 100. La o singura forma de plata suma NU se
 * scrie: FiscalWire calculeaza singur totalul (ca in procedura veche).
 *
 * @param array $items
 * @param array $platiArr
 */
function buildFiscalTextDatecs($items, $platiArr, $tvaMap = [], $cui = '') {
    $lines = [];
    $lines[] = "H,1,______,_,__;";

    // Codul fiscal al clientului (CUI), inainte de prima linie de vanzare.
    if ($cui !== '') {
        $lines[] = "K,1,______,_,__;{$cui};";
    }

    $hasItems = false;
    foreach ($items as $it) {
        $cant = (float)$it['cant'];
        if (abs($cant) < 0.0001) { continue; }
        $hasItems = true;

        $pv = (float)$it['pret'];
        $pvc = (float)($it['pvc'] ?? $it['pret']);
        $den = fiscalDatecsText($it['denumire'], 32);
        $nrTva = tvaNrFor($tvaMap, (float)$it['cota']);
        $pret = number_format($pvc, 2, '.', '');
        $q = number_format($cant, 3, '.', '');
        $lines[] = "S,1,______,_,__;{$den};{$pret};{$q};1;1;{$nrTva};0;0;";

        // Discount pe linie. In modul implicit pretul e deja net (PVC = PV),
        // deci nu se scrie nicio comanda C. In modul detaliat se scrie procentul
        // simplu (ex. 5.00 = 5%), conform manualului FiscalWire.
        if ($pvc > 0.0001) {
            $ratio = round(round($pvc - $pv, 2) / $pvc, 4);
            $prRed = 100 * $ratio;
            if ($prRed > 0.0001) {
                $val = number_format($prRed, 2, '.', '');
                $lines[] = "C,1,______,_,__;1;{$val};;;;";
            }
        }
    }

    if (!$hasItems) {
        // Bon fara articole: o singura plata 0 (ca in procedura veche).
        $lines[] = "T,1,______,_,__;0;;;;;";
        return $lines;
    }

    // Platile, in ordine descrescatoare dupa cod (procedura veche: For j = 6 To 0).
    $plati = array_values(array_filter($platiArr, function ($p) {
        return (float)($p['suma'] ?? 0) > 0.0001;
    }));
    usort($plati, function ($a, $b) {
        return fiscalPaymentCode($b) <=> fiscalPaymentCode($a);
    });

    if (count($plati) === 1) {
        // O singura forma de plata: NU scriem suma, FiscalWire calculeaza singur
        // totalul bonului (exact ca in procedura veche SendtoDatecs).
        $lines[] = "T,1,______,_,__;" . fiscalPaymentCode($plati[0]) . ";;;;;";
    } else {
        // Mai multe forme de plata: cate o linie T cu suma fiecarei forme.
        foreach ($plati as $p) {
            $cod = fiscalPaymentCode($p);
            $suma = number_format((float)$p['suma'], 2, '.', '');
            $lines[] = "T,1,______,_,__;{$cod};{$suma};;;;";
        }
    }

    return $lines;
}

/**
 * Construieste liniile de text pentru driver-ul casei de marcat.
 * FiscalWire = formatul .inp (buildFiscalTextDatecs), FiscalNet = formatul dedicat
 * (buildFiscalTextFiscalNet, conform FiscalNet.pdf).
 *
 * @param array $items
 * @param array $platiArr
 */
function buildFiscalText($items, $platiArr, $tipCasa = 'fiscalwire', $tvaMap = [], $cui = '') {
    if ($tipCasa === 'fiscalnet') {
        return buildFiscalTextFiscalNet($items, $platiArr, $tvaMap, $cui);
    }
    return buildFiscalTextDatecs($items, $platiArr, $tvaMap, $cui);
}

// --------------------------------------------------------------------------
// Asteptarea raspunsului casei de marcat FiscalWire (AsteaptaRaspunsCasa = 1)
// --------------------------------------------------------------------------

/**
 * Scrie fisierul de comenzi in folderul monitorizat de driverul FiscalWire.
 * Scriere atomica (tmp + rename), codificare Windows-1250 (fallback ASCII),
 * linii separate cu CRLF, ca sa nu fie citit un fisier partial de catre driver.
 *
 * @param string $dir
 * @param string $filename
 * @param array $lines
 */
function fiscalWireWriteCommand($dir, $filename, $lines) {
    $text = implode("\r\n", $lines);
    $encoded = @iconv('UTF-8', 'Windows-1250//TRANSLIT', $text);
    if ($encoded === false) {
        $encoded = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($encoded === false) { $encoded = $text; }
    }

    $path = rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . $filename;
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $encoded) === false) { return false; }
    if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
    return true;
}

/**
 * Sterge fisierul de comenzi scris de noi (si eventualul .tmp ramas). Se foloseste
 * cand inchiderea notei esueaza (casa nu raspunde / eroare / refuz), ca FiscalWire
 * sa nu gaseasca mai tarziu comanda si sa tipareasca un bon abandonat.
 * Intoarce true daca fisierul principal nu mai exista dupa operatie.
 *
 * @param string $dir
 * @param string $filename
 */
function fiscalWireRemoveCommand($dir, $filename) {
    $path = rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . $filename;
    @unlink($path . '.tmp');
    @unlink($path);
    return !is_file($path);
}

/**
 * Curata comenzile abandonate (ramase dupa crash / reboot / oprire brusca a
 * PHP-ului in timpul asteptarii). Sterge fisierele pos_tm_*.inp (si .tmp) mai
 * vechi de $minAgeSec secunde, ca un bon abandonat sa nu ajunga la registru
 * cand FiscalWire porneste ulterior. Best-effort.
 *
 * @param string $dir
 * @param int $minAgeSec
 */
function fiscalWireCleanupStale($dir, $minAgeSec) {
    $minAgeSec = max(0, (int)$minAgeSec);
    $dir = rtrim($dir, "\\/");
    if ($dir === '' || !is_dir($dir)) { return; }
    $now = time();
    $files = @scandir($dir);
    if ($files === false) { return; }
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') { continue; }
        if (strncmp($f, 'pos_tm_', 7) !== 0) { continue; }
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if ($ext !== 'inp' && $ext !== 'tmp' && $ext !== 'txt') { continue; }
        $full = $dir . DIRECTORY_SEPARATOR . $f;
        if (!is_file($full)) { continue; }
        $mtime = @filemtime($full);
        if ($mtime !== false && ($now - $mtime) >= $minAgeSec) {
            @unlink($full);
        }
    }
}

/**
 * Log best-effort (doar esecuri) in logs/fiscal_wait.log: casa de marcat nu a
 * raspuns (timeout), a refuzat bonul (CANCELED) sau a dat eroare (linii Er).
 * $comandaStearsa = true/false cand stim daca am sters fisierul de comenzi.
 *
 * @param int $docId
 * @param array|null $bon
 * @param string $comanda
 * @param string $rezultat
 * @param string $detaliu
 */
function fiscalWaitLog($docId, $bon, $comanda, $rezultat, $detaliu, $comandaStearsa = null) {
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    $line = sprintf(
        "[%s] DocID=%d NrDoc=%s comanda=%s rezultat=%s detaliu=\"%s\"",
        date('Y-m-d H:i:s'),
        (int)$docId,
        (string)($bon['NrDoc'] ?? ''),
        ($comanda === '' ? '-' : $comanda),
        $rezultat,
        str_replace(["\r", "\n", '"'], [' ', ' ', "'"], (string)$detaliu)
    );
    if ($comandaStearsa !== null) {
        $line .= " comanda_stearsa=" . ($comandaStearsa ? 'da' : 'nu');
    }
    @file_put_contents($dir . DIRECTORY_SEPARATOR . 'fiscal_wait.log', $line . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Parseaza fisierul de confirmare .nrb emis de FiscalWire. Prima linie are
 * forma: OK;Z=<z>|NR=<nr>|UNIQUE_ID=<fisier>|<dataora>|BF=<bf> sau CANCELED;...
 * Intoarce ['ok'=>true,'z','nr','bf'] sau ['ok'=>false,'reason','message'].
 *
 * @param string $raw
 */
function fiscalWireParseNrb($raw) {
    $text = str_replace("\r", "", (string)$raw);
    $parts = explode("\n", $text, 2);
    $firstLine = $parts[0] ?? '';
    $status = strtoupper(trim((string)strtok($firstLine, ';')));

    if ($status === 'CANCELED') {
        return ['ok' => false, 'reason' => 'refuz', 'message' => 'Casa de marcat a anulat bonul (CANCELED).'];
    }
    if ($status !== 'OK') {
        return ['ok' => false, 'reason' => 'eroare',
                'message' => 'Raspuns necunoscut al casei de marcat: ' . trim($firstLine)];
    }

    $z = null; $nr = null; $bf = null;
    if (preg_match('/Z=(\d+)/', $firstLine, $m)) { $z = (int)$m[1]; }
    if (preg_match('/NR=(\d+)/', $firstLine, $m)) { $nr = (int)$m[1]; }
    if (preg_match('/BF=(\d+)/', $firstLine, $m)) { $bf = (int)$m[1]; }
    if ($nr === null) {
        return ['ok' => false, 'reason' => 'eroare',
                'message' => 'Fisierul de confirmare nu contine numarul bonului fiscal (NR).'];
    }
    // Pastram continutul brut al .nrb (pentru audit / export / POS bancar).
    return ['ok' => true, 'z' => $z, 'nr' => $nr, 'bf' => $bf, 'raw' => (string)$raw];
}

/**
 * Detecteaza prima linie cu status "Er" din fisierul de raspuns .txt (copie a
 * bonului cu statusul fiecarei linii). Intoarce detaliul erorii sau null.
 *
 * @param string $raw
 */
function fiscalWireTxtError($raw) {
    $text = str_replace("\r", "", (string)$raw);
    foreach (explode("\n", $text) as $line) {
        if ($line === '') { continue; }
        if (preg_match('/,\s*Er\s*[;,$]/i', $line) || preg_match('/,\s*Er\s*$/i', $line)) {
            $pos = strpos($line, ';');
            $rest = ($pos !== false) ? substr($line, $pos + 1) : '';
            $chunks = array_values(array_filter(array_map('trim', explode(';', $rest)), function ($s) {
                return $s !== '';
            }));
            $code = !empty($chunks) ? (string)end($chunks) : '';
            $code = trim($code, " ,;\t");
            return ($code !== '') ? $code : 'linie de comanda respinsa';
        }
    }
    return null;
}

/**
 * Asteapta in folderul de raspuns fisierul generat de FiscalWire pentru comanda
 * noastra (corelare dupa prefixul numelui comenzii si UNIQUE_ID din .nrb).
 * Gata de testat cu tools/mock_fiscalwire.py.
 *
 * @param string $raspunsDir
 * @param string $cmdBase
 * @param string $cmdName
 * @param int $sentAt
 * @param int $timeoutSec
 */
function fiscalWireWaitResponse($raspunsDir, $cmdBase, $cmdName, $sentAt, $timeoutSec) {
    $timeoutSec = max(1, (int)$timeoutSec);
    $deadline = microtime(true) + $timeoutSec;
    $dir = rtrim($raspunsDir, "\\/");

    while (true) {
        $txtErr = null;
        $files = @scandir($dir);
        if ($files !== false) {
            foreach ($files as $f) {
                if ($f === '.' || $f === '..') { continue; }
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if ($ext !== 'nrb' && $ext !== 'txt') { continue; }
                // Corelare: numele raspunsului incepe cu numele comenzii fara ext.
                if (strncmp($f, $cmdBase, strlen($cmdBase)) !== 0) { continue; }
                $full = $dir . DIRECTORY_SEPARATOR . $f;
                if (!is_file($full)) { continue; }
                $mtime = @filemtime($full);
                if ($mtime !== false && $mtime + 5 < $sentAt) { continue; }

                if ($ext === 'nrb') {
                    $raw = @file_get_contents($full);
                    if ($raw === false || $raw === '') { continue; }
                    if (strpos($raw, 'UNIQUE_ID=' . $cmdName) === false
                        && strpos($raw, 'UNIQUE_ID=' . $cmdBase) === false) {
                        continue;
                    }
                    return fiscalWireParseNrb($raw);
                }

                $raw = @file_get_contents($full);
                if ($raw === false) { continue; }
                $err = fiscalWireTxtError($raw);
                if ($err !== null) { $txtErr = $err; }
            }
        }

        if ($txtErr !== null) {
            return ['ok' => false, 'reason' => 'eroare',
                    'message' => 'Casa de marcat a raportat o eroare: ' . $txtErr];
        }
        if (microtime(true) >= $deadline) { break; }
        usleep(300000);
    }

    return ['ok' => false, 'reason' => 'timeout',
            'message' => 'Casa de marcat nu a raspuns in ' . $timeoutSec
                       . ' secunde (nu s-a generat fisierul de confirmare).'];
}

/**
 * Parseaza fisierul de raspuns FiscalNet (acelasi nume ca al comenzii, in
 * directorul de raspuns). Liniile: "BONOK=1" (ok) / "BONOK=0" (eroare) /
 * "BONOK=-1" (nu s-a putut identifica raspunsul terminalului bancar), apoi
 * "NRBON=<nr>" (la succes) sau "ERRCODE=<cod>" + "ERRINFO=<info>" (la eroare).
 * Intoarce ['ok'=>true,'z','nr','bf','raw'] sau ['ok'=>false,'reason','message'].
 *
 * @param string $raw
 */
function fiscalNetParseResponse($raw) {
    $text = str_replace("\r", "", (string)$raw);
    $map = [];
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line === '') { continue; }
        $pos = strpos($line, '=');
        if ($pos === false) { continue; }
        $map[strtoupper(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
    }

    $bonok = $map['BONOK'] ?? null;
    if ($bonok === '1') {
        $nr = (isset($map['NRBON']) && $map['NRBON'] !== '') ? (int)$map['NRBON'] : null;
        return ['ok' => true, 'z' => null, 'nr' => $nr, 'bf' => null, 'raw' => (string)$raw];
    }
    if ($bonok === '-1') {
        return ['ok' => false, 'reason' => 'eroare',
                'message' => 'Casa de marcat nu a putut identifica raspunsul terminalului bancar (BONOK=-1).'];
    }
    if ($bonok === '0') {
        $err = trim(($map['ERRCODE'] ?? '') . ' ' . ($map['ERRINFO'] ?? ''));
        return ['ok' => false, 'reason' => 'eroare',
                'message' => 'Casa de marcat a raportat o eroare: ' . ($err !== '' ? $err : 'bon incorect')];
    }
    return ['ok' => false, 'reason' => 'eroare',
            'message' => 'Raspuns necunoscut al casei de marcat: ' . trim($text)];
}

/**
 * Asteapta in folderul de raspuns fisierul generat de FiscalNet pentru comanda
 * noastra (acelasi nume, corelat dupa prefixul numelui comenzii).
 *
 * @param string $raspunsDir
 * @param string $cmdBase
 * @param int $sentAt
 * @param int $timeoutSec
 */
function fiscalNetWaitResponse($raspunsDir, $cmdBase, $sentAt, $timeoutSec) {
    $timeoutSec = max(1, (int)$timeoutSec);
    $deadline = microtime(true) + $timeoutSec;
    $dir = rtrim($raspunsDir, "\\/");

    while (true) {
        $files = @scandir($dir);
        if ($files !== false) {
            foreach ($files as $f) {
                if ($f === '.' || $f === '..') { continue; }
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if ($ext !== 'txt') { continue; }
                if (strncmp($f, $cmdBase, strlen($cmdBase)) !== 0) { continue; }
                $full = $dir . DIRECTORY_SEPARATOR . $f;
                if (!is_file($full)) { continue; }
                $mtime = @filemtime($full);
                if ($mtime !== false && $mtime + 5 < $sentAt) { continue; }
                $raw = @file_get_contents($full);
                if ($raw === false || $raw === '') { continue; }
                // Fisierul poate fi scris incremental: asteptam linia BONOK=.
                if (stripos($raw, 'BONOK=') === false) { continue; }
                return fiscalNetParseResponse($raw);
            }
        }
        if (microtime(true) >= $deadline) { break; }
        usleep(300000);
    }

    return ['ok' => false, 'reason' => 'timeout',
            'message' => 'Casa de marcat nu a raspuns in ' . $timeoutSec
                       . ' secunde (nu s-a generat fisierul de raspuns).'];
}

/**
 * Trimite bonul fiscal catre casa de marcat (FiscalWire sau FiscalNet) si
 * asteapta confirmarea. Se apeleaza inainte de tranzactia de inchidere a notei,
 * ca sa nu tinem lock-uri DB in timpul asteptarii. Scrie in log la orice esec.
 * Intoarce ['ok'=>true,'z','nr','bf','raw','comanda'] sau
 * ['ok'=>false,'reason','message','comanda'].
 *
 * @param resource $conn
 * @param int $docId
 * @param array|null $bon
 * @param string $tipCasa
 * @param array $fiscalLines
 */
/**
 * Sterge din folderul de raspuns fisierele care poarta exact baza comenzii pe
 * care o trimitem acum.
 *
 * Numele comenzii are rezolutia de o secunda (`pos_tm_<DocID>_<YmdHis>`), deci
 * doua incercari de inchidere a ACELEIASI note in aceeasi secunda (ex. OK apasat
 * de doua ori foarte repede dupa un esec) folosesc acelasi nume - iar a doua ar
 * citi instant raspunsul primei in loc sa trimita o comanda noua. Stergem
 * preventiv acele fisiere, ca raspunsul folosit sa fie mereu al acestei comenzi.
 *
 * @param string $raspunsDir
 * @param string $cmdBase
 * @return int numarul de fisiere sterse
 */
function fiscalRemoveResponses($raspunsDir, $cmdBase) {
    $dir = rtrim($raspunsDir, "\\/");
    $files = @scandir($dir);
    if ($files === false) { return 0; }
    $n = 0;
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') { continue; }
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if ($ext !== 'nrb' && $ext !== 'txt') { continue; }
        if (strncmp($f, $cmdBase, strlen($cmdBase)) !== 0) { continue; }
        $full = $dir . DIRECTORY_SEPARATOR . $f;
        if (is_file($full) && @unlink($full)) { $n++; }
    }
    return $n;
}

function fiscalSendAndWait($conn, $docId, $bon, $tipCasa, $fiscalLines) {
    // Asteptarea poate depasi max_execution_time implicit; o permitem explicit
    // (ca la api/pos_banca.php).
    @set_time_limit(0);
    @ignore_user_abort(true);

    $comenziDir = getCaleFisierComenziECR($conn);
    $raspunsDir = getCaleFisierRaspunsECR($conn);

    if ($comenziDir === '' || !is_dir($comenziDir)) {
        $msg = "Folderul de comenzi al casei de marcat nu este configurat sau nu exista: " . $comenziDir;
        fiscalWaitLog($docId, $bon, '', 'eroare', $msg);
        return ['ok' => false, 'reason' => 'eroare', 'message' => $msg, 'comanda' => ''];
    }
    if ($raspunsDir === '' || !is_dir($raspunsDir)) {
        $msg = "Folderul de raspuns al casei de marcat nu este configurat sau nu exista: " . $raspunsDir;
        fiscalWaitLog($docId, $bon, '', 'eroare', $msg);
        return ['ok' => false, 'reason' => 'eroare', 'message' => $msg, 'comanda' => ''];
    }

    $fiscalnet = ($tipCasa === 'fiscalnet');
    $cmdBase = 'pos_tm_' . (int)$docId . '_' . date('YmdHis');
    $cmdName = $cmdBase . ($fiscalnet ? '.txt' : '.inp');
    $sentAt = time();

    // Curatam intai comenzile abandonate (ramase de la un crash / reboot / o
    // oprire brusca a PHP-ului), ca sa nu fie tiparite la pornirea registrului.
    fiscalWireCleanupStale($comenziDir, max(getAsteptareCasaSec($conn), 120));

    // Si raspunsurile care ar purta exact baza acestei comenzi (vezi
    // fiscalRemoveResponses): altfel o reincercare in aceeasi secunda ar citi
    // raspunsul incercarii anterioare in loc sa trimita comanda noua.
    fiscalRemoveResponses($raspunsDir, $cmdBase);

    if (!fiscalWireWriteCommand($comenziDir, $cmdName, $fiscalLines)) {
        $msg = "Nu pot scrie fisierul de comenzi al casei de marcat: " . $cmdName;
        fiscalWaitLog($docId, $bon, $cmdName, 'eroare', $msg);
        return ['ok' => false, 'reason' => 'eroare', 'message' => $msg, 'comanda' => $cmdName];
    }

    $timeout = getAsteptareCasaSec($conn);
    $result = $fiscalnet
        ? fiscalNetWaitResponse($raspunsDir, $cmdBase, $sentAt, $timeout)
        : fiscalWireWaitResponse($raspunsDir, $cmdBase, $cmdName, $sentAt, $timeout);
    $result['comanda'] = $cmdName;

    // La timeout mai acordam o scurta perioada de gratie (un raspuns poate veni
    // chiar la limita), inainte de a sterge comanda. Daca si atunci e timeout,
    // pastram rezultatul initial, ca mesajul sa arate timeout-ul configurat.
    if (!$result['ok'] && ($result['reason'] ?? '') === 'timeout') {
        $grace = $fiscalnet
            ? fiscalNetWaitResponse($raspunsDir, $cmdBase, $sentAt, 2)
            : fiscalWireWaitResponse($raspunsDir, $cmdBase, $cmdName, $sentAt, 2);
        $grace['comanda'] = $cmdName;
        if ($grace['ok']) { $result = $grace; }
    }

    if (!$result['ok']) {
        // Nota nu se inchide: stergem fisierul de comenzi ca driverul sa nu
        // gaseasca mai tarziu comanda si sa tipareasca un bon abandonat
        // (desincronizare fata de POS-ul care are nota inca deschisa).
        $stearsa = fiscalWireRemoveCommand($comenziDir, $cmdName);
        fiscalWaitLog($docId, $bon, $cmdName, $result['reason'], $result['message'], $stearsa);
    }
    return $result;
}

/**
 * Codul de plata pentru casa de marcat FiscalNet (comanda P^TipPlata^Valoare).
 * Driverul FiscalNet foloseste coduri 1-based (1 = Numerar, 2 = Card, ...),
 * in timp ce FPID-ul din tblFP este 0-based (0 = Numerar, 1 = Card, 2 = CEC,
 * 3 = Tichet, 4 = OP, 5 = Voucher), deci se aplica offset +1.
 * Fallback pe denumire cand FPID lipseste sau nu e numeric.
 */
function fiscalPaymentCodeFiscalNet(array $plata) {
    $fpid = $plata['fpid'] ?? null;
    if ($fpid !== null && $fpid !== '' && is_numeric($fpid) && (int)$fpid >= 0) {
        return (int)$fpid + 1;
    }
    return fiscalPaymentCodeFiscalNetByName((string)($plata['denumire'] ?? ''));
}

/**
 * Rezerva: codul de plata FiscalNet dedus din denumirea formei de plata,
 * folosit doar cand FPID-ul lipseste. 1 = Numerar, 2 = Card, 3 = Credit,
 * 4 = Tichet masa, 5 = Tichet valoric, 6 = Voucher.
 *
 * @param string $denumire
 */
function fiscalPaymentCodeFiscalNetByName($denumire) {
    $d = mb_strtolower(trim((string)$denumire));
    if (mb_strpos($d, 'card') !== false) { return 2; }
    if (mb_strpos($d, 'tichet masa') !== false || mb_strpos($d, 'ticket masa') !== false) { return 4; }
    if (mb_strpos($d, 'tichet valoric') !== false || mb_strpos($d, 'ticket valoric') !== false) { return 5; }
    if (mb_strpos($d, 'voucher') !== false) { return 6; }
    if (mb_strpos($d, 'credit') !== false) { return 3; }
    if (mb_strpos($d, 'numerar') !== false || mb_strpos($d, 'cash') !== false) { return 1; }
    return 1;
}

/**
 * Formateaza o valoare pentru casa de marcat FiscalNet: numar intreg fara
 * delimitator zecimal, scalat (ex. 10.00 RON -> 1000, 1 -> 1000, 4.15 -> 415).
 * $decimals = 2 pentru pret/discount/plata, 3 pentru cantitate.
 *
 * @param mixed $value
 * @param int $decimals
 */
function fiscalNetAmount($value, $decimals) {
    $factor = ($decimals === 3) ? 1000 : 100;
    return (string)(int)round(((float)$value) * $factor);
}

/**
 * Curata un text destinat fisierului FiscalNet: elimina separatorul "^" si
 * caracterele de linie noua, care ar strica structura comenzii.
 *
 * @param string $text
 */
function fiscalNetText($text) {
    $t = trim((string)$text);
    $t = str_replace(["\r", "\n", "^"], [" ", " ", " "], $t);
    return mb_substr($t, 0, 32);
}

/**
 * Construieste fisierul de comenzi pentru driver-ul FiscalNet (FiscalNet.pdf).
 * Separatorul este "^", iar valorile se scriu fara delimitator zecimal, scalate:
 *   S^Denumire^Pret^Cantitate^UM^GRTVA^GRDEP     (vanzare)
 *   VS^Denumire^Pret^Cantitate^UM^GRTVA^GRDEP    (voidare / storno)
 *   DV^Valoare / MV^Valoare                      (discount/majorare valoric)
 *   ST^                                          (subtotal)
 *   P^TipPlata^Valoare                           (incasare)
 * Comenzile de discount/majorare se scriu obligatoriu imediat sub articolul la
 * care se aplica.
 *
 * @param array $items
 * @param array $platiArr
 */
function buildFiscalTextFiscalNet($items, $platiArr, $tvaMap = [], $cui = '') {
    $lines = [];
    // Codul fiscal al clientului (CUI), inainte de prima linie de vanzare.
    if ($cui !== '') {
        $lines[] = "CF^{$cui}";
    }
    $hasItems = false;

    foreach ($items as $it) {
        $cant = (float)$it['cant'];
        if (abs($cant) < 0.0001) { continue; }
        $hasItems = true;

        $pv = (float)$it['pret'];
        $pvc = (float)($it['pvc'] ?? $it['pret']);
        $den = fiscalNetText($it['denumire']);
        $grupa = tvaNrFor($tvaMap, (float)$it['cota']);
        $pretStr = fiscalNetAmount($pvc, 2);
        $cantStr = fiscalNetAmount(abs($cant), 3);

        // Linie de storno: comanda de voidare (VS), cu cantitate pozitiva.
        if ($cant < 0) {
            $lines[] = "VS^{$den}^{$pretStr}^{$cantStr}^buc^{$grupa}^1";
            continue;
        }

        $lines[] = "S^{$den}^{$pretStr}^{$cantStr}^buc^{$grupa}^1";

        // Discount/majorare pe linie, imediat sub articol (obligatoriu).
        $diff = $pvc - $pv;
        if (abs($diff) >= 0.0001) {
            $valStr = fiscalNetAmount(abs($diff) * abs($cant), 2);
            $lines[] = ($diff > 0 ? "DV^{$valStr}" : "MV^{$valStr}");
        }
    }

    if ($hasItems) {
        $lines[] = "ST^";
    }
    foreach ($platiArr as $p) {
        $cod = fiscalPaymentCodeFiscalNet($p);
        $suma = fiscalNetAmount((float)$p['suma'], 2);
        $lines[] = "P^{$cod}^{$suma}";
    }

    return $lines;
}

$conn = getDBConnection();
ensureOrderSchema($conn);
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
                        COALESCE(tv.Cota, 0) as CotaTVA, p.MagID AS ProdMagID
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
        // Magazia liniei: gestiunea produsului (tblProd.MagID), cu fallback pe
        // prima magazie din tblSet.MagID.
        $lineMagID = getLineMagId($conn, $prod['ProdMagID'] ?? null);

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
                             VALUES (?, GETDATE(), GETDATE(), ?, ?, 0, 'D', ?);
                             SELECT SCOPE_IDENTITY() AS DocID;";
            $stmtNewBon = sqlsrv_query($conn, $sqlInsertBon, [$nextNrDoc, $nrMasa, $nrOp, getMagIdDefault($conn)]);
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
                          VALUES (?, ?, ?, ?, ?, ?, GETDATE(), ?, 0, ?, ?, 0, 0)";
        $stmtIns = sqlsrv_query($conn, $sqlInsertNote, [$docId, $prodId, $cantitate, $pret, $pret, $cotaTva, $lineMagID, $denumire, $nrGrp]);
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
                   VALUES (?, 0, 0, 0, 0, 0, GETDATE(), ?, 0, ?, ?, 0, 0)";
        $stmtIns = sqlsrv_query($conn, $sqlIns, [$docId, getMagIdDefault($conn), $textMesaj, $nrMesaj]);
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

    case 'set_cui':
        // Salveaza codul fiscal al clientului (CUI) direct pe nota deschisa, in
        // tblBonCurent.CodFiscBon, in momentul in care utilizatorul il introduce.
        // Astfel codul nu ramane doar in memoria clientului (supravietuieste unui
        // refresh) si ajunge in arhiva tblBon la raportul Z.
        $docId = (int)($input['docId'] ?? 0);
        $cui = normalizeCui($input['cui'] ?? '');
        if ($docId <= 0) {
            sendJsonResponse(["status" => "error", "message" => "DocID invalid"], 400);
        }
        if ($cui !== '' && !validateCui($cui)) {
            sendJsonResponse(["status" => "error", "message" => "Cod fiscal (CUI) invalid: " . $cui], 400);
        }
        ensureCodFiscBonColumns($conn);
        $upd = sqlsrv_query(
            $conn,
            "UPDATE tblBonCurent SET CodFiscBon = ? WHERE DocID = ? AND Stare = 'D'",
            [($cui === '' ? null : $cui), $docId]
        );
        if ($upd === false) {
            sendJsonResponse(["status" => "error", "message" => "Eroare salvare cod fiscal: " . sqlsrv_errors()[0]['message']], 500);
        }
        if (sqlsrv_rows_affected($upd) === 0) {
            sendJsonResponse(["status" => "error", "message" => "Nu exista o nota deschisa cu acest DocID"], 404);
        }
        sendJsonResponse(["status" => "success", "docId" => $docId, "cui" => $cui]);
        break;

    case 'close_bill':
        $docId = (int)($input['docId'] ?? 0);
        $plati = $input['plati'] ?? [];
        $cui = $input['cui'] ?? '';
        $result = closeBill($conn, $docId, $plati, $cui);
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
        $data = buildBillPrintData($conn, $docId, $bon, [], "NOTA PROFORMA", true);
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

        // Context pentru jurnal (logs/app_events.log). `sursa` vine de la client:
        // "login" = modalul de autentificare ospatar, "parola" = promptul
        // deschis de butonul Parola. Nu se jurnalizeaza NICIODATA parola, doar
        // lungimea ei - suficient ca sa stim ce s-a tastat cand aplicatia se inchide.
        $authCtx = appEventClientCtx();
        $authSursa = trim((string)($input['sursa'] ?? ''));
        $authCtx['sursa'] = $authSursa !== '' ? $authSursa : '(neindicat)';
        $authCtx['parola_len'] = strlen($parola);

        // 1. Parole POS (tblParola): programare / rapoarte / inchidere aplicatie
        $stmtP = @sqlsrv_query($conn, "SELECT TOP 1 ParolaProgramare, ParolaRapoarte, ParolaExit FROM tblParola");
        $p = $stmtP ? sqlsrv_fetch_array($stmtP, SQLSRV_FETCH_ASSOC) : null;
        $prgParola = $p ? trim((string)$p['ParolaProgramare']) : '';
        $rapParola = $p ? trim((string)$p['ParolaRapoarte']) : '';
        $exitParola = $p ? trim((string)$p['ParolaExit']) : '';

        if ($prgParola !== '' && $prgParola === $parola) {
            appEventLog('auth', $authCtx + ['rezultat' => 'programare']);
            sendJsonResponse([
                "status" => "success",
                "programare" => true,
                "message" => "Acces programare"
            ]);
        }

        if ($rapParola !== '' && $rapParola === $parola) {
            appEventLog('auth', $authCtx + ['rezultat' => 'rapoarte']);
            sendJsonResponse([
                "status" => "success",
                "rapoarte" => true,
                "message" => "Acces rapoarte"
            ]);
        }

        if ($exitParola !== '' && $exitParola === $parola) {
            appEventLog('auth', $authCtx + ['rezultat' => 'EXIT']);
            sendJsonResponse([
                "status" => "success",
                "exit" => true,
                "message" => "Inchidere aplicatie"
            ]);
        }

        // 2. Altfel, autentificare ospatar (tblOsp)
        $sqlOsp = "SELECT NrOsp, Nume, Expl FROM tblOsp WHERE Parola = ? AND Blocat = 0";
        $stmtOsp = sqlsrv_query($conn, $sqlOsp, [$parola]);
        $osp = sqlsrv_fetch_array($stmtOsp, SQLSRV_FETCH_ASSOC);

        if ($osp) {
            appEventLog('auth', $authCtx + ['rezultat' => 'ospatar', 'ospatar' => trim((string)$osp['Nume'])]);
            sendJsonResponse([
                "status" => "success",
                "nrOsp" => (int)$osp['NrOsp'],
                "nume" => trim($osp['Nume']),
                "rol" => trim($osp['Expl'])
            ]);
        } else {
            appEventLog('auth', $authCtx + ['rezultat' => 'refuzat']);
            sendJsonResponse(["status" => "error", "message" => "Parola incorecta!"], 401);
        }
        break;

    case 'client_event':
        // Eveniment de UI trimis de client (ex. deschiderea promptului de parola),
        // ca sa avem contextul complet in logs/app_events.log atunci cand aplicatia
        // se inchide. Se accepta doar nume de eveniment si campuri scurte.
        $ev = preg_replace('/[^a-z_]/', '', strtolower((string)($input['event'] ?? '')));
        if ($ev === '') {
            sendJsonResponse(["status" => "error", "message" => "Eveniment lipsa"], 400);
        }
        $evCtx = appEventClientCtx();
        $evCtx['event'] = $ev;
        foreach (['modul', 'buton', 'ecran', 'detaliu'] as $k) {
            if (isset($input[$k]) && is_scalar($input[$k])) { $evCtx[$k] = (string)$input[$k]; }
        }
        appEventLog('client', $evCtx);
        sendJsonResponse(["status" => "success", "event" => $ev]);
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
                             VALUES (?, GETDATE(), GETDATE(), ?, ?, 0, 'D', ?);
                             SELECT SCOPE_IDENTITY() AS DocID;";
            $stmtNewBon = sqlsrv_query($conn, $sqlInsertBon, [$nextNrDoc, $destNrMasa, $nrOp, getMagIdDefault($conn)]);
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

        // Cand TipDiscount = 1 sau 2, discountul se aplica doar pe subtotal,
        // procentual. La 2 valoarea este cea fixa, programata in Setari (fara
        // popup pe client).
        $tipDiscount = getTipDiscount($conn);
        if ($tipDiscount === 1 || $tipDiscount === 2) {
            $scope = 'bill';
            $mode = 'percent';
            if ($tipDiscount === 2) {
                $value = getProcentDiscountFix($conn);
            }
        }

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
            // Discountul pe subtotal: motivul apartine notei (tblBonCurent),
            // nu liniilor, ca sa nu fie confundat cu un discount de produs
            // (care se evidentiaza pe linie prin tblNoteD.[Comment]).
            ensureBillDiscountMotiveColumn($conn);
            $billMotiv = ($motiv !== null && $motiv !== '') ? $motiv : 'Discount nota';
            if (!sqlsrv_query($conn, "UPDATE tblBonCurent SET MotivDiscount = ? WHERE DocID = ?", [$billMotiv, $docId])) {
                sendJsonResponse(["status" => "error", "message" => "Eroare salvare motiv discount: " . sqlsrv_errors()[0]['message']], 500);
            }
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

        // Pentru liniile deja trimise (Preluat = 1) cerem parola de manager si
        // motivul doar daca ParolaStornare este setata; altfel nu cerem nimic.
        if ($trimis) {
            $parolaStorn = getStornoParola($conn);
            if ($parolaStorn !== '') {
                if ($motiv === '') {
                    sendJsonResponse(["status" => "error", "message" => "Selectati motivul anularii"], 400);
                }
                if ($parola === '') {
                    sendJsonResponse(["status" => "error", "message" => "Introduceti parola de manager"], 401);
                }
                if ($parolaStorn !== $parola) {
                    sendJsonResponse(["status" => "error", "message" => "Parola de stornare incorecta"], 401);
                }
            } else {
                // Parola nu este setata: nu cerem nici parola, nici motiv.
                $motiv = ($motiv === '') ? null : $motiv;
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

            // Linia de storno: Cant negativ, PV = PVC (pretul intreg de catalog).
            // Magazia = gestiunea produsului (tblProd.MagID), cu fallback pe
            // prima magazie din tblSet.MagID.
            $stmtProdM = sqlsrv_query($conn, "SELECT MagID FROM tblProd WHERE ProdID = ?", [(int)$row['ProdID']]);
            $prodM = $stmtProdM ? sqlsrv_fetch_array($stmtProdM, SQLSRV_FETCH_ASSOC) : null;
            $stornoMagID = getLineMagId($conn, $prodM['MagID'] ?? null);

            $sqlIns = "INSERT INTO tblNoteD (DocID, ProdID, Cant, PV, PVC, TVAc, OraComanda, Magid, H, Descriere, NrGrp, Preluat, Preluat1, StornoRef, [Comment])
                       VALUES (?, ?, ?, ?, ?, ?, GETDATE(), ?, 0, ?, ?, 0, 0, ?, ?)";
            $stmtIns = sqlsrv_query($conn, $sqlIns, [
                $docId,
                (int)$row['ProdID'],
                -$cantitate,
                $pvc,
                $pvc,
                (int)($row['TVAc'] ?? 0),
                $stornoMagID,
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
            ? tr("Comanda (bon {bon}) a fost trimisa la bucatarie ({nr} bon(uri))", ["bon" => ($result['nrBon'] ?? '-'), "nr" => $result['jobs']])
            : tr("Nu exista linii netrimise");

        sendJsonResponse([
            "status" => "success",
            "message" => $message,
            "docId" => $docId,
            "jobs" => $result['jobs'],
            "marcate" => $result['lines'],
            "nrBon" => $result['nrBon'] ?? null
        ]);
        break;

    default:
        sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}
