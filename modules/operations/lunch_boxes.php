<?php
require_once __DIR__ . '/../../includes/auth.php';
require_permission('operations');
$page_title = 'Lunch Boxes — Savannah Explorers Hub';

$extra_css = '
/* ── Tabs ── */
.tab-nav{background:var(--black);display:flex;gap:0;padding:0 32px;overflow-x:auto;}
.tab-btn{font-family:"Open Sans",sans-serif;font-size:.75rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:13px 18px;border:none;background:transparent;color:rgba(255,255,255,.5);cursor:pointer;border-bottom:3px solid transparent;transition:all .2s;white-space:nowrap;}
.tab-btn:hover{color:rgba(255,255,255,.85);}
.tab-btn.active{color:var(--white);border-bottom-color:var(--red);}
.tab-panel{display:none;padding:32px 40px 60px;}
.tab-panel.active{display:block;}

/* ── Drop zone ── */
.drop-zone{border:2.5px dashed var(--grey-lt);border-radius:12px;padding:52px 32px;text-align:center;cursor:pointer;transition:all .2s;background:var(--white);}
.drop-zone:hover,.drop-zone.drag-over{border-color:var(--red);background:var(--red-lt);}
.drop-zone h3{font-family:"Merriweather",serif;font-size:1.05rem;color:var(--black);margin-bottom:6px;}
.drop-zone p{font-size:.8rem;color:var(--grey-mid);}
.drop-zone input[type=file]{display:none;}

/* ── Preview card ── */
#preview-section{display:none;margin-top:28px;}
.preview-card{background:var(--white);border-radius:10px;box-shadow:0 1px 8px rgba(0,0,0,.08);padding:24px 28px;}
.preview-card h3{font-family:"Merriweather",serif;font-size:.95rem;color:var(--red-dk);margin-bottom:18px;display:flex;align-items:center;gap:10px;}
.ph-grid{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:16px;margin-bottom:16px;}
@media(max-width:900px){.ph-grid{grid-template-columns:1fr 1fr;}}
.ph-field label{display:block;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);margin-bottom:4px;}
.ph-field input,.ph-field textarea{width:100%;padding:8px 12px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:"Open Sans",sans-serif;font-size:.88rem;color:var(--black);box-sizing:border-box;}
.ph-field input:focus,.ph-field textarea:focus{outline:none;border-color:var(--red);}
.ph-field textarea{resize:vertical;min-height:80px;}
.ph-full{grid-column:1/-1;}
.parsed-badge{font-size:.7rem;background:var(--green-lt);color:var(--green);padding:2px 8px;border-radius:10px;font-weight:600;}
.manual-badge{font-size:.7rem;background:var(--amber-lt);color:#7A4F01;padding:2px 8px;border-radius:10px;font-weight:600;}
.preview-actions{display:flex;gap:12px;align-items:center;margin-top:20px;flex-wrap:wrap;}
.parsed-from{font-size:.75rem;color:var(--grey-mid);margin-left:auto;}

/* ── Records ── */
.records-toolbar{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:22px;}
.records-toolbar .form-group{margin-bottom:0;}
.records-toolbar label{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);display:block;margin-bottom:4px;}
.records-toolbar input[type=date],.records-toolbar input[type=text]{padding:8px 12px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:"Open Sans",sans-serif;font-size:.85rem;color:var(--black);}
.records-toolbar input[type=text]{min-width:200px;}
.records-toolbar input:focus{outline:none;border-color:var(--red);}

.lb-table-wrap{background:var(--white);border-radius:10px;box-shadow:0 1px 8px rgba(0,0,0,.08);overflow:hidden;}
.lb-table{width:100%;border-collapse:collapse;font-size:.83rem;}
.lb-table th{background:var(--off-white);padding:10px 14px;text-align:left;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:var(--grey-mid);border-bottom:2px solid var(--grey-lt);}
.lb-table td{padding:10px 14px;border-bottom:1px solid var(--grey-lt);color:var(--grey-dk);vertical-align:top;}
.lb-table tr:last-child td{border-bottom:none;}
.lb-table tr:hover td{background:#FAFAFA;}
.extra-cell{max-width:280px;white-space:pre-wrap;font-size:.78rem;color:var(--grey-mid);}
.badge-pax{background:#EAE6F0;color:#4A3575;border-radius:10px;padding:1px 9px;font-size:.72rem;font-weight:700;white-space:nowrap;}
.badge-jeep{background:var(--green-lt);color:var(--green);border-radius:10px;padding:1px 9px;font-size:.72rem;font-weight:700;white-space:nowrap;}
.badge-hist{background:var(--amber-lt);color:#7A4F01;border-radius:10px;padding:1px 9px;font-size:.72rem;font-weight:700;white-space:nowrap;}
.action-btns{display:flex;gap:6px;white-space:nowrap;}

/* ── Export ── */
.export-card{background:var(--white);border-radius:10px;box-shadow:0 1px 8px rgba(0,0,0,.08);padding:28px 32px;max-width:520px;}
.export-card h3{font-family:"Merriweather",serif;font-size:1rem;color:var(--black);margin-bottom:6px;}
.export-card p{font-size:.82rem;color:var(--grey-mid);margin-bottom:22px;line-height:1.6;}
.export-row{display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap;}
.export-field label{display:block;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);margin-bottom:4px;}
.export-field input[type=date]{padding:10px 14px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:"Open Sans",sans-serif;font-size:.88rem;color:var(--black);}
.export-field input:focus{outline:none;border-color:var(--red);}

/* ── Modal ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal-box{background:var(--white);border-radius:12px;box-shadow:0 8px 40px rgba(0,0,0,.2);max-width:560px;width:92%;padding:32px;max-height:90vh;overflow-y:auto;}
.modal-box h3{font-family:"Merriweather",serif;font-size:1.05rem;color:var(--red-dk);margin-bottom:18px;}
.modal-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px;}
.modal-full{grid-column:1/-1;}
.modal-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px;}
.mf-label{display:block;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);margin-bottom:4px;}
.mf-ctrl{width:100%;padding:9px 12px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:"Open Sans",sans-serif;font-size:.88rem;color:var(--black);box-sizing:border-box;}
.mf-ctrl:focus{outline:none;border-color:var(--red);}
textarea.mf-ctrl{resize:vertical;min-height:90px;}

/* ── Toast ── */
.toast{position:fixed;bottom:24px;right:24px;padding:14px 20px;border-radius:8px;font-size:.82rem;font-weight:600;z-index:2000;transform:translateY(80px);opacity:0;transition:all .3s;box-shadow:0 4px 20px rgba(0,0,0,.15);}
.toast.show{transform:translateY(0);opacity:1;}
.toast.success{background:var(--green);color:var(--white);}
.toast.error{background:var(--red);color:var(--white);}
.toast.warning{background:var(--amber);color:var(--white);}

.empty-state{text-align:center;padding:48px 24px;color:var(--grey-mid);}
.empty-state .es-icon{font-size:2.5rem;margin-bottom:12px;}
.empty-state p{font-size:.85rem;}
.sheet-error{display:none;margin-top:16px;}
';
?>
<?php include __DIR__ . '/../../includes/layout_header.php'; ?>

<!-- ── Tab Nav ─────────────────────────────────────────────────────────────── -->
<div class="tab-nav">
  <button class="tab-btn active" data-tab="import">📥 Import</button>
  <button class="tab-btn" data-tab="records">🍱 Records</button>
  <button class="tab-btn" data-tab="history">📦 History</button>
  <button class="tab-btn" data-tab="export">📤 Export</button>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     TAB — IMPORT
     ════════════════════════════════════════════════════════════════════════════ -->
<div class="tab-panel active" id="tab-import">
  <div class="page-title">📥 Import Lunch Box File</div>

  <div class="drop-zone" id="dropZone" onclick="document.getElementById('fileInput').click()">
    <div style="font-size:2.2rem;margin-bottom:10px;">📂</div>
    <h3>Drop Safari Calc file here</h3>
    <p>or click to browse — accepts .xlsx / .xls<br>
       Sheet selection: RECAP › CONF › first sheet</p>
    <input type="file" id="fileInput" accept=".xlsx,.xls">
  </div>

  <div id="sheet-error" class="flash error sheet-error"></div>

  <!-- Preview section -->
  <div id="preview-section">
    <div class="preview-card">
      <h3>🍱 Extracted Data <span id="parse-badge" class="parsed-badge">Parsed</span></h3>

      <div class="ph-grid">
        <div class="ph-field" style="grid-column:1/3;">
          <label>Client Name <small style="text-transform:none;font-weight:400;">(from folder name)</small></label>
          <input type="text" id="ph-client" placeholder="e.g. Cinzia Biaggi">
        </div>
        <div class="ph-field">
          <label>Lunch Boxes <small style="text-transform:none;font-weight:400;">(pax + jeeps)</small></label>
          <input type="number" id="ph-travelers" min="1" max="999" placeholder="—">
        </div>
        <div class="ph-field">
          <label>Guide</label>
          <input type="text" id="ph-guide" placeholder="—">
        </div>
        <div class="ph-field">
          <label>Safari Date</label>
          <input type="date" id="ph-date">
        </div>
        <div class="ph-field" style="grid-column:1/3;">
          <label>Folder Name</label>
          <input type="text" id="ph-folder" placeholder="e.g. CinziaBiaggi(Samwel-Drct)">
        </div>
        <div class="ph-field ph-full">
          <label>Extra Details</label>
          <textarea id="ph-extra" placeholder="Content extracted from EXTRA section…"></textarea>
        </div>
        <div class="ph-field ph-full">
          <label>Notes</label>
          <input type="text" id="ph-notes" placeholder="Optional notes">
        </div>
      </div>

      <div class="preview-actions">
        <button class="btn btn-secondary btn-sm" onclick="clearPreview()">✕ Clear</button>
        <button class="btn btn-primary" id="btn-save" onclick="saveRecord()">💾 Save to Database</button>
        <span class="parsed-from" id="parsed-from-label"></span>
      </div>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     TAB — RECORDS
     ════════════════════════════════════════════════════════════════════════════ -->
<div class="tab-panel" id="tab-records">
  <div class="page-title" style="margin-bottom:20px;">🍱 Lunch Box Records</div>

  <div class="records-toolbar">
    <div class="form-group">
      <label>From</label>
      <input type="date" id="rec-from">
    </div>
    <div class="form-group">
      <label>To</label>
      <input type="date" id="rec-to">
    </div>
    <div class="form-group">
      <label>Search</label>
      <input type="text" id="rec-search" placeholder="Client name…" oninput="debounceRecords()">
    </div>
    <button class="btn btn-primary btn-sm" onclick="loadRecords()">🔍 Search</button>
    <button class="btn btn-secondary btn-sm" onclick="clearRecordFilters()">Reset</button>
  </div>

  <div id="records-container">
    <div class="empty-state"><div class="es-icon">🔍</div><p>Use the filters above to search records.</p></div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     TAB — HISTORY
     ════════════════════════════════════════════════════════════════════════════ -->
<div class="tab-panel" id="tab-history">
  <div class="page-title" style="margin-bottom:20px;">📦 Lunch Box History</div>

  <div class="records-toolbar">
    <div class="form-group">
      <label>From</label>
      <input type="date" id="hist-from">
    </div>
    <div class="form-group">
      <label>To</label>
      <input type="date" id="hist-to">
    </div>
    <div class="form-group">
      <label>Search</label>
      <input type="text" id="hist-search" placeholder="Client name…" oninput="debounceHistory()">
    </div>
    <button class="btn btn-primary btn-sm" onclick="loadHistory()">🔍 Search</button>
    <button class="btn btn-secondary btn-sm" onclick="clearHistoryFilters()">Reset</button>
  </div>

  <div id="history-container">
    <div class="empty-state"><div class="es-icon">📦</div><p>Use the filters above to browse history.</p></div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     TAB — EXPORT
     ════════════════════════════════════════════════════════════════════════════ -->
<div class="tab-panel" id="tab-export">
  <div class="page-title" style="margin-bottom:20px;">📤 Export Lunch Boxes</div>
  <div class="export-card">
    <h3>Generate Lunch Box Report</h3>
    <p>Exports all lunch box entries whose <strong>safari date</strong> falls within the selected range.</p>
    <div class="export-row">
      <div class="export-field">
        <label>From</label>
        <input type="date" id="exp-from">
      </div>
      <div class="export-field">
        <label>To</label>
        <input type="date" id="exp-to">
      </div>
      <button class="btn btn-primary" onclick="downloadReport(false)">⬇ Active Records</button>
      <button class="btn btn-secondary" onclick="downloadReport(true)">⬇ History</button>
    </div>
  </div>
</div>

<!-- ── Edit Modal ── -->
<div class="modal-overlay" id="edit-modal">
  <div class="modal-box">
    <h3>✏️ Edit Lunch Box Entry</h3>
    <input type="hidden" id="edit-id">
    <div class="modal-grid">
      <div class="modal-full">
        <label class="mf-label">Client Name</label>
        <input type="text" class="mf-ctrl" id="edit-client">
      </div>
      <div>
        <label class="mf-label">Lunch Boxes <small style="font-weight:400;text-transform:none;">(pax + jeeps)</small></label>
        <input type="number" class="mf-ctrl" id="edit-travelers" min="1" max="999">
      </div>
      <div>
        <label class="mf-label">Guide</label>
        <input type="text" class="mf-ctrl" id="edit-guide">
      </div>
      <div>
        <label class="mf-label">Safari Date</label>
        <input type="date" class="mf-ctrl" id="edit-date">
      </div>
      <div>
        <label class="mf-label">Folder Name</label>
        <input type="text" class="mf-ctrl" id="edit-folder">
      </div>
      <div class="modal-full">
        <label class="mf-label">Extra Details</label>
        <textarea class="mf-ctrl" id="edit-extra"></textarea>
      </div>
      <div class="modal-full">
        <label class="mf-label">Notes</label>
        <input type="text" class="mf-ctrl" id="edit-notes">
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
      <button class="btn btn-primary" id="edit-save-btn" onclick="saveEdit()">💾 Save</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<?php $extra_js = <<<'JSEOF'
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script><script>
/* ════════════════════════════════════════════════════════
   LUNCH BOXES — Client-side logic
   ════════════════════════════════════════════════════════ */

// ── Tabs ──────────────────────────────────────────────────────────────────────
document.querySelectorAll(".tab-btn").forEach(btn => {
    btn.addEventListener("click", () => {
        document.querySelectorAll(".tab-btn").forEach(b => b.classList.remove("active"));
        document.querySelectorAll(".tab-panel").forEach(p => p.classList.remove("active"));
        btn.classList.add("active");
        document.getElementById("tab-" + btn.dataset.tab).classList.add("active");
        if (btn.dataset.tab === "records") loadRecords();
        if (btn.dataset.tab === "history") loadHistory();
    });
});

// ── Drop Zone ─────────────────────────────────────────────────────────────────
const dropZone = document.getElementById("dropZone");
const fileInput = document.getElementById("fileInput");

dropZone.addEventListener("dragover",  e => { e.preventDefault(); dropZone.classList.add("drag-over"); });
dropZone.addEventListener("dragleave", () => dropZone.classList.remove("drag-over"));
dropZone.addEventListener("drop", e => {
    e.preventDefault(); dropZone.classList.remove("drag-over");
    const f = e.dataTransfer.files[0];
    if (f) handleFile(f);
});
fileInput.addEventListener("change", () => { if (fileInput.files[0]) handleFile(fileInput.files[0]); });

// ── Excel Parser ──────────────────────────────────────────────────────────────
function handleFile(file) {
    const reader = new FileReader();
    reader.onload = e => {
        try {
            const wb = XLSX.read(e.target.result, {type: "array"});
            const sheet = selectSheet(wb);
            if (!sheet) return;
            const rows = XLSX.utils.sheet_to_json(sheet, {header: 1, defval: "", raw: true});
            const extracted = parseLunchBox(rows);
            const { clientName, folderName } = suggestFromFilename(file.name);
            showPreview(extracted, clientName, folderName, file.name);
        } catch(ex) {
            showToast("Error reading file: " + ex.message, "error");
        }
    };
    reader.readAsArrayBuffer(file);
    fileInput.value = "";
}

function selectSheet(wb) {
    document.getElementById("sheet-error").style.display = "none";
    if (wb.SheetNames.length === 1) return wb.Sheets[wb.SheetNames[0]];
    const recapIdx = wb.SheetNames.findIndex(n => n.toUpperCase().includes("RECAP"));
    if (recapIdx >= 0) return wb.Sheets[wb.SheetNames[recapIdx]];
    const confIdx  = wb.SheetNames.findIndex(n => n.toUpperCase().includes("CONF"));
    if (confIdx  >= 0) return wb.Sheets[wb.SheetNames[confIdx]];
    // Fallback to first sheet with a warning
    const el = document.getElementById("sheet-error");
    el.textContent = `No RECAP or CONF sheet found (sheets: ${wb.SheetNames.join(", ")}). Using first sheet — please verify.`;
    el.style.display = "block";
    return wb.Sheets[wb.SheetNames[0]];
}

// ── Core extraction ───────────────────────────────────────────────────────────
function parseLunchBox(rows) {
    const result = {travelers: null, jeeps: null, guide: null, safariDate: null, extraDetails: null, parseFlags: []};

    // --- 1. TOT PAX, Number of Jeeps, Guide: label in col A, value in col B --
    for (let i = 0; i < rows.length; i++) {
        const colA = String(rows[i][0] || "").toLowerCase().trim();
        const colB = rows[i][1];

        if (result.travelers === null && colA === "tot pax") {
            const v = parseFloat(colB);
            if (!isNaN(v) && v > 0) { result.travelers = Math.round(v); result.parseFlags.push("pax:auto"); }
        }
        if (result.jeeps === null && colA.includes("number of jeep")) {
            const v = parseFloat(colB);
            if (!isNaN(v) && v > 0) { result.jeeps = Math.round(v); result.parseFlags.push("jeep:auto"); }
        }
        if (result.guide === null && colA === "guide") {
            const v = String(colB || "").trim();
            if (v) { result.guide = v; result.parseFlags.push("guide:auto"); }
        }
        if (result.travelers !== null && result.jeeps !== null && result.guide !== null) break;
    }

    // --- 2. Park fees date ---------------------------------------------------
    // Column "DATA" or "DATE" + column "PARK FEES" (exact or containing both words)
    let dateCol = -1, parkCol = -1, headerRowIdx = -1;

    for (let i = 0; i < rows.length; i++) {
        for (let j = 0; j < rows[i].length; j++) {
            const c = String(rows[i][j] || "").toLowerCase().trim();
            if ((c === "data" || c === "date") && dateCol < 0) dateCol = j;
            if ((c === "park fees" || c === "park fee" || (c.includes("park") && c.includes("fee"))) && parkCol < 0) parkCol = j;
        }
        if (dateCol >= 0 && parkCol >= 0 && headerRowIdx < 0) { headerRowIdx = i; break; }
    }

    if (dateCol >= 0 && parkCol >= 0 && headerRowIdx >= 0) {
        for (let i = headerRowIdx + 1; i < rows.length; i++) {
            const parkVal = rows[i][parkCol];
            const dateVal = rows[i][dateCol];
            if (parkVal !== "" && parkVal !== null && parkVal !== undefined) {
                const numVal = parseFloat(parkVal);
                const hasValue = (!isNaN(numVal) && numVal > 0) ||
                                 (isNaN(numVal) && String(parkVal).trim() &&
                                  !String(parkVal).trim().toLowerCase().includes("park"));
                if (hasValue) {
                    const d = parseFlexDate(dateVal);
                    if (d) { result.safariDate = d; result.parseFlags.push("date:auto"); break; }
                }
            }
        }
    }

    // Fallback: first date in date column
    if (!result.safariDate && dateCol >= 0 && headerRowIdx >= 0) {
        for (let i = headerRowIdx + 1; i < rows.length; i++) {
            const d = parseFlexDate(rows[i][dateCol]);
            if (d) { result.safariDate = d; result.parseFlags.push("date:fallback"); break; }
        }
    }

    // --- 3. EXTRA DETAILS — col A header, content below, stop at "NAME" -----
    let extraRowIdx = -1;
    for (let i = 0; i < rows.length; i++) {
        const colA = String(rows[i][0] || "").toLowerCase().trim();
        if (colA.startsWith("extra detail") || colA === "extra" || colA === "extras") {
            extraRowIdx = i;
            break;
        }
    }

    if (extraRowIdx >= 0) {
        const parts = [];
        for (let i = extraRowIdx + 1; i < rows.length; i++) {
            const firstCell = String(rows[i][0] || "").toLowerCase().trim();
            // Stop when we hit "NAME" row (traveler list begins)
            if (firstCell === "name" || firstCell.startsWith("name (")) break;
            // Also stop at other known section headers
            if (firstCell === "date" || firstCell === "tot pax") break;
            const rowText = rows[i].filter(c => c !== "" && c !== null && c !== undefined)
                                   .map(c => String(c).trim()).filter(Boolean).join("  ");
            if (rowText) parts.push(rowText);
        }
        if (parts.length) { result.extraDetails = parts.join("\n"); result.parseFlags.push("extra:auto"); }
    }

    return result;
}

// ── Client name + folder name from filename ───────────────────────────────────
function suggestFromFilename(filename) {
    let base = filename.replace(/\.[^.]+$/, "");
    base = base.replace(/__.*$/, "")
               .replace(/_(RECAP|CONF|recap|conf).*$/i, "")
               .replace(/_?\d{4}[-_]\d{2}[-_]\d{2}.*$/, "");

    // Folder name = NomeCognome(Agent-Type) pattern intact
    const folderMatch = base.match(/^([A-Za-zÀ-ÿ]+\([^)]+\))/);
    const folderName  = folderMatch ? folderMatch[1] : base.replace(/_/g, " ").trim();

    // Client name = part before parenthesis, CamelCase split
    const parMatch  = base.match(/^([^(]+)\(/);
    let clientPart  = parMatch ? parMatch[1].trim() : base;
    clientPart = clientPart.replace(/^[\d_]+/, "").replace(/_/g, " ").trim();
    const clientName = clientPart.replace(/([a-z])([A-Z])/g, "$1 $2")
                                  .replace(/([A-Z]+)([A-Z][a-z])/g, "$1 $2").trim();
    return { clientName, folderName };
}

// ── Date helpers ──────────────────────────────────────────────────────────────
function parseFlexDate(val) {
    if (val === "" || val === null || val === undefined) return "";
    // Excel serial number (number type from raw:true)
    if (typeof val === "number" && val > 1 && val < 100000) {
        // Convert Excel serial to date (Excel epoch: 1900-01-01, with Lotus bug)
        const d = new Date(Math.round((val - 25569) * 86400 * 1000));
        if (!isNaN(d) && d.getUTCFullYear() > 1990 && d.getUTCFullYear() < 2100) {
            return d.getUTCFullYear() + "-" +
                   String(d.getUTCMonth()+1).padStart(2,"0") + "-" +
                   String(d.getUTCDate()).padStart(2,"0");
        }
    }
    if (val instanceof Date && !isNaN(val)) return fmtIso(val);
    const str = String(val).trim();
    if (!str) return "";
    let m = str.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (m) return str.substring(0, 10);
    m = str.match(/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})/);
    if (m) return buildIso(m[3], m[2], m[1]);
    m = str.match(/^(\d{1,2})-(\d{1,2})-(\d{2,4})/);
    if (m) return buildIso(m[3], m[2], m[1]);
    m = str.match(/(\d{1,2})\s+([A-Za-z]+)\s+(\d{2,4})/);
    if (m) {
        const mo = {jan:1,feb:2,mar:3,apr:4,may:5,jun:6,jul:7,aug:8,sep:9,oct:10,nov:11,dec:12}[m[2].toLowerCase().substring(0,3)];
        if (mo) return buildIso(m[3], mo, m[1]);
    }
    return "";
}
function buildIso(y, m, d) {
    let year = parseInt(y); if (year < 100) year += 2000;
    return year + "-" + String(parseInt(m)).padStart(2,"0") + "-" + String(parseInt(d)).padStart(2,"0");
}
function fmtIso(dt) {
    return dt.getFullYear() + "-" + String(dt.getMonth()+1).padStart(2,"0") + "-" + String(dt.getDate()).padStart(2,"0");
}
function fmtDisplay(isoStr) {
    if (!isoStr) return "—";
    const [y,m,d] = isoStr.split("-");
    const months = ["","Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
    return parseInt(d) + " " + months[parseInt(m)] + " " + y;
}

// ── Preview ───────────────────────────────────────────────────────────────────
let currentFilename = "";

function showPreview(extracted, clientName, folderName, filename) {
    document.getElementById("ph-client").value    = clientName;
    document.getElementById("ph-date").value      = extracted.safariDate || "";
    const lunchBoxes = (extracted.travelers || 0) + (extracted.jeeps || 0);
    document.getElementById("ph-travelers").value = lunchBoxes > 0 ? lunchBoxes : "";
    document.getElementById("ph-guide").value     = extracted.guide || "";
    document.getElementById("ph-extra").value     = extracted.extraDetails || "";
    document.getElementById("ph-folder").value    = folderName;
    document.getElementById("ph-notes").value     = "";
    document.getElementById("parsed-from-label").textContent = "Parsed from: " + filename;

    const autoCount = extracted.parseFlags.length;
    const badge = document.getElementById("parse-badge");
    if (autoCount > 0) {
        badge.className = "parsed-badge";
        badge.textContent = autoCount + " field" + (autoCount !== 1 ? "s" : "") + " auto-extracted";
    } else {
        badge.className = "manual-badge";
        badge.textContent = "Manual entry needed";
    }

    currentFilename = filename;
    document.getElementById("preview-section").style.display = "block";
    document.getElementById("preview-section").scrollIntoView({behavior: "smooth", block: "start"});
}

function clearPreview() {
    document.getElementById("preview-section").style.display = "none";
    document.getElementById("sheet-error").style.display = "none";
    currentFilename = "";
}

// ── Save ──────────────────────────────────────────────────────────────────────
async function saveRecord() {
    const client = document.getElementById("ph-client").value.trim();
    if (!client) { showToast("Client name is required.", "error"); return; }

    const payload = {
        client_name:   client,
        safari_date:   document.getElementById("ph-date").value || null,
        travelers:     parseInt(document.getElementById("ph-travelers").value) || null,
        guide:         document.getElementById("ph-guide").value.trim() || null,
        jeeps:         null,
        extra_details: document.getElementById("ph-extra").value.trim() || null,
        folder_name:   document.getElementById("ph-folder").value.trim() || null,
        notes:         document.getElementById("ph-notes").value.trim() || null,
        source_file:   currentFilename || null,
    };

    const btn = document.getElementById("btn-save");
    btn.disabled = true; btn.textContent = "Saving…";
    try {
        const r    = await fetch("api/lb_save.php", {method:"POST", headers:{"Content-Type":"application/json"}, body:JSON.stringify(payload)});
        const data = await r.json();
        if (data.ok) {
            showToast("✅ Saved: " + data.client_name, "success");
            clearPreview();
        } else {
            showToast("Error: " + (data.error || "Unknown"), "error");
        }
    } catch(ex) {
        showToast("Network error: " + ex.message, "error");
    } finally {
        btn.disabled = false; btn.textContent = "💾 Save to Database";
    }
}

// ── Records Tab ───────────────────────────────────────────────────────────────
let _recTimer = null;
function debounceRecords() { clearTimeout(_recTimer); _recTimer = setTimeout(loadRecords, 400); }

async function loadRecords() {
    const from   = document.getElementById("rec-from").value;
    const to     = document.getElementById("rec-to").value;
    const search = document.getElementById("rec-search").value.trim();
    const params = new URLSearchParams();
    if (from)   params.set("from", from);
    if (to)     params.set("to", to);
    if (search) params.set("q", search);

    const container = document.getElementById("records-container");
    container.innerHTML = '<div class="empty-state"><div class="es-icon">⏳</div><p>Loading…</p></div>';
    try {
        const r    = await fetch("api/lb_list.php?" + params);
        const data = await r.json();
        renderTable(data.records || [], container, false);
    } catch(ex) {
        container.innerHTML = `<div class="flash error">Error loading records: ${ex.message}</div>`;
    }
}

function clearRecordFilters() {
    document.getElementById("rec-from").value = "";
    document.getElementById("rec-to").value   = "";
    document.getElementById("rec-search").value = "";
    document.getElementById("records-container").innerHTML =
        '<div class="empty-state"><div class="es-icon">🔍</div><p>Use the filters above to search records.</p></div>';
}

// ── History Tab ───────────────────────────────────────────────────────────────
let _histTimer = null;
function debounceHistory() { clearTimeout(_histTimer); _histTimer = setTimeout(loadHistory, 400); }

async function loadHistory() {
    const from   = document.getElementById("hist-from").value;
    const to     = document.getElementById("hist-to").value;
    const search = document.getElementById("hist-search").value.trim();
    const params = new URLSearchParams({history: 1});
    if (from)   params.set("from", from);
    if (to)     params.set("to", to);
    if (search) params.set("q", search);

    const container = document.getElementById("history-container");
    container.innerHTML = '<div class="empty-state"><div class="es-icon">⏳</div><p>Loading…</p></div>';
    try {
        const r    = await fetch("api/lb_list.php?" + params);
        const data = await r.json();
        renderTable(data.records || [], container, true);
    } catch(ex) {
        container.innerHTML = `<div class="flash error">Error: ${ex.message}</div>`;
    }
}

function clearHistoryFilters() {
    document.getElementById("hist-from").value   = "";
    document.getElementById("hist-to").value     = "";
    document.getElementById("hist-search").value = "";
    document.getElementById("history-container").innerHTML =
        '<div class="empty-state"><div class="es-icon">📦</div><p>Use the filters above to browse history.</p></div>';
}

// ── Render table ──────────────────────────────────────────────────────────────
function renderTable(records, container, isHistory) {
    if (!records.length) {
        container.innerHTML = '<div class="empty-state"><div class="es-icon">📭</div><p>No records found.</p></div>';
        return;
    }
    const rows = records.map(r => {
        const extra = r.extra_details
            ? `<div class="extra-cell">${esc(r.extra_details.substring(0, 200))}</div>`
            : '<span style="color:var(--grey-lt)">—</span>';
        const actions = isHistory
            ? `<div class="action-btns">
                 <button class="btn btn-danger btn-sm" onclick="deleteRecord(${r.id}, this, true)">🗑 Delete</button>
               </div>`
            : `<div class="action-btns">
                 <button class="btn btn-secondary btn-sm" onclick='openEditModal(${JSON.stringify(r)})'>✏️ Edit</button>
                 <button class="btn btn-secondary btn-sm"
                         onclick="stageToHistory(${r.id}, this)"
                         style="background:var(--amber-lt);color:#7A4F01;border:1px solid #E87722;"
                         title="Stage to History">📦</button>
                 <button class="btn btn-danger btn-sm" onclick="deleteRecord(${r.id}, this, false)">✕</button>
               </div>`;
        const histBadge = isHistory ? `<span class="badge-hist">📦 ${fmtDisplay(r.archived_at ? r.archived_at.substring(0,10) : '')}</span>` : '';
        return `
        <tr id="lb-row-${r.id}">
          <td>${fmtDisplay(r.safari_date)}</td>
          <td><strong>${esc(r.client_name)}</strong>${histBadge ? '<br>' + histBadge : ''}
              ${r.folder_name ? '<br><small style="color:var(--grey-mid)">' + esc(r.folder_name) + '</small>' : ''}</td>
          <td>${r.travelers !== null ? `<span class="badge-pax">🍱 ${r.travelers}</span>` : '—'}</td>
          <td>${r.guide ? esc(r.guide) : '—'}</td>
          <td>${extra}</td>
          <td>${r.notes ? '<small>' + esc(r.notes) + '</small>' : '—'}</td>
          <td>${actions}</td>
        </tr>`;
    }).join("");

    container.innerHTML = `
    <div class="lb-table-wrap">
      <table class="lb-table">
        <thead><tr>
          <th style="width:110px">Safari Date</th>
          <th style="width:180px">Client</th>
          <th style="width:100px">Lunch Boxes</th>
          <th style="width:110px">Guide</th>
          <th>Extra Details</th>
          <th style="width:120px">Notes</th>
          <th style="width:140px"></th>
        </tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>`;
}

// ── Delete ────────────────────────────────────────────────────────────────────
async function deleteRecord(id, btn, isHistory) {
    if (!confirm("Delete this record? This cannot be undone.")) return;
    btn.disabled = true;
    try {
        const r = await fetch("api/lb_delete.php", {
            method:"POST", headers:{"Content-Type":"application/json"},
            body: JSON.stringify({id, history: !!isHistory})
        });
        const data = await r.json();
        if (data.ok) {
            document.getElementById("lb-row-" + id)?.remove();
            showToast("Record deleted.", "success");
        } else {
            showToast("Error: " + (data.error || ""), "error");
        }
    } catch(ex) { showToast("Network error.", "error"); }
    finally { btn.disabled = false; }
}

// ── Stage to History ──────────────────────────────────────────────────────────
async function stageToHistory(id, btn) {
    if (!confirm("Move this record to History?")) return;
    btn.disabled = true;
    try {
        const r = await fetch("api/lb_history.php", {
            method:"POST", headers:{"Content-Type":"application/json"},
            body: JSON.stringify({mode:"stage", id})
        });
        const data = await r.json();
        if (data.ok) {
            document.getElementById("lb-row-" + id)?.remove();
            showToast("📦 Staged to History.", "success");
        } else {
            showToast("Error: " + (data.error || ""), "error");
        }
    } catch(ex) { showToast("Network error.", "error"); }
    finally { btn.disabled = false; }
}

// ── Edit Modal ────────────────────────────────────────────────────────────────
function openEditModal(r) {
    document.getElementById("edit-id").value        = r.id;
    document.getElementById("edit-client").value    = r.client_name  || "";
    document.getElementById("edit-date").value      = r.safari_date  || "";
    document.getElementById("edit-folder").value    = r.folder_name  || "";
    document.getElementById("edit-travelers").value = r.travelers !== null ? r.travelers : "";
    document.getElementById("edit-guide").value     = r.guide || "";
    document.getElementById("edit-extra").value     = r.extra_details || "";
    document.getElementById("edit-notes").value     = r.notes || "";
    document.getElementById("edit-modal").classList.add("open");
}

function closeEditModal() { document.getElementById("edit-modal").classList.remove("open"); }

async function saveEdit() {
    const client = document.getElementById("edit-client").value.trim();
    if (!client) { showToast("Client name is required.", "error"); return; }

    const payload = {
        id:            parseInt(document.getElementById("edit-id").value),
        client_name:   client,
        safari_date:   document.getElementById("edit-date").value || null,
        folder_name:   document.getElementById("edit-folder").value.trim() || null,
        travelers:     parseInt(document.getElementById("edit-travelers").value) || null,
        guide:         document.getElementById("edit-guide").value.trim() || null,
        jeeps:         null,
        extra_details: document.getElementById("edit-extra").value.trim() || null,
        notes:         document.getElementById("edit-notes").value.trim() || null,
    };

    const btn = document.getElementById("edit-save-btn");
    btn.disabled = true; btn.textContent = "Saving…";
    try {
        const r    = await fetch("api/lb_update.php", {method:"POST", headers:{"Content-Type":"application/json"}, body:JSON.stringify(payload)});
        const data = await r.json();
        if (data.ok) {
            // Update DOM row
            const tr = document.getElementById("lb-row-" + payload.id);
            if (tr) {
                const cells = tr.querySelectorAll("td");
                cells[0].textContent = fmtDisplay(payload.safari_date);
                cells[1].innerHTML = `<strong>${esc(payload.client_name)}</strong>` +
                    (payload.folder_name ? `<br><small style="color:var(--grey-mid)">${esc(payload.folder_name)}</small>` : "");
                cells[2].innerHTML = payload.travelers ? `<span class="badge-pax">🍱 ${payload.travelers}</span>` : "—";
                cells[3].textContent = payload.guide || "—";
                cells[4].innerHTML = payload.extra_details
                    ? `<div class="extra-cell">${esc(payload.extra_details.substring(0,200))}</div>` : '<span style="color:var(--grey-lt)">—</span>';
                cells[5].innerHTML = payload.notes ? `<small>${esc(payload.notes)}</small>` : "—";
            }
            closeEditModal();
            showToast("Record updated.", "success");
        } else {
            showToast("Error: " + (data.error || ""), "error");
        }
    } catch(ex) { showToast("Network error.", "error"); }
    finally { btn.disabled = false; btn.textContent = "💾 Save"; }
}

// ── Export ────────────────────────────────────────────────────────────────────
function downloadReport(history) {
    const from = document.getElementById("exp-from").value;
    const to   = document.getElementById("exp-to").value;
    if (!from || !to) { showToast("Please select both From and To dates.", "error"); return; }
    if (from > to)    { showToast("From date must be before To date.", "error"); return; }
    window.location.href = `api/lb_report.php?from=${from}&to=${to}&history=${history ? 1 : 0}`;
}

// ── Toast / Utils ─────────────────────────────────────────────────────────────
function showToast(msg, type="success") {
    const t = document.getElementById("toast");
    t.textContent = msg; t.className = "toast " + type + " show";
    setTimeout(() => t.classList.remove("show"), 4500);
}
function esc(s) {
    if (!s) return "";
    return String(s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");
}
</script>
JSEOF;
?>
<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
