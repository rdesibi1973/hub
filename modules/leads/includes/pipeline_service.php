<?php
/**
 * pipeline_service.php — the sales Pipeline board (NEW / WIP / QUOTED / HOT / CONFIRMED), shared by
 * pipeline.php (board + drag & drop) and the Agent API (pipeline_list / pipeline_move).
 *
 * The column is derived from the status; only WIP and HOT are manual (requests.pipeline_column):
 *   Booked without _CK, trip not started → CONFIRMED (leaves the board once the folder gets _CK)
 *   pipeline_column 'booked'             → CONFIRMED (a Booked one only until its CK)
 *   Hot / Hot-Quoted                     → HOT
 *   Quoted                               → QUOTED (HOT if moved there by hand)
 *   Inquiry                              → NEW    (WIP if moved there by hand)
 * So a status change made anywhere (request page, list, edit, API) moves the card.
 * Parked requests (parked_service.php) are off the board; with "Mostra parcheggiate" / include_parked they sit in an
 * extra PARKED column at the end. Dropping a card on PARKED parks it; dragging it out unparks it to that column.
 *
 * Requires the leads config.php (db()). Keep PHP-7 compatible.
 */

require_once __DIR__ . '/booking_service.php';    // bs_status_check() / bs_set_request_status() (park / unpark), parked_service.php
require_once __DIR__ . '/folder_parser.php';      // parse_folder_dates(): start of a Booked trip from its folder
require_once __DIR__ . '/timeline_service.php';   // timeline_log(), tl_activity_map()
require_once __DIR__ . '/assignment_service.php'; // assign_schema(): requests.assigned_at

/**
 * Column definitions (key = requests.pipeline_column). status = what a drop on the column sets (null = card only).
 * $withParked adds the PARKED column (never stored in pipeline_column: the status says it).
 */
function pl_columns(bool $withParked = false): array {
    $cols = [
        'new'    => ['label' => 'NEW',       'icon' => '📥', 'cls' => 'col-new',    'default_statuses' => ['Inquiry'],           'status' => 'Inquiry'],
        'wip'    => ['label' => 'WIP',       'icon' => '⚙️', 'cls' => 'col-wip',    'default_statuses' => [],                    'status' => 'Inquiry'],
        'quoted' => ['label' => 'QUOTED',    'icon' => '💬', 'cls' => 'col-quoted', 'default_statuses' => ['Quoted'],            'status' => 'Quoted'],
        'hot'    => ['label' => 'HOT',       'icon' => '🔥', 'cls' => 'col-hot',    'default_statuses' => ['Hot-Quoted', 'Hot'], 'status' => 'Hot-Quoted'],
        'booked' => ['label' => 'CONFIRMED', 'icon' => '✅', 'cls' => 'col-booked', 'default_statuses' => [],                    'status' => null],
    ];
    if ($withParked) {
        $cols['parked'] = ['label' => 'PARKED', 'icon' => '🅿️', 'cls' => 'col-parked', 'default_statuses' => [PK_STATUS], 'status' => PK_STATUS];
    }
    return $cols;
}

/** Folder carries the _CK marker (same token rule as ck_has_marker()). */
function pl_has_ck(?string $folder): bool {
    return (bool)preg_match('/_CK(?=_|$)/i', (string)$folder);
}

/**
 * Board column of a request row (status, pipeline_column, practice_code, start_date), or null when the
 * card is off the board (Booked with _CK, Booked trip already started, Lost / Cancelled not pinned to CONFIRMED…).
 */
function pl_column_for(array $r): ?string {
    $explicit = $r['pipeline_column'] ?? null;
    $status   = (string)($r['status'] ?? '');
    $hasCk    = pl_has_ck($r['practice_code'] ?? '');
    if ($status === 'Booked' && !$hasCk && empty($r['start_date']) && $explicit !== 'booked') {
        // No start_date stored: take it from the folder name (…_START16SEP_END19SEP2026); a trip already started leaves the board.
        $fd = parse_folder_dates((string)($r['practice_code'] ?? ''));
        if ($fd['start_date'] !== null && $fd['start_date'] < date('Y-m-d')) return null;
    }
    if ($status === 'Booked')                               return $hasCk ? null : 'booked';
    if ($status === PK_STATUS)                              return 'parked';   // shown only when the PARKED column is on
    if ($explicit === 'booked')                             return 'booked';
    if (in_array($status, ['Hot', 'Hot-Quoted'], true))     return 'hot';
    if ($status === 'Quoted')                               return $explicit === 'hot' ? 'hot' : 'quoted';
    if ($status === 'Inquiry')                              return $explicit === 'wip' ? 'wip' : 'new';
    foreach (pl_columns() as $k => $c) {
        if (in_array($status, $c['default_statuses'], true)) return $k;
    }
    return null;
}

/**
 * Cards on the board.
 * $f: agent_id (0 = all), year (date_received), channel, columns [keys], stale_days (only cards whose last
 *     activity is older than N days), limit (cards returned; totals count every matching card),
 *     include_parked (bool: fetch Parked requests too — by_col['parked']; the API lists them only then).
 * $rich = false (board page): ['rows' fetched (Parked included only with include_parked), 'by_col' [col => rows in
 *     date_received DESC order]].
 * $rich = true (API) adds 'columns' [{key, label, count, value_usd}], 'total', 'cards' [] sorted by column, then
 *     oldest activity first (the ones to chase on top). Activity = last timeline / summary / note (tl_activity_map),
 *     else assigned_at, else date_received. Parked cards also carry parked_at / days_parked.
 */
function pl_fetch(PDO $db, array $f, bool $rich = false): array {
    $withParked = !empty($f['include_parked']);
    // CONFIRMED also keeps every Booked request whose folder has no _CK marker yet (same token rule as
    // ck_has_marker()), until the CK is added — only trips not started yet, so old bookings never marked
    // CK do not pile up.
    $where  = ["(r.status IN ('Inquiry','Quoted','Hot','Hot-Quoted'" . ($withParked ? ",'Parked'" : "") . ") OR r.pipeline_column = 'booked'
                OR (r.status = 'Booked' AND COALESCE(r.practice_code, '') NOT REGEXP '_CK(_|$)'
                    AND (r.start_date IS NULL OR r.start_date >= CURDATE())))"];
    if (!$withParked) $where[] = "r.status <> 'Parked'";   // a Parked request pinned to CONFIRMED stays off the board too
    pk_schema($db);
    $params = [];
    if (!empty($f['agent_id'])) { $where[] = 'r.agent_id = ?';             $params[] = (int)$f['agent_id']; }
    if (!empty($f['year']))     { $where[] = 'YEAR(r.date_received) = ?';  $params[] = (int)$f['year']; }
    if (!empty($f['channel']))  { $where[] = 'r.channel = ?';              $params[] = (string)$f['channel']; }

    $extra = '';
    if ($rich) {
        assign_schema($db);
        $extra = ", r.channel, r.group_folder, r.assigned_at, r.agency_id, ag.nome AS agency_name, ag.short_name AS agency_short";
    }
    $stmt = $db->prepare(
        "SELECT r.id, r.customer_name, r.status, r.pipeline_column, r.practice_code, r.start_date,
                r.pax, r.destination, r.period, r.date_received, r.value_usd, r.parked_at,
                a.name AS agent_name" . $extra . "
         FROM requests r
         LEFT JOIN agents a ON a.id = r.agent_id" . ($rich ? " LEFT JOIN agencies ag ON ag.id = r.agency_id" : "") . "
         WHERE " . implode(' AND ', $where) . "
         ORDER BY r.date_received DESC"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cols  = pl_columns($withParked);
    $byCol = array_fill_keys(array_keys($cols), []);
    foreach ($rows as $r) {
        $colKey = pl_column_for($r);
        if ($colKey && isset($byCol[$colKey])) $byCol[$colKey][] = $r;
    }
    $out = ['rows' => $rows, 'by_col' => $byCol];
    if (!$rich) return $out;

    $want = !empty($f['columns']) ? array_values(array_intersect(array_keys($cols), (array)$f['columns'])) : array_keys($cols);
    $ids  = [];
    foreach ($want as $k) foreach ($byCol[$k] as $r) $ids[] = (int)$r['id'];
    $act   = tl_activity_map($db, $ids);
    $now   = time();
    $stale = isset($f['stale_days']) ? (int)$f['stale_days'] : null;
    $cards = []; $columns = [];
    foreach ($want as $k) {
        $list = [];
        foreach ($byCol[$k] as $r) {
            $a    = $act[(int)$r['id']] ?? [];
            $last = $a['last_activity_at'] ?? null;
            $ref  = $last ?: ($r['assigned_at'] ?: $r['date_received']);
            $days = $ref ? (int)floor(($now - strtotime((string)$ref)) / 86400) : null;
            if ($stale !== null && $days !== null && $days < $stale) continue;
            $list[] = [
                'id' => (int)$r['id'], 'customer_name' => $r['customer_name'], 'column' => $k, 'status' => $r['status'],
                'pipeline_column' => $r['pipeline_column'], 'agent' => $r['agent_name'],
                'agency' => $r['agency_short'] ?: $r['agency_name'], 'channel' => $r['channel'],
                'folder' => $r['group_folder'] ? $r['group_folder'] . '/' . $r['practice_code'] : $r['practice_code'],
                'destination' => $r['destination'], 'period' => $r['period'], 'pax' => $r['pax'],
                'value_usd' => $r['value_usd'] !== null ? (float)$r['value_usd'] : null,
                'date_received' => $r['date_received'], 'start_date' => $r['start_date'],
                'assigned_at' => $r['assigned_at'] ? substr((string)$r['assigned_at'], 0, 16) : null,
                'last_activity_at' => $last, 'days_since_activity' => $days,
                'summary_next_step' => $a['next_step'] ?? null, 'waiting_on' => $a['waiting_on'] ?? null,
                'has_ck' => pl_has_ck($r['practice_code']),
                '_ref' => (string)$ref,
            ] + ($k === 'parked' ? ['parked_at' => $r['parked_at'] ? substr((string)$r['parked_at'], 0, 16) : null,
                                    'days_parked' => $r['parked_at'] ? (int)floor(($now - strtotime((string)$r['parked_at'])) / 86400) : null] : []);
        }
        usort($list, function ($x, $y) { return strcmp($x['_ref'], $y['_ref']) ?: $x['id'] - $y['id']; });
        $columns[] = ['key' => $k, 'label' => $cols[$k]['label'], 'count' => count($list),
                      'value_usd' => round(array_sum(array_map(function ($c) { return (float)$c['value_usd']; }, $list)), 2)];
        $cards = array_merge($cards, $list);
    }
    $out['total'] = count($cards);
    $limit = (int)($f['limit'] ?? 0);
    if ($limit > 0) $cards = array_slice($cards, 0, $limit);
    foreach ($cards as &$c) unset($c['_ref']);
    unset($c);
    $out['columns'] = $columns;
    $out['cards']   = $cards;
    return $out;
}

/**
 * Move a card (board drag & drop, API pipeline_move): pipeline_column = $col and the status of the column
 * (NEW/WIP → Inquiry, QUOTED → Quoted, HOT → Hot-Quoted). CONFIRMED only moves the card — it never books.
 * Booked / Lost / Cancelled keep their status. A status change logs a status_change timeline event.
 * $opt: dry_run (bool), restrict_agent_id (int: only that agent's requests — Hub staff), refuse_lost (bool: Lost → 409,
 *       reopen with set_request_status first), user_id (int: who parks — parked_by).
 * PARKED: dropping a card there parks it (bs_set_request_status: Inquiry / Quoted / Hot-Quoted / Lost only, else 409);
 * a Parked card dropped on NEW / WIP / QUOTED / HOT gets that column's status and leaves Parked; CONFIRMED refused (409).
 * Nothing is written when the card would stay where it is with the same status (unchanged).
 * → ['ok', 'code', 'error', 'id', 'customer_name', 'column_from', 'column_to', 'status_from', 'status_to', 'unchanged'?, 'note'?]
 */
function pl_move(PDO $db, int $id, string $col, array $opt = []): array {
    $cols = pl_columns(true);
    $out  = ['ok' => false, 'id' => $id];
    if (!isset($cols[$col])) return $out + ['code' => 400, 'error' => 'Invalid column'];
    $st = $db->prepare("SELECT id, customer_name, agent_id, status, pipeline_column, practice_code, start_date FROM requests WHERE id = ?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return $out + ['code' => 404, 'error' => 'Request ' . $id . ' not found'];
    $out['customer_name'] = $r['customer_name'];
    $restrict = (int)($opt['restrict_agent_id'] ?? 0);
    if ($restrict && (int)$r['agent_id'] !== $restrict) return $out + ['code' => 403, 'error' => 'Not your request'];
    $old = (string)$r['status'];
    if ($col === 'parked' || $old === PK_STATUS) return pl_move_parked($db, $r, $col, $opt, $out);
    if ($old === 'Lost' && !empty($opt['refuse_lost'])) {
        return $out + ['code' => 409, 'status_from' => $old, 'error' => 'Request ' . $id . ' is Lost — reopen it with set_request_status first'];
    }

    $closed = in_array($old, ['Booked', 'Lost', 'Cancelled'], true);
    $new    = ($cols[$col]['status'] !== null && !$closed) ? $cols[$col]['status'] : $old;
    $after  = array_merge($r, ['status' => $new, 'pipeline_column' => $col]);
    $from   = pl_column_for($r);
    $to     = pl_column_for($after);
    $out += ['column_from' => $from, 'column_to' => $to, 'status_from' => $old, 'status_to' => $new];
    if ($to !== $col) {
        $out['note'] = $old === 'Booked' ? 'Booked: the card stays CONFIRMED until the folder gets _CK'
                     : 'Status ' . $old . ' — the card is ' . ($to ? 'in ' . strtoupper($to) : 'off the board');
    }
    if ($from === $to && $new === $old) return array_merge($out, ['ok' => true, 'unchanged' => true]);
    if (!empty($opt['dry_run'])) return array_merge($out, ['ok' => true]);

    if ($cols[$col]['status'] !== null) {
        $db->prepare("UPDATE requests SET pipeline_column = ?,
                             status = CASE WHEN status IN ('Booked','Lost','Cancelled') THEN status ELSE ? END
                      WHERE id = ?")->execute([$col, $cols[$col]['status'], $id]);
    } else {
        $db->prepare("UPDATE requests SET pipeline_column = ? WHERE id = ?")->execute([$col, $id]);
    }
    // Report the status actually stored (a closed status is left unchanged).
    $st = $db->prepare("SELECT status FROM requests WHERE id = ?");
    $st->execute([$id]);
    $stored = (string)$st->fetchColumn();
    $out['status_to'] = $stored;
    // Same timeline event as the status menu (bs_set_request_status).
    if ($old !== '' && $stored !== $old) timeline_log($id, 'status_change', $old . ' → ' . $stored);
    return array_merge($out, ['ok' => true]);
}

/** pl_move() into or out of PARKED: the status change goes through bs_set_request_status() (parked fields, rules, timeline). */
function pl_move_parked(PDO $db, array $r, string $col, array $opt, array $out): array {
    $id   = (int)$r['id'];
    $old  = (string)$r['status'];
    $cols = pl_columns(true);
    $new  = $cols[$col]['status'];
    $out += ['column_from' => pl_column_for($r), 'status_from' => $old];
    if ($new === null) {
        return $out + ['code' => 409, 'error' => 'Request ' . $id . ' is Parked — move it to NEW, WIP, QUOTED or HOT (bookings: confirm_booking)'];
    }
    $after = array_merge($r, ['status' => $new, 'pipeline_column' => $col === 'parked' ? $r['pipeline_column'] : $col]);
    $out += ['column_to' => pl_column_for($after), 'status_to' => $new];
    if ($old === $new && $out['column_from'] === $out['column_to']) return array_merge($out, ['ok' => true, 'unchanged' => true]);
    $chk = bs_status_check($db, $id, $new, '', ['restrict_agent_id' => (int)($opt['restrict_agent_id'] ?? 0)]);
    if (!$chk['ok']) return $out + ['code' => $chk['error_code'] === 'not_found' ? 404 : 409, 'error' => $chk['error']];
    if (!empty($opt['dry_run'])) return array_merge($out, ['ok' => true]);
    if ($old !== $new) {
        $res = bs_set_request_status($db, $id, $new, '', '', (int)($opt['user_id'] ?? 0));
        if (empty($res['ok'])) return $out + ['code' => 409, 'error' => $res['error'] ?? 'status change refused'];
    }
    // Out of PARKED into WIP / HOT: the manual column too (PARKED itself is never stored in pipeline_column).
    if ($col !== 'parked') $db->prepare("UPDATE requests SET pipeline_column = ? WHERE id = ?")->execute([$col, $id]);
    return array_merge($out, ['ok' => true]);
}
