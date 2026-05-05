<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/admin/users.php');
verify_csrf();

$uid = (int)($_POST['user_id'] ?? 0);
$me  = current_user();

if ($uid === (int)$me['id']) {
    flash('You cannot deactivate your own account.', 'error');
} else {
    $stmt = $pdo->prepare('SELECT full_name, is_active FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $u = $stmt->fetch();
    if ($u) {
        $new = $u['is_active'] ? 0 : 1;
        $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$new, $uid]);
        $status = $new ? 'activated' : 'deactivated';
        flash("User \"{$u['full_name']}\" {$status}.", 'success');
    }
}

redirect(BASE_URL . '/admin/users.php');
