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
        $result=@pg_query_params($connection,"INSERT INTO synth_meta.worker_jobs
            (job_type,relationship_interaction_id,session_id,request_id,turn_id,generation,actor_identity_id,payload,state,last_error,deadline_at)
            SELECT 'relationship',i.id,i.session_id,i.request_id,$4,i.generation,i.speaker_identity_id,
                jsonb_build_object('context_sequence',i.context_sequence),$8,$9,clock_timestamp()+INTERVAL '10 minutes'
            FROM synth_meta.relationship_interaction_evaluations i
            WHERE i.id=$1 AND i.session_id=$2 AND i.request_id=$3 AND i.generation=$5 AND i.context_sequence=$6
                AND i.input_payload->'owner'=$7::jsonb AND $10::boolean
            ON CONFLICT DO NOTHING RETURNING id",
            [$interactionId,$event['session_id'],$event['request_id'],$event['turn_id'],$event['generation'],$event['context_sequence'],
                synth_protocol_canonical_json($event),$input ? 'pending' : 'stale',$input ? null : 'input_unavailable',
                ($capacity || !$input) ? 't' : 'f']);
        if (!$result) throw new RuntimeException('Unable to enqueue NPC interaction');
        if (!$input) return null;
        $result=@pg_query_params($connection,"SELECT id FROM synth_meta.worker_jobs WHERE relationship_interaction_id=$1
            AND state IN ('pending','running')",[$interactionId]);
        if (!$result) throw new RuntimeException('Unable to read NPC interaction replay');
        $row=pg_fetch_assoc($result);return $row ? intval($row['id']) : null;
    },2000);
}

// Input revalidation takes both control locks before touching the job row, matching administrative cancellation.
function synth_relationship_claim_interaction($db, int $jobId): ?array
{
    if ($jobId<1) throw new InvalidArgumentException('Invalid interaction job key');
    if (!synthRelationshipUsesDedicatedConnector()) return null;
    return $db->withNativeRequestLock(static function($connection,array $event) use($db,$jobId) {
        $result=@pg_query_params($connection,"SELECT j.relationship_interaction_id FROM synth_meta.worker_jobs j
            JOIN synth_meta.relationship_interaction_evaluations i ON i.id=j.relationship_interaction_id
                AND i.session_id=j.session_id AND i.request_id=j.request_id AND i.generation=j.generation
                AND i.context_sequence::text=j.payload->>'context_sequence' AND i.speaker_identity_id=j.actor_identity_id
            WHERE j.id=$1 AND j.job_type='relationship' AND j.relationship_evaluation_id IS NULL AND j.memory_batch_id IS NULL
                AND j.session_id=$2 AND j.request_id=$3 AND j.generation=$4 AND j.turn_id=$5 AND j.payload->>'context_sequence'=$6
                AND i.input_payload->'owner'=$7::jsonb",
            [$jobId,$event['session_id'],$event['request_id'],$event['generation'],$event['turn_id'],(string)$event['context_sequence'],
                synth_protocol_canonical_json($event)]);
        if (!$result) throw new RuntimeException('Unable to resolve NPC interaction attempt');
        $row=pg_fetch_assoc($result);if (!$row) return null;
        $input=synth_relationship_interaction_input($db,intval($row['relationship_interaction_id']));
        if (!$input) {
            $result=@pg_query_params($connection,"UPDATE synth_meta.worker_jobs SET state='stale',lease_token=NULL,lease_until=NULL,
                last_error='input_unavailable',updated_at=clock_timestamp() WHERE id=$1
                AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp()))",[$jobId]);
            if (!$result) throw new RuntimeException('Unable to retire invalid NPC interaction');
            return null;
        }
        $result=@pg_query_params($connection,"UPDATE synth_meta.worker_jobs SET state='failed',lease_token=NULL,lease_until=NULL,
            last_error='lease_expired',updated_at=clock_timestamp() WHERE id=$1 AND state IN ('pending','running')
            AND (deadline_at<=clock_timestamp() OR (attempts>=3 AND lease_until<=clock_timestamp()))",[$jobId]);
        if (!$result) throw new RuntimeException('Unable to retire expired NPC interaction');
        $result=@pg_query_params($connection,"UPDATE synth_meta.worker_jobs SET state='running',attempts=attempts+1,lease_token=$2,
            lease_until=LEAST(deadline_at,clock_timestamp()+INTERVAL '2 minutes'),updated_at=clock_timestamp()
            WHERE id=$1 AND attempts<3 AND deadline_at>clock_timestamp() AND available_at<=clock_timestamp()
                AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp())) RETURNING id,lease_token,attempts",
            [$jobId,bin2hex(random_bytes(16))]);
        if (!$result) throw new RuntimeException('Unable to claim NPC interaction');
        $claimed=pg_fetch_assoc($result);return $claimed ? $claimed+['input'=>$input] : null;
    },2000);
}

// Failure records contain fixed codes only; a cancelled, superseded or expired token cannot schedule a retry.
function synth_relationship_fail_interaction($db, int $jobId, string $token): bool
{
    if ($jobId<1 || !preg_match('/^[0-9a-f]{32}$/D',$token)) throw new InvalidArgumentException('Invalid interaction lease');
    return $db->withNativeRequestLock(static function($connection,array $event) use($jobId,$token) {
        $result=@pg_query_params($connection,"UPDATE synth_meta.worker_jobs SET state=CASE WHEN attempts>=3 OR deadline_at<=clock_timestamp()
            THEN 'failed' ELSE 'pending' END,lease_token=NULL,lease_until=NULL,available_at=clock_timestamp()+INTERVAL '10 seconds',
            last_error='provider_failed',updated_at=clock_timestamp() WHERE id=$1 AND job_type='relationship'
            AND relationship_interaction_id IS NOT NULL AND relationship_evaluation_id IS NULL AND memory_batch_id IS NULL
            AND session_id=$2 AND request_id=$3 AND generation=$4 AND turn_id=$5 AND payload->>'context_sequence'=$6
            AND state='running' AND lease_token=$7 AND lease_until>clock_timestamp() RETURNING id",
            [$jobId,$event['session_id'],$event['request_id'],$event['generation'],$event['turn_id'],(string)$event['context_sequence'],$token]);
        if (!$result) throw new RuntimeException('Unable to record NPC interaction failure');
        return pg_fetch_assoc($result)!==false;
    },2000);
}
