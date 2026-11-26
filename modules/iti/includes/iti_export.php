<?php
/**
 * iti_export.php — the programme document as a file (magazine layout):
 * Word (.docx, editable, iti_mag_word.php) or PDF (Dompdf, iti_mag_pdf.php),
 * or the simplified guide sheet as PDF (iti_guide_pdf.php) or Word (iti_guide_word.php).
 * Used by export_mag_word.php (?format=pdf / guide / guide_docx) and by the Agent API (iti_document).
 *
 * Keep PHP-7 style (no match / arrow functions / str_contains).
 */
require_once __DIR__ . '/iti_functions.php';
require_once __DIR__ . '/iti_doc.php';
require_once __DIR__ . '/iti_mag_pdf.php';    // also loads iti_mag_word.php

require_once __DIR__ . '/iti_guide_pdf.php';
require_once __DIR__ . '/iti_guide_word.php';

// 'guide' = PDF for the safari guide (safari days only + arrival flight, transfer / accommodation recap,
// no photos or prices); 'guide_docx' = the same as an editable Word file.
const ITI_EXPORT_FORMATS = ['pdf', 'docx', 'guide', 'guide_docx'];

/**
 * Build the document of programme $id in $lang ('' = the programme's language, also for 'guide').
 * Returns ['name', 'mime', 'content', 'lang', 'format', 'title'] — format is the file type (pdf / docx).
 * InvalidArgumentException: unknown programme / format; RuntimeException: library missing.
 * $opt['no_prices']: pdf / docx without the "price per person" block (table + price notes of the language);
 * inclusions / exclusions, tip note and terms stay, the programme's data is not touched. Name gets
 * _SenzaPrezzi before the extension. Ignored by the guide formats (they have no prices already).
 * $opt['neutral'] (pdf only, 26 Nov 2026): the "white label" PDF an agency sends to its end client — no prices, no
 * Savannah logo / footer / contacts / T&C / digital-itinerary link, and Savannah names, mails and links taken out of
 * the texts (iti_export_neutralise()). Name <Title>_<LANG>.pdf (never the ref_number: it holds the agency's name).
 * The reply has 'scrubbed' = how many Savannah mentions were taken out of the texts.
 */
function iti_export_file(int $id, string $lang, string $format, array $opt = array()): array {
    $format = strtolower(trim($format));
    if ($format === 'word') $format = 'docx';
    if ($format === 'guide_word') $format = 'guide_docx';
    if (!in_array($format, ITI_EXPORT_FORMATS, true)) throw new InvalidArgumentException('format must be pdf, docx, guide or guide_docx');
    $program = $id > 0 ? iti_get_program($id) : false;
    if (!$program) throw new InvalidArgumentException('Program ' . $id . ' not found');
    $guide = $format === 'guide' || $format === 'guide_docx';
    if ($guide && ($program['program_type'] ?? '') !== 'personal') {
        throw new InvalidArgumentException('The guide sheet is only for personal programs (program ' . $id . ' is a ' . ($program['program_type'] ?: 'sample') . ')');
    }
    if ($lang === '') $lang = (string)($program['display_language'] ?? 'it');   // the guide sheet too: same language as the client's programme
    if (!in_array($lang, ITI_LANGUAGES, true)) $lang = 'it';

    $vendor = dirname(__DIR__, 3) . '/vendor/autoload.php';
    if (is_file($vendor)) require_once $vendor;
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');

    $D = iti_doc_data($id, $lang);
    $name = trim(preg_replace('/[^A-Za-z0-9]+/', '_', trim($D['title'])), '_') ?: 'Program';
    if ($format === 'guide') {
        return ['name' => $name . '_GUIDE_' . strtoupper($lang) . '.pdf', 'mime' => 'application/pdf', 'content' => iti_guide_pdf_build(iti_guide_data($D)),
                'lang' => $lang, 'format' => 'pdf', 'title' => $D['title']];
    }
    if ($format === 'guide_docx') {
        return ['name' => $name . '_GUIDE_' . strtoupper($lang) . '.docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'content' => iti_guide_word_build(iti_guide_data($D)), 'lang' => $lang, 'format' => 'docx', 'title' => $D['title']];
    }
    $neutral = !empty($opt['neutral']);
    if ($neutral && $format !== 'pdf') throw new InvalidArgumentException('The neutral (white label) document is a PDF only: format must be pdf');
    $noPrices = $neutral || !empty($opt['no_prices']);
    if ($noPrices) {
        // The renderers skip the whole section when there are no prices; the page break before
        // inclusions / exclusions stays, so they start at the top of the page.
        $D['prices'] = array();
        $D['price_notes'] = '';
    }
    $name .= '_' . strtoupper($lang) . ($noPrices && !$neutral ? '_SenzaPrezzi' : '') . '.' . $format;

    $scrubbed = 0;
    if ($neutral) {
        $D = iti_export_neutralise($D, $scrubbed);
    } elseif ($format === 'pdf' && ($program['program_type'] ?? '') === 'personal') {
        // Personal programme: only the PDF carries the link to the digital itinerary (public page). The Word is the
        // agencies' editable copy — they forgot to remove the link and it reached their end client, so it never has one.
        $D['public_url'] = iti_public_url($program);
    }
    if ($format === 'pdf') {
        $content = iti_mag_pdf_build($D);
        $mime = 'application/pdf';
    } else {
        if (!class_exists('\PhpOffice\PhpWord\PhpWord')) throw new RuntimeException('PhpWord is not installed (vendor/autoload.php).');
        list($w, $tmp) = iti_mag_word_build($D);
        $out = tempnam(sys_get_temp_dir(), 'mwdoc');
        try {
            \PhpOffice\PhpWord\IOFactory::createWriter($w, 'Word2007')->save($out);
            $content = (string)file_get_contents($out);
        } finally {
            @unlink($out);
            foreach ($tmp as $f) if (is_file($f)) @unlink($f);
        }
        $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }
    $out = ['name' => $name, 'mime' => $mime, 'content' => $content, 'lang' => $lang, 'format' => $format, 'title' => $D['title']];
    if ($neutral) { $out['neutral'] = true; $out['scrubbed'] = $scrubbed; }
    return $out;
}

/**
 * Document data ($D of iti_doc_data()) without anything that names Savannah, for the white-label PDF: no logo,
 * contacts, terms, footer or public link; "Savannah Explorers / Holidays" (+ a preposition before it), our mail
 * addresses and links taken out of the printed texts. "savannah" alone (the landscape) is kept. Photos are untouched.
 * $count = how many mentions were removed.
 */
function iti_export_neutralise(array $D, &$count = 0): array {
    $count = 0;
    $D['logo'] = '';
    $D['contacts'] = array();
    $D['terms'] = '';
    $D['neutral'] = true;   // iti_mag_pdf_html(): no footer
    unset($D['public_url']);
    $D['prices'] = array();
    $D['price_notes'] = '';
    foreach (array('title', 'subtitle', 'intro') as $k) if (isset($D[$k])) $D[$k] = iti_export_scrub((string)$D[$k], $count);
    foreach (array('incl', 'excl') as $k) {
        if (empty($D[$k])) continue;
        $D[$k] = array_values(array_filter(array_map(function ($t) use (&$count) { return iti_export_scrub((string)$t, $count); }, $D[$k]), 'strlen'));
    }
    $dayKeys = array('title', 'narrative', 'dest', 'dest_desc', 'lodge', 'lodge_area', 'lodge_desc', 'lodge_url', 'tip_note', 'meals', 'room');
    $dayLists = array('transfers', 'flights', 'activities');
    foreach ($D['days'] as $i => $d) {
        foreach ($dayKeys as $k) if (isset($d[$k]) && is_string($d[$k])) $D['days'][$i][$k] = iti_export_scrub($d[$k], $count);
        foreach ($dayLists as $k) {
            if (empty($d[$k]) || !is_array($d[$k])) continue;
            $D['days'][$i][$k] = array_values(array_filter(array_map(function ($t) use (&$count) { return is_string($t) ? iti_export_scrub($t, $count) : $t; }, $d[$k]),
                                                           function ($t) { return !is_string($t) || $t !== ''; }));
        }
    }
    return $D;
}

/**
 * One text without Savannah's name, mail addresses or web links (iti_export_neutralise()). Line by line, so
 * paragraphs and "- " bullets keep their shape: a sentence with our mail / link is dropped whole (it is about
 * contacting us); the name is cut with the preposition before it ("la guida di Savannah Explorers" → "la guida"),
 * except a possessive ("with Savannah Explorers' guides" → "with guides"); a line left with punctuation only goes.
 */
function iti_export_scrub(string $t, &$count): string {
    if ($t === '' || !preg_match('/savannah\s*(explorers|holidays)|savannahexplorers|savannahholidays/iu', $t)) return $t;
    $contact = '/[\w.+-]+@savannah(explorers|holidays)\.[a-z]|(https?:\/\/|www\.)\S*savannah(explorers|holidays)|savannah(explorers|holidays)\.(com|co\.tz|mu|net|org)/iu';
    $name = 'savannah[ \t]*(?:explorers|holidays)(?:[ \t]+(?:ltd\.?|limited|tanzania|mauritius))?';
    $prep = '(?:(?:di|della|del|dello|da|dal|con|by|of|from|with|at|de|du|des|chez|avec|par|von|der|mit|bei|durch|el|la|le|il|lo|the)[ \t]+)?';
    $out = array();
    foreach (preg_split('/\r?\n/', $t) as $line) {
        if (!preg_match('/savannah[ \t]*(explorers|holidays)|savannahexplorers|savannahholidays/iu', $line)) { $out[] = $line; continue; }
        $bullet = preg_match('/^([ \t]*[-•][ \t]+)(.*)$/su', $line, $bm) ? $bm[1] : '';
        $body = $bullet !== '' ? $bm[2] : $line;
        // 1. Sentences about contacting us (our mail / site): out whole.
        $keep = array();
        foreach (preg_split('/(?<=[.!?])[ \t]+/u', $body) as $s) {
            if (preg_match($contact, $s)) { $count++; continue; }
            $keep[] = $s;
        }
        $body = implode(' ', $keep);
        // 2. The name. \x01 marks the cut, to capitalise a sentence that started with it.
        $body = preg_replace('/[ \t]*' . $name . '(\'s|’s|\')(?=[ \t])/iu', "\x01", $body, -1, $c); $count += $c;   // possessive: the preposition stays
        $body = preg_replace('/[ \t]*' . $prep . $name . '/iu', "\x01", $body, -1, $c); $count += $c;
        $body = preg_replace_callback('/(^|[.!?][ \t]*)\x01[ \t]*(\p{Ll})/u', function ($m) { return $m[1] . ($m[1] !== '' ? ' ' : '') . mb_strtoupper($m[2]); }, $body);
        $body = str_replace("\x01", ' ', $body);
        // 3. Left-over punctuation and spaces.
        $body = preg_replace('/\([ \t]*\)|\[[ \t]*\]/u', '', $body);
        $body = preg_replace('/[ \t]+([,.;:!?)])/u', '$1', $body);
        $body = preg_replace('/([(\[])[ \t]+/u', '$1', $body);
        $body = preg_replace('/[ \t]{2,}/u', ' ', $body);
        $body = preg_replace('/^[ \t]*[,;:·–—][ \t]*|[ \t]*[,;:·–—][ \t]*$/u', '', trim($body));
        $body = preg_replace('/^([.!?])[ \t]*/u', '', $body);
        if (preg_match('/^[\p{P}\p{Z}]*$/u', $body)) continue;   // nothing left but punctuation: the line goes
        $out[] = $bullet . $body;
    }
    $t = implode("\n", $out);
    return trim(preg_replace('/\n{3,}/', "\n\n", $t));
}

/** Send a file from iti_export_file() to the browser as a download. */
function iti_export_send(array $f): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . $f['mime']);
    header('Content-Disposition: attachment; filename="' . $f['name'] . '"');
    header('Content-Length: ' . strlen($f['content']));
    echo $f['content'];
}
