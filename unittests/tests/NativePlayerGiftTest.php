<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__,2).'/lib/synth_protocol_state.php';
require_once dirname(__DIR__,2).'/lib/synth_relationship_worker.php';

final class NativePlayerGiftTest extends TestCase
{
    private function fixture(): array
    {
        $player=['form_id'=>'0x00000014','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'gift-save','display_name'=>'Nora'];
        $npc=['form_id'=>'0x00000015','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'gift-save','display_name'=>'Jun'];
        $item=['form_id'=>'0x00001234','origin_plugin'=>'Fallout4.esm','display_name'=>'Heavy 10mm Pistol',
            'count'=>2,'form_type'=>43,'equipped'=>false,'value'=>100,'weight'=>3];
        $parent=['protocol_version'=>2,'runtime_variant'=>'flat','type'=>'input_text','payload'=>['text'=>'Jun, take my Heavy 10mm Pistol'],
            'capabilities'=>['action.take_item_from_player','action.player_item_inventory','action.give_item_to','action.transfer_inventory','action.inventory_observation','dialogue.turn_ownership']];
        $context=['player'=>$player,'target'=>$npc,'payload'=>['actor_states'=>[
            ['actor'=>$player,'inventory'=>[$item],'inventory_observation'=>'complete'],
            ['actor'=>$npc,'inventory'=>[],'inventory_observation'=>'complete']]]];
        $action=['actor'=>$npc,'target'=>$player,'name'=>'take_item_from_player','capability'=>'action.take_item_from_player',
            'server_code'=>'TakeItemFromPlayer','arguments'=>['item'=>'Heavy 10mm Pistol','amount'=>1]];
        $after=$item;$after['count']=1;
        $result=['schema'=>'synth.action.transfer-result.v2','status'=>'succeeded','action_id'=>'gift:one',
            'inventory'=>['actor'=>$player,'items'=>[$after],'observation'=>'complete'],
            'recipient_inventory'=>['actor'=>$npc,'items'=>[$after],'observation'=>'complete']];
        return [$parent,$action,$context,$result];
    }

    public function testOnlyConfirmedPairedGiftGetsFactsAndModelChoosesItsValue(): void
    {
        [$parent,$action,$context,$result]=$this->fixture();
        self::assertTrue(synth_protocol_valid_transfer_intent($action));
        $gift=synth_player_gift_proof($parent,$action,$context,$result);
        self::assertSame('gift',$gift['meaning']);self::assertSame(1,$gift['amount']);
        self::assertSame('recipient_decides',$gift['valuation']);self::assertArrayNotHasKey('affinity',$gift);
        $entry=['speaker'=>$context['target'],'text'=>'This could help keep Sanctuary safe.',
            'routing'=>['availability'=>'addressed','listener'=>$context['player']]];
        $response=['schema'=>'synth.relationship.response.v3','evaluation_mode'=>'dedicated','entries'=>[$entry],'input_events'=>[]];
        $input=['schema'=>'synth.relationship.evaluation.v2','evaluation_policy'=>'synth.relationship.policy.v2','system_prompt'=>'Evaluate feelings.',
            'context'=>['target'=>$context['target'],'player'=>$context['player']],
            'subjects'=>[['label'=>'Player','subject_id'=>1,'relationship'=>null]],'recent_events'=>[],
            'response'=>$response,'player_lines'=>synth_relationship_player_lines($context,$response),
            'received_gift'=>['fact'=>$gift,'recipient_perspective'=>['actor_identity_id'=>2,'biography'=>'A grieving father rebuilding Sanctuary.',
                'fields'=>['personality'=>'Cautious and protective.','goals'=>'Protect my family.']]]];
        $prompt=synth_relationship_evaluation_prompt($input);
        $data=json_decode($prompt[1]['content'],true,flags:JSON_THROW_ON_ERROR);
        self::assertSame($gift,$data['received_gift']['fact']);
        self::assertSame('Protect my family.',$data['received_gift']['recipient_perspective']['fields']['goals']);
        self::assertStringContainsString('positive, negative or zero',$prompt[0]['content']);
        self::assertStringContainsString('no fixed gift bonus',$prompt[0]['content']);
        $input['received_gift']['recipient_perspective']['fields']=['personality'=>'Dislikes guns.','goals'=>'Avoid violence.'];
        self::assertNotSame($prompt,synth_relationship_evaluation_prompt($input),'The recipient perspective changes the evidence, not a hard-coded score.');
    }

    public function testFailureUnknownInventoryWrongOwnerAndUnconservedCountsAreNotGifts(): void
    {
        foreach (range(0,12) as $bad) {
            [$parent,$action,$context,$result]=$this->fixture();
            if($bad<5)$result['status']=['failed','rejected','cancelled','timed_out','unavailable'][$bad];
            if($bad===5)$result['inventory']['items'][0]['count']=2;
            if($bad===6)$result['recipient_inventory']['items'][0]['count']=2;
            if($bad===7)$result['inventory']['observation']='partial';
            if($bad===8)$result['recipient_inventory']['actor']['playthrough_id']='other-save';
            if($bad===9)$context['payload']['actor_states'][0]['inventory'][0]['equipped']=true;
            if($bad===10)$parent['type']='trigger';
            if($bad===11)$parent['payload']['text']="Don't take my Heavy 10mm Pistol";
            if($bad===12)unset($context['payload']['actor_states'][1]['inventory_observation']);
            self::assertNull(synth_player_gift_proof($parent,$action,$context,$result),'case '.$bad);
        }
    }

    public function testPlayerItemEmissionRequiresAClaimBeforeAnyWrite(): void
    {
        [$event,$action]=$this->fixture();
        $oldEvent=$GLOBALS['SYNTH_REQUEST_EVENT'] ?? null;$oldClaim=$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'] ?? null;
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$event];$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=false;
        try {
            $this->expectException(LogicException::class);$this->expectExceptionMessage('player_item_emission_claim_required');
            synth_protocol_record_action_intent(new stdClass(),$action);
        } finally {
            if ($oldEvent===null) unset($GLOBALS['SYNTH_REQUEST_EVENT']);else $GLOBALS['SYNTH_REQUEST_EVENT']=$oldEvent;
            if ($oldClaim===null) unset($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']);else $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=$oldClaim;
        }
    }

    public function testAudioConsentUsesOnlyTheRawTranscriptionForThisExactOwner(): void
    {
        [$event,$action,$context]=$this->fixture();$event['type']='input_audio';$event['request_id']='audio-one';$event['payload']=['media'=>[]];
        $old=$GLOBALS['SYNTH_PLAYER_ITEM_INPUT'] ?? null;
        try {
            unset($GLOBALS['SYNTH_PLAYER_ITEM_INPUT']);self::assertFalse(synth_player_item_request_allowed($event));
            $GLOBALS['SYNTH_PLAYER_ITEM_INPUT']=['owner_sha256'=>hash('sha256',synth_protocol_canonical_json($event)),'text'=>'Jun, take my pistol'];
            self::assertTrue(synth_player_item_request_allowed($event,null,$context));
            self::assertSame('Heavy 10mm Pistol',synth_player_item_validate(null,$event,$action,$context)['display_name']);
            $changed=$action;
            $changed['arguments']['amount']=2;
            try { synth_player_item_validate(null,$event,$changed,$context); self::fail('Wrong audio quantity accepted'); }
            catch (DomainException $error) { self::assertSame('player_item_offer_mismatch',$error->getMessage()); }
            $wrong=$event;$wrong['request_id']='audio-two';self::assertFalse(synth_player_item_request_allowed($wrong));
            $GLOBALS['SYNTH_PLAYER_ITEM_INPUT']['text']='Do not take my pistol';self::assertFalse(synth_player_item_request_allowed($event));
        } finally {
            if ($old===null) unset($GLOBALS['SYNTH_PLAYER_ITEM_INPUT']);else $GLOBALS['SYNTH_PLAYER_ITEM_INPUT']=$old;
        }
    }

    public function testPlayerOfferBindsTheObservedItemAndQuantity(): void
    {
        [$event,$action,$context,$result]=$this->fixture();
        foreach (['ok, grab the Heavy 10mm Pistol', 'take this pistol', 'Jun, take one pistol from my pack',
            'I have a gift for you, a Heavy 10mm Pistol','Take 1 of my Heavy 10mm Pistols'] as $command) {
            $event['payload']['text']=$command;
            self::assertTrue(synth_player_item_request_allowed($event,null,$context),$command);
            self::assertSame('Heavy 10mm Pistol',synth_player_item_validate(null,$event,$action,$context)['display_name']);
            self::assertSame('gift',synth_player_gift_proof($event,$action,$context,$result)['meaning']);
        }
        $pistol=$context['payload']['actor_states'][0]['inventory'][0];
        $pistol['count']=25;
        $musket=array_replace($pistol,['form_id'=>'0x00001235','display_name'=>'Laser Musket']);
        $context['payload']['actor_states'][0]['inventory']=[$pistol,$musket];
        foreach (['take 1 laser musket','Take 1 laser musket from my pack'] as $command) {
            $event['payload']['text']=$command;
            foreach ([['Heavy 10mm Pistol',20],['Heavy 10mm Pistol',1],['Laser Musket',20]] as [$item,$amount]) {
                $action['arguments']=['item'=>$item,'amount'=>$amount];
                try { synth_player_item_validate(null,$event,$action,$context); self::fail('Wrong item or amount accepted'); }
                catch (DomainException $error) { self::assertSame('player_item_offer_mismatch',$error->getMessage()); }
            }
            $action['arguments']=['item'=>'Laser Musket','amount'=>1];
            self::assertSame($musket,synth_player_item_validate(null,$event,$action,$context));
        }
        $event['payload']['text']='take twenty laser muskets from my pack';
        $action['arguments']['amount']=20;
        self::assertSame($musket,synth_player_item_validate(null,$event,$action,$context));
        $ammo=array_replace($pistol,['form_id'=>'0x00001236','display_name'=>'.45 Round','form_type'=>44,'count'=>50]);
        $context['payload']['actor_states'][0]['inventory'][]=$ammo;
        $event['payload']['text']='I have a gift for you, .45 rounds';
        $action['arguments']=['item'=>'.45 Round','amount'=>1];
        self::assertSame($ammo,synth_player_item_validate(null,$event,$action,$context));
        $action['arguments']['amount']=45;
        try { synth_player_item_validate(null,$event,$action,$context); self::fail('Caliber used as quantity'); }
        catch (DomainException $error) { self::assertSame('player_item_offer_mismatch',$error->getMessage()); }
        $event['payload']['text']='take my 45 round';
        try { synth_player_item_validate(null,$event,$action,$context); self::fail('Missing caliber dot turned into quantity'); }
        catch (DomainException $error) { self::assertSame('player_item_offer_mismatch',$error->getMessage()); }
        $event['payload']['text']='take 45 of my .45 rounds';
        self::assertSame($ammo,synth_player_item_validate(null,$event,$action,$context));
        $shell=array_replace($ammo,['form_id'=>'0x00001237','display_name'=>'20 Gauge Shell']);
        $context['payload']['actor_states'][0]['inventory'][]=$shell;
        $event['payload']['text']='take my 20 gauge shell';
        $action['arguments']=['item'=>'20 Gauge Shell','amount'=>20];
        try { synth_player_item_validate(null,$event,$action,$context); self::fail('Leading item number turned into quantity'); }
        catch (DomainException $error) { self::assertSame('player_item_offer_mismatch',$error->getMessage()); }
        $event['payload']['text']='take one 20 gauge shell';
        $action['arguments']['amount']=1;
        self::assertSame($shell,synth_player_item_validate(null,$event,$action,$context));
        $short=array_replace($pistol,['form_id'=>'0x00001238','display_name'=>'Gift']);
        $long=array_replace($pistol,['form_id'=>'0x00001239','display_name'=>'Gift for You']);
        array_push($context['payload']['actor_states'][0]['inventory'],$short,$long);
        $event['payload']['text']='take my Gift for You';
        $action['arguments']=['item'=>'Gift','amount'=>1];
        try { synth_player_item_validate(null,$event,$action,$context); self::fail('A literal item name was shortened to a different item'); }
        catch (DomainException $error) { self::assertSame('player_item_offer_mismatch',$error->getMessage()); }
        $event['payload']['text']='I have a gift for you, a Laser Musket';
        self::assertNull(synth_player_gift_proof($event,$action,$context,$result));
    }

    public function testGenericOrAmbiguousOffersCannotAuthorizeAnInventoryDebit(): void
    {
        [$event,$action,$context]=$this->fixture();
        foreach (["you didn't grab the gun", "why did you take the gun?", "she said grab the gun", "say grab the gun",
            "take the pistol from Preston", "grab it from her", "take his pistol", "take their rifle", "do not take the gun",
            "if I tell you to take the gun", "do you want the laser musket or the submachine gun?",'take it from my pack',
            'grab that one','I have a gift for you, Jun'] as $command) {
            $event['payload']['text']=$command;self::assertFalse(synth_player_item_request_allowed($event,null,$context),$command);
            try { synth_player_item_validate(null,$event,$action,$context); self::fail('Unbound transfer accepted'); }
            catch (DomainException $error) { self::assertSame('player_item_explicit_request_required',$error->getMessage()); }
        }
        $event['payload']['text']='ok, grab the pistol';
        $wrong=$action;$wrong['target']=$context['target'];
        try {synth_player_item_validate(null,$event,$wrong,$context);self::fail('NPC donor accepted');}
        catch (DomainException $error) {self::assertSame('player_item_explicit_request_required',$error->getMessage());}
        $context['payload']['actor_states'][0]['inventory'][]=array_replace($context['payload']['actor_states'][0]['inventory'][0],
            ['form_id'=>'0x00001235','display_name'=>'Pipe Pistol']);
        try {synth_player_item_validate(null,$event,$action,$context);self::fail('Ambiguous shorthand accepted');}
        catch (DomainException $error) {self::assertSame('player_item_offer_mismatch',$error->getMessage());}
        $context['payload']['actor_states'][0]['inventory'][]=$context['payload']['actor_states'][0]['inventory'][0];
        try {synth_player_item_validate(null,$event,$action,$context);self::fail('Ambiguous stack accepted');}
        catch (DomainException $error) {self::assertSame('player_item_inventory_unavailable',$error->getMessage());}
    }

    public function testPlayerMustExplicitlyOfferAndCurrencyKeepsItsSeparateContract(): void
    {
        foreach (['Jun, grab my submachine gun','take my laser musket','I want you to have this pistol','this rifle is for you'] as $text)
            self::assertTrue(synth_player_item_offer($text,'Jun'),$text);
        foreach (["Don't take my pistol",'If I tell you to take my pistol, will you?','let us trade','borrow my pistol','I have a pistol','take his pistol','I have my pistol','You should not take my pistol','I will give you my pistol later'] as $text)
            self::assertFalse(synth_player_item_offer($text),$text);
        [$parent,$action,$context]=$this->fixture();
        foreach (range(0,7) as $bad) {
            $event=$parent;$intent=$action;$scene=$context;
            if($bad===0)$event['runtime_variant']='vr';
            if($bad===1)$event['protocol_version']=1;
            if($bad===2)$event['capabilities']=array_values(array_diff($event['capabilities'],['action.player_item_inventory']));
            if($bad===3)$intent['actor']=$intent['target'];
            if($bad===4)$intent['arguments']['amount']=3;
            if($bad===5)$scene['payload']['actor_states'][0]['inventory'][0]['form_id']='0x0000000F';
            if($bad===6)$intent['arguments']['item']='Pistol';
            if($bad===7)$scene['payload']['actor_states'][0]['inventory_observation']='partial';
            try {synth_player_item_validate(null,$event,$intent,$scene);self::fail('case '.$bad);}
            catch(DomainException $e){self::assertNotSame('',$e->getMessage());}
        }
    }
}
