<?php
/**
 * API: Coada durabila de tiparire (tblPrintQueue)
 *
 * Este folosita de:
 *  - serviciul Python (print-service) care ridica joburi: GET ?action=claim,
 *    apoi POST {action:'done'|'fail'}.
 *  - interfata POS pentru vizualizare/retry: GET ?action=list|status,
 *    POST {action:'retry'|'delete'|'clear_done'}.
 */
require_once __DIR__ . '/print_common.php';

$conn = getDBConnection();
ensurePrintQueueTable($conn);

$isGet = ($_SERVER['REQUEST_METHOD'] === 'GET');

if ($isGet) {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'claim') {
        // Ridica atomic un job gata de procesat ('pending' cu NextAttempt
        // depasit). 'failed' NU se ridica automat (a depasit max_attempts si
        // asteapta retry manual din ecranul Coada printare); un esec tranzitoriu
        // pune jobul inapoi pe 'pending' cu NextAttempt. READPAST + UPDLOCK
        // impiedica dubla preluare.
        $sql = "
        ;WITH cte AS (
            SELECT TOP (1) * FROM tblPrintQueue WITH (UPDLOCK, READPAST)
            WHERE Stare = 'pending'
              AND (NextAttempt IS NULL OR NextAttempt <= GETDATE())
            ORDER BY JobID
        )
        UPDATE cte
           SET Stare = 'printing', Attempts = Attempts + 1
        OUTPUT inserted.JobID, inserted.Tip, inserted.RefDocID, inserted.PrinterNr,
               inserted.Payload, inserted.Attempts;";

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
                "JobID" => (int)$job['JobID'],
                "Tip" => trim($job['Tip'] ?? ''),
                "RefDocID" => $job['RefDocID'] === null ? null : (int)$job['RefDocID'],
                "PrinterNr" => $job['PrinterNr'] === null ? null : (int)$job['PrinterNr'],
                "Payload" => (string)($job['Payload'] ?? '{}'),
                "Attempts" => (int)$job['Attempts']
            ]
        ]);
    }

    if ($action === 'status') {
        $sql = "SELECT
                    SUM(CASE WHEN Stare = 'pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN Stare = 'printing' THEN 1 ELSE 0 END) AS printing,
                    SUM(CASE WHEN Stare = 'failed' THEN 1 ELSE 0 END) AS failed,
                    SUM(CASE WHEN Stare = 'done' THEN 1 ELSE 0 END) AS donec
                FROM tblPrintQueue";
        $stmt = sqlsrv_query($conn, $sql);
        $row = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
        sendJsonResponse([
            "status" => "success",
            "pending" => (int)($row['pending'] ?? 0),
            "printing" => (int)($row['printing'] ?? 0),
            "failed" => (int)($row['failed'] ?? 0),
            "done" => (int)($row['donec'] ?? 0)
        ]);
    }

    if ($action === 'get') {
        $jobId = (int)($_GET['jobId'] ?? 0);
        $stmt = sqlsrv_query($conn, "SELECT q.JobID, q.Tip, q.RefDocID, q.PrinterNr, q.Stare, q.Attempts, q.NextAttempt,
                                            q.LastError, q.CreatedAt, q.PrintedAt,
                                            COALESCE(k.Nume, k.DenumirePrinter, '') AS PrinterName
                                     FROM tblPrintQueue q
                                     LEFT JOIN tblKP k ON q.PrinterNr = k.NrLogic
                                     WHERE q.JobID = ?", [$jobId]);
        $r = $stmt ? sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) : null;
        if (!$r) { sendJsonResponse(["status" => "error", "message" => "Job inexistent"], 404); }
        sendJsonResponse(["status" => "success", "job" => [
            "JobID" => (int)$r['JobID'],
            "Tip" => trim($r['Tip'] ?? ''),
            "RefDocID" => $r['RefDocID'] === null ? null : (int)$r['RefDocID'],
            "PrinterNr" => $r['PrinterNr'] === null ? null : (int)$r['PrinterNr'],
            "PrinterName" => trim((string)($r['PrinterName'] ?? '')),
            "Stare" => trim($r['Stare'] ?? ''),
            "Attempts" => (int)$r['Attempts'],
            "NextAttempt" => printQueueDateToString($r['NextAttempt']),
            "LastError" => trim((string)($r['LastError'] ?? '')),
            "CreatedAt" => printQueueDateToString($r['CreatedAt']),
            "PrintedAt" => printQueueDateToString($r['PrintedAt'])
        ]]);
    }

    // implicit: lista (pentru UI)
    $rows = [];
    $stmt = sqlsrv_query($conn, "SELECT TOP 100 q.JobID, q.Tip, q.RefDocID, q.PrinterNr, q.Stare, q.Attempts, q.NextAttempt,
                                        q.LastError, q.CreatedAt, q.PrintedAt,
                                        JSON_VALUE(q.Payload, '$.title') AS Title,
                                        COALESCE(k.Nume, k.DenumirePrinter, '') AS PrinterName
                                 FROM tblPrintQueue q
                                 LEFT JOIN tblKP k ON q.PrinterNr = k.NrLogic
                                 ORDER BY q.JobID DESC");
    if ($stmt) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = [
                "JobID" => (int)$r['JobID'],
                "Tip" => trim($r['Tip'] ?? ''),
                "Title" => trim((string)($r['Title'] ?? '')),
                "RefDocID" => $r['RefDocID'] === null ? null : (int)$r['RefDocID'],
                "PrinterNr" => $r['PrinterNr'] === null ? null : (int)$r['PrinterNr'],
                "PrinterName" => trim((string)($r['PrinterName'] ?? '')),
                "Stare" => trim($r['Stare'] ?? ''),
                "Attempts" => (int)$r['Attempts'],
                "NextAttempt" => printQueueDateToString($r['NextAttempt']),
                "LastError" => trim((string)($r['LastError'] ?? '')),
                "CreatedAt" => printQueueDateToString($r['CreatedAt']),
                "PrintedAt" => printQueueDateToString($r['PrintedAt'])
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
$jobId = (int)($input['jobId'] ?? 0);

switch ($action) {
    case 'done':
        if ($jobId <= 0) { sendJsonResponse(["status" => "error", "message" => "jobId invalid"], 400); }
        $stmt = sqlsrv_query($conn, "UPDATE tblPrintQueue SET Stare = 'done', PrintedAt = GETDATE(), LastError = NULL WHERE JobID = ?", [$jobId]);
        if ($stmt === false) {
            sendJsonResponse(["status" => "error", "message" => "Eroare done: " . sqlsrv_errors()[0]['message']], 500);
        }
        sendJsonResponse(["status" => "success"]);
        break;

    case 'fail':
        if ($jobId <= 0) { sendJsonResponse(["status" => "error", "message" => "jobId invalid"], 400); }
        $error = trim((string)($input['error'] ?? ''));
        if (mb_strlen($error) > 255) { $error = mb_substr($error, 0, 255); }
        $permanent = !empty($input['permanent']);

        if ($permanent) {
            $stmt = sqlsrv_query(
                $conn,
                "UPDATE tblPrintQueue SET Stare = 'failed', NextAttempt = NULL, LastError = ? WHERE JobID = ?",
                [$error, $jobId]
            );
        } else {
            $retry = max(1, (int)($input['retryInSeconds'] ?? 30));
            $stmt = sqlsrv_query(
                $conn,
                "UPDATE tblPrintQueue SET Stare = 'pending', NextAttempt = DATEADD(second, ?, GETDATE()), LastError = ? WHERE JobID = ?",
                [$retry, $error, $jobId]
            );
        }
        if ($stmt === false) {
            sendJsonResponse(["status" => "error", "message" => "Eroare fail: " . sqlsrv_errors()[0]['message']], 500);
        }
        sendJsonResponse(["status" => "success"]);
        break;

    case 'retry':
        if ($jobId <= 0) { sendJsonResponse(["status" => "error", "message" => "jobId invalid"], 400); }
        $stmt = sqlsrv_query(
            $conn,
            "UPDATE tblPrintQueue SET Stare = 'pending', NextAttempt = NULL, LastError = NULL WHERE JobID = ?",
            [$jobId]
        );
        if ($stmt === false) {
            sendJsonResponse(["status" => "error", "message" => "Eroare retry: " . sqlsrv_errors()[0]['message']], 500);
        }
        wakePrintService();
        sendJsonResponse(["status" => "success", "message" => "Job reprogramat"]);
        break;

    case 'delete':
        if ($jobId <= 0) { sendJsonResponse(["status" => "error", "message" => "jobId invalid"], 400); }
        $stmt = sqlsrv_query($conn, "DELETE FROM tblPrintQueue WHERE JobID = ?", [$jobId]);
        if ($stmt === false) {
            sendJsonResponse(["status" => "error", "message" => "Eroare stergere: " . sqlsrv_errors()[0]['message']], 500);
        }
        sendJsonResponse(["status" => "success", "message" => "Job sters"]);
        break;

    case 'clear_done':
        sqlsrv_query($conn, "DELETE FROM tblPrintQueue WHERE Stare = 'done'");
        sendJsonResponse(["status" => "success", "message" => "Joburile finalizate au fost sterse"]);
        break;

    default:
        sendJsonResponse(["status" => "error", "message" => "Actiune necunoscuta"], 400);
}
