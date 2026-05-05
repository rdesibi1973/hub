<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/admin/users.php');

$edited_user = $pdo->prepare('SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?');
$edited_user->execute([$id]);
$edited_user = $edited_user->fetch();
if (!$edited_user) redirect(BASE_URL . '/admin/users.php');

$roles  = $pdo->query('SELECT * FROM roles ORDER BY name')->fetchAll();
$form   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $form['full_name'] = trim($_POST['full_name'] ?? '');
    $form['email']     = trim($_POST['email']     ?? '');
    $form['role_id']   = (int)($_POST['role_id']  ?? 0);
    $form['is_active'] = isset($_POST['is_active']) ? 1 : 0;
    $password          = $_POST['password']  ?? '';
    $password2         = $_POST['password2'] ?? '';

    $errors = [];
    if (!$form['full_name']) $errors[] = 'Full name is required.';
    if (!$form['role_id'])   $errors[] = 'Role is required.';
    if ($password) {
        if (strlen($password) < 8)  $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $password2) $errors[] = 'Passwords do not match.';
    }

    if ($errors) {
        foreach ($errors as $err) flash($err, 'error');
    } else {
        $hash = $password ? password_hash($password, PASSWORD_BCRYPT) : $edited_user['password_hash'];
        $pdo->prepare('
            UPDATE users SET full_name=?, email=?, role_id=?, is_active=?, password_hash=? WHERE id=?
        ')->execute([
            $form['full_name'],
            $form['email'] ?: null,
            $form['role_id'],
            $form['is_active'],
            $hash,
            $id,
        ]);

        flash("User \"{$form['full_name']}\" updated.", 'success');
        redirect(BASE_URL . '/admin/users.php');
    }
}

$page_title = 'Edit User — Admin';
include __DIR__ . '/../includes/layout_header.php';
?>

<main>
  <div class="page-title">✎ Edit User: <?= e($edited_user['full_name']) ?></div>

  <div class="card" style="max-width:680px;">
    <div class="card-header"><h2>Update account details</h2></div>
    <div class="card-body">
      <form method="POST" action="<?= BASE_URL ?>/admin/user_edit.php?id=<?= $id ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Full Name *</label>
            <input class="form-control" type="text" name="full_name"
                   value="<?= e($form['full_name'] ?? $edited_user['full_name']) ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">Username</label>
            <input class="form-control" type="text" value="<?= e($edited_user['username']) ?>" disabled>
            <div class="form-hint">Username cannot be changed.</div>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Email</label>
            <input class="form-control" type="email" name="email"
                   value="<?= e($form['email'] ?? $edited_user['email'] ?? '') ?>" placeholder="optional">
          </div>
          <div class="form-group">
            <label class="form-label">Role *</label>
            <select class="form-control" name="role_id" required>
              <option value="">— Select role —</option>
              <?php foreach ($roles as $r): ?>
              <?php $sel = (isset($form['role_id']) ? $form['role_id'] : $edited_user['role_id']) == $r['id']; ?>
              <option value="<?= (int)$r['id'] ?>" <?= $sel ? 'selected' : '' ?>>
                <?= e($r['name']) ?><?= $r['description'] ? ' — ' . e($r['description']) : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">New Password</label>
            <input class="form-control" type="password" name="password"
                   autocomplete="new-password" placeholder="leave blank to keep current">
            <div class="form-hint">Minimum 8 characters.</div>
          </div>
          <div class="form-group">
            <label class="form-label">Confirm New Password</label>
            <input class="form-control" type="password" name="password2" autocomplete="new-password">
          </div>
        </div>

        <div class="form-group">
          <div class="form-check">
            <input type="checkbox" id="is_active" name="is_active" value="1"
                   <?= (isset($form['is_active']) ? $form['is_active'] : $edited_user['is_active']) ? 'checked' : '' ?>>
            <label for="is_active">Account is active (user can log in)</label>
          </div>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Save Changes</button>
          <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-secondary">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../includes/layout_footer.php'; ?>
