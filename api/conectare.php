<?php
/**
 * API: Conectare server (tblConectare) - un singur rand (ID = 1)
 * GET  -> datele de conectare
 * POST -> actiune: update
 */
require_once __DIR__ . '/db.php';

/**
 * Creeaza tabelele de mapare server->local daca lipsesc (deploy fara pasi
 * manuali). Sunt folosite la import (ImportProd) si editate in ecranul
 * Conectare server.
 */
function ensureMappingTables($conn) {
    static $done = false;
    if ($done) { return; }
    @sqlsrv_query($conn,
        "IF OBJECT_ID('dbo.tblMapKP','U') IS NULL
             CREATE TABLE dbo.tblMapKP (KPServer int NOT NULL CONSTRAINT PK_tblMapKP PRIMARY KEY, KPLocal int NOT NULL);
         IF OBJECT_ID('dbo.tblMapSectii','U') IS NULL
             CREATE TABLE dbo.tblMapSectii (SectieServer int NOT NULL CONSTRAINT PK_tblMapSectii PRIMARY KEY, SectieLocal int NOT NULL)",
        null,
        ['QueryTimeout' => 15]
    );
    $done = true;
}

/**
 * Citeste o tabela de mapare ca lista de randuri [server, local].
 */
function readMapping($conn, $table, $serverCol, $localCol) {
    $rows = [];
    $stmt = @sqlsrv_query($conn, "SELECT $serverCol AS S, $localCol AS L FROM $table ORDER BY $serverCol");
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = ["server" => (int)$r['S'], "local" => (int)$r['L']];
        }
    }
    return $rows;
}

/**
 * Inlocuieste continutul unei tabele de mapare. Intoarce string de eroare sau null.
 */
function saveMapping($conn, $table, $serverCol, $localCol, $rows) {
    if (!sqlsrv_query($conn, "DELETE FROM $table")) {
        return "Eroare stergere $table: " . sqlsrv_errors()[0]['message'];
    }
    $seen = [];
    foreach ((array)$rows as $row) {
        $sRaw = $row['server'] ?? null;
        $lRaw = $row['local'] ?? null;
        if ($sRaw === null || $sRaw === '' || $lRaw === null || $lRaw === '') { continue; }
        if (!is_numeric($sRaw) || !is_numeric($lRaw)) {
            return "Valori invalide in $table (trebuie numere intregi).";
        }
        $s = (int)$sRaw;
        $l = (int)$lRaw;
        if (isset($seen[$s])) {
            return "$serverCol=$s este duplicat (o singura mapare per valoare de server).";
        }
        $seen[$s] = true;
        $stmt = sqlsrv_query($conn, "INSERT INTO $table ($serverCol, $localCol) VALUES (?, ?)", [$s, $l]);
        if (!$stmt) {
            return "Eroare salvare in $table: " . sqlsrv_errors()[0]['message'];
        }
    }
    return null;
}

/**
 * Optiunile pentru combobox-uri: KP si Sectii de pe serverul local.
 */
function readLocalOptions($conn) {
    $kp = [];
    $sec = [];
    $r = @sqlsrv_query($conn, "SELECT NrLogic, Nume FROM tblKP ORDER BY NrLogic");
    if ($r) {
        while ($x = sqlsrv_fetch_array($r, SQLSRV_FETCH_ASSOC)) {
            $kp[] = ["value" => (int)$x['NrLogic'], "label" => trim((string)($x['Nume'] ?? ''))];
        }
    }
    $r = @sqlsrv_query($conn, "SELECT Sectie, Denumire FROM tblSectii ORDER BY Sectie");
    if ($r) {
        while ($x = sqlsrv_fetch_array($r, SQLSRV_FETCH_ASSOC)) {
            $sec[] = ["value" => (int)$x['Sectie'], "label" => trim((string)($x['Denumire'] ?? ''))];
        }
    }
    return [$kp, $sec];
}

/**
 * Optiunile pentru combobox-uri: KP si Sectii de pe serverul extern, citite
 * direct prin datele din tblConectare (daca serverul e indisponibil, listele
 * sunt goale si se intoarce un mesaj).
 */
function readRemoteOptions($conn) {
    $out = ["serverKP" => [], "serverSectii" => [], "error" => null];
    $s = @sqlsrv_query($conn, "SELECT TOP 1 ServerIP, ServerName, DataBaseName, UserName, Password, TC FROM tblConectare WHERE ID = 1");
    $c = $s ? sqlsrv_fetch_array($s, SQLSRV_FETCH_ASSOC) : null;
    if (!$c) { $out['error'] = "Nu exista configurarea de conectare."; return $out; }

    $server = trim((string)$c['ServerIP']);
    $instance = trim((string)$c['ServerName']);
    $srv = ($instance !== '' && strcasecmp($instance, $server) !== 0) ? $server . '\\' . $instance : $server;
    if ($srv === '') { $out['error'] = "ServerIP lipsa."; return $out; }

    $ci = ["CharacterSet" => "UTF-8", "LoginTimeout" => 3];
    if (trim((string)$c['DataBaseName']) !== '') { $ci["Database"] = $c['DataBaseName']; }
    if ((int)$c['TC'] !== 1) { $ci["UID"] = $c['UserName']; $ci["PWD"] = $c['Password']; }

    $cn = @sqlsrv_connect($srv, $ci);
    if (!$cn) { $out['error'] = "Server indisponibil (" . $srv . ")."; return $out; }

    $r = @sqlsrv_query($cn, "SELECT KP, DenKP FROM tblKP ORDER BY KP");
    if ($r) {
        while ($x = sqlsrv_fetch_array($r, SQLSRV_FETCH_ASSOC)) {
            $out['serverKP'][] = ["value" => (int)$x['KP'], "label" => trim((string)($x['DenKP'] ?? ''))];
        }
    }
    $r = @sqlsrv_query($cn, "SELECT Sectie, Denumire FROM tblSectii ORDER BY Sectie");
    if ($r) {
        while ($x = sqlsrv_fetch_array($r, SQLSRV_FETCH_ASSOC)) {
            $out['serverSectii'][] = ["value" => (int)$x['Sectie'], "label" => trim((string)($x['Denumire'] ?? ''))];
        }
    }
    @sqlsrv_close($cn);
    return $out;
}

$conn = getDBConnection();
ensureMappingTables($conn);

$ID = 1;

// Listele pentru combobox-urile de mapare (local + server). Se cer separat, ca
// incarcarea formularului de conectare sa nu depinda de serverul extern.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['options'])) {
    list($localKP, $localSectii) = readLocalOptions($conn);
    $remote = readRemoteOptions($conn);
    sendJsonResponse([
        "status"     => "success",
        "mapOptions" => [
            "localKP"     => $localKP,
            "serverKP"    => $remote['serverKP'],
            "localSectii" => $localSectii,
            "serverSectii"=> $remote['serverSectii'],
            "serverError" => $remote['error']
        ]
    ]);
}

$CAMPURI = [
    'ServerIP'            => 50,
    'ServerName'          => 50,
    'DataBaseName'        => 50,
    'UserName'            => 50,
    'Password'            => 50,
    'ODBC_connect_string' => 255
];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $sql = "SELECT ID, ServerIP, ServerName, DataBaseName, UserName, Password, TC, ODBC_connect_string
            FROM tblConectare WHERE ID = ?";
    $stmt = sqlsrv_query($conn, $sql, [$ID]);
    if (!$stmt) {
        sendJsonResponse(["status" => "error", "message" => "Eroare citire conectare: " . sqlsrv_errors()[0]['message']], 500);
    }
    $r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if (!$r) {
        sendJsonResponse(["status" => "error", "message" => "Nu exista randul de conectare (ID=1)"], 404);
    }

    $row = ["ID" => (int)$r['ID'], "TC" => (int)$r['TC']];
    foreach ($CAMPURI as $col => $len) {
        $row[$col] = trim($r[$col] ?? '');
    }
    sendJsonResponse([
        "status"    => "success",
        "row"       => $row,
        "mapKP"     => readMapping($conn, 'tblMapKP', 'KPServer', 'KPLocal'),
        "mapSectii" => readMapping($conn, 'tblMapSectii', 'SectieServer', 'SectieLocal')
    ]);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input || !is_array($input)) {
    $input = $_POST;
}

$action = $input['action'] ?? '';

switch ($action) {
    case 'update':
        $values = [];
        foreach ($CAMPURI as $col => $len) {
            $val = trim((string)($input[$col] ?? ''));
            if (mb_strlen($val) > $len) {
                sendJsonResponse(["status" => "error", "message" => "$col depaseste $len caractere"], 400);
            }
            $values[$col] = $val;
        }
        $tc = (int)($input['TC'] ?? 0) ? 1 : 0;
        $hasMaps = array_key_exists('mapKP', $input) || array_key_exists('mapSectii', $input);

        // Conexiunea si maparile se salveaza impreuna (totul sau nimic).
        sqlsrv_begin_transaction($conn);

        $sql = "UPDATE tblConectare SET
                    ServerIP = ?, ServerName = ?, DataBaseName = ?, UserName = ?,
                    Password = ?, TC = ?, ODBC_connect_string = ?
                WHERE ID = ?";
        $params = [
            $values['ServerIP'], $values['ServerName'], $values['DataBaseName'], $values['UserName'],
            $values['Password'], $tc, $values['ODBC_connect_string'], $ID
        ];
        $stmt = sqlsrv_query($conn, $sql, $params);
        if (!$stmt) {
            sqlsrv_rollback($conn);
            sendJsonResponse(["status" => "error", "message" => "Eroare actualizare conectare: " . sqlsrv_errors()[0]['message']], 500);
        }

        if ($hasMaps) {
            $err = saveMapping($conn, 'tblMapKP', 'KPServer', 'KPLocal', $input['mapKP'] ?? []);
            if ($err === null) {
                $err = saveMapping($conn, 'tblMapSectii', 'SectieServer', 'SectieLocal', $input['mapSectii'] ?? []);
            }
            if ($err !== null) {
                sqlsrv_rollback($conn);
                sendJsonResponse(["status" => "error", "message" => $err], 400);
            }
        }

        sqlsrv_commit($conn);
        sendJsonResponse(["status" => "success", "message" => "Conectare server actualizata"]);
        break;

    case 'test':
        // Testeaza conexiunea folosind valorile trimise din formular (chiar si
        // nesalvate), dupa aceeasi logica precum sync_products.php.
        $server   = trim((string)($input['ServerIP'] ?? ''));
        $instance = trim((string)($input['ServerName'] ?? ''));
        $database = trim((string)($input['DataBaseName'] ?? ''));
        $user     = trim((string)($input['UserName'] ?? ''));
        $pass     = (string)($input['Password'] ?? '');
        $tc       = (int)($input['TC'] ?? 0) ? true : false;

        $srv = ($instance !== '' && strcasecmp($instance, $server) !== 0)
            ? $server . '\\' . $instance
            : $server;

        if ($srv === '') {
            sendJsonResponse(["status" => "error", "message" => "ServerIP este obligatoriu"], 400);
        }

        $ci = [
            "CharacterSet" => "UTF-8",
            "LoginTimeout" => 5
        ];
        if ($database !== '') {
            $ci["Database"] = $database;
        }
        if (!$tc) {
            $ci["UID"] = $user;
            $ci["PWD"] = $pass;
        }

        $testConn = sqlsrv_connect($srv, $ci);
        if ($testConn) {
            sqlsrv_close($testConn);
            $target = $srv . ($database !== '' ? ' / ' . $database : '');
            sendJsonResponse(["status" => "success", "message" => "Conexiune reusita la " . $target]);
        }

        $err = sqlsrv_errors();
        $errMsg = $err ? $err[0]['message'] : 'eroare necunoscuta';
        sendJsonResponse(["status" => "error", "message" => "Conexiune esuata la " . $srv . ": " . $errMsg], 500);
        break;

    default:
        sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}
