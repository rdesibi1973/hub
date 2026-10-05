<?php
/**
 * modules/iti/export_mag_word.php?id=N&lang=it — the programme in the magazine
 * layout as an editable Word file, for agencies (Hub users only).
 * ?format=pdf gives the same programme as a PDF rendered on the server.
 */
ob_start();
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_export.php';

try {
    $f = iti_export_file((int)($_GET['id'] ?? 0), (string)($_GET['lang'] ?? ''), (string)($_GET['format'] ?? 'docx'));
} catch (InvalidArgumentException $e) {
    ob_end_clean(); http_response_code(404); die(h($e->getMessage()));
} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500); die(h($e->getMessage()));
}
iti_export_send($f);
