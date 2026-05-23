<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// ── AJAX handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    $action = $_POST['action'];

    if ($action === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $name       = trim($_POST['name'] ?? '');
        $category   = trim($_POST['category'] ?? '');
        $subject    = trim($_POST['subject'] ?? '');
        $body_html  = trim($_POST['body_html'] ?? '');
        $active     = isset($_POST['active']) ? 1 : 0;
        $sort_order = (int)($_POST['sort_order'] ?? 0);

        if (!$name || !$subject || !$body_html) {
            echo json_encode(['ok'=>false,'msg'=>'Name, subject and body are required.']);
            exit;
        }
        if ($id) {
            $stmt = $pdo->prepare("UPDATE email_templates SET name=?,category=?,subject=?,body_html=?,active=?,sort_order=? WHERE id=?");
            $stmt->execute([$name,$category,$subject,$body_html,$active,$sort_order,$id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO email_templates (name,category,subject,body_html,active,sort_order) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$name,$category,$subject,$body_html,$active,$sort_order]);
        }
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM email_templates WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'get') {
        $id = (int)($_POST['id'] ?? 0);
        $row = $pdo->prepare("SELECT * FROM email_templates WHERE id=?");
        $row->execute([$id]);
        echo json_encode($row->fetch(PDO::FETCH_ASSOC) ?: []);
        exit;
    }

    if ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE email_templates SET active = 1-active WHERE id=?")->execute([$id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Unknown action']);
    exit;
}

// ── Fetch all templates ───────────────────────────────────────────────────────
$templates = $pdo->query(
    "SELECT * FROM email_templates ORDER BY sort_order ASC, name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Distinct categories for the filter
$categories = array_values(array_unique(array_filter(array_column($templates, 'category'))));
sort($categories);

$page_title = 'Email Templates';
include __DIR__ . '/includes/layout_header.php';
?>

<div class="container-fluid py-3">

  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0">Email Templates</h4>
    <button class="btn btn-success btn-sm" onclick="openModal(0)">
      <i class="fas fa-plus"></i> New Template
    </button>
  </div>

  <div class="card shadow-sm">
    <div class="card-body p-0">
      <table class="table table-sm table-hover mb-0" id="tplTable">
        <thead class="thead-light">
          <tr>
            <th style="width:40px">#</th>
            <th>Name</th>
            <th>Category</th>
            <th>Subject</th>
            <th style="width:70px" class="text-center">Active</th>
            <th style="width:70px" class="text-center">Order</th>
            <th style="width:100px"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($templates as $t): ?>
          <tr id="row-<?= $t['id'] ?>" class="<?= $t['active'] ? '' : 'text-muted' ?>">
            <td><?= $t['id'] ?></td>
            <td><?= htmlspecialchars($t['name']) ?></td>
            <td><?= htmlspecialchars($t['category'] ?? '') ?></td>
            <td class="small"><?= htmlspecialchars($t['subject']) ?></td>
            <td class="text-center">
              <span class="badge badge-<?= $t['active'] ? 'success' : 'secondary' ?> cursor-pointer toggle-active"
                    data-id="<?= $t['id'] ?>">
                <?= $t['active'] ? 'Yes' : 'No' ?>
              </span>
            </td>
            <td class="text-center"><?= $t['sort_order'] ?></td>
            <td class="text-right">
              <button class="btn btn-xs btn-outline-primary" onclick="openModal(<?= $t['id'] ?>)">Edit</button>
              <button class="btn btn-xs btn-outline-danger"  onclick="deleteTemplate(<?= $t['id'] ?>, '<?= addslashes(htmlspecialchars($t['name'])) ?>')">Del</button>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$templates): ?>
          <tr><td colspan="7" class="text-center text-muted py-3">No templates yet.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ── Add / Edit Modal ──────────────────────────────────────────────────── -->
<div class="modal fade" id="tplModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title mb-0" id="tplModalTitle">New Template</h6>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">

        <div class="row">
          <div class="col-md-8">
            <div class="form-row">
              <div class="form-group col-md-6">
                <label class="small font-weight-bold">Name <span class="text-danger">*</span></label>
                <input type="text" id="f_name" class="form-control form-control-sm">
              </div>
              <div class="form-group col-md-4">
                <label class="small font-weight-bold">Category</label>
                <input type="text" id="f_category" class="form-control form-control-sm"
                       placeholder="Visa, Insurance, Travel…"
                       list="cat_list">
                <datalist id="cat_list">
                  <?php foreach ($categories as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>">
                  <?php endforeach; ?>
                  <option value="Visa">
                  <option value="Insurance">
                  <option value="Travel Info">
                  <option value="Health">
                  <option value="Payment">
                  <option value="General">
                </datalist>
              </div>
              <div class="form-group col-md-2">
                <label class="small font-weight-bold">Order</label>
                <input type="number" id="f_sort_order" class="form-control form-control-sm" value="0">
              </div>
            </div>
            <div class="form-group">
              <label class="small font-weight-bold">Subject <span class="text-danger">*</span></label>
              <input type="text" id="f_subject" class="form-control form-control-sm">
            </div>
            <div class="form-group mb-1">
              <label class="small font-weight-bold">Body (HTML) <span class="text-danger">*</span></label>
              <textarea id="f_body_html" class="form-control form-control-sm font-monospace"
                        rows="16" style="font-size:12px;resize:vertical"></textarea>
            </div>
            <div class="custom-control custom-checkbox mb-2">
              <input type="checkbox" class="custom-control-input" id="f_active" checked>
              <label class="custom-control-label small" for="f_active">Active</label>
            </div>
          </div>

          <div class="col-md-4">
            <div class="card bg-light border-0 h-100">
              <div class="card-body small">
                <p class="font-weight-bold mb-2">Available Variables</p>
                <p class="text-muted mb-1">Click to insert at cursor:</p>
                <?php
                $vars = [
                    '{{customer_name}}' => 'Customer full name',
                    '{{destination}}'   => 'Destination',
                    '{{period}}'        => 'Period (text)',
                    '{{pax}}'           => 'Number of pax',
                    '{{start_date}}'    => 'Arrival date (e.g. 12 Apr 2026)',
                    '{{end_date}}'      => 'Departure date',
                    '{{agent_name}}'    => 'Agent full name',
                    '{{agent_email}}'   => 'Agent email',
                ];
                foreach ($vars as $var => $desc): ?>
                  <div class="mb-1">
                    <code class="var-tag cursor-pointer text-primary"
                          data-var="<?= $var ?>"><?= $var ?></code>
                    <span class="text-muted"> — <?= $desc ?></span>
                  </div>
                <?php endforeach; ?>

                <hr>
                <p class="font-weight-bold mb-1">Preview</p>
                <button type="button" class="btn btn-sm btn-outline-secondary w-100" id="btnPreview">
                  Show Preview
                </button>
                <div id="previewBox" class="mt-2 border rounded p-2 bg-white d-none"
                     style="max-height:300px;overflow-y:auto;font-size:12px"></div>
              </div>
            </div>
          </div>
        </div>

      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-primary" id="btnSave">Save Template</button>
      </div>
    </div>
  </div>
</div>

<script>
let editingId = 0;

function openModal(id) {
  editingId = id;
  document.getElementById('tplModalTitle').textContent = id ? 'Edit Template' : 'New Template';
  // Clear
  ['f_name','f_category','f_subject','f_body_html','f_sort_order'].forEach(k => {
    document.getElementById(k).value = '';
  });
  document.getElementById('f_active').checked = true;
  document.getElementById('f_sort_order').value = 0;
  document.getElementById('previewBox').classList.add('d-none');

  if (id) {
    fetch('email_templates.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:`action=get&id=${id}`
    })
    .then(r=>r.json())
    .then(d => {
      if (!d) return;
      document.getElementById('f_name').value       = d.name       || '';
      document.getElementById('f_category').value   = d.category   || '';
      document.getElementById('f_subject').value    = d.subject    || '';
      document.getElementById('f_body_html').value  = d.body_html  || '';
      document.getElementById('f_sort_order').value = d.sort_order || 0;
      document.getElementById('f_active').checked   = d.active == 1;
    });
  }
  $('#tplModal').modal('show');
}

document.getElementById('btnSave').addEventListener('click', () => {
  const body = new URLSearchParams({
    action:     'save',
    id:         editingId,
    name:       document.getElementById('f_name').value,
    category:   document.getElementById('f_category').value,
    subject:    document.getElementById('f_subject').value,
    body_html:  document.getElementById('f_body_html').value,
    sort_order: document.getElementById('f_sort_order').value,
    active:     document.getElementById('f_active').checked ? '1' : '',
  });
  fetch('email_templates.php', {method:'POST', body})
    .then(r=>r.json())
    .then(d => {
      if (d.ok) location.reload();
      else alert(d.msg);
    });
});

function deleteTemplate(id, name) {
  if (!confirm(`Delete template "${name}"?`)) return;
  fetch('email_templates.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:`action=delete&id=${id}`
  }).then(r=>r.json()).then(d => { if (d.ok) location.reload(); });
}

// Toggle active badge
document.querySelectorAll('.toggle-active').forEach(el => {
  el.addEventListener('click', () => {
    fetch('email_templates.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:`action=toggle_active&id=${el.dataset.id}`
    }).then(()=>location.reload());
  });
});

// Insert variable at cursor
document.querySelectorAll('.var-tag').forEach(tag => {
  tag.addEventListener('click', () => {
    const ta = document.getElementById('f_body_html');
    const v  = tag.dataset.var;
    const start = ta.selectionStart, end = ta.selectionEnd;
    ta.value = ta.value.substring(0,start) + v + ta.value.substring(end);
    ta.selectionStart = ta.selectionEnd = start + v.length;
    ta.focus();
  });
});

// Preview
document.getElementById('btnPreview').addEventListener('click', () => {
  const box = document.getElementById('previewBox');
  box.innerHTML = document.getElementById('f_body_html').value || '<em>Nothing to preview.</em>';
  box.classList.remove('d-none');
});
</script>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
