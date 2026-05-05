<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();

// ── Handle: save permissions ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_perms'])) {
    verify_csrf();
    $role_id = (int)($_POST['role_id'] ?? 0);
    $modules = $_POST['modules'] ?? [];
    $valid   = array_keys(MODULES);

    $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$role_id]);
    $ins = $pdo->prepare('INSERT INTO role_permissions (role_id, module) VALUES (?, ?)');
    foreach ($modules as $m) {
        if (in_array($m, $valid, true)) $ins->execute([$role_id, $m]);
    }

    $rname = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
    $rname->execute([$role_id]);
    flash('Permissions for role "' . ($rname->fetchColumn() ?: $role_id) . '" updated.', 'success');
    redirect(BASE_URL . '/admin/roles.php');
}

// ── Handle: create new role ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_role'])) {
    verify_csrf();
    $name = strtolower(trim(str_replace(' ', '_', $_POST['name'] ?? '')));
    $desc = trim($_POST['description'] ?? '');

    if (!$name) {
        flash('Role name is required.', 'error');
    } else {
        $ck = $pdo->prepare('SELECT id FROM roles WHERE name = ?');
        $ck->execute([$name]);
        if ($ck->fetch()) {
            flash("Role \"{$name}\" already exists.", 'error');
        } else {
            $pdo->prepare('INSERT INTO roles (name, description) VALUES (?, ?)')->execute([$name, $desc ?: null]);
            flash("Role \"{$name}\" created.", 'success');
        }
    }
    redirect(BASE_URL . '/admin/roles.php');
}

// ── Load data ───────────────────────────────────────────────────
$roles = $pdo->query('SELECT r.*, COUNT(u.id) AS user_count FROM roles r LEFT JOIN users u ON u.role_id = r.id GROUP BY r.id ORDER BY r.name')->fetchAll();
$perms_by_role = [];
$rows = $pdo->query('SELECT role_id, module FROM role_permissions')->fetchAll();
foreach ($rows as $row) {
    $perms_by_role[$row['role_id']][] = $row['module'];
}

$page_title = 'Roles & Permissions — Admin';
include __DIR__ . '/../includes/layout_header.php';
?>

<style>
.role-card{margin-bottom:20px;}
</style>

<main>
  <div class="page-title">
    🔑 Roles &amp; Permissions
    <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-secondary btn-sm ml-auto">← Back to Users</a>
  </div>

  <?php foreach ($roles as $role): ?>
  <?php $rperms = $perms_by_role[$role['id']] ?? []; ?>
  <div class="card role-card">
    <div class="card-header">
      <h2>
        <?php $rn = $role['name']; ?>
        <span class="badge badge-<?= in_array($rn, ['admin','manager','staff']) ? e($rn) : 'other' ?>" style="font-size:.8rem;">
          <?= e($rn) ?>
        </span>
        <?php if ($role['description']): ?>
        <span style="font-family:'Open Sans',sans-serif;font-size:.82rem;font-weight:400;color:var(--grey-mid);margin-left:8px;">
          <?= e($role['description']) ?>
        </span>
        <?php endif; ?>
      </h2>
      <span style="font-size:.75rem;color:var(--grey-mid);">
        <?= (int)$role['user_count'] ?> user<?= $role['user_count'] != 1 ? 's' : '' ?>
      </span>
    </div>
    <div class="card-body">
      <form method="POST" action="<?= BASE_URL ?>/admin/roles.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="role_id"    value="<?= (int)$role['id'] ?>">
        <input type="hidden" name="save_perms" value="1">

        <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);margin-bottom:10px;">
          Module Access
        </div>
        <div class="perm-grid">
          <?php foreach (MODULES as $key => $label): ?>
          <?php $checked = in_array($key, $rperms, true); ?>
          <label class="perm-chip <?= $checked ? 'checked' : '' ?>">
            <input type="checkbox" name="modules[]" value="<?= e($key) ?>"
                   <?= $checked ? 'checked' : '' ?>
                   onchange="this.closest('.perm-chip').classList.toggle('checked', this.checked)">
            <?= e($label) ?>
          </label>
          <?php endforeach; ?>
        </div>
        <div class="form-actions" style="margin-top:16px;padding-top:12px;">
          <button type="submit" class="btn btn-primary btn-sm">Save Permissions</button>
        </div>
      </form>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- New role -->
  <div class="card" style="max-width:520px;">
    <div class="card-header"><h2>+ Create New Role</h2></div>
    <div class="card-body">
      <form method="POST" action="<?= BASE_URL ?>/admin/roles.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="new_role"   value="1">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Role Name *</label>
            <input class="form-control" type="text" name="name" placeholder="e.g. sales, guide" required>
          </div>
          <div class="form-group">
            <label class="form-label">Description</label>
            <input class="form-control" type="text" name="description" placeholder="optional">
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary btn-sm">Create Role</button>
        </div>
      </form>
    </div>
  </div>

</main>

<?php include __DIR__ . '/../includes/layout_footer.php'; ?>
