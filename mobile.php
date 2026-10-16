<?php
/**
 * Modul mobil de marcare (randare server-side pentru i18n).
 * Limba vine din tblSet.Limba (api/i18n.php) si este injectata pentru JS.
 */
require_once __DIR__ . '/api/i18n.php';
// Pagina se randeaza instant, fara sa astepte baza de date (limba se ia din
// copia locala daca baza de date nu raspunde - vezi api/i18n.php, APP_LANG_FAST).
$GLOBALS['APP_LANG_FAST'] = true;
require_once __DIR__ . '/api/version_common.php';
$LANG = appLang();
// Token de cache pentru active (JS/CSS): se schimba la fiecare versiune, altfel
// browserul ruleaza JS-ul vechi din cache (vezi comentariul din index.php).
$ASSET_V = (string)(appVersionInfo()['short'] ?? '');
if ($ASSET_V === '') { $ASSET_V = appVersionReadHuman(); }
$ASSET_V = preg_replace('/[^A-Za-z0-9._-]/', '', $ASSET_V);
?><!DOCTYPE html>
<html lang="<?= htmlspecialchars($LANG, ENT_QUOTES) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#232d84">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <title>TableManager Mobil - Marcare</title>
  <?= i18nJs($LANG) ?>
  <script src="js/i18n.js?v=<?= $ASSET_V ?>"></script>
  <link rel="stylesheet" href="css/mobile.css?v=<?= $ASSET_V ?>">
</head>
<body>

<div id="mv-app">

  <!-- ================= HEADER ================= -->
  <header class="mv-header">
    <div class="mv-header-left">
      <button class="mv-icon-btn" id="mv-back-btn" onclick="mvBack()" aria-label="Inapoi">&#9664;</button>
      <div class="mv-header-brand">
        <span class="mv-header-logo">TM</span>
        <span class="mv-header-title" id="mv-header-title" data-i18n="mobile.mese">Mese</span>
      </div>
    </div>
    <div class="mv-header-right">
      <button class="mv-icon-btn" id="mv-search-btn" onclick="openSearch()" aria-label="Cautare" style="display:none;">&#128269;</button>
      <button class="mv-waiter-chip" id="mv-waiter-chip" onclick="mvWaiterMenu()" data-i18n="mobile.neautentificat">Neautentificat</button>
    </div>
  </header>

  <!-- ================= VIEW: MESE ================= -->
  <section id="view-tables" class="mview active">
    <div class="mv-filter-bar" id="mv-tables-filter">
      <button class="mv-filter" data-filter="mine" onclick="setTablesFilter('mine')" data-i18n="mobile.filtru.mine">Ale mele</button>
      <button class="mv-filter" data-filter="free" onclick="setTablesFilter('free')" data-i18n="mobile.filtru.libere">Libere</button>
      <button class="mv-filter" data-filter="busy" onclick="setTablesFilter('busy')" data-i18n="mobile.filtru.ocupate">Ocupate</button>
      <button class="mv-filter active" data-filter="all" onclick="setTablesFilter('all')" data-i18n="mobile.filtru.toate">Toate</button>
      <button class="mv-filter mv-filter-action" onclick="openTableByNumber()" data-i18n="mobile.filtru.masa"># Masa</button>
    </div>
    <div class="mv-scroll">
      <div class="mv-tables-grid" id="mv-tables-grid"></div>
      <div class="mv-empty" id="mv-tables-empty" style="display:none;" data-i18n="mobile.fara_mese">Nu exista mese pentru filtrul selectat.</div>
    </div>
    <div class="mv-tables-footer" id="mv-tables-footer"></div>
  </section>

  <!-- ================= VIEW: MARCARE ================= -->
  <section id="view-mark" class="mview">
    <div class="mv-mark-top">
      <button class="mv-table-pill" onclick="openTablesView()">
        <span class="mv-table-pill-label" data-i18n="Masa">Masa</span>
        <span class="mv-table-pill-num" id="mv-mark-masa">-</span>
      </button>
      <div class="mv-mark-meta">
        <span class="mv-mark-reducere" id="mv-mark-reducere" style="display:none;"></span>
      </div>
      <button class="mv-cart-toggle" onclick="toggleNota()">
        Nota <span class="mv-cart-count" id="mv-cart-count">0</span>
      </button>
      <button class="mv-more-btn" onclick="openMarkMenu()" aria-label="Mai multe actiuni">&#8942;</button>
    </div>

    <div class="mv-groups-bar" id="mv-groups-bar"></div>

    <div class="mv-mark-main">
      <div class="mv-catalog">
        <div class="mv-scan-row" id="mv-scan-row" style="display:none;">
          <input type="text" id="mv-scan-input" class="mv-scan-input" placeholder="Scan / cod produs" data-i18n-placeholder="mobile.scan_placeholder" autocomplete="off" onkeydown="onScanKeydown(event)">
          <button class="mv-scan-toggle" onclick="toggleScan()">&#10005;</button>
        </div>
        <button class="mv-scan-open" id="mv-scan-open" onclick="toggleScan()" data-i18n="mobile.cod_scan">&#128242; Cod / Scan</button>
        <div class="mv-products-grid" id="mv-products-grid"></div>
        <div class="mv-empty" id="mv-products-empty" style="display:none;" data-i18n="mobile.selectati_grupa">Selectati o grupa.</div>
      </div>

      <aside class="mv-nota" id="mv-nota-panel">
        <div class="mv-nota-handle" onclick="closeNota()"></div>
        <div class="mv-nota-head">
          <span data-i18n="mobile.nota_de_plata">NOTA DE PLATA</span>
          <button class="mv-nota-close" onclick="closeNota()" aria-label="Inchide">&times;</button>
        </div>
        <div class="mv-nota-list" id="mv-nota-list"></div>
        <div class="mv-nota-totals">
          <div class="mv-total-row"><span data-i18n="common.subtotal">Subtotal</span><span id="mv-nota-subtotal">0.00</span></div>
          <div class="mv-total-row"><span data-i18n="common.reducere">Reducere</span><span id="mv-nota-reducere">0.00</span></div>
          <div class="mv-total-row mv-total-grand"><span data-i18n="common.total">TOTAL</span><span id="mv-nota-total">0.00</span></div>
        </div>
      </aside>
    </div>

    <div class="mv-cart-bar">
      <button class="mv-cart-bar-info" onclick="toggleNota()">
        <span class="mv-cart-bar-count" id="mv-cartbar-count">0 produse</span>
        <span class="mv-cart-bar-total" id="mv-cartbar-total">0.00 L</span>
      </button>
      <button class="mv-btn-marcare" id="mv-btn-marcare" onclick="actionMarcare()" data-i18n="mobile.marcheaza">Marcheaza</button>
    </div>
  </section>

  <div class="mv-nota-backdrop" id="mv-nota-backdrop" onclick="closeNota()"></div>
</div>

<!-- ================= MODAL: NUMPAD ================= -->
<div class="mv-overlay" id="modal-numpad">
  <div class="mv-sheet mv-sheet-num">
    <div class="mv-sheet-header">
      <span id="numpad-title">Introduceti</span>
      <button class="mv-sheet-close" onclick="closeModal('modal-numpad')">&times;</button>
    </div>
    <div class="mv-numpad-display" id="numpad-display">0</div>
    <div class="mv-numpad" id="numpad-grid"></div>
    <div class="mv-numpad-actions">
      <button class="mv-num-action mv-num-clear" onclick="numpadClear()" data-i18n="mobile.sterge">Sterge</button>
      <button class="mv-num-action mv-num-ok" onclick="numpadSubmit()">OK</button>
    </div>
  </div>
</div>

<!-- ================= MODAL: ACTIUNI LINIE ================= -->
<div class="mv-overlay" id="modal-line">
  <div class="mv-sheet mv-sheet-bottom">
    <div class="mv-sheet-header">
      <span id="line-actions-title">Linie</span>
      <button class="mv-sheet-close" onclick="closeModal('modal-line')">&times;</button>
    </div>
    <div class="mv-sheet-body" id="line-actions-body"></div>
  </div>
</div>

<!-- ================= MODAL: MOD PREPARARE ================= -->
<div class="mv-overlay" id="modal-mods">
  <div class="mv-sheet">
    <div class="mv-sheet-header">
      <span data-i18n="mobile.mod_preparare">Mod preparare</span>
      <button class="mv-sheet-close" onclick="closeModal('modal-mods')">&times;</button>
    </div>
    <div class="mv-sheet-body" id="mods-body"></div>
  </div>
</div>

<!-- ================= MODAL: DISCOUNT ================= -->
<div class="mv-overlay" id="modal-discount">
  <div class="mv-sheet">
    <div class="mv-sheet-header">
      <span data-i18n="mobile.discount">Discount</span>
      <button class="mv-sheet-close" onclick="closeDiscountModalMobile()">&times;</button>
    </div>
    <div class="mv-sheet-body">
      <div class="mv-field-label" id="disc-scope-label" data-i18n="mobile.disc.aplicare">Aplicare</div>
      <div class="mv-segment" id="disc-scope">
        <button data-scope="bill" onclick="discSetScope('bill')" data-i18n="mobile.disc.pe_nota">Pe nota</button>
        <button data-scope="line" onclick="discSetScope('line')" data-i18n="mobile.disc.pe_linia">Pe linia selectata</button>
      </div>
      <div class="mv-field-label" id="disc-mode-label" data-i18n="mobile.disc.tip">Tip</div>
      <div class="mv-segment" id="disc-mode">
        <button data-mode="percent" onclick="discSetMode('percent')" data-i18n="mobile.disc.procent">Procent %</button>
        <button data-mode="valoric" onclick="discSetMode('valoric')" data-i18n="mobile.disc.valoric">Valoric L</button>
      </div>
      <div class="mv-field-label" data-i18n="mobile.disc.valoare">Valoare</div>
      <button class="mv-value-btn" id="disc-value-btn" onclick="discEditValue()">0</button>
      <div class="mv-field-label" id="disc-motiv-label" data-i18n="mobile.disc.motiv">Motiv (optional)</div>
      <input type="text" class="mv-text-input" id="disc-motiv" placeholder="Motiv discount" data-i18n-placeholder="mobile.disc.motiv_ph" autocomplete="off">
      <button class="mv-primary-btn" onclick="discApply()" data-i18n="mobile.disc.aplica">Aplica discount</button>
    </div>
  </div>
</div>

<!-- ================= MODAL: VD / ANULARE ================= -->
<div class="mv-overlay" id="modal-void">
  <div class="mv-sheet">
    <div class="mv-sheet-header">
      <span data-i18n="mobile.vd">VD / Anulare</span>
      <button class="mv-sheet-close" onclick="closeModal('modal-void')">&times;</button>
    </div>
    <div class="mv-sheet-body" id="void-body"></div>
  </div>
</div>

<!-- ================= MODAL: TRANSFER ================= -->
<div class="mv-overlay" id="modal-transfer">
  <div class="mv-sheet">
    <div class="mv-sheet-header">
      <span data-i18n="mobile.transfer">Transfer produse</span>
      <button class="mv-sheet-close" onclick="closeModal('modal-transfer')">&times;</button>
    </div>
    <div class="mv-sheet-body" id="transfer-body"></div>
  </div>
</div>

<!-- ================= MODAL: CAUTARE ================= -->
<div class="mv-overlay" id="modal-search">
  <div class="mv-sheet mv-sheet-top">
    <div class="mv-sheet-header">
      <span data-i18n="mobile.cautare">Cautare produs</span>
      <button class="mv-sheet-close" onclick="closeModal('modal-search')">&times;</button>
    </div>
    <div class="mv-search-input-row">
      <input type="text" id="mv-search-input" class="mv-text-input" placeholder="Nume sau cod produs" data-i18n-placeholder="mobile.cautare_ph" autocomplete="off" oninput="onSearchInput()">
    </div>
    <div class="mv-search-results" id="mv-search-results"></div>
  </div>
</div>

<!-- ================= MODAL: CONFIRM ================= -->
<div class="mv-overlay" id="modal-confirm">
  <div class="mv-dialog">
    <div class="mv-dialog-msg" id="confirm-message"></div>
    <div class="mv-dialog-actions">
      <button class="mv-dialog-no" id="confirm-no" onclick="closeAppConfirm()" data-i18n="common.nu">Nu</button>
      <button class="mv-dialog-yes" id="confirm-yes" data-i18n="common.da">Da</button>
    </div>
  </div>
</div>

<!-- ================= MODAL: ALERT ================= -->
<div class="mv-overlay" id="modal-alert">
  <div class="mv-dialog">
    <div class="mv-dialog-msg" id="alert-message"></div>
    <div class="mv-dialog-actions">
      <button class="mv-dialog-yes" onclick="closeAppAlert()">OK</button>
    </div>
  </div>
</div>

<div class="mv-toast" id="mv-toast"></div>

<script src="js/mobile.js?v=<?= $ASSET_V ?>"></script>
<script>if (typeof applyI18n === "function") applyI18n();</script>
</body>
</html>
