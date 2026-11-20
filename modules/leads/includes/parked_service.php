<?php
/**
 * parked_service.php — status "Parked" (Roberto, 20 Nov 2026): a quote sent to an agency that will come back
 * if and when its client decides. Off the pipeline, not open, not Lost. Woken up by itself when the agency writes
 * again; closed as Lost only when the trip is over.
 *
 *   requests.parked_at / parked_by / parked_from  — set when parked, cleared when the request leaves Parked
 *                                                   (bs_set_request_status() in booking_service.php)
 *   requests.parked_wake_after — set to "now" whenever a request is parked (also by the Lost → Parked migration, which
 *                                moves parked_at back to the Lost date): only mails after it can wake the request up
 *   pk_wake()       Parked → parked_from (default Quoted): inbound mail about the file (pk_wake_from_mail), manual
 *                   timeline note, booking
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
const PK_LABEL  = 'Parked – waiting for the agency';
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

/**
 * Lazy schema (also in migrations 084_requests_parked.sql and 085_requests_parked_wake_after.sql): parked_at, parked_by,
 * parked_from, parked_wake_after. When parked_wake_after is added, the Parked requests get it backfilled
 * (pk_backfill_wake_after()). Never throws.
 */
function pk_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $have = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests'
                              AND COLUMN_NAME IN ('parked_at', 'parked_wake_after')")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('parked_at', $have, true)) {
            foreach (["ALTER TABLE requests ADD COLUMN parked_at DATETIME NULL",
                      "ALTER TABLE requests ADD COLUMN parked_by INT NULL",
                      "ALTER TABLE requests ADD COLUMN parked_from VARCHAR(20) NULL",
                      "ALTER TABLE requests ADD INDEX idx_req_parked (status, parked_at)"] as $q) {
                try { $db->exec($q); } catch (PDOException $ignored) {}
            }
        }
        if (!in_array('parked_wake_after', $have, true)) {
            try {
                $db->exec("ALTER TABLE requests ADD COLUMN parked_wake_after DATETIME NULL");
                pk_backfill_wake_after($db);
            } catch (PDOException $ignored) {}
        }
    } catch (Throwable $e) {
        error_log('pk_schema: ' . $e->getMessage());
    }
}

/**
 * parked_wake_after for the requests Parked now and without one: the time they were parked — the last timeline
 * status_change to Parked ("… → Parked", "Migrated from Lost (agent_request) to Parked"), never earlier; no such event →
 * now. Also in migrations/085. → rows updated.
 */
function pk_backfill_wake_after(PDO $db): int {
    try {
        $n = $db->exec("UPDATE requests r
                        SET r.parked_wake_after = COALESCE(
                            (SELECT MAX(t.event_at) FROM request_timeline t
                             WHERE t.request_id = r.id AND t.event_type = 'status_change'
                               AND (t.title LIKE '% → Parked' OR t.title LIKE 'Migrated from Lost%')),
                            NOW())
                        WHERE r.status = 'Parked' AND r.parked_wake_after IS NULL");
        return (int)$n;
    } catch (Throwable $e) {   // no request_timeline yet
        error_log('pk_backfill_wake_after: ' . $e->getMessage());
        return (int)$db->exec("UPDATE requests SET parked_wake_after = NOW() WHERE status = 'Parked' AND parked_wake_after IS NULL");
    }
}

/** Status shown to people: "Parked – waiting for the agency" for Parked, the value itself otherwise. */
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
            $db->prepare("UPDATE requests SET parked_at = ?, parked_by = ?, parked_from = ?, parked_wake_after = ? WHERE id = ?")
               ->execute([date('Y-m-d H:i:s'), $userId ?: null, in_array($old, PK_RESTORE, true) ? $old : 'Quoted', date('Y-m-d H:i:s'), $id]);
        } else {
            $db->prepare("UPDATE requests SET parked_at = NULL, parked_by = NULL, parked_from = NULL, parked_wake_after = NULL WHERE id = ?")->execute([$id]);
        }
        timeline_log($id, 'status_change', ($old !== '' ? $old : '(empty)') . ' → ' . $new);
    } catch (Throwable $e) {
        error_log('pk_sync_fields(' . $id . '): ' . $e->getMessage());
    }
}

/**
 * Wake a Parked request up: status back to parked_from (default Quoted), parked fields cleared, one timeline event
 * "Reactivated: <why>", mail to the request's agent ("Request reactivated: <folder>"). Nothing to do when the
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
        $why = trim($why) !== '' ? trim($why) : 'activity on the request';
        $res = bs_set_request_status($db, $id, $to, '', '', 0, ['tl_title' => 'Reactivated: ' . $why,
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

/** Mail to the request's agent: "Request reactivated: <folder>". No mail to the agency / client. */
function pk_notify_agent(PDO $db, array $r, string $why): array {
    if (empty($r['agent_id'])) return ['sent' => false, 'error' => null];
    require_once __DIR__ . '/../notifications.php';
    $st = $db->prepare("SELECT a.name, u.email FROM agents a LEFT JOIN users u ON u.agent_id = a.id AND u.is_active = 1 WHERE a.id = ? LIMIT 1");
    $st->execute([(int)$r['agent_id']]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a || empty($a['email'])) return ['sent' => false, 'error' => 'Agent ' . ($a['name'] ?? $r['agent_id']) . ' has no email — wake-up notification not sent.'];
    $folder = trim((string)$r['practice_code']) !== '' ? (string)$r['practice_code'] : (string)$r['customer_name'];
    $body = "A parked request is active again.\n\n"
          . "Client:   " . $r['customer_name'] . "\n"
          . "Folder:   " . $folder . "\n"
          . "Why:      " . $why . "\n\n"
          . "https://hub.savannahexplorers.com/modules/leads/request_view.php?id=" . (int)$r['id'] . "\n";
    $ok = send_notification_mail($a['email'], 'Request reactivated: ' . $folder, $body, 'parked wake-up #' . (int)$r['id']);
    return ['sent' => $ok, 'error' => $ok ? null : 'mail() refused the wake-up notification for ' . $a['email']];
}

// ── Wake-up from mail (change of 20 Nov 2026: the file, not the sender) ──────────────────────────────────────────
//
// A client mail (mail_headers_index, direction 'client', dated after the request's parked_wake_after) wakes a Parked
// request only when it is about THAT file:
//   folder  the folder name "Name(Agency-Agent)" is in the subject (spaces / case ignored), or the name part before "("
//           when it is distinctive (≥ PK_NAME_MIN letters/digits, its letters not a generic word: PK_GENERIC_NAMES);
//   thread  In-Reply-To / References hold a Message-ID already tied to the request: a mail logged in its timeline
//           (refs.mail_message_id), a mail Sales Response linked by its folder name (match_rule 'subject'), a direct
//           client's mail to / from their own address, and the replies in those threads (never a link made only by
//           the agency's address or domain — that is how a Fam Trip thread got tied to another file);
//   client  direct clients only: the sender is the client's own address stored on the request.
// The agency's address / domain alone never wakes anything: such a mail is listed in "Possibili risvegli"
// (pk_possible_wakeups(): Parked page, memo digest, Agent API parked_wakeups) for Roberto to decide.
// Only headers are indexed (Sales Response never stores bodies), so the body's first lines are not read.

const PK_WAKE_LOOKBACK_DAYS = 14;   // mails looked at: dated in the last 14 days (and after parked_wake_after)
const PK_NAME_MIN = 6;
const PK_GENERIC_NAMES = ['safari', 'safaris', 'tanzania', 'zanzibar', 'kenya', 'uganda', 'rwanda', 'mauritius',
    'serengeti', 'ngorongoro', 'kilimanjaro', 'tarangire', 'manyara', 'arusha', 'africa', 'honeymoon', 'honeymooners',
    'viaggio', 'viaggi', 'viaggionozze', 'nozze', 'sposi', 'preventivo', 'richiesta', 'request', 'quote', 'quotation',
    'offerta', 'booking', 'prenotazione', 'family', 'famiglia', 'famille', 'group', 'gruppo', 'groupe', 'friends', 'amici',
    'couple', 'coppia', 'capodanno', 'natale', 'pasqua', 'ferragosto', 'christmas', 'newyear', 'easter', 'gennaio',
    'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre',
    'january', 'february', 'march', 'april', 'june', 'july', 'august', 'september', 'october', 'november', 'december',
    'famtrip', 'travel', 'travels', 'voyage', 'cliente', 'clienti', 'client', 'clients', 'school', 'scuola', 'students',
    'incentive', 'beach', 'mare', 'migration', 'migrazione', 'trekking', 'climbing', 'pax'];

/** Lower-case letters and digits only (spaces, brackets, dashes dropped): "Jan3PAX (Clickmate…)" ≈ "jan3pax(clickmate…)". */
function pk_compact(string $s): string {
    return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s, 'UTF-8'));
}

/** Message-ID as Sales Response stores it: lower-case, without <>. */
function pk_msgid(string $s): string {
    return strtolower(trim($s, " \t\r\n<>"));
}

/**
 * What identifies a request's file in a subject: ['code' => compact "name(agency-agent)" | '', 'name' => compact distinctive
 * name | '', 'label' => folder or customer for the log]. No folder → the customer name (as a name only).
 */
function pk_file_keys(array $r): array {
    $pc = trim((string)($r['practice_code'] ?? ''));
    $label = $pc !== '' ? $pc : trim((string)($r['customer_name'] ?? ''));
    $code = $name = '';
    if (preg_match('/([^_\/]+)\(([^)]*)\)/', $pc, $m)) {
        $code = pk_compact($m[1] . '(' . $m[2] . ')');
        $nameRaw = $m[1];
    } else {
        $nameRaw = $pc !== '' ? preg_replace('/^\d{6}_/', '', $pc) : (string)($r['customer_name'] ?? '');
    }
    $n = pk_compact($nameRaw);
    $letters = preg_replace('/(\d+pax|pax\d*|\d+)/', '', $n);   // "capodanno2912" → "capodanno", "gobbi3pax" → "gobbi"
    if (strlen($n) >= PK_NAME_MIN && strlen($letters) >= 3 && !in_array($letters, PK_GENERIC_NAMES, true)) $name = $n;
    return ['code' => strlen($code) >= 6 ? $code : '', 'name' => $name, 'label' => $label];
}

/**
 * Does mail $m wake request $r? Pure (no DB). $r: id, practice_code, customer_name, email, kind ('direct' | …), wake_after
 * (EAT), keys (pk_file_keys), trusted (message id => true). $m: from_addr, subject, in_reply_to, refs, date_utc (UTC).
 * → null, or ['rule' => folder|name|thread|client, 'why' => text for the log].
 */
function pk_mail_match(array $r, array $m): ?array {
    $ts = strtotime($m['date_utc'] . ' UTC');
    if (!empty($r['wake_after']) && $ts <= strtotime((string)$r['wake_after'])) return null;   // before it was parked
    $keys = $r['keys'] ?? pk_file_keys($r);
    $from = strtolower(trim((string)$m['from_addr']));
    $own  = $r['own'] ?? pk_addrs((string)($r['email'] ?? ''));
    $subj = $m['subj_c'] ?? pk_compact((string)$m['subject']);
    $byName = null;
    if ($keys['code'] !== '' && strpos($subj, $keys['code']) !== false) $byName = ['rule' => 'folder', 'why' => 'folder name in subject'];
    elseif ($keys['name'] !== '' && strpos($subj, $keys['name']) !== false) $byName = ['rule' => 'name', 'why' => 'folder name (client part) in subject'];
    if ($byName) {
        // From the agency, the client or a personal mailbox: it is the file. From another company (a lodge answering an
        // availability request with the folder name) it is not the agency coming back → only a possible wake-up.
        $p = strrpos($from, '@');
        $dom = $p === false ? '' : substr($from, $p + 1);
        $senderOk = isset($r['agency_addrs'][$from]) || ($dom !== '' && isset($r['agency_doms'][$dom])) || in_array($from, $own, true)
                 || (function_exists('sr_is_free') && sr_is_free($dom));
        return $byName + ['wake' => $senderOk] + ($senderOk ? [] : ['note' => 'sender is not the agency / client']);
    }
    $trusted = $r['trusted'] ?? [];
    if ($trusted) {
        $ids = array_filter(array_merge([pk_msgid((string)$m['in_reply_to'])], explode(' ', strtolower((string)$m['refs']))));
        foreach ($ids as $id) if (isset($trusted[$id])) return ['rule' => 'thread', 'why' => 'same thread as <' . $id . '>', 'wake' => true];
    }
    if (($r['kind'] ?? '') === 'direct' && $from !== '' && in_array($from, $own, true)) {
        return ['rule' => 'client', 'why' => 'client e-mail', 'wake' => true];
    }
    return null;
}

function pk_addrs(string $s): array {
    preg_match_all("/[a-z0-9._%+'=-]+@[a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,}/i", $s, $m);
    return array_values(array_unique(array_map('strtolower', $m[0])));
}

/**
 * Message-IDs tied to each request ($ids) for the thread rule: timeline refs.mail_message_id; Sales Response links by
 * folder name (match_rule 'subject', not ambiguous); for direct clients, mails from / to the client's own address; then,
 * in date order, linked mails replying to one of those (In-Reply-To / References). → [request_id => [message_id => true]]
 */
function pk_trusted_ids(PDO $db, array $reqs): array {
    $out = [];
    if (!$reqs) return $out;
    $in = implode(',', array_map('intval', array_keys($reqs)));
    try {
        foreach ($db->query("SELECT request_id, JSON_UNQUOTE(JSON_EXTRACT(refs, '$.mail_message_id')) AS mid FROM request_timeline
                             WHERE request_id IN ($in) AND JSON_EXTRACT(refs, '$.mail_message_id') IS NOT NULL") as $t) {
            $mid = pk_msgid((string)$t['mid']);
            if ($mid !== '' && $mid !== 'null') $out[(int)$t['request_id']][$mid] = true;
        }
    } catch (Throwable $e) {
        error_log('pk_trusted_ids timeline: ' . $e->getMessage());
    }
    try {
        $st = $db->query("SELECT request_id, message_id, in_reply_to, refs, match_rule, ambiguous, direction, from_addr, to_addrs
                          FROM mail_headers_index WHERE request_id IN ($in) AND message_id IS NOT NULL
                          ORDER BY date_utc, id");
        foreach ($st as $m) {
            $rid = (int)$m['request_id'];
            $mid = (string)$m['message_id'];
            if (isset($out[$rid][$mid])) continue;
            $r = $reqs[$rid];
            $ok = $m['match_rule'] === 'subject' && !(int)$m['ambiguous'];
            if (!$ok && ($r['kind'] ?? '') === 'direct' && ($own = pk_addrs((string)$r['email']))) {
                $cp = $m['direction'] === 'client' ? [strtolower((string)$m['from_addr'])] : pk_addrs((string)$m['to_addrs']);
                $ok = (bool)array_intersect($cp, $own);
            }
            if (!$ok && !empty($out[$rid])) {
                foreach (array_filter(array_merge([(string)$m['in_reply_to']], explode(' ', (string)$m['refs']))) as $p) {
                    if (isset($out[$rid][$p])) { $ok = true; break; }
                }
            }
            if ($ok) $out[$rid][$mid] = true;
        }
    } catch (Throwable $e) {
        error_log('pk_trusted_ids mails: ' . $e->getMessage());
    }
    return $out;
}

/**
 * Parked requests with what the matching needs: id => row + kind, wake_after, keys, trusted, agency_addrs, agency_doms.
 * $ids limits to those requests (tests / Agent API).
 */
function pk_parked_for_wake(PDO $db, array $ids = []): array {
    require_once __DIR__ . '/sales_response_service.php';   // sr_request_kind(), sr_is_own(), sr_is_free(), sr_domain()
    $chn = sr_has_col($db, 'requests', 'channel') ? 'r.channel' : 'NULL';
    $rows = $db->query("SELECT r.id, r.practice_code, r.customer_name, r.email, r.agency_id, $chn AS channel,
                               COALESCE(r.parked_wake_after, r.parked_at) AS wake_after
                        FROM requests r WHERE r.status = 'Parked'"
                       . ($ids ? " AND r.id IN (" . implode(',', array_map('intval', $ids)) . ")" : "") . " ORDER BY r.id")
              ->fetchAll(PDO::FETCH_ASSOC);
    $agency = [];
    try {
        foreach ($db->query("SELECT id AS agency_id, email FROM agencies WHERE email IS NOT NULL AND email <> ''
                             UNION ALL SELECT agency_id, email FROM agency_contacts WHERE email IS NOT NULL AND email <> ''") as $a) {
            foreach (pk_addrs((string)$a['email']) as $x) $agency[(int)$a['agency_id']][$x] = true;
        }
    } catch (Throwable $e) {
        // agencies / agency_contacts missing: request e-mail only
    }
    $reqs = [];
    foreach ($rows as $r) {
        $r['id'] = (int)$r['id'];
        $r['kind'] = sr_request_kind(strtolower(trim((string)$r['channel'])), (int)$r['agency_id'], (string)$r['practice_code']);
        $r['keys'] = pk_file_keys($r);
        $addrs = array_keys($agency[(int)$r['agency_id']] ?? []);
        if ($r['kind'] !== 'direct') $addrs = array_merge($addrs, pk_addrs((string)$r['email']));
        $r['agency_addrs'] = $r['agency_doms'] = [];
        foreach (array_unique($addrs) as $a) {
            if (sr_is_own($a)) continue;
            $r['agency_addrs'][$a] = true;
            $d = sr_domain($a);
            if ($d !== '' && !sr_is_free($d)) $r['agency_doms'][$d] = true;
        }
        $reqs[$r['id']] = $r;
    }
    foreach (pk_trusted_ids($db, $reqs) as $rid => $set) $reqs[$rid]['trusted'] = $set;
    return $reqs;
}

/**
 * Match the recent client mails against the Parked requests. Pure over its inputs: $reqs from pk_parked_for_wake(),
 * $mails = unique client mails (msg_key, message_id, from_addr, subject, in_reply_to, refs, date_utc), oldest first.
 * → ['wake' => [request_id => {mail, rule, why}] (the first matching mail), 'possible' => [{mail, candidates: [{id, why}]}]]
 * possible = mails that woke nothing but come from the agency (address, or company domain) of Parked requests dated
 * after their parked_wake_after ("same agency, no folder name / thread"), or name a Parked folder from another
 * company's address.
 */
function pk_match_mails(array $reqs, array $mails): array {
    $wake = $possible = [];
    foreach ($reqs as $rid => $r) if (!isset($r['own'])) $reqs[$rid]['own'] = pk_addrs((string)($r['email'] ?? ''));
    foreach ($mails as $m) {
        $m['subj_c'] = pk_compact((string)$m['subject']);
        $hit = false;
        $cand = [];
        foreach ($reqs as $rid => $r) {
            $res = pk_mail_match($r, $m);
            if (!$res) continue;
            if (empty($res['wake'])) { $cand[$rid] = $res['why'] . ' – ' . $res['note']; continue; }
            $hit = true;
            if (!isset($wake[$rid])) $wake[$rid] = ['mail' => $m, 'rule' => $res['rule'], 'why' => $res['why']];
        }
        if ($hit) continue;
        $from = strtolower(trim((string)$m['from_addr']));
        $p = strrpos($from, '@');
        $dom = $p === false ? '' : substr($from, $p + 1);
        foreach ($reqs as $rid => $r) {
            if (isset($cand[$rid]) || isset($wake[$rid]) || ($r['kind'] ?? '') === 'direct') continue;
            if (!empty($r['wake_after']) && strtotime($m['date_utc'] . ' UTC') <= strtotime((string)$r['wake_after'])) continue;
            if (isset($r['agency_addrs'][$from]) || ($dom !== '' && isset($r['agency_doms'][$dom]))) $cand[$rid] = 'same agency, no folder name / thread';
        }
        if ($cand) {
            $list = [];
            foreach ($cand as $rid => $why) $list[] = ['id' => $rid, 'why' => $why];
            $possible[] = ['mail' => $m, 'candidates' => $list];
        }
    }
    return ['wake' => $wake, 'possible' => $possible];
}

/** Unique client mails of the last PK_WAKE_LOOKBACK_DAYS days dated after $sinceEat (EAT), oldest first. */
function pk_recent_client_mails(PDO $db, string $sinceEat): array {
    $floor = max(strtotime($sinceEat), time() - PK_WAKE_LOOKBACK_DAYS * 86400);
    $st = $db->prepare("SELECT msg_key, message_id, from_addr, subject, in_reply_to, refs, date_utc, mailbox
                        FROM mail_headers_index WHERE direction = 'client' AND date_utc > ? ORDER BY date_utc, id");
    $st->execute([gmdate('Y-m-d H:i:s', $floor)]);
    $out = [];
    foreach ($st as $m) if (!isset($out[$m['msg_key']])) $out[$m['msg_key']] = $m;
    return array_values($out);
}

/** The matching for the Parked requests ($ids: only those) → pk_match_mails() result + 'reqs'. Throws without mail_headers_index. */
function pk_wake_scan(PDO $db, array $ids = []): array {
    pk_schema($db);
    $reqs = pk_parked_for_wake($db, $ids);
    if (!$reqs) return ['reqs' => [], 'wake' => [], 'possible' => []];
    $since = min(array_map(function ($r) { return (string)($r['wake_after'] ?: '1970-01-01 00:00:00'); }, $reqs));
    return ['reqs' => $reqs] + pk_match_mails($reqs, pk_recent_client_mails($db, $since));
}

/** "mail from x@y.it, 20 Nov 10:12 – Subject" (mail date in EAT). */
function pk_mail_text(array $m): string {
    $subj = trim((string)$m['subject']) !== '' ? trim((string)$m['subject']) : '(no subject)';
    return 'mail from ' . $m['from_addr'] . ', ' . date('d M Y H:i', strtotime($m['date_utc'] . ' UTC')) . ' – ' . mb_substr($subj, 0, 120);
}

/**
 * Wake up the Parked requests a recent client mail is about (rules above). Called from ck_cron.php every 30 min (Sales
 * Response indexes hourly). The timeline event says why: "Reactivated: folder name in subject – mail from …, <date> – …".
 * $opt: dry_run (nothing written), ids (only those requests).
 * → ['checked' => n Parked, 'dry_run', 'woken' => [{id, folder, rule, why, from, subject, mail_at, status_to}], 'possible' => n]
 */
function pk_wake_from_mail(PDO $db, array $opt = []): array {
    pk_schema($db);
    $dry = !empty($opt['dry_run']);
    $out = ['checked' => (int)$db->query("SELECT COUNT(*) FROM requests WHERE status = 'Parked'")->fetchColumn(), 'dry_run' => $dry,
            'woken' => [], 'possible' => 0];
    if (!$out['checked']) return $out;
    try {
        $scan = pk_wake_scan($db, (array)($opt['ids'] ?? []));
    } catch (Throwable $e) {   // Sales Response not set up (no mail_headers_index)
        error_log('pk_wake_from_mail: ' . $e->getMessage());
        return $out;
    }
    $out['possible'] = count($scan['possible']);
    foreach ($scan['wake'] as $rid => $w) {
        $m = $w['mail'];
        $res = pk_wake($db, $rid, $w['why'] . ' – ' . pk_mail_text($m), ['dry_run' => $dry]);
        if ($res['woken']) {
            $out['woken'][] = ['id' => $rid, 'folder' => $scan['reqs'][$rid]['keys']['label'], 'rule' => $w['rule'], 'why' => $w['why'],
                               'from' => $m['from_addr'], 'subject' => (string)$m['subject'],
                               'mail_at' => date('Y-m-d H:i', strtotime($m['date_utc'] . ' UTC')), 'status_to' => $res['status_to']];
        }
    }
    return $out;
}

/**
 * "Possibili risvegli": recent mails from the agency of Parked requests that name none of them (no folder name, no
 * thread) — Roberto decides by hand. Newest first. $days ≤ PK_WAKE_LOOKBACK_DAYS. Never throws ([] on error).
 * → [{from, subject, mail_at (EAT), mailbox, candidates: [{id, folder, customer_name, why}]}]
 */
function pk_possible_wakeups(PDO $db, int $days = PK_WAKE_LOOKBACK_DAYS): array {
    try {
        $scan = pk_wake_scan($db);
    } catch (Throwable $e) {
        error_log('pk_possible_wakeups: ' . $e->getMessage());
        return [];
    }
    $floor = time() - max(1, min(PK_WAKE_LOOKBACK_DAYS, $days)) * 86400;
    $out = [];
    foreach (array_reverse($scan['possible']) as $p) {
        $ts = strtotime($p['mail']['date_utc'] . ' UTC');
        if ($ts < $floor) continue;
        $c = [];
        foreach ($p['candidates'] as $x) {
            $r = $scan['reqs'][$x['id']];
            $c[] = ['id' => $x['id'], 'folder' => $r['keys']['label'], 'customer_name' => $r['customer_name'], 'why' => $x['why']];
        }
        $out[] = ['from' => $p['mail']['from_addr'], 'subject' => (string)$p['mail']['subject'], 'mail_at' => date('Y-m-d H:i', $ts),
                  'mailbox' => $p['mail']['mailbox'] ?? null, 'candidates' => $c];
    }
    return $out;
}

/**
 * Daily close: Parked requests whose trip is over → Lost, reason no_more_replies, note "Closed automatically: …",
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
            $note = 'Closed automatically: parked since ' . ($r['parked_at'] ? date('d M Y', strtotime((string)$r['parked_at'])) : '?')
                  . ($t['basis'] === 'parked_age' ? ', no trip date, over ' . pk_stale_days() . ' days' : ', trip date passed');
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
          . ($w['woken'] ? ' (' . implode(', ', array_map(function ($x) { return $x['id'] . ' ' . $x['rule']; }, $w['woken'])) . ')' : '')
          . ', ' . $w['possible'] . ' possible';
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
                          AND (t.title LIKE 'Reactivated:%' OR t.title LIKE 'Parked auto-close%')
                        ORDER BY t.event_at DESC LIMIT " . max(1, min(200, $limit)));
    $st->execute([date('Y-m-d H:i:s', strtotime('-' . max(1, $days) . ' days'))]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $out[] = ['request_id' => (int)$e['request_id'], 'customer_name' => $e['customer_name'], 'status_now' => $e['status'],
                  'event_at' => substr((string)$e['event_at'], 0, 16),
                  'kind' => strpos($e['title'], 'Reactivated:') === 0 ? 'wakeup' : 'autoclose', 'title' => $e['title'], 'body' => $e['body']];
    }
    return $out;
}
