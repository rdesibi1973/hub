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

/**
 * Read a local Calc. Throws InvalidArgumentException when the booking is not
 * finalised (several PAX sheets) or is a group calc (RECAP).
 * @return array{sheet:string, pax:array, pax_text:string, room_config:string, extra_details:string,
 *   start_date:?string, end_date:?string, nights:int, days:array, guests:array, guests_tba:bool,
 *   arrival:string, departure:string, price_to_customer:?float, price_total:?float, price_sto:?float,
 *   warnings:string[]}
 */
function calc_read_file(string $path, string $wantSheet = ''): array {
    $sheets = sc_xlsx_sheets($path);
    if (!$sheets) throw new InvalidArgumentException('Not a readable .xlsx file.');
    if (sc_has_recap($sheets)) {
        throw new InvalidArgumentException('Group calc (RECAP sheet) — read each client\'s own Calc instead.');
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
    $rows = sc_calc_day_rows($v, $f);
    $anchor = null;
    $days = [];
    foreach ($rows as $i => $d) {
        $raw   = calc_rd_text($v, 'A', $d['row']);
        $saved = $raw !== '' ? sc_parse_xlsx_date($raw) : null;
        if (!isset($f[sc_ref('A', $d['row'])]) && $saved !== null) {
            $anchor = ['row' => $d['row'], 'date' => $saved];
        } elseif ($anchor === null && $i === 0 && $saved !== null) {
            $anchor = ['row' => $d['row'], 'date' => $saved];
            $warnings[] = 'No hard-typed first date in the DATE column — used the saved value of A' . $d['row'] . '.';
        }
        $date = null;
        if ($anchor !== null) {
            $date = date('Y-m-d', strtotime($anchor['date'] . ' ' . sprintf('%+d', $d['row'] - $anchor['row']) . ' days'));
            if ($saved !== null && $saved !== $date) {
                $warnings[] = 'A' . $d['row'] . ' shows ' . $saved . ' but follows ' . $anchor['date'] . ' (row ' . $anchor['row'] . ') → used ' . $date . '.';
            }
        }
        $days[] = [
            'row'            => $d['row'],
            'date'           => $date,
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
            $isTba = strcasecmp($name, 'TBA') === 0;
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
        'nights'            => $days ? count($days) - 1 : 0,
        'days'              => $days,
        'guests'            => $guests,
        'guests_tba'        => $tba || !$guests,
        'arrival'           => $flights['arrival'],
        'departure'         => $flights['departure'],
        'price_to_customer' => $priceCustomer,
        'price_total'       => $priceTotal,
        'price_sto'         => $priceSto,
        'warnings'          => $warnings,
    ];
}

/**
 * A Hub request's Calc: locate it in the request's Dropbox folder, download it
 * (with its Dropbox rev, to detect later changes) and read it.
 * Needs dropbox_helper.php, booking_service.php (req_folder_path) and
 * calc_service.php (calc_dbx_download) loaded.
 * @throws InvalidArgumentException (not found / not finalised) | RuntimeException (Dropbox)
 */
function calc_read_request(PDO $db, int $requestId, string $file = '', string $sheet = ''): array {
    $st = $db->prepare('SELECT * FROM requests WHERE id = ?');
    $st->execute([$requestId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new InvalidArgumentException('Request ' . $requestId . ' not found.');
    $dir = req_folder_path($r);
    if ($dir === '') throw new InvalidArgumentException('This request has no Dropbox folder.');

    $token = dropbox_get_access_token();
    $pick  = calc_pick_file(dropbox_list_files($token, $dir), $file, $dir);
    $path  = $dir . '/' . $pick['file'];
    $dl    = calc_dbx_download($token, $path);
    if ($dl === null) throw new RuntimeException('Could not download ' . $path);

    $tmp = tempnam(sys_get_temp_dir(), 'calcrd_');
    file_put_contents($tmp, $dl['bytes']);
    try {
        $data = calc_read_file($tmp, $sheet);
    } finally {
        @unlink($tmp);
    }
    $data['warnings'] = array_merge($pick['warnings'], $data['warnings']);
    return array_merge([
        'request_id' => (int)$r['id'],
        'folder'     => $dir,
        'file'       => $pick['file'],
        'candidates' => $pick['candidates'],   // every *_Calc.xlsx of the folder
        'calc_path'  => $path,
        'calc_rev'   => $dl['rev'],
    ], $data);
}
