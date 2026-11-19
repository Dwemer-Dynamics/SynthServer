<?php

// Thin hook: the memory dispatch pass lives in workers/Memory/MemoryRunner.php.
// Run in a fresh process: a fork of the legacy manager must not share its PostgreSQL socket.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 1) { fwrite(STDERR,"Usage: php service/memory_dispatch.php\n"); exit(2); }
$enginePath = dirname(__DIR__).DIRECTORY_SEPARATOR;
require_once $enginePath.'workers/Memory/MemoryRunner.php';
exit(\Synth\Workers\Memory\MemoryRunner::dispatch($enginePath));
