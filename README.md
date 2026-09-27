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
- **Printare** — bon de comanda pe sectii, nota de plata, nota proforma si text fiscal, printr-o coada durabila.
- **Rapoarte X** — PLU, Grupe, Sectii, Casieri, General, pe bonurile inchise din sesiunea curenta.
- **Inchidere Z** — arhivare si golire atomica a zilei, cu raport General Z plus rapoartele selectate.
- **Programare** — setari, grupe, produse, mese, ospatari, moduri preparare, parole, TVA,
  imprimante, forme de plata, antet, nota, conectare server si sincronizare produse.

## Tehnologii si avantajele lor

**PHP + MSSQL (extensia `sqlsrv`)** — backend simplu, fara framework.
Avantaje: apel direct de proceduri stocate, tranzactii ACID nativ pentru operatiuni
critice (inchidere bon, marcare, raport Z), zero layer de abstractizare care sa ascunda
comportamentul bazei de date, cod usor de citit si de intretinut.

**JavaScript si CSS pur (fara framework, fara build step)** — tot frontendul este in `js/pos.js`.
Avantaje: nicio dependenta externa, niciun toolchain de compilat, deploy = editi fisierul si
reincarcati pagina; aplicatia porneste instant si nu se strica la un update de pachete.

**Aspectul scalat pe canvas** — design de baza 1024x768, scalat proporțional pe orice ecran.
Avantaj: acelasi layout fix si previzibil pe monitoare si pe tablete, fara CSS fragil de tip vw/vh.

**Coada de printare durabila (tabela `tblPrintQueue` in MSSQL)** — actiunea de business scrie
doar un job in baza de date, in aceeasi tranzactie; un serviciu Python ridica jobul, il randeaza
si il livreaza. Avantaje: printarea nu blocheaza vanzarea, iar daca imprimanta este oprita jobul
ramane `pending` si este reincercat cu backoff pana revine — nimic nu se pierde.

**Serviciu de printare Python + ESC/POS** — `print-service/` randeaza bonurile si le trimite
nativ catre imprimante (Windows raw sau retea :9100). Avantaj: decuplare totala fata de procesul
de vanzare si suport pentru imprimante termice reale, cu un emulator inclus pentru testare.

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

## Cerinte

- **Apache** cu **PHP** si extensia **`php_sqlsrv`** incarcata.
- **Microsoft SQL Server** (baza de date `Rual`).
- **Python 3.12** (Windows) pentru `print-service/` — optional, doar pentru printare.
  Pentru imprimante Windows raw este nevoie de `pip install pywin32`.

## Instalare si configurare

1. Copiati proiectul in directorul servit de Apache (ex. `htdocs/rual`).
2. Configurati conexiunea la baza de date:
   copiati `api/db.local.example.php` in `api/db.local.php` si completati serverul,
   baza de date, utilizatorul si parola.
3. (Optional) Porniti serviciul de printare: `print-service/start-print-service.bat`.
   Adaugati un shortcut in `shell:startup` pentru pornire automata. Serviciul asculta
   doar pe `127.0.0.1:8756`.

## Structura proiectului

```
index.html              interfata (screens, modale, canvas scalat)
js/pos.js               toata logica de frontend (stare globala POS_STATE)
css/pos.css             stilurile aplicatiei
api/                    endpoints PHP (JSON)
  db.php                conexiunea MSSQL (+ db.local.php local, ignorat)
  order_action.php      dispatcher: add_product, close_bill, void_line, apply_discount etc.
  menu.php, get_order.php, get_tables.php
  rapoarte.php          rapoarte X, inchidere Z, printare rapoarte
  print_queue.php       API-ul cozii durabile de printare
  print_common.php      helperi comuni (ensurePrintQueueTable, enqueuePrintJob)
  ...                   editori de configurare (produse, grupe, mese, tva, kp, fp, etc.)
  sql/z_procedure.sql   procedura stocata de inchidere Z
print-service/          serviciu Python de printare ESC/POS
  server.py, worker.py, escpos.py, emulator.py, targets.py, config.json
sync.sh                 commit + push catre GitHub (doar cand exista modificari)
```
