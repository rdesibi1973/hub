<?php
/**
 * mailbox_service.php — read / draft / send over IMAP for two mailboxes, so Claude
 * (Agent API mail_* actions) doesn't have to log in to webmail. Needs the PHP imap
 * extension (on BlueHost).
 *   'info'  (default) the BlueHost mailbox info@savannahexplorers.com — sends with mail()
 *   'gmail' savannah.explorers@gmail.com — IMAP imap.gmail.com, sends via SMTP
 *           smtp.gmail.com:587 (Gmail keeps the Sent copy itself)
 * mbx_use('gmail') switches every function below to that mailbox.
 *
 * Server-only constants in the root includes/config.php (never logged):
 *   MAILBOX_USER   'info@savannahexplorers.com'
 *   MAILBOX_PASS   the mailbox password
 *   MAILBOX_IMAP   optional, default '{localhost:993/imap/ssl/novalidate-cert}'
 *   MAILBOX_NAME   optional From name, default 'Savannah Explorers'
 *   GMAIL_USER, GMAIL_APP_PASSWORD   Gmail address + app password (Google account → App passwords)
 *   GMAIL_NAME     optional From name, default 'Savannah Explorers'
 *   GMAIL_DRAFTS   optional Drafts folder (else found by its \Drafts flag: "[Gmail]/Bozze" …)
 *
 * Messages are addressed by IMAP UID + folder (UIDs are stable within a folder).
 * Reading never marks a message as seen unless asked. Nothing here deletes mail.
 */

const MBX_MAX_LIST       = 100;
const MBX_MAX_BODY_CHARS = 100000;
const MBX_MAX_ATTACHMENT = 10 * 1024 * 1024;

const MBX_BOXES = ['info', 'gmail'];

/** Switch the mailbox the functions below work on ('info' | 'gmail'). */
function mbx_use(string $box): void {
    if (!in_array($box, MBX_BOXES, true)) throw new InvalidArgumentException('Unknown mailbox "' . $box . '" — use info or gmail');
    $GLOBALS['MBX_BOX'] = $box;
}

/** The current mailbox ('info' by default). */
function mbx_box(): string {
    return $GLOBALS['MBX_BOX'] ?? 'info';
}

/** Login, server and From of the current mailbox: [user, pass, imap, name, smtp]. */
function mbx_account(): array {
    $c = function (string $n, string $def = ''): string { return defined($n) ? (string)constant($n) : $def; };
    if (mbx_box() === 'gmail') {
        return ['user' => $c('GMAIL_USER'), 'pass' => $c('GMAIL_APP_PASSWORD'), 'imap' => '{imap.gmail.com:993/imap/ssl}',
                'name' => $c('GMAIL_NAME', 'Savannah Explorers'), 'smtp' => ['host' => 'smtp.gmail.com', 'port' => 587]];
    }
    return ['user' => $c('MAILBOX_USER'), 'pass' => $c('MAILBOX_PASS'),
            'imap' => $c('MAILBOX_IMAP', '{localhost:993/imap/ssl/novalidate-cert}'),
            'name' => $c('MAILBOX_NAME', 'Savannah Explorers'), 'smtp' => null];
}

/** Constants to define for the current mailbox (error messages). */
function mbx_config_hint(): string {
    return mbx_box() === 'gmail' ? 'GMAIL_USER / GMAIL_APP_PASSWORD' : 'MAILBOX_USER / MAILBOX_PASS';
}

function mbx_configured(): bool {
    $a = mbx_account();
    return function_exists('imap_open') && $a['user'] !== '' && $a['pass'] !== '';
}

/** '{host:port/flags}' part of every mailbox name. */
function mbx_server(): string {
    return mbx_account()['imap'];
}

/** Open the mailbox on $folder (default INBOX). Throws RuntimeException. */
function mbx_open(string $folder = 'INBOX') {
    if (!function_exists('imap_open')) throw new RuntimeException('PHP imap extension not available');
    if (!mbx_configured()) throw new RuntimeException('Mailbox not configured — define ' . mbx_config_hint() . ' in includes/config.php');
    $a = mbx_account();
    $c = @imap_open(mbx_server() . mbx_folder_encode($folder), $a['user'], $a['pass'], 0, 1);
    if (!$c) {
        $err = imap_last_error() ?: 'unknown error';
        imap_errors();
        throw new RuntimeException('IMAP login failed: ' . $err);
    }
    return $c;
}

function mbx_close($c): void {
    @imap_close($c);
    imap_errors();   // drop queued notices so they don't end up in the output
    imap_alerts();
}

function mbx_folder_encode(string $folder): string {
    return function_exists('mb_convert_encoding') ? mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8') : $folder;
}

function mbx_folder_decode(string $folder): string {
    return function_exists('mb_convert_encoding') ? mb_convert_encoding($folder, 'UTF-8', 'UTF7-IMAP') : $folder;
}

/** Decode a MIME header (=?UTF-8?B?…?=) to UTF-8. */
function mbx_decode_header($s): string {
    $s = (string)$s;
    if ($s === '' || strpos($s, '=?') === false) return $s;   // plain (possibly raw UTF-8): leave as is
    $d = @iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    return $d !== false ? $d : $s;
}

/** Address objects from imap_headerinfo → [{name, email}]. */
function mbx_addresses($list): array {
    $out = [];
    foreach ((array)$list as $a) {
        if (!isset($a->mailbox)) continue;
        $email = $a->mailbox . (isset($a->host) ? '@' . $a->host : '');
        $out[] = ['name' => isset($a->personal) ? mbx_decode_header($a->personal) : '', 'email' => strtolower($email)];
    }
    return $out;
}

/** All folders → [{name, messages, unseen}]. */
function mbx_folders(): array {
    $c = mbx_open();
    try {
        $out = [];
        foreach ((array)imap_getmailboxes($c, mbx_server(), '*') as $box) {
            $name = mbx_folder_decode(substr($box->name, strlen(mbx_server())));
            if (strpos($name, '.temp.') !== false || ($box->attributes & LATT_NOSELECT)) continue;
            $st = @imap_status($c, $box->name, SA_MESSAGES | SA_UNSEEN);
            $out[] = ['name' => $name, 'messages' => $st ? (int)$st->messages : null, 'unseen' => $st ? (int)$st->unseen : null];
        }
        usort($out, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        return $out;
    } finally {
        mbx_close($c);
    }
}

/**
 * Special folder by role ('sent' | 'drafts' | 'trash'): MAILBOX_SENT / _DRAFTS / _TRASH
 * if defined, else the first existing of the usual names.
 */
function mbx_special_folder($c, string $role): string {
    $const = (mbx_box() === 'gmail' ? 'GMAIL_' : 'MAILBOX_') . strtoupper($role);
    if (defined($const)) return (string)constant($const);
    if (mbx_box() === 'gmail') {
        // Gmail names its folders in the account language ("[Gmail]/Bozze"): find them by flag.
        $byFlag = mbx_special_use();
        if (isset($byFlag[$role])) return $byFlag[$role];
    }
    $names = [
        'sent'   => ['INBOX.Sent', 'Sent', 'INBOX.Sent Messages', 'Sent Messages', 'INBOX.Sent Items', '[Gmail]/Sent Mail', '[Google Mail]/Sent Mail'],
        'drafts' => ['INBOX.Drafts', 'Drafts', '[Gmail]/Drafts', '[Google Mail]/Drafts', '[Gmail]/Bozze', '[Gmail]/Brouillons', '[Gmail]/Entwürfe', '[Gmail]/Borradores'],
        'trash'  => ['INBOX.Trash', 'Trash', '[Gmail]/Trash', '[Gmail]/Cestino'],
    ][$role] ?? [];
    $have = [];
    foreach ((array)imap_list($c, mbx_server(), '*') as $full) $have[] = mbx_folder_decode(substr($full, strlen(mbx_server())));
    foreach ($names as $n) if (in_array($n, $have, true)) return $n;
    throw new RuntimeException('No ' . $role . ' folder found — define ' . $const . ' in includes/config.php');
}

/**
 * RFC 6154 special-use folders of the current mailbox: ['drafts' => name, 'sent' => …, 'trash' => …].
 * The imap extension (c-client) doesn't report these flags, so this asks the server with a
 * plain IMAP LIST over TLS. [] when the server can't be asked.
 */
function mbx_special_use(): array {
    static $cache = [];
    $box = mbx_box();
    if (isset($cache[$box])) return $cache[$box];
    $a = mbx_account();
    if (!preg_match('#^\{([^:}/]+):(\d+)#', $a['imap'], $m)) return $cache[$box] = [];
    $fp = @stream_socket_client('ssl://' . $m[1] . ':' . $m[2], $errno, $errstr, 15);
    if (!$fp) return $cache[$box] = [];
    stream_set_timeout($fp, 15);
    $q = function (string $s): string { return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"'; };
    $cmd = function (string $tag, string $line) use ($fp): array {
        fwrite($fp, $tag . ' ' . $line . "\r\n");
        $lines = [];
        while (($l = fgets($fp)) !== false) {
            $lines[] = rtrim($l, "\r\n");
            if (strpos($l, $tag . ' ') === 0) break;
        }
        return $lines;
    };
    $out = [];
    try {
        fgets($fp);                                                   // greeting
        $login = $cmd('a1', 'LOGIN ' . $q($a['user']) . ' ' . $q($a['pass']));
        if (stripos((string)end($login), 'a1 OK') !== 0) return $cache[$box] = [];
        foreach ($cmd('a2', 'LIST "" "*"') as $l) {
            if (!preg_match('/^\* LIST \(([^)]*)\) \S+ (.+)$/i', $l, $x)) continue;
            $name = trim($x[2]);
            if ($name !== '' && $name[0] === '"') $name = stripcslashes(substr($name, 1, -1));
            foreach (['drafts' => '\Drafts', 'sent' => '\Sent', 'trash' => '\Trash'] as $role => $flag) {
                if (stripos($x[1], $flag) !== false) $out[$role] = mbx_folder_decode($name);
            }
        }
        $cmd('a3', 'LOGOUT');
    } finally {
        fclose($fp);
    }
    return $cache[$box] = $out;
}

/** 'YYYY-MM-DD' → IMAP search date '3-Oct-2026'. */
function mbx_search_date(string $iso): string {
    return date('j-M-Y', strtotime($iso));
}

/** Quote a value for an IMAP SEARCH criterion. */
function mbx_q(string $v): string {
    return '"' . str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', ' ', ' '], $v) . '"';
}

/**
 * Messages in $folder, newest first.
 * $f: unseen (bool), flagged (bool), from, to, subject, text, since, before (YYYY-MM-DD).
 * → ['folder', 'total', 'messages' => [{uid, date, from, to, subject, seen, flagged, answered, size, has_attachments}]]
 */
function mbx_list(string $folder, array $f, int $limit = 30, int $offset = 0): array {
    $crit = [];
    if (!empty($f['unseen']))  $crit[] = 'UNSEEN';
    if (!empty($f['flagged'])) $crit[] = 'FLAGGED';
    foreach (['from' => 'FROM', 'to' => 'TO', 'subject' => 'SUBJECT', 'text' => 'TEXT'] as $k => $kw) {
        $v = trim((string)($f[$k] ?? ''));
        if ($v !== '') $crit[] = $kw . ' ' . mbx_q($v);
    }
    if (!empty($f['since']))  $crit[] = 'SINCE ' . mbx_search_date($f['since']);
    if (!empty($f['before'])) $crit[] = 'BEFORE ' . mbx_search_date($f['before']);
    $criteria = $crit ? implode(' ', $crit) : 'ALL';

    $limit = max(1, min(MBX_MAX_LIST, $limit));
    $c = mbx_open($folder);
    try {
        $uids = imap_sort($c, SORTARRIVAL, 1, SE_UID | SE_NOPREFETCH, $criteria, 'UTF-8');
        if ($uids === false) $uids = [];
        $total = count($uids);
        $page  = array_slice($uids, max(0, $offset), $limit);
        $msgs  = [];
        if ($page) {
            $ov = imap_fetch_overview($c, implode(',', $page), FT_UID) ?: [];
            $byUid = [];
            foreach ($ov as $o) $byUid[(int)$o->uid] = $o;
            foreach ($page as $uid) {
                if (!isset($byUid[$uid])) continue;
                $o = $byUid[$uid];
                $st = @imap_fetchstructure($c, $uid, FT_UID);
                $msgs[] = [
                    'uid'      => (int)$uid,
                    'date'     => isset($o->date) && strtotime($o->date) ? date('Y-m-d H:i', strtotime($o->date)) : null,
                    'from'     => mbx_decode_header($o->from ?? ''),
                    'to'       => mbx_decode_header($o->to ?? ''),
                    'subject'  => mbx_decode_header($o->subject ?? ''),
                    'seen'     => !empty($o->seen),
                    'flagged'  => !empty($o->flagged),
                    'answered' => !empty($o->answered),
                    'size'     => (int)($o->size ?? 0),
                    'has_attachments' => $st ? (bool)mbx_parts($st)['attachments'] : false,
                ];
            }
        }
        return ['folder' => $folder, 'total' => $total, 'offset' => max(0, $offset), 'messages' => $msgs];
    } finally {
        mbx_close($c);
    }
}

/**
 * Walk a message structure → ['text' => [part, enc, charset]|null, 'html' => …,
 * 'attachments' => [{part, name, mime, size, inline}]]. Part numbers as imap_fetchbody wants.
 */
function mbx_parts($st, string $prefix = ''): array {
    $out = ['text' => null, 'html' => null, 'attachments' => []];
    $types = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'model', 'other'];

    if (!empty($st->parts) && $st->type == TYPEMULTIPART) {
        foreach ($st->parts as $i => $p) {
            $num = ($prefix === '' ? '' : $prefix . '.') . ($i + 1);
            $sub = mbx_parts($p, $num);
            if ($out['text'] === null) $out['text'] = $sub['text'];
            if ($out['html'] === null) $out['html'] = $sub['html'];
            $out['attachments'] = array_merge($out['attachments'], $sub['attachments']);
        }
        return $out;
    }

    $num    = $prefix === '' ? '1' : $prefix;
    $params = [];
    foreach (array_merge((array)($st->parameters ?? []), (array)($st->dparameters ?? [])) as $p) {
        $params[strtolower($p->attribute)] = $p->value;
    }
    $name = $params['filename'] ?? $params['name'] ?? ($params['filename*'] ?? $params['name*'] ?? '');
    if ($name !== '' && preg_match("/^([^']*)'[^']*'(.*)$/", $name, $m)) $name = rawurldecode($m[2]);   // RFC 2231
    $name = mbx_decode_header($name);
    $disp = strtolower((string)($st->disposition ?? ''));
    $mime = $types[(int)$st->type] . '/' . strtolower((string)($st->subtype ?? ''));

    if ($disp !== 'attachment' && $name === '' && $mime === 'text/plain') {
        $out['text'] = [$num, (int)$st->encoding, $params['charset'] ?? ''];
    } elseif ($disp !== 'attachment' && $name === '' && $mime === 'text/html') {
        $out['html'] = [$num, (int)$st->encoding, $params['charset'] ?? ''];
    } elseif ($mime === 'message/rfc822' && $name === '') {
        $out['attachments'][] = ['part' => $num, 'name' => 'message.eml', 'mime' => $mime, 'size' => (int)($st->bytes ?? 0), 'inline' => false];
    } elseif ((int)$st->type !== TYPEMULTIPART) {
        $out['attachments'][] = ['part' => $num, 'name' => $name !== '' ? $name : 'part-' . $num, 'mime' => $mime,
                                 'size' => (int)($st->bytes ?? 0), 'inline' => $disp === 'inline' || $disp === ''];
    }
    return $out;
}

/** Fetch + decode one body part to UTF-8 (FT_PEEK unless $markSeen). */
function mbx_fetch_part($c, int $uid, array $part, bool $markSeen, bool $toUtf8 = true): string {
    [$num, $enc, $charset] = $part;
    $raw = (string)imap_fetchbody($c, $uid, $num, FT_UID | ($markSeen ? 0 : FT_PEEK));
    if ($enc === ENCBASE64)               $raw = (string)base64_decode($raw);
    elseif ($enc === ENCQUOTEDPRINTABLE)  $raw = quoted_printable_decode($raw);
    if ($toUtf8 && $charset !== '' && strtoupper($charset) !== 'UTF-8') {
        $conv = @iconv($charset, 'UTF-8//IGNORE', $raw);
        if ($conv !== false) $raw = $conv;
    }
    return $raw;
}

/** HTML mail body → readable plain text. */
function mbx_html_to_text(string $html): string {
    $html = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', '', $html);
    $html = preg_replace('#<br\s*/?>|</(p|div|tr|li|h[1-6]|blockquote)>#i', "\n", $html);
    $txt  = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $txt  = preg_replace("/[ \t\x{00A0}]+/u", ' ', $txt);
    return trim(preg_replace("/\n\s*\n\s*\n+/", "\n\n", $txt));
}

/**
 * Value of one header in a raw header block ('' if missing or empty). Folded lines are unfolded
 * (RFC 5322) and the value never runs into the next header: an empty "References:" line stays empty
 * (Outlook writes them — 21 Oct 2026 the old \s* regex returned the following header as the value).
 */
function mbx_raw_header(string $raw, string $name): string {
    $raw = preg_replace('/\r?\n[ \t]+/', ' ', str_replace("\r\n", "\n", $raw));
    return preg_match('/^' . preg_quote($name, '/') . ':[ \t]*([^\n]*)$/mi', $raw, $m) ? trim($m[1]) : '';
}

/** The Message-IDs "<…@…>" in a header value, in order, without duplicates; anything else is dropped. */
function mbx_msgids(string $s): array {
    preg_match_all('/<[^<>\s@]+@[^<>\s@]+>/', $s, $m);
    return array_values(array_unique($m[0]));
}

/**
 * In-Reply-To + References for a reply. $orig = mbx_get() of the message replied to (reply_to_uid) or null;
 * $in['in_reply_to'] / $in['references'] (string or list), when given, win over the original's values.
 * Only "<…@…>" tokens are kept. → [inReplyTo, references] ('' when not a reply).
 */
function mbx_thread_headers(?array $orig, array $in): array {
    $refsIn = is_array($in['references'] ?? null) ? implode(' ', $in['references']) : trim((string)($in['references'] ?? ''));
    $inReplyTo = trim((string)($in['in_reply_to'] ?? ''));
    if ($inReplyTo !== '') {
        if ($inReplyTo[0] !== '<') $inReplyTo = '<' . $inReplyTo . '>';
        if (!preg_match('/^<[^<>\s]+@[^<>\s]+>$/', $inReplyTo)) throw new InvalidArgumentException('in_reply_to must be a Message-ID like <abc@mail.gmail.com>');
    } elseif ($orig) {
        $inReplyTo = mbx_msgids((string)($orig['message_id'] ?? ''))[0] ?? '';
    }
    if ($inReplyTo === '') return ['', ''];
    if (trim($refsIn) !== '') {
        if (!preg_match('/^(<[^<>\s]+>\s?)*$/', trim((string)preg_replace('/\s+/', ' ', $refsIn)))) {
            throw new InvalidArgumentException('references must be Message-IDs like "<a@b> <c@d>"');
        }
        $refs = mbx_msgids($refsIn);
    } else {
        $refs = $orig ? mbx_msgids((string)($orig['references'] ?? '')) : [];
    }
    if (!in_array($inReplyTo, $refs, true)) $refs[] = $inReplyTo;
    return [$inReplyTo, implode(' ', $refs)];
}

/** One message with body text and attachment list. Null if the UID doesn't exist. */
function mbx_get(string $folder, int $uid, bool $markSeen = false): ?array {
    $c = mbx_open($folder);
    try {
        $msgno = @imap_msgno($c, $uid);
        if (!$msgno) return null;
        $h  = imap_headerinfo($c, $msgno);
        $st = imap_fetchstructure($c, $uid, FT_UID);
        if (!$h || !$st) return null;
        $parts = mbx_parts($st);
        $text  = $parts['text'] ? mbx_fetch_part($c, $uid, $parts['text'], $markSeen) : '';
        $html  = $parts['html'] ? mbx_fetch_part($c, $uid, $parts['html'], $markSeen) : '';
        if (trim($text) === '' && $html !== '') $text = mbx_html_to_text($html);
        $trunc = mb_strlen($text) > MBX_MAX_BODY_CHARS;
        if ($trunc) $text = mb_substr($text, 0, MBX_MAX_BODY_CHARS);
        if ($markSeen) imap_setflag_full($c, (string)$uid, '\\Seen', ST_UID);

        $raw = (string)imap_fetchheader($c, $uid, FT_UID);
        $hdr = function (string $name) use ($raw): string { return mbx_raw_header($raw, $name); };

        return [
            'folder'      => $folder,
            'uid'         => $uid,
            'date'        => isset($h->date) && strtotime($h->date) ? date('Y-m-d H:i', strtotime($h->date)) : null,
            'from'        => mbx_addresses($h->from ?? []),
            'reply_to'    => mbx_addresses($h->reply_to ?? []),
            'to'          => mbx_addresses($h->to ?? []),
            'cc'          => mbx_addresses($h->cc ?? []),
            'subject'     => mbx_decode_header($h->subject ?? ''),
            'message_id'  => trim((string)($h->message_id ?? '')),
            'in_reply_to' => $hdr('In-Reply-To'),
            'references'  => $hdr('References'),
            'seen'        => $markSeen || trim((string)($h->Unseen ?? '')) === '' && trim((string)($h->Recent ?? '')) !== 'N',
            'flagged'     => trim((string)($h->Flagged ?? '')) === 'F',
            'answered'    => trim((string)($h->Answered ?? '')) === 'A',
            'body'        => $text,
            'body_truncated' => $trunc,
            'has_html'    => $html !== '',
            'attachments' => array_values(array_filter($parts['attachments'], function ($a) {
                return !($a['inline'] && strpos($a['mime'], 'image/') === 0 && $a['size'] < 20000);   // skip signature logos
            })),
        ];
    } finally {
        mbx_close($c);
    }
}

/** One attachment → ['name', 'mime', 'size', 'content' (binary)]. Null if not found. */
function mbx_attachment(string $folder, int $uid, string $part): ?array {
    $c = mbx_open($folder);
    try {
        $st = @imap_fetchstructure($c, $uid, FT_UID);
        if (!$st) return null;
        foreach (mbx_parts($st)['attachments'] as $a) {
            if ($a['part'] !== $part) continue;
            if ($a['size'] > MBX_MAX_ATTACHMENT * 1.4) throw new RuntimeException('Attachment too large (' . $a['size'] . ' bytes)');
            $p = mbx_find_part($st, $part);
            $bin = mbx_fetch_part($c, $uid, [$part, (int)($p->encoding ?? 0), ''], false, false);
            return ['name' => $a['name'], 'mime' => $a['mime'], 'size' => strlen($bin), 'content' => $bin];
        }
        return null;
    } finally {
        mbx_close($c);
    }
}

function mbx_find_part($st, string $part) {
    foreach (explode('.', $part) as $i) {
        if (empty($st->parts) || !isset($st->parts[(int)$i - 1])) return $st;   // single-part message: "1" is the body
        $st = $st->parts[(int)$i - 1];
    }
    return $st;
}

/** Set / clear \Seen and \Flagged on UIDs. $seen / $flagged: true, false or null (leave). */
function mbx_flag(string $folder, array $uids, ?bool $seen, ?bool $flagged): void {
    $set = implode(',', array_map('intval', $uids));
    $c = mbx_open($folder);
    try {
        foreach (['\\Seen' => $seen, '\\Flagged' => $flagged] as $flag => $on) {
            if ($on === null) continue;
            $on ? imap_setflag_full($c, $set, $flag, ST_UID) : imap_clearflag_full($c, $set, $flag, ST_UID);
        }
    } finally {
        mbx_close($c);
    }
}

/** Move UIDs to another existing folder (e.g. INBOX.Archive). No delete. */
function mbx_move(string $folder, array $uids, string $to): void {
    $c = mbx_open($folder);
    try {
        $exists = false;
        foreach ((array)imap_list($c, mbx_server(), '*') as $full) {
            if (mbx_folder_decode(substr($full, strlen(mbx_server()))) === $to) { $exists = true; break; }
        }
        if (!$exists) throw new InvalidArgumentException('Folder "' . $to . '" does not exist (see mail_folders)');
        if (!imap_mail_move($c, implode(',', array_map('intval', $uids)), mbx_folder_encode($to), CP_UID)) {
            throw new RuntimeException('Move failed: ' . (imap_last_error() ?: 'unknown error'));
        }
        imap_expunge($c);
    } finally {
        mbx_close($c);
    }
}

/**
 * Build an outgoing message from the current mailbox (info@ or Gmail).
 * $in: to, cc, bcc (string or array), subject, body (plain text) or body_html,
 *      reply_to_uid + reply_folder (threads the reply: Re: subject, In-Reply-To, To = sender),
 *      or in_reply_to (+ references): the Message-ID of a message read elsewhere (Gmail connector),
 *      attachments [{name, content_base64}].
 * → ['mailer' => PHPMailer (preSend done), 'summary' => [...], 'reply' => ['folder','uid']|null]
 */
function mbx_compose(array $in) {
    require_once __DIR__ . '/../modules/leads/includes/phpmailer/Exception.php';
    require_once __DIR__ . '/../modules/leads/includes/phpmailer/PHPMailer.php';
    if (!mbx_configured()) throw new RuntimeException('Mailbox not configured — define ' . mbx_config_hint() . ' in includes/config.php');
    $acc = mbx_account();

    $emails = function ($v): array {
        $list = is_array($v) ? $v : preg_split('/[,;]+/', (string)$v);
        $out = [];
        foreach ($list as $a) {
            $a = trim((string)$a);
            if (preg_match('/<([^>]+)>/', $a, $m)) $a = $m[1];
            if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL)) $out[] = strtolower($a);
        }
        return array_values(array_unique($out));
    };

    $to = $emails($in['to'] ?? []);
    $cc = $emails($in['cc'] ?? []);
    $bcc = $emails($in['bcc'] ?? []);
    $subject = trim((string)($in['subject'] ?? ''));
    $reply = $orig = null;

    if ((int)($in['reply_to_uid'] ?? 0) > 0) {
        $rf   = (string)($in['reply_folder'] ?? 'INBOX');
        $orig = mbx_get($rf, (int)$in['reply_to_uid']);
        if (!$orig) throw new InvalidArgumentException('Message uid ' . (int)$in['reply_to_uid'] . ' not found in ' . $rf);
        $reply = ['folder' => $rf, 'uid' => (int)$in['reply_to_uid']];
        if (!$to) {
            $src = $orig['reply_to'] ?: $orig['from'];
            $to  = array_column($src, 'email');
        }
        if ($subject === '') $subject = preg_match('/^\s*re:/i', $orig['subject']) ? $orig['subject'] : 'Re: ' . $orig['subject'];
    }
    // Threading: from the original (reply_to_uid) or a message read with another tool (in_reply_to + references).
    [$inReplyTo, $references] = mbx_thread_headers($orig, $in);
    if (!$to) throw new InvalidArgumentException('to is required (or reply_to_uid)');
    if ($subject === '') throw new InvalidArgumentException('subject is required');
    $text = (string)($in['body'] ?? '');
    $html = (string)($in['body_html'] ?? '');
    if (trim($text) === '' && trim($html) === '') throw new InvalidArgumentException('body (or body_html) is required');

    $m = new PHPMailer\PHPMailer\PHPMailer(true);
    if ($acc['smtp']) {
        require_once __DIR__ . '/../modules/leads/includes/phpmailer/SMTP.php';
        $m->isSMTP();
        $m->Host       = $acc['smtp']['host'];
        $m->Port       = $acc['smtp']['port'];
        $m->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $m->SMTPAuth   = true;
        $m->Username   = $acc['user'];
        $m->Password   = $acc['pass'];
        $m->Timeout    = 30;
    } else {
        $m->isMail();
    }
    $m->CharSet = 'UTF-8';
    $m->setFrom($acc['user'], $acc['name']);
    $m->Sender = $acc['user'];
    foreach ($to as $a)  $m->addAddress($a);
    foreach ($cc as $a)  $m->addCC($a);
    foreach ($bcc as $a) $m->addBCC($a);
    $m->Subject = $subject;
    if ($inReplyTo !== '') {
        $m->addCustomHeader('In-Reply-To', $inReplyTo);
        $m->addCustomHeader('References', $references);
    }
    if (trim($html) !== '') {
        $m->isHTML(true);
        $m->Body    = $html;
        $m->AltBody = trim($text) !== '' ? $text : mbx_html_to_text($html);
    } else {
        $m->Body = $text;
    }

    $attNames = [];
    foreach ((array)($in['attachments'] ?? []) as $i => $a) {
        $name = trim((string)($a['name'] ?? ''));
        $bin  = base64_decode((string)($a['content_base64'] ?? ''), true);
        if ($name === '' || $bin === false || $bin === '') throw new InvalidArgumentException('attachments[' . $i . ']: name and content_base64 are required');
        if (strlen($bin) > MBX_MAX_ATTACHMENT) throw new InvalidArgumentException('attachments[' . $i . ']: larger than 10 MB');
        $m->addStringAttachment($bin, $name);
        $attNames[] = $name . ' (' . strlen($bin) . ' bytes)';
    }

    $m->preSend();
    return [
        'mailer'  => $m,
        'reply'   => $reply,
        'summary' => ['from' => $acc['user'], 'mailbox' => mbx_box(), 'to' => $to, 'cc' => $cc, 'bcc' => $bcc, 'subject' => $subject,
                      'in_reply_to' => $inReplyTo ?: null, 'references' => $references ?: null, 'body' => trim($text) !== '' ? $text : mbx_html_to_text($html),
                      'attachments' => $attNames],
    ];
}

/** Store a copy of the composed message in a folder (Drafts / Sent). */
function mbx_append(string $role, string $mime, string $flags): string {
    $c = mbx_open();
    try {
        $folder = mbx_special_folder($c, $role);
        if (!imap_append($c, mbx_server() . mbx_folder_encode($folder), $mime, $flags)) {
            throw new RuntimeException('Saving to ' . $folder . ' failed: ' . (imap_last_error() ?: 'unknown error'));
        }
        return $folder;
    } finally {
        mbx_close($c);
    }
}

/** Save a composed message in Drafts (nothing is sent). → the Drafts folder name. */
function mbx_save_draft(array $composed): string {
    $m = $composed['mailer'];
    return mbx_append('drafts', $m->getSentMIMEMessage(), '\\Draft \\Seen');
}

/**
 * Send a composed message, keep a copy in Sent and mark the original \Answered.
 * Gmail (SMTP) files the Sent copy itself, so nothing is appended there.
 * → ['sent_folder' => string|null, 'warning' => string|null]
 */
function mbx_send(array $composed): array {
    $m = $composed['mailer'];
    if (!$m->postSend()) throw new RuntimeException('Send failed: ' . $m->ErrorInfo);
    $warn = null;
    $sent = null;
    try {
        $sent = mbx_box() === 'gmail' ? '(Gmail Sent)' : mbx_append('sent', $m->getSentMIMEMessage(), '\\Seen');
        if ($composed['reply']) {
            $c = mbx_open($composed['reply']['folder']);
            try { imap_setflag_full($c, (string)$composed['reply']['uid'], '\\Answered', ST_UID); } finally { mbx_close($c); }
        }
    } catch (Throwable $e) {
        $warn = 'Sent, but: ' . $e->getMessage();
    }
    return ['sent_folder' => $sent, 'warning' => $warn];
}
