<?php
/**
 * eml_sanitize.php — a raw mail (RFC 822) made safe to keep in a booking folder as .eml (proof of a supplier's
 * extension, Booking follow-up; Agent API staff_mail_save_eml). Passport numbers live only in passports/ and the Calc:
 *   - an attachment named passport / passaporto / pasaporte / reisepass … is replaced by a short text part saying so;
 *   - passport numbers ("passport no. YA1234567", machine-readable MRZ lines) are masked in the text parts and the
 *     Subject (decoded, masked, encoded again in the same transfer encoding — only parts that change are rewritten).
 * Everything else is kept byte for byte (headers, boundaries, other attachments). Self-contained: no Hub includes.
 */

const EML_PASSPORT_FILE = '/pass(?:port|aporto|eport)|pasaporte|reisepass/i';
const EML_MAX_DEPTH     = 12;

/** → ['raw' => sanitized mail, 'removed' => [attachment names replaced], 'masked' => number of text parts / headers masked]. */
function eml_sanitize(string $raw): array {
    $st = ['removed' => [], 'masked' => 0];
    $out = eml_entity($raw, $st, 0);
    return ['raw' => $out, 'removed' => $st['removed'], 'masked' => $st['masked']];
}

/** Passport numbers out of a text (bytes, any charset): same patterns as followup_strip_passport() / sm_mask(). */
function eml_mask(string $s): string {
    $r = preg_replace('/(pass(?:port|aporto|eport)\w*\s*(?:no\.?|n\.?|nr\.?|number|numero|#)?\s*[:#]?\s*)[A-Z]{0,3}\d[A-Z0-9]{4,}/i', '$1[removed]', $s);
    if ($r === null) return $s;
    $r2 = preg_replace('/(?<![A-Z0-9<])(?=[A-Z0-9<]*<)(?=[A-Z0-9<]*\d)[A-Z0-9<]{30,44}(?![A-Z0-9<])/', '[removed]', $r);
    return $r2 === null ? $r : $r2;
}

/** Unfolded headers, lower-case names (first occurrence wins). */
function eml_headers(string $head): array {
    $h = [];
    foreach (preg_split('/\r?\n(?![ \t])/', $head) as $line) {
        $p = strpos($line, ':');
        if ($p === false) continue;
        $k = strtolower(trim(substr($line, 0, $p)));
        if (!isset($h[$k])) $h[$k] = trim(preg_replace('/\r?\n[ \t]+/', ' ', substr($line, $p + 1)));
    }
    return $h;
}

/** A header parameter (boundary, name, filename), RFC 2231 and encoded words included. */
function eml_param(string $value, string $name): string {
    if (preg_match('/(?:^|;)\s*' . $name . '\*(?:0\*?)?=(?:[^\']*\'[^\']*\')?("?)([^;"]+)\1/i', $value, $m)) return rawurldecode(trim($m[2]));
    if (preg_match('/(?:^|;)\s*' . $name . '="([^"]*)"/i', $value, $m) || preg_match('/(?:^|;)\s*' . $name . '=([^;\s]+)/i', $value, $m)) {
        return function_exists('iconv_mime_decode') ? (string)@iconv_mime_decode($m[1], ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : $m[1];
    }
    return '';
}

/** Mask a plain (not encoded-word) Subject line in a header block. */
function eml_mask_subject(string $head, array &$st): string {
    return (string)preg_replace_callback('/^(Subject:)(.*(?:\r?\n[ \t].*)*)/mi', function ($m) use (&$st) {
        $v = $m[2];
        if (stripos($v, '=?') !== false && function_exists('iconv_mime_decode')) {
            $d = @iconv_mime_decode(trim(preg_replace('/\r?\n[ \t]+/', ' ', $v)), ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($d !== false && eml_mask($d) !== $d) {
                $st['masked']++;
                return $m[1] . ' =?UTF-8?B?' . base64_encode(eml_mask($d)) . '?=';
            }
            return $m[0];
        }
        $n = eml_mask($v);
        if ($n !== $v) $st['masked']++;
        return $m[1] . $n;
    }, $head, 1);
}

function eml_entity(string $raw, array &$st, int $depth): string {
    if ($depth > EML_MAX_DEPTH) return $raw;
    if (!preg_match('/\r?\n\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE)) return $raw;   // headers only
    $sep = $m[0][0];
    $nl = strpos($sep, "\r") !== false ? "\r\n" : "\n";
    $head = substr($raw, 0, $m[0][1]);
    $body = substr($raw, $m[0][1] + strlen($sep));
    $h = eml_headers($head);
    $ct = $h['content-type'] ?? 'text/plain';
    $type = strtolower(trim(explode(';', $ct, 2)[0]));
    $head = eml_mask_subject($head, $st);

    if (strpos($type, 'multipart/') === 0) {
        $b = eml_param($ct, 'boundary');
        if ($b === '') return $head . $sep . $body;
        // Delimiter lines, with their position: the parts are what lies between them (preamble / epilogue kept).
        if (!preg_match_all('/(?:^|\r?\n)--' . preg_quote($b, '/') . '(--)?[ \t]*(?:\r?\n|$)/', $body, $dl, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return $head . $sep . $body;
        }
        $out = substr($body, 0, $dl[0][0][1]);
        foreach ($dl as $i => $d) {
            $out .= $d[0][0];
            $start = $d[0][1] + strlen($d[0][0]);
            $end = isset($dl[$i + 1]) ? $dl[$i + 1][0][1] : strlen($body);
            $chunk = substr($body, $start, $end - $start);
            $closing = isset($d[1]) && $d[1][0] === '--';
            $out .= $closing ? $chunk : eml_entity($chunk, $st, $depth + 1);   // after the closing line: the epilogue, kept
        }
        return $head . $sep . $out;
    }

    $cd = $h['content-disposition'] ?? '';
    $name = eml_param($cd, 'filename') ?: eml_param($ct, 'name');
    if ($name !== '' && preg_match(EML_PASSPORT_FILE, $name)) {
        $st['removed'][] = $name;
        return 'Content-Type: text/plain; charset=UTF-8' . $nl . 'Content-Transfer-Encoding: 7bit' . $nl . $nl
             . '[Attachment "' . preg_replace('/[^\x20-\x7E]/', '?', $name) . '" removed by the Hub: passports are kept only in the booking\'s passports/ folder.]' . $nl;
    }
    if ($type === 'message/rfc822') return $head . $sep . eml_entity($body, $st, $depth + 1);
    $isAtt = $name !== '' || stripos($cd, 'attachment') === 0;
    if ($isAtt || strpos($type, 'text/') !== 0) return $head . $sep . $body;

    $enc = strtolower(trim($h['content-transfer-encoding'] ?? ''));
    if ($enc === 'base64') {
        $data = (string)base64_decode((string)preg_replace('/\s+/', '', $body));
        $new = eml_mask($data);
        if ($new === $data) return $head . $sep . $body;
        $st['masked']++;
        return $head . $sep . rtrim(chunk_split(base64_encode($new), 76, $nl)) . $nl;
    }
    if ($enc === 'quoted-printable') {
        $data = quoted_printable_decode($body);
        $new = eml_mask($data);
        if ($new === $data) return $head . $sep . $body;
        $st['masked']++;
        return $head . $sep . str_replace("\r\n", $nl, quoted_printable_encode($new)) . $nl;
    }
    $new = eml_mask($body);
    if ($new !== $body) $st['masked']++;
    return $head . $sep . $new;
}
