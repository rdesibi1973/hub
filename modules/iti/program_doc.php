<?php
/**
 * modules/iti/program_doc.php — internal preview of the programme document
 * (Etnia layout), same as the public link. ?id=N&lang=it
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';
require_once __DIR__ . '/includes/iti_doc.php';

$id = (int)($_GET['id'] ?? 0);
$program = $id ? iti_get_program($id) : false;
if (!$program) { iti_flash_set('error', 'Program not found.'); iti_redirect('programs.php'); }

$lang = $_GET['lang'] ?? $program['display_language'] ?? 'it';
if (!in_array($lang, ITI_LANGUAGES, true)) $lang = 'it';

$D = iti_doc_data($id, $lang);
$extra = '<a href="program_edit.php?id=' . $id . '">✏️ Edit</a>';
if (!empty($program['is_published']) && !empty($program['public_token'])) {
    $extra .= '<a href="itinerary.php?token=' . h($program['public_token']) . '&lang=' . h($lang) . '" target="_blank">🔗 Public link</a>';
}
$extra .= '<a href="#" onclick="window.print();return false">🖨 Print / PDF</a>';
iti_doc_page($D, iti_doc_lang_bar($lang, ['id' => $id], $extra));
