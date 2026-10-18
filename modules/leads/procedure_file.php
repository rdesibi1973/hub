<?php
/**
 * procedure_file.php?name=<file>[&dl=1] — streams one office procedure from Dropbox (procedures_service.php).
 * name must be exactly a file of the live listing of /Operations/OfficeProcedures (PDF), or for admins
 * "orig/<name>.docx". Inline by default (#page=N works in the browser viewer), attachment with dl=1.
 * 404: not in the folder; 502: Dropbox failure (JSON for AJAX, plain text otherwise).
 */
ob_start();
require_once 'config.php';
requireLogin();
require_once __DIR__ . '/includes/procedures_service.php';

function proc_fail(int $code, string $msg): void {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => $msg]);
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $msg . "\n";
    }
    exit;
}

$name = (string)($_GET['name'] ?? '');
try {
    $e = proc_find($name, proc_is_admin());
    if (!$e) proc_fail(404, 'Procedure not found: ' . $name);
    $data = proc_content($e);
} catch (Throwable $ex) {
    error_log('procedure_file: ' . $ex->getMessage());
    proc_fail(502, 'Dropbox is not reachable right now, try again in a minute.');
}

$ext = strtolower(pathinfo($e['name'], PATHINFO_EXTENSION));
$mime = $ext === 'pdf' ? 'application/pdf'
      : ($ext === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/msword');
$inline = $ext === 'pdf' && empty($_GET['dl']);
$ascii = preg_replace('/[^A-Za-z0-9 ._()-]/', '_', $e['name']);
while (ob_get_level()) ob_end_clean();
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($e['name']));
header('Content-Length: ' . strlen($data));
header('Cache-Control: private, max-age=0, must-revalidate');
header('X-Content-Type-Options: nosniff');
echo $data;
