<?php

require_once __DIR__.'/synth_relationship.php';
require_once __DIR__.'/synth_relationship_responder.php';

// A recorded addressee selects a canonical NPC pair, not every audience member or a display-name guess.
function synth_relationship_interaction_participants(array $context, array $entry, ?array $responder=null): ?array
{
    if (($entry['routing']['availability'] ?? null)!=='addressed' || !is_array($entry['speaker'] ?? null)
        || !is_array($entry['routing']['listener'] ?? null) || !is_array($context['target'] ?? null)
        || !is_array($context['player'] ?? null) || !is_string($context['playthrough_id'] ?? null)) return null;
    $resolved=[];
    foreach (['speaker'=>$entry['speaker'],'listener'=>$entry['routing']['listener']] as $role=>$identity) {
        if (!is_string($identity['form_id'] ?? null) || !is_string($identity['origin_plugin'] ?? null)
            || ($identity['playthrough_id'] ?? null)!==($context['playthrough_id'] ?? null)) return null;
        $matches=[];
        foreach (array_merge([$context['player'],$context['target']],$context['audience'] ?? []) as $actor) {
            if (!is_array($actor) || ($actor['playthrough_id'] ?? null)!==$identity['playthrough_id']
                || strcasecmp($actor['form_id'] ?? '',$identity['form_id'])!==0
                || strcasecmp($actor['origin_plugin'] ?? '',$identity['origin_plugin'])!==0) continue;
            $matches[strtolower($actor['form_id'].'|'.$actor['origin_plugin'])]=$actor;
        }
        if (count($matches)!==1) return null;
        $resolved[$role]=reset($matches);
    }
    $key=static fn(array $actor)=>strtolower($actor['form_id'].'|'.$actor['origin_plugin'].'|'.$actor['playthrough_id']);
    $responder ??= $context['target'];
    if ($key($resolved['speaker'])!==$key($responder) || $key($resolved['speaker'])===$key($context['player'])
        || $key($resolved['listener'])===$key($context['player'])
        || $key($resolved['listener'])===$key($resolved['speaker'])) return null;
    $resolved['speaker']=$responder;
    return $resolved;
}

// Read only the two directed scores at the caller's exact saved frontier; request globals and owner stay untouched.
function synth_relationship_interaction_context($db, int $responseId, int $entryIndex): ?array
{
    if ($responseId<1 || $entryIndex<0 || $entryIndex>255) throw new InvalidArgumentException('Invalid relationship interaction key');
    if (empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])) return null;
    return $db->withNativeRequestLock(static function($connection,array $event) use($db,$responseId,$entryIndex) {
        $result=@pg_query_params($connection,synth_relationship_visible_cte($db,$event,true)
            .'SELECT response_payload FROM relationship_response_evidence WHERE history_event_id=$1',[$responseId]);
        if (!$result) throw new RuntimeException('Unable to resolve relationship interaction evidence');
        $row=pg_fetch_assoc($result);if (!$row) return null;
        $response=json_decode($row['response_payload'],true,flags:JSON_THROW_ON_ERROR);
        $snapshot=synth_protocol_current_context($db,$event);
        if (($response['schema'] ?? null)!=='synth.relationship.response.v3' || !$snapshot
            || !is_array($response['entries'][$entryIndex] ?? null)) return null;
        $responder=synth_relationship_responder($db,$event,$snapshot);
        if (!$responder) return null;
        $participants=synth_relationship_interaction_participants($snapshot['payload'],$response['entries'][$entryIndex],$responder['identity']);
        if (!$participants) return null;
        $ids=[];
        foreach ($participants as $role=>$actor) {
            $result=@pg_query_params($connection,"SELECT id FROM synth.actor_identities WHERE game='fo4'
                AND upper(full_form_id)=upper($1) AND lower(origin_plugin)=lower($2) AND playthrough_id=$3",
                [$actor['form_id'],$actor['origin_plugin'],$actor['playthrough_id']]);
            if (!$result) throw new RuntimeException('Unable to resolve relationship participant');
            $identity=pg_fetch_assoc($result);if (!$identity) return null;
            $ids[$role]=intval($identity['id']);
        }
        $states=[];
        foreach (['speaker'=>'listener','listener'=>'speaker'] as $role=>$subject) {
            $result=@pg_query_params($connection,synth_relationship_visible_cte($db,$event,false,$ids[$role])
                .'SELECT EXISTS(SELECT 1 FROM native_ancestry WHERE depth=1) AS available,r.*
                    FROM (SELECT 1) one LEFT JOIN LATERAL (SELECT history_event_id,affinity,relationship_type,details
                        FROM native_relationships WHERE subject_identity_id=$1 ORDER BY history_event_id DESC LIMIT 1) r ON TRUE',[$ids[$subject]]);
            if (!$result) throw new RuntimeException('Unable to read relationship participant state');
            $row=pg_fetch_assoc($result);if (!$row || $row['available']!=='t') return null;
            $states[$role]=$row['history_event_id']===null ? null : ['revision'=>intval($row['history_event_id']),
                'aff'=>$row['affinity']===null ? null : intval($row['affinity']),'type'=>$row['relationship_type']]+json_decode($row['details'],true,flags:JSON_THROW_ON_ERROR);
        }
        return ['response_id'=>$responseId,'entry_index'=>$entryIndex,'participants'=>$participants,'actor_ids'=>$ids,'relationships'=>$states];
    },2000);
}
