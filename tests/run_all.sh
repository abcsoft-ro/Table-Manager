#!/bin/bash
# Ruleaza toate fazele de test din WSL (php.exe / node.exe in PATH).
cd "$(dirname "$0")/.." || exit 1
echo "################ LINT ################"; php.exe tests/lint.php
echo "################ FAZA A - static ################"; php.exe tests/static.php
echo "################ FAZA B - invariante DB ################"; php.exe tests/invariants.php
echo "################ FAZA C - API / securitate ################"; php.exe tests/api.php
echo "################ FAZA E - servicii ################"; php.exe tests/services.php
echo "################ FAZA D - fluxuri (scrie in DB!) ################"; php.exe tests/flows.php
echo "################ GATA ################"
