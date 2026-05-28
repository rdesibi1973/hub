<?php
require_once 'config.php';
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

// ── AJAX: move card (updates pipeline_column only, status unchanged) ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
    header('Content-Type: application/json');
    $id  = (int)($_POST['id']  ?? 0);
    $col = trim($_POST['col'] ?? '');
    $allowed = ['new','wip','quoted','hot','booked'];
    if (!$id || !in_array($col, $allowed, true)) {
        echo json_encode(['ok'=>false,'message'=>'Invalid params']); exit;
    }
    // Staff can only move their own requests
    if ($isStaff && $staffAgentId) {
        $db->prepare("UPDATE requests SET pipeline_column=? WHERE id=? AND agent_id=?")
           ->execute([$col, $id, $staffAgentId]);
    } else {
        $db->prepare("UPDATE requests SET pipeline_column=? WHERE id=?")
           ->execute([$col, $id]);
    }
    echo json_encode(['ok'=>true]); exit;
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

// ── Column definitions (key = pipeline_column value stored in DB) ──────────
// default_statuses: which status values map here when pipeline_column IS NULL
$columns = [
    'new'    => ['label'=>'NEW',      'icon'=>'📥', 'cls'=>'col-new',    'default_statuses'=>['Inquiry']],
    'wip'    => ['label'=>'WIP',      'icon'=>'⚙️', 'cls'=>'col-wip',    'default_statuses'=>[]],
    'quoted' => ['label'=>'QUOTED',   'icon'=>'💬', 'cls'=>'col-quoted', 'default_statuses'=>['Quoted']],
    'hot'    => ['label'=>'HOT',      'icon'=>'🔥', 'cls'=>'col-hot',    'default_statuses'=>['Hot']],
    'booked' => ['label'=>'CONFIRMED','icon'=>'✅', 'cls'=>'col-booked', 'default_statuses'=>[]],
];

// ── Fetch all pipeline-visible requests ────────────────────────────────────
$where  = ["(r.status IN ('Inquiry','Quoted','Hot') OR r.pipeline_column = 'booked')"];
$params = [];

if ($isStaff && $staffAgentId) {
    $where[] = 'r.agent_id = ?'; $params[] = $staffAgentId;
} elseif ($filterAgent) {
    $where[] = 'r.agent_id = ?'; $params[] = $filterAgent;
}
if ($filterYear) {
    $where[] = 'YEAR(r.date_received) = ?'; $params[] = $filterYear;
}

$stmt = $db->prepare(
    "SELECT r.id, r.customer_name, r.status, r.pipeline_column,
            r.pax, r.destination, r.period, r.date_received, r.value_usd,
            a.name AS agent_name
     FROM requests r
     LEFT JOIN agents a ON a.id = r.agent_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY r.date_received DESC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build a map: status → default column key
$statusToCol = [];
foreach ($columns as $colKey => $colDef) {
    foreach ($colDef['default_statuses'] as $s) $statusToCol[$s] = $colKey;
}

// Group cards into columns:
// - explicit pipeline_column wins if it's a known column
// - fallback: derive from status
$byCol = array_fill_keys(array_keys($columns), []);
foreach ($rows as $r) {
    $explicit = $r['pipeline_column'];
    $colKey   = (isset($columns[$explicit])) ? $explicit : ($statusToCol[$r['status']] ?? null);
    if ($colKey) $byCol[$colKey][] = $r;
}

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
    <button type="submit" class="btn btn-outline btn-sm" style="margin-left:auto;display:flex;align-items:center;gap:6px;">
      ↺ Refresh
    </button>
  </form>

  <div class="pipeline-board" id="board">
    <?php foreach ($columns as $colKey => $col): ?>
    <div class="pipeline-col <?= $col['cls'] ?>"
         data-col="<?= $colKey ?>"
         ondragover="onDragOver(event,this)"
         ondragleave="onDragLeave(this)"
         ondrop="onDrop(event,this)">
      <div class="col-header">
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
          $sbg  = ['Inquiry'=>'#E5F0FC','Quoted'=>'#FEF0E5','Hot'=>'#FFF0E0',
                   'Booked' =>'#EBF5EE','Lost'  =>'#F0F0F0','Cancelled'=>'#FAE8E7'];
          $sco  = ['Inquiry'=>'#0062B1','Quoted'=>'#E87722','Hot'=>'#C45000',
                   'Booked' =>'#1A6B3A','Lost'  =>'#888',   'Cancelled'=>'#C0211B'];
          $st      = $r['status'];
          $stLabel = ($st === 'Booked') ? 'Confirmed' : $st;
        ?>
        <div class="pipeline-card"
             draggable="true"
             data-id="<?= $r['id'] ?>"
             data-col="<?= $colKey ?>"
             ondragstart="onDragStart(event,this)"
             ondragend="onDragEnd(this)">
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
          <div class="card-footer">
            <span class="card-status-badge"
                  style="background:<?= $sbg[$st]??'#eee' ?>;color:<?= $sco[$st]??'#444' ?>">
              <?= h($stLabel) ?>
            </span>
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
  if (!card || !toBody) return;

  // Optimistic move
  card.dataset.col = to;
  toBody.appendChild(card);
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
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.message || 'Error');
    const labels = {new:'NEW', wip:'WIP', quoted:'QUOTED', hot:'HOT', booked:'CONFIRMED'};
    showToast('✓  ' + card.querySelector('.card-name').textContent.trim() + '  →  ' + (labels[to]||to.toUpperCase()));
  } catch (err) {
    // Roll back
    card.dataset.col = from;
    fromBody.appendChild(card);
    updateCount(to,   -1);
    updateCount(from, +1);
    updateEmpty(to);
    updateEmpty(from);
    showToast('✗  ' + err.message, true);
  }
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
