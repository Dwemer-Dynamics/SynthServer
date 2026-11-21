<?php

// Reads core_relationship_evaluation. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRelationshipEvaluation;

/** Finds rows of core_relationship_evaluation. */
final class RelationshipEvaluationDao
{
    use UsesEntityManager;
    use TextRows;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractRelationshipEvaluation
    {
        return $this->fresh()->find(AbstractRelationshipEvaluation::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRelationshipEvaluation> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRelationshipEvaluation::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }

    /**
     * The evaluation already frozen for this response in this request, if any.
     *
     * @return array{id: string, input_payload: string}|null
     */
    public function replayFor(string $sessionId, string $requestId, int $responseHistoryEventId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT id,input_payload FROM public.core_relationship_evaluation
            WHERE session_id=:p1 AND request_id=:p2 AND response_history_event_id=:p3',
            ['p1' => $sessionId, 'p2' => $requestId, 'p3' => $responseHistoryEventId]
        );

        return self::textRow($row);
    }

    /** The evaluation row whose frozen input still hashes to its stored digest and whose owner request completed; null otherwise. */
    public function verifiedInput(int $evaluationId, string $sessionId, string $requestId, int $generation, int $contextSequence): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT i.* FROM public.core_relationship_evaluation i
        JOIN public.core_request_execution e ON e.session_id=i.session_id AND e.request_id=i.request_id AND e.generation=i.generation AND e.state='complete'
        JOIN public.core_protocol_event p ON p.session_id=e.session_id AND p.request_id=e.request_id AND p.event_sha256=e.request_sha256
            AND p.canonical_event=i.input_payload->'owner'
        WHERE i.id=:p1 AND i.session_id=:p2 AND i.request_id=:p3 AND i.generation=:p4 AND i.context_sequence=:p5
            AND i.input_sha256=encode(sha256(convert_to(i.input_payload::text,'UTF8')),'hex')",
            ['p1' => $evaluationId, 'p2' => $sessionId, 'p3' => $requestId, 'p4' => $generation, 'p5' => $contextSequence]
        );

        return self::textRow($row);
    }

    /** True when this actor already has an evaluation of the gift with this action id. */
    public function giftEvaluated(int $actorId, string $actionId): bool
    {
        return $this->connection()->fetchOne(
            "SELECT id FROM public.core_relationship_evaluation WHERE actor_identity_id=:p1
                AND input_payload#>>'{received_gift,fact,action_id}'=:p2 LIMIT 1",
            ['p1' => $actorId, 'p2' => $actionId]
        ) !== false;
    }

    /** Freezes one evaluation input. Returns the new evaluation id. */
    public function freeze(int|string $actorId, int $responseHistoryEventId, string $sessionId, string $requestId, int $generation,
        int $contextSequence, string $inputPayload): int
    {
        return (int) $this->connection()->fetchOne(
            "INSERT INTO public.core_relationship_evaluation
            (actor_identity_id,response_history_event_id,session_id,request_id,generation,context_sequence,input_payload,input_sha256)
            VALUES(:p1,:p2,:p3,:p4,:p5,:p6,:p7::jsonb,encode(sha256(convert_to((:p7::jsonb)::text,'UTF8')),'hex')) RETURNING id",
            ['p1' => $actorId, 'p2' => $responseHistoryEventId, 'p3' => $sessionId, 'p4' => $requestId, 'p5' => $generation,
                'p6' => $contextSequence, 'p7' => $inputPayload]
        );
    }

    /**
     * A frozen player-lane or full evaluation of this session, generation and context sequence that has no job yet and whose owner
     * request completed; null when none is waiting.
     *
     * @return array<string, string>|null
     */
    public function unqueuedFrozen(string $sessionId, int $generation, string $turnId, string $contextSequence, bool $playerOnly): ?array
    {
        $frozenScope = $playerOnly ? "AND i.input_payload->>'schema'='synth.relationship.evaluation.v2'" : '';
        $row = $this->connection()->fetchAssociative(
            "SELECT i.id,i.session_id,i.request_id,i.generation,i.context_sequence,
                    e.turn_id,e.event_type,p.canonical_event,p.event_sha256
                FROM public.core_relationship_evaluation i
                JOIN public.core_request_execution e ON e.session_id=i.session_id AND e.request_id=i.request_id
                    AND e.generation=i.generation AND e.turn_id=:p3 AND e.state='complete'
                JOIN public.core_protocol_event p ON p.session_id=e.session_id AND p.request_id=e.request_id AND p.event_sha256=e.request_sha256
                WHERE i.session_id=:p1 AND i.generation=:p2 AND i.context_sequence::text=:p4 $frozenScope
                    AND i.input_payload->'owner'=p.canonical_event
                    AND NOT EXISTS(SELECT 1 FROM public.core_worker_job j WHERE j.relationship_evaluation_id=i.id)
                ORDER BY i.id LIMIT 1",
            ['p1' => $sessionId, 'p2' => $generation, 'p3' => $turnId, 'p4' => $contextSequence]
        );

        return self::textRow($row);
    }
}
