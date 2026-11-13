<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/request.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';
require_once dirname(__DIR__, 2) . '/api/v2/dispatch.php';
require_once dirname(__DIR__, 2) . '/lib/synth_rechat_routes.php';

use Synth\Core\CharacterKey;
use Synth\Core\CharacterRepository;
use Synth\Core\LlmDriver;
use Synth\Core\PlacedCharacter;
use Synth\Core\SpeechDriver;
use Synth\Core\TurnOutput;
use Synth\Core\TurnRequest;
use Synth\Core\UtteranceRepository;
use Synth\Drivers\Llm\OpenAiCompatibleDriver;
use Synth\Managers\CharacterManager;
use Synth\Managers\ContextManager;
use Synth\Managers\DialogueManager;
use Synth\Managers\PromptManager;
use Synth\Managers\SaveState;
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

    public function testPrivateNarratorIngressUsesItsOwnRouteAndReceiptIdentity(): void
    {
        foreach (['narrator-command-select','narrator-turn-typed','narrator-turn-voice','narrator-turn-scene','narrator-receipt'] as $name) {
            $event=$this->fixture('valid/'.$name.'.json');
            $this->assertSame($event,synth_protocol_validate_event($event));
            $this->assertSame('narrator',synth_api_v2_route($event));
        }
        foreach (['narrator-command-no-capability','narrator-command-state-definition','narrator-command-select-missing',
            'narrator-turn-typed-media','narrator-turn-voice-no-media','narrator-turn-unbound',
            'narrator-receipt-npc-character','narrator-receipt-presented'] as $name) {
            $event=$this->fixture('invalid/'.$name.'.json');
            $script='require '.var_export(dirname(__DIR__,2).'/lib/request.php',true).';synth_protocol_validate_event(json_decode('
                .var_export(json_encode($event),true).',true));echo "ACCEPTED";';
            $output=strval(shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script)));
            $this->assertStringNotContainsString('ACCEPTED',$output,$name);
            $this->assertFalse(json_decode($output,true)['ok'] ?? true,$name);
        }
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
        $driver=new OpenAiCompatibleDriver(static function(): void {},static fn(array $connector): array=>$connector);
        $this->assertTrue($driver->supports($base));
        foreach ([['driver'=>'player2json'],['driver'=>'openrouterjson'],['reasoning_model'=>true],['url'=>''],['model'=>''],
            ['metadata'=>'{"quickstart_managed":true}']] as $change) {
            $this->assertFalse($driver->supports($change+$base),json_encode($change));
        }
        $this->expectException(DomainException::class);
        $driver->stream(['driver'=>'player2json']+$base,[],static function(string $delta): void {});
    }

    public function testDriverChecksOwnerBeforeConnectingAndRejectsMissingStatus(): void
    {
        $base=['driver'=>'openaijson','url'=>'http://127.0.0.1:9/v1/chat/completions','model'=>'m','reasoning_model'=>false,
            'metadata'=>'{}','api_key'=>'','max_tokens'=>64];
        $text=static function(string $delta): void { throw new LogicException('unexpected_text'); };
        $cancelled=new OpenAiCompatibleDriver(static function(): void { throw new SYNTHProtocolCancelled('request_cancelled'); },
            static fn(array $connector): array=>$connector);
        try { $cancelled->stream($base,[],$text); $this->fail('cancelled'); }
        catch (SYNTHProtocolCancelled $error) { $this->assertSame('request_cancelled',$error->getMessage()); }
        // A refused connection has no HTTP status and must fail generically, never as a parsed stream.
        $driver=new OpenAiCompatibleDriver(static function(): void {},static fn(array $connector): array=>$connector);
        try { $driver->stream($base,[],$text); $this->fail('refused'); }
        catch (RuntimeException $error) { $this->assertSame('provider_failed',$error->getMessage()); }
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
    private function dialogue(?array $context, ?array $row, array $others=[], array $chunks=[], bool $speech=true,
        array|Throwable|null $saved=null): array
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
        $characters=new class($row,$others,$saved) extends CharacterRepository {
            public array $savedReads=[];
            public array $base=['npc_name'=>'Jun','prompt_head'=>'Base head.','npc_static_bio'=>'Base bio.','personality'=>'Weary.',
                'occupation'=>'Base job.','skills'=>'Base skills.','speechstyle'=>'Base style.','goals'=>'Base goals.','voiceid'=>'jun_voice'];
            public function __construct(private ?array $row, private array $others, private array|Throwable|null $saved) {}
            public function registered(string $fullFormId, string $originPlugin, string $playthroughId): ?array { return $this->row; }
            public function otherFormIds(string $originPlugin, string $playthroughId, int $identityId): array { return $this->others; }
            public function npc(int $id): ?array { return ['id'=>$id]+$this->base; }
            public function profile(int $id): ?array { return ['id'=>$id,'tts_connector_id'=>5]; }
            // Saved fields keyed by identity id; a Throwable is a failed read.
            public function savedProfile(array $event, int $identityId): ?array
            {
                $this->savedReads[]=[$event,$identityId];
                if ($this->saved instanceof Throwable) throw $this->saved;
                $fields=$this->saved[$identityId] ?? null;
                return $fields===null ? null : ['revision'=>100+$identityId,'fields'=>$fields];
            }
        };
        $utterances=new class extends UtteranceRepository {
            public array $rows=[];
            public function __construct() {}
            public function recordInput(TurnRequest $request, string $data): void { $this->rows[]=$data; }
            public function recordLine(TurnRequest $request, PlacedCharacter $character, string $lineId, string $text): void { $this->rows[]=[$lineId,$text]; }
            public function recordReceipt(TurnRequest $receipt, array $character, string $characterId, string $state, ?\Closure $heard): string
            {
                $this->rows[]=[$state,$heard===null ? null : $heard('Stored line.')];
                return $state;
            }
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
        return [$manager,$output,$utterances,$llm,$characters];
    }

    private function talkContext(): array
    {
        return ['player'=>['form_id'=>'0x00000014','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'save-1','display_name'=>'Nate'],
            'target'=>$this->fixture('valid/speech-receipt.json')['payload']['character'],'audience'=>[],'world'=>['game_time_ticks'=>42]];
    }

    public function testOwnedInteriorAncestryReachesTheNextProviderPrompt(): void
    {
        $event=$this->fixture('valid/input-text-native-turn.json');
        $row=['identity_id'=>9,'npc_id'=>3,'profile_id'=>4,'driver'=>'openaijson','url'=>'http://127.0.0.1:9/v1','model'=>'m'];
        $context=$this->talkContext();
        // These CELL/XLCN/PNAM identities were checked against installed Fallout4.esm. Capture is simulated.
        $context['world']=['location'=>'Dugout Inn','cell'=>'DmndDugoutInn01','worldspace'=>'','weather'=>'',
            'interior'=>true,'game_time_ticks'=>42,'scene'=>['cell'=>['form_id'=>'0x00001E5D','origin_plugin'=>'Fallout4.esm'],'worldspace'=>null],
            'location_ref'=>['form_id'=>'0x0000395C','origin_plugin'=>'Fallout4.esm'],
            'location_ancestry'=>['end'=>'root','chain'=>[
                ['origin_plugin'=>'Fallout4.esm','local_form_id'=>'0x00395C','display_name'=>'Dugout Inn'],
                ['origin_plugin'=>'Fallout4.esm','local_form_id'=>'0x002CEF','display_name'=>'Diamond City'],
                ['origin_plugin'=>'Fallout4.esm','local_form_id'=>'0x002CF0','display_name'=>'Commonwealth']]]];
        foreach (['root','unresolved'] as $case) {
            $snapshot=$context;
            if ($case==='unresolved') {
                array_pop($snapshot['world']['location_ancestry']['chain']);
                $snapshot['world']['location_ancestry']['end']='unresolved';
            }
            [$manager,$output,,$llm]=$this->dialogue($snapshot,$row,[],['Understood.']);
            $manager->converse($event);
            $this->assertSame(['complete'],end($output->events));
            $prompt=$llm->messages[0]['content'];
            $this->assertStringContainsString('Current location: Dugout Inn, within Diamond City',$prompt);
            $this->assertStringContainsString('interior: DmndDugoutInn01',$prompt);
            $this->assertSame(1,substr_count($prompt,'Current location:'));
            if ($case==='unresolved') {
                $this->assertStringContainsString('beyond this is not known.',$prompt);
                $this->assertStringNotContainsString('within Commonwealth',$prompt);
            } else $this->assertStringContainsString('within Commonwealth',$prompt);
            $this->assertStringNotContainsString('worldspace:',$prompt);
            if ($case==='root') {
                $manager->converse($event);
                $this->assertSame(['complete'],end($output->events));
                $this->assertSame(1,substr_count($llm->messages[0]['content'],'Current location:'));
            }
        }
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
    }

    private function savedFields(string $tag): array
    {
        return ['personality'=>"$tag personality.",'occupation'=>"$tag job.",'skills'=>"$tag skills.",
            'speechstyle'=>"$tag style.",'goals'=>"$tag goals."];
    }

    // The saved revision is read with the admitted event for the resolved identity, never a name or a global, and
    // overlays only the five dynamic fields of the turn's copy.
    public function testSavedDynamicProfileOverlaysOnlyTheTurnCopyOfTheExactIdentity(): void
    {
        $event=$this->fixture('valid/input-text-native-turn.json');
        $row=['identity_id'=>9,'npc_id'=>3,'profile_id'=>4,'driver'=>'openaijson','url'=>'http://127.0.0.1:9/v1','model'=>'m'];
        $saved=[9=>$this->savedFields('Saved'),10=>$this->savedFields('Sibling')];
        $globals=[$GLOBALS['SYNTH_REQUEST_EVENT'] ?? null,$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'] ?? null];
        [$manager,$output,,$llm,$characters]=$this->dialogue($this->talkContext(),$row,[],['Fine.'],true,$saved);
        $manager->converse($event);
        $this->assertSame([[$event,9]],$characters->savedReads);
        $this->assertSame(['complete'],end($output->events));
        $system=$llm->messages[0]['content'];
        foreach (['Prompt head: Base head.','Npc static bio: Base bio.','Personality: Saved personality.',
            'Occupation: Saved job.','Speechstyle: Saved style.'] as $line) $this->assertStringContainsString($line,$system);
        foreach (['Weary.','Base job.','Base style.','Sibling'] as $base) $this->assertStringNotContainsString($base,$system);
        $this->assertSame($globals,[$GLOBALS['SYNTH_REQUEST_EVENT'] ?? null,$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'] ?? null]);

        // Identity, connector, voice, prompt fields and the base row stay as stored; only the copy changes.
        $request=new TurnRequest($event,['target'=>$this->talkContext()['target']]);
        $character=(new CharacterManager($characters))->resolve($request);
        $this->assertSame(['id'=>3]+array_replace($characters->base,$saved[9]),$character->npc);
        $this->assertSame([9,$row,['id'=>4,'tts_connector_id'=>5],$this->talkContext()['target']],
            [$character->identityId,$character->connector,$character->profile,$character->identity]);

        // Same display name, different identities: each reads its own revision; one without a revision stays base.
        foreach ([10=>array_replace($characters->base,$saved[10]),11=>$characters->base] as $identity=>$expected) {
            [,,,,$sibling]=$this->dialogue($this->talkContext(),['identity_id'=>$identity]+$row,[],[],true,$saved);
            $character=(new CharacterManager($sibling))->resolve($request);
            $this->assertSame([[$event,$identity]],$sibling->savedReads);
            $this->assertSame(['id'=>3]+$expected,$character->npc,strval($identity));
            $this->assertSame($this->talkContext()['target']['display_name'],$character->displayName());
        }

        // A refused target is never read.
        [$manager,$output,,,$characters]=$this->dialogue($this->talkContext(),$row,['0x0701A4D7'],[],true,$saved);
        $manager->converse($event);
        $this->assertSame([[['reject','load_order_identity_ambiguous']],[]],[$output->events,$characters->savedReads]);
    }

    // A failed read is not an absent revision: the turn is not run on the base profile and nothing is written.
    // Cancellation keeps its own outcome.
    public function testSavedProfileReadFailureAndCancellationNeverFallBackToTheBaseProfile(): void
    {
        $event=$this->fixture('valid/input-text-native-turn.json');
        $row=['identity_id'=>9,'npc_id'=>3,'profile_id'=>4,'driver'=>'openaijson','url'=>'http://127.0.0.1:9/v1','model'=>'m'];
        foreach ([new RuntimeException('Unable to read saved native profile.'),new SYNTHProtocolCancelled('cancelled')] as $error) {
            [$manager,$output,$utterances,$llm]=$this->dialogue($this->talkContext(),$row,[],['Never streamed.'],true,$error);
            try { $manager->converse($event); $this->fail(get_class($error)); }
            catch (Throwable $thrown) { $this->assertSame($error,$thrown); }
            $this->assertSame([[],[],[]],[$output->events,$utterances->rows,$llm->messages],get_class($error));
        }
        require_once dirname(__DIR__,2).'/lib/synth_profile_state.php';
        // A failing database: execQuery reports false, fetchOne reports [] (as sql::fetchOne does after logging).
        $db=new class {
            public array $queries=[];
            public function escapeLiteral($value): string { return "'".str_replace("'","''",strval($value))."'"; }
            public function execQuery($sql) { $this->queries[]=['exec',$sql]; return false; }
            public function fetchOne($sql) { $this->queries[]=['fetch',$sql]; return []; }
        };
        // Only the native repository opts in to the strict read: one query, and failure is never an absent revision.
        try { (new CharacterRepository($db))->savedProfile($event,9); $this->fail('failed read reported as absent'); }
        catch (RuntimeException $error) { $this->assertSame('Unable to read saved native profile.',$error->getMessage()); }
        $this->assertSame(['exec'],array_column($db->queries,0));
        $this->assertStringContainsString('actor_identity_id=9',$db->queries[0][1]);
        // Legacy callers keep their behaviour: the identical query through fetchOne, a failed read stays null.
        $this->assertNull(synth_profile_visible($db,$event,9));
        $this->assertSame(['exec','fetch'],array_column($db->queries,0));
        $this->assertSame($db->queries[0][1],$db->queries[1][1]);
        $this->assertNull(synth_profile_visible($db,$event,0,strict: true));
        $this->assertCount(2,$db->queries);
    }

    // Only explicit conversation intent and speech receipts take the v2 dispatch, chosen before the pipeline loads.
    public function testMainIngressDispatchesOnlyExplicitNativeDialogueBeforeThePipeline(): void
    {
        $talk=$this->fixture('valid/input-text-native-turn.json');
        $this->assertSame('conversation',synth_api_v2_route($talk));
        $this->assertSame('speech_receipt',synth_api_v2_route($this->fixture('valid/speech-receipt.json')));
        $unmarked=$talk; unset($unmarked['payload']['intent']);
        foreach (['unmarked'=>$unmarked,'v1'=>['protocol_version'=>1]+$talk,'audio'=>['type'=>'input_audio']+$talk,
            'init'=>$this->fixture('valid/init-native-turn.json'),'halt'=>['type'=>'halt','payload'=>new stdClass()]+$talk] as $name=>$event)
            $this->assertNull(synth_api_v2_route($event),$name);
        $main=file_get_contents(dirname(__DIR__,2).'/main.php');
        $dispatch=strpos($main,'synth_api_v2_dispatch(');
        $this->assertNotFalse($dispatch);
        $this->assertLessThan(strpos($main,'"main_synth_pipeline.php"'),$dispatch);
        $this->assertSame(1,substr_count($main,'synth_decode_wire_event()'));
        $pipeline=file_get_contents(dirname(__DIR__,2).'/main_synth_pipeline.php');
        $this->assertStringContainsString('synth_decode_event_from_request($synthIngressEvent ?? null)',$pipeline);
        foreach (['speech_receipt_accepted','native_turn','DialogueManager',"'intent'"] as $needle)
            $this->assertStringNotContainsString($needle,$pipeline,$needle);
        foreach (['synth_dialogue_turn.php','synth_dialogue_provider.php','synth_dialogue_receipts.php'] as $removed)
            $this->assertFileDoesNotExist(dirname(__DIR__,2).'/lib/'.$removed);
    }

    // The native architecture lives only in the top-level tree; legacy lib/ keeps no duplicate copy.
    public function testNativeArchitectureUsesTheTopLevelLayoutOnly(): void
    {
        $root=dirname(__DIR__,2);
        foreach (['bootstrap.php','api/v2/dispatch.php','api/v2/routes.php','api/v2/ProtocolOutput.php',
            'managers/DialogueManager.php','core/contracts/dialogue.php','core/entities/dialogue.php',
            'core/dbmanager/repositories/UtteranceRepository.php','drivers/llm/OpenAiCompatibleDriver.php',
            'drivers/tts/ProfileSpeechDriver.php'] as $file) $this->assertFileExists($root.'/'.$file);
        foreach (['bootstrap.php','api','managers','drivers','core/contracts','core/entities','core/dbmanager','core/dialogue'] as $legacy)
            $this->assertFileDoesNotExist($root.'/lib/'.$legacy);
    }

    // The legacy writer keeps its order: route prepared, replay body updated, echoed, dispatch diagnostics, then
    // flushed. The explicit-owner route recorder is shared, not bypassed.
    public function testSharedWriterKeepsTheLegacyEmissionOrder(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/lib/response.php');
        $start=strpos($source,'function synth_protocol_write_response_line(');
        $writer=substr($source,$start,strpos($source,"\n}\n",$start)-$start);
        $order=array_map(fn($needle)=>strpos($writer,$needle),['synth_protocol_prepare_line(',
            "\$GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY'] =",'echo $wire;',"synth_jev_action_trace('dispatch'",
            'synth_protocol_flush_output();']);
        $this->assertNotContains(false,$order);
        $sorted=$order; sort($sorted);
        $this->assertSame($sorted,$order);
        $this->assertStringContainsString('synth_rechat_record_emitted_route($db, $event, $wire, $routeMetadata, $owner)',$source);
        $this->assertStringContainsString('synth_protocol_owns_rechat($event) ||',$source);
    }

    public function testReceiptRefusalsAndHeardPolicyPrecedeAnyWrite(): void
    {
        $receipt=$this->fixture('valid/speech-receipt.json');
        $row=['identity_id'=>9,'npc_id'=>3,'profile_id'=>4,'driver'=>'openaijson'];
        foreach ([[['character'=>['form_id'=>'0xFF000802']+$receipt['payload']['character']],$this->talkContext(),'receipt_invalid'],
            [[],null,'receipt_owner_mismatch']] as [$change,$context,$code]) {
            [$manager,,$utterances]=$this->dialogue($context,$row);
            try { $manager->receipt(array_replace_recursive($receipt,['payload'=>$change])); $this->fail($code); }
            catch (DomainException $error) { $this->assertSame($code,$error->getMessage()); }
            $this->assertSame([],$utterances->rows);
        }
        foreach (['audio_completed'=>['spoken',true],'text_presented'=>['presented',true],'interrupted'=>['interrupted',false],
            'failed'=>['failed',false]] as $outcome=>[$state,$heard]) {
            [$manager,,$utterances]=$this->dialogue($this->talkContext(),$row);
            $this->assertSame($state,$manager->receipt(array_replace_recursive($receipt,['payload'=>['outcome'=>$outcome]])));
            $target=$this->talkContext()['target']['display_name'];
            $this->assertSame([$state,$heard ? $target.': Stored line. (talking to Nate)' : null],$utterances->rows[0]);
        }
    }

    // A scripted request_executions/runtime_sessions double. $race runs as a competitor that committed just
    // before this request took the session lock; bytes sent while a transaction is open are counted.
    private function outcomeDb(array $event): object
    {
        return new class($event) {
            public array $row; public array $session; public ?Closure $race=null; public int $failUpdates=0;
            public bool $open=false; public int $sentInTransaction=0;
            public function __construct(array $event) {
                $this->row=['state'=>'claimed','error_code'=>null,'body'=>null];
                $this->session=['state'=>'active','active_generation'=>$event['generation'],
                    'active_turn_id'=>$event['turn_id'],'active_turn_sequence'=>$event['context_sequence']];
            }
            public function escapeLiteral($value): string { return "'".str_replace("'","''",strval($value))."'"; }
            public function execQuery($sql) {
                if ($sql==='BEGIN') $this->open=true;
                if ($sql==='COMMIT' || $sql==='ROLLBACK') $this->open=false;
                return true;
            }
            public function fetchOne($sql) {
                if (str_contains($sql,'pg_advisory_xact_lock')) {
                    if ($this->race) { ($this->race)($this); $this->race=null; }
                    return ['pg_advisory_xact_lock'=>''];
                }
                if (str_contains($sql,'JOIN synth_meta.runtime_sessions')) return [
                    'state'=>$this->row['state'],'error_code'=>$this->row['error_code'],'runtime_state'=>$this->session['state'],
                    'active_generation'=>$this->session['active_generation'],'active_turn_id'=>$this->session['active_turn_id'],
                    'active_turn_sequence'=>$this->session['active_turn_sequence']];
                if (str_starts_with($sql,'SELECT state, error_code')) return $this->row;
                if (str_starts_with($sql,'UPDATE synth_meta.request_executions')) {
                    if ($this->failUpdates-- > 0) return false;
                    if (!in_array($this->row['state'],['claimed','streaming'],true)) return null;
                    preg_match("/SET state='(\\w+)'/",$sql,$state);
                    preg_match("/response_body=(NULL|'\\\\x([0-9a-f]*)'::bytea)/",$sql,$body);
                    preg_match("/error_code=(NULL|'(\\w+)')/",$sql,$code);
                    $this->row=['state'=>$state[1],'error_code'=>$code[2] ?? null,
                        'body'=>$body[1]==='NULL' ? null : hex2bin($body[2])];
                    return ['state'=>$state[1]];
                }
                throw new LogicException('Unexpected outcome query: '.$sql);
            }
        };
    }

    private function concluded(array $event, callable $setup, callable $turn): array
    {
        $db=$this->outcomeDb($event); $setup($db);
        $output=new Synth\Api\V2\ProtocolOutput($db,$event); $output->claimed($this->owner($event));
        $sent='';
        ob_start(function (string $chunk) use (&$sent,$db): string {
            if ($chunk!=='' && $db->open) $db->sentInTransaction++;
            $sent.=$chunk; return '';
        });
        try { $turn($output); } finally { ob_end_flush(); }
        $this->assertSame(0,$db->sentInTransaction,'terminal bytes sent inside the outcome transaction');
        return [$sent,$db->row];
    }

    // A claimed owner without the canonical claim transaction (exercised end-to-end over HTTP/PostgreSQL).
    private function owner(array $event): SYNTHClaimedRequest
    {
        return Closure::bind(static fn() => new SYNTHClaimedRequest($event), null, SYNTHClaimedRequest::class)();
    }

    public function testClaimedOwnerIsExplicitExactAndNeverInstalledAsARequestGlobal(): void
    {
        $talk=$this->fixture('valid/input-text-native-turn.json');
        // A non-turn-bound event is refused before the claim transaction begins (no database is touched).
        foreach ([['context_sequence'=>0]+$talk,['protocol_version'=>1]+$talk] as $event) {
            try { synth_protocol_claim_request(null,$event,true); $this->fail('owner without a turn-bound claim'); }
            catch (LogicException $error) { $this->assertSame('A claimed native context is required',$error->getMessage()); }
        }
        $other=['request_id'=>$talk['request_id'].'-other']+$talk;
        try { (new Synth\Api\V2\ProtocolOutput(null,$talk))->claimed($this->owner($other)); $this->fail('foreign output owner'); }
        catch (LogicException $error) { $this->assertSame('Native request owner mismatch',$error->getMessage()); }
        $repository=new UtteranceRepository(null,$this->owner($talk));
        try { $repository->recordInput(new TurnRequest($other,$this->talkContext()),'text'); $this->fail('foreign history owner'); }
        catch (LogicException $error) { $this->assertSame('Native request owner mismatch',$error->getMessage()); }
        try { synth_rechat_record_emitted_route(null,$talk,'{"type":"dialogue"}',[],$this->owner($other)); $this->fail('foreign route owner'); }
        catch (LogicException $error) { $this->assertSame('Dialogue route owner mismatch',$error->getMessage()); }
        // The dispatcher claims through the explicit owner only; legacy authority globals are not installed.
        $source=file_get_contents(dirname(__DIR__,2).'/api/v2/dispatch.php');
        $this->assertStringNotContainsString("GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']",$source);
        $this->assertStringNotContainsString("GLOBALS['SYNTH_REQUEST_EVENT']",$source);
    }

    private function ends(string $wire): array
    {
        $lines=array_map(fn($line)=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),array_values(array_filter(explode("\n",$wire))));
        return array_map(fn($line)=>[$line['type'],$line['type']==='dialogue' ? null
            : ($line['payload']['status'] ?? $line['payload']['code'] ?? null)],$lines);
    }

    public function testTerminalOutcomeCommitsUnderTheClaimLockBeforeItsBytesAndCompetitorsWin(): void
    {
        $talk=$this->fixture('valid/input-text-native-turn.json');
        $plain=$talk; $plain['capabilities']=['dialogue.text','dialogue.native_turn'];
        $converse=function ($output) {
            $output->dialogue('dialogue:1','dialogue:1',['speaker'=>['display_name'=>'Piper'],'text'=>'Hello.']);
            $output->complete();
        };
        $start=['response_start',null];

        // Completion wins: the durable body is exactly the sent stream and replays byte-for-byte.
        [$sent,$row]=$this->concluded($plain,fn()=>null,$converse);
        $this->assertSame([$start,['dialogue',null],['response_end','complete']],$this->ends($sent));
        $this->assertSame(['complete',null,$sent],[$row['state'],$row['error_code'],$row['body']]);
        ob_start(); synth_protocol_emit_replay(['body'=>$row['body'],'content_type'=>'application/x-ndjson']);
        $this->assertSame($sent,ob_get_clean());

        // A cancel committed at the final-frame boundary wins: the delivered prefix closes as cancelled and the
        // competitor's NULL-body cancellation (replayed as replay_unavailable) is never replaced.
        $cancel=fn($db)=>$db->race=function ($db) { $db->row=['state'=>'cancelled','error_code'=>'turn_cancelled','body'=>null]; };
        [$sent,$row]=$this->concluded($plain,$cancel,$converse);
        $this->assertSame([$start,['dialogue',null],['response_end','cancelled']],$this->ends($sent));
        $this->assertSame(['cancelled','turn_cancelled',null],[$row['state'],$row['error_code'],$row['body']]);

        // A superseding turn that retired the owner before the row was swept also wins, for streams and acks.
        $supersede=fn($db)=>$db->race=function ($db) { $db->session['active_turn_id']='turn-next'; };
        foreach (['complete'=>fn($output)=>$output->complete(),
            'ack'=>fn($output)=>$output->ack('speech_receipt_accepted',['detail'=>'spoken'])] as $name=>$finish) {
            [$sent,$row]=$this->concluded($talk,$supersede,$finish);
            $this->assertSame([$start,['response_end','cancelled']],$this->ends($sent),$name);
            $this->assertSame(['cancelled','request_cancelled',null],[$row['state'],$row['error_code'],$row['body']],$name);
        }

        // A failed outcome write never reports completion: the generic failure is recorded and sent.
        $failure=[$start,['dialogue',null],['status','backend_request_failed'],['response_end','error']];
        [$sent,$row]=$this->concluded($plain,fn($db)=>$db->failUpdates=1,$converse);
        $this->assertSame($failure,$this->ends($sent));
        $this->assertSame(['failed','backend_request_failed',$sent],[$row['state'],$row['error_code'],$row['body']]);

        // With persistence wholly unavailable the claim stays pending (a retry answers request_pending) and the
        // wire still reports failure, never success.
        [$sent,$row]=$this->concluded($plain,fn($db)=>$db->failUpdates=2,$converse);
        $this->assertSame($failure,$this->ends($sent));
        $this->assertSame('claimed',$row['state']);

        // An owner superseded between the canonical claim and its liveness check is already bound to the output,
        // so its claim is concluded as cancelled instead of staying claimed.
        $retired=fn($db)=>$db->session['active_turn_id']='turn-next';
        [$sent,$row]=$this->concluded($talk,$retired,function ($output) {
            try { $output->assertActive(); $this->fail('superseded owner stayed live'); }
            catch (SYNTHProtocolCancelled) { $output->cancelled(); } });
        $this->assertSame([$start,['response_end','cancelled']],$this->ends($sent));
        $this->assertSame(['cancelled','request_cancelled'],[$row['state'],$row['error_code']]);
        $dispatch=file_get_contents(dirname(__DIR__,2).'/api/v2/dispatch.php');
        $this->assertLessThan(strpos($dispatch,'$output->assertActive();'),strpos($dispatch,'$output->claimed($owner);'));
        $state=file_get_contents(dirname(__DIR__,2).'/lib/synth_protocol_state.php');
        // The owner is returned by the claim itself after its attested COMMIT: no second lock can strand it.
        $claim=substr($state,strpos($state,'function synth_protocol_claim_request('));
        $claim=substr($claim,0,strpos($claim,"\n}\n"));
        $this->assertLessThan(strrpos($claim,'new SYNTHClaimedRequest($event)'),strrpos($claim,'synth_protocol_commit_transaction($db);'));
        $this->assertStringNotContainsString('withAcceptedClaimLock',$state.file_get_contents(dirname(__DIR__,2).'/lib/postgresql.class.php'));
        $this->assertStringContainsString("\$owner = \$claim['owner'];",$dispatch);

        // A receipt acknowledgement stores its exact bytes; later cancellation or failure changes nothing.
        [$sent,$row]=$this->concluded($talk,fn()=>null,function ($output) {
            $output->ack('speech_receipt_accepted',['detail'=>'spoken']); $output->cancelled(); $output->failed(); });
        $this->assertSame(['complete',$sent],[$row['state'],$row['body']]);
        $this->assertSame(['status','speech_receipt_accepted'],$this->ends($sent)[1]);
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

    // Condition comes only from the admitted snapshot's own rows for the exact player and responder.
    public function testSituationConditionProjectsOnlyTheSnapshotRowsOfTheExactPlayerAndResponder(): void
    {
        $event=$this->fixture('valid/input-text-native-turn.json');
        $row=['identity_id'=>9,'npc_id'=>3,'profile_id'=>4,'driver'=>'openaijson','url'=>'http://127.0.0.1:9/v1','model'=>'m'];
        $state=static fn(array $actor, $health, $ap, ?bool $healthOk=true, ?bool $apOk=true)=>['actor'=>$actor,
            'health_percent'=>$health,'action_points_percent'=>$ap]+array_filter(['health_percent_available'=>$healthOk,
            'action_points_percent_available'=>$apOk],static fn($flag)=>$flag!==null);
        $snapshot=function (array ...$states): array { $context=$this->talkContext(); $context['actor_states']=$states; return $context; };
        $condition=function (array $context) use ($event,$row): array {
            [$manager,$output,,$llm]=$this->dialogue($context,$row,[],['Fine.']);
            $manager->converse($event);
            $this->assertSame(['complete'],end($output->events));
            $system=$llm->messages[0]['content'];
            $situation=json_decode(substr($system,strrpos($system,"\nSituation: ")+12),true,flags:JSON_THROW_ON_ERROR);
            return [$situation['condition']['player'],$situation['condition']['responder']];
        };
        $player=$this->talkContext()['player'];
        $npc=$this->talkContext()['target'];
        $seen=static fn($health,$ap)=>['observation'=>'observed','health_percent'=>$health,'action_points_percent'=>$ap];
        $unknown=['observation'=>'unknown','health_percent'=>null,'action_points_percent'=>null];

        // Injured, then recovered on a separate snapshot; the stale snapshot keeps its own values and is not mutated.
        $injured=$snapshot($state($player,40,75),$state($npc,25.5,60));
        $copy=$injured;
        $this->assertSame([$seen(40,75),$seen(25.5,60)],$condition($injured));
        $this->assertSame([$seen(100,100),$seen(90,60)],$condition($snapshot($state($player,100,100),$state($npc,90,60))));
        $this->assertSame([$seen(40,75),$seen(25.5,60)],$condition($injured));
        $this->assertSame($copy,$injured);

        // Zero is a valid read; an unattested placeholder is null and two unattested values are unknown.
        $this->assertSame([$seen(0,null),$seen(null,0)],$condition($snapshot($state($player,0,50,true,false),$state($npc,100,0,null,true))));
        $this->assertSame([$unknown,$unknown],$condition($snapshot($state($player,40,75,false,false),$state($npc,25,60,null,null))));

        // Missing, duplicated or same-name rows of another identity are never the participant's condition.
        $sameName=['form_id'=>'0x0001A4D8']+$npc;
        $this->assertSame([$unknown,$seen(25,60)],$condition($snapshot($state($npc,25,60))));
        $this->assertSame([$seen(40,75),$unknown],$condition($snapshot($state($player,40,75),$state($sameName,10,10))));
        $this->assertSame([$seen(40,75),$unknown],$condition($snapshot($state($player,40,75),$state($npc,25,60),$state($npc,90,60))));
        $this->assertSame([$unknown,$seen(25,60)],$condition($snapshot($state(['playthrough_id'=>'save-2']+$player,40,75),$state($npc,25,60))));
    }
}
