<?php
/**
 * Faza G - inchiderea aplicatiei trebuie sa functioneze si fara baza de date.
 *
 * Pe o statie, daca instanta SQL locala nu raspunde (oprita, sau inca pornind
 * dupa un reboot Windows), parola de inchidere NU mai putea fi verificata (se
 * citeste din tblParola) => POS-ul rămânea blocat pe ecranul gol, fara nicio
 * cale de iesire. De aceea parola de iesire are o copie locala
 * (logs/parola_local.json, doar amprenta SHA-256), scrisa la fiecare pornire a
 * aplicatiei si citita cand baza de date nu raspunde (api/parola_local.php).
 *
 * Faza NU opreste baza de date (ar fi invaziv pe masina de dezvoltare) si NU
 * scrie in tblParola: verifica helper-ele care fac posibila inchiderea offline
 * plus legaturile lor din endpoint-uri. Fisierul de cache real este salvat si
 * pus la loc la final.
 */
require __DIR__ . '/lib.php';
require_once ROOT_DIR . '/api/parola_local.php';

$cacheFile = parolaLocalPath();
$backup = is_file($cacheFile) ? (string)file_get_contents($cacheFile) : null;
// Copia locala a timeout-ului de conectare (tblSet.ODBC_TimeOut) este si ea
// salvata/restaurata: testele o citesc/scriu, dar valoarea ei ramane cea reala.
$tmoFile = dbTimeoutPath();
$tmoBackup = is_file($tmoFile) ? (string)file_get_contents($tmoFile) : null;

try {
    /* ------------------------------------------------------------------ G1 */
    section('G1 - copia locala a parolei de inchidere');
    test('cache-ul sta in logs/', (bool)preg_match('#[\\\\/]logs[\\\\/]#', $cacheFile), $cacheFile);

    @unlink($cacheFile);
    test('fara fisier: "unknown" (nu stim nimic)', parolaLocalCheckExit('1234') === 'unknown');

    parolaLocalWriteExit('1234');
    test('parola corecta => "ok"', parolaLocalCheckExit('1234') === 'ok');
    test('parola gresita => "wrong"', parolaLocalCheckExit('4321') === 'wrong');
    test('parola goala => "wrong" (cand e configurata)', parolaLocalCheckExit('') === 'wrong');

    $raw = (string)@file_get_contents($cacheFile);
    $json = json_decode($raw, true);
    test('cache-ul este JSON valid', is_array($json), substr($raw, 0, 80));
    test('parola NU apare in clar in cache', $raw !== '' && !str_contains($raw, '1234'), substr($raw, 0, 80));
    test('cache-ul pastreaza doar amprenta SHA-256', preg_match('/^[0-9a-f]{64}$/', (string)($json['exit'] ?? '')) === 1);

    /* ------------------------------------------------------------------ G2 */
    section('G2 - fara parola configurata');
    parolaLocalClearExit();
    test('dupa golire: "not_set"', parolaLocalCheckExit('1234') === 'not_set');
    test('fisierul rămâne (distinge "nu e parola" de "nu stim nimic")', is_file($cacheFile));

    /* ------------------------------------------------------------------ G3 */
    section('G3 - sincronizare din baza de date (fara scriere in tabela)');
    $parolaDb = trim((string)cell("SELECT ParolaExit FROM tblParola WHERE Id = 1"));
    $setat = parolaLocalSyncFromDb(db());
    test('sync raporteaza daca parola e configurata', $setat === ($parolaDb !== ''), 'db="' . $parolaDb . '"');
    test('cache-ul reflecta parola reala din tblParola',
        $parolaDb === '' ? (parolaLocalCheckExit('1234') === 'not_set') : (parolaLocalCheckExit($parolaDb) === 'ok'));

    /* ------------------------------------------------------------------ G4 */
    section('G4 - legaturile din endpoint-uri (guard impotriva regresiilor)');
    $exitSrc = (string)@file_get_contents(ROOT_DIR . '/api/exit_app.php');
    test('exit_app.php: verifica baza de date silentios', str_contains($exitSrc, 'getDBConnection(true)'));
    test('exit_app.php: foloseste copia locala cand DB lipseste', str_contains($exitSrc, 'parolaLocalCheckExit') && str_contains($exitSrc, '$offline'));
    test('exit_app.php: reimprospateaza copia locala la succes', str_contains($exitSrc, 'parolaLocalWriteExit'));

    $oaSrc = (string)@file_get_contents(ROOT_DIR . '/api/order_action.php');
    test('order_action.php: are cale fara baza de date', str_contains($oaSrc, 'handleOfflineAction'));
    test('order_action.php: accepta parola de iesire fara DB', str_contains($oaSrc, 'parolaLocalCheckExit'));
    test('order_action.php: verifica baza de date silentios', str_contains($oaSrc, '$conn = getDBConnection(true)'));

    $menuSrc = (string)@file_get_contents(ROOT_DIR . '/api/menu.php');
    test('menu.php: scrie copia locala la fiecare pornire', str_contains($menuSrc, 'parolaLocalSyncFromDb'));
    $paroleSrc = (string)@file_get_contents(ROOT_DIR . '/api/parole.php');
    test('parole.php: actualizeaza copia locala la salvare', str_contains($paroleSrc, 'parolaLocalWriteExit'));

    $dbSrc = (string)@file_get_contents(ROOT_DIR . '/api/db.php');
    test('db.php: conectare cu LoginTimeout (nu asteptam ~30s)', str_contains($dbSrc, 'LoginTimeout'));
    test('db.php: esecul nu se reincearca in acelasi request', str_contains($dbSrc, '$failed'));

    /* ------------------------------------------------------------------ G5 */
    section('G5 - pagina se randeaza fara sa astepte baza de date');
    $idxSrc = (string)@file_get_contents(ROOT_DIR . '/index.php');
    test('index.php: banner de stare a bazei de date', str_contains($idxSrc, 'id="db-status"'));
    test('index.php: randare instant (fara asteptarea bazei de date)', str_contains($idxSrc, 'APP_LANG_FAST'));
    test('mobile.php: randare instant (fara asteptarea bazei de date)',
        str_contains((string)@file_get_contents(ROOT_DIR . '/mobile.php'), 'APP_LANG_FAST'));

    $posSrc = (string)@file_get_contents(ROOT_DIR . '/js/pos.js');
    test('pos.js: arata/ascunde banner-ul de stare', str_contains($posSrc, 'function showDbStatus') && str_contains($posSrc, 'function hideDbStatus'));
    test('pos.js: reincearca incarcarea initiala', str_contains($posSrc, 'function scheduleInitRetry') && str_contains($posSrc, 'MAX_INIT_RETRY'));
    test('pos.js: banner-ul spune ca parola de iesire functioneaza',
        str_contains($posSrc, 'Parola de inchidere functioneaza si fara baza de date'));

    $cssSrc = (string)@file_get_contents(ROOT_DIR . '/css/pos.css');
    test('css: banner-ul nu blocheaza clicurile (Parola rămâne folosibil)',
        str_contains($cssSrc, '.db-status') && str_contains($cssSrc, 'pointer-events: none'));

    require_once ROOT_DIR . '/api/i18n.php';
    $langFile = appLangLocalPath();
    $langBackup = is_file($langFile) ? (string)file_get_contents($langFile) : null;
    try {
        appLangLocalWrite('en');
        test('limba: se scrie in copia locala', appLangLocalRead() === 'en');
        appLangLocalWrite('xx');
        test('limba: valorile invalide nu se scriu', appLangLocalRead() === 'en');
        test('limba: randarea rapida foloseste copia locala',
            str_contains((string)@file_get_contents(ROOT_DIR . '/api/i18n.php'), 'APP_LANG_FAST'));
    } finally {
        if ($langBackup === null) { @unlink($langFile); } else { @file_put_contents($langFile, $langBackup); }
        @unlink($langFile . '.tmp');
    }

    /* ------------------------------------------------------------------ G6 */
    section('G6 - timeout de conectare din tblSet.ODBC_TimeOut');
    $tmoDb = (int)trim((string)cell("SELECT Value FROM tblSet WHERE Setting = 'ODBC_TimeOut'"));
    $tmo = dbOdbcTimeout(db());
    test('ODBC_TimeOut exista in tblSet si este numeric', $tmoDb > 0, 'valoare=' . $tmoDb);
    test('dbOdbcTimeout() foloseste valoarea din tblSet', $tmo === $tmoDb, 'aplicatie=' . $tmo);
    test('conectarea locala foloseste aceeasi valoare', dbLoginTimeoutLocal() === $tmoDb, 'local=' . dbLoginTimeoutLocal());
    $tmoJson = is_file($tmoFile) ? json_decode((string)file_get_contents($tmoFile), true) : null;
    test('valoarea se pastreaza in logs/db_timeout.json',
        is_array($tmoJson) && (int)($tmoJson['sec'] ?? 0) === $tmoDb, (string)@file_get_contents($tmoFile));

    section('G6b - legaturile din celelalte puncte de conectare');
    $conSrc = (string)@file_get_contents(ROOT_DIR . '/api/conectare.php');
    test('conectare.php: conexiunile folosesc setarea', substr_count($conSrc, 'dbOdbcTimeout($conn)') >= 2);
    $scSrc = (string)@file_get_contents(ROOT_DIR . '/api/send_common.php');
    test('send_common.php: trimite timeout-ul worker-ului de sincronizare',
        str_contains($scSrc, '"timeout" => dbOdbcTimeout($conn)'));
    test('sync-service/db.py: foloseste timeout-ul primit la claim',
        str_contains((string)@file_get_contents(ROOT_DIR . '/sync-service/db.py'), 'info.get("timeout")'));
    test('import_server_grp_prod.py: foloseste timeout-ul local',
        str_contains((string)@file_get_contents(ROOT_DIR . '/import_server_grp_prod.py'), 'read_local_timeout'));
    test('db/tools/export_schema.php: foloseste timeout-ul local',
        str_contains((string)@file_get_contents(ROOT_DIR . '/db/tools/export_schema.php'), 'db_timeout.json'));
    $setSrc = (string)@file_get_contents(ROOT_DIR . '/api/setari.php');
    test('setari.php: creeaza setarea daca lipseste',
        str_contains($setSrc, "ensureSetariValue(\$conn, 'ODBC_TimeOut'"));
    test('setari.php: actualizeaza copia locala la salvare',
        str_contains($setSrc, 'dbTimeoutLocalWrite((int)$values'));
    test('menu.php: reimprospateaza copia locala la fiecare pornire',
        substr_count((string)@file_get_contents(ROOT_DIR . '/api/menu.php'), 'dbOdbcTimeout(') >= 1);
} finally {
    if ($backup === null) { @unlink($cacheFile); }
    else { @file_put_contents($cacheFile, $backup); }
    @unlink($cacheFile . '.tmp');
    if ($tmoBackup === null) { @unlink($tmoFile); }
    else { @file_put_contents($tmoFile, $tmoBackup); }
    @unlink($tmoFile . '.tmp');
}

test('cache-ul real a fost restaurat',
    $backup === null ? !is_file($cacheFile) : ((string)@file_get_contents($cacheFile) === $backup));
test('copia locala a timeout-ului a fost restaurata',
    $tmoBackup === null ? !is_file($tmoFile) : ((string)@file_get_contents($tmoFile) === $tmoBackup));

exit(summary('Faza G') > 0 ? 1 : 0);
