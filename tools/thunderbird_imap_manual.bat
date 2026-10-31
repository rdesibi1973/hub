@echo off
rem Thunderbird manual mode for Roberto's PCs (office and home): our boxes are not checked automatically,
rem not even at startup - only when you open them. info@savannahexplorers.com keeps the 10-minute check;
rem Gmail is not touched. Thunderbird must be closed. Back to the normal fix: run thunderbird_imap_fix.bat.
call "%~dp0thunderbird_imap_fix.bat" manual
