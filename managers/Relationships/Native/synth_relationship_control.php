<?php

// One actor-wide gate serializes administration with inline, queue and result publication.
function synth_relationship_control_lock($connection, int $actorId): array
{
    $control=\Synth\Core\DbManager\Dao\RelationshipActorControlDao::current();
    $control->lock($actorId);
    return $control->control($actorId) ?: ['revision'=>'0','locked'=>'f','barrier_event_id'=>'0'];
}

// Administrative sequence barriers fence pre-change requests even if they finish after unlock.
// Accepted history is deliberately not filtered by this current-policy check.
function synth_relationship_write_allowed($connection, array $event, int $actorId, ?int $responseId=null): bool
{
    $control=synth_relationship_control_lock($connection,$actorId);
    if ($control['locked']==='t') return false;
    return \Synth\Core\DbManager\Dao\ProtocolEventDao::current()->pastControlBarrier($event['session_id'],$event['request_id'],$control['barrier_event_id'],$responseId,$actorId);
}
