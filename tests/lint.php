<?php
/**
 * Verificare de sintaxa (echivalentul pasilor obligatorii din AGENTS.md).
 * PHP: php -l pe toate fisierele; JS: node --check.
 */
$R = dirname(__DIR__);
$fail = 0; $n = 0;

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($R, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    $p = $f->getPathname();
    if (str_contains($p, DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR)) continue;
    if (str_contains($p, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR)) continue;
    if ($f->isDir()) continue;
    $ext = strtolower($f->getExtension());

    if ($ext === 'php') {
        $n++;
        exec('php -l ' . escapeshellarg($p) . ' 2>&1', $out, $rc);
        if ($rc !== 0) { $fail++; echo "FAIL php $p\n" . implode("\n", $out) . "\n"; }
        $out = [];
    } elseif ($ext === 'js') {
        $n++;
        exec('node --check ' . escapeshellarg($p) . ' 2>&1', $out, $rc);
        if ($rc !== 0) { $fail++; echo "FAIL js $p\n" . implode("\n", $out) . "\n"; }
        $out = [];
    }
}
echo "lint: $n fisiere verificate, $fail esecuri\n";
exit($fail > 0 ? 1 : 0);
