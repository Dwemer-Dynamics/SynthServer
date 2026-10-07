<?php

// Compare observed tracked objectives, never raw stages or enumeration order.
function synthQuestReactionChanges(array $previous, array $current): array
{
    foreach ([$previous,$current] as $journal) {
        if (!in_array($journal['active_quests_observation'] ?? '',['complete','partial'],true)) return [];
    }
    $before=[];
    $complete=($previous['active_quests_observation']==='complete');
    foreach (array_slice($previous['active_quests'] ?? [],0,16) as $quest) {
        $before[strtolower($quest['origin_plugin'].'|'.$quest['form_id'])]=$quest;
        if (!is_bool($quest['tracked'] ?? null)) $complete=false;
    }
    $changed=[];
    foreach (array_slice($current['active_quests'] ?? [],0,16) as $quest) {
        if (($quest['tracked'] ?? null)!==true || ($quest['active_objectives'] ?? 0)<1
            || !in_array($quest['objectives_observation'] ?? '',['complete','partial'],true)) continue;
        $old=$before[strtolower($quest['origin_plugin'].'|'.$quest['form_id'])] ?? null;
        $selected=($old && ($old['tracked'] ?? null)===false) || (!$old && $complete);
        $objectivesChanged=false;
        if ($old && ($old['tracked'] ?? null)===true && ($old['objectives_observation'] ?? '')==='complete'
            && $quest['objectives_observation']==='complete') {
            $a=$old['objectives'];$b=$quest['objectives'];sort($a,SORT_STRING);sort($b,SORT_STRING);
            $objectivesChanged=$a!==$b || $old['active_objectives']!==$quest['active_objectives'];
        }
        if ($selected || $objectivesChanged) $changed[]=$quest;
    }
    usort($changed,static fn($a,$b)=>strtolower($a['origin_plugin'].'|'.$a['form_id'])<=>strtolower($b['origin_plugin'].'|'.$b['form_id']));
    return $changed;
}

// Ground a reaction in two immutable captures and an active, visible participant; no model call or activation here.
function synthBindQuestReaction($db, array $event, array $context): ?array
{
    $previousSequence=$event['payload']['previous_context_sequence'] ?? 0;
    $target=$context['target'];$player=$context['player'];$payload=$context['payload'];
    if (($event['protocol_version'] ?? 1)!==2 || ($event['runtime_variant'] ?? '')!=='flat'
        || !in_array('dialogue.quest_reactions',$event['capabilities'] ?? [],true)
        || !in_array('context.quest_tracking',$event['capabilities'] ?? [],true)
        || !is_int($previousSequence) || $previousSequence<1 || $previousSequence>=$event['context_sequence']
        || $target['playthrough_id']!==$player['playthrough_id']
        || $event['payload']['actor']['playthrough_id']!==$target['playthrough_id']) {
        throw new DomainException('quest_reaction_owner_invalid');
    }
    require_once __DIR__.'/observation_reaction.php';
    if (!synthObservationReactionAudienceAvailable($context)) return null;
    $result=$db->execQuery("SELECT p.context_payload
        FROM synth_meta.context_snapshots c
        JOIN synth_meta.runtime_sessions r ON r.session_id=c.session_id AND r.active_generation=c.generation
            AND r.playthrough_id=c.playthrough_id AND r.state='active'
        JOIN synth_meta.active_agents a ON a.session_id=c.session_id AND a.generation=c.generation
            AND a.actor_identity_id=c.target_identity_id AND a.state='active'
        JOIN synth_meta.context_snapshots p ON p.session_id=c.session_id AND p.generation=c.generation
            AND p.playthrough_id=c.playthrough_id AND p.player_identity_id=c.player_identity_id AND p.snapshot_sequence=$4
        WHERE c.session_id=$1 AND c.generation=$2 AND c.snapshot_sequence=$3
          AND p.snapshot_sequence=(SELECT max(q.snapshot_sequence) FROM synth_meta.context_snapshots q
            WHERE q.session_id=c.session_id AND q.generation=c.generation AND q.playthrough_id=c.playthrough_id
              AND q.player_identity_id=c.player_identity_id AND q.snapshot_sequence<c.snapshot_sequence
              AND q.context_payload->>'active_quests_observation' IN ('complete','partial'))",
        [$event['session_id'],$event['generation'],$event['context_sequence'],$previousSequence]);
    if ($result===false) throw new RuntimeException('quest_reaction_context_read_failed');
    $row=pg_fetch_assoc($result);
    if (!$row) return null;
    $previous=json_decode($row['context_payload'],true,flags:JSON_THROW_ON_ERROR);
    $changes=synthQuestReactionChanges($previous,$payload);
    if (!$changes) return null;
    $first=$db->execQuery("SELECT request_id FROM synth_meta.protocol_events
        WHERE session_id=$1 AND generation=$2 AND event_type='trigger'
          AND canonical_event->'payload'->>'kind'='quest_updated'
          AND canonical_event->>'context_sequence'=$3 ORDER BY id LIMIT 1",
        [$event['session_id'],$event['generation'],strval($event['context_sequence'])]);
    if ($first===false) throw new RuntimeException('quest_reaction_claim_read_failed');
    if ((pg_fetch_assoc($first)['request_id'] ?? null)!==$event['request_id']) return null;
    $descriptions=[];
    foreach (array_slice($changes,0,3) as $quest) {
        $descriptions[]=$quest['display_name'].': '.implode('; ',$quest['objectives']);
    }
    return ['previous_context_sequence'=>$previousSequence,'quests'=>$changes,
        'text'=>mb_strcut('Tracked quest or objective changed: '.implode(' | ',$descriptions),0,4000,'UTF-8')];
}
