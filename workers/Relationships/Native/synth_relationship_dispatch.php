<?php

require_once __DIR__.'/synth_relationship_worker.php';

// The two evaluation lanes have independent cooldowns; the shared actor queue still bounds their combined work.
function synth_relationship_reserve_dispatch($db, bool $interaction=false, bool $deliveryInline=false): ?array
{
    if (!$db->isTransactionIdle()) throw new LogicException('Relationship dispatch requires an idle database connection');
    if ($db->execQuery('BEGIN ISOLATION LEVEL READ COMMITTED')===false) throw new RuntimeException('Unable to begin relationship dispatch');
    try {
        if ($db->execQuery("SET LOCAL statement_timeout='2s'")===false) throw new RuntimeException('Unable to bound relationship dispatch');
        $origin=\Synth\Core\DbManager\Dao\RuntimeSessionDao::current($db)->reserveRelationshipDispatch($interaction,$deliveryInline);
        if ($db->execQuery('COMMIT')===false) throw new RuntimeException('Unable to reserve relationship dispatch');
    } finally {
        if (!$db->isTransactionIdle()) $db->execQuery('ROLLBACK');
    }
    return $origin;
}

// A completed gift stays bound to its receipt even when automatic dialogue has advanced the turn.
function synth_relationship_dispatch_gift($db, ?callable $generate): ?int
{
    $gift=\Synth\Core\DbManager\Dao\RelationshipResponseInputDao::current($db)->pendingGift();
    if (!$gift) return null;
    $jobId=$gift['job_id']===null ? synth_worker_with_origin($db,$gift,static function() use($db,$gift) {
        $evaluationId=$gift['evaluation_id']===null
            ? synth_relationship_capture_configured_evaluation($db,intval($gift['response_id']),true)
            : intval($gift['evaluation_id']);
        return $evaluationId===null ? null : synth_relationship_enqueue_evaluation($db,$evaluationId);
    },'relationship') : intval($gift['job_id']);
    return $jobId===null ? null : synth_relationship_run_persisted_job($db,$jobId,$generate);
}

// Resume delivery-gated inline directives through the verified active-owner worker boundary.
function synth_relationship_dispatch_inline($db): ?int
{
    if (!synthRelationshipSettingEnabled() || synthRelationshipUsesDedicatedConnector()) return null;
    $origin=synth_relationship_reserve_dispatch($db,false,true);
    if (!$origin) return null;
    return synth_worker_with_origin($db,$origin,static function() use($db): ?int {
        $published=synth_relationship_apply_inline_response($db);
        return $published===[] ? null : max($published);
    },'relationship');
}

// Reserve one active completed owner fairly, recover frozen work first, and release SQL locks before providers.
function synth_relationship_dispatch($db, ?callable $generate=null, bool $playerOnly=false): ?int
{
    if (!synthRelationshipUsesDedicatedConnector()) return null;
    if ($playerOnly && ($giftResult=synth_relationship_dispatch_gift($db,$generate))!==null) return $giftResult;
    $origin=synth_relationship_reserve_dispatch($db);if (!$origin) return null;
    $jobId=synth_worker_with_origin($db,$origin,static function(array $event) use($db,$playerOnly) {
        $candidate=$db->withNativeRequestLock(static function($connection,array $event) use($db,$playerOnly) {
            $jobs=\Synth\Core\DbManager\Dao\WorkerJobDao::current($db);
            $jobs->cancelObsoleteEvaluationWork($event['session_id'],$event['generation'],$event['turn_id'],strval($event['context_sequence']));
            $jobs->failExpiredEvaluationWork($event['session_id']);
            $retry=$jobs->nextEvaluationRetry($event['session_id'],$event['generation'],$event['turn_id'],strval($event['context_sequence']),$playerOnly);
            if ($retry!==null) return ['job'=>$retry];
            $frozen=\Synth\Core\DbManager\Dao\RelationshipEvaluationDao::current($db)->unqueuedFrozen($event['session_id'],$event['generation'],$event['turn_id'],strval($event['context_sequence']),$playerOnly);
            if ($frozen) return ['evaluation'=>$frozen];
            $responseScope=$playerOnly ? "AND response.response_payload->>'schema'='synth.relationship.response.v3'
                AND EXISTS(SELECT 1 FROM native_ancestry n JOIN public.core_runtime_context c ON c.session_id=n.session_id
                    AND c.generation=n.generation AND c.snapshot_sequence=n.snapshot_sequence,
                    jsonb_array_elements(CASE WHEN jsonb_typeof(response.response_payload->'entries')='array'
                        THEN response.response_payload->'entries' ELSE '[]'::jsonb END) WITH ORDINALITY entry(value,ordinality)
                    WHERE n.depth=1 AND entry.ordinality<=256 AND entry.value#>>'{routing,availability}'='addressed'
                        AND upper(entry.value#>>'{speaker,form_id}')=upper(c.context_payload#>>'{target,form_id}')
                        AND lower(entry.value#>>'{speaker,origin_plugin}')=lower(c.context_payload#>>'{target,origin_plugin}')
                        AND entry.value#>>'{speaker,playthrough_id}'=c.playthrough_id
                        AND upper(entry.value#>>'{routing,listener,form_id}')=upper(c.context_payload#>>'{player,form_id}')
                        AND lower(entry.value#>>'{routing,listener,origin_plugin}')=lower(c.context_payload#>>'{player,origin_plugin}')
                        AND entry.value#>>'{routing,listener,playthrough_id}'=c.playthrough_id)" : '';
            $result=@pg_query_params($connection,synth_relationship_visible_cte($db,$event,true,sourceHistoryIds:[])."
                SELECT response.history_event_id FROM relationship_response_evidence response
                WHERE response.response_payload->>'evaluation_mode'='dedicated' $responseScope
                    AND NOT EXISTS(SELECT 1 FROM public.core_relationship_actor_control control
                        JOIN public.core_protocol_event origin ON origin.session_id=response.session_id AND origin.request_id=response.request_id
                        WHERE control.actor_identity_id=response.actor_identity_id AND (control.locked OR origin.id<=control.barrier_event_id))
                    AND NOT EXISTS(SELECT 1 FROM native_relationship_results r WHERE r.response_history_event_id=response.history_event_id)
                    AND NOT EXISTS(SELECT 1 FROM public.core_relationship_evaluation i
                        WHERE i.response_history_event_id=response.history_event_id AND i.session_id=$1 AND i.generation=$2 AND i.context_sequence::text=$3)
                    AND NOT EXISTS(SELECT 1 FROM public.core_relationship_result r JOIN public.core_relationship_evaluation i ON i.id=r.evaluation_id
                        WHERE i.response_history_event_id=response.history_event_id AND i.session_id=$1 AND i.generation=$2
                            AND r.history_event_id>(SELECT history_event_id FROM public.core_runtime_context
                                WHERE session_id=$1 AND generation=$2 AND snapshot_sequence::text=$3))
                ORDER BY response.history_event_id LIMIT 1",[$event['session_id'],$event['generation'],strval($event['context_sequence'])]);
            if (!$result) throw new RuntimeException('Unable to discover dedicated relationship response');
            $row=pg_fetch_assoc($result);return $row ? ['response'=>intval($row['history_event_id'])] : [];
        },2000);
        if (isset($candidate['job'])) return $candidate['job'];
        if (isset($candidate['evaluation'])) {
            $input=$candidate['evaluation'];
            return synth_worker_with_origin($db,$input,static fn()=>synth_relationship_enqueue_evaluation($db,intval($input['id'])),'relationship');
        }
        if (!isset($candidate['response'])) return null;
        $input=synth_relationship_capture_configured_evaluation($db,$candidate['response'],$playerOnly);
        return $input===null ? null : synth_relationship_enqueue_evaluation($db,$input);
    },'relationship');
    return $jobId===null ? null : synth_relationship_run_persisted_job($db,$jobId,$generate);
}
