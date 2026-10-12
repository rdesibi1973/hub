<?php
/**
 * payments_service.php — the bookings still to be paid, shared by the Payments
 * page (payments.php) and the Agent API (payments_due).
 *
 *   pay_unsettled_rows()  confirmed requests whose payment stage is Deposit /
 *                         Balance / Balance-Cash (the Payments page list)
 *   pay_due_report()      the same rows with invoices, payments and due dates
 *
 * Due dates (no stored due date on the page — the rule from the T&C):
 *   deposit 30% at confirmation; balance 60 days before the start for direct
 *   clients, 45 for agencies; Il Diamante: no deposit, balance 30 days before.
 * Needs folder_parser.php.
 */
require_once __DIR__ . '/folder_parser.php';

/** Payment statuses that count as "not yet settled". */
const PAY_UNPAID_STATUSES = ['Deposit', 'Balance', 'Balance-Cash'];
const PAY_DEPOSIT_PCT     = 0.30;
const PAY_SLEEPING_DAYS   = 7;

/**
 * Confirmed, not cancelled requests still to be paid, with start_date / end_date /
 * start_ts (from the folder) and _ps_derived (payment_status, else the folder tag).
 */
function pay_unsettled_rows(PDO $db): array {
    $rows = $db->query(
        "SELECT r.id, r.customer_name, r.destination, r.pax,
                -- Bookings imported from a Dropbox folder have no email: fall back to
                -- the latest email on another request for the same client.
                COALESCE(NULLIF(TRIM(r.email), ''),
                         (SELECT r2.email FROM requests r2
                           WHERE r2.customer_name = r.customer_name
                             AND r2.email IS NOT NULL AND TRIM(r2.email) <> ''
                           ORDER BY r2.id DESC LIMIT 1)) AS email,
                r.group_folder, r.practice_code, r.period, r.status, r.agent_id, r.channel, r.agency_id,
                r.source, r.payment_status, r.date_received, r.confirmation_date, r.dropbox_url,
                a.name AS agent_name,
                (SELECT u.email FROM users u
                   WHERE u.agent_id = r.agent_id ORDER BY u.id LIMIT 1) AS agent_email,
                -- Notes = manual notes + logged sent emails (same set as the notes popup).
                (SELECT COUNT(*) FROM request_notes rn
                   WHERE rn.request_id = r.id
                     AND (rn.note_type <> 'manual' OR (rn.body IS NOT NULL AND TRIM(rn.body) <> ''))) AS note_count,
                -- \"type|text\": the body for a manual note, the subject for a sent email.
                (SELECT CONCAT(rn2.note_type, '|', COALESCE(IF(rn2.note_type = 'manual', rn2.body, rn2.subject), ''))
                   FROM request_notes rn2
                   WHERE rn2.request_id = r.id
                     AND (rn2.note_type <> 'manual' OR (rn2.body IS NOT NULL AND TRIM(rn2.body) <> ''))
                   ORDER BY rn2.created_at DESC, rn2.id DESC LIMIT 1) AS last_note_raw,
                (SELECT u3.full_name FROM request_notes rn3
                   LEFT JOIN users u3 ON u3.id = rn3.created_by
                   WHERE rn3.request_id = r.id
                     AND (rn3.note_type <> 'manual' OR (rn3.body IS NOT NULL AND TRIM(rn3.body) <> ''))
                   ORDER BY rn3.created_at DESC, rn3.id DESC LIMIT 1) AS last_note_by
         FROM requests r
         LEFT JOIN agents a ON a.id = r.agent_id
         WHERE r.status IN ('Booked','Paid','Balance','Deposit')
           AND (r.payment_status IS NULL OR r.payment_status != 'Cancelled')
         ORDER BY r.id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        [$nType, $nText] = array_pad(explode('|', (string)($row['last_note_raw'] ?? ''), 2), 2, '');
        $row['last_note'] = $nType === 'email_sent' ? '📧 ' . $nText : $nText;
        $d = parse_folder_dates(get_date_folder($row));
        $row['start_date'] = $d['start_date'];
        $row['end_date']   = $d['end_date'];
        $row['start_ts']   = $d['start_ts'];
        // Per-request payment status is authoritative. When it is NULL, fall back to
        // the folder tag (display only): for a private safari that's its own folder,
        // and for a GRP it's the main group folder (folder_payment_status → group_folder).
        $row['_ps_derived'] = $row['payment_status'] ?: folder_payment_status($row);
    }
    unset($row);

    // Keep only NOT-yet-settled pratiche (per-request status).
    return array_values(array_filter($rows, fn($r) => in_array($r['_ps_derived'], PAY_UNPAID_STATUSES, true)));
}

/** 'YYYY-MM-DD' → the date $days later (negative = before); null stays null. */
function pay_add_days(?string $date, int $days): ?string {
    if (!$date) return null;
    return date('Y-m-d', strtotime(substr($date, 0, 10) . ' ' . sprintf('%+d', $days) . ' days'));
}

/** Whole days from $a to $b (dates), null if one is missing. */
function pay_days_between(?string $a, ?string $b): ?int {
    if (!$a || !$b) return null;
    return (int)round((strtotime(substr($b, 0, 10)) - strtotime(substr($a, 0, 10))) / 86400);
}

/** 'SE' / 'SH' from an invoice issuer name. */
function pay_issuer_code(?string $issuer): ?string {
    if ($issuer === null || $issuer === '') return null;
    return $issuer === 'Savannah Explorers Ltd' ? 'SE' : 'SH';
}

/**
 * Due-date rule for a request: ['client' => direct|agency|diamante, 'deposit_pct', 'balance_days'].
 * Agency = channel "agency", or (no channel) a folder tag without "Drct".
 */
function pay_due_rule(array $r): array {
    $tag = folder_agency($r);
    if (stripos($tag, 'diamante') !== false) return ['client' => 'diamante', 'deposit_pct' => 0.0, 'balance_days' => 30];
    $ch = strtolower(trim((string)($r['channel'] ?? '')));
    $agency = $ch !== '' ? $ch === 'agency' : ($tag !== '' && stripos($tag, 'drct') === false);
    return $agency ? ['client' => 'agency', 'deposit_pct' => PAY_DEPOSIT_PCT, 'balance_days' => 45]
                   : ['client' => 'direct', 'deposit_pct' => PAY_DEPOSIT_PCT, 'balance_days' => 60];
}

/**
 * Payments to collect, as data (Agent API payments_due).
 * $opt: days (default 14: next due date within N days, overdue included), overdue (bool: only
 *       overdue), stage (Deposit|Balance|Balance-Cash), issuer (SE|SH), today (YYYY-MM-DD).
 * → ['rows' => [...], 'sleeping' => [...], 'no_due_date' => [...], 'counts' => [...]]
 */
function pay_due_report(PDO $db, array $opt = []): array {
    $today   = $opt['today'] ?? date('Y-m-d');
    $days    = isset($opt['days']) ? max(0, (int)$opt['days']) : 14;
    $limit   = pay_add_days($today, $days);
    $onlyOver = !empty($opt['overdue']);
    $stageF  = (string)($opt['stage'] ?? '');
    $issuerF = strtoupper((string)($opt['issuer'] ?? ''));

    $rows = pay_unsettled_rows($db);
    $ids  = array_map(fn($r) => (int)$r['id'], $rows);
    $in   = $ids ? implode(',', array_fill(0, count($ids), '?')) : '';

    // Invoices (not cancelled) per request, with their latest active payment.
    $inv = []; $invIds = [];
    if ($ids) {
        $st = $db->prepare("SELECT i.id, i.request_id, i.invoice_number, i.issuer, i.currency, i.total, i.amount_paid, i.balance_due,
                                   i.issue_date, i.due_date, i.status, i.updated_at,
                                   (SELECT MAX(p.payment_date) FROM invoice_payments p
                                     WHERE p.invoice_id = i.id AND p.cancelled_at IS NULL) AS last_payment_date
                            FROM invoices i
                            WHERE i.request_id IN ($in) AND i.status <> 'Cancelled'
                            ORDER BY i.id");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $inv[(int)$i['request_id']][] = $i;
            $invIds[] = (int)$i['id'];
        }
    }

    // Open payment memos (auto-close on payment) on those invoices — the SH follow-ups.
    $memo = [];
    if ($invIds) {
        try {
            $inI = implode(',', array_fill(0, count($invIds), '?'));
            $st = $db->prepare("SELECT id, invoice_id FROM memos
                                WHERE invoice_id IN ($inI) AND auto_close = 'payment' AND deleted_at IS NULL
                                  AND status IN ('open','doing','waiting') ORDER BY id");
            $st->execute($invIds);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) $memo[(int)$m['invoice_id']] ??= (int)$m['id'];
        } catch (Throwable $e) { /* memo columns not created yet */ }
    }

    // Last contact: latest mail sent (timeline, else the Payments notes).
    $contact = [];
    if ($ids) {
        try {
            $st = $db->prepare("SELECT request_id, MAX(event_at) FROM request_timeline
                                WHERE request_id IN ($in) AND event_type = 'mail_sent' AND hidden = 0 GROUP BY request_id");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as [$rid, $at]) $contact[(int)$rid] = $at;
        } catch (Throwable $e) { /* timeline table not created yet */ }
        $st = $db->prepare("SELECT request_id, MAX(created_at) FROM request_notes
                            WHERE request_id IN ($in) AND note_type = 'email_sent' GROUP BY request_id");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$rid, $at]) {
            if (!isset($contact[(int)$rid]) || $at > $contact[(int)$rid]) $contact[(int)$rid] = $at;
        }
    }

    $out = []; $sleeping = []; $noDate = [];
    foreach ($rows as $r) {
        $rid   = (int)$r['id'];
        $stage = $r['_ps_derived'];
        $invs  = $inv[$rid] ?? [];
        $rule  = pay_due_rule($r);
        $total = $paid = $balance = 0.0;
        $lastPay = null; $lastInvAct = null; $memoId = null;
        foreach ($invs as $i) {
            $total   += (float)$i['total'];
            $paid    += (float)$i['amount_paid'];
            $balance += (float)$i['balance_due'];
            if ($i['last_payment_date'] && $i['last_payment_date'] > (string)$lastPay) $lastPay = $i['last_payment_date'];
            $act = max((string)$i['updated_at'], (string)$i['issue_date']);
            if ($act > (string)$lastInvAct) $lastInvAct = $act;
            $memoId = $memoId ?? ($memo[(int)$i['id']] ?? null);
        }
        $first   = $invs[0] ?? null;
        $issuer  = $first ? pay_issuer_code($first['issuer']) : null;
        $conf    = $r['confirmation_date'] ? substr($r['confirmation_date'], 0, 10) : null;
        $sinceConf = pay_days_between($conf, $today);

        $depDue     = $invs ? round($total * $rule['deposit_pct'], 2) : null;
        $depDueDate = $rule['deposit_pct'] > 0 ? $conf : null;
        $balDueDate = pay_add_days($r['start_date'], -$rule['balance_days']);
        // Folder at _DEPOSIT → the deposit is next, unless the invoices already cover it.
        $depCovered = $rule['deposit_pct'] <= 0 || ($invs && $paid + 0.5 >= (float)$depDue);
        $nextKind   = ($stage === 'Deposit' && !$depCovered) ? 'deposit' : 'balance';
        $nextDate   = $nextKind === 'deposit' ? $depDueDate : $balDueDate;
        $toDue      = pay_days_between($today, $nextDate);
        $notes      = [];
        if ($stage === 'Deposit' && $depCovered && $rule['deposit_pct'] > 0) $notes[] = 'Deposit covered by the invoice payments but the folder is still _DEPOSIT.';
        if (!$invs) $notes[] = 'No invoice for this booking.';
        if ($invs && count(array_unique(array_column($invs, 'currency'))) > 1) $notes[] = 'Invoices in more than one currency — totals are summed as is.';

        $row = [
            'request_id'              => $rid,
            'folder'                  => trim($r['group_folder'] ?? '') !== '' ? $r['group_folder'] : $r['practice_code'],
            'client_folder'           => $r['practice_code'],
            'customer'                => $r['customer_name'],
            'agency'                  => folder_agency($r) ?: null,
            'agent'                   => $r['agent_name'],
            'client_type'             => $rule['client'],
            'start_date'              => $r['start_date'],
            'stage'                   => $stage,
            'confirmation_date'       => $conf,
            'days_since_confirmation' => $sinceConf,
            'invoice_number'          => $invs ? implode(', ', array_column($invs, 'invoice_number')) : null,
            'invoice_ids'             => array_map(fn($i) => (int)$i['id'], $invs),
            'issuer'                  => $issuer,
            'currency'                => $first['currency'] ?? null,
            'total'                   => $invs ? round($total, 2) : null,
            'paid'                    => $invs ? round($paid, 2) : null,
            'balance'                 => $invs ? round($balance, 2) : null,
            'deposit_due'             => $depDue,
            'deposit_due_date'        => $depDueDate,
            'balance_due_date'        => $balDueDate,
            'invoice_due_date'        => $first['due_date'] ?? null,
            'next_due'                => $nextKind,
            'next_due_date'           => $nextDate,
            'days_to_due'             => $toDue,
            'overdue_days'            => $toDue !== null && $toDue < 0 ? -$toDue : 0,
            'last_payment_date'       => $lastPay,
            'last_contact'            => $contact[$rid] ?? null,
            'memo_id'                 => $memoId,
            'notes'                   => $notes,
        ];

        // Sleeping: confirmed with no invoice, or still at Deposit with no payment and no invoice activity for a week.
        if ($conf !== null && $sinceConf !== null && $sinceConf > PAY_SLEEPING_DAYS) {
            if (!$invs) {
                $sleeping[] = ['reason' => 'no_invoice'] + array_intersect_key($row, array_flip(['request_id', 'folder', 'customer', 'agency', 'agent', 'start_date', 'stage', 'confirmation_date', 'days_since_confirmation', 'last_contact']));
            } elseif ($stage === 'Deposit' && $lastPay === null
                      && ($lastInvAct === null || pay_days_between($lastInvAct, $today) > PAY_SLEEPING_DAYS)) {
                $sleeping[] = ['reason' => 'deposit_no_payment', 'last_invoice_activity' => $lastInvAct ? substr($lastInvAct, 0, 10) : null]
                            + array_intersect_key($row, array_flip(['request_id', 'folder', 'customer', 'agency', 'agent', 'start_date', 'stage', 'confirmation_date', 'days_since_confirmation', 'invoice_number', 'issuer', 'total', 'last_contact', 'memo_id']));
            }
        }

        if ($stageF !== '' && strcasecmp($stage, $stageF) !== 0) continue;
        if ($issuerF !== '' && $issuer !== $issuerF) continue;
        if ($nextDate === null) {
            $noDate[] = ['request_id' => $rid, 'folder' => $row['folder'], 'customer' => $row['customer'], 'stage' => $stage,
                         'reason' => $nextKind === 'deposit' ? 'no confirmation_date' : 'no start date in the folder name'];
            continue;
        }
        if ($onlyOver ? $toDue >= 0 : $nextDate > $limit) continue;
        $out[] = $row;
    }
    usort($out, fn($a, $b) => [$a['next_due_date'], $a['start_date'] ?? '9999'] <=> [$b['next_due_date'], $b['start_date'] ?? '9999']);
    usort($sleeping, fn($a, $b) => $b['days_since_confirmation'] <=> $a['days_since_confirmation']);

    return [
        'today'       => $today,
        'rule'        => 'deposit 30% at confirmation; balance 60 days before start (direct), 45 (agency); Il Diamante: no deposit, balance 30 days before',
        'counts'      => ['unsettled' => count($rows), 'rows' => count($out),
                          'overdue' => count(array_filter($out, fn($x) => $x['overdue_days'] > 0)),
                          'sleeping' => count($sleeping), 'no_due_date' => count($noDate)],
        'rows'        => $out,
        'sleeping'    => $sleeping,
        'no_due_date' => $noDate,
    ];
}
