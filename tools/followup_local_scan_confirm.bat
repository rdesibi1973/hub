@echo off
rem Booking follow-up: legge i PDF fornitori sul PC e SALVA sul Hub (--confirm). Doppio clic per lanciarlo.
rem Per la sola prova usa followup_local_scan.bat senza opzioni.
title Follow-up documenti fornitori (salvataggio)
call "%~dp0followup_local_scan.bat" --confirm
echo.
if errorlevel 1 (echo *** ERRORE: controlla i messaggi qui sopra ***) else (echo Fatto.)
pause
