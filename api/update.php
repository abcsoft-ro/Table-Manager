<?php
/**
 * API: actualizare aplicatie din GitHub (abcsoft-ro/Table-Manager, branch main).
 *
 * GET  ?check=1 -> compara SHA-ul instalat (logs/update_state.json) cu ultimul
 *                 commit de pe branch si spune daca exista o versiune noua.
 * POST {action:'apply', parola, force?} -> verifica ParolaUpdate, face backup,
 *                 descarca arhiva zip si o dezarhiveaza peste proiect,
 *                 sarind peste fisierele de configurare locale.
 *
 * Nu foloseste git pe masina clientului si nu atinge fisierele netracked
 * (api/db.local.php, .venv/, logs/, spool/, preview/).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/print_common.php';

// Raspunsurile nu se memoreaza in cache (check/apply trebuie sa fie mereu proaspete).
header('Cache-Control: no-store, no-cache, must-revalidate');

define('UPDATE_REPO', 'abcsoft-ro/Table-Manager');
define('UPDATE_BRANCH', 'main');

$UPDATE_ROOT = dirname(__DIR__);
$UPDATE_STATE_FILE = $UPDATE_ROOT . '/logs/update_state.json';
$UPDATE_BACKUP_DIR = $UPDATE_ROOT . '/logs/backups';

/**
 * Fisiere (relative la radacina) care nu se suprascriu niciodata.
 */
function updateIgnoreExact() {
    return [
        'print-service/config.json',
        'sync-service/config.json',
        'api/db.local.php',
        '.gitignore',
        'github_api.txt',
        'AGENTS.md',
    ];
}

/**
 * Prefixe de cale protejate (runtime / unelte locale).
 */
function updateIgnorePrefixes() {
    return [
        '.git/', '.venv/', 'logs/', '__pycache__/',
        'print-service/logs/', 'print-service/spool/', 'print-service/preview/',
        'sync-service/logs/',
    ];
}

function updateShort($sha) {
    return $sha ? substr($sha, 0, 7) : '';
}

function updateReadState($stateFile) {
    if (!is_file($stateFile)) {
        return [];
    }
    $raw = @file_get_contents($stateFile);
    if ($raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function updateWriteState($stateFile, $sha, $updatedAt) {
    $dir = dirname($stateFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $data = ['sha' => $sha, 'updatedAt' => $updatedAt];
    @file_put_contents($stateFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
}

/**
 * Token GitHub optional (fisierul local github_api.txt), folosit doar ca sa
 * marim limita de apeluri API. Repo-ul este public, deci nu este obligatoriu.
 */
function updateGithubToken($root) {
    $file = $root . '/github_api.txt';
    if (!is_file($file)) {
        return '';
    }
    $content = @file_get_contents($file);
    if ($content === false) {
        return '';
    }
    if (preg_match('/token:\s*([A-Za-z0-9_\-]+)/', $content, $m)) {
        return $m[1];
    }
    $line = trim($content);
    return preg_match('/^[A-Za-z0-9_\-]{20,}$/', $line) ? $line : '';
}

/**
 * GET HTTP. Foloseste cURL daca extensia e disponibila, altfel stream-urile
 * PHP (allow_url_fopen). Intoarce [body, cod_http, eroare].
 */
function updateHttpGet($url, $headers = [], $timeout = 20) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return [null, 0, "Nu pot contacta GitHub: " . $err];
        }
        return [$body, $code, null];
    }

    if (!ini_get('allow_url_fopen')) {
        return [null, 0, "Extensia PHP curl nu este disponibila si allow_url_fopen este oprit."];
    }
    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'header'          => implode("\r\n", $headers),
            'timeout'         => $timeout,
            'follow_location' => 1,
            'max_redirects'   => 5,
            'ignore_errors'   => true,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (!empty($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $code = (int)$m[1];
            }
        }
    }
    if ($body === false) {
        $e = error_get_last();
        return [null, $code, "Nu pot contacta GitHub" . ($e ? ": " . $e['message'] : "")];
    }
    return [$body, $code, null];
}

/**
 * Ultimul SHA de pe branch. Intoarce [sha_complet, null] sau [null, eroare].
 * Repo-ul este public, deci daca tokenul optional (github_api.txt) este
 * invalid/expirat reincercam fara el.
 */
function updateGithubLatestSha($root) {
    $url = 'https://api.github.com/repos/' . UPDATE_REPO . '/commits/' . UPDATE_BRANCH;
    $token = updateGithubToken($root);

    $attempts = $token !== '' ? [$token, ''] : [''];
    $lastError = "Raspuns GitHub neasteptat";
    foreach ($attempts as $authToken) {
        $headers = ['Accept: application/vnd.github+json', 'User-Agent: TableManager-Updater'];
        if ($authToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $authToken;
        }

        list($body, $code, $err) = updateHttpGet($url, $headers, 20);
        if ($body === null) {
            $lastError = $err;
            continue;
        }
        if ($code === 401 || $code === 403) {
            $lastError = "GitHub a raspuns cu HTTP " . $code;
            continue; // token invalid/limitat: reincercam fara el
        }
        if ($code < 200 || $code >= 300) {
            return [null, "GitHub a raspuns cu HTTP " . $code];
        }
        $json = json_decode($body, true);
        if (is_array($json) && !empty($json['sha'])) {
            return [$json['sha'], null];
        }
        $lastError = "Raspuns GitHub neasteptat";
    }
    return [null, $lastError];
}

/**
 * Descarca un URL binar in $dest. Foloseste cURL daca e disponibil, altfel
 * stream-urile PHP. Intoarce null la succes sau mesaj de eroare.
 */
function updateDownload($url, $dest) {
    if (function_exists('curl_init')) {
        $fp = @fopen($dest, 'wb');
        if (!$fp) {
            return "Nu pot scrie fisierul temporar.";
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'TableManager-Updater',
        ]);
        $ok   = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $code < 200 || $code >= 300) {
            @unlink($dest);
            return "Descarcare esuata" . ($err !== '' ? ": " . $err : " (HTTP " . $code . ")");
        }
        return null;
    }

    if (!ini_get('allow_url_fopen')) {
        return "Extensia PHP curl nu este disponibila si allow_url_fopen este oprit.";
    }
    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'header'          => "User-Agent: TableManager-Updater\r\n",
            'timeout'         => 180,
            'follow_location' => 1,
            'max_redirects'   => 5,
            'ignore_errors'   => true,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);
    $in = @fopen($url, 'rb', false, $ctx);
    if (!$in) {
        $e = error_get_last();
        return "Descarcare esuata" . ($e ? ": " . $e['message'] : "");
    }
    $out = @fopen($dest, 'wb');
    if (!$out) {
        fclose($in);
        return "Nu pot scrie fisierul temporar.";
    }
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);

    $code = 0;
    if (!empty($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $code = (int)$m[1];
            }
        }
    }
    if ($code >= 400) {
        @unlink($dest);
        return "Descarcare esuata (HTTP " . $code . ")";
    }
    if (!is_file($dest) || filesize($dest) === 0) {
        @unlink($dest);
        return "Descarcare esuata (fisier gol).";
    }
    return null;
}

/**
 * Verifica daca o cale relativa (cu /) este protejata.
 */
function updateIsIgnored($rel) {
    if (in_array($rel, updateIgnoreExact(), true)) {
        return true;
    }
    foreach (updateIgnorePrefixes() as $prefix) {
        if (strpos($rel, $prefix) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Copiaza un fisier sursa in $target, atomic (tmp + rename).
 */
function updateWriteFile($source, $target) {
    $dir = dirname($target);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return false;
    }
    $data = @file_get_contents($source);
    if ($data === false) {
        return false;
    }
    $tmp = $target . '.upd-tmp';
    if (@file_put_contents($tmp, $data) === false) {
        return false;
    }
    if (!@rename($tmp, $target)) {
        @unlink($target);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return false;
        }
    }
    return true;
}

/**
 * Sterge recursiv un director temporar.
 */
function updateRemoveDir($dir) {
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

/**
 * Verifica parola de update. Intoarce true sau trimite raspunsul de eroare.
 */
function updateCheckParola($conn, $parola) {
    ensureParolaUpdateColumn($conn);
    $stmt = @sqlsrv_query($conn, "SELECT TOP 1 ParolaUpdate FROM tblParola");
    $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    $configurata = $row ? trim((string)$row['ParolaUpdate']) : '';

    if ($configurata === '') {
        sendJsonResponse([
            "status" => "error",
            "message" => "Parola de update nu este configurata (Setari > Parole)."
        ], 400);
    }
    if ($parola === '' || !hash_equals($configurata, $parola)) {
        sendJsonResponse(["status" => "error", "message" => "Parola incorecta"], 401);
    }
    return true;
}

// ------------------------------------------------------------------ GET check
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($_GET['check'])) {
        sendJsonResponse(["status" => "error", "message" => "Ruta necunoscuta"], 404);
    }

    $state = updateReadState($UPDATE_STATE_FILE);
    $current = (string)($state['sha'] ?? '');

    list($latest, $err) = updateGithubLatestSha($UPDATE_ROOT);
    if ($err !== null) {
        sendJsonResponse(["status" => "error", "message" => $err], 502);
    }

    $available = ($current === '' || $current !== $latest);
    sendJsonResponse([
        "status"          => "success",
        "current"         => updateShort($current),
        "latest"          => updateShort($latest),
        "updateAvailable" => $available,
        "message"         => $available
            ? "Exista o versiune noua (" . updateShort($latest) . ")."
            : "Ai deja ultima versiune (" . updateShort($latest) . ").",
    ]);
}

// ----------------------------------------------------------------- POST apply
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

$action = $input['action'] ?? 'apply';
$parola = trim((string)($input['parola'] ?? ''));
$force  = !empty($input['force']);

if ($action !== 'apply') {
    sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}

$conn = getDBConnection();
updateCheckParola($conn, $parola);

$hasZip  = class_exists('ZipArchive');
$hasPhar = class_exists('PharData');
if (!$hasZip && !$hasPhar) {
    sendJsonResponse(["status" => "error", "message" => "Nu exista extensia PHP zip sau phar pentru dezarhivare."], 500);
}

$state   = updateReadState($UPDATE_STATE_FILE);
$oldSha  = (string)($state['sha'] ?? '');

list($latest, $err) = updateGithubLatestSha($UPDATE_ROOT);
if ($err !== null) {
    sendJsonResponse(["status" => "error", "message" => $err], 502);
}

if (!$force && $oldSha !== '' && $oldSha === $latest) {
    sendJsonResponse([
        "status"  => "success",
        "updated" => false,
        "sha"     => updateShort($latest),
        "message" => "Ai deja ultima versiune (" . updateShort($latest) . ").",
    ]);
}

// Alege formatul arhivei in functie de extractorul disponibil (zip sau tar.gz).
$useZip = $hasZip;
$ext = $useZip ? 'zip' : 'tar.gz';
$url = 'https://codeload.github.com/' . UPDATE_REPO . '/' . $ext . '/refs/heads/' . UPDATE_BRANCH;

$tmpArchive = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'tmu_' . uniqid('', true) . '.' . $ext;
$err = updateDownload($url, $tmpArchive);
if ($err !== null) {
    @unlink($tmpArchive);
    sendJsonResponse(["status" => "error", "message" => $err], 502);
}

// Dezarhiveaza intr-un director temporar, apoi copiem din el.
$extractDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'tmu_x_' . uniqid('', true);
if (!@mkdir($extractDir, 0777, true)) {
    @unlink($tmpArchive);
    sendJsonResponse(["status" => "error", "message" => "Nu pot crea directorul temporar."], 500);
}

$okExtract = false;
$extractError = '';
try {
    if ($useZip) {
        $zip = new ZipArchive();
        if ($zip->open($tmpArchive) !== true) {
            $extractError = "arhiva zip nu poate fi deschisa";
        } else {
            $okExtract = $zip->extractTo($extractDir);
            $zip->close();
        }
    } else {
        $phar = new PharData($tmpArchive);
        $phar->extractTo($extractDir);
        unset($phar);
        $okExtract = true;
    }
} catch (Exception $ex) {
    $extractError = $ex->getMessage();
} catch (Throwable $ex) {
    $extractError = $ex->getMessage();
}
@unlink($tmpArchive);

if (!$okExtract) {
    updateRemoveDir($extractDir);
    sendJsonResponse(["status" => "error", "message" => "Arhiva nu poate fi dezarhivata: " . ($extractError ?: 'necunoscut')], 500);
}

// Radacina sursei: scoatem folderul de top (ex. "Table-Manager-main").
$sourceRoot = $extractDir;
$entries = array_values(array_diff(scandir($extractDir), ['.', '..']));
if (count($entries) === 1 && is_dir($extractDir . DIRECTORY_SEPARATOR . $entries[0])) {
    $sourceRoot = $extractDir . DIRECTORY_SEPARATOR . $entries[0];
}
$sourceRootNorm = rtrim(str_replace('\\', '/', $sourceRoot), '/');

// Colecteaza fisierele de scris si cele protejate.
$toWrite = [];   // rel => cale absoluta sursa
$preserved = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $abs = str_replace('\\', '/', $file->getPathname());
    $rel = ltrim(substr($abs, strlen($sourceRootNorm)), '/');
    if ($rel === '' || strpos($rel, '..') !== false) {
        continue;
    }
    if (updateIsIgnored($rel)) {
        $preserved[$rel] = true;
        continue;
    }
    $toWrite[$rel] = $file->getPathname();
}

if (empty($toWrite)) {
    updateRemoveDir($extractDir);
    sendJsonResponse(["status" => "error", "message" => "Arhiva nu contine fisiere de instalat."], 500);
}

// Backup cu fisierele locale care vor fi inlocuite (doar daca zip e disponibil).
$backupRel = null;
$existing = [];
foreach (array_keys($toWrite) as $rel) {
    if (is_file($UPDATE_ROOT . '/' . $rel)) {
        $existing[] = $rel;
    }
}
if (!empty($existing) && $hasZip) {
    if (!is_dir($UPDATE_BACKUP_DIR)) {
        @mkdir($UPDATE_BACKUP_DIR, 0777, true);
    }
    $stamp = date('Ymd-His');
    $backupName = 'pre-update_' . (updateShort($oldSha) ?: 'unknown') . '_' . $stamp . '.zip';
    $backupPath = $UPDATE_BACKUP_DIR . '/' . $backupName;
    $bz = new ZipArchive();
    if ($bz->open($backupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        foreach ($existing as $rel) {
            $bz->addFile($UPDATE_ROOT . '/' . $rel, $rel);
        }
        $bz->close();
        $backupRel = 'logs/backups/' . $backupName;
    }
}

// Copiaza atomic, fisier cu fisier.
$written = 0;
$failed = [];
foreach ($toWrite as $rel => $src) {
    if (updateWriteFile($src, $UPDATE_ROOT . '/' . $rel)) {
        $written++;
    } else {
        $failed[] = $rel;
    }
}
updateRemoveDir($extractDir);

if ($written === 0) {
    sendJsonResponse(["status" => "error", "message" => "Nu am putut scrie niciun fisier. Backup: " . ($backupRel ?: '-')], 500);
}

updateWriteState($UPDATE_STATE_FILE, $latest, date('Y-m-d H:i:s'));

sendJsonResponse([
    "status"        => "success",
    "updated"       => true,
    "from"          => updateShort($oldSha),
    "to"            => updateShort($latest),
    "sha"           => updateShort($latest),
    "filesWritten"  => $written,
    "filesFailed"   => $failed,
    "preserved"     => array_keys($preserved),
    "backup"        => $backupRel,
    "message"       => "Actualizare aplicata (" . updateShort($latest) . "). Reporniti serviciile de tiparire si export, apoi reincarcati pagina."
]);
