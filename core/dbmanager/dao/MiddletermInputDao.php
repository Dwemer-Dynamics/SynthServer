<?php

// Reads core_middleterm_input. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMiddletermInput;

/** Finds rows of core_middleterm_input. */
final class MiddletermInputDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractMiddletermInput
    {
        return $this->fresh()->find(AbstractMiddletermInput::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMiddletermInput> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMiddletermInput::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }

    /** Takes the transaction-scoped advisory lock that serializes narrative admission, claim and publication for one actor. False when the statement fails. */
    public function lockActor(int|string $actorId): bool
    {
        try {
            $this->connection()->fetchAssociative("SELECT pg_advisory_xact_lock(hashtextextended('synth-middleterm:'||CAST(? AS text),0))", [(string) $actorId]);
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /** One raw row of core_middleterm_input, or null when missing (or the statement fails). @return array<string, mixed>|null */
    public function findRow(int $id): ?array
    {
        try {
            $row = $this->connection()->fetchAssociative('SELECT * FROM public.core_middleterm_input WHERE id = ?', [$id]);
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }

        return $row === false ? null : $row;
    }

    /** True when the stored input payload still hashes to its stored digest (false when missing or the statement fails). */
    public function digestMatches(int $id): bool
    {
        try {
            return $this->connection()->fetchOne(
                "SELECT 1 FROM public.core_middleterm_input WHERE id = ?
                 AND input_sha256=encode(sha256(convert_to(input_payload::text,'UTF8')),'hex')",
                [$id]
            ) !== false;
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }
    }

    /**
     * Freezes one narrative input (replaying the same input of the same request returns the same id) and returns its id; null when
     * no row came back. Throws when the statement fails.
     */
    public function freeze(string $sessionId, string $requestId, int $generation, int $sequence, int $actorId, string $playthroughId, ?int $priorHistoryEventId, string $inputJson, ?int $approvalId): ?int
    {
        $id = $this->connection()->fetchOne(
            "INSERT INTO public.core_middleterm_input(session_id,request_id,generation,context_sequence,
                actor_identity_id,playthrough_id,prior_history_event_id,input_payload,input_sha256,regeneration_approval_id)
             VALUES(:session,:request,:generation,:sequence,:actor,:playthrough,:prior,CAST(:input AS jsonb),
                encode(sha256(convert_to((CAST(:input AS jsonb))::text,'UTF8')),'hex'),:approval)
             ON CONFLICT(session_id,request_id,generation,actor_identity_id,input_sha256) DO UPDATE SET input_sha256=EXCLUDED.input_sha256 RETURNING id",
            ['session' => $sessionId, 'request' => $requestId, 'generation' => $generation, 'sequence' => $sequence, 'actor' => $actorId,
                'playthrough' => $playthroughId, 'prior' => $priorHistoryEventId, 'input' => $inputJson, 'approval' => $approvalId]
        );

        return $id === false ? null : (int) $id;
    }

    /** The SHA-256 hex digest of a plan's JSON as PostgreSQL normalizes it (jsonb text), or null when the statement fails. */
    public function planDigest(string $planJson): ?string
    {
        try {
            $digest = $this->connection()->fetchOne(
                "SELECT encode(sha256(convert_to((CAST(? AS jsonb))::text,'UTF8')),'hex') AS digest",
                [$planJson]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }

        return $digest === false ? null : (string) $digest;
    }

    /**
     * The target narrative of a regeneration plan with its control state, replacement fences and resolved prior, read through
     * the caller's middleterm history CTE ($cte ends with a space). Null when not visible (or the statement fails).
     *
     * @return array<string, mixed>|null
     */
    public function regenerationTarget(string $cte, int $narrativeId): ?array
    {
        try {
            $row = $this->connection()->fetchAssociative(
                $cte." SELECT i.history_event_id,i.input_sha256,i.control_revision,i.content_revision,
                    i.archived,i.control_unavailable,i.prior_history_event_id,i.input_payload,
                    EXISTS(SELECT 1 FROM middleterm_archived_lineage blocked WHERE blocked.history_event_id=i.history_event_id) AS suppressed,
                    EXISTS(SELECT 1 FROM middleterm_replacement_fences replaced WHERE replaced.target_history_event_id=i.history_event_id) AS replaced,
                    p.history_event_id AS prior_id,p.effective_narrative AS prior_narrative,p.content_revision AS prior_content_revision
                    FROM middleterm_effective i LEFT JOIN middleterm_resolved_replacements resolved ON resolved.original_id=i.prior_history_event_id
                    LEFT JOIN native_middleterms p ON p.history_event_id=resolved.current_id
                    WHERE i.history_event_id = ?",
                [$narrativeId]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }

        return $row === false ? null : $row;
    }
}
