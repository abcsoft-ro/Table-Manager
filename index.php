<?php
/**
 * Pagina principala POS (randare server-side pentru i18n).
 *
 * Limba este citita din tblSet.Limba (api/i18n.php) si aplicata server-side pe
 * <html lang> + injectata in pagina (window.LANG/window.I18N) pentru JS.
 * Textul static este marcat cu data-i18n* si tradus de js/i18n.js.
 */
require_once __DIR__ . '/api/i18n.php';
// Pagina se randeaza instant, fara sa astepte baza de date (limba se ia din
// copia locala daca baza de date nu raspunde - vezi api/i18n.php, APP_LANG_FAST).
$GLOBALS['APP_LANG_FAST'] = true;
require_once __DIR__ . '/api/app_events.php';
require_once __DIR__ . '/api/version_common.php';
$LANG = appLang();
// Jurnal (logs/app_events.log): fiecare DESCHIDERE a aplicatiei, cu versiunea
// instalata - ca sa putem corela cu inchiderile si cu prompturile de parola.
appEventLog('open', appEventClientCtx() + ['versiune' => appVersionReadHuman()]);

// Token de cache pentru active (JS/CSS): se schimba la fiecare versiune. Fara el
// browserul ruleaza JS-ul/CSS-ul vechi din cache la nesfarsit (pe kiosk nu se face
// niciodata hard-refresh), deci un fix in js/*.js nu ajunge efectiv pe statie.
$ASSET_V = (string)(appVersionInfo()['short'] ?? '');
if ($ASSET_V === '') { $ASSET_V = appVersionReadHuman(); }
$ASSET_V = preg_replace('/[^A-Za-z0-9._-]/', '', $ASSET_V);
?><!DOCTYPE html>
<html lang="<?= htmlspecialchars($LANG, ENT_QUOTES) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>TableManager 2.2.0 - Modern Web POS</title>
  <?= i18nJs($LANG) ?>
  <script src="js/i18n.js?v=<?= $ASSET_V ?>"></script>
  <link rel="stylesheet" href="css/pos.css?v=<?= $ASSET_V ?>">
  <script>
    /* Scalare UI libera pe AMBELE axe, plecand de la canvasul minim 1024x768.
       --pos-scale = factorul proportional (limitat de axa cu raportul cel mai mic);
       --pos-stage-w/h = dimensiunile NESCALATE ale canvasului, marite peste baza
       (1024x768) doar pe axele unde ecranul e mai "generos", ca surplusul sa fie
       absorbit de zonele flexibile (grila grupe/produse, lista nota, grila mese).
       Astfel canvasul scalat acopera exact fereastra -> fara benzi libere. */
    (function () {
      function fitStage() {
        var w = window.innerWidth, h = window.innerHeight;
        var s = Math.min(w / 1024, h / 768);
        var stageW = Math.max(1024, Math.round(w / s));
        var stageH = Math.max(768, Math.round(h / s));
        var root = document.documentElement;
        root.style.setProperty('--pos-scale', s);
        root.style.setProperty('--pos-stage-w', stageW + 'px');
        root.style.setProperty('--pos-stage-h', stageH + 'px');
      }
      window.addEventListener('resize', fitStage);
      window.addEventListener('orientationchange', fitStage);
      fitStage();
    })();
  </script>
</head>
<body>

  <div id="pos-stage">

  <div class="pos-viewport">
    
    <!-- Antet superior (doar ceasul) -->
    <div class="pos-top-bar">
      <span id="pos-clock-header">29-Jan-26 17:39</span>
    </div>

    <!-- ====================================================================
         ECRAN 1: MARCARE (ecran_marcare.PNG)
         ==================================================================== -->
    <div id="screen-marcare" class="pos-screen">
      <div class="marcare-body">
        
        <!-- Panoul stanga: Nota de Plata -->
        <div class="order-panel">
          
          <div class="order-top-row-1">
            <input type="text" id="scan-input" class="scan-input" placeholder="Scan / cod produs" data-i18n-placeholder="marcare.scan_placeholder" autocomplete="off" onkeydown="onScanKeydown(event)">
            <div class="table-indicator-box">
              <span class="tbl-label" data-i18n="common.masa">Masa</span>
              <div class="tbl-value" id="current-table-btn" onclick="navigateToScreen('screen-mese')">1</div>
            </div>
          </div>

          <div class="order-top-row-2">
            <span class="order-title" data-i18n="marcare.nota_de_plata">NOTA DE PLATA</span>
            <div class="cashier-badge" id="current-cashier-label">CASIER 1</div>
          </div>

          <!-- Tabelul de articole -->
          <div class="order-items-wrapper">
            <div class="order-grid-header">
              <div data-i18n="marcare.col_denumire">Denumire</div>
              <div data-i18n="marcare.col_cant">Cant</div>
              <div data-i18n="marcare.col_valoare">Valoare</div>
            </div>
            
            <div class="order-items-scroll" id="order-items-list">
              <!-- Populata in timp real din tblNoteD -->
            </div>
          </div>

          <!-- Totaluri Nota -->
          <div class="order-summary-box">
            <div class="summary-line">
              <span class="sum-lbl" data-i18n="marcare.subtotal">Subtotal :</span>
              <span class="sum-val" id="val-subtotal">0.00</span>
            </div>
            <div class="summary-line">
              <span class="sum-lbl" data-i18n-html="marcare.reducere_html"><u>R</u>educere :</span>
              <span class="sum-val" id="val-reducere">0.00</span>
            </div>
            <div class="summary-line">
              <span class="sum-lbl" data-i18n="marcare.tva">Tva :</span>
              <span class="sum-val" id="val-tva">0.00</span>
            </div>
            <div class="summary-divider"></div>
            <div class="summary-line grand-total" id="order-grand-total-line">
              <span class="sum-lbl" data-i18n="marcare.total">Total :</span>
              <span class="sum-val" id="val-total">0.00</span>
            </div>
            <!-- Mod FastFood: detaliile de plata apar aici dupa inchiderea notei,
                 in locul liniei Total, pana la inceperea unui nou bon. -->
            <div class="fastfood-receipt" id="fastfood-receipt" style="display:none;"></div>
          </div>

        </div>

        <!-- Săgeți de Scroll Up / Down -->
        <div class="nav-arrows-col">
          <button class="arrow-btn" id="btn-scroll-up" onclick="scrollOrder('up')">▲</button>
          <button class="arrow-btn" id="btn-scroll-down" onclick="scrollOrder('down')">▼</button>
        </div>

        <!-- Panou Dreapta: Meniu Grila 5 coloane (Grupe sau Produse direct pe ecran) -->
        <div class="menu-grid-panel">
          
          <!-- Bara de titlu grupa activa cand suntem in modul de produse -->
          <div id="group-nav-header" style="display: none; justify-content: space-between; align-items: center; margin-bottom: 6px; padding: 0 2px;">
            <button id="btn-group-back" class="act-btn" data-i18n="⬅ INAPOI LA GRUPE" onclick="returnToGroups()" style="height: 38px; background: #ffff00; color: #000; font-weight: 900; font-size: 13px; border: 2px solid #000;">
              ⬅ INAPOI LA GRUPE
            </button>
            <span id="active-group-title" style="color: #ffffff; font-size: 18px; font-weight: 900; letter-spacing: 1px; text-transform: uppercase;">
              MENIU
            </span>
          </div>

          <div class="menu-5x-grid" id="menu-5x-grid">
            <!-- Incarcat dinamic: tblGrp (Mod Grupe) sau tblProd (Mod Produse) -->
          </div>
        </div>

      </div>

      <!-- Bara inferioara de actiuni -->
      <div class="bottom-actions-bar">
        <button class="act-btn" id="btn-marcare" onclick="actionMarcare()" data-i18n="marcare.marcare">Marcare</button>
        <button class="act-btn red-text" onclick="actionNota()" data-i18n="marcare.nota">NOTA</button>
        <button class="act-btn" id="btn-mod-preparare" onclick="actionModPreparare()" data-i18n-html="marcare.mod_preparare_html">Mod<br>Preparare</button>
        <button class="act-btn js-discount-btn" onclick="actionDiscount()" data-i18n="marcare.discount">Discount</button>
        <button class="act-btn vd-btn" onclick="actionVoid()" data-i18n="marcare.vd">VD</button>
        <button class="act-btn" onclick="actionQty()" data-i18n="marcare.qty">QTY</button>
        
        <div class="act-spacer"></div>

        <button class="act-btn" onclick="openKeyboardModal('Parola')" data-i18n="common.parola">Parola</button>
        <button class="act-btn" onclick="openKeyboardModal('Cautare')" data-i18n="marcare.cautare">🔍 Cautare</button>
        <button class="act-btn" id="btn-meniul-zilei" style="display:none;" onclick="actionMeniulZilei()" data-i18n-html="marcare.meniul_zilei_html">Meniul<br>Zilei</button>
        <button class="act-btn" onclick="handleInapoiBtn()" data-i18n="common.inapoi">Inapoi</button>
      </div>
    </div>


    <!-- ====================================================================
         ECRAN 2: INCHIDERE NOTA (Inchidere_nota.PNG)
         ==================================================================== -->
    <div id="screen-plata" class="pos-screen">
      <div class="payment-screen-layout">
        
        <!-- Panoul stanga de plata -->
        <div class="order-panel">
          <div class="order-top-row-1">
            <span class="order-datetime" id="pay-datetime">29-Jan-26 &nbsp;&nbsp; 17:39</span>
            <div class="table-indicator-box">
              <span class="tbl-label" data-i18n="common.masa">Masa</span>
              <div class="tbl-value" id="pay-tbl-num">1</div>
            </div>
          </div>
          <div class="order-top-row-2">
            <span class="order-title" data-i18n="marcare.nota_de_plata">NOTA DE PLATA</span>
            <div class="cashier-badge" id="pay-cashier-label">CASIER 1</div>
          </div>
          <div class="order-items-wrapper">
            <div class="order-grid-header">
              <div data-i18n="marcare.col_denumire">Denumire</div>
              <div data-i18n="marcare.col_cant">Cant</div>
              <div data-i18n="marcare.col_valoare">Valoare</div>
            </div>
            <div class="order-items-scroll" id="pay-items-list"></div>
          </div>
          <div class="order-summary-box">
            <div class="summary-line">
              <span class="sum-lbl" data-i18n="marcare.subtotal">Subtotal :</span>
              <span class="sum-val" id="pay-subtotal">0.00</span>
            </div>
            <div class="summary-line">
              <span class="sum-lbl" data-i18n-html="plata.reducere_pct_html"><u>R</u>educere % :</span>
              <span class="sum-val" id="pay-reducere">0</span>
            </div>
            <div class="summary-line">
              <span class="sum-lbl" data-i18n="marcare.tva">Tva :</span>
              <span class="sum-val" id="pay-tva">0.00</span>
            </div>
            <div class="summary-divider"></div>
            <div class="summary-line grand-total">
              <span class="sum-lbl" data-i18n="marcare.total">Total :</span>
              <span class="sum-val" id="pay-total">0.00</span>
            </div>
          </div>
        </div>

        <!-- Săgeți de Scroll Up / Down -->
        <div class="nav-arrows-col">
          <button class="arrow-btn" onclick="scrollOrder('up')">▲</button>
          <button class="arrow-btn" onclick="scrollOrder('down')">▼</button>
        </div>

        <!-- Panou Dreapta: Moduri de Plata & Tastatura Numerica Mare -->
        <div class="payment-right-panel">
          
          <!-- Moduri de plata: incarcate dinamic din tblFP (Status = 1) -->
          <div class="payment-methods-grid" id="pay-methods-grid"></div>

          <!-- Centru: Afisaj CASH si Numpad mare -->
          <div class="payment-calc-center">
            <div class="payment-display-row">
              <span class="payment-label-cash" id="lbl-active-pay-type">CASH</span>
              <input type="text" class="payment-input-box" id="pay-amount-input" value="0.00" readonly>
            </div>
            <div class="pay-progress" id="pay-progress"></div>

            <!-- Numpad-ul exact ca in screenshot -->
            <div class="pos-numpad-container">
              <button class="numpad-key" onclick="payNumpadKey('7')">7</button>
              <button class="numpad-key" onclick="payNumpadKey('8')">8</button>
              <button class="numpad-key" onclick="payNumpadKey('9')">9</button>
              <button class="numpad-key key-c" onclick="payNumpadClear()">C</button>

              <button class="numpad-key" onclick="payNumpadKey('4')">4</button>
              <button class="numpad-key" onclick="payNumpadKey('5')">5</button>
              <button class="numpad-key" onclick="payNumpadKey('6')">6</button>
              <button class="numpad-key key-ok" onclick="finalizePaymentTransaction()">OK</button>

              <button class="numpad-key" onclick="payNumpadKey('1')">1</button>
              <button class="numpad-key" onclick="payNumpadKey('2')">2</button>
              <button class="numpad-key" onclick="payNumpadKey('3')">3</button>

              <button class="numpad-key key-zero" onclick="payNumpadKey('0')">0</button>
              <button class="numpad-key" onclick="payNumpadKey('.')">.</button>
              <button class="numpad-key" onclick="payNumpadBackspace()">⌫</button>
            </div>
          </div>

        </div>

      </div>

      <!-- Bara de jos pentru plata -->
      <div class="bottom-actions-bar">
        <button class="act-btn" id="btn-transfer" onclick="actionTransfer()" data-i18n="plata.transfer">Transfer</button>
        <button class="act-btn js-discount-btn" onclick="actionDiscount()" data-i18n="marcare.discount">Discount</button>
        <button class="act-btn" id="btn-cui" onclick="openCuiModal()">CUI</button>
        <button class="act-btn" id="btn-proforma" onclick="actionProforma()" data-i18n-html="plata.nota_proforma_html">Nota<br>Proforma</button>
        <div class="act-spacer"></div>
        <button class="act-btn" onclick="navigateToScreen('screen-marcare')" data-i18n="common.inapoi">Inapoi</button>
      </div>
    </div>


    <!-- ====================================================================
         ECRAN 3: SELECTIE MASA (ecran_autentificare_selectie_masa.PNG)
         ==================================================================== -->
    <div id="screen-mese" class="pos-screen active">
      <div class="tables-screen-layout">
        
        <!-- Grila celor 80 de mese -->
        <div class="tables-grid-80" id="tables-grid-container">
          <!-- Incarcat dinamic din tblBonCurent si tblMese -->
        </div>

        <!-- Meniu Dreapta -->
        <div class="tables-right-menu">
          <button class="tables-menu-btn blue-text" onclick="openFacturare()" data-i18n="mese.facturare">Facturare.</button>
          <button class="tables-menu-btn blue-text" id="btn-print-queue" onclick="openPrintQueue()" data-i18n-html="mese.coada_printare_html">Coada<br>printare</button>
          <button class="tables-menu-btn red-text" onclick="openKeyboardModal('Parola')" data-i18n="common.parola">Parola</button>
        </div>

      </div>

      <!-- Footer ecran mese cu sigla si date de contact (tblSet DistrRand1/2) -->
      <div class="tables-footer-info">
        <img src="img/abcicon.ico" alt="abc Soft" class="tables-footer-logo">
        <div>
          <b id="footer-distr1"></b> &nbsp;&nbsp; <span id="footer-distr2"></span>
        </div>
      </div>
    </div>

    <!-- ====================================================================
         ECRAN PROGRAMARE POS (parola din tblParola.ParolaPrg)
         ==================================================================== -->
    <div id="screen-programare" class="pos-screen">
      <div class="prog-header">
        <span data-i18n="prog.titlu">PROGRAMARE POS</span>
        <div style="display:flex; gap:8px; align-items:center;">
          <span id="app-version-label" class="app-version" title="Versiunea aplicatiei"></span>
          <!--button class="act-btn" onclick="toggleFloatingKeyboard()">⌨️ Tastatura</button-->
          <button class="act-btn" onclick="closeProgramming()" data-i18n="common.inapoi">Inapoi</button>
        </div>
      </div>
      <div class="prog-grid" id="prog-grid">
        <button class="prog-btn" onclick="progAction('Setari')" data-i18n="prog.setari">Setari</button>
        <button class="prog-btn" id="prog-btn-grupe" onclick="progAction('Grupe')" data-i18n="prog.grupe">Grupe</button>
        <button class="prog-btn" id="prog-btn-produse" onclick="progAction('Produse')" data-i18n="prog.produse">Produse</button>
        <button class="prog-btn" onclick="progAction('Sectii')" data-i18n="prog.sectii">Sectii</button>
        <button class="prog-btn" onclick="progAction('Programare mese')" data-i18n="prog.programare_mese">Programare mese</button>
        <button class="prog-btn" onclick="progAction('Ospatari')" data-i18n="prog.ospatari">Ospatari</button>
        <button class="prog-btn" onclick="progAction('Moduri preparare')" data-i18n="prog.moduri_preparare">Moduri preparare</button>
        <button class="prog-btn" onclick="progAction('Parole')" data-i18n="prog.parole">Parole</button>
        <button class="prog-btn" onclick="progAction('Antet')" data-i18n="prog.antet">Antet</button>
        <button class="prog-btn" onclick="progAction('Nota')" data-i18n="prog.nota">Nota</button>
        <button class="prog-btn" onclick="progAction('Imprimante sectii')" data-i18n="prog.imprimante">Imprimante sectii</button>
        <button class="prog-btn" onclick="progAction('Forme de plata')" data-i18n="prog.forme_plata">Forme de plata</button>
        <button class="prog-btn" onclick="progAction('Cote TVA')" data-i18n="prog.cote_tva">Cote TVA</button>
        <button class="prog-btn" id="prog-btn-conectare" onclick="progAction('Conectare server')" data-i18n="prog.conectare">Conectare server</button>
        <button class="prog-btn" id="prog-btn-sync" onclick="progAction('Sincronizare produse')" data-i18n="prog.sincronizare">Sincronizare produse</button>
        <button class="prog-btn" id="prog-btn-update" onclick="progAction('Update')" data-i18n="prog.update">Update</button>
        <button class="prog-btn" id="prog-btn-rollback" onclick="openRollback()" data-i18n="prog.rollback">Rollback</button>
        <button class="prog-btn" onclick="openPrintQueue()" data-i18n="prog.coada_printare">Coada printare</button>
        <button class="prog-btn" id="prog-btn-send-queue" onclick="openSendQueue()" data-i18n="prog.coada_export">Coada export</button>
      </div>
    </div>

    <!-- ECRAN SETARI (tblSet): taburi General / Device-uri / Imprimante / Altele -->
    <div id="screen-prog-setari" class="pos-screen">
      <div class="prog-header">
        <span data-i18n="setari.titlu">SETARI</span>
        <div style="display:flex; gap:8px;">
          <button class="help-btn" title="Ajutor" data-i18n-title="Ajutor" aria-label="Ajutor" onclick="openHelp('setari')">?</button>
          <button class="act-btn" id="setari-save-btn" onclick="saveSetari()" style="background:#ffff00;" data-i18n="common.salveaza">Salveaza</button>
          <button class="act-btn" onclick="closeProgSetari()" data-i18n="common.inapoi">Inapoi</button>
        </div>
      </div>
      <div class="setari-tabs" id="setari-tabs"></div>
      <div class="setari-content" id="setari-content"></div>
    </div>

    <!-- ECRAN ANTET (tblAntet, Seria '0001') -->
    <div id="screen-prog-antet" class="pos-screen">
      <div class="prog-header">
        <span data-i18n="ANTET">ANTET</span>
        <div style="display:flex; gap:8px;">
          <button class="help-btn" title="Ajutor" data-i18n-title="Ajutor" aria-label="Ajutor" onclick="openHelp('antet')">?</button>
          <button class="act-btn" onclick="saveAntet()" style="background:#ffff00;" data-i18n="Salveaza">Salveaza</button>
          <button class="act-btn" onclick="closeProgAntet()" data-i18n="Inapoi">Inapoi</button>
        </div>
      </div>
      <div class="setari-content" id="antet-content">
        <div class="antet-grid">
          <div class="tva-field">
            <label data-i18n="Nume">Nume</label>
            <div class="tva-field-row">
              <input type="text" id="antet-nume" class="tva-input" maxlength="40">
              <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('antet-nume')">⌨️</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="Denumire2">Denumire2</label>
            <div class="tva-field-row">
              <input type="text" id="antet-den2" class="tva-input" maxlength="40">
              <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('antet-den2')">⌨️</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="Adresa">Adresa</label>
            <div class="tva-field-row">
              <input type="text" id="antet-adresa" class="tva-input" maxlength="40">
              <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('antet-adresa')">⌨️</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="Oras">Oras</label>
            <div class="tva-field-row">
              <input type="text" id="antet-oras" class="tva-input" maxlength="50">
              <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('antet-oras')">⌨️</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="CodPostal">CodPostal</label>
            <div class="tva-field-row">
              <input type="text" id="antet-codpostal" class="tva-input" maxlength="10">
              <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('antet-codpostal')">⌨️</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="PersContact">PersContact</label>
            <div class="tva-field-row">
              <input type="text" id="antet-perscontact" class="tva-input" maxlength="100">
              <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('antet-perscontact')">⌨️</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="email">email</label>
            <div class="tva-field-row">
              <input type="text" id="antet-email" class="tva-input" maxlength="50">
              <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('antet-email')">⌨️</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="website">website</label>
            <div class="tva-field-row">
              <input type="text" id="antet-website" class="tva-input" maxlength="50">
              <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('antet-website')">⌨️</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="telefon">telefon</label>
            <div class="tva-field-row">
              <input type="text" id="antet-telefon" class="tva-input" maxlength="50">
              <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('antet-telefon')">⌨️</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="CodFisca">CodFisca</label>
            <div class="tva-field-row">
              <input type="text" id="antet-codfisc" class="tva-input" maxlength="40">
              <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('antet-codfisc')">⌨️</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="RegCom">RegCom</label>
            <div class="tva-field-row">
              <input type="text" id="antet-regcom" class="tva-input" maxlength="22">
              <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('antet-regcom')">⌨️</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="Judet">Judet</label>
            <div class="tva-field-row">
              <input type="text" id="antet-judet" class="tva-input" maxlength="22">
              <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('antet-judet')">⌨️</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="Cont">Cont</label>
            <div class="tva-field-row">
              <input type="text" id="antet-cont" class="tva-input" maxlength="22">
              <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('antet-cont')">⌨️</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="Banca">Banca</label>
            <div class="tva-field-row">
              <input type="text" id="antet-banca" class="tva-input" maxlength="40">
              <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('antet-banca')">⌨️</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="PlatitorTVA">PlatitorTVA</label>
            <button class="act-btn" data-i18n="common.nu" id="antet-platitor-toggle" onclick="toggleAntetPlatitor()" style="width:100%;">Nu</button>
          </div>
          <div class="tva-field">
            <label data-i18n="Cale Date logo">Cale Date logo</label>
            <div class="tva-field-row">
              <input type="text" id="antet-calelogo" class="tva-input" maxlength="255">
              <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('antet-calelogo')">⌨️</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="QRCodeText">QRCodeText</label>
            <div class="tva-field-row">
              <input type="text" id="antet-qrcode" class="tva-input" maxlength="255">
              <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('antet-qrcode')">⌨️</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="SizeModeLogo">SizeModeLogo</label>
            <div class="tva-field-row">
              <input type="text" id="antet-sizemode" class="tva-input">
              <button class="act-btn fkb-mini" onclick="openNumericKeyboardFor('antet-sizemode', 'SIZEMODELOGO')">123</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ECRAN NOTA (tblAntet, liniile H1-H3 / F1-F2) -->
    <div id="screen-prog-nota" class="pos-screen">
      <div class="prog-header">
        <span data-i18n="prog.nota">NOTA</span>
        <div style="display:flex; gap:8px;">
          <button class="help-btn" title="Ajutor" data-i18n-title="Ajutor" aria-label="Ajutor" onclick="openHelp('nota')">?</button>
          <button class="act-btn" onclick="saveNota()" style="background:#ffff00;" data-i18n="Salveaza">Salveaza</button>
          <button class="act-btn" onclick="closeProgNota()" data-i18n="Inapoi">Inapoi</button>
        </div>
      </div>
      <div class="setari-content" id="nota-content"></div>
    </div>

    <!-- ECRAN CONECTARE SERVER (tblConectare, un singur rand) -->
    <div id="screen-prog-conectare" class="pos-screen">
      <div class="prog-header">
        <span data-i18n="prog.conectare">CONECTARE SERVER</span>
        <div style="display:flex; gap:8px;">
          <button class="help-btn" title="Ajutor" data-i18n-title="Ajutor" aria-label="Ajutor" onclick="openHelp('conectare')">?</button>
          <button class="act-btn" onclick="testConectare()" data-i18n="Testeaza conexiunea">Testeaza conexiunea</button>
          <button class="act-btn" onclick="saveConectare()" style="background:#ffff00;" data-i18n="Salveaza">Salveaza</button>
          <button class="act-btn" onclick="closeProgConectare()" data-i18n="Inapoi">Inapoi</button>
        </div>
      </div>
      <div class="setari-content">
        <div class="antet-grid">
          <div class="tva-field">
            <label data-i18n="ServerIP">ServerIP</label>
            <div class="tva-field-row">
              <input type="text" id="con-serverip" class="tva-input" maxlength="50">
              <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('con-serverip')">⌨️</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="ServerName">ServerName</label>
            <div class="tva-field-row">
              <input type="text" id="con-servername" class="tva-input" maxlength="50">
              <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('con-servername')">⌨️</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="DataBaseName">DataBaseName</label>
            <div class="tva-field-row">
              <input type="text" id="con-dbname" class="tva-input" maxlength="50">
              <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('con-dbname')">⌨️</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="UserName">UserName</label>
            <div class="tva-field-row">
              <input type="text" id="con-user" class="tva-input" maxlength="50">
              <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('con-user')">⌨️</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="Password">Password</label>
            <div class="tva-field-row">
              <input type="text" id="con-pass" class="tva-input" maxlength="50">
              <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('con-pass')">⌨️</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="TC (Trusted Connection)">TC (Trusted Connection)</label>
            <select id="con-tc" class="tva-input">
              <option value="0" data-i18n="common.nu">Nu</option>
              <option value="1" data-i18n="common.da">Da</option>
            </select>
          </div>
          <div class="tva-field" style="grid-column: 1 / -1;">
            <label data-i18n="ODBC_connect_string">ODBC_connect_string</label>
            <div class="tva-field-row">
              <input type="text" id="con-odbc" class="tva-input" maxlength="255">
              <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('con-odbc')">⌨️</button>
            </div>
          </div>
        </div>

        <div class="antet-grid" style="margin-top:16px;">
          <div class="tva-field" style="grid-column: 1 / -1;">
            <label data-i18n="Mapare sectii de tiparire (KP): server -> local">Mapare sectii de tiparire (KP): server &#8594; local</label>
            <div style="font-size:12px;color:#555;margin:4px 0 6px;" data-i18n="La importul produselor, sectia de tiparire din server (tblProd.KP) este inlocuita cu sectia locala aleasa. Daca o valoare nu are mapare, se foloseste valoarea din server.">
              La importul produselor, sectia de tiparire din server (tblProd.KP) este inlocuita cu sectia locala aleasa.
              Daca o valoare nu are mapare, se foloseste valoarea din server.
            </div>
            <div id="map-server-error" style="display:none;font-size:12px;color:#b00;margin-bottom:6px;"></div>
            <div id="map-kp-list" style="margin-top:6px;"></div>
            <button class="act-btn" style="margin-top:8px;" onclick="addMapKpRow()" data-i18n="+ Adauga mapare KP">+ Adauga mapare KP</button>
          </div>
          <div class="tva-field" style="grid-column: 1 / -1;">
            <label data-i18n="Mapare sectii: server -> local">Mapare sectii: server &#8594; local</label>
            <div style="font-size:12px;color:#555;margin:4px 0 6px;" data-i18n="La importul produselor, sectia din server (tblProd.Sectie) este inlocuita cu sectia locala aleasa. Daca o valoare nu are mapare, se foloseste valoarea din server.">
              La importul produselor, sectia din server (tblProd.Sectie) este inlocuita cu sectia locala aleasa.
              Daca o valoare nu are mapare, se foloseste valoarea din server.
            </div>
            <div id="map-sectii-list" style="margin-top:6px;"></div>
            <button class="act-btn" style="margin-top:8px;" onclick="addMapSectiiRow()" data-i18n="+ Adauga mapare sectie">+ Adauga mapare sectie</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ECRAN PAROLE (tblParola, un singur rand) -->
    <div id="screen-prog-parole" class="pos-screen">
      <div class="prog-header">
        <span data-i18n="prog.parole">PAROLE</span>
        <div style="display:flex; gap:8px;">
          <button class="help-btn" title="Ajutor" data-i18n-title="Ajutor" aria-label="Ajutor" onclick="openHelp('parole')">?</button>
          <button class="act-btn" onclick="saveParole()" style="background:#ffff00;" data-i18n="Salveaza">Salveaza</button>
          <button class="act-btn" onclick="closeProgParole()" data-i18n="Inapoi">Inapoi</button>
        </div>
      </div>
      <div class="setari-content">
        <div style="font-size:13px; color:#555; margin-bottom:10px;" data-i18n="Parolele sunt numerice. Lasati gol un camp daca nu doriti ca parola respectiva sa fie ceruta.">
          Parolele sunt numerice. Lasati gol un camp daca nu doriti ca parola respectiva sa fie ceruta.
        </div>
        <div class="antet-grid">
          <div class="tva-field">
            <label data-i18n="Parola programare">Parola programare</label>
            <div class="tva-field-row">
              <input type="text" id="par-programare" class="tva-input" maxlength="20">
              <button class="act-btn fkb-mini" onclick="openNumericKeyboardFor('par-programare', 'PAROLA PROGRAMARE')">123</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="Parola rapoarte">Parola rapoarte</label>
            <div class="tva-field-row">
              <input type="text" id="par-rapoarte" class="tva-input" maxlength="20">
              <button class="act-btn fkb-mini" onclick="openNumericKeyboardFor('par-rapoarte', 'PAROLA RAPOARTE')">123</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="Parola stornare">Parola stornare</label>
            <div class="tva-field-row">
              <input type="text" id="par-stornare" class="tva-input" maxlength="20">
              <button class="act-btn fkb-mini" onclick="openNumericKeyboardFor('par-stornare', 'PAROLA STORNARE')">123</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="Parola discount">Parola discount</label>
            <div class="tva-field-row">
              <input type="text" id="par-discount" class="tva-input" maxlength="20">
              <button class="act-btn fkb-mini" onclick="openNumericKeyboardFor('par-discount', 'PAROLA DISCOUNT')">123</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="Parola inchidere aplicatie">Parola inchidere aplicatie</label>
            <div class="tva-field-row">
              <input type="text" id="par-exit" class="tva-input" maxlength="20">
              <button class="act-btn fkb-mini" onclick="openNumericKeyboardFor('par-exit', 'PAROLA INCHIDERE')">123</button>
            </div>
          </div>
          <div class="tva-field">
            <label data-i18n="Parola update">Parola update</label>
            <div class="tva-field-row">
              <input type="text" id="par-update" class="tva-input" maxlength="20">
              <button class="act-btn fkb-mini" onclick="openNumericKeyboardFor('par-update', 'PAROLA UPDATE')">123</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ECRAN RAPOARTE (taburi X / Z, accesat cu parola de rapoarte) -->
    <div id="screen-rapoarte" class="pos-screen">
      <div class="prog-header">
        <span data-i18n="RAPOARTE">RAPOARTE</span>
        <div style="display:flex; gap:8px;">
          <button class="act-btn" onclick="closeReports()" data-i18n="Inapoi">Inapoi</button>
        </div>
      </div>
      <div class="setari-tabs" id="rapoarte-tabs"></div>
      <div class="setari-content">
        <div class="rapoarte-grid" id="rapoarte-grid"></div>
      </div>
    </div>

    <!-- ECRAN FACTURARE POS (emulare ecran facturare aplicatie VBA) -->
    <div id="screen-facturare" class="pos-screen">
      <div class="fact-screen">
        <div class="fact-titlebar">
          <span data-i18n="FACTURARE POS">FACTURARE POS</span>
          <button class="fact-close-btn" onclick="closeFacturare()" data-i18n="Inchide">Inchide</button>
        </div>
        <div class="fact-redline"></div>

        <div class="fact-body">
          <div class="fact-form">
            <div class="fact-cif-title" data-i18n="Introduceti CIF:">Introduceti CIF:</div>

            <div class="fact-field-row">
              <label class="fact-lbl" data-i18n="Cod Fiscal (CIF) *">Cod Fiscal (CIF) *</label>
              <input type="text" class="fact-input fact-input-cif" id="fact-cif" maxlength="20" autocomplete="off">
              <label class="fact-lbl fact-vat-lbl">VAT:</label>
              <input type="text" class="fact-input fact-input-vat" id="fact-vat" maxlength="1" readonly autocomplete="off">
              <button type="button" class="fact-anaf-btn" id="fact-anaf-btn" onclick="interogareANAF()" data-i18n="Interogare ANAF">Interogare ANAF</button>
            </div>
            <div class="fact-field-row">
              <label class="fact-lbl" data-i18n="Denumire Firma: *">Denumire Firma: *</label>
              <input type="text" class="fact-input fact-input-long" id="fact-den" maxlength="100" autocomplete="off">
            </div>
            <div class="fact-field-row">
              <label class="fact-lbl" data-i18n="Adresa: *">Adresa: *</label>
              <input type="text" class="fact-input fact-input-long" id="fact-adresa" maxlength="120" autocomplete="off">
            </div>
            <div class="fact-field-row">
              <label class="fact-lbl" data-i18n="Judet: *">Judet: *</label>
              <input type="text" class="fact-input fact-input-short" id="fact-judet" maxlength="40" autocomplete="off">
            </div>
            <div class="fact-field-row">
              <label class="fact-lbl" data-i18n="Oras:">Oras:</label>
              <input type="text" class="fact-input fact-input-short" id="fact-oras" maxlength="40" autocomplete="off">
            </div>
            <div class="fact-field-row">
              <label class="fact-lbl" data-i18n="RegCom:">RegCom:</label>
              <input type="text" class="fact-input fact-input-short" id="fact-regcom" maxlength="40" autocomplete="off">
            </div>

            <div class="fact-oblig" data-i18n="Campurile marcate cu steluta * sunt obligatorii">Campurile marcate cu steluta * sunt obligatorii</div>
          </div>

          <div class="fact-right">
            <label class="fact-lbl" data-i18n="Selectati bonul:">Selectati bonul:</label>
            <button type="button" class="fact-bon-select" id="fact-bon-select" onclick="openFacturaBonList()">
              <span id="fact-bon-label">-</span>
              <span class="fact-bon-caret">&#9660;</span>
            </button>
          </div>
        </div>

        <div class="fact-generate-row">
          <button class="fact-generate-btn" onclick="generazaFactura()" data-i18n="Generaza Factura">Generaza Factura</button>
        </div>
      </div>
    </div>

    <!-- Modal SELECTIE BON pentru facturare (note inchise, ziua curenta) -->
    <div class="modal-keyboard-overlay" id="modal-factura-bon">
      <div class="transfer-dialog" style="width: 640px;">
        <div class="keyboard-dialog-header">
          <span data-i18n="SELECTATI BONUL">SELECTATI BONUL</span>
          <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeFacturaBonList()" data-i18n="Inchide">Inchide</button>
        </div>
        <div class="transfer-body">
          <div class="transfer-subtitle" data-i18n="Note inchise in ziua curenta">Note inchise in ziua curenta</div>
          <div class="printq-wrap">
            <table class="printq-table">
              <colgroup>
                <col style="width: 90px;">
                <col style="width: 110px;">
                <col>
                <col style="width: 70px;">
              </colgroup>
              <thead>
                <tr>
                  <th>NrNota</th>
                  <th data-i18n="Suma">Suma</th>
                  <th data-i18n="Ospatar">Ospatar</th>
                  <th data-i18n="Masa">Masa</th>
                </tr>
              </thead>
              <tbody id="fact-bon-list"></tbody>
            </table>
          </div>
          <div class="tva-actions">
            <button class="act-btn" onclick="loadFacturaBonuri()" data-i18n="Reincarca">Reincarca</button>
            <button class="act-btn" style="background:#ffff00;" id="btn-fact-bon-select" onclick="confirmFacturaBon()" disabled data-i18n="Selecteaza">Selecteaza</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ECRAN PROGRAMARE PRODUSE (tblProd): grila live 5x10 pe grupa + inspector -->
    <div id="screen-prog-produse" class="pos-screen">
      <div class="prog-header">
        <span data-i18n="PROGRAMARE PRODUSE">PROGRAMARE PRODUSE</span>
        <div style="display:flex; gap:8px; align-items:center;">
          <button class="help-btn" title="Ajutor" data-i18n-title="Ajutor" aria-label="Ajutor" onclick="openHelp('produse')">?</button>
          <span style="font-size:14px; color:#fff; font-weight:bold;" data-i18n="Grupa:">Grupa:</span>
          <select id="prod-grup-select" class="prod-combo" onchange="onProdGrupChange()"></select>
          <button class="act-btn" onclick="reloadProduse(true)" data-i18n="Reload">Reload</button>
          <button class="act-btn" onclick="closeProgProduse()" data-i18n="Inapoi">Inapoi</button>
        </div>
      </div>
      <div class="mese-prog-layout">
        <!-- Grila live 5x10 (produsele grupei selectate) -->
        <div class="mese-map-wrap">
          <div class="menu-5x-grid" id="produse-prog-grid"></div>
          <div class="mese-map-hint">Selecteaza un produs de pe grila pentru a-l edita. Sloturile punctate sunt pozitii libere.</div>
        </div>

        <!-- Inspector -->
        <div class="mese-inspector" id="prod-inspector">
          <div class="mese-insp-head">
            <span id="prod-insp-title" data-i18n="PRODUS">PRODUS</span>
            <span id="prod-dirty-badge" style="display:none; color:#b00000;" data-i18n="* nesalvat">* nesalvat</span>
          </div>

          <div id="prod-empty-msg" class="mese-empty-msg" style="display:none;" data-i18n="Alege o grupa mai sus, apoi selecteaza un produs sau o pozitie libera.">
            Alege o grupa mai sus, apoi selecteaza un produs sau o pozitie libera.
          </div>

          <div id="prod-form">
            <div class="tva-field">
              <label data-i18n="ProdID (editabil la adaugare)">ProdID (editabil la adaugare)</label>
              <div class="tva-field-row">
                <input type="text" id="prod-key-input" class="tva-input" value="">
                <button class="act-btn fkb-mini" onclick="openNumericKeyboardFor('prod-key-input', 'PRODID')">123</button>
              </div>
            </div>

            <div class="tva-field">
              <label data-i18n="BarCod">BarCod</label>
              <div class="tva-field-row">
                <input type="text" id="prod-barcod-input" class="tva-input" value="" maxlength="15">
                <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('prod-barcod-input')">⌨️</button>
              </div>
            </div>

            <div class="tva-field">
              <label data-i18n="Denumire">Denumire</label>
              <div class="tva-field-row">
                <input type="text" id="prod-den-input" class="tva-input" value="" maxlength="32">
                <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('prod-den-input')">⌨️</button>
              </div>
            </div>

            <div class="tva-field">
              <label data-i18n="Grupa (NrGrp)">Grupa (NrGrp)</label>
              <select id="prod-grup-field" class="prod-combo" onchange="markProdDirtyFromField()"></select>
            </div>

            <div class="tva-field">
              <label data-i18n="Magazie (MagID)">Magazie (MagID)</label>
              <div id="prod-magid-field"></div>
            </div>

            <div class="mese-toggle-row">
              <div class="tva-field">
                <label data-i18n="UM">UM</label>
                <div class="tva-field-row">
                  <input type="text" id="prod-um-input" class="tva-input" value="" maxlength="3">
                  <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('prod-um-input')">⌨️</button>
                </div>
              </div>
              <div class="tva-field">
                <label data-i18n="Imprimanta KP">Imprimanta KP</label>
                <select id="prod-kp-select" class="prod-combo" onchange="markProdDirtyFromField()"></select>
              </div>
            </div>

            <div class="prod-line-2">
              <div class="tva-field prod-line-2-2">
                <label data-i18n="Sectie">Sectie</label>
                <select id="prod-sectie-select" class="prod-combo" onchange="markProdDirtyFromField()"></select>
              </div>
              <div class="tva-field prod-line-2-1">
                <label data-i18n="Cota TVA">Cota TVA</label>
                <select id="prod-tva-select" class="prod-combo" onchange="markProdDirtyFromField()"></select>
              </div>
            </div>

            <div class="tva-field">
              <label data-i18n="Pret (PV)">Pret (PV)</label>
              <div class="tva-field-row">
                <input type="text" id="prod-pv-input" class="tva-input" value="">
                <button class="act-btn fkb-mini" onclick="openNumericKeyboardFor('prod-pv-input', 'PRET (PV)')">123</button>
              </div>
            </div>

            <div class="mese-toggle-row">
              <div class="tva-field">
                <label data-i18n="Poz (1-50)">Poz (1-50)</label>
                <div class="tva-field-row">
                  <input type="text" id="prod-poz-input" class="tva-input" value="">
                  <button class="act-btn fkb-mini" onclick="openNumericKeyboardFor('prod-poz-input', 'POZ')">123</button>
                </div>
              </div>
              <div class="tva-field">
                <label data-i18n="FontSize">FontSize</label>
                <div class="tva-field-row">
                  <input type="text" id="prod-size-input" class="tva-input" value="">
                  <button class="act-btn fkb-mini" onclick="openNumericKeyboardFor('prod-size-input', 'FONTSIZE')">123</button>
                </div>
              </div>
            </div>

            <div class="tva-field">
              <label data-i18n="Culoare fundal">Culoare fundal</label>
              <div class="mese-swatches" id="prod-bg-swatches"></div>
              <div class="tva-field-row">
                <input type="color" id="prod-bg-custom" class="mese-color-input" value="#ffffff">
                <span class="mese-color-extra" data-i18n="Culoare proprie">Culoare proprie</span>
              </div>
            </div>

            <div class="tva-field">
              <label data-i18n="Culoare text">Culoare text</label>
              <div class="mese-swatches" id="prod-fg-swatches"></div>
              <div class="tva-field-row">
                <input type="color" id="prod-fg-custom" class="mese-color-input" value="#000000">
                <span class="mese-color-extra" data-i18n="Culoare proprie">Culoare proprie</span>
              </div>
            </div>

            <div class="tva-field">
              <label data-i18n="FontType">FontType</label>
              <div class="tva-field-row">
                <input type="text" id="prod-font-input" class="tva-input" value="" placeholder="ex: Arial">
                <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('prod-font-input')">⌨️</button>
              </div>
            </div>

            <div class="tva-field">
              <label data-i18n="Imagine">Imagine</label>
              <div class="tva-field-row">
                <input type="text" id="prod-imagine-input" class="tva-input" value="">
                <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('prod-imagine-input')">⌨️</button>
              </div>
            </div>

            <div class="mese-toggle-row">
              <div class="tva-field">
                <label data-i18n="IRP">IRP</label>
                <div class="tva-field-row">
                  <input type="text" id="prod-irp-input" class="tva-input" value="">
                  <button class="act-btn fkb-mini" onclick="openNumericKeyboardFor('prod-irp-input', 'IRP')">123</button>
                </div>
              </div>
              <div class="tva-field">
                <label data-i18n="IIVCN">IIVCN</label>
                <div class="tva-field-row">
                  <input type="text" id="prod-iivcn-input" class="tva-input" value="">
                  <button class="act-btn fkb-mini" onclick="openNumericKeyboardFor('prod-iivcn-input', 'IIVCN')">123</button>
                </div>
              </div>
            </div>

            <div class="mese-toggle-row">
              <div class="tva-field">
                <label data-i18n="Bold">Bold</label>
                <button class="act-btn" data-i18n="common.nu" id="prod-bold-toggle" onclick="toggleProdBold()" style="width:100%;">Nu</button>
              </div>
              <div class="tva-field">
                <label data-i18n="Meniul Zilei (MZ)">Meniul Zilei (MZ)</label>
                <button class="act-btn" data-i18n="common.nu" id="prod-mz-toggle" onclick="toggleProdMz()" style="width:100%;">Nu</button>
              </div>
            </div>

            <div class="tva-actions">
              <button class="act-btn" id="prod-save-btn" style="background:#ffff00;" data-i18n="Salveaza">Salveaza</button>
              <button class="act-btn" id="prod-del-btn" onclick="deleteProdusSelected()" data-i18n="Sterge">Sterge</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ECRAN PROGRAMARE GRUPE (tblGrp): grila live 5x10 + inspector -->
    <div id="screen-prog-grupe" class="pos-screen">
      <div class="prog-header">
        <span data-i18n="PROGRAMARE GRUPE">PROGRAMARE GRUPE</span>
        <div style="display:flex; gap:8px;">
          <button class="help-btn" title="Ajutor" data-i18n-title="Ajutor" aria-label="Ajutor" onclick="openHelp('grupe')">?</button>
          <button class="act-btn" onclick="reloadGrupe(true)" data-i18n="Reload">Reload</button>
          <button class="act-btn" onclick="closeProgGrupe()" data-i18n="Inapoi">Inapoi</button>
        </div>
      </div>
      <div class="mese-prog-layout">
        <!-- Grila live 5x10 (Poz 1..50), ca in meniu -->
        <div class="mese-map-wrap">
          <div class="menu-5x-grid" id="grupe-prog-grid"></div>
          <div class="mese-map-hint">Selecteaza o grupa de pe grila pentru a o edita. Sloturile punctate sunt pozitii libere.</div>
        </div>

        <!-- Inspector -->
        <div class="mese-inspector" id="grup-inspector">
          <div class="mese-insp-head">
            <span id="grup-insp-title" data-i18n="GRUPA">GRUPA</span>
            <span id="grup-dirty-badge" style="display:none; color:#b00000;" data-i18n="* nesalvat">* nesalvat</span>
          </div>

          <div id="grup-empty-msg" class="mese-empty-msg" style="display:none;" data-i18n="Atinge o grupa sau o pozitie libera de pe grila pentru a o edita">
            Atinge o grupa sau o pozitie libera de pe grila pentru a o edita.
          </div>

          <div id="grup-form">
            <div class="tva-field">
              <label data-i18n="NrGrp (editabil la adaugare)">NrGrp (editabil la adaugare)</label>
              <div class="tva-field-row">
                <input type="text" id="grup-key-input" class="tva-input" value="">
                <button class="act-btn fkb-mini" onclick="openNumericKeyboardFor('grup-key-input', 'NRGRP')">123</button>
              </div>
            </div>

            <div class="tva-field">
              <label data-i18n="Denumire">Denumire</label>
              <div class="tva-field-row">
                <input type="text" id="grup-den-input" class="tva-input" value="" maxlength="50">
                <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('grup-den-input')">⌨️</button>
              </div>
            </div>

            <div class="mese-toggle-row">
              <div class="tva-field">
                <label data-i18n="Poz (1-50)">Poz (1-50)</label>
                <div class="tva-field-row">
                  <input type="text" id="grup-poz-input" class="tva-input" value="">
                  <button class="act-btn fkb-mini" onclick="openNumericKeyboardFor('grup-poz-input', 'POZ')">123</button>
                </div>
              </div>
              <div class="tva-field">
                <label data-i18n="FontSize">FontSize</label>
                <div class="tva-field-row">
                  <input type="text" id="grup-size-input" class="tva-input" value="">
                  <button class="act-btn fkb-mini" onclick="openNumericKeyboardFor('grup-size-input', 'FONTSIZE')">123</button>
                </div>
              </div>
            </div>

            <div class="tva-field">
              <label data-i18n="Culoare fundal">Culoare fundal</label>
              <div class="mese-swatches" id="grup-bg-swatches"></div>
              <div class="tva-field-row">
                <input type="color" id="grup-bg-custom" class="mese-color-input" value="#ffffff">
                <span class="mese-color-extra" data-i18n="Culoare proprie">Culoare proprie</span>
              </div>
            </div>

            <div class="tva-field">
              <label data-i18n="Culoare text">Culoare text</label>
              <div class="mese-swatches" id="grup-fg-swatches"></div>
              <div class="tva-field-row">
                <input type="color" id="grup-fg-custom" class="mese-color-input" value="#000000">
                <span class="mese-color-extra" data-i18n="Culoare proprie">Culoare proprie</span>
              </div>
            </div>

            <div class="tva-field">
              <label data-i18n="FontType">FontType</label>
              <div class="tva-field-row">
                <input type="text" id="grup-font-input" class="tva-input" value="" placeholder="ex: Arial">
                <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('grup-font-input')">⌨️</button>
              </div>
            </div>

            <div class="tva-field">
              <label data-i18n="Bold">Bold</label>
              <button class="act-btn" data-i18n="common.nu" id="grup-bold-toggle" onclick="toggleGrupBold()" style="width:100%;">Nu</button>
            </div>

            <div class="tva-actions">
              <button class="act-btn" id="grup-save-btn" style="background:#ffff00;" data-i18n="Salveaza">Salveaza</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ECRAN PROGRAMARE MESE (tblMese): harta live + inspector -->
    <div id="screen-prog-mese" class="pos-screen">
      <div class="prog-header">
        <span data-i18n="PROGRAMARE MESE">PROGRAMARE MESE</span>
        <div style="display:flex; gap:8px;">
          <button class="help-btn" title="Ajutor" data-i18n-title="Ajutor" aria-label="Ajutor" onclick="openHelp('mese')">?</button>
          <button class="act-btn" onclick="resetMese()" style="background:#ff0000;color:#fff;" data-i18n="Reset">Reset</button>
          <button class="act-btn" onclick="reloadMese(true)" data-i18n="Reload">Reload</button>
          <button class="act-btn" onclick="closeProgMese()" data-i18n="Inapoi">Inapoi</button>
        </div>
      </div>
      <div class="mese-prog-layout">
        <!-- Harta live 10x8 -->
        <div class="mese-map-wrap">
          <div class="tables-grid-80" id="mese-prog-grid"></div>
          <div class="mese-map-hint">Selecteaza o masa de pe harta pentru a o edita. Celulele punctate sunt pozitii libere.</div>
        </div>

        <!-- Inspector -->
        <div class="mese-inspector" id="mese-inspector">
          <div class="mese-insp-head">
            <span id="mese-insp-title" data-i18n="MASA">MASA</span>
            <span id="mese-dirty-badge" style="display:none; color:#b00000;" data-i18n="* nesalvat">* nesalvat</span>
          </div>

          <div id="mese-empty-msg" class="mese-empty-msg" style="display:none;">
            Poziție liberă. Apasa <b data-i18n="Adauga masa">Adauga masa</b> pentru a crea o masa aici.
          </div>

          <div id="mese-form">
            <!-- Previzualizare -->
            <div class="mese-prev-row">
              <div class="mese-prev-tile" id="mese-preview"><span>?</span></div>
              <div class="mese-prev-info">
                <div><span class="lab">NrMasa</span></div>
                <div class="mese-nr-readonly" id="mese-nr-display">-</div>
              </div>
            </div>

            <!-- BackColor -->
            <div class="tva-field">
              <label data-i18n="Culoare fundal">Culoare fundal</label>
              <div class="mese-swatches" id="mese-bg-swatches"></div>
              <div class="tva-field-row">
                <input type="color" id="mese-bg-custom" class="mese-color-input" value="#ffffff">
                <span class="mese-color-extra" data-i18n="Culoare proprie">Culoare proprie</span>
              </div>
            </div>

            <!-- ForeColor -->
            <div class="tva-field">
              <label data-i18n="Culoare text">Culoare text</label>
              <div class="mese-swatches" id="mese-fg-swatches"></div>
              <div class="tva-field-row">
                <input type="color" id="mese-fg-custom" class="mese-color-input" value="#000000">
                <span class="mese-color-extra" data-i18n="Culoare proprie">Culoare proprie</span>
              </div>
            </div>

            <!-- Bold / Afisez -->
            <div class="mese-toggle-row">
              <div class="tva-field">
                <label data-i18n="Bold">Bold</label>
                <button class="act-btn" data-i18n="common.nu" id="mese-bold-toggle" onclick="toggleMeseBold()" style="width:100%;">Nu</button>
              </div>
              <div class="tva-field">
                <label data-i18n="Afisez">Afisez</label>
                <button class="act-btn" id="mese-afisez-toggle" onclick="toggleMeseAfisez()" style="width:100%;" data-i18n="Da">Da</button>
              </div>
            </div>

            <!-- Obs -->
            <div class="tva-field">
              <label data-i18n="Observatii (max 10)">Observatii (max 10)</label>
              <div class="tva-field-row">
                <input type="text" id="mese-obs-input" class="tva-input" value="" maxlength="10">
                <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('mese-obs-input')">⌨️</button>
              </div>
            </div>

            <!-- Salveaza: doar in sectiunea de editare a mesei -->
            <div class="tva-actions">
              <button class="act-btn" id="mese-save-btn" style="background:#ffff00;" data-i18n="Salveaza">Salveaza</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ====================================================================
         ECRAN: IMPRIMANTE (sectii logice tblKP + destinatii fizice)
         ==================================================================== -->
    <div id="screen-prog-imprimante" class="pos-screen">
      <div class="prog-header">
        <span data-i18n="IMPRIMANTE">IMPRIMANTE</span>
        <div style="display:flex; gap:8px; align-items:center;">
          <button class="help-btn" title="Ajutor" data-i18n-title="Ajutor" aria-label="Ajutor" onclick="openHelp('imprimante')">?</button>
          <span id="impr-service-badge" class="impr-badge off" data-i18n="Serviciu: ...">Serviciu: ...</span>
          <button class="act-btn" onclick="reloadImprimante()" data-i18n="Reload">Reload</button>
          <button class="act-btn" style="background:#ffff00;" onclick="saveImprimante()" data-i18n="Salveaza">Salveaza</button>
          <button class="act-btn" onclick="closeProgImprimante()" data-i18n="Inapoi">Inapoi</button>
        </div>
      </div>
      <div class="impr-layout">
        <div class="impr-list-panel">
          <div class="impr-panel-title" data-i18n="Sectii de tiparire">Sectii de tiparire</div>
          <div class="impr-list" id="impr-list"></div>
          <div class="tva-actions">
            <button class="act-btn" style="background:#ffff00;" onclick="imprAddSection()" data-i18n="Adauga">Adauga</button>
            <button class="act-btn" id="impr-del-btn" onclick="imprDeleteSection()" data-i18n="Sterge">Sterge</button>
          </div>
        </div>
        <div class="impr-inspector">
          <div id="impr-insp-body"></div>
        </div>
        <div class="impr-global">
          <div id="impr-global-body"></div>
        </div>
      </div>
    </div>

  </div>


  <!-- ====================================================================
       MODAL TASTATURA TACTILA / AUTENTIFICARE (ecran_autentificare_selectie_masa2.PNG)
       ==================================================================== -->
  <div class="modal-keyboard-overlay" id="modal-keyboard">
    <div class="keyboard-dialog">
      <div class="keyboard-dialog-header">
        <span id="kb-title" data-i18n="AUTENTIFICARE">AUTENTIFICARE</span>
        <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeKeyboardModal()" data-i18n="Inchide">Inchide</button>
      </div>
      
      <div class="keyboard-body">
        <div id="kb-subtitle" style="font-size: 20px; font-weight: bold; color: #333;" data-i18n="Introduceti parola">Introduceti parola</div>
        
        <input type="password" id="virtual-keyboard-input" class="keyboard-input-display" readonly>

        <div class="keyboard-keys-container">
          <!-- Sectiunea QWERTY -->
          <div id="vk-rows" class="qwerty-section"></div>

          <!-- Sectiunea Numpad din tastatura tactila -->
          <div class="numpad-section">
            <button class="numpad-key" onclick="vkeyInput('7')">7</button>
            <button class="numpad-key" onclick="vkeyInput('8')">8</button>
            <button class="numpad-key" onclick="vkeyInput('9')">9</button>
            <button class="numpad-key key-c" onclick="vkeyClear()">C</button>

            <button class="numpad-key" onclick="vkeyInput('4')">4</button>
            <button class="numpad-key" onclick="vkeyInput('5')">5</button>
            <button class="numpad-key" onclick="vkeyInput('6')">6</button>
            <button class="numpad-key key-ok" onclick="vkeyValidate()">OK</button>

            <button class="numpad-key" onclick="vkeyInput('1')">1</button>
            <button class="numpad-key" onclick="vkeyInput('2')">2</button>
            <button class="numpad-key" onclick="vkeyInput('3')">3</button>

            <button class="numpad-key key-zero" style="grid-column: span 3;" onclick="vkeyInput('0')">0</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal TASTATURA VIRTUALA FLOTANTA (scrie in campul cu focus) -->
  <div class="modal-keyboard-overlay" id="modal-fkb">
    <div class="keyboard-dialog fkb-dialog" id="fkb-dialog">
      <div class="keyboard-dialog-header">
        <span data-i18n="TASTATURA VIRTUALA">TASTATURA VIRTUALA</span>
        <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeFloatingKeyboard()" data-i18n="Ascunde">Ascunde</button>
      </div>
      <div class="keyboard-body">
        <div style="font-size: 12px; color: #333;" data-i18n="Se scrie in campul care are focusul (folosita cand nu exista tastatura fizica)">Se scrie in campul care are focusul (folosita cand nu exista tastatura fizica)</div>

        <div id="fkb-rows" class="keyboard-keys-container"></div>
      </div>
    </div>
  </div>

  <!-- Modal COTE TVA: lista cotelor -->
  <div class="modal-keyboard-overlay" id="modal-tva">
    <div class="transfer-dialog">
      <div class="keyboard-dialog-header">
        <span data-i18n="COTE TVA">COTE TVA</span>
        <div class="help-header-actions">
          <button class="help-btn" title="Ajutor" data-i18n-title="Ajutor" aria-label="Ajutor" onclick="openHelp('tva')">?</button>
          <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeTVAModal()" data-i18n="Inchide">Inchide</button>
        </div>
      </div>
      <div class="transfer-body">
        <div class="transfer-subtitle" data-i18n="Atinge o cota pentru editare/stergere">Atinge o cota pentru editare/stergere</div>
        <div class="tva-list-header">
          <span>Nr_TVA</span>
          <span data-i18n="Cota">Cota</span>
        </div>
        <div class="tva-list" id="tva-list"></div>
        <div class="tva-actions">
          <button class="act-btn" style="background:#ffff00;" onclick="openTVAInsert()" data-i18n="Adauga">Adauga</button>
          <button class="act-btn" id="btn-tva-edit" onclick="openTVAEdit()" data-i18n="Editeaza">Editeaza</button>
          <button class="act-btn" id="btn-tva-del" onclick="deleteTVA()" data-i18n="Sterge">Sterge</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal COTE TVA: formular de editare (insert/update) cu tastatura numerica -->
  <div class="modal-keyboard-overlay" id="modal-tva-edit">
    <div class="transfer-dialog">
      <div class="keyboard-dialog-header">
        <span id="tva-edit-title" data-i18n="COTA TVA">COTA TVA</span>
        <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeTVAEdit()" data-i18n="Inapoi">Inapoi</button>
      </div>
      <div class="transfer-body">
        <div class="tva-field">
          <label data-i18n="Nr_TVA">Nr_TVA</label>
          <input type="text" id="tva-key-input" class="tva-input" value="">
        </div>
        <div class="tva-field">
          <label data-i18n="Cota (%)">Cota (%)</label>
          <input type="text" id="tva-val-input" class="tva-input" value="">
        </div>

        <div class="qty-numpad">
          <button class="qty-key" onclick="tvaNumKey('7')">7</button>
          <button class="qty-key" onclick="tvaNumKey('8')">8</button>
          <button class="qty-key" onclick="tvaNumKey('9')">9</button>
          <button class="qty-key qty-fn" onclick="tvaBackspace()">⌫</button>
          <button class="qty-key" onclick="tvaNumKey('4')">4</button>
          <button class="qty-key" onclick="tvaNumKey('5')">5</button>
          <button class="qty-key" onclick="tvaNumKey('6')">6</button>
          <button class="qty-key qty-fn" onclick="tvaClear()">C</button>
          <button class="qty-key" onclick="tvaNumKey('1')">1</button>
          <button class="qty-key" onclick="tvaNumKey('2')">2</button>
          <button class="qty-key" onclick="tvaNumKey('3')">3</button>
          <button class="qty-key qty-fn" onclick="tvaNumKey('.')">.</button>
          <button class="qty-key qty-zero" onclick="tvaNumKey('0')">0</button>
          <button class="qty-key qty-ok" onclick="saveTVA()">OK</button>
        </div>

        <div class="tva-actions">
          <button class="act-btn" onclick="saveTVA()" style="background:#ffff00;" data-i18n="Salveaza">Salveaza</button>
          <button class="act-btn" onclick="closeTVAEdit()" data-i18n="Anuleaza">Anuleaza</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal MODURI PREPARARE: lista modurilor -->
  <div class="modal-keyboard-overlay" id="modal-mesaje">
    <div class="transfer-dialog">
      <div class="keyboard-dialog-header">
        <span data-i18n="MODURI PREPARARE">MODURI PREPARARE</span>
        <div class="help-header-actions">
          <button class="help-btn" title="Ajutor" data-i18n-title="Ajutor" aria-label="Ajutor" onclick="openHelp('mesaje')">?</button>
          <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeMesajeModal()" data-i18n="Inchide">Inchide</button>
        </div>
      </div>
      <div class="transfer-body">
        <div class="transfer-subtitle" data-i18n="Atinge un mod pentru editare/stergere">Atinge un mod pentru editare/stergere</div>
        <div class="tva-list-header">
          <span data-i18n="Id">Id</span>
          <span data-i18n="Mesaj">Mesaj</span>
        </div>
        <div class="tva-list" id="mesaje-list"></div>
        <div class="tva-actions">
          <button class="act-btn" style="background:#ffff00;" onclick="openMesajeInsert()" data-i18n="Adauga">Adauga</button>
          <button class="act-btn" id="btn-mesaje-edit" onclick="openMesajeEdit()" data-i18n="Editeaza">Editeaza</button>
          <button class="act-btn" id="btn-mesaje-del" onclick="deleteMesaje()" data-i18n="Sterge">Sterge</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal MODURI PREPARARE: formular insert/update cu tastatura virtuala/fizica -->
  <div class="modal-keyboard-overlay" id="modal-mesaje-edit">
    <div class="transfer-dialog">
      <div class="keyboard-dialog-header">
        <span id="mesaje-edit-title" data-i18n="MOD PREPARARE">MOD PREPARARE</span>
        <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeMesajeEdit()" data-i18n="Inapoi">Inapoi</button>
      </div>
      <div class="transfer-body">
        <div class="tva-field">
          <label data-i18n="NrMesaj">NrMesaj</label>
          <div class="tva-field-row">
            <input type="text" id="mesaje-key-input" class="tva-input" value="">
            <button class="act-btn fkb-mini" onclick="openNumericKeyboardFor('mesaje-key-input', 'NRMESAJ')">123</button>
          </div>
        </div>
        <div class="tva-field">
          <label data-i18n="Mesaj">Mesaj</label>
          <div class="tva-field-row">
            <input type="text" id="mesaje-val-input" class="tva-input" value="">
            <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('mesaje-val-input')">⌨️</button>
          </div>
        </div>

        <div class="tva-actions">
          <button class="act-btn" onclick="saveMesaje()" style="background:#ffff00;" data-i18n="Salveaza">Salveaza</button>
          <button class="act-btn" onclick="closeMesajeEdit()" data-i18n="Anuleaza">Anuleaza</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal DISCOUNT: aplicare discount pe produs sau pe nota intreaga -->
  <div class="modal-keyboard-overlay" id="modal-discount">
    <div class="discount-dialog">
      <div class="keyboard-dialog-header">
        <span id="disc-modal-title" data-i18n="disc.titlu">APLICARE DISCOUNT</span>
        <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeDiscountModal()" data-i18n="common.inchide">Inchide</button>
      </div>
      <div class="discount-body">
        <!-- 1. Nivel de aplicare -->
        <div class="disc-section" id="disc-scope-section">
          <div class="disc-section-title" data-i18n="disc.nivel">NIVEL DE APLICARE</div>
          <div class="disc-scope">
            <button class="disc-scope-btn on" id="disc-scope-line" onclick="discSetScope('line')">
              <span class="disc-scope-title" data-i18n="disc.pe_produs">APLICA PE PRODUS</span>
              <span class="disc-scope-sub" id="disc-line-label">...</span>
            </button>
            <button class="disc-scope-btn" id="disc-scope-bill" onclick="discSetScope('bill')">
              <span class="disc-scope-title" data-i18n="disc.pe_subtotal">APLICA PE SUBTOTAL</span>
              <span class="disc-scope-sub" id="disc-bill-label">...</span>
            </button>
          </div>
        </div>

        <!-- 2. Tip discount -->
        <div class="disc-mode-row" id="disc-mode-section">
          <span class="disc-section-title" data-i18n="disc.tip">TIP DISCOUNT</span>
          <div class="disc-mode">
            <button class="disc-mode-btn on" id="disc-mode-percent" onclick="discSetMode('percent')" data-i18n="disc.procent">Procent (%)</button>
            <button class="disc-mode-btn" id="disc-mode-valoric" onclick="discSetMode('valoric')" data-i18n="disc.valoare_lei">Valoare (LEI)</button>
          </div>
        </div>

        <!-- 3. Valoare / numpad / presets -->
        <div class="disc-main" id="disc-main">
          <div class="disc-left">
            <div class="disc-section-title" data-i18n="disc.valoare">VALOARE DISCOUNT</div>
            <div class="disc-value-box">
              <span id="disc-value">0</span>
              <span id="disc-value-suffix">%</span>
            </div>

            <div class="disc-section-title" style="margin-top:14px;" data-i18n="disc.calcul">CALCUL ESTIMAT</div>
            <div class="disc-est">
              <div class="disc-est-row">
                <span data-i18n="disc.val_initiala">Valoare initiala:</span>
                <span id="disc-est-base">0.00 Lei</span>
              </div>
              <div class="disc-est-row">
                <span data-i18n="disc.aplicat">Discount aplicat:</span>
                <span id="disc-est-red">-0.00 Lei</span>
              </div>
              <div class="disc-est-row disc-est-total">
                <span data-i18n="disc.total_nou">Total nou:</span>
                <span id="disc-est-new">0.00 Lei</span>
              </div>
            </div>
          </div>

          <div class="disc-right">
            <div id="disc-presets-block">
              <div class="disc-section-title" data-i18n="disc.presets">PRESETS RAPIDE</div>
              <div class="disc-presets" id="disc-presets"></div>
            </div>

            <div class="disc-section-title" style="margin-top:14px;" data-i18n="disc.numpad">NUMPAD</div>
            <div class="disc-numpad">
              <button class="disc-numpad-key" onclick="discNumpad('7')">7</button>
              <button class="disc-numpad-key" onclick="discNumpad('8')">8</button>
              <button class="disc-numpad-key" onclick="discNumpad('9')">9</button>
              <button class="disc-numpad-key" onclick="discNumpad('4')">4</button>
              <button class="disc-numpad-key" onclick="discNumpad('5')">5</button>
              <button class="disc-numpad-key" onclick="discNumpad('6')">6</button>
              <button class="disc-numpad-key" onclick="discNumpad('1')">1</button>
              <button class="disc-numpad-key" onclick="discNumpad('2')">2</button>
              <button class="disc-numpad-key" onclick="discNumpad('3')">3</button>
              <button class="disc-numpad-key disc-numpad-fn" onclick="discNumpad('C')">C</button>
              <button class="disc-numpad-key" onclick="discNumpad('0')">0</button>
              <button class="disc-numpad-key" onclick="discNumpad('.')">.</button>
            </div>
          </div>
        </div>

        <!-- 4. Motiv -->
        <div class="disc-motiv" id="disc-motiv-block">
          <label for="disc-motiv-select" data-i18n="disc.motiv">MOTIV / OBSERVATII (optional)</label>
          <select id="disc-motiv-select" class="disc-motiv-select">
            <option value="" data-i18n="— selectati motivul —">— selectati motivul —</option>
          </select>
        </div>

        <!-- 5. Actiuni -->
        <div class="disc-actions">
          <button class="disc-btn-cancel" onclick="closeDiscountModal()" data-i18n="common.anuleaza">ANULEAZA</button>
          <button class="disc-btn-apply" id="btn-disc-apply" onclick="discApply()" data-i18n="disc.aplica">APLICA DISCOUNT</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal VD: anulare/stornare articol selectat -->
  <div class="modal-keyboard-overlay" id="modal-void">
    <div class="discount-dialog">
      <div class="keyboard-dialog-header">
        <span data-i18n="vd.titlu">ANULARE ARTICOL (VD)</span>
        <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeVoidModal()" data-i18n="common.inchide">Inchide</button>
      </div>
      <div class="discount-body">
        <!-- Articol selectat -->
        <div class="disc-section">
          <div class="disc-section-title" data-i18n="vd.articol">ARTICOL SELECTAT</div>
          <div class="void-prod-name" id="void-prod-name">—</div>
          <div class="void-info">
            <div><span data-i18n="vd.comandat">Comandat:</span> <b id="void-cant-linie">0</b></div>
            <div><span data-i18n="vd.deja">Deja anulat:</span> <b id="void-deja">0</b></div>
            <div><span data-i18n="vd.disponibil">Disponibil:</span> <b id="void-ramas">0</b></div>
          </div>
        </div>

        <!-- Cantitate + numpad -->
        <div class="disc-main" id="void-qty-box">
          <div class="disc-left">
            <div class="disc-section-title" data-i18n="vd.cantitate">CANTITATE DE ANULAT</div>
            <div class="disc-value-box">
              <span id="void-value">1</span>
              <span id="void-value-suffix">buc</span>
            </div>
            <div class="void-warn" id="void-warn"></div>
          </div>

          <div class="disc-right">
            <div class="disc-section-title" data-i18n="disc.numpad">NUMPAD</div>
            <div class="disc-numpad">
              <button class="disc-numpad-key" onclick="voidNumpad('7')">7</button>
              <button class="disc-numpad-key" onclick="voidNumpad('8')">8</button>
              <button class="disc-numpad-key" onclick="voidNumpad('9')">9</button>
              <button class="disc-numpad-key" onclick="voidNumpad('4')">4</button>
              <button class="disc-numpad-key" onclick="voidNumpad('5')">5</button>
              <button class="disc-numpad-key" onclick="voidNumpad('6')">6</button>
              <button class="disc-numpad-key" onclick="voidNumpad('1')">1</button>
              <button class="disc-numpad-key" onclick="voidNumpad('2')">2</button>
              <button class="disc-numpad-key" onclick="voidNumpad('3')">3</button>
              <button class="disc-numpad-key disc-numpad-fn" onclick="voidNumpad('C')">C</button>
              <button class="disc-numpad-key" onclick="voidNumpad('0')">0</button>
              <button class="disc-numpad-key" onclick="voidNumpad('.')">.</button>
            </div>
          </div>
        </div>

        <!-- Motiv + parola (doar pentru linii deja trimise) -->
        <div class="disc-motiv" id="void-motiv-box">
          <label for="void-motiv-select" data-i18n="vd.motiv">MOTIV ANULARE</label>
          <select id="void-motiv-select" class="disc-motiv-select">
            <option value="" data-i18n="— selectati motivul —">— selectati motivul —</option>
          </select>
        </div>
        <div class="disc-motiv" id="void-parola-box">
          <label for="void-parola" data-i18n="vd.parola">PAROLA STORNARE</label>
          <input type="password" id="void-parola" class="disc-motiv-select" autocomplete="off" />
          <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('void-parola')">⌨️</button>
        </div>

        <!-- Actiuni -->
        <div class="disc-actions">
          <button class="disc-btn-cancel" onclick="closeVoidModal()" data-i18n="common.anuleaza">ANULEAZA</button>
          <button class="disc-btn-apply" id="btn-void-apply" onclick="voidApply()" data-i18n="vd.confirma">CONFIRMA ANULARE</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal OSPATARI: lista ospatarilor -->
  <div class="modal-keyboard-overlay" id="modal-osp">
    <div class="transfer-dialog">
      <div class="keyboard-dialog-header">
        <span data-i18n="OSPATARI">OSPATARI</span>
        <div class="help-header-actions">
          <button class="help-btn" title="Ajutor" data-i18n-title="Ajutor" aria-label="Ajutor" onclick="openHelp('ospatari')">?</button>
          <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeOspModal()" data-i18n="Inchide">Inchide</button>
        </div>
      </div>
      <div class="transfer-body">
        <div class="transfer-subtitle" data-i18n="Atinge un ospatar pentru editare/stergere">Atinge un ospatar pentru editare/stergere</div>
        <div class="osp-head">
          <span data-i18n="Id">Id</span>
          <span data-i18n="Nume">Nume</span>
          <span data-i18n="Descriere">Descriere</span>
        </div>
        <div class="osp-list" id="osp-list"></div>
        <div class="tva-actions">
          <button class="act-btn" style="background:#ffff00;" onclick="openOspInsert()" data-i18n="Adauga">Adauga</button>
          <button class="act-btn" id="btn-osp-edit" onclick="openOspEdit()" data-i18n="Editeaza">Editeaza</button>
          <button class="act-btn" id="btn-osp-del" onclick="deleteOsp()" data-i18n="Sterge">Sterge</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal OSPATARI: formular insert/update -->
  <div class="modal-keyboard-overlay" id="modal-osp-edit">
    <div class="transfer-dialog">
      <div class="keyboard-dialog-header">
        <span id="osp-edit-title" data-i18n="OSPATAR">OSPATAR</span>
        <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeOspEdit()" data-i18n="Inapoi">Inapoi</button>
      </div>
      <div class="transfer-body">
        <div class="tva-field">
          <label data-i18n="NrOsp (max 2 caractere)">NrOsp (max 2 caractere)</label>
          <div class="tva-field-row">
            <input type="text" id="osp-key-input" class="tva-input" value="">
            <button class="act-btn fkb-mini" onclick="openNumericKeyboardFor('osp-key-input', 'NROSP')">123</button>
          </div>
        </div>
        <div class="tva-field">
          <label data-i18n="Nume">Nume</label>
          <div class="tva-field-row">
            <input type="text" id="osp-nume-input" class="tva-input" value="">
            <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('osp-nume-input')">⌨️</button>
          </div>
        </div>
        <div class="tva-field">
          <label data-i18n="Expl (explicatie)">Expl (explicatie)</label>
          <div class="tva-field-row">
            <input type="text" id="osp-expl-input" class="tva-input" value="">
            <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('osp-expl-input')">⌨️</button>
          </div>
        </div>
        <div class="tva-field">
          <label data-i18n="Parola">Parola</label>
          <div class="tva-field-row">
            <input type="text" id="osp-parola-input" class="tva-input" value="">
            <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('osp-parola-input')">⌨️</button>
          </div>
        </div>

        <div class="tva-field">
          <label data-i18n="Blocat">Blocat</label>
          <button class="act-btn" data-i18n="common.nu" id="osp-blocat-toggle" onclick="toggleOspBlocat()" style="width:100%;">Nu</button>
        </div>

        <div class="tva-actions">
          <button class="act-btn" onclick="saveOsp()" style="background:#ffff00;" data-i18n="Salveaza">Salveaza</button>
          <button class="act-btn" onclick="closeOspEdit()" data-i18n="Anuleaza">Anuleaza</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal SECTII: lista sectiilor -->
  <div class="modal-keyboard-overlay" id="modal-sect">
    <div class="transfer-dialog">
      <div class="keyboard-dialog-header">
        <span data-i18n="SECTII">SECTII</span>
        <div class="help-header-actions">
          <button class="help-btn" title="Ajutor" data-i18n-title="Ajutor" aria-label="Ajutor" onclick="openHelp('sectii')">?</button>
          <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeSectModal()" data-i18n="Inchide">Inchide</button>
        </div>
      </div>
      <div class="transfer-body">
        <div class="transfer-subtitle" data-i18n="Atinge o sectie pentru editare/stergere">Atinge o sectie pentru editare/stergere</div>
        <div class="sect-head">
          <span data-i18n="Sectie">Sectie</span>
          <span data-i18n="Denumire">Denumire</span>
        </div>
        <div class="sect-list" id="sect-list"></div>
        <div class="tva-actions">
          <button class="act-btn" style="background:#ffff00;" onclick="openSectInsert()" data-i18n="Adauga">Adauga</button>
          <button class="act-btn" id="btn-sect-edit" onclick="openSectEdit()" data-i18n="Editeaza">Editeaza</button>
          <button class="act-btn" id="btn-sect-del" onclick="deleteSect()" data-i18n="Sterge">Sterge</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal SECTII: formular insert/update -->
  <div class="modal-keyboard-overlay" id="modal-sect-edit">
    <div class="transfer-dialog">
      <div class="keyboard-dialog-header">
        <span id="sect-edit-title" data-i18n="SECTIE">SECTIE</span>
        <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeSectEdit()" data-i18n="Inapoi">Inapoi</button>
      </div>
      <div class="transfer-body">
        <div class="tva-field">
          <label data-i18n="Sectie">Sectie</label>
          <div class="tva-field-row">
            <input type="text" id="sect-key-input" class="tva-input" value="">
            <button class="act-btn fkb-mini" onclick="openNumericKeyboardFor('sect-key-input', 'SECTIE')">123</button>
          </div>
        </div>
        <div class="tva-field">
          <label data-i18n="Denumire (max 10 caractere)">Denumire (max 10 caractere)</label>
          <div class="tva-field-row">
            <input type="text" id="sect-den-input" class="tva-input" value="">
            <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('sect-den-input')">⌨️</button>
          </div>
        </div>
        <div class="tva-actions">
          <button class="act-btn" onclick="saveSect()" style="background:#ffff00;" data-i18n="Salveaza">Salveaza</button>
          <button class="act-btn" onclick="closeSectEdit()" data-i18n="Anuleaza">Anuleaza</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal FORME DE PLATA: lista formelor -->
  <div class="modal-keyboard-overlay" id="modal-fp">
    <div class="transfer-dialog">
      <div class="keyboard-dialog-header">
        <span data-i18n="FORME DE PLATA">FORME DE PLATA</span>
        <div class="help-header-actions">
          <button class="help-btn" title="Ajutor" data-i18n-title="Ajutor" aria-label="Ajutor" onclick="openHelp('forme_plata')">?</button>
          <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeFpModal()" data-i18n="Inchide">Inchide</button>
        </div>
      </div>
      <div class="transfer-body">
        <div class="transfer-subtitle" data-i18n="Atinge o forma de plata pentru editare/stergere">Atinge o forma de plata pentru editare/stergere</div>
        <div class="kp-head fp-head">
          <span>FPID</span>
          <span data-i18n="Denumire">Denumire</span>
          <span data-i18n="Poz">Poz</span>
          <span data-i18n="Status">Status</span>
        </div>
        <div class="kp-list fp-list" id="fp-list"></div>
        <div class="tva-actions">
          <button class="act-btn" style="background:#ffff00;" onclick="openFpInsert()" data-i18n="Adauga">Adauga</button>
          <button class="act-btn" id="btn-fp-edit" onclick="openFpEdit()" data-i18n="Editeaza">Editeaza</button>
          <button class="act-btn" id="btn-fp-del" onclick="deleteFp()" data-i18n="Sterge">Sterge</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal FORME DE PLATA: formular insert/update -->
  <div class="modal-keyboard-overlay" id="modal-fp-edit">
    <div class="transfer-dialog">
      <div class="keyboard-dialog-header">
        <span id="fp-edit-title" data-i18n="FORMA DE PLATA">FORMA DE PLATA</span>
        <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeFpEdit()" data-i18n="Inapoi">Inapoi</button>
      </div>
      <div class="transfer-body">
        <div class="tva-field">
          <label data-i18n="FPID">FPID</label>
          <div class="tva-field-row">
            <input type="text" id="fp-key-input" class="tva-input" value="">
            <button class="act-btn fkb-mini" onclick="openNumericKeyboardFor('fp-key-input', 'FPID')">123</button>
          </div>
        </div>
        <div class="tva-field">
          <label data-i18n="Denumire (max 10 caractere)">Denumire (max 10 caractere)</label>
          <div class="tva-field-row">
            <input type="text" id="fp-den-input" class="tva-input" value="">
            <button class="act-btn fkb-mini" onclick="openFloatingKeyboardFor('fp-den-input')">⌨️</button>
          </div>
        </div>
        <div class="tva-field">
          <label data-i18n="Poz (ordine afisare, 1 = implicit)">Poz (ordine afisare, 1 = implicit)</label>
          <div class="tva-field-row">
            <input type="text" id="fp-poz-input" class="tva-input" value="">
            <button class="act-btn fkb-mini" onclick="openNumericKeyboardFor('fp-poz-input', 'POZ')">123</button>
          </div>
        </div>
        <div class="tva-field">
          <label data-i18n="Status (activ)">Status (activ)</label>
          <button class="act-btn" data-i18n="common.nu" id="fp-status-toggle" onclick="toggleFpStatus()" style="width:100%;">Nu</button>
        </div>
        <div class="tva-actions">
          <button class="act-btn" onclick="saveFp()" style="background:#ffff00;" data-i18n="Salveaza">Salveaza</button>
          <button class="act-btn" onclick="closeFpEdit()" data-i18n="Anuleaza">Anuleaza</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal CANTITATE: tastatura numerica pentru a modifica cantitatea unui produs -->
  <div class="modal-keyboard-overlay" id="modal-qty">    <div class="keyboard-dialog qty-dialog">
      <div class="keyboard-dialog-header">
        <span data-i18n="CANTITATE">CANTITATE</span>
        <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeQtyModal()" data-i18n="Inchide">Inchide</button>
      </div>
      <div class="keyboard-body">
        <div id="qty-product-name" style="color: #333; font-size: 15px; font-weight: bold; text-align: center; max-width: 280px;">Produs</div>
        <div id="qty-cantar-badge" class="qty-cantar-badge" style="display: none;" data-i18n="Cantarul electronic nu raspunde">Cantarul electronic nu raspunde</div>
        <input type="text" id="qty-input-display" class="keyboard-input-display" readonly value="1">

        <div class="qty-numpad">
          <button class="qty-key" onclick="qtyKey('7')">7</button>
          <button class="qty-key" onclick="qtyKey('8')">8</button>
          <button class="qty-key" onclick="qtyKey('9')">9</button>
          <button class="qty-key qty-fn" onclick="qtyBackspace()">⌫</button>

          <button class="qty-key" onclick="qtyKey('4')">4</button>
          <button class="qty-key" onclick="qtyKey('5')">5</button>
          <button class="qty-key" onclick="qtyKey('6')">6</button>
          <button class="qty-key qty-fn" onclick="qtyClear()">C</button>

          <button class="qty-key" onclick="qtyKey('1')">1</button>
          <button class="qty-key" onclick="qtyKey('2')">2</button>
          <button class="qty-key" onclick="qtyKey('3')">3</button>
          <button class="qty-key qty-fn" id="qty-dot-key" onclick="qtyKey('.')">.</button>

          <button class="qty-key qty-zero" onclick="qtyKey('0')">0</button>
          <button class="qty-key qty-ok" onclick="confirmQty()">OK</button>
        </div>

        <div id="qty-dec-hint" style="font-size: 11px; color: #b00;" data-i18n="maxim o zecimala (ex: 0.2)">maxim o zecimala (ex: 0.2)</div>
      </div>
    </div>
  </div>

  <!-- Modal TASTATURA NUMERICA generica: scrie intr-un input tinta -->
  <div class="modal-keyboard-overlay" id="modal-num">
    <div class="keyboard-dialog qty-dialog">
      <div class="keyboard-dialog-header">
        <span id="num-title" data-i18n="TASTATURA NUMERICA">TASTATURA NUMERICA</span>
        <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeNumericKeyboard()" data-i18n="Inchide">Inchide</button>
      </div>
      <div class="keyboard-body">
        <input type="text" id="num-display" class="keyboard-input-display" readonly value="">
        <div class="qty-numpad">
          <button class="qty-key" onclick="numpadKey('7')">7</button>
          <button class="qty-key" onclick="numpadKey('8')">8</button>
          <button class="qty-key" onclick="numpadKey('9')">9</button>
          <button class="qty-key qty-fn" onclick="numpadBackspace()">⌫</button>
          <button class="qty-key" onclick="numpadKey('4')">4</button>
          <button class="qty-key" onclick="numpadKey('5')">5</button>
          <button class="qty-key" onclick="numpadKey('6')">6</button>
          <button class="qty-key qty-fn" onclick="numpadClear()">C</button>
          <button class="qty-key" onclick="numpadKey('1')">1</button>
          <button class="qty-key" onclick="numpadKey('2')">2</button>
          <button class="qty-key" onclick="numpadKey('3')">3</button>
          <button class="qty-key qty-fn" id="num-dot-key" onclick="numpadKey('.')">.</button>
          <button class="qty-key qty-zero" onclick="numpadKey('0')">0</button>
          <button class="qty-key qty-ok" onclick="numpadOk()">OK</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal COD FISCAL CLIENT (CUI) pentru bonul fiscal -->
  <div class="modal-keyboard-overlay" id="modal-cui">
    <div class="keyboard-dialog qty-dialog">
      <div class="keyboard-dialog-header">
        <span data-i18n="COD FISCAL CLIENT">COD FISCAL CLIENT</span>
        <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeCuiModal()" data-i18n="Inchide">Inchide</button>
      </div>
      <div class="keyboard-body">
        <div class="cui-label" data-i18n="Cod Fiscal">Cod Fiscal</div>
        <input type="text" id="cui-display" class="keyboard-input-display" value="" autocomplete="off"
               oninput="cuiInput()" onkeydown="if(event.key==='Enter'){event.preventDefault();cuiOk();}">
        <div class="cui-error" id="cui-error"></div>
        <div class="cui-numpad">
          <button class="qty-key" onclick="cuiKey('7')">7</button>
          <button class="qty-key" onclick="cuiKey('8')">8</button>
          <button class="qty-key" onclick="cuiKey('9')">9</button>
          <button class="qty-key qty-fn" onclick="cuiClear()">C</button>

          <button class="qty-key" onclick="cuiKey('4')">4</button>
          <button class="qty-key" onclick="cuiKey('5')">5</button>
          <button class="qty-key" onclick="cuiKey('6')">6</button>
          <button class="qty-key qty-ok cui-ok" onclick="cuiOk()">OK</button>

          <button class="qty-key" onclick="cuiKey('1')">1</button>
          <button class="qty-key" onclick="cuiKey('2')">2</button>
          <button class="qty-key" onclick="cuiKey('3')">3</button>

          <button class="qty-key cui-zero" onclick="cuiKey('0')">0</button>
          <button class="qty-key cui-ro" onclick="cuiRo()">RO</button>
        </div>
        <button class="act-btn cui-remove" onclick="cuiRemove()" data-i18n="Fara cod fiscal">Fara cod fiscal</button>
      </div>
    </div>
  </div>

  <!-- Modal CONFIRMARE generica (in-app, fara dialog nativ) -->
  <div class="modal-keyboard-overlay" id="modal-confirm">
    <div class="transfer-dialog" style="max-width: 440px;">
      <div class="keyboard-dialog-header">
        <span data-i18n="common.confirmare">CONFIRMARE</span>
      </div>
      <div class="transfer-body">
        <div id="confirm-message" style="font-size:15px; color:#222; margin:8px 0 18px 0; line-height:1.4;"></div>
        <div class="tva-actions">
          <button class="act-btn" id="confirm-yes" style="background:#ffff00;" data-i18n="common.da">Da</button>
          <button class="act-btn" id="confirm-no" data-i18n="common.nu">Nu</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal MESAJ generica (in-app, fara dialog nativ) -->
  <div class="modal-keyboard-overlay" id="modal-alert">
    <div class="transfer-dialog" style="max-width: 480px;">
      <div class="keyboard-dialog-header">
        <span data-i18n="ATENTIE">ATENTIE</span>
      </div>
      <div class="transfer-body">
        <div id="alert-message" style="font-size:15px; color:#222; margin:8px 0 18px 0; line-height:1.4; white-space: pre-line; max-width: 440px; max-height: 320px; overflow: auto; overflow-wrap: anywhere; word-break: break-word;"></div>
        <div class="tva-actions">
          <button class="act-btn" id="alert-ok" style="background:#ffff00;">OK</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal POS BANCAR (tranzactie refuzata): Cancel / Retry / Ignore -->
  <div class="modal-keyboard-overlay" id="modal-pos-banca">
    <div class="transfer-dialog" style="max-width: 480px;">
      <div class="keyboard-dialog-header">
        <span data-i18n="TRANZACTIE REFUZATA!">TRANZACTIE REFUZATA!</span>
      </div>
      <div class="transfer-body">
        <div id="pos-banca-message" style="font-size:15px; color:#222; margin:8px 0 18px 0; line-height:1.4; white-space: pre-line;"></div>
        <div class="tva-actions">
          <button class="act-btn" id="pos-banca-cancel">Cancel</button>
          <button class="act-btn" id="pos-banca-retry" style="background:#ffff00;">Retry</button>
          <button class="act-btn" id="pos-banca-ignore">Ignore</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal POS BANCAR - asteptare raspuns terminal (fara butoane) -->
  <div class="modal-keyboard-overlay" id="modal-pos-banca-wait">
    <div class="transfer-dialog" style="max-width: 420px;">
      <div class="keyboard-dialog-header">
        <span data-i18n="POS BANCAR">POS BANCAR</span>
      </div>
      <div class="transfer-body">
        <div style="font-size:16px; color:#222; padding:16px 0; text-align:center;">
          <span data-i18n="Se asteapta terminalul bancar...">Se asteapta terminalul bancar...</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal CASA DE MARCAT - asteptare raspuns bon fiscal (fara butoane) -->
  <div class="modal-keyboard-overlay" id="modal-fiscal-wait">
    <div class="transfer-dialog" style="max-width: 420px;">
      <div class="keyboard-dialog-header">
        <span data-i18n="CASA DE MARCAT">CASA DE MARCAT</span>
      </div>
      <div class="transfer-body">
        <div style="font-size:16px; color:#222; padding:16px 0; text-align:center;">
          <span data-i18n="Se asteapta raspunsul casei de marcat...">Se asteapta raspunsul casei de marcat...</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal TRANSFER PRODUSE (selectie produse + masa destinatie prin numar) -->
  <div class="modal-keyboard-overlay" id="modal-transfer">
    <div class="transfer-dialog">
      <div class="keyboard-dialog-header">
        <span id="transfer-dialog-title" data-i18n="TRANSFER PRODUSE">TRANSFER PRODUSE</span>
        <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeTransferModal()" data-i18n="Inchide">Inchide</button>
      </div>

      <!-- Pasul 1: selecteaza produsele din nota curenta -->
      <div id="transfer-step-products">
        <div class="transfer-body">
          <div class="transfer-subtitle">Masa sursa: <b id="transfer-source-table">1</b> — atinge produsele de mutat</div>
          <div class="transfer-list" id="transfer-products-list"></div>
          <div class="transfer-list-footer">
            <button class="act-btn" onclick="selectAllTransfer()" data-i18n="Toate">Toate</button>
            <button class="act-btn" onclick="clearTransfer()" data-i18n="Nimic">Nimic</button>
            <div class="act-spacer"></div>
            <button class="act-btn" id="btn-transfer-next" style="background:#ffff00;" onclick="goTransferToMasa()" data-i18n="Continua">Continua</button>
          </div>
        </div>
      </div>

      <!-- Pasul 2: masa destinatie, selectata dupa numar -->
      <div id="transfer-step-masa" style="display: none;">
        <div class="transfer-body">
          <div class="transfer-subtitle" id="transfer-masa-subtitle" data-i18n="Masa destinatie (numar 1–80)">Masa destinatie (numar 1–80)</div>
          <input type="text" id="transfer-masa-input" class="keyboard-input-display" readonly value="">
          <div class="transfer-numpad">
            <button class="transfer-key" onclick="transferMasaKey('1')">1</button>
            <button class="transfer-key" onclick="transferMasaKey('2')">2</button>
            <button class="transfer-key" onclick="transferMasaKey('3')">3</button>
            <button class="transfer-key" onclick="transferMasaKey('4')">4</button>
            <button class="transfer-key" onclick="transferMasaKey('5')">5</button>
            <button class="transfer-key" onclick="transferMasaKey('6')">6</button>
            <button class="transfer-key" onclick="transferMasaKey('7')">7</button>
            <button class="transfer-key" onclick="transferMasaKey('8')">8</button>
            <button class="transfer-key" onclick="transferMasaKey('9')">9</button>
            <button class="transfer-key transfer-key-clear" onclick="transferMasaClear()">C</button>
            <button class="transfer-key" onclick="transferMasaKey('0')">0</button>
            <button class="transfer-key transfer-key-clear" onclick="transferMasaBackspace()">⌫</button>
            <button class="transfer-key transfer-key-ok" onclick="execTransfer()">OK</button>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- Modal COADA DE TIPARIRE (joburi pending/failed/done) -->
  <div class="modal-keyboard-overlay" id="modal-print-queue">
    <div class="transfer-dialog" style="width: 720px;">
      <div class="keyboard-dialog-header">
        <span data-i18n="printq.titlu">COADA DE TIPARIRE</span>
        <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closePrintQueue()" data-i18n="common.inchide">Inchide</button>
      </div>
      <div class="transfer-body">
        <div class="transfer-subtitle" data-i18n="printq.subtitlu">Joburi de tiparire: in asteptare, esuate sau finalizate</div>
        <div class="printq-wrap">
          <table class="printq-table">
            <colgroup>
              <col style="width: 52px;">
              <col style="width: 150px;">
              <col style="width: 90px;">
              <col style="width: 46px;">
              <col style="width: 80px;">
              <col style="width: 162px;">
              <col>
            </colgroup>
            <thead>
              <tr>
                <th class="printq-num">JobID</th>
                <th data-i18n="common.tip">Tip</th>
                <th>Ref</th>
                <th class="printq-num">Kp</th>
                <th data-i18n="printq.col_stare">Stare</th>
                <th data-i18n="printq.col_creat">Creat</th>
                <th data-i18n="printq.col_incercari">Incercari</th>
              </tr>
            </thead>
            <tbody id="printq-list"></tbody>
          </table>
        </div>
        <div class="tva-actions">
          <button class="act-btn" onclick="refreshPrintQueue()" data-i18n="printq.reincarca">Reincarca</button>
          <button class="act-btn" id="btn-printq-preview" onclick="previewPrintJob()" disabled>Preview</button>
          <button class="act-btn" id="btn-printq-retry" onclick="retryPrintJob()" disabled>Retry</button>
          <button class="act-btn" id="btn-printq-del" onclick="deletePrintJob()" disabled data-i18n="printq.sterge">Sterge</button>
          <button class="act-btn" onclick="clearDonePrintJobs()" data-i18n="printq.goleste_done">Goleste done</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal COADA EXPORT (temp_Send_Sql - bonuri catre serverul extern) -->
  <div class="modal-keyboard-overlay" id="modal-send-queue">
    <div class="transfer-dialog" style="width: 720px;">
      <div class="keyboard-dialog-header">
        <span data-i18n="COADA EXPORT BONURI">COADA EXPORT BONURI</span>
        <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeSendQueue()" data-i18n="Inchide">Inchide</button>
      </div>
      <div class="transfer-body">
        <div class="transfer-subtitle" data-i18n="Bonuri trimise catre serverul extern: in asteptare, esuate sau trimise">Bonuri trimise catre serverul extern: in asteptare, esuate sau trimise</div>
        <div class="printq-wrap">
          <table class="printq-table">
            <colgroup>
              <col style="width: 52px;">
              <col style="width: 90px;">
              <col style="width: 80px;">
              <col style="width: 78px;">
              <col style="width: 150px;">
              <col>
            </colgroup>
            <thead>
              <tr>
                <th class="printq-num">Id</th>
                <th>Doc</th>
                <th data-i18n="Stare">Stare</th>
                <th class="printq-num" data-i18n="Incercari">Incercari</th>
                <th data-i18n="Creat">Creat</th>
                <th data-i18n="Ultima eroare">Ultima eroare</th>
              </tr>
            </thead>
            <tbody id="sendq-list"></tbody>
          </table>
        </div>
        <div class="tva-actions">
          <button class="act-btn" onclick="refreshSendQueue()" data-i18n="Reincarca">Reincarca</button>
          <button class="act-btn" id="btn-sendq-view" onclick="viewSendJob()" disabled data-i18n="Comanda SQL">Comanda SQL</button>
          <button class="act-btn" id="btn-sendq-retry" onclick="retrySendJob()" disabled>Retry</button>
          <button class="act-btn" id="btn-sendq-del" onclick="deleteSendJob()" disabled data-i18n="Sterge">Sterge</button>
          <button class="act-btn" onclick="clearDoneSendJobs()" data-i18n="Goleste trimise">Goleste trimise</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal COMANDA SQL (comanda de export catre server) -->
  <div class="modal-keyboard-overlay" id="modal-send-detail">
    <div class="transfer-dialog" style="max-width: 820px;">
      <div class="keyboard-dialog-header">
        <span id="send-det-title" data-i18n="COMANDA EXPORT">COMANDA EXPORT</span>
        <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeSendDetail()" data-i18n="Inchide">Inchide</button>
      </div>
      <div class="transfer-body" style="align-items: stretch;">
        <pre id="send-det-content" style="white-space: pre-wrap; word-break: break-word; font-family: Consolas, monospace; font-size: 12px; color: #ddd; background: #2b2b2b; padding: 10px; border-radius: 4px; max-height: 420px; overflow: auto; margin: 0;"></pre>
        <div class="tva-actions">
          <button class="act-btn" onclick="closeSendDetail()" data-i18n="Inchide">Inchide</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal PREVIEW BON (emulator ESC/POS) -->
  <div class="modal-keyboard-overlay" id="modal-print-preview">
    <div class="transfer-dialog" style="max-width: 780px;">
      <div class="keyboard-dialog-header">
        <span data-i18n="PREVIEW BON">PREVIEW BON</span>
        <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closePrintPreview()" data-i18n="Inchide">Inchide</button>
      </div>
      <div class="transfer-body" style="padding: 0;">
        <iframe id="print-preview-frame" src="" style="width: 100%; height: 560px; border: 0; background: #3a3a3a;"></iframe>
      </div>
    </div>
  </div>

  <!-- Modal RAPORT (X / Z) -->
  <div class="modal-keyboard-overlay" id="modal-raport">
    <div class="transfer-dialog" style="width: 900px;">
      <div class="keyboard-dialog-header">
        <span id="raport-title" data-i18n="RAPORT">RAPORT</span>
        <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeReportModal()" data-i18n="Inchide">Inchide</button>
      </div>
      <div class="transfer-body" style="align-items: stretch;">
        <div id="raport-content" class="raport-content"></div>
        <div class="tva-actions">
          <button class="act-btn" id="raport-print-btn" onclick="printCurrentReport()" data-i18n="Tipareste">Tipareste</button>
          <button class="act-btn" onclick="closeReportModal()" data-i18n="Inchide">Inchide</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal DETALIU NOTA (raportul "Note") -->
  <div class="modal-keyboard-overlay" id="modal-nota-detail">
    <div class="transfer-dialog" style="width: 900px;">
      <div class="keyboard-dialog-header">
        <span id="nota-det-title" data-i18n="marcare.nota">NOTA</span>
        <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeNoteDetail()" data-i18n="Inchide">Inchide</button>
      </div>
      <div class="transfer-body" style="align-items: stretch;">
        <div id="nota-det-content" class="raport-content"></div>
        <div class="tva-actions">
          <button class="act-btn" onclick="closeNoteDetail()" data-i18n="Inapoi">Inapoi</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal AJUTOR (help contextual per ecran de setari / editor) -->
  <div class="modal-keyboard-overlay" id="modal-help" onclick="if(event.target===this) closeHelp()">
    <div class="help-dialog">
      <div class="keyboard-dialog-header">
        <span id="modal-help-title" data-i18n="AJUTOR">AJUTOR</span>
        <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeHelp()" data-i18n="Inchide">Inchide</button>
      </div>
      <div class="help-body" id="modal-help-body"></div>
    </div>
  </div>

  <!-- Modal ROLLBACK (revenire la o versiune anterioara) -->
  <div class="modal-keyboard-overlay" id="modal-rollback" onclick="if(event.target===this) closeRollback()">
    <div class="transfer-dialog" style="width: 780px;">
      <div class="keyboard-dialog-header">
        <span data-i18n="ROLLBACK">ROLLBACK</span>
        <div class="help-header-actions">
          <button class="help-btn" title="Ajutor" data-i18n-title="Ajutor" aria-label="Ajutor" onclick="openHelp('rollback')">?</button>
          <button class="act-btn" style="height: 28px; min-width: 60px; padding: 0 8px;" onclick="closeRollback()" data-i18n="Inchide">Inchide</button>
        </div>
      </div>
      <div class="transfer-body" style="align-items: stretch;">
        <div class="transfer-subtitle" data-i18n="Alegeti un punct de restaurare sau o versiune din istoric, apoi apasati Aplica rollback. Configurarile locale nu sunt afectate.">Alegeti un punct de restaurare sau o versiune din istoric, apoi apasati Aplica rollback. Configurarile locale nu sunt afectate.</div>
        <div id="rollback-list" class="rollback-list"></div>
        <div class="tva-actions" style="margin-top:12px;">
          <button class="act-btn" style="background:#ffff00;" id="rollback-apply-btn" onclick="applyRollback()" data-i18n="Aplica rollback">Aplica rollback</button>
          <button class="act-btn" onclick="reloadRollback()" data-i18n="Reincarca lista">Reincarca lista</button>
          <button class="act-btn" onclick="closeRollback()" data-i18n="Anuleaza">Anuleaza</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Toast Notification -->
  <div class="pos-toast" id="pos-toast" data-i18n="Notificare">Notificare</div>

  </div><!-- /#pos-stage -->

  <!-- Stare baza de date: banner care rămâne vizibil cat timp aplicatia nu poate
       citi datele (ex. instanta SQL oprita sau inca pornind dupa un reboot).
       Sta in afara canvasului scalat (deci nu este afectat de --pos-scale) si nu
       blocheaza clicurile, ca butonul Parola sa rămâna folosibil: parola de
       inchidere functioneaza si fara baza de date. -->
  <div class="db-status" id="db-status">
    <div class="db-status-title" id="db-status-title"></div>
    <div class="db-status-text" id="db-status-text"></div>
  </div>

  <!-- Script aplicatie -->
  <script src="js/help.js?v=<?= $ASSET_V ?>"></script>
  <script src="js/pos.js?v=<?= $ASSET_V ?>"></script>
  <script>if (typeof applyI18n === "function") applyI18n();</script>
</body>
</html>
