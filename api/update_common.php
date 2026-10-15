<?php
/**
 * Helper-e comune pentru actualizarea aplicatiei si rollback
 * (descarcare / arhivare / snapshot / registru / istoric GitHub).
 * Functiile primesc radacina ca parametru; calea backup-ului este
 * globala ($UPDATE_BACKUP_DIR), setata de apelant.
 */

if (!defined('UPDATE_REPO')) { define('UPDATE_REPO', 'abcsoft-ro/Table-Manager'); }
if (!defined('UPDATE_BRANCH')) { define('UPDATE_BRANCH', 'main'); }

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

/**
 * Token GitHub optional (fisierul local github_api.txt), folosit doar ca sa
 * marim limita de apeluri API. Repo-ul este public, deci nu este obligatoriu.
 *
 * @param string $root
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
 *
 * @param string $url
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
 *
 * @param string $root
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
 *
 * @param string $url
 * @param string $dest
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
 *
 * @param string $rel
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
 * Fisiere protejate suplimentar: `api/db.php` este tratat ca fisier de
 * configurare local (poate contine credențialele) cat timp nu exista
 * `api/db.local.php`. Daca `db.local.php` exista, `db.php` este cod obisnuit
 * si se actualizeaza normal.
 *
 * @param string $rel
 * @param string $root
 */
function updatePreserveExtra($rel, $root) {
    return ($rel === 'api/db.php' && !is_file($root . '/api/db.local.php'));
}

/**
 * Copiaza un fisier sursa in $target. Incearca rename atomic (tmp + rename);
 * pe Windows rename-ul esueaza cand tinta e scriptul PHP in curs de executie
 * (api/update.php), asa ca se cade pe copy peste tinta (care functioneaza),
 * fara a sterge niciodata fisierul tinta.
 *
 * @param string $source
 * @param string $target
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

    // 1) rename atomic (functioneaza pe *nix si pe Windows cand tinta nu e blocata).
    if (@rename($tmp, $target)) {
        return true;
    }

    // 2) copy peste tinta: pe Windows functioneaza chiar si pentru scriptul in
    //    curs de executie; nu sterge fisierul tinta.
    for ($i = 0; $i < 3; $i++) {
        if (@copy($tmp, $target)) {
            @unlink($tmp);
            return true;
        }
        usleep(50000);
    }

    // 3) ultima incercare: unlink + rename; daca rename esueaza, refacem tinta
    //    din tmp ca sa nu o lasam lipsa.
    @unlink($target);
    if (@rename($tmp, $target)) {
        return true;
    }
    @copy($tmp, $target);
    @unlink($tmp);
    return false;
}

/**
 * Sterge recursiv un director temporar.
 *
 * @param string $dir
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
 * URL-ul arhivei GitHub pentru un ref (branch sau SHA).
 *
 * @param string $ext 'zip' sau 'tar.gz'
 * @param string $ref ex. 'refs/heads/main' sau un SHA de commit
 */
function updateArchiveUrl($ext, $ref) {
    return 'https://codeload.github.com/' . UPDATE_REPO . '/' . $ext . '/' . $ref;
}

/**
 * Dezarhiveaza o arhiva intr-un director temporar. Intoarce calea directorului
 * sau null (cu eroarea in $err).
 *
 * @param string $archive
 * @param string $ext 'zip' sau 'tar.gz'
 * @param string $err
 * @return string|null
 */
function updateExtractTo($archive, $ext, &$err) {
    $extractDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'tmu_x_' . uniqid('', true);
    if (!@mkdir($extractDir, 0777, true)) {
        $err = "Nu pot crea directorul temporar.";
        return null;
    }
    $ok = false;
    $extractError = '';
    try {
        if ($ext === 'zip') {
            $zip = new ZipArchive();
            if ($zip->open($archive) !== true) {
                $extractError = "arhiva zip nu poate fi deschisa";
            } else {
                $ok = $zip->extractTo($extractDir);
                $zip->close();
            }
        } else {
            $phar = new PharData($archive);
            $phar->extractTo($extractDir);
            unset($phar);
            $ok = true;
        }
    } catch (Exception $ex) {
        $extractError = $ex->getMessage();
    } catch (Throwable $ex) {
        $extractError = $ex->getMessage();
    }
    if (!$ok) {
        updateRemoveDir($extractDir);
        $err = "Arhiva nu poate fi dezarhivata: " . ($extractError ?: 'necunoscut');
        return null;
    }
    return $extractDir;
}

/**
 * Radacina sursei dintr-o arhiva GitHub (scoate folderul de top, ex.
 * "Table-Manager-main" / "Table-Manager-<sha>").
 *
 * @param string $extractDir
 * @return string
 */
function updateSourceRoot($extractDir) {
    $sourceRoot = $extractDir;
    $entries = array_values(array_diff(scandir($extractDir), ['.', '..']));
    if (count($entries) === 1 && is_dir($extractDir . DIRECTORY_SEPARATOR . $entries[0])) {
        $sourceRoot = $extractDir . DIRECTORY_SEPARATOR . $entries[0];
    }
    return $sourceRoot;
}

/**
 * Colecteaza fisierele de scris dintr-o sursa si cele protejate. Intoarce
 * [toWrite (rel => cale absoluta sursa), preserved (rel => true)].
 *
 * @param string $sourceRoot
 * @param string $root
 * @return array
 */
function updateCollectWritableFiles($sourceRoot, $root) {
    $sourceRootNorm = rtrim(str_replace('\\', '/', $sourceRoot), '/');
    $toWrite = [];
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
        if (updateIsIgnored($rel) || updatePreserveExtra($rel, $root)) {
            $preserved[$rel] = true;
            continue;
        }
        $toWrite[$rel] = $file->getPathname();
    }
    return [$toWrite, $preserved];
}

/**
 * Scrie fisierele (rel => sursa) peste $root, atomic. Intoarce [scrise, esuate].
 *
 * @param string $root
 * @param array $toWrite
 * @return array
 */
function updateApplyFiles($root, $toWrite) {
    $written = 0;
    $failed = [];
    foreach ($toWrite as $rel => $src) {
        if (updateWriteFile($src, $root . '/' . $rel)) {
            $written++;
        } else {
            $failed[] = $rel;
        }
    }
    return [$written, $failed];
}

/**
 * Calea registrului de snapshot-uri locale.
 *
 * @return string
 */
function updateBackupIndexPath() {
    global $UPDATE_BACKUP_DIR;
    return $UPDATE_BACKUP_DIR . '/index.json';
}

/**
 * Citeste registrul de snapshot-uri (array; gol daca lipseste/invalid).
 *
 * @return array
 */
function updateReadBackupIndex() {
    $file = updateBackupIndexPath();
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string)@file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

/**
 * Scrie registrul de snapshot-uri.
 *
 * @param array $entries
 */
function updateWriteBackupIndex($entries) {
    global $UPDATE_BACKUP_DIR;
    if (!is_dir($UPDATE_BACKUP_DIR)) {
        @mkdir($UPDATE_BACKUP_DIR, 0777, true);
    }
    @file_put_contents(
        updateBackupIndexPath(),
        json_encode(array_values($entries), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
    );
}

/**
 * Creeaza un snapshot COMPLET al proiectului (fara caile protejate/ignorate)
 * inainte de un update, ca punct de restaurare pentru rollback.
 *
 * @param string $root
 * @param array $meta {sha, short, build, builtAt, version, source}
 * @param int $keep Cate snapshot-uri pastram.
 * @return string|null Numele fisierului creat sau null.
 */
function updateCreateSnapshot($root, $meta, $keep = 5) {
    global $UPDATE_BACKUP_DIR;
    if (!class_exists('ZipArchive')) {
        return null;
    }
    if (!is_dir($UPDATE_BACKUP_DIR)) {
        @mkdir($UPDATE_BACKUP_DIR, 0777, true);
    }
    $stamp = date('Ymd-His');
    $short = (string)($meta['short'] ?? '');
    $file = 'full_' . ($short !== '' ? $short : 'unknown') . '_' . $stamp . '.zip';
    $path = $UPDATE_BACKUP_DIR . '/' . $file;

    $z = new ZipArchive();
    if ($z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return null;
    }
    $rootNorm = rtrim(str_replace('\\', '/', $root), '/');
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        $abs = str_replace('\\', '/', $f->getPathname());
        $rel = ltrim(substr($abs, strlen($rootNorm)), '/');
        if ($rel === '' || strpos($rel, '..') !== false) {
            continue;
        }
        if (updateIsIgnored($rel) || updateIsIgnored($rel . '/') || updatePreserveExtra($rel, $root)) {
            continue;
        }
        if ($f->isDir()) {
            $z->addEmptyDir($rel);
        } else {
            $z->addFile($f->getPathname(), $rel);
        }
    }
    $z->close();

    $entry = array_merge($meta, ['file' => $file, 'createdAt' => date('Y-m-d H:i:s')]);
    $idx = updateReadBackupIndex();
    $idx[] = $entry;
    updateWriteBackupIndex($idx);
    updatePruneSnapshots($keep);
    return $file;
}

/**
 * Pastreaza cele mai recente $keep snapshot-uri (sterge restul: fisier + intrare).
 *
 * @param int $keep
 */
function updatePruneSnapshots($keep = 5) {
    global $UPDATE_BACKUP_DIR;
    $idx = updateReadBackupIndex();
    if (count($idx) <= $keep) {
        return;
    }
    usort($idx, function ($a, $b) {
        return strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''));
    });
    $remove = array_slice($idx, $keep);
    $keepArr = array_slice($idx, 0, $keep);
    foreach ($remove as $e) {
        if (!empty($e['file'])) {
            @unlink($UPDATE_BACKUP_DIR . '/' . basename((string)$e['file']));
        }
    }
    updateWriteBackupIndex($keepArr);
}

/**
 * Ultimele commit-uri de pe branch (pentru lista de versiuni la rollback).
 * Intoarce [] daca GitHub e indisponibil. Fiecare: {sha, short, date, message}.
 *
 * @param string $root
 * @param int $limit
 * @return array
 */
function updateGithubCommits($root, $limit = 15) {
    $url = 'https://api.github.com/repos/' . UPDATE_REPO . '/commits?sha=' . UPDATE_BRANCH . '&per_page=' . (int)$limit;
    $token = updateGithubToken($root);
    $headers = ['Accept: application/vnd.github+json', 'User-Agent: TableManager-Updater'];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    list($body, $code, $err) = updateHttpGet($url, $headers, 20);
    if ($body === null || $code < 200 || $code >= 300) {
        return [];
    }
    $json = json_decode($body, true);
    if (!is_array($json)) {
        return [];
    }
    $out = [];
    foreach ($json as $c) {
        if (empty($c['sha'])) {
            continue;
        }
        $msg = (string)($c['commit']['message'] ?? '');
        $msg = trim(strtok($msg, "\n"));
        $out[] = [
            'sha'     => $c['sha'],
            'short'   => appVersionShort($c['sha']),
            'date'    => substr((string)($c['commit']['author']['date'] ?? ''), 0, 19),
            'message' => $msg,
        ];
    }
    return $out;
}
