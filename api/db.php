<?php
/**
 * Modul de conectare la baza de date MSSQL.
 */

/**
 * Credentialele reale (server, baza de date, user, parola) stau in
 * api/db.local.php (ignorat de git). Daca fisierul nu exista se folosesc
 * valorile de mai jos, care sunt doar niste substituenti (placeholder).
 */
$DB_CONFIG = [
    'Server' => 'SERVER-NAME',
    'Database' => 'Database',
    'UID' => 'sa',
    'PWD' => '',
];
$__dbLocalFile = __DIR__ . '/db.local.php';
if (is_file($__dbLocalFile)) {
    $__dbLocal = require $__dbLocalFile;
    if (is_array($__dbLocal)) {
        $DB_CONFIG = array_merge($DB_CONFIG, $__dbLocal);
    }
}
unset($__dbLocalFile, $__dbLocal);

/**
 * Timeout-ul de conectare (secunde) folosit cand tblSet.ODBC_TimeOut nu este
 * inca cunoscut (prima pornire de la instalare). Valoarea reala vine din
 * tblSet.ODBC_TimeOut si se pastreaza in logs/db_timeout.json (vezi mai jos).
 */
if (!defined('DB_TIMEOUT_DEFAULT')) { define('DB_TIMEOUT_DEFAULT', 10); }

/**
 * Copia locala a timeout-ului de conectare: `logs/db_timeout.json` (gitignorat,
 * blocat la accesul HTTP de .htaccess). Setarea sta in `tblSet.ODBC_TimeOut`,
 * dar PRIMA conectare - cea care citeste setarea - are nevoie de o valoare
 * dinainte; altfel o conectare care nu se poate face ar astepta implicitul
 * driverului (~30 secunde, timp in care ecranul POS rămâne gol).
 *
 * @return string
 */
function dbTimeoutPath() {
    return dirname(__DIR__) . '/logs/db_timeout.json';
}

/**
 * @return int|null Timeout-ul din copia locala, null daca lipseste/este invalid.
 */
function dbTimeoutLocalRead() {
    try {
        $file = dbTimeoutPath();
        if (!is_file($file)) { return null; }
        $data = json_decode((string)@file_get_contents($file), true);
        $sec = is_array($data) ? (int)($data['sec'] ?? 0) : 0;
        return $sec > 0 ? $sec : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Scrie copia locala a timeout-ului (atomic, best-effort).
 *
 * @param int $sec
 * @return bool
 */
function dbTimeoutLocalWrite($sec) {
    try {
        $sec = (int)$sec;
        if ($sec <= 0) { return false; }
        $dir = dirname(__DIR__) . '/logs';
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $file = $dir . '/db_timeout.json';
        $json = json_encode(['sec' => $sec]);
        if (is_file($file) && @file_get_contents($file) === $json) { return true; }
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) { return false; }
        return @rename($tmp, $file);
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Timeout-ul folosit la conectarea LOCALA (copia locala a tblSet.ODBC_TimeOut).
 *
 * @return int
 */
function dbLoginTimeoutLocal() {
    $sec = dbTimeoutLocalRead();
    return $sec !== null ? $sec : DB_TIMEOUT_DEFAULT;
}

/**
 * `tblSet.ODBC_TimeOut` (secunde): valoarea folosita la ORICE conectare la baze
 * de date - conexiunea locala a POS-ului, serverul de produse, testele de
 * conectare, serviciile Python (care o primesc prin API).
 *
 * Are nevoie de o conexiune deja deschisa (setarea sta in baza de date); fara
 * ea cade pe copia locala / valoarea implicita. Rezultatul este cache-uit pe
 * durata cererii, iar copia locala este reimprospatata cand setarea e citita.
 *
 * @param resource|null $conn
 * @return int
 */
function dbOdbcTimeout($conn = null) {
    static $cached = null;
    if ($cached !== null) { return $cached; }
    $c = $conn ?: getDBConnection(true);
    if ($c) {
        $stmt = @sqlsrv_query($c, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'ODBC_TimeOut'");
        $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
        $sec = $row ? (int)trim((string)$row['Value']) : 0;
        if ($sec > 0) {
            $cached = $sec;
            dbTimeoutLocalWrite($sec);
            return $cached;
        }
    }
    $cached = dbLoginTimeoutLocal();
    return $cached;
}

/**
 * Raspunde cu 500 + JSON cand baza de date nu poate fi atinsa si opreste
 * executia. Se foloseste si cand o incercare anterioara din acelasi request a
 * esuat, ca sa nu mai asteptam inca un timeout de conectare.
 */
function dbConnectionFail() {
    global $DB_CONFIG;
    $errors = sqlsrv_errors();
    $errMsg = $errors ? $errors[0]['message'] : 'Eroare necunoscuta la conectarea la MSSQL.';
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    // JSON_INVALID_UTF8_SUBSTITUTE: mesajul driverului ODBC poate contine
    // caractere in alta codificare; fara flag, json_encode intoarce false si
    // clientul primeste un raspuns gol in loc de eroarea reala.
    echo json_encode([
        "status" => "error",
        "message" => "Nu s-a putut conecta la baza de date MSSQL (server: "
            . $DB_CONFIG['Server'] . ", baza: " . $DB_CONFIG['Database'] . "): " . $errMsg
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * @param bool $silent Cand e true, intoarce null in loc sa raspunda cu eroare
 *                      JSON si sa opreasca executia (folosit de i18n la
 *                      randarea paginilor, unde lipsa DB-ului nu trebuie sa
 *                      transforme pagina in JSON; se cade pe limba implicita;
 *                      la fel il folosesc caile care trebuie sa functioneze
 *                      si fara baza de date - vezi api/parola_local.php).
 */
function getDBConnection($silent = false) {
    global $DB_CONFIG;
    static $conn = null;
    // O incercare esuata nu se repeta in acelasi request: altfel fiecare apel
    // silentios (i18n la fiecare mesaj tradus) ar astepta din nou un timeout de
    // conectare - exact cand baza de date lipseste si ecranul trebuie sa arate
    // repede un mesaj clar.
    static $failed = false;
    if ($conn !== null) {
        return $conn;
    }
    if ($failed) {
        if ($silent) { return null; }
        dbConnectionFail();
    }

    $servers = [
        $DB_CONFIG['Server'],
    ];
    $connectionInfo = [
        "Database" => $DB_CONFIG['Database'],
        "UID" => $DB_CONFIG['UID'],
        "PWD" => $DB_CONFIG['PWD'],
        "CharacterSet" => "UTF-8",
        "ReturnDatesAsStrings" => true,
        // Timeout de conectare (tblSet.ODBC_TimeOut, prin copia locala: setarea
        // se citeste abia dupa conectare - vezi dbOdbcTimeout()). Fara el,
        // driverul are un implicit mult mai mare (masurat ~30s pe o statie) si
        // aplicatia rămânea tot acest timp pe un ecran gol.
        "LoginTimeout" => dbLoginTimeoutLocal()
    ];

    foreach ($servers as $srv) {
        $conn = sqlsrv_connect($srv, $connectionInfo);
        if ($conn) {
            $failed = false;
            return $conn;
        }
    }

    $failed = true;
    if ($silent) {
        $conn = null;
        return null;
    }

    dbConnectionFail();
}

/**
 * Trimite un raspuns JSON si opreste executia.
 *
 * @param mixed $data Datele de serializat in JSON.
 * @param int $statusCode Codul HTTP al raspunsului.
 * @return void
 */
function sendJsonResponse($data, $statusCode = 200) {
    // Traducem campul "message" (text de interfata) in limba activa
    // (tblSet.Limba). Mesajele sunt chei sursa romane (gettext-style).
    if (is_array($data) && isset($data['message']) && is_string($data['message'])) {
        if (!function_exists('tr')) {
            @require_once __DIR__ . '/i18n.php';
        }
        if (function_exists('tr')) {
            $data['message'] = tr($data['message']);
        }
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    // JSON_INVALID_UTF8_SUBSTITUTE: daca un mesaj contine caractere in alta
    // codificare (ex. text de la driverul ODBC), json_encode intoarce false si
    // clientul primeste un raspuns gol - adica o eroare de parsare in loc de
    // mesajul real. Cu flag-ul, textele invalide sunt inlocuite si restul
    // raspunsului rămâne utilizabil.
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * Orice eroare neasteptata (ex. un TypeError din sqlsrv cand o coloana lipseste
 * pe o instalatie veche) este intoarsa ca JSON in loc de pagina HTML. Altfel
 * clientul primeste un raspuns non-JSON si raporteaza "JSON.parse: unexpected
 * character" in loc de eroarea reala.
 */
set_exception_handler(function ($e) {
    if (!headers_sent()) {
        sendJsonResponse([
            "status" => "error",
            "message" => "Eroare server: " . $e->getMessage()
        ], 500);
    }
});

/**
 * Whitelist de adrese IP (tblSet.IpPermise): toate cererile web trec prin acest
 * fisier (pagini prin i18n.php, endpoint-uri direct sau prin *_common.php), deci
 * verificarea de aici acopera intreaga aplicatie. Vezi api/ip_guard.php.
 *
 * Se executa ultima, dupa ce toate functiile de mai sus sunt definite (guard-ul
 * foloseste getDBConnection() cand copia locala a listei lipseste).
 */
require_once __DIR__ . '/ip_guard.php';
ipGuardCheck();
