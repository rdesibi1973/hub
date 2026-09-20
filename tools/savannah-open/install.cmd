@echo off
REM Install the "savannah://" open-folder handler for the current user (no admin).
REM Pure batch + reg.exe + wscript (no PowerShell) to avoid antivirus heuristics.
setlocal
set "DEST=%LOCALAPPDATA%\SavannahTools"
if not exist "%DEST%" mkdir "%DEST%"
copy /Y "%~dp0savannah-open.js" "%DEST%\savannah-open.js" >nul
del /q "%DEST%\savannah-open.ps1" 2>nul

set "WS=%SystemRoot%\System32\wscript.exe"

reg add "HKCU\Software\Classes\savannah" /ve /d "URL:Savannah Protocol" /f >nul
reg add "HKCU\Software\Classes\savannah" /v "URL Protocol" /d "" /f >nul
reg add "HKCU\Software\Classes\savannah\shell\open\command" /ve /d "\"%WS%\" \"%DEST%\savannah-open.js\" \"%%1\"" /f >nul

echo.
echo Savannah "open folder" handler installed for the current user.
echo Launcher: %DEST%\savannah-open.js
echo.
echo Test: paste this into your browser address bar and press Enter:
echo     savannah://open?path=001_Safari
echo.
if defined DROPBOX_HOME (echo DROPBOX_HOME = %DROPBOX_HOME%) else (echo WARNING: DROPBOX_HOME is not set on this PC.)
echo.
pause
