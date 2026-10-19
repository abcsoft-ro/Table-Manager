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
        <li><span class="help-field">MarcareMobil</span> - permite marcarea de pe
          tableta/telefon (pagina mobil): <em>DA</em> = modulul mobil este activ;
          <em>NU</em> (implicit) = pagina mobil afiseaza doar un mesaj de blocare.
          POS-ul desktop nu este afectat.</li>
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

      <h4 class="help-section">Tab General - grupul Securitate</h4>
      <ul class="help-list">
        <li><span class="help-field">IpPermise</span> - whitelist-ul de adrese IP /
          retele CIDR care pot accesa aplicatia. Localhost (127.0.0.1 / ::1)
          este intotdeauna permis si nu poate fi sters; o lista goala sau doar
          cu localhost = doar localhost are acces. Adaugati IP-urile tabletelor
          si ale stațiilor (sau o retea intreaga, ex. 192.168.1.0/24). Salvarea
          unei liste care nu contine IP-ul de pe care lucrati este refuzata.</li>
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
        <li><span class="help-field">EvidDiscBonFisc</span> - afiseaza discountul
          pe bonul fiscal, pe fiecare linie de produs (1 = DA, 0 = NU). Se refera
          numai la discount.</li>
        <li><span class="help-field">EvidStornoBonFisc</span> - afiseaza liniile
          de stornare pe bonul fiscal (1 = DA, 0 = NU). Se refera numai la
          stornare si se seteaza independent de discount.</li>
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

/* ENGLISH help content (mirrors HELP_CONTENT keys). Used when window.LANG = "en". */
const HELP_CONTENT_EN = {

  setari: {
    title: "HELP - SETTINGS",
    html: `
      <p class="help-intro">
        The <strong>Settings</strong> screen edits the general POS configuration
        (<em>tblSet</em> table). Here you change how the application works: sale
        type, discount, fiscal printer, customer display, scale, bank POS and
        server export.
      </p>
      <p class="help-tip">
        Settings are grouped in tabs (General / Devices / Integration / Services /
        Other). Editing a field marks it as unsaved; press <strong>Save</strong>
        (yellow) to write all changes in a single transaction. The
        <strong>Back</strong> button warns if you have unsaved changes.
      </p>

      <h4 class="help-section">General tab - Discount group</h4>
      <ul class="help-list">
        <li><span class="help-field">RED</span> - allow or forbid discounts.
          <em>1 = YES</em> (Discount buttons shown on desktop and tablet);
          <em>0 = NO</em> (buttons hidden, the server rejects any discount and the
          other discount settings below become inactive).</li>
        <li><span class="help-field">TipDiscount</span> - apply mode:
          <em>0</em> = full (per product or subtotal, percent or value, with a
          detail window); <em>1</em> = percent on subtotal only (a numeric keypad
          0-100); <em>2</em> = fixed percent on subtotal, applied directly with a
          single click, no window.</li>
        <li><span class="help-field">ProcentDiscountFix</span> - the percent (0-100)
          used when TipDiscount = 2.</li>
        <li><span class="help-field">MotivDiscount</span> - the list of reasons
          offered in the discount window, separated by semicolon (;).</li>
      </ul>

      <h4 class="help-section">General tab - General group</h4>
      <ul class="help-list">
        <li><span class="help-field">NrLogic</span> - the logical number of the
          station / fiscal printer (used as StatieID on export and invoicing).</li>
        <li><span class="help-field">MagID</span> - the warehouse used at import.
          Multiple warehouses separated by comma (e.g. 1,2); the first wins on
          overlaps.</li>
        <li><span class="help-field">TipVanz</span> - <em>Restaurant</em> (tables
          screen, order goes to the section, receipt can be printed) or
          <em>FastFood</em> (no tables screen, fiscal receipt only, no send/transfer).
          Menu buttons are hidden automatically in FastFood.</li>
        <li><span class="help-field">Server</span> - <em>YES</em>: the app loads
          groups and products from an external server and exports sales to it
          (through sync-service); <em>NO</em>: the app works independently. When
          YES, the Groups/Products buttons are locked and Sync products becomes
          active.</li>
        <li><span class="help-field">CantMax</span> - maximum quantity allowed on a
          receipt line (protects against mistyping). Default 1000.</li>
        <li><span class="help-field">NrZecCant</span> - quantity decimals: 0, 1 or
          2. In Restaurant mode it can be changed only when all tables are closed.</li>
        <li><span class="help-field">PlataCombinata</span> - <em>1 = YES</em>
          (several payment forms can be combined with the numpad); <em>0 = NO</em>
          (only payment buttons, no numpad - one click closes the whole receipt).</li>
        <li><span class="help-field">MarcareMobil</span> - allows marking from a
          tablet/phone (mobile page): <em>YES</em> = the mobile module is active;
          <em>NO</em> (default) = the mobile page only shows a blocking message.
          The desktop POS is not affected.</li>
        <li><span class="help-field">MeniulZilei</span> - shows the Menu of the Day
          button when products with MZ = YES exist.</li>
        <li><span class="help-field">NrBon</span> - the section ticket counter
          (number printed on the kitchen ticket). It resets on the Z report and can
          be restarted manually here.</li>
        <li><span class="help-field">MotivStornare</span> - the list of void (VD)
          reasons, separated by semicolon (;).</li>
        <li><span class="help-field">SerieFactura</span> / <span class="help-field">TextFactura</span>
          - the invoice series and footer text for the Invoicing screen.</li>
      </ul>

      <h4 class="help-section">General tab - Security group</h4>
      <ul class="help-list">
        <li><span class="help-field">IpPermise</span> - the whitelist of IP
          addresses / CIDR networks allowed to access the application. Localhost
          (127.0.0.1 / ::1) is always allowed and cannot be deleted; an empty
          list or only localhost = only localhost has access. Add the IPs of the
          tablets and stations (or a whole network, e.g. 192.168.1.0/24). Saving
          a list that does not contain the IP you are working from is refused.</li>
      </ul>

      <h4 class="help-section">Devices tab - Fiscal Printer</h4>
      <ul class="help-list">
        <li><span class="help-field">TipCasaMarcat</span> - the fiscal printer
          driver: FiscalWire, FiscalNet, Fisco or fprwin.</li>
        <li><span class="help-field">AsteaptaRaspunsCasa</span> - <em>1 = YES</em>:
          the receipt is closed only after the fiscal printer confirms it
          (FiscalWire / FiscalNet); <em>0 = NO</em>: asynchronous printing, as in
          classic mode.</li>
        <li><span class="help-field">AsteptareCasaSec</span> - maximum time
          (seconds) to wait for the fiscal printer response.</li>
        <li><span class="help-field">EvidDiscBonFisc</span> - shows the discount
          on the fiscal receipt, on every product line (1 = YES, 0 = NO). Refers
          only to the discount.</li>
        <li><span class="help-field">EvidStornoBonFisc</span> - shows void
          (storno) lines on the fiscal receipt (1 = YES, 0 = NO). Refers only to
          void lines and is set independently from the discount.</li>
        <li><span class="help-field">CaleFisierComenziECR</span> /
          <span class="help-field">CaleFisierRaspunsECR</span> - the folders where
          commands to the fiscal printer are written and responses are read.</li>
      </ul>

      <h4 class="help-section">Devices tab - Display / Scale / Bank POS</h4>
      <ul class="help-list">
        <li><span class="help-field">AfiseajClient</span> - enables the customer
          display (1 = YES, 0 = NO), with its driver and command folder.
          <strong>Under development.</strong></li>
        <li><span class="help-field">Cantar</span> - enables the electronic scale
          (1 = YES, 0 = NO), with its driver and response file. When YES, the
          <strong>QTY</strong> button launches the driver, waits <em>Delay_cantar</em>
          and writes the read quantity <strong>directly into the receipt</strong>;
          if it does not respond, the numeric keypad opens for manual entry.</li>
        <li><span class="help-field">PoSbanca</span> - enables the card bank
          terminal (1 = YES, 0 = NO), with its driver and response file.</li>
      </ul>

      <h4 class="help-section">Services tab</h4>
      <p>
        Shows the status of the local Python services (printing and sync) and
        allows starting, stopping or restarting them. The badge shows ONLINE /
        OFFLINE. The Save button is not used on this tab.
      </p>

      <h4 class="help-section">Integration tab</h4>
      <p>
        Configures the <strong>public API for third-party applications</strong>
        (servers / backends), which offers export (read) and import (write) of
        data via <em>/api/v1/</em>. Authentication uses a single global key sent
        in the <em>X-Api-Key</em> header.
      </p>
      <ul class="help-list">
        <li><span class="help-field">Cheie API</span> - press
          <strong>Generate key</strong> to create a new key. The key is shown
          <strong>only once</strong>; copy it then. Only a fingerprint (SHA-256) is
          stored, not the clear-text key. <strong>Regenerate</strong> invalidates
          the current key, <strong>Revoke</strong> removes it.</li>
        <li><span class="help-field">ApiEnabled</span> - <em>YES</em> enables the
          API, <em>NO</em> disables it (default NO).</li>
        <li><span class="help-field">ApiAllowedIps</span> - the list of allowed
          IPs / CIDR networks, separated by comma. Set the VPN virtual subnet here
          (e.g. 192.168.30.0/24) as a second layer of defence. Empty = no
          restriction.</li>
        <li><span class="help-field">ApiRateLimit</span> - maximum requests per
          minute per IP (0 = no limit).</li>
        <li><span class="help-field">ApiCorsOrigin</span> - reserved for CORS
          (browser consumers); leave empty for server-to-server integrations.</li>
      </ul>
      <p class="help-tip">
        External access is done <strong>exclusively through VPN</strong>, not over
        the internet. Recommended <strong>SoftEther VPN Azure + SecureNAT</strong>:
        SoftEther VPN Server (<a href="https://www.softether.org/5-download"
        target="_blank" rel="noopener">download</a>) runs on this station, and the
        client (cloud server) connects to the hostname <em>vpn…vpnazure.net</em>;
        the API is then reached through the VPN virtual IP (e.g.
        <em>http://192.168.30.1/rual/api/v1/</em>), with no domain, certificate or
        open router ports. Alternatively, a <strong>Tailscale / ZeroTier</strong>
        network. Do not forget to press <strong>Save</strong> after changing
        settings.
      </p>

      <h4 class="help-section">Other tab</h4>
      <p>Contains the remaining settings that are not part of the groups above.</p>
    `
  },

  antet: {
    title: "HELP - HEADER",
    html: `
      <p class="help-intro">
        The <strong>Header</strong> screen edits the company data that appears on
        documents (fiscal receipt, note, invoice). It writes the row in
        <em>tblAntet</em> with Series <em>0001</em>.
      </p>
      <h4 class="help-section">How to use</h4>
      <ul class="help-list">
        <li>Edit the desired fields; for text use the <strong>&#9000;</strong>
          button (alphanumeric keyboard).</li>
        <li>Numeric fields (SizeModeLogo) use the <strong>123</strong> keypad.</li>
        <li>Press <strong>Save</strong> (yellow) to write the changes.</li>
      </ul>
      <h4 class="help-section">Fields</h4>
      <ul class="help-list">
        <li><span class="help-field">Nume / Denumire2</span> - the company name.</li>
        <li><span class="help-field">Adresa / Oras / CodPostal / Judet</span> - the
          registered address.</li>
        <li><span class="help-field">CodFiscF / RegCom</span> - VAT number and trade
          register number.</li>
        <li><span class="help-field">Cont / Banca</span> - IBAN account and bank.</li>
        <li><span class="help-field">PersContact / email / website / telefon</span> -
          contact details.</li>
        <li><span class="help-field">PlatitorTVA</span> - YES/NO.</li>
        <li><span class="help-field">CaleDateLogo</span> - path to the logo image,
          <span class="help-field">SizeModeLogo</span> - the logo sizing mode.
          <em>(under development)</em></li>
        <li><span class="help-field">QRCodeText</span> - the text encoded in the QR
          code on the document. <em>(under development)</em></li>
      </ul>
    `
  },

  nota: {
    title: "HELP - RECEIPT",
    html: `
      <p class="help-intro">
        The <strong>Receipt</strong> screen configures the header and footer rows
        printed on the receipt (and on the proforma). It edits rows <em>H1-H3</em>
        (header) and <em>F1-F2</em> (footer), plus <em>P1-P2</em> which appear only
        on the proforma.
      </p>
      <h4 class="help-section">Fields for each row</h4>
      <ul class="help-list">
        <li><span class="help-field">Nume</span> - the row text (leave empty to not
          print the row).</li>
        <li><span class="help-field">NumeFont</span> - the ESC/POS font
          (Font A / B / C / D / E).</li>
        <li><span class="help-field">Size</span> - the size (1x1, 2x1, 1x2, 2x2,
          3x3).</li>
        <li><span class="help-field">Bold</span> - YES/NO.</li>
      </ul>
      <p class="help-tip">
        Changes are saved all at once with <strong>Save</strong>. The result can be
        seen in the preview in the Print queue.
      </p>
    `
  },

  conectare: {
    title: "HELP - SERVER CONNECTION",
    html: `
      <p class="help-intro">
        The <strong>Server connection</strong> screen configures the external data
        source (<em>tblConectare</em> table, single row) used for product sync and
        sales export, plus the server &#8594; local mapping tables.
      </p>
      <h4 class="help-section">Connection details</h4>
      <ul class="help-list">
        <li><span class="help-field">ServerIP / ServerName</span> - address / name
          of the SQL server.</li>
        <li><span class="help-field">DataBaseName</span> - the database name.</li>
        <li><span class="help-field">UserName / Password</span> - user and password
          (not used when TC = YES).</li>
        <li><span class="help-field">TC (Trusted Connection)</span> - YES = Windows
          auth, NO = user/password.</li>
        <li><span class="help-field">ODBC_connect_string</span> - the full ODBC
          string, if used directly.</li>
      </ul>
      <p class="help-tip">
        The <strong>Test connection</strong> button tries to connect with the
        on-screen values, without saving.
      </p>
      <h4 class="help-section">Server &#8594; local mappings</h4>
      <ul class="help-list">
        <li><span class="help-field">Printing section mapping (KP)</span> -
          translates the server printing section into the local one at import.</li>
        <li><span class="help-field">Section mapping</span> - translates the section
          (tblProd.Sectie) from the server into the local one at import.</li>
      </ul>
      <p>
        If a server value has no mapping, the server value is kept. Use the
        <strong>+ Add mapping</strong> buttons and then <strong>Save</strong>.
      </p>
    `
  },

  parole: {
    title: "HELP - PASSWORDS",
    html: `
      <p class="help-intro">
        The <strong>Passwords</strong> screen sets the POS passwords
        (<em>tblParola</em> table, single row). Passwords are <strong>numeric</strong>.
        A field left empty means that password is not requested.
      </p>
      <h4 class="help-section">Passwords</h4>
      <ul class="help-list">
        <li><span class="help-field">Parola programare</span> - asks for the
          password to enter the programming screen (Settings, Products, etc.).</li>
        <li><span class="help-field">Parola rapoarte</span> - asks for the password
          to open the X / Z reports screen.</li>
        <li><span class="help-field">Parola stornare</span> - asks for the password
          and reason when voiding (VD) a line already sent to the kitchen.</li>
        <li><span class="help-field">Parola discount</span> - asks for the password
          before applying a discount.</li>
        <li><span class="help-field">Parola inchidere aplicatie</span> - asks for the
          password when closing the application (exit).</li>
        <li><span class="help-field">Parola update</span> - protects the application
          update. If empty, the update is blocked.</li>
      </ul>
      <p class="help-tip">Press <strong>Save</strong> (yellow) to write the changes.</p>
    `
  },

  produse: {
    title: "HELP - PRODUCT PROGRAMMING",
    html: `
      <p class="help-intro">
        The <strong>Products</strong> screen edits the product catalogue
        (<em>tblProd</em>). At the top you choose the group; the grid on the left
        shows its products on positions 1-50, exactly like the sales menu.
      </p>
      <h4 class="help-section">How to use</h4>
      <ul class="help-list">
        <li>Choose the group from the <strong>Group</strong> list at the top.</li>
        <li>Tap a product on the grid to edit it, or a dotted (free) slot to add a
          new product.</li>
        <li>Changes are kept in memory (marked <em>* unsaved</em>) and written with
          <strong>Save</strong>.</li>
        <li><strong>Reload</strong> reloads the data from the server (loses unsaved
          changes).</li>
      </ul>
      <h4 class="help-section">Main fields</h4>
      <ul class="help-list">
        <li><span class="help-field">ProdID</span> - the product code (editable only
          on add).</li>
        <li><span class="help-field">BarCod</span> - the barcode used by the
          scanner.</li>
        <li><span class="help-field">Denumire</span> - the name shown on the menu
          and receipt.</li>
        <li><span class="help-field">Grupa (NrGrp)</span> - the group the product
          belongs to.</li>
        <li><span class="help-field">Poz</span> - the position in the menu (1-50).</li>
        <li><span class="help-field">PV</span> - the sale price.</li>
        <li><span class="help-field">Nr_TVA</span> - the VAT rate applied.</li>
        <li><span class="help-field">Imprimanta KP</span> - the section printer the
          product goes to on Send.</li>
        <li><span class="help-field">Sectie</span> - the reporting section.</li>
        <li><span class="help-field">Magazie (MagID)</span> - the product warehouse.
          If <em>tblSet.MagID</em> contains a single value, the field is prefilled
          with it; if it is a list of ids, it appears as a drop-down.</li>
        <li><span class="help-field">UM</span> - the unit of measure.</li>
        <li><span class="help-field">MZ</span> - whether the product is part of the
          Menu of the Day (YES/NO).</li>
      </ul>
      <p>
        Deleting a product asks for confirmation. In "server" mode (products come
        from sync) the screen is locked.
      </p>
    `
  },

  grupe: {
    title: "HELP - GROUP PROGRAMMING",
    html: `
      <p class="help-intro">
        The <strong>Groups</strong> screen edits the menu groups
        (<em>tblGrp</em>). The grid on the left mirrors the group grid on the sales
        screen (positions 1-50).
      </p>
      <h4 class="help-section">How to use</h4>
      <ul class="help-list">
        <li>Tap a group or a free slot on the grid to edit it.</li>
        <li>Changes are marked <em>* unsaved</em> and written with
          <strong>Save</strong>.</li>
        <li><strong>Reload</strong> reloads the data from the server.</li>
      </ul>
      <h4 class="help-section">Fields</h4>
      <ul class="help-list">
        <li><span class="help-field">NrGrp</span> - the group code (editable only on
          add).</li>
        <li><span class="help-field">Denumire</span> - the group name.</li>
        <li><span class="help-field">Poz</span> - the position in the menu (1-50).</li>
        <li><span class="help-field">FontSize / FontType</span> - the size and font
          of the button text.</li>
        <li><span class="help-field">Culoare fundal / Culoare text</span> - the
          button appearance (palette or custom color).</li>
        <li><span class="help-field">Bold</span> - bold text (YES/NO).</li>
      </ul>
      <p>Groups have no delete; to remove a group from the menu, move it to another
        position or leave it without products.</p>
    `
  },

  mese: {
    title: "HELP - TABLE LAYOUT",
    html: `
      <p class="help-intro">
        The <strong>Table layout</strong> screen edits the table appearance
        (<em>tblMese</em>). The map on the left is exactly the tables screen
        (10 columns x 8 rows = 80 positions).
      </p>
      <h4 class="help-section">How to use</h4>
      <ul class="help-list">
        <li>Tap a table on the map to edit it, or a free slot to add a new table
          (<strong>Add table</strong>).</li>
        <li>Changes are marked <em>* unsaved</em> and written with
          <strong>Save</strong>.</li>
        <li><strong>Reset</strong> deletes everything and recreates the 80 tables
          with the default appearance (asks for confirmation).</li>
        <li><strong>Reload</strong> reloads the data from the server.</li>
      </ul>
      <h4 class="help-section">Fields</h4>
      <ul class="help-list">
        <li><span class="help-field">NrMasa</span> - the position number (1-80).</li>
        <li><span class="help-field">Culoare fundal / Culoare text</span> - the table
          appearance on the map.</li>
        <li><span class="help-field">Bold</span> - bold text.</li>
        <li><span class="help-field">Afisez</span> - whether the table appears on
          the tables screen when free (a table with an open receipt is shown
          anyway).</li>
        <li><span class="help-field">Obs</span> - notes.</li>
      </ul>
    `
  },

  imprimante: {
    title: "HELP - PRINTERS",
    html: `
      <p class="help-intro">
        The <strong>Section printers</strong> screen associates each printing
        section (kitchen, bar, etc.) with a physical printer, and sets the
        destinations for the receipt, reports and fiscal printer.
      </p>
      <h4 class="help-section">How to use</h4>
      <ul class="help-list">
        <li>On the left is the list of sections (<em>tblKP</em> table). Choose a
          section and configure its name, status and destination.</li>
        <li>On the right you choose the global destinations: <em>receipt</em>,
          <em>reports</em>, <em>fiscal printer</em> and the default printer.</li>
        <li>The badge at the top shows whether the printing service is
          ONLINE/OFFLINE.</li>
        <li><strong>Save</strong> writes the sections to the database and the
          printing configuration (the Python service hot-reloads it).</li>
      </ul>
      <h4 class="help-section">Destination types</h4>
      <ul class="help-list">
        <li><span class="help-field">Retea</span> - printer with IP and port
          (default 9100).</li>
        <li><span class="help-field">Windows / USB</span> - a local printer
          installed in Windows (including USB).</li>
        <li><span class="help-field">Preview</span> - does not print physically;
          writes the content to the preview (useful until the printer is
          installed).</li>
        <li><span class="help-field">Fisier</span> - writes the content to a
          file.</li>
      </ul>
      <p>
        The <strong>Test</strong> button (per section or per destination) sends a
        test receipt.
      </p>
    `
  },

  tva: {
    title: "HELP - VAT RATES",
    html: `
      <p class="help-intro">
        The <strong>VAT rates</strong> screen edits the <em>tblTVA</em> table: the
        list of VAT rates available on products.
      </p>
      <h4 class="help-section">How to use</h4>
      <ul class="help-list">
        <li>Tap a rate in the list to select it.</li>
        <li><strong>Add</strong> opens the form for a new rate, with the numeric
          keypad (<strong>123</strong> for Nr_TVA, <strong>.</strong> for
          decimals).</li>
        <li><strong>Edit</strong> / <strong>Delete</strong> act on the selected
          rate; deleting asks for confirmation.</li>
      </ul>
      <h4 class="help-section">Fields</h4>
      <ul class="help-list">
        <li><span class="help-field">Nr_TVA</span> - the rate identifier.</li>
        <li><span class="help-field">Cota (%)</span> - the VAT percentage.</li>
      </ul>
      <p class="help-tip">
        POS prices are VAT-inclusive; the rate is used to break down VAT in
        reports.
      </p>
    `
  },

  mesaje: {
    title: "HELP - PREP MODES",
    html: `
      <p class="help-intro">
        The <strong>Prep modes</strong> screen edits the <em>tblMesaj</em> table:
        the "prep mode" texts (e.g. "no onion", "well done") that can be attached
        to a product on the receipt.
      </p>
      <h4 class="help-section">How to use</h4>
      <ul class="help-list">
        <li>Tap a mode in the list to select it.</li>
        <li><strong>Add</strong> opens the form; NrMesaj uses the
          <strong>123</strong> keypad, Mesaj uses the <strong>&#9000;</strong>
          keyboard.</li>
        <li><strong>Edit</strong> / <strong>Delete</strong> act on the selected
          mode; deleting asks for confirmation.</li>
      </ul>
      <p>
        An attached mode becomes a separate row on the receipt, under the product,
        and does not change the total.
      </p>
    `
  },

  ospatari: {
    title: "HELP - WAITERS",
    html: `
      <p class="help-intro">
        The <strong>Waiters</strong> screen edits the list of waiters / cashiers
        (<em>tblOsp</em>). They authenticate with a password to take orders.
      </p>
      <h4 class="help-section">How to use</h4>
      <ul class="help-list">
        <li>Tap a waiter in the list to select it.</li>
        <li><strong>Add</strong> opens the add form.</li>
        <li><strong>Edit</strong> / <strong>Delete</strong> act on the selected
          waiter; deleting asks for confirmation.</li>
      </ul>
      <h4 class="help-section">Fields</h4>
      <ul class="help-list">
        <li><span class="help-field">NrOsp</span> - the code (max 2 characters).</li>
        <li><span class="help-field">Nume</span> - the displayed name.</li>
        <li><span class="help-field">Expl</span> - description / role.</li>
        <li><span class="help-field">Parola</span> - the POS login password.</li>
        <li><span class="help-field">Blocat</span> - YES = cannot log in.</li>
      </ul>
    `
  },

  sectii: {
    title: "HELP - SECTIONS",
    html: `
      <p class="help-intro">
        The <strong>Sections</strong> screen edits the <em>tblSectii</em> table:
        the product reporting sections (for example kitchen, bar).
      </p>
      <h4 class="help-section">How to use</h4>
      <ul class="help-list">
        <li>Tap a section in the list to select it.</li>
        <li><strong>Add</strong> opens the form; the Sectie code uses the
          <strong>123</strong> keypad, the Name uses the <strong>&#9000;</strong>
          keyboard.</li>
        <li><strong>Edit</strong> / <strong>Delete</strong> act on the selected
          section. Sections 1 and 2 are protected and cannot be deleted.</li>
      </ul>
      <h4 class="help-section">Fields</h4>
      <ul class="help-list">
        <li><span class="help-field">Sectie</span> - the section code (key).</li>
        <li><span class="help-field">Denumire</span> - the name (max 10 characters).</li>
      </ul>
    `
  },

  forme_plata: {
    title: "HELP - PAYMENT FORMS",
    html: `
      <p class="help-intro">
        The <strong>Payment forms</strong> screen edits the <em>tblFP</em> table:
        the payment buttons shown on the receipt-closing screen (cash, card,
        vouchers, etc.).
      </p>
      <h4 class="help-section">How to use</h4>
      <ul class="help-list">
        <li>Tap a payment form in the list to select it.</li>
        <li><strong>Add</strong> opens the form; FPID and Poz use the
          <strong>123</strong> keypad, the Name uses the <strong>&#9000;</strong>
          keyboard.</li>
        <li><strong>Edit</strong> / <strong>Delete</strong> act on the selected
          form; deleting asks for confirmation.</li>
      </ul>
      <h4 class="help-section">Fields</h4>
      <ul class="help-list">
        <li><span class="help-field">FPID</span> - the payment form identifier
          (<em>FPID = 1</em> is reserved for card / bank POS).</li>
        <li><span class="help-field">Denumire</span> - the button text.</li>
        <li><span class="help-field">Poz</span> - the display order; the form with
          Poz = 1 is selected by default.</li>
        <li><span class="help-field">Status</span> - 1 = active (shown on the
          payment screen), 0 = hidden.</li>
      </ul>
    `
  },

  rollback: {
    title: "HELP - ROLLBACK",
    html: `
      <p class="help-intro">
        <strong>Rollback</strong> reverts to a previous version of the application
        if the current one has problems. Only code files are affected; local
        settings (connection, printers, fiscal printer, bank POS) are
        <strong>not</strong> touched. The database is not modified.
      </p>
      <h4 class="help-section">Restore points (this computer)</h4>
      <p>
        Before every update a full snapshot of the current version is saved
        automatically. The last 5 are kept. Choose one to return exactly to the
        version that was running then.
      </p>
      <h4 class="help-section">Versions from history (GitHub)</h4>
      <p>
        If there is internet, the latest versions from the GitHub history are also
        listed; you can return to any of them.
      </p>
      <h4 class="help-section">How to use</h4>
      <ul class="help-list">
        <li>Press <strong>Rollback</strong>; you are asked for the
          <strong>update password</strong> (the same as for Update).</li>
        <li>Choose a restore point or a version from the list.</li>
        <li>Press <strong>Apply rollback</strong> (yellow) and confirm.</li>
        <li>After rollback, <strong>restart the services</strong> (Settings ->
          Services) and reload the page.</li>
      </ul>
      <p class="help-tip">
        The current version is marked "(current)". After a rollback, the
        <strong>Update</strong> button will again offer the move to the latest
        version.
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
  const isEn = (typeof window.LANG === "string" && window.LANG === "en");
  const data = (isEn && typeof HELP_CONTENT_EN !== "undefined" && HELP_CONTENT_EN[key])
    ? HELP_CONTENT_EN[key]
    : (HELP_CONTENT[key] || HELP_FALLBACK);
  const modal = document.getElementById("modal-help");
  if (!modal) return;
  const title = document.getElementById("modal-help-title");
  const body = document.getElementById("modal-help-body");
  if (title) title.textContent = data.title || (isEn ? "HELP" : "AJUTOR");
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
