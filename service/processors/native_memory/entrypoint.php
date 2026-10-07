<?php

$GLOBALS['TASKS']['native_memory']['fn'] = static function() {
    $process = proc_open([PHP_BINARY,dirname(__DIR__,2).'/memory_dispatch.php'],
        [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
    // Do not relay provider output or inherited legacy-manager state into scheduler logs.
    if (!is_resource($process) || proc_close($process)!==0) throw new RuntimeException('Native dispatch failed');
};
