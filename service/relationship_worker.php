<?php

// Thin hook: one persisted relationship job runs in workers/Relationships/RelationshipRunner.php.
// A local worker accepts one durable relationship job ID, never request/provider overrides from HTTP.
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
if ($argc!==2 || !preg_match('/^[1-9][0-9]{0,17}$/D',$argv[1])) {
    fwrite(STDERR,"Usage: php service/relationship_worker.php <job-id>\n");exit(2);
}
$enginePath=dirname(__DIR__).DIRECTORY_SEPARATOR;
require_once $enginePath.'workers/Relationships/RelationshipRunner.php';
exit(\Synth\Workers\Relationships\RelationshipRunner::runJob($enginePath,intval($argv[1])));
