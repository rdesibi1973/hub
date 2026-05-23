<?php
/**
 * Parse start/end dates from Savannah Dropbox folder names.
 *
 * Format: 04_12APR_CustomerName_GRP1204(AGENCY-CH-Agent)_START12APR_END17APR2026_CK
 *
 * @return array ['start_date'=>'YYYY-MM-DD'|null, 'end_date'=>'YYYY-MM-DD'|null, 'start_ts'=>int|null]
 */
function parse_folder_dates(string $folder): array {
    static $months = [
        'JAN'=>1,'FEB'=>2,'MAR'=>3,'APR'=>4,'MAY'=>5,'JUN'=>6,
        'JUL'=>7,'AUG'=>8,'SEP'=>9,'OCT'=>10,'NOV'=>11,'DEC'=>12
    ];

    $result = ['start_date'=>null, 'end_date'=>null, 'start_ts'=>null];
    $f = strtoupper($folder);

    // END must be present — it carries the year
    if (!preg_match('/_END(\d{1,2})([A-Z]{3})(\d{4})/', $f, $em)) {
        return $result;
    }
    $end_day = (int)$em[1];
    $end_mon = $months[$em[2]] ?? null;
    $end_yr  = (int)$em[3];
    if (!$end_mon) return $result;

    $result['end_date'] = sprintf('%04d-%02d-%02d', $end_yr, $end_mon, $end_day);

    // START
    if (preg_match('/_START(\d{1,2})([A-Z]{3})/', $f, $sm)) {
        $start_day = (int)$sm[1];
        $start_mon = $months[$sm[2]] ?? null;
        if ($start_mon) {
            // If start month is significantly after end month, trip spans a year boundary
            $start_yr = ($start_mon > $end_mon + 1) ? $end_yr - 1 : $end_yr;
            $result['start_date'] = sprintf('%04d-%02d-%02d', $start_yr, $start_mon, $start_day);
            $result['start_ts']   = mktime(0, 0, 0, $start_mon, $start_day, $start_yr);
        }
    }

    return $result;
}
