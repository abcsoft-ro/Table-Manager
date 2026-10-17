<?php
/**
 * Faza JS - logica de CLIENT (js/*.js), rulata in Node.
 *
 * De ce exista: suita PHP acopera backend-ul, dar codul de client era verificat
 * doar sintactic (`node --check`, in lint). Exact pe aceasta zona a trecut
 * neobservat bug-ul din js/i18n.js in care placeholderele {nume} nu erau
 * completate cand textul nu avea intrare in catalog (dialogul de update afisa
 * literal "Exista o versiune noua ({v})" in romana) - vazut doar in UI.
 *
 * Nu atinge baza de date si nu foloseste reteaua: incarca js/i18n.js intr-un Node
 * cu `window` stubuit si cu CATALOGURILE REALE (api/lang/ro.php / en.php, trecute
 * in JSON), apoi verifica contractul t()/trText()/applyI18n*().
 */
require __DIR__ . '/lib.php';

$ROOT = ROOT_DIR;
$TMP  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tm_js_' . uniqid();
@mkdir($TMP, 0777, true);

$LANGS = ['ro', 'en'];

// ---------------------------------------------------- catalogurile reale -> JSON
// (Node nu poate citi PHP; le dam catalogul ca JSON, ca testul sa foloseasca
// textele reale, nu stuburi care pot divergea de ele.)
foreach ($LANGS as $lang) {
    $file = $ROOT . '/api/lang/' . $lang . '.php';
    $cat = is_file($file) ? require $file : [];
    file_put_contents(
        $TMP . '/catalog_' . $lang . '.json',
        json_encode(is_array($cat) ? $cat : [], JSON_UNESCAPED_UNICODE)
    );
}

// ------------------------------------------------------------------ harness Node
$harness = <<<'JS'
"use strict";
// Incarca js/i18n.js cu un `window` stubuit si catalogul real, apoi tipareste o
// linie per verificare:  PASS|nume|detaliu  (citite de tests/js.php).
const fs = require("fs");
const i18nPath = process.argv[2];
const catalogPath = process.argv[3];
const lang = process.argv[4] || "?";

function out(ok, name, detail) {
  console.log((ok ? "PASS" : "FAIL") + "|" + name + "|" + String(detail === undefined ? "" : detail));
}

global.window = { I18N: JSON.parse(fs.readFileSync(catalogPath, "utf8")) };
require(i18nPath);

const W = global.window;
const t = W.t;
const trText = W.trText;

// --- API expus ---------------------------------------------------------
out(
  typeof t === "function" && typeof trText === "function" &&
  typeof W.applyI18n === "function" && typeof W.applyI18nText === "function",
  "[" + lang + "] API expus: t, trText, applyI18n, applyI18nText",
  [typeof t, typeof trText, typeof W.applyI18n, typeof W.applyI18nText].join(",")
);

// --- dialogul de update (cazul care a produs bugul) ---------------------
const SRC = "Exista o versiune noua ({v}). Configurarile locale nu sunt afectate. Actualizati acum?";
const res = trText(SRC, { v: "9.9.9" });
out(res.indexOf("{v}") === -1, "[" + lang + "] {v} nu ajunge literal in text", res.slice(0, 90));
out(res.indexOf("9.9.9") !== -1, "[" + lang + "] versiunea apare in text", res.slice(0, 90));

if (lang === "en") {
  // en.php are intrarea exacta pentru propozitia-sursa: rezultatul trebuie tradus.
  out(res.indexOf("A new version is available") === 0,
      "[" + lang + "] textul e tradus, nu cade pe sursa romaneasca", res.slice(0, 70));

  // Potrivire prin prefix: traduce prefixul si completeaza placeholderul din
  // restul netradus (fara fix, restul rămânea cu {y} literal).
  const p = trText("Exista o versiune noua (X) rest {y}.", { y: "ABC" });
  out(p.indexOf("A new version is available (") === 0 && p.indexOf("{y}") === -1 && p.indexOf("ABC") !== -1,
      "[" + lang + "] prefixul e tradus si placeholderul din rest se completeaza", p);

  // Mesajele POS bancar: textul afisat in dialog este normalizat de
  // api/pos_banca.php (posBancaUserMessage) si trebuie tradus in EN.
  const banca = trText("Nu s-a putut conecta la terminalul bancar (POS).");
  out(banca === "Could not connect to the bank terminal (POS).",
      "[" + lang + "] mesaj POS bancar (conexiune) tradus", banca);
  const bancaPrefix = trText("Eroare comunicare POS bancar: fetch failed");
  out(bancaPrefix === "Bank POS communication error: fetch failed",
      "[" + lang + "] eroare comunicare POS bancar (prefix) tradusa", bancaPrefix);
} else {
  // ro.php nu contine propozitiile-sursa (sunt deja in romana): textul trebuie
  // sa rămâna sursa, dar cu placeholderul completat.
  out(res === "Exista o versiune noua (9.9.9). Configurarile locale nu sunt afectate. Actualizati acum?",
      "[" + lang + "] sursa rămâne, doar placeholderul se completeaza", res.slice(0, 80));

  // In ro, mesajul POS bancar este deja sursa (nu se traduce).
  out(trText("Nu s-a putut conecta la terminalul bancar (POS).") === "Nu s-a putut conecta la terminalul bancar (POS).",
      "[" + lang + "] mesaj POS bancar rămâne sursa", "");
}

// --- text complet inexistent, cu placeholder ---------------------------
const inex = trText("Mesaj complet inexistent ({x}).", { x: "ABB" });
out(inex === "Mesaj complet inexistent (ABB).",
    "[" + lang + "] text inexistent: placeholderul se completeaza", inex);

// --- cheie de catalog + placeholder ------------------------------------
const k = t("printq.pending", { n: 3 });
out(k.indexOf("{n}") === -1 && k.indexOf("3") !== -1,
    "[" + lang + "] t(cheie, {n}) completeaza placeholderul", k);

// --- fara parametri / input gol ---------------------------------------
out(trText("Text oarecare fara placeholdere") === "Text oarecare fara placeholdere",
    "[" + lang + "] fara parametri: textul rămâne neschimbat");
out(trText("") === "" && trText(null) === null,
    "[" + lang + "] input gol sau non-string trece neschimbat");
JS;

file_put_contents($TMP . '/harness.js', $harness);

// ------------------------------------------------------------------- rulare
section('JS1 - i18n client (js/i18n.js) cu catalogurile reale');
foreach ($LANGS as $lang) {
    $out = [];
    $cmd = 'node ' . escapeshellarg($TMP . '/harness.js')
         . ' ' . escapeshellarg($ROOT . '/js/i18n.js')
         . ' ' . escapeshellarg($TMP . '/catalog_' . $lang . '.json')
         . ' ' . escapeshellarg($lang) . ' 2>&1';
    exec($cmd, $out, $rc);

    $parsed = 0;
    foreach ($out as $line) {
        if (preg_match('/^(PASS|FAIL)\|([^|]*)\|(.*)$/', trim($line), $m)) {
            $parsed++;
            test($m[2], $m[1] === 'PASS', $m[3]);
        }
    }
    if ($parsed === 0) {
        test("[$lang] harness-ul Node a rulat", false,
            'rc=' . $rc . ' iesire: ' . implode(' / ', array_slice($out, 0, 3)));
    }
}

// ------------------------------------------------------------------ curatare
foreach (glob($TMP . '/*') ?: [] as $f) { @unlink($f); }
@rmdir($TMP);

exit(summary('Faza JS') > 0 ? 1 : 0);
