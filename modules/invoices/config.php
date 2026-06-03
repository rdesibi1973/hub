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

const CN_STATUSES = [
    'Issued'    => 'inv-paid',
    'Cancelled' => 'inv-cancelled',
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

    // $paid is the NET of payments minus refunds (refunds are stored as
    // negative invoice_payments rows linked to credit notes).
    $newStatus = $cur;
    if ($cur !== 'Cancelled') {
        if ($paid > 0.001) {
            $newStatus = ($balance <= 0.001) ? 'Fully Paid' : 'Partially Paid';
        } elseif (in_array($cur, ['Partially Paid', 'Fully Paid'])) {
            // all positive payments cancelled, or fully refunded back to <= 0
            $newStatus = 'New';
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
            ) - (
                SELECT COALESCE(SUM(total), 0)
                FROM credit_notes
                WHERE request_id = ? AND status != 'Cancelled'
            )
         WHERE id = ?"
    )->execute([$request_id, $request_id, $request_id]);
}

// ── Credit note number: CN-2026-0001 ─────────────────────────────────────────
function generate_cn_number(PDO $db): string {
    $year = date('Y');
    $stmt = $db->prepare(
        "SELECT MAX(CAST(SUBSTRING_INDEX(cn_number, '-', -1) AS UNSIGNED))
         FROM credit_notes WHERE cn_number LIKE ?"
    );
    $stmt->execute(["CN-$year-%"]);
    $max = (int)$stmt->fetchColumn();
    return sprintf('CN-%d-%04d', $year, $max + 1);
}

// ── Recalculate credit note totals from its items ────────────────────────────
function recalculate_credit_note(PDO $db, int $id): void {
    $stmt = $db->prepare("SELECT COALESCE(SUM(line_total),0) FROM credit_note_items WHERE credit_note_id=?");
    $stmt->execute([$id]);
    $subtotal = round((float)$stmt->fetchColumn(), 2);
    $db->prepare("UPDATE credit_notes SET subtotal=?, total=?, updated_at=NOW() WHERE id=?")
       ->execute([$subtotal, $subtotal, $id]);
}

// ── Sync the linked negative invoice payment to match the CN total ───────────
// A credit note records a refund against its invoice. We mirror the CN total
// as a NEGATIVE invoice_payments row so the invoice balance recalculates
// through the existing payment machinery. Returns the payment id.
function sync_cn_invoice_payment(PDO $db, int $cnId): void {
    $cn = $db->prepare("SELECT * FROM credit_notes WHERE id=?");
    $cn->execute([$cnId]);
    $cn = $cn->fetch();
    if (!$cn) return;

    $invoiceId = (int)$cn['invoice_id'];
    $payId     = (int)($cn['payment_id'] ?? 0);
    // Refund is the negative of the CN total (CN totals are stored positive).
    $refund    = -1 * abs((float)$cn['total']);
    $ref       = $cn['cn_number'];
    $note      = 'Credit note ' . $cn['cn_number'] . ($cn['reason'] ? ' — ' . $cn['reason'] : '');

    if ($cn['status'] === 'Cancelled') {
        // Cancel the linked payment so it no longer affects the invoice.
        if ($payId) {
            $db->prepare("UPDATE invoice_payments SET cancelled_at=NOW(), cancellation_reason=? WHERE id=? AND cancelled_at IS NULL")
               ->execute(['Credit note cancelled', $payId]);
        }
    } elseif ($payId) {
        // Update existing payment (un-cancel if it was cancelled, refresh amount).
        $db->prepare("UPDATE invoice_payments SET payment_date=?, amount=?, reference=?, notes=?, cancelled_at=NULL, cancellation_reason=NULL WHERE id=?")
           ->execute([$cn['issue_date'], $refund, $ref, $note, $payId]);
    } else {
        // Create the linked negative payment.
        $db->prepare("INSERT INTO invoice_payments (invoice_id,payment_date,amount,method,reference,notes) VALUES (?,?,?,?,?,?)")
           ->execute([$invoiceId, $cn['issue_date'], $refund, 'Other', $ref, $note]);
        $payId = (int)$db->lastInsertId();
        $db->prepare("UPDATE credit_notes SET payment_id=? WHERE id=?")->execute([$payId, $cnId]);
    }

    recalculate_invoice($db, $invoiceId);
    sync_request_value($db, $invoiceId);
}

// ── Format monetary amount ────────────────────────────────────────────────────
function fmt_money(float $amount, string $currency): string {
    $sym = ($currency === 'EUR') ? '€' : '$';
    return $sym . number_format($amount, 2);
}

// ── Credit note: amount already allocated (in CN currency) ───────────────────
// Sums non-cancelled allocations. The CN "remaining credit" is total minus this.
function cn_allocated_amount(PDO $db, int $cnId): float {
    $s = $db->prepare("SELECT COALESCE(SUM(amount_cn),0) FROM credit_note_allocations WHERE credit_note_id=? AND cancelled_at IS NULL");
    $s->execute([$cnId]);
    return round((float)$s->fetchColumn(), 2);
}

function cn_remaining_credit(PDO $db, int $cnId): float {
    $s = $db->prepare("SELECT total FROM credit_notes WHERE id=?");
    $s->execute([$cnId]);
    $total = (float)$s->fetchColumn();
    return round($total - cn_allocated_amount($db, $cnId), 2);
}

// ── Apply part of a credit note's credit to another invoice ──────────────────
// $amountCn   : amount to deduct from the CN, in the CN's currency
// $fxRate     : CN currency -> invoice currency (1 when same currency)
// The invoice amount = floor(amountCn * fxRate) to the unit, then recorded as a
// POSITIVE payment on the target invoice (method "Other", reference = CN number).
// Returns the new allocation id. Throws on validation failure.
function cn_apply_to_invoice(
    PDO $db, int $cnId, int $targetInvoiceId,
    float $amountCn, float $fxRate, string $allocDate, ?string $note, int $uid
): int {
    $cn = $db->prepare("SELECT * FROM credit_notes WHERE id=?");
    $cn->execute([$cnId]);
    $cn = $cn->fetch();
    if (!$cn) throw new \RuntimeException('Credit note not found.');
    if ($cn['status'] !== 'Issued') throw new \RuntimeException('Credit note is not active.');

    $inv = $db->prepare("SELECT * FROM invoices WHERE id=?");
    $inv->execute([$targetInvoiceId]);
    $inv = $inv->fetch();
    if (!$inv) throw new \RuntimeException('Target invoice not found.');
    if ($inv['status'] === 'Cancelled') throw new \RuntimeException('Cannot apply credit to a cancelled invoice.');

    $amountCn = round($amountCn, 2);
    if ($amountCn <= 0) throw new \RuntimeException('Amount must be greater than zero.');

    $remaining = cn_remaining_credit($db, $cnId);
    if ($amountCn > $remaining + 0.001) {
        throw new \RuntimeException('Amount exceeds remaining credit (' . fmt_money($remaining, $cn['currency']) . ').');
    }

    if ($fxRate <= 0) $fxRate = 1.0;
    // Floor to the unit in the invoice currency.
    $amountInvoice = floor($amountCn * $fxRate);
    if ($amountInvoice <= 0) throw new \RuntimeException('Resulting invoice amount is zero after rounding.');

    $payNote = 'Credit note ' . $cn['cn_number']
             . ' (' . fmt_money($amountCn, $cn['currency']) . ' @ ' . rtrim(rtrim(number_format($fxRate, 6, '.', ''), '0'), '.') . ')'
             . ($note ? ' — ' . $note : '');

    $db->beginTransaction();
    try {
        // Positive payment on the target invoice, in invoice currency.
        $db->prepare("INSERT INTO invoice_payments (invoice_id,payment_date,amount,method,reference,notes) VALUES (?,?,?,?,?,?)")
           ->execute([$targetInvoiceId, $allocDate, $amountInvoice, 'Other', $cn['cn_number'], $payNote]);
        $payId = (int)$db->lastInsertId();

        $db->prepare("INSERT INTO credit_note_allocations
            (credit_note_id, invoice_id, payment_id, amount_cn, amount_invoice, fx_rate, alloc_date, notes, created_by)
            VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$cnId, $targetInvoiceId, $payId, $amountCn, $amountInvoice, $fxRate, $allocDate, $note ?: null, $uid]);
        $allocId = (int)$db->lastInsertId();

        recalculate_invoice($db, $targetInvoiceId);
        sync_request_value($db, $targetInvoiceId);

        $db->commit();
        return $allocId;
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// ── Cancel an allocation: removes the linked payment, frees the credit ───────
function cn_cancel_allocation(PDO $db, int $allocId): void {
    $a = $db->prepare("SELECT * FROM credit_note_allocations WHERE id=? AND cancelled_at IS NULL");
    $a->execute([$allocId]);
    $a = $a->fetch();
    if (!$a) return;

    $db->beginTransaction();
    try {
        if ($a['payment_id']) {
            $db->prepare("UPDATE invoice_payments SET cancelled_at=NOW(), cancellation_reason=? WHERE id=? AND cancelled_at IS NULL")
               ->execute(['Credit note allocation cancelled', (int)$a['payment_id']]);
        }
        $db->prepare("UPDATE credit_note_allocations SET cancelled_at=NOW() WHERE id=?")->execute([$allocId]);

        recalculate_invoice($db, (int)$a['invoice_id']);
        sync_request_value($db, (int)$a['invoice_id']);

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
