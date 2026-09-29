<?php
/**
 * safari_check.php — lightweight pre-confirmation QC for the Hub "Confirm Safari".
 *
 * A dependency-free (ZipArchive + DOM) port of the essential checks from the
 * standalone `safariagent` QC tool (v1.0 subset — no Word programme, no invoice):
 *
 *   1. The trip dates written in the safari calc Excel are in the future.
 *   2. Those Excel dates agree with the Start / End dates being confirmed.
 *   3. The ARRIVAL flight details in the Excel land on (around) the Start date.
 *   4. The DEPARTURE flight details in the Excel leave on (around) the End date.
 *      Booking-system flight lines are read exactly (sc_gds_check, the rules of
 *      safariagent gds.py); free text falls back to "a date near Start / End".
 *
 * All checks are advisory: they never block a confirmation, they only surface
 * warnings for a human to judge. The Excel is the per-booking "*_Calc.xlsx".
 *
 * Nothing here echoes output or touches the DB; callers own that.
 */

// ─────────────────────────────────────────────────────────────────────────────
//  Minimal XLSX reader (self-contained — mirrors modules/iti voucher_lib.php)
// ─────────────────────────────────────────────────────────────────────────────

/** Split a cell ref "AB12" → ['AB', 12]. */
function sc_ref_split(string $ref): array {
    preg_match('/^([A-Z]+)(\d+)$/', strtoupper($ref), $m);
    return [$m[1] ?? '', (int)($m[2] ?? 0)];
}

/**
 * Read one worksheet into a coordinate→value map, e.g. ['A17' => '46388'].
 * Date/number cells come back as their raw stored value (Excel serial for dates);
 * use sc_parse_xlsx_date() to interpret them. $sheetName null → first sheet.
 * @return array<string,string>
 */
function sc_xlsx_cells(string $path, ?string $sheetName = null): array {
    $full = sc_xlsx_sheet($path, $sheetName);
    return $full['v'];
}

/**
 * Like sc_xlsx_cells() but also returns the formulas:
 *   ['v' => ref→cached value (non-empty only), 'f' => ref→formula text ('=' + text;
 *    '=(shared)' for a shared-formula child whose text lives on the master cell)].
 * A formula cell with no cached value appears in 'f' but not in 'v'.
 */
function sc_xlsx_sheet(string $path, ?string $sheetName = null): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Cannot open Excel file.');
    $read = function (string $name) use ($zip) { return $zip->getFromName($name); };

    // Shared strings.
    $shared = [];
    $ssXml = $read('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML($ssXml);
        libxml_clear_errors();
        foreach ($dom->getElementsByTagName('si') as $si) {
            $buf = '';
            foreach ($si->getElementsByTagName('t') as $t) $buf .= $t->textContent;
            $shared[] = $buf;
        }
    }

    // Resolve sheet name → worksheet path (fall back to sheet1).
    $wbXml   = $read('xl/workbook.xml');
    $relsXml = $read('xl/_rels/workbook.xml.rels');
    $target  = 'xl/worksheets/sheet1.xml';
    if ($wbXml !== false && $relsXml !== false) {
        $nameToRid = [];
        if (preg_match_all('/<sheet\b[^>]*\/?>/', $wbXml, $m)) {
            foreach ($m[0] as $tag) {
                $nm  = preg_match('/name="([^"]*)"/', $tag, $a) ? html_entity_decode($a[1], ENT_QUOTES | ENT_XML1, 'UTF-8') : '';
                $rid = preg_match('/r:id="([^"]*)"/', $tag, $b) ? $b[1] : '';
                if ($nm !== '' && $rid !== '') $nameToRid[$nm] = $rid;
            }
        }
        $ridToTarget = [];
        if (preg_match_all('/<Relationship\b[^>]*\/?>/', $relsXml, $m)) {
            foreach ($m[0] as $tag) {
                $id  = preg_match('/Id="([^"]*)"/', $tag, $a) ? $a[1] : '';
                $tgt = preg_match('/Target="([^"]*)"/', $tag, $b) ? $b[1] : '';
                if ($id !== '' && $tgt !== '') $ridToTarget[$id] = $tgt;
            }
        }
        $pick = null;
        if ($sheetName !== null && isset($nameToRid[$sheetName])) $pick = $nameToRid[$sheetName];
        elseif ($sheetName === null && $nameToRid)                $pick = reset($nameToRid);
        if ($pick && isset($ridToTarget[$pick])) {
            $tgt = ltrim($ridToTarget[$pick], '/');
            $target = (strpos($tgt, 'xl/') === 0) ? $tgt : 'xl/' . $tgt;
        }
    }

    $sheetXml = $read($target);
    $zip->close();
    if ($sheetXml === false) return ['v' => [], 'f' => []];

    $cells = []; $formulas = [];
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadXML($sheetXml);
    libxml_clear_errors();
    foreach ($dom->getElementsByTagName('c') as $c) {
        $ref = $c->getAttribute('r');
        if ($ref === '') continue;
        $type = $c->getAttribute('t');
        $val  = '';
        $vNode = null; $fNode = null;
        foreach ($c->childNodes as $ch) {
            if ($ch->nodeType !== XML_ELEMENT_NODE) continue;
            if ($ch->localName === 'v' && !$vNode) $vNode = $ch;
            if ($ch->localName === 'f' && !$fNode) $fNode = $ch;
        }
        if ($fNode) {
            $ft = trim($fNode->textContent);
            $formulas[strtoupper($ref)] = '=' . ($ft !== '' ? $ft : '(shared)');
        }
        if ($type === 'inlineStr') {
            foreach ($c->getElementsByTagName('t') as $t) $val .= $t->textContent;
        } else {
            $raw = $vNode ? $vNode->textContent : '';
            if ($type === 's') { $val = $shared[(int)$raw] ?? ''; }
            else               { $val = $raw; }
        }
        $val = trim($val);
        if ($val !== '') $cells[strtoupper($ref)] = $val;
    }
    return ['v' => $cells, 'f' => $formulas];
}

/** Value at column letter + row from a cells map, '' if absent. */
function sc_cell(array $cells, string $col, int $row): string {
    return $cells[strtoupper($col) . $row] ?? '';
}

/** Worksheet names in workbook order. */
function sc_xlsx_sheets(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [];
    $wb = $zip->getFromName('xl/workbook.xml');
    $zip->close();
    if ($wb === false) return [];
    $names = [];
    if (preg_match_all('/<sheet\b[^>]*\/?>/', $wb, $m)) {
        foreach ($m[0] as $tag) {
            if (preg_match('/name="([^"]*)"/', $tag, $mm)) {
                $names[] = html_entity_decode($mm[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
    }
    return $names;
}

/** True if any worksheet name contains "RECAP" — the mark of a GROUP (GRP) calc. */
function sc_has_recap(array $sheetNames): bool {
    foreach ($sheetNames as $n) { if (stripos($n, 'recap') !== false) return true; }
    return false;
}

/** Excel serial or text date → 'Y-m-d', or null. */
function sc_parse_xlsx_date(string $v): ?string {
    $v = trim($v);
    if ($v === '') return null;
    // Excel serial (bare number in the modern-date range).
    if (preg_match('/^\d+(?:\.\d+)?$/', $v)) {
        $n = (int)floor((float)$v);
        if ($n >= 20000 && $n <= 80000) {
            $d = new DateTime('1899-12-30');
            $d->modify('+' . $n . ' days');
            return $d->format('Y-m-d');
        }
        return null;
    }
    $ts = strtotime($v);
    if ($ts === false) $ts = strtotime(str_replace('/', '-', $v));
    return $ts !== false ? date('Y-m-d', $ts) : null;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Extraction: itinerary dates + flight blocks
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The day-by-day itinerary dates from the calc sheet's DATE column.
 * Finds the "DATE" table header, then reads the contiguous run of dated rows.
 * @return array{start:?string, end:?string, all:string[]}  (Y-m-d)
 */
function sc_trip_dates(array $cells): array {
    $dateCol = null; $headerRow = 0;
    foreach ($cells as $ref => $val) {
        if (strcasecmp(trim($val), 'DATE') === 0) {
            [$c, $r] = sc_ref_split($ref);
            if ($c !== '') { $dateCol = $c; $headerRow = $r; break; }
        }
    }
    if ($dateCol === null) { $dateCol = 'A'; $headerRow = 16; }

    $dates = [];
    for ($r = $headerRow + 1, $misses = 0; $r < $headerRow + 60; $r++) {
        $raw = sc_cell($cells, $dateCol, $r);
        if ($raw !== '' && stripos($raw, 'total') === 0) break;
        $d = ($raw !== '') ? sc_parse_xlsx_date($raw) : null;
        if ($d !== null) { $dates[] = $d; $misses = 0; }
        elseif ($dates) { if (++$misses >= 2) break; }   // tolerate a single blank line inside the table
    }
    sort($dates);
    return [
        'start' => $dates[0] ?? null,
        'end'   => $dates ? end($dates) : null,
        'all'   => $dates,
    ];
}

/**
 * Free-text ARRIVAL / DEPARTURE flight blocks. Reads only the left-hand
 * columns (A–D) so unrelated notes kept in later columns are ignored.
 * @return array{arrival:string, departure:string}
 */
function sc_flight_blocks(array $cells): array {
    $arrRow = 0; $depRow = 0;
    foreach ($cells as $ref => $val) {
        $u = strtoupper($val);
        if (strpos($u, 'DETAIL') === false) continue;
        [$c, $r] = sc_ref_split($ref);
        if     ($arrRow === 0 && strpos($u, 'ARRIVAL')   !== false) $arrRow = $r;
        elseif ($depRow === 0 && strpos($u, 'DEPARTURE') !== false) $depRow = $r;
    }

    $gather = function (int $lo, int $hi) use ($cells): string {
        $out = [];
        for ($r = $lo; $r <= $hi; $r++) {
            $line = '';
            foreach (['A', 'B', 'C', 'D'] as $col) {
                $t = sc_cell($cells, $col, $r);
                if ($t !== '') $line .= ' ' . $t;
            }
            $line = trim($line);
            if ($line !== '') $out[] = $line;
        }
        return implode("\n", $out);
    };

    $arrival   = '';
    $departure = '';
    if ($arrRow > 0) {
        $end = ($depRow > $arrRow) ? $depRow - 1 : $arrRow + 12;
        $arrival = $gather($arrRow + 1, $end);
    }
    if ($depRow > 0) {
        $departure = $gather($depRow + 1, $depRow + 15);
    }
    return ['arrival' => $arrival, 'departure' => $departure];
}

/**
 * Extract candidate dates from free flight text, resolving the year to the one
 * closest to $anchor (a Y-m-d). Handles: "31DEC", "03 JAN", "4TH JAN",
 * "2/01/2027", "15-01-2027".
 * @return string[]  distinct Y-m-d, sorted
 */
function sc_dates_in_text(string $text, string $anchor): array {
    static $mon = ['JAN'=>1,'FEB'=>2,'MAR'=>3,'APR'=>4,'MAY'=>5,'JUN'=>6,
                   'JUL'=>7,'AUG'=>8,'SEP'=>9,'OCT'=>10,'NOV'=>11,'DEC'=>12];
    $anchorTs = strtotime($anchor) ?: time();
    $found = [];

    $pushMD = function (int $day, int $m) use (&$found, $anchorTs) {
        if ($day < 1 || $day > 31 || $m < 1 || $m > 12) return;
        $anchorYr = (int)date('Y', $anchorTs);
        $best = null; $bestDiff = PHP_INT_MAX;
        foreach ([$anchorYr - 1, $anchorYr, $anchorYr + 1] as $yr) {
            $ts = mktime(0, 0, 0, $m, $day, $yr);
            if ($ts === false) continue;
            $diff = abs($ts - $anchorTs);
            if ($diff < $bestDiff) { $bestDiff = $diff; $best = date('Y-m-d', $ts); }
        }
        if ($best !== null) $found[$best] = true;
    };

    $up = strtoupper($text);

    // DD MON  (optional ordinal / spaces): 31DEC, 4TH JAN, 03 JAN
    if (preg_match_all('/\b(\d{1,2})\s*(?:ST|ND|RD|TH)?\s*([A-Z]{3})\b/', $up, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $g) {
            if (isset($mon[$g[2]])) $pushMD((int)$g[1], $mon[$g[2]]);
        }
    }
    // DD/MM/YYYY or DD-MM-YYYY (European order)
    if (preg_match_all('/\b(\d{1,2})[\/\.\-](\d{1,2})[\/\.\-](\d{2,4})\b/', $up, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $g) {
            $day = (int)$g[1]; $m = (int)$g[2]; $yr = (int)$g[3];
            if ($yr < 100) $yr += 2000;
            if ($day >= 1 && $day <= 31 && $m >= 1 && $m <= 12) {
                $ts = mktime(0, 0, 0, $m, $day, $yr);
                if ($ts !== false) $found[date('Y-m-d', $ts)] = true;
            }
        }
    }

    $out = array_keys($found);
    sort($out);
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Flight lines in booking-system (GDS / Amadeus) format — port of the
//  safariagent gds.py rules, so the Hub and SafariCheck judge a flight alike:
//    "2 . AF  874 R  06DEC CDGJRO HK4  1035  2125"   "8 TK 566 V 01FEB 1 ISTJRO HK6 1710 0015+1"
//  Arrival = the flight entering the trip area, departure = the one leaving it;
//  OK on the Start / End day, or the next day before 06:00 (night flight).
// ─────────────────────────────────────────────────────────────────────────────

const SC_AREA = ['JRO','ARK','ZNZ','DAR','SEU','MYW','MWZ','TKQ','LKY','MFA','PMA','TBO','IRI',
                 'NBO','WIL','MBA','MYD','LAU','UKA','KGL','EBB',
                 'JNB','CPT','WDH','MUB','BBK','VFA','LVI','TNR','NOS','DIE'];
const SC_NIGHT_LIMIT = '06:00';

/**
 * Flight segments in the text (other lines ignored); the year of each DDMON is
 * the one closest to $anchor (Y-m-d).
 * @return array<int,array{flight:string,org:string,dst:string,dep:string,dep_time:string,arr:string,arr_time:string}>
 */
function sc_gds_segments(string $text, string $anchor): array {
    static $mon = ['JAN'=>1,'FEB'=>2,'MAR'=>3,'APR'=>4,'MAY'=>5,'JUN'=>6,
                   'JUL'=>7,'AUG'=>8,'SEP'=>9,'OCT'=>10,'NOV'=>11,'DEC'=>12];
    $head = '/(?<![A-Z0-9])([A-Z][A-Z0-9]|[0-9][A-Z])\s*(\d{1,4})\s+(?:[A-Z]\s+)?(\d{1,2})('
          . implode('|', array_keys($mon)) . ')\s+(?:\d\s+)?([A-Z]{3})([A-Z]{3})\b(?:\s*\*?[A-Z]{2}\d{1,2}\b)?/';
    $segs = [];
    foreach (preg_split('/\R/', $text) as $raw) {
        $line = strtoupper(str_replace("\xC2\xA0", ' ', $raw));
        if (!preg_match_all($head, $line, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) continue;
        foreach ($mm as $i => $m) {
            $from = $m[0][1] + strlen($m[0][0]);
            $to   = isset($mm[$i + 1]) ? $mm[$i + 1][0][1] : strlen($line);
            preg_match_all('/(?<![\d.])([#+]?)(\d{4})(\+\d)?(?![\d.])/', substr($line, $from, $to - $from), $tt, PREG_SET_ORDER);
            if (count($tt) < 2) continue;
            $d = $tt[count($tt) - 2]; $a = $tt[count($tt) - 1];
            if ((int)substr($d[2], 0, 2) > 23 || (int)substr($d[2], 2) > 59
             || (int)substr($a[2], 0, 2) > 23 || (int)substr($a[2], 2) > 59) continue;
            $dates = sc_dates_in_text($m[3][0] . $m[4][0], $anchor);
            if (!$dates) continue;
            $depT = substr($d[2], 0, 2) . ':' . substr($d[2], 2);
            $arrT = substr($a[2], 0, 2) . ':' . substr($a[2], 2);
            if (!empty($a[3]))      $days = (int)substr($a[3], 1);
            elseif ($a[1] === '#')  $days = 1;                       // Amadeus: arrives next day
            else                    $days = $arrT < $depT ? 1 : 0;
            $segs[] = [
                'flight' => $m[1][0] . ' ' . (ltrim($m[2][0], '0') ?: '0'),
                'org' => $m[5][0], 'dst' => $m[6][0],
                'dep' => $dates[0], 'dep_time' => $depT,
                'arr' => date('Y-m-d', strtotime($dates[0] . ' +' . $days . ' days')), 'arr_time' => $arrT,
            ];
        }
    }
    return $segs;
}

/** $b continues $a's journey (same airport, within a day). */
function sc_gds_connects(array $a, array $b): bool {
    $g = sc_days_between($a['arr'], $b['dep']);
    return $b['org'] === $a['dst'] && $g !== null && $g >= 0 && $g <= 1;
}

/** The arrival segment: first entering the area, followed through connections inside it. */
function sc_gds_arrival(array $segs): ?array {
    foreach ($segs as $i => $s) {
        if (!in_array($s['dst'], SC_AREA, true) || in_array($s['org'], SC_AREA, true)) continue;
        $last = $s;
        for ($j = $i + 1; $j < count($segs); $j++) {
            if (!in_array($segs[$j]['dst'], SC_AREA, true) || !sc_gds_connects($last, $segs[$j])) break;
            $last = $segs[$j];
        }
        return $last;
    }
    return null;
}

/** The departure segment: first leaving the area, walked back through connections inside it. */
function sc_gds_departure(array $segs): ?array {
    foreach ($segs as $i => $s) {
        if (!in_array($s['org'], SC_AREA, true) || in_array($s['dst'], SC_AREA, true)) continue;
        $first = $s;
        for ($j = $i - 1; $j >= 0; $j--) {
            if (!in_array($segs[$j]['org'], SC_AREA, true) || !sc_gds_connects($segs[$j], $first)) break;
            $first = $segs[$j];
        }
        return $first;
    }
    return null;
}

/**
 * Check the ARRIVAL ($arrival=true, vs $day = Start) or DEPARTURE (vs End) block
 * when it has booking-system lines: a check row, or null to fall back.
 */
function sc_gds_check(string $text, string $day, bool $arrival): ?array {
    $segs = sc_gds_segments($text, $day);
    $s = $arrival ? sc_gds_arrival($segs) : sc_gds_departure($segs);
    if ($s === null) return null;
    $d = $arrival ? $s['arr'] : $s['dep'];
    $t = $arrival ? $s['arr_time'] : $s['dep_time'];
    $what = $s['flight'] . ' ' . $s['org'] . '→' . $s['dst'] . ($arrival ? ' lands ' : ' leaves ') . sc_fmt($d) . ' ' . $t;
    $label = $arrival ? 'Start' : 'End';
    $g = sc_days_between($day, $d);
    if ($g === 0) return ['level' => 'ok', 'msg' => $what . ' — on the ' . $label . ' date.'];
    if ($g === 1 && $t < SC_NIGHT_LIMIT) {
        return ['level' => 'ok', 'msg' => $what . ' — night ' . ($arrival ? 'arrival' : 'departure') . ' after the ' . $label . ' date ' . sc_fmt($day) . '.'];
    }
    return ['level' => 'warn', 'msg' => $what . ' — but the ' . $label . ' date is ' . sc_fmt($day) . '.'];
}

// ─────────────────────────────────────────────────────────────────────────────
//  Orchestration
// ─────────────────────────────────────────────────────────────────────────────

/** Whole days between two Y-m-d (b - a), or null. */
function sc_days_between(?string $a, ?string $b): ?int {
    if (!$a || !$b) return null;
    $ta = strtotime($a); $tb = strtotime($b);
    if ($ta === false || $tb === false) return null;
    return (int)round(($tb - $ta) / 86400);
}

/** Pretty "01 Jan 2027" from Y-m-d. */
function sc_fmt(?string $ymd): string {
    if (!$ymd) return '—';
    $ts = strtotime($ymd);
    return $ts !== false ? date('d M Y', $ts) : $ymd;
}

/**
 * Run the v1.0 checks.
 * @param array $ctx  ['xlsx_path'=>?string, 'start'=>?Y-m-d, 'end'=>?Y-m-d, 'today'=>?Y-m-d]
 * @return array<int,array{level:string,msg:string}>  level: ok|warn|info
 */
function sc_run_checks(array $ctx): array {
    $start = $ctx['start'] ?? null;
    $end   = $ctx['end']   ?? null;
    $today = $ctx['today'] ?? date('Y-m-d');
    $path  = $ctx['xlsx_path'] ?? null;
    $res   = [];

    if (!$path || !is_file($path)) {
        $res[] = ['level' => 'info', 'msg' => 'No “*_Calc.xlsx” found in the folder — Excel checks skipped.'];
        return $res;
    }

    try {
        $cells = sc_xlsx_cells($path);
    } catch (Throwable $e) {
        $res[] = ['level' => 'info', 'msg' => 'Could not read the Excel (' . $e->getMessage() . ') — Excel checks skipped.'];
        return $res;
    }
    if (!$cells) {
        $res[] = ['level' => 'info', 'msg' => 'The Excel appears empty — Excel checks skipped.'];
        return $res;
    }

    // RECAP tab → this is a GROUP calc. Cross-check against the chosen booking type.
    $grpAction = strtoupper($ctx['grp_action'] ?? 'NONE');
    $hasRecap  = sc_has_recap(sc_xlsx_sheets($path));
    if ($hasRecap) {
        if ($grpAction === 'NONE') {
            $res[] = ['level' => 'warn', 'msg' => 'Excel has a RECAP tab → this is a GROUP booking, but you are confirming it as Private. Use Create / Add to GRP.'];
        } else {
            $res[] = ['level' => 'ok', 'msg' => 'Excel has a RECAP tab → confirmed as a GROUP booking.'];
        }
    } elseif ($grpAction !== 'NONE') {
        $res[] = ['level' => 'warn', 'msg' => 'No RECAP tab in the Excel — confirm this really is a group before creating/adding a GRP.'];
    }

    $trip = sc_trip_dates($cells);

    // 1) Excel trip dates are in the future.
    if ($trip['start'] === null) {
        $res[] = ['level' => 'info', 'msg' => 'Could not locate the itinerary DATE column in the Excel.'];
    } elseif ($trip['start'] < $today) {
        $res[] = ['level' => 'warn', 'msg' => 'Excel trip starts ' . sc_fmt($trip['start']) . ' — that is in the past.'];
    } elseif ($trip['end'] !== null && $trip['end'] < $today) {
        $res[] = ['level' => 'warn', 'msg' => 'Excel trip ends ' . sc_fmt($trip['end']) . ' — that is in the past.'];
    } else {
        $res[] = ['level' => 'ok', 'msg' => 'Excel trip dates are in the future ('
                 . sc_fmt($trip['start']) . ' → ' . sc_fmt($trip['end']) . ').'];
    }

    // 2) Excel dates agree with the Start / End being confirmed.
    if ($trip['start'] !== null && $start) {
        if ($trip['start'] === $start) {
            $res[] = ['level' => 'ok', 'msg' => 'Excel start matches the folder Start date (' . sc_fmt($start) . ').'];
        } else {
            $res[] = ['level' => 'warn', 'msg' => 'Excel start ' . sc_fmt($trip['start'])
                     . ' ≠ folder Start ' . sc_fmt($start) . '.'];
        }
    }
    if ($trip['end'] !== null && $end) {
        if ($trip['end'] === $end) {
            $res[] = ['level' => 'ok', 'msg' => 'Excel end matches the folder End date (' . sc_fmt($end) . ').'];
        } else {
            $res[] = ['level' => 'warn', 'msg' => 'Excel end ' . sc_fmt($trip['end'])
                     . ' ≠ folder End ' . sc_fmt($end) . '.'];
        }
    }

    // 2b) GRP code (DDMM) coherence vs the Excel trip start (RECAP tab on a group).
    // ±1 day is tolerated: the group code often sits a day off the itinerary's first
    // row (pre-night / arrival day). Only a ≥2-day gap is flagged.
    $grpCode = preg_replace('/\D/', '', (string)($ctx['grp_code'] ?? ''));
    if ($grpAction !== 'NONE' && strlen($grpCode) === 4 && $trip['start'] !== null) {
        $codeDay  = (int)substr($grpCode, 0, 2);
        $codeMon  = (int)substr($grpCode, 2);
        $anchorTs = strtotime($trip['start']);
        $anchorYr = (int)date('Y', $anchorTs);
        $bestDiff = null;
        foreach ([$anchorYr - 1, $anchorYr, $anchorYr + 1] as $yr) {
            $ct = mktime(0, 0, 0, $codeMon, $codeDay, $yr);
            if ($ct === false) continue;
            $d = (int)round(abs($ct - $anchorTs) / 86400);
            if ($bestDiff === null || $d < $bestDiff) $bestDiff = $d;
        }
        if ($bestDiff === 0) {
            $res[] = ['level' => 'ok', 'msg' => 'GRP code ' . $grpCode . ' matches the Excel start (' . sc_fmt($trip['start']) . ').'];
        } elseif ($bestDiff !== null && $bestDiff <= 1) {
            $res[] = ['level' => 'ok', 'msg' => 'GRP code ' . $grpCode . ' is within 1 day of the Excel start (' . sc_fmt($trip['start']) . ').'];
        } else {
            $res[] = ['level' => 'warn', 'msg' => 'GRP code ' . $grpCode . ' is ' . ($bestDiff ?? '?')
                     . ' days off the Excel start ' . sc_fmt($trip['start']) . ' — check the code or the Excel.'];
        }
    }

    // 3 & 4) Flight details around Start / End.
    $blocks = sc_flight_blocks($cells);

    if ($start) {
        if (trim($blocks['arrival']) === '') {
            $res[] = ['level' => 'info', 'msg' => 'No ARRIVAL flight details filled in the Excel.'];
        } elseif ($g = sc_gds_check($blocks['arrival'], $start, true)) {
            $res[] = $g;
        } else {
            $ad = sc_dates_in_text($blocks['arrival'], $start);
            $hit = false;
            foreach ($ad as $d) { $g = sc_days_between($start, $d); if ($g !== null && $g >= -1 && $g <= 0) { $hit = true; break; } }
            if ($hit) {
                $res[] = ['level' => 'ok', 'msg' => 'Arrival flight matches the Start date (' . sc_fmt($start) . ').'];
            } else {
                $res[] = ['level' => 'warn', 'msg' => 'Arrival flight date'
                         . ($ad ? 's ' . implode(', ', array_map('sc_fmt', $ad)) : ' not found')
                         . ' — expected on/just before Start ' . sc_fmt($start) . '.'];
            }
        }
    }
    if ($end) {
        if (trim($blocks['departure']) === '') {
            $res[] = ['level' => 'info', 'msg' => 'No DEPARTURE flight details filled in the Excel.'];
        } elseif ($g = sc_gds_check($blocks['departure'], $end, false)) {
            $res[] = $g;
        } else {
            $dd = sc_dates_in_text($blocks['departure'], $end);
            $hit = false;
            foreach ($dd as $d) { $g = sc_days_between($end, $d); if ($g !== null && $g >= 0 && $g <= 1) { $hit = true; break; } }
            if ($hit) {
                $res[] = ['level' => 'ok', 'msg' => 'Departure flight matches the End date (' . sc_fmt($end) . ').'];
            } else {
                $res[] = ['level' => 'warn', 'msg' => 'Departure flight date'
                         . ($dd ? 's ' . implode(', ', array_map('sc_fmt', $dd)) : ' not found')
                         . ' — expected on/just after End ' . sc_fmt($end) . '.'];
            }
        }
    }

    return $res;
}

/**
 * Best-effort: locate & download the booking's "*_Calc.xlsx" to a temp file.
 * Returns the local path (caller unlinks) or null. Requires dropbox_helper.php
 * loaded and a valid $token; $folderPath is the Dropbox folder (e.g. /2026/Foo).
 */
function sc_fetch_calc_xlsx(string $token, string $folderPath): ?string {
    if (!function_exists('dropbox_list_files') || !function_exists('dropbox_download_text')) return null;
    try {
        $files = dropbox_list_files($token, $folderPath);
    } catch (Throwable $e) { return null; }

    // Prefer a top-level "*_Calc.xlsx"; if several, the highest leading number.
    $cands = [];
    foreach ($files as $name) {
        if (preg_match('/_calc\.xlsx$/i', $name)) $cands[] = $name;
    }
    if (!$cands) {
        foreach ($files as $name) { if (preg_match('/\.xlsx$/i', $name) && stripos($name, '~$') !== 0) $cands[] = $name; }
    }
    if (!$cands) return null;
    rsort($cands, SORT_NATURAL | SORT_FLAG_CASE);
    $pick = $cands[0];

    try {
        $bytes = dropbox_download_text($token, rtrim($folderPath, '/') . '/' . $pick);
    } catch (Throwable $e) { return null; }
    if ($bytes === null || $bytes === '') return null;

    $tmp = tempnam(sys_get_temp_dir(), 'safcalc_');
    if ($tmp === false) return null;
    if (file_put_contents($tmp, $bytes) === false) { @unlink($tmp); return null; }
    return $tmp;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Calc house rules (Roberto's rules — see docs/AGENT_API.md, fill_calc)
// ─────────────────────────────────────────────────────────────────────────────

/** 'A1'-style ref for column letter + row. */
function sc_ref(string $col, int $row): string { return strtoupper($col) . $row; }

/**
 * Itinerary day rows of a calc sheet: rows between the DATE header and the
 * "Totals" row whose DATE cell holds a date or a formula (the A18+ cascade).
 * Each: ['row'=>int, 'date'=>Y-m-d|null (cached, else A17 + offset)].
 */
function sc_calc_day_rows(array $v, array $f): array {
    $hdr = 16;
    foreach ($v as $ref => $val) {
        if (strcasecmp(trim($val), 'DATE') === 0) { list($c, $r) = sc_ref_split($ref); if ($c === 'A') { $hdr = $r; break; } }
    }
    $rows = [];
    $first = null;
    for ($r = $hdr + 1; $r < $hdr + 40; $r++) {
        $a = sc_cell($v, 'A', $r);
        if ($a !== '' && stripos($a, 'total') === 0) break;
        $isFormula = isset($f[sc_ref('A', $r)]);
        $d = $a !== '' ? sc_parse_xlsx_date($a) : null;
        if ($d === null && !$isFormula) continue;
        if ($first === null && $d !== null) $first = ['row' => $r, 'date' => $d];
        if ($d === null && $first !== null) {
            $d = date('Y-m-d', strtotime($first['date'] . ' +' . ($r - $first['row']) . ' days'));
        }
        $rows[] = ['row' => $r, 'date' => $d];
    }
    return $rows;
}

/**
 * House-rule checks on a single-booking calc. Levels: 'error' (must fix — blocks
 * the Agent API confirm unless forced), 'warn', 'ok', 'info'.
 * $ctx: mid (Y-m-d of the last MIDT, i.e. first beach night, or null),
 *       flight_costs (float[] known flight costs = flight_routes.rate_pax, or null to skip).
 */
function sc_calc_rule_checks(string $path, array $ctx = []): array {
    $res = [];
    $sheets = sc_xlsx_sheets($path);
    if (sc_has_recap($sheets)) {
        return [['level' => 'info', 'msg' => 'Group calc (RECAP) — single-booking calc rules skipped.']];
    }

    // 1) Only the confirmed pax sheet.
    $pax = [];
    foreach ($sheets as $s) { if (stripos($s, 'PAX') !== false) $pax[] = trim($s); }
    if (count($pax) > 1) {
        $res[] = ['level' => 'error', 'msg' => 'Calc still has ' . count($pax) . ' pax sheets (' . implode(', ', $pax) . ') — keep only the confirmed one.'];
    }

    $full = sc_xlsx_sheet($path);
    $v = $full['v']; $f = $full['f'];

    // 2) H6:I14 (teen/child price, rack, sto, single supp, discounts) must be cleared.
    $left = [];
    foreach (['H', 'I'] as $col) {
        for ($r = 6; $r <= 14; $r++) {
            $ref = sc_ref($col, $r);
            if (isset($v[$ref]) || isset($f[$ref])) $left[] = $ref;
        }
    }
    if ($left) $res[] = ['level' => 'warn', 'msg' => 'H6:I14 not cleared (' . implode(', ', $left) . ') — remove rack/sto/single/discount block.'];

    // 3) F9 (Price to customer) = formula of its components.
    if (!isset($f['F9'])) {
        $res[] = ['level' => 'warn', 'msg' => 'F9 (Price to customer) is ' . (isset($v['F9']) ? 'a fixed number (' . $v['F9'] . ')' : 'empty')
                 . ' — write it as a formula of its components, e.g. =1625+255+70.'];
    }

    // 4) Hotel on every night (col K); the last day (departure) has none.
    $days = sc_calc_day_rows($v, $f);
    $mid  = $ctx['mid'] ?? null;
    $missBeach = []; $missOther = [];
    foreach ($days as $i => $d) {
        if ($i === count($days) - 1) break;
        if (sc_cell($v, 'K', $d['row']) !== '') continue;
        $lbl = ($d['date'] ? sc_fmt($d['date']) : 'row ' . $d['row']);
        if ($mid && $d['date'] && $d['date'] >= $mid) $missBeach[] = $lbl;
        else                                           $missOther[] = $lbl;
    }
    if ($missBeach) $res[] = ['level' => 'error', 'msg' => 'No hotel (col K) on beach night(s): ' . implode(', ', $missBeach) . '.'];
    if ($missOther) $res[] = ['level' => 'warn',  'msg' => 'No hotel (col K) on night(s): ' . implode(', ', $missOther) . '.'];

    // 5) Flight cost (col C) = <cost_pp>*$B$1 with a cost from the rate table.
    $known = isset($ctx['flight_costs']) && is_array($ctx['flight_costs']) ? $ctx['flight_costs'] : null;
    foreach ($days as $d) {
        $ref = sc_ref('C', $d['row']);
        if (isset($f[$ref])) {
            if (preg_match('/^=\s*([\d.]+)\s*\*\s*\$B\$1\s*$/i', $f[$ref], $m)) {
                if ($known !== null && $known && !in_array((float)$m[1], $known, true)) {
                    $res[] = ['level' => 'warn', 'msg' => 'Flight cost ' . $m[1] . ' pp in ' . $ref . ' is not in the flight rate table.'];
                }
            }
        } elseif (isset($v[$ref]) && is_numeric($v[$ref])) {
            $res[] = ['level' => 'warn', 'msg' => 'Flight cost in ' . $ref . ' is a fixed number — use =<cost_pp>*$B$1.'];
        }
    }

    // 6) Every formula must carry a saved value (the Hub parser reads saved values).
    $noVal = [];
    foreach ($f as $ref => $ftxt) { if (!isset($v[$ref])) $noVal[] = $ref; }
    if ($noVal) {
        $res[] = ['level' => 'error', 'msg' => count($noVal) . ' formula cell(s) have no saved value (e.g. ' . implode(', ', array_slice($noVal, 0, 4))
                 . ') — the file was not recalculated; Hub would read the dates wrong. Open & save it in Excel, or use fill_calc.'];
    }

    // 7) Guests (row 43+: name A, MR/MRS B, country F) and room type (A37).
    $guests = 0; $noCountry = []; $noTitle = [];
    for ($r = 43; $r <= 49; $r++) {
        if (sc_cell($v, 'A', $r) === '') continue;
        $guests++;
        if (sc_cell($v, 'F', $r) === '') $noCountry[] = sc_cell($v, 'A', $r);
        if (sc_cell($v, 'B', $r) === '') $noTitle[]   = sc_cell($v, 'A', $r);
    }
    if (!$guests)   $res[] = ['level' => 'warn', 'msg' => 'No guest names in the Calc (row 43+).'];
    if ($noCountry) $res[] = ['level' => 'warn', 'msg' => 'Country missing for: ' . implode(', ', $noCountry) . '.'];
    if ($noTitle)   $res[] = ['level' => 'warn', 'msg' => 'MR/MRS missing for: ' . implode(', ', $noTitle) . '.'];
    if (sc_cell($v, 'A', 37) === '') $res[] = ['level' => 'warn', 'msg' => 'Room type (A37) is empty.'];

    if (!$res) $res[] = ['level' => 'ok', 'msg' => 'Calc house rules: all OK.'];
    return $res;
}
