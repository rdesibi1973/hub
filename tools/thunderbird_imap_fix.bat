<# : batch + PowerShell in one file
@echo off
rem Thunderbird: 1 cached IMAP connection per mailbox, no IDLE, check every 10 minutes - only for our mail domains
rem (Savannah + the lodges' domains), not Gmail.
rem BlueHost counts every IMAP connection in the account limit (25 processes); since Dovecot 2.4 (Oct 2026)
rem idle connections are no longer cut (ticket E-621469). NOT 0: Thunderbird treats 0 as "unset" and writes
rem back its default of 5 (seen in prefs.js on 20 Oct 2026), so 1 is the real minimum. Thunderbird must be closed.
rem Undo: run "thunderbird_imap_fix.bat undo".
rem Manual mode ("thunderbird_imap_fix.bat manual", or thunderbird_imap_manual.bat): no automatic check at all, not
rem even at startup, except the boxes in $autoBoxes below; the others are read only when you open them.
rem Profile in an unusual place (portable Thunderbird...): in Thunderbird open Help > Troubleshooting Information >
rem Profile Folder > Open Folder, close Thunderbird, then drag that folder onto this .bat file.
powershell -NoProfile -ExecutionPolicy Bypass -Command "$undo = '%~1' -eq 'undo'; $manual = '%~1' -eq 'manual'; $given = '%~1'; iex ((Get-Content -LiteralPath '%~f0') -join [Environment]::NewLine)"
pause
goto :eof
#>

$ErrorActionPreference = 'Stop'
$begin = '// --- Savannah IMAP fix: begin ---'
$end   = '// --- Savannah IMAP fix: end ---'
$ourHosts = 'savannahexplorers|bluehost|mareraviewlodge|kifarutentedlodge|theorangicollection'
# manual mode: these boxes keep the 10-minute check
$autoBoxes = @('info@savannahexplorers.com')
if ($undo -or $manual) { $given = '' }

if (Get-Process thunderbird -ErrorAction SilentlyContinue) {
    Write-Host 'Thunderbird is open: close it and run this file again.' -ForegroundColor Red
    return
}
# Profiles: normal install (%APPDATA%), Microsoft Store install (Packages\...\LocalCache), and any path in profiles.ini
$tbRoots = @(Join-Path $env:APPDATA 'Thunderbird')
$tbRoots += @(Get-ChildItem -LiteralPath (Join-Path $env:LOCALAPPDATA 'Packages') -Directory -Filter '*Thunderbird*' -ErrorAction SilentlyContinue |
    ForEach-Object { Join-Path $_.FullName 'LocalCache\Roaming\Thunderbird' })
$dirs = @()
if ($given -and -not $undo) {
    # a profile folder dragged onto the .bat: use only that one
    if (-not (Test-Path -LiteralPath (Join-Path $given 'prefs.js'))) {
        Write-Host "Not a Thunderbird profile folder (no prefs.js inside): $given" -ForegroundColor Red
        return
    }
    $tbRoots = @()
    $dirs = @($given)
}
foreach ($r in $tbRoots) {
    $dirs += @(Get-ChildItem -LiteralPath (Join-Path $r 'Profiles') -Directory -ErrorAction SilentlyContinue | ForEach-Object FullName)
    $ini = Join-Path $r 'profiles.ini'
    if (Test-Path $ini) {
        $rel = $true
        foreach ($l in (Get-Content -LiteralPath $ini)) {
            if ($l -match '^\[') { $rel = $true }
            elseif ($l -match '^IsRelative=0') { $rel = $false }
            elseif ($l -match '^Path=(.+)$') { $dirs += $(if ($rel) { Join-Path $r ($Matches[1] -replace '/', '\') } else { $Matches[1] }) }
        }
    }
}
$profiles = @($dirs | Where-Object { Test-Path (Join-Path $_ 'prefs.js') } | Sort-Object -Unique | ForEach-Object { Get-Item -LiteralPath $_ })
if (-not $profiles) {
    Write-Host 'No Thunderbird profile found on this PC (user ' $env:USERNAME ').' -ForegroundColor Red
    $other = @()
    if (Get-Process outlook -ErrorAction SilentlyContinue) { $other += 'Outlook (classic)' }
    if (Get-Process olk -ErrorAction SilentlyContinue) { $other += 'new Outlook' }
    if (Get-Process HxOutlook, HxTsr -ErrorAction SilentlyContinue) { $other += 'Windows Mail' }
    if (Get-Process MailClient -ErrorAction SilentlyContinue) { $other += 'eM Client' }
    if ($other) { Write-Host ('Mail program running now: ' + ($other -join ', ') + ' - change it by hand (no IDLE/push, check every 10-15 min).') -ForegroundColor Yellow }
    else {
        Write-Host 'If Thunderbird is used here: open it, Help > Troubleshooting Information > Profile Folder > Open Folder,' -ForegroundColor Yellow
        Write-Host 'close Thunderbird, then drag that folder onto this .bat file.' -ForegroundColor Yellow
    }
    return
}
$utf8 = New-Object System.Text.UTF8Encoding $false

foreach ($p in $profiles) {
    Write-Host ''
    Write-Host "Profile: $($p.Name)"
    $prefs = [IO.File]::ReadAllText((Join-Path $p.FullName 'prefs.js'), $utf8)
    $userJs = Join-Path $p.FullName 'user.js'
    $old = if (Test-Path $userJs) { [IO.File]::ReadAllText($userJs, $utf8) } else { '' }
    # drop our previous block, keep anything else in user.js
    $keep = [regex]::Replace($old, '(?s)\r?\n?' + [regex]::Escape($begin) + '.*?' + [regex]::Escape($end) + '\r?\n?', [Environment]::NewLine).Trim()

    if ($undo) {
        if ($keep) { [IO.File]::WriteAllText($userJs, $keep + [Environment]::NewLine, $utf8) } elseif (Test-Path $userJs) { Remove-Item $userJs }
        Write-Host '  settings removed (Thunderbird goes back to its defaults next time it starts)' -ForegroundColor Yellow
        continue
    }

    # only servers of accounts still in the account list: a removed account can leave its mail.server.* prefs behind
    $accList = [regex]::Match($prefs, 'user_pref\("mail\.accountmanager\.accounts",\s*"([^"]*)"\);').Groups[1].Value -split ','
    $active = @($accList | Where-Object { $_ } | ForEach-Object {
        [regex]::Match($prefs, 'user_pref\("mail\.account\.' + [regex]::Escape($_.Trim()) + '\.server",\s*"([^"]*)"\);').Groups[1].Value } | Where-Object { $_ })

    $lines = @()
    foreach ($m in [regex]::Matches($prefs, 'user_pref\("mail\.server\.(server\d+)\.type",\s*"imap"\);')) {
        $id = $m.Groups[1].Value
        if ($active -notcontains $id) { continue }
        $hm = [regex]::Match($prefs, 'user_pref\("mail\.server\.' + $id + '\.hostname",\s*"([^"]*)"\);')
        $um = [regex]::Match($prefs, 'user_pref\("mail\.server\.' + $id + '\.userName",\s*"([^"]*)"\);')
        $hostName = $hm.Groups[1].Value
        $label = if ($um.Success) { $um.Groups[1].Value } else { $id }
        if ($hostName -notmatch $ourHosts) {
            Write-Host "  skipped: $label ($hostName, not our mail server)"
            continue
        }
        $lines += "user_pref(""mail.server.$id.max_cached_connections"", 1);"
        $lines += "user_pref(""mail.server.$id.use_idle"", false);"
        if ($manual -and $autoBoxes -notcontains $label.ToLower()) {
            Write-Host "  manual: $label ($hostName)" -ForegroundColor Green
            $lines += "user_pref(""mail.server.$id.check_new_mail"", false);"
            $lines += "user_pref(""mail.server.$id.login_at_startup"", false);"
        } else {
            Write-Host "  fixed: $label ($hostName)" -ForegroundColor Green
            $lines += "user_pref(""mail.server.$id.check_new_mail"", true);"
            $lines += "user_pref(""mail.server.$id.check_time"", 10);"
            # a box switched back from manual mode checks at startup again
            $lines += "user_pref(""mail.server.$id.login_at_startup"", true);"
        }
    }
    if (-not $lines) {
        Write-Host '  no mailbox on our mail server in this profile'
        continue
    }
    $block = @($begin) + $lines + @($end)
    $new = (@(@($keep) | Where-Object { $_ }) + $block) -join [Environment]::NewLine
    [IO.File]::WriteAllText($userJs, $new + [Environment]::NewLine, $utf8)
}
Write-Host ''
Write-Host 'Done. Open Thunderbird again.' -ForegroundColor Green
if ($manual) {
    Write-Host 'Manual boxes: click the box (or its Inbox) to read it, or right-click it > Get Messages.' -ForegroundColor Yellow
    Write-Host 'Avoid "Get All New Messages": it opens every box at once.' -ForegroundColor Yellow
}
