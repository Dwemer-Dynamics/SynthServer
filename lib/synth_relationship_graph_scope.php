<?php

// Expand only through saved paired publications. These private scopes validate dependencies, not raw-history access.
function synth_relationship_graph_scope_cte(): string
{
    return ", relationship_graph_readers(actor_identity_id) AS (
        SELECT reader_identity_id::bigint FROM native_ancestry WHERE depth=1
        UNION
        SELECT CASE WHEN i.speaker_identity_id=reader.actor_identity_id THEN i.listener_identity_id ELSE i.speaker_identity_id END
        FROM relationship_graph_readers reader JOIN synth_meta.relationship_interaction_evaluations i
            ON i.speaker_identity_id=reader.actor_identity_id OR i.listener_identity_id=reader.actor_identity_id
        JOIN synth_meta.relationship_interaction_results result ON result.interaction_id=i.id
        JOIN synth_meta.history_events h ON h.id=result.history_event_id
        JOIN native_history_scopes scope ON scope.session_id=h.session_id AND scope.generation=h.generation
            AND scope.playthrough_id=h.playthrough_id AND h.id<=scope.cutoff
    ), relationship_graph_scopes AS MATERIALIZED (
        SELECT scope.session_id,scope.generation,scope.playthrough_id,reader.actor_identity_id AS reader_identity_id,
            scope.reader_player_id,scope.cutoff
        FROM native_history_scopes scope CROSS JOIN relationship_graph_readers reader
        JOIN synth.actor_identities actor ON actor.id=reader.actor_identity_id AND actor.game='fo4'
        WHERE actor.playthrough_id=scope.playthrough_id
    ) ";
}
