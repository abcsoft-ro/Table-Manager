@echo off
setlocal
rem Driver POS bancar Viva.com (ECR Protocol TCP/IP).
rem Apelat de api/pos_banca.php cu argumentele: <suma> <txId>.
rem Copiati acest fisier (eventual ca vanzare.bat), viva_client.py si
rem env_config.py in folderul driverului, langa fisierul .env cu ECR_HOST/ECR_PORT.
set "HERE=%~dp0"
where python >nul 2>&1
if errorlevel 1 (
    echo Eroare: Python nu este disponibil in PATH. Porniti aplicatia din .venv ^(setup-venv.bat^).
    exit /b 1
)
if not exist "%HERE%viva_client.py" (
    echo Eroare: lipseste viva_client.py langa acest fisier.
    exit /b 1
)
set "OUT=%HERE%rezultat_ecr.txt"
if defined POS_BANCA_RESULT set "OUT=%POS_BANCA_RESULT%"
python "%HERE%viva_client.py" sale --amount "%~1" --tx "%~2" --out "%OUT%"
