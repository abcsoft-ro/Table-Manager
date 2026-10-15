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
 * @param bool $silent Cand e true, intoarce null in loc sa raspunda cu eroare
 *                      JSON si sa opreasca executia (folosit de i18n la
 *                      randarea paginilor, unde lipsa DB-ului nu trebuie sa
 *                      transforme pagina in JSON; se cade pe limba implicita).
 */
function getDBConnection($silent = false) {
    global $DB_CONFIG;
    static $conn = null;
    if ($conn !== null) {
        return $conn;
    }

    $servers = [
        $DB_CONFIG['Server'],
    ];
    $connectionInfo = [
        "Database" => $DB_CONFIG['Database'],
        "UID" => $DB_CONFIG['UID'],
        "PWD" => $DB_CONFIG['PWD'],
        "CharacterSet" => "UTF-8",
        "ReturnDatesAsStrings" => true
    ];

    foreach ($servers as $srv) {
        $conn = sqlsrv_connect($srv, $connectionInfo);
        if ($conn) {
            return $conn;
        }
    }

    if ($silent) {
        $conn = null;
        return null;
    }

    // Daca nu s-a putut conecta
    $errors = sqlsrv_errors();
    $errMsg = $errors ? $errors[0]['message'] : 'Eroare necunoscuta la conectarea la MSSQL.';
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "status" => "error",
        "message" => "Nu s-a putut conecta la baza de date MSSQL (server: "
            . $DB_CONFIG['Server'] . ", baza: " . $DB_CONFIG['Database'] . "): " . $errMsg
    ]);
    exit;
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
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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
