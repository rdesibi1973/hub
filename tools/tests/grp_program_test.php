<?php
/**
 * Group programme in GRP folder names (modules/leads/includes/grp_program_service.php).
 * Run: C:/Utility/PHP/php.exe tools/tests/grp_program_test.php
 */
require_once __DIR__ . '/../../modules/leads/includes/grp_program_service.php';

$fail = 0; $n = 0;
function t($label, $got, $want) {
    global $fail, $n; $n++;
    if ($got !== $want) { $fail++; echo "FAIL $label\n  got:  " . var_export($got, true) . "\n  want: " . var_export($want, true) . "\n"; }
}

$tail = '(Elkebir-Roberto)_START02SEP_MIDT08SEP_END09SEP2027_PROGRESS';
$norm = '09_02SEP_AdolfoPiazza_GRP0209Nyumbu' . $tail;

// ── Normalise ────────────────────────────────────────────────────────────────
t('already right', bo_grp_normalise($norm)['name'], $norm);
t('already right normal', bo_grp_normalise($norm)['normal'], true);
foreach (['GRP0209_Nyumbu', 'GRP0209-nyumbu', 'GRP0209 NYUMBU', 'NyumbuGRP0209', 'Nyumbu_GRP0209', 'GRP-Nyumbu-0209',
          'GRP0209NyumbuNyumbu', 'grp0209nyumbu'] as $v) {
    t("variant $v", bo_grp_normalise('09_02SEP_AdolfoPiazza_' . $v . $tail)['name'], $norm);
}
t('in customer part', bo_grp_normalise('09_02SEP_AdolfoPiazzaNyumbu_GRP0209' . $tail)['name'], $norm);
t('orphan NyumbuGRP after paren', bo_grp_normalise('X_GRP0209Nyumbu(A-B)_NyumbuGRP')['name'], 'X_GRP0209Nyumbu(A-B)');
t('own departure unchanged', bo_grp_normalise('08_21AUG_GRP2108Nyumbu_(Savannah-Roberto)_START21AUG_END30AUG2027_PROGRESS')['name'],
  '08_21AUG_GRP2108Nyumbu_(Savannah-Roberto)_START21AUG_END30AUG2027_PROGRESS');
t('own departure normal', bo_grp_normalise('08_21AUG_GRP2108Nyumbu_(Savannah-Roberto)_START21AUG_END30AUG2027_PROGRESS')['normal'], true);
t('legacy no programme', bo_grp_normalise('09_02SEP_AdolfoPiazza_GRP0209' . $tail)['program'], null);
t('legacy no programme name', bo_grp_normalise('09_02SEP_AdolfoPiazza_GRP0209' . $tail)['name'], '09_02SEP_AdolfoPiazza_GRP0209' . $tail);
t('legacy + programme given', bo_grp_normalise('09_02SEP_AdolfoPiazza_GRP0209' . $tail, '0209', 'Nyumbu')['name'], $norm);
t('two programmes conflict', bo_grp_normalise('X_SimbaGRP0209Nyumbu(A-B)')['conflict'] !== '', true);
t('given vs name conflict', bo_grp_normalise('X_GRP0209Simba(A-B)', '0209', 'Nyumbu')['conflict'] !== '', true);
t('given vs name forced', bo_grp_normalise('X_GRP0209Simba(A-B)', '0209', 'Nyumbu', true)['name'], 'X_GRP0209Nyumbu(A-B)');
t('DumaShort not Duma', bo_grp_programs_in('X_DumaShort_GRP0209(A-B)'), []);
t('DUMASHORT not Duma', bo_grp_programs_in('X_DUMASHORT_GRP0209(A-B)'), []);
t('Duma found', bo_grp_programs_in('X_GRP0209Duma(A-B)'), ['Duma']);
t('inside word not matched', bo_grp_programs_in('Simbazi_Dumas_nyumbucamp_GRP0209(A-B)'), []);
t('agency part ignored', bo_grp_programs_in('X_GRP0209(Simba-Roberto)'), []);
t('code found with programme before', bo_grp_normalise('09_02SEP_AdolfoPiazza_NyumbuGRP0209' . $tail)['code'], '0209');

// ── Insert (CREATE) ──────────────────────────────────────────────────────────
t('insert plain', bo_grp_insert('X(Agency-Agent)', '0209', 'Nyumbu'), 'X_GRP0209Nyumbu(Agency-Agent)');
t('insert word in name', bo_grp_insert('XNyumbu(Agency-Agent)', '0209', 'Nyumbu'), 'X_GRP0209Nyumbu(Agency-Agent)');
t('insert word sep in name', bo_grp_insert('X_Nyumbu(Agency-Agent)', '0209', 'Nyumbu'), 'X_GRP0209Nyumbu(Agency-Agent)');
t('insert code already there', bo_grp_insert('X_GRP0209_nyumbu(Agency-Agent)', '0209', 'Nyumbu'), 'X_GRP0209Nyumbu(Agency-Agent)');
t('insert no programme', bo_grp_insert('X(Agency-Agent)', '0209', null), 'X_GRP0209(Agency-Agent)');
t('insert NyumbuGRP request name', bo_grp_insert('X_NyumbuGRP(Agency-Agent)', '0209', 'Nyumbu'), 'X_GRP0209Nyumbu(Agency-Agent)');
t('insert conflict keeps one code', substr_count(bo_grp_insert('X_GRP0209Simba_Nyumbu(A-B)', '0209', null), 'GRP0209'), 1);
t('insert AdolfoPiazza', bo_grp_insert('AdolfoPiazza(Elkebir-Roberto)', '0209', 'Nyumbu'), 'AdolfoPiazza_GRP0209Nyumbu(Elkebir-Roberto)');

// ── Calc ─────────────────────────────────────────────────────────────────────
t('calc Nyumbu', bo_grp_calc_programs(['01_X(Agency-Agent)_NyumbuGRP_Calc.xlsx', 'notes.docx'])['programs'], ['Nyumbu']);
t('calc DumaShort', bo_grp_calc_programs(['01_X(Agency-Agent)_DumaShort_Calc.xlsx'])['programs'], []);
t('calc private', bo_grp_calc_programs(['01_X(Agency-Agent)_Calc.xlsx'])['programs'], []);
t('calc DumaGRP', bo_grp_calc_programs(['02_X(A-B)_DumaGRP_Calc.xlsx'])['programs'], ['Duma']);

// ── Resolve ──────────────────────────────────────────────────────────────────
$r = bo_grp_resolve_program(null, [], ['Nyumbu']);
t('resolve calc', [$r['program'], $r['source']], ['Nyumbu', 'calc']);
$r = bo_grp_resolve_program(null, ['Simba'], ['Nyumbu']);
t('resolve folder vs calc conflict', $r['conflict'], 'Programme unclear: name says Simba, Calc says Nyumbu — choose one');
$r = bo_grp_resolve_program('Nyumbu', ['Simba'], []);
t('resolve field wins + note', [$r['program'], $r['source'], $r['note'] !== ''], ['Nyumbu', 'field', true]);
$r = bo_grp_resolve_program(null, [], []);
t('resolve none', [$r['program'], $r['conflict']], [null, '']);
$r = bo_grp_resolve_program(null, ['Nyumbu'], ['Nyumbu']);
t('resolve folder', [$r['program'], $r['source']], ['Nyumbu', 'folder']);

// ── Existing groups (ADD / CREATE) ───────────────────────────────────────────
$names = ['09_02SEP_A_GRP0209Simba(X-Y)_START02SEP_END09SEP2027_PROGRESS',
          '09_02SEP_B_NyumbuGRP0209(X-Y)_START02SEP_END09SEP2027_PROGRESS',
          '09_02SEP_C_GRP0209(X-Y)_START02SEP_END09SEP2027_PROGRESS',
          '09_03SEP_D_GRP0309Nyumbu(X-Y)_START03SEP_END10SEP2027_PROGRESS',
          '09_02SEP_PrivateClient(X-Y)_START02SEP_END09SEP2027_PROGRESS'];
$s = bo_grp_split($names, '0209', 'Nyumbu');
t('split same', $s['same'], [$names[1]]);
t('split match (same + legacy)', $s['match'], [$names[1], $names[2]]);
t('split other', $s['other'], [$names[0]]);
t('split suggested rename', $s['info'][$names[1]]['name'], '09_02SEP_B_GRP0209Nyumbu(X-Y)_START02SEP_END09SEP2027_PROGRESS');
$s = bo_grp_split($names, '0209', null);
t('split no programme = all of the code', count($s['match']), 3);

// ── Rename warning ───────────────────────────────────────────────────────────
t('rename warn ok', bo_grp_rename_warning($norm), '');
t('rename warn suggests', bo_grp_rename_warning('09_02SEP_AdolfoPiazza_NyumbuGRP0209' . $tail), 'Group name not in the normalised form — suggested: ' . $norm);
t('rename warn missing', strpos(bo_grp_rename_warning('09_02SEP_AdolfoPiazza_GRP0209' . $tail), 'no programme') !== false, true);
t('rename warn private', bo_grp_rename_warning('09_02SEP_AdolfoPiazza' . $tail), '');

echo $fail ? "$fail of $n FAILED\n" : "All $n passed\n";
exit($fail ? 1 : 0);
