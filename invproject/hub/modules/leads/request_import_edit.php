<?php
require_once 'config.php';
$pageTitle = 'Edit Historical Request';
$db = db();

$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM requests_import WHERE id = ?");
$stmt->execute([$id]);
$req = $stmt->fetch();
if (!$req) { header('Location: requests_import_list.php'); exit; }

$agents  = $db->query("SELECT * FROM agents ORDER BY name")->fetchAll();
$errors  = [];
$v = $req;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v['date_received'] = trim($_POST['date_received'] ?? '');
    $v['customer_name'] = trim($_POST['customer_name'] ?? '');
    $v['agency_name']   = trim($_POST['agency_name']   ?? '');
    $v['agent_id']      = $_POST['agent_id'] !== '' ? (int)$_POST['agent_id'] : null;
    $v['status']        = trim($_POST['status']        ?? '');
    $v['practice_code'] = trim($_POST['practice_code'] ?? '');

    if (!$v['customer_name']) $errors[] = 'Customer name is required.';
    if (!$v['date_received']) $errors[] = 'Date is required.';

    if (!$errors) {
        $db->prepare("
            UPDATE requests_import SET
              date_received=?, customer_name=?, agency_name=?, agent_id=?, status=?, practice_code=?
            WHERE id=?
        ")->execute([
            $v['date_received'],
            $v['customer_name'],
            $v['agency_name']   ?: null,
            $v['agent_id']      ?: null,
            $v['status'],
            $v['practice_code'] ?: null,
            $id,
        ]);
        header('Location: requests_import_list.php');
        exit;
    }
}

$statuses = ['In Progress','Confirmed','Balance Due','Deposit Paid','Cancelled','Provisional'];

include 'includes/header.php';
?>

<div class="page-header">
  <div>
    <h2>Edit Historical Request</h2>
    <div class="sub"><a href="requests_import_list.php" style="color:var(--grey-mid);text-decoration:none">&#8592; Back to Historical Requests</a></div>
  </div>
</div>

<?php if ($errors): ?>
  <div class="flash flash-error"><?= implode('<br>', array_map('h', $errors)) ?></div>
<?php endif; ?>

<div class="form-card">
  <form method="POST">
    <div class="form-section-title" style="margin-top:0">Request Details</div>
    <div class="form-grid">

      <div class="form-group">
        <label>Date Received *</label>
        <input type="date" name="date_received" value="<?= h($v['date_received']) ?>" required>
      </div>

      <div class="form-group">
        <label>Customer Name *</label>
        <input type="text" name="customer_name" value="<?= h($v['customer_name']) ?>" required>
      </div>

      <div class="form-group">
        <label>Agency</label>
        <input type="text" name="agency_name" value="<?= h($v['agency_name'] ?? '') ?>" placeholder="Agency name">
      </div>

      <div class="form-group">
        <label>Agent</label>
        <select name="agent_id">
          <option value="">— Unassigned —</option>
          <?php foreach ($agents as $ag): ?>
            <option value="<?= $ag['id'] ?>" <?= (string)$v['agent_id']===(string)$ag['id']?'selected':'' ?>><?= h($ag['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>Status</label>
        <select name="status">
          <?php foreach ($statuses as $s): ?>
            <option value="<?= h($s) ?>" <?= $v['status']===$s?'selected':'' ?>><?= h($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group full">
        <label>Dropbox Folder</label>
        <input type="text" name="practice_code" value="<?= h($v['practice_code'] ?? '') ?>">
      </div>

    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-red">Save Changes</button>
      <a href="requests_import_list.php" class="btn btn-outline">Cancel</a>
    </div>
  </form>
</div>

<?php include 'includes/footer.php'; ?>
