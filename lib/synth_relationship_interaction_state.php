<?php

require_once __DIR__.'/synth_relationship_pair_evidence.php';

// Accepted paired evidence is checked against its original context, not current administrative controls.
function synth_relationship_interaction_state_cte(): string
{
    return ", interaction_result_base AS MATERIALIZED (
        SELECT result.*,i.speaker_identity_id,i.listener_identity_id,i.response_history_event_id,i.input_payload,i.input_sha256,
            h.playthrough_id,h.session_id,h.generation,h.context_sequence,h.request_id,h.event_payload
        FROM synth_meta.relationship_interaction_results result
        JOIN synth_meta.relationship_interaction_evaluations i ON i.id=result.interaction_id
        JOIN synth_meta.history_events h ON h.id=result.history_event_id AND h.session_id=i.session_id AND h.request_id=i.request_id
            AND h.generation=i.generation AND h.context_sequence=i.context_sequence
        JOIN relationship_graph_scopes scope ON scope.session_id=h.session_id AND scope.generation=h.generation
            AND scope.playthrough_id=h.playthrough_id AND scope.reader_identity_id=i.speaker_identity_id AND h.id<=scope.cutoff
        WHERE h.event_payload->>'type'='relationship_interaction_result' AND h.event_payload->>'delivery_state'='derived'
            AND h.event_payload->>'evaluation_sha256'=i.input_sha256
            AND i.input_sha256=encode(sha256(convert_to(i.input_payload::text,'UTF8')),'hex')
            AND h.event_payload->>'result_sha256'=encode(sha256(convert_to(result.result_payload::text,'UTF8')),'hex')
    ) ".synth_relationship_pair_evidence_cte().", interaction_result_origins AS MATERIALIZED (
        SELECT i.* FROM interaction_result_execution i
        JOIN synth.actor_identities listener ON listener.id=i.listener_identity_id AND listener.game='fo4'
            AND listener.playthrough_id=i.playthrough_id AND listener.id<>i.player_identity_id AND listener.id<>i.speaker_identity_id
        CROSS JOIN LATERAL (
            SELECT jsonb_agg(jsonb_build_object('entry_index',entry.ordinality-1,'entry',entry.value) ORDER BY entry.ordinality) AS lines
            FROM jsonb_array_elements(CASE WHEN jsonb_typeof(i.pair_response_payload->'entries')='array'
                THEN i.pair_response_payload->'entries' ELSE '[]'::jsonb END) WITH ORDINALITY entry(value,ordinality)
            WHERE entry.value#>>'{routing,availability}'='addressed'
                AND upper(entry.value#>>'{speaker,form_id}')=upper(i.effective_speaker_identity->>'form_id')
                AND lower(entry.value#>>'{speaker,origin_plugin}')=lower(i.effective_speaker_identity->>'origin_plugin')
                AND entry.value#>>'{speaker,playthrough_id}'=i.playthrough_id
                AND upper(entry.value#>>'{routing,listener,form_id}')=upper(listener.full_form_id)
                AND lower(entry.value#>>'{routing,listener,origin_plugin}')=lower(listener.origin_plugin)
                AND entry.value#>>'{routing,listener,playthrough_id}'=i.playthrough_id
        ) grouped
        WHERE i.input_payload->>'schema'='synth.relationship.interaction.input.v1'
            AND i.input_payload->>'evaluation_policy'='synth.relationship.policy.v2'
            AND i.input_payload->'response'=i.pair_response_payload
            AND i.pair_response_payload->>'schema'='synth.relationship.response.v3'
            AND i.pair_response_payload->>'evaluation_mode'='dedicated'
            AND i.input_payload#>'{interaction,actor_ids}'=jsonb_build_object('speaker',i.speaker_identity_id,'listener',i.listener_identity_id)
            AND i.input_payload#>'{interaction,response_id}'=to_jsonb(i.response_history_event_id)
            AND i.input_payload#>'{interaction,participants,speaker}'=i.effective_speaker_identity
            AND (i.target_identity_id=i.listener_identity_id OR i.audience_identity_ids @> jsonb_build_array(i.listener_identity_id))
            AND EXISTS(SELECT 1 FROM jsonb_array_elements(jsonb_build_array(i.original_context->'target')
                ||COALESCE(i.original_context->'audience','[]'::jsonb)) actor
                WHERE actor=i.input_payload#>'{interaction,participants,listener}' AND actor->>'playthrough_id'=i.playthrough_id
                    AND upper(actor->>'form_id')=upper(listener.full_form_id) AND lower(actor->>'origin_plugin')=lower(listener.origin_plugin))
            AND grouped.lines=i.input_payload->'lines'
            AND grouped.lines @> jsonb_build_array(jsonb_build_object('entry_index',i.input_payload#>'{interaction,entry_index}'))
            AND jsonb_typeof(i.input_payload#>'{interaction,relationships}')='object'
            AND i.input_payload#>'{interaction,relationships}' ?& ARRAY['speaker','listener']
            AND (CASE WHEN jsonb_typeof(i.input_payload#>'{interaction,relationships}')='object'
                THEN i.input_payload#>'{interaction,relationships}' ELSE '{}'::jsonb END)-'speaker'-'listener'='{}'::jsonb
            AND jsonb_typeof(i.input_payload#>'{writable,speaker}')='boolean' AND jsonb_typeof(i.input_payload#>'{writable,listener}')='boolean'
            AND i.result_payload->>'schema'='synth.relationship.interaction.result.v1'
            AND i.result_payload-'schema'-'changes'='{}'::jsonb
            AND jsonb_typeof(i.result_payload->'changes')='array'
            AND jsonb_array_length(CASE WHEN jsonb_typeof(i.result_payload->'changes')='array' THEN i.result_payload->'changes' ELSE '[]'::jsonb END)<=2
            AND (SELECT count(DISTINCT change->>'role') FROM jsonb_array_elements(CASE WHEN jsonb_typeof(i.result_payload->'changes')='array'
                THEN i.result_payload->'changes' ELSE '[]'::jsonb END) change)=jsonb_array_length(CASE WHEN jsonb_typeof(i.result_payload->'changes')='array'
                    THEN i.result_payload->'changes' ELSE '[]'::jsonb END)
            AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(i.result_payload->'changes')='array'
                THEN i.result_payload->'changes' ELSE '[]'::jsonb END) change
                WHERE change->>'role' NOT IN ('speaker','listener') OR i.input_payload#>>ARRAY['writable',change->>'role'] IS DISTINCT FROM 'true')
    ), interaction_result_prior_candidates AS MATERIALIZED (
        SELECT i.* FROM interaction_result_origins i
        WHERE (SELECT count(*) FROM synth_meta.relationship_interaction_priors d WHERE d.interaction_id=i.interaction_id)
                =(SELECT count(*) FROM jsonb_each(i.input_payload#>'{interaction,relationships}') prior WHERE prior.value<>'null'::jsonb)
            AND (SELECT count(*) FROM synth_meta.relationship_interaction_result_priors d WHERE d.result_history_event_id=i.history_event_id)
                =(SELECT count(*) FROM jsonb_each(i.input_payload#>'{interaction,relationships}') prior WHERE prior.value<>'null'::jsonb)
            AND NOT EXISTS(SELECT 1 FROM jsonb_each(i.input_payload#>'{interaction,relationships}') prior WHERE prior.value<>'null'::jsonb AND NOT EXISTS(
                SELECT 1 FROM synth_meta.relationship_interaction_priors frozen
                JOIN synth_meta.relationship_interaction_result_priors edge ON edge.result_history_event_id=i.history_event_id
                    AND edge.actor_identity_id=frozen.actor_identity_id AND edge.prior_history_event_id=frozen.prior_history_event_id
                JOIN synth_meta.relationship_revisions p ON p.history_event_id=edge.prior_history_event_id AND p.actor_identity_id=edge.actor_identity_id
                WHERE frozen.interaction_id=i.interaction_id AND to_jsonb(p.actor_identity_id)=i.input_payload#>ARRAY['interaction','actor_ids',prior.key]
                    AND p.subject_identity_id=CASE WHEN prior.key='speaker' THEN i.listener_identity_id ELSE i.speaker_identity_id END
                    AND to_jsonb(p.history_event_id)=prior.value->'revision' AND COALESCE(to_jsonb(p.affinity),'null'::jsonb)=prior.value->'aff'
                    AND p.relationship_type IS NOT DISTINCT FROM prior.value->>'type' AND p.details=prior.value-'revision'-'aff'-'type'))
    ), interaction_revision_candidates AS MATERIALIZED (
        SELECT r.*,h.playthrough_id FROM synth_meta.relationship_revisions r
        JOIN interaction_result_prior_candidates i ON i.history_event_id=r.interaction_result_history_event_id
        JOIN synth_meta.history_events h ON h.id=r.history_event_id AND h.session_id=i.session_id AND h.request_id=i.request_id
            AND h.generation=i.generation AND h.context_sequence=i.context_sequence AND h.playthrough_id=i.playthrough_id AND h.id>i.history_event_id
        JOIN relationship_graph_scopes scope ON scope.session_id=h.session_id AND scope.generation=h.generation
            AND scope.playthrough_id=h.playthrough_id AND scope.reader_identity_id=r.actor_identity_id AND h.id<=scope.cutoff
        CROSS JOIN LATERAL jsonb_array_elements(i.result_payload->'changes') change
        WHERE r.result_history_event_id IS NULL AND h.event_payload->>'type'='relationship_state' AND h.event_payload->>'delivery_state'='derived'
            AND r.affinity IS NOT NULL AND r.relationship_type IS NOT NULL
            AND change->>'role' IN ('speaker','listener')
            AND r.actor_identity_id=CASE WHEN change->>'role'='speaker' THEN i.speaker_identity_id ELSE i.listener_identity_id END
            AND r.subject_identity_id=CASE WHEN change->>'role'='speaker' THEN i.listener_identity_id ELSE i.speaker_identity_id END
            AND change=jsonb_build_object('role',change->>'role','actor_id',r.actor_identity_id,'subject_id',r.subject_identity_id,
                'prior_revision',r.prior_history_event_id,'state',jsonb_build_object('aff',r.affinity,'type',r.relationship_type)||r.details)
            AND COALESCE(to_jsonb(r.prior_history_event_id),'null'::jsonb)=COALESCE(i.input_payload#>ARRAY['interaction','relationships',change->>'role','revision'],'null'::jsonb)
            AND r.details=h.event_payload->'relationship_details' AND to_jsonb(i.history_event_id)=h.event_payload->'interaction_result'
            AND r.input_payload=jsonb_build_object('schema','synth.relationship.interaction.revision.v1','interaction_result',i.history_event_id,
                'role',change->>'role','prior',r.prior_history_event_id,'state',change->'state','sources',jsonb_build_array(i.response_history_event_id))
            AND r.input_hash=encode(sha256(convert_to(r.input_payload::text,'UTF8')),'hex') AND r.input_hash=h.event_payload->>'relationship_input_sha256'
            AND (SELECT count(*) FROM synth_meta.relationship_revision_sources d WHERE d.relationship_history_event_id=r.history_event_id)=1
            AND EXISTS(SELECT 1 FROM synth_meta.relationship_revision_sources d WHERE d.relationship_history_event_id=r.history_event_id AND d.source_history_event_id=i.response_history_event_id)
    ), interaction_result_candidates AS MATERIALIZED (
        SELECT i.* FROM interaction_result_prior_candidates i
        WHERE (SELECT count(*) FROM synth_meta.relationship_revisions r WHERE r.interaction_result_history_event_id=i.history_event_id)=jsonb_array_length(i.result_payload->'changes')
            AND (SELECT count(*) FROM interaction_revision_candidates r WHERE r.interaction_result_history_event_id=i.history_event_id)=jsonb_array_length(i.result_payload->'changes')
            AND (SELECT count(DISTINCT r.actor_identity_id) FROM interaction_revision_candidates r WHERE r.interaction_result_history_event_id=i.history_event_id)=jsonb_array_length(i.result_payload->'changes')
    ) ";
}
