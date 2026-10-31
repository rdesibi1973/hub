<# : batch + PowerShell in one file
@echo off
rem Mail check for one PC: public IP and open IMAP connections to our mail server (box2233, BlueHost).
rem Looking for the device on 197.250.231.152 that holds several sessions each for glady@, operations@, office@
rem (BlueHost log 23 Oct 2026, ticket E-621469). Run it with the mail program OPEN, then send a screenshot.
rem Read-only: changes nothing.
powershell -NoProfile -ExecutionPolicy Bypass -Command "iex ((Get-Content -LiteralPath '%~f0') -join [Environment]::NewLine)"
pause
goto :eof
#>

$wanted = '197.250.231.152'
$names  = 'box2233.bluehost.com', 'mail.savannahexplorers.com', 'savannahexplorers.com'

Write-Host ''
Write-Host "PC: $env:COMPUTERNAME   user: $env:USERNAME   $(Get-Date -Format 'yyyy-MM-dd HH:mm')"

try { $ip = (Invoke-RestMethod -Uri 'https://api.ipify.org' -TimeoutSec 15).Trim() } catch { $ip = '' }
if (-not $ip) { Write-Host 'Public IP: not found (no internet?)' -ForegroundColor Yellow }
elseif ($ip -eq $wanted) { Write-Host "Public IP: $ip   <-- THIS IS THE ONE WE ARE LOOKING FOR" -ForegroundColor Red }
else { Write-Host "Public IP: $ip" }

$server = @('50.87.221.49')
foreach ($n in $names) {
    try { $server += @(Resolve-DnsName $n -Type A -ErrorAction Stop | Where-Object IPAddress | ForEach-Object IPAddress) } catch { }
}
$server = @($server | Sort-Object -Unique)

$conns = @(Get-NetTCPConnection -State Established -ErrorAction SilentlyContinue | Where-Object { $server -contains $_.RemoteAddress })
Write-Host ''
if (-not $conns) {
    Write-Host 'Connections to our mail server: none (is the mail program open?)'
} else {
    Write-Host "Connections to our mail server ($($server -join ', ')): $($conns.Count)"
    foreach ($g in ($conns | Group-Object OwningProcess)) {
        $p = Get-Process -Id $g.Name -ErrorAction SilentlyContinue
        $ports = ($g.Group | Group-Object RemotePort | ForEach-Object { "port $($_.Name) x$($_.Count)" }) -join ', '
        Write-Host ('  {0,-15} {1,3} connections   ({2})' -f $(if ($p) { $p.ProcessName } else { "pid $($g.Name)" }), $g.Count, $ports)
    }
}

# Thunderbird: is our fix (thunderbird_imap_fix.bat) in the profile?
$roots = @(Join-Path $env:APPDATA 'Thunderbird')
$roots += @(Get-ChildItem -LiteralPath (Join-Path $env:LOCALAPPDATA 'Packages') -Directory -Filter '*Thunderbird*' -ErrorAction SilentlyContinue |
    ForEach-Object { Join-Path $_.FullName 'LocalCache\Roaming\Thunderbird' })
$profiles = @($roots | ForEach-Object { Get-ChildItem -LiteralPath (Join-Path $_ 'Profiles') -Directory -ErrorAction SilentlyContinue } |
    Where-Object { Test-Path (Join-Path $_.FullName 'prefs.js') })
Write-Host ''
if (-not $profiles) {
    Write-Host 'Thunderbird: no profile on this PC'
} else {
    foreach ($p in $profiles) {
        $userJs = Join-Path $p.FullName 'user.js'
        $uj = if (Test-Path $userJs) { Get-Content -LiteralPath $userJs -Raw } else { '' }
        # the first version wrote max_cached_connections 0, which Thunderbird ignores (it writes back 5)
        $fixed = ($uj -match 'Savannah IMAP fix') -and ($uj -notmatch 'max_cached_connections", 0\)')
        if ($uj -match 'max_cached_connections", 0\)') {
            Write-Host "Thunderbird profile $($p.Name): OLD FIX (0 connections = ignored) - close Thunderbird and run thunderbird_imap_fix.bat" -ForegroundColor Red
        }
        $prefs = Get-Content -LiteralPath (Join-Path $p.FullName 'prefs.js') -Raw
        # only servers of accounts still in the account list: a removed account can leave its mail.server.* prefs behind
        $accList = [regex]::Match($prefs, 'user_pref\("mail\.accountmanager\.accounts",\s*"([^"]*)"\);').Groups[1].Value -split ','
        $active = @($accList | Where-Object { $_ } | ForEach-Object {
            [regex]::Match($prefs, 'user_pref\("mail\.account\.' + [regex]::Escape($_.Trim()) + '\.server",\s*"([^"]*)"\);').Groups[1].Value } | Where-Object { $_ })
        $boxes = @([regex]::Matches($prefs, 'user_pref\("mail\.server\.(server\d+)\.userName",\s*"([^"]+@(savannahexplorers|mareraviewlodge|kifarutentedlodge|theorangicollection)[^"]*)"\);') |
            Where-Object { $active -contains $_.Groups[1].Value } | ForEach-Object { $_.Groups[2].Value } | Sort-Object -Unique)
        if ($fixed) { Write-Host "Thunderbird profile $($p.Name): fix OK" -ForegroundColor Green }
        elseif ($uj -notmatch 'max_cached_connections", 0\)') { Write-Host "Thunderbird profile $($p.Name): FIX MISSING - close Thunderbird and run thunderbird_imap_fix.bat" -ForegroundColor Red }
        if ($boxes) { Write-Host ('  mailboxes: ' + ($boxes -join ', ')) }
        # details per active account on our servers: values Thunderbird really uses (prefs.js) and whether it read user.js
        $tbProc = Get-Process thunderbird -ErrorAction SilentlyContinue | Sort-Object StartTime | Select-Object -First 1
        if ($fixed -and $tbProc -and $tbProc.StartTime -lt (Get-Item $userJs).LastWriteTime) {
            Write-Host '  Thunderbird was started BEFORE the fix was written: close it and open it again' -ForegroundColor Red
        }
        foreach ($sid in $active) {
            $get = { param($k) [regex]::Match($prefs, 'user_pref\("mail\.server\.' + $sid + '\.' + $k + '",\s*"?([^"\)]*)"?\);').Groups[1].Value }
            $u = & $get 'userName'; $h = & $get 'hostname'; $type = & $get 'type'
            if ($u -notmatch '@(savannahexplorers|mareraviewlodge|kifarutentedlodge|theorangicollection)' -and $h -notmatch 'savannahexplorers|bluehost|50\.87\.') { continue }
            $cached = & $get 'max_cached_connections'; $idle = & $get 'use_idle'; $check = & $get 'check_time'
            $check = if ((& $get 'check_new_mail') -eq 'false') { 'manual' } elseif ($check) { "$check min" } else { '-' }
            Write-Host ('  {0,-9} {1,-34} {2,-26} {3,-4}  cached={4} idle={5} check={6}' -f $sid, $u, $h, $type,
                $(if ($cached) { $cached } else { 'default(5)' }), $(if ($idle) { $idle } else { 'default(on)' }), $check)
        }
    }
}
Write-Host ''
Write-Host 'Take a screenshot of this window and send it to Roberto.' -ForegroundColor Cyan
