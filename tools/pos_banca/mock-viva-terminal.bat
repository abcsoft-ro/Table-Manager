@echo off
setlocal
rem Simulator Viva.com ECR Protocol (POS bancar, TCP) pentru testarea
rem platii / verificarii / anularii, fara un terminal real.
rem Necesita mediul virtual al aplicatiei (biblioteca standard Python).
set "PY=%~dp0..\..\.venv\Scripts\python.exe"
if not exist "%PY%" (
    echo Eroare: lipseste mediul virtual al aplicatiei: "%PY%"
    echo Rulati setup-venv.bat in radacina aplicatiei.
    pause
    exit /b 1
)

rem Exemple:
rem   mock-viva-terminal.bat --mode ok --port 8090
rem   mock-viva-terminal.bat --mode accept-silent --journal-file jurnal_viva.json
rem   mock-viva-terminal.bat --mode test_general --once
"%PY%" "%~dp0mock_viva_terminal.py" %*
pause
