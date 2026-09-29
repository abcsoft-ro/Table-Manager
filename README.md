# TableManager 2.1C

Aplicatie web de tip POS (Point of Sale) pentru restaurante, servita direct de Apache.
Gestioneaza mesele, bonurile, ospatarii, meniul, discounturile, stornarile, platile
si printarea catre bucatarie/bar/nota fiscala, plus rapoartele X si inchiderea Z.

Acest proiect **rescrie de la zero o aplicatie mai veche**, pastrand fluxurile de lucru
cunoscute de operatori, dar reconstruind complet arhitectura pe tehnologii moderne,
usoare si durabile. In loc sa caram mostenirea unui cod vechi, fiecare modul a fost
regandit pentru fiabilitate, intretinere simpla si lipsa dependentelor grele.

## Functionalitati principale

- **Mese si bonuri** — harta de 80 de mese, deschidere/inchidere bon, proprietar pe ospatar.
- **Meniu** — grupe si produse cu preturi, culori, fonturi si dimensiuni configurabile; meniul zilei.
- **Mod preparare (modificatori)** — text atasat unei linii, fara a afecta totalul.
- **Discounturi** — pe linie sau pe bon, procentual sau valoric, distribuite corect pe cotele de TVA.
- **Stornare (VD)** — anulare linii trimise, cu parola de manager si motiv; cantitatile nu pot fi depasite.
- **Transfer produse** intre mese.
- **Plata** — split pe mai multe forme de plata (numerar, card etc.), cu rest afisat.
- **POS bancar (terminal card)** — cand nota are plata pe card si `tblSet.PoSbanca = 1`,
  la inchidere aplicatia comunica sincron cu terminalul bancar printr-un driver extern
  (un folder cu `vanzare.bat`, sau direct un `.bat`/`.cmd`/`.exe`), cu timeout de 190 s,
  dialog **Cancel / Retry / Ignore** la refuz (Cancel pastreaza nota deschisa, Ignore
  inchide nota oricum) si audit al fiecarei incercari (suma, TRX_ID, status, mesaj) in
  `tblPosBancaLog`.
- **Printare** — bon de comanda pe sectii (cu numerotare proprie a bonurilor de sectie),
  nota de plata, nota proforma si rapoarte, printr-o coada durabila; destinatiile fizice
  ale fiecarei sectii se configureaza din aplicatie, cu test de tiparire.
- **Casa de marcat** — fisierul de comenzi pentru driverul fiscal, generat pentru
  **Datecs/FiscalWire** (`.inp`) sau **FiscalNet** (`^`), in functie de `tblSet.TipCasaMarcat`,
  si copiat automat in folderul monitorizat de driver (`tblSet.CaleFisierComenziECR`).
- **CUI (cod fiscal client)** — popup cu tastatura dedicata pe ecranul de plata; codul e validat
  cu cifra de control ANAF si trimis pe bon (`K,...` la Datecs, `CF^...` la FiscalNet).
- **Modul mobil** — pagina dedicata pentru tableta/telefon (`mobile.html`) pentru marcarea
  meselor, independenta de POS-ul desktop, dar folosind aceleasi API-uri.
- **Export catre server** — la inchiderea fiecarei note se scrie o comanda `EXEC EmitBon_Ext_NOU`
  (Restaurant) / `EmitBon_Ext_2` (FastFood) in coada durabila `temp_Send_Sql`; un serviciu Python
  (`sync-service/`) o executa pe serverul din `tblConectare` si marcheaza `Preluat = 1`. Daca serverul
  e oprit sau locatia e offline, comanda ramane in coada si se reincearca **la infinit** (cu backoff),
  pana cand legatura revine; ecranul **Coada export** (Programare) arata starea si permite retry/stergere.
- **Import produse/grupe la pornire** — la deschiderea kiosc-ului, `Pornire-POS-Kiosk.bat`
  ruleaza `import_server_grp_prod.py`, care (doar cand `tblSet.Server = 1`) executa procedura
  stocata `dbo.ImportProd` pentru sincronizarea produselor si grupelor de pe serverul extern.
  Comportamentul cand exista note deschise se alege din parametrul `--if-notes` (`run` implicit,
  sau `skip` pentru a rula doar dupa raportul Z).
- **Pornire kiosc si inchidere** — `Pornire-POS-Kiosk.bat` (in radacina proiectului si pe Desktop)
  deschide aplicatia in mod kiosc fullscreen, cu profil dedicat de browser; parola de inchidere
  (`tblParola.ParolaExit`, tastata in modalul de parola) inchide browserul POS.
- **Rapoarte X** — PLU, Grupe, Sectii, Casieri, General si **Note** (bonurile inchise din
  sesiune, cu detaliul fiecarei note), pe bonurile inchise din sesiunea curenta.
- **Inchidere Z** — arhivare si golire atomica a zilei, cu raport General Z plus rapoartele selectate.
- **Setari operationale** — cantitate maxima pe linie, numar de zecimale la cantitate,
  permitere/respingere discount (cu parola optionala), contorul bonurilor de sectie, tipul
  casei de marcat impreuna cu caile fisierelor de comenzi/raspuns, afisarea discountului si
  stornarii pe bonul fiscal, plus listele de motive pentru discount si stornare.
- **Programare** — setari, grupe, produse, sectii, mese, ospatari, moduri preparare, parole, TVA,
  imprimante sectii (destinatie fizica pe tip de tinta si test de tiparire), forme de plata,
  antet, nota, conectare server si sincronizare produse.

## Tehnologii si avantajele lor

**PHP + MSSQL (extensia `sqlsrv`)** — backend simplu, fara framework.
Avantaje: apel direct de proceduri stocate, tranzactii ACID nativ pentru operatiuni
critice (inchidere bon, marcare, raport Z), zero layer de abstractizare care sa ascunda
comportamentul bazei de date, cod usor de citit si de intretinut.

**JavaScript si CSS pur (fara framework, fara build step)** — frontendul desktop este in
`js/pos.js`, iar cel mobil in `js/mobile.js`.
Avantaje: nicio dependenta externa, niciun toolchain de compilat, deploy = editi fisierul si
reincarcati pagina; aplicatia porneste instant si nu se strica la un update de pachete.

**Aspectul scalat pe canvas** — design de baza 1024x768, scalat proporțional pe orice ecran.
Avantaj: acelasi layout fix si previzibil pe monitoare si pe tablete, fara CSS fragil de tip vw/vh.

**Coada de printare durabila (tabela `tblPrintQueue` in MSSQL)** — actiunea de business scrie
doar un job in baza de date, in aceeasi tranzactie; un serviciu Python ridica jobul, il randeaza
si il livreaza. Avantaje: printarea nu blocheaza vanzarea, iar daca imprimanta este oprita jobul
ramane `pending` si este reincercat cu backoff pana revine — nimic nu se pierde.

**Serviciu de printare Python + ESC/POS** — `print-service/` randeaza bonurile si le trimite
nativ catre imprimante (Windows raw sau retea :9100). Serviciul isi detine fisierul de configurare
`config.json` (il valideaza, il scrie atomic si il reincarca la cald, fara repornire), iar POS-ul
il editeaza printr-un proxy. Avantaj: decuplare totala fata de procesul de vanzare si suport pentru
imprimante termice reale, cu un emulator inclus pentru testare.

**Modul mobil separat (`mobile.html`)** — un al doilea client, pentru tableta/telefon, care
reutilizeaza exact aceleasi API-uri PHP (fara backend duplicat). Avantaj: ospatarii pot marca de pe
terminal propriu, fara sa atinga POS-ul desktop si fara cod sau rute suplimentare pe server.

**Securitate** — interogari cu parametri (prepared statements), parole POS pentru programare,
rapoarte, stornare, discount si iesire, autentificare pe fiecare ospatar cu blocare, iar
credențialele bazei de date stau intr-un fisier local ignorat de git.

## Fiabilitate si siguranta in exploatare

Operatiunile critice (inchiderea bonului, marcarea catre bucatarie, inchiderea Z) se executa
in tranzactii atomice, astfel incat un bon sau o zi nu poate ramane pe jumatate procesat(a).
Printarea este asincrona si durabila: joburile sunt persistate in baza de date si reincercate
automat, deci o imprimanta offline nu pierde si nu blocheaza nicio comanda. Raportul Z foloseste
o procedura stocata care arhiveaza si goleste datele intr-o singura tranzactie cu rollback la
eroare, iar toate accesarile la baza de date folosesc interogari parametrizate, ceea ce elimina
injectia SQL.

## Casa de marcat (fiscal)

La inchiderea notei (`close_bill`), in aceeasi tranzactie, se genereaza fisierul de comenzi
pentru casa de marcat si se scrie in coada durabila de printare; serviciul Python il scrie in
`spool/fiscal` si il **copiaza** in folderul monitorizat de driverul fiscal
(`tblSet.CaleFisierComenziECR`), de unde casa il proceseaza. Daca folderul nu e disponibil,
jobul se reincearca cu backoff, deci bonul nu se pierde.

Tipul casei se alege din **Setari → Casa Marcat** (`tblSet.TipCasaMarcat`):

- **Datecs** (driver FiscalWire) — fisier `.inp`:
  - `H,1,______,_,__;` antet (fara linie `F` la final, ca in procedura veche);
  - `S,1,______,_,__;NUME;PRET;CANT;1;1;NrTVA;0;0;` vanzare (nume majuscule, completat/trunchiat
    la 22 de caractere; pret cu 2 zecimale; cantitate cu 3 zecimale; storno = cantitate negativa);
  - `C,1,______,_,__;1;PROCENT;` discount pe linie (procent simplu, ex. `5.00` = 5%);
  - `K,1,______,_,__;RO12345678;` cod fiscal client, inainte de prima vanzare;
  - `T,1,______,_,__;COD_PLATA;SUMA;` incasare (platile, descrescator dupa cod).
- **FiscalNet** — fisier `.txt` cu separator `^`:
  - `S^NUME^PRET^CANT^buc^GRTVA^1`, `DV^valoare` / `MV^valoare` pentru discount/majorare,
    `VS^...` pentru stornare, `ST^` subtotal, `P^COD_PLATA^SUMA` incasare;
  - `CF^RO12345678` cod fiscal client, inainte de prima vanzare.

Codurile de plata se iau direct din `tblFP.FPID` (0 = Numerar, 1 = Card, 2 = CEC, 3 = Tichet,
4 = OP, 5 = Voucher): la Datecs se scrie `FPID`, la FiscalNet `FPID + 1` (driver-ul foloseste
coduri 1-based). Grupa de TVA se rezolva din `tblTVA` dupa cota liniei (`tblNoteD.TVAc` ->
`tblTVA.Nr_TVA`), la fel ca `DLookup("Nr_TVA","tblTVA","Cota=" & TVAc)` din aplicatia veche.

Implicit, bonul fiscal **nu** afiseaza discountul si nici stornarea: se trimit doar cantitatile
si preturile nete (o linie per produs, cu cantitatea neta dupa stornari). Setarea
`tblSet.BonFiscalDiscStorno = 1` activeaza modul detaliat (linii de discount/majorare `C`/`DV`/`MV`
si linii de stornare `VS`).

CUI-ul se introduce dintr-un popup deschis cu butonul **CUI** de pe ecranul de plata, este
validat cu cifra de control ANAF (algoritmul CUI/CIF) si tinut per nota (se goleste la
schimbarea notei si dupa inchiderea bonului); se poate sterge cu butonul „Fara cod fiscal".

## Cerinte

- **Apache** cu **PHP** si extensia **`php_sqlsrv`** incarcata.
- **Microsoft SQL Server** (baza de date `Rual`).
- **Python 3.12** (Windows) pentru `print-service/` — optional, doar pentru printare.
  Pentru imprimante Windows raw este nevoie de `pip install pywin32`.
- **Python 3.12** (Windows) pentru `sync-service/` — optional, doar daca se foloseste
  exportul catre serverul extern. Are nevoie de `pip install pyodbc` si de un
  **ODBC Driver for SQL Server** instalat.
- **Python 3.12** (Windows) pentru `import_server_grp_prod.py` — optional, doar daca se
  foloseste importul produselor/grupelor la pornire. Are nevoie de `pip install pyodbc`
  si de un **ODBC Driver for SQL Server** (aceleasi ca la `sync-service/`). Daca lipseste,
  kiosc-ul porneste normal, iar importul este doar sarit.

## Instalare si configurare

1. Copiati proiectul in directorul servit de Apache (ex. `htdocs/rual`).
2. Reconstruiti baza de date ruland `db/schema.sql` apoi `db/seed.sql`
   (detalii in [db/README.md](db/README.md)).
3. Configurati conexiunea la baza de date:
   copiati `api/db.local.example.php` in `api/db.local.php` si completati serverul,
   baza de date, utilizatorul si parola.
4. (Optional) Porniti serviciul de printare: `print-service/start-print-service.bat`.
   Adaugati un shortcut in `shell:startup` pentru pornire automata. Serviciul asculta
   doar pe `127.0.0.1:8756`. Destinatiile fizice (sectii si tintele globale Nota/Rapoarte/
   Fiscal) se configureaza din **Setari → Imprimante sectii**, cu butoane de test; modificarile
   se aplica instant (hot-reload), fara repornirea serviciului.
5. (Optional) Porniti serviciul de export catre server: `sync-service/start-sync-service.bat`
   (sau `start-sync-service-hidden.vbs` pentru rulare in fundal, fara consola). Adaugati un
   shortcut in `shell:startup` pentru pornire automata. Serviciul asculta doar pe `127.0.0.1:8757`,
   citeste serverul/baza/credentialele din `tblConectare` (ID = 1) si executa pe acel server
   comenzile scrise in `temp_Send_Sql`. Necesita `pip install pyodbc`.
6. (Optional) Folositi `Pornire-POS-Kiosk.bat` (in radacina proiectului sau pe Desktop) pentru a
   deschide aplicatia in mod kiosc fullscreen. La pornire, acelasi bat ruleaza si
   `import_server_grp_prod.py` (importul produselor/grupelor de pe server, cand
   `tblSet.Server = 1`); se poate pune un shortcut in `shell:startup` pentru pornire automata.

## Reconstructie baza de date

Structura bazei de date si datele de referinta sunt publicate in directorul `db/`,
astfel incat oricine poate reconstrui o baza functionala:

- `db/schema.sql` — tabelele, indecsii, constrangerile, cheile straine si procedurile stocate.
- `db/seed.sql` — date de referinta minime plus un meniu demo (fara date reale).

Pasii sunt: rulati `db/schema.sql`, apoi `db/seed.sql`, apoi configurati `api/db.local.php`.
Instructiuni complete: [db/README.md](db/README.md).

## Structura proiectului

```
index.html              POS-ul desktop (screens, modale, canvas scalat)
js/pos.js               toata logica POS-ului desktop (stare globala POS_STATE)
css/pos.css             stilurile POS-ului desktop
mobile.html             clientul mobil/tableta (marcare mese)
js/mobile.js            logica modulului mobil (stare globala MOBILE_STATE)
css/mobile.css          stilurile modulului mobil
api/                    endpoints PHP (JSON)
  db.php                conexiunea MSSQL (+ db.local.php local, ignorat)
  order_action.php      dispatcher: add_product, close_bill, void_line, apply_discount etc.
  menu.php, get_order.php, get_tables.php
  rapoarte.php          rapoarte X, inchidere Z, printare rapoarte
  print_queue.php       API-ul cozii durabile de printare
  print_common.php      helperi comuni (ensurePrintQueueTable, enqueuePrintJob)
  send_queue.php        API-ul cozii durabile de export catre server (temp_Send_Sql)
  send_common.php       helperi comuni (ensureSendSqlTable, enqueueBillSendSql)
  print_config.php      proxy catre serviciul de printare (citire/salvare config, test)
  pos_banca.php         comunicarea cu terminalul bancar (POS bancar) + audit
  exit_app.php          inchide browserul POS din parola de inchidere
  sync_products.php     ruleaza procedura stocata dbo.ImportProd (Sincronizare produse)
  ...                   editori de configurare (produse, grupe, mese, tva, kp, fp, etc.)
  sql/z_procedure.sql   procedura stocata de inchidere Z
print-service/          serviciu Python de printare ESC/POS
  server.py, worker.py, escpos.py, emulator.py, targets.py, config.py, config.json
sync-service/           serviciu Python de export catre serverul extern (temp_Send_Sql)
  server.py, worker.py, db.py, config.py, config.json
db/                     reconstructia bazei de date
  schema.sql            structura tabelelor + proceduri stocate
  seed.sql              date de referinta + meniu demo (fara date reale)
Pornire-POS-Kiosk.bat   lansator kiosc (fullscreen) + import produse la pornire
import_server_grp_prod.py  import produse/grupe de pe server (dbo.ImportProd)
sync.sh                 commit + push catre GitHub (doar cand exista modificari)
```
