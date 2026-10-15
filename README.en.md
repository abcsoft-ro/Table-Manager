**English** · [Română](README.md)

# TableManager 2.0.0

### Complete POS for restaurants and fast-food — automatic installation, operational reliability, no heavy dependencies.

![Main screen (Restaurant)](docs/img/01-hero-restaurant.png)

TableManager manages **tables, bills, waiters, the menu, discounts, voids, payments** (including card payment through a bank terminal) and **printing to the kitchen / bar / fiscal receipt**, plus X reports and Z closure.

The same application runs in **two modes** — **Restaurant** (table-side ordering) and **FastFood** (quick counter sales) — selected with a single setting. It is rewritten from scratch on modern, lightweight technologies, with **durable queues**: *no order and no bill is ever lost*.

`Version 2.0.0` · `PHP 8.3 + MSSQL` · `No build step` · `Windows + Apache` · `Mobile module included` · `Integration API`

---

## What's new in version 2.0 — Integration API (major release)

Version **2.0** brings the **public integration API** for third-party applications, a major version update:

- **Versioned REST API `/api/v1/`** for **export (read)** and **import (write)** of data by other applications (servers/backends): products, groups, tables, bills (current and archived after Z), payments, X reports and an incremental feed of closed bills (`/events`) for synchronization.
- **Single global key authentication** (`X-Api-Key`), generatable from **Setări → Integrare** (only SHA‑256 is stored), with **IP restriction** and **rate limiting** options.
- **External access exclusively via VPN** (recommended *SoftEther VPN Azure + SecureNAT*, alternatively *Tailscale/ZeroTier*) — no internet exposure, no domain/certificate/TLS; the application stays on HTTP on the local network.
- **Transactional writes** with `Idempotency-Key` support (safe retries) and **audit** of every request (`tblApiLog`).
- **Complete documentation** for integrators: `docs/API-INTEGRARE.md`, the OpenAPI spec (`docs/openapi.json`) and the HTML reference (`docs/api-reference.html`).

---

## Why TableManager?

- **One-step automatic installation** — a dedicated installer sets up everything on a clean Windows 11: Apache, PHP, SQL Server Express, Python, the services and the shortcuts.
- **One-click update**, with **versioning** (version + build + commit), **automatic backup** before each update and the ability to know exactly what version runs on each station.
- **Never loses an order or a bill** — durable queues with automatic retry; the printer or the server can be down, nothing is lost.
- **Works offline** and exports to the server as soon as connectivity returns.
- **Fiscal + bank POS + kitchen printing built in** — no expensive external modules.
- **One product, two modes:** Restaurant and FastFood.
- **Mobile module included** (tablet/phone), at no extra cost.

---

## Two operating modes: Restaurant and FastFood

Selected from **Setări → General** (`TipVanz`). Same database, same menu, same programming interface.

### 🍽️ Restaurant — table-side ordering

![Table map (Restaurant)](docs/img/02-mese.png)

- Table map (80 seats), open bill per table, owner per waiter.
- **Order ticket** to the kitchen/bar, per section, with its own section-ticket numbering.
- **Payment note**, **proforma note**, **product transfer** between tables and **preparation mode**.

### 🍔 FastFood — quick counter sales

![FastFood mode](docs/img/02-fastfood.png)

- No table map — the application starts directly in the sales screen.
- No section ticket, no proforma note, no transfer, no preparation mode (hidden automatically).
- On close, **only the fiscal receipt** is issued; the total and change appear in the sales panel.

> One project, two flows — not two applications to maintain.

---

## Features

### Floor operation
- **Tables and bills** — 80 tables, open/close bill, owner per waiter (a waiter cannot enter another waiter's table).
- **Menu** — groups and products with configurable prices, colors, fonts and sizes; **Meniul Zilei** (Daily Menu).
- **Code scanning** — search by barcode or product code.

![Menu](docs/img/03-meniu.png)

### Orders & kitchen
- **Order ticket per section**, with its own section-ticket numbering (dedicated counter, reset at the Z report).
- **Preparation mode** — text attached to a line (e.g. "no onion"), which does not affect the total.

### Discount & void
- **Discount** per line or per bill, percentage or amount, correctly distributed across the VAT rates.
- Three configurable modes: full (product/subtotal, percent/amount, with popup) · subtotal percent only · fixed subtotal percent, applied directly.
- **Void (VD)** — cancellation of already-sent lines, with manager password and reason; quantities cannot be exceeded; unsent lines are cancelled directly.

### Payments
- **Split** across several payment forms (cash, card, voucher etc.), with change displayed.
- The **combined payment** setting (DA/NU): when *NU*, the close screen shows only the payment forms (no numpad), and a click on a form closes the whole bill.

![Payment](docs/img/04-plata.png)

- **Bank POS (card terminal)** — when the bill has a card payment and `tblSet.PoSbanca = 1`, the application communicates synchronously with the terminal through an external driver, with a 190 s timeout, a **Cancel / Retry / Ignore** dialog on refusal (Cancel keeps the bill open, Ignore closes it anyway) and **audit** of every attempt (amount, TRX_ID, status, message) in `tblPosBancaLog`.

### Cash register (fiscal)
- The command file for the fiscal driver, generated for **FiscalWire** (`.inp`) or **FiscalNet** (`^` separator), depending on `tblSet.TipCasaMarcat`.
- **CUI (customer tax code)** — popup with a dedicated keypad, validated with the ANAF check digit and sent on the receipt.

### Printing
- **Durable print queue** — section order ticket, payment note, proforma note and reports.
- The physical destinations of each section (Windows raw, network :9100, file, preview) are configured from the application, with a **test print**; changes apply instantly (hot-reload).
- **Receipt emulator included** — preview without hardware.

![Section order ticket (emulator)](docs/img/05-bon-sectie.png)

![Payment note (emulator)](docs/img/09-nota-plata.png)

### Reports and closure
- **X reports** — PLU, Groups, Sections, Cashiers, General and **Notes** (the session's closed bills, with the detail of each bill).
- **Z closure** — atomic archiving and clearing of the day, with a General Z report plus the selected reports.

![X report](docs/img/06-raport-x.png)

### Administration from the interface
- Settings, groups, products, sections, tables, waiters, preparation modes, passwords, VAT rates, printers per section, payment forms, header, note, server connection and product sync — all editable directly from the application.

### Mobile module
- Dedicated page for tablet/phone (`mobile.html`) for table ordering, independent of the desktop POS, but using the same APIs.

![Mobile module](docs/img/07-mobil.png)

### Multi-station & integration
- **Public integration API (`/api/v1/`)** — third-party applications can **export** (products, groups, tables, current/archived bills, payments, X reports) and **import** (products, groups, tables, waiters, payment forms, VAT rates) data, with key authentication (`X-Api-Key`), idempotent writes and audit. External access is exclusively via VPN. See `docs/API-INTEGRARE.md`.
- **Export to server** — on closing each bill, an `EXEC EmitBon_Ext_NOU` (Restaurant) / `EmitBon_Ext_2` (FastFood) command is written to a durable queue; a Python service executes it on the server in `tblConectare`. If the server is down, the command is retried **forever**, with backoff.
- **Product/group import at startup** — synchronization from the external server through the `dbo.ImportProd` procedure. One or more stocks can be imported (`tblSet.MagID`, e.g. `1,2`); for products present in several stocks, the first in the list wins.

---

## Reliability and safety

- **Atomic transactions** for critical operations (bill close, marking, Z closure): a bill or a day cannot remain half-processed.
- **Durable queues** for printing and export — jobs are persisted in the database and retried automatically, so an offline printer loses and blocks no order.
- **Parameterized queries** — eliminate SQL injection.
- **Role-based passwords** — programming, reports, void, discount and exit; per-waiter authentication, with blocking.
- **Credentials in a local file**, ignored by git.
- **Automatic backup** before each update.

---

## Installation & update

- **Automatic installer** (separate repo `TableManager-Setup`): on an empty Windows 11 it configures Apache + PHP 8.3 + SQL Server 2022 Express + Python 3.12, the application deploy, the database clone, the `.venv` virtual environment, the services and the shortcuts. Double-click `Setup.bat`; the installation is idempotent.
- **Kiosk launch** — `Pornire-POS-Kiosk.vbs` (no DOS window) opens the application fullscreen and imports products at startup.
- **In-app update** — the **Update** button compares the installed version with GitHub, backs up and applies automatically.
- **Versioning** — each station knows exactly what it runs: the `VERSION` file (e.g. `2.0.0`) plus build + commit + date, visible in **Setări → Servicii**, in the Update dialog and programmatically via `api/version.php`. The release process (version bump, `sync.sh`, git tag) is described in [docs/VERSIONARE.md](docs/VERSIONARE.md).

![Setări → Servicii (version + services)](docs/img/08-setari-versiune.png)

---

## Technology and advantages

- **PHP + MSSQL (`sqlsrv` extension)** — simple backend, no framework. Direct stored-procedure calls and native ACID transactions for critical operations; zero abstraction layer hiding the database behavior.
- **Plain JavaScript and CSS (no framework, no build step)** — desktop frontend in `js/pos.js`, mobile in `js/mobile.js`. Deploy = edit the file and reload the page.
- **Canvas-scaled layout** — base design 1024×768, scaled proportionally on any screen, without fragile vw/vh CSS.
- **Python + ESC/POS print service** — `print-service/` renders receipts and sends them natively to printers (Windows raw or network :9100); it owns its configuration and hot-reloads it, with an emulator included for testing.
- **Runs on HTTP** — the services derive their API URL from the installation folder name, so it works even if the project is renamed or installed in another folder directly under `htdocs`.

---

## Requirements

- **Apache** with **PHP** and the **`php_sqlsrv`** extension loaded.
- **Microsoft SQL Server** (database configurable in `api/db.local.php`).
- **Python 3.12** (Windows) for the Python components (`print-service/`, `sync-service/`, `import_server_grp_prod.py`) — optional, only if you use one of them. All run **from a common virtual environment** `.venv` in the project root, created once with `setup-venv.bat` (dependencies — `pyodbc`, `pywin32`, `pyserial` — are in `requirements.txt`). An **ODBC Driver for SQL Server** is also required. The virtual environment is mandatory: without it, the kiosk and the Python services stop with a message asking to run `setup-venv.bat`.

---

## Installation and configuration (manual)

1. Copy the project into the directory served by Apache (e.g. `htdocs/rual`).
2. Rebuild the database by running `db/schema.sql` then `db/seed.sql` (details in [db/README.md](db/README.md)).
3. Configure the connection: copy `api/db.local.example.php` to `api/db.local.php` and fill in the server, database, user and password.
4. (Optional, but mandatory if you use the Python components) Run `setup-venv.bat` from the project root: it creates `.venv`, installs the dependencies and creates the shortcuts (the two services in `Startup` and `TableManager POS` on the Desktop).
5. (Optional) Start the print service: `print-service/start-print-service.bat` (or `start-print-service-hidden.vbs`). It listens only on `127.0.0.1:8756`. Destinations are configured from **Setări → Imprimante secții**, with test buttons: each target (section, note, reports) can be on the **network** (IP:port) or **Windows / USB** (local printer with the Windows driver installed, marked `(USB)` if on a USB port). USB printers are accessible only on the station running the print service.
6. (Optional) Start the export service: `sync-service/start-sync-service.bat` (or the `-hidden` variant). It listens only on `127.0.0.1:8757` and reads the server/credentials from `tblConectare` (ID = 1).
7. (Optional) Use `Pornire-POS-Kiosk.vbs` to open the application in fullscreen kiosk mode, without a DOS window.

---

## Database rebuild

The database structure and the reference data are published in the `db/` directory:

- `db/schema.sql` — tables, indexes, constraints, foreign keys and stored procedures.
- `db/seed.sql` — minimal reference data plus a demo menu (no real data).

The steps are: run `db/schema.sql`, then `db/seed.sql`, then configure `api/db.local.php`. Full instructions: [db/README.md](db/README.md).

---

## Database backup

Scheduled MSSQL database backup, independent of the application (it does not depend on an open browser):

- `tools/backup-db.ps1` — makes a **full** backup (`BACKUP DATABASE ... WITH INIT, CHECKSUM`), **verifies** it (`RESTORE VERIFYONLY`), deletes backups older than **14 days** and writes a log in `logs/backup_db.log`. Credentials are read from `api/db.local.php` (same source as the application), and execution uses ADO.NET (`System.Data.SqlClient`), with no additional dependencies.
- `tools/backup-db-task.xml` — Windows Scheduler task that runs the script **daily at 03:00**, as **SYSTEM** (no stored password), with `StartWhenAvailable` (it also runs if the station was off).
- `tools/install-backup-task.bat` — mounts the task (run as Administrator): it derives the project folder, rewrites the path in the XML and calls `schtasks /Create`.

By default, backups are written to `C:\ABCSoft\Data\backup`, named `<Database>_<yyyyMMdd_HHmmss>.bak`. When SQL Server runs on the same machine, the script creates the folder and grants write rights to the SQL service account; if the server is remote, the folder must already exist and be writable on that machine.

**Mount:**

```bat
tools\install-backup-task.bat        :: right-click -> Run as administrator
```

or manually: `schtasks /Create /TN "TableManager\Backup DB" /XML "…\tools\backup-db-task.xml" /F`.

**Manual run / test:**

```bat
powershell -ExecutionPolicy Bypass -File tools\backup-db.ps1            :: backup now
powershell -ExecutionPolicy Bypass -File tools\backup-db.ps1 -DryRun    :: only show the plan
schtasks /Run /TN "TableManager\Backup DB"                              :: force the task
```

**Restore** (after stopping the application), in SQL:

```sql
RESTORE DATABASE [YourDatabase]
  FROM DISK = N'C:\ABCSoft\Data\backup\YourDatabase_20260101_030000.bak'
  WITH REPLACE;
```

---

## Project structure

```
index.html              desktop POS (screens, modals, scaled canvas)
js/pos.js               all desktop POS logic (global state POS_STATE)
css/pos.css             desktop POS styles
mobile.html             mobile/tablet client (table ordering)
js/mobile.js            mobile module logic (global state MOBILE_STATE)
css/mobile.css          mobile module styles
VERSION                 human version (e.g. 2.0.0)
docs/img/               the screenshots in this README
api/                    PHP endpoints (JSON)
  db.php                MSSQL connection (+ local db.local.php, ignored)
  order_action.php      dispatcher: add_product, close_bill, void_line, apply_discount etc.
  menu.php, get_order.php, get_tables.php
  rapoarte.php          X reports, Z closure, report printing
  print_queue.php       durable print queue API
  print_common.php      shared helpers (ensurePrintQueueTable, enqueuePrintJob)
  send_queue.php        durable export-to-server queue API (temp_Send_Sql)
  send_common.php       shared helpers (ensureSendSqlTable, enqueueBillSendSql)
  print_config.php      proxy to the print service (read/save config, test)
  pos_banca.php         bank terminal communication (bank POS) + audit
  version.php           application version (VERSION + build + commit)
  version_common.php    versioning helpers (state, label, log)
  exit_app.php          closes the POS browser from the exit password
  sync_products.php     runs the dbo.ImportProd stored procedure (Product sync)
  ...                   configuration editors (products, groups, tables, vat, kp, fp, etc.)
  sql/z_procedure.sql   Z-closure stored procedure
print-service/          Python ESC/POS print service
  server.py, worker.py, escpos.py, emulator.py, targets.py, config.py, config.json
sync-service/           Python export-to-external-server service (temp_Send_Sql)
  server.py, worker.py, db.py, config.py, config.json
db/                     database rebuild
  schema.sql            table structure + stored procedures
  seed.sql              reference data + demo menu (no real data)
Pornire-POS-Kiosk.vbs   hidden kiosk launcher (no DOS window) + product import at startup
Pornire-POS-Kiosk.bat   kiosk launcher with a visible window (same logic)
import_server_grp_prod.py  product/group import from the server (dbo.ImportProd)
requirements.txt        Python dependencies (pyodbc, pywin32, pyserial) for the virtual environment
setup-venv.bat          creates .venv, installs the dependencies and creates the shortcuts (once)
tools/create-shortcuts.vbs  creates the shortcuts: services in Startup + POS on the Desktop
tools/backup-db.ps1     full database backup (scheduled, verified, with retention)
tools/backup-db-task.xml    Windows Scheduler task for backup (daily 03:00, as SYSTEM)
tools/install-backup-task.bat  mounts the backup task (Run as administrator)
.venv/                  Python virtual environment (created locally, ignored by git)
sync.sh                 commit + push to GitHub + regenerate the install snapshot
```

---

## Technical appendix

### Cash register (detailed protocol)

When the bill is closed (`close_bill`), in the same transaction, the command file for the cash register is generated and written to the durable print queue; the Python service writes it to `spool/fiscal` and **copies** it into the folder monitored by the fiscal driver (`tblSet.CaleFisierComenziECR`), from where the register processes it. If the folder is unavailable, the job is retried with backoff, so the receipt is not lost.

The register type is chosen from **Setări → Casa Marcat** (`tblSet.TipCasaMarcat`):

- **FiscalWire** (FiscalWire driver) — `.inp` file:
  - `H,1,______,_,__;` header (no trailing `F` line, as in the old procedure);
  - `S,1,______,_,__;NAME;PRICE;QTY;1;1;NrTVA;0;0;` sale (uppercase name, truncated to 32 characters, no space padding; price with 2 decimals; quantity with 3 decimals; void = negative quantity);
  - `C,1,______,_,__;1;PERCENT;` line discount (simple percentage, e.g. `5.00` = 5%);
  - `K,1,______,_,__;RO12345678;` customer tax code, before the first sale;
  - `T,1,______,_,__;PAY_CODE;;;;;` collection with a **single** payment form (no amount: FiscalWire computes the total itself); for several payment forms, one line per form with its amount (`T,1,______,_,__;PAY_CODE;AMOUNT;;;;`), in descending code order.
- **FiscalNet** — `.txt` file with a `^` separator:
  - `S^NAME^PRICE^QTY^um^VATGRP^1`, `DV^value` / `MV^value` for discount/surcharge, `VS^...` for void, `ST^` subtotal, `P^PAY_CODE^AMOUNT` collection;
  - `CF^RO12345678` customer tax code, before the first sale.
- **Fisco**, **fprwin** — work in progress.

Payment codes are taken directly from `tblFP.FPID` (0 = Cash, 1 = Card, 2 = Cheque, 3 = Voucher, 4 = OP, 5 = Voucher): for FiscalWire `FPID` is written, for FiscalNet `FPID + 1`. The VAT group is resolved from `tblTVA` by the line's rate (`tblNoteD.TVAc` → `tblTVA.Nr_TVA`).

By default, the fiscal receipt does **not** print the discount or the void (only the net quantities and prices). The `tblSet.BonFiscalDiscStorno = 1` setting enables the detailed mode.

### Waiting for the cash register response (FiscalWire / FiscalNet)

The `AsteaptaRaspunsCasa` setting (Setări → Casa Marcat, default **NU**): when it is **DA** and the register type is **FiscalWire** or **FiscalNet**, on closing the bill the POS writes the command file directly to `tblSet.CaleFisierComenziECR` and **waits** up to `AsteptareCasaSec` seconds (default 30) for confirmation in `tblSet.CaleFisierRaspunsECR`, before closing the bill:

- **FiscalWire**: the `.nrb` file (first line `OK;Z=..|NR=..|...|BF=..`) confirms the receipt; the real `NrBon` (`NR`) and `NrZ` (`Z`) are forwarded to the export service; `CANCELED` or any line with status `Er` blocks the close.
- **FiscalNet**: the response file contains `BONOK=1` + `NRBON=<nr>` on success, `BONOK=0` + `ERRCODE`/`ERRINFO` on error, or `BONOK=-1`.
- a missing response (timeout) means the bill is **not** closed; the payment screen returns to its initial state and it can be retried.
- in any failure case, the POS **deletes the command file**, so the driver does not later print an abandoned receipt; before each send, abandoned `pos_tm_*` files are also cleaned up.
- every failure is written to `logs/fiscal_wait.log`.

### Fiscal testing (mocks)

- `tools/mock_fiscalwire.py` (started with `tools/mock_fiscalwire.bat`, from `.venv`) simulates the FiscalWire driver: it writes the `.txt`/`.nrb` responses in the configured folder (modes `ok` / `err` / `canceled` / `silent` / `random`) and deletes the command file after processing (it can be kept with `--keep`).
- `tools/mock_fiscalnet.py` (with `tools/mock_fiscalnet.bat`) simulates the FiscalNet driver: it writes the response with the same name as the command (modes `ok` / `err` / `pos` / `silent` / `random`).

---

## License

This project is distributed under the **GNU General Public License v3.0 (GPL-3.0)**.

Copyright (C) 2026 **Florian Liviu Stefanescu**.

The full license text: [LICENSE](LICENSE).

The application is free software: you may use, study, modify and redistribute it freely, provided that any distributed derivative version remains, in turn, under GPL-3.0 with open source. It is distributed without any warranty.

---

© ABCSoft · https://abcsoft.ro
