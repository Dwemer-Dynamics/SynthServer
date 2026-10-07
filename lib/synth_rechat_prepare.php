<?php

require_once __DIR__.'/synth_rechat_selection.php';

// Overlay only the chosen profile's rechat controls; never load its connector or mutate profile globals here.
function synth_rechat_profile_policy(array $serverPolicy, array $candidate): array
{
    $metadata=is_array($candidate['profile_metadata'] ?? null) ? $candidate['profile_metadata'] : [];
    foreach (['RECHAT_H'=>'rounds','RECHAT_P'=>'probability'] as $key=>$field) {
        if (!array_key_exists($key,$metadata) || $metadata[$key]===null || $metadata[$key]==='') continue;
        if (!is_scalar($metadata[$key]) || !is_numeric($metadata[$key])) throw new LogicException('rechat_profile_policy_invalid');
        $serverPolicy[$field]=intval($metadata[$key]);
    }
    if (isset($metadata['RECHAT_ALLOW_ACTIONS']) && $metadata['RECHAT_ALLOW_ACTIONS']!=='') {
        $value=filter_var($metadata['RECHAT_ALLOW_ACTIONS'],FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
        if ($value===null) throw new LogicException('rechat_profile_policy_invalid');
        $serverPolicy['allow_actions']=$value;
    }
    $serverPolicy['source_profile_id']=intval($candidate['profile_id']);
    $serverPolicy['source_actor_identity_id']=intval($candidate['actor_identity_id']);
    return $serverPolicy;
}

// Stored choices survive management changes; generation must refuse a deleted/rebound row, not choose someone else.
function synth_rechat_assert_live_selection($connection, array $event, array $selection): void
{
    if ($selection['role']==='terminal') return;
    if ($selection['role']==='npc') {
        $identity=$selection['identity'];
        $result=@pg_query_params($connection,"SELECT 1 FROM synth.core_npc_master n
            JOIN synth.core_profiles p ON p.id=n.profile_id
            JOIN synth.actor_identities a ON a.id=n.actor_identity_id AND a.game='fo4'
            JOIN synth_meta.runtime_sessions r ON r.session_id=$1 AND r.playthrough_id=a.playthrough_id
            WHERE n.id=$2 AND n.actor_identity_id=$3 AND p.id=$4
                AND upper(a.full_form_id)=upper($5) AND lower(a.origin_plugin)=lower($6) AND a.playthrough_id=$7
                AND r.runtime_variant=$8 FOR SHARE OF n,p",
            [$event['session_id'],$selection['npc_id'],$selection['actor_identity_id'],$selection['profile_id'],
             $identity['form_id'],$identity['origin_plugin'],$identity['playthrough_id'],$event['runtime_variant']]);
    } elseif ($selection['role']==='narrator') {
        $result=@pg_query_params($connection,"SELECT 1 FROM synth.core_narrator n JOIN synth.core_profiles p
            ON n.id='profile_id' AND n.value=p.id::text WHERE p.id=$1 FOR SHARE OF n,p",[$selection['profile_id']]);
    } else throw new LogicException('rechat_selection_role_invalid');
    if (!$result) throw new RuntimeException('rechat_selection_binding_query_failed');
    if (!pg_fetch_assoc($result)) throw new LogicException('rechat_selected_binding_changed');
}

// The first continuation inherits its completed origin's pre-profile policy, never the current request's globals.
function synth_rechat_prepare_continuation($db, array $event): array
{
    return $db->withNativeRequestLock(static function($connection,array $owner) use($db,$event) {
        if ($owner!==$event) throw new LogicException('rechat_prepare_owner_changed');
        // Validate before cached choices, policy reads or reservation can bypass the candidate loader.
        if (in_array('dialogue.rechat.scene',$event['capabilities'] ?? [],true)
            || array_key_exists('scene_context_sequence',$event['payload'] ?? [])) {
            synth_rechat_scene_context_locked($connection,$event);
        }
        if (!@pg_query($connection,'SAVEPOINT synth_rechat_prepare')) throw new RuntimeException('rechat_prepare_savepoint_failed');
        try {
            $proof=synth_rechat_completed_parent($db,$event);
            if ($proof===null) throw new LogicException('rechat_prepare_parent_unavailable');
            $origin=synth_rechat_action_chain_origin($connection,$event,$proof['request']);
            $chain=synth_rechat_find_chain($connection,$event,$origin['request_id']);
            $initialTerminal=null;
            if ($chain!==null) {
                $savedPolicy=json_decode($chain['policy'],true,flags:JSON_THROW_ON_ERROR);
                if ((!is_int($savedPolicy['source_profile_id'] ?? null) || $savedPolicy['source_profile_id']<1
                        || !is_int($savedPolicy['source_actor_identity_id'] ?? null) || $savedPolicy['source_actor_identity_id']<1)
                    && !in_array($chain['terminal_reason'],['private','no_candidate','ineligible'],true)) {
                    throw new LogicException('rechat_chain_policy_unproven');
                }
            }
            if ($chain===null) {
                if (($origin['type'] ?? '')==='trigger' && ($origin['payload']['kind'] ?? '')==='rechat') {
                    throw new LogicException('rechat_parent_reservation_missing');
                }
                $originCanonical=synth_protocol_canonical_json($origin);
                $result=@pg_query_params($connection,"SELECT d.policy FROM synth_meta.dialogue_origin_policies d
                    JOIN synth_meta.request_executions e USING(session_id,request_id)
                    JOIN synth_meta.protocol_events p USING(session_id,request_id)
                    WHERE d.session_id=$1 AND d.request_id=$2 AND e.state='complete'
                        AND p.canonical_event=$3::jsonb AND p.event_sha256=$4 AND e.request_sha256=p.event_sha256",
                    [$event['session_id'],$origin['request_id'],$originCanonical,hash('sha256',$originCanonical)]);
                if (!$result) throw new RuntimeException('rechat_origin_policy_query_failed');
                $saved=pg_fetch_assoc($result);
                if (!$saved) throw new LogicException('rechat_origin_policy_unavailable');
                $serverPolicy=json_decode($saved['policy'],true,flags:JSON_THROW_ON_ERROR);
                $mode=$serverPolicy['mode'] ?? '';
                if (!in_array($mode,['tight','conversational','group','random'],true)
                    || !is_bool($serverPolicy['private'] ?? null)) throw new InvalidArgumentException('rechat_policy_invalid');
                $serverPolicy['mode']=$mode==='random' ? ['tight','conversational','group'][random_int(0,2)] : $mode;
                if (!$serverPolicy['private']) {
                    $candidates=synth_rechat_load_candidates($connection,$db,$event);
                    $plan=synth_rechat_candidate_plan($candidates,$proof,$serverPolicy['mode'],[]);
                    $initialTerminal=$plan['terminal_reason'];
                    if ($plan['candidate']!==null) $serverPolicy=synth_rechat_profile_policy($serverPolicy,$plan['candidate']);
                }
            }
            $reservation=synth_rechat_reserve_continuation($db,$event,$chain===null ? $serverPolicy : [],$initialTerminal);
            $selection=synth_rechat_select_responder($db,$event);
            synth_rechat_assert_live_selection($connection,$event,$selection);
            $result=@pg_query_params($connection,'UPDATE synth_meta.rechat_selections
                SET prepared_at=COALESCE(prepared_at,clock_timestamp()) WHERE session_id=$1 AND request_id=$2 RETURNING prepared_at',
                [$event['session_id'],$event['request_id']]);
            if (!$result || !($receipt=pg_fetch_assoc($result))) throw new RuntimeException('rechat_prepare_receipt_failed');
            $selection['prepared_at']=$receipt['prepared_at'];
            if (!@pg_query($connection,'RELEASE SAVEPOINT synth_rechat_prepare')) throw new RuntimeException('rechat_prepare_release_failed');
            return ['reservation'=>$reservation,'selection'=>$selection];
        } catch (Throwable $error) {
            if (!@pg_query($connection,'ROLLBACK TO SAVEPOINT synth_rechat_prepare')
                || !@pg_query($connection,'RELEASE SAVEPOINT synth_rechat_prepare')) throw new RuntimeException('rechat_prepare_rollback_failed',0,$error);
            throw $error;
        }
    },2000);
}

// Downstream readers must consume a coordinated choice, never reserve, roll, or select on demand.
function synth_rechat_prepared_selection($db, array $event): array
{
    return $db->withNativeRequestLock(static function($connection,array $owner) use($db,$event) {
        if ($owner!==$event) throw new LogicException('rechat_prepared_owner_changed');
        $proof=synth_rechat_completed_parent($db,$event);
        if ($proof===null) throw new LogicException('rechat_prepared_parent_unavailable');
        $result=@pg_query_params($connection,"SELECT s.*,d.status,d.parent_request_id,d.parent_line_id,d.parent_response_sha256,
                d.round_number,d.chain_id,c.budget,c.policy,c.terminal_reason AS chain_terminal
            FROM synth_meta.rechat_selections s JOIN synth_meta.rechat_continuations d USING(session_id,request_id)
            JOIN synth_meta.rechat_chains c ON c.id=d.chain_id AND c.session_id=d.session_id
            WHERE s.session_id=$1 AND s.request_id=$2 AND s.prepared_at IS NOT NULL
                AND c.generation=$3 AND c.context_sequence=$4 AND c.turn_id=$5 AND c.runtime_variant=$6",
            [$event['session_id'],$event['request_id'],$event['generation'],$event['context_sequence'],$event['turn_id'],$event['runtime_variant']]);
        if (!$result) throw new RuntimeException('rechat_prepared_selection_query_failed');
        $selection=pg_fetch_assoc($result);
        if (!$selection || $selection['parent_request_id']!==$event['payload']['reply_to_request_id']
            || $selection['parent_line_id']!==$event['payload']['reply_to_line_id']
            || !hash_equals($selection['parent_response_sha256'],$proof['response_sha256'])) {
            throw new LogicException('rechat_prepared_selection_unavailable');
        }
        $policy=json_decode($selection['policy'],true,flags:JSON_THROW_ON_ERROR);
        if ($selection['role']!=='terminal') {
            if ($selection['status']!=='reserved'
                || in_array($selection['chain_terminal'],['private','no_candidate','ineligible'],true)) {
                throw new LogicException('rechat_prepared_chain_stopped');
            }
            if (!is_int($policy['source_profile_id'] ?? null) || $policy['source_profile_id']<1
                || !is_int($policy['source_actor_identity_id'] ?? null) || $policy['source_actor_identity_id']<1) {
                throw new LogicException('rechat_chain_policy_unproven');
            }
        }
        $selection['identity']=$selection['identity']===null ? null : json_decode($selection['identity'],true,flags:JSON_THROW_ON_ERROR);
        $selection['policy']=$policy;
        $selection['previous_speaker']=$proof['speaker'];
        synth_rechat_assert_live_selection($connection,$event,$selection);
        return $selection;
    },2000);
}
