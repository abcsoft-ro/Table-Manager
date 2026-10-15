<?php
/**
 * api_common.php
 * Helper-e comune pentru API-ul public de integrare (/api/v1/), folosit de
 * aplicatii terte (servere/backend-uri) pentru export (citire) si import
 * (scriere) de date.
 *
 * Autentificare: o singura cheie globala, trimisa in headerul `X-Api-Key`
 * (sau `Authorization: Bearer <cheie>`). In baza de date se pastreaza doar
 * SHA-256 al cheii (tblSet.ApiKeyHash, grup 'Integrare'), niciodata cheia in clar.
 *
 * Setari (tblSet, grup 'Integrare', create de ensureSetariMeta()):
 *  - ApiEnabled     (1 = API activ, 0 = dezactivat; implicit 0)
 *  - ApiKeyHash     (SHA-256 al cheii)
 *  - ApiAllowedIps  (IP/CIDR separate prin virgula; gol = fara restrictie)
 *  - ApiRateLimit   (cereri/minut per IP; implicit 120)
 *  - ApiCorsOrigin  (rezervat pentru CORS, nefolosit deocamdata)
 *
 * Audit + rate-limit + idempotency: tabelul tblApiLog (creat automat).
 */
require_once __DIR__ . '/db.php';

/**
 * Citeste valoarea unei setari din tblSet. Intoarce $default cand lipseste.
 *
 * @param resource $conn
 * @param string $setting
 * @param string $default
 * @return string
 */
function apiSetting($conn, $setting, $default = '') {
    $stmt = @sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = ?", [$setting]);
    $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    if (!$row) { return $default; }
    $v = trim((string)($row['Value'] ?? ''));
    return ($v === '') ? $default : $v;
}

/**
 * Creeaza tabelul de audit/rate-limit daca nu exista.
 *
 * @param resource $conn
 * @return void
 */
function apiEnsureTables($conn) {
    static $done = false;
    if ($done) { return; }
    $sql = "
    IF OBJECT_ID('dbo.tblApiLog','U') IS NULL
    BEGIN
        CREATE TABLE dbo.tblApiLog (
            Id             INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
            Endpoint       NVARCHAR(120) NULL,
            Method         NVARCHAR(10)  NULL,
            StatusCode     INT           NULL,
            Ip             NVARCHAR(45)  NULL,
            IdempotencyKey NVARCHAR(120) NULL,
            ResponseBody   NVARCHAR(MAX) NULL,
            CreatedAt      DATETIME2     NOT NULL CONSTRAINT DF_tblApiLog_Created DEFAULT (SYSDATETIME())
        );
        CREATE INDEX IX_tblApiLog_Ip_Created ON dbo.tblApiLog (Ip, CreatedAt);
        CREATE INDEX IX_tblApiLog_Idem ON dbo.tblApiLog (IdempotencyKey, Method, Endpoint);
    END";
    @sqlsrv_query($conn, $sql, null, ['QueryTimeout' => 15]);
    $done = true;
}

/**
 * Adresa IP a clientului (tine cont de proxy-uri locale de incredere).
 *
 * @return string
 */
function apiClientIp() {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * Verifica daca un IP se afla intr-o lista de IP-uri / retele CIDR separate
 * prin virgula. Lista goala = fara restrictie.
 *
 * @param string $ip
 * @param string $list
 * @return bool
 */
function apiIpAllowed($ip, $list) {
    $list = trim((string)$list);
    if ($list === '') { return true; }
    $ipBin = @inet_pton($ip);
    if ($ipBin === false) { return false; }
    foreach (explode(',', $list) as $entry) {
        $entry = trim($entry);
        if ($entry === '') { continue; }
        if (strpos($entry, '/') === false) {
            $entryBin = @inet_pton($entry);
            if ($entryBin !== false && $entryBin === $ipBin) { return true; }
            continue;
        }
        [$net, $bits] = explode('/', $entry, 2);
        $netBin = @inet_pton(trim($net));
        $bits = (int)trim($bits);
        if ($netBin === false) { continue; }
        $len = strlen($netBin) * 8;
        if ($bits < 0 || $bits > $len) { continue; }
        $fullBytes = intdiv($bits, 8);
        $remBits = $bits % 8;
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) {
            continue;
        }
        if ($remBits > 0) {
            $mask = 0xFF << (8 - $remBits) & 0xFF;
            $ipByte = ord($ipBin[$fullBytes]);
            $netByte = ord($netBin[$fullBytes]);
            if (($ipByte & $mask) !== ($netByte & $mask)) { continue; }
        }
        return true;
    }
    return false;
}

/**
 * Cheia API din cerere (header X-Api-Key sau Authorization: Bearer).
 *
 * @return string
 */
function apiRequestKey() {
    if (!empty($_SERVER['HTTP_X_API_KEY'])) {
        return trim((string)$_SERVER['HTTP_X_API_KEY']);
    }
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($auth === '' && function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        foreach ($h as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $auth = (string)$v; break; }
        }
    }
    if (preg_match('/^\s*Bearer\s+(.+)$/i', $auth, $m)) {
        return trim($m[1]);
    }
    return '';
}

/**
 * Raspuns JSON de succes, in envelope-ul API-ului v1: {status, data, meta}.
 *
 * @param mixed $data
 * @param array|null $meta
 * @param int $statusCode
 * @return void
 */
function apiRespond($data, $meta = null, $statusCode = 200) {
    $payload = ['status' => 'success', 'data' => $data];
    if ($meta !== null) { $payload['meta'] = $meta; }
    header('Cache-Control: no-store');
    sendJsonResponse($payload, $statusCode);
}

/**
 * Retine conexiunea curenta (pentru rollback la eroare, ca tranzactia deschisa
 * de un import sa nu anuleze randul de audit scris la shutdown).
 *
 * @param resource $conn
 * @return void
 */
function apiSetConn($conn) {
    $GLOBALS['__api_conn'] = $conn;
}

/**
 * Face rollback daca o tranzactie este deschisa pe conexiunea curenta. Fara
 * aceasta, un apiError in timpul unui import ar iesi cu tranzactia deschisa,
 * iar INSERT-ul de audit din shutdown (rulat pe aceeasi conexiune) ar fi
 * anulat la inchiderea conexiunii.
 *
 * @return void
 */
function apiRollbackIfAny() {
    if (!empty($GLOBALS['__api_conn'])) {
        @sqlsrv_rollback($GLOBALS['__api_conn']);
    }
}

/**
 * Raspuns JSON de eroare. Nu iese din functie (sendJsonResponse face exit).
 *
 * @param string $message
 * @param int $statusCode
 * @param array $extra
 * @return void
 */
function apiError($message, $statusCode = 400, $extra = []) {
    apiRollbackIfAny();
    header('Cache-Control: no-store');
    sendJsonResponse(array_merge(['status' => 'error', 'message' => $message], $extra), $statusCode);
}

/**
 * Verifica autentificarea (cheie globala + IP allowlist) si rate-limitul.
 * Opreste executia cu 401/403/429/503 la esec. Intoarce IP-ul clientului.
 *
 * @param resource $conn
 * @return string
 */
function apiRequireAuth($conn) {
    apiEnsureTables($conn);

    if (apiSetting($conn, 'ApiEnabled', '0') !== '1') {
        apiError('API dezactivat. Activati integrarea din Setari -> Integrare.', 503);
    }
    $expected = apiSetting($conn, 'ApiKeyHash', '');
    if ($expected === '') {
        apiError('Cheie API neconfigurata. Generati o cheie din Setari -> Integrare.', 503);
    }
    $key = apiRequestKey();
    if ($key === '') {
        apiError('Cheie API lipsa (header X-Api-Key).', 401);
    }
    if (!hash_equals($expected, hash('sha256', $key))) {
        apiError('Cheie API invalida.', 401);
    }

    $ip = apiClientIp();
    if (!apiIpAllowed($ip, apiSetting($conn, 'ApiAllowedIps', ''))) {
        apiError('Adresa IP nu este permisa.', 403);
    }

    $limit = (int)apiSetting($conn, 'ApiRateLimit', '120');
    if ($limit > 0) {
        $stmt = @sqlsrv_query(
            $conn,
            "SELECT COUNT(*) AS c FROM tblApiLog WHERE Ip = ? AND CreatedAt > DATEADD(minute, -1, SYSDATETIME())",
            [$ip]
        );
        $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
        if ($row && (int)$row['c'] >= $limit) {
            apiError('Prea multe cereri. Reincercati mai tarziu.', 429);
        }
    }

    return $ip;
}

/**
 * Scrie un rand de audit in tblApiLog. Best-effort (nu opreste cererea).
 *
 * @param resource $conn
 * @param string $endpoint
 * @param string $method
 * @param int $statusCode
 * @param string $ip
 * @param string|null $idempotencyKey
 * @param string|null $responseBody
 * @return void
 */
function apiLog($conn, $endpoint, $method, $statusCode, $ip, $idempotencyKey = null, $responseBody = null) {
    apiEnsureTables($conn);
    @sqlsrv_query(
        $conn,
        "INSERT INTO tblApiLog (Endpoint, Method, StatusCode, Ip, IdempotencyKey, ResponseBody, CreatedAt)
         VALUES (?, ?, ?, ?, ?, ?, SYSDATETIME())",
        [$endpoint, $method, $statusCode, $ip, $idempotencyKey, $responseBody]
    );
}

/**
 * Headerul Idempotency-Key al cererii curente ('' daca lipseste).
 *
 * @return string
 */
function apiIdempotencyKey() {
    if (!empty($_SERVER['HTTP_IDEMPOTENCY_KEY'])) {
        return trim((string)$_SERVER['HTTP_IDEMPOTENCY_KEY']);
    }
    return '';
}

/**
 * Intoarce raspunsul salvat anterior pentru (cheie, metoda, endpoint), sau null.
 *
 * @param resource $conn
 * @param string $key
 * @param string $method
 * @param string $endpoint
 * @return array|null
 */
function apiIdempotencyLookup($conn, $key, $method, $endpoint) {
    if ($key === '') { return null; }
    apiEnsureTables($conn);
    $stmt = @sqlsrv_query(
        $conn,
        "SELECT TOP 1 ResponseBody FROM tblApiLog
         WHERE IdempotencyKey = ? AND Method = ? AND Endpoint = ? AND ResponseBody IS NOT NULL
         ORDER BY Id DESC",
        [$key, $method, $endpoint]
    );
    $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    if (!$row || $row['ResponseBody'] === null) { return null; }
    $decoded = json_decode((string)$row['ResponseBody'], true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Serializeaza un raspuns de succes pentru stocarea idempotenta.
 *
 * @param mixed $data
 * @param array|null $meta
 * @return string
 */
function apiEncodeResponse($data, $meta = null) {
    $payload = ['status' => 'success', 'data' => $data];
    if ($meta !== null) { $payload['meta'] = $meta; }
    return json_encode($payload, JSON_UNESCAPED_UNICODE);
}
