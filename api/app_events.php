<?php
/**
 * Jurnal de evenimente al aplicatiei: DESCHIDERE / autentificare / INCHIDERE.
 *
 * Scop: sa putem reconstitui exact ce s-a intamplat cand aplicatia "se inchide
 * singura" - cine a deschis-o, ce prompt de parola s-a deschis, ce s-a tastat
 * (doar LUNGIMEA parolei, NICIODATA valoarea) si pe ce cale s-a ajuns la
 * inchidere (exit_app.php).
 *
 * Scrie best-effort in `logs/app_events.log` (gitignorat, blocat la accesul HTTP
 * de .htaccess). Fisierul se limiteaza singur: peste APP_EVENTS_MAX_BYTES se
 * pastreaza doar coada, ca sa nu creasca nelimitat pe statii. Nu arunca niciodata
 * exceptii: un log nu are voie sa strice fluxul aplicatiei.
 */

const APP_EVENTS_FILE = 'app_events.log';
const APP_EVENTS_MAX_BYTES = 10485760; // 10 MB -> se trunchiaza
const APP_EVENTS_KEEP_BYTES = 1048576; // pastram ultimul ~1 MB

/**
 * Curata o valoare pentru o linie de jurnal (fara newline, fara separator, limitat).
 *
 * @param mixed $v
 * @return string
 */
function appEventVal($v) {
    $s = trim((string)$v);
    $s = str_replace(["\r", "\n", "|"], ' ', $s);
    return function_exists('mb_substr') ? mb_substr($s, 0, 160, 'UTF-8') : substr($s, 0, 160);
}

/**
 * Scrie o linie in jurnalul de evenimente. Best-effort, nu arunca niciodata.
 *
 * @param string $tip
 * @param array $ctx
 */
function appEventLog($tip, array $ctx = []) {
    try {
        $dir = dirname(__DIR__) . '/logs';
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $file = $dir . '/' . APP_EVENTS_FILE;

        // Limitare de dimensiune: pastram doar coada, cu un marcaj in cap.
        if (is_file($file) && @filesize($file) > APP_EVENTS_MAX_BYTES) {
            $fh = @fopen($file, 'r');
            if ($fh) {
                @fseek($fh, -APP_EVENTS_KEEP_BYTES, SEEK_END);
                @fgets($fh); // sarim peste linia partiala de la inceput
                $tail = (string)@stream_get_contents($fh);
                @fclose($fh);
                @file_put_contents($file, "[...] jurnal trunchiat (limita de dimensiune)\n" . $tail);
            }
        }

        $parts = [];
        foreach ($ctx as $k => $v) { $parts[] = $k . '=' . appEventVal($v); }
        $line = '[' . date('Y-m-d H:i:s') . '] ' . appEventVal($tip)
              . ($parts ? ' | ' . implode(' | ', $parts) : '') . "\n";
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    } catch (\Throwable $e) {
        // ignorat intentionat
    }
}

/**
 * Contextul comun al cererii: cine si cu ce browser a facut-o.
 *
 * @return array
 */
function appEventClientCtx() {
    return [
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'ua' => preg_replace('/\s+/', ' ', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
    ];
}
