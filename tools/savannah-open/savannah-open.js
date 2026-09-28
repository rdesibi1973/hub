// savannah-open.js — open a Dropbox folder in Explorer from a "savannah://" link.
// Run by the protocol handler as:  wscript.exe savannah-open.js "<url>"
// With "&open=calc" it opens the folder's quotation Excel instead (see pickCalc).
//
// Uses JScript via wscript.exe (no PowerShell) to avoid antivirus heuristics that
// flag browser-spawned powershell.exe command lines. Security: the path is decoded,
// rejected on traversal / drive / shell-wildcard characters, and must resolve inside
// %DROPBOX_HOME%; Explorer is launched directly (no shell), so no command injection.

var sh  = WScript.CreateObject("WScript.Shell");
var fso = WScript.CreateObject("Scripting.FileSystemObject");

function warn(msg) { try { sh.Popup(msg, 8, "Savannah \u2014 Open folder", 48); } catch (e) {} }

if (WScript.Arguments.length === 0) { WScript.Quit(); }
var url = WScript.Arguments(0);

// ── Extract the relative path from the URL ──────────────────────────────────
var wantCalc = /[?&]open=calc(&|$)/i.test(url);
var rel, m = /[?&]path=([^&]+)/.exec(url);
if (m) { rel = m[1]; }
else   { rel = url.replace(/^savannah:(\/\/)?(open\/?)?/i, ""); }
try { rel = decodeURIComponent(rel); } catch (e) { try { rel = unescape(rel); } catch (e2) {} }
if (!rel) { WScript.Quit(); }

// Normalize separators, trim leading/trailing spaces and slashes.
rel = rel.replace(/\//g, "\\").replace(/^[\s\\]+/, "").replace(/[\s\\]+$/, "");

// ── Reject anything unsafe ──────────────────────────────────────────────────
if (rel.indexOf("..") !== -1)      { warn("Blocked: path traversal."); WScript.Quit(); }
if (/[:*?"<>|]/.test(rel))         { warn("Blocked: invalid characters."); WScript.Quit(); }

var root = sh.ExpandEnvironmentStrings("%DROPBOX_HOME%");
if (!root || root === "%DROPBOX_HOME%") {
  warn("DROPBOX_HOME is not set on this PC. Ask IT to set it (same as the old BackOffice tool).");
  WScript.Quit();
}
root = root.replace(/\\+$/, "");
var full = root + "\\" + rel;

// Must stay within DROPBOX_HOME.
if (full.substring(0, root.length).toLowerCase() !== root.toLowerCase()) {
  warn("Blocked: path is outside the Dropbox folder."); WScript.Quit();
}

// Full path: some PCs have a PATH without the Windows folders.
var explorer = sh.ExpandEnvironmentStrings("%SystemRoot%") + "\\explorer.exe";
function openExplorer(args) { sh.Run('"' + explorer + '" ' + args, 1, false); }

// ── Pick the booking's quotation Excel (same rule as Hub's fill_calc) ──────
// Top-level "*_Calc.xlsx", else any .xlsx/.xlsm/.xls; Office lock files (~$)
// skipped; if several, the highest leading number (natural sort, descending).
function natCmp(a, b) {
  var ra = a.toLowerCase().match(/\d+|\D+/g) || [], rb = b.toLowerCase().match(/\d+|\D+/g) || [];
  for (var i = 0; i < ra.length && i < rb.length; i++) {
    if (ra[i] === rb[i]) continue;
    var na = /^\d/.test(ra[i]), nb = /^\d/.test(rb[i]);
    if (na && nb) return parseInt(ra[i], 10) - parseInt(rb[i], 10);
    return ra[i] < rb[i] ? -1 : 1;
  }
  return ra.length - rb.length;
}
// Listing via Shell.Application: JScript's Enumerator over FSO .Files returns
// nothing on Windows 11 24H2 (JScript9Legacy). System.FileName always carries
// the extension, even with "Hide extensions for known file types" on.
function pickCalc(dir) {
  var calc = [], any = [];
  var items = WScript.CreateObject("Shell.Application").NameSpace(dir).Items();
  for (var i = 0; i < items.Count; i++) {
    var it = items.Item(i);
    if (it.IsFolder) continue;
    var n = String(it.ExtendedProperty("System.FileName") || it.Name);
    if (n.indexOf("~$") === 0) continue;
    if (/_calc\.xlsx$/i.test(n)) calc.push(n);
    else if (/\.(xlsx|xlsm|xls)$/i.test(n)) any.push(n);
  }
  var c = calc.length ? calc : any;
  if (!c.length) return null;
  c.sort(natCmp);
  return dir + "\\" + c[c.length - 1];
}

if (wantCalc && fso.FolderExists(full)) {
  var xl = pickCalc(full);
  if (xl) openExplorer('"' + xl + '"');   // Explorer hands the file to Excel
  else { warn("No Excel (*_Calc.xlsx) found in:\n" + full + "\n\nOpening the folder instead."); openExplorer('"' + full + '"'); }
} else if (fso.FolderExists(full)) {
  openExplorer('"' + full + '"');
} else if (fso.FileExists(full)) {
  openExplorer('/select,"' + full + '"');
} else {
  var i = full.lastIndexOf("\\");
  var parent = i > 0 ? full.substring(0, i) : full;
  if (fso.FolderExists(parent)) {
    openExplorer('"' + parent + '"');
  } else {
    warn("Folder not found locally:\n" + full + "\n\nIt may have been renamed or not yet synced by Dropbox.");
  }
}
