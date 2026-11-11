<?php
/**
 * iti_program_build.php — building a personal programme day by day through the
 * Agent API (tailor-made trips with no matching sample): the days with their
 * transfers / activities / flights, the included / excluded lists, the master
 * data they point to (flight routes, activities, transfer routes) and
 * "save as sample". Loaded by iti_program_service.php.
 *
 * Day item (iti_set_days / iti_add_day / iti_update_day), every key optional:
 *   day_title_<lang>, narrative_<lang>,
 *   end_lodge_id | end_lodge (name), end_lodge_custom, room_type ("1 Double + 1 Twin", shown with the lodge),
 *   destination_id | destination (name or code), destination_custom,
 *   start_lodge_id, start_destination_id, start_custom, transfer_route_id, transfer_custom,
 *   meal_breakfast / meal_lunch / meal_dinner / meal_all_inclusive (0/1),
 *   transfers  [ {transfer_route_id, game_drive?} | "Dar airport – Serena Hotel, 40 min" | {description, text_<lang>?} … ]
 *              (replaces the day's list; a catalogue route prints its label in every language; an unchanged
 *              free text keeps its translations)
 *   activities [ {activity_id} | {activity: "<name>"} | {custom: "<text>", text_<lang>?: "<translation>"} … ]
 *   flights    [ {flight_route_id | custom, airline?, dep?: "07:40", arr?: "10:05", note_<lang>?} … ]
 */

/** Day columns an agent may set (limited to the live table). */
function iti_pb_day_columns(): array {
    iti_ps_schema();   // room_type and the day-transfer columns, before the column list is cached (and before any transaction)
    $c = ['end_lodge_id', 'end_lodge_custom', 'room_type', 'destination_id', 'destination_custom', 'start_lodge_id', 'start_destination_id',
          'start_custom', 'transfer_route_id', 'transfer_custom', 'meal_breakfast', 'meal_lunch', 'meal_dinner', 'meal_all_inclusive'];
    foreach (ITI_PS_LANGS as $l) { $c[] = 'day_title_' . $l; $c[] = 'narrative_' . $l; }
    return array_values(array_intersect($c, iti_table_columns('iti_program_days')));
}

/** One row id by id or by name (lodge / destination / activity), or an error that says what to use. */
function iti_pb_lookup(PDO $db, string $what, $val): int {
    $tables = ['lodge' => ['iti_lodges', 'name', 'is_active = 1', 'iti_lodges'],
               'destination' => ['iti_destinations', 'name_en', 'is_active = 1', 'iti_destinations'],
               'activity' => ['iti_activities', 'name_en', 'is_active = 1', 'iti_activities']];
    list($t, $col, $where, $hint) = $tables[$what];
    if (is_numeric($val)) {
        $st = $db->prepare("SELECT id FROM $t WHERE id = ?"); $st->execute([(int)$val]);
        if ($st->fetchColumn()) return (int)$val;
        throw new InvalidArgumentException($what . ' ' . $val . ' not found (' . $hint . ')');
    }
    $v = trim((string)$val);
    $extra = $what === 'destination' ? ' OR code = ?' : ($what === 'activity' ? ' OR name_it = ?' : '');
    $st = $db->prepare("SELECT id FROM $t WHERE $where AND ($col = ?$extra)");
    $st->execute($extra ? [$v, $what === 'destination' ? strtoupper($v) : $v] : [$v]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) === 1) return (int)$ids[0];
    $st = $db->prepare("SELECT id, $col AS n FROM $t WHERE $where AND $col LIKE ? LIMIT 5");
    $st->execute(['%' . $v . '%']);
    $hits = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($hits) === 1) return (int)$hits[0]['id'];
    throw new InvalidArgumentException($what . ' "' . $v . '": ' . ($hits ? 'several match (' . implode(', ', array_map(function ($h) { return '#' . $h['id'] . ' ' . $h['n']; }, $hits)) . ') — use the id'
                                                                         : 'not found — see ' . $hint));
}

/** Validate a day item → ['cols' => [...], 'transfers' => ?[], 'activities' => ?[], 'flights' => ?[]] (null = untouched). */
function iti_pb_day_item(PDO $db, array $f): array {
    foreach (['end_lodge' => ['end_lodge_id', 'lodge'], 'destination' => ['destination_id', 'destination']] as $k => $m) {
        if (array_key_exists($k, $f)) { $f[$m[0]] = $f[$k] === null || $f[$k] === '' ? null : iti_pb_lookup($db, $m[1], $f[$k]); unset($f[$k]); }
    }
    $children = ['transfers' => null, 'activities' => null, 'flights' => null];
    foreach (array_keys($children) as $k) if (array_key_exists($k, $f)) { $children[$k] = (array)$f[$k]; unset($f[$k]); }
    $allowed = iti_pb_day_columns();
    $bad = array_values(array_diff(array_keys($f), $allowed));
    if ($bad) throw new InvalidArgumentException('Day field(s) not allowed: ' . implode(', ', $bad) . ' — allowed: ' . implode(', ', $allowed) . ', end_lodge, destination, transfers, activities, flights');
    $cols = [];
    foreach ($f as $k => $v) {
        if (in_array($k, ['end_lodge_id', 'destination_id', 'start_lodge_id', 'start_destination_id', 'transfer_route_id'], true)) {
            if ($v === null || $v === '' || (int)$v === 0) { $cols[$k] = null; continue; }
            $t = ['end_lodge_id' => 'lodge', 'start_lodge_id' => 'lodge', 'destination_id' => 'destination', 'start_destination_id' => 'destination'];
            if (isset($t[$k])) $v = iti_pb_lookup($db, $t[$k], (int)$v);
            else { $st = $db->prepare('SELECT id FROM iti_transfer_routes WHERE id = ?'); $st->execute([(int)$v]); if (!$st->fetchColumn()) throw new InvalidArgumentException('transfer_route_id ' . $v . ' not found (iti_transfer_routes)'); }
            $cols[$k] = (int)$v;
        } elseif (strpos($k, 'meal_') === 0) {
            $cols[$k] = empty($v) ? 0 : 1;
        } else {
            $v = trim((string)$v);
            if ($k === 'room_type') $v = mb_substr($v, 0, 100);
            $cols[$k] = ($v === '' && in_array($k, ['end_lodge_custom', 'room_type', 'destination_custom', 'start_custom', 'transfer_custom'], true)) ? null : $v;
        }
    }
    if ($children['transfers'] !== null) {
        $tr = [];
        $routes = iti_tr_routes($db);
        foreach ($children['transfers'] as $t) {
            // {"transfer_route_id": 15, "game_drive": 0|1}: from the catalogue, printed in every language.
            if (is_array($t) && !empty($t['transfer_route_id'])) {
                $rid = (int)$t['transfer_route_id'];
                if (!isset($routes[$rid]) || empty($routes[$rid]['is_active'])) throw new InvalidArgumentException('transfer_route_id ' . $rid . ' not found or inactive (iti_transfer_routes)');
                $tr[] = ['route' => $rid, 'game_drive' => empty($t['game_drive']) ? 0 : 1];
                continue;
            }
            $txt = trim(is_array($t) ? (string)($t['description'] ?? $t['text'] ?? '') : (string)$t);
            if ($txt === '') continue;
            $row = ['description' => mb_substr($txt, 0, 255), 'tr' => []];
            // {"description": "…", "text_en": "…"}: translations given with the transfer
            if (is_array($t)) foreach (ITI_TEXT_LANGS as $l) if (trim((string)($t['text_' . $l] ?? '')) !== '') $row['tr'][$l] = mb_substr(trim((string)$t['text_' . $l]), 0, 500);
            $tr[] = $row;
        }
        $children['transfers'] = $tr;
    }
    if ($children['activities'] !== null) {
        $acts = [];
        foreach ($children['activities'] as $a) {
            if (!is_array($a)) $a = ['custom' => (string)$a];
            $row = ['activity_id' => null, 'activity_custom' => null];
            if (!empty($a['activity_id']))      $row['activity_id'] = iti_pb_lookup($db, 'activity', (int)$a['activity_id']);
            elseif (!empty($a['activity']))     $row['activity_id'] = iti_pb_lookup($db, 'activity', (string)$a['activity']);
            elseif (trim((string)($a['custom'] ?? '')) !== '') $row['activity_custom'] = mb_substr(trim((string)$a['custom']), 0, 200);
            else throw new InvalidArgumentException('activity needs activity_id, activity (name) or custom');
            // A custom activity's text per language (custom_note_<lang>, as the translations write it);
            // catalogue activities show their own name, so no text is taken for them.
            foreach (ITI_PS_LANGS as $l) {
                $t = $a['text_' . $l] ?? null;
                if ($t === null || trim((string)$t) === '') continue;
                if ($row['activity_id']) throw new InvalidArgumentException('text_' . $l . ' is only for custom activities (a catalogue activity shows its own name) — use {custom, text_' . $l . '}');
                $row['custom_note_' . $l] = trim((string)$t);
            }
            $acts[] = $row;
        }
        $children['activities'] = $acts;
    }
    if ($children['flights'] !== null) {
        $fl = [];
        foreach ($children['flights'] as $x) {
            if (!is_array($x)) $x = ['custom' => (string)$x];
            $row = ['flight_route_id' => null, 'flight_custom' => null, 'airline_company' => null, 'departure_time' => null, 'arrival_time' => null];
            if (!empty($x['flight_route_id'])) {
                $st = $db->prepare('SELECT id, operator FROM iti_flight_routes WHERE id = ?'); $st->execute([(int)$x['flight_route_id']]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if (!$r) throw new InvalidArgumentException('flight_route_id ' . $x['flight_route_id'] . ' not found (iti_flight_routes)');
                $row['flight_route_id'] = (int)$r['id']; $row['airline_company'] = $r['operator'] ?: null;
            } elseif (trim((string)($x['custom'] ?? '')) !== '') {
                $row['flight_custom'] = mb_substr(trim((string)$x['custom']), 0, 200);
            } else throw new InvalidArgumentException('flight needs flight_route_id or custom');
            if (!empty($x['airline'])) $row['airline_company'] = mb_substr(trim((string)$x['airline']), 0, 100);
            if (($no = trim((string)($x['flight_no'] ?? ''))) !== '') { iti_ensure_doc_columns(); $row['flight_no'] = mb_substr(strtoupper($no), 0, 20); }   // column added lazily
            foreach (['dep' => 'departure_time', 'arr' => 'arrival_time'] as $in => $col) {
                $t = trim((string)($x[$in] ?? $x[$col] ?? ''));
                if ($t === '') continue;
                if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $t)) throw new InvalidArgumentException($in . ': HH:MM');
                $row[$col] = $t . ':00';
            }
            foreach (ITI_PS_LANGS as $l) if (isset($x['note_' . $l])) $row['note_' . $l] = trim((string)$x['note_' . $l]);
            $fl[] = $row;
        }
        $children['flights'] = $fl;
    }
    return ['cols' => $cols] + $children;
}

/** Write a validated item onto a day: columns, then replace the child lists that were given. */
function iti_pb_day_write(PDO $db, int $dayId, array $item): void {
    if ($item['cols']) {
        $sets = []; $args = [];
        foreach ($item['cols'] as $k => $v) { $sets[] = '`' . $k . '` = ?'; $args[] = $v; }
        $args[] = $dayId;
        $db->prepare('UPDATE iti_program_days SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
    }
    if ($item['transfers'] !== null) iti_transfers_replace($db, $dayId, $item['transfers']);
    foreach (['activities' => 'iti_day_activities', 'flights' => 'iti_day_flights'] as $k => $t) {
        if ($item[$k] === null) continue;
        $db->prepare("DELETE FROM $t WHERE program_day_id = ?")->execute([$dayId]);
        foreach ($item[$k] as $i => $row) {
            $row = ['program_day_id' => $dayId, 'sort_order' => $i + 1] + $row;
            $db->prepare("INSERT INTO $t (`" . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
        }
    }
}

/** Delete a day row and its children. */
function iti_pb_day_delete(PDO $db, int $dayId): void {
    foreach (['iti_day_activities', 'iti_day_flights', 'iti_day_transfers'] as $t) $db->prepare("DELETE FROM $t WHERE program_day_id = ?")->execute([$dayId]);
    $db->prepare('DELETE FROM iti_program_days WHERE id = ?')->execute([$dayId]);
}

/** Day numbers 1..N in the current order (two passes: the (program_id, day_number) key is unique), and duration_days. */
function iti_pb_renumber(PDO $db, int $pid): int {
    $st = $db->prepare('SELECT id FROM iti_program_days WHERE program_id = ? ORDER BY day_number, id');
    $st->execute([$pid]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    $db->prepare('UPDATE iti_program_days SET day_number = -day_number WHERE program_id = ?')->execute([$pid]);
    foreach ($ids as $i => $id) $db->prepare('UPDATE iti_program_days SET day_number = ? WHERE id = ?')->execute([$i + 1, $id]);
    $db->prepare('UPDATE iti_programs SET duration_days = ? WHERE id = ?')->execute([max(1, count($ids)), $pid]);
    return count($ids);
}

/** Insert a new day after $after (0 = first) with $item; returns its id. */
function iti_pb_day_insert(PDO $db, int $pid, int $after, array $item): int {
    $db->prepare('UPDATE iti_program_days SET day_number = -(day_number + 1) WHERE program_id = ? AND day_number > ?')->execute([$pid, $after]);
    $db->prepare('UPDATE iti_program_days SET day_number = -day_number WHERE program_id = ? AND day_number < 0')->execute([$pid]);
    $db->prepare('INSERT INTO iti_program_days (program_id, day_number) VALUES (?, ?)')->execute([$pid, $after + 1]);
    $id = (int)$db->lastInsertId();
    iti_pb_day_write($db, $id, $item);
    return $id;
}

/** Replace all the days of a personal programme with $days (ordered list of day items). */
function iti_pb_set_days(PDO $db, int $pid, array $days, bool $go): array {
    iti_ps_personal($pid);
    if (!$days) throw new InvalidArgumentException('days[] is empty');
    $items = [];
    foreach (array_values($days) as $i => $d) {
        try { $items[] = iti_pb_day_item($db, (array)$d); }
        catch (InvalidArgumentException $e) { throw new InvalidArgumentException('days[' . $i . '] (day ' . ($i + 1) . '): ' . $e->getMessage()); }
    }
    $st = $db->prepare('SELECT COUNT(*) FROM iti_program_days WHERE program_id = ?'); $st->execute([$pid]);
    $plan = ['program_id' => $pid, 'days_before' => (int)$st->fetchColumn(), 'days_after' => count($items)];
    if (!$go) return $plan;
    $db->beginTransaction();
    try {
        $st = $db->prepare('SELECT id FROM iti_program_days WHERE program_id = ?'); $st->execute([$pid]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) iti_pb_day_delete($db, (int)$id);
        foreach ($items as $i => $it) {
            $db->prepare('INSERT INTO iti_program_days (program_id, day_number) VALUES (?, ?)')->execute([$pid, $i + 1]);
            iti_pb_day_write($db, (int)$db->lastInsertId(), $it);
        }
        iti_pb_renumber($db, $pid);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    return $plan;
}

/** Add one day after day $after (0 = at the start). */
function iti_pb_add_day(PDO $db, int $pid, int $after, array $fields, bool $go): array {
    iti_ps_personal($pid);
    $st = $db->prepare('SELECT COUNT(*) FROM iti_program_days WHERE program_id = ?'); $st->execute([$pid]);
    $n = (int)$st->fetchColumn();
    if ($after < 0 || $after > $n) throw new InvalidArgumentException('after_day must be 0..' . $n);
    $item = iti_pb_day_item($db, $fields);
    $plan = ['program_id' => $pid, 'new_day' => $after + 1, 'days_after' => $n + 1];
    if (!$go) return $plan;
    $db->beginTransaction();
    try { iti_pb_day_insert($db, $pid, $after, $item); iti_pb_renumber($db, $pid); $db->commit(); }
    catch (Throwable $e) { $db->rollBack(); throw $e; }
    return $plan;
}

/** Delete day $day (and its transfers / activities / flights); the next days move up. */
function iti_pb_delete_day(PDO $db, int $pid, int $day, bool $go): array {
    iti_ps_personal($pid);
    $st = $db->prepare('SELECT id, day_title_it, day_title_en FROM iti_program_days WHERE program_id = ? AND day_number = ?');
    $st->execute([$pid, $day]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new InvalidArgumentException('Day ' . $day . ' not found in program ' . $pid);
    $plan = ['program_id' => $pid, 'delete_day' => $day, 'title' => $row['day_title_it'] ?: $row['day_title_en']];
    if (!$go) return $plan;
    $db->beginTransaction();
    try { iti_pb_day_delete($db, (int)$row['id']); $plan['days_after'] = iti_pb_renumber($db, $pid); $db->commit(); }
    catch (Throwable $e) { $db->rollBack(); throw $e; }
    return $plan;
}

/** The days as stored (ids included), so they can be edited and sent back. */
function iti_pb_days_raw(PDO $db, int $pid): array {
    $st = $db->prepare('SELECT * FROM iti_program_days WHERE program_id = ? ORDER BY day_number');
    $st->execute([$pid]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $id = (int)$d['id'];
        $row = ['day' => (int)$d['day_number']];
        foreach (iti_pb_day_columns() as $c) {
            $v = $d[$c];
            if ($v === null || $v === '') continue;
            if (substr($c, -3) === '_id') { if (!(int)$v) continue; $v = (int)$v; }
            elseif (strpos($c, 'meal_') === 0) $v = (int)$v;
            $row[$c] = $v;
        }
        // Free text → the text; catalogue transfer → {transfer_route_id, game_drive, description} (same shape iti_set_days takes).
        $q = $db->prepare('SELECT * FROM iti_day_transfers WHERE program_day_id = ? ORDER BY sort_order, id'); $q->execute([$id]);
        $row['transfers'] = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $row['transfers'][] = !empty($t['transfer_route_id'])
                ? ['transfer_route_id' => (int)$t['transfer_route_id'], 'game_drive' => (int)($t['game_drive'] ?? 0), 'description' => $t['description']]
                : $t['description'];
        }
        $q = $db->prepare('SELECT a.*, x.name_en AS activity_name FROM iti_day_activities a LEFT JOIN iti_activities x ON x.id = a.activity_id WHERE a.program_day_id = ? ORDER BY a.sort_order, a.id'); $q->execute([$id]);
        $row['activities'] = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $x = $a['activity_id'] ? ['activity_id' => (int)$a['activity_id'], 'name' => $a['activity_name']] : ['custom' => $a['activity_custom']];
            foreach (ITI_PS_LANGS as $l) if (trim((string)$a['custom_note_' . $l]) !== '') $x['text_' . $l] = $a['custom_note_' . $l];
            $row['activities'][] = $x;
        }
        $q = $db->prepare('SELECT f.*, r.from_airport, r.to_airport FROM iti_day_flights f LEFT JOIN iti_flight_routes r ON r.id = f.flight_route_id WHERE f.program_day_id = ? ORDER BY f.sort_order, f.id'); $q->execute([$id]);
        $row['flights'] = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $fl) {
            $x = $fl['flight_route_id'] ? ['flight_route_id' => (int)$fl['flight_route_id'], 'route' => $fl['from_airport'] . ' → ' . $fl['to_airport']] : ['custom' => $fl['flight_custom']];
            if ($fl['airline_company']) $x['airline'] = $fl['airline_company'];
            if (!empty($fl['flight_no'])) $x['flight_no'] = $fl['flight_no'];
            if ($fl['departure_time']) $x['dep'] = substr($fl['departure_time'], 0, 5);
            if ($fl['arrival_time']) $x['arr'] = substr($fl['arrival_time'], 0, 5);
            $row['flights'][] = $x;
        }
        $out[] = $row;
    }
    return $out;
}

// ── Included / not included ──────────────────────────────────────────────────

/**
 * Standard included / not included rows. $country ("KE", "TZ+KE", '' = all): only the rows of
 * those countries and the ones for every country (ALL) — a Kenya programme never gets the
 * Tanzania items (NIC, Tanzania visa), a TZ+KE programme gets both.
 */
function iti_pb_standard_inclusions(PDO $db, string $country = ''): array {
    iti_ensure_country_schema();
    $out = ['included' => [], 'excluded' => []];
    $sql = 'SELECT * FROM iti_standard_inclusions WHERE is_active = 1';
    $args = [];
    if (trim($country) !== '') {
        $codes = array_merge(iti_country_list($country), ['ALL']);
        $sql .= ' AND country IN (' . implode(',', array_fill(0, count($codes), '?')) . ')';
        $args = $codes;
    }
    $st = $db->prepare($sql . ' ORDER BY item_type, sort_order, id');
    $st->execute($args);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $x = ['std_id' => (int)$r['id'], 'country' => $r['country'] ?? 'TZ'];
        foreach (ITI_PS_LANGS as $l) $x['text_' . $l] = $r['text_' . $l];
        $out[$r['item_type'] === 'exclusion' ? 'excluded' : 'included'][] = $x;
    }
    return $out;
}

/**
 * Change the texts of a standard row (only the text_<lang> given). Programme rows with their own copy of the text
 * do not follow it (their text wins): rows_with_own_text says how many.
 */
function iti_pb_update_standard_inclusion(PDO $db, int $stdId, array $in, bool $go): array {
    if ($stdId <= 0) throw new InvalidArgumentException('std_id is required');
    $st = $db->prepare('SELECT * FROM iti_standard_inclusions WHERE id = ?');
    $st->execute([$stdId]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) throw new InvalidArgumentException('std_id ' . $stdId . ' not found (iti_inclusions)');
    $given = 0; $changes = [];
    foreach (ITI_PS_LANGS as $l) {
        $k = 'text_' . $l;
        if (!array_key_exists($k, $in)) continue;
        $given++;
        $v = trim((string)$in[$k]);
        if ($v === '') throw new InvalidArgumentException($k . ' is empty — a standard row needs a text in every language');
        if (mb_strlen($v) > 1000) throw new InvalidArgumentException($k . ' is longer than 1000 characters');
        if ($v !== (string)$s[$k]) $changes[$k] = ['before' => $s[$k], 'after' => $v];
    }
    if (!$given) throw new InvalidArgumentException('Send at least one text_<lang>');
    $own = [];
    foreach (ITI_PS_LANGS as $l) $own[] = "NULLIF(TRIM(text_$l), '')";
    $st = $db->prepare('SELECT COUNT(*) AS linked, COALESCE(SUM(COALESCE(' . implode(', ', $own) . ') IS NOT NULL), 0) AS own
                          FROM iti_program_inclusions WHERE standard_inclusion_id = ?');
    $st->execute([$stdId]);
    $n = $st->fetch(PDO::FETCH_ASSOC);
    $plan = ['std_id' => $stdId, 'item_type' => $s['item_type'], 'changes' => $changes,
             'linked_rows' => (int)$n['linked'], 'rows_with_own_text' => (int)$n['own']];
    if (!$go || !$changes) return $plan;
    $set = []; $args = [];
    foreach ($changes as $k => $c) { $set[] = "`$k` = ?"; $args[] = $c['after']; }
    $args[] = $stdId;
    $db->prepare('UPDATE iti_standard_inclusions SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
    return $plan;
}

/** Replace the included and / or excluded list (only the keys given). Items: {std_id} | {text_<lang>…} | "text". */
function iti_pb_update_inclusions(PDO $db, int $pid, array $in, bool $go): array {
    $p = iti_ps_personal($pid);
    $lang = $p['display_language'] ?: 'it';
    $lists = iti_pb_inclusion_rows($db, $in, $lang);
    $plan = ['program_id' => $pid];
    foreach ($lists as $type => $rows) $plan[$type === 'inclusion' ? 'included' : 'excluded'] = array_map(function ($r) use ($lang) { return $r['text_' . $lang]; }, $rows);
    if (!$go) return $plan;
    $db->beginTransaction();
    try { iti_pb_inclusions_write($db, $pid, $lists); $db->commit(); }
    catch (Throwable $e) { $db->rollBack(); throw $e; }
    return $plan;
}

/**
 * Validate included[] / excluded[] of $in (only the keys given) → ['inclusion' => rows, 'exclusion' => rows],
 * each row {standard_inclusion_id, text_<lang>…}; text_$lang (the program language) is required.
 */
function iti_pb_inclusion_rows(PDO $db, array $in, string $lang): array {
    $lists = [];
    foreach (['included' => 'inclusion', 'excluded' => 'exclusion'] as $k => $type) {
        if (!array_key_exists($k, $in)) continue;
        $rows = [];
        foreach ((array)$in[$k] as $i => $x) {
            $row = ['standard_inclusion_id' => null];
            foreach (ITI_PS_LANGS as $l) $row['text_' . $l] = null;
            if (is_array($x) && !empty($x['std_id'])) {
                $st = $db->prepare('SELECT * FROM iti_standard_inclusions WHERE id = ?'); $st->execute([(int)$x['std_id']]);
                $s = $st->fetch(PDO::FETCH_ASSOC);
                if (!$s) throw new InvalidArgumentException($k . '[' . $i . ']: std_id ' . $x['std_id'] . ' not found (iti_inclusions)');
                $row['standard_inclusion_id'] = (int)$s['id'];
                foreach (ITI_PS_LANGS as $l) $row['text_' . $l] = $s['text_' . $l];
            } else {
                if (!is_array($x)) $x = ['text_' . $lang => (string)$x];
                foreach (ITI_PS_LANGS as $l) if (isset($x['text_' . $l]) && trim((string)$x['text_' . $l]) !== '') $row['text_' . $l] = mb_substr(trim((string)$x['text_' . $l]), 0, 1000);   // VARCHAR(1000) since migration 066
                if ($row['text_' . $lang] === null) throw new InvalidArgumentException($k . '[' . $i . ']: text_' . $lang . ' (the program language) is required');
            }
            $rows[] = $row;
        }
        if (!$rows) throw new InvalidArgumentException($k . ' is empty — send at least one item (a program always lists what is ' . $k . ')');
        $lists[$type] = $rows;
    }
    if (!$lists) throw new InvalidArgumentException('Send included[] and / or excluded[]');
    return $lists;
}

/** Replace a programme's lists with rows from iti_pb_inclusion_rows(); the caller holds the transaction. */
function iti_pb_inclusions_write(PDO $db, int $pid, array $lists): void {
    foreach ($lists as $type => $rows) {
        $db->prepare('DELETE FROM iti_program_inclusions WHERE program_id = ? AND item_type = ?')->execute([$pid, $type]);
        foreach ($rows as $i => $r) {
            $r = ['program_id' => $pid, 'item_type' => $type, 'sort_order' => $i + 1] + $r;
            $db->prepare('INSERT INTO iti_program_inclusions (`' . implode('`,`', array_keys($r)) . '`) VALUES (' . implode(',', array_fill(0, count($r), '?')) . ')')->execute(array_values($r));
        }
    }
}

// ── Master data: flight routes, activities, transfer routes ─────────────────

function iti_pb_flight_routes(PDO $db, array $f): array {
    $w = ['is_active = 1']; $a = [];
    foreach (['from' => 'from_airport', 'to' => 'to_airport'] as $k => $c) if (($v = trim((string)($f[$k] ?? ''))) !== '') { $w[] = "($c LIKE ? OR " . str_replace('airport', 'code', $c) . ' = ?)'; $a[] = "%$v%"; $a[] = strtoupper($v); }
    if (($q = trim((string)($f['q'] ?? ''))) !== '') { $w[] = '(from_airport LIKE ? OR to_airport LIKE ? OR operator LIKE ?)'; array_push($a, "%$q%", "%$q%", "%$q%"); }
    $st = $db->prepare('SELECT id, from_airport, from_code, to_airport, to_code, operator, flight_type, duration_min FROM iti_flight_routes WHERE ' . implode(' AND ', $w) . ' ORDER BY from_airport, to_airport LIMIT 200');
    $st->execute($a);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function iti_pb_create_flight_route(PDO $db, array $f, bool $go): array {
    $row = ['from_airport' => trim((string)($f['from_airport'] ?? '')), 'to_airport' => trim((string)($f['to_airport'] ?? '')),
            'from_code' => strtoupper(trim((string)($f['from_code'] ?? ''))), 'to_code' => strtoupper(trim((string)($f['to_code'] ?? ''))),
            'operator' => trim((string)($f['operator'] ?? '')), 'flight_type' => (string)($f['flight_type'] ?? 'scheduled'),
            'duration_min' => isset($f['duration_min']) ? (int)$f['duration_min'] : null, 'is_active' => 1];
    if ($row['from_airport'] === '' || $row['to_airport'] === '') throw new InvalidArgumentException('from_airport and to_airport are required');
    if (!in_array($row['flight_type'], ['scheduled', 'charter'], true)) throw new InvalidArgumentException('flight_type: scheduled|charter');
    foreach (ITI_PS_LANGS as $l) $row['notes_' . $l] = trim((string)($f['notes_' . $l] ?? ''));
    $st = $db->prepare('SELECT id FROM iti_flight_routes WHERE from_airport = ? AND to_airport = ? AND operator = ?');
    $st->execute([$row['from_airport'], $row['to_airport'], $row['operator']]);
    if ($id = $st->fetchColumn()) throw new InvalidArgumentException('Flight route already exists: #' . $id);
    if (!$go) return ['would_create' => $row];
    $db->prepare('INSERT INTO iti_flight_routes (`' . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    return ['created' => ['id' => (int)$db->lastInsertId()] + $row];
}

function iti_pb_create_activity(PDO $db, array $f, bool $go): array {
    $types = ['game_drive', 'walking_safari', 'cultural', 'boat', 'balloon', 'hiking', 'beach', 'other'];
    $row = ['activity_type' => (string)($f['activity_type'] ?? 'other'), 'is_active' => 1,
            'destination_id' => empty($f['destination_id']) && empty($f['destination']) ? null : iti_pb_lookup($db, 'destination', $f['destination_id'] ?? $f['destination']),
            'duration_hours' => isset($f['duration_hours']) ? (float)$f['duration_hours'] : null];
    if (!in_array($row['activity_type'], $types, true)) throw new InvalidArgumentException('activity_type: ' . implode('|', $types));
    $first = '';
    foreach (array_merge(['en', 'it'], ITI_PS_LANGS) as $l) if ($first === '' && trim((string)($f['name_' . $l] ?? '')) !== '') $first = trim((string)$f['name_' . $l]);
    if ($first === '') throw new InvalidArgumentException('name_en or name_it is required');
    foreach (ITI_PS_LANGS as $l) {
        // A language not given stays empty (NOT NULL: ''): a copy of another language would hide the gap, and the
        // documents never print a name in another language (iti_doc_own()). Fill it later with iti_update_activity.
        $row['name_' . $l] = trim((string)($f['name_' . $l] ?? ''));
        $row['description_' . $l] = trim((string)($f['description_' . $l] ?? ''));
    }
    $given = array_values(array_filter([$row['name_en'], $row['name_it']], 'strlen'));
    $st = $db->prepare('SELECT id FROM iti_activities WHERE ' . implode(' OR ', array_fill(0, count($given), '(name_en = ? OR name_it = ?)')));
    $args = [];
    foreach ($given as $n) array_push($args, $n, $n);
    $st->execute($args);
    if ($id = $st->fetchColumn()) throw new InvalidArgumentException('Activity already exists: #' . $id);
    if (!$go) return ['would_create' => $row];
    $db->prepare('INSERT INTO iti_activities (`' . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    return ['created' => ['id' => (int)$db->lastInsertId()] + $row];
}

/** Active routes (from? / to?: name or code), each with its label in the 5 languages (iti_tr_routes_list()). */
function iti_pb_transfer_routes(PDO $db, array $f): array {
    $out = [];
    $dests = iti_tr_dests($db);
    $match = function ($id, $v) use ($dests) {
        $d = $dests[(int)$id] ?? [];
        return stripos((string)($d['name_en'] ?? ''), $v) !== false || strtoupper((string)($d['code'] ?? '')) === strtoupper($v);
    };
    foreach (iti_tr_routes_list($db) as $r) {
        if (($v = trim((string)($f['from'] ?? ''))) !== '' && !$match($r['from_destination'], $v)) continue;
        if (($v = trim((string)($f['to'] ?? ''))) !== '' && !$match($r['to_destination'], $v)) continue;
        $out[] = $r;
    }
    return $out;
}

/** Route fields from an API body: via_destination (id / name / code, null = none), duration_min, distance_km, road_type, label_<lang>, notes_<lang>. */
function iti_pb_route_fields(PDO $db, array $f): array {
    $row = [];
    if (array_key_exists('via_destination', $f) || array_key_exists('via', $f)) {
        $v = $f['via_destination'] ?? $f['via'];
        $row['via_destination'] = $v === null || $v === '' || $v === 0 ? null : iti_pb_lookup($db, 'destination', $v);
    }
    foreach (['duration_min', 'distance_km'] as $k) if (array_key_exists($k, $f)) $row[$k] = $f[$k] === null || $f[$k] === '' ? null : max(0, (int)$f[$k]);
    if (array_key_exists('road_type', $f)) {
        if (!in_array($f['road_type'], ['tarmac', 'gravel', 'mixed'], true)) throw new InvalidArgumentException('road_type: tarmac|gravel|mixed');
        $row['road_type'] = $f['road_type'];
    }
    foreach (ITI_PS_LANGS as $l) {
        if (array_key_exists('label_' . $l, $f)) { $v = trim((string)$f['label_' . $l]); $row['label_' . $l] = $v === '' ? null : mb_substr($v, 0, 255); }
        if (array_key_exists('notes_' . $l, $f)) $row['notes_' . $l] = trim((string)$f['notes_' . $l]);
    }
    return $row;
}

function iti_pb_create_transfer_route(PDO $db, array $f, bool $go): array {
    iti_tr_schema($db);
    $row = ['from_destination' => iti_pb_lookup($db, 'destination', $f['from_destination'] ?? $f['from'] ?? ''),
            'to_destination' => iti_pb_lookup($db, 'destination', $f['to_destination'] ?? $f['to'] ?? ''),
            'duration_min' => 0, 'distance_km' => null, 'road_type' => 'tarmac', 'is_active' => 1];
    $row = array_merge($row, iti_pb_route_fields($db, $f));
    if ($row['duration_min'] === null) $row['duration_min'] = 0;   // NOT NULL column
    foreach (ITI_PS_LANGS as $l) if (!isset($row['notes_' . $l])) $row['notes_' . $l] = '';
    $st = $db->prepare('SELECT id FROM iti_transfer_routes WHERE from_destination = ? AND to_destination = ?'); $st->execute([$row['from_destination'], $row['to_destination']]);
    if ($id = $st->fetchColumn()) throw new InvalidArgumentException('Transfer route already exists: #' . $id . ' — change it with iti_update_transfer_route');
    $labels = iti_tr_labels($db, $row);
    if (!$go) return ['would_create' => $row, 'labels' => $labels];
    $db->prepare('INSERT INTO iti_transfer_routes (`' . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    $id = (int)$db->lastInsertId();
    iti_tr_routes($db, true);
    return ['created' => ['id' => $id] + $row, 'labels' => $labels];
}

/** Change a route: via, duration, distance, road type, labels / notes per language, is_active. Every programme linked to it prints the new label. */
function iti_pb_update_transfer_route(PDO $db, int $id, array $f, bool $go): array {
    $routes = iti_tr_routes($db);
    if (!isset($routes[$id])) throw new InvalidArgumentException('Transfer route ' . $id . ' not found');
    $set = iti_pb_route_fields($db, $f);
    if (array_key_exists('is_active', $f)) $set['is_active'] = empty($f['is_active']) ? 0 : 1;
    if (array_key_exists('duration_min', $set) && $set['duration_min'] === null) $set['duration_min'] = 0;
    if (!$set) throw new InvalidArgumentException('fields: via_destination, duration_min, distance_km, road_type, label_<lang>, notes_<lang>, is_active');
    $before = $routes[$id];
    $after = array_merge($before, $set);
    $st = $db->prepare('SELECT COUNT(*) FROM iti_day_transfers WHERE transfer_route_id = ?'); $st->execute([$id]);
    $plan = ['route_id' => $id, 'changes' => array_map(function ($k) use ($before, $set) { return ['field' => $k, 'before' => $before[$k] ?? null, 'after' => $set[$k]]; }, array_keys($set)),
             'labels_before' => iti_tr_labels($db, $before), 'labels_after' => iti_tr_labels($db, $after), 'linked_day_transfers' => (int)$st->fetchColumn()];
    if (!$go) return $plan;
    $sets = []; $args = [];
    foreach ($set as $k => $v) { $sets[] = '`' . $k . '` = ?'; $args[] = $v; }
    $args[] = $id;
    $db->prepare('UPDATE iti_transfer_routes SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
    iti_tr_routes($db, true);
    return $plan;
}

// ── Save as sample ───────────────────────────────────────────────────────────

/**
 * A finished personal programme → a new sample (copy): client data removed (dates, pax, prices,
 * request, publication). $in: title_<lang> or title (all languages), code? (Calc code). Confirm-only.
 */
function iti_pb_save_as_sample(PDO $db, int $pid, array $in, string $who, bool $go): array {
    $p = iti_ps_personal($pid);
    $set = ['lead_request_id' => null, 'ref_number' => null, 'start_date' => null, 'pax_adults' => 2, 'pax_teens' => 0, 'pax_children' => 0, 'pax_open' => 0, 'stage' => 'proposal',
            'status' => 'draft', 'hub_program_code' => trim((string)($in['code'] ?? '')) ?: null];
    $cols = iti_table_columns('iti_programs');
    foreach (['price_table_json', 'source_calc_path', 'source_calc_rev', 'generated_at', 'generated_by', 'superseded_by', 'superseded_at'] as $c) if (in_array($c, $cols, true)) $set[$c] = null;
    foreach (ITI_PS_LANGS as $l) foreach (['price_notes_', 'pax_label_'] as $c) if (in_array($c . $l, $cols, true)) $set[$c . $l] = null;
    $title = trim((string)($in['title'] ?? ''));
    foreach (ITI_PS_LANGS as $l) {
        $t = trim((string)($in['title_' . $l] ?? '')) ?: $title;
        if ($t !== '') $set['title_' . $l] = $t;
    }
    if (!isset($set['title_' . ($p['display_language'] ?: 'it')])) throw new InvalidArgumentException('title (or title_<lang>) is required: a sample has no client name');
    $set = array_intersect_key($set, array_flip($cols));
    if (!$go) return ['from_program' => $pid, 'would_set' => $set];
    $id = iti_duplicate_program($pid, 'sample', $who, $set);
    if (in_array('room_type', iti_table_columns('iti_program_days'), true)) {   // the client's rooms are not part of a sample
        $db->prepare('UPDATE iti_program_days SET room_type = NULL WHERE program_id = ?')->execute([$id]);
    }
    return ['from_program' => $pid, 'sample_id' => $id];
}
