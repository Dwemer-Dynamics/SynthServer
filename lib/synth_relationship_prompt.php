<?php

require_once __DIR__.'/synth_relationship.php';

// One relationship graph query supplies the prompt; no profile lookup, global history or provider work is allowed here.
function synth_relationship_prompt_context($db, string $npcName): string
{
    if (!synthRelationshipSettingEnabled()) return '';
    return $db->withNativeRequestLock(static function($connection,array $event) use($db,$npcName) {
        $context=synth_protocol_current_context($db,$event);
        $responder=$context ? synth_protocol_response_actor_identity($db,$event,$context) : null;
        if (!$responder || strcasecmp(trim($npcName),$responder['display_name'] ?? '')!==0) {
            return '[RELATIONSHIPS] Saved actor context unavailable; do not infer relationship state.';
        }
        $result=@pg_query($connection,synth_relationship_visible_cte($db,$event)."
            SELECT actor.id,actor.full_form_id,actor.origin_plugin,actor.playthrough_id,
                state.history_event_id,state.affinity,state.relationship_type,state.details
            FROM native_ancestry current
            JOIN synth_meta.context_snapshots c ON c.session_id=current.session_id AND c.generation=current.generation
                AND c.snapshot_sequence=current.snapshot_sequence
            JOIN synth.actor_identities actor ON actor.game='fo4' AND actor.playthrough_id=c.playthrough_id
                AND (actor.id=c.player_identity_id OR actor.id=c.target_identity_id
                    OR c.audience_identity_ids @> jsonb_build_array(actor.id))
            LEFT JOIN LATERAL (SELECT r.* FROM native_relationships r WHERE r.subject_identity_id=actor.id
                ORDER BY r.history_event_id DESC LIMIT 1) state ON TRUE
            WHERE current.depth=1 AND actor.id<>current.reader_identity_id ORDER BY actor.id");
        if (!$result) throw new RuntimeException('Unable to read saved relationship prompt');
        $rows=pg_fetch_all($result);
        if (!$rows) return '[RELATIONSHIPS] Saved actor context unavailable; do not infer relationship state.';
        $payload=$context['payload'];$actors=array_merge([$payload['player'],$payload['target']],$payload['audience'] ?? []);
        $names=[];$identities=[];
        foreach ($actors as $actor) {
            $key=strtolower($actor['form_id'].'|'.$actor['origin_plugin']).'|'.$actor['playthrough_id'];
            $names[strtolower(trim($actor['display_name']))][$key]=true;
            $identities[$key]=$actor;
        }
        $player=$payload['player'];$playerKey=strtolower($player['form_id'].'|'.$player['origin_plugin']).'|'.$player['playthrough_id'];
        $relationships=[];$nearby=[];
        foreach ($rows as $row) {
            $key=strtolower($row['full_form_id'].'|'.$row['origin_plugin']).'|'.$row['playthrough_id'];
            $actor=$identities[$key] ?? null;if (!$actor) continue;
            $label=$key===$playerKey ? 'Player' : $actor['display_name'];
            // Ambiguous display labels and player aliases cannot grant another actor's state or command authority.
            if ($key!==$playerKey && (count($names[strtolower(trim($label))] ?? [])!==1
                || in_array(strtolower(trim($label)),['player','the player','player character','the player character',
                    'sole survivor','the sole survivor','#player_name#','{player_name}',strtolower(trim($player['display_name']))],true))) continue;
            if ($row['history_event_id']===null || $row['affinity']===null) continue;
            $relationships[$label]=['aff'=>intval($row['affinity']),'type'=>$row['relationship_type']]
                +json_decode($row['details'],true,flags:JSON_THROW_ON_ERROR);
            if ($key!==$playerKey) $nearby[]=$label;
        }
        // Only the display name is rebound for the shared formatter; request/actor ownership never changes.
        $hadPlayer=array_key_exists('PLAYER_NAME',$GLOBALS);$previous=$GLOBALS['PLAYER_NAME'] ?? null;
        $GLOBALS['PLAYER_NAME']=$player['display_name'];
        try {
            return RelationshipManager::buildContextFromRelationships($responder['display_name'],
                $relationships,$nearby,synthRelationshipUsesDedicatedConnector(),true);
        } finally {
            if ($hadPlayer) $GLOBALS['PLAYER_NAME']=$previous;else unset($GLOBALS['PLAYER_NAME']);
        }
    },2000);
}
