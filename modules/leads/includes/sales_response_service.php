<?php
/**
 * sales_response_service.php — how quickly the sellers answer their clients by email, request by request,
 * with evenings / weekends / Tanzanian public holidays measured apart. HEADERS ONLY: From, To, Cc, Date,
 * Subject, Message-ID, In-Reply-To, References (+ the auto-reply markers). Bodies and attachments are never
 * read, stored or shown. Spec: Cowork handoff "Sales responsiveness report", 17 Oct 2026 (Roberto).
 *
 * Used by modules/leads/sales_response_scan.php (CLI, cPanel cron) and sales_response_cron.php (cron-job.org URL),
 * both through sr_run() (reads the Maildirs, links, emails),
 * modules/leads/sales_response.php (Hub page) and the Agent API sales_response_status. All three show
 * sr_report(), so the numbers are the same everywhere.
 * Tables: migrations/072_sales_response.sql (sr_schema() creates them too).
 *
 * Server-only overrides (includes/config.php), all optional:
 *   SALES_RESPONSE_LIVE      true = daily alerts on, reports no longer flagged TEST (Roberto's OK after the first run)
 *   SALES_RESPONSE_TO        recipient (default savannah.explorers@gmail.com)
 *   SALES_RESPONSE_SELLERS   ['Asia' => ['agent_id' => 7, 'leave' => 'Asia Caruso', 'mailbox' => '…'], …] merged over SR_SELLERS
 *   SALES_RESPONSE_HOLIDAYS  ['2027-03-10', …] replaces SR_HOLIDAYS
 *   SALES_RESPONSE_MAIL_ROOT Maildir root (default <home>/mail)
 */

const SR_THRESHOLD_H   = 24;     // Roberto: 24 clock hours, always (weekdays, evenings, weekends, holidays)
const SR_BACKFILL_DAYS = 90;     // first run reads 90 days back
const SR_RELINK_DAYS   = 90;     // links of the last 90 days are recomputed on every run (requests created after the mail)
const SR_SCOPE_DAYS    = 90;     // a request is "open" with date_received / activity in the last 90 days (Booked: until start_date)
const SR_SILENT_DAYS   = 7;
const SR_KEEP_MONTHS   = 12;
const SR_OWN_DOMAINS   = ['savannahexplorers.com', 'theorangicollection.com'];
const SR_OWN_ADDRESSES = ['savannah.explorers@gmail.com'];          // Roberto's Gmail: his replies count as ours
// Shared mailboxes read too (change request 19 Oct 2026): most direct leads arrive on info@ and are assigned in the Hub.
// A linked client mail received there is a client message for the seller of the request; a reply sent from there counts
// as "Savannah answered". Unlinked mail on them (spam, suppliers, newsletters, staff) is never counted nor listed.
const SR_SHARED_BOXES  = ['info@savannahexplorers.com', 'safari@savannahexplorers.com', 'sales@savannahexplorers.com'];
const SR_CLOSED        = ['Lost', 'Cancelled'];
// Direct clients only (Roberto, 18 Oct 2026): requests with channel 'direct', or — older requests without a channel —
// no agency and not an SB folder ("(Agent)" / "(Agent-Drct)"). Agency, SB and Other requests are still linked (better matching) but never counted.
const SR_DIRECT_ONLY   = true;

// Sellers in scope (Roberto, 17 Oct 2026). Requests of Roberto / Daniela are excluded by not being here.
// agent / leave = name in agents.name / leave_entries.employee_name (exact, else "<name> …"); the first run
// mails the resolved mapping for Roberto to confirm. Fix a wrong match with SALES_RESPONSE_SELLERS (agent_id).
const SR_SELLERS = [
    ['name' => 'Asia',     'mailbox' => 'asia.caruso@savannahexplorers.com', 'agent' => 'Asia',     'leave' => 'Asia'],
    ['name' => 'Alessia',  'mailbox' => 'alessia@savannahexplorers.com',     'agent' => 'Alessia',  'leave' => 'Alessia'],
    ['name' => 'Anderson', 'mailbox' => 'anderson.jr@savannahexplorers.com', 'agent' => 'Anderson', 'leave' => 'Anderson'],
    ['name' => 'Nuru',     'mailbox' => 'nuru@savannahexplorers.com',        'agent' => 'Nuru',     'leave' => 'Nuru'],
    ['name' => 'Agustin',  'mailbox' => 'agustin@savannahexplorers.com',     'agent' => 'Agustin',  'leave' => 'Agustin'],
];

// Tanzanian public holidays. Eid el-Fitr, Eid el-Adha and Maulid follow the moon: the dates below are the
// expected ones — correct them when the government announces them (or override with SALES_RESPONSE_HOLIDAYS).
const SR_HOLIDAYS = [
    '2026-01-01', '2026-01-12', '2026-03-20', '2026-04-03', '2026-04-06', '2026-04-07', '2026-04-26', '2026-05-01',
    '2026-05-27', '2026-07-07', '2026-08-08', '2026-08-26', '2026-10-14', '2026-12-09', '2026-12-25', '2026-12-26',
    '2027-01-01', '2027-01-12', '2027-03-10', '2027-03-26', '2027-03-29', '2027-04-07', '2027-04-26', '2027-05-01',
    '2027-05-16', '2027-07-07', '2027-08-08', '2027-08-15', '2027-10-14', '2027-12-09', '2027-12-25', '2027-12-26',
];

const SR_FREEMAIL = ['gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.it', 'yahoo.fr', 'yahoo.co.uk', 'ymail.com', 'hotmail.com',
    'hotmail.it', 'hotmail.fr', 'hotmail.co.uk', 'outlook.com', 'outlook.it', 'live.com', 'live.it', 'msn.com', 'icloud.com',
    'me.com', 'mac.com', 'aol.com', 'libero.it', 'virgilio.it', 'tiscali.it', 'alice.it', 'tin.it', 'fastwebnet.it', 'email.it',
    'gmx.com', 'gmx.de', 'gmx.net', 'web.de', 't-online.de', 'orange.fr', 'free.fr', 'wanadoo.fr', 'laposte.net', 'sfr.fr',
    'proton.me', 'protonmail.com', 'mail.com', 'zoho.com', 'yandex.com', 'qq.com', 'bluewin.ch', 'pec.it'];

const SR_LIMITS = ['Solo clienti diretti: le richieste di agenzie, SB e Other non sono contate.', 'WhatsApp e telefono non sono visibili.', 'Le risposte da indirizzi Gmail personali non sono visibili.',
                   'Alcuni abbinamenti ambigui (stesso indirizzo su più richieste aperte) possono essere errati.',
                   'I lead arrivati dal modulo web non hanno una prima mail del cliente: la prima risposta non è misurabile.'];
const SR_FOOTER = 'Misura solo la posta aziendale.';

// ── Config ───────────────────────────────────────────────────────────────────

function sr_cfg(): array {
    static $c = null;
    if ($c !== null) return $c;
    $over = defined('SALES_RESPONSE_SELLERS') && is_array(SALES_RESPONSE_SELLERS) ? SALES_RESPONSE_SELLERS : [];
    $sellers = [];
    foreach (SR_SELLERS as $s) $sellers[] = array_merge($s, ['agent_id' => null], (array)($over[$s['name']] ?? []));
    $c = ['sellers'  => $sellers,
          'holidays' => array_flip(defined('SALES_RESPONSE_HOLIDAYS') && is_array(SALES_RESPONSE_HOLIDAYS) ? SALES_RESPONSE_HOLIDAYS : SR_HOLIDAYS),
          'to'       => defined('SALES_RESPONSE_TO') ? (string)SALES_RESPONSE_TO : 'savannah.explorers@gmail.com',
          'live'     => defined('SALES_RESPONSE_LIVE') && SALES_RESPONSE_LIVE,
          'base_url' => rtrim(defined('BASE_URL') ? (string)BASE_URL : 'https://hub.savannahexplorers.com', '/')];
    return $c;
}

function sr_mailboxes(): array {
    $m = array_map(function ($s) { return strtolower($s['mailbox']); }, sr_cfg()['sellers']);
    return array_values(array_unique(array_merge($m, SR_SHARED_BOXES)));
}

function sr_request_url(int $id): string {
    return sr_cfg()['base_url'] . '/modules/leads/request_view.php?id=' . $id;
}

function sr_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS mail_headers_index (
        id INT AUTO_INCREMENT PRIMARY KEY, mailbox VARCHAR(120) NOT NULL, folder VARCHAR(190) NOT NULL,
        file_key VARCHAR(190) NOT NULL, msg_key CHAR(40) NOT NULL, message_id VARCHAR(255) NULL, in_reply_to VARCHAR(255) NULL,
        refs TEXT NULL, date_utc DATETIME NOT NULL, date_src VARCHAR(6) NOT NULL DEFAULT 'header', from_addr VARCHAR(190) NOT NULL DEFAULT '',
        to_addrs TEXT NULL, subject VARCHAR(500) NULL, direction VARCHAR(10) NOT NULL, request_id INT NULL, match_rule VARCHAR(10) NULL,
        ambiguous TINYINT(1) NOT NULL DEFAULT 0, indexed_at DATETIME NOT NULL,
        UNIQUE KEY uq_mhi_file (mailbox, folder, file_key), KEY idx_mhi_msg (msg_key), KEY idx_mhi_mid (message_id(100)),
        KEY idx_mhi_date (date_utc), KEY idx_mhi_req (request_id, date_utc)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS sales_response_scan_state (
        mailbox VARCHAR(120) NOT NULL, folder VARCHAR(190) NOT NULL, last_mtime INT NOT NULL, files INT NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL, PRIMARY KEY (mailbox, folder)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS sales_response_run (
        id INT AUTO_INCREMENT PRIMARY KEY, ran_at DATETIME NOT NULL, ok TINYINT(1) NOT NULL, error TEXT NULL,
        counts_json TEXT NULL, mailed VARCHAR(20) NULL, KEY idx_srr_ran (ran_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS sales_response_reminder (
        id INT AUTO_INCREMENT PRIMARY KEY, sent_at DATETIME NOT NULL, agent_id INT NOT NULL, mailbox VARCHAR(120) NOT NULL,
        n INT NOT NULL, items_json TEXT NOT NULL, sent_by VARCHAR(60) NOT NULL, KEY idx_srm_sent (sent_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** Sellers with agent_id, agent_name, leave_names[] and error (null = mapping ok). */
function sr_sellers(PDO $db): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $out = [];
    foreach (sr_cfg()['sellers'] as $s) {
        $s['agent_name'] = null;
        $s['error'] = null;
        if ($s['agent_id']) {
            $st = $db->prepare("SELECT id, name FROM agents WHERE id = ?");
            $st->execute([(int)$s['agent_id']]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $st = $db->prepare("SELECT id, name FROM agents WHERE LOWER(TRIM(name)) = LOWER(?)");
            $st->execute([$s['agent']]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                $st = $db->prepare("SELECT id, name FROM agents WHERE name LIKE ?");
                $st->execute([$s['agent'] . ' %']);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        if (count($rows) === 1) {
            $s['agent_id'] = (int)$rows[0]['id'];
            $s['agent_name'] = $rows[0]['name'];
        } else {
            $s['agent_id'] = null;
            $s['error'] = count($rows) ? 'più agenti trovati per "' . $s['agent'] . '": ' . implode(', ', array_column($rows, 'name'))
                                       : 'nessun agente trovato per "' . $s['agent'] . '"';
        }
        $s['leave_names'] = [];
        try {
            $st = $db->prepare("SELECT DISTINCT employee_name FROM leave_entries WHERE LOWER(TRIM(employee_name)) = LOWER(?) OR employee_name LIKE ?");
            $st->execute([$s['leave'], $s['leave'] . ' %']);
            $s['leave_names'] = $st->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            // leave module never used: no leave_entries table
        }
        $out[] = $s;
    }
    return $cache = $out;
}

/** agent_id => ['Y-m-d' => true] leave days. */
function sr_leave_days(PDO $db, array $sellers): array {
    $out = [];
    foreach ($sellers as $s) {
        if (!$s['agent_id'] || !$s['leave_names']) continue;
        $in = implode(',', array_fill(0, count($s['leave_names']), '?'));
        $st = $db->prepare("SELECT start_date, end_date FROM leave_entries WHERE employee_name IN ($in)");
        $st->execute($s['leave_names']);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = strtotime($r['start_date'] . ' 12:00:00');
            $end = strtotime($r['end_date'] . ' 12:00:00');
            for ($i = 0; $d <= $end && $i < 400; $i++, $d += 86400) $out[$s['agent_id']][date('Y-m-d', $d)] = true;
        }
    }
    return $out;
}

// ── Maildir (CLI only) ───────────────────────────────────────────────────────

function sr_mail_root(): string {
    if (defined('SALES_RESPONSE_MAIL_ROOT')) return rtrim((string)SALES_RESPONSE_MAIL_ROOT, '/');
    $h = (string)getenv('HOME');
    if ($h === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $h = (string)(posix_getpwuid(posix_geteuid())['dir'] ?? '');
    }
    if ($h === '' && ($p = strpos(__DIR__, '/public_html')) !== false) $h = substr(__DIR__, 0, $p);
    return rtrim($h, '/') . '/mail';
}

/** Folders of one Maildir: name => path. INBOX + every ".Folder" with cur/, except trash / junk / drafts. */
function sr_folders(string $dir): array {
    $out = ['INBOX' => $dir];
    foreach ((array)@scandir($dir) as $e) {
        if (!is_string($e) || $e === '.' || $e === '..' || $e[0] !== '.' || !is_dir($dir . '/' . $e . '/cur')) continue;
        $name = substr($e, 1);
        if (preg_match('/trash|junk|spam|deleted|draft|bozze|cestino|indesiderat|outbox|bin$/i', $name)) continue;
        $out[$name] = $dir . '/' . $e;
    }
    return $out;
}

/**
 * The header block only (up to the first empty line, max 64 KB), or null if unreadable.
 * BlueHost's Dovecot stores the Maildir files gzip-compressed; gzopen() reads plain files unchanged too.
 */
function sr_read_header(string $file): ?string {
    $fh = @gzopen($file, 'rb');
    if (!$fh) return null;
    $raw = '';
    while (($line = gzgets($fh, 8192)) !== false) {
        if (rtrim($line, "\r\n") === '') break;
        $raw .= $line;
        if (strlen($raw) >= 65536) break;
    }
    gzclose($fh);
    return $raw;
}

/** lower-case header name => value (first occurrence, continuation lines unfolded). */
function sr_parse_headers(string $raw): array {
    $raw = preg_replace("/\r?\n[ \t]+/", ' ', $raw);
    $h = [];
    foreach (preg_split("/\r?\n/", $raw) as $line) {
        $p = strpos($line, ':');
        if ($p === false || $p === 0) continue;
        $k = strtolower(trim(substr($line, 0, $p)));
        if (!isset($h[$k])) $h[$k] = trim(substr($line, $p + 1));
    }
    return $h;
}

function sr_addrs(string $s): array {
    preg_match_all("/[a-z0-9._%+'=-]+@[a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,}/i", $s, $m);
    return array_values(array_unique(array_map('strtolower', $m[0])));
}

function sr_msgids(string $s): array {
    preg_match_all('/<([^<>\s]+)>/', $s, $m);
    return array_map('strtolower', $m[1]);
}

function sr_domain(string $a): string {
    $p = strrpos($a, '@');
    return $p === false ? '' : strtolower(substr($a, $p + 1));
}

function sr_is_own(string $a): bool {
    $a = strtolower(trim($a));
    if (in_array($a, SR_OWN_ADDRESSES, true)) return true;
    $d = sr_domain($a);
    foreach (SR_OWN_DOMAINS as $o) if ($d === $o || substr($d, -strlen('.' . $o)) === '.' . $o) return true;
    return false;
}

function sr_is_free(string $domain): bool {
    return in_array($domain, SR_FREEMAIL, true);
}

/**
 * client = from an outside address; savannah = from us to at least one outside address; internal = us to us;
 * auto = auto-replies, bulk / lists, bounces, no-reply senders (Hub notifications). Only client / savannah count.
 */
function sr_direction(array $h, string $from, array $rcpt): string {
    $as   = strtolower($h['auto-submitted'] ?? '');
    $prec = strtolower($h['precedence'] ?? '');
    if (($as !== '' && $as !== 'no') || isset($h['x-autoreply']) || isset($h['x-autorespond']) || isset($h['x-autoresponse'])
        || in_array($prec, ['auto_reply', 'bulk', 'junk', 'list'], true) || isset($h['list-id']) || isset($h['list-unsubscribe'])
        || stripos($h['content-type'] ?? '', 'multipart/report') === 0 || isset($h['x-failed-recipients'])
        || $from === '' || preg_match('/^(no-?reply|do-?not-?reply|mailer-daemon|postmaster|bounces?)[@+.-]/', $from)) {
        return 'auto';
    }
    if (sr_is_own($from)) {
        foreach ($rcpt as $a) if (!sr_is_own($a)) return 'savannah';
        return 'internal';
    }
    return 'client';
}

/** One index row from a header block (headers only). $mtime = file mtime, the fallback date. */
function sr_header_row(string $raw, int $mtime): array {
    $h = sr_parse_headers($raw);
    $from = sr_addrs($h['from'] ?? '')[0] ?? '';
    $rcpt = array_values(array_unique(array_merge(sr_addrs($h['to'] ?? ''), sr_addrs($h['cc'] ?? ''))));
    $ts = isset($h['date']) ? strtotime(preg_replace('/\s*\([^)]*\)\s*$/', '', $h['date'])) : false;
    $src = 'header';
    if (!$ts || $ts < 946684800 || $ts > max($mtime, time()) + 2 * 86400) {   // missing or absurd Date
        $ts = $mtime;
        $src = 'mtime';
    }
    $subject = (string)($h['subject'] ?? '');
    if ($subject !== '' && function_exists('iconv_mime_decode')) {
        $dec = @iconv_mime_decode($subject, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($dec !== false) $subject = $dec;
    }
    $subject = mb_substr(mb_convert_encoding($subject, 'UTF-8', 'UTF-8'), 0, 500);
    $mid  = sr_msgids($h['message-id'] ?? '')[0] ?? null;
    $refs = array_slice(sr_msgids($h['references'] ?? ''), -20);
    return ['message_id' => $mid !== null ? substr($mid, 0, 255) : null,
            'in_reply_to' => ($x = sr_msgids($h['in-reply-to'] ?? '')[0] ?? null) !== null ? substr($x, 0, 255) : null,
            'refs' => $refs ? implode(' ', $refs) : null, 'ts' => (int)$ts, 'date_src' => $src,
            'from' => substr($from, 0, 190), 'to' => implode(',', $rcpt), 'subject' => $subject,
            'direction' => sr_direction($h, $from, $rcpt)];
}

/**
 * Index new files of every mailbox / folder (incremental on file mtime; first run: $backfillDays back).
 * Throws if a Maildir is missing or unreadable — silence must never mean OK. Returns per-folder counts.
 */
function sr_scan(PDO $db, int $backfillDays = SR_BACKFILL_DAYS): array {
    $root = sr_mail_root();
    if (!is_dir($root)) throw new RuntimeException('Mail root not found: ' . $root);
    $state = [];
    foreach ($db->query("SELECT mailbox, folder, last_mtime FROM sales_response_scan_state") as $r) {
        $state[$r['mailbox'] . "\n" . $r['folder']] = (int)$r['last_mtime'];
    }
    $start = time();
    $floor = $start - $backfillDays * 86400;
    $oldest = $start - 400 * 86400;
    $ins = $db->prepare("INSERT IGNORE INTO mail_headers_index (mailbox, folder, file_key, msg_key, message_id, in_reply_to, refs, date_utc,
        date_src, from_addr, to_addrs, subject, direction, indexed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $up = $db->prepare("REPLACE INTO sales_response_scan_state (mailbox, folder, last_mtime, files, updated_at) VALUES (?,?,?,?,?)");
    $now = date('Y-m-d H:i:s');
    $out = [];
    foreach (sr_mailboxes() as $mb) {
        [$user, $dom] = explode('@', $mb, 2);
        $dir = $root . '/' . $dom . '/' . $user;
        if (!is_dir($dir . '/cur') || !is_readable($dir . '/cur')) throw new RuntimeException("Maildir not readable for $mb: $dir");
        foreach (sr_folders($dir) as $fname => $fpath) {
            $last = $state[$mb . "\n" . $fname] ?? $floor;
            $max = $last;
            $read = 0;
            $new = 0;
            foreach (['cur', 'new'] as $sub) {
                $d = $fpath . '/' . $sub;
                if (!is_dir($d)) continue;
                $dh = @opendir($d);
                if ($dh === false) throw new RuntimeException("Cannot open $d");
                while (($f = readdir($dh)) !== false) {
                    if ($f === '' || $f[0] === '.') continue;
                    $p = $d . '/' . $f;
                    $mt = @filemtime($p);
                    if ($mt === false || $mt < $last) continue;
                    if ($mt > $max) $max = $mt;
                    $raw = sr_read_header($p);
                    if ($raw === null || $raw === '') continue;
                    $read++;
                    $r = sr_header_row($raw, (int)$mt);
                    if ($r['ts'] < $oldest) continue;
                    $fk = substr(explode(':', $f, 2)[0], 0, 190);       // Maildir base name, without ":2,flags"
                    $mk = sha1($r['message_id'] !== null ? $r['message_id'] : $mb . '|' . $fname . '|' . $fk);
                    $ins->execute([$mb, mb_substr($fname, 0, 190), $fk, $mk, $r['message_id'], $r['in_reply_to'], $r['refs'],
                                   gmdate('Y-m-d H:i:s', $r['ts']), $r['date_src'], $r['from'], $r['to'], $r['subject'], $r['direction'], $now]);
                    $new += $ins->rowCount();
                }
                closedir($dh);
            }
            // Files delivered while scanning are read again next time (INSERT IGNORE).
            $up->execute([$mb, mb_substr($fname, 0, 190), max($last, min($max, $start - 120)), $read, $now]);
            $out[] = ['mailbox' => $mb, 'folder' => $fname, 'read' => $read, 'new' => $new];
        }
    }
    return $out;
}

/** Delete index rows older than 12 months. */
function sr_purge(PDO $db): int {
    return $db->exec("DELETE FROM mail_headers_index WHERE date_utc < '" . gmdate('Y-m-d H:i:s', strtotime('-' . SR_KEEP_MONTHS . ' months')) . "'");
}

// ── Requests + linking ───────────────────────────────────────────────────────

function sr_has_col(PDO $db, string $table, string $col): bool {
    static $c = [];
    $k = $table . '.' . $col;
    if (!isset($c[$k])) {
        $st = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $st->execute([$table, $col]);
        $c[$k] = (int)$st->fetchColumn() > 0;
    }
    return $c[$k];
}

/** "CustomerName(Agency-Agent)" part of a folder name, lower-case, or '' if too short to match safely. */
function sr_folder_code(string $pc): string {
    $code = preg_match('/([^_\/]+\([^)]*\))/', $pc, $m) ? $m[1] : $pc;
    $code = strtolower(trim($code));
    return strlen($code) >= 6 ? $code : '';
}

/** Requests that mail can belong to: received in the last 18 months or travelling soon. id => row. */
function sr_requests(PDO $db): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $upd = sr_has_col($db, 'requests', 'updated_at') ? ', r.updated_at' : ', NULL AS updated_at';
    $chn = sr_has_col($db, 'requests', 'channel') ? ', r.channel' : ', NULL AS channel';
    $rows = $db->query("SELECT r.id, r.agent_id, r.status, r.email, r.agency_id, r.practice_code, r.customer_name, r.date_received,
                               r.start_date $upd $chn, a.name AS agent_name
                        FROM requests r LEFT JOIN agents a ON a.id = r.agent_id
                        WHERE r.date_received >= CURDATE() - INTERVAL 18 MONTH OR r.start_date >= CURDATE() - INTERVAL 60 DAY")
               ->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $r['id'] = (int)$r['id'];
        $r['agent_id'] = (int)$r['agent_id'];
        $r['recv_ts'] = $r['date_received'] ? (int)strtotime(substr($r['date_received'], 0, 10) . ' 00:00:00') : 0;
        $r['code'] = sr_folder_code((string)$r['practice_code']);
        $r['closed'] = in_array($r['status'], SR_CLOSED, true);
        $ch = strtolower(trim((string)$r['channel']));
        $r['direct'] = $ch === 'direct' || ($ch === '' && !(int)$r['agency_id'] && stripos((string)$r['practice_code'], '-SB)') === false);
        $out[$r['id']] = $r;
    }
    return $cache = $out;
}

/** Contact maps: address => [request ids], domain (company domains only) => [request ids]. */
function sr_contact_maps(PDO $db, array $reqs): array {
    $agency = [];
    try {
        foreach ($db->query("SELECT id, email FROM agencies WHERE email IS NOT NULL AND email <> ''") as $a) {
            $agency[(int)$a['id']] = sr_addrs($a['email']);
        }
        foreach ($db->query("SELECT agency_id, email FROM agency_contacts WHERE email IS NOT NULL AND email <> ''") as $a) {
            $agency[(int)$a['agency_id']] = array_merge($agency[(int)$a['agency_id']] ?? [], sr_addrs($a['email']));
        }
    } catch (Throwable $e) {
        // agencies / agency_contacts missing: only requests.email is used
    }
    $byAddr = [];
    $byDom = [];
    foreach ($reqs as $id => $r) {
        $addrs = array_merge(sr_addrs((string)$r['email']), $agency[(int)$r['agency_id']] ?? []);
        foreach (array_unique($addrs) as $a) {
            if (sr_is_own($a)) continue;
            $byAddr[$a][$id] = true;
            $d = sr_domain($a);
            if ($d !== '' && !sr_is_free($d)) $byDom[$d][$id] = true;
        }
    }
    return [$byAddr, $byDom];
}

/** Best of several candidate requests: open before closed, then the most recent. */
function sr_pick(array $ids, array $reqs): int {
    usort($ids, function ($a, $b) use ($reqs) {
        return [$reqs[$a]['closed'], -$reqs[$a]['recv_ts'], -$a] <=> [$reqs[$b]['closed'], -$reqs[$b]['recv_ts'], -$b];
    });
    return (int)$ids[0];
}

/**
 * Link of one message: [request_id|null, rule|null, ambiguous]. Order:
 *  1. thread — In-Reply-To is a mail already linked (outgoing forwards "Fwd:/I:/TR:" excluded);
 *  2. address — the counterpart (From for client mail, outside To/Cc for ours) is the request email or an
 *     agency / agency contact address (or same company domain). Several candidates → References to a linked
 *     mail, then the folder name in the subject, else the most recent open request, flagged ambiguous;
 *  3. subject — folder name "Name(Agency-Agent)" in the subject, only for a free-mail counterpart (a client
 *     writing from another personal address; a supplier writing about the booking must not count as the client).
 * A request must exist ≤ 14 days after the mail (it is often created the day after the first client mail).
 * One of ours linked by thread counts only if its To/Cc reaches the client of that request (request / agency address,
 * company domain, or an address the client already wrote from: $clientAddr) — a forward from info@ to the seller, or a
 * note to a supplier in the same thread, is not an answer to the client (change request 19 Oct 2026).
 */
function sr_link_one(array $m, array $reqs, array $byAddr, array $byDom, array $known, array $clientAddr = []): array {
    $none = [null, null, 0];
    if ($m['direction'] !== 'client' && $m['direction'] !== 'savannah') return $none;
    $ts = strtotime($m['date_utc'] . ' UTC');
    $subj = strtolower((string)$m['subject']);
    $cp = $m['direction'] === 'client' ? [$m['from_addr']]
        : array_values(array_filter(explode(',', (string)$m['to_addrs']), function ($a) { return $a !== '' && !sr_is_own($a); }));
    if (!$cp) return $none;
    $alive = function ($id) use ($reqs, $ts) { return isset($reqs[$id]) && $reqs[$id]['recv_ts'] <= $ts + 14 * 86400; };
    $fwd = (bool)preg_match('/^\s*(fwd?|i|tr|wg|inoltr\w*)\s*:/i', (string)$m['subject']);

    $reaches = function (int $id) use ($m, $cp, $byAddr, $byDom, $clientAddr) {
        if ($m['direction'] === 'client') return true;
        foreach ($cp as $a) {
            $d = sr_domain($a);
            if (isset($byAddr[$a][$id]) || isset($clientAddr[$id][$a]) || ($d !== '' && !sr_is_free($d) && isset($byDom[$d][$id]))) return true;
        }
        return false;
    };

    $irt = strtolower((string)$m['in_reply_to']);
    if ($irt !== '' && isset($known[$irt]) && !($m['direction'] === 'savannah' && $fwd) && $reaches($known[$irt])) return [$known[$irt], 'thread', 0];

    $cand = [];
    foreach ($cp as $a) {
        foreach (array_keys($byAddr[$a] ?? []) as $id) $cand[$id] = true;
        $d = sr_domain($a);
        if ($d !== '' && !sr_is_free($d)) foreach (array_keys($byDom[$d] ?? []) as $id) $cand[$id] = true;
    }
    $cand = array_values(array_filter(array_keys($cand), $alive));
    if (count($cand) === 1) return [$cand[0], 'address', 0];
    if ($cand) {
        foreach (array_reverse(array_filter(explode(' ', (string)$m['refs']))) as $ref) {
            if (isset($known[$ref]) && in_array($known[$ref], $cand, true)) return [$known[$ref], 'thread', 0];
        }
        $bySub = array_values(array_filter($cand, function ($id) use ($reqs, $subj) {
            return $reqs[$id]['code'] !== '' && strpos($subj, $reqs[$id]['code']) !== false;
        }));
        if (count($bySub) === 1) return [$bySub[0], 'subject', 0];
        return [sr_pick($bySub ?: $cand, $reqs), 'address', 1];
    }

    $free = false;
    foreach ($cp as $a) if (sr_is_free(sr_domain($a))) $free = true;
    if (!$free || $subj === '') return $none;
    $bySub = [];
    foreach ($reqs as $id => $r) {
        if ($r['code'] !== '' && $alive($id) && strpos($subj, $r['code']) !== false) $bySub[] = $id;
    }
    if (!$bySub) return $none;
    return [sr_pick($bySub, $reqs), 'subject', count($bySub) > 1 ? 1 : 0];
}

/** Recompute the links of the last $days days (unique messages in date order). Returns counts. */
function sr_link(PDO $db, int $days = SR_RELINK_DAYS): array {
    $reqs = sr_requests($db);
    [$byAddr, $byDom] = sr_contact_maps($db, $reqs);
    $since = gmdate('Y-m-d H:i:s', time() - $days * 86400);
    $known = [];        // message_id => request_id
    $clientAddr = [];   // request_id => [address the client wrote from => true]
    $st = $db->prepare("SELECT message_id, request_id, direction, from_addr FROM mail_headers_index
                        WHERE request_id IS NOT NULL AND date_utc < ? AND date_utc >= ?");
    $st->execute([$since, gmdate('Y-m-d H:i:s', time() - ($days + 120) * 86400)]);
    foreach ($st as $r) {
        if ($r['message_id'] !== null) $known[$r['message_id']] = (int)$r['request_id'];
        if ($r['direction'] === 'client') $clientAddr[(int)$r['request_id']][$r['from_addr']] = true;
    }

    $st = $db->prepare("SELECT msg_key, message_id, in_reply_to, refs, date_utc, from_addr, to_addrs, subject, direction, request_id,
                               match_rule, ambiguous FROM mail_headers_index WHERE date_utc >= ? ORDER BY date_utc, id");
    $st->execute([$since]);
    $msgs = [];
    foreach ($st as $r) {
        $k = $r['msg_key'];
        if (!isset($msgs[$k])) { $msgs[$k] = $r; $msgs[$k]['mixed'] = false; continue; }
        if ($msgs[$k]['request_id'] !== $r['request_id'] || $msgs[$k]['match_rule'] !== $r['match_rule']) $msgs[$k]['mixed'] = true;
    }
    $up = $db->prepare("UPDATE mail_headers_index SET request_id = ?, match_rule = ?, ambiguous = ? WHERE msg_key = ?");
    $n = ['messages' => count($msgs), 'linked' => 0, 'ambiguous' => 0, 'changed' => 0];
    foreach ($msgs as $k => $m) {
        [$rid, $rule, $amb] = sr_link_one($m, $reqs, $byAddr, $byDom, $known, $clientAddr);
        if ($rid !== null) {
            $n['linked']++;
            if ($amb) $n['ambiguous']++;
            if ($m['message_id'] !== null) $known[$m['message_id']] = $rid;
            if ($m['direction'] === 'client') $clientAddr[$rid][$m['from_addr']] = true;
        }
        $old = $m['request_id'] === null ? null : (int)$m['request_id'];
        if ($m['mixed'] || $old !== $rid || $m['match_rule'] !== $rule || (int)$m['ambiguous'] !== $amb) {
            $up->execute([$rid, $rule, $amb, $k]);
            $n['changed']++;
        }
    }
    return $n;
}

// ── Metrics ──────────────────────────────────────────────────────────────────

/** Monday (Y-m-d) of the week containing $date; default: last complete week. */
function sr_week_start(?string $date = null): string {
    $ts = ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date)) ? strtotime($date . ' 12:00:00') : time() - 7 * 86400;
    return date('Y-m-d', $ts - ((int)date('N', $ts) - 1) * 86400);
}

function sr_week_of(int $ts): string {
    return date('Y-m-d', $ts - ((int)date('N', $ts) - 1) * 86400);
}

/** Evening / night (18:00–08:00 EAT), Saturday, Sunday or Tanzanian public holiday. */
function sr_offhours(int $ts): bool {
    $h = (int)date('G', $ts);
    return $h >= 18 || $h < 8 || (int)date('N', $ts) >= 6 || isset(sr_cfg()['holidays'][date('Y-m-d', $ts)]);
}

/**
 * Unique linked client / savannah messages since $sinceTs, in date order. One Message-ID = one message, however many
 * mailboxes hold a copy (client mail To seller + Cc info@ counts once); 'boxes' = the mailboxes holding it.
 */
function sr_messages(PDO $db, int $sinceTs): array {
    $st = $db->prepare("SELECT msg_key, MIN(date_utc) AS date_utc, MAX(direction) AS direction, MAX(from_addr) AS from_addr,
                               MAX(request_id) AS request_id, MAX(ambiguous) AS ambiguous, MAX(subject) AS subject,
                               GROUP_CONCAT(DISTINCT mailbox ORDER BY mailbox) AS boxes
                        FROM mail_headers_index WHERE request_id IS NOT NULL AND direction IN ('client','savannah') AND date_utc >= ?
                        GROUP BY msg_key ORDER BY MIN(date_utc), msg_key");
    $st->execute([gmdate('Y-m-d H:i:s', $sinceTs)]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['ts' => (int)strtotime($r['date_utc'] . ' UTC'), 'dir' => $r['direction'], 'from' => $r['from_addr'],
                  'request_id' => (int)$r['request_id'], 'ambiguous' => (int)$r['ambiguous'], 'subject' => (string)$r['subject'],
                  'boxes' => array_filter(explode(',', (string)$r['boxes']))];
    }
    return $out;
}

/** "info@, alessia@" — the mailboxes a client wrote to, shared ones first. */
function sr_boxes_label(array $boxes): string {
    $boxes = array_values(array_unique($boxes));
    usort($boxes, function ($a, $b) {
        return [!in_array($a, SR_SHARED_BOXES, true), $a] <=> [!in_array($b, SR_SHARED_BOXES, true), $b];
    });
    return implode(', ', array_map(function ($b) { return strstr($b, '@', true) . '@'; }, $boxes));
}

/**
 * Waits of one request. Several client mails in a row = one wait, from the first of them to the next
 * savannah mail. new_lead = first wait of a new request, with no savannah mail before it.
 */
function sr_episodes(array $req, array $msgs): array {
    $eps = [];
    $ep = null;
    $sent = false;
    foreach ($msgs as $m) {
        if ($m['dir'] === 'client') {
            if ($ep === null) {
                $ep = ['request_id' => $req['id'], 'agent_id' => $req['agent_id'], 'start' => $m['ts'], 'last' => $m['ts'], 'n' => 1,
                       'client' => $m['from'], 'subject' => $m['subject'], 'answered' => null, 'by' => null, 'resp_h' => null, 'ambiguous' => $m['ambiguous'],
                       'boxes' => $m['boxes'], 'new_lead' => !$sent && !$eps && $req['recv_ts'] >= $m['ts'] - 14 * 86400];
            } else {
                $ep['last'] = $m['ts'];
                $ep['n']++;
                $ep['boxes'] = array_merge($ep['boxes'], $m['boxes']);
                $ep['client'] = $m['from'];
                $ep['subject'] = $m['subject'];
                $ep['ambiguous'] = max($ep['ambiguous'], $m['ambiguous']);
            }
        } else {
            $sent = true;
            if ($ep !== null) {
                $ep['answered'] = $m['ts'];
                $ep['by'] = $m['from'];
                $ep['resp_h'] = round(($m['ts'] - $ep['start']) / 3600, 2);
                $eps[] = $ep;
                $ep = null;
            }
        }
    }
    if ($ep !== null) $eps[] = $ep;
    return $eps;
}

function sr_percentile(array $v, float $p): ?float {
    if (!$v) return null;
    sort($v);
    return round($v[max(0, (int)ceil($p * count($v)) - 1)], 1);
}

function sr_pct(int $a, int $b): ?float {
    return $b > 0 ? round(100 * $a / $b, 1) : null;
}

/** Metrics of a set of waits (already filtered: seller, weeks, leave days if excluded) + message volumes. */
function sr_metrics(array $eps, int $clientMsgs, int $sentMsgs, int $now): array {
    $due = $ok = $offDue = $offOk = 0;
    $resp = $newResp = [];
    $newN = 0;
    foreach ($eps as $e) {
        if ($e['answered'] !== null) $resp[] = $e['resp_h'];
        if ($e['new_lead']) {
            $newN++;
            if ($e['answered'] !== null) $newResp[] = $e['resp_h'];
        }
        if ($e['start'] + SR_THRESHOLD_H * 3600 > $now) continue;      // not yet due
        $in24 = $e['answered'] !== null && $e['resp_h'] <= SR_THRESHOLD_H;
        $due++;
        if ($in24) $ok++;
        if (sr_offhours($e['start'])) {
            $offDue++;
            if ($in24) $offOk++;
        }
    }
    return ['due' => $due, 'answered_24h' => $ok, 'pct_24h' => sr_pct($ok, $due), 'late' => $due - $ok,
            'median_h' => sr_percentile($resp, 0.5), 'p90_h' => sr_percentile($resp, 0.9), 'answered' => count($resp),
            'off_due' => $offDue, 'off_answered_24h' => $offOk, 'pct_off_24h' => sr_pct($offOk, $offDue),
            'new_leads' => $newN, 'new_leads_median_h' => sr_percentile($newResp, 0.5),
            'client_msgs' => $clientMsgs, 'sent_msgs' => $sentMsgs];
}

/**
 * The report shown by the weekly email, the Hub page and the Agent API.
 * $week: any date of the week (default last complete week); $nPrev: previous weeks pooled as "avg"
 * (pooled = all their waits together, not the mean of weekly percentages).
 * $excludeLeave: leave out of the KPIs the waits that start on a seller's leave day. Off by default (Roberto,
 * 18 Oct 2026: sellers on leave must still answer); on request only (page checkbox, API exclude_leave=1).
 * Leave days are always shown as a note (in_ferie / on_leave).
 * $range: any period instead of a week — ['from', 'to'] (Y-m-d, inclusive) and optionally ['prev_from', 'prev_to'],
 * the period it is compared with (default: the same number of days just before). Then 'week' / 'prev' are that
 * period and the previous one, 'weeks' has the two buckets 'prev' and 'cur'; $week and $nPrev are ignored.
 */
function sr_report(PDO $db, ?string $week = null, ?int $agentId = null, int $nPrev = 4, bool $excludeLeave = false, ?array $range = null): array {
    sr_schema($db);
    $now = time();
    if ($range) {
        $curFrom = strtotime($range['from'] . ' 00:00:00');
        $toTs = strtotime($range['to'] . ' 00:00:00') + 86400;
        $prevFrom = !empty($range['prev_from']) ? strtotime($range['prev_from'] . ' 00:00:00') : $curFrom - ($toTs - $curFrom);
        $fromTs = $prevFrom;
        $ws = 'cur';
        $weeks = ['prev', 'cur'];
        $nPrev = 1;
        $bucketOf = function (int $ts) use ($curFrom) { return $ts >= $curFrom ? 'cur' : 'prev'; };
        $period = ['mode' => 'range', 'from' => date('Y-m-d', $curFrom), 'to' => date('Y-m-d', $toTs - 86400),
                   'prev_from' => date('Y-m-d', $prevFrom), 'prev_to' => date('Y-m-d', $curFrom - 86400)];
    } else {
        $ws = sr_week_start($week);
        $weeks = [];
        for ($i = $nPrev; $i >= 0; $i--) $weeks[] = date('Y-m-d', strtotime($ws . ' 12:00:00') - 7 * $i * 86400);
        $fromTs = strtotime($weeks[0] . ' 00:00:00');
        $toTs = strtotime($ws . ' 00:00:00') + 7 * 86400;
        $bucketOf = 'sr_week_of';
        $period = ['mode' => 'week', 'from' => $ws, 'to' => date('Y-m-d', $toTs - 86400),
                   'prev_from' => $weeks[0], 'prev_to' => date('Y-m-d', strtotime($ws . ' 12:00:00') - 86400)];
    }

    $sellers = sr_sellers($db);
    if ($agentId) $sellers = array_values(array_filter($sellers, function ($s) use ($agentId) { return $s['agent_id'] === $agentId; }));
    $byAgent = [];
    foreach ($sellers as $s) if ($s['agent_id']) $byAgent[$s['agent_id']] = $s;
    $reqs = sr_requests($db);
    $leave = sr_leave_days($db, $sellers);
    $scopeTs = $now - SR_SCOPE_DAYS * 86400;

    $perReq = [];
    foreach (sr_messages($db, min($fromTs, $scopeTs) - 30 * 86400) as $m) {
        $r = $reqs[$m['request_id']] ?? null;
        if ($r && isset($byAgent[$r['agent_id']]) && ($r['direct'] || !SR_DIRECT_ONLY)) $perReq[$m['request_id']][] = $m;
    }

    $eps = [];          // agent_id => week => [waits in the KPIs]
    $vol = [];          // agent_id => week => [client, sent]
    $leaveSkipped = []; // agent_id => week => n
    $unanswered = $newLeads = $silent = [];
    $today = date('Y-m-d', $now);
    foreach ($perReq as $rid => $msgs) {
        $r = $reqs[$rid];
        $aid = $r['agent_id'];
        $lastTs = 0;
        foreach ($msgs as $m) {
            $lastTs = max($lastTs, $m['ts']);
            if ($m['ts'] < $fromTs || $m['ts'] >= $toTs) continue;
            $w = $bucketOf($m['ts']);
            $vol[$aid][$w][$m['dir'] === 'client' ? 0 : 1] = ($vol[$aid][$w][$m['dir'] === 'client' ? 0 : 1] ?? 0) + 1;
        }
        $open = !$r['closed'] && ($r['status'] === 'Booked'
            ? ($r['start_date'] === null || $r['start_date'] >= $today)
            : ($r['recv_ts'] >= $scopeTs || $lastTs >= $scopeTs || ($r['updated_at'] && strtotime($r['updated_at']) >= $scopeTs)));
        $base = ['agent_id' => $aid, 'seller' => $byAgent[$aid]['name'], 'request_id' => $rid, 'folder' => $r['practice_code'],
                 'customer' => $r['customer_name'], 'status' => $r['status'], 'url' => sr_request_url($rid)];
        foreach (sr_episodes($r, $msgs) as $e) {
            $onLeave = isset($leave[$aid][date('Y-m-d', $e['start'])]);
            if ($e['start'] >= $fromTs && $e['start'] < $toTs) {
                $w = $bucketOf($e['start']);
                if ($onLeave && $excludeLeave) $leaveSkipped[$aid][$w] = ($leaveSkipped[$aid][$w] ?? 0) + 1;
                else $eps[$aid][$w][] = $e;
                if ($e['new_lead'] && $w === $ws) {
                    $newLeads[] = $base + ['first_client_at' => date('Y-m-d H:i', $e['start']),
                                           'first_reply_at' => $e['answered'] ? date('Y-m-d H:i', $e['answered']) : null,
                                           'hours' => $e['resp_h'], 'answered_by' => $e['by'], 'on_leave' => $onLeave,
                                           'written_to' => sr_boxes_label($e['boxes'])];
                }
            }
            if ($open && $e['answered'] === null && $now - $e['start'] > SR_THRESHOLD_H * 3600) {
                $unanswered[] = $base + ['client' => $e['client'], 'subject' => $e['subject'], 'first_client_at' => date('Y-m-d H:i', $e['start']),
                                         'last_client_at' => date('Y-m-d H:i', $e['last']), 'client_msgs' => $e['n'],
                                         'hours_waiting' => round(($now - $e['start']) / 3600, 1), 'written_to' => sr_boxes_label($e['boxes']),
                                         'in_ferie' => $onLeave || isset($leave[$aid][$today]), 'ambiguous' => (bool)$e['ambiguous']];
            }
        }
        $lastMsg = end($msgs);
        if ($open && $r['status'] !== 'Booked' && $lastMsg['dir'] === 'savannah' && $now - $lastMsg['ts'] >= SR_SILENT_DAYS * 86400) {
            $silent[] = $base + ['last_sent_at' => date('Y-m-d H:i', $lastMsg['ts']), 'days' => (int)floor(($now - $lastMsg['ts']) / 86400)];
        }
    }
    usort($unanswered, function ($a, $b) { return $b['hours_waiting'] <=> $a['hours_waiting']; });
    usort($newLeads, function ($a, $b) { return [$a['seller'], $a['first_client_at']] <=> [$b['seller'], $b['first_client_at']]; });
    usort($silent, function ($a, $b) { return [$a['seller'], -$a['days']] <=> [$b['seller'], -$b['days']]; });

    $rows = [];
    foreach ($sellers as $s) {
        $aid = $s['agent_id'];
        $perWeek = [];
        $prevEps = $allEps = [];
        $prevC = $prevS = $prevLeave = 0;
        foreach ($weeks as $w) {
            $e = $aid ? ($eps[$aid][$w] ?? []) : [];
            $c = $aid ? (int)($vol[$aid][$w][0] ?? 0) : 0;
            $snt = $aid ? (int)($vol[$aid][$w][1] ?? 0) : 0;
            $perWeek[$w] = sr_metrics($e, $c, $snt, $now) + ['on_leave_excluded' => $aid ? (int)($leaveSkipped[$aid][$w] ?? 0) : 0];
            $allEps = array_merge($allEps, $e);
            if ($w !== $ws) {
                $prevEps = array_merge($prevEps, $e);
                $prevC += $c;
                $prevS += $snt;
                $prevLeave += $perWeek[$w]['on_leave_excluded'];
            }
        }
        $rows[] = ['name' => $s['name'], 'agent_id' => $aid, 'agent_name' => $s['agent_name'], 'mailbox' => $s['mailbox'],
                   'leave_names' => $s['leave_names'], 'mapping_error' => $s['error'],
                   'week' => $perWeek[$ws],
                   'prev' => $nPrev ? sr_metrics($prevEps, $prevC, $prevS, $now) + ['on_leave_excluded' => $prevLeave, 'weeks' => $nPrev] : null,
                   'total' => sr_metrics($allEps, $prevC + $perWeek[$ws]['client_msgs'], $prevS + $perWeek[$ws]['sent_msgs'], $now)
                              + ['on_leave_excluded' => $prevLeave + $perWeek[$ws]['on_leave_excluded'], 'weeks' => $nPrev + 1],
                   'weeks' => $perWeek,
                   'open_unanswered' => count(array_filter($unanswered, function ($u) use ($aid) { return $u['agent_id'] === $aid; }))];
    }
    return ['ok' => true, 'week' => ['start' => $period['from'], 'end' => $period['to']], 'period' => $period, 'previous_weeks' => $nPrev,
            'generated_at' => date('Y-m-d H:i:s', $now), 'threshold_hours' => SR_THRESHOLD_H, 'timezone' => 'Africa/Dar_es_Salaam',
            'live' => sr_cfg()['live'], 'leave_excluded' => $excludeLeave, 'sellers' => $rows, 'unanswered' => $unanswered, 'new_leads' => $newLeads, 'silent' => $silent,
            'last_scan' => sr_last_run($db), 'limits' => SR_LIMITS, 'footer' => SR_FOOTER];
}

// ── Reminders to a seller ────────────────────────────────────────────────────

/** Stable key of an unanswered wait (the page checkboxes, the reminder log). */
function sr_unanswered_key(array $u): string {
    return $u['request_id'] . '|' . $u['first_client_at'];
}

/** unanswered key => last reminder time (Y-m-d H:i), reminders of the last 60 days. */
function sr_reminded(PDO $db): array {
    sr_schema($db);
    $out = [];
    $st = $db->prepare("SELECT sent_at, items_json FROM sales_response_reminder WHERE sent_at >= ? ORDER BY sent_at");
    $st->execute([date('Y-m-d H:i:s', time() - 60 * 86400)]);
    foreach ($st as $r) {
        foreach ((array)json_decode((string)$r['items_json'], true) as $k) $out[(string)$k] = substr($r['sent_at'], 0, 16);
    }
    return $out;
}

/** Reminder mail to one seller (English: the sales team is mixed). Headers-derived data only. [subject, html, text] */
function sr_reminder_email(array $seller, array $items): array {
    $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $n = count($items);
    $subject = '[Sales Response] Reminder: ' . $n . ' client mail' . ($n === 1 ? '' : 's') . ' waiting more than ' . SR_THRESHOLD_H . 'h';
    $intro = 'Hi ' . $seller['name'] . ', ' . ($n === 1 ? 'this client mail has' : 'these client mails have')
           . ' been waiting for a reply for more than ' . SR_THRESHOLD_H . ' hours:';
    $outro = 'Please reply today. If one of them is already handled (by phone, WhatsApp, or from another address), just reply to this mail and say so.';
    $td = 'style="padding:6px 8px;border-bottom:1px solid #eee;text-align:left;vertical-align:top;"';
    $html = '<p>' . $h($intro) . '</p><table style="border-collapse:collapse;font-size:13px;"><tr>';
    foreach (['Request', 'Client', 'Subject', 'Last client mail', 'Written to', 'Waiting'] as $c) $html .= '<th ' . $td . '>' . $h($c) . '</th>';
    $html .= '</tr>';
    $txt = $intro . "\n\n";
    foreach ($items as $u) {
        $req = $u['folder'] ?: '#' . $u['request_id'];
        $client = trim($u['customer'] . ' <' . $u['client'] . '>');
        $wait = sr_fmt_h($u['hours_waiting'], true);
        $to = $u['written_to'] ?? '';
        $html .= '<tr><td ' . $td . '><a href="' . $h($u['url']) . '">' . $h($req) . '</a></td><td ' . $td . '>' . $h($client) . '</td><td ' . $td . '>'
               . $h($u['subject'] ?? '') . '</td><td ' . $td . '>' . $h($u['last_client_at']) . '</td><td ' . $td . '>' . $h($to) . '</td><td ' . $td . '>' . $h($wait) . '</td></tr>';
        $txt .= '- ' . $req . ' | ' . $client . ' | ' . ($u['subject'] ?? '') . ' | last mail ' . $u['last_client_at'] . ($to !== '' ? ' to ' . $to : '')
              . ' | waiting ' . $wait . "\n   " . $u['url'] . "\n";
    }
    $html .= '</table><p>' . $h($outro) . '</p>';
    $txt .= "\n" . $outro . "\n";
    return [$subject, $html, $txt];
}

/**
 * Remind one seller of their unanswered client mails (> SR_THRESHOLD_H, as in sr_report()). $keys = sr_unanswered_key()
 * values to include (empty = all of that seller). Mail from the Hub to the seller's mailbox, reply-to SALES_RESPONSE_TO
 * (Roberto); send_hub_email() adds the signature of the logged-in sender. Logged in sales_response_reminder.
 * $confirm false = preview only. Returns ['ok', 'to', 'n', 'subject', 'text', 'sent'] or ['ok' => false, 'error'].
 */
function sr_send_reminder(PDO $db, int $agentId, array $keys, string $by, bool $confirm = true): array {
    $seller = null;
    foreach (sr_sellers($db) as $s) if ($s['agent_id'] === $agentId) $seller = $s;
    if (!$seller) return ['ok' => false, 'error' => 'Not a seller in Sales Response: agent ' . $agentId];
    // The scheduled scan runs once a day: read the mails delivered since, so a mail answered today is not reminded.
    try {
        if ((int)$db->query("SELECT GET_LOCK('hub_sales_response_run', 5)")->fetchColumn()) {
            try {
                sr_schema($db);
                sr_scan($db);
                sr_link($db);
            } finally {
                $db->query("SELECT RELEASE_LOCK('hub_sales_response_run')");
            }
        }
    } catch (Throwable $e) {
        error_log('Sales Response reminder: scan before the reminder failed: ' . $e->getMessage());
    }
    $want = array_flip(array_map('strval', $keys));
    $items = array_values(array_filter(sr_report($db, null, $agentId, 0)['unanswered'], function ($u) use ($want) {
        return !$want || isset($want[sr_unanswered_key($u)]);
    }));
    if (!$items) return ['ok' => false, 'error' => 'No unanswered client mail to remind ' . $seller['name'] . ' of (already answered?)'];
    [$subject, $html, $text] = sr_reminder_email($seller, $items);
    $res = ['ok' => true, 'to' => $seller['mailbox'], 'n' => count($items), 'subject' => $subject, 'text' => $text, 'sent' => false];
    if (!$confirm) return $res;
    require_once __DIR__ . '/../../../includes/mail_helper.php';
    if (!send_hub_email($seller['mailbox'], $subject, $html, 'Savannah Explorers Hub', 'noreply@savannahexplorers.com', sr_cfg()['to'], $text)) {
        return ['ok' => false, 'error' => 'The mail to ' . $seller['mailbox'] . ' could not be sent'];
    }
    $db->prepare("INSERT INTO sales_response_reminder (sent_at, agent_id, mailbox, n, items_json, sent_by) VALUES (?,?,?,?,?,?)")
       ->execute([date('Y-m-d H:i:s'), $agentId, $seller['mailbox'], count($items), json_encode(array_map('sr_unanswered_key', $items)), substr($by, 0, 60)]);
    return ['sent' => true] + $res;
}

// ── Runs ─────────────────────────────────────────────────────────────────────

function sr_log_run(PDO $db, bool $ok, ?string $error, ?array $counts, string $mailed): void {
    $db->prepare("INSERT INTO sales_response_run (ran_at, ok, error, counts_json, mailed) VALUES (?,?,?,?,?)")
       ->execute([date('Y-m-d H:i:s'), $ok ? 1 : 0, $error, $counts === null ? null : json_encode($counts, JSON_UNESCAPED_SLASHES), $mailed]);
}

/** Last run + last good run (for the page / API "is it working?"). */
function sr_last_run(PDO $db): array {
    $last = $db->query("SELECT ran_at, ok, error, mailed FROM sales_response_run ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$last) return ['status' => 'never_run', 'message' => 'modules/leads/sales_response_scan.php has not run on the server yet.'];
    $good = $db->query("SELECT ran_at FROM sales_response_run WHERE ok = 1 ORDER BY id DESC LIMIT 1")->fetchColumn();
    $ageH = $good ? round((time() - strtotime($good)) / 3600, 1) : null;
    return ['status' => !$last['ok'] ? 'scan_failed' : ($ageH !== null && $ageH > 26 ? 'stale' : 'ok'),
            'last_run' => ['at' => $last['ran_at'], 'ok' => (bool)$last['ok'], 'error' => $last['error'], 'mailed' => $last['mailed']],
            'last_good_scan' => $good ?: null, 'hours_ago' => $ageH];
}

/** Has a mail of this kind already gone out today (EAT)? */
function sr_mailed_today(PDO $db, string $kind): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM sales_response_run WHERE mailed LIKE ? AND ran_at >= ?");
    $st->execute(['%' . $kind . '%', date('Y-m-d') . ' 00:00:00']);
    return (int)$st->fetchColumn() > 0;
}

// ── Run (cPanel cron CLI and cron-job.org URL) ───────────────────────────────

/** Send one sr_email() result, or with $print return it as text instead. */
function sr_send(array $mail, bool $print, string &$out): bool {
    [$subject, $html, $text] = $mail;
    if ($print) {
        $out .= "--- would send to " . sr_cfg()['to'] . " ---\nSubject: $subject\n\n$text\n";
        return true;
    }
    require_once __DIR__ . '/../../../includes/mail_helper.php';
    return send_hub_email(sr_cfg()['to'], $subject, $html, 'Savannah Explorers Hub', 'noreply@savannahexplorers.com', '', $text);
}

/**
 * One run: incremental scan + relink + purge, then the mails that are due. Used by sales_response_scan.php (CLI)
 * and sales_response_cron.php (cron-job.org), so both behave the same. Options (all optional, false by default):
 *   cron    hourly mode: 07:xx EAT daily alert (only LIVE and something to report), Monday 07:xx weekly report
 *   daily / weekly / report   send that mail now; week = 'YYYY-MM-DD' for weekly
 *   print   collect the mails in 'output' instead of sending; no_scan  skip the scan
 * A run already in progress (MySQL lock) → ['skipped' => true]. Errors are thrown: the caller passes them to
 * sr_run_failed(). Returns ['counts', 'mailed', 'failed', 'output', 'line'].
 */
function sr_run(PDO $db, array $o = []): array {
    $print = !empty($o['print']);
    $today = date('Y-m-d');
    $out = '';
    if (!(int)$db->query("SELECT GET_LOCK('hub_sales_response_run', 0)")->fetchColumn()) {
        return ['skipped' => true, 'line' => date('Y-m-d H:i:s') . ' EAT — SALESRESP: another run is in progress, skipped'];
    }
    try {
        sr_schema($db);
        $cfg = sr_cfg();
        $first = !(int)$db->query("SELECT COUNT(*) FROM sales_response_scan_state")->fetchColumn();
        $scan = [];
        $counts = [];
        if (empty($o['no_scan'])) {
            $scan = sr_scan($db);
            $link = sr_link($db);
            $purged = sr_purge($db);
            $counts = ['files_read' => array_sum(array_column($scan, 'read')), 'new_rows' => array_sum(array_column($scan, 'new')),
                       'folders' => count($scan), 'purged' => $purged] + $link;
        }

        $cron = !empty($o['cron']);
        $atSeven = (int)date('G') === 7;
        $send = [];
        if ($first || !empty($o['report'])) $send[] = 'first';
        if (!empty($o['daily']) || ($cron && $atSeven && $cfg['live'] && !sr_mailed_today($db, 'daily'))) $send[] = 'daily';
        if (!empty($o['weekly']) || ($cron && $atSeven && date('N') === '1' && !sr_mailed_today($db, 'weekly'))) $send[] = 'weekly';

        $mailed = [];
        $failed = false;
        foreach ($send as $type) {
            if ($type === 'first') $rep = sr_report($db, $today, null, 12);
            elseif ($type === 'weekly') $rep = sr_report($db, $o['week'] ?? null);
            else $rep = sr_report($db, null, null, 0);
            if ($type === 'daily' && !$rep['unanswered'] && empty($o['daily'])) continue;   // nothing to report
            if (sr_send(sr_email($type, $rep, ['date' => $today, 'scan' => $scan]), $print, $out)) $mailed[] = $type;
            else $failed = true;
        }
        $mailedStr = $failed ? 'mail_failed' : ($mailed ? implode('+', $mailed) : 'none');
        if (!$print) sr_log_run($db, true, null, $counts ?: null, $mailedStr);
        $line = date('Y-m-d H:i:s') . ' EAT — SALESRESP: ' . json_encode($counts) . '; mailed=' . ($print ? 'print:' : '') . $mailedStr;
        return ['counts' => $counts, 'mailed' => $mailedStr, 'failed' => $failed, 'output' => $out, 'line' => $line];
    } finally {
        $db->query("SELECT RELEASE_LOCK('hub_sales_response_run')");
    }
}

/** Error text of a failed run, as logged and mailed. */
function sr_error_text(Throwable $e): string {
    return get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
}

/** A failed run: SCAN FAILED mail (at most once a day; without a DB a stamp file decides) and run log. */
function sr_run_failed(?PDO $db, string $err, bool $print = false): void {
    $today = date('Y-m-d');
    $stamp = sys_get_temp_dir() . '/sales_response_failed_' . $today;
    $already = false;
    try {
        $already = $db ? sr_mailed_today($db, 'failed') : is_file($stamp);
    } catch (Throwable $e2) {
        $already = is_file($stamp);
    }
    $out = '';
    $sent = $already ? false : sr_send(sr_email('failed', null, ['date' => $today, 'error' => $err]), $print, $out);
    if ($print) echo $out;
    if ($sent && !$print) @touch($stamp);
    if ($db && !$print) {
        try {
            sr_log_run($db, false, $err, null, $already ? 'none' : ($sent ? 'failed' : 'mail_failed'));
        } catch (Throwable $e2) {
            error_log('Sales Response run log failed: ' . $e2->getMessage());
        }
    }
}

// ── Email ────────────────────────────────────────────────────────────────────

function sr_fmt_h(?float $h, bool $en = false): string {
    if ($h === null) return '–';
    $dec = $en ? '.' : ',';
    return $h >= 48 ? number_format($h / 24, 1, $dec, '') . ($en ? ' d' : ' g') : number_format($h, 1, $dec, '') . ' h';
}

function sr_fmt_pct(?float $p): string {
    return $p === null ? '–' : number_format($p, 0, ',', '') . '%';
}

/** Seller table columns: label => fn(metrics) → string. $lang: it (emails) | en (Hub page). */
function sr_metric_cols(string $lang = 'it'): array {
    $en = $lang === 'en';
    return [($en ? 'Within 24h' : 'Entro 24h') => function ($m) { return sr_fmt_pct($m['pct_24h']) . ($m['due'] ? ' (' . $m['answered_24h'] . '/' . $m['due'] . ')' : ''); },
            ($en ? 'Evening / weekend / holiday within 24h' : 'Sera/weekend/festivi entro 24h') => function ($m) { return sr_fmt_pct($m['pct_off_24h']) . ($m['off_due'] ? ' (' . $m['off_answered_24h'] . '/' . $m['off_due'] . ')' : ''); },
            ($en ? 'Median time' : 'Tempo mediano') => function ($m) use ($en) { return sr_fmt_h($m['median_h'], $en); },
            ($en ? '90th percentile' : '90° percentile') => function ($m) use ($en) { return sr_fmt_h($m['p90_h'], $en); },
            ($en ? 'First reply to new leads (median)' : 'Prima risposta nuovi lead (mediana)') => function ($m) use ($en) { return sr_fmt_h($m['new_leads_median_h'], $en) . ($m['new_leads'] ? ' (' . $m['new_leads'] . ')' : ''); },
            ($en ? 'Client mails' : 'Mail clienti') => function ($m) { return (string)$m['client_msgs']; },
            ($en ? 'Mails sent' : 'Mail inviate') => function ($m) { return (string)$m['sent_msgs']; }];
}

/**
 * Build an email. $type: daily | weekly | first | failed. $rep = sr_report(); $x: error, scan (per-folder counts),
 * date. Returns [subject, html, text]. Labels in Italian.
 */
function sr_email(string $type, ?array $rep, array $x = []): array {
    $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $date = $x['date'] ?? date('Y-m-d');
    $live = sr_cfg()['live'];
    $td = 'border:1px solid #ddd;padding:4px 6px;vertical-align:top;';
    $html = '';
    $txt = '';
    $sec = function (string $t) use (&$html, &$txt, $h) {
        $html .= '<h3 style="font-family:Arial,sans-serif;font-size:15px;margin:18px 0 6px 0;">' . $h($t) . '</h3>';
        $txt .= "\n== " . $t . " ==\n";
    };
    $table = function (array $head, array $rows, array $rawHtml = []) use (&$html, &$txt, $h, $td) {
        if (!$rows) { $html .= '<div style="color:#777;">nessuno</div>'; $txt .= "nessuno\n"; return; }
        $html .= '<table cellpadding="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:14px;"><tr>';
        foreach ($head as $c) $html .= '<th style="' . $td . 'background:#f5f5f5;text-align:left;">' . $h($c) . '</th>';
        $html .= '</tr>';
        foreach ($rows as $i => $r) {
            $html .= '<tr>';
            foreach (array_values($r) as $j => $c) $html .= '<td style="' . $td . '">' . ($rawHtml[$i][$j] ?? $h($c)) . '</td>';
            $html .= '</tr>';
            $txt .= '- ' . implode(' | ', array_map(function ($k, $v) { return $k . ': ' . $v; }, $head, array_values($r))) . "\n";
        }
        $html .= '</table>';
    };
    $reqCell = function (array $u) use ($h) {
        return '<a href="' . $h($u['url']) . '">' . $h($u['folder'] ?: '#' . $u['request_id']) . '</a>';
    };
    $unansweredTable = function (array $list) use ($table, $reqCell) {
        $rows = $raw = [];
        foreach ($list as $i => $u) {
            $rows[] = [$u['seller'], ($u['folder'] ?: '#' . $u['request_id']) . ' ' . $u['url'], trim($u['customer'] . ' <' . $u['client'] . '>'),
                       $u['last_client_at'] . ($u['client_msgs'] > 1 ? ' (' . $u['client_msgs'] . ' mail)' : ''), sr_fmt_h($u['hours_waiting']),
                       $u['written_to'] ?? '',
                       ($u['in_ferie'] ? 'in ferie' : '') . ($u['ambiguous'] ? ($u['in_ferie'] ? ', ' : '') . 'abbinamento incerto' : '')];
            $raw[$i][1] = $reqCell($u);
        }
        $table(['Venditore', 'Richiesta', 'Cliente', 'Ultima mail cliente', 'In attesa da', 'Scritto a', 'Note'], $rows, $raw);
    };
    $mapping = function () use ($rep, $sec, $table, $x) {
        $sec('Mappatura venditori (da confermare)');
        $rows = [];
        foreach ($rep['sellers'] as $s) {
            $rows[] = [$s['name'], $s['mailbox'], $s['agent_id'] ? $s['agent_name'] . ' (id ' . $s['agent_id'] . ')' : 'ERRORE: ' . $s['mapping_error'],
                       $s['leave_names'] ? implode(', ', $s['leave_names']) : 'nessun nome in leave_entries'];
        }
        $table(['Venditore', 'Casella', 'Agente Hub', 'Nome ferie'], $rows);
        if (!empty($x['scan'])) {
            $sec('Cartelle lette');
            $by = [];
            foreach ($x['scan'] as $f) $by[$f['mailbox']][] = $f['folder'] . ' (' . $f['read'] . ')';
            $table(['Casella', 'Cartelle (mail lette)'], array_map(function ($k, $v) { return [$k, implode(', ', $v)]; }, array_keys($by), $by));
        }
    };

    if ($type === 'failed') {
        $subject = '[Sales Response] SCAN FAILED ' . $date;
        $html .= '<p>La scansione dei tempi di risposta <b>non</b> è andata a buon fine: i dati non sono aggiornati.</p>'
               . '<pre style="white-space:pre-wrap;background:#f5f5f5;padding:8px;">' . $h($x['error'] ?? '') . '</pre>'
               . '<p>Verifica sul server: <code>php ~/public_html/hub/modules/leads/sales_response_scan.php --check</code></p>';
        $txt .= "La scansione dei tempi di risposta NON è andata a buon fine: i dati non sono aggiornati.\n\n" . ($x['error'] ?? '')
              . "\n\nVerifica sul server: php ~/public_html/hub/modules/leads/sales_response_scan.php --check\n";
    } elseif ($type === 'daily') {
        $n = count($rep['unanswered']);
        $subject = '[Sales Response] ' . ($live ? '' : 'TEST ') . $date . ' – ' . $n . ' mail senza risposta >' . SR_THRESHOLD_H . 'h';
        $html .= '<p>Clienti che aspettano una risposta da più di ' . SR_THRESHOLD_H . ' ore (richieste aperte).</p>';
        $txt .= 'Clienti che aspettano una risposta da più di ' . SR_THRESHOLD_H . " ore (richieste aperte).\n";
        $unansweredTable($rep['unanswered']);
    } else {
        $first = $type === 'first';
        $subject = $first ? '[Sales Response] TEST – prima scansione ' . $date . ' (' . SR_BACKFILL_DAYS . ' giorni)'
                          : '[Sales Response] Weekly ' . $date . ($live ? '' : ' – TEST');
        if ($first || !$live) {
            $html .= '<p style="background:#fff3cd;padding:8px;"><b>TEST</b> — da verificare prima dell\'uso per i KPI. Gli avvisi giornalieri partono '
                   . 'solo dopo l\'OK di Roberto (SALES_RESPONSE_LIVE in includes/config.php).</p>';
            $txt .= "TEST — da verificare prima dell'uso per i KPI. Gli avvisi giornalieri partono solo dopo l'OK (SALES_RESPONSE_LIVE).\n";
        }
        $wk = $rep['week']['start'] . ' – ' . $rep['week']['end'];
        $cols = sr_metric_cols();
        $sec($first ? 'Ultima settimana ' . $wk : 'Settimana ' . $wk . ' (tra parentesi: media delle ' . $rep['previous_weeks'] . ' settimane precedenti)');
        $rows = [];
        foreach ($rep['sellers'] as $s) {
            $row = [$s['name']];
            foreach ($cols as $fn) $row[] = $fn($s['week']) . ($s['prev'] && !$first ? ' [' . $fn($s['prev']) . ']' : '');
            $row[] = (string)$s['open_unanswered'];
            if ($rep['leave_excluded']) $row[] = $s['week']['on_leave_excluded'] ? $s['week']['on_leave_excluded'] . ' escluse (ferie)' : '';
            $rows[] = $row;
        }
        $table(array_merge(['Venditore'], array_keys($cols), ['Senza risposta ora'], $rep['leave_excluded'] ? ['Ferie'] : []), $rows);
        if ($first) {
            $sec('Ultime ' . ($rep['previous_weeks'] + 1) . ' settimane: % entro 24h (mediana)');
            $head = ['Settimana'];
            foreach ($rep['sellers'] as $s) $head[] = $s['name'];
            $rows = [];
            foreach (array_keys($rep['sellers'][0]['weeks'] ?? []) as $w) {
                $row = [$w];
                foreach ($rep['sellers'] as $s) $row[] = sr_fmt_pct($s['weeks'][$w]['pct_24h']) . ' (' . sr_fmt_h($s['weeks'][$w]['median_h']) . ')';
                $rows[] = $row;
            }
            $table($head, $rows);
            $sec('Totale ' . ($rep['previous_weeks'] + 1) . ' settimane');
            $rows = [];
            foreach ($rep['sellers'] as $s) {
                $row = [$s['name']];
                foreach ($cols as $fn) $row[] = $fn($s['total']);
                $rows[] = $row;
            }
            $table(array_merge(['Venditore'], array_keys($cols)), $rows);
        }
        $sec('Mail senza risposta da più di ' . SR_THRESHOLD_H . 'h (' . count($rep['unanswered']) . ')');
        $unansweredTable($rep['unanswered']);
        if (!$first) {
            $sec('Nuovi lead della settimana: prima risposta');
            $rows = $raw = [];
            foreach ($rep['new_leads'] as $i => $l) {
                $rows[] = [$l['seller'], ($l['folder'] ?: '#' . $l['request_id']) . ' ' . $l['url'], $l['first_client_at'],
                           $l['written_to'] ?? '', $l['first_reply_at'] ?? 'nessuna risposta', sr_fmt_h($l['hours'])];
                $raw[$i][1] = $reqCell($l);
            }
            $table(['Venditore', 'Richiesta', 'Prima mail cliente', 'Scritto a', 'Prima risposta', 'Tempo'], $rows, $raw);
        }
        $sec('Clienti silenziosi da ' . SR_SILENT_DAYS . '+ giorni, nessun follow-up (informativo)');
        $rows = $raw = [];
        foreach ($rep['silent'] as $i => $l) {
            $rows[] = [$l['seller'], ($l['folder'] ?: '#' . $l['request_id']) . ' ' . $l['url'], $l['status'], $l['last_sent_at'], $l['days'] . ' g'];
            $raw[$i][1] = $reqCell($l);
        }
        $table(['Venditore', 'Richiesta', 'Stato', 'Nostra ultima mail', 'Da'], $rows, $raw);
        if ($first || !$live) $mapping();
    }
    if ($type !== 'failed') {
        $html .= '<p style="color:#555;font-size:12px;margin-top:16px;"><b>' . $h(SR_FOOTER) . '</b> ' . $h(implode(' ', SR_LIMITS))
               . ' Soglia ' . SR_THRESHOLD_H . ' ore di calendario; sera = 18:00–08:00 EAT.</p>';
        $txt .= "\n" . SR_FOOTER . ' ' . implode(' ', SR_LIMITS) . "\n";
    }
    $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#222;">' . $html
          . '<p style="color:#999;font-size:12px;">Savannah Explorers Hub — tempi di risposta (modules/leads/sales_response_scan.php)</p></div>';
    $txt = trim($txt) . "\n\n-- \nSavannah Explorers Hub — tempi di risposta\n";
    return [$subject, $html, $txt];
}
