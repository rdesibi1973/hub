@echo off
rem Booking follow-up: read the supplier PDFs on this PC and send them to the Hub.
rem   followup_local_scan.bat              dry run
rem   followup_local_scan.bat --confirm    save
rem Options: see tools\followup_local_scan.php
setlocal
set "PHP=C:\Utility\PHP\php.exe"
rem Load curl/openssl/mbstring only if php.ini does not already (else "Module already loaded" warnings)
set "EXT="
for %%e in (curl openssl mbstring) do (
  "%PHP%" -r "exit(extension_loaded('%%e') ? 0 : 1);" 2>nul || call set "EXT=%%EXT%% -d extension=%%e"
)
"%PHP%" -d extension_dir="C:\Utility\PHP\ext" %EXT% "%~dp0followup_local_scan.php" %*
