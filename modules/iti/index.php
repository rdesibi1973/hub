<?php
/**
 * modules/iti/index.php
 * Dashboard — Itinerary Builder
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';

$db  = db();
$_cu = current_user();

// ── Statistiche rapide ──────────────────────────────────────
$stat_samples     = (int)$db->query("SELECT COUNT(*) FROM iti_programs WHERE program_type='sample' AND status != 'cancelled'")->fetchColumn();
$stat_personal    = (int)$db->query("SELECT COUNT(*) FROM iti_programs WHERE program_type='personal' AND status != 'cancelled'")->fetchColumn();
$stat_confirmed   = (int)$db->query("SELECT COUNT(*) FROM iti_programs WHERE program_type='personal' AND status='confirmed'")->fetchColumn();
$stat_requests    = (int)$db->query("SELECT COUNT(*) FROM iti_requests WHERE status IN ('open','quoted')")->fetchColumn();
$stat_destinations= (int)$db->query("SELECT COUNT(*) FROM iti_destinations WHERE is_active=1")->fetchColumn();
$stat_lodges      = (int)$db->query("SELECT COUNT(*) FROM iti_lodges WHERE is_active=1")->fetchColumn();

// ── Ultimi programmi PERSONAL ───────────────────────────────
$recent_programs = $db->query(
    "SELECT p.*, r.client_name, r.agent_name
       FROM iti_programs p
       LEFT JOIN iti_requests r ON r.id = p.request_id
      WHERE p.program_type = 'personal'
      ORDER BY p.id DESC LIMIT 8"
)->fetchAll();

// ── Richieste aperte ────────────────────────────────────────
$open_requests = $db->query(
    "SELECT * FROM iti_requests WHERE status = 'open' ORDER BY id DESC LIMIT 6"
)->fetchAll();

$page_title = 'Itinerary Builder — Savannah Explorers';
$extra_css = iti_extra_css();
include __DIR__ . '/../../includes/layout_header.php';
?>

<main>

<?php iti_flash_render(); ?>

<div class="page-header">
  <div>
    <h2>Itinerary Builder</h2>
    <div class="sub">Savannah Explorers — Program management</div>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="<?= ITI_MODULE_URL ?>/requests.php?action=add" class="btn btn-outline">+ New Request</a>
    <a href="<?= ITI_MODULE_URL ?>/programs.php?type=sample&action=add" class="btn btn-red">+ New Sample</a>
  </div>
</div>

<!-- ── Stat cards ── -->
<div class="stat-grid">
  <div class="stat-card red">
    <div class="stat-label">Sample Programs</div>
    <div class="stat-value"><?= $stat_samples ?></div>
    <div class="stat-sub"><a href="<?= ITI_MODULE_URL ?>/programs.php?type=sample" style="color:var(--red);text-decoration:none;">View all →</a></div>
  </div>
  <div class="stat-card amber">
    <div class="stat-label">Open Requests</div>
    <div class="stat-value"><?= $stat_requests ?></div>
    <div class="stat-sub"><a href="<?= ITI_MODULE_URL ?>/requests.php" style="color:var(--amber);text-decoration:none;">View all →</a></div>
  </div>
  <div class="stat-card blue">
    <div class="stat-label">Personal Programs</div>
    <div class="stat-value"><?= $stat_personal ?></div>
    <div class="stat-sub"><?= $stat_confirmed ?> confirmed</div>
  </div>
  <div class="stat-card green">
    <div class="stat-label">Destinations</div>
    <div class="stat-value"><?= $stat_destinations ?></div>
    <div class="stat-sub"><?= $stat_lodges ?> active lodges</div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:8px;">

  <!-- ── Recent personal programs ── -->
  <div>
    <div class="section-label">Recent Personal Programs</div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Client</th>
            <th>Program</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($recent_programs): ?>
            <?php foreach ($recent_programs as $p): ?>
            <tr>
              <td>
                <div style="font-weight:600;font-size:.83rem;"><?= h($p['client_name'] ?? '—') ?></div>
                <div style="font-size:.7rem;color:var(--grey-mid);"><?= h($p['agent_name'] ?? '') ?></div>
              </td>
              <td>
                <div style="font-size:.82rem;"><?= h($p['title_en']) ?></div>
                <div style="font-size:.7rem;color:var(--grey-mid);"><?= iti_duration_label((int)$p['duration_days']) ?></div>
              </td>
              <td><span class="badge <?= ITI_PROGRAM_STATUS_BADGE[$p['status']] ?? '' ?>"><?= h($p['status']) ?></span></td>
              <td>
                <a href="<?= ITI_MODULE_URL ?>/program_edit.php?id=<?= $p['id'] ?>"
                   class="btn btn-outline btn-sm">Edit</a>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="4">
              <div class="empty-state" style="padding:24px;">
                <div class="icon">📋</div>
                <p>No personal programs yet.</p>
              </div>
            </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Open requests ── -->
  <div>
    <div class="section-label">Open Requests</div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Client</th>
            <th>Pax</th>
            <th>Dates</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($open_requests): ?>
            <?php foreach ($open_requests as $r): ?>
            <tr>
              <td>
                <div style="font-weight:600;font-size:.83rem;"><?= h($r['client_name']) ?></div>
                <div style="font-size:.7rem;color:var(--grey-mid);"><?= h($r['agent_name'] ?? '') ?></div>
              </td>
              <td style="font-size:.82rem;"><?= $r['pax_adults'] ?>A<?= $r['pax_children'] ? '+' . $r['pax_children'] . 'C' : '' ?></td>
              <td style="font-size:.75rem;color:var(--grey-mid);white-space:nowrap;">
                <?= $r['arrival_date'] ? date('d M', strtotime($r['arrival_date'])) : '—' ?>
              </td>
              <td>
                <a href="<?= ITI_MODULE_URL ?>/requests.php?action=view&id=<?= $r['id'] ?>"
                   class="btn btn-outline btn-sm">View</a>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="4">
              <div class="empty-state" style="padding:24px;">
                <div class="icon">✅</div>
                <p>No open requests.</p>
              </div>
            </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- ── Quick links master data ── -->
<div class="section-label" style="margin-top:32px;">Master Data</div>
<div style="display:flex;gap:10px;flex-wrap:wrap;">
  <a href="<?= ITI_MODULE_URL ?>/destinations.php" class="btn btn-outline">🗺️ Destinations (<?= $stat_destinations ?>)</a>
  <a href="<?= ITI_MODULE_URL ?>/lodges.php"        class="btn btn-outline">🏕️ Lodges (<?= $stat_lodges ?>)</a>
  <a href="<?= ITI_MODULE_URL ?>/transfers.php"     class="btn btn-outline">🚗 Transfers &amp; Flights</a>
  <a href="<?= ITI_MODULE_URL ?>/activities.php"    class="btn btn-outline">🦁 Activities</a>
</div>

</main>

<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
