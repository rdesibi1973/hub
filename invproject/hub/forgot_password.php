<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
start_session();

if (is_logged_in()) redirect(BASE_URL . '/hub.php');

$sent  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $username = strtolower(trim($_POST['username'] ?? ''));

    if (!$username) {
        $error = 'Please enter your username.';
    } else {
        $stmt = $pdo->prepare('SELECT id, full_name, email FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // Always show success to prevent username enumeration
        if ($user && !empty($user['email'])) {
            // Invalidate any previous unused tokens for this user
            $pdo->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0')->execute([$user['id']]);

            // Create new token (expires in 1 hour)
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600);
            $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)')->execute([$user['id'], $token, $expires]);

            $resetUrl = BASE_URL . '/reset_password.php?token=' . $token;
            $subject  = 'Password Reset — Savannah Explorers Hub';
            $body     =
                "Hi {$user['full_name']},\n\n"
              . "You requested a password reset for your Savannah Explorers Hub account.\n\n"
              . "Click the link below to set a new password (valid for 1 hour):\n"
              . "{$resetUrl}\n\n"
              . "If you did not request this, you can ignore this email — your password will not change.\n\n"
              . "— Savannah Explorers Hub";

            $headers = "From: noreply@savannahexplorers.com\r\nContent-Type: text/plain; charset=UTF-8\r\n";
            @mail($user['email'], $subject, $body, $headers);
        }

        $sent = true; // Always show success
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password — Savannah Explorers</title>
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
.success-box{background:var(--green-lt);border-left:4px solid var(--green);border-radius:6px;padding:14px 16px;font-size:.85rem;color:var(--green);font-weight:600;line-height:1.5;}
.form-group{margin-bottom:18px;}
.form-label{display:block;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-dk);margin-bottom:6px;}
.form-control{width:100%;padding:11px 14px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:'Open Sans',sans-serif;font-size:.88rem;color:var(--black);transition:border-color .15s;}
.form-control:focus{outline:none;border-color:var(--red);}
.form-hint{font-size:.75rem;color:var(--grey-mid);margin-bottom:20px;line-height:1.5;}
.btn-login{width:100%;padding:12px;background:var(--red);color:var(--white);border:none;border-radius:6px;font-family:'Open Sans',sans-serif;font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;cursor:pointer;transition:background .15s;}
.btn-login:hover{background:var(--red-dk);}
.back-link{display:block;text-align:center;margin-top:18px;font-size:.78rem;color:var(--grey-mid);text-decoration:none;}
.back-link:hover{color:var(--red-dk);}
.login-footer{text-align:center;margin-top:20px;font-size:.7rem;color:var(--grey-mid);text-transform:uppercase;letter-spacing:.08em;}
</style>
</head>
<body>
<div class="login-card">
  <div class="login-header">
    <img class="login-logo" src="https://www.savannahexplorers.net/img/logo-savannah-explorers.png" alt="Savannah Explorers" onerror="this.style.display='none'">
    <div>
      <h1>Savannah Explorers Hub</h1>
      <p>Password Reset</p>
    </div>
  </div>
  <div class="login-body">
    <?php if ($sent): ?>
      <div class="success-box">
        ✓ If that username exists and has an email address on file, a reset link has been sent.<br><br>
        Check your inbox — the link is valid for <strong>1 hour</strong>.
      </div>
      <a href="<?= BASE_URL ?>/login.php" class="back-link">← Back to Login</a>
    <?php else: ?>
      <?php if ($error): ?>
        <div class="error-box"><?= e($error) ?></div>
      <?php endif; ?>
      <p class="form-hint">Enter your username and we'll send a reset link to your registered email address.</p>
      <form method="POST" action="<?= e(BASE_URL) ?>/forgot_password.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="form-group">
          <label class="form-label" for="username">Username</label>
          <input class="form-control" type="text" id="username" name="username"
                 value="<?= e($_POST['username'] ?? '') ?>" autocomplete="username" autofocus required>
        </div>
        <button type="submit" class="btn-login">Send Reset Link</button>
      </form>
      <a href="<?= BASE_URL ?>/login.php" class="back-link">← Back to Login</a>
    <?php endif; ?>
  </div>
</div>
<div class="login-footer">Savannah Explorers &mdash; Internal Use Only</div>
</body>
</html>
