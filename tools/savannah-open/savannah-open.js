// savannah-open.js — open a Dropbox folder in Explorer from a "savannah://" link.
// Run by the protocol handler as:  wscript.exe savannah-open.js "<url>"
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

if (fso.FolderExists(full)) {
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
