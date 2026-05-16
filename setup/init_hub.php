<?php
/**
 * One-time database setup — run once via browser, then DELETE this file.
 * URL: https://hub.savannahexplorers.net/setup/setup_db.php
 *
 * Creates tables: roles, role_permissions, users
 * Seeds default roles and admin user rdesibi.
 */

require_once __DIR__ . '/includes/db.php';

// Safety: refuse to run if users already exist
$count = 0;
// Table may not exist yet — catch error below

$output = [];
$error  = null;

try {
    // Check if already set up
    try {
        $n = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($n > 0) {
            die('<h2>Already set up (' . $n . ' users exist). Delete this file.</h2>');
        }
    } catch (PDOException $e) {
        // Table doesn't exist yet — that's fine, we'll create it
    }

    // ── Create tables ──────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS roles (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            name        VARCHAR(50)  NOT NULL UNIQUE,
            description VARCHAR(200),
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $output[] = '✓ Table <code>roles</code> ready.';

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS role_permissions (
            id      INT AUTO_INCREMENT PRIMARY KEY,
            role_id INT         NOT NULL,
            module  VARCHAR(50) NOT NULL,
            UNIQUE KEY uq_role_module (role_id, module),
            FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $output[] = '✓ Table <code>role_permissions</code> ready.';

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            username      VARCHAR(80)  NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name     VARCHAR(150) NOT NULL,
            email         VARCHAR(150),
            role_id       INT          NOT NULL,
            is_active     TINYINT(1)   NOT NULL DEFAULT 1,
            created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
            last_login    DATETIME,
            FOREIGN KEY (role_id) REFERENCES roles(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $output[] = '✓ Table <code>users</code> ready.';

    // ── Seed roles ─────────────────────────────────────────────
    $roles = [
        ['admin',       'Full access to all modules including user management', ['hub','operations','leave','leads','admin']],
        ['manager',     'Access to hub, operations and leave calendar',         ['hub','operations','leave']],
        ['staff',       'Access to hub and operations only',                    ['hub','operations']],
        ['accountant',  'Full edit access to leads for financial management',   ['hub','leads']],
    ];

    $ins_role = $pdo->prepare('INSERT IGNORE INTO roles (name, description) VALUES (?, ?)');
    $ins_perm = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id, module) VALUES (?, ?)');

    foreach ($roles as [$name, $desc, $perms]) {
        $ins_role->execute([$name, $desc]);
        $role_id = $pdo->query("SELECT id FROM roles WHERE name = '$name'")->fetchColumn();
        foreach ($perms as $m) $ins_perm->execute([$role_id, $m]);
        $output[] = "✓ Role <b>{$name}</b> seeded (" . implode(', ', $perms) . ')';
    }

    // ── Create rdesibi as admin ────────────────────────────────
    $tmp_password = 'Savannah2025!';
    $admin_id     = $pdo->query("SELECT id FROM roles WHERE name = 'admin'")->fetchColumn();

    $pdo->prepare('
        INSERT IGNORE INTO users (username, password_hash, full_name, role_id, is_active)
        VALUES (?, ?, ?, ?, 1)
    ')->execute(['rdesibi', password_hash($tmp_password, PASSWORD_BCRYPT), 'R. Desibi', $admin_id]);

    $output[] = '✓ User <b>rdesibi</b> created as admin.';
    $output[] = '⚠ Temporary password: <code>' . htmlspecialchars($tmp_password) . '</code> — <strong>CHANGE IMMEDIATELY</strong>';

} catch (PDOException $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Setup — Savannah Hub</title>
<style>
body{font-family:sans-serif;max-width:640px;margin:60px auto;padding:0 24px;background:#F7F5F2;}
h1{color:#A01A14;font-size:1.4rem;}
.item{padding:8px 12px;margin:6px 0;border-radius:6px;background:#fff;border-left:4px solid #1A6B3A;font-size:.9rem;}
.warn{border-left-color:#E87722;background:#FEF0E5;}
.err{border-left-color:#C0211B;background:#FAE8E7;}
.done{margin-top:24px;padding:16px;background:#D6EDD9;border-radius:8px;color:#1A6B3A;font-weight:700;}
a{color:#A01A14;}
</style>
</head>
<body>
<h1>Savannah Explorers Hub — Setup</h1>

<?php if ($error): ?>
  <div class="item err">❌ Error: <?= htmlspecialchars($error) ?></div>
<?php else: ?>
  <?php foreach ($output as $line): ?>
    <div class="item <?= str_starts_with($line, '⚠') ? 'warn' : '' ?>"><?= $line ?></div>
  <?php endforeach; ?>
  <div class="done">
    ✓ Setup complete!<br><br>
    1. <a href="<?= BASE_URL ?>/login.php">Log in now</a> with <code>rdesibi</code> / <code>Savannah2025!</code><br>
    2. Change the password immediately in Admin → Edit User.<br>
    3. <strong>Delete this file</strong>: <code>setup/setup_db.php</code>
  </div>
<?php endif; ?>
</body>
</html>
