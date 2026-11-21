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
        $visibleCte=synth_relationship_visible_cte($db,$event);
        try {
            $rows=\Synth\Core\DbManager\Dao\ActorIdentityDao::current($db)->relationshipPromptRows($visibleCte);
        } catch (Throwable $error) {
            throw new RuntimeException('Unable to read saved relationship prompt',0,$error);
        }
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
        $hadPlayer=array_key_exists('PLAYER_NAME',$GLOBALS);$previous=\Synth\Bootstrap::config('PLAYER_NAME') ?? null;
        \Synth\Bootstrap::set('PLAYER_NAME', $player['display_name']);
        try {
            return RelationshipManager::buildContextFromRelationships($responder['display_name'],
                $relationships,$nearby,synthRelationshipUsesDedicatedConnector(),true);
        } finally {
            if ($hadPlayer) \Synth\Bootstrap::set('PLAYER_NAME', $previous);else \Synth\Bootstrap::forget('PLAYER_NAME');
        }
    },2000);
}
