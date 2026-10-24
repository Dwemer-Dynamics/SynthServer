<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'core'.DIRECTORY_SEPARATOR.'action_catalog.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'synth_command_payload.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'narrator_actions.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'response.php';

final class ActionCatalogTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    #[\PHPUnit\Framework\Attributes\DataProvider('nativeWaitCatalogActivationProvider')]
    public function testNativeWaitDefinitionCannotRestoreLegacyTargetOrParameterTemplate(bool $enabled): void
    {
        ini_set('error_log','/dev/null');
        $GLOBALS['PLAYER_NAME']='Nora';$GLOBALS['SYNTH_NAME']='(actor)';$GLOBALS['IS_NPC']=true;$GLOBALS['BASE_FUNCTIONS']=[];
        $event=['protocol_version'=>2,'runtime_variant'=>'flat','type'=>'input_text','payload'=>['text'=>'wait here'],
            'capabilities'=>['dialogue.turn_ownership','action.wait_here','action.release_wait']];
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$event];$GLOBALS['SYNTH_CLIENT_CAPABILITIES']=$event['capabilities'];
        $GLOBALS['SYNTH_ACTION_CATALOG_DB_READY']=true;
        $GLOBALS['db']=new class {public function fetchOne(string $query):array{return ['has_row'=>1];}};
        foreach (['WaitHere','ReleaseWait'] as $code) $GLOBALS['SYNTH_ACTION_CATALOG_ROWS_BY_CODE'][$code]=[
            'code_name'=>$code,'action_name'=>$code,'description'=>'Legacy target action.','return_message'=>'',
            'available_to_npc'=>true,'available_to_followers'=>true,'available_to_narrator'=>false,'is_activated'=>$enabled,
            'game_function'=>true,'metadata'=>['parameter_template'=>['target'=>'Foreign actor']],'script_proxy_program'=>null,
            'parameters_json'=>['type'=>'object','properties'=>['target'=>['type'=>'string']], 'required'=>['target']]];
        $before=$GLOBALS['SYNTH_ACTION_CATALOG_ROWS_BY_CODE'];
        require_once dirname(__DIR__,2).'/lib/logger.php';restore_error_handler();
        require_once dirname(__DIR__,2).'/functions/functions.php';
        foreach (['WaitHere','ReleaseWait'] as $code) {
            $definition=findFunctionByName($code);
            if (!$enabled) {$this->assertNull($definition);$this->assertNotContains($code,$GLOBALS['ENABLED_FUNCTIONS']);continue;}
            $this->assertSame([],$definition['parameters']['required']);
            $this->assertSame([],get_object_vars($definition['parameters']['properties']));
            $this->assertSame('{}',buildFunctionExecutionParameter($code,[]));
            $execution=buildFunctionExecutionContextFromResponse(['action'=>$code,'character'=>'(actor)','listener'=>'Nora',
                'message'=>'Very well.','mood'=>'normal','target'=>'','item'=>'']);
            $this->assertSame([],$execution['missing_required']);$this->assertFalse($execution['has_required_parameters']);
            $commands=[];$sent=[];$this->assertTrue(queueFunctionExecutionCommand($commands,$sent,$execution,'synthetic'));
            $this->assertSame([],synthDecodeActionLine($commands[0])['parameter_args']);
            $this->assertSame('',synthEncodeActionLine('(actor)',$code,[], '{"target":"Nora"}'));
        }
        $this->assertSame($before,$GLOBALS['SYNTH_ACTION_CATALOG_ROWS_BY_CODE'],'Runtime overrides never rewrite configured catalog rows');
    }

    public static function nativeWaitCatalogActivationProvider(): array
    {
        return ['activated legacy target schema'=>[true],'explicitly disabled'=>[false]];
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testNativeCapsCatalogCannotRestoreLegacyAmountFallback(): void
    {
        ini_set('error_log','/dev/null');
        $GLOBALS['PLAYER_NAME']='Player';$GLOBALS['SYNTH_NAME']='(actor)';$GLOBALS['IS_NPC']=true;$GLOBALS['BASE_FUNCTIONS']=[];
        $event=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/init-caps.json'),true);
        $event['capabilities']=array_merge($event['capabilities'],['action.player_caps_inventory','action.take_caps_from_player']);
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$event];$GLOBALS['SYNTH_CLIENT_CAPABILITIES']=$event['capabilities'];
        $GLOBALS['SYNTH_ACTION_CATALOG_DB_READY']=true;
        $GLOBALS['db']=new class {public function fetchOne(string $query):array{return ['has_row'=>1];}};
        $GLOBALS['SYNTH_ACTION_CATALOG_ROWS_BY_CODE']['GiveCapsTo']=['code_name'=>'GiveCapsTo','action_name'=>'Give_Caps_To',
            'description'=>'Legacy item amount fallback.','return_message'=>'','available_to_npc'=>true,'available_to_followers'=>true,
            'available_to_narrator'=>false,'is_activated'=>true,'game_function'=>true,'metadata'=>[],'script_proxy_program'=>null,
            'parameters_json'=>['type'=>'object','properties'=>['target'=>['type'=>'string'],'item'=>['type'=>'string']], 'required'=>['target']]];
        $GLOBALS['SYNTH_ACTION_CATALOG_ROWS_BY_CODE']['TakeCapsFromPlayer']=array_replace(
            $GLOBALS['SYNTH_ACTION_CATALOG_ROWS_BY_CODE']['GiveCapsTo'],['code_name'=>'TakeCapsFromPlayer','action_name'=>'Take_Caps_From_Player']);
        require_once dirname(__DIR__,2).'/lib/logger.php';restore_error_handler();
        require_once dirname(__DIR__,2).'/lib/synth_protocol_state.php';
        require_once dirname(__DIR__,2).'/functions/functions.php';
        $definition=findFunctionByName('GiveCapsTo');
        $this->assertIsArray($definition);
        $this->assertSame(['target','amount'],$definition['parameters']['required']);
        $this->assertArrayNotHasKey('item',$definition['parameters']['properties']);
        $this->assertSame(['type'=>'integer','minimum'=>1,'maximum'=>1000000],$definition['parameters']['properties']['amount']);
        $this->assertFalse($definition['parameters']['additionalProperties']);
        $this->assertStringContainsString('No item fallback',$definition['description']);
        $this->assertArrayHasKey('item',$GLOBALS['SYNTH_ACTION_CATALOG_ROWS_BY_CODE']['GiveCapsTo']['parameters_json']['properties']);
        $payment=findFunctionByName('TakeCapsFromPlayer');
        $this->assertSame($definition['parameters'],$payment['parameters']);
        $this->assertStringContainsString('only after the player agrees',$payment['description']);
        $this->assertArrayHasKey('item',$GLOBALS['SYNTH_ACTION_CATALOG_ROWS_BY_CODE']['TakeCapsFromPlayer']['parameters_json']['properties']);
        $GLOBALS['SYNTH_ACTION_CATALOG_ROWS_BY_CODE']['TakeCapsFromPlayer']['return_message']='#PLAYER_NAME# gave #TARGET# caps to #SYNTH_NAME#';
        $this->assertSame('Player gave 2 caps to (actor)',synthFormatReturnMessageTemplate('TakeCapsFromPlayer',['target'=>'Player','amount'=>2]));
        $GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version']=1;
        $this->assertSame('Player gave 3 caps to (actor)',synthFormatReturnMessageTemplate('TakeCapsFromPlayer','3'));
        $GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version']=2;
        // A read-only rechat filter must not be bypassed by the database lookup.
        $GLOBALS['FUNCTIONS']=[];
        $this->assertNull(findFunctionByName('GiveCapsTo'));
        $this->assertNull(findFunctionByName('TakeCapsFromPlayer'));
    }

    public function testPlayerPaymentContractCannotReuseNpcGivingCapsOrNpcFunds(): void
    {
        require_once dirname(__DIR__,2).'/lib/request.php';
        require_once dirname(__DIR__,2).'/lib/synth_protocol_state.php';
        $event=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/init-player-caps.json'),true);
        $this->assertTrue(synth_protocol_supports_player_caps($event));
        $this->assertSame($event,synth_protocol_validate_event($event));
        foreach ($event['capabilities'] as $missing) {
            $bad=$event;$bad['capabilities']=array_values(array_diff($event['capabilities'],[$missing]));
            $this->assertFalse(synth_protocol_supports_player_caps($bad),$missing);
        }
        foreach (['init-player-caps-no-action','init-player-caps-no-caps','init-player-caps-vr'] as $fixture) {
            $bad=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/invalid/'.$fixture.'.json'),true);
            $this->assertFalse(synth_protocol_supports_player_caps($bad));
        }
        $actor=['form_id'=>'0x0001A4D7','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'save:payment'];
        $player=array_replace($actor,['form_id'=>'0x00000014']);
        $action=['name'=>'take_caps_from_player','capability'=>'action.take_caps_from_player','server_code'=>'TakeCapsFromPlayer',
            'actor'=>$actor,'target'=>$player,'arguments'=>['amount'=>2]];
        $this->assertTrue(synth_protocol_valid_transfer_intent($action));
        foreach (['npc-target','player-performer','foreign-target','wrong-code','wrong-capability'] as $case) {
            $bad=$action;
            if($case==='npc-target')$bad['target']['form_id']='0x00000016';
            if($case==='player-performer')$bad['actor']=$player;
            if($case==='foreign-target')$bad['target']['origin_plugin']='Other.esm';
            if($case==='wrong-code')$bad['server_code']='GiveCapsTo';
            if($case==='wrong-capability')$bad['capability']='action.give_caps_to';
            $this->assertFalse(synth_protocol_valid_transfer_intent($bad),$case);
        }
        $item=['form_id'=>'0x0000000F','origin_plugin'=>'Fallout4.esm','form_type'=>35,'display_name'=>'Caps',
            'count'=>5,'equipped'=>false,'weight'=>0,'value'=>1];
        $context=['payload'=>['actor_states'=>[['actor'=>$actor,'inventory'=>[$item],'inventory_observation'=>'complete'],
            ['actor'=>$player,'inventory'=>[],'inventory_observation'=>'unavailable']]]];
        $this->assertTrue(synth_protocol_caps_inventory_allows($context,$actor,2));
        $this->assertFalse(synth_protocol_caps_inventory_allows($context,$player,2));
        $context['payload']['actor_states'][1]['inventory']=[$item];$context['payload']['actor_states'][1]['inventory_observation']='partial';
        $this->assertTrue(synth_protocol_caps_inventory_allows($context,$player,5));
        $this->assertFalse(synth_protocol_caps_inventory_allows($context,$player,6));
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testDatabaseCatalogCannotRestoreUnnegotiatedNativeActions(): void
    {
        ini_set('error_log', '/dev/null');
        $GLOBALS['PLAYER_NAME'] = 'Player';
        $GLOBALS['SYNTH_NAME'] = '(actor)';
        $GLOBALS['IS_NPC'] = true;
        $GLOBALS['BASE_FUNCTIONS'] = [];
        $GLOBALS['SYNTH_CLIENT_CAPABILITIES'] = ['dialogue.text', 'action.end_conversation'];
        $GLOBALS['SYNTH_ACTION_CATALOG_DB_READY'] = true;
        $GLOBALS['db'] = new class {
            public function fetchOne(string $query): array { return ['has_row' => 1]; }
        };
        foreach (['EndConversation' => 'End_Conversation', 'Inspect' => 'Inspect'] as $code => $name) {
            $GLOBALS['SYNTH_ACTION_CATALOG_ROWS_BY_CODE'][$code] = [
                'code_name' => $code, 'action_name' => $name, 'description' => 'Synthetic database action.',
                'return_message' => '', 'available_to_npc' => true, 'available_to_followers' => true,
                'available_to_narrator' => false, 'is_activated' => true, 'game_function' => true,
                'metadata' => [], 'script_proxy_program' => null,
                'parameters_json' => ['type' => 'object', 'properties' => [], 'required' => []],
            ];
        }
        require_once dirname(__DIR__, 2).'/lib/logger.php';
        restore_error_handler();
        require_once dirname(__DIR__, 2).'/functions/functions.php';

        // Prove the database merge restored Inspect, then the final runtime boundary removed it.
        $this->assertArrayHasKey('Inspect', $GLOBALS['BASE_FUNCTIONS']);
        $this->assertSame(['EndConversation'], $GLOBALS['ENABLED_FUNCTIONS']);
        $this->assertSame(['End_Conversation'], array_column($GLOBALS['FUNCTIONS'], 'name'));
        $this->assertSame(['EndConversation'], $GLOBALS['DEFINED_FUNCTIONS']);
        $this->assertTrue(synthActionCodeIsNegotiated('EndConversation'));
        $this->assertFalse(synthActionCodeIsNegotiated('Inspect'));
        $GLOBALS['SYNTH_CLIENT_CAPABILITIES'] = ['action.inspect_actor'];
        $this->assertTrue(synthActionCodeIsNegotiated('Inspect'));
        $this->assertFalse(synthActionCodeIsNegotiated('EndConversation'));
        $GLOBALS['SYNTH_CLIENT_CAPABILITIES'] = ['dialogue.text'];
        $this->assertFalse(synthActionCodeIsNegotiated('EndConversation'));
        $this->assertFalse(synthActionCodeIsNegotiated('EquipItem'));
        $this->assertFalse(synthActionCodeIsNegotiated('UnequipItem'));
        $GLOBALS['SYNTH_CLIENT_CAPABILITIES'] = ['action.equip_item'];
        $this->assertTrue(synthActionCodeIsNegotiated('EquipItem'));
        $this->assertFalse(synthActionCodeIsNegotiated('UnequipItem'));
        $GLOBALS['SYNTH_CLIENT_CAPABILITIES'] = ['action.unequip_item'];
        $this->assertFalse(synthActionCodeIsNegotiated('EquipItem'));
        $this->assertTrue(synthActionCodeIsNegotiated('UnequipItem'));
        require_once dirname(__DIR__,2).'/lib/synth_protocol_state.php';
        $GLOBALS['SYNTH_CLIENT_CAPABILITIES']=['action.consume'];
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>['protocol_version'=>2,'runtime_variant'=>'flat','capabilities'=>['action.consume']]];
        $this->assertFalse(synthActionCodeIsNegotiated('Consume'));
        $GLOBALS['SYNTH_CLIENT_CAPABILITIES']=['action.give_item_to'];
        $base=['protocol_version'=>2,'runtime_variant'=>'flat','capabilities'=>
            ['action.transfer_inventory','action.give_item_to','action.inventory_observation','dialogue.turn_ownership']];
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$base];
        $this->assertTrue(synthActionCodeIsNegotiated('GiveItemTo'));
        foreach ($base['capabilities'] as $missing) {
            $GLOBALS['SYNTH_REQUEST_EVENT']['json']['capabilities']=array_values(array_diff($base['capabilities'],[$missing]));
            $this->assertFalse(synthActionCodeIsNegotiated('GiveItemTo'));
        }
        foreach (['vr','v1'] as $lane) {
            $GLOBALS['SYNTH_REQUEST_EVENT']['json']=$base;
            if($lane==='vr')$GLOBALS['SYNTH_REQUEST_EVENT']['json']['runtime_variant']='vr';
            else $GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version']=1;
            $this->assertFalse(synthActionCodeIsNegotiated('GiveItemTo'));
        }
        $GLOBALS['SYNTH_CLIENT_CAPABILITIES']=['action.give_caps_to'];
        $caps=$base;$caps['capabilities']=array_merge($base['capabilities'],['action.give_caps_to','action.caps_inventory']);
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$caps];
        $this->assertTrue(synthActionCodeIsNegotiated('GiveCapsTo'));
        foreach ($caps['capabilities'] as $missing) {
            $GLOBALS['SYNTH_REQUEST_EVENT']['json']['capabilities']=array_values(array_diff($caps['capabilities'],[$missing]));
            $this->assertFalse(synthActionCodeIsNegotiated('GiveCapsTo'),$missing);
        }
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$caps];$GLOBALS['SYNTH_REQUEST_EVENT']['json']['runtime_variant']='vr';
        $this->assertFalse(synthActionCodeIsNegotiated('GiveCapsTo'));
        $GLOBALS['SYNTH_CLIENT_CAPABILITIES']=['action.consume'];
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>['protocol_version'=>2,'runtime_variant'=>'flat','capabilities'=>['action.consume']]];
        $GLOBALS['SYNTH_REQUEST_EVENT']['json']['capabilities']=['action.consume','action.consume_inventory','action.inventory_observation','dialogue.turn_ownership'];
        $this->assertTrue(synthActionCodeIsNegotiated('Consume'));
        $GLOBALS['SYNTH_REQUEST_EVENT']['json']['runtime_variant']='vr';
        $this->assertFalse(synthActionCodeIsNegotiated('Consume'));
        $rechat=$base;$rechat['type']='trigger';$rechat['payload']=['kind'=>'rechat'];
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$rechat];
        $GLOBALS['SYNTH_CLIENT_CAPABILITIES']=['action.give_item_to','action.inspect_actor'];
        $this->assertFalse(synthActionCodeIsNegotiated('GiveItemTo'));
        $this->assertTrue(synthActionCodeIsNegotiated('Inspect'));
        $this->assertNull(findFunctionByName('GiveItemTo'));
        // Changing the capability fixture does not rebuild the already-filtered
        // runtime list. A cached database row must not restore a missing native
        // definition, even when that action is negotiated by the next request.
        $this->assertNull(findFunctionByName('Inspect'));
        $nativeInspect = synthNativeObservationActionDefinition('Inspect', $GLOBALS['BASE_FUNCTIONS']['Inspect'], 2);
        $GLOBALS['FUNCTIONS'] = [$nativeInspect];
        $GLOBALS['ENABLED_FUNCTIONS'] = ['Inspect'];
        $GLOBALS['DEFINED_FUNCTIONS'] = ['Inspect'];
        $GLOBALS['BASE_FUNCTIONS']['Inspect'] = $nativeInspect;
        $GLOBALS['F_DESCRIPTIONS']['Inspect'] = $nativeInspect['description'];
        $this->assertSame($nativeInspect, findFunctionByName('Inspect'));
        $this->assertStringContainsString('does not refresh weapon or clothing', findFunctionByName('Inspect')['description']);
        $this->assertSame('Synthetic database action.', $GLOBALS['SYNTH_ACTION_CATALOG_ROWS_BY_CODE']['Inspect']['description']);
        $this->assertNull(synth_protocol_action_from_legacy_line(['command_name'=>'GiveItemTo','speaker'=>'Piper','target'=>'Player','item'=>'Rifle']));
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$base];
        foreach (['GiveItemTo','GiveCapsTo','TakeCapsFromPlayer'] as $transferName) {
            foreach ([1,1000000,1.5,1.0,true,null,'2',0,-1,1000001] as $amount) {
                $parameters=['target'=>'Fixture Settler','amount'=>$amount]+($transferName==='GiveItemTo'?['item'=>'Rifle']:[]);
                $context=buildFunctionExecutionContextFromResponse(['action'=>$transferName,
                    'message'=>'Speech is not an action argument.']+$parameters);
                $this->assertSame($amount,$context['parameter_value']['amount']);
                $context['function_found']=true;
                $buffer=[];$sent=[];$valid=is_int($amount) && $amount>=1 && $amount<=1000000;
                $this->assertSame($valid,queueFunctionExecutionCommand($buffer,$sent,$context,'fixture','Piper'));
                $this->assertCount($valid?1:0,$buffer);
                $this->assertSame($valid,synthEncodeActionLine('Piper',$transferName,$parameters)!=='');
            }
        }
        foreach ([['target'=>'Player'],['target'=>'Player','item'=>'2'],['target'=>'Player','amount'=>2,'item'=>''],
            ['target'=>'Player','amount'=>2,'location'=>'Elsewhere'],['target'=>'','amount'=>2]] as $invalid)
            $this->assertSame('',synthEncodeActionLine('Piper','GiveCapsTo',$invalid));
        $placeholder=buildFunctionExecutionContextFromResponse(['action'=>'GiveCapsTo','target'=>'Player','amount'=>2,'item'=>'']);
        $this->assertSame(['target'=>'Player','amount'=>2],$placeholder['parameter_value']);
        $nonempty=buildFunctionExecutionContextFromResponse(['action'=>'GiveCapsTo','target'=>'Player','amount'=>2,'item'=>' ']);
        $this->assertFalse(synthNativeTransferParametersValid($nonempty['parameter_value'],true));
        $this->assertSame('',synthEncodeActionLine('Piper','GiveItemTo',['target'=>'Fixture Settler','item'=>'Rifle','location'=>'Elsewhere']));
        $this->assertNotSame('',synthEncodeActionLine('Piper','GiveItemTo',['target'=>'Fixture Settler','item'=>'Rifle']));
        $GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version']=1;
        $this->assertNotSame('',synthEncodeActionLine('Piper','GiveItemTo',['target'=>'Fixture Settler','item'=>'Rifle','amount'=>1.5]));
        $this->assertNotSame('',synthEncodeActionLine('Piper','GiveCapsTo',['target'=>'Player','item'=>'2']));
    }

    public function testCapsIntentRequiresExactObservedCurrencyAndIntegerAmount(): void
    {
        require_once dirname(__DIR__,2).'/lib/request.php';
        require_once dirname(__DIR__,2).'/lib/synth_protocol_state.php';
        $actor=['form_id'=>'0x0001A4D7','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'save:caps'];
        $target=array_replace($actor,['form_id'=>'0x00000014']);
        $action=['name'=>'give_caps_to','capability'=>'action.give_caps_to','server_code'=>'GiveCapsTo',
            'actor'=>$actor,'target'=>$target,'arguments'=>['amount'=>2]];
        $item=['form_id'=>'0x0000000F','origin_plugin'=>'Fallout4.esm','form_type'=>35,'display_name'=>'Kronkorken',
            'count'=>10,'equipped'=>false,'weight'=>0,'value'=>1];
        $context=['payload'=>['actor_states'=>[['actor'=>$actor,'inventory'=>[$item],'inventory_observation'=>'partial']]]];
        $this->assertTrue(synth_protocol_valid_transfer_intent($action));
        $this->assertTrue(synth_protocol_caps_inventory_allows($context,$actor,10));
        $this->assertFalse(synth_protocol_caps_inventory_allows($context,$actor,11));
        $previous=$GLOBALS['SYNTH_REQUEST_EVENT']??null;
        $event=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/init-caps.json'),true);
        $event['type']='trigger';$event['payload']=['kind'=>'rechat'];$event['capabilities'][]='dialogue.rechat.ownership';
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$event];
        try {
            synth_protocol_record_action_intent(new stdClass(),$action);
            $this->fail('Direct rechat caps mutation reached persistence');
        } catch (DomainException $error) {
            $this->assertSame('caps_rechat_mutation_not_allowed',$error->getMessage());
        } finally {$GLOBALS['SYNTH_REQUEST_EVENT']=$previous;}
        foreach ([[],['amount'=>'2'],['amount'=>2.0],['amount'=>true],['amount'=>null],['amount'=>0],['amount'=>1000001],
            ['amount'=>2,'item'=>'Caps'],['amount'=>2,'target_name'=>'Player']] as $arguments)
            $this->assertFalse(synth_protocol_valid_transfer_intent(array_replace($action,['arguments'=>$arguments])));
        foreach (['self','player-donor','other-save','wrong-code','wrong-capability'] as $case) {
            $bad=$action;
            if($case==='self')$bad['target']=$actor;
            if($case==='player-donor')$bad['actor']=$target;
            if($case==='other-save')$bad['target']['playthrough_id']='other:save';
            if($case==='wrong-code')$bad['server_code']='GiveItemTo';
            if($case==='wrong-capability')$bad['capability']='action.give_item_to';
            $this->assertFalse(synth_protocol_valid_transfer_intent($bad),$case);
        }
        foreach (['unknown','absent','duplicate','ambiguous-actor','wrong-actor','wrong-save','plugin','form','type','equipped','fraction','overflow','label-match'] as $case) {
            $bad=$context;$row=&$bad['payload']['actor_states'][0];
            if($case==='unknown')$row['inventory_observation']='unavailable';
            if($case==='absent')$row['inventory']=[];
            if($case==='duplicate')$row['inventory'][]=$item;
            if($case==='ambiguous-actor')$bad['payload']['actor_states'][]=$row;
            if($case==='wrong-actor')$row['actor']['form_id']='0xFF000001';
            if($case==='wrong-save')$row['actor']['playthrough_id']='other:save';
            if($case==='plugin')$row['inventory'][0]['origin_plugin']='Other.esp';
            if($case==='form')$row['inventory'][0]['form_id']='0x00000010';
            if($case==='type')$row['inventory'][0]['form_type']=43;
            if($case==='equipped')$row['inventory'][0]['equipped']=true;
            if($case==='fraction')$row['inventory'][0]['count']=10.0;
            if($case==='overflow')$row['inventory'][0]['count']=2147483648;
            if($case==='label-match'){$row['inventory'][0]['form_id']='0x00000010';$row['inventory'][0]['display_name']='0x0000000F';}
            $this->assertFalse(synth_protocol_caps_inventory_allows($bad,$actor,2),$case);
            unset($row);
        }
    }

    public function testRechatMutationIsRejectedBeforeIntentOrEmissionDatabaseAccess(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_protocol_state.php';
        require_once dirname(__DIR__,2).'/lib/synth_rechat_emission.php';
        foreach (['CheckInventory','Inspect','InspectSurroundings','ReadQuests','EndConversation'] as $code)
            $this->assertTrue(synth_rechat_action_code_allowed($code));
        foreach (['GiveItemTo'=>'give_item_to','GiveCapsTo'=>'give_caps_to','Consume'=>'consume','EquipItem'=>'equip_item',
            'Attack'=>'attack','MoveTo'=>'move_to','SpawnItem'=>'spawn_item','Unknown'=>'unknown'] as $code=>$name) {
            $this->assertFalse(synth_rechat_action_code_allowed($code));
            foreach ([false,true] as $emission) {
                try {
                    synth_rechat_assert_action_emission(new stdClass(),[],['name'=>$name],$emission);
                    $this->fail('Rechat mutation reached database access');
                } catch (LogicException $error) {$this->assertSame('rechat_action_not_allowed',$error->getMessage());}
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testEquipmentCommandArgumentIsAnItemNotAnActorTarget(): void
    {
        $GLOBALS['SYNTH_RESPONSE_STREAMING'] = false;
        foreach (['EquipItem', 'UnequipItem', 'Consume'] as $action) {
            foreach ([[], ['item' => '0x00000123:Combat rifle']] as $metadata) {
                $GLOBALS['SYNTH_JSON_RESPONSE_LINES'] = [];
                synth_buffer_command_response_line('Piper',
                    synthEncodeCommandAction($action, ['0x00000123:Combat rifle']), $metadata);
                $this->assertCount(1, $GLOBALS['SYNTH_JSON_RESPONSE_LINES']);
                $line = $GLOBALS['SYNTH_JSON_RESPONSE_LINES'][0];
                $this->assertSame($action, $line['command_name']);
                $this->assertSame('0x00000123:Combat rifle', $line['item']);
                $this->assertSame('', $line['target'] ?? '');
                $this->assertSame('', $line['amount'] ?? '');
            }
        }
    }

    public function testCommandPayloadsRequireSYNTHJsonEnvelope(): void
    {
        $payload = synthEncodeCommandAction('Attack', ['target' => 'Raider']);
        $decoded = synthDecodeCommandAction($payload);

        $this->assertSame('Attack', $decoded['command_name']);
        $this->assertSame(['Raider'], $decoded['command_args']);

        $oldFormat = synthDecodeCommandAction('Attack@Raider');
        $this->assertSame('', $oldFormat['command_name']);
        $this->assertSame([], $oldFormat['command_args']);
    }

    public function testTransferIntentRejectsUnnormalizedArgumentsBeforeWrites(): void
    {
        require_once dirname(__DIR__,2).'/lib/request.php';
        require_once dirname(__DIR__,2).'/lib/synth_protocol_state.php';
        $actor=['form_id'=>'0x0001A4D7','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'save:one'];
        $target=array_replace($actor,['form_id'=>'0x00000014']);
        $action=['name'=>'give_item_to','capability'=>'action.give_item_to','actor'=>$actor,'target'=>$target,
            'arguments'=>['item'=>'Rifle','amount'=>1]];
        $this->assertTrue(synth_protocol_valid_transfer_intent($action));
        $this->assertTrue(synth_protocol_valid_transfer_intent(array_replace($action,['arguments'=>(object)$action['arguments']])));
        $omitted=$action;unset($omitted['arguments']['amount']);$this->assertTrue(synth_protocol_valid_transfer_intent($omitted));
        $previous=$GLOBALS['SYNTH_REQUEST_EVENT']??null;
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>['protocol_version'=>2,'runtime_variant'=>'flat','capabilities'=>
            ['action.transfer_inventory','action.give_item_to','action.inventory_observation','dialogue.turn_ownership']]];
        try {
            foreach ([0,-1,1000001,1.5,1.0,'1',true,null] as $amount) {
                $bad=$action;$bad['arguments']['amount']=$amount;
                $this->assertFalse(synth_protocol_valid_transfer_intent($bad));
                try{synth_protocol_record_action_intent(new stdClass(),$bad);$this->fail('Invalid quantity reached persistence');}
                catch(DomainException $error){$this->assertSame('invalid_transfer_intent',$error->getMessage());}
            }
            foreach (['self','player-donor','other-save','bad-plugin','extra','blank-item','long-item','utf8','wrong-code'] as $case) {
                $bad=$action;
                if($case==='self')$bad['target']=$actor;
                if($case==='player-donor')$bad['actor']=$target;
                if($case==='other-save')$bad['target']['playthrough_id']='other:save';
                if($case==='bad-plugin')$bad['target']['origin_plugin']='../Other.esp';
                if($case==='extra')$bad['arguments']['target_name']='Player';
                if($case==='blank-item')$bad['arguments']['item']='  ';
                if($case==='long-item')$bad['arguments']['item']=str_repeat('a',256);
                if($case==='utf8')$bad['arguments']['item']="\xff";
                if($case==='wrong-code')$bad['server_code']='EquipItem';
                $this->assertFalse(synth_protocol_valid_transfer_intent($bad),$case);
            }
        } finally {$GLOBALS['SYNTH_REQUEST_EVENT']=$previous;}
    }

    public function testActionPayloadsExposeOrderedJsonParameters(): void
    {
        $action = synthEncodeActionLine('Veronica', 'GiveItemTo', [
            'target' => 'Graussy',
            'item' => 'Trail Carbine',
            'amount' => 1,
        ]);
        $decodedAction = synthDecodeActionLine($action);

        $this->assertSame('synth.action.v1', $decodedAction['schema']);
        $this->assertSame('GiveItemTo', $decodedAction['action']);
        $this->assertSame(['Graussy', 'Trail Carbine', '1'], $decodedAction['parameter_args']);

        $command = synthEncodeCommandAction($decodedAction['action'], $decodedAction['parameter_args']);
        $decodedCommand = synthDecodeCommandAction($command);

        $this->assertSame('GiveItemTo', $decodedCommand['command_name']);
        $this->assertSame(['Graussy', 'Trail Carbine', '1'], $decodedCommand['command_args']);
    }

    public function testNarratorMutationPayloadsKeepStructuredAuthorityAndOrdering(): void
    {
        $GLOBALS['PLAYER_NAME'] = 'Graussy';
        $prepared = synthPrepareNarratorPluginAction('SpawnCaps', [
            'target' => 'me',
            'amount' => 125,
        ]);

        $this->assertIsArray($prepared);
        $this->assertSame('narrator', $prepared['action_source']);
        $this->assertSame('narrator', $prepared['authority']);
        $this->assertSame('Graussy', $prepared['target']);
        $this->assertSame('0x00000014', $prepared['target_refid']);
        $this->assertSame(125, $prepared['amount']);

        $action = synthEncodeActionLine('The Narrator', 'SpawnCaps', $prepared);
        $decoded = synthDecodeActionLine($action);
        $this->assertSame(['Graussy', '125'], $decoded['parameter_args']);
    }

    public function testNarratorAuthorityAndResolvedFormsReachPluginResponseEnvelope(): void
    {
        $GLOBALS['SYNTH_JSON_RESPONSE_LINES'] = [];
        $GLOBALS['SYNTH_RESPONSE_STREAMING'] = false;
        $command = synthEncodeCommandAction('SpawnItem', ['Graussy', '10mm Pistol', '1']);
        synth_buffer_command_response_line('The Narrator', $command, [
            'target' => 'Graussy',
            'target_refid' => '0x00000014',
            'item' => '10mm Pistol',
            'item_baseid' => '0x00004822',
            'item_plugin' => 'Fallout4.esm',
            'item_stable_key' => 'Fallout4.esm:00004822',
            'action_source' => 'narrator',
            'authority' => 'narrator',
        ]);

        $this->assertCount(1, $GLOBALS['SYNTH_JSON_RESPONSE_LINES']);
        $line = $GLOBALS['SYNTH_JSON_RESPONSE_LINES'][0];
        $this->assertSame('synth.response.line.v1', $line['schema']);
        $this->assertSame('The Narrator', $line['speaker']);
        $this->assertSame('SpawnItem', $line['command_name']);
        $this->assertSame(['Graussy', '10mm Pistol', '1'], $line['command_args']);
        $this->assertSame('0x00000014', $line['target_refid']);
        $this->assertSame('0x00004822', $line['item_baseid']);
        $this->assertSame('Fallout4.esm:00004822', $line['item_stable_key']);
        $this->assertSame('narrator', $line['action_source']);
        $this->assertSame('narrator', $line['authority']);
    }

    public function testNarratorKillTargetAllowsPlayerAliasesButStillRequiresTarget(): void
    {
        $GLOBALS['PLAYER_NAME'] = 'Graussy';
        $namedPlayer = synthPrepareNarratorPluginAction('KillTarget', ['target' => 'Graussy']);
        $playerAlias = synthPrepareNarratorPluginAction('KillTarget', ['target' => 'player']);

        $this->assertSame('Graussy', $namedPlayer['target']);
        $this->assertSame('0x00000014', $namedPlayer['target_refid']);
        $this->assertSame('Graussy', $playerAlias['target']);
        $this->assertSame('0x00000014', $playerAlias['target_refid']);
        $this->assertNull(synthPrepareNarratorPluginAction('KillTarget', ['target' => '']));
    }

    public function testBaseSeedFileDefinesActionAvailabilityAndActivation(): void
    {
        $expectedCodes = [
            'Attack',
            'Barter',
            'CheckInventory',
            'ComeCloser',
            'Consume',
            'EquipItem',
            'DecreaseWalkSpeed',
            'EndConversation',
            'Follow',
            'FollowPlayer',
            'GiveCapsTo',
            'GiveItemTo',
            'IncreaseWalkSpeed',
            'Inspect',
            'InspectSurroundings',
            'MakeFollower',
            'MoveTo',
            'OpenInventory',
            'PickupItem',
            'ReadQuests',
            'ReleaseWait',
            'Relax',
            'DirectorCommand',
            'SpawnCaps',
            'SpawnItem',
            'TeleportActor',
            'KillTarget',
            'SheatheWeapon',
            'StopFollowing',
            'StopWalk',
            'TakeASeat',
            'TakeCapsFromPlayer',
            'TravelTo',
            'UnequipItem',
            'WaitHere',
        ];
        $rows = synthLoadActionCatalogBaseSeedRowsFromSeedFile();
        $actualCodes = array_keys($rows);
        sort($expectedCodes);
        sort($actualCodes);

        $this->assertSame($expectedCodes, $actualCodes);
        $this->assertTrue($rows['ReleaseWait']['available_to_npc']);
        $this->assertTrue($rows['ReleaseWait']['available_to_followers']);
        $this->assertFalse($rows['ReleaseWait']['available_to_narrator']);
        $disabledNarratorActions = ['DirectorCommand', 'SpawnCaps', 'SpawnItem', 'TeleportActor', 'KillTarget'];
        foreach ($expectedCodes as $codeName) {
            $this->assertSame(!in_array($codeName, $disabledNarratorActions, true), $rows[$codeName]['is_activated']);
        }

        $this->assertTrue($rows['Inspect']['available_to_npc']);
        $this->assertTrue($rows['Inspect']['available_to_followers']);
        $this->assertFalse($rows['Inspect']['available_to_narrator']);

        $this->assertTrue($rows['InspectSurroundings']['available_to_npc']);
        $this->assertTrue($rows['InspectSurroundings']['available_to_followers']);
        $this->assertFalse($rows['InspectSurroundings']['available_to_narrator']);

        $this->assertTrue($rows['ReadQuests']['available_to_narrator']);
        foreach ($disabledNarratorActions as $codeName) {
            $this->assertTrue($rows[$codeName]['available_to_narrator']);
            $this->assertFalse($rows[$codeName]['available_to_npc']);
            $this->assertFalse($rows[$codeName]['available_to_followers']);
        }
        $this->assertFalse($rows['SheatheWeapon']['available_to_npc']);
        $this->assertTrue($rows['SheatheWeapon']['available_to_followers']);
        $this->assertFalse($rows['StopWalk']['available_to_npc']);
        $this->assertFalse($rows['StopWalk']['available_to_followers']);
        $this->assertFalse($rows['StopWalk']['available_to_narrator']);
    }

    public function testBuildActionCatalogSeedRows_AssignsScopesAndSkipsNonCanonicalActions(): void
    {
        $seedDefaultsByCode = [
            'MoveTo' => [
                'available_to_npc' => true,
                'available_to_followers' => false,
                'available_to_narrator' => false,
                'is_activated' => true,
            ],
            'ReadQuests' => [
                'available_to_npc' => true,
                'available_to_followers' => true,
                'available_to_narrator' => true,
                'is_activated' => true,
            ],
            'ObsoleteAction' => [
                'available_to_npc' => true,
                'available_to_followers' => true,
                'available_to_narrator' => false,
                'is_activated' => true,
            ],
        ];

        $rows = synthBuildActionCatalogSeedRows(
            [
                'MoveTo' => 'MoveTo',
                'ReadQuests' => 'ReadQuests',
                'ObsoleteAction' => 'ObsoleteAction',
            ],
            [],
            [],
            [],
            [],
            [
                'MoveTo' => [
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'target' => ['type' => 'string'],
                        ],
                        'required' => ['target'],
                    ],
                ],
                'ReadQuests' => [
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'id_quest' => ['type' => 'string'],
                        ],
                        'required' => [],
                    ],
                ],
            ],
            $seedDefaultsByCode
        );

        $this->assertArrayNotHasKey('ObsoleteAction', $rows);
        $this->assertTrue($rows['MoveTo']['available_to_npc']);
        $this->assertFalse($rows['MoveTo']['available_to_followers']);
        $this->assertFalse($rows['MoveTo']['available_to_narrator']);
        $this->assertTrue($rows['MoveTo']['is_activated']);

        $this->assertTrue($rows['ReadQuests']['available_to_npc']);
        $this->assertTrue($rows['ReadQuests']['available_to_followers']);
        $this->assertTrue($rows['ReadQuests']['available_to_narrator']);
        $this->assertTrue($rows['ReadQuests']['is_activated']);
    }

    public function testBuildActionCatalogSeedRows_SeedsParametersMetadataAndScriptProxyProgram(): void
    {
        $rows = synthBuildActionCatalogSeedRows(
            [
                'MoveTo' => 'MoveTo',
                'CheckInventory' => 'CheckInventory',
                'ReadQuests' => 'ReadQuests',
                'GiveCapsTo' => 'GiveCapsTo',
            ],
            [],
            [],
            [],
            [],
            [
                'MoveTo' => [
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'target' => ['type' => 'string'],
                        ],
                        'required' => ['target'],
                    ],
                ],
                'CheckInventory' => [
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'target' => ['type' => 'string'],
                        ],
                        'required' => [],
                    ],
                ],
                'ReadQuests' => [
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'id_quest' => ['type' => 'string'],
                        ],
                        'required' => [],
                    ],
                ],
                'GiveCapsTo' => [
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'target' => ['type' => 'string'],
                            'amount' => ['type' => 'integer'],
                        ],
                        'required' => ['target'],
                    ],
                ],
            ]
        );

        $this->assertSame('object', $rows['MoveTo']['parameters_json']['type']);
        $this->assertSame(['target'], $rows['MoveTo']['parameters_json']['required']);
        $this->assertSame('plugin_command', $rows['MoveTo']['metadata']['dispatch']);
        $this->assertTrue($rows['MoveTo']['game_function']);
        $this->assertNull($rows['MoveTo']['script_proxy_program']);

        $this->assertSame('plugin_command', $rows['CheckInventory']['metadata']['dispatch']);
        $this->assertTrue($rows['CheckInventory']['metadata']['followup']['enabled']);
        $this->assertSame('target', $rows['CheckInventory']['metadata']['followup']['arg_name']);
        $this->assertNull($rows['CheckInventory']['script_proxy_program']);

        $this->assertSame('plugin_command', $rows['ReadQuests']['metadata']['dispatch']);
        $this->assertTrue($rows['ReadQuests']['metadata']['followup']['enabled']);
        $this->assertSame('id_quest', $rows['ReadQuests']['metadata']['followup']['arg_name']);
        $this->assertNull($rows['ReadQuests']['script_proxy_program']);

        $this->assertSame('plugin_command', $rows['GiveCapsTo']['metadata']['dispatch']);
        $this->assertFalse($rows['GiveCapsTo']['metadata']['followup']['enabled']);
        $this->assertNull($rows['GiveCapsTo']['script_proxy_program']);
    }

    public function testActionCatalogMetadataFlagEnabled_ReadsBooleanFlagsFromCatalogRows(): void
    {
        $GLOBALS['SYNTH_ACTION_CATALOG_ROWS_BY_CODE'] = [
            'OpenInventory' => [
                'code_name' => 'OpenInventory',
                'metadata' => [
                    'suppress_placeholder_infoaction' => true,
                ],
            ],
        ];

        $this->assertTrue(
            synthActionCatalogMetadataFlagEnabled(
                'OpenInventory',
                'suppress_placeholder_infoaction'
            )
        );
        $this->assertFalse(
            synthActionCatalogMetadataFlagEnabled(
                'OpenInventory',
                'missing_flag'
            )
        );

        unset($GLOBALS['SYNTH_ACTION_CATALOG_ROWS_BY_CODE']);
    }

    public function testResolveNpcRolemasterState_UsesStructuredNpcMetadata(): void
    {
        $previousRolemaster = $GLOBALS['is_rolemastered'] ?? null;
        $hadRolemaster = array_key_exists('is_rolemastered', $GLOBALS);

        unset($GLOBALS['is_rolemastered']);
        synthRolemasterStateResetCache();

        try {
            $this->assertTrue(synthResolveNpcRolemasterState('Mallory Mucklow', [
                'metadata' => ['is_rolemastered' => 'true'],
                'extended' => [],
                'load_lookup' => false,
                'use_global' => false,
            ]));
            $this->assertTrue(synthResolveNpcRolemasterState('Mallory Mucklow', [
                'metadata' => [],
                'extended' => ['is_rolemastered' => 1],
                'load_lookup' => false,
                'use_global' => false,
            ]));
            $this->assertFalse(synthResolveNpcRolemasterState('Mallory Mucklow', [
                'npc_data' => ['is_rolemastered' => 'false'],
                'metadata' => ['is_rolemastered' => '0'],
                'extended' => ['is_rolemastered' => 'off'],
                'load_lookup' => false,
                'use_global' => false,
            ]));
        } finally {
            synthRolemasterStateResetCache();

            if ($hadRolemaster) {
                $GLOBALS['is_rolemastered'] = $previousRolemaster;
            } else {
                unset($GLOBALS['is_rolemastered']);
            }
        }
    }

    public function testBuildActionCatalogSeedRows_SeedsBuiltinRequirementsAndCooldownMetadata(): void
    {
        $rows = synthBuildActionCatalogSeedRows(
            [
                'ComeCloser' => 'ComeCloser',
                'FollowPlayer' => 'FollowPlayer',
                'WaitHere' => 'WaitHere',
                'SheatheWeapon' => 'SheatheWeapon',
                'TakeASeat' => 'TakeASeat',
            ],
            [],
            [],
            [],
            [],
            [
                'ComeCloser' => ['parameters' => ['type' => 'object', 'properties' => [], 'required' => []]],
                'FollowPlayer' => ['parameters' => ['type' => 'object', 'properties' => [], 'required' => []]],
                'WaitHere' => ['parameters' => ['type' => 'object', 'properties' => [], 'required' => []]],
                'SheatheWeapon' => ['parameters' => ['type' => 'object', 'properties' => [], 'required' => []]],
                'TakeASeat' => ['parameters' => ['type' => 'object', 'properties' => [], 'required' => []]],
            ]
        );

        $this->assertSame(30, $rows['ComeCloser']['metadata']['cooldown_seconds']);
        $this->assertSame(60, $rows['FollowPlayer']['metadata']['cooldown_seconds']);
        $this->assertSame(30, $rows['WaitHere']['metadata']['cooldown_seconds']);
        $this->assertTrue($rows['SheatheWeapon']['metadata']['requirements']['activity']['is_weapon_drawn']);
        $this->assertContains('sitting', $rows['TakeASeat']['metadata']['requirements']['activity']['current_action_not_in']);
    }

    public function testBuildActionCatalogSeedRows_NormalizesDisplayTextToGenericNpcAndPlayerLabels(): void
    {
        $hadSYNTHName = array_key_exists('SYNTH_NAME', $GLOBALS);
        $hadPlayerName = array_key_exists('PLAYER_NAME', $GLOBALS);
        $originalSYNTHName = $GLOBALS['SYNTH_NAME'] ?? null;
        $originalPlayerName = $GLOBALS['PLAYER_NAME'] ?? null;

        $GLOBALS['SYNTH_NAME'] = 'Narrator';
        $GLOBALS['PLAYER_NAME'] = 'RANGROO';

        try {
            $rows = synthBuildActionCatalogSeedRows(
                [
                    'TakeCapsFromPlayer' => 'TakeCapsFromRANGROO',
                    'MakeFollower' => 'JoinRANGROOParty',
                ],
                [
                    'TakeCapsFromPlayer' => 'The Narrator takes amount (property target) of caps from RANGROO, once RANGROO is agree. infer amount from context.',
                    'MakeFollower' => 'The Narrator joins RANGROO party and travels with RANGROO as an ally.',
                ],
                [
                    'TakeCapsFromPlayer' => 'RANGROO gave #TARGET# caps to The Narrator. If this a transaction, maybe GiveItemTo is needed.',
                    'MakeFollower' => 'The Narrator is now part of RANGROO party.',
                ]
            );

            $this->assertSame('Take_Caps_From_Player', $rows['TakeCapsFromPlayer']['action_name']);
            $this->assertSame(
                'NPC takes amount (property target) of caps from PLAYER, once PLAYER is agree. infer amount from context.',
                $rows['TakeCapsFromPlayer']['description']
            );
            $this->assertSame(
                'PLAYER gave #TARGET# caps to NPC. If this a transaction, maybe GiveItemTo is needed.',
                $rows['TakeCapsFromPlayer']['return_message']
            );
            $this->assertSame('Join_Player_Party', $rows['MakeFollower']['action_name']);
            $this->assertSame(
                'NPC joins PLAYER party and travels with PLAYER as an ally.',
                $rows['MakeFollower']['description']
            );
            $this->assertSame(
                'NPC is now part of PLAYER party.',
                $rows['MakeFollower']['return_message']
            );
        } finally {
            if ($hadSYNTHName) {
                $GLOBALS['SYNTH_NAME'] = $originalSYNTHName;
            } else {
                unset($GLOBALS['SYNTH_NAME']);
            }

            if ($hadPlayerName) {
                $GLOBALS['PLAYER_NAME'] = $originalPlayerName;
            } else {
                unset($GLOBALS['PLAYER_NAME']);
            }
        }
    }

    public function testApplyRowsToRuntimeFunctions_UsesCatalogRowsAsBaselineSourceOfTruth(): void
    {
        $previousRows = $GLOBALS['SYNTH_ACTION_CATALOG_ROWS_BY_CODE'] ?? null;
        $previousFunctions = $GLOBALS['FUNCTIONS'] ?? null;
        $previousBaseFunctions = $GLOBALS['BASE_FUNCTIONS'] ?? null;
        $previousFallbackBaseFunctions = $GLOBALS['SYNTH_BASE_FUNCTIONS_FALLBACK'] ?? null;
        $previousNames = $GLOBALS['F_NAMES'] ?? null;
        $previousDescriptions = $GLOBALS['F_DESCRIPTIONS'] ?? null;
        $previousReturnMessages = $GLOBALS['F_RETURNMESSAGES'] ?? null;
        $previousPreferredCodes = $GLOBALS['SYNTH_ACTION_NAME_PREFERRED_CODE'] ?? null;
        $previousResolver = $GLOBALS['SYNTH_ACTION_CODE_RESOLVER'] ?? null;

        $GLOBALS['SYNTH_ACTION_CATALOG_ROWS_BY_CODE'] = [
            'Toast' => [
                'code_name' => 'Toast',
                'action_name' => 'Make_a_Toast',
                'description' => 'Table-owned toast description.',
                'return_message' => 'Table-owned toast return.',
                'available_to_npc' => true,
                'available_to_followers' => true,
                'is_activated' => true,
                'parameters_json' => [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
                'metadata' => [
                    'builtin' => true,
                    'dispatch' => 'plugin_command',
                ],
                'game_function' => true,
                'script_proxy_program' => null,
            ],
        ];

        $GLOBALS['FUNCTIONS'] = [
            [
                'name' => 'Fallback_Toast',
                'description' => 'Fallback toast description.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
            ],
            [
                'name' => 'Ext_Action',
                'description' => 'Extension action.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
            ],
        ];

        $GLOBALS['SYNTH_BASE_FUNCTIONS_FALLBACK'] = [
            'Toast' => $GLOBALS['FUNCTIONS'][0],
        ];
        $GLOBALS['F_NAMES'] = [
            'Toast' => 'Fallback_Toast',
            'ExtCode' => 'Ext_Action',
        ];
        $GLOBALS['F_DESCRIPTIONS'] = [
            'Toast' => 'Fallback toast description.',
            'ExtCode' => 'Extension action.',
        ];
        $GLOBALS['F_RETURNMESSAGES'] = [
            'Toast' => 'Fallback toast return.',
            'ExtCode' => '',
        ];
        $codeMap = [
            'Fallback_Toast' => 'Toast',
            'Make_a_Toast' => 'Toast',
            'Ext_Action' => 'ExtCode',
        ];
        $GLOBALS['SYNTH_ACTION_CODE_RESOLVER'] = static fn($key) => $codeMap[$key] ?? false;

        try {
            synthActionCatalogApplyRowsToRuntimeFunctions();

            $this->assertSame('Make_a_Toast', $GLOBALS['F_NAMES']['Toast']);
            $this->assertSame('Table-owned toast description.', $GLOBALS['F_DESCRIPTIONS']['Toast']);
            $this->assertSame('Table-owned toast return.', $GLOBALS['F_RETURNMESSAGES']['Toast']);
            $this->assertArrayHasKey('Toast', $GLOBALS['BASE_FUNCTIONS']);
            $this->assertSame('Make_a_Toast', $GLOBALS['BASE_FUNCTIONS']['Toast']['name']);
            $this->assertSame('Table-owned toast description.', $GLOBALS['BASE_FUNCTIONS']['Toast']['description']);
            $this->assertArrayHasKey('ExtCode', $GLOBALS['BASE_FUNCTIONS']);
            $this->assertSame('Ext_Action', $GLOBALS['BASE_FUNCTIONS']['ExtCode']['name']);
        } finally {
            if ($previousRows !== null) {
                $GLOBALS['SYNTH_ACTION_CATALOG_ROWS_BY_CODE'] = $previousRows;
            } else {
                unset($GLOBALS['SYNTH_ACTION_CATALOG_ROWS_BY_CODE']);
            }

            if ($previousFunctions !== null) {
                $GLOBALS['FUNCTIONS'] = $previousFunctions;
            } else {
                unset($GLOBALS['FUNCTIONS']);
            }

            if ($previousBaseFunctions !== null) {
                $GLOBALS['BASE_FUNCTIONS'] = $previousBaseFunctions;
            } else {
                unset($GLOBALS['BASE_FUNCTIONS']);
            }

            if ($previousFallbackBaseFunctions !== null) {
                $GLOBALS['SYNTH_BASE_FUNCTIONS_FALLBACK'] = $previousFallbackBaseFunctions;
            } else {
                unset($GLOBALS['SYNTH_BASE_FUNCTIONS_FALLBACK']);
            }

            if ($previousNames !== null) {
                $GLOBALS['F_NAMES'] = $previousNames;
            } else {
                unset($GLOBALS['F_NAMES']);
            }

            if ($previousDescriptions !== null) {
                $GLOBALS['F_DESCRIPTIONS'] = $previousDescriptions;
            } else {
                unset($GLOBALS['F_DESCRIPTIONS']);
            }

            if ($previousReturnMessages !== null) {
                $GLOBALS['F_RETURNMESSAGES'] = $previousReturnMessages;
            } else {
                unset($GLOBALS['F_RETURNMESSAGES']);
            }

            if ($previousPreferredCodes !== null) {
                $GLOBALS['SYNTH_ACTION_NAME_PREFERRED_CODE'] = $previousPreferredCodes;
            } else {
                unset($GLOBALS['SYNTH_ACTION_NAME_PREFERRED_CODE']);
            }

            if ($previousResolver !== null) {
                $GLOBALS['SYNTH_ACTION_CODE_RESOLVER'] = $previousResolver;
            } else {
                unset($GLOBALS['SYNTH_ACTION_CODE_RESOLVER']);
            }
        }
    }

    public function testActionCatalogRowIsAvailableInCurrentMode_UsesNarratorScopeForNarrator(): void
    {
        $previousSYNTHName = $GLOBALS['SYNTH_NAME'] ?? null;
        $hadSYNTHName = array_key_exists('SYNTH_NAME', $GLOBALS);
        $previousIsNpc = $GLOBALS['IS_NPC'] ?? null;
        $hadIsNpc = array_key_exists('IS_NPC', $GLOBALS);

        $GLOBALS['SYNTH_NAME'] = 'The Narrator';
        $GLOBALS['IS_NPC'] = false;

        try {
            $this->assertTrue(synthActionCatalogRowIsAvailableInCurrentMode([
                'available_to_npc' => false,
                'available_to_followers' => false,
                'available_to_narrator' => true,
            ]));

            $this->assertFalse(synthActionCatalogRowIsAvailableInCurrentMode([
                'available_to_npc' => true,
                'available_to_followers' => true,
                'available_to_narrator' => false,
            ]));
        } finally {
            if ($hadSYNTHName) {
                $GLOBALS['SYNTH_NAME'] = $previousSYNTHName;
            } else {
                unset($GLOBALS['SYNTH_NAME']);
            }

            if ($hadIsNpc) {
                $GLOBALS['IS_NPC'] = $previousIsNpc;
            } else {
                unset($GLOBALS['IS_NPC']);
            }
        }
    }
}
