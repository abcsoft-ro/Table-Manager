<?php
/**
 * _common.php
 * Helper-e specifice API-ului v1 (parsare ruta, paginare, normalizare,
 * validare corp JSON). Este inclus de api/v1/index.php.
 */

/**
 * Segmentele rutei, dupa baza /api/v1 (ex. "orders/1042" -> ["orders","1042"]).
 *
 * @return array
 */
function apiV1Segments() {
    $uri = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $base = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
    $path = '';
    if ($base !== '' && strpos($uri, $base) === 0) {
        $path = substr($uri, strlen($base));
    } else {
        $pos = strpos($uri, '/api/v1');
        $path = ($pos !== false) ? substr($uri, $pos + 7) : $uri;
    }
    $path = trim($path, '/');
    if ($path === '') { return []; }
    $seg = array_values(array_filter(explode('/', $path), function ($s) { return $s !== ''; }));
    // Toleram apelul direct la index.php.
    if (!empty($seg) && strcasecmp($seg[0], 'index.php') === 0) { array_shift($seg); }
    return $seg;
}

/**
 * Limita de paginare (implicit 100, maxim 500).
 *
 * @return int
 */
function apiV1Limit() {
    $l = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    if ($l <= 0) { $l = 100; }
    return min($l, 500);
}

/**
 * Offset-ul de paginare (implicit 0).
 *
 * @return int
 */
function apiV1Offset() {
    $o = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    return max(0, $o);
}

/**
 * Citeste toate randurile unui query.
 *
 * @param resource $conn
 * @param string $sql
 * @param array $params
 * @return array
 */
function apiV1FetchAll($conn, $sql, $params = []) {
    $stmt = @sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        apiError('Eroare interogare: ' . apiV1SqlError(), 500);
    }
    $rows = [];
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) { $rows[] = $r; }
    return $rows;
}

/**
 * Primul rand al unui query (sau null).
 *
 * @param resource $conn
 * @param string $sql
 * @param array $params
 * @return array|null
 */
function apiV1FetchOne($conn, $sql, $params = []) {
    $stmt = @sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        apiError('Eroare interogare: ' . apiV1SqlError(), 500);
    }
    $r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return $r ?: null;
}

/**
 * Mesajul ultimei erori sqlsrv.
 *
 * @return string
 */
function apiV1SqlError() {
    $e = sqlsrv_errors();
    return $e ? (string)$e[0]['message'] : 'eroare necunoscuta';
}

/**
 * Face rollback si raspunde cu eroarea SQL, capturand mesajul INAINTE de
 * rollback: sqlsrv_rollback() goleste sqlsrv_errors(), deci citirea ei dupa
 * rollback intoarce "eroare necunoscuta" si ascunde cauza reala
 * (vezi tests/REPORT.md B-05).
 *
 * @param resource $conn
 * @param string $prefix
 * @return void
 */
function apiV1RollbackWithError($conn, $prefix) {
    $message = apiV1SqlError();
    @sqlsrv_rollback($conn);
    apiError($prefix . $message, 500);
}

/**
 * Corpul JSON al cererii (array). Fallback pe $_POST.
 *
 * @return array
 */
function apiV1Body() {
    $raw = file_get_contents('php://input');
    $input = json_decode((string)$raw, true);
    if (!is_array($input)) { $input = $_POST; }
    return is_array($input) ? $input : [];
}

/**
 * Valoare booleana din MSSQL bit / int / string.
 *
 * @param mixed $v
 * @return bool
 */
function apiV1Bool($v) {
    return ($v == 1 || $v == -1 || $v === true || $v === '1' || $v === 'true');
}

/**
 * Normalizeaza un rand de produs (tblProd) pentru API.
 *
 * @param array $r
 * @return array
 */
function apiV1Product($r) {
    return [
        'prodId'    => (int)$r['ProdID'],
        'barCod'    => trim((string)($r['BarCod'] ?? '')),
        'denumire'  => trim((string)($r['Denumire'] ?? '')),
        'um'        => trim((string)($r['UM'] ?? '')),
        'nrGrp'     => (int)($r['NrGrp'] ?? 0),
        'kp'        => ($r['KP'] === null ? null : (int)$r['KP']),
        'sectie'    => ($r['Sectie'] === null ? null : (int)$r['Sectie']),
        'pv'        => (float)($r['PV'] ?? 0),
        'nrTva'     => ($r['Nr_TVA'] === null ? null : (int)$r['Nr_TVA']),
        'mz'        => (int)($r['MZ'] ?? 0),
        'magId'     => ($r['MagID'] === null ? null : (int)$r['MagID']),
        'poz'       => (int)($r['Poz'] ?? 0),
        'backColor' => ($r['BackColor'] === null ? null : (int)$r['BackColor']),
        'fontColor' => ($r['FontColor'] === null ? null : (int)$r['FontColor']),
        'fontSize'  => ($r['FontSize'] === null ? null : (int)$r['FontSize']),
        'fontType'  => trim((string)($r['FontType'] ?? '')),
        'bold'      => apiV1Bool($r['Bold'] ?? 0),
        'imagine'   => trim((string)($r['Imagine'] ?? '')),
        'dataUM'    => ($r['DataUM'] === null ? null : (string)$r['DataUM']),
    ];
}

/**
 * Normalizeaza un rand de grupa (tblGrp) pentru API.
 *
 * @param array $r
 * @return array
 */
function apiV1Group($r) {
    return [
        'nrGrp'     => (int)$r['NrGrp'],
        'denumire'  => trim((string)($r['Denumire'] ?? '')),
        'poz'       => (int)($r['Poz'] ?? 0),
        'backColor' => ($r['BackColor'] === null ? null : (int)$r['BackColor']),
        'fontColor' => ($r['FontColor'] === null ? null : (int)$r['FontColor']),
        'fontSize'  => ($r['FontSize'] === null ? null : (int)$r['FontSize']),
        'fontType'  => trim((string)($r['FontType'] ?? '')),
        'bold'      => apiV1Bool($r['Bold'] ?? 0),
    ];
}

/**
 * Meta de paginare pentru raspuns.
 *
 * @param int $limit
 * @param int $offset
 * @param int $count
 * @return array
 */
function apiV1PageMeta($limit, $offset, $count) {
    return [
        'limit'   => $limit,
        'offset'  => $offset,
        'count'   => $count,
        'hasMore' => ($count === $limit),
    ];
}
