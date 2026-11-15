<?php
/**
 * invoice_service.php — invoice logic shared by the Invoices pages (config.php
 * requires this file) and the Agent API (modules/leads/agent_api.php).
 *
 * No session, no auth, no db(): callers pass the PDO. Functions that touch
 * Dropbox load modules/leads/dropbox_helper.php themselves.
 * Writes on request-linked invoices are logged in the request timeline (tl_log_invoice).
 */

require_once __DIR__ . '/../../leads/includes/timeline_service.php';

const INV_STATUSES = [
    'New'            => 'inv-draft',
    'Partially Paid' => 'inv-partial',
    'Fully Paid'     => 'inv-paid',
    'Cancelled'      => 'inv-cancelled',
];

const CN_STATUSES = [
    'Issued'            => 'inv-paid',
    'Partially applied' => 'inv-partial',
    'Applied'           => 'inv-draft',
    'Cancelled'         => 'inv-cancelled',
];

const INV_CURRENCIES  = ['USD', 'EUR'];
const INV_ISSUERS     = ['Savannah Explorers Ltd', 'Savannah Holidays Ltd'];
const INV_METHODS     = ['Bank Transfer', 'Credit Card', 'Cash', 'Other'];
// Only for payments made by applying a credit note: not a choice in INV_METHODS.
const INV_METHOD_CREDIT_NOTE = 'Credit note';
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
// Credit notes (since 15 Nov 2026 never a negative payment). Per invoice:
//   T = total, P = active payments, C = active credit notes issued on it,
//   U = part of those credit notes already used (applied to other invoices + refunds paid).
//   net_due = T − C (also the revenue)      balance = net_due − P + U
//   balance > 0 still to collect · = 0 Fully Paid · < 0 credit to client of −balance.
// balance_due is stored SIGNED (as overpaid invoices always were): readers that add up what is
// still to collect take GREATEST(balance_due, 0); inv_credit_to_client() gives the credit.
function recalculate_invoice(PDO $db, int $id): void {
    $f = inv_figures($db, $id);
    $db->prepare("UPDATE invoices SET subtotal=?,total=?,amount_paid=?,balance_due=?,status=?,updated_at=NOW() WHERE id=?")
       ->execute([$f['total'], $f['total'], $f['paid'], $f['balance'], $f['status'], $id]);
    if ($f['credited'] > 0) inv_refresh_cn_statuses($db, $id);
}

/**
 * T, P, C, U of invoice $id from the live rows (see recalculate_invoice()), with balance, credit_to_client
 * and the status they give. $paidDelta / $creditDelta: what-if amounts (dry runs, migration).
 */
function inv_figures(PDO $db, int $id, float $paidDelta = 0.0, float $creditDelta = 0.0): array {
    $raw      = inv_figures_raw($db, $id);
    $total    = $raw['total'];
    $paid     = round($raw['paid'] + $paidDelta, 2);
    $credited = round($raw['credited'] + $creditDelta, 2);
    $used     = inv_cn_used_total($db, $id);
    $balance  = inv_balance_for($total, $paid, $credited, $used);
    $s = $db->prepare("SELECT status FROM invoices WHERE id=?");
    $s->execute([$id]);
    return ['total' => $total, 'paid' => $paid, 'credited' => $credited, 'used' => $used,
            'net_due' => round($total - $credited, 2), 'balance' => $balance,
            'credit_to_client' => inv_credit_to_client($balance),
            'cn_available' => round(inv_cn_pool($total, $paid, $credited) - $used, 2),
            'status' => inv_status_for((string)$s->fetchColumn(), $paid, $balance, $credited)];
}

/** C: sum of the active (not Cancelled) credit notes issued on invoice $id. */
function inv_credited_total(PDO $db, int $id): float {
    $s = $db->prepare("SELECT COALESCE(SUM(total),0) FROM credit_notes WHERE invoice_id=? AND status<>'Cancelled'");
    $s->execute([$id]);
    return round((float)$s->fetchColumn(), 2);
}

/** U: credit of the active credit notes issued on invoice $id already used (applications elsewhere + refunds paid). */
function inv_cn_used_total(PDO $db, int $id): float {
    $s = $db->prepare("SELECT COALESCE(SUM(a.amount_cn),0) FROM credit_note_allocations a JOIN credit_notes c ON c.id = a.credit_note_id
                        WHERE c.invoice_id=? AND c.status<>'Cancelled' AND a.cancelled_at IS NULL");
    $s->execute([$id]);
    return round((float)$s->fetchColumn(), 2);
}

/** balance = (T − C) − P + U, signed: < 0 is credit to client. */
function inv_balance_for(float $total, float $paid, float $credited, float $used = 0.0): float {
    return round($total - $credited - $paid + $used, 2);
}

/** Credit to client from a signed balance (0 when nothing is owed to the client). */
function inv_credit_to_client(float $balance): float {
    return max(0.0, round(-$balance, 2));
}

/** Credit the credit notes of an invoice can give back at most: min(C, max(0, P − (T − C))). */
function inv_cn_pool(float $total, float $paid, float $credited): float {
    return round(min($credited, max(0.0, $paid - ($total - $credited))), 2);
}

/**
 * Per active credit note of invoice $invId: cap (its share of inv_cn_pool(), spread in date order),
 * used, remaining = max(0, cap − used). [cn_id => ['cap', 'used', 'remaining']].
 */
function inv_cn_availability(PDO $db, int $invId): array {
    $f = inv_figures_raw($db, $invId);
    $pool = inv_cn_pool($f['total'], $f['paid'], $f['credited']);
    $s = $db->prepare("SELECT c.id, c.total,
                              (SELECT COALESCE(SUM(a.amount_cn),0) FROM credit_note_allocations a WHERE a.credit_note_id = c.id AND a.cancelled_at IS NULL) AS used
                         FROM credit_notes c WHERE c.invoice_id=? AND c.status<>'Cancelled' ORDER BY c.issue_date, c.id");
    $s->execute([$invId]);
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cap  = round(min((float)$c['total'], $pool), 2);
        $pool = round($pool - $cap, 2);
        $used = round((float)$c['used'], 2);
        $out[(int)$c['id']] = ['cap' => $cap, 'used' => $used, 'remaining' => max(0.0, round($cap - $used, 2))];
    }
    return $out;
}

/** T, P, C of an invoice from the live rows (no status / U). */
function inv_figures_raw(PDO $db, int $id): array {
    $s = $db->prepare("SELECT COALESCE(SUM(line_total),0) FROM invoice_items WHERE invoice_id=?");
    $s->execute([$id]);
    $total = round((float)$s->fetchColumn(), 2);
    $s = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE invoice_id=? AND cancelled_at IS NULL");
    $s->execute([$id]);
    return ['total' => $total, 'paid' => round((float)$s->fetchColumn(), 2), 'credited' => inv_credited_total($db, $id)];
}

// Fully Paid when nothing is left to collect and something was paid or credited;
// Partially Paid with payments and a balance; otherwise back to New.
function inv_status_for(string $cur, float $paid, float $balance, float $credited = 0.0): string {
    if ($cur === 'Cancelled') return $cur;
    if ($balance <= 0.001 && $paid + $credited > 0.001) return 'Fully Paid';
    if ($paid > 0.001) return 'Partially Paid';
    // all payments cancelled (or only part of the invoice credited)
    return in_array($cur, ['Partially Paid', 'Fully Paid']) ? 'New' : $cur;
}

// ── Sync request value_usd from linked invoice totals ────────────────────────
// Sums the `total` of all non-Cancelled invoices linked to the same request,
// minus its credit notes, and writes the result to requests.value_usd. Credit
// applied to an invoice of the same request stayed in the booking: not subtracted.
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
            ) + (
                SELECT COALESCE(SUM(a.amount_cn), 0)
                FROM credit_note_allocations a
                JOIN credit_notes c ON c.id = a.credit_note_id
                JOIN invoices i ON i.id = a.invoice_id
                WHERE c.request_id = ? AND c.status != 'Cancelled' AND a.cancelled_at IS NULL
                  AND i.request_id = ? AND i.status != 'Cancelled'
            )
         WHERE id = ?"
    )->execute([$request_id, $request_id, $request_id, $request_id, $request_id]);
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

// ── Source invoice of a credit note: recalculated after the CN changed ───────
// The credit note is its own document: it lowers the source invoice's balance through
// recalculate_invoice() (never as a payment). Rows written before 15 Nov 2026 may still
// have a NEGATIVE invoice_payments row (credit_notes.payment_id): cn_legacy_payments()
// lists them, cn_migrate_legacy_payments() cancels them.
function cn_resync_invoice(PDO $db, int $cnId): void {
    $s = $db->prepare("SELECT invoice_id FROM credit_notes WHERE id=?");
    $s->execute([$cnId]);
    $invoiceId = (int)$s->fetchColumn();
    if (!$invoiceId) return;
    recalculate_invoice($db, $invoiceId);
    sync_request_value($db, $invoiceId);
}

// ── Errors for a new credit note on $inv (cn_add.php, Agent API) ─────────────
// $credited: credit notes already active on the invoice (inv_credited_total()).
function cn_new_errors(array $inv, array $items, string $issueDate, float $credited = 0.0): array {
    $errors = [];
    if ($inv['status'] === 'Cancelled') $errors[] = 'Invoice ' . $inv['invoice_number'] . ' is cancelled.';
    if (!$issueDate) $errors[] = 'Issue date is required.';
    if (empty($items)) $errors[] = 'At least one credit line with an amount is required.';
    $cnTotal = inv_items_total($items);
    if ($cnTotal + $credited > (float)$inv['total'] + 0.001) {
        $errors[] = 'Credit note total (' . fmt_money($cnTotal, $inv['currency']) . ')'
                  . ($credited > 0.001 ? ' plus the credit notes already issued (' . fmt_money($credited, $inv['currency']) . ')' : '')
                  . ' cannot exceed the invoice total (' . fmt_money((float)$inv['total'], $inv['currency']) . ').';
    }
    return $errors;
}

/**
 * What a new credit note of $amount does to invoice $inv (cn_add.php, Agent API dry run):
 * balance_after, status_after, credit_to_client_after. The source invoice never becomes collectable.
 */
function cn_create_preview(PDO $db, array $inv, float $amount): array {
    $now   = inv_figures($db, (int)$inv['id']);
    $after = inv_figures($db, (int)$inv['id'], 0.0, $amount);
    // The new CN comes last in date order: it gets what the pool grows by.
    return ['balance_after' => $after['balance'], 'status_after' => $after['status'],
            'credit_to_client_after' => $after['credit_to_client'],
            'cn_available' => max(0.0, round($after['cn_available'] - max(0.0, $now['cn_available']), 2))];
}

// ── Create a credit note on $inv: its own document, lowers the invoice balance ─
// $items: [{description, quantity, unit_price, line_total}], checked with cn_new_errors().
// The credit stays on the CN as "remaining" until applied to another invoice or refunded.
function cn_create(PDO $db, array $inv, array $items, string $issueDate, string $reason, string $notes, ?int $uid): array {
    $invoiceId = (int)$inv['id'];
    $db->beginTransaction();
    try {
        $cnNum = generate_cn_number($db);
        $db->prepare("INSERT INTO credit_notes
            (cn_number, invoice_id, request_id, customer_id, bill_to_name, bill_to_address,
             issuer, currency, issue_date, reason, notes, status, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,'Issued',?)")
           ->execute([
                $cnNum, $invoiceId, $inv['request_id'], $inv['customer_id'],
                $inv['bill_to_name'], $inv['bill_to_address'],
                $inv['issuer'], $inv['currency'], $issueDate,
                $reason ?: null, $notes ?: null, $uid,
           ]);
        $cnId = (int)$db->lastInsertId();

        $sort = 0;
        foreach ($items as $item) {
            $db->prepare("INSERT INTO credit_note_items (credit_note_id,sort_order,description,quantity,unit_price,line_total) VALUES (?,?,?,?,?,?)")
               ->execute([$cnId,$sort++,$item['description'],$item['quantity'],$item['unit_price'],$item['line_total']]);
        }

        recalculate_credit_note($db, $cnId);
        cn_resync_invoice($db, $cnId);   // balance − credit, request value − credit
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    tl_log_invoice($db, $invoiceId, 'credit note ' . $cnNum . ' issued', ['credit_note_id' => $cnId, 'cn_number' => $cnNum]);
    return ['credit_note_id' => $cnId, 'cn_number' => $cnNum];
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

/**
 * Credit still available on a credit note: only what the client actually paid above net_due can be
 * given back — inv_cn_availability() of its source invoice (0 for a Cancelled CN). A CN on an unpaid
 * invoice just wipes the unpaid part: nothing available.
 */
function cn_remaining_credit(PDO $db, int $cnId): float {
    return cn_availability($db, $cnId)['remaining'];
}

/** ['cap', 'used', 'remaining'] of one credit note (all 0 when Cancelled / not found). */
function cn_availability(PDO $db, int $cnId): array {
    $s = $db->prepare("SELECT invoice_id FROM credit_notes WHERE id=? AND status<>'Cancelled'");
    $s->execute([$cnId]);
    $invId = (int)$s->fetchColumn();
    return $invId ? (inv_cn_availability($db, $invId)[$cnId] ?? ['cap' => 0.0, 'used' => 0.0, 'remaining' => 0.0])
                  : ['cap' => 0.0, 'used' => 0.0, 'remaining' => 0.0];
}

// ── Credit note status from its credit usage (a Cancelled one stays Cancelled) ─
// $cap: what the CN can give back (cn_availability()); nothing used yet → Issued.
function cn_status_for(float $cap, float $applied): string {
    if ($applied <= 0.001) return 'Issued';
    return $applied >= $cap - 0.001 ? 'Applied' : 'Partially applied';
}

function cn_refresh_status(PDO $db, int $cnId): void {
    $a = cn_availability($db, $cnId);
    $db->prepare("UPDATE credit_notes SET status=?, updated_at=NOW() WHERE id=? AND status<>'Cancelled'")
       ->execute([cn_status_for($a['cap'], cn_allocated_amount($db, $cnId)), $cnId]);
}

/** Every active credit note of an invoice: status refreshed (its caps move with P / C). */
function inv_refresh_cn_statuses(PDO $db, int $invId): void {
    $s = $db->prepare("SELECT id FROM credit_notes WHERE invoice_id=? AND status<>'Cancelled'");
    $s->execute([$invId]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $cid) cn_refresh_status($db, (int)$cid);
}

// ── Allocations of a credit note, active first (with the target invoice) ─────
function cn_allocations(PDO $db, int $cnId): array {
    $s = $db->prepare(
        "SELECT a.*, i.invoice_number, i.currency AS invoice_currency, i.bill_to_name
         FROM credit_note_allocations a
         LEFT JOIN invoices i ON i.id = a.invoice_id
         WHERE a.credit_note_id=?
         ORDER BY a.cancelled_at IS NOT NULL, a.alloc_date, a.id"
    );
    $s->execute([$cnId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

// ── Credit notes with their source invoice and the request's agency ──────────
// $f: id, number, invoice_id, request_id, agency_id, status (all optional).
function cn_find(PDO $db, array $f, int $limit = 100): array {
    $cols  = ['id' => 'cn.id', 'number' => 'cn.cn_number', 'invoice_id' => 'cn.invoice_id',
              'request_id' => 'cn.request_id', 'agency_id' => 'r.agency_id', 'status' => 'cn.status'];
    $where = []; $args = [];
    foreach ($cols as $k => $col) {
        if (isset($f[$k]) && $f[$k] !== '' && $f[$k] !== 0) { $where[] = "$col = ?"; $args[] = $f[$k]; }
    }
    $s = $db->prepare("SELECT cn.*, i.invoice_number, r.agency_id
                       FROM credit_notes cn
                       LEFT JOIN invoices i ON i.id = cn.invoice_id
                       LEFT JOIN requests r ON r.id = cn.request_id"
                      . ($where ? " WHERE " . implode(' AND ', $where) : "")
                      . " ORDER BY cn.issue_date DESC, cn.id DESC LIMIT " . max(1, $limit));
    $s->execute($args);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

// ── Checks for applying a credit note to an invoice ──────────────────────────
// Returns what would happen; RuntimeException when it is refused.
// $opts['strict'] (Agent API) also refuses another currency, a second active
// application on the same invoice and more than the invoice balance (unless
// $opts['allow_overpaid']). The invoice page allows those: it has an FX rate.
function cn_apply_plan(PDO $db, array $cn, array $inv, float $amountCn, float $fxRate, array $opts = []): array {
    if ($cn['status'] === 'Cancelled') throw new \RuntimeException('Credit note ' . $cn['cn_number'] . ' is cancelled.');
    if ($inv['status'] === 'Cancelled') throw new \RuntimeException('Cannot apply credit to a cancelled invoice.');
    // The credit already lowers its source invoice's balance: applying it there would count it twice.
    if ((int)$cn['invoice_id'] === (int)$inv['id']) {
        throw new \RuntimeException('Credit note ' . $cn['cn_number'] . ' was issued on invoice ' . $inv['invoice_number']
                                    . ': its credit is already deducted there. Apply it to another invoice, or record a refund.');
    }

    $cnId      = (int)$cn['id'];
    $remaining = cn_remaining_credit($db, $cnId);
    if ($remaining <= 0.001) throw new \RuntimeException('Credit note ' . $cn['cn_number'] . ' is already fully applied.');
    $amountCn = round($amountCn, 2);
    if ($amountCn <= 0) throw new \RuntimeException('Amount must be greater than zero.');
    if ($amountCn > $remaining + 0.001) {
        throw new \RuntimeException('Amount exceeds remaining credit (' . fmt_money($remaining, $cn['currency']) . ').');
    }

    $strict = !empty($opts['strict']);
    $same   = $cn['currency'] === $inv['currency'];
    if ($strict && !$same) {
        throw new \RuntimeException('Credit note ' . $cn['cn_number'] . ' is in ' . $cn['currency'] . ', invoice ' . $inv['invoice_number'] . ' in ' . $inv['currency'] . '.');
    }
    if ($strict) {
        $s = $db->prepare("SELECT id FROM credit_note_allocations WHERE credit_note_id=? AND invoice_id=? AND cancelled_at IS NULL LIMIT 1");
        $s->execute([$cnId, (int)$inv['id']]);
        $dup = (int)$s->fetchColumn();
        if ($dup) throw new \RuntimeException('Credit note ' . $cn['cn_number'] . ' is already applied to invoice ' . $inv['invoice_number'] . ' (allocation ' . $dup . ').');
    }

    if ($fxRate <= 0) $fxRate = 1.0;
    // Same currency at rate 1: the exact amount. With a rate: floor to the unit in the invoice currency.
    $amountInvoice = ($same && abs($fxRate - 1.0) < 0.0000001) ? $amountCn : floor($amountCn * $fxRate);
    if ($amountInvoice <= 0) throw new \RuntimeException('Resulting invoice amount is zero after rounding.');

    $balance = round((float)$inv['balance_due'], 2);
    if ($strict && $amountInvoice > $balance + 0.005 && empty($opts['allow_overpaid'])) {
        throw new \RuntimeException('Amount ' . fmt_money($amountInvoice, $inv['currency']) . ' is more than the balance due '
                                    . fmt_money($balance, $inv['currency']) . ' of invoice ' . $inv['invoice_number'] . ' ("allow_overpaid": true to accept it).');
    }

    $balanceAfter = round($balance - $amountInvoice, 2);
    return [
        'amount_cn'            => $amountCn,
        'amount_invoice'       => (float)$amountInvoice,
        'fx_rate'              => $fxRate,
        'cn_remaining_after'   => round($remaining - $amountCn, 2),
        'cn_status_after'      => cn_status_for(cn_availability($db, $cnId)['cap'], cn_allocated_amount($db, $cnId) + $amountCn),
        'balance_after'        => $balanceAfter,
        'invoice_status_after' => inv_status_for($inv['status'], round((float)$inv['amount_paid'] + $amountInvoice, 2), $balanceAfter,
                                                 inv_credited_total($db, (int)$inv['id'])),
        'request_value_change' => !empty($cn['request_id']) && (int)$cn['request_id'] === (int)$inv['request_id'] ? $amountCn : 0.0,
    ];
}

// ── Apply part of a credit note's credit to an invoice ───────────────────────
// $amountCn   : amount to deduct from the CN, in the CN's currency
// $fxRate     : CN currency -> invoice currency (1 when same currency)
// Recorded as a POSITIVE payment on the target invoice (method "Credit note",
// reference = CN number); see cn_apply_plan() for the amount and $opts.
// Returns the new allocation id. Throws on validation failure.
function cn_apply_to_invoice(
    PDO $db, int $cnId, int $targetInvoiceId,
    float $amountCn, float $fxRate, string $allocDate, ?string $note, int $uid,
    array $opts = [], ?array &$closedMemos = null
): int {
    $db->beginTransaction();
    try {
        // Rows locked so that two requests at once cannot spend the same credit.
        $cn = $db->prepare("SELECT * FROM credit_notes WHERE id=? FOR UPDATE");
        $cn->execute([$cnId]);
        $cn = $cn->fetch(PDO::FETCH_ASSOC);
        if (!$cn) throw new \RuntimeException('Credit note not found.');

        $inv = $db->prepare("SELECT * FROM invoices WHERE id=? FOR UPDATE");
        $inv->execute([$targetInvoiceId]);
        $inv = $inv->fetch(PDO::FETCH_ASSOC);
        if (!$inv) throw new \RuntimeException('Target invoice not found.');

        $plan = cn_apply_plan($db, $cn, $inv, $amountCn, $fxRate, $opts);
        $payNote = 'Credit note ' . $cn['cn_number']
                 . ' (' . fmt_money($plan['amount_cn'], $cn['currency']) . ' @ ' . rtrim(rtrim(number_format($plan['fx_rate'], 6, '.', ''), '0'), '.') . ')'
                 . ($note ? ' — ' . $note : '');

        // Positive payment on the target invoice, in invoice currency.
        $db->prepare("INSERT INTO invoice_payments (invoice_id,payment_date,amount,method,reference,notes) VALUES (?,?,?,?,?,?)")
           ->execute([$targetInvoiceId, $allocDate, $plan['amount_invoice'], INV_METHOD_CREDIT_NOTE, $cn['cn_number'], $payNote]);
        $payId = (int)$db->lastInsertId();

        $db->prepare("INSERT INTO credit_note_allocations
            (credit_note_id, invoice_id, payment_id, amount_cn, amount_invoice, fx_rate, alloc_date, notes, created_by)
            VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$cnId, $targetInvoiceId, $payId, $plan['amount_cn'], $plan['amount_invoice'], $plan['fx_rate'], $allocDate, $note ?: null, $uid]);
        $allocId = (int)$db->lastInsertId();

        cn_refresh_status($db, $cnId);
        recalculate_invoice($db, $targetInvoiceId);
        sync_request_value($db, $targetInvoiceId);
        recalculate_invoice($db, (int)$cn['invoice_id']);   // source: U up, "credit to client" down

        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    $closedMemos = inv_after_payment($db, $targetInvoiceId, $plan['amount_invoice'], $allocDate, $cn['cn_number'],
        'credit note ' . $cn['cn_number'] . ' applied',
        ['credit_note_id' => $cnId, 'cn_number' => $cn['cn_number'], 'payment_id' => $payId, 'payment_amount' => $plan['amount_invoice'], 'payment_date' => $allocDate]);
    return $allocId;
}

// ── Cancel an allocation: removes the linked payment, frees the credit ───────
// A refund allocation (kind 'refund', no invoice) only frees the credit.
function cn_cancel_allocation(PDO $db, int $allocId): void {
    $a = $db->prepare("SELECT a.*, c.cn_number, c.invoice_id AS source_invoice_id FROM credit_note_allocations a LEFT JOIN credit_notes c ON c.id = a.credit_note_id WHERE a.id=? AND a.cancelled_at IS NULL");
    $a->execute([$allocId]);
    $a = $a->fetch();
    if (!$a) return;
    $target = (int)($a['invoice_id'] ?? 0);

    $db->beginTransaction();
    try {
        if ($a['payment_id']) {
            $db->prepare("UPDATE invoice_payments SET cancelled_at=NOW(), cancellation_reason=? WHERE id=? AND cancelled_at IS NULL")
               ->execute(['Credit note allocation cancelled', (int)$a['payment_id']]);
        }
        $db->prepare("UPDATE credit_note_allocations SET cancelled_at=NOW() WHERE id=?")->execute([$allocId]);

        cn_refresh_status($db, (int)$a['credit_note_id']);
        if ($target) {
            recalculate_invoice($db, $target);
            sync_request_value($db, $target);
        }
        if ((int)$a['source_invoice_id']) recalculate_invoice($db, (int)$a['source_invoice_id']);   // source: U down

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    tl_log_invoice($db, $target ?: (int)$a['source_invoice_id'],
                   'credit note ' . $a['cn_number'] . ($target ? ' application' : ' refund') . ' cancelled',
                   ['credit_note_id' => (int)$a['credit_note_id'], 'cn_number' => $a['cn_number'], 'payment_id' => (int)$a['payment_id']]);
}

// ── Refund paid out (bank transfer) from a credit note ───────────────────────
/** credit_note_allocations.kind / reference and a nullable invoice_id (refunds have no target invoice). Once per PHP request. */
function cn_alloc_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $cols = $db->query("SELECT COLUMN_NAME, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'credit_note_allocations'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $alter = [];
    if (!isset($cols['kind']))      $alter[] = "ADD COLUMN kind ENUM('invoice','refund') NOT NULL DEFAULT 'invoice' AFTER credit_note_id";
    if (!isset($cols['reference'])) $alter[] = "ADD COLUMN reference VARCHAR(100) NULL AFTER alloc_date";
    if (($cols['invoice_id'] ?? 'YES') === 'NO') $alter[] = "MODIFY invoice_id INT(10) UNSIGNED NULL";
    if ($alter) {
        try { $db->exec("ALTER TABLE credit_note_allocations " . implode(', ', $alter)); }
        catch (\Throwable $e) { error_log('cn_alloc_schema: ' . $e->getMessage()); }
    }
}

/**
 * Record that part (or all) of a credit note's credit was paid back to the client: an allocation
 * of kind 'refund' (no target invoice). U of the source invoice goes up (its "credit to client"
 * down), the CN "remaining" down. Returns the allocation id.
 */
function cn_record_refund(PDO $db, int $cnId, float $amount, string $date, string $reference, ?string $note, int $uid): int {
    cn_alloc_schema($db);
    $amount = round($amount, 2);
    if ($amount <= 0) throw new \RuntimeException('Amount must be greater than zero.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) throw new \RuntimeException('Date must be YYYY-MM-DD.');
    $db->beginTransaction();
    try {
        $cn = $db->prepare("SELECT * FROM credit_notes WHERE id=? FOR UPDATE");
        $cn->execute([$cnId]);
        $cn = $cn->fetch(PDO::FETCH_ASSOC);
        if (!$cn) throw new \RuntimeException('Credit note not found.');
        if ($cn['status'] === 'Cancelled') throw new \RuntimeException('Credit note ' . $cn['cn_number'] . ' is cancelled.');
        $remaining = cn_remaining_credit($db, $cnId);
        if ($amount > $remaining + 0.001) {
            throw new \RuntimeException('Amount exceeds remaining credit (' . fmt_money($remaining, $cn['currency']) . ').');
        }
        $db->prepare("INSERT INTO credit_note_allocations
            (credit_note_id, kind, invoice_id, payment_id, amount_cn, amount_invoice, fx_rate, alloc_date, reference, notes, created_by)
            VALUES (?, 'refund', NULL, NULL, ?, ?, 1, ?, ?, ?, ?)")
           ->execute([$cnId, $amount, $amount, $date, $reference !== '' ? mb_substr($reference, 0, 100) : null, $note ?: null, $uid ?: null]);
        $allocId = (int)$db->lastInsertId();
        cn_refresh_status($db, $cnId);
        recalculate_invoice($db, (int)$cn['invoice_id']);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    tl_log_invoice($db, (int)$cn['invoice_id'], 'credit note ' . $cn['cn_number'] . ' refund paid ' . fmt_money($amount, $cn['currency']),
                   ['credit_note_id' => $cnId, 'cn_number' => $cn['cn_number'], 'allocation_id' => $allocId, 'reference' => $reference]);
    return $allocId;
}

// ── Cancel / restore a credit note (cn_view.php) ─────────────────────────────
/** Cancelled, remaining 0: its applications / refunds are cancelled, the source invoice is back to T − P (without this CN). */
function cn_cancel(PDO $db, int $cnId): void {
    $out = $db->prepare("SELECT id FROM credit_note_allocations WHERE credit_note_id=? AND cancelled_at IS NULL");
    $out->execute([$cnId]);
    foreach ($out->fetchAll(PDO::FETCH_COLUMN) as $aId) cn_cancel_allocation($db, (int)$aId);
    $db->prepare("UPDATE credit_notes SET status='Cancelled', updated_at=NOW() WHERE id=? AND status<>'Cancelled'")->execute([$cnId]);
    cn_cancel_legacy_payment($db, $cnId, 'Credit note cancelled');
    cn_resync_invoice($db, $cnId);
    $c = $db->prepare("SELECT invoice_id, cn_number FROM credit_notes WHERE id=?");
    $c->execute([$cnId]);
    if ($c = $c->fetch(PDO::FETCH_ASSOC)) tl_log_invoice($db, (int)$c['invoice_id'], 'credit note ' . $c['cn_number'] . ' cancelled', ['credit_note_id' => $cnId, 'cn_number' => $c['cn_number']]);
}

/** Back to Issued: the credit is deducted from the source invoice again (applications stay cancelled). */
function cn_restore(PDO $db, int $cnId): void {
    $db->prepare("UPDATE credit_notes SET status='Issued', updated_at=NOW() WHERE id=? AND status='Cancelled'")->execute([$cnId]);
    cn_refresh_status($db, $cnId);
    cn_resync_invoice($db, $cnId);
    $c = $db->prepare("SELECT invoice_id, cn_number FROM credit_notes WHERE id=?");
    $c->execute([$cnId]);
    if ($c = $c->fetch(PDO::FETCH_ASSOC)) tl_log_invoice($db, (int)$c['invoice_id'], 'credit note ' . $c['cn_number'] . ' restored', ['credit_note_id' => $cnId, 'cn_number' => $c['cn_number']]);
}

// ── Credit note notes (printed on the CN PDF) + the CN PDF itself ────────────
/** Save the notes of a credit note ('' = none). Returns [before, after]. */
function cn_update_notes(PDO $db, int $cnId, string $notes): array {
    $s = $db->prepare("SELECT notes, invoice_id, cn_number FROM credit_notes WHERE id=?");
    $s->execute([$cnId]);
    $c = $s->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new \RuntimeException('Credit note not found.');
    $notes = trim(str_replace("\r\n", "\n", $notes));
    $db->prepare("UPDATE credit_notes SET notes=?, updated_at=NOW() WHERE id=?")->execute([$notes !== '' ? $notes : null, $cnId]);
    tl_log_invoice($db, (int)$c['invoice_id'], 'credit note ' . $c['cn_number'] . ' notes edited', ['credit_note_id' => $cnId, 'cn_number' => $c['cn_number']]);
    return [(string)($c['notes'] ?? ''), $notes];
}

/** Credit note row joined with its invoice_number (what the CN layout needs), or null. */
function cn_get(PDO $db, int $cnId): ?array {
    $s = $db->prepare("SELECT cn.*, i.invoice_number FROM credit_notes cn LEFT JOIN invoices i ON i.id = cn.invoice_id WHERE cn.id=?");
    $s->execute([$cnId]);
    $c = $s->fetch(PDO::FETCH_ASSOC);
    return $c ?: null;
}

function cn_items(PDO $db, int $cnId): array {
    $s = $db->prepare("SELECT * FROM credit_note_items WHERE credit_note_id=? ORDER BY sort_order,id");
    $s->execute([$cnId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

/** Credit note PDF (Dompdf, same layout as cn_pdf.php). $cn: cn_get(). */
function cn_pdf(array $cn, array $items): string {
    $auto = __DIR__ . '/../../../vendor/autoload.php';
    if (!class_exists('\Dompdf\Dompdf') && is_file($auto)) require_once $auto;
    if (!class_exists('\Dompdf\Dompdf')) throw new RuntimeException('PDF library not installed. Run: composer install');
    require_once __DIR__ . '/invoice_html.php';
    $dompdf = new \Dompdf\Dompdf(inv_doc_dompdf_options());
    $dompdf->loadHtml(inv_doc_dompdf_html(inv_doc_from_credit_note($cn, $items), 'Credit Note ' . $cn['cn_number']));
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

/** "Credit Note CN-2026-0002.pdf". */
function cn_pdf_dropbox_name(array $cn): string {
    return 'Credit Note ' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$cn['cn_number']) . '.pdf';
}

// ── Before 15 Nov 2026: credit notes recorded as negative payments ───────────
/** Cancel the old negative payment of one credit note, if still active (no recalculation). */
function cn_cancel_legacy_payment(PDO $db, int $cnId, string $reason): void {
    foreach (cn_legacy_payments($db, $cnId) as $p) {
        $db->prepare("UPDATE invoice_payments SET cancelled_at=NOW(), cancellation_reason=? WHERE id=? AND cancelled_at IS NULL")
           ->execute([$reason, (int)$p['payment_id']]);
    }
}

/**
 * Active NEGATIVE payments written by the old cn_create (method Other, reference CN-YYYY-NNNN, not a
 * credit note application), with their invoice and credit note. $cnId: only that credit note.
 */
function cn_legacy_payments(PDO $db, int $cnId = 0): array {
    $s = $db->prepare(
        "SELECT p.id AS payment_id, p.invoice_id, p.amount, p.payment_date, p.reference,
                i.invoice_number, i.currency, i.status AS invoice_status, i.total, i.amount_paid, i.balance_due,
                c.id AS credit_note_id, c.cn_number, c.total AS cn_total, c.status AS cn_status
           FROM invoice_payments p
           JOIN invoices i ON i.id = p.invoice_id
           LEFT JOIN credit_notes c ON c.id = COALESCE((SELECT c1.id FROM credit_notes c1 WHERE c1.payment_id = p.id LIMIT 1),
                                                       (SELECT c2.id FROM credit_notes c2 WHERE c2.cn_number = p.reference AND c2.invoice_id = p.invoice_id LIMIT 1))
          WHERE p.cancelled_at IS NULL AND p.amount < 0 AND p.method = 'Other'
            AND p.reference REGEXP '^CN-[0-9]{4}-[0-9]{4}$'
            AND NOT EXISTS (SELECT 1 FROM credit_note_allocations a WHERE a.payment_id = p.id)"
        . ($cnId ? " AND c.id = ?" : "") . "
          ORDER BY p.invoice_id, p.id");
    $s->execute($cnId ? [$cnId] : []);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Migration: cancel the legacy negative payments (cn_legacy_payments()), then recalculate each invoice
 * and its request value. Dry run unless $confirm. Returns ['rows' => [{invoice, cn, amount, balance /
 * status before → after}], 'warnings' => [...], 'migrated' => n].
 */
function cn_migrate_legacy_payments(PDO $db, bool $confirm): array {
    $reason = 'Migrated: credit notes no longer recorded as negative payments';
    $rows = []; $warnings = []; $byInv = []; $rowsInfo = [];
    foreach (cn_legacy_payments($db) as $p) $byInv[(int)$p['invoice_id']][] = $p;
    // Invoices with active credit notes but no legacy payment: recalculated too when the new formula changes them.
    foreach ($db->query("SELECT DISTINCT i.id, i.invoice_number, i.currency, i.status AS invoice_status, i.total, i.amount_paid, i.balance_due
                           FROM invoices i JOIN credit_notes c ON c.invoice_id = i.id
                          WHERE c.status <> 'Cancelled' AND i.status <> 'Cancelled'")->fetchAll(PDO::FETCH_ASSOC) as $i) {
        if (isset($byInv[(int)$i['id']])) continue;
        $f = inv_figures($db, (int)$i['id']);
        if (abs($f['balance'] - (float)$i['balance_due']) < 0.005 && $f['status'] === $i['invoice_status']) continue;
        $byInv[(int)$i['id']] = [];
        $rowsInfo[(int)$i['id']] = $i;
    }
    foreach ($byInv as $invId => $pays) {
        $inv      = $pays[0] ?? $rowsInfo[$invId];
        $legacy   = array_sum(array_map(fn($p) => (float)$p['amount'], $pays));   // negative
        $f        = inv_figures($db, $invId, -$legacy);
        foreach ($pays as $p) {
            if (!$p['credit_note_id']) $warnings[] = 'Payment ' . $p['payment_id'] . ' on ' . $p['invoice_number'] . ': no credit note ' . $p['reference'] . ' found — check by hand.';
            elseif ($p['cn_status'] === 'Cancelled') $warnings[] = 'Payment ' . $p['payment_id'] . ' on ' . $p['invoice_number'] . ': credit note ' . $p['cn_number'] . ' is Cancelled but its negative payment was still active.';
        }
        $rows[] = [
            'invoice_id' => $invId, 'invoice' => $inv['invoice_number'], 'currency' => $inv['currency'],
            'credit_notes' => array_map(fn($p) => ['payment_id' => (int)$p['payment_id'], 'cn' => $p['cn_number'] ?: $p['reference'],
                                                   'amount' => round(-(float)$p['amount'], 2), 'cn_status' => $p['cn_status']], $pays),
            'total' => round((float)$inv['total'], 2),
            'paid_before' => round((float)$inv['amount_paid'], 2), 'paid_after' => $f['paid'],
            'credited' => $f['credited'], 'credit_used' => $f['used'],
            'balance_before' => round((float)$inv['balance_due'], 2), 'balance_after' => $f['balance'],
            'status_before' => $inv['invoice_status'], 'status_after' => $f['status'],
            'credit_to_client_after' => $f['credit_to_client'], 'cn_available_after' => max(0.0, $f['cn_available']),
        ];
    }
    // Credit notes applied to their own source invoice: the balance is unchanged (C and U cancel out) but
    // "paid" shows the application as a payment. Real case SE-2026-0313 / CN-2026-0003: a deposit parked during a
    // date change and re-applied — not a reduction, so the whole CN was cancelled.
    foreach ($db->query("SELECT a.id, c.cn_number, i.invoice_number FROM credit_note_allocations a
                           JOIN credit_notes c ON c.id = a.credit_note_id JOIN invoices i ON i.id = a.invoice_id
                          WHERE a.cancelled_at IS NULL AND c.status <> 'Cancelled' AND a.invoice_id = c.invoice_id")->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $warnings[] = 'Credit note ' . $a['cn_number'] . ' is applied to its own invoice ' . $a['invoice_number'] . ' (allocation ' . $a['id'] . '): its amount paid is inflated by the application. If the CN was a real reduction, cancel the application; if it only parked money (no reduction), cancel the whole credit note — before migrating.';
    }
    $migrated = 0;
    if ($confirm) {
        foreach ($byInv as $invId => $pays) {
            $db->beginTransaction();
            try {
                foreach ($pays as $p) {
                    $db->prepare("UPDATE invoice_payments SET cancelled_at=NOW(), cancellation_reason=? WHERE id=? AND cancelled_at IS NULL")
                       ->execute([$reason, (int)$p['payment_id']]);
                    $migrated++;
                }
                recalculate_invoice($db, $invId);
                sync_request_value($db, $invId);
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            tl_log_invoice($db, $invId, 'credit notes no longer recorded as negative payments (migration)',
                           ['payment_ids' => array_map(fn($p) => (int)$p['payment_id'], $pays)]);
        }
    }
    return ['dry_run' => !$confirm, 'rows' => $rows, 'warnings' => $warnings, 'migrated' => $migrated];
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

/**
 * Credit notes issued on invoice $id, each with `used` (applied elsewhere + refunds paid) and `used_on`
 * ("SE-2026-0504", "refund", …) for the totals of the invoice page / PDF (inv_doc_totals()).
 */
function inv_credit_notes(PDO $db, int $id): array {
    $s = $db->prepare("SELECT * FROM credit_notes WHERE invoice_id=? ORDER BY issue_date, id");
    $s->execute([$id]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$c) {
        $used = 0.0; $on = [];
        foreach (cn_allocations($db, (int)$c['id']) as $a) {
            if (!empty($a['cancelled_at'])) continue;
            $used += (float)$a['amount_cn'];
            $on[] = ($a['kind'] ?? 'invoice') === 'refund' ? 'refund' : (string)$a['invoice_number'];
        }
        $c['used']    = round($used, 2);
        $c['used_on'] = implode(', ', array_unique($on));
    }
    return $rows;
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

    $closedMemos = inv_after_payment($db, $invId, $amount, $date, $ref, 'payment recorded',
                   ['payment_id' => $pid, 'payment_amount' => $amount, 'payment_date' => $date, 'payment_reference' => $ref]);
    return $pid;
}

/** After a new payment: close the memos waiting for it (returned) and log it in the request timeline. */
function inv_after_payment(PDO $db, int $invId, float $amount, string $date, string $ref, string $what, array $refs): array {
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
    tl_log_invoice($db, $invId, $what, $refs);
    return $closedMemos;
}

/** Cancel a payment (kept for history) and recalculate. False if nothing matched. */
function inv_cancel_payment(PDO $db, int $paymentId, int $invId, string $reason): bool {
    $st = $db->prepare("UPDATE invoice_payments SET cancelled_at=NOW(), cancellation_reason=? WHERE id=? AND invoice_id=?");
    $st->execute([$reason, $paymentId, $invId]);
    // A payment made with a credit note gives the credit back.
    $al = $db->prepare("SELECT id, credit_note_id FROM credit_note_allocations WHERE payment_id=? AND cancelled_at IS NULL");
    $al->execute([$paymentId]);
    foreach ($al->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $db->prepare("UPDATE credit_note_allocations SET cancelled_at=NOW() WHERE id=?")->execute([(int)$a['id']]);
        cn_refresh_status($db, (int)$a['credit_note_id']);
    }
    recalculate_invoice($db, $invId);
    sync_request_value($db, $invId);
    $ok = $st->rowCount() > 0;
    if ($ok) tl_log_invoice($db, $invId, 'payment cancelled', ['payment_id' => $paymentId, 'reason' => $reason]);
    return $ok;
}

// ── Cancel / restore an invoice (invoice_view.php + Agent API) ───────────────
/** Current requests.value_usd of the invoice's request, null when none is linked. */
function inv_request_value_now(PDO $db, int $invId): ?float {
    $s = $db->prepare("SELECT r.value_usd FROM invoices i JOIN requests r ON r.id = i.request_id WHERE i.id = ?");
    $s->execute([$invId]);
    $v = $s->fetchColumn();
    return $v === false ? null : round((float)$v, 2);
}

/**
 * What sync_request_value() would write for $requestId if invoice $asCancelledId were
 * Cancelled (same formula; 0 = as it is now). Used by the cancel_invoice dry run.
 */
function inv_request_value_if_cancelled(PDO $db, int $requestId, int $asCancelledId): float {
    $s = $db->prepare(
        "SELECT (SELECT COALESCE(SUM(total), 0) FROM invoices
                  WHERE request_id = ? AND status != 'Cancelled' AND id <> ?)
              - (SELECT COALESCE(SUM(total), 0) FROM credit_notes
                  WHERE request_id = ? AND status != 'Cancelled')
              + (SELECT COALESCE(SUM(a.amount_cn), 0)
                   FROM credit_note_allocations a
                   JOIN credit_notes c ON c.id = a.credit_note_id
                   JOIN invoices i ON i.id = a.invoice_id
                  WHERE c.request_id = ? AND c.status != 'Cancelled' AND a.cancelled_at IS NULL
                    AND i.request_id = ? AND i.status != 'Cancelled' AND i.id <> ?)"
    );
    $s->execute([$requestId, $asCancelledId, $requestId, $requestId, $requestId, $asCancelledId]);
    return round((float)$s->fetchColumn(), 2);
}

/**
 * Set an invoice Cancelled: balance and amount paid to 0 (payment rows are kept for
 * history), request value resynced, timeline event (with the reason, if any).
 * The page allows it with payments; the Agent API refuses that before calling this.
 * Returns ['request_value_before' => ?float, 'request_value_after' => ?float].
 */
function inv_cancel_invoice(PDO $db, int $id, string $reason = ''): array {
    $reason = trim($reason);
    $before = inv_request_value_now($db, $id);
    // Zero out the balance so a cancelled invoice never appears in the
    // payment follow-up list (existing payment rows are kept for history).
    $db->prepare("UPDATE invoices SET status='Cancelled', balance_due=0, amount_paid=0, updated_at=NOW() WHERE id=?")->execute([$id]);
    sync_request_value($db, $id);
    // invoices has no internal notes column (`notes` is printed on the PDF): the reason lives in the timeline.
    tl_log_invoice($db, $id, $reason !== '' ? 'cancelled: ' . $reason : 'cancelled', $reason !== '' ? ['reason' => $reason] : []);
    return ['request_value_before' => $before, 'request_value_after' => inv_request_value_now($db, $id)];
}

/**
 * Back from Cancelled to New, then recalculated: with active payments the status
 * becomes Partially / Fully Paid (warning says so). Nothing happens if not Cancelled.
 * Returns ['final_status' => string, 'warning' => ?string].
 */
function inv_restore_invoice(PDO $db, int $id): array {
    $db->prepare("UPDATE invoices SET status='New', updated_at=NOW() WHERE id=? AND status='Cancelled'")->execute([$id]);
    recalculate_invoice($db, $id);
    sync_request_value($db, $id);
    tl_log_invoice($db, $id, 'restored');
    $s = $db->prepare("SELECT status FROM invoices WHERE id=?");
    $s->execute([$id]);
    $finalStatus = (string)$s->fetchColumn();
    $warning = $finalStatus !== 'New'
        ? "Invoice has recorded payments — status has been set to \"$finalStatus\" instead of New. To revert to New, cancel all payments first."
        : null;
    return ['final_status' => $finalStatus, 'warning' => $warning];
}

/**
 * Open Memo Board follow-ups waiting on this invoice's payment: ext_key pay-<number>
 * (SH invoices) or auto_close = 'payment' on the invoice. [{id, title, ext_key, user_id}].
 */
function inv_open_payment_memos(PDO $db, array $inv): array {
    try {
        require_once __DIR__ . '/../../memo/memo_lib.php';
        memo_schema($db);
        $s = $db->prepare("SELECT id, title, ext_key, user_id FROM memos
                           WHERE deleted_at IS NULL AND status IN ('open','doing','waiting')
                             AND (ext_key = ? OR (invoice_id = ? AND auto_close = 'payment'))
                           ORDER BY id");
        $s->execute(['pay-' . $inv['invoice_number'], (int)$inv['id']]);
        return array_map(function ($m) {
            return ['id' => (int)$m['id'], 'title' => $m['title'], 'ext_key' => $m['ext_key'], 'user_id' => (int)$m['user_id']];
        }, $s->fetchAll(PDO::FETCH_ASSOC));
    } catch (\Throwable $e) {
        error_log('inv_open_payment_memos(' . $inv['id'] . '): ' . $e->getMessage());
        return [];
    }
}

/** Close those memos (done, note "invoice cancelled"); returns the ones closed. Never throws. */
function inv_close_payment_memos(PDO $db, array $inv): array {
    $closed = [];
    foreach (inv_open_payment_memos($db, $inv) as $m) {
        try {
            $closed[] = $m + ['opened_next' => memo_close($db, $m['id'], 'Invoice ' . $inv['invoice_number'] . ' cancelled — closed automatically.')];
        } catch (\Throwable $e) {
            error_log('inv_close_payment_memos(' . $inv['id'] . ', memo ' . $m['id'] . '): ' . $e->getMessage());
        }
    }
    return $closed;
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
    tl_log_invoice($db, $id, 'created');
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
    tl_log_invoice($db, $id, 'updated');
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
 * Sales (agent) of the invoice's linked request, for the Cc of the invoice email:
 * ['name' => …, 'email' => …] or null (no request, no agent, or the agent's user has no email).
 */
function inv_sales_contact(PDO $db, int $invId): ?array {
    $s = $db->prepare("SELECT a.name,
                              (SELECT u.email FROM users u WHERE u.agent_id = a.id AND u.is_active = 1
                                      AND u.email IS NOT NULL AND u.email <> '' ORDER BY u.id LIMIT 1) AS email
                         FROM invoices i JOIN requests r ON r.id = i.request_id JOIN agents a ON a.id = r.agent_id
                        WHERE i.id = ?");
    $s->execute([$invId]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r || !filter_var(trim((string)$r['email']), FILTER_VALIDATE_EMAIL)) return null;
    return ['name' => (string)$r['name'], 'email' => trim((string)$r['email'])];
}

/**
 * Default Cc of the invoice email: $base addresses plus the sales of the request,
 * unless the sales is already among $to / $base. Comma-separated string.
 */
function inv_mail_default_cc(PDO $db, int $invId, string $to, string $base = 'accountant@savannahexplorers.com'): string {
    $cc    = array_values(array_filter(array_map('trim', preg_split('/[,;]+/', $base))));
    $sales = inv_sales_contact($db, $invId);
    if ($sales) {
        $have = array_map('strtolower', array_merge($cc, array_map('trim', preg_split('/[,;]+/', $to))));
        if (!in_array(strtolower($sales['email']), $have, true)) $cc[] = $sales['email'];
    }
    return implode(', ', $cc);
}

/** Valid addresses from "a@x, b@y; c@z" or a list; invalid ones are dropped, duplicates removed. */
function inv_mail_addrs($v): array {
    $out = [];
    foreach (is_array($v) ? $v : preg_split('/[,;]+/', (string)$v) as $a) {
        $a = trim((string)$a);
        if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL) && !in_array(strtolower($a), array_map('strtolower', $out), true)) $out[] = $a;
    }
    return $out;
}

/**
 * Default invoice email (the "Send by email" form on invoice_view.php and the Agent API
 * send_invoice_email): To = the request's email, Cc = inv_mail_default_cc(), subject, body (Esther's receipt text).
 */
function inv_mail_defaults(PDO $db, array $inv): array {
    $r  = inv_linked_request($db, (int)$inv['id']);
    $to = trim((string)($r['email'] ?? ''));
    return [
        'to'      => $to,
        'cc'      => inv_mail_default_cc($db, (int)$inv['id'], $to),
        'subject' => $inv['bill_to_name'] . ' trip with Savannah Explorers',
        'body'    => "Greetings from Savannah Explorers.\r\nKindly find your attached receipt and note that your money was successfully received into our bank account,\r\nWe wish you all the best for your upcoming safari/trip,\r\nRegards with thanks,\r\nEsther\r\n--\r\nSavannah Explorers Ltd\r\nEngosheraton - P.O. Box 16726\r\nArusha - Tanzania\r\nOffice +255 768 900 199\r\nEmergency Mobile Tanzania: +255 768 900 199 and +255 747 777 315\r\nEmail: accountant@savannahexplorers.com\r\nWebsite: savannahexplorers.net",
    ];
}

/** [To list, Cc list] as sent: valid addresses only, a Cc address already in To dropped. */
function inv_mail_recipients($to, $cc): array {
    $toList  = inv_mail_addrs($to);
    $toLower = array_map('strtolower', $toList);
    return [$toList, array_values(array_filter(inv_mail_addrs($cc), fn($a) => !in_array(strtolower($a), $toLower, true)))];
}

/** File name of the invoice PDF attached to the email. */
function inv_mail_filename(array $inv): string {
    return 'Invoice_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $inv['invoice_number']) . '.pdf';
}

/**
 * Send the invoice PDF by email (BlueHost mail(), From noreply@, Reply-To accountant@).
 * $to / $cc: string or list (inv_mail_addrs()); a Cc address already in To is dropped.
 * Returns ['to' => [...], 'cc' => [...], 'subject', 'attachment']; RuntimeException on error.
 */
function inv_send_email(PDO $db, array $inv, $to, $cc, string $subject, string $body): array {
    [$toList, $ccList] = inv_mail_recipients($to, $cc);
    $subject = trim(str_replace(["\r", "\n"], ' ', $subject));
    if (!$toList)              throw new InvalidArgumentException('No valid To address found');
    if ($subject === '')       throw new InvalidArgumentException('Subject is required');
    if (trim($body) === '')    throw new InvalidArgumentException('Body is required');

    $id       = (int)$inv['id'];
    $pdfData  = inv_pdf($inv, inv_items($db, $id), inv_payments($db, $id, true), inv_credit_notes($db, $id));
    $filename = inv_mail_filename($inv);
    $boundary = '----_Part_' . md5(uniqid('', true));

    $headers  = "From: Savannah Explorers <noreply@savannahexplorers.com>\r\n";
    $headers .= "Reply-To: accountant@savannahexplorers.com\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
    if ($ccList) $headers .= "Cc: " . implode(', ', $ccList) . "\r\n";

    $msgBody  = "--{$boundary}\r\n";
    $msgBody .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $msgBody .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $msgBody .= $body . "\r\n\r\n";
    $msgBody .= "--{$boundary}\r\n";
    $msgBody .= "Content-Type: application/pdf; name=\"{$filename}\"\r\n";
    $msgBody .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n";
    $msgBody .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $msgBody .= chunk_split(base64_encode($pdfData)) . "\r\n";
    $msgBody .= "--{$boundary}--";

    if (!mail(implode(', ', $toList), $subject, $msgBody, $headers)) {
        throw new RuntimeException('mail() returned false — check BlueHost mail log');
    }
    // Request timeline: mail_sent (page and Agent API alike).
    $eventId = !empty($inv['request_id'])
        ? timeline_log((int)$inv['request_id'], 'mail_sent', 'Invoice ' . $inv['invoice_number'] . ' sent by email', [
            'body' => 'To: ' . implode(', ', $toList) . ($ccList ? "\nCc: " . implode(', ', $ccList) : '')
                    . "\nSubject: " . $subject . "\nAttachment: " . $filename,
            'refs' => ['invoice_id' => $id]])
        : null;
    return ['to' => $toList, 'cc' => $ccList, 'subject' => $subject, 'attachment' => $filename, 'timeline_event_id' => $eventId];
}

/**
 * Rename the invoice's Dropbox folder to the status $label (a FOLDER_TAG_OPTIONS
 * key), update practice_code / dropbox_url / payment_status, and re-tag the GRP
 * parent. Returns [new_name, new_tag, new_url, group_msg]; RuntimeException on error.
 */
function inv_update_folder_status(PDO $db, int $invId, string $newLabel, ?int $userId = null, string $gateVia = 'status_change'): array {
    if (!array_key_exists($newLabel, FOLDER_TAG_OPTIONS)) throw new InvalidArgumentException('Invalid status selected.');
    $dbTag = FOLDER_TAG_OPTIONS[$newLabel];
    $req   = inv_linked_request($db, $invId);
    if (!$req || !$req['practice_code']) throw new RuntimeException('No linked Dropbox folder found.');

    $oldName = $req['practice_code'];
    $newName = inv_folder_new_name($oldName, $newLabel);
    if ($newName === $oldName) {
        return ['new_name' => $newName, 'new_tag' => $dbTag, 'new_url' => (string)($req['dropbox_url'] ?? ''), 'group_msg' => '', 'unchanged' => true];
    }
    // Out of _PROGRESS: the booking check must have passed (leads/includes/booking_gate_service.php).
    require_once __DIR__ . '/../../leads/includes/booking_gate_service.php';
    $gate = booking_gate_check($db, (int)$req['id'], $oldName, $newName, $userId, $gateVia);
    if ($gate['refused']) throw new BookingGateRefused(booking_gate_message($gate), $gate);

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

    // Resolve the real Dropbox path: by its Dropbox folder id (stable when the folder was moved / renamed
    // outside the Hub), else by searching for the folder name (the stored dropbox_url may have wrong case or subfolder).
    $realFromPath = null;
    if (!empty($req['dropbox_id'])) {
        try {
            $m = dropbox_get_metadata($token, (string)$req['dropbox_id']);
            if ($m && $m['tag'] === 'folder' && strcasecmp($m['name'], $oldName) === 0) $realFromPath = $m['path'];
        } catch (\Throwable $ignored) {}
    }
    if ($realFromPath === null) $realFromPath = dropbox_find_folder($token, $oldName);
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
    // A cancelled folder (no such tag in FOLDER_TAG_OPTIONS today): its Booking follow-up lines close at once.
    require_once __DIR__ . '/../../leads/includes/folder_parser.php';
    if (folder_is_cancelled($newName)) folder_followup_cancelled($db, (int)$req['id'], $newName, 'folder status set on invoice ' . $invId);

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

    timeline_log((int)$req['id'], 'status_change', 'Folder status → ' . $newLabel,
                 ['body' => $oldName . "\n→ " . $newName, 'refs' => ['invoice_id' => $invId]]);
    if ($gate['gated'] && !in_array($gate['state'], ['passed', 'overridden'], true)) $groupMsg = trim($groupMsg . ' ⚠ ' . $gate['reason']);   // shadow mode
    return ['new_name' => $newName, 'new_tag' => $dbTag, 'new_url' => $newUrl, 'group_msg' => $groupMsg, 'unchanged' => false, 'booking_gate' => $gate];
}

// ── PDF (Dompdf, same layout as the emailed / zipped invoices) ───────────────
function inv_pdf(array $inv, array $items, array $activePayments, array $creditNotes = []): string {
    $auto = __DIR__ . '/../../../vendor/autoload.php';
    if (!class_exists('\Dompdf\Dompdf') && is_file($auto)) require_once $auto;
    if (!class_exists('\Dompdf\Dompdf')) throw new RuntimeException('PDF library not installed. Run: composer install');
    require_once __DIR__ . '/invoice_html.php';

    $dompdf = new \Dompdf\Dompdf(inv_doc_dompdf_options());   // no external resources; Open Sans from assets/fonts
    $dompdf->loadHtml(buildInvoiceHtml($inv, $items, $activePayments, $creditNotes));
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
    tl_log_invoice($db, (int)$invId, 'imported');
    return ['invoice_id' => $invId, 'invoice_number' => $invNum];
}
