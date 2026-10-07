<?php
/**
 * timeline_backfill.php — one-off: seed the request timeline from existing data
 * for requests received in the last 12 months (or --months=N).
 *
 *   requests.notes not empty → note "Notes (import)"  (dated updated_at if the column exists, else date_received)
 *   confirmation_date        → confirmation "Booking confirmed (import)"
 *   invoices                 → invoice "Invoice <number> issued (import)"  (issue_date)
 *   invoice payments         → payment "Payment <amount> on <number> (import)" (payment_date; cancelled ones skipped)
 *
 * Dry run by default (prints counts); writes only with --confirm. Re-running is safe:
 * an event with the same request, type, title and date is not inserted twice.
 *
 *   php tools/timeline_backfill.php [--months=12] [--confirm]
 *
 * CLI only (run over SSH on the server).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('Africa/Dar_es_Salaam');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
if (!function_exists('db')) {
    function db(): PDO { global $pdo; return $pdo; }
}
require_once __DIR__ . '/../modules/leads/includes/timeline_service.php';

$confirm = in_array('--confirm', $argv, true);
$months  = 12;
foreach ($argv as $a) if (preg_match('/^--months=(\d+)$/', $a, $m)) $months = max(1, (int)$m[1]);
$since = date('Y-m-d', strtotime('-' . $months . ' months'));

$db = db();
tl_schema($db);

$cols = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests'")
           ->fetchAll(PDO::FETCH_COLUMN);
$noteDate = in_array('updated_at', $cols, true) ? 'COALESCE(r.updated_at, r.date_received)' : 'r.date_received';

$exists = $db->prepare("SELECT id FROM request_timeline WHERE request_id = ? AND event_type = ? AND title = ? AND event_at = ? LIMIT 1");
$insert = $db->prepare("INSERT INTO request_timeline (request_id, event_at, event_type, title, body, author_type, source, refs, created_at)
                        VALUES (?,?,?,?,?,'system','import',?,?)");
$counts = ['note' => 0, 'confirmation' => 0, 'invoice' => 0, 'payment' => 0, 'skipped_existing' => 0];

/** Insert one event unless it is already there. */
$add = function (int $rid, string $at, string $type, string $title, ?string $body, array $refs = []) use ($exists, $insert, $confirm, &$counts) {
    $at = strlen($at) === 10 ? $at . ' 09:00:00' : $at;
    $title = tl_cut($title, 200);
    $exists->execute([$rid, $type, $title, $at]);
    if ($exists->fetchColumn()) { $counts['skipped_existing']++; return; }
    $counts[$type]++;
    if (!$confirm) return;
    $insert->execute([$rid, $at, $type, $title, $body !== null && trim($body) !== '' ? tl_cut($body, 20000) : null,
                      $refs ? json_encode($refs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, date('Y-m-d H:i:s')]);
};

// Notes
$st = $db->prepare("SELECT r.id, r.notes, $noteDate AS at FROM requests r
                    WHERE r.date_received >= ? AND r.notes IS NOT NULL AND TRIM(r.notes) <> ''");
$st->execute([$since]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $add((int)$r['id'], (string)$r['at'], 'note', 'Notes (import)', $r['notes']);

// Confirmations
$st = $db->prepare("SELECT id, confirmation_date, practice_code FROM requests WHERE date_received >= ? AND confirmation_date IS NOT NULL");
$st->execute([$since]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $add((int)$r['id'], (string)$r['confirmation_date'], 'confirmation', 'Booking confirmed (import)', 'Folder: ' . $r['practice_code']);
}

// Invoices + payments
$st = $db->prepare("SELECT i.id, i.request_id, i.invoice_number, i.issue_date, i.currency, i.total, i.status
                    FROM invoices i JOIN requests r ON r.id = i.request_id WHERE r.date_received >= ?");
$st->execute([$since]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
    $add((int)$i['request_id'], (string)$i['issue_date'], 'invoice', 'Invoice ' . $i['invoice_number'] . ' issued (import)',
         'Total ' . $i['currency'] . ' ' . number_format((float)$i['total'], 2) . ' · ' . $i['status'],
         ['invoice_id' => (int)$i['id'], 'invoice_number' => $i['invoice_number']]);
}
$st = $db->prepare("SELECT p.id, p.payment_date, p.amount, p.method, p.reference, i.id AS invoice_id, i.invoice_number, i.currency, i.request_id
                    FROM invoice_payments p JOIN invoices i ON i.id = p.invoice_id JOIN requests r ON r.id = i.request_id
                    WHERE r.date_received >= ? AND p.cancelled_at IS NULL");
$st->execute([$since]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $add((int)$p['request_id'], (string)$p['payment_date'], 'payment',
         'Payment ' . $p['currency'] . ' ' . number_format((float)$p['amount'], 2) . ' on ' . $p['invoice_number'] . ' (import)',
         trim($p['method'] . ($p['reference'] ? ' · ref ' . $p['reference'] : '')),
         ['invoice_id' => (int)$p['invoice_id'], 'invoice_number' => $p['invoice_number'], 'payment_id' => (int)$p['id']]);
}

echo ($confirm ? 'WRITTEN' : 'DRY RUN (add --confirm to write)') . " — requests received since $since\n";
foreach ($counts as $k => $n) echo str_pad($k, 18) . $n . "\n";
