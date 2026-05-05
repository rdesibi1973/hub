<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$_cu = current_user();
$is_admin_or_manager = in_array($_cu['role_name'], ['admin', 'manager']);

$page_title = 'Hub — Savannah Explorers';
include __DIR__ . '/includes/layout_header.php';
?>

<main>

  <!-- ══ CORE OPERATIONS ══ -->
  <div class="section-label">Core Operations</div>
  <div class="primary-grid">

    <?php if (has_permission('operations')): ?>
    <a class="primary-card" href="<?= BASE_URL ?>/modules/operations/index.php">
      <div class="pc-icon">✈️</div>
      <div class="pc-text"><h3>Operations Hub</h3><p>Arrivals &amp; Departures</p></div>
      <span class="pc-arrow">↗</span>
    </a>
    <?php endif; ?>

    <?php if (has_permission('leave')): ?>
    <a class="primary-card" href="<?= BASE_URL ?>/modules/leave/index.php">
      <div class="pc-icon">📅</div>
      <div class="pc-text"><h3>Leave Calendar</h3><p>Staff leave &amp; availability</p></div>
      <span class="pc-arrow">↗</span>
    </a>
    <?php endif; ?>

    <?php if (has_permission('invoices')): ?>
    <a class="primary-card" href="<?= BASE_URL ?>/modules/invoices/invoices.php">
      <div class="pc-icon">🧾</div>
      <div class="pc-text"><h3>Invoices</h3><p>Invoice &amp; payment management</p></div>
      <span class="pc-arrow">↗</span>
    </a>
    <?php endif; ?>

    <?php if (has_permission('leads') && !$is_admin_or_manager): ?>
    <a class="primary-card" href="<?= BASE_URL ?>/modules/leads/requests.php">
      <div class="pc-icon">📋</div>
      <div class="pc-text"><h3>My Requests</h3><p>Your assigned requests</p></div>
      <span class="pc-arrow">↗</span>
    </a>
    <?php endif; ?>

    <a class="primary-card pc-green" href="https://www.savannahexplorers.net/" target="_blank">
      <div class="pc-icon pc-logo">
        <img src="https://www.savannahexplorers.net/img/logo-savannah-explorers.png" alt="Savannah Explorers">
      </div>
      <div class="pc-text"><h3>Website — EN</h3><p>savannahexplorers.net</p></div>
      <span class="pc-arrow">↗</span>
    </a>

    <a class="primary-card pc-green" href="https://www.savannahexplorers.com/" target="_blank">
      <div class="pc-icon pc-logo">
        <img src="https://www.savannahexplorers.net/img/logo-savannah-explorers.png" alt="Savannah Explorers">
      </div>
      <div class="pc-text"><h3>Website — IT</h3><p>savannahexplorers.com</p></div>
      <span class="pc-arrow">↗</span>
    </a>

  </div>

  <?php if ($is_admin_or_manager): ?>
  <!-- ══ SALES & MARKETING ══ -->
  <div class="section-label dot-green group-spacer">Sales &amp; Marketing</div>
  <div class="links-grid">

    <?php if (has_permission('leads')): ?>
    <a class="link-card lc-green" href="<?= BASE_URL ?>/modules/leads/dashboard.php">
      <div class="lc-icon" style="background:#fff;border:1px solid var(--grey-lt);padding:3px;">
        <img src="https://www.savannahexplorers.net/img/logo-savannah-explorers.png" alt="Lead Tracker">
      </div>
      <div><div class="lc-label">Lead Tracker</div><div class="lc-sub">hub.savannahexplorers.com</div></div>
    </a>
    <?php endif; ?>

    <a class="link-card lc-orange"
       href="https://app.hubspot.com/contacts/7793801/objects/0-5/views/all/list?prefetch="
       target="_blank">
      <div class="lc-icon"><img src="https://www.google.com/s2/favicons?domain=hubspot.com&sz=64" alt="HubSpot"></div>
      <div><div class="lc-label">HubSpot iBot</div><div class="lc-sub">HubSpot CRM</div></div>
    </a>

    <a class="link-card lc-pink" href="https://www.instagram.com/savannahexplorers/" target="_blank">
      <div class="lc-icon" style="background:none;padding:0;">
        <svg viewBox="0 0 36 36" xmlns="http://www.w3.org/2000/svg" width="36" height="36">
          <defs><radialGradient id="ig1" cx="30%" cy="107%" r="120%">
            <stop offset="0%" stop-color="#FCAF45"/><stop offset="50%" stop-color="#F56040"/>
            <stop offset="70%" stop-color="#C13584"/><stop offset="100%" stop-color="#833AB4"/>
          </radialGradient></defs>
          <rect width="36" height="36" rx="8" fill="url(#ig1)"/>
          <rect x="8" y="8" width="20" height="20" rx="5.5" fill="none" stroke="white" stroke-width="2"/>
          <circle cx="18" cy="18" r="5" fill="none" stroke="white" stroke-width="2"/>
          <circle cx="24" cy="12" r="1.5" fill="white"/>
        </svg>
      </div>
      <div><div class="lc-label">Instagram</div><div class="lc-sub">@savannahexplorers</div></div>
    </a>

    <a class="link-card lc-blue" href="https://www.facebook.com/SavannahExplorersLtd" target="_blank">
      <div class="lc-icon" style="background:none;padding:0;">
        <svg viewBox="0 0 36 36" xmlns="http://www.w3.org/2000/svg" width="36" height="36">
          <rect width="36" height="36" rx="8" fill="#1877F2"/>
          <path fill="white" d="M23 18h-3v-2.5c0-.8.5-1 .9-1H23V11.5l-3-.01c-3.3 0-4 2.5-4 4V18h-2v3h2v8h4v-8h2.7L23 18z"/>
        </svg>
      </div>
      <div><div class="lc-label">Facebook</div><div class="lc-sub">Savannah Explorers Ltd</div></div>
    </a>

    <?php if (has_permission('leads')): ?>
    <a class="link-card lc-green"
       href="https://docs.google.com/spreadsheets/d/1wR6rCaN4t9UWOkIRdrfakp6ho7G_JyiA/edit?gid=1549059665#gid=1549059665"
       target="_blank">
      <div class="lc-icon"><img src="https://ssl.gstatic.com/docs/spreadsheets/favicon3.ico" alt="Sheets"></div>
      <div><div class="lc-label">Commissions</div><div class="lc-sub">Google Sheets</div></div>
    </a>
    <?php endif; ?>

    <a class="link-card lc-pink" href="https://www.instagram.com/theorangicollection/" target="_blank">
      <div class="lc-icon" style="background:none;padding:0;">
        <svg viewBox="0 0 36 36" xmlns="http://www.w3.org/2000/svg" width="36" height="36">
          <defs><radialGradient id="ig2" cx="30%" cy="107%" r="120%">
            <stop offset="0%" stop-color="#FCAF45"/><stop offset="50%" stop-color="#F56040"/>
            <stop offset="70%" stop-color="#C13584"/><stop offset="100%" stop-color="#833AB4"/>
          </radialGradient></defs>
          <rect width="36" height="36" rx="8" fill="url(#ig2)"/>
          <rect x="8" y="8" width="20" height="20" rx="5.5" fill="none" stroke="white" stroke-width="2"/>
          <circle cx="18" cy="18" r="5" fill="none" stroke="white" stroke-width="2"/>
          <circle cx="24" cy="12" r="1.5" fill="white"/>
        </svg>
      </div>
      <div><div class="lc-label">Instagram Orangi</div><div class="lc-sub">@theorangicollection</div></div>
    </a>

    <a class="link-card lc-blue" href="https://www.facebook.com/profile.php?id=61574801976890" target="_blank">
      <div class="lc-icon" style="background:none;padding:0;">
        <svg viewBox="0 0 36 36" xmlns="http://www.w3.org/2000/svg" width="36" height="36">
          <rect width="36" height="36" rx="8" fill="#1877F2"/>
          <path fill="white" d="M23 18h-3v-2.5c0-.8.5-1 .9-1H23V11.5l-3-.01c-3.3 0-4 2.5-4 4V18h-2v3h2v8h4v-8h2.7L23 18z"/>
        </svg>
      </div>
      <div><div class="lc-label">Facebook Orangi</div><div class="lc-sub">The Orangi Collection</div></div>
    </a>

  </div>
  <?php endif; // admin_or_manager: Sales & Marketing ?>

  <?php if ($is_admin_or_manager): ?>
  <!-- ══ REVIEW PLATFORMS ══ -->
  <div class="section-label dot-amber group-spacer">Review Platforms</div>
  <div class="links-grid">

    <a class="link-card lc-teal" href="https://www.tripadvisor.com/Owners" target="_blank">
      <div class="lc-icon" style="background:#fff;padding:3px;">
        <img src="https://www.google.com/s2/favicons?domain=tripadvisor.com&sz=64" alt="TripAdvisor">
      </div>
      <div><div class="lc-label">TripAdvisor</div><div class="lc-sub">Owner Portal</div></div>
    </a>

    <a class="link-card lc-amber" href="https://operators.safaribookings.com/index/login" target="_blank">
      <div class="lc-icon" style="background:#fff;padding:3px;">
        <img src="https://www.google.com/s2/favicons?domain=safaribookings.com&sz=64" alt="Safari Bookings">
      </div>
      <div><div class="lc-label">Safari Bookings</div><div class="lc-sub">Operator Login</div></div>
    </a>

    <a class="link-card lc-blue" href="https://business.google.com/locations" target="_blank">
      <div class="lc-icon" style="background:none;padding:0;">
        <svg viewBox="0 0 36 36" xmlns="http://www.w3.org/2000/svg" width="36" height="36">
          <rect width="36" height="36" rx="8" fill="#fff" stroke="#E8E8E8" stroke-width="1"/>
          <path d="M26.5 18.2c0-.6-.1-1.2-.2-1.7H18v3.2h4.8c-.2 1.1-.9 2-1.8 2.6v2.2h3c1.7-1.6 2.7-4 2.5-6.3z" fill="#4285F4"/>
          <path d="M18 27c2.4 0 4.5-.8 6-2.2l-2.9-2.2c-.8.6-1.9.9-3.1.9-2.4 0-4.4-1.6-5.1-3.8H9.8v2.3C11.3 24.9 14.4 27 18 27z" fill="#34A853"/>
          <path d="M12.9 19.7c-.2-.6-.3-1.1-.3-1.7s.1-1.2.3-1.7v-2.3H9.8C9.1 15.5 8.7 16.7 8.7 18s.4 2.5 1.1 3.9l3.1-2.2z" fill="#FBBC05"/>
          <path d="M18 12.5c1.3 0 2.5.5 3.4 1.3l2.6-2.6C22.5 9.7 20.4 9 18 9c-3.6 0-6.7 2.1-8.2 5.1l3.1 2.3c.7-2.2 2.7-3.9 5.1-3.9z" fill="#EA4335"/>
        </svg>
      </div>
      <div><div class="lc-label">Google Business</div><div class="lc-sub">business.google.com</div></div>
    </a>

  </div>
  <?php endif; // admin_or_manager: Review Platforms ?>

  <!-- ══ TOOLS ══ -->
  <div class="section-label dot-navy group-spacer">Tools</div>
  <div class="links-grid">

    <a class="link-card lc-navy" href="https://operators.intermundial.es/" target="_blank">
      <div class="lc-icon emoji">🧭</div>
      <div><div class="lc-label">Intermundial</div><div class="lc-sub">Operator Portal</div></div>
    </a>

    <a class="link-card lc-grey" href="https://www.calculator.net/age-calculator.html" target="_blank">
      <div class="lc-icon emoji">🎂</div>
      <div><div class="lc-label">Age Calculator</div><div class="lc-sub">calculator.net</div></div>
    </a>

    <a class="link-card lc-orange" href="https://invoice.zoho.com/app/800553733#/home/dashboard" target="_blank">
      <div class="lc-icon" style="background:none;padding:0;">
        <svg viewBox="0 0 36 36" xmlns="http://www.w3.org/2000/svg" width="36" height="36">
          <rect width="36" height="36" rx="8" fill="#E42527"/>
          <text x="18" y="24" font-family="Arial,sans-serif" font-size="13" font-weight="900" fill="white" text-anchor="middle" letter-spacing="-0.5">zoho</text>
        </svg>
      </div>
      <div><div class="lc-label">Zoho</div><div class="lc-sub">Invoice Dashboard</div></div>
    </a>

    <a class="link-card lc-navy" href="https://www.xe.com/currencyconverter/" target="_blank">
      <div class="lc-icon" style="background:none;padding:0;">
        <svg viewBox="0 0 36 36" xmlns="http://www.w3.org/2000/svg" width="36" height="36">
          <rect width="36" height="36" rx="8" fill="#0C3B6E"/>
          <text x="18" y="25" font-family="Arial,sans-serif" font-size="15" font-weight="900" fill="white" text-anchor="middle" letter-spacing="0.5">XE</text>
        </svg>
      </div>
      <div><div class="lc-label">Currency Converter</div><div class="lc-sub">xe.com</div></div>
    </a>

    <a class="link-card lc-green" href="https://www.savannahexplorers.net/blog" target="_blank">
      <div class="lc-icon" style="background:#fff;border:1px solid var(--grey-lt);padding:4px;width:56px;height:36px;border-radius:6px;flex-shrink:0;">
        <img src="https://www.savannahexplorers.net/img/logo-savannah-explorers.png" alt="Blog EN" style="width:100%;height:100%;object-fit:contain;">
      </div>
      <div><div class="lc-label">EN Blog</div><div class="lc-sub">savannahexplorers.net</div></div>
    </a>

    <a class="link-card lc-green" href="https://www.savannahexplorers.com/blog" target="_blank">
      <div class="lc-icon" style="background:#fff;border:1px solid var(--grey-lt);padding:4px;width:56px;height:36px;border-radius:6px;flex-shrink:0;">
        <img src="https://www.savannahexplorers.net/img/logo-savannah-explorers.png" alt="Blog IT" style="width:100%;height:100%;object-fit:contain;">
      </div>
      <div><div class="lc-label">IT Blog</div><div class="lc-sub">savannahexplorers.com</div></div>
    </a>

    <?php if ($is_admin_or_manager): ?>
    <a class="link-card lc-blue" href="https://www.bluehost.com/my-account/login" target="_blank">
      <div class="lc-icon" style="background:none;padding:0;">
        <svg viewBox="0 0 36 36" xmlns="http://www.w3.org/2000/svg" width="36" height="36">
          <rect width="36" height="36" rx="8" fill="#003082"/>
          <text x="18" y="16" font-family="Arial,sans-serif" font-size="7.5" font-weight="800" fill="white" text-anchor="middle">BLUE</text>
          <text x="18" y="26" font-family="Arial,sans-serif" font-size="7.5" font-weight="800" fill="#4A9EFF" text-anchor="middle">HOST</text>
        </svg>
      </div>
      <div><div class="lc-label">BlueHost</div><div class="lc-sub">Account Login</div></div>
    </a>

    <a class="link-card lc-brevo" href="https://app.brevo.com/" target="_blank">
      <div class="lc-icon" style="background:none;padding:0;">
        <svg viewBox="0 0 36 36" xmlns="http://www.w3.org/2000/svg" width="36" height="36">
          <rect width="36" height="36" rx="8" fill="#0092FF"/>
          <text x="18" y="23" font-family="Arial,sans-serif" font-size="11" font-weight="900" fill="white" text-anchor="middle" letter-spacing="-0.3">brevo</text>
        </svg>
      </div>
      <div><div class="lc-label">Brevo</div><div class="lc-sub">app.brevo.com</div></div>
    </a>
    <?php endif; // admin_or_manager: BlueHost + Brevo ?>

  </div>

  <?php if ($is_admin_or_manager): ?>
  <!-- ══ DRIVES ══ -->
  <div class="section-label dot-drive group-spacer">Drives</div>
  <div class="links-grid">

    <a class="link-card lc-gdrive" href="https://drive.google.com/drive/u/0/folders/1eAL6qkcEnCAtfgWpKq_jt2H99N26s6hc" target="_blank">
      <div class="lc-icon" style="background:none;padding:0;">
        <svg viewBox="0 0 36 36" xmlns="http://www.w3.org/2000/svg" width="36" height="36">
          <rect width="36" height="36" rx="8" fill="#fff" stroke="#E8E8E8" stroke-width="1"/>
          <polygon points="18,7 29,26 23.5,26" fill="#FBBC04"/>
          <polygon points="18,7 12.5,26 7,26" fill="#0F9D58"/>
          <polygon points="12.5,26 23.5,26 29,26 7,26" fill="#4285F4"/>
        </svg>
      </div>
      <div><div class="lc-label">Drive MyPressLab</div><div class="lc-sub">Google Drive</div></div>
    </a>

    <a class="link-card lc-gdrive" href="https://drive.google.com/drive/u/0/folders/11_RcXCfmmfDMOh8y9XwYPwGEvy65EjIp" target="_blank">
      <div class="lc-icon" style="background:none;padding:0;">
        <svg viewBox="0 0 36 36" xmlns="http://www.w3.org/2000/svg" width="36" height="36">
          <rect width="36" height="36" rx="8" fill="#fff" stroke="#E8E8E8" stroke-width="1"/>
          <polygon points="18,7 29,26 23.5,26" fill="#FBBC04"/>
          <polygon points="18,7 12.5,26 7,26" fill="#0F9D58"/>
          <polygon points="12.5,26 23.5,26 29,26 7,26" fill="#4285F4"/>
        </svg>
      </div>
      <div><div class="lc-label">Drive Savannah</div><div class="lc-sub">Google Drive</div></div>
    </a>

    <a class="link-card lc-dropbox" href="https://www.dropbox.com/home" target="_blank">
      <div class="lc-icon" style="background:none;padding:0;">
        <svg viewBox="0 0 36 36" xmlns="http://www.w3.org/2000/svg" width="36" height="36">
          <rect width="36" height="36" rx="8" fill="#0061FF"/>
          <polygon points="18,8 26,13 18,18 10,13" fill="white"/>
          <polygon points="10,18.5 18,23.5 26,18.5 18,13.5" fill="white" opacity="0.85"/>
          <polygon points="13,25 18,22 23,25 18,28" fill="white" opacity="0.9"/>
        </svg>
      </div>
      <div><div class="lc-label">Dropbox</div><div class="lc-sub">dropbox.com</div></div>
    </a>

  </div>
  <?php endif; // admin_or_manager: Drives ?>

</main>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
