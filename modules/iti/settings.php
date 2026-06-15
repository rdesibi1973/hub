<?php
/**
 * settings.php — ITI Settings
 * Available to all logged-in users. Company / emergency / logo / T&C
 * sections are visible to everyone but editable by admins only.
 * Every user can edit their own profile (name, phone, photo, bio).
 */
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';

$db    = db();
$_cu   = current_user();
$admin = is_admin();
$tab   = $_GET['tab'] ?? 'profile';

// Upload directories (live on server only — not in repo)
$UPLOAD_BASE   = dirname(__FILE__) . '/uploads';
$LOGO_DIR      = $UPLOAD_BASE . '/logo';
$PROFILE_DIR   = $UPLOAD_BASE . '/profiles';
$UPLOAD_URL    = ITI_MODULE_URL . '/uploads';

// ── Helper: handle a single image upload, return public URL or null ──────────
function iti_handle_upload(array $file, string $destDir, string $urlBase, string $prefix): ?string {
    if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > 4 * 1024 * 1024) {                 // 4 MB ceiling
        iti_flash_set('error', 'Image too large (max 4 MB).');
        return null;
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info) { iti_flash_set('error', 'File is not a valid image.'); return null; }
    $ext = match ($info[2]) {
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
        default        => null,
    };
    if (!$ext) { iti_flash_set('error', 'Use JPG, PNG, GIF or WEBP.'); return null; }
    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
    $name = $prefix . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $destDir . '/' . $name)) {
        iti_flash_set('error', 'Could not save the uploaded file.');
        return null;
    }
    return $urlBase . '/' . $name;
}

// ── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['_action'] ?? '';

    // ---- Personal profile (any logged-in user, own row) ----
    if ($do === 'profile') {
        $full  = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $bio   = trim($_POST['bio'] ?? '');
        $photo = iti_handle_upload($_FILES['photo'] ?? [], $PROFILE_DIR, $UPLOAD_URL.'/profiles', 'u'.$_cu['id']);

        if ($photo !== null) {
            $db->prepare('UPDATE users SET full_name=?, phone=?, bio=?, photo_url=? WHERE id=?')
               ->execute([$full, $phone ?: null, $bio ?: null, $photo, $_cu['id']]);
        } else {
            $db->prepare('UPDATE users SET full_name=?, phone=?, bio=? WHERE id=?')
               ->execute([$full, $phone ?: null, $bio ?: null, $_cu['id']]);
        }
        // keep session name in sync
        $_SESSION['full_name'] = $full;
        iti_flash_set('success', 'Your profile has been updated.');
        iti_redirect('settings.php?tab=profile');
    }

    // ---- Company / contacts / emergency (admin only) ----
    if ($do === 'company' && $admin) {
        $logo = iti_handle_upload($_FILES['logo'] ?? [], $LOGO_DIR, $UPLOAD_URL.'/logo', 'logo');
        if ($logo !== null) iti_set_setting('logo_url', $logo);

        foreach ([
            'company_name','company_tagline','office_email','office_phone',
            'office_address','website','emergency_name','emergency_phone1','emergency_phone2'
        ] as $k) {
            if (array_key_exists($k, $_POST)) iti_set_setting($k, trim($_POST[$k]));
        }
        iti_flash_set('success', 'Company settings saved.');
        iti_redirect('settings.php?tab=company');
    }

    // ---- T&C standard library (admin only) ----
    if ($do === 'terms_save' && $admin) {
        $tid = (int)($_POST['id'] ?? 0);
        $f = [
            trim($_POST['version'] ?? ''),
            ($_POST['effective_date'] ?? '') ?: null,
            iti_sanitize_richtext($_POST['text_en'] ?? ''),
            iti_sanitize_richtext($_POST['text_it'] ?? ''),
            iti_sanitize_richtext($_POST['text_fr'] ?? ''),
            iti_sanitize_richtext($_POST['text_es'] ?? ''),
            iti_sanitize_richtext($_POST['text_de'] ?? ''),
            isset($_POST['is_active']) ? 1 : 0,
        ];
        if ($f[0] !== '') {
            if ($tid) {
                $db->prepare('UPDATE iti_terms_conditions SET name=?,effective_date=?,content_en=?,content_it=?,content_fr=?,content_es=?,content_de=?,is_active=? WHERE id=? AND program_id IS NULL')
                   ->execute([...$f, $tid]);
                iti_flash_set('success', 'T&C updated.');
            } else {
                $db->prepare('INSERT INTO iti_terms_conditions (name,effective_date,content_en,content_it,content_fr,content_es,content_de,is_active,program_id) VALUES (?,?,?,?,?,?,?,?,NULL)')
                   ->execute($f);
                iti_flash_set('success', 'New T&C created.');
            }
        } else {
            iti_flash_set('error', 'Name is required.');
        }
        iti_redirect('settings.php?tab=terms');
    }
    if ($do === 'terms_toggle' && $admin) {
        $tid = (int)($_POST['id'] ?? 0);
        $db->prepare('UPDATE iti_terms_conditions SET is_active = 1 - is_active WHERE id=? AND program_id IS NULL')->execute([$tid]);
        iti_redirect('settings.php?tab=terms');
    }
    if ($do === 'terms_duplicate' && $admin) {
        $tid = (int)($_POST['id'] ?? 0);
        $src = $db->prepare('SELECT * FROM iti_terms_conditions WHERE id=? AND program_id IS NULL');
        $src->execute([$tid]);
        $row = $src->fetch();
        if ($row) {
            // Name capped at varchar(20); keep room for the suffix
            $newname = substr($row['name'], 0, 43) . ' (copy)';
            $ins = $db->prepare(
                'INSERT INTO iti_terms_conditions
                   (name,effective_date,content_en,content_it,content_fr,content_es,content_de,is_active,program_id)
                 VALUES (?,?,?,?,?,?,?,0,NULL)'
            );
            $ins->execute([
                $newname, $row['effective_date'],
                $row['content_en'], $row['content_it'], $row['content_fr'],
                $row['content_es'], $row['content_de'],
            ]);
            $newid = (int)$db->lastInsertId();
            iti_flash_set('success', 'Duplicated — edit the copy below.');
            iti_redirect('settings.php?tab=terms&action=edit&id=' . $newid);
        }
        iti_flash_set('error', 'Could not duplicate.');
        iti_redirect('settings.php?tab=terms');
    }
}

// ── Load data ────────────────────────────────────────────────────────────────
$me = $db->prepare('SELECT full_name, email, phone, bio, photo_url FROM users WHERE id=?');
$me->execute([$_cu['id']]);
$me = $me->fetch() ?: [];

$S = iti_settings_all();
$get = fn(string $k, string $d = '') => ($S[$k] ?? '') !== '' ? $S[$k] : $d;

$terms_edit = null;
if ($tab === 'terms' && ($_GET['action'] ?? '') === 'edit' && ($eid = (int)($_GET['id'] ?? 0))) {
    $st = $db->prepare('SELECT * FROM iti_terms_conditions WHERE id=? AND program_id IS NULL');
    $st->execute([$eid]);
    $terms_edit = $st->fetch() ?: null;
}
$terms_list = [];
try { $terms_list = iti_get_terms(false); } catch (Exception $e) {}

$LANGS = ['en'=>'English','it'=>'Italiano','fr'=>'Français','es'=>'Español','de'=>'Deutsch'];

$page_title = 'Settings';
$extra_css = iti_extra_css() . '
.set-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:22px;}
.set-tab{padding:7px 16px;border-radius:6px;text-decoration:none;font-size:.8rem;font-weight:600;background:#f0f0ef;color:var(--grey-dk);}
.set-tab.active{background:var(--red);color:#fff;}
.ro-note{font-size:.72rem;color:var(--grey-mid);background:#fff8e1;border:1px solid #ffe0a3;border-radius:6px;padding:8px 12px;margin-bottom:16px;}
.kv-table{width:100%;border-collapse:collapse;font-size:.82rem;}
.kv-table th{text-align:left;padding:8px 10px;color:var(--grey-mid);font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;width:200px;vertical-align:top;}
.kv-table td{padding:8px 10px;}
.term-row td{padding:9px 12px;border-bottom:1px solid var(--grey-lt);font-size:.82rem;}
.lang-tabs{display:flex;gap:4px;margin-bottom:8px;flex-wrap:wrap;}
.lang-tab{padding:5px 12px;border-radius:6px;font-size:.74rem;font-weight:600;background:#f0f0ef;color:var(--grey-dk);cursor:pointer;border:none;}
.lang-tab.active{background:var(--grey-dk);color:#fff;}
.lang-pane{display:none;}
.lang-pane.active{display:block;}
.tc-quill{background:#fff;min-height:260px;}
.tc-quill .ql-editor{min-height:260px;font-family:\'Open Sans\',sans-serif;font-size:.85rem;}
.form-card textarea{width:100%;box-sizing:border-box;padding:10px 14px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:\'Open Sans\',sans-serif;font-size:.85rem;resize:vertical;}
.avatar{width:84px;height:84px;border-radius:50%;object-fit:cover;border:2px solid var(--grey-lt);background:#f0f0ef;}
';
include __DIR__ . '/../../includes/layout_header.php';
?>
<main>
<?php iti_nav('Settings'); ?>
<?php iti_flash_render(); ?>

<div class="set-tabs">
  <a href="settings.php?tab=profile" class="set-tab <?= $tab==='profile'?'active':'' ?>">👤 My Profile</a>
  <a href="settings.php?tab=company" class="set-tab <?= $tab==='company'?'active':'' ?>">🏢 Company &amp; Contacts</a>
  <a href="settings.php?tab=terms"   class="set-tab <?= $tab==='terms'?'active':''   ?>">📜 Terms &amp; Conditions</a>
</div>

<?php /* ════════════ PROFILE ════════════ */ if ($tab === 'profile'): ?>
<div class="page-header"><div><h2>My Profile</h2><div class="sub">These details are visible to your colleagues.</div></div></div>
<form method="POST" enctype="multipart/form-data" action="settings.php?tab=profile">
  <input type="hidden" name="_action" value="profile">
  <div class="form-card">
    <div class="form-grid" style="grid-template-columns:100px 1fr;align-items:start;">
      <div class="form-group" style="text-align:center;">
        <img class="avatar" src="<?= h($me['photo_url'] ?? '') ?: 'data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2284%22 height=%2284%22><rect width=%2284%22 height=%2284%22 fill=%22%23f0f0ef%22/></svg>' ?>" alt="">
      </div>
      <div>
        <div class="form-grid" style="grid-template-columns:1fr 1fr;">
          <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="full_name" value="<?= h($me['full_name'] ?? '') ?>" maxlength="150">
          </div>
          <div class="form-group">
            <label>Phone</label>
            <input type="text" name="phone" value="<?= h($me['phone'] ?? '') ?>" maxlength="40" placeholder="+255 ...">
          </div>
        </div>
        <div class="form-group">
          <label>Email <span style="color:var(--grey-mid);font-weight:400;">(managed by admin)</span></label>
          <input type="text" value="<?= h($me['email'] ?? '') ?>" disabled style="background:#f5f5f5;">
        </div>
        <div class="form-group">
          <label>Profile Photo</label>
          <input type="file" name="photo" accept="image/*">
          <div style="font-size:.7rem;color:var(--grey-mid);margin-top:4px;">JPG/PNG/WEBP, max 4 MB.</div>
        </div>
        <div class="form-group">
          <label>Biography</label>
          <textarea name="bio" rows="4" placeholder="A short bio shown alongside your name."><?= h($me['bio'] ?? '') ?></textarea>
        </div>
      </div>
    </div>
    <div style="margin-top:16px;"><button type="submit" class="btn btn-red">💾 Save Profile</button></div>
  </div>
</form>

<?php /* ════════════ COMPANY ════════════ */ elseif ($tab === 'company'): ?>
<div class="page-header"><div><h2>Company &amp; Contacts</h2><div class="sub">Used across itineraries, previews and exports.</div></div></div>
<?php if (!$admin): ?><div class="ro-note">🔒 Read-only. Only administrators can change company settings.</div><?php endif; ?>
<form method="POST" enctype="multipart/form-data" action="settings.php?tab=company">
  <input type="hidden" name="_action" value="company">
  <div class="form-card">
    <div class="form-section-title">Company</div>
    <div class="form-grid" style="grid-template-columns:90px 1fr;align-items:start;">
      <div class="form-group" style="text-align:center;">
        <?php if ($get('logo_url')): ?>
          <img src="<?= h($get('logo_url')) ?>" alt="logo" style="max-width:80px;max-height:80px;background:#333;padding:6px;border-radius:6px;">
        <?php else: ?>
          <div style="width:80px;height:60px;background:#f0f0ef;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:.65rem;color:var(--grey-mid);">no logo</div>
        <?php endif; ?>
      </div>
      <div>
        <div class="form-grid" style="grid-template-columns:1fr 1fr;">
          <div class="form-group">
            <label>Company Name</label>
            <input type="text" name="company_name" value="<?= h($get('company_name')) ?>" <?= $admin?'':'disabled' ?>>
          </div>
          <div class="form-group">
            <label>Tagline</label>
            <input type="text" name="company_tagline" value="<?= h($get('company_tagline')) ?>" <?= $admin?'':'disabled' ?>>
          </div>
        </div>
        <?php if ($admin): ?>
        <div class="form-group">
          <label>Logo</label>
          <input type="file" name="logo" accept="image/*">
          <div style="font-size:.7rem;color:var(--grey-mid);margin-top:4px;">Upload replaces the current logo. PNG with transparency recommended.</div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="form-section-title" style="margin-top:18px;">Office Contacts</div>
    <div class="form-grid" style="grid-template-columns:1fr 1fr;">
      <div class="form-group"><label>Email</label><input type="email" name="office_email" value="<?= h($get('office_email')) ?>" <?= $admin?'':'disabled' ?>></div>
      <div class="form-group"><label>Phone / WhatsApp</label><input type="text" name="office_phone" value="<?= h($get('office_phone')) ?>" <?= $admin?'':'disabled' ?>></div>
      <div class="form-group"><label>Address</label><input type="text" name="office_address" value="<?= h($get('office_address')) ?>" <?= $admin?'':'disabled' ?>></div>
      <div class="form-group"><label>Website</label><input type="text" name="website" value="<?= h($get('website')) ?>" <?= $admin?'':'disabled' ?>></div>
    </div>

    <div class="form-section-title" style="margin-top:18px;">Emergency 24/7</div>
    <div class="form-grid" style="grid-template-columns:1fr 1fr 1fr;">
      <div class="form-group"><label>Contact Name</label><input type="text" name="emergency_name" value="<?= h($get('emergency_name')) ?>" <?= $admin?'':'disabled' ?>></div>
      <div class="form-group"><label>Emergency Phone 1</label><input type="text" name="emergency_phone1" value="<?= h($get('emergency_phone1')) ?>" <?= $admin?'':'disabled' ?>></div>
      <div class="form-group"><label>Emergency Phone 2</label><input type="text" name="emergency_phone2" value="<?= h($get('emergency_phone2')) ?>" <?= $admin?'':'disabled' ?>></div>
    </div>

    <?php if ($admin): ?>
    <div style="margin-top:16px;"><button type="submit" class="btn btn-red">💾 Save Company Settings</button></div>
    <?php endif; ?>
  </div>
</form>

<?php /* ════════════ TERMS ════════════ */ else: ?>
<?php if (!$admin): ?>
  <div class="page-header"><div><h2>Terms &amp; Conditions</h2><div class="sub">Standard T&amp;C used in itineraries.</div></div></div>
  <div class="ro-note">🔒 Read-only. Only administrators can manage T&amp;C.</div>
  <div class="form-card" style="padding:0;overflow:hidden;">
    <table class="kv-table"><tbody>
    <?php foreach ($terms_list as $t): ?>
      <tr class="term-row"><td><strong><?= h($t['name']) ?></strong></td>
      <td><?= $t['effective_date'] ? date('d M Y', strtotime($t['effective_date'])) : '—' ?></td>
      <td><?= $t['is_active'] ? 'Active' : 'Inactive' ?></td>
      <?php if ($admin): ?>
      <td style="text-align:right;white-space:nowrap;">
        <a href="settings.php?tab=terms&action=edit&id=<?= (int)$t['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
        <form method="POST" action="settings.php?tab=terms" style="display:inline;">
          <input type="hidden" name="_action" value="terms_duplicate">
          <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <button type="submit" class="btn btn-outline btn-sm">Duplicate</button>
        </form>
      </td>
      <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$terms_list): ?><tr><td style="padding:24px;text-align:center;color:var(--grey-mid);">No T&C yet.</td></tr><?php endif; ?>
    </tbody></table>
  </div>

<?php elseif (($_GET['action'] ?? '') === 'add' || $terms_edit): ?>
  <!-- T&C editor -->
  <div class="page-header">
    <div><h2><?= $terms_edit ? 'Edit: '.h($terms_edit['name']) : 'New T&C' ?></h2></div>
    <a href="settings.php?tab=terms" class="btn btn-outline btn-sm">← Cancel</a>
  </div>
  <form method="POST" action="settings.php?tab=terms" id="terms-form">
    <input type="hidden" name="_action" value="terms_save">
    <input type="hidden" name="id" value="<?= (int)($terms_edit['id'] ?? 0) ?>">
    <div class="form-card">
      <div class="form-grid" style="grid-template-columns:1fr 200px 120px;">
        <div class="form-group"><label>Name <span style="color:var(--red)">*</span></label>
          <input type="text" name="version" value="<?= h($terms_edit['name'] ?? '') ?>" required maxlength="50" placeholder="e.g. Tanzania Standard"></div>
        <div class="form-group"><label>Effective Date</label>
          <input type="date" name="effective_date" value="<?= h($terms_edit['effective_date'] ?? '') ?>"></div>
        <div class="form-group"><label>Status</label>
          <label class="check-label" style="margin-top:8px;"><input type="checkbox" name="is_active" value="1" <?= ($terms_edit['is_active'] ?? 1)?'checked':'' ?>> Active</label></div>
      </div>

      <div class="form-section-title" style="margin-top:14px;">Text (all languages)</div>
      <div class="lang-tabs">
        <?php $i=0; foreach ($LANGS as $code=>$name): ?>
          <button type="button" class="lang-tab <?= $i===0?'active':'' ?>" data-lang="<?= $code ?>"><?= $name ?></button>
        <?php $i++; endforeach; ?>
      </div>
      <?php $i=0; foreach ($LANGS as $code=>$name): ?>
        <div class="lang-pane <?= $i===0?'active':'' ?>" data-lang="<?= $code ?>">
          <div class="tc-quill" id="quill_<?= $code ?>"><?= $terms_edit['content_'.$code] ?? '' ?></div>
          <textarea name="text_<?= $code ?>" id="ta_<?= $code ?>" style="display:none;"></textarea>
        </div>
      <?php $i++; endforeach; ?>

      <div style="margin-top:16px;"><button type="submit" class="btn btn-red">💾 Save</button></div>
    </div>
  </form>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.snow.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.min.js"></script>
  <script>
  (function(){
    // Language tab switching
    var tabs = document.querySelectorAll('.lang-tab');
    var panes = document.querySelectorAll('.lang-pane');
    for (var i=0;i<tabs.length;i++){
      tabs[i].addEventListener('click', function(){
        var lang = this.getAttribute('data-lang');
        for (var j=0;j<tabs.length;j++) tabs[j].classList.remove('active');
        this.classList.add('active');
        for (var k=0;k<panes.length;k++){
          panes[k].classList.toggle('active', panes[k].getAttribute('data-lang')===lang);
        }
      });
    }

    // One Quill editor per language
    var LANGS = <?= json_encode(array_keys($LANGS)) ?>;
    var editors = {};
    var toolbar = [
      ['bold','italic','underline'],
      [{'list':'ordered'},{'list':'bullet'}],
      ['link','clean'],
      [{'color':[]},{'background':[]}],
      [{'align':[]}]
    ];
    for (var n=0;n<LANGS.length;n++){
      var code = LANGS[n];
      editors[code] = new Quill('#quill_'+code, { theme:'snow', modules:{ toolbar: toolbar } });
    }

    // Sync each editor into its hidden textarea before submit
    var form = document.getElementById('terms-form') || document.querySelector('form');
    if (form) {
      form.addEventListener('submit', function(){
        for (var n=0;n<LANGS.length;n++){
          var code = LANGS[n];
          var html = editors[code].root.innerHTML;
          // Treat an empty editor as truly empty
          if (html === '<p><br></p>') html = '';
          document.getElementById('ta_'+code).value = html;
        }
      });
    }
  })();
  </script>

<?php else: ?>
  <!-- T&C list -->
  <div class="page-header">
    <div><h2>Terms &amp; Conditions</h2><div class="sub"><?= count($terms_list) ?> standard T&amp;C</div></div>
    <a href="settings.php?tab=terms&action=add" class="btn btn-red">+ New T&amp;C</a>
  </div>
  <div class="form-card" style="padding:0;overflow:hidden;">
    <table class="kv-table">
      <thead><tr>
        <th style="width:auto;">Name</th><th style="width:140px;">Effective</th>
        <th style="width:100px;">Status</th><th style="width:160px;"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($terms_list as $t): ?>
        <tr class="term-row" style="<?= $t['is_active']?'':'opacity:.55;' ?>">
          <td><strong><?= h($t['name']) ?></strong></td>
          <td><?= $t['effective_date'] ? date('d M Y', strtotime($t['effective_date'])) : '—' ?></td>
          <td><?= $t['is_active'] ? '<span style="color:var(--green);font-weight:700;">Active</span>' : '<span style="color:var(--grey-mid);">Inactive</span>' ?></td>
          <td style="white-space:nowrap;">
            <a href="settings.php?tab=terms&action=edit&id=<?= $t['id'] ?>" style="font-size:.78rem;color:var(--green);text-decoration:none;margin-right:10px;">Edit</a>
            <form method="POST" action="settings.php?tab=terms" style="display:inline;">
              <input type="hidden" name="_action" value="terms_toggle">
              <input type="hidden" name="id" value="<?= $t['id'] ?>">
              <button type="submit" style="background:none;border:none;cursor:pointer;font-size:.78rem;color:var(--grey-dk);text-decoration:underline;padding:0;">
                <?= $t['is_active'] ? 'Deactivate' : 'Activate' ?>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$terms_list): ?>
        <tr><td colspan="4" style="text-align:center;padding:28px;color:var(--grey-mid);">No T&C yet. Create the first one.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
<?php endif; /* inner terms admin if/elseif/else */ ?>
<?php endif; /* outer tab if/elseif/else */ ?>

</main>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
