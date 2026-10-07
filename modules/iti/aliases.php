<?php
/**
 * modules/iti/aliases.php
 * Calc aliases: the free text of the booking's Calc Excel (HOTEL/LODGE, ACTIVITY
 * DESC, PARK/OVERNIGHT) mapped to ITI lodges, activities and transfer/flight
 * routes. Used to build the final programme from a confirmed booking: a text
 * without an alias is never guessed, it is asked once and saved here.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';
iti_ensure_final_schema();

$db       = db();
$_cu      = current_user();
$can_edit = true;   // all staff since 7 Oct 2026 (was admin/manager)
$user     = $_cu['username'] ?? 'system';

$type = $_REQUEST['type'] ?? 'lodge';
if (!array_key_exists($type, ITI_ALIAS_TYPES)) $type = 'lodge';
$table = ITI_ALIAS_TYPES[$type]['table'];
$back  = 'aliases.php?type=' . $type;

// ── POST ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            $targets = array(
                'lodge_id'          => (int)($_POST['lodge_id'] ?? 0),
                'activity_id'       => (int)($_POST['activity_id'] ?? 0),
                'transfer_route_id' => (int)($_POST['transfer_route_id'] ?? 0),
                'flight_route_id'   => (int)($_POST['flight_route_id'] ?? 0),
            );
            $meal = ($_POST['meal_basis'] ?? '') !== '' ? $_POST['meal_basis'] : null;
            iti_alias_save($type, (string)($_POST['alias'] ?? ''), $targets, $meal, $user);
            iti_flash_set('success', 'Alias "' . iti_alias_norm((string)$_POST['alias']) . '" saved.');
        } elseif ($action === 'delete') {
            $db->prepare('DELETE FROM `' . $table . '` WHERE id = ?')->execute(array((int)($_POST['id'] ?? 0)));
            iti_flash_set('success', 'Alias deleted.');
        } elseif ($action === 'seed') {
            $_SESSION['iti_alias_seed'] = iti_seed_aliases($user);
            iti_flash_set('success', 'Seed done — see the result below.');
        }
    } catch (Exception $e) {
        iti_flash_set('error', $e->getMessage());
    }
    iti_redirect($back . (!empty($_POST['lodge_id_filter']) ? '&lodge_id=' . (int)$_POST['lodge_id_filter'] : ''));
}

// ── Data ────────────────────────────────────────────────────
$q         = trim($_GET['q'] ?? '');
$lodge_fil = $type === 'lodge' ? (int)($_GET['lodge_id'] ?? 0) : 0;

$counts = array();
foreach (ITI_ALIAS_TYPES as $t => $def) {
    $counts[$t] = (int)$db->query('SELECT COUNT(*) FROM `' . $def['table'] . '`')->fetchColumn();
}

$where = array('1=1'); $params = array();
if ($q !== '') { $where[] = 'a.alias LIKE ?'; $params[] = '%' . iti_alias_norm($q) . '%'; }
if ($type === 'lodge') {
    if ($lodge_fil) { $where[] = 'a.lodge_id = ?'; $params[] = $lodge_fil; }
    $sql = 'SELECT a.*, l.name AS target, d.name_en AS target_sub
              FROM iti_lodge_aliases a
              LEFT JOIN iti_lodges l ON l.id = a.lodge_id
              LEFT JOIN iti_destinations d ON d.id = l.destination_id';
} elseif ($type === 'activity') {
    $sql = 'SELECT a.*, x.name_en AS target, x.name_it AS target_sub
              FROM iti_activity_aliases a
              LEFT JOIN iti_activities x ON x.id = a.activity_id';
} else {
    $sql = "SELECT a.*, CONCAT(fd.name_en, ' → ', td.name_en) AS target,
                   CONCAT(fr.from_airport, ' → ', fr.to_airport, IFNULL(CONCAT(' (', fr.operator, ')'), '')) AS target_sub
              FROM iti_route_aliases a
              LEFT JOIN iti_transfer_routes tr ON tr.id = a.transfer_route_id
              LEFT JOIN iti_destinations fd ON fd.id = tr.from_destination
              LEFT JOIN iti_destinations td ON td.id = tr.to_destination
              LEFT JOIN iti_flight_routes fr ON fr.id = a.flight_route_id";
}
$st = $db->prepare($sql . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY a.alias');
$st->execute($params);
$rows = $st->fetchAll();

// Pickers for the add form.
$opt_lodges = array(); $opt_acts = array(); $opt_tr = array(); $opt_fl = array();
if ($type === 'lodge') {
    foreach (iti_get_lodges() as $l) $opt_lodges[$l['id']] = $l['name'] . ' — ' . $l['dest_name_en'];
} elseif ($type === 'activity') {
    foreach (iti_get_activities() as $a) $opt_acts[$a['id']] = $a['name_en'] . ($a['name_it'] && $a['name_it'] !== $a['name_en'] ? ' / ' . $a['name_it'] : '');
} else {
    foreach (iti_get_transfer_routes() as $r) $opt_tr[$r['id']] = $r['from_name'] . ' → ' . $r['to_name'];
    foreach (iti_get_flight_routes() as $r) {
        $opt_fl[$r['id']] = $r['from_airport'] . ' → ' . $r['to_airport'] . ($r['operator'] ? ' (' . $r['operator'] . ')' : '');
    }
}

$seed = $_SESSION['iti_alias_seed'] ?? null;
unset($_SESSION['iti_alias_seed']);
if ($seed) {
    // Texts still to map first, then the ones added / already there.
    usort($seed, function ($a, $b) {
        $ok = function ($s) { return strpos($s[2], 'added') === 0 || $s[2] === 'exists'; };
        return ((int)$ok($a) - (int)$ok($b)) ?: strcmp($a[0] . $a[1], $b[0] . $b[1]);
    });
}

$page_title = 'Calc aliases — Itinerary Builder';
$extra_css = iti_extra_css();
include __DIR__ . '/../../includes/layout_header.php';
?>
<main>
<?php iti_nav('Aliases'); ?>
<?php iti_flash_render(); ?>

<div class="page-header">
  <div>
    <h2>Calc aliases</h2>
    <div class="sub">Master Data › How the Calc Excel's free text maps to ITI records — used to build the program from the Calc</div>
  </div>
  <?php if ($can_edit): ?>
  <form method="POST" action="<?= h($back) ?>" style="margin:0;">
    <input type="hidden" name="action" value="seed">
    <button type="submit" class="btn btn-outline btn-sm"
            title="Adds the lodge / activity texts found in the 27 Calc templates, only when a name matches exactly one record. Existing aliases are never changed.">🌱 Seed from Calc templates</button>
  </form>
  <?php endif; ?>
</div>

<?php if ($seed): ?>
<div class="form-card" style="margin-bottom:18px;">
  <div class="form-section-title">Seed result</div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Type</th><th>Alias</th><th>Result</th></tr></thead>
      <tbody>
      <?php foreach ($seed as $s): $ok = strpos($s[2], 'added') === 0 || $s[2] === 'exists'; ?>
        <tr><td><?= h($s[0]) ?></td><td><?= h($s[1]) ?></td>
            <td style="color:<?= $ok ? 'var(--green)' : 'var(--amber)' ?>;"><?= h($s[2]) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div style="display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap;">
  <?php foreach (ITI_ALIAS_TYPES as $t => $def): ?>
    <a href="aliases.php?type=<?= $t ?>" class="btn btn-sm <?= $t === $type ? 'btn-red' : 'btn-outline' ?>"><?= h($def['label']) ?> (<?= $counts[$t] ?>)</a>
  <?php endforeach; ?>
</div>

<?php if ($can_edit): ?>
<div class="form-card" style="margin-bottom:18px;">
<form method="POST" action="<?= h($back) ?>">
  <input type="hidden" name="action" value="save">
  <?php if ($lodge_fil): ?><input type="hidden" name="lodge_id_filter" value="<?= $lodge_fil ?>"><?php endif; ?>
  <div class="form-section-title">Add or change an alias</div>
  <div class="form-grid">
    <div class="form-group">
      <label>Calc text <span style="color:var(--red)">*</span></label>
      <input type="text" name="alias" maxlength="150" required autocomplete="off"
             placeholder="<?= $type === 'lodge' ? 'e.g. Kifaru' : ($type === 'activity' ? 'e.g. Iraqw boma' : 'e.g. Karatu-NCA-Serengeti') ?>">
      <div style="font-size:.72rem;color:var(--grey-mid);margin-top:4px;">Stored lower-case with spaces collapsed<?= $type === 'activity' ? '; texts joined with “+” are split, so add each part (e.g. “maasai”, “olduvai”)' : '' ?>. An existing alias is replaced.</div>
    </div>
    <?php if ($type === 'lodge'): ?>
    <div class="form-group">
      <label>Lodge</label>
      <select name="lodge_id"><?= iti_options($opt_lodges, $lodge_fil ?: null, '— nothing (no lodge) —') ?></select>
    </div>
    <div class="form-group">
      <label>Meal basis <span style="font-weight:400;text-transform:none;">(only if the text says it, e.g. “in HB”)</span></label>
      <select name="meal_basis"><?= iti_options(ITI_MEAL_BASIS, null, '— lodge default —') ?></select>
    </div>
    <?php elseif ($type === 'activity'): ?>
    <div class="form-group">
      <label>Activity</label>
      <select name="activity_id"><?= iti_options($opt_acts, null, '— nothing (not an activity, e.g. medivac) —') ?></select>
    </div>
    <?php else: ?>
    <div class="form-group">
      <label>Transfer route</label>
      <select name="transfer_route_id"><?= iti_options($opt_tr, null, '— no transfer —') ?></select>
    </div>
    <div class="form-group">
      <label>Flight route</label>
      <select name="flight_route_id"><?= iti_options($opt_fl, null, '— no flight —') ?></select>
    </div>
    <?php endif; ?>
  </div>
  <div class="form-actions">
    <button type="submit" class="btn btn-red">💾 Save alias</button>
  </div>
</form>
</div>
<?php endif; ?>

<form method="GET" action="aliases.php" class="filters">
  <input type="hidden" name="type" value="<?= h($type) ?>">
  <?php if ($lodge_fil): ?><input type="hidden" name="lodge_id" value="<?= $lodge_fil ?>"><?php endif; ?>
  <div class="filter-search"><label>Search</label><input type="text" name="q" placeholder="Calc text…" value="<?= h($q) ?>"></div>
  <div style="display:flex;gap:8px;align-items:flex-end;">
    <button type="submit" class="btn btn-outline btn-sm">🔍 Filter</button>
    <?php if ($q !== '' || $lodge_fil): ?><a href="<?= h($back) ?>" class="btn btn-outline btn-sm">✕ Clear</a><?php endif; ?>
  </div>
</form>

<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Calc text</th>
        <th><?= $type === 'lodge' ? 'Lodge' : ($type === 'activity' ? 'Activity' : 'Transfer / flight') ?></th>
        <?php if ($type === 'lodge'): ?><th>Meal</th><?php endif; ?>
        <th>Added</th><th></th>
      </tr>
    </thead>
    <tbody>
    <?php if ($rows): ?>
      <?php foreach ($rows as $r):
          $none = $type === 'route' ? (!$r['transfer_route_id'] && !$r['flight_route_id'])
                                    : !$r[$type === 'lodge' ? 'lodge_id' : 'activity_id']; ?>
      <tr>
        <td style="font-family:monospace;font-size:.82rem;"><?= h($r['alias']) ?></td>
        <td>
          <?php if ($none): ?>
            <span class="badge" style="background:var(--grey-lt);color:var(--grey-dk);">nothing to show</span>
          <?php else: ?>
            <?php if ($r['target']): ?><div style="font-weight:600;font-size:.85rem;"><?= h($r['target']) ?></div><?php endif; ?>
            <?php if ($r['target_sub']): ?><div style="font-size:.72rem;color:var(--grey-mid);"><?= $type === 'route' ? '✈ ' : '' ?><?= h($r['target_sub']) ?></div><?php endif; ?>
          <?php endif; ?>
        </td>
        <?php if ($type === 'lodge'): ?><td><?= $r['meal_basis'] ? '<span class="badge status-quoted">' . h($r['meal_basis']) . '</span>' : '' ?></td><?php endif; ?>
        <td style="font-size:.72rem;color:var(--grey-mid);"><?= h($r['created_by'] ?? '') ?><br><?= h(substr((string)$r['created_at'], 0, 10)) ?></td>
        <td>
          <?php if ($can_edit): ?>
          <form method="POST" action="<?= h($back) ?>" style="display:inline;">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <?php if ($lodge_fil): ?><input type="hidden" name="lodge_id_filter" value="<?= $lodge_fil ?>"><?php endif; ?>
            <button class="btn btn-danger btn-sm" onclick="return confirm('Delete this alias? The text will be asked again at the next import.')">Delete</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php else: ?>
      <tr><td colspan="<?= $type === 'lodge' ? 5 : 4 ?>"><div class="empty-state"><div class="icon">🔤</div><p>No aliases<?= $q !== '' ? ' for this search' : ' yet' ?>.</p></div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
</main>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
