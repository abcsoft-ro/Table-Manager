<?php
/**
 * Proxy pentru preview-ul emulatorului de bonuri.
 *
 * Pagina POS ruleaza pe HTTPS, deci un iframe direct catre serviciul Python
 * (http://127.0.0.1:8756) ar fi blocat de browser ca mixed content. Acest
 * endpoint preia HTML-ul de la serviciu si il serveste same-origin.
 */
require_once __DIR__ . '/print_common.php';

$jobId = (int)($_GET['jobId'] ?? 0);
$name = ($jobId > 0) ? ("job_" . $jobId . ".html") : "ultimul.html";
$url = PRINT_SERVICE_BASE . '/preview/' . $name;

$ctx = stream_context_create([
    'http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true]
]);
$body = @file_get_contents($url, false, $ctx);

header('Content-Type: text/html; charset=utf-8');
if ($body === false) {
    http_response_code(404);
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'></head>"
       . "<body style='font-family:monospace;background:#3a3a3a;color:#ddd;padding:24px;'>"
       . "Preview indisponibil. Serviciul de tiparire nu raspunde.</body></html>";
    exit;
}
echo $body;
