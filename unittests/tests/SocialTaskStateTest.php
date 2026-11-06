<?php declare(strict_types=1);
require_once __DIR__.'/DatabaseTestCase.php';
require_once dirname(__DIR__,2).'/lib/request.php';
require_once dirname(__DIR__,2).'/lib/synth_social_tasks.php';
final class SocialTaskFixtureDatabase
{
    public int $locks=0;
    private ?array $lockOwner=null;
    public function __construct(public $connection) {}
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
}


final class SocialTaskStateTest extends DatabaseTestCase {
    private SocialTaskFixtureDatabase $fixtureDb;private array $player,$performer,$recipient,$payload,$event;private int $serial=0;private array $saved=[];
    public function setUp(): void {
        parent::setUp();foreach(['db','SYNTH_REQUEST_EVENT','SYNTH_PROTOCOL_REQUEST_CLAIMED'] as $key)$this->saved[$key]=[array_key_exists($key,$GLOBALS),$GLOBALS[$key]??null];
        $connection=pg_connect('host=localhost dbname=testdb user=dwemer password=dwemer',PGSQL_CONNECT_FORCE_NEW);$this->assertNotFalse($connection);
        $this->fixtureDb=new SocialTaskFixtureDatabase($connection);$this->fixtureDb->execQuery('SET search_path TO synth,synth_meta,public');$GLOBALS['db']=$this->fixtureDb;$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=true;
        $this->player=$this->actor('0x00000014','Nora');$this->performer=$this->actor('0x00000015','Codsworth');$this->recipient=$this->actor('0x00000016','Jun');
        $state=static fn($a,$x,$f)=>['actor'=>$a,'alive'=>true,'disabled'=>false,'in_combat'=>false,'hostile_to_player'=>false,'teammate'=>$f,'position'=>['x'=>$x,'y'=>0,'z'=>0]];
        $this->payload=['playthrough_id'=>'social-save','player'=>$this->player,'target'=>$this->performer,'audience'=>[$this->performer,$this->recipient],
          'actor_states'=>[$state($this->performer,0,true),$state($this->recipient,200,false)],'world'=>['location'=>'Sanctuary','location_ref'=>['form_id'=>'0x00000020','origin_plugin'=>'Fallout4.esm'],'game_time_ticks'=>1000]];
        $this->session('social-session',1);$this->snapshot('social-session',1,1,0);
        $this->select('social-session',1,1,'input_text',['text'=>'Go talk to Jun about the water pump.']);
    }
    public function tearDown(): void {if(isset($this->fixtureDb))pg_close($this->fixtureDb->connection);unset($GLOBALS['db']);parent::tearDown();foreach($this->saved as $key=>[$exists,$value]){if($exists)$GLOBALS[$key]=$value;else unset($GLOBALS[$key]);}}
    private function actor($form,$name): array {$a=['form_id'=>$form,'origin_plugin'=>'Fallout4.esm','playthrough_id'=>'social-save','display_name'=>$name];synth_protocol_actor_identity_id($this->fixtureDb,$a);return $a;}
    private function json($v): string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
    private function session($id,$gen): void {$this->fixtureDb->execQuery("INSERT INTO synth_meta.runtime_sessions(session_id,playthrough_id,game,runtime_variant,client_version,runtime_version,active_generation,capabilities) VALUES($1,'social-save','fo4','flat','fixture','fixture',$2,$3::jsonb)",[$id,$gen,$this->json(['context.saved_anchor','dialogue.turn_ownership','social.tasks.v1','context.location_identity','dialogue.rechat.ownership'])]);}
    private function snapshot($session,$gen,$seq,$frontier,$anchor=null): void {$payload=$this->payload+['snapshot_sequence'=>$seq];if($anchor)$payload['loaded_context']=$anchor;
      $this->fixtureDb->execQuery('INSERT INTO synth_meta.context_snapshots(session_id,generation,snapshot_sequence,playthrough_id,player_identity_id,target_identity_id,audience_identity_ids,context_payload,history_event_id) VALUES($1,$2,$3,$4,$5,$6,$7::jsonb,$8::jsonb,$9)',[$session,$gen,$seq,'social-save',synth_protocol_actor_identity_id($this->fixtureDb,$this->player),synth_protocol_actor_identity_id($this->fixtureDb,$this->performer),$this->json([synth_protocol_actor_identity_id($this->fixtureDb,$this->performer),synth_protocol_actor_identity_id($this->fixtureDb,$this->recipient)]),$this->json($payload),$frontier]);}
    private function select($session,$gen,$seq,$type,$payload,$turn=null): void {$request='social-test:'.(++$this->serial);$this->event=['schema'=>'synth.event.v2','protocol_version'=>2,'type'=>$type,'session_id'=>$session,'request_id'=>$request,'turn_id'=>$turn??'turn:'.$request,'generation'=>$gen,'context_sequence'=>$seq,'game'=>'fo4','runtime_variant'=>'flat','client_version'=>'fixture','runtime_version'=>'fixture','capabilities'=>['social.tasks.v1','context.saved_anchor','dialogue.turn_ownership','context.location_identity','dialogue.rechat.ownership'],'payload'=>$payload];$canonical=synth_protocol_canonical_json($this->event);$hash=hash('sha256',$canonical);
      $this->fixtureDb->execQuery("INSERT INTO synth_meta.request_executions(session_id,request_id,turn_id,generation,request_sha256,event_type,state) VALUES($1,$2,$3,$4,$5,$6,'claimed')",[$session,$request,$this->event['turn_id'],$gen,$hash,$type]);
      $this->fixtureDb->execQuery('INSERT INTO synth_meta.protocol_events(session_id,request_id,turn_id,generation,event_type,canonical_event,event_sha256) VALUES($1,$2,$3,$4,$5,$6::jsonb,$7)',[$session,$request,$this->event['turn_id'],$gen,$type,$canonical,$hash]);
      $this->fixtureDb->execQuery('UPDATE synth_meta.runtime_sessions SET active_turn_id=$2,active_turn_sequence=$3 WHERE session_id=$1',[$session,$this->event['turn_id'],$seq]);$GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$this->event];}
    private function finish(): void {$this->fixtureDb->execQuery("UPDATE synth_meta.request_executions SET state='complete' WHERE session_id=$1 AND request_id=$2",[$this->event['session_id'],$this->event['request_id']]);}
    private function progress($task,$phase,$exec='visit:social-session:1:1'): array {$this->select($this->event['session_id'],$this->event['generation'],$this->event['context_sequence'],'social_task_progress',['task_id'=>$task['task_id'],'expected_revision'=>$task['revision'],'execution_id'=>$exec,'phase'=>$phase]);$next=synth_social_progress($this->fixtureDb,$this->event);$this->finish();return $next;}
    public function testCanonicalInputAndProgressAreSavedWithOwnedDiaryNoteAndFreshArrival(): void {
      $source=$this->event;$task=synth_social_admit_from_input($this->fixtureDb,$this->event);$this->assertSame('ready',$task['status']);$this->assertSame('Jun',$task['recipient']['display_name']);$this->finish();
      $this->assertSame($source,$this->event);$this->assertCount(1,synth_social_visible($this->fixtureDb,$this->event));
      $notes=synth_social_diary_notes($this->fixtureDb,$this->event,$this->performer);$this->assertCount(1,$notes);$this->assertStringContainsString('the water pump',$notes[0]['content']);$this->assertSame([],synth_social_diary_notes($this->fixtureDb,$this->event,$this->recipient));
      require_once dirname(__DIR__,2).'/lib/chat_helper_functions.php';
      $block=synthBuildLatestDiaryContextBlock('Codsworth',['actor_identity_id'=>synth_protocol_actor_identity_id($this->fixtureDb,$this->performer)]);
      $this->assertStringContainsString('<saved_conversation_tasks>',$block);$this->assertStringContainsString('the water pump',$block);
      $this->assertSame('',synthBuildSavedSocialTaskContextBlock('Jun',['actor_identity_id'=>synth_protocol_actor_identity_id($this->fixtureDb,$this->recipient)]));
      $task=$this->progress($task,'started');$this->assertSame('travelling',$task['status']);$task=$this->progress($task,'arrived');$this->assertSame('arrived',$task['status']);
      $this->assertTrue(synth_social_arrival_parent_valid($this->fixtureDb->connection,$this->event));$this->assertSame('the water pump',synth_social_exchange_context($this->fixtureDb,$this->event)['topic']);
      $this->select('social-session',1,1,'social_task_progress',['task_id'=>$task['task_id'],'expected_revision'=>$task['revision'],'execution_id'=>$task['execution_id'],'phase'=>'spoken','proof'=>['request_id'=>'invented','line_id'=>'invented']]);
      try{synth_social_progress($this->fixtureDb,$this->event);$this->fail('Invented dialogue completed task');}catch(DomainException $e){$this->assertSame('social_conversation_not_delivered',$e->getMessage());}
    }
    public function testOldSaveBeforeInputCannotSeeTaskAndSaveBeforeCompletionResumesWithNewClaim(): void {
      $task=synth_social_admit_from_input($this->fixtureDb,$this->event);$this->finish();$this->snapshot('social-session',1,2,$task['revision']);$this->select('social-session',1,2,'input_text',['text'=>'Continue.']);
      $task=$this->progress($task,'started');$savedRevision=$task['revision'];$this->snapshot('social-session',1,3,$savedRevision);
      $task=$this->progress($task,'cancelled');$this->assertSame('cancelled',$task['status']);
      $this->session('before-task',1);$this->snapshot('before-task',1,1,0,['session_id'=>'social-session','generation'=>1,'context_sequence'=>1,'playthrough_id'=>'social-save']);$this->select('before-task',1,1,'input_text',['text'=>'Hello.']);$this->assertSame([],synth_social_offers($this->fixtureDb,$this->event));
      $this->session('resume-task',1);$this->snapshot('resume-task',1,1,0,['session_id'=>'social-session','generation'=>1,'context_sequence'=>3,'playthrough_id'=>'social-save']);$this->select('resume-task',1,1,'input_text',['text'=>'Hello.']);
      $offers=synth_social_offers($this->fixtureDb,$this->event);$this->assertCount(1,$offers);$this->assertSame('ready',$offers[0]['status']);$this->assertArrayNotHasKey('execution_id',$offers[0]);$this->finish();
      $new=$this->progress($offers[0],'started','visit:resume-task:1:1');$this->assertSame('travelling',$new['status']);$this->assertNotSame($task['execution_id'],$new['execution_id']);
    }
    public function testCanonicalInputCannotBeChangedAfterAdmissionAndFutureSnapshotDoesNotEnterOldSave(): void {
      $forged=$this->event;$forged['payload']['text']='Go talk to Jun about forged work.';$GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$forged];
      try{synth_social_admit_from_input($this->fixtureDb,$forged);$this->fail('Forged canonical input accepted');}catch(DomainException $e){$this->assertSame('social_canonical_owner_missing',$e->getMessage());}
      $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$this->event];
      $task=synth_social_admit_from_input($this->fixtureDb,$this->event);$this->finish();
      $this->payload['audience'][]=$this->actor('0x00000017','Marcy');$this->snapshot('social-session',1,2,$task['revision']);
      $this->session('old-observation',1);$this->snapshot('old-observation',1,1,0,['session_id'=>'social-session','generation'=>1,'context_sequence'=>1,'playthrough_id'=>'social-save']);
      $this->select('old-observation',1,1,'input_text',['text'=>'Hello.']);
      // This current test snapshot also observes Marcy, so strip her before checking the ancestry selector.
      $this->fixtureDb->execQuery("UPDATE synth_meta.context_snapshots SET context_payload=jsonb_set(context_payload,'{audience}',$1::jsonb) WHERE session_id='old-observation'",[$this->json([$this->performer,$this->recipient])]);
      $contexts=synth_social_known_contexts($this->fixtureDb,$this->event);
      try{synth_social_resolve_recipient($contexts,$this->performer,'Marcy');$this->fail('A later unsaved observation leaked');}catch(DomainException $e){$this->assertSame('social_recipient_unknown',$e->getMessage());}
    }
    public function testDeferredReturnAndExpiredUnacknowledgedClaimRemainSaveScopedAndResumable(): void {
      $this->payload['world']=['location'=>'Road','location_ref'=>['form_id'=>'0x00000021','origin_plugin'=>'Fallout4.esm'],'game_time_ticks'=>1100];
      $this->snapshot('social-session',1,2,0);$this->select('social-session',1,2,'input_text',['text'=>'When we get back to Sanctuary, talk to Jun about the pump.']);
      $task=synth_social_admit_from_input($this->fixtureDb,$this->event);$this->finish();$this->assertSame('waiting_return',$task['status']);
      $this->payload['world']=['location'=>'Sanctuary','location_ref'=>['form_id'=>'0x00000020','origin_plugin'=>'Fallout4.esm'],'game_time_ticks'=>1200];
      $this->snapshot('social-session',1,3,$task['revision']);$this->select('social-session',1,3,'input_text',['text'=>'We are back.']);
      $offers=synth_social_offers($this->fixtureDb,$this->event);$this->assertSame('ready',$offers[0]['status']);$this->finish();
      $task=$this->progress($offers[0],'started');
      $this->fixtureDb->execQuery("UPDATE synth_meta.social_task_claims SET deadline_at=clock_timestamp()-INTERVAL '1 second'");
      $this->select('social-session',1,3,'input_text',['text'=>'What next?']);
      $offers=synth_social_offers($this->fixtureDb,$this->event);$this->assertSame('ready',$offers[0]['status']);$this->assertArrayNotHasKey('execution_id',$offers[0]);$this->finish();
      $new=$this->progress($offers[0],'started','visit:retry:1:2');$this->assertSame('travelling',$new['status']);
    }
    public function testRecipientReplyNeedsExactPersistedArrivalAncestryAndWireLine(): void {
      $task=synth_social_admit_from_input($this->fixtureDb,$this->event);$this->finish();$task=$this->progress($task,'started');$task=$this->progress($task,'arrived');$arrival=$this->event;
      $this->select('social-session',1,1,'trigger',['kind'=>'rechat','instruction'=>'','reply_to_request_id'=>$arrival['request_id'],'reply_to_line_id'=>'follower-first-line']);
      $reply=$this->event;$line=$this->json(['line_id'=>'recipient-reply:1','type'=>'dialogue','payload'=>['text'=>'We need to repair it.']])."\n";
      $this->fixtureDb->execQuery("UPDATE synth_meta.request_executions SET state='complete',response_body=decode($3,'hex') WHERE session_id=$1 AND request_id=$2",[$reply['session_id'],$reply['request_id'],bin2hex($line)]);
      $this->fixtureDb->execQuery("INSERT INTO synth_meta.response_routes(session_id,request_id,line_id,line_sha256,generation,context_sequence,speaker,listener_state,listener) VALUES($1,$2,$3,$4,1,1,$5::jsonb,'addressed',$6::jsonb)",[$reply['session_id'],$reply['request_id'],'recipient-reply:1',hash('sha256',$line),$this->json($this->recipient),$this->json($this->performer)]);
      $this->select('social-session',1,1,'social_task_progress',['task_id'=>$task['task_id'],'expected_revision'=>$task['revision'],'execution_id'=>$task['execution_id'],'phase'=>'spoken','proof'=>['request_id'=>$reply['request_id'],'line_id'=>'recipient-reply:1']]);
      $done=synth_social_progress($this->fixtureDb,$this->event);$this->assertSame('completed',$done['status']);$this->finish();
      $this->assertSame('completed',synth_social_visible($this->fixtureDb,$this->event)[$task['task_id']]['task']['status']);
      $this->assertFalse(synth_social_spoken_proof($this->fixtureDb->connection,$this->event,$task,['request_id'=>$arrival['request_id'],'line_id'=>'follower-first-line']));
    }
    public function testProductionArrivalEmitterPreparesOnlyAddressedRecipientRechatAndItsRealRouteCanComplete(): void {
      require_once dirname(__DIR__,2).'/lib/synth_social_response.php';require_once dirname(__DIR__,2).'/lib/synth_rechat_prepare.php';
      foreach([$this->performer,$this->recipient] as $actor)$this->fixtureDb->execQuery('INSERT INTO synth.core_npc_master(npc_name,actor_identity_id,profile_id) VALUES($1,$2,1)',[$actor['display_name'],synth_protocol_actor_identity_id($this->fixtureDb,$actor)]);
      $task=synth_social_admit_from_input($this->fixtureDb,$this->event);$this->finish();$acceptedRevision=$task['revision'];
      $this->snapshot('social-session',1,2,$acceptedRevision);$this->select('social-session',1,2,'input_text',['text'=>'Checkpoint after accepting the task.']);$this->finish();$task=$this->progress($task,'started');
      $this->select('social-session',1,2,'social_task_progress',['task_id'=>$task['task_id'],'expected_revision'=>$task['revision'],'execution_id'=>$task['execution_id'],'phase'=>'arrived']);
      $arrival=$this->event;
      $keys=['SYNTH_RESPONSE_FORMAT','SYNTH_RESPONSE_STREAMING','TTSFUNCTION','SYNTH_CLIENT_CAPABILITIES','SYNTH_PROTOCOL_RESPONSE_BODY','SYNTH_PROTOCOL_RESPONSE_STARTED','SYNTH_PROTOCOL_RESPONSE_SEQUENCE','SYNTH_JSON_RESPONSE_LINES','SYNTH_JSON_RESPONSE_STREAM_FINAL_EMITTED','SYNTH_NAME','SYNTH_SPEECH_ROLE','SYNTH_INPUT_TARGET','SYNTH_INPUT_TARGET_IDENTITY','SYNTH_RESPONSE_SPEAKER_FORMID'];$save=[];foreach($keys as $key)$save[$key]=[array_key_exists($key,$GLOBALS),$GLOBALS[$key]??null];
      try {
        $GLOBALS['SYNTH_RESPONSE_FORMAT']='json';$GLOBALS['SYNTH_RESPONSE_STREAMING']=true;$GLOBALS['TTSFUNCTION']='none';$GLOBALS['SYNTH_CLIENT_CAPABILITIES']=$arrival['capabilities'];$GLOBALS['SYNTH_REQUEST_EVENT']=synth_normalize_json_event($arrival);
        $source=file_get_contents(dirname(__DIR__,2).'/main_synth_pipeline.php');
        // Build the marker literally: PHP must not interpolate the production variable in the test.
        $marker='    if (in_array($wireEvent[\'type\'] ?? \'\',[\'social_task_request\',\'social_task_progress\'],true)) {';$begin=strpos($source,$marker);$end=strpos($source,"        exit;\n    }",$begin);
        $this->assertIsInt($begin);$this->assertIsInt($end);$dispatch=substr($source,$begin,$end-$begin)."\n    }";
        $db=$this->fixtureDb;$wireEvent=$arrival;$path=dirname(__DIR__,2).'/';
        ob_start(static fn($chunk)=>'');try{eval($dispatch);}finally{ob_end_clean();}
        $lines=array_map(static fn($line)=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),explode("\n",trim($GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY'])));
        $this->assertSame(['response_start','social_task','dialogue','response_end'],array_column($lines,'type'));
        $first=$lines[2];$this->assertEquals($this->performer,$first['payload']['speaker']);$this->assertSame('I wanted to talk with you about the water pump.',$first['payload']['text']);
        $route=$this->fixtureDb->fetchOne('SELECT listener_state,listener FROM synth_meta.response_routes WHERE request_id='.$this->fixtureDb->escapeLiteral($arrival['request_id']));
        $this->assertSame('addressed',$route['listener_state']);$this->assertEquals($this->recipient,json_decode($route['listener'],true));
        $this->select('social-session',1,2,'trigger',['kind'=>'rechat','actor'=>$this->performer,'reply_to_request_id'=>$arrival['request_id'],'reply_to_line_id'=>$first['line_id']],$arrival['turn_id']);
        // The fixture session negotiated ownership; no provider/model is used in this coordinator test.
        $GLOBALS['SYNTH_REQUEST_EVENT']=synth_normalize_json_event($this->event);$reply=$this->event;
        $proof=synth_rechat_completed_parent($this->fixtureDb,$reply);$this->assertNotNull($proof);
        $prepared=synth_rechat_prepare_continuation($this->fixtureDb,$reply);$this->assertSame('npc',$prepared['selection']['role']);$this->assertEquals($this->recipient,$prepared['selection']['identity']);
        $again=synth_rechat_prepare_continuation($this->fixtureDb,$reply);$this->assertSame($prepared['selection']['identity'],$again['selection']['identity']);
        $GLOBALS['SYNTH_NAME']='Jun';$GLOBALS['SYNTH_INPUT_TARGET']='Jun';$GLOBALS['SYNTH_INPUT_TARGET_IDENTITY']=$this->recipient;$GLOBALS['SYNTH_SPEECH_ROLE']='npc';$GLOBALS['SYNTH_RESPONSE_SPEAKER_FORMID']=$this->recipient['form_id'];
        ob_start(static fn($chunk)=>'');try{synth_start_json_response_buffer();synth_buffer_response_line('Jun','say','We should repair the pump.',['listener'=>'Codsworth','listener_formid'=>$this->performer['form_id'],'listener_plugin'=>'Fallout4.esm','text_only'=>true],'npc');synth_emit_buffered_json_response();}finally{ob_end_clean();}
        $response=array_map(static fn($line)=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),explode("\n",trim($GLOBALS['SYNTH_PROTOCOL_RESPONSE_BODY'])));$spoken=$response[1];$this->assertSame('dialogue',$spoken['type']);$this->assertEquals($this->recipient,$spoken['payload']['speaker']);
        $this->select('social-session',1,2,'social_task_progress',['task_id'=>$task['task_id'],'expected_revision'=>$task['revision'],'execution_id'=>$task['execution_id'],'phase'=>'spoken','proof'=>['request_id'=>$reply['request_id'],'line_id'=>$spoken['line_id']]]);
        $done=synth_social_progress($this->fixtureDb,$this->event);$this->assertSame('completed',$done['status']);$this->assertSame('0',$this->fixtureDb->fetchOne('SELECT count(*) AS n FROM synth_meta.action_intents')['n']);
        $this->finish();$this->snapshot('social-session',1,3,$done['revision']);
        $this->session('restore-pending-checkpoint',1);$this->snapshot('restore-pending-checkpoint',1,1,0,['session_id'=>'social-session','generation'=>1,'context_sequence'=>2,'playthrough_id'=>'social-save']);$this->select('restore-pending-checkpoint',1,1,'input_text',['text'=>'Earlier save.']);
        $pending=synth_social_offers($this->fixtureDb,$this->event);$this->assertCount(1,$pending);$this->assertSame('ready',$pending[0]['status']);$this->assertArrayNotHasKey('execution_id',$pending[0]);$this->finish();
        $this->session('restore-completed-checkpoint',1);$this->snapshot('restore-completed-checkpoint',1,1,0,['session_id'=>'social-session','generation'=>1,'context_sequence'=>3,'playthrough_id'=>'social-save']);$this->select('restore-completed-checkpoint',1,1,'input_text',['text'=>'Newer save.']);
        $completed=synth_social_offers($this->fixtureDb,$this->event);$this->assertCount(1,$completed);$this->assertSame('completed',$completed[0]['status']);

      } finally {foreach($save as $key=>[$exists,$value]){if($exists)$GLOBALS[$key]=$value;else unset($GLOBALS[$key]);}}
    }
    public function testAudioAdmissionUsesOnlyDurablyCapturedTranscriptionAndCanonicalSourceMedia(): void {
      require_once dirname(__DIR__,2).'/lib/synth_dialogue_policy.php';
      $media=json_decode(file_get_contents(dirname(__DIR__,2).'/protocol/v2/fixtures/valid/input-audio.json'),true,flags:JSON_THROW_ON_ERROR)['payload']['media'];
      $this->select('social-session',1,1,'input_audio',['media'=>$media]);$source=$this->event;$text='Go talk to Jun about repairs.';
      try{synth_social_admit_from_input($this->fixtureDb,$source,$text);$this->fail('Unbound transcript accepted');}catch(DomainException $e){$this->assertSame('social_transcript_not_captured',$e->getMessage());}
      synth_dialogue_capture_origin_policy($this->fixtureDb,$source,['RECHAT_H'=>1,'RECHAT_P'=>100,'RECHAT_MODE'=>'tight','RECHAT_ALLOW_ACTIONS'=>false,'RANDOM_NARATION'=>false,'RANDOM_NARATION_CHANCE'=>0,'RANDOM_NARRATION_COOLDOWN'=>0],'STANDARD',$text);
      $task=synth_social_admit_from_input($this->fixtureDb,$source,$text);$this->finish();$this->assertSame('ready',$task['status']);$this->assertSame($source,$this->event);$this->assertSame('input_audio',$source['type']);
      $this->assertCount(1,synth_social_visible($this->fixtureDb,$source));
    }
    public function testCorruptedCanonicalInputOrProgressCannotSupplySavedTaskAuthority(): void {
      $source=$this->event;$task=synth_social_admit_from_input($this->fixtureDb,$source);$this->finish();$task=$this->progress($task,'started');$progress=$this->event;
      foreach([$source,$progress] as $origin) {
        $this->fixtureDb->execQuery("UPDATE synth_meta.protocol_events SET canonical_event=jsonb_set(canonical_event,'{client_version}','\"corrupted\"'::jsonb) WHERE session_id=$1 AND request_id=$2",[$origin['session_id'],$origin['request_id']]);
        if($origin['type']==='input_text') {
          // A future bug could copy altered canonical bytes into its task row while retaining the old accepted request digest.
          $this->fixtureDb->execQuery("UPDATE synth_meta.social_task_inputs SET input_payload=jsonb_set(input_payload,'{owner}',(SELECT canonical_event FROM synth_meta.protocol_events WHERE session_id=$1 AND request_id=$2))",[$origin['session_id'],$origin['request_id']]);
          $this->fixtureDb->execQuery("UPDATE synth_meta.social_task_inputs SET input_sha256=encode(sha256(convert_to(input_payload::text,'UTF8')),'hex')");
        }
        try{synth_social_visible($this->fixtureDb,$this->event);$this->fail('Corrupted accepted source retained task authority');}catch(DomainException $e){$this->assertSame('social_source_corrupt',$e->getMessage());}
        $this->fixtureDb->execQuery('UPDATE synth_meta.protocol_events SET canonical_event=$3::jsonb WHERE session_id=$1 AND request_id=$2',[$origin['session_id'],$origin['request_id'],synth_protocol_canonical_json($origin)]);
        if($origin['type']==='input_text') {
          $this->fixtureDb->execQuery("UPDATE synth_meta.social_task_inputs SET input_payload=jsonb_set(input_payload,'{owner}',$1::jsonb)",[synth_protocol_canonical_json($origin)]);
          $this->fixtureDb->execQuery("UPDATE synth_meta.social_task_inputs SET input_sha256=encode(sha256(convert_to(input_payload::text,'UTF8')),'hex')");
        }
      }
      $this->assertCount(1,synth_social_visible($this->fixtureDb,$this->event));
    }
    public function testUnknownAndAmbiguousRecipientsAndWrongExecutionCannotMutate(): void {
      $this->select('social-session',1,1,'input_text',['text'=>'Go talk to Unknown about repairs.']);
      try{synth_social_admit_from_input($this->fixtureDb,$this->event);$this->fail('Unknown actor accepted');}catch(DomainException $e){$this->assertSame('social_recipient_unknown',$e->getMessage());}
      $this->select('social-session',1,1,'input_text',['text'=>'Go talk to Jun about repairs.']);$task=synth_social_admit_from_input($this->fixtureDb,$this->event);$this->finish();$task=$this->progress($task,'started');
      try{$this->progress($task,'arrived','visit:wrong:1:1');$this->fail('Foreign execution arrived');}catch(DomainException $e){$this->assertSame('social_execution_not_owned',$e->getMessage());}
    }
}
