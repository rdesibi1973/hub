<?php
/**
 * Pure-logic checks for the ITI T&C variant, placeholder texts and the get_rates search.
 * No DB, no server: php tools/tests/iti_terms_test.php   (exit code 1 on a failure)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../modules/iti/includes/iti_terms.php';
require_once __DIR__ . '/../../modules/leads/includes/calc_search.php';

$fail = 0;
$t = function (string $name, bool $ok) use (&$fail) { echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$ok) $fail++; };

// Direct client = the request folder's "(Agent-Drct)"; anything else is an agency.
$t('folder Drct',            iti_terms_variant_from_folder('ManuelaMiglior(Roberto-Drct)') === 'direct');
$t('confirmed folder Drct',  iti_terms_variant_from_folder('06_15MAR_LauraB(Roberto-Drct)_START15MAR_END22MAR2027_DEPOSIT') === 'direct');
$t('folder drct lower case', iti_terms_variant_from_folder('X(Micky-drct)') === 'direct');
$t('folder agency',          iti_terms_variant_from_folder('Gobbi3pax(Panorama-Roberto)') === 'agency');
$t('folder PS agency',       iti_terms_variant_from_folder('Famiglia4pax(Tuscialand-PS-Roberto)') === 'agency');
$t('folder SB',              iti_terms_variant_from_folder('Smith(Micky-SB)') === 'agency');
$t('no folder',              iti_terms_variant_from_folder('') === null);
$t('no brackets',            iti_terms_variant_from_folder('Something') === null);

// Standard T&C versions by name.
$t('name STANDARD DIRECT',   iti_terms_variant_of_name('STANDARD DIRECT') === 'direct');
$t('name STANDARD AGENTS',   iti_terms_variant_of_name('STANDARD AGENTS') === 'agency');
$t('name Agenzie 2027',      iti_terms_variant_of_name('Condizioni agenzie 2027') === 'agency');
$t('name Clienti diretti',   iti_terms_variant_of_name('Clienti diretti') === 'direct');
$t('name other',             iti_terms_variant_of_name('Special Kili 2026') === null);

// Website placeholder copied into descriptions = missing.
$ph = 'Savannah Explorers - Tour Operator for Safari in Tanzania, Kilimanjaro Trekking and Beach Holidays in Zanzibar';
$t('placeholder',            iti_is_placeholder_text($ph));
$t('placeholder spaces',     iti_is_placeholder_text("  $ph \n"));
$t('real text',              !iti_is_placeholder_text('Il Lago Natron è un lago salato ai piedi dell\'Ol Doinyo Lengai.'));
$t('empty not placeholder',  !iti_is_placeholder_text(''));
$t('mentions name only',     !iti_is_placeholder_text('Savannah Explorers organizza safari nel Serengeti da 20 anni, con guide italiane.'));

// get_rates search: every word must match one of the columns.
list($sql, $args) = calc_search_where('Arusha  Zanzibar', ['route_name', 'origin']);
$t('two words → two groups', $sql === '((route_name LIKE ? OR origin LIKE ?) AND (route_name LIKE ? OR origin LIKE ?))'
                              && $args === ['%Arusha%', '%Arusha%', '%Zanzibar%', '%Zanzibar%']);
list($sql, $args) = calc_search_where('   ', ['name']);
$t('blank → no filter',      $sql === '' && $args === []);
list($sql, $args) = calc_search_where('50%_off', ['name']);
$t('LIKE wildcards escaped', $args === ['%50\\%\\_off%']);

echo $fail ? "$fail FAILED\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
