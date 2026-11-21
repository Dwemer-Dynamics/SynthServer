<?php

// Reads core_relationship_revision. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRelationshipRevision;

/** Finds rows of core_relationship_revision. */
final class RelationshipRevisionDao
{
    use UsesEntityManager;
    use TextRows;

    /** One row by its primary key, or null. */
    public function find(int|string $historyEventId): ?AbstractRelationshipRevision
    {
        return $this->fresh()->find(AbstractRelationshipRevision::class, $historyEventId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRelationshipRevision> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRelationshipRevision::class)->findBy([], ['historyEventId' => 'ASC'], max(1, $limit)));
    }

    /**
     * The revision this actor already published about this subject for this request, if any.
     *
     * @return array{history_event_id: string, input_hash: string, context_sequence: string}|null
     */
    public function replayFor(string $sessionId, int $generation, string $requestId, int $actorId, int $subjectId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT r.history_event_id,r.input_hash,h.context_sequence FROM public.core_relationship_revision r
            JOIN public.core_history_event h ON h.id=r.history_event_id
            WHERE h.session_id=:p1 AND h.generation=:p2 AND h.request_id=:p3
                AND r.actor_identity_id=:p4 AND r.subject_identity_id=:p5',
            ['p1' => $sessionId, 'p2' => $generation, 'p3' => $requestId, 'p4' => $actorId, 'p5' => $subjectId]
        );

        return self::textRow($row);
    }

    /** The newest revision of this pair published after the context snapshot (as text), or null when none: such a head makes a proposal stale. */
    public function headAfterContext(string $sessionId, int $generation, int $actorId, int $subjectId, int $contextSequence): ?string
    {
        $id = $this->connection()->fetchOne(
            'SELECT max(r.history_event_id) AS id FROM public.core_relationship_revision r
            JOIN public.core_history_event h ON h.id=r.history_event_id WHERE h.session_id=:p1 AND h.generation=:p2
                AND r.actor_identity_id=:p3 AND r.subject_identity_id=:p4 AND h.id>(
                    SELECT history_event_id FROM public.core_runtime_context WHERE session_id=:p1 AND generation=:p2 AND snapshot_sequence=:p5)',
            ['p1' => $sessionId, 'p2' => $generation, 'p3' => $actorId, 'p4' => $subjectId, 'p5' => $contextSequence]
        );

        return $id === false || $id === null ? null : (string) $id;
    }

    /** True when this pair already has a revision published after the context snapshot (an interaction perspective is then stale). */
    public function existsAfterContext(string $sessionId, int $generation, int $actorId, int $subjectId, int $contextSequence): bool
    {
        return $this->connection()->fetchOne(
            'SELECT 1 FROM public.core_relationship_revision r JOIN public.core_history_event h ON h.id=r.history_event_id
                WHERE h.session_id=:p1 AND h.generation=:p2 AND r.actor_identity_id=:p3 AND r.subject_identity_id=:p4
                    AND h.id>(SELECT history_event_id FROM public.core_runtime_context WHERE session_id=:p1 AND generation=:p2 AND snapshot_sequence=:p5) LIMIT 1',
            ['p1' => $sessionId, 'p2' => $generation, 'p3' => $actorId, 'p4' => $subjectId, 'p5' => $contextSequence]
        ) !== false;
    }

    /** Publishes a native relationship revision with its derived history event. Returns the new history event id. */
    public function publish(string $playthroughId, int $gameTime, int $now, string $sessionId, int $generation, int $contextSequence,
        string $requestId, int $actorId, int $subjectId, ?int $priorId, int $affinity, string $type, string $inputPayload,
        string $inputHash, ?int $resultHistoryEventId, string $details): int
    {
        return (int) $this->connection()->fetchOne(
            "WITH publication AS (
            INSERT INTO public.core_context_event(type,data,sess,gamets,localts,ts,people,location,party,delivery_state,
                synth_session_id,synth_generation,synth_context_sequence,synth_request_id)
            VALUES('relationship_state','Native relationship state publication',:p1,:p2,:p3,:p3,'','','','derived',:p4,:p5,:p6,:p7) RETURNING *
        ), history AS (
            INSERT INTO public.core_history_event(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
            SELECT synth_session_id,synth_generation,synth_context_sequence,synth_request_id,:p1,rowid,to_jsonb(publication)
                ||jsonb_build_object('relationship_details',:p16::jsonb)
            FROM publication RETURNING id
        ) INSERT INTO public.core_relationship_revision(history_event_id,actor_identity_id,subject_identity_id,
            prior_history_event_id,affinity,relationship_type,input_payload,input_hash,result_history_event_id,details)
            SELECT id,:p8,:p9,:p10,:p11,:p12,:p13::jsonb,:p14,:p15,:p16::jsonb FROM history RETURNING history_event_id",
            ['p1' => $playthroughId, 'p2' => $gameTime, 'p3' => $now, 'p4' => $sessionId, 'p5' => $generation, 'p6' => $contextSequence,
                'p7' => $requestId, 'p8' => $actorId, 'p9' => $subjectId, 'p10' => $priorId, 'p11' => $affinity, 'p12' => $type,
                'p13' => $inputPayload, 'p14' => $inputHash, 'p15' => $resultHistoryEventId, 'p16' => $details]
        );
    }

    /** Publishes one NPC interaction perspective revision with its derived history event. Returns the new history event id. */
    public function publishInteractionPerspective(string $playthroughId, int $gameTime, int $now, string $sessionId, int $generation,
        int $contextSequence, string $requestId, int $actorId, int $subjectId, ?int $priorId, int $affinity, string $type,
        string $inputPayload, int $interactionResultId, string $details): int
    {
        return (int) $this->connection()->fetchOne(
            "WITH publication AS (
                INSERT INTO public.core_context_event(type,data,sess,gamets,localts,ts,people,location,party,delivery_state,
                    synth_session_id,synth_generation,synth_context_sequence,synth_request_id)
                VALUES('relationship_state','Native NPC interaction perspective',:p1,:p2,:p3,:p3,'','','','derived',:p4,:p5,:p6,:p7) RETURNING *
            ), history AS (
                INSERT INTO public.core_history_event(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
                SELECT synth_session_id,synth_generation,synth_context_sequence,synth_request_id,:p1,rowid,to_jsonb(publication)
                    ||jsonb_build_object('relationship_details',:p15::jsonb,'interaction_result',:p14::bigint,
                        'relationship_input_sha256',encode(sha256(convert_to((:p13::jsonb)::text,'UTF8')),'hex'))
                FROM publication RETURNING id
            ) INSERT INTO public.core_relationship_revision(history_event_id,actor_identity_id,subject_identity_id,prior_history_event_id,
                affinity,relationship_type,input_payload,input_hash,interaction_result_history_event_id,details)
                SELECT id,:p8,:p9,:p10,:p11,:p12,:p13::jsonb,encode(sha256(convert_to((:p13::jsonb)::text,'UTF8')),'hex'),:p14,:p15::jsonb
                FROM history RETURNING history_event_id",
            ['p1' => $playthroughId, 'p2' => $gameTime, 'p3' => $now, 'p4' => $sessionId, 'p5' => $generation, 'p6' => $contextSequence,
                'p7' => $requestId, 'p8' => $actorId, 'p9' => $subjectId, 'p10' => $priorId, 'p11' => $affinity, 'p12' => $type,
                'p13' => $inputPayload, 'p14' => $interactionResultId, 'p15' => $details]
        );
    }

    /** Serializes proposals for one actor/subject pair (the key is `synth-relationship:<actor>:<subject>`) until the caller's transaction ends. */
    public function lockPair(string $key): void
    {
        $this->connection()->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:p1,0))', ['p1' => $key]);
    }

    /**
     * The distinct relationship types in the reader's saved current state, as `['type' => name]` rows. $visibleCte is the reader's
     * visibility CTE chain ending in `native_relationships`; the statement binds no parameters.
     *
     * @return list<array{type: string}>
     */
    public function savedCurrentTypes(string $visibleCte): array
    {
        return self::textRows($this->connection()->fetchAllAssociative(
            $visibleCte.'SELECT DISTINCT relationship_type AS type FROM (SELECT DISTINCT ON(subject_identity_id) relationship_type
                FROM native_relationships ORDER BY subject_identity_id,history_event_id DESC) current_types WHERE relationship_type IS NOT NULL'
        ));
    }
}
