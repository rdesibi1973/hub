# Savannah — "Open folder in Explorer" from Hub

A tiny per-PC helper that lets Hub links open a Dropbox folder in Windows
Explorer, replacing that convenience of the old Java BackOffice tool — without
requiring Java. The heavy work (creating/renaming folders) is done by Hub via
the Dropbox API; this only opens a local folder.

## What it does
Registers a custom URL scheme `savannah://` for the current Windows user. When
you click a link like:

    savannah://open?path=001_Safari/SmithJohn(BTG-Roberto)_..._BALANCE

Explorer opens `%DROPBOX_HOME%\001_Safari\SmithJohn(BTG-Roberto)_..._BALANCE`.

The handler is a small JScript file run by `wscript.exe` (no PowerShell) — this
avoids antivirus heuristics that flag browser-spawned `powershell.exe` command
lines.

## Install (per PC, once — no admin needed)
1. Copy this `savannah-open` folder to the PC (anywhere).
2. Double-click **`install.cmd`**.
3. Test: paste `savannah://open?path=001_Safari` into the browser address bar
   and press Enter — the 001_Safari folder should open in Explorer.

The first time a browser sees a `savannah://` link it may ask
"Open Savannah Protocol?" — tick "Always allow" and confirm.

Requires the `DROPBOX_HOME` environment variable to be set (same one the old
BackOffice tool used).

## Uninstall
Double-click **`uninstall.cmd`**.

## Files
- `savannah-open.js` — the launcher (validates the path, opens Explorer).
- `install.cmd` — copies the launcher to `%LOCALAPPDATA%\SavannahTools` and
  registers the handler in HKCU (pure batch + reg.exe).
- `uninstall.cmd` — remove it.

## Security
The launcher only opens folders **inside `%DROPBOX_HOME%`**. It rejects path
traversal (`..`), drive letters, UNC/rooted paths, and shell/wildcard
characters, and launches Explorer directly (no shell), so a link cannot run a
command or reach files outside Dropbox.

## If the antivirus still blocks it
Bitdefender/others may still be cautious about any browser-launched script. If a
block persists, add an exception for `%LOCALAPPDATA%\SavannahTools\savannah-open.js`
(and `wscript.exe`) in the AV's Advanced Threat Control / exclusions. As a
no-install alternative, use the **Copy path** button in Hub (below).

## No-install fallback
Every Hub folder also has a **Copy path** button that copies
`%DROPBOX_HOME%\001_Safari\<folder>`. Paste it into the Explorer address bar and
press Enter — Windows expands the variable and opens the folder, no handler
needed.
