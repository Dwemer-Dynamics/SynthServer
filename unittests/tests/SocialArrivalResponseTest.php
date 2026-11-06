<?php declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/lib/synth_social_response.php';

final class SocialArrivalResponseTest extends TestCase {
    private function actor(string $form,string $name): array {
        return ['form_id'=>$form,'origin_plugin'=>'Fallout4.esm','playthrough_id'=>'arrival-save','display_name'=>$name];
    }
    private function fixture(): array {
        $performer=$this->actor('0x00000015','Settler');$recipient=$this->actor('0x00000016','Settler');
        $state=static fn($actor,$x,$f)=>['actor'=>$actor,'alive'=>true,'disabled'=>false,'in_combat'=>false,
            'hostile_to_player'=>false,'teammate'=>$f,'position'=>['x'=>$x,'y'=>0,'z'=>0]];
        $context=['target'=>$performer,'payload'=>['audience'=>[$performer,$recipient],
            'actor_states'=>[$state($performer,0,true),$state($recipient,200,false)],
            'private_history'=>'Do not disclose this player briefing.']];
        $task=['task_id'=>'social:'.str_repeat('a',32),'execution_id'=>'visit:session:1:1',
            'status'=>'arrived','performer'=>$performer,'recipient'=>$recipient,'topic'=>'the water pump'];
        return [$context,$task,array_intersect_key($task,array_flip(['task_id','execution_id','performer','recipient','topic']))];
    }
    public function testSameNameActorsKeepCanonicalRouteAndOnlyAuthorizedTopicIsSpoken(): void {
        [$context,$task,$proof]=$this->fixture();
        $line=synth_social_arrival_line($context,$proof,$task);
        self::assertSame('0x00000015',$line['performer']['form_id']);
        self::assertSame('0x00000016',$line['recipient']['form_id']);
        self::assertSame('I wanted to talk with you about the water pump.',$line['text']);
        self::assertStringNotContainsString('briefing',json_encode($line,JSON_THROW_ON_ERROR));
    }
    public function testReadableLabelsComeFromFreshCanonicalCapture(): void {
        [$context,$task,$proof]=$this->fixture();
        $context['target']['display_name']='Codsworth';$context['payload']['audience'][0]['display_name']='Codsworth';
        $context['payload']['audience'][1]['display_name']='Jun';
        $line=synth_social_arrival_line($context,$proof,$task);
        self::assertSame('Codsworth',$line['task']['performer']['display_name']);
        self::assertSame('Jun',$line['task']['recipient']['display_name']);
    }
    public function testTaskActorTopicAndExecutionCannotBeReplacedAfterArrivalProof(): void {
        foreach(['task_id','execution_id','topic','performer','recipient','status'] as $field) {
            [$context,$task,$proof]=$this->fixture();
            if(in_array($field,['performer','recipient'],true))$task[$field]['form_id']='0x00000017';
            else $task[$field]='forged';
            try {synth_social_arrival_line($context,$proof,$task);self::fail('Changed task reached response');}
            catch(DomainException $error) {self::assertStringStartsWith('social_arrival_',$error->getMessage());}
        }
    }
    public function testFreshDistanceCombatAndSelectedPerformerRemainRequired(): void {
        foreach(['range','combat','selection'] as $change) {
            [$context,$task,$proof]=$this->fixture();
            if($change==='range')$context['payload']['actor_states'][1]['position']['x']=300;
            if($change==='combat')$context['payload']['actor_states'][1]['in_combat']=true;
            if($change==='selection')$context['target']=$task['recipient'];
            try {synth_social_arrival_line($context,$proof,$task);self::fail('Unavailable pair reached response');}
            catch(DomainException $error) {self::assertSame('social_arrival_pair_unavailable',$error->getMessage());}
        }
    }
}
