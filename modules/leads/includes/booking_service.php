<?php
/**
 * booking_service.php — booking workflow logic shared by the Hub pages and the
 * Agent API (agent_api.php).
 *
 *   Create request  : bs_create_request()            (request_add.php, API create_request)
 *   Copy programs   : bs_copy_programs(), bs_next_prognum()   (request_view.php, API copy_program)
 *   Confirm Safari  : bs_confirm_plan() → bs_confirm_checks() / bs_confirm_commit()
 *                                                     (backoffice.php, API confirm_*)
 *   Rollback        : bs_rollback()                   (backoffice.php, API rollback_booking)
 *   Booking email   : bo_booking_email(), bs_send_mail()     (backoffice.php, ajax_booking_email.php, API)
 *
 * Requires the leads config.php (db(), DROPBOX_BASE_PATH, …) to be loaded first.
 * Dropbox helpers are loaded lazily, as the pages did before.
 *
 * Keep PHP-7 compatible (no match / arrow functions / str_contains).
 */

require_once __DIR__ . '/folder_parser.php';
require_once __DIR__ . '/safari_check.php';

// ════════════════════════════════════════════════════════════════════════════
//  Paths / URLs
// ════════════════════════════════════════════════════════════════════════════

/** Rebuild a Dropbox web URL from an API path_display ('/001_Safari/Foo/Bar'). */
function bo_url_from_path(string $path): string {
    $enc = implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    return 'https://www.dropbox.com/home/' . $enc;
}

/** API path ('/001_Safari/…') from a stored dropbox_url, or '' if unparseable. */
function bo_path_from_url(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    $p = parse_url($url, PHP_URL_PATH);
    if (!$p) return '';
    $p = urldecode($p);
    $p = preg_replace('#^/?home/#i', '', ltrim($p, '/')); // strip leading /home/
    $p = trim((string)$p, '/');
    return $p !== '' ? '/' . $p : '';
}

/**
 * Current Dropbox path of a request's folder, e.g. '/001_Safari/00_2026/Foo'.
 * Trusts dropbox_url (kept fresh by confirm / rename / re-group / "Re-link folder"),
 * so a folder moved by hand into an archive still resolves once re-linked.
 * Falls back to the group/practice construction when no URL is stored.
 */
function req_folder_path(array $r): string {
    $leaf = trim($r['practice_code'] ?? '');
    if (!empty($r['dropbox_url']) && preg_match('#dropbox\.com/home(/.*)?$#i', $r['dropbox_url'], $m)) {
        $p = rtrim(urldecode($m[1] ?? ''), '/');
        if ($p !== '') {
            // If the link points only to a parent container (…/001_Safari or
            // …/001_Safari/00_2026), append the booking folder so it opens the
            // real folder. A link already ending in the booking folder is kept.
            $segs = explode('/', ltrim($p, '/'));
            $last = (string)end($segs);
            if ($leaf !== '' && !folder_is_booking_leaf($last)) {
                $p .= '/' . $leaf;
            }
            return $p;
        }
    }
    if (!empty($r['group_folder']) && $leaf !== '') {
        return '/001_Safari/' . $r['group_folder'] . '/' . $leaf;
    }
    return '';
}

// ════════════════════════════════════════════════════════════════════════════
//  Create request
// ════════════════════════════════════════════════════════════════════════════

/** "patrizia fiorini" → "PatriziaFiorini"; compact CamelCase is kept as-is. */
function bs_camel_case(string $name): string {
    $name = trim($name);
    if (strpos($name, ' ') === false && strpos($name, '-') === false) return $name;
    return implode('', array_map('ucfirst', array_map('mb_strtolower', preg_split('/[\s\-]+/', $name))));
}

/** Folder name for a new request, e.g. "PatriziaFiorini(TVT-PS-Roberto)". */
function bs_request_folder_name(PDO $db, string $customerName, string $channel, $agencyId, $agentId): string {
    $agStmt = $db->prepare("SELECT name FROM agents WHERE id = ? LIMIT 1");
    $agStmt->execute([$agentId]);
    $agRow     = $agStmt->fetch(PDO::FETCH_ASSOC);
    $agentName = $agRow ? str_replace(' ', '', $agRow['name']) : 'Unknown';

    $agencyNome = '';
    if ($channel === 'agency' && $agencyId) {
        $agencyStmt = $db->prepare("SELECT nome, short_name FROM agencies WHERE id = ? LIMIT 1");
        $agencyStmt->execute([$agencyId]);
        $agencyRow = $agencyStmt->fetch(PDO::FETCH_ASSOC);
        if ($agencyRow) {
            $raw        = $agencyRow['short_name'] ?: $agencyRow['nome'];
            $agencyNome = preg_replace('/[^\w\-]/', '', $raw);
        }
    }

    $namePart = bs_camel_case($customerName);
    switch ($channel) {
        case 'agency': $suffix = "({$agencyNome}-{$agentName})"; break;
        case 'sb':     $suffix = "({$agentName}-SB)";            break;
        case 'other':  $suffix = "({$agentName})";               break;
        default:       $suffix = "({$agentName}-Drct)";          break;
    }
    return $namePart . $suffix;
}

/**
 * Create a request: duplicate check → Dropbox folder (+ subfolders, CustomerInfo.txt)
 * → DB row → optional agent notification.
 *
 * $v keys: date_received, customer_name, email, whatsapp, source, channel, agency_id,
 *          agent_id, destination, period, pax, status, value_usd, commission_pct,
 *          commission_usd, date_paid, initial_request, notes  (strings, '' = empty)
 * $opt:    dropbox_skip (bool), dup_override (bool), notify_agent (bool),
 *          creator_user_id (int)
 *
 * Returns ['ok'=>bool, 'errors'=>[], 'error_code'=>''|'validation'|'duplicate'|'folder_exists'|'dropbox',
 *          'dup_candidates'=>[], 'request_id', 'folder_name', 'dropbox_path', 'notify'=>['sent','error']].
 * Validation of caller-specific rules (staff restrictions, status list) stays with the caller.
 */
function bs_create_request(PDO $db, array $v, array $opt = []): array {
    $dropboxSkip = !empty($opt['dropbox_skip']);
    $out = ['ok' => false, 'errors' => [], 'error_code' => '', 'dup_candidates' => [],
            'request_id' => 0, 'folder_name' => '', 'dropbox_path' => '',
            'notify' => ['sent' => false, 'error' => null]];

    foreach (['date_received','customer_name','email','whatsapp','source','channel','agency_id','agent_id',
              'destination','period','pax','status','value_usd','commission_pct','commission_usd',
              'date_paid','initial_request','notes'] as $k) {
        $v[$k] = trim((string)($v[$k] ?? ''));
    }
    if ($v['source'] === '')  $v['source'] = 'Email';
    if ($v['status'] === '')  $v['status'] = 'Inquiry';
    if ($v['channel'] === '') $v['channel'] = 'agency';
    if ($v['value_usd'] !== '' && $v['commission_pct'] !== '') {
        $v['commission_usd'] = (string)round((float)$v['value_usd'] * (float)$v['commission_pct'] / 100, 2);
    }

    // ── Validate ──────────────────────────────────────────────────────────────
    $errors = [];
    if (!$v['customer_name'])   $errors[] = 'Customer name is required.';
    if (!$v['date_received'])   $errors[] = 'Date received is required.';
    if (!$dropboxSkip && !$v['initial_request']) $errors[] = 'Initial Request is required.';
    if (!$v['agent_id'])        $errors[] = 'Please select an agent.';
    if ($v['channel'] === 'agency' && !$v['agency_id']) $errors[] = 'Please select an agency.';
    if ($errors) { $out['errors'] = $errors; $out['error_code'] = 'validation'; return $out; }

    // ── Duplicate check BEFORE inserting (same checks as Incoming) ────────────
    // Agency requests: the same agency contact sends many requests, so a shared
    // email / WhatsApp is not a duplicate — check the name only.
    if (empty($opt['dup_override'])) {
        require_once __DIR__ . '/dup_check.php';
        $isAgency = $v['channel'] === 'agency';
        $dups = [];
        foreach (find_duplicate_candidates($db, $v['customer_name'],
                     $isAgency ? '' : $v['email'], $isAgency ? '' : $v['whatsapp']) as $c) {
            if ($c['severity'] !== 'weak') $dups[] = $c;
        }
        if ($dups) {
            $out['dup_candidates'] = $dups;
            $out['errors'][]  = 'Possible duplicate found — review the matches below, then tick "Create anyway" if this really is a new booking.';
            $out['error_code'] = 'duplicate';
            return $out;
        }
    }

    // ── Folder name ───────────────────────────────────────────────────────────
    $folderName    = bs_request_folder_name($db, $v['customer_name'], $v['channel'], $v['agency_id'], $v['agent_id']);
    $dropboxPath   = DROPBOX_BASE_PATH . '/' . $folderName;
    $dropboxWebUrl = 'https://www.dropbox.com/home' . $dropboxPath;
    $out['folder_name']  = $folderName;
    $out['dropbox_path'] = $dropboxPath;

    // ── Dropbox folder (unless "already exists" flag is set) ──────────────────
    if (!$dropboxSkip) {
        require_once __DIR__ . '/../dropbox_helper.php';
        try {
            $token = dropbox_get_access_token();
            dropbox_create_folder($token, $dropboxPath, true); // throwOnConflict=true

            foreach (['bookings','complain','flights','guestcomments','insurance',
                      'IntFlights','invoices','mails','old','passports','vouchers'] as $sub) {
                try { dropbox_create_folder($token, $dropboxPath . '/' . $sub); }
                catch (RuntimeException $e) { /* non-blocking */ }
            }

            $waDigits = preg_replace('/\D/', '', $v['whatsapp']);
            $txtContent =
                "CUSTOMER:\r\n\r\n"
              . "Name:        " . $v['customer_name'] . "\r\n"
              . "Email:       " . $v['email'] . "\r\n"
              . "WhatsApp:    " . $v['whatsapp'] . "\r\n\r\n\r\n"
              . "REQUEST DETAILS:\r\n\r\n"
              . $v['initial_request'] . "\r\n\r\n\r\n"
              . "WHATSAPP link\r\n"
              . "Add phone number with international code without + or spaces and use the following link to chat with customer on whatsapp web\r\n"
              . "https://web.whatsapp.com/send?phone=" . $waDigits . "\r\n\r\n"
              . "CUSTOMERS FULL NAMES:\r\n\r\n\r\n\r\n"
              . "ARRIVAL/DEPARTURE DETAILS - FLIGHTS:\r\n\r\n\r\n\r\n\r\n\r\n"
              . "DIETARY RESTRICTIONS:\r\n\r\n\r\n\r\n"
              . "NOTES:\r\n\r\n";
            dropbox_upload_text($token, $dropboxPath . '/CustomerInfo.txt', $txtContent);

        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'already exists') !== false) {
                $out['errors'][]   = 'Dropbox folder already exists: ' . $folderName . ' — check for duplicates before proceeding.';
                $out['error_code'] = 'folder_exists';
            } else {
                $out['errors'][]   = 'Dropbox error: ' . $msg;
                $out['error_code'] = 'dropbox';
            }
            return $out;
        }
    }

    // ── INSERT ────────────────────────────────────────────────────────────────
    $db->prepare("
        INSERT INTO requests
          (practice_code, date_received, customer_name, email, whatsapp, source, agent_id,
           destination, period, pax, status, value_usd, commission_pct, commission_usd,
           date_paid, initial_request, dropbox_url, notes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $folderName,
        $v['date_received'],
        $v['customer_name'],
        $v['email']           ?: null,
        $v['whatsapp']        ?: null,
        $v['source'],
        $v['agent_id']        ?: null,
        $v['destination']     ?: null,
        $v['period']          ?: null,
        $v['pax']             ?: null,
        $v['status'],
        $v['value_usd']       !== '' ? $v['value_usd']      : null,
        $v['commission_pct']  !== '' ? $v['commission_pct'] : null,
        $v['commission_usd']  !== '' ? $v['commission_usd'] : null,
        $v['date_paid']       ?: null,
        $dropboxSkip ? null : ($v['initial_request'] ?: null),
        $dropboxWebUrl,
        $v['notes']           ?: null,
    ]);
    $out['request_id'] = (int)$db->lastInsertId();
    $out['ok'] = true;

    // ── Notify agent (non-fatal) ──────────────────────────────────────────────
    require_once __DIR__ . '/../notifications.php';
    try {
        $out['notify'] = notify_agent_new_request(
            $db, (int)$v['agent_id'], (int)($opt['creator_user_id'] ?? 0),
            $out['request_id'], $v['customer_name'], $folderName, !empty($opt['notify_agent'])
        );
    } catch (Throwable $e) {
        $out['notify'] = ['sent' => false, 'error' => 'Notification failed: ' . $e->getMessage()];
    }
    return $out;
}

// ════════════════════════════════════════════════════════════════════════════
//  Standard programs
// ════════════════════════════════════════════════════════════════════════════

/** Program groups: group label => [program label => [['src','dst'], …]]. */
function bs_std_programs(): array {
    static $groups = null;
    if ($groups === null) $groups = require __DIR__ . '/std_programs.php';
    return $groups;
}

/** Next free ProgNumber in a Dropbox folder: max existing NN_ prefix + 1, 2 digits. */
function bs_next_prognum(string $token, string $destDir): string {
    $max = 0;
    foreach (dropbox_list_files($token, $destDir) as $fn) {
        if (preg_match('/^(\d{1,3})_/', $fn, $mm)) {
            $n = (int)$mm[1];
            if ($n > $max) $max = $n;
        }
    }
    return str_pad((string)($max + 1), 2, '0', STR_PAD_LEFT);
}

/**
 * Copy standard program templates into a request's folder, renamed
 * {ProgNumber}_{FolderName}_{dst}. $prognum '' = next free number.
 * Returns ['ok','msg','prognum','summary','copied','skipped','missing','unknown'].
 */
function bs_copy_programs(PDO $db, int $reqId, string $prognum, array $programs): array {
    $prognum = trim($prognum);
    if ($prognum !== '' && preg_match('#[\\\\/:*?"<>|]#', $prognum)) {
        return ['ok' => false, 'msg' => 'Enter a valid ProgNumber (no \\ / : * ? " < > |).'];
    }
    if (!$programs) return ['ok' => false, 'msg' => 'Select at least one program.'];

    $rq = $db->prepare("SELECT id, practice_code, group_folder, dropbox_url, start_date FROM requests WHERE id=?");
    $rq->execute([$reqId]);
    $rr = $rq->fetch(PDO::FETCH_ASSOC);
    if (!$rr) return ['ok' => false, 'msg' => 'Request not found.'];

    // Destination = the request's real Dropbox folder — works whether the booking
    // is still pre-confirmation (/YYYY/…) or already confirmed (/001_Safari/…).
    $destDir    = req_folder_path($rr);
    $folderName = trim($rr['practice_code'] ?? '');
    if ($destDir === '' || $folderName === '') {
        return ['ok' => false, 'msg' => 'This request has no Dropbox folder yet — cannot copy programs.'];
    }

    // {YEAR} for the group-template sources: folder year if pre-confirmation,
    // else the booking's start year, else current year.
    $seg = explode('/', ltrim($destDir, '/'));
    if (isset($seg[0]) && preg_match('/^\d{4}$/', $seg[0]))          $year = $seg[0];
    elseif (!empty($rr['start_date']) && preg_match('/^(\d{4})/', $rr['start_date'], $ym)) $year = $ym[1];
    else                                                             $year = date('Y');

    // Flatten the grouped program map by label.
    $byLabel = [];
    foreach (bs_std_programs() as $progs) foreach ($progs as $label => $files) $byLabel[$label] = $files;

    require_once __DIR__ . '/../dropbox_helper.php';
    $token = dropbox_get_access_token();
    if ($prognum === '') $prognum = bs_next_prognum($token, $destDir);

    $copied = []; $skipped = []; $missing = []; $unknown = [];
    foreach ($programs as $label) {
        if (!isset($byLabel[$label])) { $unknown[] = $label; continue; }
        foreach ($byLabel[$label] as $f) {
            $src  = str_replace('{YEAR}', $year, $f['src']);
            $base = $prognum . '_' . $folderName . '_' . $f['dst'];
            $res  = dropbox_copy_file($token, $src, $destDir . '/' . $base);
            if      ($res === 'copied') $copied[]  = $base;
            elseif  ($res === 'exists') $skipped[] = $base;
            else                        $missing[] = basename($src); // src_missing
        }
    }

    $parts = [];
    if ($copied)  $parts[] = count($copied) . ' copied';
    if ($skipped) $parts[] = count($skipped) . ' skipped (already there)';
    if ($missing) $parts[] = count($missing) . ' template(s) missing';
    if ($unknown) $parts[] = count($unknown) . ' unknown';
    return [
        'ok'       => true,
        'prognum'  => $prognum,
        'folder'   => $destDir,
        'summary'  => $parts ? implode(', ', $parts) : 'Nothing to do',
        'copied'   => $copied,
        'skipped'  => $skipped,
        'missing'  => $missing,
        'unknown'  => $unknown,
    ];
}

// ════════════════════════════════════════════════════════════════════════════
//  Confirm Safari
// ════════════════════════════════════════════════════════════════════════════

/** label => [folder suffix inserted inside (), requests.destination value]. */
function bs_confirm_destinations(): array {
    return [
        'Safari / Safari & Beach — Tanzania' => ['',             'Tanzania'],
        'Trekking Kilimanjaro / Meru'        => ['-TREK',        'Tanzania'],
        'Only Zanzibar'                      => ['-ZNZ',         'Tanzania'],
        'Safari Kenya-Tanzania'              => ['-TZ-KENYA',    'Kenya'],
        'Safari Kenya'                       => ['-KENYA',       'Kenya'],
        'Uganda'                             => ['-UGANDA',      'Uganda'],
        'Namibia'                            => ['-NAMIBIA',     'Namibia'],
        'South Africa'                       => ['-SOUTHAFRICA', 'South Africa'],
        'Rwanda'                             => ['-RWANDA',      'Rwanda'],
        'Madagascar'                         => ['-MADAGASCAR',  'Madagascar'],
        'Botswana'                           => ['-BOTSWANA',    'Botswana'],
        'Staff / Internal'                   => ['-STAFF',       'Staff'],
    ];
}

function bs_confirm_months(): array {
    return ['JAN'=>'01','FEB'=>'02','MAR'=>'03','APR'=>'04','MAY'=>'05','JUN'=>'06',
            'JUL'=>'07','AUG'=>'08','SEP'=>'09','OCT'=>'10','NOV'=>'11','DEC'=>'12'];
}

/**
 * Build the confirmed folder name from the entered dates, mirroring the Java
 * "Confirm Safari" rules exactly:
 *   {MM}_{START}_{custname}_START{START}[_MIDT{MID}][_MIDT{MID2}]_END{END}_PROGRESS
 * with the destination suffix inserted before the last ')'. Hard-format errors
 * (bad month / wrong length) are returned in $errors and yield null.
 *
 * $start/$mid/$mid2 are DDMMM (e.g. 05JAN); $end is DDMMMYYYY (e.g. 18JAN2026).
 * "NA" or empty middles are skipped.
 */
function bo_confirmed_name(string $custname, string $start, string $mid, string $mid2,
                           string $end, string $destSuffix, array $months, array &$errors): ?string {
    $errors = [];
    $custname = trim($custname);
    $start = strtoupper(trim($start));
    $mid   = strtoupper(trim($mid));
    $mid2  = strtoupper(trim($mid2));
    $end   = strtoupper(trim($end));

    $monOf = function (string $s) use ($months): string {
        foreach ($months as $abbr => $num) { if (strpos($s, $abbr) !== false) return $num; }
        return '00';
    };

    // Start date: DDMMM, valid month, exactly 5 chars.
    $month = $monOf($start);
    if ($month === '00') { $errors[] = 'Start Date month is wrong — use DDMMM, e.g. 05JAN.'; }
    if (strpos($start, 'NA') !== false || strlen($start) !== 5) {
        $errors[] = 'Start Date must be DDMMM (5 characters), e.g. 05JAN.';
    }

    // End date: DDMMMYYYY, valid month, exactly 9 chars.
    $endMonth = $monOf($end);
    if ($endMonth === '00') { $errors[] = 'End Date month is wrong — use DDMMMYYYY, e.g. 18JAN2026.'; }
    if (strpos($end, 'NA') !== false || strlen($end) !== 9) {
        $errors[] = 'End Date must be DDMMMYYYY (9 characters), e.g. 18JAN2026.';
    }

    // Middle dates (optional): DDMMM. Middle 2 only considered if middle 1 is set.
    $hasMid  = ($mid  !== '' && strpos($mid, 'NA')  === false);
    $hasMid2 = $hasMid && ($mid2 !== '' && strpos($mid2, 'NA') === false);
    if ($hasMid  && strlen($mid)  !== 5) { $errors[] = 'Middle Date must be DDMMM (5 characters), e.g. 10JAN.'; }
    if ($hasMid2 && strlen($mid2) !== 5) { $errors[] = 'Middle Date 2 must be DDMMM (5 characters), e.g. 12JAN.'; }

    if ($errors) return null;

    $name = $month . '_' . $start . '_' . $custname . '_START' . $start;
    if ($hasMid)  $name .= '_MIDT' . $mid;
    if ($hasMid2) $name .= '_MIDT' . $mid2;
    $name .= '_END' . $end . '_PROGRESS';

    if ($destSuffix !== '') {
        $close = strrpos($name, ')');
        if ($close !== false) {
            $name = substr($name, 0, $close) . $destSuffix . substr($name, $close);
        }
    }
    return $name;
}

/** Insert "_GRP{code}" before the first '(' of the customer folder (Java CREATE rule). */
function bo_grp_insert(string $cust, string $code): string {
    $paren = strpos($cust, '(');
    return $paren !== false
        ? substr($cust, 0, $paren) . '_GRP' . $code . substr($cust, $paren)
        : $cust . '_GRP' . $code;
}

/** Valid GRP code = 4 digits DDMM, day 1-31, month 1-12 (mirrors the Java check). */
function bo_grp_code_valid(string $code): bool {
    if (!preg_match('/^\d{4}$/', $code)) return false;
    $d = (int)substr($code, 0, 2); $m = (int)substr($code, 2);
    return $d >= 1 && $d <= 31 && $m >= 1 && $m <= 12;
}

/** 'DDMM' from a Y-m-d start date, or '' if not parseable. */
function bo_grp_code_from_ymd(?string $ymd): string {
    if (!$ymd) return '';
    $ts = strtotime($ymd);
    return $ts !== false ? date('dm', $ts) : '';
}

/** Existing GRP folder names under /001_Safari whose name contains "GRP{code}". */
function bo_find_grps(string $token, string $code): array {
    if ($code === '' || !function_exists('dropbox_list_folder')) return [];
    try { $all = dropbox_list_folder($token, '/001_Safari'); }
    catch (Throwable $e) { return []; }
    $needle = 'GRP' . $code;
    $out = [];
    foreach ($all as $name) { if (stripos($name, $needle) !== false) $out[] = $name; }
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/**
 * Work out a Confirm Safari: target folder name(s), format errors, current Dropbox
 * location, existing GRPs and whether the confirmation is blocked. Nothing is moved.
 *
 * $in keys: start, mid, mid2 (DDMMM), end (DDMMMYYYY), dest (destination label),
 *           grp (NONE|CREATE|ADD), grpcode (DDMM), grpmain (chosen existing GRP).
 *
 * Returns the plan array; 'found' = false (+ 'error') when the request/folder is
 * unusable, 'errs' = hard format errors (nothing else is resolved in that case).
 */
function bs_confirm_plan(PDO $db, int $reqId, array $in): array {
    $months = bs_confirm_months();
    $dests  = bs_confirm_destinations();

    $fStart   = strtoupper(trim((string)($in['start'] ?? '')));
    $fMid     = strtoupper(trim((string)($in['mid']   ?? '')));
    $fMid2    = strtoupper(trim((string)($in['mid2']  ?? '')));
    $fEnd     = strtoupper(trim((string)($in['end']   ?? '')));
    $fDestKey = (string)($in['dest'] ?? '');
    $destPair   = isset($dests[$fDestKey]) ? $dests[$fDestKey] : ['', ''];
    $destSuffix = $destPair[0];
    $destValue  = $destPair[1];

    $grpAction = strtoupper(trim((string)($in['grp'] ?? 'NONE')));
    if (!in_array($grpAction, ['NONE', 'CREATE', 'ADD'], true)) $grpAction = 'NONE';
    $grpCode = preg_replace('/\D/', '', (string)($in['grpcode'] ?? ''));   // digits only (DDMM)
    $grpMain = trim((string)($in['grpmain'] ?? ''));                       // chosen existing GRP (ADD)

    $plan = ['found' => false, 'error' => '', 'request_id' => $reqId, 'r' => null, 'old_folder' => '',
             'grp_action' => $grpAction, 'grp_code' => $grpCode, 'grp_main' => $grpMain,
             'dest_value' => $destValue, 'errs' => [], 'new_name' => null, 'sub_name' => null,
             'member_name' => null, 'pd' => ['start_date' => null, 'end_date' => null],
             'token' => null, 'cur_path' => null, 'dbx_err' => '', 'grps' => [],
             'block' => false, 'can_override' => false, 'block_msg' => '', 'fields' => []];

    $stmt = $db->prepare("SELECT id, customer_name, practice_code, group_folder, dropbox_url, status, payment_status, agent_id
                          FROM requests WHERE id = ?");
    $stmt->execute([$reqId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) { $plan['error'] = 'Request not found.'; return $plan; }
    $oldFolder = trim($r['practice_code'] ?? '');
    if ($oldFolder === '') { $plan['error'] = 'This request has no folder (practice_code) to confirm.'; return $plan; }
    $plan['found'] = true; $plan['r'] = $r; $plan['old_folder'] = $oldFolder;

    // GRP code defaults to DDMM derived from the Start date when left blank.
    if ($grpAction !== 'NONE' && $grpCode === '' && strlen($fStart) >= 5 && ctype_digit(substr($fStart, 0, 2))) {
        foreach ($months as $ab => $num) {
            if (strpos($fStart, $ab) !== false) { $grpCode = substr($fStart, 0, 2) . $num; break; }
        }
    }

    // ── Build the target name(s) + validate per booking type ──────────────────
    $errs = [];
    if ($grpAction === 'ADD') {
        // No dates: the customer folder keeps its name and moves into the existing GRP.
        $plan['member_name'] = $oldFolder;
        if ($grpCode === '')                  $errs[] = 'Enter the GRP code (DDMM) to find the existing group.';
        elseif (!bo_grp_code_valid($grpCode)) $errs[] = 'GRP code must be 4 digits DDMM (e.g. 2306 = 23 Jun).';
    } else {
        $custForName = $oldFolder;
        if ($grpAction === 'CREATE') {
            if (!bo_grp_code_valid($grpCode)) $errs[] = 'GRP code must be 4 digits DDMM (e.g. 2306 = 23 Jun).';
            else $custForName = bo_grp_insert($oldFolder, $grpCode);
        }
        $buildErr = [];
        $plan['new_name'] = bo_confirmed_name($custForName, $fStart, $fMid, $fMid2, $fEnd, $destSuffix, $months, $buildErr);
        $errs = array_merge($errs, $buildErr);
        if ($plan['new_name'] !== null) { $plan['pd'] = parse_folder_dates($plan['new_name']); $plan['sub_name'] = $oldFolder; }
    }
    $plan['grp_code'] = $grpCode;
    $plan['errs']     = $errs;
    $plan['fields']   = [
        'fStart'    => $fStart, 'fMid' => $fMid, 'fMid2' => $fMid2, 'fEnd' => $fEnd,
        'fDestKey'  => $fDestKey, 'grpAction' => $grpAction, 'grpCode' => $grpCode, 'grpMain' => $grpMain,
    ];
    if ($errs) return $plan;

    require_once __DIR__ . '/../dropbox_helper.php';

    // Resolve the folder's current Dropbox path (url first — no search lag).
    try {
        $plan['token']    = dropbox_get_access_token();
        $plan['cur_path'] = bo_path_from_url($r['dropbox_url'] ?? '');
        if ($plan['cur_path'] === '' || !dropbox_path_exists($plan['token'], $plan['cur_path'])) {
            $plan['cur_path'] = dropbox_find_folder($plan['token'], $oldFolder);
        }
    } catch (Throwable $e) { $plan['dbx_err'] = $e->getMessage(); }

    // Existing GRP folders for this date/code.
    $grps = ($grpAction !== 'NONE' && $plan['token']) ? bo_find_grps($plan['token'], $grpCode) : [];
    $plan['grps'] = $grps;

    // ADD: auto-select the single match; resolve the group dates for the checks.
    if ($grpAction === 'ADD') {
        if (count($grps) === 1) $grpMain = $grps[0];
        $plan['grp_main'] = $grpMain;
        $plan['fields']['grpMain'] = $grpMain;
        if ($grpMain !== '') {
            $gpd = parse_folder_dates($grpMain);
            $plan['pd'] = ['start_date' => $gpd['start_date'], 'end_date' => $gpd['end_date']];
        }
    }

    // ── Decide whether the confirmation is blocked ────────────────────────────
    if ($grpAction === 'CREATE' && $grps) {
        $plan['block'] = true; $plan['can_override'] = true;
        $plan['block_msg'] = 'A GRP already exists for ' . $grpCode . ': ' . implode(', ', $grps)
                           . '. Add to it instead — or tick “Proceed anyway” to create a second GRP.';
    } elseif ($grpAction === 'ADD' && !$grps) {
        $plan['block'] = true;
        $plan['block_msg'] = 'No existing GRP found for code ' . $grpCode . ' in 001_Safari. Use “Create new GRP” instead.';
    } elseif ($grpAction === 'ADD' && count($grps) > 1 && ($grpMain === '' || !in_array($grpMain, $grps, true))) {
        $plan['block'] = true;
        $plan['block_msg'] = 'Several GRP folders match ' . $grpCode . ' — choose one below.';
    }
    return $plan;
}

/** Name shown in the preview panel for a (format-valid) plan. */
function bs_confirm_display_name(array $plan): ?string {
    if ($plan['grp_action'] === 'ADD') {
        return $plan['grp_main'] !== '' ? $plan['grp_main'] . ' / ' . $plan['member_name'] : '(choose a GRP)';
    }
    return $plan['new_name'];
}

/**
 * Y-m-d of the last _MIDTddMON in a confirmed folder name (= first night of the
 * last leg, e.g. the first beach night), resolved against the END date; or null.
 */
function bs_last_midt(?string $name, ?string $endYmd): ?string {
    if (!$name || !$endYmd) return null;
    if (!preg_match_all('/_MIDT(\d{2})([A-Z]{3})/i', $name, $m) || !$m[0]) return null;
    $months = bs_confirm_months();
    $mon = $months[strtoupper(end($m[2]))] ?? null;
    if (!$mon) return null;
    $day = (int)end($m[1]);
    $y   = (int)substr($endYmd, 0, 4);
    if ((int)$mon > (int)substr($endYmd, 5, 2)) $y--;   // MIDT in Dec, END in Jan
    return sprintf('%04d-%02d-%02d', $y, (int)$mon, $day);
}

/** Active flight cost_pax values from the rate table (floats), or null if unavailable. */
function bs_known_flight_costs(): ?array {
    try {
        $rows = db()->query("SELECT DISTINCT cost_pax FROM flight_routes WHERE active = 1 AND cost_pax IS NOT NULL")
                    ->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return null; }   // column not created yet
    $out = [];
    foreach ($rows as $c) $out[] = (float)$c;
    return $out;
}

/**
 * Advisory pre-flight checks for a format-valid plan: Excel QC (dates, flights),
 * Dropbox reachability and GRP existence. Each: ['level'=>ok|warn|info|…,'msg'].
 */
function bs_confirm_checks(array $plan): array {
    $checks = [];
    $token = $plan['token']; $curPath = $plan['cur_path'];
    try {
        $xlsx   = ($token && $curPath) ? sc_fetch_calc_xlsx($token, $curPath) : null;
        $checks = sc_run_checks([
            'xlsx_path'  => $xlsx,
            'start'      => $plan['pd']['start_date'],
            'end'        => $plan['pd']['end_date'],
            'today'      => date('Y-m-d'),
            'grp_action' => $plan['grp_action'],
            'grp_code'   => $plan['grp_code'],
        ]);
        // House rules of the Calc (single pax sheet, F9 formula, beach-night hotels, …).
        if ($xlsx && $plan['grp_action'] !== 'ADD') {
            $checks = array_merge($checks, sc_calc_rule_checks($xlsx, [
                'mid'          => bs_last_midt($plan['new_name'], $plan['pd']['end_date']),
                'flight_costs' => bs_known_flight_costs(),
            ]));
        }
        if ($xlsx) @unlink($xlsx);
    } catch (Throwable $e) {
        $checks[] = ['level' => 'info', 'msg' => 'Excel checks skipped — ' . $e->getMessage()];
    }
    if ($plan['dbx_err'] !== '') {
        $checks[] = ['level' => 'info', 'msg' => 'Dropbox error while checking: ' . $plan['dbx_err']];
    } elseif (!$curPath) {
        array_unshift($checks, ['level' => 'warn',
            'msg' => 'Folder "' . $plan['old_folder'] . '" not found in Dropbox search yet (new folders can lag ~1h). You can still confirm if you know it exists.']);
    }

    // GRP existence check lines.
    $grps = $plan['grps'];
    if ($plan['grp_action'] === 'CREATE') {
        $checks[] = $grps
            ? ['level' => 'warn', 'msg' => $plan['block_msg']]
            : ['level' => 'ok',   'msg' => 'No existing GRP for ' . $plan['grp_code'] . ' — safe to create a new group.'];
    } elseif ($plan['grp_action'] === 'ADD') {
        if (!$grps)                          $checks[] = ['level' => 'warn', 'msg' => $plan['block_msg']];
        elseif (count($grps) === 1)          $checks[] = ['level' => 'ok',   'msg' => 'Will add to existing GRP: ' . $grps[0] . '.'];
        elseif ($plan['grp_main'] !== '')    $checks[] = ['level' => 'ok',   'msg' => 'Will add to GRP: ' . $plan['grp_main'] . '.'];
        else                                 $checks[] = ['level' => 'warn', 'msg' => $plan['block_msg']];
    }
    return $checks;
}

/**
 * Commit a format-valid plan: move the Dropbox folder into 001_Safari and set the
 * request to Booked (snapshotting the prior state for Rollback). Dropbox moves
 * first; the DB is only touched if it succeeds.
 *
 * Returns ['ok'=>bool, 'msg'=>string, 'folder'=>new practice_code, 'group_folder', 'dropbox_path'].
 */
function bs_confirm_commit(PDO $db, array $plan, bool $proceed): array {
    $fail = function (string $msg) { return ['ok' => false, 'msg' => $msg]; };
    if (empty($plan['found']))  return $fail($plan['error'] ?: 'Request not found.');
    if (!empty($plan['errs']))  return $fail(implode(' ', $plan['errs']));

    // Re-enforce the block server-side (CREATE dup requires the override).
    if ($plan['block'] && !($plan['can_override'] && $proceed)) {
        return $fail($plan['block_msg'] !== '' ? $plan['block_msg'] : 'Confirmation is blocked — review the checks.');
    }

    $r         = $plan['r'];
    $reqId     = (int)$r['id'];
    $oldFolder = $plan['old_folder'];
    $token     = $plan['token'];
    $curPath   = $plan['cur_path'];
    $grpAction = $plan['grp_action'];
    $destValue = $plan['dest_value'];
    $cust      = $r['customer_name'] ?: 'Booking';

    try {
        if ($token === null) throw new RuntimeException($plan['dbx_err'] ?: 'No Dropbox token.');
        if ($curPath === null || $curPath === '') {
            return $fail('Could not find "' . $oldFolder . '" in Dropbox — nothing changed. Verify the folder, then retry.');
        }
        try { $db->exec("ALTER TABLE requests ADD COLUMN confirmation_date DATE NULL DEFAULT NULL"); } catch (PDOException $ig) {}
        try { $db->exec("ALTER TABLE requests ADD COLUMN group_folder VARCHAR(255) NULL DEFAULT NULL"); } catch (PDOException $ig) {}
        try { $db->exec("ALTER TABLE requests ADD COLUMN pre_confirm_json TEXT NULL DEFAULT NULL"); } catch (PDOException $ig) {}

        // Snapshot the pre-confirmation state so this can be rolled back exactly.
        $preConfirm = json_encode([
            'name'   => $oldFolder,
            'path'   => $curPath,
            'status' => $r['status'] ?? '',
            'pay'    => $r['payment_status'] ?? null,
            'action' => $grpAction,
            'group'  => trim($r['group_folder'] ?? ''),
            'sub'    => $plan['sub_name'],
        ], JSON_UNESCAPED_UNICODE);

        if ($grpAction === 'ADD') {
            $grpMain    = $plan['grp_main'];
            $memberName = $plan['member_name'];
            $destPath   = '/001_Safari/' . $grpMain . '/' . $memberName;
            if (dropbox_path_exists($token, $destPath)) {
                return $fail('"' . $memberName . '" already exists inside GRP "' . $grpMain . '" — nothing changed.');
            }
            dropbox_move_folder($token, $curPath, $destPath);
            $gpd = parse_folder_dates($grpMain);
            $db->prepare(
                "UPDATE requests
                 SET practice_code=?, group_folder=?, dropbox_url=?, status='Booked', confirmation_date=CURDATE(),
                     start_date=COALESCE(?, start_date),
                     destination=CASE WHEN ?<>'' THEN ? ELSE destination END,
                     pre_confirm_json=?
                 WHERE id=?"
            )->execute([$memberName, $grpMain, bo_url_from_path($destPath), $gpd['start_date'], $destValue, $destValue, $preConfirm, $reqId]);
            return ['ok' => true, 'msg' => '✔ ' . $cust . ' added to GRP "' . $grpMain . '" (status → Booked).',
                    'folder' => $memberName, 'group_folder' => $grpMain, 'dropbox_path' => $destPath];
        }

        // NONE or CREATE — move to a top-level 001_Safari folder
        $newName = $plan['new_name'];
        $newPath = '/001_Safari/' . $newName;
        if (dropbox_path_exists($token, $newPath)) {
            return $fail('A folder named "' . $newName . '" already exists in 001_Safari — nothing changed.');
        }
        dropbox_move_folder($token, $curPath, $newPath);

        if ($grpAction === 'CREATE') {
            // Create the member subfolder and move the loose docs (keep the group xlsx at GRP root).
            $subName = $plan['sub_name'];
            $subPath = $newPath . '/' . $subName;
            dropbox_create_folder($token, $subPath, false);
            foreach (dropbox_list_files($token, $newPath) as $fn) {
                if (preg_match('/\.xlsx?$/i', $fn)) continue;         // group calc stays at GRP root
                try { dropbox_move_folder($token, $newPath . '/' . $fn, $subPath . '/' . $fn); }
                catch (Throwable $ig) { /* best-effort per file */ }
            }
            $db->prepare(
                "UPDATE requests
                 SET practice_code=?, group_folder=?, dropbox_url=?, status='Booked', confirmation_date=CURDATE(),
                     start_date=COALESCE(?, start_date),
                     destination=CASE WHEN ?<>'' THEN ? ELSE destination END,
                     pre_confirm_json=?
                 WHERE id=?"
            )->execute([$subName, $newName, bo_url_from_path($subPath), $plan['pd']['start_date'], $destValue, $destValue, $preConfirm, $reqId]);
            return ['ok' => true, 'msg' => '✔ ' . $cust . ' — new GRP "' . $newName . '" created (status → Booked).',
                    'folder' => $subName, 'group_folder' => $newName, 'dropbox_path' => $subPath];
        }

        // NONE
        $db->prepare(
            "UPDATE requests
             SET practice_code=?, dropbox_url=?, status='Booked', confirmation_date=CURDATE(),
                 start_date=COALESCE(?, start_date),
                 destination=CASE WHEN ?<>'' THEN ? ELSE destination END,
                 pre_confirm_json=?
             WHERE id=?"
        )->execute([$newName, bo_url_from_path($newPath), $plan['pd']['start_date'], $destValue, $destValue, $preConfirm, $reqId]);
        return ['ok' => true, 'msg' => '✔ ' . $cust . ' confirmed → "' . $newName . '" (status → Booked).',
                'folder' => $newName, 'group_folder' => null, 'dropbox_path' => $newPath];

    } catch (Throwable $e) {
        return $fail('Dropbox/DB error — nothing was changed: ' . $e->getMessage());
    }
}

// ════════════════════════════════════════════════════════════════════════════
//  Rollback a confirmation
// ════════════════════════════════════════════════════════════════════════════

/** Restore the request row to its pre-confirmation state (used by rollback). */
function bo_rollback_db(PDO $db, int $id, string $name, string $path, array $pre): void {
    $status = $pre['status'] ?? '';
    // Never leave it Booked after a rollback; fall back to Inquiry if unknown.
    if ($status === '' || strcasecmp($status, 'Booked') === 0) $status = 'Inquiry';
    $pay   = $pre['pay']   ?? null;
    $group = trim($pre['group'] ?? '');
    $db->prepare(
        "UPDATE requests
         SET practice_code=?, group_folder=?, dropbox_url=?, status=?, payment_status=?,
             confirmation_date=NULL, pre_confirm_json=NULL
         WHERE id=?"
    )->execute([$name, ($group !== '' ? $group : null), bo_url_from_path($path), $status, ($pay !== '' ? $pay : null), $id]);
}

/**
 * Undo a Hub confirmation: move the folder back to where it was before Confirm Safari
 * and restore the request row from pre_confirm_json. $commit = false → only checks
 * and reports what would happen (nothing moved).
 *
 * Returns ['ok'=>bool, 'msg'=>string, 'action'=>NONE|ADD|CREATE, 'from'=>path, 'to'=>path,
 *          'restore'=>['name','status','payment_status','group']].
 */
function bs_rollback(PDO $db, int $reqId, bool $commit): array {
    $fail = function (string $msg, array $extra = []) { return array_merge(['ok' => false, 'msg' => $msg], $extra); };

    $stmt = $db->prepare("SELECT id, customer_name, practice_code, group_folder, dropbox_url, pre_confirm_json
                          FROM requests WHERE id = ?");
    $stmt->execute([$reqId]);
    $r   = $stmt->fetch(PDO::FETCH_ASSOC);
    $pre = $r ? json_decode($r['pre_confirm_json'] ?? '', true) : null;

    if (!$r) return $fail('Request not found.');
    if (!$pre || empty($pre['name']) || empty($pre['path'])) {
        return $fail('No rollback data stored for this booking (only Hub-confirmed bookings can be rolled back).');
    }

    $cust     = $r['customer_name'] ?: 'Booking';
    $act      = strtoupper($pre['action'] ?? 'NONE');
    $origName = $pre['name'];
    $destPath = $pre['path'];                        // e.g. /2026/CustName(Ag-Handler)
    $group    = trim($r['group_folder'] ?? '');
    $member   = trim($r['practice_code'] ?? '');
    $preStat  = (string)($pre['status'] ?? '');
    $restore  = [
        'name'           => $origName,
        'status'         => ($preStat === '' || strcasecmp($preStat, 'Booked') === 0) ? 'Inquiry' : $preStat,
        'payment_status' => $pre['pay'] ?? null,
        'group'          => trim($pre['group'] ?? '') !== '' ? trim($pre['group']) : null,
    ];

    // Where the folder is now.
    if ($act === 'CREATE') {
        $curPath = '/001_Safari/' . $group;          // the whole (renamed) GRP folder moves back
    } else {
        $curPath = bo_path_from_url($r['dropbox_url'] ?? '');
        if ($curPath === '') {
            $curPath = ($act === 'ADD' && $group !== '')
                ? '/001_Safari/' . $group . '/' . $member
                : '/001_Safari/' . $member;
        }
    }
    $info = ['action' => $act, 'from' => $curPath, 'to' => $destPath, 'restore' => $restore];

    require_once __DIR__ . '/../dropbox_helper.php';
    try {
        $token = dropbox_get_access_token();

        if (dropbox_path_exists($token, $destPath)) {
            return $fail('The original location "' . $destPath . '" already exists — resolve it manually; nothing changed.', $info);
        }
        if ($act === 'CREATE') {
            // Safe only while this is the group's sole member (authoritative: the DB).
            if ($group === '') return $fail('Missing group folder — cannot roll back automatically.', $info);
            $cnt = $db->prepare("SELECT COUNT(*) FROM requests WHERE group_folder = ?");
            $cnt->execute([$group]);
            $members = (int)$cnt->fetchColumn();
            if ($members > 1) {
                return $fail('This group has ' . $members . ' members — remove the others first, then roll back the last one.', $info);
            }
        }
        if (!$commit) {
            return array_merge(['ok' => true, 'msg' => 'Would move "' . $curPath . '" back to "' . $destPath
                              . '" and set status → ' . $restore['status'] . '.'], $info);
        }

        if ($act === 'CREATE') {
            // Move the member's loose files back to the group root, drop the empty
            // subfolder, then move the (renamed) group folder back to its source.
            $sub     = trim($pre['sub'] ?? $member);
            $subPath = $curPath . '/' . $sub;
            foreach (dropbox_list_files($token, $subPath) as $fn) {
                try { dropbox_move_folder($token, $subPath . '/' . $fn, $curPath . '/' . $fn); }
                catch (Throwable $ig) { /* best-effort per file */ }
            }
            try { dropbox_delete_folder($token, $subPath); } catch (Throwable $ig) { /* empty subfolder */ }
            dropbox_move_folder($token, $curPath, $destPath);
            bo_rollback_db($db, $reqId, $origName, $destPath, $pre);
            return array_merge(['ok' => true, 'msg' => '↩ Rolled back "' . $cust . '" — single-member group undone, folder restored to ' . $destPath . '.'], $info);
        }

        // NONE / ADD — a single folder move back to the source path.
        dropbox_move_folder($token, $curPath, $destPath);
        bo_rollback_db($db, $reqId, $origName, $destPath, $pre);
        return array_merge(['ok' => true, 'msg' => '↩ Rolled back "' . $cust . '" — folder restored to ' . $destPath . '.'], $info);

    } catch (Throwable $e) {
        return $fail('Dropbox/DB error — nothing was changed: ' . $e->getMessage(), $info);
    }
}

// ════════════════════════════════════════════════════════════════════════════
//  Booking email
// ════════════════════════════════════════════════════════════════════════════

/**
 * Build the post-confirmation booking-notification email (ports the Java
 * showSafariBookingEmailDialog template). $grpMain != '' → the "added to group"
 * variant. Returns ['to','cc','subject','body'] (to/cc comma-separated).
 */
function bo_booking_email(string $folder, string $agentEmail, string $grpMain, string $sessionFullName): array {
    // Agent code = last token inside the last (…) block, e.g. "(GoWorld-PS-Roberto)" → "Roberto".
    $agentCode = '';
    if (preg_match_all('/\(([^)]+)\)/', $folder, $m) && !empty($m[1])) {
        $parts = array_values(array_filter(array_map('trim', explode('-', (string)end($m[1]))), 'strlen'));
        if ($parts) $agentCode = (string)end($parts);
    }
    $grpAdd  = ($grpMain !== '');
    $fnLower = strtolower(trim($sessionFullName));

    $to = ['accountant@savannahexplorers.com', 'glady@savannahexplorers.com', 'operations@savannahexplorers.com'];
    $addNuru = in_array(strtolower($agentCode), ['roberto','robertocapri','eleonoraongaro','alessia','daniela'], true)
            || in_array($fnLower, ['roberto','roberto capri','alessia','daniela'], true);
    if ($addNuru) $to[] = 'nuru@savannahexplorers.com';

    $cc = [];
    if ($agentEmail !== '') $cc[] = $agentEmail;
    $cc[] = 'savannah.explorers@gmail.com';
    $cc[] = 'saruni@savannahexplorers.com';

    if ($grpAdd) {
        $subject = $grpMain . '\\' . $folder;
    } else {
        $cust = preg_replace('/^\d+_\d+[A-Za-z]+_/', '', $folder);
        $cust = preg_replace('/_START.+$/', '', $cust);
        $subject = $cust . ' safari bookings';
    }

    $agentDisplay = $sessionFullName !== '' ? $sessionFullName : $agentCode;
    $b  = "Hi Glady/Lydia,\n";
    if ($grpAdd) {
        $b .= "         this customer has been added to group " . $grpMain . ",\n";
        $b .= "         please check the extra services to book as in the excel file (transfers, hotels, Zanzibar, etc. — if any)\n\n";
        $b .= "Dropbox folder is   " . $grpMain . "\\" . $folder . "\n\n";
    } else {
        $b .= "         you can book for this safari as in the excel file\n\n";
        $b .= "Dropbox folder is   " . $folder . "\n\n";
    }
    $b .= "Kindly check domestic flights, invoices, transfers and activities are correctly"
        . " booked and invoiced for the correct price/date/pax before saving."
        . " Put invoice details and your name in Excel after it's checked.\n\n";
    if ($addNuru) $b .= "Nuru - prepare the final program when bookings are completed\n\n";
    $b .= "Esther - prepare the not paid invoice\n";
    $b .= "\nThanks\nBest Regards,\n" . $agentDisplay;

    return [
        'to'      => implode(', ', array_values(array_unique($to))),
        'cc'      => implode(', ', array_values(array_unique($cc))),
        'subject' => $subject,
        'body'    => $b,
    ];
}

/** Valid, de-duplicated addresses from "a, b; c" or an array. */
function bs_parse_emails($list): array {
    if (!is_array($list)) $list = preg_split('/[,;]+/', (string)$list);
    $out = [];
    foreach ($list as $addr) {
        $addr = trim((string)$addr);
        if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) $out[] = $addr;
    }
    return array_values(array_unique($out));
}

/**
 * Send a plain booking-team email from noreply@, with Reply-To + HTML signature of
 * $userId (when set). Returns ['success'=>bool, 'message'=>string].
 */
function bs_send_mail(array $toList, array $ccList, string $subject, string $body, int $userId): array {
    if (!function_exists('get_user_signature_html')) {
        $sig = __DIR__ . '/../../../includes/signature_helper.php';
        if (is_file($sig)) require_once $sig;
    }
    if (empty($toList)) return ['success' => false, 'message' => 'No valid To addresses.'];

    $replyTo = '';
    $signatureHtml = $signaturePlain = '';
    if ($userId > 0) {
        try {
            $u = db()->prepare('SELECT email FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
            $u->execute([$userId]);
            $em = (string)($u->fetchColumn() ?: '');
            if (filter_var($em, FILTER_VALIDATE_EMAIL)) $replyTo = $em;
        } catch (Throwable $e) { /* non-blocking */ }

        if (function_exists('get_user_signature_html')) {
            $sig = get_user_signature_html($userId);
            if ($sig !== '') {
                $signatureHtml  = '<br><br><hr style="border:none;border-top:1px solid #ccc;margin:12px 0;">' . $sig;
                $signaturePlain = "\r\n\r\n--\r\n" . (function_exists('signature_html_to_plain') ? signature_html_to_plain($sig) : strip_tags($sig));
            }
        }
    }

    $toStr = implode(', ', $toList);

    if ($signatureHtml !== '') {
        $boundary  = 'boundary_' . md5(uniqid('', true));
        $plainPart = $body . $signaturePlain;
        $htmlBody  = '<html><body><p style="font-family:Arial,sans-serif;font-size:13px;color:#333;line-height:1.6;white-space:pre-wrap;">'
                   . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</p>' . $signatureHtml . '</body></html>';

        $headers = "From: noreply@savannahexplorers.com\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        if ($replyTo !== '')  $headers .= "Reply-To: {$replyTo}\r\n";
        if (!empty($ccList))  $headers .= "Cc: " . implode(', ', $ccList) . "\r\n";

        $message = "--{$boundary}\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
                 . quoted_printable_encode($plainPart) . "\r\n"
                 . "--{$boundary}\r\n"
                 . "Content-Type: text/html; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
                 . quoted_printable_encode($htmlBody) . "\r\n"
                 . "--{$boundary}--";

        $ok = mail($toStr, $subject, $message, $headers);
    } else {
        $headers = "From: noreply@savannahexplorers.com\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n";
        if ($replyTo !== '')  $headers .= "Reply-To: {$replyTo}\r\n";
        if (!empty($ccList))  $headers .= "Cc: " . implode(', ', $ccList) . "\r\n";

        $ok = mail($toStr, $subject, $body, $headers);
    }

    return $ok
        ? ['success' => true, 'message' => '']
        : ['success' => false, 'message' => 'mail() returned false — check the BlueHost mail log.'];
}
