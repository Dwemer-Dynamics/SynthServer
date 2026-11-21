<?php

// Reads core_dialogue_origin_policy. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractDialogueOriginPolicy;

/** Finds rows of core_dialogue_origin_policy. */
final class DialogueOriginPolicyDao
{
    use UsesEntityManager;
    use FailSoftQueries;

    /** One row by its primary key, or null. */
    public function find(string $sessionId, string $requestId): ?AbstractDialogueOriginPolicy
    {
        return $this->fresh()->find(AbstractDialogueOriginPolicy::class, ['sessionId' => $sessionId, 'requestId' => $requestId]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractDialogueOriginPolicy> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractDialogueOriginPolicy::class)->findBy([], ['sessionId' => 'ASC', 'requestId' => 'ASC'], max(1, $limit)));
    }

    /**
     * The frozen policy of a completed origin request whose canonical event and hash match. Parameters, in order: session
     * id, request id, canonical event JSON, event sha256. [] when none matches, null when the read fails.
     *
     * @return array<string, mixed>|null
     */
    public function completedOriginPolicy(array $parameters): ?array
    {
        return $this->rowOrNull("SELECT d.policy FROM public.core_dialogue_origin_policy d
                    JOIN public.core_request_execution e USING(session_id,request_id)
                    JOIN public.core_protocol_event p USING(session_id,request_id)
                    WHERE d.session_id=:p1 AND d.request_id=:p2 AND e.state='complete'
                        AND p.canonical_event=:p3::jsonb AND p.event_sha256=:p4 AND e.request_sha256=p.event_sha256", $parameters);
    }

    /** The frozen input text and execution mode of one request. @return array<string, mixed>|null */
    public function inputPolicy(string $sessionId, string $requestId): ?array
    {
        return $this->rowOrNull('SELECT input_text,execution_mode FROM public.core_dialogue_origin_policy WHERE session_id=:p1 AND request_id=:p2', [$sessionId, $requestId]);
    }

    /** Execution mode and audience intent of a root request in one exact turn and context. @return array<string, mixed>|null */
    public function rootMode(string $sessionId, string $requestId, int $generation, string $turnId, int $contextSequence): ?array
    {
        return $this->rowOrNull("SELECT d.execution_mode,p.canonical_event#>>'{payload,audience_intent}' AS audience_intent
        FROM public.core_dialogue_origin_policy d
        JOIN public.core_protocol_event p USING(session_id,request_id)
        JOIN public.core_request_execution e USING(session_id,request_id)
        WHERE d.session_id=:p1 AND d.request_id=:p2 AND p.generation=:p3 AND p.turn_id=:p4
            AND p.canonical_event->>'context_sequence'=:p5::text AND e.generation=p.generation AND e.turn_id=p.turn_id
            AND e.event_type=p.event_type AND e.request_sha256=p.event_sha256",
            [$sessionId, $requestId, $generation, $turnId, $contextSequence]);
    }
}
