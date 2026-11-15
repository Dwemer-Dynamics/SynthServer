<?php

// Deferred inline, dedicated player and NPC-pair lanes share the existing scheduled manager.
$GLOBALS['TASKS']['native_relationship']['fn']=static function() {
    $process=proc_open([PHP_BINARY,dirname(__DIR__,2).'/relationship_dispatch.php'],
        [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
    if (!is_resource($process) || proc_close($process)!==0) throw new RuntimeException('Native relationship dispatch failed');
};
