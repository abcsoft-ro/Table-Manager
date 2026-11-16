/**
 * TableManager 2.2.0 - Client Logic conectat direct la MSSQL
 * Afisare produse direct pe panoul albastru (Poz, BackColor, FontColor, Bold)
 * Suport pentru BackColor = 0 (fond negru) cu FontColor alb
 */

const POS_STATE = {
  masaCurenta: 1,
  docId: null,
  casierCurent: "CASIER 1",
  nrOp: 8,
  // Autentificare ospatar: loggedIn + rolul curent; bonNrOp = ospatarul care a
  // deschis bonul de pe masa curenta (pt. verificarea proprietarului).
  loggedIn: false,
  waiterRol: null,
  bonNrOp: null,
  // Mod logare din tblSet.Mod_logare: 1 = ramane logat toata sesiunea,
  // 0 = delogare automata la iesirea de pe masa.
  modLogare: 1,
  // Tipul de vanzare din tblSet.TipVanz: "restaurant" (default) sau "fastfood".
  // In FastFood nu se afiseaza ecranul de mese, comanda nu pleaca la sectie si
  // nota de plata nu se tipareste (doar bonul fiscal).
  tipVanz: "restaurant",
  // Limba interfetei (tblSet.Limba): "ro" (default) sau "en".
  lang: "ro",
  // Cantitatea maxima admisa pe o linie (tblSet.CantMax, implicit 1000).
  cantMax: 1000,
  // Numarul de zecimale pentru cantitate (tblSet.NrZecCant: 0, 1 sau 2).
  nrZecCant: 1,
  // Fontul si marimea implicita pentru panoul din dreapta (tblSet.Font/Size):
  // se folosesc atunci cand grupa/produsul nu au FontType/FontSize setate.
  fontPanel: "Arial",
  sizePanel: 12,
  // Discountul permis/interzis (tblSet.RED: 1 = DA, 0 = NU).
  red: 1,
  // Tipul discountului (tblSet.TipDiscount): 0 = complet, 1 = doar procent la
  // subtotal (popup cu tastatura numerica), 2 = procent fix la subtotal
  // (aplicat direct, fara popup). Procentul fix = tblSet.ProcentDiscountFix.
  tipDiscount: 0,
  procentDiscountFix: 10,
  // Export/sincronizare server (tblSet.Server): 1 = DA. Cand e 1, Grupe/Produse
  // sunt blocate in ecranul de programare, iar Sincronizare produse e activa.
  server: 0,
  // Daca discountul cere parola (tblParola.ParolaDiscount setata) si parola
  // verificata in sesiunea curenta de discount.
  parolaDiscount: 0,
  discountParola: "",
  // Daca stornarea liniilor trimise cere parola/motiv (tblParola.ParolaStornare
  // setata). Cand e 0, popup-ul de stornare nu le mai afiseaza.
  parolaStornare: 0,
  // POS bancar activ (tblSet.PoSbanca): cand e 1, la inchiderea unei note cu
  // plata pe card se apeleaza terminalul bancar prin api/pos_banca.php.
  posBanca: 0,
  // Retiparirea ultimei chitante a terminalului bancar
  // (tblSet.RetiparireChitantaPoSbanca): 1 = DA (butonul "Chitanță card"
  // apare dupa o aprobare pe card), 0 = NU (implicit).
  retiparireChitantaPoSbanca: 0,
  // Registrul incasarilor pe card ale notei curente (swipe-uri aprobate de
  // terminal: {approved, requested, source, trxId, logId, at}). Este SINGURA
  // sursa de adevar pentru banii de pe card: `payments` nu contine niciodata
  // cheia 1, linia de card se deriveaza in paymentsForClose(). Asa, un cod care
  // ar scrie accidental payments[1] nu poate taxa nimic (cheia nu e citita).
  // Se reseteaza implicit cand se schimba DocID-ul notei (vezi cardLedgerForDoc).
  cardLedger: { docId: null, swipes: [] },
  // Plata pe card fara terminal (PosBanca = 0) sau cu PlataCombinata = 0 (click
  // pe CARD inchide nota): suma declarata, netaxata inca. La inchidere se
  // taxeaza o singura data, ca inainte.
  cardDeclared: 0,
  // Garda anti-dublu-tap cat timp terminalul este taxat (un al doilea OK/click
  // pe o forma de plata in acest interval este ignorat).
  cardPaying: false,
  // Conexiunea terminalului citita din .env-ul driverului SmartPay (transport +
  // port), folosita in mesajul "terminalul nu raspunde" fara a hardcoda un port.
  // Ramane null pentru un driver fara conexiune in .env.
  bankPosConnection: null,
  // Protocolul POS bancar si capabilitatile raportate de server. Verifica/void
  // se afiseaza doar cand protocolul le suporta; Manual ramane disponibil.
  bankPosProtocol: "",
  bankPosCanResync: false,
  bankPosCanVoid: false,
  // Nota are o plata pe card cu stare incerta (UNKNOWN/TIMEOUT): este blocata
  // pana la clarificare (Verifica / Manual din ecranul de plata). Serverul
  // refuza oricum modificarile cu 423 bank_pos_uncertain; flagul doar blocheaza
  // ecranul de marcare inainte de a trimite cererea.
  bankPosLocked: false,
  bankPosLockAmount: "",
  bankPosUid: "",
  // Nota are bani aprobati pe terminal dar inca neaplicati pe nota (ex. dupa
  // un reload in timpul platii). Ramane nemodificabila, dar plata poate fi
  // continuata din NOTA (banner-ul o spune, iar butonul NOTA rămâne vizibil).
  bankPosApproved: false,
  bankPosApprovedTotal: 0,
  // Incertitudine nerezolvata / deja lamurita (jurnal sau decizie manuala):
  // doar prima justifica butoanele Verifica si Am chitanța (Manual).
  bankPosUncertain: false,
  bankPosResolved: false,
  // Comanda trimisa terminalului, fara raspuns inca (randul PENDING din audit):
  // nota este blocata, dar inca nu are sens sa verificam jurnalul.
  bankPosPending: false,
  // Ultima nota inchisa cu plata pe card: {docId}. Retiparirea chitantei de
  // card rămâne disponibila si dupa close_bill (nota nu mai exista in memorie,
  // dar randurile din tblPosBancaLog dau datele duplicatului; serverul alege
  // ultima tranzactie aprobata).
  lastCardReprint: null,
  cantar: 0,
  // Asteapta raspunsul casei de marcat la inchiderea notei
  // (tblSet.AsteaptaRaspunsCasa, doar FiscalWire): cand e 1, dupa plata se
  // asteapta confirmarea bonului fiscal inainte de a inchide nota.
  asteaptaRaspunsCasa: 0,
  // Plata combinata (tblSet.PlataCombinata): 1 = DA (plata mixta, cu numpad);
  // 0 = NU (ecranul de inchidere arata doar formele de plata, fara numpad; la
  // click pe o forma nota se inchide integral cu acea forma).
  plataCombinata: 1,
  reducereProcent: 0,
  selectedItemIndex: 0,
  // Forme de plata (tblFP) si platile inregistrate pe ecranul de inchidere:
  // paymentForms = [{FPID, Denumire, Status}], payments = { FPID: valoare }
  // payments = sumele APLICATE notei (nu sumele tastate), plafonate la total;
  // rest = diferenta tastata in plus (schimb de dat la numerar).
  paymentForms: [],
  payments: {},
  rest: 0,
  activePaymentFp: null,
  // Garda anti-reentrare la inchiderea notei: cat timp un close_bill e in curs
  // (inclusiv asteptarea terminalului bancar / a casei de marcat), un al doilea
  // click pe OK sau pe o forma de plata este ignorat, ca sa nu se dubleze
  // bonul fiscal / platile. Se reseteaza in finally-ul lui executeClose.
  closingBill: false,
  
  // Mod panou dreapta: "groups" sau "products"
  currentPanelMode: "groups",
  activeGroup: null,

  // Date incarcate din baza de date
  groups: [],
  productsByGroup: {},
  messages: [],
  // Motive programabile (tblSet.MotivDiscount / MotivStornare), editabile in Setari.
  motivDiscount: [],
  motivStornare: [],
  meniulZilei: 0,
  articole: [],
  tables: [],

  // Codul fiscal al clientului (CUI) pentru bonul fiscal, tinut per nota.
  cui: "",
  cuiDocId: null
};

// Traducere gettext-style: daca sirul (text-sursa romana) exista in catalog,
// intoarce traducerea; altfel il lasa neschimbat. Suporta placeholdere {nume}.
// Folosit si de functiile-funnel (appAlert/appConfirm/showToast), ca mesajele
// scrise direct in romana sa fie traduse automat fara a edita fiecare apel.
function tr(s, params) {
  if (typeof window.trText === "function") return window.trText(s, params);
  if (typeof window.t === "function") return window.t(s, params);
  return s;
}

// Initializare la incarcarea paginii
document.addEventListener("DOMContentLoaded", () => {
  initApp();
  updateLiveClock();
  setInterval(updateLiveClock, 1000);
  // Campul de scanare pastreaza focusul in ecranul de marcare (fara popup activ)
  setInterval(focusScanInput, 700);
  // Eticheta "Coada printare" se sincronizeaza periodic cu coada (joburile
  // asincrone trec in 'done' fara vreo actiune a utilizatorului).
  setInterval(refreshPrintStatus, 5000);
});

// Numaratoarea reincerarilor de incarcare initiala (tipic: dupa un reboot,
// SQL Server nu e inca pornit). Se reseteaza la fiecare incarcare reusita.
const MAX_INIT_RETRY = 20;
let initRetryCount = 0;
// Banner-ul de stare se afiseaza doar daca incarcarea intarzie (pe o pornire
// normala nu are rost sa clipeasca pe ecran).
let initStatusTimer = null;

// --------------------------------------------------------------------------
// STARE BAZA DE DATE (banner vizibil cat timp datele nu pot fi citite)
// --------------------------------------------------------------------------
// Fara baza de date aplicatia nu poate citi nimic. In loc sa rămâna ecranul gol
// (sau sa apara doar un toast care dispare in 2 secunde), aratam un banner care
// rămâne pe ecran pana cand datele se incarca. Banner-ul nu blocheaza clicurile
// (vezi .db-status din css/pos.css), deci butonul Parola rămâne utilizabil:
// parola de inchidere functioneaza si fara baza de date (api/parola_local.php).
function showDbStatus(title, text) {
  const box = document.getElementById("db-status");
  if (!box) return;
  const elTitle = document.getElementById("db-status-title");
  const elText = document.getElementById("db-status-text");
  if (elTitle) elTitle.innerText = title;
  if (elText) elText.innerText = text || "";
  box.classList.add("visible");
}

function hideDbStatus() {
  if (initStatusTimer) { clearTimeout(initStatusTimer); initStatusTimer = null; }
  const box = document.getElementById("db-status");
  if (box) box.classList.remove("visible");
}

async function initApp() {
  try {
    if (initRetryCount === 0) {
      initStatusTimer = setTimeout(() => {
        initStatusTimer = null;
        showDbStatus(tr("Se conecteaza la baza de date..."),
          tr("Aplicatia citeste meniul si mesele din baza de date locala."));
      }, 2500);
    }
    // Titlul tabului reflecta versiunea reala a aplicatiei (api/version.php).
    loadAppVersion();
    // 1. Incarcam meniul si produsele din MSSQL
    await loadMenu();
    // 1a. Formele de plata (pentru etichetele din dialogul de inchidere).
    // Necesar si inainte de prima deschidere a ecranului de plata, ca nota sa
    // fie afisata cu "CARD", nu cu "FP 1".
    loadPaymentForms();
    // 1b. Restauram sesiunea de ospatar (doar daca Mod_logare = 1)
    restoreWaiterSession();
    // 2. Incarcam comanda pentru masa pe care lucra operatorul. Dupa un refresh
    // (Ctrl+F5) trebuie sa rămâna pe aceeasi masa: nota poate avea o plata pe
    // card incerta/confirmata, care nu are voie sa dispara de pe ecran. In
    // FastFood nu exista masa selectata - nota unica este pe masa 1.
    const savedMasa = isFastFood() ? 0 : savedCurrentTable();
    if (isFastFood()) {
      POS_STATE.masaCurenta = 1;
      clearCurrentTable();
    } else if (savedMasa > 0) {
      POS_STATE.masaCurenta = savedMasa;
    }
    await loadOrder(POS_STATE.masaCurenta);
    selectLastAndScroll();
    // 3. Incarcam starea celor 80 de mese
    await loadTables();
    hideDbStatus();
    showToast("Conectat la baza de date a aplicației!");
    initRetryCount = 0;

    // Mod FastFood: pornim direct in ecranul de marcare (fara selectie de mese).
    if (isFastFood()) {
      navigateToScreen("screen-marcare");
    } else if (savedMasa > 0 && POS_STATE.docId) {
      // Revenim exact acolo unde era operatorul (nota de pe masa respectiva).
      returnToGroups();
      navigateToScreen("screen-marcare");
    } else if (savedMasa > 0) {
      // Masa memorata nu mai are nota deschisa (s-a inchis intre timp).
      clearCurrentTable();
    }
  } catch (err) {
    console.error("Eroare la initializare:", err);
    scheduleInitRetry(err);
  }
}

// Daca incarcarea initiala esueaza (tipic: dupa un reboot Windows, instanta SQL
// locala nu e inca pornita), NU rămânem cu ecranul gol: aratam un banner clar cu
// eroarea tehnica primita si reincercam automat. Banner-ul spune si ca parola de
// inchidere functioneaza in continuare, ca sa nu fie nevoie sa omori procese.
function scheduleInitRetry(err) {
  initRetryCount++;
  const detaliu = (err && err.message) ? String(err.message) : "";
  if (initRetryCount > MAX_INIT_RETRY) {
    showDbStatus(tr("Baza de date nu raspunde. Reporniti aplicatia POS."),
      tr("Incercarile automate s-au terminat.") + (detaliu ? "\n" + detaliu : ""));
    return;
  }
  const sec = Math.min(5 + initRetryCount * 2, 15);
  showDbStatus(tr("Baza de date nu raspunde."),
    tr("Se reincearca automat") + " (" + initRetryCount + "/" + MAX_INIT_RETRY + ", " + sec + "s).\n"
    + tr("Parola de inchidere functioneaza si fara baza de date.")
    + (detaliu ? "\n" + detaliu : ""));
  setTimeout(initApp, sec * 1000);
}

// --------------------------------------------------------------------------
// 1. INCARCARE MENIU DIN MSSQL (tblGrp & tblProd + pret din tblProd.PV)
// --------------------------------------------------------------------------
// Motive implicite daca setarile tblSet.MotivDiscount / MotivStornare lipsesc.
const DEFAULT_MOTIV_DISCOUNT = ["Protocol", "Inlocuire preparat", "Membru fidelitate", "Angajat", "Card fidelitate", "Altele"];
const DEFAULT_MOTIV_STORNARE = ["Retur client", "Greseala ospatar", "Lipsa stoc", "Comanda gresita", "Altele"];

// Desparte o lista de motive separata prin punct si virgula.
function parseMotiveList(v) {
  return String(v == null ? "" : v)
    .split(";")
    .map(s => s.trim())
    .filter(s => s !== "");
}

// Umple un <select> de motive cu o lista; daca lista e goala foloseste implicitul.
function populateMotiveSelect(id, list, fallback) {
  const el = document.getElementById(id);
  if (!el) return;
  const items = (Array.isArray(list) && list.length > 0) ? list : fallback;
  el.innerHTML = '<option value="">' + escapeHtml(tr("— selectati motivul —")) + '</option>';
  items.forEach(m => {
    const opt = document.createElement("option");
    opt.value = m;
    opt.textContent = m;
    el.appendChild(opt);
  });
}

// Reimprospateaza listele de motive din casetele Discount si VD.
function refreshMotiveSelects() {
  populateMotiveSelect("disc-motiv-select", POS_STATE.motivDiscount, DEFAULT_MOTIV_DISCOUNT);
  populateMotiveSelect("void-motiv-select", POS_STATE.motivStornare, DEFAULT_MOTIV_STORNARE);
}

async function loadMenu() {
  const resp = await fetch("api/menu.php");
  const data = await resp.json();
  if (data.status !== "success") throw new Error(data.message || "Eroare meniu");

  POS_STATE.groups = data.groups || [];
  POS_STATE.productsByGroup = data.productsByGroup || {};
  POS_STATE.messages = data.messages || [];
  POS_STATE.motivDiscount = Array.isArray(data.motivDiscount) ? data.motivDiscount : [];
  POS_STATE.motivStornare = Array.isArray(data.motivStornare) ? data.motivStornare : [];
  POS_STATE.meniulZilei = (data.meniulZilei == 1) ? 1 : 0;
  POS_STATE.modLogare = (data.modLogare == 0) ? 0 : 1;
  POS_STATE.tipVanz = (data.tipVanz === "fastfood") ? "fastfood" : "restaurant";
  POS_STATE.lang = (data.lang === "en") ? "en" : "ro";
  POS_STATE.cantMax = (Number(data.cantMax) > 0) ? Number(data.cantMax) : 1000;
  const nz = parseInt(data.nrZecCant, 10);
  POS_STATE.nrZecCant = (nz >= 0 && nz <= 2) ? nz : 1;
  // Fontul/marimea implicita a panoului din dreapta (fallback pentru grupe/produse).
  POS_STATE.fontPanel = String(data.fontPanel || "Arial").trim() || "Arial";
  const sp = parseInt(data.sizePanel, 10);
  POS_STATE.sizePanel = (sp >= 6 && sp <= 72) ? sp : 12;
  POS_STATE.red = (data.red === 0 || data.red === "0") ? 0 : 1;
  const td = parseInt(data.tipDiscount, 10);
  POS_STATE.tipDiscount = (td >= 0 && td <= 2) ? td : 0;
  const pf = Number(data.procentDiscountFix);
  POS_STATE.procentDiscountFix = (pf >= 0 && pf <= 100) ? pf : 10;
  POS_STATE.server = (data.server == 1) ? 1 : 0;
  POS_STATE.posBanca = (data.posBanca == 1) ? 1 : 0;
  POS_STATE.retiparireChitantaPoSbanca = (data.retiparireChitantaPoSbanca == 1) ? 1 : 0;
  POS_STATE.cantar = (data.cantar == 1) ? 1 : 0;
  POS_STATE.asteaptaRaspunsCasa = (data.asteaptaRaspunsCasa == 1) ? 1 : 0;
  POS_STATE.plataCombinata = (data.plataCombinata == 0) ? 0 : 1;
  POS_STATE.parolaDiscount = (data.parolaDiscount == 1) ? 1 : 0;
  POS_STATE.parolaStornare = (data.parolaStornare == 1) ? 1 : 0;

  // Traducem elementele statice marcate cu data-i18n* (limba din tblSet.Limba).
  if (typeof window.applyI18n === "function") window.applyI18n();
  // Titlul butonului CUI este setat dinamic (include codul curent), deci il
  // retradusim dupa aplicarea catalogului i18n.
  updateCuiButton();

  renderMenuGrid();
  updateMeniulZileiButton();
  applyTipVanzUI();
  applyRedUI();
  applyTipDiscountUI();
  applyServerModeUI();
  applyPlataCombinataUI();
  refreshMotiveSelects();
  updateTablesFooter(data.distrRand1, data.distrRand2);
}

// Modul FastFood: fara ecran de mese, fara Marcare/Transfer/Nota Proforma.
function isFastFood() {
  return POS_STATE.tipVanz === "fastfood";
}

// Aplica vizibilitatea butoanelor in functie de tipul de vanzare.
function applyTipVanzUI() {
  const fastfood = isFastFood();
  const ids = ["btn-marcare", "btn-transfer", "btn-proforma", "btn-mod-preparare"];
  ids.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.display = fastfood ? "none" : "";
  });
  document.body.classList.toggle("fastfood-mode", fastfood);
  // FastFood are o singura nota, pe masa 1 (nu exista ecran de mese). Daca
  // modul se activeaza in timpul lucrului pe o masa de Restaurant, nu mostenim
  // masa respectiva: altfel comenzile din FastFood ajung pe masa aceea (bug
  // real: produsele marcate in FastFood au ajuns pe masa 8).
  if (fastfood) {
    clearCurrentTable();
    if (Number(POS_STATE.masaCurenta) !== 1 || POS_STATE.docId) {
      POS_STATE.masaCurenta = 1;
      loadOrder(1);
    }
  }
  // In FastFood nu se afiseaza niciodata ecranul de mese.
  if (fastfood) {
    const active = document.querySelector(".pos-screen.active");
    if (active && active.id === "screen-mese") {
      navigateToScreen("screen-marcare");
    }
  }
}

// Afiseaza/ascunde butoanele de discount in functie de tblSet.RED.
function applyRedUI() {
  const allowed = POS_STATE.red !== 0;
  document.querySelectorAll(".js-discount-btn").forEach(el => {
    el.style.display = allowed ? "" : "none";
  });
}

// Eticheta procentului de discount (fara zecimale inutile).
function discountPctLabel(v) {
  const n = Number(v);
  if (!isFinite(n)) return "0";
  return (Math.round(n * 100) / 100).toString();
}

// Actualizeaza eticheta butoanelor de discount in functie de tblSet.TipDiscount.
function applyTipDiscountUI() {
  const tip = POS_STATE.tipDiscount;
  let label = "Discount";
  if (tip === 1) {
    label = "Discount %";
  } else if (tip === 2) {
    label = "Discount " + discountPctLabel(POS_STATE.procentDiscountFix) + "%";
  }
  document.querySelectorAll(".js-discount-btn").forEach(el => {
    el.textContent = label;
  });
}

// In ecranul de programare: cand tblSet.Server = 1 (export/sincronizare activ),
// Grupe si Produse sunt blocate (datele vin din sincronizare), iar Sincronizare
// produse, Conectare server si Coada export sunt active; cand Server = 0 este invers
// (fara server nu se scrie nimic in coada de export).
function applyServerModeUI() {
  const serverOn = (POS_STATE.server === 1);
  const set = (id, disabled) => {
    const el = document.getElementById(id);
    if (el) el.disabled = disabled;
  };
  set("prog-btn-grupe", serverOn);
  set("prog-btn-produse", serverOn);
  set("prog-btn-sync", !serverOn);
  set("prog-btn-conectare", !serverOn);
  set("prog-btn-send-queue", !serverOn);
}

// Footer-ul ecranului mese: datele de contact din tblSet (DistrRand1/2)
function updateTablesFooter(line1, line2) {
  const el1 = document.getElementById("footer-distr1");
  const el2 = document.getElementById("footer-distr2");
  if (el1) el1.textContent = line1 || "";
  if (el2) el2.textContent = line2 || "";
}

/**
 * Fontul si marimea efectiva pentru un buton din panoul din dreapta.
 * Prioritate: valoarea proprie a grupei/produsului (tblGrp/tblProd), apoi
 * setarea implicita din Setari (tblSet.Font / tblSet.Size), apoi CSS-ul.
 */
function effectiveFontSize(item) {
  const own = parseInt(item && item.FontSize, 10);
  if (own > 0) return own;
  const def = parseInt(POS_STATE.sizePanel, 10);
  return (def >= 6 && def <= 72) ? def : 0;
}

function effectiveFontType(item) {
  const own = item && item.FontType ? String(item.FontType).trim() : "";
  if (own !== "") return own;
  return POS_STATE.fontPanel ? String(POS_STATE.fontPanel).trim() : "";
}

/**
 * Randare GRUPE pe panoul albastru (Grila 5x10, Pozitii 1..50 din tblGrp)
 */
function renderMenuGrid() {
  POS_STATE.currentPanelMode = "groups";
  POS_STATE.activeGroup = null;

  const header = document.getElementById("group-nav-header");
  if (header) header.style.display = "none";

  const gridContainer = document.getElementById("menu-5x-grid");
  if (!gridContainer) return;

  gridContainer.innerHTML = "";

  // Harta Pozitie -> Grup din tblGrp
  const posMap = {};
  POS_STATE.groups.forEach(g => {
    if (g.Poz >= 1 && g.Poz <= 50) {
      posMap[g.Poz] = g;
    }
  });

  for (let poz = 1; poz <= 50; poz++) {
    const grp = posMap[poz];
    if (grp) {
      const btn = document.createElement("button");
      btn.className = "grid-pos-btn";
      
      // Stiluri din baza de date
      if (grp.BackColor) {
        btn.style.backgroundColor = grp.BackColor;
      }
      if (grp.FontColor) {
        btn.style.color = grp.FontColor;
      }
      if (grp.Bold) {
        btn.style.fontWeight = "bold";
      }
      const menuFontSize = effectiveFontSize(grp);
      if (menuFontSize) {
        btn.style.fontSize = menuFontSize + "px";
      }
      const menuFontType = effectiveFontType(grp);
      if (menuFontType) {
        btn.style.fontFamily = menuFontType;
      }

      // Butoane speciale cyan (WR1, WR2, WR3)
      if (grp.Denumire.startsWith("WR")) {
        btn.classList.add("btn-cyan");
      }

      btn.innerHTML = grp.Denumire.replace(/\s+/g, "<br>");
      btn.onclick = () => openCategory(grp.NrGrp, grp.Denumire);
      gridContainer.appendChild(btn);
    } else {
      // Slot gol invizibil pentru a pastra alinierea exacta
      const emptyDiv = document.createElement("div");
      emptyDiv.className = "grid-pos-btn empty";
      gridContainer.appendChild(emptyDiv);
    }
  }
}

/**
 * Deschidere grupa -> Afisare PRODUSE DIRECT PE PANOUL ALBASTRU
 * Foloseste tblProd.Poz, tblProd.BackColor, tblProd.FontColor, tblProd.Bold
 * Daca BackColor == 0 (negru), aplica FontColor alb
 */
function openCategory(nrGrp, groupName) {
  const produse = POS_STATE.productsByGroup[nrGrp] || [];
  if (produse.length === 0) {
    showToast(tr("msg.grupa_fara_produse", { grupa: groupName }));
    return;
  }

  POS_STATE.currentPanelMode = "products";
  POS_STATE.activeGroup = { nrGrp, groupName };

  // Afisam bara superioara cu numele grupei si buton de inapoi
  const header = document.getElementById("group-nav-header");
  const title = document.getElementById("active-group-title");
  const backBtn = document.getElementById("btn-group-back");
  if (header && title) {
    header.style.display = "flex";
    title.innerText = groupName;
    if (backBtn) {
      backBtn.innerText = tr("⬅ INAPOI LA GRUPE");
      backBtn.onclick = () => returnToGroups();
    }
  }

  showMenuHeader(groupName);
  renderProductGrid(produse);
}

// Afiseaza bara superioara cu titlul panoului si butonul de inapoi la grupe
function showMenuHeader(titleText) {
  const header = document.getElementById("group-nav-header");
  const title = document.getElementById("active-group-title");
  const backBtn = document.getElementById("btn-group-back");
  if (header && title) {
    header.style.display = "flex";
    title.innerText = titleText;
    if (backBtn) {
      backBtn.innerText = tr("⬅ INAPOI LA GRUPE");
      backBtn.onclick = () => returnToGroups();
    }
  }
}

// Randeaza o lista de produse in grila 5x10 (pozitii 1..50).
// Daca ignorePoz=true, produsele se aseaza strict in ordinea primita (slot 1,2,...).
function renderProductGrid(produse, ignorePoz) {
  const gridContainer = document.getElementById("menu-5x-grid");
  if (!gridContainer) return;

  gridContainer.innerHTML = "";

  let slots;
  if (ignorePoz) {
    slots = produse.slice(0, 50);
  } else {
    // Harta Pozitie -> Produs (Pozitii 1..50).
    // La aceeasi pozitie castiga produsul din Meniul Zilei (MZ = 1); daca
    // niciunul nu e in Meniul Zilei (sau ambele sunt), ramane primul produs
    // primit de la server (ordinea din menu.php: Poz, Denumire).
    const prodPosMap = {};
    const prodPosIdx = {};
    const pePozitie = new Set();

    produse.forEach((p, idx) => {
      const poz = Number(p.Poz);
      if (!(poz >= 1 && poz <= 50)) return;
      const idxExistent = prodPosIdx[poz];
      if (idxExistent === undefined) {
        prodPosMap[poz] = p;
        prodPosIdx[poz] = idx;
        pePozitie.add(idx);
        return;
      }
      const existentMZ = Number(produse[idxExistent].MZ) === 1;
      const nouMZ = Number(p.MZ) === 1;
      if (!existentMZ && nouMZ) {
        prodPosMap[poz] = p;
        prodPosIdx[poz] = idx;
        pePozitie.delete(idxExistent);
        pePozitie.add(idx);
      }
    });

    // Produsele fara pozitie proprie (Poz in afara 1..50 sau Poz deja ocupat)
    // intra pe primele sloturi libere, in ordinea primita de la server.
    const produseFaraPoz = produse.filter((p, idx) => !pePozitie.has(idx));

    slots = [];
    for (let poz = 1; poz <= 50; poz++) {
      let p = prodPosMap[poz];
      // Daca slotul e liber si avem produse fara pozitie fixa, le plasam
      if (!p && produseFaraPoz.length > 0) {
        p = produseFaraPoz.shift();
      }
      slots.push(p || null);
    }
  }

  // Umplem grila 1..50
  for (let poz = 0; poz < 50; poz++) {
    const p = slots[poz];

    if (p) {
      const btn = document.createElement("button");
      btn.className = "grid-pos-btn grid-prod-btn";

      // Verificam daca este fond negru (BackColor = 0 sau #000000)
      const isBlackBg = (p.rawBackColor === 0 || p.BackColor === "#000000");

      if (isBlackBg) {
        btn.style.backgroundColor = "#000000";
        btn.style.color = "#ffffff";
        btn.style.borderColor = "#555555";
      } else {
        if (p.BackColor) btn.style.backgroundColor = p.BackColor;
        if (p.FontColor) btn.style.color = p.FontColor;
      }

      if (p.Bold) {
        btn.style.fontWeight = "bold";
      }
      const prodFontSize = effectiveFontSize(p);
      if (prodFontSize) {
        btn.style.fontSize = prodFontSize + "px";
      }
      const prodFontType = effectiveFontType(p);
      if (prodFontType) {
        btn.style.fontFamily = prodFontType;
      }

      btn.innerHTML = escapeHtml(capitalizeName(p.Denumire));

      btn.onclick = () => addProduct(p.ProdID);
      gridContainer.appendChild(btn);
    } else {
      // Slot gol invizibil
      const emptyDiv = document.createElement("div");
      emptyDiv.className = "grid-pos-btn empty";
      gridContainer.appendChild(emptyDiv);
    }
  }
}

// Re-randeaza panoul din dreapta pastrand nivelul curent (grupe / grupa
// deschisa / Meniul Zilei), ca setarile de font sa se aplice imediat dupa
// Salveaza, fara sa fie nevoie de repornirea aplicatiei.
function refreshMenuPanel() {
  const mode = POS_STATE.currentPanelMode;
  const grp = POS_STATE.activeGroup;
  if (mode === "products" && grp) {
    openCategory(grp.nrGrp, grp.groupName);
  } else if (mode === "meniu") {
    const produse = getMeniulZileiProducts();
    if (produse.length > 0) {
      showMenuHeader("MENIUL ZILEI");
      renderProductGrid(produse, true);
    } else {
      renderMenuGrid();
    }
  } else {
    renderMenuGrid();
  }
}

// Toate produsele cu MZ = 1, in ordine alfabetica dupa denumire
function getMeniulZileiProducts() {
  const all = [];
  Object.values(POS_STATE.productsByGroup).forEach(list => {
    (list || []).forEach(p => {
      if (p.MZ === 1) all.push(p);
    });
  });
  all.sort((a, b) => String(a.Denumire).localeCompare(String(b.Denumire), "ro"));
  return all;
}

// Afiseaza/ascunde butonul "Meniul Zilei" din bara de actiuni
function updateMeniulZileiButton() {
  const btn = document.getElementById("btn-meniul-zilei");
  if (!btn) return;
  const has = getMeniulZileiProducts().length > 0;
  btn.style.display = (POS_STATE.meniulZilei === 1 && has) ? "" : "none";
}

// Butonul "Meniul Zilei": umple panoul albastru cu produsele MZ=1, alfabetic
function actionMeniulZilei() {
  const produse = getMeniulZileiProducts();
  if (produse.length === 0) {
    showToast("Nu exista produse in Meniul Zilei");
    return;
  }
  POS_STATE.currentPanelMode = "meniu";
  POS_STATE.activeGroup = null;
  showMenuHeader("MENIUL ZILEI");
  renderProductGrid(produse, true);
  showToast("Meniul Zilei");
}

// --------------------------------------------------------------------------
// SCANARE COD DE BARE / PRODID (campul galben din ecranul de marcare)
// --------------------------------------------------------------------------
function onScanKeydown(e) {
  if (e.key === "Enter" || e.keyCode === 13) {
    e.preventDefault();
    const input = e.target;
    const code = String(input.value || "").trim();
    input.value = "";
    if (code !== "") handleScan(code);
  }
}

// Cauta mai intai dupa BarCod, apoi (fallback) dupa ProdID
function handleScan(code) {
  let found = null;

  Object.values(POS_STATE.productsByGroup).forEach(list => {
    (list || []).forEach(p => {
      if (!found && String(p.BarCod || "") === code) found = p;
    });
  });

  if (!found) {
    const idNum = parseInt(code, 10);
    if (!isNaN(idNum)) {
      Object.values(POS_STATE.productsByGroup).forEach(list => {
        (list || []).forEach(p => {
          if (!found && Number(p.ProdID) === idNum) found = p;
        });
      });
    }
  }

  if (found) {
    addProduct(found.ProdID);
  } else {
    appAlert("Produs negasit: " + code);
  }
}

// Pastreaza focusul pe campul de scanare cat timp suntem in ecranul de
// marcare si nu exista niciun popup/modal activ.
function focusScanInput() {
  const marcare = document.getElementById("screen-marcare");
  const input = document.getElementById("scan-input");
  if (!marcare || !input) return;
  if (!marcare.classList.contains("active")) return;
  if (document.querySelector(".modal-keyboard-overlay.active")) return;

  const ae = document.activeElement;
  if (ae && ae !== input && (ae.tagName === "INPUT" || ae.tagName === "TEXTAREA" || ae.tagName === "SELECT")) {
    return;
  }
  if (document.activeElement !== input) input.focus();
}

/**
 * Intoarcere din modul de produse la lista de grupe
 */
function returnToGroups() {
  renderMenuGrid();
}

/**
 * Comportament buton "Inapoi" din bara inferioara:
 * - Daca suntem in modul de produse -> ne intoarce la grupe
 * - Daca suntem deja in grupe -> ne duce la ecranul de mese
 */
function handleInapoiBtn() {
  if (POS_STATE.currentPanelMode === "products" || POS_STATE.currentPanelMode === "meniu") {
    returnToGroups();
    return;
  }
  if (POS_STATE.currentPanelMode === "mods") {
    backFromMods();
    return;
  }
  if (POS_STATE.currentPanelMode === "search") {
    renderMenuGrid();
    return;
  }

  // Parasim masa catre ecranul de mese: trimitem automat la sectie tot ce nu
  // a fost inca trimis (Preluat = 0) pe masa curenta.
  // Mod FastFood: nu exista ecran de mese si nu se trimite nimic la sectie.
  if (isFastFood()) return;
  leaveTableToTables();
}

let leavingTableInProgress = false;

async function leaveTableToTables() {
  if (isFastFood()) return;
  if (leavingTableInProgress) return;
  leavingTableInProgress = true;
  try {
    const docId = POS_STATE.docId;
    if (!docId) { return; }
    // Nota fara nicio linie: o inchidem (serverul o sterge), altfel masa ar
    // ramane ocupata degeaba pe ecranul de mese.
    if (POS_STATE.articole.length === 0) {
      if (POS_STATE.bankPosLocked === true) {
        // Plata pe card incerta: nu o stergem tacit. Operatorul o inchide din
        // NOTA, unde confirma explicit riscul de desincronizare.
        showToast(POS_STATE.bankPosApproved === true
          ? tr("Nota are o plata pe card confirmata ({suma} Lei). Inchideti nota din NOTA.", {
              suma: (Number(POS_STATE.bankPosApprovedTotal) || 0).toFixed(2)
            })
          : tr("Nota are o plata pe card cu stare incerta. Inchideti nota din NOTA."));
        return;
      }
      await closeEmptyNoteOnLeave(docId);
      return;
    }
    const res = await printKitchen(docId);
    if (res && res.jobs > 0) {
      showToast(res.message || "Comanda a fost trimisa la sectie");
    }
  } finally {
    // Am parasit masa: un refresh nu mai trebuie sa ne readuca pe ea.
    clearCurrentTable();
    navigateToScreen("screen-mese");
    leavingTableInProgress = false;
  }
}

// Inchide (sterge) o nota goala la iesirea din masa, fara sa blocheze
// navigarea daca serverul nu raspunde: masa se reincarca oricum la intrarea in
// ecranul de mese.
async function closeEmptyNoteOnLeave(docId) {
  try {
    const resp = await fetch("api/order_action.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "close_bill", docId: docId, plati: {} })
    });
    const res = await resp.json();
    if (res && (res.status === "success" || res.alreadyClosed)) {
      clearOrderPanelLocal();
    }
  } catch (err) {
    /* la iesirea din masa nu blocam navigarea */
  }
}

// --------------------------------------------------------------------------
// 2. INCARCARE SI GESTIUNE COMANDA (tblBonCurent & tblNoteD)
// --------------------------------------------------------------------------
async function loadOrder(nrMasa) {
  try {
    const resp = await fetch(`api/get_order.php?masa=${nrMasa}`);
    const data = await resp.json();

    if (data.status !== "success") throw new Error(data.message);

    POS_STATE.masaCurenta = nrMasa;
    POS_STATE.docId = data.docId || null;
    // CUI-ul (codul fiscal al clientului) este tinut pe nota in
    // tblBonCurent.CodFiscBon; il preluam de la server ca sa fie pastrat si
    // dupa un refresh al paginii sau la revenirea pe nota.
    POS_STATE.cuiDocId = POS_STATE.docId;
    POS_STATE.cui = (data.codFiscBon || "");
    updateCuiButton();
    POS_STATE.articole = data.articole || [];
    // Proprietarul bonului (ospatarul care l-a deschis) - nu suprascriem
    // casierul curent (operatorul logat).
    POS_STATE.bonNrOp = (data.nrOp != null) ? Number(data.nrOp) : null;
    POS_STATE.bonCasier = data.casier || null;
    // Lock-ul bancar vine de la server: nota cu plata pe card incerta este
    // doar citire pana la clarificarea platii.
    POS_STATE.bankPosLocked = (data.bankPosLocked === true);
    POS_STATE.bankPosLockAmount = data.bankPosAmount || "";
    POS_STATE.bankPosUid = data.bankPosUid || "";
    POS_STATE.bankPosApproved = (data.bankPosApproved === true);
    POS_STATE.bankPosApprovedTotal = Number(data.bankPosApprovedTotal) || 0;
    POS_STATE.bankPosUncertain = (data.bankPosUncertain === true);
    POS_STATE.bankPosResolved = (data.bankPosResolved === true);
    POS_STATE.bankPosPending = (data.bankPosPending === true);
    applyBankPosLockUI();
    refreshBankReprintButton();
    if (POS_STATE.bankPosLocked) {
      // Dupa un reload stim doar ca nota e blocata: luam si capabilitatile
      // protocolului (Verifica apare doar daca exista jurnal) si TRX-ul la zi.
      refreshBankPosCheckButton();
    }

    // Sincronizam interfata
    document.getElementById("current-table-btn").innerText = nrMasa;
    const cashierEl = document.getElementById("current-cashier-label");
    if (cashierEl) cashierEl.innerText = POS_STATE.loggedIn ? (POS_STATE.casierCurent || "") : tr("Neautentificat");
    
    // Ecranul de marcare nu are element de data/ora: locul din coltul stanga-sus
    // este ocupat de caseta de scanare (#scan-input). "order-datetime" era doar
    // clasa CSS; singurul element real este #pay-datetime, pe ecranul de plata,
    // setat la deschiderea lui. Ramura veche, care cauta un id inexistent, a
    // fost eliminata (tests/REPORT.md B-09).

    renderOrderItems(data.subtotal || 0, data.total || 0);
    // Un bon nou incepe: panoul revine la forma initiala (cu linia Total).
    hideFastFoodReceipt();
  } catch (err) {
    console.error("Eroare la incarcare comanda:", err);
  }
}

// Dupa close_bill nota nu mai exista. Curatam local panoul, fara sa mai
// asteptam un loadOrder: acela poate fi lent sau poate esua exact dupa
// inchidere si ar lasa pe ecran liniile notei deja inchise. In FastFood,
// showFastFoodReceipt() afiseaza apoi confirmarea platii pana la nota urmatoare.
function clearOrderPanelLocal() {
  POS_STATE.docId = null;
  POS_STATE.articole = [];
  POS_STATE.selectedItemIndex = -1;
  POS_STATE.bonNrOp = null;
  POS_STATE.bonCasier = null;
  POS_STATE.cui = "";
  POS_STATE.cuiDocId = null;
  // Nota nu mai exista: nici masa memorata pentru un refresh nu mai are sens.
  clearCurrentTable();
  // Nota nu mai exista: lock-ul bancar nu o mai urmareste.
  POS_STATE.bankPosLocked = false;
  POS_STATE.bankPosLockAmount = "";
  POS_STATE.bankPosUid = "";
  POS_STATE.bankPosApproved = false;
  POS_STATE.bankPosApprovedTotal = 0;
  POS_STATE.bankPosUncertain = false;
  POS_STATE.bankPosResolved = false;
  POS_STATE.bankPosPending = false;
  // Registrul de card al notei inchise nu mai e valabil (banii sunt deja in
  // tblPosBancaLog si pe bon). Nota urmatoare porneste cu ledger gol.
  cardLedgerReset();
  applyBankPosLockUI();
  updateCuiButton();
  renderOrderItems(0, 0);
  hideFastFoodReceipt();
  refreshBankReprintButton();
}

function lineVal(item) {
  const orig = (typeof item.valoare === "number") ? item.valoare
    : (parseFloat(item.valoare) || 0);
  if (orig > 0) return orig;
  const q = parseFloat(item.cantitate) || 0;
  const p = parseFloat(item.pretUnitar) || 0;
  return q * p;
}

// Valoarea de catalog a liniei (Cant * PVC), inainte de discount
function lineValOriginal(item) {
  const v = (typeof item.valoareOriginala === "number")
    ? item.valoareOriginala
    : parseFloat(item.valoareOriginala);
  if (!isNaN(v)) return v;
  const q = parseFloat(item.cantitate) || 0;
  const p = parseFloat(item.pvc) || parseFloat(item.pretUnitar) || 0;
  return q * p;
}

// Valoarea NETA a liniei (cantitate ramasa dupa voidari * PVC). Este baza pe care
// se poate aplica discount: o linie complet voidata are valoarea 0.
function lineValNet(item) {
  if (!item || item.storno) return 0;
  const cant = parseFloat(item.cantitate) || 0;
  const stornat = parseFloat(item.stornat) || 0;
  const ramas = (typeof item.ramas === "number") ? item.ramas : (cant - stornat);
  const p = parseFloat(item.pvc) || parseFloat(item.pretUnitar) || 0;
  return Math.max(0, ramas) * p;
}

// Formateaza o cantitate cu numarul de zecimale configurat (tblSet.NrZecCant).
function formatQtyCant(v) {
  const n = Number(v || 0);
  return n.toFixed(POS_STATE.nrZecCant);
}

function renderOrderItems(subtotalCalculat = null, totalCalculat = null) {
  const container = document.getElementById("order-items-list");
  if (!container) return;

  container.innerHTML = "";

  if (POS_STATE.articole.length && POS_STATE.selectedItemIndex >= POS_STATE.articole.length) {
    POS_STATE.selectedItemIndex = POS_STATE.articole.length - 1;
  }

  let sumOriginal = 0;
  let sumNet = 0;
  let sumTva = 0;

  POS_STATE.articole.forEach((item, index) => {
    const orig = lineValOriginal(item);
    const net = lineVal(item);
    const isStorno = !!item.storno;
    // Doar discountul PE PRODUS (linie) se evidentiaza pe rand (badge); cel pe
    // subtotal apare doar in totalul de reducere de sub subtotal.
    const isLineDisc = !isStorno && !!item.comment;
    const isDisc = isLineDisc && net < orig - 0.005;
    sumOriginal += orig;
    sumNet += net;

    const cota = parseFloat(item.tva) || 0;
    sumTva += net - (net / (1 + cota / 100));

    const row = document.createElement("div");
    row.className = `order-row ${index === POS_STATE.selectedItemIndex ? "selected" : ""} ${isDisc ? "discounted" : ""} ${isStorno ? "storno-row" : ""}`;
    row.onclick = (e) => {
      if (e.target.type !== "checkbox" && !e.target.closest(".mod-del-btn")) {
        selectOrderItem(index);
      }
    };

    const numeAfis = (isStorno ? "ANULAT: " : "") + escapeHtml(capitalizeName(item.denumire));
    const qtyLocked = !isStorno && !!item.preluat;
    const qtyHtml = isStorno
      ? `<div class="order-row-qty">${formatQtyCant(item.cantitate)}</div>`
      : (qtyLocked
          ? `<div class="order-row-qty qty-locked" title="Trimis la sectie">${formatQtyCant(item.cantitate)}</div>`
          : `<div class="order-row-qty ${index === POS_STATE.selectedItemIndex ? "active" : ""}" title="Cantitate" onclick="openQtyModal(${index}, event)">${formatQtyCant(item.cantitate)}</div>`);

    row.innerHTML = `
      <div class="order-row-name">${numeAfis}</div>
      ${qtyHtml}
      <div class="order-row-val">${orig.toFixed(2)}${isDisc ? '<span class="disc-badge">↓</span>' : ""}</div>
    `;

    container.appendChild(row);

    (item.mods || []).forEach(m => {
      const mrow = document.createElement("div");
      mrow.className = "order-mod-row";
      mrow.innerHTML = `
        <span class="order-mod-text">${escapeHtml(m.text)}</span>
        <button class="mod-del-btn" title="${tr('Sterge modul')}" onclick="deleteMod(${m.ecrId})">✕</button>
      `;
      container.appendChild(mrow);
    });
  });

  scrollSelectedRowIntoView();

  // Subtotal = suma de catalog a liniilor; Total = suma neta (dupa discount)
  let subtotal = sumOriginal;
  let total = sumNet;
  if (typeof subtotalCalculat === "number" && subtotalCalculat > 0) subtotal = subtotalCalculat;
  if (typeof totalCalculat === "number" && totalCalculat > 0) total = totalCalculat;

  // Reducerea (in lei) = diferenta dintre catalog si net
  const redTotal = Math.max(0, subtotal - total);

  document.getElementById("val-subtotal").innerText = subtotal.toFixed(2);
  document.getElementById("val-reducere").innerText = redTotal.toFixed(2);
  document.getElementById("val-tva").innerText = sumTva.toFixed(2);
  document.getElementById("val-total").innerText = total.toFixed(2);
  // Banii de pe card + restul de plata (dupa un reload vin din audit).
  updateCardPaymentFooter();
}

function selectOrderItem(index) {
  if (index >= 0 && index < POS_STATE.articole.length) {
    POS_STATE.selectedItemIndex = index;
    renderOrderItems();
  }
}

// Asigura ca produsul selectat (impreuna cu modurile lui de preparare,
// daca are) este mereu vizibil, aliniat la finalul blocului -> auto-scroll
function scrollSelectedRowIntoView() {
  const container = document.getElementById("order-items-list");
  if (!container) return;
  const rows = container.querySelectorAll(".order-row");
  if (!rows.length) return;
  const idx = Math.min(Math.max(0, POS_STATE.selectedItemIndex), rows.length - 1);
  const selRow = rows[idx];

  // Ultimul rand al blocului selectat: produsul + randurile de mod de sub el
  let target = selRow;
  let el = selRow.nextElementSibling;
  while (el && el.classList.contains("order-mod-row")) {
    target = el;
    el = el.nextElementSibling;
  }

  target.scrollIntoView({ block: "end" });
}

// Selecteaza ultimul produs din nota si il aduce in vizor (deschidere masa)
function selectLastAndScroll() {
  if (!POS_STATE.articole.length) return;
  POS_STATE.selectedItemIndex = POS_STATE.articole.length - 1;
  renderOrderItems();
}

async function addProduct(prodId) {
  // O nota cu plata pe card incerta este blocata (serverul refuza oricum cu
  // 423): nu se mai adauga produse pana la clarificarea platii.
  if (bankPosBlockedNotice()) { return; }
  // Marcarea se face doar de catre un ospatar autentificat.
  if (!POS_STATE.loggedIn) {
    openLoginModal(() => addProduct(prodId));
    return;
  }
  try {
    const payload = {
      action: "add_product",
      nrMasa: POS_STATE.masaCurenta,
      nrOp: POS_STATE.nrOp,
      prodId: prodId,
      cantitate: 1.0
    };

    const resp = await fetch("api/order_action.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });

    const res = await resp.json();
    if (res.status !== "success") {
      const errAdd = new Error(res.message);
      errAdd.code = res.code || "";
      throw errAdd;
    }

    await loadOrder(POS_STATE.masaCurenta);
    await loadTables();

    // Produsul nou este intotdeauna un rand nou, la sfarsitul notei:
    // il selectam si il aducem in vizor cu scroll automat.
    if (POS_STATE.articole.length) {
      POS_STATE.selectedItemIndex = POS_STATE.articole.length - 1;
      renderOrderItems();
    }
    focusScanInput();
  } catch (err) {
    // Blocajul bancar nu este o eroare de baza de date: serverul refuza
    // modificarea (423) pentru ca banii de pe card nu sunt inca pe nota. Afisam
    // mesajul de lock si reimprospatam bara, ca butoanele de clarificare sa
    // apara imediat (in loc de "Eroare la salvare in baza de date").
    const code = String((err && err.code) || "");
    if (code === "bank_pos_payable" || code === "bank_pos_uncertain") {
      await loadOrder(POS_STATE.masaCurenta);
      bankPosBlockedNotice();
      return;
    }
    appAlert("Eroare la salvare in baza de date: " + err.message);
  }
}

async function toggleCookDb(ecrId, isChecked) {
  if (!ecrId) return;
  if (POS_STATE.bankPosLocked === true) {
    // Lock bancar: bifa se reverteaza in UI, nota nu se modifica.
    await loadOrder(POS_STATE.masaCurenta);
    bankPosBlockedNotice();
    return;
  }
  try {
    await fetch("api/order_action.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        action: "toggle_cook",
        ecrId: ecrId,
        cook: isChecked
      })
    });
  } catch (err) {
    console.error("Eroare toggle cook:", err);
  }
}

function scrollOrder(direction) {
  if (POS_STATE.articole.length === 0) return;
  if (direction === "up" && POS_STATE.selectedItemIndex > 0) {
    selectOrderItem(POS_STATE.selectedItemIndex - 1);
  } else if (direction === "down" && POS_STATE.selectedItemIndex < POS_STATE.articole.length - 1) {
    selectOrderItem(POS_STATE.selectedItemIndex + 1);
  }
}

// --------------------------------------------------------------------------
// 3. ECRAN MESE (tblMese & tblBonCurent)
// --------------------------------------------------------------------------
async function loadTables() {
  try {
    const resp = await fetch("api/get_tables.php");
    const data = await resp.json();
    if (data.status === "success") {
      POS_STATE.tables = data.tables || [];
      renderTablesGrid();
      refreshPrintStatus();
    }
  } catch (err) {
    console.error("Eroare la incarcare mese:", err);
  }
}

function renderTablesGrid() {
  const container = document.getElementById("tables-grid-container");
  if (!container) return;

  container.innerHTML = "";

  // Intotdeauna randam toate cele 80 de sloturi, ca mesele sa isi pastreze pozitia fixa.
  // Sloturile fara masa vizibila raman goale (transparente, se vede fundalul verde).
  POS_STATE.tables.forEach(t => {
    // Masa cu bon deschis => intotdeauna vizibila si selectabila
    if (t.isOccupied) {
      const occ = document.createElement("div");
      occ.className = "table-cell-btn table-occupied";
      occ.innerHTML = `<div>${t.nrMasa}</div><div style="font-size: 11px;">${escapeHtml(t.casier || tr("OCUPAT"))}<br>${t.total.toFixed(0)} L</div>`;
      occ.onclick = () => selectTableAndOpen(t.nrMasa);
      container.appendChild(occ);
      return;
    }

    // Nu este masa reala (fara rand in tblMese) sau Afisez = 0 => slot gol (se vede verdele)
    const hiddenSlot = !t.present || (t.hasConfig && t.afisez === false);
    if (hiddenSlot) {
      const empty = document.createElement("div");
      empty.className = "table-cell-btn table-empty-slot";
      container.appendChild(empty);
      return;
    }

    const btn = document.createElement("div");
    btn.className = "table-cell-btn";

    // Culoare: config din tblMese daca exista, altfel benzi conform capturii originale
    let styled = false;
    if (t.hasConfig) {
      const hasBg = t.backColor !== null && t.backColor !== undefined;
      const hasFg = t.foreColor !== null && t.foreColor !== undefined;
      if (hasBg) {
        btn.style.background = colorIntToHex(t.backColor);
        styled = true;
      }
      if (hasFg) btn.style.color = colorIntToHex(t.foreColor);
      if (t.bold) btn.style.fontWeight = "bold";
    }
    if (!styled) {
      if (t.nrMasa <= 20) btn.classList.add("band-black");
      else if (t.nrMasa <= 40) btn.classList.add("band-blue");
      else if (t.nrMasa <= 60) btn.classList.add("band-green");
      else btn.classList.add("band-white");
    }

    btn.innerHTML = `<div>${t.label}</div>`;
    btn.onclick = () => {
      selectTableAndOpen(t.nrMasa);
    };

    container.appendChild(btn);
  });
}

async function selectTableAndOpen(numarMasa) {
  // Un ospatar trebuie sa fie autentificat ca sa intre/deschida o masa.
  if (!POS_STATE.loggedIn) {
    openLoginModal(() => selectTableAndOpen(numarMasa));
    return;
  }

  // Verificare rapida din starea meselor (cea autoritara se face pe server).
  const t = POS_STATE.tables.find(x => x.nrMasa === numarMasa);
  if (t && t.isOccupied && t.nrOp != null && Number(t.nrOp) !== Number(POS_STATE.nrOp)) {
    appAlert(tr("msg.masa_alt_ospatar", { masa: numarMasa, casier: t.casier || "?" }));
    return;
  }

  // Incarcam comanda ca sa aflam cine a deschis bonul.
  await loadOrder(numarMasa);

  // Un ospatar nu poate intra pe masa deschisa de alt ospatar.
  if (POS_STATE.docId && POS_STATE.bonNrOp != null && Number(POS_STATE.bonNrOp) !== Number(POS_STATE.nrOp)) {
    appAlert(tr("msg.masa_alt_ospatar", { masa: numarMasa, casier: POS_STATE.bonCasier || "?" }));
    return;
  }

  returnToGroups();
  // Masa pe care lucram: memorata pentru un eventual refresh al paginii.
  saveCurrentTable(numarMasa);
  showToast(`Comutare pe Masa ${numarMasa}`);
  navigateToScreen("screen-marcare");
  selectLastAndScroll();
}

// --------------------------------------------------------------------------
// 4. INCHIDERE NOTA SI PLATA (Inchidere_nota.PNG)
// --------------------------------------------------------------------------

// Cand PlataCombinata = NU (0), ecranul de plata ascunde numpad-ul si afisajul
// de sume: se vad doar formele de plata, iar un click pe o forma inchide nota
// integral cu acea forma (vezi paySingleForm / renderPaymentMethods).
function applyPlataCombinataUI() {
  const single = (POS_STATE.plataCombinata === 0);
  const center = document.querySelector("#screen-plata .payment-calc-center");
  if (center) { center.style.display = single ? "none" : ""; }
  const grid = document.getElementById("pay-methods-grid");
  if (grid) { grid.classList.toggle("single-mode", single); }
}

function syncPaymentScreen() {
  document.getElementById("pay-tbl-num").innerText = POS_STATE.masaCurenta;
  document.getElementById("pay-cashier-label").innerText = POS_STATE.casierCurent;

  const payList = document.getElementById("pay-items-list");
  payList.innerHTML = document.getElementById("order-items-list").innerHTML;

  const total = document.getElementById("val-total").innerText;
  document.getElementById("pay-subtotal").innerText = document.getElementById("val-subtotal").innerText;
  document.getElementById("pay-reducere").innerText = document.getElementById("val-reducere").innerText;
  document.getElementById("pay-tva").innerText = document.getElementById("val-tva").innerText;
  document.getElementById("pay-total").innerText = total;

  // Resetam platile si incarcam formele de plata din tblFP
  POS_STATE.payments = {};
  POS_STATE.rest = 0;
  POS_STATE.activePaymentFp = null;
  // Cardul declarat (PlataCombinata = 0) apartine interactiunii curente; banii
  // deja taxati pe card stau in cardLedger si NU se pierd la reintrarea pe
  // ecranul de plata (altfel nota se putea inchide fara linia CARD).
  POS_STATE.cardDeclared = 0;
  // Suma implicita = restul de plata, nu totalul notei: cardul aprobat inainte
  // de reload (ledger) si celelalte plati deja inregistrate se scad.
  updatePayAmountDefault(true);

  loadPaymentForms().then(() => {
    renderPaymentMethods();
    applyPlataCombinataUI();
    // Forma de plata cu Poz = 1 este cea implicita (altfel prima din lista).
    // In modul "fara plata combinata" nu selectam nimic: click pe o forma
    // inchide direct nota.
    const def = POS_STATE.paymentForms.find(f => f.Poz === 1) || POS_STATE.paymentForms[0];
    if (def && POS_STATE.plataCombinata !== 0) {
      selectPaymentMethod(def.FPID);
    }
    updatePaymentProgress();
    // refreshBankPosCheckButton() intoarce starea bancara: o refolosim ca sa nu
    // mai facem un al doilea apel `status` pentru preluarea automata.
    refreshBankPosCheckButton().then(st => bankPosAutoAdoptOnPaymentScreen(st));
  });
}

// Incarca formele de plata active (Status = 1) din tblFP, ordonate dupa Poz
async function loadPaymentForms() {
  try {
    const resp = await fetch("api/fp.php");
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message || "Eroare");
    // FPID/Poz se normalizeaza la numere: altfel comparatiile `===` cu
    // parseInt(id) ar rata (ex. "1" === 1 este false) si dialogul de dupa
    // inchidere ar afisa "FP 1" in loc de denumirea formei de plata.
    POS_STATE.paymentForms = (data.rows || [])
      .filter(r => Number(r.Status) === 1)
      .map(r => ({
        FPID: parseInt(r.FPID, 10),
        Denumire: String(r.Denumire == null ? "" : r.Denumire).trim(),
        Status: Number(r.Status),
        Poz: (r.Poz == null || r.Poz === "") ? null : parseInt(r.Poz, 10)
      }))
      .filter(r => !isNaN(r.FPID))
      .sort((a, b) => (a.Poz ?? 9999) - (b.Poz ?? 9999) || a.FPID - b.FPID);
  } catch (err) {
    POS_STATE.paymentForms = [];
    showToast("Eroare forme de plata: " + err.message);
  }
}

// Denumirea formei de plata pentru un FPID (ex. "CARD"), cu fallback pe "FP <id>".
function paymentFormLabel(fpid) {
  const id = parseInt(fpid, 10);
  const f = (POS_STATE.paymentForms || [])
    .find(x => parseInt(x.FPID, 10) === id);
  const nume = f && f.Denumire ? String(f.Denumire).trim() : "";
  return nume !== "" ? nume : ("FP " + (isNaN(id) ? fpid : id));
}

// Simbol (SVG inline) de card bancar, afisat pe butonul formei de plata CARD.
const PAY_CARD_ICON =
  '<svg viewBox="0 0 24 24" width="1.05em" height="1.05em" fill="none" ' +
  'stroke="currentColor" stroke-width="2" stroke-linecap="round" ' +
  'stroke-linejoin="round" aria-hidden="true">' +
  '<rect x="2" y="5" width="20" height="14" rx="2" ry="2"></rect>' +
  '<line x1="2" y1="10" x2="22" y2="10"></line></svg>';

// Randeaza butoanele formelor de plata
function renderPaymentMethods() {
  const grid = document.getElementById("pay-methods-grid");
  if (!grid) return;
  grid.innerHTML = "";

  POS_STATE.paymentForms.forEach(f => {
    const btn = document.createElement("button");
    btn.className = "pay-btn";
    btn.id = "btn-pay-fp-" + f.FPID;

    // Forma de plata "card" (FPID = 1 sau denumire care contine "card"):
    // primește un simbol de card bancar, ca sa fie mai intuitiv.
    const isCard = (f.FPID === 1) || /card/i.test(f.Denumire || "");
    if (isCard) {
      const icon = document.createElement("span");
      icon.className = "pay-btn-icon";
      icon.innerHTML = PAY_CARD_ICON;
      btn.appendChild(icon);
    }

    const label = document.createElement("span");
    label.textContent = f.Denumire;
    btn.appendChild(label);

    btn.onclick = () => {
      if (POS_STATE.plataCombinata === 0) {
        paySingleForm(f.FPID);
      } else {
        selectPaymentMethod(f.FPID);
      }
    };
    grid.appendChild(btn);
  });
}

function selectPaymentMethod(fpid) {
  POS_STATE.activePaymentFp = fpid;
  document.querySelectorAll(".pay-btn").forEach(btn => btn.classList.remove("selected"));
  document.getElementById("btn-pay-fp-" + fpid)?.classList.add("selected");

  document.getElementById("lbl-active-pay-type").innerText = POS_STATE.paymentForms.length
    ? paymentFormLabel(fpid).toUpperCase()
    : "";
}

// Suma implicita din campul de plata: RESTUL de plata (totalul notei minus
// platile deja inregistrate, inclusiv cardul aprobat din ledger), nu totalul
// notei. Fara asta, dupa un reload campul arata 84.00 desi pe terminal s-au
// incasat deja 50 Lei si mai ramane 34 Lei. `fresh` marcheaza valoarea ca
// implicita (prima tasta apasata o inlocuieste).
function updatePayAmountDefault(fresh) {
  const input = document.getElementById("pay-amount-input");
  if (!input) { return; }
  const rest = Math.max(0, posRound2(noteTotal() - paidTotal()));
  input.value = rest.toFixed(2);
  if (fresh !== false) { input.dataset.fresh = "true"; }
}

// Afiseaza platile inregistrate si restul de plata. Linia de card este cea
// derivata din ledger (o singura linie, oricate swipe-uri).
function updatePaymentProgress() {
  const el = document.getElementById("pay-progress");
  if (!el) return;

  const total = parseFloat(document.getElementById("val-total").innerText) || 0;
  const plati = paymentsForClose();
  const totalPaid = Object.values(plati).reduce((a, b) => a + (Number(b) || 0), 0);
  const rest = total - totalPaid;

  const parts = Object.entries(plati).map(([fp, val]) => {
    return `${paymentFormLabel(fp)}: ${Number(val).toFixed(2)}`;
  });

  el.innerText = parts.length
    ? parts.join("   |   ") + "   |   " + tr("Rest: ") + rest.toFixed(2)
    : tr("Total de plata: ") + total.toFixed(2);

  // Campul de suma urmeaza restul de plata cat timp valoarea este cea implicita
  // (nu suprascriem o suma tastata de operator). Acopera reînsămânțarea
  // ledger-ului dupa reload si aprobarile manuale / din jurnal.
  const amountInput = document.getElementById("pay-amount-input");
  if (amountInput && amountInput.dataset.fresh === "true") {
    amountInput.value = Math.max(0, rest).toFixed(2);
  }
  refreshBankReprintButton();
}

function payNumpadKey(key) {
  const input = document.getElementById("pay-amount-input");
  if (input.dataset.fresh === "true" || input.value === "0" || input.value === "0.00") {
    input.value = key === "." ? "0." : key;
    input.dataset.fresh = "false";
  } else {
    if (key === "." && input.value.includes(".")) return;
    input.value += key;
  }
}

function payNumpadClear() {
  const input = document.getElementById("pay-amount-input");
  input.value = "0";
  input.dataset.fresh = "true";
}

function payNumpadBackspace() {
  const input = document.getElementById("pay-amount-input");
  if (input.dataset.fresh === "true") return;

  let v = input.value;
  if (v === "0" || v === "0.00" || v === "") {
    input.value = "0";
    input.dataset.fresh = "true";
    return;
  }
  v = v.slice(0, -1);
  if (v === "" || v === ".") {
    v = "0";
    input.dataset.fresh = "true";
  } else {
    input.dataset.fresh = "false";
  }
  input.value = v;
}

// Mod FastFood: afiseaza detaliile de plata in panoul alb din stanga, in locul
// liniei Total, dupa inchiderea notei. Revine la normal la urmatorul loadOrder
// (inceperea unui nou bon).
function showFastFoodReceipt(payments, total, rest) {
  const box = document.getElementById("fastfood-receipt");
  const totalLine = document.getElementById("order-grand-total-line");
  if (!box) return;

  const parts = Object.entries(payments || {}).map(([id, val]) => {
    return { label: paymentFormLabel(id), val: Number(val) || 0 };
  });

  let html = '<div class="ff-receipt-title">' + tr("PLATA EFECTUATA") + '</div>';
  parts.forEach(p => {
    html += `<div class="ff-receipt-line"><span>${escapeHtml(p.label)}</span><span>${p.val.toFixed(2)}</span></div>`;
  });
  html += `<div class="ff-receipt-line ff-receipt-total"><span>${tr("Total")}</span><span>${(Number(total) || 0).toFixed(2)}</span></div>`;
  if (rest > 0) {
    html += `<div class="ff-receipt-line ff-receipt-rest"><span>${tr("Rest")}</span><span>${Number(rest).toFixed(2)}</span></div>`;
  }
  // Retiparirea chitantei de card ramane posibila si dupa inchidere (cazul
  // "terminalul a ramas fara hartie la ultimul swipe").
  if (POS_STATE.lastCardReprint && Number((payments || {})[1]) > 0) {
    html += '<button class="ff-receipt-reprint" onclick="actionBankPosReprint()">'
      + escapeHtml(tr("Chitanță card")) + '</button>';
  }

  box.innerHTML = html;
  box.style.display = "";
  if (totalLine) totalLine.style.display = "none";
}

function hideFastFoodReceipt() {
  const box = document.getElementById("fastfood-receipt");
  const totalLine = document.getElementById("order-grand-total-line");
  if (box) { box.style.display = "none"; box.innerHTML = ""; }
  if (totalLine) totalLine.style.display = "";
}

// --------------------------------------------------------------------------
// 4a-bis. REGISTRUL PLATILOR PE CARD (ledger)
//
// Banii de pe card sunt tinuti intr-un singur loc (cardLedger) si sunt
// derivati in linia de plata la inchidere. `payments` pastreaza doar celelalte
// forme de plata, deci un cod care ar scrie payments[1] nu poate taxa nimic.
// --------------------------------------------------------------------------

function posRound2(v) {
  return Math.round((Number(v) || 0) * 100) / 100;
}

function noteTotal() {
  return parseFloat(document.getElementById("val-total").innerText) || 0;
}

// Sumele de pe celelalte forme de plata (cardul nu face parte din `payments`).
function otherPaidTotal(plati) {
  const src = plati || POS_STATE.payments || {};
  let sum = 0;
  Object.keys(src).forEach(id => {
    if (String(id) !== "1") { sum += Number(src[id]) || 0; }
  });
  return posRound2(sum);
}

// Cat mai trebuie incasat pe card pentru nota curenta.
function cardNeeded() {
  return Math.max(0, posRound2(noteTotal() - otherPaidTotal()));
}

// Ledger-ul notei curente; se goleste singur cand se schimba DocID-ul.
function cardLedgerForDoc() {
  const docId = POS_STATE.docId || null;
  if (!POS_STATE.cardLedger || POS_STATE.cardLedger.docId !== docId) {
    POS_STATE.cardLedger = { docId: docId, swipes: [] };
  }
  return POS_STATE.cardLedger;
}

function cardLedgerTotal() {
  return posRound2(cardLedgerForDoc().swipes.reduce(
    (a, s) => a + (Number(s.approved) || 0), 0
  ));
}

// Exista in ledger o aprobare reala a terminalului (nu doar suma declarata /
// eroare de configurare)? Doar acelea pot fi retiparite din audit.
function cardLedgerHasTerminalApproval() {
  return cardLedgerForDoc().swipes.some(s =>
    String((s && s.source) || "") !== "config" && (Number(s && s.approved) || 0) > 0
  );
}

function cardLedgerReset() {
  POS_STATE.cardLedger = { docId: POS_STATE.docId || null, swipes: [] };
  POS_STATE.cardDeclared = 0;
  updateCardPaymentFooter();
}

// Suma aprobata pe card pentru nota curenta: ledger-ul local (la zi, cat timp
// fereastra nu a fost reincarcata) sau sumele din auditul serverului
// (`status.approvedEntries` / `get_order.bankPosApprovedTotal`). Se ia maximul,
// ca un Ctrl+F5 sa nu ascunda banii deja incasati pe terminal.
function cardPaidTotal() {
  const ledger = cardLedgerTotal();
  const server = Number(POS_STATE.bankPosApprovedTotal) || 0;
  return posRound2(Math.max(ledger, server));
}

// Subsolul notei: "Achitat CARD x Lei" + "Rest de plata y Lei", cand nota are
// bani aprobati pe card. Suma de pe card nu este o plata inregistrata in nota
// (devine linie CARD doar la inchidere), deci trebuie afisata explicit - altfel
// operatorul vede doar Totalul si nu stie cat s-a incasat deja pe terminal.
function updateCardPaymentFooter() {
  const box = document.getElementById("order-card-paid-box");
  if (!box) { return; }
  const paid = cardPaidTotal();
  if (paid <= 0.005) {
    box.style.display = "none";
    return;
  }
  const total = noteTotal();
  const rest = Math.max(0, posRound2(total - otherPaidTotal() - paid));
  const paidEl = document.getElementById("val-card-paid");
  const restEl = document.getElementById("val-card-rest");
  if (paidEl) { paidEl.innerText = paid.toFixed(2); }
  if (restEl) { restEl.innerText = rest.toFixed(2); }
  box.style.display = "";
}

// Singurul loc care aduna bani pe card. Idempotent pe trxId (fallback logId):
// aceeasi incercare preluata din terminal, din jurnal sau manual se adauga o
// singura data. Returneaza true daca s-a adaugat ceva nou.
function cardLedgerAdd(swipe) {
  const approved = posRound2(swipe && swipe.approved);
  if (approved <= 0) { return false; }
  const lg = cardLedgerForDoc();
  const key = String((swipe && (swipe.trxId || swipe.logId)) || "");
  if (key && lg.swipes.some(s => String(s.trxId || s.logId || "") === key)) {
    return false;
  }
  lg.swipes.push({
    approved: approved,
    requested: posRound2(swipe && swipe.requested),
    source: String((swipe && swipe.source) || "terminal"),
    trxId: String((swipe && swipe.trxId) || ""),
    logId: (swipe && swipe.logId != null) ? swipe.logId : null,
    at: Date.now()
  });
  updateCardPaymentFooter();
  return true;
}

// Reînsămânțeaza ledger-ul din server (dupa reload/crash): fiecare intrare
// aprobata din tblPosBancaLog devine un swipe. Dedupe pe trxId face apelul
// repetat inofensiv.
function cardLedgerSeed(entries) {
  if (!Array.isArray(entries) || entries.length === 0) { return 0; }
  let added = 0;
  entries.forEach(e => {
    if (!e) { return; }
    if (cardLedgerAdd({
      approved: Number(e.amount) || 0,
      requested: Number(e.requested) || Number(e.amount) || 0,
      source: String(e.source || "journal"),
      trxId: String(e.trxId || ""),
      logId: e.logId
    })) {
      added++;
    }
  });
  return added;
}

// Aliniaza ledger-ul la auditul serverului (`status.approvedEntries`), care este
// sursa de adevar pentru banii de pe card: adauga aprobarile care lipsesc (dupa
// un reload) si elimina swipe-urile care nu mai exista in audit (o numarare
// dubla in memorie, ex. Verifica/Manual apasat pe o tranzactie deja preluata).
// Aprobarile locale fara urma in audit (`source = 'config'`, eroare de
// configurare a terminalului) se pastreaza: ele nu sunt incasari pe terminal.
function cardLedgerReconcile(entries) {
  if (!Array.isArray(entries)) { return 0; }
  const lg = cardLedgerForDoc();
  const serverKeys = new Set();
  entries.forEach(e => {
    if (!e) { return; }
    const k = String(e.trxId || e.logId || "");
    if (k) { serverKeys.add(k); }
  });
  lg.swipes = lg.swipes.filter(s => {
    if (String(s.source || "") === "config") { return true; }
    const k = String(s.trxId || s.logId || "");
    // Un swipe fara cheie nu poate fi corelat cu auditul: il pastram doar daca
    // serverul nu a trimis nicio lista (raspuns incomplet / fara aprobari).
    if (k === "") { return entries.length === 0; }
    return serverKeys.has(k);
  });
  return cardLedgerSeed(entries);
}

// Linia de card care intra in nota (0 = fara card). Ledger-ul are prioritate;
// `cardDeclared` acopera plata pe card fara terminal / cu PlataCombinata = 0.
// Se plafoneaza la cat mai e necesar, ca o modificare a notei sa nu lase plata
// peste total (reconcilierea de la close_bill semnaleaza diferenta).
function cardAmountForClose() {
  const taken = cardLedgerTotal();
  const declared = Number(POS_STATE.cardDeclared) || 0;
  // Ledger-ul are prioritate (banii sunt deja aprobati), dar cardul declarat
  // pentru toata nota (modul fara plata combinata) trebuie sa acopere si
  // restul neincasat: executeClose taxeaza doar diferenta fata de ledger.
  const amount = taken > 0 ? Math.max(taken, declared) : declared;
  return posRound2(Math.min(amount, cardNeeded()));
}

// Platile notei asa cum pleaca la server si la casa de marcat: formele
// inregistrate + o singura linie de card, derivata.
function paymentsForClose() {
  const out = {};
  Object.keys(POS_STATE.payments || {}).forEach(id => {
    if (String(id) !== "1") { out[id] = posRound2(POS_STATE.payments[id]); }
  });
  const card = cardAmountForClose();
  if (card > 0) { out[1] = card; }
  return out;
}

// Cat s-a acoperit din nota: formele non-card + cardul aplicat.
function paidTotal() {
  return posRound2(otherPaidTotal() + cardAmountForClose());
}

async function finalizePaymentTransaction() {
  // Inchidere in curs (sau terminalul e taxat chiar acum): ignoram un al
  // doilea click pe OK (anti-dublu-click / anti-dubla taxare).
  if (POS_STATE.closingBill || POS_STATE.cardPaying) { return; }

  const total = parseFloat(document.getElementById("val-total").innerText) || 0;
  if (total <= 0) {
    appAlert("Nu exista articole pe aceasta nota de plata!");
    return;
  }

  // Totalul e deja acoperit (ex. cardul a fost aprobat integral inainte de un
  // reload, deci campul de suma arata 0.00): OK inchide direct, fara sa mai
  // ceara o suma. Verificarea sta INAINTE de validarea sumei, altfel nota ar
  // rămâne blocata cu "Introduceti suma de plata!".
  const remaining = Math.max(0, posRound2(total - paidTotal()));
  if (remaining <= 0.001) {
    updatePaymentProgress();
    await executeClose(total, POS_STATE.rest || 0);
    return;
  }

  if (POS_STATE.activePaymentFp === null) {
    appAlert("Selectati o forma de plata!");
    return;
  }

  const input = document.getElementById("pay-amount-input");
  const amount = parseFloat(input.value) || 0;
  if (amount <= 0) {
    appAlert("Introduceti suma de plata!");
    return;
  }

  // Suma se plafoneaza la cat mai e de plata: in plata intra doar suma APLICATA
  // notei, iar diferenta tastata in plus (ex. 200 la o nota de 100) este rest
  // (schimb de dat) doar la NUMERAR (FPID = 0); la card/cec excesul se ignora
  // (nu se poate da rest pe card, iar terminalul e taxat exact cat trebuie).
  const fp = POS_STATE.activePaymentFp;
  const applied = Math.min(amount, remaining);

  if (fp === 1 && POS_STATE.posBanca === 1) {
    // FLUXUL NOU: terminalul este taxat la fiecare OK pe CARD, cu suma introdusa.
    // Niciun ban nu intra in nota pana cand terminalul nu confirma.
    POS_STATE.cardPaying = true;
    setClosingState(true);
    try {
      const r = await bankPosChargeCard(applied);
      if (!r || r.ok !== true) {
        // Refuzat / anulat / "Nu a tipărit" / terminal mut: nimic nu se
        // inregistreaza in nota, rămâne restul de plata.
        showToast(tr("Plata pe card nu a fost efectuata."));
        input.value = Math.max(0, posRound2(total - paidTotal())).toFixed(2);
        input.dataset.fresh = "true";
        updatePaymentProgress();
        return;
      }
      const suma = Math.min(posRound2(r.approved), remaining);
      cardLedgerAdd({
        approved: suma,
        requested: applied,
        source: r.source,
        trxId: r.trxId,
        logId: r.logId
      });
      showToast(tr("Card aprobat: {suma} Lei", { suma: suma.toFixed(2) }), 2500);
    } finally {
      POS_STATE.cardPaying = false;
      setClosingState(false);
    }
  } else if (fp === 1) {
    // Card fara terminal (PosBanca = 0): suma declarata, taxata la inchidere.
    POS_STATE.cardDeclared = posRound2((Number(POS_STATE.cardDeclared) || 0) + applied);
  } else {
    POS_STATE.payments[fp] = (POS_STATE.payments[fp] || 0) + applied;
    if (fp === 0) {
      POS_STATE.rest = (POS_STATE.rest || 0) + (amount - applied);
    }
  }

  const totalPaid = paidTotal();

  // Nu s-a acoperit totalul: asteptam inca o forma de plata
  if (totalPaid < total - 0.001) {
    input.value = (total - totalPaid).toFixed(2);
    input.dataset.fresh = "true";
    updatePaymentProgress();
    showToast("Plata partiala inregistrata");
    return;
  }

  const rest = POS_STATE.rest || 0;
  await executeClose(total, rest);
}

// Blocheaza/deblocheaza butoanele de plata cat timp se inchide nota, ca un
// dublu-click sa nu poata declansa doua tranzactii (bancar / casa de marcat).
function setClosingState(on) {
  const okBtn = document.querySelector("#screen-plata .key-ok");
  if (okBtn) { okBtn.disabled = !!on; }
  document.querySelectorAll(".pay-btn").forEach(btn => { btn.disabled = !!on; });
}

// Inchide nota cu formele de plata deja inregistrate in POS_STATE.payments.
// Comunicarea cu POS-ul bancar (card) se face inaintea rutinei casei de marcat.
// opts.allowUncertainEmpty = true permite inchiderea pe zero a unei note blocate
// care nu mai are produse (vezi closeEmptyUncertainBill); opts.allowEmpty = true
// permite inchiderea oricarei note goale (closeEmptyNote). Ambele sunt ignorate
// daca nota are linii sau un total, ca garda sa nu poata fi ocolita accidental.
async function executeClose(total, rest, opts = {}) {
  // Inchidere deja in curs (ex. al doilea click rapid pe OK sau pe o forma de
  // plata): ignoram apelul. Fara aceasta garda, doua cereri close_bill
  // concurente puteau duce la doua bonuri fiscale / doua taxari pe card.
  if (POS_STATE.closingBill) { return; }
  // DocID-ul notei care se inchide: il pastram pentru retiparirea chitantei de
  // card dupa ce POS_STATE.docId este golit de clearOrderPanelLocal.
  const closedDocId = POS_STATE.docId;
  const isEmptyNote = Number(total || 0) <= 0.005 && POS_STATE.articole.length === 0;
  const allowUncertainEmpty = isEmptyNote && opts && opts.allowUncertainEmpty === true;
  const allowEmptyNote = isEmptyNote
    && (allowUncertainEmpty || (opts && opts.allowEmpty === true));
  // Nota cu produse care se anuleaza reciproc: total 0, dar exista linii.
  const allowZeroTotal = Number(total || 0) <= 0.005
    && POS_STATE.articole.length > 0
    && opts && opts.allowZeroTotal === true;
  POS_STATE.closingBill = true;
  setClosingState(true);

  // Platile notei: formele inregistrate + linia de card derivata din ledger.
  // Se calculeaza o singura data si se foloseste peste tot (pas bancar,
  // close_bill, dialogul de succes, reteta FastFood).
  let plati = paymentsForClose();
  let cardAmount = Number(plati[1] || 0);
  let chargeAmount = posRound2(Math.max(0, cardAmount - Math.min(cardLedgerTotal(), cardAmount)));
  // Cardul e deja acoperit de swipe-urile aprobate: nu mai taxam si nu mai
  // cerem clarificarea unei incercari incerte (banii sunt confirmati).
  const bankAlreadyApproved = (cardAmount > 0 && chargeAmount <= 0.005);
  let bankApproved = true;

  // Garda generala: o incercare incerta blocheaza orice forma de plata, nu
  // doar cardul. Altfel s-ar putea inchide nota cu numerar dupa un UNKNOWN,
  // iar clientul ar putea fi incasat si pe card (dubla incasare / raport gresit).
  // Se rezolva prin Verifica sau Manual (inclusiv in FastFood, unde nota nu
  // poate ramane deschisa).
  if (!bankAlreadyApproved && !allowUncertainEmpty && await bankPosHasUncertain()) {
    POS_STATE.closingBill = false;
    setClosingState(false);
    appAlert(POS_STATE.bankPosCanResync
      ? tr("Există o plată pe card cu stare necunoscută. Apăsați Verifica sau „Da, am chitanța (Manual)” înainte de a închide nota.")
      : tr("Există o plată pe card cu stare necunoscută. Apăsați „Da, am chitanța (Manual)” înainte de a închide nota."));
    return;
  }

  if (POS_STATE.posBanca === 1 && chargeAmount > 0.005) {
    fiscFlowLog("banca: start (de taxat pe card " + chargeAmount.toFixed(2)
      + " din " + cardAmount.toFixed(2) + ")");
    // O SINGURA taxare, doar pentru partea neincasata: ce a fost deja aprobat
    // (swipe-uri anterioare, preluare automata din jurnal) nu se mai taxeaza.
    const r = await bankPosChargeCard(chargeAmount);
    if (!r || r.ok !== true) {
      fiscFlowLog("banca: rezultat=neaprobat -> stop");
      resetPaymentScreen();
      POS_STATE.closingBill = false;
      setClosingState(false);
      // "Nu a tipărit" / refuz: plata pe card nu s-a facut, nota rămâne
      // deschisa si deblocata pentru o noua incercare sau pentru numerar.
      showToast(tr("Plata pe card nu a fost efectuată. Puteți reîncerca sau încasa cu numerar."));
      return;
    }
    const suma = Math.min(posRound2(r.approved), chargeAmount);
    cardLedgerAdd({
      approved: suma,
      requested: chargeAmount,
      source: r.source,
      trxId: r.trxId,
      logId: r.logId
    });
    // Recalculam plata cu suma confirmata (poate fi mai mica decat cea ceruta).
    plati = paymentsForClose();
    cardAmount = Number(plati[1] || 0);
    const covered = paidTotal();
    fiscFlowLog("banca: rezultat=verde (aprobat " + suma.toFixed(2)
      + ", acoperit " + covered.toFixed(2) + " din " + total.toFixed(2) + ")");
    if (covered < total - 0.001) {
      // Aprobat doar partial: nota rămâne deschisa cu suma aprobata, ca
      // operatorul sa incaseze restul (numerar sau inca un swipe).
      POS_STATE.closingBill = false;
      setClosingState(false);
      updatePaymentProgress();
      refreshBankPosCheckButton();
      showToast(tr("Plată recuperată parțial: {suma} Lei. Mai rămâne {rest} Lei de plată.", {
        suma: suma.toFixed(2),
        rest: Math.max(0, posRound2(total - covered)).toFixed(2)
      }), 4000);
      return;
    }
  } else if (cardAmount > 0) {
    fiscFlowLog("banca: sarita (plata deja aprobata pe terminal, suma " + cardAmount.toFixed(2) + ")");
  } else {
    fiscFlowLog("banca: sarita (posBanca=" + POS_STATE.posBanca + ", sumaCard=" + cardAmount.toFixed(2) + ")");
  }

  // Poarta stricta: casa de marcat se contacteaza numai daca pasul bancar a
  // fost aprobat (sau nu era cazul unui card cu terminal activ).
  if (!bankApproved) {
    resetPaymentScreen();
    POS_STATE.closingBill = false;
    setClosingState(false);
    return;
  }

  // Poarta de acoperire: nu se inchide o nota care nu e platita integral.
  // Prinde cazurile in care plata de card nu acopera partea de card (aprobare
  // partiala, suma declarata mai mica dupa un reload) si in care o eroare de
  // client ar fi trimis mai putin decat totalul.
  if (Number(total || 0) > 0.005 && paidTotal() < total - 0.001) {
    POS_STATE.closingBill = false;
    setClosingState(false);
    updatePaymentProgress();
    refreshBankPosCheckButton();
    showToast(tr("Mai rămâne de plată: {rest} Lei.", {
      rest: Math.max(0, posRound2(total - paidTotal())).toFixed(2)
    }), 4000);
    return;
  }

  const asteaptaCasa = (POS_STATE.asteaptaRaspunsCasa === 1);
  try {
    if (asteaptaCasa) { showFiscalWait(); }
    fiscFlowLog("casa: start (asteaptaRaspunsCasa=" + (asteaptaCasa ? 1 : 0) + ")");
    const resp = await fetch("api/order_action.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        action: "close_bill",
        docId: POS_STATE.docId,
        plati: plati,
        cui: POS_STATE.cui || ""
      })
    });

    const res = await resp.json();
    // O alta fereastra inchide deja nota: nu raportam eroare, doar informam.
    if (res.alreadyClosing) {
      showToast("Nota se inchide deja");
      return;
    }
    if (res.status !== "success") {
      // Pastram si codul de eroare: mesajele de business (ex. card_not_approved)
      // sunt complete in sine, iar cele de infrastructura (baza cazuta) primesc
      // mai jos un adaos util.
      const errClose = new Error(res.message);
      errClose.code = res.code || "";
      throw errClose;
    }
    fiscFlowLog("casa: rezultat=ok" + (res.fiscal && res.fiscal.nr != null ? " (NR " + res.fiscal.nr + ")" : ""));

    // Nota fusese deja inchisa (ex. o a doua fereastra / un click scapat inainte
    // de blocarea butoanelor): serverul nu a mai inchis nimic a doua oara, deci
    // nu dublam bonul; revenim linistit la lista de mese.
    if (res.alreadyClosed) {
      POS_STATE.cui = "";
      POS_STATE.cuiDocId = null;
      updateCuiButton();
      showToast("Nota era deja inchisa");
      returnToGroups();
      clearOrderPanelLocal();
      if (isFastFood()) {
        navigateToScreen("screen-marcare");
      } else {
        await loadTables();
        navigateToScreen("screen-mese");
      }
      return;
    }

    // Bonul s-a inchis: CUI-ul nu mai apartine unei note deschise.
    POS_STATE.cui = "";
    POS_STATE.cuiDocId = null;
    updateCuiButton();

    // Nota goala inchisa pe zero: serverul a sters-o din tblBonCurent, deci nu
    // exista bon fiscal de anuntat si nici plati de afisat.
    if (res.sters === true || allowEmptyNote) {
      showToast(tr("Nota goala a fost stearsa"));
      returnToGroups();
      clearOrderPanelLocal();
      if (isFastFood()) {
        navigateToScreen("screen-marcare");
      } else {
        await loadTables();
        navigateToScreen("screen-mese");
      }
      return;
    }

    // Nota cu valoare 0 (produse anulate reciproc): s-a inchis normal, dar
    // fara bon fiscal, deci nu anuntam casa de marcat.
    if (allowZeroTotal || res.zeroTotal === true) {
      showToast(tr("Nota a fost inchisa (valoare 0, fara bon fiscal)."));
      returnToGroups();
      clearOrderPanelLocal();
      if (isFastFood()) {
        navigateToScreen("screen-marcare");
      } else {
        await loadTables();
        navigateToScreen("screen-mese");
      }
      return;
    }

    // Ultima tranzactie de card a notei inchise: butonul de retiparire a
    // chitantei rămâne disponibil in panoul FastFood / dialogul de succes.
    // Doar cand exista o aprobare reala a terminalului (nu card declarat fara
    // terminal si nu eroare de configurare): altfel serverul nu are ce tipari.
    if (Number(cardAmount || 0) > 0.005
        && POS_STATE.retiparireChitantaPoSbanca === 1
        && POS_STATE.posBanca === 1
        && cardLedgerHasTerminalApproval()) {
      POS_STATE.lastCardReprint = { docId: closedDocId };
    } else {
      POS_STATE.lastCardReprint = null;
    }

    // Mod FastFood: fara popup; detaliile de plata raman in panoul alb din
    // stanga (in locul liniei Total) pana la inceperea unui nou bon.
    if (isFastFood()) {
      showToast("BON INCHIS CU SUCCES!");
      returnToGroups();
      clearOrderPanelLocal();
      showFastFoodReceipt(plati, total, rest);
      navigateToScreen("screen-marcare");
      return;
    }

    // Formele de plata sunt afisate cu denumirea lor ("CARD: 73.00 Lei"), nu cu
    // codul intern (FP 1) - utilizatorul trebuie sa recunoasca imediat forma.
    const detalii = Object.entries(plati).map(([id, val]) => {
      return `${escapeHtml(paymentFormLabel(id))}: ${Number(val).toFixed(2)} Lei`;
    }).join("<br>");

    let msgHtml = `${tr("NOTA A FOST TRIMISA LA APARATUL FISCAL")}<br>${detalii}<br>${tr("Total: ")}${total.toFixed(2)} Lei`;
    if (rest > 0) {
      msgHtml += `<br><span style="font-size:22px; font-weight:bold;">${tr("Rest de dat: ")}${rest.toFixed(2)} Lei</span>`;
    }
    if (res.fiscal) {
      let casaLinie = `<br>${tr("Casa de marcat: NR ")}${res.fiscal.nr != null ? res.fiscal.nr : "-"}`;
      if (res.fiscal.z != null) { casaLinie += ` / Z ${res.fiscal.z}`; }
      msgHtml += casaLinie;
    }
    // Chitanta de card se poate retipari si dupa inchiderea notei.
    if (POS_STATE.lastCardReprint && Number((plati || {})[1]) > 0) {
      msgHtml += '<div style="margin-top:12px; text-align:center;">'
        + '<button class="act-btn" onclick="closeAppAlert(); actionBankPosReprint()">'
        + escapeHtml(tr("Chitanță card")) + '</button></div>';
    }

    appAlertHtml(msgHtml);
    showToast("BON INCHIS CU SUCCES!");

    returnToGroups();
    clearOrderPanelLocal();
    await loadTables();
    navigateToScreen("screen-mese");
  } catch (err) {
    fiscFlowLog("casa: rezultat=eroare (" + err.message + ")");
    // Cardul a fost deja taxat inainte de close_bill: nu mai incarcam cardul
    // inca o data la reincercare, iar operatorul trebuie sa stie asta.
    const cardApproved = (cardAmount > 0 && cardLedgerTotal() + 0.005 >= cardAmount);
    let msg = tr("Eroare la inchiderea bonului: ") + (err.message || "");
    // Adaosul are sens doar cand inchiderea a esuat din cauza infrastructurii
    // (baza de date cazuta / raspuns pierdut). Erorile de business au cod si un
    // mesaj complet; adaugarea "plata a fost aprobata, apasati OK" peste ele
    // contrazicea eroarea reala si deruta operatorul.
    if (cardApproved && !err.code) {
      msg += "\n\n" + tr("Plata pe card a fost aprobata. Nu o mai incarcati inca o data. Dupa ce baza de date revine, apasati OK din nou; nota se va inchide cu cardul deja aprobat.");
    }
    appAlertHtml(escapeHtml(msg).replace(/\r?\n/g, "<br>"));
    // Daca plata pe card a fost aprobata, pastram sumele si aprobarea: OK va
    // relua doar inchiderea, fara un nou sale. Altfel resetam ca inainte.
    if (asteaptaCasa && !cardApproved) { resetPaymentScreen(); }
  } finally {
    if (asteaptaCasa) { hideFiscalWait(); }
    // Inchiderea s-a terminat (succes sau eroare): deblocam butoanele pentru o
    // eventuala reincercare.
    POS_STATE.closingBill = false;
    setClosingState(false);
  }
}

// Mod "fara plata combinata" (PlataCombinata = 0): un click pe o forma de plata
// inchide nota integral cu acea forma, fara numpad de introducere a sumelor.
async function paySingleForm(fpid) {
  // Inchidere in curs (sau card taxat chiar acum): ignoram un al doilea click.
  if (POS_STATE.closingBill || POS_STATE.cardPaying) { return; }

  const total = parseFloat(document.getElementById("val-total").innerText) || 0;
  if (total <= 0) {
    appAlert("Nu exista articole pe aceasta nota de plata!");
    return;
  }
  // Restul de plata: banii deja aprobati pe card (ledger-ul, inclusiv dupa un
  // reload) nu se mai cer din nou. Altfel, dupa un card aprobat partial, un
  // click pe Numerar ar incerca sa incaseze tot totalul peste suma de pe card
  // (serverul l-ar bloca la inchidere cu 409 card_not_approved).
  const remaining = Math.max(0, posRound2(total - paidTotal()));
  POS_STATE.activePaymentFp = fpid;
  POS_STATE.payments = {};
  POS_STATE.rest = 0;
  if (remaining <= 0.001) {
    // Nota e deja acoperita (ex. card aprobat integral inainte de reload).
    await executeClose(total, 0);
    return;
  }
  if (Number(fpid) === 1) {
    // Cardul nu intra in `payments`: se declara aici si se taxeaza o singura
    // data, la inchidere (ledger-ul preia apoi suma aprobata). Se declara
    // TOTALUL notei, iar executeClose taxeaza doar diferenta fata de ledger.
    POS_STATE.cardDeclared = total;
  } else {
    POS_STATE.payments[fpid] = remaining;
  }
  await executeClose(total, 0);
}

// --------------------------------------------------------------------------
// 4b. COMUNICARE POS BANCAR (terminal card) la inchiderea notei
// --------------------------------------------------------------------------

// Jurnal de ordine pentru fluxul de plata: banca -> casa de marcat. Scrie in
// consola browserului (F12) cu marca de timp, ca sa se poata verifica usor ca
// rutina casei de marcat NU porneste inainte de aprobarea terminalului bancar.
function fiscFlowLog(msg) {
  try {
    console.log("[plata " + new Date().toLocaleTimeString("ro-RO") + "] " + msg);
  } catch (err) {
    /* consola indisponibila: nu deranjam fluxul de plata */
  }
}

// Aceeasi regula ca actiunea "status" din api/pos_banca.php: o incercare este
// incerta cand terminalul a acceptat comanda fara sa confirme rezultatul.
function bankPosResultUncertain(status, mesaj) {
  const s = String(status || "").toUpperCase();
  const m = String(mesaj || "");
  return s === "UNKNOWN" || s === "TIMEOUT"
    || (s === "ERROR" && /eroare la lansarea|nu a returnat rezultat|stare necunoscuta/i.test(m));
}

function bankPosOperatorLabel() {
  if (POS_STATE.loggedIn && POS_STATE.casierCurent) {
    return String(POS_STATE.casierCurent);
  }
  if (POS_STATE.bonNrOp) { return "bon #" + POS_STATE.bonNrOp; }
  if (POS_STATE.nrOp) { return "#" + POS_STATE.nrOp; }
  return tr("Operator necunoscut");
}

function posBancaManualNrOp() {
  // Daca nu e nimeni logat, lasam serverul sa foloseasca NrOp-ul notei.
  if (POS_STATE.loggedIn && POS_STATE.nrOp) { return POS_STATE.nrOp; }
  return POS_STATE.bonNrOp || "";
}

// Preia din raspunsul serverului protocolul si capabilitatile lui. Serverul este
// autoritatea: daca schimbi protocolul din Setari, urmatorul status actualizeaza
// butoanele fara reload.
function applyBankPosMeta(data) {
  if (!data) { return; }
  if (data.protocol) { POS_STATE.bankPosProtocol = String(data.protocol); }
  if (typeof data.canResync === "boolean") { POS_STATE.bankPosCanResync = data.canResync; }
  if (typeof data.canVoid === "boolean") { POS_STATE.bankPosCanVoid = data.canVoid; }
  if (data.connection === null) {
    POS_STATE.bankPosConnection = null;
  } else if (data.connection) {
    POS_STATE.bankPosConnection = data.connection;
  }
}

// Mesajul unic al blocarii (si textul din banner, si alertele de pe marcare).
// Actiunile de clarificare sunt in bara de jos a ecranului de marcare
// (Verifica / Altă formă de plată / Am chitanța), deci mesajul trimite acolo -
// fara sa le enumere, ca sa rămână corect si cand jurnalul nu poate fi citit
// (caz in care butonul Verifica nu exista).
function bankPosLockMessage() {
  // Comanda este inca in curs: terminalul nu a raspuns, deci nici nu avem ce
  // verifica. Cel mai important mesaj pentru operator este sa astepte.
  if (POS_STATE.bankPosPending === true) {
    return tr("Plata pe card este în curs: terminalul nu a răspuns încă. Nota rămâne blocată până la clarificare — așteptați câteva secunde.");
  }
  // Bani aprobati pe terminal, inca neaplicati pe nota: singura ieșire corecta
  // este continuarea platii din NOTA (sau anularea ei de pe terminal).
  if (POS_STATE.bankPosApproved === true) {
    const suma = (Number(POS_STATE.bankPosApprovedTotal) || 0).toFixed(2);
    // Poate exista si o incercare fara raspuns, pe langa banii deja confirmati
    // (o plata anterioara aprobata, apoi un swipe nou ramas in UNKNOWN): aceea
    // trebuie clarificata inainte de inchidere, altfel close_bill o refuza.
    if (POS_STATE.bankPosUncertain === true && POS_STATE.bankPosResolved !== true) {
      return tr("Plata pe card este confirmată pentru {suma} Lei, dar ultima încercare ({incercare} Lei) nu a primit răspuns: apăsați Verifica pentru a o clarifica.", {
        suma: suma,
        incercare: String(POS_STATE.bankPosLockAmount || "").trim() || "?"
      });
    }
    let msg = tr("Plata pe card a fost confirmată ({suma} Lei). Nota nu poate fi modificată: apăsați NOTA pentru a încasa restul de plată.", {
      suma: suma
    });
    return msg;
  }
  let msg = tr("Plata pe card nu a fost confirmată. Nota nu poate fi modificată: clarificați plata cu butoanele din bara de jos.");
  // Nota fara produse: singura iesire este inchiderea pe zero, din NOTA.
  if (POS_STATE.docId && POS_STATE.articole.length === 0) {
    msg += " " + tr("Nota nu are produse: apăsați NOTA pentru a o închide pe zero.");
  }
  return msg;
}

// Aplica lock-ul bancar in UI: banner informativ in panoul notei + blocarea
// butoanelor care modifica nota. Marcare rămâne permisa (trimite la bucatarie
// ce s-a adaugat inainte de incident), iar NOTA rămâne permisa pentru ca de
// acolo se ajunge la Verifica / Manual.
function applyBankPosLockUI() {
  const locked = POS_STATE.bankPosLocked === true;

  const screen = document.getElementById("screen-marcare");
  if (screen) {
    screen.classList.toggle("bank-locked", locked);
    // Bani aprobati pe card: singura ieșire corecta este continuarea platii
    // din NOTA, deci butonul rămâne la vedere (restul modificarilor sunt blocate).
    screen.classList.toggle("bank-locked-payable", locked && POS_STATE.bankPosApproved === true);
    // Nota goala + blocata: singura iesire este inchiderea pe zero din NOTA,
    // deci butonul rămâne vizibil (banner-ul il si mentioneaza).
    screen.classList.toggle(
      "bank-locked-empty",
      locked && POS_STATE.bankPosApproved !== true
        && Array.isArray(POS_STATE.articole) && POS_STATE.articole.length === 0
    );
  }

  // "Verifica" are sens doar cat timp exista o incertitudine NEREZOLVATA si
  // protocolul permite citirea jurnalului. O plata confirmata (bani aprobati pe
  // terminal) este certa: butonul ar sugera ca mai e ceva de verificat, desi
  // singura actiune utila este continuarea platii din NOTA (sau void). Cat timp
  // comanda este inca in curs (PENDING), verificarea ar fi prematura.
  const uncertainNow = POS_STATE.bankPosUncertain === true
    && POS_STATE.bankPosResolved !== true
    && POS_STATE.bankPosPending !== true;
  const verifyBtn = document.getElementById("btn-bank-verify");
  if (verifyBtn) {
    const showVerify = locked && uncertainNow && POS_STATE.bankPosCanResync === true;
    verifyBtn.style.display = showVerify ? "" : "none";
  }

  // Cu plata deja confirmata pe terminal nu mai exista nimic de decis manual:
  // butonul "Am chitanța (Manual)" doar ar re-consemna aceeasi aprobare, deci se
  // ascunde. Iar "Anulează" este atunci clar o retragere pe terminal (void).
  const payable = locked && POS_STATE.bankPosApproved === true;
  const manualBtn = document.getElementById("btn-bank-manual");
  if (manualBtn) {
    manualBtn.style.display = (locked && uncertainNow) ? "" : "none";
  }
  const returnBtn = document.getElementById("btn-bank-return");
  if (returnBtn) {
    returnBtn.textContent = payable
      ? tr("Anulează plata pe card (void)")
      : tr("Altă formă de plată / Anulează");
    // Cat timp comanda este in curs, nici anularea nu e o decizie valida:
    // terminalul poate aproba imediat dupa ce operatorul a apasat butonul.
    returnBtn.style.display = (locked && POS_STATE.bankPosPending === true) ? "none" : "";
  }

  // "Marcare" nu mai are ce face pe o nota cu plata confirmata: acolo se ajunge
  // dupa click pe NOTA, care trimite deja toate pozitiile la bucatarie. Butonul
  // rămâne totusi vizibil daca a rămas o linie netrimisa (Marcarea anterioara a
  // esuat) - altfel bucataria nu ar primi-o niciodata. In FastFood butonul nu
  // exista (il ascunde applyTipVanzUI), deci nu il readucem.
  const markBtn = document.getElementById("btn-marcare");
  if (markBtn) {
    const hasUnsent = (POS_STATE.articole || []).some(a => a && a.preluat !== true);
    const showMark = (!payable || hasUnsent) && !isFastFood();
    markBtn.style.display = showMark ? "" : "none";
  }

  const banner = document.getElementById("bank-lock-banner");
  if (banner) {
    banner.style.display = locked ? "flex" : "none";
    const txt = banner.querySelector(".bank-lock-text");
    if (txt) txt.textContent = bankPosLockMessage();
    // TRX_ID-ul incercarii (acelasi ca in jurnalul terminalului bancar).
    const trx = document.getElementById("bank-lock-trx");
    if (trx) {
      // Doar la o incertitudine NEREZOLVATA: acolo TRX-ul ajuta operatorul sa
      // caute tranzactia in jurnalul terminalului (sau sa o dea la suport). La
      // o plata confirmata nu e nimic de cautat, deci linia dispare.
      const uid = (locked && uncertainNow) ? String(POS_STATE.bankPosUid || "").trim() : "";
      trx.textContent = uid ? ("TRX: " + uid) : "";
    }
  }

  const scan = document.getElementById("scan-input");
  if (scan) {
    scan.disabled = locked;
    if (locked) scan.blur();
  }

  const lockIds = ["btn-qty", "btn-transfer", "btn-proforma", "btn-meniul-zilei", "btn-mod-preparare"];
  lockIds.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.disabled = locked;
  });
  document.querySelectorAll(".js-discount-btn, .vd-btn").forEach(btn => {
    btn.disabled = locked;
  });

  if (!locked) { focusScanInput(); }
  // Suma aprobata pe card se poate schimba odata cu starea (status/reload).
  updateCardPaymentFooter();
  // Daca o comanda asteapta inca raspunsul terminalului, starea se reimprospateaza
  // singura (fara un al doilea Ctrl+F5).
  watchBankPending();
}

// Cat timp randul de audit al incercarii este `PENDING` (comanda plecata, fara
// raspuns), polling pe `status` pana se rezolva. Driverul scrie rezultatul in
// cel mult ~190s, deci ciclul se opreste de la sine cand `pending` dispare.
let bankPendingTimer = null;
function watchBankPending() {
  if (bankPendingTimer) { return; }
  if (POS_STATE.bankPosPending !== true || !POS_STATE.docId) { return; }
  bankPendingTimer = setTimeout(async () => {
    bankPendingTimer = null;
    if (POS_STATE.bankPosPending !== true || !POS_STATE.docId) { return; }
    const st = await fetchBankPosStatus();
    if (st && POS_STATE.bankPosPending === true) { watchBankPending(); }
  }, 5000);
}

// Garda din client: aceeasi regula ca pe server (care răspunde 423
// bank_pos_uncertain). Returneaza true cand actiunea trebuie oprita.
function bankPosBlockedNotice() {
  if (POS_STATE.bankPosLocked !== true) { return false; }
  appAlert(bankPosLockMessage());
  return true;
}

// Nota fara nicio linie (ex. dupa stergerea ultimului produs): click pe NOTA o
// inchide pe zero. Serverul nu emite bon fiscal, sterge doar randul din
// tblBonCurent, deci masa se elibereaza.
async function closeEmptyNote() {
  if (POS_STATE.closingBill || POS_STATE.cardPaying) { return; }
  // Bani aprobati pe card fara nicio linie pe nota (poate aparea doar dupa un
  // reload in timpul platii): se anuleaza pe terminal, ca sa nu rămâna incasati
  // fara document. Dupa un reload ledger-ul local e gol, dar sumele aprobate
  // sunt in audit, deci `force` le ia de la server. Daca void-ul nu e posibil,
  // se inchide mai departe (politica "ieșire cu avertisment"), iar diferenta se
  // vede in audit.
  const cardOnTerminal = Math.max(cardLedgerTotal(), Number(POS_STATE.bankPosApprovedTotal) || 0);
  if (cardOnTerminal > 0.005 && !(await bankPosVoidAll(true)).ok) {
    appAlert(tr("Plata pe card nu a putut fi anulată complet. Verificați terminalul: sumele rămân încasate până la clarificare."));
  }
  POS_STATE.payments = {};
  POS_STATE.rest = 0;
  await executeClose(0, 0, { allowEmpty: true });
}

// Nota cu produse a caror valoare se anuleaza (produs + storno, discount 100%):
// are total 0. Un click pe NOTA o inchide direct (fara dialog de confirmare),
// rămâne in istoric, dar fara bon fiscal, ca registrul de casa nu are ce emite
// pe zero.
async function closeZeroTotalNote() {
  if (POS_STATE.closingBill) { return; }
  POS_STATE.payments = {};
  POS_STATE.rest = 0;
  await executeClose(0, 0, { allowZeroTotal: true });
}

// Nota blocata care nu mai are niciun produs: un click pe NOTA o inchide pe
// zero, fara dialog de confirmare. Terminalul bancar NU este contactat, deci
// daca pe card a fost incasata o suma, ea ramane incasata si va aparea ca
// diferenta fata de casa de marcat (desincronizare asumata de operator).
// close_bill sterge nota goala din tblBonCurent, iar incercarea bancara ramane
// in tblPosBancaLog pentru audit.
async function closeEmptyUncertainBill() {
  if (POS_STATE.closingBill) { return; }
  const docId = POS_STATE.docId;
  if (!docId) { return; }
  // Consemnam decizia in audit (tblPosBancaLog) si in jurnalul aplicatiei.
  await resolveBankPosDecision(docId, "ignorat");
  logUiEvent("nota_incerta_inchisa_zero", {
    docId: docId,
    buton: "nota",
    ecran: currentScreenId(),
    detaliu: "suma=" + (POS_STATE.bankPosLockAmount || "") + " articole=0"
  });
  POS_STATE.payments = {};
  POS_STATE.rest = 0;
  await executeClose(0, 0, { allowUncertainEmpty: true });
}

// Starea bancara a notei curente: este autoritatea pentru butoanele Verifica /
// Manual si pentru garda din executeClose. Retine si conexiunea din .env.
async function fetchBankPosStatus() {
  if (!POS_STATE.docId) { return null; }
  try {
    const resp = await fetch("api/pos_banca.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "status", docId: POS_STATE.docId })
    });
    const data = await resp.json();
    if (data && data.status === "success") {
      applyBankPosMeta(data);
      // Recuperare dupa reload/crash: ledger-ul se aliniaza la auditul
      // serverului. Altfel banii incasati pe card s-ar pierde din memorie si
      // nota s-ar putea inchide fara linia CARD (sau s-ar taxa a doua oara),
      // iar o numarare dubla din memorie ar putea inchide nota fara sa taxeze.
      const ledgerBefore = cardLedgerTotal();
      cardLedgerReconcile(data.approvedEntries);
      if (cardLedgerTotal() !== ledgerBefore) {
        updatePaymentProgress();
      }
      // Lock-ul notei se reimprospateaza odata cu starea bancara (dupa
      // resync / void / decizie manuala), fara sa fie nevoie de reload.
      // Nota este blocata la modificare din DOUA motive: incercare incerta SAU
      // bani aprobati pe terminal dar neaplicati pe nota (plata partiala cu
      // card). Aici se recalcula exact ca pe server (`posBancaDocLock`); inainte
      // se lua doar `uncertain`, deci dupa un `status` nota cu card aprobat
      // rămânea aparent deblocata si orice modificare lua 423.
      POS_STATE.bankPosLockAmount = data.amount || "";
      // Sume aprobate dar neaplicate inca pe nota (neoferite de `payments`):
      // nota rămâne nemodificabila, dar NOTA poate continua plata.
      POS_STATE.bankPosApprovedTotal = Number(data.approvedTotal) || 0;
      POS_STATE.bankPosApproved = (POS_STATE.bankPosApprovedTotal > 0.005);
      POS_STATE.bankPosLocked = (data.uncertain === true) || POS_STATE.bankPosApproved;
      // Verifica / Manual: doar pentru o incertitudine nerezolvata.
      POS_STATE.bankPosUncertain = (data.uncertain === true);
      POS_STATE.bankPosResolved = (data.resyncApproved === true) || (data.manualApproved === true);
      POS_STATE.bankPosPending = (data.pending === true);
      applyBankPosLockUI();
      return data;
    }
  } catch (err) {
    /* fara baza de date / retea: fara stare bancara */
  }
  return null;
}

// FUNNEL-UL BINAR al platii pe card: trimite o suma la terminal si rezolva
// DOAR cand rezultatul este cunoscut. NU atinge nota (nu scrie `payments`, nu
// seteaza flaguri de aprobare, nu inchide): banii se adauga de apelant in
// ledger, prin cardLedgerAdd/bankPosMarkApproved.
//
//   { ok:true,  approved, requested, source:'terminal'|'journal'|'manual'|'config', trxId, logId }
//   { ok:false, approved:0, requested, reason:'declined'|'unreachable'|'cancelled'|'voided'|'notfound', trxId, logId }
//
// Fluxul nu expune operatorului masina de stari: la timeout/UNKNOWN verificarea
// se face automat, cu ecranul blocat pe un indicator de asteptare, iar dialogul
// apare doar cu rezultatul (banii s-au luat sau nu). Fiecare ramura scrie o
// decizie in tblPosBancaLog inainte de a reveni, deci nu ramane "unknown".
async function bankPosChargeCard(amount) {
  const requested = Number(amount) || 0;
  const result = (ok, extra) => Object.assign({
    ok: ok,
    approved: 0,
    requested: requested,
    trxId: "",
    logId: null,
    source: "terminal"
  }, extra || {});

  showBankPosWait();
  try {
    while (true) {
      let res;
      try {
        const resp = await fetch("api/pos_banca.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            action: "pay",
            amount: requested,
            docId: POS_STATE.docId
          })
        });
        res = await resp.json();
      } catch (err) {
        res = {
          status: "error",
          emiteBon: false,
          posStatus: "ERROR",
          posMessage: "Eroare comunicare POS bancar: " + err.message,
          trxId: ""
        };
      }

      applyBankPosMeta(res);

      // Aprobat direct. Eroarea de configurare pastreaza comportamentul vechi
      // (se emite bonul oricum), dar sursa 'config' nu intra in reconcilierea
      // de pe server: nu sunt bani incasati de terminal.
      if (res && res.emiteBon) {
        if (res.configError) {
          hideBankPosWait();
          appAlert(String(res.posMessage || "Eroare configurare POS bancar"));
          return result(true, {
            approved: requested,
            source: "config",
            trxId: (res && res.trxId) || "",
            logId: (res && res.logId) || null
          });
        }
        return result(true, {
          approved: requested,
          source: "terminal",
          trxId: (res && res.trxId) || "",
          logId: (res && res.logId) || null
        });
      }

      // Starea din baza de date este autoritatea: un refuz normal nu este
      // incert, iar UNKNOWN/TIMEOUT/eroarea veche de lansare sunt. Astfel si un
      // raspuns pierdut intre browser si PHP este tratat corect.
      const st = await fetchBankPosStatus();
      if (res && st) {
        res.uncertain = (st.uncertain === true);
        if (st.amount && !res.amount) { res.amount = st.amount; }
        if (!res.trxId && st.uid) { res.trxId = st.uid; }
        if (st.connection) { res.connection = st.connection; }
      }
      const uncertain = !!(res && res.uncertain === true)
        || bankPosResultUncertain(res && res.posStatus, res && res.posMessage);
      const trx = (res && res.trxId) || "";

      hideBankPosWait();

      if (!uncertain) {
        // Refuz normal (card refuzat) sau terminal indisponibil (comanda nu a
        // plecat): "Reincearca plata" sau alta metoda. A doua e tot o eroare
        // curata - cardul nu a fost debitat -, dar mesajul e despre legatura,
        // nu despre o tranzactie refuzata.
        const decizie = await openPosBancaDialog(res || {}, {
          kind: (res && res.noAnswer === true) ? "unreachable" : "declined"
        });
        if (decizie.decision === "retry") { showBankPosWait(); continue; }
        await resolveBankPosDecision(POS_STATE.docId, "anulat", trx);
        return result(false, {
          reason: (res && res.noAnswer === true) ? "unreachable" : "declined",
          trxId: trx,
          logId: (res && res.logId) || null
        });
      }

      // Stare incerta: verificam noi in jurnal, fara sa cerem nimic
      // operatorului si fara sa-i aratam loguri. Daca terminalul confirma plata,
      // o preluam in liniste (zero click-uri).
      const check = await bankPosVerifySilently(res && res.trxId,
        tr("Timp expirat. Se verifica statusul platii in terminal..."));
      if (check.kind === "approved") {
        // Aprobarea din jurnal e decizia finala: o consemnam in audit si o
        // raportam apelantului, care o adauga in ledger.
        await resolveBankPosDecision(POS_STATE.docId, "aprobat");
        const approved = Number(check.amount) || requested;
        if (approved + 0.005 >= requested) {
          showToast(tr("Plată recuperată cu succes"), 2500);
        }
        return result(true, {
          approved: approved,
          source: "journal",
          trxId: check.trxId || trx,
          logId: (check.data && check.data.logId) || null
        });
      }

      const decizie = await openPosBancaDialog(res || {}, {
        kind: check.kind,
        amount: check.amount,
        // TRX_ID afisat = al incercarii de plata (nu ecoul cautarii in jurnal).
        trxId: (res && res.trxId) || check.trxId
      });
      if (decizie.decision === "retry") { showBankPosWait(); continue; }
      if (decizie.decision === "approved") {
        // "Da, am chitantța (Manual)": operatorul a confirmat plata pe terminal.
        // Dialogul NU scrie in nota; suma confirmata se intoarce aici.
        const approved = Number(decizie.amount) || Number(check.amount) || requested;
        await resolveBankPosDecision(POS_STATE.docId, "aprobat", check.trxId);
        return result(true, {
          approved: approved,
          source: "manual",
          trxId: check.trxId || trx,
          logId: (decizie.data && decizie.data.logId) || null
        });
      }
      // "Nu a tipărit (Anulează)": fara chitanta, incercarea curenta este
      // considerata esuata. Serverul o marcheaza CANCELED_MANUAL (cu ResolvedAt)
      // si deblocheaza nota, ca operatorul sa poata reincerca plata pe card
      // (TRX_ID nou) sau sa incaseze numerar.
      const trxFinal = (res && res.trxId) || check.trxId || "";
      if (check.kind === "noresponse") {
        await resolveBankPosDecision(POS_STATE.docId, "neaprobat", trxFinal);
      } else {
        await resolveBankPosDecision(POS_STATE.docId, "dialog_inchis", trxFinal);
      }
      bankPosUnlockLocal();
      return result(false, {
        reason: (check.kind === "voided") ? "voided"
          : (check.kind === "notfound") ? "notfound"
          : (check.kind === "noresponse") ? "unreachable" : "cancelled",
        trxId: trxFinal
      });
    }
  } finally {
    hideBankPosWait();
  }
}

// --------------------------------------------------------------------------
// Ieșirea din ecranul de plata: "totul sau nimic".
//
// Secventa de plata porneste la click pe NOTA si se termina fie cu nota
// acoperita integral, fie cu anularea (void) sumelor deja aprobate pe card.
// Nu exista stare intermediara de plata partiala pe card: daca operatorul
// apasa Inapoi, sumele se anuleaza pe terminal (cu confirmare).
// --------------------------------------------------------------------------

// Anuleaza pe terminal toate sumele aprobate ale notei curente.
// `force` = se bazeaza pe server (nu pe ledger): folosit cand starea aprobata
// vine din `status` (dupa reload) si ledger-ul nu a fost inca reînsămânțat.
// Returneaza { ok, nothing, voided, failed, data }.
async function bankPosVoidAll(force) {
  if (!force && cardLedgerTotal() <= 0.005) {
    return { ok: true, nothing: true, voided: [], failed: [], data: null };
  }
  let data;
  try {
    const resp = await fetch("api/pos_banca.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "void_doc", docId: POS_STATE.docId })
    });
    data = await resp.json();
  } catch (err) {
    data = { status: "error", message: "Eroare comunicare POS bancar: " + err.message };
  }
  const voided = Array.isArray(data && data.voided) ? data.voided : [];
  const failed = Array.isArray(data && data.failed) ? data.failed : [];
  // Serverul intoarce totalul aprobat RAMAS dupa anulari: il preluam, altfel
  // suma veche ar rămâne afisata in subsolul notei dupa un void reusit.
  if (data && data.status === "success" && data.approvedTotal !== undefined) {
    POS_STATE.bankPosApprovedTotal = Number(data.approvedTotal) || 0;
    POS_STATE.bankPosApproved = POS_STATE.bankPosApprovedTotal > 0.005;
    updateCardPaymentFooter();
  }
  const ok = !!(data && data.status === "success")
    && failed.length === 0
    && Number(data && data.approvedTotal) <= 0.005;
  return { ok: ok, nothing: false, voided: voided, failed: failed, data: data };
}

// Golește starea de plata a ecranului: sumele tastate si ledger-ul local.
// Nu atinge baza; baza rămâne sursa de adevar (auditul din tblPosBancaLog).
function clearPaymentScreenState() {
  POS_STATE.payments = {};
  POS_STATE.rest = 0;
  POS_STATE.cardDeclared = 0;
  cardLedgerReset();
  updateCardPaymentFooter();
}

// appConfirm cu promisiune (confirmarile din POS sunt pe callback).
function appConfirmAsync(message, yesLabel, noLabel) {
  return new Promise(resolve => {
    appConfirm(message, () => resolve(true), yesLabel, noLabel, () => resolve(false));
  });
}

// Paraseste ecranul de plata. Cu bani aprobati pe card: confirmare + void pe
// terminal, apoi ieșirea; daca void-ul eșueaza, ieșirea este permisa, dar cu
// avertisment (banii pot rămâne incasati pe terminal, se clarifica acolo).
async function leavePaymentScreen(target) {
  const dest = (typeof target === "string" && target !== "") ? target : "screen-marcare";
  if (POS_STATE.cardPaying) { return; }
  // Suma luata in calcul: ledger-ul local sau, dupa un reload, totalul aprobat
  // din audit (altfel un Inapoi dupa Ctrl+F5 ar crede ca nu s-a incasat nimic).
  const taken = Math.max(cardLedgerTotal(), Number(POS_STATE.bankPosApprovedTotal) || 0);
  if (taken <= 0.005) {
    // Doar sume tastate (numerar / alta forma): nimic incasat, se arunca.
    clearPaymentScreenState();
    navigateToScreenNow(dest);
    return;
  }
  const ok = await appConfirmAsync(tr(
    "Ați încasat {suma} Lei pe card. Dacă ieșiți, plata se anulează pe terminal (void). Continuați?",
    { suma: taken.toFixed(2) }
  ), tr("Da, anulează"), tr("Nu"));
  if (!ok) { return; }

  POS_STATE.cardPaying = true;
  setClosingState(true);
  let result;
  try {
    result = await bankPosVoidAll(true);
  } finally {
    POS_STATE.cardPaying = false;
    setClosingState(false);
  }
  clearPaymentScreenState();
  if (result && result.ok && !result.nothing) {
    showToast(tr("Plata pe card a fost anulată (void)."), 4000);
  } else if (result && !result.ok) {
    appAlert(tr("Plata pe card nu a putut fi anulată complet. Verificați terminalul: sumele rămân încasate până la clarificare."));
  }
  navigateToScreenNow(dest);
  // Dupa void, starea bancara a notei se schimba (incertitudinea dispare);
  // reimprospatam bannerul/butoanele si eventualele sume rămase aprobate.
  refreshBankPosCheckButton();
}

// Verifica in jurnalul terminalului ultima incercare a notei, cu ecranul blocat
// pe indicatorul de asteptare. Nu taxeaza si nu modifica nota.
// Intoarce { kind: approved | notfound | voided | noresponse, amount, trxId }.
async function bankPosVerifySilently(trxId, waitText) {
  if (POS_STATE.bankPosCanResync !== true) {
    // Driver fara jurnal (nu exista in prezent): nu avem ce verifica.
    return { kind: "noresponse", amount: 0, trxId: trxId || "" };
  }
  showBankPosWait(waitText || tr("Se verifica terminalul..."));
  try {
    const r = await bankPosResync(trxId || "");
    applyBankPosMeta(r);
    // Identificatorul incercarii (trimis de noi) are prioritate fata de ecoul
    // din raspunsul de resync.
    const uid = trxId || (r && r.uid) || "";
    if (!r || r.status !== "success") {
      return { kind: "noresponse", amount: 0, trxId: uid, data: r };
    }
    if (r.posStatus === "MANUAL") {
      return {
        kind: r.found ? "approved" : "notfound",
        amount: Number(r.amount) || 0,
        trxId: uid,
        data: r
      };
    }
    if (r.posStatus === "ERROR" || r.posStatus === "UNKNOWN") {
      return { kind: "noresponse", amount: 0, trxId: uid, data: r };
    }
    if (!r.found) { return { kind: "notfound", amount: 0, trxId: uid, data: r }; }
    if (!r.approved) { return { kind: "voided", amount: 0, trxId: uid, data: r }; }
    return { kind: "approved", amount: Number(r.amount) || 0, trxId: uid, data: r };
  } catch (err) {
    return { kind: "noresponse", amount: 0, trxId: trxId || "", data: null };
  } finally {
    hideBankPosWait();
  }
}

// Marcheaza in audit (tblPosBancaLog) decizia finala a utilizatorului.
// trxId = incercarea pe care operatorul a vazut-o in dialog: serverul o
// foloseste ca sa nu anuleze o incercare mai noua pornita intre timp.
async function resolveBankPosDecision(docId, actiune, trxId) {
  try {
    await fetch("api/pos_banca.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        action: "resolve",
        docId: docId,
        actiune: actiune,
        trxId: trxId || ""
      })
    });
  } catch (err) {
    // Auditul este best-effort; nu blocam inchiderea notei.
  }
}

// Decizia operatorului a lamurit starea incerta: nota se deblocheaza imediat in
// UI (banner + grid de produse), fara sa mai asteptam reincarcarea notei.
function bankPosUnlockLocal() {
  // Incertitudinea s-a incheiat (decizie manuala / void). Daca au rāmas bani
  // aprobati pe terminal, nota rămâne blocata (reason `payable`): `approved`
  // nu se reseteaza aici, iar `bankPosLocked` il urmeaza pe server.
  POS_STATE.bankPosUncertain = false;
  POS_STATE.bankPosResolved = true;
  POS_STATE.bankPosLocked = (POS_STATE.bankPosApproved === true);
  POS_STATE.bankPosLockAmount = "";
  if (POS_STATE.bankPosApproved !== true) { POS_STATE.bankPosUid = ""; }
  applyBankPosLockUI();
}

// Re-sincronizare (SmartPay Appendix 3): intreaba jurnalul terminalului daca
// tranzactia cu TRX_ID a fost aprobata. Nu modifica nota si nu taxeaza.
async function bankPosResync(trxId) {
  try {
    const resp = await fetch("api/pos_banca.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        action: "resync",
        docId: POS_STATE.docId,
        uid: trxId || ""
      })
    });
    return await resp.json();
  } catch (err) {
    return {
      status: "error",
      found: false,
      posStatus: "ERROR",
      posMessage: "Eroare comunicare POS bancar: " + err.message
    };
  }
}

// Exista pentru nota curenta o incercare bancara cu stare necunoscuta?
async function bankPosHasUncertain() {
  const data = await fetchBankPosStatus();
  return !!(data && data.uncertain === true);
}

// Butonul "Verifica" de pe ecranul de plata apare DOAR daca ultima incercare
// bancara a notei are stare incerta (UNKNOWN / TIMEOUT / eroare de lansare),
// nu ori de cate ori PoSbanca este activ. Manual este a doua cale de iesire,
// inclusiv in FastFood, unde nota nu poate ramane deschisa.
// Butonul de retiparire a chitantei de card: apare dupa orice aprobare pe card
// (inclusiv dupa reload, cand ledger-ul este refacut din server). Terminalul
// bancar nu are comanda ECR de reprint, deci duplicatul se tipareste pe
// imprimanta POS (vezi actionBankPosReprint).
function refreshBankReprintButton() {
  const btn = document.getElementById("btn-banca-reprint");
  if (!btn) { return; }
  const hasCard = POS_STATE.retiparireChitantaPoSbanca === 1
    && POS_STATE.posBanca === 1
    && !!POS_STATE.docId
    && (cardLedgerHasTerminalApproval()
        || (Number(POS_STATE.bankPosApprovedTotal) || 0) > 0.005);
  btn.style.display = hasCard ? "" : "none";
}

async function refreshBankPosCheckButton() {
  const check = document.getElementById("btn-pos-banca-check");
  const manual = document.getElementById("btn-pos-banca-manual");
  if (!POS_STATE.docId) {
    if (check) check.style.display = "none";
    if (manual) manual.style.display = "none";
    refreshBankReprintButton();
    return null;
  }
  const data = await fetchBankPosStatus();
  const uncertain = !!(data && data.uncertain === true);
  const canResync = POS_STATE.bankPosCanResync === true;
  // TRX_ID-ul se poate schimba dupa o incercare noua: il tinem la zi si in
  // banner (cand nota este blocata).
  if (data && data.uid) { POS_STATE.bankPosUid = String(data.uid); }
  // Daca ultima incercare a fost deja rezolvata (manual sau resync), nu mai
  // exista nimic de verificat/declarat: butoanele ar fi doar o reluare a
  // aceleiasi decizii. Atentie: `uncertain` rămâne true si dupa o aprobare
  // (nota rămâne blocata pana la inchidere), deci singure nu e suficient.
  const resyncApproved = !!(data && data.resyncApproved === true);
  const manualApproved = !!(data && data.manualApproved === true);
  const resolved = resyncApproved || manualApproved;
  if (check) check.style.display = (uncertain && canResync && !resolved) ? "" : "none";
  if (manual) manual.style.display = (uncertain && !resolved) ? "" : "none";
  refreshBankReprintButton();
  return data;
}

// Preluarea automata a unei plati confirmate de terminal (resync gasit), la
// deschiderea ecranului de plata. Acopera notele rămase blocate dupa un resync
// reusit (eroare fiscala, reload de pagina, suma aprobata partial): operatorul
// nu mai primeste nicio fereastra de decizie.
//   true  = nota a fost inchisa
//   false = nu era cazul / suma aprobata acopera doar partial partea de card
async function bankPosAutoAdoptOnPaymentScreen(statusData) {
  if (POS_STATE.closingBill) { return false; }
  if (!POS_STATE.docId) { return false; }
  if (POS_STATE.posBanca !== 1 && POS_STATE.bankPosLocked !== true) { return false; }

  const st = (statusData === null) ? null : (statusData || await fetchBankPosStatus());
  // Ledger-ul poate fi deja completat din server (reînsămânțare la intrarea pe
  // ecranul de plata, dupa un reload): atunci aprobarea e deja in memorie.
  if (st && st.resyncApproved === true) {
    const approved = Number(st.amount) || 0;
    if (approved > 0) {
      const plan = bankPosApprovedPlan(approved);
      if (plan && plan.cardNeeded > 0) {
        bankPosMarkApproved(plan, { source: "journal", trxId: st.uid || "" });
        if (approved + 0.005 < plan.cardNeeded) {
          // Terminalul a aprobat doar o parte: o aplicam si lasam restul.
          showToast(tr("Plată recuperată parțial: {suma} Lei. Mai rămâne {rest} Lei de plată.", {
            suma: approved.toFixed(2),
            rest: (plan.cardNeeded - approved).toFixed(2)
          }), 4000);
        }
      }
    }
  }

  const taken = cardLedgerTotal();
  const needed = cardNeeded();
  if (taken <= 0 || needed <= 0) { return false; }
  // Suma din ledger trebuie confirmata si de auditul serverului: o numarare
  // dubla in memorie nu are voie sa inchida nota pe card fara ca terminalul sa
  // fi incasat (ar ieși un bon pe card cu diferenta semnalata abia in audit).
  const serverApproved = Number(st && st.approvedTotal);
  if (st && isFinite(serverApproved) && taken > serverApproved + 0.005) {
    return false;
  }
  if (taken + 0.005 < needed) {
    // Acoperire doar partiala: restul rămâne de plata (fara dialog).
    return false;
  }
  showToast(tr("Plată recuperată cu succes"), 2500);
  await executeClose(noteTotal(), 0);
  return true;
}

// Planul de aplicare a unei sume aprobate pe terminal (Check sau Manual).
function bankPosApprovedPlan(amount) {
  const total = noteTotal();
  if (total <= 0) { return null; }
  const otherPaid = otherPaidTotal();
  const cardNeeded = Math.max(0, posRound2(total - otherPaid));
  const approved = posRound2(amount);
  return { total, otherPaid, cardNeeded, approved };
}

// Marcheaza suma ca aprobata pe card: o adauga in ledger (idempotent pe trxId)
// si actualizeaza progresul platii. `meta` = {source, trxId, logId}.
function bankPosMarkApproved(plan, meta) {
  if (!plan) { return null; }
  const approved = Math.min(posRound2(plan.approved), posRound2(plan.cardNeeded));
  if (approved <= 0) { return null; }
  const trxId = String((meta && meta.trxId) || "");
  const logId = (meta && meta.logId != null) ? meta.logId : null;
  // Fara identificatorul tranzactiei nu putem dedupe: daca ledger-ul are deja
  // cel putin suma aprobata, o consideram deja preluata. Altfel aceeasi
  // aprobare preluata de doua ori (Verifica / Manual dupa un reload) ar dubla
  // banii in memorie, iar nota s-ar putea inchide pe card fara ca terminalul
  // sa fi incasat diferenta.
  if (trxId === "" && logId === null && cardLedgerTotal() >= approved - 0.005) {
    updatePaymentProgress();
    return false;
  }
  const added = cardLedgerAdd({
    approved: approved,
    requested: plan.approved,
    source: (meta && meta.source) || "journal",
    trxId: trxId,
    logId: logId
  });
  updatePaymentProgress();
  return added;
}

// Aplica o suma aprobata de pe ecranul de plata (Verifica sau Manual). Suma
// intra in ledger CU identificatorul tranzactiei (`meta.trxId`), deci aceeasi
// aprobare preluata de doua ori (ex. dupa un reload: ledger-ul este deja
// reînsămânțat din server, apoi se apasa Verifica) nu se aduna de doua ori.
// Daca dupa preluare nota este acoperita, se inchide; altfel rămâne restul.
async function bankPosApplyApprovedOnPaymentScreen(amount, meta) {
  const plan = bankPosApprovedPlan(amount);
  if (!plan) {
    appAlert(tr("Nota nu are o suma de plata."));
    return;
  }
  if (plan.cardNeeded <= 0) {
    appAlert(tr("Nu mai este nimic de platit pe card."));
    return;
  }
  if (plan.approved <= 0) {
    appAlert(tr("Suma aprobata pe terminal este 0. Verificati terminalul."));
    return;
  }
  const added = bankPosMarkApproved(plan, meta);
  // Restul de plata DUPA preluare: daca suma era deja in ledger, el nu scade
  // din nou (altfel s-ar afisa un rest care nu mai exista).
  const rest = Math.max(0, posRound2(plan.total - paidTotal()));
  if (rest <= 0.005) {
    showToast(tr("Plata aprobata pe terminal"));
    await executeClose(plan.total, 0);
    return;
  }
  if (added !== true) {
    appAlert(tr("Plata aprobata pe terminal a fost deja preluata. Mai ramane de plata: {rest} Lei.", {
      rest: rest.toFixed(2)
    }));
    return;
  }
  if (plan.approved < plan.cardNeeded) {
    // Daca pe nota mai exista si alte sume aprobate pe card (swipe-uri
    // anterioare), restul se calculeaza din TOATE, nu doar din aceasta
    // incercare - altfel mesajul contrazice bannerul notei.
    const cardTotal = cardAmountForClose();
    appAlert(cardTotal > plan.approved + 0.005
      ? tr("Plata aprobata pe terminal: {suma} Lei. Total pe card: {total} Lei. Mai ramane de plata: {rest} Lei.", {
        suma: plan.approved.toFixed(2),
        total: cardTotal.toFixed(2),
        rest: rest.toFixed(2)
      })
      : tr("Plata aprobata pe terminal: {suma} Lei. Mai ramane de plata: {rest} Lei.", {
        suma: plan.approved.toFixed(2),
        rest: rest.toFixed(2)
      }));
    return;
  }
  appAlert(tr("Pe terminal a fost aprobata suma de {suma} Lei, dar pe nota mai era nevoie de {necesar} Lei. Verificati terminalul.", {
    suma: plan.approved.toFixed(2),
    necesar: plan.cardNeeded.toFixed(2)
  }));
}

// Butonul "Verifica" de pe ecranul de plata: verifica in jurnalul terminalului
// ultima incercare nerezolvata a notei, fara sa mai apeleze terminalul. Daca
// plata a fost aprobata, o preia si inchide nota (fara dialog de void).
async function actionBankPosCheck() {
  if (POS_STATE.closingBill) { return; }
  if (!POS_STATE.docId) {
    appAlert(tr("Nu exista o nota deschisa."));
    return;
  }
  if (!POS_STATE.bankPosCanResync) {
    appAlert(tr("Protocolul terminalului bancar nu suporta verificarea in jurnal."));
    return;
  }
  const check = await bankPosVerifySilently("");
  applyBankPosMeta(check.data);
  if (check.kind === "approved") {
    const approved = Number(check.amount) || Number(POS_STATE.bankPosLockAmount) || 0;
    await bankPosApplyApprovedOnPaymentScreen(approved, {
      source: "journal",
      trxId: check.trxId || POS_STATE.bankPosUid || ""
    });
    refreshBankPosCheckButton();
    return;
  }
  if (check.kind === "notfound") {
    showToast(tr("Cardul clientului NU a fost debitat. Puteti reincerca plata."));
  } else if (check.kind === "voided") {
    showToast(tr("Tranzactia a fost anulata pe terminal. Puteti reincerca plata."));
  } else {
    appAlert(tr("Terminalul nu a răspuns. Verificați terminalul, apoi apăsați „Da, am chitanța (Manual)”."));
  }
  refreshBankPosCheckButton();
}

// Butonul "Altă formă de plată / Anulează" din bara de marcare (nota blocata):
// daca terminalul a confirmat deja plata, incercarea NU poate fi declarata
// neaprobata (serverul o refuza cu 409) - se anuleaza pe terminal (void), dupa
// confirmare, si abia apoi nota se deblocheaza. Daca nu e nimic aprobat, se
// consemneaza abandonarea incercarii incerte (aceeasi cale ca "Nu a tipărit").
async function actionBankPosReturnToPay() {
  if (POS_STATE.closingBill || POS_STATE.cardPaying) { return; }
  if (!POS_STATE.docId) {
    appAlert(tr("Nu exista o nota deschisa."));
    return;
  }
  const st = await fetchBankPosStatus();
  // Comanda inca in curs: terminalul nu a raspuns, deci orice decizie (inclusiv
  // "nu a tiparit") ar fi prematura - terminalul poate aproba dupa aceea, iar
  // nota ar rămâne deblocata cu bani incasati. Se asteapta rezultatul.
  if ((st && st.pending === true) || POS_STATE.bankPosPending === true) {
    showToast(tr("Plata pe card este în curs: așteptați răspunsul terminalului."));
    watchBankPending();
    return;
  }
  const approvedTotal = Number((st && st.approvedTotal) || POS_STATE.bankPosApprovedTotal) || 0;
  if (approvedTotal > 0.005) {
    const ok = await appConfirmAsync(tr(
      "Plata pe card a fost confirmată ({suma} Lei). Dacă o anulați, suma se retrage pe terminal (void). Continuați?",
      { suma: approvedTotal.toFixed(2) }
    ), tr("Da, anulează"), tr("Nu"));
    if (!ok) { return; }
    const r = await bankPosVoidAll(true);
    if (!r.ok) {
      appAlert(tr("Plata pe card nu a putut fi anulată complet. Verificați terminalul: sumele rămân încasate până la clarificare."));
      await refreshBankPosCheckButton();
      return;
    }
    cardLedgerReset();
    bankPosUnlockLocal();
    await refreshBankPosCheckButton();
    showToast(tr("Plata pe card a fost anulată (void). Nota poate fi inchisă cu alta formă de plată."), 4000);
    return;
  }
  if (st && st.uncertain === true) {
    await resolveBankPosDecision(POS_STATE.docId, "neaprobat", st.uid || POS_STATE.bankPosUid);
  }
  bankPosUnlockLocal();
  await refreshBankPosCheckButton();
  showToast(tr("Plata pe card a fost anulata. Nota poate fi inchisa cu alta forma de plata."));
}

// Butonul "Manual" de pe ecranul de plata: verificarea facuta de operator pe
// terminal (fara parola), cu audit pe operator. Este iesirea si in FastFood,
// unde nota nu poate ramane deschisa.
async function actionBankPosManual() {
  if (POS_STATE.closingBill) { return; }
  if (!POS_STATE.docId) {
    appAlert(tr("Nu exista o nota deschisa."));
    return;
  }
  const st = await fetchBankPosStatus();
  if (!st || st.uncertain !== true) {
    appAlert(tr("Nu exista o plata bancara cu stare incerta pentru aceasta nota."));
    refreshBankPosCheckButton();
    return;
  }
  const res = await openBankPosManualModal({
    amount: st.amount,
    trxId: st.uid,
    connection: st.connection || POS_STATE.bankPosConnection,
    sursa: "ecran_plata",
    protocol: st.protocol || POS_STATE.bankPosProtocol,
    canResync: st.canResync === true,
    canVoid: st.canVoid === true,
    resyncApproved: st.resyncApproved === true,
    manualApproved: st.manualApproved === true
  });
  if (!res) { return; }
  if (res.verdict === "aprobat") {
    const approved = Number(res.data && res.data.amount) || Number(st.amount) || 0;
    await bankPosApplyApprovedOnPaymentScreen(approved, {
      source: "manual",
      trxId: (res.data && res.data.trxId) || st.uid || "",
      logId: (res.data && res.data.logId) || null
    });
  }
  // Decizia manuala este definitiva: starea incerta s-a incheiat, deci nota se
  // deblocheaza in UI (banner-ul dispare, butoanele normale revin). Daca nota
  // s-a inchis deja, apelul e inofensiv.
  bankPosUnlockLocal();
  refreshBankPosCheckButton();
}

// Retiparirea chitantei de card (duplicat pe imprimanta POS).
//
// Protocoalele ECR folosite nu au comanda de reprint: chitanta originala a
// terminalului se retipareste doar din meniul lui. Duplicatul de aici este
// tiparit din datele durabile din tblPosBancaLog (suma, RRN, STAN, AUTHCODE,
// PAN mascat) si este marcat explicit "DUPLICAT".
async function actionBankPosReprint(docId, logId) {
  if (POS_STATE.retiparireChitantaPoSbanca !== 1) {
    appAlert(tr("Retiparirea chitantei terminalului bancar este dezactivata din Setari."));
    return;
  }
  const last = POS_STATE.lastCardReprint || {};
  const id = Number(docId || POS_STATE.docId || last.docId || 0);
  if (!id) {
    appAlert(tr("Nu exista o nota pentru retiparirea chitantei."));
    return;
  }
  // Fara un logId implicit: serverul alege ULTIMA tranzactie aprobata a notei
  // (dupa Id), deci o reînsămânțare a ledger-ului intr-o alta ordine nu poate
  // face butonul sa tipareasca o tranzactie mai veche.
  const lid = Number(logId || 0);
  let data;
  try {
    const resp = await fetch("api/pos_banca.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "reprint", docId: id, logId: lid || null })
    });
    data = await resp.json();
  } catch (err) {
    appAlert(tr("Eroare la retiparirea chitantei: ") + (err.message || ""));
    return;
  }
  if (!data || data.status !== "success") {
    appAlert(data && data.message
      ? tr(data.message)
      : tr("Chitanta nu a putut fi retiparita."));
    return;
  }
  let html = escapeHtml(tr("Duplicatul chitantei a fost adaugat in coada de tiparire."));
  if (data.jobId) {
    html += ' <span style="color:#666;">(job ' + escapeHtml(String(data.jobId)) + ')</span>';
  }
  // Instructiunea pentru originalul terminalului este utila mereu, chiar si
  // cand aprobarea a fost manuala (fara STAN/RRN in audit).
  html += '<br><br>' + escapeHtml(tr("Pentru chitanta originala a terminalului: MENU -> Reprint last receipt."));
  if (data.stan) { html += '<br>STAN: ' + escapeHtml(String(data.stan)); }
  if (data.rrn) { html += '<br>RRN: ' + escapeHtml(String(data.rrn)); }
  appAlertHtml(html);
}

// Trimite decizia manuala la server. Fara parola: serverul o consemneaza in
// tblPosBancaLog (operator, suma, TRX_ID, IP) si in logs/app_events.log.
async function bankPosManualRequest(verdict, sursa, cod) {
  const resp = await fetch("api/pos_banca.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      action: "resolve_manual",
      docId: POS_STATE.docId,
      verdict: verdict,
      cod: String(cod || "").replace(/\s+/g, ""),
      nrOp: posBancaManualNrOp(),
      sursa: sursa || "manual"
    })
  });
  const data = await resp.json();
  if (!data || data.status !== "success") {
    if (data && data.code === "auth_code_required") {
      throw new Error(tr("Pentru aprobare introduceți AUTHCODE-ul de pe chitanța terminalului."));
    }
    throw new Error(tr((data && data.message) || tr("Eroare la salvarea deciziei manuale.")));
  }
  applyBankPosMeta(data);
  return data;
}

// Modalul de verificare manuala: doar Aprobat / Inapoi. Aprobarea se face cu
// AUTHCODE-ul de pe chitanta terminalului: EXACT 6 caractere alfanumerice.
// Codul poate fi alfanumeric, deci il normalizam la majuscule si il tratam ca text.
function bankPosAuthCodeFilter(value) {
  return String(value || "").toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, 6);
}

function bankPosAuthCodeValid(cod) {
  const c = String(cod || "").replace(/\s+/g, "").toUpperCase();
  return /^[A-Z0-9]{6}$/.test(c);
}

// Scrie codul in campul din modal (majuscule, doar A-Z/0-9, maxim 6) si
// actualizeaza starea butonului Aprobat.
function bankPosManualSetCode(value) {
  const el = document.getElementById("pos-banca-manual-cod");
  const clean = bankPosAuthCodeFilter(value);
  if (el && el.value !== clean) { el.value = clean; }
  bankPosManualValidateCode();
  return clean;
}

// Marcheaza sub camp un cod incomplet/gresit, fara sa blocheze tastarea.
function bankPosManualValidateCode() {
  const el = document.getElementById("pos-banca-manual-cod");
  const err = document.getElementById("pos-banca-manual-err");
  const raw = el ? bankPosAuthCodeFilter(el.value) : "";
  if (el && el.value !== raw) { el.value = raw; }
  const ok = bankPosAuthCodeValid(raw);
  if (err) {
    err.textContent = (raw !== "" && !ok)
      ? tr("AUTHCODE invalid: trebuie exact 6 caractere (litere/cifre).")
      : "";
  }
  if (el) { el.classList.toggle("err", raw !== "" && !ok); }
  const aprobat = document.getElementById("pos-banca-manual-aprobat");
  if (aprobat) { aprobat.disabled = !ok; }
  return ok;
}

// Tastatura alfanumerica (QWERTY) pentru AUTHCODE: se deschide peste modalul
// de solutionare manuala si scrie direct in campul acestuia.
function bankPosManualCaptureCode() {
  const el = document.getElementById("pos-banca-manual-cod");
  const modal = document.getElementById("modal-keyboard");
  const input = document.getElementById("virtual-keyboard-input");
  const title = document.getElementById("kb-title");
  const subtitle = document.getElementById("kb-subtitle");
  if (!modal || !input) { return; }

  keyboardAction = "bankpos_auth";
  logUiEvent("kb_open", { modul: "bankpos_auth", ecran: currentScreenId() });
  if (title) { title.innerText = tr("AUTHCODE"); }
  if (subtitle) { subtitle.innerText = tr("Codul de autorizare de pe chitanța terminalului (6 caractere)"); }
  input.type = "text";
  input.value = bankPosAuthCodeFilter(el ? el.value : "");
  // Tastatura se deschide PESTE modalul manual (care are z-index 1200).
  modal.style.zIndex = "2700";
  modal.classList.add("active");
  vkShift = false;
  vkCaps = true; // AUTHCODE-urile sunt de regula cu majuscule
  vkRender();
}

// Modalul de solutionare manuala: Aprobat / Inapoi. Aprobarea cere AUTHCODE-ul
// de pe chitanta terminalului (6 caractere alfanumerice), ca sa nu poata fi
// facuta "din burta"; serverul valideaza din nou acelasi cod.
function openBankPosManualModal(info) {
  info = info || {};
  const modal = document.getElementById("modal-pos-banca-manual");
  const infoEl = document.getElementById("pos-banca-manual-info");
  if (!modal || !infoEl) {
    const approved = window.confirm(tr("Plata a fost aprobata pe terminal?"));
    if (!approved) { return Promise.resolve(null); }
    const cod = bankPosAuthCodeFilter(window.prompt(
      tr("AUTHCODE de pe chitanța terminalului:"), ""
    ) || "");
    if (!bankPosAuthCodeValid(cod)) {
      appAlert(tr("AUTHCODE invalid: trebuie exact 6 caractere (litere/cifre)."));
      return Promise.resolve(null);
    }
    return bankPosManualRequest("aprobat", info.sursa || "manual", cod)
      .then(data => ({ verdict: "aprobat", data }))
      .catch(err => {
        appAlert(err.message || tr("Eroare la salvarea deciziei manuale."));
        return null;
      });
  }

  const amount = Number(info.amount) || Number(POS_STATE.bankPosLockAmount) || 0;
  // Suma este elementul pe care operatorul il coreleaza cu chitanta: se scrie
  // mare si bold. Titlul ferestrei spune deja ce se intampla, deci nu repetam.
  infoEl.innerHTML =
    '<div class="pos-banca-man-suma">' + tr("Suma: ") + escapeHtml(amount.toFixed(2)) + ' Lei</div>'
    + '<div class="pos-banca-man-op">' + tr("Operator: ") + escapeHtml(bankPosOperatorLabel()) + '</div>';

  const codEl = document.getElementById("pos-banca-manual-cod");
  const errEl = document.getElementById("pos-banca-manual-err");
  if (codEl) { codEl.value = ""; codEl.classList.remove("err"); }
  if (errEl) { errEl.textContent = ""; }

  const aprobat = document.getElementById("pos-banca-manual-aprobat");
  const cancel = document.getElementById("pos-banca-manual-cancel");
  const all = [aprobat, cancel].filter(Boolean);
  if (cancel) { cancel.disabled = false; }
  // Aprobarea este conditionata de AUTHCODE (vezi bankPosManualValidateCode):
  // butonul rămâne gri pana la exact 6 caractere alfanumerice.
  if (aprobat) { aprobat.disabled = true; }

  return new Promise(resolve => {
    const close = (val) => {
      modal.classList.remove("active");
      // Tastatura AUTHCODE poate fi deschisa peste acest modal: o inchidem si pe ea.
      if (keyboardAction === "bankpos_auth") {
        const kb = document.getElementById("modal-keyboard");
        if (kb && kb.classList.contains("active")) { closeKeyboardModal(); }
      }
      if (aprobat) aprobat.onclick = null;
      if (cancel) cancel.onclick = null;
      resolve(val);
    };
    const submit = async () => {
      const cod = bankPosManualSetCode(codEl ? codEl.value : "");
      // Fara AUTHCODE de pe chitanta nu se poate aproba: altfel un operator ar
      // putea inchide nota pe card fara ca terminalul sa fi incasat nimic.
      if (!bankPosAuthCodeValid(cod)) {
        if (errEl) {
          errEl.textContent = tr("Pentru aprobare introduceți AUTHCODE-ul de pe chitanța terminalului.");
        }
        if (codEl) { codEl.classList.add("err"); }
        bankPosManualCaptureCode();
        return;
      }
      all.forEach(btn => { btn.disabled = true; });
      try {
        const data = await bankPosManualRequest("aprobat", info.sursa || "manual", cod);
        close({ verdict: "aprobat", data });
      } catch (err) {
        if (cancel) { cancel.disabled = false; }
        // Cod respins: golim campul, ca operatorul sa-l poata retasta.
        if (codEl) { codEl.value = ""; }
        if (errEl) { errEl.textContent = ""; }
        bankPosManualValidateCode();
        appAlert(err.message || tr("Eroare la salvarea deciziei manuale."));
      }
    };
    if (aprobat) aprobat.onclick = submit;
    if (cancel) cancel.onclick = () => close(null);
    modal.classList.add("active");
  });
}

// Dialog cu rezultatul unei plati care nu a putut fi confirmata direct.
// Nu expune operatorului masina de stari: fara "jurnal", "UNKNOWN" sau istoric
// de actiuni. Mesaj binar (banii s-au luat sau nu) + doua actiuni.
// ctx.kind: "declined" | "notfound" | "voided" | "noresponse" | "unreachable".
// Rezolva o DECIZIE (nu scrie in nota):
//   { decision:'cancel'|'retry' }
//   { decision:'approved', amount, data }  (operatorul a confirmat Manual)
function openPosBancaDialog(info, ctx) {
  ctx = ctx || {};
  info = info || {};
  const modal = document.getElementById("modal-pos-banca");
  const kind = ctx.kind || "declined";
  const trx = String(ctx.trxId || info.trxId || "").trim();
  applyBankPosMeta(info);

  const noResponse = (kind === "noresponse");

  // Titlu + mesaj, fara termeni tehnici.
  let title = tr("PLATA REFUZATA");
  let text = tr("Terminalul a refuzat plata. Incercati din nou sau alegeti alta metoda de plata.");
  if (kind === "notfound" || kind === "voided") {
    title = tr("PLATA NEPROCESATA");
    text = tr("Timpul alocat platii a expirat. Cardul clientului NU a fost debitat.");
  } else if (kind === "unreachable") {
    // Terminalul nu a putut fi contactat: comanda nu a plecat, deci cardul NU
    // a fost debitat. Nu e nimic de verificat/declarat: doar o eroare de
    // legatura, cu reincercare sigura.
    title = tr("TERMINALUL NU RASPUNDE");
    text = tr("Terminalul bancar nu răspunde. Verificați că este pornit și conectat, apoi reîncercați.");
  } else if (noResponse) {
    title = tr("PLATA NECLARIFICATA");
    // Instruiune fizica, fara termeni tehnici: operatorul trebuie doar sa se
    // uite daca terminalul a tiparit chitanta.
    text = tr("Verificați dacă terminalul a tipărit chitanța cu APROBAT:");
  }

  if (!modal) {
    // Fara modal: nicio reincercare pe o stare neclarificata (ar putea taxa
    // cardul a doua oara).
    if (noResponse) {
      window.alert(text);
      return Promise.resolve({ decision: "cancel" });
    }
    return Promise.resolve({
      decision: window.confirm(text + "\n\n" + tr("Reincercati plata?")) ? "retry" : "cancel"
    });
  }

  const titleEl = document.getElementById("pos-banca-title");
  const msgEl = document.getElementById("pos-banca-message");
  const trxEl = document.getElementById("pos-banca-trx");
  const cancel = document.getElementById("pos-banca-cancel");
  const retry = document.getElementById("pos-banca-retry");
  const manual = document.getElementById("pos-banca-manual");

  if (titleEl) { titleEl.textContent = title; }
  if (msgEl) { msgEl.textContent = text; }
  // TRX_ID ramane doar pentru suport tehnic: mic, gri, in colt.
  if (trxEl) { trxEl.textContent = trx ? ("TRX_ID: " + trx) : ""; }

  // Butoanele raspund direct la intrebarea din mesaj (Da / Nu), in loc sa
  // descrie mecanismul ("Soluționare manuală" / "Altă metodă").
  if (cancel) {
    cancel.textContent = noResponse
      ? tr("Nu a tipărit (Anulează)")
      : tr("Altă metodă / Anulează");
  }
  if (manual) { manual.textContent = tr("Da, am chitanța (Manual)"); }

  [cancel, retry, manual].forEach(btn => { if (btn) { btn.disabled = false; } });
  // "Reincearca plata" doar cand stim sigur ca nu s-a incasat nimic. Pe cazul
  // "terminal mut" butonul este ascuns complet, nu doar dezactivat: un buton
  // gri care nu face nimic aglomereaza dialogul si sugereaza o actiune posibila.
  if (retry) {
    retry.disabled = noResponse;
    retry.style.display = noResponse ? "none" : "";
  }
  // Manual doar cand terminalul nu poate fi verificat (nu raspunde / jurnal indisponibil).
  if (manual) { manual.style.display = noResponse ? "" : "none"; }

  return new Promise(resolve => {
    let doneCalled = false;
    const done = (val) => {
      if (doneCalled) { return; }
      doneCalled = true;
      modal.classList.remove("active");
      if (cancel) cancel.onclick = null;
      if (retry) retry.onclick = null;
      if (manual) manual.onclick = null;
      resolve(val);
      // Starea butoanelor Verifica / Manual de pe ecranul de plata se poate
      // schimba (ex. dupa Cancel sau dupa Manual).
      setTimeout(refreshBankPosCheckButton, 0);
    };

    if (cancel) { cancel.onclick = () => done({ decision: "cancel" }); }
    if (retry) { retry.onclick = () => done({ decision: "retry" }); }
    if (manual) {
      manual.onclick = async () => {
        const res = await openBankPosManualModal({
          amount: ctx.amount || info.amount || "",
          trxId: trx,
          connection: POS_STATE.bankPosConnection,
          sursa: "dialog",
          protocol: POS_STATE.bankPosProtocol,
          canResync: POS_STATE.bankPosCanResync,
          canVoid: POS_STATE.bankPosCanVoid
        });
        if (!res) { return; }
        if (res.verdict === "aprobat") {
          // Decizia se intoarce apelantului (funnel-ul), care o scrie in ledger.
          // Dialogul NU atinge nota.
          done({
            decision: "approved",
            amount: Number(res.data && res.data.amount) || Number(ctx.amount) || Number(info.amount) || 0,
            data: res.data || null
          });
          return;
        }
        done({ decision: "cancel" });
      };
    }

    modal.classList.add("active");
  });
}

// Indicator de asteptare (fara butoane). Textul se poate schimba in timpul
// verificarii automate, ca operatorul sa stie ce se intampla.
function showBankPosWait(text) {
  const modal = document.getElementById("modal-pos-banca-wait");
  const el = document.getElementById("pos-banca-wait-text");
  if (el) {
    el.textContent = text ? tr(text) : tr("Se asteapta terminalul bancar...");
  }
  if (modal) modal.classList.add("active");
}

function hideBankPosWait() {
  const modal = document.getElementById("modal-pos-banca-wait");
  if (modal) modal.classList.remove("active");
}

// Asteptarea raspunsului casei de marcat (FiscalWire) la inchiderea notei.
function showFiscalWait() {
  const modal = document.getElementById("modal-fiscal-wait");
  if (modal) modal.classList.add("active");
}

function hideFiscalWait() {
  const modal = document.getElementById("modal-fiscal-wait");
  if (modal) modal.classList.remove("active");
}

// Readuce ecranul de plata la starea initiala (fara plati inregistrate).
// Cardul deja taxat (cardLedger) NU se sterge: banii au fost incasati si linia
// de card se regenereaza automat din ledger.
function resetPaymentScreen() {
  POS_STATE.payments = {};
  POS_STATE.rest = 0;
  POS_STATE.cardDeclared = 0;
  POS_STATE.activePaymentFp = null;
  // Restul de plata, nu totalul notei (cardul aprobat rămâne in ledger).
  updatePayAmountDefault(true);
  const def = POS_STATE.paymentForms.find(f => f.Poz === 1) || POS_STATE.paymentForms[0];
  if (def && POS_STATE.plataCombinata !== 0) { selectPaymentMethod(def.FPID); }
  updatePaymentProgress();
}

// --------------------------------------------------------------------------
// 5. AUTENTIFICARE CU PAROLA / CAUTARE PRODUS (tastatura virtuala comuna)
// --------------------------------------------------------------------------
let keyboardAction = "parola";
let pendingLoginCallback = null;
const WAITER_STORAGE_KEY = "rual_waiter";

function updateCashierLabel() {
  const el = document.getElementById("current-cashier-label");
  if (el) el.innerText = POS_STATE.loggedIn ? (POS_STATE.casierCurent || "") : tr("Neautentificat");
}

// Seteaza ospatarul autentificat. Daca Mod_logare = 1, sesiunea se pastreaza
// in sessionStorage (supravietuieste reload-ului din aceeasi sesiune de browser).
function setAuthenticatedWaiter(nrOsp, nume, rol) {
  POS_STATE.nrOp = parseInt(nrOsp, 10) || null;
  POS_STATE.casierCurent = nume || "";
  POS_STATE.waiterRol = rol || "";
  POS_STATE.loggedIn = true;
  updateCashierLabel();
  if (POS_STATE.modLogare === 1) {
    try {
      sessionStorage.setItem(WAITER_STORAGE_KEY, JSON.stringify({
        nrOp: POS_STATE.nrOp, nume: POS_STATE.casierCurent, rol: POS_STATE.waiterRol
      }));
    } catch (e) { /* ignoram */ }
  }
}

function clearWaiterSession() {
  try { sessionStorage.removeItem(WAITER_STORAGE_KEY); } catch (e) { /* ignoram */ }
}

function restoreWaiterSession() {
  if (POS_STATE.modLogare !== 1) { clearWaiterSession(); return; }
  try {
    const raw = sessionStorage.getItem(WAITER_STORAGE_KEY);
    if (!raw) return;
    const w = JSON.parse(raw);
    if (w && w.nrOp) setAuthenticatedWaiter(w.nrOp, w.nume, w.rol);
  } catch (e) { /* ignoram */ }
}

// Masa pe care lucra operatorul, pastrata peste un refresh (Ctrl+F5). Fara ea,
// dupa un reload aplicatia pornea mereu pe Masa 1, iar nota de pe masa reala
// disparea de pe ecran - inclusiv una care astepta raspunsul terminalului
// bancar (incercare UNKNOWN), care trebuie vazuta ca sa fie clarificata.
const TABLE_STORAGE_KEY = "rual_masa_curenta";

function saveCurrentTable(masa) {
  // In FastFood nu exista masa selectata: nota unica este pe masa 1, iar o masa
  // memorata aici ar fi folosita la urmatorul refresh (comenzi pe masa greșita).
  if (isFastFood()) { return; }
  const n = Number(masa) || 0;
  if (n < 1 || n > 80) { return; }
  try { window.sessionStorage.setItem(TABLE_STORAGE_KEY, String(n)); } catch (e) { /* ignoram */ }
}

function clearCurrentTable() {
  try { window.sessionStorage.removeItem(TABLE_STORAGE_KEY); } catch (e) { /* ignoram */ }
}

function savedCurrentTable() {
  try {
    const n = Number(window.sessionStorage.getItem(TABLE_STORAGE_KEY));
    return (n >= 1 && n <= 80) ? n : 0;
  } catch (e) { return 0; }
}

function logoutWaiter() {
  POS_STATE.loggedIn = false;
  POS_STATE.nrOp = null;
  POS_STATE.casierCurent = "";
  POS_STATE.waiterRol = null;
  POS_STATE.bonNrOp = null;
  clearWaiterSession();
  updateCashierLabel();
}

// Trimite un eveniment de UI in jurnalul de pe server (logs/app_events.log).
// Scop: sa avem contextul complet cand aplicatia "se inchide singura" - ce modal
// de parola s-a deschis, in ce mod si pe ce ecran. Nu trimite date sensibile.
function logUiEvent(event, extra) {
  try {
    fetch("api/order_action.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(Object.assign({ action: "client_event", event: event }, extra || {})),
      keepalive: true
    }).catch(() => {});
  } catch (e) { /* jurnalul nu are voie sa strice fluxul */ }
}

function currentScreenId() {
  const s = document.querySelector(".pos-screen.active");
  return s ? (s.id || "") : "";
}

// Deschide modalul de autentificare in modul "ospatar" si executa callback-ul
// dupa autentificare reusita (ex. continuarea deschiderii mesei).
function openLoginModal(callback) {
  keyboardAction = "login";
  pendingLoginCallback = callback || null;
  logUiEvent("kb_open", { modul: "login", ecran: currentScreenId() });
  const title = document.getElementById("kb-title");
  const subtitle = document.getElementById("kb-subtitle");
  if (title) title.innerText = tr("AUTENTIFICARE OSPATAR");
  if (subtitle) subtitle.innerText = tr("Introduceti parola");
  const modal = document.getElementById("modal-keyboard");
  const input = document.getElementById("virtual-keyboard-input");
  if (input) { input.type = "password"; input.value = ""; }
  if (modal) { modal.style.zIndex = ""; modal.classList.add("active"); }
  vkShift = false;
  vkCaps = false;
  vkRender();
}

function openKeyboardModal(mode) {
  keyboardAction = mode === "Cautare" ? "search" : "parola";
  logUiEvent("kb_open", { modul: keyboardAction, buton: mode, ecran: currentScreenId() });
  const title = document.getElementById("kb-title");
  const subtitle = document.getElementById("kb-subtitle");
  if (title) title.innerText = tr(keyboardAction === "search" ? "CAUTARE PRODUS" : "AUTENTIFICARE");
  if (subtitle) subtitle.innerText = tr(keyboardAction === "search" ? "Introduceti textul cautarii" : "Introduceti parola");

  const modal = document.getElementById("modal-keyboard");
  const input = document.getElementById("virtual-keyboard-input");
  input.type = keyboardAction === "search" ? "text" : "password";
  input.value = "";
  modal.style.zIndex = "";
  modal.classList.add("active");
  vkShift = false;
  vkCaps = false;
  vkRender();
}

function closeKeyboardModal() {
  const modal = document.getElementById("modal-keyboard");
  if (modal) {
    modal.classList.remove("active");
    // Fara z-index-ul ridicat folosit peste modalul manual (vezi
    // bankPosManualCaptureCode); urmatoarea deschidere (login/cautare) revine normal.
    modal.style.zIndex = "";
  }
  logUiEvent("kb_close", { modul: keyboardAction, ecran: currentScreenId() });
}

// Tastarea in modul AUTHCODE: doar A-Z/0-9, majuscule, maxim 6 caractere.
// Valoarea se oglindeste imediat in modalul manual, iar la 6 caractere
// tastatura se inchide, ca operatorul sa vada butonul Aprobat activ.
// `closeAtSix` = false la Backspace/Clear/OK, ca redeschiderea unui cod deja
// complet sa nu inchida tastatura la prima tasta (trebuie intai sters).
function vkeySyncBankPosAuth(closeAtSix) {
  const input = document.getElementById("virtual-keyboard-input");
  if (!input) { return ""; }
  const value = bankPosAuthCodeFilter(input.value);
  if (input.value !== value) { input.value = value; }
  bankPosManualSetCode(value);
  if (closeAtSix !== false && value.length >= 6) { closeKeyboardModal(); }
  return value;
}

function vkeyInput(char) {
  const input = document.getElementById("virtual-keyboard-input");
  if (keyboardAction === "bankpos_auth") {
    const before = input.value.length;
    input.value += char;
    vkeySyncBankPosAuth(before < 6);
    return;
  }
  input.value += char;
}

function vkeyBackspace() {
  const input = document.getElementById("virtual-keyboard-input");
  if (keyboardAction === "bankpos_auth") {
    input.value = input.value.slice(0, -1);
    vkeySyncBankPosAuth(false);
    return;
  }
  input.value = input.value.slice(0, -1);
}

function vkeyClear() {
  const input = document.getElementById("virtual-keyboard-input");
  if (keyboardAction === "bankpos_auth") {
    input.value = "";
    vkeySyncBankPosAuth(false);
    return;
  }
  input.value = "";
}

// Tastatura de autentificare / cautare: litere mici/mari + Shift/Caps (acelasi principiu ca tastatura flotanta)
let vkShift = false;
let vkCaps = false;

function vkRender() {
  const wrap = document.getElementById("vk-rows");
  if (!wrap) return;
  const upper = vkShift || vkCaps;

  let html = "";
  // 1. Rindul Q..P
  let row = "";
  ["Q", "W", "E", "R", "T", "Y", "U", "I", "O", "P"].forEach(l => {
    const ch = upper ? l : l.toLowerCase();
    row += `<button class="qkey" data-vk-char="${ch}" onclick="vkKey(this.dataset.vkChar)">${ch}</button>`;
  });
  html += '<div class="qwerty-row">' + row + "</div>";

  // 2. Rindul A..L
  row = "";
  ["A", "S", "D", "F", "G", "H", "J", "K", "L"].forEach(l => {
    const ch = upper ? l : l.toLowerCase();
    row += `<button class="qkey" data-vk-char="${ch}" onclick="vkKey(this.dataset.vkChar)">${ch}</button>`;
  });
  html += '<div class="qwerty-row">' + row + "</div>";

  // 3. Rindul Z..M + Backspace
  row = "";
  ["Z", "X", "C", "V", "B", "N", "M"].forEach(l => {
    const ch = upper ? l : l.toLowerCase();
    row += `<button class="qkey" data-vk-char="${ch}" onclick="vkKey(this.dataset.vkChar)">${ch}</button>`;
  });
  html += '<div class="qwerty-row">' + row;
  html += '<button class="qkey key-enter" onclick="vkeyBackspace()">Backspace</button></div>';

  // 4. Rindul de control: Shift, Caps, space, ENTER
  const shiftOn = vkShift || vkCaps;
  html += '<div class="qwerty-row">';
  html += `<button class="qkey ${shiftOn ? "fkb-shift-on" : ""}" style="min-width:70px;" onclick="vkToggleShift()">⇧</button>`;
  html += `<button class="qkey ${vkCaps ? "fkb-caps-on" : ""}" style="min-width:58px;font-size:14px;" onclick="vkToggleCaps()">CAPS</button>`;
  html += '<button class="qkey key-space" onclick="vkeyInput(\' \')">SPACE</button>';
  html += '<button class="qkey key-enter" onclick="vkeyValidate()">ENTER</button>';
  html += "</div>";

  wrap.innerHTML = html;
}

function vkToggleShift() {
  vkShift = !vkShift;
  vkRender();
}

function vkToggleCaps() {
  vkCaps = !vkCaps;
  vkShift = false;
  vkRender();
}

function vkKey(ch) {
  vkeyInput(ch);
  if (vkShift && !vkCaps) {
    vkShift = false;
    vkRender();
  }
}

// Suport pentru tastatura fizica: cand modalul de autentificare/cautare este
// deschis, tastele fizice scriu in campul virtual (litere, cifre, spatiu,
// Backspace) si Enter valideaza. Escape inchide modalul.
document.addEventListener("keydown", function (e) {
  const modal = document.getElementById("modal-keyboard");
  if (!modal || !modal.classList.contains("active")) return;
  // Nu interceptam cand un alt dialog este peste (alerta/confirmare).
  if (document.querySelector("#modal-alert.active, #modal-confirm.active")) return;

  if (e.key === "Enter") { e.preventDefault(); vkeyValidate(); return; }
  if (e.key === "Escape") { e.preventDefault(); closeKeyboardModal(); return; }
  if (e.key === "Backspace") { e.preventDefault(); vkeyBackspace(); return; }
  if (e.key === " ") { e.preventDefault(); vkeyInput(" "); return; }
  if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
    e.preventDefault();
    vkeyInput(e.key);
  }
});

async function vkeyValidate() {
  const input = document.getElementById("virtual-keyboard-input");
  const valoare = input.value;

  if (keyboardAction === "bankpos_auth") {
    // Valoarea este deja oglindita in modal (vkeySyncBankPosAuth); aici doar
    // confirmam ca sunt exact 6 caractere si inchidem tastatura.
    const cod = vkeySyncBankPosAuth(false);
    if (cod.length !== 6) {
      appAlert(tr("Introduceți exact 6 caractere (litere sau cifre)."));
      return;
    }
    closeKeyboardModal();
    return;
  }

  if (keyboardAction === "search") {
    doProductSearch(valoare);
    return;
  }

  if (!valoare) {
    appAlert("Introduceti parola!");
    return;
  }

  try {
    const resp = await fetch("api/order_action.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        action: "authenticate",
        parola: valoare,
        sursa: keyboardAction
      })
    });

    const res = await resp.json();

    // Mod "login" (intrarea pe masa): acceptam doar parola de ospatar.
    if (keyboardAction === "login") {
      if (res.status !== "success" || res.programare || res.rapoarte || res.exit) {
        appAlert("Parola incorecta sau nu apartine unui ospatar!");
        return;
      }
      setAuthenticatedWaiter(res.nrOsp, res.nume, res.rol);
      closeKeyboardModal();
      showToast(`Autentificat: ${res.nume} (${res.rol})`);
      const cb = pendingLoginCallback;
      pendingLoginCallback = null;
      if (cb) cb();
      return;
    }

    if (res.status !== "success") {
      appAlert(res.message || "Parola incorecta!");
      return;
    }

    if (res.programare) {
      closeKeyboardModal();
      openProgramming();
      return;
    }

    if (res.rapoarte) {
      closeKeyboardModal();
      openReports();
      return;
    }

    // Parola de inchidere aplicatie: se inchide fereastra POS (kiosk).
    if (res.exit) {
      closeKeyboardModal();
      exitApplication(valoare);
      return;
    }

    setAuthenticatedWaiter(res.nrOsp, res.nume, res.rol);
    closeKeyboardModal();
    showToast(`Autentificat: ${res.nume} (${res.rol})`);
  } catch (err) {
    appAlert("Eroare la autentificare: " + err.message);
  }
}

// Inchide aplicatia POS: cere serverului sa termine procesele de browser ale
// aplicatiei si, in plus, incearca window.close() (browser normal).
function exitApplication(parola) {
  try {
    fetch("api/exit_app.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ parola: parola || "" }),
      keepalive: true
    }).catch(() => {});
  } catch (err) { /* fereastra se inchide oricum */ }
  setTimeout(() => { try { window.close(); } catch (e) {} }, 150);
}

// --------------------------------------------------------------------------
// CAUTARE PRODUSE (panoul albastru afiseaza rezultatele cautarii)
// --------------------------------------------------------------------------
async function doProductSearch(query) {
  const q = (query || "").trim();
  if (!q) {
    showToast("Introduceti un termen de cautare");
    return;
  }
  closeKeyboardModal();
  try {
    const resp = await fetch("api/menu.php?search=" + encodeURIComponent(q));
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message || "Eroare cautare");
    renderSearchResults(q, data.searchProducts || []);
  } catch (err) {
    showToast("Eroare la cautare: " + err.message);
  }
}

function renderSearchResults(query, products) {
  POS_STATE.currentPanelMode = "search";
  POS_STATE.activeGroup = null;

  const header = document.getElementById("group-nav-header");
  const title = document.getElementById("active-group-title");
  const backBtn = document.getElementById("btn-group-back");
  if (header && title) {
    header.style.display = "flex";
    title.innerText = tr("REZULTATE: ") + query;
    if (backBtn) {
      backBtn.innerText = tr("⬅ INAPOI LA GRUPE");
      backBtn.onclick = () => renderMenuGrid();
    }
  }

  const grid = document.getElementById("menu-5x-grid");
  if (!grid) return;
  grid.innerHTML = "";

  if (products.length === 0) {
    showToast("Niciun produs gasit");
  }

  for (let i = 0; i < 50; i++) {
    const p = products[i];
    if (p) {
      grid.appendChild(makeProductGridBtn(p));
    } else {
      const empty = document.createElement("div");
      empty.className = "grid-pos-btn empty";
      grid.appendChild(empty);
    }
  }
}

// Construieste un buton de produs in grila (folosit si la cautare)
function makeProductGridBtn(p) {
  const btn = document.createElement("button");
  btn.className = "grid-pos-btn grid-prod-btn";
  const isBlackBg = (p.rawBackColor === 0 || p.BackColor === "#000000");
  if (isBlackBg) {
    btn.style.backgroundColor = "#000000";
    btn.style.color = "#ffffff";
    btn.style.borderColor = "#555555";
  } else {
    if (p.BackColor) btn.style.backgroundColor = p.BackColor;
    if (p.FontColor) btn.style.color = p.FontColor;
  }
  if (p.Bold) btn.style.fontWeight = "bold";
  const searchFontSize = effectiveFontSize(p);
  if (searchFontSize) btn.style.fontSize = searchFontSize + "px";
  const searchFontType = effectiveFontType(p);
  if (searchFontType) btn.style.fontFamily = searchFontType;

  btn.innerHTML = escapeHtml(p.Denumire);
  btn.onclick = () => addProduct(p.ProdID);
  return btn;
}

// --------------------------------------------------------------------------
// 6. UTILITARE & NAVIGARE
// --------------------------------------------------------------------------
function navigateToScreen(screenId) {
  // Mod FastFood: ecranul de mese nu este niciodata afisat.
  if (isFastFood() && screenId === "screen-mese") {
    screenId = "screen-marcare";
  }

  const prev = document.querySelector(".pos-screen.active");
  const prevId = prev ? prev.id : null;

  // "Totul sau nimic": ecranul de plata nu se paraseste cu bani aprobati pe
  // card. Ieșirea se face printr-un singur loc, care anuleaza (void) sumele de
  // pe terminal (cu confirmare) sau arunca sumele doar tastate.
  if (prevId === "screen-plata" && screenId !== "screen-plata") {
    leavePaymentScreen(screenId);
    return;
  }

  navigateToScreenNow(screenId);
}

// Navigarea efectiva (fara garda de plata), ca leavePaymentScreen sa poata
// pleca dupa void fara sa reintre in confirmare.
function navigateToScreenNow(screenId) {
  if (isFastFood() && screenId === "screen-mese") {
    screenId = "screen-marcare";
  }

  const prev = document.querySelector(".pos-screen.active");
  const prevId = prev ? prev.id : null;

  document.querySelectorAll(".pos-screen").forEach(s => s.classList.remove("active"));
  const target = document.getElementById(screenId);
  if (target) target.classList.add("active");

  // Mod_logare = 0: delogare automata la iesirea de pe masa (marcare/plata -> mese).
  if (POS_STATE.modLogare === 0 && screenId === "screen-mese" &&
      (prevId === "screen-marcare" || prevId === "screen-plata")) {
    logoutWaiter();
  }

  if (screenId === "screen-plata") {
    syncPaymentScreen();
  }

  // Reincarcam mereu starea/configuratia meselor cand intram pe selectia de mese
  if (screenId === "screen-mese") {
    loadTables();
  }

  // Campul de scanare primeste focusul cand intram in ecranul de marcare
  if (screenId === "screen-marcare") {
    setTimeout(focusScanInput, 50);
  }
}

// --------------------------------------------------------------------------
// PROGRAMARE POS (parola din tblParola.ParolaProgramare)
// --------------------------------------------------------------------------
let progPrevScreen = "screen-marcare";

function openProgramming() {
  const active = document.querySelector(".pos-screen.active");
  progPrevScreen = active ? active.id : "screen-marcare";
  navigateToScreen("screen-programare");
  applyServerModeUI();
  loadAppVersion();
  showToast("Acces programare");
}

// --------------------------------------------------------------------------
// VERSIUNE: versiunea aplicatiei care ruleaza pe aceasta stație (api/version.php)
// --------------------------------------------------------------------------
let APP_VERSION_INFO = null;

async function loadAppVersion() {
  const label = document.getElementById("app-version-label");
  try {
    const data = await fetchJsonNoStore("api/version.php");
    APP_VERSION_INFO = data;
    if (label) { label.textContent = data.label || ""; }
    // Titlul tabului reflecta intotdeauna versiunea reala a aplicatiei.
    if (data && data.version) {
      document.title = "TableManager " + data.version + " - Modern Web POS";
    }
  } catch (err) {
    if (label) { label.textContent = tr("versiune indisponibila"); }
  }
  return APP_VERSION_INFO;
}

function closeProgramming() {
  navigateToScreen(progPrevScreen);
}

function progAction(nume) {
  if (nume === "Cote TVA") {
    openTVAEditor();
    return;
  }
  if (nume === "Moduri preparare") {
    openMesajeEditor();
    return;
  }
  if (nume === "Ospatari") {
    openOspEditor();
    return;
  }
  if (nume === "Imprimante sectii") {
    openProgImprimante();
    return;
  }
  if (nume === "Forme de plata") {
    openFpEditor();
    return;
  }
  if (nume === "Programare mese") {
    openProgMese();
    return;
  }
  if (nume === "Grupe") {
    openProgGrupe();
    return;
  }
  if (nume === "Produse") {
    openProgProduse();
    return;
  }
  if (nume === "Sectii") {
    openSectEditor();
    return;
  }
  if (nume === "Setari") {
    openProgSetari();
    return;
  }
  if (nume === "Antet") {
    openProgAntet();
    return;
  }
  if (nume === "Nota") {
    openProgNota();
    return;
  }
  if (nume === "Conectare server") {
    openProgConectare();
    return;
  }
  if (nume === "Parole") {
    openProgParole();
    return;
  }
  if (nume === "Sincronizare produse") {
    syncProducts();
    return;
  }
  if (nume === "Update") {
    runUpdate();
    return;
  }
  showToast("Programare: " + nume);
}

// --------------------------------------------------------------------------
// SINCRONIZARE PRODUSE: ruleaza procedura ImportProd (sursa din tblConectare)
// --------------------------------------------------------------------------
async function syncProducts() {
  try {
    // 1. Verificam mai intai daca se poate sincroniza (raportul Z efectuat)
    const chkResp = await fetch("api/sync_products.php?check=1");
    const chk = await chkResp.json();
    if (chk.status !== "success") throw new Error(chk.message);
    if (!chk.allowed) {
      appAlert(chk.message);
      return;
    }

    // 2. Doar daca este permis, cerem confirmarea
    appConfirm(
      "Sincronizarea inlocuieste produsele si grupele din sursa externa. Continuati?",
      () => doSyncProducts(),
      "Da",
      "Nu"
    );
  } catch (err) {
    appAlert("Eroare sincronizare produse: " + err.message);
  }
}

async function doSyncProducts() {
  try {
    showToast("Sincronizare produse... va rugam asteptati");
    const resp = await fetch("api/sync_products.php", { method: "POST" });
    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message);

    showToast(res.message);
    await loadMenu();
  } catch (err) {
    appAlert("Eroare sincronizare produse: " + err.message);
  }
}

// --------------------------------------------------------------------------
// UPDATE: descarca ultima versiune din GitHub (api/update.php)
// Fisierele de configurare locale (config.json, db.local.php) nu sunt atinse.
// --------------------------------------------------------------------------
// Fetch JSON fara cache, cu eroare explicita daca serverul nu intoarce JSON
// (ex. 404/500 HTML), ca sa nu apara "JSON.parse: unexpected character".
async function fetchJsonNoStore(url, options) {
  const resp = await fetch(url, Object.assign({ cache: "no-store" }, options || {}));
  const text = await resp.text();
  let data = null;
  try {
    data = JSON.parse(text);
  } catch (e) {
    const snippet = text.replace(/\s+/g, " ").trim().slice(0, 140);
    throw new Error("HTTP " + resp.status + (snippet ? " - " + snippet : " - raspuns gol"));
  }
  if (data.status !== "success") {
    throw new Error(data.message || ("HTTP " + resp.status));
  }
  return data;
}

async function runUpdate() {
  try {
    showToast("Verific versiunea disponibila...");
    const data = await fetchJsonNoStore("api/update.php?check=1");

    const curent = data.label ? (tr("Versiune curenta: ") + data.label + ".\n") : "";

    if (!data.updateAvailable) {
      appAlert(curent + tr("Ai deja ultima versiune (") + data.latest + ").");
      return;
    }

    appConfirm(
      curent + tr("Exista o versiune noua ({v}). Configurarile locale nu sunt afectate. Actualizati acum?", { v: data.latest }),
      () => promptUpdateParola(),
      "Da",
      "Nu"
    );
  } catch (err) {
    appAlert("Eroare verificare update: " + err.message);
  }
}

function promptUpdateParola() {
  openNumericPrompt("PAROLA UPDATE", async (parola) => {
    try {
      showToast("Se descarca si se instaleaza actualizarea...");
      const res = await fetchJsonNoStore("api/update.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "apply", parola: parola })
      });
      appAlert(res.message, () => location.reload());
    } catch (err) {
      appAlert("Eroare update: " + err.message);
    }
  });
}

// --------------------------------------------------------------------------
// ROLLBACK: revenire la o versiune anterioara (punct local sau SHA GitHub)
// --------------------------------------------------------------------------
let rollbackSelected = null;
let rollbackParola = "";

function openRollback() {
  rollbackSelected = null;
  rollbackParola = "";
  // Parola se cere PRIMA, imediat ce se apasa butonul Rollback.
  openNumericPrompt("PAROLA UPDATE", (parola) => {
    if (!parola) {
      appAlert("Introduceti parola de update.");
      return;
    }
    rollbackParola = parola;
    const modal = document.getElementById("modal-rollback");
    if (modal) modal.classList.add("active");
    reloadRollback();
  });
}

function closeRollback() {
  const modal = document.getElementById("modal-rollback");
  if (modal) modal.classList.remove("active");
}

function rollbackLocalLabel(e) {
  const parts = [];
  if (e.version) parts.push("v" + e.version);
  if (e.short) parts.push(e.short);
  if (e.build) parts.push("build " + e.build);
  if (e.createdAt) parts.push(e.createdAt);
  return parts.join(" · ") || (e.id || "punct de restaurare");
}

async function reloadRollback() {
  const list = document.getElementById("rollback-list");
  if (!list) return;
  rollbackSelected = null;
  list.innerHTML = '<div class="setari-empty">Se incarca...</div>';
  try {
    const data = await fetchJsonNoStore("api/update.php?list=1");
    renderRollbackList(data);
  } catch (err) {
    list.innerHTML = '<div class="setari-empty">Eroare: ' + escapeHtml(err.message) + "</div>";
  }
}

function renderRollbackList(data) {
  const list = document.getElementById("rollback-list");
  if (!list) return;
  list.innerHTML = "";
  rollbackSelected = null;

  const cur = data.current || "";

  const mkSection = (title) => {
    const h = document.createElement("div");
    h.className = "rollback-section-title";
    h.textContent = title;
    list.appendChild(h);
  };

  const mkItem = (isCurrent, label, onClick) => {
    const it = document.createElement("div");
    it.className = "rollback-item" + (isCurrent ? " current" : "");
    it.innerHTML = escapeHtml(label) + (isCurrent ? ' <span class="rollback-current-tag">(curenta)</span>' : "");
    if (!isCurrent) {
      it.onclick = () => {
        rollbackSelected = null;
        onClick();
        list.querySelectorAll(".rollback-item").forEach(x => x.classList.remove("selected"));
        it.classList.add("selected");
      };
    }
    list.appendChild(it);
  };

  if ((data.local || []).length) {
    mkSection("Puncte de restaurare (acest calculator)");
    data.local.forEach(e => {
      const isCur = !!e.short && e.short === cur;
      mkItem(isCur, rollbackLocalLabel(e), () => {
        rollbackSelected = { type: "local", id: e.id, label: rollbackLocalLabel(e) };
      });
    });
  }

  if ((data.github || []).length) {
    mkSection("Versiuni din istoric (GitHub)");
    data.github.forEach(c => {
      // Tag-ul de release (ex. "v2.1.1"), cand exista, e primul in eticheta.
      const tag = String(c.tag || "").trim();
      const label = [tag, c.short, c.date, c.message].filter(Boolean).join(" · ");
      const isCur = !!c.short && c.short === cur;
      mkItem(isCur, label, () => {
        rollbackSelected = {
          type: "github",
          sha: c.sha,
          label: tag ? (tag + " · " + (c.short || "")) : ("v " + (c.short || ""))
        };
      });
    });
  }

  if (!list.children.length) {
    list.innerHTML = '<div class="setari-empty">Nu exista puncte de restaurare (si istoricul GitHub nu este disponibil).</div>';
  }
}

function applyRollback() {
  if (!rollbackSelected) {
    appAlert("Selectati un punct de restaurare sau o versiune.");
    return;
  }
  appConfirm(
    tr("Reveniti la versiunea: ") + rollbackSelected.label + tr("? Configurarile locale nu sunt afectate."),
    async () => {
      try {
        showToast("Se aplica rollback...");
        const body = { action: "rollback", parola: rollbackParola };
        if (rollbackSelected.type === "local") {
          body.id = rollbackSelected.id;
        } else {
          body.sha = rollbackSelected.sha;
        }
        const res = await fetchJsonNoStore("api/update.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(body)
        });
        appAlert(res.message, () => location.reload());
      } catch (err) {
        appAlert("Eroare rollback: " + err.message);
      }
    },
    "Da",
    "Nu"
  );
}

// --------------------------------------------------------------------------
// SETARI (tblSet): taburi General / Device-uri / Imprimante / Altele
// Doar coloana Value este editabila; Setting si Descriere sunt doar afisate.
// --------------------------------------------------------------------------
const SETARI_TABS = [
  { id: "general",    label: "setari.tab.general",   groups: ["General", "Fonturi", "Discount", "Securitate"] },
  { id: "device",     label: "setari.tab.device",    groups: ["Casa", "Afiseaj", "Cantar", "PoSbanca"] },
  { id: "integrare",  label: "setari.tab.integrare", groups: ["Integrare"], special: true },
  { id: "servicii",   label: "setari.tab.servicii",  groups: [], special: true },
  { id: "altele",     label: "setari.tab.altele",    groups: null } // null = toate cele neincluse mai sus
];

let setariRows = [];
// Lista protocoalelor POS bancar, primita de la server (api/setari.php).
// Fallback-ul acopera doar prima randare, inainte de raspunsul API.
let setariPosBancaProtocols = [
  { value: "SmartPayECR", label: "SmartPay ECR Link" }
];
let setariActiveTab = "general";
let setariDirty = {};
// In modul Restaurant, NrZecCant e editabil doar cand toate mesele sunt inchise.
let setariCanEditNrZecCant = true;
// Starea cheii API (tabul Integrare): exista cheie + ultima utilizare.
let setariApiKeySet = false;
let setariApiLastUsed = null;
// IP-ul clientului care a deschis Setarile (pentru editorul whitelist-ului).
let setariClientIp = "";
let setariClientIpLocal = false;
// Cheia nou generata, afisata o singura data (nu vine niciodata din server).
let setariNewApiKey = null;

// Confirmare in-app (nu depinde de dialogul nativ confirm(), care poate fi
// suprimat de browser). Fallback pe confirm() daca modalul nu exista.
let _appConfirmCb = null;

function appConfirm(message, onYes, yesLabel, noLabel, onNo) {
  message = tr(message);
  const modal = document.getElementById("modal-confirm");
  if (!modal) {
    if (window.confirm(message)) {
      if (typeof onYes === "function") onYes();
    } else if (typeof onNo === "function") {
      onNo();
    }
    return;
  }
  document.getElementById("confirm-message").textContent = message;
  const yes = document.getElementById("confirm-yes");
  const no = document.getElementById("confirm-no");
  yes.textContent = tr(yesLabel || "Da");
  no.textContent = tr(noLabel || "Nu");
  _appConfirmCb = (typeof onYes === "function") ? onYes : null;
  const noCb = (typeof onNo === "function") ? onNo : null;

  yes.onclick = () => {
    const cb = _appConfirmCb;
    closeAppConfirm();
    if (cb) cb();
  };
  no.onclick = () => {
    closeAppConfirm();
    if (noCb) noCb();
  };

  modal.classList.add("active");
}

function closeAppConfirm() {
  const modal = document.getElementById("modal-confirm");
  if (modal) modal.classList.remove("active");
  _appConfirmCb = null;
}

// Mesaj in-app (nu depinde de alert() nativ, care poate fi suprimat de browser)
function appAlert(message, onOk) {
  message = tr(message);
  const modal = document.getElementById("modal-alert");
  if (!modal) {
    window.alert(message);
    if (typeof onOk === "function") onOk();
    return;
  }
  document.getElementById("alert-message").textContent = message;
  const ok = document.getElementById("alert-ok");
  ok.onclick = () => {
    closeAppAlert();
    if (typeof onOk === "function") onOk();
  };
  modal.classList.add("active");
}

function appAlertHtml(html, onOk) {
  const modal = document.getElementById("modal-alert");
  if (!modal) {
    window.alert(String(html).replace(/<[^>]*>/g, ""));
    if (typeof onOk === "function") onOk();
    return;
  }
  document.getElementById("alert-message").innerHTML = html;
  const ok = document.getElementById("alert-ok");
  ok.onclick = () => {
    closeAppAlert();
    if (typeof onOk === "function") onOk();
  };
  modal.classList.add("active");
}

function closeAppAlert() {
  const modal = document.getElementById("modal-alert");
  if (modal) modal.classList.remove("active");
}

function openProgSetari() {
  setariDirty = {};
  setariActiveTab = "general";
  navigateToScreen("screen-prog-setari");
  loadSetari();
}

function closeProgSetari() {
  if (Object.keys(setariDirty).length > 0) {
    appConfirm(
      "Aveti setari nesalvate. Renuntati la modificari si parasiti ecranul Setari?",
      () => {
        setariDirty = {};
        navigateToScreen("screen-programare");
      },
      "Renunta",
      "Continua"
    );
    return;
  }
  navigateToScreen("screen-programare");
}

// Setari tblSet ascunse in ecranul Setari (legacy / fara efect).
const SETARI_ASCUNSE = new Set(["start", "nrboncmd", "paradiscount", "storno_parola", "retea", "apikeyhash"]);

async function loadSetari() {
  try {
    const resp = await fetch("api/setari.php");
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message || "Eroare");
    setariRows = (data.rows || []).filter(r => !SETARI_ASCUNSE.has((r.Setting || "").trim().toLowerCase()));
    if (Array.isArray(data.posBancaProtocols) && data.posBancaProtocols.length > 0) {
      setariPosBancaProtocols = data.posBancaProtocols;
    }
    // Restrictia pentru NrZecCant (Restaurant + mese deschise) vine de la server.
    setariCanEditNrZecCant = (data.canEditNrZecCant !== false);
    // Starea cheii API pentru tabul Integrare.
    setariApiKeySet = (data.apiKeySet === true);
    setariApiLastUsed = data.apiLastUsed || null;
    // IP-ul clientului curent (afisat in editorul whitelist-ului de IP-uri).
    setariClientIp = String(data.clientIp || "");
    setariClientIpLocal = (data.clientIpLocal === true);
    renderSetariTabs();
    renderSetariTab(setariActiveTab);
  } catch (err) {
    showToast("Eroare setari: " + err.message);
  }
}

function setariNorm(s) {
  return (s || "").trim().toLowerCase();
}

function renderSetariTabs() {
  const wrap = document.getElementById("setari-tabs");
  if (!wrap) return;
  wrap.innerHTML = "";
  SETARI_TABS.forEach(t => {
    const b = document.createElement("button");
    b.className = "setari-tab" + (t.id === setariActiveTab ? " active" : "");
    b.textContent = (typeof window.t === "function") ? window.t(t.label) : t.label;
    b.onclick = () => {
      setariActiveTab = t.id;
      renderSetariTabs();
      renderSetariTab(t.id);
    };
    wrap.appendChild(b);
  });
}

// Fonturile oferite in combobox-ul din Setari > General > Fonturi. Lista este
// fixa (fonturi sigure, prezente pe orice statie Windows) ca sa nu difere de la
// o masina la alta; daca in baza de date exista o alta valoare, aceasta este
// adaugata in lista ca optiune suplimentara.
const SETARI_FONTS = [
  "Arial",
  "Segoe UI",
  "Tahoma",
  "Verdana",
  "Calibri",
  "Georgia",
  "Times New Roman",
  "Trebuchet MS",
  "Courier New",
  "Comic Sans MS",
  "Impact",
  "Lucida Console"
];

// Ordinea explicita a setarilor in cadrul unui grup (dupa tblSet.Setting).
// Cele care nu apar in lista raman dupa ele, in ordinea primita de la server.
const SETARI_ORDINE = {
  "general": ["Limba", "NrLogic", "MagID", "TipVanz", "Server", "MarcareMobil"],
  "fonturi": ["Font", "Size"],
  "discount": ["RED", "TipDiscount", "ProcentDiscountFix", "MotivDiscount"],
  "casa": ["TipCasaMarcat", "AsteaptaRaspunsCasa", "AsteptareCasaSec", "EvidDiscBonFisc", "EvidStornoBonFisc", "CaleFisierComenziECR", "CaleFisierRaspunsECR"],
  "afiseaj": ["AfiseajClient", "PortComAfiseaj", "BaudRateAfisaj", "CaleDriverAfiseajClient", "CaleFisierComenziAfiseajClient"],
  "cantar": ["Cantar", "CaleDriverCantar", "CaleFisierRaspunsCantar", "Delay_cantar"],
  "posbanca": ["PoSbanca", "ProtocolPoSbanca", "CaleDriverPoSbanca", "CaleFisierRaspunsPoSbanca"],
  "securitate": ["IpPermise"]
};

// Etichete prietenoase pentru grupuri (altfel se afiseaza numele brut din tblSet.Grup).
const SETARI_GRUP_LABELS = {
  "discount": "setari.grup.discount",
  "fonturi": "setari.grup.fonturi",
  "casa": "setari.grup.casa",
  "posbanca": "setari.grup.posbanca",
  "afiseaj": "setari.grup.afiseaj",
  "cantar": "setari.grup.cantar",
  "integrare": "setari.grup.integrare",
  "securitate": "setari.grup.securitate"
};

function setariSortRows(grup, rows) {
  const ord = SETARI_ORDINE[setariNorm(grup)];
  if (!ord || ord.length === 0) return rows;
  const idx = r => {
    const i = ord.findIndex(k => setariNorm(k) === setariNorm(r.Setting));
    return i === -1 ? ord.length : i;
  };
  return rows.slice().sort((a, b) => idx(a) - idx(b));
}

// Grupurile (din tblSet.Grup) afisate in tabul curent
function setariGroupsForTab(tabId) {
  const tab = SETARI_TABS.find(t => t.id === tabId);

  if (tab && tab.groups) {
    return tab.groups
      .map(g => {
        const lk = SETARI_GRUP_LABELS[setariNorm(g)];
        return {
          label: lk ? ((typeof window.t === "function") ? window.t(lk) : g) : g,
          rows: setariSortRows(g, setariRows.filter(r => setariNorm(r.Grup) === setariNorm(g)))
        };
      })
      .filter(grp => grp.rows.length > 0);
  }

  // Altele: tot ce nu apartine grupurilor explicite
  const used = new Set();
  SETARI_TABS.forEach(t => { if (t.groups) t.groups.forEach(g => used.add(setariNorm(g))); });
  return [{ label: "", rows: setariRows.filter(r => !used.has(setariNorm(r.Grup))) }];
}

function setariField(row) {
  // Limba interfetei: lista Romana / Engleza.
  if (row.Setting === "Limba") {
    return setariLimbaField(row);
  }
  // Fontul implicit al panoului din dreapta: combobox cu fonturi sigure.
  if (row.Setting === "Font") {
    return setariFontField(row);
  }
  // Tipul de vanzare se editeaza ca lista (Restaurant / FastFood), nu ca text.
  if (row.Setting === "TipVanz") {
    return setariTipVanzField(row);
  }
  // Numarul de zecimale pentru cantitate: lista 0 / 1 / 2.
  if (row.Setting === "NrZecCant") {
    return setariNrZecCantField(row);
  }
  // Discountul permis/interzis: lista DA / NU.
  if (row.Setting === "RED") {
    return setariRedField(row);
  }
  // Tipul discountului: lista 0 / 1 / 2.
  if (row.Setting === "TipDiscount") {
    return setariTipDiscountField(row);
  }
  // Transferul de produse permis/interzis: lista DA / NU.
  if (row.Setting === "TransferMasa") {
    return setariTransferMasaField(row);
  }
  // Asteptarea raspunsului casei de marcat: lista DA / NU (implicit NU).
  if (row.Setting === "AsteaptaRaspunsCasa") {
    return setariDaNuField(row.Setting, row.Value, row.Descriere, true);
  }
  // Tipul casei de marcat fiscale: lista FiscalWire / FiscalNet / Fisco / fprwin.
  if (row.Setting === "TipCasaMarcat") {
    return setariTipCasaMarcatField(row);
  }
  // Discount / stornare pe bonul fiscal: liste DA / NU (implicit NU).
  if (row.Setting === "EvidDiscBonFisc" || row.Setting === "EvidStornoBonFisc") {
    return setariEvidBonFiscField(row);
  }
  // Afisajul client activ/inactiv: lista DA / NU.
  if (row.Setting === "AfiseajClient") {
    return setariAfiseajClientField(row);
  }
  // Cantarul electronic activ/inactiv: lista DA / NU.
  if (row.Setting === "Cantar") {
    return setariCantarField(row);
  }
  // Meniul zilei activ/inactiv: lista DA / NU.
  if (row.Setting === "MeniulZilei") {
    return setariMeniulZileiField(row);
  }
  // POS bancar activ/inactiv: lista DA / NU.
  if (row.Setting === "PoSbanca") {
    return setariPoSbancaField(row);
  }
  // Retiparirea ultimei chitante a terminalului bancar: lista DA / NU (implicit NU).
  if (row.Setting === "RetiparireChitantaPoSbanca") {
    return setariDaNuField(row.Setting, row.Value, row.Descriere, true);
  }
  // Protocolul terminalului bancar: SmartPay ECR Link.
  if (row.Setting === "ProtocolPoSbanca") {
    return setariProtocolPoSbancaField(row);
  }
  // Plata combinata activ/inactiv: lista DA / NU (implicit DA).
  if (row.Setting === "PlataCombinata") {
    return setariDaNuField(row.Setting, row.Value, row.Descriere, false);
  }
  // Marcarea de pe tableta/telefon activa/inactiva: lista DA / NU (implicit NU).
  if (row.Setting === "MarcareMobil") {
    return setariDaNuField(row.Setting, row.Value, row.Descriere, true);
  }
  // Whitelist de IP-uri: editor cu chips-uri, cu localhost blocat.
  if (row.Setting === "IpPermise") {
    return setariIpWhitelistField(row);
  }
  // Export/sincronizare cu serverul extern: lista DA / NU (implicit NU).
  if (row.Setting === "Server") {
    return setariDaNuField(row.Setting, row.Value, row.Descriere, true);
  }
  // API public pentru aplicatii terte: lista DA / NU (implicit NU).
  if (row.Setting === "ApiEnabled") {
    return setariDaNuField(row.Setting, row.Value, row.Descriere, true);
  }

  const div = document.createElement("div");
  div.className = "setari-field";

  const label = document.createElement("label");
  label.className = "setari-label";
  label.textContent = row.Setting;
  div.appendChild(label);

  const fieldRow = document.createElement("div");
  fieldRow.className = "setari-field-row";

  const input = document.createElement("input");
  input.type = "text";
  input.className = "tva-input setari-input";
  input.id = "setari-inp-" + row.Setting;
  input.value = (row.Value === null || row.Value === undefined) ? "" : String(row.Value);
  input.oninput = () => { setariDirty[row.Setting] = input.value; };
  fieldRow.appendChild(input);

  // Tastatura: numpad daca valoarea curenta este numerica, altfel alfanumerica
  const isNum = /^-?\d+([.,]\d+)?$/.test(input.value.trim());
  const kb = document.createElement("button");
  kb.className = "act-btn fkb-mini";
  if (isNum) {
    kb.textContent = "123";
    kb.onclick = () => openNumericKeyboardFor(input.id, row.Setting);
  } else {
    kb.textContent = "⌨️";
    kb.onclick = () => openFloatingKeyboardFor(input.id);
  }
  fieldRow.appendChild(kb);

  div.appendChild(fieldRow);

  if (row.Descriere && String(row.Descriere).trim() !== "") {
    const d = document.createElement("div");
    d.className = "setari-desc";
    d.textContent = row.Descriere;
    div.appendChild(d);
  }

  return div;
}

// Combobox pentru tblSet.Limba: ro = Romana, en = Engleza.
function setariLimbaField(row) {
  const div = document.createElement("div");
  div.className = "setari-field";

  const label = document.createElement("label");
  label.className = "setari-label";
  label.textContent = (typeof window.t === "function") ? window.t("setari.limba") : row.Setting;
  div.appendChild(label);

  const select = document.createElement("select");
  select.className = "tva-input setari-input";
  select.id = "setari-inp-" + row.Setting;

  const cur = (String(row.Value === null || row.Value === undefined ? "" : row.Value).trim().toLowerCase() === "en") ? "en" : "ro";
  const lblRo = (typeof window.t === "function") ? window.t("setari.limba.ro") : "Romana";
  const lblEn = (typeof window.t === "function") ? window.t("setari.limba.en") : "Engleza";
  [["ro", lblRo], ["en", lblEn]].forEach(pair => {
    const opt = document.createElement("option");
    opt.value = pair[0];
    opt.textContent = tr(pair[1]);
    if (pair[0] === cur) opt.selected = true;
    select.appendChild(opt);
  });
  select.onchange = () => { setariDirty[row.Setting] = select.value; };
  div.appendChild(select);

  if (row.Descriere && String(row.Descriere).trim() !== "") {
    const d = document.createElement("div");
    d.className = "setari-desc";
    d.textContent = row.Descriere;
    div.appendChild(d);
  }

  return div;
}

// Combobox pentru tblSet.Font: fontul implicit al panoului din dreapta
// (grupe/produse). O valoare existenta care nu e in lista este pastrata ca
// optiune suplimentara, ca sa nu se piarda la salvare.
function setariFontField(row) {
  const div = document.createElement("div");
  div.className = "setari-field";

  const label = document.createElement("label");
  label.className = "setari-label";
  label.textContent = row.Setting;
  div.appendChild(label);

  const select = document.createElement("select");
  select.className = "tva-input setari-input";
  select.id = "setari-inp-" + row.Setting;

  const cur = String(row.Value === null || row.Value === undefined ? "" : row.Value).trim() || "Arial";
  const fonts = SETARI_FONTS.slice();
  if (fonts.indexOf(cur) === -1) fonts.unshift(cur);
  fonts.forEach(f => {
    const opt = document.createElement("option");
    opt.value = f;
    opt.textContent = f;
    if (f === cur) opt.selected = true;
    select.appendChild(opt);
  });
  select.onchange = () => { setariDirty[row.Setting] = select.value; };
  div.appendChild(select);

  if (row.Descriere && String(row.Descriere).trim() !== "") {
    const d = document.createElement("div");
    d.className = "setari-desc";
    d.textContent = row.Descriere;
    div.appendChild(d);
  }

  return div;
}

// Combobox pentru tblSet.TipVanz: 0 = Restaurant, 1 = FastFood.
function setariTipVanzField(row) {
  const div = document.createElement("div");
  div.className = "setari-field";

  const label = document.createElement("label");
  label.className = "setari-label";
  label.textContent = row.Setting;
  div.appendChild(label);

  const select = document.createElement("select");
  select.className = "tva-input setari-input";
  select.id = "setari-inp-" + row.Setting;

  const cur = (String(row.Value === null || row.Value === undefined ? "" : row.Value).trim() === "1") ? "1" : "0";
  [["0", "Restaurant"], ["1", "FastFood"]].forEach(pair => {
    const opt = document.createElement("option");
    opt.value = pair[0];
    opt.textContent = tr(pair[1]);
    if (pair[0] === cur) opt.selected = true;
    select.appendChild(opt);
  });
  select.onchange = () => { setariDirty[row.Setting] = select.value; };
  div.appendChild(select);

  if (row.Descriere && String(row.Descriere).trim() !== "") {
    const d = document.createElement("div");
    d.className = "setari-desc";
    d.textContent = row.Descriere;
    div.appendChild(d);
  }

  return div;
}

// Combobox pentru tblSet.NrZecCant: 0 / 1 / 2 zecimale la cantitate.
function setariNrZecCantField(row) {
  const div = document.createElement("div");
  div.className = "setari-field";

  const label = document.createElement("label");
  label.className = "setari-label";
  label.textContent = row.Setting;
  div.appendChild(label);

  const select = document.createElement("select");
  select.className = "tva-input setari-input";
  select.id = "setari-inp-" + row.Setting;

  const cur = String(row.Value === null || row.Value === undefined ? "" : row.Value).trim();
  [["0", "0 zecimale (numere intregi)"], ["1", "1 zecimala"], ["2", "2 zecimale"]].forEach(pair => {
    const opt = document.createElement("option");
    opt.value = pair[0];
    opt.textContent = tr(pair[1]);
    if (pair[0] === (cur === "1" || cur === "2" ? cur : "0")) opt.selected = true;
    select.appendChild(opt);
  });
  select.onchange = () => { setariDirty[row.Setting] = select.value; };

  // In modul Restaurant, setarea e blocata cat timp exista mese deschise.
  if (!setariCanEditNrZecCant) {
    select.disabled = true;
    select.title = "Toate mesele trebuie sa fie inchise pentru a modifica zecimalele.";
  }
  div.appendChild(select);

  if (!setariCanEditNrZecCant) {
    const lock = document.createElement("div");
    lock.className = "setari-desc";
    lock.style.color = "#b00";
    lock.style.fontWeight = "bold";
    lock.textContent = tr("Toate mesele trebuie sa fie inchise pentru a modifica zecimalele cantitatii.");
    div.appendChild(lock);
  }

  if (row.Descriere && String(row.Descriere).trim() !== "") {
    const d = document.createElement("div");
    d.className = "setari-desc";
    d.textContent = row.Descriere;
    div.appendChild(d);
  }

  return div;
}

// Combobox pentru tblSet.RED: 1 = DA (discount permis), 0 = NU (ascuns).
function setariRedField(row) {
  const div = document.createElement("div");
  div.className = "setari-field";

  const label = document.createElement("label");
  label.className = "setari-label";
  label.textContent = row.Setting;
  div.appendChild(label);

  const select = document.createElement("select");
  select.className = "tva-input setari-input";
  select.id = "setari-inp-" + row.Setting;

  const cur = String(row.Value === null || row.Value === undefined ? "" : row.Value).trim();
  [["1", "DA"], ["0", "NU"]].forEach(pair => {
    const opt = document.createElement("option");
    opt.value = pair[0];
    opt.textContent = tr(pair[1]);
    if (pair[0] === (cur === "0" ? "0" : "1")) opt.selected = true;
    select.appendChild(opt);
  });
  select.onchange = () => { setariDirty[row.Setting] = select.value; applyRedFieldsUI(); };
  div.appendChild(select);

  if (row.Descriere && String(row.Descriere).trim() !== "") {
    const d = document.createElement("div");
    d.className = "setari-desc";
    d.textContent = row.Descriere;
    div.appendChild(d);
  }

  return div;
}

// Combobox pentru tblSet.TipDiscount: 0 = complet, 1 = procent la subtotal,
// 2 = procent fix la subtotal.
function setariTipDiscountField(row) {
  const div = document.createElement("div");
  div.className = "setari-field";

  const label = document.createElement("label");
  label.className = "setari-label";
  label.textContent = row.Setting;
  div.appendChild(label);

  const select = document.createElement("select");
  select.className = "tva-input setari-input";
  select.id = "setari-inp-" + row.Setting;

  const cur = String(row.Value === null || row.Value === undefined ? "" : row.Value).trim();
  [
    ["0", "0 - Complet (produs/subtotal, procent/valoare)"],
    ["1", "1 - Doar procent la subtotal (popup)"],
    ["2", "2 - Procent fix la subtotal (fara popup)"]
  ].forEach(pair => {
    const opt = document.createElement("option");
    opt.value = pair[0];
    opt.textContent = tr(pair[1]);
    if (pair[0] === (cur === "1" || cur === "2" ? cur : "0")) opt.selected = true;
    select.appendChild(opt);
  });
  select.onchange = () => { setariDirty[row.Setting] = select.value; };
  div.appendChild(select);

  if (row.Descriere && String(row.Descriere).trim() !== "") {
    const d = document.createElement("div");
    d.className = "setari-desc";
    d.textContent = row.Descriere;
    div.appendChild(d);
  }

  return div;
}

// Combobox pentru tblSet.TransferMasa: 1 = DA (transfer permis), 0 = NU.
function setariTransferMasaField(row) {
  const div = document.createElement("div");
  div.className = "setari-field";

  const label = document.createElement("label");
  label.className = "setari-label";
  label.textContent = row.Setting;
  div.appendChild(label);

  const select = document.createElement("select");
  select.className = "tva-input setari-input";
  select.id = "setari-inp-" + row.Setting;

  const cur = String(row.Value === null || row.Value === undefined ? "" : row.Value).trim();
  [["1", "DA"], ["0", "NU"]].forEach(pair => {
    const opt = document.createElement("option");
    opt.value = pair[0];
    opt.textContent = tr(pair[1]);
    if (pair[0] === (cur === "0" ? "0" : "1")) opt.selected = true;
    select.appendChild(opt);
  });
  select.onchange = () => { setariDirty[row.Setting] = select.value; };
  div.appendChild(select);

  if (row.Descriere && String(row.Descriere).trim() !== "") {
    const d = document.createElement("div");
    d.className = "setari-desc";
    d.textContent = row.Descriere;
    div.appendChild(d);
  }

  return div;
}

// Combobox pentru tblSet.TipCasaMarcat: modelul casei de marcat fiscale.
function setariTipCasaMarcatField(row) {
  const div = document.createElement("div");
  div.className = "setari-field";

  const label = document.createElement("label");
  label.className = "setari-label";
  label.textContent = row.Setting;
  div.appendChild(label);

  const select = document.createElement("select");
  select.className = "tva-input setari-input";
  select.id = "setari-inp-" + row.Setting;

  const opts = ["FiscalWire", "FiscalNet", "Fisco", "fprwin"];
  const cur = String(row.Value === null || row.Value === undefined ? "" : row.Value).trim();
  opts.forEach(val => {
    const opt = document.createElement("option");
    opt.value = val;
    opt.textContent = val;
    if (val === (opts.includes(cur) ? cur : "FiscalWire")) opt.selected = true;
    select.appendChild(opt);
  });
  select.onchange = () => { setariDirty[row.Setting] = select.value; };
  div.appendChild(select);

  if (row.Descriere && String(row.Descriere).trim() !== "") {
    const d = document.createElement("div");
    d.className = "setari-desc";
    d.textContent = row.Descriere;
    div.appendChild(d);
  }

  return div;
}

// Combobox pentru tblSet.EvidDiscBonFisc / EvidStornoBonFisc: 1 = DA
// (discount, respectiv stornare, pe bonul fiscal), 0 = NU (implicit).
function setariEvidBonFiscField(row) {
  return setariDaNuField(row.Setting, row.Value, row.Descriere, true);
}

// Combobox pentru tblSet.AfiseajClient: 1 = DA (afisaj activ), 0 = NU.
function setariAfiseajClientField(row) {
  return setariDaNuField(row.Setting, row.Value, row.Descriere, false);
}

// Combobox pentru tblSet.Cantar: 1 = DA (cantar activ), 0 = NU.
function setariCantarField(row) {
  return setariDaNuField(row.Setting, row.Value, row.Descriere, true);
}

// Combobox pentru tblSet.MeniulZilei: 1 = DA (meniul zilei activ), 0 = NU.
function setariMeniulZileiField(row) {
  return setariDaNuField(row.Setting, row.Value, row.Descriere, false);
}

// Combobox pentru tblSet.PoSbanca: 1 = DA (POS bancar activ), 0 = NU.
function setariPoSbancaField(row) {
  return setariDaNuField(row.Setting, row.Value, row.Descriere, false);
}

// Combobox pentru tblSet.ProtocolPoSbanca. Lista vine de la server, ca sa fie
// aceeasi sursa de adevar ca validarea din api/setari.php.
function setariProtocolPoSbancaField(row) {
  const div = document.createElement("div");
  div.className = "setari-field";

  const label = document.createElement("label");
  label.className = "setari-label";
  label.textContent = row.Setting;
  div.appendChild(label);

  const select = document.createElement("select");
  select.className = "tva-input setari-input";
  select.id = "setari-inp-" + row.Setting;

  const cur = String(row.Value === null || row.Value === undefined ? "" : row.Value).trim()
    || "SmartPayECR";
  const options = Array.isArray(setariPosBancaProtocols)
    ? setariPosBancaProtocols.slice()
    : [];
  // Lista de drivere este inchisa: o valoare veche/necunoscuta (ex. "Generic")
  // nu se mai afiseaza ca optiune, ci se cade pe primul driver suportat.
  const known = options.some(o => String(o.value) === cur);
  const selected = known ? cur : String((options[0] || {}).value || "SmartPayECR");
  options.forEach(o => {
    const opt = document.createElement("option");
    opt.value = String(o.value);
    opt.textContent = tr(String(o.label || o.value));
    if (String(o.value) === selected) { opt.selected = true; }
    select.appendChild(opt);
  });
  select.onchange = () => { setariDirty[row.Setting] = select.value; };
  div.appendChild(select);

  if (row.Descriere && String(row.Descriere).trim() !== "") {
    const d = document.createElement("div");
    d.className = "setari-desc";
    d.textContent = row.Descriere;
    div.appendChild(d);
  }

  return div;
}

// Construieste un combobox generic DA / NU. defaultToNu = true cand valoarea
// lipsa/legacy trebuie interpretata ca NU (ex. Cantar avea valoarea "None").
function setariDaNuField(setting, value, descriere, defaultToNu) {
  const div = document.createElement("div");
  div.className = "setari-field";

  const label = document.createElement("label");
  label.className = "setari-label";
  label.textContent = setting;
  div.appendChild(label);

  const select = document.createElement("select");
  select.className = "tva-input setari-input";
  select.id = "setari-inp-" + setting;

  const cur = String(value === null || value === undefined ? "" : value).trim();
  const fallback = defaultToNu ? "0" : "1";
  const selected = (cur === "0") ? "0" : ((cur === "1") ? "1" : fallback);
  [["1", "DA"], ["0", "NU"]].forEach(pair => {
    const opt = document.createElement("option");
    opt.value = pair[0];
    opt.textContent = tr(pair[1]);
    if (pair[0] === (cur === "0" ? "0" : "1")) opt.selected = true;
    select.appendChild(opt);
  });
  select.onchange = () => { setariDirty[setting] = select.value; };
  div.appendChild(select);

  if (descriere && String(descriere).trim() !== "") {
    const d = document.createElement("div");
    d.className = "setari-desc";
    d.textContent = descriere;
    div.appendChild(d);
  }

  return div;
}

// --------------------------------------------------------------------------
// Whitelist de IP-uri (tblSet.IpPermise)
// Localhost (127.0.0.1 / ::1) este mereu prezent si nu poate fi sters; lista
// goala sau doar localhost = doar localhost are acces (verificat pe server).
// --------------------------------------------------------------------------
const IPW_LOCAL = ["127.0.0.1", "::1"];

function ipwIsLocal(entry) {
  const e = String(entry == null ? "" : entry).trim().toLowerCase();
  return e === "127.0.0.1" || e === "::1" || e === "::ffff:127.0.0.1";
}

// Normalizeaza o intrare introdusa de utilizator (localhost -> 127.0.0.1).
function ipwNormalizeEntry(entry) {
  const e = String(entry == null ? "" : entry).trim();
  if (/^localhost$/i.test(e)) return "127.0.0.1";
  return e;
}

// Lista afisata: localhost este mereu prezent, duplicatele sunt eliminate.
function ipwParseList(value) {
  const out = IPW_LOCAL.slice();
  String(value == null ? "" : value).split(",").forEach(raw => {
    const e = ipwNormalizeEntry(raw);
    if (!e) return;
    if (out.some(x => x.toLowerCase() === e.toLowerCase())) return;
    out.push(e);
  });
  return out;
}

// Validare preliminara pe client (serverul valideaza strict, inclusiv IPv6).
function ipwLooksValid(entry) {
  const raw = ipwNormalizeEntry(entry);
  if (!raw) return false;
  const parts = raw.split("/");
  if (parts.length > 2) return false;
  const ip = parts[0];
  const bits = (parts.length === 2) ? parts[1] : null;
  if (bits !== null && !/^\d{1,3}$/.test(bits)) return false;
  if (/^\d{1,3}(\.\d{1,3}){3}$/.test(ip)) {
    const octetsOk = ip.split(".").every(p => {
      const n = parseInt(p, 10);
      return n >= 0 && n <= 255;
    });
    if (!octetsOk) return false;
    return bits === null || parseInt(bits, 10) <= 32;
  }
  if (ip.indexOf(":") !== -1 && /^[0-9a-f:.]+$/i.test(ip)) {
    return bits === null || parseInt(bits, 10) <= 128;
  }
  return false;
}

// Editorul propriu-zis: chips-uri + input pentru adaugare.
function setariIpWhitelistField(row) {
  const div = document.createElement("div");
  div.className = "setari-field";

  const label = document.createElement("label");
  label.className = "setari-label";
  label.textContent = row.Setting;
  div.appendChild(label);

  let entries = ipwParseList(row.Value);

  const chips = document.createElement("div");
  chips.className = "ipw-chips";
  div.appendChild(chips);

  const inputRow = document.createElement("div");
  inputRow.className = "setari-field-row";

  const input = document.createElement("input");
  input.type = "text";
  input.className = "tva-input setari-input";
  input.id = "setari-inp-" + row.Setting;
  input.placeholder = tr("setari.ip.placeholder");
  input.autocomplete = "off";
  inputRow.appendChild(input);

  const kb = document.createElement("button");
  kb.className = "act-btn fkb-mini";
  kb.textContent = "⌨️";
  kb.onclick = () => openFloatingKeyboardFor(input.id);
  inputRow.appendChild(kb);

  const add = document.createElement("button");
  add.className = "act-btn ipw-add";
  add.textContent = tr("common.adauga");
  inputRow.appendChild(add);
  div.appendChild(inputRow);

  const hint = document.createElement("div");
  hint.className = "setari-desc ipw-only-local";
  div.appendChild(hint);

  const commit = () => { setariDirty[row.Setting] = entries.join(","); };

  const render = () => {
    chips.innerHTML = "";
    entries.forEach(entry => {
      const local = ipwIsLocal(entry);
      const chip = document.createElement("span");
      chip.className = "ipw-chip" + (local ? " ipw-chip-locked" : "");

      const txt = document.createElement("span");
      txt.textContent = entry;
      chip.appendChild(txt);

      if (local) {
        const lock = document.createElement("span");
        lock.className = "ipw-lock";
        lock.textContent = "🔒";
        lock.title = tr("setari.ip.localhost");
        chip.appendChild(lock);
      } else {
        const rm = document.createElement("button");
        rm.type = "button";
        rm.className = "ipw-remove";
        rm.textContent = "✕";
        rm.title = tr("common.sterge");
        rm.onclick = () => {
          entries = entries.filter(x => x !== entry);
          commit();
          render();
        };
        chip.appendChild(rm);
      }
      chips.appendChild(chip);
    });
    const onlyLocal = !entries.some(e => !ipwIsLocal(e));
    hint.textContent = onlyLocal ? tr("setari.ip.only_local") : "";
    hint.style.display = onlyLocal ? "" : "none";
  };

  const addEntry = () => {
    const raw = String(input.value || "").trim();
    if (!raw) return;
    if (!ipwLooksValid(raw)) {
      appAlert(tr("setari.ip.invalid") + " " + raw);
      return;
    }
    const entry = ipwNormalizeEntry(raw);
    if (!entries.some(x => x.toLowerCase() === entry.toLowerCase())) {
      entries.push(entry);
      commit();
    }
    input.value = "";
    render();
    input.focus();
  };

  add.onclick = addEntry;
  input.onkeydown = (ev) => {
    if (ev.key === "Enter") { ev.preventDefault(); addEntry(); }
  };

  render();

  if (setariClientIp) {
    const client = document.createElement("div");
    client.className = "setari-desc ipw-client";
    client.textContent = tr("setari.ip.client") + " " + setariClientIp
      + (setariClientIpLocal ? " (" + tr("setari.ip.localhost") + ")" : "");
    div.appendChild(client);
  }

  if (row.Descriere && String(row.Descriere).trim() !== "") {
    const d = document.createElement("div");
    d.className = "setari-desc";
    d.textContent = row.Descriere;
    div.appendChild(d);
  }

  return div;
}

// Campurile din grupul Discount care depind de tblSet.RED. Cand RED = NU (0),
// ele devin inactive (nu are sens sa le configurezi daca discountul e interzis).
const RED_DEPENDENT_FIELDS = ["setari-inp-TipDiscount", "setari-inp-ProcentDiscountFix", "setari-inp-MotivDiscount"];

function applyRedFieldsUI() {
  const redEl = document.getElementById("setari-inp-RED");
  if (!redEl) return;
  const enabled = (String(redEl.value).trim() === "1");
  RED_DEPENDENT_FIELDS.forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.disabled = !enabled;
    const row = el.closest(".setari-field-row");
    if (row) row.querySelectorAll("button").forEach(b => { b.disabled = !enabled; });
    const field = el.closest(".setari-field");
    if (field) field.classList.toggle("setari-field-disabled", !enabled);
  });
}

// Butonul "Revino la implicit" din grupul Fonturi: readuce in formular
// Font = Arial si Size = 12 (fara sa scrie in baza de date; se aplica la
// apasarea butonului Salveaza, ca restul setarilor).
function makeFontResetRow() {
  const div = document.createElement("div");
  div.className = "setari-field";

  const btn = document.createElement("button");
  btn.className = "act-btn";
  btn.id = "setari-font-reset";
  btn.textContent = tr("Revino la implicit");
  btn.onclick = resetFontDefaults;
  div.appendChild(btn);

  const d = document.createElement("div");
  d.className = "setari-desc";
  d.textContent = tr("Readuce Font si Size la valorile implicite (Arial / 12). Apasati Salveaza pentru a aplica.");
  div.appendChild(d);

  return div;
}

function resetFontDefaults() {
  const fontSel = document.getElementById("setari-inp-Font");
  const sizeInp = document.getElementById("setari-inp-Size");
  if (fontSel) {
    fontSel.value = "Arial";
    setariDirty["Font"] = "Arial";
  }
  if (sizeInp) {
    sizeInp.value = "12";
    setariDirty["Size"] = "12";
  }
  showToast("Valori implicite incarcate. Apasati Salveaza.");
}

function renderSetariTab(tabId) {
  const wrap = document.getElementById("setari-content");
  if (!wrap) return;
  wrap.innerHTML = "";

  // Butonul Salveaza nu are sens pe tabul Servicii (fara campuri tblSet).
  const saveBtn = document.getElementById("setari-save-btn");
  if (saveBtn) saveBtn.style.visibility = (tabId === "servicii") ? "hidden" : "visible";

  if (tabId === "servicii") {
    renderServiciiTab(wrap);
    return;
  }

  if (tabId === "integrare") {
    renderIntegrareTab(wrap);
    return;
  }

  const groups = setariGroupsForTab(tabId);
  const total = groups.reduce((n, g) => n + g.rows.length, 0);

  if (total === 0) {
    wrap.innerHTML = '<div class="setari-empty">Nu exista setari in acest tab.</div>';
    return;
  }

  groups.forEach(grp => {
    if (grp.label) {
      const h = document.createElement("div");
      h.className = "setari-group-title";
      h.textContent = grp.label;
      wrap.appendChild(h);
    }
    grp.rows.forEach(r => wrap.appendChild(setariField(r)));
    // Grupul Fonturi primeste si butonul de revenire la valorile implicite.
    if (grp.rows.length > 0 && setariNorm(grp.rows[0].Grup) === "fonturi") {
      wrap.appendChild(makeFontResetRow());
    }
  });

  // Campurile de discount depind de RED (le activeaza/dezactiveaza).
  applyRedFieldsUI();
}

// --------------------------------------------------------------------------
// SERVICII (print-service / sync-service): pornire / oprire / repornire
// --------------------------------------------------------------------------
const SERVICES_DEF = [
  { key: "print", label: "Serviciu de tiparire (print-service)" },
  { key: "sync",  label: "Serviciu de sincronizare (sync-service)" }
];

function renderServiciiTab(wrap) {
  const info = document.createElement("div");
  info.className = "setari-desc";
  info.style.marginBottom = "12px";
  info.textContent = tr("Serviciile locale ruleaza pe acest calculator. Pornirea, oprirea si repornirea se fac de aici.");
  wrap.appendChild(info);

  const ver = document.createElement("div");
  ver.className = "serviciu-version";
  ver.id = "serviciu-version";
  ver.textContent = tr("Versiune: ") + "...";
  wrap.appendChild(ver);
  loadAppVersion().then(v => {
    if (!v) { return; }
    const parts = [];
    if (v.version) parts.push("v" + v.version);
    if (v.short) parts.push(v.short);
    if (v.build) parts.push("build " + v.build);
    if (v.builtAt) parts.push(v.builtAt.slice(0, 10));
    if (v.source) parts.push("(" + v.source + ")");
    ver.textContent = tr("Versiune: ") + (parts.join(" · ") || tr("necunoscuta"))
      + (v.host ? tr(" — stația ") + v.host : "");
  });

  SERVICES_DEF.forEach(svc => {
    const card = document.createElement("div");
    card.className = "serviciu-card";

    const head = document.createElement("div");
    head.className = "serviciu-head";
    const nume = document.createElement("span");
    nume.className = "serviciu-nume";
    nume.textContent = tr(svc.label);
    const badge = document.createElement("span");
    badge.className = "serviciu-badge";
    badge.id = "serviciu-badge-" + svc.key;
    badge.textContent = "...";
    head.appendChild(nume);
    head.appendChild(badge);
    card.appendChild(head);

    const actions = document.createElement("div");
    actions.className = "serviciu-actions";
    [["start", "Porneste"], ["stop", "Opreste"], ["restart", "Reporneste"]].forEach(pair => {
      const b = document.createElement("button");
      b.className = "act-btn";
      b.textContent = tr(pair[1]);
      b.onclick = () => serviceAction(svc.key, pair[0], b);
      actions.appendChild(b);
    });
    card.appendChild(actions);
    wrap.appendChild(card);
  });

  const bar = document.createElement("div");
  bar.className = "serviciu-actions";
  bar.style.marginTop = "14px";
  [["servicii-start-all", "Porneste toate", () => serviceAction("all", "start")],
   ["servicii-restart-all", "Reporneste toate", () => serviceAction("all", "restart")],
   ["servicii-refresh", "Reincarca starea", () => refreshServicesStatus(true)]].forEach(item => {
    const b = document.createElement("button");
    b.className = "act-btn";
    b.id = item[0];
    b.textContent = tr(item[1]);
    b.onclick = item[2];
    bar.appendChild(b);
  });
  wrap.appendChild(bar);

  refreshServicesStatus();
}

async function refreshServicesStatus(showMessage) {
  try {
    const resp = await fetch("api/services.php?action=status", { cache: "no-store" });
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message || "Eroare");
    applyServicesStatus(data.services || {});
    if (showMessage) showToast("Stare actualizata");
  } catch (err) {
    if (showMessage) appAlert("Eroare status servicii: " + err.message);
  }
}

function applyServicesStatus(services) {
  Object.keys(services).forEach(key => {
    const badge = document.getElementById("serviciu-badge-" + key);
    if (!badge) return;
    const up = !!services[key].up;
    badge.textContent = up ? "ONLINE" : "OFFLINE";
    badge.className = "serviciu-badge " + (up ? "up" : "down");
  });
}

function serviceLabel(key) {
  const def = SERVICES_DEF.find(s => s.key === key);
  return def ? tr(def.label) : key;
}

async function serviceAction(service, action, btn) {
  const actiune = { start: "pornirea", stop: "oprirea", restart: "repornirea" }[action] || action;
  const tinta = (service === "all") ? tr("tuturor serviciilor") : (tr("serviciului ") + serviceLabel(service));
  appConfirm(
    tr("Confirmati ") + tr(actiune) + " " + tinta + "?",
    async () => {
      const buttons = document.querySelectorAll("#setari-content button");
      buttons.forEach(b => { b.disabled = true; });
      const old = btn ? btn.textContent : null;
      if (btn) btn.textContent = "...";
      try {
        showToast(tr("Se executa: ") + tr(actiune) + " ...");
        const resp = await fetch("api/services.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          cache: "no-store",
          body: JSON.stringify({ action: action, service: service })
        });
        const data = await resp.json();
        applyServicesStatus(data.services || {});
        if (data.status !== "success") {
          appAlert(data.message || tr("Operatiunea nu a reusit"));
        } else {
          showToast(data.message || tr("Gata"));
        }
      } catch (err) {
        appAlert("Eroare servicii: " + err.message);
      } finally {
        buttons.forEach(b => { b.disabled = false; });
        if (btn && old !== null) btn.textContent = old;
      }
    },
    "Da",
    "Nu"
  );
}

// --------------------------------------------------------------------------
// INTEGRARE / API public (/api/v1/): cheie globala + setari
// --------------------------------------------------------------------------
function renderIntegrareTab(wrap) {
  const info = document.createElement("div");
  info.className = "setari-desc";
  info.style.marginBottom = "12px";
  info.textContent = tr("API public pentru aplicatii terte (servere/backend-uri): export (citire) si import (scriere) de date prin /api/v1/. Autentificarea se face cu o singura cheie globala, trimisa in headerul X-Api-Key.");
  wrap.appendChild(info);

  const vpnNote = document.createElement("div");
  vpnNote.className = "setari-desc";
  vpnNote.style.marginBottom = "12px";
  vpnNote.innerHTML = t("integrare.vpn_note");
  wrap.appendChild(vpnNote);

  const card = document.createElement("div");
  card.className = "setari-field";
  card.style.marginBottom = "16px";

  const title = document.createElement("label");
  title.className = "setari-label";
  title.textContent = tr("Cheie API");
  card.appendChild(title);

  const status = document.createElement("div");
  status.className = "setari-desc";
  let stText = setariApiKeySet ? tr("Cheie configurata.") : tr("Nu exista nicio cheie configurata.");
  if (setariApiKeySet && setariApiLastUsed) stText += tr(" Ultima utilizare: ") + setariApiLastUsed + ".";
  status.textContent = stText;
  card.appendChild(status);

  if (setariNewApiKey) {
    const keyRow = document.createElement("div");
    keyRow.className = "setari-field-row";
    const keyInput = document.createElement("input");
    keyInput.type = "text";
    keyInput.readOnly = true;
    keyInput.className = "tva-input setari-input";
    keyInput.value = setariNewApiKey;
    keyInput.onclick = () => keyInput.select();
    keyRow.appendChild(keyInput);
    const copyBtn = document.createElement("button");
    copyBtn.className = "act-btn";
    copyBtn.textContent = tr("Copiaza");
    copyBtn.onclick = () => copyApiKey(setariNewApiKey);
    keyRow.appendChild(copyBtn);
    card.appendChild(keyRow);

    const warn = document.createElement("div");
    warn.className = "setari-desc";
    warn.style.color = "#b00";
    warn.style.fontWeight = "bold";
    warn.textContent = tr("Copiati cheia acum: nu va mai fi afisata.");
    card.appendChild(warn);
  }

  const btnRow = document.createElement("div");
  btnRow.className = "setari-field-row";
  btnRow.style.marginTop = "8px";

  const genBtn = document.createElement("button");
  genBtn.className = "act-btn";
  genBtn.textContent = setariApiKeySet ? tr("Regenereaza cheia") : tr("Genereaza cheia");
  genBtn.onclick = () => generateApiKey();
  btnRow.appendChild(genBtn);

  if (setariApiKeySet) {
    const revBtn = document.createElement("button");
    revBtn.className = "act-btn";
    revBtn.textContent = tr("Revoca");
    revBtn.onclick = () => revokeApiKey();
    btnRow.appendChild(revBtn);
  }
  card.appendChild(btnRow);
  wrap.appendChild(card);

  // Setarile din grupul Integrare (ApiEnabled, ApiAllowedIps, ApiRateLimit, ApiCorsOrigin).
  const groups = setariGroupsForTab("integrare");
  groups.forEach(grp => grp.rows.forEach(r => wrap.appendChild(setariField(r))));
}

function copyApiKey(key) {
  if (!key) return;
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(key).then(
      () => showToast("Cheie copiata"),
      () => showToast("Copiati manual cheia")
    );
  } else {
    showToast("Copiati manual cheia");
  }
}

function generateApiKey() {
  if (setariApiKeySet) {
    appConfirm(
      "Regenerarea cheii API va invalida cheia curenta si va intrerupe orice integrare activa. Continuati?",
      () => doGenerateApiKey(),
      "Regenereaza",
      "Renunta"
    );
    return;
  }
  doGenerateApiKey();
}

async function doGenerateApiKey() {
  try {
    const resp = await fetch("api/setari.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      cache: "no-store",
      body: JSON.stringify({ action: "apikey_generate" })
    });
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message || "Eroare");
    setariNewApiKey = data.apiKey;
    setariApiKeySet = true;
    renderSetariTab("integrare");
    showToast("Cheie API generata");
  } catch (err) {
    appAlert("Eroare generare cheie: " + err.message);
  }
}

function revokeApiKey() {
  appConfirm(
    "Revocarea cheii API intrerupe toate integrarile active pana la generarea unei chei noi. Continuati?",
    async () => {
      try {
        const resp = await fetch("api/setari.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          cache: "no-store",
          body: JSON.stringify({ action: "apikey_revoke" })
        });
        const data = await resp.json();
        if (data.status !== "success") throw new Error(data.message || "Eroare");
        setariNewApiKey = null;
        setariApiKeySet = false;
        renderSetariTab("integrare");
        showToast("Cheie API revocata");
      } catch (err) {
        appAlert("Eroare revocare cheie: " + err.message);
      }
    },
    "Revoca",
    "Renunta"
  );
}

async function saveSetari() {
  const keys = Object.keys(setariDirty);
  if (keys.length === 0) {
    showToast("Nu sunt modificari de salvat");
    return;
  }

  try {
    const resp = await fetch("api/setari.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "update", values: setariDirty })
    });
    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message);

    // Reflectam modificarile in starea locala
    keys.forEach(k => {
      const row = setariRows.find(r => r.Setting === k);
      if (row) row.Value = setariDirty[k];
    });

    // Tipul de vanzare se aplica imediat (ascunde/afiseaza butoane, ecran mese).
    if (keys.indexOf("TipVanz") !== -1) {
      POS_STATE.tipVanz = (String(setariDirty["TipVanz"]).trim() === "1") ? "fastfood" : "restaurant";
      applyTipVanzUI();
    }

    // Numarul de zecimale se aplica imediat in nota afisata.
    if (keys.indexOf("NrZecCant") !== -1) {
      const nz = parseInt(setariDirty["NrZecCant"], 10);
      POS_STATE.nrZecCant = (nz >= 0 && nz <= 2) ? nz : 1;
      renderOrderItems();
    }

    // Fontul/marimea implicita a panoului din dreapta se aplica imediat.
    if (keys.indexOf("Font") !== -1 || keys.indexOf("Size") !== -1) {
      if (keys.indexOf("Font") !== -1) {
        POS_STATE.fontPanel = String(setariDirty["Font"] || "Arial").trim() || "Arial";
      }
      if (keys.indexOf("Size") !== -1) {
        const sps = parseInt(setariDirty["Size"], 10);
        POS_STATE.sizePanel = (sps >= 6 && sps <= 72) ? sps : 12;
      }
      refreshMenuPanel();
    }

    // Discountul permis/interzis se aplica imediat (ascunde butoanele).
    if (keys.indexOf("RED") !== -1) {
      POS_STATE.red = (String(setariDirty["RED"]).trim() === "0") ? 0 : 1;
      applyRedUI();
    }

    // Tipul discountului si procentul fix se aplica imediat pe eticheta butonului.
    if (keys.indexOf("TipDiscount") !== -1 || keys.indexOf("ProcentDiscountFix") !== -1) {
      if (keys.indexOf("TipDiscount") !== -1) {
        const td = parseInt(setariDirty["TipDiscount"], 10);
        POS_STATE.tipDiscount = (td >= 0 && td <= 2) ? td : 0;
      }
      if (keys.indexOf("ProcentDiscountFix") !== -1) {
        const pf = Number(String(setariDirty["ProcentDiscountFix"]).replace(",", "."));
        POS_STATE.procentDiscountFix = (pf >= 0 && pf <= 100) ? pf : 10;
      }
      applyTipDiscountUI();
    }

    // Motivele de discount/stornare se aplica imediat in casetele Discount/VD.
    if (keys.indexOf("MotivDiscount") !== -1 || keys.indexOf("MotivStornare") !== -1) {
      if (keys.indexOf("MotivDiscount") !== -1) {
        POS_STATE.motivDiscount = parseMotiveList(setariDirty["MotivDiscount"]);
      }
      if (keys.indexOf("MotivStornare") !== -1) {
        POS_STATE.motivStornare = parseMotiveList(setariDirty["MotivStornare"]);
      }
      refreshMotiveSelects();
    }

    // Export/sincronizare server: actualizeaza blocarea Grupe/Produse in programare.
    if (keys.indexOf("Server") !== -1) {
      POS_STATE.server = (String(setariDirty["Server"]).trim() === "1") ? 1 : 0;
      applyServerModeUI();
    }

    // POS bancar si asteptarea casei de marcat: se aplica imediat, pentru ca
    // altfel un toggle facut in Setari nu se reflecta pana la reincarcarea
    // paginii, iar pasul bancar ar fi sarit la inchiderea notei.
    if (keys.indexOf("PoSbanca") !== -1) {
      POS_STATE.posBanca = (String(setariDirty["PoSbanca"]).trim() === "1") ? 1 : 0;
    }
    // Retiparirea chitantei de card se aplica imediat: butonul din ecranul de
    // plata apare/dispare fara sa fie nevoie de reincarcarea paginii.
    if (keys.indexOf("RetiparireChitantaPoSbanca") !== -1) {
      POS_STATE.retiparireChitantaPoSbanca =
        (String(setariDirty["RetiparireChitantaPoSbanca"]).trim() === "1") ? 1 : 0;
      refreshBankReprintButton();
    }
    // Protocolul se aplica imediat in client; urmatorul status primit de la
    // server este oricum autoritatea pentru capabilitati.
    if (keys.indexOf("ProtocolPoSbanca") !== -1) {
      const proto = String(setariDirty["ProtocolPoSbanca"] || "SmartPayECR").trim();
      POS_STATE.bankPosProtocol = proto;
      // Capabilitatile vin de la server (sursa de adevar): lista de protocoale
      // a fost incarcata la deschiderea Setarilor, cu canResync/canVoid.
      const opt = (Array.isArray(setariPosBancaProtocols) ? setariPosBancaProtocols : [])
        .find(o => String(o.value) === proto);
      POS_STATE.bankPosCanResync = opt ? (opt.canResync === true) : true;
      POS_STATE.bankPosCanVoid = opt ? (opt.canVoid === true) : true;
      // Conexiunea (.env) este citita de server la urmatorul status.
      if (opt && opt.envConnection === false) { POS_STATE.bankPosConnection = null; }
    }
    if (keys.indexOf("AsteaptaRaspunsCasa") !== -1) {
      POS_STATE.asteaptaRaspunsCasa = (String(setariDirty["AsteaptaRaspunsCasa"]).trim() === "1") ? 1 : 0;
    }

    // Plata combinata: se aplica imediat in ecranul de inchidere.
    if (keys.indexOf("PlataCombinata") !== -1) {
      POS_STATE.plataCombinata = (String(setariDirty["PlataCombinata"]).trim() === "0") ? 0 : 1;
      applyPlataCombinataUI();
    }

    // Limba interfetei: reincarcam pagina ca sa se aplice la randare
    // (server-side in index.php) si sa se retraduca toate ecranele.
    if (keys.indexOf("Limba") !== -1) {
      POS_STATE.lang = (String(setariDirty["Limba"]).trim().toLowerCase() === "en") ? "en" : "ro";
      setariDirty = {};
      showToast(res.message);
      setTimeout(() => window.location.reload(), 600);
      return;
    }

    setariDirty = {};
    showToast(res.message);
  } catch (err) {
    appAlert("Eroare salvare setari: " + err.message);
  }
}


// --------------------------------------------------------------------------
// PROGRAMARE MESE (tblMese) - harta live 10x8 + inspector
// --------------------------------------------------------------------------
let meseWorking = {};   // NrMasa -> {MasaID?, NrMasa, back, fg, bold, afisez, obs}
let meseDirty = {};     // NrMasa -> true daca are modificari nesalvate
let meseSelected = null;
let meseLoaded = false;

const MESE_PALETTE = [
  { v: null, auto: true },
  { v: "#ffffff" },
  { v: "#000000" },
  { v: "#0000ff" },
  { v: "#00e800" },
  { v: "#008000" },
  { v: "#ffff00" },
  { v: "#ff0000" },
  { v: "#232d84" },
  { v: "#808080" }
];

function colorHexToInt(hex) {
  if (!hex || hex[0] !== "#") return null;
  const r = parseInt(hex.slice(1, 3), 16);
  const g = parseInt(hex.slice(3, 5), 16);
  const b = parseInt(hex.slice(5, 7), 16);
  return (r) + (g << 8) + (b << 16);
}

// Culori de sistem VBA / SystemColorConstants (Access/VB): nu sunt COLORREF
// reale, au bitul 0x80000000 set (valoare negativa), ex. vbButtonFace =
// &H8000000F. Le mapam la culori concrete (implicit gri neutru).
function vbaSystemColorToHex(index) {
  const map = {
    0x00: "#000000", 0x01: "#000000", 0x02: "#000080", 0x03: "#808080",
    0x04: "#c0c0c0", 0x05: "#ffffff", 0x06: "#000000", 0x07: "#000000",
    0x08: "#000000", 0x09: "#ffffff", 0x0a: "#c0c0c0", 0x0b: "#c0c0c0",
    0x0c: "#c0c0c0", 0x0d: "#000080", 0x0e: "#ffffff", 0x0f: "#d4d0c8",
    0x10: "#808080", 0x11: "#808080", 0x12: "#000000", 0x13: "#c0c0c0",
    0x14: "#ffffff", 0x15: "#000000", 0x16: "#d4d0c8", 0x17: "#000000",
    0x18: "#ffffe1"
  };
  return map[index & 0xff] || "#d4d0c8";
}

function colorIntToHex(v) {
  if (v === null || v === undefined) return null;
  const n = Number(v);
  if (n < 0 || n > 0xffffff) return vbaSystemColorToHex(n & 0xff);
  const r = (n & 255).toString(16).padStart(2, "0");
  const g = ((n >> 8) & 255).toString(16).padStart(2, "0");
  const b = ((n >> 16) & 255).toString(16).padStart(2, "0");
  return "#" + r + g + b;
}

function openProgMese() {
  meseSelected = null;
  navigateToScreen("screen-prog-mese");
  reloadMese(false);
}

function closeProgMese() {
  if (Object.keys(meseDirty).length > 0) {
    appConfirm("Aveti modificari nesalvate. Renuntati si parasiti programarea mese?", () => {
      navigateToScreen("screen-programare");
    }, "Renunta", "Continua");
    return;
  }
  navigateToScreen("screen-programare");
}

function resetMese() {
  appConfirm("Resetati toate mesele la valorile implicite? Toate modificarile nesalvate vor fi pierdute.", () => doResetMese(), "Reset", "Anuleaza");
}

function doResetMese() {
  fetch("api/mese.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ action: "reset" })
  })
    .then(r => r.json())
    .then(data => {
      if (data.status !== "success") throw new Error(data.message || "Eroare");
      showToast(data.message);
      reloadMese(false);
    })
    .catch(err => appAlert("Eroare resetare mese: " + err.message));
}

function reloadMese(warn) {
  const hasDirty = Object.keys(meseDirty).length > 0;
  if (warn && hasDirty) {
    appConfirm("Reload sterge modificarile nesalvate. Continuati?", () => doReloadMese(), "Da", "Nu");
    return;
  }
  doReloadMese();
}

function doReloadMese() {
  fetch("api/mese.php")
    .then(r => r.json())
    .then(data => {
      if (data.status !== "success") throw new Error(data.message || "Eroare");
      meseWorking = {};
      meseDirty = {};
      meseSelected = null;
      (data.rows || []).forEach(m => {
        meseWorking[m.NrMasa] = {
          MasaID: m.MasaID,
          NrMasa: m.NrMasa,
          back: m.BackColor,
          fg: m.ForeColor,
          bold: m.Bold,
          afisez: m.Afisez,
          obs: m.Obs
        };
      });
      meseLoaded = true;
      renderMeseMap();
      renderMeseInspector();
    })
    .catch(err => appAlert("Eroare incarcare mese: " + err.message));
}

function renderMeseMap() {
  const grid = document.getElementById("mese-prog-grid");
  if (!grid) return;
  grid.innerHTML = "";

  for (let nr = 1; nr <= 80; nr++) {
    const o = meseWorking[nr];
    const cell = document.createElement("div");
    cell.className = "table-cell-btn";

    if (!o) {
      cell.classList.add("mese-empty");
      cell.innerHTML = "<span>+</span>";
      cell.onclick = () => { meseSelected = nr; renderMeseMap(); renderMeseInspector(); };
    } else {
      const bg = o.back !== null && o.back !== undefined ? colorIntToHex(o.back) : "#f0f0f0";
      const fg = o.fg !== null && o.fg !== undefined ? colorIntToHex(o.fg) : "#000000";
      cell.style.background = bg;
      cell.style.color = fg;
      cell.style.fontWeight = o.bold ? "bold" : "normal";
      cell.innerHTML = `<span>${nr}</span>${meseDirty[nr] ? '<span class="mese-dirty-mark">*</span>' : ""}`;
      if (o.afisez === false) cell.classList.add("mese-hidden");
      cell.onclick = () => { meseSelected = nr; renderMeseMap(); renderMeseInspector(); };
    }

    if (nr === meseSelected) cell.classList.add("mese-selected");
    grid.appendChild(cell);
  }
}

function buildMeseSwatches(containerId, getVal, setVal) {
  const wrap = document.getElementById(containerId);
  if (!wrap) return;
  wrap.innerHTML = "";
  MESE_PALETTE.forEach(p => {
    const s = document.createElement("div");
    if (p.auto) {
      s.className = "mese-swatch auto-swatch";
      s.title = "Implicit";
      s.innerHTML = "A";
    } else {
      s.className = "mese-swatch";
      s.style.background = p.v;
    }
    if ((getVal() === null || getVal() === undefined) && p.auto) s.classList.add("sel");
    else if (!p.auto && getVal() === colorHexToInt(p.v)) s.classList.add("sel");
    s.onclick = () => {
      setVal(p.auto ? null : colorHexToInt(p.v));
      markMeseDirty(meseSelected);
      renderMeseMap();
      renderMeseInspector();
    };
    wrap.appendChild(s);
  });
}

function setMeseCustom(which) {
  const id = which === "bg" ? "mese-bg-custom" : "mese-fg-custom";
  const el = document.getElementById(id);
  if (!el || meseSelected === null) return;
  const o = meseWorking[meseSelected];
  if (!o) return;
  const val = colorHexToInt(el.value);
  if (which === "bg") o.back = val; else o.fg = val;
  markMeseDirty(meseSelected);
  renderMeseMap();
  renderMeseInspector();
}

function renderMeseInspector() {
  let o = meseSelected === null ? null : meseWorking[meseSelected];
  const title = document.getElementById("mese-insp-title");
  const dirtyBadge = document.getElementById("mese-dirty-badge");
  const emptyMsg = document.getElementById("mese-empty-msg");
  const form = document.getElementById("mese-form");
  const nrDisplay = document.getElementById("mese-nr-display");

  // Nimic selectat: doar mesajul introductiv, fara formular / buton
  if (meseSelected === null) {
    if (title) title.innerText = tr("MASA");
    if (dirtyBadge) dirtyBadge.style.display = "none";
    if (nrDisplay) nrDisplay.innerText = "-";
    if (emptyMsg) {
      emptyMsg.style.display = "block";
      emptyMsg.innerHTML = tr('Atinge o pozitie pe harta pentru a o edita');
    }
    if (form) form.style.display = "none";
    return;
  }

  // O pozitie libera selectata => cream automat un draft (implicit) de masa pentru editare
  if (!o) {
    o = { MasaID: null, NrMasa: meseSelected, back: null, fg: null, bold: false, afisez: true, obs: "" };
    meseWorking[meseSelected] = o;
    renderMeseMap(); // celula isi schimba aspectul din "+" liber in masa editabila
  }

  const saveBtn = document.getElementById("mese-save-btn");
  saveBtn.onclick = saveMeseSelected;

  document.getElementById("mese-insp-title").innerText = tr("MASA ") + meseSelected;
  dirtyBadge.style.display = meseDirty[meseSelected] ? "inline" : "none";
  emptyMsg.style.display = "none";
  form.style.display = "block";

  // Previzualizare
  const preview = document.getElementById("mese-preview");
  const bg = o.back !== null && o.back !== undefined ? colorIntToHex(o.back) : "#f0f0f0";
  const fg = o.fg !== null && o.fg !== undefined ? colorIntToHex(o.fg) : "#000000";
  preview.style.background = bg;
  preview.style.color = fg;
  preview.style.fontWeight = o.bold ? "bold" : "normal";
  preview.innerHTML = "<span>" + meseSelected + "</span>";
  document.getElementById("mese-nr-display").innerText = meseSelected;

  // Toggluri
  renderMeseToggle("mese-bold-toggle", !!o.bold, "Da", "Nu");
  renderMeseToggle("mese-afisez-toggle", !!o.afisez, "Da", "Nu");

  // Inputuri
  document.getElementById("mese-obs-input").value = o.obs || "";
  document.getElementById("mese-bg-custom").value = colorIntToHex(o.back !== null ? o.back : 0xf0f0f0) || "#ffffff";
  document.getElementById("mese-fg-custom").value = colorIntToHex(o.fg !== null ? o.fg : 0x000000) || "#000000";

  buildMeseSwatches("mese-bg-swatches", () => o.back, v => { o.back = v; });
  buildMeseSwatches("mese-fg-swatches", () => o.fg, v => { o.fg = v; });

  saveBtn.innerText = tr("Salveaza");
  saveBtn.onclick = saveMeseSelected;
  saveBtn.disabled = false;
}

function renderMeseToggle(id, on, yesTxt, noTxt) {
  const btn = document.getElementById(id);
  if (!btn) return;
  btn.innerText = on ? tr(yesTxt) : tr(noTxt);
  btn.style.background = on ? "#008000" : "#ffff00";
  btn.style.color = on ? "#ffffff" : "#000000";
}

function markMeseDirty(nr) {
  if (nr === null || nr === undefined) return;
  meseDirty[nr] = true;
  const badge = document.getElementById("mese-dirty-badge");
  if (badge) badge.style.display = "inline";
}

function toggleMeseBold() {
  const o = meseWorking[meseSelected];
  if (!o) return;
  o.bold = !o.bold;
  markMeseDirty(meseSelected);
  renderMeseMap();
  renderMeseInspector();
}

function toggleMeseAfisez() {
  const o = meseWorking[meseSelected];
  if (!o) return;
  o.afisez = !o.afisez;
  markMeseDirty(meseSelected);
  renderMeseMap();
  renderMeseInspector();
}

function bindMeseTextInput() {
  const obs = document.getElementById("mese-obs-input");
  if (obs) obs.oninput = () => {
    const o = meseWorking[meseSelected];
    if (!o) return;
    o.obs = obs.value;
    markMeseDirty(meseSelected);
  };
  const bgCustom = document.getElementById("mese-bg-custom");
  const fgCustom = document.getElementById("mese-fg-custom");
  if (bgCustom) bgCustom.oninput = () => setMeseCustom("bg");
  if (fgCustom) fgCustom.oninput = () => setMeseCustom("fg");
}

async function saveMeseSelected() {
  const o = meseWorking[meseSelected];
  if (!o) return;

  const payload = {
    action: o.MasaID > 0 ? "update" : "insert",
    MasaID: o.MasaID > 0 ? o.MasaID : 0,
    NrMasa: o.NrMasa,
    BackColor: o.back === null ? "" : o.back,
    ForeColor: o.fg === null ? "" : o.fg,
    Bold: o.bold ? 1 : 0,
    Afisez: o.afisez ? 1 : 0,
    Obs: o.obs || ""
  };

  try {
    const resp = await fetch("api/mese.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message);
    showToast(res.message);
    delete meseDirty[o.NrMasa];
    reloadMese(false);
  } catch (err) {
    appAlert("Eroare salvare masa: " + err.message);
  }
}

document.addEventListener("DOMContentLoaded", bindMeseTextInput);

// --------------------------------------------------------------------------
// PROGRAMARE GRUPE (tblGrp) - grila live 5x10 + inspector
// --------------------------------------------------------------------------
let grupWorking = {};   // cheie ('g'+NrGrp pt. existente / 'new...' pt. draft) -> row
let grupDirty = {};     // cheie -> true daca nesalvat
let grupSelected = null;
let grupTempCounter = 0;

function grupDefaultRow(poz) {
  return {
    key: "new" + (++grupTempCounter),
    isNew: true,
    NrGrp: "",
    Denumire: "",
    back: null,
    fg: null,
    fontSize: null,
    fontType: "",
    poz: (poz >= 1 && poz <= 50) ? poz : null,
    bold: false
  };
}

function openProgGrupe() {
  grupSelected = null;
  navigateToScreen("screen-prog-grupe");
  reloadGrupe(false);
}

function closeProgGrupe() {
  if (Object.keys(grupDirty).length > 0) {
    appConfirm("Aveti modificari nesalvate. Renuntati si parasiti programarea grupelor?", () => {
      navigateToScreen("screen-programare");
    }, "Renunta", "Continua");
    return;
  }
  navigateToScreen("screen-programare");
}

function reloadGrupe(warn) {
  if (warn && Object.keys(grupDirty).length > 0) {
    appConfirm("Reload sterge modificarile nesalvate. Continuati?", () => doReloadGrupe(), "Da", "Nu");
    return;
  }
  doReloadGrupe();
}

function doReloadGrupe() {
  fetch("api/grupe.php")
    .then(r => r.json())
    .then(data => {
      if (data.status !== "success") throw new Error(data.message || "Eroare");
      grupWorking = {};
      grupDirty = {};
      grupSelected = null;
      (data.rows || []).forEach(g => {
        grupWorking["g" + g.NrGrp] = {
          key: "g" + g.NrGrp,
          isNew: false,
          NrGrp: g.NrGrp,
          Denumire: g.Denumire,
          back: g.BackColor,
          fg: g.FontColor,
          fontSize: g.FontSize,
          fontType: g.FontType,
          poz: g.Poz,
          bold: g.Bold
        };
      });
      renderGrupeGrid();
      renderGrupeInspector();
    })
    .catch(err => appAlert("Eroare incarcare grupe: " + err.message));
}

function rowForPoz(poz) {
  const found = Object.values(grupWorking).find(r => r.poz === poz);
  return found || null;
}

function renderGrupeGrid() {
  const grid = document.getElementById("grupe-prog-grid");
  if (!grid) return;
  grid.innerHTML = "";

  for (let poz = 1; poz <= 50; poz++) {
    const row = rowForPoz(poz);
    const btn = document.createElement("button");
    btn.className = "grid-pos-btn";

    if (row) {
      const bg = row.back !== null && row.back !== undefined ? colorIntToHex(row.back) : null;
      const fg = row.fg !== null && row.fg !== undefined ? colorIntToHex(row.fg) : null;
      if (bg) btn.style.backgroundColor = bg;
      if (fg) btn.style.color = fg;
      btn.style.fontWeight = row.bold ? "bold" : "normal";
      if (row.fontSize) btn.style.fontSize = row.fontSize + "px";
      if (row.fontType) btn.style.fontFamily = row.fontType;
      if (row.isNew) btn.classList.add("grup-new");
      btn.innerHTML = (row.Denumire || "(grup nou)").replace(/\s+/g, "<br>");
      btn.onclick = () => {
        grupSelected = row.key;
        renderGrupeGrid();
        renderGrupeInspector();
      };
    } else {
      btn.classList.add("grup-empty");
      btn.innerHTML = `<span class="prod-slot-nr">${poz}</span><span>+</span>`;
      btn.onclick = () => {
        const nr = grupDefaultRow(poz);
        grupWorking[nr.key] = nr;
        grupSelected = nr.key;
        renderGrupeGrid();
        renderGrupeInspector();
      };
    }

    if (row && row.key === grupSelected) btn.classList.add("grup-selected");
    grid.appendChild(btn);
  }
}

function renderGrupSwatches(containerId, getVal, setVal) {
  const wrap = document.getElementById(containerId);
  if (!wrap) return;
  wrap.innerHTML = "";
  MESE_PALETTE.forEach(p => {
    const s = document.createElement("div");
    if (p.auto) {
      s.className = "mese-swatch auto-swatch";
      s.title = "Implicit";
      s.innerHTML = "A";
    } else {
      s.className = "mese-swatch";
      s.style.background = p.v;
    }
    if ((getVal() === null || getVal() === undefined) && p.auto) s.classList.add("sel");
    else if (!p.auto && getVal() === colorHexToInt(p.v)) s.classList.add("sel");
    s.onclick = () => {
      setVal(p.auto ? null : colorHexToInt(p.v));
      const row = grupWorking[grupSelected];
      if (row) markGrupDirty(row.key);
      renderGrupeGrid();
      renderGrupeInspector();
    };
    wrap.appendChild(s);
  });
}

function setGrupCustom(which) {
  const el = document.getElementById(which === "bg" ? "grup-bg-custom" : "grup-fg-custom");
  const row = grupWorking[grupSelected];
  if (!el || !row) return;
  const val = colorHexToInt(el.value);
  if (which === "bg") row.back = val; else row.fg = val;
  markGrupDirty(row.key);
  renderGrupeGrid();
  renderGrupeInspector();
}

function markGrupDirty(key) {
  grupDirty[key] = true;
  const badge = document.getElementById("grup-dirty-badge");
  if (badge) badge.style.display = "inline";
}

function renderGrupeInspector() {
  const title = document.getElementById("grup-insp-title");
  const dirtyBadge = document.getElementById("grup-dirty-badge");
  const emptyMsg = document.getElementById("grup-empty-msg");
  const form = document.getElementById("grup-form");
  const row = grupSelected === null ? null : grupWorking[grupSelected];

  if (!row) {
    if (title) title.innerText = tr("GRUPA");
    if (dirtyBadge) dirtyBadge.style.display = "none";
    if (emptyMsg) {
      emptyMsg.style.display = "block";
      emptyMsg.innerHTML = tr('Atinge o grupa sau o pozitie libera de pe grila pentru a o edita');
    }
    if (form) form.style.display = "none";
    return;
  }

  const saveBtn = document.getElementById("grup-save-btn");
  saveBtn.onclick = saveGrupeSelected;

  document.getElementById("grup-insp-title").innerText = row.isNew ? tr("GRUPA NOUA") : (tr("GRUPA ") + row.NrGrp);
  dirtyBadge.style.display = grupDirty[row.key] ? "inline" : "none";
  emptyMsg.style.display = "none";
  form.style.display = "block";

  document.getElementById("grup-key-input").readOnly = !row.isNew;
  document.getElementById("grup-key-input").value = row.NrGrp !== null && row.NrGrp !== undefined ? row.NrGrp : "";
  document.getElementById("grup-den-input").value = row.Denumire || "";
  document.getElementById("grup-poz-input").value = row.poz !== null && row.poz !== undefined ? row.poz : "";
  document.getElementById("grup-size-input").value = row.fontSize !== null && row.fontSize !== undefined ? row.fontSize : "";
  document.getElementById("grup-font-input").value = row.fontType || "";
  document.getElementById("grup-bg-custom").value = colorIntToHex(row.back !== null ? row.back : 0xf0f0f0) || "#ffffff";
  document.getElementById("grup-fg-custom").value = colorIntToHex(row.fg !== null ? row.fg : 0x000000) || "#000000";

  renderMeseToggle("grup-bold-toggle", !!row.bold, "Da", "Nu");

  renderGrupSwatches("grup-bg-swatches", () => row.back, v => { row.back = v; });
  renderGrupSwatches("grup-fg-swatches", () => row.fg, v => { row.fg = v; });

  saveBtn.disabled = false;
}

function toggleGrupBold() {
  const row = grupWorking[grupSelected];
  if (!row) return;
  row.bold = !row.bold;
  markGrupDirty(row.key);
  renderGrupeGrid();
  renderGrupeInspector();
}

function bindGrupeInputs() {
  const map = {
    "grup-key-input": r => {
      const v = document.getElementById("grup-key-input").value.trim();
      r.NrGrp = v === "" ? "" : parseInt(v, 10);
    },
    "grup-den-input": r => { r.Denumire = document.getElementById("grup-den-input").value; },
    "grup-font-input": r => { r.fontType = document.getElementById("grup-font-input").value; }
  };
  Object.keys(map).forEach(id => {
    const el = document.getElementById(id);
    if (el) el.oninput = () => {
      const row = grupWorking[grupSelected];
      if (!row) return;
      map[id](row);
      markGrupDirty(row.key);
    };
  });

  const pozEl = document.getElementById("grup-poz-input");
  if (pozEl) pozEl.oninput = () => {
    const row = grupWorking[grupSelected];
    if (!row) return;
    const v = pozEl.value.trim();
    row.poz = v === "" ? null : parseInt(v, 10);
    markGrupDirty(row.key);
    renderGrupeGrid();
  };
  const sizeEl = document.getElementById("grup-size-input");
  if (sizeEl) sizeEl.oninput = () => {
    const row = grupWorking[grupSelected];
    if (!row) return;
    const v = sizeEl.value.trim();
    row.fontSize = v === "" ? null : parseInt(v, 10);
    markGrupDirty(row.key);
    renderGrupeGrid();
  };
  document.getElementById("grup-bg-custom").oninput = () => setGrupCustom("bg");
  document.getElementById("grup-fg-custom").oninput = () => setGrupCustom("fg");
}

async function saveGrupeSelected() {
  const row = grupWorking[grupSelected];
  if (!row) return;

  // Citim direct din inputuri, ca sursa de adevar (nu ne bazam doar pe starea interna)
  const keyRaw = (document.getElementById("grup-key-input").value || "").trim();
  const denRaw = (document.getElementById("grup-den-input").value || "").trim();
  const pozRaw = (document.getElementById("grup-poz-input").value || "").trim();
  const sizeRaw = (document.getElementById("grup-size-input").value || "").trim();
  const fontRaw = (document.getElementById("grup-font-input").value || "").trim();

  const nrGrp = row.isNew ? parseInt(keyRaw, 10) : row.NrGrp;
  const poz = pozRaw === "" ? row.poz : parseInt(pozRaw, 10);
  const fontSize = sizeRaw === "" ? row.fontSize : parseInt(sizeRaw, 10);

  if (isNaN(nrGrp) || nrGrp <= 0) {
    showToast("Introduceti un NrGrp intreg > 0");
    return;
  }
  if (denRaw === "") {
    showToast("Denumire obligatorie");
    return;
  }
  if (poz === null || isNaN(poz) || poz < 1 || poz > 50) {
    showToast("Poz trebuie sa fie intre 1 si 50");
    return;
  }

  const payload = {
    action: row.isNew ? "insert" : "update",
    NrGrp: nrGrp,
    Denumire: denRaw,
    BackColor: row.back === null ? "" : row.back,
    FontColor: row.fg === null ? "" : row.fg,
    FontSize: (fontSize === null || isNaN(fontSize)) ? "" : fontSize,
    Bold: row.bold ? 1 : 0,
    FontType: fontRaw,
    Poz: poz
  };

  try {
    const resp = await fetch("api/grupe.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message);
    showToast(res.message);
    grupSelected = "g" + nrGrp;
    reloadGrupe(false);
  } catch (err) {
    appAlert("Eroare salvare grupa: " + err.message);
  }
}

document.addEventListener("DOMContentLoaded", bindGrupeInputs);

// --------------------------------------------------------------------------
// PROGRAMARE PRODUSE (tblProd) - grila live 5x10 pe grupa + inspector
// --------------------------------------------------------------------------
let prodWorking = {};      // cheie ('p'+ProdID / 'new...') -> row
let prodSelected = null;
let prodCurGrup = null;    // NrGrp afisat in grila
let prodDirty = {};        // cheie -> true daca nesalvat
let prodTempCounter = 0;
let prodGroups = [];       // din tblGrp
let prodSectii = [];       // din tblSectii
let prodTvaList = [];      // din tblTVA
let prodKpList = [];       // din tblKP (sectii de tiparire)
let prodMagId = "";        // tblSet.MagID brut (poate fi o lista "1,2")
let prodMagIdList = [];    // int-urile din tblSet.MagID

function prodNum(v) {
  const s = (v === null || v === undefined) ? "" : String(v).trim();
  if (s === "") return null;
  const n = Number(s);
  return isNaN(n) ? null : n;
}

function prodDefaultRow(poz, nrGrp) {
  return {
    key: "new" + (++prodTempCounter),
    isNew: true,
    ProdID: "",
    BarCod: "",
    Denumire: "",
    UM: "",
    NrGrp: nrGrp,
    KP: null,
    Sectie: null,
    Nr_TVA: null,
    PV: null,
    Poz: poz,
    FontSize: null,
    back: null,
    fg: null,
    FontType: "",
    Imagine: "",
    IRP: null,
    IIVCN: null,
    Bold: false,
    MZ: false,
    MagID: prodMagIdList.length ? prodMagIdList[0] : null
  };
}

function openProgProduse() {
  prodSelected = null;
  navigateToScreen("screen-prog-produse");
  reloadProduse(false);
}

function closeProgProduse() {
  if (Object.keys(prodDirty).length > 0) {
    appConfirm("Aveti modificari nesalvate. Renuntati si parasiti programarea produselor?", () => {
      navigateToScreen("screen-programare");
    }, "Renunta", "Continua");
    return;
  }
  navigateToScreen("screen-programare");
}

function reloadProduse(warn) {
  if (warn && Object.keys(prodDirty).length > 0) {
    appConfirm("Reload sterge modificarile nesalvate. Continuati?", () => loadProdGrupes(), "Da", "Nu");
    return;
  }
  loadProdGrupes();
}

async function loadProdGrupes() {
  try {
    const resp = await fetch("api/grupe.php");
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message);
    prodGroups = data.rows || [];
    if (prodGroups.length === 0) {
      showToast("Nu exista grupe de produse");
      return;
    }
    if (!prodGroups.some(g => g.NrGrp === prodCurGrup)) {
      prodCurGrup = prodGroups[0].NrGrp;
    }
    await loadProdGroup();
  } catch (err) {
    appAlert("Eroare incarcare grupe produse: " + err.message);
  }
}

function fillProdCombo(el, value, labelFn) {
  if (!el) return;
  const prev = el.value;
  el.innerHTML = "";
  value.forEach(v => {
    const opt = document.createElement("option");
    opt.value = v;
    opt.textContent = labelFn(v);
    el.appendChild(opt);
  });
  if (Array.from(el.options).some(o => o.value === prev)) el.value = prev;
}

// Randeaza campul MagID (magazia) din inspectorul de produse:
// - daca tblSet.MagID este o lista (mai multe id-uri) -> combobox;
// - daca este o valoare unica -> camp numeric, precompletat cu valoarea implicita.
function renderProdMagIdField() {
  const wrap = document.getElementById("prod-magid-field");
  if (!wrap) return;
  wrap.innerHTML = "";

  if (prodMagIdList.length > 1) {
    const select = document.createElement("select");
    select.id = "prod-magid-select";
    select.className = "prod-combo";
    prodMagIdList.forEach(id => {
      const opt = document.createElement("option");
      opt.value = id;
      opt.textContent = id;
      select.appendChild(opt);
    });
    select.onchange = onProdMagIdChange;
    wrap.appendChild(select);
  } else {
    const rowWrap = document.createElement("div");
    rowWrap.className = "tva-field-row";
    const input = document.createElement("input");
    input.type = "text";
    input.id = "prod-magid-input";
    input.className = "tva-input";
    input.value = (prodMagIdList.length === 1) ? String(prodMagIdList[0]) : "";
    input.oninput = onProdMagIdChange;
    rowWrap.appendChild(input);
    const kb = document.createElement("button");
    kb.className = "act-btn fkb-mini";
    kb.textContent = "123";
    kb.onclick = () => openNumericKeyboardFor("prod-magid-input", "MAGID");
    rowWrap.appendChild(kb);
    wrap.appendChild(rowWrap);
  }
}

function onProdMagIdChange() {
  const row = prodWorking[prodSelected];
  if (!row) return;
  syncProdFromInputs();
  markProdDirty(row.key);
}

async function loadProdGroup() {
  try {
    const resp = await fetch("api/produse.php?nrgrp=" + prodCurGrup);
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message);

    prodSectii = data.sectii || [];
    prodTvaList = data.tva || [];
    prodKpList = data.kpList || [];
    prodMagId = data.magId || "";
    prodMagIdList = Array.isArray(data.magIdList) ? data.magIdList : [];
    renderProdMagIdField();

    const headerSel = document.getElementById("prod-grup-select");
    if (headerSel) {
      headerSel.innerHTML = "";
      prodGroups.forEach(g => {
        const o = document.createElement("option");
        o.value = g.NrGrp;
        o.textContent = g.NrGrp + " - " + g.Denumire;
        if (g.NrGrp === prodCurGrup) o.selected = true;
        headerSel.appendChild(o);
      });
    }
    fillProdCombo(document.getElementById("prod-grup-field"),
      prodGroups.map(g => g.NrGrp), v => {
        const g = prodGroups.find(x => x.NrGrp === v);
        return v + " - " + (g ? g.Denumire : v);
      });
    fillProdCombo(document.getElementById("prod-sectie-select"),
      prodSectii.map(s => s.Sectie), v => {
        const s = prodSectii.find(x => x.Sectie === v);
        return v + (s ? " - " + s.Denumire : "");
      });
    fillProdCombo(document.getElementById("prod-tva-select"),
      prodTvaList.map(t => t.Nr_TVA), v => {
        const t = prodTvaList.find(x => x.Nr_TVA === v);
        return v + (t ? " - " + t.Cota + "%" : "");
      });
    fillProdCombo(document.getElementById("prod-kp-select"),
      [""].concat(prodKpList.map(k => k.NrLogic)), v => {
        if (v === "") return "(fara imprimanta)";
        const k = prodKpList.find(x => x.NrLogic === v);
        return v + (k ? " - " + k.Nume : "");
      });

    prodWorking = {};
    prodDirty = {};
    prodSelected = null;
    (data.products || []).forEach(p => {
      prodWorking["p" + p.ProdID] = {
        key: "p" + p.ProdID,
        isNew: false,
        ProdID: p.ProdID,
        BarCod: p.BarCod,
        Denumire: p.Denumire,
        UM: p.UM,
        NrGrp: p.NrGrp,
        KP: p.KP,
        Sectie: p.Sectie,
        Nr_TVA: p.Nr_TVA,
        PV: p.PV,
        Poz: p.Poz,
        FontSize: p.FontSize,
        back: p.BackColor,
        fg: p.FontColor,
        FontType: p.FontType,
        Imagine: p.Imagine,
        IRP: p.IRP,
        IIVCN: p.IIVCN,
        Bold: p.Bold,
        MZ: p.MZ,
        MagID: p.MagID
      };
    });

    renderProduseGrid();
    renderProduseInspector();
  } catch (err) {
    appAlert("Eroare incarcare produse: " + err.message);
  }
}

function onProdGrupChange() {
  const el = document.getElementById("prod-grup-select");
  if (!el) return;
  const v = parseInt(el.value, 10);
  if (isNaN(v)) return;
  prodCurGrup = v;
  loadProdGroup();
}

function prodRowAtPoz(poz) {
  return Object.values(prodWorking).find(r => r.Poz === poz) || null;
}

function renderProduseGrid() {
  const grid = document.getElementById("produse-prog-grid");
  if (!grid) return;
  grid.innerHTML = "";

  const posMap = {};
  const faraPoz = [];
  Object.values(prodWorking).forEach(p => {
    if (p.Poz >= 1 && p.Poz <= 50 && !posMap[p.Poz]) posMap[p.Poz] = p;
    else faraPoz.push(p);
  });

  for (let poz = 1; poz <= 50; poz++) {
    let p = posMap[poz];
    if (!p && faraPoz.length > 0) p = faraPoz.shift();

    const btn = document.createElement("button");
    btn.className = "grid-pos-btn grid-prod-btn";

    if (p) {
      const isBlackBg = (p.back === 0 || (p.back !== null && p.back !== undefined && colorIntToHex(p.back) === "#000000"));
      if (isBlackBg) {
        btn.style.backgroundColor = "#000000";
        btn.style.color = "#ffffff";
        btn.style.borderColor = "#555555";
      } else {
        if (p.back !== null && p.back !== undefined) btn.style.backgroundColor = colorIntToHex(p.back);
        if (p.fg !== null && p.fg !== undefined) btn.style.color = colorIntToHex(p.fg);
      }
      if (p.Bold) btn.style.fontWeight = "bold";
      if (p.FontSize) btn.style.fontSize = p.FontSize + "px";
      if (p.FontType) btn.style.fontFamily = p.FontType;
      if (p.isNew) btn.classList.add("prod-new");
      btn.innerHTML = escapeHtml(capitalizeName(p.Denumire || "(produs nou)"));
      btn.onclick = () => {
        prodSelected = p.key;
        renderProduseGrid();
        renderProduseInspector();
      };
      if (p.key === prodSelected) btn.classList.add("prod-selected");
    } else {
      btn.classList.add("prod-empty");
      btn.innerHTML = `<span class="prod-slot-nr">${poz}</span><span>+</span>`;
      btn.onclick = () => {
        const nr = prodDefaultRow(poz, prodCurGrup);
        prodWorking[nr.key] = nr;
        prodSelected = nr.key;
        renderProduseGrid();
        renderProduseInspector();
      };
    }
    grid.appendChild(btn);
  }
}

function renderProdSwatches(containerId, getVal, setVal) {
  const wrap = document.getElementById(containerId);
  if (!wrap) return;
  wrap.innerHTML = "";
  MESE_PALETTE.forEach(pal => {
    const s = document.createElement("div");
    if (pal.auto) {
      s.className = "mese-swatch auto-swatch";
      s.title = "Implicit";
      s.innerHTML = "A";
    } else {
      s.className = "mese-swatch";
      s.style.background = pal.v;
    }
    if ((getVal() === null || getVal() === undefined) && pal.auto) s.classList.add("sel");
    else if (!pal.auto && getVal() === colorHexToInt(pal.v)) s.classList.add("sel");
    s.onclick = () => {
      setVal(pal.auto ? null : colorHexToInt(pal.v));
      const row = prodWorking[prodSelected];
      if (row) markProdDirty(row.key);
      renderProduseGrid();
      renderProduseInspector();
    };
    wrap.appendChild(s);
  });
}

function setProdCustom(which) {
  const el = document.getElementById(which === "bg" ? "prod-bg-custom" : "prod-fg-custom");
  const row = prodWorking[prodSelected];
  if (!el || !row) return;
  const val = colorHexToInt(el.value);
  if (which === "bg") row.back = val; else row.fg = val;
  markProdDirty(row.key);
  renderProduseGrid();
  renderProduseInspector();
}

function markProdDirty(key) {
  prodDirty[key] = true;
  const badge = document.getElementById("prod-dirty-badge");
  if (badge) badge.style.display = "inline";
}

function prodFieldValue(id, fallback) {
  const el = document.getElementById(id);
  return el ? el.value : fallback;
}

function setProdField(id, value) {
  const el = document.getElementById(id);
  if (el && value !== null && value !== undefined) el.value = value;
}

function renderProduseInspector() {
  const title = document.getElementById("prod-insp-title");
  const dirtyBadge = document.getElementById("prod-dirty-badge");
  const emptyMsg = document.getElementById("prod-empty-msg");
  const form = document.getElementById("prod-form");
  const row = prodSelected === null ? null : prodWorking[prodSelected];

  if (!row) {
    if (title) title.innerText = tr("PRODUS");
    if (dirtyBadge) dirtyBadge.style.display = "none";
    if (emptyMsg) emptyMsg.style.display = "block";
    if (form) form.style.display = "none";
    return;
  }

  const saveBtn = document.getElementById("prod-save-btn");
  const delBtn = document.getElementById("prod-del-btn");
  saveBtn.onclick = saveProdusSelected;

  document.getElementById("prod-insp-title").innerText = row.isNew ? tr("PRODUS NOU") : (tr("PRODUS ") + row.ProdID);
  dirtyBadge.style.display = prodDirty[row.key] ? "inline" : "none";
  emptyMsg.style.display = "none";
  form.style.display = "block";

  document.getElementById("prod-key-input").readOnly = !row.isNew;
  setProdField("prod-key-input", row.ProdID);
  setProdField("prod-barcod-input", row.BarCod);
  setProdField("prod-den-input", row.Denumire);
  setProdField("prod-um-input", row.UM);
  if (document.getElementById("prod-grup-field")) document.getElementById("prod-grup-field").value = String(row.NrGrp);
  const kpSel = document.getElementById("prod-kp-select");
  if (kpSel) kpSel.value = (row.KP === null || row.KP === undefined) ? "" : String(row.KP);
  if (document.getElementById("prod-sectie-select")) document.getElementById("prod-sectie-select").value = String(row.Sectie);
  if (document.getElementById("prod-tva-select")) document.getElementById("prod-tva-select").value = String(row.Nr_TVA);
  setProdField("prod-pv-input", row.PV);
  setProdField("prod-poz-input", row.Poz);
  setProdField("prod-size-input", row.FontSize);
  setProdField("prod-font-input", row.FontType);
  setProdField("prod-imagine-input", row.Imagine);
  setProdField("prod-irp-input", row.IRP);
  setProdField("prod-iivcn-input", row.IIVCN);

  const magIdVal = (row.MagID === null || row.MagID === undefined || row.MagID === "")
    ? (prodMagIdList.length ? prodMagIdList[0] : "")
    : row.MagID;
  const magSel = document.getElementById("prod-magid-select");
  const magInp = document.getElementById("prod-magid-input");
  if (magSel) {
    if (magIdVal !== "" && !Array.from(magSel.options).some(o => o.value === String(magIdVal))) {
      const opt = document.createElement("option");
      opt.value = String(magIdVal);
      opt.textContent = String(magIdVal) + " (produs)";
      magSel.appendChild(opt);
    }
    magSel.value = (magIdVal === "" || magIdVal === null) ? "" : String(magIdVal);
  }
  if (magInp) magInp.value = (magIdVal === null || magIdVal === undefined) ? "" : String(magIdVal);
  document.getElementById("prod-bg-custom").value = colorIntToHex(row.back !== null && row.back !== undefined ? row.back : 0xf0f0f0) || "#ffffff";
  document.getElementById("prod-fg-custom").value = colorIntToHex(row.fg !== null && row.fg !== undefined ? row.fg : 0x000000) || "#000000";

  renderMeseToggle("prod-bold-toggle", !!row.Bold, "Da", "Nu");
  renderMeseToggle("prod-mz-toggle", !!row.MZ, "Da", "Nu");
  renderProdSwatches("prod-bg-swatches", () => row.back, v => { row.back = v; });
  renderProdSwatches("prod-fg-swatches", () => row.fg, v => { row.fg = v; });

  delBtn.disabled = row.isNew;
  saveBtn.disabled = false;
}

function toggleProdBold() {
  const row = prodWorking[prodSelected];
  if (!row) return;
  row.Bold = !row.Bold;
  markProdDirty(row.key);
  renderProduseGrid();
  renderProduseInspector();
}

function toggleProdMz() {
  const row = prodWorking[prodSelected];
  if (!row) return;
  row.MZ = !row.MZ;
  markProdDirty(row.key);
  renderProduseInspector();
}

function markProdDirtyFromField() {
  const row = prodWorking[prodSelected];
  if (row) markProdDirty(row.key);
}

function syncProdFromInputs() {
  const row = prodWorking[prodSelected];
  if (!row) return;
  row.ProdID = prodFieldValue("prod-key-input", "");
  row.BarCod = prodFieldValue("prod-barcod-input", "");
  row.Denumire = prodFieldValue("prod-den-input", "");
  row.UM = prodFieldValue("prod-um-input", "");
  row.NrGrp = prodNum(document.getElementById("prod-grup-field") ? document.getElementById("prod-grup-field").value : row.NrGrp);
  row.KP = prodNum(document.getElementById("prod-kp-select") ? document.getElementById("prod-kp-select").value : "");
  row.Sectie = prodNum(document.getElementById("prod-sectie-select") ? document.getElementById("prod-sectie-select").value : "");
  row.Nr_TVA = prodNum(document.getElementById("prod-tva-select") ? document.getElementById("prod-tva-select").value : "");
  row.PV = prodNum(document.getElementById("prod-pv-input") ? document.getElementById("prod-pv-input").value : "");
  row.Poz = prodNum(document.getElementById("prod-poz-input") ? document.getElementById("prod-poz-input").value : "");
  row.FontSize = prodNum(document.getElementById("prod-size-input") ? document.getElementById("prod-size-input").value : "");
  row.FontType = prodFieldValue("prod-font-input", "");
  row.Imagine = prodFieldValue("prod-imagine-input", "");
  row.IRP = prodNum(document.getElementById("prod-irp-input") ? document.getElementById("prod-irp-input").value : "");
  row.IIVCN = prodNum(document.getElementById("prod-iivcn-input") ? document.getElementById("prod-iivcn-input").value : "");
  const magSel = document.getElementById("prod-magid-select");
  const magInp = document.getElementById("prod-magid-input");
  if (magSel) row.MagID = prodNum(magSel.value);
  else if (magInp) row.MagID = prodNum(magInp.value);
}

function bindProdInputs() {
  const textIds = ["prod-key-input", "prod-barcod-input", "prod-den-input", "prod-um-input",
    "prod-pv-input", "prod-poz-input", "prod-size-input", "prod-font-input",
    "prod-imagine-input", "prod-irp-input", "prod-iivcn-input"];
  textIds.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.oninput = () => {
      const row = prodWorking[prodSelected];
      if (!row) return;
      syncProdFromInputs();
      markProdDirty(row.key);
      renderProduseGrid();
    };
  });
  ["prod-grup-field", "prod-sectie-select", "prod-tva-select"].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.onchange = () => {
      const row = prodWorking[prodSelected];
      if (!row) return;
      syncProdFromInputs();
      markProdDirty(row.key);
      renderProduseGrid();
    };
  });
  document.getElementById("prod-bg-custom").oninput = () => setProdCustom("bg");
  document.getElementById("prod-fg-custom").oninput = () => setProdCustom("fg");
}

async function saveProdusSelected() {
  const row = prodWorking[prodSelected];
  if (!row) return;
  syncProdFromInputs();

  const prodId = row.isNew ? parseInt(prodFieldValue("prod-key-input", ""), 10) : row.ProdID;
  const barcod = String(row.BarCod || "").trim();
  const denumire = String(row.Denumire || "").trim();
  const um = String(row.UM || "").trim();

  if (isNaN(prodId) || prodId <= 0) {
    showToast("Introduceti un ProdID intreg > 0");
    return;
  }
  if (barcod === "") { showToast("BarCod obligatoriu"); return; }
  if (denumire === "") { showToast("Denumire obligatorie"); return; }
  if (um === "") { showToast("UM obligatorie"); return; }
  if (row.NrGrp === null || row.NrGrp <= 0) { showToast("Alegeti grupa"); return; }
  if (row.Poz === null || row.Poz < 1 || row.Poz > 50) { showToast("Poz trebuie sa fie intre 1 si 50"); return; }

  const payload = {
    action: row.isNew ? "insert" : "update",
    ProdID: prodId,
    BarCod: barcod,
    Denumire: denumire,
    UM: um,
    IRP: row.IRP === null ? "" : row.IRP,
    IIVCN: row.IIVCN === null ? "" : row.IIVCN,
    NrGrp: row.NrGrp,
    KP: row.KP === null ? "" : row.KP,
    BackColor: row.back === null ? "" : row.back,
    FontColor: row.fg === null ? "" : row.fg,
    FontSize: row.FontSize === null ? "" : row.FontSize,
    Bold: row.Bold ? 1 : 0,
    Poz: row.Poz,
    FontType: row.FontType || "",
    Imagine: row.Imagine || "",
    Sectie: row.Sectie === null ? "" : row.Sectie,
    PV: row.PV === null ? "" : row.PV,
    Nr_TVA: row.Nr_TVA === null ? "" : row.Nr_TVA,
    MZ: row.MZ ? 1 : 0,
    MagID: (row.MagID === null || row.MagID === undefined) ? "" : row.MagID
  };

  try {
    const resp = await fetch("api/produse.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message);
    showToast(res.message);
    prodCurGrup = row.NrGrp;
    prodSelected = null;
    loadProdGrupes();
    loadMenu();
  } catch (err) {
    appAlert("Eroare salvare produs: " + err.message);
  }
}

function deleteProdusSelected() {
  const row = prodWorking[prodSelected];
  if (!row || row.isNew) return;
  appConfirm("Stergeti produsul " + row.ProdID + "?", async () => {
    try {
      const resp = await fetch("api/produse.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "delete", ProdID: row.ProdID })
      });
      const res = await resp.json();
      if (res.status !== "success") throw new Error(res.message);
      showToast(res.message);
      prodSelected = null;
      loadProdGroup();
      loadMenu();
    } catch (err) {
      appAlert("Eroare stergere produs: " + err.message);
    }
  }, "Da", "Nu");
}

document.addEventListener("DOMContentLoaded", bindProdInputs);

// --------------------------------------------------------------------------
// ANTET (tblAntet, doar linia cu Seria = '0001')
// --------------------------------------------------------------------------
const ANTET_FIELDS = [
  { key: "Nume", id: "antet-nume" },
  { key: "Denumire2", id: "antet-den2" },
  { key: "Adresa", id: "antet-adresa" },
  { key: "CodFiscF", id: "antet-codfisc" },
  { key: "RegCom", id: "antet-regcom" },
  { key: "Judet", id: "antet-judet" },
  { key: "Cont", id: "antet-cont" },
  { key: "Banca", id: "antet-banca" },
  { key: "Oras", id: "antet-oras" },
  { key: "CodPostal", id: "antet-codpostal" },
  { key: "PersContact", id: "antet-perscontact" },
  { key: "email", id: "antet-email" },
  { key: "website", id: "antet-website" },
  { key: "telefon", id: "antet-telefon" },
  { key: "CaleDateLogo", id: "antet-calelogo" },
  { key: "QRCodeText", id: "antet-qrcode" }
];

let antetPlatitorTVA = 0;

function openProgAntet() {
  navigateToScreen("screen-prog-antet");
  loadAntet();
}

function closeProgAntet() {
  navigateToScreen("screen-programare");
}

async function loadAntet() {
  try {
    const resp = await fetch("api/antet.php");
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message || "Eroare");
    const row = data.row || {};
    ANTET_FIELDS.forEach(f => {
      const el = document.getElementById(f.id);
      if (el) el.value = row[f.key] || "";
    });
    const sizeEl = document.getElementById("antet-sizemode");
    if (sizeEl) sizeEl.value = (row.SizeModeLogo !== undefined && row.SizeModeLogo !== null) ? row.SizeModeLogo : "";
    antetPlatitorTVA = row.PlatitorTVA ? 1 : 0;
    renderAntetPlatitor();
  } catch (err) {
    appAlert("Eroare antet: " + err.message);
  }
}

function renderAntetPlatitor() {
  const btn = document.getElementById("antet-platitor-toggle");
  if (!btn) return;
  btn.innerText = antetPlatitorTVA ? tr("Da") : tr("Nu");
  btn.style.background = antetPlatitorTVA ? "#008000" : "#ffff00";
  btn.style.color = antetPlatitorTVA ? "#ffffff" : "#000000";
}

function toggleAntetPlatitor() {
  antetPlatitorTVA = antetPlatitorTVA ? 0 : 1;
  renderAntetPlatitor();
}

async function saveAntet() {
  const payload = { action: "update", PlatitorTVA: antetPlatitorTVA };
  ANTET_FIELDS.forEach(f => {
    const el = document.getElementById(f.id);
    payload[f.key] = el ? el.value.trim() : "";
  });
  const sizeEl = document.getElementById("antet-sizemode");
  payload.SizeModeLogo = sizeEl ? (parseInt(sizeEl.value, 10) || 0) : 0;

  try {
    const resp = await fetch("api/antet.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message);
    showToast(res.message);
  } catch (err) {
    appAlert("Eroare salvare antet: " + err.message);
  }
}

// --------------------------------------------------------------------------
// NOTA (tblAntet, liniile H1-H3 si F1-F2)
// --------------------------------------------------------------------------
const NOTA_LABELS = {
  H1: "Header 1",
  H2: "Header 2",
  H3: "Header 3",
  F1: "Footer 1",
  F2: "Footer 2",
  P1: "Footer Proforma 1 (doar proforma)",
  P2: "Footer Proforma 2 (doar proforma)"
};

// Fonturi ESC/POS (comanda ESC M n). Valoarea salvata in tblAntet.NumeFont
// este tokenul folosit de driverul de printare (A/B/C/D/E).
const NOTA_FONTS = [
  { value: "A", label: "Font A (12x24) - normal" },
  { value: "B", label: "Font B (9x17) - condensat" },
  { value: "C", label: "Font C (9x24)" },
  { value: "D", label: "Font D (9x32)" },
  { value: "E", label: "Font E (8x16) - foarte mic" }
];

// Magnitudini ESC/POS. Valoarea salvata in tblAntet.Size este chiar parametrul
// comenzii GS ! n: bits 0-2 = inaltime-1, bits 4-6 = latime-1 (1..8x).
const NOTA_SIZES = [
  { value: 0,  label: "1x1 - normal" },
  { value: 16, label: "2x1 - lat" },
  { value: 1,  label: "1x2 - inalt" },
  { value: 17, label: "2x2 - dublu" },
  { value: 34, label: "3x3 - triplu" }
];

let notaRows = [];

function openProgNota() {
  navigateToScreen("screen-prog-nota");
  loadNota();
}

function closeProgNota() {
  navigateToScreen("screen-programare");
}

async function loadNota() {
  try {
    const resp = await fetch("api/nota.php");
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message || "Eroare");
    notaRows = data.rows || [];
    renderNota();
  } catch (err) {
    appAlert("Eroare nota: " + err.message);
  }
}

// Optiunile combobox-ului de fonturi ESC/POS. Orice valoare veche (nume de
// font Windows) este ignorata si inlocuita cu Font A.
function notaFontOptions(selected) {
  const val = NOTA_FONTS.some(f => f.value === selected) ? selected : "A";
  return NOTA_FONTS.map(f =>
    '<option value="' + f.value + '"' + (f.value === val ? " selected" : "") + '>' +
    escapeHtml(tr(f.label)) + '</option>'
  ).join("");
}

// Optiunile combobox-ului de magnitudini. Valorile vechi (puncte Windows) sunt
// ignorate si inlocuite cu 1x1 (normal).
function notaSizeOptions(selected) {
  const val = NOTA_SIZES.some(s => s.value === selected) ? selected : 0;
  return NOTA_SIZES.map(s =>
    '<option value="' + s.value + '"' + (s.value === val ? " selected" : "") + '>' +
    escapeHtml(tr(s.label)) + '</option>'
  ).join("");
}

function renderNota() {
  const wrap = document.getElementById("nota-content");
  if (!wrap) return;
  wrap.innerHTML = "";

  notaRows.forEach(r => {
    const label = tr(NOTA_LABELS[r.Seria] || r.Seria);
    const rec = document.createElement("div");
    rec.className = "nota-record";
    rec.innerHTML =
      '<div class="nota-record-title">' + escapeHtml(label) + ' (' + escapeHtml(r.Seria) + ')</div>' +
      '<div class="nota-fields">' +
        '<div class="tva-field"><label>' + tr("Nume") + '</label><div class="tva-field-row">' +
          '<input type="text" id="nota-nume-' + r.Seria + '" class="tva-input" maxlength="40">' +
          '<button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor(\'nota-nume-' + r.Seria + '\')">⌨️</button>' +
        '</div></div>' +
        '<div class="tva-field"><label>' + tr("NumeFont (ESC/POS)") + '</label>' +
          '<select id="nota-font-' + r.Seria + '" class="tva-input">' + notaFontOptions(r.NumeFont) + '</select>' +
        '</div>' +
        '<div class="tva-field"><label>' + tr("Size (ESC/POS)") + '</label>' +
          '<select id="nota-size-' + r.Seria + '" class="tva-input">' + notaSizeOptions(r.Size) + '</select>' +
        '</div>' +
        '<div class="tva-field"><label>' + tr("Bold") + '</label>' +
          '<button class="act-btn" id="nota-bold-' + r.Seria + '" onclick="toggleNotaBold(\'' + r.Seria + '\')" style="width:100%;">' + tr("Nu") + '</button>' +
        '</div>' +
      '</div>';
    wrap.appendChild(rec);

    document.getElementById("nota-nume-" + r.Seria).value = r.Nume || "";
    document.getElementById("nota-font-" + r.Seria).value = r.NumeFont || "";
    document.getElementById("nota-size-" + r.Seria).value = (r.Size !== undefined && r.Size !== null) ? r.Size : "";
    renderNotaBold(r.Seria);
  });
}

function renderNotaBold(seria) {
  const btn = document.getElementById("nota-bold-" + seria);
  const row = notaRows.find(x => x.Seria === seria);
  if (!btn || !row) return;
  const on = !!row.Bold;
  btn.innerText = on ? tr("Da") : tr("Nu");
  btn.style.background = on ? "#008000" : "#ffff00";
  btn.style.color = on ? "#ffffff" : "#000000";
}

function toggleNotaBold(seria) {
  const row = notaRows.find(x => x.Seria === seria);
  if (!row) return;
  row.Bold = row.Bold ? 0 : 1;
  renderNotaBold(seria);
}

async function saveNota() {
  const rows = notaRows.map(r => {
    const numeEl = document.getElementById("nota-nume-" + r.Seria);
    const fontEl = document.getElementById("nota-font-" + r.Seria);
    const sizeEl = document.getElementById("nota-size-" + r.Seria);
    return {
      Seria: r.Seria,
      Nume: numeEl ? numeEl.value.trim() : "",
      NumeFont: fontEl ? fontEl.value.trim() : "",
      Size: sizeEl ? (parseInt(sizeEl.value, 10) || 0) : 0,
      Bold: r.Bold ? 1 : 0
    };
  });

  try {
    const resp = await fetch("api/nota.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "update", rows: rows })
    });
    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message);
    showToast(res.message);
  } catch (err) {
    appAlert("Eroare salvare nota: " + err.message);
  }
}

// --------------------------------------------------------------------------
// CONECTARE SERVER (tblConectare, un singur rand ID=1)
// --------------------------------------------------------------------------
const CONECTARE_FIELDS = [
  { key: "ServerIP", id: "con-serverip" },
  { key: "ServerName", id: "con-servername" },
  { key: "DataBaseName", id: "con-dbname" },
  { key: "UserName", id: "con-user" },
  { key: "Password", id: "con-pass" },
  { key: "ODBC_connect_string", id: "con-odbc" }
];

// Mapari server -> local (tblMapKP / tblMapSectii), editate in Conectare server.
let mapKpRows = [];
let mapSectiiRows = [];
let mapOptions = { localKP: [], serverKP: [], localSectii: [], serverSectii: [], serverError: null };

// Optiunile unui <select>: lista server/local, plus valoarea curenta daca nu e
// deja in lista (ca sa nu se piarda o mapare existenta).
function mapOptionListHtml(options, currentValue) {
  const cur = (currentValue === null || currentValue === undefined) ? "" : String(currentValue);
  const list = (options || []).slice();
  if (cur !== "" && !list.some(o => String(o.value) === cur)) {
    list.unshift({ value: cur, label: cur + " (fara denumire)" });
  }
  let html = '<option value=""></option>';
  list.forEach(o => {
    const sel = String(o.value) === cur ? " selected" : "";
    html += `<option value="${escapeHtml(o.value)}"${sel}>${escapeHtml(o.value)} - ${escapeHtml(o.label)}</option>`;
  });
  return html;
}

function renderMapKpList() {
  const cont = document.getElementById("map-kp-list");
  if (!cont) return;
  if (!mapKpRows.length) {
    cont.innerHTML = '<div style="color:#777;font-size:12px;">Nicio mapare (se folosesc valorile din server).</div>';
    return;
  }
  let html = '<table class="raport-table" style="width:auto;"><thead><tr>'
    + '<th>KP server</th><th>KP local</th><th></th></tr></thead><tbody>';
  mapKpRows.forEach((r, i) => {
    html += `<tr>
      <td><select class="tva-input" id="map-kp-s-${i}">${mapOptionListHtml(mapOptions.serverKP, r.server)}</select></td>
      <td><select class="tva-input" id="map-kp-l-${i}">${mapOptionListHtml(mapOptions.localKP, r.local)}</select></td>
      <td><button class="act-btn" onclick="removeMapKpRow(${i})">Sterge</button></td>
    </tr>`;
  });
  cont.innerHTML = html + '</tbody></table>';
}

function collectMapKp() {
  return mapKpRows.map((r, i) => {
    const s = document.getElementById(`map-kp-s-${i}`);
    const l = document.getElementById(`map-kp-l-${i}`);
    return { server: s ? s.value : (r.server ?? ""), local: l ? l.value : (r.local ?? "") };
  });
}

function addMapKpRow() {
  mapKpRows = collectMapKp();
  mapKpRows.push({ server: "", local: "" });
  renderMapKpList();
}

function removeMapKpRow(i) {
  mapKpRows = collectMapKp();
  mapKpRows.splice(i, 1);
  renderMapKpList();
}

function renderMapSectiiList() {
  const cont = document.getElementById("map-sectii-list");
  if (!cont) return;
  if (!mapSectiiRows.length) {
    cont.innerHTML = '<div style="color:#777;font-size:12px;">Nicio mapare (se folosesc valorile din server).</div>';
    return;
  }
  let html = '<table class="raport-table" style="width:auto;"><thead><tr>'
    + '<th>Sectie server</th><th>Sectie locala</th><th></th></tr></thead><tbody>';
  mapSectiiRows.forEach((r, i) => {
    html += `<tr>
      <td><select class="tva-input" id="map-sec-s-${i}">${mapOptionListHtml(mapOptions.serverSectii, r.server)}</select></td>
      <td><select class="tva-input" id="map-sec-l-${i}">${mapOptionListHtml(mapOptions.localSectii, r.local)}</select></td>
      <td><button class="act-btn" onclick="removeMapSectiiRow(${i})">Sterge</button></td>
    </tr>`;
  });
  cont.innerHTML = html + '</tbody></table>';
}

function collectMapSectii() {
  return mapSectiiRows.map((r, i) => {
    const s = document.getElementById(`map-sec-s-${i}`);
    const l = document.getElementById(`map-sec-l-${i}`);
    return { server: s ? s.value : (r.server ?? ""), local: l ? l.value : (r.local ?? "") };
  });
}

function addMapSectiiRow() {
  mapSectiiRows = collectMapSectii();
  mapSectiiRows.push({ server: "", local: "" });
  renderMapSectiiList();
}

function removeMapSectiiRow(i) {
  mapSectiiRows = collectMapSectii();
  mapSectiiRows.splice(i, 1);
  renderMapSectiiList();
}

// Incarca listele pentru combobox-uri (KP/Sectii de pe local si server).
async function loadMapOptions() {
  try {
    const resp = await fetch("api/conectare.php?options=1");
    const data = await resp.json();
    if (data.status === "success" && data.mapOptions) {
      mapOptions = data.mapOptions;
    }
  } catch (err) {
    /* listele optionale; se pastreaza valorile curente */
  }
  const errEl = document.getElementById("map-server-error");
  if (errEl) {
    errEl.textContent = mapOptions.serverError ? ("Listele de pe server nu s-au putut incarca: " + mapOptions.serverError) : "";
    errEl.style.display = mapOptions.serverError ? "block" : "none";
  }
  renderMapKpList();
  renderMapSectiiList();
}

// Ecranul Conectare server: evidentiaza ca valorile din formular nu sunt
// salvate pana la apasarea butonului Salveaza ("Testeaza conexiunea" foloseste
// doar ce este pe ecran, fara sa scrie in tblConectare).
function markConectareDirty() {
  const el = document.getElementById("con-dirty");
  if (el) el.style.display = "inline-block";
}

function clearConectareDirty() {
  const el = document.getElementById("con-dirty");
  if (el) el.style.display = "none";
}

// Identitatea bazei locale (server + baza) pe care lucreaza aplicatia: arata
// unde se salveaza de fapt tblConectare, ca sa nu fie confundata cu baza de pe
// serverul extern (ex. DataRON), care are propriul rand tblConectare.
function renderConectareLocal(local) {
  const el = document.getElementById("con-local-info");
  if (!el) return;
  const parts = [];
  if (local && local.server) parts.push(local.server);
  if (local && local.database) parts.push(local.database);
  el.textContent = parts.length
    ? tr("Baza locala (unde se salveaza aceste date)") + ": " + parts.join(" / ")
    : "";
}

function openProgConectare() {
  navigateToScreen("screen-prog-conectare");
  loadConectare();
}

function closeProgConectare() {
  navigateToScreen("screen-programare");
}

async function loadConectare() {
  try {
    const resp = await fetch("api/conectare.php");
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message || "Eroare");
    const row = data.row || {};
    CONECTARE_FIELDS.forEach(f => {
      const el = document.getElementById(f.id);
      if (el) el.value = row[f.key] || "";
    });
    const tcEl = document.getElementById("con-tc");
    if (tcEl) tcEl.value = row.TC ? "1" : "0";

    mapKpRows = Array.isArray(data.mapKP)
      ? data.mapKP.map(m => ({ server: m.server, local: m.local }))
      : [];
    mapSectiiRows = Array.isArray(data.mapSectii)
      ? data.mapSectii.map(m => ({ server: m.server, local: m.local }))
      : [];
    renderConectareLocal(data.local);
    clearConectareDirty();
    await loadMapOptions();
  } catch (err) {
    appAlert("Eroare conectare server: " + err.message);
  }
}

async function saveConectare() {
  const payload = { action: "update" };
  CONECTARE_FIELDS.forEach(f => {
    const el = document.getElementById(f.id);
    payload[f.key] = el ? el.value.trim() : "";
  });
  const tcEl = document.getElementById("con-tc");
  payload.TC = (tcEl && tcEl.value === "1") ? 1 : 0;
  payload.mapKP = collectMapKp();
  payload.mapSectii = collectMapSectii();

  try {
    const resp = await fetch("api/conectare.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message);
    clearConectareDirty();
    showToast(res.message);
  } catch (err) {
    appAlert("Eroare salvare conectare server: " + err.message);
  }
}

async function testConectare() {
  const payload = { action: "test" };
  CONECTARE_FIELDS.forEach(f => {
    const el = document.getElementById(f.id);
    payload[f.key] = el ? el.value.trim() : "";
  });
  const tcEl = document.getElementById("con-tc");
  payload.TC = (tcEl && tcEl.value === "1") ? 1 : 0;

  try {
    showToast("Se testeaza conexiunea...");
    const resp = await fetch("api/conectare.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message);
    appAlert(res.message);
  } catch (err) {
    appAlert("Test conexiune: " + err.message);
  }
}

// --------------------------------------------------------------------------
// PAROLE (tblParola, un singur rand Id=1)
// --------------------------------------------------------------------------
const PAROLE_FIELDS = [
  { key: "ParolaProgramare", id: "par-programare" },
  { key: "ParolaRapoarte",   id: "par-rapoarte" },
  { key: "ParolaStornare",   id: "par-stornare" },
  { key: "ParolaDiscount",   id: "par-discount" },
  { key: "ParolaExit",       id: "par-exit" },
  { key: "ParolaUpdate",     id: "par-update" }
];

function openProgParole() {
  navigateToScreen("screen-prog-parole");
  loadParole();
}

function closeProgParole() {
  navigateToScreen("screen-programare");
}

async function loadParole() {
  try {
    const resp = await fetch("api/parole.php");
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message || "Eroare");
    const row = data.row || {};
    PAROLE_FIELDS.forEach(f => {
      const el = document.getElementById(f.id);
      if (el) el.value = row[f.key] || "";
    });
  } catch (err) {
    appAlert("Eroare parole: " + err.message);
  }
}

async function saveParole() {
  const payload = { action: "update" };
  PAROLE_FIELDS.forEach(f => {
    const el = document.getElementById(f.id);
    payload[f.key] = el ? el.value.trim() : "";
  });

  try {
    const resp = await fetch("api/parole.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message);
    // Reflectam imediat daca discountul cere parola.
    POS_STATE.parolaDiscount = (String(payload.ParolaDiscount || "").trim() !== "") ? 1 : 0;
    POS_STATE.discountParola = "";
    showToast(res.message);
  } catch (err) {
    appAlert("Eroare salvare parole: " + err.message);
  }
}

// --------------------------------------------------------------------------
// RAPOARTE (taburi X / Z, accesate cu ParolaRapoarte)
// --------------------------------------------------------------------------
const REPORTS_TABS = ["X", "Z"];
const REPORTS_TYPES = ["PLU", "Grupe", "Sectii", "Casieri", "General", "Note"];
const REPORTS_Z_OPTIONS = ["PLU", "Grupe", "Sectii", "Casieri"];
let reportsActiveTab = "X";
let reportsPrevScreen = "screen-mese";

function openReports() {
  const active = document.querySelector(".pos-screen.active");
  reportsPrevScreen = active ? active.id : "screen-mese";
  reportsActiveTab = "X";
  navigateToScreen("screen-rapoarte");
  renderReportsTabs();
  renderReportsGrid();
}

function closeReports() {
  navigateToScreen(reportsPrevScreen);
}

function renderReportsTabs() {
  const wrap = document.getElementById("rapoarte-tabs");
  if (!wrap) return;
  wrap.innerHTML = "";
  REPORTS_TABS.forEach(t => {
    const b = document.createElement("button");
    b.className = "setari-tab" + (t === reportsActiveTab ? " active" : "");
    b.textContent = tr("Raport ") + t;
    b.onclick = () => {
      reportsActiveTab = t;
      renderReportsTabs();
      renderReportsGrid();
    };
    wrap.appendChild(b);
  });
}

function renderReportsGrid() {
  const wrap = document.getElementById("rapoarte-grid");
  if (!wrap) return;
  wrap.innerHTML = "";

  if (reportsActiveTab === "X") {
    // Tab X: butoane de raport
    REPORTS_TYPES.forEach(ty => {
      const b = document.createElement("button");
      b.className = "prog-btn";
      b.textContent = tr(ty);
      b.onclick = () => runReport("X", ty);
      wrap.appendChild(b);
    });
    return;
  }

  // Tab Z: checkbox-uri pentru rapoarte + buton de golire
  REPORTS_Z_OPTIONS.forEach(ty => {
    const label = document.createElement("label");
    label.className = "rapoarte-check";
    label.innerHTML = '<input type="checkbox" id="z-chk-' + ty + '"> <span>' + tr(ty) + '</span>';
    wrap.appendChild(label);
  });

  const btn = document.createElement("button");
  btn.className = "prog-btn rapoarte-z-btn";
  btn.textContent = tr("Efectueaza Golirea Z");
  btn.onclick = actionGolireZ;
  wrap.appendChild(btn);
}

function actionGolireZ() {
  const sel = REPORTS_Z_OPTIONS.filter(ty => {
    const el = document.getElementById("z-chk-" + ty);
    return el && el.checked;
  });
  // Golirea Z se face oricum; daca nu e bifat nimic se tipareste doar raportul general.
  fetch("api/rapoarte.php?tab=Z&check=1")
    .then(r => r.json())
    .then(info => {
      if (info && info.allowed === false) {
        appAlert(info.message || "Raportul Z nu poate fi efectuat.");
        return;
      }
      const mesaj = sel.length > 0
        ? "Efectuati golirea Z. Se tiparesc: General Z + " + sel.join(", ") + "?"
        : "Efectuati golirea Z (doar raportul general)?";
      appConfirm(mesaj, () => performGolireZ(sel), "Da", "Nu");
    })
    .catch(() => {
      appAlert("Nu pot verifica starea meselor.");
    });
}

async function performGolireZ(tipuri) {
  try {
    const resp = await fetch("api/rapoarte.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "z", tipuri: tipuri })
    });
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message);
    appAlert(data.message || "Raport Z efectuat.");
    refreshPrintStatus();
  } catch (err) {
    appAlert("Eroare raport Z: " + err.message);
  }
}

// Raportul curent afisat in #modal-raport (pentru butonul Tipareste)
let currentReport = null;

function reportMoney(v) {
  return (parseFloat(v) || 0).toFixed(2);
}

async function runReport(tab, tip) {
  if (tip !== "PLU" && tip !== "Grupe" && tip !== "Sectii" && tip !== "Casieri" && tip !== "General" && tip !== "Note") {
    showToast("Raport " + tab + " - " + tip + " (in curand)");
    return;
  }
  try {
    const resp = await fetch(`api/rapoarte.php?tab=${encodeURIComponent(tab)}&tip=${encodeURIComponent(tip)}`);
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message);
    currentReport = data;
    renderReportModal(data);
  } catch (err) {
    appAlert("Eroare raport: " + err.message);
  }
}

function renderReportModal(data) {
  const titleEl = document.getElementById("raport-title");
  if (titleEl) titleEl.innerText = data.title || "RAPORT";

  const content = document.getElementById("raport-content");
  if (!content) return;

  let html = `<div class="raport-meta">Generat: ${escapeHtml(data.generat || "")}</div>`;

  // Raportul de note este doar pe ecran (fara tiparire).
  const printBtn = document.getElementById("raport-print-btn");
  if (printBtn) printBtn.style.display = (data.tip === "NOTE") ? "none" : "";

  if (data.tip === "NOTE") {
    const note = data.note || [];
    html += '<table class="raport-table"><thead><tr>' +
            '<th>NrNota</th><th>Masa</th><th>Ospatar</th><th>Ora</th>' +
            '<th class="num">Reducere</th><th>Motiv reducere</th>' +
            '<th class="num">Stornari</th><th>Plati</th><th class="num">Total</th>' +
            '</tr></thead><tbody>';
    note.forEach(n => {
      const platiTxt = (n.plati || []).map(p => escapeHtml(p.denumire || "") + " " + reportMoney(p.suma)).join(" / ");
      html += `<tr class="raport-row-click" onclick="openNoteDetail(${n.docId})">` +
              `<td>${n.nrNota}</td><td>${n.nrMasa}</td>` +
              `<td>${escapeHtml(n.ospatar || "")}</td>` +
              `<td>${escapeHtml(n.dataOra || "")}</td>` +
              `<td class="num">${reportMoney(n.reducere)}</td>` +
              `<td>${escapeHtml(n.motivDiscount || "")}</td>` +
              `<td class="num">${reportMoney(n.stornari)}</td>` +
              `<td>${platiTxt}</td>` +
              `<td class="num">${reportMoney(n.total)}</td></tr>`;
    });
    html += `<tr class="raport-total"><td colspan="4">TOTAL (${data.totalBonuri || 0} note)</td>` +
            `<td class="num">${reportMoney(data.totalReducere)}</td>` +
            `<td></td>` +
            `<td class="num">${reportMoney(data.totalStornari)}</td>` +
            `<td></td>` +
            `<td class="num">${reportMoney(data.totalValoare)}</td></tr>`;
    html += '</tbody></table>';
    if (note.length === 0) {
      html += '<div style="padding:14px; color:#888;">Nu exista note inchise in sesiunea curenta.</div>';
    }
    content.innerHTML = html;
    if (typeof window.applyI18nText === "function") window.applyI18nText(content);
    document.getElementById("modal-raport").classList.add("active");
    return;
  }

  if (data.tip === "GENERAL") {
    html += '<table class="raport-table"><tbody>';
    html += `<tr><td>Numar bonuri</td><td class="num">${data.totalBonuri || 0}</td></tr>`;
    html += `<tr><td>Discount</td><td class="num">${reportMoney(data.totalDiscount)}</td></tr>`;
    html += `<tr><td>Stornari</td><td class="num">${reportMoney(data.totalStornari)}</td></tr>`;
    html += `<tr class="raport-total"><td>TOTAL</td><td class="num">${reportMoney(data.totalValoare)}</td></tr>`;
    html += '</tbody></table>';

    html += '<div class="raport-sect" style="padding:6px 8px;">Forme de plata</div>';
    html += '<table class="raport-table"><thead><tr><th>Forma de plata</th><th class="num">Suma</th></tr></thead><tbody>';
    (data.plati || []).forEach(p => {
      html += `<tr><td>${escapeHtml(p.denumire || "")}</td>` +
              `<td class="num">${reportMoney(p.suma)}</td></tr>`;
    });
    html += '</tbody></table>';

    html += '<div class="raport-sect" style="padding:6px 8px;">TVA pe cote</div>';
    html += '<table class="raport-table"><thead><tr>' +
            '<th>Cota</th><th class="num">Baza</th><th class="num">TVA</th>' +
            '</tr></thead><tbody>';
    (data.tva || []).forEach(t => {
      const cota = (parseFloat(t.cota) || 0).toFixed(2).replace(/\.?0+$/, "");
      html += `<tr><td>${cota}%</td>` +
              `<td class="num">${reportMoney(t.baza)}</td>` +
              `<td class="num">${reportMoney(t.suma)}</td></tr>`;
    });
    html += '</tbody></table>';

    html += '<div class="raport-sect" style="padding:6px 8px;">Lista note</div>';
    html += '<table class="raport-table"><thead><tr>' +
            '<th>NrNota</th><th>Masa</th><th>Ospatar</th><th class="num">Total</th>' +
            '</tr></thead><tbody>';
    (data.note || []).forEach(n => {
      html += `<tr><td>${n.nrNota}</td><td>${n.nrMasa}</td>` +
              `<td>${escapeHtml(n.ospatar || "")}</td>` +
              `<td class="num">${reportMoney(n.total)}</td></tr>`;
    });
    html += '</tbody></table>';

    content.innerHTML = html;
    if (typeof window.applyI18nText === "function") window.applyI18nText(content);
    document.getElementById("modal-raport").classList.add("active");
    return;
  }

  if (data.tip === "CASIERI") {
    const casieri = data.casieri || [];
    if (casieri.length === 0) {
      html += '<div style="padding:14px; color:#888;">Nu exista date pentru raport.</div>';
    }
    casieri.forEach(c => {
      html += '<table class="raport-table"><tbody>';
      html += `<tr class="raport-sect"><td colspan="2">${escapeHtml(c.casier || "")}</td></tr>`;
      html += `<tr><td>Bonuri</td><td class="num">${c.nrBonuri || 0}</td></tr>`;
      html += `<tr><td>Reducere</td><td class="num">${reportMoney(c.reducere)}</td></tr>`;
      html += `<tr><td>Stornari</td><td class="num">${reportMoney(c.stornari)}</td></tr>`;
      (c.plati || []).forEach(p => {
        html += `<tr><td>${escapeHtml(p.denumire || "")}</td>` +
                `<td class="num">${reportMoney(p.suma)}</td></tr>`;
      });
      html += `<tr class="raport-sub"><td>Total casier</td>` +
              `<td class="num">${reportMoney(c.total)}</td></tr>`;
      html += '</tbody></table>';
    });

    html += '<table class="raport-table"><tbody>';
    html += `<tr class="raport-total"><td>TOTAL Bonuri</td><td class="num">${data.totalBonuri || 0}</td></tr>`;
    html += `<tr><td>Total Reducere</td><td class="num">${reportMoney(data.totalReducere)}</td></tr>`;
    html += `<tr><td>Total Stornari</td><td class="num">${reportMoney(data.totalStornari)}</td></tr>`;
    (data.totalPlati || []).forEach(p => {
      html += `<tr><td>${escapeHtml(p.denumire || "")}</td>` +
              `<td class="num">${reportMoney(p.suma)}</td></tr>`;
    });
    html += `<tr class="raport-total"><td>TOTAL</td>` +
            `<td class="num">${reportMoney(data.totalValoare)}</td></tr>`;
    html += '</tbody></table>';

    content.innerHTML = html;
    if (typeof window.applyI18nText === "function") window.applyI18nText(content);
    document.getElementById("modal-raport").classList.add("active");
    return;
  }

  const isList = data.tip === "GRUPE" || data.tip === "SECTII";
  if (isList) {
    const labelKey = data.tip === "GRUPE" ? "grupa" : "sectie";
    const labelHead = data.tip === "GRUPE" ? "Grupa" : "Sectie";
    const items = data.grupe || data.sectii || [];
    html += '<table class="raport-table"><thead><tr>' +
            `<th>${labelHead}</th><th class="num">Valoare</th>` +
            '</tr></thead><tbody>';
    items.forEach(it => {
      html += `<tr><td>${escapeHtml(it[labelKey] || "")}</td>` +
              `<td class="num">${reportMoney(it.valoare)}</td></tr>`;
    });
    html += `<tr class="raport-total"><td>TOTAL</td>` +
            `<td class="num">${reportMoney(data.totalValoare)}</td></tr>`;
    html += '</tbody></table>';
    if (items.length === 0) {
      html += '<div style="padding:14px; color:#888;">Nu exista date pentru raport.</div>';
    }
    content.innerHTML = html;
    if (typeof window.applyI18nText === "function") window.applyI18nText(content);
    document.getElementById("modal-raport").classList.add("active");
    return;
  }

  html += '<table class="raport-table"><thead><tr>' +
          '<th>Produs</th><th class="num">Cant</th><th class="num">Valoare</th>' +
          '</tr></thead><tbody>';

  const sectii = data.sectii || [];
  sectii.forEach(sec => {
    const label = sec.sectie || "";
    html += `<tr class="raport-sect"><td colspan="3">${escapeHtml(label)}</td></tr>`;
    (sec.rows || []).forEach(r => {
      html += `<tr><td>${escapeHtml(r.produs)}</td>` +
              `<td class="num">${reportMoney(r.cant)}</td>` +
              `<td class="num">${reportMoney(r.valoare)}</td></tr>`;
    });
    html += `<tr class="raport-sub"><td>Subtotal ${escapeHtml(label)}</td>` +
            `<td class="num">${reportMoney(sec.subtotalCant)}</td>` +
            `<td class="num">${reportMoney(sec.subtotalValoare)}</td></tr>`;
  });

  html += `<tr class="raport-total"><td>TOTAL</td>` +
          `<td class="num">${reportMoney(data.totalCant)}</td>` +
          `<td class="num">${reportMoney(data.totalValoare)}</td></tr>`;
  html += '</tbody></table>';

  if (sectii.length === 0) {
    html += '<div style="padding:14px; color:#888;">Nu exista date pentru raport.</div>';
  }

  content.innerHTML = html;
  if (typeof window.applyI18nText === "function") window.applyI18nText(content);
  document.getElementById("modal-raport").classList.add("active");
}

function closeReportModal() {
  document.getElementById("modal-raport").classList.remove("active");
}

// --------------------------------------------------------------------------
// Detaliul unei note inchise (raportul "Note")
// --------------------------------------------------------------------------
async function openNoteDetail(docId) {
  const content = document.getElementById("nota-det-content");
  const titleEl = document.getElementById("nota-det-title");
  if (!content) return;

  content.innerHTML = '<div style="padding:14px; color:#888;">Se incarca...</div>';
  document.getElementById("modal-nota-detail").classList.add("active");
  try {
    const resp = await fetch(`api/rapoarte.php?tab=X&tip=NOTE&docId=${encodeURIComponent(docId)}`);
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message);
    if (titleEl) titleEl.innerText = tr("msg.nota_masa", { nota: data.nrNota, masa: data.nrMasa });
    content.innerHTML = renderNoteDetailHtml(data);
  } catch (err) {
    content.innerHTML = '<div style="padding:14px; color:#b00000;">Eroare: ' + escapeHtml(err.message) + '</div>';
  }
}

function renderNoteDetailHtml(d) {
  let html = '<div class="raport-meta">' +
    escapeHtml(d.ospatar || "") + ' &middot; ' + escapeHtml(d.dataOra || "") +
    ' &middot; ' + (d.articole || []).length + ' pozitii</div>';
  if (d.motivDiscount) {
    html += '<div class="raport-meta">Motiv reducere nota: <b>' + escapeHtml(d.motivDiscount) + '</b></div>';
  }

  html += '<table class="raport-table"><thead><tr>' +
          '<th>Produs</th><th class="num">Cant</th><th class="num">Pret</th>' +
          '<th class="num">Valoare</th></tr></thead><tbody>';
  (d.articole || []).forEach(a => {
    const rowCls = a.storno ? "nota-det-storno" : "";
    const name = escapeHtml(a.denumire || "") + (a.storno ? ' <span class="nota-det-tag">ANULARE</span>' : '');
    html += `<tr class="${rowCls}"><td>${name}</td>` +
            `<td class="num">${reportMoney(a.cantitate)}</td>` +
            `<td class="num">${reportMoney(a.pretUnitar)}</td>` +
            `<td class="num">${reportMoney(a.valoare)}</td></tr>`;
    // Doar reducerile PE LINIE se evidentiaza sub produs (cu motivul lor);
    // reducerea pe subtotal se arata la nivelul notei (antet + total Reducere).
    if (!a.storno && a.discount > 0 && a.comment) {
      html += `<tr class="nota-det-disc"><td colspan="3">Discount linie: ${escapeHtml(a.comment)}</td>` +
              `<td class="num">-${reportMoney(a.discount)}</td></tr>`;
    }
    if (a.storno && a.comment) {
      html += `<tr class="nota-det-disc"><td colspan="3">Motiv anulare: ${escapeHtml(a.comment)}</td><td></td></tr>`;
    }
    (a.mods || []).forEach(m => {
      html += `<tr class="nota-det-mod"><td colspan="4">+ ${escapeHtml(m.text || "")}</td></tr>`;
    });
  });
  html += `<tr class="raport-sub"><td colspan="3">Subtotal</td>` +
          `<td class="num">${reportMoney(d.subtotal)}</td></tr>`;
  html += `<tr><td colspan="3">Reducere</td>` +
          `<td class="num">${reportMoney(d.reducere)}</td></tr>`;
  html += `<tr class="raport-total"><td colspan="3">TOTAL</td>` +
          `<td class="num">${reportMoney(d.total)}</td></tr>`;
  html += '</tbody></table>';

  html += '<div class="raport-sect" style="padding:6px 8px;">Forme de plata</div>';
  html += '<table class="raport-table"><tbody>';
  (d.plati || []).forEach(p => {
    html += `<tr><td>${escapeHtml(p.denumire || "")}</td>` +
            `<td class="num">${reportMoney(p.suma)}</td></tr>`;
  });
  if ((d.plati || []).length === 0) {
    html += '<tr><td colspan="2" style="color:#888;">Fara plati inregistrate</td></tr>';
  }
  html += '</tbody></table>';

  return html;
}

function closeNoteDetail() {
  document.getElementById("modal-nota-detail").classList.remove("active");
}

async function printCurrentReport() {
  if (!currentReport) return;
  try {
    const resp = await fetch("api/rapoarte.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "print", tab: currentReport.tab, tip: currentReport.tip })
    });
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message);
    showToast(data.message || "Raport trimis la tiparire");
    refreshPrintStatus();
  } catch (err) {
    appAlert("Eroare tiparire raport: " + err.message);
  }
}

// --------------------------------------------------------------------------
// COTE TVA (tblTVA: Nr_TVA, Cota) - insert / update / delete
// --------------------------------------------------------------------------
let tvaRows = [];
let tvaSelectedKey = null;
let tvaMode = "insert";
let tvaActive = "key";

function openTVAEditor() {
  document.getElementById("modal-tva").classList.add("active");
  loadTVARows();
}

function closeTVAModal() {
  document.getElementById("modal-tva").classList.remove("active");
  tvaSelectedKey = null;
}

function loadTVARows() {
  fetch("api/tva.php")
    .then(r => r.json())
    .then(data => {
      if (data.status !== "success") throw new Error(data.message || "Eroare");
      tvaRows = data.rows || [];
      renderTVAList();
    })
    .catch(err => showToast("Eroare TVA: " + err.message));
}

function renderTVAList() {
  const list = document.getElementById("tva-list");
  if (!list) return;
  list.innerHTML = "";

  if (tvaRows.length === 0) {
    list.innerHTML = '<div class="tva-row" style="color:#888;">Nu exista cote TVA</div>';
  }

  tvaRows.forEach(r => {
    const row = document.createElement("div");
    row.className = `tva-row ${r.Nr_TVA === tvaSelectedKey ? "selected" : ""}`;
    row.innerHTML = `
      <span><b>${r.Nr_TVA}</b></span>
      <span class="tva-val">${r.Cota}</span>
    `;
    row.onclick = () => {
      tvaSelectedKey = r.Nr_TVA;
      renderTVAList();
      updateTVABtns();
    };
    list.appendChild(row);
  });

  updateTVABtns();
}

function updateTVABtns() {
  const hasSel = tvaSelectedKey !== null;
  const ed = document.getElementById("btn-tva-edit");
  const del = document.getElementById("btn-tva-del");
  if (ed) ed.disabled = !hasSel;
  if (del) del.disabled = !hasSel;
}

function openTVAInsert() {
  tvaMode = "insert";
  const keyInput = document.getElementById("tva-key-input");
  const valInput = document.getElementById("tva-val-input");
  keyInput.readOnly = false;
  keyInput.value = "";
  valInput.value = "";
  document.getElementById("tva-edit-title").innerText = tr("COTA TVA NOUA");
  keyInput.onfocus = () => { tvaActive = "key"; };
  valInput.onfocus = () => { tvaActive = "val"; };
  tvaActive = "key";
  document.getElementById("modal-tva-edit").classList.add("active");
  keyInput.focus();
}

function openTVAEdit() {
  if (tvaSelectedKey === null) {
    showToast("Selectati mai intai o cota");
    return;
  }
  const found = tvaRows.find(r => r.Nr_TVA === tvaSelectedKey);
  if (!found) return;

  tvaMode = "update";
  const keyInput = document.getElementById("tva-key-input");
  const valInput = document.getElementById("tva-val-input");
  keyInput.readOnly = true;
  keyInput.value = found.Nr_TVA;
  valInput.value = found.Cota;
  document.getElementById("tva-edit-title").innerText = tr("EDITARE COTA TVA");
  keyInput.onfocus = () => { tvaActive = "key"; };
  valInput.onfocus = () => { tvaActive = "val"; };
  tvaActive = "val";
  document.getElementById("modal-tva-edit").classList.add("active");
  valInput.focus();
}

function closeTVAEdit() {
  document.getElementById("modal-tva-edit").classList.remove("active");
}

function tvaActiveInput() {
  const keyInput = document.getElementById("tva-key-input");
  const valInput = document.getElementById("tva-val-input");
  return tvaActive === "key" && !keyInput.readOnly ? keyInput : valInput;
}

function tvaNumKey(ch) {
  const el = tvaActiveInput();
  if (!el) return;
  el.value += ch;
  el.focus();
}

function tvaBackspace() {
  const el = tvaActiveInput();
  if (!el) return;
  el.value = el.value.slice(0, -1);
  el.focus();
}

function tvaClear() {
  const el = tvaActiveInput();
  if (!el) return;
  el.value = "";
  el.focus();
}

async function saveTVA() {
  const keyInput = document.getElementById("tva-key-input");
  const valInput = document.getElementById("tva-val-input");
  const key = parseInt(keyInput.value, 10);
  const cota = parseFloat(valInput.value);

  if (isNaN(key) || key <= 0) {
    showToast("Nr_TVA trebuie sa fie un numar intreg > 0");
    return;
  }
  if (isNaN(cota) || cota < 0) {
    showToast("Cota nu poate fi negativa");
    return;
  }

  const action = tvaMode === "update" ? "update" : "insert";
  const payload = {
    action: action,
    Nr_TVA: key,
    Cota: cota
  };

  try {
    const resp = await fetch("api/tva.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message);
    closeTVAEdit();
    showToast(res.message);
    tvaSelectedKey = key;
    loadTVARows();
  } catch (err) {
    appAlert("Eroare salvare TVA: " + err.message);
  }
}

function deleteTVA() {
  if (tvaSelectedKey === null) return;
  appConfirm("Stergeti cota TVA " + tvaSelectedKey + "?", async () => {
    try {
      const resp = await fetch("api/tva.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "delete", Nr_TVA: tvaSelectedKey })
      });
      const res = await resp.json();
      if (res.status !== "success") throw new Error(res.message);
      showToast(res.message);
      tvaSelectedKey = null;
      loadTVARows();
    } catch (err) {
      appAlert("Eroare stergere TVA: " + err.message);
    }
  }, "Da", "Nu");
}

// --------------------------------------------------------------------------
// MODURI PREPARARE (tblMesaj: NrMesaj, Mesaj) - insert / update / delete
// --------------------------------------------------------------------------
let mesajeRows = [];
let mesajeSelectedKey = null;
let mesajeMode = "insert";

function openMesajeEditor() {
  document.getElementById("modal-mesaje").classList.add("active");
  loadMesajeRows();
}

function closeMesajeModal() {
  document.getElementById("modal-mesaje").classList.remove("active");
  mesajeSelectedKey = null;
}

function loadMesajeRows() {
  fetch("api/mesaje.php")
    .then(r => r.json())
    .then(data => {
      if (data.status !== "success") throw new Error(data.message || "Eroare");
      mesajeRows = data.rows || [];
      renderMesajeList();
    })
    .catch(err => showToast("Eroare moduri preparare: " + err.message));
}

function renderMesajeList() {
  const list = document.getElementById("mesaje-list");
  if (!list) return;
  list.innerHTML = "";

  if (mesajeRows.length === 0) {
    list.innerHTML = '<div class="tva-row" style="color:#888;">Nu exista moduri de preparare</div>';
  }

  mesajeRows.forEach(r => {
    const row = document.createElement("div");
    row.className = `tva-row ${r.NrMesaj === mesajeSelectedKey ? "selected" : ""}`;
    row.innerHTML = `
      <span><b>${r.NrMesaj}</b></span>
      <span class="row-mesaj">${escapeHtml(r.Mesaj)}</span>
    `;
    row.onclick = () => {
      mesajeSelectedKey = r.NrMesaj;
      renderMesajeList();
      updateMesajeBtns();
    };
    list.appendChild(row);
  });

  updateMesajeBtns();
}

function updateMesajeBtns() {
  const hasSel = mesajeSelectedKey !== null;
  const ed = document.getElementById("btn-mesaje-edit");
  const del = document.getElementById("btn-mesaje-del");
  if (ed) ed.disabled = !hasSel;
  if (del) del.disabled = !hasSel;
}

function openMesajeInsert() {
  mesajeMode = "insert";
  const keyInput = document.getElementById("mesaje-key-input");
  keyInput.readOnly = false;
  keyInput.value = "";
  document.getElementById("mesaje-val-input").value = "";
  document.getElementById("mesaje-edit-title").innerText = tr("MOD PREPARARE NOU");
  document.getElementById("modal-mesaje-edit").classList.add("active");
}

function openMesajeEdit() {
  if (mesajeSelectedKey === null) {
    showToast("Selectati mai intai un mod");
    return;
  }
  const found = mesajeRows.find(r => r.NrMesaj === mesajeSelectedKey);
  if (!found) return;

  mesajeMode = "update";
  const keyInput = document.getElementById("mesaje-key-input");
  keyInput.readOnly = true;
  keyInput.value = found.NrMesaj;
  document.getElementById("mesaje-val-input").value = found.Mesaj;
  document.getElementById("mesaje-edit-title").innerText = tr("EDITARE MOD PREPARARE");
  document.getElementById("modal-mesaje-edit").classList.add("active");
}

function closeMesajeEdit() {
  document.getElementById("modal-mesaje-edit").classList.remove("active");
}

async function saveMesaje() {
  const keyInput = document.getElementById("mesaje-key-input");
  const valInput = document.getElementById("mesaje-val-input");
  const key = parseInt(keyInput.value, 10);
  const mesaj = valInput.value.trim();

  if (isNaN(key) || key <= 0) {
    showToast("NrMesaj trebuie sa fie un numar intreg > 0");
    return;
  }
  if (!mesaj) {
    showToast("Mesajul nu poate fi gol");
    return;
  }

  const action = mesajeMode === "update" ? "update" : "insert";
  const payload = { action: action, NrMesaj: key, Mesaj: mesaj };

  try {
    const resp = await fetch("api/mesaje.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message);
    closeMesajeEdit();
    showToast(res.message);
    mesajeSelectedKey = key;
    loadMesajeRows();
    refreshMessages();
  } catch (err) {
    appAlert("Eroare salvare mod preparare: " + err.message);
  }
}

function deleteMesaje() {
  if (mesajeSelectedKey === null) return;
  appConfirm("Stergeti modul de preparare " + mesajeSelectedKey + "?", async () => {
    try {
      const resp = await fetch("api/mesaje.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "delete", NrMesaj: mesajeSelectedKey })
      });
      const res = await resp.json();
      if (res.status !== "success") throw new Error(res.message);
      showToast(res.message);
      mesajeSelectedKey = null;
      loadMesajeRows();
      refreshMessages();
    } catch (err) {
      appAlert("Eroare stergere mod preparare: " + err.message);
    }
  }, "Da", "Nu");
}

// Reincarca lista de mesaje folosita in panoul "Mod Preparare"
function refreshMessages() {
  fetch("api/menu.php")
    .then(r => r.json())
    .then(data => {
      if (data.status === "success") {
        POS_STATE.messages = data.messages || [];
      }
    })
    .catch(() => {});
}

// --------------------------------------------------------------------------
// OSPATARI (tblOsp: NrOsp, Nume, Expl, Parola, Blocat)
// --------------------------------------------------------------------------
let ospRows = [];
let ospSelected = null;
let ospMode = "insert";
let ospBlocat = false;

function openOspEditor() {
  document.getElementById("modal-osp").classList.add("active");
  loadOspRows();
}

function closeOspModal() {
  document.getElementById("modal-osp").classList.remove("active");
  ospSelected = null;
}

function loadOspRows() {
  fetch("api/ospatari.php")
    .then(r => r.json())
    .then(data => {
      if (data.status !== "success") throw new Error(data.message || "Eroare");
      ospRows = data.rows || [];
      renderOspList();
    })
    .catch(err => showToast("Eroare ospatari: " + err.message));
}

function renderOspList() {
  const list = document.getElementById("osp-list");
  if (!list) return;
  list.innerHTML = "";

  if (ospRows.length === 0) {
    list.innerHTML = '<div class="osp-row" style="color:#888; display:block;">Nu exista ospatari</div>';
  }

  ospRows.forEach(r => {
    const row = document.createElement("div");
    row.className = `osp-row ${r.NrOsp === ospSelected ? "selected" : ""}`;
    row.innerHTML = `
      <span><b>${escapeHtml(r.NrOsp)}</b></span>
      <span class="osp-cell-mid ${r.Blocat ? "osp-blocked" : ""}">${escapeHtml(r.Nume)}${r.Blocat ? " (" + tr("Blocat") + ")" : ""}</span>
      <span class="osp-cell-r">${escapeHtml(r.Expl)}</span>
    `;
    row.onclick = () => {
      ospSelected = r.NrOsp;
      renderOspList();
      updateOspBtns();
    };
    list.appendChild(row);
  });

  updateOspBtns();
}

function updateOspBtns() {
  const hasSel = ospSelected !== null;
  const ed = document.getElementById("btn-osp-edit");
  const del = document.getElementById("btn-osp-del");
  if (ed) ed.disabled = !hasSel;
  if (del) del.disabled = !hasSel;
}

function renderOspBlocatToggle() {
  const btn = document.getElementById("osp-blocat-toggle");
  if (!btn) return;
  btn.innerText = ospBlocat ? tr("Da - Blocat") : tr("Nu");
  btn.style.background = ospBlocat ? "#e30707" : "#ffff00";
  btn.style.color = ospBlocat ? "#ffffff" : "#000000";
}

function toggleOspBlocat() {
  ospBlocat = !ospBlocat;
  renderOspBlocatToggle();
}

function openOspInsert() {
  ospMode = "insert";
  ospBlocat = false;
  document.getElementById("osp-key-input").readOnly = false;
  document.getElementById("osp-key-input").value = "";
  document.getElementById("osp-nume-input").value = "";
  document.getElementById("osp-expl-input").value = "";
  document.getElementById("osp-parola-input").value = "";
  document.getElementById("osp-edit-title").innerText = tr("OSPATAR NOU");
  renderOspBlocatToggle();
  document.getElementById("modal-osp-edit").classList.add("active");
}

function openOspEdit() {
  if (ospSelected === null) {
    showToast("Selectati mai intai un ospatar");
    return;
  }
  const found = ospRows.find(r => r.NrOsp === ospSelected);
  if (!found) return;

  ospMode = "update";
  document.getElementById("osp-key-input").readOnly = true;
  document.getElementById("osp-key-input").value = found.NrOsp;
  document.getElementById("osp-nume-input").value = found.Nume;
  document.getElementById("osp-expl-input").value = found.Expl;
  document.getElementById("osp-parola-input").value = found.Parola;
  ospBlocat = !!found.Blocat;
  document.getElementById("osp-edit-title").innerText = tr("EDITARE OSPATAR");
  renderOspBlocatToggle();
  document.getElementById("modal-osp-edit").classList.add("active");
}

function closeOspEdit() {
  document.getElementById("modal-osp-edit").classList.remove("active");
}

async function saveOsp() {
  const nrOsp = document.getElementById("osp-key-input").value.trim();
  if (!nrOsp || nrOsp.length > 2) {
    showToast("NrOsp obligatoriu, maxim 2 caractere");
    return;
  }

  const payload = {
    action: ospMode === "update" ? "update" : "insert",
    NrOsp: nrOsp,
    Nume: document.getElementById("osp-nume-input").value,
    Expl: document.getElementById("osp-expl-input").value,
    Parola: document.getElementById("osp-parola-input").value,
    Blocat: ospBlocat ? 1 : 0
  };

  try {
    const resp = await fetch("api/ospatari.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message);
    closeOspEdit();
    showToast(res.message);
    ospSelected = nrOsp;
    loadOspRows();
  } catch (err) {
    appAlert("Eroare salvare ospatar: " + err.message);
  }
}

function deleteOsp() {
  if (ospSelected === null) return;
  appConfirm("Stergeti ospatarul " + ospSelected + "?", async () => {
    try {
      const resp = await fetch("api/ospatari.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "delete", NrOsp: ospSelected })
      });
      const res = await resp.json();
      if (res.status !== "success") throw new Error(res.message);
      showToast(res.message);
      ospSelected = null;
      loadOspRows();
    } catch (err) {
      appAlert("Eroare stergere ospatar: " + err.message);
    }
  }, "Da", "Nu");
}

// --------------------------------------------------------------------------
// SECTII (tblSectii)
// --------------------------------------------------------------------------
let sectRows = [];
let sectSelected = null;
let sectMode = "insert";

function openSectEditor() {
  document.getElementById("modal-sect").classList.add("active");
  loadSectRows();
}

function closeSectModal() {
  document.getElementById("modal-sect").classList.remove("active");
  sectSelected = null;
}

function loadSectRows() {
  fetch("api/sectii.php")
    .then(r => r.json())
    .then(data => {
      if (data.status !== "success") throw new Error(data.message || "Eroare");
      sectRows = data.rows || [];
      renderSectList();
    })
    .catch(err => showToast("Eroare sectii: " + err.message));
}

function renderSectList() {
  const list = document.getElementById("sect-list");
  if (!list) return;
  list.innerHTML = "";

  if (sectRows.length === 0) {
    list.innerHTML = '<div class="sect-row" style="display:block; color:#888;">Nu exista sectii</div>';
  }

  sectRows.forEach(r => {
    const protected = (r.Sectie === 1 || r.Sectie === 2);
    const row = document.createElement("div");
    row.className = `sect-row ${r.Sectie === sectSelected ? "selected" : ""}`;
    row.innerHTML = `
      <span class="sect-cell"><b>${r.Sectie}</b></span>
      <span class="sect-cell">${escapeHtml(r.Denumire)}${protected ? ' <span class="sect-prot">' + tr("(protejata)") + '</span>' : ""}</span>
    `;
    row.onclick = () => {
      sectSelected = r.Sectie;
      renderSectList();
      updateSectBtns();
    };
    list.appendChild(row);
  });

  updateSectBtns();
}

function updateSectBtns() {
  const hasSel = sectSelected !== null;
  const ed = document.getElementById("btn-sect-edit");
  const del = document.getElementById("btn-sect-del");
  if (ed) ed.disabled = !hasSel;
  if (del) {
    const prot = (sectSelected === 1 || sectSelected === 2);
    del.disabled = !hasSel || prot;
  }
}

function openSectInsert() {
  sectMode = "insert";
  document.getElementById("sect-key-input").readOnly = false;
  document.getElementById("sect-key-input").value = "";
  document.getElementById("sect-den-input").value = "";
  document.getElementById("sect-edit-title").innerText = tr("SECTIE NOUA");
  document.getElementById("modal-sect-edit").classList.add("active");
}

function openSectEdit() {
  if (sectSelected === null) {
    showToast("Selectati mai intai o sectie");
    return;
  }
  const found = sectRows.find(r => r.Sectie === sectSelected);
  if (!found) return;

  sectMode = "update";
  document.getElementById("sect-key-input").readOnly = true;
  document.getElementById("sect-key-input").value = found.Sectie;
  document.getElementById("sect-den-input").value = found.Denumire;
  document.getElementById("sect-edit-title").innerText = tr("EDITARE SECTIE");
  document.getElementById("modal-sect-edit").classList.add("active");
}

function closeSectEdit() {
  document.getElementById("modal-sect-edit").classList.remove("active");
}

async function saveSect() {
  const nrSect = document.getElementById("sect-key-input").value.trim();
  if (!/^[1-9][0-9]*$/.test(nrSect)) {
    showToast("Sectie obligatorie, numar intreg mai mare decat 0");
    return;
  }
  const denumire = document.getElementById("sect-den-input").value;
  if (!denumire.trim() || denumire.length > 10) {
    showToast("Denumire obligatorie, maxim 10 caractere");
    return;
  }

  const payload = {
    action: sectMode === "update" ? "update" : "insert",
    Sectie: parseInt(nrSect, 10),
    Denumire: denumire
  };

  try {
    const resp = await fetch("api/sectii.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message);
    closeSectEdit();
    showToast(res.message);
    sectSelected = parseInt(nrSect, 10);
    loadSectRows();
  } catch (err) {
    appAlert("Eroare salvare sectie: " + err.message);
  }
}

function deleteSect() {
  if (sectSelected === null) return;
  if (sectSelected === 1 || sectSelected === 2) {
    showToast("Sectia " + sectSelected + " este protejata si nu poate fi stearsa");
    return;
  }
  appConfirm("Stergeti sectia " + sectSelected + "?", async () => {
    try {
      const resp = await fetch("api/sectii.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "delete", Sectie: sectSelected })
      });
      const res = await resp.json();
      if (res.status !== "success") throw new Error(res.message);
      showToast(res.message);
      sectSelected = null;
      loadSectRows();
    } catch (err) {
      appAlert("Eroare stergere sectie: " + err.message);
    }
  }, "Da", "Nu");
}

// --------------------------------------------------------------------------
// IMPRIMANTE (sectii tblKP + destinatii fizice in print-service/config.json)
// --------------------------------------------------------------------------
let imprSections = [];     // [{NrLogic, Nume, Stare, usedCount, isNew, target:{type,host,port,name}}]
let imprDeleted = [];      // NrLogic de sters la salvare
let imprGlobal = {         // tinte globale
  nota: { type: "preview", host: "", port: "9100", name: "" },
  raport: { type: "preview", host: "", port: "9100", name: "" },
  fiscal: { type: "file", host: "", port: "9100", name: "" },
  defaultPrinter: ""
};
let imprSelected = null;
let imprWindowsPrinters = [];
let imprUsbPrinters = [];
let imprServiceUp = false;

const IMPR_TARGET_TYPES = [
  ["network", "Retea (IP:port)"],
  ["windows", "Windows / USB (nume imprimanta)"],
  ["preview", "Preview (emulator)"],
  ["file", "Fisier (spool)"]
];

function imprNormalizeTarget(t) {
  t = t || {};
  return {
    type: (t.target || "preview"),
    host: (t.host || ""),
    port: (t.port !== undefined && t.port !== null ? String(t.port) : "9100"),
    name: (t.name || t.printer || "")
  };
}

function openProgImprimante() {
  navigateToScreen("screen-prog-imprimante");
  loadImprimante();
}

function closeProgImprimante() {
  navigateToScreen("screen-programare");
}

function reloadImprimante() {
  loadImprimante();
}

async function loadImprimante() {
  try {
    const kpRes = await fetch("api/kp.php").then(r => r.json());
    if (kpRes.status !== "success") throw new Error(kpRes.message || "Eroare sectii");
    const cfgRes = await fetch("api/print_config.php?action=config").then(r => r.json());
    const cfg = (cfgRes && cfgRes.status === "success" && cfgRes.config) ? cfgRes.config : {};
    imprServiceUp = !!(cfgRes && cfgRes.service_up);
    const printers = cfg.printers || {};

    imprSections = (kpRes.rows || []).map(r => ({
      NrLogic: r.NrLogic,
      Nume: r.Nume,
      Stare: !!r.Stare,
      usedCount: r.usedCount || 0,
      isNew: false,
      target: imprNormalizeTarget(printers[String(r.NrLogic)])
    }));
    imprDeleted = [];
    imprGlobal = {
      nota: imprNormalizeTarget(cfg.nota_target),
      raport: imprNormalizeTarget(cfg.raport_target || cfg.nota_target),
      fiscal: imprNormalizeTarget(cfg.fiscal_target || { target: "file" }),
      defaultPrinter: (cfg.default_printer_nr !== undefined && cfg.default_printer_nr !== null) ? String(cfg.default_printer_nr) : ""
    };
    imprSelected = imprSections.length ? imprSections[0].NrLogic : null;

    // Lista imprimantelor Windows (optional, pentru combo) + cele pe USB local.
    imprWindowsPrinters = [];
    imprUsbPrinters = [];
    fetch("api/print_config.php?action=printers")
      .then(r => r.json())
      .then(d => {
        if (d && d.status === "success") {
          imprWindowsPrinters = d.printers || [];
          imprUsbPrinters = d.usb || [];
          renderImprimante();
        }
      })
      .catch(() => {});

    renderImprimante();
  } catch (err) {
    appAlert("Eroare incarcare imprimante: " + err.message);
  }
}

function imprSel() {
  return imprSections.find(s => s.NrLogic === imprSelected) || null;
}

function renderImprimante() {
  const badge = document.getElementById("impr-service-badge");
  if (badge) {
    badge.textContent = tr("Serviciu: ") + (imprServiceUp ? "ONLINE" : "OFFLINE");
    badge.className = "impr-badge " + (imprServiceUp ? "on" : "off");
  }
  renderImprList();
  renderImprInspector();
  renderImprGlobal();
}

function imprIsUsb(name) {
  return !!(name && imprUsbPrinters.indexOf(name) !== -1);
}

function imprTargetLabel(t) {
  if (!t) return "-";
  if (t.type === "network") return tr("Retea ") + (t.host || "?") + ":" + (t.port || "9100");
  if (t.type === "windows") return tr("Windows / USB ") + (t.name || "?") + (imprIsUsb(t.name) ? " (USB)" : "");
  if (t.type === "file") return tr("Fisier");
  return tr("Preview");
}

function renderImprList() {
  const list = document.getElementById("impr-list");
  if (!list) return;
  if (!imprSections.length) {
    list.innerHTML = '<div class="impr-empty">' + tr("Nu exista sectii. Apasa Adauga.") + '</div>';
  } else {
    list.innerHTML = imprSections.map(s => {
      const sel = (s.NrLogic === imprSelected) ? " selected" : "";
      const off = s.Stare ? "" : " inactive";
      return `<div class="impr-list-item${sel}${off}" onclick="imprSelect(${s.NrLogic})">
        <div><span class="impr-li-nr">${s.NrLogic}</span> ${escapeHtml(s.Nume || tr("(fara nume)"))}${s.isNew ? ' <span class="impr-dirty">*</span>' : ''}</div>
        <div class="impr-li-sub">${escapeHtml(imprTargetLabel(s.target))}</div>
      </div>`;
    }).join("");
  }
  const del = document.getElementById("impr-del-btn");
  if (del) del.disabled = (imprSelected === null);
}

function imprSelect(nr) {
  imprSelected = nr;
  renderImprInspector();
  renderImprList();
}

function imprSetNume(v) {
  const s = imprSel(); if (!s) return;
  s.Nume = v;
}

function imprToggleStare() {
  const s = imprSel(); if (!s) return;
  s.Stare = !s.Stare;
  renderImprInspector();
}

function imprTargetFieldChanged(prefix, field, value) {
  if (prefix === "sect") { const s = imprSel(); if (s) s.target[field] = value; }
  else if (imprGlobal[prefix]) { imprGlobal[prefix][field] = value; }
}

function imprTargetTypeChanged(prefix, type) {
  if (prefix === "sect") { const s = imprSel(); if (s) { s.target.type = type; renderImprInspector(); } }
  else if (imprGlobal[prefix]) { imprGlobal[prefix].type = type; renderImprGlobal(); }
}

function imprTypeOptions(selected) {
  return IMPR_TARGET_TYPES.map(([v, l]) =>
    `<option value="${v}"${v === selected ? " selected" : ""}>${tr(l)}</option>`).join("");
}

function imprWinPrinterField(prefix, current) {
  if (imprWindowsPrinters && imprWindowsPrinters.length) {
    const has = imprWindowsPrinters.indexOf(current) !== -1;
    let opts = "";
    if (current && !has) {
      opts += `<option value="${escapeHtml(current)}" selected>${escapeHtml(current)} ${tr("(actual)")}</option>`;
    }
    opts += imprWindowsPrinters.map(n => {
      const label = escapeHtml(n) + (imprIsUsb(n) ? " (USB)" : "");
      return `<option value="${escapeHtml(n)}"${n === current ? " selected" : ""}>${label}</option>`;
    }).join("");
    return `<div class="tva-field"><label>${tr("Imprimanta Windows / USB")}</label>
      <select class="prod-combo" onchange="imprTargetFieldChanged('${prefix}','name',this.value)">${opts}</select>
      <div class="impr-hint">${tr("Imprimantele USB locale sunt marcate cu (USB).")}</div></div>`;
  }
  return `<div class="tva-field"><label>${tr("Imprimanta Windows / USB")}</label>
    <div class="tva-field-row">
      <input type="text" class="tva-input" value="${escapeHtml(current || "")}" oninput="imprTargetFieldChanged('${prefix}','name',this.value)">
    </div>
    <div class="impr-hint">${tr("Lista imprimantelor Windows nu este disponibila (pywin32 lipseste). Scrie numele exact (inclusiv pentru o imprimanta USB locala).")}</div></div>`;
}

function imprTargetFieldsHtml(prefix, t) {
  let html = `<div class="tva-field"><label>${tr("Tip destinatie")}</label>
    <select class="prod-combo" onchange="imprTargetTypeChanged('${prefix}', this.value)">${imprTypeOptions(t.type)}</select></div>`;
  if (t.type === "network") {
    html += `<div class="tva-field"><label>${tr("Host / IP")}</label>
      <div class="tva-field-row"><input type="text" class="tva-input" value="${escapeHtml(t.host || "")}" oninput="imprTargetFieldChanged('${prefix}','host',this.value)"></div></div>
      <div class="tva-field"><label>${tr("Port")}</label>
      <div class="tva-field-row"><input type="text" class="tva-input" value="${escapeHtml(t.port || "9100")}" oninput="imprTargetFieldChanged('${prefix}','port',this.value)"></div></div>`;
  } else if (t.type === "windows") {
    html += imprWinPrinterField(prefix, t.name);
  } else if (t.type === "file") {
    html += `<div class="impr-hint">${tr("Bonurile se scriu in spool-ul serviciului de tiparire (fara imprimanta).")}</div>`;
  } else {
    html += `<div class="impr-hint">${tr("Bonul merge in emulatorul de previzualizare.")}</div>`;
  }
  return html;
}

function renderImprInspector() {
  const wrap = document.getElementById("impr-insp-body");
  if (!wrap) return;
  const s = imprSel();
  if (!s) {
    wrap.innerHTML = '<div class="impr-empty">' + tr("Selecteaza o sectie sau adauga una noua.") + '</div>';
    return;
  }
  const title = s.isNew ? tr("SECTIE NOUA") : (tr("SECTIE ") + s.NrLogic);
  wrap.innerHTML = `
    <div class="impr-insp-head"><span>${title}</span>${s.isNew ? '<span class="impr-dirty">' + tr("* nesalvata") + '</span>' : ''}</div>
    <div class="tva-field"><label>NrLogic</label>
      <div class="tva-field-row"><input type="text" class="tva-input" value="${s.NrLogic}" readonly></div></div>
    <div class="tva-field"><label>${tr("Nume sectie")}</label>
      <div class="tva-field-row">
        <input type="text" id="impr-nume-input" class="tva-input" maxlength="255" value="${escapeHtml(s.Nume || "")}" oninput="imprSetNume(this.value)">
        <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('impr-nume-input')">&#9000;</button>
      </div></div>
    <div class="tva-field"><label>${tr("Stare (activa)")}</label>
      <button class="act-btn" onclick="imprToggleStare()" style="width:100%;${s.Stare ? "background:#008000;color:#fff;" : "background:#ffff00;"}">${s.Stare ? tr("Da") : tr("Nu")}</button></div>
    <div class="impr-sep"></div>
    <div class="impr-panel-title">${tr("Destinatie fizica")}</div>
    ${imprTargetFieldsHtml("sect", s.target)}
    <div class="tva-actions"><button class="act-btn" onclick="imprTestSection()">${tr("Test tiparire")}</button></div>
  `;
}

function renderImprGlobal() {
  const wrap = document.getElementById("impr-global-body");
  if (!wrap) return;
  let html = "";
  html += `<div class="impr-global-block"><div class="impr-panel-title">${tr("Nota de plata")}</div>${imprTargetFieldsHtml("nota", imprGlobal.nota)}
    <div class="tva-actions"><button class="act-btn" onclick="imprTestGlobal('nota')">Test</button></div></div>`;
  html += `<div class="impr-global-block"><div class="impr-panel-title">${tr("Rapoarte")}</div>${imprTargetFieldsHtml("raport", imprGlobal.raport)}
    <div class="tva-actions"><button class="act-btn" onclick="imprTestGlobal('raport')">Test</button></div></div>`;
  html += `<div class="impr-global-block"><div class="impr-panel-title">${tr("Fiscal")}</div>${imprTargetFieldsHtml("fiscal", imprGlobal.fiscal)}</div>`;

  const defOpts = imprSections.map(s =>
    `<option value="${s.NrLogic}"${String(s.NrLogic) === imprGlobal.defaultPrinter ? " selected" : ""}>${escapeHtml(s.Nume || (tr("Sectia ") + s.NrLogic))}</option>`).join("");
  html += `<div class="impr-global-block"><div class="impr-panel-title">${tr("Imprimanta implicita")}</div>
    <div class="tva-field"><select class="prod-combo" onchange="imprSetDefault(this.value)">
      <option value=""${imprGlobal.defaultPrinter === "" ? " selected" : ""}>${tr("(implicit / preview)")}</option>
      ${defOpts}
    </select></div>
    <div class="impr-hint">${tr("Folosita pentru bonurile de sectie fara destinatie proprie.")}</div></div>`;
  wrap.innerHTML = html;
}

function imprSetDefault(v) {
  imprGlobal.defaultPrinter = v;
}

function imprAddSection() {
  const maxNr = imprSections.reduce((m, s) => Math.max(m, Number(s.NrLogic) || 0), 0);
  const nr = maxNr + 1;
  imprSections.push({
    NrLogic: nr, Nume: "", Stare: true, usedCount: 0, isNew: true,
    target: { type: "preview", host: "", port: "9100", name: "" }
  });
  imprSelected = nr;
  renderImprimante();
}

function imprDeleteSection() {
  const s = imprSel();
  if (!s) return;
  if (!s.isNew && s.usedCount > 0) {
    appAlert(tr("Sectia are {n} produse atasate. Mutati-le pe alta sectie inainte de stergere.", { n: s.usedCount }));
    return;
  }
  appConfirm(tr("Stergeti sectia ") + s.NrLogic + " (" + (s.Nume || tr("fara nume")) + ")?", () => {
    if (!s.isNew) imprDeleted.push(s.NrLogic);
    imprSections = imprSections.filter(x => x.NrLogic !== s.NrLogic);
    imprSelected = imprSections.length ? imprSections[0].NrLogic : null;
    renderImprimante();
  }, "Sterge", "Renunta");
}

function imprValidateTarget(t) {
  if (t.type === "network") {
    if (!String(t.host || "").trim()) return tr("lipseste host/IP");
    const p = parseInt(t.port, 10);
    if (isNaN(p) || p < 1 || p > 65535) return tr("port invalid (1-65535)");
  }
  if (t.type === "windows") {
    if (!String(t.name || "").trim()) return tr("lipseste numele imprimantei Windows");
  }
  return null;
}

function imprTargetToPayload(t) {
  const out = { target: t.type };
  if (t.type === "network") { out.host = String(t.host || "").trim(); out.port = parseInt(t.port, 10) || 9100; }
  if (t.type === "windows") { out.name = String(t.name || "").trim(); }
  return out;
}

async function imprPost(url, payload) {
  const resp = await fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload)
  });
  return resp.json();
}

async function saveImprimante() {
  for (const s of imprSections) {
    if (!String(s.Nume || "").trim()) {
      appAlert(tr("Sectia {n} nu are nume.", { n: s.NrLogic }));
      imprSelected = s.NrLogic; renderImprimante(); return;
    }
    const e = imprValidateTarget(s.target);
    if (e) { appAlert(tr("Sectia ") + (s.Nume || s.NrLogic) + ": " + e); imprSelected = s.NrLogic; renderImprimante(); return; }
  }
  for (const key of ["nota", "raport", "fiscal"]) {
    const e = imprValidateTarget(imprGlobal[key]);
    if (e) { appAlert(tr("Tinta ") + key + ": " + e); return; }
  }
  if (!imprServiceUp) { appAlert("Serviciul de tiparire este oprit; configuratia nu poate fi salvata."); return; }

  try {
    for (const nr of imprDeleted) {
      const res = await imprPost("api/kp.php", { action: "delete", NrLogic: nr });
      if (res.status !== "success") throw new Error(res.message);
    }
    for (const s of imprSections) {
      const res = await imprPost("api/kp.php", {
        action: s.isNew ? "insert" : "update",
        NrLogic: s.NrLogic,
        Nume: String(s.Nume).trim(),
        Stare: s.Stare ? 1 : 0
      });
      if (res.status !== "success") throw new Error(res.message);
      s.isNew = false;
    }

    const printers = {};
    imprSections.forEach(s => { printers[String(s.NrLogic)] = imprTargetToPayload(s.target); });
    const config = {
      printers: printers,
      nota_target: imprTargetToPayload(imprGlobal.nota),
      raport_target: imprTargetToPayload(imprGlobal.raport),
      fiscal_target: imprTargetToPayload(imprGlobal.fiscal),
      default_printer_nr: imprGlobal.defaultPrinter === "" ? null : imprGlobal.defaultPrinter
    };
    const res = await imprPost("api/print_config.php", { action: "save", config: config });
    if (res.status !== "success") throw new Error(res.message);

    showToast("Configurare salvata");
    imprDeleted = [];
    await loadImprimante();
  } catch (err) {
    appAlert("Eroare salvare imprimante: " + err.message);
  }
}

async function imprTestTarget(t) {
  if (!imprServiceUp) { appAlert("Serviciul de tiparire este oprit."); return; }
  const e = imprValidateTarget(t);
  if (e) { appAlert(e); return; }
  showToast("Se trimite testul...");
  try {
    const res = await imprPost("api/print_config.php", { action: "test", target: imprTargetToPayload(t) });
    if (res.status === "success") appAlert("Test trimis: " + (res.message || "OK"));
    else appAlert("Eroare test: " + (res.message || "eroare"));
  } catch (err) {
    appAlert("Eroare test: " + err.message);
  }
}

function imprTestSection() {
  const s = imprSel(); if (!s) return;
  imprTestTarget(s.target);
}

function imprTestGlobal(key) {
  if (imprGlobal[key]) imprTestTarget(imprGlobal[key]);
}

// --------------------------------------------------------------------------
// FORME DE PLATA (tblFP: FPID, Denumire, Status)
// --------------------------------------------------------------------------
let fpRows = [];
let fpSelected = null;
let fpMode = "insert";
let fpStatus = false;

function openFpEditor() {
  document.getElementById("modal-fp").classList.add("active");
  loadFpRows();
}

function closeFpModal() {
  document.getElementById("modal-fp").classList.remove("active");
  fpSelected = null;
}

function loadFpRows() {
  fetch("api/fp.php")
    .then(r => r.json())
    .then(data => {
      if (data.status !== "success") throw new Error(data.message || "Eroare");
      fpRows = data.rows || [];
      renderFpList();
    })
    .catch(err => showToast("Eroare forme de plata: " + err.message));
}

function renderFpList() {
  const list = document.getElementById("fp-list");
  if (!list) return;
  list.innerHTML = "";

  if (fpRows.length === 0) {
    list.innerHTML = '<div class="fp-row" style="display:block; color:#888;">Nu exista forme de plata</div>';
  }

  fpRows.forEach(r => {
    const row = document.createElement("div");
    row.className = `fp-row ${r.FPID === fpSelected ? "selected" : ""}`;
    const st = r.Status
      ? '<span class="fp-on">Da</span>'
      : '<span class="fp-off">Nu</span>';
    row.innerHTML = `
      <span><b>${r.FPID}</b></span>
      <span class="fp-name">${escapeHtml(r.Denumire)}</span>
      <span>${r.Poz !== null && r.Poz !== undefined ? r.Poz : ""}</span>
      ${st}
    `;
    row.onclick = () => {
      fpSelected = r.FPID;
      renderFpList();
      updateFpBtns();
    };
    list.appendChild(row);
  });

  updateFpBtns();
}

function updateFpBtns() {
  const hasSel = fpSelected !== null;
  const ed = document.getElementById("btn-fp-edit");
  const del = document.getElementById("btn-fp-del");
  if (ed) ed.disabled = !hasSel;
  if (del) del.disabled = !hasSel;
}

function renderFpStatusToggle() {
  const btn = document.getElementById("fp-status-toggle");
  if (!btn) return;
  btn.innerText = fpStatus ? tr("Da") : tr("Nu");
  btn.style.background = fpStatus ? "#008000" : "#ffff00";
  btn.style.color = fpStatus ? "#ffffff" : "#000000";
}

function toggleFpStatus() {
  fpStatus = !fpStatus;
  renderFpStatusToggle();
}

function openFpInsert() {
  fpMode = "insert";
  fpStatus = false;
  document.getElementById("fp-key-input").readOnly = false;
  document.getElementById("fp-key-input").value = "";
  document.getElementById("fp-den-input").value = "";
  document.getElementById("fp-poz-input").value = "";
  document.getElementById("fp-edit-title").innerText = tr("FORMA DE PLATA NOUA");
  renderFpStatusToggle();
  document.getElementById("modal-fp-edit").classList.add("active");
}

function openFpEdit() {
  if (fpSelected === null) {
    showToast("Selectati mai intai o forma de plata");
    return;
  }
  const found = fpRows.find(r => r.FPID === fpSelected);
  if (!found) return;

  fpMode = "update";
  document.getElementById("fp-key-input").readOnly = true;
  document.getElementById("fp-key-input").value = found.FPID;
  document.getElementById("fp-den-input").value = found.Denumire;
  document.getElementById("fp-poz-input").value = (found.Poz !== null && found.Poz !== undefined) ? found.Poz : "";
  fpStatus = !!found.Status;
  document.getElementById("fp-edit-title").innerText = tr("EDITARE FORMA DE PLATA");
  renderFpStatusToggle();
  document.getElementById("modal-fp-edit").classList.add("active");
}

function closeFpEdit() {
  document.getElementById("modal-fp-edit").classList.remove("active");
}

async function saveFp() {
  const fpid = parseInt(document.getElementById("fp-key-input").value, 10);
  const denumire = document.getElementById("fp-den-input").value.trim();
  const pozRaw = document.getElementById("fp-poz-input").value.trim();

  if (isNaN(fpid) || fpid < 0) {
    showToast("FPID trebuie sa fie un numar intreg >= 0");
    return;
  }
  if (!denumire || denumire.length > 10) {
    showToast("Denumire obligatorie, maxim 10 caractere");
    return;
  }
  let poz = null;
  if (pozRaw !== "") {
    poz = parseInt(pozRaw, 10);
    if (isNaN(poz) || poz < 0) {
      showToast("Poz trebuie sa fie un numar intreg >= 0");
      return;
    }
  }

  const payload = {
    action: fpMode === "update" ? "update" : "insert",
    FPID: fpid,
    Denumire: denumire,
    Status: fpStatus ? 1 : 0,
    Poz: poz
  };

  try {
    const resp = await fetch("api/fp.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message);
    closeFpEdit();
    showToast(res.message);
    fpSelected = fpid;
    loadFpRows();
  } catch (err) {
    appAlert("Eroare salvare forma de plata: " + err.message);
  }
}

function deleteFp() {
  if (fpSelected === null) return;
  appConfirm("Stergeti forma de plata " + fpSelected + "?", async () => {
    try {
      const resp = await fetch("api/fp.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "delete", FPID: fpSelected })
      });
      const res = await resp.json();
      if (res.status !== "success") throw new Error(res.message);
      showToast(res.message);
      fpSelected = null;
      loadFpRows();
    } catch (err) {
      appAlert("Eroare stergere forma de plata: " + err.message);
    }
  }, "Da", "Nu");
}

// --------------------------------------------------------------------------
// TASTATURA VIRTUALA FLOTANTA (scrie in campul cu focus)
// --------------------------------------------------------------------------
let fkbTarget = null;
let fkbShift = false;    // tasta Shift: litere mari + simboluri
let fkbCaps = false;     // Caps Lock: litere mari persistente

document.addEventListener("focusin", function (e) {
  const t = e.target;
  if (t && (t.tagName === "INPUT" || t.tagName === "TEXTAREA")) {
    fkbTarget = t.readOnly ? null : t;
  }
});

// Construieste / redesenaza tastatura virtuala flotanta (litere mici/mari + simboluri)
const FKB_ROWS = [
  [
    { lo: "1", up: "!", lbl: null },
    { lo: "2", up: "@", lbl: null },
    { lo: "3", up: "#", lbl: null },
    { lo: "4", up: "$", lbl: null },
    { lo: "5", up: "%", lbl: null },
    { lo: "6", up: "^", lbl: null },
    { lo: "7", up: "&", lbl: null },
    { lo: "8", up: "*", lbl: null },
    { lo: "9", up: "(", lbl: null },
    { lo: "0", up: ")", lbl: null }
  ],
  [
    ...["Q", "W", "E", "R", "T", "Y", "U", "I", "O", "P"].map(l => ({ lo: l.toLowerCase(), up: l })),
    { lo: "[", up: "{" },
    { lo: "]", up: "}" },
    { lo: "\\", up: "|" }
  ],
  [
    ...["A", "S", "D", "F", "G", "H", "J", "K", "L"].map(l => ({ lo: l.toLowerCase(), up: l })),
    { lo: ";", up: ":" },
    { lo: "'", up: "\"" }
  ],
  [
    ...["Z", "X", "C", "V", "B", "N", "M"].map(l => ({ lo: l.toLowerCase(), up: l })),
    { lo: ",", up: "<" },
    { lo: ".", up: ">" },
    { lo: "/", up: "?" },
    { lo: "-", up: "_" }
  ]
];

function fkbRender() {
  const wrap = document.getElementById("fkb-rows");
  if (!wrap) return;
  const upper = fkbShift || fkbCaps;

  let html = '<div class="qwerty-section">';

  // 1. Rindul de cifre (devin simboluri doar la Shift, nu la Caps)
  const showSym = fkbShift && !fkbCaps;
  html += '<div class="qwerty-row">';
  FKB_ROWS[0].forEach(k => {
    const disp = showSym ? k.up : k.lo;
    html += `<button class="qkey" data-fkb-char="${escapeHtml(disp)}" onclick="fkbKey(this.dataset.fkbChar)">${escapeHtml(disp)}</button>`;
  });
  html += "</div>";

  // 2. Rindul Q..P + [ ] \ + Backspace
  const abc0 = FKB_ROWS[1].map(l => (upper ? l.up : l.lo));
  html += '<div class="qwerty-row">';
  abc0.forEach(ch => {
    html += `<button class="qkey" data-fkb-char="${escapeHtml(ch)}" onclick="fkbKey(this.dataset.fkbChar)">${escapeHtml(ch)}</button>`;
  });
  html += '<button class="qkey key-enter" onclick="fkbBackspace()">⌫</button>';
  html += "</div>";

  // 3. Rindul A..L
  const abc1 = FKB_ROWS[2].map(l => (upper ? l.up : l.lo));
  html += '<div class="qwerty-row">';
  abc1.forEach(ch => {
    html += `<button class="qkey" data-fkb-char="${escapeHtml(ch)}" onclick="fkbKey(this.dataset.fkbChar)">${escapeHtml(ch)}</button>`;
  });
  html += "</div>";

  // 4. Rindul Z..M + , . / -
  const abc2 = FKB_ROWS[3].map(l => (upper ? l.up : l.lo));
  html += '<div class="qwerty-row">';
  abc2.forEach(ch => {
    html += `<button class="qkey" data-fkb-char="${escapeHtml(ch)}" onclick="fkbKey(this.dataset.fkbChar)">${escapeHtml(ch)}</button>`;
  });
  html += "</div>";

  // 5. Rindul de control: Shift, Caps, space, CLR
  const shiftOn = fkbShift || fkbCaps;
  html += '<div class="qwerty-row">';
  html += `<button class="qkey ${shiftOn ? "fkb-shift-on" : ""}" style="min-width:70px;" onclick="fkbToggleShift()">${fkbShift ? "⇧" : "⇧"}</button>`;
  html += `<button class="qkey ${fkbCaps ? "fkb-caps-on" : ""}" style="min-width:58px;font-size:14px;" onclick="fkbToggleCaps()">CAPS</button>`;
  html += `<button class="qkey key-space" onclick="fkbKey(' ')">SPACE</button>`;
  html += '<button class="qkey key-enter" onclick="fkbClear()">CLR</button>';
  html += "</div>";

  html += "</div>";
  wrap.innerHTML = html;
}

function fkbToggleShift() {
  fkbShift = !fkbShift;
  fkbRender();
}

function fkbToggleCaps() {
  fkbCaps = !fkbCaps;
  fkbShift = false;
  fkbRender();
}

function toggleFloatingKeyboard() {
  const m = document.getElementById("modal-fkb");
  const willOpen = !m.classList.contains("active");
  if (willOpen) {
    const t = document.activeElement;
    fkbTarget = (t && (t.tagName === "INPUT" || t.tagName === "TEXTAREA") && !t.readOnly) ? t : null;
    fkbShift = false;
    fkbCaps = false;
    m.classList.add("active");
    fkbRender();
    if (fkbTarget) {
      positionFloatingKeyboard(fkbTarget);
      requestAnimationFrame(() => fkbEnsureFieldVisible(fkbTarget));
    }
  } else {
    m.classList.remove("active");
  }
}

function closeFloatingKeyboard() {
  document.getElementById("modal-fkb").classList.remove("active");
}

// Deschide tastatura virtuala tintita spre un camp anume (butonul ⌨️ langa input)
function openFloatingKeyboardFor(inputId) {
  const el = document.getElementById(inputId);
  if (!el) return;
  fkbTarget = el;
  fkbShift = false;
  fkbCaps = false;
  document.getElementById("modal-fkb").classList.add("active");
  fkbRender();
  try { el.focus({ preventScroll: true }); } catch (err) { el.focus(); }
  // Pozitionare automata: tastatura nu trebuie sa acopere campul editat
  positionFloatingKeyboard(el);
  requestAnimationFrame(() => fkbEnsureFieldVisible(el));
}

// Pozitioneaza tastatura flotanta astfel incat sa nu acopere campul tinta.
// Coordonatele din #pos-stage sunt NESCALATE (stage-ul e transformat cu scale).
function positionFloatingKeyboard(targetEl) {
  const dlg = document.getElementById("fkb-dialog");
  const stage = document.getElementById("pos-stage");
  if (!dlg || !stage || !targetEl) return;

  const sr = stage.getBoundingClientRect();
  const scale = stage.offsetWidth ? (sr.width / stage.offsetWidth) : 1;
  const stageW = stage.offsetWidth;
  const stageH = stage.offsetHeight;

  const rect = targetEl.getBoundingClientRect();
  const fx = (rect.left - sr.left) / scale;
  const fy = (rect.top - sr.top) / scale;
  const fw = rect.width / scale;
  const fh = rect.height / scale;

  const kw = dlg.offsetWidth;
  const kh = dlg.offsetHeight;
  const margin = 10;

  let left = fx + fw / 2 - kw / 2;
  left = Math.max(margin, Math.min(left, stageW - kw - margin));

  const spaceBelow = stageH - (fy + fh);
  const spaceAbove = fy;

  let top;
  if (spaceBelow >= kh + margin) {
    top = fy + fh + margin;          // sub camp
  } else if (spaceAbove >= kh + margin) {
    top = fy - kh - margin;          // deasupra campului
  } else if (spaceBelow >= spaceAbove) {
    top = stageH - kh - margin;      // lipita de jos
  } else {
    top = margin;                    // lipita de sus
  }
  top = Math.max(margin, Math.min(top, stageH - kh - margin));

  dlg.style.left = Math.round(left) + "px";
  dlg.style.top = Math.round(top) + "px";
}

function fkbScrollableAncestor(el) {
  let p = el.parentElement;
  while (p && p !== document.body) {
    const st = window.getComputedStyle(p);
    if (/(auto|scroll)/.test(st.overflowY) && p.scrollHeight > p.clientHeight + 1) {
      return p;
    }
    p = p.parentElement;
  }
  return null;
}

// Daca totusi se suprapune, deruleaza containerul campului ca sa ramana vizibil
// in banda libera (deasupra sau sub tastatura).
function fkbEnsureFieldVisible(targetEl) {
  const dlg = document.getElementById("fkb-dialog");
  const stage = document.getElementById("pos-stage");
  if (!dlg || !stage || !targetEl) return;
  const scroller = fkbScrollableAncestor(targetEl);
  if (!scroller) return;

  const sr = stage.getBoundingClientRect();
  const scale = stage.offsetWidth ? (sr.width / stage.offsetWidth) : 1;
  const kTop = parseFloat(dlg.style.top) || 0;
  const kBottom = kTop + dlg.offsetHeight;
  const margin = 8;

  const rect = targetEl.getBoundingClientRect();
  const fTop = (rect.top - sr.top) / scale;
  const fBottom = (rect.bottom - sr.top) / scale;

  if (kTop >= fBottom) {
    const delta = fBottom - (kTop - margin);
    if (delta > 0) scroller.scrollTop += delta;
  } else if (kBottom <= fTop) {
    const delta = (kBottom + margin) - fTop;
    if (delta > 0) scroller.scrollTop -= delta;
  } else {
    const freeAbove = kTop;
    const freeBelow = stage.offsetHeight - kBottom;
    if (freeAbove >= freeBelow) {
      const delta = fBottom - (kTop - margin);
      if (delta > 0) scroller.scrollTop += delta;
    } else {
      const delta = (kBottom + margin) - fTop;
      if (delta > 0) scroller.scrollTop -= delta;
    }
  }
}

// Tastatura numerica generica: scrie in input-ul tinta (butonul "123" de langa
// camp) SAU ruleaza un callback (mod "prompt", ex. parola de discount).
let numpadTargetId = null;
let numpadPromptCallback = null;
let numpadDigitsOnly = false;
// Lungimea maxima acceptata in #num-display. Implicit 20 (parolele sunt
// nvarchar(20)); se poate limita per apel din openNumericPrompt(..., maxLen).
let numpadMaxLen = 20;

function openNumericKeyboardFor(inputId, title) {
  const el = document.getElementById(inputId);
  if (!el) return;
  numpadTargetId = inputId;
  numpadPromptCallback = null;
  numpadDigitsOnly = false;
  numpadMaxLen = 20;
  const disp = document.getElementById("num-display");
  if (disp) { disp.type = "text"; disp.value = el.value || ""; }
  const dot = document.getElementById("num-dot-key");
  if (dot) { dot.style.visibility = "visible"; dot.disabled = false; }
  const t = document.getElementById("num-title");
  if (t) t.innerText = tr(title || "TASTATURA NUMERICA");
  document.getElementById("modal-num").classList.add("active");
}

// Mod prompt: colecteaza o valoare numerica si o trimite callback-ului la OK.
function openNumericPrompt(title, onSubmit, maxLen) {
  numpadTargetId = null;
  numpadPromptCallback = onSubmit || null;
  numpadDigitsOnly = true;
  numpadMaxLen = (Number(maxLen) > 0) ? Number(maxLen) : 20;
  const disp = document.getElementById("num-display");
  if (disp) { disp.type = "password"; disp.value = ""; }
  const dot = document.getElementById("num-dot-key");
  if (dot) { dot.style.visibility = "hidden"; dot.disabled = true; }
  const t = document.getElementById("num-title");
  if (t) t.innerText = tr(title || "INTRODUCETI");
  const modal = document.getElementById("modal-num");
  if (modal) {
    // Asigura afisarea peste orice alt modal (ex. rollback), chiar daca CSS-ul
    // este inca in cache.
    modal.style.zIndex = "2600";
    modal.classList.add("active");
  }
}

function closeNumericKeyboard() {
  document.getElementById("modal-num").classList.remove("active");
  numpadTargetId = null;
  numpadPromptCallback = null;
  numpadDigitsOnly = false;
  numpadMaxLen = 20;
}

// --------------------------------------------------------------------------
// COD FISCAL CLIENT (CUI) pentru bonul fiscal
// --------------------------------------------------------------------------
// Normalizeaza CUI-ul: majuscule, doar litere/cifre (ex. "ro 12-345" -> "RO12345").
function cuiNorm(v) {
  return String(v == null ? "" : v).toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, 14);
}

// Valideaza CUI/CIF prin cifra de control (algoritmul ANAF), ignorand prefixul
// RO si orice caracter non-numeric.
function cuiValid(value) {
  const digits = String(value == null ? "" : value).replace(/[^0-9]/g, "");
  if (digits.length < 2 || digits.length > 10) return false;

  const key = [7, 5, 3, 2, 1, 7, 5, 3, 2];
  const ctrl = parseInt(digits.slice(-1), 10);
  const body = digits.slice(0, -1);
  const keyAdj = key.slice(key.length - body.length);

  let sum = 0;
  for (let i = 0; i < body.length; i++) {
    sum += parseInt(body[i], 10) * keyAdj[i];
  }
  const rest = (sum * 10) % 11;
  const calc = (rest === 10) ? 0 : rest;
  return calc === ctrl;
}

function cuiSetError(msg) {
  const el = document.getElementById("cui-error");
  if (el) el.textContent = msg || "";
}

function openCuiModal() {
  const inp = document.getElementById("cui-display");
  if (inp) inp.value = POS_STATE.cui || "";
  cuiSetError("");
  document.getElementById("modal-cui").classList.add("active");
  if (inp) {
    setTimeout(() => {
      inp.focus();
      try { inp.setSelectionRange(inp.value.length, inp.value.length); } catch (e) { /* ignoram */ }
    }, 50);
  }
}

function closeCuiModal() {
  document.getElementById("modal-cui").classList.remove("active");
}

function cuiInput() {
  const inp = document.getElementById("cui-display");
  if (inp) inp.value = cuiNorm(inp.value);
  cuiSetError("");
}

function cuiKey(ch) {
  const inp = document.getElementById("cui-display");
  if (inp) inp.value = cuiNorm(inp.value + ch);
  cuiSetError("");
}

function cuiBackspace() {
  const inp = document.getElementById("cui-display");
  if (inp) inp.value = inp.value.slice(0, -1);
  cuiSetError("");
}

function cuiClear() {
  const inp = document.getElementById("cui-display");
  if (inp) inp.value = "";
  cuiSetError("");
}

// Butonul RO: adauga prefixul RO daca nu exista deja.
function cuiRo() {
  const inp = document.getElementById("cui-display");
  if (!inp) return;
  let v = cuiNorm(inp.value);
  if (v.slice(0, 2) !== "RO") v = "RO" + v;
  inp.value = v;
  cuiSetError("");
}

function cuiOk() {
  const inp = document.getElementById("cui-display");
  const raw = cuiNorm(inp ? inp.value : "");
  // Codul fiscal e optional; daca e completat, cifra de control trebuie sa fie valida.
  if (raw !== "" && !cuiValid(raw)) {
    cuiSetError("Cod fiscal invalid (cifra de control gresita).");
    return;
  }
  POS_STATE.cui = raw;
  POS_STATE.cuiDocId = (raw !== "") ? POS_STATE.docId : null;
  cuiSetError("");
  closeCuiModal();
  updateCuiButton();
  saveCuiToServer(raw);
  showToast(POS_STATE.cui ? ("CUI: " + POS_STATE.cui) : "Cod fiscal sters");
}

// Sterge codul fiscal atasat notei curente.
function cuiRemove() {
  POS_STATE.cui = "";
  POS_STATE.cuiDocId = null;
  cuiSetError("");
  closeCuiModal();
  updateCuiButton();
  saveCuiToServer("");
  showToast("Cod fiscal sters");
}

// Salveaza codul fiscal al clientului pe nota deschisa (tblBonCurent.CodFiscBon),
// ca sa fie pastrat si in afara memoriei clientului. Best-effort: la inchidere
// codul este oricum trimis la close_bill.
async function saveCuiToServer(cui) {
  if (!POS_STATE.docId) return;
  try {
    const resp = await fetch("api/order_action.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "set_cui", docId: POS_STATE.docId, cui: cui || "" })
    });
    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message || "eroare necunoscuta");
    if (typeof res.cui === "string") {
      POS_STATE.cui = res.cui;
      POS_STATE.cuiDocId = res.cui ? POS_STATE.docId : null;
      updateCuiButton();
    }
  } catch (e) {
    showToast("Eroare salvare cod fiscal: " + e.message);
  }
}

function updateCuiButton() {
  const b = document.getElementById("btn-cui");
  if (!b) return;
  const base = tr("Cod fiscal pe bon");
  b.title = POS_STATE.cui ? (base + ": " + POS_STATE.cui) : base;
  b.classList.toggle("cui-set", !!POS_STATE.cui);
}

function numpadKey(ch) {
  const disp = document.getElementById("num-display");
  let v = disp.value;
  // Limita de lungime (parole = 20): tastele in plus sunt ignorate.
  if (v.length >= numpadMaxLen) { return; }
  if (ch === ".") {
    if (numpadDigitsOnly) return; // parola = doar cifre
    if (!v.includes(".")) disp.value = v === "" ? "0." : v + ".";
    return;
  }
  // La cantitati/preturi "0" apoi "5" da "5" (zero nesemnificativ). La coduri
  // si parole (numpadDigitsOnly) zero-ul din fata este SEMNIFICATIV: codurile
  // numerice pot incepe cu 0 (ex. 012345), deci se adauga normal.
  if (!numpadDigitsOnly && v === "0") { disp.value = ch; return; }
  disp.value = v + ch;
}

function numpadBackspace() {
  const disp = document.getElementById("num-display");
  disp.value = disp.value.slice(0, -1);
}

function numpadClear() {
  document.getElementById("num-display").value = "";
}

function numpadOk() {
  const value = document.getElementById("num-display").value;
  if (numpadPromptCallback) {
    const cb = numpadPromptCallback;
    closeNumericKeyboard();
    cb(value);
    return;
  }
  const el = document.getElementById(numpadTargetId);
  if (el) {
    el.value = value;
    el.dispatchEvent(new Event("input", { bubbles: true }));
  }
  closeNumericKeyboard();
}

// Suport pentru tastatura fizica (PC) in tastatura numerica #modal-num:
// cifrele, virgula/punctul, Backspace, Delete/C, Enter (= OK) si Escape
// (= Inchide). Cheile trec prin numpadKey(), deci se aplica aceleasi reguli:
// doar cifre in modul prompt, limita de lungime (numpadMaxLen) si pastrarea
// zero-ului din fata. Butoanele de pe ecran rămân functionale.
function numpadPhysicalKey(e) {
  const modal = document.getElementById("modal-num");
  if (!modal || !modal.classList.contains("active")) { return; }
  // Un dialog de alerta/confirmare peste numpad are prioritatea.
  if (document.querySelector("#modal-alert.active, #modal-confirm.active")) { return; }
  if (e.ctrlKey || e.metaKey || e.altKey) { return; }
  const key = String(e.key || "");

  if (key === "Enter") { e.preventDefault(); numpadOk(); return; }
  if (key === "Escape") { e.preventDefault(); closeNumericKeyboard(); return; }
  if (key === "Backspace") { e.preventDefault(); numpadBackspace(); return; }
  if (key === "Delete" || key === "c" || key === "C") { e.preventDefault(); numpadClear(); return; }
  if (key === "." || key === ",") { e.preventDefault(); numpadKey("."); return; }
  if (/^[0-9]$/.test(key)) {
    e.preventDefault();
    numpadKey(key);
  }
}

if (typeof document !== "undefined" && document.addEventListener) {
  document.addEventListener("keydown", numpadPhysicalKey);
}

function fkbTargetElement() {
  const t = document.activeElement;
  if (t && (t.tagName === "INPUT" || t.tagName === "TEXTAREA") && !t.readOnly) {
    return t;
  }
  if (fkbTarget && !fkbTarget.readOnly && document.contains(fkbTarget)) {
    return fkbTarget;
  }
  return null;
}

function insertStringAtCaret(el, s) {
  const len = el.value.length;
  const start = (typeof el.selectionStart === "number") ? el.selectionStart : len;
  const end = (typeof el.selectionEnd === "number") ? el.selectionEnd : len;
  el.value = el.value.slice(0, start) + s + el.value.slice(end);
  const pos = start + s.length;
  el.setSelectionRange(pos, pos);
  el.focus();
  // Tastatura virtuala modifica .value direct; declansam 'input' ca sa fie sincronizata starea (oninput)
  el.dispatchEvent(new Event("input", { bubbles: true }));
}

function fkbKey(ch) {
  const el = fkbTargetElement();
  if (!el) {
    showToast("Atingeti mai intai un camp de text");
    return;
  }
  insertStringAtCaret(el, ch);
  if (fkbShift && !fkbCaps) {
    fkbShift = false;
    fkbRender();
  }
}

function fkbBackspace() {
  const el = fkbTargetElement();
  if (!el) return;
  const start = (typeof el.selectionStart === "number") ? el.selectionStart : el.value.length;
  const end = (typeof el.selectionEnd === "number") ? el.selectionEnd : el.value.length;
  if (start === end && start > 0) {
    el.value = el.value.slice(0, start - 1) + el.value.slice(end);
    el.setSelectionRange(start - 1, start - 1);
  } else if (start < end) {
    el.value = el.value.slice(0, start) + el.value.slice(end);
    el.setSelectionRange(start, start);
  }
  el.focus();
  el.dispatchEvent(new Event("input", { bubbles: true }));
}

function fkbClear() {
  const el = fkbTargetElement();
  if (!el) return;
  el.value = "";
  el.setSelectionRange(0, 0);
  el.focus();
  el.dispatchEvent(new Event("input", { bubbles: true }));
}

function updateLiveClock() {
  const now = new Date();
  const months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
  const day = String(now.getDate()).padStart(2, "0");
  const month = months[now.getMonth()];
  const year = String(now.getFullYear()).slice(-2);
  const hours = String(now.getHours()).padStart(2, "0");
  const mins = String(now.getMinutes()).padStart(2, "0");
  const formatted = `${day}-${month}-${year} &nbsp;&nbsp; ${hours}:${mins}`;

  document.getElementById("pos-clock-header").innerHTML = formatted;
  document.getElementById("pay-datetime").innerHTML = formatted;
}

// --------------------------------------------------------------------------
// PRINTARE BUCATARIE
// Trimite la bucatarie liniile notei inca netrimise (Preluat = 0).
// Momentan doar le marcheaza ca preluate; printarea efectiva se va adauga ulterior.
// --------------------------------------------------------------------------
async function printKitchen(docId) {
  if (!docId) return null;
  try {
    const resp = await fetch("api/order_action.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "print_kitchen", docId: docId })
    });
    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message);
    return res;
  } catch (err) {
    appAlert("Eroare la printare bucatarie: " + err.message);
    return null;
  }
}

async function actionMarcare() {
  if (isFastFood()) return;
  if (!POS_STATE.articole.length) {
    showToast("Nota este goala; nu exista ce marca");
    return;
  }
  const res = await printKitchen(POS_STATE.docId);
  if (!res) return;

  await loadOrder(POS_STATE.masaCurenta);
  showToast(res.message || "Comanda a fost trimisa la bucatarie!");
  refreshPrintStatus();
}

// --------------------------------------------------------------------------
// BUTON NOTA: deschide ecranul de inchidere nota.
// Daca totalul este 0, inchide direct nota si revine la ecranul de mese
// (in FastFood ramane pe ecranul de marcare).
// --------------------------------------------------------------------------
async function actionNota() {
  const total = parseFloat(document.getElementById("val-total").innerText) || 0;

  // Total 0: fie nota nu are nicio linie, fie liniile se anuleaza reciproc
  // (produs + storno / discount 100%). In ambele cazuri nota se poate inchide.
  if (total <= 0) {
    if (!POS_STATE.docId) { return; }
    if (POS_STATE.articole.length === 0) {
      if (POS_STATE.bankPosLocked === true) {
        // Cu bani confirmati pe card, nota goala se inchide din NOTA (acolo se
        // face void sau inchiderea pe zero, explicit de catre operator).
        if (POS_STATE.bankPosApproved === true) {
          closeEmptyNote();
          return;
        }
        // Blocata de o plata pe card incerta: inchiderea pe zero cere confirmare.
        closeEmptyUncertainBill();
        return;
      }
      closeEmptyNote();
      return;
    }
    // Are produse, dar valoarea neta este 0: se inchide fara bon fiscal.
    closeZeroTotalNote();
    return;
  }

  // Click pe Nota trimite automat si la imprimantele de sectie; reincarcam
  // nota ca liniile sa fie marcate ca trimise (qty blocat) si la revenirea
  // pe ecranul de marcare.
  // Mod FastFood: nu se trimite nimic la sectie.
  if (!isFastFood()) {
    await printKitchen(POS_STATE.docId);
    await loadOrder(POS_STATE.masaCurenta);
  }
  navigateToScreen("screen-plata");
}

// --------------------------------------------------------------------------
// FACTURARE POS (ecran de facturare a notelor inchise din ziua curenta)
// --------------------------------------------------------------------------
let facturaPrevScreen = "screen-mese";
let facturaFieldsBound = false;
let facturaBonuri = [];
let facturaBonSel = null;

function openFacturare() {
  const active = document.querySelector(".pos-screen.active");
  facturaPrevScreen = active ? active.id : "screen-mese";
  navigateToScreen("screen-facturare");
  facturaBindFields();
  loadFacturaBonuri();
  setTimeout(() => { const el = document.getElementById("fact-cif"); if (el) el.focus(); }, 60);
}

function closeFacturare() {
  closeFloatingKeyboard();
  resetFacturareForm();
  navigateToScreen(facturaPrevScreen || "screen-mese");
}

// Golesc campurile si selectia bonului (la inchiderea ecranului de facturare).
function resetFacturareForm() {
  document.querySelectorAll("#screen-facturare .fact-input").forEach(el => { el.value = ""; });
  const label = document.getElementById("fact-bon-label");
  if (label) label.textContent = "-";
  facturaBonSel = null;
  facturaAnafCif = "";
  applyFacturareLock(false);
  updateFacturaBonSelectBtn();
}

// Blocheaza/deblocheaza campurile facturii. Cand nota aleasa a fost deja
// facturata, datele partenerului sunt cele salvate si nu mai pot fi editate.
function applyFacturareLock(locked) {
  document.querySelectorAll("#screen-facturare .fact-input").forEach(el => {
    if (el.id === "fact-vat") return; // mereu readonly (0/1)
    el.readOnly = !!locked;
  });
  const anafBtn = document.getElementById("fact-anaf-btn");
  if (anafBtn) anafBtn.disabled = !!locked;
  const screen = document.getElementById("screen-facturare");
  if (screen) screen.classList.toggle("fact-locked", !!locked);
  if (locked) closeFloatingKeyboard();
}

// Completeaza campurile din datele clientului salvat (reprint).
function fillFacturareClient(c) {
  const set = (id, val) => { const el = document.getElementById(id); if (el) el.value = (val == null ? "" : val); };
  set("fact-cif", c.cif || "");
  set("fact-vat", (String(c.vat) === "1") ? "1" : "0");
  set("fact-den", c.denumire || "");
  set("fact-adresa", c.adresa || "");
  set("fact-judet", c.judet || "");
  set("fact-oras", c.oras || "");
  set("fact-regcom", c.regCom || "");
}

// Fiecare camp deschide tastatura virtuala standard (aceeasi folosita in
// ecranele de programare), care scrie in campul care are focusul.
function facturaBindFields() {
  if (facturaFieldsBound) return;
  document.querySelectorAll("#screen-facturare .fact-input").forEach(el => {
    if (el.readOnly) return;
    el.addEventListener("focus", () => { facturaOpenKeyboard(el); });
    el.addEventListener("click", () => { facturaOpenKeyboard(el); });
  });
  const cifEl = document.getElementById("fact-cif");
  if (cifEl) {
    cifEl.addEventListener("keydown", (e) => {
      if (e.key === "Enter") { e.preventDefault(); interogareANAF(); }
    });
  }
  facturaFieldsBound = true;
}

// Deschide tastatura standard pentru campul dat, fara sa o mai reseteze daca
// este deja afisata pentru acelasi camp (altfel CAPS s-ar dezactiva la fiecare
// tasta, pentru ca fiecare tasta re-focusa campul).
function facturaOpenKeyboard(el) {
  if (!el || el.readOnly) return; // campurile blocate (factura deja generata) nu se editeaza
  const m = document.getElementById("modal-fkb");
  if (m && m.classList.contains("active") && fkbTarget === el) return;
  openFloatingKeyboardFor(el.id);
}

// ---- Interogare server ANAF (datele firmei dupa CIF) --------------------
let facturaAnafCif = "";

async function interogareANAF() {
  const cifEl = document.getElementById("fact-cif");
  if (!cifEl || cifEl.readOnly) return;
  const cif = (cifEl.value || "").replace(/\s+/g, "");
  if (cif.length < 2) {
    appAlert("Introduceti codul fiscal (CIF) inainte de interogarea ANAF.");
    return;
  }
  if (cif === facturaAnafCif) {
    const d = document.getElementById("fact-den");
    if (d && d.value) { return; } // deja interogat cu succes pentru acest CIF
  }

  const btn = document.getElementById("fact-anaf-btn");
  if (btn) { btn.disabled = true; }
  showToast("Interogare server ANAF...");
  try {
    const data = await fetchJsonNoStore("api/anaf.php?cif=" + encodeURIComponent(cif));
    const f = data.found || {};
    const set = (id, val) => { const el = document.getElementById(id); if (el) el.value = val == null ? "" : val; };
    set("fact-den", f.denumire || "");
    set("fact-adresa", f.adresa || "");
    set("fact-judet", f.judet || "");
    set("fact-oras", f.oras || "");
    set("fact-regcom", f.regCom || "");
    set("fact-vat", f.platitorTVA ? "1" : "0");
    facturaAnafCif = cif;
    showToast("Date preluate de la ANAF");
  } catch (err) {
    facturaAnafCif = "";
    appAlert("Interogare ANAF: " + err.message);
  } finally {
    if (btn) { btn.disabled = false; }
  }
}

// ---- Selectia bonului (note inchise, ziua curenta) ----------------------
async function loadFacturaBonuri() {
  const tbody = document.getElementById("fact-bon-list");
  if (tbody) {
    tbody.innerHTML = '<tr class="inactive"><td colspan="4" style="text-align:center;color:#888;">Se incarca...</td></tr>';
  }
  try {
    const data = await fetchJsonNoStore("api/facturare.php?action=lista");
    if (data.status !== "success") throw new Error(data.message || "Eroare");
    facturaBonuri = data.bonuri || [];
    renderFacturaBonuri();
  } catch (err) {
    if (tbody) {
      tbody.innerHTML = '<tr class="inactive"><td colspan="4" style="text-align:center;color:#c00;">'
        + escapeHtml(err.message) + '</td></tr>';
    }
  }
}

function renderFacturaBonuri() {
  const tbody = document.getElementById("fact-bon-list");
  if (!tbody) return;
  if (!facturaBonuri.length) {
    tbody.innerHTML = '<tr class="inactive"><td colspan="4" style="text-align:center;">Nu exista note inchise in ziua curenta</td></tr>';
    updateFacturaBonSelectBtn();
    return;
  }
  tbody.innerHTML = facturaBonuri.map(b => {
    const sel = (facturaBonSel === b.docId) ? " selected" : "";
    return '<tr data-docid="' + b.docId + '" onclick="facturaBonSelectRow(' + b.docId + ')" class="' + sel.trim() + '">'
      + '<td class="printq-num">' + b.nrNota + '</td>'
      + '<td class="printq-num">' + b.suma.toFixed(2) + '</td>'
      + '<td>' + escapeHtml(b.ospatar) + '</td>'
      + '<td class="printq-num">' + b.masa + '</td>'
      + '</tr>';
  }).join("");
  updateFacturaBonSelectBtn();
}

function facturaBonSelectRow(docId) {
  facturaBonSel = docId;
  document.querySelectorAll("#fact-bon-list tr").forEach(rowEl => {
    rowEl.classList.toggle("selected", parseInt(rowEl.dataset.docid, 10) === docId);
  });
  updateFacturaBonSelectBtn();
}

function updateFacturaBonSelectBtn() {
  const btn = document.getElementById("btn-fact-bon-select");
  if (btn) btn.disabled = !facturaBonSel;
}

function openFacturaBonList() {
  facturaBonSel = null;
  document.getElementById("modal-factura-bon").classList.add("active");
  loadFacturaBonuri();
}

function closeFacturaBonList() {
  document.getElementById("modal-factura-bon").classList.remove("active");
}

function confirmFacturaBon() {
  if (!facturaBonSel) return;
  const b = facturaBonuri.find(x => x.docId === facturaBonSel);
  const label = document.getElementById("fact-bon-label");
  if (b && label) {
    label.textContent = "#" + b.nrNota + " - " + b.suma.toFixed(2) + " Lei - Masa " + b.masa;
  }
  closeFacturaBonList();
  loadFacturareBonStatus(facturaBonSel);
}

// Verifica daca nota aleasa a fost deja facturata. Daca da, completeaza
// formularul cu datele partenerului salvat si il blocheaza (reprint fara
// modificari); altfel lasa formularul gol si editabil.
async function loadFacturareBonStatus(docId, silent) {
  applyFacturareLock(false);
  try {
    const data = await fetchJsonNoStore("api/facturare.php?action=get&docId=" + encodeURIComponent(docId));
    if (data.client) {
      fillFacturareClient(data.client);
      applyFacturareLock(true);
      if (!silent) {
        if (data.generated) {
          showToast("Nota a fost deja facturata (" + (data.serie ? data.serie + " " : "") + data.nrFactura + ")");
        } else {
          showToast("Factura este in curs de generare; datele sunt blocate");
        }
      }
    } else {
      // Nota noua: curatam eventualele date rămase de la alta nota.
      ["fact-cif", "fact-vat", "fact-den", "fact-adresa", "fact-judet", "fact-oras", "fact-regcom"].forEach(id => {
        const el = document.getElementById(id); if (el) el.value = "";
      });
      facturaAnafCif = "";
    }
  } catch (err) {
    if (!silent) showToast("Nu pot verifica starea facturii: " + err.message);
  }
}

// Trimite factura la tiparire (pe imprimanta de note): datele furnizorului vin
// din tblAntet, cele ale clientului din formular, iar liniile din nota aleasa.
async function generazaFactura() {
  if (!facturaBonSel) {
    appAlert("Selectati mai intai bonul de facturat.");
    return;
  }
  const val = (id) => { const el = document.getElementById(id); return el ? el.value.trim() : ""; };
  const client = {
    cif: val("fact-cif"),
    vat: val("fact-vat") || "0",
    denumire: val("fact-den"),
    adresa: val("fact-adresa"),
    judet: val("fact-judet"),
    oras: val("fact-oras"),
    regCom: val("fact-regcom")
  };
  const lipsa = [];
  if (!client.cif) { lipsa.push("Cod Fiscal (CIF)"); }
  if (!client.denumire) { lipsa.push("Denumire Firma"); }
  if (!client.adresa) { lipsa.push("Adresa"); }
  if (!client.judet) { lipsa.push("Judet"); }
  if (lipsa.length) {
    appAlert("Completati campurile obligatorii: " + lipsa.join(", "));
    return;
  }

  try {
    showToast("Generez factura...");
    const resp = await fetch("api/facturare.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "print", docId: facturaBonSel, client: client })
    });
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message || "Eroare");
    const docId = facturaBonSel;
    showToast(data.message || "Factura a fost trimisa la tiparire");
    closeFloatingKeyboard();
    refreshPrintStatus();
    // Dupa generare, reincarcam starea: nota intra in tblFacturaDoc si
    // formularul se blocheaza pe datele partenerului salvat.
    loadFacturareBonStatus(docId, true);
  } catch (err) {
    appAlert("Generare factura: " + err.message);
  }
}

// --------------------------------------------------------------------------
// COADA DE TIPARIRE (tblPrintQueue via api/print_queue.php)
// --------------------------------------------------------------------------
let printQueueRows = [];
let printQueueSelected = null;

async function openPrintQueue() {
  document.getElementById("modal-print-queue").classList.add("active");
  await refreshPrintQueue();
}

function closePrintQueue() {
  document.getElementById("modal-print-queue").classList.remove("active");
  printQueueSelected = null;
  refreshPrintStatus();
}

async function refreshPrintQueue() {
  try {
    const resp = await fetch("api/print_queue.php?action=list", { cache: "no-store" });
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message || "Eroare");
    printQueueRows = data.rows || [];
    renderPrintQueue();
    // Coada si eticheta de pe buton se citesc din aceeasi stare: le sincronizam.
    refreshPrintStatus();
  } catch (err) {
    showToast("Eroare coada printare: " + err.message);
  }
}

async function refreshPrintStatus() {
  // Fara butonul de pe ecran (ex. in alte ecrane) nu are rost sa interogam.
  const btn = document.getElementById("btn-print-queue");
  if (!btn) return;
  try {
    const resp = await fetch("api/print_queue.php?action=status", { cache: "no-store" });
    const data = await resp.json();
    if (data.status !== "success") return;
    const inLucru = (data.pending || 0) + (data.printing || 0);
    const tt = (typeof window.t === "function") ? window.t : null;
    const base = tt ? tt("mese.coada_printare_html") : "Coada<br>printare";
    if (inLucru > 0 || (data.failed || 0) > 0) {
      const pendingTxt = tt ? tt("printq.pending", { n: inLucru }) : (inLucru + " in asteptare");
      const failedTxt = (data.failed || 0) > 0
        ? (tt ? tt("printq.failed", { n: data.failed }) : (", " + data.failed + " esuate"))
        : "";
      btn.innerHTML = base + `<div style="font-size:11px; font-weight:bold; color:#000000;">${pendingTxt}${failedTxt}</div>`;
    } else {
      btn.innerHTML = base;
    }
  } catch (err) {
    /* serviciul/API indisponibil: nu deranjam ecranul */
  }
}

function printJobLabel(r) {
  const tip = (r.Tip || "").toLowerCase();
  if (tip === "banca") return tr("Chitanță card (duplicat)");
  if (tip !== "raport") return r.Tip || "";
  const title = (r.Title || "").trim();
  if (!title) return tr("report.label");
  if (/^raport\s+z/i.test(title)) return tr("report.z");
  const m = title.match(/^raport\s+x\s*-\s*(.+)$/i);
  if (m) {
    const key = m[1].trim().toUpperCase();
    const nice = { PLU: "PLU", GRUPE: "report.tip.grupe", SECTII: "report.tip.sectii", CASIERI: "report.tip.casieri", GENERAL: "report.tip.general" };
    const tipLabel = nice[key] ? tr(nice[key]) : m[1].trim();
    return tr("report.x", { tip: tipLabel });
  }
  return tr("report.label") + title.replace(/^raport/i, "");
}

function renderPrintQueue() {
  const tbody = document.getElementById("printq-list");
  if (!tbody) return;
  tbody.innerHTML = "";

  if (printQueueRows.length === 0) {
    const emptyRow = document.createElement("tr");
    const emptyTxt = (typeof window.t === "function") ? window.t("printq.gol") : "Coada este goala";
    emptyRow.innerHTML = '<td colspan="7" style="color:#888; font-weight:normal;">' + emptyTxt + '</td>';
    tbody.appendChild(emptyRow);
    updatePrintQueueBtns();
    return;
  }

  printQueueRows.forEach(r => {
    const row = document.createElement("tr");
    const isFailed = r.Stare === "failed";
    if (r.JobID === printQueueSelected) row.classList.add("selected");
    if (isFailed) row.classList.add("inactive");
    const stareCls = isFailed ? "kp-off" : (r.Stare === "done" ? "kp-on" : "");
    const stareTitle = (r.Stare === "pending" && r.NextAttempt)
      ? ((typeof window.t === "function") ? window.t("printq.next_attempt", { t: r.NextAttempt }) : ("urmatoarea incercare: " + r.NextAttempt))
      : "";
    const errTitle = r.LastError || stareTitle;
    row.innerHTML = `
      <td class="printq-num"><b>${r.JobID}</b></td>
      <td>${escapeHtml(printJobLabel(r))}</td>
      <td>${r.RefDocID ? "doc " + r.RefDocID : "-"}</td>
      <td class="printq-num" title="${escapeHtml(r.PrinterName || '')}">${r.PrinterNr != null ? r.PrinterNr : "-"}</td>
      <td class="${stareCls}" title="${escapeHtml(stareTitle)}">${escapeHtml(r.Stare)}</td>
      <td title="${escapeHtml(r.CreatedAt || '')}">${escapeHtml(r.CreatedAt || "-")}</td>
      <td title="${escapeHtml(errTitle)}">${r.Attempts}${r.LastError ? " - " + escapeHtml(r.LastError) : ""}</td>
    `;
    row.onclick = () => {
      printQueueSelected = r.JobID;
      renderPrintQueue();
      updatePrintQueueBtns();
    };
    tbody.appendChild(row);
  });

  updatePrintQueueBtns();
}

function updatePrintQueueBtns() {
  const job = selectedPrintJob();
  const has = !!job;
  const done = has && job.Stare === "done";

  const preview = document.getElementById("btn-printq-preview");
  if (preview) preview.disabled = !has;

  // Retry doar pentru joburile neterminate (pending / printing / failed)
  const retry = document.getElementById("btn-printq-retry");
  if (retry) retry.disabled = !has || done;

  const del = document.getElementById("btn-printq-del");
  if (del) del.disabled = !has;
}

function selectedPrintJob() {
  return printQueueRows.find(r => r.JobID === printQueueSelected) || null;
}

function previewPrintJob() {
  const job = selectedPrintJob();
  if (!job) return;
  if (job.Tip === "fiscal") {
    appAlert((typeof window.t === "function") ? window.t("printq.fiscal_no_preview") : "Jobul fiscal scrie un fisier text in folderul spool (nu are preview).");
    return;
  }
  const base = "api/print_preview.php?jobId=" + job.JobID;
  document.getElementById("print-preview-frame").src = base + "&t=" + Date.now();
  document.getElementById("modal-print-preview").classList.add("active");
}

function closePrintPreview() {
  document.getElementById("modal-print-preview").classList.remove("active");
  document.getElementById("print-preview-frame").src = "";
}

async function retryPrintJob() {
  const job = selectedPrintJob();
  if (!job) return;
  if (job.Stare === "done") {
    showToast("Jobul este deja finalizat (folositi o retiparire)");
    return;
  }
  try {
    const resp = await fetch("api/print_queue.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "retry", jobId: job.JobID })
    });
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message);
    showToast("Job reprogramat");
    printQueueSelected = null;
    await refreshPrintQueue();
    refreshPrintStatus();
  } catch (err) {
    appAlert("Eroare retry: " + err.message);
  }
}

function deletePrintJob() {
  const job = selectedPrintJob();
  if (!job) return;
  appConfirm(`Stergeti jobul #${job.JobID} (${job.Tip})?`, async () => {
    try {
      const resp = await fetch("api/print_queue.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "delete", jobId: job.JobID })
      });
      const data = await resp.json();
      if (data.status !== "success") throw new Error(data.message);
      printQueueSelected = null;
      await refreshPrintQueue();
      refreshPrintStatus();
    } catch (err) {
      appAlert("Eroare stergere: " + err.message);
    }
  }, "Da", "Nu");
}

async function clearDonePrintJobs() {
  appConfirm("Stergeti toate joburile finalizate (done)?", async () => {
    try {
      const resp = await fetch("api/print_queue.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "clear_done" })
      });
      const data = await resp.json();
      if (data.status !== "success") throw new Error(data.message);
      await refreshPrintQueue();
    } catch (err) {
      appAlert("Eroare: " + err.message);
    }
  }, "Da", "Nu");
}

// --------------------------------------------------------------------------
// COADA EXPORT (temp_Send_Sql via api/send_queue.php)
// --------------------------------------------------------------------------
let sendQueueRows = [];
let sendQueueSelected = null;

async function openSendQueue() {
  document.getElementById("modal-send-queue").classList.add("active");
  await refreshSendQueue();
}

function closeSendQueue() {
  document.getElementById("modal-send-queue").classList.remove("active");
  sendQueueSelected = null;
}

async function refreshSendQueue() {
  try {
    const resp = await fetch("api/send_queue.php?action=list");
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message || "Eroare");
    sendQueueRows = data.rows || [];
    renderSendQueue();
  } catch (err) {
    showToast("Eroare coada export: " + err.message);
  }
}

function renderSendQueue() {
  const tbody = document.getElementById("sendq-list");
  if (!tbody) return;
  tbody.innerHTML = "";

  if (sendQueueRows.length === 0) {
    const emptyRow = document.createElement("tr");
    emptyRow.innerHTML = '<td colspan="6" style="color:#888; font-weight:normal;">' + escapeHtml(tr("printq.gol")) + '</td>';
    tbody.appendChild(emptyRow);
    updateSendQueueBtns();
    return;
  }

  sendQueueRows.forEach(r => {
    // Atentie: variabila se numeste `row`, nu `tr` - altfel umbreste functia
    // globala de traducere tr() si apelul tr("trimis: ") arunca
    // "tr is not a function" (randurile nu se mai randeaza deloc).
    const row = document.createElement("tr");
    const isFailed = r.Stare === "failed";
    const isDone = r.Preluat === true || r.Stare === "done";
    if (r.Id === sendQueueSelected) row.classList.add("selected");
    if (isFailed) row.classList.add("inactive");
    const stareCls = isFailed ? "kp-off" : (isDone ? "kp-on" : "");
    const stareTitle = (r.Stare === "sending" && r.NextAttempt) ? (tr("preluat la: ") + r.NextAttempt) : "";
    row.innerHTML = `
      <td class="printq-num"><b>${r.Id}</b></td>
      <td>${r.DocID != null ? "doc " + r.DocID : "-"}</td>
      <td class="${stareCls}" title="${escapeHtml(isDone ? (tr("trimis: ") + (r.SentAt || '')) : stareTitle)}">${escapeHtml(r.Stare)}</td>
      <td class="printq-num" title="${escapeHtml(stareTitle)}">${r.Attempts}</td>
      <td title="${escapeHtml(r.CreatedAt || '')}">${escapeHtml(r.CreatedAt || "-")}</td>
      <td title="${escapeHtml(r.LastError || '')}">${escapeHtml(r.LastError || "-")}</td>
    `;
    row.onclick = () => {
      sendQueueSelected = r.Id;
      renderSendQueue();
      updateSendQueueBtns();
    };
    tbody.appendChild(row);
  });

  updateSendQueueBtns();
}

function updateSendQueueBtns() {
  const job = selectedSendJob();
  const has = !!job;
  const done = has && (job.Preluat === true || job.Stare === "done");

  const view = document.getElementById("btn-sendq-view");
  if (view) view.disabled = !has;

  // Retry doar pentru bonurile netrimise
  const retry = document.getElementById("btn-sendq-retry");
  if (retry) retry.disabled = !has || done;

  const del = document.getElementById("btn-sendq-del");
  if (del) del.disabled = !has;
}

function selectedSendJob() {
  return sendQueueRows.find(r => r.Id === sendQueueSelected) || null;
}

async function viewSendJob() {
  const job = selectedSendJob();
  if (!job) return;
  try {
    const resp = await fetch("api/send_queue.php?action=get&id=" + job.Id);
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message || "Eroare");
    const docTxt = data.row.DocID != null ? " (doc " + data.row.DocID + ")" : "";
    document.getElementById("send-det-title").innerText = tr("COMANDA EXPORT #") + job.Id + docTxt;
    let content = data.row.str_sql || "(comanda goala)";
    const raspuns = (data.row.RaspunsCasa || "").trim();
    if (raspuns) {
      content += "\n\n--- RASPUNS CASA DE MARCAT ---\n" + data.row.RaspunsCasa;
    }
    document.getElementById("send-det-content").textContent = content;
    document.getElementById("modal-send-detail").classList.add("active");
  } catch (err) {
    appAlert("Eroare citire comanda: " + err.message);
  }
}

function closeSendDetail() {
  document.getElementById("modal-send-detail").classList.remove("active");
  document.getElementById("send-det-content").textContent = "";
}

async function retrySendJob() {
  const job = selectedSendJob();
  if (!job) return;
  if (job.Preluat === true || job.Stare === "done") {
    showToast("Bonul a fost deja trimis");
    return;
  }
  try {
    const resp = await fetch("api/send_queue.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "retry", id: job.Id })
    });
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message);
    showToast("Bon reprogramat pentru trimitere");
    sendQueueSelected = null;
    await refreshSendQueue();
  } catch (err) {
    appAlert("Eroare retry: " + err.message);
  }
}

function deleteSendJob() {
  const job = selectedSendJob();
  if (!job) return;
  const docTxt = job.DocID != null ? job.DocID : "-";
  appConfirm(`Stergeti bonul din coada de export #${job.Id} (doc ${docTxt})?`, async () => {
    try {
      const resp = await fetch("api/send_queue.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "delete", id: job.Id })
      });
      const data = await resp.json();
      if (data.status !== "success") throw new Error(data.message);
      sendQueueSelected = null;
      await refreshSendQueue();
    } catch (err) {
      appAlert("Eroare stergere: " + err.message);
    }
  }, "Da", "Nu");
}

async function clearDoneSendJobs() {
  appConfirm("Stergeti toate bonurile deja trimise (done)?", async () => {
    try {
      const resp = await fetch("api/send_queue.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "clear_done" })
      });
      const data = await resp.json();
      if (data.status !== "success") throw new Error(data.message);
      await refreshSendQueue();
    } catch (err) {
      appAlert("Eroare: " + err.message);
    }
  }, "Da", "Nu");
}

// --------------------------------------------------------------------------
// TRANSFER PRODUSE de la o masa la alta (popup touch, masa dest. dupa numar)
// --------------------------------------------------------------------------
let transferSelected = new Set();

function actionTransfer() {
  if (bankPosBlockedNotice()) { return; }
  if (!POS_STATE.articole.length) {
    showToast("Nu exista produse pe nota de transferat");
    return;
  }
  openTransferModal();
}

function openTransferModal() {
  transferSelected = new Set();
  document.getElementById("transfer-source-table").innerText = POS_STATE.masaCurenta;
  document.getElementById("transfer-dialog-title").innerText = tr("TRANSFER PRODUSE");
  document.getElementById("transfer-step-products").style.display = "block";
  document.getElementById("transfer-step-masa").style.display = "none";
  renderTransferList();
  updateTransferNextBtn();
  document.getElementById("modal-transfer").classList.add("active");
}

function closeTransferModal() {
  document.getElementById("modal-transfer").classList.remove("active");
  transferSelected = new Set();
}

function renderTransferList() {
  const list = document.getElementById("transfer-products-list");
  if (!list) return;
  list.innerHTML = "";

  POS_STATE.articole.forEach((item) => {
    const sel = transferSelected.has(item.ecrId);
    const row = document.createElement("div");
    row.className = `transfer-product-row ${sel ? "selected" : ""}`;
    row.innerHTML = `
      <div class="tpr-name">${escapeHtml(capitalizeName(item.denumire))}</div>
      <div class="tpr-qty">${parseFloat(item.cantitate).toFixed(3)}</div>
      <div class="tpr-check">${sel ? "✓" : ""}</div>
    `;
    row.onclick = () => toggleTransferItem(item.ecrId);
    list.appendChild(row);
  });
}

function toggleTransferItem(ecrId) {
  if (transferSelected.has(ecrId)) {
    transferSelected.delete(ecrId);
  } else {
    transferSelected.add(ecrId);
  }
  renderTransferList();
  updateTransferNextBtn();
}

function selectAllTransfer() {
  transferSelected = new Set(POS_STATE.articole.map(a => a.ecrId));
  renderTransferList();
  updateTransferNextBtn();
}

function clearTransfer() {
  transferSelected = new Set();
  renderTransferList();
  updateTransferNextBtn();
}

function updateTransferNextBtn() {
  const btn = document.getElementById("btn-transfer-next");
  if (btn) btn.disabled = transferSelected.size === 0;
}

function goTransferToMasa() {
  if (transferSelected.size === 0) {
    showToast("Selecteaza macar un produs");
    return;
  }
  document.getElementById("transfer-dialog-title").innerText = tr("MASA DESTINATIE");
  document.getElementById("transfer-step-products").style.display = "none";
  document.getElementById("transfer-step-masa").style.display = "block";
  document.getElementById("transfer-masa-input").value = "";
}

function transferMasaKey(d) {
  const input = document.getElementById("transfer-masa-input");
  if (input.value.length >= 3) return;
  input.value += d;
}

function transferMasaClear() {
  document.getElementById("transfer-masa-input").value = "";
}

function transferMasaBackspace() {
  const input = document.getElementById("transfer-masa-input");
  input.value = input.value.slice(0, -1);
}

async function execTransfer() {
  const val = parseInt(document.getElementById("transfer-masa-input").value, 10);
  if (isNaN(val) || val < 1 || val > 80) {
    showToast("Numar masa invalid (1-80)");
    return;
  }
  if (val === POS_STATE.masaCurenta) {
    showToast("Masa destinatie trebuie sa fie diferita de masa sursa");
    return;
  }

  const ecrIds = Array.from(transferSelected);

  try {
    const resp = await fetch("api/order_action.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        action: "transfer_products",
        nrMasa: POS_STATE.masaCurenta,
        destNrMasa: val,
        nrOp: POS_STATE.nrOp,
        ecrIds: ecrIds
      })
    });
    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message);

    closeTransferModal();
    showToast(`Transferat pe masa ${val} (${res.moved || ecrIds.length} produse)`);
    await loadOrder(POS_STATE.masaCurenta);
    await loadTables();
    selectLastAndScroll();
  } catch (err) {
    appAlert("Eroare la transfer: " + err.message);
  }
}

function actionModPreparare() {
  if (bankPosBlockedNotice()) { return; }
  if (!POS_STATE.articole.length) {
    showToast("Nu exista produse pe nota!");
    return;
  }
  if (POS_STATE.selectedItemIndex < 0 || POS_STATE.selectedItemIndex >= POS_STATE.articole.length) {
    POS_STATE.selectedItemIndex = 0;
  }
  openModsPanel();
}

// Deschide in panoul albastru modurile de preparare (tblMesaj), ordine alfabetica
function openModsPanel() {
  const produs = POS_STATE.articole[POS_STATE.selectedItemIndex];
  if (!produs) return;

  POS_STATE.currentPanelMode = "mods";

  const header = document.getElementById("group-nav-header");
  const title = document.getElementById("active-group-title");
  const backBtn = document.getElementById("btn-group-back");
  if (header && title) {
    header.style.display = "flex";
    title.innerText = tr("MOD PREPARARE: ") + produs.denumire;
    if (backBtn) {
      backBtn.innerText = tr("⬅ INAPOI LA PRODUSE");
      backBtn.onclick = () => backFromMods();
    }
  }

  const gridContainer = document.getElementById("menu-5x-grid");
  if (!gridContainer) return;
  gridContainer.innerHTML = "";

  const msgCount = Math.min(50, POS_STATE.messages.length);
  for (let i = 0; i < 50; i++) {
    const m = POS_STATE.messages[i];
    if (i < msgCount && m) {
      const btn = document.createElement("button");
      btn.className = "grid-pos-btn";
      btn.style.backgroundColor = "#ffff00";
      btn.style.color = "#000000";
      btn.style.borderColor = "#555555";
      btn.style.textTransform = "none";
      btn.style.fontSize = "13px";
      btn.innerHTML = escapeHtml(m.Mesaj);
      btn.onclick = () => addMod(m.NrMesaj);
      gridContainer.appendChild(btn);
    } else {
      const emptyDiv = document.createElement("div");
      emptyDiv.className = "grid-pos-btn empty";
      gridContainer.appendChild(emptyDiv);
    }
  }

  showToast(`Mod preparare pentru: ${produs.denumire}`);
}

// Intoarcere din panoul de moduri la lista de produse a grupei active
function backFromMods() {
  if (POS_STATE.activeGroup) {
    openCategory(POS_STATE.activeGroup.nrGrp, POS_STATE.activeGroup.groupName);
  } else {
    renderMenuGrid();
  }
}

// Adauga modul de preparare selectat pe produsul curent din nota
async function addMod(nrMesaj) {
  if (!POS_STATE.articole.length) return;
  try {
    const resp = await fetch("api/order_action.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        action: "add_mod",
        nrMasa: POS_STATE.masaCurenta,
        nrMesaj: nrMesaj
      })
    });
    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message);
    showToast("Mod de preparare adaugat");
    await loadOrder(POS_STATE.masaCurenta);
  } catch (err) {
    appAlert("Eroare la adaugarea modului de preparare: " + err.message);
  }
}

// Sterge un mod de preparare adaugat pe nota
async function deleteMod(ecrId) {
  if (!ecrId) return;
  try {
    const resp = await fetch("api/order_action.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        action: "delete_mod",
        nrMasa: POS_STATE.masaCurenta,
        ecrId: ecrId
      })
    });
    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message);
    showToast("Mod de preparare sters");
    await loadOrder(POS_STATE.masaCurenta);
    await loadTables();
  } catch (err) {
    appAlert("Eroare la stergerea modului: " + err.message);
  }
}

let qtyEditingIndex = -1;

// Butonul QTY: deschide popup-ul de modificare a cantitatii pentru articolul selectat
function actionQty() {
  if (bankPosBlockedNotice()) { return; }
  if (!POS_STATE.articole.length) {
    showToast("Nota este goala; nu exista articole");
    return;
  }
  const it = discSelectedItem();
  if (!it) {
    showToast("Selecteaza un articol pentru a-i modifica cantitatea");
    return;
  }
  if (it.storno) {
    showToast("Nu puteti modifica cantitatea unei linii de anulare");
    return;
  }
  if (it.preluat) {
    showToast("Produsul a fost trimis la sectie; cantitatea nu mai poate fi modificata");
    return;
  }
  if (POS_STATE.cantar) {
    qtyFromCantar(POS_STATE.selectedItemIndex);
    return;
  }
  openQtyModal(POS_STATE.selectedItemIndex);
}

// Butonul QTY cu cantar electronic activ: lanseaza driverul (prin api/cantar.php),
// asteapta Delay_cantar si preia cantitatea citita. Daca nu raspunde, deschide
// tastatura numerica pentru introducere manuala, cu un badge de atentionare.
async function qtyFromCantar(index) {
  showToast("Se citeste cantarul electronic...");
  let value = null;
  let noResponse = false;
  try {
    const resp = await fetch("api/cantar.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      cache: "no-store",
      body: JSON.stringify({ action: "read" })
    });
    const res = await resp.json();
    if (res.status === "success" && res.enabled && res.value != null && Number(res.value) > 0) {
      value = Number(res.value);
    } else {
      noResponse = true;
    }
  } catch (err) {
    noResponse = true;
  }
  if (value !== null) {
    // Cantitatea de la cantar merge direct in nota, fara popup de introducere.
    // O rotunjim la numarul de zecimale configurat (ca update_qty pe server).
    const rounded = Number(Number(value).toFixed(POS_STATE.nrZecCant));
    showToast("Cantar: " + rounded + " kg");
    await applyQuantityToLine(index, rounded);
  } else {
    // Fara raspuns: tastatura numerica (goala) + badge de atentionare.
    openQtyModal(index, null, null, noResponse);
  }
}

// Deschide popup-ul cu tastatura numerica pentru modificarea cantitatii.
// prefill = valoare preluata (ex. de la cantarul electronic) care e pusa in camp;
// cantarError = true afiseaza badge-ul "cantarul electronic nu raspunde".
function openQtyModal(index, ev, prefill, cantarError) {
  if (ev) ev.stopPropagation();
  const item = POS_STATE.articole[index];
  if (!item) return;
  if (!item.storno && item.preluat) {
    showToast("Produsul a fost trimis la sectie; cantitatea nu mai poate fi modificata");
    return;
  }
  qtyEditingIndex = index;

  // Selectam randul ca sa fie evidentiat cu galben (campul de cantitate)
  if (POS_STATE.selectedItemIndex !== index) {
    selectOrderItem(index);
  }

  document.getElementById("qty-product-name").innerText = item.denumire;
  const input = document.getElementById("qty-input-display");
  // Cand avem o valoare preluata (cantar), o punem in camp; altfel pornim gol.
  if (prefill !== undefined && prefill !== null && !isNaN(Number(prefill))) {
    input.value = Number(prefill).toFixed(POS_STATE.nrZecCant);
  } else {
    input.value = "";
  }

  // Badge pentru cantarul electronic care nu a raspuns.
  const badge = document.getElementById("qty-cantar-badge");
  if (badge) {
    if (cantarError) {
      badge.textContent = tr("Cantarul electronic nu raspunde");
      badge.style.display = "";
    } else {
      badge.style.display = "none";
    }
  }

  // Adaptam tastatura la numarul de zecimale configurat (tblSet.NrZecCant).
  const zec = POS_STATE.nrZecCant;
  const dot = document.getElementById("qty-dot-key");
  if (dot) {
    // Pastram celula in grid (nu stricam aranjarea tastaturii).
    dot.style.visibility = (zec > 0) ? "visible" : "hidden";
    dot.disabled = (zec <= 0);
  }
  const hint = document.getElementById("qty-dec-hint");
  if (hint) {
    hint.textContent = (zec === 0)
      ? tr("doar numere intregi")
      : tr(zec === 1 ? "maxim 1 zecimala" : "maxim 2 zecimale");
  }

  document.getElementById("modal-qty").classList.add("active");
}

function closeQtyModal() {
  document.getElementById("modal-qty").classList.remove("active");
  qtyEditingIndex = -1;
}

// Apeleaza la fiecare tasta apasata in tastatura cantitatii
function qtyKey(ch) {
  const input = document.getElementById("qty-input-display");
  let v = input.value;
  const zec = POS_STATE.nrZecCant;

  if (ch === ".") {
    if (zec <= 0) return; // fara zecimale
    if (!v.includes(".")) {
      input.value = v === "" ? "0." : v + ".";
    }
    return;
  }

  // cifra: respectam numarul de zecimale configurat
  if (v.includes(".")) {
    const dec = v.split(".")[1];
    if (dec.length >= zec) return;
  }
  const nv = (v === "0") ? ch : (v + ch);
  const n = parseFloat(nv);
  if (!isNaN(n) && n > POS_STATE.cantMax) {
    showToast("Cantitatea maxima admisa este " + POS_STATE.cantMax);
    return;
  }
  input.value = nv;
}

function qtyBackspace() {
  const input = document.getElementById("qty-input-display");
  input.value = input.value.slice(0, -1);
}

function qtyClear() {
  document.getElementById("qty-input-display").value = "";
}

// Aplica o cantitate unei linii de nota (update_qty + reincarcare). Reutilizata
// atat de tastatura numerica (confirmQty), cat si de cantarul electronic (care
// scrie direct in nota, fara popup). Intoarce true la succes.
async function applyQuantityToLine(index, cantitate) {
  const item = POS_STATE.articole[index];
  if (!item || isNaN(cantitate) || cantitate <= 0) {
    showToast("Cantitatea trebuie sa fie mai mare decat 0");
    return false;
  }
  if (cantitate > POS_STATE.cantMax) {
    showToast("Cantitatea maxima admisa este " + POS_STATE.cantMax);
    return false;
  }

  try {
    const resp = await fetch("api/order_action.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        action: "update_qty",
        nrMasa: POS_STATE.masaCurenta,
        ecrId: item.ecrId,
        cantitate: cantitate
      })
    });
    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message);

    showToast(`Cantitate setata: ${cantitate}`);
    await loadOrder(POS_STATE.masaCurenta);
    await loadTables();

    // Pastram selectat produsul editat (numarul de produse nu s-a schimbat)
    if (index >= 0 && index < POS_STATE.articole.length) {
      POS_STATE.selectedItemIndex = index;
      renderOrderItems();
    }
    return true;
  } catch (err) {
    appAlert("Eroare la actualizarea cantitatii: " + err.message);
    return false;
  }
}

// Confirma noua cantitate (din tastatura numerica) si salveaza in baza de date
async function confirmQty() {
  const input = document.getElementById("qty-input-display");
  const cantitate = parseFloat(input.value);
  const idx = qtyEditingIndex;
  const ok = await applyQuantityToLine(idx, cantitate);
  if (ok) { closeQtyModal(); }
}

function actionCUI() {
  openCuiModal();
}

// --------------------------------------------------------------------------
// DISCOUNT (pop-up dedicat; aplicarea in tabele se face mai tarziu)
// --------------------------------------------------------------------------
let discScope = "line";     // 'line' | 'bill'
let discMode = "percent";   // 'percent' | 'valoric'
let discBuffer = "";        // valoarea tastata
let discMotiv = "";

function discSubtotal() {
  return POS_STATE.articole.reduce((s, it) => s + lineValNet(it), 0);
}

function discSelectedItem() {
  const i = POS_STATE.selectedItemIndex;
  return (POS_STATE.articole[i]) ? POS_STATE.articole[i] : null;
}

// Baza de calcul este valoarea neta de catalog (PVC), dupa voidari, pentru ca un
// discount nou sa inlocuiasca discountul anterior, nu sa se cumuleze cu el.
function discBase() {
  if (discScope === "bill") return discSubtotal();
  const it = discSelectedItem();
  return it ? lineValNet(it) : 0;
}

function discNum() {
  const n = parseFloat(discBuffer.replace(",", "."));
  return isNaN(n) ? 0 : n;
}

function discReduction() {
  const base = discBase();
  const v = discNum();
  if (base <= 0 || v <= 0) return { val: 0, pct: 0 };
  if (discMode === "percent") {
    const capped = Math.min(v, 100);
    const red = base * capped / 100;
    return { val: Math.min(red, base), pct: capped };
  }
  const red = Math.min(v, base);
  return { val: red, pct: (red / base) * 100 };
}

function discNewTotal() {
  const base = discBase();
  return base - discReduction().val;
}

function discScopeLineDisabled() {
  // Nu permitem discount pe produs daca linia nu mai are valoare (a fost voidata integral)
  const it = discSelectedItem();
  return !it || lineValNet(it) <= 0.0001;
}

function openDiscountModal() {
  // Deschidem pe "produs" doar daca linia selectata mai are valoare neta;
  // altfel (linie voidata integral sau fara selectie) pe nota intreaga.
  const it = discSelectedItem();
  if (POS_STATE.tipDiscount === 1 || POS_STATE.tipDiscount === 2) {
    openDiscountSubtotalModal();
    return;
  }
  discScope = (it && lineValNet(it) > 0.0001) ? "line" : "bill";
  discBuffer = "";
  discMotiv = "";
  const titleFull = document.getElementById("disc-modal-title");
  if (titleFull) titleFull.textContent = tr("disc.titlu");
  refreshMotiveSelects();
  discApplySimpleVisibility(false);
  discRender();
  document.getElementById("modal-discount").classList.add("active");
  discRefreshScopeButtons();
  // Sincronizam butoanele de tip cu modul curent (pastrat de la ultima utilizare),
  // ca butonul activ sa corespunda intotdeauna calculului.
  discRefreshModeButtons();
  discRecalcEstimate();
}

// Popup simplificat pentru TipDiscount = 1: doar procent pe subtotal, cu
// tastatura numerica 0-100 si buton de confirmare.
function openDiscountSubtotalModal() {
  discScope = "bill";
  discMode = "percent";
  discBuffer = "";
  discMotiv = "";
  const motivSel = document.getElementById("disc-motiv-select");
  if (motivSel) motivSel.value = "";
  discApplySimpleVisibility(true);
  const title = document.getElementById("disc-modal-title");
  if (title) title.textContent = tr("DISCOUNT PROCENT SUBTOTAL");
  discRender();
  document.getElementById("modal-discount").classList.add("active");
  discRefreshScopeButtons();
  discRefreshModeButtons();
  discRecalcEstimate();
}

// Ascunde/afiseaza in modalul de discount sectiunile care nu se folosesc in
// modul "doar procent la subtotal" (nivel, tip, presets, motiv).
function discApplySimpleVisibility(simple) {
  const ids = ["disc-scope-section", "disc-mode-section", "disc-presets-block", "disc-motiv-block"];
  ids.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.display = simple ? "none" : "";
  });
}

function closeDiscountModal() {
  document.getElementById("modal-discount").classList.remove("active");
  // Parola verificata este valabila doar pentru aceasta operatiune de discount.
  POS_STATE.discountParola = "";
}

function discSetScope(scope) {
  discScope = scope;
  discRender();
  discRefreshScopeButtons();
  discRecalcEstimate();
}

function discSetMode(mode) {
  discMode = mode;
  discRender();
  discRefreshModeButtons();
  discRecalcEstimate();
}

function discRefreshScopeButtons() {
  const line = document.getElementById("disc-scope-line");
  const bill = document.getElementById("disc-scope-bill");
  if (line) line.classList.toggle("on", discScope === "line");
  if (bill) bill.classList.toggle("on", discScope === "bill");
}

function discRefreshModeButtons() {
  const p = document.getElementById("disc-mode-percent");
  const v = document.getElementById("disc-mode-valoric");
  if (p) p.classList.toggle("on", discMode === "percent");
  if (v) v.classList.toggle("on", discMode === "valoric");
}

function discRender() {
  // Etichete scop
  const sel = discSelectedItem();
  const sub = discSubtotal();
  const lblLine = document.getElementById("disc-line-label");
  const lblBill = document.getElementById("disc-bill-label");
  if (lblLine) lblLine.innerText = sel
    ? `${capitalizeName(sel.denumire)} — ${lineValNet(sel).toFixed(2)} Lei`
    : tr("Niciun produs selectat");
  if (lblBill) lblBill.innerText = `Subtotal: ${sub.toFixed(2)} Lei`;

  // Dezactivam butonul de produs daca nu e selectat nimic
  const scopeLine = document.getElementById("disc-scope-line");
  if (scopeLine) scopeLine.disabled = discScopeLineDisabled();

  // Sufix: % sau Lei
  const suffix = document.getElementById("disc-value-suffix");
  if (suffix) suffix.innerText = (discMode === "percent") ? "%" : "Lei";

  discRenderPresets();
}

function discRenderPresets() {
  const wrap = document.getElementById("disc-presets");
  if (!wrap) return;
  wrap.innerHTML = "";
  let list = [];
  if (discMode === "percent") {
    list = [5, 10, 15, 20, 25, 50].map(v => ({ label: `-${v}%`, val: String(v) }));
  } else {
    list = [5, 10, 20, 50].map(v => ({ label: `-${v} Lei`, val: String(v) }));
  }
  list.forEach(p => {
    const b = document.createElement("button");
    b.className = "disc-preset-btn";
    b.innerText = p.label;
    b.onclick = () => {
      discBuffer = p.val;
      discRecalcEstimate();
      discRefreshValue();
    };
    wrap.appendChild(b);
  });
}

function discRefreshValue() {
  const box = document.getElementById("disc-value");
  if (box) box.innerText = discBuffer === "" ? "0" : discBuffer;
}

function discRecalcEstimate() {
  const base = discBase();
  const { val, pct } = discReduction();
  const newT = discBase() - val;

  const set = (id, txt) => {
    const el = document.getElementById(id);
    if (el) el.innerText = txt;
  };
  set("disc-est-base", base.toFixed(2) + " Lei");
  set("disc-est-red", "-" + val.toFixed(2) + " Lei" + (discMode === "percent" ? ` (${pct}%)` : ""));
  set("disc-est-new", newT.toFixed(2) + " Lei");

  // Dezactivam "Aplica" daca nu exista valoare sau subtotal
  const applyBtn = document.getElementById("btn-disc-apply");
  if (applyBtn) applyBtn.disabled = (val <= 0 || base <= 0);
  discRefreshValue();
}

function discNumpad(ch) {
  if (ch === "C") {
    discBuffer = "";
    discRecalcEstimate();
    return;
  }
  if (ch === ".") {
    if (discBuffer.indexOf(".") === -1 && discBuffer !== "") {
      discBuffer += ".";
      discRecalcEstimate();
    }
    return;
  }
  if (discBuffer.replace(".", "").length >= 5) return;  // max 5 cifre
  if (discBuffer === "" && ch === "0") {
    discBuffer = "0";
  } else {
    discBuffer += ch;
  }
  discRecalcEstimate();
}

async function discApply(fixedPct) {
  // Cand fixedPct este dat (TipDiscount = 2), aplicam procentul fix pe subtotal
  // direct, fara sa depindem de starea modalului.
  if (fixedPct != null) {
    discScope = "bill";
    discMode = "percent";
    discBuffer = String(fixedPct);
    const motivSel = document.getElementById("disc-motiv-select");
    if (motivSel) motivSel.value = "";
  }
  // Daca discountul cere parola si nu a fost inca verificata, o cerem acum.
  if (POS_STATE.parolaDiscount === 1 && !POS_STATE.discountParola) {
    ensureDiscountParola(() => discApply(fixedPct));
    return;
  }

  const base = discBase();
  const v = discNum();
  if (base <= 0) {
    showToast("Nu exista valoare pe care sa se aplice discount");
    return;
  }
  if (v <= 0) {
    showToast("Introduceti o valoare de discount");
    return;
  }
  if (discMode === "percent" && v > 100) {
    showToast("Discountul procentual nu poate depasi 100%");
    return;
  }
  if (discMode === "valoric" && v > base) {
    showToast("Discountul valoric nu poate depasi " + base.toFixed(2) + " Lei");
    return;
  }

  const motivEl = document.getElementById("disc-motiv-select");
  discMotiv = motivEl ? motivEl.value : "";

  const payload = {
    action: "apply_discount",
    nrMasa: POS_STATE.masaCurenta,
    scope: discScope,
    mode: discMode,
    value: v,
    motiv: discMotiv
  };
  if (POS_STATE.parolaDiscount === 1) {
    payload.parola = POS_STATE.discountParola;
  }

  if (discScope === "line") {
    const it = discSelectedItem();
    if (!it) {
      showToast("Selecteaza un produs pe care sa aplici discountul");
      return;
    }
    payload.ecrId = it.ecrId;
  }

  try {
    const resp = await fetch("api/order_action.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const res = await resp.json();
    if (res.status !== "success") {
      // Daca serverul cere parola (sau a fost schimbata intre timp), o cerem acum.
      if (res.required === true || /parola/i.test(res.message || "")) {
        POS_STATE.discountParola = "";
        POS_STATE.parolaDiscount = 1;
        ensureDiscountParola(() => discApply(fixedPct));
        return;
      }
      throw new Error(res.message);
    }

    POS_STATE.discountParola = "";
    closeDiscountModal();
    await loadOrder(POS_STATE.masaCurenta);
    syncPaymentScreen();

    if (discMotiv) {
      showToast("Discount aplicat. Motiv: " + discMotiv);
    } else {
      showToast("Discount aplicat!");
    }
  } catch (err) {
    appAlert("Eroare la aplicarea discountului: " + err.message);
  }
}

function actionDiscount() {
  if (bankPosBlockedNotice()) { return; }
  if (POS_STATE.red === 0) {
    showToast("Discountul nu este permis.");
    return;
  }
  if (!POS_STATE.articole.length) {
    showToast("Nota este goala; nu exista pe ce sa se aplice discount");
    return;
  }
  // TipDiscount = 2: procent fix pe subtotal, aplicat direct, fara popup.
  if (POS_STATE.tipDiscount === 2) {
    const pct = Number(POS_STATE.procentDiscountFix);
    if (!(pct > 0) || pct > 100) {
      showToast("Procentul fix de discount nu este configurat (Setari > ProcentDiscountFix).");
      return;
    }
    ensureDiscountParola(() => discApply(pct));
    return;
  }
  ensureDiscountParola(() => openDiscountModal());
}

// Cere parola de discount (daca este setata in tblParola.ParolaDiscount) inainte
// de o operatiune de discount. Tastatura este numerica (parola = doar cifre).
// Intreaba serverul de fiecare data, ca sa nu depinda de un flag local invechit.
async function ensureDiscountParola(cb) {
  if (POS_STATE.discountParola) {
    if (cb) cb();
    return;
  }

  let required = (POS_STATE.parolaDiscount === 1);
  try {
    const resp = await fetch("api/order_action.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "verify_discount_parola", parola: "" })
    });
    const res = await resp.json();
    required = (res.required === true);
    POS_STATE.parolaDiscount = required ? 1 : 0;
  } catch (err) {
    // Daca nu putem verifica, ne bazam pe flag-ul local.
  }

  if (!required) {
    if (cb) cb();
    return;
  }
  promptDiscountParola(cb);
}

function promptDiscountParola(cb) {
  openNumericPrompt("PAROLA DISCOUNT", async (val) => {
    if (!val) {
      showToast("Introduceti parola de discount!");
      promptDiscountParola(cb);
      return;
    }
    try {
      const resp = await fetch("api/order_action.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "verify_discount_parola", parola: val })
      });
      const res = await resp.json();
      if (res.status !== "success") {
        appAlert("Parola de discount incorecta!");
        promptDiscountParola(cb);
        return;
      }
      POS_STATE.discountParola = val;
      if (cb) cb();
    } catch (err) {
      appAlert("Eroare verificare parola: " + err.message);
    }
  });
}

// --------------------------------------------------------------------------
// ANULARE ARTICOL (VD): stergere daca nu e trimis, storno daca e trimis
// --------------------------------------------------------------------------
let voidTarget = null;
let voidBuffer = "1";
let voidFresh = true;

function voidRamas(item) {
  if (!item) return 0;
  const cant = parseFloat(item.cantitate) || 0;
  if (typeof item.ramas === "number") return Math.max(0, item.ramas);
  const stornat = parseFloat(item.stornat) || 0;
  return Math.max(0, cant - stornat);
}

function actionVoid() {
  if (bankPosBlockedNotice()) { return; }
  if (!POS_STATE.articole.length) {
    showToast("Nota este goala; nu exista ce anula");
    return;
  }
  const it = discSelectedItem();
  if (!it) {
    showToast("Selecteaza un articol pe care sa il anulezi");
    return;
  }

  // Linie de storno: se sterge direct (fara confirmare) cat timp nu a fost marcata
  if (it.storno) {
    if (it.preluat) {
      showToast("Storno-ul a fost deja trimis la bucatarie; nu mai poate fi anulat");
      return;
    }
    voidSend({
      action: "void_line",
      nrMasa: POS_STATE.masaCurenta,
      ecrId: it.ecrId,
      cantitate: Math.abs(parseFloat(it.cantitate) || 0)
    });
    return;
  }

  const ramas = voidRamas(it);
  if (ramas <= 0.0001) {
    showToast("Linia a fost deja anulata integral");
    return;
  }

  // Produs netrimis (Preluat = 0): se sterge direct, fara popup
  if (!it.preluat) {
    voidSend({
      action: "void_line",
      nrMasa: POS_STATE.masaCurenta,
      ecrId: it.ecrId,
      cantitate: ramas
    });
    return;
  }

  // Produs deja trimis (Preluat = 1): popup cu cantitate + motiv + parola
  voidTarget = it;
  voidBuffer = (ramas < 1) ? String(ramas) : "1";
  voidFresh = true;

  document.getElementById("void-prod-name").innerText = capitalizeName(it.denumire);
  document.getElementById("void-cant-linie").innerText = (parseFloat(it.cantitate) || 0).toFixed(2);
  document.getElementById("void-deja").innerText = (parseFloat(it.stornat) || 0).toFixed(2);
  document.getElementById("void-ramas").innerText = ramas.toFixed(2);
  refreshMotiveSelects();
  document.getElementById("void-motiv-select").value = "";
  document.getElementById("void-parola").value = "";
  document.getElementById("btn-void-apply").innerText = tr("CONFIRMA STORNO");

  // Daca ParolaStornare nu este setata, nu cerem nici parola, nici motiv:
  // ascundem complet cele doua campuri din popup.
  const needStornoCreds = POS_STATE.parolaStornare === 1;
  const motivBox = document.getElementById("void-motiv-box");
  const parolaBox = document.getElementById("void-parola-box");
  if (motivBox) motivBox.style.display = needStornoCreds ? "" : "none";
  if (parolaBox) parolaBox.style.display = needStornoCreds ? "" : "none";

  voidRefresh();
  document.getElementById("modal-void").classList.add("active");
}

function closeVoidModal() {
  document.getElementById("modal-void").classList.remove("active");
  closeFloatingKeyboard();
  voidTarget = null;
}

function voidNum() {
  const n = parseFloat(voidBuffer.replace(",", "."));
  return isNaN(n) ? 0 : n;
}

function voidNumpad(ch) {
  if (ch === "C") {
    voidBuffer = "";
    voidFresh = false;
    voidRefresh();
    return;
  }
  if (ch === ".") {
    if (voidFresh) {
      voidBuffer = "0.";
      voidFresh = false;
      voidRefresh();
      return;
    }
    if (voidBuffer.indexOf(".") === -1 && voidBuffer !== "") {
      voidBuffer += ".";
      voidRefresh();
    }
    return;
  }
  // Prima cifra apasata inlocuieste valoarea implicita (1), nu se adauga la ea
  if (voidFresh) {
    voidBuffer = "";
    voidFresh = false;
  }
  if (voidBuffer.replace(".", "").length >= 5) return;
  if (voidBuffer === "" && ch === "0") {
    voidBuffer = "0";
  } else {
    voidBuffer += ch;
  }
  voidRefresh();
}

function voidRefresh() {
  const box = document.getElementById("void-value");
  if (box) box.innerText = voidBuffer === "" ? "0" : voidBuffer;

  const ramas = voidRamas(voidTarget);
  const v = voidNum();
  const warn = document.getElementById("void-warn");
  const applyBtn = document.getElementById("btn-void-apply");
  if (warn) warn.innerText = (v > ramas + 0.0001) ? ("Maxim disponibil: " + ramas.toFixed(2)) : "";
  if (applyBtn) applyBtn.disabled = (v <= 0 || v > ramas + 0.0001);
}

// Trimite cererea de anulare catre server si reincarca nota
async function voidSend(payload) {
  if (bankPosBlockedNotice()) { return; }
  try {
    const resp = await fetch("api/order_action.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message);

    closeVoidModal();
    await loadOrder(POS_STATE.masaCurenta);
    await loadTables();
    syncPaymentScreen();
    showToast(res.message || "Articol anulat");
  } catch (err) {
    appAlert("Eroare la anulare: " + err.message);
  }
}

// Aplicare din popup (doar pentru produse deja trimise)
function voidApply() {
  if (!voidTarget) return;

  const ramas = voidRamas(voidTarget);
  const v = voidNum();
  if (v <= 0) {
    showToast("Introduceti cantitatea de anulat");
    return;
  }
  if (v > ramas + 0.0001) {
    showToast("Nu puteti anula mai mult de " + ramas.toFixed(2));
    return;
  }

  const motivEl = document.getElementById("void-motiv-select");
  const parolaEl = document.getElementById("void-parola");

  const payload = {
    action: "void_line",
    nrMasa: POS_STATE.masaCurenta,
    ecrId: voidTarget.ecrId,
    cantitate: v
  };

  // Motivul + parola se cer doar daca ParolaStornare este setata.
  if (POS_STATE.parolaStornare === 1) {
    const motiv = motivEl ? motivEl.value : "";
    const parola = parolaEl ? parolaEl.value : "";
    if (!motiv) {
      showToast("Selectati motivul anularii");
      return;
    }
    if (!parola) {
      showToast("Introduceti parola de stornare");
      return;
    }
    payload.motiv = motiv;
    payload.parola = parola;
  }

  voidSend(payload);
}

async function actionProforma() {
  if (!POS_STATE.articole.length) {
    showToast("Nota este goala; nu exista ce tipari");
    return;
  }
  try {
    const resp = await fetch("api/order_action.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ action: "print_proforma", docId: POS_STATE.docId })
    });
    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message);
    showToast(res.message || "Nota proforma a fost trimisa la tiparire");
    refreshPrintStatus();
  } catch (err) {
    appAlert("Eroare la tiparirea notei proforma: " + err.message);
  }
}

function showToast(text, ms) {
  const toast = document.getElementById("pos-toast");
  if (!toast) return;
  toast.innerText = tr(text);
  toast.style.display = "block";
  clearTimeout(showToast._t);
  showToast._t = setTimeout(() => {
    toast.style.display = "none";
  }, ms || 1800);
}

// Scrie cu litera mare prima litera a unui nume, restul il lasa intact (doar afisare)
function capitalizeName(text) {
  if (!text) return "";
  const s = String(text);
  return s.charAt(0).toUpperCase() + s.slice(1);
}

function escapeHtml(text) {
  if (!text) return "";
  return String(text)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

// Tastatura virtuala flotanta: o putem muta cu capul antetului,
// ca sa nu acopere campul pe care il editam
function initFloatingKeyboardDrag() {
  const header = document.querySelector("#modal-fkb .keyboard-dialog-header");
  const dlg = document.getElementById("fkb-dialog");
  if (!header || !dlg) return;

  header.addEventListener("pointerdown", (e) => {
    if (e.target.closest("button")) return;

    if (header.setPointerCapture) {
      try { header.setPointerCapture(e.pointerId); } catch (err) {}
    }

    const stage = document.getElementById("pos-stage");
    const sr = stage ? stage.getBoundingClientRect() : null;
    const scale = (stage && stage.offsetWidth) ? (sr.width / stage.offsetWidth) : 1;
    const offX = e.clientX - dlg.getBoundingClientRect().left;
    const offY = e.clientY - dlg.getBoundingClientRect().top;

    function move(ev) {
      let x = (ev.clientX - offX - (sr ? sr.left : 0)) / scale;
      let y = (ev.clientY - offY - (sr ? sr.top : 0)) / scale;
      if (stage) {
        x = Math.max(0, Math.min(x, stage.offsetWidth - dlg.offsetWidth));
        y = Math.max(0, Math.min(y, stage.offsetHeight - dlg.offsetHeight));
      }
      dlg.style.left = x + "px";
      dlg.style.top = y + "px";
    }

    function end() {
      header.removeEventListener("pointermove", move);
      header.removeEventListener("pointerup", end);
      header.removeEventListener("pointercancel", end);
    }

    header.addEventListener("pointermove", move);
    header.addEventListener("pointerup", end);
    header.addEventListener("pointercancel", end);
  });
}

document.addEventListener("DOMContentLoaded", initFloatingKeyboardDrag);
document.addEventListener("DOMContentLoaded", fkbRender);