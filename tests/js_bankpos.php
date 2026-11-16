<?php
/**
 * FAZA JS BANCAR - fluxul de client al platii pe card (js/pos.js), in Node.
 *
 * De ce exista: backend-ul POS bancar este acoperit de tests/pos_banca.php
 * (status / manual / resync / protocol), dar logica din browser - ce vede si ce
 * poate apasa operatorul - nu era verificata nicaieri. Exact aici s-a schimbat
 * UX-ul: la UNKNOWN/TIMEOUT verificarea se face automat (fara dialog si fara
 * click-uri), iar dialogul apare doar cu rezultatul.
 *
 * tests/js_bankpos_client.js incarca `js/pos.js` REAL intr-un sandbox Node (DOM
 * stubuit, `fetch` controlat) si tipareste o linie per verificare:
 *   PASS|nume|detaliu
 * Nu atinge baza de date si nu foloseste reteaua.
 */
require __DIR__ . '/lib.php';

$harness = __DIR__ . '/js_bankpos_client.js';
$posjs = ROOT_DIR . '/js/pos.js';

section('JS2 - POS bancar: fluxul de client (js/pos.js in Node)');

if (!is_file($harness)) {
    test('harness-ul Node exista (tests/js_bankpos_client.js)', false, $harness);
} else {
    $out = [];
    $cmd = 'node ' . escapeshellarg($harness) . ' ' . escapeshellarg($posjs) . ' 2>&1';
    exec($cmd, $out, $rc);

    $parsed = 0;
    foreach ($out as $line) {
        if (preg_match('/^(PASS|FAIL)\|([^|]*)\|(.*)$/', trim($line), $m)) {
            $parsed++;
            test($m[2], $m[1] === 'PASS', $m[3]);
        }
    }
    if ($parsed === 0) {
        test('harness-ul Node a rulat', false,
            'rc=' . $rc . ' iesire: ' . implode(' / ', array_slice($out, 0, 3)));
    }
}

exit(summary('Faza JS bancar') > 0 ? 1 : 0);
