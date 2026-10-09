@echo off
REM Porneste mock-ul FiscalWire pentru testarea asteptarii raspunsului casei de
REM marcat (AsteaptaRaspunsCasa = 1). NU este parte din aplicatia de productie.
REM
REM Exemple:
REM   tools\mock_fiscalwire.bat --mode ok
REM   tools\mock_fiscalwire.bat --mode err
REM   tools\mock_fiscalwire.bat --mode canceled
REM   tools\mock_fiscalwire.bat --mode silent
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

echo Pornire mock FiscalWire cu: %PYEXE%
%PYEXE% mock_fiscalwire.py %*

echo.
Mock-ul s-a oprit.
pause
