# tests/ — harness de test TableManager 2.1.3

Scripturi CLI (rulează cu `php.exe`, vezi `AGENTS.md`). **Nu se servesc prin web**
(`tests/.htaccess` → `Require all denied`).

## Rulare

```bash
bash tests/run_all.sh        # WSL (php.exe / node.exe in PATH)
tests\run_all.bat            # Windows
```

sau individual:

```
php tests/lint.php        # sintaxa PHP + JS (echivalentul pasilor din AGENTS.md)
php tests/js.php          # Faza JS1 - logica de client (js/i18n.js) rulata in Node
php tests/js_bankpos.php  # Faza JS2 - POS bancar: fluxul de client (js/pos.js) in Node
php tests/static.php      # Faza A - contract static, i18n, OpenAPI, DOM
php tests/invariants.php  # Faza B - invariante DB (DOAR CITIRE)
php tests/api.php         # Faza C - oracle API, validari negative, securitate, API v1
php tests/pos_banca.php   # Faza C2 - POS bancar: status / manual / resync / protocol (scrie si curata in DB)
php tests/viva_ecr.php    # Faza C3 - driverul Viva ECR: framing, vanzare/refuz/anulare/UNKNOWN contra simulatorului (fara DB)
php tests/parola_local.php # Faza G - inchiderea aplicatiei fara baza de date (copia locala a parolei)
php tests/services.php    # Faza E - print/sync service, coada, emulator, retry
php tests/flows.php       # Faza D - fluxuri business (SCRIE in DB, cu curatare)
php tests/fiscal_wait.php # Faza F - asteptarea raspunsului casei de marcat (SCRIE in DB, cu curatare)
```

## Reguli de siguranta

**Harness-ul se ruleaza pe masina de dezvoltare.** Fazele D si F scriu in baza
`Rual` (cu curatare completa la final); pe statii nu se instaleaza deloc.

1. **Faza D scrie in baza `Rual`** (mesele 80 si 79) si curata tot la final.
   **Faza C2** scrie doar in `tblPosBancaLog` (DocID de test 999990, sters la final) si schimba temporar `CaleDriverPoSbanca` / `ProtocolPoSbanca`, cu restaurare
   si schimba temporar `tblSet.CaleDriverPoSbanca` catre un driver de test izolat
   (restaurat la final); nu atinge notele. **Faza C3** nu atinge baza deloc:
   ruleaza driverul real `viva_client.py` contra simulatorului
   `mock_viva_terminal.py` pe porturi locale (18765-18768) si il opreste la
   final; daca `.venv`/Python nu exista, testeaza doar registrul de protocoale
   si sare peste partea de retea (fara esec).
2. Fazele A/B/C/E/G si fazele JS nu distrug date (niciuna nu atinge baza deloc).
   **JS1** ruleaza `js/i18n.js` intr-un Node cu `window` stubuit si catalogurile
   reale trecute in JSON. **JS2** incarca `js/pos.js` REAL intr-un sandbox Node
   (DOM stubuit, `fetch` controlat) si verifica fluxul platii pe card: la
   `UNKNOWN`/`TIMEOUT` verificarea se face automat (fara dialog, fara click-uri),
   o tranzactie gasita aprobata se preia silentios, una inexistenta afiseaza
   „PLATA NEPROCESATA", iar un terminal mut „PLATA NECLARIFICATA" cu Manual.
   Verifica si ca nu pleaca un al doilea `pay` (fara dubla taxare). C salveaza si restaureaza setarile atinse;
   E linisteste worker-ele prin `poll_interval_sec` mare (hot-reload) si
   restaureaza configul (nu opreste serviciile).
   G nu scrie in `tblParola` (testeaza copia locala a parolei si legaturile din
   endpoint-uri) si salveaza/restaureaza `logs/parola_local.json`.
3. E nu imprima fizic: foloseste tinte `preview`/`file` sau o tinta moarta
   (`127.0.0.1:9`).
4. Nu se executa un raport Z real (ar modifica numerotarea contabila);
   se testeaza doar guard-ul.
5. **Faza F scrie in baza `Rual`** (doar masa 80, ca faza D - deci se ruleaza
   dupa ea, niciodata in paralel) si porneste singura mock-ul FiscalWire in
   foldere izolate (`logs/mock_fiscal_test/`, sterse la final), deci nu atinge
   mock-ul real (`logs/mock_fiscal_wire/`) si nici contoarele lui. Pune tintele
   de tiparire pe `preview` in timpul testului si le restaureaza la final, ca sa
   nu atinga nicio imprimanta.
6. **Configul de tiparire nu se pierde daca un run este intrerupt.** D si F muta
   toate tintele pe `preview`, dar inainte de prima modificare scriu o copie a
   configului real in `logs/print_config_before_tests.json`
   (`printConfigBackup()` din `tests/lib.php`); la inceputul urmatorului run D/F
   copia este restaurata automat (`printConfigRestoreBackup()`), deci un run
   omorat (Ctrl+C / timeout) nu mai lasa statia pe `preview`. Restaurarea se face
   si direct in fisier (`printConfigSave()`) daca serviciul de tiparire nu
   raspunde - altfel, cu serviciul cazut, scrierea se pierdea silentios.
   **Atentie:** daca un run este intrerupt, lasa o imprimanta fizica pe `preview`
   pana la urmatorul run - porniti manual un run D/F sau verificati
   Setari → Imprimante.

`run_all.sh` / `run_all.bat` ruleaza toate fazele si ies cu cod non-zero daca
oricare a esuat (cauta `[FAIL]` in iesire).

## Rezultat

`REPORT.md` contine raportul complet (constatari, severitati, dovezi).

## Nota

Fisierele de test nu fac parte din aplicatie si nu ajung pe statiile de productie:
`sync.sh` le exclude din arhiva de instalare (`dev/sync.sh` de cand scriptul de
release s-a mutat in `dev/`), iar `api/update_common.php`
(`updateIgnorePrefixes`) le exclude si la autoactualizare. Nu din grija pentru
datele de pe statii (acolo nu se ruleaza teste), ci ca sa nu impachetam unelte de
dezvoltare in instalarea livrata.
