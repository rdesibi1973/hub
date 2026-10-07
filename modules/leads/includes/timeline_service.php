<?php
/**
 * timeline_service.php — running history + "where are we" summary per request,
 * shared by request_view.php (Timeline section), requests.php (Last activity)
 * and the Agent API (timeline_list / timeline_add / timeline_update / summary_set /
 * request_resume). Spec: docs/AGENT_API.md.
 *
 *   request_timeline : append-only events (user / claude / system); hidden = soft delete
 *   request_summary  : one editable summary per request; the previous text is kept
 *                      as a "Summary updated" note event, so nothing is lost
 *   request_notes    : the older notes / sent-email log, shown merged in the list
 *
 * System events come from timeline_log(), called by the shared services (create
 * request, copy program, Calc, confirm, ITI, invoices, payments, folder status).
 * Logging never breaks the action that called it.
 *
 * Keep PHP-7 compatible (included from booking_service.php / iti_final.php).
 */

/** event_type => [label, badge colours (background, text, border)]. */
const TL_TYPES = [
    'note'           => ['Note',            '#F0F0F0', '#555555', '#DDDDDD'],
    'client_request' => ['Client request',  '#E3EEFB', '#1F5FA8', '#C5DBF5'],
    'quote_sent'     => ['Quote sent',      '#E3F3E6', '#2E6B3E', '#BFE2C6'],
    'program_update' => ['Program',         '#EEF8EF', '#3F7F4D', '#D3ECD7'],
    'calc_update'    => ['Calc',            '#EEF8EF', '#3F7F4D', '#D3ECD7'],
    'mail_sent'      => ['Mail sent',       '#EEF5FD', '#3A6EA5', '#D6E6F8'],
    'mail_received'  => ['Mail received',   '#EEF5FD', '#3A6EA5', '#D6E6F8'],
    'call'           => ['Call',            '#F0F0F0', '#555555', '#DDDDDD'],
    'confirmation'   => ['Confirmation',    '#C0211B', '#FFFFFF', '#C0211B'],
    'invoice'        => ['Invoice',         '#FBF3DC', '#8A6A12', '#EEDCA6'],
    'payment'        => ['Payment',         '#FBF3DC', '#8A6A12', '#EEDCA6'],
    'supplier'       => ['Supplier',        '#F3EBE3', '#7A5233', '#E2D2C2'],
    'status_change'  => ['Status',          '#F0F0F0', '#555555', '#DDDDDD'],
    'issue'          => ['Issue',           '#FFFFFF', '#C0211B', '#C0211B'],
];
const TL_WAITING_ON = ['client', 'agency', 'supplier', 'us'];
const TL_SUMMARY_MAX = 1500;

/** Tables (also in migrations/065_request_timeline.sql): created on first use. */
function tl_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS request_timeline (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        request_id    INT NOT NULL,
        event_at      DATETIME NOT NULL,
        event_type    VARCHAR(30) NOT NULL,
        title         VARCHAR(200) NOT NULL,
        body          TEXT NULL,
        next_step     VARCHAR(500) NULL,
        author_type   ENUM('user','claude','system') NOT NULL DEFAULT 'user',
        user_id       INT NULL,
        source        VARCHAR(20) NOT NULL DEFAULT 'hub',
        session_url   VARCHAR(500) NULL,
        refs          JSON NULL,
        pinned        TINYINT(1) NOT NULL DEFAULT 0,
        hidden        TINYINT(1) NOT NULL DEFAULT 0,
        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_req_time (request_id, event_at),
        KEY idx_type (event_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS request_summary (
        request_id      INT NOT NULL PRIMARY KEY,
        summary         TEXT NOT NULL,
        next_step       VARCHAR(500) NULL,
        waiting_on      VARCHAR(30) NULL,
        updated_by_type ENUM('user','claude') NOT NULL,
        updated_by      INT NULL,
        session_url     VARCHAR(500) NULL,
        updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/**
 * Who triggers system events in this request: the Agent API sets
 * $GLOBALS['TL_ACTOR'] = ['user_id' => …, 'source' => 'api']; Hub pages use the session user.
 */
function tl_actor(): array {
    if (!empty($GLOBALS['TL_ACTOR']) && is_array($GLOBALS['TL_ACTOR'])) return $GLOBALS['TL_ACTOR'] + ['user_id' => null, 'source' => 'auto'];
    $uid = null;
    if (function_exists('current_user') && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
        $cu = current_user();
        $uid = !empty($cu['id']) ? (int)$cu['id'] : null;
    }
    return ['user_id' => $uid, 'source' => 'auto'];
}

/**
 * Log a system event (author 'system'). Never throws: failures go to error_log.
 * $opts: body, next_step, refs (array), event_at, pinned. Same request + type + title
 * within 60 s is not logged twice. Returns the event id, or null.
 */
function timeline_log(int $requestId, string $type, string $title, array $opts = []): ?int {
    if ($requestId <= 0 || !isset(TL_TYPES[$type])) return null;
    try {
        $db = db();
        tl_schema($db);
        $title = tl_cut($title, 200);
        // created_at is written by PHP (EAT), not by MySQL (server time zone), so it compares.
        $st = $db->prepare("SELECT id FROM request_timeline WHERE request_id = ? AND event_type = ? AND title = ?
                            AND created_at >= ? LIMIT 1");
        $st->execute([$requestId, $type, $title, date('Y-m-d H:i:s', time() - 60)]);
        if ($dup = $st->fetchColumn()) return (int)$dup;
        $actor = tl_actor();
        $refs  = isset($opts['refs']) && is_array($opts['refs']) ? array_filter($opts['refs'], function ($v) { return $v !== null && $v !== ''; }) : [];
        $db->prepare("INSERT INTO request_timeline (request_id, event_at, event_type, title, body, next_step, author_type, user_id, source, refs, pinned, created_at)
                      VALUES (?,?,?,?,?,?,'system',?,?,?,?,?)")
           ->execute([$requestId, (string)($opts['event_at'] ?? date('Y-m-d H:i:s')), $type, $title,
                      isset($opts['body']) && trim((string)$opts['body']) !== '' ? tl_cut((string)$opts['body'], 20000) : null,
                      isset($opts['next_step']) && trim((string)$opts['next_step']) !== '' ? tl_cut((string)$opts['next_step'], 500) : null,
                      $actor['user_id'], 'auto', $refs ? json_encode($refs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                      !empty($opts['pinned']) ? 1 : 0, date('Y-m-d H:i:s')]);
        return (int)$db->lastInsertId();
    } catch (Throwable $e) {
        error_log('timeline_log(' . $requestId . ', ' . $type . ') failed: ' . $e->getMessage());
        return null;
    }
}

/** The Hub request linked to an invoice (0 if none). */
function tl_invoice_request(PDO $db, int $invId): int {
    $st = $db->prepare("SELECT request_id FROM invoices WHERE id = ?");
    $st->execute([$invId]);
    return (int)($st->fetchColumn() ?: 0);
}

/** Invoice event for request-linked invoices: "<number> created / updated / cancelled". */
function tl_log_invoice(PDO $db, int $invId, string $what, array $extraRefs = []): void {
    try {
        $st = $db->prepare("SELECT request_id, invoice_number, total, amount_paid, balance_due, currency, status FROM invoices WHERE id = ?");
        $st->execute([$invId]);
        $i = $st->fetch(PDO::FETCH_ASSOC);
        if (!$i || !(int)$i['request_id']) return;
        $type = in_array($what, ['payment recorded', 'payment cancelled'], true) ? 'payment' : 'invoice';
        timeline_log((int)$i['request_id'], $type, 'Invoice ' . $i['invoice_number'] . ' — ' . $what,
            ['body' => 'Total ' . $i['currency'] . ' ' . number_format((float)$i['total'], 2) . ' · paid ' . number_format((float)$i['amount_paid'], 2)
                       . ' · balance ' . number_format((float)$i['balance_due'], 2) . ' · ' . $i['status'],
             'refs' => $extraRefs + ['invoice_id' => $invId, 'invoice_number' => $i['invoice_number']]]);
    } catch (Throwable $e) {
        error_log('tl_log_invoice(' . $invId . ') failed: ' . $e->getMessage());
    }
}

function tl_cut(string $s, int $max): string {
    $s = trim($s);
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

function tl_len(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}

/** 'YYYY-MM-DD[ HH:MM[:SS]]' / ISO 'T' → 'Y-m-d H:i:s', or null. */
function tl_datetime($v): ?string {
    $v = trim(str_replace('T', ' ', (string)$v));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $v)) return null;
    $ts = strtotime($v);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

/** A passport-like number next to "passport / passaporto" → warning text (never blocks). */
function tl_passport_warning(string $text): ?string {
    if (preg_match('/(passport|passaporto|passeport|pasaporte|reisepass)\W{0,3}(\w+\W{1,3}){0,4}?[A-Z]{1,2}\s?\d{7,9}\b/iu', $text)
     || preg_match('/\b[A-Z]{1,2}\s?\d{7,9}\b\W{0,3}(\w+\W{1,3}){0,3}?(passport|passaporto|passeport|pasaporte|reisepass)/iu', $text)) {
        return 'The text seems to contain a passport number — passport numbers must not be stored in the Hub. Remove it before saving.';
    }
    return null;
}

/** session_url: empty, or https://claude.ai/… (else error text). */
function tl_session_url($v, array &$errors): ?string {
    $v = trim((string)$v);
    if ($v === '') return null;
    if (strpos($v, 'https://claude.ai/') !== 0) { $errors[] = 'session_url must start with https://claude.ai/'; return null; }
    return tl_cut($v, 500);
}

/**
 * Validate event input. $partial = only the keys present (update).
 * Returns ['fields' => column => value, 'errors' => [], 'warnings' => []].
 */
function tl_event_input(array $in, bool $partial = false): array {
    $f = []; $errors = []; $warnings = [];
    $has = function ($k) use ($in, $partial) { return !$partial || array_key_exists($k, $in); };
    if ($has('event_type')) {
        $t = trim((string)($in['event_type'] ?? ''));
        if (!isset(TL_TYPES[$t])) $errors[] = 'event_type must be one of: ' . implode(', ', array_keys(TL_TYPES));
        else $f['event_type'] = $t;
    }
    if ($has('title')) {
        $t = trim((string)($in['title'] ?? ''));
        if ($t === '') $errors[] = 'title is required';
        elseif (tl_len($t) > 200) $errors[] = 'title is longer than 200 characters';
        else $f['title'] = $t;
    }
    if (array_key_exists('body', $in))      $f['body'] = trim((string)$in['body']) !== '' ? tl_cut((string)$in['body'], 20000) : null;
    if (array_key_exists('next_step', $in)) $f['next_step'] = trim((string)$in['next_step']) !== '' ? tl_cut((string)$in['next_step'], 500) : null;
    if (array_key_exists('event_at', $in) && trim((string)$in['event_at']) !== '') {
        $d = tl_datetime($in['event_at']);
        if ($d === null) $errors[] = 'event_at must be "YYYY-MM-DD HH:MM" (EAT)';
        else $f['event_at'] = $d;
    } elseif (!$partial) {
        $f['event_at'] = date('Y-m-d H:i:s');
    }
    if (array_key_exists('session_url', $in)) $f['session_url'] = tl_session_url($in['session_url'], $errors);
    if (array_key_exists('refs', $in)) {
        if ($in['refs'] !== null && $in['refs'] !== '' && !is_array($in['refs'])) $errors[] = 'refs must be an object';
        else $f['refs'] = is_array($in['refs']) && $in['refs'] ? json_encode($in['refs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    }
    if (array_key_exists('pinned', $in)) $f['pinned'] = !empty($in['pinned']) ? 1 : 0;
    $w = tl_passport_warning(($f['title'] ?? '') . "\n" . ($f['body'] ?? '') . "\n" . ($f['next_step'] ?? ''));
    if ($w) $warnings[] = $w;
    return ['fields' => $f, 'errors' => $errors, 'warnings' => $warnings];
}

/**
 * Add a user / Claude event. $author = ['type' => 'user'|'claude', 'user_id' => int, 'source' => 'hub'|'api'|'cowork'].
 * Returns the new event id. Throws InvalidArgumentException on bad input.
 */
function tl_add(PDO $db, int $requestId, array $in, array $author): int {
    tl_schema($db);
    $v = tl_event_input($in);
    if ($v['errors']) throw new InvalidArgumentException(implode(' ', $v['errors']));
    $f = $v['fields'] + ['request_id' => $requestId,
                         'author_type' => ($author['type'] ?? 'user') === 'claude' ? 'claude' : 'user',
                         'user_id' => !empty($author['user_id']) ? (int)$author['user_id'] : null,
                         'source' => in_array($author['source'] ?? '', ['hub', 'api', 'cowork'], true) ? $author['source'] : 'hub',
                         'created_at' => date('Y-m-d H:i:s')];
    $db->prepare("INSERT INTO request_timeline (" . implode(', ', array_keys($f)) . ") VALUES (" . implode(',', array_fill(0, count($f), '?')) . ")")
       ->execute(array_values($f));
    return (int)$db->lastInsertId();
}

function tl_event(PDO $db, int $id): ?array {
    tl_schema($db);
    $st = $db->prepare("SELECT t.*, u.full_name AS user_name FROM request_timeline t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?");
    $st->execute([$id]);
    $e = $st->fetch(PDO::FETCH_ASSOC);
    return $e ? tl_event_out($e) : null;
}

/**
 * Update an event. System events: only pinned / hidden. $canHide = may hide / unhide
 * (admins in the Hub; the Agent API). Returns the changed column names.
 */
function tl_update(PDO $db, int $id, array $in, bool $canHide): array {
    $cur = tl_event($db, $id);
    if (!$cur) throw new InvalidArgumentException('Event ' . $id . ' not found');
    if ($cur['author_type'] === 'system') {
        $extra = array_diff(array_keys($in), ['event_id', 'pinned', 'hidden', 'confirm']);
        if ($extra) throw new InvalidArgumentException('System events: only pinned / hidden can change');
        $f = [];
        if (array_key_exists('pinned', $in)) $f['pinned'] = !empty($in['pinned']) ? 1 : 0;
    } else {
        $v = tl_event_input($in, true);
        if ($v['errors']) throw new InvalidArgumentException(implode(' ', $v['errors']));
        $f = $v['fields'];
    }
    if (array_key_exists('hidden', $in)) {
        if (!$canHide) throw new InvalidArgumentException('Only admins can hide events');
        $f['hidden'] = !empty($in['hidden']) ? 1 : 0;
    }
    if (!$f) return [];
    $db->prepare("UPDATE request_timeline SET " . implode(', ', array_map(function ($c) { return $c . ' = ?'; }, array_keys($f))) . " WHERE id = ?")
       ->execute(array_merge(array_values($f), [$id]));
    return array_keys($f);
}

/** Public shape of an event row. */
function tl_event_out(array $e): array {
    $refs = isset($e['refs']) && $e['refs'] !== null && $e['refs'] !== '' ? json_decode((string)$e['refs'], true) : null;
    return [
        'id'          => (int)$e['id'],
        'request_id'  => (int)$e['request_id'],
        'event_at'    => substr((string)$e['event_at'], 0, 16),
        'event_type'  => $e['event_type'],
        'title'       => $e['title'],
        'body'        => $e['body'],
        'next_step'   => $e['next_step'],
        'author_type' => $e['author_type'],
        'author'      => $e['author_type'] === 'system' ? 'System' : ($e['author_type'] === 'claude' ? 'Claude' : ($e['user_name'] ?? null)),
        'user_id'     => $e['user_id'] !== null ? (int)$e['user_id'] : null,
        'triggered_by'=> $e['author_type'] === 'system' ? ($e['user_name'] ?? null) : null,
        'source'      => $e['source'],
        'session_url' => $e['session_url'],
        'refs'        => is_array($refs) ? $refs : null,
        'pinned'      => (bool)$e['pinned'],
        'hidden'      => (bool)$e['hidden'],
    ];
}

/**
 * Events of a request, newest first (pinned first when $opt['pinned_first']).
 * $opt: limit (default 50, max 500), since (datetime), types (array), include_hidden,
 * include_notes (merge request_notes as read-only "note" / "mail_sent" rows, id "n<id>").
 */
function tl_list(PDO $db, int $requestId, array $opt = []): array {
    tl_schema($db);
    $limit = max(1, min(500, (int)($opt['limit'] ?? 50)));
    $where = ["t.request_id = ?"]; $args = [$requestId];
    if (empty($opt['include_hidden'])) $where[] = "t.hidden = 0";
    if (!empty($opt['since'])) { $where[] = "t.event_at >= ?"; $args[] = $opt['since']; }
    $types = !empty($opt['types']) ? array_values(array_intersect((array)$opt['types'], array_keys(TL_TYPES))) : [];
    if ($types) { $where[] = "t.event_type IN (" . implode(',', array_fill(0, count($types), '?')) . ")"; $args = array_merge($args, $types); }
    $st = $db->prepare("SELECT t.*, u.full_name AS user_name FROM request_timeline t LEFT JOIN users u ON u.id = t.user_id
                        WHERE " . implode(' AND ', $where) . " ORDER BY t.event_at DESC, t.id DESC LIMIT " . $limit);
    $st->execute($args);
    $out = array_map('tl_event_out', $st->fetchAll(PDO::FETCH_ASSOC));

    if (!empty($opt['include_notes']) && (!$types || array_intersect($types, ['note', 'mail_sent']))) {
        foreach (tl_legacy_notes($db, $requestId, $opt['since'] ?? null) as $n) {
            if (!$types || in_array($n['event_type'], $types, true)) $out[] = $n;
        }
        usort($out, function ($a, $b) { return strcmp($b['event_at'], $a['event_at']); });
        $out = array_slice($out, 0, $limit);
    }
    if (!empty($opt['pinned_first'])) {
        $pinned = array_values(array_filter($out, function ($e) { return $e['pinned']; }));
        $rest   = array_values(array_filter($out, function ($e) { return !$e['pinned']; }));
        $out = array_merge($pinned, $rest);
    }
    return $out;
}

/** request_notes rows (Notes section, template emails) in event shape, read-only. */
function tl_legacy_notes(PDO $db, int $requestId, ?string $since = null): array {
    try {
        $st = $db->prepare("SELECT n.*, u.full_name AS user_name FROM request_notes n
                            LEFT JOIN users u ON u.id = COALESCE(n.user_id, n.created_by)
                            WHERE n.request_id = ?" . ($since ? " AND n.created_at >= ?" : "") . " ORDER BY n.created_at DESC LIMIT 200");
        $st->execute($since ? [$requestId, $since] : [$requestId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $n) {
        $isMail = ($n['note_type'] ?? '') === 'email_sent';
        $text = trim((string)($n['note'] ?? '') !== '' ? $n['note'] : ($n['body'] ?? ''));
        if ($isMail) $text = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $text)), ENT_QUOTES, 'UTF-8'));
        $title = $isMail ? 'Email: ' . trim((string)($n['subject'] ?? '')) : strtok($text, "\n");
        $out[] = [
            'id' => 'n' . (int)$n['id'], 'request_id' => $requestId, 'event_at' => substr((string)$n['created_at'], 0, 16),
            'event_type' => $isMail ? 'mail_sent' : 'note', 'title' => tl_cut((string)$title, 200),
            'body' => $text, 'next_step' => null, 'author_type' => 'user', 'author' => $n['user_name'] ?? null,
            'user_id' => null, 'triggered_by' => null, 'source' => 'notes', 'session_url' => null, 'refs' => null,
            'pinned' => false, 'hidden' => false,
        ];
    }
    return $out;
}

function tl_summary_get(PDO $db, int $requestId): ?array {
    tl_schema($db);
    $st = $db->prepare("SELECT s.*, u.full_name AS user_name FROM request_summary s LEFT JOIN users u ON u.id = s.updated_by WHERE s.request_id = ?");
    $st->execute([$requestId]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) return null;
    return ['summary' => $s['summary'], 'next_step' => $s['next_step'], 'waiting_on' => $s['waiting_on'],
            'updated_at' => substr((string)$s['updated_at'], 0, 16), 'updated_by_type' => $s['updated_by_type'],
            'updated_by' => $s['updated_by_type'] === 'claude' ? 'Claude' : ($s['user_name'] ?? null),
            'session_url' => $s['session_url']];
}

/** Validate summary input → ['fields', 'errors', 'warnings']. */
function tl_summary_input(array $in): array {
    $errors = []; $warnings = [];
    $sum = trim((string)($in['summary'] ?? ''));
    if ($sum === '') $errors[] = 'summary is required';
    elseif (tl_len($sum) > TL_SUMMARY_MAX) $errors[] = 'summary is ' . tl_len($sum) . ' characters — keep it under ' . TL_SUMMARY_MAX;
    $wo = trim((string)($in['waiting_on'] ?? ''));
    if ($wo !== '' && !in_array($wo, TL_WAITING_ON, true)) $errors[] = 'waiting_on must be one of: ' . implode(', ', TL_WAITING_ON) . ' (or empty)';
    $f = ['summary' => $sum, 'next_step' => trim((string)($in['next_step'] ?? '')) !== '' ? tl_cut((string)$in['next_step'], 500) : null,
          'waiting_on' => $wo !== '' ? $wo : null, 'session_url' => tl_session_url($in['session_url'] ?? '', $errors)];
    $w = tl_passport_warning($sum . "\n" . (string)$f['next_step']);
    if ($w) $warnings[] = $w;
    return ['fields' => $f, 'errors' => $errors, 'warnings' => $warnings];
}

/**
 * Save the summary. The previous one (if any, and different) becomes a note event
 * "Summary updated" so nothing is lost. $author as in tl_add(). Returns the saved summary.
 */
function tl_summary_set(PDO $db, int $requestId, array $in, array $author): array {
    tl_schema($db);
    $v = tl_summary_input($in);
    if ($v['errors']) throw new InvalidArgumentException(implode(' ', $v['errors']));
    $f = $v['fields'];
    $prev = tl_summary_get($db, $requestId);
    $type = ($author['type'] ?? 'user') === 'claude' ? 'claude' : 'user';
    $uid  = !empty($author['user_id']) ? (int)$author['user_id'] : null;
    $db->beginTransaction();
    try {
        if ($prev && ($prev['summary'] !== $f['summary'] || (string)$prev['next_step'] !== (string)$f['next_step'] || (string)$prev['waiting_on'] !== (string)$f['waiting_on'])) {
            $body = $prev['summary']
                  . ($prev['next_step'] ? "\n\nNext step: " . $prev['next_step'] : '')
                  . ($prev['waiting_on'] ? "\nWaiting on: " . $prev['waiting_on'] : '')
                  . "\n\n(" . ($prev['updated_by'] ?: $prev['updated_by_type']) . ', ' . $prev['updated_at'] . ')';
            $db->prepare("INSERT INTO request_timeline (request_id, event_at, event_type, title, body, author_type, user_id, source, session_url, created_at)
                          VALUES (?,?,'note','Summary updated — previous version',?,?,?,?,?,?)")
               ->execute([$requestId, date('Y-m-d H:i:s'), $body, $type, $uid,
                          in_array($author['source'] ?? '', ['hub', 'api', 'cowork'], true) ? $author['source'] : 'hub', $prev['session_url'],
                          date('Y-m-d H:i:s')]);
        }
        $db->prepare("INSERT INTO request_summary (request_id, summary, next_step, waiting_on, updated_by_type, updated_by, session_url, updated_at)
                      VALUES (?,?,?,?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE summary = VALUES(summary), next_step = VALUES(next_step), waiting_on = VALUES(waiting_on),
                        updated_by_type = VALUES(updated_by_type), updated_by = VALUES(updated_by), session_url = VALUES(session_url),
                        updated_at = VALUES(updated_at)")
           ->execute([$requestId, $f['summary'], $f['next_step'], $f['waiting_on'], $type, $uid, $f['session_url'], date('Y-m-d H:i:s')]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return tl_summary_get($db, $requestId);
}

/**
 * Last activity per request (latest visible event, summary update or note) and whether
 * it has a summary: [request_id => ['last_activity_at' => ?string, 'has_summary' => bool, 'summary' => ?string]].
 */
function tl_activity_map(PDO $db, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $out = [];
    foreach ($ids as $id) $out[$id] = ['last_activity_at' => null, 'has_summary' => false, 'summary' => null];
    if (!$ids) return $out;
    tl_schema($db);
    $in = implode(',', $ids);   // ints only
    $bump = function ($id, $ts) use (&$out) {
        if ($ts && ($out[$id]['last_activity_at'] === null || $ts > $out[$id]['last_activity_at'])) $out[$id]['last_activity_at'] = substr($ts, 0, 16);
    };
    foreach ($db->query("SELECT request_id, MAX(event_at) m FROM request_timeline WHERE hidden = 0 AND request_id IN ($in) GROUP BY request_id") as $r) $bump((int)$r['request_id'], $r['m']);
    foreach ($db->query("SELECT request_id, summary, updated_at FROM request_summary WHERE request_id IN ($in)") as $r) {
        $out[(int)$r['request_id']]['has_summary'] = true;
        $out[(int)$r['request_id']]['summary'] = $r['summary'];
        $bump((int)$r['request_id'], $r['updated_at']);
    }
    try {
        foreach ($db->query("SELECT request_id, MAX(created_at) m FROM request_notes WHERE request_id IN ($in) GROUP BY request_id") as $r) $bump((int)$r['request_id'], $r['m']);
    } catch (Throwable $e) { /* older installs without request_notes */ }
    return $out;
}

/**
 * Everything needed to pick up a request in a new session: the request, summary,
 * last 20 events (pinned first, notes merged), ITI programmes, Calc files in the
 * folder (when Dropbox helpers are loaded), invoices, memos, other requests of the
 * same client, last activity.
 */
function tl_resume(PDO $db, array $r): array {
    $rid = (int)$r['id'];
    $out = [
        'request' => [
            'id' => $rid, 'customer_name' => $r['customer_name'], 'email' => $r['email'] ?? null,
            'agency' => function_exists('folder_agency') ? folder_agency($r) : null, 'agent' => $r['agent_name'] ?? null,
            'status' => $r['status'], 'payment_status' => $r['payment_status'] ?? null,
            'period' => $r['period'] ?? null, 'pax' => $r['pax'] ?? null, 'value_usd' => $r['value_usd'] ?? null,
            'destination' => $r['destination'] ?? null, 'folder' => $r['practice_code'] ?? null,
            'group_folder' => $r['group_folder'] ?? null,
            'dropbox_path' => function_exists('req_folder_path') ? req_folder_path($r) : null,
            'date_received' => $r['date_received'] ?? null, 'confirmation_date' => $r['confirmation_date'] ?? null,
            'start_date' => $r['start_date'] ?? null,
            'initial_request' => isset($r['initial_request']) ? tl_cut((string)$r['initial_request'], 1500) : null,
        ],
        'summary' => tl_summary_get($db, $rid),
        'events'  => tl_list($db, $rid, ['limit' => 20, 'include_notes' => true, 'pinned_first' => true]),
        'programs' => [], 'calc_files' => null, 'invoices' => [], 'memos' => [], 'client_history' => [],
    ];

    try {
        $st = $db->prepare("SELECT id, program_type, title_it, title_en, display_language, status, stage, is_published, public_token, updated_at
                            FROM iti_programs WHERE lead_request_id = ? AND status <> 'cancelled' ORDER BY updated_at DESC");
        $st->execute([$rid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $out['programs'][] = ['program_id' => (int)$p['id'], 'title' => $p['title_it'] ?: $p['title_en'], 'type' => $p['program_type'],
                                  'stage' => $p['stage'] ?? null, 'language' => $p['display_language'], 'status' => $p['status'],
                                  'updated_at' => substr((string)$p['updated_at'], 0, 16),
                                  'public_link' => $p['public_token'] ? 'https://hub.savannahexplorers.com/modules/iti/itinerary.php?token=' . $p['public_token'] : null];
        }
    } catch (Throwable $e) { /* ITI link column not created yet */ }

    if (function_exists('dropbox_get_access_token') && function_exists('req_folder_path') && !empty($r['practice_code'])) {
        try {
            $token = dropbox_get_access_token();
            $dir = req_folder_path($r);
            if ($dir !== '' && (!function_exists('dropbox_path_exists') || dropbox_path_exists($token, $dir))) {
                $out['calc_files'] = array_values(array_filter(dropbox_list_files($token, $dir), function ($fn) { return (bool)preg_match('/_Calc.*\.xlsx$/i', $fn); }));
            }
        } catch (Throwable $e) {
            $out['calc_files_error'] = $e->getMessage();
        }
    }

    try {
        $st = $db->prepare("SELECT id, invoice_number, issuer, currency, total, amount_paid, balance_due, status, issue_date FROM invoices WHERE request_id = ? ORDER BY id");
        $st->execute([$rid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $out['invoices'][] = ['invoice_id' => (int)$i['id'], 'invoice_number' => $i['invoice_number'], 'issue_date' => $i['issue_date'],
                                  'currency' => $i['currency'], 'total' => round((float)$i['total'], 2), 'amount_paid' => round((float)$i['amount_paid'], 2),
                                  'balance_due' => round((float)$i['balance_due'], 2), 'status' => $i['status']];
        }
    } catch (Throwable $e) { /* invoices module not installed */ }

    try {
        $st = $db->prepare("SELECT id, ext_key, title, status, waiting_on, due_date FROM memos WHERE request_id = ? AND deleted_at IS NULL ORDER BY (status IN ('done','archived')), due_date IS NULL, due_date LIMIT 30");
        $st->execute([$rid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $out['memos'][] = ['id' => (int)$m['id'], 'ext_key' => $m['ext_key'], 'title' => $m['title'], 'status' => $m['status'],
                               'waiting_on' => $m['waiting_on'], 'due_date' => $m['due_date']];
        }
    } catch (Throwable $e) { /* memo module not installed */ }

    $out['client_history'] = tl_client_history($db, $r);
    $act = tl_activity_map($db, [$rid]);
    $out['last_activity_at'] = $act[$rid]['last_activity_at'];
    return $out;
}

/** Other requests of the same client: same email, or (no email) the same customer name. */
function tl_client_history(PDO $db, array $r): array {
    $email = strtolower(trim((string)($r['email'] ?? '')));
    if ($email !== '') {
        $st = $db->prepare("SELECT id, customer_name, date_received, status, practice_code FROM requests
                            WHERE id <> ? AND LOWER(TRIM(email)) = ? ORDER BY date_received DESC LIMIT 20");
        $st->execute([(int)$r['id'], $email]);
    } else {
        $name = strtolower(preg_replace('/\s+/', ' ', trim((string)($r['customer_name'] ?? ''))));
        if ($name === '') return [];
        $st = $db->prepare("SELECT id, customer_name, date_received, status, practice_code FROM requests
                            WHERE id <> ? AND LOWER(TRIM(customer_name)) = ? ORDER BY date_received DESC LIMIT 20");
        $st->execute([(int)$r['id'], $name]);
    }
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $out[] = ['request_id' => (int)$h['id'], 'customer_name' => $h['customer_name'], 'date_received' => $h['date_received'],
                  'status' => $h['status'], 'folder' => $h['practice_code']];
    }
    return $out;
}
