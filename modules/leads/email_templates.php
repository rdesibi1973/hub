<?php
ob_start();
require_once 'config.php';
requireLogin();

$cu          = current_user();
$is_admin    = in_array($cu['role_name'] ?? '', ['admin']);
$_aid_stmt   = db()->prepare("SELECT agent_id FROM users WHERE id=?");
$_aid_stmt->execute([$cu['id']]);
$my_agent_id = (int)($_aid_stmt->fetchColumn() ?: 0);

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    $action = $_POST['action'];

    $can_edit = function(int $id) use ($is_admin, $my_agent_id): bool {
        if ($is_admin) return true;
        $r = db()->prepare("SELECT visibility, agent_id FROM email_templates WHERE id=?");
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
        if ($visibility === 'private') {
            if ($my_agent_id <= 0) {
                echo json_encode(['ok'=>false,'msg'=>'Your user is not linked to an agent profile, so a private template cannot be saved. Please ask an administrator to link your account to an agent.']); exit;
            }
            $agent_id = $my_agent_id;
        } else {
            $agent_id = null;
        }

        if (!$name || !$subject || !$body_html) {
            echo json_encode(['ok'=>false,'msg'=>'Name, subject and body are required.']); exit;
        }
        if ($id) {
            if (!$can_edit($id)) { echo json_encode(['ok'=>false,'msg'=>'Not allowed.']); exit; }
            db()->prepare("UPDATE email_templates SET name=?,category=?,subject=?,body_html=?,active=?,sort_order=?,visibility=?,agent_id=? WHERE id=?")
               ->execute([$name,$category,$subject,$body_html,$active,$sort_order,$visibility,$agent_id,$id]);
        } else {
            db()->prepare("INSERT INTO email_templates (name,category,subject,body_html,active,sort_order,visibility,agent_id) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$name,$category,$subject,$body_html,$active,$sort_order,$visibility,$agent_id]);
        }
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        if (!$can_edit($id)) { echo json_encode(['ok'=>false,'msg'=>'Not allowed.']); exit; }
        db()->prepare("DELETE FROM email_templates WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'get') {
        $s = db()->prepare("SELECT * FROM email_templates WHERE id=?");
        $s->execute([(int)$_POST['id']]);
        echo json_encode($s->fetch(PDO::FETCH_ASSOC) ?: []); exit;
    }

    if ($action === 'toggle_active') {
        $id = (int)$_POST['id'];
        if (!$can_edit($id)) { echo json_encode(['ok'=>false,'msg'=>'Not allowed.']); exit; }
        db()->prepare("UPDATE email_templates SET active=1-active WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]); exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown action']); exit;
}

// ── Data ──────────────────────────────────────────────────────────────────────
if ($is_admin) {
    $templates = db()->query(
        "SELECT t.*, a.name AS agent_name
         FROM email_templates t LEFT JOIN agents a ON a.id = t.agent_id
         ORDER BY t.visibility ASC, t.sort_order ASC, t.name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = db()->prepare(
        "SELECT t.*, a.name AS agent_name
         FROM email_templates t LEFT JOIN agents a ON a.id = t.agent_id
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
$pageTitle  = 'Email Templates';
$extra_css  = '
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:200;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px;overflow-y:auto}
.modal-overlay.hidden{display:none}
.modal-box{background:#fff;border-radius:10px;box-shadow:0 8px 40px rgba(0,0,0,.2);width:100%}
.modal-header{padding:15px 24px;border-bottom:1px solid var(--grey-lt);display:flex;align-items:center;justify-content:space-between}
.modal-header h3{font-family:"Merriweather",serif;font-size:.95rem;font-weight:700;margin:0;color:var(--black)}
.modal-body{padding:22px 24px}
.modal-footer{padding:14px 24px;border-top:1px solid var(--grey-lt);display:flex;justify-content:flex-end;gap:10px}
.modal-close{background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--grey-mid);line-height:1;padding:0}
.tpl-grid{display:grid;grid-template-columns:1fr 340px;gap:24px}
.var-panel{background:var(--off-white);border-radius:8px;border:1px solid var(--grey-lt);padding:16px}
.var-panel-title{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--grey-mid);margin-bottom:12px}
.var-tag{display:inline-block;font-family:monospace;font-size:.75rem;background:var(--navy-lt,#e8eef5);color:var(--navy,#1a3a5c);padding:2px 8px;border-radius:4px;cursor:pointer;margin:2px 2px 4px 0;border:none;font-family:monospace}
.var-tag:hover{background:#cfe0f5}
.var-desc{font-size:.72rem;color:var(--grey-mid)}
.tabs{display:flex;border-bottom:2px solid var(--grey-lt)}
.tab-btn{background:none;border:none;padding:7px 16px;font-size:.75rem;font-weight:700;cursor:pointer;color:var(--grey-mid);border-bottom:2px solid transparent;margin-bottom:-2px;font-family:"Open Sans",sans-serif}
.tab-btn.active{color:var(--red);border-bottom-color:var(--red)}
.tab-pane{display:none}.tab-pane.active{display:block}
.section-sep td{background:var(--off-white);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);padding:8px 16px;border-bottom:1px solid var(--grey-lt)}
.vis-badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.vis-public{background:#e8f5e9;color:#2e7d32}
.vis-private{background:#fff3e0;color:#c45000}
.toggle-active{cursor:pointer}
';
include 'includes/header.php';
?>

<div class="page-header">
  <h2>📧 Email Templates</h2>
  <button type="button" class="btn btn-red btn-sm" onclick="openModal(0)">+ New Template</button>
</div>

<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Name</th>
        <th>Category</th>
        <th>Visibility</th>
        <?php if ($is_admin): ?><th>Agent</th><?php endif; ?>
        <th>Subject</th>
        <th style="text-align:center">Active</th>
        <?php if ($is_admin): ?><th style="text-align:center">Order</th><?php endif; ?>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$templates): ?>
      <tr><td colspan="7" style="text-align:center;color:var(--grey-mid);padding:32px">No templates yet.</td></tr>
    <?php endif; ?>
    <?php
    $last_vis = null;
    foreach ($templates as $t):
        $editable = $is_admin || ($t['visibility'] === 'private' && (int)$t['agent_id'] === $my_agent_id);
        if ($is_admin && $t['visibility'] !== $last_vis):
            $last_vis = $t['visibility'];
    ?>
      <tr class="section-sep">
        <td colspan="<?= $is_admin ? '8' : '6' ?>">
          <?= $t['visibility'] === 'public' ? '🌐 Public Templates' : '🔒 Private Templates' ?>
        </td>
      </tr>
    <?php endif; ?>
      <tr style="<?= $t['active'] ? '' : 'opacity:.5' ?>">
        <td style="font-weight:600"><?= h($t['name']) ?></td>
        <td><?= h($t['category'] ?? '') ?></td>
        <td><span class="vis-badge vis-<?= $t['visibility'] ?>"><?= $t['visibility'] === 'public' ? '🌐 Public' : '🔒 Private' ?></span></td>
        <?php if ($is_admin): ?>
        <td style="color:var(--grey-mid)"><?= h($t['agent_name'] ?? '—') ?></td>
        <?php endif; ?>
        <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--grey-dk)"><?= h($t['subject']) ?></td>
        <td style="text-align:center">
          <?php if ($editable): ?>
            <span class="badge toggle-active <?= $t['active'] ? 'status-booked' : '' ?>"
                  style="<?= $t['active'] ? '' : 'background:var(--grey-lt);color:var(--grey-mid)' ?>"
                  onclick="toggleActive(<?= $t['id'] ?>)">
              <?= $t['active'] ? 'Yes' : 'No' ?>
            </span>
          <?php else: ?>
            <span class="badge" style="background:var(--<?= $t['active']?'green':'grey' ?>-lt);color:var(--<?= $t['active']?'green':'grey-mid' ?>)">
              <?= $t['active'] ? 'Yes' : 'No' ?>
            </span>
          <?php endif; ?>
        </td>
        <?php if ($is_admin): ?>
        <td style="text-align:center;color:var(--grey-mid)"><?= $t['sort_order'] ?></td>
        <?php endif; ?>
        <td>
          <?php if ($editable): ?>
          <div style="display:flex;gap:6px">
            <button type="button" class="btn btn-outline btn-sm" onclick="openModal(<?= $t['id'] ?>)">Edit</button>
            <button type="button" class="btn btn-danger btn-sm" onclick="deleteTpl(<?= $t['id'] ?>, '<?= addslashes(h($t['name'])) ?>')">Delete</button>
          </div>
          <?php else: ?>
          <span style="font-size:.72rem;color:var(--grey-lt)">read-only</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ── Modal ─────────────────────────────────────────────────────────────── -->
<div class="modal-overlay hidden" id="tplOverlay" style="display:none">
  <div class="modal-box" style="max-width:960px">
    <div class="modal-header">
      <h3 id="modalTitle">New Template</h3>
      <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="f_id">

      <div style="display:grid;grid-template-columns:1fr 1fr <?= $is_admin ? '130px ' : '' ?>120px;gap:16px;margin-bottom:20px;align-items:start">
        <div class="form-group">
          <label>Name <span style="color:var(--red)">*</span></label>
          <input type="text" id="f_name" autocomplete="off">
        </div>
        <div class="form-group">
          <label>Category</label>
          <input type="text" id="f_category" list="cat_list" placeholder="Visa, Insurance…">
          <datalist id="cat_list">
            <?php foreach ($categories as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?>
          </datalist>
        </div>
        <?php if ($is_admin): ?>
        <div class="form-group">
          <label>Visibility</label>
          <select id="f_visibility">
            <option value="public">🌐 Public</option>
            <option value="private">🔒 Private</option>
          </select>
        </div>
        <?php endif; ?>
        <div class="form-group">
          <label>Order</label>
          <input type="number" id="f_sort_order" value="0">
        </div>
      </div>

      <div style="margin-bottom:18px">
        <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:.82rem">
          <input type="checkbox" id="f_active" checked> Active
        </label>
      </div>

      <div class="form-group" style="margin-bottom:18px">
        <label>Subject <span style="color:var(--red)">*</span></label>
        <input type="text" id="f_subject">
      </div>

      <div class="tpl-grid">
        <div>
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
            <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-dk)">
              Body <span style="color:var(--red)">*</span>
            </div>
            <div class="tabs" style="border:none;margin:0">
              <button type="button" class="tab-btn active" id="tabVisual" onclick="switchEditorTab('visual',this)">Visual</button>
              <?php if ($is_admin): ?>
              <button type="button" class="tab-btn" id="tabHtml" onclick="switchEditorTab('html',this)">HTML</button>
              <?php endif; ?>
              <button type="button" class="tab-btn" id="tabPreview" onclick="switchEditorTab('preview',this)">Preview</button>
            </div>
          </div>

          <!-- Visual (Quill) -->
          <div id="pane-visual" style="border:1.5px solid var(--grey-lt);border-radius:7px;overflow:hidden">
            <div id="quill-editor" style="min-height:300px;font-size:.88rem;font-family:'Open Sans',sans-serif"></div>
          </div>

          <!-- HTML (admin only) -->
          <?php if ($is_admin): ?>
          <div id="pane-html" style="display:none;border:1.5px solid var(--grey-lt);border-radius:7px;overflow:hidden">
            <textarea id="f_body_html" style="width:100%;min-height:320px;font-family:monospace;font-size:.78rem;border:none;padding:12px;resize:vertical;outline:none;box-sizing:border-box"></textarea>
          </div>
          <?php else: ?>
          <textarea id="f_body_html" style="display:none"></textarea>
          <?php endif; ?>

          <!-- Preview -->
          <div id="pane-preview" style="display:none;border:1.5px solid var(--grey-lt);border-radius:7px;padding:16px;min-height:320px;font-size:.88rem">
            <div id="previewContent"></div>
          </div>
        </div>

        <div class="var-panel">
          <div class="var-panel-title">Available Variables</div>
          <p style="font-size:.75rem;color:var(--grey-mid);margin-bottom:12px">Click to insert at cursor:</p>
          <?php
          $vars = [
            '{{customer_name}}' => 'Customer name',
            '{{destination}}'   => 'Destination',
            '{{period}}'        => 'Period (text)',
            '{{pax}}'           => 'Pax count',
            '{{start_date}}'    => 'Arrival date',
            '{{end_date}}'      => 'Departure date',
            '{{agent_name}}'    => 'Agent name',
            '{{agent_email}}'   => 'Agent email',
          ];
          foreach ($vars as $var => $desc): ?>
          <div style="margin-bottom:8px">
            <button type="button" class="var-tag" onclick="insertVar('<?= $var ?>')"><?= $var ?></button>
            <span class="var-desc"> — <?= $desc ?></span>
          </div>
          <?php endforeach; ?>

          <div style="border-top:1px solid var(--grey-lt);margin-top:16px;padding-top:14px">
            <div class="var-panel-title">Tips</div>
            <p style="font-size:.72rem;color:var(--grey-mid);line-height:1.6">
              Use standard HTML for formatting.<br>
              Variables are substituted when you click <strong>Load</strong> in the send dialog.
            </p>
          </div>
        </div>
      </div>

      <div id="saveAlert" style="display:none;margin-top:14px;padding:10px 14px;border-radius:6px;font-size:.82rem"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline" onclick="closeModal()">Cancel</button>
      <button type="button" class="btn btn-red" onclick="saveTemplate()">Save Template</button>
    </div>
  </div>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.snow.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.min.js"></script>
<script>
const IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;

// ── Quill init ────────────────────────────────────────────────────────────────
var quill = new Quill('#quill-editor', {
  theme: 'snow',
  modules: {
    toolbar: [
      ['bold','italic','underline'],
      [{'list':'ordered'},{'list':'bullet'}],
      ['link','clean'],
      [{'color':[]},{'background':[]}],
      [{'align':[]}]
    ]
  }
});

// Keep hidden textarea in sync (for fallback and HTML tab)
quill.on('text-change', function() {
  document.getElementById('f_body_html').value = quill.root.innerHTML;
});

function getBodyHtml() {
  if (document.getElementById('pane-visual').style.display !== 'none') {
    return quill.root.innerHTML;
  }
  return document.getElementById('f_body_html').value;
}

function setBodyHtml(html) {
  quill.root.innerHTML = '';
  if (html) quill.clipboard.dangerouslyPasteHTML(0, html);
  document.getElementById('f_body_html').value = html || '';
}

// ── Editor tab switching ──────────────────────────────────────────────────────
function switchEditorTab(tab, btn) {
  document.querySelectorAll('#tplOverlay .tab-btn').forEach(function(b) { b.classList.remove('active'); });
  if (btn) btn.classList.add('active');

  document.getElementById('pane-visual').style.display   = 'none';
  if (IS_ADMIN) document.getElementById('pane-html').style.display = 'none';
  document.getElementById('pane-preview').style.display  = 'none';

  if (tab === 'visual') {
    document.getElementById('pane-visual').style.display = 'block';
    // Sync from HTML textarea if switching from HTML tab
    if (IS_ADMIN) {
      var html = document.getElementById('f_body_html').value;
      if (html !== quill.root.innerHTML) quill.root.innerHTML = html;
    }
  } else if (tab === 'html') {
    document.getElementById('pane-html').style.display = 'block';
    // Sync textarea from Quill
    document.getElementById('f_body_html').value = quill.root.innerHTML;
  } else if (tab === 'preview') {
    document.getElementById('pane-preview').style.display = 'block';
    document.getElementById('previewContent').innerHTML =
      getBodyHtml() || '<em style="color:var(--grey-mid)">Nothing to preview.</em>';
  }
}

// ── Insert variable at Quill cursor ──────────────────────────────────────────
function insertVar(v) {
  var paneHtmlVisible = IS_ADMIN && document.getElementById('pane-html').style.display !== 'none';
  if (paneHtmlVisible) {
    var ta = document.getElementById('f_body_html');
    var s = ta.selectionStart, e = ta.selectionEnd;
    ta.value = ta.value.substring(0,s) + v + ta.value.substring(e);
    ta.selectionStart = ta.selectionEnd = s + v.length;
    ta.focus();
  } else {
    var range = quill.getSelection(true);
    quill.insertText(range ? range.index : quill.getLength(), v, 'user');
    quill.focus();
  }
}

// ── Modal open/close ─────────────────────────────────────────────────────────
function openModal(id) {
  document.getElementById('f_id').value = id || 0;
  document.getElementById('modalTitle').textContent = id ? 'Edit Template' : 'New Template';
  ['f_name','f_category','f_subject'].forEach(function(k) { document.getElementById(k).value = ''; });
  document.getElementById('f_sort_order').value = 0;
  document.getElementById('f_active').checked = true;
  if (IS_ADMIN) document.getElementById('f_visibility').value = 'public';
  document.getElementById('saveAlert').style.display = 'none';
  setBodyHtml('');
  switchEditorTab('visual', document.getElementById('tabVisual'));

  if (id) {
    fetch('email_templates.php', {
      method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'action=get&id='+id
    }).then(function(r){return r.json();}).then(function(d) {
      if (!d) return;
      document.getElementById('f_name').value       = d.name       || '';
      document.getElementById('f_category').value   = d.category   || '';
      document.getElementById('f_subject').value    = d.subject    || '';
      document.getElementById('f_sort_order').value = d.sort_order || 0;
      document.getElementById('f_active').checked   = d.active == 1;
      if (IS_ADMIN) document.getElementById('f_visibility').value = d.visibility || 'public';
      setBodyHtml(d.body_html || '');
    });
  }
  document.getElementById('tplOverlay').style.display = 'flex';
}

function closeModal() { document.getElementById('tplOverlay').style.display = 'none'; }

// Modal closes only via X button or Cancel — not on overlay click
// (browser autocomplete clicks outside the DOM would trigger close otherwise)
document.querySelector('#tplOverlay .modal-box').addEventListener('click', function(e) {
  e.stopPropagation();
});

// ── Save ─────────────────────────────────────────────────────────────────────
function saveTemplate() {
  var alrt = document.getElementById('saveAlert');
  var body = new URLSearchParams({
    action:     'save',
    id:         document.getElementById('f_id').value,
    name:       document.getElementById('f_name').value,
    category:   document.getElementById('f_category').value,
    subject:    document.getElementById('f_subject').value,
    body_html:  getBodyHtml(),
    sort_order: document.getElementById('f_sort_order').value,
    active:     document.getElementById('f_active').checked ? '1' : '',
    visibility: IS_ADMIN ? document.getElementById('f_visibility').value : 'private',
  });
  fetch('email_templates.php', {method:'POST', body:body})
    .then(function(r){return r.json();})
    .then(function(d) {
      if (d.ok) { location.reload(); }
      else {
        alrt.style.cssText = 'display:block;background:#ffebee;color:#c62828;margin-top:14px;padding:10px 14px;border-radius:6px;font-size:.82rem';
        alrt.textContent = d.msg;
      }
    });
}

function deleteTpl(id, name) {
  if (!confirm('Delete template "' + name + '"?')) return;
  fetch('email_templates.php', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=delete&id='+id
  }).then(function(r){return r.json();}).then(function(d) {
    if (d.ok) location.reload(); else alert(d.msg);
  });
}

function toggleActive(id) {
  fetch('email_templates.php', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=toggle_active&id='+id
  }).then(function() { location.reload(); });
}
</script>

<?php include 'includes/footer.php'; ?>
