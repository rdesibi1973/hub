<?php
/**
 * modules/iti/export_mag_word.php?id=N&lang=it — the programme in the magazine
 * layout as an editable Word file, for agencies (Hub users only).
 * ?format=pdf gives the same programme as a PDF rendered on the server.
 * &no_prices=1: pdf / docx without the price table; named <Ref. Number>_SenzaPrezzi.pdf / .docx
 * when the programme has a Ref. Number.
 * &neutral=1 (with format=pdf): white-label PDF for the agency's end client — no prices, Savannah logo / contacts /
 * T&C / names (iti_export_neutralise()); named <Title>_<LANG>.pdf, never the Ref. Number (it holds the agency's name).
 */
ob_start();
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_export.php';
require_once __DIR__ . '/includes/iti_program_service.php';   // iti_ps_document_name()

$id       = (int)($_GET['id'] ?? 0);
$format   = (string)($_GET['format'] ?? 'docx');
$neutral  = !empty($_GET['neutral']);
$noPrices = !$neutral && !empty($_GET['no_prices']) && !in_array(strtolower(trim($format)), ['guide', 'guide_docx', 'guide_word'], true);
try {
    $f = iti_export_file($id, (string)($_GET['lang'] ?? ''), $format, ['no_prices' => $noPrices, 'neutral' => $neutral]);
} catch (InvalidArgumentException $e) {
    ob_end_clean(); http_response_code(404); die(h($e->getMessage()));
} catch (Throwable $e) {
    ob_end_clean(); http_response_code(500); die(h($e->getMessage()));
}
if ($noPrices) {
    $refName = iti_ps_document_name((array)iti_get_program($id), $f['format'], true);
    if ($refName !== null) $f['name'] = $refName;
}
iti_export_send($f);
