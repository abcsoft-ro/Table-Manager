#!/bin/bash
# Ruleaza toate fazele de test din WSL (php.exe / node.exe in PATH).
#
# Se ruleaza pe masina de DEZVOLTARE: fazele D si F scriu in baza `Rual`
# (cu curatare la final). Iese cu cod 0 doar daca TOATE fazele au trecut.
cd "$(dirname "$0")/.." || exit 1

FAIL=0
phase() {
  local label="$1"; shift
  echo "################ $label ################"
  "$@"
  if [ $? -ne 0 ]; then FAIL=1; fi
}

phase "LINT" php.exe tests/lint.php
phase "FAZA JS - client (i18n)" php.exe tests/js.php
phase "FAZA A - static" php.exe tests/static.php
phase "FAZA B - invariante DB" php.exe tests/invariants.php
phase "FAZA C - API / securitate" php.exe tests/api.php
phase "FAZA G - inchidere aplicatie fara baza de date" php.exe tests/parola_local.php
phase "FAZA E - servicii" php.exe tests/services.php
phase "FAZA D - fluxuri (scrie in DB!)" php.exe tests/flows.php
phase "FAZA F - asteptare casa de marcat (scrie in DB!)" php.exe tests/fiscal_wait.php

echo "################ GATA ################"
if [ "$FAIL" -ne 0 ]; then
  echo "REZULTAT: exista faze cu esecuri (cauta [FAIL] mai sus)."
  exit 1
fi
echo "REZULTAT: toate fazele au trecut."
exit 0
