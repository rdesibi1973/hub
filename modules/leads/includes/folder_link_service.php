<?php
/**
 * folder_link_service.php — stable link request ↔ Dropbox folder through the folder id.
 *
 * The Hub reads a request's folder from dropbox_url (req_folder_path()). A move / rename done outside the Hub
 * (Dropbox web, PC, Java GUI, Claude's Dropbox connector) leaves that path stale. Dropbox folder ids ("id:…") never
 * change on move or rename, so requests.dropbox_id (migration 082) is the anchor:
 *
 *   folder_heal()          every reader of the folder: stored path → else the id → rewrite dropbox_url / practice_code
 *                          (and group_folder for the whole group) + timeline event folder_relinked; the suffix of the new
 *                          name never changes the Hub status: a mismatch is a warning + a memo for Roberto.
 *   folder_locate()        read-only variant (no DB write): current path of a request's folder, or null.
 *   folder_id_store()      writers: save the id returned by create_folder / move (also folder_id_save()).
 *   folder_ids_backfill()  requests without an id: get_metadata(path) → id; not found = the broken links.
 *   folder_orphans_run()   unresolved requests + folders under the roots that no request knows → one memo (folders-orphans).
 *   folder_archive_cancelled()  /001_Safari/*_CANCELLED → /001_Safari/00_CANCELED/00_<trip year>/.
 *
 * A folder found at its stored path wins: if its id differs from the stored one (the Hub re-created the folder, e.g.
 * Confirm Safari CREATE GRP) the stored id is refreshed. Without an id, callers keep their old fallbacks (name search);
 * with an id that Dropbox no longer knows, the folder was deleted: clear error, never a guess by name.
 *
 * Requires the leads config.php (db(), DROPBOX_BASE_PATH) and booking_service.php; dropbox_helper.php is loaded here.
 * Keep PHP-7 compatible (no arrow functions / match / str_contains).
 */

require_once __DIR__ . '/booking_service.php';   // req_folder_path, bo_url_from_path, timeline_log
if (!function_exists('dropbox_get_metadata')) require_once __DIR__ . '/../dropbox_helper.php';   // (tests stub it)

/** Memo key of the nightly orphan report (one memo, rewritten, closed when the list is empty). */
const FL_ORPHAN_MEMO_KEY = 'folders-orphans';

/** Folders under the roots that are never bookings (config FOLDER_ORPHAN_SKIP replaces this list). "00_*" and years are always skipped. */
const FL_ORPHAN_SKIP_DEFAULT = ['99_office', 'MEDIVAC_COVERAGE', 'WMA', 'Claude outputs'];

/** Archive of cancelled bookings inside /001_Safari: <CK_BASE>/00_CANCELED/00_<year>. */
const FL_CANCELED_DIR = '00_CANCELED';

/** requests.dropbox_id, if migration 082 was not run (once per PHP request). */
function fl_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $n = (int)$db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND COLUMN_NAME = 'dropbox_id'")->fetchColumn();
        if ($n > 0) return;
        $db->exec("ALTER TABLE requests ADD COLUMN dropbox_id VARCHAR(64) NULL AFTER dropbox_url, ADD KEY idx_requests_dropbox_id (dropbox_id)");
    } catch (Throwable $e) {
        error_log('fl_schema: ' . $e->getMessage());
    }
}

/** 'id:…' from a Dropbox answer: create_folder_v2 / move_v2 ({metadata:{id}}) or get_metadata ({id}). '' if none. */
function folder_id_from_meta($res): string {
    if (!is_array($res)) return '';
    $id = (string)($res['metadata']['id'] ?? ($res['id'] ?? ''));
    return strpos($id, 'id:') === 0 ? $id : '';
}

/** Save a request's folder id ('' / invalid = nothing). Never throws. */
function folder_id_save(PDO $db, int $requestId, string $id): void {
    if ($requestId <= 0 || strpos($id, 'id:') !== 0) return;
    try {
        fl_schema($db);
        $db->prepare("UPDATE requests SET dropbox_id = ? WHERE id = ?")->execute([$id, $requestId]);
    } catch (Throwable $e) {
        error_log('folder_id_save: ' . $e->getMessage());
    }
}

/** Writers: save the id carried by a create_folder / move answer. Never throws. */
function folder_id_store(PDO $db, int $requestId, $dropboxRes): void {
    folder_id_save($db, $requestId, folder_id_from_meta($dropboxRes));
}

/** The request row with dropbox_id (callers often select a few columns only). */
function fl_row(PDO $db, array $r): array {
    if (array_key_exists('dropbox_id', $r) || empty($r['id'])) return $r;
    try {
        fl_schema($db);
        $st = $db->prepare("SELECT dropbox_id FROM requests WHERE id = ?");
        $st->execute([(int)$r['id']]);
        $r['dropbox_id'] = $st->fetchColumn() ?: null;
    } catch (Throwable $e) { $r['dropbox_id'] = null; }
    return $r;
}

/** Lower-case path for comparisons. */
function fl_lc(string $p): string {
    return function_exists('mb_strtolower') ? mb_strtolower($p) : strtolower($p);
}

/** Is $p equal to $base or inside it (case-insensitive)? */
function fl_under(string $p, string $base): bool {
    $p = fl_lc($p); $base = fl_lc(rtrim($base, '/'));
    return $p === $base || strpos($p, $base . '/') === 0;
}

/**
 * Current path of a request's folder without writing anything: the stored path if it exists, else the path of its
 * dropbox_id, else null. $folder (optional): the folder wanted when it is the group parent of the request
 * (rename of a group) — then the parent of the id's path is returned.
 */
function folder_locate(string $token, array $r, string $folder = ''): ?string {
    $stored = req_folder_path($r);
    if ($stored !== '' && ($folder === '' || strcasecmp(basename($stored), $folder) === 0)) {
        try { if (dropbox_path_exists($token, $stored)) return $stored; } catch (Throwable $ig) {}
    }
    $id = (string)($r['dropbox_id'] ?? '');
    if ($id === '') return null;
    try { $m = dropbox_get_metadata($token, $id); } catch (Throwable $e) { return null; }
    if (!$m || $m['tag'] !== 'folder') return null;
    $leaf = trim((string)($r['practice_code'] ?? ''));
    if ($folder === '' || strcasecmp($folder, $leaf) === 0) return $m['path'];
    $parent = rtrim(dirname($m['path']), '/');
    return ($parent !== '' && folder_is_booking_leaf(basename($parent))) ? $parent : null;
}

/**
 * Self-heal a request's folder link. Returns the row, brought up to date when the folder moved (dropbox_url,
 * practice_code, group_folder, dropbox_id), with '_folder' => [
 *     'state'    => 'ok' | 'relinked' | 'no_folder' | 'missing' (stored path gone, no id) | 'deleted' (id unknown to Dropbox) | 'error',
 *     'path'     => current path or null, 'id' => folder id or null, 'old_path' => stored path before a relink,
 *     'warnings' => [text], 'error' => text or null ]
 * At most one Dropbox resolution per request per PHP run (cached). Never throws.
 */
function folder_heal(PDO $db, string $token, array $r): array {
    static $cache = [];
    $rid = (int)($r['id'] ?? 0);
    if ($rid > 0 && isset($cache[$rid])) return array_merge($r, $cache[$rid]);

    $r = fl_row($db, $r);
    $info = ['state' => 'ok', 'path' => null, 'id' => $r['dropbox_id'] ?? null, 'old_path' => null, 'warnings' => [], 'error' => null];
    $stored = req_folder_path($r);
    $id = (string)($r['dropbox_id'] ?? '');
    $upd = [];
    try {
        if ($stored === '' && $id === '') {
            $info['state'] = 'no_folder';
        } else {
            $m = $stored !== '' ? dropbox_get_metadata($token, $stored) : null;
            if ($m && $m['tag'] === 'folder') {
                $info['path'] = $m['path'];
                if ($m['id'] !== '' && $m['id'] !== $id) {
                    if ($id !== '') error_log('folder_heal: request ' . $rid . ' folder id ' . $id . ' → ' . $m['id'] . ' (found at ' . $stored . ')');
                    folder_id_save($db, $rid, $m['id']);
                    $info['id'] = $upd['dropbox_id'] = $m['id'];
                }
            } elseif ($id === '') {
                $info['state'] = 'missing';
                $info['error'] = 'Folder not found at ' . $stored . ' and no Dropbox folder id is stored for this request (run the folder id backfill, or re-link it).';
            } else {
                $mi = dropbox_get_metadata($token, $id);
                if (!$mi || $mi['tag'] !== 'folder') {
                    $info['state'] = 'deleted';
                    $info['error'] = 'Folder deleted or not reachable in Dropbox (id ' . $id . ', last known at ' . ($stored ?: '—') . ').';
                } else {
                    $rel = fl_relink($db, $token, $r, $stored, $mi['path']);
                    $info['state'] = 'relinked';
                    $info['path'] = $mi['path'];
                    $info['old_path'] = $stored;
                    $info['warnings'] = $rel['warnings'];
                    $upd = $rel['row'];
                }
            }
        }
    } catch (Throwable $e) {
        $info['state'] = 'error';
        $info['error'] = 'Dropbox: ' . $e->getMessage();
    }
    $out = $upd + ['_folder' => $info];
    if ($rid > 0 && $info['state'] !== 'error') $cache[$rid] = $out;
    return array_merge($r, $out);
}

/**
 * Rewrite the requests whose folder is $old or inside it so that it becomes $new (Dropbox already moved it).
 * practice_code / group_folder equal to the old basename follow the new one. Returns [request ids changed].
 */
function fl_repath(PDO $db, string $old, string $new): array {
    $old = rtrim($old, '/'); $new = rtrim($new, '/');
    $oldBase = basename($old); $newBase = basename($new);
    $st = $db->prepare("SELECT id, practice_code, group_folder, dropbox_url FROM requests
                        WHERE practice_code = ? OR group_folder = ? OR dropbox_url LIKE ? OR dropbox_url LIKE ?");
    $st->execute([$oldBase, $oldBase, '%' . $oldBase . '%', '%' . rawurlencode($oldBase) . '%']);
    $upd = $db->prepare("UPDATE requests SET practice_code = ?, group_folder = ?, dropbox_url = ? WHERE id = ?");
    $ids = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $p = req_folder_path($o);
        if ($p === '' || !fl_under($p, $old)) continue;
        $np = $new . substr($p, strlen($old));
        $pc = (string)$o['practice_code'];
        $gf = $o['group_folder'];
        if (strcasecmp($pc, $oldBase) === 0) $pc = $newBase;
        if ($gf !== null && strcasecmp((string)$gf, $oldBase) === 0) $gf = $newBase;
        $upd->execute([$pc, $gf, bo_url_from_path($np), (int)$o['id']]);
        $ids[] = (int)$o['id'];
    }
    return $ids;
}

/**
 * The folder of $r moved from $old (stored path, may be '') to $new (path of its id): update the DB.
 * A group member whose group folder is gone from its old place moves the whole group (every member, group_folder).
 * Logs folder_relinked on every request changed; status suffix ≠ Hub status → warnings + memo.
 * Returns ['row' => fresh fields of $r, 'warnings' => [], 'changed' => [ids]].
 */
function fl_relink(PDO $db, string $token, array $r, string $old, string $new): array {
    $rid = (int)$r['id'];
    $warnings = [];
    $changed = [];
    $oldShown = $old !== '' ? $old : '(no stored path)';
    $gf = trim((string)($r['group_folder'] ?? ''));
    if ($old !== '' && $gf !== '' && strcasecmp(basename(dirname($old)), $gf) === 0
        && strcasecmp(dirname($old), dirname($new)) !== 0) {
        // The member's parent changed: the whole group moved / was renamed, or only this member left it.
        $parentGone = true;
        try { $parentGone = dropbox_get_metadata($token, dirname($old)) === null; } catch (Throwable $ig) {}
        if ($parentGone) {
            $changed = fl_repath($db, dirname($old), dirname($new));
            $old = rtrim(dirname($new), '/') . '/' . basename($old);
        } else {
            $warnings[] = 'The folder left its group folder ' . dirname($old) . ' — group_folder "' . $gf . '" kept; check the group.';
        }
    }
    if ($old === '') {
        $db->prepare("UPDATE requests SET practice_code = ?, dropbox_url = ? WHERE id = ?")
           ->execute([basename($new), bo_url_from_path($new), $rid]);
        $changed[] = $rid;
    } elseif (strcmp($old, $new) !== 0) {
        $changed = array_merge($changed, fl_repath($db, $old, $new));
    }
    if (!in_array($rid, $changed, true)) {   // stored path in an unusual shape: this row at least
        $db->prepare("UPDATE requests SET dropbox_url = ? WHERE id = ?")->execute([bo_url_from_path($new), $rid]);
        $changed[] = $rid;
    }
    $changed = array_values(array_unique($changed));

    foreach ($changed as $cid) {
        timeline_log($cid, 'folder_relinked', 'Folder auto-relinked',
                     ['body' => 'auto-relinked: ' . $oldShown . "\n→ " . $new, 'refs' => ['dropbox_path' => $new]]);
    }
    $warnings = array_merge($warnings, fl_status_check($db, $changed, $rid, $new));

    $st = $db->prepare("SELECT practice_code, group_folder, dropbox_url, dropbox_id FROM requests WHERE id = ?");
    $st->execute([$rid]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['row' => $row, 'warnings' => $warnings, 'changed' => $changed];
}

/**
 * After a relink: does the status suffix of the new names agree with the Hub? Never changes the status.
 * Mismatch → warnings + one memo for Roberto (ext_key folder-status:<request id>). Returns the warnings.
 */
function fl_status_check(PDO $db, array $ids, int $rid, string $newPath): array {
    if (!$ids) return [];
    require_once __DIR__ . '/folder_service.php';   // bo_status_from_name (the suffix parser of folder_rename)
    $st = $db->prepare("SELECT id, customer_name, practice_code, group_folder, status, payment_status FROM requests
                        WHERE id IN (" . implode(',', array_map('intval', $ids)) . ")");
    $st->execute();
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) {
        $gf = trim((string)$x['group_folder']);
        $name = $gf !== '' ? $gf : (string)$x['practice_code'];   // a group carries its status on the group folder
        $d = bo_status_from_name($name);
        if (!$d['matched']) continue;
        $psDiff = $gf === '' && $d['ps'] !== null && (string)$d['ps'] !== (string)$x['payment_status'];
        if ($d['status'] === $x['status'] && !$psDiff) continue;
        $out[] = 'Request ' . $x['id'] . ' (' . $x['customer_name'] . '): folder renamed to ' . $name . ' — Hub status is '
               . $x['status'] . ($x['payment_status'] ? ' / ' . $x['payment_status'] : '') . ', the suffix says ' . $d['status']
               . ($d['ps'] ? ' / ' . $d['ps'] : '') . '. Status not changed.';
    }
    if ($out) {
        $html = '<p>A booking folder was moved or renamed outside the Hub; the Hub re-linked it to <b>'
              . htmlspecialchars($newPath, ENT_QUOTES, 'UTF-8') . '</b> but did not change any status.</p><p>'
              . implode('<br>', array_map(function ($w) { return htmlspecialchars($w, ENT_QUOTES, 'UTF-8'); }, $out))
              . '</p><p>Set the right status in the Hub (or rename the folder back), then close this memo.</p>';
        fl_memo_upsert($db, 'folder-status:' . $rid, 'Folder renamed outside the Hub — status to check', $html, $rid, 'high');
    }
    return $out;
}

/**
 * Create or rewrite one open memo on Roberto's board (AGENT_MEMO_USER) by ext_key. Returns the memo id, or 0 when
 * there is no memo owner. Never throws.
 */
function fl_memo_upsert(PDO $db, string $key, string $title, string $html, ?int $requestId = null, string $priority = 'normal'): int {
    try {
        require_once __DIR__ . '/../../memo/memo_lib.php';
        memo_schema($db);
        $owner = memo_owner($db);
        if (!$owner) return 0;
        $now = date('Y-m-d H:i:s');
        $st = $db->prepare("SELECT id FROM memos WHERE user_id = ? AND ext_key = ? AND deleted_at IS NULL
                            AND status NOT IN ('done','archived') ORDER BY id DESC LIMIT 1");
        $st->execute([$owner['id'], $key]);
        $id = (int)($st->fetchColumn() ?: 0);
        $title = mb_substr($title, 0, 250);
        if ($id) {
            $db->prepare("UPDATE memos SET title = ?, body = ?, request_id = ?, updated_at = ? WHERE id = ?")
               ->execute([$title, $html, $requestId, $now, $id]);
            return $id;
        }
        $db->prepare("INSERT INTO memos (user_id, title, body, type, status, priority, pinned, color, due_date, reminder_at, reminder_emails,
                          reminder_sent, recur_rule, sort_order, request_id, source, ext_key, created_at, updated_at)
                      VALUES (?, ?, ?, 'todo', 'open', ?, 0, NULL, ?, NULL, NULL, 0, 'none', 0, ?, 'dropbox', ?, ?, ?)")
           ->execute([$owner['id'], $title, $html, $priority, date('Y-m-d'), $requestId, $key, $now, $now]);
        return (int)$db->lastInsertId();
    } catch (Throwable $e) {
        error_log('fl_memo_upsert: ' . $e->getMessage());
        return 0;
    }
}

/** Close the open memo with this ext_key on Roberto's board (if any). Returns true if one was closed. */
function fl_memo_close(PDO $db, string $key, string $note): bool {
    try {
        require_once __DIR__ . '/../../memo/memo_lib.php';
        $owner = memo_owner($db);
        if (!$owner) return false;
        $st = $db->prepare("SELECT id FROM memos WHERE user_id = ? AND ext_key = ? AND deleted_at IS NULL AND status NOT IN ('done','archived')");
        $st->execute([$owner['id'], $key]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) memo_close($db, (int)$id, $note);
        return (bool)$ids;
    } catch (Throwable $e) {
        error_log('fl_memo_close: ' . $e->getMessage());
        return false;
    }
}

// ── Backfill ─────────────────────────────────────────────────────────────────

/**
 * Requests with a folder and no dropbox_id: get_metadata(stored path) → id. Batches by id (after_id), at most
 * $opt['limit'] (default 100, max 300) rows or $opt['budget'] seconds (default 45). $opt['confirm']: save the ids
 * (otherwise count only). Returns ['checked', 'found', 'saved', 'not_found' => [{id, customer_name, folder, path, status}],
 * 'errors' => [], 'next_after_id', 'remaining', 'done'].
 */
function folder_ids_backfill(PDO $db, string $token, array $opt = []): array {
    fl_schema($db);
    $after   = max(0, (int)($opt['after_id'] ?? 0));
    $limit   = max(1, min(300, (int)($opt['limit'] ?? 100)));
    $until   = time() + max(5, (int)($opt['budget'] ?? 45));
    $confirm = !empty($opt['confirm']);
    $where   = "practice_code IS NOT NULL AND practice_code <> '' AND (dropbox_id IS NULL OR dropbox_id = '')";
    $st = $db->prepare("SELECT id, customer_name, practice_code, group_folder, dropbox_url, status FROM requests
                        WHERE $where AND id > ? ORDER BY id LIMIT $limit");
    $st->execute([$after]);
    $out = ['dry_run' => !$confirm, 'checked' => 0, 'found' => 0, 'saved' => 0, 'not_found' => [], 'errors' => [],
            'next_after_id' => $after, 'remaining' => 0, 'done' => false];
    $save = $db->prepare("UPDATE requests SET dropbox_id = ? WHERE id = ?");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (time() >= $until) break;
        $out['checked']++;
        $out['next_after_id'] = (int)$r['id'];
        $path = req_folder_path($r);
        try {
            $m = $path !== '' ? dropbox_get_metadata($token, $path) : null;
        } catch (Throwable $e) {
            $out['errors'][] = 'Request ' . $r['id'] . ': ' . $e->getMessage();
            continue;
        }
        if ($m && $m['tag'] === 'folder' && $m['id'] !== '') {
            $out['found']++;
            if ($confirm) { $save->execute([$m['id'], (int)$r['id']]); $out['saved']++; }
        } else {
            $out['not_found'][] = ['id' => (int)$r['id'], 'customer_name' => $r['customer_name'], 'folder' => $r['practice_code'],
                                   'path' => $path, 'status' => $r['status']];
        }
    }
    $rem = $db->prepare("SELECT COUNT(*) FROM requests WHERE $where AND id > ?");
    $rem->execute([$out['next_after_id']]);
    $out['remaining'] = (int)$rem->fetchColumn();
    $out['done'] = $out['remaining'] === 0;
    return $out;
}

// ── Orphan check ─────────────────────────────────────────────────────────────

/** Roots checked for orphans: the booking roots of folder_rename (/2026, /2027, /001_Safari — one list). */
function fl_roots(): array {
    require_once __DIR__ . '/folder_service.php';
    return fs_allowed_roots();
}

/** Names never reported as orphans (config FOLDER_ORPHAN_SKIP, else FL_ORPHAN_SKIP_DEFAULT). */
function fl_orphan_skip(): array {
    $list = defined('FOLDER_ORPHAN_SKIP') && is_array(FOLDER_ORPHAN_SKIP) ? FOLDER_ORPHAN_SKIP : FL_ORPHAN_SKIP_DEFAULT;
    return array_map('fl_lc', $list);
}

/** Is a direct child of a root a candidate booking folder (not 00_*, a year, the skip list, a container)? */
function fl_orphan_candidate(string $name): bool {
    if (preg_match('/^00_/', $name) || preg_match('/^\d{4}$/', $name)) return false;
    if (in_array(fl_lc($name), fl_orphan_skip(), true)) return false;
    return folder_is_booking_leaf($name);
}

/**
 * The nightly orphan check:
 *  - live requests (not Lost / Cancelled, trip not ended > 30 days, or a recent inquiry) whose folder cannot be resolved
 *    (stored path gone and id missing / unknown) — a folder found through its id is healed on the way;
 *  - folders directly under the roots that no request knows (id, path, folder or group name).
 * $opt: budget (seconds, default 90), max_checks (Dropbox lookups for requests, default 150), memo (bool: write / close
 * the folders-orphans memo). Returns ['unresolved' => [], 'healed' => [], 'orphans' => [], 'unchecked' => n, 'memo' => …].
 */
function folder_orphans_run(PDO $db, string $token, array $opt = []): array {
    fl_schema($db);
    $until = time() + max(10, (int)($opt['budget'] ?? 90));
    $maxChecks = max(0, (int)($opt['max_checks'] ?? 150));
    $out = ['roots' => fl_roots(), 'unresolved' => [], 'healed' => [], 'orphans' => [], 'unchecked' => 0, 'errors' => [], 'memo' => null];

    // Direct children of the roots: id → path.
    $listed = []; $listedPath = [];
    foreach ($out['roots'] as $root) {
        try {
            foreach (dropbox_list_folder_entries($token, $root) as $e) {
                $p = $root . '/' . $e['name'];
                $listed[$e['id']] = $p;
                $listedPath[fl_lc($p)] = $e['id'];
            }
        } catch (Throwable $e) {
            $out['errors'][] = $root . ': ' . $e->getMessage();
        }
    }

    // Every request: what the Hub knows (ids, paths and their parents, folder / group names).
    $knownId = []; $knownPath = []; $knownName = [];
    foreach ($db->query("SELECT id, practice_code, group_folder, dropbox_url, dropbox_id FROM requests
                         WHERE practice_code IS NOT NULL AND practice_code <> ''")->fetchAll(PDO::FETCH_ASSOC) as $x) {
        if (!empty($x['dropbox_id'])) $knownId[$x['dropbox_id']] = true;
        $p = req_folder_path($x);
        while ($p !== '' && $p !== '/' && substr_count($p, '/') >= 2) { $knownPath[fl_lc($p)] = true; $p = rtrim(dirname($p), '/'); }
        $knownName[fl_lc(trim((string)$x['practice_code']))] = true;
        if (trim((string)$x['group_folder']) !== '') $knownName[fl_lc(trim((string)$x['group_folder']))] = true;
    }

    // Live requests that must resolve.
    $st = $db->query("SELECT id, customer_name, practice_code, group_folder, dropbox_url, dropbox_id, status, payment_status, start_date
                      FROM requests
                      WHERE practice_code IS NOT NULL AND practice_code <> '' AND status NOT IN ('Lost','Cancelled')
                        AND (start_date >= CURDATE() - INTERVAL 30 DAY OR (start_date IS NULL AND date_received >= CURDATE() - INTERVAL 400 DAY))
                      ORDER BY start_date IS NULL, start_date, id");
    $checks = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $path = req_folder_path($r);
        $lp = fl_lc($path);
        if ($path !== '' && isset($listedPath[$lp])) {                       // found in the listing: no call
            if ((string)$r['dropbox_id'] !== $listedPath[$lp]) folder_id_save($db, (int)$r['id'], $listedPath[$lp]);
            continue;
        }
        if (time() >= $until || $checks >= $maxChecks) { $out['unchecked']++; continue; }
        $checks++;
        $h = folder_heal($db, $token, $r);
        $f = $h['_folder'];
        if ($f['state'] === 'relinked') {
            $out['healed'][] = ['id' => (int)$r['id'], 'customer_name' => $r['customer_name'], 'from' => $f['old_path'], 'to' => $f['path'],
                                'warnings' => $f['warnings']];
            if (!empty($h['dropbox_id'])) $knownId[$h['dropbox_id']] = true;
            $knownPath[fl_lc($f['path'])] = true;
            $knownName[fl_lc(basename($f['path']))] = true;
            if (trim((string)($h['group_folder'] ?? '')) !== '') $knownName[fl_lc(trim((string)$h['group_folder']))] = true;
        } elseif (in_array($f['state'], ['missing', 'deleted', 'no_folder'], true)) {
            $out['unresolved'][] = ['id' => (int)$r['id'], 'customer_name' => $r['customer_name'], 'folder' => $r['practice_code'],
                                    'status' => $r['status'], 'start_date' => $r['start_date'], 'path' => $path, 'why' => $f['error'] ?: $f['state']];
        } elseif ($f['state'] === 'error') {
            $out['errors'][] = 'Request ' . $r['id'] . ': ' . $f['error'];
        }
    }

    // Folders no request knows.
    foreach ($listed as $id => $p) {
        $name = basename($p);
        if (!fl_orphan_candidate($name)) continue;
        if (isset($knownId[$id]) || isset($knownPath[fl_lc($p)]) || isset($knownName[fl_lc($name)])) continue;
        $out['orphans'][] = ['path' => $p, 'id' => $id];
    }

    if (!empty($opt['memo'])) $out['memo'] = fl_orphans_memo($db, $out);
    return $out;
}

/** Write (or close, when the lists are empty) the folders-orphans memo. Returns 'written #id' | 'closed' | 'none'. */
function fl_orphans_memo(PDO $db, array $res): string {
    if (!$res['unresolved'] && !$res['orphans']) {
        return fl_memo_close($db, FL_ORPHAN_MEMO_KEY, 'Nothing left to fix — closed automatically.') ? 'closed' : 'none';
    }
    $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $base = 'https://hub.savannahexplorers.com/modules/leads/';
    $html = '<p>Nightly folder check (' . date('d M Y') . ').</p>';
    if ($res['unresolved']) {
        $html .= '<p><b>Requests whose Dropbox folder cannot be found (' . count($res['unresolved']) . ')</b> — re-link them on the request page '
               . '(🔗 Re-link / ✎ Pick manually):</p><p>';
        foreach ($res['unresolved'] as $u) {
            $html .= '<a href="' . $base . 'request_view.php?id=' . (int)$u['id'] . '">' . $e($u['customer_name']) . '</a> — ' . $e($u['status'])
                   . ($u['start_date'] ? ', start ' . $e($u['start_date']) : '') . '<br><small>' . $e($u['why']) . '</small><br>';
        }
        $html .= '</p>';
    }
    if ($res['orphans']) {
        $html .= '<p><b>Folders no Hub request points to (' . count($res['orphans']) . ')</b> — import them, re-link the right request, or archive them:</p><p>';
        foreach ($res['orphans'] as $o) {
            $html .= '<a href="' . $e(bo_url_from_path($o['path'])) . '">' . $e($o['path']) . '</a><br>';
        }
        $html .= '</p>';
    }
    if ($res['healed']) $html .= '<p>' . count($res['healed']) . ' folder(s) moved outside the Hub were re-linked automatically (see their timelines).</p>';
    if ($res['unchecked']) $html .= '<p><small>' . (int)$res['unchecked'] . ' request(s) not checked tonight (time budget) — next night.</small></p>';
    $n = count($res['unresolved']) + count($res['orphans']);
    $id = fl_memo_upsert($db, FL_ORPHAN_MEMO_KEY, 'Dropbox folders to fix: ' . $n, $html, null, 'normal');
    return $id ? 'written #' . $id : 'none (no memo owner)';
}

/** Once a day (first call after 05:00 EAT), from ck_cron.php after its reply. Returns a one-line summary. */
function folder_orphans_nightly(PDO $db): string {
    $db->exec("CREATE TABLE IF NOT EXISTS ck_meta (k VARCHAR(40) PRIMARY KEY, v VARCHAR(255) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ((int)date('G') < 5) return 'folders: not yet (before 05:00)';
    $st = $db->prepare("SELECT v FROM ck_meta WHERE k = 'folders_orphans_last'");
    $st->execute();
    if ($st->fetchColumn() === date('Y-m-d')) return 'folders: already checked today';
    $db->prepare("INSERT INTO ck_meta (k, v) VALUES ('folders_orphans_last', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([date('Y-m-d')]);
    $res = folder_orphans_run($db, dropbox_get_access_token(), ['memo' => true]);
    return 'folders: ' . count($res['unresolved']) . ' unresolved, ' . count($res['orphans']) . ' orphans, ' . count($res['healed'])
         . ' healed, ' . $res['unchecked'] . ' unchecked, memo ' . $res['memo'] . ($res['errors'] ? ', ' . count($res['errors']) . ' errors' : '');
}

// ── Archive cancelled folders ───────────────────────────────────────────────

/** Archive folder for a cancelled booking: <CK_BASE>/00_CANCELED/00_<year of the trip END, else this year>. */
function fl_cancel_archive_dir(string $name): string {
    $pd = parse_folder_dates($name);
    $year = !empty($pd['end_date']) ? substr($pd['end_date'], 0, 4) : (!empty($pd['start_date']) ? substr($pd['start_date'], 0, 4) : date('Y'));
    return '/001_Safari/' . FL_CANCELED_DIR . '/00_' . $year;
}

/**
 * Move cancelled booking folders out of /001_Safari into /001_Safari/00_CANCELED/00_<year>/ (year folder created if
 * missing), update the requests (path; status Cancelled) and log folder_relinked. $opt: request_id (only that request's
 * top folder: its group folder for a group member), confirm (bool, else a dry run listing the moves).
 * Returns ['dry_run', 'moves' => [{from, to, requests: [ids], status_set: [ids]}], 'errors' => [], 'skipped' => []].
 */
function folder_archive_cancelled(PDO $db, string $token, array $opt = []): array {
    fl_schema($db);
    $confirm = !empty($opt['confirm']);
    $out = ['dry_run' => !$confirm, 'moves' => [], 'errors' => [], 'skipped' => []];
    $folders = [];
    $rid = (int)($opt['request_id'] ?? 0);
    if ($rid > 0) {
        $st = $db->prepare("SELECT * FROM requests WHERE id = ?");
        $st->execute([$rid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return $out + ['error' => 'Request ' . $rid . ' not found.'];
        $r = folder_heal($db, $token, $r);
        $p = (string)($r['_folder']['path'] ?? '');
        if ($p === '') return $out + ['error' => 'Folder of request ' . $rid . ' not found: ' . ($r['_folder']['error'] ?? '')];
        $top = $p;   // the folder directly under /001_Safari (a member's group folder)
        while (strcasecmp(dirname($top), '/001_Safari') !== 0 && dirname($top) !== '/' && dirname($top) !== $top) $top = dirname($top);
        if (strcasecmp(dirname($top), '/001_Safari') !== 0) {
            return $out + ['error' => 'The folder is not directly in /001_Safari (' . $p . ') — nothing to archive.'];
        }
        if (!folder_is_cancelled(basename($top)) && $r['status'] !== 'Cancelled') {
            return $out + ['error' => basename($top) . ' is not cancelled (no _CANCELLED suffix, Hub status ' . $r['status'] . ').'];
        }
        $folders[] = basename($top);
    } else {
        foreach (dropbox_list_folder($token, '/001_Safari') as $name) {
            if (ck_is_booking_folder_safe($name) && folder_is_cancelled($name)) $folders[] = $name;
        }
    }
    $made = [];
    foreach ($folders as $name) {
        $from = '/001_Safari/' . $name;
        $dir  = fl_cancel_archive_dir($name);
        $to   = $dir . '/' . $name;
        $move = ['from' => $from, 'to' => $to, 'requests' => [], 'status_set' => []];
        $st = $db->prepare("SELECT id, practice_code, group_folder, dropbox_url, status FROM requests
                            WHERE practice_code = ? OR group_folder = ? OR dropbox_url LIKE ? OR dropbox_url LIKE ?");
        $st->execute([$name, $name, '%' . $name . '%', '%' . rawurlencode($name) . '%']);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) {
            if (fl_under(req_folder_path($x), $from)) { $rows[] = $x; $move['requests'][] = (int)$x['id']; }
        }
        foreach ($rows as $x) if ($x['status'] !== 'Cancelled') $move['status_set'][] = (int)$x['id'];
        try {
            if (dropbox_path_exists($token, $to)) { $out['skipped'][] = $to . ' already exists'; continue; }
        } catch (Throwable $e) { $out['errors'][] = $from . ': ' . $e->getMessage(); continue; }
        if (!$confirm) { $out['moves'][] = $move; continue; }
        try {
            if (!isset($made[$dir])) { dropbox_create_folder($token, $dir, false); $made[$dir] = true; }   // no error if it exists
            dropbox_move_folder($token, $from, $to);
        } catch (Throwable $e) {
            $out['errors'][] = $from . ': ' . $e->getMessage();
            continue;
        }
        $ids = fl_repath($db, $from, $to);
        if ($move['status_set']) {
            $db->prepare("UPDATE requests SET status = 'Cancelled' WHERE id IN (" . implode(',', $move['status_set']) . ")")->execute();
            folder_followup_cancelled($db, null, $name, 'folder archived ' . $to);
        }
        foreach (array_unique(array_merge($ids, $move['requests'])) as $cid) {
            timeline_log((int)$cid, 'folder_relinked', 'Folder archived (cancelled)' . (in_array($cid, $move['status_set'], true) ? ' — status Cancelled' : ''),
                         ['body' => $from . "\n→ " . $to, 'refs' => ['dropbox_path' => $to]]);
        }
        $out['moves'][] = $move;
    }
    return $out;
}

/** ck_is_booking_folder() without loading the CK tracker: not a 00_* container, looks like a booking. */
function ck_is_booking_folder_safe(string $name): bool {
    return !preg_match('/^00_/', $name) && folder_is_booking_leaf($name);
}
