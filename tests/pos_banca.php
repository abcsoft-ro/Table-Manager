<?php
require __DIR__ . '/lib.php';
// Functiile pure de clasificare a unei incercari (fara conexiune la baza).
require_once __DIR__ . '/../api/pos_banca_state.php';
require_once __DIR__ . '/../api/pos_banca_driver.php';

$docId = 999990;
$lockDoc = 0;
$lockMasa = 0;
$plainDoc = 0;
$zeroDoc = 0;
$reconDoc = 0;
$voidDoc = 0;
$repDoc = 0;
$repEmptyDoc = 0;
$payDoc = 0;
$pendDoc = 0;
$origServer = cell("SELECT Value FROM tblSet WHERE Setting='Server'");
$hadDriverRow = ((int)cell("SELECT COUNT(*) FROM tblSet WHERE Setting='CaleDriverPoSbanca'")) > 0;
$origDriver = $hadDriverRow ? cell("SELECT Value FROM tblSet WHERE Setting='CaleDriverPoSbanca'") : null;
$hadProtocolRow = ((int)cell("SELECT COUNT(*) FROM tblSet WHERE Setting='ProtocolPoSbanca'")) > 0;
$origProtocol = $hadProtocolRow ? cell("SELECT Value FROM tblSet WHERE Setting='ProtocolPoSbanca'") : null;
$origPoSbanca = cell("SELECT Value FROM tblSet WHERE Setting='PoSbanca'");
$hadRetiparire = ((int)cell("SELECT COUNT(*) FROM tblSet WHERE Setting='RetiparireChitantaPoSbanca'")) > 0;
$origRetiparire = $hadRetiparire ? cell("SELECT Value FROM tblSet WHERE Setting='RetiparireChitantaPoSbanca'") : null;
$origResultFile = cell("SELECT Value FROM tblSet WHERE Setting='CaleFisierRaspunsPoSbanca'");
$procs = [];
$mockLogs = [];
$tmpDir = rtrim(sys_get_temp_dir(), '\\/') . '\\pos_banca_test_' . getmypid();
$tmpBat = $tmpDir . '\\vanzare.bat';

try {
    // Driver de test izolat: nu depinde de .env-ul real al masinii. Scriptul
    // contine markerul `_find_record` cerut de actiunea resync; verificarea
    // manuala se opreste inainte de a-l executa.
    if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0777, true); }
    @file_put_contents($tmpBat, "@echo off\r\n");
    @file_put_contents($tmpDir . '\\.env', "ECR_TRANSPORT=tcp\nECR_HOST=10.1.2.3\nECR_PORT=6000\n");
    @file_put_contents($tmpDir . '\\smartpay_client.py', "# _find_record\n");
    // Numele curent al driverului SmartPay, cu fallback pe numele vechi.
    $drvNew = posBancaDriverScript($tmpBat);
    test('driverul SmartPay este gasit ca smartpay_client.py',
        basename(str_replace('\\', '/', (string)$drvNew)) === 'smartpay_client.py',
        (string)$drvNew);
    @rename($tmpDir . '\\smartpay_client.py', $tmpDir . '\\ecr_client.py');
    $drvLegacy = posBancaDriverScript($tmpBat);
    test('numele vechi ecr_client.py rămâne acceptat (compatibilitate)',
        basename(str_replace('\\', '/', (string)$drvLegacy)) === 'ecr_client.py',
        (string)$drvLegacy);
    @rename($tmpDir . '\\ecr_client.py', $tmpDir . '\\smartpay_client.py');
    if ($hadDriverRow) {
        q("UPDATE tblSet SET Value=? WHERE Setting='CaleDriverPoSbanca'", [$tmpBat]);
    } else {
        q("INSERT INTO tblSet (Setting, Value, Descriere, Grup) VALUES ('CaleDriverPoSbanca', ?, 'Test POS bancar', 'Casa')", [$tmpBat]);
    }
    // Fara export catre serverul extern: notele de test nu trebuie sa ajunga in DataRON.
    q("UPDATE tblSet SET Value='0' WHERE Setting='Server'");
    if ($hadProtocolRow) {
        q("UPDATE tblSet SET Value='SmartPayECR' WHERE Setting='ProtocolPoSbanca'");
    } else {
        q("INSERT INTO tblSet (Setting, Value, Descriere, Grup) VALUES ('ProtocolPoSbanca', 'SmartPayECR', 'Test POS bancar', 'PoSbanca')");
    }

    // Primul status creeaza tabela de audit daca nu exista.
    jpost('/api/pos_banca.php', ['action' => 'status', 'docId' => $docId]);
    q("DELETE FROM tblPosBancaLog WHERE DocID=?", [$docId]);
    q("INSERT INTO tblPosBancaLog (DocID, NrDoc, Suma, TrxId, Status, Mesaj, EmiteBon, Actiune, CreatedAt)
       VALUES (?, ?, ?, ?, 'UNKNOWN', 'Stare necunoscuta: terminalul nu a confirmat rezultatul.', 0, NULL, GETDATE())",
       [$docId, 0, 34.50, 'TXTEST20261029']);

    // Terminal indisponibil: driverul a esuat INAINTE sa trimita comanda, deci
    // cardul nu a putut fi debitat. E o eroare de legatura („terminalul nu
    // raspunde”), nu o stare incerta: nota rămâne deblocata si reincercarea e
    // sigura. Doar UNKNOWN/TIMEOUT (comanda poate a plecat) cer verificare.
    test('eroare de conectare -> "terminalul nu raspunde" (nu plata incerta)',
        posBancaAttemptIsNoAnswer('ERROR', 'Nu s-a putut conecta la terminalul bancar (POS).')
        && posBancaAttemptIsNoAnswer('ERROR', 'Login esuat: POS nu a raspuns cu ACK')
        && posBancaAttemptIsNoAnswer('ERROR', 'Eroare de comunicatie cu terminalul bancar (POS).'),
        'clasificare conectare');
    test('UNKNOWN / TIMEOUT / refuz normal NU sunt "terminalul nu raspunde"',
        !posBancaAttemptIsNoAnswer('UNKNOWN', 'Stare necunoscuta: terminalul a acceptat comanda.')
        && !posBancaAttemptIsNoAnswer('TIMEOUT', 'Terminalul nu a returnat rezultat in timp util.')
        && !posBancaAttemptIsNoAnswer('ERROR', 'Tranzactie respinsa. Card refuzat.'),
        'clasificare refuz');
    test('comanda care a plecat (UNKNOWN) rămâne incerta',
        posBancaAttemptIsUncertain('UNKNOWN', 'Stare necunoscuta: terminalul a acceptat comanda.')
        && !posBancaAttemptIsUncertain('ERROR', 'Nu s-a putut conecta la terminalul bancar (POS).'),
        'separare incert / indisponibil');
    // PENDING = randul scris INAINTE de lansarea driverului. Este incertitudinea
    // cea mai puternica: cardul poate fi debitat, iar un Ctrl+F5 in timpul
    // asteptarii trebuie sa gaseasca nota blocata.
    test('comanda in curs (PENDING) este tratata ca incerta',
        posBancaAttemptIsUncertain('PENDING', 'Comanda trimisa catre terminal, se asteapta raspunsul.')
        && !posBancaAttemptIsNoAnswer('PENDING', 'Comanda trimisa catre terminal, se asteapta raspunsul.'),
        'clasificare PENDING');
    test('PENDING recent blocheaza Verifica (verificare prematura)',
        posBancaAttemptPendingFresh('PENDING', date('Y-m-d H:i:s')) === true
        && posBancaAttemptPendingFresh('PENDING', date('Y-m-d H:i:s', time() - 400)) === false
        && posBancaAttemptPendingFresh('UNKNOWN', date('Y-m-d H:i:s')) === false,
        'age gate PENDING');

    $st = jpost('/api/pos_banca.php', ['action' => 'status', 'docId' => $docId]);
    $sj = $st['json'] ?: [];
    test('status UNKNOWN -> uncertain', ($sj['uncertain'] ?? null) === true, substr($st['body'], 0, 200));
    test('status intoarce suma incercarii', (string)($sj['amount'] ?? '') === '34.50', (string)($sj['amount'] ?? ''));
    $conn = $sj['connection'] ?? null;
    test('connection citit din .env (TCP host:port)',
        is_array($conn) && ($conn['transport'] ?? '') === 'tcp'
        && ($conn['host'] ?? '') === '10.1.2.3' && ($conn['port'] ?? '') === '6000',
        json_encode($conn));
    info('connection TCP test: ' . json_encode($conn));

    $man = jpost('/api/pos_banca.php', [
        'action' => 'resolve_manual', 'docId' => $docId, 'verdict' => 'neaprobat',
        'nrOp' => '8', 'sursa' => 'test'
    ]);
    $mj = $man['json'] ?: [];
    test('manual neaprobat -> success', ($mj['status'] ?? '') === 'success', substr($man['body'], 0, 200));
    $row = q1("SELECT TOP 1 * FROM tblPosBancaLog WHERE DocID=? AND Status='MANUAL' ORDER BY Id DESC", [$docId]);
    test('manual scrie Status=MANUAL / Actiune=neaprobat', $row && $row['Status'] === 'MANUAL' && $row['Actiune'] === 'neaprobat', json_encode($row));
    test('manual este semnat cu operatorul', $row && strpos((string)$row['Mesaj'], 'NEAPROBAT') !== false && strpos((string)$row['Mesaj'], '(8)') !== false, (string)($row['Mesaj'] ?? ''));
    test('manual nu stocheaza parola', $row && stripos((string)$row['Detalii'], 'parola') === false, (string)($row['Detalii'] ?? ''));

    $st2 = jpost('/api/pos_banca.php', ['action' => 'status', 'docId' => $docId]);
    $s2 = $st2['json'] ?: [];
    test('dupa manual neaprobat -> uncertain=false', ($s2['uncertain'] ?? null) === false, substr($st2['body'], 0, 200));

    // A doua incercare incerta, apoi manual aprobat.
    q("INSERT INTO tblPosBancaLog (DocID, NrDoc, Suma, TrxId, Status, Mesaj, EmiteBon, Actiune, CreatedAt)
       VALUES (?, ?, ?, ?, 'UNKNOWN', 'Stare necunoscuta: terminalul nu a confirmat rezultatul.', 0, NULL, GETDATE())",
       [$docId, 0, 12.00, 'TXTEST20261030']);
    // Aprobarea manuala cere AUTHCODE-ul de pe chitanta terminalului: EXACT 6
    // caractere alfanumerice. Fara el, un operator ar putea declara o plata pe
    // card fara ca terminalul sa fi incasat nimic (frauda interna).
    $noCod = jpost('/api/pos_banca.php', [
        'action' => 'resolve_manual', 'docId' => $docId, 'verdict' => 'aprobat',
        'nrOp' => '8', 'sursa' => 'test'
    ]);
    test('manual aprobat fara cod -> 400 auth_code_required',
        $noCod['code'] === 400 && (($noCod['json']['code'] ?? '') === 'auth_code_required'),
        'code=' . $noCod['code'] . ' ' . substr($noCod['body'], 0, 200));
    $badCod = jpost('/api/pos_banca.php', [
        'action' => 'resolve_manual', 'docId' => $docId, 'verdict' => 'aprobat',
        'cod' => '12345', 'nrOp' => '8', 'sursa' => 'test'
    ]);
    test('manual aprobat cu cod invalid (5 caractere) -> 400', $badCod['code'] === 400,
        'code=' . $badCod['code']);
    $badChars = jpost('/api/pos_banca.php', [
        'action' => 'resolve_manual', 'docId' => $docId, 'verdict' => 'aprobat',
        'cod' => 'AB-12C', 'nrOp' => '8', 'sursa' => 'test'
    ]);
    test('manual aprobat cu caractere nepermise -> 400', $badChars['code'] === 400,
        'code=' . $badChars['code']);
    // Vechiul RRN de 12 cifre nu mai este acceptat: se cere AUTHCODE de 6.
    $rrn12 = jpost('/api/pos_banca.php', [
        'action' => 'resolve_manual', 'docId' => $docId, 'verdict' => 'aprobat',
        'cod' => '001234567890', 'nrOp' => '8', 'sursa' => 'test'
    ]);
    test('manual aprobat cu RRN de 12 cifre -> 400 (se cere AUTHCODE)', $rrn12['code'] === 400,
        'code=' . $rrn12['code']);
    // AUTHCODE poate fi alfanumeric; literele mici se normalizeaza la majuscule.
    $man2 = jpost('/api/pos_banca.php', [
        'action' => 'resolve_manual', 'docId' => $docId, 'verdict' => 'aprobat',
        'cod' => 'ab12cd', 'nrOp' => '8', 'sursa' => 'test'
    ]);
    $m2 = $man2['json'] ?: [];
    test('manual aprobat cu AUTHCODE -> success', ($m2['status'] ?? '') === 'success', substr($man2['body'], 0, 200));
    $rowM = q1("SELECT TOP 1 * FROM tblPosBancaLog WHERE DocID=? AND Status='MANUAL' ORDER BY Id DESC", [$docId]);
    test('AUTHCODE se consemneaza in audit (Detalii, majuscule)',
        $rowM && strpos((string)$rowM['Detalii'], '"authCode":"AB12CD"') !== false
            && strpos((string)$rowM['Detalii'], '"authType":"auth"') !== false,
        substr((string)($rowM['Detalii'] ?? ''), 0, 220));
    test('AUTHCODE se salveaza in coloana AuthCode (nu in Rrn)',
        $rowM && (string)($rowM['AuthCode'] ?? '') === 'AB12CD'
            && (string)($rowM['Rrn'] ?? '') === '',
        json_encode(['Rrn' => $rowM['Rrn'] ?? null, 'AuthCode' => $rowM['AuthCode'] ?? null]));
    $st3 = jpost('/api/pos_banca.php', ['action' => 'status', 'docId' => $docId]);
    $s3 = $st3['json'] ?: [];
    test('dupa manual aprobat -> uncertain=true + manualApproved', ($s3['uncertain'] ?? null) === true && ($s3['manualApproved'] ?? null) === true, substr($st3['body'], 0, 250));
    test('manual aprobat intoarce suma', (string)($s3['amount'] ?? '') === '12.00', (string)($s3['amount'] ?? ''));

    // Resync-ul nu are voie sa suprascrie decizia manuala.
    $rs = jpost('/api/pos_banca.php', ['action' => 'resync', 'docId' => $docId, 'uid' => 'TXTEST20261030']);
    $rj = $rs['json'] ?: [];
    test('resync dupa manual -> raspunde MANUAL, nu ruleaza terminalul',
        ($rj['posStatus'] ?? '') === 'MANUAL' && ($rj['manual'] ?? null) === true,
        substr($rs['body'], 0, 250));

    // Un resync care a gasit deja plata aprobata nu permite manual "Neaprobat".
    q("INSERT INTO tblPosBancaLog (DocID, NrDoc, Suma, TrxId, Status, Mesaj, EmiteBon, Actiune, CreatedAt)
       VALUES (?, ?, ?, ?, 'UNKNOWN', 'Stare necunoscuta: terminalul nu a confirmat rezultatul.', 0, NULL, GETDATE())",
       [$docId, 0, 7.00, 'TXTEST20261031']);
    q("INSERT INTO tblPosBancaLog (DocID, NrDoc, Suma, TrxId, Status, Mesaj, EmiteBon, Actiune, Rrn, Stan, AuthCode, CreatedAt)
       VALUES (?, ?, ?, ?, 'RESYNC', 'FOUND=1 APPROVED=1 Tranzactia a fost gasita in jurnalul terminalului.', 0, 'verificat', '123456789012', '000042', '111111', GETDATE())",
       [$docId, 0, 6.50, 'TXTEST20261031']);
    $st5 = jpost('/api/pos_banca.php', ['action' => 'status', 'docId' => $docId]);
    $s5 = $st5['json'] ?: [];
    test('status dupa resync aprobat -> resyncApproved=true',
        ($s5['uncertain'] ?? null) === true && ($s5['resyncApproved'] ?? null) === true,
        substr($st5['body'], 0, 250));
    test('status raporteaza suma confirmata de terminal (nu pe cea ceruta)',
        (string)($s5['amount'] ?? '') === '6.50', (string)($s5['amount'] ?? '?'));
    $bad = jpost('/api/pos_banca.php', [
        'action' => 'resolve_manual', 'docId' => $docId, 'verdict' => 'neaprobat',
        'nrOp' => '8', 'sursa' => 'test'
    ]);
    test('manual neaprobat dupa resync aprobat -> 400', $bad['code'] === 400, 'code=' . $bad['code']);
    // Decizie arhitecturala: daca resync-ul a extras tranzactia electronic, nota
    // se inchide automat, deci codul tastat NU se mai compara cu jurnalul. Cand
    // se ajunge la solutionarea manuala, terminalul nu a putut fi citit - deci
    // codul e doar capturat pentru reconcilierea Z-ului bancar.
    $good = jpost('/api/pos_banca.php', [
        'action' => 'resolve_manual', 'docId' => $docId, 'verdict' => 'aprobat',
        'cod' => 'A1B2C3', 'nrOp' => '8', 'sursa' => 'test'
    ]);
    test('manual aprobat acceptat pe format (fara comparatie cu jurnalul)',
        ($good['json']['status'] ?? '') === 'success', substr($good['body'], 0, 200));
    $rowGood = q1("SELECT TOP 1 Rrn, AuthCode FROM tblPosBancaLog WHERE DocID=? AND Status='MANUAL' ORDER BY Id DESC", [$docId]);
    test('AUTHCODE se salveaza in AuthCode, indexat (nu in Rrn)',
        $rowGood && (string)($rowGood['AuthCode'] ?? '') === 'A1B2C3'
            && (string)($rowGood['Rrn'] ?? '') === '',
        json_encode(['Rrn' => $rowGood['Rrn'] ?? null, 'AuthCode' => $rowGood['AuthCode'] ?? null]));

    // Un void esuat inseamna ca plata a rămas incarcata pe card: nu permitem
    // manual "Neaprobat" (ar inchide nota cu numerar peste o incasare pe card).
    q("INSERT INTO tblPosBancaLog (DocID, NrDoc, Suma, TrxId, Status, Mesaj, EmiteBon, Actiune, CreatedAt)
       VALUES (?, ?, ?, ?, 'UNKNOWN', 'Stare necunoscuta: terminalul nu a confirmat rezultatul.', 0, NULL, GETDATE())",
       [$docId, 0, 9.00, 'TXTEST20261101']);
    q("INSERT INTO tblPosBancaLog (DocID, NrDoc, Suma, TrxId, Status, Mesaj, EmiteBon, Actiune, CreatedAt)
       VALUES (?, ?, ?, ?, 'VOID', 'Anularea a esuat; reincercati mai tarziu.', 0, NULL, GETDATE())",
       [$docId, 0, 9.00, 'TXTEST20261101']);
    $st6 = jpost('/api/pos_banca.php', ['action' => 'status', 'docId' => $docId]);
    $s6 = $st6['json'] ?: [];
    test('status dupa void esuat -> resyncApproved=true',
        ($s6['uncertain'] ?? null) === true && ($s6['resyncApproved'] ?? null) === true,
        substr($st6['body'], 0, 250));
    $bad2 = jpost('/api/pos_banca.php', [
        'action' => 'resolve_manual', 'docId' => $docId, 'verdict' => 'neaprobat',
        'nrOp' => '8', 'sursa' => 'test'
    ]);
    test('manual neaprobat dupa void esuat -> 400', $bad2['code'] === 400, 'code=' . $bad2['code']);

    // Conexiunea seriala este citita din .env, nu hardcodata.
    @file_put_contents($tmpDir . '\\.env', "ECR_TRANSPORT=serial\nECR_SERIAL_PORT=COM99\n");
    $st4 = jpost('/api/pos_banca.php', ['action' => 'status', 'docId' => $docId]);
    $s4 = $st4['json'] ?: [];
    $c4 = $s4['connection'] ?? [];
    test('connection serial citita din .env (COM99)',
        ($c4['transport'] ?? '') === 'serial' && ($c4['serialPort'] ?? '') === 'COM99' && strpos($st4['body'], 'COM3') === false,
        json_encode($c4));
    info('connection serial test: ' . json_encode($c4));

    // Vechiul driver "Generic" a fost eliminat. O valoare rămasă pe o
    // instalatie veche este normalizata la SmartPayECR, deci capabilitatile
    // (jurnal/void) rămân active si conexiunea este citita tot din .env.
    q("UPDATE tblSet SET Value='Generic' WHERE Setting='ProtocolPoSbanca'");
    $stG = jpost('/api/pos_banca.php', ['action' => 'status', 'docId' => $docId]);
    $sg = $stG['json'] ?: [];
    test('valoarea legacy Generic este normalizata la SmartPayECR',
        ($sg['protocol'] ?? '') === 'SmartPayECR', substr($stG['body'], 0, 200));
    test('valoarea legacy Generic pastreaza capabilitatile SmartPay',
        ($sg['canResync'] ?? null) === true && ($sg['canVoid'] ?? null) === true,
        substr($stG['body'], 0, 250));
    test('valoarea legacy Generic citeste conexiunea din .env',
        is_array($sg['connection'] ?? null) && (($sg['connection']['serialPort'] ?? '') === 'COM99'),
        json_encode($sg['connection'] ?? null));

    // Al doilea driver cu identitate clara: VivaECR (ECR Protocol TCP/IP).
    // Foloseste acelasi .env (ECR_HOST/ECR_PORT) si acelasi contract de rezultat;
    // adaptorul este insa altul (api/pos_banca_viva.php).
    @file_put_contents($tmpDir . '\\.env', "ECR_HOST=10.1.2.3\nECR_PORT=6000\n");
    q("UPDATE tblSet SET Value='VivaECR' WHERE Setting='ProtocolPoSbanca'");
    $stV = jpost('/api/pos_banca.php', ['action' => 'status', 'docId' => $docId]);
    $sv = $stV['json'] ?: [];
    test('VivaECR este raportat de status cu jurnal / void',
        ($sv['protocol'] ?? '') === 'VivaECR'
        && ($sv['canResync'] ?? null) === true && ($sv['canVoid'] ?? null) === true,
        substr($stV['body'], 0, 250));
    $cv = $sv['connection'] ?? [];
    test('VivaECR citeste conexiunea TCP din .env',
        ($cv['transport'] ?? '') === 'tcp' && ($cv['host'] ?? '') === '10.1.2.3'
        && ($cv['port'] ?? '') === '6000',
        json_encode($cv));
    $rv = jpost('/api/pos_banca.php', ['action' => 'resync', 'docId' => $docId]);
    test('VivaECR fara viva_client.py -> 400 cu mesaj de instalare',
        $rv['code'] === 400
        && stripos((string)($rv['json']['message'] ?? ''), 'viva_client.py') !== false,
        substr($rv['body'], 0, 240));
    /* ------------------------------------------------------------------
     * Viva ECR cap-coada, cu driverul real si simulatorul local:
     *   - plata fara raspuns -> UNKNOWN; resync confirma ultima tranzactie
     *   - plata aprobata -> STAN/RRN in audit; void dupa STAN
     * Se ruleaza doar pe masina de dezvoltare (are .venv); altfel se sare.
     * ------------------------------------------------------------------ */
    $vivaSilentDoc = 999996;
    $vivaOkDoc = 999997;
    $pyDir = posBancaFindPythonDir();
    if ($pyDir === '') {
        info('Python (.venv) lipseste - testul cap-coada Viva este sarit.');
    } else {
        $py = rtrim($pyDir, '\\/') . '\\python.exe';
        $mockSrc = ROOT_DIR . '/tools/pos_banca/mock_viva_terminal.py';
        @copy(ROOT_DIR . '/tools/pos_banca/viva_client.py', $tmpDir . '\\viva_client.py');
        @copy(ROOT_DIR . '/tools/pos_banca/env_config.py', $tmpDir . '\\env_config.py');
        @copy(ROOT_DIR . '/tools/pos_banca/vanzare-viva.bat', $tmpBat);
        q("UPDATE tblSet SET Value='1' WHERE Setting='PoSbanca'");
        q("UPDATE tblSet SET Value='' WHERE Setting='CaleFisierRaspunsPoSbanca'");

        $vivaJournal = $tmpDir . '\\jurnal_viva.json';
        $vivaPort = 18100 + (getmypid() % 500);
        @file_put_contents($tmpDir . '\\.env',
            "ECR_HOST=127.0.0.1\nECR_PORT=" . $vivaPort . "\nECR_TIMEOUT=3\n");

        list($mock, $up, $mockLog) = posBancaMockStart($py, $mockSrc, $vivaPort,
            ['--mode', 'accept-silent', '--silent-seconds', '6',
             '--journal-file', '"' . $vivaJournal . '"']);
        $procs[] = $mock;
        $mockLogs[] = $mockLog;
        test('Viva: simulatorul (accept-silent) porneste', $up,
            (string)@file_get_contents($mockLog));

        $paySilent = jpost('/api/pos_banca.php', [
            'action' => 'pay', 'docId' => $vivaSilentDoc, 'amount' => 12.00
        ]);
        $ps = $paySilent['json'] ?: [];
        test('Viva: plata fara raspuns -> posStatus UNKNOWN, fara bon',
            ($ps['status'] ?? '') === 'success' && ($ps['posStatus'] ?? '') === 'UNKNOWN'
            && ($ps['emiteBon'] ?? true) === false,
            substr($paySilent['body'], 0, 240));

        $rs = jpost('/api/pos_banca.php', [
            'action' => 'resync', 'docId' => $vivaSilentDoc,
            'uid' => (string)($ps['trxId'] ?? '')
        ]);
        $rj = $rs['json'] ?: [];
        test('Viva: resync confirma ultima tranzactie (found/approved)',
            ($rj['status'] ?? '') === 'success' && ($rj['found'] ?? false) === true
            && ($rj['approved'] ?? false) === true
            && abs((float)($rj['amount'] ?? 0) - 12.0) < 0.005,
            substr($rs['body'], 0, 300));
        $rr = q1("SELECT TOP 1 Mesaj, Rrn, Suma FROM tblPosBancaLog
                  WHERE DocID=? AND Status='RESYNC' ORDER BY Id DESC", [$vivaSilentDoc]);
        test('Viva: audit RESYNC cu APPROVED=1 si RRN',
            $rr && stripos((string)$rr['Mesaj'], 'APPROVED=1') !== false
            && preg_match('/^\d{12}$/', (string)($rr['Rrn'] ?? '')) === 1,
            json_encode($rr));
        $appr = posBancaApprovedTotal(db(), $vivaSilentDoc);
        test('Viva: suma aprobata preluata din resync',
            abs($appr['total'] - 12.0) < 0.005, 'total=' . $appr['total']);

        $vd = jpost('/api/pos_banca.php', ['action' => 'void_doc', 'docId' => $vivaSilentDoc]);
        test('Viva: void_doc fara STAN -> failed (anulare de pe terminal)',
            $vd['code'] === 200 && count($vd['json']['failed'] ?? []) === 1
            && abs((float)($vd['json']['approvedTotal'] ?? 0) - 12.0) < 0.005,
            substr($vd['body'], 0, 240));
        posBancaMockStop($mock);

        // A doua faza: vanzare aprobata, apoi void dupa STAN.
        $okPort = $vivaPort + 1;
        @file_put_contents($tmpDir . '\\.env',
            "ECR_HOST=127.0.0.1\nECR_PORT=" . $okPort . "\nECR_TIMEOUT=10\n");
        list($mock2, $up2, $mockLog2) = posBancaMockStart($py, $mockSrc, $okPort,
            ['--mode', 'ok']);
        $procs[] = $mock2;
        $mockLogs[] = $mockLog2;
        test('Viva: simulatorul (ok) porneste', $up2,
            (string)@file_get_contents($mockLog2));

        $payOk = jpost('/api/pos_banca.php', [
            'action' => 'pay', 'docId' => $vivaOkDoc, 'amount' => 20.00
        ]);
        $po = $payOk['json'] ?: [];
        test('Viva: plata aprobata -> emiteBon + posStatus OK',
            ($po['status'] ?? '') === 'success' && ($po['emiteBon'] ?? false) === true
            && ($po['posStatus'] ?? '') === 'OK',
            substr($payOk['body'], 0, 240));
        $sale = q1("SELECT TOP 1 Stan, Rrn FROM tblPosBancaLog
                    WHERE DocID=? AND EmiteBon=1 ORDER BY Id DESC", [$vivaOkDoc]);
        $stan = trim((string)($sale['Stan'] ?? ''));
        test('Viva: STAN si RRN salvate in audit',
            $stan !== '' && preg_match('/^\d{12}$/', (string)($sale['Rrn'] ?? '')) === 1,
            json_encode($sale));

        $void = jpost('/api/pos_banca.php', [
            'action' => 'void', 'docId' => $vivaOkDoc, 'stan' => $stan, 'amount' => '20.00'
        ]);
        test('Viva: void cu STAN -> voided',
            ($void['json']['status'] ?? '') === 'success'
            && ($void['json']['voided'] ?? false) === true,
            substr($void['body'], 0, 240));
        $appr2 = posBancaApprovedTotal(db(), $vivaOkDoc);
        test('Viva: void scoate suma din approvedTotal',
            abs($appr2['total']) < 0.005, 'total=' . $appr2['total']);
        posBancaMockStop($mock2);
    }

    // Revenim la valoarea legacy folosita de testele urmatoare (void_doc).
    q("UPDATE tblSet SET Value='Generic' WHERE Setting='ProtocolPoSbanca'");

    /* ------------------------------------------------------------------
     * Lock bancar: o nota cu plata pe card incerta nu mai poate fi modificata
     * (adaugare/sterge produse, cantitati, discounturi, transferuri) pana la
     * clarificarea platii. Marcare (print_kitchen) ramane permisa.
     * ------------------------------------------------------------------ */
    $magCfg = (string)cfg('MagID', '11');
    $magId = (int)(explode(',', $magCfg)[0] ?: 1);
    $lockMasa = (int)(cell("SELECT TOP 1 NrMasa FROM tblMese
                             WHERE Afisez = 1
                               AND NrMasa NOT IN (SELECT NrMasa FROM tblBonCurent WHERE Stare = 'D' AND NrMasa IS NOT NULL)
                             ORDER BY NrMasa DESC") ?: 0);
    if ($lockMasa > 0) {
        $lockDoc = (int)cell("INSERT INTO tblBonCurent (NrDoc, Data, Ora, NrMasa, NrOp, TotalB, Stare, MagID)
                              OUTPUT INSERTED.DocID
                              VALUES (0, GETDATE(), GETDATE(), ?, 8, 0, 'D', ?)", [$lockMasa, $magId]);
        q("INSERT INTO tblPosBancaLog (DocID, NrDoc, Suma, TrxId, Status, Mesaj, EmiteBon, Actiune, CreatedAt)
           VALUES (?, 0, 21.00, 'TXTESTLOCK01', 'UNKNOWN', 'Stare necunoscuta: terminalul nu a confirmat rezultatul.', 0, NULL, GETDATE())",
           [$lockDoc]);

        $go = jget('/api/get_order.php?masa=' . $lockMasa);
        $goj = $go['json'] ?: [];
        test('get_order raporteaza nota blocata (bankPosLocked)',
            ($goj['bankPosLocked'] ?? null) === true && (string)($goj['bankPosAmount'] ?? '') === '21.00',
            substr($go['body'], 0, 250));
        // TRX_ID-ul incercarii merge in bannerul de blocare, ca sa poata fi
        // cautat direct in jurnalul terminalului bancar.
        test('get_order intoarce TRX_ID-ul incercarii (bankPosUid)',
            (string)($goj['bankPosUid'] ?? '') === 'TXTESTLOCK01',
            (string)($goj['bankPosUid'] ?? '?'));
        // Doar o incertitudine NEREZOLVATA justifica butoanele Verifica/Manual.
        test('incertitudinea nerezolvata este raportata (bankPosUncertain)',
            ($goj['bankPosUncertain'] ?? null) === true
                && ($goj['bankPosResolved'] ?? null) === false,
            'uncertain=' . json_encode($goj['bankPosUncertain'] ?? null)
                . ' resolved=' . json_encode($goj['bankPosResolved'] ?? null));

        // Banii aprobati pe terminal dar neaplicati pe nota (cazul real: UNKNOWN
        // urmat de resync APPROVED=1): nota rămâne blocata, insa
        // `bankPosApproved` permite clientului sa pastreze NOTA vizibila
        // (plata se continua din ecranul de plata).
        q("INSERT INTO tblPosBancaLog (DocID, NrDoc, Suma, TrxId, Status, Mesaj, EmiteBon, Actiune, Stan, CreatedAt)
           VALUES (?, 0, 21.00, 'TXTESTLOCK01', 'RESYNC', 'FOUND=1 APPROVED=1', 0, 'verificat', '000099', GETDATE())",
           [$lockDoc]);
        $goAp = jget('/api/get_order.php?masa=' . $lockMasa);
        $goApj = $goAp['json'] ?: [];
        test('get_order semnaleaza banii aprobati pe card (bankPosApproved)',
            ($goApj['bankPosLocked'] ?? null) === true
                && ($goApj['bankPosApproved'] ?? null) === true
                && abs((float)($goApj['bankPosApprovedTotal'] ?? 0) - 21.00) < 0.005,
            'locked=' . json_encode($goApj['bankPosLocked'] ?? null)
                . ' approved=' . json_encode($goApj['bankPosApproved'] ?? null)
                . ' total=' . json_encode($goApj['bankPosApprovedTotal'] ?? null));
        q("DELETE FROM tblPosBancaLog WHERE DocID=? AND Status='RESYNC'", [$lockDoc]);

        $lockAdd = jpost('/api/order_action.php', [
            'action' => 'add_product', 'nrMasa' => $lockMasa, 'nrOp' => 8, 'prodId' => 1, 'cantitate' => 1
        ]);
        test('add_product pe nota blocata -> 423 bank_pos_uncertain',
            $lockAdd['code'] === 423 && (($lockAdd['json']['code'] ?? '') === 'bank_pos_uncertain'),
            'code=' . $lockAdd['code'] . ' ' . substr($lockAdd['body'], 0, 200));

        $lockQty = jpost('/api/order_action.php', [
            'action' => 'update_qty', 'nrMasa' => $lockMasa, 'nrOp' => 8, 'ecrId' => 1, 'cantitate' => 2
        ]);
        test('update_qty pe nota blocata -> 423',
            $lockQty['code'] === 423 && (($lockQty['json']['code'] ?? '') === 'bank_pos_uncertain'),
            'code=' . $lockQty['code']);

        $destMasa = ($lockMasa === 80) ? 79 : 80;
        $lockTr = jpost('/api/order_action.php', [
            'action' => 'transfer_products', 'nrMasa' => $lockMasa, 'destNrMasa' => $destMasa,
            'nrOp' => 8, 'ecrIds' => [1]
        ]);
        test('transfer_products din nota blocata -> 423', $lockTr['code'] === 423, 'code=' . $lockTr['code']);

        $lockDiscount = jpost('/api/order_action.php', [
            'action' => 'apply_discount', 'scope' => 'bill', 'mode' => 'percent',
            'value' => 10, 'nrMasa' => $lockMasa, 'nrOp' => 8
        ]);
        test('apply_discount pe nota blocata -> 423', $lockDiscount['code'] === 423, 'code=' . $lockDiscount['code']);

        // Marcare rămâne permisa (bucataria primeste ce s-a adaugat inainte).
        $lockMark = jpost('/api/order_action.php', ['action' => 'print_kitchen', 'docId' => $lockDoc]);
        test('print_kitchen (Marcare) rămâne permis pe nota blocata', $lockMark['code'] !== 423,
            'code=' . $lockMark['code'] . ' ' . substr($lockMark['body'], 0, 200));

        // Clarificarea platii (manual neaprobat) deblocheaza nota.
        $unlock = jpost('/api/pos_banca.php', [
            'action' => 'resolve_manual', 'docId' => $lockDoc, 'verdict' => 'neaprobat',
            'nrOp' => '8', 'sursa' => 'test'
        ]);
        test('manual neaprobat pe nota de test -> success', ($unlock['json']['status'] ?? '') === 'success',
            substr($unlock['body'], 0, 200));

        $go2 = jget('/api/get_order.php?masa=' . $lockMasa);
        $goj2 = $go2['json'] ?: [];
        test('dupa clarificare get_order nu mai raporteaza blocaj', ($goj2['bankPosLocked'] ?? null) === false,
            substr($go2['body'], 0, 200));

        $prodId = (int)(cell("SELECT TOP 1 ProdID FROM tblProd WHERE ProdID > 9 AND COALESCE(PV,0) > 0") ?: 0);
        $unlockedAdd = jpost('/api/order_action.php', [
            'action' => 'add_product', 'nrMasa' => $lockMasa, 'nrOp' => 8,
            'prodId' => $prodId > 0 ? $prodId : 1, 'cantitate' => 1
        ]);
        test('dupa clarificare nota poate fi modificata din nou', $unlockedAdd['code'] !== 423,
            'code=' . $unlockedAdd['code'] . ' ' . substr($unlockedAdd['body'], 0, 200));

        // Nota incerta ramasa fara produse (situatia din vechea versiune, care
        // permitea stergerea liniilor): se poate inchide pe zero, adica
        // close_bill sterge nota. Terminalul bancar nu este contactat.
        q("DELETE FROM tblNoteD WHERE DocID=?", [$lockDoc]);
        q("INSERT INTO tblPosBancaLog (DocID, NrDoc, Suma, TrxId, Status, Mesaj, EmiteBon, Actiune, CreatedAt)
           VALUES (?, 0, 21.00, 'TXTESTLOCK02', 'UNKNOWN', 'Stare necunoscuta: terminalul nu a confirmat rezultatul.', 0, NULL, GETDATE())",
           [$lockDoc]);
        $goEmpty = jget('/api/get_order.php?masa=' . $lockMasa);
        $gojEmpty = $goEmpty['json'] ?: [];
        test('nota goala cu plata incerta ramane raportata ca blocata',
            ($gojEmpty['bankPosLocked'] ?? null) === true && (int)cell("SELECT COUNT(*) FROM tblNoteD WHERE DocID=?", [$lockDoc]) === 0,
            substr($goEmpty['body'], 0, 250));

        $closeEmpty = jpost('/api/order_action.php', ['action' => 'close_bill', 'docId' => $lockDoc, 'plati' => []]);
        test('close_bill pe nota incerta fara produse -> nota este stearsa',
            $closeEmpty['code'] === 200 && ($closeEmpty['json']['sters'] ?? null) === true,
            'code=' . $closeEmpty['code'] . ' ' . substr($closeEmpty['body'], 0, 200));
        test('nota goala chiar a fost stearsa din tblBonCurent',
            (int)cell("SELECT COUNT(*) FROM tblBonCurent WHERE DocID=?", [$lockDoc]) === 0);
        // Din UNKNOWN/RESYNC nu se putea afla rezultatul final: inchiderea se
        // consemneaza ca eveniment separat in audit.
        $closeRow = q1("SELECT TOP 1 * FROM tblPosBancaLog WHERE DocID=? AND Status='CLOSED' ORDER BY Id DESC", [$lockDoc]);
        test('inchiderea notei se consemneaza in audit (Status=CLOSED)',
            $closeRow && (string)$closeRow['Actiune'] === 'inchis'
                && stripos((string)$closeRow['Mesaj'], 'goala') !== false
                && (int)$closeRow['EmiteBon'] === 0,
            json_encode($closeRow));

        // Nota goala obisnuita (fara istoric bancar), cum rămâne dupa ce se
        // sterge ultimul produs: click pe NOTA o inchide si masa se elibereaza.
        $plainDoc = (int)cell("INSERT INTO tblBonCurent (NrDoc, Data, Ora, NrMasa, NrOp, TotalB, Stare, MagID)
                               OUTPUT INSERTED.DocID
                               VALUES (0, GETDATE(), GETDATE(), ?, 8, 0, 'D', ?)", [$lockMasa, $magId]);
        $closePlain = jpost('/api/order_action.php', ['action' => 'close_bill', 'docId' => $plainDoc, 'plati' => []]);
        test('close_bill pe nota goala (fara istoric bancar) -> stearsa',
            $closePlain['code'] === 200 && ($closePlain['json']['sters'] ?? null) === true,
            'code=' . $closePlain['code'] . ' ' . substr($closePlain['body'], 0, 200));
        test('nota goala obisnuita chiar a fost stearsa',
            (int)cell("SELECT COUNT(*) FROM tblBonCurent WHERE DocID=?", [$plainDoc]) === 0);
        $plainDoc = 0;

        /* ------------------------------------------------------------------
         * Regresie (bug real): Ctrl+F5 in timpul asteptarii raspunsului
         * terminalului. Randul de audit se scria doar DUPA ce termina driverul,
         * deci la reload baza nu avea nicio urma: nota aparea curata, fara
         * banner. Acum incercarea se scrie ca `PENDING` inainte de lansare.
         * ---------------------------------------------------------------- */
        $pendLine = q1("SELECT TOP 1 p.ProdID, COALESCE(p.PV,0) AS PV, p.NrGrp,
                               COALESCE(tv.Cota,0) AS TVAc, COALESCE(p.MagID, ?) AS MagID
                        FROM tblProd p LEFT JOIN tblTVA tv ON p.Nr_TVA = tv.Nr_TVA
                        WHERE p.ProdID > 9 AND COALESCE(p.PV,0) > 0", [$magId]);
        if ($pendLine) {
            $pendDoc = (int)cell("INSERT INTO tblBonCurent (NrDoc, Data, Ora, NrMasa, NrOp, TotalB, Stare, MagID)
                                  OUTPUT INSERTED.DocID
                                  VALUES (0, GETDATE(), GETDATE(), ?, 8, 0, 'D', ?)", [$lockMasa, $magId]);
            q("INSERT INTO tblNoteD (DocID, ProdID, Cant, PV, PVC, TVAc, OraComanda, Magid, H, Descriere, NrGrp, Preluat, Preluat1)
               VALUES (?, ?, 1, ?, ?, ?, GETDATE(), ?, 0, 'TEST PENDING', ?, 1, 0)",
               [$pendDoc, (int)$pendLine['ProdID'], (float)$pendLine['PV'], (float)$pendLine['PV'],
                (float)$pendLine['TVAc'], (int)($pendLine['Magid'] ?? $magId), (int)$pendLine['NrGrp']]);
            q("UPDATE tblBonCurent SET TotalB = ? WHERE DocID = ?", [(float)$pendLine['PV'], $pendDoc]);
            q("INSERT INTO tblPosBancaLog (DocID, NrDoc, Suma, TrxId, Status, Mesaj, EmiteBon, Actiune, CreatedAt)
               VALUES (?, 0, 50.00, 'TXTESTPEND01', 'PENDING', 'Comanda trimisa catre terminal, se asteapta raspunsul.', 0, NULL, GETDATE())",
               [$pendDoc]);

            $goPend = jget('/api/get_order.php?masa=' . $lockMasa);
            $goPendJ = $goPend['json'] ?: [];
            test('comanda in curs (PENDING) blocheaza nota dupa reload',
                ($goPendJ['bankPosLocked'] ?? null) === true
                    && ($goPendJ['bankPosUncertain'] ?? null) === true
                    && ($goPendJ['bankPosPending'] ?? null) === true,
                'locked=' . json_encode($goPendJ['bankPosLocked'] ?? null)
                    . ' uncertain=' . json_encode($goPendJ['bankPosUncertain'] ?? null)
                    . ' pending=' . json_encode($goPendJ['bankPosPending'] ?? null));

            $stPend = jpost('/api/pos_banca.php', ['action' => 'status', 'docId' => $pendDoc]);
            test('status raporteaza comanda in curs (pending)',
                ($stPend['json']['pending'] ?? null) === true
                    && ($stPend['json']['uncertain'] ?? null) === true,
                substr($stPend['body'], 0, 300));

            q("DELETE FROM tblNoteD WHERE DocID=?", [$pendDoc]);
            q("DELETE FROM tblPosBancaLog WHERE DocID=?", [$pendDoc]);
            q("DELETE FROM tblBonCurent WHERE DocID=?", [$pendDoc]);
            $pendDoc = 0;
        }

        /* ------------------------------------------------------------------
         * Regresie (bug real): plata partiala cu CARD aprobata pe terminal,
         * FARA nicio incercare incerta (swipe aprobat, apoi Ctrl+F5). Nota
         * rămânea modificabila - se puteau sterge produse - iar close_bill
         * refuza apoi cu card_not_approved. Banii aprobati sunt in audit, deci
         * nota trebuie sa fie nemodificabila, cu codul dedicat
         * `bank_pos_payable` (iesirea corecta: NOTA).
         * ---------------------------------------------------------------- */
        $payLine = q1("SELECT TOP 1 p.ProdID, COALESCE(p.PV,0) AS PV, p.NrGrp,
                              COALESCE(tv.Cota,0) AS TVAc, COALESCE(p.MagID, ?) AS MagID
                       FROM tblProd p LEFT JOIN tblTVA tv ON p.Nr_TVA = tv.Nr_TVA
                       WHERE p.ProdID > 9 AND COALESCE(p.PV,0) > 0", [$magId]);
        if ($payLine) {
            $payDoc = (int)cell("INSERT INTO tblBonCurent (NrDoc, Data, Ora, NrMasa, NrOp, TotalB, Stare, MagID)
                                 OUTPUT INSERTED.DocID
                                 VALUES (0, GETDATE(), GETDATE(), ?, 8, 0, 'D', ?)", [$lockMasa, $magId]);
            $payEcr = (int)cell("INSERT INTO tblNoteD (DocID, ProdID, Cant, PV, PVC, TVAc, OraComanda, Magid, H, Descriere, NrGrp, Preluat, Preluat1)
                                 OUTPUT INSERTED.ECRID
                                 VALUES (?, ?, 1, ?, ?, ?, GETDATE(), ?, 0, 'TEST PLATA CARD', ?, 1, 0)",
                                 [$payDoc, (int)$payLine['ProdID'], (float)$payLine['PV'], (float)$payLine['PV'],
                                  (float)$payLine['TVAc'], (int)$payLine['MagID'], (int)$payLine['NrGrp']]);
            q("UPDATE tblBonCurent SET TotalB = ? WHERE DocID = ?", [(float)$payLine['PV'], $payDoc]);
            // Doar aprobarea terminalului: nu exista UNKNOWN/TIMEOUT pentru nota.
            q("INSERT INTO tblPosBancaLog (DocID, NrDoc, Suma, TrxId, Status, Mesaj, EmiteBon, Actiune, Stan, CreatedAt)
               VALUES (?, 0, 80.00, 'TXTESTPAY001', 'OK', 'Tranzactie aprobata.', 1, 'aprobat', '000123', GETDATE())",
               [$payDoc]);

            $goPay = jget('/api/get_order.php?masa=' . $lockMasa);
            $goPayJ = $goPay['json'] ?: [];
            test('plata aprobata pe card (fara incercare incerta) blocheaza nota',
                ($goPayJ['bankPosLocked'] ?? null) === true
                    && ($goPayJ['bankPosApproved'] ?? null) === true
                    && abs((float)($goPayJ['bankPosApprovedTotal'] ?? 0) - 80.00) < 0.005,
                substr($goPay['body'], 0, 300));
            // Incasarea este CERTA: Verifica / Manual nu trebuie sa apara.
            test('plata confirmata nu mai raporteaza incertitudine (bankPosUncertain=false)',
                ($goPayJ['bankPosUncertain'] ?? null) === false
                    && ($goPayJ['bankPosResolved'] ?? null) === false,
                'uncertain=' . json_encode($goPayJ['bankPosUncertain'] ?? null)
                    . ' resolved=' . json_encode($goPayJ['bankPosResolved'] ?? null));

            $payDel = jpost('/api/order_action.php', [
                'action' => 'void_line', 'nrMasa' => $lockMasa, 'nrOp' => 8,
                'ecrId' => $payEcr, 'cantitate' => 1
            ]);
            test('stergerea unui produs pe nota cu card aprobat -> 423 bank_pos_payable',
                $payDel['code'] === 423 && (($payDel['json']['code'] ?? '') === 'bank_pos_payable'),
                'code=' . $payDel['code'] . ' ' . substr($payDel['body'], 0, 250));
            test('produsul de pe nota cu card aprobat nu a fost sters',
                (int)cell("SELECT COUNT(*) FROM tblNoteD WHERE ECRID = ?", [$payEcr]) === 1);

            $payAdd = jpost('/api/order_action.php', [
                'action' => 'add_product', 'nrMasa' => $lockMasa, 'nrOp' => 8,
                'prodId' => (int)$payLine['ProdID'], 'cantitate' => 1
            ]);
            test('adaugarea de produse pe nota cu card aprobat -> 423 bank_pos_payable',
                $payAdd['code'] === 423 && (($payAdd['json']['code'] ?? '') === 'bank_pos_payable'),
                'code=' . $payAdd['code'] . ' ' . substr($payAdd['body'], 0, 250));

            // Iesirea corecta rămâne permisa: Marcarea si inchiderea notei
            // (close_bill primeste plata de card din ledger-ul clientului).
            $payMark = jpost('/api/order_action.php', ['action' => 'print_kitchen', 'docId' => $payDoc]);
            test('Marcare rămâne permisa pe nota cu card aprobat', $payMark['code'] !== 423,
                'code=' . $payMark['code']);

            q("DELETE FROM tblNoteD WHERE DocID=?", [$payDoc]);
            q("DELETE FROM tblPosBancaLog WHERE DocID=?", [$payDoc]);
            q("DELETE FROM tblBonCurent WHERE DocID=?", [$payDoc]);
            $payDoc = 0;
        }

        // Nota cu produse care se anuleaza reciproc (total 0): se inchide
        // normal, dar FARA bon fiscal (fara job `fiscal`, fara asteptare casa).
        $zeroDoc = (int)cell("INSERT INTO tblBonCurent (NrDoc, Data, Ora, NrMasa, NrOp, TotalB, Stare, MagID)
                              OUTPUT INSERTED.DocID
                              VALUES (0, GETDATE(), GETDATE(), ?, 8, 0, 'D', ?)", [$lockMasa, $magId]);
        $pz = q1("SELECT TOP 1 p.ProdID, COALESCE(p.PV,0) AS PV, p.NrGrp,
                         COALESCE(tv.Cota,0) AS TVAc, COALESCE(p.MagID, ?) AS MagID
                  FROM tblProd p LEFT JOIN tblTVA tv ON p.Nr_TVA = tv.Nr_TVA
                  WHERE p.ProdID > 9 AND COALESCE(p.PV,0) > 0", [$magId]);
        if ($pz) {
            $pvz = (float)$pz['PV'];
            $tvz = (float)$pz['TVAc'];
            $grz = (int)$pz['NrGrp'];
            $mgz = (int)$pz['MagID'];
            $prodZ = (int)$pz['ProdID'];
            $ecrZ = (int)cell("INSERT INTO tblNoteD (DocID, ProdID, Cant, PV, PVC, TVAc, OraComanda, Magid, H, Descriere, NrGrp, Preluat, Preluat1)
                               OUTPUT INSERTED.ECRID
                               VALUES (?, ?, 1, ?, ?, ?, GETDATE(), ?, 0, 'TEST ZERO', ?, 0, 0)",
                               [$zeroDoc, $prodZ, $pvz, $pvz, $tvz, $mgz, $grz]);
            q("INSERT INTO tblNoteD (DocID, ProdID, Cant, PV, PVC, TVAc, OraComanda, Magid, H, Descriere, NrGrp, Preluat, Preluat1, StornoRef)
               VALUES (?, ?, -1, ?, ?, ?, GETDATE(), ?, 0, 'TEST ZERO', ?, 0, 0, ?)",
               [$zeroDoc, $prodZ, $pvz, $pvz, $tvz, $mgz, $grz, $ecrZ]);
            q("UPDATE tblBonCurent SET TotalB = 0 WHERE DocID = ?", [$zeroDoc]);

            $closeZero = jpost('/api/order_action.php', ['action' => 'close_bill', 'docId' => $zeroDoc, 'plati' => []]);
            test('close_bill pe nota cu valoare 0 -> inchisa, fara bon fiscal',
                $closeZero['code'] === 200
                    && ($closeZero['json']['zeroTotal'] ?? null) === true
                    && array_key_exists('fiscal', $closeZero['json'] ?: [])
                    && $closeZero['json']['fiscal'] === null,
                'code=' . $closeZero['code'] . ' ' . substr($closeZero['body'], 0, 250));
            test('nota cu valoare 0 NU este stearsa (ramane inchisa)',
                (int)cell("SELECT COUNT(*) FROM tblBonCurent WHERE DocID=? AND Stare='I'", [$zeroDoc]) === 1);
            test('nu s-a scris job fiscal pentru nota cu valoare 0',
                (int)cell("SELECT COUNT(*) FROM tblPrintQueue WHERE RefDocID=? AND Tip='fiscal'", [$zeroDoc]) === 0);
        } else {
            info('nu exista produs pentru testul de nota cu valoare 0 - sarit');
        }
    } else {
        info('nu exista o masa libera pentru testul de lock bancar - sarit');
    }

    /* ------------------------------------------------------------------
     * "Nu a tipărit (Anulează)": incercarea incerta este abandonata de
     * operator. Randul TRX_ID primeste Status=CANCELED_MANUAL + ResolvedAt,
     * iar nota se deblocheaza pentru o noua incercare (sau numerar).
     * ------------------------------------------------------------------ */
    $cancelDoc = 999991;
    $approvedDoc = 999992;
    q("DELETE FROM tblPosBancaLog WHERE DocID IN (?, ?)", [$cancelDoc, $approvedDoc]);
    q("INSERT INTO tblPosBancaLog (DocID, NrDoc, Suma, TrxId, Status, Mesaj, EmiteBon, Actiune, CreatedAt)
       VALUES (?, 0, 21.00, 'TXTESTCANCEL01', 'UNKNOWN', 'Stare necunoscuta: terminalul nu a confirmat rezultatul.', 0, NULL, GETDATE())",
       [$cancelDoc]);

    $stC0 = jpost('/api/pos_banca.php', ['action' => 'status', 'docId' => $cancelDoc]);
    test('cancel: starea initiala este incerta',
        (($stC0['json'] ?: [])['uncertain'] ?? null) === true, substr($stC0['body'], 0, 200));

    // Un TRX_ID strain (alta incercare) nu are voie sa anuleze randul curent.
    $stale = jpost('/api/pos_banca.php', [
        'action' => 'resolve', 'docId' => $cancelDoc, 'actiune' => 'neaprobat', 'trxId' => 'TXALTCINEVA'
    ]);
    test('cancel cu TRX_ID strain -> 409 stale_attempt',
        $stale['code'] === 409 && (($stale['json']['code'] ?? '') === 'stale_attempt'),
        'code=' . $stale['code'] . ' ' . substr($stale['body'], 0, 160));
    $rowStill = q1("SELECT TOP 1 Status FROM tblPosBancaLog WHERE DocID=? ORDER BY Id DESC", [$cancelDoc]);
    test('cancel cu TRX_ID strain nu schimba randul', $rowStill && $rowStill['Status'] === 'UNKNOWN',
        json_encode($rowStill));

    $can = jpost('/api/pos_banca.php', [
        'action' => 'resolve', 'docId' => $cancelDoc, 'actiune' => 'neaprobat', 'trxId' => 'TXTESTCANCEL01'
    ]);
    test('cancel ("Nu a tiparit") -> success', (($can['json'] ?: [])['status'] ?? '') === 'success',
        substr($can['body'], 0, 200));
    $rowC = q1("SELECT TOP 1 * FROM tblPosBancaLog WHERE DocID=? ORDER BY Id DESC", [$cancelDoc]);
    test('cancel scrie Status=CANCELED_MANUAL / Actiune=neaprobat',
        $rowC && $rowC['Status'] === 'CANCELED_MANUAL' && $rowC['Actiune'] === 'neaprobat',
        json_encode(['Status' => $rowC['Status'] ?? null, 'Actiune' => $rowC['Actiune'] ?? null]));
    test('cancel completeaza ResolvedAt',
        $rowC && $rowC['ResolvedAt'] !== null && (string)$rowC['ResolvedAt'] !== '',
        json_encode($rowC['ResolvedAt'] ?? null));
    test('cancel pastreaza TRX_ID si mesajul pentru reconciliere',
        $rowC && (string)$rowC['TrxId'] === 'TXTESTCANCEL01'
            && stripos((string)$rowC['Mesaj'], 'nu a tiparit chitanta') !== false,
        (string)($rowC['Mesaj'] ?? ''));

    $stC1 = jpost('/api/pos_banca.php', ['action' => 'status', 'docId' => $cancelDoc]);
    $sjC1 = $stC1['json'] ?: [];
    test('dupa cancel nota este deblocata (uncertain=false)', ($sjC1['uncertain'] ?? null) === false,
        substr($stC1['body'], 0, 200));
    test('dupa cancel TRX_ID rămâne disponibil pentru suport',
        (string)($sjC1['uid'] ?? '') === 'TXTESTCANCEL01', (string)($sjC1['uid'] ?? ''));

    // Reincercarea pe card porneste curat: un rand nou, cu TRX_ID nou.
    q("INSERT INTO tblPosBancaLog (DocID, NrDoc, Suma, TrxId, Status, Mesaj, EmiteBon, Actiune, CreatedAt)
       VALUES (?, 0, 21.00, 'TXTESTCANCEL02', 'APPROVED', 'Tranzactie aprobata.', 1, NULL, GETDATE())",
       [$cancelDoc]);
    $stC2 = jpost('/api/pos_banca.php', ['action' => 'status', 'docId' => $cancelDoc]);
    $sjC2 = $stC2['json'] ?: [];
    test('dupa cancel se poate plati din nou (incercare noua, neincerta)',
        ($sjC2['uncertain'] ?? null) === false && (string)($sjC2['uid'] ?? '') === 'TXTESTCANCEL02',
        substr($stC2['body'], 0, 200));

    // O plata confirmata de terminal (jurnal) nu poate fi abandonata din dialog.
    q("INSERT INTO tblPosBancaLog (DocID, NrDoc, Suma, TrxId, Status, Mesaj, EmiteBon, Actiune, CreatedAt)
       VALUES (?, 0, 6.50, 'TXTESTCANCEL03', 'UNKNOWN', 'Stare necunoscuta: terminalul nu a confirmat rezultatul.', 0, NULL, GETDATE())",
       [$approvedDoc]);
    q("INSERT INTO tblPosBancaLog (DocID, NrDoc, Suma, TrxId, Status, Mesaj, EmiteBon, Actiune, CreatedAt)
       VALUES (?, 0, 6.50, 'TXTESTCANCEL03', 'RESYNC', 'FOUND=1 APPROVED=1 Tranzactia a fost gasita in jurnalul terminalului.', 0, 'verificat', GETDATE())",
       [$approvedDoc]);
    $badCancel = jpost('/api/pos_banca.php', [
        'action' => 'resolve', 'docId' => $approvedDoc, 'actiune' => 'neaprobat', 'trxId' => 'TXTESTCANCEL03'
    ]);
    test('cancel dupa resync aprobat -> 409 bank_pos_approved',
        $badCancel['code'] === 409 && (($badCancel['json']['code'] ?? '') === 'bank_pos_approved'),
        'code=' . $badCancel['code'] . ' ' . substr($badCancel['body'], 0, 160));
    $rowAp = q1("SELECT TOP 1 Status FROM tblPosBancaLog WHERE DocID=? ORDER BY Id DESC", [$approvedDoc]);
    test('cancel refuzat nu modifica incercarea aprobata', $rowAp && $rowAp['Status'] === 'RESYNC',
        json_encode($rowAp));
    q("DELETE FROM tblPosBancaLog WHERE DocID IN (?, ?)", [$cancelDoc, $approvedDoc]);

    /* ------------------------------------------------------------------
     * Reconcilierea cardului la close_bill (bani incasati vs. linia CARD)
     * ------------------------------------------------------------------ */
    $recDoc = 999993;
    q("DELETE FROM tblPosBancaLog WHERE DocID=?", [$recDoc]);
    // Grupare pe TrxId: UNKNOWN + RESYNC aceeasi incercare = o singura suma;
    // o anulare reusita (VOID) scoate tranzactia din total.
    $insRec = "INSERT INTO tblPosBancaLog (DocID, NrDoc, Suma, TrxId, Status, Mesaj, EmiteBon, Actiune, CreatedAt)
               VALUES (?, 0, ?, ?, ?, ?, ?, ?, GETDATE())";
    q($insRec, [$recDoc, 60.00, 'TXREC-A', 'UNKNOWN', 'Stare necunoscuta.', 0, null]);
    q($insRec, [$recDoc, 60.00, 'TXREC-A', 'RESYNC', 'FOUND=1 APPROVED=1', 0, 'verificat']);
    q($insRec, [$recDoc, 40.00, 'TXREC-B', 'OK', 'Tranzactie aprobata.', 1, 'aprobat']);
    q($insRec, [$recDoc, 20.00, 'TXREC-C', 'MANUAL', 'Verificare manuala: APROBAT', 0, 'aprobat']);
    q($insRec, [$recDoc, 15.00, 'TXREC-D', 'OK', 'Tranzactie aprobata.', 1, 'aprobat']);
    q($insRec, [$recDoc, 15.00, 'TXREC-D', 'VOID', 'Tranzactie anulata.', 0, 'anulat']);
    // Eroarea de configurare emite bonul, dar nu este o incasare pe card.
    q($insRec, [$recDoc, 70.00, 'TXREC-E', 'ERROR', 'Eroare configurare POS bancar', 1, 'config']);
    $rec = posBancaApprovedTotal(db(), $recDoc);
    test('posBancaApprovedTotal: UNKNOWN+RESYNC o singura data, VOID/config excluse',
        abs((float)$rec['total'] - 120.00) < 0.005,
        'total=' . $rec['total'] . ' entries=' . json_encode($rec['entries']));
    test('posBancaApprovedTotal: cate o intrare per tranzactie',
        count($rec['entries']) === 3, 'entries=' . count($rec['entries']));
    $srcs = array_map(fn($e) => (string)($e['source'] ?? ''), $rec['entries']);
    sort($srcs);
    test('posBancaApprovedTotal: sursa corecta (terminal / journal / manual)',
        $srcs === ['journal', 'manual', 'terminal'], json_encode($srcs));
    $stRec = jpost('/api/pos_banca.php', ['action' => 'status', 'docId' => $recDoc]);
    test('status expune approvedTotal pentru reînsămânțare',
        abs((float)($stRec['json']['approvedTotal'] ?? -1) - 120.00) < 0.005
            && count($stRec['json']['approvedEntries'] ?? []) === 3,
        substr($stRec['body'], 0, 220));

    // Terminalul a incasat mai mult decat linia CARD din nota => 409.
    $reconDoc = ($lockMasa > 0)
        ? (int)cell("INSERT INTO tblBonCurent (NrDoc, Data, Ora, NrMasa, NrOp, TotalB, Stare, MagID)
                           OUTPUT INSERTED.DocID
                           VALUES (0, GETDATE(), GETDATE(), ?, 8, 100, 'D', ?)", [$lockMasa, $magId])
        : 0;
    $pr = q1("SELECT TOP 1 p.ProdID, COALESCE(p.PV,0) AS PV, p.NrGrp,
                     COALESCE(tv.Cota,0) AS TVAc, COALESCE(p.MagID, ?) AS MagID
              FROM tblProd p LEFT JOIN tblTVA tv ON p.Nr_TVA = tv.Nr_TVA
              WHERE p.ProdID > 9 AND COALESCE(p.PV,0) > 0", [$magId]);
    if ($pr && $reconDoc > 0) {
        q("INSERT INTO tblNoteD (DocID, ProdID, Cant, PV, PVC, TVAc, OraComanda, Magid, H, Descriere, NrGrp, Preluat, Preluat1)
           VALUES (?, ?, 1, ?, ?, ?, GETDATE(), ?, 0, 'TEST RECON', ?, 0, 0)",
           [$reconDoc, (int)$pr['ProdID'], (float)$pr['PV'], (float)$pr['PV'],
            (float)$pr['TVAc'], (int)$pr['MagID'], (int)$pr['NrGrp']]);
        q($insRec, [$reconDoc, 60.00, 'TXRECON', 'OK', 'Tranzactie aprobata.', 1, 'aprobat']);

        $closeUnder = jpost('/api/order_action.php', [
            'action' => 'close_bill', 'docId' => $reconDoc, 'plati' => [1 => 40]
        ]);
        test('close_bill refuza cardul sub suma aprobata (409 card_not_approved)',
            $closeUnder['code'] === 409 && (($closeUnder['json']['code'] ?? '') === 'card_not_approved'),
            'code=' . $closeUnder['code'] . ' ' . substr($closeUnder['body'], 0, 220));
        test('dupa refuz nota rămâne deschisa',
            (int)cell("SELECT COUNT(*) FROM tblBonCurent WHERE DocID=? AND Stare='D'", [$reconDoc]) === 1);

        // Card in nota peste suma aprobata (plata in afara terminalului): nu
        // blocheaza, dar se consemneaza MISMATCH pentru reconciliere.
        $closeOver = jpost('/api/order_action.php', [
            'action' => 'close_bill', 'docId' => $reconDoc, 'plati' => [1 => 100]
        ]);
        $misRow = q1("SELECT TOP 1 * FROM tblPosBancaLog WHERE DocID=? AND Status='MISMATCH' ORDER BY Id DESC", [$reconDoc]);
        test('card in afara terminalului -> rand MISMATCH in audit',
            $misRow && abs((float)$misRow['Suma'] - 100.00) < 0.005,
            'code=' . $closeOver['code'] . ' row=' . json_encode($misRow));
    } else {
        info('nu exista produs / masa pentru testul de reconciliere - sarit');
    }
    q("DELETE FROM tblPosBancaLog WHERE DocID=?", [$recDoc]);

    /* ------------------------------------------------------------------
     * Ieșirea din secventa de plata: void_doc (totul sau nimic)
     * ------------------------------------------------------------------ */
    $voidDoc = 999995;
    q("DELETE FROM tblPosBancaLog WHERE DocID=?", [$voidDoc]);
    // Testul de normalizare de mai sus a lasat valoarea legacy 'Generic' in
    // tblSet; protocolul este insa tratat ca SmartPayECR, deci void_doc are
    // capabilitatile necesare. Restaurarea valorii originale se face in finally.
    // Fara sume aprobate, actiunea raspunde imediat (nu lanseaza driverul).
    $vd0 = jpost('/api/pos_banca.php', ['action' => 'void_doc', 'docId' => $voidDoc]);
    test('void_doc fara sume aprobate -> nimic de anulat',
        $vd0['code'] === 200 && ($vd0['json']['status'] ?? '') === 'success'
            && count($vd0['json']['voided'] ?? []) === 0
            && abs((float)($vd0['json']['approvedTotal'] ?? -1)) < 0.005,
        substr($vd0['body'], 0, 240));

    // STAN-ul ajunge in intrările aprobate: este parametrul cerut de void.
    q("INSERT INTO tblPosBancaLog (DocID, NrDoc, Suma, TrxId, Status, Mesaj, EmiteBon, Actiune, Stan, CreatedAt)
       VALUES (?, 0, ?, ?, 'OK', 'Tranzactie aprobata.', 1, 'aprobat', ?, GETDATE())",
       [$voidDoc, 60.00, 'TXV1', '000031']);
    q("INSERT INTO tblPosBancaLog (DocID, NrDoc, Suma, TrxId, Status, Mesaj, EmiteBon, Actiune, Stan, CreatedAt)
       VALUES (?, 0, ?, ?, 'OK', 'Tranzactie aprobata.', 1, 'aprobat', ?, GETDATE())",
       [$voidDoc, 40.00, 'TXV2', '000032']);
    $vdInfo = posBancaApprovedTotal(db(), $voidDoc);
    $stans = array_map(fn($e) => (string)($e['stan'] ?? ''), $vdInfo['entries']);
    test('intrările aprobate poarta STAN (necesar pentru void)',
        abs((float)$vdInfo['total'] - 100.00) < 0.005 && count($stans) === 2
            && in_array('000031', $stans, true) && in_array('000032', $stans, true),
        'total=' . $vdInfo['total'] . ' stan=' . json_encode($stans));
    q("DELETE FROM tblPosBancaLog WHERE DocID=?", [$voidDoc]);

    /* ------------------------------------------------------------------
     * Retiparirea chitantei de card (duplicat pe imprimanta POS)
     * ------------------------------------------------------------------ */
    $repDoc = 999991;
    $repEmptyDoc = 999989;
    q("DELETE FROM tblPosBancaLog WHERE DocID IN (?, ?)", [$repDoc, $repEmptyDoc]);
    q("DELETE FROM tblPrintQueue WHERE RefDocID IN (?, ?)", [$repDoc, $repEmptyDoc]);
    $insRep = "INSERT INTO tblPosBancaLog
                  (DocID, NrDoc, Suma, TrxId, Status, Mesaj, EmiteBon, Actiune,
                   Rrn, Stan, AuthCode, CardPan, Raw, CreatedAt)
               VALUES (?, 77, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())";
    // Doua aprobari: 50 Lei (terminal) si 25 Lei (manual, AUTHCODE). Ultima
    // trebuie aleasa de reprint (dupa Id), nu cea cu suma mai mare.
    q($insRep, [$repDoc, 50.00, 'TXREP-A', 'OK', 'Tranzactie aprobata.', 1, 'aprobat',
        '111122223333', '000031', 'A1B2C3', '**3337',
        "STATUS=OK\nMESAJ=Tranzactie aprobata.\nSUMA=50.00\nTRX_ID=TXREP-A\nRRN=111122223333\nSTAN=000031\nAUTH=A1B2C3\nPAN=**3337\nTERMINAL=POS00001\nMERCHANT=123456789012345"]);
    q($insRep, [$repDoc, 25.00, 'TXREP-B', 'MANUAL', 'Verificare manuala: APROBAT', 1, 'aprobat',
        null, null, 'D4E5F6', '**4444', null]);
    // Functia este opționala (Setari -> POS bancar, implicit NU): cu setarea
    // pe NU, serverul refuza si nu scrie niciun job.
    if ($hadRetiparire) {
        q("UPDATE tblSet SET Value='0' WHERE Setting='RetiparireChitantaPoSbanca'");
    } else {
        q("INSERT INTO tblSet (Setting, Value, Descriere, Grup)
           VALUES ('RetiparireChitantaPoSbanca', '0', 'Test', 'PoSbanca')");
    }
    $repOff = jpost('/api/pos_banca.php', ['action' => 'reprint', 'docId' => $repDoc]);
    test('reprint dezactivat din Setari -> 403 reprint_disabled',
        $repOff['code'] === 403 && (($repOff['json']['code'] ?? '') === 'reprint_disabled'),
        'code=' . $repOff['code'] . ' ' . substr($repOff['body'], 0, 160));
    test('reprint dezactivat nu scrie job in coada',
        (int)cell("SELECT COUNT(*) FROM tblPrintQueue WHERE RefDocID=?", [$repDoc]) === 0);
    q("UPDATE tblSet SET Value='1' WHERE Setting='RetiparireChitantaPoSbanca'");

    $rep = jpost('/api/pos_banca.php', ['action' => 'reprint', 'docId' => $repDoc]);
    $rj = $rep['json'] ?: [];
    test('reprint -> success', ($rj['status'] ?? '') === 'success', substr($rep['body'], 0, 220));
    test('reprint alege ultima aprobare (25 Lei), nu suma mai mare',
        (string)($rj['amount'] ?? '') === '25.00' && (string)($rj['trxId'] ?? '') === 'TXREP-B',
        'amount=' . (string)($rj['amount'] ?? '') . ' trx=' . (string)($rj['trxId'] ?? ''));
    $repJob = q1("SELECT TOP 1 * FROM tblPrintQueue WHERE RefDocID=? AND Tip='banca' ORDER BY JobID DESC", [$repDoc]);
    test('reprint scrie un job banca in coada de tiparire', $repJob !== null, json_encode($repJob));
    $repPayload = $repJob ? (json_decode((string)$repJob['Payload'], true) ?: []) : [];
    test('duplicatul are suma si AUTHCODE din audit',
        abs((float)($repPayload['suma'] ?? 0) - 25.00) < 0.005
            && (string)($repPayload['auth'] ?? '') === 'D4E5F6',
        json_encode($repPayload));
    $repRow = q1("SELECT TOP 1 * FROM tblPosBancaLog WHERE DocID=? AND Status='REPRINT' ORDER BY Id DESC", [$repDoc]);
    test('reprint scrie rand REPRINT/retiparit in audit',
        $repRow && (string)$repRow['Actiune'] === 'retiparit' && (int)$repRow['EmiteBon'] === 0,
        json_encode($repRow));
    $repInfo = posBancaApprovedTotal(db(), $repDoc);
    test('REPRINT nu schimba approvedTotal', abs((float)$repInfo['total'] - 75.00) < 0.005,
        'total=' . $repInfo['total']);
    $repState = posBancaDocState(db(), $repDoc);
    test('REPRINT nu devine "ultima incercare"',
        strtoupper((string)($repState['bankStatus'] ?? '')) !== 'REPRINT'
            && in_array((string)($repState['uid'] ?? ''), ['TXREP-A', 'TXREP-B'], true),
        'uid=' . (string)($repState['uid'] ?? '') . ' status=' . (string)($repState['bankStatus'] ?? ''));

    // Un rand CLOSED (bon fiscal) are EmiteBon=1 si Suma = totalul notei; nu
    // are voie sa umfle approvedTotal / duplicatul dupa inchidere.
    q("INSERT INTO tblPosBancaLog (DocID, NrDoc, Suma, TrxId, Status, Mesaj, EmiteBon, Actiune, CreatedAt)
       VALUES (?, 77, 83.00, 'TXREP-B', 'CLOSED', 'Nota inchisa. Bon fiscal NR 1.', 1, 'inchis', GETDATE())", [$repDoc]);
    $repInfo2 = posBancaApprovedTotal(db(), $repDoc);
    test('CLOSED nu umfla approvedTotal (ramane suma de pe card)',
        abs((float)$repInfo2['total'] - 75.00) < 0.005, 'total=' . $repInfo2['total']);
    $rep2 = jpost('/api/pos_banca.php', ['action' => 'reprint', 'docId' => $repDoc]);
    test('reprint dupa inchidere pastreaza suma cardului (25 Lei)',
        (string)(($rep2['json'] ?: [])['amount'] ?? '') === '25.00', substr($rep2['body'], 0, 220));

    // O anulare pe ultima tranzactie: se retipareste cea anterioara.
    q("INSERT INTO tblPosBancaLog (DocID, NrDoc, Suma, TrxId, Status, Mesaj, EmiteBon, Actiune, CreatedAt)
       VALUES (?, 77, 25.00, 'TXREP-B', 'VOID', 'Tranzactie anulata.', 0, 'anulat', GETDATE())", [$repDoc]);
    $rep3 = jpost('/api/pos_banca.php', ['action' => 'reprint', 'docId' => $repDoc]);
    test('dupa void pe ultima, reprint alege tranzactia anterioara (50 Lei)',
        (string)(($rep3['json'] ?: [])['amount'] ?? '') === '50.00'
            && (string)(($rep3['json'] ?: [])['trxId'] ?? '') === 'TXREP-A',
        substr($rep3['body'], 0, 220));
    $rep3Job = q1("SELECT TOP 1 * FROM tblPrintQueue WHERE RefDocID=? AND Tip='banca' ORDER BY JobID DESC", [$repDoc]);
    $rep3Payload = $rep3Job ? (json_decode((string)$rep3Job['Payload'], true) ?: []) : [];
    test('duplicatul preia terminal/comerciant din Raw cand exista',
        (string)($rep3Payload['terminal'] ?? '') === 'POS00001'
            && (string)($rep3Payload['merchant'] ?? '') === '123456789012345',
        json_encode($rep3Payload));

    // Fara nicio plata pe card: eroare clara, nu job in coada.
    $repEmpty = jpost('/api/pos_banca.php', ['action' => 'reprint', 'docId' => $repEmptyDoc]);
    test('reprint fara plata pe card -> 404 no_card_transaction',
        $repEmpty['code'] === 404 && (($repEmpty['json']['code'] ?? '') === 'no_card_transaction'),
        'code=' . $repEmpty['code'] . ' ' . substr($repEmpty['body'], 0, 160));
    test('reprint fara plata nu scrie job in coada',
        (int)cell("SELECT COUNT(*) FROM tblPrintQueue WHERE RefDocID=?", [$repEmptyDoc]) === 0);
    q("DELETE FROM tblPrintQueue WHERE RefDocID=?", [$repDoc]);
    q("DELETE FROM tblPosBancaLog WHERE DocID IN (?, ?)", [$repDoc, $repEmptyDoc]);
} finally {
    if ($voidDoc > 0) {
        q("DELETE FROM tblPosBancaLog WHERE DocID=?", [$voidDoc]);
    }
    if ($repDoc > 0) {
        q("DELETE FROM tblPrintQueue WHERE RefDocID=?", [$repDoc]);
        q("DELETE FROM tblPosBancaLog WHERE DocID=?", [$repDoc]);
    }
    if ($repEmptyDoc > 0) {
        q("DELETE FROM tblPrintQueue WHERE RefDocID=?", [$repEmptyDoc]);
        q("DELETE FROM tblPosBancaLog WHERE DocID=?", [$repEmptyDoc]);
    }
    if ($reconDoc > 0) {
        q("DELETE FROM tblPrintQueue WHERE RefDocID=?", [$reconDoc]);
        q("DELETE FROM tblNoteD WHERE DocID=?", [$reconDoc]);
        q("DELETE FROM tblPosBancaLog WHERE DocID=?", [$reconDoc]);
        q("DELETE FROM tblBonCurent WHERE DocID=?", [$reconDoc]);
    }
    if ($zeroDoc > 0) {
        q("DELETE FROM tblPrintQueue WHERE RefDocID=?", [$zeroDoc]);
        q("DELETE FROM tblNoteD WHERE DocID=?", [$zeroDoc]);
        q("DELETE FROM tblBonCurent WHERE DocID=?", [$zeroDoc]);
    }
    if ($plainDoc > 0) {
        q("DELETE FROM tblNoteD WHERE DocID=?", [$plainDoc]);
        q("DELETE FROM tblBonCurent WHERE DocID=?", [$plainDoc]);
    }
    if ($lockDoc > 0) {
        q("DELETE FROM tblNoteD WHERE DocID=?", [$lockDoc]);
        q("DELETE FROM tblPosBancaLog WHERE DocID=?", [$lockDoc]);
        q("DELETE FROM tblBonCurent WHERE DocID=?", [$lockDoc]);
    }
    if ($payDoc > 0) {
        q("DELETE FROM tblNoteD WHERE DocID=?", [$payDoc]);
        q("DELETE FROM tblPosBancaLog WHERE DocID=?", [$payDoc]);
        q("DELETE FROM tblBonCurent WHERE DocID=?", [$payDoc]);
    }
    if ($pendDoc > 0) {
        q("DELETE FROM tblNoteD WHERE DocID=?", [$pendDoc]);
        q("DELETE FROM tblPosBancaLog WHERE DocID=?", [$pendDoc]);
        q("DELETE FROM tblBonCurent WHERE DocID=?", [$pendDoc]);
    }
    q("DELETE FROM tblPosBancaLog WHERE DocID=?", [$docId]);
    if (isset($vivaSilentDoc) && $vivaSilentDoc > 0) {
        q("DELETE FROM tblPosBancaLog WHERE DocID=?", [$vivaSilentDoc]);
    }
    if (isset($vivaOkDoc) && $vivaOkDoc > 0) {
        q("DELETE FROM tblPosBancaLog WHERE DocID=?", [$vivaOkDoc]);
    }
    foreach ($procs as $proc) { posBancaMockStop($proc); }
    if ($origPoSbanca !== null) {
        q("UPDATE tblSet SET Value=? WHERE Setting='PoSbanca'", [$origPoSbanca]);
    }
    if ($hadRetiparire) {
        q("UPDATE tblSet SET Value=? WHERE Setting='RetiparireChitantaPoSbanca'", [$origRetiparire]);
    } else {
        q("DELETE FROM tblSet WHERE Setting='RetiparireChitantaPoSbanca'");
    }
    if ($origResultFile !== null) {
        q("UPDATE tblSet SET Value=? WHERE Setting='CaleFisierRaspunsPoSbanca'", [$origResultFile]);
    }
    if ($origServer !== null) {
        q("UPDATE tblSet SET Value=? WHERE Setting='Server'", [$origServer]);
    }
    if ($hadDriverRow) {
        q("UPDATE tblSet SET Value=? WHERE Setting='CaleDriverPoSbanca'", [$origDriver]);
    } else {
        q("DELETE FROM tblSet WHERE Setting='CaleDriverPoSbanca'");
    }
    if ($hadProtocolRow) {
        q("UPDATE tblSet SET Value=? WHERE Setting='ProtocolPoSbanca'", [$origProtocol]);
    } else {
        q("DELETE FROM tblSet WHERE Setting='ProtocolPoSbanca'");
    }
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

summary('POS bancar - status / manual / resync / protocol');
exit($GLOBALS['__exit'] ?? 0);
