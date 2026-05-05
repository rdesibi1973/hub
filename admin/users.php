<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$users = $pdo->query('
    SELECT u.*, r.name AS role_name
    FROM   users u
    JOIN   roles r ON u.role_id = r.id
    ORDER  BY u.full_name
')->fetchAll();

$page_title = 'Users — Admin';
include __DIR__ . '/../includes/layout_header.php';
?>

<main>
  <div class="page-title">
    ⚙ User Management
    <a href="<?= BASE_URL ?>/admin/roles.php" class="btn btn-secondary btn-sm ml-auto">Manage Roles</a>
    <a href="<?= BASE_URL ?>/admin/user_new.php" class="btn btn-primary btn-sm">+ New User</a>
  </div>

  <div class="card">
    <div class="card-header">
      <h2>All Users (<?= count($users) ?>)</h2>
    </div>
    <div style="overflow-x:auto;">
      <table class="data-table">
        <thead>
          <tr>
            <th>Name</th><th>Username</th><th>Email</th>
            <th>Role</th><th>Status</th><th>Last Login</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td class="td-name"><?= e($u['full_name']) ?></td>
            <td style="font-family:monospace;font-size:.78rem;"><?= e($u['username']) ?></td>
            <td><?= e($u['email'] ?? '—') ?></td>
            <td>
              <?php $rn = $u['role_name']; ?>
              <span class="badge badge-<?= in_array($rn, ['admin','manager','staff']) ? e($rn) : 'other' ?>">
                <?= e($rn) ?>
              </span>
            </td>
            <td>
              <span class="badge <?= $u['is_active'] ? 'badge-active' : 'badge-inactive' ?>">
                <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
              </span>
            </td>
            <td style="font-size:.75rem;color:var(--grey-mid);">
              <?= $u['last_login'] ? date('d/m/Y H:i', strtotime($u['last_login'])) : 'Never' ?>
            </td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <a href="<?= BASE_URL ?>/admin/user_edit.php?id=<?= (int)$u['id'] ?>"
                   class="btn btn-secondary btn-sm">Edit</a>

                <form method="POST" action="<?= BASE_URL ?>/admin/user_toggle.php" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="user_id"   value="<?= (int)$u['id'] ?>">
                  <button type="submit"
                          class="btn btn-sm <?= $u['is_active'] ? 'btn-danger' : 'btn-success' ?>"
                          onclick="return confirm('<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?> <?= e($u['full_name']) ?>?')">
                    <?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../includes/layout_footer.php'; ?>
