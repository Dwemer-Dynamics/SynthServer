<?php
// PhpToken converter for the globals pass (writers, reads, isset/empty/unset, interpolation).
// usage: php convert.php [--names=A,B|*] [--dry] path...
// Never run on bootstrap.php.
$opts = ['names' => '*', 'dry' => false]; $paths = [];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--names=')) $opts['names'] = substr($a, 8);
    elseif ($a === '--dry') $opts['dry'] = true;
    else $paths[] = $a;
}
$only = $opts['names'] === '*' ? null : array_flip(explode(',', $opts['names']));
const ACCESSOR = ['db' => 'db', 'ENGINE_PATH' => 'enginePath', 'ENGINE_ROOT' => 'engineRoot',
    'SYNTH_REQUEST_EVENT' => 'requestEvent', 'SYNTH_PROTOCOL_REQUEST_CLAIMED' => 'claimedRequest', 'gameRequest' => 'gameRequest'];
const BYREF = ['sort','rsort','usort','uasort','uksort','ksort','krsort','asort','arsort','shuffle','array_push','array_pop',
    'array_shift','array_unshift','array_splice','array_walk','array_multisort','end','reset','next','prev','settype',
    'preg_match','preg_match_all','str_replace','str_ireplace','preg_replace_callback','list','natsort','natcasesort','extract','each'];
$stats = ['global' => 0, 'ref' => 0, 'read' => 0, 'write' => 0, 'compound' => 0, 'isset' => 0, 'unset' => 0, 'interp' => 0];
$manual = [];

function sig(array $t, int $i, int $dir = 1): int {
    $n = count($t);
    for ($i += $dir; $i >= 0 && $i < $n; $i += $dir) {
        if (!$t[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) return $i;
    }
    return -1;
}
function litKey(PhpToken $k): ?string {
    if (!$k->is(T_CONSTANT_ENCAPSED_STRING)) return null;
    $s = substr($k->text, 1, -1);
    return preg_match('/^[A-Za-z0-9_]+$/', $s) ? $s : null;
}
function call(string $name, string $key): string {
    if (isset(ACCESSOR[$name])) return '\\Synth\\Bootstrap::' . ACCESSOR[$name] . '()';
    return "\\Synth\\Bootstrap::config('$name')";
}
function pathOf(array $t, array $dimRanges): ?array {
    $keys = [];
    foreach ($dimRanges as [$o, $c]) $keys[] = trim(tokText($t, $o + 1, $c - 1));
    foreach ($keys as $i => $k) if ($k === '' && $i !== count($keys) - 1) return null;
    return $keys;
}
function matchClose(array $t, int $open): int {
    $pairs = ['(' => ')', '[' => ']', '{' => '}'];
    $n = count($t); $depth = 0;
    for ($i = $open; $i < $n; $i++) {
        $x = $t[$i]->id < 256 ? $t[$i]->text : "";
        if ($x === '(' || $x === '[' || $x === '{' || $t[$i]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) $depth++;
        elseif ($x === ')' || $x === ']' || $x === '}') { $depth--; if ($depth === 0) return $i; }
    }
    return -1;
}

function convertFile(string $code, ?array $only, array &$stats, array &$manual, string $file): string {
    $t = PhpToken::tokenize($code); $n = count($t);
    $edits = []; // start => [end, replacement]
    $covered = [];
    $inStr = false;
    // 1. interpolation in double-quoted strings
    for ($i = 0; $i < $n; $i++) {
        if ($t[$i]->text !== '"' || $t[$i]->id !== ord('"')) continue;
        // find closing quote
        $j = $i + 1; $depth = 0; $parts = [];
        for (; $j < $n; $j++) {
            $x = $t[$j];
            if ($x->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) $depth++;
            elseif ($x->id === 123 && $depth > 0) $depth++;
            elseif ($x->id === 125 && $depth > 0) $depth--;
            elseif ($x->id === 34 && $depth === 0) break;
        }
        if ($j >= $n) break;
        // walk the pieces
        $pieces = []; $buf = ''; $changed = false;
        for ($k = $i + 1; $k < $j; $k++) {
            $x = $t[$k];
            $m = null; $end = $k;
            if ($x->is(T_CURLY_OPEN) && $t[$k + 1]->text === '$GLOBALS' && $t[$k + 2]->text === '[') {
                $key = litKey($t[$k + 3]);
                if ($key !== null && $t[$k + 4]->text === ']') {
                    $e = $k + 4; $dims = '';
                    while ($t[$e + 1]->text === '[') { $c = matchClose($t, $e + 1); for ($q = $e + 1; $q <= $c; $q++) $dims .= $t[$q]->text; $e = $c; }
                    if ($t[$e + 1]->text === '}' && ($only === null || isset($only[$key]))) { $m = call($key, $key) . $dims; $end = $e + 1; }
                }
            } elseif ($x->is(T_VARIABLE) && $x->text === '$GLOBALS' && $t[$k + 1]->text === '[' && $t[$k + 2]->is([T_STRING, T_NUM_STRING]) && $t[$k + 3]->text === ']') {
                $key = $t[$k + 2]->text;
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) && ($only === null || isset($only[$key]))) { $m = call($key, $key); $end = $k + 3; }
            }
            if ($m !== null) {
                if ($buf !== '') $pieces[] = '"' . $buf . '"';
                $buf = ''; $pieces[] = $m; $changed = true; $k = $end; $stats['interp']++;
            } else $buf .= $x->text;
        }
        if ($changed) {
            if ($buf !== '') $pieces[] = '"' . $buf . '"';
            $rep = count($pieces) === 1 ? '((string)' . $pieces[0] . ')' : '(' . implode(' . ', $pieces) . ')';
            $edits[$i] = [$j, $rep];
        }
        $i = $j;
    }
    $inEdit = function (int $i) use (&$edits): bool { foreach ($edits as $s => [$e]) if ($i >= $s && $i <= $e) return true; return false; };

    // 1b. `global $a, $b;` becomes reference bindings to the same slots
    for ($i = 0; $i < $n; $i++) {
        if (!$t[$i]->is(T_GLOBAL)) continue;
        $vars = []; $q = $i + 1; $ok = true;
        for (; $q < $n && $t[$q]->text !== ';'; $q++) {
            if ($t[$q]->is(T_VARIABLE)) $vars[] = ltrim($t[$q]->text, '$');
            elseif (!$t[$q]->is([T_WHITESPACE, T_COMMENT]) && $t[$q]->text !== ',') { $ok = false; break; }
        }
        if (!$ok || !$vars || $q >= $n) { $manual[] = "$file:" . $t[$i]->line . " global-shape"; continue; }
        if ($only !== null && array_diff($vars, array_keys($only))) continue;
        if (in_array('this', $vars, true)) continue;
        $parts = [];
        foreach ($vars as $v) $parts[] = '$' . $v . " = &\\Synth\\Bootstrap::ref('$v')";
        $edits[$i] = [$q - 1, implode('; ', $parts)]; $stats['global'] += count($vars); $i = $q;
    }

    // 2. code uses
    for ($i = 0; $i < $n; $i++) {
        if (!$t[$i]->is(T_VARIABLE) || $t[$i]->text !== '$GLOBALS') continue;
        $o = sig($t, $i); if ($o < 0 || $t[$o]->text !== '[') continue;
        $kk = sig($t, $o); $cl = sig($t, $kk);
        if ($kk < 0 || $cl < 0 || $t[$cl]->text !== ']') continue;
        $key = litKey($t[$kk]); if ($key === null) continue;
        if ($only !== null && !isset($only[$key])) continue;
        if ($inEdit($i)) continue;
        $line = $t[$i]->line;
        // dims
        $e = $cl; $hasDims = false; $dimRanges = [];
        while (($nx = sig($t, $e)) >= 0 && $t[$nx]->text === '[') { $c = matchClose($t, $nx); if ($c < 0) break; $e = $c; $hasDims = true; $dimRanges[] = [$nx, $c]; }
        $after = sig($t, $e); $before = sig($t, $i, -1);
        $bt = $before >= 0 ? $t[$before] : null; $at = $after >= 0 ? $t[$after] : null;
        $accessor = call($key, $key);
        // by-ref / new / as &
        if ($bt && $bt->text === '&') {
            $bb = sig($t, $before, -1); $pk = pathOf($t, $dimRanges);
            if ($bb >= 0 && $t[$bb]->text === '=' && $pk !== null && !($hasDims && end($pk) === '')) {
                $edits[$i] = [$e, "\\Synth\\Bootstrap::ref('$key'" . ($hasDims ? ', [' . implode(', ', $pk) . ']' : '') . ')']; $stats['ref']++; continue;
            }
        }
        if ($bt && ($bt->text === '&' || $bt->is([T_NEW, T_AS, T_GLOBAL, T_STATIC]))) { $manual[] = "$file:$line byref/new $key"; continue; }
        // isset / empty / unset
        if ($bt && $bt->text === '(' ) {
            $kw = sig($t, $before, -1);
            if ($kw >= 0 && $t[$kw]->is(T_ISSET)) {
                $close = matchClose($t, $before);
                if ($at && $at->text === ')' && $after === $close) {
                    if (!$hasDims) { $edits[$kw] = [$close, "\\Synth\\Bootstrap::has('$key')"]; $stats['isset']++; }
                    else { $edits[$i] = [$e, $accessor . substr(tokText($t, $cl + 1, $e), 0)]; $stats['isset']++; }
                } else $manual[] = "$file:$line multi-isset $key";
                continue;
            }
            if ($kw >= 0 && $t[$kw]->is(T_EMPTY)) { $edits[$i] = [$e, $accessor . tokText($t, $cl + 1, $e)]; $stats['isset']++; continue; }
            if ($kw >= 0 && $t[$kw]->is(T_UNSET)) {
                $close = matchClose($t, $before);
                $pk = pathOf($t, $dimRanges);
                if (!$hasDims && $at && $at->text === ')' && $after === $close) { $edits[$kw] = [$close, "\\Synth\\Bootstrap::forget('$key')"]; $stats['unset']++; }
                elseif ($hasDims && $pk !== null && end($pk) !== '' && $at && $at->text === ')' && $after === $close) { $edits[$kw] = [$close, "\\Synth\\Bootstrap::unsetPath('$key', [" . implode(', ', $pk) . "])"]; $stats['unset']++; }
                else $manual[] = "$file:$line unset $key";
                continue;
            }
        }
        $isAssign = $at && ($at->is([T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL, T_MOD_EQUAL, T_COALESCE_EQUAL, T_INC, T_DEC]) || $at->text === '=');
        if ($isAssign) {
            $stmtStart = $bt === null || $bt->text === ';' || $bt->text === '{' || $bt->text === '}' || $bt->is([T_OPEN_TAG, T_ELSE]) || $bt->text === ')';
            $pk = $hasDims ? pathOf($t, $dimRanges) : [];
            $appendLast = $hasDims && $pk !== null && end($pk) === '';
            if ($pk === null || !$stmtStart || $at->is([T_INC, T_DEC]) || ($appendLast && $at->text !== '=')) { $manual[] = "$file:$line write-shape $key"; continue; }
            // expression end
            $d = 0; $x = sig($t, $after); $first = $x; $end = -1;
            for ($q = $x; $q < $n; $q++) {
                $s = $t[$q]->id < 256 ? $t[$q]->text : "";
                if ($t[$q]->is(T_CLOSE_TAG)) break;
                if ($s === '(' || $s === '[' || $s === '{' || $t[$q]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) $d++;
                elseif ($s === ')' || $s === ']' || $s === '}') { $d--; if ($d < 0) break; }
                elseif ($s === ';' && $d === 0) { $end = $q; break; }
            }
            if ($end < 0 || $t[$first]->text === '&') { $manual[] = "$file:$line write-expr $key"; continue; }
            $expr = trim(tokText($t, $x, sig($t, $end, -1)));
            if ($hasDims && count($pk) === 1) {
                $kx = $pk[0];
                if ($at->text === '=') $rep = $kx === '' ? "\\Synth\\Bootstrap::append('$key', $expr)" : "\\Synth\\Bootstrap::setAt('$key', $kx, $expr)";
                else { $op = substr($at->text, 0, -1); $rep = "\\Synth\\Bootstrap::setAt('$key', $kx, $accessor" . "[$kx] $op ($expr))"; }
                $stats['write']++;
            } elseif ($hasDims) {
                if ($appendLast) { array_pop($pk); $rep = "\\Synth\\Bootstrap::appendPath('$key', [" . implode(', ', $pk) . "], $expr)"; }
                elseif ($at->text === '=') $rep = "\\Synth\\Bootstrap::setPath('$key', [" . implode(', ', $pk) . "], $expr)";
                else { $op = substr($at->text, 0, -1); $rep = "\\Synth\\Bootstrap::setPath('$key', [" . implode(', ', $pk) . "], $accessor" . tokText($t, $cl + 1, $e) . " $op ($expr))"; }
                $stats['write']++;
            } elseif ($at->text === '=') { $rep = "\\Synth\\Bootstrap::set('$key', $expr)"; $stats['write']++; }
            else { $op = substr($at->text, 0, -1); $rep = "\\Synth\\Bootstrap::set('$key', $accessor $op ($expr))"; $stats['compound']++; }
            $edits[$i] = [sig($t, $end, -1), $rep];
            continue;
        }
        // enclosing call by-ref check
        $depth = 0; $byref = false;
        for ($q = $i - 1; $q >= 0; $q--) {
            $s = $t[$q]->id < 256 ? $t[$q]->text : "";
            if ($s === ')' || $s === ']' || $s === '}') $depth++;
            elseif ($s === '(' || $s === '[' || $s === '{') {
                if ($depth === 0) {
                    if ($s === '(') { $p = sig($t, $q, -1); if ($p >= 0 && $t[$p]->is([T_STRING, T_LIST, T_ARRAY]) && in_array(strtolower($t[$p]->text), BYREF, true)) $byref = true; }
                    break;
                }
                $depth--;
            } elseif ($s === ';' && $depth === 0) break;
        }
        // foreach by-ref
        $fe = false;
        for ($q = $i - 1; $q >= 0 && $q > $i - 40; $q--) { if ($t[$q]->is(T_FOREACH)) { for ($r = $i; $r < $i + 40 && $r < $n; $r++) { if ($t[$r]->is(T_AS)) { $nx = sig($t, $r); if ($nx >= 0 && $t[$nx]->text === '&') $fe = true; break; } } break; } if ($t[$q]->text === ';') break; }
        if ($byref || $fe) {
            $pk = pathOf($t, $dimRanges);
            if ($pk === null || ($hasDims && end($pk) === '')) { $manual[] = "$file:$line byref-call $key"; continue; }
            $edits[$i] = [$e, "\\Synth\\Bootstrap::ref('$key'" . ($hasDims ? ', [' . implode(', ', $pk) . ']' : '') . ')']; $stats['ref']++; continue;
        }
        $edits[$i] = [$e, $accessor . tokText($t, $cl + 1, $e)]; $stats['read']++;
    }
    // apply
    ksort($edits); $out = ''; $i = 0;
    foreach ($edits as $s => [$e, $rep]) {
        if ($s < $i) continue;
        for (; $i < $s; $i++) $out .= $t[$i]->text;
        $out .= $rep; $i = $e + 1;
    }
    for (; $i < $n; $i++) $out .= $t[$i]->text;
    return $out;
}
function tokText(array $t, int $from, int $to): string { $s = ''; for ($i = $from; $i <= $to; $i++) $s .= $t[$i]->text; return $s; }

foreach ($paths as $p) {
    $files = [];
    if (is_file($p)) $files[] = $p;
    else foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS)) as $f) $files[] = (string)$f;
    foreach ($files as $f) {
        $norm = str_replace('\\', '/', $f);
        if (substr($f, -4) !== '.php' || preg_match('#/(vendor)/#', $norm) || basename($f) === 'bootstrap.php' && !str_contains($norm, '/tests/')) continue;
        $code = file_get_contents($f);
        if (!str_contains($code, 'GLOBALS')) continue;
        $new = convertFile($code, $only, $stats, $manual, $norm);
        if ($new !== $code) {
            if (!$opts['dry']) file_put_contents($f, $new);
            echo "changed $norm\n";
        }
    }
}
fwrite(STDERR, json_encode($stats) . "\n");
file_put_contents(getenv('CONV_MANUAL') ?: 'php://stderr', implode("\n", $manual) . "\n");
