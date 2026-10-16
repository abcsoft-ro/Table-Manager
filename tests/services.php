<?php
/**
 * Faza E - servicii Python (print-service + sync-service), coada durabila,
 * emulator, retry/backoff, hot-reload. Nu opreste serviciile; le "linisteste"
 * prin poll_interval mare (hot-reload), apoi restaureaza configuratia.
 */
require __DIR__ . '/lib.php';

function httpRaw($url, $method = 'GET', $body = null, $timeout = 10) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CUSTOMREQUEST => $method]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']); }
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $code, 'json' => json_decode((string)$r, true), 'body' => (string)$r];
}
function savePrintCfg($cfg) { return jpost('/api/print_config.php', ['action' => 'save', 'config' => $cfg]); }
function insJob($tip, $payload, $printerNr = null) {
    $p = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($printerNr === null) {
        q("INSERT INTO tblPrintQueue (Tip,RefDocID,PrinterNr,Payload,Stare,Attempts,NextAttempt,CreatedAt) VALUES (?,NULL,NULL,?,'pending',0,GETDATE(),GETDATE())", [$tip, $p]);
    } else {
        q("INSERT INTO tblPrintQueue (Tip,RefDocID,PrinterNr,Payload,Stare,Attempts,NextAttempt,CreatedAt) VALUES (?,NULL,?,?,'pending',0,GETDATE(),GETDATE())", [$tip, $printerNr, $p]);
    }
    return (int)cell("SELECT MAX(JobID) FROM tblPrintQueue");
}
function job($id) { return q1("SELECT Stare,Attempts,LastError FROM tblPrintQueue WHERE JobID=?", [$id]); }
function delJob($id) { q("DELETE FROM tblPrintQueue WHERE JobID=?", [$id]); }

$PREV = ROOT_DIR . '/print-service/preview';
$cfgRes = jget('/api/print_config.php?action=config');
$origCfg = $cfgRes['json']['config'] ?? null;
$syncCfgRes = httpRaw('http://127.0.0.1:8757/config');
$origSync = $syncCfgRes['json']['config'] ?? null;

if (!$origCfg) { test('print-service config disponibil', false, 'serviciul nu raspunde'); exit(1); }
if (!$origSync) { test('sync-service config disponibil', false, 'serviciul nu raspunde'); exit(1); }

$createdJobs = [];
try {
    /* ---------------------------------------------------------------- E1 */
    section('E1 - endpoint-uri serviciu de tiparire');
    $h = jget('/api/print_config.php?action=config');
    test('GET print_config?action=config -> service_up', ($h['json']['service_up'] ?? false) === true);
    test('configul are printers/nota_target/raport_target/fiscal_target', isset($origCfg['printers'], $origCfg['nota_target'], $origCfg['raport_target'], $origCfg['fiscal_target']));
    $pr = jget('/api/print_config.php?action=printers');
    test('GET print_config?action=printers raspunde 200', $pr['code'] === 200 && array_key_exists('printers', $pr['json'] ?? []), 'code=' . $pr['code']);
    $st = jget('/api/services.php?action=status');
    test('services.php status raspunde', $st['code'] === 200, 'code=' . $st['code'] . ' body=' . substr($st['body'], 0, 100));

    /* ------------------------------------------- config -> preview (sigur) */
    $previewCfg = $origCfg;
    $previewCfg['poll_interval_sec'] = 3600;              // linisteste worker-ul
    $previewCfg['backoff_seconds'] = [1, 1];
    foreach (['nota_target', 'raport_target', 'fiscal_target'] as $k) { $previewCfg[$k] = ['target' => 'preview']; }
    foreach (array_keys($previewCfg['printers'] ?? []) as $nr) { $previewCfg['printers'][$nr] = ['target' => 'preview', 'name' => 'TEST' . $nr]; }
    $sv = savePrintCfg($previewCfg);
    test('salvare config (toate tintele -> preview)', $sv['code'] === 200, 'code=' . $sv['code'] . ' body=' . substr($sv['body'], 0, 120));
    $cf = jget('/api/print_config.php?action=config');
    test('hot-reload: configul reflecta tinta preview', (($cf['json']['config']['nota_target']['target'] ?? '') === 'preview'), json_encode($cf['json']['config']['nota_target'] ?? null));

    /* ------------------------------------------------------------------ E2 emulator */
    section('E2 - emulator + /test');
    $t = jpost('/api/print_config.php', ['action' => 'test', 'which' => 'nota']);
    test('test which=nota -> 200', $t['code'] === 200, 'code=' . $t['code'] . ' body=' . substr($t['body'], 0, 120));
    $files = glob($PREV . '/*.html') ?: [];
    test('emulatorul a scris un fisier preview', count($files) > 0, 'dir=' . $PREV);
    $txt = '';
    foreach ($files as $f) { if (str_contains(basename($f), 'job_')) { $txt .= file_get_contents($f); } }
    test('preview-ul contine text de bon', stripos($txt, 'TEST IMPRIMANTA') !== false || stripos($txt, 'NOTA') !== false, 'len=' . strlen($txt));

    sleep(2); // worker-ul adopta poll_interval mare

    /* ---------------------------------------------- E4 contract coada print */
    section('E4 - contract coada de tiparire (claim/done/fail)');
    $jid = insJob('nota', ['title' => 'TEST', 'items' => [], 'total' => 1]); $createdJobs[] = $jid;
    $c1 = jget('/api/print_queue.php?action=claim');
    test('claim ridica jobul de test', ($c1['json']['job']['JobID'] ?? 0) === $jid, 'body=' . substr($c1['body'], 0, 120));
    $jr = job($jid);
    test('dupa claim: Stare=printing, Attempts=1', trim($jr['Stare']) === 'printing' && (int)$jr['Attempts'] === 1, json_encode($jr));
    $f1 = jpost('/api/print_queue.php', ['action' => 'fail', 'jobId' => $jid, 'error' => 'test', 'retryInSeconds' => 120]);
    $jr = job($jid);
    test('fail cu retry -> Stare=pending, NextAttempt in viitor', trim($jr['Stare']) === 'pending', json_encode($jr));
    $c2 = jget('/api/print_queue.php?action=claim');
    test('claim imediat dupa fail nu ridica jobul (backoff respectat)', ($c2['json']['job']['JobID'] ?? 0) !== $jid, 'body=' . substr($c2['body'], 0, 100));
    jpost('/api/print_queue.php', ['action' => 'done', 'jobId' => $jid]);
    test('done -> Stare=done', trim(job($jid)['Stare']) === 'done');

    /* -------------------------------------------- E3 retry/backoff + hot-reload */
    section('E3 - retry/backoff pe tinta indisponibila + hot-reload');
    $deadCfg = $previewCfg;
    $deadCfg['poll_interval_sec'] = 1;
    foreach (array_keys($deadCfg['printers'] ?? []) as $nr) { $deadCfg['printers'][$nr] = ['target' => 'network', 'host' => '127.0.0.1', 'port' => 9, 'name' => 'DEAD' . $nr]; }
    savePrintCfg($deadCfg);
    $kjid = insJob('kitchen', ['masa' => 1, 'casier' => 'TEST', 'nrBon' => 1, 'dataOra' => '01-01-26 00:00', 'lines' => [['denumire' => 'TEST PRODUS', 'cant' => 1]]], 1);
    $createdJobs[] = $kjid;
    $ok = false; $seen = null;
    for ($i = 0; $i < 12; $i++) { usleep(600000); $seen = job($kjid); if ((int)$seen['Attempts'] >= 1 && trim($seen['Stare']) === 'pending') { $ok = true; break; } }
    test('job pe tinta moarta: reincercat (Attempts>=1) si ramane pending', $ok, json_encode($seen));
    test('job pe tinta moarta: LastError populat', !empty($seen['LastError']), 'err=' . substr((string)$seen['LastError'], 0, 80));
    // hot-reload: aceeasi instanta de serviciu, tinta noua -> livrare reusita
    savePrintCfg($previewCfg);
    $done = false;
    for ($i = 0; $i < 15; $i++) { usleep(600000); $s = job($kjid); if (trim($s['Stare']) === 'done') { $done = true; break; } }
    test('dupa hot-reload pe preview: jobul devine done', $done, json_encode(job($kjid)));
    test('emulator: preview pentru jobul de bucatarie', is_file("$PREV/job_$kjid.html"), 'lipseste job_' . $kjid . '.html');
    if (is_file("$PREV/job_$kjid.html")) {
        $html = file_get_contents("$PREV/job_$kjid.html");
        test('preview-ul de bucatarie contine produsul', stripos($html, 'TEST PRODUS') !== false, 'len=' . strlen($html));
    }

    /* ------------------------------------------------ E5 contract coada export */
    section('E5 - contract coada de export (sync)');
    $scfg = $origSync; $scfg['poll_interval_sec'] = 3600;
    $r = httpRaw('http://127.0.0.1:8757/config', 'POST', ['config' => $scfg]);
    test('salvare config sync (poll mare)', ($r['json']['status'] ?? '') === 'success', substr($r['body'], 0, 120));
    sleep(1);
    q("INSERT INTO temp_Send_Sql (DocID,str_sql,Preluat,Stare,Attempts,NextAttempt,CreatedAt) VALUES (NULL,?,'0','pending',0,GETDATE(),GETDATE())", ['SELECT 1']);
    $sid = (int)cell("SELECT MAX(Id) FROM temp_Send_Sql");
    $sc = jget('/api/send_queue.php?action=claim');
    test('send claim ridica randul de test', ($sc['json']['job']['Id'] ?? 0) === $sid, 'body=' . substr($sc['body'], 0, 120));
    test('send claim intoarce conexiunea externa', isset($sc['json']['db']), substr($sc['body'], 0, 80));
    jpost('/api/send_queue.php', ['action' => 'fail', 'id' => $sid, 'error' => 'test', 'retryInSeconds' => 600]);
    $srow = q1("SELECT Stare,Preluat FROM temp_Send_Sql WHERE Id=?", [$sid]);
    test('send fail cu retry -> pending, netrimis', trim($srow['Stare']) === 'pending' && (int)$srow['Preluat'] === 0, json_encode($srow));
    jpost('/api/send_queue.php', ['action' => 'done', 'id' => $sid]);
    $srow = q1("SELECT Stare,Preluat FROM temp_Send_Sql WHERE Id=?", [$sid]);
    test('send done -> Stare=done, Preluat=1', trim($srow['Stare']) === 'done' && (int)$srow['Preluat'] === 1, json_encode($srow));
    q("DELETE FROM temp_Send_Sql WHERE Id=?", [$sid]);
    httpRaw('http://127.0.0.1:8757/config', 'POST', ['config' => $origSync]);

} finally {
    foreach ($createdJobs as $j) { delJob($j); }
    if ($origCfg) savePrintCfg($origCfg);
    if ($origSync) httpRaw('http://127.0.0.1:8757/config', 'POST', ['config' => $origSync]);
}

// verificare finala: configuratia initiala restaurata
$fin = jget('/api/print_config.php?action=config');
$okRestore = ($fin['json']['config']['nota_target'] ?? null) == ($origCfg['nota_target'] ?? null);
test('config print restaurata', $okRestore, json_encode($fin['json']['config']['nota_target'] ?? null));
test('print-service inca online dupa teste', ($fin['json']['service_up'] ?? false) === true);

exit(summary('Faza E') > 0 ? 1 : 0);
