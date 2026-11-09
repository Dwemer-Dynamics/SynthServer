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
    // The body is decoded and validated once. Explicit native dialogue (conversation intent and speech
    // receipts) is dispatched here; all other events, the CLI and in-process tests keep the legacy pipeline.
    if (PHP_SAPI !== 'cli' && !getenv('PHPUNIT_TEST')) {
        require_once $root . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "request.php";
        $synthIngressEvent = synth_decode_wire_event();
        $synthApiPath = $root . DIRECTORY_SEPARATOR . "api" . DIRECTORY_SEPARATOR . "v2" . DIRECTORY_SEPARATOR;
        require_once $synthApiPath . "routes.php";
        $synthApiRoute = synth_api_v2_route($synthIngressEvent);
        if ($synthApiRoute !== null) {
            require_once $synthApiPath . "dispatch.php";
            Logger::setRequestId((string)$synthIngressEvent["request_id"]);
            synth_api_v2_dispatch($root, $synthIngressEvent, $synthApiRoute);
            exit;
        }
    }
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
} catch (Throwable $e) {
    Logger::error('[request] backend_request_failed');
    if (function_exists('synth_emit_failed_json_response')
        && is_array($GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? null)) {
        synth_emit_failed_json_response();
    } else {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode(['schema'=>'synth.error.response.v1', 'code'=>'backend_request_failed',
            'retryable'=>false], JSON_THROW_ON_ERROR);
    }
}

?>
