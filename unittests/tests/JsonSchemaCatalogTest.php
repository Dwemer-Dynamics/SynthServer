<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'response.php';
require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'logger.php';
require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'core'
    .DIRECTORY_SEPARATOR.'narrator.class.php';
require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'media_store.php';
require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'visual_context_request.php';

final class JsonSchemaCatalogTest extends TestCase
{
    public function testFreshRechatSceneKeepsSeparateParentOwnership(): void
    {
        require_once dirname(__DIR__,2).'/lib/request.php';
        $root=dirname(__DIR__,2);
        $event=json_decode(file_get_contents($root.'/protocol/v2/fixtures/valid/rechat-fresh-scene.json'),true,flags:JSON_THROW_ON_ERROR);
        $this->assertSame($event,synth_protocol_validate_event($event));
        $this->assertSame(7,$event['context_sequence']);
        $this->assertSame(8,$event['payload']['scene_context_sequence']);
        require_once $root.'/lib/synth_rechat_prepare.php';
        $retiredOwner=new class {
            public function withNativeRequestLock(callable $operation,int $timeout): mixed {
                return $operation(null,['request_id'=>'retired']);
            }
        };
        try {
            synth_rechat_scene_context($retiredOwner,$event);
            $this->fail('Fresh scene accepted a different native request owner');
        } catch (LogicException $error) {
            $this->assertSame('rechat_scene_owner_changed',$error->getMessage());
        }
        try {
            synth_rechat_prepare_continuation($retiredOwner,$event);
            $this->fail('Fresh scene preparation accepted a different native request owner');
        } catch (LogicException $error) {
            $this->assertSame('rechat_prepare_owner_changed',$error->getMessage());
        }
        $invalid=[];
        foreach (glob($root.'/protocol/v2/fixtures/valid/context-scene-*.json') as $file) {
            $physical=json_decode(file_get_contents($file),true,flags:JSON_THROW_ON_ERROR);
            $this->assertSame($physical,synth_protocol_validate_event($physical));
        }
        foreach (glob($root.'/protocol/v2/fixtures/invalid/context-scene-*.json') as $file)
            $invalid[basename($file)]=json_decode(file_get_contents($file),true,flags:JSON_THROW_ON_ERROR);
        foreach (glob($root.'/protocol/v2/fixtures/invalid/rechat-scene-*.json') as $file)
            $invalid[basename($file)]=json_decode(file_get_contents($file),true,flags:JSON_THROW_ON_ERROR);
        foreach ([6,7,9007199254740992] as $sequence) {
            $candidate=$event;$candidate['payload']['scene_context_sequence']=$sequence;
            $invalid['sequence-'.$sequence]=$candidate;
        }
        foreach ($invalid as $name=>$candidate) {
            $script='require '.var_export($root.'/lib/request.php',true).';synth_protocol_validate_event(json_decode('
                .var_export(json_encode($candidate),true).',true));echo "ACCEPTED";';
            $output=strval(shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script)));
            $this->assertStringNotContainsString('ACCEPTED',$output,$name);
            $this->assertFalse(json_decode($output,true)['ok']??true,$name);
        }
    }

    public function testNativeControlSelectionsAreClosedAndRoleOwned(): void
    {
        require_once dirname(__DIR__,2).'/lib/request.php';
        require_once dirname(__DIR__,2).'/lib/synth_protocol_state.php';
        require_once dirname(__DIR__,2).'/lib/synth_control.php';
        $base = json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/init-flat.json'), true, flags: JSON_THROW_ON_ERROR);
        $base['capabilities'][] = 'control.menu';
        $base['type'] = 'control';
        $base['payload'] = ['setting'=>'status','value'=>''];
        $this->assertSame($base, synth_protocol_validate_event($base));
        $halt = $base;
        $halt['type'] = 'halt';
        $halt['payload'] = new stdClass();
        $this->assertSame($halt, synth_protocol_validate_event($halt));
        foreach (['STANDARD','WHISPER','CLOSE','SHOUT','NARRATOR','DIRECTOR','INJECTION_LOG','INJECTION_CHAT','CHEATMODE'] as $mode) {
            $event = $base;
            $event['type'] = 'input_text';
            $event['context_sequence'] = 1;
            $event['payload'] = ['text'=>'Hello','control'=>['mode'=>$mode,'model_slot'=>2]];
            $this->assertSame($event, synth_protocol_validate_event($event));
            $previous = $GLOBALS['SYNTH_REQUEST_EVENT'] ?? null;
            try {
                $GLOBALS['SYNTH_REQUEST_EVENT'] = ['json'=>$event];
                $this->assertSame($mode === 'NARRATOR' ? 'narrator' : 'npc', synth_protocol_owned_response_role());
            } finally {
                if ($previous === null) unset($GLOBALS['SYNTH_REQUEST_EVENT']);
                else $GLOBALS['SYNTH_REQUEST_EVENT'] = $previous;
            }
        }
        foreach ([['mode','AUTOCHAT'],['model_slot','0'],['model_slot','5'],['status','extra'],['shell','command'],['mode',[]]] as [$key,$value])
            $this->assertFalse(synth_control_valid_change($key,$value));
        foreach (['missing-capability','unknown-field','unknown-mode','v1'] as $case) {
            $event = $base;
            if ($case === 'missing-capability') $event['capabilities'] = [];
            if ($case === 'unknown-field') $event['payload']['extra'] = true;
            if ($case === 'unknown-mode') $event['payload'] = ['setting'=>'mode','value'=>'AUTOCHAT'];
            if ($case === 'v1') { $event['schema']='synth.event.v1'; $event['protocol_version']=1; unset($event['context_sequence']); }
            $script = 'require '.var_export(dirname(__DIR__,2).'/lib/request.php',true).';synth_protocol_validate_event(json_decode('.var_export(json_encode($event),true).',true));echo "ACCEPTED";';
            $output = strval(shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script)));
            $this->assertStringNotContainsString('ACCEPTED',$output,$case);
        }
    }
    public function testTransferReceiptsRequireTwoIndependentBoundedObservations(): void
    {
        require_once dirname(__DIR__,2).'/lib/request.php';
        $root=dirname(__DIR__,2);
        $file=$root.'/protocol/v2/fixtures/valid/transfer-inventory.json';
        $event=json_decode(file_get_contents($file),true,flags:JSON_THROW_ON_ERROR);
        $this->assertSame($event,synth_protocol_validate_event($event));
        foreach (['complete','partial','unavailable'] as $donor) foreach (['complete','partial','unavailable'] as $recipient) {
            $value=$event;$value['payload']['result']['inventory']['observation']=$donor;
            $value['payload']['result']['recipient_inventory']['observation']=$recipient;
            if($recipient==='unavailable') $value['payload']['result']['recipient_inventory']['items']=[];
            $this->assertSame($value,synth_protocol_validate_event($value));
        }
        $extended=$event;$extended['capabilities'][]='context.inventory_512';
        foreach (['inventory','recipient_inventory'] as $key)
            $extended['payload']['result'][$key]['items']=array_fill(0,512,$event['payload']['result']['recipient_inventory']['items'][0]);
        $this->assertSame($extended,synth_protocol_validate_event($extended));
        foreach (glob($root.'/protocol/v2/fixtures/invalid/transfer-*.json') as $invalid) {
            $script='require '.var_export($root.'/lib/request.php',true).';synth_protocol_validate_event(json_decode(file_get_contents('
                .var_export($invalid,true).'),true));echo "ACCEPTED";';
            $output=strval(shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script)));
            $this->assertStringNotContainsString('ACCEPTED',$output,basename($invalid));
            $this->assertFalse(json_decode($output,true)['ok']??true,basename($invalid));
        }
        $mutations=[
            'same-reference'=>'$event["payload"]["result"]["recipient_inventory"]["actor"]["form_id"]="0x0001a4d7";',
            'same-reference-other-plugin'=>'$event["payload"]["result"]["recipient_inventory"]["actor"]["form_id"]="0x0001a4d7";$event["payload"]["result"]["recipient_inventory"]["actor"]["origin_plugin"]="Other.esp";',
            'other-save'=>'$event["payload"]["result"]["recipient_inventory"]["actor"]["playthrough_id"]="other:save";',
            'bad-recipient'=>'$event["payload"]["result"]["recipient_inventory"]["actor"]["form_id"]="0xGG000001";',
            'extra-key'=>'$event["payload"]["result"]["recipient_inventory"]["extra"]=true;',
            'zero-count'=>'$event["payload"]["result"]["recipient_inventory"]["items"][0]["count"]=0;',
        ];
        foreach (['inventory','recipient_inventory'] as $key) {
            $fill='$event["payload"]["result"]["'.$key.'"]["items"]=array_fill(0,%d,$event["payload"]["result"]["recipient_inventory"]["items"][0]);';
            $mutations[$key.'-no-extended']=sprintf($fill,33);
            $mutations[$key.'-overflow']='$event["capabilities"][]="context.inventory_512";'.sprintf($fill,513);
        }
        foreach ($mutations as $case=>$mutation) {
            $script='require '.var_export($root.'/lib/request.php',true).';$event=json_decode(file_get_contents('
                .var_export($file,true).'),true);'.$mutation.'synth_protocol_validate_event($event);echo "ACCEPTED";';
            $output=strval(shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script)));
            $this->assertStringNotContainsString('ACCEPTED',$output,$case);
            $this->assertFalse(json_decode($output,true)['ok']??true,$case);
        }
    }

    public function testActionInventoryReceiptsPreserveLegacyAndObservationBounds(): void
    {
        require_once dirname(__DIR__,2).'/lib/request.php';
        $root=dirname(__DIR__,2);
        $file=$root.'/protocol/v2/fixtures/valid/action-inventory.json';
        $event=json_decode(file_get_contents($file),true,flags:JSON_THROW_ON_ERROR);
        $this->assertSame($event,synth_protocol_validate_event($event));
        foreach (['complete','partial','unavailable'] as $quality) {
            $value=$event;$value['payload']['result']['inventory']['observation']=$quality;
            if ($quality==='unavailable') $value['payload']['result']['inventory']['items']=[];
            $this->assertSame($value,synth_protocol_validate_event($value));
        }
        $extended=$event;$extended['capabilities'][]='context.inventory_512';
        $extended['payload']['result']['inventory']['items']=array_fill(0,512,$event['payload']['result']['inventory']['items'][0]);
        $this->assertSame($extended,synth_protocol_validate_event($extended));
        foreach (glob($root.'/protocol/v2/fixtures/invalid/action-inventory-*.json') as $invalid) {
            $script='require '.var_export($root.'/lib/request.php',true).';synth_protocol_validate_event(json_decode(file_get_contents('
                .var_export($invalid,true).'),true));echo "ACCEPTED";';
            $output=strval(shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script)));
            $this->assertStringNotContainsString('ACCEPTED',$output,basename($invalid));
            $this->assertFalse(json_decode($output,true)['ok']??true,basename($invalid));
        }
        foreach (['overflow','vr','no-extended-capability','v1'] as $case) {
            $mutation=match($case) {
                'overflow'=>'$event["payload"]["result"]["inventory"]["items"][]=$event["payload"]["result"]["inventory"]["items"][0];',
                'vr'=>'$event["runtime_variant"]="vr";',
                'no-extended-capability'=>'array_pop($event["capabilities"]);',
                'v1'=>'$event["protocol_version"]=1;$event["schema"]="synth.event.v1";unset($event["context_sequence"]);',
            };
            $script='require '.var_export($root.'/lib/request.php',true).';$event=json_decode(file_get_contents('
                .var_export($file,true).'),true);$event["capabilities"][]="context.inventory_512";'
                .'$event["payload"]["result"]["inventory"]["items"]=array_fill(0,512,$event["payload"]["result"]["inventory"]["items"][0]);'
                .$mutation.'synth_protocol_validate_event($event);echo "ACCEPTED";';
            $output=strval(shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script)));
            $this->assertStringNotContainsString('ACCEPTED',$output,$case);
            $this->assertFalse(json_decode($output,true)['ok']??true,$case);
        }
        $legacy=json_decode(file_get_contents($root.'/protocol/v2/fixtures/valid/action-result.json'),true,flags:JSON_THROW_ON_ERROR);
        $this->assertSame($legacy,synth_protocol_validate_event($legacy));
    }

    public function testNarrativeRegenerationRejectsUnboundIdentities(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_middleterm_regeneration.php';
        foreach ([[0,1],[1,0],[-1,1]] as [$actorId,$narrativeId]) {
            try {synth_middleterm_regeneration_plan(new stdClass(),[],$actorId,$narrativeId);$this->fail('Unbound regeneration plan accepted');}
            catch(InvalidArgumentException $error){$this->assertNotEmpty($error->getMessage());}
        }
    }

    public function testRegenerationApprovalRequiresExactScopeRevisionAndToken(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_middleterm_approval.php';
        $input=['session_id'=>'save:fixture','generation'=>'1','context_sequence'=>'2',
            'actor_identity_id'=>'9007199254740993','narrative_id'=>'9007199254740994',
            'expected_revision'=>'0','expected_plan_sha256'=>str_repeat('b',64),'approval_token'=>str_repeat('a',32)];
        $this->assertSame($input,synth_middleterm_approval_input($input));
        foreach ([['expected_revision'=>'01'],['expected_revision'=>1.5],['expected_plan_sha256'=>''],['approval_token'=>''],
            ['approval_token'=>str_repeat('g',32)],['approval_token'=>str_repeat('a',33)],
            ['operation'=>'detail'],['actor_name'=>'Preston'],['context_sequence'=>'0']] as $change) {
            try {synth_middleterm_approval_input(array_replace($input,$change));$this->fail('Invalid approval accepted');}
            catch(InvalidArgumentException $error){$this->assertNotEmpty($error->getMessage());}
        }
        foreach ([[0,1],[1,0]] as [$actorId,$approvalId]) {
            try {synth_middleterm_approved_regeneration_plan(new stdClass(),[],$actorId,$approvalId);$this->fail('Unbound approval accepted');}
            catch(InvalidArgumentException $error){$this->assertNotEmpty($error->getMessage());}
        }
        foreach ([0,-1] as $approvalId) {
            try {synth_middleterm_regeneration_budget(new stdClass(),$approvalId);$this->fail('Unbound execution budget accepted');}
            catch(InvalidArgumentException $error){$this->assertNotEmpty($error->getMessage());}
        }
    }

    public function testNarrativeInspectionRequiresExactKeysAndSingleActorSources(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_middleterm_scope.php';
        $scope=['session_id'=>'save:fixture','generation'=>'1','context_sequence'=>'2'];
        $detail=['operation'=>'detail']+$scope+['actor_identity_id'=>'9007199254740993','narrative_id'=>'9007199254740994'];
        $this->assertSame($detail,synth_middleterm_scope_input($detail));
        foreach ([['operation'=>'edit'],['operation'=>'scopes','before_session'=>'incomplete'],
            ['operation'=>'list']+$scope,$detail+['actor_name'=>'Same name'],array_replace($detail,['narrative_id'=>'0']),
            array_replace($detail,['actor_identity_id'=>1.5]),array_replace($detail,['session_id'=>"x' OR TRUE"]) ] as $invalid) {
            try {synth_middleterm_scope_input($invalid);$this->fail('Invalid narrative inspection accepted');}
            catch (InvalidArgumentException $error) {$this->assertNotEmpty($error->getMessage());}
        }
        foreach ([0,-1] as $invalidReader) {
            try {synth_memory_visible_batch_cte(new stdClass(),[],'',$invalidReader);$this->fail('Combined/invalid source reader accepted');}
            catch (InvalidArgumentException $error) {$this->assertNotEmpty($error->getMessage());}
        }
        try {synth_protocol_history_cte(new stdClass(),[],'',null,false,false,null,false,'middleterm');$this->fail('Unbound narrative journal reader accepted');}
        catch (InvalidArgumentException $error) {$this->assertNotEmpty($error->getMessage());}
    }

    public function testRechatParentContractRequiresAnOwnedClaimBeyondSchemaAcceptance(): void
    {
        require_once dirname(__DIR__,2).'/lib/request.php';
        require_once dirname(__DIR__,2).'/lib/synth_protocol_state.php';
        $root=dirname(__DIR__,2);
        foreach (['rechat-owned-parent.json','rechat-owned-parent-vr.json','trigger.json'] as $name) {
            $event=json_decode(file_get_contents($root.'/protocol/v2/fixtures/valid/'.$name),true,flags:JSON_THROW_ON_ERROR);
            $this->assertSame($event,synth_protocol_validate_event($event));
            if ($name==='trigger.json') continue;
            $request=[];
            try { synth_protocol_bind_trigger_context(null,$event,$request); $this->fail('Unclaimed owned rechat entered the legacy path'); }
            catch(RuntimeException $error) { $this->assertSame('owned_rechat_requires_claim',$error->getMessage()); }
        }
        foreach (glob($root.'/protocol/v2/fixtures/invalid/rechat-parent-*.json') as $file) {
            $script='require '.var_export($root.'/lib/request.php',true).';synth_protocol_validate_event(json_decode(file_get_contents('
                .var_export($file,true).'),true));echo "ACCEPTED";';
            $output=strval(shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script)));
            $this->assertStringNotContainsString('ACCEPTED',$output);
            $this->assertFalse(json_decode($output,true)['ok']??true);
        }
    }

    public function testExtendedInventoryKeepsLegacyAndRuntimeBoundaries(): void
    {
        require_once dirname(__DIR__,2).'/lib/request.php';
        $root=dirname(__DIR__,2);
        $file=$root.'/protocol/v2/fixtures/valid/inventory-extended.json';
        $event=json_decode(file_get_contents($file),true,flags:JSON_THROW_ON_ERROR);
        $this->assertSame($event,synth_protocol_validate_event($event));
        $row=$event['payload']['actor_states'][0]['inventory'][0];
        $event['payload']['actor_states'][0]['inventory']=array_fill(0,512,$row);
        $this->assertSame($event,synth_protocol_validate_event($event));
        // Execute rejecting ingress in a child: fail() intentionally terminates the HTTP request.
        foreach (['overflow','no-capability','vr','v1','unavailable'] as $case) {
            $mutation=match($case) {
                'overflow'=>'$event["payload"]["actor_states"][0]["inventory"]=array_fill(0,513,$event["payload"]["actor_states"][0]["inventory"][0]);',
                'no-capability'=>'$event["capabilities"]=array_values(array_diff($event["capabilities"],["context.inventory_512"]));',
                'vr'=>'$event["runtime_variant"]="vr";',
                'v1'=>'$event["schema"]="synth.event.v1";$event["protocol_version"]=1;unset($event["context_sequence"]);',
                'unavailable'=>'$event["payload"]["actor_states"][0]["inventory_observation"]="unavailable";',
            };
            $script='require '.var_export($root.'/lib/request.php',true).';$event=json_decode(file_get_contents('.
                var_export($file,true).'),true);'.$mutation.'synth_protocol_validate_event($event);echo "ACCEPTED";';
            $output=strval(shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script)));
            $this->assertStringNotContainsString('ACCEPTED',$output,$case);
            $this->assertFalse(json_decode($output,true)['ok']??true,$case);
        }
        $v1=json_decode(file_get_contents($root.'/protocol/v1/schemas/actor-state.schema.json'),true);
        $v2=json_decode(file_get_contents($root.'/protocol/v2/schemas/actor-state.schema.json'),true);
        $this->assertSame(32,$v1['properties']['inventory']['maxItems']);
        $this->assertSame(512,$v2['properties']['inventory']['maxItems']);
        $v2['$id']=$v1['$id'];$v2['title']=$v1['title'];
        $v2['properties']['actor']=$v1['properties']['actor'];
        $v2['properties']['inventory']['maxItems']=32;
        $this->assertSame(['$ref'=>'combat-target.schema.json'],$v2['properties']['combat_target']);
        $this->assertSame(['type'=>'array','maxItems'=>8,'items'=>['$ref'=>'combat-contact.schema.json']],$v2['properties']['combat_contacts']);
        $this->assertSame(['$ref'=>'enemy-awareness.schema.json'],$v2['properties']['enemy_awareness']);
        $contactRules=array_values(array_filter($v2['allOf'],static fn(array $rule):bool=>
            in_array('combat_contacts',$rule['if']['required'] ?? [],true)));
        $this->assertSame([['if'=>['required'=>['combat_contacts']],
            'then'=>['properties'=>['alive'=>['const'=>true],'disabled'=>['const'=>false],
                'actor'=>['properties'=>['form_id'=>['not'=>['const'=>'0x00000014']]]]]]]],$contactRules);
        $this->assertSame(['type'=>'boolean'],$v2['properties']['conversation_held']);
        $holdRules=array_values(array_filter($v2['allOf'],static fn(array $rule):bool=>
            in_array('conversation_held',$rule['if']['required'] ?? [],true)));
        $this->assertSame([['if'=>['required'=>['conversation_held'],
            'properties'=>['conversation_held'=>['const'=>true]]],
            'then'=>['required'=>['life_state'],'properties'=>[
                'life_state'=>['const'=>'restrained'],'alive'=>['const'=>true],'disabled'=>['const'=>false],
                'actor'=>['properties'=>['form_id'=>['not'=>['const'=>'0x00000014']]]]]]]],$holdRules);
        unset($v2['properties']['combat_target'],$v2['properties']['combat_contacts'],$v2['properties']['enemy_awareness'],$v2['properties']['conversation_held']);
        $v2['allOf']=array_values(array_filter($v2['allOf'], static fn(array $rule): bool =>
            array_intersect(['combat_target','combat_contacts','enemy_awareness','conversation_held'],$rule['if']['required'] ?? [])===[]));
        $this->assertSame($v1,$v2,'The negotiated combat target/contacts and extended inventory must preserve all other actor-state constraints.');
    }

    public function testPlayerReactionContractAndGroundedPromptPreservePolicy(): void
    {
        require_once dirname(__DIR__,2).'/lib/request.php';
        require_once dirname(__DIR__,2).'/lib/player_reaction.php';
        $root=dirname(__DIR__,2);
        $event=json_decode(file_get_contents($root.'/protocol/v2/fixtures/valid/player-reaction.json'),true);
        $this->assertSame($event,synth_protocol_validate_event($event));
        $legacy=$event;$legacy['schema']='synth.event.v1';$legacy['protocol_version']=1;unset($legacy['context_sequence']);
        $script='require '.var_export($root.'/lib/request.php',true).'; synth_protocol_validate_event('.var_export($legacy,true).'); echo "ACCEPTED";';
        $this->assertStringNotContainsString('ACCEPTED',strval(shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script))));
        // Negative player-reaction fixtures also run through the real ingress in the adjacent player-event test.
        foreach (['level_up'=>'rpg_lvlup','combat_end'=>'combatend'] as $kind=>$type) {
            foreach ([true,false] as $disabled) {
                $prompts=[$type=>['cue'=>['Celebrate the defeated foes'],'extra'=>['dontuse'=>$disabled]],'bored'=>['cue'=>['unchanged']]];
                synthApplyPlayerReactionPrompt($prompts,['kind'=>$kind,'text'=>'Recorded fact.'],'FORMAT');
                $this->assertSame($disabled,$prompts[$type]['extra']['dontuse']);
                $this->assertSame(['unchanged'],$prompts['bored']['cue']);
                $this->assertSame(['Recorded fact.'],$prompts[$type]['player_request']);
                $this->assertStringNotContainsString('Celebrate the defeated foes',$prompts[$type]['cue'][0]);
                $this->assertStringContainsString('Do not claim you witnessed it.',$prompts[$type]['cue'][0]);
                $this->assertStringContainsString($kind==='level_up'?'Do not invent a perk':'Do not assume victory',$prompts[$type]['cue'][0]);
            }
        }
        $pipeline=file_get_contents($root.'/main_synth_pipeline.php');
        $this->assertStringContainsString('synthApplyPlayerReactionPrompt($PROMPTS',$pipeline);
    }

    public function testActorEventsKeepSourceEvidenceSeparateFromPlayerKnowledge(): void
    {
        require_once dirname(__DIR__,2).'/lib/request.php';
        require_once dirname(__DIR__,2).'/lib/actor_event.php';
        $root=dirname(__DIR__,2);
        $event=json_decode(file_get_contents($root.'/protocol/v2/fixtures/valid/actor-events.json'),true);
        $this->assertSame($event,synth_protocol_validate_event($event));
        $batch=$event['payload']['actor_events'];
        $known=synthBindActorEvents($batch,$event['payload']);
        $this->assertCount(1,$known);$this->assertSame(1,$known[0]['index']);
        $this->assertSame('equipped',$known[0]['event']['kind']);
        require_once $root.'/lib/synth_protocol_state.php';
        $db=new class($event['payload']) {
            public function __construct(private array $payload) {}
            public function escapeLiteral($value): string { return "'unit-owner'"; }
            public function fetchOne($query): array { return ['context_payload'=>$this->payload]; }
        };
        $previous=$GLOBALS['SYNTH_REQUEST_EVENT']??null;
        try {
            $reader=array_replace($event,['type'=>'input_text','context_sequence'=>1,'payload'=>['text'=>'Hello']]);
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$reader];
            $this->assertSame($batch,synth_protocol_current_context($db,$reader)['payload']['actor_events']);
            $prompt=synth_protocol_prompt_snapshot($db);
            $this->assertArrayNotHasKey('actor_events',$prompt);
            $this->assertSame($event['payload']['world'],$prompt['world']);
            $request=[];synth_protocol_bind_dialogue_context($db,$reader,$request);
            $this->assertArrayNotHasKey('actor_events',json_decode($request[3],true));
            $this->assertStringNotContainsString('0x00009999',$request[3]);
        } finally {
            if ($previous===null) unset($GLOBALS['SYNTH_REQUEST_EVENT']);
            else $GLOBALS['SYNTH_REQUEST_EVENT']=$previous;
        }
        foreach (glob($root.'/protocol/v2/fixtures/invalid/actor-events-*.json') as $file) {
            $script='require '.var_export($root.'/lib/request.php',true).'; synth_protocol_validate_event(synth_protocol_decode_json(file_get_contents('.var_export($file,true).'))); echo "ACCEPTED";';
            $output=strval(shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script)));
            $this->assertStringNotContainsString('ACCEPTED',$output);
            $this->assertFalse(json_decode($output,true)['ok']??true);
        }
        for ($case=0;$case<5;++$case) {
            $bad=$batch;$payload=$event['payload'];
            if($case===0)$bad['events'][0]['player_knowledge']='player_equipment';
            if($case===1)$bad['events'][1]['actor']['playthrough_id']='other-save';
            if($case===2)$payload['actor_states']=[];
            if($case===3)$payload['actor_states'][]=$payload['actor_states'][0];
            if($case===4)$payload['actor_states'][0]['disabled']=true;
            try {synthBindActorEvents($bad,$payload);$this->fail('Unowned actor knowledge accepted');}
            catch(DomainException $error){$this->assertNotEmpty($error->getMessage());}
        }
        foreach ([['batch_id'=>'actor:9007199254740992'],['age_ms'=>true],['witnesses'=>[]]] as $change) {
            try {synthValidateActorEvents(array_replace($batch,$change));$this->fail('Invalid actor batch accepted');}
            catch(DomainException $error){$this->assertNotEmpty($error->getMessage());}
        }
    }

    public function testPlayerEventEvidenceRequiresTypedFlatOwnedTransitions(): void
    {
        require_once dirname(__DIR__,2).'/lib/request.php';
        require_once dirname(__DIR__,2).'/lib/player_event.php';
        $root=dirname(__DIR__,2);
        foreach (['player-level-up','player-combat-end'] as $fixture) {
            $event=json_decode(file_get_contents($root.'/protocol/v2/fixtures/valid/'.$fixture.'.json'),true);
            $this->assertSame($event,synth_protocol_validate_event($event));
        }
        foreach (glob($root.'/protocol/v2/fixtures/invalid/player-*.json') as $file) {
            $script='require '.var_export($root.'/lib/request.php',true).'; synth_protocol_validate_event(synth_protocol_decode_json(file_get_contents('.var_export($file,true).'))); echo "ACCEPTED";';
            $output=strval(shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script)));
            $this->assertStringNotContainsString('ACCEPTED',$output);
            $this->assertFalse(json_decode($output,true)['ok']??true);
        }
        $valid=$event['payload']['player_event'];
        foreach ([['age_ms'=>-1],['age_ms'=>true],['interval_ms'=>0],['event_id'=>'player:01'],['kind'=>'victory'],
                  ['before'=>[]],['after'=>array_replace($valid['after'],['in_combat'=>0])]] as $change) {
            try{synthValidatePlayerEvent(array_replace($valid,$change));$this->fail('Bad player event accepted');}
            catch(DomainException $error){$this->assertNotEmpty($error->getMessage());}
        }
    }

    public function testQuestReactionTrustBoundaryRejectsLegacyAndUnownedEvidence(): void
    {
        require_once dirname(__DIR__,2).'/lib/request.php';
        $root=dirname(__DIR__,2);
        $event=json_decode(file_get_contents($root.'/protocol/v2/fixtures/valid/quest-reaction.json'),true);
        $this->assertSame($event,synth_protocol_validate_event($event));
        $invalid=[];
        foreach (glob($root.'/protocol/v2/fixtures/invalid/quest-reaction-*.json') as $file) $invalid[]=json_decode(file_get_contents($file),true);
        foreach ([2,3] as $sequence) { $bad=$event;$bad['payload']['previous_context_sequence']=$sequence;$invalid[]=$bad; }
        $bad=$event;$bad['schema']='synth.event.v1';$bad['protocol_version']=1;unset($bad['context_sequence']);$invalid[]=$bad;
        foreach ($invalid as $bad) {
            $script='require '.var_export($root.'/lib/request.php',true).'; synth_protocol_validate_event('.var_export($bad,true).'); echo "ACCEPTED";';
            $output=strval(shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script)));
            $this->assertStringNotContainsString('ACCEPTED',$output);
            $this->assertContains(json_decode($output,true)['code'] ?? '', ['invalid_payload','invalid_envelope']);
        }
    }

    public function testQuestTrackingIsTypedOptionalAndFlatCapabilityGated(): void
    {
        require_once dirname(__DIR__,2).'/lib/request.php';
        require_once dirname(__DIR__,2).'/lib/synth_protocol_state.php';
        require_once dirname(__DIR__,2).'/lib/data_functions.php';
        $root=dirname(__DIR__,2);
        foreach (['quest-tracking'=>true,'quest-untracked'=>false] as $fixture=>$expected) {
            $event=synth_protocol_validate_event(synth_protocol_decode_json(file_get_contents($root.'/protocol/v2/fixtures/valid/'.$fixture.'.json')));
            $this->assertSame($expected,$event['payload']['active_quests'][0]['tracked']);
            $journal=synth_protocol_prompt_quests($event['payload']);
            $this->assertSame($expected,$journal['active_quests'][0]['tracked']);
            $rows=synthSnapshotQuestRows($journal);
            $this->assertStringContainsString($expected?'[Tracked by player]':'[Not tracked by player]',$rows[0]['briefing']);
        }
        $legacy=json_decode(file_get_contents($root.'/protocol/v2/fixtures/valid/context.json'),true);
        $journal=synth_protocol_prompt_quests($legacy['payload']);
        $this->assertNull($journal['active_quests'][0]['tracked']);
        $this->assertStringContainsString('[Quest tracking unavailable]',synthSnapshotQuestRows($journal)[0]['briefing']);
        foreach (['quest-tracking-not-boolean','quest-tracking-missing-capability','quest-tracking-vr'] as $fixture) {
            $script='require '.var_export($root.'/lib/request.php',true).'; synth_protocol_validate_event(synth_protocol_decode_json(file_get_contents('.
                var_export($root.'/protocol/v2/fixtures/invalid/'.$fixture.'.json',true).'))); echo "ACCEPTED";';
            $output=strval(shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script)));
            $this->assertStringNotContainsString('ACCEPTED',$output);
            $this->assertStringContainsString('invalid_quest_state',$output);
        }
        $quest=$event['payload']['active_quests'][0];
        $script='require '.var_export($root.'/lib/request.php',true).'; synth_protocol_validate_quest_state('.var_export($quest,true).',"quest",1); echo "ACCEPTED";';
        $this->assertStringNotContainsString('ACCEPTED',strval(shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script))));
    }

    public function testGlobalJournalScopeRequiresCanonicalNarratorAndWideAccessPolicy(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_diary_state.php';
        $keys=['SYNTH_NAME','SYNTH_PROTOCOL_REQUEST_CLAIMED','NARRATOR_ONLY_DIARY_ACCESS'];
        $previous=array_intersect_key($GLOBALS,array_flip($keys));
        $db=new class { public function escapeLiteral($value): string {return "'".str_replace("'","''",strval($value))."'";} };
        $event=['protocol_version'=>2,'session_id'=>'test','runtime_variant'=>'flat','generation'=>1,'context_sequence'=>1];
        try {
            foreach ([['Piper',true,false],['The Narrator',false,false],['The Narrator',true,true]] as [$name,$claimed,$own]) {
                $GLOBALS['SYNTH_NAME']=$name;$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=$claimed;$GLOBALS['NARRATOR_ONLY_DIARY_ACCESS']=$own;
                try { synth_diary_history_cte($db,$event,0,'npc',true);$this->fail('Unauthorized broad journal scope'); }
                catch (InvalidArgumentException $error) {$this->assertSame('Narrator journal reader required',$error->getMessage());}
            }
        } finally {foreach($keys as $key){if(array_key_exists($key,$previous))$GLOBALS[$key]=$previous[$key];else unset($GLOBALS[$key]);}}
    }

    public function testNativeHybridRecallDoesNotPromoteMissingVectorsToSemanticMatches(): void
    {
        require_once dirname(__DIR__,2).'/lib/memory_ranking.php';
        $text=['memory_source'=>'native_diary','distance'=>null,'mixed_distance'=>-5,'rank_any'=>2,'rank_all'=>3];
        $semantic=['memory_source'=>'native_diary','distance'=>'0.1','mixed_distance'=>'0.05',
            'gamets_truncated'=>'100','rowid'=>'7'];
        $this->assertNull(synthSelectBestHybridMemoryCandidate([$text]));
        $selected=synthSelectBestHybridMemoryCandidate([$text,$semantic]);
        $this->assertSame('7',$selected['rowid']);
        $this->assertEqualsWithDelta(1.3,$selected['rank_all'],0.00001);
        // Native recall can choose strong text evidence, but it must remain explicitly non-semantic.
        $chosen=synthSelectBestNativeMemoryCandidate([$text,$semantic]);
        $this->assertSame(2,$chosen['rank_any']);
        $this->assertNull($chosen['distance']);
        $this->assertNull($chosen['mixed_distance']);
        $this->assertSame('7',synthSelectBestNativeMemoryCandidate([
            array_replace($text,['rank_any'=>0.1]),$semantic])['rowid']);
        $this->assertNull(synthSelectBestNativeMemoryCandidate([array_replace($text,['rank_any'=>0])]));
        $this->assertSame(2,synthSelectBestNativeMemoryCandidate([$text])['rank_any']);
        $this->assertNull(synthSelectBestNativeMemoryCandidate([]));
    }

    public function testNativeTextRecallMergesDiaryAndDialogueWithoutLosingIdentityOrBound(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_memory_recall.php';
        $base=['rank_all'=>'0.1','rank_any'=>'0.2','gamets_truncated'=>'100','rowid'=>'1','memory_source'=>'native'];
        $diary=array_replace($base,['memory_source'=>'native_diary']);
        $strong=array_replace($diary,['rank_all'=>'0.9']);
        $recent=array_replace($base,['gamets_truncated'=>'200']);
        $expected=[$strong,$recent,$base,$diary];
        $this->assertSame($expected,synth_memory_rank_text_candidates([$diary,$recent,$strong,$base]));
        $this->assertSame($expected,synth_memory_rank_text_candidates(array_reverse($expected)));
        $rows=[];for($i=1;$i<=60;++$i)$rows[]=array_replace($base,['rowid'=>strval($i)]);
        $ranked=synth_memory_rank_text_candidates($rows);
        $this->assertCount(50,$ranked);
        $this->assertSame('60',$ranked[0]['rowid']);
        $this->assertSame('11',$ranked[49]['rowid']);
        $this->assertSame([],synth_memory_rank_text_candidates([]));
        $rows=[];
        for($i=1;$i<=70;++$i) {
            $rows[]=array_replace($base,['rowid'=>strval($i),'distance'=>'0.1','mixed_distance'=>'0.05']);
            $rows[]=array_replace($diary,['rowid'=>strval($i),'distance'=>null,'mixed_distance'=>null]);
        }
        $ranked=synth_memory_rank_text_candidates($rows,true);
        $this->assertCount(100,$ranked);
        $this->assertCount(50,array_filter($ranked,static fn($row)=>$row['distance']===null));
        $this->assertSame('70',$ranked[50]['rowid']);
        $this->assertSame('21',$ranked[99]['rowid']);
        $strongOld=array_replace($base,['rowid'=>'0','gamets_truncated'=>'1',
            'rank_all'=>'0.9','rank_any'=>'0.9','distance'=>null,'mixed_distance'=>null]);
        $rows[]=$strongOld;
        $ranked=synth_memory_rank_text_candidates($rows,true);
        $this->assertCount(100,$ranked);
        $this->assertSame($strongOld,$ranked[50]);
        $this->assertSame('22',$ranked[99]['rowid']);
    }

    public function testDiaryAdmissionRetainsOldReceiptsAndCountsUnprofiledAutomaticActors(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_diary_status.php';
        $event=['payload'=>['actors'=>[[],[]],'include_narrator'=>true,'include_player'=>true]];
        foreach (['no diary connector','role setting or no diary connector',
            'role policy, cooldown, pending result or no diary connector',
            'no existing profile, role policy, cooldown, pending result or no diary connector'] as $reason) {
            $ack=['payload'=>['code'=>'diary_queued','detail'=>"Queued: 1; disabled ($reason): 3. Generation runs in the background."]];
            $this->assertSame(1,synth_diary_admission_queued($ack,$event));
            foreach (['code'=>'diary_disabled','detail'=>"Queued: 1; disabled ($reason): 2. Generation runs in the background."] as $key=>$value) {
                $invalid=$ack;$invalid['payload'][$key]=$value;
                try { synth_diary_admission_queued($invalid,$event);$this->fail('Conflicting receipt accepted'); }
                catch (LogicException $error) { $this->assertSame('Diary admission receipt conflicts.',$error->getMessage()); }
            }
        }
    }

    public function testDiaryBatchWaitsForEveryJobAndDistinguishesPartialSuccess(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_diary_status.php';
        $cases=[
            [[], 'diary_disabled','diary_disabled'],
            [['ready'], 'diary_ready','diary_ready'],
            [['ready','ready'], 'diary_ready','diary_ready'],
            [['failed','running','ready'], 'diary_running','diary_running'],
            [['cancelled','queued','ready'], 'diary_queued','diary_queued'],
            [['failed','queued'], 'diary_queued','diary_queued'],
            [['queued','running'], 'diary_running','diary_running'],
            [['ready','failed'], 'diary_partial','diary_failed'],
            [['ready','cancelled'], 'diary_partial','diary_cancelled'],
            [['ready','failed','cancelled'], 'diary_partial','diary_failed'],
            [['failed','cancelled'], 'diary_failed','diary_failed'],
            [['cancelled'], 'diary_cancelled','diary_cancelled'],
        ];
        foreach ($cases as [$states,$supported,$legacy]) {
            $this->assertSame($supported,synth_diary_aggregate_status($states,true));
            $this->assertSame($legacy,synth_diary_aggregate_status($states,false));
            $this->assertSame($supported,synth_diary_aggregate_status(array_reverse($states),true));
        }
        $this->assertSame('diary_ready',synth_diary_aggregate_status(array_fill(0,18,'ready'),true));
        foreach ([['unknown'],['ready',null],array_fill(0,19,'ready')] as $invalid) {
            try { synth_diary_aggregate_status($invalid,true); $this->fail('Invalid batch accepted'); }
            catch (LogicException $error) { $this->assertSame('Invalid diary batch states.',$error->getMessage()); }
        }
    }

    public function testDiaryAutomationRespectsRoleSpecificReferencePolicy(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_diary_config.php';
        $resolve=static fn($role,$reason,$character=[],$profile=[],$environment=[])=>
            synth_diary_resolve_automation($role,$reason,$character,$profile,$environment);
        foreach (['npc','narrator','player'] as $role) {
            $this->assertFalse($resolve($role,'sleep')['enabled']);
            $this->assertFalse($resolve($role,'wait')['enabled']);
            $this->assertSame($role!=='player',$resolve($role,'manual')['enabled']);
        }
        $player=['diary_enabled'=>'true','auto_diary_enabled'=>'true','auto_diary_wait_enabled'=>'false'];
        $this->assertTrue($resolve('player','sleep',$player)['enabled']);
        $this->assertFalse($resolve('player','wait',$player)['enabled']);
        $this->assertTrue($resolve('player','wait',array_replace($player,['auto_diary_wait_enabled'=>'true']))['enabled']);
        foreach (['manual','sleep','wait'] as $reason) $this->assertFalse($resolve('player',$reason,array_replace($player,['diary_enabled'=>'false']))['enabled']);
        $this->assertTrue($resolve('player','manual',['diary_enabled'=>'true'])['enabled']);
        foreach (['sleep','wait'] as $reason) {
            $this->assertTrue($resolve('narrator',$reason,['auto_diary_enabled'=>'true','diary_enabled'=>'false'])['enabled']);
            $this->assertTrue($resolve('narrator',$reason,[],[],['NARRATOR_AUTO_DIARY_ENABLED'=>true])['enabled']);
            $this->assertFalse($resolve('narrator',$reason,['auto_diary_enabled'=>'false'],[],['NARRATOR_AUTO_DIARY_ENABLED'=>true])['enabled']);
        }
        $profile=['metadata'=>json_encode(['AUTO_DIARY_ENABLED'=>'1','AUTO_DIARY_WAIT_ENABLED'=>true,'DIARY_COOLDOWN'=>12])];
        $this->assertSame(['enabled'=>true,'cooldown_seconds'=>12],$resolve('npc','wait',[],$profile,['DIARY_COOLDOWN'=>80]));
        foreach ([null,''] as $inherit) $this->assertTrue($resolve('npc','wait',['extended_data'=>json_encode([
            'auto_diary_enabled'=>$inherit,'auto_diary_wait_enabled'=>$inherit])],$profile)['enabled']);
        foreach ([false,0,'0'] as $off) {
            $this->assertFalse($resolve('npc','sleep',['extended_data'=>json_encode(['auto_diary_enabled'=>$off])],$profile)['enabled']);
            $this->assertFalse($resolve('npc','wait',['extended_data'=>json_encode(['auto_diary_wait_enabled'=>$off])],$profile)['enabled']);
        }
        $this->assertTrue($resolve('npc','wait',['extended_data'=>'{"auto_diary_enabled":true,"auto_diary_wait_enabled":true}'])['enabled']);
        $this->assertFalse($resolve('npc','wait',[],['metadata'=>'{"AUTO_DIARY_ENABLED":true}'])['enabled']);
        $this->assertSame(30,$resolve('narrator','sleep')['cooldown_seconds']);
        $this->assertSame(0,$resolve('npc','sleep',[],['metadata'=>'{"DIARY_COOLDOWN":-1}'])['cooldown_seconds']);
        $this->assertSame(81,$resolve('player','sleep',$player,[],['DIARY_COOLDOWN'=>81])['cooldown_seconds']);
        $this->assertTrue($resolve('npc','manual',[],['metadata'=>'malformed'])['enabled']);
        foreach ([['invalid','sleep',[]],['npc','unknown',[]],['npc','sleep',['metadata'=>'malformed']],['npc','sleep',['metadata'=>'null']]] as [$role,$reason,$invalid]) {
            try {$resolve($role,$reason,[],$invalid);$this->fail('Invalid automatic policy accepted');}
            catch (LogicException|JsonException $expected) {$this->assertTrue(true);}
        }
    }

    public function testDiaryResolverKeepsInstalledDriversAndRejectsNonProviderPaths(): void
    {
        require_once dirname(__DIR__,2).'/lib/data_functions.php';
        $keys=['CONNECTORS_DIARY','CONNECTORS'];$previous=array_intersect_key($GLOBALS,array_flip($keys));
        try {
            $GLOBALS['CONNECTORS_DIARY']='keep-original';$GLOBALS['CONNECTORS']=['player2json'];
            foreach (['openaijson','openrouterjson','koboldcppjson','google_openaijson','groqjson','player2json'] as $driver) {
                $this->assertSame($driver,synthResolveDiaryConnectorName($driver,false));
                $this->assertSame('keep-original',$GLOBALS['CONNECTORS_DIARY']);
            }
            foreach (['openai'=>'openaijson','openrouter'=>'openrouterjson','koboldcpp'=>'koboldcppjson'] as $old=>$driver) {
                $this->assertSame($driver,synthResolveDiaryConnectorName($old,false));
            }
            foreach (['../lib/data_functions','parse_simple_yaml','__jpd',"openai\0",'file:///tmp/provider'] as $bad) {
                $this->assertSame('player2json',synthResolveDiaryConnectorName($bad,false));
            }
            $this->assertSame('openaijson',synthResolveDiaryConnectorName('["missing",["openaijson","openrouterjson"]]',false));
            $this->assertSame('koboldcppjson',synthResolveDiaryConnectorName('missing,koboldcppjson',false));
            $GLOBALS['CONNECTORS']=[];
            $this->assertSame('openrouterjson',synthResolveDiaryConnectorName(null,false));
        } finally {
            foreach ($keys as $key) { if(array_key_exists($key,$previous))$GLOBALS[$key]=$previous[$key];else unset($GLOBALS[$key]); }
        }
    }

    public function testKoboldDiaryCoreSettingsAndFastInputAreBounded(): void
    {
        require_once dirname(__DIR__,2).'/lib/core/llm_connector.class.php';
        require_once dirname(__DIR__,2).'/connector/koboldcppjson.php';
        $keys=['db','CONNECTOR','FORCE_MAX_TOKENS'];$previous=array_intersect_key($GLOBALS,array_flip($keys));
        try {
            $GLOBALS['db']=new class { public function fetchOne($query) { throw new RuntimeException('Unexpected badge read.'); } };
            $GLOBALS['CONNECTOR']['koboldcppjson']=['url'=>'http://invalid.local','max_tokens'=>999,'template'=>'chatml'];
            (new LLMConnector())->setOldGlobals(['driver'=>'koboldcppjson','url'=>'http://127.0.0.1:18104',
                'max_tokens'=>321,'repetition_penalty'=>1.2,'metadata'=>'{"top_k":17}']);
            $settings=$GLOBALS['CONNECTOR']['koboldcppjson'];
            $this->assertSame('http://127.0.0.1:18104',$settings['url']);$this->assertSame(321,$settings['max_tokens']);
            $this->assertSame(1.2,$settings['rep_pen']);$this->assertSame(17,$settings['top_k']);
            $this->assertSame('chatml',$settings['template']);$this->assertSame('',$settings['API_KEY']);
            $driver=new koboldcppjson();unset($GLOBALS['FORCE_MAX_TOKENS']);
            foreach ([[],[['role'=>'tool','content'=>'tool']], [['role'=>'user','content'=>"bad\0"]],
                [['role'=>'user','content'=>'text','tool_calls'=>[]]], [['role'=>'user','content'=>str_repeat('x',1048577)]]] as $messages) {
                try {$driver->fast_request($messages,['MAX_TOKENS'=>321],'diary');$this->fail('Invalid KoboldCpp input reached HTTP.');}
                catch (InvalidArgumentException|LengthException $error) {$this->assertNotSame('',$error->getMessage());}
            }
            $GLOBALS['FORCE_MAX_TOKENS']=0;
            $this->expectException(InvalidArgumentException::class);
            $driver->fast_request([['role'=>'user','content'=>'text']],['MAX_TOKENS'=>321],'diary');
        } finally {
            foreach ($keys as $key) { if(array_key_exists($key,$previous))$GLOBALS[$key]=$previous[$key];else unset($GLOBALS[$key]); }
        }
    }

    public function testDiaryConfigurationPreservesRoleSpecificConnectorPolicies(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_diary_config.php';
        $calls=[];$legacyCalls=0;
        $lookup=static function(int $id) use(&$calls) {
            $calls[]=$id;
            return $id===999 ? null : ['driver'=>'openaijson','max_tokens'=>''];
        };
        $legacy=static function() use(&$legacyCalls) { ++$legacyCalls;return 'openrouterjson'; };
        $environment=['CORE_CONNECTOR_SUMMARY'=>2,'CORE_CONNECTOR_PLAYER'=>3,'CORE_CONNECTOR_PROFILES'=>4,
            'PROMPT_HEAD'=>'Global head','DIARY_PROMPT'=>'Global diary','PLAYER_DIARY_PROMPT'=>'Player diary #PLAYER_NAME#',
            'CONTEXT_HISTORY_DIARY'=>0,'CONTEXT_HISTORY'=>40,
            'CONNECTOR'=>['openaijson'=>['max_tokens'=>'','MAX_TOKENS_MEMORY'=>321,'api_key'=>'must-not-be-frozen']]];
        $profile=['diary_connector_id'=>5,'connector_id'=>6,'metadata'=>json_encode([
            'PROMPT_HEAD'=>'Profile head','DIARY_PROMPT'=>'Profile diary','CONTEXT_HISTORY_DIARY'=>600])];
        $resolve=static fn(string $role,array $character=[],array $config=[],?int $override=null)=>
            synth_diary_resolve_settings($role,$character,$config,$environment,$lookup,$legacy,$override);
        $npc=$resolve('npc',[], $profile);
        $this->assertSame(5,$npc['connector_id']);$this->assertSame('profile_diary',$npc['connector_source']);
        $this->assertSame(321,$npc['max_tokens']);$this->assertSame(200,$npc['history_limit']);
        $this->assertSame('Profile diary',$npc['prompt']);$this->assertSame('Profile head',$npc['prompt_head']);
        $this->assertSame('Character head',$resolve('npc',['prompt_head'=>'Character head'],$profile)['prompt_head']);
        $this->assertStringNotContainsString('must-not-be-frozen',json_encode($npc));
        $this->assertSame(7,$resolve('npc',[],$profile,7)['connector_id']);
        $this->assertNull($resolve('npc',[],array_replace($profile,['diary_connector_id'=>null])));
        $this->assertNull($resolve('npc',[],$profile,0),'Explicit effective no-connector must not revive the raw profile field.');
        $this->assertNull($resolve('npc',[],['metadata'=>'not-json'],0),'Disabled diary must not parse unused profile settings.');
        $narrator=$resolve('narrator',[],array_replace($profile,['diary_connector_id'=>null]));
        $this->assertSame(6,$narrator['connector_id']);$this->assertSame('narrator_profile',$narrator['connector_source']);
        $this->assertSame(7,$resolve('narrator',[],$profile,7)['connector_id']);
        $this->assertSame(5,$resolve('narrator',[],$profile)['connector_id']);
        $this->assertSame(0,$legacyCalls,'NPC and Narrator must not use the player legacy fallback.');
        $player=$resolve('player',['diary_connector_id'=>8],$profile);
        $this->assertSame(8,$player['connector_id']);$this->assertSame('player_diary',$player['connector_source']);
        $this->assertSame('Player diary #PLAYER_NAME#',$player['prompt']);$this->assertSame('Global head',$player['prompt_head']);
        $this->assertSame('Global head',$resolve('player',['prompt_head'=>'Unsupported player override'],$profile)['prompt_head']);
        $this->assertSame(40,$player['history_limit'],'Player must not inherit an NPC profile diary history limit.');
        $this->assertSame(2,$resolve('player')['connector_id']);
        $fallback=synth_diary_resolve_settings('player',[],[],array_replace($environment,['CORE_CONNECTOR_SUMMARY'=>'']),$lookup,$legacy);
        $this->assertSame(3,$fallback['connector_id']);$this->assertSame('CORE_CONNECTOR_PLAYER',$fallback['connector_source']);
        $fallback=synth_diary_resolve_settings('player',[],[],array_replace($environment,['CORE_CONNECTOR_SUMMARY'=>'','CORE_CONNECTOR_PLAYER'=>'']),$lookup,$legacy);
        $this->assertSame(4,$fallback['connector_id']);$this->assertSame('CORE_CONNECTOR_PROFILES',$fallback['connector_source']);
        $calls=[];$missing=$resolve('player',['diary_connector_id'=>999]);
        $this->assertSame([999],$calls,'A broken explicit player connector must not silently select another core connector.');
        $this->assertNull($missing['connector_id']);$this->assertSame('openrouterjson',$missing['connector_driver']);
        $this->assertSame('legacy_diary',$missing['connector_source']);$this->assertSame(2048,$missing['max_tokens']);
        $this->assertSame(1,$legacyCalls);
        $this->assertNull(synth_diary_resolve_settings('player',[],[],[],$lookup,static fn()=>null));
        foreach (['npc','narrator'] as $role) {
            try { $resolve($role,[],array_replace($profile,['diary_connector_id'=>999]));$this->fail('Missing configured connector accepted.'); }
            catch (LogicException $error) { $this->assertSame('Diary connector unavailable.',$error->getMessage()); }
        }
    }

    public function testDiaryConfigurationRejectsInvalidLimitsInstructionsAndDrivers(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_diary_config.php';
        $profile=['diary_connector_id'=>1,'metadata'=>'{}'];
        foreach ([['role'=>'unknown'],['driver'=>'../other'],['tokens'=>0],['prompt'=>[]],['prompt'=>str_repeat('x',16385)],
            ['head'=>"bad\0head"],['metadata'=>'false']] as $bad) {
            try {
                synth_diary_resolve_settings($bad['role'] ?? 'npc',[],array_replace($profile,['metadata'=>$bad['metadata'] ?? '{}']),
                    ['DIARY_PROMPT'=>$bad['prompt'] ?? 'Diary','PROMPT_HEAD'=>$bad['head'] ?? ''],
                    static fn(int $id)=>['driver'=>$bad['driver'] ?? 'openaijson','max_tokens'=>$bad['tokens'] ?? 321],static fn()=>null);
                $this->fail('Invalid native diary settings accepted.');
            } catch (LogicException $error) { $this->assertNotSame('',$error->getMessage()); }
        }
    }

    public function testDiaryStatusRejectsNonPostAndNonDiaryBeforeBootstrap(): void
    {
        $root=dirname(__DIR__,2);
        foreach (['GET'=>'method_not_allowed','POST'=>'invalid_diary_status_owner'] as $method=>$code) {
            $script='putenv("PHPUNIT_TEST=1");$_SERVER["REQUEST_METHOD"]='.var_export($method,true).';'
                .'$GLOBALS["SYNTH_TEST_NORMALIZED_EVENT"]=["json"=>["protocol_version"=>2,"type"=>"input_text"]];'
                .'require '.var_export($root.'/diary_status.php',true).';';
            $output=strval(shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script)));
            $this->assertSame($code,json_decode($output,true)['code'] ?? null);
        }
    }

    public function testNativeDiaryIngressRequiresRoleAndAutomaticObservationCapabilities(): void
    {
        $root=dirname(__DIR__,2);
        $valid=json_decode(file_get_contents($root.'/protocol/v2/fixtures/valid/diary-request.json'),true);
        $narrator=array_replace($valid,['capabilities'=>['diary.manual.narrator'],
            'payload'=>array_replace($valid['payload'],['actors'=>[],'include_narrator'=>true])]);
        $mixed=array_replace($valid,['capabilities'=>['diary.manual.npc','diary.manual.narrator'],
            'payload'=>array_replace($valid['payload'],['include_narrator'=>true])]);
        $player=array_replace($narrator,['capabilities'=>['diary.manual.player'],
            'payload'=>array_replace($narrator['payload'],['include_player'=>true,'include_narrator'=>false])]);
        $cases=[$valid,$narrator,$mixed,$player];
        foreach (['sleep','wait'] as $reason) $cases[]=array_replace($player,[
            'capabilities'=>['diary.manual.player','diary.automatic.'.$reason],
            'payload'=>array_replace($player['payload'],['reason'=>$reason])]);
        $cases[]=array_replace($player,['capabilities'=>['diary.manual.narrator']]);
        $cases[]=array_replace($narrator,['capabilities'=>['diary.manual.npc']]);
        $cases[]=array_replace($mixed,['capabilities'=>['diary.manual.narrator']]);
        foreach ([['capabilities'=>[]],['context_sequence'=>0],
            ['payload'=>array_replace($valid['payload'],['actors'=>[]])],
            ['payload'=>array_replace($valid['payload'],['actors'=>[$valid['payload']['actors'][0],$valid['payload']['actors'][0]]])],
            ['payload'=>array_replace($valid['payload'],['include_narrator'=>true])],
            ['payload'=>array_replace($valid['payload'],['reason'=>'wait'])],
            ['payload'=>array_replace($valid['payload'],['prompt'=>'Untrusted client instruction'])]] as $change) $cases[]=array_replace($valid,$change);
        $legacy=array_replace($valid,['schema'=>'synth.event.v1','protocol_version'=>1]);unset($legacy['context_sequence']);$cases[]=$legacy;
        foreach ($cases as $index=>$event) {
            $script='require '.var_export($root.'/lib/request.php',true).';synth_protocol_validate_event('.var_export($event,true).');echo "accepted";';
            $output=strval(shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script)));
            if ($index<6) $this->assertSame('accepted',$output);
            else $this->assertNotEmpty(json_decode($output,true)['code'] ?? null,'Invalid diary input must be rejected before claim.');
        }
    }

    public function testNativeDiaryWorkerRejectsUnboundedOrExecutableMessages(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_diary_jobs.php';
        $input=['schema'=>'synth.diary.input.v1','diary_role'=>'npc','connector_id'=>1,'max_tokens'=>321,
            'messages'=>[['role'=>'system','content'=>'Write a diary.'],['role'=>'user','content'=>'Saved evidence'],['role'=>'user','content'=>'First person.']]];
        synth_diary_validate_job_input($input);$this->assertTrue(true);
        synth_diary_validate_job_input(array_replace($input,['diary_role'=>'narrator']));$this->assertTrue(true);
        synth_diary_validate_job_input(array_replace($input,['diary_role'=>'player']));$this->assertTrue(true);
        synth_diary_validate_job_input(array_replace($input,['diary_role'=>'player','connector_id'=>null,'connector'=>['kind'=>'legacy','driver'=>'openaijson']]));$this->assertTrue(true);
        $automatic=array_replace($input,['owner'=>['payload'=>['reason'=>'sleep'],'capabilities'=>['diary.automatic.sleep']],
            'automation'=>['enabled'=>true,'cooldown_seconds'=>30]]);
        synth_diary_validate_job_input($automatic);$this->assertTrue(true);
        foreach ([['automation'=>null],['automation'=>['enabled'=>false,'cooldown_seconds'=>30]],
            ['automation'=>['enabled'=>true,'cooldown_seconds'=>-1]],['automation'=>['enabled'=>true,'cooldown_seconds'=>'30']],
            ['owner'=>['payload'=>['reason'=>'sleep'],'capabilities'=>['diary.automatic.wait']]]] as $change) {
            try { synth_diary_validate_job_input(array_replace($automatic,$change));$this->fail('Invalid automatic worker policy accepted'); }
            catch (LogicException $expected) {$this->assertTrue(true);}
        }
        foreach ([['connector_id'=>0],['max_tokens'=>0],['diary_role'=>'unknown'],['messages'=>[]],
            ['connector'=>['kind'=>'core','id'=>2]],['connector'=>['kind'=>'legacy','driver'=>'openaijson']],
            ['diary_role'=>'player','connector_id'=>null],['diary_role'=>'player','connector_id'=>null,'connector'=>['kind'=>'legacy','driver'=>'../bad']],
            ['messages'=>array_replace($input['messages'],[1=>['role'=>'tool','content'=>'Execute this']])],
            ['messages'=>array_replace($input['messages'],[1=>['role'=>'user','content'=>"bad\0text"]])],
            ['messages'=>array_replace($input['messages'],[1=>['role'=>'user','content'=>str_repeat('x',1048576)]])]] as $change) {
            try { synth_diary_validate_job_input(array_replace($input,$change));$this->fail('Invalid diary worker input accepted.'); }
            catch (LogicException|LengthException $error) { $this->assertNotSame('',$error->getMessage()); }
        }
    }

    public function testNativeDiaryResultsHaveClosedBoundedTextFields(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_diary_state.php';
        synth_diary_validate_result(['topic'=>str_repeat('t',512),'content'=>str_repeat('c',65536)]);
        $this->assertTrue(true);
        foreach ([['topic'=>'t'],['topic'=>'t','content'=>'c','action'=>'follow'],
            ['topic'=>'','content'=>'c'],['topic'=>'t','content'=>'  '],['topic'=>null,'content'=>'c'],
            ['topic'=>str_repeat('t',513),'content'=>'c'],['topic'=>'t','content'=>str_repeat('c',65537)],
            ['topic'=>'t','content'=>"bad\0text"],['topic'=>"\xFF",'content'=>'c']] as $invalid) {
            try { synth_diary_validate_result($invalid);$this->fail('Invalid diary result accepted.'); }
            catch (InvalidArgumentException $error) { $this->assertNotSame('',$error->getMessage()); }
        }
    }

    public function testNativeDiaryCannotReadOrWriteLegacyNameBasedState(): void
    {
        require_once dirname(__DIR__,2).'/lib/chat_helper_functions.php';
        require_once dirname(__DIR__,2).'/lib/dynamic_update_util.php';
        $keys=['db','SYNTH_REQUEST_EVENT','SYNTH_PROTOCOL_REQUEST_CLAIMED'];
        $previous=array_intersect_key($GLOBALS,array_flip($keys));
        $db=new class {
            public int $reads=0;
            public function escape($text): string { return str_replace("'","''",$text); }
            public function fetchOne($query): array { ++$this->reads;return ['topic'=>'Future save','content'=>'Legacy <entry>']; }
        };
        try {
            $GLOBALS['db']=$db;$GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>['protocol_version'=>2]];
            unset($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']);
            $profile=['metadata'=>'{"LATEST_DIARY_CONTEXT_ENABLED":true}'];
            $this->assertSame('',synthBuildLatestDiaryContextBlock('Piper',$profile));
            $this->assertFalse(generateNearbyDiary('Piper',[],'manual_nearby'));
            $this->assertFalse(generatePlayerDiary([],'manual_player'));
            $this->assertFalse(generateFollowerDiary('Piper',[],'manual'));
            $this->assertFalse(generateFollowerDiary('The Narrator',[],'manual_narrator'));
            $this->assertSame(0,$db->reads,'Native diary must stop before legacy lookup or provider work.');
            $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>['protocol_version'=>1]];
            $this->assertStringContainsString('Legacy &lt;entry&gt;',synthBuildLatestDiaryContextBlock('Piper',$profile));
            $this->assertSame(1,$db->reads,'Explicit legacy diary lookup must remain available.');
        } finally {
            foreach ($keys as $key) { if (array_key_exists($key,$previous)) $GLOBALS[$key]=$previous[$key];else unset($GLOBALS[$key]); }
        }
    }

    public function testProfileWorkerInputKeepsExactOwnerAndBoundedSelectedPrompts(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_profile_jobs.php';
        $owner=['protocol_version'=>2,'session_id'=>'profile:test','generation'=>1];
        $input=['schema'=>'synth.profile.input.v1','owner'=>$owner,'actor_identity_id'=>15,'profile_role'=>'npc',
            'history'=>'frozen','profile'=>synth_profile_fields([]),'baseline_fields'=>synth_profile_fields([]),
            'prior_revision'=>null,'connector_id'=>1,'fields'=>['goals'],
            'prompts'=>['goals'=>json_encode([['role'=>'user','content'=>'Frozen goal prompt']])],
            'options'=>['goals'=>['max_tokens'=>321]]];
        synth_profile_validate_input($input,$owner,15,'npc');
        $this->assertTrue(true);
        foreach ([['actor_identity_id'=>16],['profile_role'=>'narrator'],['connector_id'=>0],['fields'=>['goals','goals']],
            ['fields'=>['voiceid']],['history'=>null],['owner'=>array_replace($owner,['generation'=>'1'])],
            ['options'=>['goals'=>['max_tokens'=>0]]],['prompts'=>['goals'=>'{}']],
            ['prompts'=>['goals'=>'[{"role":"tool","content":"untrusted"}]']]] as $change) {
            try { synth_profile_validate_input(array_replace($input,$change),$owner,15,'npc');$this->fail('Invalid worker input accepted.'); }
            catch (LogicException|JsonException $error) { $this->assertNotSame('',$error->getMessage()); }
        }
    }

    public function testNativeProfileFieldsAreBoundedAndPlayerPerspectiveIsExplicit(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_profile_state.php';
        $this->assertSame(['personality'=>'','occupation'=>'','skills'=>'','speechstyle'=>'','goals'=>''],synth_profile_fields([]));
        $this->assertSame('Saved goal',synth_profile_fields(['goals'=>'Saved goal','voiceid'=>'not dynamic'])['goals']);
        foreach ([[],false,123,"\xff","bad\0text",str_repeat('a',16385)] as $invalid) {
            try { synth_profile_fields(['goals'=>$invalid]);$this->fail('Invalid profile field accepted.'); }
            catch (InvalidArgumentException $error) { $this->assertSame('Invalid native profile field.',$error->getMessage()); }
        }
        $db=new class { public function escapeLiteral($value): string { return "'".str_replace("'","''",$value)."'"; } };
        $event=['protocol_version'=>2,'session_id'=>'profile:test','generation'=>1,'context_sequence'=>2,'runtime_variant'=>'flat'];
        $this->assertStringContainsString('AND c.target_identity_id IS NOT NULL',synth_protocol_history_cte($db,$event));
        $npcHistory=synth_protocol_history_cte($db,$event,'',15);
        $this->assertStringContainsString('AND 15 IS NOT NULL',$npcHistory);
        $this->assertStringContainsString('reader.id<>c.player_identity_id',$npcHistory);
        $this->assertStringContainsString('reader.id=c.target_identity_id OR c.audience_identity_ids',$npcHistory);
        $this->assertStringContainsString('AND c.player_identity_id IS NOT NULL',synth_protocol_history_cte($db,$event,'',null,true));
        $this->expectException(InvalidArgumentException::class);
        synth_protocol_history_cte($db,$event,'',15,true);
    }

    public function testNativeProfileRefreshUsesFrozenActorMembershipWithoutCreatingIdentities(): void
    {
        require_once dirname(__DIR__,2).'/lib/synth_profile_scope.php';
        $identity=['form_id'=>'0x00000015','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'profile:test','display_name'=>'Piper'];
        $db=new class($identity) {
            public int $lookups=0;
            public function __construct(public array $identity) {}
            public function escapeLiteral($value): string { return "'".str_replace("'","''",$value)."'"; }
            public function fetchOne($query): array { return ['context_payload'=>['target'=>$this->identity,'player'=>array_replace($this->identity,['form_id'=>'0x00000014']),'audience'=>[]]]; }
            public function fetchAll($query): array { ++$this->lookups;return [['id'=>'19','actor_identity_id'=>'15','npc_name'=>'Piper']]; }
        };
        $event=['protocol_version'=>2,'session_id'=>'profile:test','generation'=>1,'context_sequence'=>1,
            'payload'=>['actors'=>[$identity],'include_narrator'=>false]];
        $this->assertSame(19,synth_profile_refresh_bindings($db,$event)['Piper']['npc_id']);
        $this->assertSame(1,$db->lookups);
        foreach (['form_id'=>'0x00000016','playthrough_id'=>'other:test','display_name'=>'Renamed'] as $key=>$value) {
            $wrong=$event;$wrong['payload']['actors'][0][$key]=$value;
            try { synth_profile_refresh_bindings($db,$wrong);$this->fail('Unobserved refresh actor accepted'); }
            catch(RuntimeException $error) { $this->assertSame('profile_refresh_actor_not_in_snapshot',$error->getMessage()); }
        }
        $this->assertSame(1,$db->lookups,'Unobserved actors must fail before profile lookup or identity creation.');
    }

    public function testVisualHistoryCannotBeWrittenOutsideCompletionTransaction(): void
    {
        require_once dirname(__DIR__,2).'/lib/visual_context.php';
        $db=new class { public function isTransactionIdle(): bool { return true; } };
        $this->expectException(LogicException::class);
        synthVisualContextRecordResult($db,[],[]);
    }

    public function testVisualSavedReaderUsesCanonicalHistoryAndAcceptedResultBytes(): void
    {
        require_once dirname(__DIR__,2).'/lib/visual_context.php';
        $db=new class {
            public string $query='';
            public function escapeLiteral($value): string { return "'".str_replace("'","''",$value)."'"; }
            public function fetchAll($query): array { $this->query=$query; return []; }
        };
        $event=['protocol_version'=>2,'session_id'=>'visual:reader','generation'=>2,'context_sequence'=>3,'runtime_variant'=>'flat'];
        $this->assertSame([],synthVisualContextSavedHistory($db,$event,['location'=>'Sanctuary'],10));
        foreach (['native_history_scopes','h.id<=scope.cutoff','v.actor_identity_id=scope.reader_identity_id',
            "e.event_type='visual_capture'", "encode(sha256(e.response_body),'hex')=v.result_sha256",
            "p.canonical_event->>'capture_id'=v.capture_id",'origin.target_identity_id=v.actor_identity_id'] as $guard) {
            $this->assertStringContainsString($guard,$db->query);
        }
        $db->query='';
        $this->assertSame([],synthVisualContextSavedHistory($db,array_replace($event,['protocol_version'=>1]),['location'=>'Sanctuary'],10));
        $this->assertSame('',$db->query);
    }

    public function testNativeVisualCaptureRequiresExactSnapshotAndClosedReaction(): void
    {
        require_once dirname(__DIR__, 2).'/lib/request.php';
        $request = json_decode(file_get_contents(dirname(__DIR__, 2).'/protocol/v2/fixtures/valid/visual-context-capture.json'), true);
        $this->assertSame(1, synth_visual_validate_request($request)['context_sequence']);
        $unowned=$request;unset($unowned['subject']);
        try { synth_visual_validate_request($unowned); $this->fail('Native describe accepted without a frozen subject'); }
        catch (SynthVisualRequestException $error) { $this->assertSame(422,$error->httpStatus); }
        foreach ([null, 0, -1, '1', 9007199254740992] as $sequence) {
            $invalid = array_replace($request, ['context_sequence' => $sequence]);
            try { synth_visual_validate_request($invalid); $this->fail('Invalid visual binding accepted'); }
            catch (SynthVisualRequestException $error) { $this->assertSame(422, $error->httpStatus); }
        }
        $payload = ['kind' => 'external_reaction', 'actor' => $request['subject'],
            'instruction' => 'Describe this capture.', 'capture_id' => $request['capture_id']];
        $this->assertSame($payload, synth_protocol_validate_payload('trigger', $payload, 2));
        foreach ([[array_replace($payload, ['kind' => 'rechat']), 2],
            [array_replace($payload, ['capture_id' => '../wrong']), 2], [$payload, 1]] as [$invalid, $version]) {
            $script = 'require '.var_export(dirname(__DIR__, 2).'/lib/request.php', true).';'
                .'synth_protocol_validate_payload("trigger",'.var_export($invalid, true).','.$version.');echo "ACCEPTED";';
            $output = strval(shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script)));
            $this->assertStringContainsString('"code":"'.($version === 1 ? 'invalid_envelope' : 'invalid_payload').'"', $output);
            $this->assertStringNotContainsString('ACCEPTED', $output);
        }
    }

    public function testVisualReactionRejectsEveryMismatchedCaptureOwner(): void
    {
        $capture = json_decode(file_get_contents(dirname(__DIR__, 2).'/protocol/v2/fixtures/valid/visual-context-capture.json'), true);
        $db = new class {
            public array $row;
            public function escapeLiteral($value): string { return "'".str_replace("'", "''", $value)."'"; }
            public function fetchOne($query): array { return $this->row; }
        };
        $db->row = ['metadata' => json_encode($capture), 'response_payload' => '{"ok":true}', 'description' => 'Exact captured scene'];
        $event = $capture;
        $event['payload'] = ['actor' => $capture['subject'], 'capture_id' => $capture['capture_id']];
        $this->assertSame('Exact captured scene', synth_visual_bound_capture($db, $event)['description']);
        foreach (['session_id' => 'different', 'turn_id' => 'different', 'generation' => 3,
            'runtime_variant' => 'vr', 'context_sequence' => 2] as $key => $value) {
            try { synth_visual_bound_capture($db, array_replace($event, [$key => $value])); $this->fail('Capture owner mismatch accepted'); }
            catch (DomainException $error) { $this->assertSame('visual_capture_mismatch', $error->getMessage()); }
        }
        foreach (['form_id' => '0x00000015', 'origin_plugin' => 'Other.esm', 'playthrough_id' => 'another:save'] as $key => $value) {
            $wrong = $event; $wrong['payload']['actor'][$key] = $value;
            try { synth_visual_bound_capture($db, $wrong); $this->fail('Capture actor mismatch accepted'); }
            catch (DomainException $error) { $this->assertSame('visual_capture_actor_mismatch', $error->getMessage()); }
        }
        $db->row['response_payload'] = '{"ok":false}';
        $this->expectException(DomainException::class);
        synth_visual_bound_capture($db, $event);
    }

    public function testV2ResponseEchoesExactBindingWithoutChangingV1(): void
    {
        $previous = $GLOBALS['SYNTH_REQUEST_EVENT'] ?? null;
        try {
            foreach ([1, 2] as $version) {
                $GLOBALS['SYNTH_REQUEST_EVENT'] = ['protocol_version' => $version,
                    'request_id' => 'request:one', 'turn_id' => 'turn:one', 'generation' => 2,
                    'runtime_variant' => 'flat', 'context_sequence' => 7];
                foreach (['response_start', 'status', 'response_end'] as $type) {
                    $line = json_decode(synth_protocol_encode_response_line($type, [], $type), true);
                    $this->assertSame('synth.response.line.v'.$version, $line['schema']);
                    $this->assertSame($version, $line['protocol_version']);
                    if ($version === 2) $this->assertSame(7, $line['context_sequence']);
                    else $this->assertArrayNotHasKey('context_sequence', $line);
                }
            }
        } finally {
            $GLOBALS['SYNTH_REQUEST_EVENT'] = $previous;
        }
    }

    private function schemaDir(): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'protocol'.DIRECTORY_SEPARATOR.'v1'
            .DIRECTORY_SEPARATOR.'schemas';
    }

    public function testAllSchemaFilesAreValidJsonAndHaveUniqueIds(): void
    {
        $ids = [];
        foreach (glob(dirname($this->schemaDir(), 2).'/*/schemas/*.schema.json') ?: [] as $schemaFile) {
            $raw = file_get_contents($schemaFile);
            $this->assertIsString($raw, $schemaFile);

            $decoded = json_decode($raw, true);
            $this->assertIsArray($decoded, $schemaFile.' failed to decode: '.json_last_error_msg());
            $this->assertArrayHasKey('$schema', $decoded, $schemaFile);
            $this->assertArrayHasKey('$id', $decoded, $schemaFile);

            $id = (string)$decoded['$id'];
            $version = basename(dirname($schemaFile, 2));
            $this->assertContains($version, ['v1', 'v2']);
            $this->assertStringStartsWith('https://synth.local/protocol/'.$version.'/schemas/', $id, $schemaFile);
            $this->assertArrayNotHasKey($id, $ids, "Duplicate schema id {$id}");
            $ids[$id] = $schemaFile;
        }

        $this->assertNotEmpty($ids);
    }

    public function testLocalSchemaReferencesResolveToTrackedFiles(): void
    {
        foreach (glob(dirname($this->schemaDir(), 2).'/*/schemas/*.schema.json') ?: [] as $schemaFile) {
            $raw = (string)file_get_contents($schemaFile);
            preg_match_all('/"\\$ref"\\s*:\\s*"([^"]+)"/', $raw, $matches);

            foreach ($matches[1] ?? [] as $ref) {
                if (preg_match('/^https?:\\/\\//', $ref)) {
                    continue;
                }

                $refFile = explode('#', $ref, 2)[0];
                if ($refFile === '') {
                    continue;
                }

                $resolved = dirname($schemaFile).DIRECTORY_SEPARATOR.$refFile;
                $this->assertFileExists($resolved, "{$schemaFile} references missing schema {$ref}");
            }
        }
    }

    public function testVisualCaptureUsesClosedJsonAndOpaqueImageMedia(): void
    {
        $request = json_decode((string)file_get_contents(
            dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'protocol'.DIRECTORY_SEPARATOR.'v1'
            .DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'valid'
            .DIRECTORY_SEPARATOR.'visual-context-capture.json'
        ), true);

        $validated = synth_visual_validate_request($request);
        $this->assertSame('media/'.str_repeat('a', 64), $validated['media']['media_id']);
        $this->assertSame('Fallout4.esm', $validated['subject']['origin_plugin']);

        $request['unexpected'] = 'not allowed';
        $this->expectException(SynthVisualRequestException::class);
        synth_visual_validate_request($request);
    }

    public function testVisualPortraitRequiresCanonicalActorIdentity(): void
    {
        $request = json_decode((string)file_get_contents(
            dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'protocol'.DIRECTORY_SEPARATOR.'v1'
            .DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'valid'
            .DIRECTORY_SEPARATOR.'visual-context-capture.json'
        ), true);
        $request['interaction_mode'] = 'npc_portrait';
        unset($request['subject']);

        $this->expectException(SynthVisualRequestException::class);
        synth_visual_validate_request($request);
    }

    public function testVisualCaptureRejectsAudioMediaReference(): void
    {
        $request = json_decode((string)file_get_contents(
            dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'protocol'.DIRECTORY_SEPARATOR.'v1'
            .DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'valid'
            .DIRECTORY_SEPARATOR.'visual-context-capture.json'
        ), true);
        $request['media']['content_type'] = 'audio/wav';

        $this->expectException(SynthVisualRequestException::class);
        synth_visual_validate_request($request);
    }

    public function testNativeMediaBytesMustMatchTheirDeclaredContainer(): void
    {
        $wave = pack(
            'a4Va4a4VvvVVvva4V',
            'RIFF',
            40,
            'WAVE',
            'fmt ',
            16,
            1,
            1,
            8000,
            16000,
            2,
            16,
            'data',
            4
        ).pack('vv', 0, 0);

        $this->assertTrue(synth_media_is_wave($wave));
        synth_media_validate_bytes($wave, 'audio/wav');
        $this->assertFalse(synth_media_is_wave(substr($wave, 0, -1)));

        require_once dirname(__DIR__,2).'/lib/speech_cache.php';
        $path=tempnam(sys_get_temp_dir(),'synth-cache-');
        $claimed=$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'] ?? null;
        try {
            $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=false;
            foreach (['',str_repeat('broken',100),substr($wave,0,-1),str_repeat('x',SYNTH_MEDIA_MAX_BYTES+1)] as $invalid) {
                file_put_contents($path,$invalid);
                $this->assertFalse(synth_speech_cache_valid($path));
            }
            file_put_contents($path,$wave);
            $this->assertTrue(synth_speech_cache_valid($path));
            $this->assertSame($wave,file_get_contents($path));
            $this->assertFalse(synth_speech_cache_valid($path.'-missing'));
            if (PHP_OS_FAMILY!=='Windows') {
                symlink($path,$path.'-link');
                $this->assertFalse(synth_speech_cache_valid($path.'-link'));
            }
        } finally {
            if (is_link($path.'-link')) unlink($path.'-link');
            unlink($path);
            if ($claimed===null) unset($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']);
            else $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=$claimed;
        }

        $this->expectException(SynthMediaException::class);
        synth_media_validate_bytes($wave, 'audio/ogg');
    }

    public function testResponseLinesCarrySchemaAndTopLevelCommandArgs(): void
    {
        $GLOBALS['SYNTH_RESPONSE_FORMAT'] = 'json';
        $GLOBALS['SYNTH_RESPONSE_STREAMING'] = false;
        $GLOBALS['SYNTH_JSON_RESPONSE_LINES'] = [];

        synth_buffer_command_response_line('Veronica', synthEncodeCommandAction('Follow', ['Graussy']));

        $this->assertCount(1, $GLOBALS['SYNTH_JSON_RESPONSE_LINES']);
        $line = $GLOBALS['SYNTH_JSON_RESPONSE_LINES'][0];
        $this->assertSame('synth.response.line.v1', $line['schema']);
        $this->assertSame('rolecommand', $line['action']);
        $this->assertSame('Follow', $line['command_name']);
        $this->assertSame(['Graussy'], $line['command_args']);
    }

    public function testNarratorResponseKeepsCanonicalSpeakerAndAddsDisplayName(): void
    {
        $GLOBALS['SYNTH_RESPONSE_FORMAT'] = 'json';
        $GLOBALS['SYNTH_RESPONSE_STREAMING'] = false;
        $GLOBALS['SYNTH_JSON_RESPONSE_LINES'] = [];
        $GLOBALS['NARRATOR_ROLEPLAY_NAME'] = 'Mercy';

        synth_buffer_response_line('The Narrator', 'say', 'Welcome back.');

        $this->assertCount(1, $GLOBALS['SYNTH_JSON_RESPONSE_LINES']);
        $line = $GLOBALS['SYNTH_JSON_RESPONSE_LINES'][0];
        $this->assertSame('The Narrator', $line['speaker']);
        $this->assertSame('Mercy', $line['display_name']);
        $this->assertSame('Welcome back.', $line['subtitle']);
    }

    public function testRechatSpeechCarriesExactFacingTargetFormId(): void
    {
        $GLOBALS['SYNTH_RESPONSE_FORMAT'] = 'json';
        $GLOBALS['SYNTH_RESPONSE_STREAMING'] = false;
        $GLOBALS['SYNTH_JSON_RESPONSE_LINES'] = [];
        $GLOBALS['gameRequest'] = ['rechat'];
        $GLOBALS['RECHAT_REQUEST_PAYLOAD'] = [
            'speaker' => 'Veronica',
            'speaker_formid' => '0x000E32A9',
        ];

        synth_buffer_speech_response_line(
            'Deputy Weld',
            'Hello, Veronica.',
            '',
            'Veronica',
            '',
            '',
            1.0,
            'Veronica',
            'utt_rechat_facing'
        );

        $this->assertCount(1, $GLOBALS['SYNTH_JSON_RESPONSE_LINES']);
        $line = $GLOBALS['SYNTH_JSON_RESPONSE_LINES'][0];
        $this->assertSame('0x000E32A9', $line['listener_formid']);
        $this->assertSame('0x000E32A9', $line['rechat_target_formid']);
    }

    public function testStreamRequestedIsIndependentFromJsonEnvelopeMode(): void
    {
        $GLOBALS['SYNTH_RESPONSE_FORMAT'] = 'legacy';
        $GLOBALS['SYNTH_RESPONSE_STREAMING'] = true;

        $this->assertTrue(synth_response_stream_requested());
        $this->assertFalse(synth_json_streaming_enabled());
        $this->assertFalse(synth_should_generate_npc_tts_before_emit());
    }

    public function testMainPipelineLoadsVoiceAwareCacheSeedWithoutBootstrapSideEffects(): void
    {
        $script = 'require '.var_export(dirname(__DIR__, 2).'/lib/chat_helper_functions.php', true).'; '
            .'$initial=function_exists("synth_tts_cache_seed"); $GLOBALS["SYNTH_NAME"]="Settler"; '
            .'$GLOBALS["TTSFUNCTION"]="pockettts"; $GLOBALS["PATCH_OVERRIDE_VOICE"]="unit_voice_one"; '
            .'$first=synthTtsCacheKeyForLine("Settler","Hello"); '
            .'$GLOBALS["PATCH_OVERRIDE_VOICE"]="unit_voice_two"; $second=synthTtsCacheKeyForLine("Settler","Hello"); '
            .'$GLOBALS["TTSFUNCTION"]="omnivoice"; $third=synthTtsCacheKeyForLine("Settler","Hello"); '
            .'echo json_encode([$initial,$first,$second,$third,isset($GLOBALS["db"])]);';
        $output = (string)shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script));
        [$initial,$first,$second,$third,$hasDb] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($initial, 'The regression must start without a transitive voice-helper include.');
        $this->assertFalse($hasDb, 'An already selected voice must not bootstrap another database connection.');
        $this->assertStringStartsWith("synth.tts.v5\npockettts\nSettler\nunit_voice_one\n", $first);
        $this->assertNotSame('Hello', $first);
        $this->assertNotSame($first, $second);
        $this->assertNotSame($second, $third);
    }

    public function testPocketClonePreparationUsesSelectedEndpointAndKeepsBuiltinBackendPaths(): void
    {
        $script = 'function synth_sync_voice_clone_sample($voice,$sample,$options=[]) { $GLOBALS["sync_args"]=[$voice,$sample,$options]; return $GLOBALS["sync_ok"]; } '
            .'require '.var_export(dirname(__DIR__,2).'/tts/tts-pockettts.php',true).'; '
            .'$root=sys_get_temp_dir()."/synth-clone-unit-".bin2hex(random_bytes(8)); mkdir($root); $file=$root."/sample.wav"; '
            .'$wav="RIFF".pack("V",38)."WAVEfmt ".pack("VvvVVvv",16,1,1,24000,48000,2,16)."data".pack("V",2)."\0\0"; '
            .'try { file_put_contents($file,$wav); $GLOBALS["sync_ok"]=true; '
            .'$one=pockettts_prepare_voice("http://127.0.0.1:18976",$file,$root); '
            .'$args=$GLOBALS["sync_args"]; $GLOBALS["sync_ok"]=false; '
            .'$two=pockettts_prepare_voice("http://127.0.0.1:18976",$file,$root); '
            .'file_put_contents($file,substr($wav,0,-2)."\1\0"); $GLOBALS["sync_ok"]=true; '
            .'$replacement=pockettts_prepare_voice("http://127.0.0.1:18976",$file,$root); '
            .'$snapshotPreserved=file_get_contents($args[1])===$wav; '
            .'file_put_contents($file,str_repeat("x",60)); $invalid=pockettts_prepare_voice("http://127.0.0.1:18976",$file,$root); '
            .'unset($GLOBALS["sync_args"]); $preset=pockettts_prepare_voice("http://127.0.0.1:18976","builtin",$root); '
            .'$cpp=pockettts_prepare_voice("http://127.0.0.1:8086/v1/audio/speech","Unit Voice"); '
            .'echo json_encode([$one,$two,$args,$preset,$cpp,isset($GLOBALS["sync_args"]),hash("sha256",$wav),$replacement,$snapshotPreserved,$invalid]); '
            .'} finally { foreach (glob($root."/data/tmp/voice_uploads/*.wav") as $path) unlink($path); '
            .'unlink($file); rmdir($root."/data/tmp/voice_uploads"); rmdir($root."/data/tmp"); rmdir($root."/data"); rmdir($root); }';
        $output=(string)shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script));
        [$one,$two,$args,$preset,$cpp,$extraSync,$hash,$replacement,$snapshotPreserved,$invalid]=json_decode($output,true,flags:JSON_THROW_ON_ERROR);
        $this->assertSame('synth_'.$hash,$one);
        $this->assertFalse($two);
        $this->assertSame($one,$args[0]);
        $this->assertSame($hash.'.wav',basename($args[1]));
        $this->assertSame('http://127.0.0.1:18976',$args[2]['endpoint']);
        $this->assertSame('pockettts',$args[2]['driver']);
        $this->assertTrue($args[2]['verify_remote']);
        $this->assertArrayNotHasKey('actor_name',$args[2]);
        $this->assertSame('builtin',$preset);
        $this->assertSame('Unit Voice',$cpp);
        $this->assertFalse($extraSync);
        $this->assertNotSame($one,$replacement);
        $this->assertTrue($snapshotPreserved);
        $this->assertFalse($invalid);
    }

    public function testPrivateVoiceSnapshotsAreNotDevelopmentStaticAssets(): void
    {
        foreach (['/Synthserver/data/tmp/voice_uploads/test.wav','/Synthserver/DATA/TMP/VOICE_UPLOADS/test.wav',
            '/Synthserver/data%2Ftmp%2Fvoice_uploads%2Ftest.wav', '/Synthserver/data/tmp/npc_tts_jobs/job.json',
            '/Synthserver/DATA/TMP/NPC_TTS_JOBS/job.json', '/Synthserver/data%2Ftmp%2Fnpc_tts_jobs%2Fjob.json'] as $path) {
            $script='$_SERVER["REQUEST_URI"]='.var_export($path,true).'; require '.var_export(dirname(__DIR__,2).'/router.php',true).'; echo http_response_code();';
            $this->assertSame('403',trim((string)shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script))));
        }
        $apache=file_get_contents(dirname(__DIR__,2).'/tools/apache/synthserver.conf');
        $this->assertMatchesRegularExpression('~<Directory /var/www/html/Synthserver/data/tmp/voice_uploads>\\s+Require all denied\\s+</Directory>~',$apache);
        $this->assertMatchesRegularExpression('~<Directory /var/www/html/Synthserver/data/tmp/npc_tts_jobs>\\s+Require all denied\\s+</Directory>~',$apache);
    }

    public function testQueuedSpeechPinsSettingsSamplesFallbackAndRestoresCaller(): void
    {
        $script = 'function synth_sync_voice_clone_sample($voice,$sample,$options=[]) { $GLOBALS["sync_args"]=[$voice,$sample,$options]; return true; } '
            .'require '.var_export(dirname(__DIR__,2).'/lib/synth_tts_plan.php',true).'; '
            .'require '.var_export(dirname(__DIR__,2).'/tts/tts-pockettts.php',true).'; '. <<<'PHP'
$root=sys_get_temp_dir().'/synth-plan-unit-'.bin2hex(random_bytes(8)); mkdir($root);
$wav='RIFF'.pack('V',38).'WAVEfmt '.pack('VvvVVvv',16,1,1,24000,48000,2,16).'data'.pack('V',2)."\0\0";
$sample=$root.'/voice.wav'; $fallback=$root.'/fallback.wav';
try {
    file_put_contents($sample,$wav); file_put_contents($fallback,$wav);
    $GLOBALS['SYNTH_NAME']='Settler'; $GLOBALS['TTSFUNCTION']='pockettts';
    $GLOBALS['TTS']=['POCKETTTS'=>['endpoint'=>'http://127.0.0.1:18976','API_KEY'=>'fixture-secret','language'=>'en']];
    $GLOBALS['PATCH_OVERRIDE_VOICE']=$sample; $GLOBALS['TTS_NPC_FALLBACK_VOICE']=$fallback;
    $GLOBALS['db']=(object)['marker'=>'caller']; $db=$GLOBALS['db'];
    $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=true;
    $GLOBALS['TTSFUNCTION']='chatterbox'; $otherBefore=synth_capture_tts_plan($root,'Settler','Hello','happy');
    $GLOBALS['TTSFUNCTION']='pockettts';
    $plan=synth_capture_tts_plan($root,'Settler','Hello','happy');
    $plan=json_decode(json_encode($plan),true);
    file_put_contents($sample,substr($wav,0,-2)."\1\0"); file_put_contents($fallback,substr($wav,0,-2)."\2\0");
    $GLOBALS['TTSFUNCTION']='chatterbox'; $otherAfter=synth_capture_tts_plan($root,'Settler','Hello','happy');
    $GLOBALS['TTSFUNCTION']='pockettts';
    $GLOBALS['TTS']['POCKETTTS']['endpoint']='http://127.0.0.1:18977';
    $second=synth_capture_tts_plan($root,'Settler','Hello','happy');
    $GLOBALS['SYNTH_NAME']='Next actor'; $GLOBALS['PATCH_OVERRIDE_VOICE']='different';
    $inside=synth_with_tts_plan($plan,$plan['cache_seed'],'Settler',$root,static function() use($root,$sample,$fallback) {
        $voice=pockettts_prepare_voice($GLOBALS['TTS']['POCKETTTS']['endpoint'],$GLOBALS['PATCH_OVERRIDE_VOICE'],$root);
        $primary=$GLOBALS['sync_args'];
        pockettts_prepare_voice($GLOBALS['TTS']['POCKETTTS']['endpoint'],$GLOBALS['TTS_NPC_FALLBACK_VOICE'],$root);
        return [$GLOBALS['SYNTH_NAME'],$voice,$primary,$GLOBALS['sync_args'],pockettts_backend_voice_payload($sample),$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']];
    });
    $restored=[$GLOBALS['SYNTH_NAME'],$GLOBALS['PATCH_OVERRIDE_VOICE'],$GLOBALS['TTS']['POCKETTTS']['endpoint'],$GLOBALS['db']===$db,!isset($GLOBALS['SYNTH_ACTIVE_TTS_PLAN'])];
    try { synth_with_tts_plan($plan,$plan['cache_seed'],'Settler',$root,static function() { throw new RuntimeException('cancel fixture'); }); }
    catch(RuntimeException $e) { $exceptionRestored=$e->getMessage()==='cancel fixture' && $GLOBALS['SYNTH_NAME']==='Next actor' && !isset($GLOBALS['SYNTH_ACTIVE_TTS_PLAN']); }
    $reject=[];
    foreach (['speaker','seed','owner','sample'] as $case) {
        $invalid=$plan; $speaker='Settler';
        if ($case==='speaker') $speaker='Different';
        if ($case==='seed') $invalid['inputs']['TTS']['POCKETTTS']['endpoint']='changed';
        if ($case==='owner') { $invalid['inputs']['db']='forbidden'; $invalid['cache_seed']=synth_tts_plan_seed($invalid); }
        if ($case==='sample') file_put_contents($plan['samples'][$sample]['path'],substr($wav,0,-2)."\3\0");
        try { synth_validate_tts_plan($invalid,$invalid['cache_seed'],$speaker,$root); $reject[]=false; }
        catch(RuntimeException $e) { $reject[]=true; }
    }
    // Closures are retained only by the in-process queue, not silently lost by JSON worker handoff.
    $GLOBALS['TTS_FALLBACK_FNCT']=static fn()=>false; $GLOBALS['PATCH_OVERRIDE_VOICE']='builtin';
    unset($GLOBALS['TTS_NPC_FALLBACK_VOICE']);
    $local=synth_capture_tts_plan($root,'Next actor','Hello','happy');
    echo json_encode([$plan['cache_seed'],$second['cache_seed'],$inside,$restored,$exceptionRestored,$reject,hash('sha256',$wav),$local['portable'],is_callable($local['local_callbacks']['fallback']),$otherBefore['cache_seed']!==$otherAfter['cache_seed']]);
} finally {
    foreach(glob($root.'/data/tmp/voice_uploads/*.wav')?:[] as $file) unlink($file);
    unlink($sample); unlink($fallback); rmdir($root.'/data/tmp/voice_uploads'); rmdir($root.'/data/tmp'); rmdir($root.'/data'); rmdir($root);
}
PHP;
        $output=(string)shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script));
        [$first,$second,$inside,$restored,$exceptionRestored,$reject,$hash,$portable,$callable,$otherSampleSensitive]=json_decode($output,true,flags:JSON_THROW_ON_ERROR);
        $this->assertStringStartsWith("synth.tts.v6\n",$first);
        $this->assertStringNotContainsString('fixture-secret',$first);
        $this->assertNotSame($first,$second);
        $this->assertSame('Settler',$inside[0]);
        $this->assertSame('synth_'.$hash,$inside[1]);
        $this->assertSame('http://127.0.0.1:18976',$inside[2][2]['endpoint']);
        $this->assertSame($inside[2][0],$inside[3][0], 'Both original samples had the same bytes before replacement.');
        $this->assertSame($inside[2][1],$inside[4]['voice_ref']);
        $this->assertTrue($inside[5]);
        $this->assertSame(['Next actor','different','http://127.0.0.1:18977',true,true],$restored);
        $this->assertTrue($exceptionRestored);
        $this->assertSame([true,true,true,true],$reject);
        $this->assertFalse($portable);
        $this->assertTrue($callable);
        $this->assertTrue($otherSampleSensitive, 'Non-PocketTTS adapters must retain sample-aware cache keys.');
    }

    public function testTtsDriverSwitchingDoesNotReuseTheLastIncludedAdapter(): void
    {
        $root=dirname(__DIR__,2);
        $script='require '.var_export($root.'/lib/chat_helper_functions.php',true).'; '
            .'require '.var_export($root.'/tts/tts-phpunit.php',true).'; '
            .'require '.var_export($root.'/tts/tts-pockettts.php',true).'; '
            .'$GLOBALS["SYNTH_TTS_DRIVERS"]["pockettts"]=static fn()=>"pocket-fixture"; '
            .'$out=[]; foreach (["phpunit","pockettts","phpunit"] as $driver) { '
            .'$GLOBALS["TTSFUNCTION"]=$driver; $out[]=callConfiguredTts("Hello","default","switch-fixture"); } echo json_encode($out);';
        $output=(string)shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script));
        $expected='soundcache/'.md5('switch-fixture').'.wav';
        $this->assertSame([$expected,'pocket-fixture',$expected],json_decode($output,true,flags:JSON_THROW_ON_ERROR));
    }

    public function testRealCloneAdaptersCanAlternateInEveryLoadOrder(): void
    {
        $root=dirname(__DIR__,2);
        $providers=['pockettts','chatterbox','xtts-fastapi'];
        foreach ($providers as $first) foreach ($providers as $second) {
            if ($first===$second) continue;
            $third=array_values(array_diff($providers,[$first,$second]))[0];
            $order=[$first,$second,$third,$first];
            $script='require '.var_export($root.'/lib/chat_helper_functions.php',true).'; '
                .'require '.var_export($root.'/lib/logger.php',true).'; '
                .'$root='.var_export($root,true).'; $order='.var_export($order,true).'; '. <<<'PHP'
$GLOBALS['TTS']=[]; $GLOBALS['TTS_FFMPEG_FILTERS']=[]; $GLOBALS['SYNTH_NAME']='Fixture';
foreach (['POCKETTTS','CHATTERBOX','XTTSFASTAPI'] as $provider) {
    $GLOBALS['TTS'][$provider]=['endpoint'=>'http://provider.invalid/','language'=>'ja','voiceid'=>'fixture'];
}
$seen=[];
// Stop after real adapter text preparation, before any transport/file/FFmpeg work.
$GLOBALS['HOOKS']['XTTS_TEXTMODIFIER']=[static function($text) use (&$seen) {
    $seen[]=[$GLOBALS['TTSFUNCTION'],$text,basename((new ReflectionFunction($GLOBALS['TTS_IN_USE']))->getFileName())];
    throw new RuntimeException('fixture-before-transport');
}];
foreach ($order as $driver) {
    $GLOBALS['TTSFUNCTION']=$driver;
    try { callConfiguredTts('10','default','isolated-driver-fixture'); throw new LogicException('Hook not reached'); }
    catch(RuntimeException $error) { if ($error->getMessage()!=='fixture-before-transport') throw $error; }
}
echo json_encode($seen);
PHP;
            $output=(string)shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script));
            $expected=array_map(static fn($driver)=>[$driver,'十','tts-'.$driver.'.php'],$order);
            $this->assertSame($expected,json_decode($output,true,flags:JSON_THROW_ON_ERROR),implode(' -> ',$order));
        }
    }

    public function testAllSelectableTtsAdaptersLoadTogetherInBothOrders(): void
    {
        $root=dirname(__DIR__,2);
        foreach ([false,true] as $reverse) {
            $script='$root='.var_export($root,true).'; $schema=json_decode(file_get_contents($root."/conf/conf_schema.json"),true); '
                .'$GLOBALS["TTSFUNCTION"]="selected-fixture"; $GLOBALS["TTS"]=["selected"=>"unchanged"]; '
                .'$drivers=array_values(array_filter($schema["TTSFUNCTION"]["values"],static fn($name)=>$name!=="none")); sort($drivers); '
                .($reverse ? '$drivers=array_reverse($drivers); ' : '')
                .'foreach ($drivers as $driver) { require_once $root."/tts/tts-".$driver.".php"; } '
                .'$valid=[]; foreach($drivers as $driver) $valid[$driver]=is_callable($GLOBALS["SYNTH_TTS_DRIVERS"][$driver]??null); '
                .'echo json_encode([$valid,$GLOBALS["TTSFUNCTION"],$GLOBALS["TTS"],isset($GLOBALS["db"])]);';
            $output=(string)shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script));
            [$registered,$selected,$config,$hasDb]=json_decode($output,true,flags:JSON_THROW_ON_ERROR);
            $this->assertCount(17,$registered);
            $this->assertNotContains(false,$registered);
            $this->assertSame('selected-fixture',$selected);
            $this->assertSame(['selected'=>'unchanged'],$config);
            $this->assertFalse($hasDb);
        }
    }

    public function testAzureUsesOnlyTheSelectedPlannedCredentialWithoutTokenCache(): void
    {
        $script='require '.var_export(dirname(__DIR__,2).'/tts/tts-azure.php',true).'; '. <<<'PHP'
class AzureFixtureTransport {
    public $context;
    public function stream_open($path,$mode,$options,&$opened_path) {
        $GLOBALS['requests'][]=[$path,stream_context_get_options($this->context)['http']];
        throw new RuntimeException('fixture-request-captured');
    }
}
stream_wrapper_unregister('https'); stream_wrapper_register('https',AzureFixtureTransport::class);
$GLOBALS['SYNTH_ACTIVE_TTS_PLAN']=['fixture'=>true]; $GLOBALS['AVOID_TTS_CACHE']=true;
$GLOBALS['TTS_FFMPEG_FILTERS']=[]; $GLOBALS['requests']=[];
$GLOBALS['db']=new class { public $reads=0; public function fetchOne($sql) { ++$this->reads; throw new RuntimeException('Unexpected database read'); } };
foreach (['unitone'=>'fixture-key-one','unittwo'=>'fixture-key-two'] as $region=>$key) {
    $GLOBALS['TTS']['AZURE']=['region'=>$region,'API_KEY'=>$key,'voice'=>'en-US-JennyNeural',
        'validMoods'=>[],'fixedMood'=>'','volume'=>0,'rate'=>1,'countour'=>''];
    try { $GLOBALS['TTS_IN_USE']('Hello','default','azure-fixture'); throw new LogicException('Transport not reached'); }
    catch(RuntimeException $error) { if ($error->getMessage()!=='fixture-request-captured') throw $error; }
}
$GLOBALS['TTS']['AZURE']['API_KEY']='';
$missing=$GLOBALS['TTS_IN_USE']('Hello','default','azure-fixture');
echo json_encode([$GLOBALS['requests'],$missing,$GLOBALS['db']->reads]);
PHP;
        $output=(string)shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script));
        [$requests,$missing,$reads]=json_decode($output,true,flags:JSON_THROW_ON_ERROR);
        $this->assertCount(2,$requests);
        foreach (['one','two'] as $index=>$suffix) {
            $this->assertSame('https://unit'.$suffix.'.tts.speech.microsoft.com/cognitiveservices/v1',$requests[$index][0]);
            $this->assertStringContainsString('Ocp-Apim-Subscription-Key: fixture-key-'.$suffix,$requests[$index][1]['header']);
            $this->assertStringNotContainsString('Authorization:',$requests[$index][1]['header']);
            $this->assertStringContainsString('Hello',$requests[$index][1]['content']);
        }
        $this->assertFalse($missing);
        $this->assertSame(0,$reads);
    }

    public function testPlannedCloneFailuresUseOnlyCapturedFallbackAndEndpoint(): void
    {
        $script='$root='.var_export(dirname(__DIR__,2),true).'; require $root."/lib/chat_helper_functions.php"; require $root."/lib/synth_tts_plan.php"; '. <<<'PHP'
class FailedSpeechFixtureStream {
    public $context;
    public function stream_open($path,$mode,$options,&$opened_path) {
        $http=stream_context_get_options($this->context)['http'];
        $GLOBALS['attempts'][]=[$path,json_decode($http['content'],true)['speaker_wav']??null];
        return false;
    }
}
foreach(['http','https'] as $scheme) { stream_wrapper_unregister($scheme); stream_wrapper_register($scheme,FailedSpeechFixtureStream::class); }
$result=[]; $files=[];
try {
    foreach(['pockettts'=>'POCKETTTS','chatterbox'=>'CHATTERBOX','xtts-fastapi'=>'XTTSFASTAPI','omnivoice'=>'OMNIVOICE'] as $driver=>$provider) foreach([false,true] as $fallback) {
        $GLOBALS['TTSFUNCTION']=$driver; $GLOBALS['SYNTH_NAME']='Failure Fixture';
        $GLOBALS['TTS']=[$provider=>['endpoint'=>'http://fixture.invalid:8024','language'=>$driver==='omnivoice'?'':'en','voiceid'=>'fixture-primary']];
        $GLOBALS['PATCH_OVERRIDE_VOICE']='fixture-primary'; $GLOBALS['TTS_NPC_ORIGINAL_VOICE']='fixture-primary';
        $GLOBALS['TTS_NPC_FALLBACK_VOICE']=$fallback?'fixture-fallback':'';
        $GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA']=['npc_name'=>'Failure Fixture','voiceid'=>'fixture-primary'];
        $GLOBALS['TTS_FFMPEG_FILTERS']=[]; $GLOBALS['AVOID_TTS_CACHE']=true;
        $text='failure-fixture-'.bin2hex(random_bytes(12));
        $plan=synth_capture_tts_plan($root,'Failure Fixture',$text,'default',$text);
        $file=$root.'/soundcache/'.md5($plan['cache_seed']).'.err';
        if(is_file($file)) throw new RuntimeException('Fixture path already exists');
        $files[]=$file;
        $GLOBALS['attempts']=[]; $GLOBALS['PATCH_OVERRIDE_VOICE']='caller'; $GLOBALS['TTSFUNCTION']='none';
        $output=synth_call_planned_npc_tts($plan,$text,'default',$plan['cache_seed']);
        $result[]=[$driver,$fallback,$output,$GLOBALS['attempts'],$GLOBALS['PATCH_OVERRIDE_VOICE'],$GLOBALS['TTSFUNCTION']];
    }
    echo json_encode($result);
} finally { foreach($files as $file) if(is_file($file)) unlink($file); }
PHP;
        // No network is possible: stream transports are fixtures and curl_init is disabled in this child.
        $nullDevice=PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null';
        $output=(string)shell_exec(escapeshellarg(PHP_BINARY).' -d disable_functions=curl_init -r '.escapeshellarg($script).' 2>'.$nullDevice);
        $results=json_decode($output,true,flags:JSON_THROW_ON_ERROR);
        $this->assertCount(8,$results);
        foreach($results as [$driver,$fallback,$output,$attempts,$voice,$selected]) {
            $this->assertFalse($output);
            $this->assertSame($fallback?['fixture-primary','fixture-fallback']:['fixture-primary'],array_column($attempts,1),$driver);
            foreach($attempts as [$url]) $this->assertSame('http://fixture.invalid:8024/tts_to_audio'.($driver==='chatterbox'?'/':''),$url);
            $this->assertSame('caller',$voice);
            $this->assertSame('none',$selected);
        }
    }

    public function testOmniVoiceQueuedTextHooksDoNotFollowLaterCallerChanges(): void
    {
        $script='$root='.var_export(dirname(__DIR__,2),true).'; require $root."/lib/synth_tts_plan.php"; require $root."/tts/tts-omnivoice.php"; '. <<<'PHP'
$results=[];
$GLOBALS['TTSFUNCTION']='omnivoice'; $GLOBALS['SYNTH_NAME']='Hook Fixture'; $GLOBALS['PATCH_OVERRIDE_VOICE']='fixture-voice';
$GLOBALS['TTS']=['OMNIVOICE'=>['voiceid'=>'fixture-voice','fallback_male'=>'male','fallback_female'=>'female']];
foreach(['none','omni','xtts','both'] as $case) {
    $GLOBALS['HOOKS']=[];
    if(in_array($case,['omni','both'],true)) $GLOBALS['HOOKS']['OMNIVOICE_TEXTMODIFIER']=[static fn($text)=>$text.' omni-original'];
    if(in_array($case,['xtts','both'],true)) $GLOBALS['HOOKS']['XTTS_TEXTMODIFIER']=[static fn($text)=>$text.' xtts-original'];
    $plan=synth_capture_tts_plan($root,'Hook Fixture','Hello','default');
    $GLOBALS['HOOKS']=['OMNIVOICE_TEXTMODIFIER'=>[static fn($text)=>$text.' omni-new'],
        'XTTS_TEXTMODIFIER'=>[static fn($text)=>$text.' xtts-new'],'unrelated'=>['preserved']];
    $caller=$GLOBALS['HOOKS'];
    $inside=synth_with_tts_plan($plan,$plan['cache_seed'],'Hook Fixture',$root,static fn()=>[omnivoice_apply_text_hooks('Hello'),omnivoice_fallback_candidates('fixture-voice')]);
    try { synth_with_tts_plan($plan,$plan['cache_seed'],'Hook Fixture',$root,static function() { throw new RuntimeException('cancel-fixture'); }); }
    catch(RuntimeException $e) { if($e->getMessage()!=='cancel-fixture') throw $e; }
    $results[]=[$case,$plan['portable'],$inside,$GLOBALS['HOOKS']===$caller,!isset($GLOBALS['SYNTH_ACTIVE_TTS_PLAN']),omnivoice_fallback_candidates('fixture-voice')];
}
echo json_encode($results);
PHP;
        $results=json_decode((string)shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script)),true,flags:JSON_THROW_ON_ERROR);
        $this->assertCount(4,$results);
        foreach($results as [$case,$portable,$inside,$restored,$cleared,$legacyFallbacks]) {
            $this->assertSame($case==='none',$portable);
            $this->assertSame('Hello'.(in_array($case,['omni','both'],true)?' omni-original':'').(in_array($case,['xtts','both'],true)?' xtts-original':''),$inside[0]);
            $this->assertSame([],$inside[1]);
            $this->assertTrue($restored); $this->assertTrue($cleared);
            $this->assertSame(['male','female','hook_fixture'],$legacyFallbacks);
        }
    }

    public function testPlannedAudioCppRejectsUncapturedVoice(): void
    {
        $script='require '.var_export(dirname(__DIR__,2).'/tts/tts-pockettts.php',true).'; '
            .'$GLOBALS["SYNTH_ACTIVE_TTS_PLAN"]=["samples"=>["captured-preset"=>null]]; '
            .'$allowed=pockettts_prepare_voice("http://127.0.0.1:8086","captured-preset"); '
            .'$rejected=pockettts_prepare_voice("http://127.0.0.1:8086","unplanned"); '
            .'try { pockettts_backend_voice_payload("unplanned"); $guard=false; } catch(RuntimeException $e) { $guard=true; } '
            .'echo json_encode([$allowed,$rejected,$guard]);';
        $output=(string)shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script));
        $this->assertSame(['captured-preset',false,true],json_decode($output,true,flags:JSON_THROW_ON_ERROR));
    }

    public function testQueuedXttsAndChatterboxUploadCapturedSamplesBeforeSynthesis(): void
    {
        $script='require '.var_export(dirname(__DIR__,2).'/lib/synth_tts_plan.php',true).'; '
            .'require '.var_export(dirname(__DIR__,2).'/tts/tts-chatterbox.php',true).'; '
            .'require '.var_export(dirname(__DIR__,2).'/tts/tts-xtts-fastapi.php',true).'; '. <<<'PHP'
function synth_sync_voice_clone_sample($voice,$path,$options=[]) {
    $GLOBALS['uploads'][]=[$voice,hash_file('sha256',$path),$options];
    return $GLOBALS['upload_ok'];
}
class CapturedCloneFixtureTransport {
    public $context;
    public function stream_open($path,$mode,$options,&$opened_path) {
        $GLOBALS['requests'][]=[$path,json_decode(stream_context_get_options($this->context)['http']['content'],true)];
        throw new RuntimeException('fixture-request-captured');
    }
}
stream_wrapper_unregister('http'); stream_wrapper_register('http',CapturedCloneFixtureTransport::class);
$root=sys_get_temp_dir().'/synth-clone-plan-'.bin2hex(random_bytes(8)); mkdir($root);
$wav='RIFF'.pack('V',38).'WAVEfmt '.pack('VvvVVvv',16,1,1,24000,48000,2,16).'data'.pack('V',2)."\0\0";
$primary=$root.'/primary.wav'; $fallback=$root.'/fallback.wav'; $preset=$root.'/later.wav'; $results=[];
try {
    foreach(['chatterbox'=>'CHATTERBOX','xtts-fastapi'=>'XTTSFASTAPI'] as $driver=>$provider) {
        file_put_contents($primary,$wav); file_put_contents($fallback,substr($wav,0,-2)."\1\0");
        if(is_file($preset)) unlink($preset);
        $GLOBALS['TTSFUNCTION']=$driver; $GLOBALS['TTS']=[$provider=>['endpoint'=>'http://fixture.invalid:18976','voiceid'=>$primary,'language'=>'en']];
        $GLOBALS['PATCH_OVERRIDE_VOICE']=$primary; $GLOBALS['TTS_NPC_FALLBACK_VOICE']=$fallback; $GLOBALS['AVOID_TTS_CACHE']=true;
        $plan=synth_capture_tts_plan($root,'Clone Fixture','Hello','default');
        $GLOBALS['PATCH_OVERRIDE_VOICE']=$preset;
        $presetPlan=synth_capture_tts_plan($root,'Clone Fixture','Hello','default');
        file_put_contents($primary,substr($wav,0,-2)."\2\0"); file_put_contents($fallback,substr($wav,0,-2)."\3\0"); file_put_contents($preset,$wav);
        $GLOBALS['PATCH_OVERRIDE_VOICE']='caller'; $GLOBALS['TTSFUNCTION']='none'; $GLOBALS['TTS']=['caller'=>'unchanged'];
        $GLOBALS['uploads']=[]; $GLOBALS['requests']=[]; $GLOBALS['upload_ok']=true;
        $out=synth_with_tts_plan($plan,$plan['cache_seed'],'Clone Fixture',$root,static function() use($driver,$provider,$fallback,$primary,$root) {
            foreach([$primary,$fallback] as $voice) {
                $GLOBALS['PATCH_OVERRIDE_VOICE']=$voice;
                try { $GLOBALS['SYNTH_TTS_DRIVERS'][$driver]('Hello','default','captured-clone-fixture'); throw new LogicException('No synthesis request'); }
                catch(RuntimeException $e) { if($e->getMessage()!=='fixture-request-captured') throw $e; }
            }
            $unknown=synth_prepare_planned_clone_voice($driver,'http://fixture.invalid:18976','uncaptured',$root);
            $mismatch=synth_prepare_planned_clone_voice($driver==='chatterbox'?'xtts-fastapi':'chatterbox','http://fixture.invalid:18976',$primary,$root);
            $GLOBALS['upload_ok']=false;
            $GLOBALS['PATCH_OVERRIDE_VOICE']=$primary;
            $failed=$GLOBALS['SYNTH_TTS_DRIVERS'][$driver]('Hello','default','captured-clone-fixture');
            return [$unknown,$mismatch,$failed];
        });
        $late=synth_with_tts_plan($presetPlan,$presetPlan['cache_seed'],'Clone Fixture',$root,static fn()=>synth_prepare_planned_clone_voice($driver,'http://fixture.invalid:18976',$preset,$root));
        $results[]=[$driver,$out,$GLOBALS['uploads'],$GLOBALS['requests'],$late,$GLOBALS['TTSFUNCTION'],$GLOBALS['TTS'],$GLOBALS['PATCH_OVERRIDE_VOICE'],
            array_column($plan['samples'],'sha256'),hash_file('sha256',$primary),hash_file('sha256',$fallback)];
    }
    echo json_encode($results);
} finally {
    foreach(glob($root.'/data/tmp/voice_uploads/*.wav')?:[] as $file) unlink($file);
    foreach([$primary,$fallback,$preset] as $file) if(is_file($file)) unlink($file);
    rmdir($root.'/data/tmp/voice_uploads'); rmdir($root.'/data/tmp'); rmdir($root.'/data'); rmdir($root);
}
PHP;
        $output=(string)shell_exec(escapeshellarg(PHP_BINARY).' -d disable_functions=curl_init -r '.escapeshellarg($script));
        $results=json_decode($output,true,flags:JSON_THROW_ON_ERROR);
        $this->assertCount(2,$results);
        foreach($results as [$driver,$out,$uploads,$requests,$late,$selected,$settings,$voice,$hashes,$newPrimary,$newFallback]) {
            $this->assertSame([false,false,false],$out);
            $this->assertCount(3,$uploads);
            $this->assertCount(2,$requests,'Failed provisioning must not synthesize or try an uncaptured voice.');
            foreach([0,1] as $index) {
                $this->assertSame($hashes[$index],$uploads[$index][1]);
                $this->assertSame('synth_'.$hashes[$index],$requests[$index][1]['speaker_wav']);
                $this->assertSame('http://fixture.invalid:18976/tts_to_audio'.($driver==='chatterbox'?'/':''),$requests[$index][0]);
                $this->assertSame($driver,$uploads[$index][2]['driver']);
                $this->assertSame('http://fixture.invalid:18976',$uploads[$index][2]['endpoint']);
                $this->assertTrue($uploads[$index][2]['verify_remote']);
            }
            $this->assertNotSame($newPrimary,$hashes[0]);
            $this->assertNotSame($newFallback,$hashes[1]);
            $this->assertFalse($late,'A missing captured local path must never become a mutable backend reference.');
            $this->assertSame(['none',['caller'=>'unchanged'],'caller'],[$selected,$settings,$voice]);
        }
    }

    public function testCloudPlansPinLocalSampleAndCloneLanguageIdentity(): void
    {
        $script='require '.var_export(dirname(__DIR__,2).'/lib/synth_tts_plan.php',true).'; '
            .'require '.var_export(dirname(__DIR__,2).'/tts/tts-cartesia.php',true).'; '
            .'require '.var_export(dirname(__DIR__,2).'/tts/tts-inworld.php',true).'; '. <<<'PHP'
$root=sys_get_temp_dir().'/synth-cloud-plan-'.bin2hex(random_bytes(8)); mkdir($root);
stream_wrapper_unregister('https'); // This sample-identity test never calls a real voice service.
$wav='RIFF'.pack('V',38).'WAVEfmt '.pack('VvvVVvv',16,1,1,24000,48000,2,16).'data'.pack('V',2)."\0\0";
$sample=$root.'/voice.wav'; $fallback=$root.'/fallback.wav'; $results=[];
try {
    foreach(['cartesia'=>'Cartesia','inworld'=>'Inworld'] as $driver=>$title) {
        file_put_contents($sample,$wav); file_put_contents($fallback,$wav);
        $GLOBALS['TTSFUNCTION']=$driver; $GLOBALS['SYNTH_NAME']='Cloud Fixture';
        $GLOBALS['TTS']=[strtoupper($driver)=>['voiceid'=>$sample,'language'=>'en','API_KEY'=>'fixture-key','workspace'=>'workspace-A']];
        $GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA']=['npc_name'=>'Cloud Fixture','voiceid'=>'actor-original'];
        $GLOBALS['PATCH_OVERRIDE_VOICE']=$sample; $GLOBALS['TTS_NPC_FALLBACK_VOICE']=$fallback;
        $original=synth_capture_tts_plan($root,'Cloud Fixture','Hello','default');
        file_put_contents($sample,substr($wav,0,-2)."\1\0");
        $replacement=synth_capture_tts_plan($root,'Cloud Fixture','Hello','default');
        $GLOBALS['PATCH_OVERRIDE_TTS_LANGUAGE']='fr';
        $translated=synth_capture_tts_plan($root,'Cloud Fixture','Hello','default');
        unset($GLOBALS['PATCH_OVERRIDE_TTS_LANGUAGE'],$GLOBALS['PATCH_OVERRIDE_VOICE']);
        $GLOBALS['TTS'][strtoupper($driver)]['voiceid']='';
        $actor=synth_capture_tts_plan($root,'Cloud Fixture','Hello','default');
        $GLOBALS['SYNTH_CORE_CURRENT_NPC_DATA']=['npc_name'=>'Other Actor','voiceid'=>'must-not-leak'];
        $other=synth_capture_tts_plan($root,'Cloud Fixture','Hello','default');
        $GLOBALS['db']=new class { public function fetchOne($sql) { throw new LogicException('Unexpected worker-time NPC read'); } };
        $keys=[]; $getter='get'.$title.'VoiceName';
        foreach([$original,$replacement,$translated] as $plan) {
            $keys[]=synth_with_tts_plan($plan,$plan['cache_seed'],'Cloud Fixture',$root,static function() use($driver,$sample,$fallback,$getter) {
                try { synth_planned_cloud_voice_cache_name($driver,'uncaptured'); throw new LogicException('Unknown voice accepted'); }
                catch(RuntimeException $error) { if($error->getMessage()!=='Uncaptured cloud speech voice') throw $error; }
                return [synth_planned_cloud_voice_cache_name($driver,$sample),synth_planned_cloud_voice_cache_name($driver,$fallback),$getter()];
            });
        }
        unset($GLOBALS['db']);
        $results[]=[$keys,$sample,$actor['inputs']['PATCH_OVERRIDE_VOICE'],$other['inputs']['PATCH_OVERRIDE_VOICE'],
            file_get_contents($original['samples'][$sample]['path'])===$wav];
    }
    echo json_encode($results);
} finally {
    foreach(glob($root.'/data/tmp/voice_uploads/*.wav')?:[] as $file) unlink($file);
    unlink($sample); unlink($fallback); rmdir($root.'/data/tmp/voice_uploads'); rmdir($root.'/data/tmp'); rmdir($root.'/data'); rmdir($root);
}
PHP;
        $output=(string)shell_exec(escapeshellarg(PHP_BINARY).' -d disable_functions=curl_init -r '.escapeshellarg($script));
        $results=json_decode($output,true,flags:JSON_THROW_ON_ERROR);
        $this->assertCount(2,$results);
        foreach($results as [$keys,$sample,$actor,$other,$preserved]) {
            $this->assertSame($keys[0][0],$keys[0][1]);
            $this->assertNotSame($keys[0][0],$keys[1][0]);
            $this->assertNotSame($keys[1][0],$keys[2][0]);
            $this->assertSame($keys[0][1],$keys[1][1]);
            $this->assertNotSame($keys[1][1],$keys[2][1]);
            $this->assertSame([$sample,$sample,$sample],array_column($keys,2));
            $this->assertSame('actor-original',$actor);
            $this->assertSame('cloud_fixture',$other);
            $this->assertTrue($preserved);
        }
    }

    public function testCloudRemoteIdentityIsCapturedAndPaginationIsBounded(): void
    {
        $script='$root='.var_export(dirname(__DIR__,2),true).'; require $root."/lib/synth_tts_plan.php"; '
            .'require $root."/tts/tts-cartesia.php"; require $root."/tts/tts-inworld.php"; '. <<<'PHP'
class RemoteIdentityFixture {
    public $context; private string $body=''; private int $position=0;
    public function stream_open($url,$mode,$options,&$opened): bool {
        $http=stream_context_get_options($this->context)['http']; $GLOBALS['requests'][]=[$url,$http['timeout']];
        if($GLOBALS['cancel']) throw new SYNTHProtocolCancelled('fixture-cancel');
        $id=$GLOBALS['remote_id']; $name=$GLOBALS['voice'];
        $this->body=json_encode(['voices'=>$id===''?[]:[['id'=>$id,'name'=>$name,'voiceId'=>$id,'displayName'=>$name]],
            'has_more'=>$GLOBALS['cycle'],'next_page'=>$GLOBALS['cycle']?'repeat':'','nextPageToken'=>$GLOBALS['cycle']?'repeat':'']);
        return true;
    }
    public function stream_read($count): string { $out=substr($this->body,$this->position,$count); $this->position+=strlen($out); return $out; }
    public function stream_eof(): bool { return $this->position>=strlen($this->body); }
    public function stream_stat(): array { return []; }
}
function curl_exec($handle) { $GLOBALS['requests'][]=['fixture-curl-failure',60]; return false; }
stream_wrapper_unregister('https'); stream_wrapper_register('https',RemoteIdentityFixture::class);
$GLOBALS['db']=new class { public int $reads=0; public int $writes=0; public string $id=''; public function escape($value) { return $value; } public function fetchOne($sql) { ++$this->reads; if(!str_starts_with($sql,'SELECT value FROM conf_opts')) throw new LogicException('Unexpected SQL'); return ['value'=>$this->id]; } public function execQuery($sql) { ++$this->writes; throw new LogicException('Unexpected cache mutation'); } };
$results=[]; $bounds=[];
foreach(['cartesia'=>'Cartesia','inworld'=>'Inworld'] as $driver=>$title) {
    $create='getOrCreate'.$title.'Voice'; $list=$driver==='cartesia'?'listExistingCartesiaVoices':'listExistingInworldWorkspaceVoices';
    foreach(['cached','remote','missing'] as $case) {
        unset($GLOBALS['CARTESIA_REMOTE_VOICES_CACHE'],$GLOBALS['INWORLD_REMOTE_VOICES_CACHE']);
        $GLOBALS['requests']=[]; $GLOBALS['cycle']=false; $GLOBALS['cancel']=false;
        $GLOBALS['voice']='fixture-'.$case; $GLOBALS['remote_id']=$case==='remote'?'workspace-A__old':'';
        $GLOBALS['db']->id=$case==='cached'?'workspace-A__old':'';
        $GLOBALS['SYNTH_NAME']='Remote Fixture'; $GLOBALS['TTSFUNCTION']=$driver;
        $GLOBALS['PATCH_OVERRIDE_VOICE']=$GLOBALS['voice'];
        $GLOBALS['TTS']=[strtoupper($driver)=>['API_KEY'=>'fixture-key','workspace'=>'workspace-A']];
        $plan=synth_capture_tts_plan($root,'Remote Fixture','Hello','default');
        $plan=json_decode(json_encode($plan),true);
        $captureCalls=count($GLOBALS['requests']); $reads=$GLOBALS['db']->reads;
        $GLOBALS['db']->id='workspace-A__new'; $GLOBALS['remote_id']='workspace-A__new';
        $GLOBALS['PATCH_OVERRIDE_VOICE']='caller';
        $id=synth_with_tts_plan($plan,$plan['cache_seed'],'Remote Fixture',$root,static fn()=>$create($GLOBALS['voice']));
        $results[]=[$case,$id,$captureCalls,count($GLOBALS['requests'])===$captureCalls,$GLOBALS['db']->reads===$reads,
            $GLOBALS['PATCH_OVERRIDE_VOICE']==='caller',$plan['remote_voices'][$GLOBALS['voice']]];
        foreach($GLOBALS['requests'] as [$url,$timeout]) if($timeout<=0 || $timeout>10) throw new LogicException('Capture timeout escaped');
    }
    unset($GLOBALS['CARTESIA_REMOTE_VOICES_CACHE'],$GLOBALS['INWORLD_REMOTE_VOICES_CACHE']);
    $config=['api_key'=>'fixture-key','workspace'=>'workspace-A'];
    $GLOBALS['cycle']=true; $GLOBALS['requests']=[];
    $cycle=$list($config); $cycleCalls=count($GLOBALS['requests']);
    $GLOBALS['SYNTH_CLOUD_VOICE_LOOKUP_DEADLINE']=microtime(true)-1;
    $expired=$list($config); $expiredCalls=count($GLOBALS['requests']);
    unset($GLOBALS['SYNTH_CLOUD_VOICE_LOOKUP_DEADLINE']);
    $GLOBALS['cancel']=true; $GLOBALS['db']->id=''; $GLOBALS['PATCH_OVERRIDE_VOICE']='fixture-cancel';
    $GLOBALS['SYNTH_CLOUD_VOICE_LOOKUP_DEADLINE']=123;
    try { synth_capture_tts_plan($root,'Remote Fixture','Hello','default'); throw new LogicException('Cancellation swallowed'); }
    catch(SYNTHProtocolCancelled $error) { $restored=!isset($GLOBALS['SYNTH_ACTIVE_TTS_PLAN']) && $GLOBALS['SYNTH_CLOUD_VOICE_LOOKUP_DEADLINE']===123 && $GLOBALS['PATCH_OVERRIDE_VOICE']==='fixture-cancel'; }
    unset($GLOBALS['SYNTH_CLOUD_VOICE_LOOKUP_DEADLINE']);
    $bounds[]=[$cycle,$cycleCalls,$expired,$expiredCalls,$restored];
}
$failures=[];
foreach(['invalid-id','workspace-A__valid'] as $selectedId) {
    $GLOBALS['cancel']=false; $GLOBALS['cycle']=false; $GLOBALS['AVOID_TTS_CACHE']=true;
    $GLOBALS['db']->id=$selectedId; $GLOBALS['PATCH_OVERRIDE_VOICE']='fixture-failed-voice';
    $plan=synth_capture_tts_plan($root,'Remote Fixture','Hello','default');
    $GLOBALS['db']->id='workspace-A__caller'; $reads=$GLOBALS['db']->reads; $GLOBALS['requests']=[];
    $out=synth_with_tts_plan($plan,$plan['cache_seed'],'Remote Fixture',$root,static fn()=>$GLOBALS['SYNTH_TTS_DRIVERS']['inworld']('Hello','default','fixture-remote-pin-failure'));
    $failures[]=[$out,$GLOBALS['db']->reads===$reads,$GLOBALS['db']->writes,count($GLOBALS['requests'])];
}
echo json_encode([$results,$bounds,$failures]);
PHP;
        $output=(string)shell_exec(escapeshellarg(PHP_BINARY).' -d disable_functions=curl_exec -r '.escapeshellarg($script));
        [$results,$bounds,$failures]=json_decode($output,true,flags:JSON_THROW_ON_ERROR);
        $this->assertCount(6,$results);
        foreach($results as [$case,$id,$calls,$noHttp,$noSql,$restored,$captured]) {
            $this->assertSame($case==='missing'?false:'workspace-A__old',$id);
            $this->assertSame($case==='missing'?'':'workspace-A__old',$captured);
            $this->assertSame($case==='cached'?0:1,$calls);
            $this->assertTrue($noHttp); $this->assertTrue($noSql); $this->assertTrue($restored);
        }
        $this->assertSame([[[],2,[],2,true],[[],2,[],2,true]],$bounds);
        $this->assertSame([[false,true,0,0],[false,true,0,1]],$failures);
    }

    public function testCloudSpeechConfigAndVoiceCachesStayBoundToCapturedCredentials(): void
    {
        $script='$root='.var_export(dirname(__DIR__,2),true).'; require $root."/lib/synth_tts_plan.php"; '
            .'require $root."/tts/tts-cartesia.php"; require $root."/tts/tts-inworld.php"; '. <<<'PHP'
$GLOBALS['db']=new class { public int $reads=0; public function escape($value) { return $value; } public function fetchOne($sql) { if(str_starts_with($sql,'SELECT value FROM conf_opts')) return ['value'=>'fixture-captured-remote']; ++$this->reads; throw new RuntimeException('Unplanned settings read'); } public function fetchAll($sql) { return $this->fetchOne($sql); } };
$rows=[]; $scopes=[];
foreach(['cartesia'=>'Cartesia','inworld'=>'Inworld'] as $driver=>$title) {
    $getter='get'.$title.'ActiveConfig'; $resolver='resolve'.$title.'ConnectorRow'; $hydrate='hydrate'.$title.'ConnectorGlobals';
    foreach(['complete','missing-key','missing-workspace','missing-id'] as $case) {
        $GLOBALS['SYNTH_NAME']='Cloud Fixture'; $GLOBALS['TTSFUNCTION']=$driver;
        $GLOBALS['PATCH_OVERRIDE_VOICE']='fixture-cloud-voice';
        $GLOBALS['SYNTH_CORE_CURRENT_TTS_CONNECTOR_ID']=$case==='missing-id'?0:71;
        $GLOBALS['TTS']=[strtoupper($driver)=>['API_KEY'=>$case==='missing-key'?'':'fixture-captured-key','workspace'=>$case==='missing-workspace'?'':'workspace-A']];
        $plan=synth_capture_tts_plan($root,'Cloud Fixture','Hello','default');
        $GLOBALS['SYNTH_CORE_CURRENT_TTS_CONNECTOR_ID']=99;
        $GLOBALS['TTS']=[strtoupper($driver)=>['API_KEY'=>'fixture-new-key','workspace'=>'workspace-B']];
        $config=synth_with_tts_plan($plan,$plan['cache_seed'],'Cloud Fixture',$root,static function() use($getter,$resolver,$hydrate) {
            $config=$getter(); if($resolver()!==null || $hydrate()!==null) throw new RuntimeException('Planned resolver escaped'); return $config;
        });
        $rows[]=[$driver,$case,$config,$GLOBALS['SYNTH_CORE_CURRENT_TTS_CONNECTOR_ID'],$GLOBALS['TTS'][strtoupper($driver)]['API_KEY']];
    }
    $prefix='get'.$title.'VoiceCachePrefix'; $remote='get'.$title.'RemoteVoicesCacheKey';
    $one=['connector_id'=>71,'api_badge_id'=>1,'api_key'=>'fixture-key-one','workspace'=>'workspace-A'];
    $two=array_replace($one,['api_key'=>'fixture-key-two']);
    $scopes[$driver]=[$prefix($one),$prefix($two),$remote($one),$remote($two),
        $prefix(array_replace($one,['connector_id'=>72])),$prefix(array_replace($one,['api_badge_id'=>2])),
        $prefix(array_replace($one,['workspace'=>'workspace-B']))];
    $GLOBALS['SYNTH_ACTIVE_TTS_PLAN']=['inputs'=>['TTSFUNCTION'=>'wrong-provider']];
    try { $getter(); throw new LogicException('Provider mismatch accepted'); }
    catch(RuntimeException $error) { if($error->getMessage()!=='Queued speech provider mismatch') throw $error; }
    unset($GLOBALS['SYNTH_ACTIVE_TTS_PLAN']);
}
echo json_encode([$rows,$scopes,$GLOBALS['db']->reads]);
PHP;
        $output=(string)shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script));
        [$rows,$scopes,$reads]=json_decode($output,true,flags:JSON_THROW_ON_ERROR);
        $this->assertSame(0,$reads);
        $this->assertCount(8,$rows);
        foreach($rows as [$driver,$case,$config,$callerId,$callerKey]) {
            $this->assertSame($case==='missing-id'?0:71,$config['connector_id']);
            $this->assertSame($case==='missing-key'?'':'fixture-captured-key',$config['api_key']);
            $this->assertNull($config['row']);
            $this->assertSame(99,$callerId);
            $this->assertSame('fixture-new-key',$callerKey);
            if($driver==='inworld') {
                $this->assertSame($case==='missing-workspace'?'':'workspace-A',$config['workspace']);
                $this->assertSame($case==='missing-workspace'?'':'workspaces/workspace-A',$config['workspace_path']);
            }
        }
        foreach($scopes as $driver=>[$one,$two,$remoteOne,$remoteTwo,$otherId,$otherBadge,$otherWorkspace]) {
            $this->assertStringStartsWith($driver.'_voice_scope_v2_',$one);
            $this->assertStringNotContainsString('fixture-key-one',$one);
            $this->assertNotSame($one,$two);
            $this->assertSame($one,$remoteOne);
            $this->assertSame($two,$remoteTwo);
            $this->assertNotSame($one,$otherId);
            $this->assertSame($one,$otherBadge);
            if($driver==='inworld') $this->assertNotSame($one,$otherWorkspace);
        }
    }

    public function testCloneRemoteVerificationRecoversFromLostSamplesAndCachedFailure(): void
    {
        $script = 'function synth_voice_clone_sync_http_json($url,$timeout=4) { return str_ends_with($url,"/speakers_list") ? '
            .'["ok"=>true,"data"=>array_keys($GLOBALS["remote"])] : ["ok"=>false,"data"=>null]; } '
            .'function synth_voice_clone_sync_upload($endpoint,$voice,$path) { ++$GLOBALS["uploads"]; '
            .'$ok=!$GLOBALS["fail_upload"]; if ($ok) $GLOBALS["remote"][$voice]=true; '
            .'return ["ok"=>$ok,"http_code"=>$ok ? 200 : 503,"body"=>"","error"=>""]; } '
            .'require '.var_export(dirname(__DIR__,2).'/lib/voice_clone_sync.php',true).'; '
            .'$root=sys_get_temp_dir()."/synth-sync-unit-".bin2hex(random_bytes(8)); mkdir($root); '
            .'$file=$root."/sample.wav"; file_put_contents($file,str_repeat("a",64)); '
            .'$options=["root"=>$root,"driver"=>"pockettts","endpoint"=>"http://127.0.0.1:18976","verify_remote"=>true]; '
            .'$GLOBALS["remote"]=[]; $GLOBALS["uploads"]=0; $GLOBALS["fail_upload"]=false; '
            .'try { $results=[]; $results[]=synth_sync_voice_clone_sample("synth_test",$file,$options); '
            .'$results[]=synth_sync_voice_clone_sample("synth_test",$file,$options); $GLOBALS["remote"]=[]; '
            .'$results[]=synth_sync_voice_clone_sample("synth_test",$file,$options); $GLOBALS["remote"]=[]; $GLOBALS["fail_upload"]=true; '
            .'$results[]=synth_sync_voice_clone_sample("synth_test",$file,$options); $GLOBALS["fail_upload"]=false; '
            .'$results[]=synth_sync_voice_clone_sample("synth_test",$file,$options); echo json_encode([$results,$GLOBALS["uploads"]]); '
            .'} finally { unlink($file); unlink($root."/data/voice_sync_status.json"); rmdir($root."/data"); rmdir($root); }';
        $output=(string)shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script));
        [$results,$uploads]=json_decode($output,true,flags:JSON_THROW_ON_ERROR);
        $this->assertSame([true,true,true,false,true],$results);
        $this->assertSame(4,$uploads,'Known samples avoid reupload; backend loss and transient failure must recover.');
    }

    public function testDisabledNpcVoiceFreezesTextOnlyWithoutChangingPlayerLane(): void
    {
        $keys = ['TTSFUNCTION', 'SYNTH_RESPONSE_STREAMING', 'SYNTH_JSON_RESPONSE_LINES'];
        $saved = [];
        foreach ($keys as $key) if (array_key_exists($key, $GLOBALS)) $saved[$key] = $GLOBALS[$key];
        try {
            $GLOBALS['SYNTH_RESPONSE_STREAMING'] = false;
            foreach (['Settler', 'Player', 'The Narrator'] as $speaker) {
                foreach (['none', ' None ', 'pockettts'] as $driver) {
                    foreach (['Sole Survivor', '__player_tts', '__player_menu_tts', '__player_text_only'] as $listener) {
                        $GLOBALS['TTSFUNCTION'] = $driver;
                        $GLOBALS['SYNTH_JSON_RESPONSE_LINES'] = [];
                        $metadata = ['listener' => $listener, 'utterance_id' => 'utt:text-only',
                            'tts_cache_key' => '0123456789abcdef0123456789abcdef'];
                        synth_buffer_response_line($speaker, 'say', 'Still readable.', $metadata);
                        $line = $GLOBALS['SYNTH_JSON_RESPONSE_LINES'][0];
                        $expected = $listener === '__player_text_only' ||
                            ($listener === 'Sole Survivor' && trim(strtolower($driver)) === 'none');
                        $this->assertSame($expected, $line['text_only']);
                        $this->assertSame('Still readable.', $line['subtitle']);
                        $this->assertSame('utt:text-only', $line['utterance_id']);
                        $this->assertSame(!$expected, isset($line['tts_text']));
                        $this->assertSame(!$expected, isset($line['tts_cache_key']));
                        $this->assertSame(!$expected, isset($line['metadata']['tts_cache_key']));
                    }
                }
            }
        } finally {
            foreach ($keys as $key) {
                if (array_key_exists($key, $saved)) $GLOBALS[$key] = $saved[$key]; else unset($GLOBALS[$key]);
            }
        }
    }

    public function testTextOnlyWireDecisionSurvivesProfileSwitchAndStaleCacheKey(): void
    {
        foreach ([false, true] as $streaming) {
            foreach ([false, true] as $disabled) {
                $script = 'require '.var_export(dirname(__DIR__, 2).'/lib/response.php', true).'; '
                    .'$GLOBALS["SYNTH_RESPONSE_STREAMING"]='.var_export($streaming, true).'; '
                    .'$GLOBALS["TTSFUNCTION"]='.var_export($disabled ? 'none' : 'pockettts', true).'; '
                    .'synth_buffer_response_line("The Narrator","say","Still readable.",'
                    .'["utterance_id"=>"utt:text-only","tts_cache_key"=>"0123456789abcdef0123456789abcdef"]); '
                    .'if (!$GLOBALS["SYNTH_RESPONSE_STREAMING"]) {'
                    .'$GLOBALS["TTSFUNCTION"]='.var_export($disabled ? 'pockettts' : 'none', true).'; '
                    .'$line=$GLOBALS["SYNTH_JSON_RESPONSE_LINES"][0]; '
                    .'$line["tts_cache_key"]="0123456789abcdef0123456789abcdef"; '
                    .'unset($line["text_only"]); '
                    .'synth_emit_json_response_envelope([$line]);}';
                $output = (string)shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script));
                $lines = array_map(static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                    array_values(array_filter(explode("\n", trim($output)))));
                $this->assertCount(2, $lines);
                $this->assertSame('response_start', $lines[0]['type']);
                $this->assertSame('dialogue', $lines[1]['type']);
                $this->assertSame('Still readable.', $lines[1]['payload']['text']);
                $this->assertSame(!$disabled, isset($lines[1]['payload']['speech']));
                if (!$disabled) $this->assertSame('utt:text-only', $lines[1]['payload']['speech']['utterance_id']);
            }
        }
    }

    public function testStreamingResponseLineEmitsImmediateEnvelopeAndTimingMarker(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'synth_stream_');
        $this->assertIsString($logFile);
        $scriptFile = tempnam(sys_get_temp_dir(), 'synth_stream_script_');
        $this->assertIsString($scriptFile);

        $script = <<<'PHP'
<?php
require_once __RESPONSE_PATH__;
require_once __LOGGER_PATH__;
Logger::setCustomLog(__LOG_PATH__);
Logger::setRequestId('unit-stream-test');
$GLOBALS['SYNTH_RESPONSE_FORMAT'] = 'json';
$GLOBALS['SYNTH_RESPONSE_STREAMING'] = true;
$GLOBALS['SYNTH_JSON_RESPONSE_LINES'] = [];
$GLOBALS['SYNTH_REQUEST_EVENT'] = [
    'request_id' => 'unit-stream-test',
    'turn_id' => 'turn-unit-stream-test',
    'generation' => 1,
    'runtime_variant' => 'flat',
];
$GLOBALS['SYNTH_TURN_START_TIME'] = microtime(true) - 0.25;
synth_buffer_speech_response_line(
    'The Narrator',
    'Fast streamed line.',
    '',
    'Graussy',
    '',
    '',
    1.0,
    'Graussy',
    'utt_unit_stream',
    '0123456789abcdef0123456789abcdef'
);
PHP;
        $script = strtr($script, [
            '__RESPONSE_PATH__' => var_export(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'response.php', true),
            '__LOGGER_PATH__' => var_export(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'logger.php', true),
            '__LOG_PATH__' => var_export($logFile, true),
        ]);
        file_put_contents($scriptFile, $script);

        $output = (string)shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($scriptFile));
        @unlink($scriptFile);

        $decoded = array_map(static fn(string $line): array => (array)json_decode($line, true),
            array_values(array_filter(explode("\n", trim($output)))));
        $this->assertCount(2, $decoded);
        $this->assertSame('response_start', $decoded[0]['type']);
        $this->assertSame('dialogue', $decoded[1]['type']);
        $this->assertSame('narrator', $decoded[1]['payload']['speaker']['kind']);
        $this->assertSame('Fast streamed line.', $decoded[1]['payload']['text']);
        $this->assertSame('pending', $decoded[1]['payload']['speech']['status']);
        $this->assertSame('0123456789abcdef0123456789abcdef', $decoded[1]['payload']['speech']['cache_key']);
        $this->assertSame('utt_unit_stream', $decoded[1]['payload']['speech']['utterance_id']);

        $log = (string)file_get_contents($logFile);
        @unlink($logFile);
        $this->assertStringContainsString('[plugin-response] streamed JSON envelope', $log);
        $this->assertStringContainsString('speaker=The Narrator', $log);
        $this->assertStringContainsString('utterance_id=utt_unit_stream', $log);
        $this->assertStringContainsString('tts_cache_key=0123456789abcdef0123456789abcdef', $log);
    }

    public function testCancellationClosesBufferedAndStreamingResponsesOnlyOnce(): void
    {
        foreach ([false, true] as $streaming) {
            foreach ([false, true] as $completed) {
                $script = 'require '.var_export(dirname(__DIR__, 2).'/lib/response.php', true).'; '
                    .'$GLOBALS["SYNTH_RESPONSE_FORMAT"]="json"; '
                    .'$GLOBALS["SYNTH_RESPONSE_STREAMING"]='.var_export($streaming, true).'; '
                    .'$GLOBALS["SYNTH_REQUEST_EVENT"]=["request_id"=>"req-test","turn_id"=>"turn-test","generation"=>1,"runtime_variant"=>"flat"]; '
                    .'synth_start_json_response_buffer(); '
                    .($completed ? 'synth_emit_buffered_json_response(); ' : '')
                    .'synth_emit_cancelled_json_response(); synth_emit_cancelled_json_response(); synth_emit_buffered_json_response();';
                $output = strval(shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script)));
                $lines = array_map(static fn($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                    array_values(array_filter(explode("\n", trim($output)))));
                $this->assertCount(2, $lines);
                $this->assertSame('response_start', $lines[0]['type']);
                $this->assertSame('response_end', $lines[1]['type']);
                $this->assertSame($completed ? 'complete' : 'cancelled', $lines[1]['payload']['status']);
            }
        }
    }

    public function testStreamingFinalEnvelopeUsesEmptyLinesArrayAndCloseMarker(): void
    {
        $scriptFile = tempnam(sys_get_temp_dir(), 'synth_stream_final_');
        $this->assertIsString($scriptFile);

        $script = <<<'PHP'
<?php
require_once __RESPONSE_PATH__;
$GLOBALS['SYNTH_RESPONSE_FORMAT'] = 'json';
$GLOBALS['SYNTH_RESPONSE_STREAMING'] = true;
$GLOBALS['SYNTH_JSON_RESPONSE_LINES'] = [];
$GLOBALS['SYNTH_REQUEST_EVENT'] = [
    'request_id' => 'unit-final-test',
    'turn_id' => 'turn-unit-final-test',
    'generation' => 1,
    'runtime_variant' => 'flat',
];
synth_buffer_response_close();
$GLOBALS['SYNTH_JSON_RESPONSE_STREAM_FINAL_EMITTED'] = false;
synth_emit_buffered_json_response();
PHP;
        $script = strtr($script, [
            '__RESPONSE_PATH__' => var_export(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'response.php', true),
        ]);
        file_put_contents($scriptFile, $script);

        $output = trim((string)shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($scriptFile)));
        @unlink($scriptFile);

        $decoded = array_map(static fn(string $line): array => (array)json_decode($line, true),
            array_values(array_filter(explode("\n", $output))));
        $this->assertCount(2, $decoded);
        $this->assertSame('response_start', $decoded[0]['type']);
        $this->assertSame('response_end', $decoded[1]['type']);
        $this->assertSame('complete', $decoded[1]['payload']['status']);
    }
}
