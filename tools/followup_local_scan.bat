@echo off
rem Booking follow-up: read the supplier PDFs on this PC and send them to the Hub.
rem   followup_local_scan.bat              dry run
rem   followup_local_scan.bat --confirm    save
rem Options: see tools\followup_local_scan.php
"C:\Utility\PHP\php.exe" -d extension_dir="C:\Utility\PHP\ext" -d extension=curl -d extension=openssl -d extension=mbstring "%~dp0followup_local_scan.php" %*
