<?php
/**
 * Faza B - invariante de baza de date (DOAR CITIRE). Fiecare verificare
 * trebuie sa intoarca 0 randuri / conditie true.
 */
require __DIR__ . '/lib.php';

function sample($sql, $p = [], $n = 3) {
    try { $r = rows($sql, $p); } catch (Throwable $e) { return 'EROARE SQL: ' . $e->getMessage(); }
    $c = count($r);
    $s = '';
    foreach (array_slice($r, 0, $n) as $row) { $s .= '  ' . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n"; }
    return "n=$c\n$s";
}

section('B1 - TotalB = SUM(Cant*PV)');
$bad = rows("SELECT b.DocID, b.Stare, b.TotalB, x.s
             FROM tblBonCurent b
             JOIN (SELECT DocID, SUM(CAST(Cant AS float)*CAST(PV AS float)) s FROM tblNoteD GROUP BY DocID) x ON x.DocID=b.DocID
             WHERE ABS(CAST(ISNULL(b.TotalB,0) AS float) - x.s) > 0.02");
test('bon viu: TotalB = suma liniilor', count($bad) === 0, json_encode(array_slice($bad, 0, 5)));

section('B2 - conventia linii produs vs modificator');
$m1 = rows("SELECT ECRID,DocID,Cant,PV,PVC FROM tblNoteD WHERE ProdID=0 AND (Cant>0 OR ISNULL(PV,0)<>0 OR ISNULL(PVC,0)<>0)");
test('modificator: ProdID=0 => Cant<=0 si PV=PVC=0', count($m1) === 0, json_encode(array_slice($m1, 0, 5)));
$m2 = rows("SELECT ECRID,DocID,ProdID,Cant FROM tblNoteD WHERE ProdID>0 AND Cant<=0");
test('produs: ProdID>0 => Cant>0', count($m2) === 0, json_encode(array_slice($m2, 0, 5)));

section('B3 - storno');
$ov = rows("SELECT s.StornoRef, SUM(-s.Cant) AS stornat, MAX(o.Cant) AS original
            FROM tblNoteD s JOIN tblNoteD o ON o.ECRID=s.StornoRef
            WHERE s.Cant<0 GROUP BY s.StornoRef, o.Cant
            HAVING SUM(-s.Cant) > MAX(o.Cant)");
test('storno cumulat <= cantitatea originala', count($ov) === 0, json_encode(array_slice($ov, 0, 5)));
$orph = rows("SELECT s.ECRID,s.DocID,s.StornoRef FROM tblNoteD s
              WHERE s.Cant<0 AND s.StornoRef IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM tblNoteD o WHERE o.ECRID=s.StornoRef)");
test('StornoRef indica o linie existenta', count($orph) === 0, json_encode(array_slice($orph, 0, 5)));
$xdoc = rows("SELECT s.ECRID,s.DocID,s.StornoRef,o.DocID AS origDoc FROM tblNoteD s JOIN tblNoteD o ON o.ECRID=s.StornoRef WHERE s.DocID<>o.DocID");
test('storno si originalul sunt in acelasi bon', count($xdoc) === 0, json_encode(array_slice($xdoc, 0, 5)));
$sn = rows("SELECT ECRID,DocID,StornoRef FROM tblNoteD WHERE Cant<0 AND StornoRef IS NULL");
test('linie negativa are StornoRef', count($sn) === 0, json_encode(array_slice($sn, 0, 5)));

section('B4 - plati pe bonuri inchise');
$pay = rows("SELECT b.DocID,b.NrDoc,b.TotalB,p.s,p.n
             FROM tblBonCurent b
             LEFT JOIN (SELECT DocID, SUM(CAST(Suma AS float)) s, COUNT(*) n FROM trelDocIDFpID GROUP BY DocID) p ON p.DocID=b.DocID
             WHERE b.Stare='I' AND ABS(CAST(ISNULL(b.TotalB,0) AS float) - ISNULL(p.s,0)) > 0.02");
test('bon inchis: TotalB = suma platilor', count($pay) === 0, json_encode(array_slice($pay, 0, 5)));
$noOra = rows("SELECT DocID,NrDoc FROM tblBonCurent WHERE Stare='I' AND Ora IS NULL");
test('bon inchis are Ora', count($noOra) === 0, json_encode(array_slice($noOra, 0, 5)));

section('B5 - arhiva Z');
test('tblBon: NrZ nu e NULL', (int)cell("SELECT COUNT(*) FROM tblBon WHERE NrZ IS NULL") === 0);
$maxArc = (int)cell("SELECT ISNULL(MAX(NrZ),0) FROM tblBon");
$lastZ = (int)cell("SELECT ISNULL(MAX(NrZ),0) FROM tblNrZ");
test('ultimul Z >= ultimul NrZ arhivat', $lastZ >= $maxArc, "tblNrZ=$lastZ maxArhiva=$maxArc");
info('bonuri arhivate=' . (int)cell("SELECT COUNT(*) FROM tblBon") . ' linii tempECR=' . (int)cell("SELECT COUNT(*) FROM tempECR") . ' randuri tblNrZ=' . (int)cell("SELECT COUNT(*) FROM tblNrZ"));

section('B6 - referinte rupte');
test('tblProd.KP exista in tblKP (sau 0)', (int)cell("SELECT COUNT(*) FROM tblProd p WHERE p.KP IS NOT NULL AND p.KP<>0 AND NOT EXISTS(SELECT 1 FROM tblKP k WHERE k.NrLogic=p.KP)") === 0);
test('tblProd.Nr_TVA exista in tblTVA', (int)cell("SELECT COUNT(*) FROM tblProd p WHERE p.Nr_TVA IS NOT NULL AND NOT EXISTS(SELECT 1 FROM tblTVA t WHERE t.Nr_TVA=p.Nr_TVA)") === 0);
test('tblProd.Sectie exista in tblSectii', (int)cell("SELECT COUNT(*) FROM tblProd p WHERE p.Sectie IS NOT NULL AND NOT EXISTS(SELECT 1 FROM tblSectii s WHERE s.Sectie=p.Sectie)") === 0);
test('tblGrp.Poz in 1..50', (int)cell("SELECT COUNT(*) FROM tblGrp WHERE Poz IS NULL OR Poz<1 OR Poz>50") === 0);
$dupMasa = rows("SELECT NrMasa, COUNT(*) n FROM tblMese GROUP BY NrMasa HAVING COUNT(*)>1");
test('tblMese.NrMasa unic', count($dupMasa) === 0, json_encode(array_slice($dupMasa, 0, 5)));
test('tblMese.NrMasa in 1..80', (int)cell("SELECT COUNT(*) FROM tblMese WHERE NrMasa<1 OR NrMasa>80") === 0);
test('tblNoteD fara bon parinte', (int)cell("SELECT COUNT(*) FROM tblNoteD d WHERE NOT EXISTS(SELECT 1 FROM tblBonCurent b WHERE b.DocID=d.DocID)") === 0);
test('Stare bon in (D,I)', (int)cell("SELECT COUNT(*) FROM tblBonCurent WHERE Stare NOT IN ('D','I')") === 0);
test('tblProd.Poz in 1..50', (int)cell("SELECT COUNT(*) FROM tblProd WHERE Poz IS NULL OR Poz<1 OR Poz>50") === 0);

section('B7 - cozi');
$stuck = rows("SELECT JobID,Tip,Stare,Attempts,DATEDIFF(MINUTE,NextAttempt,GETDATE()) AS min FROM tblPrintQueue WHERE Stare='printing' AND NextAttempt < DATEADD(MINUTE,-5,GETDATE())");
test('print: fara joburi blocate in printing >5min', count($stuck) === 0, json_encode(array_slice($stuck, 0, 5)));
$pf = rows("SELECT JobID,Tip,Stare,Attempts,LastError FROM tblPrintQueue WHERE Stare='failed'");
info('print failed=' . count($pf) . ' pending=' . (int)cell("SELECT COUNT(*) FROM tblPrintQueue WHERE Stare='pending'") . ' done=' . (int)cell("SELECT COUNT(*) FROM tblPrintQueue WHERE Stare='done'"));
$ss = rows("SELECT Id,Stare,Attempts,DATEDIFF(MINUTE,NextAttempt,GETDATE()) AS min FROM temp_Send_Sql WHERE Stare='sending' AND NextAttempt < DATEADD(MINUTE,-5,GETDATE())");
test('export: fara randuri blocate in sending >5min', count($ss) === 0, json_encode(array_slice($ss, 0, 5)));
info('export pending=' . (int)cell("SELECT COUNT(*) FROM temp_Send_Sql WHERE Stare='pending'") . ' sending=' . (int)cell("SELECT COUNT(*) FROM temp_Send_Sql WHERE Stare='sending'") . ' failed=' . (int)cell("SELECT COUNT(*) FROM temp_Send_Sql WHERE Stare='failed'") . ' done=' . (int)cell("SELECT COUNT(*) FROM temp_Send_Sql WHERE Stare='done'"));

section('B8 - culori (COLORREF -> hex)');
require_once ROOT_DIR . '/api/colors.php';
$badColors = [];
foreach (rows("SELECT NrGrp,Denumire,BackColor,FontColor FROM tblGrp") as $g) {
    foreach (['BackColor', 'FontColor'] as $col) {
        if ($g[$col] === null || $g[$col] === '') continue;
        $hex = winColorToHex((int)$g[$col]);
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string)$hex)) $badColors[] = "grp{$g['NrGrp']}.$col=" . $g[$col];
    }
}
foreach (rows("SELECT MasaID,NrMasa,BackColor,ForeColor FROM tblMese WHERE BackColor IS NOT NULL OR ForeColor IS NOT NULL") as $m) {
    foreach (['BackColor', 'ForeColor'] as $col) {
        if ($m[$col] === null || $m[$col] === '') continue;
        $hex = winColorToHex((int)$m[$col]);
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string)$hex)) $badColors[] = "masa{$m['NrMasa']}.$col=" . $m[$col];
    }
}
test('toate culorile se convertesc in hex valid', count($badColors) === 0, implode(', ', array_slice($badColors, 0, 8)));

section('B9 - semnale suplimentare (info)');
info('linii cu PV > PVC (pret peste catalog): ' . (int)cell("SELECT COUNT(*) FROM tblNoteD WHERE ProdID>0 AND PV > PVC"));
info('produse cu PV NULL/0: ' . (int)cell("SELECT COUNT(*) FROM tblProd WHERE PV IS NULL OR PV=0"));
info('bonuri deschise (Stare=D): ' . (int)cell("SELECT COUNT(*) FROM tblBonCurent WHERE Stare='D'"));

exit(summary('Faza B') > 0 ? 1 : 0);
