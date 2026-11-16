<?php
/**
 * Adaptorul SmartPay ECR Link pentru POS-ul bancar.
 *
 * Fisierul este incarcat doar cand tblSet.ProtocolPoSbanca este SmartPayECR.
 * Contine strict partea care tine de driverul SmartPay: smartpay_client.py, .env si
 * comenzile auxiliare resync/void. Fluxul comun de plata, auditul si decizia
 * manuala raman in api/pos_banca.php.
 */

/**
 * Conexiunea terminalului asa cum o citeste driverul din .env-ul aflat langa
 * vanzare.bat. Nu se hardcodeaza niciun port: pe o statie poate fi COMx, pe
 * alta COMy, iar in dezvoltare transportul este TCP.
 *
 * @param string $batPath
 * @return array{transport:string,serialPort:string,host:string,port:string,label:string}|null
 */
function posBancaConnectionInfo($batPath) {
    $batPath = trim((string)$batPath);
    if ($batPath === '') { return null; }
    $dir = dirname($batPath);
    $env = posBancaReadDotEnv(rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . '.env');
    $transport = strtolower(trim((string)($env['ECR_TRANSPORT'] ?? 'tcp')));
    if ($transport === 'serial') {
        $serialPort = trim((string)($env['ECR_SERIAL_PORT'] ?? ''));
        return [
            'transport' => 'serial',
            'serialPort' => $serialPort,
            'host' => '',
            'port' => '',
            'label' => $serialPort
        ];
    }
    // Valorile implicite ale driverului (smartpay_client.py -> resolve_config).
    $host = trim((string)($env['ECR_HOST'] ?? ''));
    if ($host === '') { $host = '127.0.0.1'; }
    $port = trim((string)($env['ECR_PORT'] ?? ''));
    if ($port === '') { $port = '5070'; }
    return [
        'transport' => 'tcp',
        'serialPort' => '',
        'host' => $host,
        'port' => $port,
        'label' => $host . ':' . $port
    ];
}

/**
 * Normalizeaza textele specifice driverului SmartPay. Mesajele generice
 * (stare necunoscuta) sunt tratate de posBancaUserMessage() inainte de a ajunge
 * aici, ca sa fie valabile pentru orice protocol.
 *
 * @param string $raw
 * @return string
 */
function posBancaSmartPayUserMessage($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') { return ''; }
    if (preg_match('/^Nu s-a putut conecta la (POS|terminalul bancar)/iu', $raw)) {
        return 'Nu s-a putut conecta la terminalul bancar (POS).';
    }
    if (preg_match('/^Eroare de comunicatie cu (POS|terminalul bancar)/iu', $raw)) {
        return 'Eroare de comunicatie cu terminalul bancar (POS).';
    }
    if (preg_match('/^Eroare la lansarea (PoS|driverului terminalului bancar)/iu', $raw)) {
        return 'Eroare la lansarea driverului terminalului bancar (POS).';
    }
    return $raw;
}

/**
 * Ruleaza o re-sincronizare (jurnalul terminalului) pentru un TRX si intoarce
 * campurile parsate. Nu scrie in audit si nu trimite raspuns HTTP: este
 * folosita de actiunea 'resync' si de 'void_doc', care are nevoie de STAN-ul
 * din jurnal atunci cand auditul nu il are (aprobare manuala, raspuns pierdut).
 *
 * @return array{ok:bool,message:string,status:string,found:bool,approved:bool,stan:string,amount:string,rrn:string,raw:string}
 */
function posBancaSmartPayResyncFields($script, $batPath, $pythonDir, $driverLog, $baseResult, $uid) {
    $resultPath = posBancaAuxResultPath($baseResult, 'resync');
    $args = [
        '--timeout', '10', '--retries', '1',
        'resync', '--uid', '"' . $uid . '"', '--max-records', '5'
    ];
    $res = posBancaRunCommand($script, $batPath, $pythonDir, $args, $resultPath, $driverLog, 70000);
    $f = $res['fields'];
    $status = strtoupper((string)($f['STATUS'] ?? ''));
    $found = (($f['FOUND'] ?? '0') === '1');
    return [
        'ok' => (bool)$res['ok'],
        'message' => (string)$res['message'],
        'status' => $res['ok'] ? $status : 'ERROR',
        'found' => $found,
        'approved' => (($f['APPROVED'] ?? ($found ? '1' : '0')) === '1'),
        'stan' => (string)($f['STAN'] ?? ''),
        'amount' => (string)($f['SUMA'] ?? ''),
        'rrn' => (string)($f['RRN'] ?? ''),
        'raw' => (string)$res['raw']
    ];
}

/**
 * Executa o anulare (void) pe terminal si scrie rezultatul in audit. Nu trimite
 * raspuns HTTP: este folosita atat de actiunea 'void' (o tranzactie anume), cat
 * si de 'void_doc' (toate tranzactiile aprobate ale notei).
 *
 * @return array{voided:bool,stan:string,amount:string,uid:string,posStatus:string,posMessage:string,logId:int|null}
 */
function posBancaSmartPayVoid($conn, $docId, $nrDoc, $stan, $amount, $uid, $script, $batPath, $pythonDir, $driverLog, $baseResult) {
    $resultPath = posBancaAuxResultPath($baseResult, 'void');
    $args = [
        '--timeout', '10', '--retries', '1',
        'void', '--stan', '"' . $stan . '"', '--amount', '"' . $amount . '"'
    ];
    $res = posBancaRunCommand($script, $batPath, $pythonDir, $args, $resultPath, $driverLog, 70000);
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
            'auth' => (string)($fields['AUTH'] ?? ''),
            'pan' => (string)($fields['PAN'] ?? ''),
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

function posBancaSmartPayHandleAux($conn, $action, array $input) {
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
    $connection = posBancaConnectionInfo($batPath);
    $script = posBancaDriverScript($batPath);
    if ($script === '') {
        sendJsonResponse([
            "status" => "error",
            "message" => "Driverul configurat nu suporta re-sincronizarea (lipseste smartpay_client.py)."
        ], 400);
    }
    // Verificam ca driverul are comenzile noi (altfel ar da argparse error si
    // am astepta degeaba pana la timeout).
    $scriptSrc = @file_get_contents($script);
    if ($scriptSrc === false || strpos($scriptSrc, '_find_record') === false) {
        sendJsonResponse([
            "status" => "error",
            "message" => "Driverul smartpay_client.py este vechi. Copiati tools/pos_banca/smartpay_client.py in folderul driverului (langa vanzare.bat).",
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
        $uid = trim((string)($input['uid'] ?? ''));
        if ($uid === '') {
            // Cea mai recenta incercare nerezolvata a notei.
            $stmt = sqlsrv_query(
                $conn,
                "SELECT TOP 1 TrxId FROM tblPosBancaLog
                 WHERE DocID = ? AND TrxId IS NOT NULL AND LTRIM(RTRIM(TrxId)) <> ''
                   AND Status IN ('ERROR', 'TIMEOUT', 'UNKNOWN')
                 ORDER BY Id DESC",
                [$docId]
            );
            if ($stmt) {
                $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                if ($row) { $uid = trim((string)$row['TrxId']); }
            }
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,40}$/', $uid)) {
            sendJsonResponse([
                "status" => "error",
                "message" => "Nu exista un TRX_ID de verificat pentru aceasta nota.",
                "connection" => $connection
            ], 400);
        }

        // O decizie manuala este definitiva: un resync tardiv nu are voie sa o
        // suprascrie (ex. operatorul a folosit Manual cat timp terminalul era
        // blocat, iar verificarea automata se termina dupa aceea).
        $attempt = posBancaLatestAttempt($conn, $docId);
        if ($attempt) {
            $manual = posBancaManualAfter($conn, $docId, (int)$attempt['Id']);
            if ($manual) {
                sendJsonResponse(posBancaManualResyncPayload($manual, $attempt, $uid, $connection));
            }
        }

        $resultPath = posBancaAuxResultPath($baseResult, 'resync');
        $args = [
            '--timeout', '10', '--retries', '1',
            'resync', '--uid', '"' . $uid . '"', '--max-records', '5'
        ];
        $res = posBancaRunCommand($script, $batPath, $pythonDir, $args, $resultPath, $driverLog, 70000);
        $fields = $res['fields'];
        $status = strtoupper((string)($fields['STATUS'] ?? ''));
        $found = (($fields['FOUND'] ?? '0') === '1');
        $approved = (($fields['APPROVED'] ?? ($found ? '1' : '0')) === '1');
        $stan = (string)($fields['STAN'] ?? '');
        // Identificatorii tranzactiei gasite in jurnal (RRN-ul e cel de pe
        // chitanta; AUTH/PAN vin din inregistrarea terminalului).
        $rrn = (string)($fields['RRN'] ?? '');
        $auth = (string)($fields['AUTH'] ?? '');
        $pan = (string)($fields['PAN'] ?? '');
        $amount = (string)($fields['SUMA'] ?? '');
        $type = (string)($fields['TYPE'] ?? '');
        $msg = (string)($fields['MESAJ'] ?? '');
        if (!$res['ok']) {
            $status = 'ERROR';
            $msg = $res['message'];
        }
        // Decizia manuala poate fi luata cat timp resync-ul ruleaza: in acest
        // caz rezultatul automat nu mai este scris, ca sa nu suprascrie omul.
        if ($attempt) {
            $manualLate = posBancaManualAfter($conn, $docId, (int)$attempt['Id']);
            if ($manualLate) {
                sendJsonResponse(posBancaManualResyncPayload($manualLate, $attempt, $uid, $connection));
            }
        }
        // Marcam rezultatul in Mesaj (FOUND/APPROVED), ca actiunea "status" si
        // verificarea manuala sa stie daca terminalul a confirmat deja plata.
        $logMsg = $msg;
        if ($res['ok'] && ($status === 'OK' || $status === 'NOTFOUND')) {
            $logMsg = 'FOUND=' . ($found ? '1' : '0')
                . ' APPROVED=' . ($approved ? '1' : '0')
                . ($msg !== '' ? ' ' . $msg : '');
        }
        $detalii = trim($res['raw'] . "\n" . posBancaDriverTail($driverLog, 2000));
        $logId = posBancaLogAttempt(
            $conn, $docId, $nrDoc, (float)($amount !== '' ? $amount : 0),
            $uid, 'RESYNC', $logMsg, false, 'verificat', $detalii,
            ['rrn' => $rrn, 'stan' => $stan, 'auth' => $auth, 'pan' => $pan, 'raw' => (string)$res['raw']]
        );
        sendJsonResponse([
            "status" => "success",
            "enabled" => true,
            "found" => $found,
            "approved" => $approved,
            "voided" => false,
            "uid" => $uid,
            "stan" => $stan,
            "rrn" => $rrn,
            "amount" => $amount,
            "type" => $type,
            "posStatus" => $status,
            "posMessage" => $msg,
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
            // Auditul nu are intotdeauna STAN-ul (aprobare manuala, raspuns
            // pierdut la vanzare). Jurnalul terminalului il are: il cerem intai,
            // altfel void-ul nu poate tinta tranzactia.
            if ($stan === '' && $txUid !== '' && preg_match('/^[A-Za-z0-9._-]{1,40}$/', $txUid)) {
                $rs = posBancaSmartPayResyncFields(
                    $script, $batPath, $pythonDir, $driverLog, $baseResult, $txUid
                );
                $rsStan = preg_replace('/\D/', '', (string)$rs['stan']);
                if ($rs['ok'] && $rs['found'] && $rsStan !== '') {
                    $stan = $rsStan;
                    if ((float)$rs['amount'] > 0) {
                        $txAmount = number_format((float)$rs['amount'], 2, '.', '');
                    }
                    // Consemnam citirea jurnalului, ca STAN-ul sa fie disponibil
                    // si pentru urmatoarele anulari / pentru status.
                    posBancaLogAttempt(
                        $conn, $docId, $nrDoc, (float)$txAmount, $txUid, 'RESYNC',
                        'FOUND=1 APPROVED=' . ($rs['approved'] ? '1' : '0') . ' (STAN pentru void)',
                        false, 'verificat', trim($rs['raw']),
                        ['rrn' => $rs['rrn'], 'stan' => $stan, 'raw' => $rs['raw']]
                    );
                } elseif ($rs['ok'] && $rs['status'] === 'NOTFOUND'
                        && (string)($tx['source'] ?? '') === 'manual') {
                    // Jurnalul terminalului a fost citit si tranzactia NU exista:
                    // aprobarea manuala era o declaratie a operatorului (chitanta
                    // de la alt terminal / citita gresit), deci nu exista bani de
                    // anulat pe terminal. O retragem din audit, ca nota sa nu
                    // rămâna blocata pe o suma inexistenta.
                    $upM = @sqlsrv_query(
                        $conn,
                        "UPDATE tblPosBancaLog SET Actiune = 'neaprobat', ResolvedAt = GETDATE()
                         WHERE DocID = ? AND TrxId = ? AND Status = 'MANUAL' AND Actiune = 'aprobat'",
                        [$docId, $txUid]
                    );
                    $upA = @sqlsrv_query(
                        $conn,
                        "UPDATE tblPosBancaLog
                            SET Status = 'CANCELED_MANUAL', Actiune = 'neaprobat', ResolvedAt = GETDATE(),
                                Mesaj = LEFT(ISNULL(Mesaj, '') + ' | Anulata: tranzactia nu exista in jurnalul terminalului', 255)
                          WHERE DocID = ? AND TrxId = ? AND Status IN ('UNKNOWN', 'TIMEOUT', 'ERROR')",
                        [$docId, $txUid]
                    );
                    if ($upM !== false && $upA !== false) {
                        appEventLog('pos_banca_void_doc_manual_cancel', [
                            'docId' => $docId,
                            'trxId' => $txUid,
                            'suma' => $txAmount,
                            'motiv' => 'aprobare manuala fara tranzactie in jurnal'
                        ]);
                        $voided[] = [
                            'trxId' => $txUid,
                            'amount' => $txAmount,
                            'posMessage' => 'Aprobarea manuala a fost retrasa (tranzactia nu exista in jurnalul terminalului).',
                            'cancelled' => true
                        ];
                        continue;
                    }
                    $failed[] = [
                        'trxId' => $txUid,
                        'amount' => $txAmount,
                        'posMessage' => 'Aprobarea manuala nu a putut fi retrasa.'
                    ];
                    continue;
                } else {
                    $failed[] = [
                        'trxId' => $txUid,
                        'amount' => $txAmount,
                        'posMessage' => $rs['message'] !== ''
                            ? $rs['message']
                            : 'Jurnalul terminalului nu a putut fi citit; anularea se face de pe terminal.'
                    ];
                    continue;
                }
            }
            if ($stan === '' || (float)$txAmount <= 0) {
                $failed[] = [
                    'trxId' => $txUid,
                    'amount' => $txAmount,
                    'posMessage' => 'STAN lipsa in audit: anularea se face de pe terminal.'
                ];
                continue;
            }
            $r = posBancaSmartPayVoid(
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

    // action === 'void'
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

    $r = posBancaSmartPayVoid(
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
