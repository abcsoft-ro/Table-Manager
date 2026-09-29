/**
 * TableManager 2.1C - Client Logic conectat direct la MSSQL Rual
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
  // Cantitatea maxima admisa pe o linie (tblSet.CantMax, implicit 1000).
  cantMax: 1000,
  // Numarul de zecimale pentru cantitate (tblSet.NrZecCant: 0, 1 sau 2).
  nrZecCant: 1,
  // Discountul permis/interzis (tblSet.RED: 1 = DA, 0 = NU).
  red: 1,
  // Daca discountul cere parola (tblParola.ParolaDiscount setata) si parola
  // verificata in sesiunea curenta de discount.
  parolaDiscount: 0,
  discountParola: "",
  // Daca stornarea liniilor trimise cere parola/motiv (tblParola.ParolaStornare
  // setata). Cand e 0, popup-ul de stornare nu le mai afiseaza.
  parolaStornare: 0,
  reducereProcent: 0,
  selectedItemIndex: 0,
  // Forme de plata (tblFP) si platile inregistrate pe ecranul de inchidere:
  // paymentForms = [{FPID, Denumire, Status}], payments = { FPID: valoare }
  paymentForms: [],
  payments: {},
  activePaymentFp: null,
  
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

// Initializare la incarcarea paginii
document.addEventListener("DOMContentLoaded", () => {
  initApp();
  updateLiveClock();
  setInterval(updateLiveClock, 1000);
  // Campul de scanare pastreaza focusul in ecranul de marcare (fara popup activ)
  setInterval(focusScanInput, 700);
});

async function initApp() {
  try {
    showToast("Conectare la MSSQL Rual...");
    // 1. Incarcam meniul si produsele din MSSQL
    await loadMenu();
    // 1b. Restauram sesiunea de ospatar (doar daca Mod_logare = 1)
    restoreWaiterSession();
    // 2. Incarcam comanda pentru Masa 1
    await loadOrder(POS_STATE.masaCurenta);
    selectLastAndScroll();
    // 3. Incarcam starea celor 80 de mese
    await loadTables();
    showToast("Conectat la baza de date Rual!");

    // Mod FastFood: pornim direct in ecranul de marcare (fara selectie de mese).
    if (isFastFood()) {
      navigateToScreen("screen-marcare");
    }
  } catch (err) {
    console.error("Eroare la initializare:", err);
    showToast("Eroare comunicare server: " + err.message);
  }
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
  el.innerHTML = '<option value="">— selectati motivul —</option>';
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
  POS_STATE.cantMax = (Number(data.cantMax) > 0) ? Number(data.cantMax) : 1000;
  const nz = parseInt(data.nrZecCant, 10);
  POS_STATE.nrZecCant = (nz >= 0 && nz <= 2) ? nz : 1;
  POS_STATE.red = (data.red === 0 || data.red === "0") ? 0 : 1;
  POS_STATE.parolaDiscount = (data.parolaDiscount == 1) ? 1 : 0;
  POS_STATE.parolaStornare = (data.parolaStornare == 1) ? 1 : 0;

  renderMenuGrid();
  updateMeniulZileiButton();
  applyTipVanzUI();
  applyRedUI();
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

// Footer-ul ecranului mese: datele de contact din tblSet (DistrRand1/2)
function updateTablesFooter(line1, line2) {
  const el1 = document.getElementById("footer-distr1");
  const el2 = document.getElementById("footer-distr2");
  if (el1) el1.textContent = line1 || "";
  if (el2) el2.textContent = line2 || "";
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
      if (grp.FontSize) {
        btn.style.fontSize = grp.FontSize + "px";
      }
      if (grp.FontType) {
        btn.style.fontFamily = grp.FontType;
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
    showToast(`Grupa "${groupName}" nu contine produse active`);
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
      backBtn.innerText = "⬅ INAPOI LA GRUPE";
      backBtn.onclick = () => returnToGroups();
    }
  }

  showMenuHeader(groupName);
  renderProductGrid(produse);
  showToast(`Grupa: ${groupName}`);
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
      backBtn.innerText = "⬅ INAPOI LA GRUPE";
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
    // Harta Pozitie -> Produs (Pozitii 1..50)
    const prodPosMap = {};
    const produseFaraPoz = [];

    produse.forEach(p => {
      if (p.Poz >= 1 && p.Poz <= 50 && !prodPosMap[p.Poz]) {
        prodPosMap[p.Poz] = p;
      } else {
        produseFaraPoz.push(p);
      }
    });

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
      if (p.FontSize) {
        btn.style.fontSize = p.FontSize + "px";
      }
      if (p.FontType) {
        btn.style.fontFamily = p.FontType;
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
    if (docId) {
      const res = await printKitchen(docId);
      if (res && res.jobs > 0) {
        showToast(res.message || "Comanda a fost trimisa la sectie");
      }
    }
  } finally {
    navigateToScreen("screen-mese");
    leavingTableInProgress = false;
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
    // CUI-ul apartine notei curente: se goleste cand trecem pe alta nota.
    if (POS_STATE.cuiDocId !== POS_STATE.docId) {
      POS_STATE.cuiDocId = POS_STATE.docId;
      POS_STATE.cui = "";
      updateCuiButton();
    }
    POS_STATE.articole = data.articole || [];
    // Proprietarul bonului (ospatarul care l-a deschis) - nu suprascriem
    // casierul curent (operatorul logat).
    POS_STATE.bonNrOp = (data.nrOp != null) ? Number(data.nrOp) : null;
    POS_STATE.bonCasier = data.casier || null;

    // Sincronizam interfata
    document.getElementById("current-table-btn").innerText = nrMasa;
    const cashierEl = document.getElementById("current-cashier-label");
    if (cashierEl) cashierEl.innerText = POS_STATE.loggedIn ? (POS_STATE.casierCurent || "") : "Neautentificat";
    
    const dtEl = document.getElementById("order-datetime");
    if (data.dataOra && dtEl) {
      dtEl.innerHTML = data.dataOra;
    }

    renderOrderItems(data.subtotal || 0, data.total || 0);
    // Un bon nou incepe: panoul revine la forma initiala (cu linia Total).
    hideFastFoodReceipt();
  } catch (err) {
    console.error("Eroare la incarcare comanda:", err);
  }
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
        <button class="mod-del-btn" title="Sterge modul" onclick="deleteMod(${m.ecrId})">✕</button>
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
    if (res.status !== "success") throw new Error(res.message);

    showToast(`Adaugat in BD: ${res.denumire || "Produs"}`);
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
    appAlert("Eroare la salvare in baza de date: " + err.message);
  }
}

async function toggleCookDb(ecrId, isChecked) {
  if (!ecrId) return;
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
      occ.innerHTML = `<div>${t.nrMasa}</div><div style="font-size: 11px;">${escapeHtml(t.casier || "OCUPAT")}<br>${t.total.toFixed(0)} L</div>`;
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
    appAlert("Masa " + numarMasa + " este deschisa de alt ospatar (" + (t.casier || "?") + ").");
    return;
  }

  // Incarcam comanda ca sa aflam cine a deschis bonul.
  await loadOrder(numarMasa);

  // Un ospatar nu poate intra pe masa deschisa de alt ospatar.
  if (POS_STATE.docId && POS_STATE.bonNrOp != null && Number(POS_STATE.bonNrOp) !== Number(POS_STATE.nrOp)) {
    appAlert("Masa " + numarMasa + " este deschisa de alt ospatar (" + (POS_STATE.bonCasier || "?") + ").");
    return;
  }

  returnToGroups();
  showToast(`Comutare pe Masa ${numarMasa}`);
  navigateToScreen("screen-marcare");
  selectLastAndScroll();
}

// --------------------------------------------------------------------------
// 4. INCHIDERE NOTA SI PLATA (Inchidere_nota.PNG)
// --------------------------------------------------------------------------
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
  POS_STATE.activePaymentFp = null;
  const input = document.getElementById("pay-amount-input");
  input.value = total;
  input.dataset.fresh = "true";

  loadPaymentForms().then(() => {
    renderPaymentMethods();
    // Forma de plata cu Poz = 1 este cea implicita (altfel prima din lista)
    const def = POS_STATE.paymentForms.find(f => f.Poz === 1) || POS_STATE.paymentForms[0];
    if (def) {
      selectPaymentMethod(def.FPID);
    }
    updatePaymentProgress();
  });
}

// Incarca formele de plata active (Status = 1) din tblFP, ordonate dupa Poz
async function loadPaymentForms() {
  try {
    const resp = await fetch("api/fp.php");
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message || "Eroare");
    POS_STATE.paymentForms = (data.rows || [])
      .filter(r => r.Status === 1)
      .sort((a, b) => (a.Poz ?? 9999) - (b.Poz ?? 9999) || a.FPID - b.FPID);
  } catch (err) {
    POS_STATE.paymentForms = [];
    showToast("Eroare forme de plata: " + err.message);
  }
}

// Randeaza butoanele formelor de plata
function renderPaymentMethods() {
  const grid = document.getElementById("pay-methods-grid");
  if (!grid) return;
  grid.innerHTML = "";

  POS_STATE.paymentForms.forEach(f => {
    const btn = document.createElement("button");
    btn.className = "pay-btn";
    btn.id = "btn-pay-fp-" + f.FPID;
    btn.textContent = f.Denumire;
    btn.onclick = () => selectPaymentMethod(f.FPID);
    grid.appendChild(btn);
  });
}

function selectPaymentMethod(fpid) {
  POS_STATE.activePaymentFp = fpid;
  document.querySelectorAll(".pay-btn").forEach(btn => btn.classList.remove("selected"));
  document.getElementById("btn-pay-fp-" + fpid)?.classList.add("selected");

  const f = POS_STATE.paymentForms.find(x => x.FPID === fpid);
  document.getElementById("lbl-active-pay-type").innerText = f ? f.Denumire.toUpperCase() : "";
}

// Afiseaza platile inregistrate si restul de plata
function updatePaymentProgress() {
  const el = document.getElementById("pay-progress");
  if (!el) return;

  const total = parseFloat(document.getElementById("val-total").innerText) || 0;
  const totalPaid = Object.values(POS_STATE.payments).reduce((a, b) => a + b, 0);
  const rest = total - totalPaid;

  const parts = Object.entries(POS_STATE.payments).map(([fp, val]) => {
    const f = POS_STATE.paymentForms.find(x => x.FPID === parseInt(fp, 10));
    return `${f ? f.Denumire : ("FP " + fp)}: ${val.toFixed(2)}`;
  });

  el.innerText = parts.length
    ? parts.join("   |   ") + "   |   Rest: " + rest.toFixed(2)
    : "Total de plata: " + total.toFixed(2);
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
    const f = POS_STATE.paymentForms.find(x => x.FPID === parseInt(id, 10));
    return { label: f ? f.Denumire : ("FP " + id), val: Number(val) || 0 };
  });

  let html = '<div class="ff-receipt-title">PLATA EFECTUATA</div>';
  parts.forEach(p => {
    html += `<div class="ff-receipt-line"><span>${escapeHtml(p.label)}</span><span>${p.val.toFixed(2)}</span></div>`;
  });
  html += `<div class="ff-receipt-line ff-receipt-total"><span>Total</span><span>${(Number(total) || 0).toFixed(2)}</span></div>`;
  if (rest > 0) {
    html += `<div class="ff-receipt-line ff-receipt-rest"><span>Rest</span><span>${Number(rest).toFixed(2)}</span></div>`;
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

async function finalizePaymentTransaction() {
  const total = parseFloat(document.getElementById("val-total").innerText) || 0;
  if (total <= 0) {
    appAlert("Nu exista articole pe aceasta nota de plata!");
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

  // Inregistram suma pe forma de plata selectata (plata mixta)
  const fp = POS_STATE.activePaymentFp;
  POS_STATE.payments[fp] = (POS_STATE.payments[fp] || 0) + amount;

  const totalPaid = Object.values(POS_STATE.payments).reduce((a, b) => a + b, 0);

  // Nu s-a acoperit totalul: asteptam inca o forma de plata
  if (totalPaid < total) {
    input.value = (total - totalPaid).toFixed(2);
    input.dataset.fresh = "true";
    updatePaymentProgress();
    showToast("Plata partiala inregistrata");
    return;
  }

  const rest = totalPaid - total;

  try {
    const resp = await fetch("api/order_action.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        action: "close_bill",
        docId: POS_STATE.docId,
        plati: POS_STATE.payments,
        cui: POS_STATE.cui || ""
      })
    });

    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message);

    // Bonul s-a inchis: CUI-ul nu mai apartine unei note deschise.
    POS_STATE.cui = "";
    POS_STATE.cuiDocId = null;
    updateCuiButton();

    // Mod FastFood: fara popup; detaliile de plata raman in panoul alb din
    // stanga (in locul liniei Total) pana la inceperea unui nou bon.
    if (isFastFood()) {
      showToast("BON INCHIS CU SUCCES!");
      returnToGroups();
      await loadOrder(POS_STATE.masaCurenta);
      showFastFoodReceipt(POS_STATE.payments, total, rest);
      navigateToScreen("screen-marcare");
      return;
    }

    const detalii = Object.entries(POS_STATE.payments).map(([id, val]) => {
      const f = POS_STATE.paymentForms.find(x => x.FPID === parseInt(id, 10));
      return `${f ? f.Denumire : ("FP " + id)}: ${val.toFixed(2)} Lei`;
    }).join("\n");

    let msg = `BON FISCAL EMIS IN MSSQL!\n${detalii}\nTotal: ${total.toFixed(2)} Lei`;
    if (rest > 0) {
      msg += `\nRest de dat: ${rest.toFixed(2)} Lei`;
    }
    if (res.print) {
      if (res.print.nota) {
        msg += `\nTiparire: nota #${res.print.nota}, fiscal #${res.print.fiscal}`;
      } else {
        msg += `\nTiparire: fiscal #${res.print.fiscal}`;
      }
    }

    appAlert(msg);
    showToast("BON INCHIS CU SUCCES!");

    returnToGroups();
    await loadOrder(POS_STATE.masaCurenta);
    await loadTables();
    navigateToScreen("screen-mese");
  } catch (err) {
    appAlert("Eroare la inchiderea bonului: " + err.message);
  }
}

// --------------------------------------------------------------------------
// 5. AUTENTIFICARE CU PAROLA / CAUTARE PRODUS (tastatura virtuala comuna)
// --------------------------------------------------------------------------
let keyboardAction = "parola";
let pendingLoginCallback = null;
const WAITER_STORAGE_KEY = "rual_waiter";

function updateCashierLabel() {
  const el = document.getElementById("current-cashier-label");
  if (el) el.innerText = POS_STATE.loggedIn ? (POS_STATE.casierCurent || "") : "Neautentificat";
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

function logoutWaiter() {
  POS_STATE.loggedIn = false;
  POS_STATE.nrOp = null;
  POS_STATE.casierCurent = "";
  POS_STATE.waiterRol = null;
  POS_STATE.bonNrOp = null;
  clearWaiterSession();
  updateCashierLabel();
}

// Deschide modalul de autentificare in modul "ospatar" si executa callback-ul
// dupa autentificare reusita (ex. continuarea deschiderii mesei).
function openLoginModal(callback) {
  keyboardAction = "login";
  pendingLoginCallback = callback || null;
  const title = document.getElementById("kb-title");
  const subtitle = document.getElementById("kb-subtitle");
  if (title) title.innerText = "AUTENTIFICARE OSPATAR";
  if (subtitle) subtitle.innerText = "Introduceti parola";
  const modal = document.getElementById("modal-keyboard");
  const input = document.getElementById("virtual-keyboard-input");
  if (input) { input.type = "password"; input.value = ""; }
  if (modal) modal.classList.add("active");
  vkShift = false;
  vkCaps = false;
  vkRender();
}

function openKeyboardModal(mode) {
  keyboardAction = mode === "Cautare" ? "search" : "parola";
  const title = document.getElementById("kb-title");
  const subtitle = document.getElementById("kb-subtitle");
  if (title) title.innerText = keyboardAction === "search" ? "CAUTARE PRODUS" : "AUTENTIFICARE";
  if (subtitle) subtitle.innerText = keyboardAction === "search" ? "Introduceti textul cautarii" : "Introduceti parola";

  const modal = document.getElementById("modal-keyboard");
  const input = document.getElementById("virtual-keyboard-input");
  input.type = keyboardAction === "search" ? "text" : "password";
  input.value = "";
  modal.classList.add("active");
  vkShift = false;
  vkCaps = false;
  vkRender();
}

function closeKeyboardModal() {
  document.getElementById("modal-keyboard").classList.remove("active");
}

function vkeyInput(char) {
  const input = document.getElementById("virtual-keyboard-input");
  input.value += char;
}

function vkeyBackspace() {
  const input = document.getElementById("virtual-keyboard-input");
  input.value = input.value.slice(0, -1);
}

function vkeyClear() {
  document.getElementById("virtual-keyboard-input").value = "";
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

async function vkeyValidate() {
  const input = document.getElementById("virtual-keyboard-input");
  const valoare = input.value;

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
        parola: valoare
      })
    });

    const res = await resp.json();

    // Mod "login" (intrarea pe masa): acceptam doar parola de ospatar.
    if (keyboardAction === "login") {
      if (res.status !== "success" || res.programare || res.rapoarte) {
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

    setAuthenticatedWaiter(res.nrOsp, res.nume, res.rol);
    closeKeyboardModal();
    showToast(`Autentificat: ${res.nume} (${res.rol})`);
  } catch (err) {
    appAlert("Eroare la autentificare: " + err.message);
  }
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
    title.innerText = "REZULTATE: " + query;
    if (backBtn) {
      backBtn.innerText = "⬅ INAPOI LA GRUPE";
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
  showToast("Acces programare");
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
      "Sincronizarea inlocuieste produsele, grupele, ospatarii si formele de plata din sursa externa. Continuati?",
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
// SETARI (tblSet): taburi General / Device-uri / Imprimante / Altele
// Doar coloana Value este editabila; Setting si Descriere sunt doar afisate.
// --------------------------------------------------------------------------
const SETARI_TABS = [
  { id: "general",    label: "General",    groups: ["General"] },
  { id: "device",     label: "Device-uri", groups: ["Casa", "Afiseaj", "Cantar", "PoSbanca"] },
  { id: "altele",     label: "Altele",     groups: null } // null = toate cele neincluse mai sus
];

let setariRows = [];
let setariActiveTab = "general";
let setariDirty = {};
// In modul Restaurant, NrZecCant e editabil doar cand toate mesele sunt inchise.
let setariCanEditNrZecCant = true;

// Confirmare in-app (nu depinde de dialogul nativ confirm(), care poate fi
// suprimat de browser). Fallback pe confirm() daca modalul nu exista.
let _appConfirmCb = null;

function appConfirm(message, onYes, yesLabel, noLabel) {
  const modal = document.getElementById("modal-confirm");
  if (!modal) {
    if (window.confirm(message) && typeof onYes === "function") onYes();
    return;
  }
  document.getElementById("confirm-message").textContent = message;
  const yes = document.getElementById("confirm-yes");
  const no = document.getElementById("confirm-no");
  yes.textContent = yesLabel || "Da";
  no.textContent = noLabel || "Nu";
  _appConfirmCb = (typeof onYes === "function") ? onYes : null;

  yes.onclick = () => {
    const cb = _appConfirmCb;
    closeAppConfirm();
    if (cb) cb();
  };
  no.onclick = () => closeAppConfirm();

  modal.classList.add("active");
}

function closeAppConfirm() {
  const modal = document.getElementById("modal-confirm");
  if (modal) modal.classList.remove("active");
  _appConfirmCb = null;
}

// Mesaj in-app (nu depinde de alert() nativ, care poate fi suprimat de browser)
function appAlert(message, onOk) {
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
const SETARI_ASCUNSE = new Set(["start", "nrboncmd", "paradiscount", "storno_parola", "retea"]);

async function loadSetari() {
  try {
    const resp = await fetch("api/setari.php");
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message || "Eroare");
    setariRows = (data.rows || []).filter(r => !SETARI_ASCUNSE.has((r.Setting || "").trim().toLowerCase()));
    // Restrictia pentru NrZecCant (Restaurant + mese deschise) vine de la server.
    setariCanEditNrZecCant = (data.canEditNrZecCant !== false);
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
    b.textContent = t.label;
    b.onclick = () => {
      setariActiveTab = t.id;
      renderSetariTabs();
      renderSetariTab(t.id);
    };
    wrap.appendChild(b);
  });
}

// Ordinea explicita a setarilor in cadrul unui grup (dupa tblSet.Setting).
// Cele care nu apar in lista raman dupa ele, in ordinea primita de la server.
const SETARI_ORDINE = {
  "casa": ["TipCasaMarcat", "BonFiscalDiscStorno", "CaleFisierComenziECR", "CaleFisierRaspunsECR"],
  "afiseaj": ["AfiseajClient", "PortComAfiseaj", "BaudRateAfisaj", "CaleDriverAfiseajClient", "CaleFisierComenziAfiseajClient"],
  "cantar": ["Cantar", "CaleDriverCantar", "CaleFisierRaspunsCantar", "Delay_cantar"],
  "posbanca": ["PoSbanca", "CaleDriverPoSbanca", "CaleFisierComenziPoSbanca"]
};

// Etichete prietenoase pentru grupuri (altfel se afiseaza numele brut din tblSet.Grup).
const SETARI_GRUP_LABELS = { "casa": "Casa Marcat", "posbanca": "POS Banca" };

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
      .map(g => ({
        label: SETARI_GRUP_LABELS[setariNorm(g)] || g,
        rows: setariSortRows(g, setariRows.filter(r => setariNorm(r.Grup) === setariNorm(g)))
      }))
      .filter(grp => grp.rows.length > 0);
  }

  // Altele: tot ce nu apartine grupurilor explicite
  const used = new Set();
  SETARI_TABS.forEach(t => { if (t.groups) t.groups.forEach(g => used.add(setariNorm(g))); });
  return [{ label: "", rows: setariRows.filter(r => !used.has(setariNorm(r.Grup))) }];
}

function setariField(row) {
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
  // Transferul de produse permis/interzis: lista DA / NU.
  if (row.Setting === "TransferMasa") {
    return setariTransferMasaField(row);
  }
  // Tipul casei de marcat fiscale: lista Datecs / FiscalNet / Tremol.
  if (row.Setting === "TipCasaMarcat") {
    return setariTipCasaMarcatField(row);
  }
  // Discount/stornare pe bonul fiscal: lista DA / NU (implicit NU).
  if (row.Setting === "BonFiscalDiscStorno") {
    return setariBonFiscalDiscStornoField(row);
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
    opt.textContent = pair[1];
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
    opt.textContent = pair[1];
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
    lock.textContent = "Toate mesele trebuie sa fie inchise pentru a modifica zecimalele cantitatii.";
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
    opt.textContent = pair[1];
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
    opt.textContent = pair[1];
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

  const opts = ["Datecs", "FiscalNet", "Tremol"];
  const cur = String(row.Value === null || row.Value === undefined ? "" : row.Value).trim();
  opts.forEach(val => {
    const opt = document.createElement("option");
    opt.value = val;
    opt.textContent = val;
    if (val === (opts.includes(cur) ? cur : "Datecs")) opt.selected = true;
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

// Combobox pentru tblSet.BonFiscalDiscStorno: 1 = DA (discount/stornare pe bon),
// 0 = NU (implicit; bonul fiscal doar cu cantitati/preturi nete).
function setariBonFiscalDiscStornoField(row) {
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
    opt.textContent = pair[1];
    if (pair[0] === selected) opt.selected = true;
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

function renderSetariTab(tabId) {
  const wrap = document.getElementById("setari-content");
  if (!wrap) return;
  wrap.innerHTML = "";

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
  });
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

    // Discountul permis/interzis se aplica imediat (ascunde butoanele).
    if (keys.indexOf("RED") !== -1) {
      POS_STATE.red = (String(setariDirty["RED"]).trim() === "0") ? 0 : 1;
      applyRedUI();
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

    setariDirty = {};
    showToast(res.message);
  } catch (err) {
    appAlert("Eroare salvare setari: " + err.message);
  }
}


// --------------------------------------------------------------------------
// PROGRAMARE MESE (tblMese) - harta live 10x8 + inspector
// --------------------------------------------------------------------------
let meseWorking = {};   // NrMasa -> {MasaID?, NrMasa, back, fg, bold, afisez, nrpos, obs}
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

function colorIntToHex(v) {
  if (v === null || v === undefined) return null;
  const r = (v & 255).toString(16).padStart(2, "0");
  const g = ((v >> 8) & 255).toString(16).padStart(2, "0");
  const b = ((v >> 16) & 255).toString(16).padStart(2, "0");
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
          nrpos: m.NrPOS,
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
    if (title) title.innerText = "MASA";
    if (dirtyBadge) dirtyBadge.style.display = "none";
    if (nrDisplay) nrDisplay.innerText = "-";
    if (emptyMsg) {
      emptyMsg.style.display = "block";
      emptyMsg.innerHTML = 'Atinge o pozitie pe harta pentru a o edita';
    }
    if (form) form.style.display = "none";
    return;
  }

  // O pozitie libera selectata => cream automat un draft (implicit) de masa pentru editare
  if (!o) {
    o = { MasaID: null, NrMasa: meseSelected, back: null, fg: null, bold: false, afisez: true, nrpos: null, obs: "" };
    meseWorking[meseSelected] = o;
    renderMeseMap(); // celula isi schimba aspectul din "+" liber in masa editabila
  }

  const saveBtn = document.getElementById("mese-save-btn");
  saveBtn.onclick = saveMeseSelected;

  document.getElementById("mese-insp-title").innerText = "MASA " + meseSelected;
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

  saveBtn.innerText = "Salveaza";
  saveBtn.onclick = saveMeseSelected;
  saveBtn.disabled = false;
}

function renderMeseToggle(id, on, yesTxt, noTxt) {
  const btn = document.getElementById(id);
  if (!btn) return;
  btn.innerText = on ? yesTxt : noTxt;
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
    NrPOS: o.nrpos === null ? "" : o.nrpos,
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
    if (title) title.innerText = "GRUPA";
    if (dirtyBadge) dirtyBadge.style.display = "none";
    if (emptyMsg) {
      emptyMsg.style.display = "block";
      emptyMsg.innerHTML = 'Atinge o grupa sau o pozitie libera de pe grila pentru a o edita';
    }
    if (form) form.style.display = "none";
    return;
  }

  const saveBtn = document.getElementById("grup-save-btn");
  saveBtn.onclick = saveGrupeSelected;

  document.getElementById("grup-insp-title").innerText = row.isNew ? "GRUPA NOUA" : ("GRUPA " + row.NrGrp);
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
    MZ: false
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

async function loadProdGroup() {
  try {
    const resp = await fetch("api/produse.php?nrgrp=" + prodCurGrup);
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message);

    prodSectii = data.sectii || [];
    prodTvaList = data.tva || [];
    prodKpList = data.kpList || [];

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
        if (v === "") return "(fara sectie de tiparire)";
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
        MZ: p.MZ
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
    if (title) title.innerText = "PRODUS";
    if (dirtyBadge) dirtyBadge.style.display = "none";
    if (emptyMsg) emptyMsg.style.display = "block";
    if (form) form.style.display = "none";
    return;
  }

  const saveBtn = document.getElementById("prod-save-btn");
  const delBtn = document.getElementById("prod-del-btn");
  saveBtn.onclick = saveProdusSelected;

  document.getElementById("prod-insp-title").innerText = row.isNew ? "PRODUS NOU" : ("PRODUS " + row.ProdID);
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
    MZ: row.MZ ? 1 : 0
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
  btn.innerText = antetPlatitorTVA ? "Da" : "Nu";
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
    escapeHtml(f.label) + '</option>'
  ).join("");
}

// Optiunile combobox-ului de magnitudini. Valorile vechi (puncte Windows) sunt
// ignorate si inlocuite cu 1x1 (normal).
function notaSizeOptions(selected) {
  const val = NOTA_SIZES.some(s => s.value === selected) ? selected : 0;
  return NOTA_SIZES.map(s =>
    '<option value="' + s.value + '"' + (s.value === val ? " selected" : "") + '>' +
    escapeHtml(s.label) + '</option>'
  ).join("");
}

function renderNota() {
  const wrap = document.getElementById("nota-content");
  if (!wrap) return;
  wrap.innerHTML = "";

  notaRows.forEach(r => {
    const label = NOTA_LABELS[r.Seria] || r.Seria;
    const rec = document.createElement("div");
    rec.className = "nota-record";
    rec.innerHTML =
      '<div class="nota-record-title">' + escapeHtml(label) + ' (' + escapeHtml(r.Seria) + ')</div>' +
      '<div class="nota-fields">' +
        '<div class="tva-field"><label>Nume</label><div class="tva-field-row">' +
          '<input type="text" id="nota-nume-' + r.Seria + '" class="tva-input" maxlength="40">' +
          '<button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor(\'nota-nume-' + r.Seria + '\')">⌨️</button>' +
        '</div></div>' +
        '<div class="tva-field"><label>NumeFont (ESC/POS)</label>' +
          '<select id="nota-font-' + r.Seria + '" class="tva-input">' + notaFontOptions(r.NumeFont) + '</select>' +
        '</div>' +
        '<div class="tva-field"><label>Size (ESC/POS)</label>' +
          '<select id="nota-size-' + r.Seria + '" class="tva-input">' + notaSizeOptions(r.Size) + '</select>' +
        '</div>' +
        '<div class="tva-field"><label>Bold</label>' +
          '<button class="act-btn" id="nota-bold-' + r.Seria + '" onclick="toggleNotaBold(\'' + r.Seria + '\')" style="width:100%;">Nu</button>' +
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
  btn.innerText = on ? "Da" : "Nu";
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

  try {
    const resp = await fetch("api/conectare.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const res = await resp.json();
    if (res.status !== "success") throw new Error(res.message);
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
  { key: "ParolaExit",       id: "par-exit" }
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
    b.textContent = "Raport " + t;
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
      b.textContent = ty;
      b.onclick = () => runReport("X", ty);
      wrap.appendChild(b);
    });
    return;
  }

  // Tab Z: checkbox-uri pentru rapoarte + buton de golire
  REPORTS_Z_OPTIONS.forEach(ty => {
    const label = document.createElement("label");
    label.className = "rapoarte-check";
    label.innerHTML = '<input type="checkbox" id="z-chk-' + ty + '"> <span>' + ty + '</span>';
    wrap.appendChild(label);
  });

  const btn = document.createElement("button");
  btn.className = "prog-btn rapoarte-z-btn";
  btn.textContent = "Efectueaza Golirea Z";
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
    if (titleEl) titleEl.innerText = "NOTA " + data.nrNota + " - MASA " + data.nrMasa;
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
  document.getElementById("tva-edit-title").innerText = "COTA TVA NOUA";
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
  document.getElementById("tva-edit-title").innerText = "EDITARE COTA TVA";
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
  document.getElementById("mesaje-edit-title").innerText = "MOD PREPARARE NOU";
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
  document.getElementById("mesaje-edit-title").innerText = "EDITARE MOD PREPARARE";
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
      <span class="osp-cell-mid ${r.Blocat ? "osp-blocked" : ""}">${escapeHtml(r.Nume)}${r.Blocat ? " (Blocat)" : ""}</span>
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
  btn.innerText = ospBlocat ? "Da - Blocat" : "Nu";
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
  document.getElementById("osp-edit-title").innerText = "OSPATAR NOU";
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
  document.getElementById("osp-edit-title").innerText = "EDITARE OSPATAR";
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
      <span class="sect-cell">${escapeHtml(r.Denumire)}${protected ? ' <span class="sect-prot">(protejata)</span>' : ""}</span>
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
  document.getElementById("sect-edit-title").innerText = "SECTIE NOUA";
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
  document.getElementById("sect-edit-title").innerText = "EDITARE SECTIE";
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
let imprServiceUp = false;

const IMPR_TARGET_TYPES = [
  ["network", "Retea (IP:port)"],
  ["windows", "Windows (nume imprimanta)"],
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

    // Lista imprimantelor Windows (optional, pentru combo).
    imprWindowsPrinters = [];
    fetch("api/print_config.php?action=printers")
      .then(r => r.json())
      .then(d => { if (d && d.status === "success") { imprWindowsPrinters = d.printers || []; renderImprimante(); } })
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
    badge.textContent = imprServiceUp ? "Serviciu: ONLINE" : "Serviciu: OFFLINE";
    badge.className = "impr-badge " + (imprServiceUp ? "on" : "off");
  }
  renderImprList();
  renderImprInspector();
  renderImprGlobal();
}

function imprTargetLabel(t) {
  if (!t) return "-";
  if (t.type === "network") return "Retea " + (t.host || "?") + ":" + (t.port || "9100");
  if (t.type === "windows") return "Windows " + (t.name || "?");
  if (t.type === "file") return "Fisier";
  return "Preview";
}

function renderImprList() {
  const list = document.getElementById("impr-list");
  if (!list) return;
  if (!imprSections.length) {
    list.innerHTML = '<div class="impr-empty">Nu exista sectii. Apasa Adauga.</div>';
  } else {
    list.innerHTML = imprSections.map(s => {
      const sel = (s.NrLogic === imprSelected) ? " selected" : "";
      const off = s.Stare ? "" : " inactive";
      return `<div class="impr-list-item${sel}${off}" onclick="imprSelect(${s.NrLogic})">
        <div><span class="impr-li-nr">${s.NrLogic}</span> ${escapeHtml(s.Nume || "(fara nume)")}${s.isNew ? ' <span class="impr-dirty">*</span>' : ''}</div>
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
    `<option value="${v}"${v === selected ? " selected" : ""}>${l}</option>`).join("");
}

function imprWinPrinterField(prefix, current) {
  if (imprWindowsPrinters && imprWindowsPrinters.length) {
    const has = imprWindowsPrinters.indexOf(current) !== -1;
    let opts = "";
    if (current && !has) {
      opts += `<option value="${escapeHtml(current)}" selected>${escapeHtml(current)} (actual)</option>`;
    }
    opts += imprWindowsPrinters.map(n =>
      `<option value="${escapeHtml(n)}"${n === current ? " selected" : ""}>${escapeHtml(n)}</option>`).join("");
    return `<div class="tva-field"><label>Imprimanta Windows</label>
      <select class="prod-combo" onchange="imprTargetFieldChanged('${prefix}','name',this.value)">${opts}</select></div>`;
  }
  return `<div class="tva-field"><label>Imprimanta Windows</label>
    <div class="tva-field-row">
      <input type="text" class="tva-input" value="${escapeHtml(current || "")}" oninput="imprTargetFieldChanged('${prefix}','name',this.value)">
    </div>
    <div class="impr-hint">Lista imprimantelor Windows nu este disponibila (pywin32 lipseste). Scrie numele exact.</div></div>`;
}

function imprTargetFieldsHtml(prefix, t) {
  let html = `<div class="tva-field"><label>Tip destinatie</label>
    <select class="prod-combo" onchange="imprTargetTypeChanged('${prefix}', this.value)">${imprTypeOptions(t.type)}</select></div>`;
  if (t.type === "network") {
    html += `<div class="tva-field"><label>Host / IP</label>
      <div class="tva-field-row"><input type="text" class="tva-input" value="${escapeHtml(t.host || "")}" oninput="imprTargetFieldChanged('${prefix}','host',this.value)"></div></div>
      <div class="tva-field"><label>Port</label>
      <div class="tva-field-row"><input type="text" class="tva-input" value="${escapeHtml(t.port || "9100")}" oninput="imprTargetFieldChanged('${prefix}','port',this.value)"></div></div>`;
  } else if (t.type === "windows") {
    html += imprWinPrinterField(prefix, t.name);
  } else if (t.type === "file") {
    html += `<div class="impr-hint">Bonurile se scriu in spool-ul serviciului de tiparire (fara imprimanta).</div>`;
  } else {
    html += `<div class="impr-hint">Bonul merge in emulatorul de previzualizare.</div>`;
  }
  return html;
}

function renderImprInspector() {
  const wrap = document.getElementById("impr-insp-body");
  if (!wrap) return;
  const s = imprSel();
  if (!s) {
    wrap.innerHTML = '<div class="impr-empty">Selecteaza o sectie sau adauga una noua.</div>';
    return;
  }
  const title = s.isNew ? "SECTIE NOUA" : ("SECTIE " + s.NrLogic);
  wrap.innerHTML = `
    <div class="impr-insp-head"><span>${title}</span>${s.isNew ? '<span class="impr-dirty">* nesalvata</span>' : ''}</div>
    <div class="tva-field"><label>NrLogic</label>
      <div class="tva-field-row"><input type="text" class="tva-input" value="${s.NrLogic}" readonly></div></div>
    <div class="tva-field"><label>Nume sectie</label>
      <div class="tva-field-row">
        <input type="text" id="impr-nume-input" class="tva-input" maxlength="255" value="${escapeHtml(s.Nume || "")}" oninput="imprSetNume(this.value)">
        <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('impr-nume-input')">&#9000;</button>
      </div></div>
    <div class="tva-field"><label>Stare (activa)</label>
      <button class="act-btn" onclick="imprToggleStare()" style="width:100%;${s.Stare ? "background:#008000;color:#fff;" : "background:#ffff00;"}">${s.Stare ? "Da" : "Nu"}</button></div>
    <div class="impr-sep"></div>
    <div class="impr-panel-title">Destinatie fizica</div>
    ${imprTargetFieldsHtml("sect", s.target)}
    <div class="tva-actions"><button class="act-btn" onclick="imprTestSection()">Test tiparire</button></div>
  `;
}

function renderImprGlobal() {
  const wrap = document.getElementById("impr-global-body");
  if (!wrap) return;
  let html = "";
  html += `<div class="impr-global-block"><div class="impr-panel-title">Nota de plata</div>${imprTargetFieldsHtml("nota", imprGlobal.nota)}
    <div class="tva-actions"><button class="act-btn" onclick="imprTestGlobal('nota')">Test</button></div></div>`;
  html += `<div class="impr-global-block"><div class="impr-panel-title">Rapoarte</div>${imprTargetFieldsHtml("raport", imprGlobal.raport)}
    <div class="tva-actions"><button class="act-btn" onclick="imprTestGlobal('raport')">Test</button></div></div>`;
  html += `<div class="impr-global-block"><div class="impr-panel-title">Fiscal</div>${imprTargetFieldsHtml("fiscal", imprGlobal.fiscal)}</div>`;

  const defOpts = imprSections.map(s =>
    `<option value="${s.NrLogic}"${String(s.NrLogic) === imprGlobal.defaultPrinter ? " selected" : ""}>${escapeHtml(s.Nume || ("Sectia " + s.NrLogic))}</option>`).join("");
  html += `<div class="impr-global-block"><div class="impr-panel-title">Imprimanta implicita</div>
    <div class="tva-field"><select class="prod-combo" onchange="imprSetDefault(this.value)">
      <option value=""${imprGlobal.defaultPrinter === "" ? " selected" : ""}>(implicit / preview)</option>
      ${defOpts}
    </select></div>
    <div class="impr-hint">Folosita pentru bonurile de sectie fara destinatie proprie.</div></div>`;
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
    appAlert("Sectia are " + s.usedCount + " produse atasate. Mutati-le pe alta sectie inainte de stergere.");
    return;
  }
  appConfirm("Stergeti sectia " + s.NrLogic + " (" + (s.Nume || "fara nume") + ")?", () => {
    if (!s.isNew) imprDeleted.push(s.NrLogic);
    imprSections = imprSections.filter(x => x.NrLogic !== s.NrLogic);
    imprSelected = imprSections.length ? imprSections[0].NrLogic : null;
    renderImprimante();
  }, "Sterge", "Renunta");
}

function imprValidateTarget(t) {
  if (t.type === "network") {
    if (!String(t.host || "").trim()) return "lipseste host/IP";
    const p = parseInt(t.port, 10);
    if (isNaN(p) || p < 1 || p > 65535) return "port invalid (1-65535)";
  }
  if (t.type === "windows") {
    if (!String(t.name || "").trim()) return "lipseste numele imprimantei Windows";
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
      appAlert("Sectia " + s.NrLogic + " nu are nume.");
      imprSelected = s.NrLogic; renderImprimante(); return;
    }
    const e = imprValidateTarget(s.target);
    if (e) { appAlert("Sectia " + (s.Nume || s.NrLogic) + ": " + e); imprSelected = s.NrLogic; renderImprimante(); return; }
  }
  for (const key of ["nota", "raport", "fiscal"]) {
    const e = imprValidateTarget(imprGlobal[key]);
    if (e) { appAlert("Tinta " + key + ": " + e); return; }
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
        Stare: s.Stare ? 1 : 0,
        DenumirePrinter: s.target.type === "windows" ? s.target.name : (s.target.type === "network" ? s.target.host : "")
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
  btn.innerText = fpStatus ? "Da" : "Nu";
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
  document.getElementById("fp-edit-title").innerText = "FORMA DE PLATA NOUA";
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
  document.getElementById("fp-edit-title").innerText = "EDITARE FORMA DE PLATA";
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

  const tr = targetEl.getBoundingClientRect();
  const fx = (tr.left - sr.left) / scale;
  const fy = (tr.top - sr.top) / scale;
  const fw = tr.width / scale;
  const fh = tr.height / scale;

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

  const tr = targetEl.getBoundingClientRect();
  const fTop = (tr.top - sr.top) / scale;
  const fBottom = (tr.bottom - sr.top) / scale;

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

function openNumericKeyboardFor(inputId, title) {
  const el = document.getElementById(inputId);
  if (!el) return;
  numpadTargetId = inputId;
  numpadPromptCallback = null;
  numpadDigitsOnly = false;
  const disp = document.getElementById("num-display");
  if (disp) { disp.type = "text"; disp.value = el.value || ""; }
  const dot = document.getElementById("num-dot-key");
  if (dot) { dot.style.visibility = "visible"; dot.disabled = false; }
  const t = document.getElementById("num-title");
  if (t) t.innerText = title || "TASTATURA NUMERICA";
  document.getElementById("modal-num").classList.add("active");
}

// Mod prompt: colecteaza o valoare numerica si o trimite callback-ului la OK.
function openNumericPrompt(title, onSubmit) {
  numpadTargetId = null;
  numpadPromptCallback = onSubmit || null;
  numpadDigitsOnly = true;
  const disp = document.getElementById("num-display");
  if (disp) { disp.type = "password"; disp.value = ""; }
  const dot = document.getElementById("num-dot-key");
  if (dot) { dot.style.visibility = "hidden"; dot.disabled = true; }
  const t = document.getElementById("num-title");
  if (t) t.innerText = title || "INTRODUCETI";
  document.getElementById("modal-num").classList.add("active");
}

function closeNumericKeyboard() {
  document.getElementById("modal-num").classList.remove("active");
  numpadTargetId = null;
  numpadPromptCallback = null;
  numpadDigitsOnly = false;
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
  if (POS_STATE.cui !== "") POS_STATE.cuiDocId = POS_STATE.docId;
  cuiSetError("");
  closeCuiModal();
  updateCuiButton();
  showToast(POS_STATE.cui ? ("CUI: " + POS_STATE.cui) : "Cod fiscal sters");
}

// Sterge codul fiscal atasat notei curente.
function cuiRemove() {
  POS_STATE.cui = "";
  POS_STATE.cuiDocId = null;
  cuiSetError("");
  closeCuiModal();
  updateCuiButton();
  showToast("Cod fiscal sters");
}

function updateCuiButton() {
  const b = document.getElementById("btn-cui");
  if (!b) return;
  b.title = POS_STATE.cui ? ("CUI: " + POS_STATE.cui) : "Fara cod fiscal";
  b.classList.toggle("cui-set", !!POS_STATE.cui);
}

function numpadKey(ch) {
  const disp = document.getElementById("num-display");
  let v = disp.value;
  if (ch === ".") {
    if (numpadDigitsOnly) return; // parola = doar cifre
    if (!v.includes(".")) disp.value = v === "" ? "0." : v + ".";
    return;
  }
  if (v === "0") { disp.value = ch; return; }
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

  if (total <= 0) {
    try {
      const resp = await fetch("api/order_action.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "close_bill",
          docId: POS_STATE.docId,
          plati: {},
          cui: POS_STATE.cui || ""
        })
      });
      const res = await resp.json();
      if (res.status !== "success") throw new Error(res.message);

      POS_STATE.cui = "";
      POS_STATE.cuiDocId = null;
      updateCuiButton();

      showToast(res.message || "Nota a fost inchisa");
      await loadOrder(POS_STATE.masaCurenta);
      if (isFastFood()) {
        returnToGroups();
        navigateToScreen("screen-marcare");
        return;
      }
      await loadTables();
      navigateToScreen("screen-mese");
    } catch (err) {
      appAlert("Eroare la inchiderea notei: " + err.message);
    }
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
}

async function refreshPrintQueue() {
  try {
    const resp = await fetch("api/print_queue.php?action=list");
    const data = await resp.json();
    if (data.status !== "success") throw new Error(data.message || "Eroare");
    printQueueRows = data.rows || [];
    renderPrintQueue();
  } catch (err) {
    showToast("Eroare coada printare: " + err.message);
  }
}

async function refreshPrintStatus() {
  try {
    const resp = await fetch("api/print_queue.php?action=status");
    const data = await resp.json();
    if (data.status !== "success") return;
    const inLucru = (data.pending || 0) + (data.printing || 0);
    const btn = document.getElementById("btn-print-queue");
    if (!btn) return;
    if (inLucru > 0 || (data.failed || 0) > 0) {
      btn.innerHTML = `Coada<br>printare` + `<div style="font-size:11px; font-weight:bold; color:#000000;">${inLucru} in asteptare${(data.failed||0)>0 ? ', ' + data.failed + ' esuate' : ''}</div>`;
    } else {
      btn.innerHTML = "Coada<br>printare";
    }
  } catch (err) {
    /* serviciul/API indisponibil: nu deranjam ecranul */
  }
}

function printJobLabel(r) {
  const tip = (r.Tip || "").toLowerCase();
  if (tip !== "raport") return r.Tip || "";
  const t = (r.Title || "").trim();
  if (!t) return "Raport";
  if (/^raport\s+z/i.test(t)) return "Raport Z";
  const m = t.match(/^raport\s+x\s*-\s*(.+)$/i);
  if (m) {
    const key = m[1].trim().toUpperCase();
    const nice = { PLU: "PLU", GRUPE: "Grupe", SECTII: "Sectii", CASIERI: "Casieri", GENERAL: "General" };
    return "Raport X - " + (nice[key] || m[1].trim());
  }
  return "Raport" + t.replace(/^raport/i, "");
}

function renderPrintQueue() {
  const tbody = document.getElementById("printq-list");
  if (!tbody) return;
  tbody.innerHTML = "";

  if (printQueueRows.length === 0) {
    const tr = document.createElement("tr");
    tr.innerHTML = '<td colspan="7" style="color:#888; font-weight:normal;">Coada este goala</td>';
    tbody.appendChild(tr);
    updatePrintQueueBtns();
    return;
  }

  printQueueRows.forEach(r => {
    const tr = document.createElement("tr");
    const isFailed = r.Stare === "failed";
    if (r.JobID === printQueueSelected) tr.classList.add("selected");
    if (isFailed) tr.classList.add("inactive");
    const stareCls = isFailed ? "kp-off" : (r.Stare === "done" ? "kp-on" : "");
    const stareTitle = (r.Stare === "pending" && r.NextAttempt) ? ("urmatoarea incercare: " + r.NextAttempt) : "";
    const errTitle = r.LastError || stareTitle;
    tr.innerHTML = `
      <td class="printq-num"><b>${r.JobID}</b></td>
      <td>${escapeHtml(printJobLabel(r))}</td>
      <td>${r.RefDocID ? "doc " + r.RefDocID : "-"}</td>
      <td class="printq-num" title="${escapeHtml(r.PrinterName || '')}">${r.PrinterNr != null ? r.PrinterNr : "-"}</td>
      <td class="${stareCls}" title="${escapeHtml(stareTitle)}">${escapeHtml(r.Stare)}</td>
      <td title="${escapeHtml(r.CreatedAt || '')}">${escapeHtml(r.CreatedAt || "-")}</td>
      <td title="${escapeHtml(errTitle)}">${r.Attempts}${r.LastError ? " - " + escapeHtml(r.LastError) : ""}</td>
    `;
    tr.onclick = () => {
      printQueueSelected = r.JobID;
      renderPrintQueue();
      updatePrintQueueBtns();
    };
    tbody.appendChild(tr);
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
    appAlert("Jobul fiscal scrie un fisier text in folderul spool (nu are preview).");
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
    const tr = document.createElement("tr");
    tr.innerHTML = '<td colspan="6" style="color:#888; font-weight:normal;">Coada este goala</td>';
    tbody.appendChild(tr);
    updateSendQueueBtns();
    return;
  }

  sendQueueRows.forEach(r => {
    const tr = document.createElement("tr");
    const isFailed = r.Stare === "failed";
    const isDone = r.Preluat === true || r.Stare === "done";
    if (r.Id === sendQueueSelected) tr.classList.add("selected");
    if (isFailed) tr.classList.add("inactive");
    const stareCls = isFailed ? "kp-off" : (isDone ? "kp-on" : "");
    const stareTitle = (r.Stare === "sending" && r.NextAttempt) ? ("preluat la: " + r.NextAttempt) : "";
    tr.innerHTML = `
      <td class="printq-num"><b>${r.Id}</b></td>
      <td>${r.DocID != null ? "doc " + r.DocID : "-"}</td>
      <td class="${stareCls}" title="${escapeHtml(isDone ? ("trimis: " + (r.SentAt || '')) : stareTitle)}">${escapeHtml(r.Stare)}</td>
      <td class="printq-num" title="${escapeHtml(stareTitle)}">${r.Attempts}</td>
      <td title="${escapeHtml(r.CreatedAt || '')}">${escapeHtml(r.CreatedAt || "-")}</td>
      <td title="${escapeHtml(r.LastError || '')}">${escapeHtml(r.LastError || "-")}</td>
    `;
    tr.onclick = () => {
      sendQueueSelected = r.Id;
      renderSendQueue();
      updateSendQueueBtns();
    };
    tbody.appendChild(tr);
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
    document.getElementById("send-det-title").innerText = "COMANDA EXPORT #" + job.Id + docTxt;
    document.getElementById("send-det-content").textContent = data.row.str_sql || "(comanda goala)";
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
  if (!POS_STATE.articole.length) {
    showToast("Nu exista produse pe nota de transferat");
    return;
  }
  openTransferModal();
}

function openTransferModal() {
  transferSelected = new Set();
  document.getElementById("transfer-source-table").innerText = POS_STATE.masaCurenta;
  document.getElementById("transfer-dialog-title").innerText = "TRANSFER PRODUSE";
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
  document.getElementById("transfer-dialog-title").innerText = "MASA DESTINATIE";
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
    title.innerText = "MOD PREPARARE: " + produs.denumire;
    if (backBtn) {
      backBtn.innerText = "⬅ INAPOI LA PRODUSE";
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
  openQtyModal(POS_STATE.selectedItemIndex);
}

// Deschide popup-ul cu tastatura numerica pentru modificarea cantitatii
function openQtyModal(index, ev) {
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
  // Campul porneste gol: utilizatorul tasteaza direct cantitatea noua (ex: 5)
  const input = document.getElementById("qty-input-display");
  input.value = "";

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
      ? "doar numere intregi"
      : "maxim " + zec + (zec === 1 ? " zecimala" : " zecimale");
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

// Confirma noua cantitate si salveaza in baza de date
async function confirmQty() {
  const input = document.getElementById("qty-input-display");
  const cantitate = parseFloat(input.value);
  const idx = qtyEditingIndex;
  const item = POS_STATE.articole[idx];

  if (isNaN(cantitate) || cantitate <= 0 || !item) {
    showToast("Cantitatea trebuie sa fie mai mare decat 0");
    return;
  }
  if (cantitate > POS_STATE.cantMax) {
    showToast("Cantitatea maxima admisa este " + POS_STATE.cantMax);
    return;
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

    closeQtyModal();
    showToast(`Cantitate setata: ${cantitate}`);
    await loadOrder(POS_STATE.masaCurenta);
    await loadTables();

    // Pastram selectat produsul editat (numarul de produse nu s-a schimbat)
    if (idx >= 0 && idx < POS_STATE.articole.length) {
      POS_STATE.selectedItemIndex = idx;
      renderOrderItems();
    }
  } catch (err) {
    appAlert("Eroare la actualizarea cantitatii: " + err.message);
  }
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
  discScope = (it && lineValNet(it) > 0.0001) ? "line" : "bill";
  discBuffer = "";
  discMotiv = "";
  refreshMotiveSelects();
  discRender();
  document.getElementById("modal-discount").classList.add("active");
  discRefreshScopeButtons();
  // Sincronizam butoanele de tip cu modul curent (pastrat de la ultima utilizare),
  // ca butonul activ sa corespunda intotdeauna calculului.
  discRefreshModeButtons();
  discRecalcEstimate();
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
    : "Niciun produs selectat";
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

async function discApply() {
  // Daca discountul cere parola si nu a fost inca verificata, o cerem acum.
  if (POS_STATE.parolaDiscount === 1 && !POS_STATE.discountParola) {
    ensureDiscountParola(() => discApply());
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
        ensureDiscountParola(() => discApply());
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
  if (POS_STATE.red === 0) {
    showToast("Discountul nu este permis.");
    return;
  }
  if (!POS_STATE.articole.length) {
    showToast("Nota este goala; nu exista pe ce sa se aplice discount");
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
  document.getElementById("btn-void-apply").innerText = "CONFIRMA STORNO";

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

function showToast(text) {
  const toast = document.getElementById("pos-toast");
  if (!toast) return;
  toast.innerText = text;
  toast.style.display = "block";
  setTimeout(() => {
    toast.style.display = "none";
  }, 1800);
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