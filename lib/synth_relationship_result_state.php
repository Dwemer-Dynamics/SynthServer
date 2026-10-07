<?php

require_once __DIR__.'/synth_relationship_interaction_state.php';
require_once __DIR__.'/synth_relationship_manual_state.php';

// Results and revisions share an acyclic dependency graph; an invalid input invalidates every descendant.
function synth_relationship_result_state_cte(): string
{
    return ", relationship_result_origins AS MATERIALIZED (
        SELECT result.*,i.actor_identity_id,h.playthrough_id,i.response_history_event_id,i.input_payload,i.input_sha256,
            h.session_id,h.generation,h.context_sequence,h.request_id,h.event_payload,j.turn_id
        FROM synth_meta.relationship_results result JOIN synth_meta.relationship_evaluations i ON i.id=result.evaluation_id
        JOIN synth_meta.history_events h ON h.id=result.history_event_id AND h.session_id=i.session_id
            AND h.generation=i.generation AND h.context_sequence=i.context_sequence AND h.request_id=i.request_id
        JOIN relationship_graph_scopes scope ON scope.session_id=h.session_id AND scope.generation=h.generation
            AND scope.playthrough_id=h.playthrough_id AND scope.reader_identity_id=i.actor_identity_id AND h.id<=scope.cutoff
        JOIN synth_meta.worker_jobs j ON j.id=result.job_id AND j.relationship_evaluation_id=i.id AND j.state='ready'
            AND j.job_type='relationship' AND j.session_id=i.session_id AND j.request_id=i.request_id AND j.generation=i.generation
            AND j.payload->>'context_sequence'=i.context_sequence::text
        WHERE h.event_payload->>'type'='relationship_result' AND h.event_payload->>'delivery_state'='derived'
            AND i.input_sha256=h.event_payload->>'evaluation_sha256'
            AND i.input_sha256=encode(sha256(convert_to(i.input_payload::text,'UTF8')),'hex')
            AND encode(sha256(convert_to(result.result_payload::text,'UTF8')),'hex')=h.event_payload->>'result_sha256'
    ), relationship_result_candidates AS MATERIALIZED (
        SELECT i.* FROM relationship_result_origins i
        JOIN synth_meta.context_snapshots c ON c.session_id=i.session_id AND c.generation=i.generation
            AND c.snapshot_sequence=i.context_sequence AND c.target_identity_id=i.actor_identity_id AND c.playthrough_id=i.playthrough_id
        JOIN synth_meta.request_executions owner ON owner.session_id=i.session_id AND owner.request_id=i.request_id
            AND owner.generation=i.generation AND owner.turn_id=i.turn_id AND owner.state='complete'
        JOIN synth_meta.protocol_events protocol ON protocol.session_id=i.session_id AND protocol.request_id=i.request_id
            AND protocol.event_sha256=owner.request_sha256 AND protocol.canonical_event=i.input_payload->'owner'
        JOIN relationship_graph_responses response ON response.history_event_id=i.response_history_event_id AND response.actor_identity_id=i.actor_identity_id
        WHERE i.input_payload->'context'=c.context_payload AND i.input_payload->'response'=response.response_payload
            AND ((i.input_payload->>'schema'='synth.relationship.evaluation.v1' AND NOT i.input_payload ? 'player_lines')
                OR (i.input_payload->>'schema'='synth.relationship.evaluation.v2'
                    AND i.input_payload->>'evaluation_policy'='synth.relationship.policy.v2'
                    AND i.input_payload->'recent_events'='[]'::jsonb
                    AND i.input_payload->'source_ids'=jsonb_build_array(i.response_history_event_id)
                    AND jsonb_array_length(CASE WHEN jsonb_typeof(i.input_payload->'subjects')='array' THEN i.input_payload->'subjects' ELSE '[]'::jsonb END)=1
                    AND i.input_payload#>'{subjects,0,subject_id}'=to_jsonb(c.player_identity_id)
                    AND i.input_payload#>>'{subjects,0,label}'='Player'
                    AND response.response_payload->>'schema'='synth.relationship.response.v3'
                    AND response.response_payload->>'evaluation_mode'='dedicated'
                    AND i.input_payload->'player_lines'<> '[]'::jsonb
                    AND i.input_payload->'player_lines'=(SELECT jsonb_agg(jsonb_build_object('index',entry.ordinality-1,'entry',entry.value) ORDER BY entry.ordinality)
                        FROM jsonb_array_elements(CASE WHEN jsonb_typeof(response.response_payload->'entries')='array'
                            THEN response.response_payload->'entries' ELSE '[]'::jsonb END) WITH ORDINALITY entry(value,ordinality)
                        WHERE entry.ordinality<=256 AND entry.value#>>'{routing,availability}'='addressed'
                            AND upper(entry.value#>>'{speaker,form_id}')=upper(c.context_payload#>>'{target,form_id}')
                            AND lower(entry.value#>>'{speaker,origin_plugin}')=lower(c.context_payload#>>'{target,origin_plugin}')
                            AND entry.value#>>'{speaker,playthrough_id}'=c.context_payload#>>'{target,playthrough_id}'
                            AND upper(entry.value#>>'{routing,listener,form_id}')=upper(c.context_payload#>>'{player,form_id}')
                            AND lower(entry.value#>>'{routing,listener,origin_plugin}')=lower(c.context_payload#>>'{player,origin_plugin}')
                            AND entry.value#>>'{routing,listener,playthrough_id}'=c.context_payload#>>'{player,playthrough_id}')
                    AND i.result_payload->>'schema'='synth.relationship.result.v2' AND jsonb_typeof(i.result_payload->'changes')='array'
                    AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(i.result_payload->'changes')='array'
                        THEN i.result_payload->'changes' ELSE '[]'::jsonb END) change
                        WHERE change->'subject_id' IS DISTINCT FROM to_jsonb(c.player_identity_id) OR change->>'label' IS DISTINCT FROM 'Player')
                    AND NOT EXISTS(SELECT 1 FROM synth_meta.relationship_revisions revision WHERE revision.result_history_event_id=i.history_event_id
                        AND (revision.actor_identity_id<>i.actor_identity_id OR revision.subject_identity_id<>c.player_identity_id))))
            AND jsonb_typeof(i.input_payload->'source_ids')='array' AND jsonb_typeof(i.input_payload->'subjects')='array'
            AND (SELECT count(*) FROM synth_meta.relationship_evaluation_sources d WHERE d.evaluation_id=i.evaluation_id)
                =jsonb_array_length(CASE WHEN jsonb_typeof(i.input_payload->'source_ids')='array' THEN i.input_payload->'source_ids' ELSE '[]'::jsonb END)
            AND NOT EXISTS(SELECT 1 FROM synth_meta.relationship_evaluation_sources d WHERE d.evaluation_id=i.evaluation_id AND (
                NOT (i.input_payload->'source_ids' @> jsonb_build_array(d.history_event_id)) OR NOT EXISTS(
                    SELECT 1 FROM relationship_graph_sources s WHERE s.history_event_id=d.history_event_id AND s.actor_identity_id=i.actor_identity_id)))
            AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(i.input_payload->'recent_events')='array'
                THEN i.input_payload->'recent_events' ELSE '[]'::jsonb END) item WHERE NOT EXISTS(
                    SELECT 1 FROM relationship_graph_history raw WHERE to_jsonb(raw.history_event_id)=item->'history_event_id' AND raw.reader_identity_id=i.actor_identity_id
                        AND raw.type=item->>'type' AND raw.data=item->>'data'))
            AND (SELECT count(*) FROM synth_meta.relationship_result_priors d WHERE d.result_history_event_id=i.history_event_id)
                =(SELECT count(*) FROM jsonb_array_elements(CASE WHEN jsonb_typeof(i.input_payload->'subjects')='array'
                    THEN i.input_payload->'subjects' ELSE '[]'::jsonb END) subject WHERE subject->'relationship' IS DISTINCT FROM 'null'::jsonb)
            AND NOT EXISTS(SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(i.input_payload->'subjects')='array'
                THEN i.input_payload->'subjects' ELSE '[]'::jsonb END) subject WHERE subject->'relationship' IS DISTINCT FROM 'null'::jsonb
                    AND NOT EXISTS(SELECT 1 FROM synth_meta.relationship_result_priors d JOIN synth_meta.relationship_revisions prior
                        ON prior.history_event_id=d.prior_history_event_id
                        WHERE d.result_history_event_id=i.history_event_id AND to_jsonb(prior.subject_identity_id)=subject->'subject_id'
                            AND to_jsonb(prior.history_event_id)=subject->'relationship'->'revision' AND prior.actor_identity_id=i.actor_identity_id
                            AND COALESCE(to_jsonb(prior.affinity),'null'::jsonb)=subject->'relationship'->'aff'
                            AND prior.relationship_type IS NOT DISTINCT FROM subject->'relationship'->>'type'
                            AND prior.details=(subject->'relationship')-'revision'-'aff'-'type'))
    ) ".synth_relationship_interaction_state_cte().synth_relationship_manual_state_cte().", all_relationship_candidates AS MATERIALIZED (
        SELECT * FROM relationship_candidates UNION ALL SELECT * FROM interaction_revision_candidates
        UNION ALL SELECT * FROM manual_relationship_candidates
    ), relationship_nodes AS MATERIALIZED (
        SELECT history_event_id,actor_identity_id,subject_identity_id,playthrough_id FROM all_relationship_candidates
        UNION ALL SELECT history_event_id,actor_identity_id,NULL::bigint,playthrough_id FROM relationship_result_candidates
        UNION ALL SELECT history_event_id,speaker_identity_id,NULL::bigint,playthrough_id FROM interaction_result_candidates
    ), relationship_edges AS MATERIALIZED (
        SELECT history_event_id AS child,prior_history_event_id AS parent,true AS same_pair,actor_identity_id AS parent_actor
            FROM all_relationship_candidates WHERE prior_history_event_id IS NOT NULL
        UNION ALL SELECT history_event_id,result_history_event_id,false,actor_identity_id FROM relationship_candidates WHERE result_history_event_id IS NOT NULL
        UNION ALL SELECT d.result_history_event_id,d.prior_history_event_id,false,r.actor_identity_id FROM synth_meta.relationship_result_priors d
            JOIN relationship_result_candidates r ON r.history_event_id=d.result_history_event_id
        UNION ALL SELECT r.history_event_id,r.interaction_result_history_event_id,false,i.speaker_identity_id
            FROM interaction_revision_candidates r JOIN synth_meta.relationship_interaction_results result ON result.history_event_id=r.interaction_result_history_event_id
            JOIN synth_meta.relationship_interaction_evaluations i ON i.id=result.interaction_id
        UNION ALL SELECT d.result_history_event_id,d.prior_history_event_id,false,d.actor_identity_id
            FROM synth_meta.relationship_interaction_result_priors d JOIN interaction_result_candidates r ON r.history_event_id=d.result_history_event_id
    ), relationship_invalid(history_event_id) AS (
        SELECT DISTINCT e.child FROM relationship_edges e JOIN relationship_nodes child ON child.history_event_id=e.child
        LEFT JOIN relationship_nodes parent ON parent.history_event_id=e.parent
        WHERE parent.history_event_id IS NULL OR parent.actor_identity_id<>e.parent_actor
            OR parent.playthrough_id<>child.playthrough_id OR e.parent>=e.child
            OR (e.same_pair AND parent.subject_identity_id IS DISTINCT FROM child.subject_identity_id)
        UNION
        SELECT e.child FROM relationship_invalid bad JOIN relationship_edges e ON e.parent=bad.history_event_id
    ), native_relationships AS (
        SELECT r.* FROM all_relationship_candidates r WHERE r.actor_identity_id IN (SELECT reader_identity_id FROM native_ancestry WHERE depth=1)
            AND NOT EXISTS(SELECT 1 FROM relationship_invalid bad WHERE bad.history_event_id=r.history_event_id)
            AND NOT EXISTS(SELECT 1 FROM manual_relationship_reset_barriers reset WHERE reset.actor_identity_id=r.actor_identity_id
                AND reset.subject_identity_id=r.subject_identity_id AND reset.history_event_id>r.history_event_id)
    ), native_relationship_results AS (
        SELECT r.* FROM relationship_result_candidates r WHERE r.actor_identity_id IN (SELECT reader_identity_id FROM native_ancestry WHERE depth=1)
            AND NOT EXISTS(SELECT 1 FROM relationship_invalid bad WHERE bad.history_event_id=r.history_event_id)
    ), native_relationship_interaction_results AS (
        SELECT r.* FROM interaction_result_candidates r WHERE EXISTS(SELECT 1 FROM native_ancestry n WHERE n.depth=1
            AND n.reader_identity_id IN (r.speaker_identity_id,r.listener_identity_id))
            AND NOT EXISTS(SELECT 1 FROM relationship_invalid bad WHERE bad.history_event_id=r.history_event_id)
    ) ";
}
