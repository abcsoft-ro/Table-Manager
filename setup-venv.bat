@echo off
setlocal
cd /d "%~dp0"

rem Creeaza mediul virtual Python .venv (in radacina proiectului) si instaleaza
rem dependintele din requirements.txt. Se ruleaza o singura data (sau dupa
rem reinstalarea Python-ului). Toate scripturile Python ale aplicatiei folosesc
rem acest mediu virtual.

set "VENV=%~dp0.venv"
set "PY=%VENV%\Scripts\python.exe"

rem Daca .venv exista dar a fost creat de ALT CONT Windows, python.exe din el
rem este un redirector care cauta interpretorul de baza in profilul contului
rem respectiv (ex. "No Python at 'C:\Users\abcsoft\...\Python312\python.exe'").
rem Pentru contul curent acel folder e inaccesibil, deci recreem mediul virtual.
if exist "%PY%" (
    "%PY%" -c "import sys" >nul 2>nul
    if errorlevel 1 (
        echo Mediul virtual existent nu poate fi folosit de contul curent - il recreez ...
        rmdir /s /q "%VENV%"
        if exist "%PY%" (
            echo.
            echo EROARE: nu am putut sterge "%VENV%".
            echo Opriti serviciile Python din Setari - Servicii si stergeti folderul manual.
            pause
            exit /b 1
        )
    )
)

if exist "%PY%" goto install

rem Instalarile pentru TOTI utilizatorii au prioritate: .venv este comun pentru
rem toate conturile statiei (cont de service + cont de utilizator POS), iar un
rem Python per-user este accesibil doar din contul care l-a instalat.
set "BASEPY="
if exist "%ProgramFiles%\Python312\python.exe" set "BASEPY=%ProgramFiles%\Python312\python.exe"
if not defined BASEPY if exist "C:\Python312\python.exe" set "BASEPY=C:\Python312\python.exe"
if not defined BASEPY if exist "%LOCALAPPDATA%\Programs\Python\Python312\python.exe" set "BASEPY=%LOCALAPPDATA%\Programs\Python\Python312\python.exe"
if not defined BASEPY if exist "%USERPROFILE%\AppData\Local\Programs\Python\Python312\python.exe" set "BASEPY=%USERPROFILE%\AppData\Local\Programs\Python\Python312\python.exe"

echo Creare mediu virtual in "%VENV%" ...
if defined BASEPY (
    rem cale completa (poate contine spatii, ex. "C:\Program Files\...")
    "%BASEPY%" -m venv "%VENV%"
) else (
    rem launcher-ul py / python din PATH
    py -3 -m venv "%VENV%"
    if errorlevel 1 python -m venv "%VENV%"
)
if errorlevel 1 (
    echo.
    echo EROARE: nu am putut crea mediul virtual. Verificati ca Python 3.12 este instalat.
    pause
    exit /b 1
)

:install
echo Actualizare pip ...
"%PY%" -m pip install --upgrade pip

echo Instalare dependinte din requirements.txt ...
"%PY%" -m pip install -r "%~dp0requirements.txt"
if errorlevel 1 (
    echo.
    echo EROARE: instalarea dependintelor a esuat.
    pause
    exit /b 1
)

rem Shortcut-uri: serviciile in Startup (pornire automata) + lansatorul POS pe Desktop.
echo.
echo Creare shortcut-uri (Startup + Desktop) ...
cscript //B //Nologo "%~dp0tools\create-shortcuts.vbs"

echo.
echo Mediul virtual este pregatit: "%VENV%"
pause
