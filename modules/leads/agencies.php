<?php
require_once 'config.php';
$pageTitle = 'Agencies';
$db = db();
requireLogin();
if (isLeadsRestricted()) { header('Location: requests.php'); exit; }

$errors  = [];
$success = '';

// Build a wa.me number (digits only) from a free-text phone, or null.
function wa_number($phone) {
    $digits = preg_replace('/[^0-9]/', '', (string)$phone);
    return $digits !== '' ? $digits : null;
}

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
                $email   = trim($_POST['email'] ?? '');
                $db->prepare('INSERT INTO agencies (nome, short_name, type, attiva, address, email) VALUES (?, ?, ?, ?, ?, ?)')
                   ->execute([$nome, $short, $type, $attiva, $address ?: null, $email ?: null]);
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
            $email   = trim($_POST['email'] ?? '');
            $db->prepare('UPDATE agencies SET nome=?, short_name=?, type=?, attiva=?, address=?, email=? WHERE id=?')
               ->execute([$nome, $short ?: null, $type, $attiva, $address ?: null, $email ?: null, $id]);
            $success = "Agency updated.";
        }
    }

    // DELETE[cite: 2]
    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $db->prepare('DELETE FROM agencies WHERE id=?')->execute([$id]);
        $db->prepare('DELETE FROM agency_contacts WHERE agency_id=?')->execute([$id]);
        $success = "Agency deleted.";
    }

    // CONTACT ADD / EDIT
    if ($action === 'contact_add' || $action === 'contact_edit') {
        $agencyId = (int)($_POST['agency_id'] ?? 0);
        $cname    = trim($_POST['c_name']  ?? '');
        $crole    = trim($_POST['c_role']  ?? '');
        $cemail   = trim($_POST['c_email'] ?? '');
        $cphone   = trim($_POST['c_phone'] ?? '');
        $cnotes   = trim($_POST['c_notes'] ?? '');
        $cprimary = isset($_POST['c_primary']) ? 1 : 0;

        if (!$agencyId || $cname === '') {
            $errors[] = 'Contact name is required.';
        } else {
            // Only one primary contact per agency.
            if ($cprimary) {
                $db->prepare('UPDATE agency_contacts SET is_primary=0 WHERE agency_id=?')->execute([$agencyId]);
            }
            if ($action === 'contact_add') {
                $db->prepare('INSERT INTO agency_contacts (agency_id, name, role, email, phone, is_primary, notes) VALUES (?, ?, ?, ?, ?, ?, ?)')
                   ->execute([$agencyId, $cname, $crole ?: null, $cemail ?: null, $cphone ?: null, $cprimary, $cnotes ?: null]);
                $success = "Contact added.";
            } else {
                $cid = (int)($_POST['contact_id'] ?? 0);
                $db->prepare('UPDATE agency_contacts SET name=?, role=?, email=?, phone=?, is_primary=?, notes=? WHERE id=? AND agency_id=?')
                   ->execute([$cname, $crole ?: null, $cemail ?: null, $cphone ?: null, $cprimary, $cnotes ?: null, $cid, $agencyId]);
                $success = "Contact updated.";
            }
        }
    }

    // CONTACT DELETE
    if ($action === 'contact_delete') {
        $cid = (int)($_POST['contact_id'] ?? 0);
        $db->prepare('DELETE FROM agency_contacts WHERE id=?')->execute([$cid]);
        $success = "Contact deleted.";
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

// ── Load contacts, grouped by agency ──────────────────────────────
$contactsByAgency = [];
$agencyIds = array_column($agencies, 'id');
if ($agencyIds) {
    try {
        $in = implode(',', array_fill(0, count($agencyIds), '?'));
        $cstmt = $db->prepare("SELECT * FROM agency_contacts WHERE agency_id IN ($in) ORDER BY is_primary DESC, name ASC");
        $cstmt->execute($agencyIds);
        foreach ($cstmt->fetchAll() as $c) {
            $contactsByAgency[$c['agency_id']][] = $c;
        }
    } catch (PDOException $e) {
        // agency_contacts table not migrated yet — degrade gracefully.
    }
}

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
    
    <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr 1fr auto;align-items:end;gap:12px;">
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
      <div class="form-group" style="margin:0">
        <label>Email <span style="font-weight:400;color:var(--grey-mid)">(comma-separated)</span></label>
        <textarea name="email" placeholder="e.g. info@agency.com, booking@agency.com" rows="5" style="width:100%;padding:7px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;font-size:.85rem;font-family:inherit;resize:vertical;"></textarea>
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
        <th>Email</th>
        <th>Contacts</th>
        <th style="text-align:center">Active</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($agencies as $ag): ?>
      <tr id="row-<?= $ag['id'] ?>">
        <td class="view-<?= $ag['id'] ?>" style="font-weight:600"><?= h($ag['nome']) ?></td>
        <td class="view-<?= $ag['id'] ?>" style="font-size:.8rem;color:var(--grey-mid)"><?= h($ag['short_name'] ?? '—') ?></td>
        <td class="view-<?= $ag['id'] ?>" style="font-size:.8rem;color:var(--grey-mid)"><?= h($ag['email'] ?? '') ?: '—' ?></td>
        <td class="view-<?= $ag['id'] ?>" style="font-size:.8rem;">
          <?php $cs = $contactsByAgency[$ag['id']] ?? []; ?>
          <?php if (!$cs): ?>
            <span style="color:var(--grey-mid)">—</span>
          <?php else: foreach ($cs as $c): $wa = wa_number($c['phone']); ?>
            <div style="margin-bottom:4px;line-height:1.35;">
              <span style="font-weight:600;"><?= h($c['name']) ?></span>
              <?php if ($c['is_primary']): ?><span title="Primary contact" style="color:#C9A227;">&#9733;</span><?php endif; ?>
              <?php if (!empty($c['role'])): ?><span style="color:var(--grey-mid);"> &middot; <?= h($c['role']) ?></span><?php endif; ?>
              <?php if ($wa): ?>
                <a href="https://wa.me/<?= h($wa) ?>" target="_blank" rel="noopener" title="WhatsApp <?= h($c['phone']) ?>" style="color:#1A6B3A;text-decoration:none;margin-left:6px;white-space:nowrap;">&#128172; <?= h($c['phone']) ?></a>
              <?php elseif (!empty($c['phone'])): ?>
                <span style="color:var(--grey-mid);margin-left:6px;"><?= h($c['phone']) ?></span>
              <?php endif; ?>
              <?php if (!empty($c['email'])): ?>
                <a href="mailto:<?= h($c['email']) ?>" title="<?= h($c['email']) ?>" style="color:var(--grey-mid);margin-left:6px;text-decoration:none;">&#9993;</a>
              <?php endif; ?>
              <?php if (!empty($c['notes'])): ?>
                <span style="color:var(--grey-mid);display:block;font-size:.74rem;"><?= h($c['notes']) ?></span>
              <?php endif; ?>
            </div>
          <?php endforeach; endif; ?>
        </td>
        <td class="view-<?= $ag['id'] ?>" style="text-align:center">
          <?= $ag['attiva'] ? '<span style="color:#1A6B3A">✓</span>' : '<span style="color:#C0211B">✗</span>' ?>
        </td>

        <td class="edit-<?= $ag['id'] ?>" style="display:none" colspan="5">
          <?php $agType = $ag['type'] ?? 'savannah'; ?>
          <form method="POST" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?= $ag['id'] ?>">
            <input type="text" name="nome" value="<?= h($ag['nome']) ?>" required style="width:160px;padding:5px 8px;border:1.5px solid var(--grey-lt);border-radius:5px;font-size:.85rem;">
            <input type="text" name="short_name" value="<?= h($ag['short_name'] ?? '') ?>" style="width:120px;padding:5px 8px;border:1.5px solid var(--grey-lt);border-radius:5px;font-size:.85rem;" placeholder="Short name">
            <textarea name="address" rows="5" style="width:260px;padding:5px 8px;border:1.5px solid var(--grey-lt);border-radius:5px;font-size:.85rem;font-family:inherit;resize:vertical;" placeholder="Address"><?= h($ag['address'] ?? '') ?></textarea>
            <textarea name="email" rows="5" style="width:240px;padding:5px 8px;border:1.5px solid var(--grey-lt);border-radius:5px;font-size:.85rem;font-family:inherit;resize:vertical;" placeholder="Email (comma-separated)"><?= h($ag['email'] ?? '') ?></textarea>
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
            <button type="button" class="btn btn-outline btn-sm" onclick="toggleContacts(<?= $ag['id'] ?>)">Contacts</button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete agency?')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $ag['id'] ?>">
              <button type="submit" class="btn btn-outline btn-sm" style="color:#C0211B; border-color:#C0211B;">Delete</button>
            </form>
          </div>
        </td>
      </tr>

      <!-- CONTACTS MANAGEMENT ROW (separate from the agency edit form) -->
      <tr class="contacts-<?= $ag['id'] ?>" style="display:none">
        <td colspan="6" style="background:var(--grey-bg,#f7f7f7);padding:14px 16px;">
          <div style="font-weight:600;font-size:.82rem;margin-bottom:10px;">Contacts &mdash; <?= h($ag['nome']) ?></div>

          <?php $cs = $contactsByAgency[$ag['id']] ?? []; ?>
          <?php foreach ($cs as $c): ?>
            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
              <form method="POST" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="action" value="contact_edit">
                <input type="hidden" name="agency_id" value="<?= $ag['id'] ?>">
                <input type="hidden" name="contact_id" value="<?= $c['id'] ?>">
                <input type="text" name="c_name"  value="<?= h($c['name']) ?>"  required placeholder="Name"  style="width:150px;padding:5px 8px;border:1.5px solid var(--grey-lt);border-radius:5px;font-size:.82rem;">
                <input type="text" name="c_role"  value="<?= h($c['role'] ?? '') ?>"  placeholder="Role"  style="width:120px;padding:5px 8px;border:1.5px solid var(--grey-lt);border-radius:5px;font-size:.82rem;">
                <input type="text" name="c_phone" value="<?= h($c['phone'] ?? '') ?>" placeholder="Phone (e.g. +39…)" style="width:150px;padding:5px 8px;border:1.5px solid var(--grey-lt);border-radius:5px;font-size:.82rem;">
                <input type="email" name="c_email" value="<?= h($c['email'] ?? '') ?>" placeholder="Email" style="width:180px;padding:5px 8px;border:1.5px solid var(--grey-lt);border-radius:5px;font-size:.82rem;">
                <input type="text" name="c_notes" value="<?= h($c['notes'] ?? '') ?>" placeholder="Notes" style="width:180px;padding:5px 8px;border:1.5px solid var(--grey-lt);border-radius:5px;font-size:.82rem;">
                <label style="display:flex;align-items:center;gap:4px;font-size:.78rem;white-space:nowrap;"><input type="checkbox" name="c_primary" <?= $c['is_primary']?'checked':'' ?>> Primary</label>
                <button type="submit" class="btn btn-outline btn-sm">Save</button>
              </form>
              <form method="POST" style="display:inline" onsubmit="return confirm('Delete contact?')">
                <input type="hidden" name="action" value="contact_delete">
                <input type="hidden" name="contact_id" value="<?= $c['id'] ?>">
                <button type="submit" class="btn btn-outline btn-sm" style="color:#C0211B;border-color:#C0211B;">Delete</button>
              </form>
            </div>
          <?php endforeach; ?>

          <!-- ADD CONTACT -->
          <form method="POST" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:6px;border-top:1px dashed var(--grey-lt);padding-top:10px;">
            <input type="hidden" name="action" value="contact_add">
            <input type="hidden" name="agency_id" value="<?= $ag['id'] ?>">
            <input type="text" name="c_name"  required placeholder="Name"  style="width:150px;padding:5px 8px;border:1.5px solid var(--grey-lt);border-radius:5px;font-size:.82rem;">
            <input type="text" name="c_role"  placeholder="Role"  style="width:120px;padding:5px 8px;border:1.5px solid var(--grey-lt);border-radius:5px;font-size:.82rem;">
            <input type="text" name="c_phone" placeholder="Phone (e.g. +39…)" style="width:150px;padding:5px 8px;border:1.5px solid var(--grey-lt);border-radius:5px;font-size:.82rem;">
            <input type="email" name="c_email" placeholder="Email" style="width:180px;padding:5px 8px;border:1.5px solid var(--grey-lt);border-radius:5px;font-size:.82rem;">
            <input type="text" name="c_notes" placeholder="Notes" style="width:180px;padding:5px 8px;border:1.5px solid var(--grey-lt);border-radius:5px;font-size:.82rem;">
            <label style="display:flex;align-items:center;gap:4px;font-size:.78rem;white-space:nowrap;"><input type="checkbox" name="c_primary"> Primary</label>
            <button type="submit" class="btn btn-red btn-sm">+ Add contact</button>
          </form>
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

function toggleContacts(id) {
  document.querySelectorAll('.contacts-' + id).forEach(function(el){
    el.style.display = (el.style.display === 'none' || el.style.display === '') ? 'table-row' : 'none';
  });
}
</script>
<?php include 'includes/footer.php'; ?>