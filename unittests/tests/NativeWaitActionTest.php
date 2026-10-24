<?php declare(strict_types=1);

require_once __DIR__.'/DatabaseTestCase.php';
require_once dirname(__DIR__,2).'/lib/request.php';
require_once dirname(__DIR__,2).'/lib/response.php';
require_once dirname(__DIR__,2).'/lib/synth_wait_actions.php';
require_once dirname(__DIR__,2).'/lib/phpunit.class.php';

final class NativeWaitFixtureDatabase extends sql
{
    public function withNativeRequestLock(callable $operation,int $timeout=0): mixed
    {
        $event=$GLOBALS['SYNTH_REQUEST_EVENT']['json'];
        $this->execQuery('BEGIN');
        try {
            $this->execQuery("SET LOCAL statement_timeout='2s'");
            $this->execQuery('SELECT pg_advisory_xact_lock(hashtext($1))',[$event['session_id']]);
            synth_protocol_assert_request_active();
            $canonical=synth_protocol_canonical_json($event);
            $result=$this->execQuery('SELECT 1 FROM synth_meta.protocol_events p JOIN synth_meta.request_executions e USING(session_id,request_id)
                WHERE p.session_id=$1 AND p.request_id=$2 AND p.canonical_event=$3::jsonb AND p.event_sha256=$4 AND e.request_sha256=p.event_sha256',
                [$event['session_id'],$event['request_id'],$canonical,hash('sha256',$canonical)]);
            if (!pg_fetch_assoc($result)) throw new LogicException('wait_fixture_canonical_owner_changed');
            $value=$operation(null,$event);$this->execQuery('COMMIT');return $value;
        } finally {
            if (!$this->isTransactionIdle()) $this->execQuery('ROLLBACK');
        }
    }
}

// Each request fixture owns its initialized catalogue and disposable database.
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class NativeWaitActionTest extends DatabaseTestCase
{
    private array $saved=[];
    private array $event;
    private array $actor;
    private array $player;
    private $database;

    public function setUp(): void
    {
        ini_set('error_log','/dev/null');
        parent::setUp();
        foreach (['db','SYNTH_REQUEST_EVENT','SYNTH_PROTOCOL_REQUEST_CLAIMED','SYNTH_SPEECH_ROLE','SYNTH_NAME',
            'SYNTH_CLIENT_CAPABILITIES','gameRequest','IS_NPC','PLAYER_NAME','FUNCTIONS','ENABLED_FUNCTIONS',
            'BASE_FUNCTIONS','F_NAMES','F_DESCRIPTIONS','F_RETURNMESSAGES','SYNTH_ACTION_CATALOG_ROWS_BY_CODE',
            'SYNTH_JSON_RESPONSE_LINES','SYNTH_RESPONSE_STREAMING','SYNTH_PROTOCOL_RESPONSE_STARTED',
            'SYNTH_PROTOCOL_RESPONSE_SEQUENCE','SYNTH_PROTOCOL_RESPONSE_BODY'] as $key)
            $this->saved[$key]=[array_key_exists($key,$GLOBALS),$GLOBALS[$key] ?? null];
        $this->database=$GLOBALS['db']=new NativeWaitFixtureDatabase();
        $this->player=['form_id'=>'0x00000014','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'wait-fixture','display_name'=>'Nora'];
        $this->actor=['form_id'=>'0x0001CA7D','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'wait-fixture','display_name'=>'Codsworth'];
        $playerId=synth_protocol_actor_identity_id($this->database,$this->player);
        $actorId=synth_protocol_actor_identity_id($this->database,$this->actor);
        $this->event=['schema'=>'synth.event.v2','protocol_version'=>2,'type'=>'input_text','game'=>'fo4','runtime_variant'=>'flat',
            'session_id'=>'wait-session','request_id'=>'wait-request','turn_id'=>'wait-turn','generation'=>1,'context_sequence'=>1,
            'client_version'=>'fixture','runtime_version'=>'fixture','capabilities'=>['dialogue.turn_ownership','action.wait_here','action.release_wait'],
            'payload'=>['text'=>'wait here']];
        $caps=json_encode($this->event['capabilities'],JSON_THROW_ON_ERROR);
        $this->database->execQuery("INSERT INTO synth_meta.runtime_sessions(session_id,playthrough_id,game,runtime_variant,client_version,
            runtime_version,active_generation,capabilities,state,active_turn_id,active_turn_sequence)
            VALUES('wait-session','wait-fixture','fo4','flat','fixture','fixture',1,$1::jsonb,'active','wait-turn',1)",[$caps]);
        $payload=['playthrough_id'=>'wait-fixture','snapshot_sequence'=>1,'player'=>$this->player,'target'=>$this->actor,
            'audience'=>[$this->actor],'audience_observation'=>'complete','world'=>['location'=>'Synthetic Sanctuary','game_time_ticks'=>1000]];
        $this->database->execQuery("INSERT INTO synth_meta.context_snapshots(session_id,generation,snapshot_sequence,playthrough_id,
            player_identity_id,target_identity_id,audience_identity_ids,context_payload)
            VALUES('wait-session',1,1,'wait-fixture',$1,$2,$3::jsonb,$4::jsonb)",[$playerId,$actorId,json_encode([$actorId]),json_encode($payload)]);
        $this->persistOwner();
        $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=true;$GLOBALS['SYNTH_SPEECH_ROLE']='npc';
        $GLOBALS['SYNTH_NAME']='Codsworth';$GLOBALS['PLAYER_NAME']='Nora';$GLOBALS['IS_NPC']=true;
        $GLOBALS['SYNTH_CLIENT_CAPABILITIES']=$this->event['capabilities'];$GLOBALS['gameRequest']=['inputtext',1000,1000,'wait here'];
        unset($GLOBALS['SYNTH_ACTION_CATALOG_ROWS_BY_CODE']);
    }

    public function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->saved as $key=>[$exists,$value]) {
            unset($GLOBALS[$key]);if ($exists) $GLOBALS[$key]=$value;
        }
    }

    private function persistOwner(): void
    {
        $canonical=synth_protocol_canonical_json($this->event);$hash=hash('sha256',$canonical);
        $this->database->execQuery("INSERT INTO synth_meta.request_executions(session_id,request_id,turn_id,generation,request_sha256,event_type,state)
            VALUES('wait-session','wait-request','wait-turn',1,$1,$2,'claimed')
            ON CONFLICT(session_id,request_id) DO UPDATE SET request_sha256=$1,event_type=$2",[$hash,$this->event['type']]);
        $this->database->execQuery("INSERT INTO synth_meta.protocol_events(session_id,request_id,turn_id,generation,event_type,canonical_event,event_sha256)
            VALUES('wait-session','wait-request','wait-turn',1,$1,$2::jsonb,$3)
            ON CONFLICT(session_id,request_id) DO UPDATE SET canonical_event=$2::jsonb,event_sha256=$3,event_type=$1",[$this->event['type'],$canonical,$hash]);
        $GLOBALS['SYNTH_REQUEST_EVENT']=synth_normalize_json_event($this->event);
    }

    private function intent(string $name='wait_here'): array
    {
        return ['schema'=>'synth.action.v1','action_id'=>'action:wait-fixture','idempotency_key'=>'idem:wait-fixture',
            'name'=>$name,'capability'=>'action.'.$name,'server_code'=>$name==='wait_here'?'WaitHere':'ReleaseWait',
            'actor'=>$this->actor,'arguments'=>new stdClass(),'deadline_ms'=>30000];
    }

    public function testOrdinaryNpcCatalogOffersNegotiatedWaitAndMissingReleaseWithoutDatabaseMutation(): void
    {
        $this->database->execQuery("DELETE FROM synth.core_action WHERE code_name='ReleaseWait'");
        $before=$this->database->fetchAll('SELECT code_name,is_activated,parameters_json::text FROM synth.core_action ORDER BY code_name');
        require_once dirname(__DIR__,2).'/functions/functions.php';
        $this->assertContains('WaitHere',$GLOBALS['ENABLED_FUNCTIONS']);
        $this->assertContains('ReleaseWait',$GLOBALS['ENABLED_FUNCTIONS']);
        foreach (['WaitHere','ReleaseWait'] as $code) {
            $this->assertTrue(synthActionCodeIsNegotiated($code));
            $this->assertSame([],get_object_vars($GLOBALS['BASE_FUNCTIONS'][$code]['parameters']['properties']));
            $this->assertSame(false,$GLOBALS['BASE_FUNCTIONS'][$code]['parameters']['additionalProperties']);
            $this->assertStringContainsString('Talk promise does not',$GLOBALS['F_DESCRIPTIONS'][$code]);
        }
        $this->assertSame($before,$this->database->fetchAll('SELECT code_name,is_activated,parameters_json::text FROM synth.core_action ORDER BY code_name'));
        $model=['action'=>$GLOBALS['F_NAMES']['WaitHere'],'character'=>'Codsworth','listener'=>'Nora',
            'mood'=>'normal','message'=>'Very well.','target'=>'','item'=>''];
        $execution=buildFunctionExecutionContextFromResponse($model);
        $this->assertTrue($execution['function_found']);$this->assertSame([],$execution['parameter_value']);
        $commands=[];$sent=[];$this->assertTrue(queueFunctionExecutionCommand($commands,$sent,$execution,'synthetic'));
        $decoded=synthDecodeActionLine($commands[0]);$this->assertSame([],$decoded['parameter_args']);
        $GLOBALS['SYNTH_JSON_RESPONSE_LINES']=[];$GLOBALS['SYNTH_RESPONSE_STREAMING']=false;
        synth_buffer_command_response_line('Codsworth',synthEncodeCommandAction('WaitHere',[]),['request_type'=>'inputtext']);
        $line=$GLOBALS['SYNTH_JSON_RESPONSE_LINES'][0];
        $this->assertNotNull(synth_protocol_action_from_legacy_line($line),'The real buffered command metadata must survive the strict empty-argument check');
        $model['target']='Nora';$bad=buildFunctionExecutionContextFromResponse($model);
        $this->assertFalse(queueFunctionExecutionCommand($commands,$sent,$bad,'synthetic'));
        $model['target']='';$model['unknown_selector']='foreign';$bad=buildFunctionExecutionContextFromResponse($model);
        $this->assertFalse(queueFunctionExecutionCommand($commands,$sent,$bad,'synthetic'));
    }

    #[PHPUnit\Framework\Attributes\DataProvider('waitInputProvider')]
    public function testTypedAndTranscribedCommandsProduceOwnedEmptyArgumentWaitIntents(string $type,string $code): void
    {
        $this->event['type']=$type;
        if ($type==='input_audio') $this->event['payload']=['media'=>['schema'=>'synth.media.v1','media_id'=>'media/'.str_repeat('b',64),
            'sha256'=>str_repeat('b',64),'content_type'=>'audio/wav','size'=>44]];
        $this->persistOwner();
        $action=synth_protocol_action_from_legacy_line(['speaker'=>'Codsworth','command_name'=>$code,
            'metadata'=>['request_type'=>'inputtext','command_args'=>[],'args'=>[]]]);
        $this->assertNotNull($action);$this->assertEquals($this->actor,$action['actor']);
        $this->assertInstanceOf(stdClass::class,$action['arguments']);$this->assertSame([],get_object_vars($action['arguments']));
        $this->assertArrayNotHasKey('target',$action);
        $row=$this->database->fetchOne('SELECT action_name,state,target_identity_id,arguments::text FROM synth_meta.action_intents');
        $this->assertSame($code,$row['action_name']);$this->assertSame('pending',$row['state']);
        $this->assertNull($row['target_identity_id']);$this->assertSame('{}',$row['arguments']);
    }

    public static function waitInputProvider(): array
    {
        return ['typed wait'=>['input_text','WaitHere'],'audio wait'=>['input_audio','WaitHere'],
            'typed release'=>['input_text','ReleaseWait'],'audio release'=>['input_audio','ReleaseWait']];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('waitInputProvider')]
    public function testSharedEmptyAmountPlaceholderReachesOwnedWireAction(string $type,string $code): void
    {
        $this->event['type']=$type;
        if ($type==='input_audio') $this->event['payload']=['media'=>['schema'=>'synth.media.v1','media_id'=>'media/'.str_repeat('c',64),
            'sha256'=>str_repeat('c',64),'content_type'=>'audio/wav','size'=>44]];
        $this->persistOwner();
        require_once dirname(__DIR__,2).'/functions/functions.php';
        $GLOBALS['F_NAMES']['WaitHere']='Wait_Here';$GLOBALS['F_NAMES']['ReleaseWait']='ReleaseWait';
        $GLOBALS['FUNCTIONS']=[['name'=>$GLOBALS['F_NAMES'][$code],
            'parameters'=>['type'=>'object','additionalProperties'=>false,'properties'=>new stdClass(),'required'=>[]]]];
        // The shared JSON response envelope includes empty target/item/amount even for a zero-argument action.
        $model=['character'=>'Codsworth','listener'=>'Nora','mood'=>'normal','action'=>$GLOBALS['F_NAMES'][$code],
            'target'=>'','item'=>'','amount'=>'','message'=>'Synthetic acknowledgement.',
            'emotion'=>'neutral','emotion_intensity'=>0.2];
        $canonical=synth_protocol_canonical_json($GLOBALS['SYNTH_REQUEST_EVENT']['json']);
        $execution=buildFunctionExecutionContextFromResponse($model);
        $commands=[];$sent=[];
        $this->assertTrue(queueFunctionExecutionCommand($commands,$sent,$execution,'synthetic-shared-envelope'));
        $this->assertSame([],$execution['parameter_value']);
        $this->assertFalse(queueFunctionExecutionCommand($commands,$sent,$execution,'synthetic-shared-envelope'));
        $this->assertCount(1,$commands);
        $decoded=synthDecodeActionLine($commands[0]);
        $this->assertSame($code,$decoded['action']);$this->assertSame([],$decoded['parameter_args']);
        $GLOBALS['SYNTH_JSON_RESPONSE_LINES']=[];$GLOBALS['SYNTH_RESPONSE_STREAMING']=false;
        $GLOBALS['SYNTH_PROTOCOL_RESPONSE_STARTED']=false;$GLOBALS['SYNTH_PROTOCOL_RESPONSE_SEQUENCE']=0;
        $GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY']='';
        synth_buffer_command_response_line($decoded['actor'],synthEncodeCommandAction($decoded['action'],$decoded['parameter_args']),
            ['request_type'=>$type==='input_audio'?'inputaudio':'inputtext']);
        ob_start(static fn($chunk)=>'');
        try { synth_emit_json_response_envelope($GLOBALS['SYNTH_JSON_RESPONSE_LINES']); }
        finally { ob_end_clean(); }
        $lines=array_map(static fn($line)=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),
            explode("\n",trim($GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY'])));
        $this->assertSame(['response_start','action'],array_column($lines,'type'));
        $action=$lines[1]['payload']['action'];
        $this->assertSame($code==='WaitHere'?'wait_here':'release_wait',$action['name']);
        $this->assertEquals($this->actor,$action['actor']);$this->assertSame([],$action['arguments']);
        $this->assertArrayNotHasKey('target',$action);
        $this->assertSame('wait-request',$lines[1]['request_id']);$this->assertSame('wait-turn',$lines[1]['turn_id']);
        $row=$this->database->fetchOne('SELECT action_name,actor_identity_id,target_identity_id,arguments::text FROM synth_meta.action_intents');
        $this->assertSame($code,$row['action_name']);$this->assertNull($row['target_identity_id']);$this->assertSame('{}',$row['arguments']);
        $this->assertSame('1',$this->database->fetchOne('SELECT count(*) AS n FROM synth_meta.action_intents')['n']);
        $this->assertSame($canonical,synth_protocol_canonical_json($GLOBALS['SYNTH_REQUEST_EVENT']['json']));
    }

    public function testSharedWaitEnvelopeStillRejectsEveryNonemptyOrUnrecognizedArgument(): void
    {
        require_once dirname(__DIR__,2).'/functions/functions.php';
        foreach (['WaitHere','ReleaseWait'] as $code) {
            $GLOBALS['F_NAMES'][$code]=$code;
            $GLOBALS['FUNCTIONS']=[['name'=>$code,'parameters'=>['type'=>'object','additionalProperties'=>false,'properties'=>new stdClass(),'required'=>[]]]];
            $base=['action'=>$code,'character'=>'Codsworth','listener'=>'Nora','message'=>'Synthetic acknowledgement.',
                'target'=>'','item'=>'','amount'=>''];
            foreach ([['amount'=>null],['amount'=>0],['amount'=>false],['amount'=>[]],['amount'=>new stdClass()],
                ['amount'=>'0'],['amount'=>' '],['amount'=>1],['target'=>'Nora'],['item'=>'Stimpak'],['unknown_selector'=>'']] as $change) {
                $execution=buildFunctionExecutionContextFromResponse(array_replace($base,$change));
                $commands=[];$sent=[];
                $this->assertFalse(queueFunctionExecutionCommand($commands,$sent,$execution,'synthetic-strict-wait'));
                $this->assertSame([],$commands);$this->assertSame([],$sent);
            }
        }
        $this->assertSame('0',$this->database->fetchOne('SELECT count(*) AS n FROM synth_meta.action_intents')['n']);
    }

    public function testExplicitDisabledOrConfiguredReleaseRowWinsOverFallback(): void
    {
        foreach ([['is_activated'=>false],['is_activated'=>true,'action_name'=>'CustomRelease']] as $row) {
            $GLOBALS['FUNCTIONS']=[];$GLOBALS['ENABLED_FUNCTIONS']=[];$GLOBALS['BASE_FUNCTIONS']=[];
            synth_wait_action_missing_release_fallback(['ReleaseWait'=>$row],['name'=>'ReleaseWait']);
            $this->assertSame([],$GLOBALS['FUNCTIONS']);$this->assertSame([],$GLOBALS['ENABLED_FUNCTIONS']);
        }
    }

    #[PHPUnit\Framework\Attributes\DataProvider('invalidWaitLineProvider')]
    public function testWaitCommandNeverDropsNonemptyOrConflictingModelArguments(array $extra): void
    {
        $this->assertNull(synth_protocol_action_from_legacy_line(array_replace(['speaker'=>'Codsworth','command_name'=>'WaitHere'],$extra)));
        $this->assertSame('0',$this->database->fetchOne('SELECT count(*) AS n FROM synth_meta.action_intents')['n']);
    }

    public static function invalidWaitLineProvider(): array
    {
        return ['target'=>[['target'=>'Nora']],'amount'=>[['amount'=>1]],'hidden target'=>[['metadata'=>['target'=>'Nora']]],
            'command arguments'=>[['metadata'=>['command_args'=>['Nora']]]],'structured arguments'=>[['arguments'=>new stdClass()]],
            'null amount'=>[['amount'=>null]],'list argument'=>[['metadata'=>['args'=>['malformed']]]]];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('forbiddenWaitOwnerProvider')]
    public function testWaitIsNotOfferedToUnsupportedOrAutonomousOwners(array $change): void
    {
        $event=array_replace($this->event,$change);
        $this->assertFalse(synth_wait_action_supported($event,'WaitHere'));
        $this->assertFalse(synth_wait_action_supported($event,'ReleaseWait'));
    }

    public static function forbiddenWaitOwnerProvider(): array
    {
        return ['vr'=>[['runtime_variant'=>'vr']],'legacy'=>[['protocol_version'=>1]],'missing capability'=>[['capabilities'=>['dialogue.turn_ownership']]],
            'unowned'=>[['capabilities'=>['action.wait_here','action.release_wait']]],'rechat'=>[['type'=>'trigger']],
            'action result'=>[['type'=>'action_result']],'narrator'=>[['payload'=>['control'=>['mode'=>'NARRATOR']]]]];
    }

    public function testDirectIntentCannotTargetPlayerOrAnotherActor(): void
    {
        foreach ([$this->player,array_replace($this->actor,['form_id'=>'0x00002F1F'])] as $actor) {
            $intent=$this->intent();$intent['actor']=$actor;
            try {synth_protocol_record_action_intent($this->database,$intent);$this->fail('Foreign wait actor was accepted');}
            catch (DomainException $error) {$this->assertSame('wait_actor_not_owned',$error->getMessage());}
        }
        $this->assertSame('0',$this->database->fetchOne('SELECT count(*) AS n FROM synth_meta.action_intents')['n']);
    }

    public function testDirectIntentRetainsCancellationAndCanonicalOwnershipChecks(): void
    {
        $this->database->execQuery("UPDATE synth_meta.request_executions SET state='cancelled' WHERE request_id='wait-request'");
        $this->expectException(SYNTHProtocolCancelled::class);
        synth_protocol_record_action_intent($this->database,$this->intent());
    }

    public function testDirectIntentCannotChangeCanonicalInputOrInjectArguments(): void
    {
        foreach ([['arguments'=>['target'=>'Nora']],['target'=>$this->player],['capability'=>'action.release_wait'],
            ['arguments'=>[]]] as $change) {
            try {synth_protocol_record_action_intent($this->database,array_replace($this->intent(),$change));$this->fail('Invalid wait intent was accepted');}
            catch (DomainException $error) {$this->assertSame('invalid_wait_intent',$error->getMessage());}
        }
        $GLOBALS['SYNTH_REQUEST_EVENT']['json']['payload']['text']='A different input';
        try {synth_protocol_record_action_intent($this->database,$this->intent());$this->fail('Changed canonical input emitted an action');}
        catch (LogicException $error) {$this->assertSame('wait_fixture_canonical_owner_changed',$error->getMessage());}
        $this->assertSame('0',$this->database->fetchOne('SELECT count(*) AS n FROM synth_meta.action_intents')['n']);
    }
}
