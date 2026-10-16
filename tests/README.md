# tests/ — harness de test TableManager 2.0.0

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
php tests/static.php      # Faza A - contract static, i18n, OpenAPI, DOM
php tests/invariants.php  # Faza B - invariante DB (DOAR CITIRE)
php tests/api.php         # Faza C - oracle API, validari negative, securitate, API v1
php tests/services.php    # Faza E - print/sync service, coada, emulator, retry
php tests/flows.php       # Faza D - fluxuri business (SCRIE in DB, cu curatare)
php tests/fiscal_wait.php # Faza F - asteptarea raspunsului casei de marcat (SCRIE in DB, cu curatare)
```

## Reguli de siguranta

1. **Faza D scrie in baza `Rual`** (mesele 80 si 79) si curata tot la final.
   Faceti backup inainte: `tools/backup-db.ps1` este **stricat** (vezi
   `REPORT.md` B-01) — folositi `sqlcmd` / `BACKUP DATABASE`.
2. Fazele A/B/C/E nu distrug date. C salveaza si restaureaza setarile atinse;
   E linisteste worker-ele prin `poll_interval_sec` mare (hot-reload) si
   restaureaza configul (nu opreste serviciile).
3. E nu imprima fizic: foloseste tinte `preview`/`file` sau o tinta moarta
   (`127.0.0.1:9`).
4. Nu se executa un raport Z real (ar modifica numerotarea contabila);
   se testeaza doar guard-ul.
5. **Faza F scrie in baza `Rual`** (doar masa 80) si porneste singura mock-ul
   FiscalWire in foldere izolate (`logs/mock_fiscal_test/`, sterse la final),
   deci nu atinge mock-ul real (`logs/mock_fiscal_wire/`) si nici contoarele lui.
   Nu este inclusa automat in `run_all` tocmai pentru ca scrie in baza; se ruleaza
   individual. Pune tintele de tiparire pe `preview` in timpul testului si le
   restaureaza la final, ca sa nu atinga nicio imprimanta.

## Rezultat

`REPORT.md` contine raportul complet (constatari, severitati, dovezi).

## Nota

Fisierele de test nu fac parte din aplicatie si nu ajung pe statiile de productie:
`sync.sh` le exclude din arhiva de instalare, iar `api/update_common.php`
(`updateIgnorePrefixes`) le exclude si la autoactualizare.

**Exceptie:** Faza F (`fiscal_wait.php`) scrie in baza `Rual` (doar masa 80) —
vezi regula 5 mai sus.
