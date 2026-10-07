<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "player_tts_helpers.php");
require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "synth_runtime.php");
require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "request.php");
require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "chat_helper_functions.php");
require_once(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "response.php");

final class PlayerTtsHelpersTest extends TestCase
{
    public function testOnlyOwnedPortablePlayerSpeechUsesDeferredJobs(): void
    {
        $keys = ['SYNTH_RESPONSE_FORMAT', 'SYNTH_SPEECH_ROLE', 'SYNTH_PROTOCOL_REQUEST_CLAIMED', 'SYNTH_REQUEST_EVENT'];
        $saved = array_intersect_key($GLOBALS, array_flip($keys));
        try {
            $GLOBALS['SYNTH_RESPONSE_FORMAT'] = 'json';
            $GLOBALS['SYNTH_SPEECH_ROLE'] = 'player';
            $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'] = true;
            $event = ['protocol_version' => 2, 'type' => 'input_text', 'capabilities' => ['dialogue.turn_ownership']];
            $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json' => $event];
            foreach (['xtts-fastapi', 'chatterbox', 'pockettts', 'cartesia', 'inworld'] as $provider) {
                $plan = ['portable' => true, 'inputs' => ['TTSFUNCTION' => $provider]];
                $this->assertTrue(synth_should_defer_speech_tts($plan), $provider);
                $this->assertFalse(synth_should_defer_speech_tts(array_replace($plan, ['portable' => false])));
            }
            $plan = ['portable' => true, 'inputs' => ['TTSFUNCTION' => 'inworld']];
            foreach (['protocol_version' => 1, 'type' => 'context', 'capabilities' => []] as $field => $value) {
                $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json' => array_replace($event, [$field => $value])];
                $this->assertFalse(synth_should_defer_speech_tts($plan));
            }
            $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json' => $event];
            $this->assertFalse(synth_should_defer_speech_tts(['portable' => true, 'inputs' => ['TTSFUNCTION' => 'none']]));
            $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'] = false;
            $this->assertFalse(synth_should_defer_speech_tts($plan));
            $GLOBALS['SYNTH_SPEECH_ROLE'] = 'npc';
            $this->assertTrue(synth_should_defer_speech_tts($plan));
            $GLOBALS['SYNTH_RESPONSE_FORMAT'] = 'legacy';
            $this->assertFalse(synth_should_defer_speech_tts($plan));
        } finally {
            foreach ($keys as $key) unset($GLOBALS[$key]);
            foreach ($saved as $key => $value) $GLOBALS[$key] = $value;
        }
    }

    public function testPlayerProcessorRestoresSpeechRoleAfterTextOnlyEmptyAndFailedRequests(): void
    {
        $root=var_export(dirname(__DIR__,2),true);
        $code=<<<'PHP'
$root=ROOT_PATH;
require $root.'/lib/logger.php';
require $root.'/lib/chat_helper_functions.php';
require $root.'/lib/response.php';
require $root.'/lib/player_tts_helpers.php';
function audit_log($file=''): void {}
$GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>['protocol_version'=>2,'type'=>'input_text']];
$GLOBALS['db']=new class {
    public bool $fail=false;
    public array $roles=[];
    public function escape(string $value): string { return $value; }
    public function fetchOne(string $query): ?array {
        $this->roles[]=synth_protocol_current_speech_role();
        if ($this->fail) throw new RuntimeException('player-fixture-failure');
        return null;
    }
};
$results=[];
foreach (['text','empty','failed'] as $case) {
    $GLOBALS['SYNTH_NAME']='Player';
    $GLOBALS['SYNTH_SPEECH_ROLE']='npc';
    $GLOBALS['TTSFUNCTION']='none';
    $GLOBALS['SYNTH_JSON_RESPONSE_LINES']=[];
    $GLOBALS['db']->fail=$case==='failed';
    $gameRequest=['inputtext',0,0,$case==='empty'?'':'hello'];
    $error='';
    try { include $root.'/processor/player_tts.php'; }
    catch (RuntimeException $failure) { $error=$failure->getMessage(); }
    $results[$case]=['role'=>synth_protocol_current_speech_role(),'name'=>$GLOBALS['SYNTH_NAME'],
        'lines'=>$GLOBALS['SYNTH_JSON_RESPONSE_LINES'],'error'=>$error];
}
echo json_encode(['results'=>$results,'observed_roles'=>$GLOBALS['db']->roles],JSON_THROW_ON_ERROR);
PHP;
        $output=shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg(str_replace('ROOT_PATH',$root,$code)));
        $result=json_decode(strval($output),true,flags:JSON_THROW_ON_ERROR);
        $this->assertSame(['player','player','player'],$result['observed_roles']);
        foreach ($result['results'] as $case) {
            $this->assertSame('npc',$case['role']);
            $this->assertSame('Player',$case['name']);
        }
        $this->assertSame('player',$result['results']['text']['lines'][0]['speech_role']);
        $this->assertSame('player-fixture-failure',$result['results']['failed']['error']);
        $this->assertSame([],$result['results']['failed']['lines']);
        $this->assertSame([],$result['results']['empty']['lines']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['SYNTH_JSON_RESPONSE_LINES']);
    }

    public function testExtractsTextFromJsonPayload(): void
    {
        $payload = json_encode([
            'type' => 'inputtext',
            'text' => 'Hello there',
            'payload' => [
                'text' => 'Nested wins',
            ],
        ]);

        $this->assertSame(
            'Nested wins',
            synthExtractPlayerTtsDialogueLine($payload)
        );
    }

    public function testStripsPlayerPrefixAndUnsafeSeparators(): void
    {
        $this->assertSame(
            'Hello there friend',
            synthExtractPlayerTtsDialogueLine("Graussy: Hello|there\r\nfriend")
        );
    }

    public function testExtractsTextFromSYNTHInputEnvelopeWithTargetSuffix(): void
    {
        $payload = json_encode([
            'schema' => 'synth.input.v1',
            'npc' => 'Doc Mitchell',
            'npc_id' => '0x00104c0f',
            'player' => 'Graussy',
            'text' => 'hello',
            'skip_player_tts' => true,
            'synth_mode' => 'STANDARD',
            'game' => 'fo4',
        ]);

        $this->assertSame('hello', synthExtractPlayerTtsDialogueLine($payload));
        $this->assertTrue(synthShouldSkipPlayerTtsForRequest('inputtext', $payload));
    }

    public function testAdaptsStructuredInputForDialoguePipeline(): void
    {
        $GLOBALS['PLAYER_NAME'] = 'Graussy';
        unset(
            $GLOBALS['SYNTH_STRUCTURED_INPUT_FIELDS'],
            $GLOBALS['SYNTH_PLAYER_INPUT_TEXT'],
            $GLOBALS['SYNTH_INPUT_TARGET'],
            $GLOBALS['SYNTH_SKIP_PLAYER_TTS']
        );

        $payload = json_encode([
            'schema' => 'synth.input.v1',
            'npc' => 'Doc Mitchell',
            'npc_id' => '0x00104c0f',
            'player' => 'Graussy',
            'text' => 'hello',
            'skip_player_tts' => true,
            'audience_snapshot' => [
                'people' => '|Doc Mitchell|Graussy|Veronica|',
            ],
            'game' => 'fo4',
        ]);
        $gameRequest = ['inputtext', '1', '2', $payload];

        $result = synth_adapt_json_input_payload_for_pipeline($gameRequest);

        $this->assertTrue($result['changed']);
        $this->assertSame('Graussy: hello (Talking to Doc Mitchell)', $gameRequest[3]);
        $this->assertSame('hello', $GLOBALS['SYNTH_PLAYER_INPUT_TEXT']);
        $this->assertTrue($GLOBALS['SYNTH_SKIP_PLAYER_TTS']);
        $this->assertSame('{"people":"|Doc Mitchell|Graussy|Veronica|"}', $gameRequest[4]);
    }

    public function testRuntimePlayerTextExtractionRequiresJsonPayload(): void
    {
        $this->assertSame('', synth_extract_player_text('npc=Doc%20Mitchell&player=Graussy&text=hello'));

        $payload = json_encode([
            'schema' => 'synth.input.v1',
            'player' => 'Graussy',
            'text' => 'hello',
        ]);

        $this->assertSame('hello', synth_extract_player_text($payload));
    }

    public function testAudienceSnapshotPeopleCanUseJsonActorArray(): void
    {
        $payload = json_encode([
            'people' => [
                ['name' => 'Graussy'],
                ['actor_name' => 'Doc Mitchell'],
                ['display_name' => 'Veronica'],
                '|Graussy|',
            ],
        ]);

        $this->assertSame('|Graussy|Doc Mitchell|Veronica|', synthDecodeAudienceSnapshotField($payload));
    }

    public function testRechatPayloadPreservesActorFormIds(): void
    {
        $payload = synthParseServerSideRechatPayload(json_encode([
            'speaker' => 'Veronica',
            'listener_hint' => 'Deputy Weld',
            'rechat_target_hint' => 'Deputy Weld',
            'speaker_formid' => '0x000E32A9',
            'listener_formid' => '0x0002F586',
            'target_formid' => '0x0002F586',
        ]));

        $this->assertSame('0x000E32A9', $payload['speaker_formid']);
        $this->assertSame('0x0002F586', $payload['listener_formid']);
        $this->assertSame('0x0002F586', $payload['target_formid']);
    }

    public function testTextOnlyPlayerLineHasNoTtsCacheKey(): void
    {
        $GLOBALS['PLAYER_NAME'] = 'Graussy';
        $GLOBALS['SYNTH_JSON_RESPONSE_LINES'] = [];

        emitPlayerMenuTextOnlyLine('hello there');

        $this->assertCount(1, $GLOBALS['SYNTH_JSON_RESPONSE_LINES']);
        $line = $GLOBALS['SYNTH_JSON_RESPONSE_LINES'][0];
        $this->assertSame('Player', $line['speaker']);
        $this->assertSame('hello there', $line['text']);
        $this->assertSame('__player_text_only', $line['listener']);
        $this->assertArrayNotHasKey('tts_cache_key', $line);
        $this->assertArrayNotHasKey('tts_text', $line);
    }
}
