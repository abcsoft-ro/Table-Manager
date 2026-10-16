@echo off
REM Ruleaza toate fazele de test. Necesita php si node in PATH (vezi AGENTS.md).
REM Se ruleaza pe masina de DEZVOLTARE: fazele D si F scriu in baza Rual
REM (cu curatare la final). Iese cu cod 0 doar daca TOATE fazele au trecut.
setlocal
cd /d "%~dp0.."
set FAIL=0

echo ################ LINT ################
php tests\lint.php
if errorlevel 1 set FAIL=1

echo ################ FAZA JS - client (i18n) ################
php tests\js.php
if errorlevel 1 set FAIL=1

echo ################ FAZA A - static ################
php tests\static.php
if errorlevel 1 set FAIL=1

echo ################ FAZA B - invariante DB ################
php tests\invariants.php
if errorlevel 1 set FAIL=1

echo ################ FAZA C - API / securitate ################
php tests\api.php
if errorlevel 1 set FAIL=1

echo ################ FAZA E - servicii ################
php tests\services.php
if errorlevel 1 set FAIL=1

echo ################ FAZA D - fluxuri (scrie in DB!) ################
php tests\flows.php
if errorlevel 1 set FAIL=1

echo ################ FAZA F - asteptare casa de marcat (scrie in DB!) ################
php tests\fiscal_wait.php
if errorlevel 1 set FAIL=1

echo ################ GATA ################
if "%FAIL%"=="1" (
  echo REZULTAT: exista faze cu esecuri - cauta [FAIL] mai sus.
  endlocal
  exit /b 1
)
echo REZULTAT: toate fazele au trecut.
endlocal
exit /b 0
