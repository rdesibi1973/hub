<?php
require_once 'config.php';
require_once 'includes/pipeline_service.php'; // pl_columns / pl_fetch / pl_move — shared with the Agent API (pipeline_list / pipeline_move)
requireLogin();

$isStaff      = isLeadsRestricted();
$staffAgentId = $isStaff ? getStaffAgentId() : 0;
$db           = db();
$currentUser  = current_user();
$myRole       = $currentUser['role_name'] ?? '';
$stmt = db()->prepare("SELECT agent_id FROM users WHERE id=?");
$stmt->execute([$currentUser['id']]);
$myAgentId = (int)($stmt->fetchColumn() ?: 0);
$canSeeAll    = in_array($myRole, ['admin','manager']);

// ── AJAX: move card ───────────────────────────────────────────────────────
// Status and column stay in step (see "Group cards" below): dropping a card sets
// the status of its column — NEW/WIP → Inquiry, QUOTED → Quoted, HOT → Hot-Quoted.
// CONFIRMED only moves the card. Closed statuses (Booked/Lost/Cancelled) are kept.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    $id  = (int)($_POST['id']  ?? 0);
    $col = trim($_POST['col'] ?? '');
    if (!$id || !isset(pl_columns(true)[$col])) {
        echo json_encode(['ok'=>false,'message'=>'Invalid params']); exit;
    }
    // Staff can only move their own requests. PARKED = park (parked_service.php), out of PARKED = unpark.
    $res = pl_move($db, $id, $col, ['restrict_agent_id' => ($isStaff && $staffAgentId) ? $staffAgentId : 0,
                                    'user_id' => (int)($currentUser['id'] ?? 0)]);
    if (!$res['ok']) { echo json_encode(['ok'=>false,'message'=>$res['error']]); exit; }
    echo json_encode(['ok'=>true, 'status'=>pl_columns(true)[$col]['status'] !== null ? $res['status_to'] : null]); exit;
}

// ── Filters ────────────────────────────────────────────────────────────────
$pageTitle   = 'Pipeline';
$filterYear  = (int)($_GET['year']  ?? 0);

// Default agent filter: own agent. Admin/manager can override to another or All (0).
if ($canSeeAll) {
    $filterAgent = isset($_GET['agent']) ? (int)$_GET['agent'] : $myAgentId;
} else {
    // Non-admin/manager: always locked to their own agent
    $filterAgent = $myAgentId ?: ($isStaff ? $staffAgentId : 0);
}

$agents = $db->query(
    "SELECT a.id, a.name FROM agents a
     JOIN users u ON u.agent_id = a.id
     WHERE a.active = 1 AND u.is_active = 1 ORDER BY a.name"
)->fetchAll(PDO::FETCH_ASSOC);

$years = $db->query(
    "SELECT DISTINCT YEAR(date_received) AS y FROM requests ORDER BY y DESC LIMIT 5"
)->fetchAll(PDO::FETCH_COLUMN);

// ── Columns + cards (includes/pipeline_service.php: the grouping rules live there) ──
// Parked requests are always fetched (for the toggle's count) but shown only with ?parked=1.
$showParked = !empty($_GET['parked']);
$board   = pl_fetch($db, ['agent_id' => ($isStaff && $staffAgentId) ? $staffAgentId : $filterAgent,
                          'year'     => $filterYear, 'include_parked' => true]);
$byCol   = $board['by_col'];
$nParked = count($byCol['parked']);
$columns = pl_columns($showParked);
if (!$showParked) unset($byCol['parked']);
$rows    = array_filter($board['rows'], function ($r) use ($showParked) { return $showParked || $r['status'] !== PK_STATUS; });
$toggleQs = http_build_query(array_filter(['agent' => $canSeeAll && isset($_GET['agent']) ? (int)$_GET['agent'] : null,
                                           'year' => $filterYear ?: null, 'parked' => $showParked ? null : 1],
                                          function ($v) { return $v !== null; }));

include 'includes/header.php';
?>
<style>
.pipeline-wrap {
  padding: 0 24px 40px;
  overflow-x: auto;
  min-height: calc(100vh - 140px);
}
.pipeline-toolbar {
  display: flex; align-items: center; gap: 12px;
  padding: 14px 0 16px; flex-wrap: wrap;
}
.pipeline-toolbar h2 {
  font-family: 'Merriweather', serif;
  font-size: 1.15rem; color: var(--red-dk); margin-right: 8px;
}
.pipeline-toolbar select {
  font-size: .8rem; padding: 5px 10px;
  border: 1.5px solid var(--grey-lt); border-radius: 6px;
  background: var(--white); color: var(--grey-dk);
}
.pipeline-toolbar select:focus { border-color: var(--red); outline: none; }

.pipeline-board {
  display: flex; gap: 14px; align-items: flex-start; min-width: 940px;
}
.pipeline-col {
  flex: 1; min-width: 0; background: #F5F5F5;
  border-radius: 10px; overflow: hidden; border: 1.5px solid var(--grey-lt);
  transition: border-color .15s, background .15s;
}
.pipeline-col.drag-over { border-color: var(--red); background: var(--red-lt); }

.col-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 12px 9px; font-size: .72rem; font-weight: 700;
  letter-spacing: .08em; text-transform: uppercase;
  border-bottom: 3px solid transparent;
}
.col-new    .col-header { background: var(--blue-lt);  color: var(--blue);   border-color: var(--blue); }
.col-wip    .col-header { background: #EDE9FE;          color: #5B21B6;       border-color: #7C3AED; }
.col-quoted .col-header { background: var(--amber-lt); color: var(--amber);  border-color: var(--amber); }
.col-hot    .col-header { background: #FFF0E0;          color: #C45000;       border-color: #E87722; }
.col-booked .col-header { background: var(--green-lt); color: var(--green);  border-color: var(--green); }
.col-parked .col-header { background: #EEF0F3;          color: #4B5563;       border-color: #9CA3AF; cursor: pointer; }
.pipeline-col.col-parked.collapsed { flex: 0 0 150px; }
.pipeline-col.col-parked.collapsed .col-body { display: none; }
.pipeline-col.col-parked.collapsed.drag-over .col-header { background: var(--red-lt); }
.parked-toggle { font-size: .78rem; color: var(--grey-dk); text-decoration: none; border: 1.5px solid var(--grey-lt);
                 border-radius: 6px; padding: 4px 9px; background: var(--white); }
.parked-toggle:hover { border-color: var(--red); }
.card-park {
  position: absolute; top: 6px; right: 6px; z-index: 2;
  font-size: .64rem; font-weight: 700; color: #4B5563; background: #EEF0F3;
  border: 1px solid #D1D5DB; border-radius: 4px; padding: 1px 5px; cursor: pointer; display: none;
}
.pipeline-card:hover .card-park { display: block; }
.pipeline-card[data-col="parked"] .card-park,
.pipeline-card[data-status="Booked"] .card-park { display: none !important; }
.card-parked-at { font-size: .68rem; color: #4B5563; }

.col-count {
  background: rgba(0,0,0,.1); border-radius: 10px;
  padding: 1px 7px; font-size: .68rem; font-weight: 700;
}
.col-body {
  padding: 8px; min-height: 200px;
  display: flex; flex-direction: column; gap: 7px;
}

/* ── Cards ─────────────────────────────────────────────────────────────── */
.pipeline-card {
  background: #fff; border: 1.5px solid var(--grey-lt);
  border-radius: 8px; padding: 10px 11px; cursor: grab;
  transition: box-shadow .15s, transform .1s, border-color .15s;
  user-select: none; position: relative;
}
.pipeline-card:hover { box-shadow: 0 3px 10px rgba(0,0,0,.10); border-color: #ccc; }
.pipeline-card.dragging {
  opacity: .5; transform: rotate(1.5deg);
  box-shadow: 0 6px 20px rgba(0,0,0,.15);
}
.card-name {
  font-weight: 700; font-size: .82rem; color: var(--grey-dk);
  line-height: 1.3; margin-bottom: 4px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.card-meta {
  font-size: .71rem; color: var(--grey-mid);
  display: flex; flex-wrap: wrap; gap: 4px 10px; margin-top: 3px;
}
.card-meta span { white-space: nowrap; }
.card-footer {
  display: flex; align-items: center; gap: 6px; margin-top: 6px; flex-wrap: wrap;
}
.card-agent {
  font-size: .68rem; font-weight: 700; color: #fff;
  background: var(--grey-mid); border-radius: 4px; padding: 1px 5px;
}
.card-status-badge {
  font-size: .67rem; font-weight: 700; border-radius: 4px;
  padding: 1px 6px; text-transform: uppercase; letter-spacing: .04em;
}
.card-link { position: absolute; inset: 0; border-radius: 8px; }

.col-empty {
  text-align: center; color: var(--grey-mid);
  font-size: .75rem; padding: 24px 8px; font-style: italic;
}

#move-toast {
  position: fixed; bottom: 28px; right: 28px;
  background: var(--grey-dk); color: #fff;
  padding: 10px 18px; border-radius: 8px; font-size: .8rem; font-weight: 600;
  box-shadow: 0 4px 16px rgba(0,0,0,.25);
  opacity: 0; transform: translateY(8px);
  transition: opacity .2s, transform .2s;
  pointer-events: none; z-index: 9999;
}
#move-toast.show  { opacity: 1; transform: translateY(0); }
#move-toast.error { background: var(--red-dk); }
</style>

<div class="pipeline-wrap">
  <form method="get" class="pipeline-toolbar">
    <h2>🔥 Pipeline</h2>
    <?php if ($canSeeAll): ?>
    <select name="agent" onchange="this.form.submit()">
      <option value="0" <?= $filterAgent===0?'selected':'' ?>>All agents</option>
      <?php foreach ($agents as $ag): ?>
      <option value="<?= $ag['id'] ?>" <?= $filterAgent==$ag['id']?'selected':'' ?>>
        <?= h($ag['name']) ?>
      </option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select name="year" onchange="this.form.submit()">
      <option value="0" <?= $filterYear===0?'selected':'' ?>>All years</option>
      <?php foreach ($years as $y): ?>
      <option value="<?= $y ?>" <?= $filterYear==$y?'selected':'' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
    <span style="font-size:.78rem;color:var(--grey-mid)"><?= count($rows) ?> requests</span>
    <a class="parked-toggle" id="parked-toggle" href="pipeline.php?<?= h($toggleQs) ?>"
       title="<?= h(PK_TIP) ?>"><?= $showParked ? '🅿️ Hide parked' : '🅿️ Show parked' ?> (<span id="parked-n"><?= $nParked ?></span>)</a>
    <?php if ($showParked): ?><input type="hidden" name="parked" value="1"><?php endif; ?>
    <button type="submit" class="btn btn-outline btn-sm" style="margin-left:auto;display:flex;align-items:center;gap:6px;">
      ↺ Refresh
    </button>
  </form>

  <div class="pipeline-board" id="board">
    <?php foreach ($columns as $colKey => $col): ?>
    <div class="pipeline-col <?= $col['cls'] ?><?= $colKey === 'parked' ? ' collapsed' : '' ?>"
         data-col="<?= $colKey ?>"
         ondragover="onDragOver(event,this)"
         ondragleave="onDragLeave(this)"
         ondrop="onDrop(event,this)">
      <div class="col-header"<?= $colKey === 'parked' ? ' onclick="this.parentNode.classList.toggle(\'collapsed\')" title="' . h(PK_TIP) . ' — click to open / close"' : '' ?>>
        <span><?= $col['icon'] ?>&nbsp; <?= $col['label'] ?></span>
        <span class="col-count" id="cnt-<?= $colKey ?>"><?= count($byCol[$colKey]) ?></span>
      </div>
      <div class="col-body" id="col-<?= $colKey ?>">
        <?php if (empty($byCol[$colKey])): ?>
        <div class="col-empty" id="empty-<?= $colKey ?>">No requests</div>
        <?php endif; ?>
        <?php foreach ($byCol[$colKey] as $r):
          $dest = $r['destination'] ?: '';
          $pax  = $r['pax']       ? $r['pax'].' pax' : '';
          $val  = $r['value_usd'] ? '$'.number_format($r['value_usd'],0) : '';
          $date = date('d M', strtotime($r['date_received']));
          // Status badge colors inline (no dependency on CSS class names for WIP)
          $sbg  = ['Inquiry'=>'#E5F0FC','Quoted'=>'#FEF0E5','Hot'=>'#FFF0E0','Hot-Quoted'=>'#FFF0E0',
                   'Booked' =>'#EBF5EE','Lost'  =>'#F0F0F0','Cancelled'=>'#FAE8E7','Parked'=>'#EEF0F3'];
          $sco  = ['Inquiry'=>'#0062B1','Quoted'=>'#E87722','Hot'=>'#C45000','Hot-Quoted'=>'#C45000',
                   'Booked' =>'#1A6B3A','Lost'  =>'#888',   'Cancelled'=>'#C0211B','Parked'=>'#4B5563'];
          $st      = $r['status'];
          $stLabel = ($st === 'Booked') ? 'Confirmed' : $st;
        ?>
        <div class="pipeline-card"
             draggable="true"
             data-id="<?= $r['id'] ?>"
             data-col="<?= $colKey ?>"
             data-status="<?= h($st) ?>"
             ondragstart="onDragStart(event,this)"
             ondragend="onDragEnd(this)">
          <button type="button" class="card-park" title="<?= h(PK_TIP) ?>"
                  onclick="event.preventDefault();event.stopPropagation();moveCard(this.parentNode.dataset.id, this.parentNode.dataset.col, 'parked')">🅿️ Park</button>
          <div class="card-name" title="<?= h($r['customer_name']) ?>">
            <?= h($r['customer_name']) ?>
          </div>
          <div class="card-meta">
            <?php if ($dest): ?><span>🌍 <?= h($dest) ?></span><?php endif; ?>
            <?php if ($r['period']): ?><span>🗓 <?= h($r['period']) ?></span><?php endif; ?>
            <?php if ($pax): ?><span>👥 <?= $pax ?></span><?php endif; ?>
            <?php if ($val): ?><span>💵 <?= $val ?></span><?php endif; ?>
            <span>📅 <?= $date ?></span>
          </div>
          <?php if ($st === PK_STATUS && !empty($r['parked_at'])): ?>
          <div class="card-parked-at">🅿️ Parked on <?= date('d M Y', strtotime($r['parked_at'])) ?></div>
          <?php endif; ?>
          <div class="card-footer">
            <span class="card-status-badge"
                  style="background:<?= $sbg[$st]??'#eee' ?>;color:<?= $sco[$st]??'#444' ?>">
              <?= h($stLabel) ?>
            </span>
            <?php if ($st === 'Booked'): ?>
            <span class="card-status-badge" style="background:#FBF3DC;color:#8A6A12" title="The card leaves the board when the folder gets _CK">CK pending</span>
            <?php endif; ?>
            <?php if (!$isStaff && $r['agent_name']): ?>
            <span class="card-agent"><?= h($r['agent_name']) ?></span>
            <?php endif; ?>
          </div>
          <a class="card-link" href="request_view.php?id=<?= $r['id'] ?>" title="Open" target="_blank"></a>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<div id="move-toast"></div>

<script>
let dragId = null, fromCol = null;

function onDragStart(e, card) {
  dragId  = card.dataset.id;
  fromCol = card.dataset.col;
  card.classList.add('dragging');
  e.dataTransfer.effectAllowed = 'move';
  e.stopPropagation();
}
function onDragEnd(card)      { card.classList.remove('dragging'); }
function onDragOver(e, col)   { e.preventDefault(); col.classList.add('drag-over'); }
function onDragLeave(col)     { col.classList.remove('drag-over'); }

function onDrop(e, col) {
  e.preventDefault();
  col.classList.remove('drag-over');
  const toCol = col.dataset.col;
  if (!dragId || toCol === fromCol) return;
  moveCard(dragId, fromCol, toCol);
}

async function moveCard(id, from, to) {
  const card     = document.querySelector(`.pipeline-card[data-id="${id}"]`);
  const fromBody = document.getElementById('col-' + from);
  const toBody   = document.getElementById('col-' + to);
  // Parking with the PARKED column hidden: the card leaves the board (no target column on the page).
  if (!card || (!toBody && to !== 'parked')) return;

  // Optimistic move
  card.dataset.col = to;
  if (toBody) toBody.appendChild(card); else card.style.display = 'none';
  updateCount(from, -1);
  updateCount(to,   +1);
  updateEmpty(from);
  updateEmpty(to);

  try {
    const res  = await fetch('pipeline.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded',
                 'X-Requested-With': 'XMLHttpRequest' },
      body:    'id=' + encodeURIComponent(id) + '&col=' + encodeURIComponent(to)
                 + '&from=' + encodeURIComponent(from)
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.message || 'Error');
    if (data.status) { setStatusBadge(card, data.status); card.dataset.status = data.status; }
    const pn = document.getElementById('parked-n');
    if (pn && (to === 'parked') !== (from === 'parked')) pn.textContent = Math.max(0, (parseInt(pn.textContent)||0) + (to === 'parked' ? 1 : -1));
    if (from === 'parked') { const pa = card.querySelector('.card-parked-at'); if (pa) pa.remove(); }
    if (!toBody) card.remove();
    const labels = {new:'NEW', wip:'WIP', quoted:'QUOTED', hot:'HOT', booked:'CONFIRMED', parked:'PARKED'};
    showToast('✓  ' + card.querySelector('.card-name').textContent.trim() + '  →  ' + (labels[to]||to.toUpperCase()));
  } catch (err) {
    // Roll back
    card.dataset.col = from;
    card.style.display = '';
    fromBody.appendChild(card);
    updateCount(to,   -1);
    updateCount(from, +1);
    updateEmpty(to);
    updateEmpty(from);
    showToast('✗  ' + err.message, true);
  }
}

// Update a card's status badge in place (colors mirror the PHP-rendered map).
function setStatusBadge(card, status) {
  const badge = card.querySelector('.card-status-badge');
  if (!badge) return;
  const sbg = {Inquiry:'#E5F0FC',Quoted:'#FEF0E5',Hot:'#FFF0E0','Hot-Quoted':'#FFF0E0',Booked:'#EBF5EE',Lost:'#F0F0F0',Cancelled:'#FAE8E7',Parked:'#EEF0F3'};
  const sco = {Inquiry:'#0062B1',Quoted:'#E87722',Hot:'#C45000','Hot-Quoted':'#C45000',Booked:'#1A6B3A',Lost:'#888',Cancelled:'#C0211B',Parked:'#4B5563'};
  badge.textContent      = (status === 'Booked') ? 'Confirmed' : status;
  badge.style.background  = sbg[status] || '#eee';
  badge.style.color       = sco[status] || '#444';
}

function updateCount(col, delta) {
  const el = document.getElementById('cnt-' + col);
  if (el) el.textContent = Math.max(0, (parseInt(el.textContent)||0) + delta);
}
function updateEmpty(col) {
  const body  = document.getElementById('col-' + col);
  const empty = document.getElementById('empty-' + col);
  if (!body) return;
  const cards = body.querySelectorAll('.pipeline-card').length;
  if (empty) { empty.style.display = cards ? 'none' : ''; }
  else if (!cards) {
    const d = document.createElement('div');
    d.className = 'col-empty'; d.id = 'empty-' + col; d.textContent = 'No requests';
    body.appendChild(d);
  }
}

let toastTimer;
function showToast(msg, isError=false) {
  const el = document.getElementById('move-toast');
  el.textContent = msg;
  el.className   = 'show' + (isError ? ' error' : '');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.className = '', 3000);
}

// Prevent link-click during drag
document.getElementById('board').addEventListener('click', e => {
  if (e.target.classList.contains('card-link') && dragId) {
    e.preventDefault(); dragId = null;
  }
});
</script>

<?php include 'includes/footer.php'; ?>
