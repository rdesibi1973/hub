<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
start_session();

if (is_logged_in()) redirect(BASE_URL . '/hub.php');

$token = trim($_GET['token'] ?? '');
$done  = false;
$error = '';

// Validate token upfront
$reset = null;
if ($token) {
    $stmt = $pdo->prepare('
        SELECT pr.*, u.username, u.full_name
        FROM password_resets pr
        JOIN users u ON u.id = pr.user_id
        WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > NOW()
        LIMIT 1
    ');
    $stmt->execute([$token]);
    $reset = $stmt->fetch();
}

if (!$token || !$reset) {
    $error = 'This reset link is invalid or has expired. Please request a new one.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reset) {
    verify_csrf();

    $password  = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $password2) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $reset['user_id']]);
        $pdo->prepare('UPDATE password_resets SET used = 1 WHERE token = ?')->execute([$token]);
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password — Savannah Explorers</title>
<link rel="icon" type="image/png" href="https://www.savannahexplorers.net/img/logo-savannah-explorers.png">
<link href="https://fonts.googleapis.com/css2?family=Merriweather:wght@400;700&family=Open+Sans:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
:root { --red:#C0211B;--red-dk:#A01A14;--red-lt:#FAE8E7;--black:#1A1A1A;--grey-dk:#444;--grey-mid:#888;--grey-lt:#E8E8E8;--white:#FFF;--off-white:#F7F5F2;--green:#1A6B3A;--green-lt:#EBF5EE; }
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Open Sans',sans-serif;background:var(--off-white);color:var(--black);min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;}
.login-card{background:var(--white);border-radius:14px;box-shadow:0 4px 32px rgba(0,0,0,.12);width:100%;max-width:400px;overflow:hidden;}
.login-header{background:var(--red-dk);padding:28px 32px;display:flex;flex-direction:column;align-items:center;gap:14px;}
.login-logo{height:64px;width:auto;object-fit:contain;}
.login-header h1{font-family:'Merriweather',serif;font-size:1.2rem;font-weight:700;color:var(--white);text-align:center;}
.login-header p{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.14em;color:rgba(255,255,255,.6);}
.login-body{padding:32px;}
.error-box{background:var(--red-lt);border-left:4px solid var(--red);border-radius:6px;padding:10px 14px;margin-bottom:20px;font-size:.82rem;color:var(--red-dk);font-weight:600;}
.success-box{background:var(--green-lt);border-left:4px solid var(--green);border-radius:6px;padding:14px 16px;font-size:.88rem;color:var(--green);font-weight:600;line-height:1.5;}
.form-group{margin-bottom:18px;}
.form-label{display:block;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-dk);margin-bottom:6px;}
.form-control{width:100%;padding:11px 14px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:'Open Sans',sans-serif;font-size:.88rem;color:var(--black);transition:border-color .15s;}
.form-control:focus{outline:none;border-color:var(--red);}
.form-hint{font-size:.72rem;color:var(--grey-mid);margin-top:4px;}
.btn-login{width:100%;padding:12px;background:var(--red);color:var(--white);border:none;border-radius:6px;font-family:'Open Sans',sans-serif;font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;cursor:pointer;transition:background .15s;}
.btn-login:hover{background:var(--red-dk);}
.back-link{display:block;text-align:center;margin-top:18px;font-size:.78rem;color:var(--grey-mid);text-decoration:none;}
.back-link:hover{color:var(--red-dk);}
.login-footer{text-align:center;margin-top:20px;font-size:.7rem;color:var(--grey-mid);text-transform:uppercase;letter-spacing:.08em;}
.greeting{font-size:.85rem;color:var(--grey-dk);margin-bottom:20px;}
</style>
</head>
<body>
<div class="login-card">
  <div class="login-header">
    <img class="login-logo" src="https://www.savannahexplorers.net/img/logo-savannah-explorers.png" alt="Savannah Explorers" onerror="this.style.display='none'">
    <div>
      <h1>Savannah Explorers Hub</h1>
      <p>Set New Password</p>
    </div>
  </div>
  <div class="login-body">
    <?php if ($done): ?>
      <div class="success-box">✓ Password updated successfully.</div>
      <a href="<?= BASE_URL ?>/login.php" class="back-link" style="margin-top:20px;display:block;text-align:center;background:var(--red);color:#fff;padding:12px;border-radius:6px;text-decoration:none;font-weight:700;font-size:.85rem;text-transform:uppercase;letter-spacing:.1em;">Sign In</a>
    <?php elseif (!$reset): ?>
      <div class="error-box"><?= e($error) ?></div>
      <a href="<?= BASE_URL ?>/forgot_password.php" class="back-link">Request a new reset link</a>
    <?php else: ?>
      <?php if ($error): ?>
        <div class="error-box"><?= e($error) ?></div>
      <?php endif; ?>
      <p class="greeting">Hi <strong><?= e($reset['full_name']) ?></strong>, choose a new password.</p>
      <form method="POST" action="<?= e(BASE_URL) ?>/reset_password.php?token=<?= urlencode($token) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="form-group">
          <label class="form-label" for="password">New Password</label>
          <input class="form-control" type="password" id="password" name="password" autocomplete="new-password" autofocus required>
          <div class="form-hint">Minimum 8 characters.</div>
        </div>
        <div class="form-group">
          <label class="form-label" for="password2">Confirm Password</label>
          <input class="form-control" type="password" id="password2" name="password2" autocomplete="new-password" required>
        </div>
        <button type="submit" class="btn-login">Set New Password</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<div class="login-footer">Savannah Explorers &mdash; Internal Use Only</div>
</body>
</html>
