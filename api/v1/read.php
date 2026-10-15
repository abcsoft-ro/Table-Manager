<?php
/**
 * read.php
 * Handlerele GET ale API-ului v1 (export / citire date).
 *
 * Fiecare handler intoarce [data, meta]. index.php le trimite prin apiRespond().
 */

/**
 * GET /api/v1/version
 *
 * @param resource $conn
 * @return array
 */
function apiV1VersionGet($conn) {
    require_once __DIR__ . '/../version_common.php';
    $i = appVersionInfo();
    return [[
        'version'   => $i['version'],
        'commit'    => $i['commit'],
        'short'     => $i['short'],
        'build'     => $i['build'],
        'branch'    => $i['branch'],
        'builtAt'   => $i['builtAt'],
        'updatedAt' => $i['updatedAt'],
        'source'    => $i['source'],
        'label'     => appVersionLabel(),
        'php'       => PHP_VERSION,
    ], null];
}

/**
 * GET /api/v1/products?nrgrp=&mz=&kp=&limit=&offset=
 *
 * @param resource $conn
 * @return array
 */
function apiV1ProductsGet($conn) {
    $limit = apiV1Limit();
    $offset = apiV1Offset();

    $where = [];
    $params = [];
    if (isset($_GET['nrgrp']) && $_GET['nrgrp'] !== '') {
        $where[] = 'p.NrGrp = ?';
        $params[] = (int)$_GET['nrgrp'];
    }
    if (isset($_GET['mz']) && $_GET['mz'] !== '') {
        $where[] = 'p.MZ = ?';
        $params[] = ((int)$_GET['mz'] === 0) ? 0 : 1;
    }
    if (isset($_GET['kp']) && $_GET['kp'] !== '') {
        $where[] = 'p.KP = ?';
        $params[] = (int)$_GET['kp'];
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $totalRow = apiV1FetchOne($conn, "SELECT COUNT(*) AS c FROM tblProd p $whereSql", $params);
    $total = $totalRow ? (int)$totalRow['c'] : 0;

    $p = $params;
    $p[] = $offset;
    $p[] = $limit;
    $rows = apiV1FetchAll(
        $conn,
        "SELECT p.* FROM tblProd p $whereSql
         ORDER BY p.NrGrp, p.Poz, p.ProdID
         OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
        $p
    );

    $out = array_map('apiV1Product', $rows);
    return [$out, array_merge(apiV1PageMeta($limit, $offset, count($out)), ['total' => $total])];
}

/**
 * GET /api/v1/groups
 *
 * @param resource $conn
 * @return array
 */
function apiV1GroupsGet($conn) {
    $rows = apiV1FetchAll(
        $conn,
        "SELECT NrGrp, Denumire, Poz, BackColor, FontColor, FontSize, FontType, Bold
         FROM tblGrp
         WHERE Denumire IS NOT NULL AND LTRIM(RTRIM(Denumire)) <> ''
         ORDER BY Poz, NrGrp"
    );
    return [array_map('apiV1Group', $rows), null];
}

/**
 * GET /api/v1/tables
 *
 * @param resource $conn
 * @return array
 */
function apiV1TablesGet($conn) {
    $meseConfig = [];
    foreach (apiV1FetchAll($conn, "SELECT MasaID, NrMasa, BackColor, ForeColor, Bold, Afisez, Obs FROM tblMese ORDER BY NrMasa") as $m) {
        $meseConfig[(int)$m['NrMasa']] = $m;
    }
    $activeBills = [];
    foreach (apiV1FetchAll(
        $conn,
        "SELECT b.NrMasa, b.DocID, b.TotalB, b.NrOp, o.Nume AS NumeCasier
         FROM tblBonCurent b LEFT JOIN tblOsp o ON b.NrOp = o.NrOsp
         WHERE b.Stare = 'D'"
    ) as $b) {
        $activeBills[(int)$b['NrMasa']] = $b;
    }

    $tables = [];
    for ($i = 1; $i <= 80; $i++) {
        $cfg = $meseConfig[$i] ?? null;
        $bill = $activeBills[$i] ?? null;
        $tables[] = [
            'nrMasa'     => $i,
            'isOccupied' => $bill !== null,
            'present'    => ($cfg !== null) || ($bill !== null),
            'casier'     => $bill ? (trim((string)$bill['NumeCasier']) ?: 'CASIER') : null,
            'nrOp'       => $bill ? (int)$bill['NrOp'] : null,
            'total'      => $bill ? round((float)$bill['TotalB'], 2) : 0,
            'docId'      => $bill ? (int)$bill['DocID'] : null,
            'backColor'  => ($cfg && $cfg['BackColor'] !== null) ? (int)$cfg['BackColor'] : null,
            'foreColor'  => ($cfg && $cfg['ForeColor'] !== null) ? (int)$cfg['ForeColor'] : null,
            'bold'       => $cfg ? apiV1Bool($cfg['Bold']) : false,
            'afisez'     => $cfg ? apiV1Bool($cfg['Afisez']) : true,
        ];
    }
    return [$tables, null];
}

/**
 * GET /api/v1/orders?stare=D|I&from=&to=&source=current|archive&limit&offset
 * GET /api/v1/orders/{docId}?source=current|archive
 *
 * @param resource $conn
 * @param string|null $id
 * @return array
 */
function apiV1OrdersGet($conn, $id) {
    $isArchive = (isset($_GET['source']) && strtolower(trim((string)$_GET['source'])) === 'archive');
    if ($id !== null && $id !== '' && ctype_digit((string)$id)) {
        return apiV1OrderOne($conn, (int)$id);
    }
    return apiV1OrdersList($conn, $isArchive);
}

/**
 * Lista bonurilor (sesiunea curenta sau arhiva dupa Z).
 *
 * @param resource $conn
 * @param bool $isArchive
 * @return array
 */
function apiV1OrdersList($conn, $isArchive) {
    $limit = apiV1Limit();
    $offset = apiV1Offset();
    $where = [];
    $params = [];

    if (!$isArchive) {
        if (isset($_GET['stare']) && $_GET['stare'] !== '') {
            $where[] = "b.Stare = ?";
            $params[] = trim((string)$_GET['stare']);
        }
        if (!empty($_GET['from'])) { $where[] = 'COALESCE(b.Ora, b.Data) >= ?'; $params[] = (string)$_GET['from']; }
        if (!empty($_GET['to']))   { $where[] = 'COALESCE(b.Ora, b.Data) < DATEADD(day, 1, ?)'; $params[] = (string)$_GET['to']; }

        $table = 'tblBonCurent';
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $select = "b.DocID, b.NrDoc, b.Data, b.Ora, b.NrMasa, b.NrOp, b.Stare,
                          b.TotalB, b.MagID, b.ClientID, b.CodFiscBon, b.NrZ,
                          COALESCE(o.Nume, '') AS Casier";
    } else {
        if (!empty($_GET['from'])) { $where[] = 'COALESCE(b.Ora, b.Data) >= ?'; $params[] = (string)$_GET['from']; }
        if (!empty($_GET['to']))   { $where[] = 'COALESCE(b.Ora, b.Data) < DATEADD(day, 1, ?)'; $params[] = (string)$_GET['to']; }
        $table = 'tblBon';
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $select = "b.DocID, b.NrDoc, b.Data, b.Ora, b.NrMasa, b.NrOp, b.Stare,
                          b.TotalB, b.MagID, b.ClientID, b.CodFiscBon, b.NrZ,
                          COALESCE(o.Nume, '') AS Casier";
    }

    $totalRow = apiV1FetchOne($conn, "SELECT COUNT(*) AS c FROM $table b $whereSql", $params);
    $total = $totalRow ? (int)$totalRow['c'] : 0;

    $p = $params;
    $p[] = $offset;
    $p[] = $limit;
    $rows = apiV1FetchAll(
        $conn,
        "SELECT $select FROM $table b LEFT JOIN tblOsp o ON b.NrOp = o.NrOsp
         $whereSql ORDER BY b.DocID DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
        $p
    );

    $out = [];
    foreach ($rows as $r) { $out[] = apiV1BillHeader($r, $isArchive); }
    return [$out, array_merge(apiV1PageMeta($limit, $offset, count($out)), ['total' => $total, 'source' => $isArchive ? 'archive' : 'current'])];
}

/**
 * Un singur bon: antet + linii + plati.
 *
 * @param resource $conn
 * @param int $docId
 * @return array
 */
function apiV1OrderOne($conn, $docId) {
    $isArchive = (isset($_GET['source']) && strtolower(trim((string)$_GET['source'])) === 'archive');
    $table = $isArchive ? 'tblBon' : 'tblBonCurent';
    $linesTable = $isArchive ? 'tempECR' : 'tblNoteD';

    $bon = apiV1FetchOne(
        $conn,
        "SELECT b.DocID, b.NrDoc, b.Data, b.Ora, b.NrMasa, b.NrOp, b.Stare,
                b.TotalB, b.MagID, b.ClientID, b.CodFiscBon, b.NrZ,
                COALESCE(o.Nume, '') AS Casier
         FROM $table b LEFT JOIN tblOsp o ON b.NrOp = o.NrOsp
         WHERE b.DocID = ?",
        [$docId]
    );
    if (!$bon) { apiError('Bon inexistent.', 404); }

    $header = apiV1BillHeader($bon, $isArchive);

    $orderLines = $isArchive ? 'd.ECRID' : 'd.OraComanda, d.ECRID';
    // tempECR (arhiva) are coloana Denumire, nu Descriere.
    $denumireExpr = $isArchive
        ? "COALESCE(p.Denumire, d.Denumire, '')"
        : "COALESCE(p.Denumire, d.Descriere, '')";
    $lines = [];
    foreach (apiV1FetchAll(
        $conn,
        "SELECT d.ECRID, d.ProdID, d.Cant, d.PV, d.PVC, d.TVac AS Tvac, d.[Comment],
                d.StornoRef, $denumireExpr AS Denumire
         FROM $linesTable d LEFT JOIN tblProd p ON d.ProdID = p.ProdID
         WHERE d.DocID = ? ORDER BY $orderLines",
        [$docId]
    ) as $r) {
        $cant = (float)$r['Cant'];
        $pv = (float)$r['PV'];
        $pvc = ($r['PVC'] !== null) ? (float)$r['PVC'] : $pv;
        $prodId = (int)$r['ProdID'];
        $lines[] = [
            'ecrId'      => (int)$r['ECRID'],
            'prodId'     => $prodId,
            'denumire'   => trim((string)$r['Denumire']),
            'cantitate'  => $cant,
            'pretUnitar' => $pv,
            'pvc'        => $pvc,
            'valoare'    => round($cant * $pv, 2),
            'valoareOriginala' => round($cant * $pvc, 2),
            'tva'        => (int)($r['Tvac'] ?? 0),
            'comment'    => trim((string)($r['Comment'] ?? '')),
            'stornoRef'  => ($r['StornoRef'] === null ? null : (int)$r['StornoRef']),
            'isModifier' => ($prodId === 0 && $cant <= 0.0),
            'isStorno'   => ($prodId > 0 && $cant < 0.0),
        ];
    }

    $payments = [];
    if ($isArchive) {
        foreach (apiV1FetchAll($conn, "SELECT FPID, FPDenumire, Suma FROM trelArhZDocIDFpID WHERE DocID = ?", [$docId]) as $p) {
            $payments[] = ['fpid' => (int)$p['FPID'], 'denumire' => trim((string)$p['FPDenumire']), 'suma' => round((float)$p['Suma'], 2)];
        }
    } else {
        foreach (apiV1FetchAll(
            $conn,
            "SELECT t.FPID, COALESCE(f.Denumire, '') AS Denumire, t.Suma
             FROM trelDocIDFpID t LEFT JOIN tblFP f ON f.FPID = t.FPID
             WHERE t.DocID = ? ORDER BY t.Id",
            [$docId]
        ) as $p) {
            $payments[] = ['fpid' => (int)$p['FPID'], 'denumire' => trim((string)$p['Denumire']), 'suma' => round((float)$p['Suma'], 2)];
        }
    }

    $header['lines'] = $lines;
    $header['payments'] = $payments;
    return [$header, ['source' => $isArchive ? 'archive' : 'current']];
}

/**
 * Antet normalizat de bon.
 *
 * @param array $r
 * @param bool $isArchive
 * @return array
 */
function apiV1BillHeader($r, $isArchive) {
    $ora = !empty($r['Ora']) ? $r['Ora'] : ($r['Data'] ?? null);
    return [
        'docId'      => (int)$r['DocID'],
        'nrDoc'      => (int)$r['NrDoc'],
        'nrZ'        => ($r['NrZ'] === null ? null : (int)$r['NrZ']),
        'data'       => ($r['Data'] === null ? null : (string)$r['Data']),
        'ora'        => ($ora === null ? null : (string)$ora),
        'nrMasa'     => ($r['NrMasa'] === null ? null : (int)$r['NrMasa']),
        'nrOp'       => ($r['NrOp'] === null ? null : (int)$r['NrOp']),
        'casier'     => trim((string)($r['Casier'] ?? '')),
        'stare'      => trim((string)($r['Stare'] ?? '')),
        'total'      => round((float)($r['TotalB'] ?? 0), 2),
        'magId'      => ($r['MagID'] === null ? null : (int)$r['MagID']),
        'clientId'   => ($r['ClientID'] === null ? null : trim((string)$r['ClientID'])),
        'codFiscBon' => ($r['CodFiscBon'] === null ? null : trim((string)$r['CodFiscBon'])),
        'source'     => $isArchive ? 'archive' : 'current',
    ];
}

/**
 * GET /api/v1/reports/{plu|grupe|sectii|casieri|general}
 *
 * @param resource $conn
 * @param string|null $tip
 * @return array
 */
function apiV1ReportsGet($conn, $tip) {
    $tip = strtolower(trim((string)$tip));
    switch ($tip) {
        case 'plu':      return [apiV1ReportPlu($conn), null];
        case 'grupe':    return [apiV1ReportGrupe($conn), null];
        case 'sectii':   return [apiV1ReportSectii($conn), null];
        case 'casieri':  return [apiV1ReportCasieri($conn), null];
        case 'general':  return [apiV1ReportGeneral($conn), null];
        default:
            apiError('Tip de raport necunoscut. Folositi: plu, grupe, sectii, casieri, general.', 404);
    }
}

/**
 * @param resource $conn
 * @return array
 */
function apiV1ReportPlu($conn) {
    $rows = apiV1FetchAll(
        $conn,
        "SELECT s.Denumire AS Sectiune, p.Sectie AS SectieId, d.ProdID AS ProdID, p.Denumire AS Produs,
                ROUND(SUM(d.Cant), 2) AS Cant, ROUND(SUM(d.Cant * d.PV), 2) AS Valoare
         FROM tblNoteD d
         INNER JOIN tblBonCurent b ON d.DocID = b.DocID
         INNER JOIN tblProd p ON d.ProdID = p.ProdID
         INNER JOIN tblSectii s ON p.Sectie = s.Sectie
         WHERE b.Stare = 'I'
         GROUP BY p.Sectie, s.Denumire, d.ProdID, p.Denumire
         ORDER BY s.Denumire, p.Denumire"
    );
    $sections = [];
    $tc = 0.0; $tv = 0.0;
    foreach ($rows as $r) {
        $k = (int)$r['SectieId'];
        if (!isset($sections[$k])) {
            $sections[$k] = ['sectie' => trim((string)$r['Sectiune']), 'rows' => [], 'subtotalCant' => 0.0, 'subtotalValoare' => 0.0];
        }
        $c = (float)$r['Cant']; $v = (float)$r['Valoare'];
        $sections[$k]['rows'][] = ['prodId' => (int)$r['ProdID'], 'produs' => trim((string)$r['Produs']), 'cant' => round($c, 2), 'valoare' => round($v, 2)];
        $sections[$k]['subtotalCant'] += $c;
        $sections[$k]['subtotalValoare'] += $v;
        $tc += $c; $tv += $v;
    }
    foreach ($sections as $k => $s) {
        $sections[$k]['subtotalCant'] = round($s['subtotalCant'], 2);
        $sections[$k]['subtotalValoare'] = round($s['subtotalValoare'], 2);
    }
    return ['sectii' => array_values($sections), 'totalCant' => round($tc, 2), 'totalValoare' => round($tv, 2)];
}

/**
 * @param resource $conn
 * @return array
 */
function apiV1ReportGrupe($conn) {
    $rows = apiV1FetchAll(
        $conn,
        "SELECT g.NrGrp AS NrGrp, g.Denumire AS Denumire, ROUND(SUM(d.Cant * d.PV), 2) AS Valoare
         FROM tblNoteD d
         INNER JOIN tblBonCurent b ON d.DocID = b.DocID
         INNER JOIN tblProd p ON d.ProdID = p.ProdID
         INNER JOIN tblGrp g ON p.NrGrp = g.NrGrp
         WHERE b.Stare = 'I'
         GROUP BY g.NrGrp, g.Denumire
         ORDER BY g.Denumire"
    );
    $grupe = [];
    $tv = 0.0;
    foreach ($rows as $r) {
        $v = round((float)$r['Valoare'], 2);
        $grupe[] = ['nrGrp' => (int)$r['NrGrp'], 'denumire' => trim((string)$r['Denumire']), 'valoare' => $v];
        $tv += $v;
    }
    return ['grupe' => $grupe, 'totalValoare' => round($tv, 2)];
}

/**
 * @param resource $conn
 * @return array
 */
function apiV1ReportSectii($conn) {
    $rows = apiV1FetchAll(
        $conn,
        "SELECT s.Sectie AS Sectie, s.Denumire AS Denumire, ROUND(SUM(d.Cant * d.PV), 2) AS Valoare
         FROM tblNoteD d
         INNER JOIN tblBonCurent b ON d.DocID = b.DocID
         INNER JOIN tblProd p ON d.ProdID = p.ProdID
         INNER JOIN tblSectii s ON p.Sectie = s.Sectie
         WHERE b.Stare = 'I'
         GROUP BY s.Sectie, s.Denumire
         ORDER BY s.Denumire"
    );
    $sectii = [];
    $tv = 0.0;
    foreach ($rows as $r) {
        $v = round((float)$r['Valoare'], 2);
        $sectii[] = ['sectie' => (int)$r['Sectie'], 'denumire' => trim((string)$r['Denumire']), 'valoare' => $v];
        $tv += $v;
    }
    return ['sectii' => $sectii, 'totalValoare' => round($tv, 2)];
}

/**
 * @param resource $conn
 * @return array
 */
function apiV1ReportCasieri($conn) {
    $rows = apiV1FetchAll(
        $conn,
        "SELECT b.NrOp AS NrOp, COALESCE(o.Nume, '') AS Nume,
                COUNT(DISTINCT b.DocID) AS NrBonuri,
                ROUND(SUM(CASE WHEN d.Cant > 0 THEN d.Cant * (COALESCE(d.PVC, d.PV) - d.PV) ELSE 0 END), 2) AS Reducere,
                ROUND(SUM(CASE WHEN d.Cant < 0 THEN -d.Cant * d.PV ELSE 0 END), 2) AS Stornari,
                ROUND(SUM(d.Cant * d.PV), 2) AS Total
         FROM tblNoteD d
         INNER JOIN tblBonCurent b ON d.DocID = b.DocID
         LEFT JOIN tblOsp o ON b.NrOp = o.NrOsp
         WHERE b.Stare = 'I'
         GROUP BY b.NrOp, o.Nume
         ORDER BY o.Nume"
    );
    $casieri = [];
    $tBonuri = 0; $tRed = 0.0; $tStorn = 0.0; $tVal = 0.0;
    foreach ($rows as $r) {
        $casieri[] = [
            'nrOp'     => ($r['NrOp'] === null ? null : (int)$r['NrOp']),
            'nume'     => trim((string)$r['Nume']),
            'nrBonuri' => (int)$r['NrBonuri'],
            'reducere' => round((float)$r['Reducere'], 2),
            'stornari' => round((float)$r['Stornari'], 2),
            'total'    => round((float)$r['Total'], 2),
        ];
        $tBonuri += (int)$r['NrBonuri'];
        $tRed += (float)$r['Reducere'];
        $tStorn += (float)$r['Stornari'];
        $tVal += (float)$r['Total'];
    }
    return [
        'casieri' => $casieri,
        'totalBonuri' => $tBonuri,
        'totalReducere' => round($tRed, 2),
        'totalStornari' => round($tStorn, 2),
        'totalValoare' => round($tVal, 2),
    ];
}

/**
 * @param resource $conn
 * @return array
 */
function apiV1ReportGeneral($conn) {
    $tot = apiV1FetchOne(
        $conn,
        "SELECT COUNT(DISTINCT b.DocID) AS TotalBonuri,
                ROUND(SUM(CASE WHEN d.Cant > 0 THEN d.Cant * (COALESCE(d.PVC, d.PV) - d.PV) ELSE 0 END), 2) AS TotalDiscount,
                ROUND(SUM(CASE WHEN d.Cant < 0 THEN -d.Cant * d.PV ELSE 0 END), 2) AS TotalStornari,
                ROUND(SUM(d.Cant * d.PV), 2) AS TotalValoare
         FROM tblNoteD d INNER JOIN tblBonCurent b ON d.DocID = b.DocID
         WHERE b.Stare = 'I'"
    );

    $plati = [];
    foreach (apiV1FetchAll(
        $conn,
        "SELECT f.FPID, f.Denumire, ROUND(SUM(t.Suma), 2) AS Suma
         FROM trelDocIDFpID t
         INNER JOIN tblBonCurent b ON t.DocID = b.DocID
         LEFT JOIN tblFP f ON f.FPID = t.FPID
         WHERE b.Stare = 'I'
         GROUP BY f.FPID, f.Denumire ORDER BY f.FPID"
    ) as $r) {
        $plati[] = ['fpid' => (int)$r['FPID'], 'denumire' => trim((string)$r['Denumire']), 'suma' => round((float)$r['Suma'], 2)];
    }

    $tva = [];
    foreach (apiV1FetchAll(
        $conn,
        "SELECT d.TVac AS Cota,
                ROUND(SUM(d.Cant * d.PV), 2) AS Total,
                ROUND(SUM(d.Cant * d.PV) / (1 + d.TVac / 100.0), 2) AS Baza
         FROM tblNoteD d INNER JOIN tblBonCurent b ON d.DocID = b.DocID
         WHERE b.Stare = 'I' AND d.ProdID > 0
         GROUP BY d.TVac ORDER BY d.TVac"
    ) as $r) {
        $total = (float)$r['Total'];
        $baza = (float)$r['Baza'];
        $tva[] = ['cota' => (int)$r['Cota'], 'total' => round($total, 2), 'baza' => round($baza, 2), 'suma' => round($total - $baza, 2)];
    }

    $note = [];
    foreach (apiV1FetchAll(
        $conn,
        "SELECT b.NrDoc, b.NrMasa, COALESCE(o.Nume, '') AS Ospatar, ROUND(SUM(d.Cant * d.PV), 2) AS Total
         FROM tblBonCurent b INNER JOIN tblNoteD d ON d.DocID = b.DocID
         LEFT JOIN tblOsp o ON b.NrOp = o.NrOsp
         WHERE b.Stare = 'I'
         GROUP BY b.NrDoc, b.NrMasa, o.Nume, b.DocID ORDER BY b.NrDoc"
    ) as $r) {
        $note[] = ['nrNota' => (int)$r['NrDoc'], 'nrMasa' => (int)$r['NrMasa'], 'ospatar' => trim((string)$r['Ospatar']), 'total' => round((float)$r['Total'], 2)];
    }

    return [
        'totalBonuri' => $tot ? (int)$tot['TotalBonuri'] : 0,
        'totalDiscount' => $tot ? round((float)$tot['TotalDiscount'], 2) : 0,
        'totalStornari' => $tot ? round((float)$tot['TotalStornari'], 2) : 0,
        'totalValoare' => $tot ? round((float)$tot['TotalValoare'], 2) : 0,
        'plati' => $plati,
        'tva' => $tva,
        'note' => $note,
    ];
}

/**
 * GET /api/v1/events?since=<DocID>&limit=&source=current|archive|all
 * Flux incremental de bonuri inchise (pentru sincronizare prin polling).
 * `since` = ultimul DocID procesat; intoarce bonurile cu DocID > since.
 *
 * @param resource $conn
 * @return array
 */
function apiV1EventsGet($conn) {
    $since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
    $limit = min(apiV1Limit(), 500);
    $rows = [];

    // Sesiunea curenta (doar bonuri inchise).
    foreach (apiV1FetchAll(
        $conn,
        "SELECT b.DocID, b.NrDoc, b.Data, b.Ora, b.NrMasa, b.NrOp, b.Stare, b.TotalB,
                b.MagID, b.ClientID, b.CodFiscBon, b.NrZ, COALESCE(o.Nume, '') AS Casier
         FROM tblBonCurent b LEFT JOIN tblOsp o ON b.NrOp = o.NrOsp
         WHERE b.Stare = 'I' AND b.DocID > ?
         ORDER BY b.DocID",
        [$since]
    ) as $r) {
        $rows[] = apiV1BillHeader($r, false);
    }

    // Arhiva (dupa Z). DocID e pastrat din tblBonCurent, deci cursorul e comun.
    foreach (apiV1FetchAll(
        $conn,
        "SELECT b.DocID, b.NrDoc, b.Data, b.Ora, b.NrMasa, b.NrOp, b.Stare, b.TotalB,
                b.MagID, b.ClientID, b.CodFiscBon, b.NrZ, COALESCE(o.Nume, '') AS Casier
         FROM tblBon b LEFT JOIN tblOsp o ON b.NrOp = o.NrOsp
         WHERE b.DocID > ?
         ORDER BY b.DocID",
        [$since]
    ) as $r) {
        $rows[] = apiV1BillHeader($r, true);
    }

    usort($rows, function ($a, $b) { return $a['docId'] <=> $b['docId']; });
    $hasMore = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);

    $nextSince = $since;
    foreach ($rows as $r) { if ($r['docId'] > $nextSince) { $nextSince = $r['docId']; } }

    return [$rows, [
        'since'     => $since,
        'nextSince' => $nextSince,
        'count'     => count($rows),
        'hasMore'   => $hasMore,
    ]];
}
