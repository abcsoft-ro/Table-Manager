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
require_once __DIR__ . '/parola_local.php';

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
    $script = "\$all = Get-CimInstance Win32_Process | Where-Object { " .
        "(\$_.Name -eq 'chrome.exe' -or \$_.Name -eq 'msedge.exe') -and " .
        "(\$_.CommandLine -like '*TableManagerKiosk*'";
    if ($folder !== '') {
        $script .= " -or " .
            "\$_.CommandLine -like '*127.0.0.1/" . $folder . "*' -or " .
            "\$_.CommandLine -like '*localhost/" . $folder . "*'";
    }
    // Omoram doar ferestrele care existau deja: PowerShell-ul porneste in ~cateva
    // secunde, iar intre timp utilizatorul poate deschide o fereastra noua - aceea
    // nu trebuie omorata (de aceea parea ca "s-a deschis si s-a inchis singura").
    $script .= ") }; " .
        "\$nou = @(\$all | Where-Object { \$_.CreationDate -gt (Get-Date).AddSeconds(-5) }).Count; " .
        "\$procs = @(\$all | Where-Object { \$_.CreationDate -lt (Get-Date).AddSeconds(-5) }); " .
        "\$procs | ForEach-Object { Stop-Process -Id \$_.ProcessId -Force }; " .
        "'KILLED=' + @(\$procs).Count + ' NOU=' + \$nou + ' TOTAL=' + @(Get-CimInstance Win32_Process | " .
        "Where-Object { \$_.Name -eq 'chrome.exe' -or \$_.Name -eq 'msedge.exe' }).Count";
    $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
    $cmd = 'powershell.exe -NoProfile -NonInteractive -EncodedCommand ' . $encoded;
    $out = [];
    $code = 0;
    @exec($cmd . ' 2>&1', $out, $code);
    if ($code !== 0) { return ['killed' => -1, 'total' => -1, 'nou' => -1]; }
    $killed = -1;
    $total = -1;
    $nou = -1;
    foreach ($out as $line) {
        if (preg_match('/KILLED=(\d+)/', (string)$line, $m)) { $killed = (int)$m[1]; }
        if (preg_match('/NOU=(\d+)/', (string)$line, $m)) { $nou = (int)$m[1]; }
        if (preg_match('/TOTAL=(\d+)/', (string)$line, $m)) { $total = (int)$m[1]; }
    }
    return ['killed' => $killed, 'total' => $total, 'nou' => $nou];
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input || !is_array($input)) { $input = $_POST; }
$parola = trim((string)($input['parola'] ?? ''));

// Verificarea parolei se face normal in baza de date. Daca baza de date nu
// raspunde (instanta SQL oprita sau inca pornind dupa un reboot), folosim copia
// locala a parolei (api/parola_local.php): inchiderea aplicatiei trebuie sa
// functioneze in ORICE situatie, altfel POS-ul rămâne blocat pe ecran.
$conn = getDBConnection(true);
$offline = false;
$copiaLocala = '';
if ($conn === null) {
    $offline = true;
    $stare = parolaLocalCheckExit($parola);
    $copiaLocala = $stare;
    if ($stare === 'wrong') {
        appEventLog('exit_refuzat', appEventClientCtx() + [
            'parola_len'  => strlen($parola),
            'baza_date'   => 'indisponibila',
        ]);
        sendJsonResponse(["status" => "error", "message" => "Parola incorecta"], 401);
    }
    if ($stare === 'not_set') {
        sendJsonResponse(["status" => "error", "message" => "Parola de inchidere aplicatie nu este configurata"], 400);
    }
    // 'ok' = parola confirmata din copia locala; 'unknown' = nu exista copie
    // locala (nicio pornire reusita de la instalare). Aplicatia este oricum
    // inutilizabila fara baza de date, deci permitem inchiderea - se noteaza
    // explicit in jurnal (vezi mai jos).
} else {
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
    // Parola confirmata in DB: reimprospatam copia locala, ca inchiderea sa
    // functioneze si daca data viitoare baza de date nu raspunde.
    parolaLocalWriteExit($exitParola);
}

$kill = posExitKillKioskBrowser();
$killed = (int)($kill['killed'] ?? -1);
$browsere = (int)($kill['total'] ?? -1);
$noi = (int)($kill['nou'] ?? -1);
appEventLog('exit', appEventClientCtx() + [
    'parola_len'      => strlen($parola),
    'procese_browser' => $killed,
    'browsere_total'  => $browsere,
    'browsere_prea_noi' => $noi,
    'baza_date'       => $offline ? ('indisponibila' . ($copiaLocala === 'unknown' ? ', fara copie locala' : ', parola din copia locala')) : 'ok',
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
    "noi"      => $noi,
    "offline"  => $offline,
]);
