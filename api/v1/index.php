<?php
/**
 * index.php
 * Front controller pentru API-ul public de integrare /api/v1/.
 *
 * Autentificare: cheie globala in headerul X-Api-Key (sau Authorization: Bearer).
 * Vezi api/api_common.php pentru setari (Setari -> Integrare) si audit.
 *
 * Rute:
 *   GET  /api/v1/version
 *   GET  /api/v1/products           POST /api/v1/products
 *   GET  /api/v1/groups             POST /api/v1/groups
 *   GET  /api/v1/tables             POST /api/v1/tables
 *   POST /api/v1/waiters
 *   POST /api/v1/payment-forms
 *   POST /api/v1/vat
 *   GET  /api/v1/orders?stare=&from=&to=&source=current|archive&limit=&offset=
 *   GET  /api/v1/orders/{docId}?source=current|archive
 *   GET  /api/v1/reports/{plu|grupe|sectii|casieri|general}
 *   GET  /api/v1/events?since=&limit=
 */
require_once __DIR__ . '/../api_common.php';
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/read.php';
require_once __DIR__ . '/write.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// Preflight (CORS): raspuns gol. CORS nu este inca emis (consumatori server-la-server).
if ($method === 'OPTIONS') {
    http_response_code(204);
    header('Allow: GET, POST, OPTIONS');
    exit;
}

$segments = apiV1Segments();
$resource = strtolower((string)($segments[0] ?? ''));
$id = $segments[1] ?? null;

$conn = getDBConnection();
apiSetConn($conn);
$ip = apiRequireAuth($conn);

$endpoint = $resource . ($id !== null && $id !== '' ? '/' . $id : '');
$idemKey = ($method === 'POST') ? apiIdempotencyKey() : '';

// Idempotenta: daca acelasi POST a fost deja procesat, intoarcem raspunsul salvat.
if ($method === 'POST' && $idemKey !== '') {
    $cached = apiIdempotencyLookup($conn, $idemKey, $method, $endpoint);
    if ($cached !== null) {
        header('Cache-Control: no-store');
        sendJsonResponse($cached);
    }
}

// Audit: logam cererea la final (dupa raspuns). Capturam si corpul pentru
// idempotenta (doar la POST cu Idempotency-Key).
if ($idemKey !== '') { ob_start(); }
register_shutdown_function(function () use ($conn, $endpoint, $method, $ip, $idemKey) {
    $body = null;
    if ($idemKey !== '' && ob_get_level() > 0) {
        // Capturam raspunsul pentru idempotenta, dar NU golim bufferul
        // (PHP il trimite catre client dupa functiile de shutdown).
        $body = ob_get_contents();
    }
    $code = http_response_code();
    apiLog($conn, $endpoint, $method, $code ?: 200, $ip, ($idemKey !== '' ? $idemKey : null), $body);
});

$allowedGet = ['version', 'products', 'groups', 'tables', 'orders', 'reports', 'events'];
$allowedPost = ['products', 'groups', 'tables', 'waiters', 'payment-forms', 'vat'];

if ($resource === '') {
    apiError('Ruta lipsa. Exemple: /api/v1/products, /api/v1/orders.', 404);
}

if ($method === 'GET') {
    if (!in_array($resource, $allowedGet, true)) {
        apiError('Resursa necunoscuta sau metoda nepermisa.', 404);
    }
    switch ($resource) {
        case 'version':  [$data, $meta] = apiV1VersionGet($conn); break;
        case 'products': [$data, $meta] = apiV1ProductsGet($conn); break;
        case 'groups':   [$data, $meta] = apiV1GroupsGet($conn); break;
        case 'tables':   [$data, $meta] = apiV1TablesGet($conn); break;
        case 'orders':   [$data, $meta] = apiV1OrdersGet($conn, $id); break;
        case 'reports':  [$data, $meta] = apiV1ReportsGet($conn, $id); break;
        case 'events':   [$data, $meta] = apiV1EventsGet($conn); break;
    }
    apiRespond($data, $meta);
}

if ($method === 'POST') {
    if (!in_array($resource, $allowedPost, true)) {
        apiError('Resursa necunoscuta sau metoda nepermisa.', 404);
    }
    switch ($resource) {
        case 'products':      [$data, $meta] = apiV1ProductsPost($conn); break;
        case 'groups':        [$data, $meta] = apiV1GroupsPost($conn); break;
        case 'tables':        [$data, $meta] = apiV1TablesPost($conn); break;
        case 'waiters':       [$data, $meta] = apiV1WaitersPost($conn); break;
        case 'payment-forms': [$data, $meta] = apiV1PaymentFormsPost($conn); break;
        case 'vat':           [$data, $meta] = apiV1VatPost($conn); break;
    }
    apiRespond($data, $meta);
}

header('Allow: GET, POST, OPTIONS');
apiError('Metoda nepermisa.', 405);
