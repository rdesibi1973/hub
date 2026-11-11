<?php
/**
 * booking_mail_service.php — is the booking team writing to the suppliers? Headers of glady@ / operations@ crossed with
 * the open Booking follow-up lines, plus, for a specific problem, reading one thread and replying from the same mailbox.
 *
 * - Scan: same Maildir reader as Sales Response, own table (booking_mail_index), so the sellers' numbers do not change.
 * - Reading a thread: admins only, reason required, every opening in mail_access_log. Bodies are read from the Maildir
 *   at that moment and never stored. Not exposed through the Agent API.
 * - Reply: from the mailbox holding the thread, signed "Booking Team". Needs that mailbox's password
 *   (BOOKING_MAIL_GLADY_PASS / BOOKING_MAIL_OPERATIONS_PASS, see mbx_use_staff()) for the copy in Sent.
 *
 * Nothing runs until BOOKING_MAIL_LIVE is true in includes/config.php: set it once the staff notice has gone out.
 * Requires sales_response_service.php, mailbox_service.php and followup_service.php (loaded here).
 */

require_once __DIR__ . '/../../leads/includes/sales_response_service.php';
require_once __DIR__ . '/../../../includes/mailbox_service.php';
require_once __DIR__ . '/followup_service.php';

const BM_BOXES         = ['glady' => 'glady@savannahexplorers.com', 'operations' => 'operations@savannahexplorers.com'];
const BM_BACKFILL_DAYS = 90;
const BM_KEEP_MONTHS   = 12;
const BM_CHECK_DAYS    = 120;   // mails read for the cross-check
const BM_NO_MAIL_DAYS  = 3;     // an open line with no mail to the supplier is flagged after this
const BM_WAITING_HOURS = 24;    // a supplier mail with no answer is flagged after this
const BM_MAX_THREAD    = 30;
const BM_MAX_FILE      = 40 * 1024 * 1024;
const BM_REASON_MIN    = 10;
const BM_SIGNATURE     = "Booking Team\nSavannah Explorers";
const BM_SUPPLIER_STOP = ['lodge', 'lodges', 'camp', 'camps', 'tented', 'hotel', 'hotels', 'resort', 'safari', 'safaris', 'luxury', 'house', 'limited', 'company', 'tours'];

function bm_live(): bool {
    return defined('BOOKING_MAIL_LIVE') && BOOKING_MAIL_LIVE;
}

function bm_require_live(): void {
    if (!bm_live()) throw new RuntimeException('Not active yet: set BOOKING_MAIL_LIVE in includes/config.php once the staff notice has gone out.');
}

function bm_can_read(array $user): bool {
    return ($user['role_name'] ?? '') === 'admin';
}

function bm_schema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $sql = (string)file_get_contents(__DIR__ . '/../../../migrations/078_booking_mail.sql');
    foreach (preg_split('/;\s*(?:\r?\n|$)/', (string)preg_replace('/^--.*$/m', '', $sql)) as $stmt) {
        if (trim($stmt) !== '') $db->exec($stmt);
    }
    $done = true;
}

function bm_log(PDO $db, array $user, string $action, ?string $mailbox, ?string $msgKey, ?int $requestId, string $reason = ''): void {
    bm_schema($db);
    $db->prepare('INSERT INTO mail_access_log (at, user_id, username, action, mailbox, msg_key, request_id, reason) VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?)')
       ->execute([$user['id'] ?? null, $user['username'] ?? null, $action, $mailbox, $msgKey, $requestId, $reason !== '' ? mb_substr($reason, 0, 500) : null]);
}

// ── Scan (headers only) ──────────────────────────────────────────────────────

/** Index new files of the booking mailboxes. Throws if a Maildir is missing: silence must never mean OK. → rows added. */
function bm_scan(PDO $db, int $backfillDays = BM_BACKFILL_DAYS): int {
    bm_schema($db);
    $root = sr_mail_root();
    if (!is_dir($root)) throw new RuntimeException('Mail root not found: ' . $root);
    $state = [];
    foreach ($db->query('SELECT mailbox, folder, last_mtime FROM booking_mail_scan_state') as $r) $state[$r['mailbox'] . "\n" . $r['folder']] = (int)$r['last_mtime'];
    $start = time();
    $floor = $start - $backfillDays * 86400;
    $ins = $db->prepare('INSERT IGNORE INTO booking_mail_index (mailbox, folder, file_key, msg_key, message_id, in_reply_to, refs, date_utc,
                         from_addr, to_addrs, subject, direction, indexed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
    $up = $db->prepare('REPLACE INTO booking_mail_scan_state (mailbox, folder, last_mtime, updated_at) VALUES (?,?,?,NOW())');
    $added = 0;
    foreach (BM_BOXES as $mb) {
        [$user, $dom] = explode('@', $mb, 2);
        $dir = $root . '/' . $dom . '/' . $user;
        if (!is_dir($dir . '/cur') || !is_readable($dir . '/cur')) throw new RuntimeException("Maildir not readable for $mb");
        foreach (sr_folders($dir) as $fname => $fpath) {
            $last = $state[$mb . "\n" . $fname] ?? $floor;
            $max = $last;
            foreach (['cur', 'new'] as $sub) {
                $d = $fpath . '/' . $sub;
                if (!is_dir($d)) continue;
                $dh = @opendir($d);
                if ($dh === false) throw new RuntimeException("Cannot open a folder of $mb");
                while (($f = readdir($dh)) !== false) {
                    if ($f === '' || $f[0] === '.') continue;
                    $p = $d . '/' . $f;
                    $mt = @filemtime($p);
                    if ($mt === false || $mt < $last) continue;
                    if ($mt > $max) $max = $mt;
                    $raw = sr_read_header($p);
                    if ($raw === null || $raw === '') continue;
                    $r = sr_header_row($raw, (int)$mt);
                    $fk = substr(explode(':', $f, 2)[0], 0, 190);
                    $mk = sha1($r['message_id'] !== null ? $r['message_id'] : $mb . '|' . $fname . '|' . $fk);
                    $ins->execute([$mb, mb_substr($fname, 0, 190), $fk, $mk, $r['message_id'], $r['in_reply_to'], $r['refs'],
                                   gmdate('Y-m-d H:i:s', $r['ts']), $r['from'], $r['to'], $r['subject'], $r['direction']]);
                    $added += $ins->rowCount();
                }
                closedir($dh);
            }
            $up->execute([$mb, mb_substr($fname, 0, 190), max($last, min($max, $start - 120))]);
        }
    }
    $db->exec('DELETE FROM booking_mail_index WHERE date_utc < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . BM_KEEP_MONTHS . ' MONTH)');
    return $added;
}

/** One recorded scan; a MySQL lock skips overlapping runs. */
function bm_run(PDO $db): array {
    bm_schema($db);
    if (!bm_live()) return ['ok' => false, 'error' => 'not live', 'added' => 0];
    if ((int)$db->query("SELECT GET_LOCK('booking_mail_scan', 0)")->fetchColumn() !== 1) return ['ok' => false, 'error' => 'already running', 'added' => 0];
    $ok = 1; $err = null; $added = 0;
    try {
        $added = bm_scan($db);
    } catch (Throwable $e) {
        $ok = 0;
        $err = $e->getMessage();
    }
    $db->prepare('INSERT INTO booking_mail_run (ran_at, ok, error, added) VALUES (NOW(), ?, ?, ?)')->execute([$ok, $err, $added]);
    $db->query("SELECT RELEASE_LOCK('booking_mail_scan')");
    return ['ok' => (bool)$ok, 'error' => $err, 'added' => $added];
}

function bm_last_run(PDO $db): ?array {
    bm_schema($db);
    $r = $db->query('SELECT ran_at, ok, error, added FROM booking_mail_run ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

// ── Cross-check with the Booking follow-up (pure functions first) ────────────

function bm_words(string $s, int $min, array $stop = []): array {
    $out = [];
    foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) as $w) {
        if (mb_strlen($w) >= $min && !in_array($w, $stop, true)) $out[$w] = true;
    }
    return array_keys($out);
}

function bm_has_word(string $haystack, string $word): bool {
    return preg_match('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/u', $haystack) === 1;
}

/**
 * The mails about a line, oldest first. A mail counts when its subject has the supplier's reference, or the client's name
 * together with the supplier (in the subject or in the other party's address); then the rest of those threads follow.
 * $mails: rows with message_id, in_reply_to, refs, date_utc, from_addr, to_addrs, subject, direction.
 */
function bm_match_line(array $line, array $mails): array {
    $ref = trim((string)($line['supplier_ref'] ?? ''));
    $refLc = strlen($ref) >= 4 ? mb_strtolower($ref) : '';
    $client = bm_words((string)($line['customer_name'] ?? ''), 3);
    $supplier = bm_words((string)($line['supplier'] ?? ''), 4, BM_SUPPLIER_STOP);
    if ($refLc === '' && (!$client || !$supplier)) return [];
    $hit = [];
    foreach ($mails as $i => $m) {
        if ($m['direction'] !== 'client' && $m['direction'] !== 'savannah') continue;
        $subject = mb_strtolower((string)$m['subject']);
        if ($refLc !== '' && mb_strpos($subject, $refLc) !== false) { $hit[$i] = true; continue; }
        $clientOk = false;
        foreach ($client as $w) if (bm_has_word($subject, $w)) { $clientOk = true; break; }
        if (!$clientOk) continue;
        $party = mb_strtolower($m['from_addr'] . ' ' . $m['to_addrs']);
        foreach ($supplier as $w) {
            if (bm_has_word($subject, $w) || mb_strpos($party, $w) !== false) { $hit[$i] = true; break; }
        }
    }
    for ($round = 0; $round < 5 && $hit; $round++) {
        $ids = [];
        foreach ($hit as $i => $_) {
            foreach (array_filter(array_merge([$mails[$i]['message_id'], $mails[$i]['in_reply_to']], explode(' ', (string)$mails[$i]['refs']))) as $id) $ids[$id] = true;
        }
        $more = false;
        foreach ($mails as $i => $m) {
            if (isset($hit[$i]) || ($m['direction'] !== 'client' && $m['direction'] !== 'savannah')) continue;
            if (($m['message_id'] && isset($ids[$m['message_id']])) || ($m['in_reply_to'] && isset($ids[$m['in_reply_to']]))) { $hit[$i] = true; $more = true; }
        }
        if (!$more) break;
    }
    $out = array_values(array_intersect_key($mails, $hit));
    usort($out, function ($a, $b) { return strcmp($a['date_utc'], $b['date_utc']); });
    return $out;
}

/** What the mails say about a line → code: supplier_waiting | contacted_no_mail | no_mail | ok | wait. */
function bm_signal(array $line, array $matched, int $now): string {
    $last = $matched ? $matched[count($matched) - 1] : null;
    if ($last && $last['direction'] === 'client' && $now - strtotime($last['date_utc'] . ' UTC') > BM_WAITING_HOURS * 3600) return 'supplier_waiting';
    $out = array_values(array_filter($matched, function ($m) { return $m['direction'] === 'savannah'; }));
    if (!empty($line['contacted_at'])) {
        $d = strtotime($line['contacted_at'] . ' 12:00:00 UTC');
        foreach ($out as $m) {
            $t = strtotime($m['date_utc'] . ' UTC');
            if ($t >= $d - 3 * 86400 && $t <= $d + 86400) return 'ok';
        }
        return 'contacted_no_mail';
    }
    if ($out) return 'ok';
    return $now - strtotime((string)$line['first_seen_at'] . ' UTC') > BM_NO_MAIL_DAYS * 86400 ? 'no_mail' : 'wait';
}

function bm_signal_label(string $code): string {
    return ['supplier_waiting' => 'Supplier wrote, no answer', 'contacted_no_mail' => 'Marked contacted, no mail found',
            'no_mail' => 'No mail to the supplier', 'ok' => 'Mail found', 'wait' => 'Too early to say'][$code] ?? $code;
}

/** Open booking lines with what the mails say, worst first. "No mail" means to verify: phone, WhatsApp and portals are not seen. */
function bm_crosscheck(PDO $db): array {
    bm_require_live();
    bm_schema($db);
    followup_schema($db);
    $lines = $db->query("SELECT b.id, b.request_id, b.folder, b.supplier, b.supplier_ref, b.service, b.service_date, b.issue, b.contacted_at, b.first_seen_at,
                                r.customer_name, COALESCE(o.full_name, o.username) AS owner
                         FROM booking_followups b LEFT JOIN requests r ON r.id = b.request_id LEFT JOIN users o ON o.id = b.owner_user_id
                         WHERE b.closed_at IS NULL AND COALESCE(b.audience, 'booking') = 'booking' AND b.staff_status NOT IN ('resolved', 'not_relevant')
                           AND (COALESCE(b.supplier, '') <> '' OR COALESCE(b.supplier_ref, '') <> '')
                         ORDER BY b.service_date ASC, b.id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $st = $db->prepare('SELECT mailbox, msg_key, message_id, in_reply_to, refs, date_utc, from_addr, to_addrs, subject, direction FROM booking_mail_index
                        WHERE date_utc >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)');
    $st->execute([BM_CHECK_DAYS]);
    $seen = []; $mails = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
        if (isset($seen[$m['msg_key']])) continue;
        $seen[$m['msg_key']] = true;
        $mails[] = $m;
    }
    $rank = ['supplier_waiting' => 0, 'contacted_no_mail' => 1, 'no_mail' => 2, 'wait' => 3, 'ok' => 4];
    $now = time();
    $out = [];
    foreach ($lines as $l) {
        $matched = bm_match_line($l, $mails);
        $code = bm_signal($l, $matched, $now);
        $last = $matched ? $matched[count($matched) - 1] : null;
        $out[] = ['id' => (int)$l['id'], 'request_id' => $l['request_id'] !== null ? (int)$l['request_id'] : null, 'client' => $l['customer_name'] ?: $l['folder'],
                  'supplier' => $l['supplier'], 'ref' => $l['supplier_ref'], 'service_date' => $l['service_date'], 'issue' => followup_issue_label((string)$l['issue']),
                  'owner' => $l['owner'], 'contacted_at' => $l['contacted_at'], 'signal' => $code, 'signal_label' => bm_signal_label($code), 'mails' => count($matched),
                  'last_at' => $last ? $last['date_utc'] : null, 'last_key' => $last ? $last['msg_key'] : null, 'last_subject' => $last ? $last['subject'] : null];
    }
    usort($out, function ($a, $b) use ($rank) { return $rank[$a['signal']] <=> $rank[$b['signal']]; });
    return $out;
}

// ── Reading a mail file ──────────────────────────────────────────────────────

/** The file of an indexed mail, or null. mailbox / folder / key come from the index, never from the browser. */
function bm_mail_file(string $mailbox, string $folder, string $key): ?string {
    if (strpos($mailbox, '@') === false || preg_match('#/|\.\.#', $key . $folder)) return null;
    [$user, $dom] = explode('@', strtolower($mailbox), 2);
    $base = sr_mail_root() . '/' . $dom . '/' . $user . ($folder === 'INBOX' ? '' : '/.' . $folder);
    $esc = str_replace('[', '[[]', $key);
    foreach (['cur', 'new'] as $sub) {
        if (is_file("$base/$sub/$key")) return "$base/$sub/$key";
        $g = glob("$base/$sub/$esc:*");
        if ($g) return $g[0];
    }
    return null;
}

/** Whole file, gzip or plain (Dovecot on BlueHost compresses). */
function bm_read_file(string $file): ?string {
    $fh = @gzopen($file, 'rb');
    if (!$fh) return null;
    $raw = '';
    while (!gzeof($fh) && strlen($raw) < BM_MAX_FILE) {
        $chunk = gzread($fh, 1 << 20);
        if ($chunk === false || $chunk === '') break;
        $raw .= $chunk;
    }
    gzclose($fh);
    return $raw;
}

/** A header value's parameter (charset, boundary, name, filename), RFC 2231 included. */
function bm_mime_param(string $value, string $name): string {
    if (preg_match('/(?:^|;)\s*' . $name . '\*=(?:[^\']*\'[^\']*\')?([^;]+)/i', $value, $m)) return rawurldecode(trim($m[1], " \t\""));
    if (preg_match('/(?:^|;)\s*' . $name . '="([^"]*)"/i', $value, $m) || preg_match('/(?:^|;)\s*' . $name . '=([^;\s]+)/i', $value, $m)) {
        return function_exists('iconv_mime_decode') ? (string)@iconv_mime_decode($m[1], ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : $m[1];
    }
    return '';
}

function bm_mime_decode(string $body, string $encoding): string {
    $e = strtolower(trim($encoding));
    if ($e === 'base64') return (string)base64_decode((string)preg_replace('/\s+/', '', $body));
    if ($e === 'quoted-printable') return quoted_printable_decode($body);
    return $body;
}

function bm_to_utf8(string $s, string $charset): string {
    $c = trim($charset) ?: 'UTF-8';
    try {
        return (string)mb_convert_encoding($s, 'UTF-8', $c);
    } catch (Throwable $e) {
        return (string)mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }
}

/**
 * Parse a message → ['text', 'html', 'attachments' => [{part, name, mime, size}]]. With $want (a part number), that
 * attachment's decoded bytes are in ['content'].
 */
function bm_mime_parse(string $raw, ?string $want = null): array {
    $out = ['text' => '', 'html' => '', 'attachments' => [], 'content' => null];
    bm_mime_walk($raw, '', $want, $out);
    return $out;
}

function bm_mime_walk(string $raw, string $prefix, ?string $want, array &$out): void {
    $p = preg_match('/\r?\n\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : -1;
    $head = $p >= 0 ? substr($raw, 0, $p) : $raw;
    $body = $p >= 0 ? substr($raw, $p + strlen($m[0][0])) : '';
    $h = sr_parse_headers($head);
    $ct = $h['content-type'] ?? 'text/plain';
    $type = strtolower(trim(explode(';', $ct, 2)[0]));
    if (strpos($type, 'multipart/') === 0) {
        $boundary = bm_mime_param($ct, 'boundary');
        if ($boundary === '') return;
        $parts = preg_split('/(?:^|\r?\n)--' . preg_quote($boundary, '/') . '(?:--)?[ \t]*(?=\r?\n|$)/', $body);
        array_shift($parts);   // the preamble
        $n = 0;
        foreach ($parts as $part) {
            $part = (string)preg_replace('/^\r?\n/', '', $part);
            if (trim($part) === '') continue;
            $n++;
            bm_mime_walk($part, ($prefix === '' ? '' : $prefix . '.') . $n, $want, $out);
        }
        return;
    }
    $part = $prefix === '' ? '1' : $prefix;
    $cd = $h['content-disposition'] ?? '';
    $name = bm_mime_param($cd, 'filename') ?: bm_mime_param($ct, 'name');
    $isAtt = $name !== '' || stripos($cd, 'attachment') === 0 || $type === 'message/rfc822';
    $data = bm_mime_decode($body, $h['content-transfer-encoding'] ?? '');
    if ($isAtt) {
        $out['attachments'][] = ['part' => $part, 'name' => $name !== '' ? $name : 'attachment-' . $part, 'mime' => $type, 'size' => strlen($data)];
        if ($want === $part) $out['content'] = $data;
        return;
    }
    if ($type === 'text/plain' && $out['text'] === '') $out['text'] = bm_to_utf8($data, bm_mime_param($ct, 'charset'));
    elseif ($type === 'text/html' && $out['html'] === '') $out['html'] = bm_to_utf8($data, bm_mime_param($ct, 'charset'));
}

// ── One thread ───────────────────────────────────────────────────────────────

function bm_index_row(PDO $db, string $msgKey): ?array {
    bm_schema($db);
    $st = $db->prepare('SELECT * FROM booking_mail_index WHERE msg_key = ? ORDER BY id LIMIT 1');
    $st->execute([$msgKey]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** The index rows of $seed's thread, one per message, oldest first, the newest BM_MAX_THREAD. */
function bm_thread_rows(PDO $db, array $seed): array {
    $ids = [];
    foreach (array_filter(array_merge([$seed['message_id'], $seed['in_reply_to']], explode(' ', (string)$seed['refs']))) as $id) $ids[$id] = true;
    $rows = [$seed['msg_key'] => $seed];
    for ($round = 0; $round < 6 && $ids; $round++) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $list = array_map('strval', array_keys($ids));
        $st = $db->prepare("SELECT * FROM booking_mail_index WHERE message_id IN ($in) OR in_reply_to IN ($in)");
        $st->execute(array_merge($list, $list));
        $grew = false;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!isset($rows[$r['msg_key']]) || ($r['mailbox'] === $seed['mailbox'] && $rows[$r['msg_key']]['mailbox'] !== $seed['mailbox'])) {
                if (!isset($rows[$r['msg_key']])) $grew = true;
                $rows[$r['msg_key']] = $r;
            }
            foreach (array_filter(array_merge([$r['message_id'], $r['in_reply_to']], explode(' ', (string)$r['refs']))) as $id) $ids[$id] = true;
        }
        if (!$grew) break;
    }
    $rows = array_values($rows);
    usort($rows, function ($a, $b) { return strcmp($a['date_utc'], $b['date_utc']); });
    return array_slice($rows, -BM_MAX_THREAD);
}

function bm_check_reason(string $reason): string {
    $reason = trim($reason);
    if (mb_strlen($reason) < BM_REASON_MIN) throw new InvalidArgumentException('Write why you are opening this thread (at least ' . BM_REASON_MIN . ' characters).');
    return $reason;
}

/** The thread with its text, read now and not kept. Admin, reason required, logged. */
function bm_open_thread(PDO $db, array $user, string $msgKey, string $reason): array {
    bm_require_live();
    if (!bm_can_read($user)) throw new RuntimeException('Admins only.');
    $reason = bm_check_reason($reason);
    $seed = bm_index_row($db, $msgKey);
    if (!$seed) throw new InvalidArgumentException('Mail not found.');
    bm_log($db, $user, 'open_thread', $seed['mailbox'], $msgKey, null, $reason);
    $out = [];
    foreach (bm_thread_rows($db, $seed) as $r) {
        $file = bm_mail_file($r['mailbox'], $r['folder'], $r['file_key']);
        $raw = $file !== null ? bm_read_file($file) : null;
        $p = $raw !== null ? bm_mime_parse($raw) : null;
        $text = $p ? (trim($p['text']) !== '' ? $p['text'] : mbx_html_to_text($p['html'])) : '';
        $out[] = ['msg_key' => $r['msg_key'], 'mailbox' => $r['mailbox'], 'date_utc' => $r['date_utc'], 'from' => $r['from_addr'], 'to' => $r['to_addrs'],
                  'subject' => $r['subject'], 'direction' => $r['direction'], 'readable' => $p !== null,
                  'body' => followup_strip_passport(mb_substr($text, 0, MBX_MAX_BODY_CHARS)), 'attachments' => $p ? $p['attachments'] : []];
    }
    return $out;
}

/** One attachment of a thread message. Admin, reason required, logged. → [name, mime, content]. */
function bm_attachment(PDO $db, array $user, string $msgKey, string $part, string $reason): array {
    bm_require_live();
    if (!bm_can_read($user)) throw new RuntimeException('Admins only.');
    $reason = bm_check_reason($reason);
    $row = bm_index_row($db, $msgKey);
    if (!$row) throw new InvalidArgumentException('Mail not found.');
    $file = bm_mail_file($row['mailbox'], $row['folder'], $row['file_key']);
    $raw = $file !== null ? bm_read_file($file) : null;
    if ($raw === null) throw new RuntimeException('The mail can no longer be read.');
    $p = bm_mime_parse($raw, $part);
    if ($p['content'] === null) throw new InvalidArgumentException('Attachment not found.');
    bm_log($db, $user, 'attachment', $row['mailbox'], $msgKey, null, $reason);
    foreach ($p['attachments'] as $a) if ($a['part'] === $part) return ['name' => $a['name'], 'mime' => $a['mime'], 'content' => $p['content']];
    throw new InvalidArgumentException('Attachment not found.');
}

/** Who a reply to this message goes to: the sender (or Reply-To) of a received mail, the outside recipients of one we sent. */
function bm_reply_recipients(array $row, array $headers): array {
    if ($row['direction'] === 'client') {
        $rt = sr_addrs($headers['reply-to'] ?? '');
        return $rt ?: [$row['from_addr']];
    }
    return array_values(array_filter(explode(',', (string)$row['to_addrs']), function ($a) { return $a !== '' && !sr_is_own($a); }));
}

function bm_signed(string $body): string {
    $b = rtrim($body);
    return stripos($b, 'booking team') !== false ? $b : $b . "\n\n" . BM_SIGNATURE;
}

/** Reply from the mailbox holding the thread. $confirm false = preview only. */
function bm_reply(PDO $db, array $user, string $msgKey, string $body, string $reason, bool $confirm): array {
    bm_require_live();
    if (!bm_can_read($user)) throw new RuntimeException('Admins only.');
    $reason = bm_check_reason($reason);
    if (trim($body) === '') throw new InvalidArgumentException('Write the reply.');
    $row = bm_index_row($db, $msgKey);
    if (!$row) throw new InvalidArgumentException('Mail not found.');
    if (!$row['message_id']) throw new RuntimeException('This mail has no Message-ID: it cannot be answered in its thread.');
    $box = array_search(strtolower($row['mailbox']), BM_BOXES, true);
    if ($box === false) throw new RuntimeException('Replies are possible only from ' . implode(' / ', BM_BOXES) . '.');
    $file = bm_mail_file($row['mailbox'], $row['folder'], $row['file_key']);
    $head = $file !== null ? sr_read_header($file) : null;
    $to = bm_reply_recipients($row, $head !== null ? sr_parse_headers($head) : []);
    if (!$to) throw new RuntimeException('No outside recipient found for this mail.');
    $subject = (string)$row['subject'];
    if (!preg_match('/^\s*re:/i', $subject)) $subject = 'Re: ' . $subject;
    $prev = $GLOBALS['MBX_BOX'] ?? null;
    mbx_use_staff($box);
    try {
        $refs = trim((string)$row['refs']);
        $composed = mbx_compose(['to' => $to, 'subject' => $subject, 'body' => bm_signed($body), 'in_reply_to' => '<' . $row['message_id'] . '>',
                                 'references' => $refs !== '' ? implode(' ', array_map(function ($r) { return '<' . $r . '>'; }, explode(' ', $refs))) : '']);
        if (!$confirm) return ['sent' => false, 'from' => $row['mailbox'], 'to' => $to, 'subject' => $subject, 'body' => bm_signed($body)];
        $res = mbx_send($composed);
    } finally {
        $GLOBALS['MBX_BOX'] = $prev;
    }
    bm_log($db, $user, 'reply', $row['mailbox'], $msgKey, null, $reason);
    return ['sent' => true, 'from' => $row['mailbox'], 'to' => $to, 'subject' => $subject, 'warning' => $res['warning']];
}
