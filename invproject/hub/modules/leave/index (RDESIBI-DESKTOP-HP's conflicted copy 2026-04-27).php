<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_permission('leave');

// ══════════════════════════════════════════════════════════════════════════
//  DB LOADING — uses the hub's shared $pdo connection (savannp5_rdesibi).
//  Tables are created automatically on first run.
//  Falls back silently to Excel-upload-only if DB is unavailable.
// ══════════════════════════════════════════════════════════════════════════
$dbAvailable = false;
$hasData     = false;
$leaveData   = [];
$lastImport  = '';
$lastFile    = '';
$dbError     = '';

try {
    $leavePdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    if ($leavePdo instanceof PDO) {
        $dbAvailable = true;

        // Create tables on first run
        $leavePdo->exec("CREATE TABLE IF NOT EXISTS leave_employees (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            name           VARCHAR(100)   NOT NULL,
            palette_index  TINYINT        NOT NULL DEFAULT 0,
            open_balance   FLOAT          DEFAULT NULL,
            alloc1         FLOAT          DEFAULT NULL,
            used1          FLOAT          DEFAULT NULL,
            bal1           FLOAT          DEFAULT NULL,
            alloc2         FLOAT          DEFAULT NULL,
            used2          FLOAT          DEFAULT NULL,
            bal2           FLOAT          DEFAULT NULL,
            remaining      FLOAT          DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $leavePdo->exec("CREATE TABLE IF NOT EXISTS leave_entries (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            employee_name   VARCHAR(100)  NOT NULL,
            leave_type      VARCHAR(200)  DEFAULT NULL,
            start_date      DATE          NOT NULL,
            end_date        DATE          NOT NULL,
            INDEX idx_emp (employee_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $leavePdo->exec("CREATE TABLE IF NOT EXISTS leave_meta (
            meta_key    VARCHAR(60)  NOT NULL PRIMARY KEY,
            meta_value  TEXT         DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $empRows = $leavePdo->query(
            "SELECT * FROM leave_employees ORDER BY id"
        )->fetchAll();

        if ($empRows) {
            $hasData = true;
            $stmtLeaves = $leavePdo->prepare(
                "SELECT leave_type, start_date, end_date
                   FROM leave_entries
                  WHERE employee_name = ?
               ORDER BY start_date"
            );
            foreach ($empRows as $emp) {
                $stmtLeaves->execute([$emp['name']]);
                $leaves = $stmtLeaves->fetchAll();
                $leaveData[] = [
                    'name'         => $emp['name'],
                    'paletteIndex' => (int)$emp['palette_index'],
                    'balance'      => [
                        'open'      => $emp['open_balance'],
                        'alloc1'    => $emp['alloc1'],
                        'used1'     => $emp['used1'],
                        'bal1'      => $emp['bal1'],
                        'alloc2'    => $emp['alloc2'],
                        'used2'     => $emp['used2'],
                        'bal2'      => $emp['bal2'],
                        'remaining' => $emp['remaining'],
                    ],
                    'leaves' => array_map(fn($l) => [
                        'leaveType' => $l['leave_type'],
                        'start'     => $l['start_date'],   // 'YYYY-MM-DD'
                        'end'       => $l['end_date'],
                    ], $leaves),
                ];
            }
        }

        $metaStmt = $leavePdo->query(
            "SELECT meta_key, meta_value FROM leave_meta
              WHERE meta_key IN ('last_import','last_file')"
        );
        foreach ($metaStmt->fetchAll() as $row) {
            if ($row['meta_key'] === 'last_import') $lastImport = $row['meta_value'];
            if ($row['meta_key'] === 'last_file')   $lastFile   = $row['meta_value'];
        }

    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

// ══════════════════════════════════════════════════════════════════════════
//  PAGE SETUP
// ══════════════════════════════════════════════════════════════════════════
$page_title = 'Leave Calendar';
$extra_css = <<<'CSS'
/* ── Controls bar ── */
.controls{background:var(--red-dk);padding:14px 40px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;}
.btn{font-family:"Open Sans",sans-serif;font-size:.78rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:9px 24px;border:none;border-radius:6px;cursor:pointer;transition:all .2s;}
.btn-upload{background:rgba(255,255,255,.15);color:var(--white);border:1px solid rgba(255,255,255,.3)!important;}
.btn-upload:hover{background:rgba(255,255,255,.25);}
.ctrl-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.7);}
.file-badge{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);border-radius:4px;padding:4px 12px;font-size:.75rem;color:rgba(255,255,255,.9);}
.db-badge{background:rgba(0,180,100,.25);border:1px solid rgba(0,220,120,.4);border-radius:4px;padding:4px 12px;font-size:.75rem;color:#a0ffc8;display:flex;align-items:center;gap:6px;}
/* ── Main layout ── */
main{padding:32px 40px;}
.legend-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:22px;}
.legend-lbl{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--grey-mid);margin-right:4px;}
.leg-chip{display:flex;align-items:center;gap:6px;padding:4px 12px;border-radius:20px;border:1.5px solid;font-size:.75rem;font-weight:700;cursor:pointer;transition:opacity .15s;user-select:none;}
.leg-chip.inactive{opacity:.3;}
/* ── Month nav ── */
.month-nav{display:flex;align-items:center;justify-content:center;gap:16px;margin-bottom:22px;}
.nav-btn{background:var(--red-dk);color:var(--white);border:none;border-radius:6px;width:34px;height:34px;font-size:1.1rem;cursor:pointer;transition:background .15s;display:flex;align-items:center;justify-content:center;}
.nav-btn:hover{background:var(--red);}
.month-title{font-family:"Merriweather",serif;font-size:1.3rem;font-weight:700;color:var(--red-dk);min-width:210px;text-align:center;}
/* ── Calendar ── */
.cal-wrap{background:var(--white);border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.08);overflow:hidden;margin-bottom:28px;}
.cal-head-grid{display:grid;grid-template-columns:repeat(7,1fr);background:var(--black);}
.cal-dow{padding:10px 0;text-align:center;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:rgba(255,255,255,.7);}
.cal-body{display:grid;grid-template-columns:repeat(7,1fr);}
.cal-cell{min-height:90px;padding:6px 5px 4px;border-right:.5px solid var(--grey-lt);border-bottom:.5px solid var(--grey-lt);cursor:default;}
.cal-cell.weekend{background:#FBF8F4;}
.cal-cell.empty{background:#FAFAF7;}
.cal-cell.has-leave{cursor:pointer;}
.cal-cell.has-leave:hover{background:#FFF9F2;}
.day-num{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:600;color:var(--grey-mid);margin-bottom:3px;}
.day-num.today{background:var(--red);color:var(--white);font-weight:700;}
.leave-tag{border-radius:4px;padding:2px 6px;font-size:.65rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:2px;max-width:100%;}
/* ── Section titles ── */
.section-title{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--grey-mid);margin-bottom:12px;display:flex;align-items:center;gap:8px;}
.section-title::before{content:"";display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--red);}
/* ── Overview ── */
.overview-wrap{background:var(--white);border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.08);padding:18px 20px;margin-bottom:28px;}
.ov-row{display:grid;align-items:center;gap:3px;margin-bottom:3px;}
.ov-name{font-size:.72rem;font-weight:700;color:var(--black);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding-right:8px;}
.ov-cell{height:16px;border-radius:3px;cursor:pointer;transition:filter .12s;position:relative;}
.ov-cell:hover{filter:brightness(.85);}
.month-labels-row{display:grid;gap:3px;margin-bottom:5px;}
.mlbl{font-size:.6rem;font-weight:700;text-align:center;color:var(--grey-mid);text-transform:uppercase;letter-spacing:.04em;}
/* ── Balance table ── */
.balance-wrap{background:var(--white);border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.08);overflow-x:auto;margin-bottom:28px;}
.balance-table{width:100%;border-collapse:collapse;font-size:.72rem;}
.balance-table th{background:var(--black);color:rgba(255,255,255,.8);padding:9px 12px;text-align:center;font-weight:700;font-size:.62rem;text-transform:uppercase;letter-spacing:.08em;white-space:nowrap;}
.balance-table th:first-child{text-align:left;}
.balance-table td{padding:8px 12px;text-align:center;border-bottom:.5px solid var(--grey-lt);color:var(--grey-dk);}
.balance-table td:first-child{font-weight:700;color:var(--black);text-align:left;}
.balance-table tr:last-child td{border-bottom:none;}
.balance-table tr:hover td{background:#FAFAFA;}
.balance-table .neg{color:var(--red-dk);font-weight:700;}
.balance-table .pos{color:var(--green);font-weight:700;}
/* ── Upload section ── */
.upload-section{max-width:560px;margin:48px auto;text-align:center;}
.upload-zone{border:2.5px dashed var(--grey-lt);border-radius:12px;padding:52px 32px;background:var(--white);cursor:pointer;transition:all .2s;}
.upload-zone:hover,.upload-zone.drag{border-color:var(--red);background:var(--red-lt);}
.upload-zone .icon{font-size:2.8rem;margin-bottom:12px;}
.upload-zone h2{font-family:"Merriweather",serif;font-size:1.1rem;color:var(--red-dk);margin-bottom:6px;}
.upload-zone p{font-size:.82rem;color:var(--grey-mid);line-height:1.6;}
.info-box{background:#F0F4FF;border-left:4px solid #4A7BE0;border-radius:6px;padding:12px 16px;margin-top:16px;font-size:.8rem;color:var(--grey-dk);text-align:left;}
.error-box{background:var(--red-lt);border-left:4px solid var(--red);border-radius:6px;padding:12px 16px;margin-bottom:20px;font-size:.82rem;color:var(--red-dk);}
/* ── Saving overlay ── */
.saving-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:2000;align-items:center;justify-content:center;}
.saving-overlay.open{display:flex;}
.saving-box{background:var(--white);border-radius:12px;padding:32px 40px;text-align:center;box-shadow:0 8px 40px rgba(0,0,0,.2);}
.saving-box .spin{font-size:2rem;animation:spin 1s linear infinite;display:inline-block;margin-bottom:12px;}
@keyframes spin{to{transform:rotate(360deg)}}
.saving-box p{font-family:"Merriweather",serif;font-size:.95rem;color:var(--red-dk);}
/* ── Modal ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal{background:var(--white);border-radius:12px;padding:28px;width:min(380px,92vw);box-shadow:0 8px 40px rgba(0,0,0,.25);display:flex;flex-direction:column;gap:14px;}
.modal h3{font-family:"Merriweather",serif;font-size:1rem;color:var(--red-dk);}
.modal-leave{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:8px;border:1.5px solid;}
.modal-dot{width:11px;height:11px;border-radius:50%;flex-shrink:0;}
.modal-emp{font-weight:700;font-size:.88rem;}
.modal-type{font-size:.78rem;color:var(--grey-mid);margin-top:2px;}
.modal-actions{display:flex;justify-content:flex-end;}
.btn-modal-close{background:var(--grey-lt);color:var(--grey-dk);font-family:"Open Sans",sans-serif;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:8px 18px;border-radius:6px;border:none;cursor:pointer;}
@media(max-width:700px){main,.controls{padding-left:16px;padding-right:16px;}}
CSS;

include __DIR__ . '/../../includes/layout_header.php';
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<!-- ══ Controls bar (hidden until calendar is shown) ══ -->
<div class="controls" id="controlsBar" style="display:none">
  <span class="ctrl-label">&#128197; Staff Leaves</span>

  <?php if ($dbAvailable): ?>
    <!-- DB badge: shown when loaded from database -->
    <span id="dbBadge" class="db-badge" style="display:none">
      <span>&#128200;</span>
      <span id="dbBadgeText"></span>
    </span>
  <?php endif; ?>

  <!-- File badge: shown when loaded from an uploaded file (no DB) -->
  <span id="fileBadge" class="file-badge" style="display:none"></span>

  <?php if ($dbAvailable): ?>
    <button class="btn btn-upload" onclick="confirmReimport()">&#8635; Re-import Excel</button>
  <?php else: ?>
    <button class="btn btn-upload" onclick="resetApp()">&#8617; Change File</button>
  <?php endif; ?>
</div>

<main>
  <div id="errorBox" class="error-box" style="display:none"></div>

  <!-- ══ Upload section ══ -->
  <div id="uploadSection" class="upload-section" <?= $hasData ? 'style="display:none"' : '' ?>>
    <div class="upload-zone" id="uploadZone"
      onclick="document.getElementById('fileInput').click()"
      ondragover="handleDragOver(event)"
      ondragleave="handleDragLeave(event)"
      ondrop="handleDrop(event)">
      <div class="icon">&#128202;</div>
      <?php if ($dbAvailable && !$hasData): ?>
        <h2>Import Staff Leave File</h2>
        <p>Upload the Excel file to populate the database.<br>Future visits will load automatically.</p>
      <?php elseif ($dbAvailable && $hasData): ?>
        <h2>Re-import Staff Leave File</h2>
        <p>Upload a new Excel file to replace the current data.</p>
      <?php else: ?>
        <h2>Upload Staff Leave File</h2>
        <p>Click to browse or drag &amp; drop the file here.</p>
      <?php endif; ?>
    </div>
    <div class="info-box" style="display:flex;align-items:flex-start;gap:10px;">
      &#128193;
      <div>
        <strong>File location:</strong><br>
        <code style="font-size:.83rem;color:#333">H:\My Drive\Commissions\Leaves_for_Savannah_Staff.xlsx</code>
      </div>
    </div>
    <?php if ($dbAvailable && $hasData): ?>
      <p style="margin-top:14px;">
        <a href="#" onclick="cancelReimport();return false;"
           style="font-size:.8rem;color:var(--grey-mid);text-decoration:underline;">
          ← Back to calendar
        </a>
      </p>
    <?php endif; ?>
    <?php if ($dbError): ?>
      <div class="error-box" style="margin-top:14px;">
        &#9888; DB connection error: <?= htmlspecialchars($dbError) ?>
      </div>
    <?php endif; ?>
    <input id="fileInput" type="file" accept=".xlsx,.xls" style="display:none" onchange="handleFileInput(event)">
  </div>

  <!-- ══ Calendar section ══ -->
  <div id="calendarSection" <?= $hasData ? '' : 'style="display:none"' ?>>
    <div class="legend-row" id="legendRow"><span class="legend-lbl">Employees:</span></div>

    <div class="month-nav">
      <button class="nav-btn" onclick="changeMonth(-1)">&#8249;</button>
      <div class="month-title" id="monthTitle"></div>
      <button class="nav-btn" onclick="changeMonth(1)">&#8250;</button>
    </div>

    <div class="cal-wrap">
      <div class="cal-head-grid">
        <div class="cal-dow">Sun</div><div class="cal-dow">Mon</div>
        <div class="cal-dow">Tue</div><div class="cal-dow">Wed</div>
        <div class="cal-dow">Thu</div><div class="cal-dow">Fri</div>
        <div class="cal-dow">Sat</div>
      </div>
      <div class="cal-body" id="calBody"></div>
    </div>

    <div class="section-title">Year Overview</div>
    <div class="overview-wrap" id="overviewWrap"></div>

    <div class="section-title">Leave Balances 2026</div>
    <div class="balance-wrap">
      <table class="balance-table">
        <thead>
          <tr>
            <th>Employee</th>
            <th>Open Balance</th>
            <th>1st Half Alloc.</th>
            <th>1st Half Used</th>
            <th>1st Half Bal.</th>
            <th>2nd Half Alloc.</th>
            <th>2nd Half Used</th>
            <th>2nd Half Bal.</th>
            <th>Remaining</th>
          </tr>
        </thead>
        <tbody id="balanceBody"></tbody>
      </table>
    </div>
  </div>
</main>

<!-- ══ Day detail modal ══ -->
<div class="modal-overlay" id="modalOverlay" onclick="if(event.target===this)closeModal()">
  <div class="modal">
    <h3 id="modalTitle"></h3>
    <div id="modalBody"></div>
    <div class="modal-actions">
      <button class="btn-modal-close" onclick="closeModal()">Close</button>
    </div>
  </div>
</div>

<!-- ══ Saving overlay ══ -->
<div class="saving-overlay" id="savingOverlay">
  <div class="saving-box">
    <div class="spin">&#9881;</div>
    <p>Saving to database&hellip;</p>
  </div>
</div>

<script>
// ── PHP → JS data injection ──────────────────────────────────────────────────
const INITIAL_DATA  = <?= json_encode($leaveData, JSON_UNESCAPED_UNICODE) ?>;
const HAS_INITIAL   = <?= $hasData      ? 'true' : 'false' ?>;
const DB_AVAILABLE  = <?= $dbAvailable  ? 'true' : 'false' ?>;
const LAST_IMPORT   = <?= json_encode($lastImport) ?>;
const LAST_FILE     = <?= json_encode($lastFile) ?>;

// ── Constants ────────────────────────────────────────────────────────────────
const MONTHS   = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const MSHORT   = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const DAY_FULL = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
const PALETTE  = [
  {bg:'#FAE8E7',border:'#C0211B',text:'#A01A14'},
  {bg:'#D6EDD9',border:'#2E6B3E',text:'#1E4A2A'},
  {bg:'#E8F0FA',border:'#1A4D8A',text:'#0D3060'},
  {bg:'#FFF8E1',border:'#C47A0A',text:'#7A4A00'},
  {bg:'#EDE8F8',border:'#5C3AB0',text:'#3A1F80'},
  {bg:'#E8F8F4',border:'#1A8A65',text:'#0D5A40'},
  {bg:'#FCE8F4',border:'#A01A7A',text:'#6B0050'},
  {bg:'#E8F4F8',border:'#1A6A8A',text:'#0D3F5A'},
  {bg:'#F8F0E8',border:'#8A5A1A',text:'#5A3500'},
  {bg:'#F0F8E8',border:'#5A8A1A',text:'#355A00'},
  {bg:'#FEF2F2',border:'#DC2626',text:'#991B1B'},
  {bg:'#F0FDF4',border:'#16A34A',text:'#14532D'},
  {bg:'#EFF6FF',border:'#2563EB',text:'#1E3A8A'},
  {bg:'#FEFCE8',border:'#CA8A04',text:'#713F12'},
  {bg:'#FDF4FF',border:'#9333EA',text:'#581C87'},
];

// ── State ────────────────────────────────────────────────────────────────────
let employees = [], empColors = {}, activeEmps = {};
let viewYear  = new Date().getFullYear();
let viewMonth = new Date().getMonth();
const today   = new Date(); today.setHours(0,0,0,0);

// ── Initialisation ───────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  if (HAS_INITIAL) {
    loadFromDB(INITIAL_DATA);
  }
  // else: upload section is already visible (PHP rendered it that way)
});

/**
 * Populates the app from PHP-injected DB data.
 * Dates arrive as 'YYYY-MM-DD' strings.
 */
function loadFromDB(data) {
  employees = []; empColors = {}; activeEmps = {};
  data.forEach(emp => {
    const leaves = (emp.leaves || [])
      .map(l => ({
        leaveType: l.leaveType,
        start: parseDBDate(l.start),
        end:   parseDBDate(l.end),
      }))
      .filter(l => l.start && !isNaN(l.start));

    employees.push({name: emp.name, leaves, balance: emp.balance});
    empColors[emp.name]  = PALETTE[emp.paletteIndex % PALETTE.length];
    activeEmps[emp.name] = true;
  });

  // Show DB badge in controls bar
  if (DB_AVAILABLE) {
    const badge = document.getElementById('dbBadge');
    if (badge) {
      document.getElementById('dbBadgeText').textContent =
        LAST_FILE ? LAST_FILE + (LAST_IMPORT ? '  ·  ' + LAST_IMPORT : '') : 'Database';
      badge.style.display = 'flex';
    }
  }

  document.getElementById('uploadSection').style.display    = 'none';
  document.getElementById('calendarSection').style.display  = 'block';
  document.getElementById('controlsBar').style.display      = 'flex';
  buildLegend(); renderCalendar(); renderOverview(); renderBalances();
}

/** Parse 'YYYY-MM-DD' to a local-midnight Date (same as parseExcelDate output). */
function parseDBDate(s) {
  if (!s || typeof s !== 'string') return null;
  const [y, m, d] = s.split('-').map(Number);
  if (!y || !m || !d) return null;
  return new Date(y, m - 1, d);
}

// ── Excel date parsing ───────────────────────────────────────────────────────
function parseExcelDate(v) {
  if (!v) return null;
  if (v instanceof Date) {
    const d = new Date(v.getFullYear(), v.getMonth(), v.getDate());
    return isNaN(d) ? null : d;
  }
  if (typeof v === 'number') {
    const d = new Date(Math.round((v - 25569) * 86400 * 1000));
    return new Date(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate());
  }
  if (typeof v === 'string') {
    const m = v.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (m) return new Date(parseInt(m[3]), parseInt(m[2]) - 1, parseInt(m[1]));
    const d = new Date(v.split(' ')[0]);
    if (!isNaN(d)) return new Date(d.getFullYear(), d.getMonth(), d.getDate());
  }
  return null;
}

function fmtDate(d) { return MSHORT[d.getMonth()] + ' ' + d.getDate(); }

function dateToISO(d) {
  return d.getFullYear() + '-' +
         String(d.getMonth() + 1).padStart(2, '0') + '-' +
         String(d.getDate()).padStart(2, '0');
}

// ── Drag-and-drop / file input ────────────────────────────────────────────────
function handleDragOver(e) { e.preventDefault(); document.getElementById('uploadZone').classList.add('drag'); }
function handleDragLeave()  { document.getElementById('uploadZone').classList.remove('drag'); }
function handleDrop(e)      { e.preventDefault(); handleDragLeave(); readFile(e.dataTransfer.files[0]); }
function handleFileInput(e) { readFile(e.target.files[0]); }

// ── Read & parse Excel file ───────────────────────────────────────────────────
function readFile(file) {
  if (!file) return;
  document.getElementById('errorBox').style.display = 'none';
  const r = new FileReader();
  r.onload = e => {
    try {
      const wb = XLSX.read(e.target.result, {type:'array'});
      employees = []; empColors = {}; activeEmps = {};

      wb.SheetNames.forEach((sheetName, idx) => {
        const ws  = wb.Sheets[sheetName];
        const raw = XLSX.utils.sheet_to_json(ws, {header:1, defval:null});

        // Balance row (index 2)
        const bRow = raw[2] || [];
        const balance = {
          open:      (bRow[1] !== null && bRow[1] !== undefined) ? bRow[1] : '',
          alloc1:    (bRow[2] !== null && bRow[2] !== undefined) ? bRow[2] : '',
          used1:     (bRow[3] !== null && bRow[3] !== undefined) ? bRow[3] : '',
          bal1:      (bRow[4] !== null && bRow[4] !== undefined) ? bRow[4] : '',
          alloc2:    (bRow[5] !== null && bRow[5] !== undefined) ? bRow[5] : '',
          used2:     (bRow[6] !== null && bRow[6] !== undefined) ? bRow[6] : '',
          bal2:      (bRow[7] !== null && bRow[7] !== undefined) ? bRow[7] : '',
          remaining: (bRow[8] !== null && bRow[8] !== undefined) ? bRow[8] : '',
        };

        // Find header row (LEAVE TYPE)
        let headerRow = -1;
        for (let i = 0; i < raw.length; i++) {
          const c = raw[i] && raw[i][0];
          if (c && String(c).replace(/\s/g,'').toUpperCase().includes('LEAVETYPE')) { headerRow = i; break; }
        }

        const leaves = [];
        if (headerRow >= 0) {
          for (let i = headerRow + 1; i < raw.length; i++) {
            const row = raw[i]; if (!row) continue;
            const col0 = row[0] ? String(row[0]).trim() : '';
            if (col0.toUpperCase().startsWith('TOTAL')) continue;
            const start = parseExcelDate(row[1]); if (!start) continue;
            const end   = parseExcelDate(row[2]) || start;
            leaves.push({leaveType: col0 || 'Leave', start, end});
          }
        }

        let empName = sheetName;
        if (raw[2] && raw[2][0] && String(raw[2][0]).trim()) empName = String(raw[2][0]).trim();

        employees.push({name: empName, leaves, balance});
        empColors[empName]  = PALETTE[idx % PALETTE.length];
        activeEmps[empName] = true;
      });

      // Show calendar immediately from parsed data
      document.getElementById('uploadSection').style.display   = 'none';
      document.getElementById('calendarSection').style.display = 'block';
      document.getElementById('controlsBar').style.display     = 'flex';
      buildLegend(); renderCalendar(); renderOverview(); renderBalances();

      // ── Save to DB if available ──────────────────────────────────────────
      if (DB_AVAILABLE) {
        document.getElementById('savingOverlay').classList.add('open');
        saveToDatabase(file.name)
          .then(() => { location.reload(); })
          .catch(err => {
            document.getElementById('savingOverlay').classList.remove('open');
            // Show inline badge but stay on calendar
            const fb = document.getElementById('fileBadge');
            fb.textContent = '• ' + file.name + ' (not in DB)';
            fb.style.display = 'inline-block';
            showError('File loaded but DB save failed: ' + err.message);
          });
      } else {
        // No DB: just show filename badge
        const fb = document.getElementById('fileBadge');
        fb.textContent = '• ' + file.name;
        fb.style.display = 'inline-block';
      }

    } catch (err) { showError('Error reading file: ' + err.message); }
  };
  r.readAsArrayBuffer(file);
}

/** POST parsed employees array to save_leaves.php as JSON. */
async function saveToDatabase(fileName) {
  const payload = {
    fileName,
    employees: employees.map((emp, idx) => ({
      name:         emp.name,
      paletteIndex: idx,
      balance:      emp.balance,
      leaves: emp.leaves.map(l => ({
        leaveType: l.leaveType,
        start:     dateToISO(l.start),
        end:       dateToISO(l.end),
      })),
    })),
  };

  const resp = await fetch('save_leaves.php', {
    method:  'POST',
    headers: {'Content-Type': 'application/json'},
    body:    JSON.stringify(payload),
  });
  if (!resp.ok) throw new Error('HTTP ' + resp.status);
  const result = await resp.json();
  if (!result.success) throw new Error(result.error || 'Unknown DB error');
}

// ── Re-import / reset ────────────────────────────────────────────────────────
function confirmReimport() {
  if (confirm('Upload a new Excel file?\nCurrent data in the database will be replaced.')) {
    showUploadSection();
  }
}

function showUploadSection() {
  document.getElementById('uploadSection').style.display    = 'block';
  document.getElementById('calendarSection').style.display  = 'none';
  document.getElementById('controlsBar').style.display      = 'none';
  document.getElementById('fileBadge').style.display        = 'none';
  const db = document.getElementById('dbBadge');
  if (db) db.style.display = 'none';
  document.getElementById('fileInput').value = '';
  document.getElementById('errorBox').style.display = 'none';
}

function cancelReimport() {
  if (employees.length) {
    // Return to current in-memory state
    document.getElementById('uploadSection').style.display   = 'none';
    document.getElementById('calendarSection').style.display = 'block';
    document.getElementById('controlsBar').style.display     = 'flex';
  }
}

function resetApp() {
  employees = []; empColors = {}; activeEmps = {};
  showUploadSection();
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function showError(msg) {
  const b = document.getElementById('errorBox');
  b.textContent = '⚠ ' + msg;
  b.style.display = 'block';
}

function getDays(y, m) { return new Date(y, m + 1, 0).getDate(); }

function getLeavesForDay(date) {
  const res = [];
  employees.forEach(emp => {
    if (!activeEmps[emp.name]) return;
    emp.leaves.forEach(l => {
      if (date >= l.start && date <= l.end)
        res.push({employee: emp.name, leaveType: l.leaveType, color: empColors[emp.name]});
    });
  });
  return res;
}

function changeMonth(d) {
  viewMonth += d;
  if (viewMonth < 0)  { viewMonth = 11; viewYear--; }
  if (viewMonth > 11) { viewMonth = 0;  viewYear++; }
  renderCalendar(); renderOverview();
}

// ── Render: Calendar ─────────────────────────────────────────────────────────
function renderCalendar() {
  document.getElementById('monthTitle').textContent = MONTHS[viewMonth] + ' ' + viewYear;
  const body = document.getElementById('calBody'); body.innerHTML = '';
  const dim     = getDays(viewYear, viewMonth);
  const firstDow = new Date(viewYear, viewMonth, 1).getDay();

  for (let i = 0; i < firstDow; i++) {
    const c = document.createElement('div'); c.className = 'cal-cell empty'; body.appendChild(c);
  }
  for (let d = 1; d <= dim; d++) {
    const date   = new Date(viewYear, viewMonth, d);
    const isWknd = date.getDay() === 0 || date.getDay() === 6;
    const isTdy  = date.getTime() === today.getTime();
    const leaves = getLeavesForDay(date);
    const cell   = document.createElement('div');
    cell.className = 'cal-cell' + (isWknd ? ' weekend' : '') + (leaves.length ? ' has-leave' : '');

    const num = document.createElement('div');
    num.className = 'day-num' + (isTdy ? ' today' : '');
    num.textContent = d; cell.appendChild(num);

    leaves.slice(0, 4).forEach(l => {
      const tag = document.createElement('div'); tag.className = 'leave-tag';
      tag.style.cssText = `background:${l.color.bg};border:1px solid ${l.color.border};color:${l.color.text}`;
      tag.textContent = l.employee.split(' ')[0]; cell.appendChild(tag);
    });
    if (leaves.length > 4) {
      const m = document.createElement('div');
      m.style.cssText = 'font-size:.6rem;color:var(--grey-mid);padding:1px 4px';
      m.textContent = '+' + (leaves.length - 4) + ' more'; cell.appendChild(m);
    }
    if (leaves.length) cell.onclick = () => showModal(d, leaves);
    body.appendChild(cell);
  }
}

// ── Render: Day modal ────────────────────────────────────────────────────────
function showModal(day, leaves) {
  const date = new Date(viewYear, viewMonth, day);
  document.getElementById('modalTitle').textContent =
    DAY_FULL[date.getDay()] + ', ' + MONTHS[viewMonth] + ' ' + day + ', ' + viewYear;
  const body = document.getElementById('modalBody'); body.innerHTML = '';
  leaves.forEach(l => {
    const d = document.createElement('div'); d.className = 'modal-leave';
    d.style.cssText = `background:${l.color.bg};border-color:${l.color.border}`;
    d.innerHTML = `<div class="modal-dot" style="background:${l.color.border}"></div>
                   <div><div class="modal-emp" style="color:${l.color.text}">${l.employee}</div>
                   <div class="modal-type">${l.leaveType}</div></div>`;
    body.appendChild(d);
  });
  document.getElementById('modalOverlay').classList.add('open');
}
function closeModal() { document.getElementById('modalOverlay').classList.remove('open'); }

// ── Render: Legend ───────────────────────────────────────────────────────────
function buildLegend() {
  const row = document.getElementById('legendRow');
  row.innerHTML = '<span class="legend-lbl">Employees:</span>';
  employees.forEach(emp => {
    const c    = empColors[emp.name];
    const chip = document.createElement('div');
    chip.className = 'leg-chip';
    chip.style.cssText = `background:${c.bg};border-color:${c.border};color:${c.text}`;
    chip.innerHTML = `<span style="width:9px;height:9px;border-radius:50%;background:${c.border};display:inline-block;flex-shrink:0"></span>
                      ${emp.name}&nbsp;<span style="font-weight:400;opacity:.65">${emp.leaves.length}</span>`;
    chip.onclick = () => {
      activeEmps[emp.name] = !activeEmps[emp.name];
      chip.classList.toggle('inactive', !activeEmps[emp.name]);
      renderCalendar(); renderOverview(); renderBalances();
    };
    row.appendChild(chip);
  });
}

// ── Render: Year overview ────────────────────────────────────────────────────
function renderOverview() {
  const el   = document.getElementById('overviewWrap'); el.innerHTML = '';
  const cols = '130px ' + Array(12).fill('1fr').join(' ');

  const hdr = document.createElement('div');
  hdr.className = 'month-labels-row';
  hdr.style.cssText = `display:grid;grid-template-columns:${cols};gap:3px`;
  hdr.innerHTML = '<div></div>' + MSHORT.map(m => `<div class="mlbl">${m}</div>`).join('');
  el.appendChild(hdr);

  employees.filter(e => activeEmps[e.name]).forEach(emp => {
    const c   = empColors[emp.name];
    const row = document.createElement('div');
    row.className = 'ov-row';
    row.style.cssText = `display:grid;grid-template-columns:${cols};gap:3px`;
    const nameEl = document.createElement('div'); nameEl.className = 'ov-name'; nameEl.textContent = emp.name;
    row.appendChild(nameEl);

    for (let m = 0; m < 12; m++) {
      const dim   = getDays(viewYear, m);
      const mStart = new Date(viewYear, m, 1), mEnd = new Date(viewYear, m + 1, 0);
      const overlapping = emp.leaves.filter(l => l.end >= mStart && l.start <= mEnd);
      let days = 0;
      for (let d = 1; d <= dim; d++) {
        const dt = new Date(viewYear, m, d);
        if (emp.leaves.some(l => dt >= l.start && dt <= l.end)) days++;
      }
      const cell  = document.createElement('div'); cell.className = 'ov-cell';
      const isCur = m === viewMonth;
      cell.style.cssText = `background:${days > 0 ? c.bg : 'var(--grey-lt)'};` +
        `border:${isCur ? '2px' : '1px'} solid ${days > 0 ? c.border : (isCur ? 'var(--red)' : 'var(--grey-lt)')};`;
      let tip = `${MONTHS[m]}: ${days} day${days !== 1 ? 's' : ''} away`;
      overlapping.forEach(l => { tip += `\n  ${l.leaveType}: ${fmtDate(l.start)} – ${fmtDate(l.end)}`; });
      cell.title   = tip;
      cell.onclick = () => { viewMonth = m; renderCalendar(); renderOverview(); };
      row.appendChild(cell);
    }
    el.appendChild(row);
  });
}

// ── Render: Balances table ───────────────────────────────────────────────────
function renderBalances() {
  const tbody = document.getElementById('balanceBody'); tbody.innerHTML = '';
  function fmt(v) {
    if (v === '' || v === null || v === undefined) return '<span style="color:var(--grey-mid)">—</span>';
    const n = Number(v); if (isNaN(n)) return v;
    const cls = n < 0 ? 'neg' : n > 0 ? 'pos' : '';
    return cls ? `<span class="${cls}">${n}</span>` : String(n);
  }
  employees.filter(e => activeEmps[e.name]).forEach(emp => {
    const b  = emp.balance || {};
    const c  = empColors[emp.name];
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td style="border-left:3px solid ${c.border};padding-left:10px">${emp.name}</td>
      <td>${fmt(b.open)}</td>
      <td>${fmt(b.alloc1)}</td><td>${fmt(b.used1)}</td><td>${fmt(b.bal1)}</td>
      <td>${fmt(b.alloc2)}</td><td>${fmt(b.used2)}</td><td>${fmt(b.bal2)}</td>
      <td style="font-weight:700">${fmt(b.remaining)}</td>`;
    tbody.appendChild(tr);
  });
}
</script>

<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
