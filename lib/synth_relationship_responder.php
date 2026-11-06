<?php

require_once __DIR__.'/synth_protocol_state.php';

// Relationship evidence belongs to the proven replying NPC, not the scene focus or parent speaker.
function synth_relationship_responder($db, array $event, array $context): ?array
{
    $actor=synth_protocol_response_actor_identity($db,$event,$context);
    if ($actor===null || isset($actor['kind'])) return null;
    $payload=$context['payload'] ?? null;
    if (!is_array($payload) || !is_array($payload['player'] ?? null) || !is_array($payload['target'] ?? null)) return null;
    $key=static function(array $identity): ?string {
        foreach (['form_id','origin_plugin','playthrough_id'] as $field) {
            if (!is_string($identity[$field] ?? null) || $identity[$field]==='') return null;
        }
        return strtoupper($identity['form_id']).'|'.strtolower($identity['origin_plugin']).'|'.$identity['playthrough_id'];
    };
    $actorKey=$key($actor);
    if ($actorKey===null || $actorKey===$key($payload['player'])
        || ($actor['playthrough_id'] ?? null)!==($payload['playthrough_id'] ?? null)) return null;
    if (synth_protocol_owns_rechat($event)) {
        $selection=synth_rechat_prepared_selection($db,$event);
        if ($selection['role']!=='npc' || ($selection['policy']['private'] ?? null)!==false) return null;
    } elseif ($actorKey!==$key($payload['target'])) {
        // Preserve target-only admission for non-rechat requests, including action replies.
        return null;
    }
    $matches=[];
    foreach (array_merge([$payload['target']],$payload['audience'] ?? []) as $identity) {
        if (is_array($identity) && $key($identity)===$actorKey) $matches[$actorKey]=$identity;
    }
    if (count($matches)!==1) return null;
    $result=$db->execQuery("SELECT id FROM synth.actor_identities WHERE game='fo4'
        AND upper(full_form_id)=upper($1) AND lower(origin_plugin)=lower($2) AND playthrough_id=$3",
        [$actor['form_id'],$actor['origin_plugin'],$actor['playthrough_id']]);
    $row=$result ? $db->fetchArray($result) : null;
    if (!$row || intval($row['id'])<1) return null;
    return ['actor_identity_id'=>intval($row['id']),'identity'=>$actor];
}

// Expressions are private SQL fragments supplied by readers, never client/model text.
function synth_relationship_responder_context_sql(string $event): string
{
    return "(SELECT captured.context_payload FROM synth_meta.protocol_events context_owner
        JOIN synth_meta.context_snapshots captured ON captured.session_id=context_owner.session_id
            AND captured.generation=context_owner.generation
            AND captured.snapshot_sequence=CASE WHEN ($event)->'capabilities' ? 'dialogue.rechat.scene'
                AND ($event)->>'type'='trigger' AND ($event)#>>'{payload,kind}'='rechat'
                THEN CASE WHEN jsonb_typeof(($event)#>'{payload,scene_context_sequence}')='number'
                    AND ($event)#>>'{payload,scene_context_sequence}' ~ '^[1-9][0-9]{0,15}$'
                    THEN (($event)#>>'{payload,scene_context_sequence}')::bigint ELSE NULL END
                ELSE CASE WHEN ($event)->>'context_sequence' ~ '^[1-9][0-9]{0,15}$'
                    THEN (($event)->>'context_sequence')::bigint ELSE NULL END END
        WHERE context_owner.session_id=($event)->>'session_id' AND context_owner.request_id=($event)->>'request_id'
            AND context_owner.generation::text=($event)->>'generation' AND context_owner.turn_id=($event)->>'turn_id'
            AND context_owner.event_type=($event)->>'type' AND context_owner.canonical_event=($event))";
}

// Historical proof uses immutable prepared identities and captured membership, never live NPC profiles.
function synth_relationship_responder_sql(string $event, string $actorId, string $context): string
{
    return "EXISTS (SELECT 1 FROM synth_meta.protocol_events responder_owner
        JOIN synth_meta.request_executions responder_execution ON responder_execution.session_id=responder_owner.session_id
            AND responder_execution.request_id=responder_owner.request_id AND responder_execution.generation=responder_owner.generation
            AND responder_execution.turn_id=responder_owner.turn_id AND responder_execution.event_type=responder_owner.event_type
            AND responder_execution.request_sha256=responder_owner.event_sha256
        JOIN synth_meta.runtime_sessions responder_runtime ON responder_runtime.session_id=responder_owner.session_id
            AND responder_runtime.runtime_variant=responder_owner.canonical_event->>'runtime_variant' AND responder_runtime.game='fo4'
        JOIN synth_meta.context_snapshots responder_root ON responder_root.session_id=responder_owner.session_id
            AND responder_root.generation=responder_owner.generation AND responder_root.playthrough_id=responder_runtime.playthrough_id
            AND responder_root.snapshot_sequence=CASE WHEN ($event)->>'context_sequence' ~ '^[1-9][0-9]{0,15}$'
                THEN (($event)->>'context_sequence')::bigint ELSE NULL END
        JOIN synth.actor_identities responder_actor ON responder_actor.id=($actorId) AND responder_actor.game='fo4'
            AND responder_actor.playthrough_id=responder_root.playthrough_id AND responder_actor.id<>responder_root.player_identity_id
        LEFT JOIN synth_meta.rechat_selections responder_selection ON responder_selection.session_id=responder_owner.session_id
            AND responder_selection.request_id=responder_owner.request_id
        LEFT JOIN synth_meta.rechat_continuations responder_continuation ON responder_continuation.session_id=responder_owner.session_id
            AND responder_continuation.request_id=responder_owner.request_id
        LEFT JOIN synth_meta.rechat_chains responder_chain ON responder_chain.id=responder_continuation.chain_id
            AND responder_chain.session_id=responder_owner.session_id
        WHERE responder_owner.session_id=($event)->>'session_id' AND responder_owner.request_id=($event)->>'request_id'
            AND responder_owner.generation::text=($event)->>'generation' AND responder_owner.turn_id=($event)->>'turn_id'
            AND responder_owner.event_type=($event)->>'type' AND responder_owner.canonical_event=($event)
            AND ($event)->>'protocol_version'='2'
            AND ($event)->>'session_id'=responder_owner.session_id AND ($event)->>'request_id'=responder_owner.request_id
            AND ($event)->>'generation'=responder_owner.generation::text AND ($event)->>'turn_id'=responder_owner.turn_id
            AND ($context)=".synth_relationship_responder_context_sql($event)."
            AND ($context)->>'playthrough_id'=responder_actor.playthrough_id
            AND EXISTS(SELECT 1 FROM jsonb_array_elements(jsonb_build_array(($context)->'target')
                ||CASE WHEN jsonb_typeof(($context)->'audience')='array' THEN ($context)->'audience' ELSE '[]'::jsonb END) captured_actor
                WHERE upper(captured_actor->>'form_id')=upper(responder_actor.full_form_id)
                    AND lower(captured_actor->>'origin_plugin')=lower(responder_actor.origin_plugin)
                    AND captured_actor->>'playthrough_id'=responder_actor.playthrough_id)
            AND ((NOT (COALESCE(($event)->>'type','')='trigger' AND COALESCE(($event)#>>'{payload,kind}','')='rechat')
                    AND responder_actor.id=responder_root.target_identity_id
                    AND upper(($context)#>>'{target,form_id}')=upper(responder_actor.full_form_id)
                    AND lower(($context)#>>'{target,origin_plugin}')=lower(responder_actor.origin_plugin))
                OR (($event)->>'type'='trigger' AND ($event)#>>'{payload,kind}'='rechat'
                    AND ($event)->'capabilities' @> '[\"dialogue.turn_ownership\",\"dialogue.rechat.ownership\"]'::jsonb
                    AND responder_selection.role='npc' AND responder_selection.prepared_at IS NOT NULL
                    AND responder_selection.actor_identity_id=responder_actor.id
                    AND upper(responder_selection.identity->>'form_id')=upper(responder_actor.full_form_id)
                    AND lower(responder_selection.identity->>'origin_plugin')=lower(responder_actor.origin_plugin)
                    AND responder_selection.identity->>'playthrough_id'=responder_actor.playthrough_id
                    AND responder_continuation.status='reserved'
                    AND responder_continuation.parent_request_id=($event)#>>'{payload,reply_to_request_id}'
                    AND responder_continuation.parent_line_id=($event)#>>'{payload,reply_to_line_id}'
                    AND responder_chain.generation=responder_owner.generation AND responder_chain.turn_id=responder_owner.turn_id
                    AND responder_chain.context_sequence=responder_root.snapshot_sequence
                    AND responder_chain.runtime_variant=responder_runtime.runtime_variant AND responder_chain.policy->'private'='false'::jsonb
                    AND responder_chain.policy->>'source_profile_id' ~ '^[1-9][0-9]*$'
                    AND responder_chain.policy->>'source_actor_identity_id' ~ '^[1-9][0-9]*$'
                    AND CASE WHEN ($event)->'capabilities' ? 'dialogue.rechat.scene' THEN
                        jsonb_typeof(($context)->'snapshot_sequence')='number'
                        AND CASE WHEN ($context)->>'snapshot_sequence' ~ '^[1-9][0-9]{0,15}$'
                            THEN (($context)->>'snapshot_sequence')::bigint ELSE NULL END>responder_root.snapshot_sequence
                        AND EXISTS(SELECT 1 FROM synth_meta.context_snapshots fresh
                            WHERE fresh.session_id=responder_owner.session_id AND fresh.generation=responder_owner.generation
                                AND fresh.playthrough_id=responder_root.playthrough_id AND fresh.context_payload=($context)
                                AND fresh.player_identity_id=responder_root.player_identity_id
                                AND fresh.received_at BETWEEN responder_execution.claimed_at-interval '10 seconds' AND responder_execution.claimed_at)
                        ELSE NOT (($event)->'payload' ? 'scene_context_sequence') END))
    )";
}
