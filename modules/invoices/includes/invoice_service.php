<?php
/**
 * invoice_service.php — invoice logic shared by the Invoices pages (config.php
 * requires this file) and the Agent API (modules/leads/agent_api.php).
 *
 * No session, no auth, no db(): callers pass the PDO. Functions that touch
 * Dropbox load modules/leads/dropbox_helper.php themselves.
 */

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

// Folder status label (as shown in the Invoice page) => tag in the folder name.
const FOLDER_TAG_OPTIONS = [
    'PROGRESS'     => 'PROGRESS',
    'PROVISIONAL'  => 'PROVISIONAL',
    'DEPOSIT'      => 'DEPOSIT',
    'BALANCE'      => 'BALANCE',
    'BALANCE-CASH' => 'BALANCE-CASH',
    'FULLY PAID'   => 'PAID',
];

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

// ════════════════════════════════════════════════════════════════════════════
//  Invoice page actions (invoice_view.php + Agent API)
// ════════════════════════════════════════════════════════════════════════════

/** Invoice row (+ created_by_name) or null. */
function inv_get(PDO $db, int $id): ?array {
    $s = $db->prepare("SELECT i.*, u.full_name AS created_by_name FROM invoices i LEFT JOIN users u ON u.id=i.created_by WHERE i.id=?");
    $s->execute([$id]);
    $inv = $s->fetch(PDO::FETCH_ASSOC);
    return $inv ?: null;
}

function inv_items(PDO $db, int $id): array {
    $s = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY sort_order,id");
    $s->execute([$id]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

/** All payments (cancelled included), or only active ones. */
function inv_payments(PDO $db, int $id, bool $activeOnly = false): array {
    $s = $db->prepare("SELECT * FROM invoice_payments WHERE invoice_id=?" . ($activeOnly ? " AND cancelled_at IS NULL" : "") . " ORDER BY payment_date, id");
    $s->execute([$id]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function inv_credit_notes(PDO $db, int $id): array {
    $s = $db->prepare("SELECT * FROM credit_notes WHERE invoice_id=? ORDER BY issue_date, id");
    $s->execute([$id]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * An active payment that looks like $amount again: same amount and the same
 * reference (or, with no reference, the same date). Null when none.
 */
function inv_find_duplicate_payment(PDO $db, int $invId, float $amount, string $date, string $ref): ?array {
    $sql  = "SELECT * FROM invoice_payments WHERE invoice_id=? AND cancelled_at IS NULL AND ABS(amount - ?) < 0.005";
    $args = [$invId, round($amount, 2)];
    if ($ref !== '') { $sql .= " AND reference = ?";    $args[] = $ref; }
    else             { $sql .= " AND payment_date = ?"; $args[] = $date; }
    $s = $db->prepare($sql . " ORDER BY id LIMIT 1");
    $s->execute($args);
    $p = $s->fetch(PDO::FETCH_ASSOC);
    return $p ?: null;
}

/**
 * Record a payment, then recalculate the invoice and the request value, and
 * close the Memo Board follow-ups waiting for it ($closedMemos receives them).
 * Returns the new payment id; InvalidArgumentException on bad input.
 */
function inv_add_payment(PDO $db, int $invId, string $date, float $amount, string $method, string $ref = '', string $notes = '', ?array &$closedMemos = null): int {
    if ($amount <= 0) throw new InvalidArgumentException('Amount must be greater than zero.');
    if (!in_array($method, INV_METHODS, true)) throw new InvalidArgumentException('Invalid payment method.');
    $db->prepare("INSERT INTO invoice_payments (invoice_id,payment_date,amount,method,reference,notes) VALUES (?,?,?,?,?,?)")
       ->execute([$invId, $date, $amount, $method, $ref !== '' ? $ref : null, $notes !== '' ? $notes : null]);
    $pid = (int)$db->lastInsertId();
    recalculate_invoice($db, $invId);
    sync_request_value($db, $invId);

    // A memo problem must never undo / fail the payment.
    $closedMemos = [];
    try {
        require_once __DIR__ . '/../../memo/memo_lib.php';
        $cur = $db->prepare("SELECT currency FROM invoices WHERE id=?");
        $cur->execute([$invId]);
        $closedMemos = memo_on_payment($db, $invId, $amount, (string)$cur->fetchColumn(), $date, $ref);
    } catch (\Throwable $e) {
        error_log('memo_on_payment failed for invoice ' . $invId . ': ' . $e->getMessage());
    }
    return $pid;
}

/** Cancel a payment (kept for history) and recalculate. False if nothing matched. */
function inv_cancel_payment(PDO $db, int $paymentId, int $invId, string $reason): bool {
    $st = $db->prepare("UPDATE invoice_payments SET cancelled_at=NOW(), cancellation_reason=? WHERE id=? AND invoice_id=?");
    $st->execute([$reason, $paymentId, $invId]);
    recalculate_invoice($db, $invId);
    sync_request_value($db, $invId);
    return $st->rowCount() > 0;
}

// ── Create / edit (invoice_add.php, invoice_edit.php, Agent API) ─────────────
/**
 * Invoice header + lines from input $in, ready for inv_create() / inv_update().
 * $cur = the invoice being edited (keys missing from $in keep its values), null for a new one.
 * Lines: $in['items'] = [{description, quantity?, unit_price}] — required for a new invoice,
 * optional on edit (absent = unchanged → 'items' null).
 * Returns ['fields' => [...], 'items' => ?[...], 'errors' => [...]].
 */
function inv_prepare(array $in, ?array $cur = null): array {
    $get = function (string $k, $def) use ($in, $cur) {
        if (array_key_exists($k, $in)) return $in[$k];
        return $cur !== null && array_key_exists($k, $cur) ? $cur[$k] : $def;
    };
    $str = function (string $k, $def) use ($get) { return trim((string)$get($k, $def)); };
    $f = [
        'issuer'           => $str('issuer', INV_ISSUERS[0]),
        'currency'         => strtoupper($str('currency', 'USD')),
        'bill_to_name'     => $str('bill_to_name', ''),
        'bill_to_address'  => $str('bill_to_address', '') ?: null,
        'customer_id'      => (int)$get('customer_id', 0) ?: null,
        'request_id'       => (int)$get('request_id', 0) ?: null,
        'issue_date'       => $str('issue_date', date('Y-m-d')),
        'due_date'         => $str('due_date', '') ?: null,
        'terms'            => $str('terms', 'Due on Receipt'),
        'notes'            => $str('notes', INV_DEFAULT_NOTES) ?: null,
        'terms_conditions' => $str('terms_conditions', INV_DEFAULT_TC) ?: null,
        'follow_up'        => !empty($get('follow_up', 0)) ? 1 : 0,
    ];
    $note = $f['follow_up'] ? mb_substr($str('follow_up_note', ''), 0, 255) : '';
    $f['follow_up_note'] = $note !== '' ? $note : null;

    $errors = [];
    if ($f['bill_to_name'] === '')                       $errors[] = 'Bill To name is required.';
    if (!in_array($f['issuer'], INV_ISSUERS, true))      $errors[] = 'Invalid issuer (' . implode(' / ', INV_ISSUERS) . ').';
    if (!in_array($f['currency'], INV_CURRENCIES, true)) $errors[] = 'Invalid currency (' . implode(' / ', INV_CURRENCIES) . ').';
    foreach (['issue_date', 'due_date'] as $k) {
        if ($f[$k] !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k]) || !strtotime($f[$k]))) $errors[] = $k . ' must be YYYY-MM-DD.';
    }

    $items = null;
    if ($cur === null || array_key_exists('items', $in)) {
        $items = [];
        foreach ((array)($in['items'] ?? []) as $it) {
            $desc = trim((string)($it['description'] ?? ''));
            if ($desc === '') continue;
            $qty = (float)($it['quantity'] ?? 1);
            if ($qty == (int)$qty) $qty = (int)$qty;
            $price = (float)($it['unit_price'] ?? 0);
            $items[] = ['description' => $desc, 'quantity' => $qty, 'unit_price' => $price, 'line_total' => round($qty * $price, 2)];
        }
        if (!$items) $errors[] = 'At least one item is required.';
    }
    return ['fields' => $f, 'items' => $items, 'errors' => $errors];
}

/** Sum of prepared lines. */
function inv_items_total(array $items): float {
    $s = 0.0;
    foreach ($items as $it) $s += (float)$it['line_total'];
    return round($s, 2);
}

function inv_write_items(PDO $db, int $invId, array $items): void {
    $db->prepare("DELETE FROM invoice_items WHERE invoice_id=?")->execute([$invId]);
    $st = $db->prepare("INSERT INTO invoice_items (invoice_id,sort_order,description,quantity,unit_price,line_total) VALUES (?,?,?,?,?,?)");
    foreach (array_values($items) as $i => $it) {
        $st->execute([$invId, $i, $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);
    }
}

/** New invoice (status New) from inv_prepare() output; numbered SE-/SH-YYYY-NNNN. Returns [id, number]. */
function inv_create(PDO $db, array $f, array $items, ?int $createdBy): array {
    $db->beginTransaction();
    try {
        $num = generate_invoice_number($db, $f['issuer']);
        $db->prepare("INSERT INTO invoices
            (invoice_number, request_id, customer_id, bill_to_name, bill_to_address,
             issuer, currency, issue_date, due_date, terms, notes, terms_conditions,
             status, follow_up, follow_up_note, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'New',?,?,?)")
           ->execute([$num, $f['request_id'], $f['customer_id'], $f['bill_to_name'], $f['bill_to_address'],
                      $f['issuer'], $f['currency'], $f['issue_date'], $f['due_date'], $f['terms'],
                      $f['notes'], $f['terms_conditions'], $f['follow_up'], $f['follow_up_note'], $createdBy]);
        $id = (int)$db->lastInsertId();
        inv_write_items($db, $id, $items);
        recalculate_invoice($db, $id);
        sync_request_value($db, $id);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return ['invoice_id' => $id, 'invoice_number' => $num];
}

/** Save an edited invoice; $items null = lines unchanged. Recalculates the invoice and the request value(s). */
function inv_update(PDO $db, int $id, array $f, ?array $items): void {
    $s = $db->prepare("SELECT request_id FROM invoices WHERE id=?");
    $s->execute([$id]);
    $oldReq = (int)$s->fetchColumn();
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE invoices SET
            customer_id=?, request_id=?, bill_to_name=?, bill_to_address=?, issuer=?, currency=?,
            issue_date=?, due_date=?, terms=?, notes=?, terms_conditions=?, follow_up=?, follow_up_note=?, updated_at=NOW()
            WHERE id=?")
           ->execute([$f['customer_id'], $f['request_id'], $f['bill_to_name'], $f['bill_to_address'], $f['issuer'], $f['currency'],
                      $f['issue_date'], $f['due_date'], $f['terms'], $f['notes'], $f['terms_conditions'],
                      $f['follow_up'], $f['follow_up_note'], $id]);
        if ($items !== null) inv_write_items($db, $id, $items);
        recalculate_invoice($db, $id);
        sync_request_value($db, $id);
        if ($oldReq && $oldReq !== (int)$f['request_id']) sync_request_value($db, 0, $oldReq);   // unlinked request
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

// ── Dropbox folder status (…_DEPOSIT / _BALANCE / _PAID …) ───────────────────
function folder_current_tag(string $name): string {
    // If the folder ends with _CK, look at the status tag that precedes it
    if (str_ends_with($name, '_CK')) $name = substr($name, 0, -3);
    foreach (['BALANCE-CASH','BALANCE','DEPOSIT','PROGRESS','PROVISIONAL','PAID','CK','CANCELLED','BOOKED'] as $tag) {
        if (str_ends_with($name, '_'.$tag)) return $tag;
    }
    return '';
}
function folder_strip_tag(string $name): string {
    foreach (['_BALANCE-CASH','_BALANCE','_DEPOSIT','_PROGRESS','_PROVISIONAL','_PAID','_CK','_CANCELLED','_BOOKED'] as $tag) {
        if (str_ends_with($name, $tag)) return substr($name, 0, -strlen($tag));
    }
    return $name;
}

/** Folder name with its status tag replaced by $label's tag; _CK stays last. */
function inv_folder_new_name(string $oldName, string $label): string {
    // Preserve _CK suffix: strip it before removing the status tag,
    // then re-append it after the new status tag (e.g. _DEPOSIT_CK → _BALANCE_CK)
    $hasCK    = str_ends_with($oldName, '_CK');
    $baseName = folder_strip_tag($hasCK ? substr($oldName, 0, -3) : $oldName);
    return $baseName . '_' . FOLDER_TAG_OPTIONS[$label] . ($hasCK ? '_CK' : '');
}

/** The request linked to an invoice (id, practice_code, dropbox_url, status, group_folder…) or null. */
function inv_linked_request(PDO $db, int $invId): ?array {
    $s = $db->prepare("SELECT r.* FROM invoices i JOIN requests r ON r.id = i.request_id WHERE i.id = ?");
    $s->execute([$invId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/**
 * Rename the invoice's Dropbox folder to the status $label (a FOLDER_TAG_OPTIONS
 * key), update practice_code / dropbox_url / payment_status, and re-tag the GRP
 * parent. Returns [new_name, new_tag, new_url, group_msg]; RuntimeException on error.
 */
function inv_update_folder_status(PDO $db, int $invId, string $newLabel): array {
    if (!array_key_exists($newLabel, FOLDER_TAG_OPTIONS)) throw new InvalidArgumentException('Invalid status selected.');
    $dbTag = FOLDER_TAG_OPTIONS[$newLabel];
    $req   = inv_linked_request($db, $invId);
    if (!$req || !$req['practice_code']) throw new RuntimeException('No linked Dropbox folder found.');

    $oldName = $req['practice_code'];
    $newName = inv_folder_new_name($oldName, $newLabel);
    if ($newName === $oldName) {
        return ['new_name' => $newName, 'new_tag' => $dbTag, 'new_url' => (string)($req['dropbox_url'] ?? ''), 'group_msg' => '', 'unchanged' => true];
    }

    // ── Rename folder in Dropbox ───────────────────────────────────
    $dropboxPrefix = 'https://www.dropbox.com/home';
    $oldUrl        = $req['dropbox_url'] ?? '';
    $fromPath = $toPath = '';

    if (str_starts_with($oldUrl, $dropboxPrefix)) {
        $apiPath   = urldecode(substr($oldUrl, strlen($dropboxPrefix)));
        $lastSlash = strrpos($apiPath, '/');
        if ($lastSlash !== false) {
            $parentPath = substr($apiPath, 0, $lastSlash);
            $fromPath   = $apiPath;
            $toPath     = $parentPath . '/' . $newName;
        }
    }
    if ($fromPath === '') {
        $parentPath = ($req['status'] === 'Booked') ? '/001_Safari' : '/2026';
        $fromPath   = $parentPath . '/' . $oldName;
        $toPath     = $parentPath . '/' . $newName;
    }

    require_once __DIR__ . '/../../leads/dropbox_constants.php';
    require_once __DIR__ . '/../../leads/dropbox_helper.php';
    $token = dropbox_get_access_token();

    // Resolve the real Dropbox path by searching for the folder name,
    // because the stored dropbox_url may have wrong case or subfolder.
    $realFromPath = dropbox_find_folder($token, $oldName);
    if ($realFromPath !== null) {
        // Derive toPath from real parent
        $realParent = substr($realFromPath, 0, strrpos($realFromPath, '/'));
        $toPath     = $realParent . '/' . $newName;
        $fromPath   = $realFromPath;
    }

    try {
        dropbox_move_folder($token, $fromPath, $toPath);
    } catch (\Throwable $dbx) {
        // If folder not found, the stored dropbox_url parent is stale (e.g. folder was
        // confirmed into 001_Safari but DB still says /2026/).
        // Retry by swapping /2026/ ↔ /001_Safari/ before giving up.
        $retried = false;
        if (str_contains($dbx->getMessage(), 'not_found')) {
            $altFrom = null;
            if (str_starts_with($fromPath, '/2026/')) {
                $altFrom = '/001_Safari/' . $oldName;
                $altTo   = '/001_Safari/' . $newName;
            } elseif (str_starts_with($fromPath, '/001_Safari/')) {
                $altFrom = '/2026/' . $oldName;
                $altTo   = '/2026/'  . $newName;
            }
            if ($altFrom !== null) {
                try {
                    dropbox_move_folder($token, $altFrom, $altTo);
                    // Use the alternate paths for the URL rebuild below
                    $fromPath = $altFrom;
                    $toPath   = $altTo;
                    $retried  = true;
                } catch (\Throwable $ignored) {}
            }
        }
        if (!$retried) {
            throw new RuntimeException($dbx->getMessage() . ' | from: ' . $fromPath . ' | to: ' . $toPath);
        }
    }

    // ── Update DB ──────────────────────────────────────────────────
    // Rebuild dropbox_url from the path that actually succeeded ($toPath),
    // so stale /2026/ URLs get corrected to /001_Safari/ after a retry.
    $newUrl = 'https://www.dropbox.com/home' . $toPath;

    // Derive payment_status from the new folder tag
    $psMap = [
        'DEPOSIT'      => 'Deposit',
        'BALANCE'      => 'Balance',
        'BALANCE-CASH' => 'Balance-Cash',
        'PAID'         => 'Paid',
        'PROGRESS'     => null,
        'PROVISIONAL'  => null,
    ];
    $newPs = array_key_exists($dbTag, $psMap) ? $psMap[$dbTag] : false;

    if ($newPs !== false) {
        $db->prepare("UPDATE requests SET practice_code=?, dropbox_url=?, payment_status=? WHERE id=?")
           ->execute([$newName, $newUrl, $newPs, (int)$req['id']]);
    } else {
        $db->prepare("UPDATE requests SET practice_code=?, dropbox_url=? WHERE id=?")
           ->execute([$newName, $newUrl, (int)$req['id']]);
    }

    // GRP client: the parent folder's tag follows the client furthest
    // behind with payments (includes/grp_status.php).
    $groupMsg = '';
    if (trim($req['group_folder'] ?? '') !== '') {
        require_once __DIR__ . '/../../leads/includes/grp_status.php';
        try {
            $groupMsg = grp_sync_parent_tag($db, $token, $req['group_folder'])['msg'];
        } catch (\Throwable $g) {
            $groupMsg = 'Group folder not updated: ' . $g->getMessage();
        }
    }

    return ['new_name' => $newName, 'new_tag' => $dbTag, 'new_url' => $newUrl, 'group_msg' => $groupMsg, 'unchanged' => false];
}

// ── PDF (Dompdf, same layout as the emailed / zipped invoices) ───────────────
function inv_pdf(array $inv, array $items, array $activePayments): string {
    $auto = __DIR__ . '/../../../vendor/autoload.php';
    if (!class_exists('\Dompdf\Dompdf') && is_file($auto)) require_once $auto;
    if (!class_exists('\Dompdf\Dompdf')) throw new RuntimeException('PDF library not installed. Run: composer install');
    require_once __DIR__ . '/invoice_html.php';

    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);   // no external resources
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml(buildInvoiceHtml($inv, $items, $activePayments));
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

/** "Invoice SE-2026-0012.pdf" — the name check_dropbox.php looks for. */
function inv_pdf_dropbox_name(array $inv): string {
    return 'Invoice ' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$inv['invoice_number']) . '.pdf';
}

// ── Import an existing (Zoho / PDF) invoice ──────────────────────────────────
/**
 * Create an invoice + items + optional payment from the importer body
 * (see api_import.php for the fields). InvalidArgumentException on bad input,
 * DomainException when the invoice number already exists.
 * Returns [invoice_id, invoice_number].
 */
function inv_import(PDO $db, array $body, ?int $createdBy = null): array {
    $errors = [];
    $billToName = trim($body['bill_to_name'] ?? '');
    $issuer     = $body['issuer'] ?? INV_ISSUERS[0];
    $currency   = $body['currency'] ?? 'USD';
    $issueDate  = $body['issue_date'] ?? '';

    if (!$billToName)                       $errors[] = 'bill_to_name is required.';
    if (!in_array($issuer, INV_ISSUERS))    $errors[] = 'Invalid issuer.';
    if (!in_array($currency, INV_CURRENCIES)) $errors[] = 'Invalid currency.';
    if (!$issueDate)                        $errors[] = 'issue_date is required.';

    $items = $body['items'] ?? [];
    if (empty($items))                      $errors[] = 'At least one item is required.';
    if ($errors) throw new InvalidArgumentException(implode(' ', $errors));

    // ── Build invoice number ──────────────────────────────────────────────────
    $numberMode = $body['invoice_number_mode'] ?? 'original';
    $invNum = $numberMode === 'generate' ? '' : trim($body['invoice_number'] ?? '');
    if ($invNum !== '') {
        $s = $db->prepare("SELECT id FROM invoices WHERE invoice_number = ? LIMIT 1");
        $s->execute([$invNum]);
        $dupId = (int)$s->fetchColumn();
        if ($dupId) throw new DomainException("Invoice $invNum already exists in Hub (id $dupId).");
    }

    $db->beginTransaction();
    try {
        if ($invNum === '') $invNum = generate_invoice_number($db, $issuer);
        $reqId      = (int)($body['request_id']       ?? 0) ?: null;
        $customerId = (int)($body['bill_to_source_id'] ?? 0) ?: null;
        // If bill_to is an agency, customer_id stays null (agencies are not in customers table)
        if (($body['bill_to_source_type'] ?? '') === 'agency') {
            $customerId = null;
        }

        $db->prepare("
            INSERT INTO invoices
                (invoice_number, request_id, customer_id, bill_to_name, bill_to_address,
                 issuer, currency, issue_date, due_date, terms, notes, terms_conditions,
                 status, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'New',?)
        ")->execute([
            $invNum,
            $reqId,
            $customerId,
            $billToName,
            ($body['bill_to_address'] ?? '') ?: null,
            $issuer,
            $currency,
            $issueDate,
            ($body['due_date'] ?? '') ?: null,
            $body['terms'] ?? 'Due on Receipt',
            ($body['notes'] ?? '') ?: INV_DEFAULT_NOTES,
            ($body['terms_conditions'] ?? '') ?: INV_DEFAULT_TC,
            $createdBy,
        ]);
        $invId = (int)$db->lastInsertId();

        // ── Line items ────────────────────────────────────────────────────────
        $sort = 0;
        $itemStmt = $db->prepare("
            INSERT INTO invoice_items (invoice_id, sort_order, description, quantity, unit_price, line_total)
            VALUES (?,?,?,?,?,?)
        ");
        foreach ($items as $item) {
            $desc  = trim($item['description'] ?? '');
            if (!$desc) continue;
            $qty   = round((float)($item['quantity']   ?? 1), 2);
            $price = round((float)($item['unit_price'] ?? 0), 2);
            $total = round((float)($item['line_total'] ?? $qty * $price), 2);
            $itemStmt->execute([$invId, $sort++, $desc, $qty, $price, $total]);
        }

        // ── Optional payment ──────────────────────────────────────────────────
        $payAmount = round((float)($body['payment_amount'] ?? 0), 2);
        if ($payAmount > 0) {
            $payMethod = $body['payment_method'] ?? 'Bank Transfer';
            if (!in_array($payMethod, INV_METHODS)) $payMethod = 'Bank Transfer';
            $db->prepare("
                INSERT INTO invoice_payments (invoice_id, payment_date, amount, method, reference, notes)
                VALUES (?,?,?,?,?,?)
            ")->execute([
                $invId,
                ($body['payment_date'] ?? '') ?: $issueDate,
                $payAmount,
                $payMethod,
                ($body['payment_reference'] ?? '') ?: null,
                'Imported from Zoho / PDF',
            ]);
        }

        // ── Recalculate totals & sync request ─────────────────────────────────
        recalculate_invoice($db, $invId);
        if ($reqId) sync_request_value($db, $invId);

        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return ['invoice_id' => $invId, 'invoice_number' => $invNum];
}
