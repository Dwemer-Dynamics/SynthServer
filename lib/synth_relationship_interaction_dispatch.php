<?php

require_once __DIR__.'/synth_relationship_dispatch.php';
require_once __DIR__.'/synth_relationship_interaction_worker.php';

// Recover owned work before discovering one exact response/listener pair; providers never run inside reservation SQL.
function synth_relationship_dispatch_interaction($db, ?callable $generate=null): ?int
{
    if (!synthRelationshipUsesDedicatedConnector()) return null;
    $origin=synth_relationship_reserve_dispatch($db,true);if (!$origin) return null;
    $jobId=synth_worker_with_origin($db,$origin,static function(array $event) use($db) {
        $candidate=$db->withNativeRequestLock(static function($connection,array $event) use($db) {
            $params=[$event['session_id'],$event['generation'],$event['turn_id'],(string)$event['context_sequence']];
            $context=synth_protocol_current_context($db,$event);
            $responder=$context ? synth_relationship_responder($db,$event,$context) : null;
            if (!$responder) return [];
            $result=@pg_query_params($connection,"UPDATE synth_meta.worker_jobs j SET state='cancelled',lease_token=NULL,lease_until=NULL,
                last_error='owner_unavailable',updated_at=clock_timestamp() WHERE job_type='relationship' AND relationship_interaction_id IS NOT NULL
                    AND session_id=$1 AND state IN ('pending','running') AND (generation<>$2 OR turn_id<>$3 OR payload->>'context_sequence' IS DISTINCT FROM $4
                        OR relationship_evaluation_id IS NOT NULL OR memory_batch_id IS NOT NULL
                        OR NOT EXISTS(SELECT 1 FROM synth_meta.relationship_interaction_evaluations i
                            JOIN synth_meta.request_executions e ON e.session_id=i.session_id AND e.request_id=i.request_id
                                AND e.generation=i.generation AND e.turn_id=j.turn_id AND e.state='complete'
                            JOIN synth_meta.protocol_events p ON p.session_id=e.session_id AND p.request_id=e.request_id
                                AND p.generation=e.generation AND p.turn_id=e.turn_id AND p.event_type=e.event_type AND p.event_sha256=e.request_sha256
                            WHERE i.id=j.relationship_interaction_id AND i.session_id=j.session_id AND i.request_id=j.request_id
                                AND i.generation=j.generation AND i.context_sequence::text=j.payload->>'context_sequence'
                                AND i.speaker_identity_id=j.actor_identity_id AND i.input_payload->'owner'=p.canonical_event
                                AND ".synth_relationship_responder_sql('p.canonical_event','i.speaker_identity_id',"i.input_payload->'context'")."))",$params);
            if (!$result) throw new RuntimeException('Unable to retire obsolete interaction owner');
            $result=@pg_query_params($connection,"UPDATE synth_meta.worker_jobs SET state='failed',lease_token=NULL,lease_until=NULL,
                last_error='lease_expired',updated_at=clock_timestamp() WHERE relationship_interaction_id IS NOT NULL
                    AND session_id=$1 AND state IN ('pending','running')
                    AND (deadline_at<=clock_timestamp() OR (attempts>=3 AND lease_until<=clock_timestamp()))",[$event['session_id']]);
            if (!$result) throw new RuntimeException('Unable to retire expired interaction dispatch');
            $result=@pg_query_params($connection,"SELECT id FROM synth_meta.worker_jobs WHERE job_type='relationship'
                AND relationship_interaction_id IS NOT NULL AND relationship_evaluation_id IS NULL AND memory_batch_id IS NULL
                AND session_id=$1 AND generation=$2 AND turn_id=$3 AND payload->>'context_sequence'=$4
                AND available_at<=clock_timestamp() AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp()))
                ORDER BY available_at,id LIMIT 1",$params);
            if (!$result) throw new RuntimeException('Unable to select interaction retry');
            if ($row=pg_fetch_assoc($result)) return ['job'=>intval($row['id'])];
            $result=@pg_query_params($connection,"SELECT i.id,i.session_id,i.request_id,i.generation,i.context_sequence,
                    e.turn_id,e.event_type,p.canonical_event,p.event_sha256
                FROM synth_meta.relationship_interaction_evaluations i
                JOIN synth_meta.request_executions e ON e.session_id=i.session_id AND e.request_id=i.request_id
                    AND e.generation=i.generation AND e.turn_id=$3 AND e.state='complete'
                JOIN synth_meta.protocol_events p ON p.session_id=e.session_id AND p.request_id=e.request_id
                    AND p.generation=e.generation AND p.turn_id=e.turn_id AND p.event_type=e.event_type AND p.event_sha256=e.request_sha256
                WHERE i.session_id=$1 AND i.generation=$2 AND i.context_sequence::text=$4 AND i.input_payload->'owner'=p.canonical_event
                    AND NOT EXISTS(SELECT 1 FROM synth_meta.worker_jobs j WHERE j.relationship_interaction_id=i.id)
                ORDER BY i.id LIMIT 1",$params);
            if (!$result) throw new RuntimeException('Unable to recover frozen interaction');
            if ($row=pg_fetch_assoc($result)) return ['input'=>$row];
            // Source visibility stays speaker-scoped. Addressing selects one canonical listener, not every nearby actor.
            $result=@pg_query_params($connection,synth_protocol_history_cte($db,$event)
                .synth_relationship_response_evidence_cte($db,$event,true)."
                SELECT response.history_event_id,listener.id AS listener_id,MIN(entry.ordinality-1) AS entry_index
                FROM relationship_response_evidence response
                JOIN native_ancestry current ON current.depth=1
                JOIN synth_meta.context_snapshots c ON c.session_id=current.session_id AND c.generation=current.generation AND c.snapshot_sequence=current.snapshot_sequence
                JOIN synth_meta.protocol_events owner ON owner.session_id=$1 AND owner.request_id=$5 AND owner.generation=$2 AND owner.turn_id=$3
                JOIN synth_meta.protocol_events source ON source.session_id=response.session_id AND source.request_id=response.request_id
                JOIN synth.actor_identities replying ON replying.id=$6::bigint AND replying.game='fo4' AND replying.playthrough_id=c.playthrough_id
                CROSS JOIN LATERAL jsonb_array_elements(CASE WHEN jsonb_typeof(response.response_payload->'entries')='array'
                    THEN response.response_payload->'entries' ELSE '[]'::jsonb END) WITH ORDINALITY entry(value,ordinality)
                JOIN synth.actor_identities listener ON listener.game='fo4' AND listener.playthrough_id=c.playthrough_id
                    AND upper(listener.full_form_id)=upper(entry.value#>>'{routing,listener,form_id}')
                    AND lower(listener.origin_plugin)=lower(entry.value#>>'{routing,listener,origin_plugin}')
                LEFT JOIN synth_meta.relationship_actor_controls speaker_control ON speaker_control.actor_identity_id=replying.id
                LEFT JOIN synth_meta.relationship_actor_controls listener_control ON listener_control.actor_identity_id=listener.id
                WHERE response.response_payload->>'schema'='synth.relationship.response.v3' AND response.response_payload->>'evaluation_mode'='dedicated'
                    AND entry.ordinality<=256 AND entry.value#>>'{routing,availability}'='addressed'
                    AND response.actor_identity_id=replying.id
                    AND response.session_id=$1 AND response.request_id=$5
                    AND response.generation=$2 AND response.context_sequence::text=$4
                    AND upper(entry.value#>>'{speaker,form_id}')=upper(replying.full_form_id)
                    AND lower(entry.value#>>'{speaker,origin_plugin}')=lower(replying.origin_plugin)
                    AND entry.value#>>'{speaker,playthrough_id}'=c.playthrough_id AND entry.value#>>'{routing,listener,playthrough_id}'=c.playthrough_id
                    AND listener.id<>replying.id AND listener.id<>c.player_identity_id
                    AND (listener.id=c.target_identity_id OR c.audience_identity_ids @> jsonb_build_array(listener.id))
                    AND EXISTS(SELECT 1 FROM jsonb_array_elements(jsonb_build_array(c.context_payload->'target')
                        ||COALESCE(c.context_payload->'audience','[]'::jsonb)) actor
                        WHERE upper(actor->>'form_id')=upper(listener.full_form_id) AND lower(actor->>'origin_plugin')=lower(listener.origin_plugin)
                            AND actor->>'playthrough_id'=c.playthrough_id)
                    AND ((NOT COALESCE(speaker_control.locked,false) AND owner.id>COALESCE(speaker_control.barrier_event_id,0)
                            AND source.id>COALESCE(speaker_control.barrier_event_id,0))
                        OR (NOT COALESCE(listener_control.locked,false) AND owner.id>COALESCE(listener_control.barrier_event_id,0)
                            AND source.id>COALESCE(listener_control.barrier_event_id,0)))
                    AND NOT EXISTS(SELECT 1 FROM synth_meta.relationship_interaction_evaluations i WHERE i.response_history_event_id=response.history_event_id
                        AND i.listener_identity_id=listener.id AND i.session_id=$1 AND i.generation=$2 AND i.context_sequence::text=$4)
                    AND NOT EXISTS(SELECT 1 FROM synth_meta.relationship_interaction_results result
                        JOIN synth_meta.relationship_interaction_evaluations i ON i.id=result.interaction_id
                        JOIN synth_meta.history_events h ON h.id=result.history_event_id
                        WHERE i.response_history_event_id=response.history_event_id AND i.listener_identity_id=listener.id
                            AND ((h.session_id=$1 AND h.generation=$2) OR EXISTS(SELECT 1 FROM native_history_scopes scope
                                WHERE scope.session_id=h.session_id AND scope.generation=h.generation AND scope.playthrough_id=h.playthrough_id AND h.id<=scope.cutoff)))
                    AND NOT EXISTS(SELECT 1 FROM synth_meta.relationship_results result JOIN synth_meta.relationship_evaluations i ON i.id=result.evaluation_id
                        JOIN synth_meta.history_events h ON h.id=result.history_event_id
                        WHERE i.response_history_event_id=response.history_event_id AND i.input_payload->'subjects' @> jsonb_build_array(jsonb_build_object('subject_id',listener.id))
                            AND ((h.session_id=$1 AND h.generation=$2) OR EXISTS(SELECT 1 FROM native_history_scopes scope
                                WHERE scope.session_id=h.session_id AND scope.generation=h.generation AND scope.playthrough_id=h.playthrough_id AND h.id<=scope.cutoff)))
                GROUP BY response.history_event_id,listener.id ORDER BY response.history_event_id,listener.id LIMIT 1",
                [...$params,$event['request_id'],$responder['actor_identity_id']]);
            if (!$result) throw new RuntimeException('Unable to discover NPC interaction');
            $row=pg_fetch_assoc($result);return $row ? ['response'=>intval($row['history_event_id']),'entry'=>intval($row['entry_index'])] : [];
        },2000);
        if (isset($candidate['job'])) return $candidate['job'];
        if (isset($candidate['input'])) {
            $input=$candidate['input'];
            return synth_worker_with_origin($db,$input,static fn()=>synth_relationship_enqueue_interaction($db,intval($input['id'])),'relationship');
        }
        if (!isset($candidate['response'])) return null;
        $input=synth_relationship_capture_configured_interaction($db,$candidate['response'],$candidate['entry']);
        return $input===null ? null : synth_relationship_enqueue_interaction($db,$input);
    },'relationship');
    return $jobId===null ? null : synth_relationship_run_persisted_interaction($db,$jobId,$generate);
}
