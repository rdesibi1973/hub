<?php
/**
 * iti_import_sto.php — import the STO sample programmes (Wetu Word exports in
 * Dropbox) as ITI SAMPLE programmes: days, day texts, transfers, activities,
 * meals, inclusions; fills empty lodge / destination descriptions.
 *
 * 1) list the .docx of the folder  2) Preview (read-only)  3) Import (selected).
 * Parser + import: includes/sto_import.php.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';
require_once __DIR__ . '/includes/sto_import.php';
require_once __DIR__ . '/../leads/dropbox_constants.php';
require_once __DIR__ . '/../leads/dropbox_helper.php';

$db  = db();
$_cu = current_user();
if (!in_array($_cu['role_name'] ?? '', ['admin', 'manager'], true)) {
    iti_flash_set('error', 'Access denied.');
    iti_redirect('programs.php');
}
@set_time_limit(300);

$folder = trim($_REQUEST['folder'] ?? '/itineraries/SafariClassic/it/Agenzia/2026-27/STO');
$lang   = in_array($_REQUEST['lang'] ?? 'it', ITI_LANGUAGES, true) ? ($_REQUEST['lang'] ?? 'it') : 'it';
$action = $_POST['action'] ?? '';
$picked = isset($_POST['files']) && is_array($_POST['files']) ? $_POST['files'] : [];

$files = []; $error = ''; $previews = []; $results = [];
try {
    $token = dropbox_get_access_token();
    foreach (dropbox_list_files($token, $folder) as $fn) {
        if (preg_match('/\.docx$/i', $fn) && strpos($fn, '~$') !== 0) $files[] = $fn;
    }
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

/** Download + parse one file of the folder. */
function sto_load(string $token, string $folder, string $fn): array {
    $bytes = dropbox_download_text($token, rtrim($folder, '/') . '/' . $fn);
    if ($bytes === null) throw new RuntimeException('File not found in Dropbox.');
    $tmp = tempnam(sys_get_temp_dir(), 'sto_');
    file_put_contents($tmp, $bytes);
    try { return sto_parse_docx($tmp); } finally { @unlink($tmp); }
}

if (!$error && in_array($action, ['preview', 'import'], true)) {
    if ($action === 'import') sto_ensure_schema($db);
    foreach ($picked as $fn) {
        if (!in_array($fn, $files, true)) continue;
        $ref = rtrim($folder, '/') . '/' . $fn;
        try {
            $prog = sto_load($token, $folder, $fn);
            if ($action === 'preview') {
                $previews[$fn] = sto_preview($db, $prog, $ref);
            } else {
                $results[$fn] = sto_import($db, $prog, $ref, $lang, !empty($_POST['replace']), $_cu['username'] ?? 'system');
            }
        } catch (Throwable $e) {
            if ($action === 'preview') $previews[$fn] = ['error' => $e->getMessage()];
            else                       $results[$fn]  = ['error' => $e->getMessage()];
        }
    }
}

// Which files were already imported (source_ref) → programme id.
$imported = [];
try {
    $st = $db->prepare('SELECT source_ref, id FROM iti_programs WHERE program_type = "sample" AND source_ref LIKE ?');
    $st->execute([rtrim($folder, '/') . '/%']);
    foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $ref => $pid) $imported[basename($ref)] = (int)$pid;
} catch (PDOException $e) { /* source_ref not created yet */ }

$pageTitle = 'Import STO programmes';
include __DIR__ . '/../../includes/layout_header.php';
?>
<style>
.sto-wrap{max-width:1100px}
.sto-files{columns:2;column-gap:28px;margin:10px 0 14px}
.sto-files label{display:block;font-size:.84rem;padding:3px 0;break-inside:avoid}
.sto-tag{display:inline-block;font-size:.68rem;padding:1px 6px;border-radius:4px;margin-left:6px;background:#E8F5E9;color:#1A6B3A}
.sto-card{background:#fff;border:1px solid var(--grey-lt);border-radius:10px;padding:14px 16px;margin-bottom:14px}
.sto-card h3{margin:0 0 4px;font-size:.98rem}
.sto-card table{width:100%;border-collapse:collapse;font-size:.8rem;margin-top:8px}
.sto-card th{text-align:left;font-size:.66rem;text-transform:uppercase;color:var(--grey-mid);border-bottom:1px solid var(--grey-lt);padding:4px 6px}
.sto-card td{padding:4px 6px;border-bottom:1px solid #f3f3f3;vertical-align:top}
.sto-ok{color:#1A6B3A}.sto-miss{color:#B26A00}.sto-err{color:#C0211B}
.sto-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
</style>
<div class="sto-wrap">
<?php iti_nav('Programs', [['label' => 'Programs', 'url' => ITI_MODULE_URL . '/programs.php']]); ?>
<?php iti_flash_render(); ?>
<h2 style="margin:0 0 6px">Import STO sample programmes</h2>
<p style="font-size:.84rem;color:var(--grey-mid);margin:0 0 14px">
  Reads the Wetu Word exports in Dropbox and creates <strong>Sample</strong> programmes (days, day texts, transfers,
  activities, meals, included / not included). Lodge and destination descriptions are filled <strong>only where empty</strong>.
  Prices are not imported — they come from the Calc Excel.
</p>

<form method="get" class="sto-bar" style="margin-bottom:12px">
  <label style="font-size:.8rem">Dropbox folder
    <input type="text" name="folder" value="<?= h($folder) ?>" style="width:460px">
  </label>
  <label style="font-size:.8rem">Language
    <select name="lang"><?php foreach (ITI_LANGUAGES as $l): ?><option value="<?= $l ?>" <?= $l === $lang ? 'selected' : '' ?>><?= strtoupper($l) ?></option><?php endforeach; ?></select>
  </label>
  <button class="btn btn-grey">Reload</button>
</form>

<?php if ($error): ?>
  <div class="flash flash-error">Dropbox: <?= h($error) ?></div>
<?php else: ?>
<form method="post">
  <input type="hidden" name="folder" value="<?= h($folder) ?>">
  <input type="hidden" name="lang" value="<?= h($lang) ?>">
  <div style="font-size:.8rem;color:var(--grey-mid)"><?= count($files) ?> Word files —
    <a href="#" onclick="document.querySelectorAll('.sto-cb').forEach(function(c){c.checked=true});return false">all</a> ·
    <a href="#" onclick="document.querySelectorAll('.sto-cb').forEach(function(c){c.checked=false});return false">none</a></div>
  <div class="sto-files">
    <?php foreach ($files as $fn): $on = !$picked || in_array($fn, $picked, true); ?>
      <label><input type="checkbox" class="sto-cb" name="files[]" value="<?= h($fn) ?>" <?= $on ? 'checked' : '' ?>>
        <?= h($fn) ?><?php if (isset($imported[$fn])): ?><a class="sto-tag" href="program_edit.php?id=<?= $imported[$fn] ?>">imported #<?= $imported[$fn] ?></a><?php endif; ?></label>
    <?php endforeach; ?>
  </div>
  <div class="sto-bar">
    <button class="btn btn-grey" name="action" value="preview">🔍 Preview (no changes)</button>
    <button class="btn btn-red" name="action" value="import" onclick="return confirm('Import the selected programmes as Sample programmes?')">⬇ Import selected</button>
    <label style="font-size:.8rem"><input type="checkbox" name="replace" value="1" <?= !empty($_POST['replace']) ? 'checked' : '' ?>> Replace programmes already imported</label>
  </div>
</form>
<?php endif; ?>

<?php foreach ($previews as $fn => $p): ?>
  <div class="sto-card">
    <?php if (!empty($p['error'])): ?>
      <h3><?= h($fn) ?></h3><div class="sto-err">✖ <?= h($p['error']) ?></div>
    <?php else: ?>
      <h3><?= h($p['title']) ?> <span style="font-weight:400;color:var(--grey-mid);font-size:.78rem">— <?= h($fn) ?></span></h3>
      <div style="font-size:.8rem"><?= h($p['route']) ?> · <?= count($p['days']) ?> days
        <?php if ($p['days_n'] && $p['days_n'] !== count($p['days'])): ?><span class="sto-err">(document says <?= (int)$p['days_n'] ?>)</span><?php endif; ?>
        · <?= (int)$p['include'] ?> included · <?= (int)$p['exclude'] ?> not included
        <?php if ($p['existing_id']): ?> · <span class="sto-miss">already imported as #<?= (int)$p['existing_id'] ?></span><?php endif; ?></div>
      <table>
        <tr><th>Day</th><th>Title</th><th>Overnight</th><th>Destination</th><th>Meals</th><th>Activities</th></tr>
        <?php foreach ($p['days'] as $d): ?>
          <tr>
            <td><?= (int)$d['n'] ?></td>
            <td><?= h($d['title']) ?></td>
            <td><?php if ($d['lodge']): ?><span class="<?= $d['lodge_id'] ? 'sto-ok' : 'sto-miss' ?>"><?= $d['lodge_id'] ? '✓' : '⚠' ?> <?= h($d['lodge']) ?></span><?php else: ?>—<?php endif; ?></td>
            <td><?php if ($d['place']): ?><span class="<?= $d['dest_id'] ? 'sto-ok' : 'sto-miss' ?>"><?= $d['dest_id'] ? '✓' : '⚠' ?> <?= h($d['place']) ?></span><?php else: ?>—<?php endif; ?></td>
            <td><?= h($d['meals']) ?></td>
            <td><?= h(implode(' · ', $d['activities'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <div style="font-size:.72rem;color:var(--grey-mid);margin-top:6px">✓ found in ITI · ⚠ not in ITI — imported as free text; add it in Lodges / Destinations to link it.</div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php foreach ($results as $fn => $r): ?>
  <div class="sto-card">
    <h3><?= h($fn) ?></h3>
    <?php if (!empty($r['error'])): ?>
      <div class="sto-err">✖ <?= h($r['error']) ?></div>
    <?php else: ?>
      <div class="sto-ok">✔ <?= $r['replaced'] ? 'Replaced' : 'Created' ?> sample programme
        <a href="program_edit.php?id=<?= (int)$r['program_id'] ?>">#<?= (int)$r['program_id'] ?></a></div>
      <?php if ($r['filled']): ?><div style="font-size:.78rem">Descriptions filled: <?= h(implode(', ', $r['filled'])) ?></div><?php endif; ?>
      <?php if ($r['unmatched']): ?><div class="sto-miss" style="font-size:.78rem">⚠ Not in ITI (kept as text): <?= h(implode(', ', $r['unmatched'])) ?></div><?php endif; ?>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
