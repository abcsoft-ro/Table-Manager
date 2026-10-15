<?php
/**
 * Internationalizare (i18n) TableManager: romana (ro) / engleza (en).
 *
 * Catalogul unic de traduceri sta in api/lang/<limba>.php si este folosit
 * atat de PHP (functia t()) cat si de JavaScript (injecția i18nJs()).
 *
 * Limba activa este tblSet.Limba (grup 'General'); valoarea implicita/fallback
 * este 'ro'.
 */
require_once __DIR__ . '/db.php';

if (!defined('APP_LANG_DEFAULT')) { define('APP_LANG_DEFAULT', 'ro'); }
if (!defined('APP_LANGS')) { define('APP_LANGS', 'ro,en'); }

/**
 * Lista limbilor suportate.
 *
 * @return string[]
 */
function appLangList() {
    return ['ro', 'en'];
}

/**
 * Limba activa din tblSet.Limba. Daca baza de date nu este disponibila sau
 * setarea lipseste, revine la limba implicita (ro). Rezultatul este cache-uit
 * pe durata cererii.
 *
 * @param resource|null $conn Conexiune SQL Server deja deschisa (optional).
 * @return string
 */
function appLang($conn = null) {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $lang = APP_LANG_DEFAULT;
    $c = $conn ?: getDBConnection(true);
    if ($c) {
        $stmt = @sqlsrv_query($c, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'Limba'");
        if ($stmt) {
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            if ($row) {
                $v = strtolower(trim((string)$row['Value']));
                if (in_array($v, appLangList(), true)) { $lang = $v; }
            }
        }
    }
    $cached = $lang;
    return $lang;
}

/**
 * Catalogul de traduceri pentru o limba (array cheie => text). Cache-uit.
 *
 * @param string|null $lang
 * @return array<string,string>
 */
function i18nCatalog($lang = null) {
    static $cache = [];
    $lang = $lang ?: appLang();
    if (!in_array($lang, appLangList(), true)) { $lang = APP_LANG_DEFAULT; }
    if (!array_key_exists($lang, $cache)) {
        $file = __DIR__ . '/lang/' . $lang . '.php';
        $cache[$lang] = is_file($file) ? (array)require $file : [];
    }
    return $cache[$lang];
}

/**
 * Traduce o cheie. Placeholderele folosesc stilul {nume} si sunt inlocuite din
 * $params (ex. t('msg.eroare', ['detaliu' => $x])). Daca cheia lipseste,
 * intoarce cheia insasi (usor de depistat in UI).
 *
 * @param string $key
 * @param array<string,string|int> $params
 * @return string
 */
function i18nFill($s, $params) {
    if (empty($params)) { return (string)$s; }
    $map = [];
    foreach ($params as $k => $v) {
        $map['{' . $k . '}'] = (string)$v;
    }
    return strtr((string)$s, $map);
}

function t($key, $params = []) {
    $cat = i18nCatalog();
    $s = array_key_exists($key, $cat) ? $cat[$key] : $key;
    return i18nFill($s, $params);
}

/**
 * Traducere gettext-style pentru mesaje: intai potrivire exacta pe textul-sursa
 * (romana), apoi cea mai lunga cheie care este prefix al textului si se termina
 * cu separator (ex. "Eroare la salvare: " + detaliu). Restul este pastrat.
 * Folosita central in sendJsonResponse() pentru campul "message".
 *
 * @param string $text
 * @param array<string,string|int> $params
 * @return string
 */
function tr($text, $params = []) {
    if (!is_string($text) || $text === '') { return $text; }
    $cat = i18nCatalog();
    if (array_key_exists($text, $cat)) {
        return i18nFill($cat[$text], $params);
    }
    $len = strlen($text);
    $bestKey = null;
    foreach ($cat as $k => $v) {
        $kl = strlen($k);
        if ($kl >= $len) { continue; }
        if (preg_match('/[A-Za-z0-9]$/', $k)) { continue; }
        if (strncmp($text, $k, $kl) === 0) {
            if ($bestKey === null || $kl > strlen($bestKey)) { $bestKey = $k; }
        }
    }
    if ($bestKey !== null) {
        return i18nFill($cat[$bestKey], $params) . substr($text, strlen($bestKey));
    }
    return $text;
}

/**
 * Injecteaza limba si catalogul in pagina, pentru JS (js/i18n.js).
 *
 * @param string|null $lang
 * @return string
 */
function i18nJs($lang = null) {
    $lang = $lang ?: appLang();
    $catalog = i18nCatalog($lang);
    $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    return '<script>window.LANG=' . json_encode($lang)
        . ';window.I18N=' . json_encode($catalog, $flags) . ';</script>';
}
