<?php
// usage: php inv.php dir|file...  ; classify remaining global uses by name and kind
$names = []; $total = 0;
$skip = static fn($p) => preg_match('#/(tests|unittests|vendor)/#', str_replace('\\', '/', $p));
foreach (array_slice($argv, 1) as $d) {
    $files = [];
    if (is_file($d)) { $files[] = $d; }
    else {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS)) as $f) {
            $files[] = (string)$f;
        }
    }
    foreach ($files as $f) {
        if (substr($f, -4) !== '.php' || $skip($f)) continue;
        $t = PhpToken::tokenize(file_get_contents($f)); $n = count($t);
        for ($i = 0; $i < $n; $i++) {
            if ($t[$i]->is(T_GLOBAL)) {
                for ($j = $i + 1; $j < $n && !$t[$j]->is(';'); $j++) {
                    if ($t[$j]->is(T_VARIABLE)) { $k = ltrim($t[$j]->text, '$'); $names[$k]['global'] = ($names[$k]['global'] ?? 0) + 1; $total++; }
                }
            } elseif ($t[$i]->is(T_VARIABLE) && $t[$i]->text === '$GLOBALS') {
                $s = trim($t[$i + 2]->text ?? '', "'\"");
                $names[$s]['GLOBALS'] = ($names[$s]['GLOBALS'] ?? 0) + 1; $total++;
            }
        }
    }
}
uasort($names, fn($a, $b) => array_sum($b) <=> array_sum($a));
foreach ($names as $k => $v) echo str_pad($k, 40), json_encode($v), "\n";
echo "total $total\n";
