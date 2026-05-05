<?php
require_once 'config.php';
$pageTitle = 'Customers';

$db = db();
$errors = [];

// ── Handle add / edit ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isInvoiceAdmin()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $editId  = (int)($_POST['id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $type    = $_POST['type'] ?? 'individual';
        $address = trim($_POST['address'] ?? '');
        $city    = trim($_POST['city'] ?? '');
        $country = trim($_POST['country'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $vat     = trim($_POST['vat_number'] ?? '');
        $notes   = trim($_POST['notes'] ?? '');

        if (!$name) $errors[] = 'Name is required.';
        if (!in_array($type, ['individual','agency','to'])) $errors[] = 'Invalid type.';

        if (!$errors) {
            if ($editId) {
                $db->prepare("UPDATE customers SET type=?,name=?,address=?,city=?,country=?,email=?,phone=?,vat_number=?,notes=? WHERE id=?")
                   ->execute([$type,$name,$address?:null,$city?:null,$country?:null,$email?:null,$phone?:null,$vat?:null,$notes?:null,$editId]);
                flash("Customer updated.");
            } else {
                $db->prepare("INSERT INTO customers (type,name,address,city,country,email,phone,vat_number,notes) VALUES (?,?,?,?,?,?,?,?,?)")
                   ->execute([$type,$name,$address?:null,$city?:null,$country?:null,$email?:null,$phone?:null,$vat?:null,$notes?:null]);
                flash("Customer added.");
            }
            header('Location: customers.php'); exit;
        }
    }

    if ($action === 'toggle' && isInvoiceAdmin()) {
        $cid = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE customers SET active = 1 - active WHERE id=?")->execute([$cid]);
        header('Location: customers.php'); exit;
    }
}

// ── Filters ───────────────────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$ftype  = $_GET['type'] ?? '';
$showId = (int)($_GET['edit'] ?? 0);

$where = ['1=1']; $params = [];
if ($search) { $where[] = 'name LIKE ?'; $params[] = "%$search%"; }
if ($ftype && in_array($ftype, ['individual','agency','to'])) { $where[] = 'type = ?'; $params[] = $ftype; }

$stmt = $db->prepare("SELECT * FROM customers WHERE " . implode(' AND ', $where) . " ORDER BY name");
$stmt->execute($params);
$customers = $stmt->fetchAll();

// If editing, fetch that customer
$editing = null;
if ($showId) {
    $s = $db->prepare("SELECT * FROM customers WHERE id=?"); $s->execute([$showId]);
    $editing = $s->fetch();
}

include 'includes/header.php';
?>

<div class="page-header">
  <div>
    <h2>Customers</h2>
    <div class="sub"><?= count($customers) ?> customer<?= count($customers)!==1?'s':'' ?></div>
  </div>
  <?php if (isInvoiceAdmin()): ?>
  <a href="customers.php?edit=new" class="btn btn-red">+ New Customer</a>
  <?php endif; ?>
</div>

<?php if ($errors): ?>
  <div class="flash flash-error"><?= implode('<br>', array_map('h', $errors)) ?></div>
<?php endif; ?>

<!-- ADD / EDIT FORM -->
<?php if (isInvoiceAdmin() && (isset($_GET['edit']))): ?>
<div class="form-card" style="max-width:780px;margin-bottom:28px;">
  <div class="form-section"><?= $editing ? 'Edit Customer' : 'New Customer' ?></div>
  <form method="POST">
    <input type="hidden" name="action" value="save">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
    <div class="form-grid">
      <div class="form-group full">
        <label>Name *</label>
        <input type="text" name="name" value="<?= h($editing['name'] ?? '') ?>" required placeholder="Full name or company name">
      </div>
      <div class="form-group">
        <label>Type</label>
        <select name="type">
          <?php foreach (['individual'=>'Individual','agency'=>'Agency','to'=>'Tour Operator'] as $v=>$l): ?>
            <option value="<?= $v ?>" <?= ($editing['type'] ?? 'individual')===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>VAT / Tax Number</label>
        <input type="text" name="vat_number" value="<?= h($editing['vat_number'] ?? '') ?>" placeholder="Optional">
      </div>
      <div class="form-group full">
        <label>Address</label>
        <input type="text" name="address" value="<?= h($editing['address'] ?? '') ?>" placeholder="Street address">
      </div>
      <div class="form-group">
        <label>City</label>
        <input type="text" name="city" value="<?= h($editing['city'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Country</label>
        <input type="text" name="country" value="<?= h($editing['country'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" value="<?= h($editing['email'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Phone / WhatsApp</label>
        <input type="text" name="phone" value="<?= h($editing['phone'] ?? '') ?>">
      </div>
      <div class="form-group full">
        <label>Notes</label>
        <textarea name="notes"><?= h($editing['notes'] ?? '') ?></textarea>
      </div>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-red"><?= $editing ? 'Save Changes' : 'Add Customer' ?></button>
      <a href="customers.php" class="btn btn-grey">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- FILTER -->
<form method="GET" class="filters">
  <div><label>Search</label><input type="text" name="q" value="<?= h($search) ?>" placeholder="Customer name…"></div>
  <div>
    <label>Type</label>
    <select name="type">
      <option value="">All types</option>
      <option value="individual" <?= $ftype==='individual'?'selected':'' ?>>Individual</option>
      <option value="agency"     <?= $ftype==='agency'    ?'selected':'' ?>>Agency</option>
      <option value="to"         <?= $ftype==='to'        ?'selected':'' ?>>Tour Operator</option>
    </select>
  </div>
  <div><label>&nbsp;</label><button type="submit" class="btn btn-outline">Filter</button></div>
  <div><label>&nbsp;</label><a href="customers.php" class="btn btn-grey">✕ Clear</a></div>
</form>

<!-- TABLE -->
<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Name</th>
        <th>Type</th>
        <th>Email</th>
        <th>Phone</th>
        <th>City / Country</th>
        <th>VAT</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if ($customers): ?>
        <?php foreach ($customers as $c): ?>
        <tr <?= $c['active'] ? '' : 'style="opacity:.45"' ?>>
          <td style="font-weight:600"><?= h($c['name']) ?></td>
          <td>
            <span class="badge type-<?= $c['type'] ?>">
              <?= $c['type'] === 'to' ? 'Tour Op.' : ucfirst($c['type']) ?>
            </span>
          </td>
          <td class="text-muted"><?= $c['email'] ? '<a href="mailto:'.h($c['email']).'">'.h($c['email']).'</a>' : '—' ?></td>
          <td class="text-muted"><?= h($c['phone'] ?? '—') ?></td>
          <td class="text-muted"><?= h(implode(', ', array_filter([$c['city'], $c['country']])) ?: '—') ?></td>
          <td class="text-muted"><?= h($c['vat_number'] ?? '—') ?></td>
          <td>
            <div class="gap-8">
              <?php if (isInvoiceAdmin()): ?>
              <a href="customers.php?edit=<?= $c['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button type="submit" class="btn btn-outline btn-sm <?= $c['active'] ? '' : 'btn-green' ?>">
                  <?= $c['active'] ? 'Disable' : 'Enable' ?>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="7">
          <div class="empty-state"><div class="icon">👤</div><p>No customers found.</p></div>
        </td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php include 'includes/footer.php'; ?>
