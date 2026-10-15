/* ==========================================================================
   AJUTOR CONTEXTUAL (help)
   --------------------------------------------------------------------------
   Butoane rotunde "?" in header-ul fiecarui ecran de setari / editor deschid
   modalul #modal-help cu text explicativ in romana: cum functioneaza ecranul
   si ce face fiecare setare / camp. Continutul este pastrat aici (nu in DB),
   ca sa poata fi redactat clar si explicit, independent de descrierile
   afisate sub campuri.

   API minim: openHelp(key) / closeHelp().
   ========================================================================== */

const HELP_CONTENT = {

  /* ------------------------------------------------------------------ SETARI */
  setari: {
    title: "AJUTOR - SETARI",
    html: `
      <p class="help-intro">
        Ecranul <strong>Setari</strong> editeaza configurarea generala a POS-ului
        (tabelul <em>tblSet</em>). Aici se schimba modul de functionare al
        aplicatiei: tipul de vanzare, discountul, casa de marcat, afisajul,
        cantarul, POS-ul bancar si exportul catre server.
      </p>
      <p class="help-tip">
        Setarile sunt impartite in taburi (General / Device-uri / Integrare /
        Servicii / Altele). Modificarea unui camp il marcheaza ca nesalvat; apasati
        <strong>Salveaza</strong> (galben) pentru a scrie toate modificarile
        intr-o singura tranzactie. Butonul <strong>Inapoi</strong> atentioneaza
        daca aveti modificari nesalvate.
      </p>

      <h4 class="help-section">Tab General - grupul Discount</h4>
      <ul class="help-list">
        <li><span class="help-field">RED</span> - permite sau interzice discountul.
          <em>1 = DA</em> (butoanele Discount sunt afisate pe desktop si pe tableta);
          <em>0 = NU</em> (butoanele sunt ascunse, serverul respinge orice discount,
          iar celelalte setari de discount de mai jos devin inactive).</li>
        <li><span class="help-field">TipDiscount</span> - modul de aplicare:
          <em>0</em> = complet (pe produs sau pe subtotal, procent sau valoare, cu
          fereastra de detaliu); <em>1</em> = doar procent pe subtotal (o tastatura
          numerica 0-100); <em>2</em> = procent fix pe subtotal, aplicat direct la
          un singur click, fara fereastra.</li>
        <li><span class="help-field">ProcentDiscountFix</span> - procentul (0-100)
          folosit cand TipDiscount = 2.</li>
        <li><span class="help-field">MotivDiscount</span> - lista de motive oferite
          in fereastra de discount, separate prin punct si virgula (;).</li>
      </ul>

      <h4 class="help-section">Tab General - grupul General</h4>
      <ul class="help-list">
        <li><span class="help-field">NrLogic</span> - numarul logic al statiei /
          casei de marcat (folosit ca StatieID la exportul vanzarilor si la
          facturare).</li>
        <li><span class="help-field">MagID</span> - magazia (gestiunea) folosita la
          import. Mai multe magazii separate prin virgula (ex. 1,2); la suprapuneri
          castiga prima.</li>
        <li><span class="help-field">TipVanz</span> - <em>Restaurant</em> (ecran de
          mese, comanda pleaca la sectie, se poate tipari nota de plata) sau
          <em>FastFood</em> (fara ecran de mese, doar bon fiscal, fara marcare/transfer).
          Butoanele din meniu se ascund automat in FastFood.</li>
        <li><span class="help-field">Server</span> - <em>DA</em>: aplicatia incarca
          grupele si produsele de pe un server extern si exporta vanzarile catre
          acel server (prin sync-service); <em>NU</em>: aplicatia functioneaza
          independent. Cand este DA, butoanele Grupe/Produse din programare se
          blocheaza, iar Sincronizare produse devine activa.</li>
        <li><span class="help-field">CantMax</span> - cantitatea maxima admisa pe o
          linie de nota (protejeaza impotriva tastarii gresite). Implicit 1000.</li>
        <li><span class="help-field">NrZecCant</span> - cate zecimale are cantitatea:
          0, 1 sau 2. In modul Restaurant se poate schimba doar cand toate mesele
          sunt inchise.</li>
        <li><span class="help-field">PlataCombinata</span> - <em>1 = DA</em> (se pot
          combina mai multe forme de plata cu numpad); <em>0 = NU</em> (doar
          butoanele de plata, fara numpad - un click inchide nota integral).</li>
        <li><span class="help-field">MeniulZilei</span> - afiseaza butonul Meniul
          Zilei cand exista produse cu MZ = DA.</li>
        <li><span class="help-field">NrBon</span> - contorul bonurilor de sectie
          (numarul tiparit pe bonul de bucatarie). Se reseteaza la raportul Z si
          poate fi restartat manual de aici.</li>
        <li><span class="help-field">MotivStornare</span> - lista de motive pentru
          anulare (VD), separate prin punct si virgula (;).</li>
        <li><span class="help-field">SerieFactura</span> / <span class="help-field">TextFactura</span>
          - seria facturii si textul de subsol pentru ecranul Facturare.</li>
      </ul>

      <h4 class="help-section">Tab Device-uri - Casa Marcat</h4>
      <ul class="help-list">
        <li><span class="help-field">TipCasaMarcat</span> - driverul casei de
          marcat: FiscalWire, FiscalNet, Fisco sau fprwin.</li>
        <li><span class="help-field">AsteaptaRaspunsCasa</span> - <em>1 = DA</em>:
          nota se inchide doar dupa confirmarea bonului fiscal de catre casa de
          marcat (FiscalWire / FiscalNet); <em>0 = NU</em>: tiparire asincrona,
          ca in modul clasic.</li>
        <li><span class="help-field">AsteptareCasaSec</span> - durata maxima
          (secunde) de asteptare a raspunsului casei de marcat.</li>
        <li><span class="help-field">BonFiscalDiscStorno</span> - afiseaza
          discountul si liniile de stornare pe bonul fiscal (1 = DA, 0 = NU).</li>
        <li><span class="help-field">CaleFisierComenziECR</span> /
          <span class="help-field">CaleFisierRaspunsECR</span> - folderele in care
          se scriu comenzile catre casa de marcat, respectiv de unde se citesc
          raspunsurile.</li>
      </ul>

      <h4 class="help-section">Tab Device-uri - Afiseaj / Cantar / POS Banca</h4>
      <ul class="help-list">
        <li><span class="help-field">AfiseajClient</span> - activeaza afisajul
          client (1 = DA, 0 = NU), cu driverul si folderul de comenzi aferente.
          <strong>In curs de implementare.</strong></li>
        <li><span class="help-field">Cantar</span> - activeaza cantarul electronic
          (1 = DA, 0 = NU), cu driverul si fisierul de raspuns. Cand e DA, butonul
          <strong>QTY</strong> lanseaza driverul, asteapta <em>Delay_cantar</em> si
          scrie cantitatea citita <strong>direct in nota</strong>; daca nu raspunde,
          se afiseaza tastatura numerica pentru introducerea manuala.</li>
        <li><span class="help-field">PoSbanca</span> - activeaza terminalul bancar
          de card (1 = DA, 0 = NU), cu driverul si fisierul de raspuns.</li>
      </ul>

      <h4 class="help-section">Tab Servicii</h4>
      <p>
        Afiseaza starea serviciilor locale Python (tiparire si sincronizare) si
        permite pornirea, oprirea sau repornirea lor. Badge-ul arata ONLINE /
        OFFLINE. Butonul Salveaza nu se foloseste pe acest tab.
      </p>

      <h4 class="help-section">Tab Integrare</h4>
      <p>
        Configureaza <strong>API-ul public pentru aplicatii terte</strong>
        (servere / backend-uri), care ofera export (citire) si import (scriere)
        de date prin <em>/api/v1/</em>. Autentificarea se face cu o singura cheie
        globala, trimisa in headerul <em>X-Api-Key</em>.
      </p>
      <ul class="help-list">
        <li><span class="help-field">Cheie API</span> - apasati
          <strong>Genereaza cheia</strong> pentru a crea o cheie noua. Cheia se
          afiseaza <strong>o singura data</strong>; copiati-o atunci. Se pastreaza
          doar o amprenta (SHA-256), nu cheia in clar. <strong>Regenereaza</strong>
          invalideaza cheia curenta, <strong>Revoca</strong> o sterge.</li>
        <li><span class="help-field">ApiEnabled</span> - <em>DA</em> activeaza
          API-ul, <em>NU</em> il dezactiveaza (implicit NU).</li>
        <li><span class="help-field">ApiAllowedIps</span> - lista de IP-uri /
          retele CIDR permise, separate prin virgula. Setati aici subreteaua
          virtuala a VPN-ului (ex. 192.168.30.0/24) ca al doilea strat de
          aparare. Gol = fara restrictie.</li>
        <li><span class="help-field">ApiRateLimit</span> - numarul maxim de cereri
          pe minut per IP (0 = fara limita).</li>
        <li><span class="help-field">ApiCorsOrigin</span> - rezervat pentru CORS
          (consumatori din browser); lasati gol pentru integrari server-la-server.</li>
      </ul>
      <p class="help-tip">
        Accesul din exterior se face <strong>exclusiv prin VPN</strong>, nu prin
        internet. Recomandat <strong>SoftEther VPN Azure + SecureNAT</strong>:
        SoftEther VPN Server (<a href="https://www.softether.org/5-download"
        target="_blank" rel="noopener">descarcare</a>) ruleaza pe aceasta statie,
        iar clientul (serverul cloud) se conecteaza la hostname-ul
        <em>vpn…vpnazure.net</em>; API-ul se acceseaza apoi prin IP-ul virtual al
        retelei VPN (ex. <em>http://192.168.30.1/rual/api/v1/</em>), fara domeniu,
        certificat sau porturi deschise pe router. Alternativ, o retea
        <strong>Tailscale / ZeroTier</strong>. Nu uitati sa apasati
        <strong>Salveaza</strong> dupa modificarea setarilor.
      </p>

      <h4 class="help-section">Tab Altele</h4>
      <p>
        Contine restul setarilor care nu fac parte din grupurile de mai sus.
      </p>
    `
  },

  /* ------------------------------------------------------------------- ANTET */
  antet: {
    title: "AJUTOR - ANTET",
    html: `
      <p class="help-intro">
        Ecranul <strong>Antet</strong> editeaza datele firmei care apar pe
        documente (bonul fiscal, nota, factura). Se scrie pe randul din
        <em>tblAntet</em> cu Seria <em>0001</em>.
      </p>
      <h4 class="help-section">Cum se foloseste</h4>
      <ul class="help-list">
        <li>Modificati campurile dorite; pentru text folositi butonul
          <strong>&#9000;</strong> (tastatura alfanumerica).</li>
        <li>Campurile numerice (SizeModeLogo) folosesc tastatura <strong>123</strong>.</li>
        <li>Apasati <strong>Salveaza</strong> (galben) pentru a scrie modificarile.</li>
      </ul>
      <h4 class="help-section">Campuri</h4>
      <ul class="help-list">
        <li><span class="help-field">Nume / Denumire2</span> - denumirea firmei.</li>
        <li><span class="help-field">Adresa / Oras / CodPostal / Judet</span> -
          adresa sediului.</li>
        <li><span class="help-field">CodFiscF / RegCom</span> - CUI / cod fiscal si
          numarul de inregistrare Registrul Comertului.</li>
        <li><span class="help-field">Cont / Banca</span> - contul IBAN si banca.</li>
        <li><span class="help-field">PersContact / email / website / telefon</span> -
          date de contact.</li>
        <li><span class="help-field">PlatitorTVA</span> - DA/NU.</li>
        <li><span class="help-field">CaleDateLogo</span> - calea catre imaginea
          siglei, <span class="help-field">SizeModeLogo</span> - modul de
          dimensionare a siglei. <em>(in curs de implementare)</em></li>
        <li><span class="help-field">QRCodeText</span> - textul codat in codul QR
          de pe document. <em>(in curs de implementare)</em></li>
      </ul>
    `
  },

  /* -------------------------------------------------------------------- NOTA */
  nota: {
    title: "AJUTOR - NOTA",
    html: `
      <p class="help-intro">
        Ecranul <strong>Nota</strong> configureaza randurile de antet si de
        subsol tiparite pe nota de plata (si pe proforma). Se editeaza randurile
        <em>H1-H3</em> (antet) si <em>F1-F2</em> (subsol), plus
        <em>P1-P2</em> care apar doar pe proforma.
      </p>
      <h4 class="help-section">Campuri pentru fiecare rand</h4>
      <ul class="help-list">
        <li><span class="help-field">Nume</span> - textul randului (lasati gol
          pentru a nu tipari randul).</li>
        <li><span class="help-field">NumeFont</span> - fontul ESC/POS
          (Font A / B / C / D / E).</li>
        <li><span class="help-field">Size</span> - dimensiunea (1x1, 2x1, 1x2,
          2x2, 3x3).</li>
        <li><span class="help-field">Bold</span> - DA/NU.</li>
      </ul>
      <p class="help-tip">
        Modificarile se salveaza in bloc cu <strong>Salveaza</strong>. Rezultatul
        poate fi vazut in previzualizarea din Coada printare.
      </p>
    `
  },

  /* -------------------------------------------------------------- CONECTARE */
  conectare: {
    title: "AJUTOR - CONECTARE SERVER",
    html: `
      <p class="help-intro">
        Ecranul <strong>Conectare server</strong> configureaza sursa de date
        externa (tabelul <em>tblConectare</em>, un singur rand) folosita la
        sincronizarea produselor si la exportul vanzarilor, plus tabelele de
        mapare server &#8594; local.
      </p>
      <h4 class="help-section">Date de conectare</h4>
      <ul class="help-list">
        <li><span class="help-field">ServerIP / ServerName</span> - adresa / numele
          serverului SQL.</li>
        <li><span class="help-field">DataBaseName</span> - numele bazei de date.</li>
        <li><span class="help-field">UserName / Password</span> - utilizatorul si
          parola (nu se folosesc cand TC = Da).</li>
        <li><span class="help-field">TC (Trusted Connection)</span> - DA = aute
          Windows, NU = user/parola.</li>
        <li><span class="help-field">ODBC_connect_string</span> - sirul ODBC
          complet, daca este folosit direct.</li>
      </ul>
      <p class="help-tip">
        Butonul <strong>Testeaza conexiunea</strong> incearca conectarea cu
        valorile de pe ecran, fara sa salveze.
      </p>
      <h4 class="help-section">Mapari server &#8594; local</h4>
      <ul class="help-list">
        <li><span class="help-field">Mapare sectii de tiparire (KP)</span> -
          translateaza sectia de tiparire din server in cea locala la import.</li>
        <li><span class="help-field">Mapare sectii</span> - translateaza sectia
          (tblProd.Sectie) din server in cea locala la import.</li>
      </ul>
      <p>
        Daca o valoare din server nu are mapare, se pastreaza valoarea din server.
        Folositi butoanele <strong>+ Adauga mapare</strong> si apoi
        <strong>Salveaza</strong>.
      </p>
    `
  },

  /* ------------------------------------------------------------------ PAROLE */
  parole: {
    title: "AJUTOR - PAROLE",
    html: `
      <p class="help-intro">
        Ecranul <strong>Parole</strong> seteaza parolele POS-ului (tabelul
        <em>tblParola</em>, un singur rand). Parolele sunt <strong>numerice</strong>.
        Un camp lasat gol inseamna ca parola respectiva nu este ceruta.
      </p>
      <h4 class="help-section">Parole</h4>
      <ul class="help-list">
        <li><span class="help-field">Parola programare</span> - cere parola pentru a
          intra in ecranul de programare (Setari, Produse, etc.).</li>
        <li><span class="help-field">Parola rapoarte</span> - cere parola pentru a
          deschide ecranul de rapoarte X / Z.</li>
        <li><span class="help-field">Parola stornare</span> - cere parola si motivul
          la anularea (VD) unei linii deja trimise la bucatarie.</li>
        <li><span class="help-field">Parola discount</span> - cere parola inainte de
          aplicarea unui discount.</li>
        <li><span class="help-field">Parola inchidere aplicatie</span> - cere parola
          la inchiderea aplicatiei (exit).</li>
        <li><span class="help-field">Parola update</span> - protejeaza actualizarea
          aplicatiei. Daca este goala, update-ul este blocat.</li>
      </ul>
      <p class="help-tip">
        Apasati <strong>Salveaza</strong> (galben) pentru a scrie modificarile.
      </p>
    `
  },

  /* ----------------------------------------------------------------- PRODUSE */
  produse: {
    title: "AJUTOR - PROGRAMARE PRODUSE",
    html: `
      <p class="help-intro">
        Ecranul <strong>Produse</strong> editeaza catalogul de produse
        (<em>tblProd</em>). Sus se alege grupa, iar grila din stanga arata
        produsele ei pe pozitiile 1-50, exact ca in meniul de vanzare.
      </p>
      <h4 class="help-section">Cum se foloseste</h4>
      <ul class="help-list">
        <li>Alege grupa din lista <strong>Grupa</strong> de sus.</li>
        <li>Apasa un produs de pe grila pentru a-l edita, sau un slot punctat
          (liber) ca sa adaugi un produs nou.</li>
        <li>Modificarile sunt tinute in memorie (marcate cu <em>* nesalvat</em>)
          si se scriu cu <strong>Salveaza</strong>.</li>
        <li><strong>Reload</strong> reincarca datele de pe server (pierde
          modificarile nesalvate).</li>
      </ul>
      <h4 class="help-section">Campuri principale</h4>
      <ul class="help-list">
        <li><span class="help-field">ProdID</span> - codul produsului (editabil
          doar la adaugare).</li>
        <li><span class="help-field">BarCod</span> - codul de bare folosit de
          scaner.</li>
        <li><span class="help-field">Denumire</span> - numele afisat pe meniu si
          pe nota.</li>
        <li><span class="help-field">Grupa (NrGrp)</span> - grupa din care face
          parte produsul.</li>
        <li><span class="help-field">Poz</span> - pozitia in meniu (1-50).</li>
        <li><span class="help-field">PV</span> - pretul de vanzare.</li>
        <li><span class="help-field">Nr_TVA</span> - cota de TVA aplicata.</li>
        <li><span class="help-field">Imprimanta KP</span> - imprimanta de sectie pe
          care pleaca produsul la Marcare.</li>
        <li><span class="help-field">Sectie</span> - sectiunea de raportare.</li>
        <li><span class="help-field">Magazie (MagID)</span> - gestiunea produsului.
          Daca <em>tblSet.MagID</em> contine o singura valoare, campul este
          precompletat cu ea; daca este o lista de id-uri, apare ca lista
          derulanta.</li>
        <li><span class="help-field">UM</span> - unitatea de masura.</li>
        <li><span class="help-field">MZ</span> - daca produsul face parte din
          Meniul Zilei (DA/NU).</li>
      </ul>
      <p>
        Stergerea unui produs cere confirmare. In modul "server" (produsele vin
        din sincronizare) ecranul este blocat.
      </p>
    `
  },

  /* ------------------------------------------------------------------- GRUPE */
  grupe: {
    title: "AJUTOR - PROGRAMARE GRUPE",
    html: `
      <p class="help-intro">
        Ecranul <strong>Grupe</strong> editeaza grupele din meniu
        (<em>tblGrp</em>). Grila din stanga reproduce exact grila de grupe din
        ecranul de vanzare (pozitiile 1-50).
      </p>
      <h4 class="help-section">Cum se foloseste</h4>
      <ul class="help-list">
        <li>Atinge o grupa sau un slot liber de pe grila pentru a o edita.</li>
        <li>Modificarile sunt marcate cu <em>* nesalvat</em> si se scriu cu
          <strong>Salveaza</strong>.</li>
        <li><strong>Reload</strong> reincarca datele de pe server.</li>
      </ul>
      <h4 class="help-section">Campuri</h4>
      <ul class="help-list">
        <li><span class="help-field">NrGrp</span> - codul grupei (editabil doar la
          adaugare).</li>
        <li><span class="help-field">Denumire</span> - numele grupei.</li>
        <li><span class="help-field">Poz</span> - pozitia in meniu (1-50).</li>
        <li><span class="help-field">FontSize / FontType</span> - marimea si
          fontul textului de pe buton.</li>
        <li><span class="help-field">Culoare fundal / Culoare text</span> -
          aspectul butonului (paleta sau culoare proprie).</li>
        <li><span class="help-field">Bold</span> - text ingrosat (DA/NU).</li>
      </ul>
      <p>Gruparea nu are stergere; pentru a scoate o grupa din meniu mutati-o pe
        alta pozitie sau lasati-o fara produse.</p>
    `
  },

  /* -------------------------------------------------------------------- MESE */
  mese: {
    title: "AJUTOR - PROGRAMARE MESE",
    html: `
      <p class="help-intro">
        Ecranul <strong>Programare mese</strong> editeaza aspectul mesei
        (<em>tblMese</em>). Harta din stanga este exact ecranul de mese
        (10 coloane x 8 randuri = 80 de pozitii).
      </p>
      <h4 class="help-section">Cum se foloseste</h4>
      <ul class="help-list">
        <li>Atinge o masa de pe harta pentru a o edita, sau un slot liber ca sa
          adaugi o masa noua (<strong>Adauga masa</strong>).</li>
        <li>Modificarile sunt marcate cu <em>* nesalvat</em> si se scriu cu
          <strong>Salveaza</strong>.</li>
        <li><strong>Reset</strong> sterge tot si recreeaza cele 80 de mese cu
          aspectul implicit (cere confirmare).</li>
        <li><strong>Reload</strong> reincarca datele de pe server.</li>
      </ul>
      <h4 class="help-section">Campuri</h4>
      <ul class="help-list">
        <li><span class="help-field">NrMasa</span> - numarul pozitiei (1-80).</li>
        <li><span class="help-field">Culoare fundal / Culoare text</span> -
          aspectul mesei pe harta.</li>
        <li><span class="help-field">Bold</span> - text ingrosat.</li>
        <li><span class="help-field">Afisez</span> - daca masa apare pe ecranul de
          mese cand este libera (o masa cu nota deschisa se afiseaza oricum).</li>
        <li><span class="help-field">Obs</span> - observatii.</li>
      </ul>
    `
  },

  /* -------------------------------------------------------------- IMPRIMANTE */
  imprimante: {
    title: "AJUTOR - IMPRIMANTE",
    html: `
      <p class="help-intro">
        Ecranul <strong>Imprimante sectii</strong> asociaza fiecarei sectii de
        tiparire (bucatarie, bar, etc.) o imprimanta fizica, si stabileste
        destinatiile pentru nota de plata, rapoarte si casa de marcat.
      </p>
      <h4 class="help-section">Cum se foloseste</h4>
      <ul class="help-list">
        <li>In stanga este lista sectiilor (tabelul <em>tblKP</em>). Alegeti o
          sectie si configurati-i numele, starea si destinatia.</li>
        <li>In dreapta se aleg destinatiile globale: <em>nota</em>,
          <em>rapoarte</em>, <em>casa de marcat</em> si imprimanta implicita.</li>
        <li>Badge-ul de sus arata daca serviciul de tiparire este ONLINE/OFFLINE.</li>
        <li><strong>Salveaza</strong> scrie sectiile in baza de date si
          configuratia de tiparire (serviciul Python o reincarca la cald).</li>
      </ul>
      <h4 class="help-section">Tipuri de destinatie</h4>
      <ul class="help-list">
        <li><span class="help-field">Retea</span> - imprimanta cu IP si port
          (implicit 9100).</li>
        <li><span class="help-field">Windows / USB</span> - o imprimanta locala
          instalata in Windows (inclusiv USB).</li>
        <li><span class="help-field">Preview</span> - nu tipareste fizic; scrie
          continutul in previzualizare (util pana la instalarea imprimantei).</li>
        <li><span class="help-field">Fisier</span> - scrie continutul intr-un
          fisier.</li>
      </ul>
      <p>
        Butonul <strong>Test</strong> (per sectie sau per destinatie) trimite un
        bon de proba.
      </p>
    `
  },

  /* ------------------------------------------------------------------- TVA */
  tva: {
    title: "AJUTOR - COTE TVA",
    html: `
      <p class="help-intro">
        Ecranul <strong>Cote TVA</strong> editeaza tabelul <em>tblTVA</em>:
        lista cotelor de TVA disponibile la produse.
      </p>
      <h4 class="help-section">Cum se foloseste</h4>
      <ul class="help-list">
        <li>Atinge o cota din lista pentru a o selecta.</li>
        <li><strong>Adauga</strong> deschide formularul pentru o cota noua, cu
          tastatura numerica (<strong>123</strong> pentru Nr_TVA, <strong>.</strong>
          pentru zecimale).</li>
        <li><strong>Editeaza</strong> / <strong>Sterge</strong> actioneaza pe cota
          selectata; stergerea cere confirmare.</li>
      </ul>
      <h4 class="help-section">Campuri</h4>
      <ul class="help-list">
        <li><span class="help-field">Nr_TVA</span> - identificatorul cotei.</li>
        <li><span class="help-field">Cota (%)</span> - procentul de TVA.</li>
      </ul>
      <p class="help-tip">
        Preturile din POS sunt cu TVA inclus; cota se foloseste pentru defalcarea
        TVA in rapoarte.
      </p>
    `
  },

  /* ---------------------------------------------------------------- MESAJE */
  mesaje: {
    title: "AJUTOR - MODURI PREPARARE",
    html: `
      <p class="help-intro">
        Ecranul <strong>Moduri preparare</strong> editeaza tabelul
        <em>tblMesaj</em>: textele de "mod preparare" (ex. "fara ceapa",
        "bine facut") care se pot atasa unui produs pe nota.
      </p>
      <h4 class="help-section">Cum se foloseste</h4>
      <ul class="help-list">
        <li>Atinge un mod din lista pentru a-l selecta.</li>
        <li><strong>Adauga</strong> deschide formularul; NrMesaj foloseste
          tastatura <strong>123</strong>, Mesaj foloseste tastatura
          <strong>&#9000;</strong>.</li>
        <li><strong>Editeaza</strong> / <strong>Sterge</strong> actioneaza pe
          modul selectat; stergerea cere confirmare.</li>
      </ul>
      <p>
        Un mod atasat devine un rand separat pe nota, sub produs, si nu modifica
        totalul.
      </p>
    `
  },

  /* -------------------------------------------------------------- OSPATARI */
  ospatari: {
    title: "AJUTOR - OSPATARI",
    html: `
      <p class="help-intro">
        Ecranul <strong>Ospatari</strong> editeaza lista de chelneri / casieri
        (<em>tblOsp</em>). Acestia se autentifica cu parola pentru a putea lua
        comenzi.
      </p>
      <h4 class="help-section">Cum se foloseste</h4>
      <ul class="help-list">
        <li>Atinge un ospatar din lista pentru a-l selecta.</li>
        <li><strong>Adauga</strong> deschide formularul de adaugare.</li>
        <li><strong>Editeaza</strong> / <strong>Sterge</strong> actioneaza pe
          ospatarul selectat; stergerea cere confirmare.</li>
      </ul>
      <h4 class="help-section">Campuri</h4>
      <ul class="help-list">
        <li><span class="help-field">NrOsp</span> - codul (max 2 caractere).</li>
        <li><span class="help-field">Nume</span> - numele afisat.</li>
        <li><span class="help-field">Expl</span> - explicatie / functie.</li>
        <li><span class="help-field">Parola</span> - parola de autentificare pe
          POS.</li>
        <li><span class="help-field">Blocat</span> - DA = nu se poate autentifica.</li>
      </ul>
    `
  },

  /* ----------------------------------------------------------------- SECTII */
  sectii: {
    title: "AJUTOR - SECTII",
    html: `
      <p class="help-intro">
        Ecranul <strong>Sectii</strong> editeaza tabelul <em>tblSectii</em>:
        sectiunile de raportare ale produselor (de exemplu bucatarie, bar).
      </p>
      <h4 class="help-section">Cum se foloseste</h4>
      <ul class="help-list">
        <li>Atinge o sectie din lista pentru a o selecta.</li>
        <li><strong>Adauga</strong> deschide formularul; codul Sectie foloseste
          tastatura <strong>123</strong>, Denumirea tastatura
          <strong>&#9000;</strong>.</li>
        <li><strong>Editeaza</strong> / <strong>Sterge</strong> actioneaza pe
          sectia selectata. Sectiile 1 si 2 sunt protejate si nu pot fi sterse.</li>
      </ul>
      <h4 class="help-section">Campuri</h4>
      <ul class="help-list">
        <li><span class="help-field">Sectie</span> - codul sectiei (cheie).</li>
        <li><span class="help-field">Denumire</span> - numele (max 10 caractere).</li>
      </ul>
    `
  },

  /* ------------------------------------------------------------ FORME PLATA */
  forme_plata: {
    title: "AJUTOR - FORME DE PLATA",
    html: `
      <p class="help-intro">
        Ecranul <strong>Forme de plata</strong> editeaza tabelul <em>tblFP</em>:
        butoanele de plata afisate pe ecranul de inchidere a notei (numerar,
        card, tichete, etc.).
      </p>
      <h4 class="help-section">Cum se foloseste</h4>
      <ul class="help-list">
        <li>Atinge o forma de plata din lista pentru a o selecta.</li>
        <li><strong>Adauga</strong> deschide formularul; FPID si Poz folosesc
          tastatura <strong>123</strong>, Denumirea tastatura
          <strong>&#9000;</strong>.</li>
        <li><strong>Editeaza</strong> / <strong>Sterge</strong> actioneaza pe
          forma selectata; stergerea cere confirmare.</li>
      </ul>
      <h4 class="help-section">Campuri</h4>
      <ul class="help-list">
        <li><span class="help-field">FPID</span> - identificatorul formei de plata
          (<em>FPID = 1</em> este rezervat pentru card / POS bancar).</li>
        <li><span class="help-field">Denumire</span> - textul de pe buton.</li>
        <li><span class="help-field">Poz</span> - ordinea de afisare; forma cu
          Poz = 1 este selectata implicit.</li>
        <li><span class="help-field">Status</span> - 1 = activa (apare pe ecranul
          de plata), 0 = ascunsa.</li>
      </ul>
    `
  },

  rollback: {
    title: "AJUTOR - ROLLBACK",
    html: `
      <p class="help-intro">
        <strong>Rollback</strong> revine la o versiune anterioara a aplicatiei,
        daca versiunea curenta are probleme. Sunt afectate doar fisierele de cod;
        configurarile locale (conectare, imprimante, casa de marcat, POS bancar)
        <strong>nu</strong> sunt atinse. Baza de date nu este modificata.
      </p>
      <h4 class="help-section">Puncte de restaurare (acest calculator)</h4>
      <p>
        Inainte de fiecare update se salveaza automat un snapshot complet al
        versiunii curente. Se pastreaza ultimele 5. Alegeti unul pentru a reveni
        exact la versiunea care rula atunci.
      </p>
      <h4 class="help-section">Versiuni din istoric (GitHub)</h4>
      <p>
        Daca exista internet, sunt listate si ultimele versiuni din istoricul
        GitHub; puteti reveni la oricare dintre ele.
      </p>
      <h4 class="help-section">Cum se foloseste</h4>
      <ul class="help-list">
        <li>Apasati <strong>Rollback</strong>; vi se cere <strong>parola de
          update</strong> (aceeasi ca la Update).</li>
        <li>Alegeti un punct de restaurare sau o versiune din lista.</li>
        <li>Apasati <strong>Aplica rollback</strong> (galben) si confirmati.</li>
        <li>Dupa rollback, <strong>reporniti serviciile</strong> (Setari ->
          Servicii) si reincarcati pagina.</li>
      </ul>
      <p class="help-tip">
        Versiunea curenta este marcata „(curenta)". Dupa un rollback, butonul
        <strong>Update</strong> va oferi din nou trecerea la ultima versiune.
      </p>
    `
  }

};

/* Rezerva pentru tastele care nu au continut definit. */
const HELP_FALLBACK = {
  title: "AJUTOR",
  html: '<p class="help-intro">Nu exista inca un text de ajutor pentru acest ecran.</p>'
};

/**
 * Deschide modalul de ajutor pentru cheia data.
 * @param {string} key Cheia din HELP_CONTENT (ex. "setari", "produse").
 */
function openHelp(key) {
  const data = HELP_CONTENT[key] || HELP_FALLBACK;
  const modal = document.getElementById("modal-help");
  if (!modal) return;
  const title = document.getElementById("modal-help-title");
  const body = document.getElementById("modal-help-body");
  if (title) title.textContent = data.title || "AJUTOR";
  if (body) body.innerHTML = data.html || "";
  modal.classList.add("active");
  if (body) body.scrollTop = 0;
}

/** Inchide modalul de ajutor. */
function closeHelp() {
  const modal = document.getElementById("modal-help");
  if (modal) modal.classList.remove("active");
}

/* Inchidere cu tasta Escape cand modalul este deschis. */
document.addEventListener("keydown", function (e) {
  if (e.key !== "Escape") return;
  const modal = document.getElementById("modal-help");
  if (modal && modal.classList.contains("active")) {
    closeHelp();
  }
});
