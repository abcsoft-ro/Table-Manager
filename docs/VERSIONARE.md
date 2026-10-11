# Versionare și release

Cum se trece la o versiune superioară a aplicației TableManager și cum ajunge ea pe stații.

> Document de dezvoltare. Nu este necesar pe stațiile de producție și este exclus din
> snapshot-ul de instalare (`app.tgz`).

## Unde „trăiește" versiunea

| Element | Rol | Cine îl scrie |
|---|---|---|
| `VERSION` (rădăcină) | versiunea human (sursa de adevăr), ex. `1.5.0` | manual, la release |
| `logs/update_state.json` | identitatea mașină: `sha`, `short`, `build`, `branch`, `builtAt`, `updatedAt`, `source` (`setup`/`update`/`dev`) | `sync.sh` (dev), `install.ps1` (setup), `api/update.php` (update) |
| `payload/app-version.json` (repo `TableManager-Setup`) | versiunea snapshot-ului de instalare | `sync.sh` (automat) |

`build` = `git rev-list --count HEAD` (crește singur cu fiecare commit), calculat de `sync.sh`.

Vizibil în: **Setări → Servicii** (cardul „Versiune"), dialogul **Update** și programatic la
`GET /rual/api/version.php`.

## Pașii pentru o versiune nouă

### 1. Bumpează `VERSION`

Ex.: `1.5.0` → `1.5.1` (patch) / `1.6.0` (minor) / `2.0.0` (major).

### 2. (Opțional, dar recomandat) Actualizează referințele „hardcodate"

Ca să nu rămână text cu versiunea veche:

- **Aplicație** (`Table-Manager`):
  - `README.md` — titlul și badge-ul;
  - `index.html` — `<title>`;
  - `js/pos.js` — comentariul de sus;
  - `db/tools/export_schema.php` — 3 locuri (schema, seed, `VersiuneDB`).
- **Setup** (`TableManager-Setup`):
  - `README.md`, `Setup.bat`;
  - `install/install.ps1` — 2 locuri;
  - `install/script_rual.ps1` — 2 locuri.

`payload/app-version.json` **nu** se editează manual — este regenerat de `sync.sh`.

### 3. Rulează `./sync.sh`

Din rădăcina proiectului, `./sync.sh` face automat:

1. commit + push pe repo-ul aplicației (`abcsoft-ro/Table-Manager`, branch `main`);
2. calculează `build`, citește `VERSION`;
3. scrie `payload/app-version.json` în repo-ul Setup, regenerează `payload/app.tgz`
   (exclude `.git/.venv/logs`, `db.local.php`, `config.json`, `docs/`, PDF-uri etc.);
4. commit + push pe repo-ul Setup (`abcsoft-ro/TableManager-Setup`);
5. scrie local `logs/update_state.json` (`source=dev`) + o linie în `logs/version.log`.

> Folderul repo-ului de Setup se poate schimba cu variabila `SETUP_DIR`.

### 4. (Opțional) Tag git de release

`sync.sh` **nu** creează tag-uri. Pentru un reper manual pe GitHub:

```bash
./sync.sh                      # asigura-te ca totul e impins
git tag -a v1.5.1 -m "Release 1.5.1"
# push tag (folosind tokenul din github_api.txt, ca in sync.sh):
TOKEN=$(sed -n 's/^token://p' github_api.txt | tr -d '\r\n')
git push "https://x-access-token:${TOKEN}@github.com/abcsoft-ro/Table-Manager.git" v1.5.1
```

## Cum ajunge versiunea pe stații

- **Instalare nouă** → installerul (`install.ps1`) scrie `logs/update_state.json` din
  `payload/app-version.json` (`source=setup`).
- **Stație existentă** → din aplicație, **Programare → Update** (`api/update.php`) descarcă
  arhiva de cod de pe GitHub, face backup înainte și o aplică (`source=update`).
  Actualizarea nu folosește git pe client și nu atinge fișierele locale
  (`api/db.local.php`, `config.json`, `logs/`, `.venv/`).

## Limitare cunoscută: `build` la self-update

La **self-update**, `version` (din `VERSION`) și `sha` se actualizează, dar **`build` rămâne cel
de dinainte**: `api/update.php` păstrează `build`/`builtAt` existente, pentru că arhiva de cod
de pe GitHub nu conține numărul de build (acesta e calculat doar la împachetare, în `sync.sh`).

Consecință: două stații cu **exact același cod** (același `sha`) pot afișa `build` diferit —
una instalată din snapshot-ul nou, alta actualizată prin butonul Update. De aceea:

- `sha` este **identitatea exactă** (se compară mereu cu GitHub);
- `build` este un **contor de pachet**, util mai ales pentru snapshot-ul de instalare.

> Îmbunătățire posibilă (neimplementată): mutarea `build` într-un fișier **comis** în repo-ul
> aplicației (ex. `version.json`), scris de `sync.sh` și citit de `api/update.php` din arhivă,
> astfel încât toate stațiile la același commit să arate același `build`. Nu are problema
> „de ou și găină" a SHA-ului, pentru că `build` nu depinde de hash-ul commit-ului.
