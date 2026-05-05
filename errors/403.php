<?php
http_response_code(403);
$page_title = 'Access Denied';
include __DIR__ . '/../includes/layout_header.php';
?>
<main>
  <div style="text-align:center;padding:80px 40px;">
    <div style="font-size:3rem;margin-bottom:16px;">🔒</div>
    <h2 style="font-family:'Merriweather',serif;color:var(--red-dk);margin-bottom:12px;">Access Denied</h2>
    <p style="color:var(--grey-mid);">You don't have permission to access this page.<br>Contact your administrator if you need access.</p>
    <a href="<?= BASE_URL ?>/hub.php" class="btn btn-secondary" style="margin-top:24px;">← Back to Hub</a>
  </div>
</main>
<?php include __DIR__ . '/../includes/layout_footer.php'; ?>
