<?php
/**
 * API Fiscal Print (Exemplu emitere Bon Fiscal in PHP)
 * Genereaza comanda pentru casa de marcat (Datecs / Daisy / Custom)
 * prin fisier spooler (FPrint / FiscalNet / DPrint).
 */
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['articole'])) {
    echo json_encode(["status" => "error", "message" => "Lipsesc datele comenzii"]);
    exit;
}

$masa = $input['masa'] ?? 1;
$casier = $input['casier'] ?? "CASIER 1";
$metodaPlata = $input['plata'] ?? "NUMERAR";
$articole = $input['articole'];
$cui = $input['cui'] ?? null;

// Exemplu format clasic Datecs / FiscalNet .TXT
// S = Linie vanzare: S,Denumire,Pret,Cantitate,Departament,GrupaTVA
$liniiComanda = [];

// Header bon fiscal
if (!empty($cui)) {
    $liniiComanda[] = "CF," . preg_replace('/[^0-9]/', '', $cui); // Cod fiscal client
}

foreach ($articole as $art) {
    $den = substr($art['denumire'], 0, 32); // Max 32 caractere pentru casa fiscala
    $pret = number_format($art['pretUnitar'], 2, '.', '');
    $cant = number_format($art['cantitate'], 3, '.', '');
    $tvaGrupa = ($art['tva'] ?? 9) == 19 ? "1" : "2"; // 1 = 19%, 2 = 9%
    
    $liniiComanda[] = "S,{$den},{$pret},{$cant},1,{$tvaGrupa},1,0";
}

// Total si plata
// T = Total: T,TipPlata (1 = Numerar, 2 = Card, etc.)
$codPlata = 1; // Default numerar
if (strtoupper($metodaPlata) === "CARD") $codPlata = 2;
if (strtoupper($metodaPlata) === "TICHET") $codPlata = 3;

$liniiComanda[] = "T,{$codPlata}";

$continutFisier = implode("\r\n", $liniiComanda);

// In mod normal se salveaza in folderul monitorizat de driver:
// file_put_contents("C:\\FiscalNet\\In\\bon_{$masa}_" . time() . ".txt", $continutFisier);

echo json_encode([
    "status" => "success",
    "message" => "Comanda fiscala a fost generata cu succes",
    "spooler_preview" => $liniiComanda,
    "plata" => $metodaPlata
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
