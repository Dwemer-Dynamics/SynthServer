<?php

require_once __DIR__.'/synth_relationship_responder.php';

// Private saved-pair proof. Never add these receipts to ordinary speech/history sources.
// A ready hashed publication proves acceptance; mutable cancellation timestamps do not.
function synth_relationship_pair_evidence_cte(): string
{
    return ", interaction_result_owners AS MATERIALIZED (
        SELECT i.*,owner.state AS owner_state,owner.error_code AS owner_error,owner.response_body AS owner_body,
            owner.claimed_at AS owner_claimed_at,protocol.canonical_event AS owner_event,
            c.context_payload AS owner_context,c.player_identity_id AS owner_player_identity_id,
            c.target_identity_id AS owner_target_identity_id,c.audience_identity_ids AS owner_audience_identity_ids
        FROM interaction_result_base i
        JOIN synth_meta.worker_jobs j ON j.id=i.job_id AND j.relationship_interaction_id=i.interaction_id AND j.state='ready'
            AND j.job_type='relationship' AND j.relationship_evaluation_id IS NULL AND j.memory_batch_id IS NULL
            AND j.session_id=i.session_id AND j.request_id=i.request_id AND j.generation=i.generation
            AND j.actor_identity_id=i.speaker_identity_id AND j.payload->>'context_sequence'=i.context_sequence::text
        JOIN synth_meta.context_snapshots c ON c.session_id=i.session_id AND c.generation=i.generation
            AND c.snapshot_sequence=i.context_sequence AND c.playthrough_id=i.playthrough_id
        JOIN synth_meta.request_executions owner ON owner.session_id=i.session_id AND owner.request_id=i.request_id
            AND owner.generation=i.generation AND owner.turn_id=j.turn_id
        JOIN synth_meta.protocol_events protocol ON protocol.session_id=i.session_id AND protocol.request_id=i.request_id
            AND protocol.generation=i.generation AND protocol.turn_id=j.turn_id AND protocol.event_type=owner.event_type
            AND protocol.event_sha256=owner.request_sha256 AND protocol.canonical_event=i.input_payload->'owner'
            AND ".synth_relationship_responder_sql('protocol.canonical_event','i.speaker_identity_id',"i.input_payload->'context'")."
    ), interaction_rechat_response AS MATERIALIZED (
        SELECT i.*,receipt.response_payload AS pair_response_payload,receipt.history_event_id AS owner_response_history_event_id
        FROM interaction_result_owners i
        JOIN synth_meta.runtime_sessions runtime ON runtime.session_id=i.session_id AND runtime.playthrough_id=i.playthrough_id
            AND runtime.game='fo4' AND runtime.runtime_variant=i.owner_event->>'runtime_variant'
        JOIN synth_meta.relationship_response_inputs receipt ON receipt.session_id=i.session_id
            AND receipt.request_id=i.request_id AND receipt.actor_identity_id=i.speaker_identity_id
        JOIN synth_meta.history_events speech ON speech.id=receipt.history_event_id AND speech.session_id=i.session_id
            AND speech.generation=i.generation AND speech.context_sequence=i.context_sequence AND speech.request_id=i.request_id
            AND speech.playthrough_id=i.playthrough_id AND speech.id<i.history_event_id
        WHERE i.owner_event->>'protocol_version'='2' AND i.owner_event->>'type'='trigger'
            AND i.owner_event#>>'{payload,kind}'='rechat' AND i.owner_event->>'runtime_variant'='flat'
            AND i.owner_event->'capabilities' @> '[\"dialogue.turn_ownership\",\"dialogue.rechat.ownership\"]'::jsonb
            AND i.owner_event->>'session_id'=i.session_id AND i.owner_event->>'request_id'=i.request_id
            AND i.owner_event->>'generation'=i.generation::text AND i.owner_event->>'context_sequence'=i.context_sequence::text
            AND speech.event_payload->>'type'='relationship_response' AND speech.event_payload->>'delivery_state'='derived'
            AND speech.event_payload->>'response_sha256'=receipt.response_sha256
            AND speech.event_payload->>'response_payload_sha256'=encode(sha256(convert_to(receipt.response_payload::text,'UTF8')),'hex')
            AND receipt.response_payload->>'schema'='synth.relationship.response.v3'
            AND receipt.response_payload->'request'=i.owner_event
            AND receipt.response_payload->>'request_sha256'=(SELECT p.event_sha256 FROM synth_meta.protocol_events p
                WHERE p.session_id=i.session_id AND p.request_id=i.request_id)
            AND receipt.response_payload->'input_events'='[]'::jsonb
            AND NOT EXISTS(SELECT 1 FROM synth_meta.relationship_response_sources source WHERE source.response_history_event_id=receipt.history_event_id)
            AND ((i.owner_state='complete' AND encode(sha256(i.owner_body),'hex')=receipt.response_sha256)
                OR (i.owner_state='cancelled' AND i.owner_body IS NULL AND i.owner_error IN ('turn_cancelled','request_cancelled')))
    ), interaction_rechat_prepared AS MATERIALIZED (
        SELECT i.*,selection.identity AS reply_identity,continuation.parent_request_id AS proven_parent_request_id,
            continuation.parent_line_id AS proven_parent_line_id,continuation.parent_response_sha256 AS proven_parent_response_sha256
        FROM interaction_rechat_response i
        JOIN synth_meta.rechat_selections selection ON selection.session_id=i.session_id AND selection.request_id=i.request_id
            AND selection.role='npc' AND selection.prepared_at IS NOT NULL AND selection.actor_identity_id=i.speaker_identity_id
        JOIN synth_meta.rechat_continuations continuation ON continuation.session_id=selection.session_id
            AND continuation.request_id=selection.request_id AND continuation.status='reserved'
            AND continuation.parent_request_id=i.owner_event#>>'{payload,reply_to_request_id}'
            AND continuation.parent_line_id=i.owner_event#>>'{payload,reply_to_line_id}'
        JOIN synth_meta.rechat_chains chain ON chain.id=continuation.chain_id AND chain.session_id=i.session_id
            AND chain.generation=i.generation AND chain.context_sequence=i.context_sequence
            AND chain.turn_id=i.owner_event->>'turn_id' AND chain.runtime_variant=i.owner_event->>'runtime_variant'
            -- A later round may close the chain; the original reserved/public proof remains historical.
        WHERE chain.policy->'private'='false'::jsonb
            AND chain.policy->>'source_profile_id' ~ '^[1-9][0-9]*$'
            AND chain.policy->>'source_actor_identity_id' ~ '^[1-9][0-9]*$'
            AND selection.identity=i.input_payload#>'{interaction,participants,speaker}'
            AND selection.identity->>'playthrough_id'=i.playthrough_id
    ), interaction_rechat_parent AS MATERIALIZED (
        SELECT i.*,parent.canonical_event AS parent_event,parent.event_sha256 AS parent_event_sha256,
            parent_owner.state AS parent_execution_state,parent_owner.error_code AS parent_execution_error,
            parent_owner.response_body AS parent_execution_body,parent_owner.event_type AS parent_execution_type
        FROM interaction_rechat_prepared i
        JOIN synth.actor_identities speaker ON speaker.id=i.speaker_identity_id AND speaker.game='fo4'
            AND speaker.playthrough_id=i.playthrough_id AND upper(speaker.full_form_id)=upper(i.reply_identity->>'form_id')
            AND lower(speaker.origin_plugin)=lower(i.reply_identity->>'origin_plugin')
            AND speaker.playthrough_id=i.reply_identity->>'playthrough_id'
        JOIN synth.actor_identities player ON player.id=i.owner_player_identity_id AND player.game='fo4'
            AND player.playthrough_id=i.playthrough_id AND upper(player.full_form_id)=upper(i.owner_context#>>'{player,form_id}')
            AND lower(player.origin_plugin)=lower(i.owner_context#>>'{player,origin_plugin}')
            AND player.playthrough_id=i.owner_context#>>'{player,playthrough_id}'
        JOIN synth_meta.request_executions parent_owner ON parent_owner.session_id=i.session_id
            AND parent_owner.request_id=i.proven_parent_request_id AND parent_owner.generation=i.generation
            AND parent_owner.turn_id=i.owner_event->>'turn_id'
        JOIN synth_meta.protocol_events parent ON parent.session_id=parent_owner.session_id AND parent.request_id=parent_owner.request_id
            AND parent.generation=parent_owner.generation AND parent.turn_id=parent_owner.turn_id
            AND parent.event_type=parent_owner.event_type AND parent.event_sha256=parent_owner.request_sha256
        WHERE parent.canonical_event->>'protocol_version'='2'
            AND parent.canonical_event->>'session_id'=i.session_id AND parent.canonical_event->>'request_id'=parent.request_id
            AND parent.canonical_event->>'generation'=i.generation::text
            AND parent.canonical_event->>'turn_id'=i.owner_event->>'turn_id'
            AND parent.canonical_event->>'context_sequence'=i.context_sequence::text
            AND parent.canonical_event->>'runtime_variant'=i.owner_event->>'runtime_variant'
            AND parent.canonical_event->>'type'=parent_owner.event_type AND parent_owner.event_type IN ('input_text','input_audio','trigger','activate')
            AND (parent_owner.event_type<>'activate' OR parent.canonical_event#>>'{payload,source}'='manual')
            AND parent.canonical_event->'capabilities' @> '[\"dialogue.turn_ownership\"]'::jsonb
    ), interaction_rechat_provenance AS MATERIALIZED (
        SELECT i.* FROM interaction_rechat_parent i
        JOIN synth_meta.relationship_response_inputs parent_receipt ON parent_receipt.session_id=i.session_id
            AND parent_receipt.request_id=i.proven_parent_request_id AND parent_receipt.response_sha256=i.proven_parent_response_sha256
        JOIN synth.actor_identities parent_actor ON parent_actor.id=parent_receipt.actor_identity_id
            AND parent_actor.game='fo4' AND parent_actor.playthrough_id=i.playthrough_id
        JOIN synth_meta.history_events parent_speech ON parent_speech.id=parent_receipt.history_event_id
            AND parent_speech.session_id=i.session_id AND parent_speech.generation=i.generation
            AND parent_speech.context_sequence=i.context_sequence AND parent_speech.request_id=i.proven_parent_request_id
            AND parent_speech.playthrough_id=i.playthrough_id AND parent_speech.id<i.owner_response_history_event_id
        JOIN synth_meta.response_routes route ON route.session_id=i.session_id AND route.request_id=i.proven_parent_request_id
            AND route.generation=i.generation AND route.context_sequence=i.context_sequence AND route.line_id=i.proven_parent_line_id
        WHERE parent_speech.event_payload->>'type'='relationship_response' AND parent_speech.event_payload->>'delivery_state'='derived'
            AND ".synth_relationship_responder_sql('i.parent_event','parent_receipt.actor_identity_id',synth_relationship_responder_context_sql('i.parent_event'))."
            AND parent_speech.event_payload->>'response_sha256'=parent_receipt.response_sha256
            AND parent_speech.event_payload->>'response_payload_sha256'=encode(sha256(convert_to(parent_receipt.response_payload::text,'UTF8')),'hex')
            AND parent_receipt.response_payload->'request'=i.parent_event
            AND parent_receipt.response_payload->>'request_sha256'=i.parent_event_sha256
            AND parent_receipt.response_payload->>'schema' IN ('synth.relationship.response.v2','synth.relationship.response.v3')
            AND EXISTS(SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(parent_receipt.response_payload->'entries')='array'
                THEN parent_receipt.response_payload->'entries' ELSE '[]'::jsonb END) entry
                WHERE upper(entry#>>'{speaker,form_id}')=upper(route.speaker->>'form_id')
                    AND lower(entry#>>'{speaker,origin_plugin}')=lower(route.speaker->>'origin_plugin')
                    AND entry#>>'{speaker,playthrough_id}'=i.playthrough_id)
            AND CASE WHEN jsonb_typeof(parent_receipt.response_payload->'input_events')='array' THEN
                jsonb_array_length(parent_receipt.response_payload->'input_events')=CASE WHEN i.parent_execution_type IN ('input_text','input_audio') THEN 1 ELSE 0 END
                AND jsonb_array_length(parent_receipt.response_payload->'input_events')=(SELECT count(*) FROM synth_meta.relationship_response_sources source
                    WHERE source.response_history_event_id=parent_receipt.history_event_id)
                AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(parent_receipt.response_payload->'input_events') item WHERE NOT EXISTS(
                    SELECT 1 FROM synth_meta.relationship_response_sources source JOIN synth_meta.history_events raw ON raw.id=source.source_history_event_id
                    WHERE source.response_history_event_id=parent_receipt.history_event_id AND to_jsonb(raw.id)=item->'id' AND raw.id<parent_speech.id
                        AND raw.session_id=i.session_id AND raw.generation=i.generation AND raw.request_id=i.proven_parent_request_id
                        AND raw.context_sequence=i.context_sequence AND raw.playthrough_id=i.playthrough_id
                        AND raw.event_payload=item->'event' AND raw.event_payload->>'type' IN ('inputtext','inputtext_s')
                        AND (raw.event_payload->>'delivery_state' IS NULL OR raw.event_payload->>'delivery_state'='received')))
                ELSE FALSE END
            AND ((i.parent_execution_state='complete' AND encode(sha256(i.parent_execution_body),'hex')=parent_receipt.response_sha256)
                OR (i.parent_execution_state IN ('complete','cancelled') AND i.parent_execution_body IS NULL
                    AND i.parent_execution_error IN ('turn_cancelled','request_cancelled')))
            AND upper(route.speaker->>'form_id')=upper(i.owner_event#>>'{payload,actor,form_id}')
            AND lower(route.speaker->>'origin_plugin')=lower(i.owner_event#>>'{payload,actor,origin_plugin}')
            AND route.speaker->>'playthrough_id'=i.playthrough_id
            AND upper(route.speaker->>'form_id')=upper(parent_actor.full_form_id)
            AND lower(route.speaker->>'origin_plugin')=lower(parent_actor.origin_plugin)
    ), interaction_result_execution AS MATERIALIZED (
        SELECT i.*,c.context_payload AS original_context,c.player_identity_id,c.target_identity_id,c.audience_identity_ids,
            CASE WHEN rechat.history_event_id IS NOT NULL THEN rechat.reply_identity ELSE c.context_payload->'target' END AS effective_speaker_identity,
            -- Legacy deferred evaluations may refer to older speech. Prove the evaluator's
            -- own prepared response independently, then require the older source's saved proof.
            CASE WHEN rechat.history_event_id IS NOT NULL THEN
                CASE WHEN i.response_history_event_id=rechat.owner_response_history_event_id
                    THEN rechat.pair_response_payload ELSE response.response_payload END
                WHEN i.owner_state='complete' AND response.history_event_id IS NOT NULL
                    AND i.input_payload->'context'=i.owner_context THEN response.response_payload END AS pair_response_payload
        FROM interaction_result_owners i
        LEFT JOIN interaction_rechat_provenance rechat ON rechat.history_event_id=i.history_event_id
        LEFT JOIN relationship_graph_responses response ON response.history_event_id=i.response_history_event_id
            AND response.actor_identity_id=i.speaker_identity_id
        JOIN synth_meta.context_snapshots c ON c.session_id=i.session_id AND c.generation=i.generation
            AND c.playthrough_id=i.playthrough_id AND c.player_identity_id=i.owner_player_identity_id
            AND c.snapshot_sequence=CASE WHEN i.owner_state='complete' AND response.history_event_id IS NOT NULL
                AND i.input_payload->'context'=i.owner_context THEN i.context_sequence ELSE CASE WHEN rechat.history_event_id IS NOT NULL
                AND i.owner_event->'capabilities' @> '[\"dialogue.rechat.scene\"]'::jsonb THEN
                CASE WHEN jsonb_typeof(i.owner_event#>'{payload,scene_context_sequence}')='number'
                    AND i.owner_event#>>'{payload,scene_context_sequence}' ~ '^[1-9][0-9]{0,15}$'
                    THEN (i.owner_event#>>'{payload,scene_context_sequence}')::bigint ELSE NULL END
                ELSE i.context_sequence END END
        LEFT JOIN synth_meta.context_snapshots previous ON previous.session_id=c.session_id AND previous.generation=c.generation
            AND previous.playthrough_id=c.playthrough_id AND previous.player_identity_id=c.player_identity_id
            AND previous.snapshot_sequence=CASE WHEN rechat.parent_event->'payload' ? 'scene_context_sequence' THEN
                CASE WHEN jsonb_typeof(rechat.parent_event#>'{payload,scene_context_sequence}')='number'
                    AND rechat.parent_event#>>'{payload,scene_context_sequence}' ~ '^[1-9][0-9]{0,15}$'
                    THEN (rechat.parent_event#>>'{payload,scene_context_sequence}')::bigint ELSE NULL END ELSE i.context_sequence END
        WHERE i.input_payload->'context'=c.context_payload
            AND ((i.owner_state='complete' AND response.history_event_id IS NOT NULL
                AND c.snapshot_sequence=i.context_sequence
                AND c.target_identity_id=i.speaker_identity_id)
                OR (rechat.history_event_id IS NOT NULL AND c.history_event_id IS NOT NULL AND c.history_event_id<i.history_event_id
                    AND c.context_payload->>'playthrough_id'=i.playthrough_id
                    AND c.context_payload->'snapshot_sequence'=to_jsonb(c.snapshot_sequence)
                    AND jsonb_typeof(c.context_payload->'audience')='array'
                    AND jsonb_typeof(c.context_payload->'player')='object' AND jsonb_typeof(c.context_payload->'target')='object'
                    AND upper(c.context_payload#>>'{player,form_id}')=upper(i.owner_context#>>'{player,form_id}')
                    AND lower(c.context_payload#>>'{player,origin_plugin}')=lower(i.owner_context#>>'{player,origin_plugin}')
                    AND c.context_payload#>>'{player,playthrough_id}'=i.playthrough_id
                    AND CASE WHEN i.owner_event->'capabilities' @> '[\"dialogue.rechat.scene\"]'::jsonb THEN
                c.snapshot_sequence>i.context_sequence AND c.snapshot_sequence>previous.snapshot_sequence
                AND c.received_at BETWEEN i.owner_claimed_at-interval '10 seconds' AND i.owner_claimed_at
                AND c.context_payload->>'audience_observation' IN ('complete','partial')
                AND upper(c.context_payload#>>'{target,form_id}')=upper(i.owner_event#>>'{payload,actor,form_id}')
                AND lower(c.context_payload#>>'{target,origin_plugin}')=lower(i.owner_event#>>'{payload,actor,origin_plugin}')
                AND c.context_payload#>>'{target,playthrough_id}'=i.playthrough_id
                AND upper(c.context_payload#>>'{player,form_id}')=upper(previous.context_payload#>>'{player,form_id}')
                AND lower(c.context_payload#>>'{player,origin_plugin}')=lower(previous.context_payload#>>'{player,origin_plugin}')
                AND c.context_payload#>>'{player,playthrough_id}'=previous.context_payload#>>'{player,playthrough_id}'
                AND jsonb_typeof(c.context_payload#>'{world,interior}')='boolean'
                AND c.context_payload#>'{world,interior}'=previous.context_payload#>'{world,interior}'
                AND jsonb_typeof(c.context_payload#>'{world,scene,cell,form_id}')='string'
                AND jsonb_typeof(c.context_payload#>'{world,scene,cell,origin_plugin}')='string'
                AND jsonb_typeof(previous.context_payload#>'{world,scene,cell,form_id}')='string'
                AND jsonb_typeof(previous.context_payload#>'{world,scene,cell,origin_plugin}')='string'
                AND c.context_payload#>>'{world,scene,cell,form_id}'<>'0x00000000'
                AND c.context_payload#>>'{world,scene,cell,origin_plugin}'<>''
                AND upper(c.context_payload#>>'{world,scene,cell,form_id}')=upper(previous.context_payload#>>'{world,scene,cell,form_id}')
                AND lower(c.context_payload#>>'{world,scene,cell,origin_plugin}')=lower(previous.context_payload#>>'{world,scene,cell,origin_plugin}')
                AND CASE WHEN c.context_payload#>'{world,interior}'='true'::jsonb THEN
                    c.context_payload#>'{world,scene,worldspace}'='null'::jsonb AND previous.context_payload#>'{world,scene,worldspace}'='null'::jsonb
                    ELSE jsonb_typeof(c.context_payload#>'{world,scene,worldspace,form_id}')='string'
                        AND jsonb_typeof(c.context_payload#>'{world,scene,worldspace,origin_plugin}')='string'
                        AND jsonb_typeof(previous.context_payload#>'{world,scene,worldspace,form_id}')='string'
                        AND jsonb_typeof(previous.context_payload#>'{world,scene,worldspace,origin_plugin}')='string'
                        AND c.context_payload#>>'{world,scene,worldspace,form_id}'<>'0x00000000'
                        AND c.context_payload#>>'{world,scene,worldspace,origin_plugin}'<>''
                        AND upper(c.context_payload#>>'{world,scene,worldspace,form_id}')=upper(previous.context_payload#>>'{world,scene,worldspace,form_id}')
                        AND lower(c.context_payload#>>'{world,scene,worldspace,origin_plugin}')=lower(previous.context_payload#>>'{world,scene,worldspace,origin_plugin}') END
                ELSE NOT (i.owner_event->'payload' ? 'scene_context_sequence') END))
    ) ";
}
