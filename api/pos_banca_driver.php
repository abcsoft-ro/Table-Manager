<?php
/**
 * Helpers comuni pentru driverele POS bancar (SmartPay ECR Link si Viva ECR).
 *
 * Aici traieste strict partea care nu tine de un protocol anume:
 *   - lansarea unui driver Python (.py) cu argumente si asteptarea fisierului
 *     de rezultat,
 *   - interpretorul Python (mediul virtual al aplicatiei are prioritate),
 *   - citirea unui fisier .env minimal (conexiunea terminalului),
 *   - raspunsul unei verificari pentru o decizie manuala deja luata.
 *
 * Codul specific fiecarui protocol sta in adaptorul lui:
 * api/pos_banca_smartpay.php, respectiv api/pos_banca_viva.php.
 */

/**
 * Gaseste folderul unei instalari Python (python.exe). Apache ruleaza ca
 * LocalSystem si nu mosteneste PATH-ul utilizatorului, deci driverul .bat nu
 * gaseste "python" (desi merge lansat manual). Cautam instalari uzuale si le
 * adaugam la PATH-ul procesului copil.
 */
function posBancaFindPythonDir() {
    if (stripos(PHP_OS, 'WIN') !== 0) { return ''; }
    // 1. Mediul virtual al proiectului (.venv): il preferam, ca driverul POS
    //    bancar sa ruleze exact ca restul scripturilor Python ale aplicatiei.
    //    Important: .venv are pyserial (vezi requirements.txt), deci merge cu
    //    transportul serial (smartpay_client.py). Fara el, driverul .bat cadea pe un
    //    "python" fara pyserial si raporta eronat "Python sau script lipsa".
    $venvPy = __DIR__ . '/../.venv/Scripts/python.exe';
    if (is_file($venvPy)) {
        $dir = realpath(dirname($venvPy));
        if ($dir !== false) {
            $dir = str_replace('/', '\\', $dir);
            posBancaEnsurePyShim($dir);
            return $dir;
        }
    }
    // 2. Instalari Python uzuale (fallback).
    $patterns = [
        'C:\\Users\\*\\AppData\\Local\\Programs\\Python\\Python3*',
        'C:\\Python3*',
        'C:\\Program Files\\Python3*',
        'C:\\Program Files (x86)\\Python3*',
    ];
    $dirs = [];
    foreach ($patterns as $pat) {
        foreach ((array)glob($pat, GLOB_ONLYDIR) as $d) { $dirs[] = $d; }
    }
    // Preferam ultima versiune (sortate descrescator dupa nume).
    rsort($dirs);
    foreach ($dirs as $d) {
        if (is_file($d . '\\python.exe')) { return $d; }
    }
    return '';
}

/**
 * Creeaza in folderul Python dat un launcher `py.bat` care redirectioneaza spre
 * interpretorul din acelasi folder. Driverul POS bancar (`vanzare.bat`) prefera
 * launcher-ul `py` daca `where py` reuseste; pe unele masini exista un `py.exe`
 * care nu are niciun Python inregistrat si raspunde "No installed Python found!".
 * Fiindca folderul `.venv\Scripts` este pus PRIMUL in PATH-ul procesului copil,
 * un `py.bat` acolo face ca `where py` sa gaseasca launcher-ul nostru corect.
 *
 * @param string $dir
 */
function posBancaEnsurePyShim($dir) {
    $shim = rtrim($dir, '\\/') . '\\py.bat';
    if (is_file($shim)) { return; }
    $batch = "@echo off\r\n\"%~dp0python.exe\" %*\r\n";
    @file_put_contents($shim, $batch);
}

/**
 * Construieste environment-ul procesului copil cu folderul Python adaugat la
 * PATH. Intoarce null (mostenire) cand nu putem citi environment-ul curent.
 *
 * @param string $pythonDir
 */
function posBancaChildEnv($pythonDir) {
    $env = getenv();
    if (!is_array($env)) { return null; }
    if ($pythonDir !== '') {
        $key = isset($env['Path']) ? 'Path' : (isset($env['PATH']) ? 'PATH' : 'Path');
        $cur = isset($env[$key]) ? (string)$env[$key] : '';
        if (stripos($cur, $pythonDir) === false) {
            $env[$key] = $pythonDir . ';' . $cur;
        }
    }
    return $env;
}

/**
 * Ultimele linii din jurnalul driverului POS bancar (stdout+stderr), pentru
 * diagnosticoane cand driverul nu scrie fisierul de rezultat (ex. Python fara
 * un modul necesar). Intoarce string (eventual gol).
 *
 * @param string $path
 */
function posBancaDriverTail($path, $maxBytes = 1500) {
    if (!is_file($path)) { return ''; }
    $data = @file_get_contents($path);
    if ($data === false || $data === '') { return ''; }
    $data = trim($data);
    if (strlen($data) > $maxBytes) { $data = substr($data, -$maxBytes); }
    return $data;
}

/**
 * @param string $path
 */
function posBancaResultReady($path) {
    return is_file($path) && filesize($path) > 0;
}

/**
 * Citeste un fisier .env minimal (aceleasi reguli ca env_config.py din drivere:
 * comentarii cu #, prefix "export ", valori optionale intre ghilimele).
 *
 * @param string $file
 * @return array<string,string>
 */
function posBancaReadDotEnv($file) {
    $out = [];
    if (!is_file($file)) { return $out; }
    $lines = @file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) { return $out; }
    foreach ($lines as $line) {
        // BOM UTF-8 (unele editoare Windows il scriu) ar face cheia invizibila.
        $line = ltrim((string)$line, "\xEF\xBB\xBF");
        $line = trim($line);
        if ($line === '' || $line[0] === '#') { continue; }
        if (stripos($line, 'export ') === 0) { $line = substr($line, 7); }
        if (strpos($line, '=') === false) { continue; }
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2) {
            $q = $value[0];
            if (($q === '"' || $q === "'") && substr($value, -1) === $q) {
                $value = substr($value, 1, -1);
            }
        }
        if ($key !== '') { $out[$key] = $value; }
    }
    return $out;
}

/**
 * Scriptul Python al driverului (ex. smartpay_client.py / viva_client.py),
 * daca exista langa .bat. Comenzile auxiliare (resync / void) ruleaza direct
 * scriptul, nu .bat-ul.
 *
 * Pentru SmartPay acceptam si numele vechi `ecr_client.py`: statiile care au
 * deja driverul copiat in folderul lor (ex. C:\Sunmi) rămân functionale dupa
 * redenumirea din repo, fara sa fie nevoie sa copieze imediat fisierul nou.
 *
 * @param string $batPath
 * @param string $scriptName
 */
function posBancaDriverScript($batPath, $scriptName = 'smartpay_client.py') {
    $dir = rtrim(dirname($batPath), '\\/');
    $script = $dir . '\\' . $scriptName;
    if (is_file($script)) { return $script; }
    if ($scriptName === 'smartpay_client.py') {
        $legacy = $dir . '\\ecr_client.py';
        if (is_file($legacy)) { return $legacy; }
    }
    return '';
}

/**
 * Interpretorul Python pentru comenzile auxiliare.
 *
 * @param string $pythonDir
 */
function posBancaPythonExe($pythonDir) {
    if ($pythonDir !== '') {
        $exe = rtrim($pythonDir, '\\/') . '\\python.exe';
        if (is_file($exe)) { return $exe; }
    }
    return 'python';
}

/**
 * Fisier de rezultat separat pentru comenzile auxiliare, ca sa nu se amestece
 * cu rezultatul unei plati in curs.
 *
 * @param string $resultPath
 * @param string $suffix
 */
function posBancaAuxResultPath($resultPath, $suffix) {
    $base = pathinfo($resultPath, PATHINFO_FILENAME);
    $safe = preg_replace('/[^A-Za-z0-9_]/', '', (string)$suffix);
    return dirname($resultPath) . DIRECTORY_SEPARATOR . $base . '_' . $safe . '.txt';
}

/**
 * Parseaza fisierul de rezultat (linii CHEIE=VALOARE, case-insensitive).
 *
 * @param string $content
 */
function posBancaParseResult($content) {
    $out = [];
    $content = str_replace(["\r\n", "\r"], "\n", (string)$content);
    foreach (explode("\n", $content) as $line) {
        if (strpos($line, '=') === false) { continue; }
        list($key, $val) = explode('=', $line, 2);
        $key = strtoupper(trim($key));
        if ($key === '') { continue; }
        $out[$key] = trim($val);
    }
    return $out;
}

/**
 * Ruleaza o comanda a driverului Python (sale/resync/void/last-receipt) si
 * intoarce rezultatul parsat din fisierul --out. Comanda primeste
 * --timeout/--retries inaintea subcomenzii, ca un terminal blocat sa nu tina
 * request-ul 3 minute.
 *
 * @param string $script
 * @param string $batPath
 * @param string $pythonDir
 * @param array $args
 * @param string $resultPath
 * @param string $driverLog
 * @param int $timeoutMs
 */
function posBancaRunCommand($script, $batPath, $pythonDir, array $args, $resultPath, $driverLog, $timeoutMs = 70000) {
    @unlink($resultPath);
    $py = posBancaPythonExe($pythonDir);
    $parts = ['"' . $py . '"', '"' . $script . '"'];
    foreach ($args as $arg) { $parts[] = $arg; }
    $parts[] = '--out';
    $parts[] = '"' . $resultPath . '"';
    $cmd = implode(' ', $parts);
    $childEnv = ($pythonDir !== '') ? posBancaChildEnv($pythonDir) : null;
    $cwd = dirname($batPath);
    @file_put_contents(
        $driverLog,
        '[' . date('Y-m-d H:i:s') . '] cmd=' . $cmd
            . ' | pythonDir=' . ($pythonDir !== '' ? $pythonDir : '(none)')
            . ' | cwd=' . $cwd . "\n",
        FILE_APPEND
    );
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $driverLog, 'a'],
        2 => ['file', $driverLog, 'a']
    ];
    $proc = @proc_open($cmd, $descriptors, $pipes, $cwd, $childEnv);
    if (!is_resource($proc)) {
        return ['ok' => false, 'message' => 'Nu pot lansa driverul terminalului bancar (POS).', 'fields' => [], 'raw' => ''];
    }
    if (isset($pipes[0]) && is_resource($pipes[0])) { fclose($pipes[0]); }
    $waited = 0;
    while (!posBancaResultReady($resultPath)) {
        usleep(250000);
        $waited += 250;
        if ($waited >= $timeoutMs) { break; }
    }
    @proc_close($proc);
    if (!posBancaResultReady($resultPath)) {
        return ['ok' => false, 'message' => 'Terminalul bancar (POS) nu a raspuns in timp util.', 'fields' => [], 'raw' => ''];
    }
    usleep(100000);
    $raw = (string)@file_get_contents($resultPath);
    return ['ok' => true, 'message' => '', 'fields' => posBancaParseResult($raw), 'raw' => trim($raw)];
}

/**
 * Raspunsul unei verificari pentru o decizie MANUAL deja luata. Este folosit
 * atat inainte de a rula resync-ul (decizia exista deja), cat si dupa, pentru
 * cazul in care operatorul a apasat Manual cat timp terminalul se verifica.
 *
 * @param array<string,mixed> $manual
 * @param array<string,mixed>|null $attempt
 * @param string $fallbackUid
 * @param array<string,mixed>|null $connection
 * @return array<string,mixed>
 */
function posBancaManualResyncPayload($manual, $attempt, $fallbackUid, $connection) {
    $act = strtolower(trim((string)($manual['Actiune'] ?? '')));
    $found = ($act === 'aprobat');
    $amount = trim((string)($manual['Suma'] ?? ''));
    if ($amount === '' && is_array($attempt)) {
        $amount = trim((string)($attempt['Suma'] ?? ''));
    }
    $trx = trim((string)($manual['TrxId'] ?? ''));
    if ($trx === '' && is_array($attempt)) {
        $trx = trim((string)($attempt['TrxId'] ?? ''));
    }
    if ($trx === '') { $trx = trim((string)$fallbackUid); }
    return [
        "status" => "success",
        "enabled" => true,
        "found" => $found,
        "approved" => $found,
        "voided" => false,
        "uid" => $trx,
        "stan" => "",
        "amount" => $amount,
        "type" => "MANUAL",
        "posStatus" => "MANUAL",
        "posMessage" => "Decizie manuala: " . ($found ? "aprobat" : "neaprobat"),
        "logId" => null,
        "manual" => true,
        "connection" => $connection
    ];
}
