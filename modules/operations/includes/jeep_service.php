<?php
/**
 * jeep_service.php — Operations → Jeep demand (phase 1, 22 Nov 2026).
 *
 * How many safari jeeps Savannah needs per day / week / month, read from each confirmed safari's Calc:
 *   jeeps(day) = JEEP cell / "Cost per Jeep"  (calculated values)
 * so a doubled day (empty drive back, fly-out from Kogatende) = 2, a half arrival day = 0.5, a beach day = 0,
 * a 3-jeep safari = 3. Same rules as the Cowork prototype (Dropbox /Exchange/JeepUtilization/scan2.py + agg.py).
 *
 * Shared by the page (jeep_demand.php + api/jeep_demand.php), the cron step in leads/ck_cron.php and the Agent API
 * (jeep_demand, jeep_demand_day, jeep_demand_scan). Tables: ops_jeep_scan / ops_jeep_days / ops_jeep_meta
 * (migration 087, also created lazily by jeep_schema()).
 *
 *   Scan (Dropbox)   jeep_scan_run()        discover folders, list their files, re-read a Calc only when its files changed
 *   Read (local)     jeep_pick_order()      which Excel files of a folder to try, in order
 *                    jeep_read_workbook()   a local .xlsx/.xlsm → its sheets with a Calc header + their dated JEEP values
 *                    jeep_folder_result()   the sheets → jeeps per day of the folder (RECAP / CONF / only / most days)
 *   Report           jeep_daily()           jeeps per date (zeros filled), jeep_stats(), jeep_periods(), jeep_page_data(),
 *                    jeep_day_detail()      the safaris on the road one day
 *
 * Not covered (said on the page): transfers not in Calcs, work for other operators without a Calc, old .xls Calcs,
 * cancelled bookings. Kenya / Namibia Calcs have no JEEP values and count 0 (not our jeeps).
 * No passport numbers: only the header block and the day table are read.
 */
require_once __DIR__ . '/../../leads/includes/folder_parser.php';
require_once __DIR__ . '/../../leads/includes/ck_lib.php';          // CK_BASE, ck_is_other_destination(), ck_url_from_path()
require_once __DIR__ . '/../../leads/includes/safari_check.php';    // sc_xlsx_sheets() / sc_xlsx_sheet() / sc_parse_xlsx_date()

const JEEP_READER_VERSION = 1;      // bump when the reading rules change: every folder is read again by the next runs
const JEEP_FIRST_ARCHIVE  = 2024;   // oldest /001_Safari/00_YYYY read by a full rebuild
const JEEP_MAX_PER_DAY    = 12;     // a day giving more jeeps than this is a Calc error: skipped and logged
const JEEP_RECHECK_HOURS  = 20;     // a folder's files are listed again after this (the cron runs every 30 min)
const JEEP_CURVE_MAX      = 45;     // "days with at least N jeeps", N = 1…45
const JEEP_STATUSES       = ['ok', 'no_calc', 'old_xls', 'no_values', 'ambiguous_sheet', 'multi_calc', 'error'];
/** File names that are copies, not the Calc (as the prototype: conflicted copies, "Copy of …", "… - Copia"). */
const JEEP_BAD_FILE_RE    = '/conflicted|copia|copy| - c/i';

// ── Schema ──────────────────────────────────────────────────────────────────

function jeep_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    // Same three tables as migrations/087_jeep_demand.sql (keep them in step).
    {
        $db->exec("CREATE TABLE IF NOT EXISTS ops_jeep_scan (id INT AUTO_INCREMENT PRIMARY KEY, dropbox_id VARCHAR(64) NULL,
            folder_path VARCHAR(500) NOT NULL, folder_name VARCHAR(300) NOT NULL, request_id INT NULL, stage VARCHAR(20) NULL,
            provisional TINYINT(1) NOT NULL DEFAULT 0, calc_file VARCHAR(300) NULL, calc_rev VARCHAR(64) NULL, calc_mtime DATETIME NULL,
            files_sig CHAR(40) NULL, sheet VARCHAR(255) NULL, kind ENUM('PVT','GRP') NULL, jeeps_header DECIMAL(4,1) NULL, pax INT NULL,
            cost_per_jeep DECIMAL(8,2) NULL, start_day DATE NULL, end_day DATE NULL, jeep_days DECIMAL(7,2) NULL, guide VARCHAR(100) NULL,
            status ENUM('ok','no_calc','old_xls','no_values','ambiguous_sheet','multi_calc','error') NOT NULL DEFAULT 'ok',
            message VARCHAR(500) NULL, need_scan TINYINT(1) NOT NULL DEFAULT 1, reader_v SMALLINT NOT NULL DEFAULT 0,
            checked_at DATETIME NULL, scanned_at DATETIME NULL, UNIQUE KEY uq_jeep_dropbox_id (dropbox_id),
            KEY idx_jeep_path (folder_path(191)), KEY idx_jeep_status (status)) DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS ops_jeep_days (id INT AUTO_INCREMENT PRIMARY KEY, scan_id INT NOT NULL, request_id INT NULL,
            day DATE NOT NULL, jeeps DECIMAL(5,2) NOT NULL, guide VARCHAR(100) NULL, KEY idx_jeep_day (day), KEY idx_jeep_scan (scan_id)) DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS ops_jeep_meta (k VARCHAR(40) PRIMARY KEY, v TEXT NULL) DEFAULT CHARSET=utf8mb4");
    }
    $done = true;
}

function jeep_meta_get(PDO $db, string $k): ?string {
    jeep_schema($db);
    $st = $db->prepare('SELECT v FROM ops_jeep_meta WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

function jeep_meta_set(PDO $db, string $k, ?string $v): void {
    jeep_schema($db);
    $db->prepare('REPLACE INTO ops_jeep_meta (k, v) VALUES (?, ?)')->execute([$k, $v]);
}

// ── Folder names ────────────────────────────────────────────────────────────

/** A folder whose Calc counts: "NN_…" with an END date; not -STAFF, not 00_CANCELED (not "NN_" anyway). */
function jeep_folder_eligible(string $name): bool {
    return (bool)preg_match('/^\d\d_/', $name) && stripos($name, 'END') !== false && stripos($name, '-STAFF') === false;
}

/** Payment / status tag of a folder name, upper-case, or null. */
function jeep_stage(string $name): ?string {
    foreach (['PAID', 'BALANCE-CASH', 'BALANCE', 'DEPOSIT', 'PROVISIONAL', 'PROGRESS'] as $t) {
        if (preg_match('/_' . preg_quote($t, '/') . '(?![A-Z])/i', $name)) return $t;
    }
    return null;
}

function jeep_is_provisional(string $name): bool {
    return in_array(jeep_stage($name), ['PROVISIONAL', 'PROGRESS'], true);
}

/** "EVAREST." / "Evarest " → "EVAREST" (case and trailing punctuation only; mapping to staff is phase 2). */
function jeep_guide_key(?string $g): string {
    $g = strtoupper(trim(preg_replace('/\s+/', ' ', (string)$g)));
    return trim(rtrim($g, " .,;:-_/\\"));
}

// ── Reading a Calc (local file) ─────────────────────────────────────────────

function jeep_num(?string $v): ?float {
    $v = trim((string)$v);
    return ($v !== '' && is_numeric($v)) ? (float)$v : null;
}

function jeep_lab(?string $v): string {
    return strtolower(trim((string)$v));
}

/** Leading number of a file name ("06_…" → 6), 0 when none. */
function jeep_file_prefix(string $name): int {
    return preg_match('/^(\d+)/', $name, $m) ? (int)$m[1] : 0;
}

/**
 * The Excel files of a folder to try, in order: top-level .xlsx / .xlsm (not "~$…"), real names before copies
 * (JEEP_BAD_FILE_RE), then the highest leading number ("06_" before "01_"), then the name (Z → A) — the first one
 * with a Calc header is the folder's Calc, as in the prototype. Old .xls files are listed apart.
 * $files: [['name' => …, 'rev' => …, 'modified' => …], …] (rev / modified optional).
 * @return array{order: array, old_xls: string[], sig: string}
 */
function jeep_pick_order(array $files): array {
    $x = []; $old = []; $sig = [];
    foreach ($files as $f) {
        $n = (string)$f['name'];
        if (strpos($n, '~$') === 0) continue;
        if (preg_match('/\.xls[xm]$/i', $n)) { $x[] = $f; $sig[] = $n . '@' . ($f['rev'] ?? ''); }
        elseif (preg_match('/\.xls$/i', $n)) { $old[] = $n; $sig[] = $n . '@' . ($f['rev'] ?? ''); }
    }
    usort($x, function ($a, $b) {
        $ba = preg_match(JEEP_BAD_FILE_RE, $a['name']) ? 1 : 0;
        $bb = preg_match(JEEP_BAD_FILE_RE, $b['name']) ? 1 : 0;
        if ($ba !== $bb) return $ba - $bb;
        $pa = jeep_file_prefix($a['name']); $pb = jeep_file_prefix($b['name']);
        if ($pa !== $pb) return $pb - $pa;
        return strcmp($b['name'], $a['name']);
    });
    sort($sig);
    return ['order' => $x, 'old_xls' => $old, 'sig' => sha1(implode("\n", $sig))];
}

/**
 * Two Calcs of different programs in one folder (EU0210: "01_…Simba3_Calc" + "02_…BeachDumaShort_Calc"): only the
 * highest is read, so the folder is listed in Data quality. The program = the name after the "(Agency-Agent)" block.
 */
function jeep_multi_calc(array $order): ?string {
    $progs = [];
    foreach ($order as $f) {
        $n = (string)$f['name'];
        if (preg_match(JEEP_BAD_FILE_RE, $n) || !preg_match('/_calc[^\/]*\.xls[xm]$/i', $n)) continue;
        $p = strtolower((string)preg_replace('/_calc.*$/i', '', (string)preg_replace('/^.*\)/', '', $n)));
        $p = trim($p, " _-");
        if ($p !== '') $progs[$p] = $n;
    }
    return count($progs) > 1 ? implode(' + ', array_values($progs)) : null;
}

/**
 * Saved values of PhpSpreadsheet-calculated cells A1:G90 of one sheet, for a Calc saved without cached formula
 * values. Null when PhpSpreadsheet is not installed or fails.
 */
function jeep_ps_values(string $path, string $sheet): ?array {
    try {
        require_once __DIR__ . '/../../leads/includes/calc_service.php';
        calc_require_spreadsheet();
        $ss = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $ws = $ss->getSheetByName($sheet);
        if (!$ws) return null;
        $v = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $c) {
            for ($r = 1; $r <= 90; $r++) {
                $x = $ws->getCell($c . $r)->getCalculatedValue();
                if ($x !== null && $x !== '') $v[$c . $r] = trim((string)$x);
            }
        }
        $ss->disconnectWorksheets();
        return $v;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * The sheets of a local Calc that have a Calc header, with their dated JEEP values:
 *   [['sheet', 'pax', 'jeeps' (Number of Jeeps), 'cpj' (Cost per Jeep), 'guide', 'days' => [[Y-m-d, value|null], …],
 *     'uncalculated' => bool (saved without formula values and PhpSpreadsheet not available)], …]
 * Header labels in rows 1–10 (A "Number of Jeeps" → B, A "TOT PAX" → B, C "Cost per Jeep" → D); day table under the
 * row whose A is "DATE" (JEEP column found by its header, D by default) up to "Totals"; "GUIDE" in A → name in B.
 * A day-row date that is a formula with no saved value is the previous day + 1.
 * @throws RuntimeException not an .xlsx (zip) file
 */
function jeep_read_workbook(string $path): array {
    $names = sc_xlsx_sheets($path);
    if (!$names) throw new RuntimeException('not a readable .xlsx file');
    $out = [];
    foreach ($names as $name) {
        $full = sc_xlsx_sheet($path, $name);
        $v = $full['v']; $f = $full['f'];
        $hdr = jeep_sheet_header($v);
        if ($hdr === null) continue;
        // Saved without cached formula values (most formula cells empty): calculate them, if PhpSpreadsheet is there.
        $noVal = 0;
        foreach ($f as $ref => $x) if (!isset($v[$ref])) $noVal++;
        $uncalc = count($f) >= 10 && $noVal > 0.8 * count($f);
        if ($uncalc) {
            $ps = jeep_ps_values($path, $name);
            if ($ps !== null) { $v = $ps + $v; $f = []; $uncalc = false; $hdr = jeep_sheet_header($v) ?? $hdr; }
        }
        $s = ['sheet' => $name, 'pax' => $hdr['pax'], 'jeeps' => $hdr['jeeps'], 'cpj' => $hdr['cpj'],
              'guide' => null, 'days' => [], 'uncalculated' => $uncalc];
        $hr = null; $jc = 'D'; $prev = null;
        for ($r = 8; $r < 90; $r++) {
            $a = trim(sc_cell($v, 'A', $r));
            if (strtoupper($a) === 'DATE') {
                $hr = $r;
                foreach (['B', 'C', 'D', 'E', 'F', 'G'] as $c) if (jeep_lab(sc_cell($v, $c, $r)) === 'jeep') $jc = $c;
                continue;
            }
            if (stripos($a, 'GUIDE') === 0) {
                $g = trim(sc_cell($v, 'B', $r));
                $s['guide'] = $g !== '' ? mb_substr($g, 0, 100) : null;
            }
            if ($hr === null || $r <= $hr) continue;
            if (stripos($a, 'TOTAL') === 0) { $hr = -1; continue; }   // end of the day table (GUIDE comes after)
            if ($hr < 0) continue;
            $date = null;
            if ($a !== '' && preg_match('/^\d+(\.\d+)?$/', $a)) $date = sc_parse_xlsx_date($a);
            elseif ($a === '' && isset($f['A' . $r]) && $prev !== null) $date = date('Y-m-d', strtotime($prev . ' +1 day'));
            if ($date === null) continue;
            $prev = $date;
            $s['days'][] = [$date, jeep_num(sc_cell($v, $jc, $r))];
        }
        $out[] = $s;
    }
    return $out;
}

/** The Calc header of a sheet's values (rows 1–10), or null when the sheet has neither Number of Jeeps nor Cost per Jeep. */
function jeep_sheet_header(array $v): ?array {
    $jr = null; $cpj = null; $pax = null;
    for ($r = 1; $r <= 10; $r++) {
        $a = jeep_lab(sc_cell($v, 'A', $r));
        $c = jeep_lab(sc_cell($v, 'C', $r));
        if (strpos($a, 'number of jeep') === 0) $jr = jeep_num(sc_cell($v, 'B', $r));
        if ($a === 'tot pax' || $a === 'total pax') $pax = jeep_num(sc_cell($v, 'B', $r));
        if (strpos($c, 'cost per jeep') === 0) $cpj = jeep_num(sc_cell($v, 'D', $r));
    }
    if ($jr === null && $cpj === null) return null;
    return ['jeeps' => $jr, 'cpj' => $cpj, 'pax' => $pax];
}

/**
 * A folder's jeeps per day from its Calc sheets (jeep_read_workbook()), the prototype's rules:
 *   - group Calc (a sheet named RECAP): RECAP's days + each other sheet's days whose date is not in RECAP (client
 *     extensions, MIDT); sheets with CANC in the name ignored;
 *   - else the sheet with CONF in the name (not CANC); else the only sheet; else the sheet with most dated rows
 *     (→ status ambiguous_sheet).
 * Per day: JEEP value / Cost per Jeep; empty, text or ≤ 0 skipped, > JEEP_MAX_PER_DAY skipped and logged.
 * @return array{status:string, message:string, kind:?string, sheet:?string, jeeps_header:?float, pax:?int, cpj:?float,
 *               guide:?string, days:array<string,float>, flags:string[]}
 */
function jeep_folder_result(array $sheets, string $folderName = ''): array {
    $res = ['status' => 'ok', 'message' => '', 'kind' => null, 'sheet' => null, 'jeeps_header' => null, 'pax' => null,
            'cpj' => null, 'guide' => null, 'days' => [], 'flags' => []];
    if (!$sheets) { $res['status'] = 'no_calc'; $res['message'] = 'No Excel with a Calc header (Number of Jeeps / Cost per Jeep).'; return $res; }
    $recap = null;
    foreach ($sheets as $s) if (strtoupper(trim($s['sheet'])) === 'RECAP') { $recap = $s; break; }
    $extra = []; $covered = [];
    if ($recap) {
        $chosen = [$recap];
        foreach ($recap['days'] as $d) $covered[$d[0]] = true;
        foreach ($sheets as $s) if ($s !== $recap && stripos($s['sheet'], 'CANC') === false) $extra[] = $s;
        $res['kind'] = 'GRP';
    } else {
        $conf = array_values(array_filter($sheets, fn($s) => stripos($s['sheet'], 'CONF') !== false && stripos($s['sheet'], 'CANC') === false));
        if ($conf) $chosen = [$conf[0]];
        elseif (count($sheets) === 1) $chosen = [$sheets[0]];
        else {
            $best = $sheets[0];
            foreach ($sheets as $s) if (count($s['days']) >= count($best['days'])) $best = $s;   // ties: the last, as sorted() in the prototype
            $chosen = [$best];
            $res['status'] = 'ambiguous_sheet';
            $res['message'] = count($sheets) . ' sheets and none marked CONF (' . implode(', ', array_map(fn($s) => trim($s['sheet']), $sheets))
                            . ') — read "' . trim($best['sheet']) . '" (most days). Mark the confirmed sheet CONF.';
        }
        $res['kind'] = 'PVT';
    }
    $c0 = $chosen[0];
    $res['sheet'] = trim($c0['sheet']);
    $res['jeeps_header'] = $c0['jeeps'];
    $res['pax'] = $c0['pax'] !== null ? (int)round($c0['pax']) : null;
    $res['cpj'] = $c0['cpj'];
    $res['guide'] = $c0['guide'];

    $add = function (array $s, array $skip) use (&$res) {
        $cpj = (float)($s['cpj'] ?? 0);
        foreach ($s['days'] as [$d, $val]) {
            if (isset($skip[$d]) || $val === null || !$cpj) continue;
            $x = $val / $cpj;
            if ($x <= 0) continue;
            if ($x > JEEP_MAX_PER_DAY) { $res['flags'][] = $d . ' ' . trim($s['sheet']) . ': JEEP ' . $val . ' / ' . $cpj . ' = ' . round($x, 1) . ' jeeps — skipped'; continue; }
            $res['days'][$d] = ($res['days'][$d] ?? 0) + $x;
        }
    };
    foreach ($chosen as $s) $add($s, []);
    foreach ($extra as $s) $add($s, $covered);
    ksort($res['days']);

    $uncalc = array_filter(array_merge($chosen, $extra), fn($s) => !empty($s['uncalculated']));
    if (!$res['days'] && $uncalc) {
        $res['status'] = 'no_values';
        $res['message'] = 'The Calc was saved without formula values (and the server cannot calculate them): open it in Excel and save it.';
    } elseif (!$res['days'] && $res['status'] === 'ok' && !ck_is_other_destination($folderName)) {
        $res['status'] = 'no_values';
        $res['message'] = $c0['cpj'] ? 'No JEEP values in the day table of "' . $res['sheet'] . '" (fine for a trip with no jeep days: beach, Zanzibar only).'
                                     : 'No Cost per Jeep in "' . $res['sheet'] . '".';
    }
    if ($res['flags']) $res['message'] = trim($res['message'] . ' ' . implode('; ', array_slice($res['flags'], 0, 3)));
    return $res;
}

// ── Scan (Dropbox) ──────────────────────────────────────────────────────────

/** The parent folders whose sub-folders are read: /001_Safari + archives (incremental: this year ± 1; full: from 2024). */
function jeep_parent_dirs(bool $full, ?int $year = null): array {
    $y = $year ?? (int)date('Y');
    $years = $full ? range(JEEP_FIRST_ARCHIVE, $y + 1) : array_merge((int)date('n') <= 2 ? [$y - 1] : [], [$y, $y + 1]);
    $dirs = [CK_BASE];
    foreach ($years as $yy) $dirs[] = CK_BASE . '/00_' . $yy;
    return $dirs;
}

/**
 * Files of many folders at once (parallel list_folder): path => [['name','rev','modified'], …], [] when the folder
 * is gone, null when the listing failed (left for the next run). A paged listing falls back to dropbox_list_file_entries().
 */
function jeep_list_files_multi(string $token, array $paths, int $concurrency = 8): array {
    $out = []; $queue = array_values(array_unique($paths)); $live = [];
    $mh = curl_multi_init();
    $start = function (string $path) use ($token, $mh, &$live) {
        $ch = curl_init('https://api.dropboxapi.com/2/files/list_folder');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['path' => $path, 'recursive' => false])]);
        curl_multi_add_handle($mh, $ch);
        $live[(int)$ch] = [$ch, $path];
    };
    while ($queue && count($live) < $concurrency) $start(array_shift($queue));
    do {
        curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 1.0);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            [, $path] = $live[(int)$ch];
            unset($live[(int)$ch]);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body = (string)curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            $data = $code === 200 ? json_decode($body, true) : null;
            if ($code === 409 && stripos($body, 'not_found') !== false) {
                $out[$path] = [];
            } elseif (!is_array($data)) {
                $out[$path] = null;
            } elseif (!empty($data['has_more'])) {
                try { $out[$path] = dropbox_list_file_entries($token, $path) ?? []; } catch (Throwable $e) { $out[$path] = null; }
            } else {
                $files = [];
                foreach ($data['entries'] ?? [] as $e) {
                    if (($e['.tag'] ?? '') === 'file') $files[] = ['name' => (string)$e['name'], 'rev' => (string)($e['rev'] ?? ''), 'modified' => (string)($e['server_modified'] ?? '')];
                }
                $out[$path] = $files;
            }
            if ($queue) $start(array_shift($queue));
        }
    } while ($running || $live);
    curl_multi_close($mh);
    return $out;
}

/**
 * The Hub request of a folder: the request linked to it by Dropbox id, else the one whose folder name is it.
 * A group folder is linked only to a request whose own folder it is (never to one member of the group).
 */
function jeep_link_request(PDO $db, ?string $dropboxId, string $name, ?string $kind): ?int {
    if ($dropboxId) {
        $st = $db->prepare('SELECT id, practice_code, group_folder FROM requests WHERE dropbox_id = ? ORDER BY id');
        $st->execute([$dropboxId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) === 1 && ($kind !== 'GRP' || strcasecmp(trim((string)$rows[0]['practice_code']), $name) === 0)) return (int)$rows[0]['id'];
    }
    $st = $db->prepare('SELECT id FROM requests WHERE practice_code = ? ORDER BY id LIMIT 2');
    $st->execute([$name]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    return count($ids) === 1 ? (int)$ids[0] : null;
}

/**
 * Discover the folders of the parent dirs and keep ops_jeep_scan in step: a folder renamed or moved to an archive keeps
 * its row (matched by Dropbox id); a new folder is queued; a folder no longer in any listed dir (moved to 00_CANCELED,
 * deleted) loses its rows. Parent dirs that could not be listed are left alone.
 * @return array{seen:int, new:int, moved:int, removed:int, dirs_failed:string[]}
 */
function jeep_discover(PDO $db, string $token, array $parents): array {
    $seen = []; $listed = []; $failed = [];
    foreach ($parents as $p) {
        try {
            $entries = dropbox_list_folder_entries($token, $p);
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'not_found') === false) $failed[] = $p;
            else $listed[strtolower($p)] = true;   // an archive not created yet
            continue;
        }
        $listed[strtolower($p)] = true;
        foreach ($entries as $e) {
            if (!jeep_folder_eligible($e['name'])) continue;
            $seen[$e['id']] = ['path' => $p . '/' . $e['name'], 'name' => $e['name']];
        }
    }
    $rows = $db->query('SELECT id, dropbox_id, folder_path, kind FROM ops_jeep_scan')->fetchAll(PDO::FETCH_ASSOC);
    $byId = []; $byPath = [];
    foreach ($rows as $r) {
        if ($r['dropbox_id']) $byId[$r['dropbox_id']] = $r;
        $byPath[strtolower($r['folder_path'])] = $r;
    }
    $n = ['seen' => count($seen), 'new' => 0, 'moved' => 0, 'removed' => 0, 'dirs_failed' => $failed];
    $upd = $db->prepare('UPDATE ops_jeep_scan SET dropbox_id = ?, folder_path = ?, folder_name = ?, stage = ?, provisional = ?, request_id = ? WHERE id = ?');
    $ins = $db->prepare('INSERT INTO ops_jeep_scan (dropbox_id, folder_path, folder_name, stage, provisional, request_id, status, need_scan) VALUES (?, ?, ?, ?, ?, ?, \'ok\', 1)');
    $keep = [];
    foreach ($seen as $id => $x) {
        $old = $byId[$id] ?? $byPath[strtolower($x['path'])] ?? null;
        if ($old) {
            $keep[(int)$old['id']] = true;
            if ($old['folder_path'] !== $x['path'] || $old['dropbox_id'] !== $id) {
                $rid = jeep_link_request($db, $id, $x['name'], $old['kind']);
                $upd->execute([$id, $x['path'], $x['name'], jeep_stage($x['name']), jeep_is_provisional($x['name']) ? 1 : 0, $rid, $old['id']]);
                $db->prepare('UPDATE ops_jeep_days SET request_id = ? WHERE scan_id = ?')->execute([$rid, $old['id']]);
                $n['moved']++;
            }
        } else {
            $ins->execute([$id, $x['path'], $x['name'], jeep_stage($x['name']), jeep_is_provisional($x['name']) ? 1 : 0, jeep_link_request($db, $id, $x['name'], null)]);
            $keep[(int)$db->lastInsertId()] = true;
            $n['new']++;
        }
    }
    foreach ($rows as $r) {
        if (isset($keep[(int)$r['id']])) continue;
        if (!isset($listed[strtolower(dirname($r['folder_path']))])) continue;   // its parent was not listed this run
        $db->prepare('DELETE FROM ops_jeep_days WHERE scan_id = ?')->execute([$r['id']]);
        $db->prepare('DELETE FROM ops_jeep_scan WHERE id = ?')->execute([$r['id']]);
        $n['removed']++;
    }
    return $n;
}

/**
 * Read one folder's Calc from its file listing and save the result (days replaced as a whole, in one transaction).
 * Downloads at most 4 Excel files (in jeep_pick_order() order) until one has a Calc header.
 * @return string the new status
 */
function jeep_scan_folder(PDO $db, string $token, array $row, array $files): string {
    require_once __DIR__ . '/../../leads/includes/calc_service.php';   // calc_dbx_download()
    $pick = jeep_pick_order($files);
    $sheets = []; $used = null; $err = '';
    foreach (array_slice($pick['order'], 0, 4) as $f) {
        try {
            $dl = cp_retry(fn() => calc_dbx_download($token, $row['folder_path'] . '/' . $f['name']));
            if ($dl === null) continue;
            $tmp = tempnam(sys_get_temp_dir(), 'jeep_');
            file_put_contents($tmp, $dl['bytes']);
            try { $sheets = jeep_read_workbook($tmp); } finally { @unlink($tmp); }
            if ($sheets) { $used = $f + ['rev' => $dl['rev'] ?: ($f['rev'] ?? '')]; break; }
        } catch (Throwable $e) {
            if (jeep_transient($e)) throw $e;   // Dropbox busy: the folder stays queued for the next run
            $err = $f['name'] . ': ' . $e->getMessage();
        }
    }
    $res = jeep_folder_result($sheets, (string)$row['folder_name']);
    if (!$used) {
        if ($err !== '') { $res['status'] = 'error'; $res['message'] = mb_substr($err, 0, 400); }
        elseif ($pick['old_xls']) { $res['status'] = 'old_xls'; $res['message'] = 'Old .xls Calc (' . implode(', ', array_slice($pick['old_xls'], 0, 2)) . '): save it as .xlsx.'; }
    } elseif ($res['status'] === 'ok' && ($multi = jeep_multi_calc($pick['order']))) {
        $res['status'] = 'multi_calc';
        $res['message'] = trim('Two Calcs of different programs: ' . $multi . ' — read ' . $used['name'] . ' only. ' . $res['message']);
    }
    jeep_save_result($db, $row, $res, $used, $pick['sig']);
    return $res['status'];
}

/** Save a folder's result: the scan row + its days (replaced in one transaction). */
function jeep_save_result(PDO $db, array $row, array $res, ?array $used, string $sig): void {
    $days = $res['days'];
    $rid = jeep_link_request($db, $row['dropbox_id'] ?? null, (string)$row['folder_name'], $res['kind']);
    $mtime = null;
    if ($used && !empty($used['modified']) && ($ts = strtotime($used['modified'])) !== false) $mtime = date('Y-m-d H:i:s', $ts);
    $now = date('Y-m-d H:i:s');
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM ops_jeep_days WHERE scan_id = ?')->execute([$row['id']]);
        if ($days) {
            $ins = $db->prepare('INSERT INTO ops_jeep_days (scan_id, request_id, day, jeeps, guide) VALUES (?, ?, ?, ?, ?)');
            foreach ($days as $d => $x) $ins->execute([$row['id'], $rid, $d, round($x, 2), $res['guide']]);
        }
        $db->prepare('UPDATE ops_jeep_scan SET request_id = ?, calc_file = ?, calc_rev = ?, calc_mtime = ?, files_sig = ?, sheet = ?, kind = ?,
                        jeeps_header = ?, pax = ?, cost_per_jeep = ?, start_day = ?, end_day = ?, jeep_days = ?, guide = ?, status = ?, message = ?,
                        need_scan = 0, reader_v = ?, checked_at = ?, scanned_at = ? WHERE id = ?')
           ->execute([$rid, $used ? mb_substr($used['name'], 0, 300) : null, $used ? mb_substr((string)($used['rev'] ?? ''), 0, 64) : null, $mtime, $sig,
                      $res['sheet'] !== null ? mb_substr($res['sheet'], 0, 255) : null, $res['kind'],
                      $res['jeeps_header'] !== null && abs($res['jeeps_header']) < 1000 ? $res['jeeps_header'] : null, $res['pax'],
                      $res['cpj'] !== null && abs($res['cpj']) < 1000000 ? $res['cpj'] : null,
                      $days ? array_key_first($days) : null, $days ? array_key_last($days) : null, round(array_sum($days), 2),
                      $res['guide'], $res['status'], $res['message'] !== '' ? mb_substr($res['message'], 0, 500) : null,
                      JEEP_READER_VERSION, $now, $now, $row['id']]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * One scan run, within a time budget (seconds); resumable — the next run goes on where this one stopped.
 *   mode 'incremental'  list /001_Safari + this year's archives, then list the files of the folders not checked for
 *                       JEEP_RECHECK_HOURS (or queued / read with older rules) and re-read the Calcs that changed;
 *                       'now' => true also re-checks every /001_Safari folder at once ("Refresh now")
 *   mode 'full'         every archive from 2024, and every folder queued to be read again (full rebuild, start)
 *   mode 'continue'     no discovery: only work through the queue (the next batches of a full rebuild)
 * @return array{mode, discover?:array, checked:int, read:int, unchanged:int, failed:int, statuses:array, remaining:int, seconds:float}
 */
function jeep_scan_run(PDO $db, array $opt = []): array {
    require_once __DIR__ . '/../../leads/dropbox_helper.php';
    require_once __DIR__ . '/../../leads/includes/calc_payments.php';   // cp_retry()
    jeep_schema($db);
    $mode = in_array($opt['mode'] ?? '', ['incremental', 'full', 'continue'], true) ? $opt['mode'] : 'incremental';
    $budget = max(5, (int)($opt['budget'] ?? 50));
    $t0 = microtime(true);
    $token = cp_retry(fn() => dropbox_get_access_token());
    $out = ['mode' => $mode, 'checked' => 0, 'read' => 0, 'unchanged' => 0, 'failed' => 0, 'statuses' => []];
    if ($mode !== 'continue') {
        $out['discover'] = jeep_discover($db, $token, jeep_parent_dirs($mode === 'full'));
        if ($mode === 'full') {
            $db->exec('UPDATE ops_jeep_scan SET need_scan = 1');
            jeep_meta_set($db, 'full_started', date('Y-m-d H:i:s'));
        }
        if (!empty($opt['now'])) {
            $db->prepare("UPDATE ops_jeep_scan SET checked_at = NULL WHERE folder_path LIKE ? AND folder_path NOT LIKE ?")
               ->execute([CK_BASE . '/%', CK_BASE . '/00\\_%']);
        }
    }
    $stale = date('Y-m-d H:i:s', time() - JEEP_RECHECK_HOURS * 3600);
    $queueSql = 'FROM ops_jeep_scan WHERE need_scan = 1 OR reader_v <> ' . (int)JEEP_READER_VERSION . ' OR checked_at IS NULL OR checked_at < ?';
    $st = $db->prepare('SELECT * ' . $queueSql . ' ORDER BY need_scan DESC, checked_at IS NULL DESC, checked_at ASC, id LIMIT 600');
    $st->execute([$stale]);
    $queue = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach (array_chunk($queue, 16) as $chunk) {
        if (microtime(true) - $t0 > $budget) break;
        $lists = jeep_list_files_multi($token, array_column($chunk, 'folder_path'));
        foreach ($chunk as $row) {
            if (microtime(true) - $t0 > $budget) break 2;
            $files = $lists[$row['folder_path']] ?? null;
            if ($files === null) { $out['failed']++; continue; }
            $out['checked']++;
            $pick = jeep_pick_order($files);
            if (!$row['need_scan'] && (int)$row['reader_v'] === JEEP_READER_VERSION && $row['files_sig'] === $pick['sig']) {
                $db->prepare('UPDATE ops_jeep_scan SET checked_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $row['id']]);
                $out['unchanged']++;
                continue;
            }
            try {
                $s = jeep_scan_folder($db, $token, $row, $files);
            } catch (Throwable $e) {
                if (jeep_transient($e)) { $out['failed']++; $out['checked']--; continue; }
                $s = 'error';
                $db->prepare("UPDATE ops_jeep_scan SET status = 'error', message = ?, need_scan = 0, reader_v = ?, checked_at = ? WHERE id = ?")
                   ->execute([mb_substr($e->getMessage(), 0, 500), JEEP_READER_VERSION, date('Y-m-d H:i:s'), $row['id']]);
            }
            $out['read']++;
            $out['statuses'][$s] = ($out['statuses'][$s] ?? 0) + 1;
        }
    }
    $st = $db->prepare('SELECT COUNT(*) ' . $queueSql);
    $st->execute([date('Y-m-d H:i:s', time() - JEEP_RECHECK_HOURS * 3600)]);
    $out['remaining'] = (int)$st->fetchColumn();
    $out['seconds'] = round(microtime(true) - $t0, 1);
    jeep_meta_set($db, 'last_run', date('Y-m-d H:i:s') . ' ' . $mode . ': ' . $out['read'] . ' read, ' . $out['unchanged'] . ' unchanged, '
                                  . $out['remaining'] . ' left' . ($out['failed'] ? ', ' . $out['failed'] . ' not listed' : ''));
    return $out;
}

/** A Dropbox error worth retrying later (rate limit, 5xx, network) — as cp_retry(). */
function jeep_transient(Throwable $e): bool {
    return $e instanceof RuntimeException && (bool)preg_match('/HTTP (0|429|5\d\d)\b/', $e->getMessage());
}

/** One-line summary of a run (cron log, page message). */
function jeep_run_summary(array $r): string {
    $d = $r['discover'] ?? null;
    return $r['mode'] . ': ' . ($d ? $d['seen'] . ' folders (' . $d['new'] . ' new, ' . $d['moved'] . ' moved, ' . $d['removed'] . ' removed), ' : '')
         . $r['read'] . ' Calc read, ' . $r['unchanged'] . ' unchanged, ' . $r['remaining'] . ' to do'
         . ($r['failed'] ? ', ' . $r['failed'] . ' not listed' : '') . ' — ' . $r['seconds'] . ' s';
}

// ── Reports ─────────────────────────────────────────────────────────────────

/**
 * Jeeps per date in [$from, $to], every date present (0 when none):
 *   [['d' => Y-m-d, 'j' => jeeps, 'p' => of which provisional, 'n' => safaris on the road], …]
 * $withProv false: _PROVISIONAL / _PROGRESS folders left out.
 */
function jeep_daily(PDO $db, string $from, string $to, bool $withProv = true): array {
    jeep_schema($db);
    $st = $db->prepare('SELECT d.day, SUM(d.jeeps) AS j, SUM(CASE WHEN s.provisional = 1 THEN d.jeeps ELSE 0 END) AS p, COUNT(*) AS n
                        FROM ops_jeep_days d JOIN ops_jeep_scan s ON s.id = d.scan_id
                        WHERE d.day BETWEEN ? AND ?' . ($withProv ? '' : ' AND s.provisional = 0') . ' GROUP BY d.day');
    $st->execute([$from, $to]);
    $by = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $by[$r['day']] = $r;
    $out = [];
    for ($t = strtotime($from), $end = strtotime($to); $t <= $end; $t = strtotime('+1 day', $t)) {
        $d = date('Y-m-d', $t);
        $r = $by[$d] ?? null;
        $out[] = ['d' => $d, 'j' => $r ? round((float)$r['j'], 2) : 0.0, 'p' => $r ? round((float)$r['p'], 2) : 0.0, 'n' => $r ? (int)$r['n'] : 0];
    }
    return $out;
}

/** Percentile with linear interpolation (numpy's default) of a sorted list. */
function jeep_percentile(array $sorted, float $p): float {
    $n = count($sorted);
    if (!$n) return 0.0;
    $pos = ($n - 1) * $p;
    $lo = (int)floor($pos); $hi = (int)ceil($pos);
    return $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * ($pos - $lo);
}

/**
 * Average / median (typical day) / 90th percentile (at least, 1 day in 10) / max (+ date) / total jeep-days /
 * days with ≥ 30 jeeps of jeep_daily() rows.
 */
function jeep_stats(array $rows): array {
    $v = array_map(fn($r) => (float)$r['j'], $rows);
    $n = count($v);
    if (!$n) return ['days' => 0, 'avg' => 0, 'median' => 0, 'p90' => 0, 'max' => 0, 'max_day' => null, 'jeep_days' => 0, 'days_ge30' => 0];
    $max = -1; $maxDay = null;
    foreach ($rows as $r) if ((float)$r['j'] > $max) { $max = (float)$r['j']; $maxDay = $r['d']; }
    $s = $v; sort($s);
    return ['days' => $n, 'avg' => round(array_sum($v) / $n, 2), 'median' => round(jeep_percentile($s, 0.5), 2),
            'p90' => round(jeep_percentile($s, 0.9), 2), 'max' => round($max, 2), 'max_day' => $max > 0 ? $maxDay : null,
            'jeep_days' => round(array_sum($v), 1), 'days_ge30' => count(array_filter($v, fn($x) => $x >= 30))];
}

/** Week of the year used everywhere here: 1 = 1–7 Jan, …, 52 = 23 Dec – 31 Dec. */
function jeep_week(string $d): int {
    return min(52, intdiv((int)date('z', strtotime($d)), 7) + 1);
}

/**
 * jeep_daily() grouped by day / week / month: [{period, avg, median, p90, max, max_day, jeep_days, days}, …].
 * Periods: Y-m-d, "2026-W07" (jeep_week()), Y-m.
 */
function jeep_periods(PDO $db, string $from, string $to, string $group = 'month', bool $withProv = true): array {
    $rows = jeep_daily($db, $from, $to, $withProv);
    if ($group === 'day') {
        return array_map(fn($r) => ['period' => $r['d'], 'avg' => $r['j'], 'median' => $r['j'], 'p90' => $r['j'], 'max' => $r['j'],
                                    'max_day' => $r['d'], 'jeep_days' => $r['j'], 'days' => 1, 'provisional' => $r['p'], 'safaris' => $r['n']], $rows);
    }
    $by = [];
    foreach ($rows as $r) {
        $k = $group === 'week' ? substr($r['d'], 0, 4) . '-W' . sprintf('%02d', jeep_week($r['d'])) : substr($r['d'], 0, 7);
        $by[$k][] = $r;
    }
    $out = [];
    foreach ($by as $k => $rs) {
        $s = jeep_stats($rs);
        $out[] = ['period' => $k, 'avg' => $s['avg'], 'median' => $s['median'], 'p90' => $s['p90'], 'max' => $s['max'],
                  'max_day' => $s['max_day'], 'jeep_days' => $s['jeep_days'], 'days' => $s['days']];
    }
    return $out;
}

/** The safaris on the road one day: [{folder, folder_path, url, request_id, jeeps, pax, guide, kind, stage, provisional, start_day, end_day}]. */
function jeep_day_detail(PDO $db, string $date, bool $withProv = true): array {
    jeep_schema($db);
    $st = $db->prepare('SELECT s.id, s.folder_name, s.folder_path, s.request_id, d.jeeps, s.pax, s.guide, s.kind, s.stage, s.provisional,
                               s.start_day, s.end_day, s.jeeps_header
                        FROM ops_jeep_days d JOIN ops_jeep_scan s ON s.id = d.scan_id
                        WHERE d.day = ?' . ($withProv ? '' : ' AND s.provisional = 0') . ' ORDER BY d.jeeps DESC, s.folder_name');
    $st->execute([$date]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['folder' => $r['folder_name'], 'folder_path' => $r['folder_path'], 'url' => ck_url_from_path($r['folder_path']),
                  'request_id' => $r['request_id'] !== null ? (int)$r['request_id'] : null, 'jeeps' => round((float)$r['jeeps'], 2),
                  'jeeps_header' => $r['jeeps_header'] !== null ? (float)$r['jeeps_header'] : null,
                  'pax' => $r['pax'] !== null ? (int)$r['pax'] : null, 'guide' => $r['guide'], 'kind' => $r['kind'], 'stage' => $r['stage'],
                  'provisional' => (bool)$r['provisional'], 'start_day' => $r['start_day'], 'end_day' => $r['end_day']];
    }
    return $out;
}

/** Guides of a year (names normalised: case + trailing punctuation): [{guide, variants[], safaris, jeep_days}], most jeep-days first. */
function jeep_guides(PDO $db, int $year, bool $withProv = true): array {
    jeep_schema($db);
    $st = $db->prepare('SELECT s.id, COALESCE(s.guide, \'\') AS guide, SUM(d.jeeps) AS j FROM ops_jeep_days d JOIN ops_jeep_scan s ON s.id = d.scan_id
                        WHERE d.day BETWEEN ? AND ?' . ($withProv ? '' : ' AND s.provisional = 0') . ' GROUP BY s.id, s.guide');
    $st->execute([$year . '-01-01', $year . '-12-31']);
    $by = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = jeep_guide_key($r['guide']);
        $by[$k] ??= ['guide' => $k !== '' ? ucwords(strtolower($k)) : '', 'variants' => [], 'safaris' => 0, 'jeep_days' => 0.0];
        $by[$k]['safaris']++;
        $by[$k]['jeep_days'] += (float)$r['j'];
        if (trim($r['guide']) !== '') $by[$k]['variants'][trim($r['guide'])] = true;
    }
    $out = [];
    foreach ($by as $g) { $g['variants'] = array_keys($g['variants']); $g['jeep_days'] = round($g['jeep_days'], 1); $out[] = $g; }
    usort($out, fn($a, $b) => [$b['jeep_days'], $a['guide']] <=> [$a['jeep_days'], $b['guide']]);
    return $out;
}

/** Folders by status (Data quality): status ≠ ok, or ok with a message (skipped cells). */
function jeep_quality(PDO $db): array {
    jeep_schema($db);
    $rows = $db->query("SELECT id, folder_name, folder_path, request_id, status, message, calc_file, sheet, scanned_at, start_day, end_day
                        FROM ops_jeep_scan WHERE status <> 'ok' OR (message IS NOT NULL AND message <> '')
                        ORDER BY FIELD(status, 'error', 'no_calc', 'old_xls', 'multi_calc', 'ambiguous_sheet', 'no_values', 'ok'), folder_path")
               ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['url'] = ck_url_from_path($r['folder_path']);
        $r['request_id'] = $r['request_id'] !== null ? (int)$r['request_id'] : null;
    }
    unset($r);
    return $rows;
}

/** Scan state: folders per status, last run, queue, a full rebuild in progress. */
function jeep_scan_state(PDO $db): array {
    jeep_schema($db);
    $by = [];
    foreach ($db->query('SELECT status, COUNT(*) n FROM ops_jeep_scan GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $r) $by[$r['status']] = (int)$r['n'];
    $st = $db->prepare('SELECT COUNT(*) FROM ops_jeep_scan WHERE need_scan = 1 OR reader_v <> ' . (int)JEEP_READER_VERSION);
    $st->execute();
    return ['folders' => array_sum($by), 'by_status' => $by, 'queued' => (int)$st->fetchColumn(),
            'last_run' => jeep_meta_get($db, 'last_run'), 'full_started' => jeep_meta_get($db, 'full_started'),
            'last_scanned_at' => $db->query('SELECT MAX(scanned_at) FROM ops_jeep_scan')->fetchColumn() ?: null];
}

/**
 * Everything the page shows, for the years that have data (2024 → next year): per year the summary (current year:
 * days up to yesterday + a separate "booked" for today → 31 Dec), months, weeks, the "days with at least N jeeps"
 * curve, the daily series; the guides of $guideYear; Data quality; the scan state.
 */
function jeep_page_data(PDO $db, bool $withProv = true, ?int $guideYear = null): array {
    jeep_schema($db);
    $today = date('Y-m-d');
    $cy = (int)date('Y');
    $mm = $db->query('SELECT MIN(day), MAX(day) FROM ops_jeep_days')->fetch(PDO::FETCH_NUM);
    $y0 = $mm && $mm[0] ? max(JEEP_FIRST_ARCHIVE, (int)substr($mm[0], 0, 4)) : $cy;
    $y1 = $mm && $mm[1] ? min($cy + 1, (int)substr($mm[1], 0, 4)) : $cy;
    $y1 = max($y1, $cy);
    $all = jeep_daily($db, $y0 . '-01-01', $y1 . '-12-31', $withProv);
    $years = [];
    foreach (range($y0, $y1) as $y) {
        $rows = array_values(array_filter($all, fn($r) => (int)substr($r['d'], 0, 4) === $y));
        $past = array_values(array_filter($rows, fn($r) => $r['d'] < $today));
        $future = array_values(array_filter($rows, fn($r) => $r['d'] >= $today));
        $window = $y < $cy ? $rows : ($y === $cy ? $past : $rows);   // summary / curve: actual days; next year: booked so far
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $mr = array_values(array_filter($rows, fn($r) => (int)substr($r['d'], 5, 2) === $m));
            $s = jeep_stats($mr);
            $months[] = ['m' => $m, 'avg' => $s['avg'], 'median' => $s['median'], 'max' => $s['max'], 'max_day' => $s['max_day'],
                         'jeep_days' => $s['jeep_days'], 'booked' => $mr && end($mr)['d'] >= $today];
        }
        $wk = [];
        foreach ($rows as $r) $wk[jeep_week($r['d'])][] = $r;
        $weeks = [];
        for ($w = 1; $w <= 52; $w++) {
            $s = jeep_stats($wk[$w] ?? []);
            $weeks[] = ['w' => $w, 'avg' => $s['avg'], 'max' => $s['max'], 'booked' => isset($wk[$w]) && end($wk[$w])['d'] >= $today];
        }
        $curve = [];
        for ($k = 1; $k <= JEEP_CURVE_MAX; $k++) $curve[] = count(array_filter($window, fn($r) => $r['j'] >= $k));
        $years[] = ['year' => $y, 'summary' => jeep_stats($window), 'booked' => $y === $cy ? jeep_stats($future) : null,
                    'window' => $y < $cy ? 'full' : ($y === $cy ? 'to_yesterday' : 'booked'),
                    'months' => $months, 'weeks' => $weeks, 'curve' => $curve];
    }
    $guideYear = $guideYear ?: $cy;
    return [
        'today' => $today, 'include_provisional' => $withProv,
        'years' => $years,
        'daily' => ['from' => $y0 . '-01-01', 'j' => array_column($all, 'j'), 'p' => array_column($all, 'p'), 'n' => array_column($all, 'n')],
        'guides' => ['year' => $guideYear, 'rows' => jeep_guides($db, $guideYear, $withProv)],
        'quality' => jeep_quality($db),
        'scan' => jeep_scan_state($db),
    ];
}

/** CSV text: 'daily' (date, jeeps, provisional, safaris) or 'folders' (one row per folder). */
function jeep_csv(PDO $db, string $what, bool $withProv = true): string {
    $fh = fopen('php://temp', 'w+');
    fwrite($fh, "\xEF\xBB\xBF");   // Excel: UTF-8
    if ($what === 'folders') {
        fputcsv($fh, ['folder', 'path', 'request_id', 'kind', 'stage', 'provisional', 'start', 'end', 'jeeps_header', 'pax', 'jeep_days', 'guide', 'status', 'message', 'calc_file', 'sheet']);
        $rows = $db->query('SELECT * FROM ops_jeep_scan ORDER BY start_day IS NULL, start_day, folder_name')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (!$withProv && $r['provisional']) continue;
            fputcsv($fh, [$r['folder_name'], $r['folder_path'], $r['request_id'], $r['kind'], $r['stage'], $r['provisional'], $r['start_day'], $r['end_day'],
                          $r['jeeps_header'], $r['pax'], $r['jeep_days'], $r['guide'], $r['status'], $r['message'], $r['calc_file'], $r['sheet']]);
        }
    } else {
        fputcsv($fh, ['date', 'jeeps', 'provisional', 'safaris']);
        $mm = $db->query('SELECT MIN(day), MAX(day) FROM ops_jeep_days')->fetch(PDO::FETCH_NUM);
        if ($mm && $mm[0]) foreach (jeep_daily($db, $mm[0], $mm[1], $withProv) as $r) fputcsv($fh, [$r['d'], $r['j'], $r['p'], $r['n']]);
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return $csv;
}
