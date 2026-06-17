<?php
/**
 * modules/iti/transfers.php
 * CRUD Transfer Routes + Flight Routes (tabs)
 */
ob_start(); // buffer output so stray warnings can't break redirect headers
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
            'distance_km'      => (($_POST['distance_km'] ?? '') !== '') ? (int)$_POST['distance_km'] : null,
            'road_type'        => $_POST['road_type'] ?? 'mixed',
            'is_active'        => isset($_POST['is_active']) ? 1 : 0,
        ];
        foreach (ITI_LANGS as $lang) $f["notes_{$lang}"] = trim($_POST["notes_{$lang}"] ?? '');

        if (!$f['from_destination'] || !$f['to_destination']) {
            iti_flash_set('error', 'From and To destinations are required.');
            iti_redirect("transfers.php?tab=road&action={$action}" . ($id ? "&id={$id}" : ''));
        }

        try {
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
        } catch (\PDOException $e) {
            error_log('ITI transfer save failed: ' . $e->getMessage());
            iti_flash_set('error', 'Could not save the route: ' . $e->getMessage());
            iti_redirect("transfers.php?tab=road&action={$action}" . ($id ? "&id={$id}" : ''));
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

// ── Filtri lista ────────────────────────────────────────────
$search_road    = trim($_GET['q']         ?? '');
$filter_road    = $_GET['road_type']      ?? '';
$filter_active  = $_GET['active']         ?? '';
$search_flight  = trim($_GET['q']         ?? '');
$filter_op      = trim($_GET['operator']  ?? '');

$road_filters   = array_filter(['q'=>$search_road,'road_type'=>$filter_road,'active'=>$filter_active], fn($v)=>$v!=='');
$flight_filters = array_filter(['q'=>$search_flight,'operator'=>$filter_op,'active'=>$filter_active], fn($v)=>$v!=='');

// ── Dati lista ──────────────────────────────────────────────
$road_routes   = iti_get_transfer_routes($road_filters);
$flight_routes = iti_get_flight_routes($flight_filters);
$dest_map      = iti_destinations_map();
$has_road_filters   = $search_road   || $filter_road  || $filter_active !== '';
$has_flight_filters = $search_flight || $filter_op    || $filter_active !== '';

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
      <select name="from_destination" id="tr_from" required>
        <?= iti_options($dest_map, $row['from_destination'] ?? null, '— Select —') ?>
      </select>
    </div>
    <div class="form-group">
      <label>To <span style="color:var(--red)">*</span></label>
      <select name="to_destination" id="tr_to" required>
        <?= iti_options($dest_map, $row['to_destination'] ?? null, '— Select —') ?>
      </select>
    </div>
    <div class="form-group">
      <label>Duration (minutes)</label>
      <input type="number" name="duration_min" id="tr_dur" min="0" value="<?= (int)($row['duration_min'] ?? 0) ?>">
    </div>
    <div class="form-group">
      <label>Distance (km)</label>
      <input type="number" name="distance_km" id="tr_km" min="0" value="<?= $row['distance_km'] ?? '' ?>">
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

  <div class="form-section-title">Notes <span style="font-weight:400;font-size:.8rem;color:var(--grey-mid)">× 5 languages</span>
    <a href="#" id="tr_regen" style="display:none;margin-left:10px;font-size:.75rem;font-weight:600;color:var(--red);text-decoration:none;">↻ Regenerate from route data</a>
  </div>
  <?php foreach (ITI_LANGS as $lang): ?>
  <div class="form-group" style="margin-bottom:12px;">
    <label><?= ITI_LANG_LABELS[$lang] ?></label>
    <textarea name="notes_<?= $lang ?>" id="tr_notes_<?= $lang ?>" data-lang="<?= $lang ?>" class="tr-note"><?= h($row["notes_{$lang}"] ?? '') ?></textarea>
  </div>
  <?php endforeach; ?>

  <script>
  (function(){
    var from = document.getElementById('tr_from');
    var to   = document.getElementById('tr_to');
    var dur  = document.getElementById('tr_dur');
    var km   = document.getElementById('tr_km');
    var regenLink = document.getElementById('tr_regen');
    if (!from || !to) return;

    var notes = {};
    var areas = document.querySelectorAll('.tr-note');
    for (var i=0;i<areas.length;i++) notes[areas[i].getAttribute('data-lang')] = areas[i];

    // Templates per language. {from} {to} {km} {time}
    var T = {
      en: 'Road transfer from {from} to {to}{km}{time}.',
      it: 'Trasferimento su strada da {from} a {to}{km}{time}.',
      fr: 'Transfert routier de {from} à {to}{km}{time}.',
      es: 'Traslado por carretera de {from} a {to}{km}{time}.',
      de: 'Straßentransfer von {from} nach {to}{km}{time}.'
    };
    var KMW = { en:' — approx. {n} km', it:' — circa {n} km', fr:' — environ {n} km', es:' — aprox. {n} km', de:' — ca. {n} km' };
    function fmtTime(min, lang){
      min = parseInt(min,10) || 0;
      if (min <= 0) return '';
      var h = Math.floor(min/60), m = min%60;
      var hUnit = (lang==='de') ? 'Std.' : 'h';
      var parts = [];
      if (h > 0) parts.push(h + ' ' + hUnit);
      if (m > 0) parts.push(m + ' min');
      return ', ' + parts.join(' ');
    }
    function selText(sel){
      if (!sel || sel.selectedIndex < 0) return '';
      var t = sel.options[sel.selectedIndex].text || '';
      if (t.indexOf('—') === 0) return '';
      return t.trim();
    }
    function build(lang){
      var f = selText(from), t = selText(to);
      if (!f || !t) return '';
      var kmVal = parseInt(km && km.value, 10) || 0;
      var kmStr = kmVal > 0 ? KMW[lang].replace('{n}', kmVal) : '';
      var timeStr = fmtTime(dur && dur.value, lang);
      return T[lang]
        .replace('{from}', f).replace('{to}', t)
        .replace('{km}', kmStr).replace('{time}', timeStr);
    }

    var lastAuto = {};
    for (var lang in notes) { if (notes.hasOwnProperty(lang)) lastAuto[lang] = notes[lang].value; }
    function isAuto(lang){
      var v = notes[lang].value.trim();
      return v === '' || v === (lastAuto[lang]||'').trim();
    }
    function anyManual(){
      for (var lang in notes){ if (notes.hasOwnProperty(lang) && !isAuto(lang)) return true; }
      return false;
    }
    function regenerate(force){
      for (var lang in notes){
        if (!notes.hasOwnProperty(lang)) continue;
        if (force || isAuto(lang)){
          var v = build(lang);
          notes[lang].value = v;
          lastAuto[lang] = v;
        }
      }
    }
    function onRouteChange(){
      if (anyManual()) regenLink.style.display = 'inline';
      else regenLink.style.display = 'none';
      regenerate(false);
    }
    [from, to, dur, km].forEach(function(el){
      if (!el) return;
      el.addEventListener('change', onRouteChange);
      el.addEventListener('input', onRouteChange);
    });
    for (var lang in notes){
      if (!notes.hasOwnProperty(lang)) continue;
      notes[lang].addEventListener('input', function(){
        if (anyManual()) regenLink.style.display = 'inline';
      });
    }
    regenLink.addEventListener('click', function(e){
      e.preventDefault();
      if (confirm('Overwrite all 5 language notes with text generated from the route data?')){
        regenerate(true);
        regenLink.style.display = 'none';
      }
    });
  })();
  </script>

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
    🚗 Road Transfers (<?= count($road_routes) ?>)
  </a>
  <a href="transfers.php?tab=flight"
     style="padding:10px 22px;font-size:.82rem;font-weight:700;text-decoration:none;border-bottom:2px solid <?= $tab==='flight'?'var(--red)':'transparent' ?>;margin-bottom:-2px;color:<?= $tab==='flight'?'var(--red)':'var(--grey-mid)' ?>;">
    ✈️ Internal Flights (<?= count($flight_routes) ?>)
  </a>
</div>

<?php if ($tab === 'road'): ?>

<div class="page-header" style="margin-bottom:16px;">
  <div><h2>Road Transfers</h2><div class="sub"><?= count($road_routes) ?> routes<?= $has_road_filters ? ' found' : ' total' ?></div></div>
  <?php if ($can_edit): ?><a href="transfers.php?tab=road&action=add" class="btn btn-red">+ New Route</a><?php endif; ?>
</div>

<form method="GET" action="transfers.php" class="filters">
  <input type="hidden" name="tab" value="road">
  <div class="filter-search">
    <label>Search</label>
    <input type="text" name="q" placeholder="From or to destination…" value="<?= h($search_road) ?>">
  </div>
  <div class="filter-sm">
    <label>Road Type</label>
    <select name="road_type">
      <option value="">All types</option>
      <?= iti_options(ITI_ROAD_TYPES, $filter_road ?: null) ?>
    </select>
  </div>
  <div class="filter-sm">
    <label>Status</label>
    <select name="active">
      <option value="">All</option>
      <option value="1" <?= $filter_active==='1'?'selected':'' ?>>Active</option>
      <option value="0" <?= $filter_active==='0'?'selected':'' ?>>Inactive</option>
    </select>
  </div>
  <div class="filter-actions">
    <button type="submit" class="btn btn-red btn-sm">🔍 Search</button>
    <?php if ($has_road_filters): ?><a href="transfers.php?tab=road" class="btn btn-outline btn-sm">✕ Clear</a><?php endif; ?>
  </div>
</form>

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
  <div><h2>Internal Flights</h2><div class="sub"><?= count($flight_routes) ?> routes<?= $has_flight_filters ? ' found' : ' total' ?></div></div>
  <?php if ($can_edit): ?>
  <a href="transfers.php?tab=flight&action=add" class="btn btn-red">+ New Route</a>
  <?php endif; ?>
</div>

<form method="GET" action="transfers.php" class="filters">
  <input type="hidden" name="tab" value="flight">
  <div class="filter-search">
    <label>Search</label>
    <input type="text" name="q" placeholder="Airport, code, operator…" value="<?= h($search_flight) ?>">
  </div>
  <div class="filter-sm">
    <label>Operator</label>
    <select name="operator">
      <option value="">All operators</option>
      <?php
        $ops = array_unique(array_filter(array_column(iti_get_flight_routes(), 'operator')));
        sort($ops);
        foreach ($ops as $op): ?>
        <option value="<?= h($op) ?>" <?= $filter_op===$op?'selected':'' ?>><?= h($op) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-sm">
    <label>Status</label>
    <select name="active">
      <option value="">All</option>
      <option value="1" <?= $filter_active==='1'?'selected':'' ?>>Active</option>
      <option value="0" <?= $filter_active==='0'?'selected':'' ?>>Inactive</option>
    </select>
  </div>
  <div class="filter-actions">
    <button type="submit" class="btn btn-red btn-sm">🔍 Search</button>
    <?php if ($has_flight_filters): ?><a href="transfers.php?tab=flight" class="btn btn-outline btn-sm">✕ Clear</a><?php endif; ?>
  </div>
</form>

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
