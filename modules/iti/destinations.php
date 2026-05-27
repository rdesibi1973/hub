<?php
/**
 * modules/iti/destinations.php
 * CRUD Destinazioni
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
    $fields = [];
    foreach (ITI_LANGS as $lang) {
        $fields["name_{$lang}"]        = trim($_POST["name_{$lang}"]        ?? '');
        $fields["description_{$lang}"] = trim($_POST["description_{$lang}"] ?? '');
    }
    $fields['code']        = strtoupper(trim($_POST['code']       ?? ''));
    $fields['region']      = trim($_POST['region']     ?? '');
    $fields['country']     = trim($_POST['country']    ?? 'Tanzania');
    $fields['latitude']    = $_POST['latitude']  !== '' ? (float)$_POST['latitude']  : null;
    $fields['longitude']   = $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;
    $fields['cover_photo'] = trim($_POST['cover_photo'] ?? '');
    $fields['sort_order']  = (int)($_POST['sort_order'] ?? 0);
    $fields['is_active']   = isset($_POST['is_active']) ? 1 : 0;

    if ($fields['name_en'] === '' || $fields['code'] === '') {
        iti_flash_set('error', 'Name (EN) and Code are required.');
        iti_redirect('destinations.php' . ($id ? "?action=edit&id={$id}" : '?action=add'));
    }

    if ($action === 'add') {
        $db->prepare(
            'INSERT INTO iti_destinations
             (code,name_en,name_it,name_fr,name_es,name_de,
              description_en,description_it,description_fr,description_es,description_de,
              region,country,latitude,longitude,cover_photo,sort_order,is_active)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute(array_values($fields));
        iti_flash_set('success', 'Destination "' . $fields['name_en'] . '" created.');
        iti_redirect('destinations.php');

    } elseif ($action === 'edit' && $id) {
        $db->prepare(
            'UPDATE iti_destinations SET
             code=?,name_en=?,name_it=?,name_fr=?,name_es=?,name_de=?,
             description_en=?,description_it=?,description_fr=?,description_es=?,description_de=?,
             region=?,country=?,latitude=?,longitude=?,cover_photo=?,sort_order=?,is_active=?
             WHERE id=?'
        )->execute([...array_values($fields), $id]);
        iti_flash_set('success', 'Destination updated.');
        iti_redirect('destinations.php');

    } elseif ($action === 'delete' && $id) {
        $db->prepare('UPDATE iti_destinations SET is_active=0 WHERE id=?')->execute([$id]);
        iti_flash_set('success', 'Destination deactivated.');
        iti_redirect('destinations.php');
    }
}

// ── Carica row per edit ─────────────────────────────────────
$row = null;
if (in_array($action, ['edit','view']) && $id) {
    $row = iti_get_destination($id);
    if (!$row) { iti_flash_set('error', 'Destination not found.'); iti_redirect('destinations.php'); }
}

// ── Lista ───────────────────────────────────────────────────
$search     = trim($_GET['q']      ?? '');
$filter_act = $_GET['active']      ?? '';
$filter_reg = trim($_GET['region'] ?? '');
$where  = ['1=1']; $params = [];
if ($search) {
    $where[]='(code LIKE ? OR name_en LIKE ? OR name_it LIKE ? OR region LIKE ?)';
    $params=[...array_fill(0,4,"%$search%")];
}
if ($filter_act !== '') { $where[]='is_active=?'; $params[]=(int)$filter_act; }
if ($filter_reg !== '') { $where[]='region=?';    $params[]=$filter_reg; }

$stmt = $db->prepare('SELECT * FROM iti_destinations WHERE '.implode(' AND ',$where).' ORDER BY sort_order,name_en');
$stmt->execute($params);
$destinations = $stmt->fetchAll();

$regions = $db->query("SELECT DISTINCT region FROM iti_destinations WHERE region IS NOT NULL AND region<>'' ORDER BY region")->fetchAll(PDO::FETCH_COLUMN);

$lodge_counts = [];
foreach ($db->query('SELECT destination_id, COUNT(*) n FROM iti_lodges WHERE is_active=1 GROUP BY destination_id')->fetchAll() as $l) {
    $lodge_counts[$l['destination_id']] = (int)$l['n'];
}

$page_title = 'Destinations — Itinerary Builder';
$extra_css = iti_extra_css();
include __DIR__ . '/../../includes/layout_header.php';
?>
<main>
<?php iti_nav($action === 'add' ? 'New Destination' : ($action === 'edit' ? 'Edit Destination' : 'Destinations')); ?>
<?php iti_flash_render(); ?>

<?php if ($action === 'add' || ($action === 'edit' && $row)): ?>

<div class="page-header">
  <div>
    <h2><?= $action === 'add' ? 'New Destination' : 'Edit: ' . h($row['name_en']) ?></h2>
    <div class="sub">Master Data › Destinations</div>
  </div>
  <a href="destinations.php" class="btn btn-outline btn-sm">← Back</a>
</div>

<div class="form-card">
<form method="POST" action="destinations.php?action=<?= h($action) ?><?= $id ? "&id={$id}" : '' ?>">

  <div class="form-section-title">Identification</div>
  <div class="form-grid">
    <div class="form-group">
      <label>Code <span style="color:var(--red)">*</span></label>
      <input type="text" name="code" maxlength="20" required
             style="text-transform:uppercase;font-family:monospace;"
             placeholder="SNP, TRP, NCA…"
             value="<?= h($row['code'] ?? '') ?>">
      <span class="form-hint">Short uppercase code</span>
    </div>
    <div class="form-group">
      <label>Country</label>
      <input type="text" name="country" maxlength="60" value="<?= h($row['country'] ?? 'Tanzania') ?>">
    </div>
    <div class="form-group">
      <label>Region</label>
      <input type="text" name="region" maxlength="80" placeholder="Northern Circuit, Zanzibar…" value="<?= h($row['region'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Sort Order</label>
      <input type="number" name="sort_order" min="0" max="999" value="<?= (int)($row['sort_order'] ?? 0) ?>">
      <span class="form-hint">Lower = first</span>
    </div>
  </div>

  <div class="form-section-title">Coordinates &amp; Media</div>
  <div class="form-grid">
    <div class="form-group">
      <label>Latitude</label>
      <input type="number" name="latitude" step="0.000001" placeholder="-3.065053" value="<?= $row['latitude'] ?? '' ?>">
    </div>
    <div class="form-group">
      <label>Longitude</label>
      <input type="number" name="longitude" step="0.000001" placeholder="35.735683" value="<?= $row['longitude'] ?? '' ?>">
    </div>
    <div class="form-group full">
      <label>Cover Photo URL</label>
      <input type="url" name="cover_photo" placeholder="https://…" value="<?= h($row['cover_photo'] ?? '') ?>">
    </div>
  </div>

  <div class="form-section-title">Name <span style="font-weight:400;font-size:.8rem;color:var(--grey-mid)">× 5 languages</span></div>
  <div class="form-grid">
    <?php foreach (ITI_LANGS as $lang): ?>
    <div class="form-group">
      <label><?= ITI_LANG_LABELS[$lang] ?><?= $lang==='en' ? ' <span style="color:var(--red)">*</span>' : '' ?></label>
      <input type="text" name="name_<?= $lang ?>" maxlength="120"
             <?= $lang==='en' ? 'required' : '' ?>
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

  <div class="form-section-title">Settings</div>
  <div class="form-group" style="flex-direction:row;align-items:center;gap:10px;">
    <input type="checkbox" name="is_active" value="1" id="is_active"
           <?= ($row['is_active'] ?? 1) ? 'checked' : '' ?>
           style="width:16px;height:16px;accent-color:var(--red);cursor:pointer;">
    <label for="is_active" style="margin:0;text-transform:none;font-size:.85rem;cursor:pointer;">Active</label>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-red"><?= $action==='add' ? '+ Create' : '💾 Save' ?></button>
    <a href="destinations.php" class="btn btn-outline">Cancel</a>
    <?php if ($action==='edit' && $can_edit): ?>
    <div style="margin-left:auto;">
      <form method="POST" action="destinations.php?action=delete&id=<?= $id ?>" style="display:inline;">
        <button class="btn btn-danger btn-sm" onclick="return confirm('Deactivate this destination?')">Deactivate</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</form>
</div>

<?php else: ?>

<div class="page-header">
  <div>
    <h2>Destinations</h2>
    <div class="sub"><?= count($destinations) ?> destination<?= count($destinations)!==1?'s':'' ?></div>
  </div>
  <?php if ($can_edit): ?>
  <a href="destinations.php?action=add" class="btn btn-red">+ New Destination</a>
  <?php endif; ?>
</div>

<form method="GET" action="destinations.php" class="filters">
  <div><label>Search</label><input type="text" name="q" placeholder="Name, code, region…" value="<?= h($search) ?>"></div>
  <div>
    <label>Region</label>
    <select name="region">
      <option value="">All regions</option>
      <?php foreach ($regions as $reg): ?>
      <option value="<?= h($reg) ?>" <?= $filter_reg===$reg?'selected':'' ?>><?= h($reg) ?></option>
      <?php endforeach; ?>
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
    <?php if ($search||$filter_act!==''||$filter_reg): ?><a href="destinations.php" class="btn btn-outline btn-sm">✕ Clear</a><?php endif; ?>
  </div>
</form>

<div class="table-wrap">
  <table>
    <thead>
      <tr><th>Code</th><th>Name</th><th>Region</th><th>Country</th><th>Lodges</th><th>Sort</th><th>Status</th><th></th></tr>
    </thead>
    <tbody>
    <?php if ($destinations): ?>
      <?php foreach ($destinations as $d): ?>
      <tr>
        <td><span style="font-family:monospace;font-weight:700;background:var(--off-white);padding:2px 8px;border-radius:4px;font-size:.8rem;"><?= h($d['code']) ?></span></td>
        <td>
          <div style="font-weight:600;"><?= h($d['name_en']) ?></div>
          <?php if ($d['name_it'] && $d['name_it']!==$d['name_en']): ?><div style="font-size:.72rem;color:var(--grey-mid);"><?= h($d['name_it']) ?></div><?php endif; ?>
        </td>
        <td class="text-muted"><?= h($d['region'] ?? '—') ?></td>
        <td class="text-muted"><?= h($d['country']) ?></td>
        <td><?php $nc=$lodge_counts[$d['id']]??0; ?>
          <?php if ($nc): ?><a href="lodges.php?destination_id=<?= $d['id'] ?>" style="color:var(--blue);text-decoration:none;font-size:.82rem;font-weight:600;"><?= $nc ?> lodge<?= $nc!==1?'s':'' ?></a><?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td class="text-muted" style="text-align:center;"><?= $d['sort_order'] ?></td>
        <td><span class="badge <?= $d['is_active']?'status-booked':'status-cancelled' ?>"><?= $d['is_active']?'Active':'Inactive' ?></span></td>
        <td>
          <div class="gap-8">
            <?php if ($can_edit): ?><a href="destinations.php?action=edit&id=<?= $d['id'] ?>" class="btn btn-outline btn-sm">Edit</a><?php endif; ?>
            <a href="lodges.php?destination_id=<?= $d['id'] ?>" class="btn btn-outline btn-sm">Lodges</a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php else: ?>
      <tr><td colspan="8"><div class="empty-state"><div class="icon">🗺️</div><p>No destinations found<?= ($search||$filter_act!==''||$filter_reg)?' for the selected filters.':' yet.' ?></p><?php if($can_edit&&!$search): ?><p style="margin-top:12px;"><a href="destinations.php?action=add" class="btn btn-red btn-sm">+ Add first destination</a></p><?php endif; ?></div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
</main>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
