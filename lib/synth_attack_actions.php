<?php
require_once __DIR__.'/synth_combat_target.php';

// Only a player-owned input turn can ask its addressed follower to attack.
function synth_attack_supported(array $event): bool
{
    return ($event['protocol_version'] ?? null)===2 && ($event['runtime_variant'] ?? '')==='flat'
        && in_array($event['type'] ?? '',['input_text','input_audio'],true)
        && in_array($event['payload']['control']['mode'] ?? 'STANDARD',['STANDARD','WHISPER','CLOSE','SHOUT'],true)
        && count(array_diff(['action.attack','context.enemy_awareness','dialogue.turn_ownership'],$event['capabilities'] ?? []))===0;
}

function synth_attack_targets(array $context,array $performer): array
{
    $states=$context['payload']['actor_states'] ?? [];
    $owners=array_values(array_filter($states,static fn($state)=>is_array($state) && is_array($state['actor'] ?? null)
        && synth_combat_identity_key($state['actor'])===synth_combat_identity_key($performer)));
    if (count($owners)!==1 || ($owners[0]['teammate'] ?? false)!==true || ($owners[0]['hostile_to_player'] ?? false)!==false) return [];
    return array_column(synth_enemy_awareness_observations($states,$performer,$context['player'] ?? [])['current'],'actor');
}

function synth_attack_target_named(array $context,array $performer,string $name): ?array
{
    $matches=[];
    foreach (synth_attack_targets($context,$performer) as $target)
        if (strcasecmp(trim($target['display_name'] ?? ''),trim($name))===0) $matches[synth_combat_identity_key($target)]=$target;
    return count($matches)===1 ? reset($matches) : null;
}

function synth_attack_intent_valid(array $event,array $context,array $performer,array $action): bool
{
    if (!synth_attack_supported($event) || ($action['name'] ?? '')!=='attack' || ($action['capability'] ?? '')!=='action.attack'
        || (isset($action['server_code']) && $action['server_code']!=='Attack') || !is_array($action['actor'] ?? null)
        || !is_array($action['target'] ?? null) || !($action['arguments'] ?? null) instanceof stdClass
        || get_object_vars($action['arguments'])!==[] || synth_combat_identity_key($action['actor'])!==synth_combat_identity_key($performer)
        || strtoupper($performer['form_id'] ?? '')==='0X00000014') return false;
    $targets=synth_attack_targets($context,$performer);
    return count(array_filter($targets,static fn($target)=>synth_combat_identity_key($target)===synth_combat_identity_key($action['target'])))===1;
}

function synth_attack_validate($db,array $event,array $action): void
{
    $context=synth_protocol_current_context($db,$event);
    $performer=$context ? synth_protocol_response_actor_identity($db,$event,$context) : null;
    if (!$context || !$performer || !synth_attack_intent_valid($event,$context,$performer,$action))
        throw new DomainException('invalid_owned_attack_intent');
}
