<?php

require_once __DIR__.'/player_event.php';
require_once __DIR__.'/observation_reaction.php';

// Resolve an ID to original acknowledged evidence, never client-provided prose or an inferred victory.
function synthBindPlayerReaction($db, array $event, array $context): ?array
{
    $target=$context['target'];$player=$context['player'];
    $eventId=$event['payload']['event_id'] ?? null;
    if (($event['protocol_version'] ?? 1)!==2 || ($event['runtime_variant'] ?? '')!=='flat'
        || !in_array('dialogue.player_reactions',$event['capabilities'] ?? [],true)
        || !in_array('context.player_events',$event['capabilities'] ?? [],true)
        || !is_string($eventId) || !preg_match('/^player:[1-9][0-9]{0,15}$/D',$eventId)
        || intval(substr($eventId,7))>9007199254740991
        || $target['playthrough_id']!==$player['playthrough_id']
        || ($event['payload']['actor']['playthrough_id'] ?? null)!==$target['playthrough_id'])
        throw new DomainException('player_reaction_owner_invalid');
    if (!synthObservationReactionAudienceAvailable($context)) return null;
    $result=$db->execQuery("SELECT (h.event_payload->>'party')::jsonb AS party,
            EXTRACT(EPOCH FROM (clock_timestamp()-h.recorded_at))*1000 AS elapsed_ms
        FROM synth_meta.context_snapshots c
        JOIN synth_meta.runtime_sessions r ON r.session_id=c.session_id AND r.active_generation=c.generation
            AND r.playthrough_id=c.playthrough_id AND r.state='active'
        JOIN synth_meta.active_agents a ON a.session_id=c.session_id AND a.generation=c.generation
            AND a.actor_identity_id=c.target_identity_id AND a.state='active'
        JOIN synth_meta.history_events h ON h.session_id=c.session_id AND h.generation=c.generation
            AND h.playthrough_id=c.playthrough_id AND h.id<=c.history_event_id
            AND h.context_sequence<=c.snapshot_sequence AND h.event_payload->>'type'='player_event'
            AND (h.event_payload->>'party')::jsonb->>'event_id'=$4
        JOIN synth_meta.context_snapshots original ON original.session_id=h.session_id AND original.generation=h.generation
            AND original.snapshot_sequence=h.context_sequence AND original.player_identity_id=c.player_identity_id
        WHERE c.session_id=$1 AND c.generation=$2 AND c.snapshot_sequence=$3
          AND c.received_at BETWEEN clock_timestamp()-interval '10 seconds' AND clock_timestamp()",
        [$event['session_id'],$event['generation'],$event['context_sequence'],$eventId]);
    if ($result===false) throw new RuntimeException('player_reaction_history_read_failed');
    $row=pg_fetch_assoc($result);
    if (!$row) return null;
    $party=json_decode($row['party'],true,flags:JSON_THROW_ON_ERROR);
    $identity=array_intersect_key($player,array_flip(['form_id','origin_plugin','playthrough_id']));
    if (($party['schema'] ?? '')!=='synth.player.event.v1' || ($party['player'] ?? null)!=$identity
        || !is_int($party['capture_age_ms'] ?? null) || $party['capture_age_ms']<0
        || (float)$row['elapsed_ms']<0 || $party['capture_age_ms']+(float)$row['elapsed_ms']>60000) return null;
    $transition=synthValidatePlayerEvent(($party['input_event'] ?? [])+['age_ms'=>$party['capture_age_ms']]);
    if ($transition['event_id']!==$eventId
        || intval($context['payload']['world']['game_time_ticks'] ?? 0)<$transition['after']['game_time_ticks']) return null;
    $playerAvailable=false;
    foreach ($context['payload']['actor_states'] ?? [] as $state) {
        if (array_intersect_key($state['actor'] ?? [],$identity)!=$identity) continue;
        $playerAvailable=($state['alive'] ?? false)===true && ($state['disabled'] ?? true)===false
            && ($state['in_combat'] ?? true)===false && intval($state['level'] ?? 0)>=$transition['after']['level'];
    }
    if (!$playerAvailable) return null;
    // Admission already serializes the session and records protocol events. A fresh context cannot replay an old ID.
    $first=$db->execQuery("SELECT request_id FROM synth_meta.protocol_events
        WHERE session_id=$1 AND generation=$2 AND event_type='trigger'
          AND canonical_event->'payload'->>'kind'='player_reaction'
          AND canonical_event->'payload'->>'event_id'=$3
          AND canonical_event->'payload'->'actor'->>'playthrough_id'=$4 ORDER BY id LIMIT 1",
        [$event['session_id'],$event['generation'],$eventId,$player['playthrough_id']]);
    if ($first===false) throw new RuntimeException('player_reaction_claim_read_failed');
    if ((pg_fetch_assoc($first)['request_id'] ?? null)!==$event['request_id']) return null;
    $name=str_replace('|',' ',trim($player['display_name']));
    return ['event_id'=>$eventId,'kind'=>$transition['kind'],'input_event'=>$transition,
        'text'=>$transition['kind']==='level_up'
            ? $name.' reached level '.$transition['after']['level'].' (previous observed level '.$transition['before']['level'].').'
            : 'Combat ended near '.$name.'. The cause and outcome are unknown.'];
}

// Override legacy/custom victory cues only for a successfully bound native player reaction; retain RPG policy extras.
function synthApplyPlayerReactionPrompt(array &$prompts, array $reaction, string $template): void
{
    $type=$reaction['kind']==='level_up'?'rpg_lvlup':'combatend';
    $constraint=$reaction['kind']==='level_up'
        ? 'Do not invent a perk, skill, reward, or the cause of this change.'
        : 'Do not assume victory, defeated enemies, kills, weapons, or why combat stopped.';
    $prompts[$type]['cue']=['Make one brief in-character comment on this recorded player observation: '.$reaction['text']
        .' '.$constraint.' Being present now does not establish witnessing the earlier event. Do not claim you witnessed it.'
        .' Do not ask a follow-up question, continue the conversation, or request an action. Output spoken dialogue only. '.$template];
    $prompts[$type]['player_request']=[$reaction['text']];
}
