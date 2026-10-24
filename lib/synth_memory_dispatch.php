<?php

require_once __DIR__.'/synth_memory_worker.php';

// Discover one completed native turn, reserve its session briefly, then do all provider work outside SQL locks.
function synth_memory_dispatch($db, ?callable $generate = null): ?int
{
    $settings = $GLOBALS['FEATURES']['MEMORY_EMBEDDING'] ?? [];
    $automatic = $settings['AUTO_CREATE_SUMMARYS'] ?? true;
    if ($automatic === false || in_array(strtolower(trim(strval($automatic))),['0','false','off','no'],true)) return null;
    if (!$db->isTransactionIdle()) throw new LogicException('Memory dispatch requires an idle database connection');
    $minimumEvents = max(1,min(150,intval($settings['AUTO_CREATE_SUMMARY_MIN_EVENTS'] ?? 5)));
    $intervalTicks = max(1,min(1000000,intval($settings['AUTO_CREATE_SUMMARY_INTERVAL'] ?? 10))) * 100000;
    if ($db->execQuery('BEGIN ISOLATION LEVEL READ COMMITTED') === false) throw new RuntimeException('Unable to begin memory dispatch');
    try {
        if ($db->execQuery("SET LOCAL statement_timeout='2s'") === false) throw new RuntimeException('Unable to bound memory dispatch');
        $selected = $db->execQuery("WITH candidate AS MATERIALIZED (
            SELECT r.session_id,o.request_id,o.turn_id,o.generation,o.event_type,
                o.canonical_event,o.event_sha256,r.active_turn_sequence AS context_sequence
            FROM synth_meta.runtime_sessions r
            CROSS JOIN LATERAL (
                SELECT e.request_id,e.turn_id,e.generation,e.event_type,p.canonical_event,p.event_sha256
                FROM synth_meta.request_executions e
                JOIN synth_meta.protocol_events p ON p.session_id=e.session_id AND p.request_id=e.request_id
                    AND p.generation=e.generation AND p.turn_id=e.turn_id AND p.event_type=e.event_type
                    AND p.event_sha256=e.request_sha256
                WHERE e.session_id=r.session_id AND e.generation=r.active_generation
                    AND e.turn_id=r.active_turn_id AND e.state='complete'
                    AND e.event_type IN ('input_text','input_audio','trigger','action_result','activate')
                    AND p.canonical_event->>'protocol_version'='2'
                    AND p.canonical_event->>'runtime_variant'=r.runtime_variant
                    AND p.canonical_event->>'context_sequence'=r.active_turn_sequence::text
                    AND p.canonical_event->'capabilities' ? 'dialogue.turn_ownership'
                    AND (e.event_type<>'activate' OR p.canonical_event->'payload'->>'source'='manual')
                    -- Closed or non-NPC continuations must not hide this turn's completed NPC dialogue.
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
            WHERE r.state='active' AND (r.memory_dispatch_after IS NULL OR r.memory_dispatch_after<=clock_timestamp())
            ORDER BY r.memory_dispatch_after NULLS FIRST,r.session_id LIMIT 1 FOR UPDATE OF r SKIP LOCKED
        ), reserved AS (
            UPDATE synth_meta.runtime_sessions r SET memory_dispatch_after=clock_timestamp()+INTERVAL '30 seconds'
            FROM candidate c WHERE r.session_id=c.session_id RETURNING r.session_id
        ) SELECT c.* FROM candidate c JOIN reserved USING (session_id)");
        if ($selected === false) throw new RuntimeException('Unable to select memory dispatch');
        $origin = pg_fetch_assoc($selected);
        if ($db->execQuery('COMMIT') === false) throw new RuntimeException('Unable to reserve memory dispatch');
    } finally {
        if (!$db->isTransactionIdle()) $db->execQuery('ROLLBACK');
    }
    if (!$origin) return null;
    $jobId = synth_memory_with_origin($db,$origin,static function(array $event) use ($db,$minimumEvents,$intervalTicks) {
        // Recover due attempts first, then frozen-but-unscheduled inputs. Never endlessly recapture a covered batch.
        $candidate = $db->withNativeRequestLock(static function($connection,array $event) use ($db) {
            $result = @pg_query_params($connection,"SELECT id FROM synth_meta.worker_jobs
                WHERE job_type='memory' AND memory_batch_id IS NOT NULL AND session_id=$1 AND turn_id=$2
                    AND generation=$3 AND payload->>'context_sequence'=$4 AND state IN ('pending','running')
                    AND (deadline_at<=clock_timestamp() OR available_at<=clock_timestamp())
                    AND (state='pending' OR lease_until<=clock_timestamp())
                ORDER BY available_at,id LIMIT 1",
                [$event['session_id'],$event['turn_id'],$event['generation'],strval($event['context_sequence'])]);
            if (!$result) throw new RuntimeException('Unable to select memory retry');
            $job = pg_fetch_assoc($result);
            if ($job) return ['job'=>intval($job['id'])];
            $result = @pg_query($connection,synth_memory_visible_batch_cte($db,$event)
                ."SELECT b.id FROM native_memory_batches b
                    WHERE NOT EXISTS (SELECT 1 FROM synth_meta.memory_summaries s WHERE s.batch_id=b.id)
                    AND NOT EXISTS (SELECT 1 FROM synth_meta.worker_jobs j WHERE j.memory_batch_id=b.id
                        AND j.job_type='memory' AND (j.state='ready'
                            OR (j.state IN ('pending','running') AND j.deadline_at>clock_timestamp()
                                AND NOT (j.attempts>=3 AND j.lease_until<=clock_timestamp()))
                            OR (j.state='failed' AND j.session_id=".$db->escapeLiteral($event['session_id'])."
                                AND j.request_id=".$db->escapeLiteral($event['request_id']).")))
                    ORDER BY b.first_gamets,b.id LIMIT 1");
            if (!$result) throw new RuntimeException('Unable to recover memory input');
            $batch = pg_fetch_assoc($result);
            if ($batch) return ['batch'=>intval($batch['id'])];
            $context=synth_memory_response_context($connection,$db,$event);
            if (!$context || !$context['target_identity_id']) return ['full'=>true];
            $result = @pg_query_params($connection,"SELECT count(*) AS n FROM synth_meta.worker_jobs
                WHERE job_type='memory' AND memory_batch_id IS NOT NULL AND actor_identity_id=$1
                    AND state IN ('pending','running') AND deadline_at>clock_timestamp()",
                [$context['target_identity_id']]);
            if (!$result) throw new RuntimeException('Unable to check memory capacity');
            return ['full'=>intval(pg_fetch_assoc($result)['n'])>=2];
        },2000);
        if (isset($candidate['job'])) return $candidate['job'];
        if (!empty($candidate['full'])) return null;
        $batchId = $candidate['batch'] ?? synth_memory_rebuild_batch($db) ?? synth_memory_capture_batch($db,$minimumEvents,$intervalTicks);
        return $batchId === null ? null : synth_memory_enqueue($db,$batchId);
    });
    return $jobId === null ? null : synth_memory_run_persisted_job($db,$jobId,$generate);
}
