<?php

require_once __DIR__.'/synth_relationship_interaction_jobs.php';
require_once __DIR__.'/synth_relationship_jobs.php';

// One durable job per frozen pair; terminal records cannot be re-enqueued under a new attempt identity.
function synth_relationship_enqueue_interaction($db, int $interactionId): ?int
{
    if ($interactionId<1) throw new InvalidArgumentException('Invalid interaction evaluation key');
    if (!synthRelationshipUsesDedicatedConnector()) return null;
    return $db->withNativeRequestLock(static function($connection,array $event) use($db,$interactionId) {
        $input=synth_relationship_interaction_input($db,$interactionId);
        $capacity=$input ? synth_relationship_queue_has_capacity($connection,array_values($input['interaction']['actor_ids'])) : false;
        $jobs=\Synth\Core\DbManager\Dao\WorkerJobDao::current($db);
        $jobs->enqueueInteraction($interactionId,$event['session_id'],$event['request_id'],$event['turn_id'],$event['generation'],$event['context_sequence'],
            synth_protocol_canonical_json($event),$input ? 'pending' : 'stale',$input ? null : 'input_unavailable',($capacity || !$input));
        if (!$input) return null;
        return $jobs->activeJobForInteraction($interactionId);
    },2000);
}

// Input revalidation takes both control locks before touching the job row, matching administrative cancellation.
function synth_relationship_claim_interaction($db, int $jobId): ?array
{
    if ($jobId<1) throw new InvalidArgumentException('Invalid interaction job key');
    if (!synthRelationshipUsesDedicatedConnector()) return null;
    return $db->withNativeRequestLock(static function($connection,array $event) use($db,$jobId) {
        $jobs=\Synth\Core\DbManager\Dao\WorkerJobDao::current($db);
        $interactionId=$jobs->interactionOfAttempt($jobId,$event['session_id'],$event['request_id'],$event['generation'],$event['turn_id'],
            (string)$event['context_sequence'],synth_protocol_canonical_json($event));
        if ($interactionId===null) return null;
        $input=synth_relationship_interaction_input($db,intval($interactionId));
        if (!$input) {
            $jobs->retireInvalidInteractionJob($jobId);
            return null;
        }
        $jobs->failIfExpired($jobId);
        $claimed=$jobs->claimAttempt($jobId,bin2hex(random_bytes(16)));
        return $claimed ? $claimed+['input'=>$input] : null;
    },2000);
}

// Failure records contain fixed codes only; a cancelled, superseded or expired token cannot schedule a retry.
function synth_relationship_fail_interaction($db, int $jobId, string $token): bool
{
    if ($jobId<1 || !preg_match('/^[0-9a-f]{32}$/D',$token)) throw new InvalidArgumentException('Invalid interaction lease');
    return $db->withNativeRequestLock(static function($connection,array $event) use($jobId,$token) {
        return \Synth\Core\DbManager\Dao\WorkerJobDao::current()->failInteractionAttempt($jobId,$event['session_id'],$event['request_id'],$event['generation'],$event['turn_id'],
            (string)$event['context_sequence'],$token);
    },2000);
}
