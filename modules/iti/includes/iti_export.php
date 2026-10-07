<?php
/**
 * iti_export.php — the programme document as a file (magazine layout):
 * Word (.docx, editable, iti_mag_word.php) or PDF (Dompdf, iti_mag_pdf.php),
 * or the simplified guide sheet as PDF (iti_guide_pdf.php).
 * Used by export_mag_word.php (?format=pdf / guide) and by the Agent API (iti_document).
 *
 * Keep PHP-7 style (no match / arrow functions / str_contains).
 */
require_once __DIR__ . '/iti_functions.php';
require_once __DIR__ . '/iti_doc.php';
require_once __DIR__ . '/iti_mag_pdf.php';    // also loads iti_mag_word.php

require_once __DIR__ . '/iti_guide_pdf.php';

// 'guide' = PDF for the safari guide (day by day + transfer / accommodation recap, no photos or prices).
const ITI_EXPORT_FORMATS = ['pdf', 'docx', 'guide'];

/**
 * Build the document of programme $id in $lang ('' = the programme's language, also for 'guide').
 * Returns ['name', 'mime', 'content', 'lang', 'format', 'title'] — format is the file type (pdf / docx).
 * InvalidArgumentException: unknown programme / format; RuntimeException: library missing.
 */
function iti_export_file(int $id, string $lang, string $format): array {
    $format = strtolower(trim($format)) === 'word' ? 'docx' : strtolower(trim($format));
    if (!in_array($format, ITI_EXPORT_FORMATS, true)) throw new InvalidArgumentException('format must be pdf, docx or guide');
    $program = $id > 0 ? iti_get_program($id) : false;
    if (!$program) throw new InvalidArgumentException('Program ' . $id . ' not found');
    if ($format === 'guide' && ($program['program_type'] ?? '') !== 'personal') {
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
        return ['name' => $name . '_GUIDE_' . strtoupper($lang) . '.pdf', 'mime' => 'application/pdf', 'content' => iti_guide_pdf_build($D),
                'lang' => $lang, 'format' => 'pdf', 'title' => $D['title']];
    }
    $name .= '_' . strtoupper($lang) . '.' . $format;

    // Personal programme: only the PDF carries the link to the digital itinerary (public page). The Word is the
    // agencies' editable copy — they forgot to remove the link and it reached their end client, so it never has one.
    if ($format === 'pdf' && ($program['program_type'] ?? '') === 'personal') $D['public_url'] = iti_public_url($program);
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
    return ['name' => $name, 'mime' => $mime, 'content' => $content, 'lang' => $lang, 'format' => $format, 'title' => $D['title']];
}

/** Send a file from iti_export_file() to the browser as a download. */
function iti_export_send(array $f): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . $f['mime']);
    header('Content-Disposition: attachment; filename="' . $f['name'] . '"');
    header('Content-Length: ' . strlen($f['content']));
    echo $f['content'];
}
