<?php

require_once __DIR__.'/synth_relationship_worker.php';

// The two evaluation lanes have independent cooldowns; the shared actor queue still bounds their combined work.
function synth_relationship_reserve_dispatch($db, bool $interaction=false): ?array
{
    $after=$interaction ? 'interaction_dispatch_after' : 'relationship_dispatch_after';
    $control=$interaction ? '' : "AND NOT EXISTS(SELECT 1 FROM synth_meta.relationship_actor_controls control
        WHERE control.actor_identity_id=c.target_identity_id AND control.locked)";
    if (!$db->isTransactionIdle()) throw new LogicException('Relationship dispatch requires an idle database connection');
    if ($db->execQuery('BEGIN ISOLATION LEVEL READ COMMITTED')===false) throw new RuntimeException('Unable to begin relationship dispatch');
    try {
        if ($db->execQuery("SET LOCAL statement_timeout='2s'")===false) throw new RuntimeException('Unable to bound relationship dispatch');
        $selected=$db->execQuery("WITH candidate AS MATERIALIZED (
            SELECT r.session_id,o.request_id,o.turn_id,o.generation,o.event_type,
                o.canonical_event,o.event_sha256,r.active_turn_sequence AS context_sequence
            FROM synth_meta.runtime_sessions r
            CROSS JOIN LATERAL (
                SELECT e.request_id,e.turn_id,e.generation,e.event_type,p.canonical_event,p.event_sha256
                FROM synth_meta.request_executions e
                JOIN synth_meta.protocol_events p ON p.session_id=e.session_id AND p.request_id=e.request_id
                    AND p.generation=e.generation AND p.turn_id=e.turn_id AND p.event_type=e.event_type AND p.event_sha256=e.request_sha256
                WHERE e.session_id=r.session_id AND e.generation=r.active_generation AND e.turn_id=r.active_turn_id AND e.state='complete'
                    AND e.event_type IN ('input_text','input_audio','trigger','action_result','activate')
                    AND p.canonical_event->>'protocol_version'='2' AND p.canonical_event->>'runtime_variant'=r.runtime_variant
                    AND p.canonical_event->>'context_sequence'=r.active_turn_sequence::text
                    AND p.canonical_event->'capabilities' ? 'dialogue.turn_ownership'
                    AND (e.event_type<>'activate' OR p.canonical_event->'payload'->>'source'='manual')
                    -- A terminal or non-NPC continuation cannot replace a usable completed dialogue owner.
                    AND (e.event_type<>'trigger' OR p.canonical_event->'payload'->>'kind' IS DISTINCT FROM 'rechat'
                        OR EXISTS (SELECT 1 FROM synth_meta.rechat_selections selection
                            JOIN synth_meta.rechat_continuations continuation USING(session_id,request_id)
                            JOIN synth_meta.rechat_chains chain
                                ON chain.id=continuation.chain_id AND chain.session_id=continuation.session_id
                            WHERE selection.session_id=e.session_id AND selection.request_id=e.request_id
                                AND selection.prepared_at IS NOT NULL AND selection.role='npc'
                                AND continuation.status='reserved'
                                AND COALESCE(chain.terminal_reason,'') NOT IN ('private','no_candidate','ineligible')
                                AND chain.generation=e.generation AND chain.turn_id=e.turn_id
                                AND chain.context_sequence=r.active_turn_sequence AND chain.runtime_variant=r.runtime_variant))
                ORDER BY e.claimed_at DESC,e.request_id DESC LIMIT 1
            ) o
            JOIN synth_meta.context_snapshots c ON c.session_id=r.session_id AND c.generation=r.active_generation
                AND c.snapshot_sequence=r.active_turn_sequence AND c.playthrough_id=r.playthrough_id
                AND c.target_identity_id IS NOT NULL AND c.history_event_id IS NOT NULL
            WHERE r.state='active' $control AND (r.$after IS NULL OR r.$after<=clock_timestamp())
            ORDER BY r.$after NULLS FIRST,r.session_id LIMIT 1 FOR UPDATE OF r SKIP LOCKED
        ), reserved AS (
            UPDATE synth_meta.runtime_sessions r SET $after=clock_timestamp()+INTERVAL '2 seconds'
            FROM candidate c WHERE r.session_id=c.session_id RETURNING r.session_id
        ) SELECT c.* FROM candidate c JOIN reserved USING(session_id)");
        if (!$selected) throw new RuntimeException('Unable to select relationship dispatch');
        $origin=pg_fetch_assoc($selected);
        if ($db->execQuery('COMMIT')===false) throw new RuntimeException('Unable to reserve relationship dispatch');
    } finally {
        if (!$db->isTransactionIdle()) $db->execQuery('ROLLBACK');
    }
    return $origin ?: null;
}

// Reserve one active completed owner fairly, recover frozen work first, and release SQL locks before providers.
function synth_relationship_dispatch($db, ?callable $generate=null, bool $playerOnly=false): ?int
{
    if (!synthRelationshipUsesDedicatedConnector()) return null;
    $origin=synth_relationship_reserve_dispatch($db);if (!$origin) return null;
    $jobId=synth_worker_with_origin($db,$origin,static function(array $event) use($db,$playerOnly) {
        $candidate=$db->withNativeRequestLock(static function($connection,array $event) use($db,$playerOnly) {
            $params=[$event['session_id'],$event['generation'],$event['turn_id'],strval($event['context_sequence'])];
            $inputScope=$playerOnly ? "AND relationship_evaluation_id IN (SELECT id FROM synth_meta.relationship_evaluations
                WHERE input_payload->>'schema'='synth.relationship.evaluation.v2')" : '';
            $result=@pg_query_params($connection,"UPDATE synth_meta.worker_jobs j SET state='cancelled',lease_token=NULL,lease_until=NULL,
                last_error='owner_unavailable',updated_at=clock_timestamp() WHERE job_type='relationship' AND relationship_evaluation_id IS NOT NULL
                    AND session_id=$1 AND state IN ('pending','running') AND (generation<>$2 OR turn_id<>$3 OR payload->>'context_sequence' IS DISTINCT FROM $4
                        OR memory_batch_id IS NOT NULL OR actor_identity_id IS DISTINCT FROM(SELECT target_identity_id FROM synth_meta.context_snapshots
                            WHERE session_id=$1 AND generation=$2 AND snapshot_sequence::text=$4)
                        OR NOT EXISTS(SELECT 1 FROM synth_meta.request_executions e
                            JOIN synth_meta.protocol_events p ON p.session_id=e.session_id AND p.request_id=e.request_id AND p.event_sha256=e.request_sha256
                            JOIN synth_meta.relationship_evaluations i ON i.id=j.relationship_evaluation_id AND i.input_payload->'owner'=p.canonical_event
                            WHERE e.session_id=j.session_id AND e.request_id=j.request_id
                                AND e.generation=j.generation AND e.turn_id=j.turn_id AND e.state='complete'))",$params);
            if (!$result) throw new RuntimeException('Unable to retire obsolete relationship owner');
            $result=@pg_query_params($connection,"UPDATE synth_meta.worker_jobs SET state='failed',lease_token=NULL,lease_until=NULL,
                last_error='lease_expired',updated_at=clock_timestamp() WHERE job_type='relationship' AND relationship_evaluation_id IS NOT NULL
                    AND session_id=$1 AND state IN ('pending','running')
                    AND (deadline_at<=clock_timestamp() OR (attempts>=3 AND lease_until<=clock_timestamp()))",[$event['session_id']]);
            if (!$result) throw new RuntimeException('Unable to retire expired relationship dispatch');
            $result=@pg_query_params($connection,"SELECT id FROM synth_meta.worker_jobs
                WHERE job_type='relationship' AND relationship_evaluation_id IS NOT NULL AND memory_batch_id IS NULL
                    AND session_id=$1 AND generation=$2 AND turn_id=$3 AND payload->>'context_sequence'=$4 $inputScope
                    AND available_at<=clock_timestamp() AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp()))
                ORDER BY available_at,id LIMIT 1",$params);
            if (!$result) throw new RuntimeException('Unable to select relationship retry');
            if ($row=pg_fetch_assoc($result)) return ['job'=>intval($row['id'])];
            $frozenScope=$playerOnly ? "AND i.input_payload->>'schema'='synth.relationship.evaluation.v2'" : '';
            $result=@pg_query_params($connection,"SELECT i.id,i.session_id,i.request_id,i.generation,i.context_sequence,
                    e.turn_id,e.event_type,p.canonical_event,p.event_sha256
                FROM synth_meta.relationship_evaluations i
                JOIN synth_meta.request_executions e ON e.session_id=i.session_id AND e.request_id=i.request_id
                    AND e.generation=i.generation AND e.turn_id=$3 AND e.state='complete'
                JOIN synth_meta.protocol_events p ON p.session_id=e.session_id AND p.request_id=e.request_id AND p.event_sha256=e.request_sha256
                WHERE i.session_id=$1 AND i.generation=$2 AND i.context_sequence::text=$4 $frozenScope
                    AND i.input_payload->'owner'=p.canonical_event
                    AND NOT EXISTS(SELECT 1 FROM synth_meta.worker_jobs j WHERE j.relationship_evaluation_id=i.id)
                ORDER BY i.id LIMIT 1",$params);
            if (!$result) throw new RuntimeException('Unable to recover frozen relationship dispatch');
            if ($row=pg_fetch_assoc($result)) return ['evaluation'=>$row];
            $responseScope=$playerOnly ? "AND response.response_payload->>'schema'='synth.relationship.response.v3'
                AND EXISTS(SELECT 1 FROM native_ancestry n JOIN synth_meta.context_snapshots c ON c.session_id=n.session_id
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
            $result=@pg_query_params($connection,synth_relationship_visible_cte($db,$event,true)."
                SELECT response.history_event_id FROM relationship_response_evidence response
                WHERE response.response_payload->>'evaluation_mode'='dedicated' $responseScope
                    AND NOT EXISTS(SELECT 1 FROM synth_meta.relationship_actor_controls control
                        JOIN synth_meta.protocol_events origin ON origin.session_id=response.session_id AND origin.request_id=response.request_id
                        WHERE control.actor_identity_id=response.actor_identity_id AND (control.locked OR origin.id<=control.barrier_event_id))
                    AND NOT EXISTS(SELECT 1 FROM native_relationship_results r WHERE r.response_history_event_id=response.history_event_id)
                    AND NOT EXISTS(SELECT 1 FROM synth_meta.relationship_evaluations i
                        WHERE i.response_history_event_id=response.history_event_id AND i.session_id=$1 AND i.generation=$2 AND i.context_sequence::text=$3)
                    AND NOT EXISTS(SELECT 1 FROM synth_meta.relationship_results r JOIN synth_meta.relationship_evaluations i ON i.id=r.evaluation_id
                        WHERE i.response_history_event_id=response.history_event_id AND i.session_id=$1 AND i.generation=$2
                            AND r.history_event_id>(SELECT history_event_id FROM synth_meta.context_snapshots
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
