<?php
require_once 'config.php';
$pageTitle = 'Lodge Pricing';
requireLogin();
if (isLeadsRestricted()) { flash('Access denied.', 'error'); header('Location: requests.php'); exit; }
$db = db();

$lodgeId = (int)($_GET['id'] ?? 0);
if (!$lodgeId) { header('Location: pricing.php'); exit; }

$lodge = $db->prepare("SELECT * FROM lodges WHERE id = ?")->execute([$lodgeId]) ? null : null;
$stmt = $db->prepare("SELECT * FROM lodges WHERE id = ?");
$stmt->execute([$lodgeId]);
$lodge = $stmt->fetch();
if (!$lodge) { flash('Lodge not found.', 'error'); header('Location: pricing.php'); exit; }

function redir(int $id, string $anchor = ''): never {
    header("Location: pricing_lodge.php?id=$id" . ($anchor ? "#$anchor" : '')); exit;
}

// ── POST handlers ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    global $db, $lodgeId;
    $action = $_POST['action'] ?? '';

    if ($action === 'edit_lodge') {
        $name  = substr(trim($_POST['name']     ?? ''), 0, 200);
        $loc   = substr(trim($_POST['location'] ?? ''), 0, 200);
        $meal  = in_array($_POST['meal_plan'] ?? '', ['BB','HB','FB','AI']) ? $_POST['meal_plan'] : 'BB';
        $notes = trim($_POST['notes'] ?? '');
        if ($name) {
            $db->prepare("UPDATE lodges SET name=?, location=?, default_meal_plan=?, notes=? WHERE id=?")
               ->execute([$name, $loc, $meal, $notes, $lodgeId]);
            flash('Lodge updated.', 'success');
        }
        redir($lodgeId);
    }

    if ($action === 'add_season') {
        $name  = substr(trim($_POST['name'] ?? ''), 0, 100);
        $order = (int)($_POST['sort_order'] ?? 0);
        if ($name) {
            $db->prepare("INSERT INTO lodge_seasons (lodge_id, name, sort_order) VALUES (?,?,?)")
               ->execute([$lodgeId, $name, $order]);
        }
        redir($lodgeId, 'seasons');
    }

    if ($action === 'delete_season') {
        $sid = (int)($_POST['season_id'] ?? 0);
        // Verify belongs to this lodge
        $check = $db->prepare("SELECT id FROM lodge_seasons WHERE id=? AND lodge_id=?");
        $check->execute([$sid, $lodgeId]);
        if ($check->fetch()) $db->prepare("DELETE FROM lodge_seasons WHERE id=?")->execute([$sid]);
        redir($lodgeId, 'seasons');
    }

    if ($action === 'add_period') {
        $sid   = (int)($_POST['season_id']   ?? 0);
        $start = $_POST['start_mmdd'] ?? '';
        $end   = $_POST['end_mmdd']   ?? '';
        $year  = (int)($_POST['year'] ?? 0) ?: null;
        // Validate MM-DD format
        if ($sid && preg_match('/^\d{2}-\d{2}$/', $start) && preg_match('/^\d{2}-\d{2}$/', $end)) {
            $check = $db->prepare("SELECT id FROM lodge_seasons WHERE id=? AND lodge_id=?");
            $check->execute([$sid, $lodgeId]);
            if ($check->fetch()) {
                $db->prepare("INSERT INTO lodge_season_periods (season_id, year, start_mmdd, end_mmdd) VALUES (?,?,?,?)")
                   ->execute([$sid, $year, $start, $end]);
            }
        }
        redir($lodgeId, 'seasons');
    }

    if ($action === 'delete_period') {
        $pid = (int)($_POST['period_id'] ?? 0);
        // Verify belongs to this lodge via join
        $check = $db->prepare("SELECT p.id FROM lodge_season_periods p JOIN lodge_seasons s ON s.id=p.season_id WHERE p.id=? AND s.lodge_id=?");
        $check->execute([$pid, $lodgeId]);
        if ($check->fetch()) $db->prepare("DELETE FROM lodge_season_periods WHERE id=?")->execute([$pid]);
        redir($lodgeId, 'seasons');
    }

    if ($action === 'add_room_type') {
        $name    = substr(trim($_POST['name'] ?? ''), 0, 200);
        $maxPax  = max(1, min(10, (int)($_POST['max_pax'] ?? 2)));
        $order   = (int)($_POST['sort_order'] ?? 0);
        if ($name) {
            $db->prepare("INSERT INTO lodge_room_types (lodge_id, name, max_pax, sort_order) VALUES (?,?,?,?)")
               ->execute([$lodgeId, $name, $maxPax, $order]);
        }
        redir($lodgeId, 'roomtypes');
    }

    if ($action === 'delete_room_type') {
        $rtId = (int)($_POST['rt_id'] ?? 0);
        $check = $db->prepare("SELECT id FROM lodge_room_types WHERE id=? AND lodge_id=?");
        $check->execute([$rtId, $lodgeId]);
        if ($check->fetch()) $db->prepare("DELETE FROM lodge_room_types WHERE id=?")->execute([$rtId]);
        redir($lodgeId, 'roomtypes');
    }

    if ($action === 'save_prices') {
        foreach (($_POST['prices'] ?? []) as $rtId => $seasonRow) {
            $rtId = (int)$rtId;
            // Verify RT belongs to this lodge
            $rtCheck = $db->prepare("SELECT id FROM lodge_room_types WHERE id=? AND lodge_id=?");
            $rtCheck->execute([$rtId, $lodgeId]);
            if (!$rtCheck->fetch()) continue;

            foreach ($seasonRow as $sId => $pData) {
                $sId  = (int)$sId;
                $meal = in_array($pData['meal_plan'] ?? '', ['BB','HB','FB','AI']) ? $pData['meal_plan'] : 'BB';
                $basis = in_array($pData['basis'] ?? '', ['per_room','per_person']) ? $pData['basis'] : 'per_room';
                $pax = [];
                for ($i = 1; $i <= 5; $i++) {
                    $v = trim($pData["pax_$i"] ?? '');
                    $pax[] = ($v !== '' && is_numeric($v)) ? (float)$v : null;
                }
                [$p1, $p2, $p3, $p4, $p5] = $pax;
                $db->prepare("
                    INSERT INTO lodge_prices
                      (room_type_id, season_id, meal_plan, price_basis, pax_1, pax_2, pax_3, pax_4, pax_5)
                    VALUES (?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                      meal_plan=VALUES(meal_plan), price_basis=VALUES(price_basis),
                      pax_1=VALUES(pax_1), pax_2=VALUES(pax_2), pax_3=VALUES(pax_3),
                      pax_4=VALUES(pax_4), pax_5=VALUES(pax_5)
                ")->execute([$rtId, $sId, $meal, $basis, $p1, $p2, $p3, $p4, $p5]);
            }
        }
        flash('Prices saved.', 'success');
        redir($lodgeId, 'prices');
    }

    if ($action === 'add_supplement') {
        $name   = substr(trim($_POST['name'] ?? ''), 0, 200);
        $start  = $_POST['start_mmdd'] ?? '';
        $end    = $_POST['end_mmdd']   ?? '';
        $year   = (int)($_POST['year'] ?? 0) ?: null;
        $adult  = max(0, (float)($_POST['amount_adult'] ?? 0));
        $child  = max(0, (float)($_POST['amount_child'] ?? 0));
        $mand   = (int)($_POST['mandatory'] ?? 0);
        if ($name && preg_match('/^\d{2}-\d{2}$/', $start) && preg_match('/^\d{2}-\d{2}$/', $end)) {
            $db->prepare("INSERT INTO lodge_supplements (lodge_id, name, start_mmdd, end_mmdd, year, amount_adult, amount_child, mandatory) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$lodgeId, $name, $start, $end, $year, $adult, $child, $mand]);
        }
        redir($lodgeId, 'supplements');
    }

    if ($action === 'delete_supplement') {
        $supId = (int)($_POST['sup_id'] ?? 0);
        $db->prepare("DELETE FROM lodge_supplements WHERE id=? AND lodge_id=?")->execute([$supId, $lodgeId]);
        redir($lodgeId, 'supplements');
    }

    redir($lodgeId);
}

// ── Fetch data ─────────────────────────────────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM lodge_seasons WHERE lodge_id=? ORDER BY sort_order, id");
$stmt->execute([$lodgeId]);
$seasons = $stmt->fetchAll();

$seasonIds = array_column($seasons, 'id');
$periods   = [];
if ($seasonIds) {
    $in   = implode(',', array_fill(0, count($seasonIds), '?'));
    $stmt = $db->prepare("SELECT * FROM lodge_season_periods WHERE season_id IN ($in) ORDER BY season_id, year IS NULL DESC, start_mmdd");
    $stmt->execute($seasonIds);
    foreach ($stmt->fetchAll() as $p) $periods[$p['season_id']][] = $p;
}

$stmt = $db->prepare("SELECT * FROM lodge_room_types WHERE lodge_id=? ORDER BY sort_order, name");
$stmt->execute([$lodgeId]);
$roomTypes = $stmt->fetchAll();

// Price matrix: $prices[rt_id][season_id] = row
$prices = [];
if ($roomTypes && $seasonIds) {
    $rtIds = array_column($roomTypes, 'id');
    $in1   = implode(',', array_fill(0, count($rtIds),    '?'));
    $in2   = implode(',', array_fill(0, count($seasonIds), '?'));
    $stmt  = $db->prepare("SELECT * FROM lodge_prices WHERE room_type_id IN ($in1) AND season_id IN ($in2)");
    $stmt->execute(array_merge($rtIds, $seasonIds));
    foreach ($stmt->fetchAll() as $row) {
        $prices[$row['room_type_id']][$row['season_id']] = $row;
    }
}

$stmt = $db->prepare("SELECT * FROM lodge_supplements WHERE lodge_id=? ORDER BY start_mmdd");
$stmt->execute([$lodgeId]);
$supplements = $stmt->fetchAll();

include 'includes/header.php';
?>
<style>
.px{max-width:1100px;margin:24px auto;padding:0 20px}
.card{background:#fff;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.07);margin-bottom:18px;overflow:hidden}
.card-head{padding:14px 18px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between}
.card-head h3{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#6b7280;margin:0}
.card-pad{padding:18px}
.flbl{display:block;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:4px}
.finp{width:100%;padding:7px 9px;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem;box-sizing:border-box}
.finp:focus{outline:none;border-color:#166534;box-shadow:0 0 0 2px rgba(22,101,52,.12)}
.btn-g{padding:8px 18px;border:none;border-radius:8px;background:#166534;color:#fff;cursor:pointer;font-size:.85rem;font-weight:700}
.btn-sm{padding:4px 10px;border-radius:5px;font-size:.72rem;font-weight:600;cursor:pointer;border:1px solid #d1d5db;background:#fff;color:#374151}
.btn-del{border:none;background:none;cursor:pointer;color:#ef4444;font-size:.78rem;padding:2px 6px}
.stbl{width:100%;border-collapse:collapse;font-size:.82rem}
.stbl th{background:#f9fafb;padding:7px 12px;text-align:left;font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;white-space:nowrap}
.stbl td{padding:7px 12px;border-top:1px solid #f3f4f6;vertical-align:top}
/* Price matrix */
.pmtbl{width:100%;border-collapse:collapse;font-size:.8rem}
.pmtbl th{background:#f9fafb;padding:8px 10px;text-align:center;font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;border:1px solid #e5e7eb;white-space:nowrap}
.pmtbl td{border:1px solid #e5e7eb;padding:8px 10px;vertical-align:top;min-width:120px}
.pmtbl td:first-child{font-weight:600;color:#374151;background:#fafafa;min-width:160px;white-space:nowrap}
.pax-row{display:flex;align-items:center;gap:6px;margin-bottom:4px}
.pax-lbl{font-size:.65rem;color:#9ca3af;width:24px;flex-shrink:0;font-weight:600}
.pax-inp{width:70px;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:.8rem;text-align:right}
.pax-inp:focus{outline:none;border-color:#166534}
.pill{display:inline-block;padding:1px 7px;border-radius:4px;font-size:.72rem;font-weight:600;background:#f3f4f6;color:#374151;margin:1px}
</style>

<div class="px">

<!-- Breadcrumb -->
<div style="font-size:.8rem;color:#9ca3af;margin-bottom:12px">
  <a href="pricing.php" style="color:#6b7280;text-decoration:none">Pricing</a>
  <span style="margin:0 6px">›</span>
  <span style="color:#111827;font-weight:600"><?= h($lodge['name']) ?></span>
</div>

<!-- Lodge info -->
<div class="card">
  <div class="card-head">
    <h3>Lodge Info</h3>
    <button onclick="document.getElementById('lodgeEditForm').style.display=document.getElementById('lodgeEditForm').style.display==='none'?'':'none'" class="btn-sm">Edit</button>
  </div>
  <div class="card-pad">
    <div style="display:flex;gap:24px;flex-wrap:wrap;font-size:.88rem">
      <div><span style="color:#6b7280;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em">Name</span><br><strong><?= h($lodge['name']) ?></strong></div>
      <div><span style="color:#6b7280;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em">Location</span><br><?= h($lodge['location'] ?: '—') ?></div>
      <div><span style="color:#6b7280;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em">Meal Plan</span><br><?= h($lodge['default_meal_plan']) ?></div>
      <div><span style="color:#6b7280;font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em">Status</span><br><?= $lodge['active'] ? '<span style="color:#166534;font-weight:700">Active</span>' : '<span style="color:#9ca3af">Inactive</span>' ?></div>
    </div>
    <?php if ($lodge['notes']): ?>
      <div style="margin-top:10px;font-size:.82rem;color:#6b7280"><?= h($lodge['notes']) ?></div>
    <?php endif; ?>
    <!-- Edit form (hidden) -->
    <form method="post" id="lodgeEditForm" style="display:none;margin-top:14px;padding-top:14px;border-top:1px solid #f3f4f6">
      <input type="hidden" name="action" value="edit_lodge">
      <div style="display:grid;grid-template-columns:2fr 2fr 1fr;gap:12px;margin-bottom:10px">
        <div><label class="flbl">Name *</label><input class="finp" name="name" required value="<?= h($lodge['name']) ?>"></div>
        <div><label class="flbl">Location</label><input class="finp" name="location" value="<?= h($lodge['location']) ?>"></div>
        <div><label class="flbl">Meal Plan</label>
          <select class="finp" name="meal_plan">
            <?php foreach (['BB','HB','FB','AI'] as $mp): ?>
              <option value="<?= $mp ?>"<?= $lodge['default_meal_plan']===$mp?' selected':'' ?>><?= $mp ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div style="margin-bottom:10px"><label class="flbl">Notes</label><textarea class="finp" name="notes" rows="2"><?= h($lodge['notes']) ?></textarea></div>
      <button type="submit" class="btn-g">Save</button>
    </form>
  </div>
</div>

<!-- ── SEASONS ──────────────────────────────────────────────────────────────── -->
<div class="card" id="seasons">
  <div class="card-head"><h3>Seasons &amp; Date Ranges</h3></div>
  <div class="card-pad">

  <?php if (!$seasons): ?>
    <p style="color:#9ca3af;font-size:.85rem;margin:0 0 14px">No seasons defined yet. Add one below.</p>
  <?php endif; ?>

  <?php foreach ($seasons as $s): ?>
    <div style="margin-bottom:16px;padding:12px 14px;border:1px solid #e5e7eb;border-radius:8px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
        <strong style="font-size:.9rem"><?= h($s['name']) ?></strong>
        <form method="post" onsubmit="return confirm('Delete season «<?= h($s['name']) ?>» and all its prices?')">
          <input type="hidden" name="action" value="delete_season">
          <input type="hidden" name="season_id" value="<?= $s['id'] ?>">
          <button type="submit" class="btn-del">🗑 Delete season</button>
        </form>
      </div>
      <!-- Period list -->
      <div style="margin-bottom:8px">
        <?php $perRows = $periods[$s['id']] ?? []; ?>
        <?php if (!$perRows): ?>
          <span style="color:#9ca3af;font-size:.8rem">No date ranges — add one below.</span>
        <?php endif; ?>
        <?php foreach ($perRows as $p): ?>
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;font-size:.83rem">
            <span class="pill"><?= h($p['start_mmdd']) ?> → <?= h($p['end_mmdd']) ?><?= $p['year'] ? ' ('.h($p['year']).')' : ' (every year)' ?></span>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="delete_period">
              <input type="hidden" name="period_id" value="<?= $p['id'] ?>">
              <button type="submit" class="btn-del" style="font-size:.7rem">×</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
      <!-- Add period -->
      <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="action" value="add_period">
        <input type="hidden" name="season_id" value="<?= $s['id'] ?>">
        <div>
          <label class="flbl">From (MM-DD)</label>
          <input class="finp" name="start_mmdd" required pattern="\d{2}-\d{2}" placeholder="e.g. 07-01" style="width:100px">
        </div>
        <div>
          <label class="flbl">To (MM-DD)</label>
          <input class="finp" name="end_mmdd" required pattern="\d{2}-\d{2}" placeholder="e.g. 10-31" style="width:100px">
        </div>
        <div>
          <label class="flbl">Year (blank = every year)</label>
          <input class="finp" name="year" type="number" min="2024" max="2099" placeholder="e.g. 2026" style="width:100px">
        </div>
        <button type="submit" class="btn-sm" style="background:#f0fdf4;color:#166534;border-color:#bbf7d0">+ Add Range</button>
      </form>
    </div>
  <?php endforeach; ?>

  <!-- Add season form -->
  <div style="padding-top:10px;border-top:1px solid #f3f4f6;margin-top:4px">
    <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
      <input type="hidden" name="action" value="add_season">
      <div>
        <label class="flbl">New Season Name</label>
        <input class="finp" name="name" required placeholder="e.g. High Season" style="width:160px">
      </div>
      <div>
        <label class="flbl">Sort Order</label>
        <input class="finp" name="sort_order" type="number" value="<?= count($seasons) ?>" style="width:70px">
      </div>
      <button type="submit" class="btn-g">+ Add Season</button>
    </form>
  </div>
  </div>
</div>

<!-- ── ROOM TYPES ───────────────────────────────────────────────────────────── -->
<div class="card" id="roomtypes">
  <div class="card-head"><h3>Room Types</h3></div>
  <div class="card-pad">
  <?php if ($roomTypes): ?>
  <table class="stbl" style="margin-bottom:14px">
    <thead><tr><th>Name</th><th>Max Pax</th><th>Order</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($roomTypes as $rt): ?>
      <tr>
        <td style="font-weight:600"><?= h($rt['name']) ?></td>
        <td><?= (int)$rt['max_pax'] ?></td>
        <td><?= (int)$rt['sort_order'] ?></td>
        <td>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete room type «<?= h($rt['name']) ?>» and all its prices?')">
            <input type="hidden" name="action" value="delete_room_type">
            <input type="hidden" name="rt_id" value="<?= $rt['id'] ?>">
            <button type="submit" class="btn-del">🗑</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="action" value="add_room_type">
    <div>
      <label class="flbl">Room Type Name *</label>
      <input class="finp" name="name" required placeholder="e.g. Standard Double" style="width:220px">
    </div>
    <div>
      <label class="flbl">Max Pax</label>
      <input class="finp" name="max_pax" type="number" min="1" max="10" value="2" style="width:70px">
    </div>
    <div>
      <label class="flbl">Sort Order</label>
      <input class="finp" name="sort_order" type="number" value="<?= count($roomTypes) ?>" style="width:70px">
    </div>
    <button type="submit" class="btn-g">+ Add Room Type</button>
  </form>
  </div>
</div>

<!-- ── PRICE MATRIX ─────────────────────────────────────────────────────────── -->
<div class="card" id="prices">
  <div class="card-head"><h3>Price Matrix (STO / Nett)</h3></div>
  <div class="card-pad">
  <?php if (!$seasons): ?>
    <p style="color:#9ca3af;font-size:.85rem;margin:0">Add seasons first.</p>
  <?php elseif (!$roomTypes): ?>
    <p style="color:#9ca3af;font-size:.85rem;margin:0">Add room types first.</p>
  <?php else: ?>
    <p style="font-size:.78rem;color:#6b7280;margin:0 0 12px">Enter the total cost per room (or per person if the contract is per-person basis) for each occupancy level. Leave blank if the occupancy is not available.</p>
    <form method="post">
      <input type="hidden" name="action" value="save_prices">
      <div style="overflow-x:auto">
      <table class="pmtbl">
        <thead>
          <tr>
            <th style="text-align:left">Room Type</th>
            <?php foreach ($seasons as $s): ?>
              <th><?= h($s['name']) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($roomTypes as $rt): ?>
          <tr>
            <td>
              <?= h($rt['name']) ?><br>
              <span style="font-size:.7rem;color:#9ca3af">max <?= (int)$rt['max_pax'] ?> pax</span>
            </td>
            <?php foreach ($seasons as $s): ?>
              <?php $p = $prices[$rt['id']][$s['id']] ?? null; ?>
              <td>
                <div style="margin-bottom:6px">
                  <select name="prices[<?= $rt['id'] ?>][<?= $s['id'] ?>][meal_plan]" style="width:100%;padding:3px 5px;border:1px solid #d1d5db;border-radius:4px;font-size:.72rem;color:#374151">
                    <?php foreach (['BB','HB','FB','AI'] as $mp): ?>
                      <option value="<?= $mp ?>"<?= ($p['meal_plan']??'BB')===$mp?' selected':'' ?>><?= $mp ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div style="margin-bottom:4px">
                  <select name="prices[<?= $rt['id'] ?>][<?= $s['id'] ?>][basis]" style="width:100%;padding:3px 5px;border:1px solid #d1d5db;border-radius:4px;font-size:.72rem;color:#374151">
                    <option value="per_room"<?= ($p['price_basis']??'per_room')==='per_room'?' selected':'' ?>>Per room</option>
                    <option value="per_person"<?= ($p['price_basis']??'')==='per_person'?' selected':'' ?>>Per person</option>
                  </select>
                </div>
                <?php for ($i = 1; $i <= $rt['max_pax']; $i++): ?>
                  <div class="pax-row">
                    <span class="pax-lbl"><?= $i ?>p</span>
                    <input class="pax-inp" type="number" step="0.01" min="0"
                           name="prices[<?= $rt['id'] ?>][<?= $s['id'] ?>][pax_<?= $i ?>]"
                           value="<?= h($p["pax_$i"] ?? '') ?>"
                           placeholder="—">
                  </div>
                <?php endfor; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <button type="submit" class="btn-g" style="margin-top:14px">💾 Save Prices</button>
    </form>
  <?php endif; ?>
  </div>
</div>

<!-- ── SUPPLEMENTS ─────────────────────────────────────────────────────────── -->
<div class="card" id="supplements">
  <div class="card-head"><h3>Holiday &amp; Festive Supplements</h3></div>
  <div class="card-pad">
  <?php if ($supplements): ?>
  <table class="stbl" style="margin-bottom:14px">
    <thead>
      <tr><th>Name</th><th>Dates</th><th>Year</th><th style="text-align:right">Adult</th><th style="text-align:right">Child</th><th>Mandatory</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($supplements as $sup): ?>
      <tr>
        <td style="font-weight:600"><?= h($sup['name']) ?></td>
        <td><?= h($sup['start_mmdd']) ?> → <?= h($sup['end_mmdd']) ?></td>
        <td><?= $sup['year'] ? h($sup['year']) : 'Every year' ?></td>
        <td style="text-align:right">$<?= number_format($sup['amount_adult'],2) ?></td>
        <td style="text-align:right">$<?= number_format($sup['amount_child'],2) ?></td>
        <td><?= $sup['mandatory'] ? 'Yes' : 'No' ?></td>
        <td>
          <form method="post" style="display:inline">
            <input type="hidden" name="action" value="delete_supplement">
            <input type="hidden" name="sup_id" value="<?= $sup['id'] ?>">
            <button type="submit" class="btn-del">🗑</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="action" value="add_supplement">
    <div><label class="flbl">Name *</label><input class="finp" name="name" required placeholder="e.g. Christmas Dinner" style="width:180px"></div>
    <div><label class="flbl">From (MM-DD)</label><input class="finp" name="start_mmdd" required pattern="\d{2}-\d{2}" placeholder="12-24" style="width:90px"></div>
    <div><label class="flbl">To (MM-DD)</label><input class="finp" name="end_mmdd" required pattern="\d{2}-\d{2}" placeholder="12-26" style="width:90px"></div>
    <div><label class="flbl">Year</label><input class="finp" name="year" type="number" min="2024" max="2099" placeholder="blank=every" style="width:90px"></div>
    <div><label class="flbl">Adult $/pax</label><input class="finp" name="amount_adult" type="number" step="0.01" min="0" value="0" style="width:80px"></div>
    <div><label class="flbl">Child $/pax</label><input class="finp" name="amount_child" type="number" step="0.01" min="0" value="0" style="width:80px"></div>
    <div><label class="flbl">Mandatory</label>
      <select class="finp" name="mandatory" style="width:80px">
        <option value="1">Yes</option>
        <option value="0">No</option>
      </select>
    </div>
    <button type="submit" class="btn-g">+ Add</button>
  </form>
  </div>
</div>

<div style="margin-bottom:30px">
  <a href="pricing.php" style="font-size:.82rem;color:#6b7280;text-decoration:none">← Back to all lodges</a>
</div>

</div>
<?php include 'includes/footer.php'; ?>
