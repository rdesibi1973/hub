<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

function db(): PDO { global $pdo; return $pdo; }

function requireInvoiceAccess(): void { require_permission('invoices'); }

function isInvoiceAdmin(): bool {
    return in_array(current_user()['role_name'] ?? '', ['admin', 'manager']);
}

if (!function_exists('flash')) {
    function flash(string $msg, string $type = 'success'): void {
        start_session();
        $_SESSION['flash'][] = ['type' => $type, 'message' => $msg];
    }
}
if (!function_exists('getFlash')) {
    function getFlash(): ?array {
        start_session();
        if (!empty($_SESSION['flash'])) {
            $f = $_SESSION['flash'][0]; array_shift($_SESSION['flash']);
            return ['msg' => $f['message'], 'type' => $f['type']];
        }
        return null;
    }
}

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

const INV_STATUSES = [
    'New'            => 'inv-draft',
    'Partially Paid' => 'inv-partial',
    'Fully Paid'     => 'inv-paid',
    'Cancelled'      => 'inv-cancelled',
];

const INV_CURRENCIES  = ['USD', 'EUR'];
const INV_ISSUERS     = ['Savannah Explorers Ltd', 'Savannah Holidays Ltd'];
const INV_METHODS     = ['Bank Transfer', 'Credit Card', 'Cash', 'Other'];
const INV_TERMS_OPTS  = ['Due on Receipt', 'Net 7', 'Net 15', 'Net 30', 'Net 60'];

const INV_DEFAULT_TC    = '30% deposit at the time of booking, balance within 60 days before the trip starts';
const INV_AGENCY_TC     = '30% deposit at the time of booking, balance within 45 days before the trip starts';
const INV_DEFAULT_NOTES = 'Thanks for your business.';

// ── Invoice number: SE-2026-0001 / SH-2026-0001 ──────────────────────────────
function generate_invoice_number(PDO $db, string $issuer): string {
    $prefix = ($issuer === 'Savannah Explorers Ltd') ? 'SE' : 'SH';
    $year   = date('Y');
    $stmt   = $db->prepare(
        "SELECT MAX(CAST(SUBSTRING_INDEX(invoice_number, '-', -1) AS UNSIGNED))
         FROM invoices WHERE invoice_number LIKE ?"
    );
    $stmt->execute(["$prefix-$year-%"]);
    $max = (int)$stmt->fetchColumn();
    return sprintf('%s-%d-%04d', $prefix, $year, $max + 1);
}

// ── Recalculate totals + auto-update status ───────────────────────────────────
function recalculate_invoice(PDO $db, int $id): void {
    $stmt = $db->prepare("SELECT COALESCE(SUM(line_total),0) FROM invoice_items WHERE invoice_id=?");
    $stmt->execute([$id]);
    $subtotal = (float)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE invoice_id=? AND cancelled_at IS NULL");
    $stmt->execute([$id]);
    $paid = (float)$stmt->fetchColumn();

    $balance = round($subtotal - $paid, 2);

    $stmt = $db->prepare("SELECT status FROM invoices WHERE id=?");
    $stmt->execute([$id]);
    $cur = (string)$stmt->fetchColumn();

    $newStatus = $cur;
    if ($cur !== 'Cancelled') {
        if ($paid > 0.001) {
            $newStatus = ($balance <= 0.001) ? 'Fully Paid' : 'Partially Paid';
        } elseif (in_array($cur, ['Partially Paid', 'Fully Paid'])) {
            $newStatus = 'New'; // all payments were cancelled
        }
    }

    $db->prepare("UPDATE invoices SET subtotal=?,total=?,amount_paid=?,balance_due=?,status=?,updated_at=NOW() WHERE id=?")
       ->execute([round($subtotal,2), round($subtotal,2), round($paid,2), $balance, $newStatus, $id]);
}

// ── Sync request value_usd from linked invoice totals ────────────────────────
// Sums the `total` of all non-Cancelled invoices linked to the same request
// and writes the result to requests.value_usd.
// Pass either the invoice id (most callers) or 0 with an explicit $requestId.
function sync_request_value(PDO $db, int $invoice_id, int $request_id = 0): void {
    if (!$request_id) {
        $s = $db->prepare("SELECT request_id FROM invoices WHERE id = ?");
        $s->execute([$invoice_id]);
        $request_id = (int)$s->fetchColumn();
    }
    if (!$request_id) return;

    $db->prepare(
        "UPDATE requests
            SET value_usd = (
                SELECT COALESCE(SUM(total), 0)
                FROM invoices
                WHERE request_id = ? AND status != 'Cancelled'
            )
         WHERE id = ?"
    )->execute([$request_id, $request_id]);
}

// ── Format monetary amount ────────────────────────────────────────────────────
function fmt_money(float $amount, string $currency): string {
    $sym = ($currency === 'EUR') ? '€' : '$';
    return $sym . number_format($amount, 2);
}
