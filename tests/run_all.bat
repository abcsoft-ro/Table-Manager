@echo off
REM Ruleaza toate fazele de test. Necesita php si node in PATH (vezi AGENTS.md).
setlocal
cd /d "%~dp0.."
echo ################ LINT ################
php tests\lint.php
echo ################ FAZA A - static ################
php tests\static.php
echo ################ FAZA B - invariante DB ################
php tests\invariants.php
echo ################ FAZA C - API / securitate ################
php tests\api.php
echo ################ FAZA E - servicii ################
php tests\services.php
echo ################ FAZA D - fluxuri (scrie in DB!) ################
php tests\flows.php
echo ################ GATA ################
endlocal
