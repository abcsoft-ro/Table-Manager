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
require_once __DIR__ . '/app_events.php';

/**
 * Inchide procesele de browser care ruleaza aplicatia POS.
 *
 * @return int numarul de procese omorate, -1 daca nu se poate aplica
 *             (nu ruleaza pe Windows / exec dezactivat / eroare PowerShell)
 */
function posExitKillKioskBrowser() {
    if (stripos(PHP_OS, 'WIN') !== 0 || !function_exists('exec')) {
        return -1;
    }
    // Numele folderului proiectului, dedus din locatia acestui fisier
    // (merge si daca folderul este redenumit). Doar caractere sigure.
    $folder = preg_replace('/[^A-Za-z0-9_.-]/', '', basename(dirname(__DIR__)));
    $script = "\$procs = Get-CimInstance Win32_Process | Where-Object { " .
        "(\$_.Name -eq 'chrome.exe' -or \$_.Name -eq 'msedge.exe') -and " .
        "(\$_.CommandLine -like '*TableManagerKiosk*'";
    if ($folder !== '') {
        $script .= " -or " .
            "\$_.CommandLine -like '*127.0.0.1/" . $folder . "*' -or " .
            "\$_.CommandLine -like '*localhost/" . $folder . "*'";
    }
    $script .= ") }; " .
        "\$procs | ForEach-Object { Stop-Process -Id \$_.ProcessId -Force }; " .
        "'KILLED=' + @(\$procs).Count + ' TOTAL=' + @(Get-CimInstance Win32_Process | " .
        "Where-Object { \$_.Name -eq 'chrome.exe' -or \$_.Name -eq 'msedge.exe' }).Count";
    $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
    $cmd = 'powershell.exe -NoProfile -NonInteractive -EncodedCommand ' . $encoded;
    $out = [];
    $code = 0;
    @exec($cmd . ' 2>&1', $out, $code);
    if ($code !== 0) { return ['killed' => -1, 'total' => -1]; }
    $killed = -1;
    $total = -1;
    foreach ($out as $line) {
        if (preg_match('/KILLED=(\d+)/', (string)$line, $m)) { $killed = (int)$m[1]; }
        if (preg_match('/TOTAL=(\d+)/', (string)$line, $m)) { $total = (int)$m[1]; }
    }
    return ['killed' => $killed, 'total' => $total];
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
    // Incercare de inchidere cu parola gresita - util in jurnal (ex. un cod
    // tastat din greseala intr-un prompt deschis fara sa vrei).
    appEventLog('exit_refuzat', appEventClientCtx() + ['parola_len' => strlen($parola)]);
    sendJsonResponse(["status" => "error", "message" => "Parola incorecta"], 401);
}

$kill = posExitKillKioskBrowser();
$killed = (int)($kill['killed'] ?? -1);
$browsere = (int)($kill['total'] ?? -1);
appEventLog('exit', appEventClientCtx() + [
    'parola_len'      => strlen($parola),
    'procese_browser' => $killed,
    'browsere_total'  => $browsere,
    'rezultat'        => $killed > 0 ? 'browsere kiosk omorate'
                       : ($browsere > 0 ? 'niciun proces de kiosk gasit (browsere exista)'
                       : ($browsere === 0 ? 'nu rula niciun browser' : 'fara suport (exec/Windows)')),
]);
sendJsonResponse([
    "status"   => "success",
    "message"  => "Aplicatie inchisa",
    "killed"   => ($killed > 0),
    "procese"  => $killed,
    "browsere" => $browsere,
]);
