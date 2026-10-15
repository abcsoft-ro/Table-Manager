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
require_once __DIR__ . '/version_common.php';

// Raspunsurile nu se memoreaza in cache (check/apply trebuie sa fie mereu proaspete).
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/update_common.php';

$UPDATE_ROOT = dirname(__DIR__);
$UPDATE_BACKUP_DIR = $UPDATE_ROOT . '/logs/backups';


/**
 * Verifica parola de update. Intoarce true sau trimite raspunsul de eroare.
 *
 * @param resource $conn
 * @param string $parola
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

// ------------------------------------------------- GET check / list (rollback)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Lista de puncte de restaurare locale + versiuni GitHub (pt. rollback).
    if (isset($_GET['list'])) {
        $state = appVersionReadState();
        $idx = updateReadBackupIndex();
        usort($idx, function ($a, $b) {
            return strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''));
        });
        $local = [];
        foreach ($idx as $e) {
            $local[] = [
                'id'        => (string)($e['file'] ?? ''),
                'sha'       => (string)($e['sha'] ?? ''),
                'short'     => (string)($e['short'] ?? ''),
                'build'     => $e['build'] ?? null,
                'builtAt'   => (string)($e['builtAt'] ?? ''),
                'version'   => (string)($e['version'] ?? ''),
                'source'    => (string)($e['source'] ?? ''),
                'createdAt' => (string)($e['createdAt'] ?? ''),
            ];
        }
        $github = [];
        try {
            $github = updateGithubCommits($UPDATE_ROOT, 15);
        } catch (Throwable $ex) {
            $github = [];
        }
        sendJsonResponse([
            "status"  => "success",
            "version" => appVersionReadHuman(),
            "label"   => appVersionLabel(),
            "current" => appVersionShort((string)($state['sha'] ?? '')),
            "local"   => $local,
            "github"  => $github,
        ]);
    }

    if (!isset($_GET['check'])) {
        sendJsonResponse(["status" => "error", "message" => "Ruta necunoscuta"], 404);
    }

    $state = appVersionReadState();
    $current = (string)($state['sha'] ?? '');

    list($latest, $err) = updateGithubLatestSha($UPDATE_ROOT);
    if ($err !== null) {
        sendJsonResponse(["status" => "error", "message" => $err], 502);
    }

    $available = ($current === '' || $current !== $latest);
    sendJsonResponse([
        "status"          => "success",
        "version"         => appVersionReadHuman(),
        "label"           => appVersionLabel(),
        "current"         => appVersionShort($current),
        "latest"          => appVersionShort($latest),
        "updateAvailable" => $available,
        "message"         => $available
            ? "Exista o versiune noua (" . appVersionShort($latest) . ")."
            : "Ai deja ultima versiune (" . appVersionShort($latest) . ").",
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

// -------------------------------------------------------- POST rollback
if ($action === 'rollback') {
    $conn = getDBConnection();
    // Aceeasi parola ca la update (tblParola.ParolaUpdate).
    updateCheckParola($conn, $parola);

    $hasZip  = class_exists('ZipArchive');
    $hasPhar = class_exists('PharData');
    if (!$hasZip && !$hasPhar) {
        sendJsonResponse(["status" => "error", "message" => "Nu exista extensia PHP zip sau phar pentru dezarhivare."], 500);
    }
    $ext = $hasZip ? 'zip' : 'tar.gz';

    $state  = appVersionReadState();
    $oldSha = (string)($state['sha'] ?? '');

    $id  = trim((string)($input['id'] ?? ''));
    $sha = trim((string)($input['sha'] ?? ''));
    $newMeta = ['sha' => '', 'short' => '', 'build' => null, 'builtAt' => '', 'version' => ''];

    $archivePath = null;
    $archiveExt  = 'zip';
    $tmpArchive  = null;

    if ($id !== '') {
        // Punct de restaurare local.
        $idx = updateReadBackupIndex();
        $entry = null;
        foreach ($idx as $e) {
            if ((string)($e['file'] ?? '') === $id) {
                $entry = $e;
                break;
            }
        }
        if (!$entry) {
            sendJsonResponse(["status" => "error", "message" => "Punctul de restaurare nu exista."], 404);
        }
        $file = basename((string)$entry['file']);
        $path = $UPDATE_BACKUP_DIR . '/' . $file;
        if (!is_file($path)) {
            sendJsonResponse(["status" => "error", "message" => "Fisierul punctului de restaurare lipseste."], 404);
        }
        $archivePath = $path;
        $archiveExt  = (strtolower((string)pathinfo($file, PATHINFO_EXTENSION)) === 'zip') ? 'zip' : 'tar.gz';
        $newMeta = [
            'sha'     => (string)($entry['sha'] ?? ''),
            'short'   => (string)($entry['short'] ?? ''),
            'build'   => $entry['build'] ?? null,
            'builtAt' => (string)($entry['builtAt'] ?? ''),
            'version' => (string)($entry['version'] ?? ''),
        ];
    } elseif ($sha !== '') {
        // Versiune din istoricul GitHub, dupa SHA.
        if (!preg_match('/^[0-9a-fA-F]{7,40}$/', $sha)) {
            sendJsonResponse(["status" => "error", "message" => "SHA invalid."], 400);
        }
        $url = updateArchiveUrl($ext, $sha);
        $tmpArchive = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'tmr_' . uniqid('', true) . '.' . $ext;
        $err = updateDownload($url, $tmpArchive);
        if ($err !== null) {
            @unlink($tmpArchive);
            sendJsonResponse(["status" => "error", "message" => $err], 502);
        }
        $archivePath = $tmpArchive;
        $archiveExt  = $ext;
        $newMeta = ['sha' => $sha, 'short' => appVersionShort($sha), 'build' => null, 'builtAt' => '', 'version' => ''];
    } else {
        sendJsonResponse(["status" => "error", "message" => "Selectati un punct de restaurare sau o versiune."], 400);
    }

    $exErr = '';
    $extractDir = updateExtractTo($archivePath, $archiveExt, $exErr);
    if ($tmpArchive !== null) {
        @unlink($tmpArchive);
    }
    if ($extractDir === null) {
        sendJsonResponse(["status" => "error", "message" => $exErr], 500);
    }

    $sourceRoot = updateSourceRoot($extractDir);
    list($toWrite, $preserved) = updateCollectWritableFiles($sourceRoot, $UPDATE_ROOT);
    if (empty($toWrite)) {
        updateRemoveDir($extractDir);
        sendJsonResponse(["status" => "error", "message" => "Arhiva nu contine fisiere de instalat."], 500);
    }
    list($written, $failed) = updateApplyFiles($UPDATE_ROOT, $toWrite);
    updateRemoveDir($extractDir);

    if ($written === 0) {
        sendJsonResponse(["status" => "error", "message" => "Nu am putut scrie niciun fisier."], 500);
    }

    appVersionWriteState([
        'sha'       => $newMeta['sha'],
        'short'     => $newMeta['short'],
        'build'     => $newMeta['build'],
        'branch'    => UPDATE_BRANCH,
        'builtAt'   => $newMeta['builtAt'],
        'updatedAt' => date('Y-m-d H:i:s'),
        'source'    => 'rollback',
    ]);
    $newLabel = appVersionLabel();
    appVersionLog('ROLLBACK: ' . $newLabel . ' source=rollback');

    sendJsonResponse([
        "status"       => "success",
        "rolledback"   => true,
        "from"         => appVersionShort($oldSha),
        "to"           => $newMeta['short'],
        "sha"          => $newMeta['short'],
        "version"      => appVersionReadHuman(),
        "label"        => $newLabel,
        "filesWritten" => $written,
        "filesFailed"  => $failed,
        "preserved"    => array_keys($preserved),
        "message"      => "Rollback aplicat (" . $newLabel . "). Reporniti serviciile de tiparire si export, apoi reincarcati pagina.",
    ]);
}

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

$state   = appVersionReadState();
$oldSha  = (string)($state['sha'] ?? '');

list($latest, $err) = updateGithubLatestSha($UPDATE_ROOT);
if ($err !== null) {
    sendJsonResponse(["status" => "error", "message" => $err], 502);
}

if (!$force && $oldSha !== '' && $oldSha === $latest) {
    sendJsonResponse([
        "status"  => "success",
        "updated" => false,
        "sha"     => appVersionShort($latest),
        "version" => appVersionReadHuman(),
        "label"   => appVersionLabel(),
        "message" => "Ai deja ultima versiune (" . appVersionShort($latest) . ").",
    ]);
}

// Alege formatul arhivei in functie de extractorul disponibil (zip sau tar.gz).
$useZip = $hasZip;
$ext = $useZip ? 'zip' : 'tar.gz';
$url = updateArchiveUrl($ext, 'refs/heads/' . UPDATE_BRANCH);

$tmpArchive = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'tmu_' . uniqid('', true) . '.' . $ext;
$err = updateDownload($url, $tmpArchive);
if ($err !== null) {
    @unlink($tmpArchive);
    sendJsonResponse(["status" => "error", "message" => $err], 502);
}

// Dezarhiveaza intr-un director temporar, apoi copiem din el.
$exErr = '';
$extractDir = updateExtractTo($tmpArchive, $ext, $exErr);
@unlink($tmpArchive);
if ($extractDir === null) {
    sendJsonResponse(["status" => "error", "message" => $exErr], 500);
}

$sourceRoot = updateSourceRoot($extractDir);

// Colecteaza fisierele de scris si cele protejate.
list($toWrite, $preserved) = updateCollectWritableFiles($sourceRoot, $UPDATE_ROOT);

if (empty($toWrite)) {
    updateRemoveDir($extractDir);
    sendJsonResponse(["status" => "error", "message" => "Arhiva nu contine fisiere de instalat."], 500);
}

// Snapshot COMPLET al versiunii curente (punct de restaurare pt. rollback),
// inainte de a scrie noua versiune.
$backupRel = null;
$snapFile = updateCreateSnapshot($UPDATE_ROOT, [
    'sha'     => $oldSha,
    'short'   => appVersionShort($oldSha),
    'build'   => $state['build'] ?? null,
    'builtAt' => $state['builtAt'] ?? '',
    'version' => appVersionReadHuman(),
    'source'  => (string)($state['source'] ?? ''),
], 5);
if ($snapFile !== null) {
    $backupRel = 'logs/backups/' . $snapFile;
}

// Copiaza atomic, fisier cu fisier.
list($written, $failed) = updateApplyFiles($UPDATE_ROOT, $toWrite);
updateRemoveDir($extractDir);

if ($written === 0) {
    sendJsonResponse(["status" => "error", "message" => "Nu am putut scrie niciun fisier. Backup: " . ($backupRel ?: '-')], 500);
}

// Starea versiunii: pastram build/branch/builtAt existente daca arhiva nu le
// aduce (update-ul prin GitHub nu expune numarul de build); actualizam SHA-ul,
// data aplicarii si sursa.
$prevState = appVersionReadState();
appVersionWriteState([
    'sha'       => $latest,
    'short'     => appVersionShort($latest),
    'build'     => $prevState['build'] ?? null,
    'branch'    => UPDATE_BRANCH,
    'builtAt'   => $prevState['builtAt'] ?? '',
    'updatedAt' => date('Y-m-d H:i:s'),
    'source'    => 'update',
]);
$newLabel = appVersionLabel();
appVersionLog('VERSION SET: ' . $newLabel . ' source=update');

sendJsonResponse([
    "status"        => "success",
    "updated"       => true,
    "from"          => appVersionShort($oldSha),
    "to"            => appVersionShort($latest),
    "sha"           => appVersionShort($latest),
    "version"       => appVersionReadHuman(),
    "label"         => $newLabel,
    "filesWritten"  => $written,
    "filesFailed"   => $failed,
    "preserved"     => array_keys($preserved),
    "backup"        => $backupRel,
    "message"       => "Actualizare aplicata (" . appVersionShort($latest) . "). Reporniti serviciile de tiparire si export, apoi reincarcati pagina."
]);
