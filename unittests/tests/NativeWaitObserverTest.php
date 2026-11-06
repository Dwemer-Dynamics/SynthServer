<?php declare(strict_types=1);

require_once dirname(__DIR__,2).'/lib/synth_wait_actions.php';

final class NativeWaitObserverTest extends PHPUnit\Framework\TestCase
{
    private function actor(string $form='0x00000015'): array
    {
        return ['form_id'=>$form,'origin_plugin'=>'Fallout4.esm','playthrough_id'=>'synthetic-save','display_name'=>'Settler'];
    }
    private function event(): array
    {
        return ['protocol_version'=>2,'runtime_variant'=>'flat','type'=>'input_text',
            'capabilities'=>['context.wait_state','action.wait_verified'],'payload'=>['text'=>'Are you waiting?']];
    }
    private function receiptEvent(string $action='wait_here',bool $restrained=false): array
    {
        $event=$this->event();$event['type']='action_result';
        $proof=['schema'=>'synth.action.wait.result.v1','action_id'=>'synthetic:wait','action'=>$action,'actor'=>$this->actor(),
            'owned_hold'=>$action==='wait_here','restrained'=>$restrained,'lease_ms'=>90000,'reload_policy'=>'release_owned_hold',
            'owned_package'=>$action==='wait_here'?'0x01000900':'0x00000000','stationary'=>$action==='wait_here'];
        $event['payload']=['result'=>['action_id'=>'synthetic:wait','status'=>'succeeded','detail'=>json_encode($proof,JSON_THROW_ON_ERROR)]];
        return $event;
    }
    private function text(array $event,array $states=[]): string
    {
        return synth_wait_observer_message($event,$states,$this->actor())['content']??'';
    }
    public function testObserverRequiresNegotiatedFlatSelectedActorContext(): void
    {
        $event=$this->event();$state=[['actor'=>$this->actor(),'wait_held'=>true]];
        foreach(['protocol_version'=>1,'runtime_variant'=>'vr','type'=>'rechat','capabilities'=>[]] as $field=>$value) {
            $invalid=$event;$invalid[$field]=$value;
            $this->assertSame([],synth_wait_observer_message($invalid,$state,$this->actor()));
        }
        $this->assertSame([],synth_wait_observer_message($event,$state,['display_name'=>'Settler']));
        $this->assertSame('system',synth_wait_observer_message($event,$state,$this->actor())['role']);
    }
    public function testFreshCaptureIsExactActorScopedAndMissingIsUnknown(): void
    {
        $event=$this->event();
        $this->assertStringContainsString('confirms a SYNTH-owned Wait hold',$this->text($event,[['actor'=>$this->actor(),'wait_held'=>true]]));
        $false=$this->text($event,[['actor'=>$this->actor(),'wait_held'=>false,'restrained'=>true]]);
        $this->assertStringContainsString('confirms no SYNTH-owned Wait hold',$false);
        $this->assertStringContainsString('does not prove freedom from another restraint',$false);
        foreach([[],[['actor'=>$this->actor()]], [['actor'=>$this->actor('0x00000016'),'wait_held'=>true]],
            [['actor'=>$this->actor(),'wait_held'=>true],['actor'=>$this->actor(),'wait_held'=>false]],
            [['actor'=>$this->actor(),'wait_held'=>'false']]] as $states)
            $this->assertStringContainsString('unknown, not false',$this->text($event,$states));
        $this->assertStringContainsString('90 seconds of unpaused gameplay',$false);
        $this->assertStringContainsString('Stop, load or cleanup may release',$false);
    }
    public function testVerifiedReceiptSupersedesPreActionCaptureWithoutClaimingEnduringWait(): void
    {
        $wait=$this->text($this->receiptEvent(),[['actor'=>$this->actor(),'wait_held'=>false]]);
        $this->assertStringContainsString('issuing snapshot predates this action result',$wait);
        $this->assertStringContainsString('verified to enter a temporary SYNTH wait hold in its owned package and remain stationary',$wait);
        $this->assertStringContainsString('Current waiting requires a fresh observation',$wait);
        $release=$this->text($this->receiptEvent('release_wait',true),[['actor'=>$this->actor(),'wait_held'=>true]]);
        $this->assertStringContainsString('verified to have no SYNTH-owned Wait package',$release);
        $this->assertStringContainsString('Another restraint was present and was not cleared',$release);
        $this->assertStringNotContainsString('unrestrained',$release);
    }
    public function testUnverifiedFailedOrForeignResultCannotReuseIssuingSnapshot(): void
    {
        foreach(['failed','legacy','foreign','moving','bad-package'] as $case) {
            $event=$this->receiptEvent();$proof=json_decode($event['payload']['result']['detail'],true);
            if($case==='failed')$event['payload']['result']['status']='failed';
            if($case==='legacy')$event['capabilities']=['context.wait_state'];
            if($case==='foreign')$proof['actor']=$this->actor('0x00000016');
            if($case==='moving')$proof['stationary']=false;
            if($case==='bad-package')$proof['owned_package']='0xFF000900';
            $event['payload']['result']['detail']=json_encode($proof,JSON_THROW_ON_ERROR);
            $message=$this->text($event,[['actor'=>$this->actor(),'wait_held'=>true]]);
            $this->assertStringContainsString('current owned hold is unknown',$message);
            $this->assertStringNotContainsString('was verified to enter',$message);
        }
    }
    public function testOwnedPackageValidationIsBoundedAndForeignRestraintIsFactual(): void
    {
        $event=$this->receiptEvent('wait_here',false);
        $proof=synth_wait_result_observation($event,'wait_here',$this->actor());
        $this->assertFalse($proof['restrained']);
        foreach(['0x00000900','0xFE0009FF'] as $package)$this->assertTrue(synth_wait_owned_package_valid($package,true));
        foreach(['0xFF000900','0x010008FF','0x01000A00','0x00000000','queued'] as $package)$this->assertFalse(synth_wait_owned_package_valid($package,true));
        $this->assertTrue(synth_wait_owned_package_valid('0x00000000',false));
        $this->assertFalse(synth_wait_owned_package_valid('0x01000900',false));
        $release=$this->receiptEvent('release_wait',true);
        $this->assertTrue(synth_wait_result_observation($release,'release_wait',$this->actor())['restrained']);
    }
}
