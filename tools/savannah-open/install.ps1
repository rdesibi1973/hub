<#
  install.ps1 — register the "savannah://" protocol for the CURRENT user.
  No admin rights needed (writes to HKCU). Run via install.cmd (double-click).
#>
$ErrorActionPreference = 'Stop'

$dest = Join-Path $env:LOCALAPPDATA 'SavannahTools'
New-Item -ItemType Directory -Force -Path $dest | Out-Null
Copy-Item -Force -Path (Join-Path $PSScriptRoot 'savannah-open.ps1') -Destination (Join-Path $dest 'savannah-open.ps1')

$ps       = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$launcher = Join-Path $dest 'savannah-open.ps1'
$cmd      = "`"$ps`" -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$launcher`" `"%1`""

$base = 'HKCU:\Software\Classes\savannah'
New-Item -Path $base -Force | Out-Null
Set-ItemProperty -Path $base -Name '(default)'    -Value 'URL:Savannah Protocol'
Set-ItemProperty -Path $base -Name 'URL Protocol' -Value ''
New-Item -Path "$base\shell\open\command" -Force | Out-Null
Set-ItemProperty -Path "$base\shell\open\command" -Name '(default)' -Value $cmd

Write-Host ''
Write-Host 'Savannah "open folder" handler installed for the current user.' -ForegroundColor Green
Write-Host "Launcher: $launcher"
Write-Host ''
Write-Host 'Test: paste this into your browser address bar and press Enter:'
Write-Host '    savannah://open?path=001_Safari' -ForegroundColor Cyan
Write-Host '(It should open the 001_Safari folder in Explorer.)'
Write-Host ''
if ([string]::IsNullOrWhiteSpace($env:DROPBOX_HOME)) {
    Write-Host 'WARNING: DROPBOX_HOME is not set on this PC — set it before using the links.' -ForegroundColor Yellow
} else {
    Write-Host ("DROPBOX_HOME = {0}" -f $env:DROPBOX_HOME)
}
