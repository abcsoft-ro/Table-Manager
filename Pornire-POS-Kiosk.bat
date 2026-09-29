@echo off
setlocal
title TableManager POS - Kiosk

rem Porneste TableManager in modul kiosc (fullscreen), intr-un profil dedicat.
rem Se poate pune un shortcut in shell:startup pentru pornire automata.

set "URL=https://127.0.0.1/rual/"
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

rem Import produse/grupe de pe server (ruleaza doar cand tblSet.Server = 1,
rem doar daca nu exista linii in tblNoteD). Se lanseaza in fundal, ca sa nu
rem intarzie pornirea aplicatiei.
set "IMPORT_SCRIPT=%~dp0import_server_grp_prod.py"
if not exist "%IMPORT_SCRIPT%" set "IMPORT_SCRIPT=C:\Apache24\htdocs\rual\import_server_grp_prod.py"

set "PYEXE="
if exist "%LOCALAPPDATA%\Programs\Python\Python312\python.exe" set "PYEXE=%LOCALAPPDATA%\Programs\Python\Python312\python.exe"
if not defined PYEXE if exist "%USERPROFILE%\AppData\Local\Programs\Python\Python312\python.exe" set "PYEXE=%USERPROFILE%\AppData\Local\Programs\Python\Python312\python.exe"
if not defined PYEXE set "PYEXE=python"

rem Ce face importul daca tblNoteD are linii (se poate schimba aici):
rem   --if-notes=run   executa ImportProd oricum (implicit, ca in aplicatia veche)
rem   --if-notes=skip  sare peste import daca sunt linii (se ruleaza dupa Z)
set "IMPORT_ARGS=--if-notes=run"
if exist "%IMPORT_SCRIPT%" start "TableManager Import" /min "%PYEXE%" "%IMPORT_SCRIPT%" %IMPORT_ARGS%

start "" "%BROWSER%" --kiosk "%URL%" --user-data-dir="%PROFILE%" --no-first-run --no-default-browser-check --disable-session-crashed-bubble --disable-infobars --disable-features=TranslateUI --ignore-certificate-errors --test-type --autoplay-policy=no-user-gesture-required

endlocal
