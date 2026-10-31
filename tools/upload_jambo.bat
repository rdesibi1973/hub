@echo off
rem Uploads the Jambo PDFs to the website (www.savannahexplorers.com/pdf/), backing up the old ones on the server.
rem Source: C:\Dropbox\SALES TEAM - info for customers\Jambo PDF - all languages
setlocal
set KEY=%USERPROFILE%\.ssh\bluehost_hub
set H=savannp5@box2233.bluehost.com
set SRC=C:\Dropbox\SALES TEAM - info for customers\Jambo PDF - all languages
set TOOLS=%~dp0
echo 1/3 Creating temp folder on the server...
ssh -i "%KEY%" -p 2222 %H% "mkdir -p jambo_upload"
if errorlevel 1 goto err
echo 2/3 Uploading the PDFs...
scp -i "%KEY%" -P 2222 "%SRC%\Jambo da Savannah Explorers IT.pdf" "%SRC%\Jambo from Savannah Explorers.pdf" "%SRC%\Jambo da Savannah Explorers FR.pdf" "%SRC%\Jambo da Savannah Explorers SPA.pdf" "%SRC%\Jambo da Savannah Explorers KENYA IT.pdf" "%SRC%\Jambo from Savannah Explorers KENYA.pdf" "%TOOLS%upload_jambo_install.sh" %H%:jambo_upload/
if errorlevel 1 goto err
echo 3/3 Installing into the website pdf folder...
ssh -i "%KEY%" -p 2222 %H% "bash jambo_upload/upload_jambo_install.sh"
if errorlevel 1 goto err
echo.
echo Done.
pause
exit /b 0
:err
echo.
echo ERROR: upload not completed. If BlueHost refuses the connection, wait a minute and run it again.
pause
exit /b 1
