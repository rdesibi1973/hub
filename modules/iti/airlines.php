<?php
/**
 * airlines.php  — ITI Airlines master data
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';

$db       = db();
$_cu      = current_user();
$can_edit = in_array($_cu['role_name'], ['admin', 'manager']);
$action   = $_GET['action'] ?? '';
$id       = (int)($_GET['id'] ?? 0);

// ── Check table ───────────────────────────────────────────────────────────────
$table_ok = false;
try { $db->query("SELECT 1 FROM iti_airlines LIMIT 1"); $table_ok = true; } catch (Exception $e) {}

// ── POST save ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit && $table_ok) {
    $f = [
        'name'      => trim($_POST['name'] ?? ''),
        'iata_code' => strtoupper(trim($_POST['iata_code'] ?? '')) ?: null,
        'type'      => $_POST['type'] ?? 'international',
        'country'   => trim($_POST['country'] ?? 'Tanzania'),
        'website'   => trim($_POST['website'] ?? '') ?: null,
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];
    if ($f['name']) {
        if ($_POST['_action'] === 'add') {
            $db->prepare('INSERT INTO iti_airlines (name,iata_code,type,country,website,is_active) VALUES (?,?,?,?,?,?)')->execute(array_values($f));
            iti_flash_set('success', '"'.$f['name'].'" added.');
        } elseif ($_POST['_action'] === 'edit' && $id) {
            $db->prepare('UPDATE iti_airlines SET name=?,iata_code=?,type=?,country=?,website=?,is_active=? WHERE id=?')->execute([...(array_values($f)), $id]);
            iti_flash_set('success', 'Airline updated.');
        }
    }
    iti_redirect('airlines.php');
}
if ($action === 'delete' && $id && $can_edit && $table_ok) {
    $db->prepare('UPDATE iti_airlines SET is_active=0 WHERE id=?')->execute([$id]);
    iti_flash_set('success', 'Airline deactivated.');
    iti_redirect('airlines.php');
}

// ── Load for edit ─────────────────────────────────────────────────────────────
$row = null;
if ($action === 'edit' && $id && $table_ok)
    $row = $db->prepare('SELECT * FROM iti_airlines WHERE id=?')->execute([$id]) ? $db->prepare('SELECT * FROM iti_airlines WHERE id=?') : null;
if ($action === 'edit' && $id && $table_ok) {
    $st = $db->prepare('SELECT * FROM iti_airlines WHERE id=?');
    $st->execute([$id]);
    $row = $st->fetch();
}

// ── Load list ─────────────────────────────────────────────────────────────────
$airlines = [];
if ($table_ok) {
    $airlines = $db->query("SELECT * FROM iti_airlines ORDER BY type, name")->fetchAll();
}

$page_title = 'Airlines';
$extra_css  = iti_extra_css() . '
.airline-table{width:100%;border-collapse:collapse;font-size:.8rem;}
.airline-table th{background:#f0f0ef;padding:8px 12px;text-align:left;font-size:.71rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:var(--grey-mid);border-bottom:1.5px solid var(--grey-lt);}
.airline-table td{padding:9px 12px;border-bottom:1px solid var(--grey-lt);vertical-align:middle;}
.airline-table tr:hover td{background:#fafafa;}
.badge-dom{display:inline-block;padding:2px 8px;border-radius:4px;background:#dcfce7;color:#166534;font-size:.68rem;font-weight:700;}
.badge-reg{display:inline-block;padding:2px 8px;border-radius:4px;background:#dbeafe;color:#1e40af;font-size:.68rem;font-weight:700;}
.badge-int{display:inline-block;padding:2px 8px;border-radius:4px;background:#f3e8ff;color:#7e22ce;font-size:.68rem;font-weight:700;}
.badge-off{display:inline-block;padding:2px 8px;border-radius:4px;background:#f1f1f1;color:#888;font-size:.68rem;}
';
include __DIR__ . '/../../includes/layout_header.php';
?>
<main>
<?php iti_nav('Airlines'); ?>
<?php iti_flash_render(); ?>

<?php if (!$table_ok): ?>
<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:16px 20px;">
  <strong>⚠ Table <code>iti_airlines</code> not found.</strong>
  Run the CREATE TABLE SQL shown in the <a href="iti_import_airlines.php">Import Airlines</a> script, then reload.
</div>

<?php elseif ($action === 'add' || ($action === 'edit' && $row)): ?>
<!-- ── FORM ── -->
<div class="page-header">
  <div><h2><?= $action==='add' ? 'New Airline' : 'Edit: '.h($row['name']) ?></h2></div>
  <a href="airlines.php" class="btn btn-outline btn-sm">← Cancel</a>
</div>
<form method="POST" action="airlines.php<?= $id ? "?id={$id}" : '' ?>">
  <input type="hidden" name="_action" value="<?= $action==='add'?'add':'edit' ?>">
  <div class="form-card">
    <div class="form-section-title">Airline Details</div>
    <div class="form-grid" style="grid-template-columns:1fr 100px 1fr;">
      <div class="form-group">
        <label>Name <span style="color:var(--red)">*</span></label>
        <input type="text" name="name" value="<?= h($row['name'] ?? '') ?>" required maxlength="120" placeholder="e.g. Coastal Aviation">
      </div>
      <div class="form-group">
        <label>IATA Code</label>
        <input type="text" name="iata_code" value="<?= h($row['iata_code'] ?? '') ?>" maxlength="3" style="text-transform:uppercase;" placeholder="CQ">
      </div>
      <div class="form-group">
        <label>Country</label>
        <input type="text" name="country" value="<?= h($row['country'] ?? 'Tanzania') ?>" maxlength="80">
      </div>
    </div>
    <div class="form-grid" style="grid-template-columns:1fr 1fr;">
      <div class="form-group">
        <label>Type</label>
        <select name="type">
          <?php foreach (['domestic'=>'Domestic (Tanzania)','regional'=>'Regional (East Africa)','international'=>'International'] as $tv=>$tl): ?>
          <option value="<?= $tv ?>" <?= ($row['type']??'international')===$tv?'selected':'' ?>><?= $tl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Website</label>
        <input type="url" name="website" value="<?= h($row['website'] ?? '') ?>" placeholder="https://...">
      </div>
    </div>
    <div class="form-group">
      <label class="check-label">
        <input type="checkbox" name="is_active" value="1" <?= ($row['is_active']??1)?'checked':'' ?>>
        Active
      </label>
    </div>
    <div style="margin-top:16px;">
      <button type="submit" class="btn btn-red">💾 Save</button>
    </div>
  </div>
</form>

<?php else: ?>
<!-- ── LIST ── -->
<div class="page-header">
  <div>
    <h2>✈️ Airlines</h2>
    <div class="sub"><?= count($airlines) ?> airlines</div>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="iti_import_airlines.php" class="btn btn-outline btn-sm">⬆ Import</a>
    <?php if ($can_edit): ?><a href="airlines.php?action=add" class="btn btn-red">+ New Airline</a><?php endif; ?>
  </div>
</div>

<div class="form-card" style="padding:0;overflow:hidden;">
  <table class="airline-table">
    <thead>
      <tr>
        <th>Name</th>
        <th style="width:60px;">IATA</th>
        <th style="width:130px;">Type</th>
        <th>Country</th>
        <th>Website</th>
        <th style="width:80px;"></th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$airlines): ?>
    <tr><td colspan="6" style="text-align:center;color:var(--grey-mid);padding:32px;">No airlines yet — <a href="iti_import_airlines.php">import from the standard list</a>.</td></tr>
    <?php endif; ?>
    <?php foreach ($airlines as $a):
      $badge = match($a['type']) { 'domestic'=>'dom', 'regional'=>'reg', default=>'int' };
      $label = match($a['type']) { 'domestic'=>'Domestic', 'regional'=>'Regional', default=>'International' };
    ?>
    <tr style="<?= $a['is_active'] ? '' : 'opacity:.5' ?>">
      <td>
        <strong><?= h($a['name']) ?></strong>
        <?php if (!$a['is_active']): ?><span class="badge-off">Inactive</span><?php endif; ?>
      </td>
      <td style="font-family:monospace;font-weight:700;color:var(--grey-dk);"><?= h($a['iata_code'] ?? '') ?></td>
      <td><span class="badge-<?= $badge ?>"><?= $label ?></span></td>
      <td style="color:var(--grey-mid);"><?= h($a['country']) ?></td>
      <td style="font-size:.75rem;"><?= $a['website'] ? '<a href="'.h($a['website']).'" target="_blank" rel="noopener">'.h($a['website']).'</a>' : '' ?></td>
      <td style="white-space:nowrap;">
        <?php if ($can_edit): ?>
        <a href="airlines.php?action=edit&id=<?= $a['id'] ?>" style="font-size:.75rem;color:var(--green);text-decoration:none;margin-right:8px;">Edit</a>
        <a href="airlines.php?action=delete&id=<?= $a['id'] ?>" style="font-size:.75rem;color:var(--red);text-decoration:none;"
           onclick="return confirm('Deactivate <?= h(addslashes($a['name'])) ?>?')">✕</a>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
</main>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
