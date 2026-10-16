<?php
/**
 * Copia locala a parolei de inchidere a aplicatiei (tblParola.ParolaExit).
 *
 * De ce exista: parola de iesire se verifica in baza de date. Daca instanta SQL
 * locala nu raspunde (oprita, sau inca pornind dupa un reboot Windows), pana
 * acum NU se mai putea inchide aplicatia deloc - exact cand utilizatorul are cea
 * mai mare nevoie de asta (POS blocat pe ecranul gol, fara meniu si fara mese).
 *
 * Solutia: o copie locala a parolei, scrisa de fiecare data cand baza de date
 * este disponibila (la fiecare pornire a aplicatiei - api/menu.php - si la
 * salvarea parolelor - api/parole.php) si citita ca rezerva cand baza de date
 * nu raspunde (api/exit_app.php, order_action.php action=authenticate).
 *
 * Fisierul sta in `logs/parola_local.json` (gitignorat, blocat la accesul HTTP
 * de .htaccess) si NU contine parola in clar, doar amprenta ei SHA-256. Nu este
 * o expunere suplimentara: in tblParola parola e oricum in clar, iar in
 * api/db.local.php sta parola de SQL in clar - pe aceeasi masina.
 *
 * Toate functiile sunt best-effort: nu arunca niciodata exceptii, un cache nu
 * are voie sa strice fluxul aplicatiei.
 */

const PAROLA_LOCAL_FILE = 'parola_local.json';

/**
 * Calea fisierului de cache (logs/parola_local.json).
 *
 * @return string
 */
function parolaLocalPath() {
    return dirname(__DIR__) . '/logs/' . PAROLA_LOCAL_FILE;
}

/**
 * Amprenta parolei de iesire (ce se scrie in cache; niciodata parola in clar).
 *
 * @param string $parola
 * @return string
 */
function parolaLocalHash($parola) {
    return hash('sha256', 'rual-exit|' . (string)$parola);
}

/**
 * Citeste cache-ul local.
 *
 * @return array Cheile pot fi: 'exit' (amprenta sau null = fara parola) si
 *               'updatedAt'. Array gol = fisier lipsa / ilizibil.
 */
function parolaLocalRead() {
    try {
        $file = parolaLocalPath();
        if (!is_file($file)) { return []; }
        $data = json_decode((string)@file_get_contents($file), true);
        return is_array($data) ? $data : [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Scrie cache-ul in mod atomic (tmp + rename). Best-effort.
 *
 * @param array $data
 * @return bool
 */
function parolaLocalStore(array $data) {
    try {
        $dir = dirname(__DIR__) . '/logs';
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $file = $dir . '/' . PAROLA_LOCAL_FILE;
        $data['updatedAt'] = date('Y-m-d H:i:s');
        $json = json_encode($data);
        // Nu rescriem degeaba la fiecare pornire a aplicatiei (amprenta e
        // determinista, deci comparatia e exacta).
        if (is_file($file) && @file_get_contents($file) === $json) { return true; }
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) { return false; }
        return @rename($tmp, $file);
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Scrie amprenta parolei de iesire (parola goala => marcheaza "fara parola").
 *
 * @param string|null $parola
 * @return bool
 */
function parolaLocalWriteExit($parola) {
    $parola = trim((string)$parola);
    $data = parolaLocalRead();
    $data['exit'] = ($parola === '') ? null : parolaLocalHash($parola);
    return parolaLocalStore($data);
}

/**
 * Marcheaza in cache ca nu este configurata nicio parola de inchidere.
 * (Fisierul rămâne, ca sa se poata distinge "stim ca nu e parola" de
 * "nu stim nimic" - vezi parolaLocalCheckExit.)
 *
 * @return bool
 */
function parolaLocalClearExit() {
    return parolaLocalWriteExit('');
}

/**
 * Verifica parola de inchidere fara baza de date.
 *
 * @param string $parola
 * @return string 'ok'        - parola corecta (conform copiei locale)
 *                'wrong'     - parola gresita
 *                'not_set'   - stim sigur ca nu este configurata nicio parola
 *                'unknown'   - nu exista copie locala (nu stim nimic)
 */
function parolaLocalCheckExit($parola) {
    $data = parolaLocalRead();
    if (!array_key_exists('exit', $data)) { return 'unknown'; }
    $hash = $data['exit'];
    if ($hash === null || $hash === '') { return 'not_set'; }
    return hash_equals((string)$hash, parolaLocalHash($parola)) ? 'ok' : 'wrong';
}

/**
 * Reimprospateaza copia locala din baza de date (apelabila doar cand baza de
 * date raspunde). Best-effort.
 *
 * @param resource $conn
 * @return bool true daca parola de inchidere este configurata
 */
function parolaLocalSyncFromDb($conn) {
    try {
        $stmt = @sqlsrv_query($conn, "SELECT TOP 1 ParolaExit FROM tblParola");
        $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
        $parola = $row ? trim((string)$row['ParolaExit']) : '';
        parolaLocalWriteExit($parola);
        return $parola !== '';
    } catch (\Throwable $e) {
        return false;
    }
}
