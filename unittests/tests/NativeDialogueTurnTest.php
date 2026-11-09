<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/request.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';
require_once dirname(__DIR__, 2) . '/lib/synth_dialogue_turn.php';

use Synth\Core\CharacterKey;
use Synth\Core\CharacterRepository;
use Synth\Core\LlmDriver;
use Synth\Core\PlacedCharacter;
use Synth\Core\SaveState;
use Synth\Core\SpeechDriver;
use Synth\Core\TurnOutput;
use Synth\Core\TurnRequest;
use Synth\Core\UtteranceRepository;
use Synth\Drivers\Llm\OpenAiCompatibleDriver;
use Synth\Managers\CharacterManager;
use Synth\Managers\ContextManager;
use Synth\Managers\DialogueManager;
use Synth\Managers\PromptManager;
use Synth\Managers\SessionManager;
use Synth\Managers\SpeechManager;

final class NativeDialogueTurnTest extends TestCase
{
    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/protocol/v2/fixtures/'.$name),true,flags:JSON_THROW_ON_ERROR);
    }

    public function testCharacterKeyUsesPlacedReferenceFromValidatedWireIdentities(): void
    {
        $receipt=$this->fixture('valid/speech-receipt.json');
        $wire=$receipt['payload']['character'];
        $speaker=json_decode(explode("\n",file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/response-native-turn.ndjson'))[1],true)['payload']['speaker'];
        $key=CharacterKey::of($wire);
        $this->assertMatchesRegularExpression('/^chr:[0-9a-f]{40}$/D',strval($key));
        $this->assertSame($key,CharacterKey::of($speaker));
        $this->assertSame($key,CharacterKey::of(['form_id'=>'0x0001a4d7','origin_plugin'=>'FALLOUT4.ESM','playthrough_id'=>'save-1']));
        // A regular plugin's load-order byte and a light plugin's index are not part of the placed reference.
        $this->assertSame(CharacterKey::of(['form_id'=>'0x05000801']+$wire),CharacterKey::of(['form_id'=>'0x07000801']+$wire));
        $this->assertSame(CharacterKey::of(['form_id'=>'0xFE001801']+$wire),CharacterKey::of(['form_id'=>'0xFE002801']+$wire));
        $this->assertNotSame($key,CharacterKey::of(['playthrough_id'=>'save-2']+$wire));
        $this->assertNotSame($key,CharacterKey::of(['origin_plugin'=>'DLCRobot.esm']+$wire));
        foreach (['0xFF000802','0X0001A4D7','0x0x01A4D7','0001A4D7','0x1A4D7',''] as $form)
            $this->assertNull(CharacterKey::of(['form_id'=>$form]+$wire),$form);
    }

    public function testReceiptIngressAcceptsOnlyOwnedLineFixtures(): void
    {
        foreach (glob(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/speech-receipt*.json') as $file) {
            $event=json_decode(file_get_contents($file),true,flags:JSON_THROW_ON_ERROR);
            $this->assertSame($event,synth_protocol_validate_event($event),basename($file));
        }
        $init=$this->fixture('valid/input-text-native-turn.json');
        $this->assertSame($init,synth_protocol_validate_event($init));
        $invalid=[];
        foreach ([...glob(dirname(__DIR__,2).'/protocol/v2/fixtures/invalid/speech-receipt*.json'),
            ...glob(dirname(__DIR__,2).'/protocol/v2/fixtures/invalid/input-text-intent*.json')] as $file)
            $invalid[basename($file)]=json_decode(file_get_contents($file),true,flags:JSON_THROW_ON_ERROR);
        $nativeWithoutOwnership=$this->fixture('valid/input-text-native-turn.json');
        $nativeWithoutOwnership['capabilities']=['dialogue.text','dialogue.native_turn'];
        $invalid['native-without-ownership']=$nativeWithoutOwnership;
        $this->assertGreaterThanOrEqual(8,count($invalid));
        foreach ($invalid as $name=>$event) {
            $script='require '.var_export(dirname(__DIR__,2).'/lib/request.php',true).';synth_protocol_validate_event(json_decode('
                .var_export(json_encode($event),true).',true));echo "ACCEPTED";';
            $output=strval(shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script)));
            $this->assertStringNotContainsString('ACCEPTED',$output,$name);
            $this->assertFalse(json_decode($output,true)['ok'] ?? true,$name);
        }
    }

    private function parse(array $lines): string
    {
        $text='';
        OpenAiCompatibleDriver::parseSse(static function() use(&$lines) { return array_shift($lines) ?? false; },
            static function(string $delta) use(&$text): void { $text.=$delta; },static function(): void {});
        return $text;
    }

    public function testProviderStreamRequiresTerminalCompletion(): void
    {
        $chunk=static fn(string $text, ?string $finish=null)=>'data: '.json_encode(['choices'=>[['delta'=>['content'=>$text],'finish_reason'=>$finish]]])."\n";
        $this->assertSame('Hello there.',$this->parse([": keepalive\n",$chunk('Hello '),$chunk('there.','stop'),"data: [DONE]\n"]));
        $this->assertSame('Hi.',$this->parse([$chunk('Hi.','stop')]));
        $this->assertSame('Hi.',$this->parse([$chunk('Hi.'),"data: [DONE]\n"]));
        $failures=['truncated'=>[$chunk('Hello')],'empty'=>[],'length'=>[$chunk('Hel','length')],
            'malformed'=>[$chunk('Hi'),"data: {not json\n"],'error'=>['data: {"error":{"message":"x"}}'."\n"],
            'non_sse'=>['{"choices":[]}'."\n"],'after_finish'=>[$chunk('Hi.','stop'),$chunk('More')],
            'non_text_delta'=>['data: {"choices":[{"delta":{"content":7}}]}'."\n"]];
        foreach ($failures as $name=>$lines) {
            try { $this->parse($lines); $this->fail($name); }
            catch (RuntimeException $error) {
                $this->assertContains($error->getMessage(),['provider_stream_incomplete','provider_stream_malformed'],$name);
            }
        }
    }

    public function testRequestBodyPreservesExplicitConnectorOptionsWithoutCredentials(): void
    {
        $config=['url'=>'http://127.0.0.1:9/v1/chat/completions','model'=>'local-model',
            'api_key'=>'secret-key','max_tokens'=>4096,'temperature'=>0.4,'top_p'=>0.9,'presence_penalty'=>0.1,'frequency_penalty'=>0.2];
        $body=json_decode(OpenAiCompatibleDriver::requestBody($config,[['role'=>'user','content'=>'Hi']]),true);
        $this->assertSame(['model'=>'local-model','messages'=>[['role'=>'user','content'=>'Hi']],'stream'=>true,'max_tokens'=>2048,
            'temperature'=>0.4,'top_p'=>0.9,'presence_penalty'=>0.1,'frequency_penalty'=>0.2],$body);
        $this->assertStringNotContainsString('secret-key',OpenAiCompatibleDriver::requestBody($config,[]));
    }

    public function testOnlyPlainOpenAiCompatibleConnectorsAreOwned(): void
    {
        $base=['driver'=>'openaijson','url'=>'http://127.0.0.1:9/v1/chat/completions','model'=>'m','reasoning_model'=>false,'metadata'=>'{}'];
        $driver=new OpenAiCompatibleDriver(static function(): void {});
        $this->assertTrue($driver->supports($base));
        foreach ([['driver'=>'player2json'],['driver'=>'openrouterjson'],['reasoning_model'=>true],['url'=>''],['model'=>''],
            ['metadata'=>'{"quickstart_managed":true}']] as $change) {
            $this->assertFalse($driver->supports($change+$base),json_encode($change));
        }
        $this->expectException(DomainException::class);
        $driver->stream(['driver'=>'player2json']+$base,[],static function(string $delta): void {});
    }

    public function testSentencesStreamAsCompleteLines(): void
    {
        $buffer='Hello there. How are';
        $this->assertSame(['Hello there.'],DialogueManager::takeSentences($buffer,false));
        $this->assertSame('How are',$buffer);
        $buffer.=' you?" Fine';
        $this->assertSame(['How are you?"'],DialogueManager::takeSentences($buffer,false));
        $this->assertSame(['Fine'],DialogueManager::takeSentences($buffer,true));
    }

    // Dialogue services over injected repository, driver and output fakes (no database or provider).
    private function dialogue(?array $context, ?array $row, array $others=[], array $chunks=[], bool $speech=true): array
    {
        $saves=new class($context) extends SaveState {
            public function __construct(private ?array $snapshot) {}
            public function boundContext(array $event): ?array
            {
                return $this->snapshot===null ? null : ['payload'=>$this->snapshot,'player'=>$this->snapshot['player'],'target'=>$this->snapshot['target'] ?? null];
            }
            public function conversationRows(array $event, string $targetName, int $limit): array
            {
                return [['type'=>'chat','data'=>$targetName.': Earlier. (talking to Nate)'],['type'=>'inputtext','data'=>'Nate: Hello. (Talking to '.$targetName.')']];
            }
        };
        $characters=new class($row,$others) extends CharacterRepository {
            public function __construct(private ?array $row, private array $others) {}
            public function registered(string $fullFormId, string $originPlugin, string $playthroughId): ?array { return $this->row; }
            public function otherFormIds(string $originPlugin, string $playthroughId, int $identityId): array { return $this->others; }
            public function npc(int $id): ?array { return ['id'=>$id,'npc_name'=>'Jun','personality'=>'Weary.']; }
            public function profile(int $id): ?array { return ['id'=>$id,'tts_connector_id'=>5]; }
        };
        $utterances=new class extends UtteranceRepository {
            public array $rows=[];
            public function __construct() {}
            public function recordInput(TurnRequest $request, string $data): void { $this->rows[]=$data; }
            public function recordLine(TurnRequest $request, PlacedCharacter $character, string $lineId, string $text): void { $this->rows[]=[$lineId,$text]; }
        };
        $output=new class implements TurnOutput {
            public array $events=[];
            private int $line=0;
            public function assertActive(): void {}
            public function allocateLine(): string { return 'dialogue:'.++$this->line; }
            public function dialogue(string $suffix, string $lineId, array $payload): void { $this->events[]=[$lineId,$payload['text'],$payload['speech']['cache_key']]; }
            public function complete(): void { $this->events[]=['complete']; }
            public function fail(string $code, string $detail): void { $this->events[]=['fail',$code,$detail]; }
            public function reject(string $reason): void { $this->events[]=['reject',$reason]; }
        };
        $llm=new class($chunks) implements LlmDriver {
            public array $messages=[];
            public function __construct(private array $chunks) {}
            public function supports(array $connector): bool { return ($connector['driver'] ?? '')==='openaijson'; }
            public function stream(array $connector, array $messages, callable $onText): void { $this->messages=$messages; foreach ($this->chunks as $chunk) $onText($chunk); }
        };
        $tts=new class($speech) implements SpeechDriver {
            public function __construct(private bool $speech) {}
            public function settings(array $npcRow, array $profile): ?array { return ['TTSFUNCTION'=>'xtts-fastapi']; }
            public function queue(array $speaker, string $lineId, string $text, array $settings): string
            {
                if (!$this->speech) throw new RuntimeException('tts down');
                return 'cache-'.$lineId;
            }
        };
        $manager=new DialogueManager(new SessionManager($saves),new CharacterManager($characters),new ContextManager($saves),
            new PromptManager(),$llm,new SpeechManager($tts),$utterances,$output);
        return [$manager,$output,$utterances,$llm];
    }

    private function talkContext(): array
    {
        return ['player'=>['form_id'=>'0x00000014','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'save-1','display_name'=>'Nate'],
            'target'=>$this->fixture('valid/speech-receipt.json')['payload']['character'],'audience'=>[],'world'=>['game_time_ticks'=>42]];
    }

    public function testAdmittedTurnStreamsBoundedVoicedLinesAndEndsTruthfully(): void
    {
        $event=$this->fixture('valid/input-text-native-turn.json');
        $row=['identity_id'=>9,'npc_id'=>3,'profile_id'=>4,'driver'=>'openaijson','url'=>'http://127.0.0.1:9/v1','model'=>'m'];
        [$manager,$output,$utterances,$llm]=$this->dialogue($this->talkContext(),$row,[],['Holding, barely. Thanks','.']);
        $manager->converse($event);
        $id=$event['request_id'];
        $this->assertSame([[$id.':dialogue:1','Holding, barely.','cache-'.$id.':dialogue:1'],[$id.':dialogue:2','Thanks.','cache-'.$id.':dialogue:2'],['complete']],$output->events);
        $this->assertStringEndsWith(': '.trim($event['payload']['text']).' (Talking to '.$this->talkContext()['target']['display_name'].')',$utterances->rows[0]);
        $this->assertSame([[$id.':dialogue:1','Holding, barely.'],[$id.':dialogue:2','Thanks.']],array_slice($utterances->rows,1));
        $this->assertSame([['user','Hello.'],['assistant','Earlier.'],['user',trim($event['payload']['text'])]],
            array_map(static fn($m)=>[$m['role'],$m['content']],array_slice($llm->messages,1)));
        $this->assertStringContainsString('Personality: Weary.',$llm->messages[0]['content']);
        // Over-limit, unvoiced and empty output end with a terminal error; nothing is truncated or silently text-only.
        foreach ([[array_map(static fn($i)=>"Line $i. ",range(1,13)),true,12,'provider_output_too_many_lines'],
            [['Short. '.str_repeat('a',1001)],true,1,'provider_line_oversized'],[['Hello.'],false,0,'speech_unavailable'],
            [[str_repeat('Hi. ',4097)],true,0,'provider_output_oversized']] as [$chunks,$speech,$lines,$detail]) {
            [$manager,$output]=$this->dialogue($this->talkContext(),$row,[],$chunks,$speech);
            $manager->converse($event);
            $this->assertCount($lines+1,$output->events,$detail);
            $this->assertSame(['fail','provider_failed',$detail],end($output->events));
        }
        [$manager,$output]=$this->dialogue($this->talkContext(),$row,[],['  ']);
        $manager->converse($event);
        $this->assertSame([['fail','provider_empty','provider_empty']],$output->events);
    }

    public function testExplicitConversationIntentAdmissionReportsUsefulReasonsAndNeverUsesNames(): void
    {
        $event=$this->fixture('valid/input-text-native-turn.json');
        $context=$this->talkContext();
        $npc=$context['target'];
        $row=['identity_id'=>9,'npc_id'=>3,'profile_id'=>4,'npc_name'=>'Jun','voiceid'=>'','driver'=>'openaijson','url'=>'http://127.0.0.1:9/v1',
            'model'=>'m','reasoning_model'=>false,'metadata'=>'{}'];
        $unmarked=$event; unset($unmarked['payload']['intent']);
        // Capability alone never selects the route; Jev/action settings no longer participate in admission.
        $cases=['native_turn_not_negotiated'=>[[$unmarked,$context,$row,[]],
                [array_replace($event,['capabilities'=>['dialogue.text','dialogue.turn_ownership']]),$context,$row,[]],
                [array_replace($event,['type'=>'input_audio']),$context,$row,[]]],
            'conversation_mode_unsupported'=>[[array_replace_recursive($event,['payload'=>['control'=>['mode'=>'NARRATOR','model_slot'=>1]]]),$context,$row,[]]],
            'target_not_placed_reference'=>[[$event,array_replace($context,['target'=>['form_id'=>'0xFF000802']+$npc]),$row,[]]],
            'npc_not_registered'=>[[$event,$context,null,[]]],
            'llm_connector_unsupported'=>[[$event,$context,['driver'=>'player2json']+$row,[]]],
            'load_order_identity_ambiguous'=>[[$event,$context,$row,['0x0701A4D7']]],
            'target_missing'=>[[$event,array_replace($context,['target'=>null]),$row,[]],[$event,null,$row,[]]]];
        foreach ($cases as $reason=>$variants) foreach ($variants as [$candidate,$snapshot,$registered,$others]) {
            [$manager,$output]=$this->dialogue($snapshot,$registered,$others,['Never streamed.']);
            $manager->converse($candidate);
            $this->assertSame([['reject',$reason]],$output->events,$reason);
        }
        $source=file_get_contents(dirname(__DIR__,2).'/main_synth_pipeline.php');
        $this->assertStringContainsString("if ((\$wireEvent['payload']['intent'] ?? null) === 'conversation')",$source);
        $this->assertStringContainsString('synth_dialogue_turn_handle($db,$wireEvent)',$source);
        $edge=file_get_contents(dirname(__DIR__,2).'/lib/synth_dialogue_turn.php');
        $this->assertStringContainsString("synth_protocol_emit_terminal_error('native_turn_ineligible'",$edge);
    }

    public function testInitAcknowledgementGrantsNativeTurnOnlyToTheRequestingInit(): void
    {
        $init=$this->fixture('valid/init-native-turn.json');
        $saved=$GLOBALS['SYNTH_REQUEST_EVENT'] ?? null;
        try {
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$init];
            $status=json_decode(explode("\n",synth_protocol_encode_ack('init_accepted'))[1],true)['payload'];
            $this->assertTrue($status['native_turn'] ?? false);
            $init['capabilities']=['dialogue.text','dialogue.native_turn'];
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$init];
            $status=json_decode(explode("\n",synth_protocol_encode_ack('init_accepted'))[1],true)['payload'];
            $this->assertArrayNotHasKey('native_turn',$status);
        } finally { $GLOBALS['SYNTH_REQUEST_EVENT']=$saved; }
    }
}
