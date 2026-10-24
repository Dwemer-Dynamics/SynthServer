<?php declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/lib/request.php';
require_once dirname(__DIR__,2).'/lib/synth_rechat_selection.php';

final class ConversationHoldTest extends TestCase
{
    private function fixture(): array {
        $event=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/context-combat-contacts.json'),true,flags:JSON_THROW_ON_ERROR);
        foreach($event['payload']['actor_states'] as &$state) unset($state['combat_contacts'],$state['combat_target']);
        unset($state);
        $state=&$event['payload']['actor_states'][0];
        $state['life_state']='restrained';$state['alive']=true;$state['disabled']=false;$state['in_combat']=false;
        $state['conversation_held']=true;
        return $event;
    }
    public function testOwnedConversationHoldDoesNotSuppressTheAddressedResponder(): void {
        $event=$this->fixture();$state=$event['payload']['actor_states'][0];
        $validated=synth_protocol_validate_actor_state($state,'payload.actor_states.0',2,$event['payload']['player']);
        $this->assertTrue($validated['conversation_held']);
        $candidate=['state'=>$state,'npc'=>['race'=>'HumanRace'],'identity'=>$state['actor']];
        $this->assertTrue(synth_rechat_candidate_available($candidate));
        unset($candidate['state']['conversation_held']);
        $this->assertFalse(synth_rechat_candidate_available($candidate));
        $candidate['state']['conversation_held']=false;
        $this->assertFalse(synth_rechat_candidate_available($candidate));
        foreach(['dead','unconscious','bleedout','essential_down'] as $life) {
            $candidate['state']['conversation_held']=true;$candidate['state']['life_state']=$life;
            $this->assertFalse(synth_rechat_candidate_available($candidate),$life);
        }
    }
    public function testConversationHoldMarkerCannotAdmitInvalidOrPlayerState(): void {
        foreach(['not-bool','not-restrained','dead','disabled','player','v1'] as $case) {
            $event=$this->fixture();$state=&$event['payload']['actor_states'][0];
            if($case==='not-bool')$state['conversation_held']='true';
            if($case==='not-restrained')$state['life_state']='alive';
            if($case==='dead')$state['alive']=false;
            if($case==='disabled')$state['disabled']=true;
            if($case==='player')$state['actor']=$event['payload']['player'];
            unset($state);
            $version=$case==='v1'?1:2;
            $root=dirname(__DIR__,2);
            $script='require '.var_export($root.'/lib/request.php',true).';synth_protocol_validate_actor_state(json_decode('.var_export(json_encode($event['payload']['actor_states'][0]),true).',true),"state",'.$version.');echo "ACCEPTED";';
            $output=(string)shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script));
            $this->assertStringNotContainsString('ACCEPTED',$output,$case);
            $this->assertFalse(json_decode($output,true)['ok']??true,$case);
        }
    }
}
