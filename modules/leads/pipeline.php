<?php
require_once 'config.php';
requireLogin();
$pageTitle = 'Pipeline';
$db = db();

$isStaff      = isLeadsRestricted();
$staffAgentId = $isStaff ? getStaffAgentId() : 0;

// ── Filters ───────────────────────────────────────────────────────────────
$filterAgent = (int)($_GET['agent'] ?? 0);
$filterYear  = (int)($_GET['year']  ?? date('Y'));

// Build agent list for dropdown
$agents = $db->query(
    "SELECT a.id, a.name FROM agents a
     JOIN users u ON u.agent_id = a.id
     WHERE a.active = 1 AND u.is_active = 1
     ORDER BY a.name"
)->fetchAll(PDO::FETCH_ASSOC);

// ── Pipeline columns — the "sales funnel" statuses ────────────────────────
$columns = [
    'Inquiry'   => ['label' => 'NEW',      'icon' => '📥', 'cls' => 'col-inquiry'],
    'Quoted'    => ['label' => 'QUOTED',   'icon' => '💬', 'cls' => 'col-quoted'],
    'Hot'       => ['label' => 'HOT',      'icon' => '🔥', 'cls' => 'col-hot'],
    'Booked'    => ['label' => 'BOOKED',   'icon' => '✅', 'cls' => 'col-booked'],
    'Lost'      => ['label' => 'LOST',     'icon' => '❌', 'cls' => 'col-lost'],
];

// ── Fetch requests for pipeline statuses ─────────────────────────────────
$where   = ['r.status IN (' . implode(',', array_fill(0, count($columns), '?')) . ')'];
$params  = array_keys($columns);

if ($isStaff && $staffAgentId) {
    $where[]  = 'r.agent_id = ?';
    $params[] = $staffAgentId;
} elseif ($filterAgent) {
    $where[]  = 'r.agent_id = ?';
    $params[] = $filterAgent;
}
if ($filterYear) {
    $where[]  = 'YEAR(r.date_received) = ?';
    $params[] = $filterYear;
}

$sql = "SELECT r.id, r.customer_name, r.status, r.pax, r.destination,
               r.period, r.date_received, r.value_usd, r.agent_id,
               a.name AS agent_name
        FROM requests r
        LEFT JOIN agents a ON a.id = r.agent_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY r.date_received DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by status
$byStatus = array_fill_keys(array_keys($columns), []);
foreach ($rows as $r) {
    if (isset($byStatus[$r['status']])) {
        $byStatus[$r['status']][] = $r;
    }
}

// Available years for filter
$years = $db->query(
    "SELECT DISTINCT YEAR(date_received) AS y FROM requests ORDER BY y DESC LIMIT 5"
)->fetchAll(PDO::FETCH_COLUMN);

include 'includes/header.php';
?>

<style>
/* ── Pipeline page ──────────────────────────────────────────────────────── */
.pipeline-wrap {
  padding: 0 24px 40px;
  overflow-x: auto;
  min-height: calc(100vh - 140px);
}
.pipeline-toolbar {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 0 16px;
  flex-wrap: wrap;
}
.pipeline-toolbar h2 {
  font-family: 'Merriweather', serif;
  font-size: 1.15rem;
  color: var(--red-dk);
  margin-right: 8px;
}
.pipeline-toolbar select,
.pipeline-toolbar input {
  font-size: .8rem;
  padding: 5px 10px;
  border: 1.5px solid var(--grey-lt);
  border-radius: 6px;
  background: var(--white);
  color: var(--grey-dk);
}
.pipeline-toolbar select:focus {
  border-color: var(--red);
  outline: none;
}

/* ── Board ─────────────────────────────────────────────────────────────── */
.pipeline-board {
  display: flex;
  gap: 14px;
  align-items: flex-start;
  min-width: 900px;
}

/* ── Column ────────────────────────────────────────────────────────────── */
.pipeline-col {
  flex: 1;
  min-width: 0;
  background: #F5F5F5;
  border-radius: 10px;
  overflow: hidden;
  border: 1.5px solid var(--grey-lt);
}
.pipeline-col.drag-over {
  border-color: var(--red);
  background: var(--red-lt);
}
.col-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 12px 9px;
  font-size: .72rem;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
  border-bottom: 3px solid transparent;
}
.col-inquiry  .col-header { background: var(--blue-lt);  color: var(--blue);   border-color: var(--blue); }
.col-quoted   .col-header { background: var(--amber-lt); color: var(--amber);  border-color: var(--amber); }
.col-hot      .col-header { background: #FFF0E0;          color: #C45000;       border-color: #E87722; }
.col-booked   .col-header { background: var(--green-lt); color: var(--green);  border-color: var(--green); }
.col-lost     .col-header { background: var(--grey-lt);  color: var(--grey-dk);border-color: #bbb; }

.col-count {
  background: rgba(0,0,0,.1);
  border-radius: 10px;
  padding: 1px 7px;
  font-size: .68rem;
  font-weight: 700;
}
.col-body {
  padding: 8px;
  min-height: 200px;
  display: flex;
  flex-direction: column;
  gap: 7px;
}

/* ── Cards ─────────────────────────────────────────────────────────────── */
.pipeline-card {
  background: #fff;
  border: 1.5px solid var(--grey-lt);
  border-radius: 8px;
  padding: 10px 11px;
  cursor: grab;
  transition: box-shadow .15s, transform .1s, border-color .15s;
  user-select: none;
  position: relative;
}
.pipeline-card:hover {
  box-shadow: 0 3px 10px rgba(0,0,0,.10);
  border-color: #ccc;
}
.pipeline-card.dragging {
  opacity: .55;
  transform: rotate(1.5deg);
  box-shadow: 0 6px 20px rgba(0,0,0,.15);
}
.card-name {
  font-weight: 700;
  font-size: .82rem;
  color: var(--grey-dk);
  line-height: 1.3;
  margin-bottom: 4px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.card-meta {
  font-size: .71rem;
  color: var(--grey-mid);
  display: flex;
  flex-wrap: wrap;
  gap: 4px 10px;
  margin-top: 3px;
}
.card-meta span { white-space: nowrap; }
.card-agent {
  display: inline-block;
  font-size: .68rem;
  font-weight: 700;
  color: #fff;
  background: var(--grey-mid);
  border-radius: 4px;
  padding: 1px 5px;
  margin-top: 5px;
}
.card-link {
  position: absolute;
  inset: 0;
  border-radius: 8px;
}

/* ── Empty state ───────────────────────────────────────────────────────── */
.col-empty {
  text-align: center;
  color: var(--grey-mid);
  font-size: .75rem;
  padding: 24px 8px;
  font-style: italic;
}

/* ── Move toast ────────────────────────────────────────────────────────── */
#move-toast {
  position: fixed;
  bottom: 28px;
  right: 28px;
  background: var(--grey-dk);
  color: #fff;
  padding: 10px 18px;
  border-radius: 8px;
  font-size: .8rem;
  font-weight: 600;
  box-shadow: 0 4px 16px rgba(0,0,0,.25);
  opacity: 0;
  transform: translateY(8px);
  transition: opacity .2s, transform .2s;
  pointer-events: none;
  z-index: 9999;
}
#move-toast.show {
  opacity: 1;
  transform: translateY(0);
}
#move-toast.error { background: var(--red-dk); }
</style>

<div class="pipeline-wrap">

  <!-- Toolbar -->
  <form method="get" class="pipeline-toolbar">
    <h2>🔥 Pipeline</h2>
    <?php if (!$isStaff): ?>
    <select name="agent" onchange="this.form.submit()">
      <option value="0">All agents</option>
      <?php foreach ($agents as $ag): ?>
      <option value="<?= $ag['id'] ?>" <?= $filterAgent == $ag['id'] ? 'selected':'' ?>>
        <?= h($ag['name']) ?>
      </option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <select name="year" onchange="this.form.submit()">
      <?php foreach ($years as $y): ?>
      <option value="<?= $y ?>" <?= $filterYear == $y ? 'selected':'' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
    <span style="font-size:.78rem;color:var(--grey-mid)">
      <?= count($rows) ?> requests
    </span>
  </form>

  <!-- Board -->
  <div class="pipeline-board" id="board">
    <?php foreach ($columns as $status => $col): ?>
    <div class="pipeline-col <?= $col['cls'] ?>"
         data-status="<?= h($status) ?>"
         ondragover="onDragOver(event, this)"
         ondragleave="onDragLeave(this)"
         ondrop="onDrop(event, this)">
      <div class="col-header">
        <span><?= $col['icon'] ?>&nbsp; <?= $col['label'] ?></span>
        <span class="col-count" id="cnt-<?= $status ?>"><?= count($byStatus[$status]) ?></span>
      </div>
      <div class="col-body" id="col-<?= $status ?>">
        <?php if (empty($byStatus[$status])): ?>
        <div class="col-empty" id="empty-<?= $status ?>">No requests</div>
        <?php endif; ?>
        <?php foreach ($byStatus[$status] as $r): ?>
        <?php
          $dest = $r['destination'] ?: '—';
          $pax  = $r['pax']  ? $r['pax'] . ' pax' : '';
          $val  = $r['value_usd'] ? '$' . number_format($r['value_usd'], 0) : '';
          $date = date('d M', strtotime($r['date_received']));
        ?>
        <div class="pipeline-card"
             draggable="true"
             data-id="<?= $r['id'] ?>"
             data-status="<?= h($status) ?>"
             ondragstart="onDragStart(event, this)"
             ondragend="onDragEnd(this)">
          <div class="card-name" title="<?= h($r['customer_name']) ?>">
            <?= h($r['customer_name']) ?>
          </div>
          <div class="card-meta">
            <?php if ($dest !== '—'): ?>
            <span>🌍 <?= h($dest) ?></span>
            <?php endif; ?>
            <?php if ($r['period']): ?>
            <span>🗓 <?= h($r['period']) ?></span>
            <?php endif; ?>
            <?php if ($pax): ?><span>👥 <?= $pax ?></span><?php endif; ?>
            <?php if ($val):  ?><span>💵 <?= $val ?></span><?php endif; ?>
            <span>📅 <?= $date ?></span>
          </div>
          <?php if (!$isStaff && $r['agent_name']): ?>
          <span class="card-agent"><?= h($r['agent_name']) ?></span>
          <?php endif; ?>
          <a class="card-link" href="request_view.php?id=<?= $r['id'] ?>" title="Open request"></a>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

</div><!-- .pipeline-wrap -->

<div id="move-toast"></div>

<script>
// ── Drag & Drop ────────────────────────────────────────────────────────────
let dragId   = null;
let fromStatus = null;

function onDragStart(e, card) {
  dragId     = card.dataset.id;
  fromStatus = card.dataset.status;
  card.classList.add('dragging');
  e.dataTransfer.effectAllowed = 'move';
  // Prevent the card link from intercepting drag
  e.stopPropagation();
}

function onDragEnd(card) {
  card.classList.remove('dragging');
}

function onDragOver(e, col) {
  e.preventDefault();
  e.dataTransfer.dropEffect = 'move';
  col.classList.add('drag-over');
}

function onDragLeave(col) {
  col.classList.remove('drag-over');
}

function onDrop(e, col) {
  e.preventDefault();
  col.classList.remove('drag-over');
  const toStatus = col.dataset.status;
  if (!dragId || toStatus === fromStatus) return;
  moveCard(dragId, fromStatus, toStatus, col);
}

async function moveCard(id, from, to, colEl) {
  const card = document.querySelector(`.pipeline-card[data-id="${id}"]`);
  if (!card) return;

  // Optimistic UI move
  const fromBody = document.getElementById('col-' + from);
  const toBody   = document.getElementById('col-' + to);
  card.dataset.status = to;
  toBody.appendChild(card);
  updateCount(from, -1);
  updateCount(to,   +1);
  updateEmpty(from);
  updateEmpty(to);

  // API call
  try {
    const res  = await fetch('request_view.php?id=' + id, {
      method:  'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded',
                 'X-Requested-With': 'XMLHttpRequest' },
      body:    'quick_status=' + encodeURIComponent(to)
    });
    const data = await res.json();
    if (data.ok) {
      showToast('✓ ' + decodeURIComponent(card.querySelector('.card-name').textContent.trim())
                     + ' → ' + to);
    } else {
      throw new Error(data.message || 'Error');
    }
  } catch (err) {
    // Roll back
    card.dataset.status = from;
    fromBody.appendChild(card);
    updateCount(to,   -1);
    updateCount(from, +1);
    updateEmpty(to);
    updateEmpty(from);
    showToast('✗ ' + err.message, true);
  }
}

function updateCount(status, delta) {
  const el = document.getElementById('cnt-' + status);
  if (el) el.textContent = Math.max(0, (parseInt(el.textContent) || 0) + delta);
}

function updateEmpty(status) {
  const body  = document.getElementById('col-' + status);
  const empty = document.getElementById('empty-' + status);
  if (!body) return;
  const cards = body.querySelectorAll('.pipeline-card');
  if (empty) {
    empty.style.display = cards.length ? 'none' : '';
  } else if (!cards.length) {
    const d = document.createElement('div');
    d.className = 'col-empty';
    d.id        = 'empty-' + status;
    d.textContent = 'No requests';
    body.appendChild(d);
  }
}

// ── Toast ─────────────────────────────────────────────────────────────────
let toastTimer;
function showToast(msg, isError = false) {
  const el = document.getElementById('move-toast');
  el.textContent = msg;
  el.className   = 'show' + (isError ? ' error' : '');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { el.className = ''; }, 3000);
}

// ── Prevent link click during drag ───────────────────────────────────────
document.getElementById('board').addEventListener('click', e => {
  if (e.target.classList.contains('card-link') && dragId) {
    e.preventDefault();
    dragId = null;
  }
});
</script>

<?php include 'includes/footer.php'; ?>
