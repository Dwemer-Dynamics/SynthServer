<?php

require_once dirname(__DIR__, 3).'/lib'.'/synth_protocol_state.php';

function synth_npc_native_request(): bool
{
    return !empty(\Synth\Bootstrap::claimedRequest())
        && (\Synth\Bootstrap::requestEvent()['json']['protocol_version'] ?? null)===2;
}

// Name-only legacy facades may resolve only the current canonical target or a unique captured participant.
function synth_npc_request_actor_id($db, string $name, ?array $boundContext=null): ?int
{
    if (!synth_npc_native_request()) return null;
    synth_protocol_assert_request_active();
    if (\Synth\Bootstrap::has('SYNTH_NATIVE_PROFILE_BINDINGS')) {
        $bindings=\Synth\Bootstrap::config('SYNTH_NATIVE_PROFILE_BINDINGS');
        $binding=$name==='' && count($bindings)===1 ? reset($bindings) : ($bindings[$name] ?? null);
        if (is_array($binding) && intval($binding['actor_identity_id'] ?? 0)>0) {
            return intval($binding['actor_identity_id']);
        }
        return null;
    }
    $context=$boundContext ?? synth_protocol_current_context($db,\Synth\Bootstrap::requestEvent()['json']);
    if (!$context) return null;
    $matches=[];
    $target=synth_protocol_response_actor_identity($db,\Synth\Bootstrap::requestEvent()['json'],$context);
    if ($target===null) return null;
    if (is_array($target) && ($name==='' || strcasecmp(trim($target['display_name']),trim($name))===0)) {
        $matches=[$target];
    } else {
        foreach (array_merge([$context['target'] ?? null],$context['payload']['audience'] ?? []) as $identity) {
            if (!is_array($identity)) continue;
            if (strcasecmp(trim($identity['display_name']),trim($name))!==0) continue;
            $key=strtoupper($identity['form_id']).'|'.strtolower($identity['origin_plugin']).'|'.$identity['playthrough_id'];
            $matches[$key]=$identity;
        }
    }
    if (count($matches)!==1) return null;
    $identity=reset($matches);
    $event=\Synth\Bootstrap::requestEvent()['json'];
    return \Synth\Core\DbManager\Dao\ActorIdentityDao::current($db)->findIdInSession(strval($event['session_id']),strval($event['runtime_variant']),
        strval($identity['form_id']),strval($identity['origin_plugin']),strval($identity['playthrough_id']));
}
