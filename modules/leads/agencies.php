<?php
require_once 'config.php';
$pageTitle = 'Agencies';
$db = db();
requireLogin();
if (isLeadsRestricted()) { header('Location: requests.php'); exit; }

$errors  = [];
$success = '';

// ── Handle POST actions ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ADD
    if ($action === 'add') {
        $nome  = trim($_POST['nome']       ?? '');
        $short = trim($_POST['short_name'] ?? '');
        $type  = $_POST['type']            ?? 'savannah'; 
        $attiva = 1;

        if ($nome === '') {
            $errors[] = 'Agency name is required.';
        } else {
            // 1. Se lo short è vuoto, lo generiamo dal nome[cite: 2]
            if ($short === '') {
                $short = preg_replace_callback(
                    '/(?:^|\s+|-)(\S)/',
                    fn($m) => strtoupper($m[1]),
                    strtolower($nome)
                );
                $short = preg_replace('/[\s-]+/', '', $short);
            }

            // 2. Applichiamo il suffisso scelto dal radio button[cite: 2]
            if ($type === 'promoservice') {
                $short .= '-PS';
            } elseif ($type === 'lamprati') {
                $short .= '-LAM';
            }

            try {
                $address = trim($_POST['address'] ?? '');
                $db->prepare('INSERT INTO agencies (nome, short_name, type, attiva, address) VALUES (?, ?, ?, ?, ?)')
                   ->execute([$nome, $short, $type, $attiva, $address ?: null]);
                $success = "Agency \"$nome\" added.";
            } catch (PDOException $e) {
                $errors[] = 'Duplicate name or database error.';
            }
        }
    }

    // EDIT
    if ($action === 'edit') {
        $id    = (int)$_POST['id'];
        $nome  = trim($_POST['nome']       ?? '');
        $short = trim($_POST['short_name'] ?? '');
        $type  = $_POST['type']            ?? 'savannah';
        $attiva = isset($_POST['attiva']) ? 1 : 0;
        if ($nome === '') {
            $errors[] = 'Agency name is required.';
        } else {
            // Strip any existing suffix before re-applying
            $short = preg_replace('/-PS$|-LAM$/', '', $short);
            if ($type === 'promoservice') {
                $short .= '-PS';
            } elseif ($type === 'lamprati') {
                $short .= '-LAM';
            }
            $address = trim($_POST['address'] ?? '');
            $db->prepare('UPDATE agencies SET nome=?, short_name=?, type=?, attiva=?, address=? WHERE id=?')
               ->execute([$nome, $short ?: null, $type, $attiva, $address ?: null, $id]);
            $success = "Agency updated.";
        }
    }

    // DELETE[cite: 2]
    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $db->prepare('DELETE FROM agencies WHERE id=?')->execute([$id]);
        $success = "Agency deleted.";
    }

    if (!$errors) {
        header('Location: agencies.php' . ($success ? '?msg=' . urlencode($success) : ''));
        exit;
    }
}

$msg = trim($_GET['msg'] ?? '');

// ── Load agencies ─────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$showInactive = isset($_GET['inactive']);
$where  = $showInactive ? '1=1' : 'attiva = 1';
$params = [];
if ($search !== '') {
    $where  .= ' AND (nome LIKE ? OR short_name LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$agencies = $db->prepare("SELECT * FROM agencies WHERE $where ORDER BY nome ASC");
$agencies->execute($params);
$agencies = $agencies->fetchAll();

include 'includes/header.php';
?>

<div class="page-header">
  <div style="display:flex;align-items:center;gap:12px;">
    <a href="<?= defined('BASE_URL') ? BASE_URL.'/hub.php' : '../../hub.php' ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;">&#8592; Hub</a>
    <div>
      <h2>Agencies</h2>
      <div class="sub"><?= count($agencies) ?> agenc<?= count($agencies)!==1?'ies':'y' ?></div>
    </div>
  </div>
</div>

<?php if ($msg): ?>
  <div class="flash flash-success"><?= h($msg) ?></div>
<?php endif; ?>
<?php if ($errors): ?>
  <div class="flash flash-error"><?= implode('<br>', array_map('h', $errors)) ?></div>
<?php endif; ?>

<!-- ADD FORM -->
<div class="form-card" style="margin-bottom:24px;">
  <form method="POST">
    <input type="hidden" name="action" value="add">
    <div class="form-section-title" style="margin-top:0">Add Agency</div>
    
    <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr auto;align-items:end;gap:12px;">
      <div class="form-group" style="margin:0">
        <label>Agency Name *</label>
        <input type="text" name="nome" placeholder="e.g. BTG" required>
      </div>
      <div class="form-group" style="margin:0">
        <label>Short Name <span style="font-weight:400;color:var(--grey-mid)">(suffix will be added)</span></label>
        <input type="text" name="short_name" placeholder="e.g. BTG">
      </div>
      <div class="form-group" style="margin:0">
        <label>Address</label>
        <textarea name="address" placeholder="e.g. Via Roma 1, Milan" rows="5" style="width:100%;padding:7px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;font-size:.85rem;font-family:inherit;resize:vertical;"></textarea>
      </div>
      <button type="submit" class="btn btn-red" style="height:38px;white-space:nowrap;">+ Add</button>
    </div>

    <!-- RADIO BUTTONS[cite: 2] -->
    <div style="margin-top:15px; display:flex; gap:20px; align-items:center;">
      <span style="font-size:.85rem; font-weight:600; color:var(--grey-dark);">Add suffix:</span>
      <label style="font-size:.85rem; cursor:pointer; display:flex; align-items:center; gap:5px;"><input type="radio" name="type" value="savannah" checked> Savannah (none)</label>
      <label style="font-size:.85rem; cursor:pointer; display:flex; align-items:center; gap:5px;"><input type="radio" name="type" value="promoservice"> Promoservice (-PS)</label>
      <label style="font-size:.85rem; cursor:pointer; display:flex; align-items:center; gap:5px;"><input type="radio" name="type" value="lamprati"> Lamprati (-LAM)</label>
    </div>
  </form>
</div>

<!-- SEARCH + FILTER[cite: 2] -->
<form method="GET" style="display:flex;gap:10px;align-items:center;margin-bottom:16px;">
  <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search agencies..." style="width:260px;padding:7px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;font-size:.85rem;">
  <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;color:var(--grey-mid);cursor:pointer;">
    <input type="checkbox" name="inactive" <?= $showInactive?'checked':'' ?> onchange="this.form.submit()">
    Show inactive
  </label>
  <button type="submit" class="btn btn-outline btn-sm">Search</button>
  <?php if ($search): ?>
    <a href="agencies.php" class="btn btn-outline btn-sm">✕ Clear</a>
  <?php endif; ?>
</form>

<!-- TABLE -->
<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Agency Name</th>
        <th>Short Name</th>
        <th style="text-align:center">Active</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($agencies as $ag): ?>
      <tr id="row-<?= $ag['id'] ?>">
        <td class="view-<?= $ag['id'] ?>" style="font-weight:600"><?= h($ag['nome']) ?></td>
        <td class="view-<?= $ag['id'] ?>" style="font-size:.8rem;color:var(--grey-mid)"><?= h($ag['short_name'] ?? '—') ?></td>
        <td class="view-<?= $ag['id'] ?>" style="text-align:center">
          <?= $ag['attiva'] ? '<span style="color:#1A6B3A">✓</span>' : '<span style="color:#C0211B">✗</span>' ?>
        </td>
        
        <td class="edit-<?= $ag['id'] ?>" style="display:none" colspan="3">
          <?php $agType = $ag['type'] ?? 'savannah'; ?>
          <form method="POST" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?= $ag['id'] ?>">
            <input type="text" name="nome" value="<?= h($ag['nome']) ?>" required style="width:160px;padding:5px 8px;border:1.5px solid var(--grey-lt);border-radius:5px;font-size:.85rem;">
            <input type="text" name="short_name" value="<?= h($ag['short_name'] ?? '') ?>" style="width:120px;padding:5px 8px;border:1.5px solid var(--grey-lt);border-radius:5px;font-size:.85rem;" placeholder="Short name">
            <textarea name="address" rows="5" style="width:260px;padding:5px 8px;border:1.5px solid var(--grey-lt);border-radius:5px;font-size:.85rem;font-family:inherit;resize:vertical;" placeholder="Address"><?= h($ag['address'] ?? '') ?></textarea>
            <label style="display:flex;align-items:center;gap:4px;font-size:.82rem;cursor:pointer;white-space:nowrap;"><input type="radio" name="type" value="savannah" <?= $agType==='savannah'?'checked':'' ?>> Savannah</label>
            <label style="display:flex;align-items:center;gap:4px;font-size:.82rem;cursor:pointer;white-space:nowrap;"><input type="radio" name="type" value="promoservice" <?= $agType==='promoservice'?'checked':'' ?>> Promo (-PS)</label>
            <label style="display:flex;align-items:center;gap:4px;font-size:.82rem;cursor:pointer;white-space:nowrap;"><input type="radio" name="type" value="lamprati" <?= $agType==='lamprati'?'checked':'' ?>> Lamprati (-LAM)</label>
            <label style="display:flex;align-items:center;gap:4px;font-size:.82rem;white-space:nowrap;">
              <input type="checkbox" name="attiva" <?= $ag['attiva']?'checked':'' ?>> Active
            </label>
            <button type="submit" class="btn btn-red btn-sm">Save</button>
            <button type="button" class="btn btn-outline btn-sm" onclick="toggleEdit(<?= $ag['id'] ?>, false)">Cancel</button>
          </form>
        </td>

        <td class="actions-<?= $ag['id'] ?>">
          <div style="display:flex; gap:8px;">
            <button class="btn btn-outline btn-sm" onclick="toggleEdit(<?= $ag['id'] ?>, true)">Edit</button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete agency?')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $ag['id'] ?>">
              <button type="submit" class="btn btn-outline btn-sm" style="color:#C0211B; border-color:#C0211B;">Delete</button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
function toggleEdit(id, show) {
  ['view-','edit-','actions-'].forEach(function(cls){
    var els = document.querySelectorAll('.' + cls + id);
    els.forEach(function(el){
      if (cls === 'edit-')    el.style.display = show ? 'table-cell' : 'none';
      else                   el.style.display = show ? 'none' : 'flex';
    });
  });
}
</script>
<?php include 'includes/footer.php'; ?>