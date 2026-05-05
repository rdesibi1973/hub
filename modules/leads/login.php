<?php
require_once 'config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!empty($_SESSION['user_id'])) { header('Location: dashboard.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    $row = db()->prepare("SELECT * FROM users WHERE username = ?");
    $row->execute([$u]);
    $user = $row->fetch();
    if ($user && password_verify($p, $user['password_hash'])) {
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_name'] = $user['full_name'];
        header('Location: dashboard.php');
        exit;
    }
    $error = 'Incorrect username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — Savannah Explorers Lead Tracker</title>
<link rel="icon" type="image/png" href="https://www.savannahexplorers.net/img/logo-savannah-explorers.png">
<link href="https://fonts.googleapis.com/css2?family=Merriweather:wght@400;700&family=Open+Sans:wght@300;400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <img class="login-logo"
         src="https://www.savannahexplorers.net/img/logo-savannah-explorers.png"
         alt="Savannah Explorers"
         onerror="this.style.display='none'">
    <h1>Lead Tracker</h1>
    <p class="sub">Savannah Explorers</p>

    <?php if ($error): ?>
      <div class="login-error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <div class="form-group">
        <label for="username">Username</label>
        <input type="text" id="username" name="username"
               value="<?= h($_POST['username'] ?? '') ?>"
               autocomplete="username" autofocus required>
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password"
               autocomplete="current-password" required>
      </div>
      <button type="submit" class="btn btn-red">Sign In</button>
    </form>
  </div>
</div>
</body>
</html>
