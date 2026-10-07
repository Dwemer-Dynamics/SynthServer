<?php

// One actor-wide gate serializes administration with inline, queue and result publication.
function synth_relationship_control_lock($connection, int $actorId): array
{
    if (@pg_query_params($connection,"SELECT pg_advisory_xact_lock(hashtextextended('synth-relationship-control:'||$1,0))",[$actorId])===false) {
        throw new RuntimeException('Unable to lock relationship control.');
    }
    $result=@pg_query_params($connection,'SELECT revision,locked,barrier_event_id FROM synth_meta.relationship_actor_controls WHERE actor_identity_id=$1',[$actorId]);
    if (!$result) throw new RuntimeException('Unable to read relationship control.');
    return pg_fetch_assoc($result) ?: ['revision'=>'0','locked'=>'f','barrier_event_id'=>'0'];
}

// Administrative sequence barriers fence pre-change requests even if they finish after unlock.
// Accepted history is deliberately not filtered by this current-policy check.
function synth_relationship_write_allowed($connection, array $event, int $actorId, ?int $responseId=null): bool
{
    $control=synth_relationship_control_lock($connection,$actorId);
    if ($control['locked']==='t') return false;
    $result=@pg_query_params($connection,'SELECT 1 FROM synth_meta.protocol_events p
        WHERE p.session_id=$1 AND p.request_id=$2 AND p.id>$3
            AND ($4::bigint IS NULL OR EXISTS(SELECT 1 FROM synth_meta.relationship_response_inputs i
                JOIN synth_meta.protocol_events origin ON origin.session_id=i.session_id AND origin.request_id=i.request_id
                WHERE i.history_event_id=$4 AND i.actor_identity_id=$5 AND origin.id>$3))',
        [$event['session_id'],$event['request_id'],$control['barrier_event_id'],$responseId,$actorId]);
    if (!$result) throw new RuntimeException('Unable to verify relationship control boundary.');
    return pg_fetch_assoc($result)!==false;
}
