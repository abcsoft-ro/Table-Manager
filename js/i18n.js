/**
 * TableManager - i18n client (RO/EN).
 *
 * Catalogul este injectat de PHP in pagina: window.LANG + window.I18N
 * (vezi api/i18n.php -> i18nJs()). Expune:
 *   - t(key, params)   - traduce o cheie (fallback pe cheie)
 *   - applyI18n(root)  - traduce elementele cu atribute data-i18n*
 *
 * Atribute suportate:
 *   data-i18n="cheie"             -> textContent
 *   data-i18n-html="cheie"        -> innerHTML
 *   data-i18n-title="cheie"       -> atributul title
 *   data-i18n-placeholder="cheie" -> atributul placeholder
 */
(function () {
  "use strict";

  var CATALOG = (typeof window !== "undefined" && window.I18N && typeof window.I18N === "object")
    ? window.I18N
    : {};

  function fill(s, params) {
    if (params) {
      for (var k in params) {
        if (Object.prototype.hasOwnProperty.call(params, k)) {
          s = String(s).split("{" + k + "}").join(String(params[k]));
        }
      }
    }
    return s;
  }

  // Traducere exacta dupa cheie sau dupa textul-sursa romana.
  function t(key, params) {
    var s = Object.prototype.hasOwnProperty.call(CATALOG, key) ? CATALOG[key] : key;
    return fill(s, params);
  }

  // Traducere gettext-style pentru mesaje: intai potrivire exacta, apoi cea mai
  // lunga cheie care este prefix al textului (ex. "Eroare la salvare: " + detaliu).
  // Restul textului (detaliul interpolat) este pastrat.
  function trText(s, params) {
    if (typeof s !== "string" || s === "") { return s; }
    if (Object.prototype.hasOwnProperty.call(CATALOG, s)) {
      return fill(CATALOG[s], params);
    }
    var bestKey = null;
    for (var k in CATALOG) {
      if (!Object.prototype.hasOwnProperty.call(CATALOG, k)) { continue; }
      if (k.length >= s.length) { continue; }
      // Doar cheile care se termina cu un separator potrivite ca prefix (evita
      // potriviri gresite de tipul "Sterge" in "Stergeti ...").
      if (/[A-Za-z0-9]/.test(k.charAt(k.length - 1))) { continue; }
      if (s.lastIndexOf(k, 0) === 0) {
        if (bestKey === null || k.length > bestKey.length) { bestKey = k; }
      }
    }
    if (bestKey !== null) {
      return fill(CATALOG[bestKey], params) + s.slice(bestKey.length);
    }
    return s;
  }

  function applyI18n(root) {
    var scope = root || document;
    var nodes;

    nodes = scope.querySelectorAll("[data-i18n]");
    for (var i = 0; i < nodes.length; i++) {
      var key = nodes[i].getAttribute("data-i18n");
      if (key) { nodes[i].textContent = t(key); }
    }

    nodes = scope.querySelectorAll("[data-i18n-html]");
    for (var j = 0; j < nodes.length; j++) {
      var khtml = nodes[j].getAttribute("data-i18n-html");
      if (khtml) { nodes[j].innerHTML = t(khtml); }
    }

    nodes = scope.querySelectorAll("[data-i18n-title]");
    for (var a = 0; a < nodes.length; a++) {
      var ktitle = nodes[a].getAttribute("data-i18n-title");
      if (ktitle) { nodes[a].setAttribute("title", t(ktitle)); }
    }

    nodes = scope.querySelectorAll("[data-i18n-placeholder]");
    for (var b = 0; b < nodes.length; b++) {
      var kph = nodes[b].getAttribute("data-i18n-placeholder");
      if (kph) { nodes[b].setAttribute("placeholder", t(kph)); }
    }
  }

  // Traduce nodurile de text dintr-un container (HTML randat dinamic). Se
  // potrivesc doar noduri al caror text (fara spatii) exista exact in catalog,
  // deci datele dinamice (numere, denumiri de produse) rămân neatinse.
  function applyI18nText(root) {
    var scope = root || document;
    if (!scope || typeof document.createTreeWalker !== "function") { return; }
    var walker = document.createTreeWalker(scope, NodeFilter.SHOW_TEXT, null);
    var nodes = [];
    var n;
    while ((n = walker.nextNode()) !== null) { nodes.push(n); }
    for (var i = 0; i < nodes.length; i++) {
      var node = nodes[i];
      var raw = node.nodeValue;
      if (!raw) { continue; }
      var trimmed = raw.trim();
      if (!trimmed) { continue; }
      var translated = trText(trimmed);
      if (translated !== trimmed) {
        node.nodeValue = raw.replace(trimmed, translated);
      }
    }
  }

  window.t = t;
  window.trText = trText;
  window.applyI18n = applyI18n;
  window.applyI18nText = applyI18nText;
})();
