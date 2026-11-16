<?php
/**
 * Adaptorul Viva.com Terminal pentru POS-ul bancar (ECR Protocol TCP/IP).
 *
 * Fisierul este incarcat doar cand tblSet.ProtocolPoSbanca este VivaECR.
 * Protocolul (developer.viva.com -> In-person payments -> ECR Protocol) este
 * implementat de driverul Python tools/pos_banca/viva_client.py, care vorbeste
 * cu terminalul pe socket TCP (implicit port 8080) si scrie acelasi fisier de
 * rezultat ca SmartPay (STATUS/MESAJ/TRX_ID/EMITE_BON + RRN/STAN/AUTH/PAN).
 *
 * Aici sta strict partea care tine de Viva:
 *   - conexiunea citita din .env-ul de langa driver (ECR_HOST / ECR_PORT),
 *   - verificarea unei plati incerte cu ultima tranzactie din terminal
 *     (getLastReceipt) - protocolul nu permite cautarea dupa un id,
 *   - anularea (txCancelRequest) pe baza STAN-ului din audit.
 *
 * Fluxul comun de plata, auditul si decizia manuala raman in api/pos_banca.php.
 */

/**
 * Conexiunea terminalului asa cum o citeste driverul din .env-ul aflat langa
 * vanzare.bat. Viva ECR este doar TCP; nu hardcodam portul (implicit 8080, cel
 * afisat de terminal cand se activeaza ERP/ECR).
 *
 * @param string $batPath
 * @return array{transport:string,serialPort:string,host:string,port:string,label:string}|null
 */
function posBancaVivaConnectionInfo($batPath) {
    $batPath = trim((string)$batPath);
    if ($batPath === '') { return null; }
    $dir = dirname($batPath);
    $env = posBancaReadDotEnv(rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . '.env');
    $host = trim((string)($env['ECR_HOST'] ?? ''));
    if ($host === '') { return null; }
    $port = trim((string)($env['ECR_PORT'] ?? ''));
    if ($port === '') { $port = '8080'; }
    return [
        'transport' => 'tcp',
        'serialPort' => '',
        'host' => $host,
        'port' => $port,
        'label' => $host . ':' . $port
    ];
}

/**
 * Textele driverului Viva sunt deja clare (le scrie viva_client.py cu aceleasi
 * formulari canonice ca SmartPay), deci nu mai e nevoie de normalizare.
 *
 * @param string $raw
 * @return string
 */
function posBancaVivaUserMessage($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') { return ''; }
    if (preg_match('/^Nu s-a putut conecta la (POS|terminalul bancar)/iu', $raw)) {
        return 'Nu s-a putut conecta la terminalul bancar (POS).';
    }
    if (preg_match('/^Eroare de comunicatie cu (POS|terminalul bancar)/iu', $raw)) {
        return 'Eroare de comunicatie cu terminalul bancar (POS).';
    }
    return $raw;
}

/**
 * Scriptul Python al driverului Viva, daca exista langa .bat.
 *
 * @param string $batPath
 */
function posBancaVivaScript($batPath) {
    $script = rtrim(dirname($batPath), '\\/') . '\\viva_client.py';
    return is_file($script) ? $script : '';
}

/**
 * Incercarea bancara indicata de client (dupa TRX_ID) sau, daca nu exista,
 * ultima incercare efectiva a notei. Este folosita la potrivirea cu ultima
 * tranzactie din terminal (suma / interval orar).
 *
 * @param resource $conn
 * @param int $docId
 * @param string $uid
 * @return array<string,mixed>|null
 */
function posBancaVivaFindAttempt($conn, $docId, $uid) {
    $uid = trim((string)$uid);
    if ($uid !== '' && preg_match('/^[A-Za-z0-9._-]{1,40}$/', $uid)) {
        $stmt = sqlsrv_query(
            $conn,
            "SELECT TOP 1 Id, NrDoc, Suma, TrxId, Status, Mesaj, Actiune, CreatedAt
             FROM tblPosBancaLog
             WHERE DocID = ? AND TrxId = ?
               AND Status NOT IN ('RESYNC', 'VOID', 'MANUAL', 'CLOSED', 'MISMATCH')
             ORDER BY Id DESC",
            [(int)$docId, $uid]
        );
        if ($stmt) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            if ($row) { return $row; }
        }
    }
    return posBancaLatestAttempt($conn, (int)$docId);
}

/**
 * RRN-ul a mai fost inregistrat in audit in ultimele 24h? (inclusiv pentru
 * aceeasi nota, ca o verificare repetata sa nu dubleze suma aprobata).
 *
 * @param resource $conn
 * @param string $rrn
 */
function posBancaVivaRrnSeen($conn, $rrn) {
    $rrn = trim((string)$rrn);
    if ($rrn === '') { return true; }
    $stmt = sqlsrv_query(
        $conn,
        "SELECT TOP 1 Id FROM tblPosBancaLog
         WHERE Rrn = ? AND CreatedAt >= DATEADD(hour, -24, GETDATE())",
        [$rrn]
    );
    if (!$stmt) { return true; }
    return (bool)sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
}

/**
 * Anuleaza o tranzactie pe terminal (txCancelRequest, 200/04) si scrie
 * rezultatul in audit. Nu trimite raspuns HTTP: este folosita atat de actiunea
 * 'void', cat si de 'void_doc' (toate tranzactiile aprobate ale notei).
 *
 * @return array{voided:bool,stan:string,amount:string,uid:string,posStatus:string,posMessage:string,logId:int|null}
 */
function posBancaVivaVoid($conn, $docId, $nrDoc, $stan, $amount, $uid, $script, $batPath, $pythonDir, $driverLog, $baseResult) {
    $resultPath = posBancaAuxResultPath($baseResult, 'void');
    $args = [
        '--timeout', '30', '--retries', '1',
        'void', '--stan', '"' . $stan . '"', '--amount', '"' . $amount . '"'
    ];
    $res = posBancaRunCommand($script, $batPath, $pythonDir, $args, $resultPath, $driverLog, 90000);
    $fields = $res['fields'];
    $status = strtoupper((string)($fields['STATUS'] ?? ''));
    $voided = (($fields['VOIDED'] ?? '0') === '1');
    $msg = (string)($fields['MESAJ'] ?? '');
    if (!$res['ok']) {
        $status = 'ERROR';
        $msg = $res['message'];
    }
    $detalii = trim($res['raw'] . "\n" . posBancaDriverTail($driverLog, 2000));
    $logId = posBancaLogAttempt(
        $conn, $docId, $nrDoc, (float)$amount, $uid, 'VOID', $msg, false,
        $voided ? 'anulat' : null, $detalii,
        [
            'rrn' => (string)($fields['RRN'] ?? ''),
            'stan' => $stan,
            'raw' => (string)$res['raw']
        ]
    );
    return [
        'voided' => $voided,
        'stan' => $stan,
        'amount' => $amount,
        'uid' => $uid,
        'posStatus' => $status,
        'posMessage' => $msg,
        'logId' => $logId
    ];
}

/**
 * Decide daca ultima tranzactie citita din terminal (getLastReceipt) este chiar
 * plata incercata. Functie pura, ca sa poata fi testata fara baza de date.
 *
 * Potrivirea este intentionat strictă: suma (±0.01), momentul in fereastra
 * [incercare - 60s, acum + 120s] si un RRN nou (care nu mai apare in audit).
 * Orice nepotrivire inseamna "neconfirmat" (manual), niciodata "nu a fost
 * debitat" - o concluzie gresita ar permite o a doua taxare.
 *
 * @param float $attemptAmount
 * @param int $attemptTs
 * @param string $amount
 * @param int $epoch
 * @param string $rrn
 * @param bool $rrnSeen
 * @param int|null $now
 * @return array{0:bool,1:string}
 */
function posBancaVivaEvaluateReceipt($attemptAmount, $attemptTs, $amount, $epoch, $rrn, $rrnSeen, $now = null) {
    $now = $now === null ? time() : (int)$now;
    if ((float)$attemptAmount <= 0) {
        return [false, 'Suma incercarii nu este valida.'];
    }
    if ($amount === '' || !is_numeric($amount)) {
        return [false, 'Ultima tranzactie din terminal nu a putut fi citita.'];
    }
    if (abs((float)$amount - (float)$attemptAmount) > 0.01) {
        return [false, 'Ultima tranzactie din terminal are alta suma decat plata incercata.'];
    }
    if ((int)$attemptTs <= 0 || (int)$epoch <= 0
        || (int)$epoch < ((int)$attemptTs - 60) || (int)$epoch > ($now + 120)) {
        return [false, 'Ultima tranzactie din terminal este din alt interval orar.'];
    }
    if (preg_match('/^\d{6,12}$/', (string)$rrn) !== 1) {
        return [false, 'Ultima tranzactie din terminal nu are RRN.'];
    }
    if ($rrnSeen) {
        return [false, 'Ultima tranzactie din terminal a fost deja inregistrata.'];
    }
    return [true, ''];
}

/**
 * Trateaza actiunile auxiliare Viva (resync / void / void_doc). Este apelata
 * de api/pos_banca.php doar dupa verificarea capabilitatilor protocolului.
 *
 * @param resource $conn
 * @param string $action
 * @param array<string,mixed> $input
 */
function posBancaVivaHandleAux($conn, $action, array $input) {
    ensurePosBancaLogTable($conn);
    $docId = (int)($input['docId'] ?? 0);
    if ($docId <= 0) {
        sendJsonResponse(["status" => "error", "message" => "Parametri invalizi"], 400);
    }
    $driverRaw = posBancaSetting($conn, 'CaleDriverPoSbanca', '');
    $batPath = posBancaResolveBatPath($driverRaw);
    if ($batPath === '' || !is_file($batPath)) {
        sendJsonResponse(["status" => "error", "message" => "Driverul terminalului bancar (POS) nu este configurat."], 400);
    }
    $connection = posBancaVivaConnectionInfo($batPath);
    $script = posBancaVivaScript($batPath);
    if ($script === '') {
        sendJsonResponse([
            "status" => "error",
            "message" => "Driverul configurat nu suporta verificarea (lipseste viva_client.py)."
        ], 400);
    }
    // Verificam ca driverul are comenzile noi (altfel ar da argparse error si
    // am astepta degeaba pana la timeout).
    $scriptSrc = @file_get_contents($script);
    if ($scriptSrc === false || strpos($scriptSrc, 'last-receipt') === false) {
        sendJsonResponse([
            "status" => "error",
            "message" => "Driverul viva_client.py este vechi. Copiati tools/pos_banca/viva_client.py in folderul driverului (langa vanzare.bat).",
            "connection" => $connection
        ], 400);
    }

    $nrDoc = posBancaNrDoc($conn, $docId);
    $pythonDir = posBancaFindPythonDir();
    $driverLog = __DIR__ . '/../logs/pos_banca_driver.log';
    @mkdir(dirname($driverLog), 0777, true);
    $baseResult = posBancaResultPathFor($batPath, posBancaSetting($conn, 'CaleFisierRaspunsPoSbanca', ''));
    set_time_limit(0);
    ignore_user_abort(true);

    if ($action === 'resync') {
        $requestedUid = trim((string)($input['uid'] ?? ''));
        $attempt = posBancaVivaFindAttempt($conn, $docId, $requestedUid);
        if (!$attempt) {
            sendJsonResponse([
                "status" => "error",
                "message" => "Nu exista o plata bancara pentru aceasta nota.",
                "connection" => $connection
            ], 400);
        }
        $uid = trim((string)($attempt['TrxId'] ?? ''));
        if ($uid === '') { $uid = $requestedUid; }

        // O decizie manuala este definitiva: un resync tardiv nu are voie sa o
        // suprascrie (ex. operatorul a folosit Manual cat timp terminalul era
        // blocat, iar verificarea automata se termina dupa aceea).
        $manual = posBancaManualAfter($conn, $docId, (int)$attempt['Id']);
        if ($manual) {
            sendJsonResponse(posBancaManualResyncPayload($manual, $attempt, $uid, $connection));
        }

        // Ultima tranzactie din terminal. Protocolul ECR nu permite interogarea
        // dupa un id, deci putem doar CONFIRMA aprobarea cand suma si momentul
        // corespund acestei incercari. Nu raportam niciodata "nu a fost
        // debitat": o concluzie gresita ar permite o a doua taxare.
        $resultPath = posBancaAuxResultPath($baseResult, 'resync');
        $args = ['--timeout', '15', '--retries', '1', 'last-receipt'];
        $res = posBancaRunCommand($script, $batPath, $pythonDir, $args, $resultPath, $driverLog, 70000);
        $fields = $res['fields'];
        $status = strtoupper(trim((string)($fields['STATUS'] ?? '')));
        $receiptOk = $res['ok'] && $status === 'OK';
        $amount = trim((string)($fields['AMOUNT'] ?? ''));
        $rrn = preg_replace('/\D/', '', (string)($fields['RRN'] ?? ''));
        $epoch = (int)($fields['TXN_EPOCH'] ?? 0);
        $attemptAmount = (float)($attempt['Suma'] ?? 0);
        $attemptTs = strtotime((string)($attempt['CreatedAt'] ?? ''));
        $now = time();

        if (!$res['ok'] || !$receiptOk) {
            $confirmed = false;
            $reason = (!$res['ok'] && $res['message'] !== '')
                ? $res['message']
                : 'Ultima tranzactie din terminal nu a putut fi citita.';
        } else {
            list($confirmed, $reason) = posBancaVivaEvaluateReceipt(
                $attemptAmount,
                $attemptTs === false ? 0 : (int)$attemptTs,
                $amount, $epoch, $rrn,
                posBancaVivaRrnSeen($conn, $rrn),
                $now
            );
        }

        // Decizia manuala poate fi luata cat timp comanda ruleaza: in acest caz
        // rezultatul automat nu mai este scris, ca sa nu suprascrie omul.
        $manualLate = posBancaManualAfter($conn, $docId, (int)$attempt['Id']);
        if ($manualLate) {
            sendJsonResponse(posBancaManualResyncPayload($manualLate, $attempt, $uid, $connection));
        }

        // Marcam rezultatul in Mesaj (FOUND/APPROVED), ca actiunea "status" si
        // posBancaApprovedTotal sa stie daca terminalul a confirmat plata.
        $logMsg = $confirmed
            ? 'FOUND=1 APPROVED=1 ULTIMA TRANZACTIE ' . $amount . ' RRN=' . $rrn
            : 'NECONFIRMAT: ' . $reason;
        $detalii = trim($res['raw'] . "\n" . posBancaDriverTail($driverLog, 2000));
        $logId = posBancaLogAttempt(
            $conn, $docId, $nrDoc,
            $confirmed ? (float)$amount : $attemptAmount,
            $uid, 'RESYNC', $logMsg, false, 'verificat', $detalii,
            ['rrn' => $confirmed ? $rrn : '', 'raw' => (string)$res['raw']]
        );
        sendJsonResponse([
            "status" => "success",
            "enabled" => true,
            "found" => $confirmed,
            "approved" => $confirmed,
            "voided" => false,
            "uid" => $uid,
            "stan" => "",
            "rrn" => $confirmed ? $rrn : '',
            "amount" => $confirmed ? $amount : '',
            "type" => "LAST",
            "posStatus" => $confirmed ? 'OK' : 'ERROR',
            "posMessage" => $confirmed
                ? 'Ultima tranzactie a terminalului corespunde acestei plati.'
                : $reason,
            "logId" => $logId,
            "connection" => $connection
        ]);
    }

    // action === 'void_doc': anuleaza toate sumele aprobate ale notei. Folosit
    // cand operatorul paraseste ecranul de plata ("totul sau nimic"): banii
    // incasati pe card nu au voie sa rămâna fara o nota care sa ii contina.
    if ($action === 'void_doc') {
        $approved = posBancaApprovedTotal($conn, $docId);
        $voided = [];
        $failed = [];
        foreach ($approved['entries'] as $tx) {
            $stan = preg_replace('/\D/', '', (string)($tx['stan'] ?? ''));
            $txAmount = number_format((float)($tx['amount'] ?? 0), 2, '.', '');
            $txUid = (string)($tx['trxId'] ?? '');
            // Protocolul Viva nu are un jurnal interogabil: fara STAN in audit
            // (aprobare manuala sau raspuns pierdut la vanzare) tranzactia nu
            // poate fi tintita. Anularea se face de pe terminal.
            if ($stan === '' || (float)$txAmount <= 0) {
                $failed[] = [
                    'trxId' => $txUid,
                    'amount' => $txAmount,
                    'posMessage' => 'STAN lipsa in audit (aprobare manuala sau raspuns pierdut): anularea se face de pe terminal.'
                ];
                continue;
            }
            $r = posBancaVivaVoid(
                $conn, $docId, $nrDoc, $stan, $txAmount, $txUid,
                $script, $batPath, $pythonDir, $driverLog, $baseResult
            );
            if ($r['voided']) {
                $voided[] = $r + ['trxId' => $txUid];
            } else {
                $failed[] = $r + ['trxId' => $txUid];
            }
        }
        $after = posBancaApprovedTotal($conn, $docId);
        sendJsonResponse([
            "status" => "success",
            "enabled" => true,
            "voided" => $voided,
            "failed" => $failed,
            "approvedTotal" => $after['total'],
            "connection" => $connection
        ]);
    }

    // action === 'void' (o singura tranzactie, dupa STAN + suma)
    $stan = preg_replace('/\D/', '', (string)($input['stan'] ?? ''));
    $amount = str_replace(',', '.', trim((string)($input['amount'] ?? '')));
    $uid = trim((string)($input['uid'] ?? ''));
    if ($stan === '' || $amount === '' || !is_numeric($amount)) {
        sendJsonResponse([
            "status" => "error",
            "message" => "Parametri invalizi (STAN / suma).",
            "connection" => $connection
        ], 400);
    }
    if ($uid !== '' && !preg_match('/^[A-Za-z0-9._-]{1,40}$/', $uid)) {
        $uid = '';
    }
    // Fara TRX_ID, anularea nu ar fi scazuta din suma aprobata (auditul este
    // grupat pe TrxId): il luam din randul aprobat care poarta acest STAN.
    if ($uid === '') {
        $stUid = sqlsrv_query(
            $conn,
            "SELECT TOP 1 TrxId FROM tblPosBancaLog
             WHERE DocID = ? AND Stan = ? AND EmiteBon = 1
             ORDER BY Id DESC",
            [$docId, $stan]
        );
        if ($stUid) {
            $rowUid = sqlsrv_fetch_array($stUid, SQLSRV_FETCH_ASSOC);
            if ($rowUid) { $uid = trim((string)$rowUid['TrxId']); }
        }
    }

    $r = posBancaVivaVoid(
        $conn, $docId, $nrDoc, $stan, $amount, $uid,
        $script, $batPath, $pythonDir, $driverLog, $baseResult
    );
    sendJsonResponse([
        "status" => "success",
        "enabled" => true,
        "voided" => $r['voided'],
        "stan" => $r['stan'],
        "amount" => $r['amount'],
        "uid" => $r['uid'],
        "posStatus" => $r['posStatus'],
        "posMessage" => $r['posMessage'],
        "logId" => $r['logId'],
        "connection" => $connection
    ]);
}
