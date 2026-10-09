@echo off
REM Porneste mock-ul FiscalNet pentru testarea asteptarii raspunsului casei de
REM marcat (AsteaptaRaspunsCasa = 1, TipCasaMarcat = FiscalNet). NU este parte
REM din aplicatia de productie.
REM
REM Exemple:
REM   tools\mock_fiscalnet.bat --mode ok
REM   tools\mock_fiscalnet.bat --mode err
REM   tools\mock_fiscalnet.bat --mode pos
REM   tools\mock_fiscalnet.bat --mode silent
REM   tools\mock_fiscalnet.bat --mode random --err-prob 0.3
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

echo Pornire mock FiscalNet cu: %PYEXE%
%PYEXE% mock_fiscalnet.py %*

echo.
Mock-ul s-a oprit.
pause
