"use strict";
/**
 * FAZA JS BANCAR - fluxul de client al platii pe card (js/pos.js real).
 *
 * Incarca `js/pos.js` (fisierul de productie, nu o copie) intr-un sandbox Node
 * cu DOM stubuit si `fetch` controlat, apoi verifica exact comportamentul cerut
 * de UX:
 *   - UNKNOWN/TIMEOUT      -> verificare AUTOMATA (ecran blocat, fara dialog,
 *                             fara click-uri), apoi dialog doar cu rezultatul
 *   - gasita aprobata      -> preluata silentios, nota se inchide (zero click-uri)
 *   - negasita             -> "PLATA NEPROCESATA" + Reincearca / Alta metoda
 *   - terminal mut         -> "PLATA NECLARIFICATA" + Da/Nu pe chitanta
 *   - refuz normal         -> "PLATA REFUZATA" + Reincearca / Alta metoda
 *   - fara dubla taxare    -> un singur `pay` la terminal
 *
 * Nu foloseste baza de date si nu are nevoie de retea: totul e simulat.
 * Iese cu cod 1 daca exista verificari esuate.
 *
 * Rulare directa:
 *   node tests/js_bankpos_client.js [calea/catre/js/pos.js]
 */

const fs = require("fs");
const path = require("path");
const vm = require("vm");

const POSJS = process.argv[2] || path.join(__dirname, "..", "js", "pos.js");

let pass = 0;
let fail = 0;

/** Simuleaza ecranul activ (navigateToScreen verifica `.pos-screen.active`). */
function setActiveScreen(s, id) {
  const el = s.__getEl(id);
  const orig = s.document.querySelector;
  s.document.querySelector = (sel) => (String(sel).indexOf(".pos-screen.active") !== -1 ? el : orig(sel));
  return el;
}

/** O linie per verificare: PASS|nume|detaliu (citita de tests/js_bankpos.php). */
function out(ok, name, detail) {
  if (ok) { pass++; } else { fail++; }
  console.log((ok ? "PASS" : "FAIL") + "|" + name + "|" + String(detail === undefined ? "" : detail));
}

function makeEl(id) {
  const classes = new Set();
  return {
    id,
    classes,
    style: {},
    dataset: {},
    innerText: "",
    textContent: "",
    innerHTML: "",
    value: "",
    disabled: false,
    checked: false,
    onclick: null,
    classList: {
      add(c) { classes.add(c); },
      remove(c) { classes.delete(c); },
      contains(c) { return classes.has(c); },
      toggle(c, on) {
        if (on === undefined ? !classes.has(c) : on) { classes.add(c); } else { classes.delete(c); }
      }
    },
    addEventListener() {},
    removeEventListener() {},
    appendChild() {},
    removeChild() {},
    remove() {},
    setAttribute() {},
    getAttribute() { return null; },
    focus() {},
    blur() {},
    click() {},
    querySelector() { return null; },
    querySelectorAll() { return []; },
    closest() { return null; },
    getBoundingClientRect() { return { left: 0, top: 0, width: 0, height: 0 }; },
    offsetWidth: 0,
    offsetHeight: 0,
    children: []
  };
}

/** Context nou (POS_STATE proaspat) cu js/pos.js incarcat. */
function makeSandbox(code) {  const cache = new Map();
  const getEl = id => {
    if (!cache.has(id)) { cache.set(id, makeEl(id)); }
    return cache.get(id);
  };

  const document = {
    getElementById: getEl,
    querySelector() { return null; },
    querySelectorAll() { return []; },
    createElement() { return makeEl("new"); },
    addEventListener() {},
    removeEventListener() {},
    body: makeEl("body"),
    head: makeEl("head"),
    documentElement: makeEl("html"),
    activeElement: null,
    readyState: "complete"
  };

  const sandbox = {
    document,
    console,
    setTimeout,
    clearTimeout,
    setImmediate,
    clearImmediate,
    setInterval: () => 0,
    clearInterval() {},
    navigator: { userAgent: "node" },
    requestAnimationFrame() { return 0; },
    alert() {},
    confirm() { return true; },
    prompt() { return null; }
  };
  // `window` este chiar globalul: js/pos.js foloseste si `window.x`, si `x`.
  sandbox.window = sandbox;
  sandbox.globalThis = sandbox;
  sandbox.localStorage = { getItem() { return null; }, setItem() {}, removeItem() {} };
  sandbox.sessionStorage = { getItem() { return null; }, setItem() {}, removeItem() {} };
  sandbox.location = { href: "http://127.0.0.1/rual/index.php", reload() {}, assign() {} };
  // Ca in productie (js/i18n.js): fara intrare in catalog, placeholderele
  // {nume} sunt totusi completate din parametri.
  sandbox.I18N = {};
  sandbox.trText = (s, params) => {
    if (typeof s !== "string" || s === "") { return s; }
    return s.replace(/\{(\w+)\}/g, (m, k) => (params && params[k] !== undefined) ? String(params[k]) : m);
  };

  vm.createContext(sandbox);
  vm.runInContext(code, sandbox, { filename: "pos.js" });

  sandbox.__getEl = getEl;
  return sandbox;
}

const resp = data => ({ ok: true, json: async () => data });

/** Pregateste un context cu o nota de plata de `total` Lei. */
function setup(code, docId, total) {
  const s = makeSandbox(code);
  s.POS_STATE.docId = docId;
  s.POS_STATE.payments = {};
  s.POS_STATE.paymentForms = [{ FPID: 0, Denumire: "Numerar" }, { FPID: 1, Denumire: "Card" }];
  s.POS_STATE.loggedIn = true;
  s.POS_STATE.casierCurent = "TEST";
  s.__getEl("val-total").innerText = Number(total).toFixed(2);

  s.__calls = [];
  s.__bodies = [];
  s.__dialogOpened = false;

  const modal = s.__getEl("modal-pos-banca");
  const origAdd = modal.classList.add.bind(modal.classList);
  modal.classList.add = c => {
    if (c === "active") { s.__dialogOpened = true; }
    origAdd(c);
  };
  return s;
}

/** Inlocuieste `fetch` cu un router pe `action` (sau pe ultimul segment de URL). */
function wireFetch(s, handlers) {
  s.fetch = async (url, opts) => {
    let body = {};
    try { body = JSON.parse((opts && opts.body) || "{}"); } catch (e) { body = {}; }
    const action = body.action
      || String(url || "").split("?")[0].split("/").pop()
      || "?";
    s.__calls.push(action);
    s.__bodies.push(body);
    const h = handlers[action];
    if (!h) {
      // Endpoint-uri auxiliare (get_tables, fp, print_queue...): raspuns neutru,
      // ca inchiderea notei sa poata rula pana la capat in teste.
      return resp({ status: "success", tables: [], rows: [], print: [], pending: 0, failed: 0 });
    }
    return resp(typeof h === "function" ? h(body) : h);
  };
}

/** Asteapta ca dialogul sa-si lege butoanele (apare dupa resync). */
async function waitFor(fn, tries) {
  for (let i = 0; i < (tries || 200); i++) {
    if (fn()) { return true; }
    await new Promise(r => setTimeout(r, 10));
  }
  return false;
}

const PAY_UNKNOWN = {
  status: "success",
  emiteBon: false,
  posStatus: "UNKNOWN",
  posMessage: "Stare necunoscuta: terminalul a acceptat comanda, dar nu a trimis raspuns.",
  trxId: "TX20261101015629",
  amount: 83,
  protocol: "SmartPayECR",
  canResync: true,
  canVoid: true
};

const STATUS_UNCERTAIN = {
  status: "success",
  uncertain: true,
  amount: "83.00",
  uid: "TX20261101015629",
  protocol: "SmartPayECR",
  canResync: true,
  canVoid: true
};

const RESYNC_FOUND = {
  status: "success",
  posStatus: "OK",
  found: true,
  approved: true,
  amount: "83.00",
  uid: "TX20261101015629",
  stan: "000031",
  rrn: "576659823373",
  protocol: "SmartPayECR",
  canResync: true,
  canVoid: true
};

function actionsOf(s) { return JSON.stringify(s.__calls); }

(async () => {
  if (!fs.existsSync(POSJS)) {
    out(false, "js/pos.js este disponibil", POSJS);
    console.log("--- Faza JS bancar: " + pass + " pass, " + fail + " fail ---");
    process.exit(1);
  }
  const CODE = fs.readFileSync(POSJS, "utf8") +
    "\n;try { globalThis.POS_STATE = POS_STATE; } catch (e) {}";

  /* ------------------------------------------------------------------ */
  /* S1: timeout -> verificare automata -> gasita aprobata (zero click)  */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5001, 83);
    wireFetch(s, { pay: PAY_UNKNOWN, status: STATUS_UNCERTAIN, resync: RESYNC_FOUND, resolve: { status: "success" } });
    const r = await s.bankPosChargeCard(83);
    const ok = (r && r.ok === true);

    out(ok === true, "S1 timeout + gasita in jurnal -> plata aprobata (zero click-uri)", JSON.stringify(r));
    out(s.__calls.filter(a => a === "pay").length === 1, "S1 un singur 'pay' trimis la terminal", actionsOf(s));
    out(actionsOf(s) === JSON.stringify(["pay", "status", "resync", "resolve"]),
      "S1 ordinea: pay -> status -> resync -> resolve", actionsOf(s));
    out(s.__dialogOpened === false, "S1 verificarea automata nu deschide niciun dialog", "");
    out(Number(r && r.approved) === 83 && r.source === "journal",
      "S1 aprobarea este preluata silentios din jurnal", JSON.stringify(r));
    out((s.__bodies.filter(b => b.action === "resolve").pop() || {}).actiune === "aprobat",
      "S1 decizia se scrie in audit ca 'aprobat'", "");
    out(/Se verifica statusul platii in terminal/.test(s.__getEl("pos-banca-wait-text").textContent),
      "S1 indicatorul de asteptare anunta verificarea", s.__getEl("pos-banca-wait-text").textContent);
    out(s.__getEl("modal-pos-banca-wait").classList.contains("active") === false,
      "S1 indicatorul de asteptare este inchis la final", "");
    out(s.__calls.indexOf("void") === -1, "S1 nu se ofera void cand plata e gasita aprobata", actionsOf(s));
    // Funnel-ul NU atinge nota: ledger-ul rămâne gol pana cand apelantul il
    // alimenteaza (asta garanteaza ca o eroare de apelant nu poate taxa dublu).
    out(s.cardLedgerTotal() === 0, "S1 funnel-ul nu scrie in nota (ledger gol)", String(s.cardLedgerTotal()));
  }

  /* ------------------------------------------------------------------ */
  /* S2: timeout -> tranzactie inexistenta (card nedebitat)              */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5002, 83);
    wireFetch(s, {
      pay: PAY_UNKNOWN,
      status: STATUS_UNCERTAIN,
      resync: {
        status: "success", posStatus: "OK", found: false, approved: false,
        uid: "TX20261101015629", protocol: "SmartPayECR", canResync: true
      },
      resolve: { status: "success" }
    });
    const p = s.bankPosChargeCard(83);
    await waitFor(() => !!s.__getEl("pos-banca-cancel").onclick);

    out(s.__dialogOpened === true, "S2 dupa verificare apare dialogul cu rezultatul", "");
    out(s.__getEl("pos-banca-title").textContent === "PLATA NEPROCESATA",
      "S2 titlu 'PLATA NEPROCESATA'", s.__getEl("pos-banca-title").textContent);
    out(/NU a fost debitat/.test(s.__getEl("pos-banca-message").textContent),
      "S2 mesajul spune clar ca cardul nu a fost debitat", s.__getEl("pos-banca-message").textContent);
    out(!/jurnal|UNKNOWN|Checking the terminal/i.test(s.__getEl("pos-banca-message").textContent),
      "S2 fara termeni tehnici / istoric de actiuni in corp", s.__getEl("pos-banca-message").textContent);
    out(s.__getEl("pos-banca-retry").disabled === false
      && s.__getEl("pos-banca-retry").style.display === "",
      "S2 'Reincearca plata' este activ si vizibil (cardul NU a fost debitat)", "");
    out(s.__getEl("pos-banca-manual").style.display === "none",
      "S2 'Da, am chitanța' este ascuns (nu e nevoie)", String(s.__getEl("pos-banca-manual").style.display));
    out(s.__getEl("pos-banca-cancel").textContent === "Altă metodă / Anulează",
      "S2 pe card nedebitat butonul rămâne 'Altă metodă / Anulează'", s.__getEl("pos-banca-cancel").textContent);
    out(/^TRX_ID: TX20261101015629$/.test(s.__getEl("pos-banca-trx").textContent),
      "S2 TRX_ID afisat discret, in colt (identificatorul incercarii)", s.__getEl("pos-banca-trx").textContent);

    s.__getEl("pos-banca-cancel").onclick();
    const ok = await p;
    out(ok && ok.ok === false, "S2 'Alta metoda / Anuleaza' -> nota rămâne deschisa", JSON.stringify(ok));
    out((s.__bodies.filter(b => b.action === "resolve").pop() || {}).actiune === "dialog_inchis",
      "S2 audit: dialogul inchis fara decizie", "");
    out(s.__calls.filter(a => a === "pay").length === 1, "S2 niciun al doilea 'pay'", actionsOf(s));
  }

  /* ------------------------------------------------------------------ */
  /* S3: terminal mut (resync fara raspuns)                              */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5003, 120);
    wireFetch(s, {
      pay: Object.assign({}, PAY_UNKNOWN, { amount: 120 }),
      status: Object.assign({}, STATUS_UNCERTAIN, { amount: "120.00" }),
      resync: {
        status: "error", posStatus: "ERROR", message: "Terminalul bancar nu raspunde.",
        connection: { transport: "tcp", host: "127.0.0.1", port: "5070" },
        protocol: "SmartPayECR", canResync: true
      },
      resolve: { status: "success" }
    });
    const p = s.bankPosChargeCard(120);
    await waitFor(() => !!s.__getEl("pos-banca-cancel").onclick);

    out(s.__getEl("pos-banca-title").textContent === "PLATA NECLARIFICATA",
      "S3 titlu 'PLATA NECLARIFICATA' (terminal mut)", s.__getEl("pos-banca-title").textContent);
    out(/cu APROBAT/.test(s.__getEl("pos-banca-message").textContent),
      "S3 mesajul este intrebarea despre chitanta cu APROBAT", s.__getEl("pos-banca-message").textContent);
    out(s.__getEl("pos-banca-cancel").textContent === "Nu a tipărit (Anulează)",
      "S3 butonul alb raspunde 'Nu a tipărit (Anulează)'", s.__getEl("pos-banca-cancel").textContent);
    out(s.__getEl("pos-banca-manual").textContent === "Da, am chitanța (Manual)",
      "S3 butonul galben raspunde 'Da, am chitanța (Manual)'", s.__getEl("pos-banca-manual").textContent);
    out(s.__getEl("pos-banca-retry").disabled === true
      && s.__getEl("pos-banca-retry").style.display === "none",
      "S3 'Reincearca plata' este ascuns complet (risc de dubla taxare, nu aglomereaza dialogul)", "");
    out(s.__getEl("pos-banca-manual").style.display === "",
      "S3 'Solutionare manuala' este disponibil", String(s.__getEl("pos-banca-manual").style.display));

    s.__getEl("pos-banca-cancel").onclick();
    const ok = await p;
    out(ok && ok.ok === false, "S3 anulare -> nota rămâne deschisa", JSON.stringify(ok));
    out(s.__calls.indexOf("void") === -1, "S3 nu se ofera void", actionsOf(s));
    const body3 = s.__bodies.filter(b => b.action === "resolve").pop() || {};
    out(body3.actiune === "neaprobat",
      "S3 'Nu a tipărit' -> incercarea este abandonata (actiune 'neaprobat')", JSON.stringify(body3));
    out(body3.trxId === "TX20261101015629",
      "S3 decizia trimite TRX_ID-ul incercarii (randul din audit)", String(body3.trxId));
    out(s.POS_STATE.bankPosLocked === false,
      "S3 nota este deblocata local dupa decizie", String(s.POS_STATE.bankPosLocked));
  }

  /* ------------------------------------------------------------------ */
  /* S4: refuz normal al cardului (nu e incert)                          */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5004, 50);
    wireFetch(s, {
      pay: {
        status: "success", emiteBon: false, posStatus: "ERROR",
        posMessage: "Tranzactie respinsa.", trxId: "TX4",
        protocol: "SmartPayECR", canResync: true
      },
      status: { status: "success", uncertain: false, protocol: "SmartPayECR", canResync: true },
      resolve: { status: "success" }
    });
    const p = s.bankPosChargeCard(50);
    await waitFor(() => !!s.__getEl("pos-banca-cancel").onclick);

    out(s.__getEl("pos-banca-title").textContent === "PLATA REFUZATA",
      "S4 titlu 'PLATA REFUZATA'", s.__getEl("pos-banca-title").textContent);
    out(s.__calls.indexOf("resync") === -1, "S4 refuz normal -> fara resync automat", actionsOf(s));
    out(s.__getEl("pos-banca-retry").disabled === false, "S4 'Reincearca plata' este activ", "");

    s.__getEl("pos-banca-cancel").onclick();
    const ok = await p;
    out(ok && ok.ok === false, "S4 anulare -> nota rămâne deschisa", JSON.stringify(ok));
    out((s.__bodies.filter(b => b.action === "resolve").pop() || {}).actiune === "anulat",
      "S4 audit: refuz consemnat ca 'anulat'", "");
  }

  /* ------------------------------------------------------------------ */
  /* S5: 'Reincearca plata' -> un singur retry, apoi aprobare            */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5005, 30);
    let pays = 0;
    wireFetch(s, {
      pay: () => {
        pays++;
        if (pays === 1) { return Object.assign({}, PAY_UNKNOWN, { amount: 30, trxId: "TX5A" }); }
        return {
          status: "success", emiteBon: true, posStatus: "OK", amount: 30,
          trxId: "TX5B", protocol: "SmartPayECR", canResync: true
        };
      },
      status: () => (pays === 1
        ? Object.assign({}, STATUS_UNCERTAIN, { amount: "30.00", uid: "TX5A" })
        : { status: "success", uncertain: false, protocol: "SmartPayECR", canResync: true }),
      resync: {
        status: "success", posStatus: "OK", found: false, approved: false,
        uid: "TX5A", protocol: "SmartPayECR", canResync: true
      },
      resolve: { status: "success" }
    });
    const p = s.bankPosChargeCard(30);
    await waitFor(() => !!s.__getEl("pos-banca-retry").onclick);
    s.__getEl("pos-banca-retry").onclick();
    const r = await p;

    out(r && r.ok === true, "S5 dupa 'Reincearca plata' plata e aprobata", JSON.stringify(r));
    out(s.__calls.filter(a => a === "pay").length === 2,
      "S5 exact doua 'pay' (initial + un retry)", actionsOf(s));
    out(Number(r && r.approved) === 30, "S5 suma aprobata este raportata",
      String(r && r.approved));
  }

  /* ------------------------------------------------------------------ */
  /* S7: aprobare partiala -> se aplica cat s-a aprobat, fara dublare    */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5007, 83);
    wireFetch(s, {
      pay: PAY_UNKNOWN,
      status: STATUS_UNCERTAIN,
      resync: Object.assign({}, RESYNC_FOUND, { amount: "50.00" }),
      resolve: { status: "success" }
    });
    const r = await s.bankPosChargeCard(83);

    out(r && r.ok === true, "S7 aprobare partiala -> plata e confirmata pentru suma aprobata", JSON.stringify(r));
    out(Number(r && r.approved) === 50, "S7 se raporteaza doar suma aprobata", String(r && r.approved));
    out(Number(r && r.requested) === 83, "S7 suma ceruta rămâne disponibila pentru rest",
      String(r && r.requested));
    out(s.__calls.indexOf("pay") === s.__calls.lastIndexOf("pay"), "S7 fara dubla taxare la partial",
      actionsOf(s));
  }

  /* ------------------------------------------------------------------ */
  /* S8: aprobare directa (fara UNKNOWN) -> un singur pay               */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5008, 25);
    wireFetch(s, {
      pay: {
        status: "success", emiteBon: true, posStatus: "OK", amount: 25,
        trxId: "TX8", protocol: "SmartPayECR", canResync: true
      }
    });
    const r = await s.bankPosChargeCard(25);

    out(r && r.ok === true, "S8 aprobare directa -> plata confirmata", JSON.stringify(r));
    out(actionsOf(s) === JSON.stringify(["pay"]), "S8 fara status/resync cand e aprobat direct", actionsOf(s));
    out(Number(r && r.approved) === 25 && r.source === "terminal",
      "S8 suma aprobata raportata de funnel", JSON.stringify(r));
  }

  /* ------------------------------------------------------------------ */
  /* S9: solutionare manuala - AUTHCODE (6 caractere) obligatoriu       */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5009, 83);
    wireFetch(s, {
      resolve_manual: { status: "success", verdict: "aprobat", amount: "83.00", protocol: "SmartPayECR", canResync: true }
    });

    // AUTHCODE: exact 6 caractere alfanumerice (A-Z/0-9), fara alte simboluri.
    out(s.bankPosAuthCodeValid("AB12CD") === true
      && s.bankPosAuthCodeValid("ab12cd") === true
      && s.bankPosAuthCodeValid("123456") === true
      && s.bankPosAuthCodeValid("A1B2C3") === true
      && s.bankPosAuthCodeValid("AB12C") === false
      && s.bankPosAuthCodeValid("AB12CDE") === false
      && s.bankPosAuthCodeValid("AB-12C") === false
      && s.bankPosAuthCodeValid("123456789012") === false
      && s.bankPosAuthCodeValid("") === false,
      "S9 AUTHCODE este valid doar cu exact 6 caractere A-Z/0-9");

    // Filtrarea tastarii: majuscule, doar A-Z/0-9, maxim 6 caractere.
    out(s.bankPosAuthCodeFilter("ab-12_cd3") === "AB12CD"
      && s.bankPosAuthCodeFilter("1234567") === "123456",
      "S9 tastarea este filtrata (majuscule, A-Z/0-9, maxim 6)");

    const p = s.openBankPosManualModal({
      amount: 83, sursa: "test", canResync: true,
      connection: { transport: "tcp", host: "127.0.0.1", port: "5070" }
    });
    const html = s.__getEl("pos-banca-manual-info").innerHTML;
    out(/pos-banca-man-suma/.test(html) && /Suma: 83\.00/.test(html),
      "S9 suma este afisata evidentiat (clasa dedicata)", html);
    out(!/Soluționare manuală a plății pe card/.test(html),
      "S9 linia care repeta titlul a fost scoasa", html);
    out(!/Conexiune|127\.0\.0\.1|5070/.test(html),
      "S9 fara detalii tehnice (IP/conexiune) pe ecranul secundar", html);
    out(s.__getEl("pos-banca-manual-aprobat").disabled === true,
      "S9 'Aprobat' porneste dezactivat", "");

    // Fara cod: aprobarea nu pleaca la server si se deschide tastatura alfanumerica.
    s.__getEl("pos-banca-manual-aprobat").onclick();
    await new Promise(r => setTimeout(r, 30));
    out(s.__calls.indexOf("resolve_manual") === -1,
      "S9 'Aprobat' fara cod NU trimite decizia la server", actionsOf(s));
    out(s.__getEl("modal-keyboard").classList.contains("active") === true,
      "S9 fara cod se deschide tastatura alfanumerica", "");

    // Tastarea: majuscule, doar A-Z/0-9; dupa 6 caractere tastatura se inchide.
    "ab-12_cd7".split("").forEach(ch => s.vkeyInput(ch));
    out(s.__getEl("pos-banca-manual-cod").value === "AB12CD",
      "S9 codul este filtrat si afisat cu majuscule", s.__getEl("pos-banca-manual-cod").value);
    out(s.__getEl("pos-banca-manual-aprobat").disabled === false,
      "S9 6 caractere deblocheaza 'Aprobat'", "");
    out(s.__getEl("modal-keyboard").classList.contains("active") === false,
      "S9 tastatura se inchide automat la 6 caractere", "");

    // Redeschidere precompletata; Backspace sub 6 caractere dezactiveaza Aprobat.
    s.bankPosManualCaptureCode();
    out(s.__getEl("virtual-keyboard-input").value === "AB12CD",
      "S9 tastatura redeschisa este precompletata cu codul", s.__getEl("virtual-keyboard-input").value);
    s.vkeyBackspace();
    out(s.__getEl("pos-banca-manual-cod").value === "AB12C"
      && s.__getEl("pos-banca-manual-aprobat").disabled === true,
      "S9 Backspace sub 6 caractere dezactiveaza 'Aprobat'", "");
    s.vkeyInput("d");
    out(s.__getEl("pos-banca-manual-cod").value === "AB12CD",
      "S9 retastarea completeaza codul", s.__getEl("pos-banca-manual-cod").value);

    s.__getEl("pos-banca-manual-aprobat").onclick();
    const res = await p;
    const body = s.__bodies.filter(b => b.action === "resolve_manual").pop() || {};
    out(res && res.verdict === "aprobat", "S9 cu cod valid aprobarea este trimisa", JSON.stringify(res && res.verdict));
    out(body.cod === "AB12CD", "S9 AUTHCODE-ul pleaca la server", JSON.stringify(body.cod));
  }

  /* ------------------------------------------------------------------ */
  /* S10: doar Aprobat / Inapoi (fara "Chitanță Respinsă")              */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5010, 43);
    const html = fs.readFileSync(path.join(__dirname, "..", "index.php"), "utf8");
    out(!html.includes('id="pos-banca-manual-neaprobat"')
      && !html.includes('data-i18n="Chitanță Respinsă"'),
      "S10 modalul manual nu mai are butonul 'Chitanță Respinsă'", "");

    const p = s.openBankPosManualModal({ amount: 43, sursa: "test" });
    s.__getEl("pos-banca-manual-cancel").onclick();
    const res = await p;
    out(res === null && s.__calls.indexOf("resolve_manual") === -1,
      "S10 'Înapoi' inchide fara sa trimita vreo decizie", actionsOf(s));
  }

  /* ------------------------------------------------------------------ */
  /* S11: preluare automata la deschiderea ecranului de plata            */
  /* ------------------------------------------------------------------ */
  {
    // a) resync a confirmat si suma acopera exact partea de card -> inchide singur
    const s = setup(CODE, 5011, 83);
    s.POS_STATE.posBanca = 1;
    wireFetch(s, {
      status: {
        status: "success", uncertain: true, resyncApproved: true, amount: "83.00",
        canResync: true, protocol: "SmartPayECR"
      },
      close_bill: {
        status: "success", total: 83,
        print: { nota: 1, fiscal: 2 }, fiscal: { nr: 123, z: 7 }
      }
    });
    const closed = await s.bankPosAutoAdoptOnPaymentScreen();
    const closeBody = s.__bodies.filter(b => b.action === "close_bill").pop() || {};
    out(closed === true, "S11 resync aprobat -> nota se inchide automat", String(closed));
    out(s.__calls.indexOf("pay") === -1, "S11 nu se mai cere cardul la terminal", actionsOf(s));
    out(Number(closeBody.plati && closeBody.plati["1"]) === 83,
      "S11 nota se inchide cu suma aprobata pe card", JSON.stringify(closeBody.plati || null));
    out(s.__dialogOpened === false, "S11 niciun dialog pentru operator", "");

    // b) aprobat doar partial -> aplica partea aprobata, restul ramane de plata
    const s2 = setup(CODE, 5012, 83);
    s2.POS_STATE.posBanca = 1;
    wireFetch(s2, {
      status: {
        status: "success", uncertain: true, resyncApproved: true, amount: "50.00",
        canResync: true, protocol: "SmartPayECR"
      }
    });
    const closed2 = await s2.bankPosAutoAdoptOnPaymentScreen();
    out(closed2 === false, "S11 aprobare partiala -> nota rămâne deschisa", String(closed2));
    out(Number(s2.paymentsForClose()[1]) === 50,
      "S11 se aplica doar suma aprobata", JSON.stringify(s2.paymentsForClose()));
    out(s2.__calls.indexOf("close_bill") === -1, "S11 partial -> fara close_bill", actionsOf(s2));

    // c) fara resync aprobat -> nu se preia nimic
    const s3 = setup(CODE, 5013, 83);
    s3.POS_STATE.posBanca = 1;
    wireFetch(s3, {
      status: { status: "success", uncertain: true, resyncApproved: false, amount: "83.00" }
    });
    const closed3 = await s3.bankPosAutoAdoptOnPaymentScreen();
    out(closed3 === false && s3.__calls.length === 1,
      "S11 fara confirmare din terminal -> nu se preia nimic", actionsOf(s3));

    // d) butonul Manual dispare cand terminalul a confirmat deja plata
    const s4 = setup(CODE, 5014, 83);
    wireFetch(s4, {
      status: {
        status: "success", uncertain: true, resyncApproved: true, amount: "83.00",
        canResync: true, protocol: "SmartPayECR"
      }
    });
    await s4.refreshBankPosCheckButton();
    out(s4.__getEl("btn-pos-banca-manual").style.display === "none",
      "S11 'Solutionare manuala' ascuns cand plata e confirmata electronic",
      String(s4.__getEl("btn-pos-banca-manual").style.display));
    out(s4.__getEl("btn-pos-banca-check").style.display === "none",
      "S11 'Verifica' ascuns dupa confirmarea electronica",
      String(s4.__getEl("btn-pos-banca-check").style.display));
  }

  /* ------------------------------------------------------------------ */
  /* S12: terminalul nu raspunde (comanda nu a plecat: login fara ACK /   */
  /* conexiune refuzata) -> nu e "plata neclarificata", e eroare de legatura */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5015, 17);
    wireFetch(s, {
      pay: {
        status: "success", emiteBon: false, posStatus: "ERROR", noAnswer: true,
        posMessage: "Nu s-a putut conecta la terminalul bancar (POS).",
        trxId: "TX20261101134247", protocol: "SmartPayECR", canResync: true, canVoid: true
      },
      status: { status: "success", uncertain: false, protocol: "SmartPayECR", canResync: true },
      resolve: { status: "success" }
    });
    const p = s.bankPosChargeCard(17);
    await waitFor(() => !!s.__getEl("pos-banca-cancel").onclick);

    const msg = s.__getEl("pos-banca-message").textContent;
    out(s.__getEl("pos-banca-title").textContent === "TERMINALUL NU RASPUNDE",
      "S12 titlu 'TERMINALUL NU RASPUNDE' (nu PLATA NECLARIFICATA)",
      s.__getEl("pos-banca-title").textContent);
    out(/nu răspunde/.test(msg) && !/APROBAT|chitanț/.test(msg),
      "S12 mesajul spune ca terminalul nu raspunde, fara intrebarea despre chitanta", msg);
    out(s.__getEl("pos-banca-manual").style.display === "none",
      "S12 fara 'Da, am chitanța' (nu exista chitanta, comanda nu a plecat)",
      String(s.__getEl("pos-banca-manual").style.display));
    out(s.__getEl("pos-banca-retry").disabled === false
      && s.__getEl("pos-banca-retry").style.display === "",
      "S12 'Reincearca plata' este oferit (nimic nu a fost debitat)",
      String(s.__getEl("pos-banca-retry").style.display));
    out(s.__calls.indexOf("resync") === -1,
      "S12 fara resync (nu exista tranzactie de verificat)", actionsOf(s));
    out(s.__calls.filter(a => a === "pay").length === 1,
      "S12 un singur 'pay' la terminal", actionsOf(s));

    s.__getEl("pos-banca-cancel").onclick();
    const ok = await p;
    out(ok && ok.ok === false, "S12 anulare -> nota rămâne deschisa (nedebitata)", JSON.stringify(ok));
    out((s.__bodies.filter(b => b.action === "resolve").pop() || {}).actiune === "anulat",
      "S12 decizia se consemneaza in audit", "");
    out(s.POS_STATE.bankPosLocked === false, "S12 nota nu rămâne blocata", String(s.POS_STATE.bankPosLocked));
  }

  /* ------------------------------------------------------------------ */
  /* S13: bannerul de blocare arata TRX_ID-ul incercarii (pentru jurnal)  */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5016, 73);
    s.POS_STATE.bankPosLocked = true;
    s.POS_STATE.bankPosUncertain = true;
    s.POS_STATE.bankPosLockAmount = "73.00";
    s.POS_STATE.bankPosUid = "TX20261101153210";
    s.applyBankPosLockUI();

    out(s.__getEl("bank-lock-banner").style.display === "flex",
      "S13 bannerul de blocare este vizibil", String(s.__getEl("bank-lock-banner").style.display));
    // Mesajul trimite la actiunile din bara de jos (nu la vechiul ecran de plata).
    s.POS_STATE.articole = [{ ecrId: 1 }];
    const lockMsg = s.bankPosLockMessage();
    out(/bara de jos/.test(lockMsg) && !/ecranul de plat/i.test(lockMsg),
      "S13 mesajul trimite la butoanele din bara de jos", lockMsg);
    s.POS_STATE.articole = [];
    out(/apăsați NOTA/.test(s.bankPosLockMessage()),
      "S13 nota goala: mesajul trimite la NOTA (inchidere pe zero)", s.bankPosLockMessage());
    out(s.__getEl("bank-lock-trx").textContent === "TRX: TX20261101153210",
      "S13 bannerul arata TRX_ID-ul incercarii (pentru jurnalul terminalului)",
      s.__getEl("bank-lock-trx").textContent);

    // Dupa deblocare (decizia operatorului) TRX-ul dispare.
    s.bankPosUnlockLocal();
    out(s.__getEl("bank-lock-trx").textContent === "",
      "S13 dupa deblocare TRX-ul dispare din banner", s.__getEl("bank-lock-trx").textContent);
    out(s.__getEl("bank-lock-banner").style.display === "none",
      "S13 dupa deblocare bannerul se ascunde", String(s.__getEl("bank-lock-banner").style.display));
  }

  /* ------------------------------------------------------------------ */
  /* S14: bara de marcare pe nota blocata (Verifica / Alta forma / Manual) */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5017, 73);
    s.POS_STATE.articole = [{ ecrId: 1, cantitate: 1, valoare: 73 }];
    s.POS_STATE.bankPosLocked = true;
    s.POS_STATE.bankPosUncertain = true;
    s.POS_STATE.bankPosLockAmount = "73.00";
    s.POS_STATE.bankPosUid = "TX20261101190200";
    s.POS_STATE.bankPosCanResync = true;
    s.applyBankPosLockUI();

    out(s.__getEl("screen-marcare").classList.contains("bank-locked") === true,
      "S14 nota blocata: bara trece in modul de clarificare", "");
    out(s.__getEl("btn-bank-verify").style.display === "",
      "S14 'Verifica' este disponibil cand protocolul are jurnal",
      String(s.__getEl("btn-bank-verify").style.display));

    // Un driver (viitor) fara jurnal nu are ce verifica -> Verifica ascuns.
    s.POS_STATE.bankPosCanResync = false;
    s.applyBankPosLockUI();
    out(s.__getEl("btn-bank-verify").style.display === "none",
      "S14 'Verifica' ascuns pe un driver fara jurnal",
      String(s.__getEl("btn-bank-verify").style.display));
    s.POS_STATE.bankPosCanResync = true;
    s.applyBankPosLockUI();

    // "Alta forma de plata / Anuleaza": decizia se scrie in audit si nota se deblocheaza.
    let resolved14 = false;
    wireFetch(s, {
      status: () => ({
        status: "success", uncertain: !resolved14, amount: "73.00", uid: "TX20261101190200",
        approvedTotal: 0, protocol: "SmartPayECR", canResync: true
      }),
      resolve: () => { resolved14 = true; return { status: "success" }; }
    });
    await s.actionBankPosReturnToPay();
    const body = s.__bodies.filter(b => b.action === "resolve").pop() || {};
    out(body.actiune === "neaprobat" && body.trxId === "TX20261101190200",
      "S14 'Alta forma de plata' consemneaza abandonarea incercarii (TRX_ID)",
      JSON.stringify(body));
    out(s.POS_STATE.bankPosLocked === false,
      "S14 'Alta forma de plata' deblocheaza nota", String(s.POS_STATE.bankPosLocked));
    out(s.__getEl("screen-marcare").classList.contains("bank-locked") === false,
      "S14 dupa deblocare bara revine la butoanele normale", "");
  }

  /* ------------------------------------------------------------------ */
  /* S15: nota goala si blocata -> NOTA rămâne vizibil (inchidere pe zero) */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5018, 0);
    s.POS_STATE.articole = [];
    s.POS_STATE.bankPosLocked = true;
    s.applyBankPosLockUI();
    out(s.__getEl("screen-marcare").classList.contains("bank-locked-empty") === true,
      "S15 nota goala blocata: NOTA rămâne disponibil", "");
    s.POS_STATE.articole = [{ ecrId: 1 }];
    s.applyBankPosLockUI();
    out(s.__getEl("screen-marcare").classList.contains("bank-locked-empty") === false,
      "S15 nota cu produse: NOTA se ascunde", "");
  }

  /* ------------------------------------------------------------------ */
  /* S16: dialogul de inchidere afiseaza denumirea formei de plata        */
  /* (CARD), nu codul intern (FP 1) - indiferent de tipul FPID din API.   */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5100, 73);
    // API-ul poate da FPID ca text: comparatia trebuie sa fie toleranta.
    s.POS_STATE.paymentForms = [{ FPID: "1", Denumire: "CARD", Status: 1, Poz: "1" }];
    out(s.paymentFormLabel(1) === "CARD",
      "S16 eticheta formei de plata este denumirea (CARD)", s.paymentFormLabel(1));
    out(s.paymentFormLabel("1") === "CARD",
      "S16 eticheta functioneaza si cu FPID text", s.paymentFormLabel("1"));

    // Fara denumire in tblFP, rămâne codul intern.
    s.POS_STATE.paymentForms = [{ FPID: 1, Denumire: "", Status: 1, Poz: 1 }];
    out(s.paymentFormLabel(1) === "FP 1",
      "S16 fara denumire se afiseaza 'FP <id>'", s.paymentFormLabel(1));

    s.POS_STATE.paymentForms = [{ FPID: 0, Denumire: "Numerar", Status: 1, Poz: 2 }];
    out(s.paymentFormLabel(0) === "Numerar",
      "S16 forma de plata 0 este 'Numerar'", s.paymentFormLabel(0));
  }

  /* ------------------------------------------------------------------ */
  /* S17: doua swipe-uri aprobate (fluxul nou, per OK pe CARD)           */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5201, 100);
    s.POS_STATE.posBanca = 1;
    s.POS_STATE.plataCombinata = 1;
    s.POS_STATE.activePaymentFp = 1;
    const amounts = [];
    wireFetch(s, {
      pay: (b) => {
        amounts.push(Number(b.amount));
        return {
          status: "success", emiteBon: true, posStatus: "OK", amount: b.amount,
          trxId: "TXS17-" + amounts.length, logId: amounts.length,
          protocol: "SmartPayECR", canResync: true
        };
      },
      status: { status: "success", uncertain: false, protocol: "SmartPayECR", canResync: true },
      close_bill: (b) => {
        // Snapshot inainte de clearOrderPanelLocal (inchiderea goleste ledger-ul).
        s.__ledgerAtClose = s.cardLedgerTotal();
        s.__swipesAtClose = s.POS_STATE.cardLedger.swipes.map(x => x.trxId);
        return { status: "success", total: 100, print: {}, fiscal: { nr: 321, z: 7 } };
      }
    });

    s.__getEl("pay-amount-input").value = "60";
    await s.finalizePaymentTransaction();
    out(amounts.length === 1 && amounts[0] === 60,
      "S17 primul OK pe CARD taxeaza imediat 60", JSON.stringify(amounts));
    out(s.cardLedgerTotal() === 60, "S17 ledger-ul retine primul swipe", String(s.cardLedgerTotal()));
    out(s.__calls.indexOf("close_bill") === -1, "S17 nota NU se inchide dupa primul swipe", actionsOf(s));

    s.__getEl("pay-amount-input").value = "40";
    await s.finalizePaymentTransaction();
    out(amounts.length === 2 && amounts[1] === 40,
      "S17 al doilea OK pe CARD taxeaza doar restul (40)", JSON.stringify(amounts));
    out(s.__ledgerAtClose === 100, "S17 ledger-ul insumeaza cele doua swipe-uri",
      String(s.__ledgerAtClose));
    const pays = s.__bodies.filter(b => b.action === "pay");
    out(pays.length === 2, "S17 doua cereri pay distincte", JSON.stringify(s.__calls));
    out(JSON.stringify(s.__swipesAtClose) === JSON.stringify(["TXS17-1", "TXS17-2"]),
      "S17 fiecare swipe are TRX-ul lui (audit separat)",
      JSON.stringify(s.__swipesAtClose));
    out(s.cardLedgerTotal() === 0 && s.POS_STATE.docId === null,
      "S17 dupa inchidere ledger-ul notei se goleste", String(s.cardLedgerTotal()));
    const close = s.__bodies.filter(b => b.action === "close_bill").pop() || {};
    out(Number(close.plati && close.plati["1"]) === 100,
      "S17 bonul are o singura linie CARD de 100", JSON.stringify(close.plati || null));
    out(s.__calls.filter(a => a === "pay").length === 2,
      "S17 inchiderea nu mai taxeaza a treia oara", actionsOf(s));
    out(s.__calls.filter(a => a === "close_bill").length === 1,
      "S17 o singura inchidere de nota", actionsOf(s));
  }

  /* ------------------------------------------------------------------ */
  /* S18: al doilea swipe refuzat -> nota rămâne cu primul swipe         */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5202, 100);
    s.POS_STATE.posBanca = 1;
    s.POS_STATE.plataCombinata = 1;
    s.POS_STATE.activePaymentFp = 1;
    let pays = 0;
    wireFetch(s, {
      pay: (b) => {
        pays++;
        if (pays === 1) {
          return {
            status: "success", emiteBon: true, posStatus: "OK", amount: b.amount,
            trxId: "TXS18A", logId: 1, protocol: "SmartPayECR", canResync: true
          };
        }
        return {
          status: "success", emiteBon: false, posStatus: "ERROR",
          posMessage: "Tranzactie respinsa.", trxId: "TXS18B",
          protocol: "SmartPayECR", canResync: true
        };
      },
      status: { status: "success", uncertain: false, protocol: "SmartPayECR", canResync: true },
      resolve: { status: "success" },
      close_bill: { status: "success", total: 100 }
    });

    s.__getEl("pay-amount-input").value = "60";
    await s.finalizePaymentTransaction();

    s.__getEl("pay-amount-input").value = "40";
    const p2 = s.finalizePaymentTransaction();
    await waitFor(() => !!s.__getEl("pos-banca-cancel").onclick);
    out(s.__getEl("pos-banca-title").textContent === "PLATA REFUZATA",
      "S18 refuzul celui de-al doilea swipe se vede in dialog",
      s.__getEl("pos-banca-title").textContent);
    s.__getEl("pos-banca-cancel").onclick();
    await p2;

    out(pays === 2, "S18 exact doua incercari la terminal", String(pays));
    out(s.cardLedgerTotal() === 60, "S18 refuzul nu adauga nimic in nota",
      String(s.cardLedgerTotal()));
    out(s.__calls.indexOf("close_bill") === -1, "S18 nota rămâne deschisa", actionsOf(s));
    out(s.paymentsForClose()[1] === 60, "S18 linia de card rămâne 60",
      JSON.stringify(s.paymentsForClose()));
  }

  /* ------------------------------------------------------------------ */
  /* S19: cardLedgerAdd este idempotent (aceeasi incercare, o singura data) */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5203, 100);
    const a = s.cardLedgerAdd({ approved: 60, trxId: "TX-DUP", source: "terminal" });
    const b = s.cardLedgerAdd({ approved: 60, trxId: "TX-DUP", source: "journal" });
    out(a === true && b === false, "S19 al doilea add al aceluiasi TRX este ignorat",
      JSON.stringify([a, b]));
    out(s.cardLedgerTotal() === 60, "S19 suma rămâne 60 (fara dublare)",
      String(s.cardLedgerTotal()));
  }

  /* ------------------------------------------------------------------ */
  /* S20: reînsămânțare din server (reload) + linia de card reapare      */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5204, 100);
    s.POS_STATE.posBanca = 1;
    s.POS_STATE.payments = {};
    s.cardLedgerSeed([
      { trxId: "TXA", amount: 60, source: "terminal", logId: 1 },
      { trxId: "TXB", amount: 40, source: "terminal", logId: 2 }
    ]);
    s.cardLedgerSeed([{ trxId: "TXA", amount: 60, source: "terminal", logId: 1 }]);
    out(s.cardLedgerTotal() === 100, "S20 seed-ul repune ambele swipe-uri (idempotent)",
      String(s.cardLedgerTotal()));
    out(Number(s.paymentsForClose()[1]) === 100,
      "S20 linia de card reapare dupa reload", JSON.stringify(s.paymentsForClose()));

    // Ecranul de plata a fost resetat intre timp: banii tot acolo sunt.
    s.POS_STATE.payments = {};
    s.POS_STATE.rest = 0;
    out(Number(s.paymentsForClose()[1]) === 100,
      "S20 resetarea platilor nu pierde cardul", JSON.stringify(s.paymentsForClose()));
  }

  /* ------------------------------------------------------------------ */
  /* S21: PlataCombinata = 0 -> o singura taxare, la inchidere           */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5205, 73);
    s.POS_STATE.posBanca = 1;
    s.POS_STATE.plataCombinata = 0;
    const amounts = [];
    wireFetch(s, {
      pay: (b) => {
        amounts.push(Number(b.amount));
        return {
          status: "success", emiteBon: true, posStatus: "OK", amount: b.amount,
          trxId: "TXS21", logId: 1, protocol: "SmartPayECR", canResync: true
        };
      },
      status: { status: "success", uncertain: false, protocol: "SmartPayECR", canResync: true },
      close_bill: { status: "success", total: 73, fiscal: { nr: 11, z: 3 } }
    });

    await s.paySingleForm(1);
    out(amounts.length === 1 && amounts[0] === 73,
      "S21 un singur pay, cu tot totalul, la inchidere", JSON.stringify(amounts));
    const close = s.__bodies.filter(b => b.action === "close_bill").pop() || {};
    out(Number(close.plati && close.plati["1"]) === 73,
      "S21 nota se inchide cu cardul integral", JSON.stringify(close.plati || null));
  }

  /* ------------------------------------------------------------------ */
  /* S22: dublu-tap pe OK in timpul taxarii -> un singur pay             */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5206, 30);
    s.POS_STATE.posBanca = 1;
    s.POS_STATE.plataCombinata = 1;
    s.POS_STATE.activePaymentFp = 1;
    let pays = 0;
    const slowPay = () => new Promise(resolve => {
      pays++;
      setTimeout(() => resolve({
        ok: true,
        json: async () => ({
          status: "success", emiteBon: true, posStatus: "OK", amount: 30,
          trxId: "TXS22", logId: 1, protocol: "SmartPayECR", canResync: true
        })
      }), 20);
    });
    s.fetch = async (url, opts) => {
      let body = {};
      try { body = JSON.parse((opts && opts.body) || "{}"); } catch (e) { body = {}; }
      s.__calls.push(body.action || "?");
      s.__bodies.push(body);
      if (body.action === "pay") { return slowPay(); }
      if (body.action === "close_bill") { s.__ledgerAtClose = s.cardLedgerTotal(); }
      return resp({ status: "success", uncertain: false, print: {}, fiscal: { nr: 1, z: 1 } });
    };

    s.__getEl("pay-amount-input").value = "30";
    const p1 = s.finalizePaymentTransaction();
    const p2 = s.finalizePaymentTransaction();
    await Promise.all([p1, p2]);
    out(pays === 1, "S22 dublu-tap in timpul taxarii -> un singur pay", String(pays));
    out(s.__ledgerAtClose === 30, "S22 suma aprobata o singura data", String(s.__ledgerAtClose));
  }

  /* ------------------------------------------------------------------ */
  /* S23: cardul deja incasat nu se mai taxeaza la reincercarea lui OK   */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5207, 60);
    s.POS_STATE.posBanca = 1;
    s.cardLedgerAdd({ approved: 60, trxId: "TXS23", source: "terminal", logId: 1 });
    wireFetch(s, {
      status: { status: "success", uncertain: false, protocol: "SmartPayECR", canResync: true },
      close_bill: { status: "success", total: 60, fiscal: { nr: 9, z: 2 } }
    });

    await s.executeClose(60, 0);
    out(s.__calls.indexOf("pay") === -1,
      "S23 a doua inchidere nu mai taxeaza cardul", actionsOf(s));
    const close = s.__bodies.filter(b => b.action === "close_bill").pop() || {};
    out(Number(close.plati && close.plati["1"]) === 60,
      "S23 nota pleaca cu cardul deja aprobat", JSON.stringify(close.plati || null));
  }

  /* ------------------------------------------------------------------ */
  /* S24: cardul nu acopera nota -> NU se inchide (fara a doua taxare)   */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5210, 100);
    s.POS_STATE.posBanca = 1;
    // Aprobare partiala ramasa dinainte (ex. dupa un reload): 50 din 100.
    s.cardLedgerAdd({ approved: 50, trxId: "TXS24", source: "journal", logId: 1 });
    wireFetch(s, {
      status: { status: "success", uncertain: false, protocol: "SmartPayECR", canResync: true },
      pay: { status: "success", emiteBon: false, posStatus: "ERROR", trxId: "TXS24B" },
      close_bill: { status: "success", total: 100 }
    });

    await s.executeClose(100, 0);
    out(s.__calls.indexOf("pay") === -1,
      "S24 cardul deja aprobat nu se mai taxeaza pentru o nota neacoperita", actionsOf(s));
    out(s.__calls.indexOf("close_bill") === -1,
      "S24 nota neplatita integral NU se inchide", actionsOf(s));
  }

  /* ------------------------------------------------------------------ */
  /* S25: ieșire din plata cu bani pe card -> void + navigare            */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5301, 100);
    s.POS_STATE.posBanca = 1;
    s.cardLedgerAdd({ approved: 60, trxId: "TXS25", source: "terminal", logId: 1 });
    setActiveScreen(s, "screen-plata");
    wireFetch(s, {
      void_doc: {
        status: "success", enabled: true, approvedTotal: 0,
        voided: [{ trxId: "TXS25", amount: "60.00", voided: true }], failed: []
      }
    });

    s.navigateToScreen("screen-marcare");
    await waitFor(() => !!s.__getEl("confirm-yes").onclick);
    out(s.__getEl("modal-confirm").classList.contains("active") === true,
      "S25 ieșirea cu bani pe card cere confirmare", "");
    out(/Ați încasat 60\.00 Lei pe card/.test(s.__getEl("confirm-message").textContent),
      "S25 mesajul de confirmare arata suma incasata",
      s.__getEl("confirm-message").textContent);
    s.__getEl("confirm-yes").onclick();
    await waitFor(() => s.__getEl("screen-marcare").classes.has("active"));

    out(s.__calls.indexOf("void_doc") !== -1, "S25 cardul este anulat la ieșire", actionsOf(s));
    out(s.cardLedgerTotal() === 0, "S25 ledger-ul este golit dupa void", String(s.cardLedgerTotal()));
    out(s.__getEl("screen-marcare").classes.has("active") === true,
      "S25 navigarea continua dupa void", "");
  }

  /* ------------------------------------------------------------------ */
  /* S26: ieșire fara bani pe card -> fara confirmare, fara void          */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5302, 100);
    s.POS_STATE.posBanca = 1;
    s.POS_STATE.payments = { 0: 40 };
    setActiveScreen(s, "screen-plata");
    wireFetch(s, {});
    s.navigateToScreen("screen-marcare");
    await new Promise(r => setTimeout(r, 20));
    out(s.__calls.length === 0, "S26 fara bani pe card nu se cheama nimic pe server", actionsOf(s));
    out(s.__getEl("modal-confirm").classList.contains("active") === false,
      "S26 fara confirmare cand nu s-a incasat nimic", "");
    out(s.__getEl("screen-marcare").classes.has("active") === true,
      "S26 navigarea se face imediat", "");
    out(Object.keys(s.POS_STATE.payments).length === 0,
      "S26 sumele doar tastate se arunca", JSON.stringify(s.POS_STATE.payments));
  }

  /* ------------------------------------------------------------------ */
  /* S27: void eșuat -> ieșire cu avertisment                             */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5303, 100);
    s.POS_STATE.posBanca = 1;
    s.cardLedgerAdd({ approved: 60, trxId: "TXS27", source: "terminal", logId: 1 });
    setActiveScreen(s, "screen-plata");
    wireFetch(s, {
      void_doc: { status: "error", message: "Terminalul nu raspunde", voided: [], failed: [] }
    });
    s.navigateToScreen("screen-marcare");
    await waitFor(() => !!s.__getEl("confirm-yes").onclick);
    s.__getEl("confirm-yes").onclick();
    await waitFor(() => s.__getEl("screen-marcare").classes.has("active"));
    out(s.__calls.indexOf("void_doc") !== -1, "S27 void-ul a fost incercat", actionsOf(s));
    out(s.__getEl("screen-marcare").classes.has("active") === true,
      "S27 ieșirea este permisa si cand void-ul eșueaza", "");
    out(s.__getEl("modal-alert").classList.contains("active") === true,
      "S27 operatorul primeste avertismentul", "");
  }

  /* ------------------------------------------------------------------ */
  /* S28: back in timpul unei taxari (cardPaying) -> ignorat             */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5304, 100);
    s.POS_STATE.posBanca = 1;
    s.POS_STATE.cardPaying = true;
    s.cardLedgerAdd({ approved: 0, trxId: "TX-nimic" });
    setActiveScreen(s, "screen-plata");
    wireFetch(s, {});
    s.navigateToScreen("screen-marcare");
    await new Promise(r => setTimeout(r, 20));
    out(s.__getEl("screen-marcare").classes.has("active") === false,
      "S28 back in timpul taxarii nu schimba ecranul", "");
    out(s.__calls.length === 0, "S28 nimic trimis la server", actionsOf(s));
  }

  /* ------------------------------------------------------------------ */
  /* S29: confirmarea refuzata -> rămâne pe ecranul de plata             */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5305, 100);
    s.POS_STATE.posBanca = 1;
    s.cardLedgerAdd({ approved: 60, trxId: "TXS29", source: "terminal", logId: 1 });
    setActiveScreen(s, "screen-plata");
    wireFetch(s, {});
    s.navigateToScreen("screen-marcare");
    await waitFor(() => !!s.__getEl("confirm-no").onclick);
    s.__getEl("confirm-no").onclick();
    await new Promise(r => setTimeout(r, 30));
    out(s.__calls.indexOf("void_doc") === -1, "S29 'Nu' nu anuleaza nimic", actionsOf(s));
    out(s.cardLedgerTotal() === 60, "S29 ledger-ul rămâne intact", String(s.cardLedgerTotal()));
    out(s.__getEl("screen-marcare").classes.has("active") === false,
      "S29 se rămâne pe ecranul de plata", "");
  }

  /* ------------------------------------------------------------------ */
  /* S30: nota blocata cu bani aprobati -> NOTA rămâne vizibil (payable)  */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5310, 464);
    s.POS_STATE.articole = [{ ecrId: 1, cantitate: 1, valoare: 464 }];
    s.POS_STATE.bankPosLocked = true;
    s.POS_STATE.bankPosLockAmount = "100.00";
    s.POS_STATE.bankPosUid = "TX20261102023941";
    s.POS_STATE.bankPosApproved = true;
    s.POS_STATE.bankPosApprovedTotal = 100;
    s.applyBankPosLockUI();
    out(s.__getEl("screen-marcare").classList.contains("bank-locked-payable") === true,
      "S30 nota blocata cu bani aprobati -> mod 'payable' (NOTA vizibil)", "");
    const msg = s.bankPosLockMessage();
    out(/apăsați NOTA/.test(msg) && /100\.00/.test(msg) && !/nu a fost confirmată/.test(msg),
      "S30 bannerul spune sa continue plata din NOTA", msg);
    out(s.__getEl("btn-bank-manual").style.display === "none",
      "S30 'Am chitanța (Manual)' ascuns cand plata e deja confirmata",
      String(s.__getEl("btn-bank-manual").style.display));
    out(s.__getEl("btn-bank-return").textContent === "Anulează plata pe card (void)",
      "S30 butonul de anulare se numeste 'Anulează plata pe card (void)'",
      s.__getEl("btn-bank-return").textContent);

    // Fara bani aprobati, bara revine la etichetele normale (Manual are sens).
    s.POS_STATE.bankPosApproved = false;
    s.POS_STATE.bankPosApprovedTotal = 0;
    // Lock fara bani aprobati = incertitudine nerezolvata (singurul caz real).
    s.POS_STATE.bankPosUncertain = true;
    s.applyBankPosLockUI();
    out(s.__getEl("btn-bank-manual").style.display === ""
      && s.__getEl("btn-bank-return").textContent === "Altă formă de plată / Anulează",
      "S30 fara bani aprobati: Manual vizibil + eticheta normala",
      s.__getEl("btn-bank-return").textContent);
  }

  /* ------------------------------------------------------------------ */
  /* S31: 'Altă formă de plată / Anulează' cu bani aprobati -> void       */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5311, 464);
    s.POS_STATE.posBanca = 1;
    let approved = "100.00";
    wireFetch(s, {
      status: () => ({
        status: "success", uncertain: approved !== "0", amount: "100.00", uid: "TX20261102023941",
        approvedTotal: approved, resyncApproved: approved !== "0", canResync: true, protocol: "SmartPayECR"
      }),
      void_doc: () => {
        approved = "0";
        return { status: "success", voided: [{ trxId: "TX20261102023941", amount: "100.00" }], failed: [], approvedTotal: 0 };
      }
    });
    const p = s.actionBankPosReturnToPay();
    await waitFor(() => !!s.__getEl("confirm-yes").onclick);
    out(/100\.00/.test(s.__getEl("confirm-message").textContent),
      "S31 anularea banilor aprobati cere confirmare cu suma",
      s.__getEl("confirm-message").textContent);
    s.__getEl("confirm-yes").onclick();
    await p;
    out(s.__calls.indexOf("void_doc") !== -1, "S31 sumele se retrag pe terminal (void_doc)", actionsOf(s));
    out(s.POS_STATE.bankPosLocked === false, "S31 nota se deblocheaza dupa void", "");
    out(s.POS_STATE.bankPosApproved === false, "S31 starea 'approved' se stinge", String(s.POS_STATE.bankPosApproved));
  }

  /* ------------------------------------------------------------------ */
  /* S32: Verifica / Manual pe ecranul de plata - doar cat timp e nerezolvat */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5320, 84);
    s.POS_STATE.posBanca = 1;
    let st = {
      status: "success", uncertain: true, manualApproved: false, resyncApproved: false,
      amount: "50.00", approvedTotal: 0, approvedEntries: [], canResync: true,
      protocol: "SmartPayECR"
    };
    wireFetch(s, { status: () => st });
    const check = s.__getEl("btn-pos-banca-check");
    const manual = s.__getEl("btn-pos-banca-manual");

    await s.refreshBankPosCheckButton();
    out(check.style.display === "" && manual.style.display === "",
      "S32 UNKNOWN nerezolvat -> Verifica + Manual vizibile",
      String(check.style.display) + "/" + String(manual.style.display));

    st = Object.assign({}, st, { manualApproved: true, amount: "50.00", approvedTotal: 50 });
    await s.refreshBankPosCheckButton();
    out(check.style.display === "none" && manual.style.display === "none",
      "S32 dupa aprobare manuala -> ambele butoane ascunse",
      String(check.style.display) + "/" + String(manual.style.display));

    st = Object.assign({}, st, { manualApproved: false, resyncApproved: true, approvedTotal: 50 });
    await s.refreshBankPosCheckButton();
    out(check.style.display === "none" && manual.style.display === "none",
      "S32 dupa resync aprobat -> ambele butoane ascunse",
      String(check.style.display) + "/" + String(manual.style.display));

    st = Object.assign({}, st, { resyncApproved: false, canResync: false });
    await s.refreshBankPosCheckButton();
    out(check.style.display === "none" && manual.style.display === "",
      "S32 protocol fara jurnal -> doar Manual vizibil",
      String(check.style.display) + "/" + String(manual.style.display));

    st = Object.assign({}, st, { canResync: true, manualApproved: false, approvedTotal: 50 });
    await s.refreshBankPosCheckButton();
    out(check.style.display === "" && manual.style.display === "",
      "S32 incercare noua dupa o suma deja aprobata -> ambele vizibile",
      String(check.style.display) + "/" + String(manual.style.display));
  }

  /* ------------------------------------------------------------------ */
  /* S33: dupa reload campul de suma arata RESTUL (total - card aprobat) */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5330, 84);
    s.POS_STATE.posBanca = 1;
    s.POS_STATE.plataCombinata = 1;
    const pays = [];
    wireFetch(s, {
      // loadPaymentForms() face GET api/fp.php, deci cheia este "fp.php".
      "fp.php": {
        status: "success", rows: [
          { FPID: 0, Denumire: "Numerar", Status: 1, Poz: 2 },
          { FPID: 1, Denumire: "CARD", Status: 1, Poz: 1 }
        ]
      },
      status: {
        status: "success", uncertain: false, canResync: true, protocol: "SmartPayECR",
        approvedTotal: 50,
        approvedEntries: [
          { amount: 50, requested: 50, source: "terminal", trxId: "T33", logId: 33 }
        ]
      },
      pay: (b) => {
        pays.push(b.amount);
        return {
          status: "success", emiteBon: true, posStatus: "OK", amount: b.amount,
          trxId: "T33B", logId: 34, protocol: "SmartPayECR", canResync: true
        };
      },
      close_bill: { status: "success", total: 84, print: {}, fiscal: { nr: 1, z: 1 } }
    });

    // Exact ca la intrarea pe ecranul de plata dupa Ctrl+F5: ledger-ul se
    // reînsămânțeaza din server, asincron, dupa ce ecranul a fost randat.
    s.syncPaymentScreen();
    await waitFor(() => s.cardLedgerTotal() === 50);
    const amountInput = s.__getEl("pay-amount-input");
    out(amountInput.value === "34.00",
      "S33 dupa reload campul de suma arata restul (84 - 50 = 34)", amountInput.value);
    out(/CARD: 50\.00/.test(s.__getEl("pay-progress").innerText)
      && /Rest: 34\.00/.test(s.__getEl("pay-progress").innerText),
      "S33 progresul arata cardul aprobat si restul",
      s.__getEl("pay-progress").innerText);
    out(amountInput.dataset.fresh === "true",
      "S33 suma implicita rămâne 'fresh' (prima tasta o inlocuieste)",
      String(amountInput.dataset.fresh));

    // OK pe CARD taxeaza doar restul, iar nota pleaca cu tot cardul.
    s.POS_STATE.activePaymentFp = 1;
    await s.finalizePaymentTransaction();
    out(pays.length === 1 && pays[0] === 34,
      "S33 OK pe CARD taxeaza terminalul doar cu restul (34)", JSON.stringify(pays));
    const close = s.__bodies.filter(b => b.action === "close_bill").pop() || {};
    out(Number(close.plati && close.plati["1"]) === 84,
      "S33 bonul are o singura linie CARD de 84 (50 aprobat + 34)",
      JSON.stringify(close.plati || null));
  }

  /* ------------------------------------------------------------------ */
  /* S34: nota deja acoperita -> OK inchide, nu cere suma                */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5331, 60);
    s.POS_STATE.posBanca = 1;
    s.POS_STATE.activePaymentFp = 1;
    s.cardLedgerAdd({ approved: 60, trxId: "T34", source: "terminal", logId: 1 });
    wireFetch(s, {
      status: { status: "success", uncertain: false, canResync: true, protocol: "SmartPayECR" },
      close_bill: { status: "success", total: 60, fiscal: { nr: 2, z: 1 } }
    });
    s.__getEl("pay-amount-input").value = "0.00";
    await s.finalizePaymentTransaction();
    out(s.__calls.indexOf("close_bill") !== -1 && s.__calls.indexOf("pay") === -1,
      "S34 nota deja acoperita se inchide la OK, fara sa mai ceara suma", actionsOf(s));
  }

  /* ------------------------------------------------------------------ */
  /* S35: aprobarea manuala actualizeaza restul din camp                 */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5332, 84);
    s.POS_STATE.posBanca = 1;
    const amountInput = s.__getEl("pay-amount-input");
    amountInput.value = "84.00";
    amountInput.dataset.fresh = "true";
    s.bankPosMarkApproved(s.bankPosApprovedPlan(50), { source: "manual", trxId: "M35" });
    out(amountInput.value === "34.00",
      "S35 aprobarea manuala partiala actualizeaza restul in camp", amountInput.value);

    // O suma tastata de operator (fresh = false) NU este suprascrisa.
    amountInput.value = "20";
    amountInput.dataset.fresh = "false";
    s.updatePaymentProgress();
    out(amountInput.value === "20",
      "S35 suma tastata de operator nu este suprascrisa", amountInput.value);
  }

  /* ------------------------------------------------------------------ */
  /* S36: PlataCombinata = 0 - click pe forma foloseste restul, nu totalul */
  /* ------------------------------------------------------------------ */
  {
    // a) click pe CARD dupa un card aprobat partial -> taxeaza doar restul
    const s = setup(CODE, 5333, 84);
    s.POS_STATE.posBanca = 1;
    s.POS_STATE.plataCombinata = 0;
    s.cardLedgerAdd({ approved: 50, trxId: "T36", source: "journal", logId: 1 });
    const pays = [];
    wireFetch(s, {
      status: { status: "success", uncertain: false, canResync: true, protocol: "SmartPayECR" },
      pay: (b) => {
        pays.push(b.amount);
        return {
          status: "success", emiteBon: true, posStatus: "OK", amount: b.amount,
          trxId: "T36B", logId: 2, protocol: "SmartPayECR", canResync: true
        };
      },
      close_bill: { status: "success", total: 84, fiscal: { nr: 3, z: 1 } }
    });
    await s.paySingleForm(1);
    out(pays.length === 1 && pays[0] === 34,
      "S36 fara plata combinata, click CARD taxeaza doar restul (34)", JSON.stringify(pays));
    const closeCard = s.__bodies.filter(b => b.action === "close_bill").pop() || {};
    out(Number(closeCard.plati && closeCard.plati["1"]) === 84,
      "S36 bonul pleaca cu tot cardul (50 + 34)", JSON.stringify(closeCard.plati || null));

    // b) click pe Numerar dupa un card aprobat partial -> doar restul cash
    const s2 = setup(CODE, 5334, 84);
    s2.POS_STATE.posBanca = 1;
    s2.POS_STATE.plataCombinata = 0;
    s2.cardLedgerAdd({ approved: 50, trxId: "T36C", source: "journal", logId: 1 });
    wireFetch(s2, {
      status: { status: "success", uncertain: false, canResync: true, protocol: "SmartPayECR" },
      close_bill: { status: "success", total: 84, fiscal: { nr: 4, z: 1 } }
    });
    await s2.paySingleForm(0);
    const closeCash = s2.__bodies.filter(b => b.action === "close_bill").pop() || {};
    out(Number(closeCash.plati && closeCash.plati["0"]) === 34
      && Number(closeCash.plati && closeCash.plati["1"]) === 50,
      "S36 fara plata combinata, Numerar incaseaza doar restul (34) + cardul deja aprobat (50)",
      JSON.stringify(closeCash.plati || null));
    out(s2.__calls.indexOf("pay") === -1,
      "S36 Numerar nu mai taxeaza cardul", actionsOf(s2));
  }

  /* ------------------------------------------------------------------ */
  /* S37: Verifica pe o tranzactie deja preluata nu dubleaza banii      */
  /* ------------------------------------------------------------------ */
  {
    // Scenariul real: aprobare manuala de 50 Lei, reload (ledger-ul este
    // reînsămânțat din server cu TRX-ul ei), apoi Verifica -> serverul
    // răspunde MANUAL pentru aceeași tranzactie. Inainte, suma se adauga a
    // doua oara (ledger 100), nota parea acoperita si se inchidea pe card 84
    // fara sa fie taxata diferenta (MISMATCH in audit).
    const s = setup(CODE, 5340, 84);
    s.POS_STATE.posBanca = 1;
    s.POS_STATE.plataCombinata = 1;
    s.POS_STATE.bankPosCanResync = true;
    s.cardLedgerSeed([{ amount: 50, requested: 50, source: "manual", trxId: "TX40", logId: 40 }]);
    wireFetch(s, {
      status: {
        status: "success", uncertain: true, manualApproved: true, resyncApproved: false,
        amount: "50.00", uid: "TX40", approvedTotal: 50,
        approvedEntries: [{ amount: 50, requested: 50, source: "manual", trxId: "TX40", logId: 40 }],
        canResync: true, protocol: "SmartPayECR"
      },
      resync: {
        status: "success", posStatus: "MANUAL", found: true, amount: "50.00",
        uid: "TX40", protocol: "SmartPayECR", canResync: true
      },
      close_bill: { status: "success", total: 84, fiscal: { nr: 1, z: 1 } }
    });

    await s.actionBankPosCheck();
    out(s.cardLedgerTotal() === 50,
      "S37 Verifica pe o tranzactie deja preluata NU dubleaza suma din ledger",
      String(s.cardLedgerTotal()));
    out(s.__calls.indexOf("close_bill") === -1,
      "S37 nota NU se inchide pe o suma numarata dublu", actionsOf(s));

    // Intrarea pe ecranul de plata: restul real (34), nu 0.
    s.syncPaymentScreen();
    await waitFor(() => s.__calls.indexOf("status") !== -1);
    out(s.cardLedgerTotal() === 50, "S37 ledger-ul rămâne 50 dupa reîncărcarea starii",
      String(s.cardLedgerTotal()));
    out(s.__getEl("pay-amount-input").value === "34.00",
      "S37 campul de suma arata restul real (34)", s.__getEl("pay-amount-input").value);
    out(s.__calls.indexOf("close_bill") === -1,
      "S37 nota nu se inchide automat (mai e de plata)", actionsOf(s));
  }

  /* ------------------------------------------------------------------ */
  /* S38: o preluare fara TRX nu dubleaza suma deja in ledger            */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5341, 84);
    s.cardLedgerSeed([{ amount: 50, requested: 50, source: "terminal", trxId: "TX41", logId: 41 }]);
    const r = s.bankPosMarkApproved(s.bankPosApprovedPlan(50));
    out(r === false && s.cardLedgerTotal() === 50,
      "S38 preluarea fara TRX a unei sume deja prezente este ignorata",
      String(s.cardLedgerTotal()) + " / " + String(r));
  }

  /* ------------------------------------------------------------------ */
  /* S39: ledger-ul se aliniaza la audit (corecteaza o numarare dubla)   */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5342, 84);
    s.POS_STATE.posBanca = 1;
    s.POS_STATE.plataCombinata = 1;
    // Ledger cu 100 Lei (doua swipe-uri), dar auditul confirma doar 50.
    s.cardLedgerAdd({ approved: 50, trxId: "T39A", source: "terminal", logId: 1 });
    s.cardLedgerAdd({ approved: 50, trxId: "T39B", source: "manual", logId: 2 });
    wireFetch(s, {
      status: {
        status: "success", uncertain: false, approvedTotal: 50,
        approvedEntries: [{ amount: 50, requested: 50, source: "terminal", trxId: "T39A", logId: 1 }],
        canResync: true, protocol: "SmartPayECR"
      },
      close_bill: { status: "success", total: 84, fiscal: { nr: 1, z: 1 } }
    });
    s.syncPaymentScreen();
    await waitFor(() => s.__calls.indexOf("status") !== -1);
    out(s.cardLedgerTotal() === 50,
      "S39 reconcilierea elimina suma care nu exista in audit", String(s.cardLedgerTotal()));
    out(s.__calls.indexOf("close_bill") === -1,
      "S39 nota nu se inchide pe bani neconfirmati de audit", actionsOf(s));
    out(s.__getEl("pay-amount-input").value === "34.00",
      "S39 campul de suma revine la restul real (34)", s.__getEl("pay-amount-input").value);
  }

  /* ------------------------------------------------------------------ */
  /* S40: restul din mesaj tine cont de cardul deja aprobat (bannerul)   */
  /* ------------------------------------------------------------------ */
  {
    // Scenariul real: 50 Lei aprobati cu cardul, apoi o noua incercare de 25
    // Lei ramasa UNKNOWN si aprobata manual. Bannerul notei arata 75 Lei
    // aprobati; mesajul trebuie sa spuna restul real (83 - 75 = 8), nu
    // 83 - 25 = 58 (cat ar iesi daca s-ar uita doar la ultima incercare).
    const s = setup(CODE, 5343, 83);
    s.POS_STATE.posBanca = 1;
    s.POS_STATE.plataCombinata = 1;
    s.cardLedgerSeed([{ amount: 50, requested: 50, source: "terminal", trxId: "TX50", logId: 1 }]);
    await s.bankPosApplyApprovedOnPaymentScreen(25, { source: "manual", trxId: "TX25", logId: 2 });
    out(s.cardLedgerTotal() === 75,
      "S40 cei 25 Lei se adauga peste cei 50 deja aprobati", String(s.cardLedgerTotal()));
    const msg = s.__getEl("alert-message").textContent;
    out(/Mai ramane de plata: 8\.00 Lei/.test(msg),
      "S40 mesajul arata restul real (83 - 75 = 8), nu 58", msg);
    out(/Total pe card: 75\.00 Lei/.test(msg),
      "S40 mesajul arata si totalul de pe card (ca bannerul notei)", msg);

    // Ecranul de plata porneste de la acelasi rest real.
    s.__getEl("val-total").innerText = "83.00";
    s.updatePayAmountDefault(true);
    out(s.__getEl("pay-amount-input").value === "8.00",
      "S40 campul de suma arata restul (8.00)", s.__getEl("pay-amount-input").value);
  }

  /* ------------------------------------------------------------------ */
  /* S41: butonul de retiparire apare si trimite reprint                 */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5344, 84);
    s.POS_STATE.posBanca = 1;
    s.POS_STATE.retiparireChitantaPoSbanca = 1;
    s.POS_STATE.plataCombinata = 1;
    s.cardLedgerSeed([{ amount: 50, requested: 50, source: "terminal", trxId: "TX50", logId: 7 }]);
    s.refreshBankReprintButton();
    out(s.__getEl("btn-banca-reprint").style.display !== "none",
      "S41 butonul Chitanta card apare dupa o aprobare pe card",
      s.__getEl("btn-banca-reprint").style.display);
    wireFetch(s, {
      reprint: {
        status: "success", jobId: 77, amount: "50.00", stan: "000031",
        rrn: "111122223333", trxId: "TX50"
      }
    });
    await s.actionBankPosReprint();
    const body = s.__bodies.filter(b => b.action === "reprint").pop() || {};
    out(s.__calls.filter(a => a === "reprint").length === 1,
      "S41 un singur reprint trimis", actionsOf(s));
    out(Number(body.docId) === 5344 && body.logId === null,
      "S41 reprint trimite docId (serverul alege ultima aprobare)", JSON.stringify(body));
    const html = s.__getEl("alert-message").innerHTML;
    out(/job 77/.test(html), "S41 mesajul arata jobul de tiparire", html);
    out(/Reprint last receipt/.test(html),
      "S41 mesajul explica si retiparirea originalului din terminal", html);

    // Fara card, butonul nu apare.
    const s2 = setup(CODE, 5345, 84);
    s2.POS_STATE.posBanca = 1;
    s2.refreshBankReprintButton();
    out(s2.__getEl("btn-banca-reprint").style.display === "none",
      "S41 fara plata pe card butonul este ascuns",
      s2.__getEl("btn-banca-reprint").style.display);

    // Setarea din Setari -> POS bancar este pe NU (implicit): chiar daca exista
    // o plata pe card, butonul nu apare si actiunea este refuzata in client.
    const s3 = setup(CODE, 5348, 84);
    s3.POS_STATE.posBanca = 1;
    s3.cardLedgerSeed([{ amount: 50, requested: 50, source: "terminal", trxId: "TX51", logId: 8 }]);
    s3.refreshBankReprintButton();
    out(s3.__getEl("btn-banca-reprint").style.display === "none",
      "S41 cu RetiparireChitantaPoSbanca = NU butonul este ascuns",
      s3.__getEl("btn-banca-reprint").style.display);
    await s3.actionBankPosReprint();
    out(s3.__calls.indexOf("reprint") === -1,
      "S41 cu setarea NU nu se trimite reprint", actionsOf(s3));
    out(/dezactivata din Setari/.test(s3.__getEl("alert-message").textContent),
      "S41 mesajul spune ca functia este dezactivata din Setari",
      s3.__getEl("alert-message").textContent);
  }

  /* ------------------------------------------------------------------ */
  /* S42: dupa inchidere, chitanta card poate fi retiparita (FastFood)   */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5346, 84);
    s.POS_STATE.posBanca = 1;
    s.POS_STATE.retiparireChitantaPoSbanca = 1;
    s.POS_STATE.plataCombinata = 1;
    s.POS_STATE.tipVanz = "fastfood";
    s.cardLedgerSeed([{ amount: 84, requested: 84, source: "terminal", trxId: "TX84", logId: 12 }]);
    wireFetch(s, {
      close_bill: { status: "success", total: 84, fiscal: { nr: 1, z: 1 }, print: [] },
      status: { status: "success", uncertain: false, approvedTotal: 84, approvedEntries: [] },
      tables: { status: "success", tables: [] }
    });
    await s.executeClose(84, 0);
    out(s.__calls.indexOf("close_bill") !== -1, "S42 nota s-a inchis", actionsOf(s));
    out(s.POS_STATE.lastCardReprint && Number(s.POS_STATE.lastCardReprint.docId) === 5346,
      "S42 se pastreaza docId pentru retiparire",
      JSON.stringify(s.POS_STATE.lastCardReprint));
    const html = s.__getEl("fastfood-receipt").innerHTML;
    out(/actionBankPosReprint/.test(html),
      "S42 panoul FastFood ofera retiparirea chitantei card", html.slice(0, 200));
  }

  /* ------------------------------------------------------------------ */
  /* S43: dialogul de succes (Restaurant) ofera retiparirea              */
  /* ------------------------------------------------------------------ */
  {
    const s = setup(CODE, 5347, 84);
    s.POS_STATE.posBanca = 1;
    s.POS_STATE.retiparireChitantaPoSbanca = 1;
    s.POS_STATE.plataCombinata = 1;
    s.POS_STATE.tipVanz = "restaurant";
    s.cardLedgerSeed([{ amount: 84, requested: 84, source: "terminal", trxId: "TX84", logId: 13 }]);
    wireFetch(s, {
      close_bill: { status: "success", total: 84, fiscal: { nr: 1, z: 1 }, print: [] },
      status: { status: "success", uncertain: false, approvedTotal: 84, approvedEntries: [] },
      tables: { status: "success", tables: [] }
    });
    await s.executeClose(84, 0);
    out(/actionBankPosReprint/.test(s.__getEl("alert-message").innerHTML),
      "S43 dialogul de succes ofera retiparirea chitantei card",
      s.__getEl("alert-message").innerHTML.slice(0, 200));
  }

  /* ------------------------------------------------------------------ */
  {
    // Regresie: `status` (numit la intrarea pe ecranul de plata si dupa orice
    // decizie) recalcula lock-ul. Inainte il lua doar din `uncertain`, deci o
    // nota cu bani aprobati pe card (plata partiala + Ctrl+F5) rămânea aparent
    // deblocata: bara de jos arata Discount/VD/QTY, iar prima modificare lua
    // 423 "Nota are o plata pe card confirmata" - fara niciun buton de void.
    const s = setup(CODE, 5348, 83);
    s.POS_STATE.posBanca = 1;
    s.POS_STATE.bankPosCanResync = true;
    s.POS_STATE.bankPosCanVoid = true;
    s.POS_STATE.bankPosUid = "TX20261116184054";
    wireFetch(s, {
      status: {
        status: "success", uncertain: false, approvedTotal: 70,
        approvedEntries: [{ amount: 70, requested: 70, source: "terminal", trxId: "TX44", logId: 44 }],
        canResync: true, canVoid: true, protocol: "SmartPayECR"
      }
    });

    await s.fetchBankPosStatus();
    out(s.POS_STATE.bankPosLocked === true && s.POS_STATE.bankPosApproved === true,
      "S44 status cu bani aprobati pe card blocheaza nota",
      "locked=" + s.POS_STATE.bankPosLocked + " approved=" + s.POS_STATE.bankPosApproved);
    out(s.__getEl("screen-marcare").classList.contains("bank-locked")
      && s.__getEl("screen-marcare").classList.contains("bank-locked-payable"),
      "S44 bara arata lock-ul de plata (bank-locked-payable)",
      Array.from(s.__getEl("screen-marcare").classes).join(","));
    out(s.__getEl("btn-bank-return").textContent === "Anulează plata pe card (void)",
      "S44 butonul de anulare (void) este disponibil in bara",
      String(s.__getEl("btn-bank-return").textContent));
    out(s.__getEl("bank-lock-banner").style.display === "flex",
      "S44 banner-ul notei este vizibil", String(s.__getEl("bank-lock-banner").style.display));
    // Plata confirmata = certa: nu mai exista nimic de verificat.
    out(s.__getEl("btn-bank-verify").style.display === "none",
      "S44 Verifica NU apare cand plata este confirmata (cert)",
      String(s.__getEl("btn-bank-verify").style.display));
    out(s.__getEl("btn-bank-manual").style.display === "none",
      "S44 Manual NU apare cand plata este confirmata",
      String(s.__getEl("btn-bank-manual").style.display));
    // TRX-ul nu are ce cauta intr-o plata confirmata (nimic de verificat).
    out(s.__getEl("bank-lock-trx").textContent === "",
      "S44 TRX-ul dispare cand plata este confirmata",
      String(s.__getEl("bank-lock-trx").textContent));
    // Toate pozitiile sunt deja trimise la bucatarie (NOTA le marcheaza inainte
    // de ecranul de plata), deci Marcare nu mai are obiect.
    out(s.__getEl("btn-marcare").style.display === "none",
      "S44 Marcare este ascuns pe nota cu plata confirmata",
      String(s.__getEl("btn-marcare").style.display));
    // ...dar rămâne la vedere daca o linie a rămas netrimisa (Marcare esuata).
    s.POS_STATE.articole = [{ ecrId: 1, cantitate: 1, preluat: false }];
    s.applyBankPosLockUI();
    out(s.__getEl("btn-marcare").style.display !== "none",
      "S44 Marcare rămâne disponibil daca exista o linie netrimisa",
      String(s.__getEl("btn-marcare").style.display));
  }

  /* ------------------------------------------------------------------ */
  {
    // Incertitudine reala (UNKNOWN nerezolvat): aici Verifica si Manual sunt
    // exact actiunile utile.
    const s = setup(CODE, 5349, 83);
    s.POS_STATE.posBanca = 1;
    s.POS_STATE.bankPosCanResync = true;
    s.POS_STATE.bankPosUid = "TX45";
    wireFetch(s, {
      status: {
        status: "success", uncertain: true, approvedTotal: 0, amount: "20.00", uid: "TX45",
        approvedEntries: [], resyncApproved: false, manualApproved: false,
        canResync: true, canVoid: true, protocol: "SmartPayECR"
      }
    });

    await s.fetchBankPosStatus();
    out(s.POS_STATE.bankPosLocked === true && s.POS_STATE.bankPosApproved === false,
      "S45 incertitudine nerezolvata blocheaza nota (fara bani aprobati)",
      "locked=" + s.POS_STATE.bankPosLocked);
    out(s.__getEl("btn-bank-verify").style.display !== "none"
      && s.__getEl("btn-bank-manual").style.display !== "none",
      "S45 Verifica si Manual apar la o plata incerta",
      "verify=" + s.__getEl("btn-bank-verify").style.display
        + " manual=" + s.__getEl("btn-bank-manual").style.display);
    // TRX-ul rămâne util aici: se cauta in jurnalul terminalului.
    out(s.__getEl("bank-lock-trx").textContent === "TRX: TX45",
      "S45 TRX-ul este afisat la o plata incerta",
      String(s.__getEl("bank-lock-trx").textContent));
  }

  /* ------------------------------------------------------------------ */
  {
    // Incertitudinea a fost lamurita de jurnal (RESYNC APPROVED=1): banii sunt
    // confirmati, deci Verifica/Manual dispar - rămâne doar continuarea platii.
    const s = setup(CODE, 5350, 83);
    s.POS_STATE.posBanca = 1;
    s.POS_STATE.bankPosCanResync = true;
    s.POS_STATE.bankPosUid = "TX46";
    wireFetch(s, {
      status: {
        status: "success", uncertain: true, approvedTotal: 50, amount: "50.00", uid: "TX46",
        approvedEntries: [{ amount: 50, requested: 50, source: "journal", trxId: "TX46", logId: 46 }],
        resyncApproved: true, manualApproved: false,
        canResync: true, canVoid: true, protocol: "SmartPayECR"
      }
    });

    await s.fetchBankPosStatus();
    out(s.POS_STATE.bankPosLocked === true && s.POS_STATE.bankPosApproved === true,
      "S46 nota rămâne blocata dupa confirmarea din jurnal",
      "locked=" + s.POS_STATE.bankPosLocked + " approved=" + s.POS_STATE.bankPosApproved);
    out(s.__getEl("btn-bank-verify").style.display === "none"
      && s.__getEl("btn-bank-manual").style.display === "none",
      "S46 Verifica/Manual dispar dupa confirmarea in jurnal",
      "verify=" + s.__getEl("btn-bank-verify").style.display
        + " manual=" + s.__getEl("btn-bank-manual").style.display);
    out(s.__getEl("bank-lock-trx").textContent === "",
      "S46 TRX-ul dispare dupa confirmarea in jurnal",
      String(s.__getEl("bank-lock-trx").textContent));
  }

  /* ------------------------------------------------------------------ */
  {
    // Regresie: masa pe care lucra operatorul trebuie sa supravietuiasca unui
    // refresh (Ctrl+F5). Inainte, initApp pornea mereu pe Masa 1, deci nota de
    // pe masa reala - inclusiv una cu plata pe card incerta - disparea de pe
    // ecran (rămânea doar in baza de date).
    const s = setup(CODE, 5351, 83);
    const store = new Map();
    s.sessionStorage = {
      getItem: k => (store.has(k) ? store.get(k) : null),
      setItem: (k, v) => store.set(k, String(v)),
      removeItem: k => store.delete(k)
    };
    s.saveCurrentTable(7);
    out(s.savedCurrentTable() === 7, "S47 masa curenta se pastreaza peste refresh",
      String(s.savedCurrentTable()));
    s.clearCurrentTable();
    out(s.savedCurrentTable() === 0, "S47 masa se uita la iesirea din masa",
      String(s.savedCurrentTable()));
    s.saveCurrentTable(0);
    s.saveCurrentTable(81);
    out(s.savedCurrentTable() === 0, "S47 valori invalide sunt ignorate",
      String(s.savedCurrentTable()));
  }

  /* ------------------------------------------------------------------ */
  {
    // Caz real dupa un Ctrl+F5 in timpul unui swipe: o plata anterioara este
    // confirmata (70 Lei), iar o incercare noua a rămas fara raspuns (10 Lei).
    // Bannerul trebuie sa spuna ambele lucruri (altfel operatorul crede ca totul
    // e in regula si close_bill refuza inchiderea).
    const s = setup(CODE, 5352, 109);
    s.POS_STATE.posBanca = 1;
    s.POS_STATE.bankPosCanResync = true;
    s.POS_STATE.bankPosLockAmount = "10.00";
    s.POS_STATE.bankPosUid = "TX48";
    wireFetch(s, {
      status: {
        status: "success", uncertain: true, approvedTotal: 70, amount: "10.00", uid: "TX48",
        approvedEntries: [{ amount: 70, requested: 70, source: "terminal", trxId: "TX48A", logId: 48 }],
        resyncApproved: false, manualApproved: false,
        canResync: true, canVoid: true, protocol: "SmartPayECR"
      }
    });

    await s.fetchBankPosStatus();
    const msg48 = s.bankPosLockMessage();
    out(/70\.00/.test(msg48) && /10\.00/.test(msg48) && /Verifica/.test(msg48),
      "S48 bannerul arata si suma confirmata, si incercarea fara raspuns", msg48);
    out(s.__getEl("btn-bank-verify").style.display !== "none",
      "S48 Verifica rămâne disponibil cat timp incercarea e nerezolvata",
      String(s.__getEl("btn-bank-verify").style.display));
    out(s.__getEl("bank-lock-trx").textContent === "TRX: TX48",
      "S48 TRX-ul incercarii nerezolvate este afisat",
      String(s.__getEl("bank-lock-trx").textContent));
  }

  /* ------------------------------------------------------------------ */
  {
    // Regresie: FastFood are o singura nota, pe masa 1. Daca modul se activeaza
    // in timpul lucrului pe o masa de Restaurant (sau masa e restaurata dintr-o
    // sesiune anterioara), comenzile ajung pe masa aceea - bug real: produsele
    // marcate in FastFood au ajuns pe masa 8.
    const s = setup(CODE, 5353, 0);
    const store = new Map();
    s.sessionStorage = {
      getItem: k => (store.has(k) ? store.get(k) : null),
      setItem: (k, v) => store.set(k, String(v)),
      removeItem: k => store.delete(k)
    };
    s.POS_STATE.tipVanz = "restaurant";
    s.saveCurrentTable(8);
    out(s.savedCurrentTable() === 8, "S49 restaurant: masa curenta se memoreaza",
      String(s.savedCurrentTable()));

    // Trecerea pe FastFood: masa revine la 1 si nota afisata este cea de pe 1.
    s.POS_STATE.tipVanz = "fastfood";
    s.POS_STATE.masaCurenta = 8;
    s.POS_STATE.docId = 7001;
    wireFetch(s, {
      "get_order.php": { status: "success", hasOrder: false, docId: null, articole: [], total: 0, subtotal: 0 }
    });
    s.applyTipVanzUI();
    out(Number(s.POS_STATE.masaCurenta) === 1,
      "S49 trecerea pe FastFood readuce masa la 1", String(s.POS_STATE.masaCurenta));
    await waitFor(() => s.__calls.indexOf("get_order.php") !== -1);
    out(s.__calls.indexOf("get_order.php") !== -1 && !store.has("rual_masa_curenta"),
      "S49 FastFood reincarca nota de pe masa 1 si uita masa de Restaurant",
      actionsOf(s));

    // In FastFood nu se mai memoreaza nicio masa.
    s.saveCurrentTable(5);
    out(s.savedCurrentTable() === 0, "S49 in FastFood nu se memoreaza masa",
      String(s.savedCurrentTable()));
  }

  /* ------------------------------------------------------------------ */
  {
    // PENDING: comanda a plecat spre terminal, raspunsul nu a venit inca (cazul
    // Ctrl+F5 in timpul asteptarii). Nota trebuie sa fie blocata, dar fara
    // butoane de decizie: o verificare/void acum ar fi premature.
    const s = setup(CODE, 5354, 50);
    s.POS_STATE.posBanca = 1;
    s.POS_STATE.bankPosCanResync = true;
    s.POS_STATE.bankPosLockAmount = "50.00";
    s.POS_STATE.bankPosUid = "TX50";
    wireFetch(s, {
      status: {
        status: "success", uncertain: true, pending: true, approvedTotal: 0,
        amount: "50.00", uid: "TX50", approvedEntries: [],
        manualApproved: false, resyncApproved: false,
        canResync: true, canVoid: true, protocol: "SmartPayECR"
      }
    });

    await s.fetchBankPosStatus();
    out(s.POS_STATE.bankPosLocked === true && s.POS_STATE.bankPosPending === true,
      "S50 comanda in curs (PENDING) blocheaza nota",
      "locked=" + s.POS_STATE.bankPosLocked + " pending=" + s.POS_STATE.bankPosPending);
    out(/în curs/.test(s.bankPosLockMessage()),
      "S50 bannerul spune ca plata este in curs", s.bankPosLockMessage());
    out(s.__getEl("btn-bank-verify").style.display === "none"
      && s.__getEl("btn-bank-manual").style.display === "none"
      && s.__getEl("btn-bank-return").style.display === "none",
      "S50 Verifica/Manual/Anuleaza sunt ascunse cat timp comanda e in curs",
      "verify=" + s.__getEl("btn-bank-verify").style.display
        + " manual=" + s.__getEl("btn-bank-manual").style.display
        + " return=" + s.__getEl("btn-bank-return").style.display);
  }

  console.log("--- Faza JS bancar: " + pass + " pass, " + fail + " fail (total " + (pass + fail) + ") ---");
  process.exit(fail ? 1 : 0);
})().catch(err => {
  out(false, "harness-ul a rulat fara exceptii", String(err && err.stack ? err.stack : err).slice(0, 300));
  console.log("--- Faza JS bancar: " + pass + " pass, " + fail + " fail ---");
  process.exit(1);
});
