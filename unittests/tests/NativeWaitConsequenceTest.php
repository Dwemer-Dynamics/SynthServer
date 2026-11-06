<?php declare(strict_types=1);
require_once __DIR__.'/DatabaseTestCase.php';
require_once dirname(__DIR__,2).'/lib/request.php';
require_once dirname(__DIR__,2).'/lib/synth_wait_actions.php';
require_once dirname(__DIR__,2).'/lib/synth_memory_recall.php';
require_once dirname(__DIR__,2).'/lib/chat_helper_functions.php';
require_once dirname(__DIR__,2).'/lib/data_functions.php';
final class WaitConsequenceFixtureDatabase
{
    public int $locks=0;
    private ?array $lockOwner=null;
    public function __construct(public $connection) {}
    public function escape(string $value): string {return pg_escape_string($this->connection,$value);}
    public function isTransactionIdle(): bool {return pg_transaction_status($this->connection)===PGSQL_TRANSACTION_IDLE;}
    public function escapeLiteral(string $value): string {return pg_escape_literal($this->connection,$value);}
    public function execQuery(string $sql,array $params=[])
    {
        $result=$params ? pg_query_params($this->connection,$sql,$params) : pg_query($this->connection,$sql);
        if (!$result) throw new RuntimeException('Quest fixture query failed');
        return $result;
    }
    public function fetchOne(string $sql): ?array {return pg_fetch_assoc($this->execQuery($sql)) ?: null;}
    public function fetchAll(string $sql): array {return pg_fetch_all($this->execQuery($sql)) ?: [];}
    public function withNativeRequestLock(callable $operation,int $statementTimeoutMs=0)
    {
        $event=$GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? [];
        if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']) || ($event['protocol_version'] ?? 1)!==2)
            throw new LogicException('Fixture needs claimed native request');
        // Prepared rechat readers nest the same captured owner lock; retain the outer transaction.
        if ($this->lockOwner!==null) {
            if ($this->lockOwner!==$event) throw new LogicException('Fixture nested owner changed');
            synth_protocol_assert_request_active();
            $this->locks++;
            return $operation($this->connection,$event);
        }
        $this->execQuery('BEGIN ISOLATION LEVEL READ COMMITTED');
        try {
            $this->execQuery("SET LOCAL lock_timeout='2s'");
            $this->execQuery("SELECT set_config('statement_timeout',$1,true)",[(string)max(1,min(2000,$statementTimeoutMs ?: 2000))]);
            $this->execQuery('SELECT pg_advisory_xact_lock(hashtext($1))',[$event['session_id']]);
            $result=$this->execQuery("SELECT 1 FROM synth_meta.request_executions e JOIN synth_meta.runtime_sessions r USING(session_id)
                WHERE e.session_id=$1 AND e.request_id=$2 AND e.generation=$3 AND e.turn_id=$4
                  AND e.state IN ('claimed','streaming','complete') AND r.state='active' AND r.active_generation=e.generation
                  AND r.active_turn_id=e.turn_id AND r.active_turn_sequence=$5",
                [$event['session_id'],$event['request_id'],$event['generation'],$event['turn_id'],$event['context_sequence']]);
            if (!pg_fetch_assoc($result)) throw new SYNTHProtocolCancelled('Fixture owner inactive');
            // Match the production PostgreSQL lock's post-lock cancellation-error check.
            synth_protocol_assert_request_active();
            $this->locks++;
            $this->lockOwner=$event;
            $value=$operation($this->connection,$event);
            $this->execQuery('COMMIT');
            return $value;
        } finally {
            $this->lockOwner=null;
            if (pg_transaction_status($this->connection)!==PGSQL_TRANSACTION_IDLE) pg_query($this->connection,'ROLLBACK');
        }
    }
    public function insert($table,array $data): bool {
        if(!in_array($table,['eventlog','synth.eventlog'],true))throw new LogicException('Fixture insert outside history');
        return $this->withNativeRequestLock(function($connection,array $event) use($data) {
            unset($data['rowid']);$data['synth_session_id']=$event['session_id'];$data['synth_generation']=$event['generation'];$data['synth_context_sequence']=$event['context_sequence'];$data['synth_request_id']=$event['request_id'];
            $context=synth_protocol_current_context($this,$event);$columns=array_keys($data);foreach($columns as $column)if(!preg_match('/^[a-z_]+$/D',$column))throw new LogicException('Fixture column');
            $values=implode(',',array_map(static fn($i)=>'$'.($i+1),array_keys($columns)));
            $sql='WITH publication AS (INSERT INTO synth.eventlog('.implode(',',$columns).') VALUES('.$values.') RETURNING *) INSERT INTO synth_meta.history_events(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload) SELECT synth_session_id,synth_generation,synth_context_sequence,synth_request_id,$'.(count($columns)+1).',rowid,to_jsonb(publication) FROM publication';
            $this->execQuery($sql,[...array_values($data),$context['player']['playthrough_id']]);return true;
        });
    }
}



final class NativeWaitConsequenceTest extends DatabaseTestCase {
    private WaitConsequenceFixtureDatabase $fixtureDb;
    private array $player,$actor,$other,$payload,$event,$saved=[];
    private int $serial=0;
    public function setUp(): void {
        parent::setUp();foreach(['db','SYNTH_REQUEST_EVENT','SYNTH_PROTOCOL_REQUEST_CLAIMED','SYNTH_NAME','PLAYER_NAME','gameRequest','CLEAN_CONTEXT_FOCUS_CHAT','PROMPT_TIMESTAMP','EVENT_TYPE_FILTER','FEATURES'] as $key)$this->saved[$key]=[array_key_exists($key,$GLOBALS),$GLOBALS[$key]??null];
        $connection=pg_connect('host=localhost dbname=testdb user=dwemer password=dwemer',PGSQL_CONNECT_FORCE_NEW);$this->assertNotFalse($connection);$this->fixtureDb=new WaitConsequenceFixtureDatabase($connection);$this->fixtureDb->execQuery('SET search_path TO synth,synth_meta,public');$GLOBALS['db']=$this->fixtureDb;$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=true;
        $this->player=$this->identity('0x00000014','Nora');$this->actor=$this->identity('0x00000015','Settler');$this->other=$this->identity('0x00000016','Settler');
        $this->payload=['playthrough_id'=>'wait-recall-save','player'=>$this->player,'target'=>$this->actor,'audience'=>[$this->actor,$this->other],
            'world'=>['location'=>'Synthetic Sanctuary','cell'=>'Workshop','worldspace'=>'Commonwealth','weather'=>'Clear','interior'=>false,'game_time_ticks'=>1000],
            'actor_states'=>[],'active_quests'=>[],'nearby_items'=>[],'points_of_interest'=>[]];
        $this->session('wait-recall',1);$this->snapshot('wait-recall',1,1,0);$this->select('wait-recall',1,1,'input_text',['text'=>'Wait here.']);
        $GLOBALS['PLAYER_NAME']='Nora';$GLOBALS['CLEAN_CONTEXT_FOCUS_CHAT']=false;$GLOBALS['PROMPT_TIMESTAMP']='none';$GLOBALS['EVENT_TYPE_FILTER']='';$GLOBALS['FEATURES']['MEMORY_EMBEDDING']['USE_TEXT2VEC']=false;
    }
    public function tearDown(): void {if(isset($this->fixtureDb))pg_close($this->fixtureDb->connection);unset($GLOBALS['db']);parent::tearDown();foreach($this->saved as $key=>[$exists,$value]){if($exists)$GLOBALS[$key]=$value;else unset($GLOBALS[$key]);}}
    private function json($value): string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
    private function identity($form,$name): array {$identity=['form_id'=>$form,'origin_plugin'=>'Fallout4.esm','playthrough_id'=>'wait-recall-save','display_name'=>$name];synth_protocol_actor_identity_id($this->fixtureDb,$identity);return $identity;}
    private function caps(): array{return ['dialogue.turn_ownership','context.saved_anchor','action.wait_here','action.release_wait','action.wait_verified'];}
    private function session($session,$generation): void {$this->fixtureDb->execQuery("INSERT INTO synth_meta.runtime_sessions(session_id,playthrough_id,game,runtime_variant,client_version,runtime_version,active_generation,capabilities) VALUES($1,'wait-recall-save','fo4','flat','fixture','fixture',$2,$3::jsonb)",[$session,$generation,$this->json($this->caps())]);}
    private function snapshot($session,$generation,$sequence,$frontier,$target=null,$anchor=null,$clock=1000): void {
        $p=$this->payload;$p['target']=$target??$this->actor;$p['snapshot_sequence']=$sequence;$p['world']['game_time_ticks']=$clock;if($anchor)$p['loaded_context']=$anchor;
        $this->fixtureDb->execQuery('INSERT INTO synth_meta.context_snapshots(session_id,generation,snapshot_sequence,playthrough_id,player_identity_id,target_identity_id,audience_identity_ids,context_payload,history_event_id) VALUES($1,$2,$3,$4,$5,$6,$7::jsonb,$8::jsonb,$9)',[$session,$generation,$sequence,'wait-recall-save',synth_protocol_actor_identity_id($this->fixtureDb,$this->player),synth_protocol_actor_identity_id($this->fixtureDb,$p['target']),$this->json([synth_protocol_actor_identity_id($this->fixtureDb,$this->actor),synth_protocol_actor_identity_id($this->fixtureDb,$this->other)]),$this->json($p),$frontier]);
    }
    private function select($session,$generation,$sequence,$type,$payload,$turn=null,$caps=null): void {
        $request='wait-recall:'.(++$this->serial);$this->event=['schema'=>'synth.event.v2','protocol_version'=>2,'type'=>$type,'session_id'=>$session,'request_id'=>$request,'turn_id'=>$turn??'turn:'.$request,'generation'=>$generation,'context_sequence'=>$sequence,'game'=>'fo4','runtime_variant'=>'flat','client_version'=>'fixture','runtime_version'=>'fixture','capabilities'=>$caps??$this->caps(),'payload'=>$payload];
        $canonical=synth_protocol_canonical_json($this->event);$hash=hash('sha256',$canonical);
        $this->fixtureDb->execQuery("INSERT INTO synth_meta.request_executions(session_id,request_id,turn_id,generation,request_sha256,event_type,state) VALUES($1,$2,$3,$4,$5,$6,'claimed')",[$session,$request,$this->event['turn_id'],$generation,$hash,$type]);
        $this->fixtureDb->execQuery('INSERT INTO synth_meta.protocol_events(session_id,request_id,turn_id,generation,event_type,canonical_event,event_sha256) VALUES($1,$2,$3,$4,$5,$6::jsonb,$7)',[$session,$request,$this->event['turn_id'],$generation,$type,$canonical,$hash]);
        $this->fixtureDb->execQuery('UPDATE synth_meta.runtime_sessions SET active_turn_id=$2,active_turn_sequence=$3 WHERE session_id=$1',[$session,$this->event['turn_id'],$sequence]);
        $GLOBALS['SYNTH_REQUEST_EVENT']=synth_normalize_json_event($this->event);$GLOBALS['SYNTH_NAME']='Settler';$GLOBALS['gameRequest']=[$type,time(),1000,'Synthetic request'];
    }
    private function finish(): void {$this->fixtureDb->execQuery("UPDATE synth_meta.request_executions SET state='complete' WHERE session_id=$1 AND request_id=$2",[$this->event['session_id'],$this->event['request_id']]);}
    private function intent($name='wait_here'): array {
        $id='action:wait-recall:'.$this->serial;
        $intent=['schema'=>'synth.action.v1','action_id'=>$id,'idempotency_key'=>'idem:'.$id,'name'=>$name,'capability'=>'action.'.$name,'server_code'=>$name==='wait_here'?'WaitHere':'ReleaseWait','actor'=>$this->actor,'arguments'=>new stdClass(),'deadline_ms'=>30000];
        synth_protocol_record_action_intent($this->fixtureDb,$intent);$this->finish();return $intent;
    }
    private function terminalResult(array $intent,string $status='succeeded',?array $proof=null): array {
        if($proof===null)$proof=['schema'=>'synth.action.wait.result.v1','action_id'=>$intent['action_id'],'action'=>$intent['name'],'actor'=>$this->actor,'owned_hold'=>$intent['name']==='wait_here','restrained'=>$intent['name']==='wait_here','lease_ms'=>90000,'reload_policy'=>'release_owned_hold','owned_package'=>$intent['name']==='wait_here'?'0x01000900':'0x00000000','stationary'=>true];
        return ['schema'=>'synth.action.result.v1','action_id'=>$intent['action_id'],'idempotency_key'=>$intent['idempotency_key'],'status'=>$status,'detail'=>$status==='succeeded'?$this->json($proof):'An untrusted failure detail must not become a success template.'];
    }
    private function receipt(array $intent,array $result,$turn=null,$generation=1): void {$origin=$this->event;$this->select($origin['session_id'],$generation,$origin['context_sequence'],'action_result',['result'=>$result],$turn??$origin['turn_id'],$origin['capabilities']);
        $this->fixtureDb->execQuery('BEGIN');try{synth_protocol_persist_action_result($this->fixtureDb,$this->event,$this->event['session_id']);$this->fixtureDb->execQuery('COMMIT');}finally{if(!$this->fixtureDb->isTransactionIdle())$this->fixtureDb->execQuery('ROLLBACK');}}
    private function consequenceRows(): array {return $this->fixtureDb->fetchAll("SELECT id,event_payload FROM synth_meta.history_events WHERE event_payload ? 'synth_wait_consequence' ORDER BY id");}
    private function visible(): array{return $this->fixtureDb->fetchAll(synth_protocol_history_cte($this->fixtureDb,$this->event,'Settler')." SELECT history_event_id,data FROM native_history WHERE type='infoaction' ORDER BY history_event_id");}
    public function testWaitHistoryJoinsAreConditionalAndDoNotWidenQuestOrRelationshipProjection(): void {
        $ordinary=synth_protocol_history_rows_cte();
        $this->assertStringContainsString("CASE WHEN h.event_payload ? 'synth_wait_consequence' THEN EXISTS(SELECT 1 FROM synth_meta.action_intents wi",$ordinary);
        $this->assertStringContainsString("CASE WHEN original.type IN ('funcret','infoaction') THEN EXISTS(SELECT 1 FROM synth_meta.protocol_events raw_receipt",$ordinary);
        foreach([synth_protocol_history_rows_cte(readerFirstVisibility:true),synth_protocol_history_rows_cte(relationshipGraph:true,relationshipSourceHistory:true)] as $projection) {
            $this->assertStringNotContainsString('synth_wait_consequence',$projection);
            $this->assertStringNotContainsString('action_terminal_results',$projection);
        }
    }
    public function testObservedHoldDoesNotRequireGenericRestraintAndReleasePreservesForeignRestraint(): void {
        $wait=$this->intent();$result=$this->terminalResult($wait);$proof=json_decode($result['detail'],true);$proof['restrained']=false;$result['detail']=$this->json($proof);$this->receipt($wait,$result);$this->finish();
        $frontier=(int)$this->consequenceRows()[0]['id'];$this->snapshot('wait-recall',1,2,$frontier);$this->select('wait-recall',1,2,'input_text',['text'=>'Release your hold.']);
        $release=$this->intent('release_wait');$result=$this->terminalResult($release);$proof=json_decode($result['detail'],true);$proof['restrained']=true;$proof['stationary']=false;$result['detail']=$this->json($proof);$this->receipt($release,$result);$this->finish();
        $rows=$this->consequenceRows();$this->assertCount(2,$rows);$first=json_decode($rows[0]['event_payload'],true);$last=json_decode($rows[1]['event_payload'],true);
        $this->assertTrue($first['synth_wait_consequence']['verified']);$this->assertFalse($first['synth_wait_consequence']['proof']['restrained']);
        $this->assertTrue($last['synth_wait_consequence']['verified']);$this->assertStringContainsString('Another restraint was present and was not cleared',$last['data']);$this->assertStringNotContainsString('unrestrained',$last['data']);
        $this->snapshot('wait-recall',1,3,(int)$rows[1]['id']);$this->select('wait-recall',1,3,'input_text',['text'=>'What happened?']);$this->assertCount(2,$this->visible());
    }
    public function testObservedWaitAndReleaseReachExistingPromptMemoryAndOldNewSaveScopesWithoutNameLeak(): void {
        $wait=$this->intent();$this->receipt($wait,$this->terminalResult($wait));$this->finish();$first=$this->consequenceRows();$this->assertCount(1,$first);
        $data=json_decode($first[0]['event_payload'],true);$this->assertTrue($data['synth_wait_consequence']['verified']);$this->assertStringContainsString('was verified to enter a temporary SYNTH wait hold',$data['data']);$this->assertStringContainsString('Current waiting requires a fresh observation',$data['data']);
        $this->snapshot('wait-recall',1,2,(int)$first[0]['id']);$this->select('wait-recall',1,2,'input_text',['text'=>'You can stop waiting now.']);
        $release=$this->intent('release_wait');$this->receipt($release,$this->terminalResult($release));$this->finish();$rows=$this->consequenceRows();$this->assertCount(2,$rows);$frontier=(int)$rows[1]['id'];
        $this->snapshot('wait-recall',1,3,$frontier,null,null,800000);$this->select('wait-recall',1,3,'input_text',['text'=>'Do you remember waiting?']);
        $this->assertCount(2,$this->visible());$prompt=DataLastDataExpandedFor('Settler',-25);$text=$this->json($prompt);$this->assertStringContainsString('temporary SYNTH wait hold',$text);$this->assertStringContainsString('was verified to have no SYNTH-owned Wait package',$text);
        $batch=synth_memory_capture_batch($this->fixtureDb,1);$this->assertNotNull($batch);$job=synth_memory_enqueue($this->fixtureDb,$batch);$this->assertNotNull($job);
        $summary=synth_memory_process_job($this->fixtureDb,$job,static fn($input)=>'I entered a temporary SYNTH wait hold and was later released. This remembers verified past actions, not a current wait order.',null);$this->assertNotNull($summary);
        $recall=synth_memory_recall($this->fixtureDb,'Settler',['wait','released']);$this->assertNotEmpty($recall);
        $this->snapshot('wait-recall',1,4,$frontier,$this->other,null,800000);$this->select('wait-recall',1,4,'input_text',['text'=>'What do you remember?']);$this->assertSame([],$this->visible());$this->assertSame([],synth_memory_recall($this->fixtureDb,'Settler',['wait','released']));
        $this->session('wait-old-save',2);$anchor=['session_id'=>'wait-recall','generation'=>1,'context_sequence'=>1,'playthrough_id'=>'wait-recall-save'];$this->snapshot('wait-old-save',2,1,0,null,$anchor,800000);$this->select('wait-old-save',2,1,'input_text',['text'=>'Earlier save.']);$this->assertSame([],$this->visible());$this->assertSame([],synth_memory_recall($this->fixtureDb,'Settler',['wait','released']));
        $this->session('wait-new-save',2);$anchor['context_sequence']=3;$this->snapshot('wait-new-save',2,1,0,null,$anchor,800000);$this->select('wait-new-save',2,1,'input_text',['text'=>'Later save.']);$this->assertCount(2,$this->visible());$this->assertNotEmpty(synth_memory_recall($this->fixtureDb,'Settler',['wait','released']));
    }
    #[PHPUnit\Framework\Attributes\DataProvider('nonSuccess')]
    public function testFailureCancellationAndTimeoutNeverCreatePhysicalSuccess(string $status): void {$intent=$this->intent();$this->receipt($intent,$this->terminalResult($intent,$status));$rows=$this->consequenceRows();$this->assertCount(1,$rows);$data=json_decode($rows[0]['event_payload'],true);$this->assertFalse($data['synth_wait_consequence']['verified']);$this->assertStringContainsString('status '.$status,$data['data']);$this->assertStringNotContainsString('was verified to enter',$data['data']);$request=[];synth_protocol_bind_action_result($this->fixtureDb,$this->event,$request);$this->assertStringContainsString('No successful physical waiting or release was verified',json_decode($request[3],true)['result']);}
    public static function nonSuccess(): array{return [['failed'],['cancelled'],['timed_out'],['rejected'],['unavailable']];}
    public function testWrongActorMalformedProofAndWrongTurnCannotPublishOrCompleteIntent(): void {
        foreach(['wrong-actor','wrong-save','wrong-action','wrong-bool','wrong-package','wrong-lease','extra','malformed','wrong-turn'] as $case) {
            $this->select('wait-recall',1,1,'input_text',['text'=>'Wait here.']);$intent=$this->intent();$result=$this->terminalResult($intent);$proof=json_decode($result['detail'],true);
            if($case==='wrong-actor')$proof['actor']=$this->other;if($case==='wrong-save')$proof['actor']['playthrough_id']='foreign';if($case==='wrong-action')$proof['action']='release_wait';if($case==='wrong-bool')$proof['stationary']=false;if($case==='wrong-package')$proof['owned_package']='0x01000800';if($case==='wrong-lease')$proof['lease_ms']=90001;if($case==='extra')$proof['invented_permission']=true;$result['detail']=$case==='malformed'?'queued':$this->json($proof);
            try{$this->receipt($intent,$result,$case==='wrong-turn'?'foreign-turn':null);$this->fail('Invalid receipt accepted: '.$case);}catch(DomainException $error){$this->assertContains($error->getMessage(),['invalid_wait_observation','wait_result_owner_mismatch']);}
        }
        $this->assertSame([],$this->consequenceRows());$this->assertSame('0',$this->fixtureDb->fetchOne('SELECT count(*) AS n FROM synth_meta.action_terminal_results')['n']);
    }
    public function testUnverifiedLegacyQueuedSuccessRemainsReportOnlyAndDuplicateReceiptDoesNotDoubleRecord(): void {
        $this->select('wait-recall',1,1,'input_text',['text'=>'Wait here.'],null,array_values(array_diff($this->caps(),['action.wait_verified'])));$intent=$this->intent();$result=$this->terminalResult($intent);$result['detail']='wait command queued';$this->receipt($intent,$result);$this->finish();$this->receipt($intent,$result);$rows=$this->consequenceRows();$this->assertCount(1,$rows);$data=json_decode($rows[0]['event_payload'],true);$this->assertFalse($data['synth_wait_consequence']['verified']);$this->assertStringContainsString('No successful physical waiting or release was verified',$data['data']);
    }
    public function testLegacyNameScopedFuncretAndSuccessTemplateCannotLeakToOtherNearbyNpc(): void {
        $this->actor['display_name']='Codsworth';$this->payload['target']=$this->actor;$this->payload['audience']=[$this->actor,$this->other];
        $this->snapshot('wait-recall',1,2,0);$this->select('wait-recall',1,2,'input_text',['text'=>'Wait here.']);$intent=$this->intent();$this->receipt($intent,$this->terminalResult($intent));
        foreach(['funcret','infoaction'] as $type)$this->fixtureDb->insert('eventlog',['type'=>$type,'data'=>'Codsworth waits and stands at the place.','gamets'=>1000,'ts'=>1000,'localts'=>1000,'sess'=>'wait-recall-save','people'=>'|Settler|','location'=>'Synthetic Sanctuary','party'=>'','delivery_state'=>'emitted']);
        $this->finish();$frontier=(int)$this->fixtureDb->fetchOne('SELECT max(id) AS id FROM synth_meta.history_events')['id'];$this->snapshot('wait-recall',1,3,$frontier,$this->other,null,800000);$this->select('wait-recall',1,3,'input_text',['text'=>'What did Codsworth do?']);
        $this->assertSame([],$this->visible());$this->assertNull(synth_memory_capture_batch($this->fixtureDb,1));
    }
    public function testReboundHistoryOwnerCannotGrantEitherSameNamedNpcAConsequence(): void {
        $intent=$this->intent();$this->receipt($intent,$this->terminalResult($intent));$this->finish();$row=$this->consequenceRows()[0];$other=synth_protocol_actor_identity_id($this->fixtureDb,$this->other);
        $this->fixtureDb->execQuery("UPDATE synth_meta.history_events SET event_payload=jsonb_set(event_payload,'{synth_wait_consequence,actor_identity_id}',$2::jsonb) WHERE id=$1",[$row['id'],(string)$other]);
        $this->snapshot('wait-recall',1,2,(int)$row['id']);$this->select('wait-recall',1,2,'input_text',['text'=>'Did I wait?']);$this->assertSame([],$this->visible());
        $this->snapshot('wait-recall',1,3,(int)$row['id'],$this->other);$this->select('wait-recall',1,3,'input_text',['text'=>'Did I wait?']);$this->assertSame([],$this->visible());
    }
    public function testLabelsCannotRedirectProofAndStaleGenerationCannotPublish(): void {
        $intent=$this->intent();$result=$this->terminalResult($intent);$proof=json_decode($result['detail'],true);$proof['actor']['display_name']='Forged label';$result['detail']=$this->json($proof);$this->receipt($intent,$result);$history=json_decode($this->consequenceRows()[0]['event_payload'],true);$this->assertStringNotContainsString('Forged label',$history['data']);$this->assertSame('Settler',synth_wait_result_context($this->fixtureDb,$this->event)['actor']['display_name']);
        $this->fixtureDb->execQuery("UPDATE synth_meta.runtime_sessions SET active_generation=2 WHERE session_id='wait-recall'");$this->snapshot('wait-recall',2,1,0);try{$this->receipt($intent,$result,null,2);$this->fail('Stale intent accepted');}catch(DomainException $e){$this->assertSame('unknown_or_stale_action',$e->getMessage());}
    }
}
