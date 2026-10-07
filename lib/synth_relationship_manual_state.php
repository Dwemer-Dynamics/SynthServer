<?php

// Full administrative replacements have their own provenance, not fabricated dialogue/provider dependencies.
function synth_relationship_manual_state_cte(): string
{
    return ", manual_relationship_candidates AS MATERIALIZED (
        SELECT r.*,h.playthrough_id FROM synth_meta.relationship_revisions r
        JOIN synth_meta.history_events h ON h.id=r.history_event_id
        JOIN relationship_graph_scopes scope ON scope.session_id=h.session_id AND scope.generation=h.generation
            AND scope.playthrough_id=h.playthrough_id AND scope.reader_identity_id=r.actor_identity_id AND h.id<=scope.cutoff
        JOIN synth_meta.context_snapshots c ON c.session_id=h.session_id AND c.generation=h.generation
            AND c.snapshot_sequence=h.context_sequence AND c.target_identity_id=r.actor_identity_id AND c.playthrough_id=h.playthrough_id
        JOIN synth.actor_identities actor ON actor.id=r.actor_identity_id AND actor.playthrough_id=c.playthrough_id AND actor.game='fo4'
            AND upper(actor.full_form_id)=upper(c.context_payload#>>'{target,form_id}')
            AND lower(actor.origin_plugin)=lower(c.context_payload#>>'{target,origin_plugin}')
            AND actor.playthrough_id=c.context_payload#>>'{target,playthrough_id}'
        JOIN synth.actor_identities subject ON subject.id=r.subject_identity_id AND subject.game='fo4' AND subject.playthrough_id=c.playthrough_id
        WHERE h.request_id IS NULL AND h.event_payload->>'type'='relationship_manual' AND h.event_payload->>'delivery_state'='derived'
            AND c.history_event_id<h.id AND r.prior_history_event_id IS NULL AND r.result_history_event_id IS NULL
            AND r.interaction_result_history_event_id IS NULL AND r.actor_identity_id<>r.subject_identity_id
            AND (subject.id=c.player_identity_id OR c.audience_identity_ids @> jsonb_build_array(subject.id))
            AND EXISTS(SELECT 1 FROM jsonb_array_elements(jsonb_build_array(c.context_payload->'player')
                ||COALESCE(c.context_payload->'audience','[]'::jsonb)) identity
                WHERE upper(identity->>'form_id')=upper(subject.full_form_id) AND lower(identity->>'origin_plugin')=lower(subject.origin_plugin)
                    AND identity->>'playthrough_id'=subject.playthrough_id)
            AND r.input_payload=jsonb_build_object('schema',CASE WHEN r.affinity IS NULL THEN 'synth.relationship.manual.v2' ELSE 'synth.relationship.manual.v1' END,'actor_id',r.actor_identity_id,
                'subject_id',r.subject_identity_id,'replaces',r.input_payload->'replaces','control_revision',r.input_payload->'control_revision',
                'state',CASE WHEN r.affinity IS NULL THEN 'null'::jsonb ELSE jsonb_build_object('aff',r.affinity,'type',r.relationship_type)||r.details END)
            AND (r.input_payload->'replaces'='null'::jsonb OR
                (jsonb_typeof(r.input_payload->'replaces')='number' AND r.input_payload->>'replaces' ~ '^[1-9][0-9]{0,17}$'))
            AND jsonb_typeof(r.input_payload->'control_revision')='number' AND r.input_payload->>'control_revision' ~ '^(0|[1-9][0-9]{0,17})$'
            AND h.event_payload->'manual_correction'=r.input_payload
            AND r.input_hash=encode(sha256(convert_to(r.input_payload::text,'UTF8')),'hex')
            AND h.event_payload->>'relationship_input_sha256'=r.input_hash
            AND NOT EXISTS(SELECT 1 FROM synth_meta.relationship_revision_sources d WHERE d.relationship_history_event_id=r.history_event_id)
    ), manual_relationship_reset_barriers AS MATERIALIZED (
        SELECT r.history_event_id,r.actor_identity_id,r.subject_identity_id FROM synth_meta.relationship_revisions r
        JOIN synth_meta.history_events h ON h.id=r.history_event_id
        JOIN relationship_graph_scopes scope ON scope.session_id=h.session_id AND scope.generation=h.generation
            AND scope.playthrough_id=h.playthrough_id AND scope.reader_identity_id=r.actor_identity_id AND h.id<=scope.cutoff
        JOIN synth_meta.context_snapshots origin ON origin.session_id=h.session_id AND origin.generation=h.generation
            AND origin.snapshot_sequence=h.context_sequence AND origin.target_identity_id=r.actor_identity_id AND origin.playthrough_id=h.playthrough_id
        WHERE h.request_id IS NULL AND h.event_payload->>'type'='relationship_manual'
            AND h.event_payload#>>'{manual_correction,schema}'='synth.relationship.manual.v2'
            AND h.event_payload#>'{manual_correction,state}'='null'::jsonb
            AND h.event_payload#>'{manual_correction,actor_id}'=to_jsonb(r.actor_identity_id)
            AND h.event_payload#>'{manual_correction,subject_id}'=to_jsonb(r.subject_identity_id)
    ) ";
}
