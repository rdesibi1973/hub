<?php
require_once 'config.php';
$db = db();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: quotes.php'); exit; }

$isStaff      = isLeadsRestricted();
$staffAgentId = $isStaff ? getStaffAgentId() : 0;

// Load quote
$q = $db->prepare("SELECT * FROM quotes WHERE id = ?");
$q->execute([$id]);
$quote = $q->fetch();
if (!$quote) { flash('Quote not found.', 'error'); header('Location: quotes.php'); exit; }

// Staff access check
if ($isStaff && (int)$quote['agent_id'] !== $staffAgentId) {
    flash('Access denied.', 'error'); header('Location: quotes.php'); exit;
}

// Load days + items
$dStmt = $db->prepare("SELECT * FROM quote_days WHERE quote_id = ? ORDER BY day_number");
$dStmt->execute([$id]);
$days = $dStmt->fetchAll();

$iStmt = $db->prepare("SELECT * FROM quote_day_items WHERE quote_day_id IN (SELECT id FROM quote_days WHERE quote_id = ?) ORDER BY quote_day_id, id");
$iStmt->execute([$id]);
$allItems = $iStmt->fetchAll();
$itemsByDay = [];
foreach ($allItems as $item) $itemsByDay[$item['quote_day_id']][] = $item;

// Load request
$rStmt = $db->prepare("SELECT id, practice_code, customer_name FROM requests WHERE id = ?");
$rStmt->execute([$quote['request_id']]);
$req = $rStmt->fetch();

$pax   = (int)$quote['adults'] + (int)$quote['teens'] + (int)$quote['children'];
$jeeps = $pax > 7 ? ceil($pax / 7) : 1;
$mk    = (float)$quote['markup_pct'] / 100;

$pageTitle = $quote['quote_number'] . ' — ' . $quote['customer_name'];

$cleanName  = preg_replace('/\s+/', '', $quote['customer_name']);
$xlsxName   = $quote['quote_number'] . '_' . $cleanName . '.xlsx';

include 'includes/header.php';
?>

<style>
.qv-wrap{max-width:900px;margin:0 auto;}
.qv-header{background:linear-gradient(135deg,#14532d,#166534);color:#fff;border-radius:12px;padding:16px 22px;margin-bottom:18px;display:flex;align-items:center;gap:14px;}
.qv-header h2{font-family:'Merriweather',serif;font-size:1.15rem;font-weight:700;}
.qv-header p{font-size:.75rem;opacity:.75;margin-top:2px;}
.qv-card{background:#fff;border-radius:12px;padding:20px 22px;margin-bottom:14px;box-shadow:0 1px 6px rgba(0,0,0,.07);}
.qv-sec{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#6b7280;margin-bottom:12px;}
.info-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;}
.info-cell .lbl{font-size:.68rem;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px;}
.info-cell .val{font-size:.9rem;font-weight:600;color:#111827;}
.day-row:hover{background:#fbf8f5;}
.day-row td{padding:8px 12px;border-bottom:1px solid #f3f4f6;font-size:.85rem;}
.badge-final{background:#dcfce7;color:#166534;border-radius:4px;padding:2px 8px;font-size:.72rem;font-weight:700;}
.badge-draft{background:#fef3c7;color:#92400e;border-radius:4px;padding:2px 8px;font-size:.72rem;font-weight:700;}
.kpi-row{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:14px;}
.kpi{background:#f9fafb;border-radius:8px;padding:10px 12px;border:1px solid #e5e7eb;}
.kpi.hi{background:#f0fdf4;border-color:#bbf7d0;}
.kpi .lbl{font-size:.65rem;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:3px;}
.kpi .val{font-weight:700;font-size:1.05rem;color:#111827;}
.kpi.hi .val{color:#166534;}
</style>

<div class="qv-wrap">

<!-- Header -->
<div class="qv-header">
  <div style="font-size:2rem;">📋</div>
  <div style="flex:1">
    <h2>Quote <?= h($quote['quote_number']) ?> — <?= h($quote['customer_name']) ?></h2>
    <p>
      <?php if ($req): ?>
        <a href="request_view.php?id=<?= $req['id'] ?>" style="color:rgba(255,255,255,.8);text-decoration:none;">
          ← <?= h($req['practice_code'] ?? 'Request #'.$req['id']) ?>
        </a> &nbsp;·&nbsp;
      <?php endif; ?>
      <?= h($quote['program'] ?? 'Custom') ?> &nbsp;·&nbsp;
      <?= $pax ?> PAX
      <?php if ($quote['start_date']): ?>
        &nbsp;·&nbsp; <?= date('d M Y', strtotime($quote['start_date'])) ?>
      <?php endif; ?>
    </p>
  </div>
  <div style="display:flex;gap:8px;align-items:center;">
    <?php if ($quote['status'] === 'final'): ?>
      <span class="badge-final">Final</span>
    <?php else: ?>
      <span class="badge-draft">Draft</span>
    <?php endif; ?>
    <a href="api_export_quote.php?id=<?= $id ?>"
       style="padding:8px 16px;border-radius:8px;border:1px solid rgba(255,255,255,.4);color:#fff;text-decoration:none;font-size:.82rem;font-weight:700;">
      ⬇ <?= h($xlsxName) ?>
    </a>
  </div>
</div>

<!-- Client info -->
<div class="qv-card">
  <div class="qv-sec">Client Information</div>
  <div class="info-grid">
    <div class="info-cell"><div class="lbl">Customer</div><div class="val"><?= h($quote['customer_name']) ?></div></div>
    <div class="info-cell"><div class="lbl">Agent</div><div class="val"><?= h($quote['agent_name'] ?? '—') ?></div></div>
    <div class="info-cell"><div class="lbl">Agency</div><div class="val"><?= h($quote['agency_name'] ?? '—') ?></div></div>
    <div class="info-cell"><div class="lbl">Adults</div><div class="val"><?= (int)$quote['adults'] ?></div></div>
    <div class="info-cell"><div class="lbl">Teenagers</div><div class="val"><?= (int)$quote['teens'] ?></div></div>
    <div class="info-cell"><div class="lbl">Children</div><div class="val"><?= (int)$quote['children'] ?></div></div>
    <div class="info-cell"><div class="lbl">Total PAX</div><div class="val" style="color:var(--green)"><?= $pax ?></div></div>
    <div class="info-cell"><div class="lbl">Jeeps</div><div class="val"><?= $jeeps ?></div></div>
    <div class="info-cell"><div class="lbl">Start Date</div><div class="val"><?= $quote['start_date'] ? date('d M Y', strtotime($quote['start_date'])) : '—' ?></div></div>
  </div>
</div>

<!-- Day breakdown -->
<div class="qv-card">
  <div class="qv-sec">Day-by-Day Breakdown</div>
  <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
      <thead>
        <tr style="background:#f9fafb;">
          <th style="padding:8px 12px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;">Day</th>
          <th style="padding:8px 12px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;">Location</th>
          <th style="padding:8px 12px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;">Lodge</th>
          <th style="padding:8px 12px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;">Jeep</th>
          <th style="padding:8px 12px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;">Park</th>
          <th style="padding:8px 12px;text-align:right;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;">Day Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($days as $d):
          $dayItems = $itemsByDay[$d['id']] ?? [];
        ?>
        <tr class="day-row">
          <td style="font-weight:700;color:var(--green);"><?= (int)$d['day_number'] ?></td>
          <td style="font-weight:600;"><?= h($d['route'] ?? $d['location'] ?? '—') ?></td>
          <td style="color:#6b7280;"><?= h($d['lodge'] ?? '—') ?></td>
          <td><?= ucfirst(h($d['jeep'])) ?></td>
          <td style="font-size:.8rem;color:#6b7280;"><?= h($d['park']) ?></td>
          <td style="text-align:right;font-family:monospace;font-weight:600;">$<?= number_format((float)$d['day_total'], 0, '.', ',') ?></td>
        </tr>
        <?php if (!empty($dayItems)): ?>
        <tr>
          <td></td>
          <td colspan="5" style="padding:2px 12px 10px;">
            <?php foreach ($dayItems as $it): ?>
              <span style="display:inline-block;background:#f0fdf4;color:#166534;border-radius:4px;padding:1px 8px;font-size:.72rem;margin:2px 3px 0 0;">
                <?= h($it['description']) ?>
                (<?= $it['item_type'] === 'pax' ? '$'.$it['amount'].'/pax' : '$'.$it['amount'].' fixed' ?>)
              </span>
            <?php endforeach; ?>
          </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="border-top:2px solid #e5e7eb;">
          <td colspan="5" style="padding:9px 12px;text-align:right;color:#6b7280;font-size:.82rem;">Bank Commission</td>
          <td style="padding:9px 12px;text-align:right;font-family:monospace;color:#6b7280;">$<?= number_format((float)$quote['bank_commission'], 0) ?></td>
        </tr>
        <tr style="font-weight:700;background:#f9fafb;">
          <td colspan="5" style="padding:9px 12px;text-align:right;">Net Total Costs</td>
          <td style="padding:9px 12px;text-align:right;font-family:monospace;">$<?= number_format((float)$quote['total_costs'], 0, '.', ',') ?></td>
        </tr>
        <tr style="color:#166534;">
          <td colspan="5" style="padding:7px 12px;text-align:right;font-size:.82rem;">Markup (<?= number_format($quote['markup_pct'], 0) ?>%)</td>
          <td style="padding:7px 12px;text-align:right;font-family:monospace;font-size:.82rem;">+ $<?= number_format((float)$quote['total_costs'] * $mk, 0, '.', ',') ?></td>
        </tr>
        <tr style="background:#166534;color:#fff;font-weight:700;font-size:1rem;">
          <td colspan="5" style="padding:13px 12px;text-align:right;">TOTAL PRICE</td>
          <td style="padding:13px 12px;text-align:right;font-family:monospace;">$<?= number_format((float)$quote['total_price'], 0, '.', ',') ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <?php
    $ppp    = $pax > 0 ? (float)$quote['total_price'] / $pax : 0;
    $pppTo  = $pax > 0 ? (float)$quote['total_costs'] * 1.18 / $pax : 0;
    $single = ($quote['program'] === 'beachkiboko') ? 650 : 250;
    $dep    = (float)$quote['total_price'] * 0.3;
  ?>
  <div class="kpi-row">
    <div class="kpi hi"><div class="lbl">Price p.p. (rack)</div><div class="val">$<?= number_format($ppp, 0, '.', ',') ?></div></div>
    <div class="kpi"><div class="lbl">Price p.p. (T.O.)</div><div class="val">$<?= number_format($pppTo, 0, '.', ',') ?></div></div>
    <div class="kpi"><div class="lbl">Single supplement</div><div class="val">$<?= number_format($single, 0) ?></div></div>
    <div class="kpi"><div class="lbl">Deposit (30%)</div><div class="val">$<?= number_format($dep, 0, '.', ',') ?></div></div>
  </div>
</div>

<!-- Meta -->
<div style="font-size:.72rem;color:var(--grey-mid);max-width:900px;margin-top:4px;">
  Quote #<?= h($quote['quote_number']) ?> &nbsp;·&nbsp;
  Created <?= date('d M Y H:i', strtotime($quote['created_at'])) ?> &nbsp;·&nbsp;
  <?= h($quote['markup_type']) ?> markup
</div>

</div>

<?php include 'includes/footer.php'; ?>
