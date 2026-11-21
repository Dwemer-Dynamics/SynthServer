<?php

// Reads core_worker_job. Writes stay with the statements that own them. The lease checks are plain reads (no lock).

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractWorkerJob;

/** Finds rows of core_worker_job. */
class WorkerJobDao
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

    /** Cancels pending and running jobs of older generations. */
    public function cancelOlderGenerations(string $sessionId, int $generation): bool
    {
        return $this->tryStatement(
            "UPDATE public.core_worker_job SET state='cancelled', updated_at=NOW()
              WHERE session_id = ? AND generation < ? AND state IN ('pending','running')",
            [$sessionId, $generation]
        );
    }

    /**
     * Cancels jobs of an older turn or context sequence when a new context supersedes them; diary jobs and relationship gift
     * jobs of the current schema are kept.
     */
    public function cancelSupersededTurn(string $sessionId, int $generation, string $turnId, int $sequence): bool
    {
        return $this->tryStatement(
            "UPDATE public.core_worker_job SET state='cancelled', updated_at=NOW()
                WHERE session_id = ? AND generation = ?
                  AND (turn_id <> ? OR COALESCE(payload->'protocol_event'->>'context_sequence',
                    payload->>'context_sequence') IS DISTINCT FROM ?)
                  AND (job_type<>'diary' OR payload->>'schema' IS DISTINCT FROM 'synth.diary.job.v1')
                  AND (job_type<>'relationship' OR relationship_evaluation_id IS NULL
                    OR payload->>'schema' IS DISTINCT FROM 'synth.relationship.gift-job.v1')
                  AND state IN ('pending','running')",
            [$sessionId, $generation, $turnId, (string) $sequence]
        );
    }

    /** Cancels pending and running jobs up to this generation (a halt). */
    public function cancelForHalt(string $sessionId, int $generation): bool
    {
        return $this->tryStatement(
            "UPDATE public.core_worker_job SET state='cancelled', updated_at=NOW()
              WHERE session_id = ? AND generation <= ? AND state IN ('pending', 'running')",
            [$sessionId, $generation]
        );
    }

    /** Cancels the pending and running jobs of one cancelled turn and context sequence. */
    public function cancelTurn(string $sessionId, int $generation, string $turnId, int $sequence): bool
    {
        return $this->tryStatement(
            "UPDATE public.core_worker_job SET state='cancelled', updated_at=NOW()
                WHERE session_id = ? AND generation = ? AND turn_id = ?
                  AND COALESCE(payload->'protocol_event'->>'context_sequence',payload->>'context_sequence') = ?
                  AND state IN ('pending','running')",
            [$sessionId, $generation, $turnId, (string) $sequence]
        );
    }

    /** Cancels the pending and running jobs of one cancelled request. */
    public function cancelRequest(string $sessionId, int $generation, string $requestId): bool
    {
        return $this->tryStatement(
            "UPDATE public.core_worker_job SET state='cancelled', updated_at=NOW()
                WHERE session_id = ? AND generation = ? AND request_id = ? AND state IN ('pending','running')",
            [$sessionId, $generation, $requestId]
        );
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractWorkerJob> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractWorkerJob::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }
}
