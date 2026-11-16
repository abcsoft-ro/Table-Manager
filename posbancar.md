# POS bancar (terminal card) — flux complet (SmartPay ECR Link + Viva ECR)

Document de referință pentru dezvoltare și mentenanță. Descrie **comportamentul implementat în cod**, nu un protocol teoretic:

| Zonă | Fișiere |
|---|---|
| Client POS (browser) | `js/pos.js` (secțiunea „COMUNICARE POS BANCAR”), `index.php` (dialoguri/butoane) |
| Endpoint și orchestrare | `api/pos_banca.php` |
| Adaptor SmartPay (`.env`, resync, void) | `api/pos_banca_smartpay.php` |
| Adaptor Viva (`.env`, last-receipt, void) | `api/pos_banca_viva.php` |
| Helpers comuni ai driverelor | `api/pos_banca_driver.php` |
| Starea plății bancare + audit | `api/pos_banca_state.php` |
| Registrul de protocoale | `api/pos_banca_protocol.php` |
| Driver SmartPay | `tools/pos_banca/smartpay_client.py`, `tools/pos_banca/env_config.py` |
| Driver Viva ECR | `tools/pos_banca/viva_client.py`, `tools/pos_banca/env_config.py` |
| Simulatoare terminal | `tools/pos_banca/mock_pos_server.py`, `tools/pos_banca/mock_viva_terminal.py` |
| Blocarea notei | `api/order_action.php`, `api/get_order.php`, `js/mobile.js` |
| Teste | `tests/pos_banca.php` (C2), `tests/js_bankpos.php` + `tests/js_bankpos_client.js` (JS2) |

> Sunt implementate două drivere cu identitate clară, alese din
> `tblSet.ProtocolPoSbanca`: **SmartPayECR** (SmartPay ECR Link v1.8.3 — Get
> Information, Sale, Report Initialization, Report Record, Void, Remove Card, pe
> transport serial sau TCP) și **VivaECR** (Viva.com Terminal, ECR Protocol
> TCP/IP). Fluxul comun (plată, audit, blocarea notei, dialoguri) este identic;
> diferă doar contractul cu terminalul, descris în secțiunea 20.

---

## Cuprins

1. [Rezumat](#1-rezumat)
2. [Componente și responsabilități](#2-componente-și-responsabilități)
3. [Configurare](#3-configurare)
4. [Contractul cu terminalul (protocolul SmartPay)](#4-contractul-cu-terminalul-protocolul-smartpay)
5. [Fluxul principal de plată, pas cu pas](#5-fluxul-principal-de-plată-pas-cu-pas)
6. [Cele cinci rezultate posibile](#6-cele-cinci-rezultate-posibile)
7. [Ramura UNKNOWN: verificarea automată în jurnal](#7-ramura-unknown-verificarea-automată-în-jurnal)
8. [Ramura „terminalul nu răspunde”](#8-ramura-terminalul-nu-răspunde)
9. [Ramura „refuz normal”](#9-ramura-refuz-normal)
10. [Soluționarea manuală (AUTHCODE)](#10-soluționarea-manuală-authcode)
11. [Anularea (void)](#11-anularea-void)
12. [Blocarea notei cât timp plata este incertă](#12-blocarea-notei-cât-timp-plata-este-incertă)
13. [Închiderea notei și bonul fiscal](#13-închiderea-notei-și-bonul-fiscal)
14. [Audit: `tblPosBancaLog`](#14-audit-tblposbancalog)
15. [Cazuri speciale](#15-cazuri-speciale)
16. [Testare](#16-testare)
17. [Depanare](#17-depanare)
18. [Limite, garanții și securitate](#18-limite-garanții-și-securitate)
19. [Anexă: harta acțiunilor API](#19-anexă-harta-acțiunilor-api)

---

## 1. Rezumat

Plata pe card pornește **numai când nota poate fi închisă**, nu la fiecare
apăsare a unui buton de pe ecranul de plată. `executeClose()` — funcția care
ajunge și la driverul bancar — este apelată:

- la **OK**, după ce sumele introduse pe formele de plată acoperă valoarea notei
  (`totalPaid >= total - 0.001`); în plata combinată, un OK cu sumă parțială doar
  înregistrează plata și așteaptă restul, **fără** să contacteze terminalul;
- la **click pe forma de plată** când `PlataCombinata = 0` (o singură formă de
  plată): pentru **CARD**, dialogul cu terminalul începe imediat la acel click,
  fără OK și fără tastarea sumei.

Când `executeClose()` ajunge să închidă nota, există sumă pe partea de **card**
(`FPID = 1`), `tblSet.PoSbanca = 1` și nu există deja o aprobare pentru notă,
clientul trimite suma părții de card către `api/pos_banca.php`. Serverul
lansează driverul SmartPay (`vanzare.bat` → `smartpay_client.py`) cu `suma` și un
`TRX_ID` unic, iar driverul deschide o sesiune ECR cu terminalul, trimite
comanda **Sale** și așteaptă răspunsul. Terminalul răspunde **aprobat**,
**neaprobat/refuzat** sau — dacă răspunsul se pierde — starea devine
**necunoscută**. La **aprobat** nota se închide și se emite bonul fiscal (prin
`close_bill`); la **refuz** nota rămâne deschisă; la **necunoscut** aplicația
verifică **automat** jurnalul terminalului (Report Initialization + Report
Record). Dacă plata apare în jurnal, este preluată silentios și nota se închide
fără nicio taxare suplimentară; dacă nu apare, operatorul primește un dialog
clar („cardul NU a fost debitat”); dacă terminalul nu poate fi citit deloc,
plata se clarifică manual pe baza chitanței (AUTHCODE de 6 caractere). Toate încercările
sunt scrise în `tblPosBancaLog`, iar nota este blocată la modificări cât timp
plata este incertă.

---

## 2. Componente și responsabilități

```
┌────────────────────┐   POST JSON    ┌──────────────────────────┐
│ Browser POS        │ ─────────────► │ api/pos_banca.php        │
│ js/pos.js          │ ◄───────────── │  (orchestrare + audit)   │
│ - ecran plată      │                │  ├─ pos_banca_state.php  │
│ - dialoguri        │                │  ├─ pos_banca_protocol   │
│ - blocare notă     │                │  └─ pos_banca_smartpay   │
└────────────────────┘                └───────────┬──────────────┘
                                                  │ proc_open
                                                  ▼
                                     ┌──────────────────────────┐
                                     │ vanzare.bat (pe stație)  │
                                     │  └─ smartpay_client.py        │
                                     │      ├─ .env             │
                                     │      └─ rezultat_ecr.txt │
                                     └───────────┬──────────────┘
                                                 │ TCP / serial
                                                 ▼
                                     ┌──────────────────────────┐
                                     │ Terminal bancar SmartPay │
                                     │ (chitanță tipărită de el)│
                                     └──────────────────────────┘
```

| Componentă | Rol |
|---|---|
| `js/pos.js` | Decide **când** se apelează terminalul (înainte de `close_bill`), afișează dialogurile, blochează nota, ține minte aprobarea ca să nu taxeze de două ori. |
| `api/pos_banca.php` | Validează configurația, lansează driverul, așteaptă fișierul de rezultat, normalizează mesajele, scrie auditul, expune `pay`/`status`/`resolve`/`resolve_manual`/`resync`/`void`. |
| `api/pos_banca_smartpay.php` | Doar pentru `SmartPayECR`: citește `.env`, rulează `resync`/`void` prin `smartpay_client.py`, scrie rezultatele auxiliare într-un fișier separat. |
| `api/pos_banca_state.php` | Sursa de adevăr pentru „plata este incertă?”: ultima încercare + ultima rezolvare (RESYNC/VOID/MANUAL), plus tabela de audit. Folosit și de blocarea notei. |
| `api/pos_banca_protocol.php` | Registrul driverelor cu identitate clară: `SmartPayECR` (jurnal, void, `.env`) și `VivaECR` (ECR Protocol TCP/IP). Nu există un driver „Generic”; o valoare necunoscută este normalizată la driverul implicit. |
| `smartpay_client.py` | Implementează protocolul: framing, CRC, ENQ/ACK/NAK/EOT, TLV, Sale, jurnal, void. Scrie întotdeauna un fișier de rezultat (și la eroare). |
| `tblPosBancaLog` | Audit complet: fiecare apel la terminal, fiecare verificare, fiecare decizie manuală și fiecare închidere de notă. |

---

## 3. Configurare

### 3.1 Setări în `tblSet` (Setări → Device-uri → **POS Banca**)

| Setare | Implicit | Semnificație |
|---|---|---|
| `PoSbanca` | `0` | `1` = fluxul bancar este activ. La `0` nu se lansează niciun driver, iar `pay` răspunde `enabled:false, emiteBon:true`. |
| `ProtocolPoSbanca` | `SmartPayECR` | Driverul terminalului: `SmartPayECR` (SmartPay ECR Link) sau `VivaECR` (Viva.com Terminal, ECR Protocol TCP/IP). O valoare veche/necunoscută (inclusiv `Generic`, eliminat) este normalizată la `SmartPayECR` și rescrisă de `ensureSetariMeta()`. |
| `CaleDriverPoSbanca` | gol | Folderul cu `vanzare.bat` (ex. `C:\ABCSoft\Sunmi`) **sau** direct un `.bat`/`.cmd`/`.exe`. |
| `CaleFisierRaspunsPoSbanca` | gol | Fișierul de rezultat. Gol → `rezultat_ecr.txt` lângă driver. Dacă e folder → `rezultat_ecr.txt` în folder. |
| `RetiparireChitantaPoSbanca` | `0` | `1` = butonul **„Chitanță card”** (duplicat pe imprimanta POS) apare după o aprobare pe card; `0` = NU (implicit). Când e `0`, și acțiunea `reprint` este refuzată server-side cu `403 reprint_disabled`. |

`menu.php` întoarce `PoSbanca` ca `posBanca` și setarea de retipărire ca
`retiparireChitantaPoSbanca`, păstrate în `POS_STATE`.
Setările de protocol sunt returnate de `api/setari.php` și afișate ca listă
derulantă în Setări.

### 3.2 Fișiere pe stație (SmartPay)

Lângă `vanzare.bat` trebuie copiate **ambele** fișiere:

```bat
copy tools\pos_banca\smartpay_client.py  C:\ABCSoft\Sunmi\smartpay_client.py
copy tools\pos_banca\env_config.py  C:\ABCSoft\Sunmi\env_config.py
```

Numele vechi `ecr_client.py` rămâne acceptat (aplicația îl caută dacă
`smartpay_client.py` lipsește), deci stațiile care au deja fișierul copiat
continua să meargă după update. Pentru instalări noi folosiți
`smartpay_client.py` și actualizați `vanzare.bat` să ruleze acest fișier.

`vanzare.bat` și `.env` rămân specifice stației și **nu** se suprascriu la update.
Driverul trebuie să ruleze cu Python-ul din `.venv` (are `pyserial` pentru
transport serial). Apache rulează ca `LocalSystem` și nu moștenește `PATH`-ul
utilizatorului, de aceea `posBancaFindPythonDir()` preferă
`.venv\Scripts\python.exe` și îl pune primul în `PATH`-ul procesului copil.
În plus, `posBancaEnsurePyShim()` scrie un `py.bat` în `.venv\Scripts`, ca un
`py.exe` global fără Python înregistrat să nu fie preferat de `vanzare.bat`.

### 3.3 `.env` (doar SmartPay)

Citit de `env_config.py`, cu suprascriere prin argumente de linie de comandă:

| Cheie | Implicit | Semnificație |
|---|---|---|
| `ECR_TRANSPORT` | `tcp` | `tcp` sau `serial`. |
| `ECR_HOST` | `127.0.0.1` | Host TCP. |
| `ECR_PORT` | `5070` | Port TCP. |
| `ECR_SERIAL_PORT` | — | Ex. `COM3` (obligatoriu pe serial). |
| `ECR_BAUD` | `115200` | Viteză serial (8N1). |
| `ECR_CURRENCY_NAME` | `RON` | Tag `A002`. |
| `ECR_CURRENCY_CODE` | `946` | Tag `A003`. |
| `ECR_TIMEOUT` | `180` | Timeout răspuns comandă (secunde). |
| `ECR_RETRIES` | `3` | Reîncercări framing/ACK. |
| `ECR_OUTPUT_FILE` | — | Fișier de rezultat implicit. |
| `ECR_CONN_LABEL` | — | **Doar diagnoză** (log); nu se afișează în UI. |

Conexiunea citită de PHP (`posBancaConnectionInfo`) este folosită doar pentru
mesaje de eroare/diagnosticare; nu se hardcodează niciun port.

### 3.4 Cum este lansat driverul

```text
"<CaleDriverPoSbanca>\vanzare.bat" <suma> <TRX_ID>
```

- `suma` este formatată cu **punct** zecimal și exact 2 zecimale (`100.00`).
- `TRX_ID` este generat de PHP: `TX` + `YmdHis` (ex. `TX20261101153000`).
- `cwd` = folderul driverului; `stdout` + `stderr` merg în
  `logs/pos_banca_driver.log` (se rescrie la fiecare plată, apoi se adaugă).
- PHP așteaptă fișierul de rezultat până la **190 s** (poll la 250 ms).
- Fișierul de rezultat este **șters înainte** de lansare, ca să nu se citească
  un rezultat vechi.

---

## 4. Contractul cu terminalul (protocolul SmartPay)

### 4.1 Transport și framing

- **Serial**: 8 biți, fără paritate, 1 stop bit, 115200 baud (configurabil).
- **TCP**: socket cu `TCP_NODELAY`.
- **Frame cerere** (ECR → terminal):

  ```text
  STX (0x02) | LEN (2 octeți, big-endian) | PAYLOAD (TLV) | ETX (0x03) | CRC16 (MSB, LSB)
  ```

- **Frame răspuns** (terminal → ECR):

  ```text
  STX | LEN | PAYLOAD | ETX | CRC16 (LSB, MSB)
  ```

  Ordinea octeților de CRC **diferă** între cerere și răspuns — implementată în
  `build_request()` și `_read_frame()`.
- CRC este **CRC16-IBM** (polinom `0x8005`, inițial `0x0000`).
- Payload-ul este o suită de **TLV**: `TAG` (2 octeți big-endian) + `LEN`
  (1 octet) + `VALUE`.

### 4.2 Sesiunea de control

| Octet | Nume | Rol |
|---|---|---|
| `0x05` | `ENQ` | ECR cere deschiderea sesiunii (login). |
| `0x06` | `ACK` | Confirmare. |
| `0x15` | `NAK` | Frame invalid → se cere retransmiterea. |
| `0x04` | `EOT` | Închiderea sesiunii (logout). |
| `0x02` / `0x03` | `STX` / `ETX` | Delimitatori de frame. |

**Login**: `ENQ`, se așteaptă `ACK` (2 s, până la 3 încercări). Înainte de login
se trimite un `EOT` „de curățare” (`clear_stale_session`) și se golește bufferul,
ca o sesiune rămasă deschisă de o rulare anterioară să nu desincronizeze
citirea. La final, `close_session()` trimite `EOT`; dacă scrierea eșuează
(USB suspendat / port invalid), **redeschide portul** și reîncearcă.

**O comandă**:
1. ECR scrie frame-ul și așteaptă `ACK` (5 s, până la 3 încercări). Fără `ACK`
   → `EOT` + eroare.
2. Terminalul trimite frame-ul de răspuns.
3. Dacă frame-ul e invalid (ETX/CRC), ECR trimite `NAK` și așteaptă
   retransmiterea.
4. La frame valid, ECR trimite `ACK` și parsează TLV-urile.
5. Fără răspuns în `ECR_TIMEOUT` (implicit 180 s) → `EOT` + `EcrUncertain`.

### 4.3 Comenzi

| Cod (`A000`) | Comandă | Folosită de |
|---|---|---|
| `0x01` | Get POS Information | diagnostic / `info` |
| `0x02` | **Sale by Card** | fluxul de plată |
| `0x04` | Report Initialization | verificarea în jurnal (`resync`) |
| `0x05` | Report Record | verificarea în jurnal (`resync`) |
| `0x06` | Void | anulare |
| `0x09` | Remove Card | comandă locală (nefolosită în flux) |

### 4.4 Tag-uri principale

| Tag | Nume | Observații |
|---|---|---|
| `A000` | Command | codul comenzii |
| `A001` | Amount | **12 cifre ASCII, în bani** (ex. `100.00` → `000000010000`) |
| `A002` / `A003` | Currency name / code | `RON` / `946` |
| `A004` | Index | index 0-based în jurnal (Report Record) |
| `A006` | STAN | numărul bonului terminalului (Void, zero-padded 6) |
| `A007` | Cash back | `000000000000` în fluxul normal |
| `A008` | Unique ID | `TRX_ID`-ul nostru, trimis la Sale |
| `A100` | Response code | **1 octet HEX**; `0x00` = OK |
| `A101`/`A102` | Software/Hardware version | Get Info |
| `A103`/`A104` | Terminal / Merchant ID | |
| `A105` | Payment date | |
| `A106` | Approved amount | suma aprobată efectiv |
| `A107` | Host response code | `00`, `Y1`, `Y3` = aprobat |
| `A108` | Response text | mesajul afișat (ex. `TRANZ. ACCEPTATA`) |
| `A109` | Receipt number | folosit ca **STAN** |
| `A10A` | RRN | cheia de reconciliere cu extrasul băncii |
| `A10B` | Auth code | |
| `A10C` | PAN | mascat de terminal (ex. `**3337`) |
| `A10D` | Cardholder | |
| `A10E` | Count | numărul de tranzacții din jurnal |
| `A10F` | Batch | |
| `A110` | Total amount | totalul din jurnal |
| `A112` | Transaction type | `0` = vânzare, `1` = vânzare anulată, `2` = void |
| `A116` | Transaction flags | chip/contactless/etc. (diagnostic) |
| `A117` | Unique ID (răspuns) | `TRX_ID`-ul tranzacției |

### 4.5 Regula de aprobare

O vânzare este **aprobată** doar dacă:

```text
A100 == 0x00   ȘI   A107 ∈ { "00", "Y1", "Y3" }
```

Implementat în `SaleResult.from_tags()`. Dacă `A107` lipsește, plata **nu** este
considerată aprobată. Refuzul normal are `A100 != 0` sau `A107` diferit; codul
`0x09` este tratat ca „Anulat de utilizator”.

### 4.6 Fișierul de rezultat (contract intern PHP ↔ driver)

Driverul scrie **întotdeauna** un fișier text UTF-8 cu linii `CHEIE=VALOARE`:

```text
STATUS=OK|ERROR|UNKNOWN|NOTFOUND
EMITE_BON=DA|NU
MESAJ=text
SUMA=100.00
TRX_ID=TX20261101153000
RRN=...
STAN=...
AUTH=...        (doar la Sale)
PAN=**3337      (doar la Sale)
FOUND=0|1       (doar la resync)
APPROVED=0|1    (doar la resync)
TYPE=0|1|2      (doar la resync)
COUNT=...       (doar la resync)
```

PHP parsează case-insensitive (`posBancaParseResult`) și scrie în audit
`RRN`, `STAN`, `AUTH`, `PAN` și conținutul brut în `Raw` (nu în `Detalii`).

Comenzile auxiliare scriu într-un fișier separat, ca să nu se amestece cu o
plată în curs:

- `rezultat_ecr_resync.txt`
- `rezultat_ecr_void.txt`

---

## 5. Fluxul principal de plată, pas cu pas

### 5.1 Diagrama generală

```text
Casier          POS (browser)            PHP (pos_banca.php)          Driver .py           Terminal
  │  card 100.00    │                            │                        │                   │
  │────────────────►│  POST pay {amount,docId}   │                        │                   │
  │                 │───────────────────────────►│                        │                   │
  │                 │                            │ proc_open vanzare.bat  │                   │
  │                 │                            │───────────────────────►│                   │
  │                 │                            │                        │ EOT (curățare)    │
  │                 │                            │                        │ ENQ ─────────────►│
  │                 │                            │                        │ ◄──────────── ACK │
  │                 │                            │                        │ SALE(100.00,UID) ►│
  │                 │                            │                        │ ◄──────────── ACK │
  │                 │                            │                        │ ◄── frame răspuns │
  │                 │                            │                        │ ACK, EOT          │
  │                 │                            │◄── rezultat_ecr.txt ───│                   │
  │                 │◄─ {emiteBon, posStatus…} ──│                        │                   │
  │                 │ close_bill                 │                        │                   │
  │                 │───────────────────────────►│ inchide nota + cozi    │                   │
  │◄─ bon fiscal ───│                            │ (nota + fiscal + export)                   │
```

Chitanța de card este tipărită de **terminal**, nu de aplicație. Aplicația
tipărește doar bonul fiscal (și nota de plată în modul Restaurant), după ce
terminalul a confirmat plata.

> `pay` este apelat din `bankPosChargeCard()`, adică fie la **OK pe CARD**
> când `PlataCombinata = 1` (taxare per swipe: 60 apoi 40, fiecare cu
> autorizarea ei), fie din `executeClose()` pentru partea de card **neîncasată
> încă** (click pe forma CARD când `PlataCombinata = 0`, card fără terminal,
> după o aprobare parțială). Nu se contactează terminalul la simpla selectare a
> formei de plată și nici când cardul e deja acoperit de swipe-urile aprobate.

### 5.2 Când se lansează efectiv driverul

Driverul este lansat **numai** din `bankPosChargeCard(amount)` (funnel-ul binar
din `js/pos.js`, secțiunea 4b), și numai dacă:

- `POS_STATE.posBanca === 1`;
- există o sumă de card de taxat: `executeClose()` calculează
  `chargeAmount = cardAmount - min(cardLedgerTotal(), cardAmount)`, deci zero
  după un swipe deja aprobat;
- nu există deja o aprobare în ledger care acoperă partea de card.

`executeClose()` este apelat în două situații normale de plată:

1. **Plata combinată (`PlataCombinata = 1`)** — butonul **OK**
   (`finalizePaymentTransaction`):
   - dacă forma selectată este **CARD**, suma tastată (plafonată la restul de
     plată) este taxată **imediat** la terminal prin `bankPosChargeCard()`;
     aprobarea se adaugă în ledger (`cardLedgerAdd`), refuzul nu înregistrează
     nimic în notă;
   - dacă totalul plătit este sub valoarea notei
     (`totalPaid < total - 0.001`) → toast „Plata partiala inregistrata” și se
     așteaptă încă o formă de plată;
   - când totalul plătit acoperă nota (`totalPaid >= total - 0.001`) →
     `executeClose(total, rest)`; partea de card e deja acoperită de ledger,
     deci pasul bancar este sărit și se cheamă `close_bill`.
2. **O singură formă de plată (`PlataCombinata = 0`)** — click pe forma de plată
   (`renderPaymentMethods` → `paySingleForm`):
   - `paySingleForm(fpid)` notează forma (la card scrie
     `POS_STATE.cardDeclared = total`) și apelează imediat
     `executeClose(total, 0)`;
   - dacă forma este **CARD** (`FPID = 1`), dialogul cu terminalul pornește
     **la click**, fără OK și fără introducerea sumei (o singură taxare, la
     închidere);
   - dacă forma este numerar, nu există pas bancar.

Există și apeluri care **nu** contactează terminalul, pentru că partea de card
este 0 sau deja încasată: închiderea notei goale (`closeEmptyNote`), nota cu
valoare 0 (`closeZeroTotalNote`), nota blocată rămasă fără produse
(`closeEmptyUncertainBill`) și preluarea automată a unei plăți deja aprobate
(`bankPosAutoAdoptOnPaymentScreen` — banii sunt în ledger, deci `pay` este
sărit).

După ce `executeClose()` este apelat, pașii sunt:

1. Se calculează `plati = paymentsForClose()` (formele non-card + **o singură**
   linie de card, derivată din ledger) și `cardAmount = plati[1]`.
2. `chargeAmount = cardAmount - min(cardLedgerTotal(), cardAmount)`: se taxează
   doar ce nu a fost încasat. Dacă e 0 → pasul bancar este sărit.
3. Dacă mai e ceva de taxat și ultima încercare a notei este **incertă** → se
   refuză orice formă de plată (inclusiv numerar) și se afișează mesajul de
   clarificare.
4. Dacă `POS_STATE.posBanca === 1` și `chargeAmount > 0` →
   `bankPosChargeCard(chargeAmount)`; aprobarea se adaugă în ledger, apoi se
   recalculează `plati` (aprobare parțială → nota rămâne cu rest de plată).
5. Doar dacă pasul bancar a fost aprobat (sau nu era nimic de taxat) se cheamă
   `close_bill`, care reconciliază din nou cardul (§9.3).

> Ieșirea din ecranul de plată este guvernată de regula „totul sau nimic”
> (§15.8): cu bani aprobați pe card, **Back** cere confirmare și anulează
> tranzacțiile pe terminal (`void_doc`).

> Ordinea este **bancă → casă de marcat**, niciodată invers. Jurnalul din
> consola browserului (`[plata …] banca: start/rezultat`, `casa: start/rezultat`)
> este făcut exact pentru a verifica această ordine.

### 5.3 `POST {action:'pay', amount, docId}`

În `api/pos_banca.php`:

1. **Verificări de activare**
   - `PoSbanca != 1` → `{enabled:false, emiteBon:true}` (fără log, fără driver).
   - `amount <= 0` → același răspuns (nu există parte de card).
2. **Curățenie pentru reîncercare**: toate rândurile nerezolvate ale notei
   (`Actiune IS NULL`) devin `Actiune='reincercat'`, `ResolvedAt=GETDATE()`.
3. **Rezolvarea driverului**
   - `CaleDriverPoSbanca` gol sau fișier inexistent → `configError:true`,
     `emiteBon:true`, `posStatus:'ERROR'`, audit cu `Actiune='config'`.
     Ca în aplicația VBA veche, bonul fiscal se emite totuși, dar se afișează
     eroarea de configurare.
   - `proc_open` eșuat → același tratament.
4. **Pregătirea execuției**
   - `TRX_ID = TX<YmdHis>`.
   - se șterge fișierul de rezultat anterior;
   - `set_time_limit(0)`, `ignore_user_abort(true)`;
   - se scrie antetul în `logs/pos_banca_driver.log`.
5. **Lansare și așteptare** (max. 190 s).
6. **Citirea rezultatului**:
   - fișier lipsă la expirare → audit `Status='TIMEOUT'`, `EmiteBon=0`,
     răspuns `posStatus:'ERROR'`, `emiteBon:false` + coada logului driverului
     în mesaj;
   - fișier prezent → se parsează câmpurile, se normalizează mesajul pentru
     casier (`posBancaUserMessage`), se suprascrie `TRX_ID` cu cel din fișier
     dacă există, se calculează `emiteBon` și `noAnswer`.
7. **Audit** (`posBancaLogAttempt`) + răspuns:

```json
{
  "status": "success",
  "enabled": true,
  "emiteBon": true,
  "posStatus": "OK",
  "posMessage": "TRANZ. ACCEPTATA",
  "noAnswer": false,
  "trxId": "TX20261101153000",
  "amount": "100.00",
  "logId": 123,
  "connection": { "transport": "tcp", "host": "127.0.0.1", "port": "5070" },
  "protocol": "SmartPayECR",
  "canResync": true,
  "canVoid": true
}
```

`emiteBon` este `true` **doar** când `EMITE_BON=DA`; pentru `UNKNOWN` este
forțat `false` chiar dacă driverul ar scrie altceva.

### 5.4 Ce face driverul la `sale`

1. `clear_stale_session()` → `EOT`, golire buffer.
2. `login()` → `ENQ` / `ACK`.
3. Construiește payload-ul:

   ```text
   A000 = 0x02 (Sale)
   A001 = suma (12 cifre ASCII, bani)
   A002 = RON
   A003 = 946
   A008 = TRX_ID
   A007 = 000000000000 (cash back 0)
   ```

4. `command()` → `ACK` de acceptare, apoi frame-ul de răspuns.
5. `SaleResult.from_tags()` → aprobat/refuzat.
6. Scrie fișierul de rezultat **înainte** de afișarea pe consolă (dacă afișarea
   eșuează, POS-ul primește totuși rezultatul).
7. `finally`: `close_session()` (`EOT`, cu redeschidere de port la nevoie) și
   închiderea transportului.

### 5.5 Interpretarea răspunsului în client

`bankPosChargeCard(amount)` — **funnel binar**, care nu atinge nota:

- **`emiteBon === true`**
  - `configError` → se ascunde indicatorul de așteptare, se afișează eroarea,
    dar se continuă (bonul fiscal se emite); rezultatul are `source:'config'`
    și **nu** intră în reconcilierea de pe server (nu sunt bani încasați de
    terminal).
  - altfel → `{ok:true, approved:amount, source:'terminal'}`; apelantul adaugă
    suma în ledger (`cardLedgerAdd`), deci `executeClose` nu mai taxează.
- **`emiteBon === false`** → se cere imediat starea autoritativă din DB
  (`POST {action:'status'}`). Starea din DB decide dacă încercarea este
  **incertă** sau nu (un răspuns pierdut între browser și PHP este tratat
  corect).
  - **nu este incertă** → dialog `PLATA REFUZATA` sau `TERMINALUL NU RASPUNDE`
    (dacă serverul a marcat `noAnswer`); funcția întoarce
    `{ok:false, reason:'declined'|'unreachable'}` și **nimic nu se înregistrează
    în notă**.
  - **este incertă** → verificare automată (secțiunea 7).

Contractul complet:

```js
{ ok:true,  approved, requested, source:'terminal'|'journal'|'manual'|'config', trxId, logId }
{ ok:false, approved:0, requested, reason:'declined'|'unreachable'|'cancelled'|'voided'|'notfound', trxId, logId }
```

Fiecare ramură scrie o decizie în `tblPosBancaLog` (`resolve`/`resolve_manual`)
înainte de a rezolva promisiunea, deci ieșirea este **binară**: banii s-au luat
pentru `approved > 0` sau nu s-a luat nimic. Singura excepție este întreruperea
procesului (reload/crash în timpul `pay`), acoperită de lock-ul notei + banner +
Verifica/Manual și de reînsămânțarea ledger-ului din `status.approvedEntries`.

---

## 6. Cele cinci rezultate posibile

| Situație reală | `STATUS` din fișier | `EMITE_BON` | Încercare incertă? | Dialog / acțiune |
|---|---|---|---|---|
| Terminal aprobă | `OK` | `DA` | nu | fără dialog; `close_bill` + bon fiscal |
| Card refuzat | `ERROR` | `NU` | nu | **PLATA REFUZATA** |
| Utilizator anulează pe terminal | `ERROR` (`Anulat de utilizator.`) | `NU` | nu | **PLATA REFUZATA** |
| Terminalul nu poate fi contactat **înainte** de trimiterea comenzii | `ERROR` (`Nu s-a putut conecta…`) | `NU` | nu (`noAnswer`) | **TERMINALUL NU RASPUNDE** |
| Comanda a plecat (ACK), dar răspunsul lipsește | `UNKNOWN` | `NU` | **da** | verificare automată în jurnal |
| PHP nu primește fișierul de rezultat în 190 s | `TIMEOUT` (în audit; răspunsul are `posStatus:'ERROR'`) | `NU` | **da** | verificare automată în jurnal |

Schema deciziilor:

```text
pay
 ├─ OK ───────────────────────────────► close_bill ──► bon fiscal
 ├─ ERROR (refuz) ────────────────────► PLATA REFUZATA ──► retry / alta metoda
 ├─ ERROR (noAnswer) ─────────────────► TERMINALUL NU RASPUNDE ──► retry / alta metoda
 └─ UNKNOWN / TIMEOUT ──► resync automat
                          ├─ APPROVED=1 ──► preluare silentioasa ──► close_bill
                          ├─ NOTFOUND / deja anulata ──► PLATA NEPROCESATA
                          └─ fara raspuns ──► PLATA NECLARIFICATA
                                               ├─ Da, am chitanta (AUTHCODE) ──► aprobat / respins
                                               └─ Nu a tiparit ──► incercarea se abandoneaza
```

Diferența dintre „terminalul nu răspunde” și „plata neclarificată” este
esențială:

- `EcrLink.sent == false` (conexiune refuzată, port indisponibil, login fără
  `ACK`) → driverul scrie `STATUS=ERROR`; cardul **nu** a putut fi debitat, deci
  reîncercarea este sigură, iar nota rămâne deblocată.
- `EcrLink.sent == true` (comanda a fost scrisă spre terminal, dar nu vine
  `ACK`/răspuns) → driverul scrie `STATUS=UNKNOWN`; cardul **poate** fi debitat,
  deci nu se reîncearcă orbește.

Mesajele cunoscute ale driverului sunt normalizate pentru casier
(`posBancaUserMessage` + `posBancaSmartPayUserMessage`), iar textele brute rămân
în audit și în log. Exemple:

- „Nu s-a putut conecta la POS prin WIFI.” → „Nu s-a putut conecta la
  terminalul bancar (POS).”
- „Eroare de comunicatie cu POS” → „Eroare de comunicatie cu terminalul bancar
  (POS).”
- „Stare necunoscuta…” → mesaj fără termeni tehnici, cu îndemn la verificare.

---

## 7. Ramura UNKNOWN: verificarea automată în jurnal

### 7.1 De ce

După `UNKNOWN`, terminalul poate să fi încasat sau nu. Aplicația **nu** întreabă
operatorul și **nu** afișează loguri: rulează singură `resync` (jurnalul
terminalului) cu ecranul blocat pe indicatorul de așteptare
(`#modal-pos-banca-wait`, text schimbat prin `showBankPosWait`). Rezultatul
ajunge apoi într-un singur dialog, binar: banii s-au luat sau nu.

### 7.2 `POST {action:'resync', docId, uid}`

1. Dacă lipsește `uid`, se ia ultimul `TrxId` al notei cu status
   `ERROR`/`TIMEOUT`/`UNKNOWN`.
2. Dacă există deja o **decizie manuală** după încercare, resync-ul nu mai
   rulează: răspunde `posStatus:'MANUAL'` cu decizia omului (un resync tardiv nu
   are voie să o suprascrie).
3. Se execută:

   ```text
   python smartpay_client.py --timeout 10 --retries 1 resync --uid <TRX_ID> --max-records 5 --out ...
   ```

   Timeout-ul PHP pentru comanda auxiliară este 70 s.

4. `smartpay_client.py resync`:
   - **Report Initialization** (`A000=04`): citește `A10E` = numărul de
     înregistrări din jurnal;
   - parcurge de la ultima înregistrare înapoi (`index = count-1 … max(0, count-5)`);
   - **Report Record** (`A000=05`, `A004=index`): compară `A117` cu `TRX_ID`;
   - dacă îl găsește:
     - `A112 = 0` (sau lipsește) → vânzare normală → `APPROVED=1`;
     - `A112 = 1` sau `2` → deja anulată → `APPROVED=0`;
   - dacă nu îl găsește → `STATUS=NOTFOUND`, `FOUND=0`.

5. PHP scrie un rând `Status='RESYNC'`, `Actiune='verificat'`, cu
   `Mesaj='FOUND=… APPROVED=… …'` (formatul este folosit ca stare de către
   `posBancaDocState`), plus `RRN`, `STAN`, `AUTH`, `PAN`, `Raw`.

### 7.3 Rezultatele verificării

| Jurnal | `posStatus` | `found` / `approved` | Interpretare | Ce vede operatorul |
|---|---|---|---|---|
| Găsită, tip 0 | `OK` | `true` / `true` | Cardul **a fost debitat** | preluare silentioasă, nota se închide |
| Găsită, dar anulată | `OK` | `true` / `false` | Cardul **nu** mai e debitat | **PLATA NEPROCESATA** |
| Negăsită | `NOTFOUND` | `false` / `false` | Cardul **nu** a fost debitat | **PLATA NEPROCESATA** |
| Jurnal mut / eroare | `ERROR` / `UNKNOWN` | — | Nu se poate afla | **PLATA NECLARIFICATA** |

### 7.4 Preluarea aprobării

- Dacă `approved` și suma din jurnal acoperă **exact** partea de card:
  - `resolve` cu `actiune:'aprobat'` (audit),
  - funcția întoarce `{ok:true, approved, source:'journal'}`, iar apelantul
    adaugă suma în ledger (`cardLedgerAdd` → `bankPosMarkApproved`),
  - `executeClose` continuă cu `close_bill` → bon fiscal (pasul bancar este
    sărit, cardul e deja în ledger).
  - **Zero click-uri**, niciun dialog, toast „Plată recuperată cu succes”.
- Dacă aprobarea este **parțială** (terminalul a aprobat mai puțin):
  - se adaugă în ledger doar suma aprobată (plafonată la partea de card),
  - nota rămâne deschisă pentru restul de plată (`executeClose` verifică
    `paidTotal() < total` după taxare și revine la ecranul de plată),
  - nu mai există `bankPosPartial`: starea se citeste din ledger.
- Dacă suma aprobată este **mai mare** decât partea de card:
  - în fluxul automat, nota se închide cu partea necesară (terminalul a încasat
    mai mult — diferența se vede pe chitanța terminalului și în audit);
  - pe ecranul de plată (Verifica / Manual), operatorul este avertizat și nota
    **nu** se închide automat, ca să verifice terminalul;
  - la `close_bill`, reconcilierea semnalează diferența (`409 card_not_approved`
    sau rând `MISMATCH`, vezi §9.3).

### 7.5 Butoanele **Verifica** / **Manual** de pe ecranul de plată

Ambele butoane apar doar cât timp **ultima încercare de plată este nerezolvată**
(`status.uncertain === true`), nu ori de câte ori `PoSbanca = 1`:

- `#btn-pos-banca-check` (Verifica): `uncertain:true` **și** `canResync:true`
  **și** fără decizie deja luată (`manualApproved`/`resyncApproved` false).
- `#btn-pos-banca-manual` („Da, am chitanța (Manual)”): `uncertain:true` **și**
  fără decizie deja luată (`manualApproved`/`resyncApproved` false).

După o **aprobare manuală** care acoperă doar parțial partea de card, nota
rămâne cu rest de plată, dar butoanele dispar: decizia a fost luată, operatorul
trebuie doar să încaseze restul. La fel după un **resync aprobat** (chiar și
parțial). Ele reapar doar dacă apare o **încercare nouă** `UNKNOWN`/`TIMEOUT`
(pentru care `manualApproved`/`resyncApproved` sunt din nou false).

### 7.6 Preluare automată la deschiderea ecranului de plată

`bankPosAutoAdoptOnPaymentScreen()` este apelat din `syncPaymentScreen()`
(deschiderea ecranului de plată). Dacă `status.resyncApproved === true`:

- suma aprobată acoperă exact partea de card → o aplică și **închide nota
  singură** („Plată recuperată cu succes”, fără dialog);
- acoperă doar parțial → o aplică și lasă restul de plată.

Astfel se recuperează și notele rămase blocate după un resync reușit, un reload
de pagină sau o eroare fiscală. În acest caz butonul **Manual** nu se mai
afișează.

---

## 8. Ramura „terminalul nu răspunde”

Condiție: `noAnswer:true` în răspunsul `pay` (calculat de
`posBancaAttemptIsNoAnswer`: `Status='ERROR'` + mesaj de legătură). Driverul a
eșuat **înainte** să trimită comanda, deci cardul nu a putut fi debitat.

Dialog `#modal-pos-banca`:

- titlu: **TERMINALUL NU RASPUNDE**;
- mesaj: „Terminalul bancar nu răspunde. Verificați că este pornit și conectat,
  apoi reîncercați.”;
- **Reîncearcă plata** → retrimite `sale` (TRX_ID nou);
- **Altă metodă / Anulează** → ecranul de plată revine, nota rămâne deschisă;
- **fără** „Da, am chitanța” (nu poate exista chitanță);
- **fără** blocarea notei (nu există incertitudine).

Motivul cel mai frecvent pe stațiile cu COM virtual USB este **USB selective
suspend**; vezi secțiunea de depanare.

---

## 9. Ramura „refuz normal”

Condiție: răspuns `ERROR`, dar **nu** este nici `noAnswer`, nici incert
(ex. „Tranzactie respinsa.”, „Anulat de utilizator.”).

Dialog:

- titlu: **PLATA REFUZATA**;
- mesaj: „Terminalul a refuzat plata. Încercați din nou sau alegeți altă metodă
  de plată.”;
- **Reîncearcă plata** / **Altă metodă / Anulează**.

La anulare, auditul primește `resolve` cu `actiune:'anulat'`. Nota rămâne
deschisă, cu plățile resetate (`resetPaymentScreen`).

---

## 10. Soluționarea manuală (AUTHCODE)

Se ajunge aici când terminalul **nu poate fi citit** (jurnal mut, protocol fără
jurnal) și operatorul trebuie să decidă pe baza chitanței fizice.

### 10.1 Fluxul UI

- **„Da, am chitanța (Manual)”** deschide `#modal-pos-banca-manual`.
- Fereastra afișează doar:
  - **Suma** (mare, bold — elementul pe care operatorul îl corelează cu
    chitanța),
  - **Operator**,
  - câmpul **AUTHCODE** (readonly, exact 6 caractere),
  - acțiunile **Aprobat** / **Înapoi**. Nu mai există „Chitanță Respinsă”;
    pentru „nu a tipărit” se folosește butonul din dialogul anterior
    (`resolve` cu `neaprobat` → `CANCELED_MANUAL`).
- Click pe câmp deschide **tastatura QWERTY** (`#modal-keyboard`, modul
  `bankpos_auth`), afișată peste modalul manual. Tastarea acceptă doar
  `A-Z`/`0-9`, normalizează la majuscule și limitează la 6 caractere; valoarea
  se oglindește live în câmp, iar la a 6-a caracteră tastatura se închide
  automat (OK/Enter o închide și validează).
- **Aprobat** rămâne **dezactivat** până când câmpul are **exact 6 caractere
  alfanumerice**.
- **Înapoi** închide fereastra fără nicio decizie (nota rămâne cum era).
- După o aprobare manuală care lasă rest de plată, butoanele Verifica/Manual de
  pe ecranul de plată dispar (decizia e luată); rămâne doar diferența de încasat.

### 10.2 Reguli server-side (`resolve_manual`)

- `verdict` ∈ `{aprobat, neaprobat}` (API-ul păstrează și `neaprobat`, deși
  modalul nu îl mai expune).
- **Aprobarea cere AUTHCODE** (`cod`): exact 6 caractere `A-Z0-9`, normalizate
  la majuscule; altfel `400 code=auth_code_required`.
- **Neaprobat nu cere cod** (o plată neîncasată poate fi reîncercată).
- Este refuzată dacă nu există o plată bancară incertă pentru notă.
- Un verdict **neaprobat** este refuzat dacă o verificare anterioară a spus deja
  „aprobat” (nu se poate închide cu numerar peste o încasare pe card).
- Scrie un rând `Status='MANUAL'`, `Actiune='aprobat'|'neaprobat'`, cu:
  - `Mesaj` = „Verificare manuala: APROBAT/NEAPROBAT de <nume> (<nrOp>)”;
  - `Detalii` = JSON cu sursă, verdict, `authCode`, `authType='auth'`,
    operator, sumă, TRX_ID, număr notă, IP;
  - `AuthCode` = codul AUTHCODE (coloană **indexată**, pentru reconcilierea cu
    extrasul băncii);
  - `EmiteBon = 1` la aprobat.
- Scrie evenimentul `pos_banca_manual` în `logs/app_events.log`.
- **Nu** compară codul cu jurnalul: dacă resync-ul ar fi extras tranzacția,
  nota s-ar fi închis automat; când se ajunge la manual, terminalul nu a putut
  fi citit, deci nu există nimic cu care să compare. Securitatea vine din
  reconcilierea Z-ului bancar pe baza AUTHCODE-ului.

### 10.3 Efectul deciziei

| Verdict | Starea notei | Ce poate face operatorul |
|---|---|---|
| **Aprobat** | suma rămâne pe card; `status` întoarce `uncertain:true` + `manualApproved:true` | se aplică suma aprobată; dacă acoperă exact partea de card, nota se închide; altfel rămâne rest de plată |
| **Înapoi** | nicio schimbare | poate reveni la „Nu a tipărit (Anulează)” sau poate reîncerca |

După decizie, UI-ul se deblochează imediat (`bankPosUnlockLocal`), fără reload.

---

## 11. Anularea (void)

Suportată **doar** de `SmartPayECR` (capabilitate `void:true`).

`POST {action:'void', docId, stan, amount, uid?}`:

```text
python smartpay_client.py --timeout 10 --retries 1 void --stan <STAN> --amount <SUMA> --out ...
```

- Comandă `A000=06`, `A001=sumă`, `A006=STAN` (zero-padded 6).
- Succes: `A100=0` **și** `A107='00'` → `VOIDED=1`, `Status='VOID'`,
  `Actiune='anulat'`, mesaj „Tranzactie anulata.”.
- Eșec: `Status='VOID'`, mesaj „Anularea a esuat; reincercati mai tarziu.”.
  Din perspectiva stării, un void eșuat înseamnă **plata a rămas încărcată pe
  card** (`resyncApproved:true`) — numerarul rămâne blocat.
- Rândul de audit păstrează `RRN`, `STAN`, `AUTH`, `PAN`, `Raw`.

> În UI-ul actual, butonul de void nu mai este oferit (decizia de produs:
> anularea se face de pe terminal). Endpoint-ul rămâne disponibil pentru
> suport/recuperare și este acoperit de teste.

---

## 12. Blocarea notei cât timp plata este incertă

### 12.1 Definiția incertitudinii

O notă are o plată **incertă** când:

- ultima încercare efectivă are `Status ∈ {UNKNOWN, TIMEOUT}` **sau**
  `Status='ERROR'` cu mesaj de lansare / „stare necunoscuta” / „nu a returnat
  rezultat”;
- **și** nu există o rezolvare ulterioară care să o închidă:
  - `RESYNC` cu „nu apare” / „deja anulata” → nu mai e incertă;
  - `RESYNC` cu `APPROVED=1` → rămâne „incertă” din perspectiva blocării, dar
    `resyncApproved:true` (banii sunt pe card);
  - `VOID` reușit → nu mai e incertă;
  - `VOID` eșuat → `resyncApproved:true`;
  - `MANUAL neaprobat` → nu mai e incertă;
  - `MANUAL aprobat` → incertă + `manualApproved:true`;
  - `CANCELED_MANUAL` („Nu a tipărit”) → nu mai e incertă.

Regula este implementată **o singură dată** în `posBancaDocState()` și este
folosită de `status`, de `close_bill` (indirect, prin client) și de blocarea
notei.

### 12.2 Ce este blocat

Server-side (`orderEnforceBankPosLock` în `api/order_action.php`) răspunde
`423 bank_pos_uncertain` pentru:

`add_product`, `toggle_cook`, `add_mod`, `delete_mod`, `update_qty`,
`apply_discount`, `void_line`, `transfer_products` (și pe nota sursă, și pe cea
destinație).

Rămân permise:

- `close_bill` — este chiar calea de rezolvare;
- `print_kitchen` (Marcare) — bucătăria primește ce s-a adăugat înainte;
- `print_proforma`, `set_cui`, `authenticate`;
- toate acțiunile `api/pos_banca.php` (status/pay/resync/void/resolve/manual).

Blocarea se aplică și când `PoSbanca = 0`, dacă există o încercare incertă
nerezolvată (poate fi clarificată ulterior).

### 12.3 Ce vede operatorul

- `get_order.php` întoarce `bankPosLocked`, `bankPosAmount`, `bankPosUid`.
- Pe ecranul de marcare apare banner-ul `#bank-lock-banner`:
  - text: „Plata pe card nu a fost confirmată. Nota nu poate fi modificată:
    clarificați plata cu butoanele din bara de jos.”;
  - pe nota goală se adaugă „apăsați NOTA pentru a o închide pe zero”;
  - `TRX: <uid>` monospace — identic cu `TRX_ID`-ul din jurnalul terminalului.
- Bara de jos trece în modul de clarificare (`.bank-resolve`):
  - **Verifica** (`#btn-bank-verify`, doar dacă există jurnal),
  - **Altă formă de plată / Anulează** (`#btn-bank-return` → `resolve` cu
    `neaprobat` + TRX_ID, apoi deblocare),
  - **Am chitanța (Manual)** (`#btn-bank-manual` → AUTHCODE).
- Butoanele care modifică nota sunt ascunse (`.bank-locked`), nu doar gri.
- **Marcare** rămâne vizibilă.
- **Nota goală blocată**: `#btn-nota` rămâne vizibil (`bank-locked-empty`);
  un click pe NOTA o închide pe zero, fără dialog
  (`closeEmptyUncertainBill` → `executeClose(0, 0, {allowUncertainEmpty:true})`),
  consemnând în audit `resolve` cu `ignorat` și evenimentul
  `nota_incerta_inchisa_zero`. Terminalul **nu** este contactat — o sumă
  eventual încasată pe card rămâne încasată (desincronizare asumată de
  operator).
- Modulul mobil afișează banner-ul `#mv-bank-lock` și blochează aceleași
  acțiuni; rezolvarea se face doar de pe POS-ul principal.

### 12.4 Garda din client

`executeClose` refuză **orice** formă de plată cât timp starea este incertă,
chiar dacă se încearcă închiderea cu numerar. Se face excepție doar pentru:

- suma de card deja aprobată/confirmată,
- nota goală închisă pe zero (`allowUncertainEmpty`).

Astfel nu se poate produce o dublă încasare (card + numerar) și nici un raport
greșit.

---

## 13. Închiderea notei și bonul fiscal

După aprobarea bancară, clientul cheamă `api/order_action.php`
`{action:'close_bill', docId, plati, cui}`. Serverul:

1. Ia lock-ul de închidere per notă (`sp_getapplock`) — două click-uri rapide nu
   pot emite două bonuri.
2. Marchează nota `Stare='I'` (idempotent: dacă e deja închisă, răspunde
   `alreadyClosed`).
3. Scrie plățile în `trelDocIDFpID` (`FPID → sumă`, inclusiv cardul).
4. În aceeași tranzacție:
   - enqueue `nota` (nefiscal, doar în Restaurant) și `fiscal` (casa de marcat)
     în `tblPrintQueue`;
   - enqueue exportul în `temp_Send_Sql` (dacă `tblSet.Server = 1`).
5. După commit, scrie rândul de audit `Status='CLOSED'` (dacă nota are istoric
   bancar), cu:
   - `EmiteBon=1` dacă a plecat bonul fiscal,
   - `Mesaj` = „Bon fiscal NR x / Z y” sau „Bon fiscal emis (in coada de
     tiparire)”,
   - `Detalii` = plăți, joburi de tipărire, date fiscale, `zeroTotal`.

Nuanțe:

- Dacă `AsteaptaRaspunsCasa = 1` (FiscalWire/FiscalNet), bonul fiscal este
  trimis și confirmat **înainte** de tranzacția de închidere; la succes, jobul
  `fiscal` nu se mai creează (a fost deja tipărit), iar `NrBon`/`NrZ` reale vin
  din răspuns.
  **Atenție (comportament actual):** pe această cale `skipFiscal` este setat, iar
  `posBancaLogClosed()` tratează `skipFiscal` ca „fără bon fiscal”; rândul
  `CLOSED` primește `EmiteBon=0` și mesajul „valoare 0”, deși bonul a fost emis.
  Numărul real rămâne în `Detalii.fiscal` (`nr`, `z`, `bf`). De verificat dacă
  acesta este comportamentul dorit înainte de a ne baza pe `EmiteBon` în rapoarte.
- Nota cu valoare 0 (produse care se anulează reciproc) se închide **fără** bon
  fiscal (`zeroTotal:true`).
- Nota goală se șterge din `tblBonCurent` (nu are ce tipări).
- În FastFood nu se creează jobul `nota`, doar `fiscal`; după închidere se
  revine pe ecranul de marcare, iar detaliile plății rămân în panoul alb.
- **Dacă `close_bill` eșuează după aprobarea pe card** (bază de date, casă de
  marcat), aprobarea rămâne în ledger; următoarea acțiune de închidere
  (**OK** în plata combinată sau click pe **CARD** când `PlataCombinata = 0`)
  reia doar `close_bill`, fără un al doilea `sale` pe card. Mesajul îi spune
  explicit operatorului să nu reîncarce cardul.

### 13.1 Reconcilierea cardului (bani vs. nota)

Înainte de așteptarea casei de marcat, `closeBillLocked()` compară suma
aprobată pe terminal cu linia CARD din notă:

- sursa durabilă este `tblPosBancaLog`, citită de
  `posBancaApprovedTotal($conn, $docId)` (`api/pos_banca_state.php`), care
  grupează pe `TrxId` și ia pentru fiecare tranzacție: aprobare directă
  (`EmiteBon=1`, fără `Actiune='config'`), `RESYNC` cu `APPROVED=1` sau
  `MANUAL` cu `Actiune='aprobat'`; o anulare reușită (`VOID` +
  `Actiune='anulat'`) exclude tranzacția. Gruparea pe `TrxId` este obligatorie:
  rândul `UNKNOWN` și cel `RESYNC` poartă aceeași `Suma`, iar un `SUM` naiv ar
  dubla-o.
- `approvedTotal > plati[1] + 0.005` → **409** `{code:'card_not_approved'}`;
  nota rămâne deschisă (terminalul a încasat mai mult decât arată nota — semn de
  dublă taxare sau de bug în client).
- `plati[1] > approvedTotal + 0.005` → **nu** blochează (cardul poate fi
  încasat în afara terminalului: `PoSbanca` oprit, `configError`, terminal
  extern), dar scrie un rând `Status='MISMATCH'` în `tblPosBancaLog` și
  evenimentul `pos_banca_mismatch` în `logs/app_events.log`.

Aceleași sume sunt expuse de `POST {action:'status'}` ca `approvedTotal` +
`approvedEntries`; clientul le folosește la intrarea pe ecranul de plată
(`cardLedgerSeed`) ca să refacă ledger-ul după un reload/crash — banii încasați
pe card nu se pierd din memorie și nota nu se poate închide fără linia CARD
(sau nu se taxează a doua oară).

---

## 14. Audit: `tblPosBancaLog`

Tabela este creată automat de `ensurePosBancaLogTable()` (și există în
`db/schema.sql`).

| Coloană | Semnificație |
|---|---|
| `Id` | PK identity. |
| `DocID`, `NrDoc` | nota și numărul ei. |
| `Suma` | suma trimisă/confirmată. |
| `TrxId` | `TRX_ID` (același cu `A008`/`A117`). |
| `Status` | `OK`, `ERROR`, `UNKNOWN`, `TIMEOUT`, `RESYNC`, `VOID`, `MANUAL`, `CANCELED_MANUAL`, `CLOSED`, `REPRINT`. |
| `Mesaj` | text pentru diagnoză (normalizat doar în răspunsul către client). |
| `EmiteBon` | pe `OK` = steagul `EMITE_BON` al terminalului; pe `MANUAL aprobat` = 1 (decizia operatorului); pe `CLOSED` = **bonul fiscal** al aplicației. |
| `Actiune` | `aprobat`, `ignorat`, `anulat`, `neaprobat`, `reincercat`, `config`, `verificat`, `dialog_inchis`, `inchis`, `retiparit`. |
| `Detalii` | coada `logs/pos_banca_driver.log` + rezultatul brut al comenzii auxiliare; JSON la `MANUAL`. |
| `Rrn` | RRN (indexat) — reconciliere cu extrasul băncii; completat doar de driver/terminal. |
| `Stan` | numărul bonului terminalului. |
| `AuthCode` | AUTHCODE (indexat) — primit de la driver/terminal sau tastat manual la soluționare (6 caractere A-Z/0-9, majuscule). |
| `CardPan` | PAN mascat (`**3337`) pentru reclamații de dublă debitare. |
| `Raw` | fișierul de rezultat complet (diagnostic de protocol, nu sursă de rapoarte). |
| `CreatedAt`, `ResolvedAt` | momentul încercării / al deciziei. |

Indexuri: `IX_tblPosBancaLog_DocID (DocID, Id)`, `IX_tblPosBancaLog_Rrn (Rrn)` și
`IX_tblPosBancaLog_AuthCode (AuthCode)`.

### 14.1 Ciclul de viață tipic

```text
pay        → Status=OK / UNKNOWN / ERROR / TIMEOUT
status     → (citire, nu scrie)
resync     → Status=RESYNC  Actiune=verificat   Mesaj=FOUND=… APPROVED=…
resolve    → completează Actiune/ResolvedAt pe rândurile nerezolvate
resolve_manual → Status=MANUAL Actiune=aprobat|neaprobat
void       → Status=VOID   Actiune=anulat (sau NULL la eșec)
close_bill → Status=CLOSED Actiune=inchis   EmiteBon=bon fiscal
reprint    → Status=REPRINT Actiune=retiparit (duplicat pe imprimanta POS)
```

`posBancaLatestAttempt()` ignoră `RESYNC`, `VOID`, `MANUAL`, `CLOSED`, `REPRINT`
(dar **păstrează** `CANCELED_MANUAL`, ca nota să fie deblocată după „Nu a
tipărit”). `posBancaApprovedTotal()` exclude `CLOSED` din suma aprobată: deși
are `EmiteBon=1` (bonul fiscal a plecat), `Suma` lui este totalul notei, nu
suma de pe card; `REPRINT` nu are `EmiteBon` și nu influențează totalul.

---

## 15. Cazuri speciale

### 15.1 Plată mixtă

Doar suma de pe `FPID = 1` (card) este trimisă terminalului. Restul poate fi
numerar sau altă formă. Suma este plafonată la cât mai este de plată
(`min(tastat, total - dejaPlatit)`), deci terminalul este taxat exact cu partea
de card, iar restul de dat se calculează doar la numerar.

### 15.2 `PlataCombinata = 0` (click pe forma de plată)

`applyPlataCombinataUI()` ascunde zona de numpad/afișaj. Un click pe o formă de
plată cheamă `paySingleForm(fpid)` și apelează imediat `executeClose(total, 0)`.
Pentru **CARD** (`FPID = 1`), asta înseamnă că dialogul cu terminalul pornește
**la click**, fără OK și fără introducerea sumei; pentru numerar nu există pas
bancar.

Suma folosită este **restul de plată** (`total - paidTotal()`), nu totalul
notei. După un card aprobat parțial (inclusiv refăcut din ledger după un
reload):

- click pe **Numerar** încasează doar diferența (ex. 84 − 50 = 34) și nota
  pleacă la `close_bill` cu `{0: 34, 1: 50}`;
- click pe **CARD** declară toată nota (`cardDeclared = total`), iar
  `executeClose` taxează pe terminal doar partea neacoperită de ledger (34).

Fără asta, un click pe Numerar ar încerca să încaseze tot totalul peste suma de
pe card, iar serverul ar bloca închiderea cu `409 card_not_approved`.

### 15.3 Aprobare deja obținută

Dacă ledger-ul notei (`cardLedgerTotal()`) acoperă partea de card,
`executeClose` **nu** mai apelează terminalul (`chargeAmount` devine 0). Acesta
este mecanismul care previne dubla taxare după:

- fiecare swipe aprobat în plata combinată,
- verificarea „Verifica”,
- preluarea automată la deschiderea ecranului de plată,
- o eroare la `close_bill` după aprobare,
- un reload de pagină (ledger-ul este refăcut din `tblPosBancaLog`).

Suma implicită din câmpul de plată (`#pay-amount-input`) este **restul de
plată**, nu totalul notei: `updatePayAmountDefault()` o scrie la intrarea pe
ecran (și după `resetPaymentScreen`), iar `updatePaymentProgress()` o menține
sincronizată după reînsămânțarea ledger-ului / o aprobare manuală, cât timp
valoarea este cea implicită (`dataset.fresh`), ca să nu fie suprascrisă o sumă
tastată de operator. Exemplu: notă de 84 Lei cu 50 Lei aprobați pe card →
câmpul arată `34.00`, progresul „CARD: 50.00 | Rest: 34.00”. Dacă nota este
deja acoperită integral, câmpul arată `0.00`, iar OK o închide direct:
`finalizePaymentTransaction()` verifică acoperirea **înainte** de validarea
sumei.

**Preluarea este idempotentă.** Fiecare preluare (`Verifica`, `Manual`,
auto-adoptare) trimite `trxId`-ul încercării în `cardLedgerAdd`, iar
`cardLedgerReconcile()` aliniază ledger-ul la `status.approvedEntries` la fiecare
citire de stare: adaugă ce lipsește după un reload și elimină ce nu există în
audit (aprobarile `source='config'`, care nu sunt încasări pe terminal, se
păstrează). Fără această gardă, apăsarea „Verifica” pe o tranzacție deja
preluată (serverul răspunde `MANUAL`/`FOUND` fără să scrie un rând nou) aduna
aceeași sumă a doua oară în memorie: nota părea acoperită, se închidea pe card
fără să fie taxată diferența, iar auditul consemna un `MISMATCH`
(„Card in nota 84.00 Lei, aprobat pe terminal 50.00 Lei”). Din același motiv,
`bankPosAutoAdoptOnPaymentScreen()` nu închide nota când ledger-ul depășește
`approvedTotal` confirmat de server.

Mesajul de după preluare raportează restul calculat din **toate** aprobările
notei (nu doar din încercarea curentă): când pe card există deja o sumă
aprobată, textul devine „Plata aprobata pe terminal: {suma} Lei. Total pe card:
{total} Lei. Mai ramane de plata: {rest} Lei.” (ex. 50 Lei aprobați anterior +
25 Lei acum, notă de 83 → „Total pe card: 75.00 Lei. Mai ramane de plata:
8.00 Lei”), ca să fie în acord cu bannerul notei.

### 15.4 Dublu click / două ferestre

- Client: `POS_STATE.closingBill` + `setClosingState(true)` blochează butonul OK
  și formele de plată; al doilea apel este ignorat.
- Server: `close_bill` este serializat per `DocID` cu `sp_getapplock`; al doilea
  răspunde `409 {alreadyClosing:true}`.

### 15.5 Nota goală / notă cu valoare 0

- Nota fără linii: nu se contactează terminalul; `close_bill` o șterge.
- Nota cu produse dar total 0: se închide fără bon fiscal.
- Nota goală **și blocată** (după un UNKNOWN): se poate închide pe zero din
  NOTA (vezi 12.3).

### 15.6 FastFood

Fluxul bancar este identic. Diferă doar partea de după închidere: fără ecran de
mese, fără job `nota`, fără popup de bon (detalii în panoul alb). Butoanele de
soluționare manuală sunt esențiale aici, pentru că nu există navigare către
ecranul de plată al unei note vechi.

### 15.7 Baza de date indisponibilă

`pay` are nevoie de DB pentru audit. Dacă DB cade **după** aprobarea pe card,
clientul păstrează aprobarea și reia doar `close_bill`. Dacă DB cade înainte,
fluxul se oprește la `getDBConnection`.

### 15.8 Ieșirea din ecranul de plată („totul sau nimic”)

Secvența de plată (pornită din **NOTA**) nu se părăsește cu bani aprobați pe
card. Toate ieșirile din `#screen-plata` trec prin `navigateToScreen()` →
`leavePaymentScreen()`:

- fără sume pe card (doar numerar/altă formă tastată) → se aruncă sumele și se
  navighează, **fără nicio confirmare**;
- cu sume aprobate pe card → confirmare („Ați încasat X Lei pe card. Dacă
  ieșiți, plata se anulează pe terminal (void). Continuați?”), apoi
  `POST {action:'void_doc', docId}`: serverul anulează **toate** tranzacțiile
  aprobate ale notei (STAN-ul se ia din `tblPosBancaLog`), scrie rândurile
  `VOID`/`anulat`, iar clientul golește ledger-ul și navighează;
- dacă void-ul eșuează (terminal mut, protocol fără `canVoid`) → ieșirea este
  **permisă, dar cu avertisment**; sumele pot rămâne încasate pe terminal, iar
  reconcilierea de la `close_bill` le semnalează (`409 card_not_approved`);
- cât timp un swipe este în curs (`POS_STATE.cardPaying`) ieșirea este ignorată.

Consecință: nu există plată parțială pe card păstrată între ecrane, deci nici
banner de „plată neconfirmată” la revenirea pe masă (acela apare doar pentru un
`UNKNOWN` fără nicio decizie). Un card aprobat și neanulat nu poate ajunge
„invizibil” pe notă: `close_bill` cere ca linia CARD să acopere exact sumele
aprobate.

La fel se procedează pentru o **notă goală** rămasă cu bani pe card (posibil
doar după un reload în timpul plății): `closeEmptyNote()` anulează întâi pe
terminal (best-effort), apoi o șterge.

### 15.9 Nota blocată cu bani aprobați (ieșirea din „nota nu se poate închide”)

Dacă pe notă există **bani aprobați dar încă neaplicați** (cazul real: `UNKNOWN`
urmat de resync `APPROVED=1`, sau o aprobare manuală, iar operatorul a ieșit
din ecranul de plată în versiunea veche), nota rămâne blocată pentru modificări,
dar **nu mai este o fundătură**:

- `get_order` întoarce `bankPosApproved` + `bankPosApprovedTotal`;
  `applyBankPosLockUI()` adaugă clasa `bank-locked-payable`, deci **NOTA rămâne
  vizibil**, iar banner-ul spune „Plata pe card a fost confirmată (X Lei). Nota
  nu poate fi modificată: apăsați NOTA pentru a încasa restul de plată.”;
- din NOTA se deschide ecranul de plată, unde suma reapare din ledger
  (`approvedEntries`), se încasează restul și nota se închide cu
  `{1: X, ...rest}` (reconcilierea de la `close_bill` validează);
- **Altă formă de plată / Anulează** nu mai încearcă `resolve neaprobat`
  (refuzat cu `409 bank_pos_approved`), ci cere confirmare și rulează
  `void_doc`: suma se retrage pe terminal, nota se deblochează și poate fi
  închisă cu numerar. În această stare butonul se numește **„Anulează plata pe
  card (void)”**, iar **„Am chitanța (Manual)”** este ascuns (plata e deja
  confirmată electronic, nu mai e nimic de decis manual); dacă void-ul eșuează,
  nota rămâne blocată și se afișează avertismentul (plata se poate continua
  oricum din NOTA).

  `void_doc` funcționează și când auditul nu are STAN-ul (aprobare manuală sau
  răspuns pierdut la vânzare): citește întâi jurnalul terminalului
  (`resync --uid`) și folosește STAN-ul găsit acolo. Dacă jurnalul răspunde
  `NOTFOUND` **și** aprobarea era manuală (deci nu există bani pe terminal),
  aprobarea manuală este **retrasă** din audit (`Actiune='neaprobat'`,
  încercarea devine `CANCELED_MANUAL`) — altfel nota rămânea blocată pe o sumă
  inexistentă. Pentru a testa void-ul cu simulatorul, folosiți
  `mock_pos_server.py --mode accept-silent` (înregistrează vânzarea în jurnal,
  dar nu răspunde): aplicația primește `UNKNOWN`, resync-ul găsește tranzacția
  (STAN), iar void-ul e confirmat. Modurile `silent` / `silent-random` **nu**
  înregistrează vânzarea, deci acolo nu există ce anula.

### 15.10 Chitanța card lipsă (terminalul a rămas fără hârtie)

Protocolul ECR folosit de terminal (SmartPay ECR Link v1.8.3) **nu are** o
comandă de retipărire: comenzile sunt Get Info / Sale / Settlement / Report
Init+Record / Void / etc. Nici driverul Viva ECR nu expune una. Așadar chitanța
**originală** a terminalului se poate retipări doar din meniul lui fizic
(„Reprint last receipt”); aplicația nu poate comanda asta.

Aplicația acoperă cazul cu un **duplicat tipărit pe imprimanta POS**:

- funcția este opțională: butonul apare doar când `tblSet.RetiparireChitantaPoSbanca = 1`
  (Setări → Device-uri → POS Banca, implicit **NU**); serverul refuză `reprint`
  cu `403 reprint_disabled` când setarea este `0`;
- după orice aprobare pe card, în bara ecranului de plată apare butonul
  **„Chitanță card”** (`#btn-banca-reprint`); rămâne disponibil și după
  închidere, în panoul FastFood și în dialogul de succes;
- `POST {action:'reprint', docId, logId?}` alege **ultima tranzacție aprobată**
  a notei din `tblPosBancaLog` (`posBancaLastApprovedTx`), ignorând
  tranzacțiile anulate prin `VOID` și rândul `CLOSED` (al cărui `Suma` este
  totalul notei, nu suma de pe card);
- scrie un job `Tip='banca'` în `tblPrintQueue` (pe `nota_target`) și un rând
  de audit `Status='REPRINT'`, `Actiune='retiparit'`; rândul nu schimbă
  `approvedTotal`, nu devine „ultima încercare” și nu blochează nota;
- duplicatul conține antetul firmei, **DUPLICAT**, data/ora, suma, cardul
  mascat, RRN, STAN, AUTHCODE, terminal/comerciant (când driverul le scrie),
  nota și TRX-ul; mesajul din client spune și cum se retipărește originalul
  din meniul terminalului, cu STAN/RRN-ul pentru identificare.

Duplicatul **nu** este document fiscal și nu ține locul chitanței originale a
terminalului; este o copie informativă pentru client și pentru reconciliere.

---

## 16. Testare

### 16.1 Teste automate

| Test | Ce acoperă |
|---|---|
| `tests/pos_banca.php` (faza C2) | status/manual/resync/void, clasificarea incertitudinii, `noAnswer`, AUTHCODE, `CANCELED_MANUAL`, lock-ul notei, normalizarea valorii legacy `Generic` la `SmartPayECR`, auditul `CLOSED`, `posBancaApprovedTotal` (grupare pe TRX, VOID/config excluse) și reconcilierea de la `close_bill` (`409 card_not_approved` / rând `MISMATCH`). |
| `tests/js_bankpos.php` + `tests/js_bankpos_client.js` (faza JS2) | fluxul real din `js/pos.js` într-un sandbox Node: aprobare directă, UNKNOWN + găsit/negăsit, terminal mut, refuz, retry, aprobare parțială, protocol fără jurnal, preluare automată, banner/lock, codul AUTHCODE, două swipe-uri (60+40), al doilea swipe refuzat, dublu-tap, reînsămânțare după reload, idempotența ledger-ului, un singur `pay` per swipe. |

Rulare completă: `bash tests/run_all.sh` (WSL) sau `tests\run_all.bat`
(Windows). Testele C2 scriu temporar în `tblPosBancaLog` și schimbă
`CaleDriverPoSbanca`/`ProtocolPoSbanca`, cu curățare la final.

### 16.2 Simulatorul de terminal

`tools/pos_banca/mock_pos_server.py` (lansat cu `mock-pos-server.bat`) implementează
Get Information, Sale, Report Initialization, Report Record, Void și Remove Card,
cu jurnal persistent opțional.

Moduri utile:

| Mod | Comportament |
|---|---|
| `ok` | aprobat, intră în jurnal |
| `decline` | refuzat, nu intră în jurnal |
| `cancel` | anulat de utilizator |
| `silent` | ACK, fără răspuns (UNKNOWN), implicit fără jurnal |
| `silent-random` | ca `silent`, 50/50 intră în jurnal |
| `accept-silent` | ACK, înregistrează vânzarea aprobată, fără răspuns |
| `disconnect` | ca `accept-silent`, dar închide conexiunea după ACK |
| `disconnect-random` | ca `disconnect`, 50/50 în jurnal |
| `test_general` | cinci scenarii cu șanse egale: aprobat / refuzat / UNKNOWN găsit în jurnal / UNKNOWN negăsit cu jurnal mut / terminal oprit înainte de login |

Exemplu pe dezvoltare (driver cu `.env` pe TCP `127.0.0.1:5070`):

```bat
tools\pos_banca\mock-pos-server.bat --mode test_general --port 5070 --journal-file jurnal.json
```

Test manual al driverului:

```bat
.venv\Scripts\python.exe C:\Sunmi\smartpay_client.py --transport tcp --host 127.0.0.1 --port 5070 sale 1.00 --uid TEST1
.venv\Scripts\python.exe C:\Sunmi\smartpay_client.py --transport tcp --host 127.0.0.1 --port 5070 resync --uid TEST1
.venv\Scripts\python.exe C:\Sunmi\smartpay_client.py --transport tcp --host 127.0.0.1 --port 5070 void --stan 000001 --amount 1.00
```

---

## 17. Depanare

### 17.1 Unde mă uit

| Simptom | Fișier / tabel |
|---|---|
| Driverul nu scrie rezultatul | `logs/pos_banca_driver.log` (comanda, `pythonDir`, `cwd`, stdout+stderr). |
| Ce a răspuns terminalul | `tblPosBancaLog.Raw`, `Detalii`, `Mesaj`, `Status`, `TrxId`. |
| Ce a decis operatorul | `tblPosBancaLog.Actiune`, `ResolvedAt`, `AuthCode`, `Detalii` (MANUAL). |
| De ce nota e blocată | `get_order.php` → `bankPosLocked/Amount/Uid`; `tblPosBancaLog` ultima încercare + rezolvare. |
| Fluxul banca → fiscal | consola browserului (`[plata …] banca: …`, `casa: …`). |
| Erori de configurare | `pay` cu `configError:true`; mesajul conține calea configurată. |

### 17.2 Probleme frecvente

- **„Python sau script lipsa”** — driverul `.bat` nu găsește Python-ul. Verificați
  `.venv\Scripts\python.exe`, `pyserial` în `.venv` și că Apache are acces la
  folder. Logul driverului conține eroarea reală.
- **`py` greșit** — pe unele mașini există un `py.exe` global fără Python
  înregistrat. `posBancaEnsurePyShim()` creează `py.bat` în `.venv\Scripts`,
  care este primul în `PATH`.
- **Port COM suspendat** — la stațiile cu COM virtual USB, dezactivați **USB
  selective suspend** și „Allow the computer to turn off this device to save
  power”. Un port mut ~3 minute poate face `EOT` să eșueze
  (`ERROR_BAD_COMMAND`), iar terminalul rămâne blocat până la repornire.
- **`UNKNOWN` repetat** — verificați `.env` (transport/host/port), jurnalul
  terminalului, apoi folosiți **Verifica**.
- **Rezultat vechi citit** — nu ar trebui: PHP șterge fișierul înainte de
  lansare. Dacă apar ciudățenii, verificați că `CaleFisierRaspunsPoSbanca`
  indică același fișier pe care îl scrie driverul.
- **Timp de răspuns** — PHP așteaptă max. 190 s; driverul are timeout de răspuns
  180 s. Un terminal blocat poate ține request-ul aproape 3 minute (comportament
  intenționat, ca să nu întrerupă o tranzacție în curs).
- **Driver greșit / valoare veche** — `ProtocolPoSbanca` acceptă doar drivere
  cu identitate clară (`SmartPayECR`). O valoare veche/necunoscută (inclusiv
  `Generic`, eliminat) este normalizată automat la `SmartPayECR`; nu mai există
  un driver generic care să scrie doar fișierul de rezultat. Verificați valoarea
  în Setări dacă driverul nu pornește.

---

## 18. Limite, garanții și securitate

### 18.1 Garanții

- **Fără dublă taxare** în fluxul normal: un singur `pay`; aprobarea este
  reținută și refolosită; `executeClose` ignoră al doilea click.
- **Fără bon fiscal fără plată confirmată**: casa de marcat este contactată
  doar după `emiteBon:true` (sau după preluarea din jurnal/manual).
- **Fără modificări pe notă cât timp plata e incertă** (server-side, 423).
- **Audit complet**: fiecare apel, verificare, decizie și închidere.
- **Idempotență**: `close_bill` serializat per notă; `alreadyClosed`.

### 18.2 Limite cunoscute

- **Aprobarea manuală nu este verificată criptografic** cu terminalul:
  AUTHCODE-ul este capturat și indexat pentru reconcilierea cu extrasul băncii /
  Z-ul bancar. Este o decizie asumată, pentru că exact în acel caz terminalul
  nu poate fi citit.
- **`close_bill` nu verifică singur** că partea de card a fost aprobată;
  garanția este în client + lock-ul de incertitudine. Un apel API direct care
  ocolește clientul poate închide nota fără `pay` — de aceea endpoint-urile sunt
  interne și accesul la aplicație este restricționat prin whitelist-ul de IP-uri.
- **AUTHCODE-ul manual nu este unic** între tranzacții/acquireri; indexul este
  neunic, intenționat.
- **`Raw` și `Detalii`** sunt pentru diagnoză, nu pentru rapoarte; rapoartele
  citesc coloanele structurate (`Rrn`, `Stan`, `Suma`, `Status`).
- **PAN-ul** este doar cel mascat de terminal; aplicația nu primește niciodată
  PAN complet.

### 18.3 Recomandări operaționale

- Reconciliați zilnic Z-ul terminalului cu `tblPosBancaLog` (RRN/AUTHCODE, STAN, sume).
- Nu ștergeți rândurile `UNKNOWN`/`MANUAL` din audit înainte de reconciliere.
- Păstrați `logs/pos_banca_driver.log` la o eventuală reclamație de dublă
  debitare (conține și `PAN`-ul mascat).
- Verificați periodic că `.venv` este funcțional pentru contul care rulează
  serviciile.

---

## 19. Anexă: harta acțiunilor API

`POST api/pos_banca.php` (JSON):

| Acțiune | Body | Efect |
|---|---|---|
| `pay` | `{amount, docId}` | Lansează driverul, scrie audit, întoarce `emiteBon`, `posStatus`, `noAnswer`, `trxId`, `amount`, `logId`, `connection`, `protocol`, `canResync`, `canVoid`. |
| `status` | `{docId}` | Starea autoritativă: `uncertain`, `bankStatus`, `uid`, `amount`, `manualApproved`, `resyncApproved`, `connection`, capabilități. Nu scrie. |
| `resolve` | `{docId, actiune, trxId?}` | Decizia finală: `aprobat`/`ignorat`/`anulat`/`dialog_inchis`/`neaprobat`. `neaprobat` transformă încercarea în `CANCELED_MANUAL` (cu verificări `stale_attempt` / `bank_pos_approved`). |
| `resolve_manual` | `{docId, verdict, cod, nrOp?, sursa?}` | Decizie manuală; `cod` = AUTHCODE (6 caractere A-Z/0-9) obligatoriu pentru `aprobat`. Scrie `Status='MANUAL'` + `AuthCode`. |
| `resync` | `{docId, uid?}` | Verifică terminalul prin adaptorul protocolului (SmartPay: jurnal; Viva: ultima tranzacție). Scrie `Status='RESYNC'`. |
| `void` | `{docId, stan, amount, uid?}` | Anulează prin adaptorul protocolului. Scrie `Status='VOID'`. |
| `reprint` | `{docId, logId?}` | Tipărește un **duplicat** al ultimei tranzacții aprobate pe card (job `banca` + audit `Status='REPRINT'`). Terminalul nu are comandă ECR de reprint; originalul se retipărește din meniul lui. Necesită `RetiparireChitantaPoSbanca = 1`, altfel `403 reprint_disabled`. |

Răspunsurile folosesc `{status:'success'|'error', …}`; erorile de protocol au
`code` (`unsupported_protocol`, `auth_code_required`, `stale_attempt`,
`bank_pos_approved`) și HTTP 400/409.

---

## 20. Al doilea driver: Viva.com ECR Protocol

### 20.1 De ce un protocol separat
Viva.com Terminal poate vorbi cu casa de marcat printr-un protocol local, pe
socket TCP, cu mesaje text (documentația „ECR Protocol” din portalul Viva).
Diferențele față de SmartPay sunt mari la nivel de mesaje, dar mici la nivelul
aplicației: driverul scrie **același fișier de rezultat**, deci plata, auditul,
blocarea notei și dialogurile din client rămân neschimbate. Codul specific este
izolat în `api/pos_banca_viva.php` + `tools/pos_banca/viva_client.py`.

### 20.2 Transport și framing
- Socket TCP către terminal (implicit port **8080**, afișat de terminal când se
  activează ERP/ECR; recomandat IP static).
- Mesaj: primele 4 caractere = `msgLength` (lungimea totală **minus 4**,
  incluzând separatorul de după ele), apoi câmpuri separate prin `|`.
- Flux vânzare: deschidere socket → eventual `txReady` (`810`) → `txSaleRequest`
  (`200`/`00`) → `txSaleResponse` (`210`); `respCode` `00` = aprobat.
- `uniqueTxnId` are **exact 32 caractere** (padding cu spații).

### 20.3 Configurare
Aceleași setări ca la SmartPay (`PoSbanca`, `ProtocolPoSbanca = VivaECR`,
`CaleDriverPoSbanca`, `CaleFisierRaspunsPoSbanca`). Conexiunea se citește din
`.env`-ul de lângă `vanzare.bat`:

```
ECR_HOST=192.168.1.50
ECR_PORT=8080
ECR_TIMEOUT=120
```

Pe stație se copiază `viva_client.py`, `env_config.py` și
`vanzare-viva.bat` (eventual redenumit `vanzare.bat`). Fișierul de rezultat
implicit este `rezultat_ecr.txt` lângă driver; calea configurată în
`CaleFisierRaspunsPoSbanca` este trimisă driverului și prin variabila de mediu
`POS_BANCA_RESULT`.

### 20.4 Comenzi
| Comandă | Rol |
|---|---|
| `sale --amount X --tx ID` | vânzare pe card (200/00) |
| `last-receipt` | ultima tranzacție din terminal (700/710): sumă, RRN, dată |
| `void --stan S --amount X` | anulare/refund (200/04) |
| `probe` | verifică legătura (afișează `txReady`) |
| `selftest` | verifică framing/parsarea pe exemplele din documentație (fără rețea) |

### 20.5 Maparea rezultatelor
| Situație | `STATUS` | `EMITE_BON` | Efect în POS |
|---|---|---|---|
| `respCode=00` | `OK` | `DA` | plată aprobată, notă închisă normal |
| refuz (`51`, `UD`, …) | `ERROR` | `NU` | „PLATA REFUZATA” (fără incertitudine) |
| `UC` (anulat) | `ERROR` | `NU` | „PLATA REFUZATA” / anulat |
| conexiune eșuată **înainte** de trimitere | `ERROR` | `NU` | „TERMINALUL NU RASPUNDE”, notă deblocată |
| comandă trimisă, fără răspuns | `UNKNOWN` | `NU` | verificare automată / „PLATA NECLARIFICATA” |

RRN-ul (12 cifre) este extras din `respMessageResp` (`RRN:...`), STAN-ul din
`refNumResp`, AUTH/PAN din câmpurile dedicate; toate ajung în coloanele din
`tblPosBancaLog`. `transactionId` (GUID) și `orderCode` ajung în `Detalii`.

### 20.6 Verificarea după UNKNOWN (limită de protocol)
Protocolul ECR **nu permite căutarea unei tranzacții după id**. Prin urmare
`resync` citește doar **ultima** tranzacție (`getLastReceipt`) și o confirmă
doar dacă:
1. `respCode=00` și există RRN;
2. suma coincide cu a încercării (±0.01);
3. momentul tranzacției (`TXN_EPOCH`) este în fereastra
   `[CreatedAt − 60s, acum + 120s]`;
4. RRN-ul nu apare deja în `tblPosBancaLog` (ultimele 24h) — altfel s-ar dubla
   suma aprobată.

Dacă toate condițiile sunt îndeplinite, se scrie `RESYNC` cu
`FOUND=1 APPROVED=1`, iar clientul preia plata silențios (fără click-uri). În
orice alt caz se scrie `RESYNC` cu mesaj „NECONFIRMAT” (fără `FOUND=0`), iar
clientul afișează „PLATA NECLARIFICATA” cu soluționare manuală (AUTHCODE). Intenționat
**nu** se raportează niciodată „nu a fost debitat”: o concluzie greșită ar
permite o a doua taxare.

### 20.7 Void
`txCancelRequest` (`200`/`04`) cu `refNum` = STAN-ul din audit. `void_doc`
anulează toate intrările aprobate care au STAN. Fără STAN (aprobare manuală sau
răspuns pierdut la vânzare) tranzacția nu poate fi țintită: intrarea este
raportată la `failed` cu mesajul „anularea se face de pe terminal”, fără să
blocheze nota.

### 20.8 Testare
- `tools/pos_banca/mock_viva_terminal.py` (+ `.bat`) simulează terminalul:
  `ok`, `decline`, `cancel`, `silent`, `accept-silent`, `disconnect`, `random`,
  `test_general`, cu jurnal persistent opțional (`--journal-file`).
- `tests/viva_ecr.php` (faza C3) rulează driverul real contra simulatorului:
  vânzare aprobată/refuzată, `last-receipt`, void, răspuns lipsă (`UNKNOWN`) și
  terminal mut; plus `selftest` și potrivirea sumă/timp/RRN.
- `tests/pos_banca.php` (C2) verifică registrul, normalizarea `VivaECR`,
  capabilitățile și conexiunea citită din `.env` prin API.
- Pe terminalul real: activați ERP/ECR, verificați IP-ul/portul și structura
  răspunsului (modelele Linux și Paydroid au câmpuri suplimentare la coadă;
  parserul citește primele 12 poziții și ignoră restul).

### 20.9 Limite cunoscute
- Terminalul și stația trebuie să fie în aceeași rețea; firewall-ul trebuie să
  permită ieșirea către portul terminalului.
- Un terminal partajat de două stații face potrivirea „ultima tranzacție”
  ambiguă — se recomandă un terminal per stație.
- Fără jurnal interogabil, după un UNKNOWN rămâne soluționarea manuală cu AUTHCODE
  atunci când ultima tranzacție nu corespunde.

---

*Document generat pe baza codului din această versiune. La orice modificare a
fluxului (driver, statusuri, dialoguri, coloane de audit), actualizați acest
fișier împreună cu testele din `tests/pos_banca.php` și
`tests/js_bankpos_client.js`.*
