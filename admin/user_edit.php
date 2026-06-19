<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/signature_helper.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/admin/users.php');

$edited_user = $pdo->prepare('SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?');
$edited_user->execute([$id]);
$edited_user = $edited_user->fetch();
if (!$edited_user) redirect(BASE_URL . '/admin/users.php');

$agents = $pdo->query('SELECT id, name FROM agents ORDER BY name')->fetchAll();

$roles  = $pdo->query('SELECT * FROM roles ORDER BY name')->fetchAll();
$form   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_kind'] ?? '') === 'signature') {
    verify_csrf();
    [$ok, $msg] = handle_signature_upload($id, $_FILES, $_POST);
    if ($msg !== '') flash($msg, $ok ? 'success' : 'error');
    redirect(BASE_URL . '/admin/user_edit.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $form['full_name'] = trim($_POST['full_name'] ?? '');
    $form['email']     = trim($_POST['email']     ?? '');
    $form['role_id']   = (int)($_POST['role_id']  ?? 0);
    $form['agent_id']  = (int)($_POST['agent_id'] ?? 0) ?: null;
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
            UPDATE users SET full_name=?, email=?, role_id=?, agent_id=?, is_active=?, password_hash=? WHERE id=?
        ')->execute([
            $form['full_name'],
            $form['email'] ?: null,
            $form['role_id'],
            $form['agent_id'],
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
            <label class="form-label">Agent (for sales staff)</label>
            <?php $curAgent = $form['agent_id'] ?? $edited_user['agent_id'] ?? null; ?>
            <select class="form-control" name="agent_id">
              <option value="">— None —</option>
              <?php foreach ($agents as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= $curAgent == $a['id'] ? 'selected' : '' ?>>
                <?= e($a['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div class="form-hint">Required for agents to appear in the New Request dropdown.</div>
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

  <?php
    $hasSig  = user_has_signature($id);
    $sigHtml = $hasSig ? get_user_signature_html($id) : '';
  ?>
  <div class="card" style="margin-top:20px;">
    <div class="card-header"><h2>Email Signature</h2></div>
    <div style="padding:16px 18px;">
      <p style="font-size:.86rem;color:var(--grey-mid);margin-top:0;">
        HTML signature appended to emails this user sends from Hub. Images must
        use full public URLs to display in email clients.
      </p>
      <?php if ($hasSig): ?>
        <div style="margin:14px 0;">
          <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--grey-mid);margin-bottom:6px;">Current signature preview</div>
          <div style="border:1px solid var(--grey-lt);border-radius:8px;padding:14px;background:#fff;"><?= $sigHtml ?></div>
        </div>
      <?php else: ?>
        <p style="font-size:.86rem;color:var(--grey-mid);font-style:italic;">No signature uploaded yet.</p>
      <?php endif; ?>
      <form method="POST" enctype="multipart/form-data" action="<?= BASE_URL ?>/admin/user_edit.php?id=<?= $id ?>" style="margin-top:14px;">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="form_kind" value="signature">
        <div style="display:flex;flex-direction:column;gap:12px;">
          <div>
            <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:5px;">
              <?= $hasSig ? 'Replace signature (.html)' : 'Upload signature (.html)' ?>
            </label>
            <input type="file" name="signature_file" accept=".html,.htm">
          </div>
          <div style="display:flex;gap:10px;align-items:center;">
            <button type="submit" class="btn btn-primary btn-sm">Save Signature</button>
            <?php if ($hasSig): ?>
              <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;color:var(--red-dk);cursor:pointer;">
                <input type="checkbox" name="delete_signature" value="1"> Remove current signature
              </label>
            <?php endif; ?>
          </div>
        </div>
      </form>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../includes/layout_footer.php'; ?>
