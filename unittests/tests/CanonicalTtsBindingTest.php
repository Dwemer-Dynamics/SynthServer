<?php declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/lib/synth_tts.php';

final class CanonicalTtsBindingTest extends TestCase {
    private array $saved=[];
    protected function setUp(): void {
        foreach(['db','TTSFUNCTION','SYNTH_NAME','PATCH_OVERRIDE_VOICE'] as $key)
            $this->saved[$key]=[array_key_exists($key,$GLOBALS),$GLOBALS[$key]??null];
        $GLOBALS['TTSFUNCTION']='none';$GLOBALS['SYNTH_NAME']='Settler';$GLOBALS['PATCH_OVERRIDE_VOICE']='wrong-same-name-voice';
        $GLOBALS['db']=new class {
            public array $queries=[];
            public function escapeLiteral(string $value): string {return "'".str_replace("'","''",$value)."'";}
            public function fetchOne(string $query): ?array {
                $this->queries[]=$query;
                if(str_contains($query,'npc_name'))throw new LogicException('A global name lookup crossed canonical voice boundary');
                if(str_contains($query,"upper('0x00000015')"))return ['voiceid'=>'canonical_voice','extended_data'=>'{}'];
                return null;
            }
        };
    }
    protected function tearDown(): void {
        foreach($this->saved as $key=>[$exists,$value]) {if($exists)$GLOBALS[$key]=$value;else unset($GLOBALS[$key]);}
    }
    private function actor(string $form='0x00000015',string $save='voice-save'): array {
        return ['form_id'=>$form,'origin_plugin'=>'Fallout4.esm','playthrough_id'=>$save,'display_name'=>'Settler'];
    }
    public function testCanonicalRowQueryNeverUsesSameNameProfileOrLocalNameFallback(): void {
        self::assertSame('canonical_voice',synth_tts_load_npc_voice_data($GLOBALS['db'],'Settler',$this->actor())['voiceid']);
        self::assertSame([],synth_tts_load_npc_voice_data($GLOBALS['db'],'Settler',$this->actor('0x00000016')));
        foreach($GLOBALS['db']->queries as $query) {
            self::assertStringContainsString("a.game='fo4'",$query);
            self::assertStringContainsString('a.origin_plugin',$query);
            self::assertStringContainsString("a.playthrough_id='voice-save'",$query);
            self::assertStringNotContainsString('npc_name',$query);
        }
    }
    public function testCanonicalCacheSeparatesSameNameActorAndSaveAndIgnoresInheritedVoice(): void {
        $root=dirname(__DIR__,2);
        $seed=synth_tts_cache_seed($root,'Settler','Hello.',$this->actor());
        self::assertStringContainsString('canonical_voice',$seed);
        self::assertStringNotContainsString('wrong-same-name-voice',$seed);
        self::assertNotSame($seed,synth_tts_cache_seed($root,'Settler','Hello.',$this->actor('0x00000016')));
        self::assertNotSame($seed,synth_tts_cache_seed($root,'Settler','Hello.',$this->actor('0x00000015','another-save')));
        $case=$this->actor();$case['origin_plugin']='FALLOUT4.ESM';
        self::assertSame($seed,synth_tts_cache_seed($root,'Settler','Hello.',$case));
    }
    public function testMalformedCanonicalVoiceCannotFallBackToNameLookup(): void {
        foreach(['form_id','origin_plugin','playthrough_id'] as $field) {
            $actor=$this->actor();$actor[$field]='';
            self::assertSame([],synth_tts_load_npc_voice_data($GLOBALS['db'],'Settler',$actor));
        }
        self::assertSame([],$GLOBALS['db']->queries);
    }
    public function testCanonicalGenerationScopesImmutableVoiceAndRestoresPreviousActor(): void {
        $process=proc_open([PHP_BINARY,dirname(__DIR__).'/fixtures/canonical_tts_scope.php',dirname(__DIR__,2)],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($process);fclose($pipes[0]);
        $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$errors);
        $result=json_decode($output,true,flags:JSON_THROW_ON_ERROR);
        self::assertTrue($result['generated']);self::assertTrue($result['restored']);
        self::assertSame(['planned'=>true,'voice'=>'canonical_voice','profile'=>false,'fallback'=>false,'history'=>false],$result['observed']);
        self::assertGreaterThan(0,$result['queries']);
    }
}
