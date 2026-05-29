<?php
/**
 * modules/iti/lodges.php
 * CRUD Lodge
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';

$db       = db();
$_cu      = current_user();
$can_edit = in_array($_cu['role_name'], ['admin', 'manager']);

$action = $_REQUEST['action'] ?? '';
$id     = (int)($_REQUEST['id'] ?? 0);

// ── POST ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $fields = [
        'destination_id' => (int)($_POST['destination_id'] ?? 0),
        'name'           => trim($_POST['name']      ?? ''),
        'category'       => $_POST['category']       ?? 'mid',
        'lodge_type'     => $_POST['lodge_type']     ?? 'lodge',
        'website'        => trim($_POST['website']   ?? ''),
        'is_active'      => isset($_POST['is_active']) ? 1 : 0,
    ];
    foreach (ITI_LANGS as $lang) {
        $fields["description_{$lang}"] = trim($_POST["description_{$lang}"] ?? '');
    }

    if ($fields['name'] === '' || !$fields['destination_id']) {
        iti_flash_set('error', 'Name and Destination are required.');
        iti_redirect('lodges.php' . ($id ? "?action=edit&id={$id}" : '?action=add'));
    }

    if ($action === 'add') {
        $db->prepare(
            'INSERT INTO iti_lodges
             (destination_id,name,category,lodge_type,
              description_en,description_it,description_fr,description_es,description_de,
              website,is_active)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $fields['destination_id'], $fields['name'], $fields['category'], $fields['lodge_type'],
            $fields['description_en'], $fields['description_it'], $fields['description_fr'],
            $fields['description_es'], $fields['description_de'],
            $fields['website'], $fields['is_active'],
        ]);
        iti_flash_set('success', '"' . $fields['name'] . '" created.');
        iti_redirect('lodges.php');

    } elseif ($action === 'edit' && $id) {
        $db->prepare(
            'UPDATE iti_lodges SET
             destination_id=?,name=?,category=?,lodge_type=?,
             description_en=?,description_it=?,description_fr=?,description_es=?,description_de=?,
             website=?,is_active=? WHERE id=?'
        )->execute([
            $fields['destination_id'], $fields['name'], $fields['category'], $fields['lodge_type'],
            $fields['description_en'], $fields['description_it'], $fields['description_fr'],
            $fields['description_es'], $fields['description_de'],
            $fields['website'], $fields['is_active'], $id,
        ]);
        iti_flash_set('success', 'Lodge updated.');
        iti_redirect('lodges.php');

    } elseif ($action === 'delete' && $id) {
        $db->prepare('UPDATE iti_lodges SET is_active=0 WHERE id=?')->execute([$id]);
        iti_flash_set('success', 'Lodge deactivated.');
        iti_redirect('lodges.php');
    }
}

// ── Carica row per edit ─────────────────────────────────────
$row = null;
if (in_array($action, ['edit','view']) && $id) {
    $row = iti_get_lodge($id);
    if (!$row) { iti_flash_set('error', 'Lodge not found.'); iti_redirect('lodges.php'); }
}

// ── Lista ───────────────────────────────────────────────────
$filter_dest = (int)($_GET['destination_id'] ?? 0);
$filter_cat  = $_GET['category'] ?? '';
$filter_act  = $_GET['active']   ?? '';
$search      = trim($_GET['q']   ?? '');

$where  = ['1=1']; $params = [];
if ($search)      { $where[]='(l.name LIKE ? OR d.name_en LIKE ?)'; $params[]="%$search%"; $params[]="%$search%"; }
if ($filter_dest) { $where[]='l.destination_id=?'; $params[]=$filter_dest; }
if ($filter_cat)  { $where[]='l.category=?';       $params[]=$filter_cat; }
if ($filter_act!==''){ $where[]='l.is_active=?';   $params[]=(int)$filter_act; }

$stmt = $db->prepare(
    'SELECT l.*, d.name_en AS dest_name_en, d.region
       FROM iti_lodges l
       LEFT JOIN iti_destinations d ON d.id = l.destination_id
      WHERE '.implode(' AND ',$where).'
      ORDER BY d.sort_order, d.name_en, l.name'
);
$stmt->execute($params);
$lodges = $stmt->fetchAll();

$destinations_map = iti_destinations_map();

$page_title = 'Lodges — Itinerary Builder';
$extra_css = iti_extra_css();
include __DIR__ . '/../../includes/layout_header.php';
?>
<main>
<?php iti_nav($action==='add'?'New Lodge':($action==='edit'?'Edit Lodge':'Lodges')); ?>
<?php iti_flash_render(); ?>

<?php if ($action === 'add' || ($action === 'edit' && $row)): ?>

<div class="page-header">
  <div>
    <h2><?= $action==='add' ? 'New Lodge' : 'Edit: '.h($row['name']) ?></h2>
    <div class="sub">Master Data › Lodges</div>
  </div>
  <a href="lodges.php" class="btn btn-outline btn-sm">← Back</a>
</div>

<div class="form-card">
<form method="POST" action="lodges.php?action=<?= h($action) ?><?= $id?"&id={$id}":'' ?>">

  <div class="form-section-title">Lodge Details</div>
  <div class="form-grid">
    <div class="form-group full">
      <label>Name <span style="color:var(--red)">*</span></label>
      <input type="text" name="name" maxlength="160" required value="<?= h($row['name'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Destination <span style="color:var(--red)">*</span></label>
      <select name="destination_id" required>
        <?= iti_options($destinations_map, $row['destination_id'] ?? ($filter_dest ?: null), '— Select destination —') ?>
      </select>
    </div>
    <div class="form-group">
      <label>Category</label>
      <select name="category">
        <?= iti_options(ITI_LODGE_CATEGORIES, $row['category'] ?? 'mid') ?>
      </select>
    </div>
    <div class="form-group">
      <label>Type</label>
      <select name="lodge_type">
        <?= iti_options(ITI_LODGE_TYPES, $row['lodge_type'] ?? 'lodge') ?>
      </select>
    </div>
    <div class="form-group">
      <label>Website</label>
      <input type="url" name="website" placeholder="https://…" value="<?= h($row['website'] ?? '') ?>">
    </div>
  </div>

  <div class="form-section-title">Description <span style="font-weight:400;font-size:.8rem;color:var(--grey-mid)">× 5 languages</span></div>
  <?php foreach (ITI_LANGS as $lang): ?>
  <div class="form-group" style="margin-bottom:16px;">
    <label><?= ITI_LANG_LABELS[$lang] ?></label>
    <textarea name="description_<?= $lang ?>" class="tall"><?= h($row["description_{$lang}"] ?? '') ?></textarea>
  </div>
  <?php endforeach; ?>

  <div class="form-section-title">Settings</div>
  <div class="form-group" style="flex-direction:row;align-items:center;gap:10px;">
    <input type="checkbox" name="is_active" value="1" id="is_active"
           <?= ($row['is_active'] ?? 1)?'checked':'' ?>
           style="width:16px;height:16px;accent-color:var(--red);cursor:pointer;">
    <label for="is_active" style="margin:0;text-transform:none;font-size:.85rem;cursor:pointer;">Active</label>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-red"><?= $action==='add'?'+ Create Lodge':'💾 Save' ?></button>
    <a href="lodges.php" class="btn btn-outline">Cancel</a>
    <?php if ($action==='edit' && $can_edit): ?>
    <div style="margin-left:auto;">
      <form method="POST" action="lodges.php?action=delete&id=<?= $id ?>" style="display:inline;">
        <button class="btn btn-danger btn-sm" onclick="return confirm('Deactivate this lodge?')">Deactivate</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</form>
</div>

<?php else: ?>

<div class="page-header">
  <div>
    <h2>Lodges <?php if ($filter_dest && isset($destinations_map[$filter_dest])): ?><span style="font-size:.9rem;font-weight:400;color:var(--grey-mid)">— <?= h($destinations_map[$filter_dest]) ?></span><?php endif; ?></h2>
    <div class="sub"><?= count($lodges) ?> lodge<?= count($lodges)!==1?'s':'' ?></div>
  </div>
  <?php if ($can_edit): ?>
  <div style="display:flex;gap:8px;">
    <a href="iti_import_lodges_web.php" class="btn btn-outline btn-sm">🌐 Import from Web</a>
    <a href="lodges.php?action=add<?= $filter_dest?"&destination_id={$filter_dest}":'' ?>" class="btn btn-red">+ New Lodge</a>
  </div>
  <?php endif; ?>
</div>

<form method="GET" action="lodges.php" class="filters">
  <div><label>Search</label><input type="text" name="q" placeholder="Lodge or destination…" value="<?= h($search) ?>"></div>
  <div>
    <label>Destination</label>
    <select name="destination_id">
      <option value="">All destinations</option>
      <?= iti_options($destinations_map, $filter_dest ?: null) ?>
    </select>
  </div>
  <div>
    <label>Category</label>
    <select name="category">
      <option value="">All categories</option>
      <?= iti_options(ITI_LODGE_CATEGORIES, $filter_cat ?: null) ?>
    </select>
  </div>
  <div>
    <label>Status</label>
    <select name="active">
      <option value="">All</option>
      <option value="1" <?= $filter_act==='1'?'selected':'' ?>>Active</option>
      <option value="0" <?= $filter_act==='0'?'selected':'' ?>>Inactive</option>
    </select>
  </div>
  <div style="display:flex;gap:8px;align-items:flex-end;">
    <button type="submit" class="btn btn-outline btn-sm">🔍 Filter</button>
    <?php if ($search||$filter_dest||$filter_cat||$filter_act!==''): ?><a href="lodges.php" class="btn btn-outline btn-sm">✕ Clear</a><?php endif; ?>
  </div>
</form>

<div class="table-wrap">
  <table>
    <thead>
      <tr><th>Lodge</th><th>Destination</th><th>Category</th><th>Type</th><th>Status</th><th></th></tr>
    </thead>
    <tbody>
    <?php if ($lodges): ?>
      <?php foreach ($lodges as $l): ?>
      <tr>
        <td>
          <div style="font-weight:600;"><?= h($l['name']) ?></div>
          <?php if ($l['website']): ?><div style="font-size:.7rem;"><a href="<?= h($l['website']) ?>" target="_blank" style="color:var(--blue);">🔗 website</a></div><?php endif; ?>
        </td>
        <td>
          <div style="font-size:.83rem;"><?= h($l['dest_name_en']) ?></div>
          <?php if ($l['region']): ?><div style="font-size:.7rem;color:var(--grey-mid);"><?= h($l['region']) ?></div><?php endif; ?>
        </td>
        <td><span class="badge <?= match($l['category']){ 'budget'=>'status-inquiry','mid'=>'status-quoted','luxury'=>'status-hot','ultra_luxury'=>'status-booked',default=>'' } ?>"><?= ITI_LODGE_CATEGORIES[$l['category']] ?? h($l['category']) ?></span></td>
        <td style="font-size:.82rem;color:var(--grey-dk);"><?= ITI_LODGE_TYPES[$l['lodge_type']] ?? h($l['lodge_type']) ?></td>
        <td><span class="badge <?= $l['is_active']?'status-booked':'status-cancelled' ?>"><?= $l['is_active']?'Active':'Inactive' ?></span></td>
        <td>
          <?php if ($can_edit): ?>
          <a href="lodges.php?action=edit&id=<?= $l['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php else: ?>
      <tr><td colspan="6"><div class="empty-state"><div class="icon">🏕️</div><p>No lodges found<?= ($search||$filter_dest||$filter_cat||$filter_act!=='')?' for the selected filters.':' yet.' ?></p><?php if($can_edit): ?><p style="margin-top:12px;"><a href="lodges.php?action=add" class="btn btn-red btn-sm">+ Add first lodge</a></p><?php endif; ?></div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
</main>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
