<?php
/**
 * parked_service.php — status "Parked" (Roberto, 20 Nov 2026): a quote sent to an agency that will come back
 * if and when its client decides. Off the pipeline, not open, not Lost. Woken up by itself when the agency writes
 * again; closed as Lost only when the trip is over.
 *
 *   requests.parked_at / parked_by / parked_from  — set when parked, cleared when the request leaves Parked
 *                                                   (bs_set_request_status() in booking_service.php)
 *   pk_wake()       Parked → parked_from (default Quoted): inbound mail, manual timeline note, booking
 *   pk_trip_over()  trip over? start_date, else the folder dates, else parked for PARKED_STALE_DAYS
 *   pk_autoclose()  daily (ck_cron.php): Parked + trip over → Lost (no_more_replies); report-only until
 *                   PARKED_AUTOCLOSE_LIVE is true in includes/config.php
 *
 * Transitions: to Parked from Inquiry / Quoted / Hot-Quoted / Lost; from Parked to Inquiry / Quoted / Hot-Quoted /
 * Lost / Booked. Requires the leads config.php (db(), STATUSES). Keep PHP-7 compatible.
 */

require_once __DIR__ . '/folder_parser.php';      // parse_folder_dates()
require_once __DIR__ . '/timeline_service.php';   // timeline_log()

const PK_STATUS = 'Parked';
const PK_LABEL  = 'Parked – in attesa agenzia';
const PK_TIP    = 'Quote sent, waiting for the agency; off the pipeline';
const PK_FROM   = ['Inquiry', 'Quoted', 'Hot-Quoted', 'Lost'];            // statuses a request can be parked from
const PK_TO     = ['Inquiry', 'Quoted', 'Hot-Quoted', 'Lost', 'Booked'];  // statuses a Parked request can go to
const PK_RESTORE = ['Inquiry', 'Quoted', 'Hot-Quoted'];                   // valid parked_from on wake-up (else Quoted)

/** Days without a trip date after which a Parked request counts as over (includes/config.php may define PARKED_STALE_DAYS). */
function pk_stale_days(): int {
    return defined('PARKED_STALE_DAYS') ? max(30, (int)PARKED_STALE_DAYS) : 180;
}

/** Auto-close writes only when includes/config.php has define('PARKED_AUTOCLOSE_LIVE', true); otherwise report-only. */
function pk_autoclose_live(): bool {
    return defined('PARKED_AUTOCLOSE_LIVE') && PARKED_AUTOCLOSE_LIVE;
}

/** Lazy schema (also in migrations/084_requests_parked.sql): parked_at, parked_by, parked_from. Never throws. */
function pk_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $st = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND COLUMN_NAME = 'parked_at'");
        if ((int)$st->fetchColumn() > 0) return;
        foreach (["ALTER TABLE requests ADD COLUMN parked_at DATETIME NULL",
                  "ALTER TABLE requests ADD COLUMN parked_by INT NULL",
                  "ALTER TABLE requests ADD COLUMN parked_from VARCHAR(20) NULL",
                  "ALTER TABLE requests ADD INDEX idx_req_parked (status, parked_at)"] as $q) {
            try { $db->exec($q); } catch (PDOException $ignored) {}
        }
    } catch (Throwable $e) {
        error_log('pk_schema: ' . $e->getMessage());
    }
}

/** Status shown to people: "Parked – in attesa agenzia" for Parked, the value itself otherwise. */
function pk_status_label(string $status): string {
    return $status === PK_STATUS ? PK_LABEL : $status;
}

/**
 * Trip over for a Parked request? $r: start_date, practice_code, group_folder, parked_at.
 * → ['over' => bool, 'basis' => 'start_date' | 'folder' | 'parked_age' | 'none', 'trip_date' => ?Y-m-d]
 */
function pk_trip_over(array $r, ?string $today = null): array {
    $today = $today ?: date('Y-m-d');
    $sd = trim((string)($r['start_date'] ?? ''));
    if ($sd !== '' && $sd !== '0000-00-00') return ['over' => $sd < $today, 'basis' => 'start_date', 'trip_date' => $sd];
    foreach ([(string)($r['practice_code'] ?? ''), (string)($r['group_folder'] ?? '')] as $f) {
        if ($f === '') continue;
        $fd = parse_folder_dates($f);
        if (!empty($fd['start_date'])) return ['over' => $fd['start_date'] < $today, 'basis' => 'folder', 'trip_date' => $fd['start_date']];
    }
    $pa = trim((string)($r['parked_at'] ?? ''));
    if ($pa !== '') {
        $limit = date('Y-m-d', strtotime($today . ' -' . pk_stale_days() . ' days'));
        return ['over' => substr($pa, 0, 10) < $limit, 'basis' => 'parked_age', 'trip_date' => null];
    }
    return ['over' => false, 'basis' => 'none', 'trip_date' => null];
}

/**
 * For pages that write requests.status directly (Edit Request): keep the parked fields in step with a status change
 * $old → $new (set when it becomes Parked, cleared when it leaves) and log the status_change event. Never throws.
 */
function pk_sync_fields(PDO $db, int $id, string $old, string $new, int $userId): void {
    if ($old === $new || ($old !== PK_STATUS && $new !== PK_STATUS)) return;
    try {
        pk_schema($db);
        if ($new === PK_STATUS) {
            $db->prepare("UPDATE requests SET parked_at = ?, parked_by = ?, parked_from = ? WHERE id = ?")
               ->execute([date('Y-m-d H:i:s'), $userId ?: null, in_array($old, PK_RESTORE, true) ? $old : 'Quoted', $id]);
        } else {
            $db->prepare("UPDATE requests SET parked_at = NULL, parked_by = NULL, parked_from = NULL WHERE id = ?")->execute([$id]);
        }
        timeline_log($id, 'status_change', ($old !== '' ? $old : '(empty)') . ' → ' . $new);
    } catch (Throwable $e) {
        error_log('pk_sync_fields(' . $id . '): ' . $e->getMessage());
    }
}

/**
 * Wake a Parked request up: status back to parked_from (default Quoted), parked fields cleared, one timeline event
 * "Riattivata: <why>", mail to the request's agent ("Richiesta riattivata: <folder>"). Nothing to do when the
 * request is not Parked. Never throws (a failure goes to error_log).
 * $opt: notify (default true), dry_run.
 * → ['woken' => bool, 'id', 'status_from', 'status_to', 'notify' => {sent, error} | null, 'error'?]
 */
function pk_wake(PDO $db, int $id, string $why, array $opt = []): array {
    $out = ['woken' => false, 'id' => $id, 'status_from' => null, 'status_to' => null, 'notify' => null];
    try {
        pk_schema($db);
        $st = $db->prepare("SELECT r.id, r.status, r.parked_from, r.agent_id, r.customer_name, r.practice_code, r.group_folder
                            FROM requests r WHERE r.id = ?");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || $r['status'] !== PK_STATUS) return $out;
        $to = in_array((string)$r['parked_from'], PK_RESTORE, true) ? (string)$r['parked_from'] : 'Quoted';
        $out['status_from'] = PK_STATUS;
        $out['status_to']   = $to;
        if (!empty($opt['dry_run'])) return array_merge($out, ['woken' => true]);
        $why = trim($why) !== '' ? trim($why) : 'attività sulla richiesta';
        $res = bs_set_request_status($db, $id, $to, '', '', 0, ['tl_title' => 'Riattivata: ' . $why,
                                                                'tl_body'  => PK_STATUS . ' → ' . $to]);
        if (empty($res['ok'])) return array_merge($out, ['error' => $res['error'] ?? 'status change refused']);
        $out['woken'] = true;
        if (!array_key_exists('notify', $opt) || $opt['notify']) $out['notify'] = pk_notify_agent($db, $r, $why);
    } catch (Throwable $e) {
        error_log('pk_wake(' . $id . '): ' . $e->getMessage());
        $out['error'] = $e->getMessage();
    }
    return $out;
}

/** Mail to the request's agent: "Richiesta riattivata: <folder>". No mail to the agency / client. */
function pk_notify_agent(PDO $db, array $r, string $why): array {
    if (empty($r['agent_id'])) return ['sent' => false, 'error' => null];
    require_once __DIR__ . '/../notifications.php';
    $st = $db->prepare("SELECT a.name, u.email FROM agents a LEFT JOIN users u ON u.agent_id = a.id AND u.is_active = 1 WHERE a.id = ? LIMIT 1");
    $st->execute([(int)$r['agent_id']]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a || empty($a['email'])) return ['sent' => false, 'error' => 'Agent ' . ($a['name'] ?? $r['agent_id']) . ' has no email — wake-up notification not sent.'];
    $folder = trim((string)$r['practice_code']) !== '' ? (string)$r['practice_code'] : (string)$r['customer_name'];
    $body = "Una richiesta parcheggiata è tornata attiva.\n\n"
          . "Cliente:  " . $r['customer_name'] . "\n"
          . "Cartella: " . $folder . "\n"
          . "Motivo:   " . $why . "\n\n"
          . "https://hub.savannahexplorers.com/modules/leads/request_view.php?id=" . (int)$r['id'] . "\n";
    $ok = send_notification_mail($a['email'], 'Richiesta riattivata: ' . $folder, $body, 'parked wake-up #' . (int)$r['id']);
    return ['sent' => $ok, 'error' => $ok ? null : 'mail() refused the wake-up notification for ' . $a['email']];
}

/**
 * Wake up Parked requests that received a mail since they were parked. Uses the links Sales Response already made
 * (mail_headers_index, sales_response_service.php sr_link(): thread / request or agency contact address / folder code
 * in the subject), direction 'client' only — never our own mails, auto-replies or internal ones. date_utc is UTC,
 * parked_at EAT (+03:00, no DST). Called from ck_cron.php (every 30 min; Sales Response indexes hourly).
 * → ['checked' => n Parked requests, 'woken' => [{id, from, subject, mail_at, status_to}]]
 */
function pk_wake_from_mail(PDO $db): array {
    pk_schema($db);
    $out = ['checked' => (int)$db->query("SELECT COUNT(*) FROM requests WHERE status = 'Parked'")->fetchColumn(), 'woken' => []];
    if (!$out['checked']) return $out;
    try {
        $rows = $db->query("SELECT r.id, m.from_addr, m.subject, m.date_utc
                            FROM requests r
                            JOIN mail_headers_index m ON m.request_id = r.id AND m.direction = 'client'
                                 AND m.date_utc > DATE_SUB(COALESCE(r.parked_at, '1970-01-01'), INTERVAL 3 HOUR)
                            WHERE r.status = 'Parked'
                            ORDER BY r.id, m.date_utc")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {   // Sales Response not set up (no mail_headers_index)
        error_log('pk_wake_from_mail: ' . $e->getMessage());
        return $out;
    }
    $first = [];
    foreach ($rows as $m) if (!isset($first[(int)$m['id']])) $first[(int)$m['id']] = $m;   // the first mail after parking
    foreach ($first as $rid => $m) {
        $subj = trim((string)$m['subject']) !== '' ? trim((string)$m['subject']) : '(senza oggetto)';
        $res = pk_wake($db, $rid, 'mail da ' . $m['from_addr'] . ' – ' . mb_substr($subj, 0, 120));
        if ($res['woken']) {
            $out['woken'][] = ['id' => $rid, 'from' => $m['from_addr'], 'subject' => $subj,
                               'mail_at' => date('Y-m-d H:i', strtotime($m['date_utc'] . ' UTC')), 'status_to' => $res['status_to']];
        }
    }
    return $out;
}

/**
 * Daily close: Parked requests whose trip is over → Lost, reason no_more_replies, note "Chiusa automaticamente: …",
 * timeline event (author system). $live = false → report only (nothing written).
 * → ['live' => bool, 'checked' => n, 'closed' => [{id, customer_name, folder, agent, parked_at, trip_date, basis, done}]]
 */
function pk_autoclose(PDO $db, bool $live): array {
    pk_schema($db);
    $rows = $db->query("SELECT r.id, r.customer_name, r.practice_code, r.group_folder, r.start_date, r.parked_at, a.name AS agent
                        FROM requests r LEFT JOIN agents a ON a.id = r.agent_id
                        WHERE r.status = 'Parked' ORDER BY r.parked_at")->fetchAll(PDO::FETCH_ASSOC);
    $out = ['live' => $live, 'checked' => count($rows), 'closed' => []];
    foreach ($rows as $r) {
        $t = pk_trip_over($r);
        if (!$t['over']) continue;
        $item = ['id' => (int)$r['id'], 'customer_name' => $r['customer_name'], 'folder' => $r['practice_code'],
                 'agent' => $r['agent'], 'parked_at' => $r['parked_at'], 'trip_date' => $t['trip_date'], 'basis' => $t['basis'], 'done' => false];
        if ($live) {
            $note = 'Chiusa automaticamente: parcheggiata dal ' . ($r['parked_at'] ? date('d/m/Y', strtotime((string)$r['parked_at'])) : '?')
                  . ($t['basis'] === 'parked_age' ? ', nessuna data viaggio, oltre ' . pk_stale_days() . ' giorni' : ', data viaggio passata');
            try {
                $res = bs_set_request_status($db, (int)$r['id'], 'Lost', 'no_more_replies', $note, 0,
                                             ['tl_title' => 'Parked auto-close: Parked → Lost']);
                $item['done'] = !empty($res['ok']);
                if (empty($res['ok'])) $item['error'] = $res['error'] ?? 'refused';
            } catch (Throwable $e) {
                $item['error'] = $e->getMessage();
            }
        }
        $out['closed'][] = $item;
    }
    return $out;
}

/**
 * Every ck_cron.php run: wake-ups from mail (pk_wake_from_mail). Once a day after 05:00 EAT (ck_meta
 * 'parked_autoclose_last', same guard as folder_orphans_nightly): pk_autoclose(), live only with PARKED_AUTOCLOSE_LIVE.
 * Returns one log line.
 */
function pk_cron(PDO $db): string {
    $w = pk_wake_from_mail($db);
    $line = 'parked: ' . $w['checked'] . ' parked, ' . count($w['woken']) . ' woken by mail'
          . ($w['woken'] ? ' (' . implode(', ', array_column($w['woken'], 'id')) . ')' : '');
    $db->exec("CREATE TABLE IF NOT EXISTS ck_meta (k VARCHAR(40) PRIMARY KEY, v VARCHAR(255) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ((int)date('G') < 5) return $line;
    $st = $db->prepare("SELECT v FROM ck_meta WHERE k = 'parked_autoclose_last'");
    $st->execute();
    if ($st->fetchColumn() === date('Y-m-d')) return $line;
    $db->prepare("INSERT INTO ck_meta (k, v) VALUES ('parked_autoclose_last', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([date('Y-m-d')]);
    $live = pk_autoclose_live();
    $c = pk_autoclose($db, $live);
    $ids = array_map(function ($x) { return $x['id'] . (!empty($x['error']) ? '!' : ''); }, $c['closed']);
    return $line . '; auto-close ' . ($live ? 'LIVE' : 'report-only') . ': ' . count($c['closed']) . ' of ' . $c['checked']
         . ($ids ? ' (' . implode(', ', $ids) . ')' : '');
}

/** Last automatic wake-ups and auto-closes (timeline), newest first. → [{request_id, customer_name, event_at, kind, title, body}] */
function pk_recent_events(PDO $db, int $limit = 30, int $days = 14): array {
    tl_schema($db);
    $st = $db->prepare("SELECT t.request_id, r.customer_name, r.status, t.event_at, t.title, t.body
                        FROM request_timeline t JOIN requests r ON r.id = t.request_id
                        WHERE t.event_type = 'status_change' AND t.hidden = 0 AND t.event_at >= ?
                          AND (t.title LIKE 'Riattivata:%' OR t.title LIKE 'Parked auto-close%')
                        ORDER BY t.event_at DESC LIMIT " . max(1, min(200, $limit)));
    $st->execute([date('Y-m-d H:i:s', strtotime('-' . max(1, $days) . ' days'))]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $out[] = ['request_id' => (int)$e['request_id'], 'customer_name' => $e['customer_name'], 'status_now' => $e['status'],
                  'event_at' => substr((string)$e['event_at'], 0, 16),
                  'kind' => strpos($e['title'], 'Riattivata:') === 0 ? 'wakeup' : 'autoclose', 'title' => $e['title'], 'body' => $e['body']];
    }
    return $out;
}
