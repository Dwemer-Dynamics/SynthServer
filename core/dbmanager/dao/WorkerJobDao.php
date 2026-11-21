<?php

// Reads core_worker_job. Writes stay with the statements that own them. The lease checks are plain reads (no lock).

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractWorkerJob;

/** Finds rows of core_worker_job. */
final class WorkerJobDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractWorkerJob
    {
        return $this->fresh()->find(AbstractWorkerJob::class, $id);
    }

    /** True while the memory job still runs under this lease for this exact request, generation and context sequence. */
    public function memoryLeaseActive(int $jobId, string $token, string $sessionId, string $requestId, int $generation, int $sequence): bool
    {
        return $this->connection()->fetchOne(
            "SELECT 1 FROM public.core_worker_job
             WHERE id = ? AND job_type = 'memory' AND session_id = ? AND request_id = ? AND generation = ?
               AND payload->>'context_sequence' = ? AND state = 'running' AND lease_token = ?
               AND lease_until > clock_timestamp() AND deadline_at > clock_timestamp()",
            [$jobId, $sessionId, $requestId, $generation, (string) $sequence, $token]
        ) !== false;
    }

    /** True while the relationship evaluation job still runs under this lease for this exact request and context sequence. */
    public function relationshipLeaseActive(int $jobId, string $token, string $sessionId, string $requestId, int $generation, int $sequence): bool
    {
        return $this->connection()->fetchOne(
            "SELECT 1 FROM public.core_worker_job WHERE id = ? AND job_type = 'relationship'
               AND relationship_evaluation_id IS NOT NULL AND session_id = ? AND request_id = ? AND generation = ?
               AND payload->>'context_sequence' = ? AND state = 'running' AND lease_token = ?
               AND lease_until > clock_timestamp() AND deadline_at > clock_timestamp()",
            [$jobId, $sessionId, $requestId, $generation, (string) $sequence, $token]
        ) !== false;
    }

    /** True while the profile job still runs under this lease for this exact request, turn and context sequence. */
    public function profileLeaseActive(int $jobId, string $token, string $sessionId, string $requestId, int $generation, string $turnId, int $sequence): bool
    {
        return $this->connection()->fetchOne(
            "SELECT 1 FROM public.core_worker_job WHERE id = ? AND job_type = 'profile'
               AND payload->>'schema' = 'synth.profile.job.v1' AND session_id = ? AND request_id = ?
               AND generation = ? AND turn_id = ? AND payload->>'context_sequence' = ?
               AND state = 'running' AND lease_token = ? AND lease_until > clock_timestamp() AND deadline_at > clock_timestamp()",
            [$jobId, $sessionId, $requestId, $generation, $turnId, (string) $sequence, $token]
        ) !== false;
    }

    /** True while the diary job still runs under this lease and its diary input matches the request, turn and context sequence. */
    public function diaryLeaseActive(int $jobId, string $token, string $sessionId, string $requestId, int $generation, string $turnId, int $sequence): bool
    {
        return $this->connection()->fetchOne(
            "SELECT 1 FROM public.core_worker_job j JOIN public.core_diary_input i ON i.id = j.diary_input_id
               AND i.session_id = j.session_id AND i.request_id = j.request_id AND i.generation = j.generation AND i.actor_identity_id = j.actor_identity_id
             WHERE j.id = ? AND j.job_type = 'diary' AND j.payload->>'schema' = 'synth.diary.job.v1'
               AND j.session_id = ? AND j.request_id = ? AND j.generation = ? AND j.turn_id = ?
               AND i.context_sequence = ? AND j.state = 'running' AND j.lease_token = ?
               AND j.lease_until > clock_timestamp() AND j.deadline_at > clock_timestamp()",
            [$jobId, $sessionId, $requestId, $generation, $turnId, $sequence, $token]
        ) !== false;
    }

    /** True while the NPC-to-NPC interaction job still runs under this lease and its evaluation matches the request, turn and context sequence. */
    public function interactionLeaseActive(int $jobId, string $token, string $sessionId, string $requestId, int $generation, string $turnId, int $sequence): bool
    {
        return $this->connection()->fetchOne(
            "SELECT 1 FROM public.core_worker_job j
             JOIN public.core_relationship_interaction_evaluation i ON i.id = j.relationship_interaction_id
               AND i.session_id = j.session_id AND i.request_id = j.request_id AND i.generation = j.generation
               AND i.speaker_identity_id = j.actor_identity_id AND i.context_sequence::text = j.payload->>'context_sequence'
             WHERE j.id = ? AND j.job_type = 'relationship' AND j.relationship_evaluation_id IS NULL AND j.memory_batch_id IS NULL
               AND j.session_id = ? AND j.request_id = ? AND j.generation = ? AND j.turn_id = ?
               AND j.payload->>'context_sequence' = ? AND j.state = 'running' AND j.lease_token = ?
               AND j.lease_until > clock_timestamp() AND j.deadline_at > clock_timestamp()",
            [$jobId, $sessionId, $requestId, $generation, $turnId, (string) $sequence, $token]
        ) !== false;
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractWorkerJob> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractWorkerJob::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }

    /** True while the speech job still runs under this lease for this exact request, turn and context sequence. */
    public function ttsLeaseActive(int $jobId, string $token, string $sessionId, string $requestId, int $generation, string $turnId, string $sequence): bool
    {
        return $this->connection()->fetchOne(
            "SELECT 1 FROM public.core_worker_job WHERE id = ? AND job_type = 'tts'
               AND payload->>'schema' = 'synth.tts.job.v1' AND state = 'running' AND lease_token = ?
               AND lease_until > clock_timestamp() AND deadline_at > clock_timestamp()
               AND session_id = ? AND request_id = ? AND generation = ? AND turn_id = ? AND payload->>'context_sequence' = ?",
            [$jobId, $token, $sessionId, $requestId, $generation, $turnId, $sequence]
        ) !== false;
    }

    /** Extends a live speech lease by five seconds (never past its deadline); true when exactly one row was renewed. */
    public function renewTtsLease(int $jobId, string $token): bool
    {
        return $this->connection()->executeStatement(
            "UPDATE public.core_worker_job SET lease_until = LEAST(deadline_at, clock_timestamp() + INTERVAL '5 seconds'), updated_at = clock_timestamp()
             WHERE id = ? AND state = 'running' AND lease_token = ? AND lease_until > clock_timestamp() AND deadline_at > clock_timestamp()",
            [$jobId, $token]
        ) === 1;
    }

    /**
     * A pending or running speech job with the canonical event of its request, when the request execution and the protocol event
     * still agree on session, request, generation, turn and hash; null otherwise.
     *
     * @return array<string, mixed>|null
     */
    public function ttsJobWithEvent(int $jobId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT j.*, p.canonical_event, p.event_sha256 FROM public.core_worker_job j
             JOIN public.core_request_execution e USING (session_id, request_id, generation, turn_id)
             JOIN public.core_protocol_event p ON p.session_id = e.session_id AND p.request_id = e.request_id
                AND p.generation = e.generation AND p.turn_id = e.turn_id AND p.event_sha256 = e.request_sha256
             WHERE j.id = ? AND j.job_type = 'tts' AND j.payload->>'schema' = 'synth.tts.job.v1' AND j.state IN ('pending', 'running')",
            [$jobId]
        );

        return $row === false ? null : $row;
    }

    /**
     * Records a failed speech attempt under its token: terminal (or out of attempts or past its deadline) becomes $terminalState,
     * otherwise the job goes back to pending after one second. True when the statement ran.
     */
    public function failTtsAttempt(int $jobId, string $token, bool $terminal, string $terminalState): bool
    {
        $this->connection()->executeStatement(
            "UPDATE public.core_worker_job SET state = CASE WHEN CAST(? AS boolean) OR attempts >= 3 OR deadline_at <= clock_timestamp() THEN ? ELSE 'pending' END,
                lease_token = NULL, lease_until = NULL, available_at = clock_timestamp() + INTERVAL '1 second',
                last_error = 'attempt_failed', updated_at = clock_timestamp()
             WHERE id = ? AND state = 'running' AND lease_token = ?",
            [$terminal ? 'true' : 'false', $terminalState, $jobId, $token]
        );

        return true;
    }

    /** Retires a speech job whose queued input or owner is invalid, unless a competing live attempt holds it. */
    public function retireInvalidTtsInput(int $jobId, string $terminalState): void
    {
        $this->connection()->executeStatement(
            "UPDATE public.core_worker_job SET state = ?, lease_token = NULL, lease_until = NULL, last_error = 'input_or_owner_invalid', updated_at = clock_timestamp()
             WHERE id = ? AND job_type = 'tts' AND payload->>'schema' = 'synth.tts.job.v1'
               AND (state = 'pending' OR (state = 'running' AND lease_until <= clock_timestamp()))",
            [$terminalState, $jobId]
        );
    }

    /** Fails or cancels speech jobs past their deadline or attempts, or whose request is no longer the active turn. */
    public function retireExpiredTtsWork(): void
    {
        $this->connection()->executeStatement(
            "UPDATE public.core_worker_job j SET state = CASE WHEN deadline_at <= clock_timestamp() OR attempts >= 3 THEN 'failed' ELSE 'cancelled' END,
                lease_token = NULL, lease_until = NULL, last_error = 'owner_or_attempt_expired', updated_at = clock_timestamp()
             WHERE job_type = 'tts' AND payload->>'schema' = 'synth.tts.job.v1' AND state IN ('pending', 'running')
               AND (deadline_at <= clock_timestamp() OR (attempts >= 3 AND (state = 'pending' OR lease_until <= clock_timestamp()))
                OR NOT EXISTS (SELECT 1 FROM public.core_runtime_session r JOIN public.core_request_execution e USING (session_id)
                    WHERE r.session_id = j.session_id AND r.state = 'active' AND r.active_generation = j.generation
                        AND r.active_turn_id = j.turn_id AND r.active_turn_sequence::text = j.payload->>'context_sequence'
                        AND e.request_id = j.request_id AND e.generation = j.generation AND e.turn_id = j.turn_id
                        AND e.state IN ('claimed', 'streaming', 'complete')))"
        );
    }

    /** Ids of up to five speech jobs due now (pending, or running with an expired lease), oldest first. @return list<int> */
    public function dueTtsJobIds(): array
    {
        return array_map('intval', $this->connection()->fetchFirstColumn(
            "SELECT id FROM public.core_worker_job
             WHERE job_type = 'tts' AND payload->>'schema' = 'synth.tts.job.v1' AND available_at <= clock_timestamp()
               AND deadline_at > clock_timestamp() AND attempts < 3
               AND (state = 'pending' OR (state = 'running' AND lease_until <= clock_timestamp())) ORDER BY available_at, id LIMIT 5"
        ));
    }

    /** Up to twenty terminal speech jobs older than ten seconds whose private input was not yet cleaned up. @return list<array<string, mixed>> */
    public function ttsJobsToClean(): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT id, payload FROM public.core_worker_job WHERE job_type = 'tts'
               AND payload->>'schema' = 'synth.tts.job.v1' AND state IN ('ready', 'failed', 'cancelled', 'stale')
               AND updated_at < clock_timestamp() - INTERVAL '10 seconds' AND NOT jsonb_exists(payload, 'input_cleanup_state') ORDER BY id LIMIT 20"
        );
    }

    /** Records the state of a speech job's private input cleanup in its payload. */
    public function markTtsInputCleanup(int $jobId, string $state): void
    {
        $this->connection()->executeStatement(
            "UPDATE public.core_worker_job SET payload = payload || jsonb_build_object('input_cleanup_state', CAST(? AS text)) WHERE id = ?",
            [$state, $jobId]
        );
    }

    /** State and deadline flag of one speech job by id and cache key, or null. @return array<string, mixed>|null */
    public function ttsStatusById(int $jobId, string $cacheKey): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT state, deadline_at > clock_timestamp() AS within_deadline FROM public.core_worker_job
             WHERE id = ? AND job_type = 'tts' AND payload->>'schema' = 'synth.tts.job.v1' AND cache_key = ?",
            [$jobId, $cacheKey]
        );

        return $row === false ? null : $row;
    }

    /** State and deadline flag of a live (pending or running, within deadline) speech job for a cache key, or null. @return array<string, mixed>|null */
    public function ttsActiveByCacheKey(string $cacheKey): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT state, deadline_at > clock_timestamp() AS within_deadline FROM public.core_worker_job
             WHERE job_type = 'tts' AND payload->>'schema' = 'synth.tts.job.v1' AND state IN ('pending', 'running')
               AND deadline_at > clock_timestamp() AND cache_key = ? LIMIT 1",
            [$cacheKey]
        );

        return $row === false ? null : $row;
    }
}
