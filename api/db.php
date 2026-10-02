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

function getDBConnection() {
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

function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
