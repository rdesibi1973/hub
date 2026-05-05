<?php
require_once 'config.php';
requireLogin();
if (isLeadsRestricted()) { header('Location: requests.php'); exit; }
$pageTitle = 'Agents';
$db = db();

// Add agent
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name) {
            $db->prepare("INSERT INTO agents (name) VALUES (?)")->execute([$name]);
            flash('Agent "'.$name.'" added.');
        } else {
            flash('Name cannot be empty.', 'error');
        }
    } elseif ($action === 'toggle') {
        $aid = (int)($_POST['agent_id'] ?? 0);
        $db->prepare("UPDATE agents SET active = 1 - active WHERE id = ?")->execute([$aid]);
        flash('Agent status updated.');
    } elseif ($action === 'rename') {
        $aid  = (int)($_POST['agent_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($aid && $name) {
            $db->prepare("UPDATE agents SET name = ? WHERE id = ?")->execute([$name, $aid]);
            flash('Agent renamed.');
        }
    }
    header('Location: agents.php');
    exit;
}

$agents = $db->query("SELECT a.*, COUNT(r.id) AS req_count
    FROM agents a
    LEFT JOIN requests r ON r.agent_id = a.id
    GROUP BY a.id ORDER BY a.name")->fetchAll();

include 'includes/header.php';
?>

<div class="page-header">
  <div>
    <h2>Agents</h2>
    <div class="sub">Manage your sales team</div>
  </div>
</div>

<!-- ADD AGENT -->
<div class="form-card" style="max-width:420px;margin-bottom:28px">
  <form method="POST" style="display:flex;gap:10px;align-items:flex-end">
    <input type="hidden" name="action" value="add">
    <div class="form-group" style="flex:1;margin-bottom:0">
      <label for="name">Add New Agent</label>
      <input type="text" id="name" name="name" placeholder="Full name" required>
    </div>
    <button type="submit" class="btn btn-red" style="flex-shrink:0">Add</button>
  </form>
</div>

<!-- AGENTS LIST -->
<div class="agent-grid" style="max-width:860px">
  <?php foreach ($agents as $ag): ?>
  <div class="agent-card" style="<?= !$ag['active'] ? 'opacity:.5' : '' ?>">
    <div>
      <div class="agent-name"><?= h($ag['name']) ?></div>
      <div class="agent-status">
        <?= $ag['req_count'] ?> request<?= $ag['req_count']!=1?'s':'' ?> &nbsp;·&nbsp;
        <?= $ag['active'] ? '<span style="color:var(--green)">Active</span>' : '<span>Inactive</span>' ?>
      </div>
    </div>
    <div class="gap-8">
      <!-- Rename -->
      <button class="btn btn-outline btn-sm"
              onclick="renameAgent(<?= $ag['id'] ?>, '<?= addslashes(h($ag['name'])) ?>')">
        Rename
      </button>
      <!-- Toggle active -->
      <form method="POST" style="display:inline">
        <input type="hidden" name="action"   value="toggle">
        <input type="hidden" name="agent_id" value="<?= $ag['id'] ?>">
        <button type="submit" class="btn btn-outline btn-sm">
          <?= $ag['active'] ? 'Deactivate' : 'Activate' ?>
        </button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Hidden rename form -->
<form method="POST" id="rename-form" style="display:none">
  <input type="hidden" name="action"   value="rename">
  <input type="hidden" name="agent_id" id="rename-agent-id">
  <input type="hidden" name="name"     id="rename-agent-name">
</form>

<script>
function renameAgent(id, current) {
  const n = prompt('New name for this agent:', current);
  if (n && n.trim() && n.trim() !== current) {
    document.getElementById('rename-agent-id').value   = id;
    document.getElementById('rename-agent-name').value = n.trim();
    document.getElementById('rename-form').submit();
  }
}
</script>

<?php include 'includes/footer.php'; ?>
