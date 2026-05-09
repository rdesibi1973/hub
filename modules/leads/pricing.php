<?php
require_once 'config.php';
$pageTitle = 'Pricing — Lodges';
requireLogin();
if (isLeadsRestricted()) { flash('Access denied.', 'error'); header('Location: requests.php'); exit; }
$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_lodge') {
        $name  = substr(trim($_POST['name']     ?? ''), 0, 200);
        $loc   = substr(trim($_POST['location'] ?? ''), 0, 200);
        $meal  = in_array($_POST['meal_plan'] ?? '', ['BB','HB','FB','AI']) ? $_POST['meal_plan'] : 'BB';
        if ($name) {
            $db->prepare("INSERT INTO lodges (name, location, default_meal_plan) VALUES (?,?,?)")
               ->execute([$name, $loc, $meal]);
            flash("Lodge «$name» added.", 'success');
        }
    }

    if ($action === 'toggle_lodge') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) $db->prepare("UPDATE lodges SET active = 1 - active WHERE id = ?")->execute([$id]);
    }

    if ($action === 'delete_lodge') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare("DELETE FROM lodges WHERE id = ?")->execute([$id]);
            flash('Lodge deleted.', 'success');
        }
    }

    header('Location: pricing.php'); exit;
}

$lodges = $db->query("
    SELECT l.*,
           COUNT(DISTINCT rt.id) AS room_count,
           COUNT(DISTINCT ls.id) AS season_count,
           COUNT(DISTINCT lp.id) AS price_count
    FROM   lodges l
    LEFT JOIN lodge_room_types rt ON rt.lodge_id = l.id
    LEFT JOIN lodge_seasons    ls ON ls.lodge_id = l.id
    LEFT JOIN lodge_prices     lp ON lp.room_type_id = rt.id
    GROUP BY l.id
    ORDER BY l.active DESC, l.name
")->fetchAll();

include 'includes/header.php';
?>
<style>
.px{max-width:960px;margin:24px auto;padding:0 20px}
.page-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
.page-title{font-family:'Merriweather',serif;font-size:1.3rem;color:#111827;margin:0}
.page-sub{color:#6b7280;font-size:.8rem;margin:3px 0 0}
.card{background:#fff;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.07);overflow:hidden}
.card-pad{padding:20px 22px}
.stbl{width:100%;border-collapse:collapse;font-size:.85rem}
.stbl th{background:#f9fafb;padding:9px 14px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280}
.stbl td{padding:9px 14px;border-top:1px solid #f3f4f6;vertical-align:middle}
.badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:.72rem;font-weight:700;background:#f3f4f6;color:#374151}
.badge-ok{background:#dcfce7;color:#166534}
.badge-warn{background:#fef9c3;color:#92400e}
.badge-red{background:#fee2e2;color:#991b1b}
.flbl{display:block;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:4px}
.finp{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.88rem;box-sizing:border-box}
.btn-g{padding:9px 20px;border:none;border-radius:8px;background:#166534;color:#fff;cursor:pointer;font-size:.85rem;font-weight:700}
.btn-sm{padding:4px 10px;border-radius:6px;font-size:.75rem;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block}
.btn-lnk{background:none;border:none;cursor:pointer;font-size:.78rem;color:#6b7280;padding:0}
</style>

<div class="px">

<div class="page-head">
  <div>
    <h2 class="page-title">Pricing</h2>
    <p class="page-sub">Manage lodge prices, seasons and room types</p>
  </div>
</div>

<!-- Lodge list -->
<div class="card" style="margin-bottom:18px">
  <table class="stbl">
    <thead>
      <tr>
        <th>Lodge</th>
        <th>Location</th>
        <th style="text-align:center">Meal Plan</th>
        <th style="text-align:center">Seasons</th>
        <th style="text-align:center">Room Types</th>
        <th style="text-align:center">Prices</th>
        <th style="text-align:center">Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$lodges): ?>
      <tr><td colspan="8" style="padding:24px;text-align:center;color:#9ca3af">No lodges yet — add one below.</td></tr>
    <?php endif; ?>
    <?php foreach ($lodges as $l): ?>
      <tr style="<?= $l['active'] ? '' : 'opacity:.5' ?>">
        <td style="font-weight:600;color:#111827">
          <a href="pricing_lodge.php?id=<?= $l['id'] ?>" style="color:inherit;text-decoration:none"><?= h($l['name']) ?></a>
        </td>
        <td style="color:#6b7280"><?= h($l['location']) ?></td>
        <td style="text-align:center"><span class="badge"><?= h($l['default_meal_plan']) ?></span></td>
        <td style="text-align:center">
          <span class="badge <?= $l['season_count'] ? 'badge-ok' : 'badge-red' ?>"><?= (int)$l['season_count'] ?></span>
        </td>
        <td style="text-align:center">
          <span class="badge <?= $l['room_count'] ? 'badge-ok' : 'badge-red' ?>"><?= (int)$l['room_count'] ?></span>
        </td>
        <td style="text-align:center">
          <span class="badge <?= $l['price_count'] ? 'badge-ok' : 'badge-warn' ?>"><?= (int)$l['price_count'] ?></span>
        </td>
        <td style="text-align:center">
          <form method="post" style="display:inline">
            <input type="hidden" name="action" value="toggle_lodge">
            <input type="hidden" name="id" value="<?= $l['id'] ?>">
            <button type="submit" class="btn-lnk" style="color:<?= $l['active'] ? '#166534' : '#9ca3af' ?>;font-weight:700">
              <?= $l['active'] ? 'Active' : 'Inactive' ?>
            </button>
          </form>
        </td>
        <td style="text-align:right">
          <a href="pricing_lodge.php?id=<?= $l['id'] ?>" class="btn-sm" style="background:#f0fdf4;color:#166534;border:1px solid #bbf7d0">Manage →</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Add Lodge -->
<div class="card card-pad">
  <h3 style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#6b7280;margin:0 0 14px">Add New Lodge</h3>
  <form method="post">
    <input type="hidden" name="action" value="add_lodge">
    <div style="display:grid;grid-template-columns:2fr 2fr 1fr auto;gap:12px;align-items:end">
      <div>
        <label class="flbl">Name *</label>
        <input class="finp" name="name" required placeholder="e.g. Zanzibar Pearl">
      </div>
      <div>
        <label class="flbl">Location</label>
        <input class="finp" name="location" placeholder="e.g. Zanzibar">
      </div>
      <div>
        <label class="flbl">Default Meal Plan</label>
        <select class="finp" name="meal_plan">
          <option value="BB">BB — Bed &amp; Breakfast</option>
          <option value="HB">HB — Half Board</option>
          <option value="FB">FB — Full Board</option>
          <option value="AI">AI — All Inclusive</option>
        </select>
      </div>
      <div>
        <button type="submit" class="btn-g">+ Add Lodge</button>
      </div>
    </div>
  </form>
</div>

</div>
<?php include 'includes/footer.php'; ?>
