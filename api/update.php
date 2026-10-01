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

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $lastError = "Nu pot contacta GitHub: " . $err;
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
 * Descarca un URL binar in $dest. Intoarce null la succes sau mesaj de eroare.
 */
function updateDownload($url, $dest) {
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
 * Scrie un entry din arhiva in $target, atomic (tmp + rename).
 */
function updateWriteEntry($zip, $index, $target) {
    $dir = dirname($target);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return false;
    }
    $data = $zip->getFromIndex($index);
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

if (!class_exists('ZipArchive')) {
    sendJsonResponse(["status" => "error", "message" => "Extensia PHP zip nu este disponibila."], 500);
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

// Descarca arhiva zip a branch-ului.
$tmpZip = tempnam(sys_get_temp_dir(), 'tmu');
$url = 'https://codeload.github.com/' . UPDATE_REPO . '/zip/refs/heads/' . UPDATE_BRANCH;
$err = updateDownload($url, $tmpZip);
if ($err !== null) {
    sendJsonResponse(["status" => "error", "message" => $err], 502);
}

$zip = new ZipArchive();
if ($zip->open($tmpZip) !== true) {
    @unlink($tmpZip);
    sendJsonResponse(["status" => "error", "message" => "Arhiva descarcata nu poate fi deschisa."], 500);
}

// Determina prefixul (ex. "Table-Manager-main/") si lista fisierelor de scris.
$prefix = '';
$toWrite = [];   // rel => index
$preserved = []; // fisiere protejate gasite in arhiva
for ($i = 0; $i < $zip->numFiles; $i++) {
    $stat = $zip->statIndex($i);
    if (!$stat) {
        continue;
    }
    $name = str_replace('\\', '/', (string)$stat['name']);
    $name = ltrim($name, '/');
    if ($name === '' || substr($name, -1) === '/') {
        continue; // director
    }
    if ($prefix === '') {
        $slash = strpos($name, '/');
        if ($slash !== false) {
            $prefix = substr($name, 0, $slash + 1);
        }
    }
    $rel = ($prefix !== '' && strpos($name, $prefix) === 0) ? substr($name, strlen($prefix)) : $name;
    if ($rel === '' || strpos($rel, '..') !== false) {
        continue;
    }
    if (updateIsIgnored($rel)) {
        $preserved[$rel] = true;
        continue;
    }
    $toWrite[$rel] = $i;
}

if (empty($toWrite)) {
    $zip->close();
    @unlink($tmpZip);
    sendJsonResponse(["status" => "error", "message" => "Arhiva nu contine fisiere de instalat."], 500);
}

// Backup cu fisierele locale care vor fi inlocuite.
$backupPath = null;
$backupRel = null;
$existing = [];
foreach (array_keys($toWrite) as $rel) {
    if (is_file($UPDATE_ROOT . '/' . $rel)) {
        $existing[] = $rel;
    }
}
if (!empty($existing)) {
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
    } else {
        $backupPath = null;
    }
}

// Dezarhiveaza atomic, fisier cu fisier.
$written = 0;
$failed = [];
foreach ($toWrite as $rel => $index) {
    if (updateWriteEntry($zip, $index, $UPDATE_ROOT . '/' . $rel)) {
        $written++;
    } else {
        $failed[] = $rel;
    }
}
$zip->close();
@unlink($tmpZip);

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
