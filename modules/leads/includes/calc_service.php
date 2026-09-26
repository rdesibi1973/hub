<?php
/**
 * calc_service.php — rates + server-side Calc Excel filling for the Agent API.
 *
 *   calc_rates()        get_rates: program prices (from the Calc template's pax
 *                       sheets: H9 rack, H10 sto, H11 single, H13/H14 discounts),
 *                       flight routes and activities/transfers (sale + cost).
 *   calc_fill()         fill_calc: fills the booking's *_Calc.xlsx with Roberto's
 *                       rules, recalculates, uploads (rev-checked), re-verifies.
 *
 * Requires config.php, dropbox_helper.php, includes/safari_check.php and
 * includes/booking_service.php. PhpSpreadsheet (Composer, vendor/) is loaded lazily.
 * Keep PHP-7 style (no match / arrow functions / str_contains).
 */

/** Load PhpSpreadsheet or throw a clear error. */
function calc_require_spreadsheet(): void {
    if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) return;
    $auto = __DIR__ . '/../../../vendor/autoload.php';
    if (is_file($auto)) require_once $auto;
    if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        throw new RuntimeException('PhpSpreadsheet is not installed on the server (composer: phpoffice/phpspreadsheet ^1.29).');
    }
}

/** Cost columns next to the sale rates (created lazily, like the rest of the Hub). */
function calc_rates_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    try { $db->exec("ALTER TABLE flight_routes  ADD COLUMN cost_pax DECIMAL(10,2) NULL DEFAULT NULL AFTER rate_pax"); } catch (PDOException $ignored) {}
    try { $db->exec("ALTER TABLE activity_rates ADD COLUMN cost     DECIMAL(10,2) NULL DEFAULT NULL AFTER rate"); } catch (PDOException $ignored) {}
    $done = true;
}

// ════════════════════════════════════════════════════════════════════════════
//  Rates
// ════════════════════════════════════════════════════════════════════════════

/** Rows valid on $date (Y-m-d): valid_from <= date and (valid_to null or >= date). */
function calc_valid_sql(): string {
    return "active = 1 AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)";
}

function calc_flight_rates(PDO $db, string $q, string $date): array {
    calc_rates_schema($db);
    $sql  = "SELECT id, route_name, origin, destination, airline, valid_from, valid_to, rate_pax, cost_pax, notes
             FROM flight_routes WHERE " . calc_valid_sql();
    $args = [$date, $date];
    if ($q !== '') {
        $sql .= " AND (route_name LIKE ? OR origin LIKE ? OR destination LIKE ? OR airline LIKE ?)";
        $l = '%' . $q . '%'; array_push($args, $l, $l, $l, $l);
    }
    $st = $db->prepare($sql . " ORDER BY route_name, airline, valid_from DESC");
    $st->execute($args);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['id' => (int)$r['id'], 'route' => $r['route_name'], 'origin' => $r['origin'],
                  'destination' => $r['destination'], 'airline' => $r['airline'],
                  'sale_pp' => $r['rate_pax'] !== null ? (float)$r['rate_pax'] : null,
                  'cost_pp' => $r['cost_pax'] !== null ? (float)$r['cost_pax'] : null,
                  'valid_from' => $r['valid_from'], 'valid_to' => $r['valid_to'], 'notes' => $r['notes']];
    }
    return $out;
}

function calc_activity_rates(PDO $db, string $q, string $date): array {
    calc_rates_schema($db);
    $sql  = "SELECT id, name, category, item_type, valid_from, valid_to, rate, cost, notes
             FROM activity_rates WHERE " . calc_valid_sql();
    $args = [$date, $date];
    if ($q !== '') { $sql .= " AND (name LIKE ? OR notes LIKE ?)"; $l = '%' . $q . '%'; array_push($args, $l, $l); }
    $st = $db->prepare($sql . " ORDER BY category, name, valid_from DESC");
    $st->execute($args);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'category' => $r['category'],
                  'per' => $r['item_type'] === 'pax' ? 'pax' : 'fixed',
                  'sale' => $r['rate'] !== null ? (float)$r['rate'] : null,
                  'cost' => $r['cost'] !== null ? (float)$r['cost'] : null,
                  'valid_from' => $r['valid_from'], 'valid_to' => $r['valid_to'], 'notes' => $r['notes']];
    }
    return $out;
}

/** Exactly one rate by id or name/route; throws with the candidates otherwise. */
function calc_pick_rate(array $rows, $key, string $nameField, string $what): array {
    if (is_int($key) || ctype_digit((string)$key)) {
        foreach ($rows as $r) if ($r['id'] === (int)$key) return $r;
        throw new InvalidArgumentException($what . ' id ' . $key . ' not found (or not valid on that date).');
    }
    $exact = []; $like = [];
    foreach ($rows as $r) {
        if (strcasecmp($r[$nameField], (string)$key) === 0) $exact[] = $r;
        elseif (stripos($r[$nameField], (string)$key) !== false) $like[] = $r;
    }
    $c = $exact ?: $like;
    if (count($c) === 1) return $c[0];
    $names = [];
    foreach (array_slice($c ?: $rows, 0, 12) as $r) $names[] = $r['id'] . ': ' . $r[$nameField];
    throw new InvalidArgumentException($what . ' "' . $key . '" ' . ($c ? 'is ambiguous' : 'not found')
        . ' — use an id. Candidates: ' . implode('; ', $names));
}

/** Template Calc (Dropbox path) of a standard program label, or null. */
function calc_template_path(string $program): ?string {
    foreach (bs_std_programs() as $progs) {
        foreach ($progs as $label => $files) {
            if (strcasecmp($label, $program) !== 0) continue;
            foreach ($files as $f) { if (preg_match('/\.xlsx?$/i', $f['dst'])) return $f['src']; }
        }
    }
    return null;
}

/**
 * Program prices per pax sheet from the program's Calc template:
 * [['sheet','pax','rack','sto','single_suppl','teen_discount','child_discount'], …].
 */
function calc_program_prices(string $token, string $program): array {
    $src = calc_template_path($program);
    if ($src === null) throw new InvalidArgumentException('Unknown program "' . $program . '" — see list_standard_programs.');
    $bytes = dropbox_download_text($token, $src);
    if ($bytes === null) throw new RuntimeException('Template not found in Dropbox: ' . $src);
    $tmp = tempnam(sys_get_temp_dir(), 'calctpl_');
    file_put_contents($tmp, $bytes);
    try {
        calc_require_spreadsheet();
        $ss  = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
        $out = [];
        foreach ($ss->getWorksheetIterator() as $ws) {
            $name = trim($ws->getTitle());
            if (stripos($name, 'PAX') === false) continue;
            $num = function ($ref) use ($ws) {
                try { $v = $ws->getCell($ref)->getCalculatedValue(); } catch (Throwable $e) { return null; }
                return is_numeric($v) ? round((float)$v, 2) : null;
            };
            $out[] = ['sheet' => $ws->getTitle(), 'pax' => (int)$num('B1') ?: (int)preg_replace('/\D/', '', $name),
                      'rack' => $num('H9'), 'sto' => $num('H10'), 'single_suppl' => $num('H11'),
                      'teen_discount' => $num('H13'), 'child_discount' => $num('H14')];
        }
        return ['template' => $src, 'sheets' => $out];
    } finally {
        @unlink($tmp);
    }
}

// ════════════════════════════════════════════════════════════════════════════
//  Dropbox: download with rev / rev-checked upload
// ════════════════════════════════════════════════════════════════════════════

/** ['bytes'=>string, 'rev'=>string] or null if not found. */
function calc_dbx_download(string $token, string $path): ?array {
    $hdrs = [];
    $ch = curl_init('https://content.dropboxapi.com/2/files/download');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Dropbox-API-Arg: ' . json_encode(['path' => $path])],
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$hdrs) {
            $p = strpos($line, ':');
            if ($p !== false) $hdrs[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            return strlen($line);
        },
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 409) return null;
    if ($code !== 200) throw new RuntimeException("Dropbox download failed (HTTP $code): $body");
    $meta = json_decode($hdrs['dropbox-api-result'] ?? '{}', true) ?: [];
    return ['bytes' => $body, 'rev' => (string)($meta['rev'] ?? '')];
}

/** Upload only if the file is still at $rev (Dropbox mode=update). Returns metadata. */
function calc_dbx_upload_update(string $token, string $path, string $bytes, string $rev): array {
    $ch = curl_init('https://content.dropboxapi.com/2/files/upload');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/octet-stream',
            'Dropbox-API-Arg: ' . json_encode([
                'path' => $path, 'mode' => ['.tag' => 'update', 'update' => $rev],
                'autorename' => false, 'mute' => false,
            ]),
        ],
        CURLOPT_POSTFIELDS => $bytes,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 409 && stripos((string)$body, 'conflict') !== false) {
        throw new RuntimeException('The Calc changed in Dropbox since it was read (someone saved it, e.g. from Excel) — nothing written. Retry.');
    }
    if ($code !== 200) throw new RuntimeException("Dropbox upload failed (HTTP $code): $body");
    return json_decode($body, true) ?: [];
}

// ════════════════════════════════════════════════════════════════════════════
//  fill_calc
// ════════════════════════════════════════════════════════════════════════════

/** First row (from $from) whose column-A text contains all $needles, or 0. */
function calc_find_row($ws, array $needles, int $from = 1, int $to = 120): int {
    for ($r = $from; $r <= $to; $r++) {
        $t = strtoupper((string)$ws->getCell('A' . $r)->getValue());
        if ($t === '') continue;
        $all = true;
        foreach ($needles as $n) { if (strpos($t, strtoupper($n)) === false) { $all = false; break; } }
        if ($all) return $r;
    }
    return 0;
}

/** A number or throw. */
function calc_num($v, string $what): float {
    if (!is_numeric($v)) throw new InvalidArgumentException($what . ' must be a number.');
    return (float)$v;
}

/** 1625.0 → "1625", 12.5 → "12.5" (for formulas). */
function calc_fmt_num(float $n): string {
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}

/**
 * Fill a booking's Calc. $in (see docs/AGENT_API.md → fill_calc):
 *   request_id, file?, pax_sheet?, adults?, teens?, children?, start_date (Y-m-d),
 *   price_components[] (→ F9 "=a+b+c"), days[] {row_offset?, label?, flight? (route id/name),
 *   flight_cost_pp?, activity? {amount?|rate?, desc?}, hotel?, repeat?}, guests[] {name,title,country?},
 *   room_type?, adults_teen_chd?, arrival?, departure?, mid_date? (first beach night), confirm?
 * $commit false → everything is built and verified on a temp copy, nothing uploaded.
 */
function calc_fill(PDO $db, array $in, bool $commit): array {
    calc_require_spreadsheet();
    $warnings = [];

    // ── Request + Calc file ──────────────────────────────────────────────────
    $st = $db->prepare("SELECT * FROM requests WHERE id = ?");
    $st->execute([(int)($in['request_id'] ?? 0)]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new InvalidArgumentException('Request not found.');
    $dir = req_folder_path($r);
    if ($dir === '') throw new InvalidArgumentException('This request has no Dropbox folder.');

    $token = dropbox_get_access_token();
    $cands = [];
    foreach (dropbox_list_files($token, $dir) as $fn) { if (preg_match('/_calc\.xlsx$/i', $fn)) $cands[] = $fn; }
    $file = trim((string)($in['file'] ?? ''));
    if ($file !== '') {
        if (!in_array($file, $cands, true)) throw new InvalidArgumentException('File "' . $file . '" not found. Calc files: ' . implode(', ', $cands));
    } else {
        if (!$cands) throw new InvalidArgumentException('No *_Calc.xlsx in ' . $dir . ' — run copy_program first.');
        rsort($cands, SORT_NATURAL | SORT_FLAG_CASE);
        $file = $cands[0];
        if (count($cands) > 1) $warnings[] = 'Several Calc files — used ' . $file . ' (pass "file" to choose).';
    }
    $path = $dir . '/' . $file;
    $dl = calc_dbx_download($token, $path);
    if ($dl === null) throw new RuntimeException('Could not download ' . $path);

    $tmpIn  = tempnam(sys_get_temp_dir(), 'calcin_');
    $tmpOut = tempnam(sys_get_temp_dir(), 'calcout_');
    file_put_contents($tmpIn, $dl['bytes']);

    try {
        $ss = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpIn);

        // ── 1) Keep only the confirmed pax sheet ─────────────────────────────
        $adults   = isset($in['adults'])   ? (int)$in['adults']   : null;
        $teens    = isset($in['teens'])    ? (int)$in['teens']    : 0;
        $children = isset($in['children']) ? (int)$in['children'] : 0;
        $want = trim((string)($in['pax_sheet'] ?? ''));
        if ($want === '') {
            $n = $adults !== null ? $adults + $teens + $children : (int)($r['pax'] ?? 0);
            if ($n <= 0) throw new InvalidArgumentException('Give pax_sheet (e.g. "2PAX") or adults.');
            $want = $n . 'PAX';
        }
        $keep = null;
        foreach ($ss->getSheetNames() as $i => $nm) { if (strcasecmp(trim($nm), $want) === 0) { $keep = $nm; break; } }
        if ($keep === null) throw new InvalidArgumentException('Sheet "' . $want . '" not in the Calc. Sheets: ' . implode(', ', $ss->getSheetNames()));
        $removed = [];
        foreach (array_reverse($ss->getSheetNames(), true) as $i => $nm) {
            if ($nm !== $keep) { $ss->removeSheetByIndex($i); $removed[] = $nm; }
        }
        $ss->setActiveSheetIndex(0);
        $ws = $ss->getActiveSheet();

        if ($adults !== null) {
            $ws->setCellValue('B2', $adults); $ws->setCellValue('B3', $teens); $ws->setCellValue('B4', $children);
        }
        $paxTot = (int)$ws->getCell('B1')->getCalculatedValue();
        if ($adults === null) $adults = (int)$ws->getCell('B2')->getCalculatedValue();

        // ── 2) Clear H6:I14 ──────────────────────────────────────────────────
        foreach (['H', 'I'] as $col) for ($row = 6; $row <= 14; $row++) $ws->setCellValue($col . $row, null);

        // ── 3) F9 = formula of the price components ──────────────────────────
        $comps = isset($in['price_components']) && is_array($in['price_components']) ? $in['price_components'] : [];
        if (!$comps) throw new InvalidArgumentException('price_components is required, e.g. [1625, 255, 70] (see get_rates).');
        $parts = [];
        foreach ($comps as $c) $parts[] = calc_fmt_num(calc_num($c, 'price_components item'));
        $ws->setCellValue('F9', '=' . implode('+', $parts));

        // ── 4) Dates + days ──────────────────────────────────────────────────
        $start = (string)($in['start_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) throw new InvalidArgumentException('start_date must be YYYY-MM-DD.');
        $hdr = calc_find_row($ws, ['DATE'], 1, 40);
        if (!$hdr) $hdr = 16;
        $first = $hdr + 1;
        $totals = calc_find_row($ws, ['TOTAL'], $first, $first + 40);
        if (!$totals) throw new RuntimeException('Could not find the Totals row of the itinerary.');
        $maxRow = $totals - 1;

        $ws->setCellValue('A' . $first, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new DateTime($start)));

        // Last day row already in the template (date or cascade formula in col A).
        $lastTpl = $first;
        for ($row = $first + 1; $row <= $maxRow; $row++) {
            $a = $ws->getCell('A' . $row)->getValue();
            if (is_string($a) && strpos($a, '=') === 0) $lastTpl = $row;
        }

        $flights = null; $acts = null;
        $cursor = $first;
        $lastRow = $lastTpl;
        foreach ((isset($in['days']) && is_array($in['days']) ? $in['days'] : []) as $k => $d) {
            if (isset($d['row_offset'])) $cursor = $first + (int)$d['row_offset'];
            $rep = max(1, (int)($d['repeat'] ?? 1));
            for ($j = 0; $j < $rep; $j++, $cursor++) {
                $row = $cursor;
                if ($row > $maxRow) throw new InvalidArgumentException('Too many days: row ' . $row . ' is past the itinerary table (last row ' . $maxRow . ').');
                if (isset($d['label']) && $d['label'] !== '') $ws->setCellValue('B' . $row, (string)$d['label']);

                // Flight: cost from the rate table (route) — never guessed.
                if (!empty($d['flight']) || isset($d['flight_cost_pp'])) {
                    $cost = null;
                    if (!empty($d['flight'])) {
                        if ($flights === null) $flights = calc_flight_rates($db, '', date('Y-m-d', strtotime($start . ' +' . ($row - $first) . ' days')));
                        $fr = calc_pick_rate($flights, $d['flight'], 'route', 'Flight route');
                        if ($fr['cost_pp'] === null) throw new InvalidArgumentException('Flight route "' . $fr['route'] . '" has no cost in the rate table — add it in Pricing → Flight Routes.');
                        $cost = $fr['cost_pp'];
                        if (isset($d['flight_cost_pp']) && (float)$d['flight_cost_pp'] !== $cost) {
                            $warnings[] = 'Row ' . $row . ': flight_cost_pp ' . $d['flight_cost_pp'] . ' ≠ rate table ' . $cost . ' (' . $fr['route'] . ') — used the rate table.';
                        }
                    } else {
                        $cost = calc_num($d['flight_cost_pp'], 'flight_cost_pp');
                        $known = bs_known_flight_costs();
                        if ($known !== null && !in_array($cost, $known, true)) {
                            $warnings[] = 'Row ' . $row . ': flight cost ' . $cost . ' pp is not in the flight rate table — pass "flight" (route) instead.';
                        }
                    }
                    $ws->setCellValue('C' . $row, '=' . calc_fmt_num($cost) . '*$B$1');
                }

                // Activity / transfer: amount (cost) in H, description in I.
                if (!empty($d['activity']) && is_array($d['activity'])) {
                    $a = $d['activity'];
                    $amount = null; $desc = (string)($a['desc'] ?? '');
                    if (!empty($a['rate'])) {
                        if ($acts === null) $acts = calc_activity_rates($db, '', $start);
                        $ar = calc_pick_rate($acts, $a['rate'], 'name', 'Activity rate');
                        if ($ar['cost'] === null) throw new InvalidArgumentException('Activity "' . $ar['name'] . '" has no cost in the rate table.');
                        $amount = $ar['cost'];
                        if ($desc === '') $desc = $ar['name'];
                    } else {
                        $amount = calc_num($a['amount'] ?? null, 'activity.amount');
                    }
                    $ws->setCellValue('H' . $row, $amount);
                    $ws->setCellValue('I' . $row, $desc);
                }

                if (array_key_exists('hotel', $d)) $ws->setCellValue('K' . $row, $d['hotel'] === null ? null : (string)$d['hotel']);
                if ($row > $lastRow) $lastRow = $row;
            }
        }

        // Date cascade A{first+1}..A{lastRow}: =SUM(A{n-1}+1), same format as A{first+1}.
        $fmtRef = 'A' . ($first + 1);
        for ($row = $first + 1; $row <= $lastRow; $row++) {
            $a = $ws->getCell('A' . $row)->getValue();
            if (!(is_string($a) && strpos($a, '=') === 0)) {
                $ws->setCellValue('A' . $row, '=SUM(A' . ($row - 1) . '+1)');
                $ws->duplicateStyle($ws->getStyle($fmtRef), 'A' . $row);
            }
        }
        $nDays = $lastRow - $first + 1;
        $endExpected = date('Y-m-d', strtotime($start . ' +' . ($nDays - 1) . ' days'));

        // ── 5) Guests, room type, pax text, flights ──────────────────────────
        $guests = isset($in['guests']) && is_array($in['guests']) ? $in['guests'] : [];
        $gRow = calc_find_row($ws, ['NAME', 'FIRST'], $totals, $totals + 40);
        $gRow = $gRow ? $gRow + 1 : 43;
        // Promoservice / Lamprati agencies (folder "(Agency-PS-Agent)") are Italian.
        $agType = preg_match('/-(PS|LAM)-/i', (string)$r['practice_code']) ? 'it' : '';
        $titles = [];
        foreach ($guests as $i => $g) {
            $row = $gRow + $i;
            $name = strtoupper(trim((string)($g['name'] ?? '')));
            if ($name === '') continue;
            $title = strtoupper(trim((string)($g['title'] ?? '')));
            $country = strtoupper(trim((string)($g['country'] ?? '')));
            if ($country === '' && $agType === 'it') { $country = 'ITALY'; $warnings[] = 'Country of ' . $name . ' inferred as ITALY (Italian agency).'; }
            $ws->setCellValue('A' . $row, $name);
            $ws->setCellValue('B' . $row, $title !== '' ? $title : null);
            $ws->setCellValue('F' . $row, $country !== '' ? $country : null);
            $titles[] = $title;
        }

        $roomType = trim((string)($in['room_type'] ?? ''));
        if ($roomType === '' && count($titles) === 2) {
            sort($titles);
            if ($titles === ['MR', 'MRS'] || $titles === ['MR', 'MS']) $roomType = '1 DBL';
        }
        $rtRow = calc_find_row($ws, ['ROOMS TYPE'], $totals, $totals + 40);
        if ($roomType !== '' && $rtRow) $ws->setCellValue('A' . ($rtRow + 1), $roomType);
        elseif ($roomType === '') $warnings[] = 'room_type not given and not inferable — A' . ($rtRow ? $rtRow + 1 : 37) . ' left as is.';

        $atc = trim((string)($in['adults_teen_chd'] ?? ''));
        if ($atc === '') {
            $atc = $adults . ' adults';
            if ($teens)    $atc .= ', ' . $teens . ' teen' . ($teens > 1 ? 's' : '');
            if ($children) $atc .= ', ' . $children . ' child' . ($children > 1 ? 'ren' : '');
        }
        $atRow = calc_find_row($ws, ['ADULTS/TEEN'], $totals, $totals + 40);
        if ($atRow) $ws->setCellValue('A' . ($atRow + 1), $atc);

        $arrRow = calc_find_row($ws, ['ARRIVAL', 'DETAIL'], $totals, $totals + 60);
        $depRow = calc_find_row($ws, ['DEPARTURE', 'DETAIL'], $totals, $totals + 60);
        if (!empty($in['arrival'])   && $arrRow) $ws->setCellValue('A' . ($arrRow + 1), (string)$in['arrival']);
        if (!empty($in['departure']) && $depRow) $ws->setCellValue('A' . ($depRow + 1), (string)$in['departure']);

        // ── 6) Recalculate + save (cached values written for the Hub parser) ──
        \PhpOffice\PhpSpreadsheet\Calculation\Calculation::getInstance($ss)->clearCalculationCache();
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($ss, 'Xlsx');
        $writer->setPreCalculateFormulas(true);
        $writer->save($tmpOut);
        $bytes = (string)file_get_contents($tmpOut);

        $expect = ['start' => $start, 'end' => $endExpected,
                   'mid' => isset($in['mid_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in['mid_date']) ? $in['mid_date'] : null];
        $result = [
            'ok'        => true,
            'dry_run'   => !$commit,
            'file'      => $path,
            'sheet'     => $keep,
            'removed_sheets' => $removed,
            'days'      => $nDays,
            'start'     => $start,
            'end'       => $endExpected,
            'warnings'  => $warnings,
        ];

        if (!$commit) {
            $result['verify'] = calc_verify($tmpOut, $expect);
            $result['message'] = 'Dry run — Calc built and verified on a copy, nothing uploaded. Resend with "confirm": true to write it.';
            return $result;
        }

        // ── 7) Upload (only if unchanged since read) + re-download + verify ──
        $meta = calc_dbx_upload_update($token, $path, $bytes, $dl['rev']);
        $back = calc_dbx_download($token, $path);
        file_put_contents($tmpOut, $back ? $back['bytes'] : '');
        $result['rev']     = (string)($meta['rev'] ?? '');
        $result['verify']  = calc_verify($tmpOut, $expect);
        $result['message'] = 'Calc written to Dropbox and re-verified.';
        return $result;
    } finally {
        @unlink($tmpIn); @unlink($tmpOut);
    }
}

/**
 * Post-write verification of a Calc file, read the way the Hub reads it (saved
 * values only): house rules + dates seen by the parser + key figures.
 */
function calc_verify(string $path, array $expect): array {
    $checks = sc_calc_rule_checks($path, ['mid' => $expect['mid'] ?? null, 'flight_costs' => bs_known_flight_costs()]);
    $v = sc_xlsx_cells($path);
    $trip = sc_trip_dates($v);
    if ($trip['start'] === $expect['start'] && $trip['end'] === $expect['end']) {
        $checks[] = ['level' => 'ok', 'msg' => 'Hub parser reads ' . sc_fmt($trip['start']) . ' → ' . sc_fmt($trip['end']) . '.'];
    } else {
        $checks[] = ['level' => 'error', 'msg' => 'Hub parser reads ' . sc_fmt($trip['start']) . ' → ' . sc_fmt($trip['end'])
                     . ', expected ' . sc_fmt($expect['start']) . ' → ' . sc_fmt($expect['end']) . '.'];
    }
    $num = function ($ref) use ($v) { return isset($v[$ref]) && is_numeric($v[$ref]) ? round((float)$v[$ref], 2) : null; };
    $errors = 0;
    foreach ($checks as $c) if ($c['level'] === 'error') $errors++;
    return [
        'passed'            => $errors === 0,
        'checks'            => $checks,
        'price_to_customer' => $num('F9'),
        'total_price'       => $num('F10'),
        'total_costs'       => $num('B7'),
        'margin'            => $num('B10'),
        'to_price_pp'       => $num('D10'),
    ];
}
