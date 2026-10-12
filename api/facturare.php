<?php
/**
 * API: Facturare (ecranul Facturare POS)
 *  GET  ?action=lista              -> notele inchise din ziua curenta (NrNota,
 *                                     Suma, Ospatar, Masa) pentru selectia bonului.
 *  POST {action:'print', docId, client:{...}} -> construieste factura (antetul
 *                                     furnizorului din tblAntet + datele
 *                                     clientului + liniile notei) si o trimite
 *                                     la tiparire pe imprimanta de note.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/print_common.php';
require_once __DIR__ . '/send_common.php';

/**
 * Notele inchise in ziua curenta, pentru comboboxul "Selectati bonul".
 *
 * @param resource $conn
 */
function facturareListaBonuri($conn) {
    $sql = "SELECT b.DocID AS DocID, b.NrDoc AS NrNota, b.NrMasa AS NrMasa,
                   COALESCE(o.Nume, 'CASIER 1') AS Ospatar,
                   ROUND(SUM(d.Cant * d.PV), 2) AS Suma
            FROM tblBonCurent b
            INNER JOIN tblNoteD d ON d.DocID = b.DocID
            LEFT JOIN tblOsp o ON b.NrOp = o.NrOsp
            WHERE b.Stare = 'I'
              AND CAST(COALESCE(b.Ora, b.Data) AS DATE) = CAST(GETDATE() AS DATE)
            GROUP BY b.DocID, b.NrDoc, b.NrMasa, o.Nume
            ORDER BY b.NrDoc DESC";
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        return null;
    }

    $bonuri = [];
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $bonuri[] = [
            "docId" => (int)$r['DocID'],
            "nrNota" => (int)$r['NrNota'],
            "masa" => (int)$r['NrMasa'],
            "ospatar" => trim((string)$r['Ospatar']),
            "suma" => round((float)$r['Suma'], 2)
        ];
    }
    return $bonuri;
}

/**
 * Incarca antetul unei note inchise (numar, masa, casier, data/ora).
 *
 * @param resource $conn
 * @param int $docId
 */
function facturareLoadBill($conn, $docId) {
    $sql = "SELECT b.DocID, b.NrDoc, b.NrMasa, b.NrOp, b.Data, b.Ora,
                   COALESCE(o.Nume, 'CASIER 1') AS NumeCasier
            FROM tblBonCurent b
            LEFT JOIN tblOsp o ON b.NrOp = o.NrOsp
            WHERE b.DocID = ? AND b.Stare = 'I'";
    $stmt = sqlsrv_query($conn, $sql, [(int)$docId]);
    if ($stmt === false) { return null; }
    $bon = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return $bon ?: null;
}

/**
 * Textul liber de subsol al facturii (tblSet.TextFactura), impartit in linii.
 * Mai multe randuri se separa prin punct si virgula (;) sau newline.
 *
 * @param resource $conn
 */
function facturareFooterTextLines($conn) {
    $stmt = sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'TextFactura'");
    $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    if (!$row) {
        @sqlsrv_query(
            $conn,
            "INSERT INTO tblSet (Setting, Value, Descriere, Grup) VALUES ('TextFactura', '', ?, 'General')",
            ["Text liber afisat in subsolul facturii (ecranul Facturare)."]
        );
        return [];
    }
    $text = (string)($row['Value'] ?? '');
    $lines = [];
    foreach (preg_split('/[\r\n;]+/', $text) as $p) {
        $p = trim($p);
        if ($p !== '') { $lines[] = $p; }
    }
    return $lines;
}

/**
 * Datele furnizorului din tblAntet (Seria = '0001').
 *
 * @param resource $conn
 */
function facturareFurnizor($conn) {
    $sql = "SELECT TOP 1 Nume, Denumire2, Adresa, CodFiscF, RegCom, Judet, Cont,
                   Banca, Oras, CodPostal, PersContact, email, website, telefon, PlatitorTVA
            FROM tblAntet WHERE Seria = '0001'";
    $stmt = sqlsrv_query($conn, $sql);
    $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    if (!$row) { return []; }
    return [
        "nume" => trim((string)($row['Nume'] ?? '')),
        "denumire2" => trim((string)($row['Denumire2'] ?? '')),
        "adresa" => trim((string)($row['Adresa'] ?? '')),
        "codFisc" => trim((string)($row['CodFiscF'] ?? '')),
        "regCom" => trim((string)($row['RegCom'] ?? '')),
        "judet" => trim((string)($row['Judet'] ?? '')),
        "oras" => trim((string)($row['Oras'] ?? '')),
        "codPostal" => trim((string)($row['CodPostal'] ?? '')),
        "cont" => trim((string)($row['Cont'] ?? '')),
        "banca" => trim((string)($row['Banca'] ?? '')),
        "telefon" => trim((string)($row['telefon'] ?? '')),
        "email" => trim((string)($row['email'] ?? '')),
        "website" => trim((string)($row['website'] ?? ''))
    ];
}

/**
 * Seria facturii, pastrata in tblSet.SerieFactura (creata la prima folosire).
 *
 * @param resource $conn
 */
function facturareSerieFactura($conn) {
    $stmt = sqlsrv_query($conn, "SELECT TOP 1 Value FROM tblSet WHERE Setting = 'SerieFactura'");
    $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    if ($row) {
        return trim((string)($row['Value'] ?? ''));
    }
    @sqlsrv_query(
        $conn,
        "INSERT INTO tblSet (Setting, Value, Descriere, Grup) VALUES ('SerieFactura', '', ?, 'General')",
        ["Seria facturii folosita la generarea facturii pe server (GenFactPoS)."]
    );
    return '';
}

/**
 * Creeaza tabela tblFacturaDoc (deploy fara pasi manuali): leaga nota locala
 * (DocID) de factura generata pe server, ca factura sa fie generata O SINGURA
 * data per nota. Printurile ulterioare refolosesc seria si numarul salvate.
 *
 * @param resource $conn
 */
function ensureFacturaDocTable($conn) {
    static $done = false;
    if ($done) { return; }
    @sqlsrv_query(
        $conn,
        "IF OBJECT_ID('dbo.tblFacturaDoc','U') IS NULL
         BEGIN
             CREATE TABLE dbo.tblFacturaDoc (
                 DocID       INT          NOT NULL PRIMARY KEY,
                 ServerDocID INT          NULL,
                 Serie       NVARCHAR(10) NULL,
                 NrDocFisc   INT          NULL,
                 QueueId     INT          NULL,
                 Stare       NVARCHAR(12) NOT NULL CONSTRAINT DF_tblFacturaDoc_Stare DEFAULT ('generating'),
                 LastError   NVARCHAR(500) NULL,
                 ClientCIF   NVARCHAR(18) NULL,
                 ClientVAT   INT          NULL,
                 ClientDen   NVARCHAR(150) NULL,
                 ClientAdresa NVARCHAR(250) NULL,
                 ClientJudet NVARCHAR(50) NULL,
                 ClientOras  NVARCHAR(100) NULL,
                 ClientRegCom NVARCHAR(15) NULL,
                 CreatedAt   DATETIME2    NOT NULL CONSTRAINT DF_tblFacturaDoc_Created DEFAULT (SYSDATETIME()),
                 UpdatedAt   DATETIME2    NULL
             );
         END",
        null,
        ['QueryTimeout' => 15]
    );
    // Migrare pentru instalari care au deja tabela fara noile coloane.
    $columns = [
        'ServerDocID' => "INT NULL",
        'Serie'       => "NVARCHAR(10) NULL",
        'NrDocFisc'   => "INT NULL",
        'QueueId'     => "INT NULL",
        'Stare'       => "NVARCHAR(12) NOT NULL CONSTRAINT DF_tblFacturaDoc_Stare DEFAULT ('generating')",
        'LastError'   => "NVARCHAR(500) NULL",
        'ClientCIF'   => "NVARCHAR(18) NULL",
        'ClientVAT'   => "INT NULL",
        'ClientDen'   => "NVARCHAR(150) NULL",
        'ClientAdresa' => "NVARCHAR(250) NULL",
        'ClientJudet' => "NVARCHAR(50) NULL",
        'ClientOras'  => "NVARCHAR(100) NULL",
        'ClientRegCom' => "NVARCHAR(15) NULL",
        'CreatedAt'   => "DATETIME2 NULL",
        'UpdatedAt'   => "DATETIME2 NULL",
    ];
    foreach ($columns as $col => $def) {
        @sqlsrv_query(
            $conn,
            "IF COL_LENGTH('dbo.tblFacturaDoc','$col') IS NULL ALTER TABLE dbo.tblFacturaDoc ADD $col $def",
            null,
            ['QueryTimeout' => 15]
        );
    }
    $done = true;
}

/**
 * Citeste randul de stare al facturii pentru o nota.
 *
 * @param resource $conn
 * @param int $docId
 */
function facturareReadDoc($conn, $docId) {
    $stmt = sqlsrv_query(
        $conn,
        "SELECT TOP 1 DocID, ServerDocID, Serie, NrDocFisc, QueueId, Stare, UpdatedAt
         FROM tblFacturaDoc WHERE DocID = ?",
        [(int)$docId]
    );
    return $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
}

/**
 * Salveaza datele clientului folosite la generarea facturii (pentru reprinturi,
 * fara a mai depinde de formular). Se scrie de proprietarul care genereaza.
 *
 * @param resource $conn
 * @param int $docId
 * @param array $client
 */
function facturareStoreClient($conn, $docId, $client) {
    @sqlsrv_query(
        $conn,
        "UPDATE tblFacturaDoc
         SET ClientCIF = ?, ClientVAT = ?, ClientDen = ?, ClientAdresa = ?,
             ClientJudet = ?, ClientOras = ?, ClientRegCom = ?, UpdatedAt = SYSDATETIME()
         WHERE DocID = ?",
        [
            (string)($client['cif'] ?? ''),
            ((int)($client['vat'] ?? 0) === 1) ? 1 : 0,
            (string)($client['denumire'] ?? ''),
            (string)($client['adresa'] ?? ''),
            (string)($client['judet'] ?? ''),
            (string)($client['oras'] ?? ''),
            (string)($client['regCom'] ?? ''),
            (int)$docId
        ]
    );
}

/**
 * Datele clientului salvate pentru o nota deja facturata, sau null daca nu
 * exista (nota inca nefacturata).
 *
 * @param resource $conn
 * @param int $docId
 */
function facturareStoredClient($conn, $docId) {
    $stmt = sqlsrv_query(
        $conn,
        "SELECT TOP 1 ClientCIF, ClientVAT, ClientDen, ClientAdresa, ClientJudet, ClientOras, ClientRegCom, Stare, NrDocFisc
         FROM tblFacturaDoc WHERE DocID = ?",
        [(int)$docId]
    );
    $r = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    if (!$r) { return null; }
    if (trim((string)$r['ClientCIF']) === '' && trim((string)$r['ClientDen']) === '') { return null; }
    return [
        "cif" => trim((string)$r['ClientCIF']),
        "vat" => ((int)$r['ClientVAT'] === 1) ? 1 : 0,
        "denumire" => trim((string)$r['ClientDen']),
        "adresa" => trim((string)$r['ClientAdresa']),
        "judet" => trim((string)$r['ClientJudet']),
        "oras" => trim((string)$r['ClientOras']),
        "regCom" => trim((string)$r['ClientRegCom'])
    ];
}

/**
 * Starea facturii pentru o nota (generata sau nu), pentru ecranul de facturare:
 * intoarce ['generated'=>bool, 'serie'=>..., 'nrFactura'=>..., 'client'=>...].
 *
 * @param resource $conn
 * @param int $docId
 */
function facturareDocStatus($conn, $docId) {
    $row = facturareReadDoc($conn, $docId);
    $done = ($row && trim((string)$row['Stare']) === 'done' && (int)($row['NrDocFisc'] ?? 0) > 0);
    // Datele clientului exista imediat ce nota a intrat in tblFacturaDoc
    // (le scrie proprietarul la trimiterea comenzii), chiar daca generarea e
    // inca in curs; atunci blocam formularul pentru reprinturi.
    $client = $row ? facturareStoredClient($conn, $docId) : null;
    return [
        "inDoc" => ($row !== null),
        "generated" => $done,
        "serie" => $done ? trim((string)($row['Serie'] ?? '')) : '',
        "nrFactura" => $done ? (int)$row['NrDocFisc'] : 0,
        "client" => $client
    ];
}

/**
 * Claim atomic pe DocID (PK): cine reuseste sa scrie/actualizeze randul devine
 * proprietarul generarii. Intoarce true daca trebuie sa generam noi factura.
 *
 * - randul lipseste -> INSERT 'generating' (doar un request reuseste, PK).
 * - randul e 'done' -> false (o refolosim).
 * - randul e 'failed' sau 'generating' de mult timp (stale) -> preluare.
 *
 * @param resource $conn
 * @param int $docId
 */
function facturareClaimDoc($conn, $docId) {
    $docId = (int)$docId;
    $row = facturareReadDoc($conn, $docId);

    if (!$row) {
        $ins = @sqlsrv_query(
            $conn,
            "INSERT INTO tblFacturaDoc (DocID, Stare, UpdatedAt) VALUES (?, 'generating', SYSDATETIME())",
            [$docId]
        );
        return ($ins !== false);
    }

    if (trim((string)$row['Stare']) === 'done' && (int)($row['NrDocFisc'] ?? 0) > 0) {
        return false;
    }

    // Preluare doar daca a esuat sau daca e blocat pe 'generating' de prea mult.
    $upd = @sqlsrv_query(
        $conn,
        "UPDATE tblFacturaDoc SET Stare = 'generating', UpdatedAt = SYSDATETIME()
         WHERE DocID = ?
           AND ( Stare = 'failed'
                 OR ( Stare = 'generating'
                      AND (UpdatedAt IS NULL OR UpdatedAt < DATEADD(second, -60, GETDATE())) ) )",
        [$docId]
    );
    if ($upd === false) { return false; }
    return ((int)sqlsrv_rows_affected($upd) > 0);
}

/**
 * Salveaza rezultatul final (seria si numarul) si marcheaza factura 'done'.
 *
 * @param resource $conn
 * @param int $docId
 * @param array $res
 */
function facturareStoreDoc($conn, $docId, $res) {
    $docId = (int)$docId;
    $serverDocId = (int)($res['DocID'] ?? 0);
    $serie = trim((string)($res['Serie'] ?? ''));
    $nr = (int)($res['NrDocFisc'] ?? 0);
    @sqlsrv_query(
        $conn,
        "UPDATE tblFacturaDoc
         SET ServerDocID = ?, Serie = ?, NrDocFisc = ?, Stare = 'done', LastError = NULL, UpdatedAt = SYSDATETIME()
         WHERE DocID = ?",
        [$serverDocId, $serie, $nr, $docId]
    );
}

/**
 * Marcheaza generarea ca esuata (permite o noua incercare ulterioara).
 *
 * @param resource $conn
 * @param int $docId
 * @param string $msg
 */
function facturareMarkFailed($conn, $docId, $msg) {
    @sqlsrv_query(
        $conn,
        "UPDATE tblFacturaDoc SET Stare = 'failed', LastError = ?, UpdatedAt = SYSDATETIME() WHERE DocID = ?",
        [mb_substr((string)$msg, 0, 500), (int)$docId]
    );
}

/**
 * Verifica daca randul din coada s-a terminat cu esec definitiv.
 *
 * @param resource $conn
 * @param int $queueId
 */
function facturareQueueFailed($conn, $queueId) {
    $stmt = sqlsrv_query($conn, "SELECT TOP 1 Stare FROM temp_Send_Sql WHERE Id = ?", [(int)$queueId]);
    $r = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
    return ($r && trim((string)$r['Stare']) === 'failed');
}

/**
 * Secunde ramase pana la deadline (minimum 1).
 *
 * @param float $deadline
 */
function facturareRemaining($deadline) {
    return max(1, (int)ceil($deadline - microtime(true)));
}

/**
 * Construieste rezultatul standard din randul de stare.
 *
 * @param array $row
 */
function facturareRowResult($row) {
    return [
        "DocID" => (int)($row['ServerDocID'] ?? 0),
        "Serie" => trim((string)($row['Serie'] ?? '')),
        "NrDocFisc" => (int)($row['NrDocFisc'] ?? 0)
    ];
}

/**
 * Genereaza factura pentru nota (proprietarul claim-ului): reia randul din coada
 * daca exista (QueueId), altfel construieste comanda GenFactPoS, o trimite si
 * asteapta rezultatul pana la deadline.
 *
 * @param resource $conn
 * @param int $docId
 * @param array $bon
 * @param array $client
 * @param float $deadline
 */
function facturareGenerateForDoc($conn, $docId, $bon, $client, $deadline) {
    $docId = (int)$docId;
    $row = facturareReadDoc($conn, $docId);
    $queueId = $row ? (int)($row['QueueId'] ?? 0) : 0;

    // Reluam un rand deja trimis (reluare dupa timeout / crash).
    if ($queueId > 0) {
        $res = facturareWaitResult($conn, $queueId, facturareRemaining($deadline));
        if (is_array($res) && (int)($res['NrDocFisc'] ?? 0) > 0) {
            facturareStoreDoc($conn, $docId, $res);
            return ["DocID" => (int)($res['DocID'] ?? 0), "Serie" => trim((string)($res['Serie'] ?? '')), "NrDocFisc" => (int)$res['NrDocFisc']];
        }
        // Randul e inca in lucru -> pastram QueueId si iesim (fallback).
        if (!facturareQueueFailed($conn, $queueId)) {
            return null;
        }
        // Randul a esuat definitiv -> trimitem o comanda noua.
    }

    $genSql = facturareBuildGenFactSql($conn, $bon, $client);
    if ($genSql === false) {
        facturareMarkFailed($conn, $docId, 'Eroare construire comanda GenFactPoS');
        return null;
    }
    $newId = enqueueRawSendSql($conn, $docId, $genSql, 'factura');
    if ($newId === false) {
        facturareMarkFailed($conn, $docId, 'Eroare inscriere in coada temp_Send_Sql');
        return null;
    }
    @sqlsrv_query(
        $conn,
        "UPDATE tblFacturaDoc SET QueueId = ?, UpdatedAt = SYSDATETIME() WHERE DocID = ?",
        [$newId, $docId]
    );
    // Pastram datele clientului pentru reprinturi (independent de formular).
    facturareStoreClient($conn, $docId, $client);
    wakeSyncService();

    $res = facturareWaitResult($conn, $newId, facturareRemaining($deadline));
    if (is_array($res) && (int)($res['NrDocFisc'] ?? 0) > 0) {
        facturareStoreDoc($conn, $docId, $res);
        return ["DocID" => (int)($res['DocID'] ?? 0), "Serie" => trim((string)($res['Serie'] ?? '')), "NrDocFisc" => (int)$res['NrDocFisc']];
    }
    // Timeout: randul rămâne 'generating' cu QueueId, pentru reluare la urmatorul click.
    return null;
}

/**
 * Se asigura ca factura notei a fost generata o singura data pe server si
 * intoarce ['DocID','Serie','NrDocFisc'] (sau null).
 *
 * Claim atomic pe tblFacturaDoc (fara application lock): un singur request
 * devine proprietarul generarii; ceilalalti asteapta rezultatul sau il
 * refolosesc daca factura exista deja. Astfel se pot tipari mai multe facturi,
 * dar pe server se genereaza una singura.
 *
 * @param resource $conn
 * @param int $docId
 * @param array $bon
 * @param array $client
 */
function facturareEnsureServerInvoice($conn, $docId, $bon, $client) {
    ensureFacturaDocTable($conn);
    $docId = (int)$docId;
    $deadline = microtime(true) + 10.0;

    if (facturareClaimDoc($conn, $docId)) {
        return facturareGenerateForDoc($conn, $docId, $bon, $client, $deadline);
    }

    // Nu suntem proprietari: asteptam ca proprietarul sa termine.
    while (microtime(true) < $deadline) {
        $row = facturareReadDoc($conn, $docId);
        if ($row) {
            $stare = trim((string)$row['Stare']);
            if ($stare === 'done' && (int)($row['NrDocFisc'] ?? 0) > 0) {
                return facturareRowResult($row);
            }
            if ($stare === 'failed') {
                if (facturareClaimDoc($conn, $docId)) {
                    return facturareGenerateForDoc($conn, $docId, $bon, $client, $deadline);
                }
            }
        }
        usleep(250000); // 250 ms
    }
    return null;
}

/**
 * Construieste comanda SQL care apeleaza procedura [dbo].[GenFactPoS] pe
 * serverul extern si intoarce DocID-ul generat + seria si numarul facturii.
 *
 * @param resource $conn
 * @param array $bon
 * @param array $client
 */
function facturareBuildGenFactSql($conn, $bon, $client) {
    $docId = (int)$bon['DocID'];

    // sVanzare = "ProdID:Cant:Sval:TVAC;" pentru fiecare linie de produs.
    $stmtArt = sqlsrv_query(
        $conn,
        "SELECT ProdID, Cant, PV, TVAc FROM tblNoteD
         WHERE DocID = ? AND ProdID > 0
         ORDER BY OraComanda, ECRID",
        [$docId]
    );
    if ($stmtArt === false) { return false; }

    $sVanzare = '';
    while ($r = sqlsrv_fetch_array($stmtArt, SQLSRV_FETCH_ASSOC)) {
        $cant = (float)$r['Cant'];
        $pv = (float)$r['PV'];
        $sVanzare .= (int)$r['ProdID'] . ':'
            . sendFmtNum($cant) . ':'
            . sendFmtNum($cant * $pv) . ':'
            . (int)($r['TVAc'] ?? 0) . ';';
    }

    $magId = getTblSetInt($conn, 'MagID', 0);
    $statieId = getTblSetInt($conn, 'NrLogic', 0);
    $serie = facturareSerieFactura($conn);
    $nrDoc = (int)$bon['NrDoc'];

    // Data locala ca dd/mm/yyyy (stil 103). Procedura declara @Data datetime,
    // deci o convertim explicit cu stilul 103 (robust indiferent de limba
    // serverului, care altfel ar interpreta gresit dd/mm/yyyy).
    $stmtNow = sqlsrv_query($conn, "SELECT CONVERT(varchar(10), GETDATE(), 103) AS D");
    $nowRow = $stmtNow ? sqlsrv_fetch_array($stmtNow, SQLSRV_FETCH_ASSOC) : null;
    $dataStr = $nowRow ? trim((string)$nowRow['D']) : date('d/m/Y');

    $vat = ((int)($client['vat'] ?? 0) === 1) ? 1 : 0;

    // Procedura GenFactPoS returneaza DocID-ul printr-un "SELECT @lDocID" (o
    // coloana fara nume / si alte result-set-uri din sub-proceduri), asa ca NU
    // folosim INSERT ... EXEC. @NrDocFisc = 0 lasa serverul sa atribuie numarul.
    // Dupa EXEC, citim factura proaspat creata din tblDoc (ultimul DocID pentru
    // MagID + StatieID + SerieFact), exact cum face si procedura.
    $sql = "DECLARE @dData datetime = CONVERT(datetime, " . sendSqlStr($dataStr) . ", 103);\n"
        . "DECLARE @MagID int = " . sendSqlNum($magId) . ";\n"
        . "DECLARE @StatieID int = " . sendSqlNum($statieId) . ";\n"
        . "DECLARE @Seria nvarchar(6) = " . sendSqlStr($serie) . ";\n"
        . "EXEC [dbo].[GenFactPoS]\n"
        . "    @Data = @dData,\n"
        . "    @NrDocFisc = 0,\n"
        . "    @Seria = @Seria,\n"
        . "    @MagID = @MagID,\n"
        . "    @NrBon = " . sendSqlNum($nrDoc) . ",\n"
        . "    @CIF = " . sendSqlStr($client['cif']) . ",\n"
        . "    @VAT = " . sendSqlNum($vat) . ",\n"
        . "    @DenFirma = " . sendSqlStr($client['denumire']) . ",\n"
        . "    @Adresa = " . sendSqlStr($client['adresa']) . ",\n"
        . "    @City = " . sendSqlStr($client['oras']) . ",\n"
        . "    @Judet = " . sendSqlStr($client['judet']) . ",\n"
        . "    @RegCom = " . sendSqlStr($client['regCom']) . ",\n"
        . "    @ZipCode = N'',\n"
        . "    @Country = N'RO',\n"
        . "    @sVanzare = " . sendSqlStr($sVanzare) . ",\n"
        . "    @StatieID = @StatieID;\n"
        . "SELECT TOP 1 d.DocID AS DocID, d.SerieFact AS Serie, d.NrDocFisc AS NrDocFisc\n"
        . "FROM tblDoc d\n"
        . "WHERE d.MagID = @MagID AND d.StatieID = @StatieID AND d.TipDoc = 'FAC' AND d.SerieFact = @Seria\n"
        . "ORDER BY d.DocID DESC;";
    return $sql;
}

/**
 * Asteapta (max $timeoutSec) rezultatul unei comenzi din temp_Send_Sql.
 * Intoarce array-ul decodat din Rezultat sau null (timeout / esec / gol).
 *
 * @param resource $conn
 * @param int $id
 * @param int $timeoutSec
 */
function facturareWaitResult($conn, $id, $timeoutSec = 10) {
    $id = (int)$id;
    $deadline = microtime(true) + max(1, (int)$timeoutSec);
    while (microtime(true) < $deadline) {
        $stmt = sqlsrv_query($conn, "SELECT Preluat, Stare, Rezultat FROM temp_Send_Sql WHERE Id = ?", [$id]);
        $r = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
        if ($r) {
            $preluat = ((int)$r['Preluat'] === 1);
            $stare = trim((string)($r['Stare'] ?? ''));
            if ($preluat || $stare === 'failed') {
                $raw = trim((string)($r['Rezultat'] ?? ''));
                if ($raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) { return $decoded; }
                }
                return null;
            }
        }
        usleep(250000); // 250 ms
    }
    return null;
}

/**
 * Construieste payload-ul facturii: furnizor (tblAntet) + client + liniile notei.
 * Liniile identice se cumuleaza, storno se scade. Valorile sunt cu TVA inclus
 * (PV include TVA); pentru fiecare cota se calculeaza baza si TVA.
 *
 * @param resource $conn
 * @param array $bon
 * @param array $client
 */
function facturareBuildPayload($conn, $bon, $client, $serie = '', $nrFactura = null) {
    // Subsolul facturii: doar textul liber din setari (nu liniile F1-F2 ale notei).
    $footerLines = facturareFooterTextLines($conn);

    $sqlArt = "SELECT d.ProdID, d.Cant, d.PV, d.PVC, d.TVAc, d.Descriere,
                      COALESCE(p.Denumire, d.Descriere, 'Produs #' + CAST(d.ProdID AS VARCHAR)) AS Denumire
               FROM tblNoteD d
               LEFT JOIN tblProd p ON d.ProdID = p.ProdID
               WHERE d.DocID = ?
               ORDER BY d.OraComanda, d.ECRID";
    $stmtArt = sqlsrv_query($conn, $sqlArt, [(int)$bon['DocID']]);
    if ($stmtArt === false) { return null; }

    $itemsByKey = [];
    $tvaByCota = [];
    $total = 0.0;
    $totalBaza = 0.0;

    while ($r = sqlsrv_fetch_array($stmtArt, SQLSRV_FETCH_ASSOC)) {
        $prodId = (int)$r['ProdID'];
        $cant = (float)$r['Cant'];
        if ($prodId === 0 && $cant <= 0.0) { continue; } // mod de preparare

        $pv = (float)$r['PV'];
        $cota = (float)($r['TVAc'] ?? 0);
        $val = $cant * $pv;

        $den = trim((string)$r['Denumire']);
        $key = ($prodId > 0) ? ('p' . $prodId) : ('n' . mb_strtolower($den));
        if (!isset($itemsByKey[$key])) {
            $itemsByKey[$key] = ["cant" => $cant, "denumire" => $den, "valoare" => $val, "cota" => $cota];
        } else {
            $itemsByKey[$key]["cant"] += $cant;
            $itemsByKey[$key]["valoare"] += $val;
        }

        $baza = ($cota > 0) ? ($val / (1 + $cota / 100)) : $val;
        $cotaKey = (string)$cota;
        if (!isset($tvaByCota[$cotaKey])) { $tvaByCota[$cotaKey] = ["baza" => 0.0, "suma" => 0.0]; }
        $tvaByCota[$cotaKey]["baza"] += $baza;
        $tvaByCota[$cotaKey]["suma"] += ($val - $baza);

        $total += $val;
        $totalBaza += $baza;
    }

    $items = [];
    foreach ($itemsByKey as $it) {
        if (abs($it["cant"]) < 0.0001) { continue; }
        $cant = round($it["cant"], 3);
        $val = round($it["valoare"], 2);
        $items[] = [
            "denumire" => $it["denumire"],
            "cant" => $cant,
            "cota" => $it["cota"],
            "valoare" => $val,
            "pret" => ($cant != 0.0) ? round($val / $cant, 4) : 0.0
        ];
    }

    $tvaList = [];
    foreach ($tvaByCota as $cota => $agg) {
        $tvaList[] = [
            "cota" => (float)$cota,
            "baza" => round($agg["baza"], 2),
            "suma" => round($agg["suma"], 2)
        ];
    }

    $dataStr = "";
    $oraRaw = !empty($bon['Ora']) ? $bon['Ora'] : ($bon['Data'] ?? null);
    if ($oraRaw) {
        try {
            $dt = new DateTime((string)$oraRaw);
            $dataStr = $dt->format('d-m-Y');
        } catch (Exception $e) {
            $dataStr = (string)$oraRaw;
        }
    }
    if ($dataStr === '') { $dataStr = date('d-m-Y'); }

    return [
        "title" => "FACTURA",
        "serie" => trim((string)$serie),
        "nrFactura" => ($nrFactura === null) ? (int)$bon['NrDoc'] : (int)$nrFactura,
        "nrNota" => (int)$bon['NrDoc'],
        "data" => $dataStr,
        "masa" => (int)$bon['NrMasa'],
        "casier" => trim((string)$bon['NumeCasier']),
        "furnizor" => facturareFurnizor($conn),
        "client" => $client,
        "items" => $items,
        "totalBaza" => round($totalBaza, 2),
        "tva" => $tvaList,
        "total" => round($total, 2),
        "footerLines" => $footerLines
    ];
}

$conn = getDBConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $action = strtolower(trim($_GET['action'] ?? 'lista'));

    if ($action === 'lista' || $action === 'list') {
        $bonuri = facturareListaBonuri($conn);
        if ($bonuri === null) {
            $err = sqlsrv_errors();
            sendJsonResponse(["status" => "error", "message" => "Eroare interogare bonuri: " . ($err[0]['message'] ?? 'eroare necunoscuta')], 500);
        }
        sendJsonResponse([
            "status" => "success",
            "data" => date('d-m-Y'),
            "bonuri" => $bonuri
        ]);
    }

    // Starea facturii pentru o nota (generata sau nu) + datele clientului salvat.
    if ($action === 'get' || $action === 'stare') {
        $docId = (int)($_GET['docId'] ?? 0);
        if ($docId <= 0) {
            sendJsonResponse(["status" => "error", "message" => "docId invalid"], 400);
        }
        ensureFacturaDocTable($conn);
        $status = facturareDocStatus($conn, $docId);
        sendJsonResponse(["status" => "success"] + $status);
    }

    sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}

// ---- POST ---------------------------------------------------------------
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input || !is_array($input)) { $input = $_POST; }

$action = $input['action'] ?? '';

if ($action === 'print') {
    $docId = (int)($input['docId'] ?? 0);
    if ($docId <= 0) {
        sendJsonResponse(["status" => "error", "message" => "Selectati mai intai bonul de facturat"], 400);
    }

    $cli = (is_array($input['client'] ?? null)) ? $input['client'] : [];
    $client = [
        "cif" => trim((string)($cli['cif'] ?? '')),
        "vat" => (trim((string)($cli['vat'] ?? '0')) === '1') ? 1 : 0,
        "denumire" => trim((string)($cli['denumire'] ?? '')),
        "adresa" => trim((string)($cli['adresa'] ?? '')),
        "judet" => trim((string)($cli['judet'] ?? '')),
        "oras" => trim((string)($cli['oras'] ?? '')),
        "regCom" => trim((string)($cli['regCom'] ?? ''))
    ];

    $bon = facturareLoadBill($conn, $docId);
    if (!$bon) {
        sendJsonResponse(["status" => "error", "message" => "Nota selectata nu a fost gasita (sau nu este inchisa)"], 404);
    }

    // Daca nota a fost deja facturata, datele de reprint sunt cele salvate in
    // tblFacturaDoc (formularul nu mai poate schimba partenerul unei facturi
    // deja emise pe server). Altfel validam datele introduse in formular.
    ensureFacturaDocTable($conn);
    $stored = facturareStoredClient($conn, $docId);
    if ($stored) {
        $client = $stored;
    } else {
        $lipsa = [];
        if ($client['cif'] === '') { $lipsa[] = "Cod Fiscal (CIF)"; }
        if ($client['denumire'] === '') { $lipsa[] = "Denumire Firma"; }
        if ($client['adresa'] === '') { $lipsa[] = "Adresa"; }
        if ($client['judet'] === '') { $lipsa[] = "Judet"; }
        if ($lipsa) {
            sendJsonResponse(["status" => "error", "message" => "Completati campurile obligatorii: " . implode(", ", $lipsa)], 400);
        }
    }

    // Generarea facturii pe server (GenFactPoS), idempotent per nota: se
    // apeleaza o singura data, apoi seria/numarul se refolosesc. La timeout /
    // Server = 0 folosim seria din setari si numarul notei (fallback).
    $serie = facturareSerieFactura($conn);
    $nrFactura = (int)$bon['NrDoc'];
    if (getTblSetInt($conn, 'Server', 0) === 1) {
        $gen = facturareEnsureServerInvoice($conn, $docId, $bon, $client);
        if (is_array($gen) && (int)($gen['NrDocFisc'] ?? 0) > 0) {
            $serie = trim((string)($gen['Serie'] ?? ''));
            $nrFactura = (int)$gen['NrDocFisc'];
        }
        // Dupa generare, datele clientului sunt cele salvate (proprietarul le-a
        // scris in tblFacturaDoc); le folosim ca sursa unica pentru factura.
        $stored = facturareStoredClient($conn, $docId);
        if ($stored) { $client = $stored; }
    }

    $payload = facturareBuildPayload($conn, $bon, $client, $serie, $nrFactura);
    if ($payload === null) {
        $err = sqlsrv_errors();
        sendJsonResponse(["status" => "error", "message" => "Eroare la construirea facturii: " . ($err[0]['message'] ?? 'eroare necunoscuta')], 500);
    }

    $jobId = enqueuePrintJob($conn, 'factura', $docId, null, $payload);
    if ($jobId === false) {
        $err = sqlsrv_errors();
        sendJsonResponse(["status" => "error", "message" => "Eroare inscriere in coada de tiparire: " . ($err[0]['message'] ?? 'eroare necunoscuta')], 500);
    }

    wakePrintService();
    $nrAfis = ($serie !== '') ? ($serie . ' ' . $nrFactura) : (string)$nrFactura;
    sendJsonResponse([
        "status" => "success",
        "message" => "Factura nr. " . $nrAfis . " trimisa la tiparire",
        "job" => $jobId,
        "serie" => $serie,
        "nrFactura" => $nrFactura
    ]);
}

sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
