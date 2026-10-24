<?php declare(strict_types=1);

require_once __DIR__.'/DatabaseTestCase.php';
require_once dirname(__DIR__,2).'/lib/synth_memory.php';
require_once dirname(__DIR__,2).'/lib/synth_memory_dispatch.php';

/** Captured disposable handle; production ownership assertions run inside its request lock. */
final class NativeMemoryRecoveryFixtureDatabase
{
    private ?array $lockOwner=null;
    public function __construct(private $connection) {}
    public function escapeLiteral(string $value): string {return pg_escape_literal($this->connection,$value);}
    public function execQuery(string $sql)
    {
        $result=pg_query($this->connection,$sql);
        if (!$result) throw new RuntimeException('Disposable memory adapter query failed');
        return $result;
    }
    public function fetchOne(string $sql): ?array {return pg_fetch_assoc($this->execQuery($sql)) ?: null;}
    public function fetchAll(string $sql): array {return pg_fetch_all($this->execQuery($sql)) ?: [];}
    public function isTransactionIdle(): bool {return pg_transaction_status($this->connection)===PGSQL_TRANSACTION_IDLE;}
    public function withNativeRequestLock(callable $operation,int $statementTimeoutMs=0)
    {
        $event=$GLOBALS['SYNTH_REQUEST_EVENT']['json'] ?? [];
        if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']) || ($event['protocol_version'] ?? 1)!==2)
            throw new LogicException('Fixture needs claimed native request');
        if ($this->lockOwner!==null) {
            if ($this->lockOwner!==$event) throw new LogicException('Fixture nested owner changed');
            synth_protocol_assert_request_active();
            return $operation($this->connection,$event);
        }
        $this->execQuery('BEGIN ISOLATION LEVEL READ COMMITTED');
        try {
            $this->execQuery("SET LOCAL lock_timeout='2s'");
            $result=pg_query_params($this->connection,"SELECT set_config('statement_timeout',$1,true)",[(string)max(1,min(2000,$statementTimeoutMs ?: 2000))]);
            if (!$result || !pg_query_params($this->connection,'SELECT pg_advisory_xact_lock(hashtext($1))',[$event['session_id']]))
                throw new RuntimeException('Disposable memory adapter lock failed');
            synth_protocol_assert_request_active();
            $this->lockOwner=$event;
            $value=$operation($this->connection,$event);
            $this->execQuery('COMMIT');
            return $value;
        } finally {
            $this->lockOwner=null;
            if (pg_transaction_status($this->connection)!==PGSQL_TRANSACTION_IDLE) pg_query($this->connection,'ROLLBACK');
        }
    }
}

/** Recovery fixtures use only DatabaseTestCase's disposable PostgreSQL database. */
final class NativeMemoryRecoveryTest extends DatabaseTestCase
{
    private $connection;
    private NativeMemoryRecoveryFixtureDatabase $database;
    private array $savedGlobals=[];
    private array $event=[];
    private int $actorId;
    private int $batchId;
    private int $jobId;
    private int $historyId;
    private int $nextHistoryRowid=900002;

    public function setUp(): void
    {
        parent::setUp();
        foreach (['db','SYNTH_REQUEST_EVENT','SYNTH_PROTOCOL_REQUEST_CLAIMED','SYNTH_MEMORY_JOB_LEASE','FEATURES'] as $key)
            $this->savedGlobals[$key]=[array_key_exists($key,$GLOBALS),$GLOBALS[$key] ?? null];
        $this->connection=pg_connect('host=localhost dbname=testdb user=dwemer password=dwemer',PGSQL_CONNECT_FORCE_NEW);
        $this->assertNotFalse($this->connection);
        $this->query('SET search_path TO synth,synth_meta,public');
        $this->database=new NativeMemoryRecoveryFixtureDatabase($this->connection);
        $GLOBALS['db']=$this->database;
        $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=true;
        unset($GLOBALS['SYNTH_MEMORY_JOB_LEASE']);
        $player=['form_id'=>'0x00000014','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'memory-recovery-save','display_name'=>'Nora'];
        $actor=['form_id'=>'0x00002F1F','origin_plugin'=>'Fallout4.esm','playthrough_id'=>'memory-recovery-save','display_name'=>'Piper Wright'];
        $playerId=$this->identity($player);
        $this->actorId=$this->identity($actor);
        $this->query("INSERT INTO synth_meta.runtime_sessions(session_id,playthrough_id,game,runtime_variant,client_version,runtime_version,active_generation,capabilities,state)
            VALUES('memory-recovery-session','memory-recovery-save','fo4','flat','fixture','fixture',1,'[]','active')");
        $payload=['playthrough_id'=>'memory-recovery-save','snapshot_sequence'=>1,'player'=>$player,'target'=>$actor,
            'audience'=>[$actor],'audience_observation'=>'complete','world'=>['location'=>'Diamond City','game_time_ticks'=>1000]];
        $this->snapshot(1,$playerId,$payload,0);
        $source=$this->owner(1,'memory-recovery-source');
        $this->persistRequest($source);
        $record=['type'=>'inputtext','data'=>'Nora: I helped Piper in Diamond City.','sess'=>'memory-recovery-save',
            'people'=>'|Nora|Piper Wright|','location'=>'Diamond City','party'=>'','gamets'=>1000,'localts'=>1000,'ts'=>1000,
            'rowid'=>900001,'delivery_state'=>'spoken'];
        $result=$this->query("INSERT INTO synth_meta.history_events(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
            VALUES('memory-recovery-session',1,1,$1,'memory-recovery-save',900001,$2::jsonb) RETURNING id",[$source['request_id'],$this->json($record)]);
        $this->historyId=(int)pg_fetch_assoc($result)['id'];
        $payload['snapshot_sequence']=2;
        $payload['world']['game_time_ticks']=1000000;
        $this->snapshot(2,$playerId,$payload,$this->historyId);
        $this->event=$this->owner(2,'memory-recovery-owner');
        $this->persistRequest($this->event);
        $this->query('UPDATE synth_meta.runtime_sessions SET active_turn_id=$1,active_turn_sequence=2',[$this->event['turn_id']]);
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$this->event];
        $batch=synth_memory_capture_batch($this->database,1,1000000);
        $this->assertNotNull($batch);
        $this->batchId=$batch;
        $job=synth_memory_enqueue($this->database,$this->batchId);
        $this->assertNotNull($job);
        $this->jobId=$job;
    }

    public function tearDown(): void
    {
        if (isset($this->connection)) pg_close($this->connection);
        parent::tearDown();
        foreach ($this->savedGlobals as $key=>[$exists,$value]) {
            if ($exists) $GLOBALS[$key]=$value;
            else unset($GLOBALS[$key]);
        }
    }

    private function query(string $sql,array $parameters=[])
    {
        $result=$parameters ? pg_query_params($this->connection,$sql,$parameters) : pg_query($this->connection,$sql);
        if (!$result) throw new RuntimeException('Disposable memory fixture query failed');
        return $result;
    }
    private function json(array $value): string {return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
    private function identity(array $actor): int
    {
        $result=$this->query('INSERT INTO synth.actor_identities(full_form_id,origin_plugin,playthrough_id,display_name) VALUES($1,$2,$3,$4) RETURNING id',
            [$actor['form_id'],$actor['origin_plugin'],$actor['playthrough_id'],$actor['display_name']]);
        return (int)pg_fetch_assoc($result)['id'];
    }
    private function snapshot(int $sequence,int $playerId,array $payload,int $frontier): void
    {
        $this->query("INSERT INTO synth_meta.context_snapshots(session_id,generation,snapshot_sequence,playthrough_id,player_identity_id,target_identity_id,audience_identity_ids,context_payload,history_event_id)
            VALUES('memory-recovery-session',1,$1,'memory-recovery-save',$2,$3,$4::jsonb,$5::jsonb,$6)",
            [$sequence,$playerId,$this->actorId,$this->json([$this->actorId]),$this->json($payload),$frontier]);
    }
    private function owner(int $sequence,string $request): array
    {
        return ['schema'=>'synth.event.v2','protocol_version'=>2,'type'=>'input_text','session_id'=>'memory-recovery-session',
            'request_id'=>$request,'turn_id'=>'turn:'.$request,'generation'=>1,'context_sequence'=>$sequence,
            'game'=>'fo4','runtime_variant'=>'flat','client_version'=>'fixture','runtime_version'=>'fixture',
            'capabilities'=>['dialogue.text','dialogue.turn_ownership','context.saved_anchor'],'payload'=>['text'=>'Remember our conversation.']];
    }
    private function persistRequest(array $event): void
    {
        $canonical=synth_protocol_canonical_json($event);$hash=hash('sha256',$canonical);
        $this->query("INSERT INTO synth_meta.request_executions(session_id,request_id,turn_id,generation,request_sha256,event_type,state)
            VALUES($1,$2,$3,1,$4,$5,'complete')",[$event['session_id'],$event['request_id'],$event['turn_id'],$hash,$event['type']]);
        $this->query("INSERT INTO synth_meta.protocol_events(session_id,request_id,turn_id,generation,event_type,canonical_event,event_sha256)
            VALUES($1,$2,$3,1,$4,$5::jsonb,$6)",[$event['session_id'],$event['request_id'],$event['turn_id'],$event['type'],$canonical,$hash]);
    }
    private function job(): array {return pg_fetch_assoc($this->query('SELECT * FROM synth_meta.worker_jobs WHERE id=$1',[$this->jobId]));}
    private function expireLease(): void
    {
        $this->query("UPDATE synth_meta.worker_jobs SET lease_until=clock_timestamp()-INTERVAL '1 second',available_at=clock_timestamp()-INTERVAL '1 second' WHERE id=$1",[$this->jobId]);
    }

    private function setSnapshotClock(int $sequence,mixed $clock,bool $present=true): void
    {
        if (!$present) {
            $this->query("UPDATE synth_meta.context_snapshots SET context_payload=context_payload#-'{world,game_time_ticks}'
                WHERE session_id='memory-recovery-session' AND snapshot_sequence=$1",[$sequence]);
            return;
        }
        $this->query("UPDATE synth_meta.context_snapshots SET context_payload=jsonb_set(context_payload,'{world,game_time_ticks}',$2::jsonb)
            WHERE session_id='memory-recovery-session' AND snapshot_sequence=$1",
            [$sequence,json_encode($clock,JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)]);
    }
    private function copySnapshot(int $sequence,mixed $clock): void
    {
        $source=pg_fetch_assoc($this->query("SELECT player_identity_id,context_payload FROM synth_meta.context_snapshots
            WHERE session_id='memory-recovery-session' AND snapshot_sequence=1"));
        $payload=json_decode($source['context_payload'],true,flags:JSON_THROW_ON_ERROR);
        $payload['snapshot_sequence']=$sequence;
        $payload['world']['game_time_ticks']=$clock;
        $this->snapshot($sequence,(int)$source['player_identity_id'],$payload,0);
    }
    private function appendHistory(int $sequence,array $changes=[],?int $sceneSequence=null): int
    {
        $source=pg_fetch_assoc($this->query('SELECT event_payload FROM synth_meta.history_events WHERE id=$1',[$this->historyId]));
        $record=array_replace(json_decode($source['event_payload'],true,flags:JSON_THROW_ON_ERROR),$changes);
        $record['rowid']=$this->nextHistoryRowid++;
        $event=$this->owner($sequence,'memory-recovery-extra-'.$record['rowid']);
        $this->persistRequest($event);
        $result=$this->query("INSERT INTO synth_meta.history_events(session_id,generation,context_sequence,scene_context_sequence,request_id,
            playthrough_id,eventlog_rowid,event_payload) VALUES('memory-recovery-session',1,$1,$2,$3,'memory-recovery-save',$4,$5::jsonb) RETURNING id",
            [$sequence,$sceneSequence,$event['request_id'],$record['rowid'],$this->json($record)]);
        return (int)pg_fetch_assoc($result)['id'];
    }
    private function setFrontier(int $historyId): void
    {
        $this->query("UPDATE synth_meta.context_snapshots SET history_event_id=$1
            WHERE session_id='memory-recovery-session' AND snapshot_sequence=2",[$historyId]);
    }
    private function historyRows(string $filter=''): array
    {
        return pg_fetch_all($this->query(synth_protocol_history_cte($this->database,$this->event)
            .'SELECT * FROM native_history '.$filter.' ORDER BY history_event_id')) ?: [];
    }
    private function visibleBatchIds(): array
    {
        return array_map(static fn(array $row): int=>(int)$row['id'],pg_fetch_all($this->query(
            synth_memory_visible_batch_cte($this->database,$this->event).'SELECT id FROM native_memory_batches ORDER BY id')) ?: []);
    }
    private function stampSourceWithUtcClock(): void
    {
        $this->query("UPDATE synth_meta.history_events SET event_payload=event_payload||$2::jsonb WHERE id=$1",
            [$this->historyId,$this->json(['gamets'=>1791859200,'localts'=>1791859201,'ts'=>1791859202])]);
    }
    private function attachEarlierSummary(): array
    {
        $batch=pg_fetch_assoc($this->query('SELECT input_payload FROM synth_meta.memory_batches WHERE id=$1',[$this->batchId]));
        $input=json_decode($batch['input_payload'],true,flags:JSON_THROW_ON_ERROR);
        $this->copySnapshot(3,500);
        $earlierId=$this->appendHistory(3,['data'=>'Nora: Earlier Diamond City evidence.','gamets'=>500]);
        $this->setFrontier($earlierId);
        $earlier=synth_memory_build_batch([['history_event_id'=>$earlierId,'type'=>'inputtext','gamets'=>500,
            'source_location'=>'Diamond City','data'=>'Nora: Earlier Diamond City evidence.']],1,1000000);
        $result=$this->query("INSERT INTO synth_meta.memory_batches(actor_identity_id,playthrough_id,session_id,generation,context_sequence,source_hash,input_payload,packed_message,first_gamets,last_gamets)
            VALUES($1,'memory-recovery-save','memory-recovery-session',1,2,$2,$3::jsonb,$4,500,500) RETURNING id",
            [$this->actorId,$earlier['source_hash'],$this->json($earlier['input_payload']),$earlier['packed_message']]);
        $parentId=(int)pg_fetch_assoc($result)['id'];
        $this->query('INSERT INTO synth_meta.memory_batch_sources(batch_id,history_event_id,position) VALUES($1,$2,1)',[$parentId,$earlierId]);
        $result=$this->query("INSERT INTO synth_meta.worker_jobs(job_type,session_id,request_id,turn_id,generation,actor_identity_id,memory_batch_id,payload,state)
            VALUES('memory','memory-recovery-session',$1,$2,1,$3,$4,'{\"context_sequence\":2}','ready') RETURNING id",
            [$this->event['request_id'],$this->event['turn_id'],$this->actorId,$parentId]);
        $parentJob=(int)pg_fetch_assoc($result)['id'];
        $result=$this->query('INSERT INTO synth_meta.memory_summaries(batch_id,job_id,summary) VALUES($1,$2,$3) RETURNING id',[$parentId,$parentJob,'Earlier accepted summary.']);
        $summaryId=(int)pg_fetch_assoc($result)['id'];
        $input['previous_summary']=['id'=>$summaryId,'summary'=>'Earlier accepted summary.'];
        $json=$this->json($input);
        $this->query('UPDATE synth_meta.memory_batches SET input_payload=$2::jsonb,source_hash=$3 WHERE id=$1',[$this->batchId,$json,hash('sha256',$json)]);
        $this->query('INSERT INTO synth_meta.memory_batch_summary_sources(batch_id,summary_id,summary_revision) VALUES($1,$2,0)',[$this->batchId,$summaryId]);
        return [$summaryId,$parentId];
    }

    /** Five settled sources, no pre-existing work, and a fresh scene in this disposable database only. */
    private function dispatchFixture(bool $differentResponder=false): array
    {
        $this->query('DELETE FROM synth_meta.worker_jobs WHERE id=$1',[$this->jobId]);
        $this->query('DELETE FROM synth_meta.memory_batch_sources WHERE batch_id=$1',[$this->batchId]);
        $this->query('DELETE FROM synth_meta.memory_batches WHERE id=$1',[$this->batchId]);
        $GLOBALS['FEATURES']['MEMORY_EMBEDDING']=['AUTO_CREATE_SUMMARYS'=>true,
            'AUTO_CREATE_SUMMARY_MIN_EVENTS'=>5,'AUTO_CREATE_SUMMARY_INTERVAL'=>10];
        $this->setSnapshotClock(1,26886800);
        $this->setSnapshotClock(2,27720133);
        $sourceIds=[$this->historyId];
        for ($index=2;$index<=5;++$index) {
            $sourceIds[]=$this->appendHistory(1,['data'=>'Nora: Settled owned evidence '.$index.'.','ts'=>1000+$index]);
        }
        $row=pg_fetch_assoc($this->query("SELECT player_identity_id,context_payload FROM synth_meta.context_snapshots
            WHERE session_id='memory-recovery-session' AND snapshot_sequence=2"));
        $payload=json_decode($row['context_payload'],true,flags:JSON_THROW_ON_ERROR);
        $responder=$payload['target'];$responderId=$this->actorId;
        $audienceIds=[$this->actorId];$privateId=null;
        if ($differentResponder) {
            $responder=['form_id'=>'0x0001A4D7','origin_plugin'=>'Fallout4.esm',
                'playthrough_id'=>'memory-recovery-save','display_name'=>'Preston Garvey'];
            $responderId=$this->identity($responder);
            $payload['audience'][]=$responder;
            $audienceIds[]=$responderId;
            $this->query("UPDATE synth_meta.history_events SET event_payload=jsonb_set(event_payload,'{people}',$2::jsonb)
                WHERE id=ANY($1::bigint[])",['{'.implode(',',$sourceIds).'}',json_encode('|Nora|Piper Wright|Preston Garvey|',JSON_THROW_ON_ERROR)]);
            $privateId=$this->appendHistory(1,['data'=>'Nora: Private evidence heard only by Piper.',
                'people'=>'|Nora|Piper Wright|','ts'=>1010]);
        }
        $scene=['interior'=>false,'scene'=>[
            'cell'=>['form_id'=>'0x00018AA2','origin_plugin'=>'Fallout4.esm'],
            'worldspace'=>['form_id'=>'0x0000003C','origin_plugin'=>'Fallout4.esm']]];
        foreach ([1=>26886800,2=>27720133] as $sequence=>$clock) {
            $saved=$payload;$saved['snapshot_sequence']=$sequence;
            $saved['world']=array_replace($saved['world'],$scene,['game_time_ticks'=>$clock]);
            $this->query("UPDATE synth_meta.context_snapshots SET context_payload=$2::jsonb,audience_identity_ids=$3::jsonb
                WHERE session_id='memory-recovery-session' AND snapshot_sequence=$1",
                [$sequence,$this->json($saved),$this->json($audienceIds)]);
            if ($sequence===2) $payload=$saved;
        }
        $frontier=$privateId ?? $sourceIds[array_key_last($sourceIds)];
        $this->setFrontier($frontier);
        $fresh=$payload;$fresh['snapshot_sequence']=3;$fresh['world']['game_time_ticks']=27720134;
        $this->snapshot(3,(int)$row['player_identity_id'],$fresh,$frontier);
        $this->query("UPDATE synth_meta.context_snapshots SET audience_identity_ids=$1::jsonb
            WHERE session_id='memory-recovery-session' AND snapshot_sequence=3",[$this->json($audienceIds)]);
        $this->query("UPDATE synth_meta.request_executions SET claimed_at=clock_timestamp()-INTERVAL '2 seconds'
            WHERE session_id=$1 AND request_id=$2",[$this->event['session_id'],$this->event['request_id']]);
        $sourceBinding=$this->dispatchBinding($this->actorId,$payload['target']['display_name']);
        $binding=$responderId===$this->actorId ? $sourceBinding : $this->dispatchBinding($responderId,$responder['display_name']);
        return ['sources'=>$sourceIds,'private_source'=>$privateId,'payload'=>$payload,'responder'=>$responder,
            'responder_id'=>$responderId,'binding'=>$binding,'source_binding'=>$sourceBinding];
    }

    private function dispatchBinding(int $actorId,string $name): array
    {
        $profile=(int)pg_fetch_assoc($this->query('INSERT INTO synth.core_profiles(label) VALUES($1) RETURNING id',
            ['Memory dispatch fixture '.$name]))['id'];
        $npc=(int)pg_fetch_assoc($this->query('INSERT INTO synth.core_npc_master(npc_name,profile_id,actor_identity_id)
            VALUES($1,$2,$3) RETURNING id',[$name,$profile,$actorId]))['id'];
        return ['profile_id'=>$profile,'npc_id'=>$npc];
    }

    /** Persist protocol-valid completed lines and their immutable route proof, without any provider. */
    private function dispatchParentResponse(array $parent,array $fixture): string
    {
        $lines=[];
        foreach (['response_start'=>['server_version'=>'fixture'],
            'dialogue'=>['speaker'=>$fixture['payload']['target'],'text'=>'Let us remember this conversation.'],
            'response_end'=>['status'=>'complete']] as $type=>$linePayload) {
            $lines[$type]=$this->json(['schema'=>'synth.response.line.v2','protocol_version'=>2,'game'=>'fo4',
                'request_id'=>$parent['request_id'],'turn_id'=>$parent['turn_id'],'generation'=>$parent['generation'],
                'context_sequence'=>$parent['context_sequence'],'runtime_variant'=>$parent['runtime_variant'],
                'line_id'=>'fixture:'.$type,'type'=>$type,'payload'=>$linePayload])."\n";
        }
        $body=implode('',$lines);
        $this->query("UPDATE synth_meta.request_executions SET response_body=decode($1,'hex') WHERE session_id=$2 AND request_id=$3",
            [bin2hex($body),$parent['session_id'],$parent['request_id']]);
        $this->query("INSERT INTO synth_meta.response_routes
            (session_id,request_id,line_id,line_sha256,generation,context_sequence,speaker,listener_state,listener)
            VALUES($1,$2,'fixture:dialogue',$3,$4,$5,$6::jsonb,'addressed',$7::jsonb)",
            [$parent['session_id'],$parent['request_id'],hash('sha256',$lines['dialogue']),$parent['generation'],
                $parent['context_sequence'],$this->json($fixture['payload']['target']),
                $this->json($fixture['responder_id']===$this->actorId ? $fixture['payload']['player'] : $fixture['responder'])]);
        return hash('sha256',$body);
    }

    private function dispatchContinuation(array $fixture,string $role,bool $prepared=true,?array $parent=null,?int $chainId=null): array
    {
        $parent ??= $this->event;
        $hash=$this->dispatchParentResponse($parent,$fixture);
        $sceneSequence=($parent['payload']['scene_context_sequence'] ?? $parent['context_sequence'])+1;
        if ($sceneSequence>3) {
            $this->query("INSERT INTO synth_meta.context_snapshots
                (session_id,generation,snapshot_sequence,playthrough_id,player_identity_id,target_identity_id,
                    audience_identity_ids,context_payload,history_event_id)
                SELECT session_id,generation,$1,playthrough_id,player_identity_id,target_identity_id,audience_identity_ids,
                    jsonb_set(context_payload,'{snapshot_sequence}',to_jsonb($1::bigint)),history_event_id
                FROM synth_meta.context_snapshots WHERE session_id='memory-recovery-session' AND snapshot_sequence=$2",
                [$sceneSequence,$sceneSequence-1]);
        }
        $child=$parent;$child['request_id']='memory-recovery-zz-'.$role.'-'.($chainId ?? 'first');$child['type']='trigger';
        $child['capabilities']=array_values(array_unique([...$child['capabilities'],'dialogue.rechat.ownership','dialogue.rechat.scene']));
        $child['payload']=['kind'=>'rechat','actor'=>$fixture['payload']['target'],'reply_to_request_id'=>$parent['request_id'],
            'reply_to_line_id'=>'fixture:dialogue','scene_context_sequence'=>$sceneSequence];
        $this->persistRequest($child);
        if ($chainId===null) {
            $policy=['source_profile_id'=>$fixture['source_binding']['profile_id'],'source_actor_identity_id'=>$this->actorId];
            $chainId=(int)pg_fetch_assoc($this->query("INSERT INTO synth_meta.rechat_chains
                (session_id,root_request_id,generation,context_sequence,turn_id,runtime_variant,mode,policy,budget,used_rounds,terminal_reason)
                VALUES($1,$2,1,2,$3,'flat','conversational',$4::jsonb,3,$5,$6) RETURNING id",
                [$parent['session_id'],$parent['request_id'],$parent['turn_id'],$this->json($policy),
                    $role==='terminal' ? 0 : 1,$role==='terminal' ? 'no_candidate' : null]))['id'];
        } elseif ($role==='terminal') {
            $this->query("UPDATE synth_meta.rechat_chains SET terminal_reason='no_candidate' WHERE id=$1",[$chainId]);
        }
        $this->query("INSERT INTO synth_meta.rechat_continuations
            (session_id,request_id,chain_id,parent_request_id,parent_line_id,parent_response_sha256,round_number,status,terminal_reason)
            VALUES($1,$2,$3,$4,'fixture:dialogue',$5,$6,$7,$8)",
            [$child['session_id'],$child['request_id'],$chainId,$parent['request_id'],$hash,
                $role==='terminal' ? null : 1,$role==='terminal' ? 'terminal' : 'reserved',$role==='terminal' ? 'no_candidate' : null]);
        $this->query("INSERT INTO synth_meta.rechat_selections
            (session_id,request_id,role,actor_identity_id,npc_id,profile_id,identity,terminal_reason,prepared_at)
            VALUES($1,$2,$3,$4,$5,$6,$7::jsonb,$8,CASE WHEN $9::boolean THEN clock_timestamp() END)",
            [$child['session_id'],$child['request_id'],$role,$role==='npc' ? $fixture['responder_id'] : null,
                $role==='npc' ? $fixture['binding']['npc_id'] : null,$role==='terminal' ? null : $fixture['binding']['profile_id'],
                $role==='npc' ? $this->json($fixture['responder']) : null,$role==='terminal' ? 'no_candidate' : null,
                $prepared ? 'true' : 'false']);
        return [$child,$chainId];
    }

    public static function closedDispatchSelections(): array
    {
        return ['terminal'=>['terminal',true],'narrator'=>['narrator',true],'unprepared NPC'=>['npc',false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('closedDispatchSelections')]
    public function testDispatchSkipsClosedContinuationAndPublishesSettledParentMemory(string $role,bool $prepared): void
    {
        $fixture=$this->dispatchFixture();
        [$child]=$this->dispatchContinuation($fixture,$role,$prepared);
        $calls=0;$owner=null;
        $summaryId=synth_memory_dispatch($this->database,function(array $input) use (&$calls,&$owner,$fixture): string {
            ++$calls;$owner=$GLOBALS['SYNTH_REQUEST_EVENT']['json'];
            $this->assertTrue($this->database->isTransactionIdle(),'Providers must run outside database locks');
            $this->assertSame($fixture['sources'],array_column($input['events'],'history_event_id'));
            return 'Accepted settled conversation memory.';
        });
        $this->assertNotNull($summaryId,'A closed or non-NPC continuation must not mask its completed parent');
        $this->assertSame(1,$calls);
        $this->assertSame(synth_protocol_canonical_json($this->event),synth_protocol_canonical_json($owner),
            'The immutable completed parent owns capture and publication');
        $saved=pg_fetch_assoc($this->query("SELECT j.request_id,j.actor_identity_id,j.state,j.payload ? 'accepted_summary' AS checkpoint,b.id AS batch_id
            FROM synth_meta.memory_summaries s JOIN synth_meta.worker_jobs j ON j.id=s.job_id
            JOIN synth_meta.memory_batches b ON b.id=s.batch_id WHERE s.id=$1",[$summaryId]));
        $this->assertSame($this->event['request_id'],$saved['request_id']);
        $this->assertNotSame($child['request_id'],$saved['request_id']);
        $this->assertSame((string)$this->actorId,$saved['actor_identity_id']);
        $this->assertSame('ready',$saved['state']);$this->assertSame('t',$saved['checkpoint']);
        $this->assertSame($GLOBALS['SYNTH_REQUEST_EVENT']['json'],$this->event,'Dispatch restores the caller owner');
        $this->query("UPDATE synth_meta.runtime_sessions SET memory_dispatch_after=clock_timestamp()-INTERVAL '1 second'
            WHERE session_id='memory-recovery-session'");
        $this->assertNull(synth_memory_dispatch($this->database,static function() use (&$calls): string {
            ++$calls;return 'Duplicate generation is forbidden.';
        }));
        $this->assertSame(1,$calls,'Published coverage must prevent generation on another scheduled dispatch');
        $this->assertSame('1',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.memory_summaries'))['n']);
        $this->assertSame('1',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.memory_batches'))['n']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('closedDispatchSelections')]
    public function testDispatchDoesNotInventParentForClosedOrNonNpcContinuation(string $role,bool $prepared): void
    {
        $fixture=$this->dispatchFixture();
        $this->dispatchContinuation($fixture,$role,$prepared);
        $this->query("UPDATE synth_meta.request_executions SET state='claimed' WHERE session_id=$1 AND request_id=$2",
            [$this->event['session_id'],$this->event['request_id']]);
        $calls=0;
        $this->assertNull(synth_memory_dispatch($this->database,static function() use (&$calls): string {
            ++$calls;return 'No eligible completed parent exists.';
        }));
        $this->assertSame(0,$calls);
        $this->assertSame('0',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.memory_batches'))['n']);
        $this->assertSame('0',pg_fetch_assoc($this->query("SELECT count(*) AS n FROM synth_meta.worker_jobs WHERE job_type='memory'"))['n']);
    }

    public function testDispatchStoppedNpcContinuationCannotHideCompletedParent(): void
    {
        $fixture=$this->dispatchFixture();
        [$npc,$chainId]=$this->dispatchContinuation($fixture,'npc');
        $this->dispatchContinuation($fixture,'terminal',true,$npc,$chainId);
        $owner=null;$calls=0;
        $this->assertNotNull(synth_memory_dispatch($this->database,function() use (&$owner,&$calls): string {
            $owner=$GLOBALS['SYNTH_REQUEST_EVENT']['json'];++$calls;return 'Parent conversation retained after its NPC chain stopped.';
        }));
        $this->assertSame(1,$calls);
        $this->assertSame(synth_protocol_canonical_json($this->event),synth_protocol_canonical_json($owner),
            'An older NPC on the stopped chain must not replace the usable parent');
    }

    public function testDispatchPreparedNpcUsesExactResponderAndItsPrivateVisibility(): void
    {
        $fixture=$this->dispatchFixture(true);
        [$child]=$this->dispatchContinuation($fixture,'npc');
        $calls=0;$owner=null;
        $summaryId=synth_memory_dispatch($this->database,function(array $input) use (&$calls,&$owner,$fixture): string {
            ++$calls;$owner=$GLOBALS['SYNTH_REQUEST_EVENT']['json'];
            $this->assertSame($fixture['sources'],array_column($input['events'],'history_event_id'));
            $this->assertNotContains($fixture['private_source'],array_column($input['events'],'history_event_id'));
            return 'The selected responder remembers only the five sources it could hear.';
        });
        $this->assertNotNull($summaryId);
        $this->assertSame(1,$calls);
        $this->assertSame(synth_protocol_canonical_json($child),synth_protocol_canonical_json($owner),
            'A usable prepared NPC must remain the dispatch origin');
        $saved=pg_fetch_assoc($this->query('SELECT j.request_id,b.actor_identity_id FROM synth_meta.memory_summaries s
            JOIN synth_meta.worker_jobs j ON j.id=s.job_id JOIN synth_meta.memory_batches b ON b.id=s.batch_id WHERE s.id=$1',[$summaryId]));
        $this->assertSame($child['request_id'],$saved['request_id']);
        $this->assertSame((string)$fixture['responder_id'],$saved['actor_identity_id']);
        $this->assertNotSame((string)$this->actorId,$saved['actor_identity_id'],'The captured Piper target cannot impersonate selected Preston');
    }

    public function testDispatchPreparedNpcCannotBypassChangedLiveBinding(): void
    {
        $fixture=$this->dispatchFixture(true);
        $this->dispatchContinuation($fixture,'npc');
        $this->query('UPDATE synth.core_npc_master SET profile_id=$1 WHERE id=$2',
            [$fixture['source_binding']['profile_id'],$fixture['binding']['npc_id']]);
        $calls=0;
        try {
            synth_memory_dispatch($this->database,static function() use (&$calls): string {
                ++$calls;return 'A rebound selection must never publish memory.';
            });
            $this->fail('Dispatch bypassed the exact prepared responder binding');
        } catch (LogicException $error) {
            $this->assertSame('rechat_selected_binding_changed',$error->getMessage());
        }
        $this->assertSame(0,$calls);
        $this->assertSame('0',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.memory_batches'))['n']);
        $this->assertSame('0',pg_fetch_assoc($this->query("SELECT count(*) AS n FROM synth_meta.worker_jobs WHERE job_type='memory'"))['n']);
    }

    public function testNativeClockComesFromExactSourceSnapshotWithoutRewritingUtcEvidence(): void
    {
        $this->stampSourceWithUtcClock();
        $this->setSnapshotClock(1,26886800);
        $before=pg_fetch_assoc($this->query('SELECT event_payload::text FROM synth_meta.history_events WHERE id=$1',[$this->historyId]));
        $row=$this->historyRows()[0];
        $this->assertSame('26886800',$row['gamets']);
        $this->assertSame('1791859201',$row['localts']);
        $this->assertSame('1791859202',$row['ts']);
        $this->assertSame('Diamond City',$row['source_location']);
        $this->assertSame($before,pg_fetch_assoc($this->query('SELECT event_payload::text FROM synth_meta.history_events WHERE id=$1',[$this->historyId])));
        $this->assertSame([],$this->visibleBatchIds(),'The old frozen 1000-tick input must not cover a source now projected at 26886800 ticks');

        $this->setSnapshotClock(1,9007199254740991);
        $this->assertSame('9007199254740991',$this->historyRows()[0]['gamets'],'The protocol maximum must fit without rounding');
        $this->setSnapshotClock(1,1);
        $this->assertSame('1',$this->historyRows()[0]['gamets']);
    }

    public function testMissingAndMalformedSourceClocksRemainUnknownWithoutUtcFallback(): void
    {
        $this->stampSourceWithUtcClock();
        $before=pg_fetch_assoc($this->query('SELECT event_payload::text FROM synth_meta.history_events WHERE id=$1',[$this->historyId]));
        $cases=['missing'=>[null,false],'null'=>[null,true],'zero'=>[0,true],'negative'=>[-1,true],
            'numeric string'=>['26886800',true],'fraction'=>[26886800.5,true],'boolean'=>[true,true],
            'array'=>[[26886800],true],'object'=>[(object)['ticks'=>26886800],true],
            'protocol overflow'=>[9007199254740992,true],'bigint overflow'=>['9223372036854775808',true],
            'oversize number'=>[1.0e30,true]];
        foreach ($cases as $case=>[$clock,$present]) {
            $this->setSnapshotClock(1,$clock,$present);
            $rows=$this->historyRows();
            $this->assertCount(1,$rows,$case.' must preserve owned evidence');
            $this->assertNull($rows[0]['gamets'],$case.' must not use the payload or current snapshot clock');
            $this->assertSame([],$this->visibleBatchIds(),$case.' must hide frozen memory with an unverified source clock');
            $this->assertNull(synth_memory_capture_batch($this->database,1,1000000),$case.' must not settle memory');
        }
        $this->assertSame($before,pg_fetch_assoc($this->query('SELECT event_payload::text FROM synth_meta.history_events WHERE id=$1',[$this->historyId])));
    }

    public function testSavedSceneClockOverridesOriginalRequestClock(): void
    {
        $this->stampSourceWithUtcClock();
        $this->setSnapshotClock(1,26886800);
        $this->setSnapshotClock(2,26886999);
        $this->query('UPDATE synth_meta.history_events SET scene_context_sequence=2 WHERE id=$1',[$this->historyId]);
        $this->assertSame('26886999',$this->historyRows()[0]['gamets']);
        $this->setSnapshotClock(2,null,false);
        $this->assertNull($this->historyRows()[0]['gamets'],'Missing scene time must not fall back to the original request snapshot');
    }

    public function testCaptureAdmitsSourceOnlyOnTickAfterStrictHourCutoff(): void
    {
        $this->stampSourceWithUtcClock();
        $this->setSnapshotClock(1,26886800);
        $this->setSnapshotClock(2,26886800+416666);
        $this->assertNull(synth_memory_capture_batch($this->database,1,1000000));
        $this->setSnapshotClock(2,26886800+416667);
        $batchId=synth_memory_capture_batch($this->database,1,1000000);
        $this->assertNotNull($batchId,'The first tick after the strict one-hour cutoff must capture the source');
        $batch=pg_fetch_assoc($this->query('SELECT first_gamets,last_gamets FROM synth_meta.memory_batches WHERE id=$1',[$batchId]));
        $this->assertSame(['first_gamets'=>'26886800','last_gamets'=>'26886800'],$batch);
    }

    public function testUtcStampedDialogueSettlesAfterStrictGameHourAndFreezesFiveOwnedSources(): void
    {
        $sourceClock=26886800;
        $hourTicks=416666;
        $this->stampSourceWithUtcClock();
        $this->setSnapshotClock(1,$sourceClock);
        $ids=[$this->historyId];
        for ($index=2;$index<=5;++$index) {
            $ids[]=$this->appendHistory(1,['data'=>'Nora: Owned Diamond City memory '.$index.'.',
                'ts'=>1791859202+$index,'localts'=>1791859201+$index,'gamets'=>1791859200+$index]);
        }
        $this->setFrontier($ids[array_key_last($ids)]);
        $before=pg_fetch_all($this->query('SELECT id,event_payload::text FROM synth_meta.history_events ORDER BY id'));
        foreach ([$sourceClock,$sourceClock+$hourTicks] as $currentClock) {
            $this->setSnapshotClock(2,$currentClock);
            $this->assertNull(synth_memory_capture_batch($this->database),'Settlement uses a strict older-than-one-game-hour cutoff');
        }
        $this->setSnapshotClock(2,$sourceClock+833333);
        $batchId=synth_memory_capture_batch($this->database);
        $this->assertNotNull($batchId,'UTC payload stamps must not prevent capture two game hours later');
        $batch=pg_fetch_assoc($this->query('SELECT * FROM synth_meta.memory_batches WHERE id=$1',[$batchId]));
        $this->assertSame('memory-recovery-save',$batch['playthrough_id']);
        $this->assertSame((string)$this->actorId,$batch['actor_identity_id']);
        $this->assertSame((string)$sourceClock,$batch['first_gamets']);
        $this->assertSame((string)$sourceClock,$batch['last_gamets']);
        $input=json_decode($batch['input_payload'],true,flags:JSON_THROW_ON_ERROR);
        $this->assertCount(5,$input['events']);
        $this->assertSame($ids,array_column($input['events'],'history_event_id'),'The observed source must be in the owned batch');
        $this->assertSame(array_fill(0,5,$sourceClock),array_column($input['events'],'gamets'));
        $expected=synth_memory_build_batch($this->historyRows(),5,1000000);
        $this->assertSame($expected['source_hash'],$batch['source_hash'],'Source hashes must bind the normalized game clocks');
        $sources=pg_fetch_all($this->query('SELECT history_event_id,position FROM synth_meta.memory_batch_sources WHERE batch_id=$1 ORDER BY position',[$batchId]));
        $this->assertSame($ids,array_map(static fn(array $row): int=>(int)$row['history_event_id'],$sources));
        $this->assertSame(range(1,5),array_map(static fn(array $row): int=>(int)$row['position'],$sources));
        $this->assertSame([$batchId],$this->visibleBatchIds());
        $this->assertNull(synth_memory_capture_batch($this->database),'Visible coverage must prevent duplicate memory');

        $jobId=synth_memory_enqueue($this->database,$batchId);
        $this->assertNotNull($jobId);
        $this->assertSame($jobId,synth_memory_enqueue($this->database,$batchId),'The same visible frozen batch must reuse its owned job');
        $this->batchId=$batchId;$this->jobId=$jobId;
        $checkpoint=$this->interruptAfterAcceptedText('Accepted normalized game-clock summary.');
        $hash=pg_fetch_assoc($this->query("SELECT encode(sha256(convert_to(input_payload::text,'UTF8')),'hex') AS hash
            FROM synth_meta.memory_batches WHERE id=$1",[$batchId]))['hash'];
        $this->assertSame($hash,$checkpoint['input_sha256']);
        $calls=0;
        $this->assertNotNull(synth_memory_process_job($this->database,$jobId,static function() use (&$calls) {++$calls;return 'Forbidden regenerated text.';}));
        $this->assertSame(0,$calls);
        $this->assertSame($batch['input_payload'],pg_fetch_assoc($this->query('SELECT input_payload FROM synth_meta.memory_batches WHERE id=$1',[$batchId]))['input_payload']);
        $this->assertSame($before,pg_fetch_all($this->query('SELECT id,event_payload::text FROM synth_meta.history_events ORDER BY id')));
    }

    public function testGameClockCannotWidenSavedFrontierActorOrSaveVisibility(): void
    {
        $later=$this->appendHistory(1,['data'=>'Nora: Later insertion must stay outside the saved frontier.']);
        $this->assertSame([$this->historyId],array_map(static fn(array $row): int=>(int)$row['history_event_id'],$this->historyRows()));
        $this->assertGreaterThan($this->historyId,$later);
        $this->setFrontier($later);
        $this->query("UPDATE synth_meta.history_events SET playthrough_id='another-save' WHERE id=$1",[$later]);
        $this->assertCount(1,$this->historyRows(),'Equal game time cannot grant cross-save visibility');
        $this->query("UPDATE synth_meta.history_events SET playthrough_id='memory-recovery-save',
            event_payload=jsonb_set(event_payload,'{people}','\"|Other Actor|\"'::jsonb) WHERE id=$1",[$later]);
        $this->assertCount(1,$this->historyRows(),'Equal game time cannot grant another actor\'s source visibility');
        $this->setSnapshotClock(1,1000001);
        $this->assertSame('1000001',$this->historyRows()[0]['gamets']);
        $this->assertSame([],$this->historyRows('WHERE gamets<=1000000'),'Future source time must not satisfy a current-time bound');
        $this->assertNull(synth_memory_capture_batch($this->database,1,1000000),'Future source time must not settle memory');
    }

    public function testSelfConsistentLegacyUtcCheckpointCannotPublishAndDoesNotBlockCorrectedCapture(): void
    {
        $checkpoint=$this->interruptAfterAcceptedText('Accepted legacy UTC-clock summary.');
        $this->stampSourceWithUtcClock();
        $batch=pg_fetch_assoc($this->query('SELECT input_payload FROM synth_meta.memory_batches WHERE id=$1',[$this->batchId]));
        $legacyInput=json_decode($batch['input_payload'],true,flags:JSON_THROW_ON_ERROR);
        $legacyInput['events'][0]['gamets']=1791859200;
        $legacyJson=$this->json($legacyInput);
        $this->query('UPDATE synth_meta.memory_batches SET input_payload=$2::jsonb,first_gamets=1791859200,last_gamets=1791859200,source_hash=$3 WHERE id=$1',
            [$this->batchId,$legacyJson,hash('sha256',$legacyJson)]);
        $checkpoint['input_sha256']=pg_fetch_assoc($this->query("SELECT encode(sha256(convert_to(input_payload::text,'UTF8')),'hex') AS hash
            FROM synth_meta.memory_batches WHERE id=$1",[$this->batchId]))['hash'];
        $this->query("UPDATE synth_meta.worker_jobs SET payload=jsonb_set(payload,'{accepted_summary}',$2::jsonb) WHERE id=$1",[$this->jobId,$this->json($checkpoint)]);
        $this->assertSame([],$this->visibleBatchIds());
        $this->assertNull(synth_memory_enqueue($this->database,$this->batchId));
        $this->assertNull(synth_memory_claim($this->database,$this->jobId));
        $calls=0;$embedded=0;
        $this->assertNull(synth_memory_process_job($this->database,$this->jobId,static function() use (&$calls) {++$calls;return 'Forbidden legacy recovery.';},
            static function() use (&$embedded) {++$embedded;return null;}));
        $this->assertSame(0,$calls);$this->assertSame(0,$embedded);
        $this->assertSame('0',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.memory_summaries WHERE job_id=$1',[$this->jobId]))['n']);
        $this->assertSame($checkpoint['input_sha256'],json_decode($this->job()['payload'],true)['accepted_summary']['input_sha256']);
        $corrected=synth_memory_capture_batch($this->database,1,1000000);
        $this->assertNotNull($corrected);
        $this->assertNotSame($this->batchId,$corrected,'Invalid UTC coverage must leave corrected sources capturable');
        $this->assertSame([$corrected],$this->visibleBatchIds());
        $input=json_decode(pg_fetch_assoc($this->query('SELECT input_payload FROM synth_meta.memory_batches WHERE id=$1',[$corrected]))['input_payload'],true);
        $this->assertSame(1000,$input['events'][0]['gamets']);
        $this->assertSame($this->historyId,$input['events'][0]['history_event_id']);
    }

    public function testPublishedMemoryRequiresExactFrozenClocksBoundsAndSourcePositions(): void
    {
        $summaryId=synth_memory_process_job($this->database,$this->jobId,static fn()=>'Accepted visible summary.');
        $this->assertNotNull($summaryId);
        $original=pg_fetch_assoc($this->query('SELECT input_payload,first_gamets,last_gamets FROM synth_meta.memory_batches WHERE id=$1',[$this->batchId]));
        foreach (['frozen UTC clock','frozen numeric string','first bound','last bound','source position','event count'] as $case) {
            switch ($case) {
                case 'frozen UTC clock': $this->query("UPDATE synth_meta.memory_batches SET input_payload=jsonb_set(input_payload,'{events,0,gamets}','1791859200'::jsonb) WHERE id=$1",[$this->batchId]);break;
                case 'frozen numeric string': $this->query("UPDATE synth_meta.memory_batches SET input_payload=jsonb_set(input_payload,'{events,0,gamets}','\"1000\"'::jsonb) WHERE id=$1",[$this->batchId]);break;
                case 'first bound': $this->query('UPDATE synth_meta.memory_batches SET first_gamets=999 WHERE id=$1',[$this->batchId]);break;
                case 'last bound': $this->query('UPDATE synth_meta.memory_batches SET last_gamets=1001 WHERE id=$1',[$this->batchId]);break;
                case 'source position': $this->query('UPDATE synth_meta.memory_batch_sources SET position=2 WHERE batch_id=$1',[$this->batchId]);break;
                case 'event count': $this->query("UPDATE synth_meta.memory_batches SET input_payload=jsonb_set(input_payload,'{events}',(input_payload->'events')||(input_payload->'events')) WHERE id=$1",[$this->batchId]);break;
            }
            $this->assertSame([],$this->visibleBatchIds(),$case.' must invalidate completed recall');
            $visible=pg_fetch_all($this->query(synth_memory_visible_batch_cte($this->database,$this->event)
                .'SELECT id FROM native_memory_effective_summaries')) ?: [];
            $this->assertSame([],$visible,$case.' must hide the derived summary');
            $this->query('UPDATE synth_meta.memory_batches SET input_payload=$2::jsonb,first_gamets=$3,last_gamets=$4 WHERE id=$1',
                [$this->batchId,$original['input_payload'],$original['first_gamets'],$original['last_gamets']]);
            $this->query('UPDATE synth_meta.memory_batch_sources SET position=1 WHERE batch_id=$1',[$this->batchId]);
            $this->assertSame([$this->batchId],$this->visibleBatchIds());
        }
        $this->assertSame('Accepted visible summary.',pg_fetch_assoc($this->query('SELECT summary FROM synth_meta.memory_summaries WHERE id=$1',[$summaryId]))['summary']);
    }

    public function testInconsistentParentClockHidesItsDependentCheckpointAndSummary(): void
    {
        [$summaryId,$parentId]=$this->attachEarlierSummary();
        $this->assertSame([$this->batchId,$parentId],$this->visibleBatchIds());
        $this->interruptAfterAcceptedText('Accepted descendant summary.');
        $this->setSnapshotClock(3,501);
        $this->assertSame([],$this->visibleBatchIds(),'A child must not inherit a parent whose frozen source clock no longer matches');
        $visible=pg_fetch_all($this->query(synth_memory_visible_batch_cte($this->database,$this->event)
            .'SELECT id FROM native_memory_effective_summaries')) ?: [];
        $this->assertSame([],$visible);
        $calls=0;$embedded=0;
        $this->assertNull(synth_memory_process_job($this->database,$this->jobId,static function() use (&$calls) {++$calls;return 'Forbidden descendant recovery.';},
            static function() use (&$embedded) {++$embedded;return null;}));
        $this->assertSame(0,$calls);$this->assertSame(0,$embedded);
        $this->assertSame('0',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.memory_summaries WHERE job_id=$1',[$this->jobId]))['n']);
        $this->assertSame('Earlier accepted summary.',pg_fetch_assoc($this->query('SELECT summary FROM synth_meta.memory_summaries WHERE id=$1',[$summaryId]))['summary']);
    }

    public function testAcceptedTextSurvivesAnEmbeddingInterruptionWithoutRegeneration(): void
    {
        $calls=0;
        $generate=static function() use (&$calls): string {return ++$calls===1 ? 'Accepted first summary.' : 'Different regenerated summary.';};
        $deadline=$this->job()['deadline_at'];
        try {
            synth_memory_process_job($this->database,$this->jobId,$generate,static function(): never {throw new SYNTHProtocolCancelled('memory_lease_lost');});
            $this->fail('Expected simulated interruption');
        } catch (SYNTHProtocolCancelled $error) {$this->assertSame('memory_lease_lost',$error->getMessage());}
        $this->assertSame('running',$this->job()['state']);
        $this->expireLease();
        $summaryId=synth_memory_process_job($this->database,$this->jobId,$generate,static fn()=>null);
        $this->assertNotNull($summaryId);
        $this->assertSame(1,$calls,'Recovery must not request different summary text after the accepted text was checkpointed');
        $summary=pg_fetch_assoc($this->query('SELECT summary FROM synth_meta.memory_summaries WHERE job_id=$1',[$this->jobId]));
        $this->assertSame('Accepted first summary.',$summary['summary']);
        $this->assertSame('ready',$this->job()['state']);
        $this->assertSame('2',$this->job()['attempts']);
        $this->assertSame($deadline,$this->job()['deadline_at']);
        $this->assertNull(synth_memory_process_job($this->database,$this->jobId,$generate));
        $this->assertSame(1,$calls);
        $this->assertSame('1',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.memory_summaries WHERE job_id=$1',[$this->jobId]))['n']);
    }

    public function testAcceptedCheckpointSurvivesActualWorkerProcessExit(): void
    {
        $marker=tempnam(sys_get_temp_dir(),'synth_memory_recovery_');
        $this->assertNotFalse($marker);
        try {
            $autoload=dirname((new ReflectionClass(Composer\Autoload\ClassLoader::class))->getFileName(),2).'/autoload.php';
            $script='require '.var_export($autoload,true).';require '.var_export(__FILE__,true).';'
                .'$connection=pg_connect("host=localhost dbname=testdb user=dwemer password=dwemer",PGSQL_CONNECT_FORCE_NEW);'
                .'pg_query($connection,"SET search_path TO synth,synth_meta,public");'
                .'$GLOBALS["db"]=new NativeMemoryRecoveryFixtureDatabase($connection);'
                .'$GLOBALS["SYNTH_PROTOCOL_REQUEST_CLAIMED"]=true;'
                .'$GLOBALS["SYNTH_REQUEST_EVENT"]=["json"=>json_decode('.var_export($this->json($this->event),true).',true)];'
                .'synth_memory_process_job($GLOBALS["db"],'.$this->jobId.',static function(){'
                .'file_put_contents('.var_export($marker,true).',"generated\n",FILE_APPEND);return "Accepted process summary.";},'
                .'static function(){exit(23);});exit(24);';
            $process=proc_open([PHP_BINARY,'-r',$script],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            $this->assertIsResource($process);
            stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);
            fclose($pipes[1]);fclose($pipes[2]);
            $this->assertSame(23,proc_close($process),'The worker must exit after accepted text, before publication');
            $this->assertSame("generated\n",file_get_contents($marker));
            $this->assertSame('running',$this->job()['state']);
            $this->assertArrayHasKey('accepted_summary',json_decode($this->job()['payload'],true));
            $deadline=$this->job()['deadline_at'];
            $this->expireLease();$calls=0;
            $this->assertNotNull(synth_memory_process_job($this->database,$this->jobId,static function() use (&$calls) {++$calls;return 'Regenerated text.';}));
            $this->assertSame(0,$calls);
            $this->assertSame($deadline,$this->job()['deadline_at']);
            $rows=pg_fetch_all($this->query('SELECT summary FROM synth_meta.memory_summaries WHERE job_id=$1',[$this->jobId]));
            $this->assertSame([['summary'=>'Accepted process summary.']],$rows);
        } finally {if (is_string($marker)) unlink($marker);}
    }

    #[PHPUnit\Framework\Attributes\DataProvider('invalidSummaryProvider')]
    public function testInvalidGeneratedTextNeverReachesEmbedding(string $summary): void
    {
        $embedded=0;
        try {
            synth_memory_process_job($this->database,$this->jobId,static fn()=>$summary,static function() use (&$embedded) {++$embedded;return null;});
            $this->fail('Invalid summary accepted');
        } catch (InvalidArgumentException $error) {$this->assertSame('Invalid native memory summary',$error->getMessage());}
        $this->assertSame(0,$embedded);
        $this->assertArrayNotHasKey('accepted_summary',json_decode($this->job()['payload'],true));
        $this->assertSame('pending',$this->job()['state']);
    }
    public static function invalidSummaryProvider(): array
    {
        return ['empty'=>["  \t\n "],'invalid utf8'=>["\xC3\x28"],'too large'=>[str_repeat('a',65537)],'nul'=>["summary\0text"]];
    }

    private function interruptAfterAcceptedText(string $summary='Accepted text.'): array
    {
        try {
            synth_memory_process_job($this->database,$this->jobId,static fn()=>$summary,static function(): never {throw new SYNTHProtocolCancelled('memory_lease_lost');});
            $this->fail('Expected simulated interruption');
        } catch (SYNTHProtocolCancelled $error) {$this->assertSame('memory_lease_lost',$error->getMessage());}
        $this->expireLease();
        $payload=json_decode($this->job()['payload'],true);
        $this->assertArrayHasKey('accepted_summary',$payload);
        return $payload['accepted_summary'];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('malformedCheckpointProvider')]
    public function testMalformedCheckpointCannotRegenerateOrPublish(string $case): void
    {
        $checkpoint=$this->interruptAfterAcceptedText();
        switch ($case) {
            case 'schema': $checkpoint['schema']='unknown';break;
            case 'text': $checkpoint['summary']='Tampered text.';break;
            case 'hash': $checkpoint['summary_sha256']=str_repeat('0',64);break;
            case 'job': $checkpoint['job_id']=$this->jobId+1;break;
            case 'batch': $checkpoint['batch_id']=$this->batchId+1;break;
            case 'input': $checkpoint['input_sha256']=str_repeat('0',64);break;
            case 'extra': $checkpoint['unexpected']='value';break;
            case 'missing': unset($checkpoint['summary']);break;
            case 'scalar': $checkpoint='untrusted';break;
            case 'null': $checkpoint=null;break;
            case 'blank': $checkpoint['summary']=' ';$checkpoint['summary_sha256']=hash('sha256',' ');break;
            case 'oversize': $checkpoint['summary']=str_repeat('a',65537);$checkpoint['summary_sha256']=hash('sha256',$checkpoint['summary']);break;
            case 'untrimmed': $checkpoint['summary']=' Accepted text. ';$checkpoint['summary_sha256']=hash('sha256',$checkpoint['summary']);break;
        }
        $this->query("UPDATE synth_meta.worker_jobs SET payload=jsonb_set(payload,'{accepted_summary}',$2::jsonb) WHERE id=$1",
            [$this->jobId,json_encode($checkpoint,JSON_THROW_ON_ERROR)]);
        $calls=0;$embedded=0;
        try {
            synth_memory_process_job($this->database,$this->jobId,static function() use (&$calls) {++$calls;return 'Replacement text.';},
                static function() use (&$embedded) {++$embedded;return null;});
            $this->fail('Malformed checkpoint accepted');
        } catch (LogicException|InvalidArgumentException $error) {$this->assertStringContainsString('memory',$error->getMessage());}
        $this->assertSame(0,$calls);
        $this->assertSame(0,$embedded);
        $this->assertSame('0',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.memory_summaries WHERE job_id=$1',[$this->jobId]))['n']);
        $stored=json_decode($this->job()['payload'],true)['accepted_summary'];
        // PostgreSQL JSONB preserves object values/types while ordering keys canonically.
        if (is_array($checkpoint)) {ksort($checkpoint);ksort($stored);}
        $this->assertSame($checkpoint,$stored);
    }
    public static function malformedCheckpointProvider(): array
    {
        return array_combine(['schema','text','hash','job','batch','input','extra','missing','scalar','null','blank','oversize','untrimmed'],
            array_map(static fn($value)=>[$value],['schema','text','hash','job','batch','input','extra','missing','scalar','null','blank','oversize','untrimmed']));
    }

    public function testCurrentInputMutationRejectsRecoveredCheckpoint(): void
    {
        $this->interruptAfterAcceptedText();
        $this->query("UPDATE synth_meta.memory_batches SET input_payload=jsonb_set(input_payload,'{events,0,data}','\"Changed evidence.\"'::jsonb) WHERE id=$1",[$this->batchId]);
        $calls=0;
        try {
            synth_memory_process_job($this->database,$this->jobId,static function() use (&$calls) {++$calls;return 'Replacement text.';});
            $this->fail('Changed input accepted');
        } catch (LogicException $error) {$this->assertSame('Invalid native memory checkpoint',$error->getMessage());}
        $this->assertSame(0,$calls);
    }

    public function testInputCannotChangeBetweenGenerationAndCheckpoint(): void
    {
        $embedded=0;
        try {
            synth_memory_process_job($this->database,$this->jobId,function() {
                $this->query("UPDATE synth_meta.memory_batches SET input_payload=jsonb_set(input_payload,'{events,0,data}','\"Changed evidence.\"'::jsonb) WHERE id=$1",[$this->batchId]);
                return 'Accepted against the previous input.';
            },static function() use (&$embedded) {++$embedded;return null;});
            $this->fail('Changed in-flight input accepted');
        } catch (LogicException $error) {$this->assertSame('Native memory input changed',$error->getMessage());}
        $this->assertSame(0,$embedded);
        $this->assertArrayNotHasKey('accepted_summary',json_decode($this->job()['payload'],true));
    }

    public function testCheckpointNeverOverwritesAcceptedTextAndCompleteRejectsMismatch(): void
    {
        $claimed=synth_memory_claim($this->database,$this->jobId);
        $this->assertNotNull($claimed);
        $this->assertSame('Accepted text.',synth_memory_checkpoint($this->database,$this->jobId,$claimed['lease_token'],$claimed['input_sha256'],'Accepted text.'));
        foreach (['checkpoint','publication'] as $operation) {
            try {
                if ($operation==='checkpoint') synth_memory_checkpoint($this->database,$this->jobId,$claimed['lease_token'],$claimed['input_sha256'],'Different text.');
                else synth_memory_complete($this->database,$this->jobId,$claimed['lease_token'],'Different text.');
                $this->fail('Accepted text overwritten');
            } catch (LogicException $error) {$this->assertSame('Native memory checkpoint mismatch',$error->getMessage());}
        }
        $this->assertSame('Accepted text.',json_decode($this->job()['payload'],true)['accepted_summary']['summary']);
        $this->assertSame('running',$this->job()['state']);
        $this->assertSame('0',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.memory_summaries WHERE job_id=$1',[$this->jobId]))['n']);
    }

    public function testExpiredAndReplacedLeasesCannotWriteCheckpoints(): void
    {
        $old=synth_memory_claim($this->database,$this->jobId);
        $this->assertNotNull($old);
        $this->expireLease();
        foreach ([false,true] as $replace) {
            if ($replace) $this->assertNotNull(synth_memory_claim($this->database,$this->jobId));
            try {
                synth_memory_checkpoint($this->database,$this->jobId,$old['lease_token'],$old['input_sha256'],'Late text.');
                $this->fail('Old lease accepted');
            } catch (SYNTHProtocolCancelled $error) {$this->assertSame('memory_lease_lost',$error->getMessage());}
        }
        $this->assertArrayNotHasKey('accepted_summary',json_decode($this->job()['payload'],true));
    }

    public function testLeaseLostDuringGenerationCannotCheckpointOrEmbed(): void
    {
        $embedded=0;
        try {
            synth_memory_process_job($this->database,$this->jobId,function() {$this->expireLease();return 'Late accepted text.';},
                static function() use (&$embedded) {++$embedded;return null;});
            $this->fail('Expired generation accepted');
        } catch (SYNTHProtocolCancelled $error) {$this->assertSame('memory_lease_lost',$error->getMessage());}
        $this->assertSame(0,$embedded);
        $this->assertArrayNotHasKey('accepted_summary',json_decode($this->job()['payload'],true));
    }

    #[PHPUnit\Framework\Attributes\DataProvider('sourceInvalidationProvider')]
    public function testSavedSourcesStillFenceCheckpointRecovery(string $case): void
    {
        $this->interruptAfterAcceptedText();
        switch ($case) {
            case 'generation': $this->query("UPDATE synth_meta.runtime_sessions SET active_generation=2 WHERE session_id='memory-recovery-session'");break;
            case 'frontier': $this->query("UPDATE synth_meta.context_snapshots SET history_event_id=0 WHERE session_id='memory-recovery-session' AND snapshot_sequence=2");break;
            case 'source': $this->query("UPDATE synth_meta.history_events SET event_payload=jsonb_set(event_payload,'{people}','\"|Other Actor|\"'::jsonb) WHERE id=$1",[$this->historyId]);break;
            case 'archive': $this->query('INSERT INTO synth_meta.memory_batch_controls(batch_id,archived,revision) VALUES($1,true,1)',[$this->batchId]);break;
        }
        $called=0;
        try {
            $this->assertNull(synth_memory_process_job($this->database,$this->jobId,static function() use (&$called) {++$called;return 'Forbidden summary.';}));
        } catch (SYNTHProtocolCancelled $error) {$this->assertSame('generation',$case);}
        $this->assertSame(0,$called);
        $this->assertSame('0',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.memory_summaries WHERE job_id=$1',[$this->jobId]))['n']);
        $this->assertArrayHasKey('accepted_summary',json_decode($this->job()['payload'],true));
    }
    public static function sourceInvalidationProvider(): array {return ['generation'=>['generation'],'frontier'=>['frontier'],'source'=>['source'],'archive'=>['archive']];}

    public function testOptionalEmbeddingFailureRetainsAcceptedText(): void
    {
        $this->assertNotNull(synth_memory_process_job($this->database,$this->jobId,static fn()=>'  Text-only accepted summary.  ',
            static function(): never {throw new RuntimeException('Synthetic optional embedding failure');}));
        $summary=pg_fetch_assoc($this->query('SELECT summary,embedding FROM synth_meta.memory_summaries WHERE job_id=$1',[$this->jobId]));
        $this->assertSame('Text-only accepted summary.',$summary['summary']);
        $this->assertNull($summary['embedding']);
        $this->assertSame('Text-only accepted summary.',json_decode($this->job()['payload'],true)['accepted_summary']['summary']);
    }

    public function testOlderDirectPublicationWithoutCheckpointRemainsSupported(): void
    {
        $claimed=synth_memory_claim($this->database,$this->jobId);
        $this->assertNotNull($claimed);
        $this->assertArrayNotHasKey('accepted_summary',json_decode($this->job()['payload'],true));
        $this->assertGreaterThan(0,synth_memory_complete($this->database,$this->jobId,$claimed['lease_token'],'Older direct accepted text.'));
        $this->assertSame('ready',$this->job()['state']);
    }

    public function testThirdAttemptCanPublishCheckpointWithoutResettingItsBudget(): void
    {
        $this->interruptAfterAcceptedText();
        $deadline=$this->job()['deadline_at'];$calls=0;
        $generate=static function() use (&$calls) {++$calls;return 'Forbidden regeneration.';};
        try {
            synth_memory_process_job($this->database,$this->jobId,$generate,static function(): never {throw new SYNTHProtocolCancelled('memory_lease_lost');});
            $this->fail('Expected second interrupted embedding');
        } catch (SYNTHProtocolCancelled $error) {$this->assertSame('memory_lease_lost',$error->getMessage());}
        $this->expireLease();
        $this->assertNotNull(synth_memory_process_job($this->database,$this->jobId,$generate));
        $this->assertSame(0,$calls);
        $this->assertSame('3',$this->job()['attempts']);
        $this->assertSame($deadline,$this->job()['deadline_at']);
        $this->assertSame('ready',$this->job()['state']);
    }

    public function testCheckpointDoesNotExtendDeadlineOrExhaustedAttemptBudget(): void
    {
        $this->interruptAfterAcceptedText();
        $this->query('UPDATE synth_meta.worker_jobs SET attempts=3 WHERE id=$1',[$this->jobId]);
        $calls=0;
        $this->assertNull(synth_memory_process_job($this->database,$this->jobId,static function() use (&$calls) {++$calls;return 'Forbidden fourth attempt.';}));
        $this->assertSame(0,$calls);
        $this->assertSame('3',$this->job()['attempts']);
        $this->assertSame('failed',$this->job()['state']);
        $this->assertArrayHasKey('accepted_summary',json_decode($this->job()['payload'],true));
    }

    public function testExpiredCheckpointDeadlineCannotRegenerateOrExtend(): void
    {
        $this->interruptAfterAcceptedText();
        $this->query("UPDATE synth_meta.worker_jobs SET deadline_at=clock_timestamp()-INTERVAL '1 second' WHERE id=$1",[$this->jobId]);
        $deadline=$this->job()['deadline_at'];$calls=0;
        $this->assertNull(synth_memory_process_job($this->database,$this->jobId,static function() use (&$calls) {++$calls;return 'Late summary.';}));
        $this->assertSame(0,$calls);
        $this->assertSame($deadline,$this->job()['deadline_at']);
        $this->assertSame('failed',$this->job()['state']);
        $this->assertSame('1',$this->job()['attempts']);
    }

    public function testArchiveDuringEmbeddingStillPreventsCheckpointPublication(): void
    {
        try {
            synth_memory_process_job($this->database,$this->jobId,static fn()=>'Accepted before archive.',function() {
                $this->query('INSERT INTO synth_meta.memory_batch_controls(batch_id,archived,revision) VALUES($1,true,1)',[$this->batchId]);
                return null;
            });
            $this->fail('Archived checkpoint published');
        } catch (SYNTHProtocolCancelled $error) {$this->assertSame('memory_lease_lost',$error->getMessage());}
        $this->assertArrayHasKey('accepted_summary',json_decode($this->job()['payload'],true));
        $this->assertSame('running',$this->job()['state']);
        $this->assertSame('0',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.memory_summaries WHERE job_id=$1',[$this->jobId]))['n']);
    }

    public function testPriorSummaryCorrectionStillFencesCheckpointRecovery(): void
    {
        [$summaryId]=$this->attachEarlierSummary();
        $this->interruptAfterAcceptedText();
        $this->query('INSERT INTO synth_meta.memory_summary_revisions(summary_id,revision,summary) VALUES($1,1,$2)',[$summaryId,'Corrected prior reflection.']);
        $calls=0;
        $this->assertNull(synth_memory_process_job($this->database,$this->jobId,static function() use (&$calls) {++$calls;return 'Stale descendant.';}));
        $this->assertSame(0,$calls);
        $this->assertSame('0',pg_fetch_assoc($this->query('SELECT count(*) AS n FROM synth_meta.memory_summaries WHERE job_id=$1',[$this->jobId]))['n']);
    }
}
