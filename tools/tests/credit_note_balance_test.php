<?php
/**
 * Credit notes no longer reopen the invoice balance (invoice_service.php, 15 Nov 2026). Local checks, no MySQL:
 * in-memory SQLite behind a small MySQL shim.
 *   C:/Utility/PHP/php.exe -d extension_dir=C:/Utility/PHP/ext -d extension=pdo_sqlite -d extension=mbstring tools/tests/credit_note_balance_test.php
 * Real cases: SE-2026-0332 Nuova Era paid 18,060 + CN 200 (and SE-2026-0004, SE-2026-0493) showed the credit as still due.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** MySQL → SQLite shim. */
class ShimPDO extends PDO {
    public function exec(string $sql): int|false {
        if (preg_match('/^\s*(CREATE TABLE IF NOT EXISTS|ALTER TABLE)/i', $sql)) return 0;
        return parent::exec($sql);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_ireplace(' FOR UPDATE', '', $query), $options);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false {
        if (stripos($query, 'INFORMATION_SCHEMA') !== false) {   // credit_note_allocations columns: all there, invoice_id nullable
            $query = "SELECT 'kind', 'YES' UNION ALL SELECT 'reference', 'YES' UNION ALL SELECT 'invoice_id', 'YES'";
        }
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$args);
    }
}
$db = new ShimPDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
$db->sqliteCreateFunction('REGEXP', fn($re, $s) => (int)preg_match('/' . $re . '/', (string)$s), 2);
$db->sqliteCreateFunction('SUBSTRING_INDEX', function ($s, $d, $n) { $p = explode($d, (string)$s); return $n < 0 ? implode($d, array_slice($p, $n)) : implode($d, array_slice($p, 0, $n)); }, 3);
function db() { global $db; return $db; }

$db->exec("CREATE TABLE requests (id INTEGER PRIMARY KEY, value_usd REAL)");
$db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, invoice_number TEXT, request_id INT, customer_id INT, bill_to_name TEXT, bill_to_address TEXT,
           issuer TEXT, currency TEXT, issue_date TEXT, due_date TEXT, terms TEXT, notes TEXT, terms_conditions TEXT, status TEXT DEFAULT 'New',
           subtotal REAL DEFAULT 0, total REAL DEFAULT 0, amount_paid REAL DEFAULT 0, balance_due REAL DEFAULT 0, follow_up INT DEFAULT 0,
           follow_up_note TEXT, created_by INT, created_at TEXT, updated_at TEXT)");
$db->exec("CREATE TABLE invoice_items (id INTEGER PRIMARY KEY, invoice_id INT, sort_order INT, description TEXT, quantity REAL, unit_price REAL, line_total REAL)");
$db->exec("CREATE TABLE invoice_payments (id INTEGER PRIMARY KEY, invoice_id INT, payment_date TEXT, amount REAL, method TEXT, reference TEXT, notes TEXT,
           cancelled_at TEXT, cancellation_reason TEXT)");
$db->exec("CREATE TABLE credit_notes (id INTEGER PRIMARY KEY, cn_number TEXT, invoice_id INT, request_id INT, customer_id INT, bill_to_name TEXT,
           bill_to_address TEXT, issuer TEXT, currency TEXT, issue_date TEXT, reason TEXT, notes TEXT, status TEXT, subtotal REAL DEFAULT 0,
           total REAL DEFAULT 0, payment_id INT, created_by INT, updated_at TEXT)");
$db->exec("CREATE TABLE credit_note_items (id INTEGER PRIMARY KEY, credit_note_id INT, sort_order INT, description TEXT, quantity REAL, unit_price REAL, line_total REAL)");
$db->exec("CREATE TABLE credit_note_allocations (id INTEGER PRIMARY KEY, credit_note_id INT, kind TEXT DEFAULT 'invoice', invoice_id INT NULL, payment_id INT,
           amount_cn REAL, amount_invoice REAL, fx_rate REAL DEFAULT 1, alloc_date TEXT, reference TEXT, notes TEXT, cancelled_at TEXT, created_by INT, created_at TEXT)");
$db->exec("CREATE TABLE memos (id INTEGER PRIMARY KEY)");   // memo_on_payment fails quietly (caught)

require_once __DIR__ . '/../../modules/invoices/includes/invoice_service.php';
require_once __DIR__ . '/../../modules/invoices/includes/invoice_html.php';

$fail = 0;
$t = function (string $name, bool $ok, $got = null) use (&$fail) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $got === null ? '' : '  got: ' . json_encode($got, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
    if (!$ok) $fail++;
};
$inv = fn(int $id) => inv_get($db, $id) ?? $db->query("SELECT * FROM invoices WHERE id = $id")->fetch();
$mk = function (int $id, string $num, int $req, float $total, float $paid) use ($db) {
    $db->prepare("INSERT INTO invoices (id, invoice_number, request_id, bill_to_name, issuer, currency, issue_date, status) VALUES (?,?,?,?,?,?,?,?)")
       ->execute([$id, $num, $req, 'Agency', 'Savannah Explorers Ltd', 'USD', '2026-06-01', 'New']);
    $db->prepare("INSERT INTO invoice_items (invoice_id, sort_order, description, quantity, unit_price, line_total) VALUES (?,0,'Safari',1,?,?)")
       ->execute([$id, $total, $total]);
    if ($paid > 0) $db->prepare("INSERT INTO invoice_payments (invoice_id, payment_date, amount, method, reference) VALUES (?,?,?,?,?)")
                      ->execute([$id, '2026-07-01', $paid, 'Bank Transfer', 'TT']);
    recalculate_invoice($db, $id);
    sync_request_value($db, $id);
};
$users = $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT)");
$reqVal = fn(int $r) => round((float)$db->query("SELECT value_usd FROM requests WHERE id = $r")->fetchColumn(), 2);
$line = fn(float $a, string $d = 'Goodwill') => [['description' => $d, 'quantity' => 1, 'unit_price' => $a, 'line_total' => $a]];

$db->exec("INSERT INTO requests VALUES (10, 0), (20, 0), (30, 0), (40, 0)");
$cnRow = fn(int $id) => $db->query("SELECT * FROM credit_notes WHERE id = $id")->fetch();

// ── 1. CN on a fully paid invoice (Nuova Era: T 18,060, P 18,060, CN 200) → credit to client ──
$mk(1, 'SE-2026-0332', 10, 18060, 18060);
$t('setup: fully paid', $inv(1)['status'] === 'Fully Paid' && (float)$inv(1)['balance_due'] == 0.0 && $reqVal(10) === 18060.0, $inv(1));
$pv = cn_create_preview($db, $inv(1), 200);
$t('preview: balance −200, credit to client 200, cn_available 200, Fully Paid', $pv['balance_after'] === -200.0
   && $pv['credit_to_client_after'] === 200.0 && $pv['cn_available'] === 200.0 && $pv['status_after'] === 'Fully Paid', $pv);
$cn = cn_create($db, $inv(1), $line(200, 'Picnic lunches without hampers'), '2026-11-15', 'Picnic lunches without hampers', '', 1);
$cnA = $cn['credit_note_id'];
$i1 = $inv(1);
$t('CN: Fully Paid, balance −200 (credit to client), paid unchanged', $i1['status'] === 'Fully Paid' && (float)$i1['balance_due'] == -200.0
   && (float)$i1['amount_paid'] == 18060.0, $i1);
$t('CN: no negative payment written', (int)$db->query("SELECT COUNT(*) FROM invoice_payments WHERE amount < 0")->fetchColumn() === 0);
$t('CN: remaining = amount, Issued', cn_remaining_credit($db, $cnA) === 200.0 && $cnRow($cnA)['status'] === 'Issued');
$t('request value = T − C', $reqVal(10) === 17860.0, $reqVal(10));
$tot = inv_doc_totals($i1, inv_credit_notes($db, 1));
$t('PDF totals: Payment Made, Credit note line, Credit to client 200',
   array_column($tot['rows'], 0) === ['Sub Total', 'Total', 'Payment Made', 'Credit note ' . $cn['cn_number'], 'Credit to client']
   && $tot['amount'] === 200.0, $tot);

// ── 2. Apply it to another invoice of the agency: counted once, source back to 0 ─
$mk(3, 'SE-2026-0504', 30, 2000, 0);
cn_apply_to_invoice($db, $cnA, 3, 200, 1.0, '2026-11-15', null, 1, ['strict' => true]);
$t('apply: target paid once (balance 1800, Partially Paid)', (float)$inv(3)['balance_due'] == 1800.0 && (float)$inv(3)['amount_paid'] == 200.0
   && $inv(3)['status'] === 'Partially Paid', $inv(3));
$t('apply: source from credit to client to 0, Fully Paid', $inv(1)['status'] === 'Fully Paid' && (float)$inv(1)['balance_due'] == 0.0, $inv(1));
$t('apply: CN remaining 0, Applied', cn_remaining_credit($db, $cnA) === 0.0 && $cnRow($cnA)['status'] === 'Applied', $cnRow($cnA));
$tot = inv_doc_totals($inv(1), inv_credit_notes($db, 1));
$t('PDF totals source: "Credit used on SE-2026-0504 (+) 200", Balance Due 0', in_array('Credit used on SE-2026-0504', array_column($tot['rows'], 0), true)
   && $tot['label'] === 'Balance Due' && $tot['amount'] === 0.0, $tot);
try { cn_apply_plan($db, $cnRow($cnA), $inv(1), 1, 1.0); $own = false; }
catch (RuntimeException $e) { $own = true; }
$t('apply to its own source invoice: refused', $own);

// ── 3. CN on a not fully paid invoice (Cascella type: T 6,990, P 2,090, C 4,900) ─
$mk(2, 'SE-2026-0400', 20, 6990, 2090);
$pv = cn_create_preview($db, $inv(2), 4900);
$t('preview unpaid: balance 0, cn_available 0', $pv['balance_after'] === 0.0 && $pv['cn_available'] === 0.0, $pv);
$cnB = cn_create($db, $inv(2), $line(4900, 'Cancellation'), '2026-11-15', 'Cancellation', '', 1)['credit_note_id'];
$t('unpaid: balance 0, Fully Paid, nothing to give back', (float)$inv(2)['balance_due'] == 0.0 && $inv(2)['status'] === 'Fully Paid'
   && cn_remaining_credit($db, $cnB) === 0.0, [$inv(2), cn_availability($db, $cnB)]);
try { cn_record_refund($db, $cnB, 1, '2026-11-15', '', null, 1); $nothing = false; } catch (RuntimeException $e) { $nothing = true; }
$t('unpaid: refund refused (no credit to give back)', $nothing);
$mk(5, 'SE-2026-0501', 0, 1000, 300);
cn_create($db, $inv(5), $line(100), '2026-11-15', 'Goodwill', '', 1);
$t('partly paid + small CN: balance 600, Partially Paid', (float)$inv(5)['balance_due'] == 600.0 && $inv(5)['status'] === 'Partially Paid', $inv(5));
$t('cn_new_errors: credits above the invoice total refused', (bool)cn_new_errors($inv(5), $line(950), '2026-11-15', inv_credited_total($db, 5)));

// ── 4. Refund paid (ODP type: T 5,425, P 5,425, CN 195) ─────────────────────────
$mk(4, 'SE-2026-0004', 40, 5425, 5425);
$cnC = cn_create($db, $inv(4), $line(195, 'English guide'), '2026-11-15', 'English guide', '', 1)['credit_note_id'];
$t('ODP: credit to client 195', (float)$inv(4)['balance_due'] == -195.0, $inv(4));
try { cn_record_refund($db, $cnC, 196, '2026-11-17', '', null, 1); $over = false; } catch (RuntimeException $e) { $over = true; }
$t('refund: more than the credit refused', $over);
$rid = cn_record_refund($db, $cnC, 195, '2026-11-17', 'TT-REF-1', null, 1);
$t('refund: source to 0, CN remaining 0, Applied', (float)$inv(4)['balance_due'] == 0.0 && cn_remaining_credit($db, $cnC) === 0.0
   && $cnRow($cnC)['status'] === 'Applied', [$inv(4), $cnRow($cnC)]);
$a = cn_allocations($db, $cnC);
$t('refund allocation: kind refund, no invoice, reference kept', $a[0]['kind'] === 'refund' && $a[0]['invoice_id'] === null && $a[0]['reference'] === 'TT-REF-1', $a);
cn_cancel($db, $cnC);
$t('cancel CN: source back to T − P (0), refund cancelled, request value T', (float)$inv(4)['balance_due'] == 0.0
   && $db->query("SELECT cancelled_at FROM credit_note_allocations WHERE id = $rid")->fetchColumn() !== null && $reqVal(40) === 5425.0, $inv(4));
cn_restore($db, $cnC);
$t('restore CN: credit to client 195 again, remaining 195', (float)$inv(4)['balance_due'] == -195.0 && cn_remaining_credit($db, $cnC) === 195.0, $inv(4));

// ── 5. Migration of the old negative payments ──────────────────────────────────
// Joyce Cleary: T 7,000, P 7,000, CN-2026-0002 6,790 recorded the old way (shown as 6,790 due).
$mk(6, 'SE-2026-0280', 0, 7000, 7000);
$db->exec("INSERT INTO credit_notes (id, cn_number, invoice_id, currency, issue_date, status, subtotal, total) VALUES (90, 'CN-2026-0002', 6, 'USD', '2026-10-01', 'Issued', 6790, 6790)");
$db->exec("INSERT INTO invoice_payments (invoice_id, payment_date, amount, method, reference) VALUES (6, '2026-10-01', -6790, 'Other', 'CN-2026-0002')");
$db->exec("UPDATE invoices SET amount_paid = 210, balance_due = 6790, status = 'Partially Paid' WHERE id = 6");
// Nuova Era-type: legacy negative payment AND the credit already applied elsewhere (counted twice today).
$mk(7, 'SE-2026-0333', 0, 18060, 18060);
$mk(8, 'SE-2026-0505', 0, 1000, 0);
$db->exec("INSERT INTO credit_notes (id, cn_number, invoice_id, currency, issue_date, status, subtotal, total) VALUES (91, 'CN-2026-0006', 7, 'USD', '2026-10-01', 'Issued', 200, 200)");
$db->exec("INSERT INTO invoice_payments (invoice_id, payment_date, amount, method, reference) VALUES (7, '2026-10-01', -200, 'Other', 'CN-2026-0006')");
$db->exec("INSERT INTO invoice_payments (id, invoice_id, payment_date, amount, method, reference) VALUES (600, 8, '2026-11-15', 200, 'Credit note', 'CN-2026-0006')");
$db->exec("INSERT INTO credit_note_allocations (credit_note_id, invoice_id, payment_id, amount_cn, amount_invoice, alloc_date) VALUES (91, 8, 600, 200, 200, '2026-11-15')");
$db->exec("UPDATE invoices SET amount_paid = 17860, balance_due = 200, status = 'Partially Paid' WHERE id = 7");
// a positive 'Other' payment with a CN reference: never touched
$db->exec("INSERT INTO invoice_payments (id, invoice_id, payment_date, amount, method, reference) VALUES (500, 3, '2026-10-02', 10, 'Other', 'CN-2026-0099')");
$m = cn_migrate_legacy_payments($db, false);
$by = array_column($m['rows'], null, 'invoice');
$t('migration dry run: Joyce Cleary 6,790 due → credit to client 6,790', isset($by['SE-2026-0280']) && $by['SE-2026-0280']['balance_before'] === 6790.0
   && $by['SE-2026-0280']['balance_after'] === -6790.0 && $by['SE-2026-0280']['credit_to_client_after'] === 6790.0
   && $by['SE-2026-0280']['status_after'] === 'Fully Paid' && $by['SE-2026-0280']['cn_available_after'] === 6790.0, $by['SE-2026-0280'] ?? $m);
$t('migration dry run: Nuova Era 200 due → 0 (credit used)', isset($by['SE-2026-0333']) && $by['SE-2026-0333']['balance_after'] === 0.0
   && $by['SE-2026-0333']['status_after'] === 'Fully Paid', $by['SE-2026-0333'] ?? $m);
$t('migration dry run: nothing changed', $inv(6)['status'] === 'Partially Paid' && (float)$inv(6)['balance_due'] == 6790.0);
$m = cn_migrate_legacy_payments($db, true);
$p = $db->query("SELECT cancelled_at, cancellation_reason FROM invoice_payments WHERE invoice_id = 6 AND amount < 0")->fetch();
$t('migration confirm: payments cancelled with the reason', $m['migrated'] === 2 && $p['cancelled_at'] !== null
   && strpos($p['cancellation_reason'], 'Migrated') === 0, $m);
$t('migration confirm: Joyce Cleary credit to client 6,790, CN remaining 6,790', (float)$inv(6)['balance_due'] == -6790.0
   && $inv(6)['status'] === 'Fully Paid' && cn_remaining_credit($db, 90) === 6790.0, $inv(6));
$t('migration confirm: Nuova Era Fully Paid, 0; CN Applied', (float)$inv(7)['balance_due'] == 0.0 && $inv(7)['status'] === 'Fully Paid'
   && $cnRow(91)['status'] === 'Applied', [$inv(7), $cnRow(91)]);
$t('migration: application-type payment untouched', $db->query("SELECT cancelled_at FROM invoice_payments WHERE id = 500")->fetchColumn() === null);
$t('migration: second run finds nothing', cn_migrate_legacy_payments($db, false)['rows'] === [], cn_migrate_legacy_payments($db, false));

// ── 6. Status rule ─────────────────────────────────────────────────────────────
$t('status: no payment, partial credit → stays New', inv_status_for('New', 0, 500, 100) === 'New');
$t('status: credit to client → Fully Paid', inv_status_for('Partially Paid', 7000, -6790, 6790) === 'Fully Paid');
$t('status: Cancelled stays', inv_status_for('Cancelled', 1000, 0, 0) === 'Cancelled');

echo $fail ? "\n$fail FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
