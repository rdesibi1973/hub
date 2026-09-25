<?php
/**
 * ck_lib.php — CK tracker core.
 *
 * Follows every top-level booking folder in /001_Safari (the unit the old
 * MissingCK.bat checked: a private safari or a whole GRP) and records, in
 * ck_folders / ck_events, when it moved from _PROGRESS to _DEPOSIT/_BALANCE/…
 * and when it got (or lost) its _CK "checked" marker.
 *
 * The folder name stays the visible marker everyone already uses; the tables add
 * when / who / for how long. Folders are keyed by their Dropbox file ID, which
 * does not change on rename, so a status or _CK rename is seen as a change of
 * the same folder, not as a new one.
 *
 * Requires dropbox_helper.php (for ck_scan / ck_set_marker) and folder_parser.php.
 */

const CK_BASE = '/001_Safari';

/** Stages meaning "the booking team has finished booking" — CK is due from here. */
const CK_DONE_STAGES = ['Deposit', 'Balance', 'Balance-Cash', 'Paid'];

/** Stages meaning "still being booked". */
const CK_BOOKING_STAGES = ['Progress', 'Confirmed'];

/**
 * Destinations the old MissingCK.bat left out of the report (handled apart).
 * Matched anywhere in the folder name, case-insensitive.
 */
const CK_OTHER_DEST = ['KENYA', 'UGANDA', 'NAMIBIA', 'SUDAFRICA', 'SOUTHAFRICA', 'MADAGASCAR'];

/** Office time (the server runs on another timezone), 'Y-m-d H:i:s' or other format. */
function ck_now(string $fmt = 'Y-m-d H:i:s'): string {
    return (new DateTimeImmutable('now', new DateTimeZone('Africa/Dar_es_Salaam')))->format($fmt);
}

/** Create the tables if the migration was not run yet (idempotent, once per request). */
function ck_ensure_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS ck_folders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dropbox_id VARCHAR(64) NOT NULL,
        folder_name VARCHAR(255) NOT NULL,
        stage VARCHAR(20) NULL,
        has_ck TINYINT(1) NOT NULL DEFAULT 0,
        start_date DATE NULL,
        end_date DATE NULL,
        stage_since DATETIME NULL,
        booking_done_at DATETIME NULL,
        ck_at DATETIME NULL,
        ck_by INT NULL,
        first_seen_at DATETIME NOT NULL,
        last_seen_at DATETIME NOT NULL,
        gone TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_ck_folders_dbx (dropbox_id),
        KEY idx_ck_folders_name (folder_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS ck_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ck_folder_id INT NOT NULL,
        event VARCHAR(20) NOT NULL,
        from_value VARCHAR(255) NULL,
        to_value VARCHAR(255) NULL,
        user_id INT NULL,
        source VARCHAR(10) NOT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_ck_events_folder (ck_folder_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // SafariCheck results posted by the cloud runner (safariagent ci_check.py).
    $db->exec("CREATE TABLE IF NOT EXISTS ck_checks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ck_folder_id INT NOT NULL,
        created_at DATETIME NOT NULL,
        trigger_src VARCHAR(12) NULL,
        folder_name VARCHAR(255) NULL,
        overall VARCHAR(8) NULL,
        n_red INT NOT NULL DEFAULT 0,
        n_yellow INT NOT NULL DEFAULT 0,
        n_green INT NOT NULL DEFAULT 0,
        n_grey INT NOT NULL DEFAULT 0,
        fingerprint VARCHAR(40) NULL,
        checks_json MEDIUMTEXT NULL,
        facts_json MEDIUMTEXT NULL,
        files_json MEDIUMTEXT NULL,
        report_html MEDIUMTEXT NULL,
        error TEXT NULL,
        KEY idx_ck_checks_folder (ck_folder_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Columns added after the first release (MySQL: no ADD COLUMN IF NOT EXISTS).
    $have = $db->query("SHOW COLUMNS FROM ck_folders")->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'check_requested_at' => 'DATETIME NULL',
        'check_trigger'      => 'VARCHAR(12) NULL',
        'last_check_id'      => 'INT NULL',
        'last_checked_at'    => 'DATETIME NULL',
    ] as $col => $def) {
        if (!in_array($col, $have, true)) $db->exec("ALTER TABLE ck_folders ADD COLUMN $col $def");
    }
    $done = true;
}

/** Status stage from a folder name ("contains", longest first — tolerates _CK before or after). */
function ck_stage(string $name): ?string {
    static $tags = [
        '_BALANCE-CASH' => 'Balance-Cash',
        '_BALANCE_CASH' => 'Balance-Cash',
        '_BALANCE'      => 'Balance',
        '_DEPOSIT'      => 'Deposit',
        '_PAID'         => 'Paid',
        '_PROGRESS'     => 'Progress',
        '_CONFIRMED'    => 'Confirmed',
        '_PROVISIONAL'  => 'Provisional',
        '_CANCELLED'    => 'Cancelled',
        '_CANCELED'     => 'Cancelled',
    ];
    $up = strtoupper($name);
    foreach ($tags as $t => $s) if (strpos($up, $t) !== false) return $s;
    return null;
}

/** Does the folder carry the _CK marker (as its own token: "_CK" then "_" or end)? */
function ck_has_marker(string $name): bool {
    return (bool)preg_match('/_CK(?=_|$)/i', $name);
}

/** Name with the _CK marker added at the end / removed wherever it is. */
function ck_name_with_marker(string $name, bool $on): string {
    $clean = preg_replace('/_CK(?=_|$)/i', '', $name);
    return $on ? $clean . '_CK' : $clean;
}

/** Sales person from the (…) block: last token that is not a channel / destination tag. */
function ck_agent_from_name(string $name): string {
    if (!preg_match('/\(([^)]+)\)/', $name, $m)) return '';
    static $skip = ['drct','sb','lam','ps','tz','kenya','trek','znz','uganda','namibia',
                    'southafrica','sudafrica','rwanda','madagascar','botswana','staff'];
    $toks = array_values(array_filter(array_map('trim', explode('-', $m[1])),
        fn($t) => $t !== '' && !in_array(strtolower($t), $skip, true)));
    return $toks ? (string)end($toks) : '';
}

/** Customer / group label: drop "01_03JAN_" and everything from "(" on. */
function ck_customer_label(string $name): string {
    $s = preg_replace('/^\d{1,2}_\d{1,2}[A-Za-z]{3}_/', '', $name);
    $p = strpos($s, '(');
    if ($p !== false) $s = substr($s, 0, $p);
    return trim(str_replace('_', ' ', $s));
}

function ck_is_other_destination(string $name): bool {
    $up = strtoupper($name);
    foreach (CK_OTHER_DEST as $d) if (strpos($up, $d) !== false) return true;
    return false;
}

/** Is a top-level /001_Safari entry a booking folder (not 00_2026, 00_CANCELED, …)? */
function ck_is_booking_folder(string $name): bool {
    if (preg_match('/^00_/', $name)) return false;
    return folder_is_booking_leaf($name);
}

function ck_log(PDO $db, int $folderId, string $event, ?string $from, ?string $to,
                ?int $userId, string $source, string $now): void {
    $db->prepare("INSERT INTO ck_events (ck_folder_id, event, from_value, to_value, user_id, source, created_at)
                  VALUES (?,?,?,?,?,?,?)")
       ->execute([$folderId, $event, $from, $to, $userId, $source, $now]);
}

/**
 * Bring a tracked folder up to date with its current name: log what changed
 * (rename, stage, _CK) and update the row. Shared by the Dropbox scan and by
 * renames done from the Hub (which know the user).
 *
 * Returns true when this change means "booking just finished" (first move to
 * Deposit/Balance/Balance-Cash/Paid) — the moment the automatic check is due.
 */
function ck_apply(PDO $db, array $row, string $newName, string $source, ?int $userId, string $now): bool {
    $bookingDone = false;
    $id       = (int)$row['id'];
    $newStage = ck_stage($newName);
    $newCk    = ck_has_marker($newName);
    $dates    = parse_folder_dates($newName);

    $set = ['folder_name' => $newName, 'last_seen_at' => $now, 'gone' => 0,
            'start_date' => $dates['start_date'], 'end_date' => $dates['end_date']];

    if ((int)$row['gone'] === 1) ck_log($db, $id, 'back', null, $newName, $userId, $source, $now);

    if ($row['folder_name'] !== $newName) {
        ck_log($db, $id, 'renamed', $row['folder_name'], $newName, $userId, $source, $now);
    }
    if (($row['stage'] ?? null) !== $newStage) {
        ck_log($db, $id, 'stage', $row['stage'], $newStage, $userId, $source, $now);
        $set['stage']       = $newStage;
        $set['stage_since'] = $now;
        if (in_array($newStage, CK_DONE_STAGES, true) && empty($row['booking_done_at'])) {
            $set['booking_done_at'] = $now;
            $bookingDone = true;
        }
    }
    if ((bool)$row['has_ck'] !== $newCk) {
        ck_log($db, $id, $newCk ? 'ck_set' : 'ck_removed', null, null, $userId, $source, $now);
        $set['has_ck'] = $newCk ? 1 : 0;
        $set['ck_at']  = $newCk ? $now : null;
        $set['ck_by']  = $newCk ? $userId : null;
    }

    $cols = implode(', ', array_map(fn($c) => "$c = ?", array_keys($set)));
    $db->prepare("UPDATE ck_folders SET $cols WHERE id = ?")
       ->execute([...array_values($set), $id]);

    // The request follows its folder: a renamed folder (in Dropbox or from the
    // Hub) updates practice_code/dropbox_url, and a new payment tag updates
    // payment_status, so the two never drift apart.
    if ($row['folder_name'] !== $newName) {
        $ps = in_array($newStage, CK_DONE_STAGES, true) ? $newStage : null;
        $db->prepare("UPDATE requests
                         SET practice_code = ?, dropbox_url = ?,
                             payment_status = IF(status = 'Booked' AND ? IS NOT NULL, ?, payment_status)
                       WHERE practice_code IN (?, ?)")
           ->execute([$newName, ck_url_from_path(CK_BASE . '/' . $newName), $ps, $ps,
                      $row['folder_name'], $newName]);
    }
    return $bookingDone;
}

/**
 * Scan the top of /001_Safari and record every change since the last scan.
 * The very first scan only takes a baseline: times are unknown (NULL) for
 * folders that already existed, so no fake "changed today" history is made.
 *
 * @return array{seen:int,new:int,changed:int,gone:int}
 */
function ck_scan(PDO $db, string $token): array {
    ck_ensure_schema($db);
    $now     = ck_now();
    $entries = array_filter(dropbox_list_folder_entries($token, CK_BASE),
                            fn($e) => ck_is_booking_folder($e['name']));

    $rows = [];
    foreach ($db->query("SELECT * FROM ck_folders", PDO::FETCH_ASSOC) as $r) $rows[$r['dropbox_id']] = $r;
    $initial = !$rows;

    $stats = ['seen' => count($entries), 'new' => 0, 'changed' => 0, 'gone' => 0];
    $ins = $db->prepare("INSERT IGNORE INTO ck_folders
        (dropbox_id, folder_name, stage, has_ck, start_date, end_date, stage_since, booking_done_at,
         ck_at, first_seen_at, last_seen_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)");

    $toCheck = [];   // folders whose booking just finished -> automatic check
    $db->beginTransaction();
    try {
        $seenIds = [];
        foreach ($entries as $e) {
            $seenIds[$e['id']] = true;
            $r = $rows[$e['id']] ?? null;
            if ($r === null) {
                $stage = ck_stage($e['name']);
                $ck    = ck_has_marker($e['name']);
                $d     = parse_folder_dates($e['name']);
                $ins->execute([
                    $e['id'], $e['name'], $stage, $ck ? 1 : 0, $d['start_date'], $d['end_date'],
                    $initial ? null : $now,
                    (!$initial && in_array($stage, CK_DONE_STAGES, true)) ? $now : null,
                    (!$initial && $ck) ? $now : null,
                    $now, $now,
                ]);
                if ($ins->rowCount()) {
                    $newId = (int)$db->lastInsertId();
                    ck_log($db, $newId, 'first_seen', null, $e['name'], null, 'scan', $now);
                    if (!$initial && in_array($stage, CK_DONE_STAGES, true)) $toCheck[] = $newId;
                    $stats['new']++;
                }
                continue;
            }
            $changed = $r['folder_name'] !== $e['name'] || (int)$r['gone'] === 1;
            if ($changed) {
                if (ck_apply($db, $r, $e['name'], 'scan', null, $now)) $toCheck[] = (int)$r['id'];
                $stats['changed']++;
            } else {
                $db->prepare("UPDATE ck_folders SET last_seen_at = ? WHERE id = ?")->execute([$now, (int)$r['id']]);
            }
        }
        // Folders no longer at the top of 001_Safari (archived to 00_YYYY, cancelled, …).
        foreach ($rows as $dbxId => $r) {
            if (isset($seenIds[$dbxId]) || (int)$r['gone'] === 1) continue;
            $db->prepare("UPDATE ck_folders SET gone = 1 WHERE id = ?")->execute([(int)$r['id']]);
            ck_log($db, (int)$r['id'], 'gone', $r['folder_name'], null, null, 'scan', $now);
            $stats['gone']++;
        }
        $db->commit();
    } catch (Throwable $ex) {
        $db->rollBack();
        throw $ex;
    }
    if ($toCheck) {
        try { ck_request_check($db, $toCheck, 'stage'); }
        catch (Throwable $ig) { /* stays pending — the nightly run picks it up */ }
    }
    $stats['checks'] = count($toCheck);
    return $stats;
}

/**
 * Record a rename done from the Hub (e.g. BackOffice status change) so the
 * history carries the user. No-op if the folder is not tracked.
 */
function ck_record_rename(PDO $db, string $oldName, string $newName, ?int $userId): void {
    ck_ensure_schema($db);
    $st = $db->prepare("SELECT * FROM ck_folders WHERE folder_name = ? AND gone = 0 LIMIT 1");
    $st->execute([$oldName]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && ck_apply($db, $row, $newName, 'hub', $userId, ck_now())) {
        try { ck_request_check($db, [(int)$row['id']], 'stage'); } catch (Throwable $ig) {}
    }
}

/** Dropbox web URL from an API path (same shape as backoffice.php). */
function ck_url_from_path(string $path): string {
    return 'https://www.dropbox.com/home/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
}

/**
 * Keep the requests table in step after a top-level folder rename: a private
 * safari's practice_code, or every GRP member's group_folder + dropbox_url.
 * Mirrors bo_do_rename() in backoffice.php.
 */
function ck_sync_requests(PDO $db, string $old, string $new): int {
    $n = 0;
    $st = $db->prepare("SELECT id, practice_code FROM requests WHERE group_folder = ?");
    $st->execute([$old]);
    $upd = $db->prepare("UPDATE requests SET group_folder = ?, dropbox_url = ? WHERE id = ?");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $sub = trim($g['practice_code'] ?? '');
        $upd->execute([$new, ck_url_from_path(CK_BASE . '/' . $new . ($sub !== '' ? '/' . $sub : '')), (int)$g['id']]);
        $n++;
    }
    $st = $db->prepare("UPDATE requests SET practice_code = ?, dropbox_url = ?
                        WHERE practice_code = ? AND (group_folder IS NULL OR group_folder = '')");
    $st->execute([$new, ck_url_from_path(CK_BASE . '/' . $new), $old]);
    return $n + $st->rowCount();
}

/**
 * Add ($on) or remove the _CK marker: rename the Dropbox folder, sync the
 * requests, and log it with the user. Dropbox first; the DB only if it succeeds.
 *
 * @return array{ok:bool,msg:string}
 */
function ck_set_marker(PDO $db, string $token, int $folderId, bool $on, ?int $userId): array {
    ck_ensure_schema($db);
    $st = $db->prepare("SELECT * FROM ck_folders WHERE id = ?");
    $st->execute([$folderId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['gone'] === 1) return ['ok' => false, 'msg' => 'Folder not found in 001_Safari.'];

    $old = $row['folder_name'];
    if (ck_has_marker($old) === $on) {
        return ['ok' => false, 'msg' => $on ? 'The folder already has _CK.' : 'The folder has no _CK.'];
    }
    $new = ck_name_with_marker($old, $on);
    try {
        dropbox_move_folder($token, CK_BASE . '/' . $old, CK_BASE . '/' . $new);
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => 'Dropbox rename failed — nothing changed (the folder may have been renamed meanwhile; reload). ' . $e->getMessage()];
    }
    $n = ck_sync_requests($db, $old, $new);
    if (ck_apply($db, $row, $new, 'hub', $userId, ck_now())) {
        try { ck_request_check($db, [(int)$row['id']], 'stage'); } catch (Throwable $ig) {}
    }
    return ['ok' => true, 'msg' => ($on ? '✅ CK set: ' : '↩ CK removed: ') . $new
                                   . ($n ? " ($n request" . ($n === 1 ? '' : 's') . ' updated)' : '')];
}

// ── Automatic SafariCheck (safariagent on GitHub Actions) ─────────────────────
// Server-only settings in includes/config.php:
//   CK_AGENT_TOKEN   token the runner sends to ck_agent_api.php (GitHub secret HUB_TOKEN)
//   CK_GITHUB_TOKEN  fine-grained GitHub token, "Actions: read and write" on the repo
//   CK_GITHUB_REPO   'owner/repo' of the safariagent repository
// Without the GitHub settings the request stays pending and the nightly run does it.

/** Top-level files and invoices/*.pdf are the only files the checks open
 *  (plus SafariCheck_confirmed.json, the human confirmations — ck_confirm.php). */
function ck_agent_file_allowed(string $rel): bool {
    if ($rel === '' || strpos($rel, "\\") !== false) return false;
    $parts = explode('/', trim($rel, '/'));
    // Block path traversal ('..' / '.' segments) — but a file NAME may contain
    // '..' (e.g. 'KIBO PALACE..pdf'), which must still be served.
    foreach ($parts as $p) if ($p === '' || $p === '.' || $p === '..') return false;
    $name  = strtolower(end($parts));
    if (count($parts) === 1) {
        if ($name === 'safaricheck_confirmed.json') return true;
        return (bool)preg_match('/\.(xlsx|xlsm|docx|pdf)$/', $name) && strpos($name, '~$') !== 0;
    }
    if (count($parts) !== 2) return false;
    $dir = strtolower($parts[0]);
    if ($dir === 'invoices') return str_ends_with($name, '.pdf');
    // GRP: each confirmed client's sub-folder holds that client's Word programme
    // (rooms sold). Only .docx, and never from the standard sub-folders.
    $internal = ['passports', 'flights', 'intflights', 'bookings', 'vouchers', 'insurance',
                 'complain', 'guestcomments', 'mails', 'old'];
    return !in_array($dir, $internal, true) && str_ends_with($name, '.docx') && strpos($name, '~$') !== 0;
}

/** Start the GitHub workflow for these ck_folders ids. */
function ck_github_dispatch(array $ids): array {
    if (!defined('CK_GITHUB_TOKEN') || !defined('CK_GITHUB_REPO') || CK_GITHUB_TOKEN === '' || CK_GITHUB_REPO === '') {
        return ['ok' => false, 'msg' => 'GitHub not configured — the check will run tonight.'];
    }
    $ch = curl_init('https://api.github.com/repos/' . CK_GITHUB_REPO . '/actions/workflows/ck.yml/dispatches');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . CK_GITHUB_TOKEN,
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: SavannahHub-CK',
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(['ref' => 'main', 'inputs' => ['ids' => implode(',', array_map('intval', $ids))]]),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 204) return ['ok' => true, 'msg' => 'Check started — result in about 2 minutes.'];
    return ['ok' => false, 'msg' => "GitHub dispatch failed (HTTP $code) — the check will run tonight. " . substr((string)$body, 0, 200)];
}

/** Mark folders as waiting for a check and start the runner for them. */
function ck_request_check(PDO $db, array $ids, string $trigger): array {
    ck_ensure_schema($db);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return ['ok' => false, 'msg' => 'Nothing to check.'];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("UPDATE ck_folders SET check_requested_at = ?, check_trigger = ? WHERE id IN ($in)")
       ->execute([ck_now(), $trigger, ...$ids]);
    return ck_github_dispatch($ids);
}

/**
 * Nightly automatic check, started by the Hub itself (ck_cron.php, called
 * every 30 min by cron-job.org): the first call after 05:00 Tanzania each day
 * dispatches the runner on the Hub queue. GitHub's own `schedule` trigger is
 * best-effort and was skipped, so it is not used.
 */
const CK_NIGHTLY_HOUR = 5;

function ck_nightly_dispatch(PDO $db): string {
    $db->exec("CREATE TABLE IF NOT EXISTS ck_meta (
        k VARCHAR(40) PRIMARY KEY,
        v VARCHAR(255) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $today = ck_now('Y-m-d');
    if ((int)ck_now('G') < CK_NIGHTLY_HOUR) return 'nightly: not yet (before ' . CK_NIGHTLY_HOUR . ':00)';
    $st = $db->prepare("SELECT v FROM ck_meta WHERE k = 'nightly_last'");
    $st->execute();
    if ($st->fetchColumn() === $today) return "nightly: already started today";
    $res = ck_github_dispatch([]);            // empty ids = the Hub queue
    if ($res['ok']) {
        $db->prepare("INSERT INTO ck_meta (k, v) VALUES ('nightly_last', ?)
                      ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$today]);
    }
    return 'nightly: ' . $res['msg'];
}
