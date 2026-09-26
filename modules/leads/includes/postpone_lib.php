<?php
/**
 * postpone_lib.php — move a confirmed private safari to other dates.
 *
 * Two cases (see backoffice.php, postponed.php, ck_cron.php):
 *
 *  A. Reschedule — the new dates are known. The folder gets the new date
 *     prefix and _START/_MIDT/_END, keeps its tail (payment tag, _CK…) and goes
 *     (back) to the top of /001_Safari. Hub dates follow, a note is added, the
 *     booking team gets an email listing the suppliers to move, SafariCheck runs.
 *
 *  B. Postpone — no new dates yet ("within 12 months"). The dates in the name
 *     are replaced by _POSTPONED, the folder moves to /001_Safari/00_POSTPONED
 *     (out of the operational lists, the CK tracker and the nightly check) and
 *     the request records when, until when (default: 12 months from the
 *     postponement) and from which dates. The agent and the admins get reminders
 *     60 and 30 days before the deadline and when it has passed. When the dates
 *     arrive, Reschedule brings the folder back.
 *
 * Private safaris only (a GRP's dates live on the shared group folder).
 * Requires: config.php, dropbox_helper.php, includes/folder_parser.php,
 * includes/ck_lib.php, and backoffice.php's bo_confirmed_name / bo_url_from_path
 * / bo_path_from_url / $CONFIRM_MONTHS for the actions.
 */

const PP_DIR = '/001_Safari/00_POSTPONED';

/** Postponement columns on requests (created on first use, like the rest of the Hub). */
function pp_ensure_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $have = $db->query("SHOW COLUMNS FROM requests")->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'postponed_at'   => 'DATE NULL',          // day the safari was postponed
        'postpone_until' => 'DATE NULL',          // deadline to give the new dates
        'postponed_from' => 'VARCHAR(255) NULL',  // folder name with the original dates
        'pp_reminded'    => 'VARCHAR(10) NULL',   // last reminder sent: d60 / d30 / expired
    ] as $col => $def) {
        if (!in_array($col, $have, true)) $db->exec("ALTER TABLE requests ADD COLUMN $col $def");
    }
    $done = true;
}

/**
 * Split a booking folder name into the customer part and the tail after the
 * dates: '01_15JAN_Cust(Ag)_START15JAN_MIDT21JAN_END28JAN2027_BALANCE_CK'
 * → ['cust' => 'Cust(Ag)', 'rest' => '_BALANCE_CK', 'dated' => true].
 * A postponed name 'Cust(Ag)_POSTPONED_BALANCE' gives dated = false.
 */
function pp_split(string $name): ?array {
    if (preg_match('/^(?:\d{2}_\d{2}[A-Z]{3}_)?(.+?)_START\d{2}[A-Z]{3}(?:_MIDT\d{2}[A-Z]{3})*_END\d{2}[A-Z]{3}\d{4}(.*)$/i', $name, $m)) {
        return ['cust' => $m[1], 'rest' => $m[2], 'dated' => true];
    }
    if (preg_match('/^(.+?)_POSTPONED(.*)$/i', $name, $m)) {
        return ['cust' => $m[1], 'rest' => $m[2], 'dated' => false];
    }
    return null;
}

/** '15 Jan – 28 Jan 2027' from a dated folder name ('' if it has no dates). */
function pp_dates_label(string $name): string {
    $d = parse_folder_dates($name);
    if (empty($d['start_date']) || empty($d['end_date'])) return '';
    return date('d M', strtotime($d['start_date'])) . ' – ' . date('d M Y', strtotime($d['end_date']));
}

/** Supplier documents in the folder (invoices/, vouchers/): who has to be told. */
function pp_supplier_files(string $token, string $path): array {
    $out = [];
    foreach (['invoices', 'vouchers'] as $sub) {
        try {
            foreach (dropbox_list_files($token, $path . '/' . $sub) as $f) $out[] = "$sub/$f";
        } catch (Throwable $e) { /* missing subfolder: nothing to list */ }
    }
    return $out;
}

/** Current Dropbox path of the request's folder (stored URL first, then search). */
function pp_current_path(string $token, array $r): ?string {
    $p = bo_path_from_url((string)($r['dropbox_url'] ?? ''));
    if ($p !== '' && dropbox_path_exists($token, $p)) return $p;
    return dropbox_find_folder($token, (string)$r['practice_code']);
}

/** Booking-team email (same recipients as the confirmation email). */
function pp_booking_email(array $r, string $subject, string $body, string $agentEmail): array {
    $to = 'accountant@savannahexplorers.com, glady@savannahexplorers.com, operations@savannahexplorers.com';
    $cc = implode(', ', array_filter([$agentEmail, 'savannah.explorers@gmail.com']));
    return ['to' => $to, 'cc' => $cc, 'subject' => $subject, 'body' => $body, 'request_id' => (int)$r['id']];
}

function pp_agent_email(PDO $db, $agentId): string {
    if (!$agentId) return '';
    $st = $db->prepare("SELECT email FROM users WHERE agent_id = ? AND email IS NOT NULL AND email <> '' ORDER BY is_active DESC, id LIMIT 1");
    $st->execute([(int)$agentId]);
    return (string)($st->fetchColumn() ?: '');
}

function pp_add_note(PDO $db, int $reqId, ?int $uid, string $note): void {
    $db->prepare("INSERT INTO request_notes (request_id, user_id, created_by, note, note_type) VALUES (?, ?, ?, ?, 'manual')")
       ->execute([$reqId, (int)$uid, $uid, $note]);
}

/**
 * A. Reschedule to new dates. $dates = [start DDMMM, mid, mid2, end DDMMMYYYY]
 * (as in Confirm Safari). Returns ['ok', 'msg', 'email'?].
 */
function pp_reschedule(PDO $db, array $r, array $dates, array $months, ?int $uid, string $userName): array {
    pp_ensure_schema($db);
    $old = trim((string)$r['practice_code']);
    $sp  = pp_split($old);
    if (!$sp) return ['ok' => false, 'msg' => "\"$old\" has neither dates nor _POSTPONED — use Confirm Safari."];
    $errs = [];
    $built = bo_confirmed_name($sp['cust'], $dates[0], $dates[1], $dates[2], $dates[3], '', $months, $errs);
    if ($built === null) return ['ok' => false, 'msg' => implode(' ', $errs)];
    $new = substr($built, 0, -strlen('_PROGRESS')) . $sp['rest'];
    if ($new === $old) return ['ok' => false, 'msg' => 'The new dates are the same as the current ones.'];

    $token = dropbox_get_access_token();
    $cur   = pp_current_path($token, $r);
    if (!$cur) return ['ok' => false, 'msg' => "Folder \"$old\" not found in Dropbox — nothing changed."];
    $newPath = '/001_Safari/' . $new;
    dropbox_move_folder($token, $cur, $newPath);

    $from = $sp['dated'] ? pp_dates_label($old) : ('postponed, originally ' . (pp_dates_label((string)$r['postponed_from']) ?: '?'));
    $to   = pp_dates_label($new);
    $pd   = parse_folder_dates($new);
    $db->prepare("UPDATE requests SET practice_code = ?, dropbox_url = ?, start_date = ?,
                         postponed_at = NULL, postpone_until = NULL, pp_reminded = NULL WHERE id = ?")
       ->execute([$new, bo_url_from_path($newPath), $pd['start_date'], (int)$r['id']]);
    pp_add_note($db, (int)$r['id'], $uid, "Safari rescheduled: $from → $to. Folder: $old → $new.");

    // CK tracker: a top-level rename is recorded directly; a folder coming back
    // from 00_POSTPONED is picked up by the scan. Then SafariCheck runs on it.
    try {
        if ($sp['dated']) ck_record_rename($db, $old, $new, $uid);
        else ck_scan($db, $token);
        $st = $db->prepare("SELECT id FROM ck_folders WHERE folder_name = ? AND gone = 0");
        $st->execute([$new]);
        if ($ckId = (int)$st->fetchColumn()) ck_request_check($db, [$ckId], 'stage');
    } catch (Throwable $e) { /* the nightly scan/check catches up */ }

    $files = pp_supplier_files($token, $newPath);
    $body  = "Hi Glady/Lydia,\n\nthe safari of {$r['customer_name']} has been RESCHEDULED:\n\n"
           . "  from: $from\n  to:   $to\n\nFolder: $new\n\n"
           . "Please move all reservations to the new dates (and check room types/availability).\n"
           . ($files ? "\nSupplier documents in the folder:\n  - " . implode("\n  - ", $files) . "\n" : '')
           . "\nThanks,\n$userName";
    $subj  = preg_replace('/_START.*$/', '', preg_replace('/^\d+_\d+[A-Z]+_/', '', $new))
           . " safari RESCHEDULED $to";
    return ['ok' => true, 'msg' => "✔ {$r['customer_name']} rescheduled to $to → \"$new\".",
            'email' => pp_booking_email($r, $subj, $body, pp_agent_email($db, $r['agent_id'] ?? null))];
}

/** B. Postpone without new dates, until $until (Y-m-d). */
function pp_postpone(PDO $db, array $r, string $until, ?int $uid, string $userName): array {
    pp_ensure_schema($db);
    $old = trim((string)$r['practice_code']);
    $sp  = pp_split($old);
    if (!$sp || !$sp['dated']) return ['ok' => false, 'msg' => "\"$old\" has no dates to postpone."];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) || $until <= date('Y-m-d')) {
        return ['ok' => false, 'msg' => 'Give a deadline in the future.'];
    }
    $new = $sp['cust'] . '_POSTPONED' . $sp['rest'];

    $token = dropbox_get_access_token();
    $cur   = pp_current_path($token, $r);
    if (!$cur) return ['ok' => false, 'msg' => "Folder \"$old\" not found in Dropbox — nothing changed."];
    dropbox_create_folder($token, PP_DIR);          // no error if it already exists
    $newPath = PP_DIR . '/' . $new;
    dropbox_move_folder($token, $cur, $newPath);

    $from = pp_dates_label($old);
    $db->prepare("UPDATE requests SET practice_code = ?, dropbox_url = ?, postponed_at = CURDATE(),
                         postpone_until = ?, postponed_from = ?, pp_reminded = NULL WHERE id = ?")
       ->execute([$new, bo_url_from_path($newPath), $until, $old, (int)$r['id']]);
    $untilTxt = date('d M Y', strtotime($until));
    pp_add_note($db, (int)$r['id'], $uid, "Safari POSTPONED (was $from). New dates to be given by $untilTxt. Folder: $old → $new.");
    try { ck_scan($db, $token); } catch (Throwable $e) {}

    $files = pp_supplier_files($token, $newPath);
    $body  = "Hi Glady/Lydia,\n\nthe safari of {$r['customer_name']} ($from) has been POSTPONED.\n"
           . "The client will give the new dates by $untilTxt.\n\n"
           . "Please release / put on hold the reservations according to each supplier's policy.\n"
           . ($files ? "\nSupplier documents in the folder:\n  - " . implode("\n  - ", $files) . "\n" : '')
           . "\nFolder: " . PP_DIR . "/$new\n\nThanks,\n$userName";
    return ['ok' => true, 'msg' => "✔ {$r['customer_name']} postponed until $untilTxt → " . PP_DIR . "/$new.",
            'email' => pp_booking_email($r, $sp['cust'] . " safari POSTPONED ($from)", $body,
                                        pp_agent_email($db, $r['agent_id'] ?? null))];
}

/**
 * Daily reminders (ck_cron.php): 60 and 30 days before the deadline, and once
 * it has passed — to the booking's agent and to every active admin.
 */
function pp_send_reminders(PDO $db): string {
    pp_ensure_schema($db);
    $rank = ['' => 0, 'd60' => 1, 'd30' => 2, 'expired' => 3];
    $rows = $db->query("SELECT r.id, r.customer_name, r.practice_code, r.postponed_from, r.postponed_at,
                               r.postpone_until, r.pp_reminded, r.agent_id, a.name AS agent_name
                        FROM requests r LEFT JOIN agents a ON a.id = r.agent_id
                        WHERE r.postpone_until IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    $admins = $db->query("SELECT u.email FROM users u JOIN roles ro ON ro.id = u.role_id
                          WHERE ro.name = 'admin' AND u.is_active = 1 AND u.email <> ''")->fetchAll(PDO::FETCH_COLUMN);
    $sent = 0;
    foreach ($rows as $r) {
        $days  = (int)floor((strtotime($r['postpone_until']) - strtotime(date('Y-m-d'))) / 86400);
        $stage = $days < 0 ? 'expired' : ($days <= 30 ? 'd30' : ($days <= 60 ? 'd60' : ''));
        if ($stage === '' || $rank[$stage] <= $rank[$r['pp_reminded'] ?? '']) continue;
        $to = array_values(array_unique(array_filter(array_merge([pp_agent_email($db, $r['agent_id'])], $admins))));
        if (!$to) continue;
        $until = date('d M Y', strtotime($r['postpone_until']));
        $what  = $stage === 'expired'
            ? "the deadline ($until) has PASSED without new dates. Decide whether to cancel it (deposit per the T&C) or extend it."
            : "the new dates are due by $until ($days days left).";
        $subj  = "Postponed safari: {$r['customer_name']} — " . ($stage === 'expired' ? 'deadline passed' : "$days days left");
        $html  = '<p>Postponed safari <b>' . htmlspecialchars($r['customer_name']) . '</b> (originally '
               . htmlspecialchars(pp_dates_label((string)$r['postponed_from']) ?: '?') . ', agent '
               . htmlspecialchars($r['agent_name'] ?: '—') . '): ' . htmlspecialchars($what) . '</p>'
               . '<p>When the client gives the dates, use <b>Reschedule</b> in the Hub BackOffice.</p>'
               . '<p style="color:#999;font-size:12px">Savannah Explorers Hub — postponed safaris</p>';
        if (send_hub_email(implode(', ', $to), $subj, $html, 'Savannah Explorers Hub', 'noreply@savannahexplorers.com')) {
            $db->prepare("UPDATE requests SET pp_reminded = ? WHERE id = ?")->execute([$stage, (int)$r['id']]);
            $sent++;
        }
    }
    return "postponed reminders: $sent sent";
}
