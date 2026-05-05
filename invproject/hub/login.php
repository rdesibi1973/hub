<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
start_session();

// Already logged in → go to hub
if (is_logged_in()) {
    redirect(BASE_URL . '/hub.php');
}

$error    = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (login_user($pdo, $username, $password)) {
        $next = $_GET['next'] ?? '';
        // Only allow relative redirects
        if ($next && str_starts_with($next, '/') && !str_starts_with($next, '//')) {
            redirect(BASE_URL . $next);
        }
        redirect(BASE_URL . '/hub.php');
    } else {
        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — Savannah Explorers</title>
<link rel="icon" type="image/png" href="https://www.savannahexplorers.net/img/logo-savannah-explorers.png">
<link href="https://fonts.googleapis.com/css2?family=Merriweather:wght@400;700&family=Open+Sans:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
:root { --red:#C0211B;--red-dk:#A01A14;--red-lt:#FAE8E7;--black:#1A1A1A;--grey-dk:#444;--grey-mid:#888;--grey-lt:#E8E8E8;--white:#FFF;--off-white:#F7F5F2; }
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Open Sans',sans-serif;background:var(--off-white);color:var(--black);min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;}
.login-card{background:var(--white);border-radius:14px;box-shadow:0 4px 32px rgba(0,0,0,.12);width:100%;max-width:400px;overflow:hidden;}
.login-header{background:var(--red-dk);padding:28px 32px;display:flex;flex-direction:column;align-items:center;gap:14px;}
.login-logo{height:64px;width:auto;object-fit:contain;}
.login-header h1{font-family:'Merriweather',serif;font-size:1.2rem;font-weight:700;color:var(--white);text-align:center;}
.login-header p{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.14em;color:rgba(255,255,255,.6);}
.login-body{padding:32px;}
.error-box{background:var(--red-lt);border-left:4px solid var(--red);border-radius:6px;padding:10px 14px;margin-bottom:20px;font-size:.82rem;color:var(--red-dk);font-weight:600;}
.form-group{margin-bottom:18px;}
.form-label{display:block;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-dk);margin-bottom:6px;}
.form-control{width:100%;padding:11px 14px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:'Open Sans',sans-serif;font-size:.88rem;color:var(--black);transition:border-color .15s;}
.form-control:focus{outline:none;border-color:var(--red);}
.remember-row{display:flex;align-items:center;gap:8px;margin-bottom:22px;}
.remember-row input{width:16px;height:16px;accent-color:var(--red);cursor:pointer;}
.remember-row label{font-size:.8rem;color:var(--grey-dk);cursor:pointer;}
.btn-login{width:100%;padding:12px;background:var(--red);color:var(--white);border:none;border-radius:6px;font-family:'Open Sans',sans-serif;font-size:.85rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;cursor:pointer;transition:background .15s;}
.btn-login:hover{background:var(--red-dk);}
.login-footer{text-align:center;margin-top:20px;font-size:.7rem;color:var(--grey-mid);text-transform:uppercase;letter-spacing:.08em;}
</style>
</head>
<body>
<div class="login-card">
  <div class="login-header">
    <img class="login-logo"
         src="https://www.savannahexplorers.net/img/logo-savannah-explorers.png"
         alt="Savannah Explorers"
         onerror="this.style.display='none'">
    <div>
      <h1>Savannah Explorers Hub</h1>
      <p>Internal Staff Access</p>
    </div>
  </div>
  <div class="login-body">
    <?php if ($error): ?>
    <div class="error-box"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= e(BASE_URL) ?>/login.php<?= isset($_GET['next']) ? '?next=' . urlencode($_GET['next']) : '' ?>">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

      <div class="form-group">
        <label class="form-label" for="username">Username</label>
        <input class="form-control" type="text" id="username" name="username"
               value="<?= e($username) ?>"
               autocomplete="username" autofocus required>
      </div>

      <div class="form-group">
        <label class="form-label" for="password">Password</label>
        <input class="form-control" type="password" id="password" name="password"
               autocomplete="current-password" required>
      </div>

      <div class="remember-row">
        <input type="checkbox" id="remember" name="remember">
        <label for="remember">Keep me logged in</label>
      </div>

      <button type="submit" class="btn-login">Sign In</button>
    </form>
    <a href="<?= e(BASE_URL) ?>/forgot_password.php" style="display:block;text-align:center;margin-top:16px;font-size:.78rem;color:var(--grey-mid);text-decoration:none;">Forgot your password?</a>
  </div>
</div>
<div class="login-footer">Savannah Explorers &mdash; Internal Use Only</div>
</body>
</html>
