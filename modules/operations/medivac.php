<?php
require_once __DIR__ . '/../../includes/auth.php';
require_permission('operations');
$page_title = 'Medivac — Savannah Explorers Hub';

$extra_css = '
/* ── Tabs ── */
.tab-nav{background:var(--black);display:flex;gap:0;padding:0 32px;}
.tab-btn{font-family:"Open Sans",sans-serif;font-size:.75rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:13px 18px;border:none;background:transparent;color:rgba(255,255,255,.5);cursor:pointer;border-bottom:3px solid transparent;transition:all .2s;white-space:nowrap;}
.tab-btn:hover{color:rgba(255,255,255,.85);}
.tab-btn.active{color:var(--white);border-bottom-color:var(--red);}
.tab-panel{display:none;padding:32px 40px 60px;}
.tab-panel.active{display:block;}

/* ── Drop zone ── */
.drop-zone{border:2.5px dashed var(--grey-lt);border-radius:12px;padding:52px 32px;text-align:center;cursor:pointer;transition:all .2s;background:var(--white);}
.drop-zone:hover,.drop-zone.drag-over{border-color:var(--red);background:var(--red-lt);}
.drop-zone .dz-icon{font-size:2.8rem;margin-bottom:12px;}
.drop-zone h3{font-family:"Merriweather",serif;font-size:1.05rem;color:var(--black);margin-bottom:6px;}
.drop-zone p{font-size:.8rem;color:var(--grey-mid);}
.drop-zone input[type=file]{display:none;}

/* ── Preview ── */
#preview-section{display:none;margin-top:28px;}
.preview-header{background:var(--white);border-radius:10px;box-shadow:0 1px 8px rgba(0,0,0,.08);padding:20px 24px;margin-bottom:20px;}
.ph-grid{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:16px;}
@media(max-width:900px){.ph-grid{grid-template-columns:1fr 1fr;}}
.ph-field label{display:block;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);margin-bottom:4px;}
.ph-field input{width:100%;padding:8px 12px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:"Open Sans",sans-serif;font-size:.88rem;color:var(--black);}
.ph-field input:focus{outline:none;border-color:var(--red);}

.travelers-card{background:var(--white);border-radius:10px;box-shadow:0 1px 8px rgba(0,0,0,.08);overflow:hidden;}
.travelers-card-header{background:var(--black);padding:12px 18px;display:flex;align-items:center;gap:12px;}
.travelers-card-header span{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.7);}
.travelers-card-header .tcount{background:var(--red);color:var(--white);border-radius:20px;padding:2px 10px;font-size:.7rem;font-weight:700;}

.preview-table{width:100%;border-collapse:collapse;font-size:.82rem;}
.preview-table th{background:var(--off-white);padding:9px 10px;text-align:left;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:var(--grey-mid);border-bottom:2px solid var(--grey-lt);}
.preview-table td{padding:5px 6px;border-bottom:1px solid var(--grey-lt);vertical-align:middle;}
.preview-table tr:last-child td{border-bottom:none;}
.preview-table tr.dup-row td{background:#FFF8E1;}
.preview-table tr.dup-row td:first-child{border-left:3px solid var(--amber);}
.pt-input{width:100%;padding:5px 8px;border:1.5px solid transparent;border-radius:5px;font-family:"Open Sans",sans-serif;font-size:.82rem;color:var(--black);background:transparent;transition:border-color .15s;}
.pt-input:focus{outline:none;border-color:var(--red);background:var(--white);}
.pt-select{padding:5px 6px;border:1.5px solid transparent;border-radius:5px;font-family:"Open Sans",sans-serif;font-size:.82rem;color:var(--black);background:transparent;cursor:pointer;}
.pt-select:focus{outline:none;border-color:var(--red);background:var(--white);}
.btn-del-row{background:none;border:none;cursor:pointer;color:var(--grey-mid);font-size:1rem;padding:2px 6px;border-radius:4px;transition:color .15s;}
.btn-del-row:hover{color:var(--red);}
.dup-badge{background:var(--amber-lt);color:#7A4F01;font-size:.62rem;font-weight:700;padding:2px 7px;border-radius:10px;white-space:nowrap;}

.preview-actions{display:flex;gap:12px;align-items:center;margin-top:20px;flex-wrap:wrap;}
.parsed-from{font-size:.75rem;color:var(--grey-mid);margin-left:auto;}

/* ── Dup modal ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal-box{background:var(--white);border-radius:12px;box-shadow:0 8px 40px rgba(0,0,0,.2);max-width:560px;width:92%;padding:32px;}
.modal-box h3{font-family:"Merriweather",serif;font-size:1.05rem;color:var(--red-dk);margin-bottom:12px;}
.modal-box p{font-size:.85rem;color:var(--grey-dk);margin-bottom:16px;line-height:1.6;}
.dup-list{background:var(--off-white);border-radius:6px;padding:12px 16px;margin-bottom:20px;font-size:.8rem;line-height:2;}
.modal-actions{display:flex;gap:10px;flex-wrap:wrap;}

/* ── Records ── */
.records-toolbar{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:22px;}
.records-toolbar .form-group{margin-bottom:0;}
.records-toolbar label{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);display:block;margin-bottom:4px;}
.records-toolbar input[type=date],.records-toolbar input[type=text]{padding:8px 12px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:"Open Sans",sans-serif;font-size:.85rem;color:var(--black);}
.records-toolbar input[type=text]{min-width:220px;}
.records-toolbar input:focus{outline:none;border-color:var(--red);}

.group-card{background:var(--white);border-radius:10px;box-shadow:0 1px 8px rgba(0,0,0,.08);margin-bottom:16px;overflow:hidden;}
.group-card-head{padding:14px 20px;display:flex;align-items:center;gap:12px;cursor:pointer;user-select:none;transition:background .15s;}
.group-card-head:hover{background:var(--off-white);}
.gc-name{font-family:"Merriweather",serif;font-size:.95rem;font-weight:700;color:var(--red-dk);}
.gc-meta{font-size:.75rem;color:var(--grey-mid);display:flex;gap:14px;flex-wrap:wrap;margin-top:3px;}
.gc-count{background:var(--red-lt);color:var(--red-dk);border-radius:20px;padding:2px 10px;font-size:.68rem;font-weight:700;margin-left:auto;flex-shrink:0;}
.gc-toggle{color:var(--grey-mid);font-size:.85rem;transition:transform .2s;flex-shrink:0;}
.gc-toggle.open{transform:rotate(180deg);}
.group-card-body{display:none;border-top:1px solid var(--grey-lt);}
.group-card-body.open{display:block;}
.group-card-body table{width:100%;border-collapse:collapse;font-size:.8rem;}
.group-card-body th{background:var(--off-white);padding:8px 14px;text-align:left;font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:var(--grey-mid);}
.group-card-body td{padding:9px 14px;border-bottom:1px solid var(--grey-lt);color:var(--grey-dk);vertical-align:middle;}
.group-card-body tr:last-child td{border-bottom:none;}
.group-card-body tr:hover td{background:#FAFAFA;}
.gc-foot{padding:10px 16px;background:var(--off-white);display:flex;gap:8px;}

/* ── Export ── */
.export-card{background:var(--white);border-radius:10px;box-shadow:0 1px 8px rgba(0,0,0,.08);padding:28px 32px;max-width:520px;}
.export-card h3{font-family:"Merriweather",serif;font-size:1rem;color:var(--black);margin-bottom:6px;}
.export-card p{font-size:.82rem;color:var(--grey-mid);margin-bottom:22px;line-height:1.6;}
.export-row{display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap;}
.export-field label{display:block;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);margin-bottom:4px;}
.export-field input[type=date]{padding:10px 14px;border:1.5px solid var(--grey-lt);border-radius:6px;font-family:"Open Sans",sans-serif;font-size:.88rem;color:var(--black);}
.export-field input:focus{outline:none;border-color:var(--red);}
.export-note{margin-top:16px;font-size:.75rem;color:var(--grey-mid);background:var(--off-white);padding:10px 14px;border-radius:6px;}

/* ── Toast ── */
.toast{position:fixed;bottom:24px;right:24px;padding:14px 20px;border-radius:8px;font-size:.82rem;font-weight:600;z-index:2000;transform:translateY(80px);opacity:0;transition:all .3s;box-shadow:0 4px 20px rgba(0,0,0,.15);}
.toast.show{transform:translateY(0);opacity:1;}
.toast.success{background:var(--green);color:var(--white);}
.toast.error{background:var(--red);color:var(--white);}
.toast.warning{background:var(--amber);color:var(--white);}

.empty-state{text-align:center;padding:48px 24px;color:var(--grey-mid);}
.empty-state .es-icon{font-size:2.5rem;margin-bottom:12px;}
.empty-state p{font-size:.85rem;}
';
?>
<?php include __DIR__ . '/../../includes/layout_header.php'; ?>

<!-- ── Tab Nav ─────────────────────────────────────────────────────────────── -->
<div class="tab-nav">
  <button class="tab-btn active" data-tab="import">📥 Import</button>
  <button class="tab-btn" data-tab="records">📋 Records</button>
  <button class="tab-btn" data-tab="export">📤 Export</button>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     TAB — IMPORT
     ════════════════════════════════════════════════════════════════════════════ -->
<div class="tab-panel active" id="tab-import">
  <div class="page-title">📥 Import Traveler List</div>

  <!-- Drop zone -->
  <div class="drop-zone" id="dropZone" onclick="document.getElementById('fileInput').click()">
    <div class="dz-icon">🦁</div>
    <h3>Drop Safari Calc file here</h3>
    <p>or click to browse — accepts .xlsx / .xls</p>
    <input type="file" id="fileInput" accept=".xlsx,.xls">
  </div>

  <!-- Preview section (hidden until parsed) -->
  <div id="preview-section">
    <div class="preview-header">
      <div class="ph-grid">
        <div class="ph-field">
          <label>Group Name</label>
          <input type="text" id="ph-group" placeholder="e.g. Cinzia Biaggi">
        </div>
        <div class="ph-field">
          <label>Tour Agent</label>
          <input type="text" id="ph-agent" placeholder="Agent name">
        </div>
        <div class="ph-field">
          <label>Coverage Start</label>
          <input type="date" id="ph-start">
        </div>
        <div class="ph-field">
          <label>Coverage End</label>
          <input type="date" id="ph-end">
        </div>
      </div>
    </div>

    <div class="travelers-card">
      <div class="travelers-card-header">
        <span>Travelers</span>
        <span class="tcount" id="traveler-count">0</span>
      </div>
      <div style="overflow-x:auto;">
        <table class="preview-table" id="preview-table">
          <thead>
            <tr>
              <th style="width:26%">Full Name</th>
              <th style="width:8%">Title</th>
              <th style="width:13%">Date of Birth</th>
              <th style="width:16%">Passport #</th>
              <th style="width:14%">Country / Nationality</th>
              <th style="width:7%"></th>
            </tr>
          </thead>
          <tbody id="preview-tbody"></tbody>
        </table>
      </div>
    </div>

    <div class="preview-actions">
      <button class="btn btn-secondary btn-sm" onclick="addBlankRow()">+ Add Row</button>
      <button class="btn btn-secondary btn-sm" onclick="clearPreview()">✕ Clear</button>
      <button class="btn btn-primary" id="btn-save" onclick="saveTravelers()">💾 Save to Database</button>
      <span class="parsed-from" id="parsed-from-label"></span>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     TAB — RECORDS
     ════════════════════════════════════════════════════════════════════════════ -->
<div class="tab-panel" id="tab-records">
  <div class="page-title" style="margin-bottom:20px;">📋 Traveler Records</div>

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
      <input type="text" id="rec-search" placeholder="Name or group…" oninput="debounceRecords()">
    </div>
    <button class="btn btn-primary btn-sm" onclick="loadRecords()">🔍 Search</button>
    <button class="btn btn-secondary btn-sm" onclick="clearRecordFilters()">Reset</button>
  </div>

  <div id="records-container">
    <div class="empty-state"><div class="es-icon">🔍</div><p>Use the filters above to search records.</p></div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     TAB — EXPORT
     ════════════════════════════════════════════════════════════════════════════ -->
<div class="tab-panel" id="tab-export">
  <div class="page-title" style="margin-bottom:20px;">📤 Export Medivac Report</div>
  <div class="export-card">
    <h3>Generate Medivac Tracker</h3>
    <p>Exports all travelers whose <strong>coverage start date</strong> falls within the selected date range, in the Arusha Medivac format.</p>
    <div class="export-row">
      <div class="export-field">
        <label>From</label>
        <input type="date" id="exp-from">
      </div>
      <div class="export-field">
        <label>To</label>
        <input type="date" id="exp-to">
      </div>
      <button class="btn btn-primary" onclick="downloadReport()">⬇ Download Excel</button>
    </div>
    <div class="export-note">
      ℹ️ Insurance Name and Policy # fields will be blank unless manually filled in Records.
    </div>
  </div>
</div>

<!-- ── Duplicate Warning Modal ─────────────────────────────────────────────── -->
<div class="modal-overlay" id="dup-modal">
  <div class="modal-box">
    <h3>⚠️ Possible Duplicates Found</h3>
    <p>The following travelers already exist in the database:</p>
    <div class="dup-list" id="dup-list"></div>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="closeDupModal()">Cancel</button>
      <button class="btn btn-secondary" onclick="saveWithSkip()">Skip Duplicates &amp; Save New</button>
      <button class="btn btn-primary"   onclick="saveWithForce()">Save All Anyway</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<?php $extra_js = <<<'JSEOF'
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script><script>
/* ════════════════════════════════════════════════════════
   MEDIVAC — Client-side logic
   ════════════════════════════════════════════════════════ */

// ── Tabs ──────────────────────────────────────────────────────────────────────
document.querySelectorAll(".tab-btn").forEach(btn => {
    btn.addEventListener("click", () => {
        document.querySelectorAll(".tab-btn").forEach(b => b.classList.remove("active"));
        document.querySelectorAll(".tab-panel").forEach(p => p.classList.remove("active"));
        btn.classList.add("active");
        document.getElementById("tab-" + btn.dataset.tab).classList.add("active");
        if (btn.dataset.tab === "records") loadRecords();
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
            const wb = XLSX.read(e.target.result, {type: "array", cellDates: true});
            const sheet = selectSheet(wb);
            if (!sheet) return;
            const rows = XLSX.utils.sheet_to_json(sheet, {header: 1, defval: "", raw: false, dateNF: "yyyy-mm-dd"});

            const travelers = extractTravelers(rows);
            if (!travelers.length) { showToast("No travelers found in this file.", "error"); return; }

            const {coverageStart, coverageEnd} = extractDates(rows, sheet);
            const {groupName, tourAgent} = parseFilename(file.name);

            showPreview(travelers, groupName, tourAgent, coverageStart, coverageEnd, file.name);
        } catch(ex) {
            showToast("Error reading file: " + ex.message, "error");
        }
    };
    reader.readAsArrayBuffer(file);
    // Reset input so same file can be re-uploaded
    fileInput.value = "";
}

function selectSheet(wb) {
    if (wb.SheetNames.length === 1) return wb.Sheets[wb.SheetNames[0]];
    const recapIdx = wb.SheetNames.findIndex(n => n.toUpperCase().includes("RECAP"));
    if (recapIdx >= 0) return wb.Sheets[wb.SheetNames[recapIdx]];
    const confIdx  = wb.SheetNames.findIndex(n => n.toUpperCase().includes("CONF"));
    if (confIdx  >= 0) return wb.Sheets[wb.SheetNames[confIdx]];
    showToast("Multiple sheets found but none named RECAP or CONF. Rename a sheet and retry.", "error");
    return null;
}

function extractTravelers(rows) {
    let headerRow = -1;
    for (let i = 0; i < rows.length; i++) {
        const cell = String(rows[i][0] || "").toLowerCase();
        if (cell.includes("name (first name")) { headerRow = i; break; }
    }
    if (headerRow < 0) { showToast("Cannot find traveler header row (NAME (first name + surname))", "error"); return []; }

    const travelers = [];
    for (let i = headerRow + 1; i < rows.length; i++) {
        const row = rows[i];
        const name = String(row[0] || "").trim();
        if (!name || name.toUpperCase().startsWith("ARRIVAL") || name.toUpperCase().startsWith("DEPARTURE")) break;
        travelers.push({
            full_name:       name.toUpperCase(),
            title:           normTitle(String(row[1] || "").trim()),
            dob:             parseFlexDate(row[2]),
            passport_number: String(row[4] || "").trim(),
            country:         String(row[5] || "").trim().toUpperCase(),
        });
    }
    return travelers;
}

function extractDates(rows, sheet) {
    let coverageStart = "", coverageEnd = "";

    // Try SheetJS cell-level date extraction for ARRIVAL/DEPARTURE rows
    const ref = XLSX.utils.decode_range(sheet["!ref"] || "A1:A1");

    for (let i = 0; i <= ref.e.r; i++) {
        const colA = String(rows[i]?.[0] || "").toUpperCase();
        if (colA.includes("ARRIVAL") && !coverageStart) {
            for (let j = i+1; j < Math.min(i+6, rows.length); j++) {
                const raw = rows[j]?.[0];
                if (!raw && raw !== 0) continue;
                const d = parseFlexDate(raw);
                if (d) { coverageStart = d; break; }
            }
        }
        if (colA.includes("DEPARTURE") && !coverageEnd) {
            for (let j = i+1; j < Math.min(i+6, rows.length); j++) {
                const raw = rows[j]?.[0];
                if (!raw && raw !== 0) continue;
                const d = parseFlexDate(raw);
                if (d) { coverageEnd = d; break; }
            }
        }
        if (coverageStart && coverageEnd) break;
    }
    return {coverageStart, coverageEnd};
}

// ── Date / text helpers ───────────────────────────────────────────────────────
function parseFlexDate(val) {
    if (!val && val !== 0) return "";

    // JS Date (from SheetJS cellDates:true)
    if (val instanceof Date && !isNaN(val)) return fmtIso(val);

    const str = String(val).trim();
    if (!str) return "";

    // Already ISO yyyy-mm-dd (or from SheetJS dateNF)
    let m = str.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (m) return str.substring(0, 10);

    // d/m/yy or dd/mm/yy or dd/mm/yyyy
    m = str.match(/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})/);
    if (m) return buildIso(m[3], m[2], m[1]);

    // d-m-yyyy or dd-mm-yyyy (or d-M-yy)
    m = str.match(/^(\d{1,2})-(\d{1,2})-(\d{2,4})/);
    if (m) return buildIso(m[3], m[2], m[1]);

    // d Mon yyyy  (e.g. 7 Jun 2026)
    m = str.match(/^(\d{1,2})[\s\/\-]([A-Za-z]+)[\s\/\-](\d{2,4})/);
    if (m) {
        const mo = {jan:1,feb:2,mar:3,apr:4,may:5,jun:6,jul:7,aug:8,sep:9,oct:10,nov:11,dec:12}[m[2].toLowerCase().substring(0,3)];
        if (mo) return buildIso(m[3], mo, m[1]);
    }

    // Try JS Date parsing as last resort
    const dt = new Date(str);
    if (!isNaN(dt) && dt.getFullYear() > 1970) return fmtIso(dt);

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
    if (!isoStr) return "";
    const [y,m,d] = isoStr.split("-");
    const months = ["","Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
    return parseInt(d) + " " + months[parseInt(m)] + " " + y;
}
function normTitle(s) {
    s = s.toUpperCase().replace(/\./g,"").trim();
    return ["MR","MRS","MS"].includes(s) ? s : "";
}

function parseFilename(filename) {
    let name = filename.replace(/\.[^.]+$/, "");   // remove ext
    name = name.replace(/__.*$/, "");              // remove __lodge__ suffix
    name = name.replace(/^\d+_/, "");              // remove leading 02_

    const parts = name.split("_");
    // Group name: first part, camelCase to words
    const raw = parts[0] || "";
    const groupName = raw.replace(/([a-z])([A-Z])/g, "$1 $2").replace(/([A-Z]+)([A-Z][a-z])/g, "$1 $2");

    // Agent: last dash-segment of last underscore-part
    let tourAgent = "";
    if (parts.length > 1) {
        const last = parts[parts.length - 1];
        const segs = last.split("-").filter(s => s && !["LAM","PS","SB","DRCT"].includes(s.toUpperCase()));
        if (segs.length) tourAgent = segs[segs.length - 1];
    }
    return {groupName, tourAgent};
}

// ── Preview Render ────────────────────────────────────────────────────────────
let currentFilename = "";

function showPreview(travelers, groupName, tourAgent, start, end, filename) {
    document.getElementById("ph-group").value  = groupName;
    document.getElementById("ph-agent").value  = tourAgent;
    document.getElementById("ph-start").value  = start;
    document.getElementById("ph-end").value    = end;
    document.getElementById("parsed-from-label").textContent = "Parsed from: " + filename;
    currentFilename = filename;

    const tbody = document.getElementById("preview-tbody");
    tbody.innerHTML = "";
    travelers.forEach(t => appendRow(t));
    updateCount();
    document.getElementById("preview-section").style.display = "block";
    document.getElementById("preview-section").scrollIntoView({behavior:"smooth", block:"start"});
}

function appendRow(t) {
    const tbody = document.getElementById("preview-tbody");
    const tr = document.createElement("tr");
    tr.dataset.dup = "0";
    tr.innerHTML = `
      <td><input class="pt-input" type="text" value="${esc(t.full_name || "")}" placeholder="Full Name"></td>
      <td>
        <select class="pt-select">
          <option value="">—</option>
          ${["MR","MRS","MS"].map(v => `<option value="${v}" ${t.title===v?"selected":""}>${v}</option>`).join("")}
        </select>
      </td>
      <td><input class="pt-input" type="date" value="${esc(t.dob || "")}"></td>
      <td><input class="pt-input" type="text" value="${esc(t.passport_number || "")}" placeholder="Passport #"></td>
      <td><input class="pt-input" type="text" value="${esc(t.country || "")}" placeholder="Country"></td>
      <td><button class="btn-del-row" title="Remove row" onclick="removeRow(this)">✕</button></td>
    `;
    tbody.appendChild(tr);
}

function addBlankRow() {
    appendRow({full_name:"", title:"", dob:"", passport_number:"", country:""});
    updateCount();
}

function removeRow(btn) {
    btn.closest("tr").remove();
    updateCount();
}

function clearPreview() {
    document.getElementById("preview-section").style.display = "none";
    document.getElementById("preview-tbody").innerHTML = "";
    currentFilename = "";
}

function updateCount() {
    const n = document.getElementById("preview-tbody").querySelectorAll("tr").length;
    document.getElementById("traveler-count").textContent = n;
}

function collectTravelers() {
    const rows = document.getElementById("preview-tbody").querySelectorAll("tr");
    const list = [];
    rows.forEach(tr => {
        const inputs = tr.querySelectorAll("input,select");
        const name = inputs[0].value.trim();
        if (!name) return;
        list.push({
            full_name:       name,
            title:           inputs[1].value,
            dob:             inputs[2].value || null,
            passport_number: inputs[3].value.trim(),
            country:         inputs[4].value.trim(),
        });
    });
    return list;
}

function buildPayload(extras) {
    return Object.assign({
        group_name:     document.getElementById("ph-group").value.trim(),
        tour_agent:     document.getElementById("ph-agent").value.trim(),
        coverage_start: document.getElementById("ph-start").value || null,
        coverage_end:   document.getElementById("ph-end").value   || null,
        source_file:    currentFilename,
        travelers:      collectTravelers(),
    }, extras || {});
}

// ── Save ──────────────────────────────────────────────────────────────────────
async function saveTravelers(extras) {
    const payload = buildPayload(extras);
    if (!payload.group_name) { showToast("Please enter a Group Name.", "error"); return; }
    if (!payload.travelers.length) { showToast("No travelers to save.", "error"); return; }

    const btn = document.getElementById("btn-save");
    btn.disabled = true; btn.textContent = "Saving…";

    try {
        const r = await fetch("api/medivac_save.php", {
            method:"POST",
            headers:{"Content-Type":"application/json"},
            body: JSON.stringify(payload)
        });
        const data = await r.json();

        if (data.dup_warning) {
            showDupModal(data.dups);
        } else if (data.ok) {
            const msg = `✅ Saved ${data.saved} traveler${data.saved!==1?"s":""}` +
                        (data.skipped ? ` (${data.skipped} duplicate${data.skipped!==1?"s":""} skipped)` : "") +
                        ` — Group: ${data.group_name}`;
            showToast(msg, "success");
            clearPreview();
        } else {
            showToast("Error: " + (data.error || "Unknown error"), "error");
        }
    } catch(ex) {
        showToast("Network error: " + ex.message, "error");
    } finally {
        btn.disabled = false; btn.textContent = "💾 Save to Database";
    }
}

// ── Dup Modal ─────────────────────────────────────────────────────────────────
let _pendingPayload = null;

function showDupModal(dups) {
    _pendingPayload = buildPayload();
    const list = document.getElementById("dup-list");
    list.innerHTML = dups.map(d =>
        `<div>👤 <strong>${esc(d.full_name)}</strong> (DOB: ${fmtDisplay(d.dob)}) — already in <em>${esc(d.existing_group)}</em> starting ${fmtDisplay(d.existing_start)}</div>`
    ).join("");
    document.getElementById("dup-modal").classList.add("open");
}
function closeDupModal() {
    document.getElementById("dup-modal").classList.remove("open");
    document.getElementById("btn-save").disabled = false;
    document.getElementById("btn-save").textContent = "💾 Save to Database";
}
async function saveWithSkip()  { closeDupModal(); await saveTravelers({skip_dups: true}); }
async function saveWithForce() { closeDupModal(); await saveTravelers({force_dups: true}); }

// ── Records Tab ───────────────────────────────────────────────────────────────
let _recordsTimer = null;
function debounceRecords() { clearTimeout(_recordsTimer); _recordsTimer = setTimeout(loadRecords, 400); }

async function loadRecords() {
    const from   = document.getElementById("rec-from").value;
    const to     = document.getElementById("rec-to").value;
    const search = document.getElementById("rec-search").value.trim();

    const params = new URLSearchParams();
    if (from)   params.set("from",   from);
    if (to)     params.set("to",     to);
    if (search) params.set("q",      search);

    const container = document.getElementById("records-container");
    container.innerHTML = "<div class=\"empty-state\"><div class=\"es-icon\">⏳</div><p>Loading…</p></div>";

    try {
        const r    = await fetch("api/medivac_list.php?" + params);
        const data = await r.json();
        renderGroups(data.groups || []);
    } catch(ex) {
        container.innerHTML = `<div class="flash error">Error loading records: ${ex.message}</div>`;
    }
}

function clearRecordFilters() {
    document.getElementById("rec-from").value   = "";
    document.getElementById("rec-to").value     = "";
    document.getElementById("rec-search").value = "";
    document.getElementById("records-container").innerHTML =
        "<div class=\"empty-state\"><div class=\"es-icon\">🔍</div><p>Use the filters above to search records.</p></div>";
}

function renderGroups(groups) {
    const container = document.getElementById("records-container");
    if (!groups.length) {
        container.innerHTML = "<div class=\"empty-state\"><div class=\"es-icon\">📭</div><p>No records found.</p></div>";
        return;
    }
    container.innerHTML = groups.map(g => {
        const dateRange = [fmtDisplay(g.coverage_start), fmtDisplay(g.coverage_end)].filter(Boolean).join(" → ");
        const travelerRows = g.travelers.map(t => `
            <tr>
              <td class="td-name">${esc(t.full_name)}</td>
              <td>${esc(t.title)}</td>
              <td>${fmtDisplay(t.dob)}</td>
              <td>${esc(t.passport_number || "—")}</td>
              <td>${esc(t.country || "—")}</td>
              <td>
                <button class="btn btn-danger btn-sm" onclick="deleteTraveler(${t.id}, this)">✕</button>
              </td>
            </tr>`).join("");

        return `
        <div class="group-card">
          <div class="group-card-head" onclick="toggleGroup(this)">
            <div>
              <div class="gc-name">${esc(g.group_name)}</div>
              <div class="gc-meta">
                ${dateRange ? `<span>📅 ${dateRange}</span>` : ""}
                ${g.tour_agent ? `<span>👤 ${esc(g.tour_agent)}</span>` : ""}
                ${g.source_file ? `<span>📄 ${esc(g.source_file)}</span>` : ""}
              </div>
            </div>
            <span class="gc-count">${g.travelers.length} traveler${g.travelers.length!==1?"s":""}</span>
            <span class="gc-toggle">▼</span>
          </div>
          <div class="group-card-body">
            <table>
              <thead><tr>
                <th>Name</th><th>Title</th><th>DOB</th><th>Passport #</th><th>Country</th><th></th>
              </tr></thead>
              <tbody>${travelerRows}</tbody>
            </table>
            <div class="gc-foot">
              <button class="btn btn-danger btn-sm" onclick="deleteGroup('${esc(g.group_ref)}', this)">🗑 Delete Entire Group</button>
            </div>
          </div>
        </div>`;
    }).join("");
}

function toggleGroup(head) {
    const body   = head.nextElementSibling;
    const toggle = head.querySelector(".gc-toggle");
    body.classList.toggle("open");
    toggle.classList.toggle("open");
}

async function deleteTraveler(id, btn) {
    if (!confirm("Delete this traveler?")) return;
    btn.disabled = true;
    try {
        const r = await fetch("api/medivac_delete.php", {
            method:"POST",
            headers:{"Content-Type":"application/json"},
            body: JSON.stringify({mode:"traveler", id})
        });
        const data = await r.json();
        if (data.ok) {
            btn.closest("tr").remove();
            showToast("Traveler deleted.", "success");
        } else {
            showToast("Error: " + (data.error||""), "error");
        }
    } catch(ex) { showToast("Network error.", "error"); }
    finally { btn.disabled = false; }
}

async function deleteGroup(groupRef, btn) {
    if (!confirm("Delete ALL travelers in this group? This cannot be undone.")) return;
    btn.disabled = true;
    try {
        const r = await fetch("api/medivac_delete.php", {
            method:"POST",
            headers:{"Content-Type":"application/json"},
            body: JSON.stringify({mode:"group", group_ref: groupRef})
        });
        const data = await r.json();
        if (data.ok) {
            btn.closest(".group-card").remove();
            showToast(`Group deleted (${data.count} traveler${data.count!==1?"s":""}).`, "success");
        } else {
            showToast("Error: " + (data.error||""), "error");
        }
    } catch(ex) { showToast("Network error.", "error"); }
    finally { btn.disabled = false; }
}

// ── Export ────────────────────────────────────────────────────────────────────
function downloadReport() {
    const from = document.getElementById("exp-from").value;
    const to   = document.getElementById("exp-to").value;
    if (!from || !to) { showToast("Please select both From and To dates.", "error"); return; }
    if (from > to)    { showToast("From date must be before To date.", "error"); return; }
    window.location.href = `api/medivac_report.php?from=${from}&to=${to}`;
}

// ── Toast / Utilities ─────────────────────────────────────────────────────────
function showToast(msg, type="success") {
    const t = document.getElementById("toast");
    t.textContent = msg;
    t.className = "toast " + type + " show";
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
