/**
 * TableManager Mobil - modul de marcare (tableta / telefon)
 * Reutilizeaza API-urile POS existente (menu.php, get_tables.php,
 * get_order.php, order_action.php). Nu modifica logica ecranului desktop.
 */

const MOBILE_STATE = {
  // Ospatar autentificat
  waiter: null,
  loggedIn: false,
  afterLogin: null,
  modLogare: 1,
  tipVanz: "restaurant",
  // Cantitatea maxima admisa pe o linie (tblSet.CantMax, implicit 1000).
  cantMax: 1000,
  // Numarul de zecimale pentru cantitate (tblSet.NrZecCant: 0, 1 sau 2).
  nrZecCant: 1,
  // Discountul permis/interzis (tblSet.RED: 1 = DA, 0 = NU).
  red: 1,
  // Daca discountul cere parola (tblParola.ParolaDiscount) + parola verificata.
  parolaDiscount: 0,
  discountParola: "",
  // Daca stornarea liniilor trimise cere parola/motiv (tblParola.ParolaStornare).
  parolaStornare: 0,

  // Masa / nota curenta
  masaCurenta: null,
  docId: null,
  hasOrder: false,
  bonNrOp: null,
  bonCasier: "",
  nrDoc: null,
  subtotal: 0,
  total: 0,
  reducere: 0,
  articole: [],
  selectedEcrId: null,

  // Meniu
  groups: [],
  productsByGroup: {},
  allProducts: [],
  messages: [],
  meniulZilei: 0,
  activeGroup: null,

  // Mese
  tables: [],
  tablesFilter: "all",

  currentView: "view-tables"
};

const MV_WAITER_KEY = "rual_mobile_waiter";

/* ==========================================================================
   INITIALIZARE
   ========================================================================== */
document.addEventListener("DOMContentLoaded", async () => {
  try {
    await loadMenu();
    restoreWaiterSession();
    await loadTables();
    showView("view-tables");
  } catch (err) {
    console.error("Eroare initializare:", err);
    appAlert("Eroare comunicare server: " + err.message);
  }
});

// Orice eroare de retea neprinsa ajunge aici (evita butoane "moarte").
window.addEventListener("unhandledrejection", (e) => {
  console.error("Eroare retea:", e.reason);
  showToast("Eroare comunicare server");
});

/* ==========================================================================
   HELPER-E
   ========================================================================== */
async function mvGet(url) {
  const resp = await fetch(url, { headers: { "Accept": "application/json" } });
  return resp.json();
}

async function mvPost(action, payload) {
  const body = Object.assign({ action }, payload || {});
  const resp = await fetch("api/order_action.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body)
  });
  let data;
  try {
    data = await resp.json();
  } catch (e) {
    data = { status: "error", message: "Raspuns invalid de la server" };
  }
  return data;
}

function escapeHtml(s) {
  return String(s == null ? "" : s).replace(/[&<>"']/g, c => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
  }[c]));
}

function formatMoney(v) {
  const n = Number(v || 0);
  return n.toFixed(2);
}

function formatQty(v) {
  const n = Number(v || 0);
  return n.toFixed(2).replace(/\.00$/, "").replace(/(\.\d)0$/, "$1");
}

// Cantitatea afisata cu numarul de zecimale configurat (tblSet.NrZecCant).
function formatQtyDec(v) {
  const n = Number(v || 0);
  return n.toFixed(MOBILE_STATE.nrZecCant);
}

function parseNum(v) {
  const n = parseFloat(String(v == null ? "" : v).replace(",", "."));
  return isNaN(n) ? 0 : n;
}

function colorIntToHex(v) {
  if (v === null || v === undefined) return null;
  const n = Number(v) >>> 0;
  const r = n & 0xFF, g = (n >> 8) & 0xFF, b = (n >> 16) & 0xFF;
  return "#" + [r, g, b].map(x => x.toString(16).padStart(2, "0")).join("");
}

function vibrate(ms) {
  try { if (navigator.vibrate) navigator.vibrate(ms); } catch (e) { /* ignore */ }
}

function isFastFood() {
  return MOBILE_STATE.tipVanz === "fastfood";
}

let mvToastTimer = null;
function showToast(text) {
  const el = document.getElementById("mv-toast");
  if (!el) return;
  el.textContent = text || "";
  el.classList.add("show");
  clearTimeout(mvToastTimer);
  mvToastTimer = setTimeout(() => el.classList.remove("show"), 2600);
}

function openModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.add("open");
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.remove("open");
}

/* ==========================================================================
   MENIU
   ========================================================================== */
async function loadMenu() {
  const data = await mvGet("api/menu.php");
  if (data.status !== "success") throw new Error(data.message || "Eroare meniu");

  MOBILE_STATE.groups = data.groups || [];
  MOBILE_STATE.productsByGroup = data.productsByGroup || {};
  MOBILE_STATE.messages = data.messages || [];
  MOBILE_STATE.meniulZilei = (data.meniulZilei == 1) ? 1 : 0;
  MOBILE_STATE.modLogare = (data.modLogare == 0) ? 0 : 1;
  MOBILE_STATE.tipVanz = (data.tipVanz === "fastfood") ? "fastfood" : "restaurant";
  MOBILE_STATE.cantMax = (Number(data.cantMax) > 0) ? Number(data.cantMax) : 1000;
  const nz = parseInt(data.nrZecCant, 10);
  MOBILE_STATE.nrZecCant = (nz >= 0 && nz <= 2) ? nz : 1;
  MOBILE_STATE.red = (data.red === 0 || data.red === "0") ? 0 : 1;
  MOBILE_STATE.parolaDiscount = (data.parolaDiscount == 1) ? 1 : 0;
  MOBILE_STATE.parolaStornare = (data.parolaStornare == 1) ? 1 : 0;

  // Index plat pentru cautare / scanare
  const all = [];
  Object.keys(MOBILE_STATE.productsByGroup).forEach(grp => {
    (MOBILE_STATE.productsByGroup[grp] || []).forEach(p => all.push(p));
  });
  MOBILE_STATE.allProducts = all;

  updateWaiterChip();
}

/* ==========================================================================
   AUTENTIFICARE OSPATAR
   ========================================================================== */
function saveWaiterSession() {
  try {
    if (MOBILE_STATE.modLogare === 1 && MOBILE_STATE.waiter) {
      sessionStorage.setItem(MV_WAITER_KEY, JSON.stringify(MOBILE_STATE.waiter));
    } else {
      sessionStorage.removeItem(MV_WAITER_KEY);
    }
  } catch (e) { /* ignore */ }
}

function restoreWaiterSession() {
  if (MOBILE_STATE.modLogare !== 1) return;
  try {
    const raw = sessionStorage.getItem(MV_WAITER_KEY);
    if (!raw) return;
    const w = JSON.parse(raw);
    if (w && w.nrOsp) setWaiter(w, true);
  } catch (e) { /* ignore */ }
}

function setWaiter(w, silent) {
  MOBILE_STATE.waiter = {
    nrOsp: Number(w.nrOsp),
    nume: w.nume || "Ospatar",
    rol: w.rol || ""
  };
  MOBILE_STATE.loggedIn = true;
  saveWaiterSession();
  updateWaiterChip();
  if (!silent) {
    showToast("Autentificat: " + MOBILE_STATE.waiter.nume);
    vibrate(15);
  }
}

function logoutWaiter() {
  MOBILE_STATE.waiter = null;
  MOBILE_STATE.loggedIn = false;
  try { sessionStorage.removeItem(MV_WAITER_KEY); } catch (e) { /* ignore */ }
  updateWaiterChip();
}

function updateWaiterChip() {
  const chip = document.getElementById("mv-waiter-chip");
  if (!chip) return;
  if (MOBILE_STATE.loggedIn && MOBILE_STATE.waiter) {
    chip.textContent = MOBILE_STATE.waiter.nume;
    chip.classList.add("on");
  } else {
    chip.textContent = "Autentificare";
    chip.classList.remove("on");
  }
}

function requireLogin(cb) {
  if (MOBILE_STATE.loggedIn) {
    if (cb) cb();
    return;
  }
  MOBILE_STATE.afterLogin = cb || null;
  openLogin();
}

function openLogin() {
  openNumpad({
    title: "Parola ospatar",
    initial: "",
    password: true,
    decimal: false,
    onSubmit: async (val) => {
      if (!val) { showToast("Introduceti parola!"); return; }
      const res = await mvPost("authenticate", { parola: val });
      if (res.status !== "success" || res.programare || res.rapoarte) {
        showToast("Parola incorecta sau nu apartine unui ospatar!");
        numpadClear();
        return;
      }
      closeModal("modal-numpad");
      setWaiter({ nrOsp: res.nrOsp, nume: res.nume, rol: res.rol });
      const cb = MOBILE_STATE.afterLogin;
      MOBILE_STATE.afterLogin = null;
      if (cb) cb();
    }
  });
}

function mvWaiterMenu() {
  if (!MOBILE_STATE.loggedIn) {
    MOBILE_STATE.afterLogin = null;
    openLogin();
    return;
  }
  appConfirm("Delogare " + MOBILE_STATE.waiter.nume + "?", () => {
    logoutWaiter();
    showView("view-tables");
  }, "Delogare", "Renunta");
}

/* ==========================================================================
   NUMPAD GENERIC
   ========================================================================== */
const numpadState = { value: "", decimals: 0, password: false, fresh: false, max: null, onSubmit: null };

function openNumpad(opts) {
  numpadState.value = String(opts.initial == null ? "" : opts.initial);
  // Numarul de zecimale permise (0 = doar intregi). `decimal:true` = 1 zecimala.
  const dec = (opts.decimals != null) ? parseInt(opts.decimals, 10) : (opts.decimal ? 1 : 0);
  numpadState.decimals = (dec >= 0) ? dec : 0;
  numpadState.password = !!opts.password;
  // La prima cifra tastata valoarea implicita este inlocuita (nu concatenata).
  numpadState.fresh = true;
  // Limita maxima optionala (ex. cantitatea maxima pe linie).
  numpadState.max = (opts.max != null && Number(opts.max) > 0) ? Number(opts.max) : null;
  numpadState.onSubmit = opts.onSubmit || null;
  const title = document.getElementById("numpad-title");
  if (title) title.textContent = opts.title || "Introduceti";
  renderNumpad();
  openModal("modal-numpad");
}

function renderNumpad() {
  const disp = document.getElementById("numpad-display");
  if (disp) {
    disp.textContent = numpadState.password
      ? "\u2022".repeat(numpadState.value.length)
      : (numpadState.value || "0");
    // Asiguram ca ultima cifra introdusa este mereu vizibila.
    disp.scrollLeft = disp.scrollWidth;
  }
  const grid = document.getElementById("numpad-grid");
  if (!grid) return;
  const dot = numpadState.decimals > 0 ? "." : "";
  const keys = ["7", "8", "9", "4", "5", "6", "1", "2", "3", dot, "0", "\u232B"];
  grid.innerHTML = keys.map(k => {
    if (k === "") return `<button class="mv-num-key mv-num-empty" disabled></button>`;
    if (k === "\u232B") return `<button class="mv-num-key" onclick="numpadBackspace()">\u232B</button>`;
    return `<button class="mv-num-key" onclick="numpadKey('${k}')">${k}</button>`;
  }).join("");
}

function numpadKey(ch) {
  let next = numpadState.value;

  // Punctul zecimal nu sterge valoarea implicita (permite continuarea ei, ex. 1 -> 1.5).
  if (ch === ".") {
    if (numpadState.decimals <= 0) return;
    numpadState.fresh = false;
    if (next === "") next = "0";
    if (next.includes(".")) next = next.split(".")[0];
    next += ".";
  } else {
    // Prima cifra tastata inlocuieste valoarea implicita (ex. 1 -> 3, nu 13).
    if (numpadState.fresh) {
      next = "";
      numpadState.fresh = false;
    }
    if (next.length >= 12) return;
    // Respectam numarul de zecimale configurat.
    if (next.includes(".")) {
      const decPart = next.split(".")[1] || "";
      if (decPart.length >= numpadState.decimals) return;
    }
    // Fara zerouri nesemnificative la inceput (ex. 0 -> 5 devine 5).
    next = (next === "0") ? ch : (next + ch);
  }

  // Respectam limita maxima configurata (ex. cantitatea maxima pe linie).
  if (numpadState.max != null) {
    const n = parseFloat(next);
    if (!isNaN(n) && n > numpadState.max) {
      showToast("Valoarea maxima admisa este " + formatQty(numpadState.max));
      return;
    }
  }

  numpadState.value = next;
  renderNumpad();
}

function numpadBackspace() {
  numpadState.fresh = false;
  numpadState.value = numpadState.value.slice(0, -1);
  renderNumpad();
}

function numpadClear() {
  numpadState.fresh = false;
  numpadState.value = "";
  renderNumpad();
}

function numpadSubmit() {
  if (typeof numpadState.onSubmit === "function") {
    numpadState.onSubmit(numpadState.value);
  }
}

/* ==========================================================================
   MESE
   ========================================================================== */
async function loadTables() {
  const data = await mvGet("api/get_tables.php");
  if (data.status === "success") {
    MOBILE_STATE.tables = data.tables || [];
  }
  renderTables();
  renderFooter();
}

function setTablesFilter(f) {
  MOBILE_STATE.tablesFilter = f;
  document.querySelectorAll("#mv-tables-filter .mv-filter").forEach(b => {
    b.classList.toggle("active", b.dataset.filter === f);
  });
  renderTables();
}

// Deschide o masa dupa numarul introdus din tastatura numerica, daca este permisa.
function openTableByNumber() {
  openNumpad({
    title: "Deschide masa dupa numar",
    initial: "",
    decimal: false,
    max: 80,
    onSubmit: (val) => {
      const n = parseInt(val, 10);
      if (isNaN(n) || n < 1 || n > 80) {
        showToast("Numar masa invalid (1-80)");
        return;
      }
      const t = MOBILE_STATE.tables.find(x => x.nrMasa === n);
      if (!t) {
        showToast("Masa " + n + " nu exista.");
        return;
      }
      const visible = t.isOccupied || (t.present && t.afisez !== false);
      if (!visible) {
        showToast("Masa " + n + " nu este disponibila.");
        return;
      }
      closeModal("modal-numpad");
      selectTable(n);
    }
  });
}

function renderTables() {
  const grid = document.getElementById("mv-tables-grid");
  const empty = document.getElementById("mv-tables-empty");
  if (!grid) return;

  const f = MOBILE_STATE.tablesFilter;
  const myNr = MOBILE_STATE.waiter ? Number(MOBILE_STATE.waiter.nrOsp) : null;
  let count = 0;
  let html = "";

  MOBILE_STATE.tables.forEach(t => {
    const visible = t.isOccupied || (t.present && t.afisez !== false);
    if (!visible) return;
    if (f === "free" && t.isOccupied) return;
    if (f === "busy" && !t.isOccupied) return;
    if (f === "mine" && !(t.isOccupied && myNr != null && Number(t.nrOp) === myNr)) return;

    count++;
    let cls = "mv-table";
    let style = "";

    if (t.backColor !== null && t.backColor !== undefined) {
      style += `background:${colorIntToHex(t.backColor)};`;
    }
    if (t.foreColor !== null && t.foreColor !== undefined) {
      style += `color:${colorIntToHex(t.foreColor)};`;
    }
    if (t.bold) style += "font-weight:800;";

    let sub;
    if (t.isOccupied) {
      const mine = myNr != null && Number(t.nrOp) === myNr;
      cls += mine ? " occupied mine" : " occupied other";
      sub = `<span class="mv-table-sub">${escapeHtml(t.casier || "")} \u00B7 ${formatMoney(t.total).replace(/\.00$/, "")}</span>`;
    } else {
      sub = `<span class="mv-table-sub mv-table-sub-free">liber</span>`;
    }

    html += `<button class="${cls}" style="${style}" onclick="selectTable(${t.nrMasa})">
      <span class="mv-table-num">${escapeHtml(t.label || t.nrMasa)}</span>${sub}
    </button>`;
  });

  grid.innerHTML = html;
  if (empty) empty.style.display = count ? "none" : "";
}

function renderFooter() {
  const el = document.getElementById("mv-tables-footer");
  if (!el) return;
  const total = MOBILE_STATE.tables.filter(t => t.isOccupied).length;
  el.textContent = total + " mese ocupate \u00B7 " + MOBILE_STATE.tables.filter(t => t.present).length + " mese configurate";
}

async function selectTable(nr) {
  requireLogin(async () => {
    const t = MOBILE_STATE.tables.find(x => x.nrMasa === nr);
    if (t && t.isOccupied && t.nrOp != null && Number(t.nrOp) !== Number(MOBILE_STATE.waiter.nrOsp)) {
      appAlert("Masa " + nr + " este deschisa de alt ospatar (" + (t.casier || "?") + ").");
      return;
    }

    try {
      await loadOrder(nr);
    } catch (err) {
      appAlert(err.message);
      return;
    }

    if (MOBILE_STATE.docId && MOBILE_STATE.bonNrOp != null &&
        Number(MOBILE_STATE.bonNrOp) !== Number(MOBILE_STATE.waiter.nrOsp)) {
      appAlert("Masa " + nr + " este deschisa de alt ospatar (" + (MOBILE_STATE.bonCasier || "?") + ").");
      return;
    }

    MOBILE_STATE.activeGroup = null;
    showView("view-mark");
    renderMark();
    closeNota();
  });
}

/* ==========================================================================
   NOTA / COMANDA
   ========================================================================== */
async function loadOrder(nr) {
  const data = await mvGet("api/get_order.php?masa=" + encodeURIComponent(nr));
  if (data.status !== "success") throw new Error(data.message || "Eroare incarcare nota");

  MOBILE_STATE.masaCurenta = nr;
  MOBILE_STATE.docId = data.docId;
  MOBILE_STATE.hasOrder = !!data.hasOrder;
  MOBILE_STATE.bonNrOp = data.nrOp;
  MOBILE_STATE.bonCasier = data.casier || "";
  MOBILE_STATE.nrDoc = data.nrDoc || null;
  MOBILE_STATE.subtotal = Number(data.subtotal || 0);
  MOBILE_STATE.total = Number(data.total || 0);
  MOBILE_STATE.reducere = Number(data.reducere || 0);
  MOBILE_STATE.articole = data.articole || [];
  const prevSel = MOBILE_STATE.selectedEcrId;
  MOBILE_STATE.selectedEcrId = MOBILE_STATE.articole.some(a => a.ecrId === prevSel) ? prevSel : null;

  renderMarkMeta();
  renderNota();
}

function renderMarkMeta() {
  const masaEl = document.getElementById("mv-mark-masa");
  if (masaEl) masaEl.textContent = MOBILE_STATE.masaCurenta != null ? MOBILE_STATE.masaCurenta : "-";

  const redEl = document.getElementById("mv-mark-reducere");
  if (redEl) {
    if (MOBILE_STATE.reducere > 0.005) {
      redEl.textContent = "Reducere: " + formatMoney(MOBILE_STATE.reducere);
      redEl.style.display = "";
    } else {
      redEl.style.display = "none";
    }
  }
}

function renderMark() {
  renderGroupsBar();
  renderCatalog();
  renderMarkMeta();
  renderNota();
}

/* -------- Grupe -------- */
function getMZProducts() {
  return MOBILE_STATE.allProducts
    .filter(p => Number(p.MZ) === 1)
    .sort((a, b) => String(a.Denumire).localeCompare(String(b.Denumire), "ro"));
}

function renderGroupsBar() {
  const bar = document.getElementById("mv-groups-bar");
  if (!bar) return;

  let html = `<button class="mv-group-chip ${MOBILE_STATE.activeGroup == null ? "active" : ""}" onclick="openGroup(null)">Grupe</button>`;

  if (MOBILE_STATE.meniulZilei === 1 && getMZProducts().length > 0) {
    html += `<button class="mv-group-chip ${MOBILE_STATE.activeGroup === "MZ" ? "active" : ""}" onclick="openGroup('MZ')">Meniul Zilei</button>`;
  }

  // Numele grupelor in ordine alfabetica (dupa Denumire), in bara orizontala.
  const grupeSortate = MOBILE_STATE.groups.slice().sort((a, b) =>
    String(a.Denumire || "").localeCompare(String(b.Denumire || ""), "ro"));
  grupeSortate.forEach(g => {
    const act = MOBILE_STATE.activeGroup === g.NrGrp ? "active" : "";
    html += `<button class="mv-group-chip ${act}" onclick="openGroup(${g.NrGrp})">${escapeHtml(g.Denumire)}</button>`;
  });

  bar.innerHTML = html;
  const act = bar.querySelector(".mv-group-chip.active");
  if (act && act.scrollIntoView) {
    try { act.scrollIntoView({ inline: "center", block: "nearest" }); } catch (e) { /* ignore */ }
  }
}

function openGroup(nrGrp) {
  MOBILE_STATE.activeGroup = nrGrp;
  renderGroupsBar();
  renderCatalog();
}

function productButtonHtml(p) {
  let style = "";
  if (p.BackColor) style += `background:${p.BackColor};`;
  if (p.FontColor) style += `color:${p.FontColor};`;
  else if (p.BackColor && p.BackColor.toLowerCase() === "#000000") style += "color:#ffffff;";
  if (p.Bold) style += "font-weight:800;";
  return `<button class="mv-product" style="${style}" onclick="addProduct(${p.ProdID})">
    <span class="mv-product-name">${escapeHtml(p.Denumire)}</span>
    <span class="mv-product-price">${formatMoney(p.Pret)}</span>
  </button>`;
}

function renderCatalog() {
  const grid = document.getElementById("mv-products-grid");
  const empty = document.getElementById("mv-products-empty");
  if (!grid) return;

  // Fara grupa selectata -> afisam tile-urile de grupe
  if (MOBILE_STATE.activeGroup == null) {
    let html = "";
    MOBILE_STATE.groups.forEach(g => {
      html += `<button class="mv-group-tile" onclick="openGroup(${g.NrGrp})">${escapeHtml(g.Denumire)}</button>`;
    });
    grid.innerHTML = html;
    if (empty) empty.style.display = html ? "none" : "";
    return;
  }

  let prods = [];
  if (MOBILE_STATE.activeGroup === "MZ") {
    prods = getMZProducts();
  } else {
    prods = MOBILE_STATE.productsByGroup[MOBILE_STATE.activeGroup] || [];
  }

  const html = prods.map(productButtonHtml).join("");
  grid.innerHTML = html;
  if (empty) empty.style.display = html ? "none" : "";
}

/* -------- Nota -------- */
function selectedArticle() {
  return MOBILE_STATE.articole.find(a => a.ecrId === MOBILE_STATE.selectedEcrId) || null;
}

function lastProductEcrId() {
  let last = null;
  MOBILE_STATE.articole.forEach(a => { if (!a.storno) last = a.ecrId; });
  return last;
}

function renderNota() {
  const list = document.getElementById("mv-nota-list");
  if (!list) return;

  const arts = MOBILE_STATE.articole;
  let html = "";
  let qtyTotal = 0;

  if (!arts.length) {
    html = `<div class="mv-nota-empty">Nota este goala.</div>`;
  } else {
    arts.forEach(a => {
      const isStorno = !!a.storno;
      if (!isStorno) qtyTotal += Number(a.cantitate || 0);

      const sel = MOBILE_STATE.selectedEcrId === a.ecrId ? " selected" : "";
      const stC = isStorno ? " storno" : "";
      const tag = (!isStorno && a.preluat) ? `<span class="mv-line-tag">trimis</span>` : "";

      let modsHtml = "";
      if (a.mods && a.mods.length) {
        modsHtml = `<div class="mv-line-mods">` + a.mods.map(m =>
          `<span class="mv-line-mod">${escapeHtml(m.text)}<button class="mv-mod-del" onclick="event.stopPropagation();deleteMod(${m.ecrId})">&times;</button></span>`
        ).join("") + `</div>`;
      }

      // Discountul pe PRODUS (linie) se arata sub produs; cel pe SUBTOTAL apare
      // doar in total (Reducere) sub subtotal, fara sa fie repartizat pe produse.
      const origVal = (a.valoareOriginala != null) ? Number(a.valoareOriginala) : Number(a.valoare || 0);
      const netVal = Number(a.valoare || 0);
      const isLineDisc = !isStorno && !!a.comment && (origVal - netVal > 0.005);
      const shownVal = isStorno ? netVal : origVal;

      let subHtml = "";
      if (isLineDisc) {
        subHtml += `<div class="mv-line-disc">discount: -${formatMoney(origVal - netVal)}</div>`;
        if (a.comment && a.comment !== "Discount produs") {
          subHtml += `<div class="mv-line-comment">${escapeHtml(a.comment)}</div>`;
        }
      }
      const den = a.denumire + (isStorno ? " (ANULARE)" : "");

      html += `<div class="mv-nota-line${sel}${stC}" onclick="onLineTap(${a.ecrId})">
        <div class="mv-line-main">
          <span class="mv-line-name">${escapeHtml(den)}${tag}</span>
          <span class="mv-line-qty">${formatQtyDec(a.cantitate)}</span>
          <span class="mv-line-val">${formatMoney(shownVal)}</span>
        </div>
        ${subHtml}
        ${modsHtml}
      </div>`;
    });
  }

  list.innerHTML = html;

  const subtotal = document.getElementById("mv-nota-subtotal");
  const reducere = document.getElementById("mv-nota-reducere");
  const total = document.getElementById("mv-nota-total");
  if (subtotal) subtotal.textContent = formatMoney(MOBILE_STATE.subtotal);
  if (reducere) reducere.textContent = formatMoney(MOBILE_STATE.reducere);
  if (total) total.textContent = formatMoney(MOBILE_STATE.total);

  const count = document.getElementById("mv-cart-count");
  const barCount = document.getElementById("mv-cartbar-count");
  const barTotal = document.getElementById("mv-cartbar-total");
  if (count) count.textContent = String(MOBILE_STATE.articole.filter(a => !a.storno).length);
  if (barCount) barCount.textContent = formatQty(qtyTotal) + " produse";
  if (barTotal) barTotal.textContent = formatMoney(MOBILE_STATE.total) + " L";
}

function openNota() {
  const panel = document.getElementById("mv-nota-panel");
  const back = document.getElementById("mv-nota-backdrop");
  if (panel) panel.classList.add("open");
  if (back) back.classList.add("open");
}

function closeNota() {
  const panel = document.getElementById("mv-nota-panel");
  const back = document.getElementById("mv-nota-backdrop");
  if (panel) panel.classList.remove("open");
  if (back) back.classList.remove("open");
}

function toggleNota() {
  const panel = document.getElementById("mv-nota-panel");
  if (!panel) return;
  if (panel.classList.contains("open")) closeNota();
  else openNota();
}

/* ==========================================================================
   ADAUGARE PRODUS / SCAN / CAUTARE
   ========================================================================== */
async function addProduct(prodId) {
  requireLogin(async () => {
    if (!MOBILE_STATE.masaCurenta) {
      showToast("Selectati mai intai o masa.");
      return;
    }
    try {
      const res = await mvPost("add_product", {
        nrMasa: MOBILE_STATE.masaCurenta,
        nrOp: MOBILE_STATE.waiter.nrOsp,
        prodId: prodId,
        cantitate: 1
      });
      if (res.status !== "success") throw new Error(res.message || "Eroare");
      vibrate(12);
      showToast(res.denumire ? ("Adaugat: " + res.denumire) : "Produs adaugat");
      await loadOrder(MOBILE_STATE.masaCurenta);
    } catch (err) {
      appAlert("Eroare: " + err.message);
    }
  });
}

function toggleScan() {
  const row = document.getElementById("mv-scan-row");
  const open = document.getElementById("mv-scan-open");
  if (!row) return;
  const isHidden = row.style.display === "none";
  row.style.display = isHidden ? "flex" : "none";
  if (open) open.style.display = isHidden ? "none" : "";
  if (isHidden) {
    const input = document.getElementById("mv-scan-input");
    if (input) setTimeout(() => input.focus(), 50);
  }
}

function onScanKeydown(e) {
  if (e.key === "Enter" || e.keyCode === 13) {
    e.preventDefault();
    const input = document.getElementById("mv-scan-input");
    handleScan(input ? input.value : "");
    if (input) input.value = "";
  }
}

function handleScan(code) {
  const c = String(code || "").trim();
  if (!c) return;
  let p = MOBILE_STATE.allProducts.find(x => x.BarCod && String(x.BarCod).toLowerCase() === c.toLowerCase());
  if (!p) {
    const n = parseInt(c, 10);
    if (!isNaN(n)) p = MOBILE_STATE.allProducts.find(x => Number(x.ProdID) === n);
  }
  if (!p) {
    showToast("Cod negasit: " + c);
    return;
  }
  addProduct(p.ProdID);
}

async function openSearch() {
  const inp = document.getElementById("mv-search-input");
  const res = document.getElementById("mv-search-results");
  if (inp) inp.value = "";
  if (res) res.innerHTML = "";
  openModal("modal-search");
  setTimeout(() => { if (inp) inp.focus(); }, 60);
}

function onSearchInput() {
  const inp = document.getElementById("mv-search-input");
  const res = document.getElementById("mv-search-results");
  if (!inp || !res) return;
  const q = inp.value.trim().toLowerCase();
  if (!q) { res.innerHTML = ""; return; }

  const matches = MOBILE_STATE.allProducts.filter(p => {
    const name = (p.Denumire || "").toLowerCase();
    const code = String(p.BarCod || "").toLowerCase();
    return name.includes(q) || code.includes(q) || String(p.ProdID) === q;
  }).slice(0, 80);

  if (!matches.length) {
    res.innerHTML = `<div class="mv-empty">Niciun rezultat.</div>`;
    return;
  }
  res.innerHTML = matches.map(p =>
    `<button class="mv-search-item" onclick="addProduct(${p.ProdID});closeModal('modal-search')">
      <span>${escapeHtml(p.Denumire)}</span><span>${formatMoney(p.Pret)}</span>
    </button>`
  ).join("");
}

/* ==========================================================================
   ACTIUNI PE LINIE
   ========================================================================== */
function onLineTap(ecrId) {
  const a = MOBILE_STATE.articole.find(x => x.ecrId === ecrId);
  if (!a) return;
  MOBILE_STATE.selectedEcrId = ecrId;
  renderNota();
  openLineActions(a);
}

function openLineActions(a) {
  const body = document.getElementById("line-actions-body");
  const title = document.getElementById("line-actions-title");
  if (title) title.textContent = a.denumire + " x" + formatQtyDec(a.cantitate);
  if (!body) return;

  let html = "";
  if (!a.storno) {
    if (!a.preluat) {
      html += `<button class="mv-action-btn" onclick="openQtyModal()">Cantitate (QTY)</button>`;
    }
    html += `<button class="mv-action-btn" onclick="openModsModal()">Mod preparare</button>`;
    if (MOBILE_STATE.red !== 0) {
      html += `<button class="mv-action-btn" onclick="openDiscountModal('line')">Discount pe linie</button>`;
    }
  }
  html += `<button class="mv-action-btn mv-action-danger" onclick="openVoidModal()">${a.storno ? "Revoca anularea" : "VD / Anulare"}</button>`;

  body.innerHTML = html;
  openModal("modal-line");
}

/* -------- Meniu actiuni pe nota -------- */
function openMarkMenu() {
  const body = document.getElementById("line-actions-body");
  const title = document.getElementById("line-actions-title");
  if (title) title.textContent = "Actiuni nota";
  if (!body) return;

  let html = "";
  if (MOBILE_STATE.red !== 0) {
    html += `<button class="mv-action-btn" onclick="openDiscountModal('bill')">Discount pe nota</button>`;
  }
  html += `<button class="mv-action-btn" onclick="menuMods()">Mod preparare</button>`;
  html += `<button class="mv-action-btn" onclick="openTransferModal()">Transfer produse</button>`;
  if (MOBILE_STATE.meniulZilei === 1 && getMZProducts().length > 0) {
    html += `<button class="mv-action-btn" onclick="menuMeniulZilei()">Meniul Zilei</button>`;
  }
  body.innerHTML = html;
  openModal("modal-line");
}

function menuMods() {
  const last = lastProductEcrId();
  if (last == null) { showToast("Nota nu are produse."); return; }
  MOBILE_STATE.selectedEcrId = last;
  renderNota();
  closeModal("modal-line");
  openModsModal();
}

function menuMeniulZilei() {
  closeModal("modal-line");
  const grid = document.getElementById("mv-products-grid");
  openGroup("MZ");
  if (grid) grid.scrollTop = 0;
}

/* -------- QTY -------- */
function openQtyModal() {
  const a = selectedArticle();
  if (!a) { showToast("Selectati o linie."); return; }
  if (a.preluat) { appAlert("Produsul a fost trimis la sectie; cantitatea nu mai poate fi modificata."); return; }
  closeModal("modal-line");
  openNumpad({
    title: "Cantitate - " + a.denumire,
    initial: formatQtyDec(a.cantitate),
    decimals: MOBILE_STATE.nrZecCant,
    max: MOBILE_STATE.cantMax,
    onSubmit: async (val) => {
      const q = parseNum(val);
      if (q <= 0) { showToast("Cantitate invalida"); return; }
      if (q > MOBILE_STATE.cantMax) {
        showToast("Cantitatea maxima admisa este " + formatQty(MOBILE_STATE.cantMax));
        return;
      }
      const res = await mvPost("update_qty", {
        nrMasa: MOBILE_STATE.masaCurenta,
        ecrId: a.ecrId,
        cantitate: q
      });
      if (res.status !== "success") { appAlert(res.message); return; }
      closeModal("modal-numpad");
      await loadOrder(MOBILE_STATE.masaCurenta);
    }
  });
}

/* -------- MOD PREPARARE -------- */
function openModsModal() {
  const a = selectedArticle();
  if (!a) { showToast("Selectati o linie."); return; }
  closeModal("modal-line");
  renderModsModal(a);
  openModal("modal-mods");
}

function renderModsModal(a) {
  const body = document.getElementById("mods-body");
  if (!body) return;

  let html = "";

  const lastEcr = lastProductEcrId();
  if (a.ecrId !== lastEcr) {
    html += `<div class="mv-info-note">Modurile se ataseaza ultimului produs de pe nota. Adaugati produsul dorit la finalul notei pentru a-i asocia un mod.</div>`;
  }

  if (a.mods && a.mods.length) {
    html += `<div class="mv-field-label">Moduri pe linie</div><div class="mv-mod-list">`;
    a.mods.forEach(m => {
      html += `<span class="mv-mod-chip">${escapeHtml(m.text)}<button onclick="deleteMod(${m.ecrId})">&times;</button></span>`;
    });
    html += `</div>`;
  }

  html += `<div class="mv-field-label">Adauga mod</div><div class="mv-mod-options">`;
  if (!MOBILE_STATE.messages.length) {
    html += `<div class="mv-empty">Nu exista moduri de preparare configurate.</div>`;
  } else {
    MOBILE_STATE.messages.forEach(msg => {
      html += `<button class="mv-mod-option" onclick="addMod(${msg.NrMesaj})">${escapeHtml(msg.Mesaj)}</button>`;
    });
  }
  html += `</div>`;

  body.innerHTML = html;
}

async function addMod(nrMesaj) {
  const res = await mvPost("add_mod", { nrMasa: MOBILE_STATE.masaCurenta, nrMesaj: nrMesaj });
  if (res.status !== "success") { appAlert(res.message); return; }
  closeModal("modal-mods");
  showToast("Mod adaugat");
  await loadOrder(MOBILE_STATE.masaCurenta);
}

async function deleteMod(ecrId) {
  const res = await mvPost("delete_mod", { nrMasa: MOBILE_STATE.masaCurenta, ecrId: ecrId });
  if (res.status !== "success") { appAlert(res.message); return; }
  await loadOrder(MOBILE_STATE.masaCurenta);
  if (document.getElementById("modal-mods").classList.contains("open")) {
    const a = selectedArticle();
    if (a) renderModsModal(a); else closeModal("modal-mods");
  }
}

/* -------- DISCOUNT -------- */
const discState = { scope: "bill", mode: "percent", value: 0 };

function openDiscountModal(scope) {
  if (MOBILE_STATE.red === 0) { showToast("Discountul nu este permis."); return; }
  const a = selectedArticle();
  if (scope === "line" && !a) { showToast("Selectati o linie."); return; }
  // Daca discountul cere parola, o cerem inainte de a deschide modalul.
  ensureDiscountParolaMobile(() => openDiscountModalNow(scope));
}

function openDiscountModalNow(scope) {
  discState.scope = scope || "bill";
  discState.mode = "percent";
  discState.value = 0;
  closeModal("modal-line");
  const motiv = document.getElementById("disc-motiv");
  if (motiv) motiv.value = "";
  renderDiscount();
  openModal("modal-discount");
}

function closeDiscountModalMobile() {
  MOBILE_STATE.discountParola = "";
  closeModal("modal-discount");
}

// Cere parola de discount (numerica) daca este setata in tblParola.ParolaDiscount.
// Intreaba serverul de fiecare data, ca sa nu depinda de un flag local invechit.
async function ensureDiscountParolaMobile(cb) {
  if (MOBILE_STATE.discountParola) {
    if (cb) cb();
    return;
  }

  let required = (MOBILE_STATE.parolaDiscount === 1);
  try {
    const res = await mvPost("verify_discount_parola", { parola: "" });
    required = (res.required === true);
    MOBILE_STATE.parolaDiscount = required ? 1 : 0;
  } catch (e) {
    // Daca nu putem verifica, ne bazam pe flag-ul local.
  }

  if (!required) {
    if (cb) cb();
    return;
  }
  promptDiscountParolaMobile(cb);
}

function promptDiscountParolaMobile(cb) {
  openNumpad({
    title: "Parola discount",
    initial: "",
    decimals: 0,
    password: true,
    onSubmit: async (val) => {
      if (!val) { showToast("Introduceti parola de discount!"); return; }
      const res = await mvPost("verify_discount_parola", { parola: val });
      if (res.status !== "success") {
        showToast("Parola de discount incorecta!");
        numpadClear();
        return;
      }
      closeModal("modal-numpad");
      MOBILE_STATE.discountParola = val;
      if (cb) cb();
    }
  });
}

function renderDiscount() {
  document.querySelectorAll("#disc-scope button").forEach(b => {
    const isLine = b.dataset.scope === "line";
    b.classList.toggle("active", b.dataset.scope === discState.scope);
    b.disabled = isLine && !selectedArticle();
  });
  document.querySelectorAll("#disc-mode button").forEach(b => {
    b.classList.toggle("active", b.dataset.mode === discState.mode);
  });
  const v = document.getElementById("disc-value-btn");
  if (v) v.textContent = discState.mode === "percent" ? (formatQty(discState.value) + " %") : (formatMoney(discState.value) + " L");
}

function discSetScope(scope) {
  discState.scope = scope;
  renderDiscount();
}

function discSetMode(mode) {
  discState.mode = mode;
  renderDiscount();
}

function discEditValue() {
  openNumpad({
    title: discState.mode === "percent" ? "Procent discount" : "Valoare discount (L)",
    initial: discState.value ? String(discState.value) : "",
    decimals: 2,
    onSubmit: (val) => {
      discState.value = parseNum(val);
      closeModal("modal-numpad");
      renderDiscount();
    }
  });
}

async function discApply() {
  // Daca discountul cere parola si nu a fost verificata, o cerem acum.
  if (MOBILE_STATE.parolaDiscount === 1 && !MOBILE_STATE.discountParola) {
    ensureDiscountParolaMobile(() => discApply());
    return;
  }
  if (discState.value <= 0) { showToast("Introduceti valoarea discountului"); return; }
  const a = selectedArticle();
  if (discState.scope === "line" && !a) { showToast("Selectati o linie"); return; }
  const motivEl = document.getElementById("disc-motiv");
  const motiv = motivEl ? motivEl.value.trim() : "";

  const payload = {
    nrMasa: MOBILE_STATE.masaCurenta,
    scope: discState.scope,
    mode: discState.mode,
    value: discState.value
  };
  if (motiv) payload.motiv = motiv;
  if (discState.scope === "line") payload.ecrId = a.ecrId;
  if (MOBILE_STATE.parolaDiscount === 1) payload.parola = MOBILE_STATE.discountParola;

  const res = await mvPost("apply_discount", payload);
  if (res.status !== "success") {
    // Serverul cere parola (sau a fost schimbata intre timp): o cerem si reincercam.
    if (res.required === true || /parola/i.test(res.message || "")) {
      MOBILE_STATE.discountParola = "";
      ensureDiscountParolaMobile(() => discApply());
      return;
    }
    appAlert(res.message);
    return;
  }
  closeDiscountModalMobile();
  showToast("Discount aplicat");
  await loadOrder(MOBILE_STATE.masaCurenta);
}

/* -------- VD / ANULARE -------- */
const voidState = { article: null, cantitate: 0 };

function openVoidModal() {
  const a = selectedArticle();
  if (!a) { showToast("Selectati o linie."); return; }
  closeModal("modal-line");
  voidState.article = a;
  voidState.cantitate = a.storno
    ? Math.abs(Number(a.cantitate || 0))
    : Number(a.ramas != null ? a.ramas : a.cantitate);
  renderVoid();
  openModal("modal-void");
}

function renderVoid() {
  const body = document.getElementById("void-body");
  const a = voidState.article;
  if (!body || !a) return;

  let html = "";

  if (a.storno) {
    if (a.preluat) {
      html += `<div class="mv-info-note">Aceasta linie de anulare a fost deja trimisa la sectie si nu mai poate fi revocata.</div>`;
    } else {
      html += `<div class="mv-info-note">Aceasta este o linie de anulare netrimisa. Revocarea o sterge si restabileste discountul liniei originale.</div>`;
      html += `<button class="mv-primary-btn danger" onclick="voidApply()">Revoca anularea</button>`;
    }
    body.innerHTML = html;
    return;
  }

  if (!a.preluat) {
    html += `<div class="mv-info-note">Linia nu a fost trimisa la sectie. Anularea reduce sau sterge linia direct.</div>`;
  } else if (MOBILE_STATE.parolaStornare === 1) {
    html += `<div class="mv-info-note">Linia a fost trimisa la sectie. Se va scrie o nota de anulare, cu parola de manager.</div>`;
  } else {
    html += `<div class="mv-info-note">Linia a fost trimisa la sectie. Se va scrie o nota de anulare.</div>`;
  }

  html += `<div class="mv-field-label">Cantitate de anulat (max ${formatQtyDec(a.ramas != null ? a.ramas : a.cantitate)})</div>`;
  html += `<button class="mv-value-btn" onclick="voidEditQty()">${formatQtyDec(voidState.cantitate)}</button>`;

  if (a.preluat && MOBILE_STATE.parolaStornare === 1) {
    html += `<div class="mv-field-label">Motiv anulare</div>`;
    html += `<input type="text" class="mv-text-input" id="void-motiv" placeholder="Motiv" autocomplete="off">`;
    html += `<div class="mv-field-label">Parola manager</div>`;
    html += `<input type="password" class="mv-text-input" id="void-parola" placeholder="Parola stornare" autocomplete="off" inputmode="numeric">`;
  }

  html += `<button class="mv-primary-btn danger" onclick="voidApply()">${a.storno ? "Revoca anularea" : "Anuleaza"}</button>`;
  body.innerHTML = html;
}

function voidEditQty() {
  const a = voidState.article;
  const max = Number(a.ramas != null ? a.ramas : a.cantitate);
  openNumpad({
    title: "Cantitate de anulat",
    initial: formatQtyDec(voidState.cantitate || 0),
    decimals: MOBILE_STATE.nrZecCant,
    onSubmit: (val) => {
      let q = parseNum(val);
      if (q <= 0) { showToast("Cantitate invalida"); return; }
      if (q > max) q = max;
      voidState.cantitate = q;
      closeModal("modal-numpad");
      renderVoid();
    }
  });
}

async function voidApply() {
  const a = voidState.article;
  if (!a) return;

  const payload = {
    nrMasa: MOBILE_STATE.masaCurenta,
    ecrId: a.ecrId,
    cantitate: voidState.cantitate
  };

  if (a.preluat && !a.storno && MOBILE_STATE.parolaStornare === 1) {
    const motivEl = document.getElementById("void-motiv");
    const parolaEl = document.getElementById("void-parola");
    payload.motiv = motivEl ? motivEl.value.trim() : "";
    payload.parola = parolaEl ? parolaEl.value.trim() : "";
    if (!payload.motiv) { showToast("Introduceti motivul anularii"); return; }
    if (!payload.parola) { showToast("Introduceti parola de manager"); return; }
  }

  const res = await mvPost("void_line", payload);
  if (res.status !== "success") { appAlert(res.message); return; }
  closeModal("modal-void");
  showToast(res.message || "Anulat");
  await loadOrder(MOBILE_STATE.masaCurenta);
}

/* -------- TRANSFER -------- */
const transferState = { selected: {}, destMasa: null };

function openTransferModal() {
  closeModal("modal-line");
  const arts = MOBILE_STATE.articole.filter(a => !a.storno);
  if (!arts.length) { showToast("Nota nu are produse de transferat."); return; }
  transferState.selected = {};
  transferState.destMasa = null;
  renderTransfer();
  openModal("modal-transfer");
}

function renderTransfer() {
  const body = document.getElementById("transfer-body");
  if (!body) return;

  const arts = MOBILE_STATE.articole.filter(a => !a.storno);
  let html = `<div class="mv-field-label">Produse de transferat</div>`;

  arts.forEach(a => {
    const checked = transferState.selected[a.ecrId] ? " checked" : "";
    html += `<label class="mv-transfer-item">
      <input type="checkbox" ${checked} onchange="toggleTransferItem(${a.ecrId}, this.checked)">
      <span class="mv-ti-name">${escapeHtml(a.denumire)}</span>
      <span class="mv-ti-qty">x${formatQtyDec(a.cantitate)}</span>
    </label>`;
  });

  html += `<div class="mv-transfer-dest">
    <span>Masa destinatie</span>
    <button onclick="transferEditDest()">${transferState.destMasa != null ? transferState.destMasa : "?"}</button>
  </div>`;

  html += `<button class="mv-primary-btn" onclick="execTransfer()">Transfera</button>`;
  body.innerHTML = html;
}

function toggleTransferItem(ecrId, checked) {
  if (checked) transferState.selected[ecrId] = true;
  else delete transferState.selected[ecrId];
}

function transferEditDest() {
  openNumpad({
    title: "Masa destinatie (1-80)",
    initial: transferState.destMasa != null ? String(transferState.destMasa) : "",
    decimal: false,
    onSubmit: (val) => {
      const n = parseInt(val, 10);
      if (isNaN(n) || n < 1 || n > 80) { showToast("Numar masa invalid (1-80)"); return; }
      transferState.destMasa = n;
      closeModal("modal-numpad");
      renderTransfer();
    }
  });
}

async function execTransfer() {
  const ecrIds = Object.keys(transferState.selected).map(Number);
  if (!ecrIds.length) { showToast("Selectati macar un produs."); return; }
  if (transferState.destMasa == null) { showToast("Selectati masa destinatie."); return; }
  if (transferState.destMasa === MOBILE_STATE.masaCurenta) { showToast("Masa destinatie diferita de cea curenta."); return; }

  const res = await mvPost("transfer_products", {
    nrMasa: MOBILE_STATE.masaCurenta,
    nrOp: MOBILE_STATE.waiter.nrOsp,
    destNrMasa: transferState.destMasa,
    ecrIds: ecrIds
  });
  if (res.status !== "success") { appAlert(res.message); return; }
  closeModal("modal-transfer");
  showToast(res.message || "Produse transferate");
  await loadOrder(MOBILE_STATE.masaCurenta);
  await loadTables();
  // Daca sursa a ramas fara produse, nota s-a inchis: revenim la mese.
  if (!MOBILE_STATE.docId) {
    showView("view-tables");
  }
}

/* ==========================================================================
   MARCARE / PARASIRE MASA
   ========================================================================== */
async function actionMarcare() {
  if (isFastFood()) {
    showToast("Mod FastFood: comanda nu se trimite la sectie.");
    return;
  }
  if (!MOBILE_STATE.docId) {
    showToast("Nu exista nota de trimis la sectie.");
    return;
  }
  try {
    const res = await mvPost("print_kitchen", {
      docId: MOBILE_STATE.docId,
      nrMasa: MOBILE_STATE.masaCurenta
    });
    if (res.status !== "success") throw new Error(res.message || "Eroare");
    showToast(res.message || "Comanda trimisa");
    vibrate(20);
    await loadOrder(MOBILE_STATE.masaCurenta);
  } catch (err) {
    appAlert("Eroare: " + err.message);
  }
}

let leavingInProgress = false;

async function leaveTable() {
  if (leavingInProgress) return;
  leavingInProgress = true;
  try {
    if (!isFastFood() && MOBILE_STATE.docId) {
      try {
        const res = await mvPost("print_kitchen", {
          docId: MOBILE_STATE.docId,
          nrMasa: MOBILE_STATE.masaCurenta
        });
        if (res && res.jobs > 0) showToast(res.message || "Comanda trimisa la sectie");
      } catch (e) { /* ignoram eroarea de retea la iesire */ }
    }
    if (MOBILE_STATE.modLogare === 0) logoutWaiter();
    closeNota();
    await loadTables();
    showView("view-tables");
  } finally {
    leavingInProgress = false;
  }
}

function mvBack() {
  if (MOBILE_STATE.currentView === "view-tables") return;
  leaveTable();
}

async function openTablesView() {
  closeNota();
  await loadTables();
  showView("view-tables");
}

/* ==========================================================================
   NAVIGARE INTRE ECRANE
   ========================================================================== */
function showView(id) {
  MOBILE_STATE.currentView = id;
  document.querySelectorAll(".mview").forEach(v => v.classList.toggle("active", v.id === id));

  const title = document.getElementById("mv-header-title");
  const back = document.getElementById("mv-back-btn");
  const search = document.getElementById("mv-search-btn");

  // Numarul mesei apare deja in bara de deasupra notei; in header lasam loc liber
  // pentru numele ospatarului.
  if (title) title.textContent = (id === "view-tables") ? "Mese" : "";
  if (back) back.style.visibility = (id === "view-tables") ? "hidden" : "visible";
  if (search) search.style.display = (id === "view-mark") ? "" : "none";

  if (id === "view-tables") renderTables();
}

/* ==========================================================================
   CONFIRM / ALERT (in-app)
   ========================================================================== */
function appConfirm(message, onYes, yesLabel, noLabel) {
  const msg = document.getElementById("confirm-message");
  const yes = document.getElementById("confirm-yes");
  const no = document.getElementById("confirm-no");
  if (!msg || !yes || !no) {
    if (window.confirm(message) && onYes) onYes();
    return;
  }
  msg.textContent = message || "";
  yes.textContent = yesLabel || "Da";
  no.textContent = noLabel || "Nu";
  yes.onclick = () => {
    closeAppConfirm();
    if (onYes) onYes();
  };
  openModal("modal-confirm");
}

function closeAppConfirm() {
  closeModal("modal-confirm");
}

function appAlert(message, onOk) {
  const msg = document.getElementById("alert-message");
  if (!msg) {
    window.alert(message);
    if (onOk) onOk();
    return;
  }
  msg.textContent = message || "";
  const yes = document.querySelector("#modal-alert .mv-dialog-yes");
  if (yes) {
    yes.onclick = () => {
      closeAppAlert();
      if (onOk) onOk();
    };
  }
  openModal("modal-alert");
}

function closeAppAlert() {
  closeModal("modal-alert");
}
