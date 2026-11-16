<?php
/**
 * Registrul driverelor POS bancar si capabilitatile fiecaruia.
 *
 * Fiecare driver are o identitate clara si un adaptor dedicat; nu exista un
 * driver "generic" care sa scrie doar fisierul de rezultat. Driverele
 * disponibile:
 *
 *   - SmartPayECR (SmartPay ECR Link): jurnal, resync/void si conexiune citita
 *     din .env. Codul specific traieste in api/pos_banca_smartpay.php.
 *   - VivaECR (Viva.com Terminal, ECR Protocol TCP/IP): protocolul local cu
 *     mesaje text; confirmarea unei plati incerte se face cu ultima tranzactie
 *     din terminal (getLastReceipt), void cu STAN. Codul specific traieste in
 *     api/pos_banca_viva.php.
 *
 * Adaptoarele se incarca doar pentru protocolul selectat. Un driver nou se
 * adauga aici (valoare stabila + capabilitati + metadate) si primeste un
 * adaptor dedicat, in acelasi stil ca fisierele de mai sus.
 */

const POS_BANCA_PROTOCOL_SMART_PAY = 'SmartPayECR';
const POS_BANCA_PROTOCOL_VIVA_ECR = 'VivaECR';

/**
 * @return array<string,array{label:string,caps:array<string,bool>,adapter:string,auxHandler:string,script:string,userMessage:string,connection:string}>
 */
function posBancaProtocols() {
    return [
        POS_BANCA_PROTOCOL_SMART_PAY => [
            'label' => 'SmartPay ECR Link',
            'caps' => [
                'journal' => true,
                'void' => true,
                'envConnection' => true,
            ],
            'adapter' => 'pos_banca_smartpay.php',
            'auxHandler' => 'posBancaSmartPayHandleAux',
            'script' => 'smartpay_client.py',
            'userMessage' => 'posBancaSmartPayUserMessage',
            'connection' => 'posBancaConnectionInfo',
        ],
        POS_BANCA_PROTOCOL_VIVA_ECR => [
            'label' => 'Viva.com Terminal (ECR Protocol)',
            'caps' => [
                'journal' => true,
                'void' => true,
                'envConnection' => true,
            ],
            'adapter' => 'pos_banca_viva.php',
            'auxHandler' => 'posBancaVivaHandleAux',
            'script' => 'viva_client.py',
            'userMessage' => 'posBancaVivaUserMessage',
            'connection' => 'posBancaVivaConnectionInfo',
        ],
    ];
}

/**
 * Protocolul implicit. Il pastram SmartPayECR ca instalatiile existente sa aiba
 * exact comportamentul de dinainte de introducerea setarii.
 */
function posBancaProtocolDefault() {
    return POS_BANCA_PROTOCOL_SMART_PAY;
}

/**
 * @param mixed $protocol
 */
function posBancaProtocolIsValid($protocol) {
    return array_key_exists(trim((string)$protocol), posBancaProtocols());
}

/**
 * Normalizeaza valoarea citita din tblSet. Lista de protocoale este inchisa:
 * o valoare necunoscuta (inclusiv vechiul "Generic", care a fost eliminat) este
 * tratata ca protocolul implicit, ca o instalatie veche sa nu rămână fara
 * driverul si capabilitatile lui.
 *
 * @param mixed $protocol
 */
function posBancaProtocolNormalize($protocol) {
    $protocol = trim((string)$protocol);
    if ($protocol === '') { return posBancaProtocolDefault(); }
    return posBancaProtocolIsValid($protocol) ? $protocol : posBancaProtocolDefault();
}

/**
 * @param mixed $protocol
 * @return array<string,bool>
 */
function posBancaProtocolCaps($protocol) {
    $protocol = posBancaProtocolNormalize($protocol);
    $all = posBancaProtocols();
    return $all[$protocol]['caps'];
}

/**
 * Metadatele complete ale protocolului (adaptor, handler, script, conexiune).
 *
 * @param mixed $protocol
 * @return array{label:string,caps:array<string,bool>,adapter:string,auxHandler:string,script:string,userMessage:string,connection:string}
 */
function posBancaProtocolInfo($protocol) {
    $protocol = posBancaProtocolNormalize($protocol);
    $all = posBancaProtocols();
    return $all[$protocol];
}

/**
 * @param mixed $protocol
 */
function posBancaProtocolLabel($protocol) {
    $protocol = posBancaProtocolNormalize($protocol);
    $all = posBancaProtocols();
    return $all[$protocol]['label'];
}
