@echo off
REM Double-click to remove the "savannah://" handler and its launcher (current user).
powershell -NoProfile -ExecutionPolicy Bypass -Command "Remove-Item -Path 'HKCU:\Software\Classes\savannah' -Recurse -Force -ErrorAction SilentlyContinue; Remove-Item -Path (Join-Path $env:LOCALAPPDATA 'SavannahTools') -Recurse -Force -ErrorAction SilentlyContinue; Write-Host 'Savannah open-folder handler uninstalled.' -ForegroundColor Green"
echo.
pause
