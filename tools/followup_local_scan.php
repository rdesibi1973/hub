<?php
/**
 * tools/followup_local_scan.php — Booking follow-up, supplier documents read on the office PC.
 * BlueHost cannot read PDFs (no pdftotext, no Composer), the PC can: this reads
 * <Dropbox>\001_Safari\<booking>\invoices\*.pdf (top level, not old\) of the trips not ended,
 * classifies them with the same rules as the server (modules/operations/includes/followup_classify.php)
 * and sends the result to the Agent API followup_upsert. Dry run unless --confirm.
 *
 *   tools\followup_local_scan.bat                      dry run: what would change
 *   tools\followup_local_scan.bat --confirm            write the lines
 *   tools\followup_local_scan.bat --folder=MattiaFerri --json=out.json   one booking, keep the rows
 *
 * Options: --root=C:\Dropbox\001_Safari  --pdftotext=C:\Utility\Git\mingw64\bin\pdftotext.exe (or ucrt64\, found by itself)
 *          --key-file=<path of api.txt>  (or env HUB_AGENT_KEY)  --url=https://hub.savannahexplorers.com/api/agent/index.php
 *          --today=YYYY-MM-DD (tests)  --no-send (classify only)  --cainfo=<CA bundle> (default: Git's, the office PHP has none)
 *          --no-ocr (PDFs without text stay "unreadable")
 *          --extra=<folders;…> booking folders outside 001_Safari, wildcards allowed (default: the Diamante
 *                  provisional groups, C:\Dropbox\itineraries\SafariClassic\it\Agenzia\Diamante\20*-Groups; "" = none)
 * Needs PHP with curl + openssl (the .bat loads them). Passport files are never opened (only invoices\).
 * A PDF without a text layer (scan, image printed to PDF) is read with the Windows OCR (tools\pdf_ocr.ps1);
 * its status text ends with "(OCR)".
 */
date_default_timezone_set('Africa/Dar_es_Salaam');
require_once __DIR__ . '/../modules/leads/includes/folder_parser.php';
require_once __DIR__ . '/../modules/operations/includes/followup_classify.php';

$opt = ['root' => 'C:\\Dropbox\\001_Safari', 'pdftotext' => 'C:\\Utility\\Git\\mingw64\\bin\\pdftotext.exe',
        'key-file' => __DIR__ . '/../api.txt', 'url' => 'https://hub.savannahexplorers.com/api/agent/index.php',
        'today' => date('Y-m-d'), 'folder' => '', 'json' => '', 'confirm' => false, 'no-send' => false, 'no-ocr' => false,
        'extra' => 'C:\\Dropbox\\itineraries\\SafariClassic\\it\\Agenzia\\Diamante\\20*-Groups',
        'cainfo' => 'C:\\Utility\\Git\\mingw64\\etc\\ssl\\certs\\ca-bundle.crt'];
foreach (array_slice($argv, 1) as $a) {
    if (!preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $a, $m) || !array_key_exists($m[1], $opt)) { fwrite(STDERR, "Unknown option $a\n"); exit(2); }
    $opt[$m[1]] = isset($m[2]) ? $m[2] : true;
}
// Git for Windows 2.5x installs ucrt64\ instead of mingw64\: same tools, other folder.
foreach (['pdftotext', 'cainfo'] as $k) {
    $alt = str_ireplace('\\mingw64\\', '\\ucrt64\\', $opt[$k]);
    if (!is_file($opt[$k]) && $alt !== $opt[$k] && is_file($alt)) $opt[$k] = $alt;
}
$today = $opt['today'];
$root  = rtrim($opt['root'], '\\/');
if (!is_dir($root)) { fwrite(STDERR, "Folder not found: $root\n"); exit(2); }
if (!is_file($opt['pdftotext'])) { fwrite(STDERR, "pdftotext not found: {$opt['pdftotext']}\n"); exit(2); }

// ── Booking folders of trips not ended: top level + 00_YYYY, and GRP member sub-folders ──
// Key = the folder as the Hub stores it: path under 001_Safari, or "/<path under Dropbox>" for the
// --extra folders (provisional groups kept outside 001_Safari until confirmed).
$folders = [];   // key => ['trip' => [start, end], 'path' => local path]
$consider = function (string $key, string $path) use (&$folders, $today) {
    $d = parse_folder_dates(basename($path));
    if (!$d['end_date'] || $d['end_date'] < $today) return false;
    $folders[$key] = ['trip' => $d, 'path' => $path];
    return true;
};
foreach (scandir($root) as $n) {
    if ($n[0] === '.' || !is_dir("$root/$n")) continue;
    if (preg_match('/^00_20\d\d$/', $n)) {
        foreach (scandir("$root/$n") as $m) if ($m[0] !== '.' && is_dir("$root/$n/$m") && folder_is_booking_leaf($m)) $consider("$n/$m", "$root/$n/$m");
    } elseif (folder_is_booking_leaf($n)) {
        $consider($n, "$root/$n");
    }
}
$home = dirname($root);                                      // the Dropbox folder on this PC
foreach ($opt['extra'] === '' ? [] : explode(';', $opt['extra']) as $pattern) {
    foreach (glob(rtrim(trim($pattern), '\\/'), GLOB_ONLYDIR) ?: [] as $dir) {
        if (stripos($dir, $home) !== 0) { echo "  ! not under $home: $dir\n"; continue; }
        $base = '/' . str_replace('\\', '/', trim(substr($dir, strlen($home)), '\\/'));
        foreach (scandir($dir) as $n) if ($n[0] !== '.' && is_dir("$dir/$n") && folder_is_booking_leaf($n)) $consider("$base/$n", "$dir/$n");
    }
}
foreach ($folders as $key => $f) {                          // GRP: members with their own invoices\
    foreach (scandir($f['path']) as $m) {
        if ($m[0] !== '.' && is_dir("{$f['path']}/$m") && folder_is_booking_leaf($m) && is_dir("{$f['path']}/$m/invoices")) {
            $folders["$key/$m"] = ['trip' => $f['trip'], 'path' => "{$f['path']}/$m"];
        }
    }
}
if ($opt['folder'] !== '') $folders = array_filter($folders, fn($k) => stripos($k, $opt['folder']) !== false, ARRAY_FILTER_USE_KEY);
ksort($folders);
echo count($folders) . " booking folder(s) with trips not ended\n";

/**
 * POST to the Agent API → [http code, body, curl error]. The office PHP has no CA list of its own: Git's
 * bundle (--cainfo) verifies the Hub certificate. User agent as in docs/AGENT_API.md (Mod_Security).
 */
function hub_post(array $opt, string $action, string $body, string $key): array {
    $ch = curl_init($opt['url'] . '?action=' . $action);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300, CURLOPT_POSTFIELDS => $body,
                            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Agent-Key: ' . $key],
                            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; SavannahHubAgent/1.0)']);
    if ($opt['cainfo'] !== '' && is_file($opt['cainfo'])) curl_setopt($ch, CURLOPT_CAINFO, $opt['cainfo']);
    $res = curl_exec($ch);
    $out = [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), $res === false ? '' : (string)$res, curl_errno($ch) ? curl_error($ch) : ''];
    return $out;
}

/** Same rule as followup_group_kind() in followup_service.php (not loaded here: it needs the DB). */
function followup_group_kind_local(string $name): bool {
    return (bool)(preg_match('/GRP\d{4}|\(Diamante|Panorama/i', $name)
                  || preg_match('/[_-]PROVISIONAL$/i', preg_replace('/(?:[_-]+CK)+$/i', '', trim($name))));
}

// ── Read and classify ──
$rows = []; $scanned = []; $count = ['docs' => 0, 'issues' => 0, 'confirmed' => 0, 'unknown' => 0, 'unreadable' => 0, 'ocr' => 0];
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fu_scan_' . getmypid() . '.txt';
foreach ($folders as $rel => $fo) {
    $trip = $fo['trip'];
    $dir = "{$fo['path']}/invoices";
    // The top booking folder is a group; --extra folders are all groups.
    $isGroup = $rel[0] === '/' || followup_group_kind_local(explode('/', preg_replace('#^00_20\d\d/#', '', $rel))[0]);
    $ok = true;
    foreach (is_dir($dir) ? scandir($dir) : [] as $f) {
        if (!is_file("$dir/$f") || followup_skip_file($f)) continue;
        $count['docs']++;
        @unlink($tmp);
        // cmd.exe mangles "%" and non-ASCII names: pdftotext reads an ASCII copy.
        $pdf = $tmp . '.pdf';
        if (!@copy("$dir/$f", $pdf)) { $ok = false; echo "  ! not copied: $rel/invoices/$f\n"; continue; }
        exec('"' . $opt['pdftotext'] . '" -layout -q "' . $pdf . '" "' . $tmp . '"', $o, $rc);
        $text = is_file($tmp) ? (string)file_get_contents($tmp) : '';
        $ocr  = false;
        if (trim($text, " \t\n\r\0\x0B\f") === '' && !$opt['no-ocr']) {   // no text layer (only page breaks): Windows OCR
            @unlink($tmp);
            exec('powershell -NoProfile -ExecutionPolicy Bypass -File "' . __DIR__ . '\\pdf_ocr.ps1" -Pdf "' . $pdf . '" -Out "' . $tmp . '"', $o, $orc);
            if ($orc === 0 && is_file($tmp) && trim($t = (string)file_get_contents($tmp)) !== '') { $text = $t; $ocr = true; $count['ocr']++; }
        }
        @unlink($pdf);
        if ($rc !== 0 && $text === '') { $ok = false; echo "  ! not read: $rel/invoices/$f\n"; continue; }
        $c = followup_classify($text, $f, $trip, $today);
        if ($ocr && $c['doc_status_text'] !== '') $c['doc_status_text'] .= ' (OCR)';
        $groupDoc = $isGroup;   // group folders: every PDF is sent, so the group check knows all its documents were read
        if ($c['issue']) {
            $count[$c['issue'] === 'unreadable' ? 'unreadable' : 'issues']++;
        } elseif ($c['status'] === 'confirmed') {
            $count['confirmed']++;
        } else {
            $count['unknown']++;
            if (!$groupDoc) continue;                       // no status printed: nothing to say (groups: blocks / seats still count)
        }
        $rows[] = ['folder' => $rel, 'source_file' => 'invoices/' . $f, 'supplier' => $c['supplier'], 'supplier_ref' => $c['supplier_ref'],
                   'service_date' => $c['service_date'], 'issue' => $c['issue'], 'status' => $c['status'],
                   'doc_status_text' => $c['doc_status_text'], 'expiry_date' => $c['expiry_date'], 'booked_by' => $c['booked_by'],
                   'block_text' => $c['block_text'] ?? null, 'names_tba' => $c['names_tba'] ?? false, 'flight_pax' => $c['flight_pax'] ?? null,
                   'flight_znz' => $c['flight_znz'] ?? false, 'flight_text' => $c['flight_text'] ?? null, 'ticket_limit' => $c['ticket_limit'] ?? null,
                   'payment_days' => $c['payment_days'] ?? null];
    }
    if ($ok) $scanned[] = $rel;
}
@unlink($tmp);
printf("%d PDF(s): %d to follow up, %d confirmed, %d unreadable, %d without a status (%d read with OCR)\n",
       $count['docs'], $count['issues'], $count['confirmed'], $count['unreadable'], $count['unknown'], $count['ocr']);
foreach ($rows as $r) {
    if ($r['issue']) printf("  %-22s %-38s %-12s %s\n", $r['issue'], substr($r['folder'][0] === '/' ? basename($r['folder']) : $r['folder'], 0, 38),
                            (string)$r['supplier_ref'], $r['doc_status_text']);
}
if ($opt['json'] !== '') {
    file_put_contents($opt['json'], json_encode(['rows' => $rows, 'scanned' => $scanned], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo "Rows saved to {$opt['json']}\n";
}
if ($opt['no-send']) exit(0);

// ── Send to the Hub (followup_upsert), a few hundred rows per call ──
$key = getenv('HUB_AGENT_KEY') ?: (is_file($opt['key-file']) ? trim((string)file_get_contents($opt['key-file'])) : '');
if (preg_match('/[A-Za-z0-9_\-]{20,}/', $key, $m)) $key = $m[0];   // api.txt may hold more than the key
if ($key === '') { fwrite(STDERR, "No agent key: set HUB_AGENT_KEY or --key-file\n"); exit(2); }
if (!function_exists('curl_init')) { fwrite(STDERR, "PHP curl extension missing — run tools\\followup_local_scan.bat\n"); exit(2); }

$byFolder = [];
foreach ($rows as $r) $byFolder[$r['folder']][] = $r;
$batches = []; $cur = ['rows' => [], 'scanned' => []];
foreach ($scanned as $f) {
    $cur['rows'] = array_merge($cur['rows'], $byFolder[$f] ?? []); $cur['scanned'][] = $f; unset($byFolder[$f]);
    if (count($cur['rows']) >= 300) { $batches[] = $cur; $cur = ['rows' => [], 'scanned' => []]; }
}
foreach ($byFolder as $f => $list) $cur['rows'] = array_merge($cur['rows'], $list);   // folders not read completely: no auto_not_found
if ($cur['rows'] || $cur['scanned']) $batches[] = $cur;

$total = ['added' => 0, 'updated' => 0, 'closed' => 0, 'reopened' => 0, 'unmatched' => 0];
foreach ($batches as $i => $b) {
    $body = json_encode(['b64' => base64_encode(json_encode($b + ['confirm' => (bool)$opt['confirm'], 'source' => 'local'], JSON_UNESCAPED_UNICODE))]);
    [$code, $res, $err] = hub_post($opt, 'followup_upsert', $body, $key);
    $j = json_decode((string)$res, true);
    if ($code !== 200 || empty($j['ok'])) {
        fwrite(STDERR, "Batch " . ($i + 1) . " failed (HTTP $code" . ($err !== '' ? ", $err" : '') . "): " . substr((string)$res, 0, 400) . "\n");
        exit(1);
    }
    foreach (['added', 'updated', 'closed', 'reopened'] as $k) $total[$k] += count($j[$k] ?? []);
    $total['unmatched'] += count($j['unmatched'] ?? []);
    foreach ($j['closed'] ?? [] as $c) echo "  closed ({$c['reason']}): " . ($c['folder'] ?? '') . ' ' . ($c['ref'] ?? '') . "\n";
    foreach ($j['reopened'] ?? [] as $c) echo "  re-opened: " . ($c['folder'] ?? '') . ' ' . ($c['ref'] ?? '') . "\n";
    foreach ($j['unmatched'] ?? [] as $u) echo "  not matched: " . ($u['folder'] ?? $u['row']) . ' — ' . $u['error'] . "\n";
}
// ── Group check (arrivals in the next 60 days), with the documents just sent ──
[$code, $res, $err] = hub_post($opt, 'followup_scan', json_encode(['b64' => base64_encode(json_encode(['type' => 'group', 'confirm' => (bool)$opt['confirm']]))]), $key);
$g = json_decode((string)$res, true);
if (!$g && $err !== '') $g = ['error' => $err];
if (!empty($g['group'])) {
    printf("Groups arriving within 60 days: %d checked, %d new, %d updated, %d closed\n", count($g['group']['groups'] ?? []),
           count($g['group']['added'] ?? []), count($g['group']['updated'] ?? []), count($g['group']['closed'] ?? []));
    foreach ($g['group']['added'] ?? [] as $a) echo '  + ' . ($a['folder'] ?? '') . ' — ' . ($a['status'] ?? '') . "\n";
} else {
    echo 'Group check not run: ' . substr((string)json_encode($g), 0, 300) . "\n";
}
printf("%s: %d new, %d updated, %d closed, %d re-opened, %d row(s) not matched to a booking\n",
       $opt['confirm'] ? 'Saved' : 'Dry run (add --confirm to save)', $total['added'], $total['updated'], $total['closed'], $total['reopened'], $total['unmatched']);
