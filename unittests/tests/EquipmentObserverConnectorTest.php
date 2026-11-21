<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

final class EquipmentObserverConnectorTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCurrentObservationAndAcceptedInputSurviveActualOpenRouterTransformation(): void
    {
        ini_set('error_log', '/dev/null');
        // A complete test-only connector definition avoids importing runtime
        // configuration, database defaults or real credentials.
        $GLOBALS['CONNECTOR']['openrouterjson'] = [
            'url' => 'https://openrouter.ai/api/v1/chat/completions',
            'model' => 'google/gemini-2.5-flash-lite', 'API_KEY' => 'fixture-only',
            'max_tokens' => 512, 'temperature' => 0.7, 'presence_penalty' => 0,
            'frequency_penalty' => 0, 'repetition_penalty' => 1, 'top_p' => 1,
            'top_k' => 0, 'min_p' => 0, 'top_a' => 0,
            'ENFORCE_JSON' => true, 'PREFILL_JSON' => false, 'json_schema' => false,
        ];
        $GLOBALS['EMOTEMOODS'] = 'neutral';
        $GLOBALS['LANG_LLM_XTTS'] = false;
        $GLOBALS['MAX_WORDS_LIMIT'] = 0;
        $GLOBALS['FEATURES'] = [];
        $GLOBALS['PLAYER_NAME'] = 'Fixture Player';
        $GLOBALS['SYNTH_NAME'] = 'Fixture NPC';
        $GLOBALS['IS_NPC'] = true;
        $GLOBALS['SYNTH_NO_EXAMPLES'] = true;
        $GLOBALS['FUNCTIONS_ARE_ENABLED'] = true;
        $GLOBALS['COMMAND_PROMPT'] = '';
        $GLOBALS['BASE_FUNCTIONS'] = [];
        $GLOBALS['db'] = null;
        unset($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']);
        $event = ['protocol_version' => 2, 'type' => 'input_text', 'payload' => [],
            'capabilities' => ['dialogue.text', 'action.inspect_actor', 'action.check_inventory']];
        $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json' => $event];
        $GLOBALS['SYNTH_CLIENT_CAPABILITIES'] = $event['capabilities'];
        $utterance = 'Fixture Player: What gun am I holding now? (Talking to Fixture NPC)';
        $GLOBALS['gameRequest'] = ['inputtext', 1, 1, $utterance];
        require_once dirname(__DIR__, 2) . '/lib/logger.php';
        restore_error_handler();
        require_once dirname(__DIR__, 2) . '/lib/data_functions.php';
        require_once dirname(__DIR__, 2) . '/lib/core/llm_connector.class.php';
        require_once dirname(__DIR__, 2) . '/lib/synth_equipment_prompt.php';
        require_once dirname(__DIR__, 2) . '/functions/functions.php';
        require_once dirname(__DIR__, 2) . '/functions/json_response.php';
        require_once dirname(__DIR__, 2) . '/connector/openrouterjson.php';

        $player = ['form_id' => '0x00000014', 'origin_plugin' => 'Fallout4.esm',
            'playthrough_id' => 'connector-observer-save', 'display_name' => 'Fixture Player'];
        $history = [
            ['role' => 'system', 'content' => 'Keep the character personality.' . $GLOBALS['PROMPT_ACTIONS_LIST']],
            ['role' => 'system', 'content' => 'Conversation history: an earlier incorrect Laser Musket answer.'],
            ['role' => 'user', 'content' => 'Write the next character reply.'],
        ];
        $before = $history;
        $outgoing = [];
        $GLOBALS['mockConnectorSend'] = static function ($url, $context) use (&$outgoing) {
            $options = stream_context_get_options($context);
            $outgoing[] = json_decode($options['http']['content'], true, flags: JSON_THROW_ON_ERROR);
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, "data: [DONE]\n");
            rewind($stream);
            return $stream;
        };
        $GLOBALS['mockConnectorResponseMetaData'] = static fn(): array => ['wrapper_data' => ['HTTP/1.1 200 OK']];
        foreach ([['0x00004822', '10mm Pistol', true],
            ['0x00092217', 'Short Double-Barrel Shotgun', true],
            ['0x0001DACF', 'Short Laser Musket', false]] as [$id, $name, $drawn]) {
            $projection = synth_protocol_equipment_prompt([[
                'actor' => $player, 'inventory_observation' => 'complete', 'weapon_drawn' => $drawn,
                'inventory' => [['form_id' => $id, 'origin_plugin' => 'Fallout4.esm',
                    'display_name' => $name, 'form_type' => 43, 'equipped' => true]],
            ]], $player, []);
            $messages = synth_append_equipment_observer_context($history, $event, $projection,
                [['role' => 'user', 'content' => $utterance]]);
            $connector = new openrouterjson();
            self::assertTrue($connector->open($messages, []));
            $connector->close();
            $payload = end($outgoing);
            $sent = $payload['messages'];
            $observerIndices = [];
            $inputIndices = [];
            $historyIndex = null;
            foreach ($sent as $index => $message) {
                if (str_contains($message['content'], 'Conversation history:')) $historyIndex = $index;
                if (str_contains($message['content'], '<current_observed_equipment>')) $observerIndices[] = $index;
                if ($message['content'] === $utterance) $inputIndices[] = $index;
            }
            self::assertCount(1, $observerIndices);
            self::assertCount(1, $inputIndices);
            self::assertNotNull($historyIndex);
            self::assertGreaterThan($historyIndex, $observerIndices[0]);
            self::assertGreaterThan($observerIndices[0], $inputIndices[0]);
            self::assertSame('system', $sent[$observerIndices[0]]['role']);
            self::assertStringContainsString($name, $sent[$observerIndices[0]]['content']);
            self::assertStringContainsString('weapon_readiness="' . ($drawn ? 'held' : 'stowed') . '"',
                $sent[$observerIndices[0]]['content']);
            self::assertStringContainsString('Use ONLY this JSON object', end($sent)['content']);
            self::assertStringContainsString('does not refresh weapon or clothing observations', $sent[0]['content']);
            self::assertStringContainsString('acting NPC\'s own captured inventory', $sent[0]['content']);
            self::assertStringContainsString('not a player or actor selector', $sent[0]['content']);
            self::assertSame($before, $history);
        }
        self::assertCount(3, $outgoing);
    }
}
