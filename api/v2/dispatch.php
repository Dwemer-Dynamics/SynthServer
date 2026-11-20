<?php

// Thin v2 dispatch for explicit native dialogue: conversation-intent typed turns and speech receipts.
// main.php selects it from the single validated wire event before main_synth_pipeline.php is loaded; every
// other event, including unmarked typed text, keeps the legacy pipeline.
require_once dirname(__DIR__, 2).'/lib/request.php';
require_once dirname(__DIR__, 2).'/lib/synth_protocol_state.php';
require_once dirname(__DIR__, 2).'/lib/player2_health.php';
require_once dirname(__DIR__, 2).'/bootstrap.php';
require_once __DIR__.'/routes.php';

use Synth\Api\V2\ProtocolOutput;
use Synth\Bootstrap;
use Synth\Managers\LocationManager\LocationCatalog;

// Claim, replay and pending semantics are the shared canonical ones. A conversation turn owns its own
// terminal output; a receipt is acknowledged with its stored state or refused with a specific code.
function synth_api_v2_dispatch(string $root, array $event, string $route): void
{
    $app = Bootstrap::boot($root);
    $output = new ProtocolOutput($app->db, $event);
    $output->open();
    $legacy = static fn(callable $call) => synth_api_v2_legacy_scope($app, $call);
    // Request-wide only the start-line server version used by the shared frame encoders.
    if (array_key_exists('SYNTH_SERVER_VERSION', $app->config)) $GLOBALS['SYNTH_SERVER_VERSION'] = $app->config['SYNTH_SERVER_VERSION'];
    try {
        // Validated and logged only until the catalog has storage: no row is written, so there is nothing to claim.
        if ($route === 'location_catalog') { synth_api_v2_location_catalog($output, $event); return; }
        // A save signal carries no turn: it is validated and recorded through the session manager, takes no request claim
        // and replays freely (the record is idempotent per save).
        if ($route === 'save_created') { synth_api_v2_save_created($app, $output, $event); return; }
        $legacy(synthPlayer2HealthMarkGameActivity(...));
        $claim = synth_protocol_claim_request($app->db, $event, true);
        if (($claim['status'] ?? '') === 'replay') { synth_protocol_emit_replay($claim); return; }
        if (($claim['status'] ?? '') === 'pending') {
            synth_reject_json_request(409, 'An identical request is already being processed', [
                'code' => 'request_pending', 'retryable' => true]);
        }
        // The one explicit owner of every persistence and admission write below, returned by the committed claim
        // itself; no request global is claimed. It is bound to the output before liveness is checked, so a
        // cancellation or supersession since the claim is still concluded (recorded) as this claim's cancelled outcome.
        $owner = $claim['owner'];
        $output->claimed($owner);
        $output->assertActive();
        if ($route === 'narrator') { $app->privateNarrator($output, $owner, $legacy)->handle($event); return; }
        if ($route === 'conversation') { require_once dirname(__DIR__, 2).'/lib/synth_control.php'; synth_control_note_talk_slot($app->db, $event); $app->dialogue($output, $owner, $legacy)->converse($event); return; }
        try { $state = $app->receipts($owner)->receipt($event); }
        catch (DomainException $error) {
            $output->terminal(in_array($error->getMessage(), ['receipt_conflict', 'receipt_owner_mismatch'], true)
                ? $error->getMessage() : 'receipt_invalid');
            return;
        }
        $output->ack('speech_receipt_accepted', ['detail' => $state]);
    } catch (SYNTHProtocolCancelled) {
        $output->cancelled();
    } catch (Throwable) {
        Logger::error('[request] backend_request_failed');
        $output->failed();
    }
}

// Location catalog export: the event is already shape-validated by ingress. Per-record refusals are reported in the
// acknowledgement; nothing is stored yet (docs/LOCATION-CATALOG.md).
function synth_api_v2_location_catalog(ProtocolOutput $output, array $event): void
{
    $review = LocationCatalog::review($event['payload']);
    LocationCatalog::logRequest($event, $review);
    $output->ack('location_catalog_accepted', ['detail' => LocationCatalog::detail($review)]);
}

// The player made a save. SessionManager owns the meaning (SaveState holds the save policy); nothing is stored yet, so
// the acknowledgement only confirms the signal was accepted for the named playthrough.
function synth_api_v2_save_created(Bootstrap $app, ProtocolOutput $output, array $event): void
{
    $app->sessions()->saveCreated($event);
    $output->ack('save_created_accepted');
}

// Legacy compatibility for one adapter call: the game-activity marker (player2 health options), the TTS
// connector/NPC voice resolver, API-badge credential lookup, speech plan capture and worker launch read the
// shared connection and voice directories from runtime globals. Only these named values are installed, only for
// that call, then restored. No claimed request or event is ever installed: persistence authority is the
// explicit owner. Retire each entry when its consumer accepts explicit inputs.
function synth_api_v2_legacy_scope(Bootstrap $app, callable $call)
{
    $values = ['db' => $app->db];
    foreach (['SYNTH_VOICE_SAMPLE_DIR', 'VOICE_SAMPLE_DIR', 'VOICE_DIR'] as $key) {
        if (array_key_exists($key, $app->config)) $values[$key] = $app->config[$key];
    }
    $previous = [];
    foreach ($values as $key => $value) {
        if (array_key_exists($key, $GLOBALS)) $previous[$key] = $GLOBALS[$key];
        $GLOBALS[$key] = $value;
    }
    try {
        return $call();
    } finally {
        foreach ($values as $key => $_) {
            if (array_key_exists($key, $previous)) $GLOBALS[$key] = $previous[$key];
            else unset($GLOBALS[$key]);
        }
    }
}
