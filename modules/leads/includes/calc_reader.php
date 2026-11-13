<?php
/**
 * calc_reader.php — read a booking's Calc Excel (*_Calc.xlsx) into plain data.
 *
 * One reader for the Agent API (read_calc), the ITI final programme (built from
 * the confirmed booking's Calc) and fill_calc's file lookup. Dependency-free: it
 * uses the ZipArchive + DOM xlsx reader of safari_check.php (the confirm_preview
 * checks), so it needs no PhpSpreadsheet.
 *
 *   calc_pick_file()     which *_Calc.xlsx of a folder listing to use
 *   calc_read_file()     local .xlsx → data (see the return shape below)
 *   calc_read_request()  a Hub request → download its Calc (+ Dropbox rev) → data
 *
 * Columns of the day table are found by their header text (older templates have
 * another layout). Day dates are the first date + row offset: the A18+ cascade
 * is a formula whose saved value can be missing or stale.
 * Passport numbers are never read. Keep PHP-7 style (no match / arrow functions).
 */
require_once __DIR__ . '/safari_check.php';

/**
 * The Calc was found but cannot be read as it is (several pax sheets and none marked CONF,
 * group calc with no client sheet, sheet not found): carries what a page needs to let the
 * user choose — 'file', 'candidates' (Calc files of the folder), 'sheets' (pickable sheets).
 */
class CalcSheetException extends InvalidArgumentException {
    public $data = [];
    public function __construct($msg, array $data = array()) { parent::__construct($msg); $this->data = $data; }
}

/**
 * The sheets a user can pick: the pax sheets ("2PAX", "4PAX CONF", "2PAX Fabio Zanelli")
 * and RECAP, in workbook order; every sheet when none of them says PAX or RECAP.
 */
function calc_pickable_sheets(array $sheets) {
    $out = array();
    foreach ($sheets as $s) {
        if (stripos($s, 'PAX') !== false || stripos($s, 'recap') !== false) $out[] = $s;
    }
    return $out ? $out : array_values($sheets);
}

/**
 * The Calc to use among a folder's file names: the given $file (must exist), else
 * the "*_Calc.xlsx" with the highest leading number.
 * @return array{file:string, candidates:string[], warnings:string[]}
 * @throws InvalidArgumentException
 */
function calc_pick_file(array $names, string $file = '', string $dir = ''): array {
    $cands = [];
    foreach ($names as $fn) {
        if (preg_match('/_calc\.xlsx$/i', $fn) && strpos($fn, '~$') !== 0) $cands[] = $fn;
    }
    $warnings = [];
    $file = trim($file);
    if ($file !== '') {
        if (!in_array($file, $cands, true)) {
            throw new InvalidArgumentException('File "' . $file . '" not found. Calc files: ' . ($cands ? implode(', ', $cands) : 'none'));
        }
    } else {
        if (!$cands) throw new InvalidArgumentException('No *_Calc.xlsx in ' . ($dir !== '' ? $dir : 'the folder') . ' — run copy_program first.');
        rsort($cands, SORT_NATURAL | SORT_FLAG_CASE);
        $file = $cands[0];
        if (count($cands) > 1) $warnings[] = 'Several Calc files — used ' . $file . ' (pass "file" to choose).';
    }
    return ['file' => $file, 'candidates' => $cands, 'warnings' => $warnings];
}

/** Trimmed cell text; a lone space (common in the templates) counts as empty. */
function calc_rd_text(array $v, string $col, int $row): string {
    return trim(sc_cell($v, $col, $row));
}

/** Numeric saved value of a cell, or null. */
function calc_rd_num(array $v, string $col, int $row): ?float {
    $t = calc_rd_text($v, $col, $row);
    return ($t !== '' && is_numeric($t)) ? (float)$t : null;
}

/** First row in [$from, $to] whose column-A text contains $needle (case-insensitive), or 0. */
function calc_rd_row(array $v, string $needle, int $from = 1, int $to = 120): int {
    for ($r = $from; $r <= $to; $r++) {
        if (stripos(calc_rd_text($v, 'A', $r), $needle) !== false) return $r;
    }
    return 0;
}

/** [col, row] of the first cell whose text equals $text (case-insensitive, trimmed), or null. */
function calc_rd_find(array $v, string $text, ?string $col = null): ?array {
    foreach ($v as $ref => $val) {
        if (strcasecmp(trim($val), $text) !== 0) continue;
        $cr = sc_ref_split($ref);
        if ($col === null || $cr[0] === $col) return $cr;
    }
    return null;
}

/** Column letter + $n (e.g. 'E' + 1 → 'F'); single letters only, like the Calc. */
function calc_rd_col_shift(string $col, int $n): string {
    return chr(ord($col) + $n);
}

/** The client sheets of a group calc (every sheet but RECAP). */
function calc_group_client_sheets(array $sheets): array {
    $out = [];
    foreach ($sheets as $s) { if (stripos($s, 'recap') === false) $out[] = $s; }
    return $out;
}

/**
 * Read a local Calc. Throws InvalidArgumentException when the booking is not
 * finalised (several PAX sheets), or is a group calc (RECAP) and no sheet is given.
 * A group calc is read one sheet at a time: a client's own sheet ("2PAX Fabio Zanelli":
 * that client's days, guests, flights) or "RECAP" (the whole group).
 * @return array{sheet:string, pax:array, pax_text:string, room_config:string, extra_details:string,
 *   start_date:?string, end_date:?string, nights:int, days:array, guests:array, guests_tba:bool,
 *   arrival:string, departure:string, price_to_customer:?float, price_total:?float, price_sto:?float,
 *   warnings:string[]}
 */
function calc_read_file(string $path, string $wantSheet = ''): array {
    $sheets = sc_xlsx_sheets($path);
    if (!$sheets) throw new InvalidArgumentException('Not a readable .xlsx file.');
    if (sc_has_recap($sheets) && trim($wantSheet) === '') {
        throw new InvalidArgumentException('Group calc (RECAP sheet) — pass "sheet": the client\'s own sheet ('
            . implode(', ', array_map('trim', calc_group_client_sheets($sheets))) . ') or RECAP for the whole group.');
    }
    $warnings = [];
    $pax = [];
    foreach ($sheets as $s) { if (stripos($s, 'PAX') !== false) $pax[] = $s; }
    $sheet = null;
    if (trim($wantSheet) !== '') {
        foreach ($sheets as $s) { if (strcasecmp(trim($s), trim($wantSheet)) === 0) $sheet = $s; }
        if ($sheet === null) throw new InvalidArgumentException('Sheet "' . $wantSheet . '" not found. Sheets: ' . implode(', ', array_map('trim', $sheets)));
    } elseif (count($pax) > 1) {
        // Old quotes are often kept next to the confirmed sheet, which the team
        // marks "CONF" / "CONFIRMED": use it when exactly one sheet says so.
        $conf = [];
        foreach ($pax as $s) { if (stripos($s, 'CONF') !== false) $conf[] = $s; }
        if (count($conf) !== 1) {
            throw new InvalidArgumentException('The Calc has ' . count($pax) . ' pax sheets (' . implode(', ', array_map('trim', $pax)) . ') and '
                . (count($conf) ? count($conf) . ' marked CONF' : 'none marked CONF')
                . ' — the booking is not finalised: keep only the confirmed sheet (or pass "sheet").');
        }
        $sheet = $conf[0];
        $warnings[] = 'Several pax sheets — read "' . trim($sheet) . '" (marked CONF); the others should be deleted.';
    } else {
        $sheet = $pax ? $pax[0] : $sheets[0];
    }
    $full = sc_xlsx_sheet($path, $sheet);
    $v = $full['v']; $f = $full['f'];

    // ── Header block: pax, prices ─────────────────────────────────────────────
    $paxNum = function (string $label) use ($v) {
        $r = calc_rd_row($v, $label, 1, 15);
        $n = $r ? calc_rd_num($v, 'B', $r) : null;
        return $n === null ? 0 : (int)$n;
    };
    $paxOut = ['adults' => $paxNum('adult'), 'teen' => $paxNum('teen'), 'child' => $paxNum('child')];

    $priceNext = function (string $label) use ($v) {
        $at = calc_rd_find($v, $label);
        return $at ? calc_rd_num($v, calc_rd_col_shift($at[0], 1), $at[1]) : null;
    };
    $priceCustomer = $priceNext('Price to customer');
    $priceTotal    = $priceNext('Tot price');
    $sto = calc_rd_find($v, 'sto');       // 'sto' label, amount on its left (cleared once confirmed)
    $priceSto = ($sto && $sto[0] > 'A') ? calc_rd_num($v, calc_rd_col_shift($sto[0], -1), $sto[1]) : null;
    // Per-person price to customer of a teenager / child: "Teenager price" / "Child price" (I7 / I8),
    // amount on the left (H7 = F9 − teenager discount, H8 = F9 − child discount).
    $priceLeft = function (string $label) use ($v) {
        $at = calc_rd_find($v, $label);
        return ($at && $at[0] > 'A') ? calc_rd_num($v, calc_rd_col_shift($at[0], -1), $at[1]) : null;
    };
    $priceTeen  = $priceLeft('Teenager price');
    $priceChild = $priceLeft('Child price');
    // Same block: single supplement ("single supplement" or "single supp"), teenager / child discount.
    $singleSupp = $priceLeft('single supplement');
    if ($singleSupp === null) $singleSupp = $priceLeft('single supp');
    $discTeen  = $priceLeft('teenager discount');
    $discChild = $priceLeft('child discount');

    // ── Day table ─────────────────────────────────────────────────────────────
    $hdr = 16;
    $at = calc_rd_find($v, 'DATE', 'A');
    if ($at) $hdr = $at[1];
    $headers = [];
    foreach ($v as $ref => $val) {
        $cr = sc_ref_split($ref);
        if ($cr[1] === $hdr) $headers[$cr[0]] = strtoupper(trim($val));
    }
    ksort($headers);
    $colFor = function (array $needles) use ($headers) {
        foreach ($headers as $col => $h) {
            foreach ($needles as $n) { if (strpos($h, $n) !== false) return $col; }
        }
        return null;
    };
    $cols = [
        'label'     => $colFor(['OVERNIGHT', 'PARK/']),
        'flight'    => $colFor(['FLIGHT']),
        'park_fees' => $colFor(['PARK FEES']),
        'fees_desc' => $colFor(['FEES DESC']),
        'act_desc'  => $colFor(['ACTIVITY DESC', 'ACTIVITIES DESC']),
        'hotel'     => $colFor(['HOTEL', 'LODGE', 'CAMPSITE']),
        'invoice'   => $colFor(['INVOICE']),
        'checked'   => $colFor(['CHECKED']),
    ];
    foreach (['label' => 'B', 'hotel' => 'K'] as $k => $def) {
        if ($cols[$k] === null) {
            $cols[$k] = $def;
            $warnings[] = 'Column "' . $k . '" not found in the header row ' . $hdr . ' — assumed ' . $def . '.';
        }
    }
    $cell = function (?string $col, int $row) use ($v) { return $col === null ? '' : calc_rd_text($v, $col, $row); };

    // Dates: a hard-typed date (A17, or a later row typed by hand after a gap) is
    // an anchor; a formula row (=SUM(A{n-1}+1) cascade) is anchor + row offset —
    // its saved value is only compared, never trusted.
    // A day row is found by its PARK/OVERNIGHT text too (sc_calc_day_rows): a row with no date
    // and no formula in A (a day inserted by hand, the departure row left blank) gets the
    // previous day + 1, with a warning, and becomes the anchor of the rows after it.
    $rows = sc_calc_day_rows($v, $f, $cols['label']);
    $anchor = null;
    $days = [];
    $prevDate = null;
    foreach ($rows as $i => $d) {
        $raw   = calc_rd_text($v, 'A', $d['row']);
        $saved = $raw !== '' ? sc_parse_xlsx_date($raw) : null;
        $derived = false;
        if (empty($d['dated']) && $prevDate !== null) {
            $derived = date('Y-m-d', strtotime($prevDate . ' +1 day'));
            $anchor = ['row' => $d['row'], 'date' => $derived];
            $warnings[] = 'Row ' . $d['row'] . ' has no date: used ' . $derived . ' (previous day + 1). Fix the Calc.';
        } elseif (!isset($f[sc_ref('A', $d['row'])]) && $saved !== null) {
            $anchor = ['row' => $d['row'], 'date' => $saved];
        } elseif ($anchor === null && $i === 0 && $saved !== null) {
            $anchor = ['row' => $d['row'], 'date' => $saved];
            $warnings[] = 'No hard-typed first date in the DATE column — used the saved value of A' . $d['row'] . '.';
        }
        $date = null;
        if ($derived !== false) {
            $date = $derived;
        } elseif ($anchor !== null) {
            $date = date('Y-m-d', strtotime($anchor['date'] . ' ' . sprintf('%+d', $d['row'] - $anchor['row']) . ' days'));
            if ($saved !== null && $saved !== $date) {
                $warnings[] = 'A' . $d['row'] . ' shows ' . $saved . ' but follows ' . $anchor['date'] . ' (row ' . $anchor['row'] . ') → used ' . $date . '.';
            }
        }
        if ($date !== null) $prevDate = $date;
        $days[] = [
            'row'            => $d['row'],
            'date'           => $date,
            'date_derived'   => $derived !== false,
            'label'          => $cell($cols['label'], $d['row']),
            'flight_cost'    => $cols['flight'] ? calc_rd_num($v, $cols['flight'], $d['row']) : null,
            'park_fees_desc' => $cell($cols['fees_desc'], $d['row']),
            'activity_desc'  => $cell($cols['act_desc'], $d['row']),
            'hotel_text'     => $cell($cols['hotel'], $d['row']),
            'invoice'        => $cell($cols['invoice'], $d['row']),
            'checked'        => $cell($cols['checked'], $d['row']),
        ];
    }
    if (!$days) $warnings[] = 'No day rows found under the DATE header (row ' . $hdr . ').';

    // Rows of the day table with content (hotel, activities, fees) that did not become a day
    // (no date and no PARK/OVERNIGHT text): they would be dropped from the programme without a
    // word, so iti_final_calc_review() blocks on them.
    $isDay = [];
    foreach ($rows as $d) $isDay[$d['row']] = true;
    $skipped = [];
    for ($r = $hdr + 1; $r < $hdr + 40; $r++) {
        $a = calc_rd_text($v, 'A', $r);
        if ($a !== '' && stripos($a, 'total') === 0) break;
        if (isset($isDay[$r])) continue;
        $what = [];
        foreach (['hotel' => 'hotel', 'act_desc' => 'activity', 'fees_desc' => 'park fees'] as $k => $name) {
            $t = $cell($cols[$k], $r);
            if ($t !== '' && !is_numeric($t)) $what[] = $name . ' "' . $t . '"';
        }
        if ($what) $skipped[] = ['row' => $r, 'text' => implode(', ', $what)];
    }

    // One day list for everything: nights = days − 1 here, in price_options[].nights, in the
    // plan and in the programme's duration (iti_doc_data: N days / N−1 nights). The last row
    // is the departure day: if it has a hotel, a row is missing or was not read.
    $nights = $days ? count($days) - 1 : 0;
    if ($days && $days[count($days) - 1]['hotel_text'] !== '') {
        $warnings[] = 'The last day row (' . $days[count($days) - 1]['row'] . ') has a hotel: the Calc has no departure day — add it, or check that no row was skipped.';
    }

    // ── Booking details below the table ──────────────────────────────────────
    $below = function (string $label) use ($v) {
        $r = calc_rd_row($v, $label, 20, 120);
        if (!$r) return '';
        $t = calc_rd_text($v, 'A', $r + 1);
        return in_array(strtoupper($t), ['NA', 'N/A', '-', '—'], true) ? '' : $t;
    };
    // "ADULTS/TEEN/CHD": value next to the label (B) or on the row below (A).
    $paxRow  = calc_rd_row($v, 'ADULTS/TEEN', 20, 120);
    $paxText = '';
    if ($paxRow) {
        $paxText = calc_rd_text($v, 'B', $paxRow);
        $nextA   = calc_rd_text($v, 'A', $paxRow + 1);
        if ($paxText === '' && stripos($nextA, 'ROOMS') === false) $paxText = $nextA;
    }

    // Guests: the NAME header row, then one guest per row (A name, title / DOB /
    // COUNTRY found by header; PASSPORT deliberately skipped).
    $guests = []; $tba = false;
    $gh = 0;
    for ($r = 20; $r <= 120; $r++) {
        $a = strtoupper(calc_rd_text($v, 'A', $r));
        if (strpos($a, 'NAME') === 0 && (strpos($a, 'FIRST') !== false || strpos($a, 'SURNAME') !== false)) { $gh = $r; break; }
    }
    if ($gh) {
        $gcol = ['title' => null, 'dob' => null, 'country' => null];
        foreach (['B', 'C', 'D', 'E', 'F', 'G'] as $c) {
            $h = strtoupper(calc_rd_text($v, $c, $gh));
            if ($gcol['title'] === null && strpos($h, 'MR') !== false) $gcol['title'] = $c;
            elseif ($gcol['dob'] === null && strpos($h, 'DOB') !== false) $gcol['dob'] = $c;
            elseif ($gcol['country'] === null && strpos($h, 'COUNTRY') !== false) $gcol['country'] = $c;
        }
        for ($r = $gh + 1, $blank = 0; $r <= $gh + 30; $r++) {
            $name = calc_rd_text($v, 'A', $r);
            if ($name === '') { if (++$blank >= 2) break; continue; }
            if (stripos($name, 'DETAILS') !== false) break;       // reached ARRIVAL DETAILS
            $blank = 0;
            // Placeholder rows ("TBA", "waiting", "tbc", "?", "guest 2") = name not known yet.
            $isTba = (bool)preg_match('/^(tba|tbc|tbd|n\/?a|waiting|wait|pending|unknown|da definire|\?+|-+|(guest|pax|name)\s*\d*)\.?$/i', trim($name));
            $tba = $tba || $isTba;
            $dobRaw = $gcol['dob'] ? calc_rd_text($v, $gcol['dob'], $r) : '';
            $guests[] = [
                'name'    => $name,
                'title'   => $gcol['title'] ? strtoupper(calc_rd_text($v, $gcol['title'], $r)) : '',
                'dob'     => $dobRaw !== '' ? sc_parse_xlsx_date($dobRaw) : null,
                'country' => $gcol['country'] ? calc_rd_text($v, $gcol['country'], $r) : '',
                'tba'     => $isTba,
            ];
        }
    } else {
        $warnings[] = 'Guest table (NAME header) not found.';
    }

    $noVal = 0;
    foreach ($f as $ref => $ftxt) { if (!isset($v[$ref])) $noVal++; }
    if ($noVal) $warnings[] = $noVal . ' formula cell(s) have no saved value — amounts may be missing; dates are computed from the first date.';

    $flights = sc_flight_blocks($v);
    $start = $days ? $days[0]['date'] : null;
    $end   = $days ? $days[count($days) - 1]['date'] : null;
    return [
        'sheet'             => trim($sheet),
        'pax'               => $paxOut,
        'pax_text'          => $paxText,
        'room_config'       => $below('ROOMS TYPE'),
        'extra_details'     => $below('EXTRA DETAILS'),
        'start_date'        => $start,
        'end_date'          => $end,
        'nights'            => $nights,
        'days'              => $days,
        'skipped_rows'      => $skipped,   // [{row, text}] day-table rows with content that are not days
        'guests'            => $guests,
        'guests_tba'        => $tba || !$guests,
        'arrival'           => $flights['arrival'],
        'departure'         => $flights['departure'],
        'price_to_customer' => $priceCustomer,
        'price_total'       => $priceTotal,
        'price_sto'         => $priceSto,
        'price_teen'        => $priceTeen,
        'price_child'       => $priceChild,
        'single_supp'       => $singleSupp,
        'discount_teen'     => $discTeen,
        'discount_child'    => $discChild,
        'warnings'          => $warnings,
    ];
}

/**
 * The sheet of a group calc for one client: the client sheet whose name matches the
 * customer ("1PAX Cacioli" ~ AnnamariaCaciolli, as Payments matches a RECAP row), the
 * only client sheet (or the only "…PAX…" one), else RECAP (the whole group) with a warning.
 * Shared by Operations (ops_group_sheet) and calc_read_request().
 * @return array{sheet:string, whole:bool, warning:string}
 */
function calc_group_sheet(array $sheets, string $customer): array {
    require_once __DIR__ . '/calc_payments.php';   // cp_name_tokens / cp_name_score
    $clients = calc_group_client_sheets($sheets);
    $recap = '';
    foreach ($sheets as $s) { if (stripos($s, 'recap') !== false) { $recap = $s; break; } }
    $tok = cp_name_tokens((string)preg_replace('/_?GRP\d+/i', '', $customer));
    $best = []; $max = 0;
    foreach ($clients as $s) {
        $score = $tok ? cp_name_score($tok, $s) : 0;
        if ($score > $max) { $max = $score; $best = [$s]; }
        elseif ($score === $max && $score > 0) $best[] = $s;
    }
    if (count($best) === 1) return ['sheet' => $best[0], 'whole' => false, 'warning' => ''];
    if (count($clients) === 1) return ['sheet' => $clients[0], 'whole' => false, 'warning' => ''];
    // Extra non-pax sheets (e.g. "MASAI") are not clients: the only "…PAX…" sheet is.
    $pax = [];
    foreach ($clients as $s) { if (stripos($s, 'PAX') !== false) $pax[] = $s; }
    if (count($pax) === 1) return ['sheet' => $pax[0], 'whole' => false, 'warning' => ''];
    return ['sheet' => $recap, 'whole' => true,
            'warning' => 'Group calc: no client sheet named like "' . $customer . '" (' . implode(', ', array_map('trim', $clients))
                       . ') — read RECAP, the whole group.'];
}

/**
 * Folders where a request's Calc may be: its own folder, then — for a GRP member
 * (group_folder set and not the booking itself) — the group folder above it, where the
 * group Calc lives. Same rule as invoice_calc.php / ops_request_dirs().
 * Needs booking_service.php (req_folder_path).
 */
function calc_request_dirs(array $r): array {
    $dir = req_folder_path($r);
    if ($dir === '') return [];
    $dirs = [$dir];
    $gf = trim((string)($r['group_folder'] ?? ''));
    if ($gf !== '' && strcasecmp($gf, trim((string)($r['practice_code'] ?? ''))) !== 0 && dirname($dir) !== $dir) {
        $dirs[] = dirname($dir);
    }
    return $dirs;
}

/**
 * Locate a request's Calc (calc_request_dirs, first folder with a match).
 * @return array{dir:string, pick:array, in_group:bool}
 * @throws InvalidArgumentException (no folder / no Calc / "file" not found)
 */
function calc_locate_request(string $token, array $r, string $file = ''): array {
    require_once __DIR__ . '/folder_link_service.php';   // folder moved outside the Hub: re-linked by its Dropbox id
    if (!empty($r['id'])) $r = folder_heal(db(), $token, $r);
    $dirs = calc_request_dirs($r);
    if (!$dirs) throw new InvalidArgumentException('This request has no Dropbox folder.');
    $err = null;
    foreach ($dirs as $i => $d) {
        try {
            return ['dir' => $d, 'pick' => calc_pick_file(dropbox_list_files($token, $d), $file, $d), 'in_group' => $i > 0];
        } catch (InvalidArgumentException $e) {
            if ($err === null) $err = $e;   // the request folder's message (it is the one to fix)
        }
    }
    if (count($dirs) > 1) {
        throw new InvalidArgumentException($err->getMessage() . ' Not in the group folder ' . $dirs[1] . ' either.');
    }
    throw $err;
}

/** "Lodge A|Lodge B|…": the hotel of each night, to tell a price option from another itinerary. */
function calc_hotel_signature(array $days) {
    $h = array();
    foreach ($days as $d) $h[] = strtolower(preg_replace('/\s+/', ' ', trim((string)($d['hotel_text'] ?? ''))));
    return implode('|', $h);
}

/**
 * Price options of a quote (one pax sheet per group size: 10 / 11 / 12 pax): read every sheet
 * of $priceSheets from the same local Calc and add $data['price_options'] =
 * [{sheet, pax_total, pax, price_to_customer, nights, same_itinerary}] (sorted by pax, the read
 * sheet always included), when at least two sheets are given. A sheet whose nights or hotels
 * differ from the read one is flagged (same_itinerary false + a warning): it is another
 * itinerary, not a price option. Unknown sheet names are skipped with a warning.
 */
function calc_add_price_options($path, array $sheets, array $data, array $priceSheets) {
    if (count($priceSheets) === 1 && trim((string)$priceSheets[0]) === '*') {
        // Default of the Hub page: every pax sheet, unless the Calc is a group calc (RECAP) or
        // has a sheet marked CONF (a confirmed booking: the other sheets are old quotes).
        $priceSheets = array();
        $conf = false;
        foreach ($sheets as $s) { if (stripos($s, 'CONF') !== false) $conf = true; }
        if (!$conf && !sc_has_recap($sheets)) {
            foreach ($sheets as $s) { if (stripos($s, 'PAX') !== false) $priceSheets[] = $s; }
        }
    }
    $want = array(trim((string)$data['sheet']));
    foreach ($priceSheets as $ps) {
        $ps = trim((string)$ps);
        if ($ps === '') continue;
        $hit = null;
        foreach ($sheets as $s) { if (strcasecmp(trim($s), $ps) === 0) $hit = trim($s); }
        if ($hit === null) { $data['warnings'][] = 'Price sheet "' . $ps . '" not found in the Calc — skipped.'; continue; }
        $dup = false;
        foreach ($want as $w) { if (strcasecmp($w, $hit) === 0) $dup = true; }
        if (!$dup) $want[] = $hit;
    }
    if (count($want) < 2) return $data;
    $sig  = calc_hotel_signature($data['days']);
    $opts = array();
    foreach ($want as $s) {
        $d = strcasecmp($s, trim((string)$data['sheet'])) === 0 ? $data : calc_read_file($path, $s);
        $same = (int)$d['nights'] === (int)$data['nights'] && calc_hotel_signature($d['days']) === $sig;
        if (!$same) $data['warnings'][] = 'Sheet "' . $s . '" has other nights or hotels than "' . trim((string)$data['sheet'])
                                         . '" — it looks like another itinerary, not a price option.';
        $p = $d['pax'];
        $opts[] = array('sheet' => $s, 'pax_total' => (int)$p['adults'] + (int)$p['teen'] + (int)$p['child'], 'pax' => $p,
                        'price_to_customer' => $d['price_to_customer'], 'nights' => (int)$d['nights'], 'same_itinerary' => $same);
    }
    usort($opts, function ($a, $b) { return $a['pax_total'] - $b['pax_total']; });
    $data['price_options'] = $opts;
    return $data;
}

/**
 * A Hub request's Calc: locate it in the request's Dropbox folder (GRP member: else
 * the group folder), download it (with its Dropbox rev, to detect later changes) and read it.
 * A group Calc of the group folder read with no $sheet → this client's sheet (calc_group_sheet).
 * 'folder' stays the request's own folder (documents are saved there); 'calc_folder' is
 * where the Calc was found.
 * Needs dropbox_helper.php, booking_service.php (req_folder_path) and
 * calc_service.php (calc_dbx_download) loaded.
 * @throws InvalidArgumentException (not found / not finalised) | RuntimeException (Dropbox)
 */
function calc_read_request(PDO $db, int $requestId, string $file = '', string $sheet = '', array $priceSheets = array()): array {
    $st = $db->prepare('SELECT * FROM requests WHERE id = ?');
    $st->execute([$requestId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new InvalidArgumentException('Request ' . $requestId . ' not found.');

    $token = dropbox_get_access_token();
    require_once __DIR__ . '/folder_link_service.php';
    $r     = folder_heal($db, $token, $r);   // folder moved outside the Hub: re-linked by its Dropbox id
    $own   = req_folder_path($r);
    $loc   = calc_locate_request($token, $r, $file);
    $dir   = $loc['dir'];
    $pick  = $loc['pick'];
    $path  = $dir . '/' . $pick['file'];
    $dl    = calc_dbx_download($token, $path);
    if ($dl === null) throw new RuntimeException('Could not download ' . $path);

    $tmp = tempnam(sys_get_temp_dir(), 'calcrd_');
    file_put_contents($tmp, $dl['bytes']);
    $warnings = $pick['warnings'];
    if ($loc['in_group']) $warnings[] = 'No Calc in the client folder — used the group Calc ' . $path . '.';
    $sheets = array();
    try {
        $sheets = sc_xlsx_sheets($tmp);
        if ($loc['in_group'] && trim($sheet) === '' && sc_has_recap($sheets)) {
            $g = calc_group_sheet($sheets, (string)($r['customer_name'] ?? ''));
            if ($g['whole']) {
                throw new InvalidArgumentException('Group calc ' . $pick['file'] . ': no client sheet named like "' . ($r['customer_name'] ?? '')
                    . '" (' . implode(', ', array_map('trim', calc_group_client_sheets($sheets))) . ') — pass "sheet" (the client\'s sheet, or RECAP for the whole group).');
            }
            $sheet = $g['sheet'];
        }
        $data = calc_read_file($tmp, $sheet);
        if ($priceSheets) $data = calc_add_price_options($tmp, $sheets, $data, $priceSheets);
    } catch (InvalidArgumentException $e) {
        if (!$sheets) throw $e;   // not a readable .xlsx: nothing to choose from
        throw new CalcSheetException($e->getMessage(), array(
            'file' => $pick['file'], 'candidates' => $pick['candidates'], 'sheets' => calc_pickable_sheets($sheets)));
    } finally {
        @unlink($tmp);
    }
    $data['warnings'] = array_merge($warnings, $data['warnings']);
    return array_merge([
        'request_id'  => (int)$r['id'],
        'folder'      => $own,
        'calc_folder' => $dir,
        'file'        => $pick['file'],
        'candidates'  => $pick['candidates'],   // every *_Calc.xlsx of that folder
        'sheets'      => calc_pickable_sheets($sheets),   // to switch sheet (quotes keep several pax sheets)
        'calc_path'   => $path,
        'calc_rev'    => $dl['rev'],
        'request_status' => (string)($r['status'] ?? ''),   // not Booked = a quote: room types not required yet
    ], $data);
}
