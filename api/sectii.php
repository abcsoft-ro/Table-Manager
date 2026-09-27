<?php
/**
 * API: Sectii (tblSectii: Sectie, Denumire)
 * GET  -> lista sectiilor
 * POST -> actiuni: insert / update / delete
 *
 * Sectiile 1 si 2 sunt protejate (nu pot fi sterse). Campul Status nu se foloseste.
 */
require_once __DIR__ . '/db.php';

$conn = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = [];
    $stmt = sqlsrv_query($conn, "SELECT Sectie, Denumire FROM tblSectii ORDER BY Sectie");
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = [
                "Sectie" => (int)$r['Sectie'],
                "Denumire" => trim($r['Denumire'] ?? '')
            ];
        }
    }
    sendJsonResponse(["status" => "success", "rows" => $rows]);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input || !is_array($input)) {
    $input = $_POST;
}

$action = $input['action'] ?? '';
$sectie = (int)($input['Sectie'] ?? 0);

switch ($action) {
    case 'insert':
    case 'update':
        $denumire = trim($input['Denumire'] ?? '');
        $denLen = function_exists('mb_strlen') ? mb_strlen($denumire, 'UTF-8') : strlen($denumire);
        if ($sectie <= 0) {
            sendJsonResponse(["status" => "error", "message" => "Sectie trebuie sa fie un numar intreg mai mare decat 0"], 400);
        }
        if ($denumire === '' || $denLen > 10) {
            sendJsonResponse(["status" => "error", "message" => "Denumire obligatorie, maxim 10 caractere"], 400);
        }

        if ($action === 'insert') {
            $stmt = sqlsrv_query($conn, "INSERT INTO tblSectii (Sectie, Denumire) VALUES (?, ?)", [$sectie, $denumire]);
            if (!$stmt) {
                sendJsonResponse(["status" => "error", "message" => "Eroare inserare (posibil Sectie duplicat): " . sqlsrv_errors()[0]['message']], 500);
            }
            sendJsonResponse(["status" => "success", "message" => "Sectie adaugata"]);
        } else {
            $stmt = sqlsrv_query($conn, "UPDATE tblSectii SET Denumire = ? WHERE Sectie = ?", [$denumire, $sectie]);
            if (!$stmt) {
                sendJsonResponse(["status" => "error", "message" => "Eroare actualizare: " . sqlsrv_errors()[0]['message']], 500);
            }
            sendJsonResponse(["status" => "success", "message" => "Sectie actualizata"]);
        }
        break;

    case 'delete':
        if ($sectie <= 0) {
            sendJsonResponse(["status" => "error", "message" => "Sectie invalida"], 400);
        }
        if ($sectie === 1 || $sectie === 2) {
            sendJsonResponse(["status" => "error", "message" => "Sectia $sectie este protejata si nu poate fi stearsa"], 400);
        }
        $stmt = sqlsrv_query($conn, "DELETE FROM tblSectii WHERE Sectie = ?", [$sectie]);
        if (!$stmt) {
            sendJsonResponse(["status" => "error", "message" => "Eroare stergere (posibil referita de produse): " . sqlsrv_errors()[0]['message']], 500);
        }
        sendJsonResponse(["status" => "success", "message" => "Sectie stearsa"]);
        break;

    default:
        sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}
