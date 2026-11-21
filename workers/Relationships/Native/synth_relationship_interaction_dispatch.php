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
            $jobs=\Synth\Core\DbManager\Dao\WorkerJobDao::current($db);
            $jobs->cancelObsoleteInteractionWork($event['session_id'],$event['generation'],$event['turn_id'],(string)$event['context_sequence'],
                synth_relationship_responder_sql('p.canonical_event','i.speaker_identity_id',"i.input_payload->'context'"));
            $jobs->failExpiredInteractionWork($event['session_id']);
            $retry=$jobs->nextInteractionRetry($event['session_id'],$event['generation'],$event['turn_id'],(string)$event['context_sequence']);
            if ($retry!==null) return ['job'=>$retry];
            $frozen=\Synth\Core\DbManager\Dao\RelationshipInteractionEvaluationDao::current($db)->unqueuedFrozen($event['session_id'],$event['generation'],$event['turn_id'],(string)$event['context_sequence']);
            if ($frozen) return ['input'=>$frozen];
            // Source visibility stays speaker-scoped. Addressing selects one canonical listener, not every nearby actor.
            $result=@pg_query_params($connection,synth_protocol_history_cte($db,$event)
                .synth_relationship_response_evidence_cte($db,$event,true)."
                SELECT response.history_event_id,listener.id AS listener_id,MIN(entry.ordinality-1) AS entry_index
                FROM relationship_response_evidence response
                JOIN native_ancestry current ON current.depth=1
                JOIN public.core_runtime_context c ON c.session_id=current.session_id AND c.generation=current.generation AND c.snapshot_sequence=current.snapshot_sequence
                JOIN public.core_protocol_event owner ON owner.session_id=$1 AND owner.request_id=$5 AND owner.generation=$2 AND owner.turn_id=$3
                JOIN public.core_protocol_event source ON source.session_id=response.session_id AND source.request_id=response.request_id
                JOIN public.core_actor_identity replying ON replying.id=$6::bigint AND replying.game='fo4' AND replying.playthrough_id=c.playthrough_id
                CROSS JOIN LATERAL jsonb_array_elements(CASE WHEN jsonb_typeof(response.response_payload->'entries')='array'
                    THEN response.response_payload->'entries' ELSE '[]'::jsonb END) WITH ORDINALITY entry(value,ordinality)
                JOIN public.core_actor_identity listener ON listener.game='fo4' AND listener.playthrough_id=c.playthrough_id
                    AND upper(listener.full_form_id)=upper(entry.value#>>'{routing,listener,form_id}')
                    AND lower(listener.origin_plugin)=lower(entry.value#>>'{routing,listener,origin_plugin}')
                LEFT JOIN public.core_relationship_actor_control speaker_control ON speaker_control.actor_identity_id=replying.id
                LEFT JOIN public.core_relationship_actor_control listener_control ON listener_control.actor_identity_id=listener.id
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
                    AND NOT EXISTS(SELECT 1 FROM public.core_relationship_interaction_evaluation i WHERE i.response_history_event_id=response.history_event_id
                        AND i.listener_identity_id=listener.id AND i.session_id=$1 AND i.generation=$2 AND i.context_sequence::text=$4)
                    AND NOT EXISTS(SELECT 1 FROM public.core_relationship_interaction_result result
                        JOIN public.core_relationship_interaction_evaluation i ON i.id=result.interaction_id
                        JOIN public.core_history_event h ON h.id=result.history_event_id
                        WHERE i.response_history_event_id=response.history_event_id AND i.listener_identity_id=listener.id
                            AND ((h.session_id=$1 AND h.generation=$2) OR EXISTS(SELECT 1 FROM native_history_scopes scope
                                WHERE scope.session_id=h.session_id AND scope.generation=h.generation AND scope.playthrough_id=h.playthrough_id AND h.id<=scope.cutoff)))
                    AND NOT EXISTS(SELECT 1 FROM public.core_relationship_result result JOIN public.core_relationship_evaluation i ON i.id=result.evaluation_id
                        JOIN public.core_history_event h ON h.id=result.history_event_id
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
