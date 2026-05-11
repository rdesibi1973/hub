<?php
require_once 'config.php';
$pageTitle = 'New Quote';
$db = db();

$isStaff      = isLeadsRestricted();
$staffAgentId = $isStaff ? getStaffAgentId() : 0;

// Require a linked request
$requestId = (int)($_GET['request_id'] ?? 0);
if (!$requestId) {
    flash('Please open a request first, then click "New Quote".', 'error');
    header('Location: requests.php'); exit;
}

// Load request — enforce staff ownership
$stmt = $db->prepare("
    SELECT r.*, a.name AS agent_name
    FROM   requests r
    LEFT JOIN agents a ON a.id = r.agent_id
    WHERE  r.id = ?
");
$stmt->execute([$requestId]);
$req = $stmt->fetch();
if (!$req) { flash('Request not found.', 'error'); header('Location: requests.php'); exit; }
if ($isStaff && (int)$req['agent_id'] !== $staffAgentId) {
    flash('Access denied.', 'error'); header('Location: requests.php'); exit;
}

// Prefill JSON for JavaScript
$prefill = json_encode([
    'customer' => $req['customer_name'] ?? '',
    'agent'    => $req['agent_name']    ?? '',
    'agency'   => '',
    'pax'      => $req['pax']           ?? '',
    'period'   => $req['period']        ?? '',
]);

// CSRF token — compatible with both leads config and main auth.php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Load lodge pricing data for wizard (graceful fallback if tables don't exist yet)
$pricingJson = '{"lodges":[],"parks":[],"routes":[]}';
try {
    $wLodges = $db->query("SELECT id, name, location, default_meal_plan FROM lodges WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    if ($wLodges) {
        $wIds = array_map('intval', array_column($wLodges, 'id'));
        $wIn  = implode(',', array_fill(0, count($wIds), '?'));

        $st = $db->prepare("SELECT id, lodge_id, name, max_pax FROM lodge_room_types WHERE lodge_id IN ($wIn) ORDER BY lodge_id, sort_order, name");
        $st->execute($wIds); $wRTs = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $r['id']=(int)$r['id']; $r['lodge_id']=(int)$r['lodge_id']; $r['max_pax']=(int)$r['max_pax']; $wRTs[$r['lodge_id']][] = $r; }

        $st = $db->prepare("SELECT id, lodge_id, name FROM lodge_seasons WHERE lodge_id IN ($wIn) ORDER BY lodge_id, sort_order, id");
        $st->execute($wIds); $wSeasons = []; $wSids = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) { $s['id']=(int)$s['id']; $s['lodge_id']=(int)$s['lodge_id']; $wSeasons[$s['lodge_id']][] = $s; $wSids[] = $s['id']; }

        $wPeriods = []; $wPrices = [];
        if ($wSids) {
            $wIn2 = implode(',', array_fill(0, count($wSids), '?'));
            $st = $db->prepare("SELECT season_id, year, start_mmdd, end_mmdd FROM lodge_season_periods WHERE season_id IN ($wIn2) ORDER BY season_id, year IS NULL DESC, start_mmdd");
            $st->execute($wSids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) { $p['year'] = $p['year'] !== null ? (int)$p['year'] : null; $wPeriods[(int)$p['season_id']][] = $p; }

            $wRtIds = [];
            foreach ($wRTs as $rows) foreach ($rows as $r) $wRtIds[] = $r['id'];
            if ($wRtIds) {
                $wIn3 = implode(',', array_fill(0, count($wRtIds), '?'));
                $wIn4 = implode(',', array_fill(0, count($wSids), '?'));
                $st   = $db->prepare("SELECT room_type_id, season_id, meal_plan, price_basis, pax_1, pax_2, pax_3, pax_4, pax_5 FROM lodge_prices WHERE room_type_id IN ($wIn3) AND season_id IN ($wIn4)");
                $st->execute(array_merge($wRtIds, $wSids));
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
                    for ($i = 1; $i <= 5; $i++) $p["pax_$i"] = $p["pax_$i"] !== null ? (float)$p["pax_$i"] : null;
                    $wPrices[(int)$p['room_type_id']][(int)$p['season_id']] = $p;
                }
            }
        }

        $st = $db->prepare("SELECT lodge_id, name, start_mmdd, end_mmdd, year, amount_adult, amount_child FROM lodge_supplements WHERE lodge_id IN ($wIn) ORDER BY lodge_id, start_mmdd");
        $st->execute($wIds); $wSups = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) { $s['year']=$s['year']!==null?(int)$s['year']:null; $s['amount_adult']=(float)$s['amount_adult']; $s['amount_child']=(float)$s['amount_child']; $wSups[(int)$s['lodge_id']][] = $s; }

        foreach ($wLodges as &$wL) {
            $lid = (int)$wL['id']; $wL['id'] = $lid;
            $sArr = $wSeasons[$lid] ?? [];
            foreach ($sArr as &$s) { $s['periods'] = $wPeriods[$s['id']] ?? []; } unset($s);
            $rtArr = $wRTs[$lid] ?? [];
            foreach ($rtArr as &$rt) {
                $rt['seasons'] = [];
                foreach ($sArr as $s) { $rt['seasons'][] = ['id'=>$s['id'],'name'=>$s['name'],'periods'=>$s['periods'],'prices'=>$wPrices[$rt['id']][$s['id']]??null]; }
            } unset($rt);
            $wL['room_types']  = $rtArr;
            $wL['supplements'] = $wSups[$lid] ?? [];
        } unset($wL);
        $pricingJson = json_encode(['lodges' => $wLodges, 'parks' => [], 'routes' => []]);
    }
} catch (\Throwable $e) { /* pricing tables not yet created — wizard still works without lodge prices */ }

// Load v2 pricing tables (graceful fallback if not yet migrated)
try {
    $jeepRates = $db->query("SELECT id, type, valid_from, valid_to, rate FROM jeep_rates ORDER BY type, valid_from DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($jeepRates as &$r) { $r['id']=(int)$r['id']; $r['rate']=(float)$r['rate']; } unset($r);

    $activityRates = $db->query("SELECT id, name, category, item_type, valid_from, valid_to, rate FROM activity_rates WHERE active=1 ORDER BY category, name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($activityRates as &$r) { $r['id']=(int)$r['id']; $r['rate']=(float)$r['rate']; } unset($r);

    $flightRoutes = $db->query("SELECT id, route_name, origin, destination, airline, valid_from, valid_to, rate_pax FROM flight_routes WHERE active=1 ORDER BY route_name, airline")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($flightRoutes as &$r) { $r['id']=(int)$r['id']; $r['rate_pax']=(float)$r['rate_pax']; } unset($r);

    $decoded = json_decode($pricingJson, true) ?? [];
    $pricingJson = json_encode(array_merge($decoded, [
        'jeep_rates'     => $jeepRates,
        'activity_rates' => $activityRates,
        'flight_routes'  => $flightRoutes,
    ]));
} catch (\Throwable $e) {
    $decoded = json_decode($pricingJson, true) ?? ['lodges'=>[],'parks'=>[],'routes'=>[]];
    $pricingJson = json_encode(array_merge($decoded, ['jeep_rates'=>[],'activity_rates'=>[],'flight_routes'=>[]]));
}

// Next available quote number for display in wizard
$nextQuoteNum = '01';
try {
    $nextQuoteNum = $db->query("SELECT LPAD(COALESCE(MAX(CAST(quote_number AS UNSIGNED)), 0) + 1, 2, '0') FROM quotes")->fetchColumn() ?: '01';
} catch (\Throwable $e) {}

// ── Edit mode: load existing quote ───────────────────────────────────────────
$editQuoteId = (int)($_GET['edit'] ?? 0);
$editJson    = 'null';
$isEditMode  = false;

if ($editQuoteId) {
    $eStmt = $db->prepare("SELECT * FROM quotes WHERE id = ?");
    $eStmt->execute([$editQuoteId]);
    $eQuote = $eStmt->fetch();

    if ($eQuote && (!$isStaff || (int)$eQuote['agent_id'] === $staffAgentId)) {
        $isEditMode   = true;
        $nextQuoteNum = $eQuote['quote_number']; // pre-fill existing number

        // Load days
        $eDStmt = $db->prepare("SELECT * FROM quote_days WHERE quote_id = ? ORDER BY day_number");
        $eDStmt->execute([$editQuoteId]);
        $eDays = $eDStmt->fetchAll();
        $eDayIds = array_map('intval', array_column($eDays, 'id'));

        $eRoomsByDay = []; $eItemsByDay = [];
        if ($eDayIds) {
            $eIn = implode(',', array_fill(0, count($eDayIds), '?'));
            $eRSt = $db->prepare("SELECT * FROM quote_day_rooms WHERE quote_day_id IN ($eIn) ORDER BY id");
            $eRSt->execute($eDayIds);
            foreach ($eRSt->fetchAll() as $r) $eRoomsByDay[(int)$r['quote_day_id']][] = $r;
            $eISt = $db->prepare("SELECT * FROM quote_day_items WHERE quote_day_id IN ($eIn) ORDER BY id");
            $eISt->execute($eDayIds);
            foreach ($eISt->fetchAll() as $it) $eItemsByDay[(int)$it['quote_day_id']][] = $it;
        }

        // Load safari items
        $eSiSt = $db->prepare("SELECT * FROM quote_safari_items WHERE quote_id = ? ORDER BY id");
        $eSiSt->execute([$editQuoteId]);
        $eSafariItems = $eSiSt->fetchAll();

        // Build days array for JS
        $eDaysArr = [];
        foreach ($eDays as $ed) {
            $did  = (int)$ed['id'];
            $lid  = $ed['lodge_id'] !== null ? (int)$ed['lodge_id'] : 0;
            $lc   = (float)($ed['lodge_custom'] ?? 0);
            $jsL  = $lid > 0 ? $lid : ($lc > 0 ? -1 : 0);
            $frid = $ed['flight_route_id'] !== null ? (int)$ed['flight_route_id'] : 0;
            $fc   = (float)($ed['flight_custom'] ?? 0);
            $jsF  = $frid > 0 ? $frid : ($fc > 0 ? -1 : 0);
            $trid = $ed['transfer_rate_id'] !== null ? (int)$ed['transfer_rate_id'] : 0;
            $tc   = (float)($ed['transfer_custom'] ?? 0);
            $jsT  = $trid > 0 ? $trid : ($tc > 0 ? -1 : 0);
            $eDaysArr[] = [
                'route'           => $ed['route'] ?? '',
                'attraction'      => $ed['attraction'] ?? '',
                'lodge_id'        => $jsL,
                'lodge_custom'    => $lc,
                'rooms'           => array_map(fn($r) => ['room_type_id' => (int)$r['room_type_id'], 'qty' => (int)$r['qty']], $eRoomsByDay[$did] ?? []),
                'jeep'            => $ed['jeep'] ?? 'full',
                'jeep_count'      => $ed['jeep_count'] !== null ? (int)$ed['jeep_count'] : null,
                'jeep_rate_custom'=> $ed['jeep_rate_custom'] !== null ? (float)$ed['jeep_rate_custom'] : null,
                'drinks'          => (int)($ed['drinks'] ?? 1),
                'park'            => $ed['park'] ?? 'none',
                'park_custom'     => (float)($ed['park_custom'] ?? 0),
                'flight_route_id' => $jsF,
                'flight_custom'   => $fc,
                'transfer_rate_id'=> $jsT,
                'transfer_custom' => $tc,
                'items'           => array_map(fn($it) => ['description' => $it['description'], 'item_type' => $it['item_type'], 'amount' => (float)$it['amount']], $eItemsByDay[$did] ?? []),
            ];
        }

        $editData = [
            'id'            => $editQuoteId,
            'quote_number'  => $eQuote['quote_number'],
            'customer_name' => $eQuote['customer_name'],
            'agent_name'    => $eQuote['agent_name']  ?? '',
            'agency_name'   => $eQuote['agency_name'] ?? '',
            'start_date'    => $eQuote['start_date']  ?? '',
            'adults'        => (int)$eQuote['adults'],
            'teens'         => (int)$eQuote['teens'],
            'children'      => (int)$eQuote['children'],
            'default_rooms' => json_decode($eQuote['default_rooms'] ?? '[]', true) ?: [],
            'program'       => $eQuote['program'] ?? 'simba',
            'markup_type'   => $eQuote['markup_type'] ?? 'standard',
            'markup_pct'    => (float)$eQuote['markup_pct'],
            'safari_items'  => array_map(fn($si) => ['activity_rate_id' => $si['activity_rate_id'], 'description' => $si['description'], 'item_type' => $si['item_type'], 'amount' => (float)$si['amount']], $eSafariItems),
            'days'          => $eDaysArr,
        ];
        $editJson = json_encode($editData);
    }
}

include 'includes/header.php';
?>

<style>
/* ── Wizard Chrome ── */
.wiz-wrap{max-width:900px;margin:0 auto;}
.wiz-header{background:linear-gradient(135deg,#A01A14,#C0211B);color:#fff;border-radius:12px;padding:16px 22px;margin-bottom:18px;display:flex;align-items:center;gap:14px;}
.wiz-header-icon{font-size:2.2rem;}
.wiz-header h2{font-family:'Merriweather',serif;font-size:1.15rem;font-weight:700;line-height:1.2;}
.wiz-header p{font-size:.75rem;opacity:.75;margin-top:2px;}
.wiz-prog{display:flex;gap:6px;margin-bottom:18px;}
.wiz-prog-step{flex:1;text-align:center;}
.wiz-prog-bar{height:4px;border-radius:2px;margin-bottom:5px;background:#d1d5db;transition:background .3s;}
.wiz-prog-bar.done{background:#C0211B;}
.wiz-prog-bar.active{background:#f87171;}
.wiz-prog-lbl{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#9ca3af;}
.wiz-prog-lbl.active{color:#C0211B;}

/* ── Cards & Fields ── */
.wiz-card{background:#fff;border-radius:12px;padding:20px 22px;margin-bottom:14px;box-shadow:0 1px 6px rgba(0,0,0,.07);}
.wiz-card h3{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#6b7280;margin-bottom:14px;}
.field-grid{display:grid;gap:12px;}
.field-grid-2{grid-template-columns:1fr 1fr;}
.field-grid-3{grid-template-columns:1fr 1fr 1fr;}
.f-lbl{display:block;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:4px;}
.f-inp{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.88rem;box-sizing:border-box;background:#fff;}
.f-inp:focus{outline:none;border-color:#C0211B;box-shadow:0 0 0 2px rgba(192,33,27,.15);}
.pax-bar{margin-top:12px;padding:10px 14px;background:#fef2f2;border-radius:8px;font-size:.82rem;color:#C0211B;display:flex;gap:16px;flex-wrap:wrap;}

/* ── Template picker ── */
.tpl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;}
.tpl-card{background:#fff;border-radius:12px;padding:18px;cursor:pointer;box-shadow:0 1px 6px rgba(0,0,0,.07);border:2px solid transparent;transition:border-color .15s;}
.tpl-card:hover{border-color:#C0211B;}
.tpl-card.selected{border-color:#C0211B;background:#fef2f2;}
.tpl-icon{font-size:2.4rem;margin-bottom:6px;}
.tpl-name{font-weight:700;font-size:.95rem;color:#111827;}
.tpl-desc{font-size:.78rem;color:#6b7280;margin-top:3px;}

/* ── Day cards ── */
.day-list{margin-bottom:12px;}
.day-card{background:#fff;border-radius:10px;margin-bottom:6px;box-shadow:0 1px 4px rgba(0,0,0,.07);overflow:hidden;}
.day-head{display:flex;align-items:center;padding:10px 14px;cursor:pointer;gap:10px;user-select:none;}
.day-num{background:#C0211B;color:#fff;border-radius:5px;padding:3px 7px;font-size:.72rem;font-weight:700;min-width:24px;text-align:center;}
.day-loc{flex:1;font-weight:600;font-size:.88rem;}
.day-lodge{font-size:.78rem;color:#6b7280;}
.day-total{font-weight:700;font-size:.88rem;color:#C0211B;}
.day-chevron{font-size:.7rem;color:#9ca3af;margin-left:4px;}
.day-body{padding:0 14px 14px;border-top:1px solid #f3f4f6;}
.day-fields{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;padding-top:12px;}
.day-items{margin-top:10px;background:#f9fafb;border-radius:8px;padding:10px 12px;}
.day-items-lbl{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:6px;}
.item-row{display:flex;gap:6px;margin-bottom:5px;align-items:center;}
.item-row .f-inp{font-size:.82rem;}
.btn-add-item{font-size:.78rem;color:#C0211B;background:none;border:none;cursor:pointer;padding:0;}
.btn-rm{background:none;border:none;cursor:pointer;color:#ef4444;font-size:1.1rem;padding:0 4px;line-height:1;}

/* ── Summary table ── */
.sum-table{width:100%;border-collapse:collapse;font-size:.85rem;}
.sum-table th{background:#f9fafb;padding:8px 12px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;}
.sum-table td{padding:8px 12px;border-bottom:1px solid #f3f4f6;}
.sum-total-row{background:#C0211B;color:#fff;font-weight:700;font-size:1rem;}
.sum-total-row td{padding:12px;}
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:14px;}
.kpi{background:#f9fafb;border-radius:8px;padding:10px 12px;border:1px solid #e5e7eb;}
.kpi.hi{background:#fef2f2;border-color:#fecaca;}
.kpi-lbl{font-size:.65rem;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:3px;}
.kpi-val{font-weight:700;font-size:1.1rem;color:#111827;}
.kpi.hi .kpi-val{color:#C0211B;}

/* ── Nav buttons ── */
.wiz-nav{display:flex;justify-content:space-between;margin-top:18px;gap:10px;}
.btn-back{padding:10px 20px;border-radius:8px;border:1px solid #d1d5db;background:#fff;cursor:pointer;font-size:.88rem;color:#374151;}
.btn-next{padding:10px 24px;border-radius:8px;border:none;background:#C0211B;color:#fff;cursor:pointer;font-size:.88rem;font-weight:700;margin-left:auto;}
.btn-next:disabled{background:#9ca3af;cursor:not-allowed;}
.btn-save{padding:12px 28px;border-radius:8px;border:none;background:#C0211B;color:#fff;cursor:pointer;font-size:.95rem;font-weight:700;width:100%;}

.markup-btns{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
.mkbtn{padding:8px 16px;border-radius:8px;border:2px solid #d1d5db;background:#fff;cursor:pointer;font-size:.82rem;color:#374151;}
.mkbtn.active{border-color:#C0211B;background:#fef2f2;font-weight:700;color:#C0211B;}

/* ── Safari items ── */
.safari-card{background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:14px 16px;margin-bottom:12px;}
.safari-card h3{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#92400e;margin-bottom:10px;}
.safari-total{font-size:.78rem;color:#92400e;font-weight:600;margin-top:8px;}
/* ── Rate badge next to inputs ── */
.rate-db{font-size:.68rem;color:#9ca3af;font-weight:400;}
.cost-badge{display:inline-block;font-size:.72rem;font-weight:700;background:#f0fdf4;color:#166534;border-radius:4px;padding:1px 7px;margin-left:6px;font-family:monospace;}
</style>

<div class="wiz-wrap">

<!-- Header -->
<div class="wiz-header">
  <div class="wiz-header-icon">🐘</div>
  <div>
    <h2><?= $isEditMode ? 'Edit Quote #'.h($editData['quote_number']) : 'New Quote' ?> — <?= h($req['customer_name']) ?></h2>
    <p>Request #<?= h($req['practice_code'] ?? $req['id']) ?> &nbsp;·&nbsp;
       <?php if ($isEditMode): ?>
         <a href="quote_view.php?id=<?= $editQuoteId ?>" style="color:rgba(255,255,255,.8);text-decoration:none;">← Back to quote</a>
       <?php else: ?>
         <a href="request_view.php?id=<?= $req['id'] ?>" style="color:rgba(255,255,255,.8);text-decoration:none;">← Back to request</a>
       <?php endif; ?>
    </p>
  </div>
  <div id="progBadge" style="margin-left:auto;background:rgba(255,255,255,.15);border-radius:6px;padding:4px 12px;font-size:.82rem;display:none;"></div>
</div>

<!-- Stepper -->
<div class="wiz-prog">
  <?php foreach (['Program','Client','Itinerary','Summary'] as $i => $lbl): ?>
  <div class="wiz-prog-step">
    <div class="wiz-prog-bar" id="bar<?= $i+1 ?>"></div>
    <div class="wiz-prog-lbl" id="lbl<?= $i+1 ?>"><?= $lbl ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ===== STEP 1: PROGRAM ===== -->
<div id="step1">
  <div class="wiz-card">
    <h3>Select Program Template</h3>
    <div class="tpl-grid" id="tplGrid"></div>
  </div>
  <div class="wiz-nav">
    <div></div>
    <button class="btn-next" id="btnStep1Next" disabled onclick="goTo(2)">Next →</button>
  </div>
</div>

<!-- ===== STEP 2: CLIENT ===== -->
<div id="step2" style="display:none">
  <div class="wiz-card">
    <h3>Client &amp; Participants</h3>
    <div class="field-grid field-grid-2">
      <div><label class="f-lbl">Customer Name</label><input class="f-inp" id="fName" placeholder="e.g. Smith Family"></div>
      <div><label class="f-lbl">Start Date</label><input class="f-inp" id="fDate" type="date"></div>
      <div><label class="f-lbl">Agent</label><input class="f-inp" id="fAgent" placeholder="Agent name"></div>
      <div><label class="f-lbl">Agency</label><input class="f-inp" id="fAgency" placeholder="Agency name"></div>
    </div>
    <div style="margin-top:16px;padding-top:14px;border-top:1px solid #f3f4f6;">
      <div class="f-lbl" style="margin-bottom:10px;">Participants</div>
      <div class="field-grid field-grid-3">
        <div><label class="f-lbl">Adults</label><input class="f-inp" id="fAdults" type="number" min="0" value="2"></div>
        <div><label class="f-lbl">Teenagers</label><input class="f-inp" id="fTeens" type="number" min="0" value="0"></div>
        <div><label class="f-lbl">Children (&lt;11)</label><input class="f-inp" id="fChildren" type="number" min="0" value="0"></div>
      </div>
      <div class="pax-bar" id="paxBar">2 PAX · 1 Jeep</div>
    </div>
  </div>
  <div class="wiz-nav">
    <button class="btn-back" onclick="goTo(1)">← Back</button>
    <button class="btn-next" id="btnStep2Next" onclick="goTo(3)">Next →</button>
  </div>
</div>

<!-- ===== STEP 3: ITINERARY ===== -->
<div id="step3" style="display:none">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
    <div style="font-size:.88rem;font-weight:600;color:#111827;" id="itinTitle">Itinerary</div>
    <button onclick="addDay()" style="background:#C0211B;color:#fff;border:none;border-radius:8px;padding:7px 14px;cursor:pointer;font-size:.82rem;font-weight:700;">+ Add Day</button>
  </div>

  <!-- Default Room Template — global config auto-applied to each day -->
  <div class="wiz-card" style="border:1px solid #fecaca;margin-bottom:12px;">
    <h3>Default Rooms <span class="rate-db">(auto-applied per day when a lodge is selected — overridable)</span></h3>
    <div id="defaultRoomsList"></div>
    <div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap;">
      <button class="btn-add-item" onclick="addDefaultRoom()">+ Add room type</button>
      <button class="btn-add-item" onclick="applyDefaultRoomsToAllDays()"
              style="background:#fef3c7;color:#92400e;border-color:#fcd34d;"
              title="Re-apply default rooms to every day that has a lodge selected">
        ↺ Apply to all days
      </button>
    </div>
  </div>

  <!-- Safari Fixed Costs (Emergency, Medivac, etc.) — once per safari -->
  <div class="safari-card">
    <h3>Safari Fixed Costs <span class="rate-db">(once per safari, not per day)</span></h3>
    <div id="safariItemsList"></div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;">
      <button class="btn-add-item" onclick="addSafariItem()">+ Add item</button>
      <div class="safari-total">Safari total: <span id="safariItemsTotal">$0</span></div>
    </div>
  </div>

  <div class="day-list" id="dayList"></div>
  <div class="wiz-nav">
    <button class="btn-back" onclick="goTo(2)">← Back</button>
    <button class="btn-next" id="btnStep3Next" onclick="goTo(4)">Next →</button>
  </div>
</div>

<!-- ===== STEP 4: SUMMARY ===== -->
<div id="step4" style="display:none">
  <!-- Markup -->
  <div class="wiz-card">
    <h3>Markup</h3>
    <div class="markup-btns">
      <button class="mkbtn active" id="mkStd"  onclick="setMarkup('standard')">Standard 25%</button>
      <button class="mkbtn"        id="mkTo"   onclick="setMarkup('to')">Tour Operator 18%</button>
      <button class="mkbtn"        id="mkCust" onclick="setMarkup('custom')">Custom %</button>
      <span id="custMkWrap" style="display:none;display:flex;align-items:center;gap:6px;">
        <input id="custMkVal" type="number" value="25" min="0" max="100"
               style="width:60px;padding:7px;border:1px solid #d1d5db;border-radius:6px;font-size:.88rem;"
               oninput="renderSummary()">
        <span style="font-size:.82rem;color:#6b7280;">%</span>
      </span>
    </div>
  </div>

  <!-- Breakdown -->
  <div class="wiz-card">
    <h3>Cost Breakdown</h3>
    <table class="sum-table">
      <thead>
        <tr>
          <th>Day</th><th>Route / Destination</th><th>Lodge</th><th style="text-align:right">Cost</th>
        </tr>
      </thead>
      <tbody id="sumRows"></tbody>
      <tfoot id="sumFoot"></tfoot>
    </table>
    <div class="kpi-grid" id="kpiGrid"></div>
  </div>

  <!-- Save -->
  <div class="wiz-card" id="saveCard">
    <h3>Save Quote</h3>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap;">
      <label class="f-lbl" style="margin:0;white-space:nowrap">Quote #</label>
      <input class="f-inp" id="fQuoteNum" value="<?= h($nextQuoteNum) ?>" style="width:72px;font-family:monospace;font-weight:700" placeholder="01" oninput="updateFnamePreview()">
      <span style="font-size:.82rem;color:#6b7280">_<span id="fnameCustomer" style="color:#C0211B;font-family:monospace;font-weight:600"></span>.xlsx</span>
    </div>
    <div style="font-size:.78rem;color:#9ca3af;margin-bottom:16px;">Number auto-assigned if blank; you can override it manually.</div>
    <button class="btn-save" id="btnSave" onclick="saveQuote()">💾 Save &amp; Generate Excel</button>
  </div>

  <div class="wiz-nav">
    <button class="btn-back" onclick="goTo(3)">← Back</button>
  </div>
</div>

<!-- Saved confirmation -->
<div id="savedBox" style="display:none;background:#fef2f2;border:1px solid #86efac;border-radius:12px;padding:28px;text-align:center;max-width:900px;margin:0 auto;">
  <div style="font-size:2.5rem;margin-bottom:8px;">✅</div>
  <div style="font-weight:700;color:#C0211B;font-size:1.1rem;margin-bottom:4px;">Quote saved!</div>
  <div id="savedFilename" style="font-size:.85rem;color:#6b7280;margin-bottom:16px;font-family:monospace;"></div>
  <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
    <button onclick="location.href='quote_view.php?id='+window._savedId"
      style="padding:9px 20px;border-radius:8px;border:none;background:#C0211B;color:#fff;cursor:pointer;font-size:.88rem;font-weight:700;">View Quote</button>
    <button onclick="window.open('api_export_quote.php?id='+window._savedId,'_blank')"
      style="padding:9px 20px;border-radius:8px;border:1px solid #C0211B;background:#fff;color:#C0211B;cursor:pointer;font-size:.88rem;font-weight:700;">⬇ Download Excel</button>
    <a href="request_view.php?id=<?= $req['id'] ?>"
      style="padding:9px 20px;border-radius:8px;border:1px solid #d1d5db;background:#fff;color:#374151;cursor:pointer;font-size:.88rem;text-decoration:none;">Back to Request</a>
  </div>
</div>

</div><!-- .wiz-wrap -->

<script>
// ── Data ──────────────────────────────────────────────────────────────────────
const PRICING_DATA = <?= $pricingJson ?>;
const EDIT_DATA    = <?= $editJson ?>;

const PARK_FEES = {
  none:       {l:'—',          ppp:0,   fx:0},
  tarangire:  {l:'Tarangire',  ppp:69,  fx:0},
  manyara:    {l:'Manyara',    ppp:60,  fx:0},
  serengeti1: {l:'Serengeti Day 1', ppp:179, fx:0},
  serengeti2: {l:'Serengeti Day 2', ppp:96,  fx:0},
  crater:     {l:'Ngorongoro Crater', ppp:83, fx:295},
  custom:     {l:'Custom',     ppp:0,   fx:0},
};
// Default day object factory
function mkDay(o) {
  return Object.assign({
    id:uid(), route:'', attraction:'',
    lodge_id:0, rooms:[], lodge_custom_total:0,
    jeep:'full', jeep_count:null, jeep_rate_custom:null,
    park:'none', parkCust:'',
    flight_route_id:0, flight_custom:'',
    transfer_rate_id:0, transfer_custom:'',
    items:[], drinks:true
  }, o||{});
}

const TEMPLATES = {
  simba: {
    name:'Simba Safari', icon:'🦁', desc:'6 days · Safari Tanzania',
    days:[
      {route:'Kili-Arusha',   attraction:'',                  jeep:'half', park:'none'},
      {route:'Tarangire',     attraction:'Tarangire NP',      jeep:'full', park:'tarangire',  items:[{d:'Lunch boxes',t:'p',a:'10'}]},
      {route:'Serengeti',     attraction:'Serengeti NP',      jeep:'full', park:'serengeti1'},
      {route:'Serengeti',     attraction:'Serengeti NP',      jeep:'full', park:'serengeti2'},
      {route:'Crater-Karatu', attraction:'Ngorongoro Crater', jeep:'full', park:'crater'},
      {route:'Karatu-JRO',    attraction:'',                  jeep:'full', park:'none',       items:[{d:'Natural Walk',t:'f',a:'20'}]},
    ]
  },
  beachkiboko: {
    name:'Beach Kiboko', icon:'🏖️', desc:'14 days · Safari + Zanzibar',
    days:[
      {route:'Kili-Arusha',   attraction:'',                  jeep:'half', park:'none'},
      {route:'Tarangire',     attraction:'Tarangire NP',      jeep:'full', park:'tarangire',  items:[{d:'Lunch boxes',t:'p',a:'10'}]},
      {route:'Manyara',       attraction:'Lake Manyara NP',   jeep:'full', park:'manyara'},
      {route:'Serengeti',     attraction:'Serengeti NP',      jeep:'full', park:'serengeti1'},
      {route:'Serengeti',     attraction:'Serengeti NP',      jeep:'full', park:'serengeti2'},
      {route:'Crater-Karatu', attraction:'Ngorongoro Crater', jeep:'full', park:'crater'},
      {route:'Karatu-ZNZ',    attraction:'',                  jeep:'full', park:'none',       items:[{d:'NatWalk+Transfer',t:'f',a:'80'}]},
      {route:'Zanzibar', attraction:'', jeep:'none', park:'none', drinks:false},
      {route:'Zanzibar', attraction:'', jeep:'none', park:'none', drinks:false},
      {route:'Zanzibar', attraction:'', jeep:'none', park:'none', drinks:false},
      {route:'Zanzibar', attraction:'', jeep:'none', park:'none', drinks:false},
      {route:'Zanzibar', attraction:'', jeep:'none', park:'none', drinks:false},
      {route:'Zanzibar', attraction:'', jeep:'none', park:'none', drinks:false},
      {route:'ZNZ-Home', attraction:'', jeep:'none', park:'none', drinks:false, items:[{d:'Transfer',t:'f',a:'60'}]},
    ]
  }
};

// ── State ─────────────────────────────────────────────────────────────────────
const PRE    = <?= $prefill ?>;
const BANK   = 100;
const REQ_ID = <?= $req['id'] ?>;

var state = { step:1, program:null, days:[], safariItems:[], defaultRooms:[], markup:'standard', custMk:25 };

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmt(n)   { return '$' + Math.round(n).toLocaleString('en-US'); }
function uid()    { return Math.random().toString(36).slice(2); }
function pax()    { return (+v('fAdults')||0)+(+v('fTeens')||0)+(+v('fChildren')||0); }
function jeeps()  { var p=pax(); return p>7?Math.ceil(p/7):1; }
function mkPct()  { if(state.markup==='standard')return 0.25; if(state.markup==='to')return 0.18; return(+v('custMkVal')/100)||0.25; }
function v(id)    { var e=document.getElementById(id); return e?e.value:''; }
function el(id)   { return document.getElementById(id); }
function show(id) { el(id).style.display=''; }
function hide(id) { el(id).style.display='none'; }
function esc(s)   { return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }

// ── v2 pricing helpers ────────────────────────────────────────────────────────
function getJeepRate(type, dateStr) {
  var DEFAULTS={half:125,full:250,double:500,contribution:80};
  var rates=(PRICING_DATA.jeep_rates||[]).filter(function(r){
    if(r.type!==type)return false;
    if(dateStr){if(r.valid_from&&dateStr<r.valid_from)return false; if(r.valid_to&&dateStr>r.valid_to)return false;}
    return true;
  });
  return rates.length?parseFloat(rates[0].rate):(DEFAULTS[type]||0);
}
function getFlightRoute(id){return(PRICING_DATA.flight_routes||[]).find(function(r){return r.id==id;});}
function getActivityRate(id){return(PRICING_DATA.activity_rates||[]).find(function(r){return r.id==id;});}

// ── Room helpers ──────────────────────────────────────────────────────────────
function mkRoom(o) { return Object.assign({id:uid(),room_type_id:0,qty:1},o||{}); }
function getRoomTypeName(lodge_id,rt_id){
  var l=(PRICING_DATA.lodges||[]).find(function(x){return x.id==lodge_id;});
  if(!l)return'';var r=l.room_types.find(function(x){return x.id==rt_id;});return r?r.name:'';
}
function getRoomMaxPax(lodge_id,rt_id){
  var l=(PRICING_DATA.lodges||[]).find(function(x){return x.id==lodge_id;});
  if(!l)return 2;var r=l.room_types.find(function(x){return x.id==rt_id;});return r?+(r.max_pax||2):2;
}
function getRoomUnitPrice(lodge_id,rt_id,dateStr){
  return getLodgePrice(lodge_id,rt_id,dateStr,getRoomMaxPax(lodge_id,rt_id));
}
function dayLodgeLabel(d){
  if(!d.lodge_id||d.lodge_id===0)return'—';
  if(d.lodge_id===-1)return'Custom';
  var lodge=(PRICING_DATA.lodges||[]).find(function(l){return l.id==d.lodge_id;});
  var name=lodge?lodge.name:'';
  var mp=lodge&&lodge.default_meal_plan?' ['+lodge.default_meal_plan+']':'';
  var parts=(d.rooms||[]).filter(function(r){return r.room_type_id>0;}).map(function(r){return(r.qty||1)+'×'+getRoomTypeName(d.lodge_id,r.room_type_id);});
  return name+mp+(parts.length?' ('+parts.join(', ')+')':'');
}

// ── Default room template ─────────────────────────────────────────────────────
function mkDefaultRoom(o) { return Object.assign({id:uid(),label:'',qty:1},o||{}); }
function renderDefaultRooms() {
  var html='';
  state.defaultRooms.forEach(function(dr){
    html+='<div class="item-row" id="ddr_'+dr.id+'">'
      +'<input class="f-inp" style="flex:3" placeholder="Room type label (e.g. Double)" value="'+esc(dr.label)+'" oninput="updDefaultRoom(\''+dr.id+'\',\'label\',this.value)">'
      +'<input class="f-inp" type="number" min="1" step="1" style="width:60px;text-align:center" value="'+esc(dr.qty)+'" oninput="updDefaultRoom(\''+dr.id+'\',\'qty\',+this.value||1)" placeholder="1">'
      +'<button class="btn-rm" onclick="rmDefaultRoom(\''+dr.id+'\')">×</button>'
    +'</div>';
  });
  el('defaultRoomsList').innerHTML=html;
}
function addDefaultRoom() {
  state.defaultRooms.push(mkDefaultRoom());
  renderDefaultRooms();
}
function rmDefaultRoom(drid) {
  state.defaultRooms=state.defaultRooms.filter(function(x){return x.id!==drid;});
  renderDefaultRooms();
}
function updDefaultRoom(drid,field,val) {
  var dr=state.defaultRooms.find(function(x){return x.id===drid;});
  if(dr) dr[field]=val;
}
function applyDefaultRoomsToDay(d) {
  d.rooms=[];
  if(!state.defaultRooms.length||d.lodge_id<=0) return;
  var lodge=(PRICING_DATA.lodges||[]).find(function(l){return l.id==d.lodge_id;});
  if(!lodge) return;
  state.defaultRooms.forEach(function(dr){
    if(!dr.label) return;
    var lbl=dr.label.toLowerCase();
    var rt=(lodge.room_types||[]).find(function(t){return t.name.toLowerCase().indexOf(lbl)!==-1;});
    d.rooms.push(mkRoom({room_type_id:rt?rt.id:0,qty:+dr.qty||1}));
  });
}

function applyDefaultRoomsToAllDays() {
  if (!state.defaultRooms.length) {
    alert('No default rooms defined — add at least one room type first.');
    return;
  }
  var applied = 0;
  state.days.forEach(function(d) {
    if (d.lodge_id > 0) {
      applyDefaultRoomsToDay(d);
      applied++;
    }
  });
  renderItinerary();
  if (applied === 0) alert('No days with a lodge selected — set a lodge on each day first.');
}

// ── Lodge/pricing DB helpers ──────────────────────────────────────────────────
function getDayDate(idx) {
  var sd = v('fDate');
  if (!sd) return null;
  var dt = new Date(sd + 'T00:00:00');
  dt.setDate(dt.getDate() + idx);
  var y=dt.getFullYear(), m=dt.getMonth()+1, d=dt.getDate();
  return y+'-'+String(m).padStart(2,'0')+'-'+String(d).padStart(2,'0');
}
function mmddOf(s) { return s ? s.slice(5,7)+'-'+s.slice(8,10) : null; }
function dateInPeriod(dateStr, period) {
  if (!dateStr) return false;
  var md=mmddOf(dateStr), yr=parseInt(dateStr.slice(0,4));
  if (period.year !== null && period.year !== undefined && parseInt(period.year) !== yr) return false;
  var s=period.start_mmdd, e=period.end_mmdd;
  return s<=e ? (md>=s && md<=e) : (md>=s || md<=e);
}
function getLodgePrice(lodge_id, room_type_id, dateStr, paxCount) {
  var lodge=(PRICING_DATA.lodges||[]).find(function(l){return l.id==lodge_id;});
  if (!lodge) return 0;
  var rt=lodge.room_types.find(function(r){return r.id==room_type_id;});
  if (!rt) return 0;
  for (var si=0;si<rt.seasons.length;si++) {
    var s=rt.seasons[si]; if(!s.prices) continue;
    for (var pi=0;pi<s.periods.length;pi++) {
      if (dateInPeriod(dateStr,s.periods[pi])) {
        var n=Math.min(Math.max(paxCount,1),5);
        var raw=parseFloat(s.prices['pax_'+n])||0;
        // per_person: stored value is per-person rate; multiply by pax count to get room total
        return s.prices.price_basis==='per_person' ? raw*n : raw;
      }
    }
  }
  return 0;
}
function getLodgeSupplements(lodge_id, dateStr, adults) {
  var lodge=(PRICING_DATA.lodges||[]).find(function(l){return l.id==lodge_id;});
  if (!lodge||!lodge.supplements||!lodge.supplements.length) return 0;
  var total=0;
  lodge.supplements.forEach(function(sup){
    if (dateInPeriod(dateStr,{year:sup.year,start_mmdd:sup.start_mmdd,end_mmdd:sup.end_mmdd}))
      total+=parseFloat(sup.amount_adult||0)*adults;
  });
  return total;
}
function getLodgeName(lodge_id) {
  var lodge=(PRICING_DATA.lodges||[]).find(function(l){return l.id==lodge_id;});
  return lodge?lodge.name:'';
}

function calcDay(d, idx) {
  var p=pax(), j=d.jeep_count!==null?+d.jeep_count:jeeps(), t=0, dateStr=getDayDate(idx||0), adults=+v('fAdults')||0;
  // Lodge — sum all room rows
  if (d.lodge_id>0) {
    (d.rooms||[]).forEach(function(r){
      if(r.room_type_id>0) t+=getRoomUnitPrice(d.lodge_id,r.room_type_id,dateStr)*(+r.qty||1);
    });
    t+=getLodgeSupplements(d.lodge_id,dateStr,adults);
  } else if (d.lodge_id===-1) { t+=+d.lodge_custom_total||0; }
  // Jeep (DB rate or user override)
  if (d.jeep!=='none') {
    var jr=(d.jeep_rate_custom!==null&&d.jeep_rate_custom!=='')?+d.jeep_rate_custom:getJeepRate(d.jeep,dateStr);
    t+=jr*j;
  }
  // Drinks
  if (d.drinks) t+=4*p;
  // Park
  if (d.park==='custom') t+=+d.parkCust||0;
  else { var pk=PARK_FEES[d.park]; if(pk) t+=pk.fx+pk.ppp*p; }
  // Flight (DB route or custom fixed)
  if (d.flight_route_id>0) {
    var flr=getFlightRoute(d.flight_route_id);
    var flRate=(d.flight_custom!==''&&d.flight_custom!==null)?+d.flight_custom:(flr?parseFloat(flr.rate_pax):0);
    t+=flRate*p;
  } else if (d.flight_route_id===-1) { t+=+d.flight_custom||0; }
  // Transfer (DB rate or custom)
  if (d.transfer_rate_id>0) {
    var tra=getActivityRate(d.transfer_rate_id);
    var trRate=(d.transfer_custom!==''&&d.transfer_custom!==null)?+d.transfer_custom:(tra?parseFloat(tra.rate):0);
    t+=tra&&tra.item_type==='pax'?trRate*p:trRate;
  } else if (d.transfer_rate_id===-1) { t+=+d.transfer_custom||0; }
  // Activity items
  d.items.forEach(function(a){ t+=a.t==='p'?(+a.a||0)*p:(+a.a||0); });
  return t;
}

// ── Safari fixed costs (Emergency, Medivac…) ─────────────────────────────────
function initSafariItems() {
  var dateStr=v('fDate')||new Date().toISOString().slice(0,10);
  state.safariItems=(PRICING_DATA.activity_rates||[])
    .filter(function(r){
      if(r.category!=='safari_fixed')return false;
      if(r.valid_from&&dateStr<r.valid_from)return false;
      if(r.valid_to&&dateStr>r.valid_to)return false;
      return true;
    })
    .map(function(r){return{id:uid(),activity_rate_id:r.id,description:r.name,item_type:r.item_type,amount:r.rate};});
  // Fallback: hardcoded defaults when DB is empty
  if(!state.safariItems.length) {
    state.safariItems=[
      {id:uid(),activity_rate_id:null,description:'Emergency',item_type:'pax',amount:30},
      {id:uid(),activity_rate_id:null,description:'Medivac',  item_type:'pax',amount:5},
    ];
  }
}
function calcSafariCost() {
  var p=pax();
  return state.safariItems.reduce(function(s,si){return s+(si.item_type==='pax'?(+si.amount||0)*p:(+si.amount||0));},0);
}
function renderSafariItems() {
  var p=pax(), html='';
  state.safariItems.forEach(function(si){
    var tot=si.item_type==='pax'?(+si.amount||0)*p:(+si.amount||0);
    html+='<div class="item-row" id="sir_'+si.id+'">'
      +'<input class="f-inp" style="flex:2" placeholder="Description" value="'+esc(si.description)+'" oninput="updSafariItem(\''+si.id+'\',\'description\',this.value)">'
      +'<select class="f-inp" style="flex:1" onchange="updSafariItem(\''+si.id+'\',\'item_type\',this.value)">'
        +'<option value="pax"'+(si.item_type==='pax'?' selected':'')+'>$/pax</option>'
        +'<option value="fixed"'+(si.item_type==='fixed'?' selected':'')+'>Fixed</option>'
      +'</select>'
      +'<input class="f-inp" type="number" style="width:64px" placeholder="$" value="'+esc(si.amount)+'" oninput="updSafariItem(\''+si.id+'\',\'amount\',this.value)">'
      +'<span style="font-size:.72rem;color:#92400e;min-width:52px;text-align:right;font-weight:600">'+fmt(tot)+'</span>'
      +'<button class="btn-rm" onclick="rmSafariItem(\''+si.id+'\')">×</button>'
    +'</div>';
  });
  el('safariItemsList').innerHTML=html;
  el('safariItemsTotal').textContent=fmt(calcSafariCost());
}
function addSafariItem() {
  state.safariItems.push({id:uid(),activity_rate_id:null,description:'',item_type:'pax',amount:0});
  renderSafariItems();
}
function updSafariItem(sid,field,val) {
  var si=state.safariItems.find(function(x){return x.id===sid;});
  if(si){si[field]=val; el('safariItemsTotal').textContent=fmt(calcSafariCost());}
}
function rmSafariItem(sid) {
  state.safariItems=state.safariItems.filter(function(x){return x.id!==sid;});
  renderSafariItems();
}

function calcTotals() {
  var costs=state.days.reduce(function(s,d,i){return s+calcDay(d,i);},0)+calcSafariCost()+BANK;
  var mk=mkPct(), price=costs*(1+mk), p=pax();
  return{costs,price,ppp:p>0?price/p:0,pppTo:p>0?costs*1.18/p:0,deposit:price*0.3,mk};
}

// ── Step navigation ───────────────────────────────────────────────────────────
function goTo(step) {
  ['step1','step2','step3','step4'].forEach(function(id,i){el(id).style.display=i+1===step?'':'none';});
  el('savedBox').style.display='none';
  state.step=step;
  ['bar1','bar2','bar3','bar4'].forEach(function(id,i){
    el(id).className='wiz-prog-bar'+(i+1<step?' done':i+1===step?' active':'');
    el('lbl'+(i+1)).className='wiz-prog-lbl'+(i+1===step?' active':'');
  });
  if(step===3){renderDefaultRooms();renderDays();renderSafariItems();}
  if(step===4) renderSummary();
}

// ── Step 1: Template picker ───────────────────────────────────────────────────
(function buildTplGrid() {
  var g = el('tplGrid');
  Object.keys(TEMPLATES).forEach(function(key) {
    var t = TEMPLATES[key];
    var d = document.createElement('div');
    d.className = 'tpl-card'; d.id = 'tpl_'+key;
    d.innerHTML = '<div class="tpl-icon">'+t.icon+'</div>'
                + '<div class="tpl-name">'+t.name+'</div>'
                + '<div class="tpl-desc">'+t.desc+'</div>';
    d.onclick = function() { selectTemplate(key); };
    g.appendChild(d);
  });
  // Placeholder cards
  [['⛰️','Trekking Kili'],['✨','Custom Program']].forEach(function(x){
    var d = document.createElement('div');
    d.className = 'tpl-card'; d.style.cssText='cursor:not-allowed;opacity:.5;border-style:dashed;';
    d.innerHTML = '<div class="tpl-icon">'+x[0]+'</div><div class="tpl-name">'+x[1]+'</div><div class="tpl-desc">Coming soon</div>';
    g.appendChild(d);
  });
})();

function selectTemplate(key) {
  Object.keys(TEMPLATES).forEach(function(k){el('tpl_'+k).className='tpl-card'+(k===key?' selected':'');});
  state.program=key;
  state.days=TEMPLATES[key].days.map(function(t){
    return mkDay({
      route:t.route||'', attraction:t.attraction||'',
      lodge_id:t.lodge_id||0, room_type_id:t.room_type_id||0,
      jeep:t.jeep||'full', park:t.park||'none',
      flight_route_id:t.flight_route_id||0, transfer_rate_id:t.transfer_rate_id||0,
      items:(t.items||[]).map(function(a){return{id:uid(),d:a.d,t:a.t,a:a.a};}),
      drinks:t.drinks!==undefined?t.drinks:true,
    });
  });
  initSafariItems();
  el('btnStep1Next').disabled=false;
  el('progBadge').style.display='';
  el('progBadge').textContent=TEMPLATES[key].icon+' '+TEMPLATES[key].name;
}

// ── Step 2: Client data ───────────────────────────────────────────────────────
(function preFill() {
  if (PRE.customer) el('fName').value    = PRE.customer;
  if (PRE.agent)    el('fAgent').value   = PRE.agent;
  if (PRE.agency)   el('fAgency').value  = PRE.agency;
  ['fAdults','fTeens','fChildren','fName','fAgent','fAgency','fDate'].forEach(function(id){
    el(id).addEventListener('input', updatePaxBar);
  });
  updatePaxBar();
})();

function updatePaxBar() {
  var p = pax(), j = jeeps();
  el('paxBar').textContent = p + ' PAX · ' + j + ' Jeep' + (j>1?'s':'') + ' · Bank commission: $'+BANK;
  el('btnStep2Next').disabled = p < 1;
}

// ── Step 3: Day cards ─────────────────────────────────────────────────────────
function addDay() {
  state.days.push(mkDay());
  renderDays();
}

function renderDays() {
  var p=pax();
  el('itinTitle').textContent='Itinerary — '+state.days.length+' days · '+p+' PAX';
  var list=el('dayList'); list.innerHTML='';
  state.days.forEach(function(d,idx){list.appendChild(buildDayCard(d,idx));});
  el('btnStep3Next').disabled=state.days.length===0;
  renderSafariItems();
}

function buildDayCard(d, idx) {
  var dc=calcDay(d,idx), date=getDayDate(idx), dateStr='', sd=v('fDate');
  if(sd){var dt=new Date(sd+'T00:00:00');dt.setDate(dt.getDate()+idx);dateStr=dt.toLocaleDateString('en-GB',{day:'2-digit',month:'short'});}
  var wrap=document.createElement('div'); wrap.className='day-card'; wrap.id='dc_'+d.id;

  // Lodge options
  var lodgeOpts='<option value="0">— None</option>';
  (PRICING_DATA.lodges||[]).forEach(function(l){
    var mp=l.default_meal_plan?'['+l.default_meal_plan+'] ':'';
    lodgeOpts+='<option value="'+l.id+'"'+(d.lodge_id==l.id?' selected':'')+'>'
      +mp+esc(l.name)+(l.location?' · '+esc(l.location):'')+'</option>';
  });
  lodgeOpts+='<option value="-1"'+(d.lodge_id==-1?' selected':'')+'>🔧 Custom (fixed total)</option>';

  // Rooms list (multi-room per day)
  var selLodge=(PRICING_DATA.lodges||[]).find(function(l){return l.id==d.lodge_id;});
  var roomsListHtml='';
  if(d.lodge_id>0){
    var roomRowsHtml='';
    (d.rooms||[]).forEach(function(r){
      var rRtOpts='<option value="0">— Room type</option>';
      if(selLodge){selLodge.room_types.forEach(function(rt){
        var up=getRoomUnitPrice(d.lodge_id,rt.id,date);
        var sel=rt.id==r.room_type_id?' selected':'';
        rRtOpts+='<option value="'+rt.id+'"'+sel+'>'+esc(rt.name)+' (max '+rt.max_pax+'pax'+(up?' — $'+Math.round(up):'')+')</option>';
      });}
      var up=r.room_type_id>0?getRoomUnitPrice(d.lodge_id,r.room_type_id,date):0;
      var qty=+r.qty||1;
      roomRowsHtml+='<div class="item-row">'
        +'<select class="f-inp" style="flex:3" onchange="updRoom(\''+d.id+'\',\''+r.id+'\',\'room_type_id\',parseInt(this.value))">'+rRtOpts+'</select>'
        +'<input class="f-inp" type="number" min="1" step="1" style="width:52px;text-align:center" value="'+qty+'" oninput="updRoom(\''+d.id+'\',\''+r.id+'\',\'qty\',+this.value||1)" placeholder="1">'
        +(up?'<span style="font-size:.71rem;color:#9ca3af;white-space:nowrap;min-width:58px;text-align:right">$'+Math.round(up)+'/rm</span>':'<span style="min-width:58px"></span>')
        +(up?'<span style="font-size:.72rem;font-weight:600;min-width:62px;text-align:right">'+fmt(up*qty)+'</span>':'<span style="min-width:62px"></span>')
        +'<button class="btn-rm" onclick="rmRoom(\''+d.id+'\',\''+r.id+'\')">×</button>'
        +'</div>';
    });
    var mpLabel=selLodge&&selLodge.default_meal_plan
      ?'<span style="background:#fef3c7;color:#92400e;border-radius:4px;padding:1px 7px;font-size:.7rem;font-weight:700;margin-left:8px">'+esc(selLodge.default_meal_plan)+'</span>':'';
    roomsListHtml='<div class="day-items" style="margin-top:8px">'
      +'<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">'
      +'<div class="day-items-lbl" style="margin-bottom:0">Rooms'+mpLabel+'</div>'
      +'</div>'
      +roomRowsHtml
      +'<button class="btn-add-item" onclick="addRoom(\''+d.id+'\')">+ Add room</button>'
      +'</div>';
  } else if(d.lodge_id===-1){
    roomsListHtml='<div style="margin-top:8px"><label class="f-lbl">Custom Lodge Total ($)</label>'
      +'<input class="f-inp" type="number" min="0" placeholder="Fixed total" value="'+esc(d.lodge_custom_total||'')+'" oninput="updDay(\''+d.id+'\',\'lodge_custom_total\',parseFloat(this.value)||0)"></div>';
  }

  // Lodge total cost badge
  var lodgeCost=0;
  if(d.lodge_id>0){
    (d.rooms||[]).forEach(function(r){if(r.room_type_id>0)lodgeCost+=getRoomUnitPrice(d.lodge_id,r.room_type_id,date)*(+r.qty||1);});
    lodgeCost+=getLodgeSupplements(d.lodge_id,date,+v('fAdults')||0);
  } else if(d.lodge_id===-1){lodgeCost=+d.lodge_custom_total||0;}
  var lodgeBadge=lodgeCost?'<span class="cost-badge" style="background:#d1fae5;color:#065f46;">🏠 '+fmt(lodgeCost)+'</span>':
    (d.lodge_id>0?'<span class="cost-badge" style="background:#fee2e2;color:#991b1b;" title="No pricing in DB — enter custom rate">🏠 $?</span>':'');

  // Jeep rate
  var jeepDbRate=d.jeep!=='none'?getJeepRate(d.jeep,date):0;
  var jeepRateVal=(d.jeep_rate_custom!==null&&d.jeep_rate_custom!=='')?d.jeep_rate_custom:jeepDbRate;
  var jeepRateHtml=d.jeep!=='none'
    ?'<div><label class="f-lbl">$/Jeep <span class="rate-db">(DB: $'+jeepDbRate+')</span></label><input class="f-inp" type="number" min="0" value="'+esc(jeepRateVal)+'" placeholder="'+jeepDbRate+'" oninput="updDay(\''+d.id+'\',\'jeep_rate_custom\',this.value===\'\'?null:+this.value)"></div>'
    :'<div></div>';

  // Jeep count input
  var jeepAuto=jeeps(), jeepCntVal=d.jeep_count!==null?d.jeep_count:jeepAuto;
  var jeepCountHtml=d.jeep!=='none'
    ?'<div><label class="f-lbl">Jeeps <span class="rate-db">(auto: '+jeepAuto+')</span></label><input class="f-inp" type="number" min="1" step="1" value="'+esc(jeepCntVal)+'" placeholder="'+jeepAuto+'" oninput="updDay(\''+d.id+'\',\'jeep_count\',this.value===\'\'?null:+this.value)"></div>'
    :'<div></div>';

  // Park — compute cost for badge
  var parkOpts='';
  Object.keys(PARK_FEES).forEach(function(k){var pf=PARK_FEES[k];parkOpts+='<option value="'+k+'"'+(d.park===k?' selected':'')+'>'+pf.l+(k!=='none'&&k!=='custom'?' ('+(pf.fx?'$'+pf.fx+'+':'')+'$'+pf.ppp+'/pax)':'')+'</option>';});
  var parkCustomHtml=d.park==='custom'?'<input class="f-inp" type="number" style="margin-top:5px" placeholder="Total $" value="'+esc(d.parkCust)+'" oninput="updDay(\''+d.id+'\',\'parkCust\',this.value)">':'';
  var parkCost=0;
  if(d.park==='custom') parkCost=+d.parkCust||0;
  else if(d.park&&d.park!=='none'&&PARK_FEES[d.park]){var pf=PARK_FEES[d.park];parkCost=pf.fx+pf.ppp*pax();}
  var parkBadge=parkCost?'<span class="cost-badge">'+fmt(parkCost)+'</span>':'';

  // Activity catalog
  var actCat=(PRICING_DATA.activity_rates||[]).filter(function(r){return r.category==='activity';});
  var actCatSel=actCat.length?'<select style="font-size:.74rem;padding:3px 6px;border:1px solid #d1d5db;border-radius:4px" onchange="addItemFromCatalog(\''+d.id+'\',this)"><option value="">+ From catalog</option>'+actCat.map(function(r){return'<option value="'+r.id+'">'+esc(r.name)+' ($'+r.rate+(r.item_type==='pax'?'/pax':'')+')</option>';}).join('')+'</select>':'';

  // Items
  var itemsHtml='';
  d.items.forEach(function(a){itemsHtml+='<div class="item-row" id="ir_'+a.id+'">'
    +'<input class="f-inp" style="flex:2" placeholder="Description" value="'+esc(a.d)+'" oninput="updItem(\''+d.id+'\',\''+a.id+'\',\'d\',this.value)">'
    +'<select class="f-inp" style="flex:1" onchange="updItem(\''+d.id+'\',\''+a.id+'\',\'t\',this.value)"><option value="p"'+(a.t==='p'?' selected':'')+'>$/pax</option><option value="f"'+(a.t==='f'?' selected':'')+'>Fixed</option></select>'
    +'<input class="f-inp" type="number" style="width:64px" placeholder="$" value="'+esc(a.a)+'" oninput="updItem(\''+d.id+'\',\''+a.id+'\',\'a\',this.value)">'
    +'<button class="btn-rm" onclick="rmItem(\''+d.id+'\',\''+a.id+'\')">×</button></div>';});

  // Flight
  var flOpts='<option value="0"'+(d.flight_route_id===0?' selected':'')+'>— None</option>';
  (PRICING_DATA.flight_routes||[]).forEach(function(r){flOpts+='<option value="'+r.id+'"'+(d.flight_route_id==r.id?' selected':'')+'>'+esc(r.route_name)+(r.airline?' — '+esc(r.airline):'')+'  ($'+r.rate_pax+'/pax)</option>';});
  flOpts+='<option value="-1"'+(d.flight_route_id===-1?' selected':'')+'>🔧 Custom (fixed $)</option>';
  var flRateHtml='<div></div>';
  var flCost=0;
  if(d.flight_route_id>0){var flr=getFlightRoute(d.flight_route_id),flDb=flr?flr.rate_pax:0,flVal=(d.flight_custom!==''&&d.flight_custom!==null)?d.flight_custom:flDb;flRateHtml='<div><label class="f-lbl">$/pax <span class="rate-db">(DB: $'+flDb+')</span></label><input class="f-inp" type="number" min="0" value="'+esc(flVal)+'" oninput="updDay(\''+d.id+'\',\'flight_custom\',this.value)"></div>';flCost=parseFloat(flVal)*pax();}
  else if(d.flight_route_id===-1){flRateHtml='<div><label class="f-lbl">Fixed Total $</label><input class="f-inp" type="number" min="0" value="'+esc(d.flight_custom)+'" oninput="updDay(\''+d.id+'\',\'flight_custom\',this.value)"></div>';flCost=+d.flight_custom||0;}
  var flBadge=flCost?'<span class="cost-badge">✈ '+fmt(flCost)+'</span>':'';

  // Transfer
  var trRates=(PRICING_DATA.activity_rates||[]).filter(function(r){return r.category==='transfer';});
  var trOpts='<option value="0"'+(d.transfer_rate_id===0?' selected':'')+'>— None</option>';
  trRates.forEach(function(r){trOpts+='<option value="'+r.id+'"'+(d.transfer_rate_id==r.id?' selected':'')+'>'+esc(r.name)+' ('+( r.item_type==='pax'?'$/pax':'fixed')+') — $'+r.rate+'</option>';});
  trOpts+='<option value="-1"'+(d.transfer_rate_id===-1?' selected':'')+'>🔧 Custom</option>';
  var trRateHtml='<div></div>';
  if(d.transfer_rate_id>0){var tra=getActivityRate(d.transfer_rate_id),trDb=tra?tra.rate:0,trType=tra?tra.item_type:'fixed',trVal=(d.transfer_custom!==''&&d.transfer_custom!==null)?d.transfer_custom:trDb;trRateHtml='<div><label class="f-lbl">'+(trType==='pax'?'$/pax':'Fixed $')+' <span class="rate-db">(DB: $'+trDb+')</span></label><input class="f-inp" type="number" min="0" value="'+esc(trVal)+'" oninput="updDay(\''+d.id+'\',\'transfer_custom\',this.value)"></div>';}
  else if(d.transfer_rate_id===-1){trRateHtml='<div><label class="f-lbl">Amount $</label><input class="f-inp" type="number" min="0" value="'+esc(d.transfer_custom||'')+'" oninput="updDay(\''+d.id+'\',\'transfer_custom\',this.value)"></div>';}
  var trCost=0;
  if(d.transfer_rate_id>0){var _tra=getActivityRate(d.transfer_rate_id);if(_tra){var _trVal=(d.transfer_custom!==''&&d.transfer_custom!==null)?+d.transfer_custom:+_tra.rate;trCost=_tra.item_type==='pax'?_trVal*pax():_trVal;}}else if(d.transfer_rate_id===-1){trCost=+d.transfer_custom||0;}
  var trBadge=trCost?'<span class="cost-badge">🚐 '+fmt(trCost)+'</span>':'';

  wrap.innerHTML=
    // ── Head ──
    '<div class="day-head" onclick="toggleDay(\''+d.id+'\')">'
      +'<div class="day-num">'+(idx+1)+'</div>'
      +(dateStr?'<div style="font-size:.72rem;color:#9ca3af;width:40px;flex-shrink:0">'+dateStr+'</div>':'')
      +'<div class="day-loc">'+(d.route||'<span style="color:#9ca3af">— New route</span>')
        +(d.attraction?'<span style="font-size:.73rem;color:#6b7280;margin-left:7px">'+esc(d.attraction)+'</span>':'')
      +'</div>'
      +'<div class="day-lodge">'+esc(dayLodgeLabel(d))+'</div>'
      +'<div class="day-total">'+fmt(dc)+'</div>'
      +'<div class="day-chevron" id="chev_'+d.id+'">▼</div>'
    +'</div>'
    // ── Body ──
    +'<div class="day-body" id="db_'+d.id+'" style="display:'+(idx<2?'block':'none')+'">'
      // ROUTE + DESTINATION
      +'<div class="day-fields" style="grid-template-columns:1fr 1fr">'
        +'<div><label class="f-lbl">Route</label><input class="f-inp" value="'+esc(d.route)+'" placeholder="e.g. Kili-Arusha" oninput="updDay(\''+d.id+'\',\'route\',this.value)"></div>'
        +'<div><label class="f-lbl">Destination</label><input class="f-inp" value="'+esc(d.attraction)+'" placeholder="e.g. Tarangire NP" oninput="updDay(\''+d.id+'\',\'attraction\',this.value)"></div>'
      +'</div>'
      // JEEP + COUNT + RATE + DRINKS
      +'<div class="day-fields" style="grid-template-columns:1fr 1fr 1fr 1fr;margin-top:8px">'
        +'<div><label class="f-lbl">Jeep</label><select class="f-inp" onchange="updDay(\''+d.id+'\',\'jeep\',this.value)">'
          +'<option value="none"'+(d.jeep==='none'?' selected':'')+'>— None</option>'
          +'<option value="half"'+(d.jeep==='half'?' selected':'')+'>Half day</option>'
          +'<option value="full"'+(d.jeep==='full'?' selected':'')+'>Full day</option>'
          +'<option value="double"'+(d.jeep==='double'?' selected':'')+'>Double</option>'
          +'<option value="contribution"'+(d.jeep==='contribution'?' selected':'')+'>Contribution</option>'
        +'</select></div>'
        +jeepCountHtml
        +jeepRateHtml
        +'<div><label class="f-lbl">Drinks &amp; Snacks</label><select class="f-inp" onchange="updDay(\''+d.id+'\',\'drinks\',this.value===\'y\')">'
          +'<option value="y"'+(d.drinks?' selected':'')+'>Yes ($4/pax)</option>'
          +'<option value="n"'+(!d.drinks?' selected':'')+'>No</option>'
        +'</select></div>'
      +'</div>'
      // PARK FEES
      +'<div style="margin-top:8px"><label class="f-lbl">Park Fees '+parkBadge+'</label>'
        +'<select class="f-inp" onchange="updDay(\''+d.id+'\',\'park\',this.value)">'+parkOpts+'</select>'
        +parkCustomHtml
      +'</div>'
      // ACTIVITIES
      +'<div class="day-items" style="margin-top:10px">'
        +'<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">'
          +'<div class="day-items-lbl" style="margin-bottom:0">Activities</div>'
          +actCatSel
        +'</div>'
        +itemsHtml
        +'<button class="btn-add-item" onclick="addItem(\''+d.id+'\')">+ Add custom</button>'
      +'</div>'
      // FLIGHT
      +'<div style="display:flex;gap:8px;margin-top:10px;align-items:flex-end">'
        +'<div style="flex:2"><label class="f-lbl">Flight '+flBadge+'</label><select class="f-inp" onchange="updDay(\''+d.id+'\',\'flight_route_id\',parseInt(this.value))">'+flOpts+'</select></div>'
        +flRateHtml
      +'</div>'
      // TRANSFER
      +'<div style="display:flex;gap:8px;margin-top:8px;align-items:flex-end">'
        +'<div style="flex:2"><label class="f-lbl">Transfer '+trBadge+'</label><select class="f-inp" onchange="updDay(\''+d.id+'\',\'transfer_rate_id\',parseInt(this.value))">'+trOpts+'</select></div>'
        +trRateHtml
      +'</div>'
      // LODGE + ROOMS
      +'<div style="margin-top:10px"><label class="f-lbl">Lodge '+lodgeBadge+'</label><select class="f-inp" onchange="updDay(\''+d.id+'\',\'lodge_id\',parseInt(this.value))">'+lodgeOpts+'</select></div>'
      +roomsListHtml
      // Footer
      +'<div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px">'
        +'<div style="font-size:.8rem;color:#C0211B;font-weight:600" data-dft>Day total: '+fmt(dc)+'</div>'
        +'<button onclick="removeDay(\''+d.id+'\')" style="font-size:.78rem;color:#ef4444;background:none;border:none;cursor:pointer;">🗑 Remove</button>'
      +'</div>'
    +'</div>';
  return wrap;
}

function toggleDay(id) {
  var body=el('db_'+id),chev=el('chev_'+id),open=body.style.display!=='none';
  body.style.display=open?'none':''; chev.textContent=open?'▼':'▲';
}

function updDay(did,field,val) {
  var d=state.days.find(function(x){return x.id===did;}); if(!d)return;
  d[field]=val;
  if(field==='lodge_id') applyDefaultRoomsToDay(d);
  if(field==='jeep') d.jeep_rate_custom=null;
  if(field==='flight_route_id') d.flight_custom='';
  if(field==='transfer_rate_id') d.transfer_custom='';
  var di=state.days.indexOf(d);
  // Only rebuild the card DOM for structural changes (show/hide sub-fields)
  var structural=['lodge_id','jeep','park','flight_route_id','transfer_rate_id'];
  if(structural.indexOf(field)!==-1){
    var old=el('dc_'+did),newCard=buildDayCard(d,di);
    var wasOpen=el('db_'+did)&&el('db_'+did).style.display!=='none';
    old.replaceWith(newCard);
    if(!wasOpen) el('db_'+did).style.display='none';
  } else {
    // Non-structural: update totals in existing DOM (preserves focus)
    var dc=calcDay(d,di),card=el('dc_'+did);
    if(card){
      card.querySelector('.day-total').textContent=fmt(dc);
      var ft=card.querySelector('[data-dft]');
      if(ft) ft.textContent='Day total: '+fmt(dc);
      if(field==='route'||field==='attraction'){
        var loc=card.querySelector('.day-loc');
        if(loc) loc.innerHTML=(d.route||'<span style="color:#9ca3af">— New route</span>')+(d.attraction?'<span style="font-size:.73rem;color:#6b7280;margin-left:7px">'+esc(d.attraction)+'</span>':'');
      }
    }
  }
}

// ── Room rows (mixed room types per day) ─────────────────────────────────────
function rebuildDay(did){
  var d=state.days.find(function(x){return x.id===did;}); if(!d)return;
  var di=state.days.indexOf(d),old=el('dc_'+did),newCard=buildDayCard(d,di);
  var wasOpen=el('db_'+did)&&el('db_'+did).style.display!=='none';
  old.replaceWith(newCard);
  if(!wasOpen) el('db_'+did).style.display='none';
}
function addRoom(did){
  var d=state.days.find(function(x){return x.id===did;}); if(!d)return;
  d.rooms.push(mkRoom()); rebuildDay(did);
}
function rmRoom(did,rid){
  var d=state.days.find(function(x){return x.id===did;}); if(!d)return;
  d.rooms=d.rooms.filter(function(r){return r.id!==rid;}); rebuildDay(did);
}
function updRoom(did,rid,field,val){
  var d=state.days.find(function(x){return x.id===did;}); if(!d)return;
  var r=d.rooms.find(function(x){return x.id===rid;}); if(!r)return;
  r[field]=val;
  if(field==='room_type_id'){
    rebuildDay(did);
  } else {
    // qty change — just update totals in DOM
    var di=state.days.indexOf(d),dc=calcDay(d,di),card=el('dc_'+did);
    if(card){
      card.querySelector('.day-total').textContent=fmt(dc);
      var ft=card.querySelector('[data-dft]'); if(ft) ft.textContent='Day total: '+fmt(dc);
    }
  }
}

function addItem(did) {
  var d=state.days.find(function(x){return x.id===did;});
  if(d){d.items.push({id:uid(),d:'',t:'f',a:''});rebuildDay(did);}
}
function addItemFromCatalog(did,sel) {
  var rid=parseInt(sel.value); if(!rid){return;}
  var r=getActivityRate(rid); if(!r){sel.value='';return;}
  var d=state.days.find(function(x){return x.id===did;});
  if(d){d.items.push({id:uid(),d:r.name,t:r.item_type==='pax'?'p':'f',a:String(r.rate)});rebuildDay(did);}
}
function updItem(did,iid,field,val) {
  var d=state.days.find(function(x){return x.id===did;}); if(!d)return;
  var item=d.items.find(function(x){return x.id===iid;}); if(item)item[field]=val;
  var di=state.days.indexOf(d),dc=calcDay(d,di),card=el('dc_'+did);
  if(card){
    card.querySelector('.day-total').textContent=fmt(dc);
    var ft=card.querySelector('[data-dft]'); if(ft) ft.textContent='Day total: '+fmt(dc);
  }
}
function rmItem(did,iid) {
  var d=state.days.find(function(x){return x.id===did;});
  if(d){d.items=d.items.filter(function(x){return x.id!==iid;});rebuildDay(did);}
}
function removeDay(did) {
  state.days=state.days.filter(function(d){return d.id!==did;}); renderDays();
}

// ── Step 4: Summary ───────────────────────────────────────────────────────────
function setMarkup(type) {
  state.markup = type;
  ['Std','To','Cust'].forEach(function(k){ el('mk'+k).className = 'mkbtn'+(state.markup===k.toLowerCase()?' active':''); });
  // fix: map standard→Std, to→To, custom→Cust
  var map = {standard:'Std',to:'To',custom:'Cust'};
  Object.keys(map).forEach(function(k){ el('mk'+map[k]).className = 'mkbtn'+(state.markup===k?' active':''); });
  el('custMkWrap').style.display = type==='custom' ? 'flex' : 'none';
  renderSummary();
}

function renderSummary() {
  var tot=calcTotals(), p=pax(), rows='';
  // Safari fixed items block
  if(state.safariItems.length){
    rows+='<tr><td colspan="4" style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#92400e;padding:10px 12px 3px;background:#fffbeb">Safari Fixed</td></tr>';
    state.safariItems.forEach(function(si){
      var t=si.item_type==='pax'?(+si.amount||0)*p:(+si.amount||0);
      rows+='<tr style="background:#fffbeb"><td></td><td style="color:#374151">'+esc(si.description)+'</td>'
        +'<td style="color:#6b7280;font-size:.78rem">'+(si.item_type==='pax'?'$'+si.amount+'×'+p+' pax':'fixed')+'</td>'
        +'<td style="text-align:right;font-family:monospace">'+fmt(t)+'</td></tr>';
    });
  }
  // Days
  rows+='<tr><td colspan="4" style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#6b7280;padding:10px 12px 3px">Days</td></tr>';
  state.days.forEach(function(d,i){
    var dc=calcDay(d,i), date=getDayDate(i), p=pax();
    var j=d.jeep_count!==null?+d.jeep_count:jeeps();

    // Component breakdown
    var parts=[];

    // Lodge
    var lodgeLabel=dayLodgeLabel(d);
    var lodgeCost=0;
    if(d.lodge_id>0){
      (d.rooms||[]).forEach(function(r){
        if(r.room_type_id>0) lodgeCost+=getRoomUnitPrice(d.lodge_id,r.room_type_id,date)*(+r.qty||1);
      });
      lodgeCost+=getLodgeSupplements(d.lodge_id,date,+v('fAdults')||0);
    } else if(d.lodge_id===-1){ lodgeCost=+d.lodge_custom_total||0; }
    if(lodgeCost) parts.push('Lodge $'+Math.round(lodgeCost));

    // Jeep
    if(d.jeep!=='none'){
      var jr=(d.jeep_rate_custom!==null&&d.jeep_rate_custom!=='')?+d.jeep_rate_custom:getJeepRate(d.jeep,date);
      var jc=jr*j;
      parts.push(d.jeep.charAt(0).toUpperCase()+d.jeep.slice(1)+' jeep $'+Math.round(jr)+(j>1?'×'+j:'')+'=$'+Math.round(jc));
    }

    // Park
    if(d.park==='custom'&&+d.parkCust){ parts.push('Park $'+Math.round(+d.parkCust)); }
    else if(d.park&&d.park!=='none'&&PARK_FEES[d.park]){
      var pf=PARK_FEES[d.park]; var pc=pf.fx+pf.ppp*p;
      parts.push('Park $'+Math.round(pc));
    }

    // Drinks
    if(d.drinks) parts.push('Drinks $'+Math.round(4*p));

    // Flight
    if(d.flight_route_id>0){
      var flr=getFlightRoute(d.flight_route_id);
      var flRate=(d.flight_custom!==''&&d.flight_custom!==null)?+d.flight_custom:(flr?parseFloat(flr.rate_pax):0);
      if(flRate) parts.push('✈ $'+Math.round(flRate*p));
    } else if(d.flight_route_id===-1&&+d.flight_custom){ parts.push('✈ $'+Math.round(+d.flight_custom)); }

    // Transfer
    if(d.transfer_rate_id>0){
      var tra=getActivityRate(d.transfer_rate_id);
      var trRate=(d.transfer_custom!==''&&d.transfer_custom!==null)?+d.transfer_custom:(tra?parseFloat(tra.rate):0);
      var tc=tra&&tra.item_type==='pax'?trRate*p:trRate;
      if(tc) parts.push('Transfer $'+Math.round(tc));
    } else if(d.transfer_rate_id===-1&&+d.transfer_custom){ parts.push('Transfer $'+Math.round(+d.transfer_custom)); }

    // Activity items
    d.items.forEach(function(a){
      var ac=a.t==='p'?(+a.a||0)*p:(+a.a||0);
      if(ac) parts.push(esc(a.d)+' $'+Math.round(ac));
    });

    var detailHtml=parts.length
      ?'<div style="font-size:.7rem;color:#9ca3af;margin-top:2px;line-height:1.6">'+parts.join(' &nbsp;·&nbsp; ')+'</div>'
      :'';

    rows+='<tr><td style="vertical-align:top">'+(i+1)+'</td>'
      +'<td style="font-weight:500;vertical-align:top">'+(d.route||'—')
        +(d.attraction?'<br><span style="font-size:.72rem;color:#6b7280">'+esc(d.attraction)+'</span>':'')+'</td>'
      +'<td style="color:#6b7280;vertical-align:top">'+esc(lodgeLabel)+detailHtml+'</td>'
      +'<td style="text-align:right;font-family:monospace;font-weight:500;vertical-align:top">'+fmt(dc)+'</td></tr>';
  });
  rows+='<tr style="border-top:2px solid #e5e7eb">'
    +'<td colspan="3" style="text-align:right;color:#6b7280;font-size:.8rem">Bank Commission</td>'
    +'<td style="text-align:right;font-family:monospace;color:#6b7280">'+fmt(BANK)+'</td></tr>';
  el('sumRows').innerHTML=rows;

  var foot='<tr style="font-weight:700;background:#f9fafb">'
    +'<td colspan="3" style="text-align:right;padding:9px 12px">Net Total Costs</td>'
    +'<td style="text-align:right;font-family:monospace;padding:9px 12px">'+fmt(tot.costs)+'</td></tr>'
    +'<tr style="color:#C0211B">'
    +'<td colspan="3" style="text-align:right;font-size:.82rem;padding:7px 12px">Markup ('+(tot.mk*100).toFixed(0)+'%)</td>'
    +'<td style="text-align:right;font-family:monospace;font-size:.82rem;padding:7px 12px">+ '+fmt(tot.costs*tot.mk)+'</td></tr>'
    +'<tr class="sum-total-row">'
    +'<td colspan="3" style="text-align:right;padding:12px">TOTAL PRICE</td>'
    +'<td style="text-align:right;font-family:monospace;padding:12px">'+fmt(tot.price)+'</td></tr>';
  el('sumFoot').innerHTML=foot;
  var single=state.program==='beachkiboko'?'$650':'$250';
  el('kpiGrid').innerHTML=kpiBox('Price p.p. (rack)',fmt(tot.ppp),true)+kpiBox('Price p.p. (T.O.)',fmt(tot.pppTo),false)+kpiBox('Single supplement',single,false)+kpiBox('Deposit (30%)',fmt(tot.deposit),false);
  updateFnamePreview();
}
function updateFnamePreview() {
  var custClean=(v('fName')||'Customer').replace(/\s+/g,'');
  var el2=el('fnameCustomer'); if(el2) el2.textContent=custClean;
}
function kpiBox(lbl, val, hi) {
  return '<div class="kpi'+(hi?' hi':'')+'"><div class="kpi-lbl">'+lbl+'</div><div class="kpi-val">'+val+'</div></div>';
}

// ── Save ──────────────────────────────────────────────────────────────────────
function saveQuote() {
  var btn = el('btnSave');
  btn.disabled = true; btn.textContent = 'Saving…';

  var payload = {
    csrf:       '<?= $csrfToken ?>',
    request_id: REQ_ID,
    quote_id:        window._editQuoteId || null,
    quote_number:    v('fQuoteNum'),
    customer_name:   v('fName'),
    agent_name:      v('fAgent'),
    agency_name:     v('fAgency'),
    start_date:      v('fDate'),
    adults:          +v('fAdults')||0,
    teens:           +v('fTeens') ||0,
    children:        +v('fChildren')||0,
    default_rooms: state.defaultRooms.map(function(dr){ return {label:dr.label, qty:+dr.qty||1}; }),
    program:         state.program,
    markup_type:     state.markup,
    markup_pct:      mkPct()*100,
    bank_commission: BANK,
    safari_items: state.safariItems.map(function(si){
      return {activity_rate_id:si.activity_rate_id||null, description:si.description,
              item_type:si.item_type, amount:+si.amount||0};
    }),
    days: state.days.map(function(d,i){
      return {
        route:            d.route,
        attraction:       d.attraction,
        lodge:            d.lodge_id>0?getLodgeName(d.lodge_id):(d.lodge_id===-1?'Custom':''),
        lodge_id:         d.lodge_id>0?d.lodge_id:null,
        lodge_custom:     d.lodge_id===-1?(+d.lodge_custom_total||0):0,
        rooms: d.lodge_id>0?(d.rooms||[]).filter(function(r){return r.room_type_id>0;}).map(function(r){
          var dateStr=getDayDate(i),up=getRoomUnitPrice(d.lodge_id,r.room_type_id,dateStr);
          return{room_type_id:r.room_type_id,room_type_name:getRoomTypeName(d.lodge_id,r.room_type_id),qty:+r.qty||1,unit_price:up,total_price:up*(+r.qty||1)};
        }):[],
        jeep:             d.jeep,
        jeep_count:       d.jeep_count!==null?+d.jeep_count:null,
        jeep_rate_custom: (d.jeep_rate_custom!==null&&d.jeep_rate_custom!=='')?+d.jeep_rate_custom:null,
        drinks:           d.drinks?1:0,
        park:             d.park,
        park_custom:      +d.parkCust||0,
        flight_route_id:  d.flight_route_id>0?d.flight_route_id:null,
        flight_custom:    +d.flight_custom||0,
        transfer_rate_id: d.transfer_rate_id>0?d.transfer_rate_id:null,
        transfer_custom:  +d.transfer_custom||0,
        day_total:        calcDay(d,i),
        items: d.items.map(function(a){return{description:a.d,item_type:a.t==='p'?'pax':'fixed',amount:+a.a||0};})
      };
    })
  };

  var tot = calcTotals();
  payload.total_costs = tot.costs;
  payload.total_price = tot.price;

  fetch('api_save_quote.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  })
  .then(function(r){ return r.json(); })
  .then(function(res) {
    if (!res.ok) { alert('Error: ' + (res.error||'unknown')); btn.disabled=false; btn.textContent='💾 Save & Generate Excel'; return; }
    window._savedId = res.id;
    var custClean = (v('fName')||'Customer').replace(/\s+/g,'');
    el('savedFilename').textContent = res.quote_number + '_' + custClean + '.xlsx';
    if (window._editQuoteId) {
      el('savedBox').querySelector('div:nth-child(2)').textContent = 'Quote updated!';
    }
    el('step4').style.display = 'none';
    el('savedBox').style.display = '';
  })
  .catch(function(e){ alert('Network error: '+e); btn.disabled=false; btn.textContent='💾 Save & Generate Excel'; });
}

// ── Edit mode: load existing quote into wizard ────────────────────────────────
function loadEditData(data) {
  // Reconstruct state from saved quote
  state.program = data.program || 'simba';
  state.markup  = data.markup_type || 'standard';
  state.custMk  = data.markup_pct || 25;

  state.safariItems = (data.safari_items || []).map(function(si) {
    return {id:uid(), activity_rate_id:si.activity_rate_id||null,
            description:si.description, item_type:si.item_type, amount:si.amount};
  });
  if (!state.safariItems.length) initSafariItems();

  // Restore default rooms (saved in DB since this fix)
  // Fallback: infer from first day that has rooms
  if (data.default_rooms && data.default_rooms.length) {
    state.defaultRooms = data.default_rooms.map(function(dr){
      return mkDefaultRoom({label: dr.label || '', qty: +dr.qty || 1});
    });
  } else {
    // Infer default rooms from the first day that has rooms
    var firstDayWithRooms = (data.days || []).find(function(d){ return d.rooms && d.rooms.length; });
    if (firstDayWithRooms) {
      state.defaultRooms = firstDayWithRooms.rooms.map(function(r){
        var lodge = (PRICING_DATA.lodges||[]).find(function(l){return l.id==firstDayWithRooms.lodge_id;});
        var rt    = lodge ? (lodge.room_types||[]).find(function(t){return t.id==r.room_type_id;}) : null;
        return mkDefaultRoom({label: rt ? rt.name : '', qty: +r.qty || 1});
      });
    }
  }

  state.days = (data.days || []).map(function(d) {
    return mkDay({
      route:            d.route || '',
      attraction:       d.attraction || '',
      lodge_id:         +d.lodge_id || 0,
      lodge_custom_total: +d.lodge_custom || 0,
      rooms:            (d.rooms || []).map(function(r){ return mkRoom({room_type_id:+r.room_type_id||0, qty:+r.qty||1}); }),
      jeep:             d.jeep || 'full',
      jeep_count:       d.jeep_count !== null ? +d.jeep_count : null,
      jeep_rate_custom: d.jeep_rate_custom !== null ? +d.jeep_rate_custom : null,
      drinks:           d.drinks !== 0,
      park:             d.park || 'none',
      parkCust:         String(d.park_custom || ''),
      flight_route_id:  +d.flight_route_id || 0,
      flight_custom:    String(d.flight_custom || ''),
      transfer_rate_id: +d.transfer_rate_id || 0,
      transfer_custom:  String(d.transfer_custom || ''),
      items: (d.items || []).map(function(it){
        return {id:uid(), d:it.description, t:it.item_type==='pax'?'p':'f', a:String(it.amount)};
      }),
    });
  });

  // Pre-fill step 2 fields
  el('fName').value    = data.customer_name || '';
  el('fAgent').value   = data.agent_name    || '';
  el('fAgency').value  = data.agency_name   || '';
  if (data.start_date) el('fDate').value = data.start_date;
  el('fAdults').value   = data.adults   || 0;
  el('fTeens').value    = data.teens    || 0;
  el('fChildren').value = data.children || 0;
  updatePaxBar();

  // Pre-fill step 4 markup
  setMarkup(data.markup_type || 'standard');
  if (data.markup_type === 'custom') {
    el('custMkVal').value = data.markup_pct || 25;
  }

  // Mark template selected
  if (data.program && el('tpl_'+data.program)) {
    el('tpl_'+data.program).className = 'tpl-card selected';
  }
  el('btnStep1Next').disabled = false;
  el('progBadge').style.display = '';
  el('progBadge').textContent = '✏️ Editing #' + data.quote_number;

  // Store edit id for save
  window._editQuoteId = data.id;
  // Render restored default rooms
  renderDefaultRooms();
}

// ── Init ──────────────────────────────────────────────────────────────────────
if (EDIT_DATA) {
  loadEditData(EDIT_DATA);
  goTo(3); // skip template & client steps, open itinerary directly
} else {
  goTo(1);
}
</script>

<?php include 'includes/footer.php'; ?>
