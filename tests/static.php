<?php
/**
 * Faza A - verificari statice / contract (risc zero).
 *   A2 JSON valid, A3 OpenAPI vs rute v1, A4 i18n, A5 contract DOM,
 *   A6 actiuni API client vs server, A7 drift documentatie.
 */
require __DIR__ . '/lib.php';

$R = ROOT_DIR;

function stripComments(string $s): string {
    $out = [];
    foreach (explode("\n", $s) as $ln) {
        $t = ltrim($ln);
        if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*') || str_starts_with($t, '#')) continue;
        $out[] = $ln;
    }
    return implode("\n", $out);
}

/* ------------------------------------------------------------------ A2 JSON */
section('A2 - validitate JSON');
foreach (['docs/openapi.json', 'print-service/config.json', 'sync-service/config.json', 'logs/update_state.json'] as $f) {
    if (!is_file("$R/$f")) { test("JSON exista: $f", false, 'lipseste'); continue; }
    json_decode((string)file_get_contents("$R/$f"), true);
    test("JSON valid: $f", json_last_error() === JSON_ERROR_NONE, json_last_error_msg());
}

/* ------------------------------------------------- A3 OpenAPI vs rute v1 */
section('A3 - OpenAPI vs rute API v1');
$spec = json_decode((string)@file_get_contents("$R/docs/openapi.json"), true);
$read = (string)@file_get_contents("$R/api/v1/read.php");
$write = (string)@file_get_contents("$R/api/v1/write.php");
$segments = [];
foreach (array_keys($spec['paths'] ?? []) as $p) {
    $s = explode('/', preg_replace('#^/api/v1/#', '', $p))[0];
    $segments[$s][] = $p;
}
test('openapi.json are paths', count($segments) > 0, 'paths=' . count($segments));
foreach ($segments as $seg => $paths) {
    if ($seg === '' || str_starts_with($seg, '{') || $seg === 'version') { if ($seg === 'version') { continue; } continue; }
    $found = str_contains($read, "'$seg'") || str_contains($write, "'$seg'") || str_contains($read, "\"$seg\"") || str_contains($write, "\"$seg\"");
    test("ruta v1 implementata: $seg", $found, implode(',', $paths));
}

/* ---------------------------------------------------------------- A4 i18n */
section('A4 - i18n RO/EN');
$ro = include "$R/api/lang/ro.php";
$en = include "$R/api/lang/en.php";
function flatten($a, $p = '') { $o = []; foreach ($a as $k => $v) { $key = $p === '' ? $k : "$p.$k"; if (is_array($v)) $o += flatten($v, $key); else $o[$key] = $v; } return $o; }
$roF = flatten($ro); $enF = flatten($en);
test('ro ⊆ en (nicio cheie ro lipsa din en)', count(array_diff_key($roF, $enF)) === 0, implode(', ', array_slice(array_keys(array_diff_key($roF, $enF)), 0, 10)));
info('chei ro=' . count($roF) . ' en=' . count($enF));

$srcs = array_merge(glob("$R/api/*.php"), glob("$R/api/**/*.php"), ["$R/index.php", "$R/mobile.php"], glob("$R/js/*.js"));
$miss = [];
foreach ($srcs as $f) {
    $s = stripComments((string)@file_get_contents($f));
    if (preg_match_all('/\b(?:t|tr|trText)\(\s*[\'"]([^\'"\n]{2,})[\'"]/u', $s, $m)) {
        foreach ($m[1] as $k) {
            if (str_contains($k, '$') || str_contains($k, '{')) continue;
            if (!array_key_exists($k, $enF)) $miss[$k][] = basename($f);
        }
    }
}
test('fiecare literal t()/tr()/trText() de cod are traducere in en', count($miss) === 0, implode(' | ', array_map(fn($k) => "\"$k\"(" . implode(',', array_unique($miss[$k])) . ')', array_slice(array_keys($miss), 0, 12))));
if ($miss) { info('total literali netradusi: ' . count($miss) . ' (multe in js/mobile.js: ' . substr_count(implode(',', array_map(fn($v) => implode(',', $v), $miss)), 'mobile.js') . ')'); }

// A4c: mesajele POS bancar (dialogul Cancel/Retry/Ignore) vin din textul
// dinamic al driverului / din api/pos_banca.php, deci nu apar ca literali tr()
// in cod. Verificam explicit ca textele canonice au traducere in en, altfel
// modul EN afiseaza romana (ex. vechiul "Nu s-a putut conecta la POS prin WIFI.").
$bancaMsgs = [
    'Nu s-a putut conecta la terminalul bancar (POS).',
    'Eroare de comunicatie cu terminalul bancar (POS).',
    'Eroare la lansarea driverului terminalului bancar (POS).',
    'Nu gasesc executabilul terminalului bancar (POS): ',
    'Nu pot lansa driverul terminalului bancar (POS): ',
    'Terminalul bancar (POS) nu a returnat rezultat in timp util.',
    'Python sau script lipsa',
    'Eroare comunicare POS bancar: ',
];
$missBanca = array_values(array_filter($bancaMsgs, fn($k) => !array_key_exists($k, $enF)));
test('mesajele POS bancar au traducere in en', count($missBanca) === 0, implode(' | ', $missBanca));

$htmlAll = (string)@file_get_contents("$R/index.php") . (string)@file_get_contents("$R/mobile.php");
preg_match_all('/data-i18n(?:-html|-title|-placeholder)?=["\']([^"\']+)["\']/', $htmlAll, $m2);
$dk = array_unique($m2[1]);
$missEn = array_values(array_filter($dk, fn($k) => !array_key_exists($k, $enF)));
$missRo = array_values(array_filter($dk, fn($k) => !array_key_exists($k, $roF)));
test('fiecare data-i18n are traducere in en', count($missEn) === 0, count($missEn) . ' lipsa: ' . implode(', ', array_slice($missEn, 0, 10)));
info('data-i18n distincte=' . count($dk) . ', lipsa_din_en=' . count($missEn) . ', lipsa_din_ro=' . count($missRo) . ' (ro=identitate)');

// A4b: text static vizibil (in afara <script>/<style>) care ar trebui tradus
// dar nu are data-i18n -> rămâne in romana in modul EN.
$htmlNoJs = preg_replace('#(?is)<script.*?</script>#', '', $htmlAll);
$htmlNoJs = preg_replace('#(?is)<style.*?</style>#', '', $htmlNoJs);
$htmlNoJs = preg_replace('#(?is)<!--.*?-->#', '', $htmlNoJs);
preg_match_all('#<([a-zA-Z][^>]*)>([^<>]{2,60})<#', $htmlNoJs, $mt);
$hard = [];
foreach ($mt[2] as $i => $txt) {
    $txt = trim($txt);
    $tag = $mt[1][$i];
    if ($txt === '' || str_contains($tag, 'data-i18n')) continue;
    if (isset($enF[$txt]) && $enF[$txt] !== $txt) { $hard[$txt] = ($hard[$txt] ?? 0) + 1; }
}
test('text static translatabil fara data-i18n', count($hard) === 0, json_encode($hard));

/* -------------------------------------------------------- A5 contract DOM */
section('A5 - contract DOM (id-uri + handler-e inline)');
$htmlAllStripped = stripComments($htmlAll);
$htmlIds = [];
preg_match_all('/\bid=["\']([^"\']+)["\']/', $htmlAllStripped, $mi);
foreach ($mi[1] as $id) $htmlIds[$id] = true;

$jsAll = '';
foreach (glob("$R/js/*.js") as $f) $jsAll .= stripComments((string)@file_get_contents($f)) . "\n";
// id-uri create dinamic de JS: id="x${..}" / .id = "x" + ...
foreach ([['/\bid=["\']([^"\'$+]+)/', 1], ['/\.id\s*=\s*["\']([^"\'$+]+)/', 1]] as $pat) {
    preg_match_all($pat[0], $jsAll, $mj);
    foreach ($mj[$pat[1]] as $id) $htmlIds[trim($id)] = true;
}
$known = array_keys($htmlIds);

preg_match_all('/getElementById\(\s*[\'"]([^\'"]+)[\'"]/', $jsAll, $mg);
$used = array_unique($mg[1]);
$dangling = [];
foreach ($used as $u) {
    if (isset($htmlIds[$u])) continue;
    $prefixOk = false;
    foreach ($known as $k) { if ($k !== $u && (str_starts_with($k, $u) || str_starts_with($u, $k))) { $prefixOk = true; break; } }
    // "btn-pay-fp-" + fpid : prefixul e o cheie cunoscuta?
    if (!$prefixOk) $dangling[] = $u;
}
test('fiecare getElementById static exista in DOM (HTML sau creat de JS)', count($dangling) === 0, implode(', ', $dangling));
info('id-uri cunoscute=' . count($htmlIds) . ', getElementById=' . count($used));

$reserved = ['if', 'for', 'while', 'return', 'var', 'let', 'const', 'this', 'function', 'new', 'typeof', 'switch', 'catch', 'else'];
preg_match_all('/\bon(?:click|change|input|keydown|keyup|submit|blur|focus)=["\']([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $htmlAllStripped, $mh);
$handlers = array_values(array_unique(array_filter($mh[1], fn($h) => !in_array($h, $reserved, true))));
$undef = [];
foreach ($handlers as $h) {
    if (!preg_match('/function\s+' . preg_quote($h, '/') . '\s*\(/', $jsAll)
        && !preg_match('/(?:const|let|var)\s+' . preg_quote($h, '/') . '\s*=/', $jsAll)
        && !preg_match('/window\.' . preg_quote($h, '/') . '\s*=/', $jsAll)) $undef[] = $h;
}
test('fiecare handler inline e definit in JS', count($undef) === 0, implode(', ', $undef));
info('handler-e inline=' . count($handlers));

$helpJs = (string)@file_get_contents("$R/js/help.js");
preg_match_all('/openHelp\(\s*[\'"]([^\'"]+)[\'"]/', $htmlAllStripped . $jsAll, $mk);
$helpMiss = [];
foreach (array_unique($mk[1]) as $k) {
    if (!preg_match('/[\'"]?' . preg_quote($k, '/') . '[\'"]?\s*:\s*\{/', $helpJs)) $helpMiss[] = $k;
}
test('fiecare openHelp(k) are intrare in HELP_CONTENT', count($helpMiss) === 0, implode(', ', $helpMiss));

preg_match_all('/navigateToScreen\(\s*[\'"]([^\'"]+)[\'"]/', $jsAll, $mn);
$screenMiss = [];
foreach (array_unique($mn[1]) as $s) { if (!isset($htmlIds[$s])) $screenMiss[] = $s; }
test('fiecare ecran din navigateToScreen exista in DOM', count($screenMiss) === 0, implode(', ', $screenMiss));

/* ----------------------------------------------- A6 actiuni client vs server */
section('A6 - actiuni API trimise de client vs. tratate de server');
$clientAll = $jsAll . $htmlAllStripped;
preg_match_all('/action\s*:\s*[\'"]([a-z_]+)[\'"]/', $clientAll, $ma);
$clientActions = array_unique($ma[1]);

$apiFiles = glob("$R/api/*.php");
$apiBody = [];
foreach ($apiFiles as $f) $apiBody[basename($f)] = (string)@file_get_contents($f);
$notFound = [];
foreach ($clientActions as $a) {
    $in = [];
    foreach ($apiBody as $bn => $body) { if (str_contains($body, "'$a'") || str_contains($body, "\"$a\"")) $in[] = $bn; }
    if (!$in) $notFound[] = $a;
}
test('fiecare action trimis de client e tratat de un endpoint api/', count($notFound) === 0, implode(', ', $notFound));

$srv = $apiBody['order_action.php'] ?? '';
preg_match_all("/case\s+'([a-z_]+)'\s*:/", $srv, $ms);
$srvActions = array_unique($ms[1]);
info('actiuni client=' . count($clientActions) . ' / case order_action=' . count($srvActions));
info('case order_action nefolosite din client: ' . implode(', ', array_diff($srvActions, $clientActions)));

/* -------------------------------------------------------- A7 drift doc */
section('A7 - drift documentatie');
// Doar CODUL conteaza: comentariile care explica redenumirea (in update.php,
// removed-files.txt etc.) au voie sa mentioneze index.html.
$code = '';
foreach (array_merge(glob("$R/*.php"), glob("$R/api/*.php"), glob("$R/js/*.js")) as $f) $code .= stripComments((string)@file_get_contents($f));
test('niciun cod nu mai refera index.html (redenumit index.php)', substr_count($code, 'index.html') === 0, 'aparitii=' . substr_count($code, 'index.html'));
info('index.html in AGENTS.md: ' . substr_count((string)@file_get_contents("$R/AGENTS.md"), 'index.html'));

/* --------------------------------- A8 client JS: umbrirea functiei tr() */
section('A8 - client JS: nu umbri functia globala tr()');
// Bug real (coada export): in renderSendQueue() randul tabelului era declarat
// `const tr = document.createElement("tr")`, ceea ce umbrea translatorul global
// tr() din js/pos.js; apelul tr("trimis: ") arunca "tr is not a function",
// randurile nu se mai randau si butoanele (Sterge/Retry/Comanda SQL) rămâneau
// dezactivate. Interzicem orice variabila locala numita `tr`.
$shadow = [];
foreach (glob("$R/js/*.js") as $f) {
    $lines = explode("\n", (string)@file_get_contents($f));
    foreach ($lines as $i => $ln) {
        $t = ltrim($ln);
        if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*')) continue;
        if (preg_match('/\b(?:const|let|var)\s+tr\s*=/', $ln)               // declaratie
            || preg_match('/\btr\s*=>/', $ln)                               // parametru de arrow
            || preg_match('/function\s*[\w$]*\s*\([^)]*\btr\b[^)]*\)/', $ln) // parametru de functie
        ) {
            $shadow[] = basename($f) . ':' . ($i + 1);
        }
    }
}
test('nicio variabila locala numita tr (umbrarea traducatorului tr())', count($shadow) === 0, implode(', ', $shadow));

exit(summary('Faza A') > 0 ? 1 : 0);
