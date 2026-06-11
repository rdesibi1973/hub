<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$cu         = current_user();
$is_admin   = is_admin();
$_aid_stmt  = $pdo->prepare("SELECT agent_id FROM users WHERE id=?");
$_aid_stmt->execute([$cu['id']]);
$my_agent_id = (int)($_aid_stmt->fetchColumn() ?: 0);  // 0 if user has no agent linked

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // Helper: can the current user edit this template?
    $can_edit = function(int $id) use ($pdo, $is_admin, $my_agent_id): bool {
        if ($is_admin) return true;
        $r = $pdo->prepare("SELECT visibility, agent_id FROM email_templates WHERE id=?");
        $r->execute([$id]);
        $t = $r->fetch(PDO::FETCH_ASSOC);
        return $t && $t['visibility'] === 'private' && (int)$t['agent_id'] === $my_agent_id;
    };

    if ($action === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $name       = trim($_POST['name']      ?? '');
        $category   = trim($_POST['category']  ?? '');
        $subject    = trim($_POST['subject']   ?? '');
        $body_html  = trim($_POST['body_html'] ?? '');
        $active     = isset($_POST['active']) ? 1 : 0;
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $visibility = $is_admin ? ($_POST['visibility'] ?? 'public') : 'private';
        if ($visibility === 'private' && $my_agent_id <= 0) {
            echo json_encode(['ok'=>false,'msg'=>'Your user is not linked to an agent profile, so a private template cannot be saved. Please ask an administrator to link your account to an agent.']); exit;
        }
        $agent_id   = ($visibility === 'private') ? $my_agent_id : null;

        if (!$name || !$subject || !$body_html) {
            echo json_encode(['ok'=>false,'msg'=>'Name, subject and body are required.']); exit;
        }
        if ($id) {
            if (!$can_edit($id)) { echo json_encode(['ok'=>false,'msg'=>'Not allowed.']); exit; }
            $pdo->prepare("UPDATE email_templates SET name=?,category=?,subject=?,body_html=?,active=?,sort_order=?,visibility=?,agent_id=? WHERE id=?")
                ->execute([$name,$category,$subject,$body_html,$active,$sort_order,$visibility,$agent_id,$id]);
        } else {
            $pdo->prepare("INSERT INTO email_templates (name,category,subject,body_html,active,sort_order,visibility,agent_id) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$name,$category,$subject,$body_html,$active,$sort_order,$visibility,$agent_id]);
        }
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        if (!$can_edit($id)) { echo json_encode(['ok'=>false,'msg'=>'Not allowed.']); exit; }
        $pdo->prepare("DELETE FROM email_templates WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'get') {
        $s = $pdo->prepare("SELECT * FROM email_templates WHERE id=?");
        $s->execute([(int)$_POST['id']]);
        echo json_encode($s->fetch(PDO::FETCH_ASSOC) ?: []); exit;
    }

    if ($action === 'toggle_active') {
        $id = (int)$_POST['id'];
        if (!$can_edit($id)) { echo json_encode(['ok'=>false,'msg'=>'Not allowed.']); exit; }
        $pdo->prepare("UPDATE email_templates SET active=1-active WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]); exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}

// ── Fetch templates ───────────────────────────────────────────────────────────
// Admin: all templates. Agent: public + own private.
if ($is_admin) {
    $templates = $pdo->query(
        "SELECT t.*, a.name AS agent_name
         FROM email_templates t
         LEFT JOIN agents a ON a.id = t.agent_id
         ORDER BY t.visibility ASC, t.sort_order ASC, t.name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare(
        "SELECT t.*, a.name AS agent_name
         FROM email_templates t
         LEFT JOIN agents a ON a.id = t.agent_id
         WHERE t.visibility='public' OR (t.visibility='private' AND t.agent_id=?)
         ORDER BY t.visibility ASC, t.sort_order ASC, t.name ASC"
    );
    $stmt->execute([$my_agent_id]);
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$categories = array_values(array_unique(array_filter(array_column($templates, 'category'))));
$default_categories = ['General', 'Travel Info', 'Visa', 'Insurance', 'Health', 'Payment'];
foreach ($default_categories as $dc) {
    $exists = false;
    foreach ($categories as $ec) {
        if (strcasecmp(trim($ec), $dc) === 0) { $exists = true; break; }
    }
    if (!$exists) { $categories[] = $dc; }
}
sort($categories);

$page_title = 'Email Templates';
$extra_css = '
.tpl-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px;overflow-y:auto}
.tpl-modal-overlay.hidden{display:none}
.tpl-modal-box{background:var(--white);border-radius:10px;box-shadow:0 8px 40px rgba(0,0,0,.2);width:100%;max-width:980px}
.tpl-modal-header{padding:16px 24px;border-bottom:1px solid var(--grey-lt);display:flex;align-items:center;justify-content:space-between}
.tpl-modal-header h3{font-family:"Merriweather",serif;font-size:1rem;font-weight:700;color:var(--black);margin:0}
.tpl-modal-body{padding:24px}
.tpl-modal-footer{padding:16px 24px;border-top:1px solid var(--grey-lt);display:flex;justify-content:flex-end;gap:12px}
.tpl-modal-close{background:none;border:none;font-size:1.4rem;cursor:pointer;color:var(--grey-mid);line-height:1}
.tpl-two-col{display:grid;grid-template-columns:1fr 340px;gap:24px}
.var-panel{background:var(--off-white);border-radius:8px;border:1px solid var(--grey-lt);padding:16px;height:fit-content}
.var-panel h4{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-dk);margin-bottom:12px}
.var-tag{display:inline-block;font-family:monospace;font-size:.75rem;background:var(--navy-lt);color:var(--navy);padding:2px 8px;border-radius:4px;cursor:pointer;margin-bottom:4px}
.var-tag:hover{background:#cfe0f5}
.var-row{margin-bottom:6px;font-size:.8rem}
.var-desc{color:var(--grey-mid);font-size:.75rem}
.preview-box{margin-top:12px;border:1px solid var(--grey-lt);border-radius:6px;padding:12px;background:var(--white);min-height:80px;font-size:.82rem;max-height:260px;overflow-y:auto}
.vis-badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em}
.vis-public{background:#e8f5e9;color:#2e7d32}
.vis-private{background:#fff3e0;color:#e65100}
.active-badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;cursor:pointer}
.active-yes{background:var(--green-lt);color:var(--green)}
.active-no{background:var(--grey-lt);color:var(--grey-mid)}
.tabs{display:flex;gap:0;border-bottom:2px solid var(--grey-lt);margin-bottom:0}
.tab-btn{background:none;border:none;padding:8px 18px;font-size:.78rem;font-weight:600;cursor:pointer;color:var(--grey-mid);border-bottom:2px solid transparent;margin-bottom:-2px}
.tab-btn.active{color:var(--red);border-bottom-color:var(--red)}
.tab-pane{display:none}.tab-pane.active{display:block}
.section-sep{padding:8px 16px;background:var(--off-white);border-bottom:1px solid var(--grey-lt);font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-mid)}
';
include __DIR__ . '/includes/layout_header.php';
?>

<main>
  <div class="page-title">
    📧 Email Templates
    <button class="btn btn-primary btn-sm ml-auto" onclick="openModal(0)">+ New Template</button>
  </div>

  <div class="card">
    <div style="overflow-x:auto">
      <table class="data-table" id="tplTable">
        <thead>
          <tr>
            <th>#</th><th>Name</th><th>Category</th><th>Visibility</th>
            <?php if ($is_admin): ?><th>Agent</th><?php endif; ?>
            <th>Subject</th>
            <th class="text-center">Active</th>
            <?php if ($is_admin): ?><th class="text-center">Order</th><?php endif; ?>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php
        $last_vis = null;
        foreach ($templates as $t):
            $editable = $is_admin || ($t['visibility'] === 'private' && (int)$t['agent_id'] === $my_agent_id);
            if ($is_admin && $t['visibility'] !== $last_vis):
                $last_vis = $t['visibility'];
        ?>
          <tr>
            <td colspan="8" class="section-sep">
              <?= $t['visibility'] === 'public' ? '🌐 Public Templates' : '🔒 Private Templates' ?>
            </td>
          </tr>
        <?php endif; ?>
          <tr style="<?= $t['active'] ? '' : 'color:var(--grey-mid)' ?>">
            <td style="font-size:.75rem;color:var(--grey-mid)"><?= $t['id'] ?></td>
            <td class="td-name" style="<?= $t['active'] ? '' : 'color:var(--grey-mid);font-weight:400' ?>"><?= e($t['name']) ?></td>
            <td style="font-size:.78rem"><?= e($t['category'] ?? '') ?></td>
            <td>
              <span class="vis-badge vis-<?= $t['visibility'] ?>">
                <?= $t['visibility'] === 'public' ? '🌐 Public' : '🔒 Private' ?>
              </span>
            </td>
            <?php if ($is_admin): ?>
            <td style="font-size:.78rem;color:var(--grey-mid)"><?= e($t['agent_name'] ?? '—') ?></td>
            <?php endif; ?>
            <td style="font-size:.78rem;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($t['subject']) ?></td>
            <td style="text-align:center">
              <?php if ($editable): ?>
              <span class="active-badge <?= $t['active'] ? 'active-yes' : 'active-no' ?>"
                    onclick="toggleActive(<?= $t['id'] ?>)">
                <?= $t['active'] ? 'Yes' : 'No' ?>
              </span>
              <?php else: ?>
              <span class="active-badge <?= $t['active'] ? 'active-yes' : 'active-no' ?>">
                <?= $t['active'] ? 'Yes' : 'No' ?>
              </span>
              <?php endif; ?>
            </td>
            <?php if ($is_admin): ?>
            <td style="text-align:center;font-size:.8rem"><?= $t['sort_order'] ?></td>
            <?php endif; ?>
            <td>
              <div style="display:flex;gap:6px">
                <?php if ($editable): ?>
                <button class="btn btn-secondary btn-sm" onclick="openModal(<?= $t['id'] ?>)">Edit</button>
                <button class="btn btn-danger btn-sm" onclick="deleteTpl(<?= $t['id'] ?>, '<?= addslashes(e($t['name'])) ?>')">Delete</button>
                <?php else: ?>
                <span style="font-size:.75rem;color:var(--grey-mid)">read-only</span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$templates): ?>
          <tr><td colspan="8" style="text-align:center;color:var(--grey-mid);padding:32px">No templates yet.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<!-- ── Modal ─────────────────────────────────────────────────────────────── -->
<div class="tpl-modal-overlay hidden" id="tplOverlay">
  <div class="tpl-modal-box">
    <div class="tpl-modal-header">
      <h3 id="modalTitle">New Template</h3>
      <button class="tpl-modal-close" onclick="closeModal()">&times;</button>
    </div>
    <div class="tpl-modal-body">
      <input type="hidden" id="f_id">

      <div style="display:grid;grid-template-columns:1fr 1fr <?= $is_admin ? '140px' : '' ?> 140px 100px;gap:16px;margin-bottom:20px">
        <div>
          <label class="form-label">Name <span style="color:var(--red)">*</span></label>
          <input type="text" id="f_name" class="form-control">
        </div>
        <div>
          <label class="form-label">Category</label>
          <input type="text" id="f_category" class="form-control" list="cat_list" placeholder="Visa, Insurance…">
          <datalist id="cat_list">
            <?php foreach ($categories as $c): ?>
              <option value="<?= e($c) ?>">
            <?php endforeach; ?>
          </datalist>
        </div>
        <?php if ($is_admin): ?>
        <div>
          <label class="form-label">Visibility</label>
          <select id="f_visibility" class="form-control">
            <option value="public">🌐 Public</option>
            <option value="private">🔒 Private</option>
          </select>
        </div>
        <?php endif; ?>
        <div>
          <label class="form-label">Order</label>
          <input type="number" id="f_sort_order" class="form-control" value="0">
        </div>
        <div style="padding-top:28px">
          <label class="form-check">
            <input type="checkbox" id="f_active" checked>
            <span>Active</span>
          </label>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Subject <span style="color:var(--red)">*</span></label>
        <input type="text" id="f_subject" class="form-control">
      </div>

      <div class="tpl-two-col">
        <div>
          <label class="form-label">Body (HTML) <span style="color:var(--red)">*</span></label>
          <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('edit',this)">Edit</button>
            <button class="tab-btn" onclick="switchTab('preview',this)">Preview</button>
          </div>
          <div class="tab-pane active" id="pane-edit" style="border:1.5px solid var(--grey-lt);border-top:none;border-radius:0 0 6px 6px">
            <textarea id="f_body_html" style="width:100%;min-height:300px;font-family:monospace;font-size:.78rem;border:none;padding:12px;resize:vertical;outline:none;border-radius:0 0 6px 6px"></textarea>
          </div>
          <div class="tab-pane" id="pane-preview">
            <div class="preview-box" id="previewContent" style="min-height:300px"></div>
          </div>
        </div>
        <div class="var-panel">
          <h4>Available Variables</h4>
          <p style="font-size:.75rem;color:var(--grey-mid);margin-bottom:10px">Click to insert at cursor:</p>
          <?php
          $vars = [
            '{{customer_name}}' => 'Customer name',
            '{{destination}}'   => 'Destination',
            '{{period}}'        => 'Period (text)',
            '{{pax}}'           => 'Number of pax',
            '{{start_date}}'    => 'Arrival date',
            '{{end_date}}'      => 'Departure date',
            '{{agent_name}}'    => 'Agent name',
            '{{agent_email}}'   => 'Agent email',
          ];
          foreach ($vars as $var => $desc): ?>
          <div class="var-row">
            <span class="var-tag" onclick="insertVar('<?= $var ?>')"><?= $var ?></span>
            <span class="var-desc"> — <?= $desc ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div id="saveAlert" style="margin-top:12px;padding:10px 14px;border-radius:6px;display:none;font-size:.82rem"></div>
    </div>
    <div class="tpl-modal-footer">
      <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
      <button class="btn btn-primary" onclick="saveTemplate()">Save Template</button>
    </div>
  </div>
</div>

<script>
const IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;

function openModal(id) {
  document.getElementById('f_id').value = id || 0;
  document.getElementById('modalTitle').textContent = id ? 'Edit Template' : 'New Template';
  ['f_name','f_category','f_subject','f_body_html'].forEach(k => document.getElementById(k).value = '');
  document.getElementById('f_sort_order').value = 0;
  document.getElementById('f_active').checked = true;
  if (IS_ADMIN) document.getElementById('f_visibility').value = 'public';
  document.getElementById('saveAlert').style.display = 'none';
  switchTab('edit', document.querySelector('.tab-btn'));

  if (id) {
    fetch('email_templates.php', {
      method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:`action=get&id=${id}`
    }).then(r=>r.json()).then(d => {
      if (!d) return;
      document.getElementById('f_name').value       = d.name       || '';
      document.getElementById('f_category').value   = d.category   || '';
      document.getElementById('f_subject').value    = d.subject    || '';
      document.getElementById('f_body_html').value  = d.body_html  || '';
      document.getElementById('f_sort_order').value = d.sort_order || 0;
      document.getElementById('f_active').checked   = d.active == 1;
      if (IS_ADMIN) document.getElementById('f_visibility').value = d.visibility || 'public';
    });
  }
  document.getElementById('tplOverlay').classList.remove('hidden');
}

function closeModal() { document.getElementById('tplOverlay').classList.add('hidden'); }

document.getElementById('tplOverlay').addEventListener('click', e => {
  if (e.target.id === 'tplOverlay') closeModal();
});

function saveTemplate() {
  const alrt = document.getElementById('saveAlert');
  const params = {
    action:     'save',
    id:         document.getElementById('f_id').value,
    name:       document.getElementById('f_name').value,
    category:   document.getElementById('f_category').value,
    subject:    document.getElementById('f_subject').value,
    body_html:  document.getElementById('f_body_html').value,
    sort_order: document.getElementById('f_sort_order').value,
    active:     document.getElementById('f_active').checked ? '1' : '',
    visibility: IS_ADMIN ? document.getElementById('f_visibility').value : 'private',
  };
  fetch('email_templates.php', {method:'POST', body: new URLSearchParams(params)})
    .then(r=>r.json()).then(d => {
      if (d.ok) { location.reload(); }
      else {
        alrt.style.cssText = 'display:block;background:var(--red-lt);color:var(--red-dk);margin-top:12px;padding:10px 14px;border-radius:6px;font-size:.82rem';
        alrt.textContent = d.msg;
      }
    });
}

function deleteTpl(id, name) {
  if (!confirm(`Delete template "${name}"?`)) return;
  fetch('email_templates.php', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:`action=delete&id=${id}`
  }).then(r=>r.json()).then(d => { if (d.ok) location.reload(); else alert(d.msg); });
}

function toggleActive(id) {
  fetch('email_templates.php', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:`action=toggle_active&id=${id}`
  }).then(() => location.reload());
}

function insertVar(v) {
  const ta = document.getElementById('f_body_html');
  const s = ta.selectionStart, end = ta.selectionEnd;
  ta.value = ta.value.substring(0,s) + v + ta.value.substring(end);
  ta.selectionStart = ta.selectionEnd = s + v.length;
  ta.focus();
}

function switchTab(tab, btn) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  if (btn) btn.classList.add('active');
  document.getElementById('pane-' + tab).classList.add('active');
  if (tab === 'preview') {
    document.getElementById('previewContent').innerHTML =
      document.getElementById('f_body_html').value || '<em style="color:var(--grey-mid)">Nothing to preview.</em>';
  }
}
</script>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
