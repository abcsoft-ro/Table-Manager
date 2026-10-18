<?php
/**
 * Whitelist de adrese IP pentru accesul la aplicatie (tblSet.IpPermise).
 *
 * Scop: sa nu poata oricine din reteaua locala sa interactioneze cu POS-ul.
 * Verificarea se face la nivel de aplicatie, pentru TOATE cererile web (pagini
 * si endpoint-uri), pentru ca fiecare dintre ele trece prin api/db.php (direct
 * sau prin i18n.php / print_common.php / api_common.php).
 *
 * Reguli:
 *  - localhost (127.0.0.1 / ::1 / ::ffff:127.0.0.1) este PERMIS MEREU si nu
 *    poate fi scos din lista (normalizarea il readauga la fiecare salvare);
 *  - lista goala (sau care contine doar localhost) = DOAR localhost are acces;
 *  - o adresa este permisa daca se potriveste exact sau intra in reteaua CIDR
 *    din lista;
 *  - `0.0.0.0/0` / `::/0` permite orice adresa (modalitate explicita de a
 *    dezactiva temporar restrictia).
 *
 * Setarea este citita din `tblSet` in `logs/ip_whitelist.json` (copia locala),
 * ca verificarea sa fie rapida si sa functioneze si cand baza de date este
 * oprita. Daca nici copia locala nu exista si baza de date nu raspunde, guard-ul
 * lasa accesul liber (fail-open) - altfel inchiderea aplicatiei cu parola nu ar
 * mai functiona exact cand utilizatorul are cea mai mare nevoie de ea.
 *
 * Pe CLI (teste, scripturi de export) verificarea este sarita.
 */

if (!defined('IP_GUARD_LOG_MAX_BYTES')) { define('IP_GUARD_LOG_MAX_BYTES', 2097152); } // 2 MB
if (!defined('IP_GUARD_LOG_KEEP_BYTES')) { define('IP_GUARD_LOG_KEEP_BYTES', 262144); } // ~256 KB

/**
 * Calea copiei locale a listei de IP-uri permise.
 *
 * @return string
 */
function ipGuardLocalPath() {
    return dirname(__DIR__) . '/logs/ip_whitelist.json';
}

/**
 * Normalizeaza o adresa binara IPv4-mapped (::ffff:a.b.c.d) la IPv4.
 *
 * @param string|false $bin
 * @return string|false
 */
function ipGuardNormalizeBin($bin) {
    if ($bin !== false && strlen($bin) === 16
        && substr($bin, 0, 10) === str_repeat("\0", 10)
        && substr($bin, 10, 2) === "\xff\xff") {
        return substr($bin, 12, 4);
    }
    return $bin;
}

/**
 * Adresa clientului curent (REMOTE_ADDR), normalizata (fara IPv4-mapped).
 *
 * @return string
 */
function ipGuardClientIp() {
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ip === '') { return ''; }
    $bin = ipGuardNormalizeBin(@inet_pton($ip));
    if ($bin === false) { return $ip; }
    $out = @inet_ntop($bin);
    return ($out === false) ? $ip : $out;
}

/**
 * True daca adresa este localhost (permisa intotdeauna).
 *
 * @param string $ip
 * @return bool
 */
function ipGuardIsLocal($ip) {
    $bin = ipGuardNormalizeBin(@inet_pton(trim((string)$ip)));
    if ($bin === false) { return false; }
    if ($bin === inet_pton('127.0.0.1')) { return true; }
    return $bin === inet_pton('::1');
}

/**
 * Normalizeaza o intrare din lista (IP sau CIDR). Intoarce null daca e invalida.
 * Accepta si cuvantul `localhost` (devine 127.0.0.1).
 *
 * @param string $entry
 * @return string|null
 */
function ipGuardNormalizeEntry($entry) {
    $entry = trim((string)$entry);
    if ($entry === '') { return null; }
    if (strcasecmp($entry, 'localhost') === 0) { return '127.0.0.1'; }

    if (strpos($entry, '/') === false) {
        $bin = ipGuardNormalizeBin(@inet_pton($entry));
        if ($bin === false) { return null; }
        $out = @inet_ntop($bin);
        return ($out === false) ? null : strtolower($out);
    }

    [$net, $bits] = explode('/', $entry, 2);
    $net = trim($net);
    $bits = trim($bits);
    if (!preg_match('/^\d{1,3}$/', $bits)) { return null; }
    $bin = ipGuardNormalizeBin(@inet_pton($net));
    if ($bin === false) { return null; }
    $bits = (int)$bits;
    $len = strlen($bin) * 8;
    if ($bits < 0 || $bits > $len) { return null; }

    // Adresa de retea (octetii din afara măștii devin 0).
    $full = intdiv($bits, 8);
    $rem = $bits % 8;
    $masked = '';
    for ($i = 0; $i < strlen($bin); $i++) {
        if ($i < $full) {
            $masked .= $bin[$i];
        } elseif ($i === $full && $rem > 0) {
            $masked .= chr(ord($bin[$i]) & ((0xFF << (8 - $rem)) & 0xFF));
        } else {
            $masked .= "\0";
        }
    }
    $out = @inet_ntop($masked);
    return ($out === false) ? null : (strtolower($out) . '/' . $bits);
}

/**
 * Normalizeaza lista completa: localhost este intotdeauna primul, duplicatele
 * sunt eliminate, intrarile invalide sunt raportate in $invalid.
 *
 * @param string $list
 * @param array|null $invalid
 * @return string
 */
function ipGuardNormalizeList($list, &$invalid = null) {
    $invalid = [];
    $out = [];
    $seen = [];
    foreach (['127.0.0.1', '::1'] as $local) {
        $out[] = $local;
        $seen[$local] = true;
    }
    foreach (explode(',', (string)$list) as $raw) {
        $raw = trim((string)$raw);
        if ($raw === '') { continue; }
        $entry = ipGuardNormalizeEntry($raw);
        if ($entry === null) { $invalid[] = $raw; continue; }
        $key = strtolower($entry);
        if (isset($seen[$key])) { continue; }
        $seen[$key] = true;
        $out[] = $entry;
    }
    return implode(',', $out);
}

/**
 * True daca $ip se potriveste cu o singura intrare (IP exact sau CIDR).
 *
 * @param string $ip
 * @param string $entry
 * @return bool
 */
function ipGuardMatchEntry($ip, $entry) {
    $entry = trim((string)$entry);
    if ($entry === '') { return false; }
    $ipBin = ipGuardNormalizeBin(@inet_pton(trim((string)$ip)));
    if ($ipBin === false) { return false; }

    if (strpos($entry, '/') === false) {
        $entryBin = ipGuardNormalizeBin(@inet_pton($entry));
        return ($entryBin !== false && $entryBin === $ipBin);
    }

    [$net, $bits] = explode('/', $entry, 2);
    $netBin = ipGuardNormalizeBin(@inet_pton(trim($net)));
    $bits = trim($bits);
    if ($netBin === false || !preg_match('/^\d{1,3}$/', $bits)) { return false; }
    // Nu comparam IPv4 cu IPv6 (altfel primii octeti s-ar putea potrivi intamplator).
    if (strlen($netBin) !== strlen($ipBin)) { return false; }
    $bits = (int)$bits;
    $len = strlen($netBin) * 8;
    if ($bits < 0 || $bits > $len) { return false; }
    $full = intdiv($bits, 8);
    $rem = $bits % 8;
    if ($full > 0 && substr($ipBin, 0, $full) !== substr($netBin, 0, $full)) { return false; }
    if ($rem > 0) {
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        if ((ord($ipBin[$full]) & $mask) !== (ord($netBin[$full]) & $mask)) { return false; }
    }
    return true;
}

/**
 * True daca $ip are acces conform listei (localhost este mereu permis; lista
 * goala = doar localhost).
 *
 * @param string $ip
 * @param string $list
 * @return bool
 */
function ipGuardIpAllowed($ip, $list) {
    $ip = trim((string)$ip);
    if (ipGuardIsLocal($ip)) { return true; }
    $list = trim((string)$list);
    if ($list === '') { return false; }
    foreach (explode(',', $list) as $entry) {
        if (ipGuardMatchEntry($ip, $entry)) { return true; }
    }
    return false;
}

/**
 * Citeste copia locala a listei. null = lipseste / ilizibila.
 *
 * @return string|null
 */
function ipGuardLocalRead() {
    try {
        $file = ipGuardLocalPath();
        if (!is_file($file)) { return null; }
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data) || !array_key_exists('list', $data)) { return null; }
        return (string)$data['list'];
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Scrie copia locala a listei (atomic, best-effort). Lista este normalizata
 * inainte de scriere, deci localhost este mereu prezent.
 *
 * @param string $list
 * @return bool
 */
function ipGuardLocalWrite($list) {
    try {
        $list = ipGuardNormalizeList($list);
        $json = json_encode(
            ['list' => $list, 'updatedAt' => date('c')],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $file = ipGuardLocalPath();
        $dir = dirname($file);
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        if (is_file($file) && @file_get_contents($file) === $json) { return true; }
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) { return false; }
        if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Lista configurata, pentru verificarea pe fiecare cerere.
 *
 * @return string|null null = nu se poate afla (fail-open)
 */
function ipGuardConfiguredList() {
    static $done = false;
    static $value = null;
    if ($done) { return $value; }
    $done = true;

    $cached = ipGuardLocalRead();
    if ($cached !== null) {
        $value = $cached;
        return $value;
    }

    // Prima cerere dupa instalare/update (copia locala nu exista inca): citim
    // setarea o singura data din baza de date, apoi o pastram local.
    if (!function_exists('getDBConnection')) { return $value; }
    $conn = @getDBConnection(true);
    if (!$conn) { return $value; }

    $list = '127.0.0.1,::1';
    $stmt = @sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'IpPermise'");
    $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    if ($row && trim((string)$row['Value']) !== '') { $list = (string)$row['Value']; }
    $list = ipGuardNormalizeList($list);
    ipGuardLocalWrite($list);
    $value = $list;
    return $value;
}

/**
 * Rescrie copia locala din baza de date (apelat la pornirea aplicatiei si la
 * salvarea Setarilor, ca restrictia sa se aplice imediat).
 *
 * @param resource $conn
 * @return string|null
 */
function ipGuardSyncFromDb($conn) {
    try {
        $list = '127.0.0.1,::1';
        $stmt = @sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'IpPermise'");
        $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
        if ($row && trim((string)$row['Value']) !== '') { $list = (string)$row['Value']; }
        $list = ipGuardNormalizeList($list);
        ipGuardLocalWrite($list);
        return $list;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Limba pentru mesajul de refuz (copia locala, fara baza de date).
 *
 * @return string
 */
function ipGuardLang() {
    try {
        $file = dirname(__DIR__) . '/logs/lang_local.json';
        if (is_file($file)) {
            $data = json_decode((string)@file_get_contents($file), true);
            $lang = is_array($data) ? strtolower(trim((string)($data['lang'] ?? ''))) : '';
            if ($lang === 'en') { return 'en'; }
        }
    } catch (\Throwable $e) {
        // ignorat
    }
    return 'ro';
}

/**
 * Scrie o linie in logs/ip_guard.log (best-effort, cu trunchiere de dimensiune).
 *
 * @param string $ip
 * @return void
 */
function ipGuardLog($ip) {
    try {
        $dir = dirname(__DIR__) . '/logs';
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $file = $dir . '/ip_guard.log';
        if (is_file($file) && @filesize($file) > IP_GUARD_LOG_MAX_BYTES) {
            $fh = @fopen($file, 'r');
            if ($fh) {
                @fseek($fh, -IP_GUARD_LOG_KEEP_BYTES, SEEK_END);
                @fgets($fh);
                $tail = (string)@stream_get_contents($fh);
                @fclose($fh);
                @file_put_contents($file, "[...] jurnal trunchiat (limita de dimensiune)\n" . $tail);
            }
        }
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $uri = substr(str_replace(["\r", "\n"], ' ', $uri), 0, 300);
        $method = substr((string)($_SERVER['REQUEST_METHOD'] ?? ''), 0, 10);
        $line = '[' . date('Y-m-d H:i:s') . '] ip=' . $ip . ' ' . $method . ' ' . $uri
              . ' rezultat=refuzat (nu este in lista IpPermise)' . "\n";
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    } catch (\Throwable $e) {
        // ignorat intentionat: un log nu are voie sa strice raspunsul
    }
}

/**
 * Pagina HTML afisata clientilor blocati (autonoma, fara baza de date).
 *
 * @param string $ip
 * @return string
 */
function ipGuardBlockedHtml($ip) {
    $en = (ipGuardLang() === 'en');
    $ipHtml = htmlspecialchars((string)$ip, ENT_QUOTES, 'UTF-8');
    $title = $en ? 'Access restricted' : 'Acces restrictionat';
    $line1 = $en
        ? 'The IP address <b>%s</b> is not in the allowed list for this application.'
        : 'Adresa IP <b>%s</b> nu este in lista de adrese permise pentru aceasta aplicatie.';
    $line2 = $en
        ? 'Contact the administrator. Localhost (127.0.0.1) is always allowed.'
        : 'Contactati administratorul. Localhost (127.0.0.1) este intotdeauna permis.';
    return '<!DOCTYPE html><html lang="' . ($en ? 'en' : 'ro') . '"><head>'
        . '<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $title . '</title><style>'
        . 'html,body{height:100%;margin:0}'
        . 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;'
        . 'background:#eef1f5;color:#1a1f2b;display:flex;align-items:center;justify-content:center;padding:24px}'
        . '.card{max-width:460px;width:100%;background:#fff;border:1px solid #d7dbe3;border-radius:12px;'
        . 'padding:28px 24px;box-shadow:0 2px 10px rgba(0,0,0,.08);text-align:center}'
        . '.icon{font-size:42px;line-height:1;margin-bottom:14px}'
        . 'h1{font-size:20px;margin:0 0 12px}p{font-size:14px;line-height:1.5;color:#4b5563;margin:8px 0}'
        . 'b{color:#1a1f2b}'
        . '</style></head><body><div class="card"><div class="icon">&#128274;</div>'
        . '<h1>' . $title . '</h1>'
        . '<p>' . sprintf($line1, $ipHtml) . '</p>'
        . '<p>' . $line2 . '</p>'
        . '</div></body></html>';
}

/**
 * Refuza cererea curenta: JSON pentru API, pagina HTML pentru navigare.
 *
 * @param string $ip
 * @return void
 */
function ipGuardRefuse($ip) {
    ipGuardLog($ip);
    $isApi = (strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/api/') !== false)
          || (stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false)
          || (strcasecmp((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0);
    if (!headers_sent()) {
        http_response_code(403);
        header('Cache-Control: no-store');
        if ($isApi) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status' => 'error',
                'code' => 'ip_not_allowed',
                'message' => (ipGuardLang() === 'en')
                    ? 'IP address is not allowed.'
                    : 'Adresa IP nu este permisa.'
            ], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo ipGuardBlockedHtml($ip);
        }
    }
    exit;
}

/**
 * Verificarea propriu-zisa, rulata la includerea api/db.php.
 *
 * @return bool true daca cererea poate continua
 */
function ipGuardCheck() {
    // CLI (teste, export, scripturi de mentenanta): fara restrictie.
    if (PHP_SAPI === 'cli') { return true; }

    $ip = ipGuardClientIp();
    if ($ip === '') { return true; }               // nu putem evalua: fail-open
    if (ipGuardIsLocal($ip)) { return true; }      // localhost: mereu permis

    $list = ipGuardConfiguredList();
    if ($list === null) { return true; }           // baza de date indisponibila: fail-open

    if (ipGuardIpAllowed($ip, $list)) { return true; }
    ipGuardRefuse($ip);
    return false;
}
