<?php
/**
 * API: comunicarea cu POS-ul bancar (terminalul de card) la inchiderea notei.
 *
 * Reproduce fluxul din vechea aplicatie VBA (SendtoPoS): cand nota are o plata
 * pe card (FPID = 1) si tblSet.PoSbanca = 1, se lanseaza driverul configurat in
 * tblSet.CaleDriverPoSbanca, asteptand fisierul de rezultat (tblSet.
 * CaleFisierRaspunsPoSbanca, implicit rezultat_ecr.txt langa driver). Fisierul
 * de rezultat este de forma:
 *
 *     STATUS=OK|ERROR
 *     MESAJ=text
 *     TRX_ID=...
 *     EMITE_BON=DA|NU
 *
 * Protocolul este ales in tblSet.ProtocolPoSbanca si decide capabilitatile
 * optionale (jurnal/resync/void, conexiune .env). Exista doua drivere cu
 * identitate clara: SmartPayECR (SmartPay ECR Link; api/pos_banca_smartpay.php)
 * si VivaECR (Viva.com Terminal, ECR Protocol TCP/IP; api/pos_banca_viva.php).
 * Registrul este in api/pos_banca_protocol.php.
 *
 * Apelurile catre terminal se persista in tblPosBancaLog (audit), iar decizia
 * finala a utilizatorului (aprobat / ignorat / anulat) este scrisa de acelasi
 * endpoint prin actiunea "resolve".
 *
 * Actiuni:
 *   POST {action:'pay', amount, docId}          -> ruleaza driverul
 *   POST {action:'resolve', docId, actiune}     -> marcheaza decizia finala
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/app_events.php';
require_once __DIR__ . '/pos_banca_protocol.php';
require_once __DIR__ . '/pos_banca_state.php';
require_once __DIR__ . '/pos_banca_driver.php';

/**
 * Citeste o setare din tblSet (cheie/valoare). Implicit $default cand lipseste.
 *
 * @param resource $conn
 * @param string $setting
 */
function posBancaSetting($conn, $setting, $default = '') {
    $stmt = sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = ?", [$setting]);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row) { return trim((string)$row['Value']); }
    }
    return $default;
}

/**
 * Protocolul POS bancar configurat pe statie.
 *
 * @param resource $conn
 */
function posBancaProtocol($conn) {
    return posBancaProtocolNormalize(posBancaSetting($conn, 'ProtocolPoSbanca', posBancaProtocolDefault()));
}

/**
 * Metadatele trimise clientului ca sa stie ce butoane are sens sa afiseze.
 *
 * @param resource $conn
 * @return array{protocol:string,canResync:bool,canVoid:bool}
 */
function posBancaProtocolMeta($conn) {
    $protocol = posBancaProtocol($conn);
    $caps = posBancaProtocolCaps($protocol);
    return [
        "protocol" => $protocol,
        "canResync" => !empty($caps['journal']),
        "canVoid" => !empty($caps['void']),
    ];
}

/**
 * Conexiunea terminalului este specifica fiecarui driver si se citeste din
 * .env-ul de langa .bat (SmartPay: TCP/serial, Viva: TCP). Pentru protocoalele
 * fara conexiune citita din .env nu afisam un port care nu are legatura cu
 * driverul.
 *
 * @param resource $conn
 * @param string $batPath
 * @return array<string,mixed>|null
 */
function posBancaConnectionForProtocol($conn, $batPath) {
    $protocol = posBancaProtocol($conn);
    $caps = posBancaProtocolCaps($protocol);
    if (empty($caps['envConnection'])) { return null; }
    $info = posBancaProtocolInfo($protocol);
    $adapter = trim((string)($info['adapter'] ?? ''));
    $reader = trim((string)($info['connection'] ?? ''));
    if ($adapter === '' || $reader === '') { return null; }
    require_once __DIR__ . '/' . $adapter;
    if (!function_exists($reader)) { return null; }
    return call_user_func($reader, $batPath);
}

/**
 * Rezolva calea executabilului: daca $path este chiar .bat/.cmd/.exe se foloseste
 * ca atare, altfel se considera folder si se adauga numele implicit.
 *
 * @param string $path
 */
function posBancaResolveBatPath($path) {
    $path = trim((string)$path);
    if ($path === '') { return ''; }
    if (preg_match('/\.(bat|cmd|exe)$/i', $path)) { return $path; }
    return rtrim($path, "\\/") . '\\vanzare.bat';
}

/**
 * Calea fisierului de rezultat. Daca setarea e completata se foloseste (folder
 * -> se adauga numele implicit), altfel folderul driverului + rezultat_ecr.txt.
 *
 * @param string $batPath
 * @param string $override
 */
function posBancaResultPathFor($batPath, $override) {
    $override = trim((string)$override);
    if ($override !== '') {
        if (is_dir($override) || preg_match('/[\\\\\/]$/', $override)) {
            return rtrim($override, "\\/") . '\\rezultat_ecr.txt';
        }
        return $override;
    }
    $dir = dirname($batPath);
    return rtrim($dir, "\\/") . '\\rezultat_ecr.txt';
}

/**
 * @param mixed $v
 */
function posBancaFormatAmount($v) {
    return number_format((float)$v, 2, '.', '');
}

function posBancaNewTrxId() {
    return 'TX' . date('YmdHis');
}

/**
 * Normalizeaza textul scris de driverul POS bancar intr-un mesaj clar pentru
 * casier, care se traduce apoi prin catalogul i18n (api/lang/*.php).
 *
 * Mesajele generice (ex. starea necunoscuta) sunt valabile pentru orice
 * protocol. Textele specifice SmartPay sunt normalizate doar cand protocolul
 * are conexiune citita din .env (adica este SmartPayECR).
 *
 * @param string $raw
 * @param string|null $protocol
 * @return string
 */
function posBancaUserMessage($raw, $protocol = null) {
    $raw = trim((string)$raw);
    if ($raw === '') { return ''; }
    if (stripos($raw, 'stare necunoscuta') !== false) {
        return 'Stare necunoscuta: terminalul nu a confirmat rezultatul. Verificati terminalul (ecran/jurnal/chitanta) inainte de reincercare.';
    }
    $protocol = $protocol !== null
        ? posBancaProtocolNormalize($protocol)
        : posBancaProtocolDefault();
    $caps = posBancaProtocolCaps($protocol);
    if (!empty($caps['envConnection'])) {
        $info = posBancaProtocolInfo($protocol);
        $adapter = trim((string)($info['adapter'] ?? ''));
        $normalizer = trim((string)($info['userMessage'] ?? ''));
        if ($adapter !== '' && $normalizer !== '') {
            require_once __DIR__ . '/' . $adapter;
            if (function_exists($normalizer)) {
                return call_user_func($normalizer, $raw);
            }
        }
    }
    return $raw;
}

/** Numarul notei (tblBonCurent.NrDoc) pentru un DocID, sau null. */
/**
 * @param resource $conn
 * @param int $docId
 */
function posBancaNrDoc($conn, $docId) {
    if ((int)$docId <= 0) { return null; }
    $stmt = sqlsrv_query($conn, "SELECT TOP 1 NrDoc FROM tblBonCurent WHERE DocID = ?", [(int)$docId]);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row) { return (int)$row['NrDoc']; }
    }
    return null;
}

// Functiile specifice fiecarui driver (smartpay_client.py / viva_client.py, .env,
// conexiune, resync/void) sunt in api/pos_banca_smartpay.php, respectiv
// api/pos_banca_viva.php, incarcate doar pentru protocolul selectat.

/**
 * Numarul operatorului care a deschis nota (fallback pentru auditul manual).
 *
 * @param resource $conn
 * @param int $docId
 */
function posBancaBillNrOp($conn, $docId) {
    if ((int)$docId <= 0) { return ''; }
    $stmt = sqlsrv_query($conn, "SELECT TOP 1 NrOp FROM tblBonCurent WHERE DocID = ?", [(int)$docId]);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row && $row['NrOp'] !== null) { return trim((string)$row['NrOp']); }
    }
    return '';
}

/**
 * Liniile de antet din tblAntet (implicit H1-H2), cu font/size/bold, folosite
 * pe duplicatul chitantei de card (acelasi format ca antetul notei).
 *
 * @param resource $conn
 * @param array<int,string> $series
 * @return array<int,array{text:string,font:string,size:int,bold:bool}>
 */
function posBancaHeaderLines($conn, array $series = ['H1', 'H2']) {
    $out = [];
    if (empty($series)) { return $out; }
    $placeholders = implode(',', array_fill(0, count($series), '?'));
    $stmt = sqlsrv_query(
        $conn,
        "SELECT Seria, Nume, NumeFont, Size, Bold FROM tblAntet WHERE Seria IN ($placeholders)",
        $series
    );
    if (!$stmt) { return $out; }
    $tmp = [];
    while ($h = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $nume = trim((string)($h['Nume'] ?? ''));
        if ($nume === '') { continue; }
        $sizeRaw = $h['Size'];
        $tmp[trim((string)$h['Seria'])] = [
            "text" => $nume,
            "font" => trim((string)($h['NumeFont'] ?? '')),
            "size" => ($sizeRaw === null || $sizeRaw === '') ? 0 : (int)$sizeRaw,
            "bold" => ((int)$h['Bold'] !== 0)
        ];
    }
    foreach ($series as $k) {
        if (!empty($tmp[$k])) { $out[] = $tmp[$k]; }
    }
    return $out;
}

/**
 * Numele operatorului dupa NrOsp (tblOsp). Fara parola: decidem pe baza
 * operatorului logat, dar il consemnam ca sa stim cine a luat decizia.
 *
 * @param resource $conn
 * @param string $nrOp
 */
function posBancaOperatorName($conn, $nrOp) {
    $nrOp = trim((string)$nrOp);
    if ($nrOp === '') { return ''; }
    $stmt = sqlsrv_query($conn, "SELECT TOP 1 Nume FROM tblOsp WHERE LTRIM(RTRIM(NrOsp)) = ?", [$nrOp]);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row) { return trim((string)$row['Nume']); }
    }
    return '';
}

// Ultima incercare, rezolvarile auxiliare si definitia "incertitudinii" sunt in
// api/pos_banca_state.php (partajate cu lock-ul notei din order_action.php).

// Comenzile auxiliare (resync/void), interpretorul Python si fisierele lor de
// rezultat sunt in adaptorul protocolului, incarcat conditionat la nevoie.

$conn = getDBConnection();

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input || !is_array($input)) { $input = $_POST; }
$action = $input['action'] ?? '';

if ($action === 'resolve') {
    // Decizia finala a utilizatorului pentru incercarile inca nerezolvate ale notei.
    $docId = (int)($input['docId'] ?? 0);
    $actiune = trim((string)($input['actiune'] ?? ''));
    $trxId = trim((string)($input['trxId'] ?? ''));
    $allowed = ['aprobat', 'ignorat', 'anulat', 'dialog_inchis', 'neaprobat'];
    if ($docId <= 0 || !in_array($actiune, $allowed, true)) {
        sendJsonResponse(["status" => "error", "message" => "Parametri invalizi"], 400);
    }
    ensurePosBancaLogTable($conn);

    // "Nu a tipărit (Anulează)": operatorul declara ca terminalul nu a emis
    // chitanta, deci in practica tranzactia nu a fost aprobata de banca (sau
    // terminalul a stornat-o singur, ex. lipsa hartiei). Incercarea curenta
    // este abandonata: randul ei (identificat prin TRX_ID) primeste
    // Status=CANCELED_MANUAL + ResolvedAt, iar nota se deblocheaza pentru o
    // noua incercare pe card (TRX_ID nou) sau pentru plata cu numerar.
    if ($actiune === 'neaprobat') {
        $state = posBancaDocState($conn, $docId);
        $attempt = $state['attempt'];
        if (!$attempt) {
            sendJsonResponse(["status" => "error", "message" => "Nu exista o plata bancara pentru aceasta nota."], 400);
        }
        // Terminalul a confirmat deja plata (jurnal sau decizie manuala): nu
        // putem abandona o incasare reala din dialogul de eroare.
        if (($state['resyncApproved'] ?? false) === true || ($state['manualApproved'] ?? false) === true) {
            sendJsonResponse([
                "status" => "error",
                "code" => "bank_pos_approved",
                "message" => "Terminalul a confirmat deja plata ca aprobata. Pastrati plata "
                    . "(anularea se face de pe terminal).",
                "uncertain" => true
            ], 409);
        }
        $attemptTrx = trim((string)($attempt['TrxId'] ?? ''));
        // TRX_ID-ul afisat in dialog trebuie sa fie al incercarii curente: un
        // raspuns intarziat nu are voie sa anuleze o incercare mai noua.
        if ($trxId !== '' && strcasecmp($trxId, $attemptTrx) !== 0) {
            sendJsonResponse([
                "status" => "error",
                "code" => "stale_attempt",
                "message" => "Plata bancara s-a schimbat intre timp. Verificati din nou nota.",
                "uncertain" => ($state['uncertain'] ?? false) === true
            ], 409);
        }
        // Deja rezolvata (resync "nu apare" / void / decizie manuala): nimic de facut.
        if (($state['uncertain'] ?? false) !== true) {
            sendJsonResponse([
                "status" => "success",
                "actiune" => $actiune,
                "alreadyResolved" => true,
                "uncertain" => false,
                "trxId" => $attemptTrx
            ]);
        }
        $stmt = sqlsrv_query(
            $conn,
            "UPDATE tblPosBancaLog
                SET Status = 'CANCELED_MANUAL', Actiune = 'neaprobat', ResolvedAt = GETDATE(),
                    Mesaj = LEFT(ISNULL(Mesaj, '') + ' | Anulata de operator: nu a tiparit chitanta', 255)
              WHERE Id = ? AND Status IN ('UNKNOWN', 'TIMEOUT', 'ERROR')",
            [(int)$attempt['Id']]
        );
        if ($stmt === false) {
            sendJsonResponse(["status" => "error", "message" => "Eroare salvare decizie"], 500);
        }
        appEventLog('pos_banca_cancel', [
            'docId' => $docId,
            'trxId' => $attemptTrx,
            'suma' => (string)($attempt['Suma'] ?? ''),
            'actiune' => $actiune
        ]);
        sendJsonResponse([
            "status" => "success",
            "actiune" => $actiune,
            "uncertain" => false,
            "trxId" => $attemptTrx
        ]);
    }

    $stmt = sqlsrv_query(
        $conn,
        "UPDATE tblPosBancaLog SET Actiune = ?, ResolvedAt = GETDATE()
         WHERE DocID = ? AND Actiune IS NULL",
        [$actiune, $docId]
    );
    if ($stmt === false) {
        sendJsonResponse(["status" => "error", "message" => "Eroare salvare decizie"], 500);
    }
    sendJsonResponse(["status" => "success", "actiune" => $actiune]);
}

// Re-sincronizare / void: doar protocoalele care declara aceste capabilitati.
// Adaptorul si handler-ul fiecarui protocol sunt in registru
// (api/pos_banca_protocol.php); daca un driver nu le are, raspundem explicit,
// fara sa incercam vreun script sau sa citim .env.
if ($action === 'resync' || $action === 'void' || $action === 'void_doc') {
    $protocol = posBancaProtocol($conn);
    $caps = posBancaProtocolCaps($protocol);
    $unsupported = ($action === 'resync')
        ? empty($caps['journal'])
        : empty($caps['void']);
    $info = posBancaProtocolInfo($protocol);
    $adapter = trim((string)($info['adapter'] ?? ''));
    $handler = trim((string)($info['auxHandler'] ?? ''));
    if ($unsupported || $adapter === '' || $handler === '') {
        sendJsonResponse([
            "status" => "error",
            "code" => "unsupported_protocol",
            "message" => "Protocolul terminalului bancar nu suporta verificarea in jurnal sau anularea.",
            "protocol" => $protocol
        ], 400);
    }
    require_once __DIR__ . '/' . $adapter;
    if (!function_exists($handler)) {
        sendJsonResponse([
            "status" => "error",
            "code" => "unsupported_protocol",
            "message" => "Driverul terminalului bancar nu este instalat corect (adaptor lipsa).",
            "protocol" => $protocol
        ], 400);
    }
    call_user_func($handler, $conn, $action, $input);
}

// Starea pentru butonul "Verifica" de pe ecranul de plata: apare doar daca
// ultima incercare bancara a notei a ramas cu rezultat necunoscut.
if ($action === 'status') {
    ensurePosBancaLogTable($conn);
    $docId = (int)($input['docId'] ?? 0);
    $driverRaw = posBancaSetting($conn, 'CaleDriverPoSbanca', '');
    $batPath = posBancaResolveBatPath($driverRaw);
    $connection = null;
    if ($batPath !== '' && is_file($batPath)) {
        $connection = posBancaConnectionForProtocol($conn, $batPath);
    }
    $protocolMeta = posBancaProtocolMeta($conn);
    $state = posBancaDocState($conn, $docId);
    // Sumele aprobate pe card pentru nota (din audit-ul durabil). Clientul le
    // foloseste ca sa reinsamanteze ledger-ul dupa un reload/crash, iar
    // close_bill le compara cu platile trimise.
    $approved = posBancaApprovedTotal($conn, $docId);

    sendJsonResponse(array_merge([
        "status" => "success",
        "uncertain" => $state['uncertain'],
        "bankStatus" => $state['bankStatus'],
        "uid" => $state['uid'],
        "amount" => $state['amount'],
        "manualApproved" => $state['manualApproved'],
        "resyncApproved" => $state['resyncApproved'],
        // Comanda inca in curs (PENDING recent): clientul ascunde Verifica /
        // Manual / Anuleaza pana se termina asteptarea.
        "pending" => posBancaAttemptPendingFresh(
            (string)($state['attempt']['Status'] ?? ''),
            (string)($state['attempt']['CreatedAt'] ?? '')
        ),
        "approvedTotal" => $approved['total'],
        "approvedEntries" => $approved['entries'],
        "connection" => $connection
    ], $protocolMeta));
}

if ($action === 'resolve_manual') {
    // Solutionarea manuala a unei plati incerte (terminal blocat / nu raspunde).
    // Nu cere parola, dar APROBAREA cere AUTHCODE-ul de pe chitanta terminalului
    // (exact 6 caractere alfanumerice) - decizia este consemnata in audit cu
    // operatorul logat, suma, TRX_ID, codul, sursa si IP-ul cererii.
    ensurePosBancaLogTable($conn);
    $protocolMeta = posBancaProtocolMeta($conn);
    $protocolCaps = posBancaProtocolCaps($protocolMeta['protocol']);
    $docId = (int)($input['docId'] ?? 0);
    $verdict = strtolower(trim((string)($input['verdict'] ?? '')));
    if ($docId <= 0 || !in_array($verdict, ['aprobat', 'neaprobat'], true)) {
        sendJsonResponse(["status" => "error", "message" => "Parametri invalizi"], 400);
    }

    // Aprobarea manuala cere AUTHCODE-ul de pe chitanta terminalului: exact 6
    // caractere A-Z/0-9. Fara el, un operator ar putea declara o plata pe card
    // fara ca terminalul sa fi incasat nimic (frauda interna). "Neaprobat" nu
    // cere cod: o plata neincasata poate fi reincercata oricand, deci nu e
    // periculoasa. Verificarea se face si aici, nu doar in client.
    //
    // Codul se trateaza ca text si se normalizeaza la majuscule (chitanta poate
    // fi tiparita cu majuscule; tastarea pe POS este oricum uppercased).
    $authCode = strtoupper(preg_replace('/\s+/', '', (string)($input['cod'] ?? '')));
    $codIsAuth = (preg_match('/^[A-Z0-9]{6}$/', $authCode) === 1);
    if ($verdict === 'aprobat' && !$codIsAuth) {
        sendJsonResponse([
            "status" => "error",
            "code" => "auth_code_required",
            "message" => "Pentru aprobare este obligatoriu AUTHCODE-ul de pe chitanta terminalului "
                . "(exact 6 caractere, litere sau cifre)."
        ], 400);
    }

    $attempt = posBancaLatestAttempt($conn, $docId);
    if (!$attempt) {
        sendJsonResponse([
            "status" => "error",
            "message" => "Nu exista o plata bancara pentru aceasta nota."
        ], 400);
    }
    if (!posBancaAttemptIsUncertain((string)$attempt['Status'], (string)$attempt['Mesaj'])) {
        sendJsonResponse([
            "status" => "error",
            "message" => "Nu exista o plata bancara cu stare incerta pentru aceasta nota."
        ], 400);
    }

    // O verificare automata sau o decizie manuala anterioara poate sa fi
    // rezolvat deja incertitudinea. Nu permitem un "Neaprobat" manual cand
    // terminalul a spus deja ca plata este aprobata (ar permite inchiderea cu
    // numerar peste o incasare pe card).
    $aux = posBancaAuxAfter($conn, $docId, (int)$attempt['Id']);
    if ($aux) {
        $auxStatus = strtoupper(trim((string)$aux['Status']));
        $auxMsg = (string)$aux['Mesaj'];
        $auxAct = strtolower(trim((string)($aux['Actiune'] ?? '')));
        $resolved = false;
        $voidOk = ($auxStatus === 'VOID' && (
            stripos($auxMsg, 'anulata') !== false || $auxAct === 'anulat'
        ));
        if ($voidOk) {
            $resolved = true;
        }
        $voidFailed = ($auxStatus === 'VOID' && !$voidOk);
        if ($auxStatus === 'RESYNC' && (
            stripos($auxMsg, 'FOUND=0') !== false
            || stripos($auxMsg, 'nu apare') !== false
            || stripos($auxMsg, 'deja anulata') !== false
        )) {
            $resolved = true;
        }
        if ($auxStatus === 'MANUAL' && $auxAct === 'neaprobat') {
            $resolved = true;
        }
        if ($resolved) {
            sendJsonResponse([
                "status" => "error",
                "message" => "Plata nu mai este incerta (a fost deja rezolvata)."
            ], 400);
        }
        $resyncApproved = ($auxStatus === 'RESYNC' && stripos($auxMsg, 'APPROVED=1') !== false)
            || $voidFailed;
        $manualApproved = ($auxStatus === 'MANUAL' && $auxAct === 'aprobat');
        if ($verdict === 'neaprobat' && ($resyncApproved || $manualApproved)) {
            $msg = "Terminalul a confirmat deja plata ca aprobata. "
                . (!empty($protocolCaps['void'])
                    ? "Pastrati plata sau anulati-o (void)."
                    : "Pastrati plata (anularea se face de pe terminal).");
            sendJsonResponse([
                "status" => "error",
                "message" => $msg
            ], 400);
        }
    }

    $nrDoc = ($attempt['NrDoc'] !== null) ? (int)$attempt['NrDoc'] : posBancaNrDoc($conn, $docId);
    $suma = (float)($attempt['Suma'] ?? 0);
    $trxId = trim((string)($attempt['TrxId'] ?? ''));
    $nrOp = trim((string)($input['nrOp'] ?? ''));
    if ($nrOp === '') { $nrOp = posBancaBillNrOp($conn, $docId); }
    $nume = posBancaOperatorName($conn, $nrOp);
    if ($nume === '') { $nume = 'necunoscut'; }
    $sursa = preg_replace('/[^a-z_]/i', '', (string)($input['sursa'] ?? 'manual'));
    if ($sursa === '') { $sursa = 'manual'; }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    $mesaj = 'Verificare manuala: ' . ($verdict === 'aprobat' ? 'APROBAT' : 'NEAPROBAT')
        . ' de ' . $nume . ($nrOp !== '' ? ' (' . $nrOp . ')' : '');
    $detalii = json_encode([
        'sursa' => $sursa,
        'verdict' => $verdict,
        'authCode' => $authCode,
        'authType' => 'auth',
        'nrOp' => $nrOp,
        'nume' => $nume,
        'suma' => posBancaFormatAmount($suma),
        'trxId' => $trxId,
        'nrDoc' => $nrDoc,
        'ip' => $ip
    ], JSON_UNESCAPED_UNICODE);
    $logId = posBancaLogAttempt(
        $conn,
        $docId,
        $nrDoc,
        $suma,
        $trxId,
        'MANUAL',
        $mesaj,
        $verdict === 'aprobat',
        $verdict,
        (string)$detalii,
        // AUTHCODE-ul tastat manual merge in coloana AuthCode; Rrn rămâne
        // rezervat RRN-ului primit de la driver (reconciliere bancara).
        ['auth' => $authCode]
    );

    appEventLog('pos_banca_manual', [
        'doc' => $docId,
        'nota' => $nrDoc,
        'verdict' => $verdict,
        'auth' => $authCode,
        'suma' => posBancaFormatAmount($suma),
        'trx' => $trxId,
        'operator' => $nrOp,
        'nume' => $nume,
        'sursa' => $sursa,
        'ip' => $ip
    ]);

    $driverRaw = posBancaSetting($conn, 'CaleDriverPoSbanca', '');
    $batPath = posBancaResolveBatPath($driverRaw);
    $connection = null;
    if ($batPath !== '' && is_file($batPath)) {
        $connection = posBancaConnectionForProtocol($conn, $batPath);
    }

    sendJsonResponse(array_merge([
        "status" => "success",
        "verdict" => $verdict,
        "amount" => posBancaFormatAmount($suma),
        "trxId" => $trxId,
        "nrDoc" => $nrDoc,
        "operator" => ["nrOp" => $nrOp, "nume" => $nume],
        "logId" => $logId,
        "connection" => $connection
    ], $protocolMeta));
}

// Retiparirea chitantei de card. Protocoalele ECR folosite (SmartPay ECR Link
// v1.8.3, Viva ECR) NU au o comanda de reprint, deci tiparim un DUPLICAT pe
// imprimanta POS, din ultima tranzactie aprobata a notei (datele din audit).
// Nu este document fiscal si nu inlocuieste chitanta originala a terminalului:
// mesajul din client spune si cum se retipareste originalul din meniul lui.
if ($action === 'reprint') {
    require_once __DIR__ . '/print_common.php';
    ensurePosBancaLogTable($conn);
    // Functia este opționala: butonul si actiunea exista doar cand setarea din
    // Setari -> POS bancar este DA (implicit NU).
    if (posBancaSetting($conn, 'RetiparireChitantaPoSbanca', '0') !== '1') {
        sendJsonResponse([
            "status" => "error",
            "code" => "reprint_disabled",
            "message" => "Retiparirea chitantei terminalului bancar este dezactivata din Setari."
        ], 403);
    }
    $docId = (int)($input['docId'] ?? 0);
    $logId = (int)($input['logId'] ?? 0);
    if ($docId <= 0) {
        sendJsonResponse(["status" => "error", "message" => "Parametri invalizi"], 400);
    }
    $tx = posBancaLastApprovedTx($conn, $docId, $logId);
    if (!$tx) {
        sendJsonResponse([
            "status" => "error",
            "code" => "no_card_transaction",
            "message" => "Nu exista nicio plata pe card aprobata pentru aceasta nota."
        ], 404);
    }
    $jobId = enqueuePrintJob($conn, 'banca', $docId, null, [
        "title" => "DUPLICAT CHITANTA CARD",
        "headerLines" => posBancaHeaderLines($conn, ['H1', 'H2']),
        "dataOra" => printQueueDateToString($tx['createdAt']),
        "suma" => round((float)$tx['amount'], 2),
        "stan" => (string)$tx['stan'],
        "rrn" => (string)$tx['rrn'],
        "auth" => (string)$tx['auth'],
        "pan" => (string)$tx['pan'],
        "terminal" => (string)$tx['terminal'],
        "merchant" => (string)$tx['merchant'],
        "card" => (string)$tx['card'],
        "nrDoc" => $tx['nrDoc'],
        "trxId" => (string)$tx['trxId'],
        "note" => "Copie emisa de POS. Originalul este chitanta terminalului."
    ]);
    if ($jobId === false || $jobId === null) {
        sendJsonResponse([
            "status" => "error",
            "message" => "Chitanta nu a putut fi adaugata in coada de tiparire."
        ], 500);
    }
    wakePrintService();
    $auditId = posBancaLogAttempt(
        $conn,
        $docId,
        $tx['nrDoc'],
        $tx['amount'],
        $tx['trxId'],
        'REPRINT',
        'Duplicat chitanta card (job ' . $jobId . ')',
        false,
        'retiparit',
        null,
        [
            'rrn' => $tx['rrn'],
            'stan' => $tx['stan'],
            'auth' => $tx['auth'],
            'pan' => $tx['pan']
        ]
    );
    appEventLog('pos_banca_reprint', [
        'doc' => $docId,
        'trx' => $tx['trxId'],
        'suma' => posBancaFormatAmount($tx['amount']),
        'stan' => $tx['stan'],
        'job' => $jobId
    ]);
    sendJsonResponse([
        "status" => "success",
        "jobId" => $jobId,
        "auditId" => $auditId,
        "amount" => posBancaFormatAmount($tx['amount']),
        "stan" => (string)$tx['stan'],
        "rrn" => (string)$tx['rrn'],
        "auth" => (string)$tx['auth'],
        "pan" => (string)$tx['pan'],
        "createdAt" => printQueueDateToString($tx['createdAt']),
        "trxId" => (string)$tx['trxId'],
        "nrDoc" => $tx['nrDoc'],
        "message" => "Duplicatul chitantei a fost adaugat in coada de tiparire."
    ]);
}

if ($action !== 'pay') {
    sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}

$amount = (float)str_replace(',', '.', (string)($input['amount'] ?? 0));
$docId = (int)($input['docId'] ?? 0);

$posBanca = posBancaSetting($conn, 'PoSbanca', '0');
$protocolMeta = posBancaProtocolMeta($conn);

// Fara POS bancar configurat sau fara suma pe card: se emite bonul fiscal direct.
if ($posBanca !== '1') {
    sendJsonResponse(array_merge([
        "status" => "success",
        "enabled" => false,
        "emiteBon" => true,
        "posStatus" => "",
        "posMessage" => "",
        "trxId" => "",
        "logId" => null
    ], $protocolMeta));
}
if ($amount <= 0) {
    sendJsonResponse(array_merge([
        "status" => "success",
        "enabled" => false,
        "emiteBon" => true,
        "posStatus" => "",
        "posMessage" => "",
        "trxId" => "",
        "logId" => null
    ], $protocolMeta));
}

ensurePosBancaLogTable($conn);
$nrDoc = posBancaNrDoc($conn, $docId);

// Incercarile anterioare inca nerezolvate sunt reluate (utilizatorul a ales Retry).
@sqlsrv_query(
    $conn,
    "UPDATE tblPosBancaLog SET Actiune = 'reincercat', ResolvedAt = GETDATE()
     WHERE DocID = ? AND Actiune IS NULL",
    [$docId]
);

$driverRaw = posBancaSetting($conn, 'CaleDriverPoSbanca', '');
$batPath = posBancaResolveBatPath($driverRaw);

// Eroare de configurare: ca in VBA, se emite bonul fiscal, dar se raporteaza eroarea.
if ($batPath === '' || !is_file($batPath)) {
    $msg = "Nu gasesc executabilul terminalului bancar (POS): " . $driverRaw;
    $logId = posBancaLogAttempt($conn, $docId, $nrDoc, $amount, '', 'ERROR', $msg, true, 'config');
    sendJsonResponse(array_merge([
        "status" => "success",
        "enabled" => true,
        "emiteBon" => true,
        "configError" => true,
        "posStatus" => "ERROR",
        "posMessage" => $msg,
        "trxId" => "",
        "logId" => $logId,
        "connection" => null
    ], $protocolMeta));
}

$connection = posBancaConnectionForProtocol($conn, $batPath);
$resultPath = posBancaResultPathFor($batPath, posBancaSetting($conn, 'CaleFisierRaspunsPoSbanca', ''));
$txId = posBancaNewTrxId();
$amountStr = posBancaFormatAmount($amount);

// Consemnam incercarea INAINTE de a lansa driverul, ca `PENDING`: daca
// operatorul da Ctrl+F5 in timpul asteptarii, baza are deja urma comenzii, iar
// nota apare blocata (bani posibil debitat). Randul se actualizeaza cu
// rezultatul final dupa ce driverul raspunde.
$attemptLogId = posBancaLogAttempt(
    $conn, $docId, $nrDoc, $amount, $txId, 'PENDING',
    'Comanda trimisa catre terminal, se asteapta raspunsul.', false, null
);

// Sterge rezultatul anterior ca sa nu citim unul vechi.
@unlink($resultPath);

set_time_limit(0);
ignore_user_abort(true);

// Jurnal al driverului (stdout+stderr): esential pentru diagnostic cand
// driverul .bat nu scrie fisierul de rezultat (ex. Python fara o dependinta,
// cum ar fi pyserial pentru transportul serial).
$driverLog = __DIR__ . '/../logs/pos_banca_driver.log';
@mkdir(dirname($driverLog), 0777, true);

$cmd = '"' . $batPath . '" ' . $amountStr . ' ' . $txId;
$pythonDir = posBancaFindPythonDir();
$childEnv = ($pythonDir !== '') ? posBancaChildEnv($pythonDir) : null;
// Driverul primeste calea fisierului de rezultat si prin environment, ca un
// .bat care nu cunoaste setarea (CaleFisierRaspunsPoSbanca) sa scrie exact
// unde asteapta aplicatia. Driverele vechi ignora variabila.
if (is_array($childEnv)) { $childEnv['POS_BANCA_RESULT'] = $resultPath; }
$childCwd = dirname($batPath);
@file_put_contents(
    $driverLog,
    '[' . date('Y-m-d H:i:s') . '] cmd=' . $cmd
        . ' | pythonDir=' . ($pythonDir !== '' ? $pythonDir : '(none)')
        . ' | cwd=' . $childCwd . "\n"
);
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', $driverLog, 'a'],
    2 => ['file', $driverLog, 'a']
];
$proc = @proc_open($cmd, $descriptors, $pipes, $childCwd, $childEnv);
if (!is_resource($proc)) {
    $msg = "Nu pot lansa driverul terminalului bancar (POS): " . $batPath;
    $logId = posBancaLogFinish($conn, $attemptLogId, 'ERROR', $msg, true, 'config')
        ?: posBancaLogAttempt($conn, $docId, $nrDoc, $amount, $txId, 'ERROR', $msg, true, 'config');
    sendJsonResponse(array_merge([
        "status" => "success",
        "enabled" => true,
        "emiteBon" => true,
        "configError" => true,
        "posStatus" => "ERROR",
        "posMessage" => $msg,
        "trxId" => $txId,
        "amount" => $amountStr,
        "logId" => $logId,
        "connection" => $connection
    ], $protocolMeta));
}
if (isset($pipes[0]) && is_resource($pipes[0])) { fclose($pipes[0]); }

// Asteapta fisierul de rezultat (bat-ul poate lansa procesul asincron).
$timeoutMs = 190000;
$waited = 0;
while (!posBancaResultReady($resultPath)) {
    usleep(250000);
    $waited += 250;
    if ($waited >= $timeoutMs) { break; }
}

if (is_resource($proc)) { @proc_close($proc); }

if (!posBancaResultReady($resultPath)) {
    $msg = "Terminalul bancar (POS) nu a returnat rezultat in timp util.";
    $tail = posBancaDriverTail($driverLog, 4000);
    if ($tail !== '') { $msg .= " Detalii driver: " . $tail; }
    $logId = posBancaLogFinish($conn, $attemptLogId, 'TIMEOUT', $msg, false, null, $tail)
        ?: posBancaLogAttempt($conn, $docId, $nrDoc, $amount, $txId, 'TIMEOUT', $msg, false, null, $tail);
    sendJsonResponse(array_merge([
        "status" => "success",
        "enabled" => true,
        "emiteBon" => false,
        "posStatus" => "ERROR",
        "posMessage" => $msg,
        "trxId" => $txId,
        "amount" => $amountStr,
        "logId" => $logId,
        "connection" => $connection
    ], $protocolMeta));
}

usleep(200000);
$content = @file_get_contents($resultPath);
$fields = posBancaParseResult($content);
$driverTail = posBancaDriverTail($driverLog, 4000);

$posStatus = $fields['STATUS'] ?? '';
$posMessage = $fields['MESAJ'] ?? '';
// Mesajul afisat in POS trece prin posBancaUserMessage(): textele cunoscute ale
// driverului sunt inlocuite cu un mesaj clar, care se traduce normal in engleza.
// Normalizarea SmartPay se aplica doar pentru protocolul SmartPayECR.
$userMessage = posBancaUserMessage($posMessage, $protocolMeta['protocol']);
// Driverul .bat scrie "Python sau script lipsa" si cand de fapt scriptul a
// crapat fara sa scrie rezultatul (ex. o exceptie Python). Completam mesajul
// brut (care merge in audit) cu iesirea reala a driverului, ca diagnosticul
// sa fie util.
if (stripos($posMessage, 'Python sau script lipsa') !== false) {
    $tail = posBancaDriverTail($driverLog, 400);
    if ($tail !== '') { $posMessage .= ' Detalii driver: ' . $tail; }
}
$resultTrxId = $fields['TRX_ID'] ?? '';
if ($resultTrxId !== '') { $txId = $resultTrxId; }
// Identificatorii tranzactiei, salvati separat de Detalii (text liber):
// RRN = cheia de reconciliere cu extrasul bancii, STAN = bonul terminalului,
// AUTH = codul de autorizare, PAN = cardul mascat (ex. **3337).
$resultMeta = [
    'rrn'  => (string)($fields['RRN'] ?? ''),
    'stan' => (string)($fields['STAN'] ?? ''),
    'auth' => (string)($fields['AUTH'] ?? ''),
    'pan'  => (string)($fields['PAN'] ?? ''),
    'raw'  => (string)$content
];
$emiteBon = (strtoupper($fields['EMITE_BON'] ?? '') === 'DA');
// Status UNKNOWN = comanda a fost acceptata de terminal, dar raspunsul lipseste.
// Nu se reincerca automat: operatorul trebuie sa ruleze resync/void.
if (strtoupper($posStatus) === 'UNKNOWN') { $emiteBon = false; }
// Terminalul nu a putut fi contactat (comanda nu a plecat): cardul nu a fost
// debitat si nu e nimic de verificat in jurnal. Clientul afiseaza „Terminalul
// nu raspunde” cu Reincearca / Alta metoda, fara dialogul de plata neclarificata.
$noAnswer = !$emiteBon && posBancaAttemptIsNoAnswer($posStatus, $posMessage);

$logId = posBancaLogFinish(
    $conn,
    $attemptLogId,
    $posStatus !== '' ? $posStatus : ($emiteBon ? 'OK' : 'ERROR'),
    $posMessage,
    $emiteBon,
    $emiteBon ? 'aprobat' : null,
    $driverTail,
    $resultMeta
) ?: posBancaLogAttempt(
    $conn,
    $docId,
    $nrDoc,
    $amount,
    $txId,
    $posStatus !== '' ? $posStatus : ($emiteBon ? 'OK' : 'ERROR'),
    $posMessage,
    $emiteBon,
    $emiteBon ? 'aprobat' : null,
    $driverTail,
    $resultMeta
);

sendJsonResponse(array_merge([
    "status" => "success",
    "enabled" => true,
    "emiteBon" => $emiteBon,
    "posStatus" => $posStatus,
    "posMessage" => $userMessage,
    "noAnswer" => $noAnswer,
    "trxId" => $txId,
    "amount" => $amountStr,
    "logId" => $logId,
    "connection" => $connection
], $protocolMeta));
