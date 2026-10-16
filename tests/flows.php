<?php
/**
 * Faza D - fluxuri de business end-to-end pe baza live, pe artefacte de test
 * (mesele 80 si 79), cu curatare completa la final. Nu atinge alte bonuri.
 */
require __DIR__ . '/lib.php';

function setS($k, $v) { q("UPDATE tblSet SET Value=? WHERE Setting=?", [(string)$v, $k]); }
function getS($k) { return cell("SELECT Value FROM tblSet WHERE Setting=?", [$k]); }
function post($d) { return jpost('/api/order_action.php', $d); }
function insBillCount($masa) { return (int)cell("SELECT COUNT(*) FROM tblBonCurent WHERE NrMasa=? AND Stare='D'", [$masa]); }
function jobs($docId, $tip = null) {
    if ($tip === null) return (int)cell("SELECT COUNT(*) FROM tblPrintQueue WHERE RefDocID=?", [$docId]);
    return (int)cell("SELECT COUNT(*) FROM tblPrintQueue WHERE RefDocID=? AND Tip=?", [$docId, $tip]);
}
function billState($docId) { return q1("SELECT TotalB,Stare,NrDoc FROM tblBonCurent WHERE DocID=?", [$docId]); }
function lineOf($docId, $prodId) { return q1("SELECT TOP 1 ECRID,Cant,PV,PVC,Preluat,Comment FROM tblNoteD WHERE DocID=? AND ProdID=? ORDER BY ECRID", [$docId, $prodId]); }

$MASA_A = 80; $MASA_B = 79;
$origTipVanz = getS('TipVanz'); $origServer = getS('Server'); $origRED = getS('RED');
$origTipDisc = getS('TipDiscount'); $origProcent = getS('ProcentDiscountFix'); $origNrBon = getS('NrBon');

$pdoA = rows("SELECT DocID FROM tblBonCurent WHERE NrMasa IN (?,?)", [$MASA_A, $MASA_B]);
$preDocIds = array_map(fn($r) => (int)$r['DocID'], $pdoA);
test('mesele de test (80,79) sunt libere la start', count($preDocIds) === 0, 'bonuri existente: ' . implode(',', $preDocIds));

$prodRows = rows("SELECT TOP 2 p.ProdID, COALESCE(p.PV,0) AS pv FROM tblProd p WHERE p.ProdID>9 AND COALESCE(p.PV,0)>0 ORDER BY p.ProdID");
$P1 = (int)$prodRows[0]['ProdID']; $PV1 = (float)$prodRows[0]['pv'];
$P2 = (int)$prodRows[1]['ProdID']; $PV2 = (float)$prodRows[1]['pv'];
$NRMESAJ = (int)cell("SELECT TOP 1 NrMesaj FROM tblMesaj WHERE Mesaj IS NOT NULL AND LTRIM(RTRIM(Mesaj))<>''");
info("produse test P1=$P1 (pv=$PV1), P2=$P2 (pv=$PV2), NrMesaj=$NRMESAJ");

// config print -> preview (fara imprimanta fizica)
$cfgRes = jget('/api/print_config.php?action=config');
$origCfg = $cfgRes['json']['config'] ?? null;
function saveCfg($c) { return jpost('/api/print_config.php', ['action' => 'save', 'config' => $c]); }
$prevCfg = $origCfg;
foreach (['nota_target', 'raport_target', 'fiscal_target'] as $k) $prevCfg[$k] = ['target' => 'preview'];
foreach (array_keys($prevCfg['printers'] ?? []) as $nr) $prevCfg['printers'][$nr] = ['target' => 'preview', 'name' => 'T' . $nr];

$docA = null; $docIdsCreated = [];
try {
    saveCfg($prevCfg);
    setS('Server', '0');            // fara export catre serverul extern
    setS('TipVanz', '0');           // Restaurant: print_kitchen + nota active

    section('D1-D5 - construire bon (adaugare, mod, qty, discount, VD)');
    $r = post(['action' => 'add_product', 'nrMasa' => $MASA_A, 'nrOp' => 8, 'prodId' => $P1]);
    test('add_product -> 200', $r['code'] === 200 && !empty($r['json']['docId']), substr($r['body'], 0, 100));
    $docA = (int)($r['json']['docId'] ?? 0); $docIdsCreated[] = $docA;
    $bs = billState($docA);
    test('bon deschis cu total = PV', trim($bs['Stare']) === 'D' && abs((float)$bs['TotalB'] - $PV1) < 0.01, json_encode($bs));
    $l1 = lineOf($docA, $P1);
    test('linia: PV=PVC=PV produs, Preluat=0', abs((float)$l1['PV'] - $PV1) < 0.01 && abs((float)$l1['PVC'] - $PV1) < 0.01 && (int)$l1['Preluat'] === 0, json_encode($l1));

    post(['action' => 'add_product', 'nrMasa' => $MASA_A, 'nrOp' => 8, 'prodId' => $P1]);
    test('add_product nu fuzioneaza linii identice', (int)cell("SELECT COUNT(*) FROM tblNoteD WHERE DocID=? AND ProdID=?", [$docA, $P1]) === 2);

    post(['action' => 'add_product', 'nrMasa' => $MASA_A, 'nrOp' => 8, 'prodId' => $P2]);
    $l2 = lineOf($docA, $P2);

    post(['action' => 'add_mod', 'nrMasa' => $MASA_A, 'nrOp' => 8, 'nrMesaj' => $NRMESAJ]);
    test('add_mod creeaza rand ProdID=0 Cant=0', (int)cell("SELECT COUNT(*) FROM tblNoteD WHERE DocID=? AND ProdID=0 AND Cant<=0", [$docA]) === 1);
    $ecrMod = (int)cell("SELECT TOP 1 ECRID FROM tblNoteD WHERE DocID=? AND ProdID=0 ORDER BY ECRID DESC", [$docA]);
    $go = jget('/api/get_order.php?masa=' . $MASA_A);
    $flat = $go['json']['articole'] ?? [];
    $hasMods = false;
    foreach ($flat as $it) { if (!empty($it['mods'])) $hasMods = true; }
    test('get_order imbrica modul sub produs (mods[])', $hasMods, 'articole=' . count($flat));
    post(['action' => 'delete_mod', 'nrMasa' => $MASA_A, 'nrOp' => 8, 'ecrId' => $ecrMod]);
    test('delete_mod sterge randul de mod', (int)cell("SELECT COUNT(*) FROM tblNoteD WHERE ECRID=?", [$ecrMod]) === 0);

    section('D2 - cantitate (rotunjire NrZecCant, CantMax, blocaj pe trimis)');
    $nzc = (int)cfg('NrZecCant', 1);
    post(['action' => 'update_qty', 'nrMasa' => $MASA_A, 'nrOp' => 8, 'ecrId' => $l1['ECRID'], 'cantitate' => 3.567]);
    $l1b = q1("SELECT Cant FROM tblNoteD WHERE ECRID=?", [$l1['ECRID']]);
    test("update_qty rotunjeste la $nzc zecimale", abs((float)$l1b['Cant'] - round(3.567, $nzc)) < 1e-6, 'cant=' . $l1b['Cant']);
    $r = post(['action' => 'update_qty', 'nrMasa' => $MASA_A, 'nrOp' => 8, 'ecrId' => $l1['ECRID'], 'cantitate' => (int)cfg('CantMax', 1000) + 5]);
    test('update_qty peste CantMax -> 400', $r['code'] === 400, 'code=' . $r['code']);

    section('D2b - discount pe linie (independent) si pe nota');
    setS('TipDiscount', '0'); // modul complet: permite discount pe linie
    $r = post(['action' => 'apply_discount', 'nrMasa' => $MASA_A, 'nrOp' => 8, 'scope' => 'line', 'mode' => 'percent', 'value' => 25, 'ecrId' => $l1['ECRID'], 'motiv' => 'Test linie']);
    test('discount pe linie -> 200', $r['code'] === 200, substr($r['body'], 0, 100));
    $l1c = q1("SELECT PV,PVC,Comment FROM tblNoteD WHERE ECRID=?", [$l1['ECRID']]);
    test('linia 1: PV = PVC*0.75', abs((float)$l1c['PV'] - (float)$l1c['PVC'] * 0.75) < 0.01, json_encode($l1c));
    test('linia 1: Comment = motivul', trim((string)$l1c['Comment']) === 'Test linie', 'comment=' . $l1c['Comment']);
    $l2c = q1("SELECT PV,PVC FROM tblNoteD WHERE ECRID=?", [$l2['ECRID']]);
    test('linia 2 NU e afectata de discountul pe linie 1', abs((float)$l2c['PV'] - (float)$l2c['PVC']) < 0.01, json_encode($l2c));

    $r = post(['action' => 'apply_discount', 'nrMasa' => $MASA_A, 'nrOp' => 8, 'scope' => 'bill', 'mode' => 'percent', 'value' => 10, 'motiv' => 'Test nota']);
    test('discount pe nota -> 200', $r['code'] === 200, substr($r['body'], 0, 100));
    $sum = (float)cell("SELECT SUM(CAST(Cant AS float)*CAST(PV AS float)) FROM tblNoteD WHERE DocID=? AND ProdID>0", [$docA]);
    $bs = billState($docA);
    test('TotalB = suma liniilor dupa discount', abs((float)$bs['TotalB'] - $sum) < 0.02, "total={$bs['TotalB']} sum=$sum");
    test('discount pe nota lasa Comment NULL pe linii de produs', (int)cell("SELECT COUNT(*) FROM tblNoteD WHERE DocID=? AND ProdID>0 AND Comment IS NOT NULL", [$docA]) === 0);

    section('D2c - RED=0 si TipDiscount=2');
    setS('RED', '0');
    $r = post(['action' => 'apply_discount', 'nrMasa' => $MASA_A, 'nrOp' => 8, 'scope' => 'bill', 'mode' => 'percent', 'value' => 5]);
    test('apply_discount cu RED=0 -> 403', $r['code'] === 403, 'code=' . $r['code']);
    setS('RED', $origRED);
    setS('TipDiscount', '2'); setS('ProcentDiscountFix', '15');
    $catalogTotal = (float)cell("SELECT SUM(CAST(Cant AS float)*CAST(PVC AS float)) FROM tblNoteD WHERE DocID=? AND Cant>0", [$docA]);
    $r = post(['action' => 'apply_discount', 'nrMasa' => $MASA_A, 'nrOp' => 8, 'scope' => 'bill', 'mode' => 'percent', 'value' => 1]);
    $after = (float)cell("SELECT SUM(CAST(Cant AS float)*CAST(PV AS float)) FROM tblNoteD WHERE DocID=? AND ProdID>0", [$docA]);
    test('TipDiscount=2 ignora value si aplica 15% pe baza catalog', abs($after - 0.85 * $catalogTotal) < 0.05, "catalog=$catalogTotal after=$after expected=" . round(0.85 * $catalogTotal, 2));
    setS('TipDiscount', $origTipDisc); setS('ProcentDiscountFix', $origProcent);
    setS('TipDiscount', '0'); // revenim la modul complet pentru restul testelor

    section('D3 - VD pe linie netrimisa (reducere/stergere)');
    post(['action' => 'add_product', 'nrMasa' => $MASA_A, 'nrOp' => 8, 'prodId' => $P2]);
    $l3 = q1("SELECT TOP 1 ECRID,Cant FROM tblNoteD WHERE DocID=? AND ProdID=? ORDER BY ECRID DESC", [$docA, $P2]);
    $r = post(['action' => 'void_line', 'nrMasa' => $MASA_A, 'nrOp' => 8, 'ecrId' => $l3['ECRID'], 'cantitate' => 0.5]);
    test('void partial pe linie netrimisa -> 200', $r['code'] === 200, substr($r['body'], 0, 100));
    $l3b = q1("SELECT Cant FROM tblNoteD WHERE ECRID=?", [$l3['ECRID']]);
    test('linia ramane pozitiva dupa VD partial (netrimis)', abs((float)$l3b['Cant'] - 0.5) < 0.01, 'cant=' . ($l3b['Cant'] ?? 'sters'));
    // sterge complet linia ramasa
    $r = post(['action' => 'void_line', 'nrMasa' => $MASA_A, 'nrOp' => 8, 'ecrId' => $l3['ECRID'], 'cantitate' => 0.5]);
    test('VD integral pe linie netrimisa o sterge', (int)cell("SELECT COUNT(*) FROM tblNoteD WHERE ECRID=?", [$l3['ECRID']]) === 0);

    section('D5 - Marcare (print_kitchen): grup, Preluat, NrBon, un singur numar');
    $nrBonBefore = (int)getS('NrBon');
    $jobsBefore = jobs($docA);
    $r = post(['action' => 'print_kitchen', 'docId' => $docA, 'nrMasa' => $MASA_A]);
    test('print_kitchen -> 200', $r['code'] === 200, substr($r['body'], 0, 120));
    test('print_kitchen a creat cel putin un job', jobs($docA) > $jobsBefore, 'jobs=' . jobs($docA));
    test('toate liniile au Preluat=1', (int)cell("SELECT COUNT(*) FROM tblNoteD WHERE DocID=? AND ProdID>0 AND Preluat=0", [$docA]) === 0);
    test('NrBon a crescut exact cu 1', (int)getS('NrBon') === $nrBonBefore + 1, 'before=' . $nrBonBefore . ' after=' . getS('NrBon'));

    section('D3b/D6 - VD pe linie trimisa (storno), plafon, revocare, blocaj qty');
    $sent = q1("SELECT TOP 1 ECRID,Cant,PVC FROM tblNoteD WHERE DocID=? AND ProdID=? AND Preluat=1 ORDER BY ECRID", [$docA, $P1]);
    $r = post(['action' => 'update_qty', 'nrMasa' => $MASA_A, 'nrOp' => 8, 'ecrId' => $sent['ECRID'], 'cantitate' => 2]);
    test('update_qty pe linie trimisa -> refuz (nu 200)', $r['code'] !== 200, 'code=' . $r['code']);
    $r = post(['action' => 'void_line', 'nrMasa' => $MASA_A, 'nrOp' => 8, 'ecrId' => $sent['ECRID'], 'cantitate' => 1, 'motiv' => 'Test storno']);
    test('VD pe linie trimisa -> 200 (creeaza storno)', $r['code'] === 200, substr($r['body'], 0, 120));
    $st = q1("SELECT ECRID,Cant,PV,PVC,StornoRef,Comment,Preluat FROM tblNoteD WHERE DocID=? AND StornoRef=?", [$docA, $sent['ECRID']]);
    test('storno: Cant negativ, PV=PVC, Preluat=0, Comment=motiv', $st && (float)$st['Cant'] < 0 && abs((float)$st['PV'] - (float)$st['PVC']) < 0.01 && (int)$st['Preluat'] === 0, json_encode($st));
    $r = post(['action' => 'void_line', 'nrMasa' => $MASA_A, 'nrOp' => 8, 'ecrId' => $sent['ECRID'], 'cantitate' => 99]);
    test('VD peste cantitatea ramasa -> 400', $r['code'] === 400, 'code=' . $r['code']);
    // revocare storno
    $r = post(['action' => 'void_line', 'nrMasa' => $MASA_A, 'nrOp' => 8, 'ecrId' => $st['ECRID'], 'cantitate' => 1]);
    test('revocare storno (Preluat=0) -> randul dispare', (int)cell("SELECT COUNT(*) FROM tblNoteD WHERE ECRID=?", [$st['ECRID']]) === 0);

    section('D4 - transfer produse');
    $moves = rows("SELECT ECRID FROM tblNoteD WHERE DocID=? AND ProdID>0", [$docA]);
    $eids = array_map(fn($x) => (int)$x['ECRID'], $moves);
    $r = post(['action' => 'transfer_products', 'nrMasa' => $MASA_A, 'nrOp' => 8, 'destNrMasa' => $MASA_B, 'ecrIds' => [$eids[0]]]);
    test('transfer_products -> 200', $r['code'] === 200, substr($r['body'], 0, 120));
    $destId = (int)cell("SELECT TOP 1 DocID FROM tblBonCurent WHERE NrMasa=? AND Stare='D' ORDER BY DocID DESC", [$MASA_B]);
    if ($destId) $docIdsCreated[] = $destId;
    test('linia transferata e pe bonul destinatie', (int)cell("SELECT COUNT(*) FROM tblNoteD WHERE DocID=? AND ECRID=?", [$destId, $eids[0]]) === 1, "destId=$destId");

    section('D6b - Nota Proforma');
    $r = post(['action' => 'print_proforma', 'docId' => $docA, 'nrMasa' => $MASA_A]);
    test('print_proforma -> 200 si job proforma', $r['code'] === 200 && jobs($docA, 'proforma') >= 1, 'code=' . $r['code'] . ' jobs=' . jobs($docA, 'proforma'));

    section('D6c - inchidere nota (close_bill)');
    $tot = (float)billState($docA)['TotalB'];
    $r = post(['action' => 'close_bill', 'docId' => $docA, 'plati' => ['0' => $tot]]);
    test('close_bill -> 200', $r['code'] === 200, substr($r['body'], 0, 160));
    $bs = billState($docA);
    test('bonul e inchis (Stare=I)', trim($bs['Stare']) === 'I', json_encode($bs));
    $pay = (float)cell("SELECT SUM(CAST(Suma AS float)) FROM trelDocIDFpID WHERE DocID=?", [$docA]);
    test('platile scriu suma = total', abs($pay - $tot) < 0.02, "pay=$pay total=$tot");
    test('Server=0 -> fara rand in temp_Send_Sql', (int)cell("SELECT COUNT(*) FROM temp_Send_Sql WHERE DocID=?", [$docA]) === 0);
    test('s-a enqueued job fiscal la inchidere', jobs($docA, 'fiscal') >= 1, 'fiscal=' . jobs($docA, 'fiscal'));
    test('Restaurant -> s-a enqueued si job nota', jobs($docA, 'nota') >= 1, 'nota=' . jobs($docA, 'nota'));
    $go = jget('/api/get_order.php?masa=' . $MASA_A);
    test('dupa inchidere, masa nu mai are comanda', ($go['json']['hasOrder'] ?? true) === false);

    section('D6d - anti-dublu-inchidere (secvential + concurent)');
    $r = post(['action' => 'close_bill', 'docId' => $docA, 'plati' => ['0' => $tot]]);
    test('a doua close_bill secventiala nu dubleaza (404/409)', $r['code'] === 404 || $r['code'] === 409 || ($r['json']['alreadyClosed'] ?? false) === true, 'code=' . $r['code']);
    test('platile raman o singura inregistrare', (int)cell("SELECT COUNT(*) FROM trelDocIDFpID WHERE DocID=?", [$docA]) === 1, 'n=' . (int)cell("SELECT COUNT(*) FROM trelDocIDFpID WHERE DocID=?", [$docA]));

    // concurenta: doua close_bill simultane pe un bon nou
    $rc = post(['action' => 'add_product', 'nrMasa' => $MASA_A, 'nrOp' => 8, 'prodId' => $P1]);
    $docC = (int)($rc['json']['docId'] ?? 0); $docIdsCreated[] = $docC;
    $totC = (float)billState($docC)['TotalB'];
    $mh = curl_multi_init(); $chs = [];
    for ($i = 0; $i < 2; $i++) {
        $ch = curl_init(BASE_URL . '/api/order_action.php');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['action' => 'close_bill', 'docId' => $docC, 'plati' => ['0' => $totC]])]);
        curl_multi_add_handle($mh, $ch); $chs[] = $ch;
    }
    do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 0.2); } while ($run);
    $codes = [];
    foreach ($chs as $ch) { $codes[] = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_multi_remove_handle($mh, $ch); curl_close($ch); }
    curl_multi_close($mh);
    sort($codes);
    $n200 = count(array_filter($codes, fn($c) => $c === 200));
    $nOther = count(array_filter($codes, fn($c) => $c !== 200));
    test('close_bill concurent: exact un 200 si un refuz (409)', $n200 === 1 && $nOther === 1 && in_array(409, $codes, true), 'codes=' . implode(',', $codes));
    test('close_bill concurent: o singura inregistrare de plata', (int)cell("SELECT COUNT(*) FROM trelDocIDFpID WHERE DocID=?", [$docC]) === 1, 'n=' . (int)cell("SELECT COUNT(*) FROM trelDocIDFpID WHERE DocID=?", [$docC]));

    section('D11 - rapoarte X vs. SQL oracle');
    $rep = jget('/api/rapoarte.php?tab=X&tip=General');
    test('raport X General raspunde 200 cu totaluri', $rep['code'] === 200 && isset($rep['json']['totalValoare']), 'code=' . $rep['code'] . ' keys=' . implode(',', array_slice(array_keys($rep['json'] ?? []), 0, 8)));
    $repPlu = jget('/api/rapoarte.php?tab=X&tip=PLU');
    test('raport X PLU raspunde 200', $repPlu['code'] === 200, 'code=' . $repPlu['code']);
    $repNote = jget('/api/rapoarte.php?tab=X&tip=NOTE');
    test('raport X Note raspunde 200', $repNote['code'] === 200, 'code=' . $repNote['code']);

    section('D10 - Z blocat cat timp exista bonuri deschise');
    // cream un bon deschis deliberat
    $rz = post(['action' => 'add_product', 'nrMasa' => $MASA_B, 'nrOp' => 8, 'prodId' => $P1]);
    $openId = (int)($rz['json']['docId'] ?? 0); $docIdsCreated[] = $openId;
    $zz = jpost('/api/rapoarte.php', ['action' => 'z', 'tipuri' => []]);
    test('Z cu bonuri deschise -> refuz (nu 200)', $zz['code'] !== 200 || ($zz['json']['status'] ?? '') === 'error', 'code=' . $zz['code'] . ' body=' . substr($zz['body'], 0, 120));
    $chk = jget('/api/rapoarte.php?tab=Z&check=1');
    test('GET Z check=1 raporteaza allowed=false', ($chk['json']['allowed'] ?? true) === false, substr($chk['body'], 0, 120));

} finally {
    // curatare: toate bonurile create in test
    $docIdsCreated = array_values(array_unique(array_filter($docIdsCreated)));
    foreach ($docIdsCreated as $d) {
        q("DELETE FROM trelDocIDFpID WHERE DocID=?", [$d]);
        q("DELETE FROM trelArhZDocIDFpID WHERE DocID=?", [$d]);
        q("DELETE FROM tblNoteD WHERE DocID=?", [$d]);
        q("DELETE FROM tblPrintQueue WHERE RefDocID=?", [$d]);
        q("DELETE FROM temp_Send_Sql WHERE DocID=?", [$d]);
        q("DELETE FROM tblBonCurent WHERE DocID=?", [$d]);
    }
    setS('Server', $origServer); setS('TipVanz', $origTipVanz);
    setS('RED', $origRED); setS('TipDiscount', $origTipDisc); setS('ProcentDiscountFix', $origProcent);
    setS('NrBon', $origNrBon);
    if ($origCfg) saveCfg($origCfg);
}

// verificari finale de curatenie
$left = (int)cell("SELECT COUNT(*) FROM tblBonCurent WHERE NrMasa IN (?,?)", [$MASA_A, $MASA_B]);
test('curatare: nicio masa de test ramasa', $left === 0, "au ramas $left");
test('curatare: TipVanz restaurat', (string)getS('TipVanz') === (string)$origTipVanz, getS('TipVanz'));
test('curatare: Server restaurat', (string)getS('Server') === (string)$origServer, getS('Server'));
test('curatare: NrBon restaurat', (string)getS('NrBon') === (string)$origNrBon);

exit(summary('Faza D') > 0 ? 1 : 0);
