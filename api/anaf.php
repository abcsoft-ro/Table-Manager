<?php
/**
 * API: Interogare server ANAF (datele firmei dupa CIF).
 *  GET ?cif=<cod> -> interogheaza webservicesp.anaf.ro si intoarce datele
 *  firmei (denumire, adresa, judet, oras, reg. com., platitor TVA).
 *
 * Reproduce fluxul din vechea aplicatie VBA (curl POST catre API-ul ANAF
 * PlatitorTvaRest/v9 + parsare JSON). Raspunde cu status success + found, sau
 * cu eroare (server nefunctional, cod inexistent etc.).
 */
require_once __DIR__ . '/db.php';

const ANAF_URL = 'https://webservicesp.anaf.ro/api/PlatitorTvaRest/v9/tva';

/**
 * Scrie best-effort o linie in logs/anaf.log (diagnostic).
 *
 * @param string $msg
 */
function anafLog($msg) {
    $dir = dirname(__DIR__) . '/logs';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . str_replace(["\r", "\n"], ' ', $msg) . "\n";
    @file_put_contents($dir . '/anaf.log', $line, FILE_APPEND);
}

/**
 * POST JSON catre ANAF. Foloseste cURL daca extensia e prezenta, altfel
 * stream-urile PHP. Intoarce ['body'=>...,'code'=>...] sau ['error'=>...].
 *
 * @param string $url
 * @param string $body
 * @return array
 */
function anafHttpPost($url, $body) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            // Instalatiile Windows ale Apache nu au de obicei un CA bundle
            // configurat; ca si vechea aplicatie, nu validam certificatul.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return ['error' => $err !== '' ? $err : 'Serverul ANAF nu raspunde!'];
        }
        return ['body' => (string)$resp, 'code' => $code];
    }

    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content' => $body,
        'timeout' => 30,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        return ['error' => 'Serverul ANAF nu raspunde!'];
    }
    return ['body' => (string)$resp];
}

$cifRaw = trim((string)($_GET['cif'] ?? ''));
$cif = preg_replace('/\s+/', '', $cifRaw);
$cifUpper = strtoupper($cif);
if (substr($cifUpper, 0, 2) === 'RO') {
    $cif = substr($cif, 2);
}
if ($cif === '' || !ctype_digit($cif)) {
    sendJsonResponse(["status" => "error", "message" => "Cod fiscal (CIF) invalid"], 400);
}

$body = json_encode([["cui" => (int)$cif, "data" => date('Y-m-d')]], JSON_UNESCAPED_UNICODE);
$res = anafHttpPost(ANAF_URL, $body);
if (isset($res['error'])) {
    anafLog("EROARE transport cif=" . $cif . " http=- " . $res['error']);
    sendJsonResponse(["status" => "error", "message" => "Interogare ANAF: " . $res['error']], 502);
}

$text = trim((string)$res['body']);
$httpCode = isset($res['code']) ? (int)$res['code'] : 0;
$snippet = mb_substr(preg_replace('/\s+/', ' ', $text), 0, 400);

if ($text === '') {
    anafLog("EROARE raspuns gol cif=" . $cif . " http=" . $httpCode);
    sendJsonResponse(["status" => "error", "message" => "Serverul ANAF a raspuns gol (HTTP " . $httpCode . ")"], 502);
}
if (stripos($text, '<html') === 0 || stripos($text, '<!doctype') === 0) {
    anafLog("EROARE mentenanta cif=" . $cif . " http=" . $httpCode);
    sendJsonResponse(["status" => "error", "message" => "Serverul ANAF nu raspunde sau se afla in mentenanta!"], 503);
}

$json = json_decode($text, true);
if (!is_array($json)) {
    $scurt = mb_substr($snippet, 0, 160);
    anafLog("EROARE JSON invalid cif=" . $cif . " http=" . $httpCode . " body=" . $snippet);
    sendJsonResponse(["status" => "error", "message" => "Raspuns ANAF invalid (HTTP " . $httpCode . "): " . $scurt], 502);
}

// API-ul v9 raspunde cu {"found":[...],"notFound":[...]} fara campul "cod".
// Doar daca "cod" exista si nu este 200 este vorba de o eroare.
if (isset($json['cod']) && (int)$json['cod'] !== 200) {
    $cod = (int)$json['cod'];
    $msg = trim((string)($json['message'] ?? ''));
    if ($msg === '') { $msg = mb_substr($snippet, 0, 160); }
    anafLog("EROARE cod=" . $cod . " cif=" . $cif . " http=" . $httpCode . " mesaj=" . $msg);
    sendJsonResponse(["status" => "error", "message" => "Serverul ANAF: " . $msg . " (cod " . $cod . ")"], 502);
}

$found = (is_array($json['found'] ?? null) && count($json['found'])) ? $json['found'][0] : null;
if (!$found) {
    anafLog("NEEXISTENT cif=" . $cif . " http=" . $httpCode);
    sendJsonResponse(["status" => "error", "message" => "Codul fiscal nu exista!", "notFound" => true], 404);
}

$dg = $found['date_generale'] ?? [];
$df = $found['adresa_domiciliu_fiscal'] ?? [];
$scop = $found['inregistrare_scop_Tva'] ?? ($found['inregistrare_scop_TVA'] ?? []);
$platitorTVA = !empty($scop['scpTVA']);

$cuiReturnat = (int)($dg['cui'] ?? $cif);
$denumire = trim((string)($dg['denumire'] ?? ''));
$adresa = trim((string)($dg['adresa'] ?? ''));
$regCom = trim((string)($dg['nrRegCom'] ?? ''));
if ($regCom === '') { $regCom = '-'; }

// Judet = codul judetului (format RO-XX), NU numele (ex. RO-B, RO-IF).
$codJudet = trim((string)($df['dcod_JudetAuto'] ?? ''));
if ($codJudet === '') { $codJudet = trim((string)($df['dcod_Judet'] ?? '')); }
$judet = ($codJudet !== '') ? ('RO-' . $codJudet) : '';

// Oras: pentru Bucuresti (RO-B) = "SECTOR" + numarul sectorului, fara spatii
// (ex. "Sector 6 Mun. Bucuresti" -> "SECTOR6"); altfel denumirea localitatii.
$oras = trim((string)($df['ddenumire_Localitate'] ?? ''));
if (strcasecmp($codJudet, 'B') === 0) {
    if (preg_match('/(\d+)/', $oras, $m)) {
        $oras = 'SECTOR' . $m[1];
    }
}
if ($adresa === '') { $adresa = trim((string)($df['ddetalii_Adresa'] ?? ($df['detalii_Adresa'] ?? ''))); }

sendJsonResponse([
    "status" => "success",
    "found" => [
        "cui" => (string)$cuiReturnat,
        "denumire" => $denumire,
        "adresa" => $adresa,
        "regCom" => $regCom,
        "judet" => $judet,
        "oras" => $oras,
        "platitorTVA" => $platitorTVA
    ]
]);
