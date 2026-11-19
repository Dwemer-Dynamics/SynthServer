<?php

// Thin hook: one persisted memory job runs in workers/Memory/MemoryRunner.php.
// A local worker accepts one durable job ID; no HTTP dispatch, request body or provider override.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 2 || !preg_match('/^[1-9][0-9]{0,17}$/D',$argv[1])) {
    fwrite(STDERR,"Usage: php service/memory_worker.php <job-id>\n");
    exit(2);
}
$enginePath = dirname(__DIR__).DIRECTORY_SEPARATOR;
require_once $enginePath.'workers/Memory/MemoryRunner.php';
exit(\Synth\Workers\Memory\MemoryRunner::runJob($enginePath,intval($argv[1])));
