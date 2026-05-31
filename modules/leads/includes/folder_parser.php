<?php
/**
 * Parse start/end dates from a Savannah Dropbox folder/practice_code name.
 *
 * Supported formats:
 *   Normal:  04_12APR_CustomerName_..._START12APR_END17APR2026_CK
 *   GRP:     GRP0206_CustomerName(Agency-Agent)  → start = day 02, month 06
 *
 * @param  string $folder
 * @param  int|null $hint_year  Year hint (e.g. from confirmation_date) for GRP format
 * @return array ['start_date'=>'YYYY-MM-DD'|null, 'end_date'=>'YYYY-MM-DD'|null, 'start_ts'=>int|null]
 */
function parse_folder_dates(string $folder, ?int $hint_year = null): array {
    static $months = [
        'JAN'=>1,'FEB'=>2,'MAR'=>3,'APR'=>4,'MAY'=>5,'JUN'=>6,
        'JUL'=>7,'AUG'=>8,'SEP'=>9,'OCT'=>10,'NOV'=>11,'DEC'=>12
    ];

    $result = ['start_date'=>null, 'end_date'=>null, 'start_ts'=>null];
    if (!$folder) return $result;

    $f = strtoupper($folder);

    // ── GRP format: GRP{DDMM}_... ─────────────────────────────────────────────
    if (preg_match('/^GRP(\d{2})(\d{2})_/i', $folder, $gm)) {
        $day = (int)$gm[1];
        $mon = (int)$gm[2];
        if ($day >= 1 && $day <= 31 && $mon >= 1 && $mon <= 12) {
            $yr = $hint_year ?? (int)date('Y');
            $ts = mktime(0, 0, 0, $mon, $day, $yr);
            $result['start_date'] = sprintf('%04d-%02d-%02d', $yr, $mon, $day);
            $result['start_ts']   = $ts;
        }
        return $result;
    }

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
    // If group_folder starts with GRP it carries the arrival date
    if ($gf && stripos($gf, 'GRP') === 0) return $gf;
    // Normal booking: practice_code has START/END
    $pc = trim($row['practice_code'] ?? '');
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
