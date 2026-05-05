<?php
requireInvoiceAccess();
$currentUser = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle ?? 'Invoices') ?> — Savannah Explorers</title>
<link rel="icon" type="image/png" href="https://www.savannahexplorers.net/img/logo-savannah-explorers.png">
<link href="https://fonts.googleapis.com/css2?family=Merriweather:wght@400;700&family=Open+Sans:wght@300;400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<header>
  <a href="<?= defined('BASE_URL') ? BASE_URL.'/hub.php' : '../../hub.php' ?>" class="header-brand">
    <img class="header-logo"
         src="https://www.savannahexplorers.net/img/logo-savannah-explorers.png"
         alt="Savannah Explorers"
         onerror="this.style.display='none'">
    <div class="header-text">
      <div class="header-title">Savannah Explorers Hub</div>
      <div class="header-sub">Invoices</div>
    </div>
  </a>
  <div style="display:flex;align-items:center;gap:16px;font-size:.78rem;color:var(--grey-mid);">
    <span><strong style="color:var(--grey-dk)"><?= h($currentUser['full_name'] ?? $currentUser['username'] ?? '') ?></strong><br><?= h(ucfirst($currentUser['role_name'] ?? '')) ?></span>
  </div>
</header>

<nav class="sub-nav">
  <?php $cur = basename($_SERVER['PHP_SELF']); ?>
  <a href="invoices.php"         class="<?= in_array($cur,['invoices.php','invoice_add.php','invoice_edit.php','invoice_view.php']) ? 'active':'' ?>">Invoices</a>
  <a href="customers.php"        class="<?= $cur==='customers.php'       ? 'active':'' ?>">Customers</a>
  <a href="reports.php"          class="<?= $cur==='reports.php'         ? 'active':'' ?>">Reports</a>
  <a href="booked_requests.php"  class="<?= $cur==='booked_requests.php' ? 'active':'' ?>">← Requests</a>
  <a href="<?= defined('BASE_URL') ? BASE_URL.'/logout.php' : '../../logout.php' ?>" class="nav-logout" style="margin-left:auto;">Logout</a>
</nav>

<style>
.sub-nav {
  background: var(--white);
  border-bottom: 1px solid var(--grey-lt);
  padding: 0 40px;
  display: flex;
  align-items: center;
  gap: 4px;
}
.sub-nav a {
  font-size: .8rem; font-weight: 600;
  color: var(--grey-mid);
  text-decoration: none;
  padding: 10px 16px;
  border-bottom: 3px solid transparent;
  transition: color .15s, border-color .15s;
  white-space: nowrap;
}
.sub-nav a:hover { color: var(--red); }
.sub-nav a.active { color: var(--red); border-bottom-color: var(--red); }
.nav-logout { color: var(--grey-mid) !important; }
.nav-logout:hover { color: var(--red) !important; }
</style>

<main>
<?php $flash = getFlash(); if ($flash): ?>
<div class="flash flash-<?= h($flash['type']) ?>"><?= $flash['msg'] ?></div>
<?php endif; ?>
