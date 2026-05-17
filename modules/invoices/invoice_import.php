<?php
/**
 * invoice_import.php  — Import a Zoho PDF invoice into the hub
 * Step 1 (GET):          upload form
 * Step 2 (POST upload):  extract + parse → review form
 * Step 3 (POST save):    create invoice + items + optional payment
 */
$pageTitle = 'Import Invoice';
require_once 'config.php';

$db  = db();
$uid = current_user()['id'];

/* ════════════════════════════════════════════════════════════════
   PDF TEXT EXTRACTION  (Zoho FlateDecode + wide-char encoding)
   ════════════════════════════════════════════════════════════════ */

function pdfDecompress(string $s): string|false {
    // FlateDecode = zlib (RFC 1950); try both gzuncompress and gzinflate
    $r = @gzuncompress($s);
    if ($r !== false) return $r;
    $r = @gzinflate($s);
    if ($r !== false) return $r;
    // Strip 2-byte zlib header and try raw inflate
    if (strlen($s) > 2) {
        $r = @gzinflate(substr($s, 2));
        if ($r !== false) return $r;
    }
    return false;
}

function pdfCmapShift(string $raw): int {
    // Read ToUnicode CMap bfrange to detect the glyph→unicode shift.
    // Zoho uses a uniform shift (typically 29 = 0x1D).
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $m);
    foreach ($m[1] as $s) {
        $dec = pdfDecompress($s);
        if ($dec === false) continue;
        if (strpos($dec, 'beginbfrange') === false) continue;
        // first bfrange entry: <from><to><unicode>
        if (preg_match('/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>/', $dec, $r)) {
            $shift = hexdec($r[3]) - hexdec($r[1]);
            if ($shift > 0 && $shift < 256) return $shift;
        }
    }
    return 29; // safe default for Zoho
}

function pdfExtractParenString(string $dec, int $start): array {
    // Extract bytes of a PDF parenthesised literal string starting AFTER '('
    $depth = 0; $i = $start; $bytes = '';
    $len = strlen($dec);
    while ($i < $len) {
        $c = $dec[$i];
        if ($c === '\\' && $i + 1 < $len) {
            $n = $dec[$i + 1];
            $bytes .= match($n) {
                'n' => "\n", 'r' => "\r", 't' => "\t",
                '(' => '(', ')' => ')', '\\' => '\\',
                default => $n,
            };
            $i += 2; continue;
        }
        if ($c === '(') { $depth++; $bytes .= $c; $i++; continue; }
        if ($c === ')') {
            if ($depth-- <= 0) return [$bytes, $i + 1];
            $bytes .= $c; $i++; continue;
        }
        $bytes .= $c; $i++;
    }
    return [$bytes, $i];
}

function pdfDecodeWide(string $bytes, int $shift): string {
    $text = '';
    for ($k = 0; $k + 1 < strlen($bytes); $k += 2) {
        $code = (ord($bytes[$k]) << 8) | ord($bytes[$k + 1]);
        if ($code === 0) continue;
        $text .= mb_chr($code + $shift, 'UTF-8');
    }
    return $text;
}

function extractTextFromPdf(string $path): string {
    // Try pdftotext first (fast path)
    if (function_exists('exec')) {
        $safe = escapeshellarg($path);
        $out  = []; $rc = 0;
        @exec("pdftotext -layout $safe - 2>/dev/null", $out, $rc);
        if ($rc === 0 && !empty($out) && strlen(implode('', $out)) > 20) {
            return implode("\n", $out);
        }
    }

    // Pure-PHP fallback: FlateDecode + Zoho wide-char encoding
    $raw   = file_get_contents($path);
    $shift = pdfCmapShift($raw);

    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $m);
    $pieces = [];

    foreach ($m[1] as $stream) {
        $dec = pdfDecompress($stream);
        if ($dec === false || strpos($dec, 'BT') === false) continue;

        $i = 0; $len = strlen($dec);
        while ($i < $len) {
            if ($dec[$i] === '(') {
                [$bytes, $i] = pdfExtractParenString($dec, $i + 1);
                $rest = ltrim(substr($dec, $i, 10));
                if (str_starts_with($rest, 'Tj') || str_starts_with($rest, 'TJ')) {
                    $txt = pdfDecodeWide($bytes, $shift);
                    // Normalise: replace γ (common Zoho artefact) → I
                    $txt = str_replace('γ', 'I', $txt);
                    $txt = trim($txt);
                    if ($txt !== '') $pieces[] = $txt;
                }
            } else {
                $i++;
            }
        }
    }
    return implode("\n", $pieces);
}

/* ════════════════════════════════════════════════════════════════
   ZOHO TEXT PARSER
   ════════════════════════════════════════════════════════════════ */

function parseDate(string $s): string {
    static $mo = ['jan'=>'01','feb'=>'02','mar'=>'03','apr'=>'04','may'=>'05','jun'=>'06',
                  'jul'=>'07','aug'=>'08','sep'=>'09','oct'=>'10','nov'=>'11','dec'=>'12'];
    if (preg_match('/(\d{1,2})\s+([A-Za-z]{3})\s+(\d{4})/', $s, $r))
        return $r[3].'-'.($mo[strtolower($r[2])] ?? '01').'-'.str_pad($r[1],2,'0',STR_PAD_LEFT);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($s))) return trim($s);
    return '';
}

function parseAmt(string $s): float {
    return (float) preg_replace('/[^0-9.]/', '', $s);
}

function parseZohoText(string $text): array {
    $lines = preg_split('/\r?\n/', $text);
    $mg    = fn($re) => (preg_match($re, $text, $r) ? trim($r[1]) : '');
    $d     = [];

    $d['invoice_number'] = $mg('/Invoice\s*#\s*([\w\-]+)/i')
                        ?: $mg('/#\s*(INV-[\w\-]+)/i');
    $d['issue_date']     = parseDate($mg('/Invoice\s*Date\s*[:\-]\s*(\d{1,2}\s+[A-Za-z]{3}\s+\d{4})/i'));
    $d['due_date']       = parseDate($mg('/Due\s*Date\s*[:\-]\s*(\d{1,2}\s+[A-Za-z]{3}\s+\d{4})/i'));
    $d['terms']          = $mg('/Terms\s*[:\-]\s*([^\n\r]+)/i') ?: 'Due on Receipt';
    $d['currency']       = str_contains($text, '€') ? 'EUR' : 'USD';
    $d['issuer']         = preg_match('/Savannah Holidays/i', $text)
                           ? 'Savannah Holidays Ltd' : 'Savannah Explorers Ltd';

    // Bill To: line after "Bill To"
    $bi = -1;
    foreach ($lines as $i => $l) {
        if (preg_match('/^Bill\s*To\s*$/i', trim($l))) { $bi = $i; break; }
    }
    $d['bill_to_name'] = trim($bi >= 0 ? ($lines[$bi + 1] ?? '') : $mg('/Bill\s*To\s+([A-Z][^\n\r]+)/i'));

    // Totals
    $d['subtotal']    = parseAmt($mg('/Sub\s*Total\s+([\d,]+\.?\d*)/i'));
    $d['total']       = parseAmt($mg('/Total\s+\$?([\d,]+\.?\d*)/i')) ?: $d['subtotal'];
    $d['amount_paid'] = parseAmt($mg('/Payment\s*Made\s*[-\(]?\s*([\d,]+\.?\d*)/i'));
    $d['balance_due'] = parseAmt($mg('/Balance\s*Due\s+\$?([\d,]+\.?\d*)/i'))
                       ?: max(0, $d['total'] - $d['amount_paid']);

    // Notes / T&C
    $ni = -1;
    foreach ($lines as $i => $l) { if (preg_match('/^Notes\s*$/i', trim($l))) { $ni = $i; break; } }
    $d['notes'] = $ni >= 0 ? trim($lines[$ni + 1] ?? '') : 'Thanks for your business.';

    $ti = -1;
    foreach ($lines as $i => $l) { if (preg_match('/Terms\s*&\s*Conditions?/i', $l)) { $ti = $i; break; } }
    $d['terms_conditions'] = $ti >= 0
        ? trim(implode(' ', array_slice($lines, $ti + 1, 2)))
        : '30% deposit at the time of booking, balance within 60 days before the trip starts';

    // Line items  "N  Description  qty  rate  amount"
    $items = [];
    if (preg_match_all('/^\s*\d+\s+(.+?)\s+([\d,]+\.?\d*)\s+([\d,]+\.?\d*)\s+([\d,]+\.?\d*)\s*$/m', $text, $ms, PREG_SET_ORDER)) {
        foreach ($ms as $r) {
            $desc = trim($r[1]);
            if (preg_match('/item|description|qty|rate|amount/i', $desc)) continue;
            $items[] = ['description'=>$desc,'quantity'=>parseAmt($r[2]),'unit_price'=>parseAmt($r[3]),'line_total'=>parseAmt($r[4])];
        }
    }
    // Fallback line scan
    if (empty($items)) {
        $inI = false;
        foreach ($lines as $l) {
            if (preg_match('/Item.*Description/i', $l)) { $inI = true; continue; }
            if (preg_match('/Sub\s*Total|Balance\s*Due|Payment\s*Made/i', $l)) { $inI = false; continue; }
            if (!$inI) continue;
            if (preg_match('/([\d,]+\.?\d*)\s+([\d,]+\.?\d*)\s+([\d,]+\.?\d*)$/', $l, $a)) {
                $desc = trim(substr($l, 0, -strlen($a[0])));
                if (strlen($desc) > 1)
                    $items[] = ['description'=>$desc,'quantity'=>parseAmt($a[1]),'unit_price'=>parseAmt($a[2]),'line_total'=>parseAmt($a[3])];
            }
        }
    }
    $d['items'] = $items;
    return $d;
}

/* ════════════════════════════════════════════════════════════════
   STEP 3 — SAVE
   ════════════════════════════════════════════════════════════════ */
$saved = null; $saveError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $billToName = trim($_POST['bill_to_name'] ?? '');
    $reqId      = (int)($_POST['request_id'] ?? 0) ?: null;
    $issueDate  = $_POST['issue_date'] ?? '';
    $errors     = [];
    if (!$billToName) $errors[] = 'Bill To name is required.';
    if (!$issueDate)  $errors[] = 'Issue date is required.';

    $items = [];
    foreach ($_POST['item_desc'] ?? [] as $i => $desc) {
        $desc = trim($desc); if (!$desc) continue;
        $qty   = (float)($_POST['item_qty'][$i]   ?? 1);
        $price = (float)($_POST['item_price'][$i] ?? 0);
        $items[] = ['description'=>$desc,'quantity'=>$qty,'unit_price'=>$price,'line_total'=>round($qty*$price,2)];
    }
    if (empty($items)) $errors[] = 'At least one line item is required.';

    if (!$errors) {
        try {
            $db->beginTransaction();
            $issuer   = $_POST['issuer']   ?? INV_ISSUERS[0];
            $currency = $_POST['currency'] ?? 'USD';
            $invNum   = generate_invoice_number($db, $issuer);

            $db->prepare("
                INSERT INTO invoices
                    (invoice_number, request_id, customer_id, bill_to_name, bill_to_address,
                     issuer, currency, issue_date, due_date, terms, notes, terms_conditions,
                     status, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'New',?)
            ")->execute([
                $invNum, $reqId,
                (int)($_POST['customer_id'] ?? 0) ?: null,
                $billToName,
                trim($_POST['bill_to_address'] ?? '') ?: null,
                $issuer, $currency,
                $issueDate,
                trim($_POST['due_date'] ?? '') ?: null,
                trim($_POST['terms'] ?? 'Due on Receipt'),
                trim($_POST['notes'] ?? '') ?: INV_DEFAULT_NOTES,
                trim($_POST['terms_conditions'] ?? '') ?: INV_DEFAULT_TC,
            ]);
            $invId = (int)$db->lastInsertId();

            $sort = 0;
            $ist  = $db->prepare("INSERT INTO invoice_items (invoice_id,sort_order,description,quantity,unit_price,line_total) VALUES (?,?,?,?,?,?)");
            foreach ($items as $it)
                $ist->execute([$invId, $sort++, $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);

            $payAmt = round((float)($_POST['payment_amount'] ?? 0), 2);
            if ($payAmt > 0) {
                $pm = $_POST['payment_method'] ?? 'Bank Transfer';
                if (!in_array($pm, INV_METHODS)) $pm = 'Bank Transfer';
                $db->prepare("INSERT INTO invoice_payments (invoice_id,payment_date,amount,method,reference,notes) VALUES (?,?,?,?,?,?)")
                   ->execute([$invId, $_POST['payment_date'] ?: $issueDate, $payAmt, $pm,
                              trim($_POST['payment_ref'] ?? '') ?: null, 'Imported from Zoho PDF']);
            }

            recalculate_invoice($db, $invId);

            // Update request pax if provided
            if ($reqId && isset($_POST['request_pax']) && (int)$_POST['request_pax'] > 0) {
                $db->prepare("UPDATE requests SET pax=? WHERE id=?")
                   ->execute([(int)$_POST['request_pax'], $reqId]);
            }
            if ($reqId) sync_request_value($db, $invId);

            $db->commit();
            $saved = ['id' => $invId, 'number' => $invNum];
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $saveError = $e->getMessage();
        }
    } else {
        $saveError = implode(' ', $errors);
    }
}

/* ════════════════════════════════════════════════════════════════
   STEP 2 — UPLOAD + PARSE
   ════════════════════════════════════════════════════════════════ */
$parsed = null; $parseWarn = ''; $uploadError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    $f = $_FILES['pdf_file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        $uploadError = 'Upload failed — please try again.';
    } elseif (!str_ends_with(strtolower($f['name']), '.pdf')) {
        $uploadError = 'Please upload a PDF file.';
    } else {
        $text = extractTextFromPdf($f['tmp_name']);
        if (strlen(trim($text)) < 20) {
            $parseWarn = 'Could not extract text from this PDF. Fill in the fields manually.';
            $parsed    = [];
        } else {
            $parsed = parseZohoText($text);
            if (empty($parsed['items']))  $parseWarn .= ' Line items not detected — add manually.';
            if (!$parsed['issue_date'])   $parseWarn .= ' Issue date not detected.';
        }
    }
}

/* Pre-load for autocomplete */
if ($parsed !== null) {
    $requests  = $db->query("
        SELECT r.id, r.practice_code, r.customer_name, r.date_received, r.status,
               r.pax, a.name AS agent_name
        FROM   requests r LEFT JOIN agents a ON r.agent_id = a.id
        ORDER  BY r.date_received DESC")->fetchAll();

    $billToSources = $db->query("
        SELECT id, nome AS name, 'agency' AS source_type, COALESCE(address,'') AS address
        FROM   agencies WHERE attiva = 1
        UNION ALL
        SELECT id, name, 'customer' AS source_type,
               COALESCE(CONCAT_WS(', ', NULLIF(address,''), NULLIF(city,''), NULLIF(country,'')), '') AS address
        FROM   customers WHERE active = 1
        ORDER  BY name ASC")->fetchAll();
}

include 'includes/header.php';
?>
<main>

<?php if ($saved): ?>
<!-- SUCCESS -->
<div class="page-header">
  <div><h2>Invoice Imported</h2><div class="sub">Zoho PDF → Hub</div></div>
</div>
<div class="form-card" style="text-align:center;padding:48px 32px">
  <div style="font-size:3rem;margin-bottom:16px">✓</div>
  <h3 style="font-family:'Merriweather',serif;font-size:1.2rem;margin-bottom:8px">Invoice <?= h($saved['number']) ?> created</h3>
  <p style="color:var(--grey-mid);font-size:.88rem;margin-bottom:28px">The invoice has been saved and is ready to view.</p>
  <div style="display:flex;gap:12px;justify-content:center">
    <a href="invoice_view.php?id=<?= $saved['id'] ?>" class="btn btn-red">View Invoice</a>
    <a href="invoice_import.php" class="btn btn-outline">Import Another</a>
    <a href="invoices.php" class="btn btn-outline">All Invoices</a>
  </div>
</div>

<?php elseif ($parsed !== null): ?>
<!-- STEP 2: review -->
<div class="page-header">
  <div><h2>Review &amp; Save</h2><div class="sub">Check extracted data, link request, then save</div></div>
  <a href="invoice_import.php" class="btn btn-outline btn-sm">← Upload new PDF</a>
</div>

<?php if ($saveError): ?>
<div style="background:#fff0f0;border:1.5px solid var(--red);border-radius:8px;padding:12px 16px;margin-bottom:20px;color:var(--red);font-size:.85rem"><?= h($saveError) ?></div>
<?php endif; ?>
<?php if ($parseWarn): ?>
<div style="background:#fffbea;border:1.5px solid var(--amber);border-radius:8px;padding:10px 16px;margin-bottom:20px;color:#7a5800;font-size:.82rem">⚠ <?= h(trim($parseWarn)) ?></div>
<?php endif; ?>

<form method="post" action="invoice_import.php">
<input type="hidden" name="action" value="save">
<input type="hidden" name="customer_id" id="hidCustomerId" value="">

<!-- Invoice details -->
<div class="form-card" style="margin-bottom:20px">
  <div class="section-label" style="margin-bottom:20px">Invoice details</div>
  <div class="form-grid">
    <div class="form-group">
      <label>Issue date</label>
      <input type="date" name="issue_date" value="<?= h($parsed['issue_date'] ?? '') ?>" required>
    </div>
    <div class="form-group">
      <label>Due date</label>
      <input type="date" name="due_date" value="<?= h($parsed['due_date'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Issuer</label>
      <select name="issuer">
        <?php foreach (INV_ISSUERS as $iss): ?>
          <option value="<?= h($iss) ?>" <?= ($parsed['issuer'] ?? '') === $iss ? 'selected' : '' ?>><?= h($iss) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Currency</label>
      <select name="currency">
        <?php foreach (INV_CURRENCIES as $c): ?>
          <option value="<?= h($c) ?>" <?= ($parsed['currency'] ?? 'USD') === $c ? 'selected' : '' ?>><?= h($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Original Zoho # <small style="color:var(--grey-mid)">(reference only)</small></label>
      <input type="text" name="original_invoice_number" value="<?= h($parsed['invoice_number'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Terms</label>
      <input type="text" name="terms" value="<?= h($parsed['terms'] ?? 'Due on Receipt') ?>">
    </div>
  </div>
  <p style="margin-top:14px;font-size:.75rem;color:var(--grey-mid)">ℹ Hub number (SE/SH-YYYY-NNNN) auto-generated on save.</p>
</div>

<!-- Link to request -->
<div class="form-card" style="margin-bottom:20px">
  <div class="section-label" style="margin-bottom:16px">Link to request (practice)</div>
  <input type="hidden" name="request_id" id="hidReqId" value="">

  <div id="reqLinked" style="display:none;background:#eef6ff;border:1.5px solid #b3d4f7;border-radius:8px;padding:12px 16px;margin-bottom:12px">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px">
      <div style="flex:1">
        <strong id="reqLinkedName" style="font-size:.9rem;display:block;margin-bottom:4px"></strong>
        <div id="reqLinkedSub" style="font-size:.75rem;color:var(--grey-mid);margin-bottom:10px"></div>
        <div style="display:flex;align-items:center;gap:10px">
          <label style="font-size:.8rem;font-weight:600;color:var(--grey-dk)">Pax</label>
          <input type="number" name="request_pax" id="reqPax" min="1" max="99" value=""
                 style="width:70px;padding:5px 8px;border:1.5px solid var(--grey-lt);border-radius:6px;font-size:.85rem"
                 placeholder="—">
          <span style="font-size:.75rem;color:var(--grey-mid)">Update pax on the linked request</span>
        </div>
      </div>
      <button type="button" onclick="clearReq()" style="background:none;border:none;cursor:pointer;color:var(--grey-mid);font-size:1.1rem;flex-shrink:0">✕</button>
    </div>
  </div>

  <div id="reqSearchBox">
    <input type="text" id="reqQ" placeholder="Type customer name or practice code…"
           value="<?= h($parsed['bill_to_name'] ?? '') ?>"
           oninput="filterReq(this.value)" autocomplete="off"
           style="width:100%;padding:9px 12px;border:1.5px solid var(--grey-lt);border-radius:7px;font-size:.88rem">
    <div id="reqDrop" style="display:none;position:absolute;left:0;right:0;background:#fff;border:1.5px solid var(--grey-lt);border-radius:0 0 8px 8px;z-index:200;max-height:220px;overflow-y:auto;box-shadow:0 4px 16px rgba(0,0,0,.1)"></div>
    <div style="margin-top:8px;font-size:.78rem;color:var(--grey-mid)">
      Tip: try surname only, or the practice code. &nbsp;·&nbsp;
      <a href="#" onclick="skipReq();return false;" style="color:var(--grey-mid)">Skip — save without linking a request</a>
    </div>
  </div>
</div>

<!-- Bill To -->
<div class="form-card" style="margin-bottom:20px">
  <div class="section-label" style="margin-bottom:16px">Bill to</div>
  <input type="hidden" name="bill_to_name"    id="hidBTName"  value="<?= h($parsed['bill_to_name'] ?? '') ?>">
  <input type="hidden" name="bill_to_address" id="hidBTAddr"  value="">

  <div id="btLinked" style="display:none;background:#eef6ff;border:1.5px solid #b3d4f7;border-radius:8px;padding:10px 14px;margin-bottom:10px">
    <div style="display:flex;align-items:center;justify-content:space-between">
      <div><strong id="btLinkedName" style="font-size:.9rem"></strong>
           <div id="btLinkedSub" style="font-size:.75rem;color:var(--grey-mid)"></div></div>
      <button type="button" onclick="clearBT()" style="background:none;border:none;cursor:pointer;color:var(--grey-mid);font-size:1.1rem">✕</button>
    </div>
  </div>

  <div id="btSearchBox">
    <input type="text" id="btQ" placeholder="Search agencies or customers…"
           value="<?= h($parsed['bill_to_name'] ?? '') ?>"
           oninput="filterBT(this.value)" autocomplete="off"
           style="width:100%;padding:9px 12px;border:1.5px solid var(--grey-lt);border-radius:7px;font-size:.88rem;margin-bottom:8px">
    <div id="btDrop" style="display:none;position:absolute;left:0;right:0;background:#fff;border:1.5px solid var(--grey-lt);border-radius:0 0 8px 8px;z-index:200;max-height:220px;overflow-y:auto;box-shadow:0 4px 16px rgba(0,0,0,.1)"></div>
  </div>

  <!-- Manual fallback always visible below search -->
  <div style="margin-top:10px;padding-top:10px;border-top:1px dashed var(--grey-lt)">
    <div style="font-size:.75rem;color:var(--grey-mid);margin-bottom:6px">Or enter manually:</div>
    <div class="form-grid">
      <div class="form-group">
        <label>Name</label>
        <input type="text" id="manualBTName" value="<?= h($parsed['bill_to_name'] ?? '') ?>"
               oninput="document.getElementById('hidBTName').value=this.value">
      </div>
      <div class="form-group">
        <label>Address</label>
        <input type="text" id="manualBTAddr" oninput="document.getElementById('hidBTAddr').value=this.value">
      </div>
    </div>
  </div>
</div>

<!-- Line items -->
<div class="form-card" style="margin-bottom:20px">
  <div class="section-label" style="margin-bottom:16px">Line items</div>
  <table style="width:100%;border-collapse:collapse;font-size:.85rem">
    <thead>
      <tr style="border-bottom:2px solid var(--grey-lt)">
        <th style="text-align:left;padding:6px 8px;font-size:.72rem;text-transform:uppercase;color:var(--grey-mid);width:50%">Description</th>
        <th style="text-align:right;padding:6px 8px;font-size:.72rem;text-transform:uppercase;color:var(--grey-mid);width:10%">Qty</th>
        <th style="text-align:right;padding:6px 8px;font-size:.72rem;text-transform:uppercase;color:var(--grey-mid);width:15%">Unit price</th>
        <th style="text-align:right;padding:6px 8px;font-size:.72rem;text-transform:uppercase;color:var(--grey-mid);width:15%">Total</th>
        <th style="width:10%"></th>
      </tr>
    </thead>
    <tbody id="itemsTbody">
      <?php $hasItems = !empty($parsed['items']); ?>
      <?php foreach ($parsed['items'] ?? [] as $it): ?>
      <tr class="item-row">
        <td style="padding:4px 6px"><input type="text"   name="item_desc[]"  value="<?= h($it['description']) ?>" style="width:100%"></td>
        <td style="padding:4px 6px"><input type="number" name="item_qty[]"   value="<?= h($it['quantity'])    ?>" step="0.01" style="width:100%;text-align:right" onchange="rcRow(this)"></td>
        <td style="padding:4px 6px"><input type="number" name="item_price[]" value="<?= h($it['unit_price'])  ?>" step="0.01" style="width:100%;text-align:right" onchange="rcRow(this)"></td>
        <td style="padding:4px 6px;text-align:right;font-weight:600" class="row-total"><?= number_format($it['line_total'],2) ?></td>
        <td style="text-align:center;padding:4px"><button type="button" onclick="delRow(this)" style="background:none;border:none;cursor:pointer;color:var(--grey-mid)">✕</button></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$hasItems): ?>
      <tr class="item-row">
        <td style="padding:4px 6px"><input type="text"   name="item_desc[]"  placeholder="Description" style="width:100%"></td>
        <td style="padding:4px 6px"><input type="number" name="item_qty[]"   value="1" step="0.01" style="width:100%;text-align:right" onchange="rcRow(this)"></td>
        <td style="padding:4px 6px"><input type="number" name="item_price[]" value="0" step="0.01" style="width:100%;text-align:right" onchange="rcRow(this)"></td>
        <td style="padding:4px 6px;text-align:right;font-weight:600" class="row-total">0.00</td>
        <td style="text-align:center;padding:4px"><button type="button" onclick="delRow(this)" style="background:none;border:none;cursor:pointer;color:var(--grey-mid)">✕</button></td>
      </tr>
      <?php endif; ?>
    </tbody>
  </table>
  <button type="button" onclick="addRow()" style="margin-top:10px;background:none;border:none;cursor:pointer;color:var(--grey-mid);font-size:.82rem">+ Add row</button>
  <div style="display:flex;justify-content:flex-end;padding-top:12px;border-top:1px solid var(--grey-lt);margin-top:10px;gap:1rem">
    <span style="color:var(--grey-mid);font-size:.85rem">Total</span>
    <strong id="grandTotal" style="font-size:.95rem;min-width:80px;text-align:right">
      <?= number_format(array_sum(array_column($parsed['items'] ?? [], 'line_total')), 2) ?>
    </strong>
  </div>
</div>

<!-- Payment -->
<div class="form-card" style="margin-bottom:20px">
  <div class="section-label" style="margin-bottom:16px">Payment already received</div>
  <?php $hasPaid = ($parsed['amount_paid'] ?? 0) > 0; ?>
  <label style="display:flex;align-items:center;gap:8px;font-size:.88rem;cursor:pointer;margin-bottom:14px">
    <input type="checkbox" id="hasPay" name="has_payment" value="1" <?= $hasPaid ? 'checked' : '' ?>
           onchange="document.getElementById('payFields').style.display=this.checked?'':'none'">
    Record a payment on import
  </label>
  <div id="payFields" style="<?= $hasPaid ? '' : 'display:none' ?>">
    <div class="form-grid">
      <div class="form-group">
        <label>Amount</label>
        <input type="number" name="payment_amount" step="0.01" value="<?= h(number_format($parsed['amount_paid'] ?? 0, 2, '.', '')) ?>">
      </div>
      <div class="form-group">
        <label>Date</label>
        <input type="date" name="payment_date" value="<?= h($parsed['issue_date'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Method</label>
        <select name="payment_method">
          <?php foreach (INV_METHODS as $mm): ?><option value="<?= h($mm) ?>"><?= h($mm) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Reference</label>
        <input type="text" name="payment_ref" placeholder="Wire ref, etc.">
      </div>
    </div>
  </div>
</div>

<!-- Notes -->
<div class="form-card" style="margin-bottom:28px">
  <div class="section-label" style="margin-bottom:16px">Notes &amp; terms</div>
  <div class="form-grid">
    <div class="form-group">
      <label>Notes</label>
      <textarea name="notes"><?= h($parsed['notes'] ?? INV_DEFAULT_NOTES) ?></textarea>
    </div>
    <div class="form-group">
      <label>Terms &amp; conditions</label>
      <textarea name="terms_conditions"><?= h($parsed['terms_conditions'] ?? INV_DEFAULT_TC) ?></textarea>
    </div>
  </div>
</div>

<div style="display:flex;gap:12px;justify-content:flex-end;margin-bottom:40px">
  <a href="invoice_import.php" class="btn btn-outline">← Start over</a>
  <button type="submit" class="btn btn-red">Save Invoice</button>
</div>
</form>

<script>
const REQUESTS = <?= json_encode($requests) ?>;
const BT_SOURCES = <?= json_encode($billToSources) ?>;
function escH(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');}

/* Request search — fuzzy: every word in the query must appear somewhere in name or code */
function fuzzyMatch(r, q) {
  const haystack = (r.customer_name + ' ' + (r.practice_code||'')).toLowerCase();
  return q.trim().toLowerCase().split(/\s+/).every(word => haystack.includes(word));
}
function filterReq(q, autoOpen){
  const drop=document.getElementById('reqDrop');
  if(q.trim().length < 2){ drop.style.display='none'; return; }
  const ms = REQUESTS.filter(r => fuzzyMatch(r, q)).slice(0, 25);
  const noRes = '<div style="padding:10px 14px;font-size:.82rem;color:var(--grey-mid)">No matching request — you can leave this unlinked.</div>';
  drop.innerHTML = ms.length
    ? ms.map(r=>`<div onclick='selReq(${JSON.stringify(r)})' style="padding:9px 14px;cursor:pointer;border-bottom:1px solid var(--grey-lt);font-size:.85rem" onmouseenter="this.style.background='var(--off-white)'" onmouseleave="this.style.background=''">
        <strong>${escH(r.customer_name)}</strong>${r.practice_code?' <span style="color:var(--grey-mid)">· '+escH(r.practice_code)+'</span>':''}
        <div style="font-size:.75rem;color:var(--grey-mid)">${[r.date_received,r.status,r.pax?r.pax+' pax':'',r.agent_name?'Agent: '+r.agent_name:''].filter(Boolean).join(' · ')}</div>
      </div>`).join('')
    : noRes;
  drop.style.display = 'block';
}
function selReq(r){
  document.getElementById('hidReqId').value=r.id;
  document.getElementById('reqLinkedName').textContent=r.customer_name+(r.practice_code?' · '+r.practice_code:'');
  document.getElementById('reqLinkedSub').textContent=[r.date_received,r.status,r.agent_name?'Agent: '+r.agent_name:''].filter(Boolean).join(' · ');
  document.getElementById('reqPax').value=r.pax||'';
  document.getElementById('reqLinked').style.display='block';
  document.getElementById('reqSearchBox').style.display='none';
  document.getElementById('reqDrop').style.display='none';
}
function clearReq(){
  document.getElementById('hidReqId').value='';
  document.getElementById('reqLinked').style.display='none';
  document.getElementById('reqSearchBox').style.display='';
  document.getElementById('reqQ').value='';
}
function skipReq(){
  document.getElementById('hidReqId').value='';
  document.getElementById('reqDrop').style.display='none';
  document.getElementById('reqQ').value='';
  // Show a "not linked" badge
  document.getElementById('reqLinkedName').textContent='Not linked to any request';
  document.getElementById('reqLinkedSub').textContent='Invoice will be saved without a request link — you can add it later from invoice_view.php';
  document.getElementById('reqPax').closest('div').style.display='none';
  document.getElementById('reqLinked').style.display='block';
  document.getElementById('reqSearchBox').style.display='none';
}
/* Auto-trigger search + normalise row totals on page load */
document.addEventListener('DOMContentLoaded', function(){
  // Auto-search request
  const q = document.getElementById('reqQ');
  if(q && q.value.trim().length >= 2) filterReq(q.value, true);

  // Normalise line items: if qty*price doesn't match displayed total,
  // set qty=1 and unit_price=total so the row is self-consistent.
  document.querySelectorAll('#itemsTbody .item-row').forEach(function(tr){
    const ns  = tr.querySelectorAll('input[type=number]');
    const td  = tr.querySelector('.row-total');
    if(!ns[0]||!ns[1]||!td) return;
    const qty   = parseFloat(ns[0].value) || 0;
    const price = parseFloat(ns[1].value) || 0;
    const total = parseFloat(td.textContent.replace(/,/g,'')) || 0;
    if(total > 0 && Math.abs(qty * price - total) > 0.01){
      // qty/price from PDF are unreliable — keep total, set qty=1
      ns[0].value = 1;
      ns[1].value = total.toFixed(2);
    }
  });
  reTotal();
});

/* Bill To search */
function filterBT(q){
  const drop=document.getElementById('btDrop');
  if(q.length<1){drop.style.display='none';return;}
  const ql=q.toLowerCase();
  const ms=BT_SOURCES.filter(r=>r.name.toLowerCase().includes(ql)).slice(0,25);
  drop.innerHTML=ms.length
    ? ms.map(r=>`<div onclick='selBT(${JSON.stringify(r)})' style="padding:9px 14px;cursor:pointer;border-bottom:1px solid var(--grey-lt);font-size:.85rem" onmouseenter="this.style.background='var(--off-white)'" onmouseleave="this.style.background=''">
        <strong>${escH(r.name)}</strong> <span style="font-size:.72rem;padding:1px 6px;border-radius:3px;background:var(--off-white);color:var(--grey-mid)">${r.source_type}</span>
        ${r.address?'<div style="font-size:.75rem;color:var(--grey-mid)">'+escH(r.address)+'</div>':''}
      </div>`).join('')
    : '<div style="padding:10px 14px;font-size:.82rem;color:var(--grey-mid)">No results — use manual fields below</div>';
  drop.style.display='block';
}
function selBT(r){
  document.getElementById('hidBTName').value=r.name;
  document.getElementById('hidBTAddr').value=r.address||'';
  document.getElementById('hidCustomerId').value=r.source_type==='customer'?r.id:'';
  document.getElementById('btLinkedName').textContent=r.name;
  document.getElementById('btLinkedSub').textContent=[r.source_type,r.address].filter(Boolean).join(' · ');
  document.getElementById('btLinked').style.display='block';
  document.getElementById('btSearchBox').style.display='none';
  document.getElementById('btDrop').style.display='none';
  document.getElementById('manualBTName').value=r.name;
  document.getElementById('manualBTAddr').value=r.address||'';
}
function clearBT(){
  document.getElementById('hidBTName').value=document.getElementById('manualBTName').value;
  document.getElementById('btLinked').style.display='none';
  document.getElementById('btSearchBox').style.display='';
  document.getElementById('btQ').value='';
}
document.addEventListener('click',e=>{
  if(!e.target.closest('#reqSearchBox')&&!e.target.closest('#reqLinked')) document.getElementById('reqDrop').style.display='none';
  if(!e.target.closest('#btSearchBox')&&!e.target.closest('#btLinked')) document.getElementById('btDrop').style.display='none';
});

/* Items */
function rcRow(inp){
  const tr=inp.closest('tr'),ns=tr.querySelectorAll('input[type=number]');
  ns[2]&&(tr.querySelector('.row-total').textContent=((parseFloat(ns[0].value)||0)*(parseFloat(ns[1].value)||0)).toFixed(2));
  reTotal();
}
function reTotal(){
  let s=0; document.querySelectorAll('.row-total').forEach(td=>s+=parseFloat(td.textContent.replace(/,/g,''))||0);
  document.getElementById('grandTotal').textContent=s.toFixed(2);
}
function delRow(btn){btn.closest('tr').remove();reTotal();}
function addRow(){
  const tr=document.createElement('tr'); tr.className='item-row';
  tr.innerHTML=`<td style="padding:4px 6px"><input type="text" name="item_desc[]" placeholder="Description" style="width:100%"></td>
    <td style="padding:4px 6px"><input type="number" name="item_qty[]" value="1" step="0.01" style="width:100%;text-align:right" onchange="rcRow(this)"></td>
    <td style="padding:4px 6px"><input type="number" name="item_price[]" value="0" step="0.01" style="width:100%;text-align:right" onchange="rcRow(this)"></td>
    <td style="padding:4px 6px;text-align:right;font-weight:600" class="row-total">0.00</td>
    <td style="text-align:center;padding:4px"><button type="button" onclick="delRow(this)" style="background:none;border:none;cursor:pointer;color:var(--grey-mid)">✕</button></td>`;
  document.getElementById('itemsTbody').appendChild(tr);
}
</script>

<?php else: ?>
<!-- STEP 1: upload -->
<div class="page-header">
  <div><h2>Import Invoice from PDF</h2><div class="sub">Upload a Zoho invoice PDF to auto-fill the form</div></div>
  <a href="invoices.php" class="btn btn-outline btn-sm">← All Invoices</a>
</div>
<?php if ($uploadError): ?>
<div style="background:#fff0f0;border:1.5px solid var(--red);border-radius:8px;padding:12px 16px;margin-bottom:20px;color:var(--red);font-size:.85rem"><?= h($uploadError) ?></div>
<?php endif; ?>
<div class="form-card">
  <form method="post" action="invoice_import.php" enctype="multipart/form-data">
    <input type="hidden" name="action" value="upload">
    <div style="border:2px dashed var(--grey-lt);border-radius:10px;padding:48px 32px;text-align:center;cursor:pointer"
         ondragover="event.preventDefault();this.style.borderColor='var(--red)'"
         ondragleave="this.style.borderColor='var(--grey-lt)'"
         ondrop="event.preventDefault();this.style.borderColor='var(--grey-lt)';document.getElementById('pdfIn').files=event.dataTransfer.files;document.getElementById('lbl').textContent=event.dataTransfer.files[0]?.name||'';document.getElementById('subBtn').disabled=false"
         onclick="document.getElementById('pdfIn').click()">
      <div style="font-size:2.5rem;margin-bottom:12px">📄</div>
      <div style="font-weight:600;font-size:.95rem;margin-bottom:6px">Drop Zoho PDF here or click to browse</div>
      <div id="lbl" style="font-size:.8rem;color:var(--grey-mid)">Supports Zoho Invoice PDFs</div>
    </div>
    <input type="file" id="pdfIn" name="pdf_file" accept="application/pdf" style="display:none"
           onchange="document.getElementById('lbl').textContent=this.files[0]?.name||'';document.getElementById('subBtn').disabled=!this.files[0]">
    <div style="display:flex;justify-content:center;margin-top:24px">
      <button type="submit" id="subBtn" class="btn btn-red" disabled>Extract &amp; Review →</button>
    </div>
  </form>
</div>
<?php endif; ?>
</main>
<?php include 'includes/footer.php'; ?>
