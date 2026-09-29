<?php
/**
 * API: Coada durabila de export catre server (temp_Send_Sql)
 *
 * Folosita de:
 *  - serviciul Python (sync-service), care ridica randurile cu Preluat = 0:
 *    GET ?action=claim, apoi POST {action:'done'|'fail'}.
 *  - operare/vizualizare: GET ?action=list|status|get,
 *    POST {action:'retry'|'delete'|'clear_done'}.
 *
 * La claim, pe langa comanda SQL, se intoarce si conexiunea la serverul extern
 * (tblConectare ID = 1), ca worker-ul Python sa stie unde sa execute procedura.
 */
require_once __DIR__ . '/send_common.php';

$conn = getDBConnection();
ensureSendSqlTable($conn);

$isGet = ($_SERVER['REQUEST_METHOD'] === 'GET');

if ($isGet) {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'claim') {
        // Ridica atomic un rand gata de procesat: 'pending' cu NextAttempt
        // depasit sau in-flight ramas blocat ('sending' cu NextAttempt expirat,
        // ex. worker-ul a fost oprit in timpul executiei). 'failed' NU se ridica
        // automat (a depasit max_attempts si asteapta retry manual); un esec
        // tranzitoriu pune randul inapoi pe 'pending' cu NextAttempt.
        // READPAST + UPDLOCK impiedica dubla preluare; NextAttempt se pune la
        // +5 min pe perioada executiei, ca un crash sa nu blocheze randul.
        $sql = "
        ;WITH cte AS (
            SELECT TOP (1) * FROM temp_Send_Sql WITH (UPDLOCK, READPAST)
            WHERE Preluat = 0 AND (
                    (Stare = 'pending' AND (NextAttempt IS NULL OR NextAttempt <= GETDATE()))
                 OR (Stare = 'sending' AND NextAttempt IS NOT NULL AND NextAttempt <= GETDATE())
            )
            ORDER BY Id
        )
        UPDATE cte
           SET Stare = 'sending', Attempts = Attempts + 1,
               NextAttempt = DATEADD(minute, 5, GETDATE())
        OUTPUT inserted.Id, inserted.DocID, inserted.str_sql,
               inserted.Attempts;";

        $stmt = sqlsrv_query($conn, $sql);
        if ($stmt === false) {
            sendJsonResponse(["status" => "error", "message" => "Eroare claim: " . sqlsrv_errors()[0]['message']], 500);
        }
        $job = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if (!$job) {
            sendJsonResponse(["status" => "empty"]);
        }
        sendJsonResponse([
            "status" => "success",
            "job" => [
                "Id" => (int)$job['Id'],
                "DocID" => $job['DocID'] === null ? null : (int)$job['DocID'],
                "str_sql" => (string)($job['str_sql'] ?? ''),
                "Attempts" => (int)$job['Attempts']
            ],
            "db" => getConectareDb($conn)
        ]);
    }

    if ($action === 'status') {
        $sql = "SELECT
                    SUM(CASE WHEN Preluat = 0 AND Stare = 'pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN Preluat = 0 AND Stare = 'sending' THEN 1 ELSE 0 END) AS sending,
                    SUM(CASE WHEN Preluat = 0 AND Stare = 'failed' THEN 1 ELSE 0 END) AS failed,
                    SUM(CASE WHEN Preluat = 1 THEN 1 ELSE 0 END) AS done
                FROM temp_Send_Sql";
        $stmt = sqlsrv_query($conn, $sql);
        $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
        sendJsonResponse([
            "status" => "success",
            "pending" => (int)($row['pending'] ?? 0),
            "sending" => (int)($row['sending'] ?? 0),
            "failed" => (int)($row['failed'] ?? 0),
            "done" => (int)($row['done'] ?? 0)
        ]);
    }

    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = sqlsrv_query($conn, "SELECT Id, DocID, str_sql, Preluat, Stare, Attempts,
                                            NextAttempt, LastError, CreatedAt, SentAt
                                     FROM temp_Send_Sql WHERE Id = ?", [$id]);
        $r = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
        if (!$r) { sendJsonResponse(["status" => "error", "message" => "Rand inexistent"], 404); }
        sendJsonResponse(["status" => "success", "row" => [
            "Id" => (int)$r['Id'],
            "DocID" => $r['DocID'] === null ? null : (int)$r['DocID'],
            "str_sql" => (string)($r['str_sql'] ?? ''),
            "Preluat" => ((int)$r['Preluat'] === 1),
            "Stare" => trim((string)($r['Stare'] ?? '')),
            "Attempts" => (int)$r['Attempts'],
            "NextAttempt" => sendQueueDateToString($r['NextAttempt']),
            "LastError" => trim((string)($r['LastError'] ?? '')),
            "CreatedAt" => sendQueueDateToString($r['CreatedAt']),
            "SentAt" => sendQueueDateToString($r['SentAt'])
        ]]);
    }

    // implicit: lista (pentru UI / depanare)
    $rows = [];
    $stmt = sqlsrv_query($conn, "SELECT TOP 100 Id, DocID, Preluat, Stare, Attempts,
                                        NextAttempt, LastError, CreatedAt, SentAt
                                 FROM temp_Send_Sql ORDER BY Id DESC");
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = [
                "Id" => (int)$r['Id'],
                "DocID" => $r['DocID'] === null ? null : (int)$r['DocID'],
                "Preluat" => ((int)$r['Preluat'] === 1),
                "Stare" => trim((string)($r['Stare'] ?? '')),
                "Attempts" => (int)$r['Attempts'],
                "NextAttempt" => sendQueueDateToString($r['NextAttempt']),
                "LastError" => trim((string)($r['LastError'] ?? '')),
                "CreatedAt" => sendQueueDateToString($r['CreatedAt']),
                "SentAt" => sendQueueDateToString($r['SentAt'])
            ];
        }
    }
    sendJsonResponse(["status" => "success", "rows" => $rows]);
}

// ---- POST ---------------------------------------------------------------
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input || !is_array($input)) {
    $input = $_POST;
}

$action = $input['action'] ?? '';
$id = (int)($input['id'] ?? 0);

switch ($action) {
    case 'done':
        if ($id <= 0) { sendJsonResponse(["status" => "error", "message" => "id invalid"], 400); }
        $stmt = sqlsrv_query(
            $conn,
            "UPDATE temp_Send_Sql SET Preluat = 1, Stare = 'done', SentAt = GETDATE(), LastError = NULL WHERE Id = ?",
            [$id]
        );
        if ($stmt === false) {
            sendJsonResponse(["status" => "error", "message" => "Eroare done: " . sqlsrv_errors()[0]['message']], 500);
        }
        sendJsonResponse(["status" => "success"]);
        break;

    case 'fail':
        if ($id <= 0) { sendJsonResponse(["status" => "error", "message" => "id invalid"], 400); }
        $error = trim((string)($input['error'] ?? ''));
        if (mb_strlen($error) > 500) { $error = mb_substr($error, 0, 500); }
        $permanent = !empty($input['permanent']);

        if ($permanent) {
            $stmt = sqlsrv_query(
                $conn,
                "UPDATE temp_Send_Sql SET Stare = 'failed', NextAttempt = NULL, LastError = ? WHERE Id = ?",
                [$error, $id]
            );
        } else {
            $retry = max(1, (int)($input['retryInSeconds'] ?? 30));
            $stmt = sqlsrv_query(
                $conn,
                "UPDATE temp_Send_Sql SET Stare = 'pending', NextAttempt = DATEADD(second, ?, GETDATE()), LastError = ? WHERE Id = ?",
                [$retry, $error, $id]
            );
        }
        if ($stmt === false) {
            sendJsonResponse(["status" => "error", "message" => "Eroare fail: " . sqlsrv_errors()[0]['message']], 500);
        }
        sendJsonResponse(["status" => "success"]);
        break;

    case 'retry':
        if ($id <= 0) { sendJsonResponse(["status" => "error", "message" => "id invalid"], 400); }
        $stmt = sqlsrv_query(
            $conn,
            "UPDATE temp_Send_Sql SET Preluat = 0, Stare = 'pending', NextAttempt = NULL, LastError = NULL WHERE Id = ?",
            [$id]
        );
        if ($stmt === false) {
            sendJsonResponse(["status" => "error", "message" => "Eroare retry: " . sqlsrv_errors()[0]['message']], 500);
        }
        wakeSyncService();
        sendJsonResponse(["status" => "success", "message" => "Rand reprogramat"]);
        break;

    case 'delete':
        if ($id <= 0) { sendJsonResponse(["status" => "error", "message" => "id invalid"], 400); }
        $stmt = sqlsrv_query($conn, "DELETE FROM temp_Send_Sql WHERE Id = ?", [$id]);
        if ($stmt === false) {
            sendJsonResponse(["status" => "error", "message" => "Eroare stergere: " . sqlsrv_errors()[0]['message']], 500);
        }
        sendJsonResponse(["status" => "success", "message" => "Rand sters"]);
        break;

    case 'clear_done':
        sqlsrv_query($conn, "DELETE FROM temp_Send_Sql WHERE Preluat = 1");
        sendJsonResponse(["status" => "success", "message" => "Randurile trimise au fost sterse"]);
        break;

    default:
        sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}
