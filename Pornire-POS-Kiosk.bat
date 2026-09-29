@echo off
setlocal
title TableManager POS - Kiosk

rem Porneste TableManager in modul kiosc (fullscreen), intr-un profil dedicat.
rem Aplicatia ruleaza pe HTTP (fara certificat). Se poate pune un shortcut in
rem shell:startup pentru pornire automata. Pentru fara fereastra DOS, folositi
rem Pornire-POS-Kiosk.vbs.

rem Folderul aplicatiei = folderul acestui bat (proiectul e direct sub htdocs).
rem URL-ul se compune din numele folderului, deci merge si daca il redenumesti.
set "APPDIR=%~dp0"
for %%I in ("%APPDIR%.") do set "APPFOLDER=%%~nxI"

set "URL=http://127.0.0.1/%APPFOLDER%/"
set "PROFILE=%LOCALAPPDATA%\TableManagerKiosk"

set "BROWSER="
if exist "%ProgramFiles%\Google\Chrome\Application\chrome.exe" set "BROWSER=%ProgramFiles%\Google\Chrome\Application\chrome.exe"
if not defined BROWSER if exist "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" set "BROWSER=%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe"
if not defined BROWSER if exist "%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" set "BROWSER=%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe"
if not defined BROWSER if exist "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe" set "BROWSER=%ProgramFiles%\Microsoft\Edge\Application\msedge.exe"

if not defined BROWSER (
    echo Nu am gasit Google Chrome sau Microsoft Edge.
    echo Instalati unul dintre ele sau editati acest fisier cu calea browserului.
    pause
    exit /b 1
)

rem Mediul virtual Python (obligatoriu). Daca lipseste, kiosc-ul nu porneste.
set "PYEXE=%APPDIR%.venv\Scripts\python.exe"
if not exist "%PYEXE%" (
    echo.
    echo EROARE: nu exista mediul virtual Python .venv in "%APPDIR%".
    echo Rulati mai intai setup-venv.bat din radacina proiectului.
    pause
    exit /b 1
)

rem Import produse/grupe de pe server (ruleaza doar cand tblSet.Server = 1).
rem Cand vine de la Pornire-POS-Kiosk.vbs (--no-import), acesta il lanseaza deja
rem ascuns, deci aici il sarim ca sa nu deschidem o a doua fereastra.
if /I "%~1"=="--no-import" goto after_import

rem Ce face importul daca tblNoteD are linii (se poate schimba aici):
rem   --if-notes=run   executa ImportProd oricum (implicit, ca in aplicatia veche)
rem   --if-notes=skip  sare peste import daca sunt linii (se ruleaza dupa Z)
set "IMPORT_SCRIPT=%APPDIR%import_server_grp_prod.py"
set "IMPORT_ARGS=--if-notes=run"
if exist "%IMPORT_SCRIPT%" start "TableManager Import" /min "%PYEXE%" "%IMPORT_SCRIPT%" %IMPORT_ARGS%
:after_import

start "" "%BROWSER%" --kiosk "%URL%" --user-data-dir="%PROFILE%" --no-first-run --no-default-browser-check --disable-session-crashed-bubble --disable-infobars --disable-features=TranslateUI --autoplay-policy=no-user-gesture-required

endlocal
