<?php
/**
 * ops_service.php — Operations data shared by the Operations pages / their JSON
 * endpoints (api/movements.php, api/lb_list.php, api/medivac_list.php) and the
 * Agent API (ops_movements, ops_lunch_boxes, ops_medivac, flight_tickets).
 *
 *   ops_movement_rows()      Arrivals / Departures / Transfers saved in Operations
 *   ops_lunch_box_rows()     lunch boxes (current, or history)
 *   ops_medivac_groups()     Medivac lists, per import batch
 *   ops_booked_requests()    confirmed bookings with folder start / end
 *   ops_calc()               a booking's Calc (cached on its Dropbox rev)
 *   ops_*_report()           the cross-checks for the Agent API
 *
 * Passport numbers: never stored or returned (Medivac dropped the column, migration 068);
 * the Calc reader skips them.
 * Callers pass the PDO. The Calc / Dropbox helpers are loaded only when used.
 */
require_once __DIR__ . '/../../leads/includes/folder_parser.php';
require_once __DIR__ . '/../../leads/includes/ck_lib.php';   // ck_is_other_destination()

const OPS_NIGHT_CUTOFF   = '06:00:00';
const OPS_CALC_RECHECK   = 600;     // seconds before a cached Calc is compared with Dropbox again
const OPS_CALC_VERSION   = 2;       // bump when ops_calc() reads differently: cached results are read again
const OPS_WARN_DAYS      = 7;       // flight / time missing this close to the date → warning
const OPS_TBA_RE         = '/\b(TBA|TBC|TBD)\b|to be (advised|confirmed)|da definire/i';
/** Overnight in Arusha (Calc OVERNIGHT / HOTEL text). */
const OPS_ARUSHA_WORDS   = ['ARUSHA', 'KIBO PALACE', 'GRAN MELIA', 'MOUNT MERU', 'MT MERU', 'ONSEA', 'RIVERTREES', 'DULUTI',
                            'NGURDOTO', 'PLANET LODGE', 'AFRICAN TULIP', 'LEGENDARY', 'MACHWEO', 'ARUMERU', 'MOIVARO',
                            'TULIA', 'KILI VILLA', 'FOUR POINTS', 'EXPLORERS LODGE', 'SAVANNAH LODGE'];
/** A safari day (park fees / overnight text) — trekking parks are not safari. */
const OPS_SAFARI_WORDS   = ['SERENGETI', 'NGORONGORO', 'CRATER', 'TARANGIRE', 'MANYARA', 'NDUTU', 'SERONERA', 'KOGATENDE',
                            'RUAHA', 'NYERERE', 'SELOUS', 'MIKUMI', 'KATAVI', 'MAHALE', 'NATRON', 'ARUSHA NP', 'ARUSHA NATIONAL'];
const OPS_TREK_WORDS     = ['KILIMANJARO', 'KINAPA', 'MERU', 'MACHAME', 'LEMOSHO', 'MARANGU', 'RONGAI', 'UMBWE', 'TREK'];

// ── Operations tables (pages + API) ─────────────────────────────────────────

/**
 * Movements by display date. $mode single|range|multiple with $q = ['date'|'from','to'|'dates' => [...]].
 * $shift: a movement between 00:00 and 06:00 shows on the previous day (the daily view).
 * Rows get move_date_fmt, move_time_fmt, display_date(_fmt), is_next_day, next_day_label.
 */
function ops_movement_rows(PDO $pdo, string $mode, array $q, bool $shift): array {
    $disp = ops_display_date_sql($shift);
    if ($mode === 'single') {
        $st = $pdo->prepare("SELECT *, $disp AS display_date FROM movements WHERE $disp = ? ORDER BY movement_type DESC, move_time ASC, id ASC");
        $st->execute([$q['date']]);
    } elseif ($mode === 'range') {
        $st = $pdo->prepare("SELECT *, $disp AS display_date FROM movements WHERE $disp BETWEEN ? AND ? ORDER BY display_date ASC, movement_type DESC, move_time ASC");
        $st->execute([$q['from'], $q['to']]);
    } elseif ($mode === 'multiple') {
        $dates = array_values(array_filter(array_map('trim', (array)($q['dates'] ?? []))));
        if (!$dates) return [];
        $in = implode(',', array_fill(0, count($dates), '?'));
        $st = $pdo->prepare("SELECT *, $disp AS display_date FROM movements WHERE $disp IN ($in) ORDER BY display_date ASC, movement_type DESC, move_time ASC");
        $st->execute($dates);
    } else {
        throw new InvalidArgumentException('Invalid mode');
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['move_date_fmt'] = $row['move_date'] ? (new DateTime($row['move_date']))->format('D d M Y') : '';
        $row['move_time_fmt'] = $row['move_time'] ? substr($row['move_time'], 0, 5) : '';
        // Display date (where the row is grouped) + next-day flag/label
        $row['display_date'] = $row['display_date'] ?: $row['move_date'];
        $row['display_date_fmt'] = $row['display_date'] ? (new DateTime($row['display_date']))->format('D d M Y') : '';
        $row['is_next_day'] = ($row['display_date'] !== $row['move_date']) ? 1 : 0;
        if ($row['is_next_day']) {
            $time12 = $row['move_time'] ? (new DateTime($row['move_time']))->format('h:i A') : '';
            $row['next_day_label'] = '+1 day, ' . (new DateTime($row['move_date']))->format('d M') . ($time12 ? ' ' . $time12 : '');
        } else {
            $row['next_day_label'] = '';
        }
    }
    unset($row);
    return $rows;
}

/** Every display date that has movements (calendar dots). */
function ops_movement_dates(PDO $pdo, bool $shift): array {
    $disp = ops_display_date_sql($shift);
    return $pdo->query("SELECT DISTINCT $disp AS d FROM movements ORDER BY d")->fetchAll(PDO::FETCH_COLUMN);
}

function ops_display_date_sql(bool $shift): string {
    return $shift
        ? "CASE WHEN move_time IS NOT NULL AND move_time < '" . OPS_NIGHT_CUTOFF . "'
                THEN DATE_SUB(move_date, INTERVAL 1 DAY) ELSE move_date END"
        : "move_date";
}

/** Lunch boxes by safari date (YYYY-MM-DD bounds, optional) and search; $history = the archived table. */
function ops_lunch_box_rows(PDO $pdo, ?string $from, ?string $to, string $search = '', bool $history = false): array {
    $table = $history ? 'lunch_boxes_history' : 'lunch_boxes';
    $where = []; $params = [];
    if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'safari_date >= ?'; $params[] = $from; }
    if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))     { $where[] = 'safari_date <= ?'; $params[] = $to; }
    if ($search !== '') {
        $where[] = '(LOWER(client_name) LIKE ? OR LOWER(folder_name) LIKE ? OR LOWER(extra_details) LIKE ?)';
        $like = '%' . strtolower($search) . '%';
        array_push($params, $like, $like, $like);
    }
    $sql = "SELECT * FROM `$table`" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY safari_date DESC, client_name ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Medivac lists grouped by import batch (group_ref), by coverage start (bounds optional).
 * Same data for the Medivac page and the Agent API.
 */
function ops_medivac_groups(PDO $pdo, ?string $from, ?string $to, string $search = '', bool $archive = false): array {
    $table = $archive ? 'medivac_travelers_archive' : 'medivac_travelers';
    $cols  = 'id, group_ref, group_name, tour_agent, coverage_start, coverage_end, source_file, created_at,
              full_name, title, dob, country, insurance_name, policy_number';
    $where = []; $params = [];
    if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'coverage_start >= ?'; $params[] = $from; }
    if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))     { $where[] = 'coverage_start <= ?'; $params[] = $to; }
    if ($search !== '') {
        $where[] = '(LOWER(full_name) LIKE ? OR LOWER(group_name) LIKE ?)';
        $params[] = '%' . strtolower($search) . '%';
        $params[] = '%' . strtolower($search) . '%';
    }
    $st = $pdo->prepare("SELECT $cols FROM `$table`" . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
                        . ' ORDER BY coverage_start DESC, group_ref, full_name');
    $st->execute($params);

    $groups = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ref = $r['group_ref'];
        if (!isset($groups[$ref])) {
            $groups[$ref] = [
                'group_ref' => $ref, 'group_name' => $r['group_name'], 'tour_agent' => $r['tour_agent'],
                'coverage_start' => $r['coverage_start'], 'coverage_end' => $r['coverage_end'],
                'source_file' => $r['source_file'], 'created_at' => $r['created_at'], 'travelers' => [],
            ];
        }
        $groups[$ref]['travelers'][] = ['id' => $r['id'], 'full_name' => $r['full_name'], 'title' => $r['title'],
                                        'dob' => $r['dob'], 'country' => $r['country'],
                                        'insurance_name' => $r['insurance_name'], 'policy_number' => $r['policy_number']];
    }
    return array_values($groups);
}

// ── Bookings + their Calc ────────────────────────────────────────────────────

/**
 * Confirmed, not cancelled bookings (same set as Payments, any payment stage) whose folder
 * START or END falls in [$from, $to]. Adds start_date / end_date / folder / agency_tag.
 */
function ops_booked_requests(PDO $db, string $from, string $to): array {
    $rows = $db->query("SELECT r.id, r.customer_name, r.pax, r.practice_code, r.group_folder, r.dropbox_url,
                               r.status, r.payment_status, r.channel, r.destination, a.name AS agent_name
                        FROM requests r LEFT JOIN agents a ON a.id = r.agent_id
                        WHERE r.status IN ('Booked','Paid','Balance','Deposit')
                          AND (r.payment_status IS NULL OR r.payment_status <> 'Cancelled')
                          AND (r.practice_code LIKE '%\\_END%' OR r.group_folder LIKE '%\\_END%' OR r.dropbox_url LIKE '%\\_END%')")
               ->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $folder = get_date_folder($r);
        $d = parse_folder_dates($folder);
        if (!$d['start_date'] && !$d['end_date']) continue;
        $inS = $d['start_date'] && $d['start_date'] >= $from && $d['start_date'] <= $to;
        $inE = $d['end_date'] && $d['end_date'] >= $from && $d['end_date'] <= $to;
        if (!$inS && !$inE) continue;
        $r['start_date'] = $d['start_date'];
        $r['end_date']   = $d['end_date'];
        $r['folder']     = $folder;
        $r['agency_tag'] = folder_agency($r);
        $out[] = $r;
    }
    usort($out, fn($a, $b) => [$a['start_date'] ?? '', $a['id']] <=> [$b['start_date'] ?? '', $b['id']]);
    return $out;
}

/** Destination tags of the agency block, upper-case ("Agency-Agent-TREK" → [AGENCY, AGENT, TREK]). */
function ops_tags(array $r): array {
    return array_map('strtoupper', array_filter(array_map('trim', explode('-', (string)($r['agency_tag'] ?? folder_agency($r))))));
}

function ops_has_word(string $text, array $words): bool {
    $up = strtoupper($text);
    foreach ($words as $w) if (strpos($up, $w) !== false) return true;
    return false;
}

function ops_calc_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS ops_calc_cache (
        request_id  INT UNSIGNED NOT NULL PRIMARY KEY,
        file_path   VARCHAR(1000) NULL,
        file_rev    VARCHAR(64)  NULL,
        result_json MEDIUMTEXT   NULL,
        checked_at  DATETIME     NOT NULL
    ) DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** The booking's Dropbox folders to look in: its own, then (GRP member) the group's. */
function ops_request_dirs(array $r): array {
    $rel = folder_rel_path($r);
    if ($rel === '') return [];
    $dirs = ['/' . $rel];
    $segs = explode('/', $rel);
    if (count($segs) > 2 && folder_is_booking_leaf($segs[count($segs) - 2])) $dirs[] = '/' . implode('/', array_slice($segs, 0, -1));
    // GRP: dropbox_url can keep the parent's old name after a status rename; group_folder is kept current.
    $gf = trim((string)($r['group_folder'] ?? ''));
    $pc = trim((string)($r['practice_code'] ?? ''));
    if ($gf !== '') {
        if ($pc !== '' && $pc !== $gf) $dirs[] = CK_BASE . '/' . $gf . '/' . $pc;
        $dirs[] = CK_BASE . '/' . $gf;
    }
    return array_values(array_unique($dirs));
}

/**
 * A booking's Calc, reduced to what Operations needs, cached in ops_calc_cache and
 * read again only when the file's Dropbox revision changed (checked every OPS_CALC_RECHECK s).
 * Group calc (RECAP): the client's own sheet (ops_group_sheet), else RECAP = the whole group (warning).
 * → ['status' => ok|nofolder|nocalc|unreadable, 'msg'?, 'file'?, 'dir'?, 'sheet', 'group', 'start_date', 'end_date', 'pax',
 *    'arrival', 'departure', 'extra_details', 'days' [{date, label, hotel, flight_cost, park_fees}],
 *    'guests' [{name, title, country, tba}], 'guests_tba', 'warnings']
 * No passport numbers (calc_reader.php never reads them). Dropbox errors throw RuntimeException.
 */
function ops_calc(PDO $db, array $r, bool $force = false): array {
    ops_calc_schema($db);
    require_once __DIR__ . '/../../leads/dropbox_helper.php';
    require_once __DIR__ . '/../../leads/includes/calc_reader.php';
    require_once __DIR__ . '/../../leads/includes/calc_payments.php';   // cp_list_files / cp_pick_calc / cp_retry

    $id = (int)$r['id'];
    $st = $db->prepare("SELECT * FROM ops_calc_cache WHERE request_id = ?");
    $st->execute([$id]);
    $cache  = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $cached = $cache ? json_decode((string)$cache['result_json'], true) : null;
    if ($cached && ($cached['v'] ?? 1) !== OPS_CALC_VERSION) $cached = null;   // read with older rules
    if (!$force && $cached && strtotime($cache['checked_at']) > time() - OPS_CALC_RECHECK) return $cached;

    $save = function (array $res, ?string $file, ?string $rev) use ($db, $id): array {
        $res['v'] = OPS_CALC_VERSION;
        $db->prepare("REPLACE INTO ops_calc_cache (request_id, file_path, file_rev, result_json, checked_at) VALUES (?, ?, ?, ?, ?)")
           ->execute([$id, $file, $rev, json_encode($res, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')]);
        return $res;
    };

    $dirs = ops_request_dirs($r);
    if (!$dirs) return $save(['status' => 'nofolder', 'msg' => 'No Dropbox folder linked'], null, null);
    $token = cp_retry(fn() => dropbox_get_access_token());
    $calc = null; $dir = null;
    foreach ($dirs as $d) {
        $calc = cp_pick_calc(cp_retry(fn() => cp_list_files($token, $d)));
        if ($calc) { $dir = $d; break; }
    }
    if (!$calc) return $save(['status' => 'nocalc', 'msg' => 'No *_Calc.xlsx in ' . implode(' or ', $dirs)], null, null);
    $file = $dir . '/' . $calc['name'];
    if ($cached && $cache['file_path'] === $file && $cache['file_rev'] === $calc['rev']) {
        $db->prepare("UPDATE ops_calc_cache SET checked_at = ? WHERE request_id = ?")->execute([date('Y-m-d H:i:s'), $id]);
        return $cached;
    }

    $bytes = cp_retry(fn() => dropbox_download_text($token, $file));
    if ($bytes === null || $bytes === '') return $save(['status' => 'nocalc', 'msg' => 'Could not download ' . $file], null, null);
    $tmp = tempnam(sys_get_temp_dir(), 'opscalc_');
    file_put_contents($tmp, $bytes);
    $group = null;
    try {
        $sheets = sc_xlsx_sheets($tmp);
        if (sc_has_recap($sheets)) $group = ops_group_sheet($sheets, $r);
        $c = calc_read_file($tmp, $group['sheet'] ?? '');
        if ($group && $group['warning'] !== '') array_unshift($c['warnings'], $group['warning']);
    } catch (InvalidArgumentException $e) {
        return $save(['status' => 'unreadable', 'msg' => $e->getMessage(), 'file' => $calc['name'], 'dir' => $dir], $file, $calc['rev']);
    } finally {
        @unlink($tmp);
    }
    $days = [];
    foreach ($c['days'] as $d) {
        $days[] = ['date' => $d['date'], 'label' => $d['label'], 'hotel' => $d['hotel_text'],
                   'flight_cost' => $d['flight_cost'], 'park_fees' => $d['park_fees_desc']];
    }
    $guests = [];
    foreach ($c['guests'] as $g) $guests[] = ['name' => $g['name'], 'title' => $g['title'], 'country' => $g['country'], 'tba' => $g['tba']];
    return $save([
        'status' => 'ok', 'file' => $calc['name'], 'dir' => $dir, 'sheet' => $c['sheet'],
        'group' => $group ? ($group['whole'] ? 'whole_group' : 'client_sheet') : null,
        'start_date' => $c['start_date'], 'end_date' => $c['end_date'], 'pax' => $c['pax'],
        'arrival' => $c['arrival'], 'departure' => $c['departure'], 'extra_details' => $c['extra_details'],
        'days' => $days, 'guests' => $guests, 'guests_tba' => $c['guests_tba'],
        'warnings' => array_slice($c['warnings'], 0, 5),
    ], $file, $calc['rev']);
}

/**
 * The sheet of a group calc for this booking: the client sheet whose name matches the customer
 * ("1PAX Cacioli" ~ AnnamariaCaciolli, as Payments matches a RECAP row), the only client sheet,
 * else RECAP (the whole group: every client's guests and flights) with a warning.
 * → ['sheet' => name, 'whole' => bool, 'warning' => '']
 */
function ops_group_sheet(array $sheets, array $r): array {
    $clients = calc_group_client_sheets($sheets);
    $recap = '';
    foreach ($sheets as $s) if (stripos($s, 'recap') !== false) { $recap = $s; break; }
    $tok = cp_name_tokens(preg_replace('/_?GRP\d+/i', '', (string)$r['customer_name']));
    $best = []; $max = 0;
    foreach ($clients as $s) {
        $score = $tok ? cp_name_score($tok, $s) : 0;
        if ($score > $max) { $max = $score; $best = [$s]; }
        elseif ($score === $max && $score > 0) $best[] = $s;
    }
    if (count($best) === 1) return ['sheet' => $best[0], 'whole' => false, 'warning' => ''];
    if (count($clients) === 1) return ['sheet' => $clients[0], 'whole' => false, 'warning' => ''];
    return ['sheet' => $recap, 'whole' => true,
            'warning' => 'Group calc: no client sheet named like "' . $r['customer_name'] . '" (' . implode(', ', array_map('trim', $clients))
                       . ') — read RECAP, the whole group.'];
}

/** ops_calc() that never throws: a Dropbox error becomes status "error". */
function ops_calc_safe(PDO $db, array $r): array {
    try {
        return ops_calc($db, $r);
    } catch (Throwable $e) {
        return ['status' => 'error', 'msg' => $e->getMessage()];
    }
}

/** Short public shape of a booking. */
function ops_request_out(array $r): array {
    return ['request_id' => (int)$r['id'], 'folder' => $r['folder'], 'customer' => $r['customer_name'],
            'pax' => $r['pax'] !== null ? (int)$r['pax'] : null, 'start_date' => $r['start_date'], 'end_date' => $r['end_date']];
}

/** "GRP2710" code in a folder / group name, or ''. */
function ops_grp_code(string $s): string {
    return preg_match('/GRP\d{4}/i', $s, $m) ? strtoupper($m[0]) : '';
}

/**
 * The booking among $reqs that $text names, closest to $date by $field (start_date|end_date)
 * within $maxDays. Names: the customer tokens as Payments matches a Calc (2 tokens, or 1 on the
 * same day ± 1), or the same GRP code. Null when none.
 */
function ops_match_request(array $reqs, string $text, ?string $date, string $field, int $maxDays = 7): ?array {
    require_once __DIR__ . '/../../leads/includes/calc_payments.php';   // cp_name_tokens / cp_name_score
    $code = ops_grp_code($text);
    $best = null; $bestKey = null;
    foreach ($reqs as $r) {
        $dd = ($date && $r[$field]) ? abs((int)round((strtotime($date) - strtotime($r[$field])) / 86400)) : 0;
        if ($dd > $maxDays) continue;
        $tok = cp_name_tokens((string)$r['customer_name']);
        $score = $tok ? cp_name_score($tok, $text) : 0;
        $grp = $code !== '' && $code === ops_grp_code($r['folder'] . ' ' . $r['customer_name']);
        if (!$grp && !($tok && ($score >= min(2, count($tok)) || ($score >= 1 && $dd <= 1)))) continue;
        if ($grp) $score = max($score, 1);
        $key = [$dd, -$score];
        if ($bestKey === null || $key < $bestKey) { $best = $r; $bestKey = $key; }
    }
    return $best;
}

/** Flight codes, times, DDMMM dates and TBA in a Calc ARRIVAL / DEPARTURE text. */
function ops_flight_facts(string $text): array {
    // GDS lines pasted from mails carry non-breaking spaces.
    $up = strtoupper(str_replace("\xC2\xA0", ' ', $text));
    // Words / weekday codes ("MO/TU 10") that look like an airline code before a number.
    $skip = ['AT', 'ON', 'BY', 'IN', 'TO', 'OF', 'OR', 'AM', 'PM', 'NO', 'IS', 'IT', 'UP', 'BE', 'DO', 'GO', 'ME', 'MY', 'SO', 'WE', 'US', 'AN', 'NR',
             'MO', 'TU', 'TH', 'FR', 'SA', 'SU', 'HK', 'OK'];
    $mon = 'JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC';
    $flights = $times = $dates = [];
    if (preg_match_all('/\b([A-Z]{2}|[A-Z]\d|\d[A-Z])\s{0,2}(\d{2,4})\b(?![:.,;H]\d)/', $up, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) if (!in_array($x[1], $skip, true)) $flights[] = $x[1] . ltrim($x[2], '0');
    }
    if (preg_match_all('/\b(\d{1,2})[:.,;H](\d{2})\b/', $up, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) if ((int)$x[1] < 24 && (int)$x[2] < 60) $times[] = sprintf('%02d:%02d', (int)$x[1], (int)$x[2]);
    }
    // IATA lines: "ET 815 12OCT ADDJRO 1305 1440" → departure + arrival times.
    if (preg_match_all('/\b[A-Z]{6}\b[^\n]*?\b(\d{4})\s+#?(\d{4})\b/', $up, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) foreach ([$x[1], $x[2]] as $t) {
            if ((int)substr($t, 0, 2) < 24 && (int)substr($t, 2) < 60) $times[] = substr($t, 0, 2) . ':' . substr($t, 2);
        }
    }
    // "12OCT", "13 OCT", "29 October", "October 19".
    if (preg_match_all('/\b(\d{1,2})(?:ST|ND|RD|TH)?\s?(' . $mon . ')[A-Z]*\b/', $up, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) $dates[] = sprintf('%02d', (int)$x[1]) . $x[2];
    }
    if (preg_match_all('/\b(' . $mon . ')[A-Z]*\.?\s+(\d{1,2})\b(?!:)/', $up, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) if ((int)$x[2] >= 1 && (int)$x[2] <= 31) $dates[] = sprintf('%02d', (int)$x[2]) . $x[1];
    }
    return ['flights' => array_values(array_unique($flights)), 'times' => array_values(array_unique($times)),
            'dates' => array_values(array_unique($dates)), 'tba' => (bool)preg_match(OPS_TBA_RE, $text)];
}

function ops_norm_flight(?string $f): string {
    $f = strtoupper(preg_replace('/\s+/', '', (string)$f));
    return preg_match('/^([A-Z0-9]{2})0*(\d+)$/', $f, $m) ? $m[1] . $m[2] : $f;
}

function ops_ddmmm(string $iso, int $plusDays = 0): string {
    return strtoupper(date('dM', strtotime($iso . ' ' . sprintf('%+d', $plusDays) . ' days')));
}

// ── Reports for the Agent API ────────────────────────────────────────────────

/**
 * Arrivals / departures saved in Operations for [$from, $to] (real dates) with the cross-check
 * against the confirmed bookings and their Calc (ARRIVAL / DEPARTURE DETAILS).
 * match: ok | diff | missing_in_ops | manual_only.  $withCalc = false skips the Calc reads.
 */
function ops_movements_report(PDO $db, string $from, string $to, bool $withCalc = true, ?string $today = null): array {
    $today = $today ?? date('Y-m-d');
    $st = $db->prepare("SELECT * FROM movements WHERE move_date BETWEEN ? AND ? AND movement_type IN ('Arrival','Departure')
                        ORDER BY move_date, move_time, id");
    $st->execute([$from, $to]);
    $movs = $st->fetchAll(PDO::FETCH_ASSOC);

    // Bookings a little wider than the period, so a movement one day off still finds its booking.
    $wFrom = date('Y-m-d', strtotime($from . ' -7 days'));
    $wTo   = date('Y-m-d', strtotime($to . ' +7 days'));
    $reqs  = array_values(array_filter(ops_booked_requests($db, $wFrom, $wTo), fn($r) => !ck_is_other_destination($r['folder'])));
    $calc  = [];
    $calcOf = function (array $r) use (&$calc, $db, $withCalc): ?array {
        if (!$withCalc) return null;
        return $calc[(int)$r['id']] ??= ops_calc_safe($db, $r);
    };

    $rows = []; $warnings = []; $seen = [];   // seen["arrival:<rid>"]
    foreach ($movs as $m) {
        $type  = strtolower($m['movement_type']);
        $field = $type === 'arrival' ? 'start_date' : 'end_date';
        $req   = ops_match_request($reqs, $m['client_name'] . ' ' . $m['dropbox_folder'], $m['move_date'], $field);
        $time  = $m['move_time'] ? substr($m['move_time'], 0, 5) : '';
        $row = [
            'date' => $m['move_date'], 'type' => $type, 'movement_id' => (int)$m['id'],
            'request_id' => $req ? (int)$req['id'] : null, 'folder' => $req['folder'] ?? null,
            'customer' => $m['client_name'], 'pax' => $m['pax'] !== null ? (int)$m['pax'] : null,
            'flight' => (string)$m['flight'], 'time' => $time,
            'from_to' => implode(' → ', array_filter([trim((string)$m['pickup']), trim((string)$m['dropoff'])], 'strlen')) ?: null,
            'transfer' => trim((string)$m['driver']) !== '' ? $m['driver'] : null,
            'hotel' => $type === 'arrival' ? $m['dropoff'] : $m['pickup'],
            'notes' => $m['notes'],
            'source' => trim((string)$m['dropbox_folder']) !== '' ? 'calc' : 'manual',
            'calc_value' => null, 'match' => 'manual_only', 'diffs' => [],
        ];
        if ($req) {
            $seen[$type . ':' . $req['id']] = true;
            $row['match'] = 'ok';
            $expected = $req[$field];
            $c = $calcOf($req);
            $text = ($c && $c['status'] === 'ok') ? (string)$c[$type] : '';
            $row['calc_value'] = $text !== '' ? mb_substr($text, 0, 400) : null;
            $facts = ops_flight_facts($text);
            // A bush-flight leg (Auric, Coastal …) or a move mid-trip is not in the Calc ARRIVAL /
            // DEPARTURE text (international flights): nothing to compare.
            $offEnds = $expected !== null && abs((strtotime($m['move_date']) - strtotime($expected)) / 86400) > 1;
            if (ops_is_domestic_flight((string)$m['flight']) || $offEnds) {
                $row['leg'] = $offEnds ? 'mid_trip' : 'domestic';
                $facts = ['flights' => [], 'times' => [], 'dates' => [], 'tba' => false];
                $expected = null;
            }
            // Date: the Calc flight date (±1 day: overnight flights), else the folder START / END (±1).
            $dateOk = $facts['dates']
                ? (bool)array_intersect([ops_ddmmm($m['move_date']), ops_ddmmm($m['move_date'], -1), ops_ddmmm($m['move_date'], 1)], $facts['dates'])
                : ($expected === null || abs((strtotime($m['move_date']) - strtotime($expected)) / 86400) <= 1);
            if (!$dateOk) $row['diffs'][] = ['field' => 'date', 'ops' => $m['move_date'], 'calc' => $facts['dates'] ? implode(', ', $facts['dates']) : $expected];
            if ($facts['flights']) {
                $f = ops_norm_flight($m['flight']);
                if ($f === '' || !in_array($f, array_map('ops_norm_flight', $facts['flights']), true)) {
                    $row['diffs'][] = ['field' => 'flight', 'ops' => $m['flight'] ?: null, 'calc' => implode(', ', $facts['flights'])];
                }
            }
            if ($facts['times'] && !in_array($time, $facts['times'], true)) {
                $row['diffs'][] = ['field' => 'time', 'ops' => $time ?: null, 'calc' => implode(', ', $facts['times'])];
            }
            if ($row['diffs']) $row['match'] = 'diff';
            if ($c && $c['status'] !== 'ok') $row['calc_status'] = $c['status'] . (isset($c['msg']) ? ': ' . $c['msg'] : '');
        }
        $soon = (strtotime($m['move_date']) - strtotime($today)) / 86400;
        if ($soon >= 0 && $soon <= OPS_WARN_DAYS) {
            $miss = [];
            if (trim((string)$m['flight']) === '' || preg_match(OPS_TBA_RE, (string)$m['flight'])) $miss[] = 'flight';
            if ($time === '') $miss[] = 'time';
            if ($miss) $warnings[] = ['date' => $m['move_date'], 'type' => $type, 'movement_id' => (int)$m['id'], 'customer' => $m['client_name'],
                                      'msg' => ucfirst(implode(' and ', $miss)) . ' missing / TBA'];
        }
        $rows[] = $row;
    }

    // Bookings starting / ending in the period with no arrival / departure row.
    foreach ($reqs as $r) {
        foreach (['arrival' => 'start_date', 'departure' => 'end_date'] as $type => $field) {
            $d = $r[$field];
            if (!$d || $d < $from || $d > $to || isset($seen[$type . ':' . $r['id']])) continue;
            $c = $calcOf($r);
            $text = ($c && $c['status'] === 'ok') ? (string)$c[$type] : '';
            $rows[] = ['date' => $d, 'type' => $type, 'movement_id' => null] + ops_request_out($r) + [
                'flight' => null, 'time' => null, 'source' => 'calc',
                'calc_value' => $text !== '' ? mb_substr($text, 0, 400) : null, 'match' => 'missing_in_ops', 'diffs' => [],
            ];
            $soon = (strtotime($d) - strtotime($today)) / 86400;
            if ($soon >= 0 && $soon <= OPS_WARN_DAYS) {
                $facts = ops_flight_facts($text);
                $warnings[] = ['date' => $d, 'type' => $type, 'request_id' => (int)$r['id'], 'customer' => $r['customer_name'],
                               'msg' => 'No ' . $type . ' in Operations' . ($text === '' || $facts['tba'] ? ' and no confirmed flight in the Calc' : '')];
            }
        }
    }
    usort($rows, fn($a, $b) => [$a['date'], $a['type'], (string)($a['time'] ?? '')] <=> [$b['date'], $b['type'], (string)($b['time'] ?? '')]);
    $counts = ['ok' => 0, 'diff' => 0, 'missing_in_ops' => 0, 'manual_only' => 0];
    foreach ($rows as $x) $counts[$x['match']]++;
    return ['from' => $from, 'to' => $to, 'counts' => $counts + ['warnings' => count($warnings)], 'rows' => $rows, 'warnings' => $warnings];
}

/**
 * Lunch boxes for safari dates in [$from, $to] (current + history tables) and the
 * bookings that should have one: a safari day after a night in Arusha (Calc) with no
 * lunch box row for that date.
 */
function ops_lunch_boxes_report(PDO $db, string $from, string $to, bool $withCalc = true): array {
    $lb = ops_lunch_box_rows($db, $from, $to);
    try { $lb = array_merge($lb, array_map(fn($x) => $x + ['_history' => true], ops_lunch_box_rows($db, $from, $to, '', true))); }
    catch (Throwable $e) { /* no history table */ }

    // Bookings around the period (a lunch box is on a day inside the trip).
    $wFrom = date('Y-m-d', strtotime($from . ' -21 days'));
    $reqs  = array_values(array_filter(ops_booked_requests($db, $wFrom, date('Y-m-d', strtotime($to . ' +21 days'))),
                                       fn($r) => !ck_is_other_destination($r['folder'])));
    $calc = [];
    $rows = []; $byReqDate = [];
    foreach ($lb as $x) {
        $req = ops_match_request($reqs, $x['client_name'] . ' ' . $x['folder_name'] . ' ' . $x['source_file'], $x['safari_date'], 'start_date', 30);
        $lodge = null;
        if ($req && $withCalc) {
            $c = $calc[(int)$req['id']] ??= ops_calc_safe($db, $req);
            $lodge = ops_night_before($c, (string)$x['safari_date']);
        }
        if ($req) $byReqDate[$req['id'] . '|' . $x['safari_date']] = true;
        $rows[] = [
            'id' => (int)$x['id'], 'date' => $x['safari_date'], 'client' => $x['client_name'],
            'request_id' => $req ? (int)$req['id'] : null, 'folder' => $req['folder'] ?? null,
            'pax' => $x['travelers'] !== null ? (int)$x['travelers'] : null, 'jeeps' => $x['jeeps'] !== null ? (int)$x['jeeps'] : null,
            'guide' => $x['guide'] ?? null, 'box_type' => $x['box_type'] ?? null,
            'lodge' => $lodge, 'diet_notes' => $x['extra_details'], 'notes' => $x['notes'],
            'folder_name' => $x['folder_name'], 'source' => !empty($x['_history']) ? 'lunch_boxes_history' : 'lunch_boxes',
        ];
    }

    $missing = []; $unchecked = [];
    if ($withCalc) {
        foreach ($reqs as $r) {
            // Only trips with days in the period.
            if (!$r['start_date'] || !$r['end_date'] || $r['start_date'] > $to || $r['end_date'] < $from) continue;
            $c = $calc[(int)$r['id']] ??= ops_calc_safe($db, $r);
            if ($c['status'] !== 'ok') {
                if ($r['start_date'] >= $from && $r['start_date'] <= $to) $unchecked[] = ops_request_out($r) + ['calc_status' => $c['status'], 'msg' => $c['msg'] ?? null];
                continue;
            }
            $days = $c['days'];
            for ($i = 1; $i < count($days); $i++) {
                $d = $days[$i]['date'];
                if (!$d || $d < $from || $d > $to) continue;
                $prev = $days[$i - 1];
                if (!ops_is_safari_day($days[$i]) || !ops_has_word(ops_overnight($prev), OPS_ARUSHA_WORDS)) continue;
                if (!isset($byReqDate[$r['id'] . '|' . $d])) {
                    $missing[] = ['date' => $d] + ops_request_out($r) + [
                        'lodge' => ops_overnight($prev),
                        'safari_day' => trim($days[$i]['label']), 'extra_details' => $c['extra_details'] ?: null,
                    ];
                }
            }
        }
    }
    usort($rows, fn($a, $b) => [(string)$a['date'], $a['client']] <=> [(string)$b['date'], $b['client']]);
    usort($missing, fn($a, $b) => $a['date'] <=> $b['date']);
    return ['from' => $from, 'to' => $to, 'counts' => ['rows' => count($rows), 'missing' => count($missing), 'calc_unreadable' => count($unchecked)],
            'rows' => $rows, 'missing' => $missing, 'calc_unreadable' => $unchecked];
}

/** A Calc day that is a game drive (park fees, or a safari park in the day text). */
function ops_is_safari_day(array $day): bool {
    $txt = $day['park_fees'] . ' ' . $day['label'];
    if (ops_has_word($txt, OPS_SAFARI_WORDS)) return true;
    return trim((string)$day['park_fees']) !== '' && !ops_has_word($day['park_fees'], OPS_TREK_WORDS);
}

/** Where a Calc day ends: the hotel, else the last place of the day text ("JRO-Arusha-Karatu" → Karatu). */
function ops_overnight(array $day): string {
    if (trim((string)$day['hotel']) !== '') return trim($day['hotel']);
    $parts = array_values(array_filter(array_map('trim', explode('-', (string)$day['label'])), 'strlen'));
    return $parts ? (string)end($parts) : '';
}

/** Overnight before $date in a Calc, or null. */
function ops_night_before(?array $c, string $date): ?string {
    if (!$c || $c['status'] !== 'ok' || $date === '') return null;
    $prev = date('Y-m-d', strtotime($date . ' -1 day'));
    foreach ($c['days'] as $d) {
        if ($d['date'] === $prev) return ops_overnight($d) ?: null;
    }
    return null;
}

/**
 * Does a booking need a Medivac registration? Tanzania safaris only: other countries, Only
 * Zanzibar and Staff are out; trekking only is out, trekking + safari is in (safari wins).
 * → [bool, reason]
 */
function ops_medivac_eligible(array $r, ?array $c): array {
    if (ck_is_other_destination($r['folder'])) return [false, 'not Tanzania'];
    $tags = ops_tags($r);
    if (in_array('STAFF', $tags, true)) return [false, 'staff'];
    if (in_array('ZNZ', $tags, true)) return [false, 'only Zanzibar'];
    $trek = in_array('TREK', $tags, true);
    if (!$trek) return [true, 'safari'];
    if (!$c || $c['status'] !== 'ok') return [true, 'trekking — Calc not readable, check if it includes a safari'];
    foreach ($c['days'] as $d) if (ops_is_safari_day($d)) return [true, 'trekking + safari'];
    return [false, 'trekking only'];
}

/** Medivac guest list check: TBA names, missing nationality. */
function ops_guest_warnings(array $guests): array {
    $w = [];
    if (!$guests) return ['no guest names'];
    foreach ($guests as $g) {
        if (!empty($g['tba']) || preg_match('/^(tba|tbc|tbd|n\/?a|\?+|-+|(guest|pax|name)\s*\d*)\.?$/i', trim((string)$g['name']))) {
            $w[] = 'name TBA: ' . $g['name'];
        } elseif (trim((string)($g['nationality'] ?? '')) === '') {
            $w[] = 'no nationality: ' . $g['name'];
        }
    }
    return $w;
}

/**
 * Medivac registrations with coverage starting in [$from, $to] (no passport numbers) and the
 * Tanzania safaris starting then that are not registered (guests from the Calc).
 */
function ops_medivac_report(PDO $db, string $from, string $to, bool $withCalc = true): array {
    $groups = ops_medivac_groups($db, $from, $to);
    try { $groups = array_merge($groups, array_map(fn($g) => $g + ['_archive' => true], ops_medivac_groups($db, $from, $to, '', true))); }
    catch (Throwable $e) { /* no archive table */ }
    $reqs = ops_booked_requests($db, date('Y-m-d', strtotime($from . ' -7 days')), date('Y-m-d', strtotime($to . ' +7 days')));
    $calc = [];

    $rows = []; $covered = [];
    foreach ($groups as $g) {
        $gText = $g['group_name'] . ' ' . $g['source_file'];
        $req = ops_match_request($reqs, $gText, $g['coverage_start'], 'start_date', 3);
        if ($req) $covered[(int)$req['id']] = true;
        if (($code = ops_grp_code($gText)) !== '') {      // one list for the whole group
            foreach ($reqs as $x) if (ops_grp_code($x['folder'] . ' ' . $x['customer_name']) === $code) $covered[(int)$x['id']] = true;
        }
        $guests = array_map(fn($t) => ['name' => $t['full_name'], 'title' => $t['title'], 'nationality' => $t['country']], $g['travelers']);
        $rows[] = ['group_ref' => $g['group_ref'], 'group_name' => $g['group_name'], 'tour_agent' => $g['tour_agent'],
                   'request_id' => $req ? (int)$req['id'] : null, 'folder' => $req['folder'] ?? null,
                   'start_date' => $g['coverage_start'], 'end_date' => $g['coverage_end'],
                   'guests' => $guests, 'warnings' => ops_guest_warnings($guests),
                   'source' => !empty($g['_archive']) ? 'medivac_archive' : 'medivac'];
    }

    $missing = []; $excluded = [];
    foreach ($reqs as $r) {
        if (!$r['start_date'] || $r['start_date'] < $from || $r['start_date'] > $to || isset($covered[(int)$r['id']])) continue;
        $tags = ops_tags($r);
        $c = null;
        if ($withCalc && !ck_is_other_destination($r['folder']) && !array_intersect(['STAFF', 'ZNZ'], $tags)) {
            $c = $calc[(int)$r['id']] ??= ops_calc_safe($db, $r);
        }
        [$ok, $why] = ops_medivac_eligible($r, $c);
        if (!$ok) { $excluded[] = ops_request_out($r) + ['reason' => $why]; continue; }
        $guests = [];
        if ($c && $c['status'] === 'ok') {
            foreach ($c['guests'] as $gst) $guests[] = ['name' => $gst['name'], 'title' => $gst['title'], 'nationality' => $gst['country'], 'tba' => $gst['tba']];
        }
        $w = ($c && $c['status'] === 'ok') ? ops_guest_warnings($guests) : ['Calc not read' . ($c ? ' (' . $c['status'] . (isset($c['msg']) ? ': ' . $c['msg'] : '') . ')' : '')];
        $missing[] = ops_request_out($r) + ['reason' => $why,
                     'guests' => array_map(fn($x) => array_diff_key($x, ['tba' => 1]), $guests), 'warnings' => $w];
    }
    usort($rows, fn($a, $b) => (string)$a['start_date'] <=> (string)$b['start_date']);
    return ['from' => $from, 'to' => $to,
            'counts' => ['registered' => count($rows), 'missing' => count($missing), 'excluded' => count($excluded)],
            'rows' => $rows, 'missing' => $missing, 'excluded' => $excluded];
}

/** Operations flight text of a domestic carrier ("Auric air", "Coastal", "FL 123" …). */
function ops_is_domestic_flight(string $f): bool {
    return (bool)preg_match('/auric|coastal|precision|flight ?link|as ?salaam|assalaam|safari ?air|regional|unity|zanair|tropical|\bPW\s?\d|\bQZ\s?\d/i', $f);
}

/** A file name that looks like a flight ticket. */
function ops_is_ticket_file(string $name): bool {
    if (!preg_match('/\.(pdf|jpe?g|png|eml|msg)$/i', $name)) return false;
    return (bool)preg_match('/e-?ticket|ticket|boarding|\bpnr\b|itinerar|reservation|auric|coastal|precision|flightlink|as ?salaam|'
                          . 'safari ?air|regional|air ?tanzania|airkenya|tropical|unity|zanair|\bflight/i', $name);
}

/**
 * Bookings starting in [$from, $to] with domestic flights booked by Savannah (Calc FLIGHT
 * cost > 0) and the ticket files in their folder (top level, or anything in flights/).
 * status: ok (tickets found) | missing | unknown (Calc not readable).
 */
function ops_flight_tickets_report(PDO $db, string $from, string $to): array {
    require_once __DIR__ . '/../../leads/dropbox_helper.php';
    require_once __DIR__ . '/../../leads/includes/calc_payments.php';
    $reqs = array_values(array_filter(ops_booked_requests($db, $from, $to),
                                      fn($r) => $r['start_date'] && $r['start_date'] >= $from && $r['start_date'] <= $to));
    $token = null;
    $rows = []; $noFlights = 0;
    foreach ($reqs as $r) {
        $c = ops_calc_safe($db, $r);
        if ($c['status'] !== 'ok') {
            $rows[] = ops_request_out($r) + ['flights' => [], 'ticket_files' => [], 'status' => 'unknown',
                                             'msg' => $c['status'] . (isset($c['msg']) ? ': ' . $c['msg'] : '')];
            continue;
        }
        $flights = [];
        foreach ($c['days'] as $d) {
            if ((float)$d['flight_cost'] > 0) $flights[] = ['date' => $d['date'], 'route' => trim($d['label']), 'cost' => (float)$d['flight_cost']];
        }
        if (!$flights) { $noFlights++; continue; }
        $files = [];
        try {
            $token = $token ?? cp_retry(fn() => dropbox_get_access_token());
            foreach (ops_request_dirs($r) as $dir) {
                foreach (cp_retry(fn() => cp_list_files($token, $dir)) as $f) {
                    if (ops_is_ticket_file($f['name'])) $files[] = $f['name'];
                }
                foreach (cp_retry(fn() => cp_list_files($token, $dir . '/flights')) as $f) $files[] = 'flights/' . $f['name'];
            }
        } catch (Throwable $e) {
            $rows[] = ops_request_out($r) + ['flights' => $flights, 'ticket_files' => [], 'status' => 'unknown', 'msg' => $e->getMessage()];
            continue;
        }
        $files = array_values(array_unique($files));
        $rows[] = ops_request_out($r) + ['flights' => $flights, 'ticket_files' => $files, 'status' => $files ? 'ok' : 'missing'];
    }
    $counts = ['ok' => 0, 'missing' => 0, 'unknown' => 0];
    foreach ($rows as $x) $counts[$x['status']]++;
    return ['from' => $from, 'to' => $to, 'counts' => $counts + ['without_flights' => $noFlights], 'rows' => $rows];
}
