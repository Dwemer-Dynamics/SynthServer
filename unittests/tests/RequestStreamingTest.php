<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'request.php';
require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'synth_protocol_state.php';
require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'provider_stream.php';
require_once dirname(__DIR__, 2).'/lib/synth_memory.php';
require_once dirname(__DIR__, 2).'/lib/synth_memory_worker.php';
require_once dirname(__DIR__, 2).'/lib/synth_memory_dispatch.php';

final class RequestStreamingTest extends TestCase
{
    public static function transferActionCodes(): array
    {
        return ['items'=>['GiveItemTo'],'caps'=>['GiveCapsTo'],'player-caps'=>['TakeCapsFromPlayer']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('transferActionCodes')]
    public function testTransferReceiptAndAncestryOwnBothInventories(string $transferName): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_action_inventory.php';
        $event=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/transfer-inventory.json'),true);
        $taking=$transferName==='TakeCapsFromPlayer';
        if ($transferName!=='GiveItemTo') $event['capabilities']=array_merge($event['capabilities'],['action.caps_inventory','action.give_caps_to']);
        if ($taking) {
            $event['capabilities']=array_merge($event['capabilities'],['action.player_caps_inventory','action.take_caps_from_player']);
            [$event['payload']['result']['inventory']['actor'],$event['payload']['result']['recipient_inventory']['actor']]=
                [$event['payload']['result']['recipient_inventory']['actor'],$event['payload']['result']['inventory']['actor']];
        }
        $parent=$event;$parent['type']='input_text';$parent['request_id']='parent';$parent['payload']=['text'=>'Give the rifle to me'];
        $donor=$event['payload']['result']['inventory']['actor'];$recipient=$event['payload']['result']['recipient_inventory']['actor'];
        $db=new class {
            public array $owner=[];
            public array $rows=[];
            public array $writes=[];
            public ?array $terminal=null;
            public function escapeLiteral(string $value):string{return "'".str_replace("'","''",$value)."'";}
            public function fetchOne(string $sql):?array {
                if(str_contains($sql,'origin_request_id')) {
                    preg_match("/i.action_id='([^']+)'/",$sql,$match);return $this->rows[$match[1]??'']??null;
                }
                if(str_contains($sql,'action_terminal_results')) return $this->terminal;
                if(str_contains($sql,'JOIN synth.actor_identities')) return $this->owner;
                if(str_contains($sql,'canonical_event')) return ['protocol_version'=>'2','context_sequence'=>'7'];
                return ['action_id'=>'action-1','idempotency_key'=>'idem-action-1','state'=>'pending','action_name'=>$this->owner['action_name']];
            }
            public function execQuery(string $sql):bool{$this->writes[]=$sql;return true;}
        };
        $owner=['turn_id'=>$event['turn_id'],'request_id'=>$parent['request_id'],'action_name'=>$transferName,
            'capability'=>$taking?'action.take_caps_from_player':($transferName==='GiveCapsTo'?'action.give_caps_to':'action.give_item_to'),'full_form_id'=>$donor['form_id'],'origin_plugin'=>$donor['origin_plugin'],
            'playthrough_id'=>$donor['playthrough_id'],'canonical_event'=>$parent,
            'recipient_form_id'=>$recipient['form_id'],'recipient_plugin'=>$recipient['origin_plugin'],
            'recipient_playthrough_id'=>$recipient['playthrough_id']];
        if ($taking) foreach (['full_form_id'=>'recipient_form_id','origin_plugin'=>'recipient_plugin','playthrough_id'=>'recipient_playthrough_id'] as $actor=>$target)
            [$owner[$actor],$owner[$target]]=[$owner[$target],$owner[$actor]];
        $db->owner=$owner;
        foreach (['succeeded','failed','cancelled','timed_out','rejected'] as $status) {
            $value=$event;$value['payload']['result']['status']=$status;$db->writes=[];
            synth_protocol_persist_action_result($db,$value,$value['session_id']);
            $this->assertCount(2,$db->writes);
            $this->assertStringContainsString('"recipient_inventory"',$db->writes[0]);
        }
        $db->terminal=['result'=>$event['payload']['result']];$db->writes=[];
        synth_protocol_persist_action_result($db,$event,$event['session_id']);$this->assertSame([],$db->writes);
        foreach (['donor','recipient','recipient-plugin','recipient-save','target-form','target-plugin','target-save','same-actor',
            'no-target','swapped','wrong-action','single-schema','parent-marker','receipt-marker','parent-action','receipt-action',
            'vr','generation','sequence','turn','recipient-conflict','quality-conflict',
            ...($transferName!=='GiveItemTo'?['parent-caps-marker','receipt-caps-marker']:[]),
            ...($taking?['parent-payment-marker','receipt-payment-marker']:[])] as $case) {
            $db->owner=$owner;$value=$event;
            if($case==='parent-payment-marker')$db->owner['canonical_event']['capabilities']=array_values(array_diff($value['capabilities'],['action.player_caps_inventory']));
            if($case==='receipt-payment-marker')$value['capabilities']=array_values(array_diff($value['capabilities'],['action.player_caps_inventory']));
            if($case==='parent-caps-marker')$db->owner['canonical_event']['capabilities']=array_values(array_diff($value['capabilities'],['action.caps_inventory']));
            if($case==='receipt-caps-marker')$value['capabilities']=array_values(array_diff($value['capabilities'],['action.caps_inventory']));
            if($case==='donor')$value['payload']['result']['inventory']['actor']['form_id']='0xFF000001';
            if($case==='recipient')$value['payload']['result']['recipient_inventory']['actor']['form_id']='0xFF000001';
            if($case==='recipient-plugin')$value['payload']['result']['recipient_inventory']['actor']['origin_plugin']='Other.esp';
            if($case==='recipient-save')$value['payload']['result']['recipient_inventory']['actor']['playthrough_id']='other:save';
            if($case==='target-form')$db->owner['recipient_form_id']='0xFF000002';
            if($case==='target-plugin')$db->owner['recipient_plugin']='Other.esp';
            if($case==='target-save')$db->owner['recipient_playthrough_id']='other:save';
            if($case==='same-actor')$db->owner['recipient_form_id']=$owner['full_form_id'];
            if($case==='no-target')unset($db->owner['recipient_form_id']);
            if($case==='swapped')[$value['payload']['result']['inventory'],$value['payload']['result']['recipient_inventory']]=
                [$value['payload']['result']['recipient_inventory'],$value['payload']['result']['inventory']];
            if($case==='wrong-action'){$db->owner['action_name']='EquipItem';$db->owner['capability']='action.equip_item';}
            if($case==='single-schema'){$value['payload']['result']['schema']='synth.action.result.v2';unset($value['payload']['result']['recipient_inventory']);}
            if($case==='parent-marker')array_pop($db->owner['canonical_event']['capabilities']);
            if($case==='receipt-marker')array_pop($value['capabilities']);
            if($case==='parent-action')$db->owner['canonical_event']['capabilities']=array_values(array_diff($value['capabilities'],['action.give_item_to']));
            if($case==='receipt-action')$value['capabilities']=array_values(array_diff($value['capabilities'],['action.give_item_to']));
            if($case==='vr'){$value['runtime_variant']='vr';$db->owner['canonical_event']['runtime_variant']='vr';}
            if($case==='generation')$db->owner['canonical_event']['generation']=99;
            if($case==='sequence')$db->owner['canonical_event']['context_sequence']=99;
            if($case==='turn')$db->owner['turn_id']='other:turn';
            if($case==='recipient-conflict')$value['payload']['result']['recipient_inventory']['items'][0]['count']=2;
            if($case==='quality-conflict')$value['payload']['result']['inventory']['observation']='unavailable';
            try{synth_protocol_persist_action_result($db,$value,$value['session_id']);$this->fail('Invalid transfer receipt accepted: '.$case);}
            catch(DomainException $error){$this->assertStringStartsWith('action_',$error->getMessage(),$case);}
            $this->assertSame([],$db->writes,$case);
        }
        $db->owner=$owner;$db->terminal=null;
        $legacy=$event;$legacy['payload']['result']['schema']='synth.action.result.v1';
        unset($legacy['payload']['result']['inventory'],$legacy['payload']['result']['recipient_inventory']);
        synth_protocol_persist_action_result($db,$legacy,$legacy['session_id']);$this->assertCount(2,$db->writes);
        $rows=$event['payload']['result']['recipient_inventory']['items'];
        $third=$donor;$third['form_id']='0xFF000003';
        $payload=['player'=>$taking?$donor:$recipient,'actor_states'=>[
            ['actor'=>$donor,'inventory'=>$rows,'inventory_observation'=>'complete'],
            ['actor'=>$recipient,'inventory'=>[],'inventory_observation'=>'complete'],
            ['actor'=>$third,'inventory'=>$rows,'inventory_observation'=>'partial']]];
        $original=json_encode($payload);
        $base=$owner+['origin_request_id'=>$parent['request_id'],'result'=>$event['payload']['result']];
        $db->rows=['action-1'=>$base];
        $view=synth_protocol_action_inventory_view($db,$event,$payload);
        $this->assertSame([],$view['actor_states'][0]['inventory']);
        $this->assertSame('complete',$view['actor_states'][0]['inventory_observation']);
        $this->assertSame($rows,$view['actor_states'][1]['inventory']);
        $this->assertSame($payload['actor_states'][2],$view['actor_states'][2]);
        $this->assertSame($original,json_encode($payload));
        foreach (['inventory','recipient_inventory'] as $key) {
            $failed=$event;$failed['payload']['result']['status']='failed';
            $failed['payload']['result'][$key]['items']=[];$failed['payload']['result'][$key]['observation']='unavailable';
            $db->rows['action-1']['result']=$failed['payload']['result'];
            $view=synth_protocol_action_inventory_view($db,$failed,$payload);$index=$key==='inventory'?0:1;
            $this->assertSame('unavailable',$view['actor_states'][$index]['inventory_observation']);
            $this->assertSame('complete',$view['actor_states'][1-$index]['inventory_observation']);
        }
        $db->rows['action-1']['result']=$legacy['payload']['result'];
        $view=synth_protocol_action_inventory_view($db,$legacy,$payload);
        foreach ([0,1] as $index) {
            $this->assertSame([],$view['actor_states'][$index]['inventory']);
            $this->assertSame('unavailable',$view['actor_states'][$index]['inventory_observation']);
        }
        // A later recipient-only mutation must not erase the donor's earlier transfer observation.
        $later=$event;$later['request_id']='later';$later['payload']['result']['action_id']='action-later';
        $later['payload']['result']['schema']='synth.action.result.v2';
        $later['payload']['result']['inventory']=['actor'=>$recipient,'items'=>[],'observation'=>'unavailable'];
        unset($later['payload']['result']['recipient_inventory']);
        $event['capabilities'][]='action.equip_item';$parent['capabilities']=$event['capabilities'];$later['capabilities']=$event['capabilities'];
        $db->rows=['action-1'=>array_replace($base,['canonical_event'=>$parent]),'action-later'=>array_replace($base,[
            'action_name'=>'EquipItem','capability'=>'action.equip_item','origin_request_id'=>$event['request_id'],
            'canonical_event'=>$event,'full_form_id'=>$recipient['form_id'],'result'=>$later['payload']['result']])];
        $view=synth_protocol_action_inventory_view($db,$later,$payload);
        $this->assertSame('complete',$view['actor_states'][0]['inventory_observation']);
        $this->assertSame([],$view['actor_states'][0]['inventory']);
        $this->assertSame('unavailable',$view['actor_states'][1]['inventory_observation']);
        $this->assertSame([],$view['actor_states'][1]['inventory']);
        $this->assertSame($original,json_encode($payload));
        foreach (['missing-recipient','wrong-recipient','parent-marker','recipient-absent','recipient-ambiguous',
            ...($transferName!=='GiveItemTo'?['parent-caps-marker','receipt-caps-marker']:[]),
            ...($taking?['parent-payment-marker','receipt-payment-marker']:[])] as $case) {
            $db->rows=['action-1'=>$base];$candidate=$payload;
            $candidateEvent=$event;
            if($case==='parent-payment-marker')$db->rows['action-1']['canonical_event']['capabilities']=array_values(array_diff($event['capabilities'],['action.player_caps_inventory']));
            if($case==='receipt-payment-marker')$candidateEvent['capabilities']=array_values(array_diff($event['capabilities'],['action.player_caps_inventory']));
            if($case==='parent-caps-marker')$db->rows['action-1']['canonical_event']['capabilities']=array_values(array_diff($event['capabilities'],['action.caps_inventory']));
            if($case==='receipt-caps-marker')$candidateEvent['capabilities']=array_values(array_diff($event['capabilities'],['action.caps_inventory']));
            if($case==='missing-recipient')unset($db->rows['action-1']['result']['recipient_inventory']);
            if($case==='wrong-recipient')$db->rows['action-1']['recipient_form_id']='0xFF000001';
            if($case==='parent-marker')array_pop($db->rows['action-1']['canonical_event']['capabilities']);
            if($case==='recipient-absent')unset($candidate['actor_states'][1]);
            if($case==='recipient-ambiguous')$candidate['actor_states'][]=$candidate['actor_states'][1];
            try{synth_protocol_action_inventory_view($db,$candidateEvent,$candidate);$this->fail('Invalid transfer ancestry accepted: '.$case);}
            catch(DomainException $error){$this->assertStringStartsWith('action_inventory_',$error->getMessage(),$case);}
            $this->assertSame($original,json_encode($payload));
        }
    }

    public function testActionInventoryViewUsesAcceptedAncestryWithoutRewritingSnapshots(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_action_inventory.php';
        $equip=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/action-inventory.json'),true);
        $equip['capabilities']=array_merge($equip['capabilities'],['action.unequip_item','action.check_inventory']);
        $actor=$equip['payload']['result']['inventory']['actor'];
        $items=$equip['payload']['result']['inventory']['items'];$items[0]['equipped']=false;$items[1]['equipped']=true;
        $other=$actor;$other['form_id']='0xFF000002';
        $payload=['player'=>['form_id'=>'0x00000014'],'target'=>$actor,'world'=>['game_time_ticks'=>42],
            'actor_states'=>[['actor'=>$actor,'inventory'=>$items,'inventory_observation'=>'complete','health_percent'=>82.5],
                ['actor'=>$other,'inventory'=>$items,'inventory_observation'=>'partial','health_percent'=>100]]];
        $root=$equip;$root['type']='input_text';$root['request_id']='root';$root['payload']=['text'=>'Equip rifle'];
        $check=$equip;$check['request_id']='check';$check['payload']['result']=['schema'=>'synth.action.result.v1',
            'action_id'=>'action-check','idempotency_key'=>'key-check','status'=>'succeeded','detail'=>'preview'];
        $unequip=$equip;$unequip['request_id']='unequip';$unequip['payload']['result']['action_id']='action-unequip';
        $unequip['payload']['result']['idempotency_key']='key-unequip';
        $unequip['payload']['result']['inventory']['items'][0]['equipped']=false;
        $db=new class {
            public array $rows=[];
            public int $reads=0;
            public function escapeLiteral(string $value):string{return "'".str_replace("'","''",$value)."'";}
            public function fetchOne(string $query):?array {
                ++$this->reads;preg_match("/i.action_id='([^']+)'/",$query,$match);return $this->rows[$match[1]??'']??null;
            }
        };
        $make=static fn($name,$cap,$parent,$event)=>['action_name'=>$name,'capability'=>$cap,
            'full_form_id'=>$actor['form_id'],'origin_plugin'=>$actor['origin_plugin'],'playthrough_id'=>$actor['playthrough_id'],
            'origin_request_id'=>$parent['request_id'],'canonical_event'=>$parent,'result'=>$event['payload']['result']];
        $db->rows=['action-1'=>$make('EquipItem','action.equip_item',$root,$equip),
            'action-check'=>$make('CheckInventory','action.check_inventory',$equip,$check),
            'action-unequip'=>$make('UnequipItem','action.unequip_item',$check,$unequip)];
        $original=json_encode($payload);
        $view=synth_protocol_action_inventory_view($db,$check,$payload);
        $expected=$payload;$expected['actor_states'][0]['inventory']=$equip['payload']['result']['inventory']['items'];
        $this->assertSame($expected,$view);
        $this->assertSame($original,json_encode($payload));
        $inventory=synth_protocol_inventory_action_context(['payload'=>$view,'inventory_source'=>'accepted_action_ancestry'],$actor,'Rifle');
        $this->assertTrue($inventory['items'][0]['equipped']);
        $this->assertSame('accepted_action_ancestry',$inventory['source']);
        $savedRows=$db->rows;
        $sceneParent=$root;$sceneParent['type']='trigger';$sceneParent['request_id']='fresh-rechat';
        $sceneParent['capabilities']=array_merge($sceneParent['capabilities'],['dialogue.rechat.ownership','dialogue.rechat.scene']);
        $sceneParent['payload']=['kind'=>'rechat','scene_context_sequence'=>8];
        $fresh=$payload;$fresh['snapshot_sequence']=8;
        $db->rows['action-check']=$make('CheckInventory','action.check_inventory',$sceneParent,$check);
        $db->reads=0;
        $this->assertSame($fresh,synth_protocol_action_inventory_view($db,$check,$fresh));
        $this->assertSame(1,$db->reads,'A fresh scene must stop before older action receipts');
        $db->rows['action-unequip']=$make('UnequipItem','action.unequip_item',$sceneParent,$unequip);
        $view=synth_protocol_action_inventory_view($db,$unequip,$fresh);
        $this->assertSame($unequip['payload']['result']['inventory']['items'],$view['actor_states'][0]['inventory']);
        $this->assertSame($fresh['actor_states'][1],$view['actor_states'][1]);
        $db->rows=$savedRows;
        $view=synth_protocol_action_inventory_view($db,$unequip,$payload);
        $this->assertFalse($view['actor_states'][0]['inventory'][0]['equipped']);
        $missing=$unequip;$missing['payload']['result']['inventory']=['actor'=>$actor,'items'=>[],'observation'=>'unavailable'];
        $db->rows['action-unequip']['result']=$missing['payload']['result'];
        $view=synth_protocol_action_inventory_view($db,$missing,$payload);
        $this->assertSame([],$view['actor_states'][0]['inventory']);
        $this->assertSame('unavailable',$view['actor_states'][0]['inventory_observation']);
        $legacy=$missing;$legacy['payload']['result']['schema']='synth.action.result.v1';unset($legacy['payload']['result']['inventory']);
        $db->rows['action-unequip']['result']=$legacy['payload']['result'];
        $this->assertSame([],synth_protocol_action_inventory_view($db,$legacy,$payload)['actor_states'][0]['inventory']);
        $baseline=$db->rows;
        foreach (['missing','cycle','generation','context','turn','lane','actor','terminal','capability','malformed','ambiguous'] as $case) {
            $db->rows=$baseline;$candidate=$payload;
            if($case==='missing')unset($db->rows['action-1']);
            if($case==='cycle')$db->rows['action-1']['canonical_event']=$check;
            if($case==='generation')$db->rows['action-1']['canonical_event']['generation']=99;
            if($case==='context')$db->rows['action-1']['canonical_event']['context_sequence']=99;
            if($case==='turn')$db->rows['action-1']['canonical_event']['turn_id']='other';
            if($case==='lane')$db->rows['action-1']['canonical_event']['runtime_variant']='vr';
            if($case==='actor')$db->rows['action-1']['full_form_id']='0xFF000002';
            if($case==='terminal')$db->rows['action-1']['result']['status']='failed';
            if($case==='capability')$db->rows['action-1']['canonical_event']['capabilities']=[];
            if($case==='malformed')$db->rows['action-1']['canonical_event']='not-json';
            if($case==='ambiguous')$candidate['actor_states'][]=$candidate['actor_states'][0];
            try{synth_protocol_action_inventory_view($db,$check,$candidate);$this->fail('Bad ancestry accepted: '.$case);}
            catch(DomainException $error){$this->assertStringStartsWith('action_inventory_',$error->getMessage(),$case);}
            $this->assertSame($original,json_encode($payload));
        }
        $db->rows=[];$parent=$root;
        for($i=0;$i<65;++$i) {
            $next=$check;$next['request_id']='request-'.$i;$next['payload']['result']['action_id']='chain-'.$i;
            $next['payload']['result']['idempotency_key']='key-'.$i;
            $db->rows['chain-'.$i]=$make('CheckInventory','action.check_inventory',$parent,$next);$parent=$next;
        }
        $db->reads=0;
        try{synth_protocol_action_inventory_view($db,$next,$payload);$this->fail('Unbounded ancestry accepted');}
        catch(DomainException $error){$this->assertSame('action_inventory_ancestry_limit',$error->getMessage());}
        $this->assertSame(64,$db->reads);
        $without=$check;$without['capabilities']=[];
        $this->assertSame($payload,synth_protocol_action_inventory_view($db,$without,$payload));
        $rechat=$check;$rechat['type']='trigger';$rechat['payload']=['kind'=>'rechat'];
        try{synth_protocol_action_inventory_view($db,$rechat,$payload);$this->fail('Rechat without ownership returned stale inventory');}
        catch(DomainException $error){$this->assertSame('action_inventory_ancestry_capability',$error->getMessage());}
        $rechat['capabilities'][]='dialogue.rechat.ownership';
        $differentOwner=new class {
            public function escapeLiteral(string $value):string{return "'".$value."'";}
            public function withNativeRequestLock(callable $operation,int $timeout):mixed{return $operation(null,['request_id'=>'other']);}
        };
        try{synth_protocol_action_inventory_view($differentOwner,$rechat,$payload);$this->fail('Rechat replaced the actual owner');}
        catch(DomainException $error){$this->assertSame('action_inventory_owner_changed',$error->getMessage());}
        $this->assertNull(synth_rechat_completed_parent($differentOwner,$root),'Unrelated events must not require a native lock');
        $this->assertNull(synth_rechat_completed_parent($differentOwner,$rechat),'Invalid parent IDs must not query evidence');
        // A consumed last item is known empty, not the original inventory; missing receipts mean unknown.
        $consume=$equip;$consume['capabilities']=array_merge($consume['capabilities'],['action.consume','action.consume_inventory']);
        $consume['payload']['result']['inventory']['items']=[];
        $consumeRoot=$root;$consumeRoot['capabilities']=$consume['capabilities'];
        $db->rows=['action-1'=>$make('Consume','action.consume',$consumeRoot,$consume)];
        $empty=synth_protocol_action_inventory_view($db,$consume,$payload);
        $this->assertSame([],$empty['actor_states'][0]['inventory']);
        $this->assertSame('complete',$empty['actor_states'][0]['inventory_observation']);
        $this->assertSame($payload['actor_states'][1],$empty['actor_states'][1]);
        $this->assertSame($original,json_encode($payload));
        $legacyConsume=$consume;unset($legacyConsume['payload']['result']['inventory']);
        $legacyConsume['payload']['result']['schema']='synth.action.result.v1';
        $db->rows['action-1']['result']=$legacyConsume['payload']['result'];
        $this->assertSame('unavailable',synth_protocol_action_inventory_view($db,$legacyConsume,$payload)['actor_states'][0]['inventory_observation']);
        foreach (['parent','receipt'] as $side) {
            $db->rows=['action-1'=>$make('Consume','action.consume',$consumeRoot,$consume)];$candidate=$consume;
            if($side==='parent') array_pop($db->rows['action-1']['canonical_event']['capabilities']);
            else array_pop($candidate['capabilities']);
            try{synth_protocol_action_inventory_view($db,$candidate,$payload);$this->fail('Equipment-only contract admitted Consume ancestry');}
            catch(DomainException $error){$this->assertSame('action_inventory_ancestry_capability',$error->getMessage());}
        }
    }

    public function testManualNativeActivationRoleDoesNotInheritNarratorGlobals(): void
    {
        $keys=['SYNTH_REQUEST_EVENT','SYNTH_NAME'];$saved=array_intersect_key($GLOBALS,array_flip($keys));
        try {
            $GLOBALS['SYNTH_NAME']='The Narrator';
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>['protocol_version'=>2,'type'=>'activate','payload'=>['source'=>'manual']]];
            $this->assertSame('npc',synth_protocol_owned_response_role());
            $this->assertFalse(synth_protocol_response_is_narrator());
            unset($GLOBALS['SYNTH_REQUEST_EVENT']['json']['payload']['source']);
            $this->assertNull(synth_protocol_owned_response_role(),'Unspecified legacy activation must not gain native role authority');
        } finally {
            foreach ($keys as $key) unset($GLOBALS[$key]);
            foreach ($saved as $key=>$value) $GLOBALS[$key]=$value;
        }
    }

    public function testNativeMiddletermPolicyAndWholeSourceThresholds(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_middleterm.php';
        $profile=['metadata'=>['MIDDLE_TERM_MEMORY_ENABLED'=>true]];
        $this->assertTrue(synth_middleterm_enabled([],$profile));
        foreach ([false,0,'off',null] as $disabled) {
            $this->assertFalse(synth_middleterm_enabled(['extended_data'=>['middle_term_enabled'=>$disabled]],$profile));
        }
        $this->assertTrue(synth_middleterm_enabled(['extended_data'=>['middle_term_enabled'=>'yes']],[]));
        $rows=[];
        for ($i=1;$i<=101;$i++) $rows[]=['id'=>$i,'summary_revision'=>0,'last_gamets'=>100,'summary'=>'Whole summary '.$i];
        $this->assertSame([],synth_middleterm_select_sources(array_slice($rows,0,4),null));
        $this->assertCount(5,synth_middleterm_select_sources(array_slice($rows,0,5),null));
        $prior=['history_event_id'=>123,'narrative'=>'Prior'];
        $this->assertSame([],synth_middleterm_select_sources(array_slice($rows,0,9),$prior));
        $this->assertCount(10,synth_middleterm_select_sources(array_slice($rows,0,10),$prior));
        $this->assertSame(range(1,100),array_column(synth_middleterm_select_sources($rows,null),'id'));
        foreach ($rows as &$row) $row['summary']=str_repeat('"',50000);
        unset($row);
        $bounded=synth_middleterm_select_sources($rows,null);
        $this->assertCount(7,$bounded);
        $this->assertSame($rows[0]['summary'],$bounded[0]['summary'],'Size bounding must not truncate whole summaries');
    }

    public function testActionReceiptProfileUsesPerformerBeforeTargetOrLegacySpeakerAliases(): void
    {
        $receipt=['actor'=>'Fixture Settler','target'=>'Preston Garvey','action'=>'Inspect'];
        $this->assertSame('Fixture Settler',synth_extract_funcret_actor(json_encode($receipt)));
        foreach (['speaker','npc','actor_name','character'] as $alias) {
            $this->assertSame('Fixture Settler',synth_extract_funcret_actor(json_encode($receipt+[$alias=>'Other actor'])));
            $this->assertSame('Legacy actor',synth_extract_funcret_actor(json_encode([$alias=>'Legacy actor','target'=>'Other actor'])));
        }
        $this->assertSame('Legacy target',synth_extract_funcret_actor('{"actor":"","target":"Legacy target"}'));
        $this->assertSame('',synth_extract_funcret_actor('not json'));
    }

    public function testLegacyFileWorkerCannotBypassFiveProviderDurableAdmission(): void
    {
        $path=tempnam(sys_get_temp_dir(),'synth-legacy-cutover-');
        $this->assertIsString($path);
        try {
            foreach (['xtts-fastapi','chatterbox','pockettts','cartesia','inworld'] as $driver) {
                foreach (['plan','legacy'] as $format) {
                    // Deliberately omit speaker/text so a regression cannot bootstrap a real runtime.
                    $job=['protocol_event'=>['protocol_version'=>2,'type'=>'input_text',
                        'capabilities'=>['dialogue.turn_ownership']]];
                    if ($format==='plan') $job['tts_plan']=['inputs'=>['TTSFUNCTION'=>$driver]];
                    else $job['tts_function']=$driver;
                    $bytes=json_encode($job,JSON_THROW_ON_ERROR);
                    file_put_contents($path,$bytes);
                    $process=proc_open([PHP_BINARY,dirname(__DIR__,2).'/processor/npc_tts_worker.php',$path],
                        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
                    $this->assertIsResource($process);
                    fclose($pipes[0]);
                    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
                    fclose($pipes[1]);fclose($pipes[2]);
                    $this->assertSame(0,proc_close($process));
                    $this->assertSame('',$output);
                    $this->assertStringContainsString('Legacy native speech job retained; durable admission required.',$error);
                    $this->assertSame($bytes,file_get_contents($path));
                }
            }
        } finally { unlink($path); }
    }

    public function testDurableSpeechRetriesStayPendingAndNeverExposeTheirInternalJobId(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_tts_worker.php';
        $db=new class {
            public ?array $row=null;
            public ?array $other=null;
            public array $queries=[];
            public function fetchOne($sql): ?array {
                $this->queries[]=$sql;
                return str_contains($sql,'WHERE id=') ? $this->row : $this->other;
            }
            public function escapeLiteral($value): string { return "'".$value."'"; }
        };
        $status=['schema'=>'synth.tts_status.v1','cache_key'=>str_repeat('a',32),'status'=>'pending','_job_id'=>1];
        foreach (['pending','running','ready','failed','cancelled','stale'] as $state) {
            $db->row=['state'=>$state,'within_deadline'=>'t'];
            $result=synth_tts_resolve_job_status($db,$status);
            $this->assertArrayNotHasKey('_job_id',$result);
            $this->assertSame(in_array($state,['failed','cancelled','stale'],true)?'failed':'pending',$result['status']);
        }
        $db->row=['state'=>'pending','within_deadline'=>'f'];
        $this->assertSame('failed',synth_tts_resolve_job_status($db,$status)['status']);
        $db->row=null;
        $this->assertSame('failed',synth_tts_resolve_job_status($db,$status)['status']);
        foreach (['failed','cancelled','stale'] as $state) {
            $db->row=['state'=>$state,'within_deadline'=>'t'];
            foreach (['pending','running'] as $other) {
                $db->other=['state'=>$other,'within_deadline'=>'t'];
                $result=synth_tts_resolve_job_status($db,$status);
                $this->assertSame('pending',$result['status']);
                $this->assertArrayNotHasKey('_job_id',$result);
                $this->assertStringContainsString("cache_key='".$status['cache_key']."'",end($db->queries));
            }
        }
        $db->other=['state'=>'running','within_deadline'=>'f'];
        $this->assertSame('failed',synth_tts_resolve_job_status($db,$status)['status']);
    }

    public function testDurableSpeechInputsRequirePrivateStorageOutsideTheDocumentRoot(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_tts_jobs.php';
        $root=sys_get_temp_dir().'/synth-private-input-test-'.bin2hex(random_bytes(8));
        mkdir($root,0700);$path=null;
        try {
            foreach (['','../escape',str_repeat('a',31),str_repeat('a',33)] as $id) {
                try { synth_tts_job_input_path($root,$id,true);$this->fail('Invalid private input ID accepted'); }
                catch (LogicException $error) { $this->assertSame('Invalid speech input identity',$error->getMessage()); }
            }
            $path=synth_tts_job_input_path($root,str_repeat('a',32),true);
            $this->assertFalse(str_starts_with($path,$root.DIRECTORY_SEPARATOR));
            $this->assertSame(str_repeat('a',32).'.json',basename($path));
            if (PHP_OS_FAMILY!=='Windows') {
                $this->assertSame(0,fileperms(dirname($path))&0077);
                chmod(dirname($path),0777);clearstatcache();
                try { synth_tts_job_input_path($root,str_repeat('a',32));$this->fail('Public input directory accepted'); }
                catch (RuntimeException $error) { $this->assertSame('Private speech storage is not isolated',$error->getMessage()); }
            }
        } finally {
            if ($path!==null) { chmod(dirname($path),0700);rmdir(dirname($path)); }
            rmdir($root);
        }
    }

    public function testDurableSpeechExecutionRejectsAnInheritedTransaction(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_tts_jobs.php';
        $db=new class { public function isTransactionIdle(): bool { return false; } };
        try { synth_tts_run_job($db,1,dirname(__DIR__,2),static fn()=>true);$this->fail('Open transaction reached speech worker'); }
        catch (LogicException $error) { $this->assertSame('Speech worker requires an idle database connection',$error->getMessage()); }
        try { synth_tts_due_jobs($db);$this->fail('Open transaction reached speech dispatch'); }
        catch (LogicException $error) { $this->assertSame('Speech dispatch requires an idle database connection',$error->getMessage()); }
    }

    public function testDeferredSpeechAttemptsDoNotInferWorkerLivenessFromTimestampFiles(): void
    {
        $producer=file_get_contents(dirname(__DIR__,2).'/lib/chat_helper_functions.php');
        $start=strpos($producer,'function synth_spawn_deferred_npc_tts_worker(');
        $producer=substr($producer,$start,strpos($producer,'function returnLines(',$start)-$start);
        $worker=file_get_contents(dirname(__DIR__,2).'/processor/npc_tts_worker.php');
        $this->assertStringContainsString("'-attempt-' . bin2hex(random_bytes(16))",$producer);
        $this->assertStringNotContainsString('filemtime(',$producer);
        $this->assertStringNotContainsString("file_put_contents(\$lockPath",$producer);
        $this->assertStringContainsString('flock($cacheLock, LOCK_EX | LOCK_NB)',$worker);
        $this->assertStringContainsString('if ($ownedAttempt) @unlink($jobPath);',$worker);
        $this->assertStringNotContainsString('@unlink($lockPath)',$worker);
        $this->assertStringContainsString("PHP_OS_FAMILY==='Windows'?'c':'ce'",$worker);
        foreach(['lib/synth_tts_worker.php','service/tts_dispatch.php'] as $file) {
            $this->assertStringContainsString("PHP_OS_FAMILY==='Windows'?'c':'ce'",file_get_contents(dirname(__DIR__,2).'/'.$file));
        }
        $supervisor=file_get_contents(dirname(__DIR__,2).'/service/start.sh');
        $this->assertStringContainsString('synthBackgroundProcessorStateDirectory()',$supervisor);
        $this->assertStringContainsString('trap cleanup_service_children EXIT',$supervisor);
        $this->assertStringContainsString('wait "$MANAGER_PID"',$supervisor);
    }

    public function testExplicitListenerReferencesDisambiguateReservedNpcNames(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_rechat_routes.php';
        $speaker=['form_id'=>'0x00000001','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'save','display_name'=>'Settler'];
        $player=array_replace($speaker,['form_id'=>'0x00000014','display_name'=>'Player']);
        $named=array_replace($speaker,['form_id'=>'0x00000002','display_name'=>'The Narrator']);
        $context=['player'=>$player,'target'=>$speaker,'audience'=>[$named]];
        $this->assertSame('unavailable',synth_rechat_resolve_listener($speaker,['listener'=>'The Narrator'],$context)['availability']);
        $this->assertSame($named,synth_rechat_resolve_listener($speaker,['listener'=>'The Narrator','listener_formid'=>$named['form_id']],$context)['listener']);
        $this->assertSame('unavailable',synth_rechat_resolve_listener($speaker,['listener'=>'Player','listener_formid'=>$named['form_id']],$context)['availability']);
        $named['display_name']='Player'; $context['audience']=[$named];
        $this->assertSame($named,synth_rechat_resolve_listener($speaker,['listener'=>'Player','listener_formid'=>$named['form_id']],$context)['listener']);
        $this->assertSame($player,synth_rechat_resolve_listener($speaker,['listener'=>'Sole Survivor','listener_formid'=>$player['form_id']],$context)['listener']);
        require_once dirname(__DIR__,2).'/lib/synth_history_speech.php';
        $this->assertSame([],synth_history_speech_routes(null,[],'Settler',[['type'=>'chat','history_event_id'=>1,'utterance_id'=>null]]));
    }

    public function testDialogueRouteUtteranceUsesExactSpeechOrMetadataId(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_rechat_routes.php';
        $line=['payload'=>['speech'=>['utterance_id'=>'utt:one']]];
        $this->assertNull(synth_rechat_route_utterance([],[]));
        $this->assertSame('utt:one',synth_rechat_route_utterance($line,[]));
        $this->assertSame('utt:one',synth_rechat_route_utterance([],['utterance_id'=>'utt:one']));
        $this->assertSame('utt:one',synth_rechat_route_utterance([],['metadata'=>['utterance_id'=>'utt:one']]));
        $this->assertSame('utt:one',synth_rechat_route_utterance($line,['utterance_id'=>'utt:one','metadata'=>['utterance_id'=>'utt:one']]));
        foreach ([['utterance_id'=>'other'],['metadata'=>['utterance_id'=>'other']],['utterance_id'=>17],
            ['utterance_id'=>[]],['utterance_id'=>"utt:one\n"],['utterance_id'=>str_repeat('a',129)]] as $metadata) {
            try { synth_rechat_route_utterance($line,$metadata); $this->fail('Invalid or conflicting utterance was admitted'); }
            catch (LogicException $error) { $this->assertContains($error->getMessage(),['dialogue_route_utterance_invalid','dialogue_route_utterance_mismatch']); }
        }
        $this->assertSame(str_repeat('a',128),synth_rechat_route_utterance([],['utterance_id'=>str_repeat('a',128)]));
        $this->assertSame('123',synth_rechat_route_utterance([],['utterance_id'=>'123']));
        foreach ([null,[],['utterance_id'=>''],['utterance_id'=>17]] as $speech) {
            try { synth_rechat_route_utterance(['payload'=>['speech'=>$speech]],['utterance_id'=>'utt:one']); $this->fail('Invalid wire speech ID used metadata fallback'); }
            catch (LogicException $error) { $this->assertSame('dialogue_route_utterance_invalid',$error->getMessage()); }
        }
    }

    public function testOwnedRechatEmissionRequiresClaimAndExactWireOwnerBeforeWriting(): void
    {
        require_once dirname(__DIR__,2).'/lib/response.php';
        $event=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/rechat-owned-parent.json'),true,flags:JSON_THROW_ON_ERROR);
        $keys=['SYNTH_REQUEST_EVENT','SYNTH_PROTOCOL_REQUEST_CLAIMED','SYNTH_PROTOCOL_RESPONSE_BODY'];
        $saved=[];
        foreach ($keys as $key) if (array_key_exists($key,$GLOBALS)) $saved[$key]=$GLOBALS[$key];
        try {
            foreach (['dialogue','action'] as $type) {
                $GLOBALS['SYNTH_REQUEST_EVENT']=$event+['json'=>$event];
                $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=false;
                $GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY']='unchanged';
                try { synth_protocol_write_response_line($type,[],'blocked'); $this->fail('Unclaimed response was written'); }
                catch (LogicException $error) { $this->assertSame('rechat_emission_claim_required',$error->getMessage()); }
                $this->assertSame('unchanged',$GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY']);
                $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=true;
                foreach (['request_id','turn_id','generation','context_sequence','runtime_variant','protocol_version'] as $field) {
                    $GLOBALS['SYNTH_REQUEST_EVENT']=$event+['json'=>$event];
                    $GLOBALS['SYNTH_REQUEST_EVENT'][$field]=is_int($event[$field]) ? $event[$field]+1 : 'foreign';
                    try { synth_protocol_write_response_line($type,[],'blocked'); $this->fail('Foreign response was written'); }
                    catch (LogicException $error) { $this->assertSame('rechat_emission_owner_mismatch',$error->getMessage()); }
                    $this->assertSame('unchanged',$GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY']);
                }
            }
            $GLOBALS['SYNTH_REQUEST_EVENT']=$event+['json'=>$event];
            $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=false;
            try { synth_protocol_record_action_intent(null,[]); $this->fail('Unclaimed intent reached persistence'); }
            catch (LogicException $error) { $this->assertSame('rechat_emission_claim_required',$error->getMessage()); }
        } finally {
            foreach ($keys as $key) {
                if (array_key_exists($key,$saved)) $GLOBALS[$key]=$saved[$key]; else unset($GLOBALS[$key]);
            }
        }
    }

    public function testRechatPolicyComesFromChosenProfileAndPreservesExplicitZeroAndFalse(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_rechat_prepare.php';
        $defaults=['rounds'=>9,'probability'=>90,'allow_actions'=>true,'mode'=>'group','private'=>false];
        $candidate=['profile_id'=>'42','actor_identity_id'=>'71','profile_metadata'=>[
            'RECHAT_H'=>'0','RECHAT_P'=>'100','RECHAT_ALLOW_ACTIONS'=>false,'RECHAT_MODE'=>'tight']];
        $policy=synth_rechat_profile_policy($defaults,$candidate);
        $this->assertSame(0,$policy['rounds']);
        $this->assertSame(100,$policy['probability']);
        $this->assertFalse($policy['allow_actions']);
        $this->assertSame('group',$policy['mode']);
        $this->assertSame(42,$policy['source_profile_id']);
        $this->assertSame(71,$policy['source_actor_identity_id']);
        $candidate['profile_metadata']=['RECHAT_H'=>null,'RECHAT_P'=>'','RECHAT_ALLOW_ACTIONS'=>'false'];
        $policy=synth_rechat_profile_policy($defaults,$candidate);
        $this->assertSame(9,$policy['rounds']);
        $this->assertSame(90,$policy['probability']);
        $this->assertFalse($policy['allow_actions']);
        $candidate['profile_metadata']=['RECHAT_H'=>['unexpected']];
        $this->expectExceptionMessage('rechat_profile_policy_invalid');
        synth_rechat_profile_policy($defaults,$candidate);
    }

    public function testRechatRanksCanonicalParticipantsWithoutNameCollapseOrGlobalFallback(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_rechat_selection.php';
        $a=['form_id'=>'0x00000001','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'save','display_name'=>'Settler'];
        $b=array_replace($a,['form_id'=>'0x00000002']);
        $c=array_replace($a,['form_id'=>'0x00000003','display_name'=>'Witness']);
        $candidates=array_map(static fn(array $actor):array=>['identity'=>$actor,'npc'=>['race'=>'HumanRace'],
            'state'=>['alive'=>true,'disabled'=>false,'life_state'=>'alive','posture'=>'normal']],[$a,$b,$c]);
        $proof=['speaker'=>$a,'listener_state'=>'addressed','listener'=>$b];
        foreach (['tight','conversational'] as $mode) {
            $this->assertSame($b,synth_rechat_rank_candidates($candidates,$proof,$mode,[])[0]['identity']);
        }
        $this->assertSame($c,synth_rechat_rank_candidates($candidates,$proof,'group',[])[0]['identity']);
        $history=[['role'=>'npc','identity'=>$c,'round_number'=>1]];
        $this->assertSame($b,synth_rechat_rank_candidates($candidates,$proof,'group',$history)[0]['identity']);
        $ambiguous=array_replace($proof,['listener_state'=>'ambiguous','listener'=>null]);
        $this->assertSame([],synth_rechat_rank_candidates($candidates,$ambiguous,'tight',[]));
        foreach ([['alive'=>false],['disabled'=>true],['life_state'=>'unconscious'],['posture'=>'sleeping']] as $change) {
            $bad=$candidates; $bad[1]['state']=array_replace($bad[1]['state'],$change);
            $this->assertSame([],synth_rechat_rank_candidates($bad,$proof,'tight',[]));
        }
        $bad=$candidates; $bad[1]['state']=[];
        $this->assertSame([],synth_rechat_rank_candidates($bad,$proof,'tight',[]));
        $bad=$candidates; $bad[1]['npc']['race']='FeralGhoulRace';
        $this->assertSame([],synth_rechat_rank_candidates($bad,$proof,'tight',[]));
        $bad[1]['npc']['race']='ProtectronRace';
        $this->assertSame($b,synth_rechat_rank_candidates($bad,$proof,'tight',[])[0]['identity']);
        $bad=$candidates; $bad[2]['identity']['playthrough_id']='other-save';
        $this->assertSame($b,synth_rechat_rank_candidates($bad,$proof,'group',[])[0]['identity']);
    }

    public function testRechatCompletedFramingRejectsPartialForeignAndDuplicateLines(): void
    {
        require_once dirname(__DIR__,2).'/lib/response.php';
        require_once dirname(__DIR__,2).'/lib/synth_rechat_routes.php';
        $previous=$GLOBALS['SYNTH_REQUEST_EVENT'] ?? null;
        $event=['request_id'=>'framing','turn_id'=>'turn','generation'=>1,'context_sequence'=>1,
            'protocol_version'=>2,'runtime_variant'=>'flat'];
        try {
            $GLOBALS['SYNTH_REQUEST_EVENT']=$event+['json'=>$event];
            $start=synth_protocol_encode_response_line('response_start',['server_version'=>'fixture'],'start');
            $middle=synth_protocol_encode_response_line('status',['code'=>'fixture'],'status');
            $end=synth_protocol_encode_response_line('response_end',['status'=>'complete'],'end');
            $body=$start.$middle.$end;
            $this->assertCount(3,synth_rechat_completed_lines($body,$event));
            foreach (['no_candidate','private','budget_exhausted','ineligible'] as $reason) {
                $stopped=synth_protocol_encode_ack('rechat_complete',['detail'=>$reason]);
                $lines=synth_rechat_completed_lines($stopped,$event);
                $this->assertCount(3,$lines);
                $this->assertSame('rechat_complete',$lines[1]['line']['payload']['code']);
                $this->assertSame($reason,$lines[1]['line']['payload']['detail']);
            }
            foreach ([$start.$middle,$body.$middle,$start.$middle.$middle.$end,rtrim($body),
                str_replace('"status":"complete"','"status":"error"',$body),
                str_replace('"request_id":"framing"','"request_id":"other"',$body),
                str_replace('"runtime_variant":"flat"','"runtime_variant":"vr"',$body),
                str_replace('"context_sequence":1','"context_sequence":2',$body),
                $start."not-json\n".$end] as $bad) {
                $this->assertNull(synth_rechat_completed_lines($bad,$event));
            }
        } finally { $GLOBALS['SYNTH_REQUEST_EVENT']=$previous; }
    }

    public function testNativeRechatBudgetPreservesConsecutiveSuccessBounds(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_rechat_admission.php';
        $this->assertSame(0,synth_rechat_roll_budget(10,0));
        $this->assertSame(0,synth_rechat_roll_budget(0,100));
        $this->assertSame(0,synth_rechat_roll_budget(-1,100));
        $this->assertSame(0,synth_rechat_roll_budget(10,-1));
        $this->assertSame(10,synth_rechat_roll_budget(10,100));
        $this->assertSame(10,synth_rechat_roll_budget(10,150));
        $this->assertSame(PHP_INT_MAX,synth_rechat_roll_budget(PHP_INT_MAX,100));
        for ($i=0;$i<16;++$i) {
            $budget=synth_rechat_roll_budget(4,50);
            $this->assertGreaterThanOrEqual(0,$budget);
            $this->assertLessThanOrEqual(4,$budget);
        }
    }

    public function testNativeRechatListenerUsesCapturedIdentityAndPreservesAmbiguity(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_rechat_routes.php';
        $a=['form_id'=>'0x00000001','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'Save:A','display_name'=>'Settler'];
        $b=array_replace($a,['form_id'=>'0x00000002']);
        $p=array_replace($a,['form_id'=>'0x00000014','display_name'=>'Nora']);
        $context=['player'=>$p,'target'=>$a,'audience'=>[$a,$b]];
        $input=['type'=>'input_text','payload'=>[]];
        $this->assertSame($p,synth_response_listener_route($a,[],$context,$input)['listener']);
        $this->assertSame($p,synth_response_listener_route($a,['listener'=>'Unknown'],$context,$input)['listener']);
        $this->assertSame('unavailable',synth_response_listener_route($a,[],$context,['type'=>'trigger','payload'=>[]])['availability']);
        $this->assertSame('unavailable',synth_response_listener_route($p,[],$context,$input)['availability']);
        $this->assertSame('ambiguous',synth_response_listener_route($a,['listener'=>'Settler'],$context,$input)['availability']);
        $this->assertSame('unavailable',synth_response_listener_route($a,['listener_formid'=>'0x00000999'],$context,$input)['availability']);
        $this->assertSame($b,synth_response_listener_route($a,['listener_formid'=>$b['form_id']],$context,$input)['listener']);
        $this->assertSame('ambiguous',synth_rechat_resolve_listener($a,['listener'=>'Settler'],$context)['availability']);
        $this->assertSame($b,synth_rechat_resolve_listener($a,['listener'=>'Settler','listener_formid'=>$b['form_id']],$context)['listener']);
        $this->assertSame($p,synth_rechat_resolve_listener($a,['listener'=>'Player'],$context)['listener']);
        $this->assertSame($b,synth_rechat_resolve_listener($a,['metadata'=>['listener_formid'=>$b['form_id']]],$context)['listener']);
        foreach ([[],['listener'=>'Unknown'],['listener'=>'Settler','listener_formid'=>$a['form_id']],
            ['listener'=>'__player_text_only','listener_formid'=>$b['form_id']],
            ['listener'=>'The Narrator'],['listener'=>'Nora','listener_formid'=>$b['form_id']],
            ['listener'=>'Player','listener_formid'=>$b['form_id']],['listener_formid'=>'00000002'],
            ['listener_formid'=>$b['form_id'],'listener_plugin'=>'Other.esm'],['listener_plugin'=>'Fallout4.esm']] as $metadata) {
            $this->assertSame(['availability'=>'unavailable','listener'=>null],synth_rechat_resolve_listener($a,$metadata,$context));
        }
        $other=array_replace($b,['origin_plugin'=>'Other.esm']);
        $context['audience'][]=$other;
        $this->assertSame('ambiguous',synth_rechat_resolve_listener($a,['listener_formid'=>$b['form_id']],$context)['availability']);
        $this->assertSame($other,synth_rechat_resolve_listener($a,['listener_formid'=>$b['form_id'],'listener_plugin'=>'OTHER.ESM'],$context)['listener']);
        $context['audience']=[array_replace($b,['playthrough_id'=>'save:a'])];
        $this->assertSame('unavailable',synth_rechat_resolve_listener($a,['listener_formid'=>$b['form_id']],$context)['availability']);
        $this->assertNotSame(synth_rechat_actor_key($a),synth_rechat_actor_key(array_replace($a,['playthrough_id'=>'save:a'])));
        $this->assertSame('unavailable',synth_rechat_resolve_listener(['kind'=>'narrator','id'=>'narrator'],['listener'=>'Player'],$context)['availability']);
    }

    public function testDialogueListenerWireIsCanonicalAndCapabilityGated(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_rechat_routes.php';
        $a=['form_id'=>'0x00000001','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'Save:A','display_name'=>'Settler'];
        $b=array_replace($a,['form_id'=>'0x00000002']);
        $player=array_replace($a,['form_id'=>'0x00000014','display_name'=>'Nora']);
        $context=['player'=>$player,'target'=>$a,'audience'=>[$a,$b]];
        foreach (['flat','vr'] as $lane) {
            $event=['protocol_version'=>2,'runtime_variant'=>$lane,
                'capabilities'=>['dialogue.turn_ownership','dialogue.listener_identity']];
            $line=['type'=>'dialogue','payload'=>['speaker'=>$a,'text'=>'Which settler?']];
            $wire=json_encode($line)."\n";
            foreach ([$b,$player] as $listener) {
                $route=synth_rechat_resolve_listener($a,['listener_formid'=>$listener['form_id']],$context);
                $final=synth_rechat_listener_wire($event,$line,$route,$wire);
                $this->assertSame($listener,json_decode($final,true)['payload']['listener']);
                $this->assertNotSame(hash('sha256',$wire),hash('sha256',$final));
                $this->assertSame(1,substr_count($final,"\n"));
                foreach ([[],['dialogue.turn_ownership'],['dialogue.listener_identity']] as $caps)
                    $this->assertSame($wire,synth_rechat_listener_wire(array_replace($event,['capabilities'=>$caps]),$line,$route,$wire));
                $this->assertSame($wire,synth_rechat_listener_wire(array_replace($event,['protocol_version'=>1]),$line,$route,$wire));
            }
            foreach ([[],['listener'=>'Settler'],['listener_formid'=>$a['form_id']],['listener'=>'Unknown']] as $metadata)
                $this->assertSame($wire,synth_rechat_listener_wire($event,$line,synth_rechat_resolve_listener($a,$metadata,$context),$wire));
        }
        $line['payload']['listener']=$player;
        $this->expectException(LogicException::class);
        synth_rechat_listener_wire($event,$line,['availability'=>'unavailable'],$wire);
    }

    public function testFreshSceneAcknowledgementRequiresCompleteOptInAndPreservesFeatureCode(): void
    {
        require_once dirname(__DIR__,2).'/lib/response.php';
        $previous=$GLOBALS['SYNTH_REQUEST_EVENT'] ?? null;
        try {
            $caps=['dialogue.turn_ownership','dialogue.rechat.ownership','dialogue.rechat.scene'];
            foreach (['flat','vr'] as $lane) foreach (['complete','missing-turn','missing-parent','missing-scene','old','context','other-status'] as $case) {
                $offered=$caps;
                $missing=array_search($case,['missing-turn','missing-parent','missing-scene'],true);
                if ($missing!==false) unset($offered[$missing]);
                $event=['type'=>$case==='context'?'context':'init','protocol_version'=>$case==='old'?1:2,
                    'runtime_variant'=>$lane,'capabilities'=>array_merge(array_values($offered),['context.inventory_512']),
                    'request_id'=>'init:scene','turn_id'=>'turn:scene','generation'=>1,'context_sequence'=>0];
                $GLOBALS['SYNTH_REQUEST_EVENT']=$event+['json'=>$event];
                $lines=array_map(static fn($line)=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),
                    explode("\n",trim(synth_protocol_encode_ack($case==='other-status'?'context_accepted':'init_accepted'))));
                $this->assertCount(3,$lines);
                $this->assertSame($case==='complete',$lines[1]['payload']['rechat_scene'] ?? false,$lane.':'.$case);
                if ($case==='complete') $this->assertSame($lane==='flat'?'init_inventory_512_accepted':'init_accepted',$lines[1]['payload']['code']);
            }
        } finally {
            if ($previous===null) unset($GLOBALS['SYNTH_REQUEST_EVENT']);
            else $GLOBALS['SYNTH_REQUEST_EVENT']=$previous;
        }
    }

    public function testQuestSupportIsAcknowledgedOnlyForAnOptedInNativeFlatInit(): void
    {
        require_once dirname(__DIR__,2).'/lib/response.php';
        $previous=$GLOBALS['SYNTH_REQUEST_EVENT']??null;
        try {
            foreach (['supported','tracking','both','player','reaction','reaction-no-history','actor','actor-only','inventory','inventory-only',
                'action-inventory','action-inventory-only','action-inventory-no-owner','action-inventory-no-cap',
                'consume','consume-no-marker','consume-no-action','transfer','transfer-consume','transfer-no-marker','transfer-no-action',
                'caps','caps-consume','caps-no-marker','caps-no-action','caps-payment','caps-payment-no-marker','caps-payment-no-action','old','vr','missing','context'] as $case) {
                $event=['type'=>$case==='context'?'context':'init','protocol_version'=>$case==='old'?1:2,
                    'runtime_variant'=>$case==='vr'?'vr':'flat',
                    'capabilities'=>$case==='missing'?[]:['context.quest_events'],
                    'request_id'=>'init:test','turn_id'=>'turn:test','generation'=>1,'context_sequence'=>0];
                if ($case==='tracking') $event['capabilities']=['context.quest_tracking'];
                if ($case==='both') $event['capabilities'][]='context.quest_tracking';
                if ($case==='player') $event['capabilities'][]='context.player_events';
                if (in_array($case,['reaction','old','vr','context'],true)) $event['capabilities']=['context.player_events','dialogue.player_reactions'];
                if ($case==='reaction-no-history') $event['capabilities']=['dialogue.player_reactions'];
                if (in_array($case,['actor','old','vr','context'],true)) $event['capabilities'][]='context.actor_events';
                if ($case==='actor-only') $event['capabilities']=['context.actor_events'];
                if (in_array($case,['inventory','old','vr','context'],true)) $event['capabilities'][]='context.inventory_512';
                if ($case==='inventory-only') $event['capabilities']=['context.inventory_512'];
                if (in_array($case,['action-inventory','old','vr','context'],true))
                    $event['capabilities']=array_merge($event['capabilities'],['action.inventory_observation','dialogue.turn_ownership','context.inventory_512']);
                if ($case==='action-inventory-only') $event['capabilities']=['action.inventory_observation','dialogue.turn_ownership'];
                if ($case==='action-inventory-no-owner') $event['capabilities']=['action.inventory_observation'];
                if ($case==='action-inventory-no-cap') $event['capabilities']=['dialogue.turn_ownership'];
                if (in_array($case,['consume','consume-no-marker','consume-no-action'],true)) {
                    $event['capabilities']=['action.inventory_observation','dialogue.turn_ownership'];
                    if($case!=='consume-no-marker') $event['capabilities'][]='action.consume_inventory';
                    if($case!=='consume-no-action') $event['capabilities'][]='action.consume';
                }
                if (str_starts_with($case,'transfer') || str_starts_with($case,'caps')) {
                    $event['capabilities']=['action.inventory_observation','dialogue.turn_ownership'];
                    if($case!=='transfer-no-marker')$event['capabilities'][]='action.transfer_inventory';
                    if($case!=='transfer-no-action')$event['capabilities'][]='action.give_item_to';
                    if(in_array($case,['transfer-consume','caps-consume'],true))$event['capabilities']=array_merge($event['capabilities'],['action.consume','action.consume_inventory']);
                    if(str_starts_with($case,'caps')) {
                        if($case!=='caps-no-marker')$event['capabilities'][]='action.caps_inventory';
                        if($case!=='caps-no-action')$event['capabilities'][]='action.give_caps_to';
                        if(str_starts_with($case,'caps-payment')) {
                            if($case!=='caps-payment-no-marker')$event['capabilities'][]='action.player_caps_inventory';
                            if($case!=='caps-payment-no-action')$event['capabilities'][]='action.take_caps_from_player';
                        }
                    }
                }
                $GLOBALS['SYNTH_REQUEST_EVENT']=$event+['json'=>$event];
                $lines=array_map(static fn($line)=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),
                    explode("\n",trim(synth_protocol_encode_ack('init_accepted'))));
                $this->assertCount(3,$lines);
                $expected=match($case) {'supported'=>'init_quest_events_accepted','tracking'=>'init_quest_tracking_accepted',
                    'both'=>'init_quest_events_tracking_accepted','player'=>'init_player_events_accepted',
                    'reaction'=>'init_player_reactions_accepted','actor','actor-only'=>'init_actor_events_accepted',
                    'inventory','inventory-only'=>'init_inventory_512_accepted',
                    'action-inventory','action-inventory-only','consume-no-marker','consume-no-action','transfer-no-marker','transfer-no-action'=>'init_action_inventory_accepted',
                    'transfer','transfer-consume','caps-no-marker','caps-no-action'=>'init_transfer_inventory_accepted',
                    'caps','caps-consume','caps-payment-no-marker','caps-payment-no-action'=>'init_caps_inventory_accepted',
                    'caps-payment'=>'init_player_caps_inventory_accepted',
                    'consume'=>'init_consume_inventory_accepted',default=>'init_accepted'};
                $this->assertSame($expected,$lines[1]['payload']['code']);
                $this->assertSame('init:test',$lines[1]['request_id']);
                $this->assertSame('complete',$lines[2]['payload']['status']);
            }
        } finally { $GLOBALS['SYNTH_REQUEST_EVENT']=$previous; }
    }

    public function testDiaryVisibilityValidationIsMaterializedBeforeReceiptJoins(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/lib/synth_diary_state.php');
        $this->assertStringContainsString('diary_scoped_inputs AS MATERIALIZED',$source);
        $this->assertStringContainsString('diary_visible_sources AS MATERIALIZED',$source);
        $this->assertStringContainsString('source.history_event_id=visible.history_event_id AND input.actor_identity_id=visible.reader_identity_id',$source);
        $this->assertStringContainsString('LEFT JOIN diary_visible_sources visible',$source);
        $this->assertStringContainsString('diary_valid_inputs AS MATERIALIZED',$source);
        $this->assertStringContainsString('accepted.history_event_id<=scope.cutoff',$source);
        $this->assertStringContainsString('FROM diary_valid_inputs i',$source);
        $this->assertStringContainsString("r.embedding_text_sha256=encode(sha256(convert_to(r.result_payload->>'content','UTF8')),'hex')",$source);
        $worker=file_get_contents(dirname(__DIR__,2).'/lib/synth_diary_jobs.php');
        $this->assertStringContainsString("hash('sha256',\$value['content'])",$worker);
        $this->assertStringContainsString('CASE WHEN c.embedding_text_sha256 IS NOT NULL THEN c.embedding ELSE NULL END',$worker);
    }

    public function testNativeMemorySummaryRejectsInvalidFrozenRevision(): void
    {
        foreach ([-1,'1',null] as $revision) {
            try { synth_memory_summary_prompt(['schema'=>'synth.memory.input.v1','events'=>[['data'=>'recorded']],
                'previous_summary'=>['id'=>1,'summary'=>'Frozen','revision'=>$revision]],[],'','');$this->fail('Invalid frozen revision accepted'); }
            catch (InvalidArgumentException $error) { $this->assertSame('Invalid prior native memory input',$error->getMessage()); }
        }
    }

    public function testNativeMemoryJobsKeepEvidenceAndInputLocks(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/lib/synth_memory.php');
        $this->assertStringContainsString('FROM native_memory_evidence_batches b JOIN synth_meta.memory_batch_sources',$source);
        $this->assertStringContainsString('synth_memory_lock_job_input($connection,$db,$event,$jobId)',$source);
    }

    public function testLegacyRowTargetsRejectSqlFragmentsAndNativeNamespaces(): void
    {
        require_once dirname(__DIR__,2).'/lib/legacy_row_management.php';
        foreach (['responselog','eventlog','log','diarylog','memory','memory_summary'] as $table) {
            $this->assertSame([$table,'12'],synthLegacyRowTarget(['table'=>$table,'rowid'=>12]));
        }
        $this->assertSame(['eventlog','12'],synthLegacyRowTarget(['table'=>'cache','rowid'=>'12']));
        foreach (['memory_summaries','synth_meta.memory_summaries','eventlog;DELETE FROM log','quests','',[]] as $table) {
            try { synthLegacyRowTarget(['table'=>$table,'rowid'=>1]); $this->fail('Invalid table accepted'); }
            catch (InvalidArgumentException $error) { $this->assertSame('Invalid legacy row target.',$error->getMessage()); }
        }
        foreach ([0,-1,'0','01','1 OR 1=1','1;DELETE FROM log',"1\n",1.0,true,null,[],str_repeat('9',19)] as $id) {
            try { synthLegacyRowTarget(['table'=>'eventlog','rowid'=>$id]); $this->fail('Invalid ID accepted'); }
            catch (InvalidArgumentException $error) { $this->assertSame('Invalid legacy row target.',$error->getMessage()); }
        }
    }

    public function testManagementTransactionDoesNotReconnectAfterBegin(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/lib/postgresql.class.php');
        $method=explode('public function execQueryVerbose',explode('public function withManagementTransaction',$source,2)[1],2)[0];
        $afterBegin=explode("'BEGIN ISOLATION LEVEL READ COMMITTED'",$method,2)[1];
        $this->assertStringNotContainsString('re_connect',$afterBegin);
        $this->assertStringContainsString('pg_transaction_status($connection)!==PGSQL_TRANSACTION_INTRANS',$afterBegin);
        $this->assertStringContainsString("pg_query(\$connection,'COMMIT')",$afterBegin);
        $this->assertStringContainsString("pg_query(\$connection,'ROLLBACK')",$afterBegin);
        $this->assertStringContainsString("statement_timeout='2s'",$afterBegin);
    }

    public function testNativeMemoryHelpersCannotReachLegacyMutationsOrCounts(): void
    {
        require_once dirname(__DIR__,2).'/lib/legacy_row_management.php';
        require_once dirname(__DIR__,2).'/lib/memory_helper_vectordb.php';
        $base=['rowid'=>'7','summary'=>' Saved text ','gamets_truncated'=>'100',
            'rank_any'=>0.2,'rank_all'=>0.4,'summary_revision'=>'9007199254740993'];
        $mapped=synthMemoryQueryContent([
            $base+['memory_source'=>'native','distance'=>null],
            $base+['memory_source'=>'native_diary'],
            $base,
            $base+['memory_source'=>'native_diary','distance'=>'0.15'],
            null,['summary'=>'   '],
        ])['content'];
        $this->assertCount(4,$mapped);
        foreach ([0,1,3] as $index) {
            $this->assertSame(0,$mapped[$index]['memory_id']);
            $this->assertSame('9007199254740993',$mapped[$index]['summary_revision']);
            $this->assertSame('Saved text',$mapped[$index]['briefing']);
        }
        $this->assertSame(7,$mapped[0]['native_memory_id']);
        $this->assertNull($mapped[0]['native_diary_id']);
        $this->assertNull($mapped[1]['native_memory_id']);
        $this->assertSame(7,$mapped[1]['native_diary_id']);
        $this->assertNull($mapped[0]['distance']);
        $this->assertNull($mapped[1]['distance']);
        $this->assertSame(0.15,$mapped[3]['distance']);
        $this->assertSame(7,$mapped[2]['memory_id']);
        $this->assertNull($mapped[2]['native_memory_id']);
        $this->assertNull($mapped[2]['native_diary_id']);
        $this->assertNull($mapped[2]['summary_revision']);
        $this->assertEqualsWithDelta(0.7,$mapped[2]['distance'],0.000001);
        $names=['SYNTH_REQUEST_EVENT','SYNTH_PROTOCOL_REQUEST_CLAIMED','db'];
        $previous=[];
        foreach ($names as $name) if (array_key_exists($name,$GLOBALS)) $previous[$name]=$GLOBALS[$name];
        try {
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>['protocol_version'=>2]];
            $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=false;
            // No methods: any legacy SQL access would fail this test.
            $GLOBALS['db']=new stdClass();
            $this->assertFalse(storeMemory([],'native text',1));
            $this->assertFalse(deleteElement(1));
            $this->assertFalse(deleteElement(1,true));
            $this->assertSame(0,countMemories());
            try { synthDeleteLegacyRow($GLOBALS['db'],['table'=>'eventlog','rowid'=>1]); $this->fail('Native legacy delete accepted'); }
            catch (LogicException $error) { $this->assertSame('Native requests cannot delete legacy records.',$error->getMessage()); }
            unset($GLOBALS['SYNTH_REQUEST_EVENT']);
            $busy=new class { public function isTransactionIdle(): bool { return false; } };
            try { synthDeleteLegacyRow($busy,['table'=>'log','rowid'=>1]); $this->fail('Nested delete transaction accepted'); }
            catch (LogicException $error) { $this->assertSame('Row deletion requires an idle database connection.',$error->getMessage()); }
        } finally {
            foreach ($names as $name) {
                if (array_key_exists($name,$previous)) $GLOBALS[$name]=$previous[$name];
                else unset($GLOBALS[$name]);
            }
        }
    }

    public function testNativeSummaryCarriesOnlyItsFrozenPriorReference(): void
    {
        $input=['schema'=>'synth.memory.input.v1','events'=>[['data'=>'Current recorded dialogue']],
            'previous_summary'=>['id'=>7,'summary'=>'Earlier recorded memory']];
        $prompt=synth_memory_summary_prompt($input,['player'=>['display_name'=>'Saved Player']],'','');
        $this->assertCount(4,$prompt);
        $this->assertStringContainsString('for reference only',$prompt[1]['content']);
        $this->assertStringContainsString('Earlier recorded memory',$prompt[1]['content']);
        $this->assertStringContainsString('Current recorded dialogue',$prompt[2]['content']);
        $this->assertStringNotContainsString('No previous summary is supplied',$prompt[0]['content']);
        foreach ([null,[],['id'=>'7','summary'=>'invalid'],['id'=>7,'summary'=>''],
            ['id'=>7,'summary'=>str_repeat('x',65537)],['id'=>7,'summary'=>"\xFF"]] as $invalid) {
            try { synth_memory_summary_prompt(array_replace($input,['previous_summary'=>$invalid]),[],'',''); $this->fail('Invalid prior summary accepted'); }
            catch (InvalidArgumentException $error) { $this->assertSame('Invalid prior native memory input',$error->getMessage()); }
        }
    }

    public function testManagerDisconnectsStaticDatabaseBeforeForkAndDoesNotHideWorkerFailure(): void
    {
        $runner=file_get_contents(dirname(__DIR__,2).'/service/lib/task_runner.php');
        $this->assertLessThan(strpos($runner,'$pid = pcntl_fork();'),strpos($runner,'sql::disconnectBeforeFork();'));
        $this->assertStringContainsString('catch (Throwable $error)',$runner);
        $this->assertStringContainsString('exit(1);',$runner);
        $this->assertStringNotContainsString('getMessage()',$runner);
        $sql=file_get_contents(dirname(__DIR__,2).'/lib/postgresql.class.php');
        $method=explode('private function extract_caller()',explode('public static function disconnectBeforeFork()',$sql,2)[1],2)[0];
        $this->assertLessThan(strpos($method,'pg_close(self::$link)'),strpos($method,'PGSQL_TRANSACTION_IDLE'));
        $this->assertStringContainsString('self::$link = null;',$method);
        $manager=file_get_contents(dirname(__DIR__,2).'/service/manager.php');
        $this->assertStringContainsString('pcntl_get_last_error() === PCNTL_EINTR',$manager);
        $this->assertStringContainsString('exit($managerFailed ? 1 : 0);',$manager);
        $this->assertStringContainsString("if (PHP_SAPI !== 'cli')",$manager);
    }

    public function testNativeMemoryDispatchHonorsOptOutBeforeAnyDatabaseWork(): void
    {
        $bootstrap=file_get_contents(dirname(__DIR__,2).'/lib/runtime_bootstrap.php');
        $migration=file_get_contents(dirname(__DIR__,2).'/debug/db_updates.php');
        preg_match("/'synth_protocol_schema' => ([0-9]+)/",$bootstrap,$version);
        $this->assertNotEmpty($version[1]);
        $this->assertStringContainsString("\$updateVersion('synth_protocol_schema', ".$version[1].");",$migration);
        preg_match_all("/updateVersion\('synth_protocol_schema', ([0-9]+)\)/",$migration,$versions);
        $this->assertSame(max(array_map('intval',$versions[1])),intval($version[1]),'Bootstrap must require the newest protocol migration');
        $previous=$GLOBALS['FEATURES'] ?? null;
        try {
            foreach ([false,0,'false','OFF',' no '] as $disabled) {
                $GLOBALS['FEATURES']['MEMORY_EMBEDDING']['AUTO_CREATE_SUMMARYS']=$disabled;
                $this->assertNull(synth_memory_dispatch(new stdClass()));
            }
            $GLOBALS['FEATURES']['MEMORY_EMBEDDING']['AUTO_CREATE_SUMMARYS']=true;
            $db=new class { public function isTransactionIdle(): bool { return false; } };
            $this->expectException(LogicException::class);
            synth_memory_dispatch($db);
        } finally {
            if ($previous === null) unset($GLOBALS['FEATURES']);
            else $GLOBALS['FEATURES']=$previous;
        }
    }

    public function testNativeMemoryVectorRejectsMalformedAndNonfiniteComponents(): void
    {
        $vector=array_fill(0,384,0.25);
        $this->assertCount(384,json_decode(synth_memory_vector_json($vector),true));
        foreach ([[],[1,2],array_replace($vector,[0=>'0.25']),array_replace($vector,[0=>INF]),
            array_replace($vector,[0=>NAN]),array_replace($vector,[0=>1e39]),['named'=>0.2]+$vector] as $invalid) {
            try { synth_memory_vector_json($invalid); $this->fail('Invalid vector accepted'); }
            catch (InvalidArgumentException $error) { $this->assertStringStartsWith('Invalid memory vector',$error->getMessage()); }
        }
        $source=file_get_contents(dirname(__DIR__,2).'/lib/synth_memory_recall.php');
        foreach (['synth_memory_visible_batch_cte','pg_query_params','},2000);','LIMIT 50','synth_provider_fetch',"'timeout'=>10"] as $guard) {
            $this->assertStringContainsString($guard,$source);
        }
        $this->assertStringNotContainsString('FROM synth.memory_summary',$source);
        $this->assertStringContainsString('native_memory_batches AS MATERIALIZED',file_get_contents(dirname(__DIR__,2).'/lib/synth_memory.php'));
        $minime=file_get_contents(dirname(__DIR__,2).'/lib/minimet5_service.php');
        $this->assertStringContainsString("'detect:' : 'extract:'",$minime);
        $this->assertStringContainsString("hash('sha256',_minimeServiceBaseUrl()",$minime);
    }

    public function testOptionalMemoryEmbeddingKeepsTextButPropagatesCancellation(): void
    {
        $vector=array_fill(0,384,0.25);
        $this->assertNull(synth_memory_optional_embedding('accepted text',null));
        $seen=[];
        $this->assertSame($vector,synth_memory_optional_embedding('accepted text',static function($text) use (&$seen,$vector) {
            $seen[]=$text;return $vector;
        }));
        $this->assertSame(['accepted text'],$seen);
        foreach ([null,[],[1,2],'not an array',array_replace($vector,[0=>INF])] as $value) {
            $this->assertNull(synth_memory_optional_embedding('accepted text',static fn()=>$value));
        }
        foreach ([new RuntimeException('provider body must not escape'),new TypeError('invalid provider type')] as $error) {
            $this->assertNull(synth_memory_optional_embedding('accepted text',static function() use($error) {throw $error;}));
        }
        $cancelled=new SYNTHProtocolCancelled('memory_lease_lost');
        try {
            synth_memory_optional_embedding('accepted text',static function() use($cancelled) {throw $cancelled;});
            $this->fail('Cancellation became optional failure');
        } catch (SYNTHProtocolCancelled $error) {
            $this->assertSame($cancelled,$error);
        }
    }

    public function testNativeMemoryPromptUsesRecordedEvidenceAndConfiguredInstructions(): void
    {
        $input=['schema'=>'synth.memory.input.v1','events'=>[['data'=>'Recorded dialogue','location'=>'Sanctuary']]];
        $context=['player'=>['display_name'=>'Saved Player'],'target'=>['display_name'=>'Saved NPC']];
        $prompt=synth_memory_summary_prompt($input,$context,
            '{PLAYER_NAME} custom instruction {COMPANIONS_LINE} {SUMMARY_PROMPT}','Configured summary guidance');
        $this->assertCount(3,$prompt);
        $this->assertStringContainsString('Saved Player custom instruction',$prompt[0]['content']);
        $this->assertStringContainsString('Configured summary guidance',$prompt[0]['content']);
        $this->assertStringContainsString('No previous summary is supplied',$prompt[0]['content']);
        $this->assertStringContainsString('Recorded dialogue',$prompt[1]['content']);
        $this->assertStringContainsString('Sanctuary',$prompt[1]['content']);
        $this->assertStringNotContainsString('nearby characters',json_encode($prompt));
        $this->assertStringContainsString('#Tags:',$prompt[2]['content']);
        $this->assertStringContainsString("narrator's point of view",synth_memory_summary_prompt($input,$context,'','')[0]['content']);
        $this->expectException(InvalidArgumentException::class);
        synth_memory_summary_prompt(['schema'=>'synth.memory.input.v1','events'=>[]],$context,'','');
    }

    public function testMemoryProviderCannotRunUnderCallerTransaction(): void
    {
        $db = new class { public function isTransactionIdle(): bool { return false; } };
        $called = false;
        try {
            synth_memory_process_job($db,1,static function() use (&$called) { $called=true; return 'invalid'; });
            $this->fail('Expected transaction guard');
        } catch (LogicException $error) {
            $this->assertSame('Memory providers require an idle database connection',$error->getMessage());
        }
        $this->assertFalse($called);
        $source = file_get_contents(dirname(__DIR__,2).'/lib/synth_memory.php');
        foreach (['attempts<3',"INTERVAL '2 minutes'",'clock_timestamp()','lease_token=$5',"last_error='provider_failed'"] as $guard) {
            $this->assertStringContainsString($guard,$source);
        }
    }

    public function testNativeMemoryBatchKeepsSceneBoundariesAndWholeLines(): void
    {
        $row = ['history_event_id'=>1,'type'=>'chat','data'=>'first','gamets'=>10,'source_location'=>'Sanctuary'];
        $rows = [$row, array_replace($row,['history_event_id'=>2,'data'=>'second','gamets'=>11])];
        $batch = synth_memory_build_batch($rows,2,1000);
        $this->assertSame("first\n\nsecond",$batch['packed_message']);
        $this->assertSame([1,2],array_column($batch['input_payload']['events'],'history_event_id'));
        $this->assertSame($batch,synth_memory_build_batch($rows,2,1000));
        $this->assertNull(synth_memory_build_batch([$row],2,1000));
        $this->assertNull(synth_memory_build_batch([$row,array_replace($row,['source_location'=>'Concord'])],2,1000));
        $this->assertNull(synth_memory_build_batch([$row,array_replace($row,['gamets'=>1500])],2,1000));
        $this->assertNull(synth_memory_build_batch(array_map(static fn($r)=>array_replace($r,['source_location'=>'']),$rows),2,1000));
        $this->assertSame($batch,synth_memory_build_batch([array_replace($row,['source_location'=>'Earlier']),...$rows],2,1000));
        $this->assertNull(synth_memory_build_batch([array_replace($row,['data'=>str_repeat('x',65537)])],1,1000));
        $this->assertSame('first',synth_memory_build_batch([$row,array_replace($row,['data'=>str_repeat('x',65536)])],1,1000)['packed_message']);
        $this->assertCount(150,synth_memory_build_batch(array_fill(0,151,$row),1,1000)['input_payload']['events']);
        $source = file_get_contents(dirname(__DIR__,2).'/lib/synth_memory.php');
        $this->assertStringContainsString('withNativeRequestLock',$source);
        $this->assertStringContainsString('synth_memory_visible_batch_cte($db, $event)',$source);
        $this->assertStringContainsString('pg_query_params',$source);
        $this->assertStringNotContainsString('FROM memory_v',$source);
        $this->assertStringContainsString('memory_next_group',$source);
        $this->assertStringContainsString('FROM native_memory_evidence_batches b JOIN synth_meta.memory_batch_sources',$source);
        $this->assertStringContainsString("hashtextextended('synth-memory:'",$source);
        $this->assertStringContainsString('}, 2000);',$source);
        $migration = file_get_contents(dirname(__DIR__,2).'/debug/db_updates.php');
        $timestampCheck = explode('$forceRecreate = false;',explode('$checkFunctionExists = function', $migration,2)[1],2)[0];
        $this->assertStringContainsString("routine_schema = 'synth'",$timestampCheck);
        $this->assertStringNotContainsString("routine_schema = 'public'",$timestampCheck);
    }

    public function testNativeRecallKeywordsUseOwnedHistoryInsteadOfGlobalSpeech(): void
    {
        $source = file_get_contents(dirname(__DIR__,2).'/lib/chat_helper_functions.php');
        $names = explode('function lastSpeech(', explode('function lastNames(', $source, 2)[1], 2)[0];
        $keywords = explode('function lastKeyWordsNew(', explode('function lastKeyWordsContext(', $source, 2)[1], 2)[0];
        foreach ([$names,$keywords] as $reader) {
            $this->assertStringContainsString('synth_protocol_history_cte($db, $nativeEvent', $reader);
            $this->assertStringContainsString("synthBuildChatDeliveryStateSql('delivery_state')", $reader);
            $this->assertStringContainsString('ORDER BY gamets DESC,ts DESC,rowid DESC', $reader);
            $this->assertStringContainsString('max(1, min(150, intval($m)))', $reader);
        }
        $this->assertStringContainsString('speaker_identity_id IN (reader_identity_id,reader_player_id)', $keywords);
        $this->assertStringContainsString('synth_protocol_history_cte($db, $nativeEvent, $npcname)', $keywords);
        $this->assertStringContainsString("\$types === '' ? []", $names);
    }

    public function testNativeHistoryUsesBoundedImmutableAncestryAndLegacyDeliveryCannotMutateIt(): void
    {
        $db = new class { public function escapeLiteral(string $v): string { return "'".str_replace("'","''",$v)."'"; } };
        $query = synth_protocol_history_cte($db,['protocol_version'=>2,'session_id'=>'current',
            'runtime_variant'=>'flat','generation'=>3,'context_sequence'=>7]);
        foreach (["c.session_id='current'",'c.generation=3','c.snapshot_sequence=7',"r.state='active'",
            'r.active_generation=c.generation',"r.runtime_variant='flat'",'p.playthrough_id=n.playthrough_id',
            'n.depth<64','ANY(n.path)','MIN(history_event_id)','h.id<=s.cutoff','jsonb_to_record(h.event_payload)'] as $guard) {
            $this->assertStringContainsString($guard,$query);
        }
        $this->assertStringNotContainsString('FROM synth.eventlog',$query);
        $source = file_get_contents(dirname(__DIR__,2).'/lib/data_functions.php');
        $history = explode('function buildHistoricContext(', $source, 2)[1];
        $this->assertStringContainsString("\$historySource = \$nativeHistory ? 'native_history' : 'eventlog'",$history);
        $this->assertStringContainsString('synth_protocol_history_cte($db, $nativeEvent, $actor, responseRoles:true)',$history);
        foreach (['c.target_identity_id IS NOT NULL','origin.snapshot_sequence=COALESCE(h.scene_context_sequence,h.context_sequence)',
            'origin.playthrough_id=h.playthrough_id','COUNT(DISTINCT id)=1 AND MIN(id)=s.reader_identity_id',
            'WHERE ownership.visible','speaker_identity_id'] as $guard) {
            $this->assertStringContainsString($guard,$query);
        }
        $this->assertStringContainsString('($b_actor && !$nativeHistory)',$history);
        $telemetry = file_get_contents(dirname(__DIR__,2).'/gamedata.php');
        $this->assertStringContainsString("synth_session_id IS NULL AND COALESCE(delivery_state",$telemetry);
        $legacy = file_get_contents(dirname(__DIR__,2).'/processor/comm.php');
        $this->assertSame(3,substr_count($legacy,'synth_session_id IS NULL'));
    }

    public function testNativeHistoryInsertionUsesClaimLockAndAtomicEvidenceWrite(): void
    {
        $source = file_get_contents(dirname(__DIR__,2).'/lib/postgresql.class.php');
        $owned = explode('private function insertRow', explode('public function insert($table, $data)', $source, 2)[1], 2)[0];
        $this->assertStringContainsString("['eventlog', 'synth.eventlog']", $owned);
        $this->assertStringContainsString("SYNTH_PROTOCOL_REQUEST_CLAIMED", $owned);
        $this->assertStringContainsString("BEGIN ISOLATION LEVEL READ COMMITTED", $owned);
        $this->assertStringContainsString("SHOW transaction_isolation", $owned);
        $this->assertStringContainsString("SET LOCAL lock_timeout='2s'", $owned);
        $this->assertLessThan(strpos($owned, 'synth_protocol_assert_request_active()'), strpos($owned, 'pg_advisory_xact_lock'));
        $lock = explode('public function withNativeRequestLock', $owned, 2)[1];
        $this->assertLessThan(strpos($lock, '$operation(self::$link, $event)'), strpos($lock, 'synth_protocol_assert_request_active()'));
        $this->assertStringContainsString('return $this->withNativeRequestLock(', $owned);
        $this->assertStringContainsString("unset(\$data['rowid'])", $owned);
        $this->assertStringContainsString('INSERT INTO synth_meta.history_events', $owned);
        $this->assertStringContainsString('to_jsonb(inserted)', $owned);
        $this->assertStringContainsString('finally', $owned);
        $this->assertStringContainsString('ROLLBACK', $owned);
        $schema = file_get_contents(dirname(__DIR__,2).'/lib/core/database_schema/synth_protocol.sql');
        $this->assertStringContainsString('FOREIGN KEY (session_id, generation, context_sequence)', $schema);
        $this->assertStringNotContainsString('UPDATE synth.eventlog', $schema);
    }

    public function testSavedContextResolutionIsExactAndNeverInfersHistory(): void
    {
        $event = json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/context-saved-anchor.json'), true);
        $db = new class {
            public array $queries = [];
            public ?array $row = ['context_payload'=>'{"world":{"location":"Saved Sanctuary"}}'];
            public function escapeLiteral(string $value): string { return "'".str_replace("'", "''", $value)."'"; }
            public function fetchOne(string $sql): ?array { $this->queries[] = $sql; return $this->row; }
        };
        $this->assertSame($db->row, synth_protocol_loaded_context($db, $event));
        $sql = $db->queries[0];
        foreach (["c.session_id='session:previous'", 'c.generation=6', 'c.snapshot_sequence=42',
                  "c.playthrough_id='save-001'", "r.runtime_variant='flat'"] as $filter) {
            $this->assertStringContainsString($filter, $sql);
        }
        $this->assertStringNotContainsString('ORDER BY', $sql);
        $this->assertStringNotContainsString('active_generation', $sql); // Old, halted sessions remain valid observation sources.
        $db->row = null;
        $this->assertNull(synth_protocol_loaded_context($db, $event));
        $this->assertCount(2, $db->queries); // No latest/global fallback query.
        $db->queries = [];
        $unbound = $event; unset($unbound['payload']['loaded_context']);
        $this->assertNull(synth_protocol_loaded_context($db, $unbound));
        $unbound = $event; $unbound['protocol_version'] = 1;
        $this->assertNull(synth_protocol_loaded_context($db, $unbound));
        $unbound = $event; $unbound['capabilities'] = [];
        $this->assertNull(synth_protocol_loaded_context($db, $unbound));
        $unbound = $event; $unbound['payload']['loaded_context']['playthrough_id'] = 'other';
        $this->assertNull(synth_protocol_loaded_context($db, $unbound));
        $this->assertSame([], $db->queries);
    }

    public function testLoadedContextCannotChangeWithinRuntimeGeneration(): void
    {
        $event = json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/context-saved-anchor.json'), true);
        $db = new class {
            public function escapeLiteral(string $value): string { return "'".$value."'"; }
            public function fetchOne(string $sql): array { return ['latest'=>6, 'playthrough_id'=>'save-001', 'loaded_context'=>null]; }
            public function execQuery(string $sql): never { throw new LogicException('Mismatch must not write'); }
        };
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('context_loaded_anchor_mismatch');
        synth_protocol_persist_context($db, $event, $event['session_id']);
    }

    public function testRetirementCanClaimAnUninitializedSessionWithoutStartingProviders(): void
    {
        $db = new class {
            public array $writes = [];
            public function escapeLiteral(string $value): string { return "'".str_replace("'", "''", $value)."'"; }
            public function fetchOne(string $sql): ?array { return null; }
            public function execQuery(string $sql): bool { $this->writes[]=$sql; return true; }
        };
        $event = ['protocol_version'=>2,'session_id'=>'session:retired','request_id'=>'req:halt',
            'turn_id'=>'turn:halt','generation'=>2,'runtime_variant'=>'flat','context_sequence'=>0,
            'client_version'=>'test','runtime_version'=>'1.11.240','type'=>'halt',
            'capabilities'=>['runtime.retirement'],'payload'=>new stdClass()];
        $this->assertSame('accepted',synth_protocol_claim_request($db,$event)['status']);
        $sql = implode("\n",$db->writes);
        $this->assertStringContainsString('INSERT INTO synth_meta.runtime_sessions',$sql);
        $this->assertStringContainsString("SET state='halted'",$sql);
        $this->assertStringContainsString("WHERE session_id='session:retired'",$sql);
        $this->assertSame('COMMIT',end($db->writes));
    }

    public function testNativeTimestampsCannotAuthorizeLegacyGlobalPruning(): void
    {
        require_once dirname(__DIR__,2).'/lib/save_rollback.php';
        $previous = $GLOBALS['SYNTH_REQUEST_EVENT'] ?? null;
        $previousDb = $GLOBALS['db'] ?? null;
        $GLOBALS['db'] = new class {
            public function __call($name,$args): never { throw new LogicException('No database operation is authorized'); }
        };
        try {
            foreach ([1,2] as $version) {
                $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json'=>['protocol_version'=>$version]];
                foreach ([false,true] as $force) {
                    $this->assertSame('scoped_timeline_required',synthRollbackPruneFutureData(1,'init',$force)['reason']);
                    $this->assertSame('scoped_timeline_required',synthMaybeHandleIncomingGametsRollback(1,'init',$force)['reason']);
                }
            }
            foreach (['main_synth_pipeline.php','gamedata.php'] as $path) {
                $this->assertStringNotContainsString('synthMaybeHandleIncomingGametsRollback(',file_get_contents(dirname(__DIR__,2).'/'.$path));
            }
            $this->assertStringContainsString('$db->delete(\'quests\', "sess=\'synth_active_quest\'")',file_get_contents(dirname(__DIR__,2).'/gamedata.php'));
        } finally {
            $GLOBALS['SYNTH_REQUEST_EVENT'] = $previous;
            $GLOBALS['db'] = $previousDb;
        }
    }

    public function testTurnCancelUsesNegotiatedContextAnchorAndExactScope(): void
    {
        foreach ([false, true] as $negotiated) foreach (['context','input_text','trigger','action_result','manual','automatic','legacy','unowned','zero'] as $kind) {
            $anchorEvent=['protocol_version'=>$kind==='legacy'?1:2,'type'=>in_array($kind,['manual','automatic'],true)?'activate':$kind,
                'capabilities'=>['dialogue.turn_ownership'],'context_sequence'=>$kind==='zero'?0:7,'payload'=>['snapshot_sequence'=>7]];
            if (in_array($kind,['zero','legacy','unowned'],true)) $anchorEvent['type']='input_text';
            if ($kind==='unowned') $anchorEvent['capabilities']=[];
            if (in_array($kind,['manual','automatic'],true)) $anchorEvent['payload']['source']=$kind;
            $anchored=$negotiated && in_array($kind,['context','input_text','trigger','action_result','manual'],true);
            $caps = ['dialogue.turn_ownership'];
            if ($negotiated) $caps[] = 'dialogue.turn_cancel';
            $db = new class($caps,$anchorEvent) {
                public array $queries = [];
                public function __construct(private array $caps,private array $anchorEvent) {}
                public function escapeLiteral(string $value): string { return "'".str_replace("'", "''", $value)."'"; }
                public function execQuery(string $sql): bool { $this->queries[]=$sql; return true; }
                public function fetchOne(string $sql): ?array {
                    $this->queries[]=$sql;
                    if (str_contains($sql, 'pg_advisory_xact_lock')) return ['locked'=>true];
                    if (str_contains($sql, 'FROM synth_meta.runtime_sessions r')) return [
                        'active_generation'=>2,'state'=>'active','playthrough_id'=>'save:one',
                        'runtime_variant'=>'flat','protocol_version'=>2,'capabilities'=>$this->caps];
                    if (str_contains($sql, 'SELECT turn_id, canonical_event')) return [
                        'turn_id'=>'turn:old','canonical_event'=>json_encode($this->anchorEvent,JSON_THROW_ON_ERROR)];
                    return null;
                }
            };
            $event = ['protocol_version'=>2,'session_id'=>'session:one','request_id'=>'req:cancel',
                'turn_id'=>'turn:cancel','generation'=>2,'runtime_variant'=>'flat','context_sequence'=>0,
                'type'=>'cancel','capabilities'=>$caps,'payload'=>['cancel_request_id'=>'req:context']];
            $this->assertSame('accepted', synth_protocol_claim_request($db,$event)['status']);
            $sql = implode("\n",$db->queries);
            $this->assertStringContainsString("session_id='session:one' AND request_id='req:context'",$sql);
            if ($anchored) {
                $this->assertStringContainsString("active_turn_id='turn:old' AND active_turn_sequence=7",$sql);
                $this->assertStringContainsString("canonical_event->>'context_sequence'='7'",$sql);
                $this->assertStringContainsString("COALESCE(payload->'protocol_event'->>'context_sequence',payload->>'context_sequence')='7'",$sql);
            } else {
                $this->assertStringNotContainsString('active_turn_id=NULL',$sql);
                if (!$negotiated) $this->assertStringNotContainsString('SELECT turn_id, canonical_event',$sql);
            }
        }
    }

    public function testProviderTransportPreservesResourceTestDoublesAndConnectorWiring(): void
    {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, "first\nsecond\n");
        rewind($stream);
        $this->assertSame("first\n", synth_provider_line($stream));
        $this->assertFalse(synth_provider_eof($stream));
        $this->assertIsArray(synth_provider_metadata($stream));
        $this->assertSame("second\n", synth_provider_contents($stream));
        $this->assertTrue(synth_provider_eof($stream));
        synth_provider_close($stream);
        $this->assertFalse(is_resource($stream));
        foreach (['openrouterjson','openaijson','google_openaijson','groqjson','player2json','koboldcppjson'] as $driver) {
            $this->assertStringContainsString("'".$driver."'",file_get_contents(dirname(__DIR__,2).'/lib/synth_memory_worker.php'));
            $source = file_get_contents(dirname(__DIR__,2).'/connector/'.$driver.'.php');
            $this->assertStringContainsString('synth_provider_open(', $source);
            $this->assertStringContainsString('synth_provider_line($this->primary_handler)', $source);
            $this->assertStringContainsString('synth_provider_close($this->primary_handler)', $source);
            $this->assertStringNotContainsString('fgets($this->primary_handler)', $source);
            $this->assertStringNotContainsString('fsockopen(', $source);
        }
    }

    public function testDurableTurnGuardAndTerminalCancellationAreScoped(): void
    {
        $db = new class {
            public array $writes = [];
            public array $row = ['state' => 'claimed', 'runtime_state' => 'active',
                'active_generation' => 2, 'active_turn_id' => 'turn:one', 'active_turn_sequence' => 7];
            public function escapeLiteral(string $value): string { return "'".str_replace("'", "''", $value)."'"; }
            public function fetchOne(string $sql): array { return $this->row; }
            public function execQuery(string $sql): bool { $this->writes[] = $sql; return true; }
        };
        $previous = [];
        foreach (['db','SYNTH_REQUEST_EVENT','SYNTH_PROTOCOL_REQUEST_CLAIMED'] as $key)
            $previous[$key] = $GLOBALS[$key] ?? null;
        $event = ['session_id' => 'session:one', 'request_id' => 'request:one', 'turn_id' => 'turn:one',
            'generation' => 2, 'runtime_variant' => 'flat', 'protocol_version' => 2, 'context_sequence' => 7,
            'capabilities' => ['dialogue.turn_ownership'], 'type' => 'input_text'];
        try {
            $GLOBALS['db'] = $db;
            $GLOBALS['SYNTH_REQUEST_EVENT'] = $event + ['json' => $event];
            $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'] = true;
            $this->assertTrue(synth_protocol_owns_dialogue_turn($event));
            synth_protocol_assert_request_active();
            foreach (['state' => 'cancelled', 'runtime_state' => 'halted',
                      'active_generation' => 3, 'active_turn_id' => 'turn:new', 'active_turn_sequence' => 8] as $key => $value) {
                $old = $db->row[$key];
                $db->row[$key] = $value;
                try { synth_protocol_assert_request_active(); $this->fail('Stale work was accepted'); }
                catch (SYNTHProtocolCancelled $e) { $this->assertSame('request_cancelled', $e->getMessage()); }
                try { synth_speech_fetch('invalid://no-network'); $this->fail('Stale speech opened'); }
                catch (SYNTHProtocolCancelled $e) { $this->assertSame('request_cancelled', $e->getMessage()); }
                require_once dirname(__DIR__,2).'/lib/speech_cache.php';
                try { synth_speech_cache_valid('invalid://no-cache'); $this->fail('Stale speech cache read'); }
                catch (SYNTHProtocolCancelled $e) { $this->assertSame('request_cancelled', $e->getMessage()); }
                if (function_exists('curl_init')) {
                    require_once dirname(__DIR__,2).'/lib/voice_clone_sync.php';
                    try { synth_voice_clone_sync_http_json('invalid://no-network'); $this->fail('Stale clone probe opened'); }
                    catch (SYNTHProtocolCancelled $e) { $this->assertSame('request_cancelled', $e->getMessage()); }
                    try { synth_voice_clone_sync_upload('invalid://no-network', 'unit', __FILE__); $this->fail('Stale clone upload opened'); }
                    catch (SYNTHProtocolCancelled $e) { $this->assertSame('request_cancelled', $e->getMessage()); }
                    $handle = curl_init('invalid://no-network');
                    try { synth_speech_curl_exec($handle); $this->fail('Stale curl speech opened'); }
                    catch (SYNTHProtocolCancelled $e) { $this->assertSame('request_cancelled', $e->getMessage()); }
                    finally { curl_close($handle); }
                }
                // Invalid URL proves a stale owner is rejected before transport setup or network I/O.
                try { synth_provider_open('invalid://no-network', stream_context_create()); $this->fail('Stale provider opened'); }
                catch (SYNTHProtocolCancelled $e) { $this->assertSame('request_cancelled', $e->getMessage()); }
                $db->row[$key] = $old;
            }
            if (function_exists('curl_init')) {
                $handle = curl_init('invalid://no-network');
                curl_setopt($handle,CURLOPT_RETURNTRANSFER,true);
                try {
                    $this->assertFalse(synth_speech_curl_exec($handle,100,$diagnostic));
                    $this->assertSame(CURLE_UNSUPPORTED_PROTOCOL,$diagnostic['errno']);
                    $this->assertNotSame('',$diagnostic['error']);
                } finally { curl_close($handle); }
            }
            synth_protocol_complete_request($db, 'complete', 'application/x-ndjson', 'late reply');
            $this->assertStringContainsString("session_id='session:one' AND request_id='request:one'", end($db->writes));
            $this->assertStringContainsString("state IN ('claimed','streaming')", end($db->writes));
            $event['capabilities'] = [];
            $this->assertFalse(synth_protocol_owns_dialogue_turn($event));
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $value;
            }
        }
    }

    public function testSpeechDriversUseCancellableTransportAndKeepLegacyFetchHeaders(): void
    {
        $root = dirname(__DIR__,2);
        $pipeline = file_get_contents($root.'/main_synth_pipeline.php');
        $this->assertStringContainsString('if ($error instanceof SYNTHProtocolCancelled) throw $error;', $pipeline);
        foreach (['xtts-fastapi','chatterbox','pockettts','cartesia','inworld'] as $driver) {
            $this->assertStringContainsString('synth_speech_cache_valid(',file_get_contents($root.'/tts/tts-'.$driver.'.php'));
        }
        foreach (array_merge(glob($root.'/stt/*.php'),glob($root.'/tts/*.php')) as $path) {
            $source = file_get_contents($path);
            if (!str_contains($source,'synth_speech_')) continue;
            $this->assertStringContainsString("'/lib/provider_stream.php'",$source,$path);
            $this->assertDoesNotMatchRegularExpression('/(?<![a-z_])curl_exec\\(/',$source,$path);
            $this->assertDoesNotMatchRegularExpression('/file_get_contents\\([^\\n]+, false,/',$source,$path);
        }
        $previous = $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'] ?? null;
        try {
            $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'] = false;
            $headers = ['stale header'];
            $this->assertSame("synthetic\0audio",synth_speech_fetch('data:application/octet-stream;base64,c3ludGhldGljAGF1ZGlv',null,$headers));
            $this->assertSame([],$headers);
        } finally {
            if ($previous === null) unset($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']);
            else $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'] = $previous;
        }
    }

    public function testDurableSpeechCorruptCacheReachesGenerationWithoutRemovingEvidence(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_tts_worker.php';
        $root=sys_get_temp_dir().'/synth-cache-retry-'.bin2hex(random_bytes(8));
        mkdir($root.'/soundcache',0700,true);
        $key=md5('synthetic corrupt speech');
        $path=$root.'/soundcache/'.$key.'.wav';
        $bytes=str_repeat('not audio',100);
        $claimed=$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'] ?? null;
        try {
            $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=false;
            file_put_contents($path,$bytes);
            // A deliberately invalid plan fails before provider I/O, proving the worker attempts regeneration.
            try {
                synth_tts_generate_job_audio(['cache_key'=>$key,'tts_plan'=>[],
                    'text'=>'synthetic','mood'=>'normal','cache_seed'=>'synthetic'],$root);
                $this->fail('Corrupt cache suppressed synthesis');
            } catch (RuntimeException $error) {
                $this->assertSame('Queued speech content does not match its plan',$error->getMessage());
            }
            $this->assertSame($bytes,file_get_contents($path));
        } finally {
            unlink($path);
            $lock=$root.'/data/tmp/npc_tts_jobs/'.$key.'.media-lock';
            if (is_file($lock)) unlink($lock);
            foreach (['data/tmp/npc_tts_jobs','data/tmp','data','soundcache',''] as $directory) {
                if (is_dir($root.'/'.$directory)) rmdir($root.'/'.$directory);
            }
            if ($claimed===null) unset($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']);
            else $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=$claimed;
        }
    }

    public function testV2FixturesAndV1RequestsUseSeparateClosedContracts(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (glob($root.'/protocol/v2/fixtures/valid/*.json') as $file) {
            $event = synth_protocol_decode_json(file_get_contents($file));
            if (($event['schema'] ?? '') === 'synth.visual_context.capture.v2') {
                require_once $root.'/lib/visual_context_request.php';
                $this->assertSame($event, synth_visual_validate_request($event), $file);
                continue;
            }
            $this->assertSame($event, synth_protocol_validate_event($event), $file);
        }
        $event = json_decode(file_get_contents($root.'/protocol/v1/fixtures/valid/input-text.json'), true);
        $this->assertSame($event, synth_protocol_validate_event($event));
        foreach ([...glob($root.'/protocol/v2/fixtures/invalid/*.json'),
            $root.'/protocol/v1/fixtures/invalid/init-player-caps.json'] as $file) {
            // Ingress exits after returning its error envelope, so exercise it in a child PHP process.
            $script = 'require '.var_export($root.'/lib/request.php', true).'; '
                .'synth_protocol_validate_event(synth_protocol_decode_json(file_get_contents('
                .var_export($file, true).'))); echo "unexpected acceptance";';
            $output = shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script));
            $error = json_decode(strval($output), true, flags: JSON_THROW_ON_ERROR);
            $this->assertFalse($error['ok'], $file);
            $this->assertNotEmpty($error['code'], $file);
        }
    }

    public function testV2ContextNeverFallsBackToLatestOrMutableActorNames(): void
    {
        $db = new class {
            public array $queries = [];
            public array $payload = [
                'player' => ['form_id' => '0x00000014', 'origin_plugin' => 'Fallout4.esm',
                    'playthrough_id' => 'save:one', 'display_name' => 'Sole Survivor'],
                'target' => ['form_id' => '0x0001A4D7', 'origin_plugin' => 'Fallout4.esm',
                    'playthrough_id' => 'save:one', 'display_name' => 'Preston Garvey'],
                'audience' => [], 'world' => ['location' => 'Old Sanctuary'],
            ];
            public function escapeLiteral(string $value): string { return "'".str_replace("'", "''", $value)."'"; }
            public function fetchOne(string $sql): ?array
            {
                $this->queries[] = $sql;
                return str_contains($sql, "c.session_id='session:one'")
                    && str_contains($sql, 'c.generation=2') && str_contains($sql, 'c.snapshot_sequence=7')
                    ? ['context_payload' => json_encode($this->payload)] : null;
            }
        };
        $event = ['protocol_version' => 2, 'session_id' => 'session:one', 'generation' => 2,
            'context_sequence' => 7, 'type' => 'input_text', 'payload' => ['text' => 'Hello']];
        $request = ['input_text', 1, 2, ''];
        $this->assertSame('Hello', synth_protocol_bind_dialogue_context($db, $event, $request));
        $this->assertSame('Hello', synth_protocol_bind_dialogue_context($db,
            array_replace($event, ['type' => 'input_audio']), $request));
        $this->assertSame('', synth_protocol_bind_dialogue_context($db,
            array_replace($event, ['type' => 'trigger']), $request));
        $bound = json_decode($request[3], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Preston Garvey', $bound['npc']);
        $this->assertSame('Old Sanctuary', $bound['world']['location']);
        foreach (['context_sequence' => 8, 'generation' => 3, 'session_id' => 'session:other'] as $key => $value) {
            $this->assertNull(synth_protocol_current_context($db, array_replace($event, [$key => $value])));
            try {
                synth_protocol_bind_dialogue_context($db, array_replace($event, [$key => $value]), $request);
                $this->fail('A stale request must not supply prompt selection text');
            } catch (RuntimeException $error) {
                $this->assertSame('dialogue_target_required', $error->getMessage());
            }
        }
        $this->assertNull(synth_protocol_current_context($db, array_replace($event, ['context_sequence' => 0])));
        foreach ($db->queries as $sql) {
            $this->assertStringNotContainsString('ORDER BY', $sql);
            $this->assertStringNotContainsString('actor_identities', $sql);
            $this->assertStringContainsString('r.playthrough_id=c.playthrough_id', $sql);
            $this->assertStringContainsString("r.state='active'", $sql);
        }
        $previous = $GLOBALS['SYNTH_REQUEST_EVENT'] ?? null;
        try {
            $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json' => $event];
            $this->assertSame($db->payload['target'], synth_protocol_resolve_speaker_identity($db, 'Preston Garvey'));
            $other = $db->payload['target'];
            $other['form_id'] = '0xFF000001';
            $db->payload['audience'] = [$other];
            $this->assertSame($db->payload['target'], synth_protocol_resolve_speaker_identity($db, 'Preston Garvey'),
                'The exact captured target remains the response owner when another NPC has the same name');
            $db->payload['target']['display_name']='Target';
            $db->payload['audience'][]=array_replace($other,['form_id'=>'0xFF000002']);
            $this->assertNull(synth_protocol_resolve_speaker_identity($db, 'Preston Garvey'),
                'An ambiguous non-owner name still cannot select another actor');
            $this->assertNull(synth_protocol_resolve_speaker_identity($db, 'Unobserved Actor'));
        } finally {
            $GLOBALS['SYNTH_REQUEST_EVENT'] = $previous;
        }
    }

    public function testV2ActionResultCannotSwitchItsOriginatingSnapshot(): void
    {
        $db = new class {
            public function escapeLiteral(string $value): string { return "'".str_replace("'", "''", $value)."'"; }
            public function fetchOne(string $sql): array
            {
                return str_contains($sql, 'canonical_event')
                    ? ['protocol_version' => '2', 'context_sequence' => '7']
                    : ['action_id' => 'action:one', 'idempotency_key' => 'idem:one', 'state' => 'pending'];
            }
            public function execQuery(string $sql): never { throw new LogicException('No write is permitted for this mismatch'); }
        };
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('action_context_mismatch');
        synth_protocol_persist_action_result($db, ['protocol_version' => 2, 'generation' => 2,
            'context_sequence' => 8, 'payload' => ['result' => [
                'action_id' => 'action:one', 'idempotency_key' => 'idem:one',
            ]]], 'session:one');
    }

    public function testInventoryReceiptRequiresExactIntentAndImmutableTerminalResult(): void
    {
        $event=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/action-inventory.json'),true);
        $parent=$event;$parent['type']='input_text';$parent['request_id']='parent:one';$parent['payload']=['text'=>'Equip rifle'];
        $db=new class {
            public array $owner=[];
            public ?array $terminal=null;
            public array $writes=[];
            public function escapeLiteral(string $value): string {return "'".str_replace("'","''",$value)."'";}
            public function fetchOne(string $sql): ?array {
                if (str_contains($sql,'action_terminal_results')) return $this->terminal;
                if (str_contains($sql,'JOIN synth.actor_identities')) return $this->owner;
                if (str_contains($sql,'canonical_event')) return ['protocol_version'=>'2','context_sequence'=>'7'];
                return ['action_id'=>'action-1','idempotency_key'=>'idem-action-1','state'=>'pending'];
            }
            public function execQuery(string $sql): bool {$this->writes[]=$sql;return true;}
        };
        $db->owner=['turn_id'=>$event['turn_id'],'request_id'=>$parent['request_id'],'action_name'=>'EquipItem',
            'capability'=>'action.equip_item','full_form_id'=>'0x0001A4D7','origin_plugin'=>'Fallout4.esm',
            'playthrough_id'=>'save:fo4:001','canonical_event'=>$parent];
        synth_protocol_persist_action_result($db,$event,$event['session_id']);
        $this->assertCount(2,$db->writes);
        $this->assertStringContainsString('"inventory"',$db->writes[0]);
        $db->terminal=['result'=>json_encode($event['payload']['result'])];$db->writes=[];
        synth_protocol_persist_action_result($db,$event,$event['session_id']);
        $this->assertSame([],$db->writes,'Identical observations are idempotent');
        $changed=$event;$changed['payload']['result']['inventory']['items'][0]['equipped']=false;
        try {synth_protocol_persist_action_result($db,$changed,$event['session_id']);$this->fail('Conflicting receipt accepted');}
        catch(DomainException $error) {$this->assertSame('action_result_conflict',$error->getMessage());}
        $original=$db->owner;
        foreach (['actor','plugin','playthrough','turn','request','parent-turn','parent-generation','parent-sequence',
            'parent-lane','parent-capability','receipt-capability','wrong-action','wrong-action-capability','missing-owner'] as $case) {
            $db->owner=$original;$value=$event;
            if ($case==='actor') $value['payload']['result']['inventory']['actor']['form_id']='0xFF000001';
            if ($case==='plugin') $value['payload']['result']['inventory']['actor']['origin_plugin']='Other.esp';
            if ($case==='playthrough') $value['payload']['result']['inventory']['actor']['playthrough_id']='other:save';
            if ($case==='turn') $value['turn_id']='other:turn';
            if ($case==='request') $value['request_id']=$parent['request_id'];
            if ($case==='parent-turn') $db->owner['canonical_event']['turn_id']='other:turn';
            if ($case==='parent-generation') $db->owner['canonical_event']['generation']=99;
            if ($case==='parent-sequence') $db->owner['canonical_event']['context_sequence']=8;
            if ($case==='parent-lane') $db->owner['canonical_event']['runtime_variant']='vr';
            if ($case==='parent-capability') $db->owner['canonical_event']['capabilities']=[];
            if ($case==='receipt-capability') $value['capabilities']=[];
            if ($case==='wrong-action') $db->owner['action_name']='CheckInventory';
            if ($case==='wrong-action-capability') $db->owner['capability']='action.check_inventory';
            if ($case==='missing-owner') $db->owner=[];
            try {synth_protocol_persist_action_result($db,$value,$event['session_id']);$this->fail('Invalid owner accepted: '.$case);}
            catch(DomainException $error) {$this->assertStringStartsWith('action_inventory_',$error->getMessage(),$case);}
            $this->assertSame([],$db->writes,$case);
        }
        $consume=$event;$consume['capabilities']=array_merge($consume['capabilities'],['action.consume','action.consume_inventory']);
        $consume['payload']['result']['inventory']['items']=[];
        $db->owner=$original;$db->owner['action_name']='Consume';$db->owner['capability']='action.consume';
        $db->owner['canonical_event']['capabilities']=$consume['capabilities'];$db->terminal=null;
        synth_protocol_persist_action_result($db,$consume,$consume['session_id']);
        $this->assertCount(2,$db->writes);
        $db->writes=[];$db->terminal=['result'=>$consume['payload']['result']];
        synth_protocol_persist_action_result($db,$consume,$consume['session_id']);$this->assertSame([],$db->writes);
        $consumeOwner=$db->owner;
        foreach (['parent-marker','receipt-marker','parent-action','receipt-action','vr','conflict'] as $case) {
            $db->owner=$consumeOwner;$value=$consume;
            if($case==='parent-marker') array_pop($db->owner['canonical_event']['capabilities']);
            if($case==='receipt-marker') array_pop($value['capabilities']);
            if($case==='parent-action') $db->owner['canonical_event']['capabilities']=array_values(array_diff($value['capabilities'],['action.consume']));
            if($case==='receipt-action') $value['capabilities']=array_values(array_diff($value['capabilities'],['action.consume']));
            if($case==='vr') {$value['runtime_variant']='vr';$db->owner['canonical_event']['runtime_variant']='vr';}
            if($case==='conflict') $value['payload']['result']['inventory']['observation']='unavailable';
            try{synth_protocol_persist_action_result($db,$value,$value['session_id']);$this->fail('Invalid Consume receipt accepted');}
            catch(DomainException $error){$this->assertSame($case==='conflict'?'action_result_conflict':'action_inventory_capability_mismatch',$error->getMessage());}
            $this->assertSame([],$db->writes);
        }
    }

    public function testEmptyActionArgumentsPersistAsAnObject(): void
    {
        $db = new class {
            public array $writes = [];
            public function escapeLiteral(string $value): string { return "'".str_replace("'", "''", $value)."'"; }
            public function fetchOne(string $sql): array { return ['id' => 1]; }
            public function execQuery(string $sql): bool { $this->writes[] = $sql; return true; }
        };
        $previous = $GLOBALS['SYNTH_REQUEST_EVENT'] ?? null;
        try {
            $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json' => ['session_id' => 'session:one',
                'request_id' => 'request:one', 'turn_id' => 'turn:one', 'generation' => 2]];
            synth_protocol_record_action_intent($db, ['action_id' => 'action:one',
                'idempotency_key' => 'idem:one', 'name' => 'end_conversation',
                'capability' => 'action.end_conversation', 'deadline_ms' => 30000, 'arguments' => [],
                'actor' => ['form_id' => '0x0001A4D7', 'origin_plugin' => 'Fallout4.esm',
                    'playthrough_id' => 'save:one', 'display_name' => 'Preston Garvey']]);
            $this->assertStringContainsString("'{}'::jsonb", end($db->writes));
        } finally {
            $GLOBALS['SYNTH_REQUEST_EVENT'] = $previous;
        }
    }

    public function testNativeActionFollowupDepthUsesExactPersistedAncestry(): void
    {
        $event = ['protocol_version'=>2,'type'=>'action_result','session_id'=>'session:one','turn_id'=>'turn:one',
            'generation'=>2,'context_sequence'=>7,'runtime_variant'=>'flat',
            'payload'=>['result'=>['action_id'=>'action:child','idempotency_key'=>'idem:child']]];
        $db = new class {
            public array $rows = [];
            public array $queries = [];
            public function escapeLiteral(string $value): string { return "'".str_replace("'", "''", $value)."'"; }
            public function fetchOne(string $query): ?array {
                $this->queries[] = $query;
                preg_match("/i.action_id='([^']+)'/", $query, $match);
                return $this->rows[$match[1] ?? ''] ?? null;
            }
        };
        $root = array_replace($event, ['type'=>'input_text','payload'=>['text'=>'Check my inventory.']]);
        $parent = array_replace($event, ['payload'=>['result'=>['action_id'=>'action:root','idempotency_key'=>'idem:root']]]);
        $db->rows = [
            'action:root'=>['action_name'=>'CheckInventory','canonical_event'=>json_encode($root)],
            'action:child'=>['action_name'=>'Inspect','canonical_event'=>$parent],
        ];
        $this->assertSame(1, synth_protocol_action_followup_depth($db,$event,'Inspect'));
        $this->assertSame(0, synth_protocol_action_followup_depth($db,$parent,'CheckInventory'));
        $db->rows['action:grandchild']=['action_name'=>'Inspect','canonical_event'=>$event];
        $grandchild=array_replace($event,['payload'=>['result'=>['action_id'=>'action:grandchild','idempotency_key'=>'idem:grandchild']]]);
        $this->assertSame(2, synth_protocol_action_followup_depth($db,$grandchild,'Inspect'));
        foreach ($db->queries as $query) {
            $this->assertStringContainsString("i.session_id='session:one'", $query);
            $this->assertStringContainsString("i.turn_id='turn:one'", $query);
            $this->assertStringContainsString('i.generation=2', $query);
            $this->assertStringContainsString('i.idempotency_key=', $query);
            $this->assertStringContainsString("r.runtime_variant='flat'", $query);
            $this->assertStringNotContainsString('actions_issued', $query);
        }
        $this->assertNull(synth_protocol_action_followup_depth($db,$event,'CheckInventory'));
        foreach (['session_id'=>'other','turn_id'=>'other','generation'=>3,'context_sequence'=>8,'runtime_variant'=>'vr',
            'protocol_version'=>1,'type'=>'context'] as $field=>$value) {
            $db->rows['action:child']['canonical_event']=array_replace($parent,[$field=>$value]);
            $this->assertNull(synth_protocol_action_followup_depth($db,$event,'Inspect'), $field);
        }
        $db->rows['action:child']['canonical_event']=$event;
        $this->assertNull(synth_protocol_action_followup_depth($db,$event,'Inspect'), 'Cycle');
        $db->rows['action:child']['canonical_event']=$parent;
        unset($db->rows['action:root']);
        $this->assertNull(synth_protocol_action_followup_depth($db,$event,'Inspect'), 'Missing parent');
        $db->rows['action:child']['canonical_event']='invalid JSON';
        $this->assertNull(synth_protocol_action_followup_depth($db,$event,'Inspect'), 'Malformed origin');
        $db->rows=[];$db->queries=[];
        for ($index=0;$index<65;++$index) {
            $next=array_replace($event,['payload'=>['result'=>['action_id'=>'bounded:'.($index+1),'idempotency_key'=>'next']]]);
            $db->rows['bounded:'.$index]=['action_name'=>'Inspect','canonical_event'=>$next];
        }
        $bounded=array_replace($event,['payload'=>['result'=>['action_id'=>'bounded:0','idempotency_key'=>'first']]]);
        $this->assertNull(synth_protocol_action_followup_depth($db,$bounded,'Inspect'), 'Unbounded ancestry');
        $this->assertCount(64,$db->queries);
    }

    public function testNativeActionIssuanceUsesCallerOwnedLockAndCapturedClock(): void
    {
        $db = new class {
            public bool $locked = false;
            public bool $failIntent = false;
            public array $history = [];
            public array $writes = [];
            public int $timeout = 0;
            public function escapeLiteral(string $value): string { return "'".str_replace("'", "''", $value)."'"; }
            public function withNativeRequestLock(callable $operation,int $timeout): void {
                $this->locked=true;$this->timeout=$timeout;
                try {$operation();} finally {$this->locked=false;}
            }
            public function fetchOne(string $query): array {
                if (!$this->locked) throw new LogicException('Read outside native lock');
                if (str_contains($query,'request_executions')) return ['state'=>'streaming','runtime_state'=>'active','active_generation'=>2];
                if (str_contains($query,'context_snapshots')) return ['context_payload'=>['player'=>[],'world'=>['game_time_ticks'=>321]]];
                return ['id'=>1];
            }
            public function execQuery(string $query): bool {
                if (!$this->locked) throw new LogicException('Write outside native lock');
                $this->writes[]=$query;return !($this->failIntent && str_contains($query,'INSERT INTO synth_meta.action_intents'));
            }
            public function insert($table,$data): bool {
                if (!$this->locked || $table!=='eventlog') throw new LogicException('Unowned evidence write');
                $this->history[]=$data;return true;
            }
        };
        $keys=['db','SYNTH_REQUEST_EVENT','SYNTH_PROTOCOL_REQUEST_CLAIMED','gameRequest'];
        $previous=[];foreach($keys as $key)$previous[$key]=$GLOBALS[$key]??null;
        try {
            $GLOBALS['db']=$db;$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=true;
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>['protocol_version'=>2,'session_id'=>'session:one','request_id'=>'request:one',
                'turn_id'=>'turn:one','generation'=>2,'context_sequence'=>7,'runtime_variant'=>'flat','type'=>'input_text']];
            $GLOBALS['gameRequest']=['inputtext',123,999999,'current'];
            $action=['action_id'=>'action:one','idempotency_key'=>'idem:one','name'=>'ComeCloser','capability'=>'action.come_closer',
                'arguments'=>[],'deadline_ms'=>30000,'actor'=>['form_id'=>'0x0001A4D7','origin_plugin'=>'Fallout4.esm',
                    'playthrough_id'=>'save:one','display_name'=>'Preston']];
            synth_protocol_record_action_intent($db,$action);
            $this->assertSame(2000,$db->timeout);
            $this->assertFalse($db->locked);
            $this->assertCount(2,$db->writes);
            $this->assertStringStartsWith('INSERT INTO synth_meta.action_intents',trim($db->writes[1]));
            $this->assertStringNotContainsString('COMMIT',implode("\n",$db->writes));
            $this->assertStringNotContainsString('BEGIN',implode("\n",$db->writes));
            $this->assertCount(1,$db->history);
            $this->assertSame('funccall',$db->history[0]['type']);
            $this->assertSame('action:one',$db->history[0]['data']);
            $this->assertSame(321,$db->history[0]['gamets']);
            $this->assertSame('',$db->history[0]['people']);
            $db->failIntent=true;
            try {synth_protocol_record_action_intent($db,$action);$this->fail('Failed insertion was accepted');}
            catch(RuntimeException $error){$this->assertSame('Unable to persist action intent.',$error->getMessage());}
            $this->assertCount(1,$db->history,'Failed intent must not add issuance evidence');
        } finally {foreach($previous as $key=>$value)$GLOBALS[$key]=$value;}
    }

    public function testNativeCooldownReaderCombinesSavedFrontierWithOnlyCurrentTurnIssuance(): void
    {
        $db = new class {
            public string $query='';
            public function escapeLiteral(string $value): string {return "'".str_replace("'","''",$value)."'";}
            public function fetchAll(string $query): array {$this->query=$query;return [['action'=>'ComeCloser','gamets'=>'321','ts'=>'123']];}
        };
        $event=['protocol_version'=>2,'session_id'=>'session:one','generation'=>2,'context_sequence'=>7,'runtime_variant'=>'flat','turn_id'=>'turn:one'];
        $this->assertSame(['ComeCloser'=>['action'=>'ComeCloser','gamets'=>'321','ts'=>'123']],
            synth_protocol_last_actions_issued($db,$event,'Preston'));
        foreach(["h.event_payload->>'type'='funccall'",'i.actor_identity_id=s.reader_identity_id',
            'h.playthrough_id=s.playthrough_id','h.id<=s.cutoff',"h.session_id='session:one'",'h.generation=2',
            "h.context_sequence=7 AND i.turn_id='turn:one'"] as $binding) {
            $this->assertStringContainsString($binding,$db->query);
        }
        $this->assertStringNotContainsString('FROM actions_issued',$db->query);
    }

    public function testHaltPreservesEmptyObjectThroughIngressAndCanonicalStorage(): void
    {
        foreach ([1, 2] as $version) {
            $wire = file_get_contents(dirname(__DIR__, 2).'/protocol/v'.$version.'/fixtures/valid/halt.json');
            $event = synth_protocol_validate_event(synth_protocol_decode_json($wire));
            $this->assertInstanceOf(stdClass::class, $event['payload']);
            $this->assertSame('{}', synth_normalize_json_event($event)['payload']);
            $this->assertStringContainsString('"payload":{}', synth_protocol_canonical_json($event));
        }
    }

    public function testActionIdsAreScopedToRuntimeSessionAndEmptyArgumentsStayObjects(): void
    {
        require_once dirname(__DIR__, 2).'/lib/response.php';
        $keys = ['db', 'SYNTH_REQUEST_EVENT', 'SYNTH_CLIENT_CAPABILITIES'];
        $previous = [];
        foreach ($keys as $key) $previous[$key] = $GLOBALS[$key] ?? null;
        $db = new class {
            public function escapeLiteral(string $value): string { return "'".str_replace("'", "''", $value)."'"; }
            public function fetchOne(string $sql): array {
                if (str_contains($sql, 'context_snapshots')) return ['context_payload' => [
                    'player' => ['form_id' => '0x00000014', 'origin_plugin' => 'Fallout4.esm', 'playthrough_id' => 'save:one', 'display_name' => 'Player'],
                    'target' => ['form_id' => '0x0001A4D7', 'origin_plugin' => 'Fallout4.esm', 'playthrough_id' => 'save:one', 'display_name' => 'Preston'],
                ]];
                return ['id' => 1];
            }
            public function execQuery(string $sql): bool { return true; }
        };
        try {
            $GLOBALS['db'] = $db;
            $GLOBALS['SYNTH_CLIENT_CAPABILITIES'] = ['action.end_conversation'];
            $actions = [];
            foreach (['session:first', 'session:second'] as $session) {
                $event = ['protocol_version' => 2, 'session_id' => $session, 'generation' => 1,
                    'context_sequence' => 1, 'request_id' => 'text:1:4', 'turn_id' => 'turn:1:5'];
                $GLOBALS['SYNTH_REQUEST_EVENT'] = synth_normalize_json_event($event);
                $actions[] = synth_protocol_action_from_legacy_line(['command_name' => 'EndConversation', 'speaker' => 'Preston']);
            }
            $this->assertNotNull($actions[0]);
            $this->assertNotNull($actions[1]);
            $this->assertNotSame($actions[0]['action_id'], $actions[1]['action_id']);
            $this->assertNotSame($actions[0]['idempotency_key'], $actions[1]['idempotency_key']);
            $this->assertSame('{}', json_encode($actions[0]['arguments']));
            $GLOBALS['SYNTH_CLIENT_CAPABILITIES']=['action.check_inventory','action.attack'];
            foreach (['Rifle','Preston',''] as $filter) {
                $inventory=synth_protocol_action_from_legacy_line(['command_name'=>'CheckInventory','speaker'=>'Preston','target'=>$filter]);
                $this->assertNotNull($inventory,'An inventory filter is not an actor lookup');
                $this->assertArrayNotHasKey('target',$inventory,'Even an actor-shaped filter must not gain actor-target authority');
                $this->assertSame($filter,((array)$inventory['arguments'])['target_name'] ?? '');
            }
            $this->assertNull(synth_protocol_action_from_legacy_line(['command_name'=>'Attack','speaker'=>'Preston','target'=>'Rifle']),
                'Actions requiring actor targets must retain canonical resolution');
            $attack=synth_protocol_action_from_legacy_line(['command_name'=>'Attack','speaker'=>'Preston','target'=>'Player']);
            $this->assertSame('0x00000014',$attack['target']['form_id']);
        } finally {
            foreach ($previous as $key => $value) $GLOBALS[$key] = $value;
        }
    }

    public function testPluginEventStreamsByDefaultWithoutHeaders(): void
    {
        $event = synth_normalize_json_event([
            'schema' => 'synth.event.v1',
            'type' => 'inputtext',
            'ts' => 100,
            'gamets' => 200,
            'payload' => [
                'schema' => 'synth.input.v1',
                'npc' => 'Veronica',
                'player' => 'Graussy',
                'text' => 'hello',
            ],
        ]);

        $this->assertTrue(synth_should_stream_json_response($event));
    }

    public function testPluginEventCanExplicitlyRequestBufferedResponse(): void
    {
        $event = synth_normalize_json_event([
            'schema' => 'synth.event.v1',
            'type' => 'inputtext',
            'response_streaming' => false,
            'payload' => [
                'schema' => 'synth.input.v1',
                'npc' => 'Veronica',
                'player' => 'Graussy',
                'text' => 'hello',
            ],
        ]);

        $this->assertFalse(synth_should_stream_json_response($event));
    }

    public function testNonPluginJsonRequiresStreamingHeader(): void
    {
        $event = synth_normalize_json_event([
            'schema' => 'synth.ui.request.v1',
            'type' => 'status',
            'payload' => [],
        ]);

        $this->assertFalse(synth_should_stream_json_response($event));
        $this->assertTrue(synth_should_stream_json_response($event, 'application/x-ndjson', ''));
        $this->assertTrue(synth_should_stream_json_response($event, '', '1'));
    }

    public function testCombatBarkPromptContainsOnlyCurrentAlliesAndHostiles(): void
    {
        $event = synth_normalize_json_event([
            'schema' => 'synth.event.v1',
            'type' => 'combatbark',
            'payload' => [
                'schema' => 'synth.rpg_event.v1',
                'combat' => [
                    'allies_currently_fighting' => ['Graussy', 'Veronica'],
                    'hostile_combatants' => ['Giant Radscorpion'],
                ],
            ],
        ]);

        $this->assertSame(
            "<combat>\n# Allies Currently Fighting\n- Graussy\n- Veronica\n\n# Hostile Combatants\n- Giant Radscorpion\n</combat>",
            synth_build_combat_prompt_from_event($event)
        );
    }

    public function testCombatBarkPromptCanUseStrictActorStates(): void
    {
        $event = [
            'type' => 'combatbark',
            'payload' => [
                'actor_states' => [
                    ['actor' => ['display_name' => 'Preston Garvey'], 'alive' => true,
                     'disabled' => false, 'in_combat' => true, 'hostile_to_player' => false],
                    ['actor' => ['display_name' => 'Raider'], 'alive' => true,
                     'disabled' => false, 'in_combat' => true, 'hostile_to_player' => true],
                    ['actor' => ['display_name' => 'Settler'], 'alive' => true,
                     'disabled' => false, 'in_combat' => false, 'hostile_to_player' => false],
                ],
            ],
        ];

        $this->assertSame(
            "<combat>\n# Allies Currently Fighting\n- Preston Garvey\n\n"
                . "# Hostile Combatants\n- Raider\n</combat>",
            synth_build_combat_prompt_from_event($event)
        );
    }

    public function testStrictActorStateValidatesFalloutSemantics(): void
    {
        $state = synth_protocol_validate_actor_state([
            'actor' => [
                'form_id' => '0x0001A4D7', 'origin_plugin' => 'Fallout4.esm',
                'playthrough_id' => 'save:fo4:001', 'display_name' => 'Preston Garvey',
            ],
            'base_form_id' => '0x00019FD9', 'base_origin_plugin' => 'Fallout4.esm',
            'position' => ['x' => 10.0, 'y' => 20.0, 'z' => 30.0],
            'distance' => 125.0, 'alive' => true, 'disabled' => false,
            'in_combat' => false, 'hostile_to_player' => false, 'sneaking' => false,
            'level' => 10, 'race' => 'HumanRace', 'sex' => 'male',
            'voice_type' => 'MaleBoston', 'teammate' => true,
            'health_percent' => 82.5, 'action_points_percent' => 65.0,
            'inventory' => [[
                'form_id' => '0x0001F66A', 'origin_plugin' => 'Fallout4.esm',
                'display_name' => 'Laser Musket', 'count' => 1, 'value' => 57,
                'weight' => 8.0, 'form_type' => 41, 'equipped' => true,
            ]],
            'line_of_sight' => 'visible',
            'factions' => [[
                'form_id' => '0x0005DE41', 'origin_plugin' => 'Fallout4.esm',
                'display_name' => 'MinutemenFaction', 'editor_id' => 'MinutemenFaction',
                'rank' => 0,
            ]],
            'faction_observation' => 'effective',
            'life_state' => 'alive', 'posture' => 'normal',
            'weapon_drawn' => false, 'movement_speed' => 12.5, 'sprinting' => false,
            'talking_to_player' => true, 'in_power_armor' => false,
            'current_package' => [
                'available' => true, 'form_id' => '0x000A2420',
                'origin_plugin' => 'Fallout4.esm', 'display_name' => 'SandboxPackage',
                'editor_id' => 'SandboxPackage',
            ],
        ], 'payload.actor_states.0');

        $this->assertSame('HumanRace', $state['race']);
        $this->assertSame(82.5, $state['health_percent']);
        $this->assertTrue($state['teammate']);
        $this->assertSame('visible', $state['line_of_sight']);
        $this->assertSame('Laser Musket', $state['inventory'][0]['display_name']);
        $this->assertArrayNotHasKey('inventory_observation',$state);
        // A base FormID may have differently named/equipped stacks; do not merge their counts or metadata.
        $stacks=$state;
        $stacks['inventory'][]=array_replace($state['inventory'][0],[
            'display_name'=>'Modified Laser Musket','count'=>3,'value'=>125,'weight'=>9.5,'equipped'=>false,
        ]);
        $stacks['inventory_observation']='complete';
        $this->assertSame($stacks,synth_protocol_validate_actor_state($stacks,'state'));
        $principal=synth_protocol_prompt_actor_states([$stacks],$state['actor'],$state['actor'])[0];
        $this->assertSame($stacks['inventory'],$principal['inventory']);
        $this->assertSame('complete',$principal['inventory_observation']);
        $other=['form_id'=>'0x00000014','origin_plugin'=>'Fallout4.esm'];
        $bystander=synth_protocol_prompt_actor_states([$stacks],$other,$other)[0];
        $this->assertSame([$stacks['inventory'][0]],$bystander['inventory']);
        $this->assertSame('partial',$bystander['inventory_observation']);
        foreach (['complete','partial','unavailable'] as $quality) {
            $observed=array_replace($state,['inventory_observation'=>$quality]);
            if ($quality==='unavailable') $observed['inventory']=[];
            $this->assertSame($observed,synth_protocol_validate_actor_state($observed,'state'));
        }
        foreach ([null,[],true,'invented','unavailable'] as $quality) {
            $invalid=array_replace($state,['inventory_observation'=>$quality]);
            $code='require '.var_export(dirname(__DIR__,2).'/lib/request.php',true).';'
                .'synth_protocol_validate_actor_state('.var_export($invalid,true).',"state");echo "ACCEPTED";';
            $output=shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code));
            $this->assertStringNotContainsString('ACCEPTED',strval($output));
            $this->assertStringContainsString('invalid_actor_state',strval($output));
        }
        $this->assertSame('MinutemenFaction', $state['factions'][0]['editor_id']);
        $this->assertSame('effective', $state['faction_observation']);
        $this->assertSame('alive', $state['life_state']);
        $this->assertTrue($state['talking_to_player']);
        $this->assertSame('SandboxPackage', $state['current_package']['editor_id']);
    }

    public function testActorValueAvailabilityDoesNotInventFullHealthOrActionPoints(): void
    {
        $state=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/context.json'),true)['payload']['actor_states'][0];
        foreach (['health_percent','action_points_percent'] as $field) {
            foreach ([0,82.5,100] as $value) {
                foreach ([true,false,'legacy'] as $availability) {
                    $observed=array_replace($state,[$field=>$value,$field.'_available'=>$availability]);
                    if ($availability==='legacy') unset($observed[$field.'_available']);
                    $this->assertSame($observed,synth_protocol_validate_actor_state($observed,'state'));
                    $prompt=synth_protocol_prompt_actor_states([$observed],[],[])[0];
                    $this->assertSame($availability===true,$prompt[$field.'_available']);
                    $this->assertSame($availability===true ? $value : null,$prompt[$field]);
                    $other=$field==='health_percent'?'action_points_percent':'health_percent';
                    $this->assertTrue($prompt[$other.'_available']);
                    $this->assertSame($state[$other],$prompt[$other]);
                }
            }
            foreach ([null,0,1,'true','false',[]] as $invalid) {
                $observed=array_replace($state,[$field.'_available'=>$invalid]);
                $code='require '.var_export(dirname(__DIR__,2).'/lib/request.php',true).';'
                    .'synth_protocol_validate_actor_state('.var_export($observed,true).',"state");echo "ACCEPTED";';
                $output=strval(shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code)));
                $this->assertStringContainsString('invalid_actor_state',$output);
                $this->assertStringNotContainsString('ACCEPTED',$output);
            }
        }
    }

    public function testFactionCompletenessValidationAndPromptCaps(): void
    {
        $state = json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/context.json'),true)['payload']['actor_states'][0];
        $legacy = $state;
        unset($legacy['faction_completeness']);
        $this->assertSame($legacy,synth_protocol_validate_actor_state($legacy,'state'));
        $this->assertSame('partial',synth_protocol_faction_completeness($legacy));
        $this->assertStringContainsString('completeness=partial',synth_protocol_faction_prompt_label($legacy,1));
        $this->assertStringContainsString('completeness=partial',synth_protocol_faction_prompt_label($state,0));
        $this->assertStringContainsString('completeness=complete',synth_protocol_faction_prompt_label($state,1));
        $this->assertStringContainsString('current membership unverified',synth_protocol_faction_prompt_label(array_replace($state,['faction_observation'=>'base_only']),1));
        $this->assertStringContainsString('source=unspecified; completeness=unknown',synth_protocol_faction_prompt_label(['factions'=>$state['factions']],1));
        foreach (['effective','base_only'] as $mode) {
            foreach (['complete','partial'] as $quality) {
                foreach ([0,8,9,16,17,32] as $count) {
                    $factions=[];
                    for ($i=0;$i<$count;++$i) $factions[]=array_replace($state['factions'][0],['form_id'=>sprintf('0x%08X',0x5000+$i)]);
                    $observed = array_replace($state,['faction_observation'=>$mode,'faction_completeness'=>$quality,'factions'=>$factions]);
                    $this->assertSame($observed,synth_protocol_validate_actor_state($observed,'state'));
                    foreach ([true,false] as $selected) {
                        $actual = synth_protocol_prompt_actor_states([$observed],[], $selected ? $state['actor'] : [])[0];
                        $cap = $selected ? 16 : 8;
                        $this->assertCount(min($cap,$count),$actual['factions']);
                        $this->assertSame($quality==='complete' && $count>$cap ? 'partial' : $quality,$actual['faction_completeness']);
                        $this->assertSame($mode,$actual['faction_observation']);
                    }
                }
            }
        }
        $unavailable = array_replace($state,['faction_observation'=>'unavailable','faction_completeness'=>'unavailable','factions'=>[]]);
        $this->assertSame($unavailable,synth_protocol_validate_actor_state($unavailable,'state'));
        $this->assertSame('unavailable',synth_protocol_prompt_actor_states([$unavailable],[],[])[0]['faction_completeness']);
        $invalidStates=[];
        foreach ([null,true,[],'','invented','unavailable'] as $quality) $invalidStates[]=array_replace($state,['faction_completeness'=>$quality]);
        foreach (['complete','partial'] as $quality) $invalidStates[]=array_replace($unavailable,['faction_completeness'=>$quality]);
        $invalidStates[]=array_replace($unavailable,['factions'=>$state['factions']]);
        foreach ($invalidStates as $invalid) {
            $code='require '.var_export(dirname(__DIR__,2).'/lib/request.php',true).';'
                .'synth_protocol_validate_actor_state('.var_export($invalid,true).',"state");echo "ACCEPTED";';
            $output=strval(shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code)));
            $this->assertStringContainsString('invalid_actor_state',$output);
            $this->assertStringNotContainsString('ACCEPTED',$output);
        }
    }

    public function testNativeFactionSnapshotBuildsStableProfileIdentity(): void
    {
        $factions = synth_protocol_profile_factions([
            'factions' => [[
                'form_id' => '0x0605DE41', 'origin_plugin' => 'Fallout4.esm',
                'display_name' => 'MinutemenFaction', 'editor_id' => 'MinutemenFaction',
                'rank' => 1,
            ], [
                'form_id' => '0xFE123ABC', 'origin_plugin' => 'Companion.esl',
                'display_name' => 'CompanionFaction', 'editor_id' => '', 'rank' => 0,
            ]],
        ]);

        $this->assertSame('Fallout4.esm|0005DE41', $factions[0]['stable_key']);
        $this->assertSame('Companion.esl|00000ABC', $factions[1]['stable_key']);
        $this->assertSame('0605DE41', $factions[0]['formid']);
        $this->assertSame(1, $factions[0]['rank']);
    }

    public function testNewActorIdentityPreservesCanonicalLowercaseHexPrefix(): void
    {
        $db = new class {
            public array $queries = [];
            public function escapeLiteral(string $value): string { return "'".str_replace("'", "''", $value)."'"; }
            public function fetchOne(string $sql): array
            {
                $this->queries[] = $sql;
                return str_starts_with(ltrim($sql), 'SELECT') ? [] : ['id' => 42];
            }
            public function execQuery(string $sql): bool { $this->queries[] = $sql; return true; }
        };

        $identityId = synth_protocol_actor_identity_id($db, [
            'form_id' => '0x0001a4d7',
            'origin_plugin' => 'Fallout4.esm',
            'playthrough_id' => 'save:fo4:001',
            'display_name' => 'Preston Garvey',
        ]);

        $this->assertSame(42, $identityId);
        $this->assertStringContainsString("'0x0001A4D7'", $db->queries[1]);
        $this->assertStringNotContainsString("'0X0001A4D7'", $db->queries[1]);
    }

    public function testNativeFactionSnapshotUpdatesLinkedCanonicalNpcProfile(): void
    {
        $db = new class {
            public array $queries = [];
            public function escapeLiteral(string $value): string { return "'".str_replace("'", "''", $value)."'"; }
            public function fetchOne(string $sql): array { return ['id' => 42]; }
            public function execQuery(string $sql): bool { $this->queries[] = $sql; return true; }
        };
        synth_protocol_persist_actor_factions($db, [[
            'actor' => [
                'form_id' => '0x0001A4D7', 'origin_plugin' => 'Fallout4.esm',
                'playthrough_id' => 'save:fo4:001', 'display_name' => 'Preston Garvey',
            ],
            'factions' => [[
                'form_id' => '0x0005DE41', 'origin_plugin' => 'Fallout4.esm',
                'display_name' => 'MinutemenFaction', 'editor_id' => 'MinutemenFaction',
                'rank' => 0,
            ]],
            'faction_observation' => 'effective',
        ]]);

        $this->assertCount(3, $db->queries);
        $this->assertStringContainsString('UPDATE synth.actor_identities', $db->queries[1]);
        $this->assertStringContainsString('Fallout4.esm|0005DE41', $db->queries[1]);
        $this->assertStringContainsString('UPDATE synth.core_npc_master', $db->queries[2]);
        $this->assertStringContainsString('actor_identity_id=42', $db->queries[2]);
        $this->assertStringContainsString('faction_observation', $db->queries[2]);
    }

    public function testFactionPersistenceRejectsEitherMetadataWriteFailure(): void
    {
        foreach (['SET metadata=' => 2, 'SET extended_data=jsonb_set' => 3] as $needle => $count) {
            $db = new class($needle) {
                public array $queries = [];
                public function __construct(private string $needle) {}
                public function escapeLiteral(string $value): string { return "'".str_replace("'", "''", $value)."'"; }
                public function fetchOne(string $sql): array { return ['id' => 42]; }
                public function execQuery(string $sql): bool
                {
                    $this->queries[] = $sql;
                    return !str_contains($sql, $this->needle);
                }
            };
            try {
                synth_protocol_persist_actor_factions($db, [[
                    'actor' => ['form_id' => '0x0001A4D7', 'origin_plugin' => 'Fallout4.esm',
                        'playthrough_id' => 'save:fo4:001', 'display_name' => 'Preston Garvey'],
                    'factions' => [], 'faction_observation' => 'unavailable',
                ]]);
                $this->fail('A failed faction metadata write must reject persistence.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('faction metadata', $error->getMessage());
            }
            $this->assertCount($count, $db->queries, 'No later faction write may follow a failure.');
        }
    }

    public function testProtocolCommitChecksBooleanDatabaseDoubles(): void
    {
        foreach ([true, false] as $success) {
            $db = new class($success) {
                public array $queries = [];
                public function __construct(private bool $success) {}
                public function execQuery(string $sql): bool { $this->queries[] = $sql; return $this->success; }
            };
            $rejected = false;
            try {
                synth_protocol_commit_transaction($db);
            } catch (RuntimeException $error) {
                $rejected = true;
                $this->assertSame('Unable to confirm protocol transaction commit.', $error->getMessage());
            }
            $this->assertSame(!$success, $rejected);
            $this->assertSame(['COMMIT'], $db->queries);
        }
    }

    public function testAudienceObservationIsStrictAndOlderSnapshotsRemainUnknown(): void
    {
        $payload=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/context.json'),true)['payload'];
        foreach ([1,2] as $version) {
            foreach (['complete','partial','unavailable'] as $quality) {
                $observed=array_replace($payload,['audience_observation'=>$quality]);
                if ($quality==='unavailable') {$observed['audience']=[];$observed['actor_states']=[];}
                $this->assertSame($observed,synth_protocol_validate_payload('context',$observed,$version));
                $this->assertSame($quality,synth_protocol_audience_observation($observed));
            }
            foreach ([null,true,[],'invented','cached','unavailable'] as $quality) {
                $invalid=array_replace($payload,['audience_observation'=>$quality]);
                $code='require '.var_export(dirname(__DIR__,2).'/lib/request.php',true).';'
                    .'synth_protocol_validate_payload("context",'.var_export($invalid,true).','.$version.');echo "ACCEPTED";';
                $output=shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code));
                $this->assertStringNotContainsString('ACCEPTED',strval($output));
                $this->assertStringContainsString('invalid_payload',strval($output));
            }
        }
        unset($payload['audience_observation']);
        $this->assertSame('partial',synth_protocol_audience_observation($payload));
        $this->assertArrayNotHasKey('audience_observation',$payload);
        $payload['audience']=[];
        $this->assertSame('unavailable',synth_protocol_audience_observation($payload));
        $this->assertSame('complete',synth_protocol_audience_observation($payload+['audience_observation'=>'complete']));
    }

    public function testStrictQuestStateValidatesCanonicalFalloutIdentity(): void
    {
        $quest = synth_protocol_validate_quest_state([
            'form_id' => '0x000229E5', 'origin_plugin' => 'Fallout4.esm',
            'display_name' => 'When Freedom Calls', 'editor_id' => 'Min00',
            'current_stage' => 45, 'active_objectives' => 1,
            'objectives' => ['Join Preston Garvey in Sanctuary'],
        ], 'payload.active_quests.0');

        $this->assertSame('When Freedom Calls', $quest['display_name']);
        $this->assertSame(45, $quest['current_stage']);
        foreach (['complete','partial','unavailable'] as $quality) {
            $observed=array_replace($quest,['objectives_observation'=>$quality]);
            if ($quality==='unavailable') { $observed['objectives']=[];$observed['active_objectives']=0; }
            $this->assertSame($observed,synth_protocol_validate_quest_state($observed,'quest'));
        }
        foreach ([null,true,[],'invented','unavailable'] as $quality) {
            $invalid=array_replace($quest,['objectives_observation'=>$quality]);
            $code='require '.var_export(dirname(__DIR__,2).'/lib/request.php',true).';'
                .'synth_protocol_validate_quest_state('.var_export($invalid,true).',"quest");echo "ACCEPTED";';
            $output=shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code));
            $this->assertStringNotContainsString('ACCEPTED',strval($output));
            $this->assertStringContainsString('invalid_quest_state',strval($output));
        }
        $payload=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/context.json'),true)['payload'];
        foreach ([1,2] as $version) {
            foreach (['complete','partial','cached','unavailable'] as $quality) {
                $observed=array_replace($payload,['active_quests_observation'=>$quality]);
                if ($quality==='unavailable') $observed['active_quests']=[];
                $this->assertSame($observed,synth_protocol_validate_payload('context',$observed,$version));
            }
            foreach ([null,[],'invented','unavailable'] as $quality) {
                $invalid=array_replace($payload,['active_quests_observation'=>$quality]);
                $code='require '.var_export(dirname(__DIR__,2).'/lib/request.php',true).';'
                    .'synth_protocol_validate_payload("context",'.var_export($invalid,true).','.$version.');echo "ACCEPTED";';
                $output=shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code));
                $this->assertStringNotContainsString('ACCEPTED',strval($output));
                $this->assertStringContainsString('invalid_payload',strval($output));
            }
        }
    }

    public function testStrictQuestSnapshotReplacesCanonicalQuestRows(): void
    {
        $db = new class {
            public array $queries = [];
            public function escapeLiteral(string $value): string { return "'".str_replace("'", "''", $value)."'"; }
            public function execQuery(string $sql): bool { $this->queries[] = $sql; return true; }
        };
        synth_protocol_persist_active_quests($db, [
            'payload' => [
                'playthrough_id' => 'save-fo4-001',
                'active_quests_observation' => 'complete',
                'snapshot_sequence' => 12,
                'world' => ['game_time_ticks' => 834000000000],
            ],
        ], [[
            'form_id' => '0x000229E5', 'origin_plugin' => 'Fallout4.esm',
            'display_name' => 'When Freedom Calls', 'editor_id' => 'Min00',
            'current_stage' => 45, 'active_objectives' => 1,
            'objectives' => ['Join Preston Garvey in Sanctuary'],
        ]]);

        $this->assertSame("DELETE FROM synth.quests WHERE sess='save-fo4-001'", $db->queries[0]);
        $this->assertStringContainsString('INSERT INTO synth.quests', $db->queries[1]);
        $this->assertStringContainsString('When Freedom Calls', $db->queries[1]);
        $this->assertStringContainsString('Join Preston Garvey in Sanctuary', $db->queries[1]);
        $this->assertStringContainsString('save-fo4-001', $db->queries[1]);
        $this->assertStringContainsString('834000000000', $db->queries[1]);
        foreach (['unavailable','cached',null] as $quality) {
            $db->queries=[];
            $payload=['playthrough_id'=>'save-fo4-001'];
            if ($quality!==null) $payload['active_quests_observation']=$quality;
            synth_protocol_persist_active_quests($db,['payload'=>$payload],[]);
            $this->assertSame([],$db->queries);
        }
        $db->queries=[];
        synth_protocol_persist_active_quests($db,['payload'=>['playthrough_id'=>'save-fo4-001']],[[
            'form_id'=>'0x000229E5','origin_plugin'=>'Fallout4.esm','display_name'=>'Observed',
            'current_stage'=>45,'active_objectives'=>0,'objectives'=>[],
        ]]);
        $this->assertCount(2,$db->queries);
        $this->assertStringContainsString("upper(id_quest)='0X000229E5' AND lower(mod)=lower('Fallout4.esm')",$db->queries[0]);
        foreach (['complete','partial','cached','unavailable',null] as $quality) {
            $payload=['active_quests'=>[]];
            if ($quality!==null) $payload['active_quests_observation']=$quality;
            $this->assertSame($quality??'unavailable',synth_protocol_prompt_quests($payload)['active_quests_observation']);
        }
        $unobserved=synth_protocol_prompt_quests(['active_quests'=>[['active_objectives'=>0,'objectives'=>[]]]]);
        $this->assertSame('partial',$unobserved['active_quests_observation']);
        $this->assertSame('unavailable',$unobserved['active_quests'][0]['objectives_observation']);
        $this->assertNull($unobserved['active_quests'][0]['active_objectives']);
    }

    public function testStrictEnvironmentReferencesValidateAndPersistCanonicalRows(): void
    {
        $item = [
            'reference_id' => '0xFF001234', 'reference_origin_plugin' => '',
            'base_form_id' => '0x00004822', 'base_origin_plugin' => 'Fallout4.esm',
            'cell_form_id' => '0x00018AA2', 'cell_origin_plugin' => 'Fallout4.esm',
            'display_name' => '10mm Pistol', 'position' => ['x' => 12.0, 'y' => 22.0, 'z' => 30.0],
            'distance' => 14.14, 'count' => 1, 'value' => 50, 'weight' => 4.2,
            'form_type' => 43, 'stealing' => false, 'looking_at' => true, 'held' => false,
        ];
        $poi = [
            'reference_id' => '0x0001A6D8', 'reference_origin_plugin' => 'Fallout4.esm',
            'base_form_id' => '0x0001A6D7', 'base_origin_plugin' => 'Fallout4.esm',
            'cell_form_id' => '0x00018AA2', 'cell_origin_plugin' => 'Fallout4.esm',
            'display_name' => 'Sanctuary workshop door', 'kind' => 'door',
            'position' => ['x' => 40.0, 'y' => 20.0, 'z' => 30.0], 'distance' => 40.0,
            'locked' => false, 'looking_at' => false,
        ];
        $this->assertSame('10mm Pistol', synth_protocol_validate_nearby_item($item, 'item')['display_name']);
        $this->assertSame('door', synth_protocol_validate_point_of_interest($poi, 'poi')['kind']);

        $db = new class {
            public array $queries = [];
            public function escapeLiteral(string $value): string { return "'".str_replace("'", "''", $value)."'"; }
            public function execQuery(string $sql): bool { $this->queries[] = $sql; return true; }
        };
        synth_protocol_persist_environment_snapshots($db, [
            'request_id' => 'request-context-001', 'turn_id' => 'turn-context-001',
            'generation' => 7, 'session_id' => 'session-flat-001', 'runtime_variant' => 'flat',
            'payload' => [
                'playthrough_id' => 'save-001', 'snapshot_sequence' => 12,
                'player' => ['display_name' => 'Sole Survivor'],
                'world' => ['game_time_ticks' => 834000000000],
            ],
        ], [$item], [$poi]);
        $this->assertCount(2, $db->queries);
        $this->assertStringContainsString("'nearby_items'", $db->queries[0]);
        $this->assertStringContainsString('FF001234', $db->queries[0]);
        $this->assertStringContainsString("'points_of_interest'", $db->queries[1]);
        $this->assertStringContainsString('Sanctuary workshop door', $db->queries[1]);
        $this->assertStringContainsString('request-context-001', $db->queries[1]);
        $this->assertStringContainsString('"nearby_items_observation":"partial"',$db->queries[0]);
        $this->assertStringContainsString('"points_of_interest_observation":"partial"',$db->queries[1]);
        $payload=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/context.json'),true)['payload'];
        foreach (['nearby_items'=>16,'points_of_interest'=>12] as $field=>$limit) {
            foreach (['complete','partial','cached','unavailable',null] as $quality) {
                foreach ([true,false] as $empty) {
                    if ($quality==='unavailable' && !$empty) continue;
                    $observed=$payload;if ($empty) $observed[$field]=[];
                    if ($quality===null) unset($observed[$field.'_observation']);else $observed[$field.'_observation']=$quality;
                    foreach ([1,2] as $version) $this->assertSame($observed,synth_protocol_validate_payload('context',$observed,$version));
                    $expected=$quality ?? ($empty?'unavailable':'partial');
                    $prompt=synth_protocol_prompt_environment($observed);
                    $this->assertSame($expected,$prompt[$field.'_observation']);
                    $this->assertSame($observed[$field],$prompt[$field]);
                    $db->queries=[];
                    synth_protocol_persist_environment_snapshots($db,['payload'=>$observed],$observed['nearby_items'],$observed['points_of_interest']);
                    $sql=$db->queries[$field==='nearby_items'?0:1];
                    $this->assertStringContainsString('"'.$field.'_observation":"'.$expected.'"',$sql);
                    if ($quality==='unavailable') $this->assertStringContainsString('observation unavailable',$sql);
                }
            }
            foreach ([null,[],true,'invented','unavailable'] as $quality) {
                $invalid=array_replace($payload,[$field.'_observation'=>$quality]);
                foreach ([1,2] as $version) {
                    $code='require '.var_export(dirname(__DIR__,2).'/lib/request.php',true).';'
                        .'synth_protocol_validate_payload("context",'.var_export($invalid,true).','.$version.');echo "ACCEPTED";';
                    $output=shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code));
                    $this->assertStringNotContainsString('ACCEPTED',strval($output));
                    $this->assertStringContainsString('invalid_payload',strval($output));
                }
            }
            $oversized=$payload;$oversized[$field]=array_fill(0,$limit+1,$payload[$field][0]);
            $prompt=synth_protocol_prompt_environment($oversized);
            $this->assertSame('partial',$prompt[$field.'_observation']);$this->assertCount($limit,$prompt[$field]);
            $this->assertSame('complete',$oversized[$field.'_observation']);
        }
    }

    public function testPlayerRespeechUsesCommittedCanonicalPlayerIdentity(): void
    {
        $previousEvent = $GLOBALS['SYNTH_REQUEST_EVENT'] ?? null;
        $db = new class {
            public function escapeLiteral(string $value): string { return "'".str_replace("'", "''", $value)."'"; }
            public function fetchOne(string $sql): array
            {
                return [
                    'context_payload' => '{}',
                    'player_form_id' => '0x00000014', 'player_plugin' => 'Fallout4.esm',
                    'playthrough_id' => 'save:fo4:001', 'player_name' => 'Sole Survivor',
                    'target_form_id' => '0x0001A4D7', 'target_plugin' => 'Fallout4.esm',
                    'target_name' => 'Preston Garvey',
                ];
            }
        };
        $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json' => [
            'session_id' => 'session:flat:test', 'generation' => 7,
        ]];

        try {
            $speaker = synth_protocol_resolve_speaker_identity($db, 'Player');
            $this->assertSame('0x00000014', $speaker['form_id']);
            $this->assertSame('Fallout4.esm', $speaker['origin_plugin']);
            $this->assertSame('Sole Survivor', $speaker['display_name']);
        } finally {
            if ($previousEvent === null) unset($GLOBALS['SYNTH_REQUEST_EVENT']);
            else $GLOBALS['SYNTH_REQUEST_EVENT'] = $previousEvent;
        }
    }

    public function testPromptInventoryKeepsPrincipalItemsAndOnlyEquippedBystanderItems(): void
    {
        $item = static function (string $name, bool $equipped): array {
            return ['display_name' => $name, 'equipped' => $equipped];
        };
        $states = [[
            'actor' => ['form_id' => '0x00000014', 'origin_plugin' => 'Fallout4.esm'],
            'inventory' => array_map(fn ($index) => $item('Player '.$index, false), range(1, 16)),
            'factions' => array_fill(0, 20, ['rank' => 0]),
        ], [
            'actor' => ['form_id' => '0x0001A4D7', 'origin_plugin' => 'Fallout4.esm'],
            'inventory' => array_map(fn ($index) => $item('Target '.$index, false), range(1, 16)),
            'factions' => array_fill(0, 20, ['rank' => 0]),
        ], [
            'actor' => ['form_id' => '0x0002A4D7', 'origin_plugin' => 'Fallout4.esm'],
            'inventory' => [
                $item('Pipe Pistol', true), $item('Raider Leathers', true),
                $item('Jet', false), $item('Caps', false),
            ],
            'factions' => array_fill(0, 20, ['rank' => 0]),
        ]];
        $states = array_map(static fn($state) => $state + ['faction_observation'=>'effective'],$states);
        $result = synth_protocol_prompt_actor_states(
            $states,
            ['form_id' => '0x00000014', 'origin_plugin' => 'Fallout4.esm'],
            ['form_id' => '0x0001A4D7', 'origin_plugin' => 'Fallout4.esm']
        );

        $this->assertCount(12, $result[0]['inventory']);
        $this->assertCount(12, $result[1]['inventory']);
        $this->assertSame(['Pipe Pistol', 'Raider Leathers'], array_column($result[2]['inventory'], 'display_name'));
        $this->assertCount(16, $result[0]['factions']);
        $this->assertCount(16, $result[1]['factions']);
        $this->assertCount(8, $result[2]['factions']);
        $this->assertSame(['partial','partial','partial'],array_column($result,'inventory_observation'));
        foreach (['complete','partial','unavailable',null,'invalid'] as $observation) {
            foreach ([[],[$item('Observed',true)]] as $inventory) {
                $state=['actor'=>$states[0]['actor'],'inventory'=>$inventory];
                if ($observation!==null) $state['inventory_observation']=$observation;
                $actual=synth_protocol_prompt_actor_states([$state],$states[0]['actor'],$states[1]['actor'])[0];
                $expected=in_array($observation,['complete','partial'],true) ? $observation
                    : ($observation===null && $inventory ? 'partial' : 'unavailable');
                $this->assertSame($expected,$actual['inventory_observation']);
                $this->assertSame($expected==='unavailable' ? [] : $inventory,$actual['inventory']);
            }
        }
        $completeStates=array_map(static fn($state)=>$state+['inventory_observation'=>'complete'],$states);
        $reduced=synth_protocol_prompt_actor_states($completeStates,$states[0]['actor'],$states[1]['actor']);
        $this->assertSame(['partial','partial','partial'],array_column($reduced,'inventory_observation'));
    }

    public function testInventoryQuerySelectionFindsLateRowsWithoutChangingCapturedStacks(): void
    {
        $inventory = array_map(static fn(int $i): array => [
            'form_id' => sprintf('0x%08X', 0x1000 + $i), 'origin_plugin' => 'Fallout4.esm',
            'display_name' => 'Unrelated item '.$i, 'count' => 1, 'equipped' => $i === 0,
            'weight' => 1.0, 'value' => 1, 'form_type' => 48,
        ], range(0, 511));
        $inventory[508]['display_name'] = 'Jetpack';
        $inventory[509]['display_name'] = 'Jet';
        $inventory[510]['display_name'] = 'Stimpak';
        $inventory[511] = array_replace($inventory[510], ['display_name' => 'Modified Stimpak', 'count' => 7, 'weight' => 0.5]);
        $original = $inventory;
        foreach (['', 'Do you have any fusion cores?', "\xFF"] as $query) {
            $this->assertSame(array_slice($inventory, 0, 12), synth_protocol_select_inventory_items($inventory, $query));
        }
        $jet = synth_protocol_select_inventory_items($inventory, 'Do you have JET?');
        $this->assertSame($inventory[509], $jet[0]);
        $this->assertNotContains($inventory[508], $jet);
        $stimpaks = synth_protocol_select_inventory_items($inventory, 'Any STIMPAKS, please?');
        $this->assertSame([$inventory[510], $inventory[511]], array_slice($stimpaks, 0, 2));
        $this->assertSame($inventory[508], synth_protocol_select_inventory_items($inventory, 'Jet or 0x000011fc?')[0]);
        $this->assertSame($inventory[509], synth_protocol_select_inventory_items($inventory, str_repeat('noise ', 80).'Jet')[0]);
        $this->assertSame(array_slice($inventory, 0, 12), synth_protocol_select_inventory_items($inventory, str_repeat('x', 4096).' Jet'));
        $unicode = $inventory;
        $unicode[511]['display_name'] = 'Éclair';
        $this->assertSame($unicode[511], synth_protocol_select_inventory_items($unicode, 'ÉCLAIR!')[0]);
        $this->assertCount(12, $stimpaks);
        $this->assertSame($original, $inventory);
        $this->assertSame(array_slice($inventory, 0, 5), synth_protocol_select_inventory_items(array_slice($inventory, 0, 5), 'Jet'));
        $this->assertSame([], synth_protocol_select_inventory_items([], 'Jet'));
        $overflow = array_merge(array_slice($inventory, 0, 512), [['display_name' => 'Uncaptured beyond bound']]);
        $this->assertSame(array_slice($inventory, 0, 12), synth_protocol_select_inventory_items($overflow, 'Uncaptured beyond bound'));
    }

    public function testInventoryQuerySelectionKeepsActorAndObservationBoundaries(): void
    {
        $actor = ['form_id' => '0x00000014', 'origin_plugin' => 'Fallout4.esm', 'playthrough_id' => 'save:one'];
        $inventory = array_map(static fn(int $i): array => ['display_name' => 'Item '.$i, 'equipped' => $i < 2], range(0, 510));
        $inventory[] = ['display_name' => 'Stimpak', 'equipped' => false, 'count' => 17];
        $state = ['actor' => $actor, 'inventory' => $inventory, 'inventory_observation' => 'complete'];
        $states = [$state, array_replace($state, ['actor' => array_replace($actor, ['playthrough_id' => 'save:other'])])];
        $result = synth_protocol_prompt_actor_states($states, $actor, [], 'Stimpak');
        $this->assertSame($inventory[511], $result[0]['inventory'][0]);
        $this->assertSame(array_slice($inventory, 0, 2), $result[1]['inventory']);
        $this->assertSame(['partial', 'partial'], array_column($result, 'inventory_observation'));
        $this->assertSame($inventory, $states[0]['inventory']);
        $this->assertSame($inventory[511], synth_protocol_prompt_actor_states([$state], [], $actor, 'Stimpak')[0]['inventory'][0]);
        $db = new class($state) {
            public function __construct(private array $state) {}
            public function escapeLiteral(string $value): string { return "'".$value."'"; }
            public function fetchOne(string $sql): array
            {
                $identity = $this->state['actor'] + ['display_name' => 'Owned actor'];
                return ['context_payload' => ['player' => $identity, 'target' => $identity, 'actor_states' => [$this->state]]];
            }
        };
        foreach (['input_text', 'input_audio'] as $type) {
            $request = [];
            $query = synth_protocol_bind_dialogue_context($db, ['protocol_version' => 2, 'session_id' => 'session:test',
                'generation' => 1, 'context_sequence' => 7, 'type' => $type, 'payload' => ['text' => ' Stimpak ']], $request);
            $bound = json_decode($request[3], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('Stimpak', $query);
            $this->assertSame($inventory, $bound['actor_states'][0]['inventory']);
            $this->assertSame($inventory[511], synth_protocol_prompt_actor_states($bound['actor_states'], $actor, [], $query)[0]['inventory'][0]);
        }
        $unavailable = synth_protocol_prompt_actor_states([array_replace($state, ['inventory_observation' => 'unavailable'])], $actor, [], 'Stimpak')[0];
        $this->assertSame([], $unavailable['inventory']);
        $this->assertSame('unavailable', $unavailable['inventory_observation']);
        $empty = synth_protocol_prompt_actor_states([array_replace($state, ['inventory' => []])], $actor, [], 'Stimpak')[0];
        $this->assertSame('complete', $empty['inventory_observation']);
        $source = file_get_contents(dirname(__DIR__, 2).'/main_synth_pipeline.php');
        $this->assertStringContainsString("\$inventoryPromptText = '';", $source);
        $this->assertStringContainsString('$inventoryPromptText = synth_protocol_bind_dialogue_context($db, $pipelineEvent, $gameRequest);', $source);
        $this->assertStringContainsString("        \$targetIdentity,\n        \$inventoryPromptText", str_replace("\r\n", "\n", $source));
        $this->assertStringContainsString('An omitted item does not prove the actor lacks it.', $source);
    }

    public function testInventoryActionReadsAllCapturedStacksAndReportsObservedTotals(): void
    {
        $actor = ['form_id' => '0x0001A4D7', 'origin_plugin' => 'Fallout4.esm', 'playthrough_id' => 'save:one', 'display_name' => 'Preston'];
        $items = array_map(static fn(int $i): array => ['form_id' => sprintf('0x%08X', 4096 + $i),
            'origin_plugin' => 'Fallout4.esm', 'display_name' => 'Item '.$i, 'count' => 2147483647,
            'weight' => 1.0, 'value' => 1, 'form_type' => 48, 'equipped' => false], range(0, 511));
        $items[510] = array_replace($items[510], ['display_name' => 'Éclair', 'count' => 3]);
        $items[511] = array_replace($items[510], ['display_name' => 'Éclair modified', 'count' => 11, 'weight' => 0.5]);
        $context = ['payload' => ['actor_states' => [['actor' => $actor, 'inventory' => $items, 'inventory_observation' => 'complete']]]];
        $before = $context;
        $all = synth_protocol_inventory_action_context($context, $actor, '');
        $this->assertSame($items, $all['items']);
        $this->assertSame(512, $all['matched_stack_count']);
        $this->assertSame(510 * 2147483647 + 14, $all['observed_quantity']);
        $this->assertFalse($all['quantity_is_lower_bound']);
        foreach (['ÉCLAIR', $items[510]['form_id']] as $filter) {
            $result = synth_protocol_inventory_action_context($context, $actor, $filter);
            $this->assertSame(array_slice($items, 510), $result['items']);
            $this->assertSame(14, $result['observed_quantity']);
            $this->assertSame(512, $result['captured_stack_count']);
        }
        $this->assertSame($before, $context);
        foreach (['complete', 'partial', 'unavailable'] as $quality) {
            $context['payload']['actor_states'][0]['inventory_observation'] = $quality;
            $result = synth_protocol_inventory_action_context($context, $actor, 'No such item');
            $this->assertSame([], $result['items']);
            $this->assertSame($quality, $result['inventory_observation']);
            $this->assertSame($quality === 'unavailable' ? null : 0, $result['observed_quantity']);
            $this->assertSame($quality === 'partial', $result['quantity_is_lower_bound']);
        }
        $context['payload']['actor_states'][0]['inventory_observation'] = 'complete';
        foreach (['form_id' => '0x00000014', 'origin_plugin' => 'Other.esp', 'playthrough_id' => 'save:other'] as $key => $value) {
            $this->assertSame('unavailable', synth_protocol_inventory_action_context($context, array_replace($actor, [$key => $value]), '')['inventory_observation']);
        }
        $context['payload']['actor_states'][] = $context['payload']['actor_states'][0];
        $this->assertSame('unavailable', synth_protocol_inventory_action_context($context, $actor, '')['inventory_observation']);
        $this->assertSame('unavailable', synth_protocol_inventory_action_context([], $actor, '')['inventory_observation']);
    }

    public function testInventoryActionContinuationUsesOwnedIntentNotTruncatedClientDetail(): void
    {
        $actor = ['form_id' => '0x0001A4D7', 'origin_plugin' => 'Fallout4.esm', 'playthrough_id' => 'save:one', 'display_name' => 'Old Preston'];
        $items = array_map(static fn(int $i): array => ['form_id' => sprintf('0x%08X', 4096 + $i),
            'display_name' => 'Owned item '.$i, 'count' => 1], range(0, 511));
        $db = new class($actor, $items) {
            public array $queries = [];
            public string $status = 'complete';
            public string $filter = '';
            public string $action = 'CheckInventory';
            public string $capability = 'action.check_inventory';
            public function __construct(private array $actor, private array $items) {}
            public function escapeLiteral(string $value): string { return "'".$value."'"; }
            public function fetchOne(string $sql): ?array
            {
                $this->queries[] = $sql;
                if (str_contains($sql, 'context_snapshots')) {
                    if (!str_contains($sql, "c.session_id='session:one'") || !str_contains($sql, 'c.generation=2')
                        || !str_contains($sql, 'c.snapshot_sequence=7')) return null;
                    return ['context_payload' => ['player' => $this->actor, 'target' => $this->actor,
                        'actor_states' => [['actor' => $this->actor, 'inventory' => $this->items, 'inventory_observation' => $this->status]]]];
                }
                if (str_contains($sql,'SELECT a.full_form_id,a.origin_plugin,a.playthrough_id')) {
                    return ['full_form_id'=>$this->actor['form_id'],'origin_plugin'=>$this->actor['origin_plugin'],
                        'playthrough_id'=>$this->actor['playthrough_id']];
                }
                return ['action_name' => $this->action, 'capability' => $this->capability,
                    'arguments' => json_encode([in_array($this->action,['EquipItem','UnequipItem','Consume'],true) ? 'item' : 'target_name' => $this->filter]),
                    'target_form_id' => null, 'target_name' => 'Mutable renamed Preston', 'actor_name' => 'Mutable renamed Preston',
                    'actor_form_id' => $this->actor['form_id'], 'actor_plugin' => $this->actor['origin_plugin'], 'actor_playthrough' => $this->actor['playthrough_id']];
            }
        };
        $event = ['protocol_version' => 2, 'session_id' => 'session:one', 'generation' => 2, 'context_sequence' => 7,
            'turn_id'=>'turn:one','runtime_variant'=>'flat',
            'type' => 'action_result', 'payload' => ['result' => ['action_id' => 'action:one', 'idempotency_key' => 'idem:one',
                'status' => 'succeeded', 'detail' => 'Truncated client preview']]];
        $request = [];
        synth_protocol_bind_action_result($db, $event, $request);
        $bound = json_decode($request[3], true, flags: JSON_THROW_ON_ERROR);
        $detail = json_decode($bound['result'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($items, $detail['items']);
        $this->assertSame(7, $detail['context_sequence']);
        $this->assertSame('Old Preston', $detail['actor']['display_name']);
        $this->assertSame('succeeded', $bound['status']);
        $this->assertSame('', $bound['target'], 'An unfiltered tool call must not reconstruct the actor name as its filter');
        $db->filter = '0x000011FF';
        synth_protocol_bind_action_result($db, $event, $request);
        $this->assertSame($db->filter, json_decode($request[3], true)['target']);
        $this->assertSame([$items[511]], json_decode(json_decode($request[3], true)['result'], true)['items']);
        $db->status = 'unavailable';
        synth_protocol_bind_action_result($db, $event, $request);
        $this->assertSame('unavailable', json_decode($request[3], true)['status']);
        foreach (['failed', 'cancelled', 'unavailable', 'rejected', 'timed_out'] as $status) {
            $failed = $event; $failed['payload']['result']['status'] = $status;
            synth_protocol_bind_action_result($db, $failed, $request);
            $this->assertSame('Truncated client preview', json_decode($request[3], true)['result']);
            $this->assertSame($status, json_decode($request[3], true)['status']);
        }
        $db->action = 'Inspect';
        synth_protocol_bind_action_result($db, $event, $request);
        $this->assertSame('Truncated client preview', json_decode($request[3], true)['result']);
        $db->action = 'CheckInventory'; $db->capability = 'action.inspect_actor';
        synth_protocol_bind_action_result($db, $event, $request);
        $this->assertSame('Truncated client preview', json_decode($request[3], true)['result']);
        foreach (['EquipItem'=>'action.equip_item','UnequipItem'=>'action.unequip_item','Consume'=>'action.consume'] as $action=>$capability) {
            $db->action=$action;$db->capability=$capability;$db->filter='Rifle "modified"';
            foreach (['succeeded','failed','unavailable','rejected','cancelled','timed_out'] as $status) {
                $equipment=$event;$equipment['payload']['result']['status']=$status;
                synth_protocol_bind_action_result($db,$equipment,$request);
                $bound=json_decode($request[3],true,flags:JSON_THROW_ON_ERROR);
                $this->assertSame($db->filter,$bound['target']);
                $this->assertSame('Old Preston',$bound['actor']);
                $this->assertSame($status,$bound['status']);
                $this->assertSame('Truncated client preview',$bound['result']);
            }
            $db->filter='';
            try {synth_protocol_bind_action_result($db,$event,$request);$this->fail('Missing equipment item became the actor name');}
            catch(RuntimeException $error){$this->assertSame('action_equipment_item_unavailable',$error->getMessage());}
        }
        foreach (['session_id' => 'session:other', 'generation' => 3, 'context_sequence' => 8] as $key => $value) {
            try {
                synth_protocol_bind_action_result($db, array_replace($event, [$key => $value]), $request);
                $this->fail('A continuation cannot use a different context');
            } catch (RuntimeException $error) { $this->assertSame('dialogue_target_required', $error->getMessage()); }
        }
    }

    public function testNativeActionToolPairIdIsStableAndIsolatedByItsExactOwner(): void
    {
        $bound = ['action_id' => 'action:one', 'idempotency_key' => 'idem:one'];
        $event = ['protocol_version' => 2, 'type' => 'action_result', 'session_id' => 'session:one',
            'generation' => 2, 'turn_id' => 'turn:one', 'context_sequence' => 7, 'payload' => ['result' => $bound]];
        $id = synth_protocol_action_tool_call_id($event, $bound);
        $this->assertMatchesRegularExpression('/^call_[a-f0-9]{48}$/D', $id);
        $this->assertSame($id, synth_protocol_action_tool_call_id($event + ['request_id' => 'retry:new'], $bound));
        foreach (['session_id' => 'session:other', 'generation' => 3, 'turn_id' => 'turn:other', 'context_sequence' => 8] as $key => $value) {
            $this->assertNotSame($id, synth_protocol_action_tool_call_id(array_replace($event, [$key => $value]), $bound));
        }
        foreach (['action_id', 'idempotency_key'] as $field) {
            $other = $event;
            $other['payload']['result'][$field] .= ':other';
            $this->assertNotSame($id, synth_protocol_action_tool_call_id($other, $other['payload']['result']));
            try {
                synth_protocol_action_tool_call_id($other, $bound);
                $this->fail('Mismatched bound result cannot borrow a tool ID');
            } catch (RuntimeException $error) { $this->assertSame('action_tool_owner_mismatch', $error->getMessage()); }
        }
        foreach (['protocol_version' => 1, 'type' => 'input_text', 'session_id' => '', 'turn_id' => '', 'generation' => 0, 'context_sequence' => 0] as $key => $value) {
            try {
                synth_protocol_action_tool_call_id(array_replace($event, [$key => $value]), $bound);
                $this->fail('Missing native action owner cannot use a shared ID');
            } catch (RuntimeException $error) { $this->assertSame('action_tool_owner_required', $error->getMessage()); }
        }
    }

    public function testDialogueHistoryKeepsLiteralFieldsAndNativeListenerBoundaries(): void
    {
        require_once dirname(__DIR__, 2).'/lib/data_functions.php';
        $actor = 'Preston "A"';
        $text = "Quoted \"item\" and slash\\path\nÉclair";
        $encoded = synth_provider_dialogue_history($actor, 'Player "one"', $text);
        $this->assertSame(['character' => $actor, 'listener' => 'Player "one"', 'mood' => '',
            'action' => 'Talk', 'target' => '', 'message' => $text], json_decode($encoded, true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame(['character' => $actor], json_decode(rtrim(synth_provider_json_prefill($actor), ',').'}', true, flags: JSON_THROW_ON_ERROR));
        $suffix = '"listener":"Player","action":"Talk","message":"First chunk survived."}';
        $this->assertSame('First chunk survived.', json_decode(synth_provider_json_prefill($actor).$suffix, true, flags: JSON_THROW_ON_ERROR)['message']);
        $messages = [['role' => 'assistant', 'content' => $actor.': '.$text.' (talking to Player "one")'],
            ['role' => 'assistant', 'content' => $actor.': Next line (whispering to Nick)'],
            ['role' => 'assistant', 'content' => $encoded],
            ['role' => 'assistant', 'content' => 'Compact history', '_synth_compact_history' => true],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => []]];
        $original = $messages;
        $previousEvent = $GLOBALS['SYNTH_REQUEST_EVENT'] ?? null;
        $previousActor = $GLOBALS['SYNTH_NAME'] ?? null;
        try {
            $GLOBALS['SYNTH_NAME'] = $actor;
            $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json' => ['protocol_version' => 1]];
            $this->assertSame($messages, synth_provider_dialogue_context($messages));
            $GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version'] = 2;
            $result = synth_provider_dialogue_context($messages);
            $this->assertSame($original, $messages);
            $this->assertCount(5, $result);
            $this->assertSame($encoded, $result[0]['content']);
            $second = json_decode($result[1]['content'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('Nick', $second['listener']);
            $this->assertSame('Next line', $second['message']);
            $this->assertSame(array_slice($messages, 2), array_slice($result, 2));
            foreach (['openrouterjson', 'openaijson', 'groqjson', 'google_openaijson', 'player2json'] as $driver) {
                $source = file_get_contents(dirname(__DIR__, 2).'/connector/'.$driver.'.php');
                $this->assertStringContainsString('array_values(synth_provider_dialogue_context($contextData))', $source);
                if (in_array($driver, ['openrouterjson', 'openaijson', 'groqjson'], true)) {
                    $process = substr($source, strpos($source, 'public function process()'));
                    $this->assertLessThan(strpos($process, 'synth_provider_line('), strpos($process, '$this->_buffer = $GLOBALS["PATCH"]["PREAPPEND"];'));
                }
            }
        } finally {
            $GLOBALS['SYNTH_REQUEST_EVENT'] = $previousEvent;
            $GLOBALS['SYNTH_NAME'] = $previousActor;
        }
    }

    public function testConnectorActionHistoryEncodesLiteralFieldsAndRetainsNativeToolEvidence(): void
    {
        $actor = "Preston \"A\"\\B\nÉclair";
        $target = 'item "quoted"';
        $history = synth_provider_action_history($actor, 'Player "one"', 'CheckInventory', $target);
        $this->assertSame(['character' => $actor, 'listener' => 'Player "one"', 'mood' => '',
            'action' => 'CheckInventory', 'target' => $target, 'message' => ''], json_decode($history, true, flags: JSON_THROW_ON_ERROR));
        $previous = $GLOBALS['SYNTH_REQUEST_EVENT'] ?? null;
        try {
            $detail = json_encode(['items' => array_fill(0, 512, ['display_name' => $target, 'count' => 2])], JSON_THROW_ON_ERROR);
            foreach ([1, 2] as $version) {
                $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json' => ['protocol_version' => $version]];
                $this->assertSame('Inventory: '.$detail, synth_provider_tool_result('Inventory: #RESULT#', $detail));
                $this->assertSame($version === 2 ? "Inventory checked.\nAction result: ".$detail : 'Inventory checked.',
                    synth_provider_tool_result('Inventory checked.', $detail));
                $this->assertSame('Inventory checked.', synth_provider_tool_result('Inventory checked.', ''));
            }
        } finally {
            if ($previous === null) unset($GLOBALS['SYNTH_REQUEST_EVENT']); else $GLOBALS['SYNTH_REQUEST_EVENT'] = $previous;
        }
        foreach (['openrouterjson', 'openaijson', 'google_openaijson', 'groqjson', 'player2json'] as $driver) {
            $source = file_get_contents(dirname(__DIR__, 2).'/connector/'.$driver.'.php');
            $this->assertSame(2, substr_count($source, 'synth_provider_action_history('), $driver.' must use the same encoding for history and audit');
            $this->assertStringContainsString('synth_provider_tool_result($lastAction, strval($element["content"]), $element[\'_synth_action_status\'] ?? null)', $source);
        }
    }

    public function testNativeActionOutcomeOverridesSuccessTemplatesWithoutGuessingFromDetail(): void
    {
        $previous=$GLOBALS['SYNTH_REQUEST_EVENT'] ?? null;
        try {
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>['protocol_version'=>2]];
            foreach (['rejected','unavailable','timed_out','cancelled','failed','unsupported_without_esp','unsupported_runtime'] as $status) {
                foreach (['','Error: no observation','Looks successful, but this is untrusted detail.'] as $detail) {
                    $text=synth_provider_tool_result('Actor inspects target and sees this: #RESULT#',$detail,$status);
                    $this->assertStringContainsString('Action outcome: '.$status,$text);
                    $this->assertStringContainsString('Reported detail: '.$detail,$text);
                    $this->assertStringNotContainsString('Actor inspects target and sees this:',$text);
                }
            }
            $this->assertSame("Action outcome: succeeded\nObserved: Error-shaped literal",synth_provider_tool_result('Observed: #RESULT#','Error-shaped literal','succeeded'));
            $this->assertSame("Action outcome: succeeded\nObserved.\nAction result: detail",synth_provider_tool_result('Observed.','detail','succeeded'));
            $GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version']=1;
            $this->assertSame('Observed: detail',synth_provider_tool_result('Observed: #RESULT#','detail','failed'));
            $GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version']=2;
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('action_tool_status_invalid');
            synth_provider_tool_result('Observed.','detail','invented');
        } finally {
            if ($previous===null) unset($GLOBALS['SYNTH_REQUEST_EVENT']); else $GLOBALS['SYNTH_REQUEST_EVENT']=$previous;
        }
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testNativeTemplateContextRetainsAndPairsToolEvidenceWithoutChangingInput(): void
    {
        // The production catalog bootstraps legacy globals/classes; isolate it from other fixtures.
        ini_set('error_log', '/dev/null');
        $GLOBALS['PLAYER_NAME'] = 'Player';
        $GLOBALS['SYNTH_NAME'] = 'Preston';
        $GLOBALS['BASE_FUNCTIONS'] = [];
        $GLOBALS['db'] = null;
        require_once dirname(__DIR__, 2).'/lib/logger.php';
        restore_error_handler(); // Keep PHPUnit's handler instead of the application's handler.
        require_once dirname(__DIR__, 2).'/functions/functions.php';
        require_once dirname(__DIR__, 2).'/lib/core/narrator.class.php';
        $previous = [];
        foreach (['SYNTH_REQUEST_EVENT', 'F_RETURNMESSAGES', 'PATCH_STORE_FUNC_RES', 'SYNTH_ACTION_CATALOG_ROWS_BY_CODE'] as $key) $previous[$key] = $GLOBALS[$key] ?? null;
        $call = static fn(string $id, string $target): array => ['id' => $id, 'type' => 'function',
            'function' => ['name' => 'CheckInventory', 'arguments' => json_encode(['target' => $target], JSON_THROW_ON_ERROR)]];
        $detail = json_encode(['items' => array_fill(0, 512, ['display_name' => 'item "quoted"', 'count' => 2])], JSON_THROW_ON_ERROR);
        $messages = [['role' => 'user', 'content' => 'Check inventory.'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [$call('call_one', 'item "one"'), $call('call_two', 'item two')]],
            ['role' => 'tool', 'tool_call_id' => 'call_two', 'content' => 'second result'],
            ['role' => 'tool', 'tool_call_id' => 'call_one', 'content' => $detail],
            ['role' => 'user', 'content' => 'Describe the result.']];
        $original = $messages;
        try {
            $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json' => ['protocol_version' => 1]];
            $this->assertSame($messages, synth_provider_template_context($messages));
            $GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version'] = 2;
            foreach (['Inventory: #RESULT#', 'Inventory checked.'] as $template) {
                $GLOBALS['F_RETURNMESSAGES']['CheckInventory'] = $template;
                $GLOBALS['SYNTH_ACTION_CATALOG_ROWS_BY_CODE']['CheckInventory']['return_message'] = $template;
                $normalized = synth_provider_template_context($messages);
                $this->assertSame($original, $messages);
                $this->assertCount(6, $normalized);
                $this->assertSame(array_fill(0, 6, 'user'), array_column($normalized, 'role'));
                $this->assertStringContainsString('item \\"one\\"', $normalized[1]['content']);
                $this->assertStringContainsString('Action result for "call_two"', $normalized[3]['content']);
                $this->assertStringContainsString('second result', $normalized[3]['content']);
                $this->assertStringContainsString('Action result for "call_one"', $normalized[4]['content']);
                $this->assertStringContainsString($detail, $normalized[4]['content']);
                $this->assertStringContainsString($detail, $GLOBALS['PATCH_STORE_FUNC_RES']);
                $this->assertStringContainsString($template === 'Inventory: #RESULT#' ? 'Inventory: '.$detail : "Inventory checked.\nAction result: ".$detail, $normalized[4]['content']);
            }
            $outcomes=$messages;
            $outcomes[2]['_synth_action_status']='failed';
            $outcomes[2]['content']='';
            $outcomes[3]['_synth_action_status']='succeeded';
            $outcomes[3]['content']='Error-shaped literal observation';
            $normalized=synth_provider_template_context($outcomes);
            $this->assertStringContainsString('Action outcome: failed',$normalized[3]['content']);
            $this->assertStringNotContainsString('Inventory checked.',$normalized[3]['content']);
            $this->assertStringContainsString('Action outcome: succeeded',$normalized[4]['content']);
            $this->assertStringContainsString('Error-shaped literal observation',$normalized[4]['content']);
            $this->assertSame('',$outcomes[2]['content']);
            foreach (['orphan', 'duplicate', 'replay'] as $case) {
                $invalid = $messages;
                if ($case === 'orphan') $invalid[2]['tool_call_id'] = 'call_unknown';
                elseif ($case === 'duplicate') $invalid[1]['tool_calls'][1]['id'] = 'call_one';
                else $invalid[] = $messages[2];
                try {
                    synth_provider_template_context($invalid);
                    $this->fail('Unowned or repeated tool evidence must not enter the prompt');
                } catch (RuntimeException $error) {
                    $this->assertSame($case === 'duplicate' ? 'template_tool_call_invalid' : 'template_tool_result_unpaired', $error->getMessage());
                }
            }
        } finally {
            foreach ($previous as $key => $value) { if ($value === null) unset($GLOBALS[$key]); else $GLOBALS[$key] = $value; }
        }
    }

    public function testStrictWorldStateWritesChangedCanonicalHistoryOnly(): void
    {
        $db = new class {
            public array $queries = [];
            public array $latest = [];
            public function escapeLiteral(string $value): string { return "'".str_replace("'", "''", $value)."'"; }
            public function fetchOne(string $sql): array { return $this->latest; }
            public function execQuery(string $sql): bool { $this->queries[] = $sql; return true; }
        };
        $world = [
            'location' => 'Sanctuary Hills', 'cell' => 'SanctuaryExt',
            'worldspace' => 'Commonwealth', 'weather' => 'CommonwealthClear',
            'interior' => false, 'game_time_ticks' => 834000000000,
        ];
        $event = [
            'request_id' => 'request-context-001', 'turn_id' => 'turn-context-001',
            'generation' => 7, 'runtime_variant' => 'flat',
            'session_id' => 'session-flat-001',
            'payload' => ['playthrough_id' => 'save:fo4:001'],
        ];

        synth_protocol_persist_world_context($db, $event, $world);
        $this->assertCount(1, $db->queries);
        $this->assertStringContainsString('INSERT INTO synth.eventlog', $db->queries[0]);
        $this->assertStringContainsString('request-context-001', $db->queries[0]);

        $db->queries = [];
        $db->latest = ['party' => json_encode(
            array_merge($world, ['playthrough_id' => 'save:fo4:001', 'session_id' => 'session-flat-001', 'generation' => 7]),
            JSON_THROW_ON_ERROR
        )];
        synth_protocol_persist_world_context($db, $event, $world);
        $this->assertSame([], $db->queries);
        $event['generation'] = 8;
        synth_protocol_persist_world_context($db, $event, $world);
        $this->assertCount(1, $db->queries, 'A loaded generation needs its own world observation');
    }

    public function testNativeObservationRequiresOwnedSequenceAndClosedType(): void
    {
        $db = new class {
            public function execQuery(...$args): void { throw new LogicException('Invalid observation reached SQL'); }
        };
        foreach ([[1,1,'world_context'],[2,0,'world_context'],[2,1,'chat']] as [$version,$sequence,$type]) {
            try {
                synth_protocol_persist_observation_history($db,
                    ['protocol_version'=>$version,'payload'=>['snapshot_sequence'=>$sequence]],
                    $type,'','','',[],1);
                $this->fail('Invalid native observation accepted');
            } catch (InvalidArgumentException $error) {
                $this->assertSame('Native observation requires an owned context sequence.', $error->getMessage());
            }
        }
    }

    public function testQuestHistoryRequiresFreshExplicitObservation(): void
    {
        $quest=['origin_plugin'=>'Fallout4.esm','form_id'=>'0x00000001','display_name'=>'Quest',
            'current_stage'=>10,'active_objectives'=>1,'objectives'=>['Find the settler'],'objectives_observation'=>'complete'];
        foreach ([null,'cached','unavailable'] as $quality) {
            $payload=['active_quests'=>[$quest]];
            if ($quality !== null) $payload['active_quests_observation']=$quality;
            $this->assertNull(synth_protocol_quest_history_observation($payload));
        }
        foreach (['complete','partial'] as $quality) {
            $payload=['active_quests'=>[$quest],'active_quests_observation'=>$quality];
            $observed=synth_protocol_quest_history_observation($payload);
            $this->assertSame($quality,$observed['active_quests_observation']);
            $this->assertSame($quest+['tracked'=>null],$observed['active_quests'][0]);
            $other=array_replace($quest,['form_id'=>'0x00000002']);
            $this->assertSame(synth_protocol_quest_history_observation(array_replace($payload,['active_quests'=>[$quest,$other]])),
                synth_protocol_quest_history_observation(array_replace($payload,['active_quests'=>[$other,$quest]])));
            $empty=synth_protocol_quest_history_observation(array_replace($payload,['active_quests'=>[]]));
            $this->assertSame([], $empty['active_quests']);
            $this->assertSame($quality, $empty['active_quests_observation']);
        }
        $unknown=array_replace($quest,['objectives_observation'=>'unavailable','objectives'=>[],'active_objectives'=>0]);
        $observed=synth_protocol_quest_history_observation(['active_quests'=>[$unknown],'active_quests_observation'=>'partial']);
        $this->assertNull($observed['active_quests'][0]['active_objectives']);
        $this->assertSame([], $observed['active_quests'][0]['objectives']);
    }

    public function testNativeQuestWitnessRequiresDisplayedObjectivesAndFreshOwnershipEvidence(): void
    {
        $record=['kind'=>'stage','form_id'=>'0x000229e5','origin_plugin'=>'fallout4.esm',
            'visibility_sequence'=>0,'stage'=>20,'item'=>1,'failed'=>false];
        $quest=['form_id'=>'0x000229E5','origin_plugin'=>'Fallout4.esm','display_name'=>'Quest',
            'current_stage'=>50,'active_objectives'=>1,'objectives'=>['Find the settler'],
            'objectives_observation'=>'complete'];
        $payload=['active_quests'=>[$quest],'active_quests_observation'=>'complete'];
        $this->assertSame($quest+['tracked'=>null],synth_protocol_quest_event_witness($payload,$record));
        $batch=['batch_id'=>'quest:batch:1','events'=>[$record,$record]];
        $this->assertSame($batch,synth_protocol_validate_quest_events($batch));
        $full=['batch_id'=>'quest:full','events'=>array_fill(0,32,$record)];
        $this->assertSame($full,synth_protocol_validate_quest_events($full));
        $invalidBatches=[['batch_id'=>'empty','events'=>[]],
            ['batch_id'=>'overflow','events'=>array_fill(0,33,$record)],
            array_replace($batch,['batch_id'=>'bad batch']),array_replace($batch,['extra'=>true])];
        foreach ([['stage'=>65536],['stage'=>-1],['stage'=>true],['item'=>256],['item'=>-1],
            ['visibility_sequence'=>-1],['visibility_sequence'=>9007199254740992],
            ['form_id'=>'0x00000000'],['form_id'=>'0xFfFfFfFf'],['origin_plugin'=>'../Hidden.esm'],
            ['kind'=>'completed'],['failed'=>1],['failed'=>true],['kind'=>'started'],['extra'=>true]] as $change) {
            $invalidBatches[]=['batch_id'=>'quest:invalid','events'=>[array_replace($record,$change)]];
        }
        foreach ($invalidBatches as $invalid) {
            $code='require '.var_export(dirname(__DIR__,2).'/lib/request.php',true).';'
                .'synth_protocol_validate_quest_events('.var_export($invalid,true).'); echo "ACCEPTED";';
            $output=shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code));
            $error=json_decode(strval($output),true,flags:JSON_THROW_ON_ERROR);
            $this->assertFalse($error['ok']);
        }
        foreach ([array_replace($payload,['active_quests_observation'=>'cached']),
            array_replace($payload,['active_quests_observation'=>'unavailable']),
            array_replace($payload,['active_quests'=>[]]),
            array_replace($payload,['active_quests'=>[array_replace($quest,['active_objectives'=>0])]]),
            array_replace($payload,['active_quests'=>[array_replace($quest,['origin_plugin'=>'Other.esp'])]]),
            array_replace($payload,['active_quests'=>[array_replace($quest,['objectives_observation'=>'unavailable'])]])] as $invalid) {
            try { synth_protocol_quest_event_witness($invalid,$record); $this->fail('Unproven quest admitted'); }
            catch (DomainException $error) { $this->assertSame('quest_event_visibility_unproven',$error->getMessage()); }
        }
    }

    public function testLoadedPluginManifestUsesExactRuntimeIndices(): void
    {
        $plugin = synth_protocol_validate_loaded_plugin([
            'plugin_name' => 'Fallout4.esm', 'is_light' => false,
            'compile_index' => 0, 'small_file_compile_index' => 0,
            'partial_index' => 0, 'formid_prefix' => '00',
        ], 'payload.plugins.0');

        $this->assertSame('Fallout4.esm', $plugin['plugin_name']);
        $this->assertSame('00', $plugin['formid_prefix']);
    }

    public function testStrictDialogueTriggersBindCanonicalCommittedContext(): void
    {
        $db = new class {
            public function escapeLiteral(string $value): string { return "'".str_replace("'", "''", $value)."'"; }
            public function fetchOne(string $sql): array
            {
                return [
                    'context_payload' => json_encode([
                        'audience' => [
                            ['display_name' => 'Preston Garvey'],
                            ['display_name' => 'Dogmeat'],
                        ],
                        'actor_states' => [[
                            'actor' => [
                                'form_id' => '0x0001A4D7',
                                'origin_plugin' => 'Fallout4.esm',
                                'playthrough_id' => 'save:fo4:001',
                                'display_name' => 'Preston Garvey',
                            ],
                            'base_form_id' => '0x00019FD9',
                            'base_origin_plugin' => 'Fallout4.esm',
                            'position' => ['x' => 10.0, 'y' => 20.0, 'z' => 30.0],
                            'distance' => 125.0,
                            'alive' => true,
                            'disabled' => false,
                            'in_combat' => false,
                            'hostile_to_player' => false,
                            'sneaking' => false,
                            'level' => 10,
                            'race' => 'HumanRace',
                            'sex' => 'male',
                            'voice_type' => 'MaleBoston',
                            'teammate' => true,
                            'health_percent' => 82.5,
                            'action_points_percent' => 65.0,
                            'inventory' => [[
                                'form_id' => '0x0001F66A', 'origin_plugin' => 'Fallout4.esm',
                                'display_name' => 'Laser Musket', 'count' => 1,
                                'value' => 57, 'weight' => 8.0, 'form_type' => 41,
                                'equipped' => true,
                            ]],
                            'line_of_sight' => 'visible',
                            'factions' => [[
                                'form_id' => '0x0005DE41', 'origin_plugin' => 'Fallout4.esm',
                                'display_name' => 'MinutemenFaction',
                                'editor_id' => 'MinutemenFaction', 'rank' => 0,
                            ]],
                            'faction_observation' => 'effective',
                            'life_state' => 'alive', 'posture' => 'normal',
                            'weapon_drawn' => false, 'movement_speed' => 0.0,
                            'sprinting' => false, 'talking_to_player' => false,
                            'in_power_armor' => false,
                            'current_package' => [
                                'available' => false, 'form_id' => '', 'origin_plugin' => '',
                                'display_name' => '', 'editor_id' => '',
                            ],
                        ]],
                        'world' => [
                            'location' => 'Sanctuary Hills',
                            'cell' => 'SanctuaryExt',
                            'worldspace' => 'Commonwealth',
                            'weather' => 'CommonwealthClear',
                            'interior' => false,
                            'game_time_ticks' => 834000000000,
                        ],
                        'active_quests' => [[
                            'form_id' => '0x000229E5', 'origin_plugin' => 'Fallout4.esm',
                            'display_name' => 'When Freedom Calls', 'editor_id' => 'Min00',
                            'current_stage' => 45, 'active_objectives' => 1,
                            'objectives' => ['Join Preston Garvey in Sanctuary'],
                        ]],
                        'nearby_items' => [[
                            'reference_id' => '0xFF001234', 'reference_origin_plugin' => '',
                            'base_form_id' => '0x00004822', 'base_origin_plugin' => 'Fallout4.esm',
                            'cell_form_id' => '0x00018AA2', 'cell_origin_plugin' => 'Fallout4.esm',
                            'display_name' => '10mm Pistol',
                        ]],
                        'points_of_interest' => [[
                            'reference_id' => '0x0001A6D8',
                            'display_name' => 'Sanctuary workshop door', 'kind' => 'door',
                        ]],
                    ], JSON_THROW_ON_ERROR),
                    'player_form_id' => '0x00000014',
                    'player_plugin' => 'Fallout4.esm',
                    'playthrough_id' => 'save:fo4:001',
                    'player_name' => 'Sole Survivor',
                    'target_form_id' => '0x0001A4D7',
                    'target_plugin' => 'Fallout4.esm',
                    'target_name' => 'Preston Garvey',
                ];
            }
        };
        $event = [
            'type' => 'trigger',
            'session_id' => 'session:flat:test',
            'generation' => 7,
            'payload' => [
                'kind' => 'rechat',
                'actor' => [
                    'form_id' => '0x0001A4D7',
                    'origin_plugin' => 'Fallout4.esm',
                    'playthrough_id' => 'save:fo4:001',
                    'display_name' => 'Preston Garvey',
                ],
            ],
        ];
        $request = ['trigger', 1, 2, ''];

        synth_protocol_bind_trigger_context($db, $event, $request);

        $this->assertSame('rechat', $request[0]);
        $this->assertSame('|Preston Garvey|Dogmeat|', $request[4]);
        $payload = json_decode($request[3], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('synth.trigger.v1', $payload['schema']);
        $this->assertSame('0x0001A4D7', $payload['npc_id']);
        $this->assertSame(125, $payload['actor_states'][0]['distance']);
        $this->assertSame('MaleBoston', $payload['actor_states'][0]['voice_type']);
        $this->assertTrue($payload['actor_states'][0]['teammate']);
        $this->assertSame('MinutemenFaction', $payload['actor_states'][0]['factions'][0]['editor_id']);
        $this->assertSame('alive', $payload['actor_states'][0]['life_state']);
        $this->assertSame('Sanctuary Hills', $payload['world']['location']);
        $this->assertSame('When Freedom Calls', $payload['active_quests'][0]['display_name']);
        $this->assertSame(
            'Join Preston Garvey in Sanctuary',
            $payload['active_quests'][0]['objectives'][0]
        );
        $this->assertSame('10mm Pistol', $payload['nearby_items'][0]['display_name']);
        $this->assertSame('door', $payload['points_of_interest'][0]['kind']);

        $event['payload']['kind'] = 'bored';
        $request = ['trigger', 1, 2, ''];
        synth_protocol_bind_trigger_context($db, $event, $request);
        $this->assertSame('bored', $request[0]);

        $event['payload']['kind'] = 'combat_bark';
        $request = ['trigger', 1, 2, ''];
        synth_protocol_bind_trigger_context($db, $event, $request);
        $this->assertSame('combatbark', $request[0]);
        $payload = json_decode($request[3], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Preston Garvey', $payload['actor_states'][0]['actor']['display_name']);
    }
}
