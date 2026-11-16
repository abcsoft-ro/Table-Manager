Drivere POS bancar (SmartPay ECR Link + Viva.com ECR Protocol)
==============================================================

Aplicatia are doua drivere Python pentru POS-ul bancar (api/pos_banca.php).
Fiecare are o identitate clara si un adaptor dedicat; nu exista un driver
"generic" care sa scrie doar fisierul de rezultat. Selectia se face din
`tblSet.ProtocolPoSbanca` (Setari -> Device-uri -> POS Banca):

  SmartPayECR   SmartPay ECR Link (implicit). Fisiere: smartpay_client.py +
                env_config.py; transport serial sau TCP, jurnal, resync, void.
  VivaECR       Viva.com Terminal, ECR Protocol TCP/IP. Fisiere: viva_client.py
                + env_config.py; socket TCP (implicit port 8080), verificare cu
                ultima tranzactie din terminal, void dupa STAN.

Ambele drivere sunt lansate de POS cu `suma txId`, citesc conexiunea din `.env`
(aflat langa `vanzare.bat`, folderul din `tblSet.CaleDriverPoSbanca`) si scriu
fisierul din `tblSet.CaleFisierRaspunsPoSbanca` (sau `rezultat_ecr.txt` langa
driver) cu liniile STATUS, MESAJ, TRX_ID, EMITE_BON (+ RRN / STAN / AUTH / PAN).
Calea fisierului de rezultat este trimisa si in variabila de mediu
`POS_BANCA_RESULT`, ca sa functioneze si cand `.bat`-ul nu cunoaste setarea.

`vanzare.bat` si `.env` ramin pe stație (`.env` are adresa/portul terminalului si
nu se suprascrie). Driverele folosesc mediul virtual al aplicatiei
(`.venv\Scripts\python.exe`).

Install SmartPay (folderul driverului, ex. `C:\ABCSoft\Sunmi`):

    copy tools\pos_banca\smartpay_client.py C:\ABCSoft\Sunmi\smartpay_client.py
    copy tools\pos_banca\env_config.py C:\ABCSoft\Sunmi\env_config.py

Numele vechi `ecr_client.py` rămâne acceptat: aplicatia cauta intai
`smartpay_client.py` si, daca nu exista, foloseste `ecr_client.py` din folderul
driverului. Astfel statiile care au deja fisierul copiat continua sa mearga
dupa update; pentru instalari noi folositi `smartpay_client.py` (si actualizati
`vanzare.bat` sa ruleze acest fisier).

Comenzi SmartPay (pe langa `info` si `sale`, folosite de POS):

    report-init                 numarul de tranzactii din jurnalul terminalului
    report-record --index N     detaliile unei tranzactii din jurnal
    resync --uid <TRX_ID>       re-sincronizare dupa conexiune pierduta
                                (cauta UID-ul in jurnal; --void anuleaza)
    void --stan <STAN> --amount <SUMA>
    remove-card

Rezultatul este un fisier text cu linii CHEIE=VALOARE (STATUS, FOUND,
APPROVED, STAN, SUMA, TYPE, MESAJ). `STATUS=UNKNOWN` la o vânzare inseamna ca
terminalul a acceptat comanda dar nu a trimis raspuns: nu se reincearca orb,
se ruleaza `resync` si, daca plata apare aprobata, `void` sau inchiderea notei
cu suma deja aprobata (butonul "Verifica" din POS).


Viva.com Terminal (ECR Protocol)
--------------------------------
Documentatia protocolului:
https://developer.viva.com/apis-for-point-of-sale/card-terminals-devices/vivawallet-api-cl/

Pe terminal trebuie activat ERP/ECR (Settings); terminalul afiseaza IP-ul si
portul (implicit 8080). Recomandat IP static.

Install (folderul driverului, langa `.env`):

    copy tools\pos_banca\viva_client.py C:\ABCSoft\Viva\viva_client.py
    copy tools\pos_banca\env_config.py  C:\ABCSoft\Viva\env_config.py
    copy tools\pos_banca\vanzare-viva.bat C:\ABCSoft\Viva\vanzare.bat

`.env`:

    ECR_HOST=192.168.1.50
    ECR_PORT=8080
    ECR_TIMEOUT=120

Comenzi (pe langa `sale`, folosita de POS):

    last-receipt        ultima tranzactie din terminal (suma, RRN, data) -
                        folosita de butonul "Verifica" dupa un UNKNOWN
    void --stan <STAN> --amount <SUMA>
    probe               verifica legatura (afiseaza txReady)
    selftest            verifica framing/parsarea pe exemplele din documentatie

Verificarea automata dupa UNKNOWN este intentionat conservatoare: protocolul nu
permite cautarea unei tranzactii dupa id, deci aplicatia confirma plata doar
daca ultima tranzactie din terminal are aceeasi suma, un moment apropiat de
incercare si un RRN care nu mai apare in audit. In orice alta situatie nota
rămâne in "PLATA NECLARIFICATA" si se rezolva manual (RRN de pe chitanta).
Void-ul are nevoie de STAN-ul din audit; daca lipseste (aprobare manuala sau
raspuns pierdut), anularea se face de pe terminal.

Simulator Viva (mock_viva_terminal.py)
--------------------------------------
Se porneste cu `mock-viva-terminal.bat` (sau direct cu Python din `.venv`).
Moduri `--mode`: ok, decline, cancel, silent, accept-silent, disconnect,
random, test_general. Jurnalul persistent se activeaza cu `--journal-file`.

    tools\pos_banca\mock-viva-terminal.bat --mode ok --port 8090
    tools\pos_banca\mock-viva-terminal.bat --mode accept-silent --port 8090 --journal-file jurnal_viva.json
    tools\pos_banca\mock-viva-terminal.bat --mode test_general --port 8090

Test din linia de comanda (cu `.env` pe 127.0.0.1:8090 sau cu --host/--port):

    .venv\Scripts\python.exe viva_client.py --host 127.0.0.1 --port 8090 sale --amount 1.00 --tx TEST1
    .venv\Scripts\python.exe viva_client.py --host 127.0.0.1 --port 8090 last-receipt
    .venv\Scripts\python.exe viva_client.py --host 127.0.0.1 --port 8090 void --stan 000001 --amount 1.00
    .venv\Scripts\python.exe viva_client.py selftest


Simulator pentru testare (mock_pos_server.py)
---------------------------------------------
Simulatorul TCP implementeaza Get Information, Sale, Report Initialization,
Report Record, Void si Remove Card, cu jurnal persistent optional. Se porneste
cu `mock-pos-server.bat` (sau direct cu Python din `.venv`).

Moduri `--mode`:
    random                 70% aprobat / 20% refuzat / 10% anulat (implicit)
    ok                     aprobat, intra in jurnal
    decline                refuzat, nu intra in jurnal
    cancel                 anulat de utilizator, nu intra in jurnal
    silent                 ACK, fara raspuns (clientul asteapta pana la timeout);
                           implicit nu intra in jurnal
    silent-random          ca silent, dar 50/50 intra sau nu in jurnal
    accept-silent          ACK, inregistreaza vanzarea aprobata in jurnal, fara raspuns
    accept-silent-random   ca accept-silent, dar 50/50 intra sau nu in jurnal
    disconnect             ca accept-silent, dar inchide conexiunea dupa ACK
                           (clientul primeste repede UNKNOWN, ideal pentru testul POS)
    disconnect-random      ca disconnect, dar 50/50 intra sau nu in jurnal
                           (testeaza ambele ramuri: APPROVED / NOT FOUND)
    test_general           test cap-coada, cu probabilitate egala (1/5) pentru
                           fiecare situatie reala de la POS:
                             1. aprobat                      -> bon fiscal
                             2. neaprobat                    -> PLATA REFUZATA
                             3. unknown, dar in jurnal       -> Verifica il gaseste,
                                nota se inchide automat (zero click-uri)
                             4. unknown, nu e in jurnal si nici verificarea nu
                                primeste raspuns              -> PLATA NECLARIFICATA
                                (solutionare manuala)
                             5. terminalul nu raspunde deloc -> TERMINALUL NU
                                RASPUNDE (conexiunea se inchide inainte de login,
                                deci driverul nu apuca sa trimita comanda)
                           Verificarea automata de dupa o vanzare incerta nu este
                           sabotata, ca sa se poata testa si deblocarea automata.

Probabilitatea se poate fixa si cu `--journal-prob 0..1` (ex. `0.5`).
Implicit: 1.0 pentru `accept-silent`/`disconnect`, 0.0 pentru `silent`,
0.5 pentru variantele `*-random`.

Exemplu pe masina de dezvoltare (driverul are `.env` pe TCP 127.0.0.1:5070):

    tools\pos_banca\mock-pos-server.bat --mode ok --port 5070
    tools\pos_banca\mock-pos-server.bat --mode disconnect --port 5070 --journal-file jurnal.json
    tools\pos_banca\mock-pos-server.bat --mode disconnect-random --port 5070 --journal-file jurnal.json
    tools\pos_banca\mock-pos-server.bat --mode disconnect --journal-prob 0.5 --port 5070 --journal-file jurnal.json
    tools\pos_banca\mock-pos-server.bat --mode test_general --port 5070 --journal-file jurnal.json

Test din linia de comanda:

    .venv\Scripts\python.exe C:\Sunmi\smartpay_client.py --transport tcp --host 127.0.0.1 --port 5070 sale 1.00 --uid TEST1
    .venv\Scripts\python.exe C:\Sunmi\smartpay_client.py --transport tcp --host 127.0.0.1 --port 5070 resync --uid TEST1
    .venv\Scripts\python.exe C:\Sunmi\smartpay_client.py --transport tcp --host 127.0.0.1 --port 5070 void --stan 000001 --amount 1.00

Testul complet (toate cazurile, in orice ordine): `--mode test_general`. Fiecare
plata pe card primeste una din cele cinci situatii de mai sus, cu sanse egale;
in consola simulatorului scrie scenariul ales pentru fiecare vanzare
(`test_general: scenariu "..."`), iar la `unknown-mute` si
`jurnalul nu raspunde -> resync fara raspuns`. Repetati plata pana vedeti toate
cele cinci dialoguri. Nota rămâne deschisa la oricare dintre ele (nimic nu se
inchide fara un bon fiscal aprobat), deci puteti continua pe aceeasi masa.

Pentru testul complet din POS: porniti simulatorul cu `--mode disconnect
--journal-file jurnal.json`, faceti o plata pe card (POS-ul primeste UNKNOWN),
apoi apasati **Verifica** pe ecranul de plata; simulatorul are deja vanzarea
in jurnal, deci apare ca aprobata si puteti testa void sau inchiderea notei
fara o noua taxare.

Pentru ambele ramuri, porniti cu `--mode disconnect-random` (sau
`--mode disconnect --journal-prob 0.5`). La fiecare plata POS-ul primeste
UNKNOWN si ruleaza automat Check-ul: daca vanzarea a intrat in jurnal apare
**APPROVED** (Void / Keep), daca nu a intrat apare **NOT FOUND** (Retry /
Cancel, apoi inchidere cu numerar). In consola simulatorului se vede pentru
fiecare vanzare daca a fost inregistrata sau nu. `--mode decline` /
`--mode cancel` testeaza dialogul simplu.
