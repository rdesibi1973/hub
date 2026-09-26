<?php
/**
 * modules/iti/program_doc.php — internal preview of the programme document
 * (Etnia layout), same as the public link. ?id=N&lang=it
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';
require_once __DIR__ . '/includes/iti_doc.php';
require_once __DIR__ . '/includes/iti_translate.php';

$id = (int)($_GET['id'] ?? 0);
$program = $id ? iti_get_program($id) : false;
if (!$program) { iti_flash_set('error', 'Program not found.'); iti_redirect('programs.php'); }

$lang = $_GET['lang'] ?? $program['display_language'] ?? 'it';
if (!in_array($lang, ITI_LANGUAGES, true)) $lang = 'it';

// Translate the missing texts of this language (Claude), then reload.
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'translate') {
    @set_time_limit(600);
    try {
        $r = iti_translate_program($id, $lang);
        $msg = '✔ ' . $r['filled'] . ' texts translated from ' . strtoupper($r['from']) . ' into ' . strtoupper($lang) . '.';
    } catch (Throwable $e) {
        $msg = '✖ ' . $e->getMessage();
    }
    $program = iti_get_program($id);
}

$D = iti_doc_data($id, $lang);
$extra = '';
$missing = iti_translate_missing($id, $lang);
if ($missing > 0) {
    $extra .= '<form method="post" style="display:inline;margin:0" onsubmit="this.lastChild.disabled=true;this.lastChild.textContent=&quot;Translating… (up to a minute)&quot;">'
            . '<input type="hidden" name="action" value="translate">'
            . '<button style="font:inherit;font-weight:600;border:1px solid #C0211B;color:#C0211B;background:#fff;border-radius:14px;padding:4px 10px;cursor:pointer">🌐 Translate into ' . strtoupper(h($lang)) . ' (' . $missing . ' texts missing)</button></form>';
}
if ($msg !== '') $extra .= '<span style="font-weight:600;color:' . (strpos($msg, '✖') === 0 ? '#C0211B' : '#1A6B3A') . '">' . h($msg) . '</span>';
$extra .= '<a href="program_edit.php?id=' . $id . '">✏️ Edit</a>';
if (!empty($program['is_published']) && !empty($program['public_token'])) {
    $extra .= '<a href="itinerary.php?token=' . h($program['public_token']) . '&lang=' . h($lang) . '" target="_blank">🔗 Public link</a>';
}
$extra .= '<a href="#" onclick="window.print();return false">🖨 Print / PDF</a>';
iti_doc_page($D, iti_doc_lang_bar($lang, ['id' => $id], $extra));
