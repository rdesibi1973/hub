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

$rStmt2 = $db->prepare("SELECT qdr.*, lrt.max_pax AS rt_max_pax
    FROM quote_day_rooms qdr
    LEFT JOIN lodge_room_types lrt ON lrt.id = qdr.room_type_id
    WHERE qdr.quote_day_id IN (SELECT id FROM quote_days WHERE quote_id = ?)
    ORDER BY qdr.quote_day_id, qdr.id");
$rStmt2->execute([$id]);
$roomsByDay    = [];
$roomTotalByDay= [];
foreach ($rStmt2->fetchAll() as $rm) {
    $roomsByDay[$rm['quote_day_id']][] = $rm;
}

// ── Lodge pricing data for PHP recalculation ──────────────────────────────────
// Load all season periods keyed by season_id
$allPeriods = $db->query(
    "SELECT lsp.season_id, lsp.year, lsp.start_mmdd, lsp.end_mmdd, ls.lodge_id
     FROM lodge_season_periods lsp
     JOIN lodge_seasons ls ON ls.id = lsp.season_id"
)->fetchAll();
$periodsBySeason = [];
foreach ($allPeriods as $p) {
    $periodsBySeason[(int)$p['season_id']][] = $p;
}

// Load all lodge prices
$allLodgePrices = $db->query(
    "SELECT lp.*, lrt.lodge_id, lrt.max_pax AS rt_max_pax
     FROM lodge_prices lp
     JOIN lodge_room_types lrt ON lrt.id = lp.room_type_id"
)->fetchAll();
$lodgePriceMap = []; // [room_type_id][season_id] => price row
foreach ($allLodgePrices as $lp) {
    $lodgePriceMap[(int)$lp['room_type_id']][(int)$lp['season_id']] = $lp;
}

/**
 * Mirror of JS getLodgePrice — returns unit price for one room.
 * Uses max_pax for the room type to pick the pax_N column.
 * Falls back to first available price if no season period matches the date.
 */
function getRoomUnitPricePHP(int $roomTypeId, string $dateStr, array $periodsBySeason, array $lodgePriceMap): float {
    if (!isset($lodgePriceMap[$roomTypeId])) return 0;
    $md = substr($dateStr, 5, 2) . '-' . substr($dateStr, 8, 2); // MM-DD
    $yr = (int)substr($dateStr, 0, 4);

    $firstRow = null;
    foreach ($lodgePriceMap[$roomTypeId] as $seasonId => $priceRow) {
        if ($firstRow === null) $firstRow = $priceRow; // keep as fallback
        $periods = $periodsBySeason[$seasonId] ?? [];
        // If season has no periods defined → treat as year-round
        if (empty($periods)) {
            $firstRow = $priceRow; // prefer season with no periods (most specific)
            continue;
        }
        foreach ($periods as $p) {
            if ($p['year'] !== null && (int)$p['year'] !== $yr) continue;
            $s = $p['start_mmdd']; $e = $p['end_mmdd'];
            $inRange = ($s <= $e) ? ($md >= $s && $md <= $e) : ($md >= $s || $md <= $e);
            if (!$inRange) continue;
            // Matched season — compute price
            $n   = min(max((int)($priceRow['rt_max_pax'] ?? 2), 1), 5);
            $raw = (float)($priceRow['pax_' . $n] ?? 0);
            return ($priceRow['price_basis'] === 'per_person') ? $raw * $n : $raw;
        }
    }
    // Fallback: no period matched — use first available price
    if ($firstRow) {
        $n   = min(max((int)($firstRow['rt_max_pax'] ?? 2), 1), 5);
        $raw = (float)($firstRow['pax_' . $n] ?? 0);
        return ($firstRow['price_basis'] === 'per_person') ? $raw * $n : $raw;
    }
    return 0;
}

// Pre-compute room totals per day using live DB prices
$startDate = $quote['start_date'] ?? null;
foreach ($days as $d) {
    $dayNum  = (int)$d['day_number'];
    $dayDate = null;
    if ($startDate) {
        $dt = new DateTime($startDate);
        $dt->modify('+' . ($dayNum - 1) . ' days');
        $dayDate = $dt->format('Y-m-d');
    }
    $total = 0;
    foreach ($roomsByDay[$d['id']] ?? [] as &$rm) {
        $storedTotal = (float)$rm['total_price'];
        $liveUnit    = $dayDate && $rm['room_type_id']
                       ? getRoomUnitPricePHP((int)$rm['room_type_id'], $dayDate, $periodsBySeason, $lodgePriceMap)
                       : 0;
        // Use live price if stored is 0 and live price is available
        $effectiveUnit  = ($storedTotal == 0 && $liveUnit > 0) ? $liveUnit : $storedTotal / max((int)$rm['qty'], 1);
        $effectiveTotal = $effectiveUnit * max((int)$rm['qty'], 1);
        $rm['_unit_price_display'] = $effectiveUnit;
        $rm['_total_display']      = $effectiveTotal;
        $total += $effectiveTotal;
    }
    unset($rm);
    $roomTotalByDay[$d['id']] = $total;
}

// Load jeep rates for display
$jeepRates = $db->query("SELECT type, rate, valid_from, valid_to FROM jeep_rates ORDER BY type, valid_from DESC")->fetchAll();
$jeepRateDefaults = ['half'=>125, 'full'=>250, 'double'=>500, 'contribution'=>80];

// Load flight routes and activity rates for day total recalculation
$flightRoutes = $db->query("SELECT id, rate_pax FROM flight_routes WHERE active=1")->fetchAll(PDO::FETCH_KEY_PAIR);
$activityRates = $db->query("SELECT id, rate, item_type FROM activity_rates WHERE active=1")->fetchAll();
$actRateMap = [];
foreach ($activityRates as $ar) $actRateMap[(int)$ar['id']] = $ar;

// Load safari fixed items (Emergency, Medivac, etc.)
$siStmt = $db->prepare("SELECT * FROM quote_safari_items WHERE quote_id = ? ORDER BY id");
$siStmt->execute([$id]);
$safariItems = $siStmt->fetchAll();

// Load request
$rStmt = $db->prepare("SELECT id, practice_code, customer_name FROM requests WHERE id = ?");
$rStmt->execute([$quote['request_id']]);
$req = $rStmt->fetch();

$pax   = (int)$quote['adults'] + (int)$quote['teens'] + (int)$quote['children'];
$jeeps = $pax > 7 ? ceil($pax / 7) : 1;
$mk    = (float)$quote['markup_pct'] / 100;

// ── Jeep rate lookup (mirrors JS getJeepRate) ─────────────────────────────────
function getJeepRatePHP(array $jeepRates, array $defaults, string $type, string $dateStr): float {
    foreach ($jeepRates as $r) {
        if (strtolower($r['type']) !== strtolower($type)) continue;
        if ($r['valid_from'] && $dateStr < $r['valid_from']) continue;
        if ($r['valid_to']   && $dateStr > $r['valid_to'])   continue;
        return (float)$r['rate'];
    }
    return (float)($defaults[$type] ?? 0);
}

// ── Park fees (mirrors JS PARK_FEES) ──────────────────────────────────────────
$PARK_FEES = [
    'tarangire'  => ['ppp' => 69,  'fx' => 0],
    'manyara'    => ['ppp' => 45,  'fx' => 0],
    'serengeti1' => ['ppp' => 179, 'fx' => 0],
    'serengeti2' => ['ppp' => 96,  'fx' => 0],
    'crater'     => ['ppp' => 83,  'fx' => 295],
];

$pageTitle = $quote['quote_number'] . ' — ' . $quote['customer_name'];

$cleanName  = preg_replace('/\s+/', '', $quote['customer_name']);
$xlsxName   = $quote['quote_number'] . '_' . $cleanName . '.xlsx';

include 'includes/header.php';
?>

<style>
.qv-wrap{max-width:900px;margin:0 auto;}
.qv-header{background:linear-gradient(135deg,#A01A14,#C0211B);color:#fff;border-radius:12px;padding:16px 22px;margin-bottom:18px;display:flex;align-items:center;gap:14px;}
.qv-header h2{font-family:'Merriweather',serif;font-size:1.15rem;font-weight:700;}
.qv-header p{font-size:.75rem;opacity:.75;margin-top:2px;}
.qv-card{background:#fff;border-radius:12px;padding:20px 22px;margin-bottom:14px;box-shadow:0 1px 6px rgba(0,0,0,.07);}
.qv-sec{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#6b7280;margin-bottom:12px;}
.info-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;}
.info-cell .lbl{font-size:.68rem;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px;}
.info-cell .val{font-size:.9rem;font-weight:600;color:#111827;}
.day-row:hover{background:#fbf8f5;}
.day-row td{padding:8px 12px;border-bottom:1px solid #f3f4f6;font-size:.85rem;}
.badge-final{background:#fee2e2;color:#C0211B;border-radius:4px;padding:2px 8px;font-size:.72rem;font-weight:700;}
.badge-draft{background:#fef3c7;color:#92400e;border-radius:4px;padding:2px 8px;font-size:.72rem;font-weight:700;}
.kpi-row{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:14px;}
.kpi{background:#f9fafb;border-radius:8px;padding:10px 12px;border:1px solid #e5e7eb;}
.kpi.hi{background:#fef2f2;border-color:#fecaca;}
.kpi .lbl{font-size:.65rem;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:3px;}
.kpi .val{font-weight:700;font-size:1.05rem;color:#111827;}
.kpi.hi .val{color:#C0211B;}
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
    <a href="quote_new.php?request_id=<?= $quote['request_id'] ?>&edit=<?= $id ?>"
       style="padding:8px 16px;border-radius:8px;border:1px solid rgba(255,255,255,.4);color:#fff;text-decoration:none;font-size:.82rem;font-weight:700;">
      ✏️ Edit
    </a>
    <a href="api_export_quote.php?id=<?= $id ?>"
       style="padding:8px 16px;border-radius:8px;border:1px solid rgba(255,255,255,.4);color:#fff;text-decoration:none;font-size:.82rem;font-weight:700;">
      ⬇ <?= h($xlsxName) ?>
    </a>
    <a href="wetu.php?client_name=<?= urlencode($quote['customer_name']) ?>&ref_number=<?= urlencode($quote['quote_number']) ?>&pax=<?= $pax ?>&days=<?= count($days) ?><?= $quote['start_date'] ? '&start_date='.urlencode($quote['start_date']) : '' ?>"
       style="padding:8px 16px;border-radius:8px;border:1px solid rgba(255,255,255,.4);color:#fff;text-decoration:none;font-size:.82rem;font-weight:700;">
      🗺️ Wetu
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
          <th style="padding:8px 12px;text-align:center;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;">#</th>
          <th style="padding:8px 12px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;">Park</th>
          <th style="padding:8px 12px;text-align:right;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;">Day Total</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $recalcGrandTotal = 0;
        // ── Safari fixed costs (Emergency, Medivac…) ──────────────────────────
        if ($safariItems):
            $safariTotal = 0;
            foreach ($safariItems as $si) {
                $safariTotal += $si['item_type'] === 'pax'
                    ? (float)$si['amount'] * $pax
                    : (float)$si['amount'];
            }
        ?>
        <tr style="background:#fffbeb;">
          <td colspan="7" style="padding:7px 12px;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#92400e;">Safari Fixed Costs</td>
        </tr>
        <?php foreach ($safariItems as $si):
          $siTotal = $si['item_type'] === 'pax'
              ? (float)$si['amount'] * $pax
              : (float)$si['amount'];
          $siNote  = $si['item_type'] === 'pax'
              ? '$'.(float)$si['amount'].' × '.$pax.' pax'
              : 'fixed';
        ?>
        <tr style="background:#fffbeb;">
          <td style="padding:6px 12px;"></td>
          <td colspan="5" style="padding:6px 12px;font-size:.82rem;color:#92400e;">
            <?= h($si['description']) ?>
            <span style="font-size:.72rem;color:#b45309;margin-left:6px;">(<?= $siNote ?>)</span>
          </td>
          <td style="padding:6px 12px;text-align:right;font-family:monospace;color:#92400e;">$<?= number_format($siTotal, 0) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php foreach ($days as $d):
          $dayItems   = $itemsByDay[$d['id']] ?? [];
          $dayRooms   = $roomsByDay[$d['id']] ?? [];
          $dayJeeps   = (int)($d['jeep_count'] ?? ($pax > 7 ? ceil($pax / 7) : 1));
          $startDate  = $quote['start_date'] ?? date('Y-m-d');

          // ── Recalculate day total from stored components ──────────────────
          $cLodge    = $roomTotalByDay[$d['id']] ?? 0;
          if ($cLodge == 0 && ($d['lodge_id'] ?? 0) == -1)
              $cLodge = (float)($d['lodge_custom_total'] ?? 0);

          $jeepRate  = ($d['jeep_rate_custom'] !== null && $d['jeep_rate_custom'] !== '')
                       ? (float)$d['jeep_rate_custom']
                       : getJeepRatePHP($jeepRates, $jeepRateDefaults, $d['jeep'] ?? 'full', $startDate);
          $cJeep     = ($d['jeep'] !== 'none') ? $jeepRate * $dayJeeps : 0;

          $cDrinks   = ($d['drinks'] ?? 0) ? 4 * $pax : 0;

          $cPark = 0;
          if (($d['park'] ?? 'none') === 'custom') {
              $cPark = (float)($d['park_custom'] ?? 0);
          } elseif (isset($PARK_FEES[$d['park']])) {
              $pf    = $PARK_FEES[$d['park']];
              $cPark = $pf['fx'] + $pf['ppp'] * $pax;
          }

          $cFlight = 0;
          if (($d['flight_route_id'] ?? 0) > 0) {
              $rPax    = (float)($flightRoutes[(int)$d['flight_route_id']] ?? 0);
              $cFlight = ($d['flight_custom'] !== null && $d['flight_custom'] !== '')
                         ? (float)$d['flight_custom'] : $rPax * $pax;
          } elseif (($d['flight_route_id'] ?? 0) == -1) {
              $cFlight = (float)($d['flight_custom'] ?? 0);
          }

          $cTransfer = 0;
          if (($d['transfer_rate_id'] ?? 0) > 0) {
              $ar = $actRateMap[(int)$d['transfer_rate_id']] ?? null;
              if ($ar) {
                  $trRate    = ($d['transfer_custom'] !== null && $d['transfer_custom'] !== '')
                               ? (float)$d['transfer_custom'] : (float)$ar['rate'];
                  $cTransfer = ($ar['item_type'] === 'pax') ? $trRate * $pax : $trRate;
              }
          } elseif (($d['transfer_rate_id'] ?? 0) == -1) {
              $cTransfer = (float)($d['transfer_custom'] ?? 0);
          }

          $cItems = 0;
          foreach ($dayItems as $it)
              $cItems += ($it['item_type'] === 'pax') ? (float)$it['amount'] * $pax : (float)$it['amount'];

          $dayCalc = $cLodge + $cJeep + $cDrinks + $cPark + $cFlight + $cTransfer + $cItems;
          $recalcGrandTotal += $dayCalc;
        ?>
        <tr class="day-row">
          <td style="font-weight:700;color:var(--red);"><?= (int)$d['day_number'] ?></td>
          <td style="font-weight:600;"><?= h($d['route'] ?? $d['location'] ?? '—') ?></td>
          <td style="color:#6b7280;">
            <?= h($d['lodge'] ?? '—') ?>
            <?php if ($dayRooms): ?>
              <div style="font-size:.72rem;color:#9ca3af;margin-top:2px;">
                <?php foreach ($dayRooms as $r): ?>
                  <span><?= (int)$r['qty'] ?>×<?= h($r['room_type_name']) ?>
                    <span style="color:#d1d5db;">$<?= number_format((float)$r['_total_display'], 0) ?></span>
                  </span>&nbsp;
                <?php endforeach; ?>
                <?php if ($cLodge > 0): ?>
                  <strong style="color:#6b7280;">= $<?= number_format($cLodge, 0) ?></strong>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </td>
          <td>
            <?= ucfirst(h($d['jeep'])) ?>
            <?php if ($d['jeep'] !== 'none'): ?>
              <div style="font-size:.72rem;color:#6b7280;margin-top:2px;">
                $<?= number_format($jeepRate, 0) ?><?= $dayJeeps > 1 ? ' × '.$dayJeeps : '' ?>
                <?php if ($d['jeep_rate_custom'] !== null && $d['jeep_rate_custom'] !== ''): ?>
                  <span style="color:#b45309;" title="Custom rate">✎</span>
                <?php endif; ?>
                = <strong>$<?= number_format($cJeep, 0) ?></strong>
              </div>
            <?php endif; ?>
          </td>
          <td style="text-align:center;font-size:.8rem;color:#6b7280;"><?= $dayJeeps ?></td>
          <td style="font-size:.8rem;color:#6b7280;">
            <?= h($d['park']) ?>
            <?php if ($cPark > 0): ?>
              <div style="font-size:.72rem;color:#9ca3af;">$<?= number_format($cPark, 0) ?></div>
            <?php endif; ?>
            <?php if ($cFlight > 0): ?>
              <div style="font-size:.72rem;color:#6b7280;">✈ $<?= number_format($cFlight, 0) ?></div>
            <?php endif; ?>
            <?php if ($cTransfer > 0): ?>
              <div style="font-size:.72rem;color:#6b7280;">🚐 $<?= number_format($cTransfer, 0) ?></div>
            <?php endif; ?>
          </td>
          <td style="text-align:right;font-family:monospace;font-weight:600;">
            $<?= number_format($dayCalc, 0, '.', ',') ?>
            <?php if (abs($dayCalc - (float)$d['day_total']) > 1): ?>
              <div style="font-size:.65rem;color:#9ca3af;text-decoration:line-through;">
                saved: $<?= number_format((float)$d['day_total'], 0) ?>
              </div>
            <?php endif; ?>
          </td>
        </tr>
        <?php if (!empty($dayItems)): ?>
        <tr>
          <td></td>
          <td colspan="5" style="padding:2px 12px 10px;">
            <?php foreach ($dayItems as $it): ?>
              <span style="display:inline-block;background:#fef2f2;color:#C0211B;border-radius:4px;padding:1px 8px;font-size:.72rem;margin:2px 3px 0 0;">
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
        <?php if (!empty($safariItems)): ?>
        <tr style="border-top:2px solid #e5e7eb;">
          <td colspan="6" style="padding:7px 12px;text-align:right;color:#b45309;font-size:.82rem;">Safari Fixed Total</td>
          <td style="padding:7px 12px;text-align:right;font-family:monospace;color:#b45309;">$<?= number_format($safariTotal ?? 0, 0) ?></td>
        </tr>
        <?php endif; ?>
        <tr <?= empty($safariItems) ? 'style="border-top:2px solid #e5e7eb;"' : '' ?>>
          <td colspan="6" style="padding:7px 12px;text-align:right;color:#6b7280;font-size:.82rem;">Bank Commission</td>
          <td style="padding:7px 12px;text-align:right;font-family:monospace;color:#6b7280;">$<?= number_format((float)$quote['bank_commission'], 0) ?></td>
        </tr>
        <?php
          $calcSafari   = $safariTotal ?? 0;
          $calcNetTotal = $recalcGrandTotal + $calcSafari + (float)$quote['bank_commission'];
          $calcPrice    = $calcNetTotal * (1 + $mk);
        ?>
        <tr style="font-weight:700;background:#f9fafb;border-top:2px solid #e5e7eb;">
          <td colspan="6" style="padding:9px 12px;text-align:right;">Net Total Costs</td>
          <td style="padding:9px 12px;text-align:right;font-family:monospace;">$<?= number_format($calcNetTotal, 0, '.', ',') ?>
            <?php if (abs($calcNetTotal - (float)$quote['total_costs']) > 1): ?>
              <div style="font-size:.65rem;color:#9ca3af;text-decoration:line-through;">saved: $<?= number_format((float)$quote['total_costs'], 0) ?></div>
            <?php endif; ?>
          </td>
        </tr>
        <tr style="color:#C0211B;">
          <td colspan="6" style="padding:7px 12px;text-align:right;font-size:.82rem;">Markup (<?= number_format($quote['markup_pct'], 0) ?>%)</td>
          <td style="padding:7px 12px;text-align:right;font-family:monospace;font-size:.82rem;">+ $<?= number_format($calcNetTotal * $mk, 0, '.', ',') ?></td>
        </tr>
        <tr style="background:#C0211B;color:#fff;font-weight:700;font-size:1rem;">
          <td colspan="6" style="padding:13px 12px;text-align:right;">TOTAL PRICE</td>
          <td style="padding:13px 12px;text-align:right;font-family:monospace;">$<?= number_format($calcPrice, 0, '.', ',') ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <?php
    $ppp    = $pax > 0 ? $calcPrice / $pax : 0;
    $pppTo  = $pax > 0 ? $calcNetTotal * 1.18 / $pax : 0;
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
