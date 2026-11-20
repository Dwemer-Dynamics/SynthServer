<?php
// Dead code inside comments: rewrite $GLOBALS['N'] to \Synth\Bootstrap::config('N') so the commented code stays readable.
// Run AFTER convert.php. usage: php stripcomments.php file...
foreach (array_slice($argv, 1) as $f) {
    $t = PhpToken::tokenize(file_get_contents($f)); $out = ''; $ch = false;
    foreach ($t as $k) {
        if ($k->is([T_COMMENT, T_DOC_COMMENT]) && preg_match('/\$GLOBALS\[/', $k->text)) {
            $out .= preg_replace('/\$GLOBALS\[(["\'])(\w+)\1\]/', '\\Synth\\Bootstrap::config($1$2$1)', $k->text);
            $ch = true;
        } else $out .= $k->text;
    }
    if ($ch) { file_put_contents($f, $out); echo "commented $f\n"; }
}
