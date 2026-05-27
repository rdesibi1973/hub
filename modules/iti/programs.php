<?php
/**
 * modules/iti/programs.php
 * Lista programmi SAMPLE e PERSONAL
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';

$db       = db();
$_cu      = current_user();
$can_edit = in_array($_cu['role_name'], ['admin', 'manager']);

$tab    = in_array($_GET['type'] ?? '', ['sample','personal']) ? $_GET['type'] : 'sample';
$action = $_REQUEST['action'] ?? '';
$id     = (int)($_REQUEST['id'] ?? 0);

// ── POST: crea nuovo SAMPLE ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add' && $can_edit) {
    $title_en         = trim($_POST['title_en'] ?? '');
    $display_language = $_POST['display_language'] ?? 'en';

    if ($title_en === '') {
        iti_flash_set('error', 'Title is required.');
        iti_redirect("programs.php?type=sample&action=add");
    }

    $db->prepare(
        'INSERT INTO iti_programs
         (program_type,
          title_en,title_it,title_fr,title_es,title_de,
          subtitle_en,subtitle_it,subtitle_fr,subtitle_es,subtitle_de,
          duration_days,pax_adults,pax_children,
          status,display_language,display_currency,created_by)
         VALUES
         ("sample",?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $title_en,'','','','',
        '','','','','',
        1,2,0,
        'draft',$display_language,'USD',$_cu['username'] ?? 'system',
    ]);
    $new_id = (int)$db->lastInsertId();

    iti_flash_set('success', 'Sample program created. Now build the itinerary.');
    iti_redirect(ITI_MODULE_URL . "/program_edit.php?id={$new_id}");
}

// ── DELETE ───────────────────────────────────────────────────
if ($action === 'delete' && $id && $can_edit) {
    // Soft delete: cambia status in cancelled
    $db->prepare("UPDATE iti_programs SET status='cancelled' WHERE id=?")->execute([$id]);
    iti_flash_set('success', 'Program cancelled.');
    iti_redirect("programs.php?type={$tab}");
}

// ── Duplicate SAMPLE ─────────────────────────────────────────
if ($action === 'duplicate' && $id && $can_edit) {
    $src = iti_get_program($id);
    if ($src) {
        $db->prepare(
            'INSERT INTO iti_programs
             (program_type,sample_program_id,terms_id,
              title_en,title_it,title_fr,title_es,title_de,
              subtitle_en,subtitle_it,subtitle_fr,subtitle_es,subtitle_de,
              duration_days,pax_adults,pax_children,flights_included,
              status,display_language,display_currency,created_by)
             SELECT "sample",id,terms_id,
              CONCAT(title_en," (copy)"),title_it,title_fr,title_es,title_de,
              subtitle_en,subtitle_it,subtitle_fr,subtitle_es,subtitle_de,
              duration_days,pax_adults,pax_children,flights_included,
              "draft",display_language,display_currency,?
             FROM iti_programs WHERE id=?'
        )->execute([$_cu['username'] ?? 'system', $id]);
        $new_id = (int)$db->lastInsertId();

        // Duplica giorni + attività + voli
        foreach (iti_get_program_days($id) as $day) {
            $db->prepare(
                'INSERT INTO iti_program_days
                 (program_id,day_number,day_title_en,day_title_it,day_title_fr,day_title_es,day_title_de,
                  start_lodge_id,end_lodge_id,transfer_route_id,
                  narrative_en,narrative_it,narrative_fr,narrative_es,narrative_de,
                  meal_breakfast,meal_lunch,meal_dinner)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $new_id,$day['day_number'],
                $day['day_title_en'],$day['day_title_it'],$day['day_title_fr'],
                $day['day_title_es'],$day['day_title_de'],
                $day['start_lodge_id'],$day['end_lodge_id'],$day['transfer_route_id'],
                $day['narrative_en'],$day['narrative_it'],$day['narrative_fr'],
                $day['narrative_es'],$day['narrative_de'],
                $day['meal_breakfast'],$day['meal_lunch'],$day['meal_dinner'],
            ]);
            $new_day_id = (int)$db->lastInsertId();

            foreach (iti_get_day_activities((int)$day['id']) as $a) {
                $db->prepare('INSERT INTO iti_day_activities (program_day_id,activity_id,sort_order,custom_note_en,custom_note_it,custom_note_fr,custom_note_es,custom_note_de) VALUES (?,?,?,?,?,?,?,?)')->execute([$new_day_id,$a['activity_id'],$a['sort_order'],$a['custom_note_en'],$a['custom_note_it'],$a['custom_note_fr'],$a['custom_note_es'],$a['custom_note_de']]);
            }
            foreach (iti_get_day_flights((int)$day['id']) as $fl) {
                $db->prepare('INSERT INTO iti_day_flights (program_day_id,flight_route_id,departure_time,arrival_time,sort_order,note_en,note_it,note_fr,note_es,note_de) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$new_day_id,$fl['flight_route_id'],$fl['departure_time'],$fl['arrival_time'],$fl['sort_order'],$fl['note_en'],$fl['note_it'],$fl['note_fr'],$fl['note_es'],$fl['note_de']]);
            }
        }

        // Prezzi + inclusi
        foreach (iti_get_program_prices($id) as $cat => $p) {
            $db->prepare('INSERT INTO iti_program_prices (program_id,price_category,price_per_pax_usd,price_per_pax_eur,single_suppl_usd,single_suppl_eur,child_price_usd,child_price_eur,min_pax,valid_from,valid_to,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$new_id,$cat,$p['price_per_pax_usd'],$p['price_per_pax_eur'],$p['single_suppl_usd'],$p['single_suppl_eur'],$p['child_price_usd'],$p['child_price_eur'],$p['min_pax'],$p['valid_from'],$p['valid_to'],$p['notes']]);
        }
        foreach (iti_get_program_inclusions($id) as $inc) {
            $db->prepare('INSERT INTO iti_program_inclusions (program_id,item_type,standard_inclusion_id,text_en,text_it,text_fr,text_es,text_de,sort_order) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$new_id,$inc['item_type'],$inc['standard_inclusion_id'],$inc['text_en'],$inc['text_it'],$inc['text_fr'],$inc['text_es'],$inc['text_de'],$inc['sort_order']]);
        }

        iti_flash_set('success', 'Program duplicated.');
        iti_redirect(ITI_MODULE_URL . "/program_edit.php?id={$new_id}");
    }
}

// ── Carica dati lista ────────────────────────────────────────
$search  = trim($_GET['q']      ?? '');
$fstatus = $_GET['status']      ?? '';
$programs = iti_get_programs($tab, array_filter(['q' => $search, 'status' => $fstatus]));
$terms    = iti_get_terms();

$page_title = 'Programs — Itinerary Builder';
$extra_css = iti_extra_css();
include __DIR__ . '/../../includes/layout_header.php';
?>
<main>
<?php iti_nav('Programs'); ?>
<?php iti_flash_render(); ?>

<?php if ($action === 'add' && $tab === 'sample' && $can_edit): ?>
<!-- ── FORM NUOVO SAMPLE ── -->
<div class="page-header">
  <div><h2>New Sample Program</h2><div class="sub">Itinerary Builder › Programs</div></div>
  <a href="programs.php?type=sample" class="btn btn-outline btn-sm">← Back</a>
</div>

<div class="form-card" style="max-width:520px;">
<form method="POST" action="programs.php?type=sample&action=add">

  <div class="form-group">
    <label>Program Title <span style="color:var(--red)">*</span></label>
    <input type="text" name="title_en" maxlength="200" required
           placeholder="e.g. 7 Days Northern Circuit Classic"
           style="font-size:1rem;">
    <span class="form-hint">You can add translations in the editor after creation.</span>
  </div>

  <div class="form-group">
    <label>Language</label>
    <select name="display_language"><?= iti_options(ITI_LANG_LABELS, 'en') ?></select>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-red">+ Create &amp; Build Itinerary →</button>
    <a href="programs.php?type=sample" class="btn btn-outline">Cancel</a>
  </div>
</form>
</div>

<?php else: ?>
<!-- ── LISTA ── -->

<!-- Tabs -->
<div style="display:flex;gap:0;border-bottom:2px solid var(--grey-lt);margin-bottom:24px;">
  <a href="programs.php?type=sample"
     style="padding:10px 22px;font-size:.82rem;font-weight:700;text-decoration:none;border-bottom:2px solid <?= $tab==='sample'?'var(--red)':'transparent' ?>;margin-bottom:-2px;color:<?= $tab==='sample'?'var(--red)':'var(--grey-mid)' ?>;">
    📋 Sample Programs
  </a>
  <a href="programs.php?type=personal"
     style="padding:10px 22px;font-size:.82rem;font-weight:700;text-decoration:none;border-bottom:2px solid <?= $tab==='personal'?'var(--red)':'transparent' ?>;margin-bottom:-2px;color:<?= $tab==='personal'?'var(--red)':'var(--grey-mid)' ?>;">
    👤 Personal Programs
  </a>
</div>

<div class="page-header" style="margin-bottom:16px;">
  <div>
    <h2><?= $tab==='sample'?'Sample Programs':'Personal Programs' ?></h2>
    <div class="sub"><?= count($programs) ?> program<?= count($programs)!==1?'s':'' ?></div>
  </div>
  <?php if ($tab==='sample' && $can_edit): ?>
  <a href="programs.php?type=sample&action=add" class="btn btn-red">+ New Sample</a>
  <?php endif; ?>
</div>

<form method="GET" action="programs.php" class="filters">
  <input type="hidden" name="type" value="<?= h($tab) ?>">
  <div><label>Search</label><input type="text" name="q" placeholder="Title, client…" value="<?= h($search) ?>"></div>
  <div>
    <label>Status</label>
    <select name="status">
      <option value="">All statuses</option>
      <?= iti_options(ITI_PROGRAM_STATUSES, $fstatus ?: null) ?>
    </select>
  </div>
  <div style="display:flex;gap:8px;align-items:flex-end;">
    <button type="submit" class="btn btn-outline btn-sm">🔍 Filter</button>
    <?php if ($search||$fstatus): ?><a href="programs.php?type=<?= h($tab) ?>" class="btn btn-outline btn-sm">✕ Clear</a><?php endif; ?>
  </div>
</form>

<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Title</th>
        <?php if ($tab==='personal'): ?><th>Client</th><?php endif; ?>
        <th>Duration</th>
        <th>Pax</th>
        <th>Flights</th>
        <th>Status</th>
        <th>Created</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php if ($programs): ?>
      <?php foreach ($programs as $p): ?>
      <tr>
        <td>
          <div style="font-weight:600;"><?= h($p['title_en']) ?></div>
          <?php if ($p['subtitle_en']): ?><div style="font-size:.72rem;color:var(--grey-mid);"><?= h($p['subtitle_en']) ?></div><?php endif; ?>
        </td>
        <?php if ($tab==='personal'): ?>
        <td>
          <div style="font-size:.83rem;"><?= h($p['client_name'] ?? '—') ?></div>
          <?php if ($p['agent_name']): ?><div style="font-size:.7rem;color:var(--grey-mid);"><?= h($p['agent_name']) ?></div><?php endif; ?>
        </td>
        <?php endif; ?>
        <td style="white-space:nowrap;"><?= iti_duration_label((int)$p['duration_days']) ?></td>
        <td style="font-size:.82rem;"><?= $p['pax_adults'] ?>A<?= $p['pax_children']?'+'.($p['pax_children']).'C':'' ?></td>
        <td style="text-align:center;"><?= $p['flights_included'] ? '✈️' : '<span class="text-muted">—</span>' ?></td>
        <td><span class="badge <?= ITI_PROGRAM_STATUS_BADGE[$p['status']] ?? '' ?>"><?= h($p['status']) ?></span></td>
        <td style="font-size:.75rem;color:var(--grey-mid);white-space:nowrap;"><?= date('d M Y',strtotime($p['created_at'])) ?></td>
        <td>
          <div class="gap-8">
            <a href="program_edit.php?id=<?= $p['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
            <a href="program_view.php?id=<?= $p['id'] ?>" class="btn btn-outline btn-sm">Preview</a>
            <?php if ($tab==='sample' && $can_edit): ?>
            <a href="programs.php?type=sample&action=duplicate&id=<?= $p['id'] ?>"
               class="btn btn-outline btn-sm btn-grey"
               onclick="return confirm('Duplicate this sample program?')">Duplicate</a>
            <?php endif; ?>
            <?php if ($can_edit): ?>
            <a href="programs.php?type=<?= $tab ?>&action=delete&id=<?= $p['id'] ?>"
               class="btn btn-danger btn-sm"
               onclick="return confirm('Cancel this program?')">Cancel</a>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php else: ?>
      <tr><td colspan="<?= $tab==='personal'?8:7 ?>">
        <div class="empty-state">
          <div class="icon"><?= $tab==='sample'?'📋':'👤' ?></div>
          <p>No <?= $tab ?> programs found<?= ($search||$fstatus)?' for the selected filters.':' yet.' ?></p>
          <?php if ($tab==='sample' && $can_edit && !$search): ?>
          <p style="margin-top:12px;"><a href="programs.php?type=sample&action=add" class="btn btn-red btn-sm">+ Create first sample</a></p>
          <?php endif; ?>
        </div>
      </td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
</main>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
