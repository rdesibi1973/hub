<?php
/**
 * staff_mail_service.php — read-only search and reading of the staff mailboxes @savannahexplorers.com, for the Agent API
 * (staff_mail_mailboxes / staff_mail_search / staff_mail_get / staff_mail_attachment). Roberto, 11 Nov 2026: any company
 * mailbox can be searched and read by the administrator, or by the agent on his request, for work reasons, with the
 * reason recorded (docs/STAFF_NOTICE_MAIL.md).
 *
 * - Mailboxes: every directory with cur/ under <mail root>/savannahexplorers.com/ (new staff appear by themselves).
 *   Folders: INBOX + sub-folders, trash / junk / drafts excluded (sr_folders()).
 * - Read-only: files are opened for reading only, never renamed or moved (flags unchanged, mails stay unread).
 * - Search: 1) delivery time from the Maildir file name (no file opened), 2) header block only, 3) body text only for
 *   what passed 1-2, at most SM_BODY_BYTES per mail and SM_MAX_BODIES / SM_TIME_BUDGET per call; a cursor continues.
 *   Bodies are never stored.
 * - Passports: numbers masked in subjects and texts (sm_mask()); attachments named passport / passaporto / … are listed
 *   as withheld and never returned or saved.
 * - Logged: staff_mail_get / staff_mail_attachment (reason required) and text searches in mail_access_log (Booking mail),
 *   every call in agent_audit_log (without bodies or files).
 * Off when STAFF_MAIL_API is defined false in includes/config.php.
 */

require_once __DIR__ . '/../modules/operations/includes/booking_mail_service.php';   // MIME parsing, bm_log(), sr_* Maildir helpers

const SM_DOMAIN        = 'savannahexplorers.com';
const SM_DEFAULT_DAYS  = 30;
const SM_MAX_LIMIT     = 100;
const SM_MAX_HEADERS   = 4000;              // header blocks read per call
const SM_MAX_BODIES    = 400;               // bodies read per call (text search)
const SM_BODY_BYTES    = 512 * 1024;        // of each body, for the text search
const SM_TIME_BUDGET   = 20;                // seconds per search call
const SM_MAX_ATTACH    = 10 * 1024 * 1024;
const SM_PASSPORT_FILE = '/pass(?:port|aporto|eport)|pasaporte|reisepass/i';

function sm_enabled(): bool {
    return !defined('STAFF_MAIL_API') || STAFF_MAIL_API;
}

function sm_domain_dir(): string {
    return sr_mail_root() . '/' . SM_DOMAIN;
}

/** address => Maildir path, sorted. Throws if the domain directory is not readable (silence must never mean OK). */
function sm_mailboxes(): array {
    $root = sm_domain_dir();
    if (!is_dir($root) || !is_readable($root)) throw new RuntimeException('Mail directory not readable: ' . $root);
    $out = [];
    foreach ((array)scandir($root) as $u) {
        if (!is_string($u) || $u === '' || $u[0] === '.' || !is_dir("$root/$u/cur")) continue;
        $out[strtolower($u) . '@' . SM_DOMAIN] = "$root/$u";
    }
    ksort($out);
    return $out;
}

/** "asia.caruso" or "asia.caruso@savannahexplorers.com" → the address, or null if not a mailbox. */
function sm_box(string $v): ?string {
    $v = strtolower(trim($v));
    if ($v === '') return null;
    if (strpos($v, '@') === false) $v .= '@' . SM_DOMAIN;
    return array_key_exists($v, sm_mailboxes()) ? $v : null;
}

/** Mailbox list for the API: [{mailbox, folders[]}]. */
function sm_mailbox_list(): array {
    $out = [];
    foreach (sm_mailboxes() as $addr => $dir) $out[] = ['mailbox' => $addr, 'folders' => array_keys(sr_folders($dir))];
    return $out;
}

/** Passport numbers out of any text: "passport no. YA1234567" and machine-readable (MRZ) lines. */
function sm_mask(string $s): string {
    $s = followup_strip_passport($s);
    return (string)preg_replace('/(?<![A-Z0-9<])(?=[A-Z0-9<]*<)(?=[A-Z0-9<]*\d)[A-Z0-9<]{30,44}(?![A-Z0-9<])/', '[removed]', $s);
}

function sm_is_passport_file(string $name): bool {
    return (bool)preg_match(SM_PASSPORT_FILE, $name);
}

/** The Maildir file of mailbox / folder / key (key = base name without ":2,flags"), or null. Inputs validated here. */
function sm_file(string $mailbox, string $folder, string $key): ?string {
    $box = sm_box($mailbox);
    if ($box === null || !preg_match('/^[A-Za-z0-9._,=+-]{5,190}$/', $key)) return null;
    $folders = sr_folders(sm_mailboxes()[$box]);
    if (!isset($folders[$folder])) return null;
    foreach (['cur', 'new'] as $sub) {
        $d = $folders[$folder] . '/' . $sub;
        if (is_file("$d/$key")) return "$d/$key";
        $g = glob("$d/$key:*");
        if ($g) return $g[0];
    }
    return null;
}

/** Whole file up to $max bytes, gzip or plain, read-only. */
function sm_read(string $file, int $max): ?string {
    $fh = @gzopen($file, 'rb');
    if (!$fh) return null;
    $raw = '';
    while (!gzeof($fh) && strlen($raw) < $max) {
        $chunk = gzread($fh, min(1 << 20, $max - strlen($raw)));
        if ($chunk === false || $chunk === '') break;
        $raw .= $chunk;
    }
    gzclose($fh);
    return $raw;
}

function sm_decode_header(string $v): string {
    if ($v !== '' && function_exists('iconv_mime_decode')) {
        $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($d !== false) $v = $d;
    }
    return mb_substr(mb_convert_encoding($v, 'UTF-8', 'UTF-8'), 0, 1000);
}

/** Plain text of a parsed message (HTML converted when there is no text part). */
function sm_text(array $p): string {
    return trim($p['text']) !== '' ? $p['text'] : mbx_html_to_text($p['html']);
}

/** Lower-case words of a filter ("all must appear"). */
function sm_words(string $q): array {
    return array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($q))), 'strlen'));
}

function sm_has_all(string $haystack, array $words): bool {
    $h = mb_strtolower($haystack);
    foreach ($words as $w) if (mb_strpos($h, $w) === false) return false;
    return true;
}

/** Delivery time from a Maildir name ("1731312000.M12P34.host,S=…"), else the file mtime. */
function sm_name_time(string $name, string $path): int {
    if (preg_match('/^(\d{9,11})\./', $name, $m)) return (int)$m[1];
    return (int)@filemtime($path);
}

/**
 * Search. $f: mailboxes (list of addresses, [] = all), folder ('' = all), since_ts, before_ts (exclusive),
 * from, to, subject, q (text: subject + addresses + body), message_id (thread), limit, cursor ("time:mailbox:folder:key").
 * → [messages[], scanned{files, headers, bodies}, truncated, next_cursor].
 */
function sm_search(array $f): array {
    $started = microtime(true);
    $all = sm_mailboxes();
    $boxes = $f['mailboxes'] ?: array_keys($all);
    $since = (int)$f['since_ts'];
    $before = (int)$f['before_ts'];
    $margin = 2 * 86400;   // file time vs Date header
    $limit = max(1, min(SM_MAX_LIMIT, (int)$f['limit']));

    // 1) candidates from the file names only
    $cand = [];
    foreach ($boxes as $box) {
        foreach (sr_folders($all[$box]) as $fname => $fpath) {
            if ($f['folder'] !== '' && $fname !== $f['folder']) continue;
            foreach (['cur', 'new'] as $sub) {
                $d = "$fpath/$sub";
                $dh = is_dir($d) ? @opendir($d) : false;
                if ($dh === false) continue;
                while (($n = readdir($dh)) !== false) {
                    if ($n === '' || $n[0] === '.') continue;
                    $t = sm_name_time($n, "$d/$n");
                    if ($t < $since - $margin || $t >= $before + $margin) continue;
                    $cand[] = [$t, $box, $fname, substr(explode(':', $n, 2)[0], 0, 190), "$d/$n"];
                }
                closedir($dh);
            }
        }
    }
    $cmp = fn($a, $b) => [$b[0], $b[1], $b[2], $b[3]] <=> [$a[0], $a[1], $a[2], $a[3]];   // newest first, stable order
    usort($cand, $cmp);
    if (!empty($f['cursor'])) {
        $c = explode(':', (string)$f['cursor'], 4);
        if (count($c) === 4) {
            $pos = [(int)$c[0], $c[1], $c[2], $c[3], ''];
            $cand = array_values(array_filter($cand, fn($x) => $cmp($pos, $x) < 0));
        }
    }

    $wFrom = sm_words((string)$f['from']);
    $wTo   = sm_words((string)$f['to']);
    $wSubj = sm_words((string)$f['subject']);
    $wQ    = sm_words((string)$f['q']);
    $mid   = strtolower(trim((string)$f['message_id'], " <>\t"));
    $out = [];
    $nHead = $nBody = 0;
    $last = null;
    $truncated = false;
    foreach ($cand as $i => [$t, $box, $folder, $key, $path]) {
        if ($nHead >= SM_MAX_HEADERS || $nBody >= SM_MAX_BODIES || microtime(true) - $started > SM_TIME_BUDGET) { $truncated = true; break; }
        $last = $cand[$i];
        $raw = sr_read_header($path);
        $nHead++;
        if ($raw === null || $raw === '') continue;
        $h = sr_parse_headers($raw);
        $r = sr_header_row($raw, $t);
        if ($r['ts'] < $since || $r['ts'] >= $before) continue;
        $from = sm_decode_header($h['from'] ?? '');
        $to = sm_decode_header(trim(($h['to'] ?? '') . ', ' . ($h['cc'] ?? ''), ', '));
        if ($wFrom && !sm_has_all($from, $wFrom)) continue;
        if ($wTo && !sm_has_all($to, $wTo)) continue;
        if ($wSubj && !sm_has_all($r['subject'], $wSubj)) continue;
        if ($mid !== '' && $r['message_id'] !== $mid && $r['in_reply_to'] !== $mid && !in_array($mid, explode(' ', (string)$r['refs']), true)) continue;
        if ($wQ && !sm_has_all($r['subject'] . ' ' . $from . ' ' . $to, $wQ)) {
            $body = sm_read($path, SM_BODY_BYTES);
            $nBody++;
            if ($body === null || !sm_has_all(sm_text(bm_mime_parse($body)), $wQ)) continue;
        }
        $out[] = ['mailbox' => $box, 'folder' => $folder, 'key' => $key, 'date' => date('c', $r['ts']),
                  'from' => $from, 'to' => $to, 'subject' => sm_mask($r['subject']), 'direction' => $r['direction'],
                  'message_id' => $r['message_id'], 'size' => (int)@filesize($path),
                  'has_attachments' => stripos($h['content-type'] ?? '', 'multipart/mixed') === 0];
        if (count($out) >= $limit) { $truncated = $i < count($cand) - 1; break; }
    }
    return ['messages' => $out, 'scanned' => ['files' => count($cand), 'headers' => $nHead, 'bodies' => $nBody],
            'truncated' => $truncated,
            'next_cursor' => $truncated && $last ? $last[0] . ':' . $last[1] . ':' . $last[2] . ':' . $last[3] : null];
}

/** The id written to mail_access_log (CHAR 40): Message-ID like Booking mail, else the file. */
function sm_log_key(string $mailbox, string $folder, string $key, ?string $messageId): string {
    return sha1($messageId !== null && $messageId !== '' ? $messageId : $mailbox . '|' . $folder . '|' . $key);
}

/** One message: headers, text (passports masked), attachments (passport files withheld). */
function sm_get(string $mailbox, string $folder, string $key): ?array {
    $file = sm_file($mailbox, $folder, $key);
    if ($file === null) return null;
    $raw = sm_read($file, BM_MAX_FILE);
    if ($raw === null) throw new RuntimeException('The mail can no longer be read.');
    $p = preg_match('/\r?\n\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : strlen($raw);
    $head = substr($raw, 0, $p);
    $h = sr_parse_headers($head);
    $r = sr_header_row($head, sm_name_time(basename($file), $file));
    $parsed = bm_mime_parse($raw);
    $text = sm_mask(sm_text($parsed));
    $atts = array_map(function ($a) {
        if (!sm_is_passport_file($a['name'])) return $a + ['withheld' => false];
        return ['part' => $a['part'], 'name' => '(passport file withheld)', 'mime' => $a['mime'], 'size' => $a['size'], 'withheld' => true];
    }, $parsed['attachments']);
    return ['mailbox' => sm_box($mailbox), 'folder' => $folder, 'key' => $key, 'date' => date('c', $r['ts']),
            'from' => sm_decode_header($h['from'] ?? ''), 'reply_to' => sm_decode_header($h['reply-to'] ?? ''),
            'to' => sm_decode_header($h['to'] ?? ''), 'cc' => sm_decode_header($h['cc'] ?? ''),
            'subject' => sm_mask($r['subject']), 'direction' => $r['direction'],
            'message_id' => $r['message_id'], 'in_reply_to' => $r['in_reply_to'], 'references' => $r['refs'],
            'body' => mb_substr($text, 0, MBX_MAX_BODY_CHARS), 'body_truncated' => mb_strlen($text) > MBX_MAX_BODY_CHARS,
            'has_html' => trim($parsed['html']) !== '', 'attachments' => $atts];
}

/** One attachment → [name, mime, size, content, message_id]. Passport files refused. */
function sm_attachment(string $mailbox, string $folder, string $key, string $part): ?array {
    $file = sm_file($mailbox, $folder, $key);
    if ($file === null || !preg_match('/^\d+(\.\d+)*$/', $part)) return null;
    $raw = sm_read($file, BM_MAX_FILE);
    if ($raw === null) throw new RuntimeException('The mail can no longer be read.');
    $p = bm_mime_parse($raw, $part);
    if ($p['content'] === null) return null;
    foreach ($p['attachments'] as $a) {
        if ($a['part'] !== $part) continue;
        if (sm_is_passport_file($a['name'])) throw new DomainException('Passport files are not returned or saved through the API.');
        if ($a['size'] > SM_MAX_ATTACH) throw new LengthException('Attachment larger than ' . (SM_MAX_ATTACH >> 20) . ' MB.');
        $h = sr_header_row(sr_read_header($file) ?? '', 0);
        return ['name' => $a['name'], 'mime' => $a['mime'], 'size' => $a['size'], 'content' => $p['content'], 'message_id' => $h['message_id']];
    }
    return null;
}
