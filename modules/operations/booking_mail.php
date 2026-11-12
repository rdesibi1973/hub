<?php
/**
 * booking_mail.php — Operations → Booking mail.
 * Mail check (admin): open Booking follow-up lines crossed with the headers of glady@ / operations@; one thread can be
 * opened for a specific problem (reason required, logged) and answered from the same mailbox; a supplier's mail in it can be
 * saved as proof in the booking's mails/ and set the line's expiry ("Save as proof & set expiry", bm_save_proof()).
 * Passports (booking team, admin): read a booking's passports and write the confirmed rows in its Calc.
 * Off until BOOKING_MAIL_LIVE. Data: api/booking_mail.php.
 */
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login();
require_once __DIR__ . '/../leads/config.php';
if (!defined('AGENT_API_KEY') && is_file(__DIR__ . '/../../includes/config.php')) require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/includes/booking_mail_service.php';
hub_db_timezone($pdo);

$bmUser = current_user();
$bmAdmin = bm_can_read($bmUser);
$bmBooking = followup_can_edit($bmUser);
if (!$bmAdmin && !$bmBooking) { http_response_code(403); die('Booking team and admins only.'); }
$bmRequest = (int)($_GET['request_id'] ?? 0);
$page_title = 'Booking mail — Savannah Explorers Hub';

$extra_css = '
.bm-tabs{display:flex;gap:4px;border-bottom:1px solid var(--grey-lt);margin-bottom:16px}
.bm-tabs button{font-family:inherit;font-size:.8rem;font-weight:700;color:var(--grey-mid);background:none;border:none;border-bottom:3px solid transparent;padding:9px 14px;cursor:pointer}
.bm-tabs button.active{color:var(--red);border-bottom-color:var(--red)}
.bm-note{margin:0 0 14px;padding:12px 16px;border-radius:8px;font-size:.82rem;line-height:1.5;background:var(--navy-lt);color:#123a5c}
.bm-note.err{background:var(--red-lt);color:var(--red-dk)}
.bm-sub{font-size:.78rem;color:var(--grey-mid);margin-bottom:12px}
.bm-sig{display:inline-block;padding:2px 8px;border-radius:10px;font-size:.72rem;font-weight:700;background:var(--off-white);color:var(--grey-dk);white-space:nowrap}
.bm-sig.supplier_waiting,.bm-sig.contacted_no_mail{background:var(--red-lt);color:var(--red-dk)}
.bm-sig.no_mail{background:#fff8e1;color:#8a6d00}
.bm-msg{border:1px solid var(--grey-lt);border-radius:8px;padding:12px 14px;margin-bottom:10px;background:var(--white)}
.bm-msg .h{font-size:.76rem;color:var(--grey-dk);margin-bottom:8px;overflow-wrap:anywhere}
.bm-msg pre{white-space:pre-wrap;overflow-wrap:anywhere;font-family:inherit;font-size:.84rem;margin:0}
.bm-form label{display:block;font-size:.76rem;font-weight:700;margin:10px 0 4px}
.bm-form input,.bm-form textarea,.bm-form select{width:100%;box-sizing:border-box;padding:7px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:inherit;font-size:.85rem}
.bm-pp{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px;border:1px solid var(--grey-lt);border-radius:8px;padding:14px;margin-bottom:12px;background:var(--white)}
.bm-pp img,.bm-pp iframe{width:100%;max-height:420px;object-fit:contain;border:0}
.bm-pp iframe{height:420px}
.bm-src{display:flex;gap:10px;align-items:center;padding:7px 0;border-bottom:1px solid var(--grey-lt);font-size:.82rem}
.bm-src span{flex:1;min-width:0;overflow-wrap:anywhere}
.bm-actions{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap}
.bm-proof{margin-top:8px;padding:10px 12px;border:1.5px dashed var(--grey-lt);border-radius:8px;background:var(--off-white);max-width:460px}
.bm-proof[hidden]{display:none}
[hidden]{display:none!important}
@media(max-width:700px){.bm-pp{grid-template-columns:1fr}}
';
$extra_js = '<script>window.BM = ' . json_encode(['admin' => $bmAdmin, 'booking' => $bmBooking, 'live' => bm_live(), 'request_id' => $bmRequest], JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>'
          . '<script src="booking_mail.js?v=' . @filemtime(__DIR__ . '/booking_mail.js') . '"></script>';
include __DIR__ . '/../../includes/layout_header.php';
?>
<main>
  <div class="page-title">✉ Booking mail</div>
  <?php if (!bm_live()): ?>
  <div class="bm-note err">Not active yet. It starts when <code>BOOKING_MAIL_LIVE</code> is set on the server, once the staff notice has gone out.</div>
  <?php endif; ?>
  <div class="bm-note" id="bm-msg" hidden></div>
  <div class="bm-tabs" role="tablist">
    <?php if ($bmAdmin): ?><button type="button" data-tab="check" role="tab">Mail check</button><?php endif; ?>
    <button type="button" data-tab="passports" role="tab">Passports</button>
  </div>

  <?php if ($bmAdmin): ?>
  <section id="tab-check" hidden>
    <div class="bm-sub"><span id="bm-run"></span> "No mail" means to verify: phone, WhatsApp and supplier portals are not visible.
      <button type="button" class="btn btn-outline btn-sm" id="bm-scan">Read new mail now</button></div>
    <div class="card"><div class="table-wrap"><table class="data-table" id="bm-table"></table></div></div>
    <div id="bm-thread" hidden style="margin-top:18px">
      <h3 id="bm-thread-title" style="font-size:1rem"></h3>
      <div class="bm-form" id="bm-reason-box">
        <label for="bm-reason">Why are you opening this thread? (kept in the access log)</label>
        <input type="text" id="bm-reason" maxlength="500" placeholder="Lodge says it never received the rooming list">
        <div class="bm-actions"><button type="button" class="btn btn-primary btn-sm" id="bm-open">Open thread</button>
          <button type="button" class="btn btn-outline btn-sm" id="bm-close">Close</button></div>
      </div>
      <div id="bm-messages"></div>
      <div class="bm-form" id="bm-reply-box" hidden>
        <label for="bm-reply">Reply (from the same mailbox, signed Booking Team)</label>
        <textarea id="bm-reply" rows="6"></textarea>
        <div class="bm-actions"><button type="button" class="btn btn-outline btn-sm" id="bm-preview">Preview</button>
          <button type="button" class="btn btn-primary btn-sm" id="bm-send" disabled>Send</button></div>
        <div class="bm-sub" id="bm-preview-out" style="margin-top:10px;white-space:pre-wrap"></div>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <section id="tab-passports" hidden>
    <div class="bm-form" style="max-width:320px">
      <label for="bm-req">Request #</label><input type="number" id="bm-req" min="1" value="<?= $bmRequest ?: '' ?>">
      <div class="bm-actions"><button type="button" class="btn btn-primary btn-sm" id="bm-load">Load</button></div>
    </div>
    <div class="bm-sub" id="bm-pp-head" style="margin-top:14px"></div>
    <div id="bm-sources"></div>
    <div id="bm-rows" style="margin-top:14px"></div>
    <div class="bm-actions" id="bm-apply-box" hidden><button type="button" class="btn btn-primary btn-sm" id="bm-apply">Save files and write the Calc</button></div>
  </section>
</main>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
