@echo off
REM Install the "savannah://" open-folder handler for the current user (no admin).
REM Pure batch + reg.exe + wscript (no PowerShell) to avoid antivirus heuristics.
REM System tools are called by full path: some PCs have a PATH without System32.
setlocal
set "DEST=%LOCALAPPDATA%\SavannahTools"
set "REG=%SystemRoot%\System32\reg.exe"
set "WS=%SystemRoot%\System32\wscript.exe"
set "KEY=HKCU\Software\Classes\savannah"

if not exist "%REG%" (
  echo ERROR: %REG% not found - cannot write the registry.
  goto :fail
)
if not exist "%WS%" (
  echo ERROR: %WS% not found - Windows Script Host is missing.
  goto :fail
)

if not exist "%DEST%" mkdir "%DEST%"
copy /Y "%~dp0savannah-open.js" "%DEST%\savannah-open.js" >nul || (echo ERROR: cannot copy savannah-open.js to %DEST% & goto :fail)
del /q "%DEST%\savannah-open.ps1" 2>nul

"%REG%" add "%KEY%" /ve /d "URL:Savannah Protocol" /f >nul || goto :regfail
"%REG%" add "%KEY%" /v "URL Protocol" /d "" /f >nul || goto :regfail
"%REG%" add "%KEY%\shell\open\command" /ve /d "\"%WS%\" \"%DEST%\savannah-open.js\" \"%%1\"" /f >nul || goto :regfail

REM Verify the handler is really registered.
"%REG%" query "%KEY%\shell\open\command" /ve >nul 2>nul || goto :regfail

echo.
echo OK - Savannah "open folder" handler installed for the current user.
echo Launcher: %DEST%\savannah-open.js
echo.
echo Test: paste this into your browser address bar and press Enter:
echo     savannah://open?path=001_Safari
echo.
if defined DROPBOX_HOME (echo DROPBOX_HOME = %DROPBOX_HOME%) else (echo WARNING: DROPBOX_HOME is not set on this PC - Open will not work until it is.)
echo.
pause
exit /b 0

:regfail
echo ERROR: writing the registry key %KEY% failed.
:fail
echo.
echo Installation NOT completed.
echo.
pause
exit /b 1
