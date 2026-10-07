<?php

require_once __DIR__.'/synth_protocol_state.php';

function synth_npc_native_request(): bool
{
    return !empty($GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'])
        && ($GLOBALS['SYNTH_REQUEST_EVENT']['json']['protocol_version'] ?? null)===2;
}

// Name-only legacy facades may resolve only the current canonical target or a unique captured participant.
function synth_npc_request_actor_id($db, string $name): ?int
{
    if (!synth_npc_native_request()) return null;
    synth_protocol_assert_request_active();
    if (isset($GLOBALS['SYNTH_NATIVE_PROFILE_BINDINGS'])) {
        $bindings=$GLOBALS['SYNTH_NATIVE_PROFILE_BINDINGS'];
        $binding=$name==='' && count($bindings)===1 ? reset($bindings) : ($bindings[$name] ?? null);
        if (is_array($binding) && intval($binding['actor_identity_id'] ?? 0)>0) {
            return intval($binding['actor_identity_id']);
        }
        return null;
    }
    $context=synth_protocol_current_context($db,$GLOBALS['SYNTH_REQUEST_EVENT']['json']);
    if (!$context) return null;
    $matches=[];
    $target=synth_protocol_response_actor_identity($db,$GLOBALS['SYNTH_REQUEST_EVENT']['json'],$context);
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
    $event=$GLOBALS['SYNTH_REQUEST_EVENT']['json'];
    $row=$db->fetchOne('SELECT a.id FROM synth.actor_identities a
        JOIN synth_meta.runtime_sessions r ON r.playthrough_id=a.playthrough_id
        WHERE a.game=\'fo4\' AND r.session_id='.$db->escapeLiteral($event['session_id']).'
        AND r.runtime_variant='.$db->escapeLiteral($event['runtime_variant']).'
        AND upper(a.full_form_id)=upper('.$db->escapeLiteral($identity['form_id']).')
        AND lower(a.origin_plugin)=lower('.$db->escapeLiteral($identity['origin_plugin']).')
        AND a.playthrough_id='.$db->escapeLiteral($identity['playthrough_id']));
    return $row ? intval($row['id']) : null;
}
