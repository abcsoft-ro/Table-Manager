@echo off
setlocal
cd /d "%~dp0"

rem Argumente primite de la aplicatia POS-ECR (SendtoPoS):
rem   %1 = suma (ex. 10.00)   %2 = id tranzactie (ex. TX20260923173712)
set "SUMA=%~1"
set "TRX=%~2"
set "OUT=%~dp0rezultat_ecr.txt"
set "SCRIPT=%~dp0ecr_client.py"

rem Alege interpretorul Python. Preferam "python" din PATH (aplicatia POS il
rem pune primul: .venv\Scripts, cu pyserial). "py" doar ca rezerva, fiindca pe
rem unele masini exista un py.exe global fara Python inregistrat
rem "No installed Python found!".
set "PY=python"
where python >nul 2>&1 || (
    set "PY=py"
    where py >nul 2>&1 || set "PY=python"
)

if "%TRX%"=="" (
    "%PY%" "%SCRIPT%" sale "%SUMA%" --out "%OUT%"
) else (
    "%PY%" "%SCRIPT%" sale "%SUMA%" --uid "%TRX%" --out "%OUT%"
)
set "RC=%errorlevel%"

rem Daca scriptul nu a putut rula deloc, scrie un rezultat de eroare explicit.
if not "%RC%"=="0" if not exist "%OUT%" (
    >"%OUT%" echo STATUS=ERROR
    >>"%OUT%" echo EMITE_BON=NU
    >>"%OUT%" echo MESAJ=Eroare la lansarea PoS. Interpreter=%PY%, cod=%RC%. Verificati Python/pyserial.
    >>"%OUT%" echo SUMA=%SUMA%
    >>"%OUT%" echo TRX_ID=%TRX%
    >>"%OUT%" echo RRN=
    >>"%OUT%" echo STAN=
)

endlocal
