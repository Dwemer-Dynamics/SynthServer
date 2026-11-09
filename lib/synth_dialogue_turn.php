<?php

// Transitional protocol edge for explicit conversation-intent (/talk) turns dispatched from
// main_synth_pipeline.php. Composes the dialogue services; legacy response/request globals stay here.
require_once __DIR__.'/synth_protocol_state.php';
require_once __DIR__.'/response.php';
require_once __DIR__.'/managers/DialogueManager.php';
require_once __DIR__.'/core/dialogue/CharacterRepository.php';
require_once __DIR__.'/drivers/llm/OpenAiCompatibleDriver.php';
require_once __DIR__.'/drivers/tts/ProfileSpeechDriver.php';

use Synth\Core\CharacterRepository;
use Synth\Core\SaveState;
use Synth\Core\TurnOutput;
use Synth\Core\UtteranceRepository;
use Synth\Drivers\Llm\OpenAiCompatibleDriver;
use Synth\Drivers\Tts\ProfileSpeechDriver;
use Synth\Managers\CharacterManager;
use Synth\Managers\ContextManager;
use Synth\Managers\DialogueManager;
use Synth\Managers\PromptManager;
use Synth\Managers\SessionManager;
use Synth\Managers\SpeechManager;

// Writes the NDJSON response and request completion for the claimed request.
final class SynthProtocolTurnOutput implements TurnOutput
{
    public function __construct(private $db) {}

    public function assertActive(): void
    {
        synth_protocol_assert_request_active();
    }

    public function allocateLine(): string
    {
        return synth_protocol_next_line_suffix('dialogue');
    }

    public function dialogue(string $suffix, string $lineId, array $payload): void
    {
        synth_protocol_ensure_response_started();
        synth_protocol_write_response_line('dialogue', $payload, $suffix, ['utterance_id' => $lineId]);
    }

    public function complete(): void
    {
        synth_protocol_write_response_line('response_end', ['status' => 'complete'], 'end');
        $GLOBALS['SYNTH_JSON_RESPONSE_STREAM_FINAL_EMITTED'] = true;
        synth_protocol_complete_request($this->db, 'complete', 'application/x-ndjson', strval($GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY'] ?? ''));
    }

    // Truthful terminal failure. Lines already sent stay generated (unheard unless receipted).
    public function fail(string $code, string $detail): void
    {
        if (empty($GLOBALS['SYNTH_PROTOCOL_RESPONSE_STARTED'])) { synth_protocol_emit_terminal_error($code, $detail); return; }
        synth_protocol_write_response_line('status', ['code' => $code, 'detail' => $detail], 'status');
        synth_protocol_write_response_line('response_end', ['status' => 'error'], 'end');
        $GLOBALS['SYNTH_JSON_RESPONSE_STREAM_FINAL_EMITTED'] = true;
        synth_protocol_complete_request($this->db, 'failed', 'application/x-ndjson', strval($GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY'] ?? ''), $code);
    }

    // An explicit conversation request never silently goes legacy.
    public function reject(string $reason): void
    {
        synth_protocol_emit_terminal_error('native_turn_ineligible', $reason);
    }
}

function synth_dialogue_turn_handle($db, array $event): void
{
    $output = new SynthProtocolTurnOutput($db);
    $saves = new SaveState($db);
    $root = dirname(__DIR__);
    // Server-wide speech language/filter configuration only.
    $speechConfig = array_intersect_key($GLOBALS, array_flip(['LANG_LLM_XTTS', 'TTS_FFMPEG_FILTERS']));
    (new DialogueManager(new SessionManager($saves), new CharacterManager(new CharacterRepository($db)),
        new ContextManager($saves), new PromptManager(), new OpenAiCompatibleDriver($output->assertActive(...)),
        new SpeechManager(new ProfileSpeechDriver($db, $root, $speechConfig)), new UtteranceRepository($db), $output))
        ->converse($event);
}
