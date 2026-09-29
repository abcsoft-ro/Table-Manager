<?php
/**
 * API: Programare produse (tblProd)
 * GET  -> produse (+ listele sectii din tblSectii si tva din tblTVA pentru comboboxuri)
 * POST -> actiuni: insert / update / delete
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/colors.php';

function tblProdIntOrNull($v) {
    $t = trim((string)$v);
    if ($t === '') return null;
    return (int)$t;
}

function tblProdFloatOrNull($v) {
    $t = trim((string)$v);
    if ($t === '') return null;
    return (float)$t;
}

$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $nrgrp = isset($_GET['nrgrp']) ? (int)$_GET['nrgrp'] : 0;

    $products = [];
    $where = $nrgrp > 0 ? "WHERE p.NrGrp = " . (int)$nrgrp : "";
    $sql = "SELECT p.ProdID, p.BarCod, p.Denumire, p.UM, p.IRP, p.IIVCN, p.DataUM,
                   p.NrGrp, p.KP, p.BackColor, p.FontColor, p.FontSize, p.Bold, p.Poz,
                   p.FontType, p.Imagine, p.Sectie, p.PV, p.Nr_TVA, p.MZ
            FROM tblProd p
            $where
            ORDER BY p.NrGrp, p.Poz, p.ProdID";
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt) {
        while ($p = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $bg = tblProdIntOrNull($p['BackColor'] ?? '');
            $fg = tblProdIntOrNull($p['FontColor'] ?? '');
            $products[] = [
                "ProdID" => (int)$p['ProdID'],
                "BarCod" => trim($p['BarCod'] ?? ''),
                "Denumire" => trim($p['Denumire'] ?? ''),
                "UM" => trim($p['UM'] ?? ''),
                "IRP" => tblProdIntOrNull($p['IRP'] ?? ''),
                "IIVCN" => tblProdIntOrNull($p['IIVCN'] ?? ''),
                "DataUM" => $p['DataUM'] === null ? null : $p['DataUM'],
                "NrGrp" => (int)$p['NrGrp'],
                "KP" => tblProdIntOrNull($p['KP'] ?? ''),
                "BackColor" => $bg,
                "BackHex" => winColorToHex($bg),
                "FontColor" => $fg,
                "FontHex" => winColorToHex($fg),
                "FontSize" => tblProdIntOrNull($p['FontSize'] ?? ''),
                "Bold" => (bool)($p['Bold'] == -1 || $p['Bold'] == 1),
                "Poz" => tblProdIntOrNull($p['Poz'] ?? ''),
                "FontType" => trim($p['FontType'] ?? ''),
                "Imagine" => trim($p['Imagine'] ?? ''),
                "Sectie" => tblProdIntOrNull($p['Sectie'] ?? ''),
                "PV" => tblProdFloatOrNull($p['PV'] ?? ''),
                "Nr_TVA" => tblProdIntOrNull($p['Nr_TVA'] ?? ''),
                "MZ" => (int)($p['MZ'] ?? 0)
            ];
        }
    }

    // Lista sectiilor pentru combobox (tblSectii: Sectie, Denumire)
    $sectii = [];
    $stmtS = sqlsrv_query($conn, "SELECT Sectie, Denumire FROM tblSectii ORDER BY Sectie");
    if ($stmtS) {
        while ($s = sqlsrv_fetch_array($stmtS, SQLSRV_FETCH_ASSOC)) {
            $sectii[] = [
                "Sectie" => (int)$s['Sectie'],
                "Denumire" => trim($s['Denumire'] ?? '')
            ];
        }
    }

    // Lista cotelor de TVA pentru combobox (tblTVA: Nr_TVA, Cota)
    $tvaList = [];
    $stmtT = sqlsrv_query($conn, "SELECT Nr_TVA, Cota FROM tblTVA ORDER BY Nr_TVA");
    if ($stmtT) {
        while ($t = sqlsrv_fetch_array($stmtT, SQLSRV_FETCH_ASSOC)) {
            $tvaList[] = [
                "Nr_TVA" => (int)$t['Nr_TVA'],
                "Cota" => (float)$t['Cota']
            ];
        }
    }

    // Lista sectiilor de tiparire pentru combobox (tblKP: NrLogic, Nume, Stare)
    $kpList = [];
    $stmtK = sqlsrv_query($conn, "SELECT NrLogic, Nume, Stare FROM tblKP ORDER BY Nume");
    if ($stmtK) {
        while ($k = sqlsrv_fetch_array($stmtK, SQLSRV_FETCH_ASSOC)) {
            $kpList[] = [
                "NrLogic" => (int)$k['NrLogic'],
                "Nume" => trim($k['Nume'] ?? ''),
                "Stare" => (bool)$k['Stare']
            ];
        }
    }

    sendJsonResponse(["status" => "success", "products" => $products, "sectii" => $sectii, "tva" => $tvaList, "kpList" => $kpList]);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input || !is_array($input)) {
    $input = $_POST;
}

$action = $input['action'] ?? '';
$prodId = (int)($input['ProdID'] ?? 0);

function tblProdValidate(&$input) {
    $prodId = (int)($input['ProdID'] ?? 0);
    $barcod = trim($input['BarCod'] ?? '');
    $denumire = trim($input['Denumire'] ?? '');
    $um = trim($input['UM'] ?? '');
    $nrGrp = (int)($input['NrGrp'] ?? 0);

    if ($prodId <= 0) {
        sendJsonResponse(["status" => "error", "message" => "ProdID invalid"], 400);
    }
    if ($barcod === '' || mb_strlen($barcod) > 15) {
        sendJsonResponse(["status" => "error", "message" => "BarCod obligatoriu, maxim 15 caractere"], 400);
    }
    if ($um === '' || mb_strlen($um) > 3) {
        sendJsonResponse(["status" => "error", "message" => "UM obligatorie, maxim 3 caractere"], 400);
    }
    if ($denumire === '' || mb_strlen($denumire) > 32) {
        sendJsonResponse(["status" => "error", "message" => "Denumire obligatorie, maxim 32 caractere"], 400);
    }
    if ($nrGrp <= 0) {
        sendJsonResponse(["status" => "error", "message" => "NrGrp invalid"], 400);
    }
    $poz = tblProdIntOrNull($input['Poz'] ?? '');
    if ($poz !== null && ($poz < 1 || $poz > 50)) {
        sendJsonResponse(["status" => "error", "message" => "Poz trebuie sa fie intre 1 si 50"], 400);
    }
    return [$prodId, $barcod, $denumire, $um, $nrGrp];
}

switch ($action) {
    case 'insert':
    case 'update':
        list($prodId, $barcod, $denumire, $um, $nrGrp) = tblProdValidate($input);
        $dataUM = trim($input['DataUM'] ?? ''); // ignorat: DataUM se seteaza mereu cu GETDATE()
        $irp = tblProdIntOrNull($input['IRP'] ?? '');
        $iivcn = tblProdIntOrNull($input['IIVCN'] ?? '');
        $kp = tblProdIntOrNull($input['KP'] ?? '');
        $bg = tblProdIntOrNull($input['BackColor'] ?? '');
        $fg = tblProdIntOrNull($input['FontColor'] ?? '');
        $fontSize = tblProdIntOrNull($input['FontSize'] ?? '');
        $bold = !empty($input['Bold']) ? 1 : 0;
        $poz = tblProdIntOrNull($input['Poz'] ?? '');
        $fontType = trim($input['FontType'] ?? '');
        $imagine = trim($input['Imagine'] ?? '');
        $sectie = tblProdIntOrNull($input['Sectie'] ?? '');
        $pv = tblProdFloatOrNull($input['PV'] ?? '');
        $nrTva = tblProdIntOrNull($input['Nr_TVA'] ?? '');
        $mz = !empty($input['MZ']) ? 1 : 0;

        if ($action === 'insert') {
            // DataUM e intotdeauna data curenta (GETDATE()). Coloana TVA (vechea) a fost eliminata din schema.
            $stmt = sqlsrv_query(
                $conn,
                "INSERT INTO tblProd
                   (ProdID, BarCod, Denumire, UM, IRP, IIVCN, DataUM, NrGrp, KP,
                    BackColor, FontColor, FontSize, Bold, Poz, FontType, Imagine, Sectie, PV, Nr_TVA, MZ)
                 VALUES (?, ?, ?, ?, ?, ?, GETDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$prodId, $barcod, ($denumire === '' ? null : $denumire), $um,
                 $irp, $iivcn, $nrGrp, $kp,
                 $bg, $fg, $fontSize, $bold, $poz, ($fontType === '' ? null : $fontType),
                 ($imagine === '' ? null : $imagine), $sectie, $pv, $nrTva, $mz]
            );
            if (!$stmt) {
                sendJsonResponse(["status" => "error", "message" => "Eroare inserare (posibil ProdID/BarCod duplicat): " . sqlsrv_errors()[0]['message']], 500);
            }
            sendJsonResponse(["status" => "success", "message" => "Produs adaugat"]);
        } else {
            // Actualizam toate coloanele editabile; DataUM = data curenta.
            $stmt = sqlsrv_query(
                $conn,
                "UPDATE tblProd SET BarCod = ?, Denumire = ?, UM = ?, IRP = ?, IIVCN = ?, DataUM = GETDATE(),
                        NrGrp = ?, KP = ?, BackColor = ?, FontColor = ?, FontSize = ?, Bold = ?,
                        Poz = ?, FontType = ?, Imagine = ?, Sectie = ?, PV = ?, Nr_TVA = ?, MZ = ?
                 WHERE ProdID = ?",
                [$barcod, ($denumire === '' ? null : $denumire), $um,
                 $irp, $iivcn, $nrGrp, $kp,
                 $bg, $fg, $fontSize, $bold, $poz, ($fontType === '' ? null : $fontType),
                 ($imagine === '' ? null : $imagine), $sectie, $pv, $nrTva, $mz, $prodId]
            );
            if (!$stmt) {
                sendJsonResponse(["status" => "error", "message" => "Eroare actualizare produs: " . sqlsrv_errors()[0]['message']], 500);
            }
            sendJsonResponse(["status" => "success", "message" => "Produs actualizat"]);
        }
        break;

    case 'delete':
        if ($prodId <= 0) {
            sendJsonResponse(["status" => "error", "message" => "ProdID invalid"], 400);
        }
        $stmt = sqlsrv_query($conn, "DELETE FROM tblProd WHERE ProdID = ?", [$prodId]);
        if (!$stmt) {
            sendJsonResponse(["status" => "error", "message" => "Eroare stergere produs: " . sqlsrv_errors()[0]['message']], 500);
        }
        sendJsonResponse(["status" => "success", "message" => "Produs sters"]);
        break;

    default:
        sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}
