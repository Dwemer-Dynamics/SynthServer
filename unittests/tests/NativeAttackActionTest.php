<?php declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/lib/synth_attack_actions.php';
require_once dirname(__DIR__,2).'/lib/synth_protocol_state.php';

final class NativeAttackActionTest extends TestCase
{
    private function fixture(): array
    {
        $event=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/context-combat-contacts.json'),true,flags:JSON_THROW_ON_ERROR);
        $event['type']='input_text';$event['payload']=['text'=>'Attack that mole rat.'];
        $event['capabilities']=array_values(array_unique(array_merge($event['capabilities'],['action.attack','context.enemy_awareness','dialogue.turn_ownership'])));
        $context=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/context-combat-contacts.json'),true,flags:JSON_THROW_ON_ERROR)['payload'];
        $owner=&$context['actor_states'][0];$enemy=$owner['combat_contacts'][0]['actor'];
        $owner['teammate']=true;$owner['hostile_to_player']=false;unset($owner['combat_contacts']);
        $owner['enemy_awareness']=['current'=>[['actor'=>$enemy,'race'=>'Molerat','distance'=>150,'hostile'=>true,'in_combat'=>true,'line_of_sight'=>'visible']],'recent'=>[]];
        $captured=['player'=>$context['player'],'target'=>$context['target'],'payload'=>$context];
        $action=['name'=>'attack','capability'=>'action.attack','server_code'=>'Attack','actor'=>$context['target'],'target'=>$enemy,'arguments'=>(object)[]];
        return [$event,$captured,$action];
    }
    public function testOnlyAnOwnedPlayerInputCanRequestItsFollowersCurrentVisibleHostile(): void
    {
        [$event,$context,$action]=$this->fixture();$actor=$action['actor'];
        $this->assertTrue(synth_attack_supported($event));
        $this->assertTrue(synth_attack_intent_valid($event,$context,$actor,$action));
        $this->assertSame($action['target'],synth_attack_target_named($context,$actor,$action['target']['display_name']));
        $this->assertNull(synth_attack_target_named($context,$actor,'invented target'));
        $this->assertCount(1,$context['payload']['audience']);
        foreach (['trigger','action_result','init'] as $type) {
            $changed=$event;$changed['type']=$type;$this->assertFalse(synth_attack_supported($changed));
        }
        foreach (['action.attack','context.enemy_awareness','dialogue.turn_ownership'] as $missing) {
            $changed=$event;$changed['capabilities']=array_values(array_diff($changed['capabilities'],[$missing]));
            $this->assertFalse(synth_attack_supported($changed));
        }
        $changed=$event;$changed['runtime_variant']='vr';$this->assertFalse(synth_attack_supported($changed));
        $changed=$event;$changed['protocol_version']=1;$this->assertFalse(synth_attack_supported($changed));
        $changed=$event;$changed['payload']['control']['mode']='DIRECTOR';$this->assertFalse(synth_attack_supported($changed));
    }
    public function testRecentOtherNpcAmbiguousDeadBlockedAndForgedTargetsCannotBecomeAttackIntents(): void
    {
        [$event,$context,$action]=$this->fixture();$actor=$action['actor'];
        foreach (['not-teammate','hostile-follower','dead','disabled','blocked','nonhostile','far','recent-only','other-owner','duplicate-owner','foreign-target','forged-performer','argument','missing-target','player-target','wrong-capability','wrong-code'] as $case) {
            $c=$context;$a=$action;$state=&$c['payload']['actor_states'][0];$enemy=&$state['enemy_awareness']['current'][0];
            if($case==='not-teammate')$state['teammate']=false;
            if($case==='hostile-follower')$state['hostile_to_player']=true;
            if($case==='dead')$state['alive']=false;
            if($case==='disabled')$state['disabled']=true;
            if($case==='blocked')$enemy['line_of_sight']='blocked';
            if($case==='nonhostile')$enemy['hostile']=false;
            if($case==='far')$enemy['distance']=2001;
            if($case==='recent-only')$state['enemy_awareness']=['current'=>[],'recent'=>[['actor'=>$a['target'],'race'=>'Molerat','observation'=>'seen_hostile','age_ms'=>100]]];
            if($case==='other-owner')$state['actor']['form_id']='0x00019FD9';
            if($case==='duplicate-owner')$c['payload']['actor_states'][]=$state;
            if($case==='foreign-target')$a['target']['playthrough_id']='other-save';
            if($case==='forged-performer')$a['actor']['form_id']='0x00019FD9';
            if($case==='argument')$a['arguments']=(object)['target_name'=>'Mole Rat'];
            if($case==='missing-target')unset($a['target']);
            if($case==='player-target')$a['target']=$c['player'];
            if($case==='wrong-capability')$a['capability']='action.inspect_actor';
            if($case==='wrong-code')$a['server_code']='Follow';
            $this->assertFalse(synth_attack_intent_valid($event,$c,$actor,$a),$case);
        }
        $state=$context['payload']['actor_states'][0];$duplicate=$state['enemy_awareness']['current'][0];
        $duplicate['actor']['form_id']='0xFF009999';$state['enemy_awareness']['current'][]=$duplicate;
        $context['payload']['actor_states']=[$state];
        $this->assertNull(synth_attack_target_named($context,$actor,$action['target']['display_name']));
    }
    public function testAttackCannotBePersistedBeforeAClaimedNativeRequest(): void
    {
        [$event,,$action]=$this->fixture();$saved=$GLOBALS['SYNTH_REQUEST_EVENT']??null;$claim=$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']??null;
        try {
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$event];$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=false;
            $this->expectException(LogicException::class);$this->expectExceptionMessage('attack_emission_claim_required');
            synth_protocol_record_action_intent(null,$action);
        } finally {
            if($saved===null)unset($GLOBALS['SYNTH_REQUEST_EVENT']);else$GLOBALS['SYNTH_REQUEST_EVENT']=$saved;
            if($claim===null)unset($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']);else$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=$claim;
        }
    }
}
