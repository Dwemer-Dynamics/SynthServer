<?php

// Thin hook: the relationship dispatch pass lives in workers/Relationships/RelationshipRunner.php.
// Use a fresh CLI process so the manager cannot share its PostgreSQL socket or legacy request globals.
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
if ($argc!==1) {fwrite(STDERR,"Usage: php service/relationship_dispatch.php\n");exit(2);}
$enginePath=dirname(__DIR__).DIRECTORY_SEPARATOR;
exit(\Synth\Workers\Relationships\RelationshipRunner::dispatch($enginePath));
