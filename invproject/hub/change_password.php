<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

$cu = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $current  = $_POST['current_password'] ?? '';
    $new      = $_POST['new_password']     ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    // Load current hash
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$cu['id']]);
    $row = $stmt->fetch();

    $errors = [];
    if (!$row || !password_verify($current, $row['password_hash'])) {
        $errors[] = 'Current password is incorrect.';
    }
    if (strlen($new) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }
    if ($new !== $confirm) {
        $errors[] = 'New passwords do not match.';
    }

    if (!$errors) {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_BCRYPT), $cu['id']]);
        flash('Password updated successfully.', 'success');
        redirect(BASE_URL . '/hub.php');
    }

    foreach ($errors as $err) flash($err, 'error');
}

$page_title = 'Change Password';
include __DIR__ . '/includes/layout_header.php';
?>

<main>
  <div class="page-title">🔒 Change Password</div>

  <div class="card" style="max-width:480px;">
    <div class="card-header">
      <h2>Update your password</h2>
    </div>
    <div class="card-body">
      <form method="POST" action="<?= BASE_URL ?>/change_password.php" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div class="form-group">
          <label class="form-label">Current Password *</label>
          <input class="form-control" type="password" name="current_password" autocomplete="current-password" required autofocus>
        </div>

        <div class="form-group">
          <label class="form-label">New Password *</label>
          <input class="form-control" type="password" name="new_password" autocomplete="new-password" required>
          <div class="form-hint">Minimum 8 characters.</div>
        </div>

        <div class="form-group">
          <label class="form-label">Confirm New Password *</label>
          <input class="form-control" type="password" name="confirm_password" autocomplete="new-password" required>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Update Password</button>
          <a href="<?= BASE_URL ?>/hub.php" class="btn btn-secondary">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</main>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
