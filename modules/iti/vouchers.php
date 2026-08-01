<?php
/**
 * modules/iti/vouchers.php
 *
 * Voucher generator (Strada A): upload a WeTu Word programme (.docx) + the
 * internal Excel calc (.xlsx), review/edit traveller names, dietary notes and
 * per-lodge details, then download the vouchers as PDF or Word.
 *
 * Flow (single page, driven by `step`):
 *   1. upload   — pick the two files (+ sheet if the Excel has several).
 *   2. review   — editable form pre-filled from the parsed model.
 *   3. generate — stream the PDF (Dompdf) or Word (PhpWord) download.
 *
 * Uploaded files are stashed under a per-session token dir so the review can
 * re-parse (e.g. sheet change) and the final render works from the same source.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/includes/iti_functions.php';
require_once __DIR__ . '/includes/voucher_lib.php';

$db  = db();
$_cu = current_user();

function voucher_tmp_base(): string
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hub_vouchers';
    if (!is_dir($base)) @mkdir($base, 0700, true);
    return $base;
}

/** Remove token dirs older than a day so temp storage doesn't accumulate. */
function voucher_gc(): void
{
    $base = voucher_tmp_base();
    foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (filemtime($dir) < time() - 86400) {
            foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
            @rmdir($dir);
        }
    }
}

function voucher_token_dir(string $token): string
{
    $token = preg_replace('/[^a-f0-9]/', '', $token);
    return voucher_tmp_base() . DIRECTORY_SEPARATOR . $token;
}

$step  = $_REQUEST['step'] ?? 'upload';
$error = '';

// ─────────────────────────────────────────────────────────────────────────────
//  Handle uploads → prepare token dir, then fall through to review.
// ─────────────────────────────────────────────────────────────────────────────
$token       = preg_replace('/[^a-f0-9]/', '', $_REQUEST['token'] ?? '');
$origDocx    = $_POST['orig_docx'] ?? '';
$sheet       = $_POST['sheet'] ?? ($_GET['sheet'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($step === 'review' || $step === 'generate')) {
    voucher_gc();

    // New upload? Validate + store.
    if (!empty($_FILES['docx']['name']) || !empty($_FILES['xlsx']['name'])) {
        $token = bin2hex(random_bytes(8));
        $dir   = voucher_token_dir($token);
        @mkdir($dir, 0700, true);

        $checks = [
            'docx' => ['ext' => 'docx', 'dest' => $dir . '/prog.docx'],
            'xlsx' => ['ext' => 'xlsx', 'dest' => $dir . '/calc.xlsx'],
        ];
        foreach ($checks as $field => $c) {
            if (empty($_FILES[$field]['tmp_name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) {
                $error = 'Please choose both a Word (.docx) and an Excel (.xlsx) file.';
                break;
            }
            $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
            if ($ext !== $c['ext']) {
                $error = 'Wrong file type: the ' . strtoupper($c['ext']) . ' slot needs a .' . $c['ext'] . ' file.';
                break;
            }
            if ($_FILES[$field]['size'] > 20 * 1024 * 1024) {
                $error = 'Files must be under 20 MB.';
                break;
            }
            if (!move_uploaded_file($_FILES[$field]['tmp_name'], $c['dest'])) {
                $error = 'Could not store the uploaded file. Try again.';
                break;
            }
        }
        if (!$error) {
            $origDocx = $_FILES['docx']['name'];
            file_put_contents($dir . '/orig.txt', $origDocx);
        }
    }

    if (!$error && $token) {
        $dir = voucher_token_dir($token);
        if ($origDocx === '' && is_file($dir . '/orig.txt')) {
            $origDocx = trim((string)file_get_contents($dir . '/orig.txt'));
        }
        if (!is_file($dir . '/prog.docx') || !is_file($dir . '/calc.xlsx')) {
            $error = 'Your upload session expired. Please upload the files again.';
            $step  = 'upload';
        }
    } elseif (!$error) {
        $error = 'Please upload the files.';
        $step  = 'upload';
    }
}

// ─────────────────────────────────────────────────────────────────────────────
//  GENERATE — stream PDF or Word.
// ─────────────────────────────────────────────────────────────────────────────
if ($step === 'generate' && !$error) {
    $dir    = voucher_token_dir($token);
    $format = ($_POST['format'] ?? 'pdf') === 'word' ? 'word' : 'pdf';

    try {
        $model = voucher_build_model($dir . '/prog.docx', $dir . '/calc.xlsx', $sheet ?: null, $origDocx);
        voucher_apply_directory($db, $model);
        voucher_overlay_edits($model, $_POST);
    } catch (Throwable $e) {
        $error = 'Could not read the files: ' . $e->getMessage();
        $step  = 'review';
    }

    if (!$error) {
        // Optionally remember filled-in GPS/contacts in the lodge directory.
        if (!empty($_POST['save_directory'])) {
            voucher_save_directory($db, $model);
        }

        $slug  = preg_replace('/[^A-Za-z0-9._-]+/', '_', $model['ref']);
        $slug  = trim($slug, '_') ?: 'vouchers';
        $fname = $slug . '-vouchers';

        $vendor = __DIR__ . '/../../vendor/autoload.php';
        if (!is_file($vendor)) { http_response_code(500); exit('vendor/ not installed on server.'); }
        require_once $vendor;

        if ($format === 'word') {
            $phpWord = voucher_render_word($model);
            header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            header('Content-Disposition: attachment; filename="' . $fname . '.docx"');
            header('Cache-Control: max-age=0');
            \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save('php://output');
            exit;
        }

        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml(voucher_render_html($model), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $fname . '.pdf"');
        echo $dompdf->output();
        exit;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
//  Overlay helpers (edits from the review form onto a freshly parsed model).
// ─────────────────────────────────────────────────────────────────────────────
function voucher_overlay_edits(array &$model, array $post): void
{
    if (isset($post['adults']))  $model['adults']  = max(1, (int)$post['adults']);
    if (isset($post['dietary'])) $model['dietary'] = trim((string)$post['dietary']);
    if (isset($post['ref']) && trim($post['ref']) !== '') $model['ref'] = trim($post['ref']);

    if (isset($post['tr_title']) && is_array($post['tr_title'])) {
        $travellers = [];
        foreach ($post['tr_title'] as $i => $title) {
            $name = trim($post['tr_name'][$i] ?? '');
            if ($name === '') continue;
            $travellers[] = [
                'title'   => trim((string)$title),
                'name'    => $name,
                'country' => trim($post['tr_country'][$i] ?? ''),
            ];
        }
        $model['travellers'] = $travellers;
        if (!isset($post['adults']) || $post['adults'] === '') $model['adults'] = max(1, count($travellers));
    }

    foreach (['provider_name', 'provider_phone', 'provider_address', 'gps', 'room', 'meal'] as $f) {
        $key = 'acc_' . $f;
        if (!isset($post[$key]) || !is_array($post[$key])) continue;
        foreach ($post[$key] as $i => $val) {
            if (!isset($model['accommodations'][$i])) continue;
            $model['accommodations'][$i][$f] = trim((string)$val);
            if ($f === 'gps') $model['accommodations'][$i]['gps_missing'] = (trim((string)$val) === '');
        }
    }

    // Transfers (+ their internal flight) — rebuilt from the editable rows.
    if (isset($post['xf_from']) && is_array($post['xf_from'])) {
        $transfers = [];
        foreach ($post['xf_from'] as $i => $from) {
            $from = trim((string)$from);
            $to   = trim($post['xf_to'][$i] ?? '');
            if ($from === '' && $to === '') continue;
            $transfers[] = [
                'date'        => trim($post['xf_date'][$i] ?? ''),
                'from'        => $from,
                'to'          => $to,
                'flight_no'   => trim($post['xf_flight_no'][$i] ?? ''),
                'flight_time' => trim($post['xf_flight_time'][$i] ?? ''),
                'notes'       => trim($post['xf_notes'][$i] ?? ''),
            ];
        }
        $model['transfers'] = $transfers;
    }
}

/** Persist filled-in GPS/phone/address for matched or new lodges. */
function voucher_save_directory(PDO $db, array $model): void
{
    $dir = voucher_lodge_dir($db);
    $stmt = $db->prepare(
        'INSERT INTO iti_voucher_lodges (name_key, display_name, phone, address, gps)
         VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            display_name=VALUES(display_name), phone=VALUES(phone),
            address=VALUES(address), gps=VALUES(gps)'
    );
    foreach ($model['accommodations'] as $a) {
        if ($a['gps'] === '' && $a['provider_phone'] === '' && $a['provider_address'] === '') continue;
        $hit = voucher_lodge_match($dir, $a['lodge']);
        // Prefer an existing, specific key; otherwise derive from the first two words.
        $key = $hit['name_key'] ?? voucher_norm(implode(' ', array_slice(explode(' ', voucher_norm($a['lodge'])), 0, 2)));
        if ($key === '') continue;
        try {
            $stmt->execute([$key, $a['provider_name'], $a['provider_phone'], $a['provider_address'], $a['gps']]);
        } catch (PDOException $e) { /* ignore individual failures */ }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
//  Build the review model for display (step=review).
// ─────────────────────────────────────────────────────────────────────────────
$model = null;
if ($step === 'review' && !$error) {
    $dir = voucher_token_dir($token);
    try {
        $sheets = voucher_xlsx_sheets($dir . '/calc.xlsx');
        if ($sheet === '' && $sheets) {
            // Default to a "CONF" (confirmed) sheet if present, else the first.
            $sheet = $sheets[0];
            foreach ($sheets as $s) { if (stripos($s, 'conf') !== false) { $sheet = $s; break; } }
        }
        $model = voucher_build_model($dir . '/prog.docx', $dir . '/calc.xlsx', $sheet ?: null, $origDocx);
        voucher_apply_directory($db, $model);
    } catch (Throwable $e) {
        $error  = 'Could not read the files: ' . $e->getMessage();
        $step   = 'upload';
        $model  = null;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
//  View
// ─────────────────────────────────────────────────────────────────────────────
$page_title = 'Vouchers — Itinerary Builder';
$extra_css  = iti_extra_css();
include __DIR__ . '/../../includes/layout_header.php';
?>
<main>
<?php iti_nav('Vouchers'); ?>

<div class="page-header">
  <div>
    <h2>Voucher Generator</h2>
    <div class="sub">Build guest vouchers from a WeTu programme + calc sheet</div>
  </div>
</div>

<?php if ($error): ?>
  <div style="background:#fdecea;border:1px solid #f5c6cb;color:#a1231c;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
    <?= h($error) ?>
  </div>
<?php endif; ?>

<?php if ($step !== 'review' || !$model): ?>
  <!-- ── STEP 1: UPLOAD ─────────────────────────────────────────────── -->
  <form method="post" enctype="multipart/form-data" action="vouchers.php?step=review"
        style="max-width:640px;background:#fff;border:1px solid var(--grey-lt);border-radius:10px;padding:24px;">
    <input type="hidden" name="step" value="review">
    <div class="form-group">
      <label>Word programme (.docx) — from WeTu</label>
      <input type="file" name="docx" accept=".docx" required>
    </div>
    <div class="form-group" style="margin-top:14px;">
      <label>Excel calc (.xlsx)</label>
      <input type="file" name="xlsx" accept=".xlsx" required>
    </div>
    <div style="margin-top:20px;">
      <button type="submit" class="btn btn-red">Continue to review →</button>
    </div>
    <p style="color:var(--grey-mid);font-size:.8rem;margin-top:16px;line-height:1.6;">
      The programme drives accommodations, transfers and flights (own-arrangement stays are skipped).
      The calc sheet supplies traveller names, room type and dietary notes. You can edit everything
      on the next screen before generating.
    </p>
  </form>

<?php else: ?>
  <!-- ── STEP 2: REVIEW ─────────────────────────────────────────────── -->
  <?php
    $sheets = voucher_xlsx_sheets(voucher_token_dir($token) . '/calc.xlsx');
    $titles = ['Mr', 'Mrs', 'Ms', 'Dr', 'Master', 'Miss'];
    $meals  = [
        'Full Board - Dinner, Bed, Breakfast and Lunch',
        'Half Board - Dinner, Bed and Breakfast',
        'Bed and Breakfast',
        'Room Only',
        'All Inclusive',
    ];
    $missingGps = 0;
    foreach ($model['accommodations'] as $a) { if ($a['gps_missing']) $missingGps++; }
  ?>

  <?php if (count($sheets) > 1): ?>
    <form method="post" action="vouchers.php?step=review" style="margin-bottom:16px;">
      <input type="hidden" name="step" value="review">
      <input type="hidden" name="token" value="<?= h($token) ?>">
      <input type="hidden" name="orig_docx" value="<?= h($origDocx) ?>">
      <label style="font-size:.85rem;font-weight:600;">Calc sheet:</label>
      <select name="sheet" onchange="this.form.submit()" style="padding:6px 10px;border-radius:6px;">
        <?php foreach ($sheets as $s): ?>
          <option value="<?= h($s) ?>" <?= $s === $sheet ? 'selected' : '' ?>><?= h($s) ?></option>
        <?php endforeach; ?>
      </select>
      <span style="color:var(--grey-mid);font-size:.8rem;">(change if the confirmed option is a different sheet)</span>
    </form>
  <?php endif; ?>

  <form method="post" action="vouchers.php?step=generate">
    <input type="hidden" name="step" value="generate">
    <input type="hidden" name="token" value="<?= h($token) ?>">
    <input type="hidden" name="orig_docx" value="<?= h($origDocx) ?>">
    <input type="hidden" name="sheet" value="<?= h($sheet) ?>">

    <!-- Header / travellers -->
    <div style="background:#fff;border:1px solid var(--grey-lt);border-radius:10px;padding:20px;margin-bottom:16px;">
      <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:end;">
        <div class="form-group" style="flex:1;min-width:260px;">
          <label>Our Ref. No.</label>
          <input type="text" name="ref" value="<?= h($model['ref']) ?>">
        </div>
        <div class="form-group" style="width:110px;">
          <label>Adults</label>
          <input type="number" name="adults" min="1" value="<?= (int)$model['adults'] ?>">
        </div>
      </div>

      <div style="font-weight:600;margin:14px 0 8px;">Travellers</div>
      <table style="width:100%;border-collapse:collapse;">
        <thead>
          <tr style="text-align:left;font-size:.72rem;color:var(--grey-mid);text-transform:uppercase;letter-spacing:.06em;">
            <th style="padding:4px 8px;width:110px;">Title</th>
            <th style="padding:4px 8px;">Name</th>
            <th style="padding:4px 8px;width:160px;">Country</th>
          </tr>
        </thead>
        <tbody>
          <?php
            $rows = $model['travellers'];
            for ($i = 0; $i < 2; $i++) $rows[] = ['title' => '', 'name' => '', 'country' => '']; // spare rows
            foreach ($rows as $i => $t): ?>
            <tr>
              <td style="padding:3px 8px;">
                <select name="tr_title[<?= $i ?>]" style="width:100%;padding:6px;border-radius:6px;">
                  <option value=""></option>
                  <?php foreach ($titles as $ti): ?>
                    <option value="<?= h($ti) ?>" <?= $ti === $t['title'] ? 'selected' : '' ?>><?= h($ti) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td style="padding:3px 8px;"><input type="text" name="tr_name[<?= $i ?>]" value="<?= h($t['name']) ?>" style="width:100%;padding:6px;border-radius:6px;"></td>
              <td style="padding:3px 8px;"><input type="text" name="tr_country[<?= $i ?>]" value="<?= h($t['country']) ?>" style="width:100%;padding:6px;border-radius:6px;"></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="form-group" style="margin-top:14px;">
        <label>Dietary Requirements / notes</label>
        <textarea name="dietary" rows="2" style="width:100%;padding:8px;border-radius:6px;"><?= h($model['dietary']) ?></textarea>
      </div>
    </div>

    <!-- Accommodations -->
    <div style="font-weight:600;margin:6px 0 10px;">
      Accommodation vouchers (<?= count($model['accommodations']) ?>)
      <?php if ($missingGps): ?>
        <span style="color:#C0211B;font-weight:500;font-size:.85rem;">— <?= $missingGps ?> lodge(s) missing GPS</span>
      <?php endif; ?>
    </div>

    <?php foreach ($model['accommodations'] as $i => $a): ?>
      <div style="background:#fff;border:1px solid var(--grey-lt);border-left:4px solid #C0211B;border-radius:10px;padding:16px 18px;margin-bottom:12px;">
        <div style="font-weight:600;margin-bottom:2px;"><?= h($a['lodge']) ?></div>
        <div style="color:var(--grey-mid);font-size:.82rem;margin-bottom:12px;">
          <?= h(voucher_fmt_date($a['checkin'])) ?> → <?= h(voucher_fmt_date($a['checkout'])) ?>
          (<?= (int)$a['nights'] ?> night<?= $a['nights'] === 1 ? '' : 's' ?>) · <?= h($a['dest']) ?>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div class="form-group"><label>Provider name</label>
            <input type="text" name="acc_provider_name[<?= $i ?>]" value="<?= h($a['provider_name']) ?>"></div>
          <div class="form-group"><label>Provider phone</label>
            <input type="text" name="acc_provider_phone[<?= $i ?>]" value="<?= h($a['provider_phone']) ?>"></div>
          <div class="form-group" style="grid-column:1/3;"><label>Address</label>
            <input type="text" name="acc_provider_address[<?= $i ?>]" value="<?= h($a['provider_address']) ?>"></div>
          <div class="form-group" style="grid-column:1/3;">
            <label style="<?= $a['gps_missing'] ? 'color:#C0211B;' : '' ?>">GPS <?= $a['gps_missing'] ? '(missing — fill in to appear on the voucher)' : '' ?></label>
            <input type="text" name="acc_gps[<?= $i ?>]" value="<?= h($a['gps']) ?>"
                   placeholder="S 2° 28' 19.555&quot;, E 34° 32' 48.395&quot;"
                   style="<?= $a['gps_missing'] ? 'border-color:#C0211B;' : '' ?>"></div>
          <div class="form-group"><label>Room</label>
            <input type="text" name="acc_room[<?= $i ?>]" value="<?= h($a['room']) ?>"></div>
          <div class="form-group"><label>Meal basis</label>
            <select name="acc_meal[<?= $i ?>]">
              <?php $found = false; foreach ($meals as $mopt): $sel = ($mopt === $a['meal']); $found = $found || $sel; ?>
                <option value="<?= h($mopt) ?>" <?= $sel ? 'selected' : '' ?>><?= h($mopt) ?></option>
              <?php endforeach; ?>
              <?php if (!$found && $a['meal'] !== ''): ?>
                <option value="<?= h($a['meal']) ?>" selected><?= h($a['meal']) ?></option>
              <?php endif; ?>
            </select>
          </div>
        </div>
      </div>
    <?php endforeach; ?>

    <!-- Transfers (+ internal flights) — editable -->
    <div style="font-weight:600;margin:16px 0 10px;">Transfer vouchers (<?= count($model['transfers']) ?>)</div>
    <div style="background:#fff;border:1px solid var(--grey-lt);border-radius:10px;padding:12px 14px;margin-bottom:16px;overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:.85rem;min-width:920px;">
        <thead>
          <tr style="text-align:left;font-size:.7rem;color:var(--grey-mid);text-transform:uppercase;letter-spacing:.06em;">
            <th style="padding:4px 6px;width:140px;">Date</th>
            <th style="padding:4px 6px;">Pick up</th>
            <th style="padding:4px 6px;">Drop off</th>
            <th style="padding:4px 6px;width:100px;">Flight no.</th>
            <th style="padding:4px 6px;width:90px;">Flight time</th>
            <th style="padding:4px 6px;width:230px;">Notes</th>
          </tr>
        </thead>
        <tbody>
          <?php
            $xrows = $model['transfers'];
            for ($k = 0; $k < 2; $k++) $xrows[] = ['date' => '', 'from' => '', 'to' => '', 'flight_no' => '', 'flight_time' => '', 'notes' => '']; // spare rows
            foreach ($xrows as $i => $t): ?>
            <tr>
              <td style="padding:3px 6px;"><input type="date" name="xf_date[<?= $i ?>]" value="<?= h($t['date']) ?>" style="width:100%;padding:6px;border-radius:6px;"></td>
              <td style="padding:3px 6px;"><input type="text" name="xf_from[<?= $i ?>]" value="<?= h($t['from']) ?>" style="width:100%;padding:6px;border-radius:6px;"></td>
              <td style="padding:3px 6px;"><input type="text" name="xf_to[<?= $i ?>]" value="<?= h($t['to']) ?>" style="width:100%;padding:6px;border-radius:6px;"></td>
              <td style="padding:3px 6px;"><input type="text" name="xf_flight_no[<?= $i ?>]" value="<?= h($t['flight_no']) ?>" placeholder="AL145" style="width:100%;padding:6px;border-radius:6px;"></td>
              <td style="padding:3px 6px;"><input type="text" name="xf_flight_time[<?= $i ?>]" value="<?= h($t['flight_time']) ?>" placeholder="07:45" style="width:100%;padding:6px;border-radius:6px;"></td>
              <td style="padding:3px 6px;"><input type="text" name="xf_notes[<?= $i ?>]" value="<?= h($t['notes'] ?? '') ?>" style="width:100%;padding:6px;border-radius:6px;"></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div style="color:var(--grey-mid);font-size:.78rem;margin-top:6px;">
        Leave Pick up &amp; Drop off blank to skip a row. Add a flight no./time to print “Flight Departs” on that transfer voucher.
        Zanzibar airport drop-offs get the standard pick-up timing note automatically (editable).
      </div>
    </div>

    <!-- Actions -->
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;background:#fff;border:1px solid var(--grey-lt);border-radius:10px;padding:16px 18px;">
      <?php if ($missingGps): ?>
        <label style="font-size:.85rem;display:flex;gap:6px;align-items:center;">
          <input type="checkbox" name="save_directory" value="1"> Save filled GPS/contacts to the lodge directory
        </label>
        <span style="flex:1;"></span>
      <?php endif; ?>
      <button type="submit" name="format" value="pdf" class="btn btn-red">Generate PDF</button>
      <button type="submit" name="format" value="word" class="btn btn-outline">Generate Word</button>
      <a href="vouchers.php" class="btn btn-outline">Start over</a>
    </div>
  </form>
<?php endif; ?>

</main>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
