@echo off
REM Double-click to install the "savannah://" open-folder handler (current user, no admin).
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install.ps1"
echo.
pause
