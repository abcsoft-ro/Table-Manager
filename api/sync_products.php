<?php
/**
 * API: Sincronizare produse (ruleaza procedura stocata dbo.ImportProd).
 * Sursa (server/instanta/user/parola) se citeste din tblConectare (ID=1).
 */
require_once __DIR__ . '/db.php';

$conn = getDBConnection();

// Sincronizarea este blocata cat timp exista linii in tblNoteD (note neinchise).
// Se permite doar dupa efectuarea raportului Z al POS-ului.
$stmtChk = sqlsrv_query($conn, "SELECT COUNT(*) AS c FROM tblNoteD");
$chk = $stmtChk ? sqlsrv_fetch_array($stmtChk, SQLSRV_FETCH_ASSOC) : null;
$areNote = ($chk && (int)$chk['c'] > 0);
$mesajBlocat = "Sincronizarea nu este posibila decat dupa efectuarea raportului Z al POS-ului.";

// Mod de verificare (?check=1): doar raporteaza daca se poate sincroniza, fara sa ruleze nimic.
if (isset($_GET['check'])) {
    sendJsonResponse([
        "status" => "success",
        "allowed" => !$areNote,
        "message" => $areNote ? $mesajBlocat : "Sincronizarea este posibila."
    ]);
}

if ($areNote) {
    sendJsonResponse(["status" => "error", "message" => $mesajBlocat], 409);
}

// Configuratia sursei de date (aceeasi folosita de ImportProd)
$stmtCfg = sqlsrv_query($conn, "SELECT TOP 1 ServerIP, ServerName, UserName, Password FROM tblConectare WHERE ID = 1");
$cfg = $stmtCfg ? sqlsrv_fetch_array($stmtCfg, SQLSRV_FETCH_ASSOC) : null;
if (!$cfg) {
    sendJsonResponse(["status" => "error", "message" => "Nu exista configurarea de conectare (tblConectare ID=1)"], 500);
}

$server = trim($cfg['ServerIP'] ?? '');
$instance = trim($cfg['ServerName'] ?? '');
$ip = ($instance !== '' && strcasecmp($instance, $server) !== 0) ? $server . '\\' . $instance : $server;
$user = trim($cfg['UserName'] ?? 'sa');
$pass = (string)($cfg['Password'] ?? '');

if ($ip === '') {
    sendJsonResponse(["status" => "error", "message" => "ServerIP lipsa in tblConectare"], 500);
}

// Rulam procedura. Orice eroare este propagata (THROW in CATCH) si ajunge aici.
$stmt = sqlsrv_query($conn, "EXEC dbo.ImportProd @IP = ?, @user = ?, @PassWord = ?", [$ip, $user, $pass]);
if ($stmt === false) {
    $err = sqlsrv_errors();
    sendJsonResponse([
        "status" => "error",
        "message" => "Eroare sincronizare: " . ($err ? $err[0]['message'] : 'necunoscuta')
    ], 500);
}

// Consumam toate seturile de rezultate returnate de procedura
while (sqlsrv_next_result($stmt)) { /* no-op */ }

sendJsonResponse([
    "status" => "success",
    "message" => "Sincronizarea produselor s-a finalizat",
    "server" => $ip
]);
