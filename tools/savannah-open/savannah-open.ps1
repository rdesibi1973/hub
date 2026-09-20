<#
  savannah-open.ps1 — protocol-handler launcher for the "savannah://" scheme.

  Invoked by the browser as:
      savannah://open?path=<url-encoded relative path under %DROPBOX_HOME%>
  e.g. savannah://open?path=001_Safari%2FSmithJohn(BTG-Roberto)_..._BALANCE

  Opens that folder in Windows Explorer. Security: the path is decoded, then
  rejected if it contains traversal (..), a drive letter, UNC/rooted paths, or
  shell/wildcard characters, and the resolved path MUST stay inside
  %DROPBOX_HOME%. Explorer is launched directly (no shell), so the argument
  cannot inject a command.
#>
param([string]$Url)

function Show-Msg([string]$text) {
    try { (New-Object -ComObject WScript.Shell).Popup($text, 8, 'Savannah — Open folder', 0) | Out-Null } catch {}
}

try {
    if ([string]::IsNullOrWhiteSpace($Url)) { return }

    # ── Extract the relative path from the URL ────────────────────────────────
    $rel = $null
    if ($Url -match '[?&]path=([^&]+)') {
        $rel = [System.Uri]::UnescapeDataString($matches[1])
    } else {
        $rel = $Url -replace '^savannah:(//)?(open/?)?', ''
        $rel = [System.Uri]::UnescapeDataString($rel)
    }
    if ([string]::IsNullOrWhiteSpace($rel)) { return }

    # Normalize separators and trim.
    $rel = ($rel -replace '/', '\').Trim().Trim('\')

    # ── Reject anything unsafe ────────────────────────────────────────────────
    if ($rel -match '\.\.')            { Show-Msg 'Blocked: path traversal.'; return }
    if ($rel -match '[:*?"<>|]')       { Show-Msg 'Blocked: invalid characters.'; return }
    if ($rel -match '[\x00-\x1f]')     { return }
    if ($rel.StartsWith('\'))          { Show-Msg 'Blocked: rooted/UNC path.'; return }

    $root = $env:DROPBOX_HOME
    if ([string]::IsNullOrWhiteSpace($root)) {
        Show-Msg 'DROPBOX_HOME is not set on this PC. Ask IT to set it (same as the old BackOffice tool).'
        return
    }

    $rootFull = [System.IO.Path]::GetFullPath($root)
    $full     = [System.IO.Path]::GetFullPath((Join-Path $rootFull $rel))

    # Must stay within DROPBOX_HOME.
    if (-not $full.StartsWith($rootFull, [System.StringComparison]::OrdinalIgnoreCase)) {
        Show-Msg 'Blocked: path is outside the Dropbox folder.'
        return
    }

    # ── Open in Explorer ──────────────────────────────────────────────────────
    if (Test-Path -LiteralPath $full -PathType Container) {
        Start-Process explorer.exe -ArgumentList ('"{0}"' -f $full)
    } elseif (Test-Path -LiteralPath $full -PathType Leaf) {
        Start-Process explorer.exe -ArgumentList ('/select,"{0}"' -f $full)
    } else {
        # Folder not found (renamed or not synced yet): open nearest existing parent.
        $parent = Split-Path -LiteralPath $full -Parent
        if ($parent -and (Test-Path -LiteralPath $parent -PathType Container)) {
            Start-Process explorer.exe -ArgumentList ('"{0}"' -f $parent)
        } else {
            Show-Msg ("Folder not found locally:`n{0}`n`nIt may have been renamed or not yet synced by Dropbox." -f $full)
        }
    }
} catch {
    # Fail silently; never pop up on unexpected errors.
}
