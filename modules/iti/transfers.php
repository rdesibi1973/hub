<?php
/**
 * modules/iti/transfers.php
 * CRUD Transfer Routes + Flight Routes (tabs)
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';

$db       = db();
$_cu      = current_user();
$can_edit = in_array($_cu['role_name'], ['admin', 'manager']);

$tab    = $_GET['tab']    ?? 'road';   // 'road' | 'flight'
$action = $_REQUEST['action'] ?? '';
$id     = (int)($_REQUEST['id'] ?? 0);

// ── POST ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {

    if ($tab === 'road') {
        $f = [
            'from_destination' => (int)($_POST['from_destination'] ?? 0),
            'to_destination'   => (int)($_POST['to_destination']   ?? 0),
            'duration_min'     => (int)($_POST['duration_min']     ?? 0),
            'distance_km'      => $_POST['distance_km'] !== '' ? (int)$_POST['distance_km'] : null,
            'road_type'        => $_POST['road_type'] ?? 'mixed',
            'is_active'        => isset($_POST['is_active']) ? 1 : 0,
        ];
        foreach (ITI_LANGS as $lang) $f["notes_{$lang}"] = trim($_POST["notes_{$lang}"] ?? '');

        if (!$f['from_destination'] || !$f['to_destination']) {
            iti_flash_set('error', 'From and To destinations are required.');
            iti_redirect("transfers.php?tab=road&action={$action}" . ($id ? "&id={$id}" : ''));
        }

        if ($action === 'add') {
            $db->prepare(
                'INSERT INTO iti_transfer_routes
                 (from_destination,to_destination,duration_min,distance_km,road_type,
                  notes_en,notes_it,notes_fr,notes_es,notes_de,is_active)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$f['from_destination'],$f['to_destination'],$f['duration_min'],$f['distance_km'],$f['road_type'],$f['notes_en'],$f['notes_it'],$f['notes_fr'],$f['notes_es'],$f['notes_de'],$f['is_active']]);
            iti_flash_set('success', 'Transfer route created.');
        } elseif ($action === 'edit' && $id) {
            $db->prepare(
                'UPDATE iti_transfer_routes SET
                 from_destination=?,to_destination=?,duration_min=?,distance_km=?,road_type=?,
                 notes_en=?,notes_it=?,notes_fr=?,notes_es=?,notes_de=?,is_active=? WHERE id=?'
            )->execute([$f['from_destination'],$f['to_destination'],$f['duration_min'],$f['distance_km'],$f['road_type'],$f['notes_en'],$f['notes_it'],$f['notes_fr'],$f['notes_es'],$f['notes_de'],$f['is_active'],$id]);
            iti_flash_set('success', 'Transfer route updated.');
        } elseif ($action === 'delete' && $id) {
            $db->prepare('UPDATE iti_transfer_routes SET is_active=0 WHERE id=?')->execute([$id]);
            iti_flash_set('success', 'Route deactivated.');
        }
        iti_redirect('transfers.php?tab=road');

    } else { // flight
        $f = [
            'from_airport'  => trim($_POST['from_airport']  ?? ''),
            'from_code'     => strtoupper(trim($_POST['from_code'] ?? '')),
            'to_airport'    => trim($_POST['to_airport']    ?? ''),
            'to_code'       => strtoupper(trim($_POST['to_code']   ?? '')),
            'operator'      => trim($_POST['operator']      ?? ''),
            'flight_type'   => $_POST['flight_type']        ?? 'scheduled',
            'duration_min'  => (int)($_POST['duration_min'] ?? 0),
            'is_active'     => isset($_POST['is_active']) ? 1 : 0,
        ];
        foreach (ITI_LANGS as $lang) $f["notes_{$lang}"] = trim($_POST["notes_{$lang}"] ?? '');

        if ($f['from_airport'] === '' || $f['to_airport'] === '') {
            iti_flash_set('error', 'From and To airports are required.');
            iti_redirect("transfers.php?tab=flight&action={$action}" . ($id ? "&id={$id}" : ''));
        }

        if ($action === 'add') {
            $db->prepare(
                'INSERT INTO iti_flight_routes
                 (from_airport,from_code,to_airport,to_code,operator,flight_type,duration_min,
                  notes_en,notes_it,notes_fr,notes_es,notes_de,is_active)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([$f['from_airport'],$f['from_code'],$f['to_airport'],$f['to_code'],$f['operator'],$f['flight_type'],$f['duration_min'],$f['notes_en'],$f['notes_it'],$f['notes_fr'],$f['notes_es'],$f['notes_de'],$f['is_active']]);
            iti_flash_set('success', 'Flight route created.');
        } elseif ($action === 'edit' && $id) {
            $db->prepare(
                'UPDATE iti_flight_routes SET
                 from_airport=?,from_code=?,to_airport=?,to_code=?,operator=?,flight_type=?,duration_min=?,
                 notes_en=?,notes_it=?,notes_fr=?,notes_es=?,notes_de=?,is_active=? WHERE id=?'
            )->execute([$f['from_airport'],$f['from_code'],$f['to_airport'],$f['to_code'],$f['operator'],$f['flight_type'],$f['duration_min'],$f['notes_en'],$f['notes_it'],$f['notes_fr'],$f['notes_es'],$f['notes_de'],$f['is_active'],$id]);
            iti_flash_set('success', 'Flight route updated.');
        } elseif ($action === 'delete' && $id) {
            $db->prepare('UPDATE iti_flight_routes SET is_active=0 WHERE id=?')->execute([$id]);
            iti_flash_set('success', 'Route deactivated.');
        }
        iti_redirect('transfers.php?tab=flight');
    }
}

// ── Carica row per edit ─────────────────────────────────────
$row = null;
if (in_array($action, ['edit','view']) && $id) {
    $row = $tab === 'road' ? iti_get_transfer_route($id) : iti_get_flight_route($id);
    if (!$row) { iti_flash_set('error', 'Record not found.'); iti_redirect("transfers.php?tab={$tab}"); }
}

// ── Dati lista ──────────────────────────────────────────────
$road_routes   = iti_get_transfer_routes(false);
$flight_routes = iti_get_flight_routes(false);
$dest_map      = iti_destinations_map();

$page_title = 'Transfers & Flights — Itinerary Builder';
$extra_css = iti_extra_css();
include __DIR__ . '/../../includes/layout_header.php';
?>
<main>
<?php iti_nav('Transfers & Flights'); ?>
<?php iti_flash_render(); ?>

<?php if ($action === 'add' || ($action === 'edit' && $row)): ?>

<div class="page-header">
  <div>
    <h2><?= $action==='add' ? 'New '.($tab==='road'?'Transfer Route':'Flight Route') : 'Edit Route' ?></h2>
    <div class="sub">Master Data › Transfers &amp; Flights</div>
  </div>
  <a href="transfers.php?tab=<?= h($tab) ?>" class="btn btn-outline btn-sm">← Back</a>
</div>

<div class="form-card">
<form method="POST" action="transfers.php?tab=<?= h($tab) ?>&action=<?= h($action) ?><?= $id?"&id={$id}":'' ?>">

<?php if ($tab === 'road'): ?>

  <div class="form-section-title">Route</div>
  <div class="form-grid">
    <div class="form-group">
      <label>From <span style="color:var(--red)">*</span></label>
      <select name="from_destination" required>
        <?= iti_options($dest_map, $row['from_destination'] ?? null, '— Select —') ?>
      </select>
    </div>
    <div class="form-group">
      <label>To <span style="color:var(--red)">*</span></label>
      <select name="to_destination" required>
        <?= iti_options($dest_map, $row['to_destination'] ?? null, '— Select —') ?>
      </select>
    </div>
    <div class="form-group">
      <label>Duration (minutes)</label>
      <input type="number" name="duration_min" min="0" value="<?= (int)($row['duration_min'] ?? 0) ?>">
    </div>
    <div class="form-group">
      <label>Distance (km)</label>
      <input type="number" name="distance_km" min="0" value="<?= $row['distance_km'] ?? '' ?>">
    </div>
    <div class="form-group">
      <label>Road Type</label>
      <select name="road_type">
        <?= iti_options(ITI_ROAD_TYPES, $row['road_type'] ?? 'mixed') ?>
      </select>
    </div>
    <div class="form-group" style="flex-direction:row;align-items:center;gap:10px;align-self:flex-end;">
      <input type="checkbox" name="is_active" value="1" id="is_active"
             <?= ($row['is_active'] ?? 1)?'checked':'' ?>
             style="width:16px;height:16px;accent-color:var(--red);">
      <label for="is_active" style="margin:0;text-transform:none;font-size:.85rem;">Active</label>
    </div>
  </div>

  <div class="form-section-title">Notes <span style="font-weight:400;font-size:.8rem;color:var(--grey-mid)">× 5 languages</span></div>
  <?php foreach (ITI_LANGS as $lang): ?>
  <div class="form-group" style="margin-bottom:12px;">
    <label><?= ITI_LANG_LABELS[$lang] ?></label>
    <textarea name="notes_<?= $lang ?>"><?= h($row["notes_{$lang}"] ?? '') ?></textarea>
  </div>
  <?php endforeach; ?>

<?php else: // flight ?>

  <div class="form-section-title">Flight</div>
  <div class="form-grid">
    <div class="form-group">
      <label>From Airport <span style="color:var(--red)">*</span></label>
      <input type="text" name="from_airport" maxlength="80" required placeholder="Arusha Airport" value="<?= h($row['from_airport'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>From Code <span style="font-weight:400;font-size:.7rem">(IATA)</span></label>
      <input type="text" name="from_code" maxlength="10" style="font-family:monospace;" placeholder="ARK" value="<?= h($row['from_code'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>To Airport <span style="color:var(--red)">*</span></label>
      <input type="text" name="to_airport" maxlength="80" required placeholder="Seronera Airstrip" value="<?= h($row['to_airport'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>To Code</label>
      <input type="text" name="to_code" maxlength="10" style="font-family:monospace;" placeholder="SEU" value="<?= h($row['to_code'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Operator</label>
      <input type="text" name="operator" maxlength="100" placeholder="Coastal Aviation, Auric Air…" value="<?= h($row['operator'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Flight Type</label>
      <select name="flight_type">
        <?= iti_options(ITI_FLIGHT_TYPES, $row['flight_type'] ?? 'scheduled') ?>
      </select>
    </div>
    <div class="form-group">
      <label>Duration (minutes)</label>
      <input type="number" name="duration_min" min="0" value="<?= (int)($row['duration_min'] ?? 0) ?>">
    </div>
    <div class="form-group" style="flex-direction:row;align-items:center;gap:10px;align-self:flex-end;">
      <input type="checkbox" name="is_active" value="1" id="is_active"
             <?= ($row['is_active'] ?? 1)?'checked':'' ?>
             style="width:16px;height:16px;accent-color:var(--red);">
      <label for="is_active" style="margin:0;text-transform:none;font-size:.85rem;">Active</label>
    </div>
  </div>

  <div class="form-section-title">Notes <span style="font-weight:400;font-size:.8rem;color:var(--grey-mid)">× 5 languages</span></div>
  <?php foreach (ITI_LANGS as $lang): ?>
  <div class="form-group" style="margin-bottom:12px;">
    <label><?= ITI_LANG_LABELS[$lang] ?></label>
    <textarea name="notes_<?= $lang ?>"><?= h($row["notes_{$lang}"] ?? '') ?></textarea>
  </div>
  <?php endforeach; ?>

<?php endif; ?>

  <div class="form-actions">
    <button type="submit" class="btn btn-red"><?= $action==='add'?'+ Create':'💾 Save' ?></button>
    <a href="transfers.php?tab=<?= h($tab) ?>" class="btn btn-outline">Cancel</a>
    <?php if ($action==='edit' && $can_edit): ?>
    <div style="margin-left:auto;">
      <form method="POST" action="transfers.php?tab=<?= h($tab) ?>&action=delete&id=<?= $id ?>" style="display:inline;">
        <button class="btn btn-danger btn-sm" onclick="return confirm('Deactivate this route?')">Deactivate</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</form>
</div>

<?php else: // lista ?>

<!-- Tabs -->
<div style="display:flex;gap:0;border-bottom:2px solid var(--grey-lt);margin-bottom:24px;">
  <a href="transfers.php?tab=road"
     style="padding:10px 22px;font-size:.82rem;font-weight:700;text-decoration:none;border-bottom:2px solid <?= $tab==='road'?'var(--red)':'transparent' ?>;margin-bottom:-2px;color:<?= $tab==='road'?'var(--red)':'var(--grey-mid)' ?>;">
    🚗 Road Transfers (<?= count(array_filter($road_routes, fn($r)=>$r['is_active'])) ?>)
  </a>
  <a href="transfers.php?tab=flight"
     style="padding:10px 22px;font-size:.82rem;font-weight:700;text-decoration:none;border-bottom:2px solid <?= $tab==='flight'?'var(--red)':'transparent' ?>;margin-bottom:-2px;color:<?= $tab==='flight'?'var(--red)':'var(--grey-mid)' ?>;">
    ✈️ Internal Flights (<?= count(array_filter($flight_routes, fn($r)=>$r['is_active'])) ?>)
  </a>
</div>

<?php if ($tab === 'road'): ?>

<div class="page-header" style="margin-bottom:16px;">
  <div><h2>Road Transfers</h2><div class="sub"><?= count($road_routes) ?> routes total</div></div>
  <?php if ($can_edit): ?><a href="transfers.php?tab=road&action=add" class="btn btn-red">+ New Route</a><?php endif; ?>
</div>

<div class="table-wrap">
  <table>
    <thead><tr><th>From</th><th>To</th><th>Duration</th><th>Distance</th><th>Road</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if ($road_routes): ?>
      <?php foreach ($road_routes as $r): ?>
      <tr>
        <td style="font-weight:600;"><?= h($r['from_name']) ?></td>
        <td style="font-weight:600;"><?= h($r['to_name']) ?></td>
        <td><?= $r['duration_min'] ? $r['duration_min'].' min' : '—' ?></td>
        <td class="text-muted"><?= $r['distance_km'] ? $r['distance_km'].' km' : '—' ?></td>
        <td><span class="badge status-inquiry"><?= ITI_ROAD_TYPES[$r['road_type']] ?? h($r['road_type']) ?></span></td>
        <td><span class="badge <?= $r['is_active']?'status-booked':'status-cancelled' ?>"><?= $r['is_active']?'Active':'Inactive' ?></span></td>
        <td><?php if($can_edit): ?><a href="transfers.php?tab=road&action=edit&id=<?= $r['id'] ?>" class="btn btn-outline btn-sm">Edit</a><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    <?php else: ?>
      <tr><td colspan="7"><div class="empty-state"><div class="icon">🚗</div><p>No transfer routes yet.</p></div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php else: // flight tab ?>

<div class="page-header" style="margin-bottom:16px;">
  <div><h2>Internal Flights</h2><div class="sub"><?= count($flight_routes) ?> routes total</div></div>
  <?php if ($can_edit): ?><a href="transfers.php?tab=flight&action=add" class="btn btn-red">+ New Route</a><?php endif; ?>
</div>

<div class="table-wrap">
  <table>
    <thead><tr><th>From</th><th>To</th><th>Operator</th><th>Type</th><th>Duration</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if ($flight_routes): ?>
      <?php foreach ($flight_routes as $r): ?>
      <tr>
        <td>
          <div style="font-weight:600;"><?= h($r['from_airport']) ?></div>
          <?php if ($r['from_code']): ?><div style="font-size:.7rem;font-family:monospace;color:var(--grey-mid);"><?= h($r['from_code']) ?></div><?php endif; ?>
        </td>
        <td>
          <div style="font-weight:600;"><?= h($r['to_airport']) ?></div>
          <?php if ($r['to_code']): ?><div style="font-size:.7rem;font-family:monospace;color:var(--grey-mid);"><?= h($r['to_code']) ?></div><?php endif; ?>
        </td>
        <td class="text-muted"><?= h($r['operator'] ?? '—') ?></td>
        <td><span class="badge status-inquiry"><?= ITI_FLIGHT_TYPES[$r['flight_type']] ?? h($r['flight_type']) ?></span></td>
        <td><?= $r['duration_min'] ? $r['duration_min'].' min' : '—' ?></td>
        <td><span class="badge <?= $r['is_active']?'status-booked':'status-cancelled' ?>"><?= $r['is_active']?'Active':'Inactive' ?></span></td>
        <td><?php if($can_edit): ?><a href="transfers.php?tab=flight&action=edit&id=<?= $r['id'] ?>" class="btn btn-outline btn-sm">Edit</a><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    <?php else: ?>
      <tr><td colspan="7"><div class="empty-state"><div class="icon">✈️</div><p>No flight routes yet.</p></div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>
<?php endif; ?>
</main>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
