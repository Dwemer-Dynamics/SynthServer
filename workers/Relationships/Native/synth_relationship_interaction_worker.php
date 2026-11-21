<?php

require_once __DIR__.'/synth_relationship_interaction_result.php';
require_once __DIR__.'/synth_relationship_worker.php';

// A persisted pair keeps its speaking request as owner; listener authority is limited to the frozen perspective.
function synth_relationship_run_persisted_interaction($db, int $jobId, ?callable $generate=null): ?int
{
    if ($jobId<1) throw new InvalidArgumentException('Invalid interaction job key');
    if (!synthRelationshipUsesDedicatedConnector()) return null;
    if (!$db->isTransactionIdle()) throw new LogicException('Interaction providers require an idle database connection');
    $job=\Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->persistedInteractionOrigin($jobId,synth_relationship_responder_sql('p.canonical_event','i.speaker_identity_id',"i.input_payload->'context'"));
    if (!$job) return null;
    return synth_worker_with_origin($db,$job,static fn()=>synth_relationship_process_interaction($db,$jobId,
        $generate ?? static fn(array $input)=>synth_relationship_generate_evaluation($db,$input)),'relationship');
}

// Providers run after the claim transaction commits; only fenced atomic publication can mark this job ready.
function synth_relationship_process_interaction($db, int $jobId, callable $generate): ?int
{
    if (!$db->isTransactionIdle()) throw new LogicException('Interaction providers require an idle database connection');
    $job=synth_relationship_claim_interaction($db,$jobId);if (!$job) return null;
    $previous=\Synth\Bootstrap::config('SYNTH_INTERACTION_JOB_LEASE') ?? null;
    \Synth\Bootstrap::set('SYNTH_INTERACTION_JOB_LEASE', ['id'=>$jobId,'token'=>$job['lease_token']]);
    try {
        $text=$generate($job['input']);
        if (!is_string($text)) throw new InvalidArgumentException('Invalid interaction model result');
        return synth_relationship_complete_interaction($db,$jobId,$job['lease_token'],$text);
    } catch (SYNTHProtocolCancelled $error) {throw $error;
    } catch (Throwable $error) {
        synth_relationship_fail_interaction($db,$jobId,$job['lease_token']);throw $error;
    } finally {
        if ($previous===null) \Synth\Bootstrap::forget('SYNTH_INTERACTION_JOB_LEASE');
        else \Synth\Bootstrap::set('SYNTH_INTERACTION_JOB_LEASE', $previous);
    }
}
