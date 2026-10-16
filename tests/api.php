<?php
/**
 * Faza C - API/HTTP, oracle vs DB, validari negative, securitate, API v1.
 * Scrierile de config sunt salvate si restaurate in finally.
 */
require __DIR__ . '/lib.php';

function setSetting($k, $v) { q("UPDATE tblSet SET Value=? WHERE Setting=?", [(string)$v, $k]); }
function getSetting($k) { return cell("SELECT Value FROM tblSet WHERE Setting=?", [$k]); }

/* --------------------------------------------------- C1 oracle API vs SQL */
section('C1 - oracle: API vs baza de date');
$menu = jget('/api/menu.php');
test('menu.php raspunde 200 + JSON', $menu['code'] === 200 && is_array($menu['json']), 'code=' . $menu['code']);
$byGrp = $menu['json']['productsByGroup'] ?? [];
$prods = [];
foreach ($byGrp as $g => $list) { foreach ((array)$list as $p) { if (is_array($p) && isset($p['ProdID'])) $prods[] = $p; } }
test('menu.php are produse', count($prods) > 0, 'n=' . count($prods));
$pmis = 0; $sample = '';
foreach (array_slice($prods, 0, 80) as $p) {
    $dbpv = (float)cell("SELECT COALESCE(PV,0) FROM tblProd WHERE ProdID=?", [(int)$p['ProdID']]);
    $apiPv = (float)($p['Pret'] ?? -1);
    if (abs($apiPv - $dbpv) > 0.001) { $pmis++; $sample = "ProdID={$p['ProdID']} api={$apiPv} db=$dbpv"; }
}
test('preturile din menu.php = tblProd.PV', $pmis === 0, $sample);

$tables = jget('/api/get_tables.php');
test('get_tables.php 200', $tables['code'] === 200 && isset($tables['json']['tables']), 'code=' . $tables['code']);
$openMese = [];
foreach (rows("SELECT DISTINCT NrMasa FROM tblBonCurent WHERE Stare='D'") as $r) $openMese[] = (int)$r['NrMasa'];
$apiOcc = [];
foreach (($tables['json']['tables'] ?? []) as $t) { if (!empty($t['isOccupied'])) $apiOcc[] = (int)$t['nrMasa']; }
sort($openMese); sort($apiOcc);
test('mesele ocupate din API = bonurile deschise', $openMese === $apiOcc, 'api=' . implode(',', $apiOcc) . ' db=' . implode(',', $openMese));

$free = null;
foreach (($tables['json']['tables'] ?? []) as $t) { if (empty($t['isOccupied'])) { $free = (int)$t['nrMasa']; break; } }
$ord = jget('/api/get_order.php?masa=' . ($free ?: 1));
test('get_order pe masa libera -> hasOrder=false, total 0', ($ord['json']['hasOrder'] ?? null) === false && (float)($ord['json']['total'] ?? -1) === 0.0, 'body=' . substr($ord['body'], 0, 120));

/* ------------------------------------------------ C4 envelope de eroare */
section('C4 - eroare -> mereu JSON, nu HTML');
$bad = http('POST', '/api/order_action.php', 'not-json{{');
test('POST JSON invalid -> raspuns JSON', $bad['json'] !== null, 'code=' . $bad['code'] . ' body=' . substr($bad['body'], 0, 120));
$unk = jpost('/api/order_action.php', ['action' => 'actiune_inexistenta']);
test('actiune necunoscuta -> raspuns JSON', $unk['json'] !== null, 'code=' . $unk['code'] . ' body=' . substr($unk['body'], 0, 120));
$sq = jget('/api/get_order.php?masa=1');
test('get_order.php?masa=1 raspunde JSON', $sq['json'] !== null, 'code=' . $sq['code']);

/* ------------------------------------------------ C8 injectie SQL/XSS */
section('C8 - injectie / XSS');
$inj = jget("/api/get_order.php?masa=1%27%20OR%201%3D1--");
test('get_order: parametru cu quote nu produce eroare 500', $inj['code'] !== 500 && $inj['json'] !== null, 'code=' . $inj['code']);
$inj2 = jget('/api/menu.php');
$inj3 = jpost('/api/order_action.php', ['action' => 'add_product', 'nrMasa' => "1' OR '1'='1", 'nrOp' => 8, 'prodId' => 0]);
test('add_product: nrMasa injectat -> 400 curat (nu 500)', $inj3['code'] === 400, 'code=' . $inj3['code'] . ' body=' . substr($inj3['body'], 0, 100));

/* ------------------------------------------------ C2 validari negative */
section('C2 - validari negative (fara a crea bon)');
$cm = (int)cfg('CantMax', 1000);
$r = jpost('/api/order_action.php', ['action' => 'add_product', 'nrMasa' => 79, 'nrOp' => 8, 'prodId' => 0]);
test('add_product prodId=0 -> 400', $r['code'] === 400, 'code=' . $r['code']);
$firstProd = (int)cell("SELECT TOP 1 ProdID FROM tblProd WHERE ProdID>9 AND COALESCE(PV,0)>0 ORDER BY ProdID");
$r = jpost('/api/order_action.php', ['action' => 'add_product', 'nrMasa' => 79, 'nrOp' => 8, 'prodId' => $firstProd, 'cantitate' => $cm + 50]);
test('add_product peste CantMax -> 400', $r['code'] === 400, "cantMax=$cm code=" . $r['code'] . ' body=' . substr($r['body'], 0, 90));
$r = jpost('/api/order_action.php', ['action' => 'add_product', 'nrMasa' => 79, 'nrOp' => 8, 'prodId' => 999999]);
test('add_product produs inexistent -> 404', $r['code'] === 404, 'code=' . $r['code']);
$r = jpost('/api/order_action.php', ['action' => 'authenticate', 'parola' => 'parola-gresita-xyz']);
test('authenticate parola gresita nu autentifica', ($r['json']['success'] ?? false) === false, 'body=' . substr($r['body'], 0, 120));
test('nu s-a creat niciun bon pe masa 79', (int)cell("SELECT COUNT(*) FROM tblBonCurent WHERE NrMasa=79 AND Stare='D'") === 0);

/* ------------------------------------------------ C5 API v1 + C6 rutare */
section('C5/C6 - API integrare v1 prin Apache');
$ver = jget('/api/v1/version');
test('v1 cu integrarea oprita -> 503', $ver['code'] === 503, 'code=' . $ver['code']);

$origEnabled = getSetting('ApiEnabled');
$origHash = getSetting('ApiKeyHash');
$origRate = getSetting('ApiRateLimit');
$origServer = getSetting('Server');
$xkey = 'test-key-' . bin2hex(random_bytes(6));
try {
    setSetting('ApiEnabled', '1');
    setSetting('ApiKeyHash', hash('sha256', $xkey));
    setSetting('ApiRateLimit', '0'); // fara limita pentru testele de ruta

    $noKey = jget('/api/v1/version');
    test('v1 fara cheie -> 401', $noKey['code'] === 401, 'code=' . $noKey['code']);
    $badKey = jget('/api/v1/version', ['X-Api-Key: gresit']);
    test('v1 cheie gresita -> 401', $badKey['code'] === 401, 'code=' . $badKey['code']);
    $okKey = jget('/api/v1/version', ['X-Api-Key: ' . $xkey]);
    test('v1 cheie corecta -> 200', $okKey['code'] === 200, 'code=' . $okKey['code'] . ' body=' . substr($okKey['body'], 0, 80));
    $bearer = jget('/api/v1/version', ['Authorization: Bearer ' . $xkey]);
    test('v1 Bearer -> 200', $bearer['code'] === 200, 'code=' . $bearer['code']);

    $prods = jget('/api/v1/products?limit=5', ['X-Api-Key: ' . $xkey]);
    test('GET v1/products -> 200 cu date', $prods['code'] === 200 && !empty($prods['json']['data']), 'code=' . $prods['code'] . ' body=' . substr($prods['body'], 0, 100));
    $ord = jget('/api/v1/orders', ['X-Api-Key: ' . $xkey]);
    test('GET v1/orders -> 200', $ord['code'] === 200, 'code=' . $ord['code']);
    $rep = jget('/api/v1/reports/general', ['X-Api-Key: ' . $xkey]);
    test('GET v1/reports/general -> 200', $rep['code'] === 200, 'code=' . $rep['code']);
    $ev = jget('/api/v1/events?limit=5', ['X-Api-Key: ' . $xkey]);
    test('GET v1/events -> 200', $ev['code'] === 200, 'code=' . $ev['code']);

    // --- guard Server=1 (import refuzat) ---
    $guard = jpost('/api/v1/products', ['items' => [['prodId' => 99001, 'nrGrp' => 1, 'denumire' => 'TEST']]], ['X-Api-Key: ' . $xkey]);
    test('POST v1/products cu Server=1 -> 409 server_managed', $guard['code'] === 409 && ($guard['json']['code'] ?? '') === 'server_managed', 'code=' . $guard['code'] . ' code_field=' . ($guard['json']['code'] ?? 'lipsa'));

    // --- idempotency pe o EROARE: codul HTTP trebuie pastrat ---
    $ikE = ['X-Api-Key: ' . $xkey, 'Idempotency-Key: err-' . bin2hex(random_bytes(4))];
    $e1 = jpost('/api/v1/waiters', ['bad' => 1], $ikE);
    $e2 = jpost('/api/v1/waiters', ['bad' => 1], $ikE);
    test('idempotency: replay-ul unei erori pastreaza codul HTTP', $e2['code'] === $e1['code'] && $e1['code'] !== 200, "r1={$e1['code']} r2={$e2['code']}" . ($e2['code'] === 200 ? ' (replay servit ca 200)' : ''));

    // --- scriere valida + idempotency pe succes (NrOsp are max 2 caractere!) ---
    $testOsp = '99';
    $ospBefore = (int)cell("SELECT COUNT(*) FROM tblOsp WHERE NrOsp=?", [$testOsp]);
    test('ospatarul de test 99 e liber', $ospBefore === 0, "exista deja ($ospBefore)");
    $bodyW = ['items' => [['nrOsp' => $testOsp, 'nume' => 'TEST WAITER', 'parola' => '1234', 'blocat' => 0]]];
    $ikW = ['X-Api-Key: ' . $xkey, 'Idempotency-Key: ok-' . bin2hex(random_bytes(4))];
    $w1 = jpost('/api/v1/waiters', $bodyW, $ikW);
    $w2 = jpost('/api/v1/waiters', $bodyW, $ikW);
    test('POST v1/waiters valid -> 200', $w1['code'] === 200, 'code=' . $w1['code'] . ' body=' . substr($w1['body'], 0, 120));
    test('idempotency succes: acelasi raspuns, un singur rand', $w1['body'] === $w2['body'] && (int)cell("SELECT COUNT(*) FROM tblOsp WHERE NrOsp=?", [$testOsp]) === 1, 'n=' . (int)cell("SELECT COUNT(*) FROM tblOsp WHERE NrOsp=?", [$testOsp]));

    // --- mesaj de eroare util la eroare SQL (parola depaseste nvarchar(50)) ---
    $long = jpost('/api/v1/waiters', ['items' => [['nrOsp' => 'XX', 'nume' => 'x', 'parola' => str_repeat('9', 80)]]], ['X-Api-Key: ' . $xkey]);
    $msg = (string)($long['json']['message'] ?? '');
    test('eroare SQL in v1 -> 500 cu mesaj util (nu "eroare necunoscuta")', $long['code'] === 500 && stripos($msg, 'necunoscuta') === false, "code={$long['code']} msg=" . substr($msg, 0, 100));
    q("DELETE FROM tblOsp WHERE NrOsp=?", ['XX']);

    // --- import permis cu Server=0 (grupe + produse) ---
    setSetting('Server', '0');
    q("DELETE FROM tblProd WHERE ProdID=99001");
    q("DELETE FROM tblGrp WHERE NrGrp=99");
    $g1 = jpost('/api/v1/groups', ['items' => [['nrGrp' => 99, 'denumire' => 'TEST GRP', 'poz' => 50]]], ['X-Api-Key: ' . $xkey]);
    test('POST v1/groups cu Server=0 -> 200', $g1['code'] === 200, 'code=' . $g1['code'] . ' body=' . substr($g1['body'], 0, 120));
    test('grupa 99 creata', (int)cell("SELECT COUNT(*) FROM tblGrp WHERE NrGrp=99") === 1);
    $p1 = jpost('/api/v1/products', ['items' => [['prodId' => 99001, 'nrGrp' => 99, 'denumire' => 'TEST PROD', 'pv' => 5]]], ['X-Api-Key: ' . $xkey]);
    test('POST v1/products cu Server=0 -> 200', $p1['code'] === 200, 'code=' . $p1['code'] . ' body=' . substr($p1['body'], 0, 120));
    test('produs 99001 creat cu pretul 5', (int)cell("SELECT COUNT(*) FROM tblProd WHERE ProdID=99001") === 1 && abs((float)cell("SELECT PV FROM tblProd WHERE ProdID=99001") - 5) < 0.001);
    q("DELETE FROM tblProd WHERE ProdID=99001");
    q("DELETE FROM tblGrp WHERE NrGrp=99");

    // --- rate limit ---
    setSetting('ApiRateLimit', '3');
    $codes = [];
    for ($i = 0; $i < 6; $i++) { $codes[] = jget('/api/v1/version', ['X-Api-Key: ' . $xkey])['code']; }
    test('rate limit: dupa depasire -> 429', in_array(429, $codes, true), 'codes=' . implode(',', $codes));
    setSetting('ApiRateLimit', '0');
} finally {
    q("DELETE FROM tblProd WHERE ProdID=99001");
    q("DELETE FROM tblGrp WHERE NrGrp=99");
    q("DELETE FROM tblOsp WHERE NrOsp IN (?,?)", ['99', 'XX']);
    setSetting('ApiEnabled', $origEnabled);
    setSetting('ApiKeyHash', $origHash);
    setSetting('ApiRateLimit', $origRate);
    setSetting('Server', $origServer);
}
$after = jget('/api/v1/version');
test('v1 re-dezactivat -> 503 (stare restaurata)', $after['code'] === 503, 'code=' . $after['code']);
test('ApiEnabled restaurat', (string)getSetting('ApiEnabled') === (string)$origEnabled);
test('ApiKeyHash restaurat', (string)getSetting('ApiKeyHash') === (string)$origHash);

/* ------------------------------------------------ C7 expunere fisiere */
section('C7 - fisiere servite prin web care nu ar trebui');
$exposed = ['github_api.txt', 'print-service/config.json', 'sync-service/config.json', 'AGENTS.md', 'db/tools/export_schema.php'];
foreach ($exposed as $f) {
    $r = http('GET', '/' . $f);
    test("blocat prin web: /$f", $r['code'] === 403 || $r['code'] === 404, 'code=' . $r['code']);
}
$prot = http('GET', '/logs/version.log');
test('blocat prin web: /logs/', $prot['code'] === 403, 'code=' . $prot['code']);
$git = http('GET', '/.git/config');
test('blocat prin web: /.git/', $git['code'] === 403, 'code=' . $git['code']);
$dbl = http('GET', '/api/db.local.php');
test('db.local.php nu expune credențiale', !str_contains($dbl['body'], 'PWD') && !str_contains($dbl['body'], 'Modigliani'), 'len=' . strlen($dbl['body']));
$txt = http('GET', '/tests/static.php');
test('blocat prin web: /tests/', $txt['code'] === 403 || $txt['code'] === 404, 'code=' . $txt['code']);

section('C9 - path traversal');
$pt = http('GET', '/api/print_preview.php?jobId=../../../../../../windows/win.ini');
test('print_preview: jobId non-numeric nu serveste fisier arbitrar', !str_contains($pt['body'], '[fonts]') && !str_contains($pt['body'], '[extensions]'), 'code=' . $pt['code'] . ' len=' . strlen($pt['body']));
$pt2 = http('GET', 'http://127.0.0.1:8756/preview/../../../../windows/win.ini');
test('print-service /preview/<file>: fara traversal', !str_contains($pt2['body'], '[fonts]'), 'code=' . $pt2['code']);

exit(summary('Faza C') > 0 ? 1 : 0);
