<?php
/**
 * modules/iti/includes/iti_shared_service.php — shared ITI data (16 Nov 2026).
 *
 * Shared data = what one change applies to every program and every user: Calc aliases, lodges, activities,
 * destinations, transfer / flight routes, airlines, samples. Rules:
 *   - only an admin changes it on the Hub (iti_can_edit_shared()); the Agent API is Roberto's agent and
 *     changes it after his ok;
 *   - the staff map an unknown Calc text for THEIR booking only: a mapping proposal (iti_alias_proposals),
 *     used at once for that booking (iti_alias_lookup() with the request id) and for every other one only
 *     after the admin approves it — then it becomes an alias (ITI → Aliases, "Awaiting approval");
 *   - every change is logged in iti_change_log with the row before and after (iti_sh_log()), and an admin
 *     restores it from ITI → History (iti_sh_restore());
 *   - an admin sees what a change touches before saving (iti_sh_usage(), iti_sh_usage_box()).
 *
 * Self-contained (no iti_functions.php): the Agent API loads iti_content_service.php on its own.
 * Used by: the ITI master-data pages, aliases.php, change_log.php, program_final.php, program_edit.php,
 * iti_functions.php (iti_alias_save / iti_alias_lookup), iti_content_service.php, iti_program_build.php,
 * agent_api.php (iti_alias_proposals, iti_alias_decide, iti_change_log).
 */

const ITI_SH_ADMIN_ONLY = 'Shared ITI data (lodges, activities, routes, destinations, airlines, aliases, samples) is changed by Roberto only, '
                        . 'because one change applies to every program. Ask him for what is missing or wrong.';

/** Logged entities: table, label, name column (null = built by iti_sh_label()). */
const ITI_SH_ENTITIES = [
    'lodge_alias'    => ['table' => 'iti_lodge_aliases',    'label' => 'Lodge alias',      'name' => 'alias'],
    'activity_alias' => ['table' => 'iti_activity_aliases', 'label' => 'Activity alias',   'name' => 'alias'],
    'route_alias'    => ['table' => 'iti_route_aliases',    'label' => 'Route alias',      'name' => 'alias'],
    'lodge'          => ['table' => 'iti_lodges',           'label' => 'Lodge',            'name' => 'name'],
    'activity'       => ['table' => 'iti_activities',       'label' => 'Activity',         'name' => 'name_en'],
    'destination'    => ['table' => 'iti_destinations',     'label' => 'Destination',      'name' => 'name_en'],
    'transfer_route' => ['table' => 'iti_transfer_routes',  'label' => 'Transfer route',   'name' => null],
    'flight_route'   => ['table' => 'iti_flight_routes',    'label' => 'Flight route',     'name' => null],
    'airline'        => ['table' => 'iti_airlines',         'label' => 'Airline',          'name' => 'name'],
    'proposal'       => ['table' => 'iti_alias_proposals',  'label' => 'Mapping proposal', 'name' => 'alias'],
];

const ITI_SH_ALIAS_TABLES = ['lodge' => 'iti_lodge_aliases', 'activity' => 'iti_activity_aliases', 'route' => 'iti_route_aliases'];
const ITI_SH_TARGET_COLS  = ['lodge' => ['lodge_id'], 'activity' => ['activity_id'], 'route' => ['transfer_route_id', 'flight_route_id']];

// ── Who ──────────────────────────────────────────────────────────────────────

/** May the current Hub user change shared ITI data? Admin only (Roberto, 16 Nov 2026). */
function iti_can_edit_shared(): bool {
    if (!empty($GLOBALS['ITI_SH_ACTOR']['source']) && $GLOBALS['ITI_SH_ACTOR']['source'] === 'agent_api') return true;
    return function_exists('is_admin') && is_admin();
}

/** The Agent API acts as Roberto's agent: its changes are logged under its user, source agent_api. */
function iti_sh_set_actor(string $user, string $source): void {
    $GLOBALS['ITI_SH_ACTOR'] = ['user' => $user, 'source' => $source];
}

/** [username, source] of the change being made. */
function iti_sh_actor(): array {
    if (!empty($GLOBALS['ITI_SH_ACTOR'])) return [(string)$GLOBALS['ITI_SH_ACTOR']['user'], (string)$GLOBALS['ITI_SH_ACTOR']['source']];
    $u = function_exists('current_user') ? (string)(current_user()['username'] ?? '') : '';
    return [$u !== '' ? $u : 'system', PHP_SAPI === 'cli' ? 'cli' : 'hub'];
}

/** Page guard: a POST from a non-admin is refused with a flash message (the page stays readable). */
function iti_sh_guard_post(string $back): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || iti_can_edit_shared()) return;
    if (function_exists('iti_flash_set')) iti_flash_set('error', ITI_SH_ADMIN_ONLY);
    header('Location: ' . $back);
    exit;
}

/** Notice for non-admins on a shared-data page (empty for an admin). */
function iti_sh_readonly_note(string $what = 'this data'): string {
    if (iti_can_edit_shared()) return '';
    return '<div class="form-card" style="border-left:4px solid #f0ad00;margin-bottom:16px;font-size:.85rem;">🔒 Read only: '
         . htmlspecialchars($what, ENT_QUOTES, 'UTF-8') . ' is shared by every program, so only Roberto changes it. '
         . 'Something missing or wrong? Ask him.</div>';
}

// ── Schema ───────────────────────────────────────────────────────────────────

function iti_sh_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $have = [];
    foreach ($db->query("SHOW TABLES LIKE 'iti\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) $have[$t] = true;
    $opts = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    if (empty($have['iti_change_log'])) {
        $db->exec("CREATE TABLE iti_change_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            entity VARCHAR(30) NOT NULL,
            entity_id INT UNSIGNED NULL,
            label VARCHAR(255) NOT NULL DEFAULT '',
            action VARCHAR(12) NOT NULL,
            before_json MEDIUMTEXT NULL,
            after_json MEDIUMTEXT NULL,
            changed_by VARCHAR(80) NULL,
            changed_at DATETIME NOT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'hub',
            note VARCHAR(255) NULL,
            restored_by VARCHAR(80) NULL,
            restored_at DATETIME NULL,
            KEY idx_cl_entity (entity, entity_id),
            KEY idx_cl_at (changed_at)
        )" . $opts);
    }
    if (empty($have['iti_alias_proposals'])) {
        $db->exec("CREATE TABLE iti_alias_proposals (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            type VARCHAR(10) NOT NULL,
            alias VARCHAR(150) NOT NULL,
            request_id INT UNSIGNED NOT NULL,
            lodge_id INT UNSIGNED NULL,
            meal_basis VARCHAR(2) NULL,
            activity_id INT UNSIGNED NULL,
            transfer_route_id INT UNSIGNED NULL,
            flight_route_id INT UNSIGNED NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'pending',
            proposed_by VARCHAR(80) NULL,
            proposed_at DATETIME NOT NULL,
            decided_by VARCHAR(80) NULL,
            decided_at DATETIME NULL,
            decision_note VARCHAR(255) NULL,
            UNIQUE KEY uq_ap (type, alias, request_id),
            KEY idx_ap_status (status)
        )" . $opts);
    }
}

// ── History ──────────────────────────────────────────────────────────────────

function iti_sh_row(PDO $db, string $entity, int $id): ?array {
    if (!isset(ITI_SH_ENTITIES[$entity]) || $id <= 0) return null;
    $st = $db->prepare('SELECT * FROM `' . ITI_SH_ENTITIES[$entity]['table'] . '` WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Readable name of a row ("Kifaru Lodge", "Arusha → Karatu", "kifaru (lodge)"). */
function iti_sh_label(PDO $db, string $entity, ?array $row): string {
    if (!$row) return '';
    $col = ITI_SH_ENTITIES[$entity]['name'] ?? null;
    if ($col !== null) return mb_substr((string)($row[$col] ?? ''), 0, 255) . ($entity === 'proposal' ? ' (' . ($row['type'] ?? '') . ')' : '');
    if ($entity === 'flight_route') {
        return mb_substr(trim(($row['from_airport'] ?? '') . ' → ' . ($row['to_airport'] ?? '') . (($row['operator'] ?? '') !== '' ? ' (' . $row['operator'] . ')' : '')), 0, 255);
    }
    if ($entity === 'transfer_route') {
        $st = $db->prepare('SELECT id, name_en FROM iti_destinations WHERE id IN (?, ?)');
        $st->execute([(int)($row['from_destination'] ?? 0), (int)($row['to_destination'] ?? 0)]);
        $n = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        return mb_substr(($n[(int)($row['from_destination'] ?? 0)] ?? '?') . ' → ' . ($n[(int)($row['to_destination'] ?? 0)] ?? '?'), 0, 255);
    }
    return '#' . (int)($row['id'] ?? 0);
}

/**
 * Log one change. $action: create | update | delete | approve | reject | restore. An update that changed
 * nothing is not logged. Never throws: a failed log must not block the change itself.
 */
function iti_sh_log(PDO $db, string $entity, ?int $id, string $action, ?array $before, ?array $after, string $note = ''): void {
    try {
        if ($action === 'update' && $before !== null && $after !== null && iti_sh_same($before, $after)) return;
        iti_sh_schema($db);
        [$user, $source] = iti_sh_actor();
        $enc = function ($r) { return $r === null ? null : json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR); };
        $db->prepare('INSERT INTO iti_change_log (entity, entity_id, label, action, before_json, after_json, changed_by, changed_at, source, note)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$entity, $id ?: null, iti_sh_label($db, $entity, $after ?? $before), $action, $enc($before), $enc($after),
                      mb_substr($user, 0, 80), date('Y-m-d H:i:s'), mb_substr($source, 0, 20), $note !== '' ? mb_substr($note, 0, 255) : null]);
    } catch (Throwable $e) {
        error_log('iti_sh_log ' . $entity . ' #' . $id . ': ' . $e->getMessage());
    }
}

/** Log a row change: $before read by the caller before writing (null for create), the row after read here. */
function iti_sh_log_row(PDO $db, string $entity, int $id, string $action, ?array $before, string $note = ''): void {
    $after = $action === 'delete' ? null : iti_sh_row($db, $entity, $id);
    iti_sh_log($db, $entity, $id, $action, $before, $after, $note);
}

/** Same values, ignoring timestamps written by MySQL. */
function iti_sh_same(array $a, array $b): bool {
    foreach (['updated_at'] as $k) unset($a[$k], $b[$k]);
    foreach ($a as $k => $v) if (!array_key_exists($k, $b) || (string)$v !== (string)$b[$k]) return false;
    return count($a) === count($b);
}

/** Columns whose value differs: [col => [from, to]] (for the History page). */
function iti_sh_diff(?array $before, ?array $after): array {
    $out = [];
    foreach (array_unique(array_merge(array_keys($before ?? []), array_keys($after ?? []))) as $k) {
        if (in_array($k, ['updated_at', 'created_at'], true)) continue;
        $f = $before[$k] ?? null; $t = $after[$k] ?? null;
        if ((string)$f !== (string)$t || ($f === null) !== ($t === null)) $out[$k] = [$f, $t];
    }
    return $out;
}

/** Log rows, newest first. $f: entity, entity_id, user, q (label), since (YYYY-MM-DD), limit. */
function iti_sh_log_rows(PDO $db, array $f = []): array {
    iti_sh_schema($db);
    $w = ['1=1']; $a = [];
    if (($e = (string)($f['entity'] ?? '')) !== '') { $w[] = 'entity = ?'; $a[] = $e; }
    if (!empty($f['entity_id'])) { $w[] = 'entity_id = ?'; $a[] = (int)$f['entity_id']; }
    if (($u = trim((string)($f['user'] ?? ''))) !== '') { $w[] = 'changed_by = ?'; $a[] = $u; }
    if (($q = trim((string)($f['q'] ?? ''))) !== '') { $w[] = 'label LIKE ?'; $a[] = '%' . $q . '%'; }
    if (($s = trim((string)($f['since'] ?? ''))) !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) { $w[] = 'changed_at >= ?'; $a[] = $s; }
    $lim = max(1, min(500, (int)($f['limit'] ?? 200)));
    $st = $db->prepare('SELECT * FROM iti_change_log WHERE ' . implode(' AND ', $w) . ' ORDER BY id DESC LIMIT ' . $lim);
    $st->execute($a);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Why a log row cannot be restored ('' = it can). Only the latest change of a row is restored, then the one before. */
function iti_sh_restore_blocker(PDO $db, array $log): string {
    if ($log['restored_at']) return 'already restored';
    if (in_array($log['action'], ['restore', 'approve', 'reject'], true)) return '';   // filtered by the caller
    if ($log['entity'] === 'proposal') return 'a proposal is decided again on the Aliases page';
    if (!isset(ITI_SH_ENTITIES[$log['entity']]) || !$log['entity_id']) return 'not restorable';
    $st = $db->prepare("SELECT MAX(id) FROM iti_change_log WHERE entity = ? AND entity_id = ? AND restored_at IS NULL
                         AND action IN ('create', 'update', 'delete')");
    $st->execute([$log['entity'], (int)$log['entity_id']]);
    if ((int)$st->fetchColumn() !== (int)$log['id']) return 'restore the later change of this row first';
    return '';
}

function iti_sh_restorable(PDO $db, array $log): bool {
    return in_array($log['action'], ['create', 'update', 'delete'], true) && iti_sh_restore_blocker($db, $log) === '';
}

/**
 * Put a row back as it was before the logged change: update → the old values; delete → the row again (same id);
 * create → an alias is deleted, master data is deactivated (programs may use it). Admin only (the caller checks).
 * Returns a message. The restore is logged too, so it can be undone the same way.
 */
function iti_sh_restore(PDO $db, int $logId, string $user): string {
    iti_sh_schema($db);
    $st = $db->prepare('SELECT * FROM iti_change_log WHERE id = ?');
    $st->execute([$logId]);
    $log = $st->fetch(PDO::FETCH_ASSOC);
    if (!$log) throw new InvalidArgumentException('Change #' . $logId . ' not found.');
    if (!in_array($log['action'], ['create', 'update', 'delete'], true)) throw new InvalidArgumentException('Only a create, update or delete can be restored.');
    if (($why = iti_sh_restore_blocker($db, $log)) !== '') throw new InvalidArgumentException('Change #' . $logId . ': ' . $why . '.');
    $entity = $log['entity'];
    $table = ITI_SH_ENTITIES[$entity]['table'];
    $id = (int)$log['entity_id'];
    $before = $log['before_json'] !== null ? json_decode($log['before_json'], true) : null;
    $cols = [];
    foreach ($db->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC) as $c) $cols[$c['Field']] = true;
    $now = iti_sh_row($db, $entity, $id);
    $isAlias = substr($entity, -6) === '_alias';

    if ($log['action'] === 'update') {
        if (!$now) throw new InvalidArgumentException('The row no longer exists: restore its deletion first.');
        $set = []; $args = [];
        foreach ((array)$before as $k => $v) {
            if ($k === 'id' || $k === 'updated_at' || !isset($cols[$k])) continue;
            $set[] = '`' . $k . '` = ?'; $args[] = $v;
        }
        $args[] = $id;
        $db->prepare('UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
        $msg = 'Restored the values before change #' . $logId . '.';
        $act = 'update';
    } elseif ($log['action'] === 'delete') {
        if ($now) throw new InvalidArgumentException('Row #' . $id . ' exists again: nothing to restore.');
        $row = array_intersect_key((array)$before, $cols);
        try {
            $db->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')
               ->execute(array_values($row));
        } catch (PDOException $e) {
            throw new InvalidArgumentException('Could not put the row back (' . ($isAlias ? 'the same Calc text was mapped again since' : 'a duplicate exists') . '): ' . $e->getMessage());
        }
        $msg = 'Row #' . $id . ' put back.';
        $act = 'create';
    } else {   // create
        if (!$now) throw new InvalidArgumentException('Row #' . $id . ' no longer exists.');
        if ($isAlias) {
            $db->prepare('DELETE FROM `' . $table . '` WHERE id = ?')->execute([$id]);
            $msg = 'Alias "' . $now['alias'] . '" removed: the text will be asked again.';
            $act = 'delete';
        } elseif (isset($cols['is_active'])) {
            $db->prepare('UPDATE `' . $table . '` SET is_active = 0 WHERE id = ?')->execute([$id]);
            $msg = 'Deactivated (programs that use it keep it).';
            $act = 'update';
        } else {
            throw new InvalidArgumentException('This row cannot be removed here.');
        }
    }
    $db->prepare('UPDATE iti_change_log SET restored_by = ?, restored_at = ? WHERE id = ?')->execute([$user, date('Y-m-d H:i:s'), $logId]);
    // The restore itself, so it shows in the history (and the row's previous change becomes the latest one).
    iti_sh_log($db, $entity, $id, 'restore', $now, iti_sh_row($db, $entity, $id), 'restore of change #' . $logId . ' (' . $act . ')');
    if ($entity === 'transfer_route' && function_exists('iti_tr_routes')) iti_tr_routes($db, true);
    return $msg;
}

// ── Per-booking mappings (proposals) ─────────────────────────────────────────

/** Target columns of a mapping from a form / API body: [col => id|null] (+ meal_basis for a lodge). */
function iti_sh_targets(string $type, array $in, ?string $meal): array {
    $row = [];
    foreach (ITI_SH_TARGET_COLS[$type] as $c) { $v = (int)($in[$c] ?? 0); $row[$c] = $v > 0 ? $v : null; }
    if ($type === 'lodge') $row['meal_basis'] = in_array($meal, ['BB', 'HB', 'FB', 'AI'], true) ? $meal : null;
    return $row;
}

/** Check that every target id exists (a missing one would break the program). */
function iti_sh_check_targets(PDO $db, array $t): void {
    $map = ['lodge_id' => 'iti_lodges', 'activity_id' => 'iti_activities', 'transfer_route_id' => 'iti_transfer_routes', 'flight_route_id' => 'iti_flight_routes'];
    foreach ($map as $c => $table) {
        if (empty($t[$c])) continue;
        $st = $db->prepare('SELECT 1 FROM `' . $table . '` WHERE id = ?');
        $st->execute([(int)$t[$c]]);
        if (!$st->fetchColumn()) throw new InvalidArgumentException($c . ' ' . (int)$t[$c] . ' not found.');
    }
}

/**
 * The staff's mapping of a Calc text, for ONE booking: used for that booking at once, for the others after the
 * admin approves it. A text already mapped for everyone is refused (it is not asked). Returns the proposal row.
 */
function iti_sh_propose(PDO $db, string $type, string $alias, array $targets, int $requestId): array {
    if (!isset(ITI_SH_ALIAS_TABLES[$type])) throw new InvalidArgumentException('Unknown alias type: ' . $type);
    if ($alias === '') throw new InvalidArgumentException('Empty Calc text.');
    if (mb_strlen($alias) > 150) throw new InvalidArgumentException('Calc text longer than 150 characters.');
    if ($requestId <= 0) throw new InvalidArgumentException('A mapping by the staff needs its booking (request id).');
    iti_sh_schema($db);
    $st = $db->prepare('SELECT id FROM `' . ITI_SH_ALIAS_TABLES[$type] . '` WHERE alias = ?');
    $st->execute([$alias]);
    if ($st->fetchColumn()) throw new InvalidArgumentException('"' . $alias . '" is already mapped for every program: reload the page.');
    iti_sh_check_targets($db, $targets);
    [$user] = iti_sh_actor();
    $st = $db->prepare('SELECT * FROM iti_alias_proposals WHERE type = ? AND alias = ? AND request_id = ?');
    $st->execute([$type, $alias, $requestId]);
    $before = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $row = ['type' => $type, 'alias' => $alias, 'request_id' => $requestId,
            'lodge_id' => null, 'meal_basis' => null, 'activity_id' => null, 'transfer_route_id' => null, 'flight_route_id' => null];
    $row = array_merge($row, $targets);
    $row += ['status' => 'pending', 'proposed_by' => $user, 'proposed_at' => date('Y-m-d H:i:s'),
             'decided_by' => null, 'decided_at' => null, 'decision_note' => null];
    $upd = [];
    foreach (array_keys($row) as $c) if (!in_array($c, ['type', 'alias', 'request_id'], true)) $upd[] = '`' . $c . '` = VALUES(`' . $c . '`)';
    $db->prepare('INSERT INTO iti_alias_proposals (`' . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')
                  ON DUPLICATE KEY UPDATE ' . implode(', ', $upd))->execute(array_values($row));
    $st->execute([$type, $alias, $requestId]);
    $after = $st->fetch(PDO::FETCH_ASSOC);
    iti_sh_log($db, 'proposal', (int)$after['id'], $before ? 'update' : 'create', $before, $after, 'booking #' . $requestId);
    iti_sh_pending_memo($db);
    return $after;
}

/** The pending proposal of this booking for a normalised Calc text, shaped like an alias row (+ 'local' => true), or null. */
function iti_sh_proposal_lookup(PDO $db, string $type, string $alias, int $requestId): ?array {
    if ($requestId <= 0 || !isset(ITI_SH_ALIAS_TABLES[$type])) return null;
    try {
        iti_sh_schema($db);
        $st = $db->prepare("SELECT * FROM iti_alias_proposals WHERE type = ? AND alias = ? AND request_id = ? AND status = 'pending'");
        $st->execute([$type, $alias, $requestId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        iti_sh_check_targets($db, $row);   // a target deleted since: asked again
    } catch (Throwable $e) {
        return null;
    }
    $row['local'] = true;
    return $row;
}

/** Proposals with their target names. $f: status (pending|approved|rejected|all, default pending), request_id, user, limit. */
function iti_sh_proposals(PDO $db, array $f = []): array {
    iti_sh_schema($db);
    $w = ['1=1']; $a = [];
    $status = (string)($f['status'] ?? 'pending');
    if ($status !== 'all') { $w[] = 'p.status = ?'; $a[] = $status; }
    if (!empty($f['request_id'])) { $w[] = 'p.request_id = ?'; $a[] = (int)$f['request_id']; }
    if (($u = trim((string)($f['user'] ?? ''))) !== '') { $w[] = 'p.proposed_by = ?'; $a[] = $u; }
    $lim = max(1, min(500, (int)($f['limit'] ?? 200)));
    $st = $db->prepare("SELECT p.*, r.customer_name, r.practice_code,
                               l.name AS lodge_name, ld.name_en AS lodge_dest, x.name_en AS activity_name,
                               CONCAT(fd.name_en, ' → ', td.name_en) AS transfer_name,
                               CONCAT(fr.from_airport, ' → ', fr.to_airport, IFNULL(CONCAT(' (', fr.operator, ')'), '')) AS flight_name
                          FROM iti_alias_proposals p
                          LEFT JOIN requests r ON r.id = p.request_id
                          LEFT JOIN iti_lodges l ON l.id = p.lodge_id
                          LEFT JOIN iti_destinations ld ON ld.id = l.destination_id
                          LEFT JOIN iti_activities x ON x.id = p.activity_id
                          LEFT JOIN iti_transfer_routes tr ON tr.id = p.transfer_route_id
                          LEFT JOIN iti_destinations fd ON fd.id = tr.from_destination
                          LEFT JOIN iti_destinations td ON td.id = tr.to_destination
                          LEFT JOIN iti_flight_routes fr ON fr.id = p.flight_route_id
                         WHERE " . implode(' AND ', $w) . ' ORDER BY p.proposed_at DESC, p.id DESC LIMIT ' . $lim);
    $st->execute($a);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {   // the alias saved for everyone in the meantime (conflict when its target differs)
        $g = $db->prepare('SELECT * FROM `' . ITI_SH_ALIAS_TABLES[$r['type']] . '` WHERE alias = ?');
        $g->execute([$r['alias']]);
        $r['global'] = $g->fetch(PDO::FETCH_ASSOC) ?: null;
        $r['target'] = iti_sh_target_text($r);
    }
    unset($r);
    return $rows;
}

function iti_sh_pending_count(PDO $db): int {
    try {
        iti_sh_schema($db);
        return (int)$db->query("SELECT COUNT(*) FROM iti_alias_proposals WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** "Kifaru Lodge — Karatu · HB" / "nothing (not shown)" from a proposals() row. */
function iti_sh_target_text(array $r): string {
    if ($r['type'] === 'lodge') {
        if (!$r['lodge_id']) return 'nothing (own arrangement / not a lodge)';
        return ($r['lodge_name'] ?? ('#' . $r['lodge_id'])) . (!empty($r['lodge_dest']) ? ' — ' . $r['lodge_dest'] : '') . ($r['meal_basis'] ? ' · ' . $r['meal_basis'] : '');
    }
    if ($r['type'] === 'activity') return $r['activity_id'] ? ($r['activity_name'] ?? ('#' . $r['activity_id'])) : 'nothing (not an activity)';
    $p = [];
    if ($r['transfer_route_id']) $p[] = '🚙 ' . ($r['transfer_name'] ?? ('#' . $r['transfer_route_id']));
    if ($r['flight_route_id']) $p[] = '✈ ' . ($r['flight_name'] ?? ('#' . $r['flight_route_id']));
    return $p ? implode(' + ', $p) : 'no transfer, no flight';
}

/**
 * Admin decision on a pending proposal. approve: saved as the alias for every program, with $targets (the
 * proposal's own unless the admin corrected it); reject: dropped, the text is asked again for that booking.
 * $saveAlias = iti_alias_save() (in iti_functions.php: the caller passes it so this file stays self-contained).
 * Rejected or corrected → memo to whoever proposed it, listing the programs of that booking to regenerate.
 */
function iti_sh_decide(PDO $db, int $id, string $decision, ?array $targets, string $note, callable $saveAlias): array {
    iti_sh_schema($db);
    if (!in_array($decision, ['approve', 'reject'], true)) throw new InvalidArgumentException('decision: approve | reject');
    $st = $db->prepare('SELECT * FROM iti_alias_proposals WHERE id = ?');
    $st->execute([$id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) throw new InvalidArgumentException('Proposal #' . $id . ' not found.');
    if ($p['status'] !== 'pending') throw new InvalidArgumentException('Proposal #' . $id . ' was already ' . $p['status'] . '.');
    [$user] = iti_sh_actor();
    $type = $p['type'];
    $proposed = iti_sh_targets($type, $p, $p['meal_basis']);
    $corrected = false;
    if ($decision === 'approve') {
        $final = $targets !== null ? $targets : $proposed;
        iti_sh_check_targets($db, $final);
        $corrected = !iti_sh_same(array_map('strval', $proposed), array_map('strval', $final));
        $saveAlias($type, $p['alias'], $final, $type === 'lodge' ? $final['meal_basis'] : null, $user);
        $txt = $corrected ? 'approved with a correction' : 'approved';
    } else {
        $txt = 'rejected';
    }
    $noteFull = trim($txt . ($note !== '' ? ': ' . $note : ''));
    $db->prepare('UPDATE iti_alias_proposals SET status = ?, decided_by = ?, decided_at = ?, decision_note = ? WHERE id = ?')
       ->execute([$decision === 'approve' ? 'approved' : 'rejected', $user, date('Y-m-d H:i:s'), mb_substr($noteFull, 0, 255), $id]);
    $st->execute([$id]);
    $after = $st->fetch(PDO::FETCH_ASSOC);
    iti_sh_log($db, 'proposal', $id, $decision, $p, $after, $noteFull);
    $programs = iti_sh_booking_programs($db, (int)$p['request_id'], (string)$p['proposed_at']);
    if ($decision === 'reject' || $corrected) iti_sh_notify_proposer($db, $p, $decision, $corrected, $note, $programs);
    iti_sh_pending_memo($db);
    return ['id' => $id, 'decision' => $decision, 'corrected' => $corrected, 'alias' => $p['alias'], 'type' => $type,
            'request_id' => (int)$p['request_id'], 'programs_to_check' => $programs];
}

/** Active final programs of a booking generated since $since: [{id, generated_at}]. */
function iti_sh_booking_programs(PDO $db, int $requestId, string $since): array {
    try {
        $st = $db->prepare("SELECT id, generated_at FROM iti_programs WHERE lead_request_id = ? AND program_type = 'personal'
                              AND superseded_by IS NULL AND status <> 'cancelled' AND (generated_at IS NULL OR generated_at >= ?) ORDER BY id DESC");
        $st->execute([$requestId, $since]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/** Memo on the proposer's board (not Roberto's): their mapping was rejected / corrected. */
function iti_sh_notify_proposer(PDO $db, array $p, string $decision, bool $corrected, string $note, array $programs): void {
    try {
        $st = $db->prepare('SELECT id FROM users WHERE username = ? AND is_active = 1');
        $st->execute([(string)$p['proposed_by']]);
        $uid = (int)($st->fetchColumn() ?: 0);
        if (!$uid) return;
        $e = function ($x) { return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); };
        $what = $decision === 'reject' ? 'was not accepted' : 'was corrected';
        $html = '<p>Your mapping of the Calc text <b>' . $e($p['alias']) . '</b> (' . $e($p['type']) . ', booking #' . (int)$p['request_id'] . ') '
              . $what . ' by Roberto' . ($note !== '' ? ': ' . $e($note) : '') . '.</p>'
              . ($decision === 'reject' ? '<p>Map it again on the booking\'s "Itinerary program from Calc" page.</p>' : '<p>The correct mapping is now used for every program.</p>')
              . ($programs ? '<p>Regenerate from the Calc: program #' . implode(', #', array_map(function ($r) { return (int)$r['id']; }, $programs)) . '.</p>' : '');
        $now = date('Y-m-d H:i:s');
        $db->prepare("INSERT INTO memos (user_id, title, body, type, status, priority, pinned, color, due_date, reminder_at, reminder_emails,
                          reminder_sent, recur_rule, sort_order, request_id, source, ext_key, created_at, updated_at)
                      VALUES (?, ?, ?, 'todo', 'open', 'normal', 0, NULL, ?, NULL, NULL, 0, 'none', 0, ?, 'iti', ?, ?, ?)")
           ->execute([$uid, mb_substr('ITI mapping "' . $p['alias'] . '" ' . $what, 0, 250), $html, date('Y-m-d'), (int)$p['request_id'],
                      'iti-alias-decision:' . (int)$p['id'], $now, $now]);
    } catch (Throwable $ex) {
        error_log('iti_sh_notify_proposer: ' . $ex->getMessage());
    }
}

/** One memo on Roberto's board while mappings wait for approval (updated on each change, closed at zero). */
function iti_sh_pending_memo(PDO $db): void {
    try {
        require_once __DIR__ . '/../../leads/includes/folder_link_service.php';   // fl_memo_upsert(), fl_memo_close()
        $rows = iti_sh_proposals($db, ['status' => 'pending', 'limit' => 30]);
        if (!$rows) { fl_memo_close($db, 'iti-alias-proposals', 'No mapping waiting for approval.'); return; }
        $e = function ($x) { return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); };
        $li = [];
        foreach ($rows as $r) $li[] = '<li>' . $e($r['type']) . ' <b>' . $e($r['alias']) . '</b> → ' . $e($r['target']) . ' (' . $e($r['proposed_by']) . ', #' . (int)$r['request_id'] . ')</li>';
        $n = iti_sh_pending_count($db);
        fl_memo_upsert($db, 'iti-alias-proposals', 'ITI: ' . $n . ' mapping' . ($n === 1 ? '' : 's') . ' waiting for your approval',
                       '<p>The staff mapped these Calc texts for their booking. Approve them in ITI → Aliases to use them for every program.</p><ul>'
                       . implode('', $li) . '</ul>', null, 'normal', 'iti');
    } catch (Throwable $ex) {
        error_log('iti_sh_pending_memo: ' . $ex->getMessage());
    }
}

/** A global alias was just saved: pending proposals of that text with the same target are approved with it. */
function iti_sh_after_alias_save(PDO $db, string $type, string $alias, array $saved): void {
    try {
        iti_sh_schema($db);
        $st = $db->prepare("SELECT * FROM iti_alias_proposals WHERE type = ? AND alias = ? AND status = 'pending'");
        $st->execute([$type, $alias]);
        $want = iti_sh_targets($type, $saved, $saved['meal_basis'] ?? null);
        [$user] = iti_sh_actor();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            if (!iti_sh_same(array_map('strval', iti_sh_targets($type, $p, $p['meal_basis'])), array_map('strval', $want))) continue;
            $db->prepare("UPDATE iti_alias_proposals SET status = 'approved', decided_by = ?, decided_at = ?, decision_note = 'same as the alias saved' WHERE id = ?")
               ->execute([$user, date('Y-m-d H:i:s'), (int)$p['id']]);
        }
    } catch (Throwable $e) {
        error_log('iti_sh_after_alias_save: ' . $e->getMessage());
    }
}

// ── Impact ───────────────────────────────────────────────────────────────────

/**
 * What a change to this row touches: samples / programs (active, not superseded) that use it, aliases and
 * pending proposals pointing to it, and for a destination its lodges, activities and routes.
 */
function iti_sh_usage(PDO $db, string $entity, int $id): array {
    $u = ['samples' => 0, 'programs' => 0, 'aliases' => 0, 'proposals' => 0, 'lodges' => 0, 'activities' => 0, 'routes' => 0];
    if ($id <= 0) return $u;
    $progs = function (string $join, string $where, array $args) use ($db, &$u) {
        try {
            $st = $db->prepare("SELECT p.program_type, COUNT(DISTINCT p.id) FROM iti_programs p JOIN iti_program_days d ON d.program_id = p.id $join
                                 WHERE ($where) AND p.status <> 'cancelled' AND p.superseded_by IS NULL GROUP BY p.program_type");
            $st->execute($args);
            foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $t => $n) {
                if ($t === 'sample') $u['samples'] += (int)$n; else $u['programs'] += (int)$n;
            }
        } catch (Throwable $e) { /* column not there yet */ }
    };
    $count = function (string $sql, array $args) use ($db) {
        try { $st = $db->prepare($sql); $st->execute($args); return (int)$st->fetchColumn(); } catch (Throwable $e) { return 0; }
    };
    $prop = function (string $col) use ($db, $id, $count) {
        return $count("SELECT COUNT(*) FROM iti_alias_proposals WHERE $col = ? AND status = 'pending'", [$id]);
    };
    switch ($entity) {
        case 'lodge':
            $progs('', 'd.end_lodge_id = ? OR d.start_lodge_id = ?', [$id, $id]);
            $u['aliases'] = $count('SELECT COUNT(*) FROM iti_lodge_aliases WHERE lodge_id = ?', [$id]);
            $u['proposals'] = $prop('lodge_id');
            break;
        case 'activity':
            $progs('JOIN iti_day_activities a ON a.program_day_id = d.id', 'a.activity_id = ?', [$id]);
            $u['aliases'] = $count('SELECT COUNT(*) FROM iti_activity_aliases WHERE activity_id = ?', [$id]);
            $u['proposals'] = $prop('activity_id');
            break;
        case 'transfer_route':
            $progs('LEFT JOIN iti_day_transfers t ON t.program_day_id = d.id', 'd.transfer_route_id = ? OR t.transfer_route_id = ?', [$id, $id]);
            $u['aliases'] = $count('SELECT COUNT(*) FROM iti_route_aliases WHERE transfer_route_id = ?', [$id]);
            $u['proposals'] = $prop('transfer_route_id');
            break;
        case 'flight_route':
            $progs('JOIN iti_day_flights f ON f.program_day_id = d.id', 'f.flight_route_id = ?', [$id]);
            $u['aliases'] = $count('SELECT COUNT(*) FROM iti_route_aliases WHERE flight_route_id = ?', [$id]);
            $u['proposals'] = $prop('flight_route_id');
            break;
        case 'destination':
            $u['lodges'] = $count('SELECT COUNT(*) FROM iti_lodges WHERE destination_id = ? AND is_active = 1', [$id]);
            $u['activities'] = $count('SELECT COUNT(*) FROM iti_activities WHERE destination_id = ? AND is_active = 1', [$id]);
            $u['routes'] = $count('SELECT COUNT(*) FROM iti_transfer_routes WHERE (from_destination = ? OR to_destination = ? OR via_destination = ?) AND is_active = 1', [$id, $id, $id])
                        ?: $count('SELECT COUNT(*) FROM iti_transfer_routes WHERE (from_destination = ? OR to_destination = ?) AND is_active = 1', [$id, $id]);
            $progs('JOIN iti_lodges l ON l.id = d.end_lodge_id', 'l.destination_id = ?', [$id]);
            break;
    }
    return $u;
}

/** Box above an edit form: what the change touches, and that it is logged. '' when nothing uses the row. */
function iti_sh_usage_box(array $u, string $entityLabel = 'this record'): string {
    $parts = [];
    $add = function ($n, $one, $many) use (&$parts) { if ($n > 0) $parts[] = '<strong>' . $n . '</strong> ' . ($n === 1 ? $one : $many); };
    $add($u['samples'], 'sample', 'samples');
    $add($u['programs'], 'client program', 'client programs');
    $add($u['aliases'], 'Calc alias', 'Calc aliases');
    $add($u['proposals'], 'mapping awaiting approval', 'mappings awaiting approval');
    $add($u['lodges'], 'lodge', 'lodges');
    $add($u['activities'], 'activity', 'activities');
    $add($u['routes'], 'transfer route', 'transfer routes');
    if (!$parts) return '';
    return '<div class="form-card" style="border-left:4px solid #f0ad00;margin-bottom:16px;font-size:.85rem;">⚠ Shared: '
         . htmlspecialchars($entityLabel, ENT_QUOTES, 'UTF-8') . ' is used by ' . implode(', ', $parts) . '. '
         . 'A change shows in all of them (online links and new documents; files already sent stay as they are). '
         . 'Every change is logged: <a href="change_log.php">History</a> can put it back.</div>';
}
