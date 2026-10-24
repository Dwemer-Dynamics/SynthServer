<?php

require_once __DIR__.'/synth_rechat_admission.php';
require_once __DIR__.'/chat_helper_functions.php';

// Apply the same known native life/posture exclusions to the previous speaker and every candidate.
function synth_rechat_candidate_available(array $candidate): bool
{
    $state=$candidate['state'] ?? [];
    if (($state['alive'] ?? false)!==true || ($state['disabled'] ?? true)!==false
        || in_array($state['life_state'] ?? '',['dying','dead','unconscious','reanimate','recycle','essential_down','bleedout'],true)
        || (($state['life_state'] ?? '')==='restrained' && ($state['conversation_held'] ?? false)!==true)
        || in_array($state['posture'] ?? '',['want_to_sleep','waiting_for_sleep','sleeping'],true)) return false;
    $npc=$candidate['npc'];
    $race=strval($state['race'] ?? '').' '.strval($npc['race'] ?? '');
    // Native editor IDs concatenate words; expose their categories to the legacy whole-word classifier.
    $npc['race']=$race.' '.preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $race)
        .' '.preg_replace('/Race\b/i','',$race);
    return synthRechatActorBlockReason($candidate['identity']['display_name'] ?? '',$npc)==='';
}

// Rank captured canonical candidates; never collapse same-name actors or borrow global nearby state.
function synth_rechat_rank_candidates(array $candidates, array $proof, string $mode, array $history): array
{
    if (!in_array($mode,['tight','conversational','group'],true)) throw new LogicException('rechat_mode_unresolved');
    $speaker=synth_rechat_actor_key($proof['speaker']);
    $listener=($proof['listener_state'] ?? '')==='addressed' ? synth_rechat_actor_key($proof['listener'] ?? []) : null;
    $uses=[]; $last=[];
    foreach ($history as $entry) {
        if (($entry['role'] ?? '')!=='npc') continue;
        $key=synth_rechat_actor_key($entry['identity'] ?? []);
        if ($key===null) continue;
        $uses[$key]=($uses[$key] ?? 0)+1;
        $last[$key]=intval($entry['round_number']);
    }
    $ranked=[];
    foreach ($candidates as $ordinal=>$candidate) {
        $key=synth_rechat_actor_key($candidate['identity']);
        if ($key===null || $key===$speaker
            || ($candidate['identity']['playthrough_id'] ?? null)!==($proof['speaker']['playthrough_id'] ?? null)
            || !synth_rechat_candidate_available($candidate)
            || ($mode==='tight' && ($listener===null || $key!==$listener))) continue;
        $priority=$mode==='conversational' && $key===$listener ? -1 : 0;
        $rank=[$priority,$uses[$key] ?? 0,$last[$key] ?? 0,$mode==='group' && $key===$listener ? 1 : 0,$ordinal];
        $ranked[$key]=['candidate'=>$candidate,'rank'=>$rank];
    }
    uasort($ranked,static fn(array $a,array $b):int=>$a['rank']<=>$b['rank']);
    return array_values(array_map(static fn(array $entry):array=>$entry['candidate'],$ranked));
}

// Freeze candidate profile reads once inside the coordinator's transaction, before deriving round policy.
function synth_rechat_load_candidates($connection, $db, array $event): array
{
    $context=synth_protocol_current_context($db,$event);
    if (!$context) throw new LogicException('rechat_selection_context_unavailable');
    $playerKey=synth_rechat_actor_key($context['player']); $actors=[]; $states=[];
    foreach (array_merge([$context['target']],$context['payload']['audience'] ?? []) as $actor) {
        if (!is_array($actor)) continue;
        $key=synth_rechat_actor_key($actor);
        if ($key!==null && $key!==$playerKey) $actors[$key] ??= $actor;
    }
    foreach ($context['payload']['actor_states'] ?? [] as $state) {
        $key=synth_rechat_actor_key($state['actor'] ?? []);
        if ($key!==null) $states[$key]=$state;
    }
    $result=@pg_query_params($connection,"SELECT a.id AS actor_identity_id,n.id AS npc_id,n.profile_id,
            to_jsonb(n) AS npc,captured.actor AS identity,p.metadata AS profile_metadata
        FROM jsonb_array_elements($1::jsonb) WITH ORDINALITY captured(actor,ordinal)
        JOIN synth.actor_identities a ON a.game='fo4' AND upper(a.full_form_id)=upper(captured.actor->>'form_id')
            AND lower(a.origin_plugin)=lower(captured.actor->>'origin_plugin')
            AND a.playthrough_id=captured.actor->>'playthrough_id'
        JOIN synth_meta.runtime_sessions s ON s.session_id=$2 AND s.playthrough_id=a.playthrough_id
        JOIN synth.core_npc_master n ON n.actor_identity_id=a.id
        JOIN synth.core_profiles p ON p.id=n.profile_id
        ORDER BY captured.ordinal FOR SHARE OF n,p",
        [json_encode(array_values($actors),JSON_THROW_ON_ERROR),$event['session_id']]);
    if (!$result) throw new RuntimeException('rechat_selection_candidates_query_failed');
    $candidates=[];
    foreach (pg_fetch_all($result) as $candidate) {
        $candidate['identity']=json_decode($candidate['identity'],true,flags:JSON_THROW_ON_ERROR);
        $candidate['npc']=json_decode($candidate['npc'],true,flags:JSON_THROW_ON_ERROR);
        $candidate['profile_metadata']=json_decode($candidate['profile_metadata'] ?? '{}',true,flags:JSON_THROW_ON_ERROR);
        $candidate['state']=$states[synth_rechat_actor_key($candidate['identity'])] ?? [];
        $candidates[]=$candidate;
    }
    return $candidates;
}

// Share the exact first-candidate/terminal decision between profile-policy derivation and publication.
function synth_rechat_candidate_plan(array $candidates, array $proof, string $mode, array $history): array
{
    $speakerAvailable=false;
    foreach ($candidates as $candidate) {
        if (synth_rechat_actor_key($candidate['identity'])===synth_rechat_actor_key($proof['speaker'])
            && synth_rechat_candidate_available($candidate)) $speakerAvailable=true;
    }
    $ranked=$speakerAvailable ? synth_rechat_rank_candidates($candidates,$proof,$mode,$history) : [];
    return ['candidate'=>$ranked[0] ?? null,'terminal_reason'=>$ranked ? null : ($speakerAvailable ? 'no_candidate' : 'ineligible')];
}

// Random variety must not select a dead-end tight mode when an eligible captured NPC can reply.
// Explicit tight mode keeps its original addressing restriction; round/probability controls stay separate.
function synth_rechat_initial_candidate_plan(array $candidates, array $proof, string $mode): array
{
    if ($mode!=='random') return ['mode'=>$mode]+synth_rechat_candidate_plan($candidates,$proof,$mode,[]);
    $plans=[];$available=[];
    foreach (['tight','conversational','group'] as $resolved) {
        $plan=['mode'=>$resolved]+synth_rechat_candidate_plan($candidates,$proof,$resolved,[]);
        $plans[]=$plan;
        if ($plan['candidate']!==null) $available[]=$plan;
    }
    $choices=$available ?: $plans;
    return $choices[random_int(0,count($choices)-1)];
}

// Persist one responder per reserved request, before any profile globals, connector or speech work.
function synth_rechat_select_responder($db, array $event): array
{
    return $db->withNativeRequestLock(static function($connection,array $owner) use($db,$event) {
        if ($owner!==$event) throw new LogicException('rechat_selection_owner_changed');
        if (!@pg_query($connection,'SAVEPOINT synth_rechat_selection')) throw new RuntimeException('rechat_selection_savepoint_failed');
        try {
            $proof=synth_rechat_completed_parent($db,$event);
            if ($proof===null) throw new LogicException('rechat_selection_parent_unavailable');
            $result=@pg_query_params($connection,"SELECT d.*,c.mode,c.policy,c.terminal_reason AS chain_terminal FROM synth_meta.rechat_continuations d
                JOIN synth_meta.rechat_chains c ON c.id=d.chain_id AND c.session_id=d.session_id
                WHERE d.session_id=$1 AND d.request_id=$2 AND c.generation=$3 AND c.context_sequence=$4
                    AND c.turn_id=$5 AND c.runtime_variant=$6",
                [$event['session_id'],$event['request_id'],$event['generation'],$event['context_sequence'],$event['turn_id'],$event['runtime_variant']]);
            if (!$result) throw new RuntimeException('rechat_selection_reservation_query_failed');
            $reservation=pg_fetch_assoc($result);
            if (!$reservation || $reservation['parent_request_id']!==$event['payload']['reply_to_request_id']
                || $reservation['parent_line_id']!==$event['payload']['reply_to_line_id']
                || !hash_equals($reservation['parent_response_sha256'],$proof['response_sha256'])) {
                throw new LogicException('rechat_selection_reservation_unavailable');
            }
            $result=@pg_query_params($connection,'SELECT * FROM synth_meta.rechat_selections WHERE session_id=$1 AND request_id=$2',
                [$event['session_id'],$event['request_id']]);
            if (!$result) throw new RuntimeException('rechat_selection_replay_query_failed');
            $selected=pg_fetch_assoc($result);
            if (!$selected) {
                $terminal=$reservation['terminal_reason']; $actorId=null; $npcId=null; $profileId=null; $identity=null;
                if (in_array($reservation['chain_terminal'],['private','no_candidate','ineligible'],true)) $terminal=$reservation['chain_terminal'];
                $role='terminal';
                if ($reservation['status']==='reserved' && $terminal===null) {
                    $candidates=synth_rechat_load_candidates($connection,$db,$event);
                    $result=@pg_query_params($connection,"SELECT s.role,s.identity,d.round_number
                        FROM synth_meta.rechat_selections s JOIN synth_meta.rechat_continuations d USING(session_id,request_id)
                        WHERE d.chain_id=$1 AND d.round_number<$2 ORDER BY d.round_number",
                        [$reservation['chain_id'],$reservation['round_number']]);
                    if (!$result) throw new RuntimeException('rechat_selection_history_query_failed');
                    $history=pg_fetch_all($result);
                    foreach ($history as &$entry) $entry['identity']=$entry['identity']===null ? null : json_decode($entry['identity'],true,flags:JSON_THROW_ON_ERROR);
                    unset($entry);
                    $plan=synth_rechat_candidate_plan($candidates,$proof,$reservation['mode'],$history);
                    $terminal=$plan['terminal_reason'];
                    if ($plan['candidate']!==null) {
                        $candidate=$plan['candidate']; $role='npc'; $terminal=null;
                        $actorId=$candidate['actor_identity_id']; $npcId=$candidate['npc_id'];
                        $profileId=$candidate['profile_id']; $identity=$candidate['identity'];
                        $policy=json_decode($reservation['policy'],true,flags:JSON_THROW_ON_ERROR);
                        $sinceNarrator=1; $lastRole=null;
                        foreach ($history as $entry) {
                            $lastRole=$entry['role'];
                            if ($lastRole==='narrator') $sinceNarrator=0;
                            elseif ($lastRole==='npc') ++$sinceNarrator;
                        }
                        if ($policy['random_narration'] && $lastRole!=='narrator'
                            && $sinceNarrator>=max(0,$policy['narration_cooldown']) && random_int(1,100)<=$policy['narration_chance']) {
                            $result=@pg_query($connection,"SELECT p.id FROM synth.core_profiles p JOIN synth.core_narrator n
                                ON n.id='profile_id' AND n.value=p.id::text FOR SHARE OF p,n");
                            if (!$result) throw new RuntimeException('rechat_selection_narrator_query_failed');
                            $narrator=pg_fetch_assoc($result);
                            if ($narrator) { $role='narrator'; $profileId=$narrator['id']; $actorId=null; $npcId=null; $identity=null; }
                        }
                    }
                }
                $result=@pg_query_params($connection,"INSERT INTO synth_meta.rechat_selections
                    (session_id,request_id,role,actor_identity_id,npc_id,profile_id,identity,terminal_reason)
                    VALUES($1,$2,$3,$4,$5,$6,$7::jsonb,$8) RETURNING *",
                    [$event['session_id'],$event['request_id'],$role,$actorId,$npcId,$profileId,
                     $identity===null ? null : json_encode($identity,JSON_THROW_ON_ERROR),$terminal]);
                if (!$result) throw new RuntimeException('rechat_selection_insert_failed');
                $selected=pg_fetch_assoc($result);
                if ($terminal!==null && !@pg_query_params($connection,'UPDATE synth_meta.rechat_chains SET terminal_reason=$2 WHERE id=$1',
                    [$reservation['chain_id'],$terminal])) throw new RuntimeException('rechat_selection_terminal_failed');
            }
            $selected['identity']=$selected['identity']===null ? null : json_decode($selected['identity'],true,flags:JSON_THROW_ON_ERROR);
            if (!@pg_query($connection,'RELEASE SAVEPOINT synth_rechat_selection')) throw new RuntimeException('rechat_selection_release_failed');
            return $selected;
        } catch (Throwable $error) {
            if (!@pg_query($connection,'ROLLBACK TO SAVEPOINT synth_rechat_selection')
                || !@pg_query($connection,'RELEASE SAVEPOINT synth_rechat_selection')) throw new RuntimeException('rechat_selection_rollback_failed',0,$error);
            throw $error;
        }
    },2000);
}
