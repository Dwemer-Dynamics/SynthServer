<?php

// A fresh participant may react now; this does not claim they witnessed the earlier event.
function synthObservationReactionAudienceAvailable(array $context): bool
{
    $target=$context['target'];$payload=$context['payload'];
    $same=static fn($identity)=>is_array($identity)
        && strcasecmp($identity['form_id'] ?? '',$target['form_id'])===0
        && strcasecmp($identity['origin_plugin'] ?? '',$target['origin_plugin'])===0
        && ($identity['playthrough_id'] ?? '')===$target['playthrough_id'];
    $heard=false;$available=false;
    foreach ($payload['audience'] ?? [] as $actor) if ($same($actor)) $heard=true;
    foreach ($payload['actor_states'] ?? [] as $state) {
        if (!$same($state['actor'] ?? null)) continue;
        $available=($state['alive'] ?? false)===true && ($state['disabled'] ?? true)===false
            && ($state['in_combat'] ?? true)===false && ($state['hostile_to_player'] ?? true)===false
            && ($state['talking_to_player'] ?? true)===false && ($state['life_state'] ?? '')==='alive'
            && ($state['line_of_sight'] ?? '')==='visible'
            && in_array($state['posture'] ?? '',['normal','sitting'],true);
    }
    return $heard && $available;
}
