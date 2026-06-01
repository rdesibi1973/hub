<?php
/**
 * modules/iti/activities.php
 * CRUD Attività
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
    $f = [
        'destination_id'  => ($_POST['destination_id'] !== '' ? (int)$_POST['destination_id'] : null),
        'activity_type'   => $_POST['activity_type']   ?? 'other',
        'duration_hours'  => $_POST['duration_hours']  !== '' ? (float)$_POST['duration_hours'] : null,
        'is_active'       => isset($_POST['is_active']) ? 1 : 0,
    ];
    foreach (ITI_LANGS as $lang) {
        $f["name_{$lang}"]        = trim($_POST["name_{$lang}"]        ?? '');
        $f["description_{$lang}"] = trim($_POST["description_{$lang}"] ?? '');
    }

    if ($f['name_en'] === '') {
        iti_flash_set('error', 'Name (EN) is required.');
        iti_redirect("activities.php?action={$action}" . ($id ? "&id={$id}" : ''));
    }

    if ($action === 'add') {
        $db->prepare(
            'INSERT INTO iti_activities
             (destination_id,activity_type,name_en,name_it,name_fr,name_es,name_de,
              description_en,description_it,description_fr,description_es,description_de,
              duration_hours,is_active)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $f['destination_id'], $f['activity_type'],
            $f['name_en'],$f['name_it'],$f['name_fr'],$f['name_es'],$f['name_de'],
            $f['description_en'],$f['description_it'],$f['description_fr'],$f['description_es'],$f['description_de'],
            $f['duration_hours'], $f['is_active'],
        ]);
        iti_flash_set('success', '"'.$f['name_en'].'" created.');
        iti_redirect('activities.php');

    } elseif ($action === 'edit' && $id) {
        $db->prepare(
            'UPDATE iti_activities SET
             destination_id=?,activity_type=?,name_en=?,name_it=?,name_fr=?,name_es=?,name_de=?,
             description_en=?,description_it=?,description_fr=?,description_es=?,description_de=?,
             duration_hours=?,is_active=? WHERE id=?'
        )->execute([
            $f['destination_id'], $f['activity_type'],
            $f['name_en'],$f['name_it'],$f['name_fr'],$f['name_es'],$f['name_de'],
            $f['description_en'],$f['description_it'],$f['description_fr'],$f['description_es'],$f['description_de'],
            $f['duration_hours'], $f['is_active'], $id,
        ]);
        iti_flash_set('success', 'Activity updated.');
        iti_redirect('activities.php');

    } elseif ($action === 'delete' && $id) {
        $db->prepare('UPDATE iti_activities SET is_active=0 WHERE id=?')->execute([$id]);
        iti_flash_set('success', 'Activity deactivated.');
        iti_redirect('activities.php');
    }
}

// ── Carica row per edit ─────────────────────────────────────
$row = null;
if (in_array($action, ['edit','view']) && $id) {
    $row = iti_get_activity($id);
    if (!$row) { iti_flash_set('error', 'Activity not found.'); iti_redirect('activities.php'); }
}

// ── Lista ───────────────────────────────────────────────────
$filter_dest = (int)($_GET['destination_id'] ?? 0);
$filter_type = $_GET['activity_type'] ?? '';
$filter_act  = $_GET['active'] ?? '';
$search      = trim($_GET['q'] ?? '');

$where  = ['1=1']; $params = [];
if ($search)       { $where[]='(a.name_en LIKE ? OR a.name_it LIKE ?)'; $params[]="%$search%"; $params[]="%$search%"; }
if ($filter_dest)  { $where[]='a.destination_id=?'; $params[]=$filter_dest; }
if ($filter_type)  { $where[]='a.activity_type=?';  $params[]=$filter_type; }
if ($filter_act!==''){ $where[]='a.is_active=?';    $params[]=(int)$filter_act; }

$stmt = $db->prepare(
    'SELECT a.*, d.name_en AS dest_name_en
       FROM iti_activities a
       LEFT JOIN iti_destinations d ON d.id = a.destination_id
      WHERE '.implode(' AND ',$where).'
      ORDER BY a.destination_id IS NULL, d.sort_order, a.activity_type, a.name_en'
);
$stmt->execute($params);
$activities = $stmt->fetchAll();

$dest_map = ['' => 'Generic (all destinations)'] + iti_destinations_map();

$page_title = 'Activities — Itinerary Builder';
$extra_css = iti_extra_css();
include __DIR__ . '/../../includes/layout_header.php';
?>
<main>
<?php iti_nav($action==='add'?'New Activity':($action==='edit'?'Edit Activity':'Activities')); ?>
<?php iti_flash_render(); ?>

<?php if ($action === 'add' || ($action === 'edit' && $row)): ?>

<div class="page-header">
  <div>
    <h2><?= $action==='add'?'New Activity':'Edit: '.h($row['name_en']) ?></h2>
    <div class="sub">Master Data › Activities</div>
  </div>
  <a href="activities.php" class="btn btn-outline btn-sm">← Back</a>
</div>

<div class="form-card">
<form method="POST" action="activities.php?action=<?= h($action) ?><?= $id?"&id={$id}":'' ?>">

  <div class="form-section-title">Activity Details</div>
  <div class="form-grid">
    <div class="form-group">
      <label>Destination</label>
      <select name="destination_id">
        <option value="">Generic (valid for all destinations)</option>
        <?= iti_options(iti_destinations_map(), $row['destination_id'] ?? ($filter_dest ?: null)) ?>
      </select>
      <span class="form-hint">Leave empty for activities valid everywhere</span>
    </div>
    <div class="form-group">
      <label>Activity Type</label>
      <select name="activity_type">
        <?= iti_options(ITI_ACTIVITY_TYPES, $row['activity_type'] ?? 'other') ?>
      </select>
    </div>
    <div class="form-group">
      <label>Duration (hours)</label>
      <input type="number" name="duration_hours" step="0.5" min="0" max="24"
             placeholder="3.5" value="<?= $row['duration_hours'] ?? '' ?>">
    </div>
    <div class="form-group" style="flex-direction:row;align-items:center;gap:10px;align-self:flex-end;">
      <input type="checkbox" name="is_active" value="1" id="is_active"
             <?= ($row['is_active'] ?? 1)?'checked':'' ?>
             style="width:16px;height:16px;accent-color:var(--red);">
      <label for="is_active" style="margin:0;text-transform:none;font-size:.85rem;">Active</label>
    </div>
  </div>

  <div class="form-section-title">Name <span style="font-weight:400;font-size:.8rem;color:var(--grey-mid)">× 5 languages</span></div>
  <div class="form-grid">
    <?php foreach (ITI_LANGS as $lang): ?>
    <div class="form-group">
      <label><?= ITI_LANG_LABELS[$lang] ?><?= $lang==='en'?' <span style="color:var(--red)">*</span>':'' ?></label>
      <input type="text" name="name_<?= $lang ?>" maxlength="160"
             <?= $lang==='en'?'required':'' ?>
             value="<?= h($row["name_{$lang}"] ?? '') ?>">
    </div>
    <?php endforeach; ?>
  </div>

  <div class="form-section-title">Description <span style="font-weight:400;font-size:.8rem;color:var(--grey-mid)">× 5 languages</span></div>
  <?php foreach (ITI_LANGS as $lang): ?>
  <div class="form-group" style="margin-bottom:16px;">
    <label><?= ITI_LANG_LABELS[$lang] ?></label>
    <textarea name="description_<?= $lang ?>" class="tall"><?= h($row["description_{$lang}"] ?? '') ?></textarea>
  </div>
  <?php endforeach; ?>

  <div class="form-actions">
    <button type="submit" class="btn btn-red"><?= $action==='add'?'+ Create Activity':'💾 Save' ?></button>
    <a href="activities.php" class="btn btn-outline">Cancel</a>
    <?php if ($action==='edit' && $can_edit): ?>
    <div style="margin-left:auto;">
      <form method="POST" action="activities.php?action=delete&id=<?= $id ?>" style="display:inline;">
        <button class="btn btn-danger btn-sm" onclick="return confirm('Deactivate this activity?')">Deactivate</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</form>
</div>

<?php else: ?>

<div class="page-header">
  <div><h2>Activities</h2><div class="sub"><?= count($activities) ?> activit<?= count($activities)!==1?'ies':'y' ?></div></div>
  <?php if ($can_edit): ?>
  <div style="display:flex;gap:8px;">
    <a href="iti_import_activities.php" class="btn btn-outline btn-sm">⬆ Import Standard</a>
    <a href="activities.php?action=add" class="btn btn-red">+ New Activity</a>
  </div>
  <?php endif; ?>
</div>

<form method="GET" action="activities.php" class="filters">
  <div class="filter-search"><label>Search</label><input type="text" name="q" placeholder="Activity name…" value="<?= h($search) ?>"></div>
  <div>
    <label>Destination</label>
    <select name="destination_id">
      <option value="">All</option>
      <?= iti_options(iti_destinations_map(), $filter_dest ?: null) ?>
    </select>
  </div>
  <div>
    <label>Type</label>
    <select name="activity_type">
      <option value="">All types</option>
      <?= iti_options(ITI_ACTIVITY_TYPES, $filter_type ?: null) ?>
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
    <?php if ($search||$filter_dest||$filter_type||$filter_act!==''): ?><a href="activities.php" class="btn btn-outline btn-sm">✕ Clear</a><?php endif; ?>
  </div>
</form>

<div class="table-wrap">
  <table>
    <thead><tr><th>Activity</th><th>Destination</th><th>Type</th><th>Duration</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if ($activities): ?>
      <?php foreach ($activities as $a): ?>
      <tr>
        <td>
          <div style="font-weight:600;"><?= ITI_ACTIVITY_ICONS[$a['activity_type']] ?? '⭐' ?> <?= h($a['name_en']) ?></div>
          <?php if ($a['name_it'] && $a['name_it']!==$a['name_en']): ?><div style="font-size:.7rem;color:var(--grey-mid);"><?= h($a['name_it']) ?></div><?php endif; ?>
        </td>
        <td class="text-muted"><?= $a['dest_name_en'] ? h($a['dest_name_en']) : '<em>Generic</em>' ?></td>
        <td><span class="badge status-inquiry"><?= ITI_ACTIVITY_TYPES[$a['activity_type']] ?? h($a['activity_type']) ?></span></td>
        <td class="text-muted"><?= $a['duration_hours'] ? $a['duration_hours'].' h' : '—' ?></td>
        <td><span class="badge <?= $a['is_active']?'status-booked':'status-cancelled' ?>"><?= $a['is_active']?'Active':'Inactive' ?></span></td>
        <td><?php if ($can_edit): ?><a href="activities.php?action=edit&id=<?= $a['id'] ?>" class="btn btn-outline btn-sm">Edit</a><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    <?php else: ?>
      <tr><td colspan="6"><div class="empty-state"><div class="icon">🦁</div><p>No activities found<?= ($search||$filter_dest||$filter_type||$filter_act!=='')?' for the selected filters.':' yet.' ?></p></div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
</main>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
