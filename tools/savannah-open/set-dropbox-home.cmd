@echo off
REM Check / set the DROPBOX_HOME environment variable for the current user (no admin).
REM DROPBOX_HOME = the Dropbox root folder, i.e. the one that contains 001_Safari.
REM Pure batch + reg.exe + setx.exe (no PowerShell), same as install.cmd.
REM   set-dropbox-home.cmd            interactive: show, verify, offer to change
REM   set-dropbox-home.cmd /install   called by install.cmd: silent if already OK, no pause
setlocal
set "REG=%SystemRoot%\System32\reg.exe"
set "SETX=%SystemRoot%\System32\setx.exe"
set "MARK=001_Safari"
set "MODE=%~1"

if not exist "%SETX%" (
  echo ERROR: %SETX% not found - cannot save the variable.
  goto :fail
)

REM Current value: this console's environment, else the saved user variable
REM (it may have been set after this console was opened).
set "CUR=%DROPBOX_HOME%"
if not defined CUR call :readreg
if not defined CUR goto :notset

echo DROPBOX_HOME = %CUR%
if not exist "%CUR%\%MARK%\" goto :badcur
echo OK - it contains %MARK%.
if /i "%MODE%"=="/install" goto :ok
set "ANS="
set /p "ANS=Change it? [y/N] "
if /i not "%ANS%"=="y" goto :ok
goto :detect

:badcur
echo WARNING: "%CUR%\%MARK%" not found - the value looks wrong.
goto :detect

:notset
echo DROPBOX_HOME is not set on this PC.

:detect
REM Look for a folder containing 001_Safari: above this script (when run from
REM Dropbox), the path in Dropbox's info.json, then the usual locations.
set "CAND="
call :walkup "%~dp0."
if not defined CAND call :fromjson "%LOCALAPPDATA%\Dropbox\info.json"
if not defined CAND call :fromjson "%APPDATA%\Dropbox\info.json"
if not defined CAND call :try "%USERPROFILE%\Dropbox"
if not defined CAND call :try "C:\Dropbox"
if not defined CAND call :try "D:\Dropbox"
if not defined CAND goto :ask

echo.
echo Found Dropbox folder: %CAND%
set "ANS="
set /p "ANS=Set DROPBOX_HOME to this folder? [Y/n] "
if /i "%ANS%"=="n" goto :ask
set "NEW=%CAND%"
goto :save

:ask
echo.
echo Type the Dropbox folder that contains %MARK% (e.g. C:\Dropbox),
echo or press Enter to cancel:
set "NEW="
set /p "NEW=> "
if not defined NEW goto :cancel
set "NEW=%NEW:"=%"
if "%NEW:~-1%"=="\" set "NEW=%NEW:~0,-1%"
if exist "%NEW%\%MARK%\" goto :save
echo "%NEW%\%MARK%" does not exist - try again.
goto :ask

:save
"%SETX%" DROPBOX_HOME "%NEW%" >nul || goto :setxfail
echo.
echo OK - DROPBOX_HOME = %NEW%  (saved for this Windows user)
echo Close ALL browser windows and reopen the browser so it sees the new value.
if /i not "%MODE%"=="/install" pause
endlocal & set "DROPBOX_HOME=%NEW%"
exit /b 0

:ok
if /i not "%MODE%"=="/install" pause
exit /b 0

:setxfail
echo ERROR: setx failed - DROPBOX_HOME was NOT saved.
goto :fail
:cancel
echo Cancelled - DROPBOX_HOME was NOT changed.
:fail
if /i not "%MODE%"=="/install" pause
exit /b 1

REM ---- subroutines ----

:readreg
for /f "skip=2 tokens=2,*" %%A in ('"%REG%" query HKCU\Environment /v DROPBOX_HOME 2^>nul') do set "CUR=%%B"
goto :eof

:walkup
set "W=%~f1"
:walk_loop
if exist "%W%\%MARK%\" goto :walk_found
for %%I in ("%W%\..") do set "UP=%%~fI"
if /i "%UP%"=="%W%" goto :eof
set "W=%UP%"
goto :walk_loop
:walk_found
set "CAND=%W%"
goto :eof

:fromjson
REM info.json looks like {"personal": {"path": "C:\\Dropbox", ...}} - 6th "-delimited token.
if not exist "%~1" goto :eof
set "J="
for /f usebackq^ tokens^=6^ delims^=^" %%P in ("%~1") do set "J=%%P"
if not defined J goto :eof
set "J=%J:\\=\%"
call :try "%J%"
goto :eof

:try
if exist "%~1\%MARK%\" set "CAND=%~1"
goto :eof
