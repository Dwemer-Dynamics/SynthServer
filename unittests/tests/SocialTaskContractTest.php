<?php declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/lib/synth_social_tasks.php';

final class SocialTaskContractTest extends TestCase {
    public function testSharedLiteralCommandVectorsAndByteLimits(): void {
        $vectors=json_decode(file_get_contents(__DIR__.'/../fixtures/social-task-commands.json'),true,flags:JSON_THROW_ON_ERROR);
        foreach($vectors as $case)$this->assertSame($case['expected'],synth_social_parse_directive($case['text'],$case['performer']??''),$case['text']);
        foreach([str_repeat('x',1201),"Talk to Jun about x\0x", "Talk to Jun about \xff"] as $invalid)$this->assertNull(synth_social_parse_directive($invalid));
        $actor=['form_id'=>'0x00000015','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'save-a','display_name'=>'Codsworth'];
        $payload=synth_social_parse_directive('Go talk to Jun about repairs.')+['performer'=>$actor,'source_text'=>'Go talk to Jun about repairs.'];
        $this->assertTrue(synth_social_request_valid($payload));
        $payload['topic']='attack someone';$this->assertFalse(synth_social_request_valid($payload));
        $payload['topic']='repairs';$payload['invented_permission']=true;$this->assertFalse(synth_social_request_valid($payload));
    }
    public function testDeferredReturnRequiresRealLeaveAndCanonicalPlace(): void {
        $home=['form_id'=>'0x00000020','origin_plugin'=>'Fallout4.esm'];$away=['form_id'=>'0x00000021','origin_plugin'=>'Fallout4.esm'];
        $this->assertSame('ready',synth_social_initial_status('now',$home,$home));
        $this->assertSame('waiting_departure',synth_social_initial_status('on_return',$home,$home));
        $task=['status'=>'waiting_departure','location_ref'=>$home];
        $this->assertSame('waiting_departure',synth_social_context_status($task,$home));
        $this->assertSame('waiting_departure',synth_social_context_status($task,null));
        $task['status']=synth_social_context_status($task,$away);$this->assertSame('waiting_return',$task['status']);
        $this->assertSame('ready',synth_social_context_status($task,$home));
        $this->assertFalse(synth_social_ref_valid(['form_id'=>'0x00000020','origin_plugin'=>'../Fallout4.esm']));
    }
    public function testCurrentPairMustBeExactCalmLivingFollowerAndDistinctKnownRecipient(): void {
        $a=['form_id'=>'0x00000015','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'save-a'];$b=$a;$b['form_id']='0x00000016';
        $state=static fn($actor,$x,$teammate)=>['actor'=>$actor,'alive'=>true,'disabled'=>false,'in_combat'=>false,'hostile_to_player'=>false,'teammate'=>$teammate,'position'=>['x'=>$x,'y'=>0,'z'=>0]];
        $context=['payload'=>['actor_states'=>[$state($a,0,true),$state($b,200,false)]]];
        $this->assertTrue(synth_social_pair_ready($context,$a,$b,256));
        foreach(['dead','combat','hostile','not-follower','far','duplicate','missing','foreign-save','self','player'] as $case) {
            $c=$context;$target=$b;
            if($case==='dead')$c['payload']['actor_states'][1]['alive']=false;
            if($case==='combat')$c['payload']['actor_states'][0]['in_combat']=true;
            if($case==='hostile')$c['payload']['actor_states'][1]['hostile_to_player']=true;
            if($case==='not-follower')$c['payload']['actor_states'][0]['teammate']=false;
            if($case==='far')$c['payload']['actor_states'][1]['position']['x']=257;
            if($case==='duplicate')$c['payload']['actor_states'][]=$c['payload']['actor_states'][1];
            if($case==='missing')array_pop($c['payload']['actor_states']);
            if($case==='foreign-save')$target['playthrough_id']='save-b';
            if($case==='self')$target=$a;
            if($case==='player')$target['form_id']='0x00000014';
            $this->assertFalse(synth_social_pair_ready($c,$a,$target,256),$case);
        }
    }
    public function testSocialWireFixturesUseTheClosedNativeIngressContract(): void {
        require_once dirname(__DIR__,2).'/lib/request.php';$root=dirname(__DIR__,2);
        foreach(['social-task-request','social-task-on-return','social-task-started','social-task-spoken','context-location-identity'] as $name) {
            $event=json_decode(file_get_contents($root.'/protocol/v2/fixtures/valid/'.$name.'.json'),true,flags:JSON_THROW_ON_ERROR);
            $this->assertSame($event,synth_protocol_validate_event($event),$name);
        }
        foreach(['social-task-no-capability','social-task-vr','social-task-unknown-field','social-task-no-spoken-proof','social-task-proof-before-spoken','social-task-bad-revision','context-location-no-capability'] as $name) {
            $script='require '.var_export($root.'/lib/request.php',true).'; synth_protocol_validate_event(json_decode(file_get_contents('.var_export($root.'/protocol/v2/fixtures/invalid/'.$name.'.json',true).'),true)); echo "unexpected";';
            $out=shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script));$error=json_decode($out,true,flags:JSON_THROW_ON_ERROR);$this->assertFalse($error['ok'],$name);
        }
    }
    public function testOrdinaryDialogueHasZeroSocialDatabaseReads(): void {
        $event=['protocol_version'=>2,'runtime_variant'=>'flat','capabilities'=>['social.tasks.v1','context.saved_anchor','dialogue.turn_ownership'],'type'=>'input_text','payload'=>['text'=>'Hello, how are you?']];
        $db=new class {public function __call($name,$args){throw new LogicException('Unexpected database read '.$name);}};
        $this->assertNull(synth_social_admit_from_input($db,$event));
        $event['payload']['text']='Go talk to Jun about repairs.';$event['payload']['control']['mode']='DIRECTOR';
        $this->assertNull(synth_social_admit_from_input($db,$event));
    }
}
