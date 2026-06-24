<?php
// modules/memo/index.php — private Memo Board (post-it style)
date_default_timezone_set('Africa/Dar_es_Salaam');

require_once __DIR__ . '/../../includes/auth.php';   // adjust to your auth/header bootstrap
// If your Hub uses a shared header that opens <html><body> and the nav, include it here:
// require_once __DIR__ . '/../../includes/header.php';
?>
<!-- If header.php already outputs <head>, move this <link> into the shared <head>. -->
<link rel="stylesheet" href="memo.css">

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
    <textarea id="m_body" rows="4" placeholder="Details (optional)"></textarea>

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
      <div>
        <label>Reminder (date &amp; time)</label>
        <input type="datetime-local" id="m_reminder_at">
      </div>
      <div>
        <label>Repeat</label>
        <select id="m_recur_rule">
          <option value="none">No repeat</option>
          <option value="daily">Daily</option>
          <option value="weekly">Weekly</option>
          <option value="monthly">Monthly</option>
        </select>
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

<script src="memo.js"></script>

<?php
// require_once __DIR__ . '/../../includes/footer.php';
