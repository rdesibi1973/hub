@echo off
REM Remove the "savannah://" handler and its launcher (current user).
reg delete "HKCU\Software\Classes\savannah" /f >nul 2>nul
rmdir /s /q "%LOCALAPPDATA%\SavannahTools" 2>nul
echo Savannah open-folder handler uninstalled.
echo.
pause
