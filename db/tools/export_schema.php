<?php
/**
 * export_schema.php - genereaza scripturile de reconstrucite a bazei de date.
 *
 * Ruleaza cu PHP CLI (Windows), ex.:
 *   C:\PHP\php.exe db\tools\export_schema.php            -> scrie db/schema.sql
 *   C:\PHP\php.exe db\tools\export_schema.php --seed     -> scrie si db/seed.sql
 *   C:\PHP\php.exe db\tools\export_schema.php --db=Rual_test
 *
 * Credentialele se citesc din api/db.local.php (acelasi mecanism ca aplicatia).
 * Sunt exportate doar tabelele folosite de aplicatie (whitelist mai jos).
 */

$ROOT      = dirname(__DIR__, 2);
$DB_LOCAL  = $ROOT . '/api/db.local.php';
$OUT_DIR   = $ROOT . '/db';

$APP_TABLES = [
    'tblTVA', 'tblSectii', 'tblFP', 'tblKP', 'tblSet', 'tblParola', 'tblOsp',
    'tblAntet', 'tblMese', 'tblMesaj', 'tblGrp', 'tblProd',
    'tblBonCurent', 'tblNoteD', 'tblBon', 'tempECR', 'tblNrZ',
    'trelDocIDFpID', 'trelArhZDocIDFpID', 'tblPrintQueue', 'tblConectare',
    'ERRORLOG',
];

$PROCS = ['usp_GetErrorInfo', 'RealizeazaZ', 'ImportProd'];

$SEED_TABLES = [
    'tblTVA', 'tblSectii', 'tblFP', 'tblKP', 'tblSet', 'tblParola', 'tblOsp',
    'tblAntet', 'tblMese', 'tblMesaj', 'tblConectare',
];

$withSeed = in_array('--seed', $argv, true);
$dbName   = null;
foreach ($argv as $a) {
    if (strpos($a, '--db=') === 0) {
        $dbName = substr($a, 5);
    }
}

if (!is_file($DB_LOCAL)) {
    fwrite(STDERR, "EROARE: lipseste $DB_LOCAL (creati-l din api/db.local.example.php).\n");
    exit(1);
}
$cfg = require $DB_LOCAL;
$server   = $cfg['Server']   ?? 'localhost';
$database = $dbName ?: ($cfg['Database'] ?? 'Rual');

$conn = sqlsrv_connect($server, [
    'Database'              => $database,
    'UID'                   => $cfg['UID'] ?? 'sa',
    'PWD'                   => $cfg['PWD'] ?? '',
    'CharacterSet'          => 'UTF-8',
    'ReturnDatesAsStrings'  => true,
]);
if (!$conn) {
    $e = sqlsrv_errors();
    fwrite(STDERR, "EROARE la conectare: " . ($e[0]['message'] ?? 'necunoscuta') . "\n");
    exit(1);
}

function ident($n) { return '[' . str_replace(']', ']]', $n) . ']'; }

function q($conn, $sql, $params = []) {
    $r = sqlsrv_query($conn, $sql, $params);
    if ($r === false) {
        $e = sqlsrv_errors();
        fwrite(STDERR, "EROARE query: " . ($e[0]['message'] ?? '') . "\n");
        exit(1);
    }
    return $r;
}

function rows($conn, $sql, $params = []) {
    $r = q($conn, $sql, $params);
    $out = [];
    while ($row = sqlsrv_fetch_array($r, SQLSRV_FETCH_ASSOC)) {
        $out[] = $row;
    }
    return $out;
}

function fetchColumns($conn, $table) {
    $sql = "SELECT c.column_id, c.name, ty.name AS type, c.max_length, c.precision, c.scale,
                   c.is_nullable, c.is_identity, c.is_computed,
                   dc.name AS default_name, dc.definition AS default_def,
                   ic.seed_value, ic.increment_value,
                   cc.definition AS computed_def
            FROM sys.columns c
            JOIN sys.types ty ON c.user_type_id = ty.user_type_id
            LEFT JOIN sys.default_constraints dc
                   ON dc.parent_object_id = c.object_id AND dc.parent_column_id = c.column_id
            LEFT JOIN sys.identity_columns ic
                   ON ic.object_id = c.object_id AND ic.column_id = c.column_id
            LEFT JOIN sys.computed_columns cc
                   ON cc.object_id = c.object_id AND cc.column_id = c.column_id
            WHERE c.object_id = OBJECT_ID(?)
            ORDER BY c.column_id";
    return rows($conn, $sql, [$table]);
}

function colTypeSql($c) {
    $t = $c['type'];
    if (in_array($t, ['nvarchar', 'nchar'], true)) {
        return $t . '(' . ($c['max_length'] == -1 ? 'MAX' : (int)($c['max_length'] / 2)) . ')';
    }
    if (in_array($t, ['varchar', 'char', 'binary', 'varbinary'], true)) {
        return $t . '(' . ($c['max_length'] == -1 ? 'MAX' : $c['max_length']) . ')';
    }
    if (in_array($t, ['decimal', 'numeric'], true)) {
        return $t . '(' . $c['precision'] . ',' . $c['scale'] . ')';
    }
    if (in_array($t, ['datetime2', 'datetimeoffset', 'time'], true)) {
        return $t . '(' . $c['scale'] . ')';
    }
    if ($t === 'float') {
        return ((int)$c['precision'] === 53) ? 'float' : 'float(' . $c['precision'] . ')';
    }
    return $t;
}

function keyColumns($conn, $table, $where) {
    $sql = "SELECT i.name, i.type_desc,
                   STUFF((SELECT ',' + QUOTENAME(c.name)
                          FROM sys.index_columns ic
                          JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
                          WHERE ic.object_id = i.object_id AND ic.index_id = i.index_id
                            AND ic.is_included_column = 0
                          ORDER BY ic.key_ordinal
                          FOR XML PATH('')), 1, 1, '') AS cols
            FROM sys.indexes i
            WHERE i.object_id = OBJECT_ID(?) AND $where";
    return rows($conn, $sql, [$table]);
}

function fetchIndexes($conn, $table) {
    $sql = "SELECT i.name, i.type_desc, i.is_unique, i.filter_definition,
                   STUFF((SELECT ',' + QUOTENAME(c.name)
                          FROM sys.index_columns ic
                          JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
                          WHERE ic.object_id = i.object_id AND ic.index_id = i.index_id
                            AND ic.is_included_column = 0
                          ORDER BY ic.key_ordinal
                          FOR XML PATH('')), 1, 1, '') AS kcols,
                   STUFF((SELECT ',' + QUOTENAME(c.name)
                          FROM sys.index_columns ic
                          JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
                          WHERE ic.object_id = i.object_id AND ic.index_id = i.index_id
                            AND ic.is_included_column = 1
                          ORDER BY ic.index_column_id
                          FOR XML PATH('')), 1, 1, '') AS icols
            FROM sys.indexes i
            WHERE i.object_id = OBJECT_ID(?) AND i.type IN (1, 2)
              AND i.is_primary_key = 0 AND i.is_unique_constraint = 0";
    return rows($conn, $sql, [$table]);
}

function fetchChecks($conn, $table) {
    return rows($conn, "SELECT name, definition FROM sys.check_constraints WHERE parent_object_id = OBJECT_ID(?)", [$table]);
}

function fetchForeignKeys($conn, $allowed) {
    $in = implode(',', array_map(fn($t) => "N'" . str_replace("'", "''", $t) . "'", $allowed));
    $sql = "SELECT fk.name,
                   OBJECT_NAME(fk.parent_object_id)     AS tbl,
                   OBJECT_NAME(fk.referenced_object_id) AS reftbl,
                   fk.delete_referential_action_desc    AS del_action,
                   fk.update_referential_action_desc    AS upd_action,
                   STUFF((SELECT ',' + QUOTENAME(pc.name)
                          FROM sys.foreign_key_columns fkc
                          JOIN sys.columns pc ON pc.object_id = fkc.parent_object_id
                                             AND pc.column_id = fkc.parent_column_id
                          WHERE fkc.constraint_object_id = fk.object_id
                          ORDER BY fkc.constraint_column_id
                          FOR XML PATH('')), 1, 1, '') AS pcols,
                   STUFF((SELECT ',' + QUOTENAME(rc.name)
                          FROM sys.foreign_key_columns fkc
                          JOIN sys.columns rc ON rc.object_id = fkc.referenced_object_id
                                             AND rc.column_id = fkc.referenced_column_id
                          WHERE fkc.constraint_object_id = fk.object_id
                          ORDER BY fkc.constraint_column_id
                          FOR XML PATH('')), 1, 1, '') AS rcols
            FROM sys.foreign_keys fk
            WHERE OBJECT_NAME(fk.parent_object_id) IN ($in)
              AND OBJECT_NAME(fk.referenced_object_id) IN ($in)
            ORDER BY fk.name";
    return rows($conn, $sql);
}

function actionSql($desc) {
    switch ($desc) {
        case 'CASCADE':     return 'CASCADE';
        case 'SET_NULL':    return 'SET NULL';
        case 'SET_DEFAULT': return 'SET DEFAULT';
        default:            return null;
    }
}

/* ------------------------------------------------------------------ schema */

$schema  = "-- ============================================================================\n";
$schema .= "-- TableManager 2.1C - schema bazei de date\n";
$schema .= "-- Generat automat de db/tools/export_schema.php (nu editati manual).\n";
$schema .= "-- Contine doar tabelele si procedurile folosite de aplicatie.\n";
$schema .= "-- ============================================================================\n\n";
$schema .= "SET ANSI_NULLS ON;\nGO\nSET QUOTED_IDENTIFIER ON;\nGO\n\n";
$schema .= "IF DB_ID(N'" . str_replace("'", "''", $database) . "') IS NULL\n";
$schema .= "BEGIN\n    CREATE DATABASE " . ident($database) . ";\nEND\nGO\n";
$schema .= "USE " . ident($database) . ";\nGO\n\n";

foreach ($APP_TABLES as $table) {
    $cols = fetchColumns($conn, $table);
    if (!$cols) {
        fwrite(STDERR, "AVERTISMENT: tabelul $table nu exista in baza de date, a fost sarit.\n");
        continue;
    }
    $schema .= "------------------------------------------------------------ $table\n";
    $schema .= "IF OBJECT_ID(N'dbo.$table', N'U') IS NULL\nBEGIN\n";
    $lines = [];
    foreach ($cols as $c) {
        if ((int)$c['is_computed'] === 1) {
            $lines[] = '    ' . ident($c['name']) . ' AS ' . $c['computed_def'];
            continue;
        }
        $line = '    ' . ident($c['name']) . ' ' . colTypeSql($c);
        if ((int)$c['is_identity'] === 1) {
            $line .= ' IDENTITY(' . (int)$c['seed_value'] . ',' . (int)$c['increment_value'] . ')';
        }
        $line .= ((int)$c['is_nullable'] === 1) ? ' NULL' : ' NOT NULL';
        if ($c['default_def'] !== null) {
            $line .= ' CONSTRAINT ' . ident($c['default_name']) . ' DEFAULT ' . $c['default_def'];
        }
        $lines[] = $line;
    }
    $schema .= "CREATE TABLE dbo." . ident($table) . " (\n" . implode(",\n", $lines) . "\n);\n";
    $schema .= "END\nGO\n";

    foreach (keyColumns($conn, $table, 'i.is_primary_key = 1') as $pk) {
        $schema .= "IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'" . str_replace("'", "''", $pk['name']) . "')\n";
        $schema .= "ALTER TABLE dbo." . ident($table) . " ADD CONSTRAINT " . ident($pk['name'])
                 . " PRIMARY KEY " . $pk['type_desc'] . " (" . $pk['cols'] . ");\nGO\n";
    }
    foreach (keyColumns($conn, $table, 'i.is_unique_constraint = 1') as $uq) {
        $schema .= "IF NOT EXISTS (SELECT 1 FROM sys.key_constraints WHERE name = N'" . str_replace("'", "''", $uq['name']) . "')\n";
        $schema .= "ALTER TABLE dbo." . ident($table) . " ADD CONSTRAINT " . ident($uq['name'])
                 . " UNIQUE " . $uq['type_desc'] . " (" . $uq['cols'] . ");\nGO\n";
    }
    foreach (fetchChecks($conn, $table) as $chk) {
        $schema .= "IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'" . str_replace("'", "''", $chk['name']) . "')\n";
        $schema .= "ALTER TABLE dbo." . ident($table) . " WITH CHECK ADD CONSTRAINT " . ident($chk['name'])
                 . " CHECK " . $chk['definition'] . ";\nGO\n";
    }
    foreach (fetchIndexes($conn, $table) as $ix) {
        if (empty($ix['kcols'])) continue;
        $stmt = "CREATE " . ((int)$ix['is_unique'] === 1 ? "UNIQUE " : "") . $ix['type_desc'] . " INDEX "
              . ident($ix['name']) . " ON dbo." . ident($table) . " (" . $ix['kcols'] . ")";
        if (!empty($ix['icols'])) $stmt .= " INCLUDE (" . $ix['icols'] . ")";
        if (!empty($ix['filter_definition'])) $stmt .= " WHERE " . $ix['filter_definition'];
        $schema .= "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'" . str_replace("'", "''", $ix['name']) . "' AND object_id = OBJECT_ID(N'dbo.$table'))\n";
        $schema .= $stmt . ";\nGO\n";
    }
    $schema .= "\n";
}

$schema .= "------------------------------------------------------------ chei straine\n";
foreach (fetchForeignKeys($conn, $APP_TABLES) as $fk) {
    $schema .= "IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'" . str_replace("'", "''", $fk['name']) . "')\n";
    $schema .= "ALTER TABLE dbo." . ident($fk['tbl']) . " WITH CHECK ADD CONSTRAINT " . ident($fk['name'])
             . " FOREIGN KEY (" . $fk['pcols'] . ") REFERENCES dbo." . ident($fk['reftbl']) . " (" . $fk['rcols'] . ")";
    $del = actionSql($fk['del_action']);
    $upd = actionSql($fk['upd_action']);
    if ($del) $schema .= " ON DELETE " . $del;
    if ($upd) $schema .= " ON UPDATE " . $upd;
    $schema .= ";\nGO\n";
}
$schema .= "\n";

$schema .= "------------------------------------------------------------ proceduri stocate\n";
foreach ($PROCS as $proc) {
    $r = q($conn, "SELECT m.definition FROM sys.procedures p JOIN sys.sql_modules m ON m.object_id = p.object_id WHERE p.name = ?", [$proc]);
    $row = sqlsrv_fetch_array($r, SQLSRV_FETCH_ASSOC);
    if (!$row) {
        fwrite(STDERR, "AVERTISMENT: procedura $proc nu exista, a fost sarita.\n");
        continue;
    }
    $def = preg_replace('/CREATE(\s+OR\s+ALTER)?\s+PROCEDURE/i', 'CREATE OR ALTER PROCEDURE', $row['definition'], 1);
    $def = str_replace('DataRON_CIORB', 'SourceDB', $def);
    $schema .= "SET ANSI_NULLS ON\nGO\nSET QUOTED_IDENTIFIER ON\nGO\n";
    $schema .= rtrim($def) . "\nGO\n\n";
}

if (!is_dir($OUT_DIR)) mkdir($OUT_DIR, 0777, true);
file_put_contents($OUT_DIR . '/schema.sql', $schema);
echo "Scris: db/schema.sql (" . strlen($schema) . " bytes)\n";

/* -------------------------------------------------------------------- seed */

if (!$withSeed) {
    exit(0);
}

function sqlLiteral($v, $type) {
    if ($v === null) return 'NULL';
    if (is_bool($v)) return $v ? '1' : '0';
    if (is_int($v) || is_float($v)) return rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');
    $numeric = ['int','smallint','tinyint','bigint','bit','decimal','numeric','float','real','money','smallmoney'];
    if (in_array($type, $numeric, true) && is_numeric($v)) return (string)$v;
    return "N'" . str_replace("'", "''", (string)$v) . "'";
}

function sanitizeRow($table, $row) {
    switch ($table) {
        case 'tblParola':
            foreach (['ParolaProgramare','ParolaRapoarte','ParolaStornare','ParolaDiscount','ParolaExit'] as $k) {
                if (array_key_exists($k, $row)) $row[$k] = '';
            }
            break;
        case 'tblOsp':
            $row['Nume']   = 'Ospatar ' . $row['NrOsp'];
            $row['Parola'] = '1';
            break;
        case 'tblConectare':
            foreach (['ServerIP','ServerName','DataBaseName','UserName','Password','ODBC_connect_string'] as $k) {
                if (array_key_exists($k, $row)) $row[$k] = '';
            }
            $row['TC'] = 0;
            break;
        case 'tblAntet':
            $row['VersiuneDB'] = 'TableManager 2.1C';
            if ($row['Seria'] === '0001') {
                $row['Nume']        = 'RESTAURANT DEMO';
                $row['Denumire2']   = 'DEMO SRL';
                $row['Adresa']      = 'Strada Exemplu nr. 1';
                $row['Oras']        = 'Bucuresti';
                $row['CodPostal']   = '010101';
                $row['CodFiscF']    = 'RO00000000';
                $row['RegCom']      = 'J00/0000/2024';
                $row['Judet']       = 'BUCURESTI';
                $row['Cont']        = 'RO00BANK000000000000';
                $row['Banca']       = 'BANCA DEMO';
                $row['PersContact'] = '';
                $row['email']       = 'contact@example.com';
                $row['website']     = 'https://example.com';
                $row['telefon']     = '';
                $row['NumeFont']    = 'A';
                $row['CaleDateLogo']= '';
                $row['QRCodeText']  = '';
            } else {
                $generic = [
                    'H1' => 'RESTAURANT DEMO',
                    'H2' => 'Strada Exemplu nr. 1',
                    'H3' => 'Telefon: 0000.000.000',
                    'F1' => 'Va multumim!',
                    'F2' => 'Va mai asteptam!',
                ];
                if (isset($generic[$row['Seria']])) $row['Nume'] = $generic[$row['Seria']];
            }
            break;
        case 'tblKP':
            $row['DenumirePrinter'] = 'Imprimanta demo';
            $row['CaleTSC']         = null;
            break;
        case 'tblSet':
            $map = [
                'SerieIF'       => '000000',
                'DistrRand1'    => 'demo.local',
                'DistrRand2'    => 'Suport demo',
                'SiteUrlApi'    => '',
                'ConsumerKey'   => '',
                'ConsumerSecret'=> '',
                'CaleDriverECR' => 'C:\\POS\\Fprint.exe',
                'CaleFComenzi'  => 'C:\\POS\\fiscal\\bonuri',
                'CaleFLog'      => 'C:\\POS\\fiscal\\raspuns',
            ];
            if (array_key_exists($row['Setting'], $map)) $row['Value'] = $map[$row['Setting']];
            break;
    }
    return $row;
}

function insertBlock($table, $cols, $rows) {
    $names = [];
    foreach ($cols as $c) {
        if ((int)$c['is_identity'] === 1 || (int)$c['is_computed'] === 1) continue;
        $names[] = ident($c['name']);
    }
    $types = [];
    foreach ($cols as $c) $types[$c['name']] = $c['type'];

    $sql = "";
    foreach (array_chunk($rows, 100) as $chunk) {
        $values = [];
        foreach ($chunk as $row) {
            $vals = [];
            foreach ($cols as $c) {
                if ((int)$c['is_identity'] === 1 || (int)$c['is_computed'] === 1) continue;
                $vals[] = sqlLiteral($row[$c['name']] ?? null, $c['type']);
            }
            $values[] = '(' . implode(', ', $vals) . ')';
        }
        $sql .= "INSERT INTO dbo." . ident($table) . " (" . implode(', ', $names) . ") VALUES\n"
              . implode(",\n", $values) . ";\n";
    }
    return $sql;
}

$seed  = "-- ============================================================================\n";
$seed .= "-- TableManager 2.1C - date de referinta (seed) pentru o instalare noua\n";
$seed .= "-- Generat automat de db/tools/export_schema.php --seed, apoi sanitizat.\n";
$seed .= "-- NU contine date reale: parolele, credențialele si datele firmei sunt demo.\n";
$seed .= "-- Se ruleaza dupa db/schema.sql, pe o baza de date goala.\n";
$seed .= "-- ============================================================================\n\n";
$seed .= "USE " . ident($database) . ";\nGO\nSET NOCOUNT ON;\nGO\n\n";

foreach ($SEED_TABLES as $table) {
    $cols = fetchColumns($conn, $table);
    $rows = [];
    foreach (rows($conn, "SELECT * FROM " . ident($table)) as $row) {
        $rows[] = sanitizeRow($table, $row);
    }
    $seed .= "------------------------------------------------------------ $table (" . count($rows) . " randuri)\n";
    $seed .= "DELETE FROM dbo." . ident($table) . ";\nGO\n";
    $seed .= insertBlock($table, $cols, $rows);
    $seed .= "GO\n\n";
}

/* meniu demo (nu date reale) */

// [NrGrp, Denumire, BackColor, Poz, NrKp]
$groups = [
    [1, 'Supe',                0x00E8F5D0, 1, 2],
    [2, 'Feluri principale',   0x00FFE2C0, 2, 2],
    [3, 'Garnituri',           0x00FFF2B0, 3, 2],
    [4, 'Salate',              0x00D8F0D0, 4, 2],
    [5, 'Deserturi',           0x00F3D9F0, 5, 2],
    [6, 'Bauturi racoritoare', 0x00D8E8F8, 6, 1],
    [7, 'Cafea',               0x00E8D8C8, 7, 1],
    [8, 'Bere',                0x00F8E8B8, 8, 1],
];
// [ProdID, BarCod, Denumire, UM, PV, Nr_TVA, NrGrp, Poz, MZ]
$products = [
    [101, '2800000001017', 'Ciorba de burta',          'buc', 22.00, 1, 1, 1, 0],
    [102, '2800000001024', 'Supa crema de legume',     'buc', 18.00, 1, 1, 2, 0],
    [201, '2800000002014', 'Snitel de pui cu cartofi', 'buc', 38.00, 1, 2, 1, 1],
    [202, '2800000002021', 'Cordon bleu de pui',       'buc', 40.00, 1, 2, 2, 0],
    [203, '2800000002038', 'Pasta carbonara',          'buc', 35.00, 1, 2, 3, 0],
    [204, '2800000002045', 'Burger de vita',           'buc', 42.00, 1, 2, 4, 1],
    [301, '2800000003011', 'Cartofi prajiti',          'buc', 12.00, 1, 3, 1, 0],
    [302, '2800000003028', 'Orez cu legume',           'buc', 12.00, 1, 3, 2, 0],
    [401, '2800000004018', 'Salata greceasca',         'buc', 26.00, 1, 4, 1, 0],
    [402, '2800000004025', 'Salata Caesar',            'buc', 30.00, 1, 4, 2, 0],
    [501, '2800000005015', 'Tiramisu',                 'buc', 22.00, 1, 5, 1, 0],
    [502, '2800000005022', 'Papanasi',                 'buc', 24.00, 1, 5, 2, 0],
    [601, '2800000006012', 'Coca-Cola',                'buc', 10.00, 1, 6, 1, 0],
    [602, '2800000006029', 'Apa minerala',             'buc',  8.00, 2, 6, 2, 0],
    [701, '2800000007019', 'Espresso',                 'buc',  9.00, 1, 7, 1, 0],
    [702, '2800000007026', 'Cappuccino',               'buc', 13.00, 1, 7, 2, 0],
    [801, '2800000008016', 'Bere la halba',            'buc', 12.00, 1, 8, 1, 0],
    [802, '2800000008023', 'Bere la sticla',           'buc', 14.00, 1, 8, 2, 0],
];

$seed .= "------------------------------------------------------------ tblGrp (meniu demo)\n";
$seed .= "DELETE FROM dbo.[tblGrp];\nGO\n";
$seed .= "INSERT INTO dbo.[tblGrp] ([NrGrp],[Denumire],[BackColor],[FontColor],[FontSize],[Bold],[FontType],[Tint],[Poz],[NrKp]) VALUES\n";
$gv = [];
foreach ($groups as $g) {
    $gv[] = '(' . $g[0] . ", N'" . str_replace("'", "''", $g[1]) . "', " . $g[2] . ', 0, 14, 0, N\'Arial\', 0, ' . $g[3] . ', ' . $g[4] . ')';
}
$seed .= implode(",\n", $gv) . ";\nGO\n\n";

$seed .= "------------------------------------------------------------ tblProd (meniu demo)\n";
$seed .= "DELETE FROM dbo.[tblProd];\nGO\n";
$seed .= "INSERT INTO dbo.[tblProd] ([ProdID],[BarCod],[Denumire],[UM],[NrGrp],[KP],[Bold],[Poz],[Sectie],[PV],[Nr_TVA],[MZ]) VALUES\n";
$pv = [];
foreach ($products as $p) {
    [$id, $bc, $den, $um, $price, $tva, $grp, $poz, $mz] = $p;
    $sectie = ($grp >= 6) ? 1 : 2;
    $kp     = ($grp >= 6) ? 1 : 2;
    $pv[] = '(' . $id . ", N'" . $bc . "', N'" . str_replace("'", "''", $den) . "', N'" . $um . "', "
          . $grp . ', ' . $kp . ', 0, ' . $poz . ', ' . $sectie . ', '
          . sprintf('%.2F', $price) . ', ' . $tva . ', ' . $mz . ')';
}
$seed .= implode(",\n", $pv) . ";\nGO\n";

file_put_contents($OUT_DIR . '/seed.sql', $seed);
echo "Scris: db/seed.sql (" . strlen($seed) . " bytes)\n";
