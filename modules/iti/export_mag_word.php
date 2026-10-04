<?php
/**
 * modules/iti/export_mag_word.php?id=N&lang=it — the programme in the magazine
 * layout as an editable Word file, for agencies (Hub users only).
 */
ob_start();
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';
require_once __DIR__ . '/includes/iti_doc.php';
require_once __DIR__ . '/includes/iti_mag_word.php';

$vendor = __DIR__ . '/../../vendor/autoload.php';
if (!is_file($vendor)) { ob_end_clean(); die('PhpWord is not installed (vendor/autoload.php missing).'); }
require_once $vendor;

$id = (int)($_GET['id'] ?? 0);
$program = $id ? iti_get_program($id) : false;
if (!$program) { ob_end_clean(); http_response_code(404); die('Program not found.'); }
$lang = $_GET['lang'] ?? $program['display_language'] ?? 'it';
if (!in_array($lang, ITI_LANGUAGES, true)) $lang = 'it';

@set_time_limit(300);
$D = iti_doc_data($id, $lang);
$name = preg_replace('/[^A-Za-z0-9]+/', '_', trim($D['title'])) ?: 'Programme';
iti_mag_word_send($D, trim($name, '_') . '_' . strtoupper($lang) . '.docx');
