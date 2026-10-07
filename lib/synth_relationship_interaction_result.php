<?php

require_once __DIR__.'/synth_relationship_interaction_queue.php';
require_once __DIR__.'/synth_relationship_interaction_model.php';

// Each evaluated response is consumed only within its reachable timeline, never globally across restored saves.
function synth_relationship_paired_response_consumed($connection, $db, array $event, int $responseId, array $listenerIds): bool
{
    if (!$listenerIds) return false;
    foreach ($listenerIds as $id) if (!is_int($id) || $id<1) throw new InvalidArgumentException('Invalid evaluated relationship subject');
    $result=@pg_query_params($connection,synth_protocol_history_cte($db,$event)."
        SELECT 1 FROM synth_meta.relationship_interaction_results result
        JOIN synth_meta.relationship_interaction_evaluations i ON i.id=result.interaction_id
        JOIN synth_meta.history_events h ON h.id=result.history_event_id
        WHERE i.response_history_event_id=$1 AND i.listener_identity_id=ANY($2::bigint[])
            AND (EXISTS(SELECT 1 FROM native_history_scopes scope WHERE scope.session_id=h.session_id
                AND scope.generation=h.generation AND scope.playthrough_id=h.playthrough_id AND h.id<=scope.cutoff)
                OR (h.session_id=$3 AND h.generation=$4)) LIMIT 1",
        [$responseId,'{'.implode(',',array_unique($listenerIds)).'}',$event['session_id'],$event['generation']]);
    if (!$result) throw new RuntimeException('Unable to check paired response consumption');
    return pg_fetch_assoc($result)!==false;
}

// Publication never impersonates the listener: the result and both revisions retain the original canonical request.
function synth_relationship_complete_interaction($db, int $jobId, string $token, string $modelText): int
{
    if ($jobId<1 || !preg_match('/^[0-9a-f]{32}$/D',$token)) throw new InvalidArgumentException('Invalid interaction lease');
    if (!synthRelationshipUsesDedicatedConnector()) throw new LogicException('Dedicated relationships are disabled');
    return $db->withNativeRequestLock(static function($connection,array $event) use($db,$jobId,$token,$modelText) {
        $result=@pg_query_params($connection,"SELECT j.state,j.lease_token,
                (j.lease_until>clock_timestamp() AND j.deadline_at>clock_timestamp()) AS live,i.*
            FROM synth_meta.worker_jobs j JOIN synth_meta.relationship_interaction_evaluations i ON i.id=j.relationship_interaction_id
                AND i.session_id=j.session_id AND i.request_id=j.request_id AND i.generation=j.generation
                AND i.context_sequence::text=j.payload->>'context_sequence' AND i.speaker_identity_id=j.actor_identity_id
            WHERE j.id=$1 AND j.job_type='relationship' AND j.relationship_evaluation_id IS NULL AND j.memory_batch_id IS NULL
                AND j.session_id=$2 AND j.request_id=$3 AND j.generation=$4 AND j.turn_id=$5 AND j.payload->>'context_sequence'=$6
                AND i.input_payload->'owner'=$7::jsonb
                AND i.input_sha256=encode(sha256(convert_to(i.input_payload::text,'UTF8')),'hex')",
            [$jobId,$event['session_id'],$event['request_id'],$event['generation'],$event['turn_id'],(string)$event['context_sequence'],
                synth_protocol_canonical_json($event)]);
        if (!$result) throw new RuntimeException('Unable to resolve NPC interaction completion');
        $job=pg_fetch_assoc($result);
        if (!$job || !hash_equals($job['lease_token'] ?? '',$token) || !in_array($job['state'],['running','ready'],true)
            || ($job['state']==='running' && $job['live']!=='t')) throw new SYNTHProtocolCancelled('interaction_lease_lost');
        $input=json_decode($job['input_payload'],true,flags:JSON_THROW_ON_ERROR);
        $parsed=synth_relationship_parse_interaction_result($modelText,$input);
        $json=json_encode($parsed,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if ($job['state']==='ready') {
            // Idempotent acknowledgement is not a new publication and does not reapply current actor policy.
            $result=@pg_query_params($connection,"SELECT r.history_event_id FROM synth_meta.relationship_interaction_results r
                JOIN synth_meta.history_events h ON h.id=r.history_event_id AND h.session_id=$4 AND h.request_id=$5
                    AND h.generation=$6 AND h.context_sequence=$7
                WHERE r.job_id=$1 AND r.interaction_id=$2 AND r.result_payload=$3::jsonb
                    AND h.event_payload->>'type'='relationship_interaction_result'
                    AND h.event_payload->>'evaluation_sha256'=$8
                    AND h.event_payload->>'result_sha256'=encode(sha256(convert_to(r.result_payload::text,'UTF8')),'hex')",
                [$jobId,$job['id'],$json,$event['session_id'],$event['request_id'],$event['generation'],$event['context_sequence'],$job['input_sha256']]);
            if (!$result) throw new RuntimeException('Unable to verify NPC interaction replay');
            $row=pg_fetch_assoc($result);if (!$row) throw new LogicException('Conflicting NPC interaction replay');
            return intval($row['history_event_id']);
        }
        $validated=synth_relationship_interaction_input($db,intval($job['id']));
        if (!$validated || $validated!=$input) throw new SYNTHProtocolCancelled('interaction_input_unavailable');
        $actors=$input['interaction']['actor_ids'];
        if (synth_relationship_paired_response_consumed($connection,$db,$event,intval($job['response_history_event_id']),[$actors['listener']])) {
            throw new LogicException('NPC interaction response already evaluated');
        }
        $result=@pg_query_params($connection,synth_protocol_history_cte($db,$event)."
            SELECT 1 FROM synth_meta.relationship_results r JOIN synth_meta.relationship_evaluations i ON i.id=r.evaluation_id
            JOIN synth_meta.history_events h ON h.id=r.history_event_id
            WHERE i.response_history_event_id=$1 AND i.actor_identity_id=$2
                AND i.input_payload->'subjects' @> jsonb_build_array(jsonb_build_object('subject_id',$5::bigint)) AND (
                EXISTS(SELECT 1 FROM native_history_scopes scope WHERE scope.session_id=h.session_id AND scope.generation=h.generation
                    AND scope.playthrough_id=h.playthrough_id AND h.id<=scope.cutoff) OR (h.session_id=$3 AND h.generation=$4)) LIMIT 1",
            [$job['response_history_event_id'],$actors['speaker'],$event['session_id'],$event['generation'],$actors['listener']]);
        if (!$result) throw new RuntimeException('Unable to verify single-speaker response consumption');
        if (pg_fetch_assoc($result)) throw new LogicException('Relationship response already evaluated');
        // Both controls are held in sorted order. Pair locks also coordinate with ordinary inline/single-owner writers.
        $directions=[$actors['speaker']=>$actors['listener'],$actors['listener']=>$actors['speaker']];ksort($directions,SORT_NUMERIC);
        foreach ($directions as $actor=>$subject) {
            if (@pg_query_params($connection,'SELECT pg_advisory_xact_lock(hashtextextended($1,0))',["synth-relationship:$actor:$subject"])===false) {
                throw new RuntimeException('Unable to lock interaction perspective');
            }
            $result=@pg_query_params($connection,'SELECT 1 FROM synth_meta.relationship_revisions r JOIN synth_meta.history_events h ON h.id=r.history_event_id
                WHERE h.session_id=$1 AND h.generation=$2 AND r.actor_identity_id=$3 AND r.subject_identity_id=$4
                    AND h.id>(SELECT history_event_id FROM synth_meta.context_snapshots WHERE session_id=$1 AND generation=$2 AND snapshot_sequence=$5) LIMIT 1',
                [$event['session_id'],$event['generation'],$actor,$subject,$event['context_sequence']]);
            if (!$result) throw new RuntimeException('Unable to check interaction perspective head');
            if (pg_fetch_assoc($result)) throw new LogicException('NPC interaction perspective is stale');
        }
        $result=@pg_query_params($connection,"WITH publication AS (
            INSERT INTO synth.eventlog(type,data,sess,gamets,localts,ts,people,location,party,delivery_state,
                synth_session_id,synth_generation,synth_context_sequence,synth_request_id)
            VALUES('relationship_interaction_result','Native NPC interaction evaluation',$1,$2,$3,$3,'','','','derived',$4,$5,$6,$7) RETURNING *
        ), history AS (
            INSERT INTO synth_meta.history_events(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
            SELECT synth_session_id,synth_generation,synth_context_sequence,synth_request_id,$1,rowid,to_jsonb(publication)
                ||jsonb_build_object('evaluation_sha256',$8::text,'result_sha256',encode(sha256(convert_to(($9::jsonb)::text,'UTF8')),'hex'))
            FROM publication RETURNING id
        ) INSERT INTO synth_meta.relationship_interaction_results(history_event_id,interaction_id,job_id,result_payload)
            SELECT id,$10,$11,$9::jsonb FROM history RETURNING history_event_id",
            [$input['context']['playthrough_id'],intval($input['context']['world']['game_time_ticks'] ?? 0),time(),
                $event['session_id'],$event['generation'],$event['context_sequence'],$event['request_id'],$job['input_sha256'],$json,$job['id'],$jobId]);
        if (!$result) throw new RuntimeException('Unable to publish NPC interaction result');
        $resultId=intval(pg_fetch_assoc($result)['history_event_id']);
        foreach ($input['interaction']['relationships'] as $role=>$prior) {
            if ($prior!==null && @pg_query_params($connection,'INSERT INTO synth_meta.relationship_interaction_result_priors
                (result_history_event_id,actor_identity_id,prior_history_event_id) VALUES($1,$2,$3)',[$resultId,$actors[$role],$prior['revision']])===false) {
                throw new RuntimeException('Unable to publish interaction prior dependency');
            }
        }
        foreach ($parsed['changes'] as $change) {
            $details=array_diff_key($change['state'],array_flip(['aff','type']));synth_relationship_validate_details($details);
            $revision=['schema'=>'synth.relationship.interaction.revision.v1','interaction_result'=>$resultId,'role'=>$change['role'],
                'prior'=>$change['prior_revision'],'state'=>$change['state'],'sources'=>[intval($job['response_history_event_id'])]];
            $revisionJson=json_encode($revision,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $detailsJson=json_encode((object)$details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $result=@pg_query_params($connection,"WITH publication AS (
                INSERT INTO synth.eventlog(type,data,sess,gamets,localts,ts,people,location,party,delivery_state,
                    synth_session_id,synth_generation,synth_context_sequence,synth_request_id)
                VALUES('relationship_state','Native NPC interaction perspective',$1,$2,$3,$3,'','','','derived',$4,$5,$6,$7) RETURNING *
            ), history AS (
                INSERT INTO synth_meta.history_events(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
                SELECT synth_session_id,synth_generation,synth_context_sequence,synth_request_id,$1,rowid,to_jsonb(publication)
                    ||jsonb_build_object('relationship_details',$15::jsonb,'interaction_result',$14::bigint,
                        'relationship_input_sha256',encode(sha256(convert_to(($13::jsonb)::text,'UTF8')),'hex'))
                FROM publication RETURNING id
            ) INSERT INTO synth_meta.relationship_revisions(history_event_id,actor_identity_id,subject_identity_id,prior_history_event_id,
                affinity,relationship_type,input_payload,input_hash,interaction_result_history_event_id,details)
                SELECT id,$8,$9,$10,$11,$12,$13::jsonb,encode(sha256(convert_to(($13::jsonb)::text,'UTF8')),'hex'),$14,$15::jsonb
                FROM history RETURNING history_event_id",
                [$input['context']['playthrough_id'],intval($input['context']['world']['game_time_ticks'] ?? 0),time(),
                    $event['session_id'],$event['generation'],$event['context_sequence'],$event['request_id'],$change['actor_id'],$change['subject_id'],
                    $change['prior_revision'],$change['state']['aff'],$change['state']['type'],$revisionJson,$resultId,$detailsJson]);
            if (!$result) throw new RuntimeException('Unable to publish interaction perspective');
            $revisionId=intval(pg_fetch_assoc($result)['history_event_id']);
            if (@pg_query_params($connection,'INSERT INTO synth_meta.relationship_revision_sources VALUES($1,$2)',
                [$revisionId,$job['response_history_event_id']])===false) throw new RuntimeException('Unable to publish interaction source');
        }
        $result=@pg_query_params($connection,"UPDATE synth_meta.worker_jobs SET state='ready',lease_until=NULL,updated_at=clock_timestamp()
            WHERE id=$1 AND state='running' AND lease_token=$2 AND lease_until>clock_timestamp() AND deadline_at>clock_timestamp() RETURNING id",[$jobId,$token]);
        if (!$result || !pg_fetch_assoc($result)) throw new SYNTHProtocolCancelled('interaction_lease_lost');
        return $resultId;
    },2000);
}
