<?php

require_once __DIR__.'/synth_protocol_state.php';

// Admission freezes canonical links from this acknowledged snapshot, without registering unseen actors.
function synth_profile_refresh_bindings($db, array $event, bool $stableKeys=false): array
{
    $context=synth_protocol_current_context($db,$event);
    if (!$context) throw new RuntimeException('profile_refresh_context_unavailable');
    $visible=[];
    foreach (array_merge([$context['target']],$context['payload']['audience'] ?? []) as $identity) {
        if (!is_array($identity)) continue;
        $key=strtoupper($identity['form_id']).'|'.strtolower($identity['origin_plugin']).'|'.$identity['playthrough_id'];
        $visible[$key]=$identity;
    }
    $bindings=[];
    foreach ($event['payload']['actors'] ?? [] as $identity) {
        $key=strtoupper($identity['form_id']).'|'.strtolower($identity['origin_plugin']).'|'.$identity['playthrough_id'];
        if (!isset($visible[$key]) || $visible[$key]['display_name']!==$identity['display_name']) {
            throw new RuntimeException('profile_refresh_actor_not_in_snapshot');
        }
        $rows=$db->fetchAll('SELECT n.id,n.actor_identity_id,n.npc_name FROM synth.core_npc_master n
            JOIN synth.actor_identities a ON a.id=n.actor_identity_id
            WHERE a.game=\'fo4\' AND upper(a.full_form_id)=upper('.$db->escapeLiteral($identity['form_id']).')
                AND lower(a.origin_plugin)=lower('.$db->escapeLiteral($identity['origin_plugin']).')
                AND a.playthrough_id='.$db->escapeLiteral($identity['playthrough_id']).' ORDER BY n.id LIMIT 2');
        if (count($rows)>1) throw new RuntimeException('profile_refresh_ambiguous_link');
        if (!$rows) continue;
        $row=$rows[0];$name=strval($visible[$key]['display_name']);
        $bindingKey=$stableKeys ? 'actor:'.intval($row['actor_identity_id']) : $name;
        if ($name==='' || $name==='The Narrator' || isset($bindings[$bindingKey])) throw new RuntimeException('profile_refresh_ambiguous_name');
        $bindings[$bindingKey]=['npc_id'=>intval($row['id']),'actor_identity_id'=>intval($row['actor_identity_id']),'identity'=>$visible[$key]];
    }
    if (!empty($event['payload']['include_narrator'])) $bindings['The Narrator']=['narrator'=>true];
    return $bindings;
}

// Re-resolve the immutable queue's original protocol request before any provider side effect.
function synth_profile_with_origin($db, array $queue, callable $work): void
{
    $owner=$queue['native_owner'] ?? null;
    if (!is_array($owner) || ($owner['protocol_version'] ?? null)!==2 || ($owner['type'] ?? '')!=='profile_refresh') {
        throw new RuntimeException('Native profile queue has no valid owner.');
    }
    $session=$db->escapeLiteral($owner['session_id']);$request=$db->escapeLiteral($owner['request_id']);
    $origin=$db->fetchOne("SELECT p.canonical_event,p.event_sha256 FROM synth_meta.protocol_events p
        JOIN synth_meta.request_executions e ON e.session_id=p.session_id AND e.request_id=p.request_id
            AND e.generation=p.generation AND e.turn_id=p.turn_id AND e.event_type=p.event_type
            AND e.request_sha256=p.event_sha256 AND e.state='complete'
        JOIN synth_meta.runtime_sessions r ON r.session_id=e.session_id AND r.active_generation=e.generation
            AND r.state='active' AND p.canonical_event->>'runtime_variant'=r.runtime_variant
        WHERE p.session_id=$session AND p.request_id=$request AND p.event_type='profile_refresh'");
    $canonical=synth_protocol_canonical_json($owner);
    if (!$origin || !hash_equals($origin['event_sha256'],hash('sha256',$canonical))
        || synth_protocol_canonical_json(synth_protocol_decode_json($origin['canonical_event']))!==$canonical) {
        throw new RuntimeException('Native profile queue origin mismatch.');
    }
    $keys=['db','SYNTH_REQUEST_EVENT','SYNTH_PROTOCOL_REQUEST_CLAIMED','SYNTH_NATIVE_PROFILE_BINDINGS','SYNTH_NATIVE_PROFILE_INPUTS','SYNTH_NAME','PLAYER_NAME',
        'SYNTH_MEMORY_JOB_LEASE','SYNTH_RELATIONSHIP_JOB_LEASE','SYNTH_INTERACTION_JOB_LEASE','SYNTH_PROFILE_JOB_LEASE',
        'gameRequest','SYNTH_CORE_CURRENT_CONNECTOR_DATA','CURRENT_CONNECTOR','CONNECTOR','NARRATOR_ROLEPLAY_NAME',
        'FORCE_MAX_TOKENS','DEBUG_DATA','COMMAND_PROMPT','BASE_FUNCTIONS','SYNTH_CLIENT_CAPABILITIES',
        'PATCH_PROMPT_ENFORCE_ACTIONS','COMMAND_PROMPT_ENFORCE_ACTIONS','DIRECT_NARRATOR_DIALOGUE'];
    $previous=array_intersect_key($GLOBALS,array_flip($keys));
    try {
        $GLOBALS['db']=$db;
        $GLOBALS['SYNTH_NATIVE_PROFILE_INPUTS']=[];
        unset($GLOBALS['SYNTH_MEMORY_JOB_LEASE'],$GLOBALS['SYNTH_RELATIONSHIP_JOB_LEASE'],$GLOBALS['SYNTH_INTERACTION_JOB_LEASE'],$GLOBALS['SYNTH_PROFILE_JOB_LEASE']);
        $GLOBALS['SYNTH_REQUEST_EVENT']=['json'=>$owner];$GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED']=true;
        synth_protocol_assert_request_active();
        $available=synth_profile_refresh_bindings($db,$owner,true);
        $bindings=[];
        foreach ($queue['npcs'] as $name) {
            $expected=$queue['native_bindings'][$name] ?? null;
            $key=($expected['narrator'] ?? false) ? 'The Narrator' : 'actor:'.intval($expected['actor_identity_id'] ?? 0);
            if (!isset($available[$key]) || !is_array($expected)
                || synth_protocol_canonical_json($available[$key])!==synth_protocol_canonical_json($expected)) {
                throw new RuntimeException('Native profile link changed after queue admission.');
            }
            $bindings[$name]=$available[$key];
        }
        $GLOBALS['SYNTH_NATIVE_PROFILE_BINDINGS']=array_intersect_key($bindings,array_flip($queue['npcs']));
        $context=synth_protocol_current_context($db,$owner);
        $GLOBALS['PLAYER_NAME']=$context['player']['display_name'];
        $work();
    } finally {
        foreach ($keys as $key) {
            if (array_key_exists($key,$previous)) $GLOBALS[$key]=$previous[$key];else unset($GLOBALS[$key]);
        }
    }
}

// Native writers recheck generation and the original row link; a reused display name never selects a replacement.
function synth_profile_assert_write_target($db, string $name): void
{
    if (!isset($GLOBALS['SYNTH_NATIVE_PROFILE_BINDINGS'])) return;
    synth_protocol_assert_request_active();
    $expected=$GLOBALS['SYNTH_NATIVE_PROFILE_BINDINGS'][$name] ?? null;
    if (!$expected) throw new RuntimeException('Native profile target is outside the queued batch.');
    if ($name==='The Narrator' && ($expected['narrator'] ?? false)===true) return;
    $row=$db->fetchOne('SELECT id,actor_identity_id,npc_name FROM synth.core_npc_master WHERE id='.intval($expected['npc_id'])
        .($db->isTransactionIdle() ? '' : ' FOR UPDATE'));
    if (!$row || intval($row['actor_identity_id'])!==$expected['actor_identity_id']) {
        throw new RuntimeException('Native profile target link changed.');
    }
}
