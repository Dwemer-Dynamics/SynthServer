<?php

require_once __DIR__.'/player_event.php';
require_once __DIR__.'/observation_reaction.php';

// Resolve an ID to original acknowledged evidence, never client-provided prose or an inferred victory.
function synthBindPlayerReaction($db, array $event, array $context): ?array
{
    $eventId=synthPlayerReactionOwner($event,$context,['dialogue.player_reactions','context.player_events']);
    if (!synthObservationReactionAudienceAvailable($context)) return null;
    $transition=synthPlayerReactionEvidence($db,$event,$context,$eventId);
    if ($transition===null || !synthPlayerReactionFirstClaim($db,$event,$eventId,$context['player']['playthrough_id'])) return null;
    $name=str_replace('|',' ',trim($context['player']['display_name']));
    return ['event_id'=>$eventId,'kind'=>$transition['kind'],'input_event'=>$transition,
        'text'=>$transition['kind']==='level_up'
            ? $name.' reached level '.$transition['after']['level'].' (previous observed level '.$transition['before']['level'].').'
            : 'Combat ended near '.$name.'. The cause and outcome are unknown.'];
}

// Select only among the end's stored participant evidence; current teammate status alone never proves participation.
function synthBindCompanionCombatComment($db, array $event, array $context): ?array
{
    $eventId=synthPlayerReactionOwner($event,$context,
        ['dialogue.player_reactions','context.player_events','dialogue.companion_combat_comments']);
    $transition=synthPlayerReactionEvidence($db,$event,$context,$eventId,true);
    if ($transition===null || $transition['kind']!=='combat_end'
        || !synthCompanionCommentSpeakerAvailable($context,$transition['participants'] ?? [])
        || !synthPlayerReactionFirstClaim($db,$event,$eventId,$context['player']['playthrough_id'])) return null;
    $name=str_replace('|',' ',trim($context['player']['display_name']));
    $speaker=str_replace('|',' ',trim($context['target']['display_name']));
    return ['event_id'=>$eventId,'kind'=>'combat_end','input_event'=>$transition,'companion'=>true,
        'text'=>'Combat ended near '.$name.'. '.$speaker.' was observed in that combat as their teammate.'
            .' The cause and outcome are unknown.'];
}

function synthPlayerReactionOwner(array $event, array $context, array $capabilities): string
{
    $target=$context['target'];$player=$context['player'];
    $eventId=$event['payload']['event_id'] ?? null;
    if (($event['protocol_version'] ?? 1)!==2 || ($event['runtime_variant'] ?? '')!=='flat'
        || count(array_diff($capabilities,$event['capabilities'] ?? []))!==0
        || !is_string($eventId) || !preg_match('/^player:[1-9][0-9]{0,15}$/D',$eventId)
        || intval(substr($eventId,7))>9007199254740991
        || $target['playthrough_id']!==$player['playthrough_id']
        || ($event['payload']['actor']['playthrough_id'] ?? null)!==$target['playthrough_id'])
        throw new DomainException('player_reaction_owner_invalid');
    return $eventId;
}

// A participant still following now: heard teammate, alive, calm and not SYNTH-held. Line of sight is not required.
function synthCompanionCommentSpeakerAvailable(array $context, array $participants): bool
{
    $target=$context['target'];
    $same=static fn($identity)=>is_array($identity)
        && strcasecmp($identity['form_id'] ?? '',$target['form_id'])===0
        && strcasecmp($identity['origin_plugin'] ?? '',$target['origin_plugin'])===0
        && ($identity['playthrough_id'] ?? '')===$target['playthrough_id'];
    if (!array_filter($participants,$same) || !array_filter($context['payload']['audience'] ?? [],$same)) return false;
    foreach ($context['payload']['actor_states'] ?? [] as $state) {
        if (!$same($state['actor'] ?? null)) continue;
        return ($state['teammate'] ?? false)===true && ($state['wait_held'] ?? false)!==true
            && ($state['alive'] ?? false)===true && ($state['disabled'] ?? true)===false
            && ($state['in_combat'] ?? true)===false && ($state['hostile_to_player'] ?? true)===false
            && ($state['talking_to_player'] ?? true)===false && ($state['life_state'] ?? '')==='alive'
            && in_array($state['posture'] ?? '',['normal','sitting'],true);
    }
    return false;
}

// Immutable acknowledged history for this exact owner, still recent, with the player now calm and alive.
function synthPlayerReactionEvidence($db, array $event, array $context, string $eventId, bool $requireQuiet = false): ?array
{
    $player=$context['player'];
    $row=\Synth\Core\DbManager\Dao\HistoryEventDao::current($db)->recentPlayerEventEvidence(strval($event['session_id']),intval($event['generation']),intval($event['context_sequence']),$eventId);
    if ($row===false) throw new RuntimeException('player_reaction_history_read_failed');
    if (!$row) return null;
    $party=json_decode($row['party'],true,flags:JSON_THROW_ON_ERROR);
    $identity=array_intersect_key($player,array_flip(['form_id','origin_plugin','playthrough_id']));
    if (($party['schema'] ?? '')!=='synth.player.event.v1' || ($party['player'] ?? null)!=$identity
        || !is_int($party['capture_age_ms'] ?? null) || $party['capture_age_ms']<0
        || (float)$row['elapsed_ms']<0 || $party['capture_age_ms']+(float)$row['elapsed_ms']>60000) return null;
    if ($requireQuiet && $party['capture_age_ms']+(float)$row['elapsed_ms']<3000) return null;
    $transition=synthValidatePlayerEvent(($party['input_event'] ?? [])+['age_ms'=>$party['capture_age_ms']]);
    if ($transition['event_id']!==$eventId
        || intval($context['payload']['world']['game_time_ticks'] ?? 0)<$transition['after']['game_time_ticks']) return null;
    $playerAvailable=false;
    foreach ($context['payload']['actor_states'] ?? [] as $state) {
        if (array_intersect_key($state['actor'] ?? [],$identity)!=$identity) continue;
        $playerAvailable=($state['alive'] ?? false)===true && ($state['disabled'] ?? true)===false
            && ($state['in_combat'] ?? true)===false && intval($state['level'] ?? 0)>=$transition['after']['level'];
    }
    return $playerAvailable ? $transition : null;
}

// Admission already serializes the session and records protocol events. A fresh context cannot replay an old ID,
// and one end yields at most one remark across the generic and companion kinds.
function synthPlayerReactionFirstClaim($db, array $event, string $eventId, string $playthrough): bool
{
    $first=\Synth\Core\DbManager\Dao\ProtocolEventDao::current($db)->firstReactionRequestId(strval($event['session_id']),intval($event['generation']),$eventId,$playthrough);
    if ($first===false) throw new RuntimeException('player_reaction_claim_read_failed');
    return $first===$event['request_id'];
}

// Override legacy/custom victory cues only for a successfully bound native player reaction; retain RPG policy extras.
function synthApplyPlayerReactionPrompt(array &$prompts, array $reaction, string $template): void
{
    $type=$reaction['kind']==='level_up'?'rpg_lvlup':'combatend';
    $constraint=$reaction['kind']==='level_up'
        ? 'Do not invent a perk, skill, reward, or the cause of this change.'
        : 'Do not assume victory, defeated enemies, kills, weapons, or why combat stopped.';
    if (!empty($reaction['companion'])) {
        $prompts[$type]['cue']=['As their companion, make one brief in-character remark now that this recorded combat is over: '
            .$reaction['text'].' '.$constraint.' Do not describe specific moments of the fight.'
            .' Do not ask a follow-up question, continue the conversation, or request an action. Output spoken dialogue only. '.$template];
        $prompts[$type]['player_request']=[$reaction['text']];
        return;
    }
    $prompts[$type]['cue']=['Make one brief in-character comment on this recorded player observation: '.$reaction['text']
        .' '.$constraint.' Being present now does not establish witnessing the earlier event. Do not claim you witnessed it.'
        .' Do not ask a follow-up question, continue the conversation, or request an action. Output spoken dialogue only. '.$template];
    $prompts[$type]['player_request']=[$reaction['text']];
}
