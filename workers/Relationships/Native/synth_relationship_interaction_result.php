<?php

require_once __DIR__.'/synth_relationship_interaction_queue.php';
require_once __DIR__.'/synth_relationship_interaction_model.php';

// Each evaluated response is consumed only within its reachable timeline, never globally across restored saves.
function synth_relationship_paired_response_consumed($connection, $db, array $event, int $responseId, array $listenerIds): bool
{
    if (!$listenerIds) return false;
    foreach ($listenerIds as $id) if (!is_int($id) || $id<1) throw new InvalidArgumentException('Invalid evaluated relationship subject');
    $result=@pg_query_params($connection,synth_protocol_history_cte($db,$event)."
        SELECT 1 FROM public.core_relationship_interaction_result result
        JOIN public.core_relationship_interaction_evaluation i ON i.id=result.interaction_id
        JOIN public.core_history_event h ON h.id=result.history_event_id
        WHERE i.response_history_event_id=$1 AND i.listener_identity_id=ANY($2::bigint[])
            AND (EXISTS(SELECT 1 FROM native_history_scopes scope WHERE scope.session_id=h.session_id
                AND scope.generation=h.generation AND scope.playthrough_id=h.playthrough_id AND h.id<=scope.cutoff)
                OR (h.session_id=$3 AND h.generation=$4)) LIMIT 1",
        [$responseId,'{'.implode(',',array_unique($listenerIds)).'}',$event['session_id'],$event['generation']]);
    if (!$result) throw new RuntimeException('Unable to check paired response consumption');
    return pg_fetch_assoc($result)!==false;
}

// Publication never impersonates the listener: the result and both revisions retain the original canonical request.
function synth_relationship_complete_interaction($db, int $jobId, string $token, string $modelText): int
{
    if ($jobId<1 || !preg_match('/^[0-9a-f]{32}$/D',$token)) throw new InvalidArgumentException('Invalid interaction lease');
    if (!synthRelationshipUsesDedicatedConnector()) throw new LogicException('Dedicated relationships are disabled');
    return $db->withNativeRequestLock(static function($connection,array $event) use($db,$jobId,$token,$modelText) {
        $job=\Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->interactionCompletionState($jobId,$event['session_id'],$event['request_id'],$event['generation'],$event['turn_id'],
            (string)$event['context_sequence'],synth_protocol_canonical_json($event));
        if (!$job || !hash_equals($job['lease_token'] ?? '',$token) || !in_array($job['state'],['running','ready'],true)
            || ($job['state']==='running' && $job['live']!=='t')) throw new SYNTHProtocolCancelled('interaction_lease_lost');
        $input=json_decode($job['input_payload'],true,flags:JSON_THROW_ON_ERROR);
        $parsed=synth_relationship_parse_interaction_result($modelText,$input);
        $json=json_encode($parsed,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if ($job['state']==='ready') {
            // Idempotent acknowledgement is not a new publication and does not reapply current actor policy.
            $replayed=\Synth\Core\DbManager\Dao\RelationshipInteractionResultDao::current($db)->replayFor($jobId,$job['id'],$json,$event['session_id'],$event['request_id'],
                $event['generation'],$event['context_sequence'],$job['input_sha256']);
            if ($replayed===null) throw new LogicException('Conflicting NPC interaction replay');
            return intval($replayed);
        }
        $validated=synth_relationship_interaction_input($db,intval($job['id']));
        if (!$validated || $validated!=$input) throw new SYNTHProtocolCancelled('interaction_input_unavailable');
        $actors=$input['interaction']['actor_ids'];
        if (synth_relationship_paired_response_consumed($connection,$db,$event,intval($job['response_history_event_id']),[$actors['listener']])) {
            throw new LogicException('NPC interaction response already evaluated');
        }
        $result=@pg_query_params($connection,synth_protocol_history_cte($db,$event)."
            SELECT 1 FROM public.core_relationship_result r JOIN public.core_relationship_evaluation i ON i.id=r.evaluation_id
            JOIN public.core_history_event h ON h.id=r.history_event_id
            WHERE i.response_history_event_id=$1 AND i.actor_identity_id=$2
                AND i.input_payload->'subjects' @> jsonb_build_array(jsonb_build_object('subject_id',$5::bigint)) AND (
                EXISTS(SELECT 1 FROM native_history_scopes scope WHERE scope.session_id=h.session_id AND scope.generation=h.generation
                    AND scope.playthrough_id=h.playthrough_id AND h.id<=scope.cutoff) OR (h.session_id=$3 AND h.generation=$4)) LIMIT 1",
            [$job['response_history_event_id'],$actors['speaker'],$event['session_id'],$event['generation'],$actors['listener']]);
        if (!$result) throw new RuntimeException('Unable to verify single-speaker response consumption');
        if (pg_fetch_assoc($result)) throw new LogicException('Relationship response already evaluated');
        // Both controls are held in sorted order. Pair locks also coordinate with ordinary inline/single-owner writers.
        $directions=[$actors['speaker']=>$actors['listener'],$actors['listener']=>$actors['speaker']];ksort($directions,SORT_NUMERIC);
        foreach ($directions as $actor=>$subject) {
            \Synth\Core\DbManager\Dao\RelationshipRevisionDao::current($db)->lockPair("synth-relationship:$actor:$subject");
            if (\Synth\Core\DbManager\Dao\RelationshipRevisionDao::current($db)->existsAfterContext($event['session_id'],$event['generation'],$actor,$subject,$event['context_sequence'])) {
                throw new LogicException('NPC interaction perspective is stale');
            }
        }
        $resultId=\Synth\Core\DbManager\Dao\RelationshipInteractionResultDao::current($db)->publish($input['context']['playthrough_id'],intval($input['context']['world']['game_time_ticks'] ?? 0),time(),
            $event['session_id'],$event['generation'],$event['context_sequence'],$event['request_id'],$job['input_sha256'],$json,$job['id'],$jobId);
        foreach ($input['interaction']['relationships'] as $role=>$prior) {
            if ($prior!==null) \Synth\Core\DbManager\Dao\RelationshipInteractionResultPriorDao::current($db)->add($resultId,$actors[$role],$prior['revision']);
        }
        foreach ($parsed['changes'] as $change) {
            $details=array_diff_key($change['state'],array_flip(['aff','type']));synth_relationship_validate_details($details);
            $revision=['schema'=>'synth.relationship.interaction.revision.v1','interaction_result'=>$resultId,'role'=>$change['role'],
                'prior'=>$change['prior_revision'],'state'=>$change['state'],'sources'=>[intval($job['response_history_event_id'])]];
            $revisionJson=json_encode($revision,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $detailsJson=json_encode((object)$details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $revisionId=\Synth\Core\DbManager\Dao\RelationshipRevisionDao::current($db)->publishInteractionPerspective($input['context']['playthrough_id'],
                intval($input['context']['world']['game_time_ticks'] ?? 0),time(),$event['session_id'],$event['generation'],$event['context_sequence'],
                $event['request_id'],$change['actor_id'],$change['subject_id'],$change['prior_revision'],$change['state']['aff'],$change['state']['type'],
                $revisionJson,$resultId,$detailsJson);
            \Synth\Core\DbManager\Dao\RelationshipRevisionSourceDao::current($db)->add($revisionId,$job['response_history_event_id']);
        }
        if (!\Synth\Core\DbManager\Dao\WorkerJobDao::current($db)->markReady($jobId,$token)) throw new SYNTHProtocolCancelled('interaction_lease_lost');
        return $resultId;
    },2000);
}
