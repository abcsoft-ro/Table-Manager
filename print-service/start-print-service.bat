@echo off
REM Porneste serviciul de tiparire TableManager.
REM Se poate pune un shortcut in shell:startup pentru pornire automata.
setlocal
cd /d "%~dp0"

if not exist "logs" mkdir "logs"
if not exist "preview" mkdir "preview"
if not exist "spool\fiscal" mkdir "spool\fiscal"

rem Mediul virtual Python (obligatoriu), in radacina proiectului.
set "PYEXE=%~dp0..\.venv\Scripts\python.exe"
if not exist "%PYEXE%" (
    echo.
    echo EROARE: nu exista mediul virtual Python "%PYEXE%"
    echo Rulati mai intai setup-venv.bat din radacina proiectului.
    pause
    exit /b 1
)

echo Pornire serviciu tiparire cu: %PYEXE%
%PYEXE% server.py

echo.
echo Serviciul s-a oprit.
pause
