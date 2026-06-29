<?php
require_once __DIR__ . '/../../includes/auth.php';
require_permission('operations');
$page_title = 'Operations Hub';
$extra_css = '
.tab-nav{background:var(--black);display:flex;gap:0;padding:0 32px;overflow-x:auto;}
.tab-btn{font-family:"Open Sans",sans-serif;font-size:.75rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:13px 18px;border:none;background:transparent;color:rgba(255,255,255,.5);cursor:pointer;border-bottom:3px solid transparent;transition:all .2s;white-space:nowrap;}
.tab-btn:hover{color:rgba(255,255,255,.85);}
.tab-btn.active{color:var(--white);border-bottom-color:var(--red);}
.tab-panel{display:none;}
.tab-panel.active{display:block;}

/* ── CTRL BAR ── */
.ctrl-bar{background:var(--red-dk);padding:14px 40px;display:flex;align-items:flex-start;gap:18px;flex-wrap:wrap;}
.ctrl-lbl{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.7);}
.mode-toggle{display:flex;background:rgba(0,0,0,.25);border-radius:6px;overflow:hidden;}
.mode-btn{font-family:"Open Sans",sans-serif;font-size:.73rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:8px 13px;border:none;background:transparent;color:rgba(255,255,255,.6);cursor:pointer;transition:all .15s;}
.mode-btn.active{background:var(--white);color:var(--red-dk);}
input[type=date]{font-family:"Open Sans",sans-serif;font-size:.88rem;padding:8px 12px;border:1.5px solid rgba(255,255,255,.25);border-radius:6px;background:rgba(255,255,255,.1);color:var(--white);cursor:pointer;}
input[type=date]:focus{outline:none;border-color:rgba(255,255,255,.6);}
input[type=date]::-webkit-calendar-picker-indicator{filter:invert(1);}
.btn-show{font-family:"Open Sans",sans-serif;font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;padding:9px 20px;border:none;border-radius:6px;background:var(--white);color:var(--red-dk);cursor:pointer;transition:background .15s;}
.btn-show:hover{background:var(--grey-lt);}
.btn-ghost{background:rgba(255,255,255,.15)!important;color:var(--white)!important;border:1px solid rgba(255,255,255,.3);}
.btn-ghost:hover{background:rgba(255,255,255,.25)!important;}

/* ── MINI CALENDAR ── */
.cal-wrap{background:rgba(0,0,0,.2);border-radius:8px;padding:10px;}
.cal-nav{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;}
.cal-nav button{background:rgba(255,255,255,.15);border:none;color:var(--white);border-radius:4px;width:24px;height:24px;cursor:pointer;font-size:.85rem;}
.cal-nav button:hover{background:rgba(255,255,255,.3);}
.cal-month-lbl{font-size:.78rem;font-weight:700;color:var(--white);text-transform:uppercase;letter-spacing:.05em;}
.cal-grid{display:grid;grid-template-columns:repeat(7,28px);gap:2px;}
.cal-dow{font-size:.58rem;font-weight:700;text-transform:uppercase;color:rgba(255,255,255,.5);text-align:center;padding-bottom:3px;}
.cal-day{width:28px;height:26px;display:flex;align-items:center;justify-content:center;border-radius:4px;font-size:.75rem;color:rgba(255,255,255,.8);cursor:pointer;transition:all .12s;border:1.5px solid transparent;user-select:none;position:relative;}
.cal-day:hover:not(.cal-empty){background:rgba(255,255,255,.2);}
.cal-day.has-data{font-weight:700;}
.cal-day.has-data::after{content:"";position:absolute;bottom:2px;left:50%;transform:translateX(-50%);width:4px;height:4px;border-radius:50%;background:rgba(255,255,255,.7);}
.cal-day.selected{background:var(--white);color:var(--red-dk);font-weight:700;}
.cal-day.cal-empty{cursor:default;}
.cal-day.today{border-color:rgba(255,255,255,.5);}
.sel-chips{display:flex;flex-wrap:wrap;gap:4px;margin-top:6px;max-width:220px;}
.sel-chip{background:rgba(255,255,255,.2);color:var(--white);border-radius:4px;padding:2px 8px;font-size:.7rem;font-weight:600;display:flex;align-items:center;gap:4px;}
.sel-chip button{background:none;border:none;color:rgba(255,255,255,.7);cursor:pointer;font-size:.75rem;line-height:1;padding:0;}

/* ── MOVEMENTS TABLE ── */
.mov-main{padding:26px 40px 60px;}
.date-block{margin-bottom:34px;border-left:4px solid var(--red);padding-left:18px;}
.date-block-header{display:flex;align-items:center;gap:12px;margin-bottom:10px;flex-wrap:wrap;}
.date-block-header h2{font-family:"Merriweather",serif;font-size:1.15rem;font-weight:700;color:var(--red-dk);}
.summary{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;}
.chip{display:flex;align-items:center;gap:8px;padding:8px 14px;border-radius:8px;font-size:.8rem;font-weight:600;}
.chip .num{font-family:"Merriweather",serif;font-size:1.3rem;font-weight:700;line-height:1;}
.chip-label{font-size:.68rem;text-transform:uppercase;letter-spacing:.07em;opacity:.75;}
.chip-arr{background:var(--green-lt);color:var(--green);}
.chip-dep{background:var(--red-lt);color:var(--red-dk);}
.chip-pax{background:#EAE6F0;color:#4A3575;}
.sec-title{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--grey-mid);margin-bottom:8px;display:flex;align-items:center;gap:7px;}
.sec-title::before{content:"";display:inline-block;width:7px;height:7px;border-radius:50%;}
.sec-arr::before{background:var(--green);}
.sec-dep::before{background:var(--red);}
.tbl-wrap{overflow-x:auto;border-radius:8px;box-shadow:0 1px 8px rgba(0,0,0,.08);margin-bottom:16px;}
.mov-table{width:100%;border-collapse:collapse;font-size:.83rem;background:var(--white);}
.mov-table thead th{background:var(--black);color:rgba(255,255,255,.85);font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;padding:9px 11px;text-align:left;white-space:nowrap;}
.mov-table tbody tr{border-bottom:1px solid var(--grey-lt);transition:background .1s;}
.mov-table tbody tr:last-child{border-bottom:none;}
.mov-table tbody tr:hover{background:#FBF8F5;}
.mov-table td{padding:8px 11px;vertical-align:middle;}
.td-time{font-weight:700;color:var(--red-dk);}
.td-flight{font-family:monospace;font-size:.8rem;color:#1A4D8A;font-weight:700;}
.td-pax{font-weight:700;}
.td-notes{color:var(--grey-mid);font-size:.77rem;font-style:italic;}
.btn-edit{font-size:.67rem;padding:3px 9px;background:var(--navy-lt);color:var(--navy);border:none;border-radius:4px;cursor:pointer;font-weight:700;margin-right:4px;}
.btn-edit:hover{background:#cfe0f5;}
.btn-del{font-size:.67rem;padding:3px 9px;background:var(--red-lt);color:var(--red-dk);border:none;border-radius:4px;cursor:pointer;font-weight:700;}
.btn-del:hover{background:#f5d0ce;}
.btn-wa{font-family:"Open Sans",sans-serif;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:5px 11px;border:1.5px solid var(--red);border-radius:5px;background:var(--white);color:var(--red-dk);cursor:pointer;transition:all .15s;}
.btn-wa:hover{background:var(--red-lt);}
.placeholder{text-align:center;padding:60px 20px;color:var(--grey-mid);}
.placeholder .icon{font-size:2.5rem;margin-bottom:10px;}
.placeholder h2{font-family:"Merriweather",serif;font-size:1rem;color:var(--red-dk);margin-bottom:5px;}

/* ── ADD/EDIT MODAL ── */
.mov-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;align-items:center;justify-content:center;}
.mov-modal-overlay.open{display:flex;}
.mov-modal{background:var(--white);border-radius:12px;padding:26px;width:min(700px,95vw);max-height:90vh;overflow-y:auto;box-shadow:0 8px 40px rgba(0,0,0,.25);}
.mov-modal h3{font-family:"Merriweather",serif;font-size:1rem;color:var(--red-dk);margin-bottom:18px;}
.mov-modal input[type=date]{color:var(--black);background:var(--white);border:1.5px solid var(--grey-lt);}
.mov-modal input[type=date]:focus{border-color:var(--red);}
.mov-modal input[type=date]::-webkit-calendar-picker-indicator{filter:none;}


/* ── EXCEL ACTION BAR (tabs 2+) ── */
.excel-action-bar{margin:0 40px 8px;background:var(--black);border-radius:10px;padding:13px 18px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.file-label{color:rgba(255,255,255,.6);font-size:.78rem;font-style:italic;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.btn-reextract{font-family:"Open Sans",sans-serif;font-size:.78rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;padding:8px 18px;border-radius:6px;border:none;cursor:pointer;background:var(--green);color:var(--white);transition:all .2s;}
.btn-reextract:hover{background:#245530;}
.btn-reextract:disabled{opacity:.4;cursor:default;}
.btn-audit-act{font-family:"Open Sans",sans-serif;font-size:.78rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;padding:8px 18px;border-radius:6px;border:none;cursor:pointer;background:var(--amber);color:var(--black);transition:all .2s;}
.btn-audit-act:hover{filter:brightness(.88);}

/* ── UPLOAD ZONE ── */
.upload-zone{margin:24px 40px;border:2.5px dashed var(--grey-lt);border-radius:12px;padding:44px 24px;text-align:center;background:var(--white);transition:all .2s;cursor:pointer;}
.upload-zone:hover,.upload-zone.drag{border-color:var(--red);background:var(--red-lt);}
.upload-zone .uz-icon{font-size:2.8rem;margin-bottom:12px;}
.upload-zone h2{font-family:"Merriweather",serif;font-size:1.1rem;color:var(--red-dk);margin-bottom:6px;}
.upload-zone p{font-size:.82rem;color:var(--grey-mid);}

/* ── ROW CARDS (Extractor) ── */
.ext-main{padding:0 40px 40px;}
.info-box{background:var(--amber-lt);border-left:4px solid var(--amber);border-radius:6px;padding:11px 15px;margin-bottom:18px;font-size:.82rem;color:var(--grey-dk);}
.row-lbl{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--grey-mid);margin:16px 0 6px;display:flex;align-items:center;gap:7px;}
.row-lbl::before{content:"";display:inline-block;width:8px;height:8px;border-radius:50%;}
.lbl-arrival::before{background:var(--green);}
.lbl-departure::before{background:var(--red);}
.lbl-transfer::before{background:var(--amber);}
.row-note{font-size:.7rem;color:var(--grey-mid);font-style:italic;margin-left:8px;}
/* Night movement shown on the previous day */
.next-day-badge{display:inline-block;background:var(--amber);color:var(--white);font-size:.66rem;font-weight:700;line-height:1;padding:3px 7px;border-radius:10px;margin-left:7px;white-space:nowrap;vertical-align:middle;letter-spacing:.02em;}
.mov-table tr.row-next-day{background:var(--amber-lt);}
.row-card{background:var(--white);border-radius:10px;box-shadow:0 1px 8px rgba(0,0,0,.07);margin-bottom:10px;overflow:hidden;}
.row-card-header{display:flex;align-items:center;justify-content:space-between;padding:8px 13px;border-bottom:1px solid var(--grey-lt);background:var(--off-white);}
.badge-arrival{background:var(--green-lt);color:var(--green);font-size:.65rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:3px 10px;border-radius:20px;}
.badge-departure{background:var(--red-lt);color:var(--red-dk);font-size:.65rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:3px 10px;border-radius:20px;}
.badge-transfer{background:var(--amber-lt);color:var(--amber);font-size:.65rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:3px 10px;border-radius:20px;}
.row-actions{display:flex;gap:6px;}
.btn-sm2{font-family:"Open Sans",sans-serif;font-size:.65rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;padding:4px 10px;border-radius:4px;cursor:pointer;border:1.5px solid;transition:all .15s;}
.btn-copy-row{border-color:var(--red);color:var(--red-dk);background:var(--white);}
.btn-copy-row:hover{background:var(--red-lt);}
.btn-send-row{border-color:var(--green);color:var(--green);background:var(--white);}
.btn-send-row:hover{background:var(--green-lt);}
.btn-send-row.sent{background:var(--green-lt);border-color:var(--green);color:var(--green);}
.btn-del-card{border-color:var(--grey-lt);color:var(--grey-mid);background:var(--white);}
.btn-del-card:hover{border-color:#e00;color:#e00;}
.row-card.saved .row-card-header{background:var(--green-lt);}
.row-card.saved{border-left:4px solid var(--green);}
.row-card.dup-error .row-card-header{background:var(--red-lt);}
.row-card.dup-error{border-left:4px solid var(--red);}
.btn-save-row{border-color:var(--navy);color:var(--navy);background:var(--white);}
.btn-save-row:hover{background:var(--navy-lt);}
.btn-save-row.saved{border-color:var(--green);color:var(--green);background:var(--green-lt);}
.saved-badge{font-size:.62rem;font-weight:700;color:var(--green);background:var(--green-lt);border:1px solid var(--green);border-radius:4px;padding:2px 7px;margin-left:6px;}
.dup-badge{font-size:.62rem;font-weight:700;color:var(--red-dk);background:var(--red-lt);border:1px solid var(--red);border-radius:4px;padding:2px 7px;margin-left:6px;}
.row-card.saved{border-left:4px solid var(--green);}
.row-card.saved .row-card-header{background:var(--green-lt);}
.row-card.dup-error{border-left:4px solid var(--red);}
.row-card.dup-error .row-card-header{background:var(--red-lt);}
.btn-save-row{border-color:var(--navy);color:var(--navy);background:var(--white);}
.btn-save-row:hover{background:var(--navy-lt);}
.btn-save-row.saving{opacity:.6;cursor:default;}
.btn-save-row.saved{border-color:var(--green);color:var(--green);background:var(--green-lt);}
.saved-badge{font-size:.62rem;font-weight:700;color:var(--green);background:var(--green-lt);border:1px solid var(--green);border-radius:4px;padding:2px 7px;margin-left:6px;}
.dup-badge{font-size:.62rem;font-weight:700;color:var(--red-dk);background:var(--red-lt);border:1px solid var(--red);border-radius:4px;padding:2px 7px;margin-left:6px;}
/* Grid DB toolbar */
.grid-db-bar{background:var(--off-white);border-bottom:1px solid var(--grey-lt);padding:10px 40px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.grid-db-bar label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey-dk);}
.grid-db-bar input[type=date]{font-family:"Open Sans",sans-serif;font-size:.85rem;padding:7px 10px;border:1.5px solid var(--grey-lt);border-radius:6px;color:var(--black);}
.grid-db-bar input[type=date]:focus{outline:none;border-color:var(--red);}
.fields-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:9px;padding:11px 13px;}
.field{display:flex;flex-direction:column;gap:3px;}
.field label{font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:var(--grey-mid);}
.field input{font-family:"Open Sans",sans-serif;font-size:.83rem;padding:6px 8px;border:1.5px solid var(--grey-lt);border-radius:5px;color:var(--black);background:var(--off-white);transition:border-color .15s;}
.field input:focus{outline:none;border-color:var(--red);background:var(--white);}
.field input[type=date]::-webkit-calendar-picker-indicator{filter:none;}
.add-row-bar{display:flex;gap:8px;margin:14px 0;flex-wrap:wrap;}
.btn-add-row2{background:var(--white);border:1.5px solid var(--grey-lt);color:var(--grey-dk);font-family:"Open Sans",sans-serif;font-size:.75rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:7px 15px;border-radius:6px;cursor:pointer;transition:all .15s;}
.btn-add-row2:hover{border-color:var(--red);color:var(--red-dk);}
.copy-all-bar{background:var(--black);border-radius:10px;padding:13px 18px;display:flex;align-items:center;justify-content:space-between;margin-top:22px;gap:12px;flex-wrap:wrap;}
.copy-all-bar p{color:rgba(255,255,255,.7);font-size:.8rem;}
.copy-all-bar p strong{color:var(--white);}
.btn-copy-all2{background:var(--red-dk);color:var(--white);font-family:"Open Sans",sans-serif;font-size:.75rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;padding:8px 20px;border:none;border-radius:6px;cursor:pointer;transition:all .2s;}
.btn-copy-all2:hover{background:var(--red);}
.btn-send-all{background:var(--green)!important;}

/* ── MOVEMENTS SAFARI GRID ── */
.grid-toolbar{background:var(--red-dk);padding:13px 40px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.btn-grid{font-family:"Open Sans",sans-serif;font-size:.75rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;padding:8px 16px;border-radius:6px;cursor:pointer;transition:all .2s;border:none;}
.btn-grid-outline{background:rgba(255,255,255,.12);color:var(--white);border:1px solid rgba(255,255,255,.3)!important;}
.btn-grid-outline:hover{background:rgba(255,255,255,.22);}
.grid-wrap{padding:22px 40px 40px;overflow-x:auto;}
.movements-table{width:100%;border-collapse:collapse;font-size:.82rem;background:var(--white);border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.08);}
.movements-table thead th{background:var(--black);color:var(--white);font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;padding:10px 10px;text-align:left;white-space:nowrap;}
.movements-table tbody tr{border-bottom:1px solid var(--grey-lt);}
.movements-table tbody tr:last-child{border-bottom:none;}
.movements-table tbody tr:hover{background:#FBF8F5;}
.movements-table tbody tr.row-arrival td:first-child{border-left:4px solid var(--green);}
.movements-table tbody tr.row-departure td:first-child{border-left:4px solid var(--red);}
.movements-table tbody tr.row-transfer td:first-child{border-left:4px solid var(--amber);}
.movements-table td{padding:7px 10px;vertical-align:middle;}
.movements-table td input{font-family:"Open Sans",sans-serif;font-size:.82rem;padding:4px 6px;border:1.5px solid transparent;border-radius:4px;background:transparent;width:100%;min-width:80px;color:var(--black);transition:border-color .15s;}
.movements-table td input:focus{outline:none;border-color:var(--red);background:var(--white);}
.col-date{width:90px;}.col-type{width:95px;}.col-pax{width:44px;}.col-time{width:58px;}.col-flight{width:90px;}.col-actions-g{width:60px;text-align:center;}
.btn-del-row2{background:none;border:none;cursor:pointer;color:var(--grey-mid);font-size:1rem;padding:2px 6px;border-radius:4px;transition:all .15s;}
.btn-del-row2:hover{color:#e00;background:var(--red-lt);}
.btn-add-row3{font-family:"Open Sans",sans-serif;font-size:.73rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:7px 15px;border:1.5px solid var(--grey-lt);border-radius:6px;cursor:pointer;background:var(--white);color:var(--grey-dk);margin:12px 0;transition:all .15s;}
.btn-add-row3:hover{border-color:var(--red);color:var(--red-dk);}
.grid-placeholder{text-align:center;padding:70px 20px;color:var(--grey-mid);}
.grid-placeholder .icon{font-size:3rem;margin-bottom:12px;}
.grid-placeholder h2{font-family:"Merriweather",serif;font-size:1.2rem;color:var(--red-dk);margin-bottom:8px;}
.type-select{font-family:"Open Sans",sans-serif;font-size:.78rem;padding:4px 4px;border:1.5px solid transparent;border-radius:4px;background:transparent;color:var(--black);cursor:pointer;width:100%;transition:border-color .15s;}
.type-select:focus{outline:none;border-color:var(--red);background:var(--white);}

/* ── AUDIT PANEL ── */
.audit-panel{margin:0 40px 16px;border-radius:10px;overflow:hidden;box-shadow:0 2px 14px rgba(0,0,0,.1);}
.audit-header{background:var(--black);padding:10px 16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.audit-title{font-family:"Merriweather",serif;font-size:.82rem;font-weight:700;color:var(--white);white-space:nowrap;}
.audit-summary{display:flex;gap:8px;flex:1;flex-wrap:wrap;align-items:center;}
.audit-count{font-size:.72rem;font-weight:700;padding:3px 10px;border-radius:12px;}
.audit-count-error{background:rgba(192,33,27,.35);color:#ffaaaa;}
.audit-count-warning{background:rgba(245,158,11,.25);color:#ffd580;}
.audit-count-info{background:rgba(26,77,138,.3);color:#90bfff;}
.audit-count-ok{background:rgba(46,107,62,.3);color:#80d09a;}
.audit-close{background:none;border:none;color:rgba(255,255,255,.45);font-size:1.1rem;cursor:pointer;margin-left:auto;padding:2px 7px;border-radius:4px;transition:all .15s;}
.audit-close:hover{color:var(--white);}
.audit-list{background:var(--white);}
.audit-item{display:flex;align-items:flex-start;gap:10px;padding:9px 16px;border-bottom:1px solid var(--grey-lt);font-size:.82rem;line-height:1.45;}
.audit-item:last-child{border-bottom:none;}
.audit-item-error{background:#FFF5F5;}
.audit-item-warning{background:#FFFCF0;}
.audit-item-info{background:#F5F8FF;}
.audit-ok-row{display:flex;align-items:center;gap:10px;padding:13px 16px;background:var(--green-lt);color:var(--green);font-size:.84rem;font-weight:600;}

/* ── EXT COPY MODAL ── */
.ext-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;align-items:center;justify-content:center;}
.ext-modal-overlay.open{display:flex;}
.ext-modal{background:var(--white);border-radius:12px;padding:26px;width:min(660px,93vw);box-shadow:0 8px 40px rgba(0,0,0,.25);display:flex;flex-direction:column;gap:12px;}
.ext-modal h3{font-family:"Merriweather",serif;font-size:1rem;color:var(--red-dk);}
.ext-modal p{font-size:.78rem;color:var(--grey-mid);}
.ext-modal textarea{width:100%;height:200px;font-family:monospace;font-size:.78rem;line-height:1.6;border:1.5px solid var(--grey-lt);border-radius:6px;padding:10px;resize:vertical;color:var(--black);}
.ext-modal-actions{display:flex;gap:10px;justify-content:flex-end;}
.ext-modal-actions button{font-family:"Open Sans",sans-serif;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:8px 18px;border-radius:6px;border:none;cursor:pointer;}
.btn-modal-copy{background:var(--red-dk);color:var(--white);}
.btn-modal-copy:hover{background:var(--red);}
.btn-modal-copy.copied{background:var(--green);}
.btn-modal-close{background:var(--grey-lt);color:var(--grey-dk);}

/* ── FIX CONFLICTS ── */
.cf-wrap{padding:26px 40px 60px;}
.cf-drop{border:2.5px dashed var(--grey-lt);border-radius:10px;padding:36px;text-align:center;background:var(--white);cursor:pointer;transition:all .2s;margin-bottom:14px;}
.cf-drop:hover,.cf-drop.drag{border-color:var(--red);background:var(--red-lt);}
.cf-drop .dz-icon{font-size:2.3rem;margin-bottom:8px;}
.cf-drop p{font-size:.84rem;color:var(--grey-mid);}
.cf-status{font-size:.82rem;margin:10px 0;color:var(--grey-dk);}
.disc-tbl{width:100%;border-collapse:collapse;font-size:.8rem;margin-top:14px;}
.disc-tbl th{background:var(--black);color:rgba(255,255,255,.8);padding:8px 11px;text-align:left;font-size:.65rem;text-transform:uppercase;letter-spacing:.08em;}
.disc-tbl td{padding:8px 11px;border-bottom:1px solid var(--grey-lt);vertical-align:middle;}
.disc-tbl tr:last-child td{border-bottom:none;}
.disc-tbl tr:hover td{background:#FAFAFA;}
.badge-conflict{background:var(--red-lt);color:var(--red-dk);padding:2px 8px;border-radius:4px;font-size:.65rem;font-weight:700;text-transform:uppercase;}
.badge-gap{background:#FFF8E1;color:#7A4F01;padding:2px 8px;border-radius:4px;font-size:.65rem;font-weight:700;text-transform:uppercase;}
.val-sel{font-size:.78rem;padding:4px 8px;border:1.5px solid var(--grey-lt);border-radius:4px;background:var(--white);}
.cf-actions{margin-top:18px;display:flex;gap:12px;}

@media(max-width:700px){
  .ctrl-bar,.mov-main,.cf-wrap,.ext-main,.grid-wrap,.grid-toolbar,.tab-nav{padding-left:16px;padding-right:16px;}
  .excel-action-bar,.audit-panel{margin-left:16px;margin-right:16px;}
  .upload-zone{margin:16px;}
}
';
include __DIR__ . '/../../includes/layout_header.php';
?>

<!-- TAB NAV -->
<div class="tab-nav">
  <?php if (!$_isOpsStaff): ?>
  <button class="tab-btn active"    onclick="switchTab('movements',this)">📅 Daily Movements</button>
  <button class="tab-btn"           onclick="switchTab('extractor',this)">⚙️ Movement Extractor</button>
  <button class="tab-btn"           onclick="switchTab('grid',this)">📋 Movements Safari</button>
  <?php endif; ?>
  <button class="tab-btn <?= $_isOpsStaff ? 'active' : '' ?>" onclick="switchTab('audit',this)">🔍 Audit Excel</button>
  <button class="tab-btn"           onclick="switchTab('conflicts',this)">🔀 Fix Conflicts</button>
  <a class="tab-btn" href="medivac.php"     style="text-decoration:none;">🏥 Medivac</a>
  <a class="tab-btn" href="lunch_boxes.php" style="text-decoration:none;">🍱 Lunch Boxes</a>
</div>

<!-- ═══ TAB 1: DAILY MOVEMENTS ═══ -->
<div id="tab-movements" class="tab-panel <?= !$_isOpsStaff ? 'active' : '' ?>">
  <div class="ctrl-bar">
    <div style="display:flex;align-items:center;gap:10px;">
      <span class="ctrl-lbl">Mode</span>
      <div class="mode-toggle">
        <button class="mode-btn active" id="btn-single"   onclick="setMode('single')">Single Date</button>
        <button class="mode-btn"        id="btn-range"    onclick="setMode('range')">Date Range</button>
        <button class="mode-btn"        id="btn-multiple" onclick="setMode('multiple')">Multiple Dates</button>
      </div>
    </div>
    <div id="ctrl-single" style="display:flex;align-items:center;gap:8px;">
      <span class="ctrl-lbl">Date</span>
      <input type="date" id="inp-date" value="<?= date('Y-m-d') ?>">
    </div>
    <div id="ctrl-range" style="display:none;align-items:center;gap:8px;">
      <span class="ctrl-lbl">From</span>
      <input type="date" id="inp-from" value="<?= date('Y-m-d') ?>" onchange="const t=document.getElementById('inp-to');if(!t.value||t.value<this.value)t.value=this.value;">
      <span class="ctrl-lbl">To</span>
      <input type="date" id="inp-to"   value="<?= date('Y-m-d') ?>">
    </div>
    <div id="ctrl-multiple" style="display:none;">
      <div class="cal-wrap">
        <div class="cal-nav">
          <button onclick="calPrev()">&#8249;</button>
          <span class="cal-month-lbl" id="cal-month-lbl"></span>
          <button onclick="calNext()">&#8250;</button>
        </div>
        <div class="cal-grid" id="cal-grid"></div>
        <div class="sel-chips" id="sel-chips"></div>
      </div>
    </div>
    <div style="display:flex;gap:8px;">
      <button class="btn-show" onclick="loadMovements()">Show Movements</button>
      <button class="btn-show btn-ghost" onclick="openAddModal()">&#10133; Add</button>
    </div>
  </div>
  <div class="mov-main" id="mov-output">
    <div class="placeholder"><div class="icon">&#129426;</div><h2>Select a date and click Show Movements</h2><p>Data is loaded from the database.</p></div>
  </div>
</div>

<!-- ═══ TAB 2: MOVEMENT EXTRACTOR ═══ -->
<div id="tab-extractor" class="tab-panel">
  <div class="excel-action-bar" id="excelActionBar" style="display:none;">
    <span class="file-label" id="excelFileName">&#8212;</span>
    <button class="btn-reextract" id="btnReextract" onclick="reExtractAfterEdit()">&#8635; Re-extract</button>
    <button class="btn-audit-act" id="btnAudit"     onclick="runAudit()">&#128269; Audit</button>
  </div>
  <div class="upload-zone" id="extUploadZone"
       onclick="document.getElementById('extFileInput').click()"
       ondragover="event.preventDefault();this.classList.add('drag')"
       ondragleave="this.classList.remove('drag')"
       ondrop="handleExtDrop(event)">
    <div class="uz-icon">&#128194;</div>
    <h2 id="extUploadTitle">Load Safari Calc Excel</h2>
    <p id="extUploadSub">Drop the file here or click to browse &mdash; reads CONF or RECAP sheet automatically</p>
    <input type="file" id="extFileInput" accept=".xlsx,.xls" onchange="loadExtFile(this.files[0])" style="display:none">
  </div>
  <div id="auditPanelExt" style="display:none;"></div>
  <div class="add-row-bar" id="extAddBar" style="padding:0 4px;">
    <button class="btn-add-row2" onclick="addExtRow('Arrival')">+ Add Arrival</button>
    <button class="btn-add-row2" onclick="addExtRow('Departure')">+ Add Departure</button>
    <button class="btn-add-row2" onclick="addExtRow('Transfer')" style="border-color:var(--amber);color:var(--amber);">+ Add Transfer</button>
    <button class="btn-add-row2" onclick="cancelExtractor()" style="margin-left:auto;border-color:var(--red);color:var(--red-dk);">&#10005; Cancel</button>
  </div>
  <div class="ext-main" id="extMain"></div>
</div>

<!-- ═══ TAB 3: MOVEMENTS SAFARI ═══ -->
<div id="tab-grid" class="tab-panel">
  <!-- Grid controls - same style as Daily Movements ctrl-bar -->
  <div class="ctrl-bar">
    <div style="display:flex;align-items:center;gap:10px;">
      <span class="ctrl-lbl">Mode</span>
      <div class="mode-toggle">
        <button class="mode-btn active" id="gbtn-single"   onclick="setGridMode('single')">Single Date</button>
        <button class="mode-btn"        id="gbtn-range"    onclick="setGridMode('range')">Date Range</button>
        <button class="mode-btn"        id="gbtn-multiple" onclick="setGridMode('multiple')">Multiple Dates</button>
      </div>
    </div>
    <div id="gctrl-single" style="display:flex;align-items:center;gap:8px;">
      <span class="ctrl-lbl">Date</span>
      <input type="date" id="grid-single-date" value="<?= date('Y-m-d') ?>">
    </div>
    <div id="gctrl-range" style="display:none;align-items:center;gap:8px;">
      <span class="ctrl-lbl">From</span>
      <input type="date" id="grid-from" value="<?= date('Y-m-d') ?>" onchange="const t=document.getElementById('grid-to');if(!t.value||t.value<this.value)t.value=this.value;">
      <span class="ctrl-lbl">To</span>
      <input type="date" id="grid-to" value="<?= date('Y-m-d') ?>">
    </div>
    <div id="gctrl-multiple" style="display:none;">
      <div class="cal-wrap">
        <div class="cal-nav">
          <button onclick="gcalPrev()">&#8249;</button>
          <span class="cal-month-lbl" id="gcal-month-lbl"></span>
          <button onclick="gcalNext()">&#8250;</button>
        </div>
        <div class="cal-grid" id="gcal-grid"></div>
        <div class="sel-chips" id="gsel-chips"></div>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
      <span class="ctrl-lbl">Client</span>
      <input type="text" id="grid-client-filter" placeholder="Search client…" style="font-family:'Open Sans',sans-serif;font-size:.85rem;padding:7px 11px;border:1.5px solid rgba(255,255,255,.25);border-radius:6px;background:rgba(255,255,255,.1);color:var(--white);width:170px;" oninput="renderGridDB()" onkeydown="if(event.key==='Escape'){this.value='';renderGridDB();}">
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <button class="btn-show" onclick="loadGridFromDB()">Load Movements</button>
      <button class="btn-show btn-ghost" onclick="deleteSelectedRows()" id="btnDeleteSel" style="display:none;">&#128465; Delete Selected</button>
      <button class="btn-show btn-ghost" onclick="copyGridAllRows()" id="copyGridAllBtn">&#128203; Copy all</button>
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.8rem;color:var(--white);margin-left:6px;">
        <input type="checkbox" id="gridGroupByType" onchange="renderGridDB()" style="width:15px;height:15px;cursor:pointer;">
        Group by arrival, transfer and departure
      </label>
    </div>
  </div>
  <div class="grid-wrap" id="gridWrap">
    <div class="grid-placeholder">
      <div class="icon">&#128203;</div>
      <h2>Movements Safari</h2>
      <p>Select a date range above and click Load Movements</p>
    </div>
  </div>
</div>

<!-- Delete confirmation modal -->
<div id="delConfirmOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:var(--white);border-radius:12px;padding:28px 30px;width:min(440px,92vw);box-shadow:0 8px 40px rgba(0,0,0,.3);">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
      <span style="font-size:1.6rem;">&#9888;&#65039;</span>
      <h3 style="margin:0;font-size:1rem;color:var(--red-dk);" id="delConfirmTitle">Confirm deletion</h3>
    </div>
    <p id="delConfirmMsg" style="margin:0 0 8px;font-size:.88rem;color:var(--grey-dk);line-height:1.5;"></p>
    <p style="margin:0 0 22px;font-size:.82rem;color:var(--red-dk);font-weight:700;">&#128683; This action is permanent and cannot be undone.</p>
    <div style="display:flex;justify-content:flex-end;gap:10px;">
      <button onclick="closeDelConfirm()" style="font-family:'Open Sans',sans-serif;font-size:.8rem;font-weight:700;padding:8px 20px;border:1.5px solid var(--grey-lt);border-radius:6px;background:var(--white);cursor:pointer;">Cancel</button>
      <button id="delConfirmBtn" style="font-family:'Open Sans',sans-serif;font-size:.8rem;font-weight:700;padding:8px 20px;border:none;border-radius:6px;background:var(--red-dk);color:var(--white);cursor:pointer;">Delete</button>
    </div>
  </div>
</div>


<div id="tab-audit" class="tab-panel <?= $_isOpsStaff ? 'active' : '' ?>">
  <div class="excel-action-bar" id="auditTabActionBar" style="display:none;">
    <span class="file-label" id="auditTabFileName">&#8212;</span>
    <button class="btn-audit-act" onclick="runAuditTab()">&#8635; Re-run Audit</button>
  </div>
  <div class="upload-zone" id="auditUploadZone"
       onclick="document.getElementById('auditFileInput').click()"
       ondragover="event.preventDefault();this.classList.add('drag')"
       ondragleave="this.classList.remove('drag')"
       ondrop="handleAuditDrop(event)">
    <div class="uz-icon">&#128269;</div>
    <h2 id="auditUploadTitle">Load Safari Calc Excel</h2>
    <p>Drop the file here or click to browse &mdash; reads CONF or RECAP sheet automatically</p>
    <input type="file" id="auditFileInput" accept=".xlsx,.xls" onchange="loadAuditFile(this.files[0])" style="display:none">
  </div>
  <div id="auditPanelTab" style="display:none;"></div>
</div>

<!-- ═══ TAB 5: FIX CONFLICTS ═══ -->
<div id="tab-conflicts" class="tab-panel">
  <div class="cf-wrap">
    <div class="page-title">&#128256; Fix Conflicts &mdash; Compare &amp; Merge</div>
    <p style="font-size:.85rem;color:var(--grey-mid);margin-bottom:18px;max-width:680px;">Upload 2 or more conflicting Dropbox copies of the same Excel file. Everything runs in your browser &mdash; no data is sent to the server.</p>
    <div class="cf-drop" id="cf-drop" onclick="document.getElementById('cf-input').click()">
      <div class="dz-icon">&#128194;</div>
      <p><strong>Drag Excel files here or click to select</strong></p>
      <p style="margin-top:4px;">Select 2 or more conflicting .xlsx files</p>
      <input type="file" id="cf-input" multiple accept=".xlsx" style="display:none" onchange="cfLoadFiles(this.files)">
    </div>
    <div id="cf-status" class="cf-status"></div>
    <div id="cf-results"></div>
  </div>
</div>

<!-- MODALS -->
<!-- Add/Edit movement modal -->
<div class="mov-modal-overlay" id="movModalOverlay">
  <div class="mov-modal" id="movModalBox">
    <h3 id="movModalTitle">&#10133; Add New Movement</h3>
    <form id="movModalForm">
      <input type="hidden" id="mf-id" name="id" value="0">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Date *</label><input class="form-control" type="date" id="mf-date" name="move_date" required></div>
        <div class="form-group"><label class="form-label">Movement Type *</label>
          <select class="form-control" id="mf-type" name="movement_type" required>
            <option value="">-- Select --</option><option value="Arrival">Arrival</option><option value="Departure">Departure</option><option value="Transfer">Transfer</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Client / Group Name</label><input class="form-control" type="text" id="mf-client" name="client_name"></div>
        <div class="form-group"><label class="form-label">Pax</label><input class="form-control" type="number" id="mf-pax" name="pax" value="1" min="1"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Flight / Transfer</label><input class="form-control" type="text" id="mf-flight" name="flight" placeholder="e.g. TC 136"></div>
        <div class="form-group"><label class="form-label">Time</label>
          <div style="display:flex;gap:4px;align-items:center;">
            <select id="mf-hour" class="form-control" style="flex:1;min-width:0;">
              <option value="">--</option>
              <?php for($i=0;$i<24;$i++) printf('<option value="%02d">%02d</option>',$i,$i); ?>
            </select>
            <span style="font-weight:700;color:var(--grey-mid);">:</span>
            <select id="mf-min" class="form-control" style="flex:1;min-width:0;">
              <?php for($i=0;$i<60;$i++) printf('<option value="%02d">%02d</option>',$i,$i); ?>
            </select>
          </div>
          <input type="hidden" id="mf-time" name="move_time">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Pick Up</label><input class="form-control" type="text" id="mf-pickup" name="pickup"></div>
        <div class="form-group"><label class="form-label">Drop Off</label><input class="form-control" type="text" id="mf-dropoff" name="dropoff"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Driver / Guide</label><input class="form-control" type="text" id="mf-driver" name="driver" autocomplete="off"></div>
        <div class="form-group"><label class="form-label">Dropbox Folder</label><input class="form-control" type="text" id="mf-dropbox" name="dropbox_folder"></div>
      </div>
      <div class="form-group"><label class="form-label">Notes</label><input class="form-control" type="text" id="mf-notes" name="notes"></div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary" id="mf-submit">Save Movement</button>
        <button type="button" class="btn btn-secondary" onclick="closeAddModal()">Cancel</button>
      </div>
    </form>
    <div id="mf-msg" style="margin-top:10px;font-size:.84rem;"></div>
  </div>
</div>



<!-- Extractor copy modal -->
<div class="ext-modal-overlay" id="extModalOverlay" onclick="if(event.target===this)closeExtModal()">
  <div class="ext-modal">
    <h3>Copy to Movements Safari</h3>
    <p>Select all (Ctrl+A) and copy (Ctrl+C), then in Excel paste as Text to avoid date auto-conversion.</p>
    <textarea id="extModalText" readonly onclick="this.select()"></textarea>
    <div class="ext-modal-actions">
      <button class="btn-modal-close" onclick="closeExtModal()">Close</button>
      <button class="btn-modal-copy" id="extModalCopyBtn" onclick="copyExtModal()">Copy to clipboard</button>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
const BASE = '<?= BASE_URL ?>';

// ── Tab switching ──────────────────────────────────
function switchTab(name, btn) {
  document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.getElementById('tab-'+name).classList.add('active');
  btn.classList.add('active');
}

// ═══════════════════════════════════════════════════
// TAB 1 — DAILY MOVEMENTS (from DB)
// ═══════════════════════════════════════════════════
let currentMode='single', selDates=[], datesWithData=[], lastData=[], reqDates=[];
let calYear=new Date().getFullYear(), calMonth=new Date().getMonth();
const MONTHS=['January','February','March','April','May','June','July','August','September','October','November','December'];
const DAYS=['Su','Mo','Tu','We','Th','Fr','Sa'];

function setMode(m){
  currentMode=m;
  document.getElementById('ctrl-single').style.display   = (m==='single')   ? 'flex'  : 'none';
  document.getElementById('ctrl-range').style.display    = (m==='range')    ? 'flex'  : 'none';
  document.getElementById('ctrl-multiple').style.display = (m==='multiple') ? 'block' : 'none';
  document.getElementById('btn-single').classList.toggle('active',   m==='single');
  document.getElementById('btn-range').classList.toggle('active',    m==='range');
  document.getElementById('btn-multiple').classList.toggle('active', m==='multiple');
  if(m==='multiple') renderCalendar();
}

function calPrev(){calMonth--;if(calMonth<0){calMonth=11;calYear--;}renderCalendar();}
function calNext(){calMonth++;if(calMonth>11){calMonth=0;calYear++;}renderCalendar();}

function renderCalendar(){
  document.getElementById('cal-month-lbl').textContent=MONTHS[calMonth]+' '+calYear;
  const grid=document.getElementById('cal-grid');
  grid.innerHTML=DAYS.map(d=>'<div class="cal-dow">'+d+'</div>').join('');
  const first=new Date(calYear,calMonth,1).getDay();
  const days=new Date(calYear,calMonth+1,0).getDate();
  const today=new Date().toISOString().slice(0,10);
  for(let i=0;i<first;i++) grid.innerHTML+='<div class="cal-day cal-empty"></div>';
  for(let d=1;d<=days;d++){
    const iso=calYear+'-'+String(calMonth+1).padStart(2,'0')+'-'+String(d).padStart(2,'0');
    const cls=['cal-day',selDates.includes(iso)?'selected':'',iso===today?'today':'',datesWithData.includes(iso)?'has-data':''].filter(Boolean).join(' ');
    grid.innerHTML+='<div class="'+cls+'" data-iso="'+iso+'" onclick="toggleDate(this.dataset.iso)">'+d+'</div>';
  }
  renderChips();
}
function toggleDate(iso){const i=selDates.indexOf(iso);if(i>=0)selDates.splice(i,1);else selDates.push(iso);selDates.sort();renderCalendar();}
function renderChips(){document.getElementById('sel-chips').innerHTML=selDates.map(d=>{const[y,m,day]=d.split('-');return'<div class="sel-chip">'+day+'/'+m+' <button onclick="toggleDate(\''+d+'\')">x</button></div>';}).join('');}

function loadMovements(){
  const d1=document.getElementById('inp-date').value;
  const d2=document.getElementById('inp-from').value;
  const d3=document.getElementById('inp-to').value;
  let url=BASE+'/modules/operations/api/movements.php?mode='+currentMode+'&shift=1';
  if(currentMode==='single')   url+='&date='+d1;
  if(currentMode==='range')    url+='&from='+d2+'&to='+d3;
  if(currentMode==='multiple') url+='&dates='+selDates.join(',');
  document.getElementById('mov-output').innerHTML='<div class="placeholder"><div class="icon">&#8987;</div><h2>Loading...</h2></div>';
  fetch(url).then(r=>r.json()).then(data=>{
    if(data.error){showErr(data.error);return;}
    datesWithData=data.dates_with_data||[];
    lastData=data.rows||[];
    // Build full list of requested dates (including empty ones)
    if(currentMode==='single') reqDates=[d1];
    else if(currentMode==='range'){
      reqDates=[];
      let cur=new Date(d2+'T00:00:00'), end=new Date(d3+'T00:00:00');
      while(cur<=end){reqDates.push(cur.toISOString().slice(0,10));cur.setDate(cur.getDate()+1);}
    } else reqDates=[...selDates];
    if(currentMode==='multiple') renderCalendar();
    renderMovements(lastData);
  }).catch(e=>showErr(e.message));
}
function showErr(msg){document.getElementById('mov-output').innerHTML='<div class="placeholder"><div class="icon">!</div><h2>Error</h2><p>'+xss(msg)+'</p></div>';}

function fmtDateLabel(iso){
  const d=new Date(iso+'T00:00:00');
  const days=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
  const months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  return days[d.getDay()]+' '+d.getDate()+' '+months[d.getMonth()]+' '+d.getFullYear();
}
function renderMovements(rows){
  const out=document.getElementById('mov-output');
  const byDate={};
  rows.forEach(r=>{const dk=r.display_date||r.move_date;if(!byDate[dk])byDate[dk]=[];byDate[dk].push(r);});
  const allDates=[...new Set([...reqDates,...Object.keys(byDate)])].sort();
  if(!allDates.length){out.innerHTML='<div class="placeholder"><div class="icon">&#128235;</div><h2>No movements found</h2><p>No data for the selected date(s).</p></div>';return;}
  let html='';
  allDates.forEach(date=>{
    const dr=byDate[date]||[];
    const arr=dr.filter(r=>r.movement_type==='Arrival');
    const dep=dr.filter(r=>r.movement_type==='Departure');
    const trn=dr.filter(r=>r.movement_type==='Transfer');
    const pax=dr.reduce((s,r)=>s+parseInt(r.pax||0),0);
    const lbl=dr.length?(xss(dr[0].display_date_fmt||dr[0].move_date_fmt||date)):fmtDateLabel(date);
    html+='<div class="date-block"><div class="date-block-header"><h2>'+lbl+'</h2><button class="btn-wa" onclick="copyWADate(\''+date+'\',this)">&#128203; WhatsApp</button></div>';
    html+='<div class="summary"><div class="chip chip-arr"><span class="num">'+arr.length+'</span><div><div class="chip-label">Arrivals</div></div></div>';
    html+='<div class="chip chip-dep"><span class="num">'+dep.length+'</span><div><div class="chip-label">Departures</div></div></div>';
    if(trn.length) html+='<div class="chip chip-trn" style="background:var(--amber-lt);"><span class="num" style="color:var(--amber);">'+trn.length+'</span><div><div class="chip-label" style="color:var(--amber);">Transfers</div></div></div>';
    html+='<div class="chip chip-pax"><span class="num">'+pax+'</span><div><div class="chip-label">Total Pax</div></div></div></div>';
    if(arr.length) html+='<div class="sec-title sec-arr">Arrivals</div>'+buildMovTable(arr);
    if(dep.length) html+='<div class="sec-title sec-dep">Departures</div>'+buildMovTable(dep);
    if(trn.length) html+='<div class="sec-title" style="border-left:4px solid var(--amber);padding-left:12px;color:var(--amber);font-weight:700;margin:20px 0 8px;">Transfers</div>'+buildMovTable(trn);
    html+='</div>';
  });
  out.innerHTML=html;
}
function buildMovTable(rows){
  return '<div class="tbl-wrap"><table class="mov-table"><thead><tr><th>Client / Group</th><th>Pax</th><th>Flight</th><th>Time</th><th>Pick Up</th><th>Drop Off</th><th>Driver</th><th>Notes</th><th>Dropbox</th><th></th></tr></thead><tbody>'+
    rows.map(r=>'<tr'+(r.is_next_day?' class="row-next-day"':'')+'><td>'+xss(r.client_name)+(r.is_next_day?' <span class="next-day-badge">'+xss(r.next_day_label)+'</span>':'')+'</td><td class="td-pax">'+r.pax+'</td><td class="td-flight">'+xss(r.flight)+'</td><td class="td-time">'+xss(r.move_time_fmt)+'</td><td>'+xss(r.pickup)+'</td><td>'+xss(r.dropoff)+'</td><td>'+xss(r.driver)+'</td><td class="td-notes">'+xss(r.notes)+'</td><td style="font-size:.72rem;color:var(--grey-mid);">'+xss(r.dropbox_folder)+'</td><td><button class="btn-edit" onclick="editMov('+r.id+')">Edit</button><button class="btn-del" onclick="delMov('+r.id+',this)">Del</button></td></tr>').join('')+
    '</tbody></table></div>';
}
function xss(s){if(!s)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

// Add/Edit modal
var _editOrigDriver='', _editOrigClient='';
function openAddModal(r){
  document.getElementById('movModalForm').reset();
  document.getElementById('mf-id').value=0;
  _editOrigDriver=''; _editOrigClient='';
  if(r){
    document.getElementById('movModalTitle').textContent='Edit Movement';
    document.getElementById('mf-submit').textContent='Save Changes';
    document.getElementById('mf-id').value=r.id;
    document.getElementById('mf-date').value=r.move_date;
    document.getElementById('mf-type').value=r.movement_type;
    document.getElementById('mf-client').value=r.client_name||'';
    document.getElementById('mf-pax').value=r.pax||1;
    document.getElementById('mf-flight').value=r.flight||'';
    var t=r.move_time?r.move_time.slice(0,5):'';
    document.getElementById('mf-hour').value=t?t.slice(0,2):'';
    document.getElementById('mf-min').value=t?t.slice(3,5):'00';
    document.getElementById('mf-pickup').value=r.pickup||'';
    document.getElementById('mf-dropoff').value=r.dropoff||'';
    document.getElementById('mf-driver').value=r.driver||'';
    document.getElementById('mf-notes').value=r.notes||'';
    document.getElementById('mf-dropbox').value=r.dropbox_folder||'';
    _editOrigDriver=r.driver||'';
    _editOrigClient=r.client_name||'';
  } else {
    document.getElementById('movModalTitle').textContent='Add New Movement';
    document.getElementById('mf-submit').textContent='Save Movement';
    document.getElementById('mf-hour').value='';
    document.getElementById('mf-min').value='00';
  }
  document.getElementById('mf-msg').innerHTML='';
  document.getElementById('movModalOverlay').classList.add('open');
  _movModalReady=false; setTimeout(function(){_movModalReady=true;},250);
}
function closeAddModal(){document.getElementById('movModalOverlay').classList.remove('open');}
// Close modal only when mousedown hits the overlay itself.
// _movModalReady prevents the same fast-click that opened the modal from immediately closing it.
var _movModalReady=false;
document.getElementById('movModalOverlay').addEventListener('mousedown',function(e){
  if(_movModalReady && e.target===this) closeAddModal();
});

function editMov(id){const r=lastData.find(r=>parseInt(r.id)===id);if(r)openAddModal(r);}

document.getElementById('movModalForm').addEventListener('submit',function(e){
  e.preventDefault();
  var h=document.getElementById("mf-hour").value;
  var m=document.getElementById("mf-min").value;
  document.getElementById("mf-time").value=(h!=="")?(h+":"+m):"";
  var msg=document.getElementById('mf-msg');
  var _savedOrigDriver=_editOrigDriver, _savedOrigClient=_editOrigClient;
  var _newDriver=document.getElementById('mf-driver').value.trim();
  fetch(BASE+'/modules/operations/api/save_movement.php',{method:'POST',body:new FormData(this)})
    .then(function(r){return r.json();}).then(function(d){
      if(d.ok){
        msg.innerHTML='<span style="color:var(--green);font-weight:700;">✓ Saved!</span>';
        closeAddModal();
        loadMovements();
        if(gridDBData.length)loadGridFromDB();
        // Offer group guide update if driver changed on an existing movement
        if(_savedOrigDriver && _savedOrigClient && _newDriver && _newDriver!==_savedOrigDriver){
          var others=lastData.filter(function(m){return m.client_name===_savedOrigClient && (m.driver||'')=== _savedOrigDriver;});
          var prompt='Guide changed from "'+_savedOrigDriver+'" to "'+_newDriver+'".\n\nApply to ALL movements for "'+_savedOrigClient+'" where guide is "'+_savedOrigDriver+'"?';
          if(others.length>0) prompt+=' ('+others.length+' movement'+(others.length!==1?'s':'')+' in current view)';
          if(confirm(prompt)){
            var fd=new FormData();
            fd.append('client_name',_savedOrigClient);
            fd.append('old_driver',_savedOrigDriver);
            fd.append('new_driver',_newDriver);
            fetch(BASE+'/modules/operations/api/update_guide_for_group.php',{method:'POST',body:fd})
              .then(function(r){return r.json();}).then(function(d){
                if(d.ok){loadMovements();if(gridDBData.length)loadGridFromDB();
                  if(d.updated>0)alert('✓ Also updated '+d.updated+' other movement'+(d.updated!==1?'s':'')+'.');
                } else alert('Error updating group: '+(d.error||'unknown'));
              });
          }
        }
      }
      else if(d.duplicate){msg.innerHTML='<span style="color:var(--red-dk);">⚠ '+xss(d.message)+'</span>';}
      else msg.innerHTML='<span style="color:var(--red-dk);">Error: '+xss(d.error||'unknown')+'</span>';
    });
});

function delMov(id,btn){
  if(!confirm('Delete this movement?'))return;
  btn.disabled=true;
  const fd=new FormData();fd.append('id',id);
  fetch(BASE+'/modules/operations/api/delete_movement.php',{method:'POST',body:fd})
    .then(r=>r.json()).then(d=>{if(d.ok)loadMovements();else alert('Error: '+d.error);});
}

// ── Robust clipboard helper (works on iOS, Android, desktop) ──
function robustCopy(text, onSuccess, onFail) {
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(function(){ if(onSuccess) onSuccess(); }).catch(function(){ legacyCopy(text, onSuccess, onFail); });
  } else {
    legacyCopy(text, onSuccess, onFail);
  }
}
function legacyCopy(text, onSuccess, onFail) {
  var el = document.createElement('textarea');
  el.value = text;
  el.setAttribute('readonly', '');
  el.style.cssText = 'position:fixed;top:0;left:0;width:2em;height:2em;padding:0;border:none;outline:none;box-shadow:none;background:transparent;opacity:0;z-index:-1;';
  document.body.appendChild(el);
  var isiOS = /ipad|iphone|ipod/i.test(navigator.userAgent);
  if (isiOS) {
    var range = document.createRange();
    range.selectNodeContents(el);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    el.setSelectionRange(0, 999999);
  } else {
    el.focus();
    el.select();
  }
  var success = false;
  try { success = document.execCommand('copy'); } catch(e) {}
  document.body.removeChild(el);
  if (success) { if(onSuccess) onSuccess(); }
  else { if(onFail) onFail(); else alert('Copy failed — please copy manually:\n\n'+text.substring(0,200)+'…'); }
}

// WhatsApp — copy directly to clipboard, 4 sections always shown
function isJROorARK(text){if(!text)return false;const up=String(text).toUpperCase();return up.includes('JRO')||up.includes('KILI')||up.includes('KILIMANJARO')||up.includes('ARK')||up.includes('ARUSHA')||up.includes('SERONERA')||up.includes('KOGATENDE')||up.includes('GRUMETI')||up.includes('LOBO')||up.includes('NDUTU')||up.includes('MANYARA')||up.includes('NAMANGA')||up.includes('ISEBANIA')||up.includes('ISEBANI')||up.includes('TAVETA');}
function isCoastOrIsland(text){if(!text)return false;const up=String(text).toUpperCase();return up.includes('ZNZ')||up.includes('ZANZIBAR')||up.includes('PEMBA')||up.includes('MAFIA')||up.includes('DAR');}

function buildWALine(r){
  let line1='- '+(r.client_name||'—')+' '+r.pax+' pax';
  const isArrival=(r.movement_type==='Arrival');
  let line2='';
  if(isArrival){
    // Line 2: Arrival [pickup] at [time] [note] [flight] transfer to [dropoff]
    line2='Arrival';
    if(r.pickup)  line2+=' '+r.pickup;
    if(r.move_time_fmt||r.flight){
      line2+=' at';
      if(r.move_time_fmt) line2+=' '+r.move_time_fmt;
      if(r.notes)         line2+=' '+r.notes;
      if(r.flight)        line2+=' '+r.flight;
    } else if(r.notes){
      line2+=' '+r.notes;
    }
    if(r.dropoff) line2+=' transfer to '+r.dropoff;
  } else {
    // Line 2: From [pickup] [note] transfer to [dropoff], [time] [flight]
    if(r.pickup)  line2+='From '+r.pickup;
    if(r.notes)   line2+=' '+r.notes;
    if(r.dropoff) line2+=' transfer to '+r.dropoff;
    if(r.move_time_fmt||r.flight){
      line2+=',';
      if(r.move_time_fmt) line2+=' '+r.move_time_fmt;
      if(r.flight)        line2+=' '+r.flight;
    }
  }
  if(r.driver) line2+=' | driver '+r.driver;
  if(r.is_next_day) line2+=' | '+r.next_day_label;
  let line=line1+'\n'+line2;
  return line;
}

function buildWAForDate(dateRows){
  const date=dateRows[0].display_date_fmt||dateRows[0].move_date_fmt||dateRows[0].display_date||dateRows[0].move_date;
  const arrivals=dateRows.filter(r=>r.movement_type==='Arrival');
  const departures=dateRows.filter(r=>r.movement_type==='Departure');
  const arrJRO=arrivals.filter(r=>isJROorARK(r.pickup));
  const arrCoast=arrivals.filter(r=>isCoastOrIsland(r.pickup));
  const depJRO=departures.filter(r=>isJROorARK(r.dropoff));
  const depCoast=departures.filter(r=>isCoastOrIsland(r.dropoff));
  const arrOther=arrivals.filter(r=>!isJROorARK(r.pickup)&&!isCoastOrIsland(r.pickup));
  const depOther=departures.filter(r=>!isJROorARK(r.dropoff)&&!isCoastOrIsland(r.dropoff));

  let txt=date.toUpperCase()+' - ARRIVALS/DEPARTURES\n';
  txt+='-------------------------------------\n\n';

  function section(rows,label,alwaysShow){
    if(!rows.length){if(alwaysShow)txt+=label+'\nNone\n\n';return;}
    const g=rows.length===1?'1 group':rows.length+' groups';
    txt+=label+' - '+g+'\n';
    rows.forEach((r,i)=>{if(i>0)txt+='\n';txt+=buildWALine(r)+'\n';});
    txt+='\n';
  }

  section(arrJRO,    'ARRIVALS',              true);
  section(depJRO,    'DEPARTURES',             true);
  section(arrCoast,  'ARRIVALS ZNZ/PEMBA/MAFIA/DAR',  true);
  section(depCoast,  'DEPARTURES ZNZ/PEMBA/MAFIA/DAR',true);
  section(arrOther,  'ARRIVALS ROAD TRANSFERS / OTHER',false);
  section(depOther,  'DEPARTURES ROAD TRANSFERS / OTHER',false);
  return txt.trim();
}

function copyWADate(date, btn){
  const rows=lastData.filter(r=>(r.display_date||r.move_date)===date);
  const orig=btn?btn.textContent:'';
  let text;
  if(!rows.length){
    const lbl=fmtDateLabel(date);
    text=lbl.toUpperCase()+' - ARRIVALS/DEPARTURES\n-------------------------------------\n\nARRIVALS\nNone\n\nDEPARTURES\nNone\n\nARRIVALS ZNZ/PEMBA/MAFIA/DAR\nNone\n\nDEPARTURES ZNZ/PEMBA/MAFIA/DAR\nNone';
  } else {
    text=buildWAForDate(rows);
  }
  robustCopy(text, function(){
    if(btn){btn.textContent='✓ Copied!';btn.style.background='var(--green)';btn.style.color='var(--white)';setTimeout(()=>{btn.textContent=orig;btn.style.background='';btn.style.color='';},2000);}
  });
}

// Init
var gCalYear=new Date().getFullYear(), gCalMonth=new Date().getMonth();
var gSelDates=[], gCurrentMode='single';
renderCalendar();
renderGCal();

// ═══════════════════════════════════════════════════
// TAB 2 — MOVEMENT EXTRACTOR (client-side)
// ═══════════════════════════════════════════════════
let extRows=[], currentFileName='', currentFileBlob=null, sheetData=[];

const FIELD_KEYS=['date','type','client','pax','flight','time','pickup','dropoff','driver','notes','dropbox'];
const AIRPORTS=[[['JRO','KILI','KILIMANJARO'],'JRO Kilimanjaro Airport'],[['DAR DOMESTIC'],'Dar domestic airport'],[['DAR ES SALAAM','DAR INTL','DAR INTERNATIONAL'],'Dar international airport'],[['DAR'],'Dar international airport'],[['ZNZ','ZANZIBAR'],'Zanzibar airport'],[['PEMBA'],'Pemba airport'],[['MAFIA'],'Mafia airport'],[['ARK','ARUSHA AIRPORT'],'Arusha airport'],[['NAMANGA'],'Namanga border'],[['ISEBANIA','ISEBANI'],'Isebania border'],[['TAVETA'],'Taveta border']];
const TRANSFER_DEST=[[['ZNZ','ZANZIBAR'],'Zanzibar airport'],[['PEMBA'],'Pemba airport'],[['MAFIA'],'Mafia airport'],[['DAR'],'Dar international airport'],[['JRO','KILI','KILIMANJARO'],'JRO Kilimanjaro Airport']];
const ISLAND_DEST=['ZNZ','ZANZIBAR','PEMBA','MAFIA','DAR'];
const HOTEL_AIRPORT_MAP=[[['MVUVI','VILLA KIVA','DREAMS OF ZANZIBAR','PEARL','MYBLUE','RIU PALACE','RIU JAMBO','ROYAL ZANZIBAR','Z HOTEL','NUNGWI','ZURI','TEMBO','FORODHANI'],'Zanzibar airport'],[['MANTA','AYANA','PEMBA PARADISE','FUNDU LAGOON'],'Pemba airport'],[['POLEPOLE','POLE POLE','MAFIALODGE','MAFIA LODGE','KILELENI','BUTIAMA','SHAMBA KILOLE'],'Mafia airport'],[['SLIPWAY','SERENA DAR'],'Dar international airport']];
const SAFARI_IATA=['JRO','ZNZ','DAR','ARK','PMA','MYW','MWZ'];
const AIRSTRIP_NAMES={arusha:'Arusha airport',seronera:'Seronera airstrip',kogatende:'Kogatende airstrip'};

function pad(n){return String(n).padStart(2,'0');}
function escH(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function escA(s){return String(s||'').replace(/"/g,'&quot;').replace(/</g,'&lt;');}
function isNumeric(v){return v!==null&&v!==''&&v!==' '&&!isNaN(parseFloat(String(v)));}

function fmtDate(val){
  if(!val)return'';
  if(val instanceof Date)return pad(val.getDate())+'/'+pad(val.getMonth()+1)+'/'+val.getFullYear();
  const s=String(val).trim();
  if(/^\d{1,2}\/\d{1,2}\/\d{4}$/.test(s))return s;
  const mISO=s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if(mISO)return mISO[3]+'/'+mISO[2]+'/'+mISO[1];
  const MO={jan:1,feb:2,mar:3,apr:4,may:5,jun:6,jul:7,aug:8,sep:9,oct:10,nov:11,dec:12,set:9,ott:10,mag:5,giu:6,lug:7,ago:8};
  const m=s.match(/(\d{1,2})[\s\/]([a-zA-Z]{3})[\s\/](\d{4})/);
  if(m){const mo=MO[m[2].toLowerCase()];if(mo)return pad(parseInt(m[1]))+'/'+pad(mo)+'/'+m[3];}
  const m3=s.match(/([A-Za-z]{3,9})\s+(\d{1,2}),?\s+(\d{4})$/);
  if(m3){const mo=MO[m3[1].toLowerCase().substring(0,3)];if(mo)return pad(parseInt(m3[2]))+'/'+pad(mo)+'/'+m3[3];}
  const m4=s.match(/^(\d{1,2})-([a-zA-Z]{3})-(\d{4})$/);
  if(m4){const mo=MO[m4[2].toLowerCase()];if(mo)return pad(parseInt(m4[1]))+'/'+pad(mo)+'/'+m4[3];}
  return s;
}

function toISO(val){const s=String(val||'').trim();const m=s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);if(m)return m[3]+'-'+m[2].padStart(2,'0')+'-'+m[1].padStart(2,'0');if(/^\d{4}-\d{2}-\d{2}$/.test(s))return s;return'';}
function fromISO(val){const s=String(val||'').trim();const m=s.match(/^(\d{4})-(\d{2})-(\d{2})$/);if(m)return m[3]+'/'+m[2]+'/'+m[1];return s;}

function extractTime(s){if(!s)return'';const m=String(s).match(/(\d{1,2})[.,:h](\d{2})\s*(am|pm)?/i);if(!m)return'';let h=parseInt(m[1]),mn=m[2];const ap=(m[3]||'').toLowerCase();if(ap==='pm'&&h<12)h+=12;if(ap==='am'&&h===12)h=0;return pad(h)+':'+mn;}
function extractFlight(s){if(!s)return'';s=String(s).trim();const m=s.match(/\b([A-Z]{2,3})\s*(\d{2,4})\b/);if(m)return m[1]+' '+m[2];const m2=s.match(/\bby\s+([A-Z]{2,3})\b/i);if(m2)return m2[1].toUpperCase();return'';}
function matchAirport(text){if(!text)return'';const up=String(text).toUpperCase();for(const[keys,name]of AIRPORTS)if(keys.some(k=>up.includes(k)))return name;return'';}
function matchAirportFromPark(park){if(!park)return'';const up=String(park).toUpperCase();if(up.includes('JRO')||up.includes('KILI'))return'JRO Kilimanjaro Airport';if(up.includes('ZNZ')||up.includes('ZANZIBAR'))return'Zanzibar airport';if(up.includes('PMA')||up.includes('PEMBA'))return'Pemba airport';if(up.includes('MAFIA'))return'Mafia airport';if(up.includes('DAR'))return'Dar international airport';if(up.includes('NAMANGA'))return'Namanga border';if(up.includes('ISEBANIA')||up.includes('ISEBANI'))return'Isebania border';if(up.includes('TAVETA'))return'Taveta border';return '';}
function detectTransferAirport(park){if(!park||!park.includes('-'))return'';const dest=park.toUpperCase().split('-').pop().trim();for(const[keys,name]of TRANSFER_DEST)if(keys.some(k=>dest.includes(k)))return name;return'';}
function detectExplicitDepartureAirport(park){if(!park)return'';const parts=park.toUpperCase().split('-').map(s=>s.trim());if(parts.length!==3)return'';const mid=parts[1];for(const[keys,name]of AIRPORTS)if(keys.some(k=>mid.includes(k)))return name;return'';}
function detectAirportFromHotel(h){if(!h)return'';const up=String(h).toUpperCase();for(const[keys,airport]of HOTEL_AIRPORT_MAP)if(keys.some(k=>up.includes(k)))return airport;return'';}
function detectDriverFromHotel(h){return getDriverByDropoff(detectAirportFromHotel(h));}
function getIslandDriver(park){const up=String(park||'').toUpperCase();const dest=up.includes('-')?up.split('-').pop().trim():up;if(dest.includes('ZNZ')||dest.includes('ZANZIBAR')||dest.includes('PEMBA'))return'Iddi';if(dest.includes('DAR'))return'Majid';if(dest.includes('MAFIA'))return'operated by the lodge';return'';}
function getDriverByDropoff(d){const up=String(d||'').toUpperCase();if(up.includes('ZANZIBAR')||up.includes('ZNZ')||up.includes('PEMBA'))return'Iddi';if(up.includes('DAR'))return'Majid';if(up.includes('MAFIA'))return'operated by the lodge';return'';}
function isIslandDest(park){if(!park||!park.includes('-'))return false;const dest=park.toUpperCase().split('-').pop().trim();return ISLAND_DEST.some(k=>dest.includes(k));}
function detectIslandOriginAirport(park){if(!park||!park.includes('-'))return'';const parts=park.toUpperCase().split('-');const dest=parts[parts.length-1].trim();const origin=parts[0].trim();if(ISLAND_DEST.some(k=>dest.includes(k)))return'';for(const[keys,name]of TRANSFER_DEST)if(keys.some(k=>origin.includes(k)))return name;return'';}
function getArrivalAirportForDest(d){const up=String(d||'').toUpperCase();for(const[keys,name]of AIRPORTS)if(keys.some(k=>up.includes(k)))return name;const region=getDepartureAirstrip(up);return(region&&AIRSTRIP_NAMES[region])||'';}
function getDepartureAirstrip(h){const up=String(h||'').toUpperCase();if(up.includes('KOGATENDE')||up.includes('NORTH SEREN')||up.includes('LAMAI')||up.includes('MARA')||up.includes('KOGATEN')||up==='KGD')return'kogatende';if(up.includes('SERONERA')||up.includes('SERENGETI')||up==='SEU')return'seronera';if(up.includes('ARUSHA')||up.includes('KARATU')||up.includes('TARANGIRE')||up.includes('MANYARA')||up.includes('MONDULI')||up.includes('NGORONGORO')||up.includes('MARERA')||up.includes('EXPLORERS LODGE'))return'arusha';return'';}
function getIslandDepartureAirport(hotelName,parkName,fallbackText){const region=getDepartureAirstrip(hotelName)||getDepartureAirstrip(parkName);if(region&&AIRSTRIP_NAMES[region])return AIRSTRIP_NAMES[region];return matchAirport(fallbackText)||'Arusha airport';}
function cleanHotelName(name){if(!name)return name;return name.replace(/\s+booked\s+by\s+.*/i,'').replace(/\s+in\s+(all\s+inclusive|bb|hb|fb|ai)\s*$/i,'').replace(/[\s-]+(all\s+inclusive|bb|hb|fb|ai)\s*$/i,'').replace(/\s+(deluxe\s+room|deluxe|sea\s*view|garden\s*view|superior\s+r[oa]{1,2}m|superior)\s*$/i,'').trim();}
function extractClientName(filename){const base=filename.replace(/\.xlsx?$/i,'').split('/').pop().split('\\').pop();const parts=base.split('_').filter(Boolean);for(const part of parts){if(/^\d+$/.test(part))continue;const split=part.replace(/([a-z])([A-Z])/g,'$1 $2');if(split.includes(' '))return split.replace(/\s*\(.*?\)\s*/g,'').trim();}const raw=parts.find(p=>!/^\d+$/.test(p))||'';return raw.replace(/\s*\(.*?\)\s*/g,'').trim();}
function newRow(o){return Object.assign({type:'Arrival',date:'',client:'',pax:'',flight:'',time:'',pickup:'',dropoff:'',driver:'',notes:'',dropbox:'',_note:''},o);}
function parseExtDate(s){if(!s)return 0;const m=s.match(/(\d{1,2})\/(\d{1,2})\/(\d{4})/);return m?new Date(+m[3],+m[2]-1,+m[1]).getTime():0;}
function parseFlightSubGroups(lines){const groups=[];let cur=null;for(const line of lines){const m=String(line).trim().match(/(?:^|.*?)(\d+)\s+pax\b(.*)?$/i);if(m){cur={pax:m[1].trim(),label:(m[2]||'').trim(),flightLines:[]};groups.push(cur);}else if(cur){cur.flightLines.push(line);}}return groups.length>0?groups:[];}
function parseIataLine(line){if(!line)return null;const s=String(line).replace(/\xa0/g,' ').trim().replace(/^\d+\.\s*/,'');const m=s.match(/^(?:\d+\.?\s+)?([A-Z0-9]{2,3})\s+(\d{2,4})(?:\s+[A-Z])?\s+\d{2}[A-Z]{3}(?:\s+\d)?\s+([A-Z]{6})(?:\s+[A-Z0-9]+){0,3}\s+(\d{4})\s+#?(\d{4})/i);if(!m)return null;const route=m[3].toUpperCase();return{flight:m[1].toUpperCase()+' '+m[2],origin:route.substring(0,3),dest:route.substring(3,6),depTime:m[4],arrTime:m[5]};}
function iataTimeToHHMM(t){return pad(parseInt(t.substring(0,2)))+':'+t.substring(2,4);}
function resolveArrivalFlight(lines){let best=null;for(const l of lines){const f=parseIataLine(l);if(f&&SAFARI_IATA.some(k=>f.dest===k))best=f;}if(best)return{flight:best.flight,time:iataTimeToHHMM(best.arrTime),airport:matchAirport(best.dest)||''};const fb=lines[0]||'';return{flight:extractFlight(fb),time:extractTime(fb),airport:matchAirport(fb)||''};}
function resolveDepartureFlight(lines){for(const l of lines){const f=parseIataLine(l);if(f&&SAFARI_IATA.some(k=>f.origin===k))return{flight:f.flight,time:iataTimeToHHMM(f.depTime),airport:matchAirport(f.origin)||''};}const fb=lines[0]||'';return{flight:extractFlight(fb),time:extractTime(fb),airport:matchAirport(fb)||''};}

function handleExtDrop(e){e.preventDefault();document.getElementById('extUploadZone').classList.remove('drag');if(e.dataTransfer.files[0])loadExtFile(e.dataTransfer.files[0]);}

function loadExtFile(file){
  if(!file)return;
  document.getElementById('extFileInput').value='';
  const reader=new FileReader();
  reader.onload=ev=>{
    try{
      const wb=XLSX.read(ev.target.result,{type:'array',cellDates:true});
      const sname=wb.SheetNames.find(s=>s.toUpperCase().includes('CONF'))||wb.SheetNames.find(s=>s.toUpperCase().includes('RECAP'))||wb.SheetNames[wb.SheetNames.length-1];
      const ws=wb.Sheets[sname];
      const data=XLSX.utils.sheet_to_json(ws,{header:1,defval:null,raw:false});
      const clientName=extractClientName(file.name);
      const dropboxFile=file.name.replace(/\.xlsx?$/i,'');
      extRows=extractMovements(data,clientName,dropboxFile);
      currentFileName=file.name; currentFileBlob=file;
      sheetData=XLSX.utils.sheet_to_json(ws,{header:1,defval:'',raw:false});
      document.getElementById('excelFileName').textContent='📄 '+file.name;
      document.getElementById('excelActionBar').style.display='';
      document.getElementById('extUploadTitle').textContent='✓ '+file.name;
      document.getElementById('extUploadSub').innerHTML='Load another file or drop a new one<br><small>Reads the CONF or RECAP sheet automatically</small>';
      renderExtractor();
    }catch(err){alert('Error reading file: '+err.message);}
  };
  reader.readAsArrayBuffer(file);
}

function extractMovements(data,clientName,dropboxFile){
  let headerIdx=-1;
  for(let i=0;i<data.length;i++){const r=data[i];if(r&&r.some(c=>String(c||'').toUpperCase().trim()==='DATE')&&r.some(c=>String(c||'').toUpperCase().includes('PARK'))){headerIdx=i;break;}}
  if(headerIdx<0)return[];
  const hdr=data[headerIdx].map(c=>String(c||'').toUpperCase().trim());
  const ci=kw=>hdr.findIndex(h=>h.includes(kw));
  const colDate=ci('DATE'),colPark=ci('PARK'),colFlights=ci('FLIGHT'),colHotel=ci('HOTEL'),colAct=ci('ACTIVIT');
  const colActDesc=hdr.findIndex((h,i)=>h.includes('ACTIVIT')&&i>colAct);
  let totalPax='';
  for(let i=0;i<headerIdx;i++){const r=data[i];if(r&&String(r[0]||'').toUpperCase().includes('TOT PAX')){totalPax=String(r[1]||'');break;}}
  let guide='';
  for(let i=headerIdx;i<data.length;i++){const r=data[i];if(!r)continue;if(String(r[0]||'').toUpperCase().startsWith('GUIDE')&&r[1]){const raw=String(r[1]).trim();const first=raw.split(/\s+/)[0];guide=first.charAt(0).toUpperCase()+first.slice(1).toLowerCase();break;}}
  const dataRows=[];
  for(let i=headerIdx+1;i<data.length;i++){const r=data[i];if(!r)continue;const dv=r[colDate];if(!dv)continue;if(String(r[0]||'').toUpperCase().includes('TOTAL'))break;const dateStr=fmtDate(dv);if(!dateStr)continue;dataRows.push({date:dateStr,park:String(r[colPark]||'').trim(),flights:String(r[colFlights]||'').trim(),actCost:r[colAct],actDesc:String(r[colActDesc]||'').trim(),hotel:String(r[colHotel]||'').trim()});}
  if(!dataRows.length)return[];
  while(dataRows.length>1&&!dataRows[dataRows.length-1].park)dataRows.pop();
  if(!dataRows.length)return[];
  let arrLines=[],depLines=[];let inArr=false,inDep=false;
  for(let i=headerIdx;i<data.length;i++){const r=data[i];if(!r)continue;const cell=String(r[0]||'').toUpperCase().trim();if(cell.includes('ARRIVAL')&&cell.includes('DETAIL')){inArr=true;inDep=false;continue;}if(cell.includes('DEPARTURE')&&cell.includes('DETAIL')){inDep=true;inArr=false;continue;}const v=r[0]&&String(r[0]).trim();if(!v)continue;if(inArr)arrLines.push(v);if(inDep)depLines.push(v);}
  const depDetail=depLines[0]||'';
  const firstHotel=cleanHotelName(dataRows.find(r=>r.hotel&&r.hotel.trim()&&r.hotel.trim()!==' ')?.hotel.trim()||'');
  const base={client:clientName,pax:totalPax,driver:guide,dropbox:dropboxFile};
  const movements=[];
  const arrSubGroups=parseFlightSubGroups(arrLines);
  const arrFallbackPickup=arrLines.map(l=>matchAirport(l)).find(Boolean)||matchAirportFromPark(dataRows[0].park)||'JRO Kilimanjaro Airport';
  if(arrSubGroups.length>1){for(const sg of arrSubGroups){const src=sg.flightLines.length?sg.flightLines:arrLines;const info=resolveArrivalFlight(src);const pickup=info.airport||src.map(l=>matchAirport(l)).find(Boolean)||arrFallbackPickup;movements.push(newRow({...base,type:'Arrival',date:dataRows[0].date,pax:sg.pax,flight:info.flight,time:info.time,pickup,dropoff:firstHotel,notes:sg.label||'',_note:'Main arrival (sub-group)'}));}}
  else{const arrInfo=resolveArrivalFlight(arrLines);movements.push(newRow({...base,type:'Arrival',date:dataRows[0].date,flight:arrInfo.flight,time:arrInfo.time,pickup:arrInfo.airport||arrFallbackPickup,dropoff:firstHotel,_note:'Main arrival'}));}
  for(let i=0;i<dataRows.length-1;i++){
    const row=dataRows[i];
    const _sp=row.park.toUpperCase().split('-').map(s=>s.trim()).filter(Boolean);
    if(_sp.length===3&&(_sp[1].includes('ARK')||_sp[1].includes('ARUSHA'))&&(_sp[2].includes('JRO')||_sp[2].includes('KILI')||_sp[2].includes('KILIMANJARO'))){
      const _prevHotel=cleanHotelName(i>0?dataRows[i-1].hotel.trim():'');
      const _flightCode=extractFlight(row.flights);const _region=getDepartureAirstrip(_prevHotel);const _airstrip=(_region&&AIRSTRIP_NAMES[_region])||'Kogatende airstrip';
      movements.push(newRow({...base,type:'Departure',date:row.date,flight:_flightCode,time:'',pickup:_prevHotel,dropoff:_airstrip,driver:guide,_note:'Transfer: '+row.park}));
      movements.push(newRow({...base,type:'Departure',date:row.date,flight:'road transfer',time:'',pickup:'Arusha airport',dropoff:'JRO Kilimanjaro Airport',driver:guide,notes:'Road transfer ARK → JRO',_note:'Road transfer ARK → JRO'}));
      continue;
    }
    const transferAirport=detectTransferAirport(row.park);
    const islandOriginAirport=row.flights.trim()?detectIslandOriginAirport(row.park):'';
    if(!transferAirport&&!islandOriginAirport)continue;
    const flightCode=extractFlight(row.flights);
    const prevHotel=cleanHotelName(i>0?dataRows[i-1].hotel.trim():'');
    let dropoffAirport=transferAirport||islandOriginAirport;
    if(isIslandDest(row.park))dropoffAirport=getIslandDepartureAirport(prevHotel,i>0?dataRows[i-1].park:'',row.flights);
    const depDriver=getDriverByDropoff(dropoffAirport)||guide;
    movements.push(newRow({...base,type:'Departure',date:row.date,flight:flightCode,time:'',pickup:prevHotel,dropoff:dropoffAirport,driver:depDriver,_note:'Transfer: '+row.park}));
    if(isIslandDest(row.park)||(isNumeric(row.actCost)&&row.actDesc.toLowerCase().includes('transfer'))){
      const arrivalHotel=cleanHotelName(row.hotel.trim());
      const islandDriver=detectDriverFromHotel(arrivalHotel)||(isIslandDest(row.park)?getIslandDriver(row.park):'');
      let arrPickup;
      if(islandOriginAirport&&!transferAirport){const destPart=row.park.toUpperCase().split('-').pop().trim();arrPickup=getArrivalAirportForDest(destPart)||'';}
      else arrPickup=transferAirport;
      movements.push(newRow({...base,type:'Arrival',date:row.date,flight:flightCode,time:'',pickup:arrPickup,dropoff:arrivalHotel,driver:islandDriver||guide,_note:'Transfer arrival: '+row.park}));
    }
  }
  const lastRow=dataRows[dataRows.length-1];
  const rowsExcludingLast=dataRows.slice(0,-1);
  const lastTransferArr=[...movements].reverse().find(m=>m.type==='Arrival'&&m._note&&m._note.startsWith('Transfer arrival'));
  const transferDropoff=lastTransferArr?.dropoff||'';
  const lastNightHotel=cleanHotelName(([...rowsExcludingLast].reverse().find(r=>r.hotel&&r.hotel.trim()&&r.hotel.trim()!==' ')?.hotel.trim())||transferDropoff||lastRow.hotel.trim()||'');
  const lastNightRow=[...rowsExcludingLast].reverse().find(r=>r.hotel&&r.hotel.trim()&&r.hotel.trim()!==' ')||lastRow;
  let mainDropoff,islandMainDep=false;
  const hotelUp=String(lastNightHotel).toUpperCase();
  const hotelAirport=detectAirportFromHotel(lastNightHotel);
  if(hotelAirport)mainDropoff=hotelAirport;
  else if(hotelUp.includes('ZANZIBAR')||hotelUp.includes('ZNZ'))mainDropoff='Zanzibar airport';
  else if(hotelUp.includes('PEMBA'))mainDropoff='Pemba airport';
  else if(hotelUp.includes('MAFIA'))mainDropoff='Mafia airport';
  else if(hotelUp.includes('DAR ES SALAAM')||hotelUp.includes('DAR INTL')||hotelUp.includes('DAR DOMESTIC'))mainDropoff='Dar international airport';
  else{
    const explicitMainDep=detectExplicitDepartureAirport(lastRow.park);
    const depDestUp=depDetail.toUpperCase();
    const isIslandDep=ISLAND_DEST.some(k=>depDestUp.includes(k))||isIslandDest(lastRow.park);
    if(explicitMainDep){const _pp=lastRow.park.toUpperCase().split('-').map(s=>s.trim()).filter(Boolean);const _isArkJroMain=_pp.length===3&&(_pp[1].includes('ARK')||_pp[1].includes('ARUSHA'))&&(_pp[2].includes('JRO')||_pp[2].includes('KILI')||_pp[2].includes('KILIMANJARO'));if(_isArkJroMain){const _r=getDepartureAirstrip(lastNightHotel)||getDepartureAirstrip(lastNightRow.park);mainDropoff=(_r&&AIRSTRIP_NAMES[_r])||'Kogatende airstrip';}else mainDropoff=explicitMainDep;}
    else if(isIslandDep){const _ppI=lastRow.park.toUpperCase().split('-').map(s=>s.trim()).filter(Boolean);const _seg2=_ppI.length>=3?_ppI[1]:'';const _arstripRgn=_seg2?getDepartureAirstrip(_seg2):'';if(_arstripRgn&&AIRSTRIP_NAMES[_arstripRgn])mainDropoff=AIRSTRIP_NAMES[_arstripRgn];else mainDropoff=detectTransferAirport(lastRow.park)||matchAirport(depDetail)||'Zanzibar airport';islandMainDep=true;}
    else mainDropoff=matchAirportFromPark(lastRow.park)||matchAirport(depDetail)||'JRO Kilimanjaro Airport';
  }
  const depSubGroups=parseFlightSubGroups(depLines);
  const mainDepDriver=getDriverByDropoff(mainDropoff)||guide;
  let mainDepInfo=null;
  if(depSubGroups.length>1){for(const sg of depSubGroups){const src=sg.flightLines.length?sg.flightLines:depLines;const info=resolveDepartureFlight(src);movements.push(newRow({...base,type:'Departure',date:lastRow.date,pax:sg.pax,flight:info.flight,time:info.time,pickup:lastNightHotel,dropoff:mainDropoff,driver:mainDepDriver,notes:sg.label||'',_note:'Main departure (sub-group)'}));}}
  else{mainDepInfo=resolveDepartureFlight(depLines);movements.push(newRow({...base,type:'Departure',date:lastRow.date,flight:mainDepInfo.flight,time:mainDepInfo.time,pickup:lastNightHotel,dropoff:mainDropoff,driver:mainDepDriver,_note:'Main departure'}));}
  const _parkParts=lastRow.park.toUpperCase().split('-').map(s=>s.trim()).filter(Boolean);
  if(_parkParts.length===3){const _mid=_parkParts[1];const _end=_parkParts[2];if((_mid.includes('ARK')||_mid.includes('ARUSHA'))&&(_end.includes('JRO')||_end.includes('KILI')||_end.includes('KILIMANJARO'))){movements.push(newRow({...base,type:'Departure',date:lastRow.date,flight:'road transfer',time:'',pickup:'Arusha airport',dropoff:'JRO Kilimanjaro Airport',driver:guide,notes:'Road transfer ARK → JRO',_note:'Road transfer ARK → JRO'}));}}
  if(islandMainDep&&depSubGroups.length<=1){const _islandHotel=cleanHotelName(lastRow.hotel&&lastRow.hotel.trim()?lastRow.hotel.trim():'');if(_islandHotel){const _islandArrDriver=getDriverByDropoff(mainDropoff)||getIslandDriver(lastRow.park)||guide;movements.push(newRow({...base,type:'Arrival',date:lastRow.date,flight:mainDepInfo?mainDepInfo.flight:'',time:'',pickup:mainDropoff,dropoff:_islandHotel,driver:_islandArrDriver,_note:'Island arrival: '+mainDropoff+' → hotel'}));}}
  movements.sort((a,b)=>{const da=parseExtDate(a.date),db=parseExtDate(b.date);if(da!==db)return da-db;if(a.type==='Arrival'&&b.type==='Departure')return -1;if(a.type==='Departure'&&b.type==='Arrival')return 1;return 0;});
  return movements;
}

function renderExtractor(){
  if(!extRows.length){document.getElementById('extMain').innerHTML='';return;}
  let html='<div class="info-box"><strong>'+extRows.length+' movement'+(extRows.length!==1?'s':'')+' extracted.</strong> Review, edit and save to DB.</div>';
  extRows.forEach((r,idx)=>{
    const isArr=r.type==='Arrival';
    html+='<div class="row-lbl lbl-'+(isArr?'arrival':'departure')+'">'+r.type+(r._note?'<span class="row-note">'+escH(r._note)+'</span>':'')+
    '</div><div class="row-card" id="card_'+idx+'"><div class="row-card-header"><span class="badge-'+(isArr?'arrival':'departure')+'">'+r.type+'</span><div class="row-actions">'+
    '<button class="btn-sm2 btn-save-row" id="savebtn_'+idx+'" onclick="saveExtRowToDB('+idx+')">&#128190; Save to DB</button>'+
    '<button class="btn-sm2 btn-copy-row" onclick="copyExtRow('+idx+')">Copy row</button>'+
    '<button class="btn-sm2 btn-del-card" onclick="deleteExtRow('+idx+')">&#10005;</button></div></div>'+
    '<div class="fields-grid">'+
    extFld(idx,'date','Date',r.date,true)+extFld(idx,'type','Movement Type',r.type)+extFld(idx,'client','Client / Group Name',r.client)+extFld(idx,'pax','Pax',r.pax)+
    extFld(idx,'flight','Flight / Transfer',r.flight)+extFld(idx,'time','Time',r.time,false,true)+extFld(idx,'pickup','Pick up',r.pickup)+extFld(idx,'dropoff','Drop off',r.dropoff)+
    extFld(idx,'driver','Driver / Guide',r.driver)+extFld(idx,'notes','Notes',r.notes)+extFld(idx,'dropbox','Dropbox File',r.dropbox)+
    '</div></div>';
  });
  html+='<div class="copy-all-bar"><p>Ready: '+extRows.length+' row'+(extRows.length!==1?'s':'')+'</p>'+
    '<button class="btn-copy-all2" style="background:var(--navy)" onclick="saveAllExtToDB()">&#128190; Save all to DB</button>'+
    '<button class="btn-copy-all2" onclick="copyAllExt()">Copy all</button></div>';
  document.getElementById('extMain').innerHTML=html;
}

function extFld(idx,key,label,value,isDate,isTime){
  if(isDate){return'<div class="field"><label>'+label+'</label><input type="date" value="'+toISO(value)+'" oninput="extRows['+idx+'][\''+key+'\']=fromISO(this.value)" placeholder="'+label+'"></div>';}
  if(isTime){
    var parts=(value||'').split(':');
    var hh=parts[0]||'';var mm=parts[1]||'00';
    var hOpts='<option value="">--</option>';
    for(var h=0;h<24;h++){var hv=(h<10?'0':'')+h;hOpts+='<option value="'+hv+'"'+(hv===hh?' selected':'')+'>'+hv+'</option>';}
    var mOpts='';
    for(var m=0;m<60;m++){var mv=(m<10?'0':'')+m;mOpts+='<option value="'+mv+'"'+(mv===mm?' selected':'')+'>'+mv+'</option>';}
    return'<div class="field"><label>'+label+'</label><div style="display:flex;gap:3px;align-items:center;">'+
      '<select class="form-control" style="flex:1;min-width:0;font-size:.78rem;padding:5px 4px;" onchange="extSetTime('+idx+',this.parentNode)">'+hOpts+'</select>'+
      '<span style="font-weight:700;color:var(--grey-mid);">:</span>'+
      '<select class="form-control" style="flex:1;min-width:0;font-size:.78rem;padding:5px 4px;" onchange="extSetTime('+idx+',this.parentNode)">'+mOpts+'</select>'+
      '</div></div>';
  }
  return'<div class="field"><label>'+label+'</label><input type="text" value="'+escA(value)+'" oninput="extRows['+idx+'][\''+key+'\']=this.value" placeholder="'+label+'"></div>';
}
function extSetTime(idx,wrap){var sels=wrap.querySelectorAll('select');var h=sels[0].value;var m=sels[1].value;extRows[idx].time=h?h+':'+m:'';}
function deleteExtRow(idx){extRows.splice(idx,1);renderExtractor();}
function addExtRow(type){extRows.push(newRow({type}));renderExtractor();}
function cancelExtractor(){
  extRows=[];currentFileName='';currentFileBlob=null;sheetData=[];
  document.getElementById('extMain').innerHTML='';
  document.getElementById('excelActionBar').style.display='none';
  document.getElementById('extUploadZone').style.display='';
  document.getElementById('extUploadTitle').textContent='Load Safari Calc Excel';
  document.getElementById('extUploadSub').innerHTML='Drop the file here or click to browse &mdash; reads CONF or RECAP sheet automatically';
  document.getElementById('extFileInput').value='';
  document.getElementById('auditPanelExt').style.display='none';
}
function readExtDOM(idx){const card=document.getElementById('card_'+idx);const r={...extRows[idx]};if(!card)return r;const inputKeys=FIELD_KEYS.filter(k=>k!=='time');card.querySelectorAll('input').forEach((inp,i)=>{if(inputKeys[i])r[inputKeys[i]]=(i===0?fromISO(inp.value):inp.value);});return r;}
function extRowToTSV(r){return FIELD_KEYS.map((k,i)=>{const v=String(r[k]||'');if(i===0&&v)return fmtDate(v);if(i===5&&v)return v.replace(',',':');return v;}).join('\t');}
function copyExtRow(idx){showExtModal(extRowToTSV(readExtDOM(idx)));}
function copyAllExt(){showExtModal(extRows.map((_,i)=>extRowToTSV(readExtDOM(i))).join('\n'));}
function showExtModal(text){document.getElementById('extModalText').value=text;document.getElementById('extModalOverlay').classList.add('open');setTimeout(()=>document.getElementById('extModalText').select(),80);}
function closeExtModal(){document.getElementById('extModalOverlay').classList.remove('open');}
function copyExtModal(){const ta=document.getElementById('extModalText');const text=ta.value;ta.select();const btn=document.getElementById('extModalCopyBtn');robustCopy(text,function(){btn.textContent='Copied!';btn.classList.add('copied');setTimeout(()=>{btn.textContent='Copy to clipboard';btn.classList.remove('copied');},2000);});}

function reExtractAfterEdit(){
  if(!currentFileName){alert('No file loaded.');return;}
  const btn=document.getElementById('btnReextract');const orig=btn.textContent;
  btn.disabled=true;btn.textContent='Re-extracting...';
  try{const clientName=extractClientName(currentFileName);const dropboxFile=currentFileName.replace(/\.xlsx?$/i,'');extRows=extractMovements(sheetData,clientName,dropboxFile);renderExtractor();}catch(e){alert('Re-extract error: '+e.message);}
  btn.textContent='✓ Done!';setTimeout(()=>{btn.textContent=orig;btn.disabled=false;},1500);
}

// ═══════════════════════════════════════════════════
// TAB 3 — MOVEMENTS SAFARI GRID
// ═══════════════════════════════════════════════════
let gridData=[];
const GRID_COLS=['Date','Movement Type','Client / Group Name','Pax','Flight / Transfer','Time','Pick up','Drop off','Driver / Guide','Notes','Dropbox File'];

// ── Save extractor row to DB ──────────────────────────────────
let savedRowIndices = {};

function extRowToFormData(r){
  const fd = new FormData();
  fd.append('id','0');
  // parse date dd/MM/yyyy → yyyy-MM-dd
  let dateVal = r.date||'';
  const dm = dateVal.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
  if(dm) dateVal = dm[3]+'-'+dm[2].padStart(2,'0')+'-'+dm[1].padStart(2,'0');
  fd.append('move_date',   dateVal);
  fd.append('movement_type', r.type||'Arrival');
  fd.append('client_name',   r.client||'');
  fd.append('pax',           r.pax||'1');
  fd.append('flight',        r.flight||'');
  fd.append('move_time',     r.time||'');
  fd.append('pickup',        r.pickup||'');
  fd.append('dropoff',       r.dropoff||'');
  fd.append('driver',        r.driver||'');
  fd.append('notes',         r.notes||'');
  fd.append('dropbox_folder',r.dropbox||'');
  return fd;
}

function saveExtRowToDB(idx){
  const r = readExtDOM(idx);
  const btn = document.getElementById('savebtn_'+idx);
  const card = document.getElementById('card_'+idx);
  if(!btn||!card) return;
  if(btn.classList.contains('saving')) return;
  btn.classList.add('saving');
  btn.textContent = 'Saving...';

  fetch(BASE+'/modules/operations/api/save_movement.php', {method:'POST', body: extRowToFormData(r)})
    .then(res=>res.json())
    .then(d=>{
      if(d.ok){
        savedRowIndices[idx] = d.id;
        card.classList.add('saved');
        card.classList.remove('dup-error');
        btn.classList.remove('saving');
        btn.classList.add('saved');
        btn.textContent = '✓ Saved';
        // Remove existing badges
        card.querySelectorAll('.saved-badge,.dup-badge').forEach(el=>el.remove());
        const badge = document.createElement('span');
        badge.className='saved-badge';
        badge.textContent='Saved to DB';
        card.querySelector('.row-card-header').appendChild(badge);
      } else if(d.duplicate){
        card.classList.add('dup-error');
        card.classList.remove('saved');
        btn.classList.remove('saving','saved');
        btn.textContent = '↻ Save to DB';
        card.querySelectorAll('.saved-badge,.dup-badge').forEach(el=>el.remove());
        const badge = document.createElement('span');
        badge.className='dup-badge';
        badge.textContent='⚠ Duplicate';
        badge.title = d.message;
        card.querySelector('.row-card-header').appendChild(badge);
      } else {
        btn.classList.remove('saving');
        btn.textContent = '↻ Save to DB';
        alert('Error: '+(d.error||'unknown'));
      }
    })
    .catch(e=>{btn.classList.remove('saving');btn.textContent='↻ Save to DB';alert('Error: '+e.message);});
}


async function saveAllExtToDB(){
  const total = extRows.length;
  if(!total){alert('No movements to save.');return;}
  const btn = document.querySelector('.btn-copy-all2[onclick="saveAllExtToDB()"]');
  if(btn){btn.disabled=true;btn.textContent='Saving...';}
  let saved=0, skipped=0, errors=0;
  for(let idx=0;idx<total;idx++){
    const card = document.getElementById('card_'+idx);
    // Skip already saved rows
    if(card && card.classList.contains('saved')){skipped++;continue;}
    const r = readExtDOM(idx);
    const rowBtn = document.getElementById('savebtn_'+idx);
    if(rowBtn){rowBtn.classList.add('saving');rowBtn.textContent='Saving...';}
    try{
      const res = await fetch(BASE+'/modules/operations/api/save_movement.php',{method:'POST',body:extRowToFormData(r)});
      const d = await res.json();
      if(d.ok){
        savedRowIndices[idx]=d.id;
        if(card){card.classList.add('saved');card.classList.remove('dup-error');card.querySelectorAll('.saved-badge,.dup-badge').forEach(el=>el.remove());const badge=document.createElement('span');badge.className='saved-badge';badge.textContent='Saved to DB';card.querySelector('.row-card-header').appendChild(badge);}
        if(rowBtn){rowBtn.classList.remove('saving');rowBtn.classList.add('saved');rowBtn.textContent='✓ Saved';}
        saved++;
      } else if(d.duplicate){
        if(card){card.classList.add('dup-error');card.classList.remove('saved');card.querySelectorAll('.saved-badge,.dup-badge').forEach(el=>el.remove());const badge=document.createElement('span');badge.className='dup-badge';badge.textContent='⚠ Duplicate';badge.title=d.message;card.querySelector('.row-card-header').appendChild(badge);}
        if(rowBtn){rowBtn.classList.remove('saving','saved');rowBtn.textContent='↻ Save to DB';}
        skipped++;
      } else {
        if(rowBtn){rowBtn.classList.remove('saving');rowBtn.textContent='↻ Save to DB';}
        errors++;
      }
    } catch(e){
      if(rowBtn){rowBtn.classList.remove('saving');rowBtn.textContent='↻ Save to DB';}
      errors++;
    }
  }
  if(btn){
    btn.disabled=false;
    const parts=[];
    if(saved) parts.push(saved+' saved');
    if(skipped) parts.push(skipped+' skipped');
    if(errors) parts.push(errors+' error'+(errors>1?'s':''));
    btn.innerHTML='&#128190; '+parts.join(', ');
    setTimeout(()=>{btn.innerHTML='&#128190; Save all to DB';},4000);
  }
}

// ── Grid: load from DB ────────────────────────────────────────
let gridDBData = []; // rows from DB (with id)

// ── Grid calendar (same as main calendar) ───────────────────

function setGridMode(m){
  gCurrentMode=m;
  document.getElementById('gctrl-single').style.display   = m==='single'   ? 'flex'  : 'none';
  document.getElementById('gctrl-range').style.display    = m==='range'    ? 'flex'  : 'none';
  document.getElementById('gctrl-multiple').style.display = m==='multiple' ? 'block' : 'none';
  document.getElementById('gbtn-single').classList.toggle('active',   m==='single');
  document.getElementById('gbtn-range').classList.toggle('active',    m==='range');
  document.getElementById('gbtn-multiple').classList.toggle('active', m==='multiple');
  if(m==='multiple') renderGCal();
}

function gcalPrev(){gCalMonth--;if(gCalMonth<0){gCalMonth=11;gCalYear--;}renderGCal();}
function gcalNext(){gCalMonth++;if(gCalMonth>11){gCalMonth=0;gCalYear++;}renderGCal();}

function renderGCal(){
  document.getElementById('gcal-month-lbl').textContent=MONTHS[gCalMonth]+' '+gCalYear;
  const grid=document.getElementById('gcal-grid');
  grid.innerHTML=DAYS.map(d=>'<div class="cal-dow">'+d+'</div>').join('');
  const first=new Date(gCalYear,gCalMonth,1).getDay();
  const days=new Date(gCalYear,gCalMonth+1,0).getDate();
  const today=new Date().toISOString().slice(0,10);
  for(let i=0;i<first;i++) grid.innerHTML+='<div class="cal-day cal-empty"></div>';
  for(let d=1;d<=days;d++){
    const iso=gCalYear+'-'+String(gCalMonth+1).padStart(2,'0')+'-'+String(d).padStart(2,'0');
    const cls=['cal-day',gSelDates.includes(iso)?'selected':'',iso===today?'today':''].filter(Boolean).join(' ');
    grid.innerHTML+='<div class="'+cls+'" data-iso="'+iso+'" onclick="gToggleDate(this.dataset.iso)">'+d+'</div>';
  }
  renderGChips();
}
function gToggleDate(iso){const i=gSelDates.indexOf(iso);if(i>=0)gSelDates.splice(i,1);else gSelDates.push(iso);gSelDates.sort();renderGCal();}
function renderGChips(){document.getElementById('gsel-chips').innerHTML=gSelDates.map(d=>{const[y,m,day]=d.split('-');return'<div class="sel-chip">'+day+'/'+m+' <button data-iso="'+d+'" onclick="gToggleDate(this.dataset.iso)">x</button></div>';}).join('');}

function loadGridFromDB(){
  let url = BASE+'/modules/operations/api/movements.php?';
  if(gCurrentMode==='single'){
    const d=document.getElementById('grid-single-date').value;
    if(!d){alert('Please select a date.');return;}
    url+='mode=single&date='+d;
  } else if(gCurrentMode==='range'){
    const from=document.getElementById('grid-from').value;
    const to=document.getElementById('grid-to').value;
    if(!from||!to){alert('Please select a date range.');return;}
    url+='mode=range&from='+from+'&to='+to;
  } else {
    if(!gSelDates.length){alert('Please select at least one date.');return;}
    url+='mode=multiple&dates='+gSelDates.join(',');
  }
  fetch(url)
    .then(r=>r.json())
    .then(data=>{
      if(data.error){alert('Error: '+data.error);return;}
      gridDBData = data.rows||[];
      renderGridDB();
    })
    .catch(e=>alert('Error: '+e.message));
}

function renderGridDB(){
  const wrap = document.getElementById('gridWrap');
  const filterVal = (document.getElementById('grid-client-filter')||{}).value||'';
  const filtered = filterVal.trim()
    ? gridDBData.filter(r=>(r.client_name||'').toLowerCase().includes(filterVal.trim().toLowerCase()))
    : gridDBData;
  if(!filtered.length){
    wrap.innerHTML='<div class="grid-placeholder"><div class="icon">&#128235;</div><h2>No movements found</h2><p>'+(filterVal.trim()?'No results for "'+escH(filterVal.trim())+'"':'No data for the selected date range.')+'</p></div>';
    document.getElementById('btnDeleteSel').style.display='none';
    return;
  }
  document.getElementById('btnDeleteSel').style.display='';
  const groupByType=document.getElementById('gridGroupByType') && document.getElementById('gridGroupByType').checked;
  const headCols='<th>Date</th><th>Type</th><th>Client</th><th>Pax</th><th>Flight</th><th>Time</th>'+
    '<th>Pick Up</th><th>Drop Off</th><th>Driver</th><th>Notes</th><th></th>';

  if(groupByType){
    function sortRows(a,b){return a.move_date.localeCompare(b.move_date)||(a.move_time||'').localeCompare(b.move_time||'');}
    const arr=filtered.filter(r=>r.movement_type==='Arrival').sort(sortRows);
    const dep=filtered.filter(r=>r.movement_type==='Departure').sort(sortRows);
    const trn=filtered.filter(r=>r.movement_type==='Transfer').sort(sortRows);
    let html='';
    html+=gridSection('Arrivals', arr, headCols, 'var(--green)');
    html+=gridSection('Departures', dep, headCols, 'var(--red-dk)');
    html+=gridSection('Transfers', trn, headCols, 'var(--amber)');
    wrap.innerHTML=html;
    return;
  }

  const sorted=[...filtered].sort((a,b)=>a.move_date.localeCompare(b.move_date)||(a.movement_type==='Departure'?1:-1));
  let html='<table class="movements-table"><thead><tr>'+
    '<th style="width:32px;"><input type="checkbox" id="selAll" onchange="toggleSelectAll(this)"></th>'+
    headCols+
    '</tr></thead><tbody>';
  sorted.forEach(function(row){
    html+=gridRowHtml(row);
  });
  html+='</tbody></table>';
  wrap.innerHTML=html;
}

function gridSection(label, rows, headCols, color){
  var titlePax=rows.reduce(function(s,r){return s+parseInt(r.pax||0);},0);
  var h='<div style="margin-bottom:8px;margin-top:18px;font-family:\'Merriweather\',serif;font-size:1.05rem;font-weight:700;color:'+color+';">'+
        label+' <span style="font-family:\'Open Sans\',sans-serif;font-size:.78rem;font-weight:400;color:var(--grey-mid);">('+rows.length+' &middot; '+titlePax+' pax)</span></div>';
  if(!rows.length){return h+'<p style="color:var(--grey-mid);font-style:italic;margin-bottom:14px;">No '+label.toLowerCase()+'.</p>';}
  h+='<table class="movements-table" style="margin-bottom:18px;"><thead><tr><th style="width:32px;"></th>'+headCols+'</tr></thead><tbody>';
  rows.forEach(function(row){ h+=gridRowHtml(row); });
  h+='</tbody></table>';
  return h;
}

function gridRowHtml(row){
  var cls=row.movement_type==='Arrival'?'row-arrival':row.movement_type==='Transfer'?'row-transfer':'row-departure';
  return '<tr class="'+cls+'" data-id="'+row.id+'">'+
    '<td><input type="checkbox" class="row-sel" value="'+row.id+'"></td>'+
    '<td>'+xss(row.move_date_fmt||row.move_date)+'</td>'+
    '<td><span class="badge-'+xss(row.movement_type.toLowerCase())+'">'+xss(row.movement_type)+'</span></td>'+
    '<td>'+xss(row.client_name)+'</td>'+
    '<td>'+row.pax+'</td>'+
    '<td class="td-flight">'+xss(row.flight)+'</td>'+
    '<td class="td-time">'+xss(row.move_time_fmt)+'</td>'+
    '<td>'+xss(row.pickup)+'</td>'+
    '<td>'+xss(row.dropoff)+'</td>'+
    '<td>'+xss(row.driver)+'</td>'+
    '<td class="td-notes">'+xss(row.notes)+'</td>'+
    '<td style="white-space:nowrap;">'+
      '<button class="btn-edit" onclick="editGridRow('+row.id+')">Edit</button>'+
      '<button class="btn-del" onclick="deleteGridRow('+row.id+',this)">Del</button>'+
    '</td>'+
  '</tr>';
}

// ── Delete confirmation modal ────────────────────────────────
let _delCallback = null;
function showDelConfirm(title, msg, onConfirm){
  document.getElementById('delConfirmTitle').textContent = title;
  document.getElementById('delConfirmMsg').textContent   = msg;
  document.getElementById('delConfirmBtn').onclick = function(){closeDelConfirm();onConfirm();};
  const ov = document.getElementById('delConfirmOverlay');
  ov.style.display='flex';
}
function closeDelConfirm(){
  document.getElementById('delConfirmOverlay').style.display='none';
}
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeDelConfirm();});

function toggleSelectAll(cb){
  document.querySelectorAll('.row-sel').forEach(el=>el.checked=cb.checked);
}

function editGridRow(id){
  const row=gridDBData.find(r=>parseInt(r.id)===id);
  if(!row)return;
  openAddModal(row);
  // After save, reload grid
  document.getElementById('movModalForm').onsubmit=null;
  document.getElementById('movModalForm').addEventListener('submit', function handler(e){
    e.preventDefault();
    var h=document.getElementById("mf-hour").value;
    var m=document.getElementById("mf-min").value;
    document.getElementById("mf-time").value=(h!=="")?(h+":"+m):"";
    const msg=document.getElementById('mf-msg');
    fetch(BASE+'/modules/operations/api/save_movement.php',{method:'POST',body:new FormData(this)})
      .then(r=>r.json()).then(d=>{
        if(d.ok){
          msg.innerHTML='<span style="color:var(--green);font-weight:700;">&#10003; Saved!</span>';
          closeAddModal();
          loadGridFromDB();
        } else if(d.duplicate){
          msg.innerHTML='<span style="color:var(--red-dk);">&#9888; '+xss(d.message)+'</span>';
        } else {
          msg.innerHTML='<span style="color:var(--red-dk);">Error: '+xss(d.error||'unknown')+'</span>';
        }
      });
    this.removeEventListener('submit',handler);
  },{once:true});
}

function deleteGridRow(id, btn){
  const row=gridDBData.find(r=>parseInt(r.id)===id);
  const name=row?('"'+row.client_name+'" — '+(row.movement_type)+', '+(row.move_date_fmt||row.move_date)):'this movement';
  showDelConfirm(
    'Delete movement?',
    'You are about to delete: '+name+'.',
    function(){
      if(btn)btn.disabled=true;
      const fd=new FormData();fd.append('id',id);
      fetch(BASE+'/modules/operations/api/delete_movement.php',{method:'POST',body:fd})
        .then(r=>r.json()).then(d=>{
          if(d.ok){gridDBData=gridDBData.filter(r=>parseInt(r.id)!==id);renderGridDB();}
          else alert('Error: '+d.error);
        });
    }
  );
}

function deleteSelectedRows(){
  const selected=[...document.querySelectorAll('.row-sel:checked')].map(el=>parseInt(el.value));
  if(!selected.length){alert('No rows selected.');return;}
  const n=selected.length;
  showDelConfirm(
    'Delete '+n+' movement'+(n>1?'s':'')+'?',
    'You are about to permanently delete '+n+' selected movement'+(n>1?'s':'')+'.',
    function(){
      Promise.all(selected.map(id=>{
        const fd=new FormData();fd.append('id',id);
        return fetch(BASE+'/modules/operations/api/delete_movement.php',{method:'POST',body:fd}).then(r=>r.json());
      })).then(()=>{
        gridDBData=gridDBData.filter(r=>!selected.includes(parseInt(r.id)));
        renderGridDB();
      });
    }
  );
}











function renderGrid(){
  const wrap=document.getElementById('gridWrap');
  if(!gridData.length){wrap.innerHTML='<div class="grid-placeholder"><div class="icon">&#128203;</div><h2>Movements Safari</h2><p>Send rows here from Movement Extractor</p></div>';return;}
  let html='<table class="movements-table"><thead><tr>';
  GRID_COLS.forEach(c=>html+='<th>'+c+'</th>');
  html+='<th class="col-actions-g"></th></tr></thead><tbody>';
  gridData.forEach((row,ri)=>{
    const type=(row[1]||'').toLowerCase();
    const cls=type.includes('arrival')?'row-arrival':type.includes('departure')?'row-departure':type.includes('transfer')?'row-transfer':'';
    html+='<tr class="'+cls+'" id="grid-row-'+ri+'">';
    row.forEach((cell,ci)=>{
      if(ci===1)html+='<td class="col-type"><select class="type-select" onchange="gridCell('+ri+','+ci+',this.value);updateRowClass('+ri+')"><option value=""'+(cell===''?' selected':'')+'>&#8212;</option><option value="Arrival"'+(cell==='Arrival'?' selected':'')+'>Arrival</option><option value="Departure"'+(cell==='Departure'?' selected':'')+'>Departure</option><option value="Transfer"'+(cell==='Transfer'?' selected':'')+'>Transfer</option></select></td>';
      else{const c2=ci===0?'col-date':ci===3?'col-pax':ci===5?'col-time':ci===4?'col-flight':'';const itype=ci===5?'time':'text';html+='<td class="'+c2+'"><input type="'+itype+'" value="'+cell.replace(/"/g,'&quot;')+'" oninput="gridCell('+ri+','+ci+',this.value)" placeholder="'+GRID_COLS[ci]+'"></td>';}
    });
    html+='<td class="col-actions-g"><button class="btn-del-row2" onclick="deleteMemGridRow('+ri+')" title="Delete">&#10005;</button></td></tr>';
  });
  html+='</tbody></table><button class="btn-add-row3" onclick="addGridRow()">+ Add Row</button>';
  wrap.innerHTML=html;
}
function gridCell(ri,ci,val){gridData[ri][ci]=val;}
function updateRowClass(ri){const tr=document.getElementById('grid-row-'+ri);if(!tr)return;const type=(gridData[ri][1]||'').toLowerCase();tr.className=type.includes('arrival')?'row-arrival':type.includes('departure')?'row-departure':type.includes('transfer')?'row-transfer':'';}
function deleteMemGridRow(ri){gridData.splice(ri,1);renderGrid();}
function addGridRow(){gridData.push(GRID_COLS.map(()=>''));renderGrid();const rows=document.querySelectorAll('.movements-table tbody tr');if(rows.length)rows[rows.length-1].scrollIntoView({behavior:'smooth',block:'center'});}
function copyGridAllRows(){
  if(!gridData.length){alert('No data to copy.');return;}
  const tsv=gridData.map(r=>r.map((v,i)=>{if(i===0&&v)return fmtDate(v);if(i===5&&v)return v.replace(',',':');return v;}).join('\t')).join('\n');
  robustCopy(tsv, function(){const btn=document.getElementById('copyGridAllBtn');if(btn){btn.textContent='✓ Copied!';setTimeout(()=>btn.textContent='📋 Copy all rows',2000);}});
}
function extRowToGridRow(r){return[r.date||'',r.type||'',r.client||'',r.pax||'',r.flight||'',r.time||'',r.pickup||'',r.dropoff||'',r.driver||'',r.notes||'',r.dropbox||''];}
function sendToGrid(idx){const r=readExtDOM(idx);gridData.push(extRowToGridRow(r));renderGrid();const btn=document.querySelector('#card_'+idx+' .btn-send-row');if(btn){btn.textContent='✓ Sent!';btn.classList.add('sent');setTimeout(()=>{btn.classList.remove('sent');},1800);}showSentToast(1);}
function sendAllToGrid(){const allRows=extRows.map((_,i)=>extRowToGridRow(readExtDOM(i)));gridData.push(...allRows);renderGrid();const btn=document.querySelector('.btn-send-all');if(btn){const orig=btn.textContent;btn.textContent='✓ '+allRows.length+' rows sent!';setTimeout(()=>{btn.textContent=orig;},2000);}showSentToast(allRows.length);}
function showSentToast(n){let toast=document.getElementById('sentToast');if(!toast){toast=document.createElement('div');toast.id='sentToast';toast.style.cssText='position:fixed;bottom:28px;right:28px;background:var(--green);color:#fff;font-family:Open Sans,sans-serif;font-size:.82rem;font-weight:700;padding:12px 20px;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.25);z-index:9999;cursor:pointer;transition:opacity .3s;';toast.onclick=()=>{document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));document.getElementById('tab-grid').classList.add('active');document.querySelectorAll('.tab-btn')[2].classList.add('active');toast.style.opacity='0';setTimeout(()=>toast.remove(),300);};document.body.appendChild(toast);}toast.style.opacity='1';toast.textContent='✓ '+n+' row'+(n>1?'s':'')+' added to Movements Safari — click to view';clearTimeout(toast._timer);toast._timer=setTimeout(()=>{toast.style.opacity='0';setTimeout(()=>toast.remove(),300);},4000);}

// ═══════════════════════════════════════════════════
// TAB 4 — AUDIT EXCEL
// ═══════════════════════════════════════════════════
let auditTabSheetData=[];

function handleAuditDrop(e){e.preventDefault();document.getElementById('auditUploadZone').classList.remove('drag');if(e.dataTransfer.files[0])loadAuditFile(e.dataTransfer.files[0]);}

function loadAuditFile(file){
  if(!file)return;
  document.getElementById('auditFileInput').value='';
  closeAuditPanel('auditPanelTab');
  const reader=new FileReader();
  reader.onload=ev=>{
    try{
      const wb=XLSX.read(ev.target.result,{type:'array',cellDates:true});
      const sname=wb.SheetNames.find(s=>s.toUpperCase().includes('CONF'))||wb.SheetNames.find(s=>s.toUpperCase().includes('RECAP'))||wb.SheetNames[wb.SheetNames.length-1];
      const ws=wb.Sheets[sname];
      auditTabSheetData=XLSX.utils.sheet_to_json(ws,{header:1,defval:'',raw:false});
      document.getElementById('auditTabFileName').textContent='📄 '+file.name;
      document.getElementById('auditTabActionBar').style.display='';
      document.getElementById('auditUploadTitle').textContent='✓ '+file.name;
      const findings=auditConf(auditTabSheetData);
      renderAuditPanel(findings,'auditPanelTab');
    }catch(err){alert('Error reading file: '+err.message);}
  };
  reader.readAsArrayBuffer(file);
}

function runAuditTab(){if(!auditTabSheetData.length){alert('Please load a CONF file first.');return;}renderAuditPanel(auditConf(auditTabSheetData),'auditPanelTab');}
function runAudit(){if(!sheetData.length){alert('Please load a file in the Extractor tab first.');return;}renderAuditPanel(auditConf(sheetData),'auditPanelExt');}

function renderAuditPanel(findings,panelId){
  const panel=document.getElementById(panelId);if(!panel)return;
  const errors=findings.filter(f=>f.level==='error'),warnings=findings.filter(f=>f.level==='warning'),infos=findings.filter(f=>f.level==='info');
  let summary='';
  if(errors.length)summary+='<span class="audit-count audit-count-error">&#128308; '+errors.length+' error'+(errors.length>1?'s':'')+'</span>';
  if(warnings.length)summary+='<span class="audit-count audit-count-warning">&#128993; '+warnings.length+' warning'+(warnings.length>1?'s':'')+'</span>';
  if(infos.length)summary+='<span class="audit-count audit-count-info">&#128309; '+infos.length+' info</span>';
  if(!findings.length)summary='<span class="audit-count audit-count-ok">&#9989; No issues found</span>';
  let body='';
  if(!findings.length)body='<div class="audit-ok-row">&#9989; No issues detected in this CONF.</div>';
  else{const all=[...errors,...warnings,...infos];for(const f of all){const icon=f.level==='error'?'&#128308;':f.level==='warning'?'&#128993;':'&#128309;';body+='<div class="audit-item audit-item-'+f.level+'"><span>'+icon+'</span><span>'+escH(f.msg)+'</span></div>';}}
  panel.innerHTML='<div class="audit-panel"><div class="audit-header"><span class="audit-title">&#128269; Excel Audit</span><div class="audit-summary">'+summary+'</div><button class="audit-close" onclick="closeAuditPanel(\''+panelId+'\')">&#10005;</button></div><div class="audit-list">'+body+'</div></div>';
  panel.style.display='';panel.scrollIntoView({behavior:'smooth',block:'nearest'});
}
function closeAuditPanel(panelId){const p=document.getElementById(panelId||'auditPanelExt');if(p){p.style.display='none';p.innerHTML='';}}

function auditConf(rawData){
  const findings=[];
  const err=(msg)=>findings.push({level:'error',msg});
  const warn=(msg)=>findings.push({level:'warning',msg});
  const info=(msg)=>findings.push({level:'info',msg});
  let totalPax=0,totalPaxFound=false;
  for(let i=0;i<Math.min(10,rawData.length);i++){const r=rawData[i];if(!r)continue;if(String(r[0]||'').toUpperCase().includes('TOT PAX')){const v=parseInt(r[1]);if(!isNaN(v)&&v>0){totalPax=v;totalPaxFound=true;}else err('TOT PAX missing or zero');break;}}
  if(!totalPaxFound)warn('TOT PAX row not found');
  let headerIdx=-1;
  for(let i=0;i<rawData.length;i++){const r=rawData[i];if(!r)continue;if(r.some(c=>String(c||'').toUpperCase().trim()==='DATE')&&r.some(c=>String(c||'').toUpperCase().includes('PARK'))){headerIdx=i;break;}}
  if(headerIdx<0){err('Table header DATE/PARK not found');return findings;}
  const hdr=rawData[headerIdx].map(c=>String(c||'').toUpperCase().trim());
  const ci=kw=>hdr.findIndex(h=>h.includes(kw));
  const colDate=ci('DATE'),colPark=ci('PARK'),colFlights=ci('FLIGHT'),colHotel=ci('HOTEL'),colAct=ci('ACTIVIT');
  const colActDesc=hdr.findIndex((h,i)=>h.includes('ACTIVIT')&&i>colAct);
  let guideFound=false;
  for(let i=headerIdx;i<rawData.length;i++){const r=rawData[i];if(!r)continue;if(String(r[0]||'').toUpperCase().startsWith('GUIDE')&&r[1]){guideFound=true;break;}}
  if(!guideFound)warn('Guide not specified');
  const dataRows=[];
  for(let i=headerIdx+1;i<rawData.length;i++){const r=rawData[i];if(!r)continue;const dv=r[colDate];if(!dv)continue;if(String(r[0]||'').toUpperCase().includes('TOTAL'))break;const dateStr=fmtDate(dv);if(!dateStr)continue;dataRows.push({dateStr,park:String(r[colPark]||'').trim(),flights:String(r[colFlights]||'').trim(),actCost:r[colAct],actDesc:String(r[colActDesc]||'').trim(),hotel:String(r[colHotel]||'').trim()});}
  if(!dataRows.length){err('No data rows found');return findings;}
  const active=[...dataRows];const trailing=[];
  while(active.length>1&&!active[active.length-1].park)trailing.push(active.pop());
  if(trailing.length)info(trailing.length+' row'+(trailing.length>1?'s':'')+' with date only (no park/hotel): '+trailing.map(r=>r.dateStr).reverse().join(', ')+' — will be ignored during extraction');
  const OWN_ARR=/\bown\s*arr|\bself\s*arr/i;
  for(let i=1;i<active.length;i++){
    const d0=parseExtDate(active[i-1].dateStr),d1=parseExtDate(active[i].dateStr);
    if(d1<d0)err('Dates out of order: '+active[i-1].dateStr+' → '+active[i].dateStr);
    else if(d1===d0)warn('Duplicate date: '+active[i].dateStr);
    else{const gap=Math.round((d1-d0)/86400000);if(gap>1){const prevIsOwn=OWN_ARR.test(active[i-1].hotel)||OWN_ARR.test(active[i-1].park);const nextIsOwn=OWN_ARR.test(active[i].hotel)||OWN_ARR.test(active[i].park);if(prevIsOwn||nextIsOwn)info(gap+'-day gap ('+active[i-1].dateStr+' → '+active[i].dateStr+') — client own arrangement');else warn('Non-consecutive dates: '+gap+'-day gap between '+active[i-1].dateStr+' and '+active[i].dateStr);}}
  }
  const UNCERTAIN=/\bown\s*arr|\bself\s*arr|\btba\b|\bto\s*be\s*(confirmed|advised|arranged)\b|\btbc\b|\bn\/a\b|^\?+$/i;
  const DEST_AP=['JRO','KILI','ARK','ZNZ','ZANZIBAR','PEMBA','MAFIA','DAR'];
  const lastActive=active[active.length-1];
  if(lastActive.hotel&&lastActive.hotel.trim())warn('Hotel on departure day '+lastActive.dateStr+' ("'+lastActive.hotel+'"): please verify if correct');
  for(const row of active){
    if(row.park){
      if(UNCERTAIN.test(row.park))warn('Unconfirmed park on '+row.dateStr+': "'+row.park+'"');
      if(/\b(check|tbc|confirm|tbd)\b/i.test(row.park)&&!UNCERTAIN.test(row.park))warn('Park not finalised on '+row.dateStr+': "'+row.park+'"');
      const pUp=row.park.toUpperCase();const parts=pUp.split('-').map(s=>s.trim()).filter(Boolean);
      const hasSeren=parts.some(p=>p.includes('SERENGETI'));
      const hasAirstrip=pUp.includes('SEU')||pUp.includes('KGD')||pUp.includes('KOGATENDE')||pUp.includes('SERONERA');
      if(hasSeren&&!hasAirstrip&&parts.length>=2){const last=parts[parts.length-1];if(DEST_AP.some(k=>last.includes(k)))warn('"'+row.park+'" ('+row.dateStr+'): Serengeti departure without airstrip — add SEU/Seronera or KGD/Kogatende');}
      if(parts.length>3)warn('Park with more than 3 segments on '+row.dateStr+': "'+row.park+'"');
      const isTransit=parts.length>=2&&DEST_AP.some(k=>parts[parts.length-1].includes(k));
      const isLastRow=row===active[active.length-1];
      if(!row.hotel&&!isTransit&&!isLastRow)info('Hotel missing on '+row.dateStr+' ('+row.park+')');
    }else if(row.hotel)info('Hotel present but Park empty on '+row.dateStr+': "'+row.hotel+'"');
    if(row.hotel&&UNCERTAIN.test(row.hotel))warn('Unconfirmed hotel on '+row.dateStr+': "'+row.hotel+'"');
  }
  let arrLines=[],depLines2=[];let inArr=false,inDep=false;
  for(let i=headerIdx;i<rawData.length;i++){const r=rawData[i];if(!r)continue;const cell=String(r[0]||'').toUpperCase().trim();if(cell.includes('ARRIVAL')&&cell.includes('DETAIL')){inArr=true;inDep=false;continue;}if(cell.includes('DEPARTURE')&&cell.includes('DETAIL')){inDep=true;inArr=false;continue;}const v=r[0]&&String(r[0]).trim();if(!v)continue;if(inArr)arrLines.push(v);if(inDep)depLines2.push(v);}
  const TBA_RE=/\btba\b|\bto\s*be\s*(confirmed|advised|arranged)\b|\btbc\b/i;
  function auditSection(lines,label){const isArr=label==='ARRIVAL';if(!lines.length){err(label+' DETAILS section missing or empty');return;}const allTba=lines.every(l=>TBA_RE.test(l));if(allTba){err((isArr?'Arrival':'Departure')+' flight not confirmed (TBA)');return;}if(lines.some(l=>TBA_RE.test(l)))warn(label+' DETAILS contains TBA/unconfirmed lines');if(!lines.some(l=>extractFlight(l)||(parseIataLine(l)!==null)))warn((isArr?'Arrival':'Departure')+' flight code not identified');if(!lines.some(l=>extractTime(l)||(parseIataLine(l)!==null)))warn((isArr?'Arrival':'Departure')+' time not identified');if(!lines.some(l=>matchAirport(l)||/zanzibar|dar\s|arusha|kili|jro|pemba/i.test(l)))warn('No airport recognised in '+label+' DETAILS');}
  auditSection(arrLines,'ARRIVAL');auditSection(depLines2,'DEPARTURE');
  const SECTION_CHECKS=[{key:'ADULTS/TEEN',label:'Adults/Teen/Chd breakdown'},{key:'ROOMS TYPE',label:'Room types'},{key:'EXTRA DETAIL',label:'Extra details (Dietary/Medical/Occasions)'}];
  for(const{key,label}of SECTION_CHECKS){let found=false;for(let i=0;i<rawData.length-1;i++){const r=rawData[i];if(!r)continue;const c0=String(r[0]||'').toUpperCase();if(c0.includes(key)){const sameRowVal=String(r[1]||'').trim();const nextRowVal=String((rawData[i+1]||[])[0]||'').trim();const val=sameRowVal||nextRowVal;if(!val||val===''||val.toUpperCase()==='NAN')warn(label+': missing — please fill in or write N/A');found=true;break;}}if(!found)warn(label+' section not found in CONF');}
  let nameHeaderIdx=-1;
  for(let i=0;i<rawData.length;i++){const r=rawData[i];if(!r)continue;const c0=String(r[0]||'').toUpperCase();if(c0.includes('NAME')&&(c0.includes('FIRST')||c0.includes('SURNAME'))){nameHeaderIdx=i;break;}}
  if(nameHeaderIdx<0){warn('Passenger names section not found');}else{const names=[];for(let i=nameHeaderIdx+1;i<Math.min(nameHeaderIdx+30,rawData.length);i++){const r=rawData[i];if(!r)continue;const name=String(r[0]||'').trim();if(!name)continue;if(/^(total|guide|adults|rooms|extra|invoice|arrival|departure|name|room\s*type)/i.test(name))break;names.push({name,isTba:TBA_RE.test(name)||name.toUpperCase()==='TBA'});}if(!names.length){warn('Passenger names section is empty');}else{const tbaCnt=names.filter(n=>n.isTba).length;const realCnt=names.filter(n=>!n.isTba).length;const tot=tbaCnt+realCnt;if(tbaCnt===tot)err('No passenger names entered (all TBA)');else if(tbaCnt>0)warn(tbaCnt+' passenger'+(tbaCnt>1?'s':'')+' with TBA name');if(totalPaxFound&&tot<totalPax)warn('Missing names: found '+tot+' out of '+totalPax+' passengers');if(totalPaxFound&&tot>totalPax)warn('Excess names: found '+tot+' but TOT PAX = '+totalPax);}}
  return findings;
}

// ═══════════════════════════════════════════════════
// TAB 5 — FIX CONFLICTS (pure client-side)
// ═══════════════════════════════════════════════════
let cfWBs=[],cfBaseIdx=0;
const CF_SKIP=['total costs','extra charges camping','total price','margin','price per person','price pp to','price pp special to'];

const cfDrop=document.getElementById('cf-drop');
cfDrop.addEventListener('dragover',e=>{e.preventDefault();cfDrop.classList.add('drag');});
cfDrop.addEventListener('dragleave',()=>cfDrop.classList.remove('drag'));
cfDrop.addEventListener('drop',e=>{e.preventDefault();cfDrop.classList.remove('drag');cfLoadFiles(e.dataTransfer.files);});

function cfLoadFiles(files){
  if(files.length<2){document.getElementById('cf-status').textContent='Please select at least 2 files.';return;}
  cfWBs=[];document.getElementById('cf-status').textContent='Reading files...';document.getElementById('cf-results').innerHTML='';
  const promises=Array.from(files).map(f=>new Promise((res,rej)=>{
    const reader=new FileReader();
    reader.onload=e=>{try{const wb=XLSX.read(e.target.result,{type:'array',cellStyles:true,cellNF:true,cellFormula:true});const sname=wb.SheetNames.find(n=>/CONF/i.test(n))||wb.SheetNames.find(n=>/RECAP/i.test(n))||wb.SheetNames[wb.SheetNames.length-1];res({filename:f.name,wb,sname,file:f});}catch(err){rej(f.name+': '+err.message);}};
    reader.readAsArrayBuffer(f);
  }));
  Promise.all(promises).then(wbs=>{
    cfWBs=wbs;cfBaseIdx=wbs.reduce((bi,wb,i,arr)=>{const r=XLSX.utils.sheet_to_json(wb.wb.Sheets[wb.sname],{header:1});const b=XLSX.utils.sheet_to_json(arr[bi].wb.Sheets[arr[bi].sname],{header:1});return r.length>b.length?i:bi;},0);cfCompare();
  }).catch(err=>document.getElementById('cf-status').textContent='Error: '+err);
}
function cfCellStr(v){if(v===null||v===undefined)return'';if(v instanceof Date)return String(v.getDate()).padStart(2,'0')+'/'+String(v.getMonth()+1).padStart(2,'0')+'/'+v.getFullYear();if(typeof v==='number'&&Number.isInteger(v))return String(v);if(typeof v==='number')return String(parseFloat(v.toFixed(10)));return String(v).trim();}
function cfCompare(){
  const sheets=cfWBs.map(wb=>XLSX.utils.sheet_to_json(wb.wb.Sheets[wb.sname],{header:1,defval:null}));
  const maxRow=Math.max(...sheets.map(s=>s.length));const maxCol=Math.max(...sheets.map(s=>Math.max(...s.map(r=>r.length),0)));
  const discs=[];
  for(let r=0;r<maxRow;r++){
    const rv=sheets.map(s=>(s[r]||[]).map(cfCellStr));
    if(rv.some(row=>row.some(v=>CF_SKIP.includes(v.toLowerCase()))))continue;
    for(let c=0;c<maxCol;c++){
      const vals=rv.map(row=>row[c]||'');const ne=vals.filter(v=>v!=='');if(!ne.length)continue;
      const uniq=[...new Set(ne)];if(uniq.length===1&&ne.length===vals.length)continue;
      const type=uniq.length===1?'gap':'conflict';const ctx=[];const br=sheets[cfBaseIdx][r]||[];
      for(let cc=0;cc<Math.min(8,maxCol);cc++){if(cc===c)continue;const cv=cfCellStr(br[cc]);if(cv){ctx.push(cv);if(ctx.length>=3)break;}}
      discs.push({row:r,col:c,colLetter:colLtr(c),type,vals,suggested:uniq.length===1?uniq[0]:null,ctx});
    }
  }
  const nConf=discs.filter(d=>d.type==='conflict').length,nGap=discs.filter(d=>d.type==='gap').length;
  document.getElementById('cf-status').innerHTML='Files: <strong>'+cfWBs.map(w=>xss(w.filename)).join(', ')+'</strong><br>Found <strong>'+nConf+' conflicts</strong> and <strong>'+nGap+' gaps</strong>. Base: <strong>'+xss(cfWBs[cfBaseIdx].filename)+'</strong>';
  if(!discs.length){document.getElementById('cf-results').innerHTML='<p style="color:var(--green);font-weight:700;margin-top:12px;">&#10003; No discrepancies found!</p>';return;}
  let html='<div style="overflow-x:auto;"><table class="disc-tbl"><thead><tr><th>Row</th><th>Col</th><th>Type</th><th>Context</th>'+cfWBs.map(w=>'<th>'+xss(w.filename)+'</th>').join('')+'<th>Use Value</th></tr></thead><tbody>';
  discs.forEach((d,i)=>{const opts=[...new Set(d.vals.filter(v=>v!==''))];html+='<tr><td>'+(d.row+1)+'</td><td>'+d.colLetter+'</td><td><span class="badge-'+d.type+'">'+d.type+'</span></td><td style="font-size:.75rem;color:var(--grey-mid);">'+xss(d.ctx.join(' | '))+'</td>'+d.vals.map(v=>'<td>'+xss(v)+'</td>').join('')+'<td><select class="val-sel" id="cfs-'+i+'">'+opts.map(o=>'<option value="'+xss(o)+'"'+(o===d.suggested?' selected':'')+'>'+xss(o)+'</option>').join('')+'<option value="">-- empty --</option></select></td></tr>';});
  html+='</tbody></table></div><div class="cf-actions"><button class="btn btn-primary" id="cfApplyBtn">Apply &amp; Download Fixed File</button></div>';
  document.getElementById('cf-results').innerHTML=html;
  var applyBtn=document.getElementById('cfApplyBtn');
  if(applyBtn) applyBtn.onclick=function(){cfApply(discs);};
}
function cfApply(discs){
  const base=cfWBs[cfBaseIdx];
  const changes=discs.map((d,i)=>({
    addr: XLSX.utils.encode_cell({r:d.row,c:d.col}),
    value: document.getElementById('cfs-'+i)?.value??''
  }));
  const fd=new FormData();
  fd.append('file',base.file);
  fd.append('sheet',base.sname);
  fd.append('filename',base.filename);
  fd.append('changes',JSON.stringify(changes));
  const btn=document.getElementById('cfApplyBtn');
  btn.disabled=true;btn.textContent='Processing…';
  fetch('api/fix_conflicts_apply.php',{method:'POST',body:fd})
    .then(r=>{
      if(!r.ok)return r.text().then(t=>{throw new Error(t);});
      return r.blob();
    })
    .then(blob=>{
      const stem=base.filename.replace(/\s*\([^)]*conflicted copy[^)]*\)/i,'').replace(/\.xlsx$/i,'').trim();
      const url=URL.createObjectURL(blob);
      const a=document.createElement('a');a.href=url;a.download=stem+'_FIXED.xlsx';a.click();
      URL.revokeObjectURL(url);
    })
    .catch(err=>showToast('Error: '+err.message,'error'))
    .finally(()=>{btn.disabled=false;btn.textContent='Apply & Download Fixed File';});
}
function colLtr(n){let s='';n++;while(n>0){s=String.fromCharCode(64+(n%26||26))+s;n=Math.floor((n-1)/26);}return s;}
</script>

<?php include __DIR__ . '/../../includes/layout_footer.php'; ?>
