<?php declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/lib/synth_character_forms.php';

final class CharacterFormPromptTest extends TestCase
{
    private function state(string $base,string $voice='',string $race='',string $plugin='Fallout4.esm'): array
    {
        return ['actor'=>['form_id'=>'0x00102249','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'forms-save','display_name'=>'Curie'],
            'base_form_id'=>'0x'.$base,'base_origin_plugin'=>$plugin,'voice_type'=>$voice,'race'=>$race];
    }
    public function testCurieWrapperRequiresObservedEffectiveBodyAndRetainsIdentity(): void
    {
        $robot=$this->state('00027686','RobotCurie','HandyRace');
        $synth=$this->state('00027686','NPCFCurie','HumanRace');
        self::assertStringContainsString('original Miss Nanny robot body',synth_character_form_prompt([$robot],$robot['actor']));
        $prompt=synth_character_form_prompt([$synth],$synth['actor']);
        self::assertStringContainsString('new bodily sensations, emotions', $prompt);
        self::assertStringContainsString('earlier scientific knowledge and experiences',$prompt);
        self::assertStringNotContainsString('original Miss Nanny robot body',$prompt);
        $unknown=$this->state('00027686');
        self::assertStringContainsString('unconfirmed',synth_character_form_prompt([$unknown],$unknown['actor']));
        $conflict=$this->state('00027686','RobotCurie','HumanRace');
        self::assertStringContainsString('observations conflict',synth_character_form_prompt([$conflict],$conflict['actor']));
    }
    public function testDifferentPluginSaveAndAmbiguousRowsDoNotAcquireCharacterFacts(): void
    {
        $state=$this->state('00027686','NPCFCurie','HumanRace');
        $other=$state['actor'];$other['playthrough_id']='other-save';
        self::assertSame('',synth_character_form_prompt([$state],$other));
        self::assertSame('',synth_character_form_prompt([$state,$state],$state['actor']));
        self::assertSame('',synth_character_form_prompt(array_fill(0,17,$state),$state['actor']));
        $mod=$this->state('00027686','','','OtherMod.esp');
        self::assertSame('',synth_character_form_prompt([$mod],$mod['actor']));
        self::assertSame('',synth_character_form_prompt([$state],['display_name'=>'Curie']));
    }
    public function testVirgilFormsKeepTheirPastWithoutInventingCureForMutant(): void
    {
        $mutant=$this->state('0006B503');$mutant['actor']['form_id']='0x0006B505';
        $human=$this->state('00168B89');$human['actor']['form_id']='0x00168B8A';
        $prompt=synth_character_form_prompt([$mutant],$mutant['actor']);
        self::assertStringContainsString('super mutant form',$prompt);
        self::assertStringContainsString('Brian Virgil',$prompt);
        self::assertStringContainsString('Do not claim a cure has already happened',$prompt);
        self::assertStringContainsString('intervening life as a super mutant',synth_character_form_prompt([$human],$human['actor']));
    }
    public function testDeaconRecurringDisguiseDoesNotResetAndUndercoverCoverStaysPrivate(): void
    {
        $companion=$this->state('00045AC9');$companion['actor']['form_id']='0x00050976';
        self::assertStringContainsString('Previous disguises can recur',synth_character_form_prompt([$companion],$companion['actor']));
        $spy=$this->state('001A041A');$spy['actor']['form_id']='0x001A041B';
        $prompt=synth_character_form_prompt([$spy],$spy['actor']);
        self::assertStringContainsString('Do not volunteer a concealed real name',$prompt);
        self::assertStringNotContainsString('Deacon is one continuing person',$prompt);
    }
    public function testJapanesePhraseReplacesPromptedEnglishAndRepeatedStreamChunks(): void
    {
        $state=$this->state('00002CDA');
        self::assertSame('takahashi',synth_character_speech_policy($state));
        $emitted=false;
        self::assertSame(['Nan-ni shimasho-ka?'],synth_character_constrain_lines(['I learned English!','More English.'],'takahashi',$emitted));
        self::assertSame([],synth_character_constrain_lines(['Here is a translation.'],'takahashi',$emitted));
        $state['base_origin_plugin']='OtherMod.esp';
        self::assertSame('',synth_character_speech_policy($state));
        $empty=false;self::assertSame([],synth_character_constrain_lines(['  '],'takahashi',$empty));self::assertFalse($empty);
    }
    public function testNonverbalHumanRobotAndSpecificFeralDoNotReceiveFluentTts(): void
    {
        foreach ([['00005C85','DLCCoast.esm'],['00001DAC','DLCRobot.esm']] as [$base,$plugin]) {
            $state=$this->state($base,'','',$plugin);
            self::assertSame('nonverbal',synth_character_speech_policy($state));
            $emitted=false;self::assertSame([],synth_character_constrain_lines(['Here are my thoughts.'],'nonverbal',$emitted));
        }
        $state=$this->state('0016556A');$state['actor']['origin_plugin']='DLCworkshop03.esm';$state['actor']['form_id']='0x05001086';
        self::assertSame('nonverbal',synth_character_speech_policy($state));
        $state['actor']['form_id']='0x05001088';self::assertSame('',synth_character_speech_policy($state));
        $emitted=false;self::assertSame(['ordinary speech'],synth_character_constrain_lines(['ordinary speech'],'',$emitted));
    }
    public function testKnownReferenceSpeechRulesSurviveMissingBodyObservations(): void
    {
        foreach ([['Fallout4.esm','0x00002CDB','takahashi'],['DLCworkshop03.esm','0x05004229','nonverbal'],
            ['DLCworkshop03.esm','0x05001086','nonverbal'],['DLCCoast.esm','0x03005C86','nonverbal'],
            ['DLCRobot.esm','0x01001DAD','nonverbal']] as [$plugin,$ref,$policy]) {
            self::assertSame($policy,synth_character_speech_policy(['actor'=>['form_id'=>$ref,'origin_plugin'=>$plugin]]));
            self::assertSame('',synth_character_speech_policy(['actor'=>['form_id'=>$ref,'origin_plugin'=>'OtherMod.esp']]));
        }
    }
}
