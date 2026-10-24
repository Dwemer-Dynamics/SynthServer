<?php declare(strict_types=1);

require_once __DIR__.'/DatabaseTestCase.php';
require_once dirname(__DIR__,2).'/lib/synth_relationship_dispatch.php';
require_once dirname(__DIR__,2).'/lib/synth_relationship_interaction_dispatch.php';

/** The real request-active guard is retained inside a disposable database transaction. */
final class NativeRelationshipDispatchFixtureDatabase
{
    private ?array $lockOwner=null;
    public function __construct(private $connection) {}
    public function escapeLiteral(string $value): string {return pg_escape_literal($this->connection,$value);}
    public function execQuery(string $sql,array $params=[]) {
        $result=$params ? pg_query_params($this->connection,$sql,$params) : pg_query($this->connection,$sql);
        if (!$result) throw new RuntimeException('Disposable relationship query failed');
        return $result;
    }
    public function fetchArray($result) {return pg_fetch_assoc($result);}
    public function fetchOne(string $sql): ?array {return pg_fetch_assoc($this->execQuery($sql)) ?: null;}
    public function fetchAll(string $sql): array {return pg_fetch_all($this->execQuery($sql)) ?: [];}
    public function isTransactionIdle(): bool {return pg_transaction_status($this->connection)===PGSQL_TRANSACTION_IDLE;}
    public function withNativeRequestLock(callable $operation,int $statementTimeoutMs=0) {
        $event=$GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? [];
        if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']) || ($event['protocol_version'] ?? null)!==2)
            throw new LogicException('Fixture requires a claimed native owner');
        if ($this->lockOwner!==null) {
            if ($this->lockOwner!==$event) throw new LogicException('Fixture nested owner changed');
            synth_protocol_assert_request_active();
            return $operation($this->connection,$event);
        }
        $this->execQuery('BEGIN ISOLATION LEVEL READ COMMITTED');
        try {
            $this->execQuery("SET LOCAL lock_timeout='2s'");
            if (!pg_query_params($this->connection,"SELECT set_config('statement_timeout',$1,true)",[(string)max(1,min(2000,$statementTimeoutMs ?: 2000))])
                || !pg_query_params($this->connection,'SELECT pg_advisory_xact_lock(hashtext($1))',[$event['session_id']]))
                throw new RuntimeException('Disposable relationship lock unavailable');
            synth_protocol_assert_request_active();$this->lockOwner=$event;
            $value=$operation($this->connection,$event);$this->execQuery('COMMIT');return $value;
        } finally {
            $this->lockOwner=null;
            if (!$this->isTransactionIdle()) pg_query($this->connection,'ROLLBACK');
        }
    }
}

final class NativeRelationshipDispatchTest extends DatabaseTestCase
{
    private $connection;
    private NativeRelationshipDispatchFixtureDatabase $database;
    private array $savedGlobals=[];
    private array $parent;
    private array $context;
    private int $actorId;
    private int $playerId;
    private int $profileId;
    private int $npcId;
    private int $inputHistoryId;

    public function setUp(): void {
        parent::setUp();
        foreach (['db','SYNTH_REQUEST_EVENT','SYNTH_PROTOCOL_REQUEST_CLAIMED','RELATIONSHIP_SYSTEM_ENABLED',
            'RELLLM_CONNECTOR','SYNTH_RELATIONSHIP_RESPONSE_CAPTURE','SYNTH_RELATIONSHIP_JOB_LEASE','SYNTH_INTERACTION_JOB_LEASE',
            'SYNTH_AMBIENT_NPC_EXCHANGE','SYNTH_INPUT_TARGET','SYNTH_INPUT_TARGET_IDENTITY','SYNTH_PLAYER_IDENTITY',
            'SYNTH_TURN_ACTOR_STATES','SYNTH_EXTERNAL_REQUEST','SYNTH_RESPONSE_SPEAKER_FORMID','SYNTH_TURN_QUEST_PROMPT',
            'SYNTH_TURN_ACTIVE_QUESTS','SYNTH_TURN_NEARBY_ITEMS','SYNTH_TURN_POINTS_OF_INTEREST','SYNTH_TURN_WORLD_STATE','gameRequest'] as $key)
            $this->savedGlobals[$key]=[array_key_exists($key,$GLOBALS),$GLOBALS[$key] ?? null];
        $this->connection=pg_connect('host=localhost dbname=testdb user=dwemer password=dwemer',PGSQL_CONNECT_FORCE_NEW);
        $this->assertNotFalse($this->connection);$this->query('SET search_path TO synth,synth_meta,public');
        $this->database=new NativeRelationshipDispatchFixtureDatabase($this->connection);
        $GLOBALS['db']=$this->database;$GLOBALS['RELATIONSHIP_SYSTEM_ENABLED']=true;$GLOBALS['RELLLM_CONNECTOR']=5;
        $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=true;
        unset($GLOBALS['SYNTH_RELATIONSHIP_JOB_LEASE'],$GLOBALS['SYNTH_INTERACTION_JOB_LEASE']);
        $player=['form_id'=>'0x00000014','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'relationship-dispatch-save','display_name'=>'Nora'];
        $actor=['form_id'=>'0x00002F1F','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'relationship-dispatch-save','display_name'=>'Piper Wright'];
        $this->playerId=$this->identity($player);$this->actorId=$this->identity($actor);
        $this->profileId=(int)pg_fetch_assoc($this->query("INSERT INTO synth.core_profiles(label) VALUES('Synthetic relationship dispatcher') RETURNING id"))['id'];
        $this->npcId=(int)pg_fetch_assoc($this->query('INSERT INTO synth.core_npc_master(npc_name,profile_id,actor_identity_id) VALUES($1,$2,$3) RETURNING id',
            [$actor['display_name'],$this->profileId,$this->actorId]))['id'];
        $this->context=['playthrough_id'=>'relationship-dispatch-save','snapshot_sequence'=>1,'player'=>$player,'target'=>$actor,
            'audience'=>[$actor],'audience_observation'=>'complete','world'=>['location'=>'Diamond City','game_time_ticks'=>1000,
                'interior'=>false,'scene'=>['cell'=>['form_id'=>'0x00018AA2','origin_plugin'=>'Fallout4.esm'],
                    'worldspace'=>['form_id'=>'0x0000003C','origin_plugin'=>'Fallout4.esm']]]];
        $this->parent=['schema'=>'synth.event.v2','protocol_version'=>2,'type'=>'input_text','session_id'=>'relationship-dispatch-session',
            'request_id'=>'relationship-parent','turn_id'=>'relationship-turn','generation'=>1,'context_sequence'=>1,
            'game'=>'fo4','runtime_variant'=>'flat','client_version'=>'fixture','runtime_version'=>'fixture',
            'capabilities'=>['dialogue.text','dialogue.turn_ownership','context.saved_anchor'],
            'payload'=>['text'=>'Thank you for helping me.']];
        $this->query("INSERT INTO synth_meta.runtime_sessions(session_id,playthrough_id,game,runtime_variant,client_version,runtime_version,
                active_generation,capabilities,state,active_turn_id,active_turn_sequence)
            VALUES('relationship-dispatch-session','relationship-dispatch-save','fo4','flat','fixture','fixture',1,'[]','active','relationship-turn',1)");
        $this->request($this->parent,true);
        // History references the insertion context through the real foreign key.
        foreach ([1,2,3] as $sequence) {
            $context=$this->context;$context['snapshot_sequence']=$sequence;
            $this->query("INSERT INTO synth_meta.context_snapshots
                    (session_id,generation,snapshot_sequence,playthrough_id,player_identity_id,target_identity_id,audience_identity_ids,context_payload,history_event_id)
                VALUES('relationship-dispatch-session',1,$1,'relationship-dispatch-save',$2,$3,$4::jsonb,$5::jsonb,NULL)",
                [$sequence,$this->playerId,$this->actorId,$this->json([$this->actorId]),$this->json($context)]);
        }
        $payload=['type'=>'inputtext','data'=>'Nora: Thank you for helping me.','people'=>'|Nora|Piper Wright|',
            'delivery_state'=>'received','gamets'=>1000,'localts'=>1000,'ts'=>1000,'rowid'=>910001];
        $history=(int)pg_fetch_assoc($this->query("INSERT INTO synth_meta.history_events
                (session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
            VALUES('relationship-dispatch-session',1,1,'relationship-parent','relationship-dispatch-save',910001,$1::jsonb) RETURNING id",[$this->json($payload)]))['id'];
        $this->inputHistoryId=$history;
        $this->query("UPDATE synth_meta.context_snapshots SET history_event_id=$1 WHERE session_id='relationship-dispatch-session'",[$history]);
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$this->parent];
        $this->response($this->parent);
        synth_relationship_begin_response();
        $line=synth_relationship_capture_line($actor['display_name'],'You are welcome.');
        synth_relationship_capture_route($line,'Player','fixture:dialogue');
        synth_relationship_finish_response(true);
        $body=pg_fetch_assoc($this->query("SELECT encode(response_body,'hex') AS body FROM synth_meta.request_executions WHERE request_id='relationship-parent'"))['body'];
        synth_relationship_store_response($this->database,$this->parent,hex2bin($body));
        $this->assertSame('1',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.relationship_response_inputs'))['n']);
    }

    public function tearDown(): void {
        if (isset($this->connection)) pg_close($this->connection);
        parent::tearDown();
        foreach ($this->savedGlobals as $key=>[$exists,$value]) {
            if ($exists) $GLOBALS[$key]=$value;else unset($GLOBALS[$key]);
        }
    }
    private function query(string $sql,array $params=[]) {
        $result=$params ? pg_query_params($this->connection,$sql,$params) : pg_query($this->connection,$sql);
        if (!$result) throw new RuntimeException('Disposable relationship fixture query failed');return $result;
    }
    private function json(array $value): string {return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
    private function identity(array $actor): int {
        return (int)pg_fetch_assoc($this->query('INSERT INTO synth.actor_identities(full_form_id,origin_plugin,playthrough_id,display_name) VALUES($1,$2,$3,$4) RETURNING id',
            [$actor['form_id'],$actor['origin_plugin'],$actor['playthrough_id'],$actor['display_name']]))['id'];
    }
    private function request(array $event,bool $older=false): void {
        $canonical=synth_protocol_canonical_json($event);$hash=hash('sha256',$canonical);
        $this->query("INSERT INTO synth_meta.request_executions(session_id,request_id,turn_id,generation,request_sha256,event_type,state,claimed_at)
            VALUES($1,$2,$3,1,$4,$5,'complete',clock_timestamp()-CASE WHEN $6::boolean THEN INTERVAL '2 seconds' ELSE INTERVAL '0 seconds' END)",
            [$event['session_id'],$event['request_id'],$event['turn_id'],$hash,$event['type'],$older ? 'true' : 'false']);
        $this->query('INSERT INTO synth_meta.protocol_events(session_id,request_id,turn_id,generation,event_type,canonical_event,event_sha256) VALUES($1,$2,$3,1,$4,$5::jsonb,$6)',
            [$event['session_id'],$event['request_id'],$event['turn_id'],$event['type'],$canonical,$hash]);
    }
    private function response(array $event,?array $listener=null,?array $speaker=null): string {
        $listener ??= $this->context['player'];$speaker ??= $this->context['target'];
        $lines=[];
        foreach (['response_start'=>['server_version'=>'fixture'],'dialogue'=>['speaker'=>$speaker,'text'=>'You are welcome.'],
            'response_end'=>['status'=>'complete']] as $type=>$payload) {
            $lines[$type]=$this->json(['schema'=>'synth.response.line.v2','protocol_version'=>2,'game'=>'fo4','request_id'=>$event['request_id'],
                'turn_id'=>$event['turn_id'],'generation'=>1,'context_sequence'=>$event['context_sequence'],'runtime_variant'=>'flat','line_id'=>'fixture:'.$type,'type'=>$type,'payload'=>$payload])."\n";
        }
        $body=implode('',$lines);$this->query("UPDATE synth_meta.request_executions SET response_body=decode($1,'hex') WHERE request_id=$2",[bin2hex($body),$event['request_id']]);
        $this->query("INSERT INTO synth_meta.response_routes(session_id,request_id,line_id,line_sha256,generation,context_sequence,speaker,listener_state,listener)
            VALUES($1,$2,'fixture:dialogue',$3,1,$4,$5::jsonb,'addressed',$6::jsonb)",
            [$event['session_id'],$event['request_id'],hash('sha256',$lines['dialogue']),$event['context_sequence'],$this->json($speaker),$this->json($listener)]);
        return hash('sha256',$body);
    }
    private function continuation(string $role,bool $prepared=true,?array $parent=null,?int $chainId=null,bool $scene=true,?array $capturedActor=null): array {
        $parent ??= $this->parent;
        $body=pg_fetch_assoc($this->query("SELECT encode(response_body,'hex') AS body FROM synth_meta.request_executions WHERE request_id=$1",[$parent['request_id']]))['body'];
        $child=$parent;$child['request_id']='relationship-child-'.$role.'-'.($chainId ?? 'first');$child['type']='trigger';
        $child['capabilities']=array_values(array_unique([...$child['capabilities'],'dialogue.rechat.ownership','dialogue.rechat.scene']));
        $child['payload']=['kind'=>'rechat','actor'=>$capturedActor ?? $this->context['target'],'reply_to_request_id'=>$parent['request_id'],
            'reply_to_line_id'=>'fixture:dialogue','scene_context_sequence'=>($parent['payload']['scene_context_sequence'] ?? 1)+1];
        if (!$scene) {
            $child['capabilities']=array_values(array_diff($child['capabilities'],['dialogue.rechat.scene']));
            unset($child['payload']['scene_context_sequence']);
        }
        $this->request($child);
        if ($chainId===null) {
            $policy=['source_profile_id'=>$this->profileId,'source_actor_identity_id'=>$this->actorId];
            $chainId=(int)pg_fetch_assoc($this->query("INSERT INTO synth_meta.rechat_chains
                    (session_id,root_request_id,generation,context_sequence,turn_id,runtime_variant,mode,policy,budget,used_rounds,terminal_reason)
                VALUES($1,$2,1,1,$3,'flat','conversational',$4::jsonb,3,$5,$6) RETURNING id",
                [$parent['session_id'],$parent['request_id'],$parent['turn_id'],$this->json($policy),$role==='terminal' ? 0 : 1,$role==='terminal' ? 'no_candidate' : null]))['id'];
        } elseif ($role==='terminal') $this->query("UPDATE synth_meta.rechat_chains SET terminal_reason='no_candidate' WHERE id=$1",[$chainId]);
        $round=1;
        if ($role==='npc' && $parent['type']==='trigger') {
            $round=(int)pg_fetch_assoc($this->query('SELECT COALESCE(max(round_number),0)+1 AS n FROM synth_meta.rechat_continuations WHERE chain_id=$1',[$chainId]))['n'];
            $this->query('UPDATE synth_meta.rechat_chains SET used_rounds=$1 WHERE id=$2',[$round,$chainId]);
        }
        $this->query("INSERT INTO synth_meta.rechat_continuations
                (session_id,request_id,chain_id,parent_request_id,parent_line_id,parent_response_sha256,round_number,status,terminal_reason)
            VALUES($1,$2,$3,$4,'fixture:dialogue',$5,$6,$7,$8)",
            [$child['session_id'],$child['request_id'],$chainId,$parent['request_id'],hash('sha256',hex2bin($body)),
                $role==='terminal' ? null : $round,$role==='terminal' ? 'terminal' : 'reserved',$role==='terminal' ? 'no_candidate' : null]);
        $this->query("INSERT INTO synth_meta.rechat_selections
                (session_id,request_id,role,actor_identity_id,npc_id,profile_id,identity,terminal_reason,prepared_at)
            VALUES($1,$2,$3,$4,$5,$6,$7::jsonb,$8,CASE WHEN $9::boolean THEN clock_timestamp() END)",
            [$child['session_id'],$child['request_id'],$role,$role==='npc' ? $this->actorId : null,$role==='npc' ? $this->npcId : null,
                $role==='terminal' ? null : $this->profileId,$role==='npc' ? $this->json($this->context['target']) : null,
                $role==='terminal' ? 'no_candidate' : null,$prepared ? 'true' : 'false']);
        return [$child,$chainId];
    }
    public static function rechatSceneStopCases(): array {
        return [
            'exterior cell changed'=>['cell',true],
            'exterior worldspace changed'=>['worldspace',true],
            'interior cell changed'=>['interior-cell',true],
            'interior transition'=>['interior-transition',true],
            'missing original scene'=>['missing-original-scene',false],
            'missing fresh scene'=>['missing-fresh-scene',false],
            'different player'=>['different-player',false],
            'different physical target'=>['different-target',false],
            'unavailable audience'=>['unavailable-audience',false],
            'stale fresh snapshot'=>['stale-snapshot',false],
            'changed canonical child'=>['changed-child',false],
            'incomplete parent body'=>['incomplete-parent',false],
            'forged parent route'=>['forged-parent-route',false],
        ];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('rechatSceneStopCases')]
    public function testRechatSceneChangesStopNormallyOnlyWithExactParentAndPhysicalIdentity(string $case,bool $normalStop): void {
        [$child,$chain]=$this->continuation('npc');
        // Exercise a new request: an existing choice must not be needed to stop a moved scene.
        $this->query('DELETE FROM synth_meta.rechat_chains WHERE id=$1',[$chain]);
        $original=$this->context;$fresh=$this->context;$fresh['snapshot_sequence']=2;
        $fresh['world']['scene']['cell']['form_id']='0x00018AA3';
        switch ($case) {
            case 'worldspace':$fresh['world']['scene']['worldspace']['form_id']='0x0000003D';break;
            case 'interior-cell':
                $original['world']['interior']=true;$original['world']['scene']['worldspace']=null;
                $fresh['world']['interior']=true;$fresh['world']['scene']['worldspace']=null;break;
            case 'interior-transition':$fresh['world']['interior']=true;$fresh['world']['scene']['worldspace']=null;break;
            case 'missing-original-scene':unset($original['world']['scene']);break;
            case 'missing-fresh-scene':unset($fresh['world']['scene']);break;
            case 'different-player':$fresh['player']['form_id']='0x00000015';break;
            case 'different-target':$fresh['target']['form_id']='0x00000016';break;
            case 'unavailable-audience':$fresh['audience_observation']='unavailable';break;
            case 'incomplete-parent':$this->query("UPDATE synth_meta.request_executions SET response_body=convert_to('{}','UTF8') WHERE request_id=$1",[$this->parent['request_id']]);break;
            case 'forged-parent-route':$this->query("UPDATE synth_meta.response_routes SET line_sha256=repeat('f',64) WHERE request_id=$1",[$this->parent['request_id']]);break;
        }
        foreach ([1=>$original,2=>$fresh] as $sequence=>$payload) {
            $this->query('UPDATE synth_meta.context_snapshots SET context_payload=$1::jsonb WHERE snapshot_sequence=$2',[$this->json($payload),$sequence]);
        }
        $this->query("UPDATE synth_meta.context_snapshots SET received_at=(SELECT claimed_at FROM synth_meta.request_executions WHERE request_id=$1)-INTERVAL '100 milliseconds' WHERE snapshot_sequence=2",[$child['request_id']]);
        if ($case==='stale-snapshot') $this->query("UPDATE synth_meta.context_snapshots SET received_at=received_at-INTERVAL '11 seconds' WHERE snapshot_sequence=2");
        if ($case==='changed-child') $this->query("UPDATE synth_meta.protocol_events SET canonical_event=jsonb_set(canonical_event,'{client_version}','\"forged\"') WHERE request_id=$1",[$child['request_id']]);
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$child];
        $request=['trigger',1,2,'unchanged'];$before=$request;$stop='old-stop';
        $failure=null;$admitted=null;
        try {$admitted=synth_protocol_bind_trigger_context($this->database,$child,$request,$stop);}
        catch (LogicException $error) {$failure=$error;}
        if ($normalStop) {
            $this->assertNull($failure,'A proven physical-scene change is a normal safe stop.');
            $this->assertFalse($admitted);$this->assertSame('ineligible',$stop);
        } else {
            $this->assertNotNull($failure,'Unproven parent or physical identity must remain an error.');
            $this->assertNotSame('SYNTHRechatSceneChanged',get_class($failure),'Only a fully proven scene change reaches normal stop classification.');
            $this->assertNull($stop);
        }
        $this->assertSame($before,$request);$this->assertSame($child,$GLOBALS['SYNTH_REQUEST_EVENT']['json']);
        foreach (['rechat_chains','rechat_continuations','rechat_selections'] as $table)
            $this->assertSame('0',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.'.$table))['n'],'Stopping must not reserve or publish a responder.');
    }
    private function ambientFixture(bool $robot=false): array {
        require_once dirname(__DIR__,2).'/lib/synth_ambient_npc.php';
        $npc=$this->secondaryNpc([1,2]);
        $this->query('UPDATE synth.core_npc_master SET md5=md5(npc_name) WHERE id IN ($1,$2)',[$this->npcId,$npc['npc_id']]);
        $this->query('UPDATE synth.core_profiles SET metadata=$1::jsonb WHERE id=$2',[$this->json(['RECHAT_H'=>4,'RECHAT_P'=>100]),$npc['profile_id']]);
        $payload=json_decode(pg_fetch_assoc($this->query('SELECT context_payload FROM synth_meta.context_snapshots WHERE snapshot_sequence=1'))['context_payload'],true,flags:JSON_THROW_ON_ERROR);
        $payload['actor_states']=[];
        foreach ([$payload['player'],$payload['target'],$npc['identity']] as $index=>$identity) {
            $payload['actor_states'][]=['actor'=>$identity,'alive'=>true,'disabled'=>false,'in_combat'=>false,
                'hostile_to_player'=>false,'talking_to_player'=>false,'life_state'=>'alive','posture'=>'normal',
                'race'=>$robot && $index===1 ? 'HandyRace' : 'HumanRace','distance'=>100+$index*100,
                'line_of_sight'=>'unknown','position'=>['x'=>$index*100,'y'=>0,'z'=>0]];
        }
        foreach ([1,2] as $sequence) {$payload['snapshot_sequence']=$sequence;
            $this->query('UPDATE synth_meta.context_snapshots SET context_payload=$1::jsonb WHERE snapshot_sequence=$2',[$this->json($payload),$sequence]);}
        $event=$this->parent;$event['type']='trigger';$event['request_id']='ambient-origin';$event['turn_id']='ambient-turn';
        $event['capabilities']=array_values(array_unique([...$event['capabilities'],'dialogue.ambient_npc','dialogue.rechat.ownership','dialogue.rechat.scene','dialogue.listener_identity']));
        $event['payload']=['kind'=>'bored','actor'=>$payload['target']];
        $this->request($event);
        $this->query("UPDATE synth_meta.request_executions SET state='claimed' WHERE request_id=$1",[$event['request_id']]);
        $this->query('UPDATE synth_meta.runtime_sessions SET active_turn_id=$1,active_turn_sequence=1',[$event['turn_id']]);
        $this->query("UPDATE synth_meta.context_snapshots SET received_at=(SELECT claimed_at FROM synth_meta.request_executions WHERE request_id=$1)-INTERVAL '100 milliseconds'",[$event['request_id']]);
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$event];unset($GLOBALS['SYNTH_AMBIENT_NPC_EXCHANGE']);
        return [$event,$npc];
    }
    public static function ambientModelListeners(): array {
        return ['omitted'=>['',false],'player'=>['Player',false],'third NPC'=>['Someone else',false],'Handy robot'=>['Player',true]];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('ambientModelListeners')]
    public function testAmbientNpcOriginEmitsOwnedListenerAndStartsOnlyItsRealBoundedContinuation(string $modelListener,bool $robot): void {
        require_once dirname(__DIR__,2).'/lib/synth_dialogue_policy.php';
        [$event,$npc]=$this->ambientFixture($robot);$request=['bored',1,2,''];$stop=null;
        $this->assertTrue(synth_protocol_bind_trigger_context($this->database,$event,$request,$stop));
        $pair=synth_ambient_npc_projection($event);$this->assertNotNull($pair);
        $this->assertSame(synth_protocol_canonical_json($npc['identity']),synth_protocol_canonical_json($pair['listener']));
        $this->assertStringContainsString('not the player',synth_ambient_npc_cue($pair));
        synth_dialogue_capture_origin_policy($this->database,$event,
            ['RECHAT_H'=>2,'RECHAT_P'=>100,'RECHAT_ALLOW_ACTIONS'=>false,'RECHAT_MODE'=>'random',
                'RANDOM_NARATION'=>false,'RANDOM_NARATION_CHANCE'=>0,'RANDOM_NARRATION_COOLDOWN'=>0],'STANDARD');
        $lines=[];
        foreach (['response_start'=>['server_version'=>'fixture'],'dialogue'=>['speaker'=>$pair['speaker'],'text'=>'A short public hello.'],
            'response_end'=>['status'=>'complete']] as $type=>$payload)
            $lines[$type]=$this->json(['schema'=>'synth.response.line.v2','protocol_version'=>2,'game'=>'fo4','request_id'=>$event['request_id'],
                'turn_id'=>$event['turn_id'],'generation'=>1,'context_sequence'=>1,'runtime_variant'=>'flat',
                'line_id'=>'fixture:'.$type,'type'=>$type,'payload'=>$payload])."\n";
        $lines['dialogue']=synth_rechat_record_emitted_route($this->database,$event,$lines['dialogue'],
            ['listener'=>$modelListener,'utterance_id'=>'ambient:utterance']);
        $wire=json_decode($lines['dialogue'],true,flags:JSON_THROW_ON_ERROR);
        $this->assertSame(synth_protocol_canonical_json($pair['listener']),synth_protocol_canonical_json($wire['payload']['listener']));
        synth_relationship_begin_response();$token=synth_relationship_capture_line($pair['speaker']['display_name'],'A short public hello.');
        synth_relationship_capture_route($token,$modelListener,'ambient:utterance');synth_relationship_finish_response(true);
        $body=implode('',$lines);
        $this->query("UPDATE synth_meta.request_executions SET state='complete',response_body=convert_to($1,'UTF8'),completed_at=clock_timestamp() WHERE request_id=$2",[$body,$event['request_id']]);
        synth_relationship_store_response($this->database,$event,$body);
        $receipt=pg_fetch_assoc($this->query('SELECT response_payload FROM synth_meta.relationship_response_inputs WHERE request_id=$1',[$event['request_id']]));
        $this->assertNotFalse($receipt);
        $frozen=json_decode($receipt['response_payload'],true,flags:JSON_THROW_ON_ERROR);
        $this->assertSame(synth_protocol_canonical_json($pair['listener']),synth_protocol_canonical_json($frozen['entries'][0]['routing']['listener']));
        $this->assertSame([],$frozen['input_events'],'An ambient origin must not manufacture player input.');
        [$child,$chain]=$this->continuation('npc',true,$event);$this->query('DELETE FROM synth_meta.rechat_chains WHERE id=$1',[$chain]);
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$child];
        require_once dirname(__DIR__,2).'/lib/synth_rechat_prepare.php';
        $prepared=synth_rechat_prepare_continuation($this->database,$child);
        $this->assertSame(synth_protocol_canonical_json($npc['identity']),synth_protocol_canonical_json($prepared['selection']['identity']));
        $chain=pg_fetch_assoc($this->query('SELECT budget,used_rounds FROM synth_meta.rechat_chains'));
        $this->assertSame(['budget'=>'4','used_rounds'=>'1'],$chain);
        $this->assertSame($child,$GLOBALS['SYNTH_REQUEST_EVENT']['json']);
    }
    public function testAmbientNpcProfileLoadsExactCapturedSpeakerAndPreservesInputConfiguration(): void {
        [$event]=$this->ambientFixture(true);$request=['bored',1,2,''];
        $this->assertTrue(synth_protocol_bind_trigger_context($this->database,$event,$request));
        $connector=(int)pg_fetch_assoc($this->query('SELECT id FROM synth.core_llm_connector ORDER BY id LIMIT 1'))['id'];
        $this->query('UPDATE synth.core_profiles SET llm_primary_id=$1 WHERE id=$2',[$connector,$this->profileId]);
        $previous=$GLOBALS;
        try {
            $GLOBALS['active_profile']=md5('The Narrator');$GLOBALS['SYNTH_NAME']='The Narrator';
            $GLOBALS['STTFUNCTION']='preserved-input-stt';
            $loaded=synth_ambient_npc_load_profile($this->database,$event);
            $this->assertSame($this->actorId,(int)$loaded['character']['actor_identity_id']);
            $this->assertSame($this->profileId,(int)$loaded['profile']['id']);
            $this->assertSame($this->context['target']['display_name'],$GLOBALS['SYNTH_NAME']);
            $this->assertSame('preserved-input-stt',$GLOBALS['STTFUNCTION']);
            $this->assertSame($event,$GLOBALS['SYNTH_REQUEST_EVENT']['json']);
        } finally {
            foreach (array_keys($GLOBALS) as $key) if (!array_key_exists($key,$previous)) unset($GLOBALS[$key]);
            foreach ($previous as $key=>$value) $GLOBALS[$key]=$value;
        }
    }
    public static function ambientEmissionClosedCases(): array {
        return ['not prepared'=>['not prepared'],'player speaker'=>['player speaker'],'narrator speaker'=>['narrator speaker'],
            'listener rebound'=>['listener rebound'],'other owner projection'=>['other owner projection']];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('ambientEmissionClosedCases')]
    public function testAmbientNpcEmissionCannotSwitchPreparedSpeakerListenerOrOwner(string $case): void {
        [$event,$npc]=$this->ambientFixture();
        if ($case==='listener rebound') {
            $third=$this->secondaryNpc([1],['form_id'=>'0xFF001234','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'relationship-dispatch-save','display_name'=>'Another NPC']);
            $payload=json_decode(pg_fetch_assoc($this->query('SELECT context_payload FROM synth_meta.context_snapshots WHERE snapshot_sequence=1'))['context_payload'],true,flags:JSON_THROW_ON_ERROR);
            $state=$payload['actor_states'][2];$state['actor']=$third['identity'];$payload['actor_states'][]=$state;
            $this->query('UPDATE synth_meta.context_snapshots SET context_payload=$1::jsonb WHERE snapshot_sequence=1',[$this->json($payload)]);
        }
        $request=['bored',1,2,''];$this->assertTrue(synth_protocol_bind_trigger_context($this->database,$event,$request));
        $speaker=$this->context['target'];
        if ($case==='not prepared') unset($GLOBALS['SYNTH_AMBIENT_NPC_EXCHANGE']);
        if ($case==='player speaker') $speaker=$this->context['player'];
        if ($case==='narrator speaker') $speaker=['kind'=>'narrator','id'=>'narrator'];
        if ($case==='listener rebound') $this->query('UPDATE synth.core_npc_master SET profile_id=NULL WHERE id=$1',[$npc['npc_id']]);
        if ($case==='other owner projection') $GLOBALS['SYNTH_AMBIENT_NPC_EXCHANGE']['request_sha256']=str_repeat('f',64);
        $wire=$this->json(['schema'=>'synth.response.line.v2','protocol_version'=>2,'game'=>'fo4','request_id'=>$event['request_id'],
            'turn_id'=>$event['turn_id'],'generation'=>1,'context_sequence'=>1,'runtime_variant'=>'flat',
            'line_id'=>'ambient:dialogue','type'=>'dialogue','payload'=>['speaker'=>$speaker,'text'=>'Synthetic speech.']])."\n";
        try {synth_rechat_record_emitted_route($this->database,$event,$wire,[]);$this->fail('An unproven ambient route was emitted.');}
        catch (LogicException $error) {$this->assertStringStartsWith('ambient_',$error->getMessage());}
        $this->assertSame('0',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.response_routes WHERE request_id=$1',[$event['request_id']]))['n']);
    }
    public static function ambientClosedCases(): array {
        $cases=['one NPC','no profile','dead','sleeping','combat','hostile','talking to player',
            'blocked sight','unknown sight too far','pair too far','position unavailable','feral','unknown race',
            'player combat','audience unavailable','scene unavailable','private mode','cooldown','stale snapshot',
            'forged child','wrong physical target','foreign listener'];
        return array_combine($cases,array_map(static fn($case)=>[$case],$cases));
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('ambientClosedCases')]
    public function testAmbientNpcOriginCannotUseUnsafeOrUnprovenParticipants(string $case): void {
        [$event,$npc]=$this->ambientFixture();
        $payload=json_decode(pg_fetch_assoc($this->query('SELECT context_payload FROM synth_meta.context_snapshots WHERE snapshot_sequence=1'))['context_payload'],true,flags:JSON_THROW_ON_ERROR);
        switch ($case) {
            case 'one NPC':$payload['audience']=[$payload['target']];break;
            case 'no profile':$this->query('UPDATE synth.core_npc_master SET profile_id=NULL WHERE id=$1',[$npc['npc_id']]);break;
            case 'dead':$payload['actor_states'][2]['alive']=false;break;
            case 'sleeping':$payload['actor_states'][2]['posture']='sleeping';break;
            case 'combat':$payload['actor_states'][2]['in_combat']=true;break;
            case 'hostile':$payload['actor_states'][2]['hostile_to_player']=true;break;
            case 'talking to player':$payload['actor_states'][2]['talking_to_player']=true;break;
            case 'blocked sight':$payload['actor_states'][2]['line_of_sight']='blocked';break;
            case 'unknown sight too far':$payload['actor_states'][2]['distance']=785;break;
            case 'pair too far':$payload['actor_states'][2]['position']['x']=1000;break;
            case 'position unavailable':unset($payload['actor_states'][2]['position']);break;
            case 'feral':$payload['actor_states'][2]['race']='FeralGhoulRace';break;
            case 'unknown race':$payload['actor_states'][2]['race']='UnknownRace';break;
            case 'player combat':$payload['actor_states'][0]['in_combat']=true;break;
            case 'audience unavailable':$payload['audience_observation']='unavailable';break;
            case 'scene unavailable':unset($payload['world']['scene']);break;
            case 'private mode':$this->query("INSERT INTO synth.conf_opts(id,value) VALUES('synth_mode','WHISPER') ON CONFLICT(id) DO UPDATE SET value='WHISPER'");break;
            case 'wrong physical target':$payload['target']['form_id']='0x00001234';break;
            case 'foreign listener':$payload['audience'][1]['playthrough_id']='another-save';break;
            case 'cooldown':
                $prior=$event;$prior['request_id']='earlier-ambient';$this->request($prior);
                $this->query("UPDATE synth_meta.request_executions SET claimed_at=(SELECT claimed_at FROM synth_meta.request_executions WHERE request_id=$1)-INTERVAL '20 seconds' WHERE request_id=$2",[$event['request_id'],$prior['request_id']]);break;
            case 'stale snapshot':$this->query("UPDATE synth_meta.context_snapshots SET received_at=received_at-INTERVAL '11 seconds' WHERE snapshot_sequence=1");break;
            case 'forged child':$this->query("UPDATE synth_meta.protocol_events SET canonical_event=jsonb_set(canonical_event,'{client_version}','\"forged\"') WHERE request_id=$1",[$event['request_id']]);break;
        }
        $this->query('UPDATE synth_meta.context_snapshots SET context_payload=$1::jsonb WHERE snapshot_sequence=1',[$this->json($payload)]);
        $request=['bored',1,2,'unchanged'];$before=$request;$stop=null;$failure=null;$admitted=null;
        try {$admitted=synth_protocol_bind_trigger_context($this->database,$event,$request,$stop);}
        catch (LogicException $error) {$failure=$error;}
        $this->assertNotTrue($admitted,$case);$this->assertSame($before,$request);
        $this->assertNull(synth_ambient_npc_projection($event));$this->assertSame($event,$GLOBALS['SYNTH_REQUEST_EVENT']['json']);
        foreach (['rechat_chains','rechat_continuations','rechat_selections'] as $table)
            $this->assertSame('0',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.'.$table))['n']);
    }
    public function testRandomRechatUsesOnlyViableModesWhileExplicitTightRemainsClosed(): void {
        [$event]=$this->ambientFixture();
        $candidates=$this->database->withNativeRequestLock(fn($connection)=>synth_rechat_load_candidates($connection,$this->database,$event));
        $proof=['speaker'=>$this->context['target'],'listener_state'=>'addressed','listener'=>$this->context['player']];
        $this->assertNull(synth_rechat_initial_candidate_plan($candidates,$proof,'tight')['candidate']);
        for ($attempt=0;$attempt<30;++$attempt) {
            $plan=synth_rechat_initial_candidate_plan($candidates,$proof,'random');
            $this->assertContains($plan['mode'],['conversational','group']);$this->assertNotNull($plan['candidate']);
        }
        $single=[$candidates[0]];
        $this->assertSame('no_candidate',synth_rechat_initial_candidate_plan($single,$proof,'random')['terminal_reason']);
        $single[0]['state']['alive']=false;
        $this->assertSame('ineligible',synth_rechat_initial_candidate_plan($single,$proof,'random')['terminal_reason']);
    }
    public static function closedSelections(): array {
        $cases=[];
        foreach ([false,true] as $pair) foreach (['terminal'=>['terminal',true],'narrator'=>['narrator',true],'unprepared NPC'=>['npc',false]] as $label=>$args)
            $cases[($pair ? 'pair ' : 'player ').$label]=[...$args,$pair];
        return $cases;
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('closedSelections')]
    public function testClosedContinuationCannotMaskCompletedNpcOrigin(string $role,bool $prepared,bool $pair): void {
        [$child]=$this->continuation($role,$prepared);
        $origin=synth_relationship_reserve_dispatch($this->database,$pair);
        $this->assertNotNull($origin);
        $this->assertSame($this->parent['request_id'],$origin['request_id'],'Terminal/non-NPC continuations cannot own automatic relationship learning');
        $this->assertNotSame($child['request_id'],$origin['request_id']);
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('closedSelections')]
    public function testClosedContinuationCannotInventMissingCompletedOrigin(string $role,bool $prepared,bool $pair): void {
        $this->continuation($role,$prepared);
        $this->query("UPDATE synth_meta.request_executions SET state='cancelled' WHERE request_id='relationship-parent'");
        $this->assertNull(synth_relationship_reserve_dispatch($this->database,$pair));
    }
    public function testPreparedNpcRemainsOriginForBothIndependentLanes(): void {
        [$child]=$this->continuation('npc');
        foreach ([false,true] as $pair) {
            $origin=synth_relationship_reserve_dispatch($this->database,$pair);
            $this->assertSame($child['request_id'],$origin['request_id']);
            $this->assertNull(synth_relationship_reserve_dispatch($this->database,$pair),'Each lane retains its bounded cooldown');
        }
    }
    public function testStoppedNpcChainCannotMaskCompletedOrigin(): void {
        [$npc,$chain]=$this->continuation('npc');$this->response($npc);
        $this->continuation('terminal',true,$npc,$chain);
        foreach ([false,true] as $pair) $this->assertSame($this->parent['request_id'],synth_relationship_reserve_dispatch($this->database,$pair)['request_id']);
    }
    public function testTerminalContinuationAllowsOneUnchangedEvaluationWithoutScoreInflation(): void {
        $this->continuation('terminal');$calls=0;
        $result=synth_relationship_dispatch($this->database,function(array $input) use (&$calls): string {
            ++$calls;$this->assertTrue($this->database->isTransactionIdle());
            $this->assertSame($this->parent['request_id'],$input['owner']['request_id']);
            return '{"changes":{}}';
        },true);
        $this->assertNotNull($result);$this->assertSame(1,$calls);
        $this->assertSame('1',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.relationship_results'))['n']);
        $this->assertSame('0',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.relationship_revisions'))['n']);
        $this->assertSame('ready',pg_fetch_assoc($this->query("SELECT state FROM synth_meta.worker_jobs WHERE job_type='relationship'"))['state']);
        $this->query("UPDATE synth_meta.runtime_sessions SET relationship_dispatch_after=clock_timestamp()-INTERVAL '1 second'");
        $this->assertNull(synth_relationship_dispatch($this->database,function() use (&$calls): string {++$calls;return '{"changes":{}}';},true));
        $this->assertSame(1,$calls,'An unchanged ready result suppresses duplicate provider work');
        $this->assertSame($this->parent,$GLOBALS['SYNTH_REQUEST_EVENT']['json']);
    }
    private function evaluationJob(): int {
        $response=(int)pg_fetch_assoc($this->query('SELECT history_event_id FROM synth_meta.relationship_response_inputs'))['history_event_id'];
        $evaluation=synth_relationship_capture_evaluation($this->database,$response,'Evaluate only synthetic relationship evidence.',true);
        $this->assertNotNull($evaluation);
        $job=synth_relationship_enqueue_evaluation($this->database,$evaluation);
        $this->assertNotNull($job);return $job;
    }
    public function testCompletedInputCancellationCannotGenerateOrPublishResult(): void {
        $this->query("UPDATE synth_meta.request_executions SET error_code='request_cancelled',response_body=NULL WHERE request_id='relationship-parent'");
        $calls=0;
        try {
            synth_relationship_dispatch($this->database,function() use (&$calls): string {++$calls;return '{"changes":{}}';},true);
            $this->fail('A completed but cancelled input cannot authorize late relationship work');
        } catch (SYNTHProtocolCancelled $error) {$this->assertSame('request_cancelled',$error->getMessage());}
        $this->assertSame(0,$calls);
        foreach (['relationship_results','relationship_revisions','worker_jobs'] as $table)
            $this->assertSame('0',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.'.$table))['n']);
        $this->assertSame($this->parent,$GLOBALS['SYNTH_REQUEST_EVENT']['json']);
    }
    public function testExpiredReplacedLeaseCannotPublishOrExtendJobDeadline(): void {
        $job=$this->evaluationJob();$before=pg_fetch_assoc($this->query('SELECT deadline_at FROM synth_meta.worker_jobs WHERE id=$1',[$job]));
        $first=synth_relationship_claim_evaluation($this->database,$job);$this->assertNotNull($first);
        $this->query("UPDATE synth_meta.worker_jobs SET lease_until=clock_timestamp()-INTERVAL '1 second' WHERE id=$1",[$job]);
        $second=synth_relationship_claim_evaluation($this->database,$job);$this->assertNotNull($second);
        $this->assertNotSame($first['lease_token'],$second['lease_token']);$this->assertSame('2',$second['attempts']);
        try {
            synth_relationship_complete_evaluation($this->database,$job,$first['lease_token'],'{"changes":{"Player":{"delta":50}}}');
            $this->fail('The expired token must not change a replacement attempt');
        } catch (SYNTHProtocolCancelled $error) {$this->assertSame('relationship_lease_lost',$error->getMessage());}
        $this->assertSame('0',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.relationship_results'))['n']);
        $result=synth_relationship_complete_evaluation($this->database,$job,$second['lease_token'],'{"changes":{}}');
        $this->assertGreaterThan(0,$result);
        $after=pg_fetch_assoc($this->query('SELECT state,attempts,deadline_at FROM synth_meta.worker_jobs WHERE id=$1',[$job]));
        $this->assertSame('ready',$after['state']);$this->assertSame('2',$after['attempts']);$this->assertSame($before['deadline_at'],$after['deadline_at']);
        $this->assertSame('0',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.relationship_revisions'))['n']);
        $this->assertSame($result,synth_relationship_complete_evaluation($this->database,$job,$second['lease_token'],'{"changes":{}}'));
        $this->assertNull(synth_relationship_enqueue_evaluation($this->database,(int)pg_fetch_assoc($this->query('SELECT relationship_evaluation_id FROM synth_meta.worker_jobs WHERE id=$1',[$job]))['relationship_evaluation_id']));
    }
    public function testExpiredThirdAttemptRetainsTerminalBudget(): void {
        $job=$this->evaluationJob();$before=pg_fetch_assoc($this->query('SELECT deadline_at FROM synth_meta.worker_jobs WHERE id=$1',[$job]));
        for ($attempt=1;$attempt<=3;++$attempt) {
            $claimed=synth_relationship_claim_evaluation($this->database,$job);$this->assertNotNull($claimed);
            $this->assertSame((string)$attempt,$claimed['attempts']);
            $this->query("UPDATE synth_meta.worker_jobs SET lease_until=clock_timestamp()-INTERVAL '1 second' WHERE id=$1",[$job]);
        }
        $this->assertNull(synth_relationship_claim_evaluation($this->database,$job));
        $after=pg_fetch_assoc($this->query('SELECT state,attempts,deadline_at,last_error FROM synth_meta.worker_jobs WHERE id=$1',[$job]));
        $this->assertSame('failed',$after['state']);$this->assertSame('3',$after['attempts']);
        $this->assertSame('lease_expired',$after['last_error']);$this->assertSame($before['deadline_at'],$after['deadline_at']);
        $this->assertSame('0',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.relationship_results'))['n']);
    }
    public function testPublicationRequiresSavedFrontierAndEarlierSaveExcludesLaterResult(): void {
        $job=$this->evaluationJob();$claimed=synth_relationship_claim_evaluation($this->database,$job);$this->assertNotNull($claimed);
        synth_relationship_complete_evaluation($this->database,$job,$claimed['lease_token'],
            '{"changes":{"Player":{"delta":2,"reason":"Synthetic appreciation"}}}');
        $this->assertSame('unknown',synth_relationship_read($this->database,'Player')['availability'],
            'Publication beyond this saved cutoff must not become visible immediately');
        $frontier=(int)pg_fetch_assoc($this->query('SELECT max(history_event_id) AS id FROM synth_meta.relationship_revisions'))['id'];
        $this->query("UPDATE synth_meta.context_snapshots SET history_event_id=$1 WHERE session_id='relationship-dispatch-session' AND snapshot_sequence=2",[$frontier]);
        $later=$this->parent;$later['request_id']='relationship-later';$later['turn_id']='relationship-later-turn';$later['context_sequence']=2;
        $this->request($later);$this->query("UPDATE synth_meta.runtime_sessions SET active_turn_id=$1,active_turn_sequence=2 WHERE session_id='relationship-dispatch-session'",[$later['turn_id']]);
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$later];
        $visible=synth_relationship_read($this->database,'Player');$this->assertSame('available',$visible['availability']);
        $this->assertSame(2,$visible['relationship']['aff']);$this->assertSame($frontier,$visible['relationship']['revision']);
        $earlier=$this->parent;$earlier['session_id']='relationship-earlier-session';$earlier['request_id']='relationship-earlier';$earlier['turn_id']='relationship-earlier-turn';
        $this->query("INSERT INTO synth_meta.runtime_sessions(session_id,playthrough_id,game,runtime_variant,client_version,runtime_version,
                active_generation,capabilities,state,active_turn_id,active_turn_sequence)
            VALUES('relationship-earlier-session','relationship-dispatch-save','fo4','flat','fixture','fixture',1,'[]','active',$1,1)",[$earlier['turn_id']]);
        $context=$this->context;$context['loaded_context']=['session_id'=>$this->parent['session_id'],'generation'=>1,
            'context_sequence'=>1,'playthrough_id'=>'relationship-dispatch-save'];
        $this->query("INSERT INTO synth_meta.context_snapshots(session_id,generation,snapshot_sequence,playthrough_id,player_identity_id,
                target_identity_id,audience_identity_ids,context_payload,history_event_id)
            SELECT 'relationship-earlier-session',1,1,playthrough_id,player_identity_id,target_identity_id,audience_identity_ids,$1::jsonb,history_event_id
            FROM synth_meta.context_snapshots WHERE session_id='relationship-dispatch-session' AND snapshot_sequence=1",[$this->json($context)]);
        $this->request($earlier);$GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$earlier];
        $this->assertSame('unknown',synth_relationship_read($this->database,'Player')['availability'],
            'A separately restored earlier anchor must exclude the later relationship result');
    }
    private function savedSelector(int $sequence=3,string $lane='flat'): array {
        return ['protocol_version'=>2,'session_id'=>$this->parent['session_id'],'generation'=>1,
            'context_sequence'=>$sequence,'runtime_variant'=>$lane];
    }
    private function savedRelationship(array $selector,?int $reader=null): ?array {
        return $this->database->fetchOne(synth_relationship_visible_cte($this->database,$selector,false,$reader ?? $this->actorId)
            .'SELECT history_event_id,affinity,prior_history_event_id,result_history_event_id FROM native_relationships'
            .' WHERE actor_identity_id='.($reader ?? $this->actorId).' AND subject_identity_id='.$this->playerId
            .' ORDER BY history_event_id DESC LIMIT 1');
    }
    private function completeCapturedOwner(array $owner): void {
        $this->response($owner);
        $body=hex2bin(pg_fetch_assoc($this->query('SELECT encode(response_body,\'hex\') AS body FROM synth_meta.request_executions WHERE request_id=$1',[$owner['request_id']]))['body']);
        $this->query("UPDATE synth_meta.request_executions SET state='claimed',completed_at=NULL WHERE request_id=$1",[$owner['request_id']]);
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$owner,'request_id'=>$owner['request_id'],'runtime_variant'=>$owner['runtime_variant']];
        synth_protocol_complete_request($this->database,'complete','application/x-ndjson',$body);
        $this->captureOwnedSpeech($owner,$this->context['target'],'Player');
    }
    private function publishedRetainedFixture(string $type='input_text'): array {
        // The dispatcher consumes the oldest eligible receipt, independently of its current owner.
        // Settle the setup exchange so this regression can only evaluate the fresh receipt.
        $baselineCalls=0;$baseline=synth_relationship_dispatch($this->database,function() use(&$baselineCalls): string {
            ++$baselineCalls;return '{"changes":{}}';
        },true);
        $this->assertNotNull($baseline);$this->assertSame(1,$baselineCalls);
        $owner=$this->parent;$owner['request_id']='retained-origin';$owner['turn_id']='retained-turn';$owner['context_sequence']=2;
        $owner['type']=$type;$owner['payload']=['text'=>'I appreciate the help.'];
        if ($type==='input_audio') $owner['payload']=['media'=>['schema'=>'synth.media.v1','media_id'=>'media/'.str_repeat('b',64),
            'content_type'=>'audio/wav','sha256'=>str_repeat('b',64),'size'=>44]];
        if ($type==='activate') $owner['payload']=['actor'=>$this->context['target'],'source'=>'manual'];
        $this->request($owner);$this->query("UPDATE synth_meta.runtime_sessions SET active_turn_id=$1,active_turn_sequence=2",[$owner['turn_id']]);
        if (in_array($type,['input_text','input_audio'],true)) {
            $input=['type'=>$type==='input_audio' ? 'inputtext_s' : 'inputtext','data'=>'Nora: I appreciate the help.',
                'people'=>'|Nora|Piper Wright|','delivery_state'=>'received','gamets'=>1000,'localts'=>1000,'ts'=>1000,'rowid'=>910003];
            $this->query("INSERT INTO synth_meta.history_events
                (session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
                VALUES('relationship-dispatch-session',1,2,$1,'relationship-dispatch-save',910003,$2::jsonb)",[$owner['request_id'],$this->json($input)]);
        }
        $this->query("UPDATE synth_meta.context_snapshots SET history_event_id=(SELECT max(id) FROM synth_meta.history_events)
            WHERE session_id='relationship-dispatch-session' AND snapshot_sequence=2");
        $this->completeCapturedOwner($owner);
        $freshResponse=pg_fetch_assoc($this->query('SELECT history_event_id FROM synth_meta.relationship_response_inputs WHERE request_id=$1',[$owner['request_id']]));
        $this->assertNotFalse($freshResponse);
        $this->query("UPDATE synth_meta.runtime_sessions SET relationship_dispatch_after=clock_timestamp()-INTERVAL '1 second'");$calls=0;
        $result=synth_relationship_dispatch($this->database,function(array $input) use(&$calls,$owner,$freshResponse): string {
            ++$calls;$this->assertTrue($this->database->isTransactionIdle());
            $this->assertSame(synth_protocol_canonical_json($owner),synth_protocol_canonical_json($input['owner']));
            $this->assertSame(synth_protocol_canonical_json($owner),synth_protocol_canonical_json($input['response']['request']));
            $this->assertSame((int)$freshResponse['history_event_id'],$input['response_id']);
            return '{"changes":{"Player":{"delta":2,"reason":"Synthetic appreciation"}}}';
        },true);
        $this->assertNotNull($result);$this->assertSame(1,$calls);
        $row=pg_fetch_assoc($this->query('SELECT revision.history_event_id AS revision,result.history_event_id AS result,result.job_id,
                evaluation.id AS evaluation,evaluation.response_history_event_id AS response,owner.completed_at
            FROM synth_meta.relationship_revisions revision JOIN synth_meta.relationship_results result ON result.history_event_id=revision.result_history_event_id
            JOIN synth_meta.relationship_evaluations evaluation ON evaluation.id=result.evaluation_id
            JOIN synth_meta.request_executions owner ON owner.session_id=evaluation.session_id AND owner.request_id=evaluation.request_id
            WHERE evaluation.request_id=$1',[$owner['request_id']]));
        $this->assertNotFalse($row);$this->assertNotNull($row['completed_at']);
        $this->assertSame($freshResponse['history_event_id'],$row['response']);
        $this->query("UPDATE synth_meta.context_snapshots SET history_event_id=$1 WHERE session_id='relationship-dispatch-session' AND snapshot_sequence=3",[$row['revision']]);
        $this->assertSame('2',$this->savedRelationship($this->savedSelector())['affinity']);
        return ['owner'=>$owner,'row'=>$row];
    }
    private function cancelRetainedOwner(array $owner,bool $anchored): void {
        $caps=$owner['capabilities'];if ($anchored) $caps[]='dialogue.turn_cancel';
        $this->query('UPDATE synth_meta.runtime_sessions SET capabilities=$1::jsonb',[$this->json($caps)]);
        $init=$owner;$init['type']='init';$init['request_id']='retention-init';$init['turn_id']='retention-init-turn';
        $init['context_sequence']=0;$init['capabilities']=$caps;$init['payload']=new stdClass();$this->request($init);
        $request=$owner['request_id'];
        if ($anchored) {
            $context=$owner;$context['type']='context';$context['request_id']='retention-context';$context['context_sequence']=0;
            $context['capabilities']=$caps;
            $context['payload']=json_decode(pg_fetch_assoc($this->query('SELECT context_payload FROM synth_meta.context_snapshots WHERE snapshot_sequence=$1',
                [$owner['context_sequence']]))['context_payload'],true,flags:JSON_THROW_ON_ERROR);
            $this->request($context);$request=$context['request_id'];
        }
        $cancel=$owner;$cancel['type']='cancel';$cancel['request_id']='retention-cancel';$cancel['turn_id']='retention-cancel-turn';
        $cancel['context_sequence']=0;$cancel['capabilities']=$caps;$cancel['payload']=['cancel_request_id'=>$request];
        $this->assertSame('accepted',synth_protocol_claim_request($this->database,$cancel)['status']);
    }
    private function admitRetainedNextTurn(array $owner): array {
        $next=$owner;$next['type']='input_text';$next['request_id']='retention-next';$next['turn_id']='retention-next-turn';
        $next['context_sequence']=3;$next['payload']=['text'=>'What shall we do next?'];
        $next['capabilities']=json_decode(pg_fetch_assoc($this->query('SELECT capabilities FROM synth_meta.runtime_sessions'))['capabilities'],true,flags:JSON_THROW_ON_ERROR);
        $this->assertSame('accepted',synth_protocol_claim_request($this->database,$next)['status']);
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$next];return $next;
    }
    public static function retainedCompletedInputs(): array {
        $cases=[];foreach (['input_text','input_audio'] as $type) foreach ([false,true] as $anchored)
            $cases[$type.' '.($anchored ? 'turn cancellation' : 'request cancellation')]=[$type,$anchored];return $cases;
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('retainedCompletedInputs')]
    public function testCompletedRelationshipSurvivesCancellationAndNewSavedTurn(string $type,bool $anchored): void {
        ['owner'=>$owner,'row'=>$row]=$this->publishedRetainedFixture($type);
        $this->cancelRetainedOwner($owner,$anchored);$this->admitRetainedNextTurn($owner);
        $retired=pg_fetch_assoc($this->query('SELECT state,error_code,response_body,completed_at FROM synth_meta.request_executions WHERE request_id=$1',[$owner['request_id']]));
        $this->assertSame('complete',$retired['state']);$this->assertNull($retired['response_body']);
        $this->assertSame($anchored ? 'turn_cancelled' : 'request_cancelled',$retired['error_code']);
        $this->assertSame($row['completed_at'],$retired['completed_at']);
        $visible=$this->savedRelationship($this->savedSelector());$this->assertNotNull($visible);
        $this->assertSame('2',$visible['affinity']);$this->assertSame($row['revision'],$visible['history_event_id']);
        $this->assertSame($row['result'],$visible['result_history_event_id']);
        $this->assertSame('2',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.relationship_results'))['n']);
        $this->assertSame('1',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.relationship_revisions'))['n']);
        $this->assertSame('ready',pg_fetch_assoc($this->query('SELECT state FROM synth_meta.worker_jobs WHERE id=$1',[$row['job_id']]))['state']);
        $replay=synth_protocol_claim_request($this->database,$owner);$this->assertSame('replay',$replay['status']);
        $this->assertSame('',$replay['body']);$this->assertSame($retired['error_code'],$replay['error_code']);
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$owner];
        try {synth_relationship_capture_evaluation($this->database,(int)$row['response'],'No late evaluation.',true);
            $this->fail('A retained historical receipt must not authorize late work');
        } catch (SYNTHProtocolCancelled $error) {$this->assertSame('request_cancelled',$error->getMessage());}
    }
    public function testRetiredResponseCannotUseCurrentResponseFrontierException(): void {
        ['owner'=>$owner,'row'=>$row]=$this->publishedRetainedFixture();$this->cancelRetainedOwner($owner,false);
        $cte=synth_relationship_visible_cte($this->database,$owner,true,$this->actorId);
        $this->assertSame('0',$this->database->fetchOne($cte.'SELECT count(*) AS n FROM relationship_response_evidence WHERE history_event_id='.$row['response'])['n']);
        $this->assertNull($this->savedRelationship($this->savedSelector(2)));
        $this->assertSame('2',$this->savedRelationship($this->savedSelector())['affinity']);
    }
    public static function invalidRetiredCompletions(): array {
        return ['missing completion timestamp'=>["completed_at=NULL"],'missing cancellation'=>["error_code=NULL"],
            'unrelated failure'=>["error_code='relationship_lease_lost'"],
            'non-null mismatched body'=>["response_body=decode('00','hex')"]];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRetiredCompletions')]
    public function testRetiredReceiptRequiresRetainedCompletionAndExactCancellation(string $mutation): void {
        ['owner'=>$owner]=$this->publishedRetainedFixture();$this->cancelRetainedOwner($owner,false);
        $this->query('UPDATE synth_meta.request_executions SET '.$mutation.' WHERE request_id=$1',[$owner['request_id']]);
        $this->assertNull($this->savedRelationship($this->savedSelector()));
    }
    public function testCompletedNonPlayerReceiptCannotUseCancellationRetention(): void {
        ['owner'=>$owner]=$this->publishedRetainedFixture('activate');$this->cancelRetainedOwner($owner,false);
        $this->assertSame('cancelled',pg_fetch_assoc($this->query('SELECT state FROM synth_meta.request_executions WHERE request_id=$1',[$owner['request_id']]))['state']);
        $this->assertNull($this->savedRelationship($this->savedSelector()));
        $this->query("UPDATE synth_meta.request_executions SET state='complete' WHERE request_id=$1",[$owner['request_id']]);
        $this->assertNull($this->savedRelationship($this->savedSelector()),'Even a complete non-player receipt cannot use the retired-input branch');
    }
    public function testCancelledCompletedReceiptRemainsPriorOfLaterPlayerUpdate(): void {
        ['owner'=>$owner,'row'=>$row]=$this->publishedRetainedFixture();$this->cancelRetainedOwner($owner,true);
        $next=$this->admitRetainedNextTurn($owner);
        $input=['type'=>'inputtext','data'=>'Nora: Let us continue.','people'=>'|Nora|Piper Wright|',
            'delivery_state'=>'received','gamets'=>1000,'localts'=>1000,'ts'=>1000,'rowid'=>910004];
        // The processed input belongs to the already admitted, unchanged canonical text.
        $input['data']='Nora: '.$next['payload']['text'];
        $this->query("INSERT INTO synth_meta.history_events(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
            VALUES('relationship-dispatch-session',1,3,$1,'relationship-dispatch-save',910004,$2::jsonb)",[$next['request_id'],$this->json($input)]);
        $this->completeCapturedOwner($next);$this->query("UPDATE synth_meta.runtime_sessions SET relationship_dispatch_after=clock_timestamp()-INTERVAL '1 second'");
        $calls=0;$result=synth_relationship_dispatch($this->database,function(array $evaluation) use(&$calls,$row): string {
            ++$calls;$this->assertSame((int)$row['revision'],$evaluation['subjects'][0]['relationship']['revision']);
            $this->assertSame(2,$evaluation['subjects'][0]['relationship']['aff']);
            return '{"changes":{"Player":{"delta":3,"reason":"Synthetic continuing trust"}}}';
        },true);
        $this->assertNotNull($result);$this->assertSame(1,$calls);
        $latest=pg_fetch_assoc($this->query('SELECT history_event_id,prior_history_event_id,affinity FROM synth_meta.relationship_revisions ORDER BY history_event_id DESC LIMIT 1'));
        $this->assertSame($row['revision'],$latest['prior_history_event_id']);$this->assertSame('5',$latest['affinity']);
        $context=$this->context;$context['snapshot_sequence']=4;
        $this->query("INSERT INTO synth_meta.context_snapshots(session_id,generation,snapshot_sequence,playthrough_id,player_identity_id,target_identity_id,audience_identity_ids,context_payload,history_event_id)
            VALUES('relationship-dispatch-session',1,4,'relationship-dispatch-save',$1,$2,$3::jsonb,$4::jsonb,$5)",
            [$this->playerId,$this->actorId,$this->json([$this->actorId]),$this->json($context),$latest['history_event_id']]);
        $this->assertSame('5',$this->savedRelationship($this->savedSelector(4))['affinity']);
        $this->assertSame('3',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.relationship_results'))['n']);
        $this->assertSame('2',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.relationship_revisions'))['n']);
    }
    public static function forbiddenChainReasons(): array {
        $cases=[];
        foreach ([false,true] as $pair) foreach (['private','no_candidate','ineligible'] as $reason)
            $cases[($pair ? 'pair ' : 'player ').$reason]=[$pair,$reason];
        return $cases;
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenChainReasons')]
    public function testForbiddenNpcChainCannotOwnEitherLane(bool $pair,string $reason): void {
        [, $chain]=$this->continuation('npc');
        $this->query('UPDATE synth_meta.rechat_chains SET terminal_reason=$1 WHERE id=$2',[$reason,$chain]);
        $this->assertSame($this->parent['request_id'],synth_relationship_reserve_dispatch($this->database,$pair)['request_id']);
    }
    private function secondaryNpc(array $sequences,?array $identity=null): array {
        $identity ??=['form_id'=>'0x0001A4D7','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'relationship-dispatch-save','display_name'=>'Preston Garvey'];
        $id=$this->identity($identity);
        $profile=(int)pg_fetch_assoc($this->query("INSERT INTO synth.core_profiles(label) VALUES('Synthetic addressed NPC') RETURNING id"))['id'];
        $npc=(int)pg_fetch_assoc($this->query('INSERT INTO synth.core_npc_master(npc_name,profile_id,actor_identity_id) VALUES($1,$2,$3) RETURNING id',
            [$identity['display_name'],$profile,$id]))['id'];
        foreach ($sequences as $sequence) {
            $stored=pg_fetch_assoc($this->query("SELECT context_payload,audience_identity_ids FROM synth_meta.context_snapshots WHERE session_id='relationship-dispatch-session' AND snapshot_sequence=$1",[$sequence]));
            $this->assertNotFalse($stored);
            $context=json_decode($stored['context_payload'],true,flags:JSON_THROW_ON_ERROR);$context['audience'][]=$identity;
            $audience=array_values(array_unique([...json_decode($stored['audience_identity_ids'],true,flags:JSON_THROW_ON_ERROR),$id]));
            $this->query("UPDATE synth_meta.context_snapshots SET context_payload=$1::jsonb,audience_identity_ids=$2::jsonb
                WHERE session_id='relationship-dispatch-session' AND snapshot_sequence=$3",[$this->json($context),$this->json($audience),$sequence]);
        }
        return ['identity'=>$identity,'actor_id'=>$id,'profile_id'=>$profile,'npc_id'=>$npc];
    }
    private function captureOwnedSpeech(array $owner,array $speaker,string $listener): void {
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$owner];
        synth_relationship_begin_response();
        $line=synth_relationship_capture_line($speaker['display_name'],'You are welcome.');
        synth_relationship_capture_route($line,$listener,'fixture:dialogue');synth_relationship_finish_response(true);
        $body=pg_fetch_assoc($this->query("SELECT encode(response_body,'hex') AS body FROM synth_meta.request_executions WHERE request_id=$1",[$owner['request_id']]))['body'];
        synth_relationship_store_response($this->database,$owner,hex2bin($body));
    }
    public function testAddressedNpcPairPublishesIndependentDirectionsOnceWithoutPlayerAttribution(): void {
        // Consume the separate valid earlier player exchange without changing its score.
        // Otherwise the player lane is entitled to evaluate that unrelated frozen response.
        $baselineCalls=0;
        $baseline=synth_relationship_dispatch($this->database,function() use (&$baselineCalls): string {++$baselineCalls;return '{"changes":{}}';},true);
        $this->assertNotNull($baseline);$this->assertSame(1,$baselineCalls);
        $npc=$this->secondaryNpc([2,3]);$owner=$this->parent;
        $owner['request_id']='relationship-pair-origin';$owner['turn_id']='relationship-pair-turn';$owner['context_sequence']=2;
        $owner['payload']['text']='Please speak to each other.';
        $this->request($owner);$this->query("UPDATE synth_meta.runtime_sessions SET active_turn_id=$1,active_turn_sequence=2",[$owner['turn_id']]);
        $input=['type'=>'inputtext','data'=>'Nora: Please speak to each other.','people'=>'|Nora|Piper Wright|Preston Garvey|',
            'delivery_state'=>'received','gamets'=>1000,'localts'=>1000,'ts'=>1000,'rowid'=>910002];
        $history=(int)pg_fetch_assoc($this->query("INSERT INTO synth_meta.history_events
                (session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
            VALUES('relationship-dispatch-session',1,2,$1,'relationship-dispatch-save',910002,$2::jsonb) RETURNING id",[$owner['request_id'],$this->json($input)]))['id'];
        $this->query("UPDATE synth_meta.context_snapshots SET history_event_id=$1 WHERE session_id='relationship-dispatch-session' AND snapshot_sequence=2",[$history]);
        $this->response($owner,$npc['identity']);$this->captureOwnedSpeech($owner,$this->context['target'],$npc['identity']['display_name']);
        $calls=0;
        $result=synth_relationship_dispatch_interaction($this->database,function(array $input) use (&$calls,$npc,$owner): string {
            ++$calls;$this->assertTrue($this->database->isTransactionIdle());
            $this->assertSame($owner['request_id'],$input['owner']['request_id']);
            $this->assertSame($this->actorId,$input['interaction']['actor_ids']['speaker']);
            $this->assertSame($npc['actor_id'],$input['interaction']['actor_ids']['listener']);
            return '{"speaker":{"delta":3,"reason":"Synthetic encouragement"},"listener":{"delta":-2,"reason":"Synthetic caution"}}';
        });
        $this->assertNotNull($result);$this->assertSame(1,$calls);
        $directions=pg_fetch_all($this->query('SELECT actor_identity_id,subject_identity_id,affinity FROM synth_meta.relationship_revisions ORDER BY actor_identity_id'));
        $this->assertSame([
            ['actor_identity_id'=>(string)$this->actorId,'subject_identity_id'=>(string)$npc['actor_id'],'affinity'=>'3'],
            ['actor_identity_id'=>(string)$npc['actor_id'],'subject_identity_id'=>(string)$this->actorId,'affinity'=>'-2'],
        ],$directions);
        $this->assertSame('1',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.relationship_interaction_results'))['n']);
        $this->assertSame('0',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.relationship_revisions WHERE subject_identity_id=$1',[$this->playerId]))['n']);
        $this->query("UPDATE synth_meta.runtime_sessions SET interaction_dispatch_after=clock_timestamp()-INTERVAL '1 second'");
        $this->assertNull(synth_relationship_dispatch_interaction($this->database,function() use (&$calls): string {++$calls;return '{}';}));
        $this->query("UPDATE synth_meta.runtime_sessions SET relationship_dispatch_after=clock_timestamp()-INTERVAL '1 second'");
        $this->assertNull(synth_relationship_dispatch($this->database,function() use (&$calls): string {++$calls;return '{"changes":{}}';},true));
        $this->assertSame(1,$calls,'Addressed NPC speech cannot generate duplicate or player-attributed evaluation');
    }
    public function testDistinctSelectedResponderCannotInheritCapturedTargetsPrivateEvidence(): void {
        $npc=$this->secondaryNpc([1,2,3]);
        $this->query("UPDATE synth_meta.response_routes SET listener=$1::jsonb WHERE request_id='relationship-parent'",[$this->json($npc['identity'])]);
        [$child,$chain]=$this->continuation('npc');
        $this->query("UPDATE synth_meta.rechat_chains SET policy=jsonb_set(policy,'{private}','false') WHERE id=$1",[$chain]);
        $this->query("UPDATE synth_meta.rechat_selections SET actor_identity_id=$1,npc_id=$2,profile_id=$3,identity=$4::jsonb WHERE request_id=$5",
            [$npc['actor_id'],$npc['npc_id'],$npc['profile_id'],$this->json($npc['identity']),$child['request_id']]);
        $this->response($child,$this->context['player'],$npc['identity']);
        $this->captureOwnedSpeech($child,$npc['identity'],'Player');
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$child];
        $receipt=pg_fetch_assoc($this->query('SELECT history_event_id,actor_identity_id FROM synth_meta.relationship_response_inputs WHERE request_id=$1',[$child['request_id']]));
        $this->assertNotFalse($receipt,'The public prepared responder must retain its own accepted speech.');
        $this->assertSame((string)$npc['actor_id'],$receipt['actor_identity_id']);
        $selected=synth_protocol_response_actor_identity($this->database,$child,synth_protocol_current_context($this->database,$child));
        $actual=array_intersect_key($selected,array_flip(array_keys($npc['identity'])));$expected=$npc['identity'];
        ksort($actual);ksort($expected);$this->assertSame($expected,$actual);
        $cte=synth_relationship_visible_cte($this->database,$child,true,$npc['actor_id']);
        $this->assertSame('1',$this->database->fetchOne($cte.'SELECT count(*) AS n FROM relationship_response_evidence WHERE history_event_id='.$receipt['history_event_id'])['n'],
            'Own B response evidence is admitted while A private input stays excluded.');
        $this->assertSame('0',$this->database->fetchOne($cte.'SELECT count(*) AS n FROM native_history WHERE history_event_id='.$this->inputHistoryId." AND type='inputtext'")['n'],
            'Being present in the scene does not expose the captured target\'s private player input');
        $calls=0;
        $this->assertNull(synth_relationship_dispatch($this->database,function() use (&$calls): string {++$calls;return '{"changes":{"Player":{"delta":99}}}';},true));
        $this->assertSame(0,$calls);
        $this->assertSame('0',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.relationship_results'))['n']);
        $this->assertSame('0',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.relationship_revisions'))['n']);
        $this->assertSame($child,$GLOBALS['SYNTH_REQUEST_EVENT']['json']);
    }

    private function savedPair(int $reader,int $subject,int $sequence=3,?array $selector=null,bool $current=false): ?array {
        return $this->database->fetchOne(synth_relationship_visible_cte($this->database,$selector ?? $this->savedSelector($sequence),$current,$reader)
            .'SELECT history_event_id,affinity,interaction_result_history_event_id FROM native_relationships'
            .' WHERE actor_identity_id='.$reader.' AND subject_identity_id='.$subject.' ORDER BY history_event_id DESC LIMIT 1');
    }
    private function publicNpcParent(array $npc): array {
        $baselineCalls=0;$this->assertNotNull(synth_relationship_dispatch($this->database,function() use(&$baselineCalls): string {
            ++$baselineCalls;return '{"changes":{}}';
        },true));$this->assertSame(1,$baselineCalls);
        $parent=$this->parent;
        $parent['request_id']='public-pair-parent';$parent['turn_id']='public-pair-turn';
        $parent['payload']['text']='Please have a public discussion.';
        $this->request($parent);$this->query('UPDATE synth_meta.runtime_sessions SET active_turn_id=$1,active_turn_sequence=1',[$parent['turn_id']]);
        $input=['type'=>'inputtext','data'=>'Nora: Please have a public discussion.','people'=>'|Nora|Piper Wright|Preston Garvey|',
            'delivery_state'=>'received','gamets'=>1000,'localts'=>1000,'ts'=>1000,'rowid'=>910004];
        $incoming=(int)pg_fetch_assoc($this->query("INSERT INTO synth_meta.history_events
                (session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
            VALUES('relationship-dispatch-session',1,1,$1,'relationship-dispatch-save',910004,$2::jsonb) RETURNING id",
            [$parent['request_id'],$this->json($input)]))['id'];
        $this->query('UPDATE synth_meta.context_snapshots SET history_event_id=$1 WHERE snapshot_sequence=1',[$incoming]);
        $this->response($parent,$npc['identity']);$this->captureOwnedSpeech($parent,$this->context['target'],$npc['identity']['display_name']);
        $parentReceipt=pg_fetch_assoc($this->query('SELECT history_event_id,actor_identity_id FROM synth_meta.relationship_response_inputs WHERE request_id=$1',[$parent['request_id']]));
        $this->assertNotFalse($parentReceipt,'The public parent A->B must have a durable accepted receipt.');
        $this->assertSame((string)$this->actorId,$parentReceipt['actor_identity_id']);
        $this->query('UPDATE synth_meta.context_snapshots SET history_event_id=$1 WHERE snapshot_sequence=2',[$parentReceipt['history_event_id']]);
        return ['parent'=>$parent,'receipt'=>$parentReceipt];
    }

    public function testPreparedResponderUsesFreshOwnCombatTargetWithoutWritingOtherTargetsToHistory(): void {
        $npc=$this->secondaryNpc([1,2,3]);
        $this->parent['capabilities'][]='context.combat_targets';
        $this->parent['capabilities'][]='context.enemy_awareness';
        $sample=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/context-combat-identified.json'),true,flags:JSON_THROW_ON_ERROR)['payload']['actor_states'][0];
        $makeState=static function(array $identity,string $name,int $target) use($sample): array {
            $state=$sample;$state['actor']=$identity;$state['base_form_id']=$identity['form_id'];
            $state['combat_target']=['observation'=>'identified','actor'=>['form_id'=>sprintf('0x%08X',$target),
                'origin_plugin'=>'Fallout4.esm','playthrough_id'=>$identity['playthrough_id'],'display_name'=>$name],'race'=>'HumanRace'];
            $visible=$state['combat_target']['actor'];$visible['form_id']=sprintf('0x%08X',$target+16);$visible['display_name']=$name.' visible';
            $recent=$visible;$recent['form_id']=sprintf('0x%08X',$target+32);$recent['display_name']=$name.' recent';
            $state['enemy_awareness']=['current'=>[['actor'=>$visible,'race'=>'HumanRace','distance'=>120,'hostile'=>true,
                'in_combat'=>true,'line_of_sight'=>'visible']],
                'recent'=>[['actor'=>$recent,'race'=>'HumanRace','observation'=>'seen_hostile','age_ms'=>500]]];
            return $state;
        };
        foreach ([1,2] as $sequence) {
            $context=json_decode(pg_fetch_assoc($this->query('SELECT context_payload FROM synth_meta.context_snapshots WHERE snapshot_sequence=$1',[$sequence]))['context_payload'],true,flags:JSON_THROW_ON_ERROR);
            $context['actor_states']=[
                $makeState($this->context['target'],$sequence===1 ? 'Earlier root opponent' : 'Current scene focus opponent',0xFF000121),
                $makeState($npc['identity'],$sequence===1 ? 'Earlier responder opponent' : 'Current prepared responder opponent',0xFF000122)];
            $this->query('UPDATE synth_meta.context_snapshots SET context_payload=$1::jsonb,received_at=clock_timestamp() WHERE snapshot_sequence=$2',[$this->json($context),$sequence]);
        }
        ['parent'=>$parent]=$this->publicNpcParent($npc);
        [$owner,$chain]=$this->continuation('npc',true,$parent);
        $this->query('UPDATE synth_meta.rechat_selections SET actor_identity_id=$1,npc_id=$2,profile_id=$3,identity=$4::jsonb WHERE request_id=$5',
            [$npc['actor_id'],$npc['npc_id'],$npc['profile_id'],$this->json($npc['identity']),$owner['request_id']]);
        $policy=['mode'=>'conversational','private'=>false,'rounds'=>3,'probability'=>100,'allow_actions'=>false,
            'random_narration'=>false,'narration_chance'=>0,'narration_cooldown'=>0,
            'source_profile_id'=>$this->profileId,'source_actor_identity_id'=>$this->actorId];
        $this->query('UPDATE synth_meta.rechat_chains SET policy=$1::jsonb WHERE id=$2',[$this->json($policy),$chain]);
        $this->query('UPDATE synth_meta.runtime_sessions SET capabilities=$1::jsonb',[$this->json($owner['capabilities'])]);
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$owner];
        $rootBefore=pg_fetch_assoc($this->query('SELECT context_payload::text AS payload FROM synth_meta.context_snapshots WHERE snapshot_sequence=1'))['payload'];
        $sceneBefore=pg_fetch_assoc($this->query('SELECT context_payload::text AS payload FROM synth_meta.context_snapshots WHERE snapshot_sequence=2'))['payload'];
        $previous=[];
        foreach (['SYNTH_TURN_ACTOR_STATES','SYNTH_INPUT_TARGET_IDENTITY','SYNTH_EXTERNAL_REQUEST','gameRequest'] as $key) $previous[$key]=$GLOBALS[$key] ?? null;
        try {
            $request=['trigger',1,2,''];
            $this->assertTrue(synth_protocol_bind_trigger_context($this->database,$owner,$request));
            $this->assertSame(synth_protocol_canonical_json($npc['identity']),synth_protocol_canonical_json($GLOBALS['SYNTH_INPUT_TARGET_IDENTITY']));
            $prompt=synth_protocol_current_combat_target_prompt($GLOBALS['SYNTH_TURN_ACTOR_STATES'],$GLOBALS['SYNTH_INPUT_TARGET_IDENTITY']);
            $this->assertStringContainsString('Current prepared responder opponent',$prompt);
            $this->assertStringContainsString('0xFF000122',$prompt);
            $this->assertStringNotContainsString('Current scene focus opponent',$prompt);
            $this->assertStringNotContainsString('Earlier responder opponent',$prompt);
            $observer=synth_combat_observer_message($owner,$GLOBALS['SYNTH_TURN_ACTOR_STATES'],$GLOBALS['SYNTH_INPUT_TARGET_IDENTITY'],$this->context['player']);
            $this->assertSame('system',$observer['role']);
            $this->assertStringContainsString('Current prepared responder opponent',$observer['content']);
            $this->assertStringContainsString('0xFF000122',$observer['content']);
            $this->assertStringNotContainsString('Current scene focus opponent',$observer['content']);
            $this->assertStringNotContainsString('Earlier responder opponent',$observer['content']);
            $this->assertStringContainsString('Current prepared responder opponent visible',$observer['content']);
            $this->assertStringContainsString('Current prepared responder opponent recent',$observer['content']);
            $this->assertStringNotContainsString('Current scene focus opponent visible',$observer['content']);
            $this->assertStringNotContainsString('Earlier responder opponent recent',$observer['content']);
            $this->assertStringNotContainsString('combat_target',$request[3]);
            $this->assertStringNotContainsString('enemy_awareness',$request[3]);
            $this->assertStringNotContainsString('opponent',$request[3]);
            $this->assertStringNotContainsString('combat_target',json_encode($GLOBALS['SYNTH_EXTERNAL_REQUEST']));
            $this->assertStringNotContainsString('enemy_awareness',json_encode($GLOBALS['SYNTH_EXTERNAL_REQUEST']));
            $this->assertSame($rootBefore,pg_fetch_assoc($this->query('SELECT context_payload::text AS payload FROM synth_meta.context_snapshots WHERE snapshot_sequence=1'))['payload']);
            $this->assertSame($sceneBefore,pg_fetch_assoc($this->query('SELECT context_payload::text AS payload FROM synth_meta.context_snapshots WHERE snapshot_sequence=2'))['payload']);
            $this->assertSame(synth_protocol_canonical_json([$this->context['target'],$npc['identity']]),
                synth_protocol_canonical_json(json_decode($sceneBefore,true,flags:JSON_THROW_ON_ERROR)['audience']));
        } finally {
            foreach ($previous as $key=>$value) if ($value===null) unset($GLOBALS[$key]);else $GLOBALS[$key]=$value;
        }
    }

    public function testPreparedNpcResponderPublishesItsOwnReceiptAndRetainsBothSavedDirections(): void {
        // The root target starts A->B; the prepared responder then emits B->A at the same canonical root.
        $npc=$this->secondaryNpc([1,2,3]);
        ['parent'=>$parent,'receipt'=>$parentReceipt]=$this->publicNpcParent($npc);
        [$owner,$chain]=$this->continuation('npc',true,$parent);
        $this->query('UPDATE synth_meta.rechat_selections SET actor_identity_id=$1,npc_id=$2,profile_id=$3,identity=$4::jsonb WHERE request_id=$5',
            [$npc['actor_id'],$npc['npc_id'],$npc['profile_id'],$this->json($npc['identity']),$owner['request_id']]);
        $policy=['mode'=>'conversational','private'=>false,'rounds'=>3,'probability'=>100,'allow_actions'=>false,
            'random_narration'=>false,'narration_chance'=>0,'narration_cooldown'=>0,
            'source_profile_id'=>$this->profileId,'source_actor_identity_id'=>$this->actorId];
        $this->query('UPDATE synth_meta.rechat_chains SET policy=$1::jsonb WHERE id=$2',[$this->json($policy),$chain]);
        $this->query('UPDATE synth_meta.runtime_sessions SET capabilities=$1::jsonb',[$this->json($owner['capabilities'])]);
        $this->response($owner,$this->context['target'],$npc['identity']);
        $bytes=hex2bin(pg_fetch_assoc($this->query("SELECT encode(response_body,'hex') AS body FROM synth_meta.request_executions WHERE request_id=$1",[$owner['request_id']]))['body']);
        $this->query("UPDATE synth_meta.request_executions SET state='claimed',completed_at=NULL WHERE request_id=$1",[$owner['request_id']]);
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$owner,'request_id'=>$owner['request_id'],'runtime_variant'=>$owner['runtime_variant']];
        synth_protocol_complete_request($this->database,'complete','application/x-ndjson',$bytes);
        $this->captureOwnedSpeech($owner,$npc['identity'],$this->context['target']['display_name']);
        $receipt=pg_fetch_assoc($this->query('SELECT history_event_id,actor_identity_id,response_payload FROM synth_meta.relationship_response_inputs WHERE request_id=$1',[$owner['request_id']]));
        $this->assertNotFalse($receipt);
        $snapshot=synth_protocol_current_context($this->database,$owner);
        $this->assertSame(synth_protocol_canonical_json($this->context['target']),synth_protocol_canonical_json($snapshot['payload']['target']),'The captured canonical scene target remains A.');
        $this->assertSame(1,$owner['context_sequence']);$this->assertSame(2,$snapshot['payload']['snapshot_sequence']);
        $source=$this->database->fetchOne(synth_protocol_history_cte($this->database,$owner).'SELECT reader_identity_id FROM native_history_scopes LIMIT 1');
        $this->assertSame((string)$npc['actor_id'],$source['reader_identity_id'],'Source evidence uses the prepared responder B.');
        $this->assertSame((string)$npc['actor_id'],$receipt['actor_identity_id'],
            'A prepared B->A reply must be published under B, even though the captured root target is A.');
        $payload=json_decode($receipt['response_payload'],true,flags:JSON_THROW_ON_ERROR);
        $this->assertSame(synth_protocol_canonical_json($owner),synth_protocol_canonical_json($payload['request']));
        $this->assertSame($npc['identity']['form_id'],$payload['entries'][0]['speaker']['form_id']);
        $this->assertSame($this->context['target']['form_id'],$payload['entries'][0]['routing']['listener']['form_id']);
        $canonical=pg_fetch_assoc($this->query('SELECT canonical_event FROM synth_meta.protocol_events WHERE request_id=$1',[$owner['request_id']]));
        $this->assertSame(synth_protocol_canonical_json($owner),synth_protocol_canonical_json(json_decode($canonical['canonical_event'],true,flags:JSON_THROW_ON_ERROR)));
        $this->query("UPDATE synth_meta.runtime_sessions SET interaction_dispatch_after=clock_timestamp()-INTERVAL '1 second'");
        $calls=0;$result=synth_relationship_dispatch_interaction($this->database,function(array $input) use(&$calls,$owner,$receipt,$npc): string {
            ++$calls;$this->assertTrue($this->database->isTransactionIdle());
            $this->assertSame(synth_protocol_canonical_json($owner),synth_protocol_canonical_json($input['owner']));
            $this->assertSame(synth_protocol_canonical_json($owner),synth_protocol_canonical_json($input['response']['request']));
            $this->assertSame((int)$receipt['history_event_id'],$input['interaction']['response_id']);
            $this->assertSame($npc['actor_id'],$input['interaction']['actor_ids']['speaker']);
            $this->assertSame($this->actorId,$input['interaction']['actor_ids']['listener']);
            $this->assertSame(2,$input['context']['snapshot_sequence']);
            return '{"speaker":{"delta":3,"reason":"Synthetic encouragement"},"listener":{"delta":-2,"reason":"Synthetic caution"}}';
        });$this->assertNotNull($result);$this->assertSame(1,$calls);
        $this->assertSame('1',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.relationship_interaction_evaluations'))['n']);
        $this->assertSame('1',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.relationship_interaction_results'))['n']);
        $revisions=pg_fetch_all($this->query('SELECT history_event_id,actor_identity_id,subject_identity_id,affinity FROM synth_meta.relationship_revisions ORDER BY actor_identity_id'));
        $this->assertCount(2,$revisions);
        $this->assertSame([
            ['actor_identity_id'=>(string)$this->actorId,'subject_identity_id'=>(string)$npc['actor_id'],'affinity'=>'-2'],
            ['actor_identity_id'=>(string)$npc['actor_id'],'subject_identity_id'=>(string)$this->actorId,'affinity'=>'3'],
        ],array_map(static fn(array $row): array=>array_diff_key($row,['history_event_id'=>true]),$revisions));
        $this->assertSame('0',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.relationship_revisions WHERE subject_identity_id=$1',[$this->playerId]))['n']);
        $frontier=max(array_map('intval',array_column($revisions,'history_event_id')));
        $this->query('UPDATE synth_meta.context_snapshots SET history_event_id=$1 WHERE snapshot_sequence=3',[$frontier]);
        $before=[$this->savedPair($npc['actor_id'],$this->actorId),$this->savedPair($this->actorId,$npc['actor_id'])];
        $this->assertNotNull($before[0]);$this->assertNotNull($before[1]);
        $this->assertSame('3',$before[0]['affinity']);$this->assertSame('-2',$before[1]['affinity']);
        $this->assertNull($this->savedPair($npc['actor_id'],$this->actorId,1));
        $this->assertNull($this->savedPair($this->actorId,$npc['actor_id'],1));
        $this->cancelRetainedOwner($owner,true);$this->admitRetainedNextTurn($owner);
        $this->assertSame($before,[$this->savedPair($npc['actor_id'],$this->actorId),$this->savedPair($this->actorId,$npc['actor_id'])],
            'Both accepted responder directions survive Stop at the saved frontier.');
        $loaded=$this->context;$loaded['snapshot_sequence']=1;$loaded['audience'][]=$npc['identity'];
        $loaded['loaded_context']=['session_id'=>$owner['session_id'],'generation'=>1,'context_sequence'=>3,'playthrough_id'=>$this->context['playthrough_id']];
        $this->query("UPDATE synth_meta.runtime_sessions SET active_generation=2,state='expired' WHERE session_id=$1",[$owner['session_id']]);
        foreach (['prepared-pair-later'=>3,'prepared-pair-earlier'=>1] as $session=>$sequence) {
            $loaded['loaded_context']['context_sequence']=$sequence;
            $this->query("INSERT INTO synth_meta.runtime_sessions(session_id,playthrough_id,game,runtime_variant,client_version,runtime_version,active_generation,capabilities,state)
                VALUES($1,'relationship-dispatch-save','fo4','flat','fixture','fixture',1,'[]','active')",[$session]);
            $this->query("INSERT INTO synth_meta.context_snapshots(session_id,generation,snapshot_sequence,playthrough_id,player_identity_id,target_identity_id,audience_identity_ids,context_payload,history_event_id)
                SELECT $1,1,1,playthrough_id,player_identity_id,target_identity_id,audience_identity_ids,$2::jsonb,history_event_id
                FROM synth_meta.context_snapshots WHERE session_id=$3 AND generation=1 AND snapshot_sequence=$4",
                [$session,$this->json($loaded),$owner['session_id'],$sequence]);
            $selector=['protocol_version'=>2,'session_id'=>$session,'generation'=>1,'context_sequence'=>1,'runtime_variant'=>'flat'];
            $actual=[$this->savedPair($npc['actor_id'],$this->actorId,1,$selector),$this->savedPair($this->actorId,$npc['actor_id'],1,$selector)];
            $this->assertSame($sequence===3 ? $before : [null,null],$actual,
                'Reload inherits the selected saved anchor and excludes the exchange in the earlier save.');
        }
        $this->assertSame(synth_protocol_canonical_json($owner),synth_protocol_canonical_json($payload['request']));
    }

    public function testPreparedNpcGrandchildRetainsDistinctRootParentAndSceneActors(): void {
        $b=$this->secondaryNpc([1,2,3]);
        $c=$this->secondaryNpc([1,2,3],['form_id'=>'0x0001A4D8','origin_plugin'=>'Fallout4.esm',
            'playthrough_id'=>'relationship-dispatch-save','display_name'=>'Jun Long']);
        ['parent'=>$root]=$this->publicNpcParent($b);
        [$parent,$chain]=$this->continuation('npc',true,$root);
        $this->query("UPDATE synth_meta.rechat_chains SET policy=jsonb_set(policy,'{private}','false') WHERE id=$1",[$chain]);
        $this->query('UPDATE synth_meta.rechat_selections SET actor_identity_id=$1,npc_id=$2,profile_id=$3,identity=$4::jsonb WHERE request_id=$5',
            [$b['actor_id'],$b['npc_id'],$b['profile_id'],$this->json($b['identity']),$parent['request_id']]);
        $this->query('UPDATE synth_meta.runtime_sessions SET capabilities=$1::jsonb',[$this->json($parent['capabilities'])]);
        $this->response($parent,$c['identity'],$b['identity']);$this->captureOwnedSpeech($parent,$b['identity'],$c['identity']['display_name']);
        $parentReceipt=pg_fetch_assoc($this->query('SELECT history_event_id,actor_identity_id FROM synth_meta.relationship_response_inputs WHERE request_id=$1',[$parent['request_id']]));
        $this->assertNotFalse($parentReceipt);$this->assertSame((string)$b['actor_id'],$parentReceipt['actor_identity_id']);
        $scene=json_decode(pg_fetch_assoc($this->query('SELECT context_payload FROM synth_meta.context_snapshots WHERE snapshot_sequence=3'))['context_payload'],true,flags:JSON_THROW_ON_ERROR);
        $scene['target']=$b['identity'];
        $this->query('UPDATE synth_meta.context_snapshots SET target_identity_id=$1,context_payload=$2::jsonb,history_event_id=$3,received_at=clock_timestamp() WHERE snapshot_sequence=3',
            [$b['actor_id'],$this->json($scene),$parentReceipt['history_event_id']]);
        $saved=$scene;$saved['snapshot_sequence']=4;
        $this->query("INSERT INTO synth_meta.context_snapshots(session_id,generation,snapshot_sequence,playthrough_id,player_identity_id,target_identity_id,audience_identity_ids,context_payload,history_event_id)
            SELECT session_id,generation,4,playthrough_id,player_identity_id,target_identity_id,audience_identity_ids,$1::jsonb,history_event_id FROM synth_meta.context_snapshots WHERE snapshot_sequence=3",[$this->json($saved)]);
        [$owner]=$this->continuation('npc',true,$parent,$chain,true,$b['identity']);
        $this->query('UPDATE synth_meta.rechat_selections SET actor_identity_id=$1,npc_id=$2,profile_id=$3,identity=$4::jsonb WHERE request_id=$5',
            [$c['actor_id'],$c['npc_id'],$c['profile_id'],$this->json($c['identity']),$owner['request_id']]);
        $this->response($owner,$b['identity'],$c['identity']);$this->captureOwnedSpeech($owner,$c['identity'],$b['identity']['display_name']);
        $receipt=pg_fetch_assoc($this->query('SELECT history_event_id,actor_identity_id,response_payload FROM synth_meta.relationship_response_inputs WHERE request_id=$1',[$owner['request_id']]));
        $this->assertNotFalse($receipt);$this->assertSame((string)$c['actor_id'],$receipt['actor_identity_id']);
        $this->assertSame(1,$owner['context_sequence']);$this->assertSame(3,$owner['payload']['scene_context_sequence']);
        $this->assertSame(synth_protocol_canonical_json($b['identity']),synth_protocol_canonical_json($owner['payload']['actor']));
        $rootContext=json_decode(pg_fetch_assoc($this->query('SELECT context_payload FROM synth_meta.context_snapshots WHERE snapshot_sequence=1'))['context_payload'],true,flags:JSON_THROW_ON_ERROR);
        $this->assertSame(synth_protocol_canonical_json($this->context['target']),synth_protocol_canonical_json($rootContext['target']));
        $this->assertSame(synth_protocol_canonical_json($scene),synth_protocol_canonical_json(synth_protocol_current_context($this->database,$owner)['payload']));
        $payload=json_decode($receipt['response_payload'],true,flags:JSON_THROW_ON_ERROR);
        $this->assertSame(synth_protocol_canonical_json($owner),synth_protocol_canonical_json($payload['request']));
        $this->assertSame($c['identity']['form_id'],$payload['entries'][0]['speaker']['form_id']);
        $this->assertSame($b['identity']['form_id'],$payload['entries'][0]['routing']['listener']['form_id']);
        $this->query("UPDATE synth_meta.runtime_sessions SET interaction_dispatch_after=clock_timestamp()-INTERVAL '1 second'");
        $calls=0;$result=synth_relationship_dispatch_interaction($this->database,function(array $input) use(&$calls,$owner,$receipt,$b,$c): string {
            ++$calls;$this->assertTrue($this->database->isTransactionIdle());
            $this->assertSame(synth_protocol_canonical_json($owner),synth_protocol_canonical_json($input['owner']));
            $this->assertSame(synth_protocol_canonical_json($owner),synth_protocol_canonical_json($input['response']['request']));
            $this->assertSame((int)$receipt['history_event_id'],$input['interaction']['response_id']);
            $this->assertSame($c['actor_id'],$input['interaction']['actor_ids']['speaker']);$this->assertSame($b['actor_id'],$input['interaction']['actor_ids']['listener']);
            $this->assertSame(3,$input['context']['snapshot_sequence']);
            return '{"speaker":{"delta":3,"reason":"Synthetic encouragement"},"listener":{"delta":-2,"reason":"Synthetic caution"}}';
        });$this->assertNotNull($result);$this->assertSame(1,$calls);
        $job=pg_fetch_assoc($this->query('SELECT job.actor_identity_id,job.state,evaluation.speaker_identity_id,evaluation.listener_identity_id FROM synth_meta.worker_jobs job
            JOIN synth_meta.relationship_interaction_evaluations evaluation ON evaluation.id=job.relationship_interaction_id WHERE evaluation.request_id=$1',[$owner['request_id']]));
        $this->assertSame((string)$c['actor_id'],$job['actor_identity_id']);$this->assertSame((string)$c['actor_id'],$job['speaker_identity_id']);
        $this->assertSame((string)$b['actor_id'],$job['listener_identity_id']);$this->assertSame('ready',$job['state']);
        $this->assertSame('1',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.relationship_interaction_evaluations'))['n']);
        $this->assertSame('1',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.relationship_interaction_results'))['n']);
        $this->assertSame('2',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.relationship_revisions'))['n']);
        $frontier=(int)pg_fetch_assoc($this->query('SELECT max(history_event_id) AS n FROM synth_meta.relationship_revisions'))['n'];
        $this->query('UPDATE synth_meta.context_snapshots SET history_event_id=$1 WHERE snapshot_sequence=4',[$frontier]);
        $before=[$this->savedPair($c['actor_id'],$b['actor_id'],4),$this->savedPair($b['actor_id'],$c['actor_id'],4)];
        $this->assertNotNull($before[0]);$this->assertNotNull($before[1]);$this->assertSame('3',$before[0]['affinity']);$this->assertSame('-2',$before[1]['affinity']);
        $this->assertNull($this->savedPair($c['actor_id'],$b['actor_id'],2));$this->assertNull($this->savedPair($b['actor_id'],$c['actor_id'],2));
        $this->cancelRetainedOwner($owner,true);$this->admitRetainedNextTurn($owner);
        $this->assertSame($before,[$this->savedPair($c['actor_id'],$b['actor_id'],4),$this->savedPair($b['actor_id'],$c['actor_id'],4)]);
        $this->query("UPDATE synth_meta.runtime_sessions SET active_generation=2,state='expired' WHERE session_id=$1",[$owner['session_id']]);
        foreach (['grandchild-later'=>4,'grandchild-earlier'=>2] as $session=>$sequence) {
            $loaded=$saved;$loaded['snapshot_sequence']=1;$loaded['loaded_context']=['session_id'=>$owner['session_id'],'generation'=>1,
                'context_sequence'=>$sequence,'playthrough_id'=>$this->context['playthrough_id']];
            $this->query("INSERT INTO synth_meta.runtime_sessions(session_id,playthrough_id,game,runtime_variant,client_version,runtime_version,active_generation,capabilities,state)
                VALUES($1,'relationship-dispatch-save','fo4','flat','fixture','fixture',1,'[]','active')",[$session]);
            $this->query("INSERT INTO synth_meta.context_snapshots(session_id,generation,snapshot_sequence,playthrough_id,player_identity_id,target_identity_id,audience_identity_ids,context_payload,history_event_id)
                SELECT $1,1,1,playthrough_id,player_identity_id,target_identity_id,audience_identity_ids,$2::jsonb,history_event_id FROM synth_meta.context_snapshots WHERE session_id=$3 AND generation=1 AND snapshot_sequence=$4",
                [$session,$this->json($loaded),$owner['session_id'],$sequence]);
            $selector=['protocol_version'=>2,'session_id'=>$session,'generation'=>1,'context_sequence'=>1,'runtime_variant'=>'flat'];
            $this->assertSame($sequence===4 ? $before : [null,null],[$this->savedPair($c['actor_id'],$b['actor_id'],1,$selector),$this->savedPair($b['actor_id'],$c['actor_id'],1,$selector)]);
        }
    }

    private function publishedNpcPair(bool $scene,bool $backlog=false): array {
        // Consume the independent setup exchange before asserting the new exact source identity.
        $baselineCalls=0;$this->assertNotNull(synth_relationship_dispatch($this->database,function() use(&$baselineCalls): string {
            ++$baselineCalls;return '{"changes":{}}';
        },true));$this->assertSame(1,$baselineCalls);
        $npc=$this->secondaryNpc([1,2,3]);$older=null;
        if ($backlog) {
            $parent=$this->parent;$parent['request_id']='older-addressed-root';$this->request($parent,true);
            $input=['type'=>'inputtext','data'=>'Nora: Earlier public greeting.','people'=>'|Nora|Piper Wright|Preston Garvey|',
                'delivery_state'=>'received','gamets'=>1000,'localts'=>1000,'ts'=>1000,'rowid'=>930001];
            $this->query("INSERT INTO synth_meta.history_events(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
                VALUES('relationship-dispatch-session',1,1,$1,'relationship-dispatch-save',930001,$2::jsonb)",[$parent['request_id'],$this->json($input)]);
            $this->response($parent,$npc['identity']);$this->captureOwnedSpeech($parent,$this->context['target'],$npc['identity']['display_name']);
            $older=pg_fetch_assoc($this->query('SELECT history_event_id,response_payload FROM synth_meta.relationship_response_inputs WHERE request_id=$1',[$parent['request_id']]));
            $this->assertNotFalse($older);
            $this->query('UPDATE synth_meta.request_executions SET completed_at=clock_timestamp() WHERE request_id=$1',[$parent['request_id']]);
            $this->query('UPDATE synth_meta.context_snapshots SET history_event_id=$1 WHERE snapshot_sequence=2',[$older['history_event_id']]);
        }
        [$owner,$chain]=$this->continuation('npc',true,null,null,$scene);
        $this->query('UPDATE synth_meta.runtime_sessions SET capabilities=$1::jsonb',[$this->json($owner['capabilities'])]);
        $policy=['mode'=>'conversational','private'=>false,'rounds'=>3,'probability'=>100,'allow_actions'=>false,
            'random_narration'=>false,'narration_chance'=>0,'narration_cooldown'=>0,
            'source_profile_id'=>$this->profileId,'source_actor_identity_id'=>$this->actorId];
        $this->query('UPDATE synth_meta.rechat_chains SET policy=$1::jsonb WHERE id=$2',[$this->json($policy),$chain]);
        $this->response($owner,$npc['identity']);
        $bytes=hex2bin(pg_fetch_assoc($this->query("SELECT encode(response_body,'hex') AS body FROM synth_meta.request_executions WHERE request_id=$1",
            [$owner['request_id']]))['body']);
        $this->query("UPDATE synth_meta.request_executions SET state='claimed',completed_at=NULL WHERE request_id=$1",[$owner['request_id']]);
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$owner,'request_id'=>$owner['request_id'],'runtime_variant'=>$owner['runtime_variant']];
        synth_protocol_complete_request($this->database,'complete','application/x-ndjson',$bytes);
        $this->captureOwnedSpeech($owner,$this->context['target'],$npc['identity']['display_name']);
        $fresh=pg_fetch_assoc($this->query('SELECT history_event_id,response_payload FROM synth_meta.relationship_response_inputs WHERE request_id=$1',[$owner['request_id']]));
        $this->assertNotFalse($fresh);$this->query("UPDATE synth_meta.runtime_sessions SET interaction_dispatch_after=clock_timestamp()-INTERVAL '1 second'");
        $calls=0;$result=synth_relationship_dispatch_interaction($this->database,function(array $input) use(&$calls,$owner,$fresh,$npc,$scene): string {
            ++$calls;$this->assertTrue($this->database->isTransactionIdle());
            $this->assertSame(synth_protocol_canonical_json($owner),synth_protocol_canonical_json($input['owner']));
            $this->assertSame(synth_protocol_canonical_json($owner),synth_protocol_canonical_json($input['response']['request']));
            $this->assertSame((int)$fresh['history_event_id'],$input['interaction']['response_id']);
            $this->assertSame($scene ? 2 : 1,$input['context']['snapshot_sequence']);
            $this->assertSame($this->actorId,$input['interaction']['actor_ids']['speaker']);
            $this->assertSame($npc['actor_id'],$input['interaction']['actor_ids']['listener']);
            return '{"speaker":{"delta":3,"reason":"Synthetic encouragement"},"listener":{"delta":-2,"reason":"Synthetic caution"}}';
        });$this->assertNotNull($result);$this->assertSame(1,$calls);
        $row=pg_fetch_assoc($this->query('SELECT result.history_event_id AS result,result.job_id,evaluation.id AS evaluation,
                evaluation.response_history_event_id AS response,job.lease_token,job.state,
                evaluation.input_sha256=history.event_payload->>\'evaluation_sha256\' AS input_bound,
                encode(sha256(convert_to(result.result_payload::text,\'UTF8\')),\'hex\')=history.event_payload->>\'result_sha256\' AS result_bound
            FROM synth_meta.relationship_interaction_results result JOIN synth_meta.relationship_interaction_evaluations evaluation ON evaluation.id=result.interaction_id
            JOIN synth_meta.worker_jobs job ON job.id=result.job_id JOIN synth_meta.history_events history ON history.id=result.history_event_id
            WHERE evaluation.request_id=$1',[$owner['request_id']]));
        $this->assertNotFalse($row);$this->assertSame($fresh['history_event_id'],$row['response']);
        $this->assertSame('ready',$row['state']);$this->assertSame('t',$row['input_bound']);$this->assertSame('t',$row['result_bound']);
        $revisions=pg_fetch_all($this->query('SELECT history_event_id,actor_identity_id,subject_identity_id,affinity FROM synth_meta.relationship_revisions
            WHERE interaction_result_history_event_id=$1 ORDER BY actor_identity_id',[$row['result']]));
        $this->assertCount(2,$revisions);$frontier=max(array_map('intval',array_column($revisions,'history_event_id')));
        $this->query("UPDATE synth_meta.context_snapshots SET history_event_id=$1 WHERE session_id='relationship-dispatch-session' AND snapshot_sequence=3",[$frontier]);
        return ['owner'=>$owner,'row'=>$row,'npc'=>$npc,'chain'=>$chain,'frontier'=>$frontier,'older'=>$older];
    }
    public static function acceptedNpcPairs(): array {
        $cases=[];foreach ([false,true] as $scene) foreach ([false,true] as $turn)
            $cases[($scene ? 'scene' : 'no scene').' '.($turn ? 'turn cancellation' : 'request cancellation')]=[$scene,$turn];return $cases;
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('acceptedNpcPairs')]
    public function testAcceptedNpcRechatPairRetainsBothSavedDirections(bool $scene,bool $turn): void {
        ['owner'=>$owner,'row'=>$row,'npc'=>$npc]=$this->publishedNpcPair($scene);
        $before=[$this->savedPair($this->actorId,$npc['actor_id']),$this->savedPair($npc['actor_id'],$this->actorId)];
        $this->assertNotNull($before[0],'An accepted scene pair must be readable before cancellation.');$this->assertNotNull($before[1]);
        $this->assertSame('3',$before[0]['affinity']);$this->assertSame('-2',$before[1]['affinity']);
        $this->assertSame($row['result'],$before[0]['interaction_result_history_event_id']);
        foreach ([[$this->actorId,$npc['actor_id']],[$npc['actor_id'],$this->actorId]] as [$reader,$subject])
            $this->assertNull($this->savedPair($reader,$subject,2),'A saved frontier before publication excludes either direction.');
        $this->cancelRetainedOwner($owner,$turn);$this->admitRetainedNextTurn($owner);
        $retired=pg_fetch_assoc($this->query('SELECT state,error_code,response_body IS NULL AS cleared FROM synth_meta.request_executions WHERE request_id=$1',[$owner['request_id']]));
        $this->assertSame(['state'=>'cancelled','error_code'=>$turn ? 'turn_cancelled' : 'request_cancelled','cleared'=>'t'],$retired);
        $this->assertSame($before,[$this->savedPair($this->actorId,$npc['actor_id']),$this->savedPair($npc['actor_id'],$this->actorId)],
            'Already accepted paired directions survive normal cancellation at the same saved frontier.');
        $cte=synth_relationship_visible_cte($this->database,$this->savedSelector(),false,$this->actorId);
        $this->assertSame('0',$this->database->fetchOne($cte.'SELECT count(*) AS n FROM relationship_graph_responses WHERE history_event_id='.$row['response'])['n']);
        $this->assertSame('0',$this->database->fetchOne($cte.'SELECT count(*) AS n FROM relationship_graph_sources WHERE history_event_id='.$row['response'])['n']);
        $selector=$this->savedSelector(1);$selector['request_id']=$owner['request_id'];
        $this->assertNull($this->savedPair($this->actorId,$npc['actor_id'],1,$selector,true),'The current-response exception cannot admit a pair publication beyond the saved frontier.');
        $replay=synth_protocol_claim_request($this->database,$owner);$this->assertSame('replay',$replay['status']);$this->assertSame('',$replay['body']);
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$owner];
        try {synth_relationship_capture_interaction_evaluation($this->database,(int)$row['response'],0,'No late paired work.');
            $this->fail('Historical pair evidence cannot authorize late capture');
        } catch (SYNTHProtocolCancelled $error) {$this->assertSame('request_cancelled',$error->getMessage());}
        try {synth_relationship_complete_interaction($this->database,(int)$row['job_id'],$row['lease_token'],'{}');
            $this->fail('Historical pair evidence cannot authorize late publication');
        } catch (SYNTHProtocolCancelled $error) {$this->assertSame('request_cancelled',$error->getMessage());}
        $this->assertSame('ready',pg_fetch_assoc($this->query('SELECT state FROM synth_meta.worker_jobs WHERE id=$1',[$row['job_id']]))['state']);
        $this->assertSame('1',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.relationship_interaction_results'))['n']);
        $this->assertSame('2',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.relationship_revisions'))['n']);
    }
    public function testAcceptedNpcPairSurvivesLaterChainClosureAndProfileRemoval(): void {
        ['owner'=>$owner,'row'=>$row,'npc'=>$npc,'chain'=>$chain]=$this->publishedNpcPair(true);
        $before=[$this->savedPair($this->actorId,$npc['actor_id']),$this->savedPair($npc['actor_id'],$this->actorId)];
        $this->assertNotNull($before[0]);$this->assertNotNull($before[1]);
        $this->query("UPDATE synth_meta.rechat_chains SET terminal_reason='no_candidate' WHERE id=$1",[$chain]);
        $this->query('DELETE FROM synth.core_npc_master WHERE id=$1',[$this->npcId]);
        $this->cancelRetainedOwner($owner,true);$this->admitRetainedNextTurn($owner);
        $this->assertSame($before,[$this->savedPair($this->actorId,$npc['actor_id']),$this->savedPair($npc['actor_id'],$this->actorId)]);
        $this->assertSame('ready',pg_fetch_assoc($this->query('SELECT state FROM synth_meta.worker_jobs WHERE id=$1',[$row['job_id']]))['state']);
    }
    public function testCompleteOriginalNpcPairDoesNotRequireOptionalParentReceipt(): void {
        ['row'=>$row,'npc'=>$npc]=$this->publishedNpcPair(false);
        // The parent lookup has no optional receipt; existing FK-protected setup evidence is retained separately.
        $unrelated=$this->parent;$unrelated['request_id']='fixture-unrelated-parent';$unrelated['turn_id']='fixture-unrelated-turn';$this->request($unrelated);
        $this->query("UPDATE synth_meta.relationship_response_inputs SET request_id=$1 WHERE request_id='relationship-parent'",[$unrelated['request_id']]);
        $this->assertSame('0',pg_fetch_assoc($this->query("SELECT count(*) AS n FROM synth_meta.relationship_response_inputs WHERE request_id='relationship-parent'"))['n']);
        $this->assertSame('3',$this->savedPair($this->actorId,$npc['actor_id'])['affinity']);
        $this->assertSame('-2',$this->savedPair($npc['actor_id'],$this->actorId)['affinity']);
        $this->assertSame($row['result'],$this->savedPair($this->actorId,$npc['actor_id'])['interaction_result_history_event_id']);
    }
    private function replacePairFrozenInput(array $fixture,array $input,bool $replaceOwner=false): void {
        $row=$fixture['row'];$owner=$input['owner'];
        if ($replaceOwner) {
            $canonical=synth_protocol_canonical_json($owner);$hash=hash('sha256',$canonical);
            $input['response']['request']=$owner;$input['response']['request_sha256']=$hash;
            $this->query('UPDATE synth_meta.protocol_events SET canonical_event=$1::jsonb,event_sha256=$2 WHERE request_id=$3',[$canonical,$hash,$fixture['owner']['request_id']]);
            $this->query('UPDATE synth_meta.request_executions SET request_sha256=$1 WHERE request_id=$2',[$hash,$fixture['owner']['request_id']]);
            $this->query('UPDATE synth_meta.relationship_response_inputs SET response_payload=$1::jsonb WHERE history_event_id=$2',[$this->json($input['response']),$row['response']]);
            $this->query("UPDATE synth_meta.history_events history SET event_payload=jsonb_set(history.event_payload,'{response_payload_sha256}',
                to_jsonb(encode(sha256(convert_to(receipt.response_payload::text,'UTF8')),'hex')))
                FROM synth_meta.relationship_response_inputs receipt WHERE history.id=receipt.history_event_id AND history.id=$1",[$row['response']]);
        }
        $this->query("UPDATE synth_meta.relationship_interaction_evaluations SET input_payload=$1::jsonb,
            input_sha256=encode(sha256(convert_to(($1::jsonb)::text,'UTF8')),'hex') WHERE id=$2",[$this->json($input),$row['evaluation']]);
        $this->query("UPDATE synth_meta.history_events history SET event_payload=jsonb_set(history.event_payload,'{evaluation_sha256}',to_jsonb(evaluation.input_sha256))
            FROM synth_meta.relationship_interaction_evaluations evaluation WHERE evaluation.id=$1 AND history.id=$2",[$row['evaluation'],$row['result']]);
    }
    public static function invalidSavedPairEvidence(): array {
        return array_combine([
            'receipt digest','receipt payload','frozen input','result digest','unready job','unprepared selection','narrator selection',
            'private policy','selection actor','parent receipt actor','parent receipt digest','parent frozen source','parent canonical request','parent route actor',
            'automatic activate parent',
            'unsupported cancellation','incomplete owner','retained wrong body','wrong runtime lane',
            'string scene sequence','oversized scene sequence','missing scene capability','scene timing','scene cell',
            'scene player','scene target','scene playthrough','scene audience','scalar scene audience','missing effective context',
        ],array_map(static fn(string $case): array=>[$case],[
            'receipt digest','receipt payload','frozen input','result digest','unready job','unprepared selection','narrator selection',
            'private policy','selection actor','parent receipt actor','parent receipt digest','parent frozen source','parent canonical request','parent route actor',
            'automatic activate parent',
            'unsupported cancellation','incomplete owner','retained wrong body','wrong runtime lane',
            'string scene sequence','oversized scene sequence','missing scene capability','scene timing','scene cell',
            'scene player','scene target','scene playthrough','scene audience','scalar scene audience','missing effective context',
        ]));
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSavedPairEvidence')]
    public function testAcceptedNpcPairRejectsAlteredHistoricalProof(string $case): void {
        $fixture=$this->publishedNpcPair(true);['owner'=>$owner,'row'=>$row,'npc'=>$npc,'chain'=>$chain]=$fixture;
        $this->assertNotNull($this->savedPair($this->actorId,$npc['actor_id']));
        $this->assertNotNull($this->savedPair($npc['actor_id'],$this->actorId));
        $this->cancelRetainedOwner($owner,true);$this->admitRetainedNextTurn($owner);
        $input=json_decode(pg_fetch_assoc($this->query('SELECT input_payload FROM synth_meta.relationship_interaction_evaluations WHERE id=$1',[$row['evaluation']]))['input_payload'],true,flags:JSON_THROW_ON_ERROR);
        switch ($case) {
            case 'receipt digest':$this->query("UPDATE synth_meta.relationship_response_inputs SET response_sha256=repeat('0',64) WHERE history_event_id=$1",[$row['response']]);break;
            case 'receipt payload':$this->query("UPDATE synth_meta.relationship_response_inputs SET response_payload=jsonb_set(response_payload,'{entries,0,text}','\"Changed\"') WHERE history_event_id=$1",[$row['response']]);break;
            case 'frozen input':$this->query("UPDATE synth_meta.relationship_interaction_evaluations SET input_payload=jsonb_set(input_payload,'{context,snapshot_sequence}','1') WHERE id=$1",[$row['evaluation']]);break;
            case 'result digest':$this->query("UPDATE synth_meta.relationship_interaction_results SET result_payload=jsonb_set(result_payload,'{changes,0,state,aff}','99') WHERE history_event_id=$1",[$row['result']]);break;
            case 'unready job':$this->query("UPDATE synth_meta.worker_jobs SET state='cancelled' WHERE id=$1",[$row['job_id']]);break;
            case 'unprepared selection':$this->query('UPDATE synth_meta.rechat_selections SET prepared_at=NULL WHERE request_id=$1',[$owner['request_id']]);break;
            case 'narrator selection':$this->query("UPDATE synth_meta.rechat_selections SET role='narrator',actor_identity_id=NULL,npc_id=NULL,identity=NULL WHERE request_id=$1",[$owner['request_id']]);break;
            case 'private policy':$this->query("UPDATE synth_meta.rechat_chains SET policy=jsonb_set(policy,'{private}','true') WHERE id=$1",[$chain]);break;
            case 'selection actor':$this->query('UPDATE synth_meta.rechat_selections SET actor_identity_id=$1,identity=$2::jsonb WHERE request_id=$3',[$npc['actor_id'],$this->json($npc['identity']),$owner['request_id']]);break;
            case 'parent receipt actor':$this->query("UPDATE synth_meta.relationship_response_inputs SET actor_identity_id=$1 WHERE request_id='relationship-parent'",[$npc['actor_id']]);break;
            case 'parent receipt digest':$this->query("UPDATE synth_meta.relationship_response_inputs SET response_sha256=repeat('0',64) WHERE request_id='relationship-parent'");break;
            case 'parent frozen source':$this->query("UPDATE synth_meta.relationship_response_inputs SET response_payload=jsonb_set(response_payload,'{input_events,0,event,data}','\"Other input\"') WHERE request_id='relationship-parent'");
                $this->query("UPDATE synth_meta.history_events history SET event_payload=jsonb_set(history.event_payload,'{response_payload_sha256}',
                    to_jsonb(encode(sha256(convert_to(receipt.response_payload::text,'UTF8')),'hex'))) FROM synth_meta.relationship_response_inputs receipt
                    WHERE history.id=receipt.history_event_id AND receipt.request_id='relationship-parent'");break;
            case 'parent canonical request':$this->query("UPDATE synth_meta.protocol_events SET canonical_event=jsonb_set(canonical_event,'{context_sequence}','2') WHERE request_id='relationship-parent'");break;
            case 'parent route actor':$this->query("UPDATE synth_meta.response_routes SET speaker=$1::jsonb WHERE request_id='relationship-parent'",[$this->json($npc['identity'])]);break;
            case 'automatic activate parent':
                $parent=$this->parent;$parent['type']='activate';$parent['payload']=['source'=>'automatic'];
                $canonical=synth_protocol_canonical_json($parent);$hash=hash('sha256',$canonical);
                $receipt=json_decode(pg_fetch_assoc($this->query("SELECT response_payload FROM synth_meta.relationship_response_inputs WHERE request_id='relationship-parent'"))['response_payload'],true,flags:JSON_THROW_ON_ERROR);
                $receipt['request']=$parent;$receipt['request_sha256']=$hash;$receipt['input_events']=[];
                $this->query("UPDATE synth_meta.protocol_events SET canonical_event=$1::jsonb,event_type='activate',event_sha256=$2 WHERE request_id='relationship-parent'",[$canonical,$hash]);
                $this->query("UPDATE synth_meta.request_executions SET event_type='activate',request_sha256=$1 WHERE request_id='relationship-parent'",[$hash]);
                $this->query("UPDATE synth_meta.relationship_response_inputs SET response_payload=$1::jsonb WHERE request_id='relationship-parent'",[$this->json($receipt)]);
                $this->query("DELETE FROM synth_meta.relationship_response_sources WHERE response_history_event_id IN (SELECT history_event_id FROM synth_meta.relationship_response_inputs WHERE request_id='relationship-parent')");
                $this->query("UPDATE synth_meta.history_events history SET event_payload=jsonb_set(history.event_payload,'{response_payload_sha256}',
                    to_jsonb(encode(sha256(convert_to(receipt.response_payload::text,'UTF8')),'hex'))) FROM synth_meta.relationship_response_inputs receipt
                    WHERE history.id=receipt.history_event_id AND receipt.request_id='relationship-parent'");break;
            case 'unsupported cancellation':$this->query("UPDATE synth_meta.request_executions SET error_code='relationship_lease_lost' WHERE request_id=$1",[$owner['request_id']]);break;
            case 'incomplete owner':$this->query("UPDATE synth_meta.request_executions SET state='claimed',error_code=NULL WHERE request_id=$1",[$owner['request_id']]);break;
            case 'retained wrong body':$this->query("UPDATE synth_meta.request_executions SET response_body=decode('00','hex') WHERE request_id=$1",[$owner['request_id']]);break;
            case 'wrong runtime lane':$input['owner']['runtime_variant']='vr';$this->replacePairFrozenInput($fixture,$input,true);break;
            case 'string scene sequence':$input['owner']['payload']['scene_context_sequence']='2';$this->replacePairFrozenInput($fixture,$input,true);break;
            case 'oversized scene sequence':$input['owner']['payload']['scene_context_sequence']=PHP_INT_MAX;$this->replacePairFrozenInput($fixture,$input,true);break;
            case 'missing scene capability':$input['owner']['capabilities']=array_values(array_diff($input['owner']['capabilities'],['dialogue.rechat.scene']));$this->replacePairFrozenInput($fixture,$input,true);break;
            case 'scene timing':$this->query("UPDATE synth_meta.context_snapshots SET received_at=clock_timestamp()+INTERVAL '1 minute' WHERE snapshot_sequence=2");break;
            case 'missing effective context':$input['owner']['payload']['scene_context_sequence']=99;$this->replacePairFrozenInput($fixture,$input,true);break;
            default:
                $context=$input['context'];
                if ($case==='scene cell') $context['world']['scene']['cell']['form_id']='0x00018AA3';
                elseif ($case==='scene player') $context['player']['form_id']='0x00000015';
                elseif ($case==='scene target') $context['target']=$npc['identity'];
                elseif ($case==='scene playthrough') $context['playthrough_id']='other-saved-game';
                elseif ($case==='scene audience') $context['audience']=[];
                elseif ($case==='scalar scene audience') $context['audience']='unobserved';
                $input['context']=$context;$this->replacePairFrozenInput($fixture,$input);
                $this->query('UPDATE synth_meta.context_snapshots SET context_payload=$1::jsonb WHERE snapshot_sequence=2',[$this->json($context)]);break;
        }
        $this->assertNull($this->savedPair($this->actorId,$npc['actor_id']),'Corrupt historical proof cannot retain the speaker direction.');
        $this->assertNull($this->savedPair($npc['actor_id'],$this->actorId),'Corrupt historical proof cannot retain the listener direction.');
    }
    public function testAcceptedNpcPairRemainsVisibleThroughLoadedAncestryAfterSourceGenerationChanges(): void {
        ['owner'=>$owner,'npc'=>$npc,'frontier'=>$frontier]=$this->publishedNpcPair(true);
        $this->cancelRetainedOwner($owner,true);$this->admitRetainedNextTurn($owner);
        $before=[$this->savedPair($this->actorId,$npc['actor_id']),$this->savedPair($npc['actor_id'],$this->actorId)];
        $this->assertNotNull($before[0]);$this->assertNotNull($before[1]);
        $loaded=$this->context;$loaded['snapshot_sequence']=1;$loaded['audience'][]=$npc['identity'];
        $loaded['loaded_context']=['session_id'=>$owner['session_id'],'generation'=>1,'context_sequence'=>3,'playthrough_id'=>$this->context['playthrough_id']];
        $this->query("UPDATE synth_meta.runtime_sessions SET active_generation=2,state='expired' WHERE session_id=$1",[$owner['session_id']]);
        $this->query("INSERT INTO synth_meta.runtime_sessions(session_id,playthrough_id,game,runtime_variant,client_version,runtime_version,active_generation,capabilities,state)
            VALUES('relationship-restored-session','relationship-dispatch-save','fo4','flat','fixture','fixture',1,'[]','active')");
        $this->query("INSERT INTO synth_meta.context_snapshots(session_id,generation,snapshot_sequence,playthrough_id,player_identity_id,target_identity_id,audience_identity_ids,context_payload,history_event_id)
            VALUES('relationship-restored-session',1,1,'relationship-dispatch-save',$1,$2,$3::jsonb,$4::jsonb,$5)",
            [$this->playerId,$this->actorId,$this->json([$this->actorId,$npc['actor_id']]),$this->json($loaded),$frontier]);
        $selector=['protocol_version'=>2,'session_id'=>'relationship-restored-session','generation'=>1,'context_sequence'=>1,'runtime_variant'=>'flat'];
        $this->assertSame($before,[$this->savedPair($this->actorId,$npc['actor_id'],1,$selector),$this->savedPair($npc['actor_id'],$this->actorId,1,$selector)]);
        $wrong=$selector;$wrong['runtime_variant']='vr';$this->assertNull($this->savedPair($this->actorId,$npc['actor_id'],1,$wrong));
        $loaded['loaded_context']['playthrough_id']='other-saved-game';
        $this->query("UPDATE synth_meta.context_snapshots SET context_payload=$1::jsonb WHERE session_id='relationship-restored-session'",[$this->json($loaded)]);
        $this->assertNull($this->savedPair($this->actorId,$npc['actor_id'],1,$selector));$this->assertNull($this->savedPair($npc['actor_id'],$this->actorId,1,$selector));
    }
    public function testEarlyRelationshipSourceProjectionRetainsExactVisibilityAndSavedCutoffs(): void
    {
        $row=920000;
        foreach(['inputtext','inputtext_s','chat','backgroundchat','narration','world_context','infoaction'] as $type) {
            foreach(['received','emitted','derived'] as $delivery) {
                $payload=['type'=>$type,'data'=>'Piper Wright: Synthetic projection boundary.','people'=>'|Nora|Piper Wright|',
                    'delivery_state'=>$delivery,'gamets'=>1000,'localts'=>1000,'ts'=>1000,'rowid'=>++$row];
                $this->query("INSERT INTO synth_meta.history_events(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
                    VALUES('relationship-dispatch-session',1,2,'relationship-parent','relationship-dispatch-save',$1,$2::jsonb)",[$row,$this->json($payload)]);
            }
        }
        $this->query("UPDATE synth_meta.context_snapshots SET history_event_id=(SELECT max(id) FROM synth_meta.history_events)
            WHERE session_id='relationship-dispatch-session' AND snapshot_sequence=2");
        foreach([1,2] as $sequence) {
            $event=$this->parent;$event['context_sequence']=$sequence;
            $cte=synth_relationship_visible_cte($this->database,$event);
            $columns='history_event_id,reader_identity_id,type,data,people,source_location';
            $ordinary=$this->database->fetchAll($cte." SELECT $columns FROM relationship_graph_history
                WHERE type IN ('inputtext','inputtext_s','chat','backgroundchat','narration') AND delivery_state IN ('received','emitted')
                ORDER BY history_event_id,reader_identity_id");
            $early=$this->database->fetchAll($cte." SELECT $columns FROM relationship_graph_source_history ORDER BY history_event_id,reader_identity_id");
            $this->assertSame($ordinary,$early);
            if($sequence===1)$this->assertCount(1,$early);else $this->assertGreaterThan(1,count($early));
        }
        $this->query("UPDATE synth_meta.history_events SET event_payload=jsonb_set(event_payload,'{people}','\"|Uninformed|\"'::jsonb)
            WHERE eventlog_rowid>920000");
        $event=$this->parent;$event['context_sequence']=2;$cte=synth_relationship_visible_cte($this->database,$event);
        $this->assertSame('1',$this->database->fetchOne($cte.' SELECT count(*) AS n FROM relationship_graph_source_history')['n']);
    }

    public function testPairDispatchDoesNotAttachOlderSpeechToNewerOwner(): void {
        $fixture=$this->publishedNpcPair(true,true);
        $this->assertNotSame($fixture['older']['history_event_id'],$fixture['row']['response']);
        $this->assertNotNull($this->savedPair($this->actorId,$fixture['npc']['actor_id']));
        $this->assertNotNull($this->savedPair($fixture['npc']['actor_id'],$this->actorId));
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$fixture['owner']];
        $this->assertNull(synth_relationship_capture_interaction_evaluation($this->database,
            intval($fixture['older']['history_event_id']),0,'Synthetic exact-owner guard'));
    }

    private function acceptedDeferredPublicPairFixture(): array {
        $fixture=$this->publishedNpcPair(true,true);$row=$fixture['row'];$older=$fixture['older'];
        $input=json_decode(pg_fetch_assoc($this->query('SELECT input_payload FROM synth_meta.relationship_interaction_evaluations WHERE id=$1',[$row['evaluation']]))['input_payload'],true);
        $input['response']=json_decode($older['response_payload'],true);
        $input['interaction']['response_id']=intval($older['history_event_id']);
        $input['lines']=[['entry_index'=>0,'entry'=>$input['response']['entries'][0]]];
        $this->replacePairFrozenInput($fixture,$input);
        // Reconstruct the previously accepted legacy binding, without calling the repaired writer.
        $this->query('UPDATE synth_meta.relationship_interaction_evaluations SET response_history_event_id=$1 WHERE id=$2',[$older['history_event_id'],$row['evaluation']]);
        $this->query("UPDATE synth_meta.relationship_revisions SET input_payload=jsonb_set(input_payload,'{sources}',jsonb_build_array($1::bigint))
            WHERE interaction_result_history_event_id=$2",[$older['history_event_id'],$row['result']]);
        $this->query("UPDATE synth_meta.relationship_revisions SET input_hash=encode(sha256(convert_to(input_payload::text,'UTF8')),'hex')
            WHERE interaction_result_history_event_id=$1",[$row['result']]);
        $this->query("UPDATE synth_meta.history_events h SET event_payload=jsonb_set(h.event_payload,'{relationship_input_sha256}',to_jsonb(r.input_hash))
            FROM synth_meta.relationship_revisions r WHERE r.history_event_id=h.id AND r.interaction_result_history_event_id=$1",[$row['result']]);
        $this->query('UPDATE synth_meta.relationship_revision_sources d SET source_history_event_id=$1 FROM synth_meta.relationship_revisions r
            WHERE r.history_event_id=d.relationship_history_event_id AND r.interaction_result_history_event_id=$2',[$older['history_event_id'],$row['result']]);
        return $fixture;
    }
    public function testAcceptedDeferredPublicPairRetainsExactEvidence(): void {
        $fixture=$this->acceptedDeferredPublicPairFixture();$npc=$fixture['npc'];
        $before=[$this->savedPair($this->actorId,$npc['actor_id']),$this->savedPair($npc['actor_id'],$this->actorId)];
        $this->assertNotNull($before[0],'A ready historical public source needs independent evaluator and speech proof.');
        $this->assertNotNull($before[1]);
        $this->assertSame($fixture['row']['result'],$before[0]['interaction_result_history_event_id']);
        $this->assertNull($this->savedPair($this->actorId,$npc['actor_id'],2),'An earlier frontier cannot acquire the later paired result.');
        $this->assertNull($this->savedPair($npc['actor_id'],$this->actorId,2));
        $this->cancelRetainedOwner($fixture['owner'],true);$this->admitRetainedNextTurn($fixture['owner']);
        $after=[$this->savedPair($this->actorId,$npc['actor_id']),$this->savedPair($npc['actor_id'],$this->actorId)];
        $diagnostic=[];
        if ($after!==$before) {
            $cte=synth_relationship_visible_cte($this->database,$this->savedSelector(),false,$this->actorId);
            foreach (['interaction_rechat_response','interaction_rechat_prepared','interaction_rechat_parent','interaction_rechat_provenance','interaction_result_execution'] as $stage)
                $diagnostic[$stage]=$this->database->fetchOne($cte.' SELECT count(*) AS n FROM '.$stage)['n'];
            $diagnostic['source']=$this->database->fetchOne('SELECT state,error_code,response_body IS NULL AS cleared FROM synth_meta.request_executions WHERE request_id=\'older-addressed-root\'');
            $diagnostic['source_graph']=$this->database->fetchOne($cte.' SELECT count(*) AS n FROM relationship_graph_responses WHERE history_event_id='.intval($fixture['older']['history_event_id']))['n'];
        }
        $this->assertSame($before,$after,json_encode($diagnostic));
    }
    public static function deferredProofMutations(): array {
        return [['source digest'],['source payload'],['private owner'],['unready job']];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('deferredProofMutations')]
    public function testAcceptedDeferredPublicPairRejectsUnprovenEvidence(string $case): void {
        $fixture=$this->acceptedDeferredPublicPairFixture();$npc=$fixture['npc'];
        switch($case) {
            case 'source digest':$this->query("UPDATE synth_meta.relationship_response_inputs SET response_sha256=repeat('a',64) WHERE history_event_id=$1",[$fixture['older']['history_event_id']]);break;
            case 'source payload':$this->query("UPDATE synth_meta.relationship_response_inputs SET response_payload=jsonb_set(response_payload,'{entries,0,text}','\"altered\"'::jsonb) WHERE history_event_id=$1",[$fixture['older']['history_event_id']]);break;
            case 'private owner':$this->query("UPDATE synth_meta.rechat_chains SET policy=jsonb_set(policy,'{private}','true') WHERE id=$1",[$fixture['chain']]);break;
            case 'unready job':$this->query("UPDATE synth_meta.worker_jobs SET state='failed' WHERE id=$1",[$fixture['row']['job_id']]);break;
        }
        $this->assertNull($this->savedPair($this->actorId,$npc['actor_id']));
        $this->assertNull($this->savedPair($npc['actor_id'],$this->actorId));
    }
    public function testAcceptedDeferredPublicPairSurvivesLoadedAncestryAndRejectsForeignSave(): void {
        ['owner'=>$owner,'npc'=>$npc,'frontier'=>$frontier]=$this->acceptedDeferredPublicPairFixture();
        $this->cancelRetainedOwner($owner,true);$this->admitRetainedNextTurn($owner);
        $before=[$this->savedPair($this->actorId,$npc['actor_id']),$this->savedPair($npc['actor_id'],$this->actorId)];
        $this->assertNotNull($before[0]);$this->assertNotNull($before[1]);
        $loaded=$this->context;$loaded['snapshot_sequence']=1;$loaded['audience'][]=$npc['identity'];
        $loaded['loaded_context']=['session_id'=>$owner['session_id'],'generation'=>1,'context_sequence'=>3,'playthrough_id'=>$this->context['playthrough_id']];
        $this->query("UPDATE synth_meta.runtime_sessions SET active_generation=2,state='expired' WHERE session_id=$1",[$owner['session_id']]);
        $this->query("INSERT INTO synth_meta.runtime_sessions(session_id,playthrough_id,game,runtime_variant,client_version,runtime_version,active_generation,capabilities,state)
            VALUES('relationship-restored-session','relationship-dispatch-save','fo4','flat','fixture','fixture',1,'[]','active')");
        $this->query("INSERT INTO synth_meta.context_snapshots(session_id,generation,snapshot_sequence,playthrough_id,player_identity_id,target_identity_id,audience_identity_ids,context_payload,history_event_id)
            VALUES('relationship-restored-session',1,1,'relationship-dispatch-save',$1,$2,$3::jsonb,$4::jsonb,$5)",
            [$this->playerId,$this->actorId,$this->json([$this->actorId,$npc['actor_id']]),$this->json($loaded),$frontier]);
        $selector=['protocol_version'=>2,'session_id'=>'relationship-restored-session','generation'=>1,'context_sequence'=>1,'runtime_variant'=>'flat'];
        $this->assertSame($before,[$this->savedPair($this->actorId,$npc['actor_id'],1,$selector),$this->savedPair($npc['actor_id'],$this->actorId,1,$selector)]);
        $wrong=$selector;$wrong['runtime_variant']='vr';$this->assertNull($this->savedPair($this->actorId,$npc['actor_id'],1,$wrong));
        $loaded['loaded_context']['playthrough_id']='other-saved-game';
        $this->query("UPDATE synth_meta.context_snapshots SET context_payload=$1::jsonb WHERE session_id='relationship-restored-session'",[$this->json($loaded)]);
        $this->assertNull($this->savedPair($this->actorId,$npc['actor_id'],1,$selector));$this->assertNull($this->savedPair($npc['actor_id'],$this->actorId,1,$selector));
    }

}
