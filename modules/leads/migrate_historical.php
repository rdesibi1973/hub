<?php
/**
 * migrate_historical.php
 * Migrates records from requests_import (Historical) into requests (production).
 * Admin / manager only.
 *
 * Status mapping (requests_import → requests):
 *   In Progress  → Inquiry
 *   Provisional  → Inquiry
 *   Confirmed    → Booked
 *   CK           → Booked
 *   Booked       → Booked
 *   Balance Due  → Booked
 *   Deposit Paid → Booked
 *   Cancelled    → Cancelled
 *   Lost         → Lost
 *   (other/NULL) → Inquiry
 */
require_once 'config.php';

if (isLeadsRestricted()) {
    http_response_code(403);
    echo 'Access denied.'; exit;
}

$db = db();

// ── Status mapping ────────────────────────────────────────────────────────────
const STATUS_MAP = [
    'In Progress'  => 'Inquiry',
    'Provisional'  => 'Inquiry',
    'Confirmed'    => 'Booked',
    'CK'           => 'Booked',
    'Booked'       => 'Booked',
    'Balance Due'  => 'Booked',
    'Deposit Paid' => 'Booked',
    'Cancelled'    => 'Cancelled',
    'Lost'         => 'Lost',
];
function mapStatus(?string $s): string {
    return STATUS_MAP[$s] ?? 'Inquiry';
}

// ── Parameters ────────────────────────────────────────────────────────────────
$year      = (int)($_REQUEST['year']   ?? 2026);
$source    = trim($_REQUEST['source']  ?? 'Agent');
$doExecute = ($_POST['action'] ?? '') === 'execute';

$allowedSources = const_source_list();
function const_source_list(): array {
    return ['Form','Email','Agent','iBot','WhatsApp','Social','Safari Bookings','Other'];
}

// ── Fetch candidate records ───────────────────────────────────────────────────
$whereYear  = $year > 0 ? 'AND YEAR(ri.date_received) = ' . (int)$year : '';
$candidates = $db->query("
    SELECT ri.id, ri.date_received, ri.customer_name, ri.agency_name,
           ri.agent_id, ri.status, ri.practice_code,
           a.name AS agent_name
    FROM requests_import ri
    LEFT JOIN agents a ON a.id = ri.agent_id
    WHERE 1=1 {$whereYear}
    ORDER BY ri.date_received DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Split into: will insert / will skip (practice_code already in requests)
$existingCodes = $db->query(
    "SELECT practice_code FROM requests WHERE practice_code IS NOT NULL AND practice_code != ''"
)->fetchAll(PDO::FETCH_COLUMN);
$existingSet = array_flip($existingCodes);

$toInsert = [];
$toSkip   = [];
foreach ($candidates as $row) {
    $pc = trim($row['practice_code'] ?? '');
    if ($pc !== '' && isset($existingSet[$pc])) {
        $toSkip[] = $row;
    } else {
        $toInsert[] = $row;
    }
}

// ── EXECUTE ───────────────────────────────────────────────────────────────────
$inserted = 0;
$execErrors = [];

if ($doExecute && !empty($toInsert)) {
    $stmt = $db->prepare("
        INSERT INTO requests
          (date_received, customer_name, source, agent_id, status,
           practice_code, notes, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");

    foreach ($toInsert as $row) {
        $notes = $row['agency_name'] ? 'Agency: ' . $row['agency_name'] : null;
        try {
            $stmt->execute([
                $row['date_received'],
                $row['customer_name'],
                $source,
                $row['agent_id'] ?: null,
                mapStatus($row['status']),
                $row['practice_code'] ?: null,
                $notes,
            ]);
            $inserted++;
        } catch (\PDOException $e) {
            $execErrors[] = "Row #{$row['id']} ({$row['customer_name']}): " . $e->getMessage();
        }
    }
}

// ── Years available ───────────────────────────────────────────────────────────
$years = $db->query(
    "SELECT DISTINCT YEAR(date_received) y FROM requests_import ORDER BY y DESC"
)->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Migrate Historical → Requests';
include 'includes/header.php';
?>

<div class="page-header">
  <div>
    <h2>Migrate Historical → Requests</h2>
    <div class="sub"><a href="requests_import_list.php" class="text-muted" style="text-decoration:none">← Historical</a></div>
  </div>
  <div class="gap-8">
    <a href="backup_tables.php" class="btn btn-outline"
       style="font-weight:700;color:#1A6B3A;border-color:#1A6B3A;"
       title="Download SQL backup of requests + requests_import">
      💾 Download Backup First
    </a>
  </div>
</div>

<?php if ($doExecute): ?>
  <?php if ($inserted > 0): ?>
    <div class="flash flash-ok" style="background:#D1FAE5;border:1px solid #6EE7B7;border-radius:6px;padding:12px 16px;margin-bottom:16px;color:#065F46;font-weight:600;">
      ✅ <?= $inserted ?> record<?= $inserted !== 1 ? 's' : '' ?> imported successfully into Requests.
      <?= count($toSkip) > 0 ? ' · ' . count($toSkip) . ' skipped (already exist).' : '' ?>
    </div>
  <?php endif; ?>
  <?php if ($execErrors): ?>
    <div class="flash flash-error">
      <?php foreach ($execErrors as $e): ?>
        <div>⚠️ <?= h($e) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($inserted > 0): ?>
    <div style="margin-bottom:24px;">
      <a href="requests.php" class="btn btn-red">Go to Requests →</a>
      <a href="requests_import_list.php" class="btn btn-outline" style="margin-left:8px;">Back to Historical</a>
    </div>
    <?php include 'includes/footer.php'; exit; ?>
  <?php endif; ?>
<?php endif; ?>

<!-- ── FILTERS ─────────────────────────────────────────────────────────────── -->
<form method="GET" style="display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:24px;">
  <div>
    <label style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--grey-mid);display:block;margin-bottom:4px;">Year</label>
    <select name="year" style="font-size:.85rem;">
      <option value="0">All years</option>
      <?php foreach ($years as $y): ?>
        <option value="<?= $y ?>" <?= $year===(int)$y?'selected':'' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--grey-mid);display:block;margin-bottom:4px;">Source (for new records)</label>
    <select name="source" style="font-size:.85rem;">
      <?php foreach (const_source_list() as $s): ?>
        <option value="<?= h($s) ?>" <?= $source===$s?'selected':'' ?>><?= h($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn btn-outline">Refresh Preview</button>
</form>

<!-- ── STATUS MAPPING LEGEND ─────────────────────────────────────────────────── -->
<div style="background:#FFF;border:1px solid var(--grey-lt);border-radius:8px;padding:14px 18px;margin-bottom:20px;font-size:.8rem;">
  <strong style="font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid);">Status mapping applied during migration</strong>
  <div style="display:flex;flex-wrap:wrap;gap:8px 24px;margin-top:8px;">
    <?php foreach (STATUS_MAP as $from => $to): ?>
      <span><span style="color:var(--grey-dk)"><?= h($from) ?></span> → <strong style="color:<?= $to==='Booked'?'#1A6B3A':($to==='Cancelled'?'#C0211B':'#444') ?>"><?= h($to) ?></strong></span>
    <?php endforeach; ?>
    <span><span style="color:var(--grey-dk)">(other / NULL)</span> → <strong>Inquiry</strong></span>
  </div>
</div>

<!-- ── SUMMARY ────────────────────────────────────────────────────────────────── -->
<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px;">
  <div style="background:#fff;border-radius:8px;box-shadow:0 1px 6px rgba(0,0,0,.08);padding:16px 24px;text-align:center;min-width:140px;">
    <div style="font-size:1.6rem;font-weight:800;color:var(--red-dk)"><?= count($candidates) ?></div>
    <div style="font-size:.75rem;color:var(--grey-mid);margin-top:2px">Total records<?= $year?' ('.$year.')':'' ?></div>
  </div>
  <div style="background:#D1FAE5;border-radius:8px;box-shadow:0 1px 6px rgba(0,0,0,.08);padding:16px 24px;text-align:center;min-width:140px;">
    <div style="font-size:1.6rem;font-weight:800;color:#065F46"><?= count($toInsert) ?></div>
    <div style="font-size:.75rem;color:#065F46;margin-top:2px">Will be imported</div>
  </div>
  <?php if (count($toSkip) > 0): ?>
  <div style="background:#FEF9C3;border-radius:8px;box-shadow:0 1px 6px rgba(0,0,0,.08);padding:16px 24px;text-align:center;min-width:140px;">
    <div style="font-size:1.6rem;font-weight:800;color:#92400E"><?= count($toSkip) ?></div>
    <div style="font-size:.75rem;color:#92400E;margin-top:2px">Skipped (already exist)</div>
  </div>
  <?php endif; ?>
</div>

<?php if (empty($toInsert)): ?>
  <div class="flash" style="background:#F3F4F6;border:1px solid var(--grey-lt);border-radius:6px;padding:12px 16px;color:var(--grey-mid);">
    Nothing to import — all records for <?= $year ?: 'selected period' ?> already exist in Requests.
  </div>
<?php else: ?>

<!-- ── EXECUTE FORM ───────────────────────────────────────────────────────────── -->
<form method="POST" id="executeForm"
      style="background:#FFF3CD;border:1px solid #FFC107;border-radius:8px;padding:16px 20px;margin-bottom:20px;">
  <input type="hidden" name="year"   value="<?= $year ?>">
  <input type="hidden" name="source" value="<?= h($source) ?>">
  <input type="hidden" name="action" value="execute">
  <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
    <div>
      <strong>⚠️ Ready to import <?= count($toInsert) ?> record<?= count($toInsert)!==1?'s':'' ?> into Requests.</strong><br>
      <span style="font-size:.8rem;color:var(--grey-dk);">Make sure you have downloaded the backup above before proceeding.</span>
    </div>
    <button type="submit" class="btn btn-red"
            onclick="return confirm('Import <?= count($toInsert) ?> records into Requests?\n\nMake sure you downloaded the backup first.');">
      ✅ Execute Import
    </button>
  </div>
</form>

<!-- ── PREVIEW TABLE ──────────────────────────────────────────────────────────── -->
<div class="section-label" style="margin-bottom:8px;">Preview — records to be imported (<?= count($toInsert) ?>)</div>
<div class="table-wrap" style="margin-bottom:32px;">
  <table>
    <thead>
      <tr>
        <th style="font-size:.72rem;">Date</th>
        <th style="font-size:.72rem;">Customer</th>
        <th style="font-size:.72rem;">Agent</th>
        <th style="font-size:.72rem;">Agency</th>
        <th style="font-size:.72rem;">Historical Status</th>
        <th style="font-size:.72rem;">→ New Status</th>
        <th style="font-size:.72rem;">Dropbox Folder</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($toInsert as $row): ?>
      <tr>
        <td style="font-size:.78rem;white-space:nowrap"><?= date('d M Y', strtotime($row['date_received'])) ?></td>
        <td style="font-size:.8rem;font-weight:600"><?= h($row['customer_name']) ?></td>
        <td style="font-size:.78rem"><?= h($row['agent_name'] ?? '—') ?></td>
        <td style="font-size:.78rem;color:var(--grey-mid)"><?= h($row['agency_name'] ?? '—') ?></td>
        <td style="font-size:.78rem;color:var(--grey-dk)"><?= h($row['status'] ?? '—') ?></td>
        <td style="font-size:.78rem;">
          <?php $ms = mapStatus($row['status']); ?>
          <strong style="color:<?= $ms==='Booked'?'#1A6B3A':($ms==='Cancelled'?'#C0211B':'#444') ?>">
            <?= h($ms) ?>
          </strong>
        </td>
        <td style="font-size:.73rem;font-family:monospace;color:var(--grey-mid);max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
          <?= h($row['practice_code'] ?? '—') ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if (count($toSkip) > 0): ?>
<!-- ── SKIPPED ────────────────────────────────────────────────────────────────── -->
<details style="margin-bottom:24px;">
  <summary style="font-size:.8rem;font-weight:700;color:#92400E;cursor:pointer;padding:8px 0;">
    ⚠️ <?= count($toSkip) ?> records will be skipped (practice_code already in Requests)
  </summary>
  <div class="table-wrap" style="margin-top:8px;">
    <table>
      <thead>
        <tr>
          <th style="font-size:.72rem;">Date</th>
          <th style="font-size:.72rem;">Customer</th>
          <th style="font-size:.72rem;">Dropbox Folder</th>
          <th style="font-size:.72rem;">Historical Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($toSkip as $row): ?>
        <tr style="opacity:.6">
          <td style="font-size:.78rem"><?= date('d M Y', strtotime($row['date_received'])) ?></td>
          <td style="font-size:.8rem"><?= h($row['customer_name']) ?></td>
          <td style="font-size:.73rem;font-family:monospace"><?= h($row['practice_code']) ?></td>
          <td style="font-size:.78rem"><?= h($row['status'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</details>
<?php endif; ?>

<?php endif; // end if toInsert not empty ?>

<?php include 'includes/footer.php'; ?>
