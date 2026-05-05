<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$roles  = $pdo->query('SELECT * FROM roles ORDER BY name')->fetchAll();
$agents = $pdo->query('SELECT id, name FROM agents ORDER BY name')->fetchAll();
$errors = [];
$form   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $form['username']  = strtolower(trim($_POST['username']  ?? ''));
    $form['full_name'] = trim($_POST['full_name'] ?? '');
    $form['email']     = trim($_POST['email']     ?? '');
    $form['role_id']   = (int)($_POST['role_id']  ?? 0);
    $form['agent_id']  = (int)($_POST['agent_id'] ?? 0) ?: null;
    $form['is_active'] = isset($_POST['is_active']) ? 1 : 0;
    $password          = $_POST['password']  ?? '';
    $password2         = $_POST['password2'] ?? '';

    if (!$form['username'])               $errors[] = 'Username is required.';
    elseif (!preg_match('/^[a-z0-9_]+$/', $form['username']))
                                          $errors[] = 'Username: lowercase letters, numbers and underscore only.';
    if (!$form['full_name'])              $errors[] = 'Full name is required.';
    if (!$form['role_id'])                $errors[] = 'Role is required.';
    if (!$password)                       $errors[] = 'Password is required.';
    elseif (strlen($password) < 8)        $errors[] = 'Password must be at least 8 characters.';
    if ($password && $password !== $password2) $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $ck = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $ck->execute([$form['username']]);
        if ($ck->fetch()) $errors[] = "Username \"{$form['username']}\" already exists.";
    }

    if (!$errors) {
        $pdo->prepare('
            INSERT INTO users (username, password_hash, full_name, email, role_id, agent_id, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ')->execute([
            $form['username'],
            password_hash($password, PASSWORD_BCRYPT),
            $form['full_name'],
            $form['email'] ?: null,
            $form['role_id'],
            $form['agent_id'],
            $form['is_active'],
        ]);

        flash("User \"{$form['full_name']}\" created successfully.", 'success');
        redirect(BASE_URL . '/admin/users.php');
    }

    foreach ($errors as $err) flash($err, 'error');
}

$page_title = 'New User — Admin';
include __DIR__ . '/../includes/layout_header.php';
?>

<main>
  <div class="page-title">+ New User</div>

  <div class="card" style="max-width:680px;">
    <div class="card-header"><h2>Create a new staff account</h2></div>
    <div class="card-body">
      <form method="POST" action="<?= BASE_URL ?>/admin/user_new.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Full Name *</label>
            <input class="form-control" type="text" name="full_name"
                   value="<?= e($form['full_name'] ?? '') ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">Username *</label>
            <input class="form-control" type="text" name="username"
                   value="<?= e($form['username'] ?? '') ?>"
                   placeholder="lowercase, no spaces"
                   pattern="[a-z0-9_]+" required>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Email</label>
            <input class="form-control" type="email" name="email"
                   value="<?= e($form['email'] ?? '') ?>" placeholder="optional">
          </div>
        </div>

        <div class="form-row">
            <select class="form-control" name="agent_id">
              <option value="">— None (admin / accountant / non-sales) —</option>
              <?php foreach ($agents as $a): ?>
              <option value="<?= (int)$a['id'] ?>"
                <?= (isset($form['agent_id']) && $form['agent_id'] == $a['id']) ? 'selected' : '' ?>>
                <?= e($a['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div class="form-hint">Link this user to a sales agent. Required for agents to appear in the New Request dropdown.</div>
          </div>
          <div class="form-group">
            <label class="form-label">Role *</label>
            <select class="form-control" name="role_id" required>
              <option value="">— Select role —</option>
              <?php foreach ($roles as $r): ?>
              <option value="<?= (int)$r['id'] ?>"
                <?= (isset($form['role_id']) && $form['role_id'] == $r['id']) ? 'selected' : '' ?>>\
                <?= e($r['name']) ?><?= $r['description'] ? ' — ' . e($r['description']) : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Password *</label>
            <input class="form-control" type="password" name="password" autocomplete="new-password" required>
            <div class="form-hint">Minimum 8 characters.</div>
          </div>
          <div class="form-group">
            <label class="form-label">Confirm Password *</label>
            <input class="form-control" type="password" name="password2" autocomplete="new-password" required>
          </div>
        </div>

        <div class="form-group">
          <div class="form-check">
            <input type="checkbox" id="is_active" name="is_active" value="1" checked>
            <label for="is_active">Account is active (user can log in)</label>
          </div>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Create User</button>
          <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-secondary">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../includes/layout_footer.php'; ?>
