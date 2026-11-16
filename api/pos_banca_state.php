<?php
/**
 * Starea bancara a unei note (payments POS bancar), fara efecte secundare.
 *
 * Sursa de adevar este tblPosBancaLog: o nota are o plata "incerta" cand ultima
 * incercare a terminalului a ramas fara raspuns (UNKNOWN / TIMEOUT / eroare de
 * lansare) si nu a fost rezolvata ulterior printr-o verificare in jurnal
 * (RESYNC), printr-o anulare (VOID) sau printr-o decizie manuala (MANUAL).
 *
 * Fisierul este partajat de:
 *   - api/pos_banca.php   (actiunea "status" si restul fluxului bancar),
 *   - api/order_action.php (blocarea notei cat timp plata nu e clarificata),
 *   - api/get_order.php   (lock-ul afisat in client la deschiderea notei).
 *
 * Nu contine cod specific unui driver (SmartPay): acela sta in
 * api/pos_banca_protocol.php si api/pos_banca_smartpay.php.
 */

/**
 * Creeaza tabela de audit daca nu exista.
 *
 * @param resource $conn
 */
function ensurePosBancaLogTable($conn) {
    $sql = "IF OBJECT_ID('dbo.tblPosBancaLog','U') IS NULL
            CREATE TABLE dbo.tblPosBancaLog (
                Id INT IDENTITY(1,1) PRIMARY KEY,
                DocID INT NULL,
                NrDoc INT NULL,
                Suma DECIMAL(18,2) NULL,
                TrxId NVARCHAR(50) NULL,
                Status NVARCHAR(50) NULL,
                Mesaj NVARCHAR(255) NULL,
                EmiteBon BIT NULL,
                Actiune NVARCHAR(20) NULL,
                Detalii NVARCHAR(MAX) NULL,
                Rrn NVARCHAR(32) NULL,
                Stan NVARCHAR(16) NULL,
                AuthCode NVARCHAR(32) NULL,
                CardPan NVARCHAR(24) NULL,
                Raw NVARCHAR(MAX) NULL,
                CreatedAt DATETIME NOT NULL DEFAULT (GETDATE()),
                ResolvedAt DATETIME NULL
            )";
    @sqlsrv_query($conn, $sql, null, ['QueryTimeout' => 15]);
    // Instalatiile existente primesc coloana Detalii (diagnostic driver).
    $sql = "IF COL_LENGTH('dbo.tblPosBancaLog','Detalii') IS NULL
            ALTER TABLE dbo.tblPosBancaLog ADD Detalii NVARCHAR(MAX) NULL";
    @sqlsrv_query($conn, $sql, null, ['QueryTimeout' => 15]);
    // Identificatorii tranzactiei, separat de textul liber din Detalii: RRN-ul
    // este cheia de reconciliere cu extrasul bancii (exact 12 cifre, pastrat ca
    // TEXT ca sa nu piarda zerourile din fata), STAN-ul e numarul bonului de la
    // terminal, AuthCode e codul de autorizare (primit de la driver sau tastat
    // manual la solutionarea unei plati incerte - 6 caractere alfanumerice),
    // CardPan e PAN-ul mascat (ex. **3337) folosit la reclamatiile de dubla
    // debitare. Raw pastreaza raspunsul complet al driverului, pentru
    // investigarea de protocol (nu pentru rapoarte).
    foreach ([
        'Rrn' => 'NVARCHAR(32)',
        'Stan' => 'NVARCHAR(16)',
        'AuthCode' => 'NVARCHAR(32)',
        'CardPan' => 'NVARCHAR(24)',
        'Raw' => 'NVARCHAR(MAX)',
    ] as $col => $type) {
        $sql = "IF COL_LENGTH('dbo.tblPosBancaLog','$col') IS NULL
                ALTER TABLE dbo.tblPosBancaLog ADD $col $type NULL";
        @sqlsrv_query($conn, $sql, null, ['QueryTimeout' => 15]);
    }
    // RRN-ul se cauta la reconcilierea cu extrasul de card (nu e unic intre
    // acquireri/ani, deci index neunic).
    $sql = "IF NOT EXISTS (SELECT 1 FROM sys.indexes
                          WHERE name = 'IX_tblPosBancaLog_Rrn'
                            AND object_id = OBJECT_ID('dbo.tblPosBancaLog'))
            CREATE INDEX IX_tblPosBancaLog_Rrn ON dbo.tblPosBancaLog (Rrn)";
    @sqlsrv_query($conn, $sql, null, ['QueryTimeout' => 15]);
    // AUTHCODE-ul (inclusiv cel tastat manual) se cauta la reconciliere;
    // nici el nu este unic intre tranzactii/acquireri.
    $sql = "IF NOT EXISTS (SELECT 1 FROM sys.indexes
                          WHERE name = 'IX_tblPosBancaLog_AuthCode'
                            AND object_id = OBJECT_ID('dbo.tblPosBancaLog'))
            CREATE INDEX IX_tblPosBancaLog_AuthCode ON dbo.tblPosBancaLog (AuthCode)";
    @sqlsrv_query($conn, $sql, null, ['QueryTimeout' => 15]);
    // Interogarile de stare filtreaza dupa DocID (lock-ul notei ruleaza la
    // fiecare actiune pe nota), deci merita un index.
    $sql = "IF NOT EXISTS (SELECT 1 FROM sys.indexes
                          WHERE name = 'IX_tblPosBancaLog_DocID'
                            AND object_id = OBJECT_ID('dbo.tblPosBancaLog'))
            CREATE INDEX IX_tblPosBancaLog_DocID ON dbo.tblPosBancaLog (DocID, Id)";
    @sqlsrv_query($conn, $sql, null, ['QueryTimeout' => 15]);
}

/**
 * Persista o incercare de tranzactie. Returneaza Id-ul randului inserat.
 *
 * @param resource $conn
 * @param int $docId
 * @param int $nrDoc
 * @param float $suma
 * @param string $trxId
 * @param string $status
 * @param string $mesaj
 * @param bool $emiteBon
 * @param string $actiune
 * @param string|null $detalii Diagnostic brut (coada logs/pos_banca_driver.log)
 * @param array{rrn?:string,stan?:string,auth?:string,pan?:string,raw?:string} $extra
 *        Identificatorii tranzactiei (RRN/STAN/AUTH/PAN) si raspunsul brut al
 *        driverului, pastrate separat de textul liber din Detalii.
 */
function posBancaLogAttempt($conn, $docId, $nrDoc, $suma, $trxId, $status, $mesaj, $emiteBon, $actiune, $detalii = null, array $extra = []) {
    $clean = static function ($value, $max) {
        $value = preg_replace('/\s+/', '', (string)$value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    };
    $raw = (string)($extra['raw'] ?? '');
    $sql = "INSERT INTO tblPosBancaLog
                (DocID, NrDoc, Suma, TrxId, Status, Mesaj, EmiteBon, Actiune, Detalii,
                 Rrn, Stan, AuthCode, CardPan, Raw, CreatedAt)
            OUTPUT INSERTED.Id
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())";
    $stmt = sqlsrv_query($conn, $sql, [
        $docId !== null ? (int)$docId : null,
        $nrDoc !== null ? (int)$nrDoc : null,
        (float)$suma,
        $trxId,
        $status,
        mb_substr((string)$mesaj, 0, 255),
        $emiteBon ? 1 : 0,
        $actiune,
        ($detalii !== null && $detalii !== '') ? mb_substr((string)$detalii, 0, 4000) : null,
        $clean($extra['rrn'] ?? '', 32),
        $clean($extra['stan'] ?? '', 16),
        $clean($extra['auth'] ?? '', 32),
        $clean($extra['pan'] ?? '', 24),
        $raw !== '' ? mb_substr($raw, 0, 60000) : null
    ]);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row) { return (int)$row['Id']; }
    }
    return null;
}

/**
 * Actualizeaza randul unei incercari de plata deja inregistrate (scris ca
 * `PENDING` inainte de lansarea driverului). Pastreaza `CreatedAt` (momentul
 * trimiterii) si `TrxId`-ul, ca auditul sa aiba un singur rand per incercare.
 *
 * De ce exista: randul de audit se scria DOAR dupa ce driverul termina (pana la
 * 190s). Un Ctrl+F5 in timpul asteptarii nu gasea nimic in baza, deci nota nu
 * aparea blocata (fara banner, fara Verifica) - desi cardul putea fi debitat.
 *
 * @param resource $conn
 * @param int|null $logId
 * @param array<string,mixed> $extra rrn, stan, auth, pan, raw
 * @return int|null
 */
function posBancaLogFinish($conn, $logId, $status, $mesaj, $emiteBon, $actiune, $detalii = null, array $extra = []) {
    if (!$logId) { return null; }
    $clean = static function ($value, $max) {
        $value = preg_replace('/\s+/', '', (string)$value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    };
    $raw = (string)($extra['raw'] ?? '');
    $sql = "UPDATE tblPosBancaLog
               SET Status = ?, Mesaj = ?, EmiteBon = ?, Actiune = ?, Detalii = ?,
                   Rrn = ?, Stan = ?, AuthCode = ?, CardPan = ?, Raw = ?
             WHERE Id = ?";
    $stmt = @sqlsrv_query($conn, $sql, [
        $status,
        mb_substr((string)$mesaj, 0, 255),
        $emiteBon ? 1 : 0,
        $actiune,
        ($detalii !== null && $detalii !== '') ? mb_substr((string)$detalii, 0, 4000) : null,
        $clean($extra['rrn'] ?? '', 32),
        $clean($extra['stan'] ?? '', 16),
        $clean($extra['auth'] ?? '', 32),
        $clean($extra['pan'] ?? '', 24),
        $raw !== '' ? mb_substr($raw, 0, 60000) : null,
        (int)$logId
    ]);
    return $stmt ? (int)$logId : null;
}

/**
 * Consemneaza in audit inchiderea notei (si daca a plecat bonul fiscal).
 *
 * Randurile `UNKNOWN`/`RESYNC`/`MANUAL` spun doar ce a raspuns terminalul;
 * fara acest eveniment, din `tblPosBancaLog` nu se poate afla daca nota s-a
 * inchis si daca casa de marcat a emis bonul. Se scrie doar daca nota are deja
 * istoric bancar (altfel ar polua auditul notelor platite cu numerar).
 *
 * @param resource $conn
 * @param int $docId
 * @param int|null $nrDoc
 * @param float $total Valoarea notei inchise.
 * @param array<string,mixed> $info fiscalNr, fiscalZ, print, plati, zeroTotal, skipFiscal, sters
 * @return int|null Id-ul randului scris (null daca nota nu are istoric bancar)
 */
function posBancaLogClosed($conn, $docId, $nrDoc, $total, array $info = []) {
    if ((int)$docId <= 0 || !posBancaLogTableExists($conn)) { return null; }
    // Doar notele care au trecut prin terminalul bancar.
    $stmt = sqlsrv_query($conn, "SELECT TOP 1 Id, TrxId FROM tblPosBancaLog WHERE DocID = ? ORDER BY Id DESC", [(int)$docId]);
    $last = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    if (!$last) { return null; }

    $sters = !empty($info['sters']);
    $faraBon = $sters || !empty($info['zeroTotal']) || !empty($info['skipFiscal']);
    $fiscalNr = $info['fiscalNr'] ?? null;
    $fiscalZ = $info['fiscalZ'] ?? null;

    if ($sters) {
        $mesaj = 'Nota goala stearsa (fara bon fiscal).';
    } elseif ($faraBon) {
        $mesaj = 'Nota inchisa fara bon fiscal (valoare 0).';
    } elseif ($fiscalNr !== null && $fiscalNr !== '') {
        $mesaj = 'Nota inchisa. Bon fiscal NR ' . $fiscalNr
            . ($fiscalZ !== null && $fiscalZ !== '' ? ' / Z ' . $fiscalZ : '') . '.';
    } else {
        $mesaj = 'Nota inchisa. Bon fiscal emis (in coada de tiparire).';
    }

    $detalii = json_encode([
        'sters' => $sters,
        'zeroTotal' => !empty($info['zeroTotal']),
        'skipFiscal' => !empty($info['skipFiscal']),
        'plati' => $info['plati'] ?? null,
        'fiscal' => [
            'nr' => $fiscalNr,
            'z' => $fiscalZ,
            'bf' => $info['fiscalBf'] ?? null,
        ],
        'print' => $info['print'] ?? null,
    ], JSON_UNESCAPED_UNICODE);

    return posBancaLogAttempt(
        $conn, $docId, $nrDoc, (float)$total, trim((string)($last['TrxId'] ?? '')),
        'CLOSED', $mesaj, !$faraBon, 'inchis', $detalii
    );
}

/**
 * Tabela de audit exista? Verificare ieftina (cache pe cerere), ca garda de
 * blocare sa nu ruleze DDL la fiecare actiune pe nota.
 *
 * @param resource $conn
 * @return bool
 */
function posBancaLogTableExists($conn) {
    static $exists = null;
    if ($exists !== null) { return $exists; }
    $stmt = @sqlsrv_query($conn, "SELECT OBJECT_ID('dbo.tblPosBancaLog','U') AS OID", null, ['QueryTimeout' => 5]);
    $row = $stmt ? @sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    $exists = ($row && $row['OID'] !== null);
    return $exists;
}

/**
 * Exista vreo inregistrare bancara pentru nota? (cazul obisnuit: nu, deci
 * garda nu mai face restul interogarilor).
 *
 * @param resource $conn
 * @param int $docId
 * @return bool
 */
function posBancaDocHasLog($conn, $docId) {
    if ((int)$docId <= 0 || !posBancaLogTableExists($conn)) { return false; }
    $stmt = @sqlsrv_query(
        $conn,
        "SELECT TOP 1 Id FROM tblPosBancaLog WHERE DocID = ?",
        [(int)$docId]
    );
    $row = $stmt ? @sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    return (bool)$row;
}

/**
 * Ultima incercare efectiva a notei (fara rezolvarile auxiliare).
 *
 * @param resource $conn
 * @param int $docId
 * @return array<string,mixed>|null
 */
function posBancaLatestAttempt($conn, $docId) {
    if ((int)$docId <= 0 || !posBancaLogTableExists($conn)) { return null; }
    // Atentie: CANCELED_MANUAL NU este exclus aici (desi e tot o rezolvare).
    // Randul incercarii abandonate de operator trebuie sa rămâna "ultima
    // incercare", ca posBancaAttemptIsUncertain() sa raspunda false pentru el
    // si nota sa fie deblocata (altfel ar rămâne blocata dupa "Nu a tipărit").
    // MISMATCH este doar o constatare de reconciliere (close_bill), nu o
    // incercare de plata: nu are voie sa devina "ultima incercare".
    // REPRINT este o retiparire a unei tranzactii deja aprobate (duplicat pe
    // imprimanta POS): nici ea nu este o incercare noua.
    $stmt = sqlsrv_query(
        $conn,
        "SELECT TOP 1 Id, NrDoc, Suma, TrxId, Status, Mesaj, Actiune, CreatedAt
         FROM tblPosBancaLog
         WHERE DocID = ? AND Status NOT IN ('RESYNC', 'VOID', 'MANUAL', 'CLOSED', 'MISMATCH', 'REPRINT')
         ORDER BY Id DESC",
        [(int)$docId]
    );
    return $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
}

/**
 * Rezolvarea auxiliara cea mai recenta (resync / void / decizie manuala).
 *
 * @param resource $conn
 * @param int $docId
 * @param int $attemptId
 * @return array<string,mixed>|null
 */
function posBancaAuxAfter($conn, $docId, $attemptId) {
    $stmt = sqlsrv_query(
        $conn,
        "SELECT TOP 1 Id, Status, Mesaj, Actiune, Suma, TrxId, Rrn, Stan, AuthCode, CardPan
         FROM tblPosBancaLog
         WHERE DocID = ? AND Id > ? AND Status IN ('RESYNC', 'VOID', 'MANUAL')
         ORDER BY Id DESC",
        [(int)$docId, (int)$attemptId]
    );
    return $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
}

/**
 * Decizia manuala existenta dupa o incercare (ca resync-ul tardiv sa nu o
 * suprascrie).
 *
 * @param resource $conn
 * @param int $docId
 * @param int $attemptId
 * @return array<string,mixed>|null
 */
function posBancaManualAfter($conn, $docId, $attemptId) {
    $stmt = sqlsrv_query(
        $conn,
        "SELECT TOP 1 Id, Status, Mesaj, Actiune, Suma, TrxId
         FROM tblPosBancaLog
         WHERE DocID = ? AND Id > ? AND Status = 'MANUAL'
         ORDER BY Id DESC",
        [(int)$docId, (int)$attemptId]
    );
    return $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
}

/**
 * O incercare este incerta daca terminalul a acceptat comanda fara sa confirme
 * rezultatul (UNKNOWN / TIMEOUT sau eroarea veche de lansare a driverului).
 *
 * @param string $status
 * @param string $mesaj
 */
function posBancaAttemptIsUncertain($status, $mesaj) {
    $status = strtoupper(trim((string)$status));
    $mesaj = (string)$mesaj;
    // PENDING = comanda a plecat spre terminal si raspunsul nu a venit inca.
    // Este cea mai puternica forma de incertitudine: cardul POATE fi debitat,
    // deci nota trebuie sa fie blocata (si dupa un reload in timpul asteptarii).
    return $status === 'PENDING'
        || $status === 'UNKNOWN'
        || $status === 'TIMEOUT'
        || ($status === 'ERROR' && (
            stripos($mesaj, 'Eroare la lansarea PoS') !== false
            || stripos($mesaj, 'Stare necunoscuta') !== false
            || stripos($mesaj, 'nu a returnat rezultat') !== false
        ));
}

/**
 * Terminalul nu a putut fi contactat: driverul a esuat INAINTE sa trimita
 * comanda (conexiune refuzata, port indisponibil, login fara raspuns), deci
 * cardul NU a putut fi debitat.
 *
 * Nu este o stare incerta (nu e nimic de verificat in jurnalul terminalului):
 * nota rămâne deblocata, iar reincercarea este sigura. Clientul afiseaza
 * „Terminalul nu raspunde”, nu „Plata neclarificata”.
 *
 * @param string $status
 * @param string $mesaj
 */
function posBancaAttemptIsNoAnswer($status, $mesaj) {
    $status = strtoupper(trim((string)$status));
    if ($status !== 'ERROR') { return false; }
    $mesaj = (string)$mesaj;
    if ($mesaj === '') { return false; }
    // Textele canonice ale driverului (vechi si nou) pentru eroare de legatura.
    return preg_match(
        '/nu s-a putut conecta|nu a putut fi contactat|nu raspunde|nu răspunde'
        . '|eroare de comunicatie|comunicatie cu terminalul|nu a raspuns/i',
        $mesaj
    ) === 1;
}

/**
 * Starea bancara completa a notei (fara protocol / conexiune). Aceeasi regula
 * este folosita de actiunea "status", de lock-ul notei si de get_order.php, ca
 * sa nu existe doua definitii ale "incertitudinii".
 *
 * @param resource $conn
 * @param int $docId
 * @return array{uncertain:bool,bankStatus:string,uid:string,amount:string,manualApproved:bool,resyncApproved:bool,attempt:array<string,mixed>|null}
 */
function posBancaDocState($conn, $docId) {
    $state = [
        "uncertain" => false,
        "bankStatus" => "",
        "uid" => "",
        "amount" => "",
        "manualApproved" => false,
        "resyncApproved" => false,
        "attempt" => null,
    ];
    if ((int)$docId <= 0) { return $state; }

    $row = posBancaLatestAttempt($conn, $docId);
    if (!$row) { return $state; }

    $state['attempt'] = $row;
    $state['bankStatus'] = strtoupper(trim((string)$row['Status']));
    $state['uid'] = trim((string)$row['TrxId']);
    $state['amount'] = trim((string)($row['Suma'] ?? ''));
    $state['uncertain'] = posBancaAttemptIsUncertain($state['bankStatus'], (string)$row['Mesaj']);

    if (!$state['uncertain']) { return $state; }

    // Rezolvarea auxiliara cea mai recenta de dupa incercarea incerta.
    // MANUAL este tot o rezolvare, dar NU este o incercare de plata: de aceea
    // nu intra in ultima incercare (posBancaLatestAttempt).
    $aux = posBancaAuxAfter($conn, $docId, (int)$row['Id']);
    if (!$aux) { return $state; }

    $auxStatus = strtoupper(trim((string)$aux['Status']));
    $auxMsg = (string)$aux['Mesaj'];
    $auxAct = strtolower(trim((string)($aux['Actiune'] ?? '')));

    if ($auxStatus === 'VOID' && (
        stripos($auxMsg, 'anulata') !== false
        || $auxAct === 'anulat'
    )) {
        $state['uncertain'] = false;
    } elseif ($auxStatus === 'VOID') {
        // Void-ul a esuat: tranzactia a fost gasita aprobata si cardul ramane
        // incarcat. Numerarul ramane blocat; doar Pastreaza sau Manual aprobat
        // sunt permise.
        $state['resyncApproved'] = true;
    } elseif ($auxStatus === 'RESYNC' && (
        stripos($auxMsg, 'nu apare') !== false
        || stripos($auxMsg, 'deja anulata') !== false
    )) {
        $state['uncertain'] = false;
    } elseif ($auxStatus === 'RESYNC') {
        // Terminalul a confirmat deja tranzactia ca aprobata: plata nu mai
        // poate fi inchisa cu numerar, doar pastrata (card) sau anulata cu void.
        if (stripos($auxMsg, 'APPROVED=1') !== false) {
            $state['resyncApproved'] = true;
            // Suma relevanta este cea din jurnalul terminalului, nu cea ceruta
            // (poate fi aprobata doar partial). Clientul o foloseste la
            // preluarea automata de pe ecranul de plata.
            $auxAmount = trim((string)($aux['Suma'] ?? ''));
            if ($auxAmount !== '' && (float)$auxAmount > 0) {
                $state['amount'] = $auxAmount;
            }
        }
    } elseif ($auxStatus === 'MANUAL') {
        if ($auxAct === 'neaprobat') {
            $state['uncertain'] = false;
        } elseif ($auxAct === 'aprobat') {
            // Decizia manuala a spus ca plata a fost aprobata: numerarul ramane
            // blocat pana la inchiderea cu cardul.
            $state['uncertain'] = true;
            $state['manualApproved'] = true;
            $manualAmount = trim((string)($aux['Suma'] ?? ''));
            if ($manualAmount !== '') { $state['amount'] = $manualAmount; }
        }
    }

    return $state;
}

/**
 * Suma aprobata pe card pentru o nota, derivata din tblPosBancaLog (sursa
 * durabila; ledger-ul din browser e doar un cache al ei).
 *
 * O tranzactie (TrxId) este aprobata cand are:
 *   - o incercare cu EmiteBon = 1 (aprobare directa a terminalului), fara a fi
 *     o eroare de configurare (`Actiune = 'config'` - bon emis fara terminal);
 *   - sau un rand RESYNC cu APPROVED=1 (jurnalul terminalului a confirmat);
 *   - sau o decizie MANUAL aprobata.
 * O anulare reusita (VOID cu Actiune = 'anulat') scoate tranzactia din suma.
 *
 * Gruparea pe TrxId este obligatorie: randul UNKNOWN si cel RESYNC al aceleasi
 * incercari poarta aceeasi Suma, iar un SUM naiv ar dubla-o.
 *
 * `lastId` este randul APROBARII (nu un UNKNOWN/RESYNC/REPRINT ulterior), iar
 * `rrn`/`auth`/`pan`/`createdAt`/`nrDoc` sunt luate din grupul aceleiasi
 * tranzactii (pot veni si din randul incercarii, ex. UNKNOWN cu raspuns brut).
 *
 * @param resource $conn
 * @param int $docId
 * @return array{total:float, entries:array<int,array{trxId:string,amount:float,source:string,stan:string,logId:int,nrDoc:int|null,createdAt:string,rrn:string,auth:string,pan:string}>}
 */
function posBancaApprovedTotal($conn, $docId) {
    $out = ["total" => 0.0, "entries" => []];
    if ((int)$docId <= 0 || !posBancaLogTableExists($conn)) { return $out; }
    // Conditia de "aprobare" (aceeasi folosita la alegerea sumei). `lastId`
    // trebuie sa fie randul aprobarii: altfel un REPRINT sau un RESYNC scris
    // dupa aprobare ar deveni "ultima tranzactie" pentru retiparire.
    //
    // `CLOSED` are si el EmiteBon = 1 (spune daca bonul fiscal a plecat), dar
    // Suma lui este totalul notei, nu suma de pe card: daca ar intra in grup,
    // approvedTotal ar deveni totalul notei dupa inchidere (si retiparirea ar
    // tipari suma gresita). De aceea aprobarea directa este restransa la
    // statusurile de plata aprobata, nu doar la EmiteBon = 1.
    $directCond = "(EmiteBon = 1
                    AND ISNULL(Actiune, '') <> 'config'
                    AND Status NOT IN ('MANUAL','CLOSED','REPRINT','MISMATCH','VOID','RESYNC',
                                       'CANCELED_MANUAL','UNKNOWN','TIMEOUT','ERROR'))";
    $okCond = "($directCond
                OR (Status = 'RESYNC' AND Mesaj LIKE '%APPROVED=1%')
                OR (Status = 'MANUAL' AND Actiune = 'aprobat'))";
    $sql = ";WITH ev AS (
                SELECT TrxId,
                       MAX(CASE WHEN $directCond THEN Suma END) AS okSum,
                       MAX(CASE WHEN Status = 'RESYNC' AND Mesaj LIKE '%APPROVED=1%'
                                THEN Suma END) AS resyncSum,
                       MAX(CASE WHEN Status = 'MANUAL' AND Actiune = 'aprobat'
                                THEN Suma END) AS manualSum,
                       MAX(CASE WHEN Status = 'VOID' AND Actiune = 'anulat'
                                THEN 1 ELSE 0 END) AS voided,
                       MAX(Stan) AS stan,
                       MAX(Rrn) AS rrn,
                       MAX(AuthCode) AS authCode,
                       MAX(CardPan) AS cardPan,
                       MAX(CASE WHEN $okCond THEN Id END) AS lastId,
                       MAX(CASE WHEN $okCond THEN NrDoc END) AS nrDoc,
                       -- Momentul primei inregistrari a tranzactiei (incercarea),
                       -- nu al deciziei manuale / al retiparirii ulterioare.
                       MIN(CreatedAt) AS createdAt
                FROM tblPosBancaLog
                WHERE DocID = ?
                GROUP BY TrxId
            )
            SELECT TrxId, okSum, resyncSum, manualSum, voided, stan, rrn, authCode, cardPan,
                   lastId, nrDoc, createdAt
            FROM ev
            WHERE TrxId IS NOT NULL AND LTRIM(RTRIM(TrxId)) <> ''";
    $stmt = @sqlsrv_query($conn, $sql, [(int)$docId], ['QueryTimeout' => 10]);
    if (!$stmt) { return $out; }
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        if ((int)($row['voided'] ?? 0) === 1) { continue; }
        $amount = 0.0;
        $source = '';
        if ($row['okSum'] !== null) {
            $amount = (float)$row['okSum'];
            $source = 'terminal';
        } elseif ($row['resyncSum'] !== null) {
            $amount = (float)$row['resyncSum'];
            $source = 'journal';
        } elseif ($row['manualSum'] !== null) {
            $amount = (float)$row['manualSum'];
            $source = 'manual';
        }
        if ($amount <= 0) { continue; }
        $out['entries'][] = [
            'trxId' => (string)$row['TrxId'],
            'amount' => round($amount, 2),
            'source' => $source,
            'stan' => trim((string)($row['stan'] ?? '')),
            'logId' => (int)($row['lastId'] ?? 0),
            'nrDoc' => ($row['nrDoc'] !== null) ? (int)$row['nrDoc'] : null,
            'createdAt' => (string)($row['createdAt'] ?? ''),
            'rrn' => trim((string)($row['rrn'] ?? '')),
            'auth' => trim((string)($row['authCode'] ?? '')),
            'pan' => trim((string)($row['cardPan'] ?? '')),
        ];
        $out['total'] += $amount;
    }
    $out['total'] = round($out['total'], 2);
    return $out;
}

/**
 * Ultima tranzactie aprobata a notei (pentru retiparirea chitantei de card).
 *
 * Se bazeaza pe `posBancaApprovedTotal()`: tranzactiile anulate prin VOID sunt
 * excluse, iar `logId` este randul aprobarii. `logId` primit de la client este
 * onorat doar daca apartine unei tranzactii aprobate (altfel se retipareste
 * ultima aprobare, ca un id vechi sa nu scoata la imprimanta altceva).
 *
 * @param resource $conn
 * @param int $docId
 * @param int $logId
 * @return array<string,mixed>|null
 */
function posBancaLastApprovedTx($conn, $docId, $logId = 0) {
    if ((int)$docId <= 0) { return null; }
    $approved = posBancaApprovedTotal($conn, $docId);
    $entries = $approved['entries'];
    if (empty($entries)) { return null; }
    // Cea mai recenta aprobare: logId creste cu fiecare rand inserat.
    usort($entries, static function ($a, $b) {
        return ((int)($b['logId'] ?? 0)) <=> ((int)($a['logId'] ?? 0));
    });
    $entry = null;
    if ((int)$logId > 0) {
        foreach ($entries as $e) {
            if ((int)($e['logId'] ?? 0) === (int)$logId) { $entry = $e; break; }
        }
        if ($entry === null) {
            // Clientul poate trimite id-ul altei incercari (UNKNOWN) sau al unui
            // REPRINT: il traducem in TrxId si cautam grupul aprobat.
            $stmt = sqlsrv_query(
                $conn,
                "SELECT TOP 1 TrxId FROM tblPosBancaLog WHERE Id = ? AND DocID = ?",
                [(int)$logId, (int)$docId]
            );
            $r = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
            $trx = $r ? trim((string)($r['TrxId'] ?? '')) : '';
            if ($trx !== '') {
                foreach ($entries as $e) {
                    if (strcasecmp((string)$e['trxId'], $trx) === 0) { $entry = $e; break; }
                }
            }
        }
    }
    if ($entry === null) { $entry = $entries[0]; }

    $out = [
        'trxId' => (string)$entry['trxId'],
        'amount' => (float)$entry['amount'],
        'source' => (string)$entry['source'],
        'stan' => (string)($entry['stan'] ?? ''),
        'rrn' => (string)($entry['rrn'] ?? ''),
        'auth' => (string)($entry['auth'] ?? ''),
        'pan' => (string)($entry['pan'] ?? ''),
        'createdAt' => (string)($entry['createdAt'] ?? ''),
        'nrDoc' => $entry['nrDoc'] ?? null,
        'logId' => (int)($entry['logId'] ?? 0),
        'terminal' => '',
        'merchant' => '',
        'card' => '',
    ];
    // Identificatorii terminalului nu sunt coloane separate: driverul ii scrie
    // in fisierul de rezultat (păstrat in Raw), deci ii citim de acolo cand
    // exista. Fara ei, duplicatul rămâne valid (suma/RRN/STAN/AUTH/PAN).
    $stmt = sqlsrv_query(
        $conn,
        "SELECT TOP 1 Raw FROM tblPosBancaLog
          WHERE DocID = ? AND TrxId = ? AND Raw IS NOT NULL AND LEN(Raw) > 0
          ORDER BY CASE WHEN EmiteBon = 1 THEN 0 ELSE 1 END, Id DESC",
        [(int)$docId, $out['trxId']]
    );
    $raw = '';
    if ($stmt) {
        $r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($r) { $raw = (string)($r['Raw'] ?? ''); }
    }
    if ($raw !== '') {
        foreach (explode("\n", str_replace("\r", "", $raw)) as $line) {
            if (strpos($line, '=') === false) { continue; }
            list($key, $value) = explode('=', $line, 2);
            $key = strtoupper(trim($key));
            if ($key === 'TERMINAL') { $out['terminal'] = trim($value); }
            elseif ($key === 'MERCHANT') { $out['merchant'] = trim($value); }
            elseif ($key === 'CARD') { $out['card'] = trim($value); }
        }
    }
    return $out;
}

/**
 * Comanda este inca in curs (trimisa terminalului, fara raspuns) si suficient de
 * recenta incat sa merite asteptat? Cat timp este `true`, o verificare in jurnal
 * este prematura (vanzarea poate sa nu fi ajuns inca in jurnal), deci clientul
 * ascunde Verifica / Manual.
 *
 * @param string $status
 * @param string $createdAt
 * @param int $maxAge secunde (aplicatia asteapta driverul max ~190s)
 */
function posBancaAttemptPendingFresh($status, $createdAt, $maxAge = 210) {
    if (strtoupper(trim((string)$status)) !== 'PENDING') { return false; }
    $ts = ((string)$createdAt !== '') ? strtotime((string)$createdAt) : false;
    $age = ($ts !== false) ? max(0, time() - $ts) : PHP_INT_MAX;
    return $age < $maxAge;
}

/**
 * Nota este blocata la modificare?
 *
 * Doua situatii blocheaza nota, ambele pentru ca exista bani pe terminal care
 * nu sunt inca acoperiti de un document:
 *  - `uncertain`: ultima incercare bancara nu a fost confirmata (UNKNOWN etc.);
 *  - `payable`: plata a fost aprobata pe terminal (sau o parte din ea) dar nu
 *    s-a aplicat pe nota (ex. plata partiala cu card urmata de Ctrl+F5).
 *
 * Banii aprobati se vad in audit (`tblPosBancaLog`), deci blocarea supravietuieste
 * unui reload. Fara ea, o nota cu 80 Lei incasati pe card rămânea modificabila
 * (se puteau sterge produse), iar `close_bill` refuza apoi inchiderea cu
 * `card_not_approved` (terminalul a incasat mai mult decat linia CARD a notei).
 *
 * @param resource $conn
 * @param int $docId
 * @return array{locked:bool,reason:string,uncertain:bool,resolved:bool,pending:bool,amount:string,uid:string,approved:bool,approvedTotal:float}|null null = nota nu are istoric bancar
 */
function posBancaDocLock($conn, $docId) {
    if ((int)$docId <= 0 || !posBancaDocHasLog($conn, $docId)) { return null; }
    $state = posBancaDocState($conn, $docId);
    // Suma aprobata dar inca neaplicata pe nota: nota rămâne nemodificabila, dar
    // plata POATE fi continuata din ecranul de plata (NOTA), unde suma reapare
    // din ledger. Fara asta, o nota cu bani aprobati si incercare nesolutionata
    // rămânea fara nicio ieșire (Anulare refuzata, close_bill ar fi inconsistat).
    $approved = posBancaApprovedTotal($conn, $docId);
    $uncertain = ($state['uncertain'] === true);
    $payable = ($approved['total'] > 0);
    $reason = $uncertain ? "uncertain" : ($payable ? "payable" : "");
    // O incertitudine poate fi deja lamurita de jurnal (RESYNC APPROVED=1) sau
    // de o decizie manuala: nota rămâne blocata, dar nu mai exista nimic de
    // verificat, deci butoanele Verifica / Manual nu au ce face.
    $resolved = ($uncertain
        && (!empty($state['manualApproved']) || !empty($state['resyncApproved'])));
    // Comanda inca in curs (PENDING recent): nimic de verificat inca.
    $pending = $uncertain && posBancaAttemptPendingFresh(
        (string)($state['attempt']['Status'] ?? ''),
        (string)($state['attempt']['CreatedAt'] ?? '')
    );
    return [
        "locked" => ($uncertain || $payable),
        "reason" => $reason,
        // Incertitudine NEREZOLVATA (doar ea justifica Verifica / Manual).
        "uncertain" => $uncertain,
        "resolved" => $resolved,
        "pending" => $pending,
        "amount" => $state['amount'],
        // TRX_ID-ul incercarii: afisat in bannerul de blocare, ca sa poata fi
        // cautat imediat in jurnalul terminalului bancar.
        "uid" => $state['uid'],
        // Bani aprobati pe terminal, inca neaplicati pe nota.
        "approved" => $payable,
        "approvedTotal" => $approved['total'],
    ];
}

/**
 * DocID-ul notei deschise de pe o masa, sau 0.
 *
 * @param resource $conn
 * @param int $nrMasa
 * @return int
 */
function posBancaOpenDocIdForMasa($conn, $nrMasa) {
    if ((int)$nrMasa <= 0) { return 0; }
    $stmt = @sqlsrv_query(
        $conn,
        "SELECT TOP 1 DocID FROM tblBonCurent WHERE NrMasa = ? AND Stare = 'D' ORDER BY DocID DESC",
        [(int)$nrMasa]
    );
    $row = $stmt ? @sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    return $row ? (int)$row['DocID'] : 0;
}
