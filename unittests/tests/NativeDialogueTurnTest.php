<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/lib/request.php';
require_once dirname(__DIR__, 2) . '/lib/response.php';
require_once dirname(__DIR__, 2) . '/lib/synth_dialogue_turn.php';

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
        $key=synth_dialogue_character_id($wire);
        $this->assertMatchesRegularExpression('/^chr:[0-9a-f]{40}$/D',strval($key));
        $this->assertSame($key,synth_dialogue_character_id($speaker));
        $this->assertSame($key,synth_dialogue_character_id(['form_id'=>'0x0001a4d7','origin_plugin'=>'FALLOUT4.ESM','playthrough_id'=>'save-1']));
        // A regular plugin's load-order byte and a light plugin's index are not part of the placed reference.
        $this->assertSame(synth_dialogue_character_id(['form_id'=>'0x05000801']+$wire),synth_dialogue_character_id(['form_id'=>'0x07000801']+$wire));
        $this->assertSame(synth_dialogue_character_id(['form_id'=>'0xFE001801']+$wire),synth_dialogue_character_id(['form_id'=>'0xFE002801']+$wire));
        $this->assertNotSame($key,synth_dialogue_character_id(['playthrough_id'=>'save-2']+$wire));
        $this->assertNotSame($key,synth_dialogue_character_id(['origin_plugin'=>'DLCRobot.esm']+$wire));
        foreach (['0xFF000802','0X0001A4D7','0x0x01A4D7','0001A4D7','0x1A4D7',''] as $form)
            $this->assertNull(synth_dialogue_character_id(['form_id'=>$form]+$wire),$form);
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
        synth_dialogue_parse_sse(static function() use(&$lines) { return array_shift($lines) ?? false; },
            static function(string $delta) use(&$text): void { $text.=$delta; });
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
        $provider=new SynthOpenAiCompatibleProvider(['url'=>'http://127.0.0.1:9/v1/chat/completions','model'=>'local-model',
            'api_key'=>'secret-key','max_tokens'=>4096,'temperature'=>0.4,'top_p'=>0.9,'presence_penalty'=>0.1,'frequency_penalty'=>0.2]);
        $body=json_decode($provider->requestBody([['role'=>'user','content'=>'Hi']]),true);
        $this->assertSame(['model'=>'local-model','messages'=>[['role'=>'user','content'=>'Hi']],'stream'=>true,'max_tokens'=>2048,
            'temperature'=>0.4,'top_p'=>0.9,'presence_penalty'=>0.1,'frequency_penalty'=>0.2],$body);
        $this->assertStringNotContainsString('secret-key',$provider->requestBody([]));
    }

    public function testOnlyPlainOpenAiCompatibleConnectorsAreOwned(): void
    {
        $base=['driver'=>'openaijson','url'=>'http://127.0.0.1:9/v1/chat/completions','model'=>'m','reasoning_model'=>false,'metadata'=>'{}'];
        $this->assertTrue(synth_dialogue_provider_supported($base));
        foreach ([['driver'=>'player2json'],['driver'=>'openrouterjson'],['reasoning_model'=>true],['url'=>''],['model'=>''],
            ['metadata'=>'{"quickstart_managed":true}']] as $change) {
            $this->assertFalse(synth_dialogue_provider_supported($change+$base),json_encode($change));
        }
        $this->expectException(DomainException::class);
        synth_dialogue_provider_for(['driver'=>'player2json']+$base);
    }

    public function testSentencesStreamAsCompleteLines(): void
    {
        $buffer='Hello there. How are';
        $this->assertSame(['Hello there.'],synth_dialogue_take_sentences($buffer,false));
        $this->assertSame('How are',$buffer);
        $buffer.=' you?" Fine';
        $this->assertSame(['How are you?"'],synth_dialogue_take_sentences($buffer,false));
        $this->assertSame(['Fine'],synth_dialogue_take_sentences($buffer,true));
    }

    private function eligibilityDb(array $context, ?array $row, array $others=[]): object
    {
        return new class($context,$row,$others) {
            public int $registryReads=0;
            public function __construct(private array $context, private ?array $row, private array $others) {}
            public function escapeLiteral($value): string { return "'".str_replace("'","''",strval($value))."'"; }
            public function fetchOne(string $sql): ?array
            {
                if (str_contains($sql,'context_snapshots')) return ['context_payload'=>json_encode($this->context)];
                if (str_contains($sql,'core_npc_master')) { $this->registryReads++; return $this->row; }
                return null;
            }
            public function fetchAll(string $sql): array { return $this->others; }
        };
    }

    public function testExplicitConversationIntentAdmissionReportsUsefulReasonsAndNeverUsesNames(): void
    {
        $event=$this->fixture('valid/input-text-native-turn.json');
        $npc=$this->fixture('valid/speech-receipt.json')['payload']['character'];
        $context=['player'=>['form_id'=>'0x00000014','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'save-1','display_name'=>'Nate'],
            'target'=>$npc,'audience'=>[],'world'=>['game_time_ticks'=>42]];
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
            'load_order_identity_ambiguous'=>[[$event,$context,$row,[['full_form_id'=>'0x0701A4D7']]]],
            'target_missing'=>[[$event,array_replace($context,['target'=>null]),$row,[]]]];
        foreach ($cases as $reason=>$variants) foreach ($variants as [$candidate,$snapshot,$registered,$others])
            $this->assertSame($reason,synth_dialogue_turn_owner($this->eligibilityDb($snapshot,$registered,$others),$candidate),$reason);
        $source=file_get_contents(dirname(__DIR__,2).'/main_synth_pipeline.php');
        $this->assertStringContainsString("if ((\$wireEvent['payload']['intent'] ?? null) === 'conversation')",$source);
        $this->assertStringContainsString("synth_protocol_emit_terminal_error('native_turn_ineligible'",$source);
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
