<?php
require_once __DIR__.'/synth_social_contract.php';
require_once __DIR__.'/synth_protocol_state.php';

function synth_social_schema_ready($db): bool {
    $row=$db->fetchOne("SELECT to_regclass('synth_meta.social_task_inputs') IS NOT NULL AND to_regclass('synth_meta.social_task_events') IS NOT NULL AND to_regclass('synth_meta.social_task_claims') IS NOT NULL AS ready");
    return in_array($row['ready']??false,[true,'t','1',1],true);
}
function synth_social_scope_event(array $event): array {
    if (($event['type']??'')==='context') $event['context_sequence']=intval($event['payload']['snapshot_sequence']??0);
    return $event;
}
function synth_social_context($db,array $event): array {
    $context=synth_protocol_current_context($db,synth_social_scope_event($event));
    if (!$context || !is_array($context['player']??null)) throw new DomainException('social_context_unavailable');
    return $context;
}
function synth_social_owned($db,array $event,callable $operation): mixed {
    if (!synth_social_supported($event) || !synth_social_schema_ready($db)) throw new DomainException('social_tasks_unavailable');
    return $db->withNativeRequestLock(static function($connection,array $owner) use($event,$operation) {
        if ($owner!==$event) throw new LogicException('social_owner_changed');
        $canonical=synth_protocol_canonical_json($event);
        $proof=@pg_query_params($connection,"SELECT 1 FROM synth_meta.protocol_events p JOIN synth_meta.request_executions e USING(session_id,request_id)
            WHERE p.session_id=$1 AND p.request_id=$2 AND p.generation=$3 AND p.canonical_event=$4::jsonb
                AND p.event_sha256=$5 AND e.request_sha256=p.event_sha256 AND e.generation=p.generation
                AND p.event_type=$6 AND e.event_type=p.event_type AND e.state IN ('claimed','streaming','complete')",
            [$event['session_id'],$event['request_id'],$event['generation'],$canonical,hash('sha256',$canonical),$event['type']]);
        if (!$proof||!pg_fetch_assoc($proof))throw new DomainException('social_canonical_owner_missing');
        return $operation($connection);
    },2000,($event['type']??'')==='context');
}
// Live events after the last observation are visible only in their current runtime. Saved ancestors retain their exact cutoff.
function synth_social_visibility_sql($db,array $event,string $history): string {
    $event=synth_social_scope_event($event);$session=$db->escapeLiteral($event['session_id']);$generation=intval($event['generation']);$sequence=intval($event['context_sequence']);
    return "(EXISTS(SELECT 1 FROM native_history_scopes scope WHERE scope.session_id=$history.session_id AND scope.generation=$history.generation AND scope.playthrough_id=$history.playthrough_id AND $history.id<=scope.cutoff) OR ($history.session_id=$session AND $history.generation=$generation AND $history.context_sequence<=$sequence))";
}
function synth_social_visible($db,array $event): array {
    $scope=synth_social_scope_event($event);$context=synth_social_context($db,$event);
    $player=synth_protocol_actor_identity_id($db,$context['player']);
    $cte=synth_protocol_history_cte($db,$scope,'',null,true);
    $currentSession=$db->escapeLiteral($event['session_id']);$currentRequest=$db->escapeLiteral($event['request_id']);
    $inputVisible=synth_social_visibility_sql($db,$event,'input_history');$progressVisible=synth_social_visibility_sql($db,$event,'progress_history');
    $rows=$db->fetchAll($cte." SELECT * FROM (SELECT DISTINCT ON (i.task_id) i.task_id,i.input_payload,e.history_event_id,e.status,e.execution_id,e.event_payload,
            progress_history.session_id AS progress_session,progress_history.generation AS progress_generation,
            origin.event_sha256 AS input_origin_sha256,progress_origin.event_sha256 AS progress_origin_sha256,
            CASE WHEN octet_length(origin.canonical_event::text)<=1048576 THEN origin.canonical_event::text END AS input_origin,
            CASE WHEN octet_length(progress_origin.canonical_event::text)<=1048576 THEN progress_origin.canonical_event::text END AS progress_origin
        FROM synth_meta.social_task_inputs i JOIN synth_meta.history_events input_history ON input_history.id=i.history_event_id
        JOIN synth_meta.social_task_events e ON e.task_id=i.task_id
        JOIN synth_meta.history_events progress_history ON progress_history.id=e.history_event_id
        JOIN synth_meta.protocol_events origin ON origin.session_id=input_history.session_id AND origin.request_id=input_history.request_id
        JOIN synth_meta.request_executions accepted ON accepted.session_id=origin.session_id AND accepted.request_id=origin.request_id
        JOIN synth_meta.protocol_events progress_origin ON progress_origin.session_id=progress_history.session_id AND progress_origin.request_id=progress_history.request_id
        JOIN synth_meta.request_executions progress_execution ON progress_execution.session_id=progress_origin.session_id AND progress_execution.request_id=progress_origin.request_id
        WHERE i.player_identity_id=$player AND $inputVisible AND $progressVisible
            AND accepted.request_sha256=origin.event_sha256 AND accepted.state='complete'
            AND progress_execution.request_sha256=progress_origin.event_sha256 AND progress_execution.generation=progress_history.generation
            AND (progress_execution.state='complete' OR (progress_execution.state IN ('claimed','streaming') AND progress_origin.session_id=$currentSession AND progress_origin.request_id=$currentRequest))
            AND (e.prior_revision IS NULL OR EXISTS(SELECT 1 FROM synth_meta.social_task_events prior WHERE prior.history_event_id=e.prior_revision AND prior.task_id=e.task_id))
            AND (e.status NOT IN ('travelling','arrived','completed','cancelled','failed') OR
                (progress_origin.event_type='social_task_progress' AND progress_origin.canonical_event->'payload'->>'task_id'=i.task_id
                    AND progress_origin.canonical_event->'payload'->>'phase'=CASE e.status WHEN 'travelling' THEN 'started' WHEN 'completed' THEN 'spoken' ELSE e.status END
                    AND (e.execution_id IS NULL OR progress_origin.canonical_event->'payload'->>'execution_id'=e.execution_id)))
            AND origin.event_type IN ('social_task_request','input_text','input_audio')
            AND i.input_payload->'owner'=origin.canonical_event
            AND (origin.event_type<>'input_audio' OR EXISTS(SELECT 1 FROM synth_meta.dialogue_origin_policies transcript
                WHERE transcript.session_id=origin.session_id AND transcript.request_id=origin.request_id
                    AND transcript.input_text=i.input_payload->'source'->>'source_text'))
            AND i.input_sha256=encode(sha256(convert_to(i.input_payload::text,'UTF8')),'hex')
            AND e.event_payload->>'status'=e.status
            AND COALESCE(e.event_payload->'prior_revision','null'::jsonb)=COALESCE(to_jsonb(e.prior_revision),'null'::jsonb)
            AND e.event_payload->>'execution_id' IS NOT DISTINCT FROM e.execution_id
            AND e.event_sha256=encode(sha256(convert_to(e.event_payload::text,'UTF8')),'hex')
            AND progress_history.event_payload->>'social_task_id'=i.task_id
            AND progress_history.event_payload->'social_task_state'=e.event_payload
        ORDER BY i.task_id,e.history_event_id DESC) latest_tasks
        ORDER BY CASE WHEN status IN ('completed','cancelled','failed') THEN 1 ELSE 0 END,history_event_id DESC LIMIT 16") ?: [];
    $result=[];$verified=[];$pending=0;
    foreach ($rows as $row) {
        foreach (['input_origin','progress_origin'] as $source) {
            $raw=$row[$source]??null;$hash=$row[$source.'_sha256']??'';
            if (!is_string($raw)||strlen($raw)>1048576||!is_string($hash))throw new DomainException('social_source_corrupt');
            $cache=$hash.'|'.hash('sha256',$raw);
            if (!isset($verified[$cache])) {
                $canonical=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
                if (!is_array($canonical)||!hash_equals($hash,hash('sha256',synth_protocol_canonical_json($canonical))))
                    throw new DomainException('social_source_corrupt');
                $verified[$cache]=true;
            }
        }
        if (!in_array($row['status'],['completed','cancelled','failed'],true)&&++$pending>8)throw new DomainException('social_task_capacity');
        $input=json_decode($row['input_payload'],true,flags:JSON_THROW_ON_ERROR);
        $task=array_intersect_key($input,array_fill_keys(['schema','task_id','performer','recipient','topic','when','location_name','location_ref'],true));
        $task['revision']=intval($row['history_event_id']);$task['status']=$row['status'];
        if ($row['execution_id']!==null) $task['execution_id']=$row['execution_id'];
        $result[$row['task_id']]=['task'=>$task,'session_id'=>$row['progress_session'],'generation'=>intval($row['progress_generation'])];
    }
    return $result;
}
function synth_social_known_contexts($db,array $event): array {
    $event=synth_social_scope_event($event);$session=$db->escapeLiteral($event['session_id']);$generation=intval($event['generation']);$sequence=intval($event['context_sequence']);
    $cte=synth_protocol_history_cte($db,$event,'',null,true);
    $rows=$db->fetchAll($cte." SELECT DISTINCT c.context_payload FROM synth_meta.context_snapshots c
        WHERE EXISTS(SELECT 1 FROM native_ancestry scope WHERE scope.session_id=c.session_id AND scope.generation=c.generation
            AND scope.playthrough_id=c.playthrough_id AND c.snapshot_sequence<=scope.snapshot_sequence AND c.history_event_id<=scope.history_event_id)
        OR (c.session_id=$session AND c.generation=$generation AND c.snapshot_sequence<=$sequence)") ?: [];
    return array_map(static fn($row)=>is_string($row['context_payload'])?json_decode($row['context_payload'],true,flags:JSON_THROW_ON_ERROR):$row['context_payload'],$rows);
}
function synth_social_resolve_recipient(array $contexts,array $performer,string $name): array {
    $matches=[];
    foreach ($contexts as $payload) foreach (array_merge([$payload['target']??null],$payload['audience']??[]) as $actor) {
        if (!is_array($actor) || ($actor['playthrough_id']??null)!==($performer['playthrough_id']??null)
            || strtoupper($actor['form_id']??'')==='0X00000014' || synth_social_actor_key($actor)===synth_social_actor_key($performer)
            || mb_strtolower(trim($actor['display_name']??''))!==mb_strtolower(trim($name))) continue;
        $matches[synth_social_actor_key($actor)]=$actor;
    }
    if (count($matches)!==1) throw new DomainException(count($matches)>1?'social_recipient_ambiguous':'social_recipient_unknown');
    return reset($matches);
}
function synth_social_resolve_location(array $contexts,array $recipient,string $name): array {
    $matches=[];
    foreach ($contexts as $payload) {
        $ref=synth_social_location($payload);if ($ref===null) continue;
        $label=$payload['world']['location']??'';
        if ($name!=='' && mb_strtolower(trim($label))!==mb_strtolower(trim($name))) continue;
        $seen=false;
        foreach (array_merge([$payload['target']??null],$payload['audience']??[]) as $actor)
            if (is_array($actor) && synth_social_actor_key($actor)===synth_social_actor_key($recipient)) $seen=true;
        if ($seen) $matches[synth_social_ref_key($ref)]=['ref'=>$ref,'name'=>$label];
    }
    if (count($matches)!==1) throw new DomainException(count($matches)>1?'social_destination_ambiguous':'social_destination_unobserved');
    return reset($matches);
}
function synth_social_history($connection,array $event,array $context,string $taskId,array $state,string $note): int {
    $scope=synth_social_scope_event($event);$now=time();$playthrough=$context['player']['playthrough_id'];
    $result=@pg_query_params($connection,"WITH publication AS (
        INSERT INTO synth.eventlog(type,data,sess,gamets,localts,ts,people,location,party,delivery_state,
            synth_session_id,synth_generation,synth_context_sequence,synth_request_id)
        VALUES('social_task_note',$1,$2,$3,$4,$4,'','','','derived',$5,$6,$7,$8) RETURNING *
      ) INSERT INTO synth_meta.history_events(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
        SELECT synth_session_id,synth_generation,synth_context_sequence,synth_request_id,sess,rowid,to_jsonb(publication)
            ||jsonb_build_object('social_task_id',$9::text,'social_task_state',$10::jsonb)
        FROM publication RETURNING id",
        [$note,$playthrough,intval($context['payload']['world']['game_time_ticks']??1),$now,$event['session_id'],$event['generation'],
         $scope['context_sequence'],$event['request_id'],$taskId,json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    if (!$result || !($row=pg_fetch_assoc($result))) throw new RuntimeException('Unable to publish owned social note');
    return intval($row['id']);
}
function synth_social_note(array $task,string $status): string {
    $who=$task['recipient']['display_name']??'the requested person';$where=$task['location_name']!==''?' at '.$task['location_name']:'';
    return 'Task: speak with '.$who.$where.' about '.$task['topic'].'. Status: '.$status.'. This records an accepted player instruction or verified progress; it is not proof of unseen conversation.';
}
function synth_social_append($connection,array $event,array $context,array $task,string $status,?string $execution=null): array {
    $state=['status'=>$status,'prior_revision'=>$task['revision']??null];if ($execution!==null)$state['execution_id']=$execution;
    $id=synth_social_history($connection,$event,$context,$task['task_id'],$state,synth_social_note($task,$status));
    if (!@pg_query_params($connection,"INSERT INTO synth_meta.social_task_events(history_event_id,task_id,prior_revision,status,execution_id,event_payload,event_sha256)
        VALUES($1,$2,$3,$4,$5,$6::jsonb,encode(sha256(convert_to(($6::jsonb)::text,'UTF8')),'hex'))",
        [$id,$task['task_id'],$task['revision']??null,$status,$execution,json_encode($state,JSON_THROW_ON_ERROR)]))throw new RuntimeException('Unable to persist social progress');
    $task['revision']=$id;$task['status']=$status;
    if ($execution===null)unset($task['execution_id']);else$task['execution_id']=$execution;
    return $task;
}
function synth_social_admit_locked($db,$connection,array $event,array $payload): array {
    if (!synth_social_request_valid($payload)) throw new DomainException('social_directive_not_explicit');
    $context=synth_social_context($db,$event);$performer=$context['target']??null;
    if (!is_array($performer) || synth_social_actor_key($performer)!==synth_social_actor_key($payload['performer']))throw new DomainException('social_performer_not_selected');
    $states=array_values(array_filter($context['payload']['actor_states']??[],static fn($s)=>is_array($s['actor']??null)&&synth_social_actor_key($s['actor'])===synth_social_actor_key($performer)));
    if (count($states)!==1 || ($states[0]['teammate']??false)!==true || ($states[0]['alive']??false)!==true
        || ($states[0]['disabled']??true)!==false || ($states[0]['hostile_to_player']??true)!==false)throw new DomainException('social_performer_not_follower');
    $visible=synth_social_visible($db,$event);$pending=array_filter($visible,static fn($row)=>!in_array($row['task']['status'],['completed','cancelled','failed'],true));
    if (count($pending)>=8)throw new DomainException('social_task_capacity');
    $contexts=$payload['when']==='now'?[$context['payload']]:synth_social_known_contexts($db,$event);
    $recipient=synth_social_resolve_recipient($contexts,$performer,$payload['recipient_name']);
    if ($payload['when']==='now'&&!synth_social_pair_ready($context,$performer,$recipient,2000))throw new DomainException('social_recipient_not_available');
    $destination=$payload['when']==='on_return'?synth_social_resolve_location($contexts,$recipient,$payload['location_name']??''):null;
    $task=['schema'=>'synth.social.task.v1','task_id'=>'social:'.substr(hash('sha256',synth_protocol_canonical_json($event)),0,32),
        'performer'=>$performer,'recipient'=>$recipient,'topic'=>$payload['topic'],'when'=>$payload['when'],
        'location_name'=>$destination['name']??''];
    if ($destination!==null)$task['location_ref']=$destination['ref'];
    $status=synth_social_initial_status($task['when'],synth_social_location($context),$destination['ref']??null);
    $state=['status'=>$status,'prior_revision'=>null];
    $history=synth_social_history($connection,$event,$context,$task['task_id'],$state,synth_social_note($task,$status));
    $playerId=synth_protocol_actor_identity_id($db,$context['player']);$performerId=synth_protocol_actor_identity_id($db,$performer);$recipientId=synth_protocol_actor_identity_id($db,$recipient);
    $input=$task+['source'=>$payload,'owner'=>$event];
    if (!@pg_query_params($connection,"INSERT INTO synth_meta.social_task_inputs(task_id,history_event_id,player_identity_id,performer_identity_id,recipient_identity_id,input_payload,input_sha256)
        VALUES($1,$2,$3,$4,$5,$6::jsonb,encode(sha256(convert_to(($6::jsonb)::text,'UTF8')),'hex'))",
        [$task['task_id'],$history,$playerId,$performerId,$recipientId,json_encode($input,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]))throw new RuntimeException('Unable to store accepted social task');
    $task['revision']=null;
    if (!@pg_query_params($connection,"INSERT INTO synth_meta.social_task_events(history_event_id,task_id,prior_revision,status,event_payload,event_sha256)
        VALUES($1,$2,NULL,$3,$4::jsonb,encode(sha256(convert_to(($4::jsonb)::text,'UTF8')),'hex'))",
        [$history,$task['task_id'],$status,json_encode($state,JSON_THROW_ON_ERROR)]))throw new RuntimeException('Unable to store initial social state');
    $task['revision']=$history;$task['status']=$status;return $task;
}
function synth_social_admit($db,array $event): array {
    if (($event['type']??'')!=='social_task_request')throw new DomainException('social_request_required');
    return synth_social_owned($db,$event,static fn($connection)=>synth_social_admit_locked($db,$connection,$event,$event['payload']));
}
function synth_social_admit_from_input($db,array $event,?string $transcript=null): ?array {
    if (!synth_social_supported($event)||!in_array($event['type']??'',['input_text','input_audio'],true)
        ||!in_array($event['payload']['control']['mode']??'STANDARD',['STANDARD','WHISPER','CLOSE','SHOUT'],true))return null;
    $text=$event['type']==='input_text'?($event['payload']['text']??null):$transcript;
    if (!is_string($text)||!synth_social_text($text,1200)||preg_match('/\b(?:talk|speak|chat)\s+(?:to|with)\s+/iu',$text)!==1)return null;$context=synth_social_context($db,$event);$performer=$context['target']??null;
    $parsed=synth_social_parse_directive($text,is_array($performer)?($performer['display_name']??''):'');
    if ($parsed===null)return null;
    if (!is_array($performer))throw new DomainException('social_performer_not_selected');
    if ($event['type']==='input_audio') {
        $proof=$db->fetchOne('SELECT input_text FROM synth_meta.dialogue_origin_policies WHERE session_id='.$db->escapeLiteral($event['session_id']).' AND request_id='.$db->escapeLiteral($event['request_id']));
        if (!$proof || ($proof['input_text']??null)!==$text)throw new DomainException('social_transcript_not_captured');
    }
    $payload=$parsed+['performer'=>$performer,'source_text'=>$text];
    return synth_social_owned($db,$event,static fn($connection)=>synth_social_admit_locked($db,$connection,$event,$payload));
}
function synth_social_offers($db,array $event): array {
    if (!synth_social_supported($event)||!synth_social_schema_ready($db))return [];
    return synth_social_owned($db,$event,static function($connection) use($db,$event) {
        $context=synth_social_context($db,$event);$rows=synth_social_visible($db,$event);$tasks=[];
        foreach ($rows as $row) {
            $task=$row['task'];$status=synth_social_context_status($task,synth_social_location($context));
            if (in_array($task['status'],['travelling','arrived','conversing'],true) && ($row['session_id']!==$event['session_id']||$row['generation']!==intval($event['generation']))) {
                $status=$task['when']==='on_return' && synth_social_ref_key($task['location_ref']??[])!==synth_social_ref_key(synth_social_location($context)??[])?'waiting_return':'ready';
            }
            if (in_array($task['status'],['travelling','arrived','conversing'],true) && $status===$task['status']) {
                $lease=$db->fetchOne('SELECT 1 FROM synth_meta.social_task_claims WHERE task_id='.$db->escapeLiteral($task['task_id'])
                    .' AND session_id='.$db->escapeLiteral($event['session_id']).' AND generation='.intval($event['generation'])
                    .' AND execution_id='.$db->escapeLiteral($task['execution_id']).' AND deadline_at>clock_timestamp()');
                if (!$lease) {
                    $status=$task['when']==='on_return' && synth_social_ref_key($task['location_ref'])!==synth_social_ref_key(synth_social_location($context)??[])?'waiting_return':'ready';
                    if (!@pg_query_params($connection,'DELETE FROM synth_meta.social_task_claims WHERE task_id=$1 AND session_id=$2 AND generation=$3 AND deadline_at<=clock_timestamp()',[$task['task_id'],$event['session_id'],$event['generation']]))throw new RuntimeException('Unable to retire expired social fence');
                }
            }
            if ($status!==$task['status'])$task=synth_social_append($connection,$event,$context,$task,$status);
            $tasks[]=$task;
        }
        usort($tasks,static fn($a,$b)=>(int)in_array($a['status'],['completed','cancelled','failed'],true)<=>(int)in_array($b['status'],['completed','cancelled','failed'],true) ?: $b['revision']<=>$a['revision']);
        return array_slice($tasks,0,8);
    });
}
function synth_social_progress($db,array $event): array {
    if (($event['type']??'')!=='social_task_progress')throw new DomainException('social_progress_required');
    return synth_social_owned($db,$event,static function($connection) use($db,$event) {
        $payload=$event['payload'];$id=$payload['task_id']??'';$execution=$payload['execution_id']??'';$phase=$payload['phase']??'';
        if (!synth_social_progress_valid($payload))throw new DomainException('invalid_social_progress');
        if (!is_string($id)||preg_match('/^social:[0-9a-f]{32}$/D',$id)!==1||!is_string($execution)||preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$execution)!==1)throw new DomainException('invalid_social_progress');
        if (!@pg_query_params($connection,'SELECT pg_advisory_xact_lock(hashtext($1))',[$event['session_id'].'/'.$event['generation'].'/'.$id]))throw new RuntimeException('Unable to fence social progress');
        $visible=synth_social_visible($db,$event);$task=$visible[$id]['task']??null;
        if (!$task || ($payload['expected_revision']??null)!==$task['revision'])throw new DomainException('social_revision_changed');
        $context=synth_social_context($db,$event);
        if ($phase==='started') {
            if ($task['status']!=='ready'||!synth_social_pair_ready($context,$task['performer'],$task['recipient'],2000))throw new DomainException('social_not_ready');
            if ($task['when']==='on_return' && (synth_social_ref_key($task['location_ref']??[])!==synth_social_ref_key(synth_social_location($context)??[])))throw new DomainException('social_destination_not_current');
            $claim=@pg_query_params($connection,"INSERT INTO synth_meta.social_task_claims(task_id,session_id,generation,execution_id,deadline_at)
                VALUES($1,$2,$3,$4,clock_timestamp()+INTERVAL '2 minutes') ON CONFLICT DO NOTHING RETURNING task_id",[$id,$event['session_id'],$event['generation'],$execution]);
            if (!$claim||!pg_fetch_assoc($claim))throw new DomainException('social_execution_already_claimed');
            return synth_social_append($connection,$event,$context,$task,'travelling',$execution);
        }
        if ($phase==='cancelled' && in_array($task['status'],['ready','waiting_departure','waiting_return'],true) && !isset($task['execution_id']))
            return synth_social_append($connection,$event,$context,$task,'cancelled');
        $claim=@pg_query_params($connection,'SELECT 1 FROM synth_meta.social_task_claims WHERE task_id=$1 AND session_id=$2 AND generation=$3 AND execution_id=$4 AND (deadline_at>clock_timestamp() OR $5::boolean) FOR UPDATE',[$id,$event['session_id'],$event['generation'],$execution,in_array($phase,['failed','cancelled'],true)?'true':'false']);
        if (!$claim||!pg_fetch_assoc($claim)||($task['execution_id']??null)!==$execution)throw new DomainException('social_execution_not_owned');
        if ($phase==='arrived') {
            if ($task['status']!=='travelling'||!synth_social_pair_ready($context,$task['performer'],$task['recipient'],256))throw new DomainException('social_arrival_not_observed');
            return synth_social_append($connection,$event,$context,$task,'arrived',$execution);
        }
        if ($phase==='spoken') {
            if (!in_array($task['status'],['arrived','conversing'],true)||!synth_social_pair_ready($context,$task['performer'],$task['recipient'],256)||!synth_social_spoken_proof($connection,$event,$task,$payload['proof']??[]))throw new DomainException('social_conversation_not_delivered');
            $status='completed';
        } elseif (in_array($phase,['cancelled','failed'],true))$status=$phase;
        else throw new DomainException('invalid_social_phase');
        $task=synth_social_append($connection,$event,$context,$task,$status,$execution);
        if (!@pg_query_params($connection,'DELETE FROM synth_meta.social_task_claims WHERE task_id=$1 AND session_id=$2 AND generation=$3 AND execution_id=$4',[$id,$event['session_id'],$event['generation'],$execution]))throw new RuntimeException('Unable to release social execution fence');
        return $task;
    });
}
// Delivered-recipient proof must descend from this task's verified arrival response; emitted unrelated dialogue is insufficient.
function synth_social_spoken_proof($connection,array $event,array $task,array $proof): bool {
    if (array_keys($proof)!==['request_id','line_id'] && array_keys($proof)!==['line_id','request_id'])return false;
    $result=@pg_query_params($connection,"SELECT r.speaker,r.listener,r.listener_state,r.line_sha256,e.response_body,p.canonical_event
        FROM synth_meta.response_routes r JOIN synth_meta.request_executions e USING(session_id,request_id)
        JOIN synth_meta.protocol_events p USING(session_id,request_id)
        WHERE r.session_id=$1 AND r.request_id=$2 AND r.line_id=$3 AND r.generation=$4
            AND e.state='complete' AND e.request_sha256=p.event_sha256 AND e.generation=r.generation",
        [$event['session_id'],$proof['request_id'],$proof['line_id'],$event['generation']]);
    if (!$result||!($row=pg_fetch_assoc($result))||$row['listener_state']!=='addressed')return false;
    $speaker=json_decode($row['speaker'],true);$listener=json_decode($row['listener'],true);
    if (synth_social_actor_key($speaker)!==synth_social_actor_key($task['recipient'])||synth_social_actor_key($listener)!==synth_social_actor_key($task['performer']))return false;
    $body=synth_protocol_decode_bytea($row['response_body']);$wire=null;
    foreach (explode("\n",trim($body)) as $line) {$decoded=json_decode($line,true);if(($decoded['line_id']??'')===$proof['line_id'])$wire=$line."\n";}
    if ($wire===null||!hash_equals($row['line_sha256'],hash('sha256',$wire)))return false;
    $origin=json_decode($row['canonical_event'],true);$visited=[];
    for($depth=0;$depth<64;++$depth) {
        if (($origin['type']??'')==='social_task_progress')return ($origin['payload']['phase']??'')==='arrived'
            && ($origin['payload']['task_id']??'')===$task['task_id'] && ($origin['payload']['execution_id']??'')===($task['execution_id']??null)
            && synth_social_arrival_parent_valid($connection,$origin);
        if (($origin['type']??'')!=='trigger'||($origin['payload']['kind']??'')!=='rechat')return false;
        $parent=$origin['payload']['reply_to_request_id']??'';if($parent===''||isset($visited[$parent]))return false;$visited[$parent]=true;
        $r=@pg_query_params($connection,"SELECT p.canonical_event FROM synth_meta.protocol_events p JOIN synth_meta.request_executions e USING(session_id,request_id) WHERE p.session_id=$1 AND p.request_id=$2 AND p.generation=$3 AND e.state='complete' AND e.request_sha256=p.event_sha256",[$event['session_id'],$parent,$event['generation']]);
        if(!$r||!($p=pg_fetch_assoc($r)))return false;$origin=json_decode($p['canonical_event'],true);
    }
    return false;
}
function synth_social_arrival_parent_valid($connection,array $parent): bool {
    if (($parent['type']??'')!=='social_task_progress'||($parent['payload']['phase']??'')!=='arrived')return false;
    $r=@pg_query_params($connection,"SELECT 1 FROM synth_meta.social_task_events e JOIN synth_meta.history_events h ON h.id=e.history_event_id
        JOIN synth_meta.protocol_events p ON p.session_id=h.session_id AND p.request_id=h.request_id
        WHERE e.task_id=$1 AND e.execution_id=$2 AND e.status='arrived' AND h.session_id=$3 AND h.generation=$4 AND h.request_id=$5
            AND p.canonical_event=$6::jsonb AND p.event_sha256=$7 AND e.event_payload=h.event_payload->'social_task_state'",
        [$parent['payload']['task_id'],$parent['payload']['execution_id'],$parent['session_id'],$parent['generation'],$parent['request_id'],synth_protocol_canonical_json($parent),hash('sha256',synth_protocol_canonical_json($parent))]);
    return $r && pg_fetch_assoc($r)!==false;
}
function synth_social_exchange_context($db,array $event): ?array {
    if (($event['type']??'')!=='social_task_progress'||($event['payload']['phase']??'')!=='arrived')return null;
    return synth_social_owned($db,$event,static function($connection) use($db,$event) {
        if(!synth_social_arrival_parent_valid($connection,$event))return null;
        $visible=synth_social_visible($db,$event);$task=$visible[$event['payload']['task_id']]['task']??null;
        if(!$task||$task['status']!=='arrived'||!synth_social_pair_ready(synth_social_context($db,$event),$task['performer'],$task['recipient'],256))return null;
        return array_intersect_key($task,array_fill_keys(['task_id','execution_id','performer','recipient','topic'],true));
    });
}
// These are actual actor-owned journal task notes, separate from prose reflection and never parsed for executable instructions.
function synth_social_diary_notes($db,array $event,array $actor): array {
    $notes=[];foreach(synth_social_visible($db,$event) as $row) {
        $task=$row['task'];if(synth_social_actor_key($task['performer'])!==synth_social_actor_key($actor))continue;
        $notes[]=['task_id'=>$task['task_id'],'revision'=>$task['revision'],'topic'=>'Saved conversation task','content'=>synth_social_note($task,$task['status']),'status'=>$task['status']];
    }
    usort($notes,static fn($a,$b)=>$b['revision']<=>$a['revision']);return array_slice($notes,0,8);
}
