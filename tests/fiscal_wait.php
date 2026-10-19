<?php
/**
 * Faza F - "asteptarea raspunsului casei de marcat" (tblSet.AsteaptaRaspunsCasa = 1).
 *
 * Acopera fluxul din api/order_action.php cu mock-ul FiscalWire
 * (tools/mock_fiscalwire.py) - era listat ca zona neacoperita in REPORT.md:
 *
 *   F1 succes  - casa raspunde OK. Nota se inchide, dar FARA job `fiscal`
 *                (raspunsul casei tine loc de bon tiparit), jobul `nota` se
 *                creeaza normal, raspunsul brut ajunge in
 *                tblBonCurent.RaspunsCasa + temp_Send_Sql.RaspunsCasa, iar
 *                NrBon/NrZ reale din raspuns ajung in comanda de export.
 *   F2 refuz   - casa raspunde cu o linie `Er`. Nota NU se inchide (409).
 *   F3 timeout - nicio casa pornita. Nota NU se inchide (409), niciun job nu
 *                se creeaza, iar fisierul de comenzi este sters dupa perioada
 *                de gratie (altfel driverul l-ar tipari mai tarziu, cand nota e
 *                inca deschisa in POS).
 *
 * ATENTIE: scrie in baza `Rual` (doar masa de test 80) si curata tot la final,
 * exact ca faza D. Din acest motiv NU este inclus automat in run_all.
 * Rulare: php.exe tests/fiscal_wait.php
 */
require __DIR__ . '/lib.php';

$MASA = 80;
$TEST_DIR_REL = 'logs/mock_fiscal_test';

// ---------------------------------------------------------------- helper-e ---
function setS($k, $v) { q("UPDATE tblSet SET Value=? WHERE Setting=?", [(string)$v, $k]); }
function getS($k) { return cell("SELECT Value FROM tblSet WHERE Setting=?", [$k]); }
function act($d, $timeout = 60) { return http('POST', '/api/order_action.php', $d, [], $timeout); }
function billOf($docId) { return q1("SELECT TotalB,Stare,NrDoc,RaspunsCasa FROM tblBonCurent WHERE DocID=?", [$docId]); }
function jobCount($docId, $tip) { return (int)cell("SELECT COUNT(*) FROM tblPrintQueue WHERE RefDocID=? AND Tip=?", [$docId, $tip]); }
function saveCfg($c) { return jpost('/api/print_config.php', ['action' => 'save', 'config' => $c]); }
function slashWin($p) { return str_replace('/', '\\', $p); }
function folderEmpty($dir) { return count(array_diff(scandir($dir), ['.', '..'])) === 0; }
function newestResponse($dir, $ext) {
    $f = glob($dir . '/*.' . $ext);
    if (!$f) { return null; }
    usort($f, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $f[0];
}
/** Porneste mock-ul FiscalWire pentru o singura comanda (--once) si intoarce handle-ul. */
function startMock($mode, $dirComenzi, $dirRaspuns, $stateFile, $delay = 1.0, $keep = false) {
    $py = ROOT_DIR . '/.venv/Scripts/python.exe';
    $script = ROOT_DIR . '/tools/mock_fiscalwire.py';
    $cmd = escapeshellarg($py) . ' ' . escapeshellarg($script)
         . ' --once --mode ' . escapeshellarg($mode)
         . ' --delay ' . sprintf('%.1f', $delay)
         . ' --comenzi ' . escapeshellarg($dirComenzi)
         . ' --raspuns ' . escapeshellarg($dirRaspuns)
         . ' --state ' . escapeshellarg($stateFile);
    if ($keep) { $cmd .= ' --keep'; }
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = proc_open($cmd, $desc, $pipes);
    if (!is_resource($p)) { return null; }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    return ['proc' => $p, 'pipes' => $pipes];
}
/**
 * Cel mai recent fisier de comenzi FiscalWire pastrat in folder (--keep).
 * Numele este `pos_tm_<DocID>_<YmdHis>.inp`.
 */
function newestComanda($dir, $docId) {
    $f = glob($dir . '/pos_tm_' . (int)$docId . '_*.inp');
    if (!$f) { return null; }
    usort($f, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $f[0];
}
/** Goleste folderul de comenzi (ca sa nu fie procesat un fisier de la testul anterior). */
function clearDir($dir) {
    foreach (glob($dir . '/*') ?: [] as $f) { if (is_file($f)) { @unlink($f); } }
}
function stopMock($h) {
    if (!$h) { return; }
    $st = proc_get_status($h['proc']);
    foreach ($h['pipes'] as $pp) { if (is_resource($pp)) { @stream_get_contents($pp); @fclose($pp); } }
    if ($st && $st['running']) { @proc_terminate($h['proc']); }
    @proc_close($h['proc']);
}

// --------------------------------------------------------------- pregatire ---
$TEST_DIR = ROOT_DIR . '/' . $TEST_DIR_REL;
$DIR_COMENZI = $TEST_DIR . '/comenzi';
$DIR_RASPUNS = $TEST_DIR . '/raspuns';
$STATE_FILE = $TEST_DIR . '/state.json';
$COMENZI_WIN = slashWin($DIR_COMENZI);
$RASPUNS_WIN = slashWin($DIR_RASPUNS);

// Forteaza ensureSetariMeta() (creeaza/migreaza setarile de evidentiere a
// discountului/stornarii), ca testele sa aiba pe ce seta valori.
jget('/api/setari.php');

$orig = [
    'AsteaptaRaspunsCasa' => getS('AsteaptaRaspunsCasa'),
    'AsteptareCasaSec'    => getS('AsteptareCasaSec'),
    'CaleFisierComenziECR' => getS('CaleFisierComenziECR'),
    'CaleFisierRaspunsECR' => getS('CaleFisierRaspunsECR'),
    'TipCasaMarcat'       => getS('TipCasaMarcat'),
    'TipVanz'             => getS('TipVanz'),
    'Server'              => getS('Server'),
    'EvidDiscBonFisc'     => getS('EvidDiscBonFisc'),
    'EvidStornoBonFisc'   => getS('EvidStornoBonFisc'),
    'RED'                 => getS('RED'),
    'NrBon'               => getS('NrBon'),
];
$origCfg = jget('/api/print_config.php?action=config')['json']['config'] ?? null;

test('preconditie: TipCasaMarcat = FiscalWire', $orig['TipCasaMarcat'] === 'FiscalWire', (string)$orig['TipCasaMarcat']);
test('preconditie: masa de test 80 este libera', (int)cell("SELECT COUNT(*) FROM tblBonCurent WHERE NrMasa=? AND Stare='D'", [$MASA]) === 0);

$prodRows = rows("SELECT TOP 1 p.ProdID, COALESCE(p.PV,0) AS pv FROM tblProd p WHERE p.ProdID>9 AND COALESCE(p.PV,0)>0 ORDER BY p.ProdID");
$P1 = (int)$prodRows[0]['ProdID'];
$PV1 = (float)$prodRows[0]['pv'];
info("produs de test P1=$P1 (pv=$PV1)");

$docIds = [];
$mock = null;
$logFile = ROOT_DIR . '/logs/fiscal_wait.log';
$logSizeStart = file_exists($logFile) ? filesize($logFile) : 0;

try {
    // Tinte de tiparire -> preview, ca testul sa nu atinga nicio imprimanta.
    $prevCfg = $origCfg;
    foreach (['nota_target', 'raport_target', 'fiscal_target'] as $k) { $prevCfg[$k] = ['target' => 'preview']; }
    foreach (array_keys($prevCfg['printers'] ?? []) as $nr) { $prevCfg['printers'][$nr] = ['target' => 'preview', 'name' => 'T' . $nr]; }
    saveCfg($prevCfg);

    // Config de test: asteptare activa, foldere izolate, Restaurant (job `nota`),
    // export activ (ca sa verificam ca raspunsul casei ajunge in temp_Send_Sql).
    @mkdir($DIR_COMENZI, 0777, true);
    @mkdir($DIR_RASPUNS, 0777, true);
    setS('AsteaptaRaspunsCasa', '1');
    setS('CaleFisierComenziECR', $COMENZI_WIN);
    setS('CaleFisierRaspunsECR', $RASPUNS_WIN);
    setS('TipVanz', '0');
    setS('Server', '1');
    setS('AsteptareCasaSec', '8');

    // =====================================================================
    section('F1 - succes: casa de marcat raspunde OK');

    $r = act(['action' => 'add_product', 'nrMasa' => $MASA, 'nrOp' => 8, 'prodId' => $P1]);
    $docA = (int)($r['json']['docId'] ?? 0);
    $docIds[] = $docA;
    $totA = (float)cell("SELECT COALESCE(SUM(Cant*PV),0) FROM tblNoteD WHERE DocID=?", [$docA]);
    test('F1: bon deschis pe masa de test', $docA > 0 && $totA > 0, "doc=$docA tot=$totA");

    $mock = startMock('ok', $DIR_COMENZI, $DIR_RASPUNS, $STATE_FILE, 1.0);
    test('F1: mock-ul FiscalWire a pornit', $mock !== null);

    $t0 = microtime(true);
    $resA = act(['action' => 'close_bill', 'docId' => $docA, 'plati' => ['0' => round($totA, 2)]]);
    $elapsedA = microtime(true) - $t0;
    info(sprintf('close_bill a raspuns in %.2fs', $elapsedA));

    test('F1: close_bill -> 200 (nu asteapta timeout-ul)', $resA['code'] === 200, substr($resA['body'], 0, 200));
    $fiscalA = $resA['json']['fiscal'] ?? null;
    test('F1: raspunsul contine fiscal {z,nr,bf}', is_array($fiscalA) && isset($fiscalA['z'], $fiscalA['nr'], $fiscalA['bf']),
        json_encode($fiscalA));

    $billA = billOf($docA);
    test('F1: nota este inchisa (Stare=I)', $billA && $billA['Stare'] === 'I', $billA ? $billA['Stare'] : 'lipsa');
    test('F1: NU se creeaza job `fiscal` (raspunsul casei il inlocuieste)', jobCount($docA, 'fiscal') === 0);
    test('F1: jobul `nota` se creeaza normal', jobCount($docA, 'nota') === 1, 'nota=' . jobCount($docA, 'nota'));

    // Raspunsul brut al casei de marcat, asa cum l-a scris mock-ul.
    $nrbPath = newestResponse($DIR_RASPUNS, 'nrb');
    $nrbRaw = $nrbPath ? (string)file_get_contents($nrbPath) : '';
    test('F1: mock-ul a scris si `.nrb` si `.txt`', $nrbRaw !== '' && newestResponse($DIR_RASPUNS, 'txt') !== null);
    test('F1: `.nrb` contine UNIQUE_ID corelat cu comanda',
        (bool)preg_match('/UNIQUE_ID=pos_tm_\d+_\d+\.inp/', $nrbRaw), trim(explode("\n", $nrbRaw)[0] ?? ''));

    test('F1: raspunsul brut este salvat in tblBonCurent.RaspunsCasa',
        $billA && trim((string)$billA['RaspunsCasa']) === trim($nrbRaw), substr((string)($billA['RaspunsCasa'] ?? ''), 0, 60));

    $sendRow = q1("SELECT Id, str_sql, RaspunsCasa FROM temp_Send_Sql WHERE DocID=?", [$docA]);
    test('F1: s-a scris comanda de export (temp_Send_Sql)', $sendRow !== null);
    test('F1: raspunsul brut ajunge si in temp_Send_Sql.RaspunsCasa',
        $sendRow && trim((string)$sendRow['RaspunsCasa']) === trim($nrbRaw));
    // NrZ/NrBon din raspuns trebuie sa fie cele trimise procedurii de export,
    // nu cele prezise (tblBonCurent.NrDoc / MAX(tblNrZ)+1).
    $nrFromNrb = preg_match('/NR=(\d+)/', $nrbRaw, $m) ? $m[1] : '';
    $zFromNrb = preg_match('/Z=(\d+)/', $nrbRaw, $m2) ? $m2[1] : '';
    test('F1: NR din `.nrb` apare in comanda de export',
        $nrFromNrb !== '' && strpos((string)$sendRow['str_sql'], $nrFromNrb) !== false,
        "NR=$nrFromNrb");
    test('F1: Z din `.nrb` apare in comanda de export',
        $zFromNrb !== '' && strpos((string)$sendRow['str_sql'], $zFromNrb) !== false, "Z=$zFromNrb");

    test('F1: fisierul de comenzi a fost consumat (sters de driver)', folderEmpty($DIR_COMENZI));
    stopMock($mock); $mock = null;

    // =====================================================================
    section('F2 - refuz: casa de marcat raspunde cu eroare');

    @unlink($STATE_FILE);
    $mock = startMock('err', $DIR_COMENZI, $DIR_RASPUNS, $STATE_FILE, 0.5);
    $r = act(['action' => 'add_product', 'nrMasa' => $MASA, 'nrOp' => 8, 'prodId' => $P1]);
    $docB = (int)($r['json']['docId'] ?? 0);
    $docIds[] = $docB;
    $totB = (float)cell("SELECT COALESCE(SUM(Cant*PV),0) FROM tblNoteD WHERE DocID=?", [$docB]);
    $resB = act(['action' => 'close_bill', 'docId' => $docB, 'plati' => ['0' => round($totB, 2)]]);
    stopMock($mock); $mock = null;

    test('F2: close_bill -> 409 (refuz)', $resB['code'] === 409, substr($resB['body'], 0, 200));
    $billB = billOf($docB);
    test('F2: nota ramane DESCHISA', $billB && $billB['Stare'] === 'D', $billB ? $billB['Stare'] : 'lipsa');
    test('F2: nu s-a creat niciun job de tiparire', jobCount($docB, 'fiscal') === 0 && jobCount($docB, 'nota') === 0);
    test('F2: nota nu are RaspunsCasa (nu s-a inchis)', empty(trim((string)($billB['RaspunsCasa'] ?? ''))));

    // =====================================================================
    section('F3 - timeout: casa de marcat nu raspunde (niciun mock pornit)');

    $r = act(['action' => 'add_product', 'nrMasa' => $MASA, 'nrOp' => 8, 'prodId' => $P1]);
    $docC = (int)($r['json']['docId'] ?? 0);
    $docIds[] = $docC;
    // Diagnostice: F2 lasa nota DESCHISA pe aceeasi masa, deci F3 poate primi
    // exact acelasi DocID (comanda se adauga la nota existenta). Daca F3 pica pe
    // viitor, vrem sa stim dintr-o privire daca documentul e reutilizat si ce a
    // raspuns exact close_bill (ex. 409 "se inchide deja" de la application lock,
    // care nu ajunge in logs/fiscal_wait.log).
    info('docB=' . $docB . ' docC=' . $docC . ($docB === $docC ? ' (REUTILIZAT: aceeasi nota ca F2)' : ''));
    $totC = (float)cell("SELECT COALESCE(SUM(Cant*PV),0) FROM tblNoteD WHERE DocID=?", [$docC]);
    $t0 = microtime(true);
    $resC = act(['action' => 'close_bill', 'docId' => $docC, 'plati' => ['0' => round($totC, 2)]], 90);
    $elapsedC = microtime(true) - $t0;
    info(sprintf('close_bill a asteptat %.2fs (AsteptareCasaSec=8 + gratie 2s)', $elapsedC));
    info('raspuns F3: ' . substr(preg_replace('/\s+/', ' ', $resC['body']), 0, 200));

    test('F3: close_bill -> 409 (timeout)', $resC['code'] === 409, substr($resC['body'], 0, 200));
    $billC = billOf($docC);
    test('F3: nota ramane DESCHISA', $billC && $billC['Stare'] === 'D', $billC ? $billC['Stare'] : 'lipsa');
    test('F3: nu s-a creat niciun job de tiparire', jobCount($docC, 'fiscal') === 0 && jobCount($docC, 'nota') === 0);
    test('F3: nu s-a scris comanda de export', q1("SELECT Id FROM temp_Send_Sql WHERE DocID=?", [$docC]) === null);
    test('F3: a asteptat cel putin AsteptareCasaSec', $elapsedC >= 8.0, sprintf('%.2fs', $elapsedC));
    test('F3: fisierul de comenzi a fost sters dupa gratie (nu rămâne un bon abandonat)',
        folderEmpty($DIR_COMENZI), implode(',', array_diff(scandir($DIR_COMENZI), ['.', '..'])));

    $logTail = file_exists($logFile) ? (string)file_get_contents($logFile, false, null, $logSizeStart) : '';
    test('F3: esecul este logat in logs/fiscal_wait.log',
        strpos($logTail, 'rezultat=timeout') !== false, substr(trim($logTail), 0, 120));

    // =====================================================================
    // Numele comenzii are rezolutia de o secunda (`pos_tm_<DocID>_<YmdHis>`),
    // deci o reincercare a aceleiasi note in aceeasi secunda ar folosi acelasi
    // nume si ar citi raspunsul incercarii anterioare in loc sa trimita comanda.
    // Plantam intentionat raspunsuri "vechi" pentru baza de acum + urmatoarele
    // doua secunde (ca sa garantam coliziunea) si verificam ca sunt ignorate.
    section('F4 - raspuns vechi pentru aceeasi comanda (reincercare in aceeasi secunda)');

    $r = act(['action' => 'add_product', 'nrMasa' => $MASA, 'nrOp' => 8, 'prodId' => $P1]);
    $docD = (int)($r['json']['docId'] ?? 0);
    $docIds[] = $docD;
    $totD = (float)cell("SELECT COALESCE(SUM(Cant*PV),0) FROM tblNoteD WHERE DocID=?", [$docD]);

    $nowD = time();
    for ($i = 0; $i <= 2; $i++) {
        $baseD = 'pos_tm_' . $docD . '_' . date('YmdHis', $nowD + $i);
        file_put_contents($DIR_RASPUNS . '/' . $baseD . '.txt', "1,1,Er;COTA_TVA_DEZACTIVATA,\r\n");
    }
    info('plantat ' . count(glob($DIR_RASPUNS . '/*.txt') ?: []) . ' raspunsuri "vechi"');

    $t0D = microtime(true);
    $resD = act(['action' => 'close_bill', 'docId' => $docD, 'plati' => ['0' => round($totD, 2)]], 90);
    $elapsedD = microtime(true) - $t0D;
    info(sprintf('close_bill a asteptat %.2fs (raspunsurile vechi trebuie ignorate)', $elapsedD));

    test('F4: nu preia eroarea din raspunsul vechi', strpos((string)$resD['body'], 'COTA_TVA') === false, substr($resD['body'], 0, 150));
    test('F4: a asteptat timeout-ul (>= AsteaptareCasaSec)', $elapsedD >= 8.0, sprintf('%.2fs', $elapsedD));
    $billD = billOf($docD);
    test('F4: nota ramane DESCHISA', $billD && $billD['Stare'] === 'D', $billD ? $billD['Stare'] : 'lipsa');

    // =====================================================================
    // Separarea discount / stornare pe bonul fiscal (inainte o singura setare,
    // BonFiscalDiscStorno): EvidDiscBonFisc = discount pe fiecare linie de
    // produs, EvidStornoBonFisc = liniile de storno separate.
    setS('RED', '1');

    section('F5 - EvidDiscBonFisc: discount pe fiecare linie, fara storno');
    setS('EvidDiscBonFisc', '1');
    setS('EvidStornoBonFisc', '0');
    clearDir($DIR_COMENZI);
    @unlink($STATE_FILE);

    $r = act(['action' => 'add_product', 'nrMasa' => $MASA, 'nrOp' => 8, 'prodId' => $P1]);
    $docE = (int)($r['json']['docId'] ?? 0);
    $docIds[] = $docE;
    $ecrE = (int)cell("SELECT TOP 1 ECRID FROM tblNoteD WHERE DocID=? AND ProdID>0 ORDER BY ECRID", [$docE]);
    $rDisc = act(['action' => 'apply_discount', 'nrMasa' => $MASA, 'nrOp' => 8,
                  'scope' => 'line', 'mode' => 'percent', 'value' => 10,
                  'ecrId' => $ecrE, 'motiv' => 'Test bon fiscal']);
    test('F5: discount pe linie aplicat', $rDisc['code'] === 200, substr($rDisc['body'], 0, 150));
    $totE = (float)cell("SELECT COALESCE(SUM(Cant*PV),0) FROM tblNoteD WHERE DocID=?", [$docE]);

    $mock = startMock('ok', $DIR_COMENZI, $DIR_RASPUNS, $STATE_FILE, 0.5, true);
    $resE = act(['action' => 'close_bill', 'docId' => $docE, 'plati' => ['0' => round($totE, 2)]]);
    stopMock($mock); $mock = null;

    test('F5: bonul s-a inchis', $resE['code'] === 200, substr($resE['body'], 0, 150));
    $cmdE = newestComanda($DIR_COMENZI, $docE);
    test('F5: fisierul de comenzi a fost pastrat pentru verificare', $cmdE !== null);
    $textE = $cmdE ? (string)file_get_contents($cmdE) : '';
    test('F5: discount valoric pe linie (C ...;3;)', strpos($textE, 'C,1,______,_,__;3;') !== false, substr($textE, 0, 300));
    test('F5: NU se scrie discount procentual (C ...;1;)', strpos($textE, 'C,1,______,_,__;1;') === false);
    // S - discount = totalul notei (discountul este exact, nu rotunjit procentual).
    preg_match_all('/S,1,______,_,__;[^;]*;([\d.]+);([\d.]+);/', $textE, $mS, PREG_SET_ORDER);
    $sumE = 0.0;
    foreach ($mS as $x) { $sumE += (float)$x[1] * (float)$x[2]; }
    preg_match_all('/C,1,______,_,__;3;([\d.]+)/', $textE, $mC);
    $sumC = array_sum(array_map('floatval', $mC[1] ?: []));
    test('F5: S - discount = totalul notei', abs($sumE - $sumC - $totE) < 0.02,
        sprintf('S=%.2f C=%.2f total=%.2f', $sumE, $sumC, $totE));

    section('F6 - EvidStornoBonFisc: doar stornarea apare, discountul nu');
    setS('EvidDiscBonFisc', '0');
    setS('EvidStornoBonFisc', '1');
    clearDir($DIR_COMENZI);
    @unlink($STATE_FILE);

    $r = act(['action' => 'add_product', 'nrMasa' => $MASA, 'nrOp' => 8, 'prodId' => $P1]);
    $docF = (int)($r['json']['docId'] ?? 0);
    $docIds[] = $docF;
    $ecrF = (int)cell("SELECT TOP 1 ECRID FROM tblNoteD WHERE DocID=? AND ProdID>0 ORDER BY ECRID", [$docF]);
    $cantF = (float)cell("SELECT Cant FROM tblNoteD WHERE ECRID=? AND DocID=?", [$ecrF, $docF]);
    act(['action' => 'apply_discount', 'nrMasa' => $MASA, 'nrOp' => 8,
         'scope' => 'line', 'mode' => 'percent', 'value' => 10,
         'ecrId' => $ecrF, 'motiv' => 'Test bon fiscal']);
    // Marcam linia (Preluat = 1), ca anularea sa genereze o linie de storno.
    $rMark = act(['action' => 'print_kitchen', 'docId' => $docF]);
    test('F6: linia a fost marcata (Preluat = 1)', (int)cell("SELECT Preluat FROM tblNoteD WHERE ECRID=? AND DocID=?", [$ecrF, $docF]) === 1,
        substr($rMark['body'], 0, 150));
    $stornParola = (string)cell("SELECT ParolaStornare FROM tblParola WHERE Id=1");
    $rVoid = act(['action' => 'void_line', 'nrMasa' => $MASA, 'nrOp' => 8,
                  'ecrId' => $ecrF, 'cantitate' => $cantF, 'motiv' => 'Test storno', 'parola' => $stornParola]);
    test('F6: anulare (storno) inregistrata', $rVoid['code'] === 200, substr($rVoid['body'], 0, 150));
    test('F6: exista o linie de storno pe nota', (int)cell("SELECT COUNT(*) FROM tblNoteD WHERE DocID=? AND Cant<0", [$docF]) > 0);
    $totF = (float)cell("SELECT COALESCE(SUM(Cant*PV),0) FROM tblNoteD WHERE DocID=?", [$docF]);

    $mock = startMock('ok', $DIR_COMENZI, $DIR_RASPUNS, $STATE_FILE, 0.5, true);
    $resF = act(['action' => 'close_bill', 'docId' => $docF, 'plati' => ['0' => round($totF, 2)]]);
    stopMock($mock); $mock = null;

    test('F6: bonul s-a inchis', $resF['code'] === 200, substr($resF['body'], 0, 150));
    $cmdF = newestComanda($DIR_COMENZI, $docF);
    test('F6: fisierul de comenzi a fost pastrat pentru verificare', $cmdF !== null);
    $textF = $cmdF ? (string)file_get_contents($cmdF) : '';
    test('F6: linia de storno apare pe bon (S cu cantitate negativa)',
        (bool)preg_match('/S,1,______,_,__;[^;]*;[^;]*;-[\d.]+;/', $textF), substr($textF, 0, 300));
    test('F6: NU apare comanda de discount', strpos($textF, 'C,1,______,_,__;') === false, substr($textF, 0, 300));

} finally {
    stopMock($mock);
    // ------------------------------------------------------------ curatare --
    foreach (array_values(array_unique(array_filter($docIds))) as $d) {
        q("DELETE FROM trelDocIDFpID WHERE DocID=?", [$d]);
        q("DELETE FROM trelArhZDocIDFpID WHERE DocID=?", [$d]);
        q("DELETE FROM tblNoteD WHERE DocID=?", [$d]);
        q("DELETE FROM tblPrintQueue WHERE RefDocID=?", [$d]);
        q("DELETE FROM temp_Send_Sql WHERE DocID=?", [$d]);
        q("DELETE FROM tblBonCurent WHERE DocID=?", [$d]);
    }
    foreach ($orig as $k => $v) { setS($k, $v); }
    if ($origCfg) { saveCfg($origCfg); }
    foreach ([$DIR_COMENZI, $DIR_RASPUNS, $TEST_DIR] as $d) {
        foreach (glob($d . '/*') ?: [] as $f) { if (is_file($f)) { @unlink($f); } }
        @rmdir($d);
    }
}

// ------------------------------------------------- verificari de curatenie ---
echo "\n";
test('curatare: nicio masa de test ramasa', (int)cell("SELECT COUNT(*) FROM tblBonCurent WHERE NrMasa=?", [$MASA]) === 0);
test('curatare: AsteaptaRaspunsCasa restaurat', (string)getS('AsteaptaRaspunsCasa') === (string)$orig['AsteaptaRaspunsCasa'], getS('AsteaptaRaspunsCasa'));
test('curatare: CaleFisierComenziECR restaurat', (string)getS('CaleFisierComenziECR') === (string)$orig['CaleFisierComenziECR'], getS('CaleFisierComenziECR'));
test('curatare: CaleFisierRaspunsECR restaurat', (string)getS('CaleFisierRaspunsECR') === (string)$orig['CaleFisierRaspunsECR']);
test('curatare: TipVanz restaurat', (string)getS('TipVanz') === (string)$orig['TipVanz']);
test('curatare: Server restaurat', (string)getS('Server') === (string)$orig['Server']);
test('curatare: folderul de test a fost sters', !is_dir($TEST_DIR));

exit(summary('Faza F') > 0 ? 1 : 0);
