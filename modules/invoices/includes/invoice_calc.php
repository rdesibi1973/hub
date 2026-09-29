<?php
/**
 * invoice_calc.php — the booking's Calc Excel (*_Calc.xlsx) seen from an invoice.
 *
 *   ic_read_request()  request → Calc file + sheet → TOT PAX, Price to customer, Tot price
 *   ic_check()         invoice lines vs the Calc: pax (B1 TOT PAX) and total (Tot price)
 *   ic_log()           records the check (and any bypass) for an invoice — shown on invoice_view.php
 *
 * File: the Payments rule (cp_pick_calc) — the booking folder's Calc, else, for a
 * GRP member's sub-folder, the group's Calc one level up.
 * Sheet: RECAP and sheets without "Tot price" are skipped. One left → it; else a
 * private booking takes the CONF sheet, then the customer's name; a GRP the
 * customer's name, then CONF. Still several → "ambiguous": the user picks one.
 * Keep PHP-7 style (no match / str_contains).
 */
require_once __DIR__ . '/../../leads/dropbox_constants.php';
require_once __DIR__ . '/../../leads/dropbox_helper.php';
require_once __DIR__ . '/../../leads/includes/calc_payments.php';   // cp_pick_calc, cp_list_files, cp_name_*
require_once __DIR__ . '/../../leads/includes/calc_reader.php';     // calc_rd_find / calc_rd_row / calc_rd_num

const IC_TOLERANCE = 1.0;   // $ difference accepted between invoice total and Tot price

/** Header values of one Calc sheet (cells map from sc_xlsx_sheet). */
function ic_sheet_values(array $v): array {
    $num = function ($label) use ($v) {
        $at = calc_rd_find($v, $label);
        return $at ? calc_rd_num($v, calc_rd_col_shift($at[0], 1), $at[1]) : null;
    };
    $paxOf = function ($label) use ($v) {
        $r = calc_rd_row($v, $label, 1, 15);
        return $r ? calc_rd_num($v, 'B', $r) : null;
    };
    $tot = $paxOf('tot pax');
    if ($tot === null) $tot = calc_rd_num($v, 'B', 1);
    return [
        'pax'         => $tot === null ? null : (int)round($tot),
        'adults'      => (int)($paxOf('adult') ?? 0),
        'teen'        => (int)($paxOf('teen') ?? 0),
        'child'       => (int)($paxOf('child') ?? 0),
        'price_pp'    => $num('Price to customer'),
        'total'       => $num('Tot price'),
    ];
}

/**
 * Read the Calc of a request row (needs id, customer_name, practice_code,
 * group_folder, dropbox_url). $wantSheet forces a sheet.
 * @return array status 'ok' | 'ambiguous' | 'nofolder' | 'nocalc' | 'error', with
 *   file, sheet, sheets (the choosable ones), msg and, when ok, the ic_sheet_values().
 */
function ic_read_request(array $r, string $wantSheet = ''): array {
    $out = ['status' => 'error', 'file' => '', 'sheet' => '', 'sheets' => [], 'msg' => ''];
    $rel = folder_rel_path($r);
    if ($rel === '') return ['status' => 'nofolder', 'msg' => 'The request has no Dropbox folder.'] + $out;

    try {
        $token = cp_retry(function () { return dropbox_get_access_token(); });
        $dirs = ['/' . $rel];
        $segs = explode('/', $rel);
        if (count($segs) > 2 && folder_is_booking_leaf($segs[count($segs) - 2])) {
            $dirs[] = '/' . implode('/', array_slice($segs, 0, -1));
        }
        $calc = null; $dir = null;
        foreach ($dirs as $d) {
            $calc = cp_pick_calc(cp_retry(function () use ($token, $d) { return cp_list_files($token, $d); }));
            if ($calc) { $dir = $d; break; }
        }
        if (!$calc) return ['status' => 'nocalc', 'msg' => 'No *_Calc.xlsx in the booking folder.'] + $out;
        $out['file'] = $calc['name'];

        $bytes = cp_retry(function () use ($token, $dir, $calc) { return dropbox_download_text($token, $dir . '/' . $calc['name']); });
        if ($bytes === null || $bytes === '') return ['status' => 'nocalc', 'msg' => 'Could not download ' . $calc['name'] . '.'] + $out;
    } catch (Throwable $e) {
        return ['msg' => 'Dropbox: ' . $e->getMessage()] + $out;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'invcalc_');
    file_put_contents($tmp, $bytes);
    try {
        $sheets = sc_xlsx_sheets($tmp);
        if (!$sheets) return ['msg' => $calc['name'] . ' is not a readable .xlsx file.'] + $out;
        $isGrp = sc_has_recap($sheets) || trim($r['group_folder'] ?? '') !== '';

        // Choosable sheets: not RECAP, with a "Tot price".
        $vals = [];
        foreach ($sheets as $s) {
            if (stripos($s, 'recap') !== false) continue;
            $sv = ic_sheet_values(sc_xlsx_sheet($tmp, $s)['v']);
            if ($sv['total'] !== null || $sv['price_pp'] !== null) $vals[$s] = $sv;
        }
        $names = array_keys($vals);
        $out['sheets'] = array_map('trim', $names);
        if (!$names) return ['msg' => 'No sheet with "Tot price" in ' . $calc['name'] . '.'] + $out;

        $pick = null;
        if (trim($wantSheet) !== '') {
            foreach ($names as $s) { if (strcasecmp(trim($s), trim($wantSheet)) === 0) $pick = $s; }
            if ($pick === null) return ['status' => 'ambiguous', 'msg' => 'Sheet "' . $wantSheet . '" not found — choose the sheet.'] + $out;
        } elseif (count($names) === 1) {
            $pick = $names[0];
        } else {
            $conf = array_values(array_filter($names, function ($s) { return stripos($s, 'conf') !== false; }));
            $tokens = cp_name_tokens((string)$r['customer_name']);
            $scores = [];
            foreach ($names as $s) $scores[$s] = cp_name_score($tokens, $s);
            $max = max($scores);
            $byName = $max > 0 ? array_keys(array_filter($scores, function ($n) use ($max) { return $n === $max; })) : [];
            $order = $isGrp ? [$byName, $conf] : [$conf, $byName];
            $order[] = array_values(array_intersect($conf, $byName));   // several CONF: narrow by name
            foreach ($order as $cand) { if (count($cand) === 1) { $pick = $cand[0]; break; } }
        }
        if ($pick === null) {
            return ['status' => 'ambiguous', 'msg' => 'Several possible sheets in ' . $calc['name'] . ' — choose the sheet.'] + $out;
        }
        return array_merge($out, ['status' => 'ok', 'sheet' => trim($pick), 'grp' => $isGrp], $vals[$pick]);
    } catch (Throwable $e) {
        return ['msg' => 'Could not read ' . $calc['name'] . ': ' . $e->getMessage()] + $out;
    } finally {
        @unlink($tmp);
    }
}

/** "$10,425" / "$10,425.50". */
function ic_money(float $n): string {
    return '$' . number_format($n, abs($n - round($n)) < 0.005 ? 0 : 2);
}

/** "N pax" written in a description, or null. */
function ic_desc_pax(string $desc): ?int {
    return preg_match('/(\d+)\s*pax\b/i', $desc, $m) ? (int)$m[1] : null;
}

/**
 * Invoice vs Calc. $items: [['description','quantity','unit_price','line_total'], …]
 * — the first line is the trip line. Returns the failed checks as key => message
 * (keys: excel, pax, total); [] when everything matches.
 */
function ic_check(array $calc, array $items, string $currency): array {
    if (($calc['status'] ?? '') !== 'ok') {
        return ['excel' => 'Ignore: Excel not checked — ' . (($calc['msg'] ?? '') ?: 'Calc not readable')];
    }
    $fail = [];
    $main = $items[0] ?? ['description' => '', 'quantity' => 0];

    // Pax: B1 TOT PAX = quantity and "N pax" of the trip line.
    $qty  = (float)$main['quantity'];
    $dPax = ic_desc_pax((string)$main['description']);
    if ($calc['pax'] === null) {
        $fail['pax'] = 'Ignore pax difference (Excel: TOT PAX not found, invoice ' . (int)$qty . ')';
    } elseif ((int)$qty !== $calc['pax'] || $dPax !== $calc['pax']) {
        if ($dPax === null)           $inv = 'qty ' . (int)$qty . ', no "N pax" in the description';
        elseif ($dPax === (int)$qty)  $inv = (string)(int)$qty;
        else                          $inv = 'qty ' . (int)$qty . ', description ' . $dPax . ' pax';
        $fail['pax'] = 'Ignore pax difference (Excel ' . $calc['pax'] . ', invoice ' . $inv . ')';
    }

    // Total: USD only — the Calc is in USD.
    if ($currency === 'USD') {
        $sum = 0.0;
        foreach ($items as $it) $sum += (float)$it['line_total'];
        if ($calc['total'] === null) {
            $fail['total'] = 'Ignore total difference (Excel: Tot price not found, invoice ' . ic_money($sum) . ')';
        } elseif (abs($sum - $calc['total']) > IC_TOLERANCE) {
            $fail['total'] = 'Ignore total difference (Excel ' . ic_money($calc['total']) . ', invoice ' . ic_money($sum) . ')';
        }
    }
    return $fail;
}

/** Create the log table on first use. */
function ic_ensure_table(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS invoice_excel_checks (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        invoice_id  INT UNSIGNED NOT NULL,
        request_id  INT UNSIGNED NULL,
        calc_file   VARCHAR(255) NULL,
        calc_sheet  VARCHAR(255) NULL,
        excel_pax   INT NULL,
        excel_total DECIMAL(12,2) NULL,
        from_excel  TINYINT(1) NOT NULL DEFAULT 0,
        bypassed    TEXT NULL,
        created_by  INT UNSIGNED NULL,
        created_at  DATETIME NOT NULL,
        KEY (invoice_id)
    ) DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** Record the check of a new invoice; $bypassed = the ignored messages. */
function ic_log(PDO $db, int $invoiceId, ?int $requestId, array $calc, array $bypassed, bool $fromExcel, ?int $uid): void {
    ic_ensure_table($db);
    $db->prepare("INSERT INTO invoice_excel_checks
        (invoice_id, request_id, calc_file, calc_sheet, excel_pax, excel_total, from_excel, bypassed, created_by, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,NOW())")
       ->execute([$invoiceId, $requestId, ($calc['file'] ?? '') ?: null, ($calc['sheet'] ?? '') ?: null,
                  $calc['pax'] ?? null, $calc['total'] ?? null, $fromExcel ? 1 : 0,
                  $bypassed ? implode("\n", $bypassed) : null, $uid]);
}
