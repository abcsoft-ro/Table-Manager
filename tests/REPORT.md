# Raport de test — TableManager 2.0.0

- **Data:** 2026-10-16
- **Mediu:** Apache `http://127.0.0.1/rual` (host `FLORIAN-PC`), MSSQL `Rual` pe `FUJITSU-PC\SQLEXPRESS` (remote), PHP 8.3.7, Node 24.11.1
- **Cod testat:** commit `a6cbbe7` (`VERSION` 2.0.0, branch `table_manager_ro_en`, +1 commit față de `main`), cu 1 fișier untracked (`docs/MERGE-BILINGV.md`)
- **Servicii:** print-service `:8756` (pid 136), sync-service `:8757` (pid 7080) — pornite
- **Config activ:** `Limba=en`, `TipVanz=1` (FastFood), `Server=1`, `RED=1`, `TipDiscount=1`, `NrZecCant=2`, `CantMax=100`, `PoSbanca=1`, `Cantar=1`, `AsteaptaRaspunsCasa=0`, `ApiEnabled=0`

## Rezumat

| Fază | Rezultat | Observații |
|---|---|---|
| Lint (51 PHP + 4 JS) | **55/55 pass** | fără erori de sintaxă |
| A. Static / contract | 12/15 | 3 eșecuri = probleme i18n reale |
| B. Invariante DB (read-only) | **23/23 pass** | baza e consistentă |
| C. API / securitate | 43/50 | 2 bug-uri API v1 + 5 fișiere expuse |
| E. Servicii Python | **26/26 pass** | coadă, emulator, retry, hot-reload |
| D. Fluxuri business (scriere) | **54/54 pass** | bon complet + concurență |
| **Total aserțiuni** | **158/168 pass** | 10 eșecuri = 4 majore + minore/info |

**Backup înainte de testare:** `BACKUP DATABASE Rual` manual (25,4 MB) în `C:\ABCSoft\Data\backup\Rual_manual_20261016_040136.bak` (verificat în `msdb.dbo.backupset`). Tool-ul proiectului `tools/backup-db.ps1` **nu funcționează** (vezi B-01).

**Curățare:** toate artefactele de test au fost șterse; starea DB și configul de tipărire au fost restaurate (verificat la finalul fazei D și E).

---

## Constatări

### [B-01] Blocant — backup-ul automat de bază de date nu rulează niciodată
- **Zonă:** `tools/backup-db.ps1`, funcția `Test-LocalServer` (linia 97)
- **Reproducere:** `powershell -ExecutionPolicy Bypass -File tools\backup-db.ps1`
- **Output:**
  ```
  [2026-10-16 04:01:32] Folder creat: C:\ABCSoft\Data\backup
  [2026-10-16 04:01:32] rezultat=eroare detaliu="Cannot overwrite variable Host because it is read-only or constant."
  ```
- **Cauză:** `$host = $env:COMPUTERNAME...` — `$Host` este o variabilă automată **read-only** în PowerShell. Cu `Set-StrictMode` + `ErrorActionPreference='Stop'`, scriptul aruncă imediat.
- **Impact:** task-ul programat `tools/backup-db-task.xml` (zilnic 03:00, ca SYSTEM) eșuează silent — nu există backup automat. `logs/backup_db.log` nu se scrie.
- **Fix sugerat:** redenumește variabila (ex. `$hostName`). **Suplimentar:** baza de date e pe altă mașină (`FUJITSU-PC`) decât aplicația (`FLORIAN-PC`); `Ensure-BackupDir` creează folderul local (`New-Item`) dar `BACKUP DATABASE` scrie pe serverul SQL — calea `C:\ABCSoft\Data\backup` trebuie să existe *pe FUJITSU-PC*.

### [B-02] Major (securitate) — `github_api.txt` servit public prin HTTP, conține un token
- **Reproducere:** `curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1/rual/github_api.txt` → **200**, 79 bytes
- **Conținut (redactat):** linia 1 `https://[redactat].com/[redactat]-ro`, linia 2 `token:[redactat]`
- **Impact:** tokenul GitHub (chiar dacă „doar ridică limita de apeluri") e accesibil oricui ajunge pe LAN/HTTP. `.htaccess`-ul rădăcină blochează doar `.venv`, `.git`, `logs`.
- **Fix:** adaugă `github_api.txt` (și `print-service/`, `sync-service/`, `db/tools/`, `AGENTS.md`) în `.htaccess`; mută tokenul în afara webroot. **Verifică dacă tokenul e valid și revocă-l dacă da.**

### [B-03] Major — API v1: replay-ul idempotent al unei erori întoarce HTTP 200
- **Zonă:** `api/api_common.php` (idempotency) + `api/v1/index.php`
- **Reproducere** (integrare activată, cheie validă):
  ```
  POST /api/v1/waiters  (corp invalid)  cu același Idempotency-Key
  r1 -> HTTP 400, body {"status":"error", ...}
  r2 -> HTTP 200, body {"status":"error", ...}   <-- codul se pierde
  ```
- **Impact:** un client care se uită la codul HTTP tratează un eșec repetat ca succes. Corpul rămâne `status:error`, deci e inconsistent cu codul.
- **Fix:** la replay, reia și codul HTTP original (salvează-l alături de `ResponseBody`).

### [B-04] Major (i18n) — modulul mobil nu e tradus (27 șiruri)
- **Zonă:** `js/mobile.js` (dialoguri VD, discount, transfer, moduri de preparare, toast-uri)
- **Reproducere:** `php tests/static.php` → *„fiecare literal t()/tr()/trText() are traducere in en"*: 29 lipsă, **27 în `js/mobile.js`**
- **Exemple:** `"Mod preparare"`, `"Discount pe linie"`, `"Discount pe nota"`, `"Transfer produse"`, `"Revoca anularea"`, `"Parola manager"`, `"Motiv anulare"`, `"Masa destinatie"`, `"Nota este goala."`, `"Niciun rezultat."`, mesaje lungi de ajutor VD.
- **Impact:** pe `mobile.php` cu `Limba=en` interfața de marcare rămâne în română. Modulul mobil e parte din feature-ul i18n al branch-ului.
- **Fix:** adaugă cheile în `api/lang/en.php`.

### [B-05] Minor — eroarea SQL din API v1 e mascată („eroare necunoscuta")
- **Zonă:** `api/v1/write.php` (toate handler-ele) + `api/v1/_common.php::apiV1SqlError`
- **Cauză:** `sqlsrv_rollback($conn)` **golește** `sqlsrv_errors()`; `apiV1SqlError()` e apelat *după* rollback.
- **Dovadă:** direct `sqlsrv_errors()` înainte de rollback → *"String or binary data would be truncated"*; după rollback → gol. Răspuns HTTP: `{"message":"Error saving waiter: eroare necunoscuta"}`.
- **Impact:** depanare greoaie pentru consumatorii API.
- **Fix:** capturează mesajul înainte de rollback.

### [B-06] Minor (hardening) — fișiere servite prin web
| URL | Cod | Observație |
|---|---|---|
| `/github_api.txt` | 200 | vezi B-02 |
| `/print-service/config.json` | 200 | configurație imprimante |
| `/sync-service/config.json` | 200 | configurație sync |
| `/AGENTS.md` | 200 | documentație internă |
| `/db/tools/export_schema.php` | 200 | fatal error care expune căi absolute: `Undefined variable $argv ... C:\Apache24\htdocs\rual\db\tools\export_schema.php` |

Corect blocate: `/logs/` 403, `/.git/` 403, `/tests/` 403 (adaugat de test), `/api/db.local.php` 200 dar **fără corp** (nu scurge credențiale).

### [B-07] Minor (i18n) — 17 chei `data-i18n` fără traducere în EN
- `email, website, QRCodeText, ServerIP, ServerName, DataBaseName, UserName, Password, TC (Trusted Connection), ODBC_connect_string, BarCod, Poz (1-50), FontSize, FontType, IRP, IIVCN, Id`
- Impact: în EN rămân afișate ca atare (parțial acceptabil pentru etichete tehnice).

### [B-08] Minor (i18n) — text static românesc fără `data-i18n`
- `Nu` × 7 (butoane Da/Nu din editorele Antet/Produse/Grupe/Mese/Ospatari/Forme de plata) — eticheta rămâne „Nu" până când se deschide editorul (atunci JS o traduce via `tr()`).
- `⬅ INAPOI LA GRUPE` × 1 (text inițial, corectat de JS la încărcare).

### [B-09] Minor — cod mort: `#order-datetime`
- `js/pos.js:660` citește `getElementById("order-datetime")`, dar elementul există ca `id="pay-datetime"`. Data/ora nu se actualizează pe ecranul de marcare (ramura `if (data.dataOra && dtEl)` nu se execută niciodată).

### [B-10] Info — drift documentație
- `AGENTS.md` menționează `index.html` de 6 ori, dar fișierul a fost redenumit `index.php` (rename în `git diff main`). Codul nu mai referă `index.html` (test pass).

### [B-11] Info — mesaj i18n EN inconsistent
- `apiV1RefuseWhenServer`: „**Import of produse** prin API nu este permis: ..." (engleză amestecată cu română).

### [B-12] Info — config inconsistent (casa de marcat)
- `TipCasaMarcat=FiscalWire`, dar `CaleFisierComenziECR` / `CaleFisierRaspunsECR` indică folderele mock **FiscalNet**. Inofensiv cât timp `AsteaptaRaspunsCasa=0`, dar greșit dacă se activează așteptarea.

### [B-13] Info — diverse
- `tblProd` are 1 produs cu `PV NULL/0`.
- `mark_sent` rămas nefolosit de client (legacy documentat).
- La salvarea configului de tipărire, serviciul normalizează căile relative în absolute (`preview_dir`, `fiscal_spool_dir`, `log_file`) și adaugă cheile de cleanup — inofensiv, dar config.json diferă textual de original.

---

## Ce a fost verificat și a trecut (dovadă)

- **Static/contract:** JSON valid (openapi, config.json ×2, update_state); rutele din `docs/openapi.json` au handler în `api/v1/*`; fiecare `getElementById` static există în DOM; fiecare handler inline definit; fiecare `openHelp(k)` are intrare; fiecare `navigateToScreen` valabil; fiecare `action:` client tratat de un endpoint.
- **Invariante DB (23/23):** `TotalB = SUM(Cant*PV)`; convenția modificator (`ProdID=0 ⇒ Cant≤0, PV=PVC=0`); storno (plafon cumulativ, `StornoRef` valid și în același bon); plăți = total pe bon închis; arhivă `tblBon`/`tblNrZ`; referințe KP/TVA/Sectii/Poz unic; cozi fără blocaje; conversie culori COLORREF.
- **API (C):** oracle `menu.php`↔`tblProd.PV`, `get_tables.php`↔bonuri deschise; eroare → mereu JSON; injecție quote și `nrMasa` injectat → 400/200 curat (fără 500); validări negative (prodId 0 → 400, peste `CantMax` → 400, produs inexistent → 404, `authenticate` greșit → nu autentifică, nu se creează bon); API v1 (401 fără cheie, 401 cheie greșită, 200 cu cheie/Bearer, 409 `server_managed` la `Server=1`, import permis la `Server=0`, rate-limit 429, `db.local.php` fără leak); path traversal blocat (`print_preview.php` și `/preview/`).
- **Servicii (E, 26/26):** `/health`/`/config`/`/printers`; emulator (preview HTML cu text de bon); contract coadă print (`claim` → `printing`/`Attempts++`, `fail` → `pending`+backoff respectat, `done`); retry pe țintă moartă (`LastError` populat, rămâne `pending`) apoi **hot-reload** pe preview → `done`; contract coadă export (sync) `claim`/`fail`/`done` cu `Preluat=1`; serviciile au rămas online.
- **Fluxuri (D, 54/54):** bon complet pe masa 80 — `add_product` (linii separate), `add_mod`/`delete_mod` (imbricare corectă), `update_qty` (rotunjire 2 zecimale, `CantMax` 400, blocaj pe `Preluat=1`), discount pe linie (independent, `PV=PVC*0.75`, `Comment` setat) și pe notă (reset + redistribuire, `Comment` NULL), `RED=0` → 403, `TipDiscount=2` → 15% pe baza catalog, VD netrimis (reducere/ștergere) și trimis (storno `Cant<0`, `PV=PVC`), plafon depășit → 400, revocare storno, transfer (linia pe bonul destinație), proformă, `print_kitchen` (un singur `NrBon` per marcare, `Preluat=1`), `close_bill` (Stare=I, plăți, job-uri `nota`+`fiscal`, fără export cu `Server=0`), **concurență: două `close_bill` simultane → un 200 + un 409 și o singură înregistrare de plată**, rapoarte X (General/PLU/Note), Z blocat cu bonuri deschise.
- **UI (headless Chrome):** `index.php` și `mobile.php` se încarcă fără erori JS (doar avertismentul inofensiv `Password field is not contained in a form`); ecranele și DOM-ul se randează; panoul i18n injectat (`window.LANG="en"`).

---

## Zone neacoperite / risc rezidual

1. **Nu s-a executat un raport Z real** (ar modifica numerotarea contabilă pe baza live). S-a testat doar **guard-ul** (`allowed=false` cu bonuri deschise) și existența procedurii. Z-ul complet (arhivare + golire + reset `NrBon`) a rămas neexecutat — se poate rula pe o copie a bazei.
2. **Așteptarea casei de marcat** (`AsteaptaRaspunsCasa=1`) cu mock-urile FiscalWire/FiscalNet nu a fost rulată end-to-end (setarea e 0; ar necesita și corectarea B-12). Logica de cod a fost inspectată, nu exercitată prin flux.
3. **POS bancar** (`PoSbanca=1`, driver `C:\Sunmi\vanzare.bat`) și **cantarul** — neacoperite end-to-end (necesită hardware/driver); mock-urile există în `tools/`.
4. **Imprimare fizică** (ținte `network 192.168.1.121`) — evitată intenționat; s-a testat doar `preview`/`file`/țintă moartă.
5. **UI automatizat pe fluxuri** (click-uri reale pe ecrane) — s-a făcut doar smoke headless + contract static.
6. **Update/rollback** (`api/update.php`) — verificat doar prin cod; nu s-a aplicat un update real.

---

## Anexă — cum se rulează

```bash
# WSL
bash tests/run_all.sh
# Windows
tests\run_all.bat
```

Fazele: lint → A (static) → B (invariante) → C (API) → E (servicii) → D (fluxuri, **scrie în DB**, cu curățare).

**Înainte de faza D faceți backup:** `tools/backup-db.ps1` e stricat (B-01) — folosiți `sqlcmd`:
```
sqlcmd -S FUJITSU-PC -U sa -P <parola> -C -Q "BACKUP DATABASE [Rual] TO DISK=N'...' WITH INIT, CHECKSUM"
```

---

## Anexă — stare după remedieri (2026-10-16, după commit `d8ec69c`)

Nu înlocuiește constatările de mai sus (care descriu commit-ul `a6cbbe7`), ci notează ce s-a reparat între timp.

| Constatare | Stare | Cum s-a rezolvat |
|---|---|---|
| B-01 backup automat | **Reparat** | `$host` → `$hostName` în `Test-LocalServer` (`tools/backup-db.ps1`); `$Host` e variabilă automată read-only. Verificat cu `Parser::ParseFile` (0 erori). |
| B-02 token GitHub expus | **Reparat (cod) / ACȚIUNE** | `github_api.txt` și `ssh.txt` blocate în `.htaccess`. **Tokenul e încă valid** (`GET /user` → 200, scopuri `repo`, `workflow`, `delete:packages`, `write:packages`) — **revocați-l**. |
| B-03 replay idempotent | **Reparat** | `apiIdempotencyLookup()` întoarce și `StatusCode`; `api/v1/index.php` reface codul HTTP la replay. Test C: „replay-ul unei erori pastreaza codul HTTP" PASS. |
| B-04 mobile netradus | **Reparat** | Deja rezolvat de `d8ec69c` (traduceri mobile). Faza A: 15/15. |
| B-05 eroare SQL mascată | **Reparat** | Helper nou `apiV1RollbackWithError()` care citește `sqlsrv_errors()` **înainte** de rollback; 6 apeluri din `api/v1/write.php`. Test C: „500 cu mesaj util" PASS. |
| B-06 fișiere servite prin web | **Reparat** | `.htaccess`: `print-service/`, `sync-service/`, `db/tools/`, `tests/`, `AGENTS.md`, `PLAN-INSTALARE-PRODUCTIE.md`, PDF-urile interne. Test C7: toate blocate. |
| B-07/B-08 chei i18n | **Reparat** | Deja rezolvat de `d8ec69c`. Faza A: 0 lipsă. |
| B-09 cod mort `#order-datetime` | **Reparat** | Ramura din `loadOrder()` (`js/pos.js`) a fost eliminată — ecranul de marcare nu are element de dată/oră (locul e al `#scan-input`). |
| B-10 drift `AGENTS.md` | **Reparat** | 6 referințe `index.html` → `index.php`, 2 `mobile.html` → `mobile.php`. Faza A7: 0. |
| B-11 mesaj API v1 | **Reparat** | Deja rezolvat de `d8ec69c` (mesaj parametrizat prin `t()`). |
| B-12 config casă de marcat | **Reparat** | Mock-ul FiscalWire scrie acum în `logs/mock_fiscal_wire/` (nume paralel cu `logs/mock_fiscal_net/`), nu în `logs/mock_fiscal/`; folderul runtime a fost mutat cu `state.json` cu tot, iar `tblSet.CaleFisierComenziECR`/`CaleFisierRaspunsECR` indică acele foldere — consistent cu `TipCasaMarcat=FiscalWire`. Verificat end-to-end: o comandă de test a produs `.txt`+`.nrb` cu `UNIQUE_ID` corect și a fost ștearsă (comportament FiscalWire). |
| B-13 info diverse | Informativ | Neschimbat. |

**Rezultat după remedieri** (fără faza D, care scrie în DB): lint 55/55 · A 15/15 · B 23/23 · C 50/50 · E 26/26 = **114 pass, 0 fail**.
Faza D (54/54 la rularea inițială) nu a fost rerulată. Smoke test HTTP: paginile aplicației răspund 200, fișierele sensibile 403.

### Risc rezidual închis: așteptarea răspunsului casei de marcat

Punctul 2 din „Zone neacoperite" (fluxul `AsteaptaRaspunsCasa = 1` cu mock-ul FiscalWire) este acum acoperit de **Faza F** — `tests/fiscal_wait.php`, **35 pass, 0 fail**. Testul pornește singur mock-ul în foldere izolate (`logs/mock_fiscal_test/`) și verifică trei căi:

1. **succes** — `close_bill` → 200 în ~1.4 s, nota se închide, **fără job `fiscal`** (răspunsul casei ține locul bonului tipărit), cu job `nota` creat normal; răspunsul brut ajunge identic în `tblBonCurent.RaspunsCasa` **și** în `temp_Send_Sql.RaspunsCasa`; `NR`/`Z` din `.nrb` apar în comanda de export (nu valorile prezise); fișierul de comenzi e consumat de driver.
2. **refuz** (mock `--mode err`) — `close_bill` → 409, nota rămâne deschisă, niciun job creat, `RaspunsCasa` gol.
3. **timeout** (nicio casă pornită) — `close_bill` → 409 după 10.3 s (8 s așteptare + 2 s grație), nota rămâne deschisă, niciun job și nicio comandă de export, **fișierul de comenzi e șters după grație** (ca driverul să nu tipărească un bon abandonat), iar eșecul apare în `logs/fiscal_wait.log` cu `rezultat=timeout`.

Curățarea e completă (verificată: masă liberă, setări restaurate, folder de test șters, mock-ul real și contoarele lui neatinse).
