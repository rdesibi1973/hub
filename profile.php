<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/signature_helper.php';
require_login();

$me  = current_user();
$uid = (int)$me['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    [$ok, $msg] = handle_signature_upload($uid, $_FILES, $_POST);
    if ($msg !== '') flash($msg, $ok ? 'success' : 'error');
    redirect(BASE_URL . '/profile.php');
}

$hasSig  = user_has_signature($uid);
$sigHtml = $hasSig ? get_user_signature_html($uid) : '';

$page_title = 'My Profile';
include __DIR__ . '/includes/layout_header.php';
?>

<main>
  <div class="page-title">My Profile</div>

  <div class="card" style="max-width:820px;">
    <div class="card-header"><h2>Account</h2></div>
    <div style="padding:14px 18px;font-size:.9rem;line-height:1.8;">
      <strong>Name:</strong> <?= e((string)$me['full_name']) ?><br>
      <strong>Username:</strong> <?= e((string)$me['username']) ?><br>
      <strong>Role:</strong> <?= e((string)$me['role_name']) ?>
    </div>
  </div>

  <div class="card" style="max-width:820px;margin-top:20px;">
    <div class="card-header"><h2>Email Signature</h2></div>
    <div style="padding:16px 18px;">
      <p style="font-size:.86rem;color:var(--grey-mid);margin-top:0;">
        Upload an HTML signature file (the same file you use in Thunderbird).
        It will be appended to emails you send from Hub. Images must use full
        public URLs (e.g. https://savannahexplorers.com/img/…) to display in email clients.
      </p>

      <?php if ($hasSig): ?>
        <div style="margin:14px 0;">
          <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--grey-mid);margin-bottom:6px;">Current signature preview</div>
          <div style="border:1px solid var(--grey-lt);border-radius:8px;padding:14px;background:#fff;">
            <?= $sigHtml /* already sanitized on upload */ ?>
          </div>
        </div>
      <?php else: ?>
        <p style="font-size:.86rem;color:var(--grey-mid);font-style:italic;">No signature uploaded yet.</p>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data" style="margin-top:14px;">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div style="display:flex;flex-direction:column;gap:12px;">
          <div>
            <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:5px;">
              <?= $hasSig ? 'Replace signature (.html)' : 'Upload signature (.html)' ?>
            </label>
            <input type="file" name="signature_file" accept=".html,.htm">
          </div>
          <div style="display:flex;gap:10px;align-items:center;">
            <button type="submit" class="btn btn-primary btn-sm">Save</button>
            <?php if ($hasSig): ?>
              <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;color:var(--red-dk);cursor:pointer;">
                <input type="checkbox" name="delete_signature" value="1"> Remove current signature
              </label>
            <?php endif; ?>
          </div>
        </div>
      </form>
    </div>
  </div>
</main>

<?php include __DIR__ . '/includes/layout_footer.php'; ?>
