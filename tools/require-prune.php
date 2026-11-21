<?php
// Removes `require_once` statements for files that Composer now autoloads (token based, like globals-convert.php).
//   php tools/require-prune.php [--dry] <dir|file>...
// - legacy class files (core/dbmanager/Legacy/*.class.php and their lib/core/*.class.php hooks): the statement is deleted.
// - lib/classes/Logger.php: replaced by lib/autoload.php (Logger is autoloaded once the autoloader is loaded), or deleted
//   when the file already loads the autoloader.
// A statement is only touched when it is a whole statement (previous token is ; { } or an open tag); anything else
// (`if (...) require_once ...;`, `$x = require_once ...`) is listed on stderr for hand conversion.
$dry = false; $paths = [];
foreach (array_slice($argv, 1) as $a) { if ($a === '--dry') $dry = true; else $paths[] = $a; }
const CLASS_FILES = '/(llm_connector|tts_connector|stt_connector|itt_connector|npc_master|api_badge|core_profiles|narrator|player|import_rules)\.class\.php/';
$manual = []; $stats = ['deleted' => 0, 'logger_replaced' => 0, 'logger_deleted' => 0];

function sigp(array $t, int $i, int $dir): int {
    for ($i += $dir; $i >= 0 && $i < count($t); $i += $dir) if (!$t[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) return $i;
    return -1;
}
foreach ($paths as $p) {
    $files = is_file($p) ? [$p] : array_map('strval', iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS)), false));
    foreach ($files as $f) {
        $norm = str_replace('\\', '/', $f);
        if (substr($f, -4) !== '.php' || preg_match('#/(vendor|tests|unittests)/#', $norm) || str_contains($norm, 'tools/require-prune.php')) continue;
        $code = file_get_contents($f);
        if (!str_contains($code, 'require_once')) continue;
        $t = PhpToken::tokenize($code); $n = count($t);
        $hasAutoload = (bool)preg_match('/require_once[^;]*autoload\.php/', $code);
        $edits = [];
        for ($i = 0; $i < $n; $i++) {
            if (!$t[$i]->is(T_REQUIRE_ONCE)) continue;
            $d = 0; $end = -1;
            for ($q = $i + 1; $q < $n; $q++) {
                $s = $t[$q]->id < 256 ? $t[$q]->text : '';
                if ($s === '(' || $s === '[' || $s === '{' || $t[$q]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) $d++;
                elseif ($s === ')' || $s === ']' || $s === '}') $d--;
                elseif ($s === ';' && $d === 0) { $end = $q; break; }
            }
            if ($end < 0) continue;
            $stmt = ''; for ($q = $i; $q <= $end; $q++) $stmt .= $t[$q]->text;
            $isClass = preg_match(CLASS_FILES, $stmt) === 1;
            $isLogger = preg_match('#classes/Logger\.php#', $stmt) === 1;
            if (!$isClass && !$isLogger) continue;
            $pv = sigp($t, $i, -1); $pt = $pv >= 0 ? $t[$pv] : null;
            $whole = $pt === null || $pt->is(T_OPEN_TAG) || ($pt->id < 256 && in_array($pt->text, [';', '{', '}'], true));
            if (!$whole) { $manual[] = "$norm:" . $t[$i]->line . " not a whole statement: " . trim(substr($stmt, 0, 90)); continue; }
            if ($isClass || ($isLogger && $hasAutoload)) {
                // delete the statement and the whitespace that led it onto its own line
                $from = $i; while ($from > 0 && $t[$from - 1]->is(T_WHITESPACE) && !str_contains($t[$from - 1]->text, "\n")) $from--;
                $to = $end; if ($to + 1 < $n && $t[$to + 1]->is(T_WHITESPACE)) { $to++; $lines = substr_count($t[$to]->text, "\n"); }
                $edits[$from] = [$to, ($to > $end && isset($lines) && $lines > 1) ? "\n" : '', $isLogger ? 'logger_deleted' : 'deleted'];
            } else {
                $new = preg_replace('#classes/Logger\.php#', 'autoload.php', $stmt, 1);
                $edits[$i] = [$end, $new, 'logger_replaced']; $hasAutoload = true;
            }
        }
        if (!$edits) continue;
        ksort($edits); $out = ''; $i = 0;
        foreach ($edits as $s => [$e, $rep, $kind]) {
            if ($s < $i) continue;
            for (; $i < $s; $i++) $out .= $t[$i]->text;
            $out .= $rep; $i = $e + 1; $stats[$kind]++;
        }
        for (; $i < $n; $i++) $out .= $t[$i]->text;
        if (!$dry) file_put_contents($f, $out);
        echo "changed $norm\n";
    }
}
fwrite(STDERR, json_encode($stats) . "\n" . implode("\n", $manual) . "\n");
