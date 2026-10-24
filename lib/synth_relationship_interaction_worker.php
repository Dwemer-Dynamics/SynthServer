<?php

require_once __DIR__.'/synth_relationship_interaction_result.php';
require_once __DIR__.'/synth_relationship_worker.php';

// A persisted pair keeps its speaking request as owner; listener authority is limited to the frozen perspective.
function synth_relationship_run_persisted_interaction($db, int $jobId, ?callable $generate=null): ?int
{
    if ($jobId<1) throw new InvalidArgumentException('Invalid interaction job key');
    if (!synthRelationshipUsesDedicatedConnector()) return null;
    if (!$db->isTransactionIdle()) throw new LogicException('Interaction providers require an idle database connection');
    $job=$db->fetchOne("SELECT j.session_id,j.request_id,j.turn_id,j.generation,
            j.payload->>'context_sequence' AS context_sequence,p.event_type,p.canonical_event,p.event_sha256
        FROM synth_meta.worker_jobs j JOIN synth_meta.relationship_interaction_evaluations i ON i.id=j.relationship_interaction_id
            AND i.session_id=j.session_id AND i.request_id=j.request_id AND i.generation=j.generation
            AND i.context_sequence::text=j.payload->>'context_sequence' AND i.speaker_identity_id=j.actor_identity_id
        JOIN synth_meta.request_executions e ON e.session_id=j.session_id AND e.request_id=j.request_id
            AND e.generation=j.generation AND e.turn_id=j.turn_id AND e.state='complete'
        JOIN synth_meta.protocol_events p ON p.session_id=e.session_id AND p.request_id=e.request_id
            AND p.generation=e.generation AND p.turn_id=e.turn_id AND p.event_type=e.event_type AND p.event_sha256=e.request_sha256
            AND p.canonical_event=i.input_payload->'owner'
        JOIN synth_meta.runtime_sessions r ON r.session_id=j.session_id AND r.active_generation=j.generation
            AND r.state='active' AND p.canonical_event->>'runtime_variant'=r.runtime_variant
        JOIN synth_meta.context_snapshots c ON c.session_id=j.session_id AND c.generation=j.generation
            AND c.snapshot_sequence=i.context_sequence AND c.playthrough_id=r.playthrough_id
        WHERE j.id=".intval($jobId)." AND j.job_type='relationship' AND j.relationship_evaluation_id IS NULL
            AND j.memory_batch_id IS NULL AND j.state IN ('pending','running')
            AND ".synth_relationship_responder_sql('p.canonical_event','i.speaker_identity_id',"i.input_payload->'context'"));
    if (!$job) return null;
    return synth_worker_with_origin($db,$job,static fn()=>synth_relationship_process_interaction($db,$jobId,
        $generate ?? static fn(array $input)=>synth_relationship_generate_evaluation($db,$input)),'relationship');
}

// Providers run after the claim transaction commits; only fenced atomic publication can mark this job ready.
function synth_relationship_process_interaction($db, int $jobId, callable $generate): ?int
{
    if (!$db->isTransactionIdle()) throw new LogicException('Interaction providers require an idle database connection');
    $job=synth_relationship_claim_interaction($db,$jobId);if (!$job) return null;
    $previous=$GLOBALS['SYNTH_INTERACTION_JOB_LEASE'] ?? null;
    $GLOBALS['SYNTH_INTERACTION_JOB_LEASE']=['id'=>$jobId,'token'=>$job['lease_token']];
    try {
        $text=$generate($job['input']);
        if (!is_string($text)) throw new InvalidArgumentException('Invalid interaction model result');
        return synth_relationship_complete_interaction($db,$jobId,$job['lease_token'],$text);
    } catch (SYNTHProtocolCancelled $error) {throw $error;
    } catch (Throwable $error) {
        synth_relationship_fail_interaction($db,$jobId,$job['lease_token']);throw $error;
    } finally {
        if ($previous===null) unset($GLOBALS['SYNTH_INTERACTION_JOB_LEASE']);
        else $GLOBALS['SYNTH_INTERACTION_JOB_LEASE']=$previous;
    }
}
