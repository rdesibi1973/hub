<?php
// modules/memo/memo_lib.php — follow-ups on the Memo Board.
//
//   status 'waiting'   waiting for someone (waiting_on); due_date = follow-up date
//   status 'doing'     in progress
//   status 'pending'   a next step: hidden until its parent memo is done, then
//                      opened with due_date = today + next_offset_days
//   request_id / invoice_id   link to a booking / invoice
//   auto_close = 'payment'    closed when a payment is recorded on invoice_id
//                             (inv_add_payment → memo_on_payment)
//   source / ext_key          who created it ('claude') and a dedupe key
//
// Shared by ajax.php, modules/invoices/includes/invoice_service.php and the
// Agent API. Keep PHP-7 style (no match / str_contains), like the rest of memo/.

const MEMO_STATUSES = array('open', 'doing', 'waiting', 'pending', 'done', 'archived');

/** Add the follow-up columns once (BlueHost MySQL: no ADD COLUMN IF NOT EXISTS). */
function memo_schema(PDO $pdo) {
    static $done = false;
    if ($done) { return; }
    $done = true;
    $st = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'memos' AND COLUMN_NAME = 'ext_key'");
    if ((int)$st->fetchColumn() > 0) { return; }
    $sql = array(
        "ALTER TABLE memos MODIFY status ENUM('open','doing','waiting','pending','done','archived') NOT NULL DEFAULT 'open'",
        "ALTER TABLE memos ADD COLUMN waiting_on VARCHAR(120) NULL AFTER status",
        "ALTER TABLE memos ADD COLUMN request_id INT NULL AFTER waiting_on",
        "ALTER TABLE memos ADD COLUMN invoice_id INT NULL AFTER request_id",
        "ALTER TABLE memos ADD COLUMN auto_close VARCHAR(20) NULL AFTER invoice_id",
        "ALTER TABLE memos ADD COLUMN parent_id INT NULL AFTER auto_close",
        "ALTER TABLE memos ADD COLUMN next_offset_days SMALLINT NULL AFTER parent_id",
        "ALTER TABLE memos ADD COLUMN source VARCHAR(20) NULL AFTER next_offset_days",
        "ALTER TABLE memos ADD COLUMN ext_key VARCHAR(120) NULL AFTER source",
        "ALTER TABLE memos ADD INDEX idx_memo_invoice (invoice_id)",
        "ALTER TABLE memos ADD INDEX idx_memo_request (request_id)",
        "ALTER TABLE memos ADD INDEX idx_memo_parent (parent_id)",
        "ALTER TABLE memos ADD INDEX idx_memo_ext (user_id, ext_key)",
    );
    foreach ($sql as $q) {
        try { $pdo->exec($q); } catch (PDOException $ignored) {}
    }
}

// ── Routines: recurring checks shown on top of the board ─────────────────────
// key => title, every (hours), link, hint. A routine is "due" when it was last
// done more than `every` hours ago; routines with a live count (memo_routine_count)
// also show how many items are waiting. Shown to admin / manager only.
const MEMO_ROUTINES = array(
    'leads'    => array('title' => 'Assign Incoming Leads',          'every' => 3,
                        'link'  => '../leads/staging.php',
                        'hint'  => 'Incoming Leads (HubSpot) waiting to be assigned.'),
    'mail'     => array('title' => 'Check mail for new requests',    'every' => 3,
                        'link'  => 'https://mail.google.com/',
                        'hint'  => 'Gmail + Bluehost: new requests → create and assign them.'),
    'payments' => array('title' => 'Payments to request',            'every' => 72,
                        'link'  => '../leads/payments.php',
                        'hint'  => 'Bookings with deposit / balance still to be paid.'),
    'afrasia'  => array('title' => 'Check the AfrAsia account',      'every' => 72,
                        'link'  => '../invoices/invoices.php?issuer=Savannah+Holidays+Ltd&unpaid=1&year=0',
                        'hint'  => 'Savannah Holidays invoices with an open balance: check the bank yourself.'),
);

function memo_routine_schema(PDO $pdo) {
    static $done = false;
    if ($done) { return; }
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS memo_routine_log (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        routine_key VARCHAR(30) NOT NULL,
        done_at     DATETIME NOT NULL,
        note        VARCHAR(255) NULL,
        source      VARCHAR(20) NULL,
        KEY idx_routine (user_id, routine_key, done_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Live count for a routine, or null when it has none. */
function memo_routine_count(PDO $pdo, $key) {
    try {
        if ($key === 'leads') {
            return (int)$pdo->query("SELECT COUNT(*) FROM lead_staging")->fetchColumn();
        }
        if ($key === 'afrasia') {
            return (int)$pdo->query("SELECT COUNT(*) FROM invoices
                                     WHERE issuer = 'Savannah Holidays Ltd' AND status <> 'Cancelled' AND balance_due > 0.005")->fetchColumn();
        }
    } catch (PDOException $e) { /* table missing: no count */ }
    return null;
}

/**
 * The routines for a user: [{key, title, hint, link, every_hours, last_done, last_note,
 * hours_since, due, count, attention}]. attention = due, or items waiting (leads).
 */
function memo_routines_status(PDO $pdo, $userId) {
    memo_routine_schema($pdo);
    $last = $pdo->prepare("SELECT done_at, note, source FROM memo_routine_log
                           WHERE user_id = ? AND routine_key = ? ORDER BY done_at DESC LIMIT 1");
    $out = array();
    foreach (MEMO_ROUTINES as $key => $r) {
        $last->execute(array((int)$userId, $key));
        $l     = $last->fetch(PDO::FETCH_ASSOC);
        $hours = $l ? (time() - strtotime($l['done_at'])) / 3600 : null;
        $due   = $hours === null || $hours >= $r['every'];
        $count = memo_routine_count($pdo, $key);
        $out[] = array(
            'key' => $key, 'title' => $r['title'], 'hint' => $r['hint'], 'link' => $r['link'],
            'every_hours' => $r['every'],
            'last_done' => $l ? $l['done_at'] : null, 'last_note' => $l ? $l['note'] : null,
            'last_source' => $l ? $l['source'] : null,
            'hours_since' => $hours === null ? null : round($hours, 1),
            'due' => $due, 'count' => $count,
            'attention' => $due || ($key === 'leads' && $count > 0),
        );
    }
    return $out;
}

/** Record a routine as done now. False for an unknown key. */
function memo_routine_done(PDO $pdo, $userId, $key, $note = '', $source = null) {
    if (!isset(MEMO_ROUTINES[$key])) { return false; }
    memo_routine_schema($pdo);
    $pdo->prepare("INSERT INTO memo_routine_log (user_id, routine_key, done_at, note, source) VALUES (?, ?, ?, ?, ?)")
        ->execute(array((int)$userId, $key, date('Y-m-d H:i:s'), trim((string)$note) !== '' ? mb_substr(trim($note), 0, 255) : null, $source));
    return true;
}

/** Plain text → the HTML the board stores (paragraphs, escaped). */
function memo_text_to_html($text) {
    $text = trim((string)$text);
    if ($text === '') { return ''; }
    $out = '';
    foreach (preg_split('/\r?\n/', $text) as $line) {
        $out .= '<p>' . ($line === '' ? '<br>' : htmlspecialchars($line, ENT_QUOTES, 'UTF-8')) . '</p>';
    }
    return $out;
}

/** Open the pending next steps of a memo (due = today + their offset, unless set). */
function memo_open_next(PDO $pdo, $parentId) {
    $now = date('Y-m-d H:i:s');
    $st  = $pdo->prepare("SELECT id, due_date, next_offset_days FROM memos
                          WHERE parent_id = ? AND status = 'pending' AND deleted_at IS NULL");
    $st->execute(array((int)$parentId));
    $opened = array();
    $up = $pdo->prepare("UPDATE memos SET status = 'open', due_date = ?, updated_at = ? WHERE id = ?");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $due = $c['due_date'];
        if (!$due) { $due = date('Y-m-d', strtotime('+' . max(0, (int)$c['next_offset_days']) . ' days')); }
        $up->execute(array($due, $now, (int)$c['id']));
        $opened[] = (int)$c['id'];
    }
    return $opened;
}

/** Mark a memo done (optionally appending a note) and open its next steps. */
function memo_close(PDO $pdo, $id, $note = '') {
    $now  = date('Y-m-d H:i:s');
    $note = trim((string)$note);
    if ($note !== '') {
        $pdo->prepare("UPDATE memos SET status = 'done', body = CONCAT(COALESCE(body, ''), ?), updated_at = ? WHERE id = ?")
            ->execute(array('<p><em>' . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . '</em></p>', $now, (int)$id));
    } else {
        $pdo->prepare("UPDATE memos SET status = 'done', updated_at = ? WHERE id = ?")->execute(array($now, (int)$id));
    }
    return memo_open_next($pdo, $id);
}

/**
 * A payment was recorded on an invoice: close the memos waiting for it
 * (auto_close = 'payment') and open their next steps.
 * Returns [{id, title, opened_next: [ids]}].
 */
function memo_on_payment(PDO $pdo, $invoiceId, $amount, $currency, $date, $ref) {
    memo_schema($pdo);
    $st = $pdo->prepare("SELECT id, title FROM memos
                         WHERE invoice_id = ? AND auto_close = 'payment' AND deleted_at IS NULL
                           AND status IN ('open','doing','waiting')");
    $st->execute(array((int)$invoiceId));
    $note = 'Payment recorded: ' . ($currency === 'EUR' ? '€' : '$') . number_format((float)$amount, 2)
          . ' on ' . $date . ($ref !== '' ? ' (ref ' . $ref . ')' : '') . ' — closed automatically.';
    $closed = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $closed[] = array('id' => (int)$m['id'], 'title' => $m['title'], 'opened_next' => memo_close($pdo, $m['id'], $note));
    }
    return $closed;
}

/**
 * Replace the pending next steps of a memo with $steps
 * ([{title, body?, days_after?}]). Steps inherit owner and links.
 */
function memo_set_next_steps(PDO $pdo, $parentId, array $steps) {
    $now = date('Y-m-d H:i:s');
    $st  = $pdo->prepare("SELECT user_id, request_id, invoice_id, source, status FROM memos WHERE id = ?");
    $st->execute(array((int)$parentId));
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) { return; }
    $pdo->prepare("UPDATE memos SET deleted_at = ?, updated_at = ? WHERE parent_id = ? AND status = 'pending' AND deleted_at IS NULL")
        ->execute(array($now, $now, (int)$parentId));
    $ins = $pdo->prepare(
        "INSERT INTO memos (user_id, title, body, type, status, priority, pinned, color, due_date, reminder_at, reminder_emails,
                            reminder_sent, recur_rule, sort_order, request_id, invoice_id, parent_id, next_offset_days, source,
                            created_at, updated_at)
         VALUES (?, ?, ?, 'todo', 'pending', 'normal', 0, NULL, NULL, NULL, NULL, 0, 'none', 0, ?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($steps as $s) {
        $title = trim(isset($s['title']) ? (string)$s['title'] : '');
        if ($title === '') { continue; }
        $days = isset($s['days_after']) && $s['days_after'] !== '' ? max(0, min(365, (int)$s['days_after'])) : 0;
        $body = isset($s['body_html']) ? (string)$s['body_html'] : memo_text_to_html(isset($s['body']) ? $s['body'] : '');
        $ins->execute(array((int)$p['user_id'], mb_substr($title, 0, 255), $body,
                            $p['request_id'], $p['invoice_id'], (int)$parentId, $days, $p['source'], $now, $now));
    }
    // A parent that is already done opens its new steps right away.
    if ($p['status'] === 'done') { memo_open_next($pdo, $parentId); }
}

/** Invoice id (+ its request_id) from an id or an invoice number; null if not found. */
function memo_find_invoice(PDO $pdo, $idOrNumber) {
    $v = trim((string)$idOrNumber);
    if ($v === '') { return null; }
    $st = ctype_digit($v)
        ? $pdo->prepare("SELECT id, request_id FROM invoices WHERE id = ?")
        : $pdo->prepare("SELECT id, request_id FROM invoices WHERE invoice_number = ?");
    $st->execute(array($v));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? $r : null;
}

/** SELECT list + JOINs adding the request / invoice labels to memo rows (alias m). */
function memo_link_columns() {
    return "lr.customer_name AS req_customer, lr.practice_code AS req_folder,
            li.invoice_number AS inv_number, li.issuer AS inv_issuer, li.currency AS inv_currency,
            li.balance_due AS inv_balance, li.status AS inv_status";
}
function memo_link_joins() {
    return " LEFT JOIN requests lr ON lr.id = m.request_id LEFT JOIN invoices li ON li.id = m.invoice_id ";
}
