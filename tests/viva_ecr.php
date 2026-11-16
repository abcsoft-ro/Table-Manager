<?php
/**
 * Faza C3 - POS bancar, driverul Viva ECR (protocol TCP/IP).
 *
 * Nu atinge baza de date: testeaza registrul de protocoale, citirea .env si
 * ruleaza driverul real tools/pos_banca/viva_client.py contra simulatorului
 * tools/pos_banca/mock_viva_terminal.py (vanzare aprobata/refuzata, ultima
 * tranzactie, anulare, raspuns lipsa = UNKNOWN, terminal mut).
 *
 * Daca Python (.venv) nu este disponibil, testeaza doar partea statica si sare
 * peste partea de retea (fara esec), ca harness-ul sa poata rula si pe o
 * masina fara venv.
 */
require __DIR__ . '/lib.php';
require_once ROOT_DIR . '/api/pos_banca_protocol.php';
require_once ROOT_DIR . '/api/pos_banca_driver.php';
require_once ROOT_DIR . '/api/pos_banca_viva.php';

$tmpDir = rtrim(sys_get_temp_dir(), '\\/') . '\\viva_ecr_test_' . getmypid();
// Porturi locale diferite de la un run la altul, ca o instanta ramasa dintr-un
// run intrerupt sa nu blocheze testul.
$vivaPorts = [];
$vivaPortBase = 18000 + (getmypid() % 700);
for ($i = 0; $i < 4; $i++) { $vivaPorts[] = $vivaPortBase + $i; }

function vivaRun($script, $bat, $pyDir, $port, array $args, $out, $log, $driverMs = 30000) {
    $base = ['--host', '127.0.0.1', '--port', (string)$port, '--timeout', '10'];
    return posBancaRunCommand(
        $script, $bat, $pyDir, array_merge($base, $args), $out, $log, $driverMs
    );
}

$procs = [];
$mockLogs = [];
$journal = $tmpDir . '\\jurnal_viva.json';
try {
    /* ------------------------------------------------ C3.1 - registru / .env */
    section('C3.1 - registrul de protocoale si conexiunea din .env');
    $all = posBancaProtocols();
    test('registrul contine SmartPayECR si VivaECR',
        isset($all['SmartPayECR']) && isset($all['VivaECR']),
        implode(', ', array_keys($all)));
    test('normalizarea pastreaza VivaECR',
        posBancaProtocolNormalize('VivaECR') === 'VivaECR',
        posBancaProtocolNormalize('VivaECR'));
    test('o valoare necunoscuta cade pe protocolul implicit',
        posBancaProtocolNormalize('Generic') === posBancaProtocolDefault(),
        posBancaProtocolNormalize('Generic'));
    $vivaCaps = posBancaProtocolCaps('VivaECR');
    test('VivaECR are jurnal / void / conexiune .env',
        !empty($vivaCaps['journal']) && !empty($vivaCaps['void'])
        && !empty($vivaCaps['envConnection']),
        json_encode($vivaCaps));
    test('VivaECR foloseste viva_client.py',
        (posBancaProtocolInfo('VivaECR')['script'] ?? '') === 'viva_client.py');

    if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0777, true); }
    @file_put_contents($tmpDir . '\\.env', "ECR_HOST=10.20.30.40\nECR_PORT=8080\n");
    $tmpBat = $tmpDir . '\\vanzare.bat';
    @file_put_contents($tmpBat, "@echo off\r\n");
    $conn = posBancaVivaConnectionInfo($tmpBat);
    test('conexiunea Viva se citeste din .env (TCP host:port)',
        is_array($conn) && ($conn['transport'] ?? '') === 'tcp'
        && ($conn['host'] ?? '') === '10.20.30.40'
        && ($conn['port'] ?? '') === '8080',
        json_encode($conn));
    $env = posBancaReadDotEnv($tmpDir . '\\.env');
    test('fisierul .env este parsat (cheie=valoare)',
        ($env['ECR_HOST'] ?? '') === '10.20.30.40', json_encode($env));

    // Potrivirea cu ultima tranzactie din terminal (fara baza de date).
    $nowT = 1000100;
    $match = posBancaVivaEvaluateReceipt(25.50, 1000000, '25.50', 1000010,
        '123456789012', false, $nowT);
    test('resync: ultima tranzactie cu suma/timp/RRN potrivite -> confirmat',
        $match[0] === true, json_encode($match));
    $wrongAmount = posBancaVivaEvaluateReceipt(25.50, 1000000, '25.49', 1000010,
        '123456789012', false, $nowT);
    test('resync: alta suma -> neconfirmat',
        $wrongAmount[0] === false && stripos($wrongAmount[1], 'alta suma') !== false,
        json_encode($wrongAmount));
    $oldTxn = posBancaVivaEvaluateReceipt(25.50, 1000000, '25.50', 998000,
        '123456789012', false, $nowT);
    test('resync: tranzactie din alt interval orar (veche) -> neconfirmat',
        $oldTxn[0] === false && stripos($oldTxn[1], 'interval orar') !== false,
        json_encode($oldTxn));
    $futureTxn = posBancaVivaEvaluateReceipt(25.50, 1000000, '25.50', $nowT + 500,
        '123456789012', false, $nowT);
    test('resync: tranzactie cu data in viitor -> neconfirmat',
        $futureTxn[0] === false, json_encode($futureTxn));
    $seenRrn = posBancaVivaEvaluateReceipt(25.50, 1000000, '25.50', 1000010,
        '123456789012', true, $nowT);
    test('resync: RRN deja inregistrat (nu dubla suma) -> neconfirmat',
        $seenRrn[0] === false && stripos($seenRrn[1], 'deja') !== false,
        json_encode($seenRrn));
    $missingRrn = posBancaVivaEvaluateReceipt(25.50, 1000000, '25.50', 1000010,
        '', false, $nowT);
    test('resync: fara RRN -> neconfirmat',
        $missingRrn[0] === false && stripos($missingRrn[1], 'RRN') !== false,
        json_encode($missingRrn));
    $zeroAttempt = posBancaVivaEvaluateReceipt(0, 1000000, '25.50', 1000010,
        '123456789012', false, $nowT);
    test('resync: suma incercarii invalida -> neconfirmat',
        $zeroAttempt[0] === false, json_encode($zeroAttempt));

    /* ------------------------------------------------ C3.2 - driver real */
    $pyDir = posBancaFindPythonDir();
    if ($pyDir === '') {
        info('Python (.venv) nu a fost gasit: testele de retea Viva sunt sarite.');
        summary('C3 - Viva ECR');
        exit($GLOBALS['__exit']);
    }
    $py = rtrim($pyDir, '\\/') . '\\python.exe';
    $scriptSrc = ROOT_DIR . '/tools/pos_banca/viva_client.py';
    $mockSrc = ROOT_DIR . '/tools/pos_banca/mock_viva_terminal.py';
    test('driverul si simulatorul Viva exista in tools/pos_banca',
        is_file($scriptSrc) && is_file($mockSrc));
    @copy($scriptSrc, $tmpDir . '\\viva_client.py');
    @copy(ROOT_DIR . '/tools/pos_banca/env_config.py', $tmpDir . '\\env_config.py');
    $script = $tmpDir . '\\viva_client.py';
    $driverLog = $tmpDir . '\\driver.log';
    $out = $tmpDir . '\\rezultat_ecr.txt';

    section('C3.2 - autotestul driverului (framing + exemple din documentatie)');
    $cmd = '"' . $py . '" "' . $script . '" selftest';
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $st = @proc_open($cmd, $desc, $pipes, dirname($script), null);
    $selftestOut = '';
    if (is_resource($st)) {
        $selftestOut = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = @proc_close($st);
    } else {
        $code = -1;
    }
    test('php tests: viva_client.py selftest trece',
        $code === 0, substr(trim($selftestOut), -600));
    info('selftest: ' . trim((string)substr($selftestOut, -200)));

    section('C3.3 - vanzare aprobata / ultima tranzactie / anulare');
    list($mock, $up, $mockLog) = posBancaMockStart($py, $mockSrc, $vivaPorts[0], ['--mode', 'ok', '--journal-file', '"' . $journal . '"']);
    $procs[] = $mock;
    $mockLogs[] = $mockLog;
    test('simulatorul Viva asculta pe 127.0.0.1:' . $vivaPorts[0], $up, (string)@file_get_contents($mockLog));

    $res = vivaRun($script, $tmpBat, $pyDir, $vivaPorts[0],
        ['sale', '--amount', '25.50', '--tx', 'VIVATEST0001'], $out, $driverLog);
    $f = $res['fields'];
    test('vanzare aprobata: STATUS=OK / EMITE_BON=DA',
        ($f['STATUS'] ?? '') === 'OK' && ($f['EMITE_BON'] ?? '') === 'DA',
        $res['raw']);
    test('vanzare aprobata: RRN (12 cifre), STAN, suma',
        preg_match('/^\d{12}$/', (string)($f['RRN'] ?? '')) === 1
        && (string)($f['STAN'] ?? '') !== ''
        && (string)($f['SUMA'] ?? '') === '25.50',
        $res['raw']);
    $stan = (string)($f['STAN'] ?? '');
    $rrn = (string)($f['RRN'] ?? '');

    $res = vivaRun($script, $tmpBat, $pyDir, $vivaPorts[0], ['last-receipt'], $out, $driverLog);
    $f = $res['fields'];
    test('ultima tranzactie: STATUS=OK, aceeasi suma si RRN',
        ($f['STATUS'] ?? '') === 'OK'
        && (string)($f['AMOUNT'] ?? '') === '25.50'
        && (string)($f['RRN'] ?? '') === $rrn
        && (int)($f['TXN_EPOCH'] ?? 0) > 0,
        $res['raw']);

    $res = vivaRun($script, $tmpBat, $pyDir, $vivaPorts[0],
        ['void', '--stan', $stan, '--amount', '25.50'], $out, $driverLog);
    $f = $res['fields'];
    test('anulare: VOIDED=1 si STATUS=OK',
        ($f['STATUS'] ?? '') === 'OK' && (string)($f['VOIDED'] ?? '') === '1',
        $res['raw']);
    posBancaMockStop($mock);

    section('C3.4 - refuz normal, raspuns lipsa (UNKNOWN), terminal mut');
    list($mock, $up, $mockLog) = posBancaMockStart($py, $mockSrc, $vivaPorts[1], ['--mode', 'decline']);
    $procs[] = $mock;
    $mockLogs[] = $mockLog;
    test('simulatorul Viva (decline) asculta', $up, (string)@file_get_contents($mockLog));
    $res = vivaRun($script, $tmpBat, $pyDir, $vivaPorts[1],
        ['sale', '--amount', '10.00', '--tx', 'VIVATEST0002'], $out, $driverLog);
    $f = $res['fields'];
    test('refuz: STATUS=ERROR / EMITE_BON=NU / cod 51',
        ($f['STATUS'] ?? '') === 'ERROR'
        && ($f['EMITE_BON'] ?? '') === 'NU'
        && (string)($f['CODE'] ?? '') === '51',
        $res['raw']);
    posBancaMockStop($mock);

    list($mock, $up, $mockLog) = posBancaMockStart(
        $py, $mockSrc, $vivaPorts[2],
        ['--mode', 'accept-silent', '--silent-seconds', '5', '--journal-file', '"' . $journal . '"']
    );
    $procs[] = $mock;
    $mockLogs[] = $mockLog;
    test('simulatorul Viva (accept-silent) asculta', $up, (string)@file_get_contents($mockLog));
    $res = posBancaRunCommand(
        $script, $tmpBat, $pyDir,
        ['--host', '127.0.0.1', '--port', (string)$vivaPorts[2], '--timeout', '3',
         'sale', '--amount', '12.00', '--tx', 'VIVATEST0003'],
        $out, $driverLog, 20000
    );
    $f = $res['fields'];
    test('raspuns lipsa dupa trimitere: STATUS=UNKNOWN (nu ERROR)',
        ($f['STATUS'] ?? '') === 'UNKNOWN' && ($f['EMITE_BON'] ?? '') === 'NU',
        $res['raw']);
    $res = vivaRun($script, $tmpBat, $pyDir, $vivaPorts[2], ['last-receipt'], $out, $driverLog);
    $f = $res['fields'];
    test('dupa UNKNOWN, ultima tranzactie contine suma incercata',
        ($f['STATUS'] ?? '') === 'OK' && (string)($f['AMOUNT'] ?? '') === '12.00',
        $res['raw']);
    posBancaMockStop($mock);

    list($mock, $up, $mockLog) = posBancaMockStart($py, $mockSrc, $vivaPorts[3], ['--mode', 'disconnect']);
    $procs[] = $mock;
    $mockLogs[] = $mockLog;
    test('simulatorul Viva (disconnect) asculta', $up, (string)@file_get_contents($mockLog));
    $res = vivaRun($script, $tmpBat, $pyDir, $vivaPorts[3],
        ['sale', '--amount', '9.00', '--tx', 'VIVATEST0004'], $out, $driverLog);
    $f = $res['fields'];
    test('terminal mut: STATUS=ERROR + mesaj de conectare (retry sigur)',
        ($f['STATUS'] ?? '') === 'ERROR'
        && stripos((string)($f['MESAJ'] ?? ''), 'Nu s-a putut conecta') !== false,
        $res['raw']);
    posBancaMockStop($mock);
} finally {
    foreach ($procs as $proc) { posBancaMockStop($proc); }
    // glob cu '/' functioneaza si pe Windows; fisierele care incep cu punct
    // (.env) nu sunt prinse de '*', deci le stergem explicit.
    foreach ((array)glob($tmpDir . '/*') as $file) {
        if (is_dir($file)) {
            foreach ((array)glob($file . '/*') as $sub) { @unlink($sub); }
            @rmdir($file);
        } else {
            @unlink($file);
        }
    }
    @unlink($tmpDir . '/.env');
    @rmdir($tmpDir);
    foreach ($mockLogs as $mockLogFile) { @unlink($mockLogFile); }
}

summary('C3 - Viva ECR');
exit($GLOBALS['__exit']);
