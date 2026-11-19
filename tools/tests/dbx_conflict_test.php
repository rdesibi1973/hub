<?php
/**
 * Dropbox conflicted copies — sides of the changed cells, hint, dates (handoff 19 Nov 2026, conflict 303 / request 3195).
 * Pure checks, no Dropbox / PhpSpreadsheet: the cell maps below are those of the real pair (base revision 14 Nov 13:53,
 * original saved 17 Nov 14:50, copy saved 17 Nov 12:31), cut to the cells that matter.
 *   C:/Utility/PHP/php.exe -d extension_dir=C:/Utility/PHP/ext -d extension=mbstring tools/tests/dbx_conflict_test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../modules/leads/includes/dbx_conflict_service.php';

$fail = 0;
$t = function (string $name, bool $ok, $got = null) use (&$fail) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $got === null ? '' : '  got: ' . json_encode($got, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "\n";
    if (!$ok) $fail++;
};

// ── The real pair, cut down ──
$S = '2PAX Fabrizio Florio!';
$P = '1PAX Flavio Marioni!';
$sheets = ['RECAP', '2PAX Fabrizio Florio', '1PAX Flavio Marioni'];
$textDates = ['A17' => 'Tuesday, December 22, 2026', 'A19' => 'Thursday, December 22, 2026', 'A20' => 'Friday, December 22, 2026',
              'A21' => 'Saturday, December 22, 2026', 'A22' => 'Sunday, December 22, 2026'];
$base = ['sheets' => $sheets, 'cells' => [$S . 'A16' => 's:DATE', $S . 'A18' => 'n:46379', $S . 'K23' => 's:Flight to ZNZ',
         $P . 'G11' => 's:saldo unico (partenza entro 45 gg)', $P . 'A17' => 'n:46377'], 'dates' => [$S . 'A18' => true, $P . 'A17' => true]];
foreach ($textDates as $c => $s) $base['cells'][$S . $c] = 's:' . $s;
ksort($base['cells']);
$copy = $base;
unset($copy['cells'][$S . 'K23']);                                  // the copy empties K23
$orig = $base;
foreach (['A17' => 46378, 'A19' => 46380, 'A20' => 46381, 'A21' => 46382, 'A22' => 46383] as $c => $n) {
    $orig['cells'][$S . $c] = 'n:' . $n;                             // real dates again in the original
    $orig['dates'][$S . $c] = true;
}
$orig['cells'][$P . 'G11'] = 's:saldo unico - BONIFICO 4485 USD DEL 17/11/2026 (VALUTA 19/11) IN ARRIVO - POP IN CARTELLA';
ksort($orig['cells']);

$dec = dbxc_decide($base, $orig, $copy, 'xlsx', true);
$t('outcome conflict, reason cleared', $dec['outcome'] === 'conflict' && $dec['reason'] === 'cleared', $dec);
$t('copy side = K23 only (n_cells stays 1)', $dec['keys'] === [$S . 'K23'], $dec['keys']);
$expOrig = [$P . 'G11', $S . 'A17', $S . 'A19', $S . 'A20', $S . 'A21', $S . 'A22'];
$t('original side = A17, A19:A22, G11', $dec['original'] === $expOrig, $dec['original']);
$t('A18 (same date in all three) not listed', !in_array($S . 'A18', array_merge($dec['keys'], $dec['original']), true));

$rows = dbxc_cell_rows($dec['keys'], $base, $orig, $copy, $dec['both'], $dec['original']);
$by = array_column($rows, null, 'cell');
$t('K23 side copy', ($by[$S . 'K23']['side'] ?? null) === 'copy' && $by[$S . 'K23']['copy'] === '(empty)', $by[$S . 'K23'] ?? null);
$t('A17 side original', ($by[$S . 'A17']['side'] ?? null) === 'original');
$t('G11 side original', ($by[$P . 'G11']['side'] ?? null) === 'original');
$t('copy rows first, then original rows', array_column($rows, 'side') === ['copy', 'original', 'original', 'original', 'original', 'original', 'original'], array_column($rows, 'side'));
$t('date cell shown as 2026-12-22, not the serial', $by[$S . 'A17']['original'] === '2026-12-22', $by[$S . 'A17']['original']);
$t('A19 original → 2026-12-24', $by[$S . 'A19']['original'] === '2026-12-24', $by[$S . 'A19']['original']);
$t('text date flagged in base and copy, not in the original', $by[$S . 'A20']['text_date'] === ['base', 'copy'], $by[$S . 'A20']['text_date']);
$t('payment note with a date inside is not a text date', $by[$P . 'G11']['text_date'] === [], $by[$P . 'G11']['text_date']);

$detail = ['base_saved' => '2026-11-14 13:53', 'original_saved' => '2026-11-17 14:50:32', 'n_cells' => count($dec['keys']),
           'n_cells_original' => count($dec['original']), 'reason' => $dec['reason'], 'cells' => $rows];
$hint = (string)dbxc_hint($detail, '2026-11-17 12:31:46');
$t('hint: original newer 2h19, 6 cells, copy outdated',
   strpos($hint, 'Original is newer (saved 2h19 later) and also changed 6 cells → the copy is probably outdated') === 0, $hint);
$t('hint: text dates counted', strpos($hint, '⚠ 5 cells with a date stored as text') !== false, $hint);

// Copy newer, or copy-side changes that are not only clears.
$h2 = (string)dbxc_hint(['original_saved' => '2026-11-17 10:00:00', 'n_cells' => 1, 'n_cells_original' => 0,
                         'cells' => [['cell' => 'X!A1', 'side' => 'copy', 'copy' => '5', 'text_date' => []]]], '2026-11-17 12:31:00');
$t('hint: copy newer', $h2 === 'Copy is newer (saved 2h31 later)', $h2);
$h3 = (string)dbxc_hint(['original_saved' => '2026-11-18 12:00:00', 'n_cells' => 1, 'n_cells_original' => 2,
                         'cells' => [['cell' => 'X!A1', 'side' => 'copy', 'copy' => '5', 'text_date' => []]]], '2026-11-17 12:00:00');
$t('hint: original newer, copy adds values → check', $h3 === 'Original is newer (saved 24h00 later) and also changed 2 cells → check each change of the copy against it', $h3);
$t('hint: old rows without original_saved → null', dbxc_hint(['n_cells' => 1, 'cells' => []], '2026-11-17 12:00:00') === null);

// Cap: 40 per side.
$a = ['sheets' => ['S'], 'cells' => [], 'dates' => []];
$o = $a; $c = $a;
for ($i = 1; $i <= 50; $i++) { $o['cells']['S!A' . $i] = 'n:' . $i; $c['cells']['S!B' . $i] = 'n:' . $i; }
$d2 = dbxc_decide($a, $o, $c, 'xlsx');
$r2 = dbxc_cell_rows($d2['keys'], $a, $o, $c, $d2['both'], $d2['original']);
$cnt = array_count_values(array_column($r2, 'side'));
$t('40 cells per side at most', ($cnt['copy'] ?? 0) === DBXC_MAX_MERGE && ($cnt['original'] ?? 0) === DBXC_MAX_MERGE, $cnt);

// ── Helpers ──
$t('serial 46378 → 2026-12-22', dbxc_serial_date(46378) === '2026-12-22');
$t('serial with time', dbxc_serial_date(46378.5) === '2026-12-22 12:00');
$t('number without date format stays a number', dbxc_show('n:46378') === '46378');
foreach (['Thursday, December 22, 2026', 'December 22, 2026', '22-Dec-2026', '22/12/2026', '2026-12-22', 'martedì 22 dicembre 2026', '22 dic 2026'] as $s) {
    $t("text date: $s", dbxc_text_date('s:' . $s));
}
foreach (['Flight to ZNZ', 'saldo unico - BONIFICO 4485 USD DEL 17/11/2026', 'DATE', 'Serengeti', '22 pax', 'Dec 22 arrival JRO'] as $s) {
    $t("not a text date: $s", !dbxc_text_date('s:' . $s));
}
$t('a number is never a text date', !dbxc_text_date('n:46378'));
$t('gap 45 min / 2h19 / 3 days', dbxc_gap(2700) === '45 min' && dbxc_gap(8326) === '2h19' && dbxc_gap(3 * 86400) === '3 days');
$t('same content ignores date formats', dbxc_same(['sheets' => ['S'], 'cells' => ['S!A1' => 'n:1'], 'dates' => ['S!A1' => true]],
                                                   ['sheets' => ['S'], 'cells' => ['S!A1' => 'n:1']]));

echo $fail ? "\n$fail FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
