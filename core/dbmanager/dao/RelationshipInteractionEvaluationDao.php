<?php

// Reads core_relationship_interaction_evaluation. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRelationshipInteractionEvaluation;

/** Finds rows of core_relationship_interaction_evaluation. */
final class RelationshipInteractionEvaluationDao
{
    use UsesEntityManager;
    use TextRows;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractRelationshipInteractionEvaluation
    {
        return $this->fresh()->find(AbstractRelationshipInteractionEvaluation::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRelationshipInteractionEvaluation> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRelationshipInteractionEvaluation::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }

    /** The id of the evaluation already frozen for this response and listener in this request, or null. */
    public function replayId(string $sessionId, string $requestId, int $responseHistoryEventId, int $listenerId): ?int
    {
        $id = $this->connection()->fetchOne(
            'SELECT id FROM public.core_relationship_interaction_evaluation
            WHERE session_id=:p1 AND request_id=:p2 AND response_history_event_id=:p3 AND listener_identity_id=:p4',
            ['p1' => $sessionId, 'p2' => $requestId, 'p3' => $responseHistoryEventId, 'p4' => $listenerId]
        );

        return $id === false ? null : (int) $id;
    }

    /** Freezes one speaker/listener evaluation input. Returns the new interaction id. */
    public function freeze(int $speakerId, int $listenerId, int $responseHistoryEventId, string $sessionId, string $requestId,
        int $generation, int $contextSequence, string $inputPayload): int
    {
        return (int) $this->connection()->fetchOne(
            "INSERT INTO public.core_relationship_interaction_evaluation
            (speaker_identity_id,listener_identity_id,response_history_event_id,session_id,request_id,generation,context_sequence,input_payload,input_sha256)
            VALUES(:p1,:p2,:p3,:p4,:p5,:p6,:p7,:p8::jsonb,encode(sha256(convert_to((:p8::jsonb)::text,'UTF8')),'hex')) RETURNING id",
            ['p1' => $speakerId, 'p2' => $listenerId, 'p3' => $responseHistoryEventId, 'p4' => $sessionId, 'p5' => $requestId,
                'p6' => $generation, 'p7' => $contextSequence, 'p8' => $inputPayload]
        );
    }

    /** The evaluation row whose frozen input still hashes to its stored digest, or null. */
    public function verifiedInput(int $interactionId, string $sessionId, string $requestId, int $generation, int $contextSequence): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT * FROM public.core_relationship_interaction_evaluation
            WHERE id=:p1 AND session_id=:p2 AND request_id=:p3 AND generation=:p4 AND context_sequence=:p5
                AND input_sha256=encode(sha256(convert_to(input_payload::text,'UTF8')),'hex')",
            ['p1' => $interactionId, 'p2' => $sessionId, 'p3' => $requestId, 'p4' => $generation, 'p5' => $contextSequence]
        );

        return self::textRow($row);
    }

    /**
     * A frozen interaction of this session, generation and context sequence that has no job yet and whose owner request completed.
     *
     * @return array<string, string>|null
     */
    public function unqueuedFrozen(string $sessionId, int $generation, string $turnId, string $contextSequence): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT i.id,i.session_id,i.request_id,i.generation,i.context_sequence,
                    e.turn_id,e.event_type,p.canonical_event,p.event_sha256
                FROM public.core_relationship_interaction_evaluation i
                JOIN public.core_request_execution e ON e.session_id=i.session_id AND e.request_id=i.request_id
                    AND e.generation=i.generation AND e.turn_id=:p3 AND e.state='complete'
                JOIN public.core_protocol_event p ON p.session_id=e.session_id AND p.request_id=e.request_id
                    AND p.generation=e.generation AND p.turn_id=e.turn_id AND p.event_type=e.event_type AND p.event_sha256=e.request_sha256
                WHERE i.session_id=:p1 AND i.generation=:p2 AND i.context_sequence::text=:p4 AND i.input_payload->'owner'=p.canonical_event
                    AND NOT EXISTS(SELECT 1 FROM public.core_worker_job j WHERE j.relationship_interaction_id=i.id)
                ORDER BY i.id LIMIT 1",
            ['p1' => $sessionId, 'p2' => $generation, 'p3' => $turnId, 'p4' => $contextSequence]
        );

        return self::textRow($row);
    }
}
