<?php
/**
 * invoices_zip.php
 *
 * Downloads every invoice matching the current list filters as a single ZIP
 * of PDFs. Reuses the exact filter logic of invoices.php and the same Dompdf
 * pipeline (buildInvoiceHtml) used by the email sender, so each PDF in the
 * archive is identical to the emailed / printed one.
 *
 * GET params (same as invoices.php): q, status, issuer, currency, year, request_id
 */

require_once 'config.php';
require_once __DIR__ . '/includes/invoice_html.php';
requireInvoiceAccess();

// Safety cap — rendering PDFs is CPU/memory heavy on shared hosting.
const MAX_ZIP_INVOICES = 300;

$db = db();

// ── Filters — mirror invoices.php exactly ─────────────────────────────────────
$search    = trim($_GET['q']          ?? '');
$fstatus   = $_GET['status']          ?? '';
$fissuer   = $_GET['issuer']          ?? '';
$freqid    = (int)($_GET['request_id'] ?? 0);
$fyear     = (int)($_GET['year'] ?? ($freqid ? 0 : date('Y')));
$fcurr     = $_GET['currency']        ?? '';

$where  = ['1=1']; $params = [];
if ($search)  { $where[] = '(i.invoice_number LIKE ? OR i.bill_to_name LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($fstatus && array_key_exists($fstatus, INV_STATUSES)) { $where[] = 'i.status = ?'; $params[] = $fstatus; }
if ($fissuer && in_array($fissuer, INV_ISSUERS))           { $where[] = 'i.issuer = ?'; $params[] = $fissuer; }
if ($fyear > 0) { $where[] = 'YEAR(i.issue_date) = ?'; $params[] = $fyear; }
if ($fcurr && in_array($fcurr, INV_CURRENCIES))            { $where[] = 'i.currency = ?'; $params[] = $fcurr; }
if ($freqid)                                               { $where[] = 'i.request_id = ?'; $params[] = $freqid; }

$sql = "SELECT i.* FROM invoices i
        WHERE " . implode(' AND ', $where) . "
        ORDER BY i.issue_date DESC, i.id DESC";
$stmt = $db->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Friendly, pre-stream error pages ──────────────────────────────────────────
function zip_fail(string $msg): void {
    http_response_code(400);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><meta charset="utf-8">'
       . '<div style="font-family:system-ui,Arial,sans-serif;max-width:520px;margin:80px auto;'
       . 'padding:24px 28px;border:1px solid #eee;border-radius:10px;color:#333">'
       . '<h2 style="margin:0 0 10px;color:#C0211B">Cannot build ZIP</h2>'
       . '<p style="line-height:1.6">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
       . '<p><a href="javascript:history.back()" style="color:#C0211B">← Back to invoices</a></p></div>';
    exit;
}

if (!$rows) {
    zip_fail('No invoices match the current filters, so there is nothing to download.');
}
if (count($rows) > MAX_ZIP_INVOICES) {
    zip_fail('This selection has ' . count($rows) . ' invoices, above the limit of '
           . MAX_ZIP_INVOICES . ' per ZIP. Please narrow the filters (e.g. by year, issuer or currency) and try again.');
}

$vendorAutoload = __DIR__ . '/../../vendor/autoload.php';
if (!file_exists($vendorAutoload)) {
    zip_fail('PDF library not installed on the server (run: composer install).');
}
require_once $vendorAutoload;

if (!class_exists('ZipArchive')) {
    zip_fail('ZipArchive PHP extension is not available on the server.');
}

// ── Build the ZIP in a temp file ──────────────────────────────────────────────
@set_time_limit(0);
@ini_set('memory_limit', '512M');

$tmpFile = tempnam(sys_get_temp_dir(), 'invzip_');
$zip = new ZipArchive();
if ($tmpFile === false || $zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
    zip_fail('Could not create the temporary ZIP file on the server.');
}

$options = new \Dompdf\Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);

$usedNames = [];
$errors    = [];
$added     = 0;

$itemStmt = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order, id");
$payStmt  = $db->prepare("SELECT * FROM invoice_payments WHERE invoice_id = ? AND cancelled_at IS NULL ORDER BY payment_date");

foreach ($rows as $inv) {
    try {
        $itemStmt->execute([$inv['id']]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        $payStmt->execute([$inv['id']]);
        $payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml(buildInvoiceHtml($inv, $items, $payments));
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdfData = $dompdf->output();
        unset($dompdf);

        // Unique, filesystem-safe name: Invoice_<number>_<billto>.pdf
        $base = 'Invoice_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$inv['invoice_number']);
        $who  = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$inv['bill_to_name']);
        $who  = trim($who, '_');
        if ($who !== '') $base .= '_' . mb_substr($who, 0, 40);
        $name = $base . '.pdf';
        if (isset($usedNames[$name])) {
            $name = $base . '_' . $inv['id'] . '.pdf';
        }
        $usedNames[$name] = true;

        $zip->addFromString($name, $pdfData);
        $added++;
    } catch (\Throwable $e) {
        $errors[] = 'Invoice ' . ($inv['invoice_number'] ?? $inv['id']) . ': ' . $e->getMessage();
    }
}

// Note any per-invoice failures inside the archive.
if ($errors) {
    $zip->addFromString('_SKIPPED.txt',
        "The following invoices could not be rendered and were skipped:\r\n\r\n" . implode("\r\n", $errors) . "\r\n");
}

$zip->close();

if ($added === 0) {
    @unlink($tmpFile);
    zip_fail('None of the selected invoices could be rendered. ' . implode(' ', $errors));
}

// ── Build a descriptive archive name from the active filters ──────────────────
$parts = ['invoices'];
if ($fyear > 0)  $parts[] = (string)$fyear;
if ($fissuer)    $parts[] = ($fissuer === 'Savannah Holidays Ltd') ? 'SH' : 'SE';
if ($fcurr)      $parts[] = $fcurr;
if ($fstatus)    $parts[] = preg_replace('/[^A-Za-z0-9]+/', '', $fstatus);
if ($freqid)     $parts[] = 'req' . $freqid;
$parts[] = date('Ymd');
$zipName = implode('_', $parts) . '.zip';

// ── Stream ────────────────────────────────────────────────────────────────────
while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
readfile($tmpFile);
@unlink($tmpFile);
exit;
