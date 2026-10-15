<?php
/**
 * API: Setari POS (tblSet: Setting, Value, Descriere, Grup)
 * GET  -> lista setarilor
 * POST -> actiune: update (batch de {Setting: Value})
 * Doar coloana Value este editabila.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/print_common.php';
require_once __DIR__ . '/api_common.php';
require_once __DIR__ . '/i18n.php';

/**
 * Tipul de vanzare din tblSet.TipVanz: "fastfood" cand Value = 1, altfel "restaurant".
 *
 * @param resource $conn Conexiunea SQL Server.
 * @return string
 */
function setariTipVanz($conn) {
    $stmt = @sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'TipVanz'");
    $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    return ($row && (int)trim((string)$row['Value']) === 1) ? "fastfood" : "restaurant";
}

/**
 * Numarul de bonuri deschise (mese neinchise) din tblBonCurent.
 *
 * @param resource $conn Conexiunea SQL Server.
 * @return int
 */
function setariOpenBills($conn) {
    $stmt = @sqlsrv_query($conn, "SELECT COUNT(*) AS c FROM tblBonCurent WHERE Stare IS NULL OR Stare <> 'I'");
    $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    return (int)($row['c'] ?? 0);
}

/**
 * Creeaza setarea (Setting, Value, Descriere, Grup) daca lipseste si ii
 * actualizeaza Descrierea daca s-a schimbat. Folosit pentru setarile noi, ca
 * sa apara si pe instalatiile existente, nu doar pe cele noi.
 *
 * @param resource $conn Conexiunea SQL Server.
 * @param string $setting Cheia setarii.
 * @param mixed $default Valoarea implicita la creare.
 * @param string $desc Descrierea afisata in ecranul Setari.
 * @param string $grup Grupul/tab-ul setarii.
 * @return void
 */
function ensureSetariValue($conn, $setting, $default, $desc, $grup) {
    $stmt = @sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = ?", [$setting]);
    $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    if (!$row) {
        @sqlsrv_query(
            $conn,
            "INSERT INTO tblSet (Setting, Value, Descriere, Grup) VALUES (?, ?, ?, ?)",
            [$setting, $default, $desc, $grup]
        );
    } else {
        @sqlsrv_query(
            $conn,
            "UPDATE tblSet SET Descriere = ? WHERE Setting = ? AND (Descriere IS NULL OR Descriere <> ?)",
            [$desc, $setting, $desc]
        );
    }
}

/**
 * Descrierea canonica a setarii TipVanz. O scriem in tblSet la nevoie, ca sa
 * fie afisata corect in ecranul Setari si pe instalatiile existente.
 *
 * @param resource $conn Conexiunea SQL Server.
 * @return void
 */
function ensureSetariMeta($conn) {
    static $done = false;
    if ($done) { return; }

    // Limba interfetei (ro/en). Setarea noua a versiunii bilingve.
    ensureSetariValue($conn, 'Limba', 'ro',
        "Limba interfetei: romana sau engleza.", 'General');

    $desc = "Tipul de vanzare: 0 = Restaurant (ecran de mese, comanda la sectie, nota de plata); 1 = FastFood (fara ecran de mese, comanda nu pleaca la sectie, doar bon fiscal).";
    @sqlsrv_query(
        $conn,
        "UPDATE tblSet SET Descriere = ? WHERE Setting = 'TipVanz' AND (Descriere IS NULL OR Descriere <> ?)",
        [$desc, $desc]
    );

    // Cantitatea maxima admisa pe o linie de nota; creata automat cu 1000 daca lipseste.
    $cantMaxDesc = "Cantitatea maxima admisa pentru un produs pe nota (protejeaza impotriva tastarii gresite). Implicit 1000.";
    $chk = @sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'CantMax'");
    $has = $chk ? sqlsrv_fetch_array($chk, SQLSRV_FETCH_ASSOC) : null;
    if (!$has) {
        @sqlsrv_query(
            $conn,
            "INSERT INTO tblSet (Setting, Value, Descriere, Grup) VALUES ('CantMax', '1000', ?, 'General')",
            [$cantMaxDesc]
        );
    } else {
        @sqlsrv_query(
            $conn,
            "UPDATE tblSet SET Descriere = ? WHERE Setting = 'CantMax' AND (Descriere IS NULL OR Descriere <> ?)",
            [$cantMaxDesc, $cantMaxDesc]
        );
    }

    // Contorul dedicat al bonurilor de sectie (nr. de pe bon), documentat in Descriere.
    ensureNrBonSetting($conn);

    // Numarul de zecimale pentru cantitate (0, 1 sau 2).
    $nrZecDesc = "Numarul de zecimale pentru cantitatea produselor: 0, 1 sau 2. Se aplica la afisarea pe nota si la introducerea cantitatii (0 = doar numere intregi).";
    $chkNZ = @sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'NrZecCant'");
    $hasNZ = $chkNZ ? sqlsrv_fetch_array($chkNZ, SQLSRV_FETCH_ASSOC) : null;
    if (!$hasNZ) {
        @sqlsrv_query(
            $conn,
            "INSERT INTO tblSet (Setting, Value, Descriere, Grup) VALUES ('NrZecCant', '1', ?, 'General')",
            [$nrZecDesc]
        );
    } else {
        @sqlsrv_query(
            $conn,
            "UPDATE tblSet SET Descriere = ? WHERE Setting = 'NrZecCant' AND (Descriere IS NULL OR Descriere <> ?)",
            [$nrZecDesc, $nrZecDesc]
        );
    }

    // Magazia (gestiunea) folosita la importul de pe server. Poate contine mai
    // multe magazii separate prin virgula (ex. 1,2); la suprapuneri castiga
    // prima din lista, iar exportul/facturarea folosesc primul MagID.
    ensureSetariValue($conn, 'MagID', '11',
        "Magazia (gestiunea) pentru importul grupelor/produselor/preturilor de pe server. Mai multe magazii separate prin virgula (ex. 1,2); la suprapuneri castiga prima din lista. La facturare se foloseste prima din lista.", 'General');

    // Discountul permis/interzis (RED): 1 = DA, 0 = NU.
    $redDesc = "Permite sau interzice discountul: 1 = DA (butonul Discount este afisat), 0 = NU (butonul Discount este ascuns pe desktop si pe tableta).";
    $chkRed = @sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'RED'");
    $hasRed = $chkRed ? sqlsrv_fetch_array($chkRed, SQLSRV_FETCH_ASSOC) : null;
    if (!$hasRed) {
        @sqlsrv_query(
            $conn,
            "INSERT INTO tblSet (Setting, Value, Descriere, Grup) VALUES ('RED', '1', ?, 'Discount')",
            [$redDesc]
        );
    } else {
        @sqlsrv_query(
            $conn,
            "UPDATE tblSet SET Descriere = ? WHERE Setting = 'RED' AND (Descriere IS NULL OR Descriere <> ?)",
            [$redDesc, $redDesc]
        );
    }

    // Tipul discountului (TipDiscount): 0 = complet, 1 = doar procent la
    // subtotal (popup cu tastatura numerica 0-100), 2 = procent fix la subtotal
    // (valoarea din ProcentDiscountFix, aplicat direct fara popup).
    ensureSetariValue($conn, 'TipDiscount', '0',
        "Tipul discountului: 0 = complet (produs sau subtotal, procent sau valoare, cu popup); 1 = doar procent la subtotal (popup cu tastatura numerica 0-100); 2 = procent fix la subtotal (valoarea din ProcentDiscountFix, aplicat direct fara popup).", 'Discount');
    ensureSetariValue($conn, 'ProcentDiscountFix', '10',
        "Procentul fix de discount aplicat pe subtotal cand TipDiscount = 2 (0-100).", 'Discount');

    // Export/sincronizare catre server (tblSet.Server): 0 = independent,
    // 1 = grupe/produse de pe server extern + exportul vanzarilor prin sync-service.
    $serverDesc = "Export/sincronizare cu serverul extern: 0 = NU (aplicatia functioneaza independent); 1 = DA (aplicatia incarca grupele si produsele de pe un server extern, aplicatia exporta vanzarile catre serverul extern prin serviciul python sync-service).";
    $chkSrv = @sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'Server'");
    $hasSrv = $chkSrv ? sqlsrv_fetch_array($chkSrv, SQLSRV_FETCH_ASSOC) : null;
    if (!$hasSrv) {
        @sqlsrv_query(
            $conn,
            "INSERT INTO tblSet (Setting, Value, Descriere, Grup) VALUES ('Server', '0', ?, 'General')",
            [$serverDesc]
        );
    } else {
        @sqlsrv_query(
            $conn,
            "UPDATE tblSet SET Descriere = ? WHERE Setting = 'Server' AND (Descriere IS NULL OR Descriere <> ?)",
            [$serverDesc, $serverDesc]
        );
    }
    // Server si NrLogic sunt setari din tabul General (mutate fara pasi manuali
    // de pe instalatiile existente, unde Server era in grupul ODBC).
    @sqlsrv_query(
        $conn,
        "UPDATE tblSet SET Grup = 'General' WHERE Setting IN ('Server', 'NrLogic') AND (Grup IS NULL OR Grup <> 'General')"
    );

    // Motivele de discount si de stornare: liste editabile, separate prin
    // punct si virgula. Sunt afisate ca optiuni in casetele Discount si VD.
    ensureSetariValue($conn, 'MotivDiscount',
        'Protocol;Inlocuire preparat;Membru fidelitate;Angajat;Card fidelitate;Altele',
        "Motivele de discount oferite in caseta de discount. Valorile sunt separate prin punct si virgula (;).", 'Discount');
    ensureSetariValue($conn, 'MotivStornare',
        'Retur client;Greseala ospatar;Lipsa stoc;Comanda gresita;Altele',
        "Motivele de anulare (stornare) oferite in caseta VD. Valorile sunt separate prin punct si virgula (;).", 'General');

    // Seria facturii folosita la apelul procedurii GenFactPoS pe server
    // (ecranul Facturare). Numarul facturii vine de pe server.
    ensureSetariValue($conn, 'SerieFactura', '',
        "Seria folosita la generarea facturii pe server (procedura GenFactPoS din ecranul Facturare).", 'General');

    // Text liber afisat in subsolul facturii (ecranul Facturare). Mai multe
    // randuri se separa prin punct si virgula (;).
    ensureSetariValue($conn, 'TextFactura', '',
        "Text liber afisat in subsolul facturii (ecranul Facturare). Pentru mai multe randuri, separati-le prin punct si virgula (;).", 'General');

    // Plata combinata (tblSet.PlataCombinata): 1 = DA (plata mixta, cu numpad
    // de introducere a sumelor), 0 = NU (in ecranul de inchidere se afiseaza
    // doar formele de plata, fara numpad; la click pe o forma nota se inchide
    // integral cu acea forma).
    ensureSetariValue($conn, 'PlataCombinata', '1',
        "Plata combinata: 1 = DA (se pot combina mai multe forme de plata, cu introducerea sumelor din numpad); 0 = NU (in ecranul de inchidere apar doar formele de plata, fara numpad, iar la click pe o forma nota se inchide integral cu acea forma).", 'General');

    // Toate setarile de discount sunt grupate in grupul „Discount" (deploy fara
    // pasi manuali: mutam si randurile existente care inca sunt in alt grup).
    @sqlsrv_query(
        $conn,
        "UPDATE tblSet SET Grup = 'Discount' WHERE Setting IN ('RED', 'TipDiscount', 'ProcentDiscountFix', 'MotivDiscount') AND (Grup IS NULL OR Grup <> 'Discount')"
    );

    // Casa de marcat fiscala: tipul casei + caile fisierelor de comenzi/raspuns.
    ensureSetariValue($conn, 'TipCasaMarcat', 'FiscalWire',
        "Tipul casei de marcat fiscale: FiscalWire, FiscalNet, Fisco sau fprwin. Fisco si fprwin sunt in curs de implementare.", 'Casa');
    // Migrare: vechea valoare "Datecs" (tot driver FiscalWire) devine "FiscalWire",
    // iar "Tremol" devine "Fisco".
    @sqlsrv_query(
        $conn,
        "UPDATE tblSet SET Value = 'FiscalWire' WHERE Setting = 'TipCasaMarcat' AND Value = 'Datecs'"
    );
    @sqlsrv_query(
        $conn,
        "UPDATE tblSet SET Value = 'Fisco' WHERE Setting = 'TipCasaMarcat' AND Value = 'Tremol'"
    );
    ensureSetariValue($conn, 'CaleFisierComenziECR', 'C:\\POS\\fiscal\\bonuri',
        "Folderul in care se scriu fisierele de comenzi catre casa de marcat fiscala.", 'Casa');
    ensureSetariValue($conn, 'CaleFisierRaspunsECR', 'C:\\POS\\fiscal\\raspuns',
        "Folderul in care casa de marcat fiscala scrie fisierele de raspuns.", 'Casa');
    // Asteptarea raspunsului casei de marcat la inchiderea notei (implementat
    // pentru driver-ul FiscalWire): cand e DA, POS-ul scrie fisierul de comenzi
    // si asteapta confirmarea bonului (.nrb) inainte de a inchide nota. Nota nu
    // se inchide daca registrul nu raspunde, refuza (CANCELED) sau da eroare.
    ensureSetariValue($conn, 'AsteaptaRaspunsCasa', '0',
        "Asteapta raspunsul casei de marcat la inchiderea notei (FiscalWire sau FiscalNet): 1 = DA (nota se inchide doar dupa confirmarea bonului fiscal), 0 = NU (implicit, tiparire asincrona ca pana acum).", 'Casa');
    ensureSetariValue($conn, 'AsteptareCasaSec', '30',
        "Durata maxima (secunde) cat POS-ul asteapta raspunsul casei de marcat cand AsteaptaRaspunsCasa = 1. Implicit 30.", 'Casa');
    ensureSetariValue($conn, 'BonFiscalDiscStorno', '0',
        "Afiseaza discountul si stornarea pe bonul fiscal: 1 = DA, 0 = NU (implicit). La 0 bonul contine doar cantitatile si preturile nete, fara comenzi de discount si fara linii de storno.", 'Casa');

    // Afisaj client: activare + driver + folder comenzi.
    // (functia este inca in curs de implementare)
    ensureSetariValue($conn, 'AfiseajClient', '1',
        "Afisaj client activ: 1 = DA, 0 = NU. In curs de implementare.", 'Afiseaj');
    ensureSetariValue($conn, 'CaleDriverAfiseajClient', '',
        "Calea catre driverul/executabilul afisajului client. In curs de implementare.", 'Afiseaj');
    ensureSetariValue($conn, 'CaleFisierComenziAfiseajClient', '',
        "Folderul in care se scriu fisierele de comenzi catre afisajul client. In curs de implementare.", 'Afiseaj');

    // Cantar electronic: activare + driver + fisier raspuns + delay.
    ensureSetariValue($conn, 'Cantar', '0',
        "Cantar electronic activ: 1 = DA, 0 = NU. Cand e DA, butonul QTY lanseaza driverul si preia cantitatea citita.", 'Cantar');
    ensureSetariValue($conn, 'CaleDriverCantar', '',
        "Calea catre driverul/executabilul cantarului electronic (ex. un .bat care scrie fisierul de raspuns).", 'Cantar');
    ensureSetariValue($conn, 'CaleFisierRaspunsCantar', '',
        "Fisierul in care cantarul scrie greutatea citita (un numar). Se sterge inainte de fiecare cantarire.", 'Cantar');
    ensureSetariValue($conn, 'Delay_cantar', '650',
        "Timp de asteptare (ms) dupa lansarea driverului, inainte de citirea raspunsului cantarului. Implicit 650.", 'Cantar');
    // Setari avansate ale afisajului client (in curs de implementare).
    ensureSetariValue($conn, 'PortComAfiseaj', '5',
        "Numarul portului serial al afisajului client. In curs de implementare.", 'Afiseaj');
    ensureSetariValue($conn, 'BaudRateAfisaj', '9600',
        "Viteza portului serial al afisajului client. In curs de implementare.", 'Afiseaj');

    // POS bancar: activare + driver + folder raspuns.
    ensureSetariValue($conn, 'PoSbanca', '0',
        "POS bancar activ: 1 = DA, 0 = NU.", 'PoSbanca');
    ensureSetariValue($conn, 'CaleDriverPoSbanca', '',
        "Calea catre driverul/executabilul POS-ului bancar.", 'PoSbanca');

    // Redenumire fara pasi manuali: CaleFisierComenziPoSbanca ->
    // CaleFisierRaspunsPoSbanca, pastrand valoarea de pe instalatiile existente.
    @sqlsrv_query(
        $conn,
        "UPDATE tblSet SET Setting = 'CaleFisierRaspunsPoSbanca'
         WHERE Setting = 'CaleFisierComenziPoSbanca'
           AND NOT EXISTS (SELECT 1 FROM tblSet t2 WHERE t2.Setting = 'CaleFisierRaspunsPoSbanca')"
    );
    ensureSetariValue($conn, 'CaleFisierRaspunsPoSbanca', '',
        "Calea catre fisierul in care POS-ul bancar scrie raspunsul.", 'PoSbanca');
    @sqlsrv_query($conn, "DELETE FROM tblSet WHERE Setting = 'CaleFisierComenziPoSbanca'");

    // Integrare / API public pentru aplicatii terte (grup 'Integrare'): o
    // singura cheie globala, trimisa in headerul X-Api-Key. In baza de date se
    // pastreaza doar SHA-256 al cheii (ApiKeyHash), generata din ecranul Setari.
    ensureSetariValue($conn, 'ApiEnabled', '0',
        "API public pentru aplicatii terte: 1 = DA (activ), 0 = NU (dezactivat, implicit). Cand este activ, aplicatiile terte se autentifica cu cheia API generata aici.", 'Integrare');
    ensureSetariValue($conn, 'ApiAllowedIps', '',
        "Lista de adrese IP / retele CIDR permise pentru API, separate prin virgula (ex. 192.168.1.0/24,10.0.0.5). Gol = fara restrictie. Recomandat cand API-ul este expus in afara retelei locale.", 'Integrare');
    ensureSetariValue($conn, 'ApiRateLimit', '120',
        "Numarul maxim de cereri pe minut per adresa IP pentru API. 0 = fara limita. Implicit 120.", 'Integrare');
    ensureSetariValue($conn, 'ApiCorsOrigin', '',
        "Rezervat pentru CORS (consumatori care ruleaza in browser). Lasati gol pentru integrari server-la-server.", 'Integrare');
    ensureSetariValue($conn, 'ApiKeyHash', '',
        "SHA-256 al cheii API. Se seteaza automat la generarea cheii; cheia in clar se afiseaza o singura data si nu este pastrata.", 'Integrare');

    // Chei legacy, fara efect in aplicatie: se sterg din tblSet daca exista
    // (nu mai apar nici in ecranul Setari).
    @sqlsrv_query(
        $conn,
        "DELETE FROM tblSet WHERE Setting IN ('NrBonCmd', 'ParolaDiscount', 'Storno_parola', 'Retea', 'Rapoarte_parola',
            'ProceduraExport', 'ProgramPartidVara', 'RaportZCombinat', 'RapPeriodic', 'TipPromotie', 'TSC', 'ValPunct',
            'DiscountEvidentiat', 'EC', 'FP', 'ModulTMO', 'ExportServer',
            'AccesNotaProforma', 'CumulezNota', 'CumulezSectie', 'FormatNota', 'ImprimantaComenzi', 'ImprimantaDefault',
            'PrinterBonCmd', 'PrinterNote', 'PrinterSectie', 'TiparescNota',
            'BaudRate', 'CaleDriverECR', 'CaleFComenzi', 'CaleFLog', 'ImpFisc', 'PortCom', 'ResidentModeActiva', 'SerieIF',
            'TipAfisaj')"
    );

    $done = true;
}

$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    ensureSetariMeta($conn);
    $rows = [];
    $stmt = sqlsrv_query($conn, "SELECT Setting, Value, Descriere, Grup FROM tblSet ORDER BY Grup, Setting");
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $setting = trim($r['Setting'] ?? '');
            // Hash-ul cheii API nu se trimite niciodata catre client.
            if ($setting === 'ApiKeyHash') { continue; }
            // Descrierea localizata: o luam din catalog (setari.desc.<Setting>)
            // cand exista; altfel folosim textul din baza de date (romana).
            $descKey = 'setari.desc.' . $setting;
            $cat = i18nCatalog();
            $descriere = array_key_exists($descKey, $cat) ? $cat[$descKey] : $r['Descriere'];
            $rows[] = [
                "Setting" => $setting,
                "Value" => $r['Value'],
                "Descriere" => $descriere,
                "Grup" => trim($r['Grup'] ?? '')
            ];
        }
    }
    // Starea cheii API (existenta + ultima utilizare) pentru tabul Integrare.
    $apiKeySet = (apiSetting($conn, 'ApiKeyHash', '') !== '');
    $apiLastUsed = null;
    apiEnsureTables($conn);
    $stmtLu = @sqlsrv_query($conn, "SELECT TOP 1 CreatedAt FROM tblApiLog ORDER BY Id DESC");
    $luRow = $stmtLu ? sqlsrv_fetch_array($stmtLu, SQLSRV_FETCH_ASSOC) : null;
    if ($luRow && $luRow['CreatedAt'] !== null) {
        try {
            $apiLastUsed = (new DateTime((string)$luRow['CreatedAt']))->format('d-m-Y H:i:s');
        } catch (Exception $e) {
            $apiLastUsed = (string)$luRow['CreatedAt'];
        }
    }
    // In modul Restaurant, numarul de zecimale poate fi modificat doar daca
    // toate mesele sunt inchise (in FastFood nu exista ecran de mese).
    $tipVanz = setariTipVanz($conn);
    $openBills = setariOpenBills($conn);
    sendJsonResponse([
        "status" => "success",
        "rows" => $rows,
        "tipVanz" => $tipVanz,
        "openBills" => $openBills,
        "canEditNrZecCant" => ($tipVanz === "fastfood" || $openBills === 0),
        "apiKeySet" => $apiKeySet,
        "apiLastUsed" => $apiLastUsed
    ]);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input || !is_array($input)) {
    $input = $_POST;
}

$action = $input['action'] ?? '';

if ($action === 'update') {
    $values = $input['values'] ?? [];
    if (!is_array($values)) {
        sendJsonResponse(["status" => "error", "message" => "Date invalide"], 400);
    }

    // In modul Restaurant, zecimalele cantitatii se pot modifica doar daca
    // toate mesele sunt inchise (in FastFood nu exista ecran de mese).
    if (array_key_exists('NrZecCant', $values)
        && setariTipVanz($conn) !== "fastfood"
        && setariOpenBills($conn) > 0) {
        sendJsonResponse([
            "status" => "error",
            "message" => "Numarul de zecimale poate fi modificat doar cand toate mesele sunt inchise."
        ], 400);
    }

    sqlsrv_begin_transaction($conn);
    foreach ($values as $setting => $value) {
        $setting = trim((string)$setting);
        if ($setting === '') { continue; }
        $stmt = sqlsrv_query($conn, "UPDATE tblSet SET Value = ? WHERE Setting = ?", [$value, $setting]);
        if ($stmt === false) {
            sqlsrv_rollback($conn);
            sendJsonResponse(["status" => "error", "message" => "Eroare salvare setare: " . sqlsrv_errors()[0]['message']], 500);
        }
    }
    sqlsrv_commit($conn);

    sendJsonResponse(["status" => "success", "message" => "Setarile au fost salvate"]);
}

// Generare cheie API noua (globala). Cheia in clar se intoarce o singura data;
// in baza de date se pastreaza doar SHA-256.
if ($action === 'apikey_generate') {
    ensureSetariMeta($conn);
    $key = 'tm_' . bin2hex(random_bytes(32));
    $hash = hash('sha256', $key);
    $stmt = sqlsrv_query($conn, "UPDATE tblSet SET Value = ? WHERE Setting = 'ApiKeyHash'", [$hash]);
    if ($stmt === false) {
        sendJsonResponse(["status" => "error", "message" => "Eroare salvare cheie: " . sqlsrv_errors()[0]['message']], 500);
    }
    sendJsonResponse([
        "status" => "success",
        "apiKey" => $key,
        "message" => "Cheie API generata. Copiati-o acum: nu va mai fi afisata."
    ]);
}

// Sterge cheia API (revocare). API-ul devine inutilizabil pana la generarea alteia.
if ($action === 'apikey_revoke') {
    ensureSetariMeta($conn);
    sqlsrv_query($conn, "UPDATE tblSet SET Value = '' WHERE Setting = 'ApiKeyHash'");
    sendJsonResponse(["status" => "success", "message" => "Cheia API a fost revocata"]);
}

sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
