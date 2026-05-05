<?php
requireLogin();
$currentUser = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle ?? 'Lead Tracker') ?> — Savannah Explorers</title>
<link rel="icon" type="image/png" href="https://www.savannahexplorers.net/img/logo-savannah-explorers.png">
<link href="https://fonts.googleapis.com/css2?family=Merriweather:wght@400;700&family=Open+Sans:wght@300;400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<style>
/* ── MAIN HEADER — identical to Savannah Explorers Hub ── */
header {
  background: #fff;
  border-bottom: 3px solid #C0211B;
  padding: 14px 40px;
  display: flex;
  align-items: center;
  gap: 16px;
}
.header-brand {
  display: flex;
  align-items: center;
  gap: 16px;
  text-decoration: none;
  flex: 1;
}
.header-logo {
  height: 64px;
  width: auto;
  object-fit: contain;
  flex-shrink: 0;
}
.header-text h1 {
  font-family: 'Merriweather', serif;
  font-size: 1.4rem;
  font-weight: 700;
  color: #A01A14;
  letter-spacing: .02em;
  line-height: 1.2;
}
.header-text p {
  font-size: .75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .12em;
  color: #888;
  margin-top: 3px;
}
.header-user {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: .75rem;
  color: #888;
  text-align: right;
  flex-shrink: 0;
}
.header-user strong {
  display: block;
  color: #444;
  font-size: .8rem;
  font-weight: 600;
}

/* ── SUB-NAV ── */
.sub-nav {
  background: #fff;
  border-bottom: 1px solid #E8E8E8;
  padding: 0 40px;
  display: flex;
  align-items: center;
  gap: 4px;
}
.sub-nav a {
  font-family: 'Open Sans', sans-serif;
  font-size: .8rem;
  font-weight: 600;
  color: #888;
  text-decoration: none;
  padding: 10px 16px;
  border-bottom: 3px solid transparent;
  transition: color .15s, border-color .15s;
  white-space: nowrap;
}
.sub-nav a:hover { color: #C0211B; }
.sub-nav a.active {
  color: #C0211B;
  border-bottom-color: #C0211B;
}
.sub-nav .sub-nav-logout {
  margin-left: auto;
  color: #aaa;
}
.sub-nav .sub-nav-logout:hover { color: #C0211B; }
</style>
</head>
<body>

<!-- MAIN HEADER — same as Savannah Explorers Hub -->
<header>
  <a href="<?= defined('BASE_URL') ? BASE_URL.'/hub.php' : '../../hub.php' ?>" class="header-brand">
    <img class="header-logo"
         src="https://www.savannahexplorers.net/img/logo-savannah-explorers.png"
         alt="Savannah Explorers"
         onerror="this.style.display='none'">
    <div class="header-text">
      <h1>Savannah Explorers Hub</h1>
      <p>Lead Tracker</p>
    </div>
  </a>
  <?php if (!empty($currentUser)): ?>
  <div class="header-user">
    <div>
      <strong><?= h($currentUser['name'] ?? $currentUser['username'] ?? '') ?></strong>
      <?= h(ucfirst($currentUser['role'] ?? '')) ?>
    </div>
  </div>
  <?php endif; ?>
</header>

<!-- SUB-NAV — Lead Tracker sections -->
<nav class="sub-nav">
  <?php $cur = basename($_SERVER['PHP_SELF']); ?>
  <?php if (!isLeadsRestricted()): ?>
  <a href="dashboard.php" class="<?= $cur==='dashboard.php' ? 'active':'' ?>">Dashboard</a>
  <?php endif; ?>
  <a href="requests.php"  class="<?= in_array($cur,['requests.php','request_add.php','request_edit.php','request_view.php']) ? 'active':'' ?>"><?= isLeadsRestricted() ? 'My Requests' : 'Requests' ?></a>
  <?php if (!isLeadsRestricted()): ?>
  <a href="reports.php"   class="<?= $cur==='reports.php' ? 'active':'' ?>">Reports</a>
  <a href="agents.php"    class="<?= $cur==='agents.php' ? 'active':'' ?>">Agents</a>
  <a href="agencies.php"  class="<?= $cur==='agencies.php' ? 'active':'' ?>">Agencies</a>
  <a href="requests_import_list.php" class="<?= in_array($cur,['requests_import_list.php','request_import_edit.php','reports_import.php']) ? 'active':'' ?>">Historical</a>
  <?php
  if (in_array($currentUser['role_name'] ?? '', ['admin','manager'])):
    $stagingCount = (int)db()->query("SELECT COUNT(*) FROM lead_staging")->fetchColumn();
  ?>
  <a href="staging.php" class="<?= $cur==='staging.php'?'active':'' ?>" style="display:flex;align-items:center;gap:6px;">
    Incoming
    <?php if ($stagingCount): ?>
      <span style="background:#C0211B;color:#fff;border-radius:10px;padding:1px 7px;font-size:.68rem;font-weight:700;line-height:1.6"><?= $stagingCount ?></span>
    <?php endif; ?>
  </a>
  <?php endif; ?>
  <?php endif; // !isLeadsRestricted ?>
  <a href="logout.php"    class="sub-nav-logout">Logout</a>
</nav>

<main>
<?php $flash = getFlash(); if ($flash): ?>
<div class="flash flash-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
<?php endif; ?>
