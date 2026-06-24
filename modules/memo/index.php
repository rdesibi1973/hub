<?php
require_once __DIR__ . '/../../includes/auth.php';
require_login();

$page_title = 'Memo Board';
$extra_css = '
.memo-wrap { padding: 24px 40px; }
.memo-topbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.memo-topbar h2 { margin: 0; font-family: "Merriweather", serif; color: var(--red-dk); font-size: 1.3rem; }
.memo-board { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-start; }
.memo-empty { color: #888; font-style: italic; }
.memo-card { width: 220px; min-height: 120px; background: #FFF59D; border-radius: 4px; padding: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.18); cursor: grab; position: relative; display: flex; flex-direction: column; transition: box-shadow .15s; }
.memo-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.25); }
.memo-card.dragging { opacity: .5; }
.memo-card.is-pinned { box-shadow: 0 0 0 2px #C0211B, 0 2px 6px rgba(0,0,0,0.18); }
.memo-card.is-done { opacity: .6; }
.memo-card.is-done .memo-card-title { text-decoration: line-through; }
.memo-card.prio-high::before { content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: #C0211B; border-radius: 4px 0 0 4px; }
.memo-card.prio-low::before { content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: #9e9e9e; border-radius: 4px 0 0 4px; }
.memo-card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
.memo-type-badge { font-size: 10px; text-transform: uppercase; letter-spacing: .5px; background: rgba(0,0,0,0.12); color: #333; padding: 2px 6px; border-radius: 10px; }
.memo-pin { background: none; border: none; cursor: pointer; font-size: 16px; color: #C0211B; line-height: 1; padding: 0; }
.memo-card-title { font-weight: 700; color: #2b2b2b; word-wrap: break-word; }
.memo-card-body { margin-top: 6px; font-size: 12px; color: #444; word-wrap: break-word; flex: 1; overflow: hidden; max-height: 80px; }
.memo-card-body p { margin: 0 0 2px; }
.memo-card-meta { margin-top: 8px; display: flex; flex-wrap: wrap; gap: 6px; }
.memo-meta-item { font-size: 11px; color: #555; background: rgba(255,255,255,0.5); padding: 2px 6px; border-radius: 4px; }
.memo-card-actions { margin-top: 10px; display: flex; gap: 6px; }
.memo-mini { font-size: 12px; border: none; cursor: pointer; background: rgba(0,0,0,0.10); color: #333; padding: 3px 8px; border-radius: 4px; }
.memo-mini:hover { background: rgba(0,0,0,0.18); }
.memo-btn { border: none; cursor: pointer; padding: 8px 14px; border-radius: 4px; background: #e0e0e0; color: #333; font-size: 14px; font-family: inherit; }
.memo-btn:hover { background: #d5d5d5; }
.memo-btn-primary { background: #C0211B; color: #fff; }
.memo-btn-primary:hover { background: #a51b16; }
.memo-btn-danger { background: #fff; color: #C0211B; border: 1px solid #C0211B; }
.memo-btn-danger:hover { background: #fdecea; }
.memo-modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 9999; }
.memo-modal { background: #fff; border-radius: 8px; padding: 22px; width: 520px; max-width: 94vw; max-height: 90vh; overflow-y: auto; box-shadow: 0 8px 30px rgba(0,0,0,0.3); }
.memo-modal h3 { margin: 0 0 14px; color: var(--red-dk); font-family: "Merriweather", serif; }
.memo-modal label { display: block; font-size: 12px; color: #666; margin: 10px 0 4px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
.memo-modal input[type="text"], .memo-modal input[type="date"], .memo-modal select { width: 100%; box-sizing: border-box; padding: 7px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; font-family: inherit; }
.memo-row { display: flex; gap: 10px; }
.memo-row > div { flex: 1; }
.memo-time-row { display: flex; gap: 6px; align-items: center; }
.memo-time-row input[type="date"] { flex: 2; }
.memo-time-row select { flex: 1; }
.memo-time-sep { color: #888; font-weight: 700; flex-shrink: 0; }
.memo-modal-actions { display: flex; align-items: center; gap: 8px; margin-top: 18px; }
.memo-quill .ql-toolbar { border-radius: 4px 4px 0 0; border-color: #ccc; }
.memo-quill .ql-container { border-radius: 0 0 4px 4px; border-color: #ccc; }
.memo-quill .ql-editor { min-height: 90px; font-size: 14px; font-family: "Open Sans", sans-serif; }
';

include __DIR__ . '/../../includes/layout_header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.snow.css">

<div class="memo-wrap">
  <div class="memo-topbar">
    <h2>Memo Board</h2>
    <button id="memoNewBtn" class="memo-btn memo-btn-primary">+ New memo</button>
  </div>

  <div id="memoBoard" class="memo-board">
    <!-- cards injected by memo.js -->
  </div>
</div>

<!-- Editor modal -->
<div id="memoModal" class="memo-modal-overlay" style="display:none;">
  <div class="memo-modal">
    <h3 id="memoModalTitle">New memo</h3>
    <input type="hidden" id="m_id" value="">

    <label>Title</label>
    <input type="text" id="m_title" maxlength="255" placeholder="Short title">

    <label>Notes</label>
    <div id="m_body_editor" class="memo-quill"></div>
    <textarea id="m_body" style="display:none"></textarea>

    <div class="memo-row">
      <div>
        <label>Type</label>
        <select id="m_type">
          <option value="memo">Memo</option>
          <option value="todo">To-do</option>
          <option value="note">Note</option>
        </select>
      </div>
      <div>
        <label>Priority</label>
        <select id="m_priority">
          <option value="low">Low</option>
          <option value="normal" selected>Normal</option>
          <option value="high">High</option>
        </select>
      </div>
      <div>
        <label>Color</label>
        <select id="m_color">
          <option value="">Default</option>
          <option value="#FFF59D">Yellow</option>
          <option value="#FFCC80">Orange</option>
          <option value="#A5D6A7">Green</option>
          <option value="#90CAF9">Blue</option>
          <option value="#F48FB1">Pink</option>
          <option value="#CE93D8">Purple</option>
        </select>
      </div>
    </div>

    <div class="memo-row">
      <div>
        <label>Due date</label>
        <input type="date" id="m_due_date">
      </div>
      <div style="flex:2;">
        <label>Reminder</label>
        <div class="memo-time-row">
          <input type="date" id="m_reminder_date">
          <select id="m_reminder_hour"><?php
            for ($i = 0; $i < 24; $i++) {
                printf('<option value="%02d"%s>%02d</option>', $i, $i === 9 ? ' selected' : '', $i);
            }
          ?></select>
          <span class="memo-time-sep">:</span>
          <select id="m_reminder_min"><?php
            foreach ([0, 15, 30, 45] as $m) {
                printf('<option value="%02d">%02d</option>', $m, $m);
            }
          ?></select>
          <select id="m_recur_rule">
            <option value="none">Once</option>
            <option value="daily">Daily</option>
            <option value="weekly">Weekly</option>
            <option value="monthly">Monthly</option>
          </select>
        </div>
      </div>
    </div>

    <div class="memo-modal-actions">
      <button id="memoDeleteBtn" class="memo-btn memo-btn-danger" style="display:none;">Delete</button>
      <span style="flex:1;"></span>
      <button id="memoCancelBtn" class="memo-btn">Cancel</button>
      <button id="memoSaveBtn" class="memo-btn memo-btn-primary">Save</button>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.min.js"></script>
<script src="memo.js"></script>

<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
