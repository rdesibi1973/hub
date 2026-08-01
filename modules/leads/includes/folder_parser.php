<?php
/**
 * Parse start/end dates from a Savannah Dropbox folder/practice_code name.
 *
 * Supported formats:
 *   Normal:  04_12APR_CustomerName_..._START12APR_END17APR2026_CK
 *   GRP:     GRP0206_CustomerName(Agency-Agent)  → start = day 02, month 06
 *
 * @param  string $folder
 * @return array ['start_date'=>'YYYY-MM-DD'|null, 'end_date'=>'YYYY-MM-DD'|null, 'start_ts'=>int|null]
 */
function parse_folder_dates(string $folder): array {
    static $months = [
        'JAN'=>1,'FEB'=>2,'MAR'=>3,'APR'=>4,'MAY'=>5,'JUN'=>6,
        'JUL'=>7,'AUG'=>8,'SEP'=>9,'OCT'=>10,'NOV'=>11,'DEC'=>12
    ];

    $result = ['start_date'=>null, 'end_date'=>null, 'start_ts'=>null];
    if (!$folder) return $result;

    $f = strtoupper($folder);

    // ── Normal format: _END{DD}{MMM}{YYYY} ────────────────────────────────────
    if (!preg_match('/_END(\d{1,2})([A-Z]{3})(\d{4})/', $f, $em)) return $result;
    $end_day = (int)$em[1];
    $end_mon = $months[$em[2]] ?? null;
    $end_yr  = (int)$em[3];
    if (!$end_mon) return $result;

    $result['end_date'] = sprintf('%04d-%02d-%02d', $end_yr, $end_mon, $end_day);

    if (preg_match('/_START(\d{1,2})([A-Z]{3})/', $f, $sm)) {
        $start_day = (int)$sm[1];
        $start_mon = $months[$sm[2]] ?? null;
        if ($start_mon) {
            $start_yr = ($start_mon > $end_mon + 1) ? $end_yr - 1 : $end_yr;
            $result['start_date'] = sprintf('%04d-%02d-%02d', $start_yr, $start_mon, $start_day);
            $result['start_ts']   = mktime(0, 0, 0, $start_mon, $start_day, $start_yr);
        }
    }
    return $result;
}

/**
 * Get the best folder name for date parsing.
 * For GRP bookings the date is encoded in group_folder (e.g. GRP0206_...).
 * For normal bookings use practice_code which has _START/_END tags.
 */
function get_date_folder(array $row): string {
    $gf = trim($row['group_folder'] ?? '');
    $pc = trim($row['practice_code'] ?? '');

    // If group_folder already has START/END tags, use it directly
    if ($gf && stripos($gf, '_START') !== false) return $gf;

    // For GRP bookings without START/END in group_folder (older records),
    // extract the full parent folder name from dropbox_url.
    // dropbox_url format: https://www.dropbox.com/home/001_Safari/PARENT_FOLDER/SUB_FOLDER
    if ($gf && stripos($gf, 'GRP') !== false) {
        $url = trim($row['dropbox_url'] ?? '');
        if ($url) {
            $path = urldecode(parse_url($url, PHP_URL_PATH));
            // Extract the segment after /001_Safari/
            if (preg_match('#/001_Safari/([^/]+)#i', $path, $m)) {
                $parent = $m[1];
                if (stripos($parent, '_START') !== false) return $parent;
            }
        }
        // Still no dates — fall back to group_folder (will yield no dates)
        return $gf;
    }

    // Normal booking: practice_code has _START/_END tags
    if ($pc) return $pc;
    return $gf;
}

/**
 * Extract the agent/agency part from a folder name.
 * Returns the content inside the first set of parentheses, e.g.
 *   "06_LauraBellocchi(Roberto-Drct)_START..." → "Roberto-Drct"
 * For GRP bookings uses group_folder (parent) where the agency tag lives.
 * Returns '' if no parentheses found.
 */
function folder_agency(array $row): string {
    // For GRP: agency is in group_folder; for normal: in practice_code
    $gf = trim($row['group_folder'] ?? '');
    $folder = $gf ?: trim($row['practice_code'] ?? '');
    if (!$folder) return '';
    if (preg_match('/\(([^)]+)\)/', $folder, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Derive payment_status from folder name suffix tags.
 * Used as fallback when payment_status column is null/empty.
 * Check longest tags first to avoid BALANCE matching inside BALANCE-CASH.
 */
function folder_payment_status(array $row): string {
    $folder = strtoupper(get_date_folder($row));
    if (!$folder) return '';
    $map = [
        '_BALANCE-CASH' => 'Balance-Cash',
        '_BALANCE_CASH' => 'Balance-Cash',
        '_BALANCE'      => 'Balance',
        '_DEPOSIT'      => 'Deposit',
        '_PAID'         => 'Paid',
        '_PROGRESS'     => 'Progress',
        '_PROVISIONAL'  => 'Provisional',
        '_CANCELLED'    => 'Cancelled',
    ];
    foreach ($map as $tag => $status) {
        if (strpos($folder, $tag) !== false) return $status;
    }
    return '';
}

/**
 * Strip a trailing status/document token from a confirmed-group folder name,
 * returning the "stem" that is stable across status changes.
 * e.g. "..._END09MAR2027_CONFIRMED" and "..._END09MAR2027_PROVISIONAL"
 *      both reduce to "..._END09MAR2027".
 */
function import_folder_stem(string $folder): string {
    $tags = ['_CONFIRMED','_PROVISIONAL','_CANCELLED','_BALANCE-CASH','_BALANCE_CASH',
             '_BALANCE','_DEPOSIT','_PROGRESS','_PAID','_CK'];
    $stem = trim($folder);
    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($tags as $tag) {
            if (strlen($stem) > strlen($tag)
                && strtoupper(substr($stem, -strlen($tag))) === $tag) {
                $stem    = substr($stem, 0, -strlen($tag));
                $changed = true;
                break;
            }
        }
    }
    return $stem;
}

/**
 * Parse a confirmed-group Dropbox folder name into request fields.
 *
 * Canonical shape (Diamante/Panorama example):
 *   03_02MAR_Panorama05_(Diamante-PS-Roberto)_START02MAR_END09MAR2027_CONFIRMED
 *
 * Parses generically — does not assume a specific TO/programme. The
 * parenthesised block is read as (TourOperator - agencyCode - handler);
 * 2-token and 1-token blocks are handled with a warning.
 *
 * @return array {
 *   ok, folder, stem, customer_name, tour_operator, agency_code, handler,
 *   start_date, end_date, period, status_token, status, errors[], warnings[]
 * }
 */
function parse_import_folder(string $folder): array {
    $out = [
        'ok'            => false,
        'folder'        => trim($folder),
        'stem'          => '',
        'customer_name' => '',
        'tour_operator' => '',
        'agency_code'   => '',
        'handler'       => '',
        'start_date'    => null,
        'end_date'      => null,
        'period'        => '',
        'status_token'  => '',
        'status'        => '',
        'payment_status'=> '',
        'errors'        => [],
        'warnings'      => [],
    ];

    $folder = trim($folder);
    if ($folder === '') {
        $out['errors'][] = 'Empty folder name.';
        return $out;
    }
    $out['stem'] = import_folder_stem($folder);

    // ── Dates (authoritative parser; handles year inheritance + boundary wrap) ──
    $dates = parse_folder_dates($folder);
    $out['start_date'] = $dates['start_date'];
    $out['end_date']   = $dates['end_date'];
    if (!$out['end_date']) {
        $out['errors'][] = 'No _END{DD}{MMM}{YYYY} tag found — cannot determine dates.';
    }

    // ── Parenthesised block: (TourOperator-agencyCode-handler) ─────────────────
    if (preg_match('/\(([^)]+)\)/', $folder, $pm)) {
        $block  = $pm[1];
        $tokens = array_values(array_filter(
            array_map('trim', explode('-', $block)),
            function ($t) { return $t !== ''; }
        ));
        $n = count($tokens);
        if ($n >= 3) {
            $out['tour_operator'] = $tokens[0];
            $out['agency_code']   = $tokens[1];
            $out['handler']       = $tokens[2];
        } elseif ($n === 2) {
            $out['tour_operator'] = $tokens[0];
            $out['handler']       = $tokens[1];
            $out['warnings'][]    = 'Parenthesis block "' . $block
                                  . '" has 2 tokens — assumed (TourOperator-handler). Verify agent & source.';
        } elseif ($n === 1) {
            $out['handler']    = $tokens[0];
            $out['warnings'][] = 'Parenthesis block "' . $block . '" has 1 token — assumed handler only.';
        }
    } else {
        $out['warnings'][] = 'No (TourOperator-agent-handler) block found in folder name.';
    }

    // ── Group / customer name: strip leading "NN_DDMON_", cut at "(" / "_START" ─
    $name = preg_replace('/^\s*\d{1,2}_\d{1,2}[A-Za-z]{3}_/', '', $folder);
    $ppos = strpos($name, '(');
    if ($ppos !== false) $name = substr($name, 0, $ppos);
    $spos = stripos($name, '_START');
    if ($spos !== false) $name = substr($name, 0, $spos);
    $name = trim($name, " _-");
    $out['customer_name'] = $name;
    if ($name === '') $out['errors'][] = 'Could not extract group/customer name.';

    // ── Status / payment-status suffix → HUB status ────────────────────────────
    // The confirmed workflow moves a group from _PROVISIONAL to a payment tag
    // (_BALANCE, _DEPOSIT, _PAID …), which means Booked. Longest tags first so
    // _BALANCE-CASH is not shadowed by _BALANCE. Matched anywhere near the end
    // (contains) so a trailing document marker like _CK does not hide the tag.
    $tagMap = [
        '_BALANCE-CASH' => ['Booked',      'Balance-Cash'],
        '_BALANCE_CASH' => ['Booked',      'Balance-Cash'],
        '_BALANCE'      => ['Booked',      'Balance'],
        '_DEPOSIT'      => ['Booked',      'Deposit'],
        '_PAID'         => ['Booked',      'Paid'],
        '_PROGRESS'     => ['Booked',      ''],
        '_CONFIRMED'    => ['Booked',      ''],
        '_PROVISIONAL'  => ['Provisional', ''],
        '_CANCELLED'    => ['Cancelled',   ''],
    ];
    $up = strtoupper($folder);
    foreach ($tagMap as $tag => $sp) {
        if (strpos($up, $tag) !== false) {
            $out['status_token']   = ltrim($tag, '_');
            $out['status']         = $sp[0];
            $out['payment_status'] = $sp[1];
            break;
        }
    }
    if ($out['status'] === '') {
        $out['status']     = 'Booked';
        $out['warnings'][] = 'No status/payment suffix (_BALANCE, _DEPOSIT, _PROVISIONAL …) — defaulting status to Booked.';
    }

    // ── Human-readable period ──────────────────────────────────────────────────
    if ($out['start_date'] && $out['end_date']) {
        $out['period'] = date('d M', strtotime($out['start_date']))
                       . ' – ' . date('d M Y', strtotime($out['end_date']));
    } elseif ($out['end_date']) {
        $out['period'] = date('d M Y', strtotime($out['end_date']));
    }

    $out['ok'] = empty($out['errors']);
    return $out;
}
