<?php
/**
 * API: inchiderea aplicatiei POS din parola de inchidere (tblParola.ParolaExit).
 *
 * Pagina ruleaza in browser (implicit in mod kiosc); un simplu window.close()
 * nu este de incredere pentru o fereastra deschisa direct de .bat. De aceea,
 * dupa verificarea parolei, endpoint-ul termina procesele de browser ale
 * aplicatiei (Chrome/Edge lansate cu profilul dedicat TableManagerKiosk sau cu
 * URL-ul POS-ului). Clientul apeleaza si window.close() ca rezerva.
 */

require_once __DIR__ . '/db.php';

/**
 * Inchide procesele de browser care ruleaza aplicatia POS.
 */
function posExitKillKioskBrowser() {
    if (stripos(PHP_OS, 'WIN') !== 0 || !function_exists('exec')) {
        return false;
    }
    $script = "Get-CimInstance Win32_Process | Where-Object { " .
        "(\$_.Name -eq 'chrome.exe' -or \$_.Name -eq 'msedge.exe') -and " .
        "(\$_.CommandLine -like '*TableManagerKiosk*' -or " .
        "\$_.CommandLine -like '*127.0.0.1/rual*' -or " .
        "\$_.CommandLine -like '*localhost/rual*') " .
        "} | ForEach-Object { Stop-Process -Id \$_.ProcessId -Force }";
    $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
    $cmd = 'powershell.exe -NoProfile -NonInteractive -EncodedCommand ' . $encoded;
    $out = [];
    $code = 0;
    @exec($cmd . ' 2>&1', $out, $code);
    return $code === 0;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input || !is_array($input)) { $input = $_POST; }
$parola = trim((string)($input['parola'] ?? ''));

$conn = getDBConnection();
$stmt = @sqlsrv_query($conn, "SELECT TOP 1 ParolaExit FROM tblParola");
$row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
$exitParola = $row ? trim((string)$row['ParolaExit']) : '';

if ($exitParola === '') {
    sendJsonResponse(["status" => "error", "message" => "Parola de inchidere aplicatie nu este configurata"], 400);
}
if ($parola === '' || !hash_equals($exitParola, $parola)) {
    sendJsonResponse(["status" => "error", "message" => "Parola incorecta"], 401);
}

$killed = posExitKillKioskBrowser();
sendJsonResponse(["status" => "success", "message" => "Aplicatie inchisa", "killed" => $killed]);
