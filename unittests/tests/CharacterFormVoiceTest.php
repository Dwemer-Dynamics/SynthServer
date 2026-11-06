<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__,2).'/lib/synth_character_voice.php';
require_once dirname(__DIR__,2).'/lib/response.php';
require_once dirname(__DIR__,2).'/lib/logger.php';
require_once dirname(__DIR__,2).'/lib/core/npc_master.class.php';

final class CharacterFormVoiceTest extends TestCase
{
    private function state(string $form='robot'): array
    {
        return ['actor'=>['form_id'=>'0x00102249','origin_plugin'=>'Fallout4.esm',
            'playthrough_id'=>'save-curie','display_name'=>'Curie'],
            'base_form_id'=>'0x00027686','base_origin_plugin'=>'Fallout4.esm',
            'race'=>$form==='robot' ? 'HandyRace' : 'HumanRace',
            'voice_type'=>$form==='robot' ? 'RobotCurie' : 'NPCFCurie'];
    }

    private function profile(array $extra=[]): array
    {
        return array_replace(['npc_name'=>'Curie','actor_identity_id'=>42,
            'voiceid'=>'robotcurie','race'=>'Stale Profile Race','lock_profile'=>0,
            'extended_data'=>'{"voice_metadata":{"voice_name":"RobotCurie","voice_formid":"00027687"}}'], $extra);
    }

    public function testRobotAndSynthUseObservedVoicesAcrossTheSameOwnedReference(): void
    {
        $profile=$this->profile();
        $before=$profile;
        foreach ([['robot','robotcurie'],['synth','npcfcurie'],['robot','robotcurie']] as [$form,$voice]) {
            $selected=synth_curie_form_voice_overlay($profile,$this->state($form));
            self::assertSame($voice,$selected['voiceid']);
            self::assertSame(42,$selected['actor_identity_id']);
            self::assertSame('Stale Profile Race',$selected['race']);
        }
        self::assertSame($before,$profile,'The request overlay must not modify the saved input profile.');
    }

    public function testOptionalSlotsChooseDifferentProviderVoicesWithoutStaleSampleHints(): void
    {
        $profile=$this->profile(['voiceid'=>'generic-custom','lock_profile'=>1,
            'extended_data'=>json_encode(['form_voiceids'=>['curie_robot'=>'robot-provider-uuid',
                'curie_synth'=>'synth-provider-uuid'],'voice_metadata'=>['voice_name'=>'RobotCurie']])]);
        foreach (['robot','synth'] as $form) {
            $selected=synth_curie_form_voice_overlay($profile,$this->state($form));
            self::assertSame($form.'-provider-uuid',$selected['voiceid']);
            $metadata=json_decode($selected['extended_data'],true)['voice_metadata'];
            self::assertSame('',$metadata['voice_name']);
            self::assertSame('',$metadata['voice_formid']);
        }
    }

    public function testResolvedTemplateBasesSelectOnlyTheirMatchingObservedForm(): void
    {
        $profile=$this->profile();
        foreach ([['robot','0x001846CB','robotcurie'],['synth','0x001647C6','npcfcurie']] as [$form,$base,$voice]) {
            $state=array_replace($this->state($form),['base_form_id'=>$base]);
            self::assertSame($voice,synth_curie_form_voice_overlay($profile,$state)['voiceid']);
        }
    }

    public function testMissingSlotPreservesExplicitGenericAndLockedVoices(): void
    {
        foreach ([$this->profile(['voiceid'=>'generic-custom']),
            $this->profile(['lock_profile'=>1]),$this->profile(['lock_profile'=>'true']),
            $this->profile(['voiceid'=>'generic-custom','extended_data'=>json_encode([
                'form_voiceids'=>['curie_robot'=>'configured-robot-only']])])] as $profile) {
            self::assertSame($profile,synth_curie_form_voice_overlay($profile,$this->state('synth')));
        }
    }

    public function testFormSlotsDoNotResolveFromNamesOtherPluginsOrContradictoryStates(): void
    {
        $profile=$this->profile(['extended_data'=>json_encode(['form_voiceids'=>['curie_synth'=>'custom-synth']])]);
        $synth=$this->state('synth');
        foreach ([['base_form_id'=>'0x00045AC9'],['base_origin_plugin'=>'AnotherMod.esp'],
            ['voice_type'=>'RobotCurie'],['race'=>'HandyRace'],['race'=>''],
            ['base_form_id'=>'../00027686'],['base_form_id'=>'0x001846CB']] as $bad) {
            self::assertSame($profile,synth_curie_form_voice_overlay($profile,array_replace($synth,$bad)));
        }
    }

    public function testNoNativeVoiceDoesNotInventOneButCanUseAnExplicitFormSlot(): void
    {
        $state=array_replace($this->state('synth'),['voice_type'=>'']);
        $profile=$this->profile();
        self::assertSame($profile,synth_curie_form_voice_overlay($profile,$state));
        $profile['extended_data']=json_encode(['form_voiceids'=>['curie_synth'=>'configured-synth']]);
        self::assertSame('configured-synth',synth_curie_form_voice_overlay($profile,$state)['voiceid']);
    }

    public function testDiscardedRobotChassisNeverSelectsAnActiveCurieVoice(): void
    {
        $state=$this->state();$state['actor']['form_id']='0x000865D1';
        $state['base_form_id']='0x001846CB';
        $profile=$this->profile(['extended_data'=>json_encode([
            'form_voiceids'=>['curie_robot'=>'configured-robot']])]);
        self::assertSame($profile,synth_curie_form_voice_overlay($profile,$state));
        self::assertNull(synth_curie_observed_voice_form($state));
    }

    public function testMalformedSlotsAreIgnored(): void
    {
        foreach ([[],['path'=>'voice'], '../outside.wav', 'https://example.invalid/voice',
            "bad\0voice",str_repeat('v',256)] as $invalid) {
            $profile=$this->profile(['voiceid'=>'generic-custom',
                'extended_data'=>json_encode(['form_voiceids'=>['curie_synth'=>$invalid]])]);
            self::assertSame($profile,synth_curie_form_voice_overlay($profile,$this->state('synth')));
        }
    }

    private function withContext(array $states, callable $assertion, array $identityChanges=[], array $targetChanges=[]): void
    {
        $keys=['db','SYNTH_REQUEST_EVENT','SYNTH_PROTOCOL_REQUEST_CLAIMED',
            'SYNTH_CORE_CURRENT_PROFILE_DATA','TTSFUNCTION','SYNTH_MIDDLETERM_JOB_LEASE',
            'SYNTH_NAME','SYNTH_SPEECH_ROLE','SYNTH_TTS_JOB_LEASE','SYNTH_MEMORY_JOB_LEASE',
            'SYNTH_RELATIONSHIP_JOB_LEASE','SYNTH_PROFILE_JOB_LEASE'];
        $saved=[];
        foreach ($keys as $key) {
            $saved[$key]=[array_key_exists($key,$GLOBALS),$GLOBALS[$key] ?? null];
            unset($GLOBALS[$key]);
        }
        $target=array_replace(['form_id'=>'0x00102249','origin_plugin'=>'Fallout4.esm',
            'playthrough_id'=>'save-curie','display_name'=>'Curie'],$targetChanges);
        $context=['player'=>['form_id'=>'0x00000014','origin_plugin'=>'Fallout4.esm',
            'playthrough_id'=>'save-curie','display_name'=>'Player'],'target'=>$target,'actor_states'=>$states];
        $identity=array_replace(['full_form_id'=>'0x00102249','origin_plugin'=>'Fallout4.esm',
            'playthrough_id'=>'save-curie'],$identityChanges);
        $db=new class($context,$identity) {
            public array $queries=[];
            public function __construct(public array $context,public array $identity) {}
            public function escapeLiteral($value): string { return "'".str_replace("'","''",(string)$value)."'"; }
            public function fetchOne(string $sql): array {
                $this->queries[]=$sql;
                if (str_contains($sql,'request_executions')) return ['state'=>'claimed','runtime_state'=>'active',
                    'active_generation'=>3,'active_turn_id'=>'turn-curie','active_turn_sequence'=>7];
                if (str_contains($sql,'context_snapshots')) return ['context_payload'=>$this->context];
                if (str_contains($sql,'actor_identities')) return $this->identity;
                throw new RuntimeException('Unexpected form voice query: '.$sql);
            }
            public function execQuery(string $sql): never { throw new RuntimeException('Voice overlay attempted a database write.'); }
        };
        try {
            $GLOBALS['db']=$db;
            $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=true;
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>['protocol_version'=>2,'game'=>'fo4',
                'runtime_variant'=>'flat','session_id'=>'session-curie','request_id'=>'request-curie',
                'generation'=>3,'context_sequence'=>7,'turn_id'=>'turn-curie','type'=>'input_text',
                'capabilities'=>['dialogue.turn_ownership']]];
            $GLOBALS['SYNTH_CORE_CURRENT_PROFILE_DATA']=[];
            $GLOBALS['SYNTH_NAME']=$target['display_name'];
            $GLOBALS['SYNTH_SPEECH_ROLE']='npc';
            $GLOBALS['TTSFUNCTION']='inworld';
            $assertion($db);
        } finally {
            foreach ($saved as $key=>[$exists,$value]) {
                if ($exists) $GLOBALS[$key]=$value; else unset($GLOBALS[$key]);
            }
        }
    }

    public function testNativeReaderUsesImmutableSequenceAndBoundActorNotTheName(): void
    {
        $state=$this->state('synth');$state['actor']['display_name']='Different Display Label';
        $this->withContext([$state],function($db):void {
            $profile=$this->profile(['npc_name'=>'Changed Profile Label']);
            self::assertSame('npcfcurie',synth_character_request_voice_profile($db,$profile)['voiceid']);
            self::assertStringContainsString('c.snapshot_sequence=7',implode("\n",$db->queries));
            self::assertStringContainsString("game='fo4' AND id=42",implode("\n",$db->queries));
            self::assertStringNotContainsString('UPDATE',implode("\n",$db->queries));
        },[],['display_name'=>'Different Display Label']);
    }

    public function testOnlyTheOwnedNpcResponseCanSelectFormVoices(): void
    {
        $this->withContext([$this->state('synth')],function($db):void {
            $profile=$this->profile();
            foreach (['player','narrator'] as $role) {
                $GLOBALS['SYNTH_SPEECH_ROLE']=$role;
                self::assertSame($profile,synth_character_request_voice_profile($db,$profile));
            }
            $GLOBALS['SYNTH_SPEECH_ROLE']='npc';
            $GLOBALS['SYNTH_NAME']='An Unrelated Speaker';
            self::assertSame($profile,synth_character_request_voice_profile($db,$profile));
            $GLOBALS['SYNTH_NAME']='Curie';
            $otherDb=new stdClass();
            self::assertSame($profile,synth_character_request_voice_profile($otherDb,$profile));
        });
        $this->withContext([$this->state('synth')],function($db):void {
            $profile=$this->profile();
            self::assertSame($profile,synth_character_request_voice_profile($db,$profile));
        },[],['form_id'=>'0x00102250']);
    }

    public function testNativeReaderRejectsOtherReferencePluginSaveAndDuplicateState(): void
    {
        $valid=$this->state('synth');
        foreach ([array_replace($valid,['actor'=>array_replace($valid['actor'],['form_id'=>'0x00102250'])]),
            array_replace($valid,['actor'=>array_replace($valid['actor'],['origin_plugin'=>'Other.esp'])]),
            array_replace($valid,['actor'=>array_replace($valid['actor'],['playthrough_id'=>'save-other'])])] as $other) {
            $this->withContext([$other],function($db):void {
                $profile=$this->profile();
                self::assertSame($profile,synth_character_request_voice_profile($db,$profile));
            });
        }
        $this->withContext([$valid,$valid],function($db):void {
            $profile=$this->profile();
            self::assertSame($profile,synth_character_request_voice_profile($db,$profile));
        });
        $this->withContext([$valid],function($db):void {
            $profile=$this->profile();
            self::assertSame($profile,synth_character_request_voice_profile($db,$profile));
        },['playthrough_id'=>'save-other']);
    }

    public function testLegacyOrUnboundProfileDoesNotUseNativeObservedForms(): void
    {
        $this->withContext([$this->state('synth')],function($db):void {
            $profile=$this->profile(['actor_identity_id'=>null]);
            self::assertSame($profile,synth_character_request_voice_profile($db,$profile));
            self::assertSame([],$db->queries);
            $profile=$this->profile();
            $GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version']=1;
            self::assertSame($profile,synth_character_request_voice_profile($db,$profile));
            self::assertSame([],$db->queries);
        });
    }

    public function testNpcMasterVoiceResolutionConsumesOverlayWithoutChangingStoredProfile(): void
    {
        $this->withContext([$this->state('synth')],function($db):void {
            $profile=$this->profile();$before=$profile;
            $master=(new ReflectionClass(NpcMaster::class))->newInstanceWithoutConstructor();
            $property=new ReflectionProperty(NpcMaster::class,'db');$property->setAccessible(true);$property->setValue($master,$db);
            $method=new ReflectionMethod(NpcMaster::class,'resolveNpcTtsVoice');$method->setAccessible(true);
            $voice=$method->invoke($master,$profile);
            self::assertSame('npcfcurie',$voice['original_voice']);
            self::assertSame('npcfcurie',$voice['resolved_voice']);
            self::assertSame($before,$profile);
        });
    }
}
