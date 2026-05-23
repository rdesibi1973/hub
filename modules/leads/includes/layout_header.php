<?php

// layout_header.php — include at the top of every page

// Usage: include __DIR__ . '/../includes/layout_header.php';  (adjust path)

// Expects $page_title to be set before including.



require_once __DIR__ . '/auth.php';

start_session();

$user      = current_user();

$flash_msgs = get_flash_messages();

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title><?= e($page_title ?? 'Savannah Explorers Hub') ?></title>

<link rel="icon" type="image/png" href="https://www.savannahexplorers.net/img/logo-savannah-explorers.png">

<link href="https://fonts.googleapis.com/css2?family=Merriweather:wght@400;700&family=Open+Sans:wght@300;400;600;700&display=swap" rel="stylesheet">

<style>

:root {

  --red:       #C0211B; --red-dk:  #A01A14; --red-lt:   #FAE8E7;

  --black:     #1A1A1A; --grey-dk: #444;    --grey-mid: #888; --grey-lt: #E8E8E8;

  --white:     #FFF;    --off-white: #F7F5F2;

  --green:     #1A6B3A; --green-lt: #EBF5EE;

  --amber:     #E87722; --amber-lt: #FEF0E5;

  --navy:      #0062B1; --navy-lt:  #E5F0FC;

  --blue:      #1877F2; --blue-lt:  #EAF1FE;

}

* { box-sizing: border-box; margin: 0; padding: 0; }

body { font-family: 'Open Sans', sans-serif; background: var(--off-white); color: var(--black); min-height: 100vh; }



/* HEADER */

header { background: var(--white); border-bottom: 3px solid var(--red); padding: 14px 40px; display: flex; align-items: center; gap: 16px; }

.header-logo { height: 56px; width: auto; object-fit: contain; flex-shrink: 0; }

.header-text { flex: 1; }

.header-text h1 { font-family: 'Merriweather', serif; font-size: 1.4rem; font-weight: 700; color: var(--red-dk); letter-spacing: .02em; }

.header-text p { font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .12em; color: var(--grey-mid); margin-top: 3px; }

.header-user { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }

.user-info .user-name { font-size: .78rem; font-weight: 600; color: var(--grey-dk); }

.user-info .user-role { font-size: .68rem; text-transform: uppercase; letter-spacing: .1em; color: var(--grey-mid); }

.btn-header { font-family: 'Open Sans', sans-serif; font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; padding: 7px 14px; border-radius: 6px; border: none; cursor: pointer; text-decoration: none; transition: background .15s; display: inline-block; }

.btn-logout { background: var(--red-lt); color: var(--red-dk); }

.btn-logout:hover { background: #f5d0ce; }

.btn-admin  { background: var(--navy-lt); color: var(--navy); }

.btn-admin:hover  { background: #cfe0f5; }



/* FLASH */

.flash-container { padding: 12px 40px 0; }

.flash { padding: 11px 16px; border-radius: 6px; margin-bottom: 8px; font-size: .82rem; font-weight: 600; }

.flash.success { background: var(--green-lt); color: var(--green);   border-left: 4px solid var(--green); }

.flash.error   { background: var(--red-lt);   color: var(--red-dk);  border-left: 4px solid var(--red); }

.flash.warning { background: #FFF8E1;          color: #7A4F01;        border-left: 4px solid var(--amber); }

.flash.info    { background: var(--navy-lt);   color: var(--navy);    border-left: 4px solid var(--navy); }



/* MAIN */

main { padding: 36px 40px 60px; }



/* SECTION LABELS */

.section-label { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .14em; color: var(--grey-mid); margin-bottom: 14px; margin-top: 36px; display: flex; align-items: center; gap: 8px; }

.section-label::before { content: ''; display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--red); }

.section-label:first-of-type { margin-top: 0; }

.section-label.dot-green::before  { background: var(--green); }

.section-label.dot-amber::before  { background: var(--amber); }

.section-label.dot-navy::before   { background: var(--navy); }

.section-label.dot-drive::before  { background: #4285F4; }

.group-spacer { margin-top: 28px; }



/* PRIMARY CARDS */

.primary-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }

.primary-card { background: var(--white); border-radius: 10px; box-shadow: 0 1px 8px rgba(0,0,0,.08); border-left: 4px solid var(--red); padding: 20px 22px; text-decoration: none; display: flex; align-items: center; gap: 16px; transition: box-shadow .18s, transform .18s; }

.primary-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,.13); transform: translateY(-2px); }

.pc-icon { font-size: 1.7rem; flex-shrink: 0; width: 46px; height: 46px; background: var(--red-lt); border-radius: 10px; display: flex; align-items: center; justify-content: center; overflow: hidden; }

.pc-icon img { width: 100%; height: 100%; object-fit: contain; }

.pc-icon.pc-logo { width: 90px; height: 44px; background: var(--white); border: 1px solid var(--grey-lt); padding: 5px 8px; }

.pc-text h3 { font-family: 'Merriweather', serif; font-size: .95rem; font-weight: 700; color: var(--red-dk); margin-bottom: 3px; }

.pc-text p { font-size: .73rem; color: var(--grey-mid); }

.pc-arrow { margin-left: auto; color: var(--grey-lt); font-size: 1rem; flex-shrink: 0; }

.primary-card.pc-green { border-left-color: var(--green); }

.primary-card.pc-green .pc-icon { background: var(--green-lt); }

.primary-card.pc-green .pc-text h3 { color: var(--green); }



/* LINK CARDS */

.links-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(188px, 1fr)); gap: 12px; }

.link-card { background: var(--white); border-radius: 8px; box-shadow: 0 1px 6px rgba(0,0,0,.07); border-top: 3px solid var(--grey-lt); padding: 14px 16px; text-decoration: none; display: flex; align-items: center; gap: 12px; transition: box-shadow .15s, transform .15s; overflow: hidden; }

.link-card > div:last-child { min-width: 0; }

.link-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.11); transform: translateY(-2px); }

.lc-icon { flex-shrink: 0; width: 36px; height: 36px; border-radius: 8px; background: var(--off-white); display: flex; align-items: center; justify-content: center; overflow: hidden; }

.lc-icon img { width: 100%; height: 100%; object-fit: contain; border-radius: 6px; }

.lc-icon.emoji { font-size: 1.25rem; }

.lc-label { font-size: .82rem; font-weight: 700; color: var(--black); line-height: 1.3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.lc-sub   { font-size: .68rem; color: var(--grey-mid); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.lc-green   { border-top-color: var(--green); } .lc-green   .lc-icon { background: var(--green-lt); }

.lc-orange  { border-top-color: #FF7A59; }      .lc-orange  .lc-icon { background: #FFF2EE; }

.lc-blue    { border-top-color: var(--blue); }  .lc-blue    .lc-icon { background: var(--blue-lt); }

.lc-pink    { border-top-color: #C13584; }      .lc-pink    .lc-icon { background: #FDE8EF; }

.lc-teal    { border-top-color: #00AA6C; }      .lc-teal    .lc-icon { background: #E0F6EE; }

.lc-amber   { border-top-color: var(--amber); } .lc-amber   .lc-icon { background: var(--amber-lt); }

.lc-navy    { border-top-color: var(--navy); }  .lc-navy    .lc-icon { background: var(--navy-lt); }

.lc-brevo   { border-top-color: #0092FF; }      .lc-brevo   .lc-icon { background: #E5F4FF; }

.lc-grey    { border-top-color: var(--grey-mid); }

.lc-gdrive  { border-top-color: #4285F4; }      .lc-gdrive  .lc-icon { background: #E8F0FE; }

.lc-dropbox { border-top-color: #0061FF; }      .lc-dropbox .lc-icon { background: #E5F0FF; }



/* ADMIN / SHARED */

.page-title { font-family: 'Merriweather', serif; font-size: 1.3rem; font-weight: 700; color: var(--red-dk); margin-bottom: 24px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }

.btn { font-family: 'Open Sans', sans-serif; font-size: .78rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; padding: 9px 20px; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-block; transition: all .15s; }

.btn-primary   { background: var(--red);      color: var(--white); }

.btn-primary:hover   { background: var(--red-dk); }

.btn-secondary { background: var(--grey-lt);  color: var(--grey-dk); }

.btn-secondary:hover { background: #d8d8d8; }

.btn-danger    { background: var(--red-lt);   color: var(--red-dk); }

.btn-danger:hover    { background: #f5d0ce; }

.btn-success   { background: var(--green-lt); color: var(--green); }

.btn-success:hover   { background: #c4e3c8; }

.btn-sm { padding: 5px 12px; font-size: .7rem; }

.ml-auto { margin-left: auto; }



.card { background: var(--white); border-radius: 10px; box-shadow: 0 1px 8px rgba(0,0,0,.08); overflow: hidden; }

.card-header { padding: 16px 24px; border-bottom: 1px solid var(--grey-lt); display: flex; align-items: center; justify-content: space-between; }

.card-header h2 { font-family: 'Merriweather', serif; font-size: 1rem; font-weight: 700; color: var(--black); }

.card-body { padding: 24px; }



table.data-table { width: 100%; border-collapse: collapse; font-size: .82rem; }

table.data-table th { background: var(--black); color: rgba(255,255,255,.8); padding: 10px 16px; text-align: left; font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; white-space: nowrap; }

table.data-table td { padding: 11px 16px; border-bottom: 1px solid var(--grey-lt); color: var(--grey-dk); vertical-align: middle; }

table.data-table tr:last-child td { border-bottom: none; }

table.data-table tr:hover td { background: #FAFAFA; }

.td-name { font-weight: 700; color: var(--black); }

.badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; }

.badge-admin    { background: var(--red-lt);   color: var(--red-dk); }

.badge-manager  { background: var(--amber-lt); color: #7A4F01; }

.badge-staff    { background: var(--navy-lt);  color: var(--navy); }

.badge-other    { background: var(--grey-lt);  color: var(--grey-dk); }

.badge-active   { background: var(--green-lt); color: var(--green); }

.badge-inactive { background: var(--grey-lt);  color: var(--grey-mid); }



.form-group { margin-bottom: 20px; }

.form-label { display: block; font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--grey-dk); margin-bottom: 6px; }

.form-control { width: 100%; padding: 10px 14px; border: 1.5px solid var(--grey-lt); border-radius: 6px; font-family: 'Open Sans', sans-serif; font-size: .85rem; color: var(--black); background: var(--white); transition: border-color .15s; }

.form-control:focus { outline: none; border-color: var(--red); }

.form-control:disabled { background: var(--off-white); color: var(--grey-mid); }

.form-hint { font-size: .72rem; color: var(--grey-mid); margin-top: 4px; }

.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }

.form-actions { display: flex; gap: 12px; align-items: center; padding-top: 8px; border-top: 1px solid var(--grey-lt); margin-top: 24px; }

.form-check { display: flex; align-items: center; gap: 10px; }

.form-check input[type=checkbox] { width: 18px; height: 18px; accent-color: var(--red); cursor: pointer; }

.form-check label { font-size: .85rem; color: var(--black); cursor: pointer; }



.perm-grid { display: flex; flex-wrap: wrap; gap: 8px; }

.perm-chip { display: flex; align-items: center; gap: 8px; padding: 6px 14px; border-radius: 6px; border: 1.5px solid var(--grey-lt); background: var(--white); font-size: .75rem; font-weight: 600; cursor: pointer; transition: all .15s; user-select: none; }

.perm-chip.checked { background: var(--red-lt); border-color: var(--red); color: var(--red-dk); }



footer { margin: 0 40px; padding: 20px 0; border-top: 1px solid var(--grey-lt); font-size: .7rem; color: var(--grey-mid); text-transform: uppercase; letter-spacing: .1em; }



@media (max-width: 700px) {

  header, main, .flash-container, footer { padding-left: 16px; padding-right: 16px; }

  .form-row { grid-template-columns: 1fr; }

  .header-user .user-info { display: none; }

}



<?= $extra_css ?? '' ?>

</style>

</head>

<body>



<header>

  <a href="<?= BASE_URL ?>/hub.php" style="display:flex;align-items:center;gap:16px;text-decoration:none;flex:1;min-width:0;">

    <img class="header-logo"

         src="https://www.savannahexplorers.net/img/logo-savannah-explorers.png"

         alt="Savannah Explorers"

         onerror="this.style.display='none'">

    <div class="header-text">

      <h1>Savannah Explorers Hub</h1>

      <p>Operations &amp; Resources Centre</p>

    </div>

  </a>

  <?php if (!empty($user['id'])): ?>

  <div class="header-user">

    <div class="user-info">

      <div class="user-name"><?= e($user['full_name']) ?></div>

      <div class="user-role"><?= e($user['role_name']) ?></div>

    </div>

    <?php if (is_admin()): ?>

    <a href="<?= BASE_URL ?>/admin/users.php" class="btn-header btn-admin">⚙ Admin</a>

    <?php endif; ?>

    <a href="<?= BASE_URL ?>/logout.php" class="btn-header btn-logout">Logout</a>

  </div>

  <?php endif; ?>

</header>



<!-- SUB-NAV -->
<?php
$_cur = basename($_SERVER['PHP_SELF']);
$_in_leads = in_array($_cur, [
    'dashboard.php','requests.php','request_add.php','request_edit.php','request_view.php',
    'reports.php','agents.php','agencies.php',
    'requests_import_list.php','request_import_edit.php','reports_import.php',
    'booked.php','email_templates.php'
]);
if ($_in_leads): ?>
<nav style="background:var(--white);border-bottom:1px solid var(--grey-lt);padding:0 40px;display:flex;align-items:center;gap:4px;">
  <?php
  function _nav_link(string $href, string $label, bool $active): void {
      $style = $active
          ? 'color:#C0211B;border-bottom:3px solid #C0211B;'
          : 'color:#888;border-bottom:3px solid transparent;';
      echo '<a href="'.$href.'" style="font-size:.8rem;font-weight:600;text-decoration:none;padding:10px 16px;'.$style.'">'.$label.'</a>';
  }
  _nav_link('dashboard.php',              'Dashboard',   $_cur==='dashboard.php');
  _nav_link('requests.php',               'Requests',    in_array($_cur,['requests.php','request_add.php','request_edit.php','request_view.php']));
  _nav_link('reports.php',                'Reports',     $_cur==='reports.php');
  _nav_link('agents.php',                 'Agents',      $_cur==='agents.php');
  _nav_link('agencies.php',               'Agencies',    $_cur==='agencies.php');
  _nav_link('requests_import_list.php',   'Hist. Requests', in_array($_cur,['requests_import_list.php','request_import_edit.php']));
  _nav_link('reports_import.php',         'Hist. Reports',  $_cur==='reports_import.php');
  if (is_admin()) {
      echo '<span style="display:inline-block;width:1px;background:var(--grey-lt);margin:8px 6px;align-self:stretch;"></span>';
      _nav_link('booked.php',          'Booked',           $_cur==='booked.php');
      _nav_link('email_templates.php', 'Email Templates',  $_cur==='email_templates.php');
  }
  ?>
  <a href="logout.php" style="margin-left:auto;font-size:.8rem;font-weight:600;color:#aaa;text-decoration:none;padding:10px 16px;">Logout</a>
</nav>
<?php endif; ?>

<?php if (!empty($flash_msgs)): ?>

<div class="flash-container">

  <?php foreach ($flash_msgs as $msg): ?>

  <div class="flash <?= e($msg['type']) ?>"><?= e($msg['message']) ?></div>

  <?php endforeach; ?>

</div>

<?php endif; ?>