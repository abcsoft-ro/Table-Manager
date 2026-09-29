@echo off
setlocal
cd /d "%~dp0"

rem Creeaza mediul virtual Python .venv (in radacina proiectului) si instaleaza
rem dependintele din requirements.txt. Se ruleaza o singura data (sau dupa
rem reinstalarea Python-ului). Toate scripturile Python ale aplicatiei folosesc
rem acest mediu virtual.

set "VENV=%~dp0.venv"
set "PY=%VENV%\Scripts\python.exe"

if exist "%PY%" goto install

set "BASEPY="
if exist "%LOCALAPPDATA%\Programs\Python\Python312\python.exe" set "BASEPY=%LOCALAPPDATA%\Programs\Python\Python312\python.exe"
if not defined BASEPY if exist "%USERPROFILE%\AppData\Local\Programs\Python\Python312\python.exe" set "BASEPY=%USERPROFILE%\AppData\Local\Programs\Python\Python312\python.exe"
if not defined BASEPY (py -3 --version >nul 2>nul && set "BASEPY=py -3")
if not defined BASEPY set "BASEPY=python"

echo Creare mediu virtual in "%VENV%" ...
%BASEPY% -m venv "%VENV%"
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

echo.
echo Mediul virtual este pregatit: "%VENV%"
pause
