<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__,2).'/lib/response.php';
require_once dirname(__DIR__,2).'/lib/synth_character_forms.php';

final class CharacterSpeechDeliveryTest extends TestCase
{
    private function withNativeTarget(array $target, callable $assertion): void
    {
        $keys=['db','SYNTH_REQUEST_EVENT','SYNTH_PROTOCOL_REQUEST_CLAIMED','SYNTH_NAME',
            'SYNTH_SPEECH_ROLE','TTSFUNCTION','SYNTH_RESPONSE_FORMAT','SYNTH_RESPONSE_STREAMING',
            'SYNTH_JSON_RESPONSE_LINES','SYNTH_CANONICAL_SPEECH_BUFFERED','SYNTH_CANONICAL_SPEECH_EMITTED',
            'SYNTH_PROTOCOL_RESPONSE_STARTED','SYNTH_PROTOCOL_RESPONSE_BODY','SYNTH_PROTOCOL_RESPONSE_SEQUENCE',
            'SYNTH_MIDDLETERM_JOB_LEASE','SYNTH_TTS_JOB_LEASE','SYNTH_MEMORY_JOB_LEASE',
            'SYNTH_RELATIONSHIP_JOB_LEASE','SYNTH_PROFILE_JOB_LEASE'];
        $saved=[];
        foreach ($keys as $key) {
            $saved[$key]=[array_key_exists($key,$GLOBALS),$GLOBALS[$key] ?? null];
            unset($GLOBALS[$key]);
        }
        $target+=['playthrough_id'=>'save-speech','display_name'=>'Takahashi'];
        // Physical actor state is intentionally absent: exact placed references
        // must retain their canonical limits when optional observations are missing.
        $context=['player'=>['form_id'=>'0x00000014','origin_plugin'=>'Fallout4.esm',
            'playthrough_id'=>'save-speech','display_name'=>'Player'],'target'=>$target];
        $db=new class($context) {
            public array $queries=[];
            public function __construct(public array $context) {}
            public function escapeLiteral(string $value): string { return "'".str_replace("'","''",$value)."'"; }
            public function fetchOne(string $sql): array {
                $this->queries[]=$sql;
                if (str_contains($sql,'request_executions')) return ['state'=>'claimed','runtime_state'=>'active',
                    'active_generation'=>3,'active_turn_id'=>'turn-speech','active_turn_sequence'=>7];
                if (str_contains($sql,'context_snapshots')) return ['context_payload'=>$this->context];
                throw new RuntimeException('Unexpected canonical speech query: '.$sql);
            }
            public function execQuery(string $sql): never { throw new RuntimeException('Speech delivery attempted a database write.'); }
        };
        try {
            $GLOBALS['db']=$db;
            $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=true;
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>['protocol_version'=>2,'game'=>'fo4',
                'runtime_variant'=>'flat','session_id'=>'session-speech','request_id'=>'request-speech',
                'generation'=>3,'context_sequence'=>7,'turn_id'=>'turn-speech','type'=>'input_text',
                'capabilities'=>['dialogue.turn_ownership']]];
            $GLOBALS['SYNTH_NAME']=$target['display_name'];
            $GLOBALS['SYNTH_SPEECH_ROLE']='npc';
            $GLOBALS['TTSFUNCTION']='none';
            $GLOBALS['SYNTH_RESPONSE_FORMAT']='json';
            $GLOBALS['SYNTH_RESPONSE_STREAMING']=false;
            $GLOBALS['SYNTH_JSON_RESPONSE_LINES']=[];
            $assertion($db);
        } finally {
            foreach ($saved as $key=>[$exists,$value]) {
                if ($exists) $GLOBALS[$key]=$value; else unset($GLOBALS[$key]);
            }
        }
    }

    public function testDirectTakahashiAcknowledgementUsesJapaneseWithoutOldAudioOrDuplicateChunks(): void
    {
        $this->withNativeTarget(['form_id'=>'0x00002CDB','origin_plugin'=>'Fallout4.esm'],function($db):void {
            synth_buffer_response_line('Takahashi','say','Done.', ['tts_cache_key'=>'oldEnglishKey']);
            synth_buffer_response_line('Takahashi','say','Another English chunk.', ['tts_cache_key'=>'secondEnglishKey']);
            synth_buffer_response_line('Takahashi','say','Nan-ni shimasho-ka?');
            $lines=$GLOBALS['SYNTH_JSON_RESPONSE_LINES'];
            self::assertCount(1,$lines);
            self::assertSame('Nan-ni shimasho-ka?',$lines[0]['text']);
            self::assertSame('Nan-ni shimasho-ka?',$lines[0]['subtitle']);
            self::assertSame('npc',$lines[0]['speech_role']);
            self::assertTrue($lines[0]['text_only']);
            self::assertArrayNotHasKey('tts_cache_key',$lines[0]);
            self::assertArrayNotHasKey('tts_cache_key',$lines[0]['metadata'] ?? []);
            self::assertStringNotContainsString('EnglishKey',json_encode($lines,JSON_THROW_ON_ERROR));
            self::assertTrue($GLOBALS['SYNTH_CANONICAL_SPEECH_BUFFERED']);
            self::assertStringContainsString('c.snapshot_sequence=7',implode("\n",$db->queries));
        });
    }

    public function testKnownVault88FeralReferencesSuppressDirectFluentSpeechWithoutActorStates(): void
    {
        foreach (['0x00001086','0x00004229'] as $form) {
            $this->withNativeTarget(['form_id'=>$form,'origin_plugin'=>'DLCworkshop03.esm',
                'display_name'=>'Named Feral'],function($db):void {
                synth_buffer_response_line('Named Feral','say','Done.', ['tts_cache_key'=>'oldEnglishKey']);
                self::assertSame([],$GLOBALS['SYNTH_JSON_RESPONSE_LINES']);
                self::assertStringContainsString('c.snapshot_sequence=7',implode("\n",$db->queries));
            });
        }
    }

    public function testPlayerAndNarratorLanesRemainUnchangedWhenTheirLabelsMatchRestrictedNpcs(): void
    {
        foreach ([['0x00002CDB','Fallout4.esm','Takahashi'],
            ['0x00001086','DLCworkshop03.esm','Named Feral'],
            ['0x00004229','DLCworkshop03.esm','Named Feral']] as [$form,$plugin,$name]) {
            foreach (['player','narrator'] as $role) {
                $this->withNativeTarget(['form_id'=>$form,'origin_plugin'=>$plugin,'display_name'=>$name],
                    function($db) use ($role,$name):void {
                    $GLOBALS['SYNTH_SPEECH_ROLE']=$role;
                    synth_buffer_response_line($name,'say','Done.', [],$role);
                    $lines=$GLOBALS['SYNTH_JSON_RESPONSE_LINES'];
                    self::assertCount(1,$lines);
                    self::assertSame('Done.',$lines[0]['text']);
                    self::assertSame('Done.',$lines[0]['subtitle']);
                    self::assertSame($role,$lines[0]['speech_role']);
                    self::assertFalse($GLOBALS['SYNTH_CANONICAL_SPEECH_BUFFERED'] ?? false);
                    self::assertSame([],$db->queries,'A temporary non-NPC lane must not acquire NPC speech limits.');
                });
            }
        }
    }

    public function testNamesAndOtherPluginsDoNotGrantCanonicalSpeechLimits(): void
    {
        foreach ([['0x00002CDB','OtherMod.esp','Takahashi'],
            ['0x00012345','Fallout4.esm','Takahashi'],
            ['0x00001086','OtherMod.esp','Named Feral']] as [$form,$plugin,$name]) {
            $this->withNativeTarget(['form_id'=>$form,'origin_plugin'=>$plugin,'display_name'=>$name],
                function() use ($name):void {
                synth_buffer_response_line($name,'say','Done.');
                self::assertCount(1,$GLOBALS['SYNTH_JSON_RESPONSE_LINES']);
                self::assertSame('Done.',$GLOBALS['SYNTH_JSON_RESPONSE_LINES'][0]['text']);
                self::assertFalse($GLOBALS['SYNTH_CANONICAL_SPEECH_BUFFERED'] ?? false);
            });
        }
    }
}
