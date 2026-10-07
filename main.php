<?php

error_reporting(E_ALL);
ini_set("display_errors", "0");

$root = __DIR__;

require_once $root . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "logger.php";
require_once $root . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "background_processor.php";

Logger::bootstrapRequestId("main");
Logger::rotateKnownLogs($root);

// Game traffic is the authoritative self-healing path. This keeps automatic
// memories running even when the user never opens the SYNTH home page.
if (function_exists('synthEnsureBackgroundProcessorRunning')) {
    synthEnsureBackgroundProcessorRunning(true);
}

try {
    require $root . DIRECTORY_SEPARATOR . "main_synth_pipeline.php";
} catch (SYNTHProtocolCancelled $e) {
    // A closed response cannot be reopened by deferred work that lost its owner.
    synth_emit_cancelled_json_response();
    synth_protocol_complete_request($GLOBALS['db'], 'cancelled', 'application/x-ndjson',
        strval($GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY'] ?? ''), 'request_cancelled');
} catch (SYNTHTestRequestTerminated $e) {
    if (!getenv("PHPUNIT_TEST")) {
        throw $e;
    }
}

?>
