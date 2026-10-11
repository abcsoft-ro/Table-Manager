<?php
/**
 * Helper-e comune pentru versiunea aplicatiei.
 *
 * Sursa de adevar pentru versiune:
 *   - fisierul `VERSION` de la radacina proiectului (versiune human, ex. "1.5.0",
 *     bumpita manual la release);
 *   - `logs/update_state.json` (identitatea masina: SHA commit, numar de build,
 *     branch, data) — scris de installer (source "setup"), de self-update
 *     (source "update") sau de mediul de dezvoltare (source "dev").
 *
 * Log durabil: `logs/version.log` (o linie la fiecare schimbare de versiune).
 */

/**
 * Radacina proiectului (folderul care contine `api/`).
 *
 * @return string
 */
function appVersionRoot() {
    return dirname(__DIR__);
}

/**
 * Calea fisierului de stare a versiunii.
 *
 * @return string
 */
function appVersionStatePath() {
    return appVersionRoot() . '/logs/update_state.json';
}

/**
 * Calea fisierului cu versiunea human.
 *
 * @return string
 */
function appVersionHumanPath() {
    return appVersionRoot() . '/VERSION';
}

/**
 * Citeste versiunea human din fisierul VERSION. Intoarce '' daca lipseste/invalid.
 *
 * @return string
 */
function appVersionReadHuman() {
    $file = appVersionHumanPath();
    if (!is_file($file)) {
        return '';
    }
    $raw = @file_get_contents($file);
    if ($raw === false) {
        return '';
    }
    $v = trim(preg_replace('/\s+/', ' ', (string)$raw));
    if ($v === '' || !preg_match('/^[0-9A-Za-z._\-]{1,20}$/', $v)) {
        return '';
    }
    return $v;
}

/**
 * Citeste starea versiunii din logs/update_state.json. Intoarce array (eventual gol).
 *
 * @return array
 */
function appVersionReadState() {
    $file = appVersionStatePath();
    if (!is_file($file)) {
        return [];
    }
    $raw = @file_get_contents($file);
    if ($raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Forma scurta (7 caractere) a unui SHA.
 *
 * @param string $sha
 * @return string
 */
function appVersionShort($sha) {
    $sha = (string)$sha;
    return $sha !== '' ? substr($sha, 0, 7) : '';
}

/**
 * Scrie starea versiunii atomic (tmp + rename).
 *
 * @param array $info
 * @return bool
 */
function appVersionWriteState(array $info) {
    $file = appVersionStatePath();
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return false;
    }
    $json = json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, $json) === false) {
        return false;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Adauga o linie in logs/version.log.
 *
 * @param string $message
 * @return void
 */
function appVersionLog($message) {
    $file = appVersionRoot() . '/logs/version.log';
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return;
    }
    @file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", FILE_APPEND);
}

/**
 * Versiunea completa (human + identitate masina).
 *
 * @return array
 */
function appVersionInfo() {
    $state = appVersionReadState();
    $sha = (string)($state['sha'] ?? '');
    return [
        'version'   => appVersionReadHuman(),
        'commit'    => $sha,
        'short'     => (string)($state['short'] ?? appVersionShort($sha)),
        'build'     => isset($state['build']) && $state['build'] !== '' ? (int)$state['build'] : null,
        'branch'    => (string)($state['branch'] ?? ''),
        'builtAt'   => (string)($state['builtAt'] ?? ''),
        'updatedAt' => (string)($state['updatedAt'] ?? ''),
        'source'    => (string)($state['source'] ?? ''),
    ];
}

/**
 * Eticheta scurta pentru UI, ex. "v1.5.0 · abc1234 · build 128 · 2026-10-11".
 *
 * @return string
 */
function appVersionLabel() {
    $i = appVersionInfo();
    $parts = [];
    if ($i['version'] !== '') { $parts[] = 'v' . $i['version']; }
    if ($i['short'] !== '')   { $parts[] = $i['short']; }
    if ($i['build'] !== null) { $parts[] = 'build ' . $i['build']; }
    $date = $i['builtAt'] !== '' ? $i['builtAt'] : $i['updatedAt'];
    if ($date !== '') { $parts[] = substr($date, 0, 10); }
    return empty($parts) ? 'versiune necunoscuta' : implode(' · ', $parts);
}
