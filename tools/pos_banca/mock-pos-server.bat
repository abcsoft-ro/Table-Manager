@echo off
setlocal
cd /d "%~dp0"

rem Simulator SmartPay ECR Link (POS bancar, TCP) pentru testarea resync/void.
rem Necesita mediul virtual al aplicatiei (are Python; scriptul foloseste doar
rem biblioteca standard, dar pastram aceeasi regula ca la celelalte servicii).
set "PY=%~dp0..\..\.venv\Scripts\python.exe"
if not exist "%PY%" (
    echo Eroare: lipseste mediul virtual al aplicatiei: "%PY%"
    echo Rulati setup-venv.bat in radacina aplicatiei.
    pause
    exit /b 1
)

rem Exemple:
rem   mock-pos-server.bat --mode ok --port 5070
rem   mock-pos-server.bat --mode disconnect --port 5070 --journal-file jurnal.json
rem   mock-pos-server.bat --mode test_general --port 5070 --journal-file jurnal.json
"%PY%" "%~dp0mock_pos_server.py" %*
pause
