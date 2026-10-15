@echo off
REM Porneste mock-ul cantarului electronic pentru testarea citirii cantarului.
REM NU este parte din aplicatia de productie.
REM
REM La fiecare rulare simuleaza o cantarire: dupa 0.5 secunde scrie in fisierul
REM de raspuns un numar aleator intre 0.5 si 1.5 (kg), apoi se inchide. Fisierul
REM este suprascris la fiecare apel. Setati tblSet.CaleFisierRaspunsCantar la
REM fisierul scris (implicit logs\mock_cantar\raspuns_cantar.txt).
REM
REM Exemple:
REM   tools\mock_cantar_electronic.bat
REM   tools\mock_cantar_electronic.bat --min 0.2 --max 2.0 --delay 1
setlocal
cd /d "%~dp0"

rem Mediul virtual Python (obligatoriu), in radacina proiectului.
set "PYEXE=%~dp0..\.venv\Scripts\python.exe"
if not exist "%PYEXE%" (
    echo.
    echo EROARE: nu exista mediul virtual Python "%PYEXE%"
    echo Rulati mai intai setup-venv.bat din radacina proiectului.
    pause
    exit /b 1
)

%PYEXE% mock_cantar_electronic.py %*
