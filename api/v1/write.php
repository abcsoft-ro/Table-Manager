<?php
/**
 * write.php
 * Handlerele POST ale API-ului v1 (import / scriere date).
 *
 * Fiecare handler intoarce [data, meta]. Accepta fie un singur obiect, fie
 * {items: [...]}, fie un array de obiecte. Scrierea se face in tranzactie.
 */

/**
 * Lista de elemente din corpul cererii.
 *
 * @param array $body
 * @return array
 */
function apiV1Items($body) {
    if (isset($body['items']) && is_array($body['items'])) {
        return array_values($body['items']);
    }
    if ($body === []) { return []; }
    if (array_keys($body) === range(0, count($body) - 1)) {
        return array_values($body);
    }
    return [$body];
}

/**
 * Verifica prezenta unor campuri obligatorii.
 *
 * @param array $item
 * @param array $fields
 * @param int $idx
 * @return void
 */
function apiV1RequireFields($item, $fields, $idx) {
    foreach ($fields as $f) {
        if (!array_key_exists($f, $item) || $item[$f] === null || (is_string($item[$f]) && trim($item[$f]) === '')) {
            apiError("Camp obligatoriu lipsa: $f (elementul $idx).", 400);
        }
    }
}

/**
 * Int nullable.
 *
 * @param mixed $v
 * @return int|null
 */
function apiV1NullInt($v) {
    return ($v === null || $v === '') ? null : (int)$v;
}

/**
 * Import se refuza cand meniul este administrat de server (tblSet.Server = 1).
 *
 * @param resource $conn
 * @param string $resursa
 * @return void
 */
function apiV1RefuseWhenServer($conn, $resursa) {
    if (apiSetting($conn, 'Server', '0') === '1') {
        apiError("Importul de $resursa nu este permis: meniul este administrat de server (tblSet.Server = 1).", 409);
    }
}

/**
 * POST /api/v1/groups  { items:[{nrGrp, denumire, poz, ...}] }
 *
 * @param resource $conn
 * @return array
 */
function apiV1GroupsPost($conn) {
    apiV1RefuseWhenServer($conn, 'grupe');
    $items = apiV1Items(apiV1Body());
    if (!$items) { apiError('Corp gol: trimiteti cel putin o grupa.', 400); }

    $saved = 0;
    sqlsrv_begin_transaction($conn);
    foreach ($items as $i => $it) {
        if (!is_array($it)) { apiError("Element invalid la pozitia $i.", 400); }
        apiV1RequireFields($it, ['nrGrp', 'denumire'], $i);
        $nrGrp = (int)$it['nrGrp'];

        $ok = sqlsrv_query(
            $conn,
            "IF EXISTS (SELECT 1 FROM tblGrp WHERE NrGrp = ?)
                UPDATE tblGrp SET Denumire=?, Poz=?, BackColor=?, FontColor=?, FontSize=?, FontType=?, Bold=? WHERE NrGrp=?
             ELSE
                INSERT INTO tblGrp (NrGrp, Denumire, Poz, BackColor, FontColor, FontSize, FontType, Bold, Tint)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)",
            [
                $nrGrp,
                (string)$it['denumire'], apiV1NullInt($it['poz'] ?? null),
                apiV1NullInt($it['backColor'] ?? null), apiV1NullInt($it['fontColor'] ?? null),
                apiV1NullInt($it['fontSize'] ?? null), (string)($it['fontType'] ?? ''),
                (!empty($it['bold']) ? 1 : 0),
                $nrGrp,
                $nrGrp,
                (string)$it['denumire'], apiV1NullInt($it['poz'] ?? null),
                apiV1NullInt($it['backColor'] ?? null), apiV1NullInt($it['fontColor'] ?? null),
                apiV1NullInt($it['fontSize'] ?? null), (string)($it['fontType'] ?? ''),
                (!empty($it['bold']) ? 1 : 0),
            ]
        );
        if ($ok === false) {
            sqlsrv_rollback($conn);
            apiError('Eroare salvare grupa: ' . apiV1SqlError(), 500);
        }
        $saved++;
    }
    sqlsrv_commit($conn);
    return [['saved' => $saved], null];
}

/**
 * POST /api/v1/products  { items:[{prodId, nrGrp, denumire, ...}] }
 *
 * @param resource $conn
 * @return array
 */
function apiV1ProductsPost($conn) {
    apiV1RefuseWhenServer($conn, 'produse');
    $items = apiV1Items(apiV1Body());
    if (!$items) { apiError('Corp gol: trimiteti cel putin un produs.', 400); }

    $saved = 0;
    sqlsrv_begin_transaction($conn);
    foreach ($items as $i => $it) {
        if (!is_array($it)) { apiError("Element invalid la pozitia $i.", 400); }
        apiV1RequireFields($it, ['prodId', 'nrGrp', 'denumire'], $i);
        $prodId = (int)$it['prodId'];

        $ok = sqlsrv_query(
            $conn,
            "IF EXISTS (SELECT 1 FROM tblProd WHERE ProdID = ?)
                UPDATE tblProd SET BarCod=?, Denumire=?, UM=?, NrGrp=?, KP=?, Sectie=?, PV=?, Nr_TVA=?,
                       MZ=?, MagID=?, Poz=?, DataUM=GETDATE(), BackColor=?, FontColor=?, FontSize=?, FontType=?, Bold=?
                WHERE ProdID=?
             ELSE
                INSERT INTO tblProd (ProdID, BarCod, Denumire, UM, NrGrp, KP, Sectie, PV, Nr_TVA,
                       MZ, MagID, Poz, DataUM, BackColor, FontColor, FontSize, FontType, Bold)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), ?, ?, ?, ?, ?)",
            [
                $prodId,
                (string)($it['barCod'] ?? ''), (string)$it['denumire'], (string)($it['um'] ?? 'buc'),
                (int)$it['nrGrp'], apiV1NullInt($it['kp'] ?? null), apiV1NullInt($it['sectie'] ?? null),
                (float)($it['pv'] ?? 0), apiV1NullInt($it['nrTva'] ?? null),
                (int)($it['mz'] ?? 0), apiV1NullInt($it['magId'] ?? null), apiV1NullInt($it['poz'] ?? null),
                apiV1NullInt($it['backColor'] ?? null), apiV1NullInt($it['fontColor'] ?? null),
                apiV1NullInt($it['fontSize'] ?? null), (string)($it['fontType'] ?? ''), (!empty($it['bold']) ? 1 : 0),
                $prodId,
                $prodId,
                (string)($it['barCod'] ?? ''), (string)$it['denumire'], (string)($it['um'] ?? 'buc'),
                (int)$it['nrGrp'], apiV1NullInt($it['kp'] ?? null), apiV1NullInt($it['sectie'] ?? null),
                (float)($it['pv'] ?? 0), apiV1NullInt($it['nrTva'] ?? null),
                (int)($it['mz'] ?? 0), apiV1NullInt($it['magId'] ?? null), apiV1NullInt($it['poz'] ?? null),
                apiV1NullInt($it['backColor'] ?? null), apiV1NullInt($it['fontColor'] ?? null),
                apiV1NullInt($it['fontSize'] ?? null), (string)($it['fontType'] ?? ''), (!empty($it['bold']) ? 1 : 0),
            ]
        );
        if ($ok === false) {
            sqlsrv_rollback($conn);
            apiError('Eroare salvare produs: ' . apiV1SqlError(), 500);
        }
        $saved++;
    }
    sqlsrv_commit($conn);
    return [['saved' => $saved], null];
}

/**
 * POST /api/v1/waiters  { items:[{nrOsp, nume, parola, ...}] }
 *
 * @param resource $conn
 * @return array
 */
function apiV1WaitersPost($conn) {
    $items = apiV1Items(apiV1Body());
    if (!$items) { apiError('Corp gol: trimiteti cel putin un ospatar.', 400); }

    $saved = 0;
    sqlsrv_begin_transaction($conn);
    foreach ($items as $i => $it) {
        if (!is_array($it)) { apiError("Element invalid la pozitia $i.", 400); }
        apiV1RequireFields($it, ['nrOsp'], $i);
        $nrOsp = (string)$it['nrOsp'];

        $ok = sqlsrv_query(
            $conn,
            "IF EXISTS (SELECT 1 FROM tblOsp WHERE NrOsp = ?)
                UPDATE tblOsp SET Nume=?, Expl=?, Parola=?, Blocat=? WHERE NrOsp=?
             ELSE
                INSERT INTO tblOsp (NrOsp, Nume, Expl, Parola, Blocat) VALUES (?, ?, ?, ?, ?)",
            [
                $nrOsp,
                (string)($it['nume'] ?? ''), (string)($it['expl'] ?? ''), (string)($it['parola'] ?? ''),
                (!empty($it['blocat']) ? 1 : 0),
                $nrOsp,
                $nrOsp,
                (string)($it['nume'] ?? ''), (string)($it['expl'] ?? ''), (string)($it['parola'] ?? ''),
                (!empty($it['blocat']) ? 1 : 0),
            ]
        );
        if ($ok === false) {
            sqlsrv_rollback($conn);
            apiError('Eroare salvare ospatar: ' . apiV1SqlError(), 500);
        }
        $saved++;
    }
    sqlsrv_commit($conn);
    return [['saved' => $saved], null];
}

/**
 * POST /api/v1/tables  { items:[{nrMasa, backColor, foreColor, bold, afisez, obs}] }
 *
 * @param resource $conn
 * @return array
 */
function apiV1TablesPost($conn) {
    $items = apiV1Items(apiV1Body());
    if (!$items) { apiError('Corp gol: trimiteti cel putin o masa.', 400); }

    $saved = 0;
    sqlsrv_begin_transaction($conn);
    foreach ($items as $i => $it) {
        if (!is_array($it)) { apiError("Element invalid la pozitia $i.", 400); }
        apiV1RequireFields($it, ['nrMasa'], $i);
        $nrMasa = (int)$it['nrMasa'];
        if ($nrMasa < 1 || $nrMasa > 80) { apiError("nrMasa trebuie sa fie intre 1 si 80 (elementul $i).", 400); }

        $ok = sqlsrv_query(
            $conn,
            "IF EXISTS (SELECT 1 FROM tblMese WHERE NrMasa = ?)
                UPDATE tblMese SET BackColor=?, ForeColor=?, Bold=?, Afisez=?, Obs=? WHERE NrMasa=?
             ELSE
                INSERT INTO tblMese (NrMasa, BackColor, ForeColor, Bold, Afisez, Obs) VALUES (?, ?, ?, ?, ?, ?)",
            [
                $nrMasa,
                apiV1NullInt($it['backColor'] ?? null), apiV1NullInt($it['foreColor'] ?? null),
                (array_key_exists('bold', $it) ? (!empty($it['bold']) ? 1 : 0) : 1),
                (array_key_exists('afisez', $it) ? (!empty($it['afisez']) ? 1 : 0) : 1),
                (string)($it['obs'] ?? ''),
                $nrMasa,
                $nrMasa,
                apiV1NullInt($it['backColor'] ?? null), apiV1NullInt($it['foreColor'] ?? null),
                (array_key_exists('bold', $it) ? (!empty($it['bold']) ? 1 : 0) : 1),
                (array_key_exists('afisez', $it) ? (!empty($it['afisez']) ? 1 : 0) : 1),
                (string)($it['obs'] ?? ''),
            ]
        );
        if ($ok === false) {
            sqlsrv_rollback($conn);
            apiError('Eroare salvare masa: ' . apiV1SqlError(), 500);
        }
        $saved++;
    }
    sqlsrv_commit($conn);
    return [['saved' => $saved], null];
}

/**
 * POST /api/v1/payment-forms  { items:[{fpid, denumire, status, poz}] }
 *
 * @param resource $conn
 * @return array
 */
function apiV1PaymentFormsPost($conn) {
    $items = apiV1Items(apiV1Body());
    if (!$items) { apiError('Corp gol: trimiteti cel putin o forma de plata.', 400); }

    $saved = 0;
    sqlsrv_begin_transaction($conn);
    foreach ($items as $i => $it) {
        if (!is_array($it)) { apiError("Element invalid la pozitia $i.", 400); }
        apiV1RequireFields($it, ['fpid'], $i);
        $fpid = (int)$it['fpid'];

        $ok = sqlsrv_query(
            $conn,
            "IF EXISTS (SELECT 1 FROM tblFP WHERE FPID = ?)
                UPDATE tblFP SET Denumire=?, Status=?, Poz=? WHERE FPID=?
             ELSE
                INSERT INTO tblFP (FPID, Denumire, Status, Poz) VALUES (?, ?, ?, ?)",
            [
                $fpid,
                (string)($it['denumire'] ?? ''), (int)($it['status'] ?? 1), apiV1NullInt($it['poz'] ?? null),
                $fpid,
                $fpid,
                (string)($it['denumire'] ?? ''), (int)($it['status'] ?? 1), apiV1NullInt($it['poz'] ?? null),
            ]
        );
        if ($ok === false) {
            sqlsrv_rollback($conn);
            apiError('Eroare salvare forma de plata: ' . apiV1SqlError(), 500);
        }
        $saved++;
    }
    sqlsrv_commit($conn);
    return [['saved' => $saved], null];
}

/**
 * POST /api/v1/vat  { items:[{nrTva, cota}] }
 *
 * @param resource $conn
 * @return array
 */
function apiV1VatPost($conn) {
    $items = apiV1Items(apiV1Body());
    if (!$items) { apiError('Corp gol: trimiteti cel putin o cota.', 400); }

    $saved = 0;
    sqlsrv_begin_transaction($conn);
    foreach ($items as $i => $it) {
        if (!is_array($it)) { apiError("Element invalid la pozitia $i.", 400); }
        apiV1RequireFields($it, ['nrTva', 'cota'], $i);
        $nrTva = (int)$it['nrTva'];

        $ok = sqlsrv_query(
            $conn,
            "IF EXISTS (SELECT 1 FROM tblTVA WHERE Nr_TVA = ?)
                UPDATE tblTVA SET Cota=? WHERE Nr_TVA=?
             ELSE
                INSERT INTO tblTVA (Nr_TVA, Cota) VALUES (?, ?)",
            [$nrTva, (int)$it['cota'], $nrTva, $nrTva, (int)$it['cota']]
        );
        if ($ok === false) {
            sqlsrv_rollback($conn);
            apiError('Eroare salvare cota TVA: ' . apiV1SqlError(), 500);
        }
        $saved++;
    }
    sqlsrv_commit($conn);
    return [['saved' => $saved], null];
}
