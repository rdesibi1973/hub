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
    $ref_number       = trim($_POST['ref_number'] ?? '');
    $display_language = $_POST['display_language'] ?? 'en';

    if ($title_en === '') {
        iti_flash_set('error', 'Title is required.');
        iti_redirect("programs.php?type=sample&action=add");
    }

    $db->prepare(
        'INSERT INTO iti_programs
         (program_type,
          ref_number,
          title_en,title_it,title_fr,title_es,title_de,
          subtitle_en,subtitle_it,subtitle_fr,subtitle_es,subtitle_de,
          duration_days,pax_adults,pax_children,flights_included,
          status,display_language,display_currency,created_by)
         VALUES
         ("sample",?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $ref_number ?: null,
        $title_en,'','','','',
        '','','','','',
        1,2,0,0,
        'draft',$display_language,'USD',$_cu['username'] ?? 'system',
    ]);
    $new_id = (int)$db->lastInsertId();

    iti_flash_set('success', 'Sample program created. Now build the itinerary.');
    iti_redirect("program_edit.php?id={$new_id}");
}

// ── SOFT DELETE (cancel) ─────────────────────────────────────
if ($action === 'delete' && $id && $can_edit) {
    $db->prepare("UPDATE iti_programs SET status='cancelled' WHERE id=?")->execute([$id]);
    iti_flash_set('success', 'Program cancelled.');
    iti_redirect("programs.php?type={$tab}");
}

// ── HARD DELETE (permanent) ──────────────────────────────────
if ($action === 'hard_delete' && $id && $can_edit) {
    // Verifica che sia cancelled prima di eliminare definitivamente
    $chk = $db->prepare("SELECT status FROM iti_programs WHERE id=?");
    $chk->execute([$id]);
    $row = $chk->fetch();
    if ($row && $row['status'] === 'cancelled') {
        $db->prepare("DELETE FROM iti_programs WHERE id=?")->execute([$id]);
        iti_flash_set('success', 'Program permanently deleted.');
    } else {
        iti_flash_set('error', 'Only cancelled programs can be permanently deleted.');
    }
    iti_redirect("programs.php?type={$tab}");
}

// ── Duplicate (same type or cross-type) ──────────────────────
if ($action === 'duplicate' && $id && $can_edit) {
    // dest_type: 'sample' | 'personal' — defaults to same as source
    $allowed_types = ['sample', 'personal'];
    $dest_type = in_array($_GET['dest_type'] ?? '', $allowed_types)
        ? $_GET['dest_type']
        : $tab;

    // From a Hub leads request ("Create from sample"): personal copy linked to it, sample title kept.
    $lead_id = (int)($_GET['lead_request_id'] ?? 0);
    $set     = [];
    if ($lead_id) {
        iti_ensure_lead_link();
        $lead = iti_get_lead_request($lead_id);
        if (!$lead) {
            iti_flash_set('error', "Hub request #{$lead_id} not found.");
            iti_redirect("programs.php?type={$tab}");
        }
        $src_p     = iti_get_program($id);
        $dest_type = 'personal';
        $set       = ['lead_request_id' => $lead_id, 'title_en' => $src_p['title_en'] ?? ''];
    }
    try {
        $new_id = iti_duplicate_program($id, $dest_type, $_cu['username'] ?? 'system', $set);
    } catch (Exception $e) {
        error_log('ITI duplicate program #' . $id . ': ' . $e->getMessage());
        iti_flash_set('error', 'Duplicate failed, nothing was copied: ' . $e->getMessage());
        iti_redirect("programs.php?type={$tab}");
    }
    if ($new_id) {
        $dest_label = $dest_type === 'personal' ? 'Personal' : 'Sample';
        iti_flash_set('success', "Program duplicated as {$dest_label}.");
        iti_redirect("program_edit.php?id={$new_id}");
    }
}

// ── Filtri ───────────────────────────────────────────────────
$search  = trim($_GET['q']      ?? '');
$fref    = trim($_GET['ref']    ?? '');
$fstatus = $_GET['status']      ?? '';
$flang   = $_GET['lang']        ?? '';

$programs = iti_get_programs($tab, array_filter([
    'q'      => $search,
    'ref'    => $fref,
    'status' => $fstatus,
    'lang'   => $flang,
]));

$has_filters = $search || $fref || $fstatus || $flang;

$page_title = 'Programs — Itinerary Builder';
$extra_css  = iti_extra_css();
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
    <label>Ref. Number</label>
    <input type="text" name="ref_number" maxlength="60"
           placeholder="e.g. SE-2025-001">
    <span class="form-hint">Optional. Can also be set later in the editor.</span>
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

<!-- Header con titolo + pulsante Nuovo -->
<div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:6px;">
  <div>
    <h2 style="margin:0;"><?= $tab==='sample'?'Sample Programs':'Personal Programs' ?></h2>
    <div class="sub" style="margin-top:2px;">
      <?= count($programs) ?> program<?= count($programs)!==1?'s':'' ?>
      <?php if ($has_filters): ?>
        <a href="programs.php?type=<?= h($tab) ?>" style="font-size:.75rem;margin-left:8px;color:var(--grey-mid);">✕ clear filters</a>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($tab==='sample' && $can_edit): ?>
  <div style="display:flex;gap:6px">
    <a href="iti_import_sto.php" class="btn btn-outline btn-sm" title="Create Sample programmes from the STO Word files in Dropbox">⬇ Import STO</a>
    <a href="programs.php?type=sample&action=add" class="btn btn-red btn-sm">+ New Sample</a>
  </div>
  <?php endif; ?>
</div>

<!-- Filtri -->
<form method="GET" action="programs.php" class="filters">
  <input type="hidden" name="type" value="<?= h($tab) ?>">

  <div class="filter-search">
    <label>Search</label>
    <input type="text" name="q" placeholder="Title or client name…" value="<?= h($search) ?>">
  </div>

  <div class="filter-ref">
    <label>Ref. Number</label>
    <input type="text" name="ref" placeholder="e.g. SE-2025-…" value="<?= h($fref) ?>">
  </div>

  <div class="filter-sm">
    <label>Language</label>
    <select name="lang">
      <option value="">All languages</option>
      <?= iti_options(ITI_LANG_LABELS, $flang) ?>
    </select>
  </div>

  <div class="filter-sm">
    <label>Status</label>
    <select name="status">
      <option value="">All statuses</option>
      <?= iti_options(ITI_PROGRAM_STATUSES, $fstatus) ?>
    </select>
  </div>

  <div class="filter-actions">
    <button type="submit" class="btn btn-red btn-sm">🔍 Search</button>
    <?php if ($has_filters): ?>
    <a href="programs.php?type=<?= h($tab) ?>" class="btn btn-outline btn-sm">✕ Clear</a>
    <?php endif; ?>
  </div>

</form>

<!-- Tabella -->
<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th style="width:120px;">Ref. Number</th>
        <th>Title</th>
        <?php if ($tab==='personal'): ?><th>Client</th><?php endif; ?>
        <th>Lang</th>
        <th>Duration</th>
        <?php if ($tab==='personal'): ?><th>Pax</th><?php endif; ?>
        <th>Status</th>
        <th>Updated</th>
        <th style="width:1%;white-space:nowrap;"></th>
      </tr>
    </thead>
    <tbody>
    <?php if ($programs): ?>
      <?php foreach ($programs as $p): ?>
      <tr>
        <td>
          <?php if (!empty($p['ref_number'])): ?>
          <span style="font-family:monospace;font-size:.8rem;font-weight:700;color:var(--grey-dk);"><?= h($p['ref_number']) ?></span>
          <?php else: ?>
          <span style="color:var(--grey-lt);font-size:.75rem;">—</span>
          <?php endif; ?>
        </td>
        <td>
          <div style="font-weight:600;"><?= h($p['title_en']) ?></div>
          <?php if ($p['subtitle_en']): ?><div style="font-size:.72rem;color:var(--grey-mid);"><?= h($p['subtitle_en']) ?></div><?php endif; ?>
        </td>
        <?php if ($tab==='personal'): ?>
        <td>
          <div style="font-size:.83rem;"><?= h($p['client_name'] ?? '—') ?></div>
          <?php if (!empty($p['agent_name'])): ?><div style="font-size:.7rem;color:var(--grey-mid);"><?= h($p['agent_name']) ?></div><?php endif; ?>
        </td>
        <?php endif; ?>
        <td style="font-size:.78rem;text-transform:uppercase;letter-spacing:.05em;color:var(--grey-mid);"><?= h($p['display_language']) ?></td>
        <td style="white-space:nowrap;"><?= iti_duration_label((int)$p['duration_days']) ?></td>
        <?php if ($tab==='personal'): ?>
        <td style="font-size:.82rem;"><?= $p['pax_adults'] ?>A<?= $p['pax_children'] ? '+'.$p['pax_children'].'C' : '' ?></td>
        <?php endif; ?>
        <td><span class="badge <?= ITI_PROGRAM_STATUS_BADGE[$p['status']] ?? '' ?>"><?= h($p['status']) ?></span></td>
        <td style="font-size:.75rem;color:var(--grey-mid);white-space:nowrap;"><?= date('d M Y', strtotime($p['updated_at'])) ?></td>
        <td>
          <div class="gap-8" style="white-space:nowrap;">
            <a href="program_edit.php?id=<?= $p['id'] ?>" class="btn btn-outline btn-sm">✏️ Edit</a>
            <a href="program_doc.php?id=<?= $p['id'] ?>" class="btn btn-outline btn-sm" target="_blank">👁 Preview</a>
            <?php if ($can_edit): ?>
            <a href="programs.php?type=<?= $tab ?>&action=duplicate&id=<?= $p['id'] ?>&dest_type=<?= $tab ?>"
               class="btn btn-outline btn-sm"
               onclick="return confirm('Duplicate «<?= h(addslashes($p['title_en'])) ?>»?')">⧉ Duplicate</a>
            <?php if ($tab === 'sample'): ?>
            <a href="programs.php?type=sample&action=duplicate&id=<?= $p['id'] ?>&dest_type=personal"
               class="btn btn-outline btn-sm"
               onclick="return confirm('Duplicate «<?= h(addslashes($p['title_en'])) ?>» as Personal?')">⧉ Duplicate as Personal</a>
            <?php else: ?>
            <a href="programs.php?type=personal&action=duplicate&id=<?= $p['id'] ?>&dest_type=sample"
               class="btn btn-outline btn-sm"
               onclick="return confirm('Duplicate «<?= h(addslashes($p['title_en'])) ?>» as Sample?')">⧉ Duplicate as Sample</a>
            <?php endif; ?>
            <?php if ($p['status'] !== 'cancelled'): ?>
            <a href="programs.php?type=<?= $tab ?>&action=delete&id=<?= $p['id'] ?>"
               class="btn btn-danger btn-sm"
               onclick="return confirm('Delete «<?= h(addslashes($p['title_en'])) ?>»?')">🗑 Delete</a>
            <?php else: ?>
            <a href="programs.php?type=<?= $tab ?>&action=hard_delete&id=<?= $p['id'] ?>"
               class="btn btn-danger btn-sm"
               style="background:var(--red-dk,#7b1010);border-color:var(--red-dk,#7b1010);color:#fff;"
               onclick="return confirm('PERMANENTLY delete «<?= h(addslashes($p['title_en'])) ?>»? This cannot be undone.')">🗑 Delete permanently</a>
            <?php endif; ?>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php else: ?>
      <tr><td colspan="<?= $tab==='personal' ? 9 : 7 ?>">
        <div class="empty-state">
          <div class="icon"><?= $tab==='sample' ? '📋' : '👤' ?></div>
          <p>No <?= $tab ?> programs found<?= $has_filters ? ' matching the selected filters.' : ' yet.' ?></p>
          <?php if ($tab==='sample' && $can_edit && !$has_filters): ?>
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
