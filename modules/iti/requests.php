<?php
/**
 * modules/iti/requests.php
 * CRUD Richieste cliente ITI
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';

$db   = db();
$_cu  = current_user();

$action = $_REQUEST['action'] ?? '';
$id     = (int)($_REQUEST['id'] ?? 0);

// ── POST ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $f = [
        'agent_name'          => trim($_POST['agent_name']          ?? ''),
        'client_name'         => trim($_POST['client_name']         ?? ''),
        'client_email'        => trim($_POST['client_email']        ?? ''),
        'client_phone'        => trim($_POST['client_phone']        ?? ''),
        'client_nationality'  => trim($_POST['client_nationality']  ?? ''),
        'pax_adults'          => max(1, (int)($_POST['pax_adults']  ?? 1)),
        'pax_children'        => max(0, (int)($_POST['pax_children']?? 0)),
        'arrival_date'        => $_POST['arrival_date']   ?: null,
        'departure_date'      => $_POST['departure_date'] ?: null,
        'budget_category'     => $_POST['budget_category']?? null,
        'preferred_language'  => $_POST['preferred_language'] ?? 'en',
        'preferred_currency'  => $_POST['preferred_currency'] ?? 'USD',
        'source'              => trim($_POST['source'] ?? ''),
        'notes'               => trim($_POST['notes']  ?? ''),
        'status'              => $_POST['status'] ?? 'open',
    ];

    if ($f['client_name'] === '') {
        iti_flash_set('error', 'Client name is required.');
        iti_redirect("requests.php?action={$action}" . ($id ? "&id={$id}" : ''));
    }

    if ($action === 'add') {
        $db->prepare(
            'INSERT INTO iti_requests
             (agent_name,client_name,client_email,client_phone,client_nationality,
              pax_adults,pax_children,arrival_date,departure_date,budget_category,
              preferred_language,preferred_currency,source,notes,status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute(array_values($f));
        $new_id = (int)$db->lastInsertId();
        iti_flash_set('success', 'Request for "'.$f['client_name'].'" created.');
        iti_redirect("requests.php?action=view&id={$new_id}");

    } elseif ($action === 'edit' && $id) {
        $db->prepare(
            'UPDATE iti_requests SET
             agent_name=?,client_name=?,client_email=?,client_phone=?,client_nationality=?,
             pax_adults=?,pax_children=?,arrival_date=?,departure_date=?,budget_category=?,
             preferred_language=?,preferred_currency=?,source=?,notes=?,status=?
             WHERE id=?'
        )->execute([...array_values($f), $id]);
        iti_flash_set('success', 'Request updated.');
        iti_redirect("requests.php?action=view&id={$id}");
    }
}

// ── Clone SAMPLE → PERSONAL ──────────────────────────────────
if ($action === 'clone' && $id && isset($_GET['sample_id'])) {
    $sample_id     = (int)$_GET['sample_id'];
    $req           = iti_get_request($id);
    $price_cat     = $_GET['price_cat'] ?? 'rack';
    $new_prog_id   = iti_clone_sample_to_personal(
        $sample_id, $id, $price_cat,
        $req['preferred_language'] ?? 'en',
        $req['preferred_currency'] ?? 'USD'
    );
    if ($new_prog_id) {
        iti_flash_set('success', 'Program cloned successfully. You can now edit it.');
        iti_redirect(ITI_MODULE_URL . "/program_edit.php?id={$new_prog_id}");
    } else {
        iti_flash_set('error', 'Clone failed. Check that the sample exists and try again.');
        iti_redirect("requests.php?action=view&id={$id}");
    }
}

// ── Carica row ──────────────────────────────────────────────
$row = null;
if (in_array($action, ['edit','view']) && $id) {
    $row = iti_get_request($id);
    if (!$row) { iti_flash_set('error', 'Request not found.'); iti_redirect('requests.php'); }
}

// ── Lista ───────────────────────────────────────────────────
$search  = trim($_GET['q']      ?? '');
$fstatus = $_GET['status']      ?? '';
$rows    = iti_get_requests(array_filter(['q' => $search, 'status' => $fstatus]));

// Programmi SAMPLE per il modal di clone
$samples = $db->query(
    "SELECT id, title_en, duration_days FROM iti_programs WHERE program_type='sample' AND status!='cancelled' ORDER BY title_en"
)->fetchAll();

$page_title = 'Requests — Itinerary Builder';
$extra_css = iti_extra_css();
include __DIR__ . '/../../includes/layout_header.php';
?>
<main>
<?php iti_nav(
    $action==='add'   ? 'New Request'  :
   ($action==='edit'  ? 'Edit Request' :
   ($action==='view'  ? 'View Request' : 'Requests'))
); ?>
<?php iti_flash_render(); ?>

<?php if ($action === 'add' || ($action === 'edit' && $row)): ?>
<!-- ── FORM ADD/EDIT ── -->
<div class="page-header">
  <div>
    <h2><?= $action==='add'?'New Request':'Edit: '.h($row['client_name']) ?></h2>
    <div class="sub">Itinerary Builder › Requests</div>
  </div>
  <a href="requests.php" class="btn btn-outline btn-sm">← Back</a>
</div>

<div class="form-card">
<form method="POST" action="requests.php?action=<?= h($action) ?><?= $id?"&id={$id}":'' ?>">

  <div class="form-section-title">Client</div>
  <div class="form-grid">
    <div class="form-group">
      <label>Client Name <span style="color:var(--red)">*</span></label>
      <input type="text" name="client_name" maxlength="160" required value="<?= h($row['client_name'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Agent</label>
      <input type="text" name="agent_name" maxlength="100" placeholder="Agent or Agency name" value="<?= h($row['agent_name'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Email</label>
      <input type="email" name="client_email" maxlength="160" value="<?= h($row['client_email'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Phone / WhatsApp</label>
      <input type="text" name="client_phone" maxlength="60" value="<?= h($row['client_phone'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Nationality</label>
      <input type="text" name="client_nationality" maxlength="80" value="<?= h($row['client_nationality'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Source</label>
      <input type="text" name="source" maxlength="80" placeholder="website, referral, B2B…" value="<?= h($row['source'] ?? '') ?>">
    </div>
  </div>

  <div class="form-section-title">Trip Details</div>
  <div class="form-grid">
    <div class="form-group">
      <label>Adults</label>
      <input type="number" name="pax_adults" min="1" max="99" value="<?= (int)($row['pax_adults'] ?? 2) ?>">
    </div>
    <div class="form-group">
      <label>Children</label>
      <input type="number" name="pax_children" min="0" max="99" value="<?= (int)($row['pax_children'] ?? 0) ?>">
    </div>
    <div class="form-group">
      <label>Arrival Date</label>
      <input type="date" name="arrival_date" value="<?= h($row['arrival_date'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Departure Date</label>
      <input type="date" name="departure_date" value="<?= h($row['departure_date'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Budget Category</label>
      <select name="budget_category">
        <option value="">— Not specified —</option>
        <?= iti_options(ITI_LODGE_CATEGORIES, $row['budget_category'] ?? null) ?>
      </select>
    </div>
    <div class="form-group">
      <label>Language</label>
      <select name="preferred_language">
        <?= iti_options(ITI_LANG_LABELS, $row['preferred_language'] ?? 'en') ?>
      </select>
    </div>
    <div class="form-group">
      <label>Currency</label>
      <select name="preferred_currency">
        <option value="USD" <?= ($row['preferred_currency'] ?? 'USD')==='USD'?'selected':'' ?>>USD — $</option>
        <option value="EUR" <?= ($row['preferred_currency'] ?? '')==='EUR'?'selected':'' ?>>EUR — €</option>
      </select>
    </div>
    <div class="form-group">
      <label>Status</label>
      <select name="status">
        <?= iti_options(ITI_REQUEST_STATUSES, $row['status'] ?? 'open') ?>
      </select>
    </div>
    <div class="form-group full">
      <label>Notes</label>
      <textarea name="notes" class="tall"><?= h($row['notes'] ?? '') ?></textarea>
    </div>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-red"><?= $action==='add'?'+ Create Request':'💾 Save' ?></button>
    <a href="requests.php" class="btn btn-outline">Cancel</a>
  </div>
</form>
</div>

<?php elseif ($action === 'view' && $row): ?>
<!-- ── VIEW REQUEST ── -->
<div class="page-header">
  <div>
    <h2><?= h($row['client_name']) ?></h2>
    <div class="sub">Request #<?= $row['id'] ?> &nbsp;·&nbsp; <span class="badge <?= ITI_REQUEST_STATUS_BADGE[$row['status']] ?? '' ?>"><?= h($row['status']) ?></span></div>
  </div>
  <div class="gap-8">
    <a href="requests.php?action=edit&id=<?= $id ?>" class="btn btn-outline btn-sm">Edit</a>
    <a href="requests.php" class="btn btn-outline btn-sm">← Back</a>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
<!-- Client details -->
<div class="table-wrap">
  <table>
    <tbody>
      <tr><td class="detail-label" style="width:40%;background:var(--off-white);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);padding:10px 16px;">Client</td><td style="padding:10px 16px;font-weight:600;"><?= h($row['client_name']) ?></td></tr>
      <tr><td class="detail-label" style="background:var(--off-white);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);padding:10px 16px;">Agent</td><td style="padding:10px 16px;"><?= h($row['agent_name'] ?? '—') ?></td></tr>
      <tr><td class="detail-label" style="background:var(--off-white);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);padding:10px 16px;">Email</td><td style="padding:10px 16px;"><?= $row['client_email'] ? '<a href="mailto:'.h($row['client_email']).'" style="color:var(--blue);">'.h($row['client_email']).'</a>' : '—' ?></td></tr>
      <tr><td class="detail-label" style="background:var(--off-white);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);padding:10px 16px;">Phone</td><td style="padding:10px 16px;"><?= h($row['client_phone'] ?? '—') ?></td></tr>
      <tr><td class="detail-label" style="background:var(--off-white);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);padding:10px 16px;">Nationality</td><td style="padding:10px 16px;"><?= h($row['client_nationality'] ?? '—') ?></td></tr>
      <tr><td class="detail-label" style="background:var(--off-white);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);padding:10px 16px;">Pax</td><td style="padding:10px 16px;"><?= $row['pax_adults'] ?> adult<?= $row['pax_adults']!==1?'s':'' ?><?= $row['pax_children'] ? ' + '.$row['pax_children'].' child'.(int)$row['pax_children']!==1?'ren':'' : '' ?></td></tr>
      <tr><td class="detail-label" style="background:var(--off-white);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);padding:10px 16px;">Dates</td><td style="padding:10px 16px;"><?= $row['arrival_date'] ? date('d M Y', strtotime($row['arrival_date'])) : '—' ?><?= $row['departure_date'] ? ' → ' . date('d M Y', strtotime($row['departure_date'])) : '' ?></td></tr>
      <tr><td class="detail-label" style="background:var(--off-white);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);padding:10px 16px;">Budget</td><td style="padding:10px 16px;"><?= ITI_LODGE_CATEGORIES[$row['budget_category'] ?? ''] ?? '—' ?></td></tr>
      <tr><td class="detail-label" style="background:var(--off-white);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);padding:10px 16px;">Language / Currency</td><td style="padding:10px 16px;"><?= strtoupper($row['preferred_language']) ?> / <?= $row['preferred_currency'] ?></td></tr>
      <tr><td class="detail-label" style="background:var(--off-white);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);padding:10px 16px;">Source</td><td style="padding:10px 16px;"><?= h($row['source'] ?? '—') ?></td></tr>
      <?php if ($row['notes']): ?>
      <tr><td class="detail-label" style="background:var(--off-white);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);padding:10px 16px;">Notes</td><td style="padding:10px 16px;white-space:pre-wrap;"><?= h($row['notes']) ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Programs -->
<div>
  <div class="section-label">Programs</div>
  <?php
  $prog_stmt = $db->prepare("SELECT p.* FROM iti_programs p WHERE p.request_id=? ORDER BY p.id DESC");
  $prog_stmt->execute([$id]);
  $programs = $prog_stmt->fetchAll();
  ?>
  <?php if ($programs): ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Title</th><th>Duration</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($programs as $p): ?>
      <tr>
        <td style="font-weight:600;font-size:.83rem;"><?= h($p['title_en']) ?></td>
        <td style="font-size:.8rem;color:var(--grey-mid);"><?= iti_duration_label((int)$p['duration_days']) ?></td>
        <td><span class="badge <?= ITI_PROGRAM_STATUS_BADGE[$p['status']] ?? '' ?>"><?= h($p['status']) ?></span></td>
        <td>
          <div class="gap-8">
            <a href="program_edit.php?id=<?= $p['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
            <a href="program_view.php?id=<?= $p['id'] ?>" class="btn btn-outline btn-sm">Preview</a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div style="background:var(--off-white);border-radius:8px;padding:20px;text-align:center;color:var(--grey-mid);font-size:.85rem;">No programs yet.</div>
  <?php endif; ?>

  <!-- Clone from sample -->
  <?php if ($samples): ?>
  <div style="margin-top:16px;background:var(--white);border-radius:8px;padding:16px;border:1.5px dashed var(--grey-lt);">
    <div style="font-size:.78rem;font-weight:700;color:var(--grey-dk);margin-bottom:10px;">📋 Clone from Sample</div>
    <form method="GET" action="requests.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
      <input type="hidden" name="action" value="clone">
      <input type="hidden" name="id" value="<?= $id ?>">
      <div>
        <label style="font-size:.72rem;font-weight:700;color:var(--grey-dk);display:block;margin-bottom:4px;">Sample Program</label>
        <select name="sample_id" style="padding:7px 11px;border:1.5px solid var(--grey-lt);border-radius:6px;font-size:.82rem;min-width:200px;">
          <?php foreach ($samples as $s): ?>
          <option value="<?= $s['id'] ?>"><?= h($s['title_en']) ?> (<?= $s['duration_days'] ?>d)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label style="font-size:.72rem;font-weight:700;color:var(--grey-dk);display:block;margin-bottom:4px;">Price Category</label>
        <select name="price_cat" style="padding:7px 11px;border:1.5px solid var(--grey-lt);border-radius:6px;font-size:.82rem;">
          <?= iti_options(ITI_PRICE_CATEGORIES, null) ?>
        </select>
      </div>
      <button type="submit" class="btn btn-red btn-sm">Clone →</button>
    </form>
  </div>
  <?php endif; ?>
</div>
</div>

<?php else: ?>
<!-- ── LISTA RICHIESTE ── -->
<div class="page-header">
  <div><h2>Requests</h2><div class="sub"><?= count($rows) ?> request<?= count($rows)!==1?'s':'' ?></div></div>
  <a href="requests.php?action=add" class="btn btn-red">+ New Request</a>
</div>

<form method="GET" action="requests.php" class="filters">
  <div><label>Search</label><input type="text" name="q" placeholder="Client, agent…" value="<?= h($search) ?>"></div>
  <div>
    <label>Status</label>
    <select name="status">
      <option value="">All statuses</option>
      <?= iti_options(ITI_REQUEST_STATUSES, $fstatus ?: null) ?>
    </select>
  </div>
  <div style="display:flex;gap:8px;align-items:flex-end;">
    <button type="submit" class="btn btn-outline btn-sm">🔍 Filter</button>
    <?php if ($search||$fstatus): ?><a href="requests.php" class="btn btn-outline btn-sm">✕ Clear</a><?php endif; ?>
  </div>
</form>

<div class="table-wrap">
  <table>
    <thead><tr><th>Client</th><th>Agent</th><th>Pax</th><th>Dates</th><th>Budget</th><th>Lang / Curr</th><th>Programs</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if ($rows): ?>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td>
          <a href="requests.php?action=view&id=<?= $r['id'] ?>" style="font-weight:600;color:var(--black);text-decoration:none;"><?= h($r['client_name']) ?></a>
          <?php if ($r['client_email']): ?><div style="font-size:.7rem;color:var(--grey-mid);"><?= h($r['client_email']) ?></div><?php endif; ?>
        </td>
        <td class="text-muted"><?= h($r['agent_name'] ?? '—') ?></td>
        <td style="font-size:.82rem;"><?= $r['pax_adults'] ?>A<?= $r['pax_children']?'+'.($r['pax_children']).'C':'' ?></td>
        <td style="font-size:.78rem;white-space:nowrap;color:var(--grey-mid);"><?= $r['arrival_date'] ? date('d M Y',strtotime($r['arrival_date'])) : '—' ?></td>
        <td style="font-size:.78rem;"><?= ITI_LODGE_CATEGORIES[$r['budget_category'] ?? ''] ?? '—' ?></td>
        <td style="font-size:.78rem;font-family:monospace;"><?= strtoupper($r['preferred_language']) ?> / <?= $r['preferred_currency'] ?></td>
        <td style="text-align:center;">
          <?php if ((int)$r['program_count']): ?>
          <a href="requests.php?action=view&id=<?= $r['id'] ?>" style="font-weight:700;color:var(--blue);text-decoration:none;"><?= $r['program_count'] ?>p</a>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td><span class="badge <?= ITI_REQUEST_STATUS_BADGE[$r['status']] ?? '' ?>"><?= h($r['status']) ?></span></td>
        <td>
          <div class="gap-8">
            <a href="requests.php?action=view&id=<?= $r['id'] ?>" class="btn btn-outline btn-sm">View</a>
            <a href="requests.php?action=edit&id=<?= $r['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php else: ?>
      <tr><td colspan="9"><div class="empty-state"><div class="icon">📬</div><p>No requests found<?= ($search||$fstatus)?' for the selected filters.':' yet.' ?></p></div></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
</main>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
