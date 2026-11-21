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

    /** A pending or running profile job (whole row), or null. @return array<string, mixed>|null */
    public function activeProfileJob(int $jobId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT * FROM public.core_worker_job WHERE id = ? AND job_type = 'profile' AND payload->>'schema' = 'synth.profile.job.v1' AND state IN ('pending', 'running')",
            [$jobId]
        );

        return $row === false ? null : $row;
    }

    /** Queues one profile job for an actor (a duplicate of an existing job is ignored); throws when the statement fails. */
    public function insertProfileJob(string $sessionId, string $requestId, string $turnId, int $generation, int $actorIdentityId, string $payloadJson): void
    {
        $this->profileStatement(
            "INSERT INTO public.core_worker_job (job_type, session_id, request_id, turn_id, generation, actor_identity_id, payload, deadline_at)
             VALUES ('profile', ?, ?, ?, ?, ?, CAST(? AS jsonb), clock_timestamp() + INTERVAL '30 minutes') ON CONFLICT DO NOTHING",
            [$sessionId, $requestId, $turnId, $generation, $actorIdentityId, $payloadJson],
            'Unable to persist native profile job.'
        );
    }

    /** Takes the transaction-scoped advisory lock that serialises one actor's profile attempts; throws when it fails. */
    public function lockProfileActor(int $actorIdentityId): void
    {
        try {
            $this->connection()->executeQuery('SELECT pg_advisory_xact_lock(hashtext(?))', ['synth.profile.actor:'.$actorIdentityId]);
        } catch (\Throwable $error) {
            throw new \RuntimeException('Unable to lock profile actor.', 0, $error);
        }
    }

    /**
     * Starts an attempt on a profile job under a new lease token when the job is due and no other job of the same actor holds
     * a live lease; returns the token, or null when the job could not be claimed. Throws when the statement fails.
     */
    public function claimProfileAttempt(int $jobId, string $token): ?string
    {
        try {
            $claimed = $this->connection()->fetchOne(
                "UPDATE public.core_worker_job j SET state = 'running', attempts = attempts + 1, lease_token = ?,
                    lease_until = LEAST(deadline_at, clock_timestamp() + INTERVAL '2 minutes'), updated_at = clock_timestamp()
                 WHERE j.id = ? AND j.attempts < 3 AND j.deadline_at > clock_timestamp() AND j.available_at <= clock_timestamp()
                    AND (j.state = 'pending' OR (j.state = 'running' AND j.lease_until <= clock_timestamp()))
                    AND NOT EXISTS (SELECT 1 FROM public.core_worker_job other WHERE other.id <> j.id AND other.job_type = 'profile'
                        AND other.payload->>'schema' = 'synth.profile.job.v1' AND other.actor_identity_id = j.actor_identity_id
                        AND other.state = 'running' AND other.lease_until > clock_timestamp() AND other.deadline_at > clock_timestamp())
                 RETURNING lease_token",
                [$token, $jobId]
            );
        } catch (\Throwable $error) {
            throw new \RuntimeException('Unable to claim native profile job.', 0, $error);
        }

        return $claimed === false || $claimed === null ? null : (string) $claimed;
    }

    /** Releases a profile job whose profile is disabled; throws when the statement fails. */
    public function finishDisabledProfile(int $jobId): void
    {
        $this->profileStatement(
            "UPDATE public.core_worker_job SET state = 'ready', last_error = 'profile_disabled', lease_token = NULL, lease_until = NULL, updated_at = clock_timestamp() WHERE id = ?",
            [$jobId],
            'Unable to finish disabled profile job.'
        );
    }

    /** Extends the lease of a running profile attempt by two minutes (never past its deadline); throws when the statement fails. */
    public function renewProfileLease(int $jobId): void
    {
        $this->profileStatement(
            "UPDATE public.core_worker_job SET lease_until = LEAST(deadline_at, clock_timestamp() + INTERVAL '2 minutes') WHERE id = ?",
            [$jobId],
            'Unable to renew profile attempt.'
        );
    }

    /** Marks a profile job ready and clears its lease and error; throws when the statement fails. */
    public function finishProfile(int $jobId): void
    {
        $this->profileStatement(
            "UPDATE public.core_worker_job SET state = 'ready', lease_token = NULL, lease_until = NULL, last_error = NULL, updated_at = clock_timestamp() WHERE id = ?",
            [$jobId],
            'Unable to finish profile job.'
        );
    }

    /**
     * Records a failed attempt of a running profile job under its lease token: failed when terminal, past its deadline or out
     * of attempts, else pending again in ten seconds. Throws when the statement fails.
     */
    public function recordProfileFailure(int $jobId, string $token, bool $terminal, string $code): void
    {
        $this->profileStatement(
            "UPDATE public.core_worker_job SET state = CASE WHEN CAST(? AS boolean) OR attempts >= 3 OR deadline_at <= clock_timestamp() THEN 'failed' ELSE 'pending' END,
                lease_token = NULL, lease_until = NULL, available_at = clock_timestamp() + INTERVAL '10 seconds', last_error = ?, updated_at = clock_timestamp()
             WHERE id = ? AND state = 'running' AND lease_token = ?",
            [$terminal ? 't' : 'f', $code, $jobId, $token],
            'Unable to record profile attempt failure.'
        );
    }

    /** Fails or stales profile jobs whose owner, attempts or deadline ran out; throws when the statement fails. */
    public function retireProfileWork(): void
    {
        $this->profileStatement(
            "UPDATE public.core_worker_job j SET state = CASE WHEN deadline_at <= clock_timestamp() OR attempts >= 3 THEN 'failed' ELSE 'stale' END,
                lease_token = NULL, lease_until = NULL, last_error = 'owner_or_attempt_expired', updated_at = clock_timestamp()
             WHERE j.job_type = 'profile' AND j.payload->>'schema' = 'synth.profile.job.v1' AND j.state IN ('pending', 'running')
                AND (deadline_at <= clock_timestamp() OR (attempts >= 3 AND (state = 'pending' OR lease_until <= clock_timestamp()))
                    OR NOT EXISTS (SELECT 1 FROM public.core_runtime_session r JOIN public.core_request_execution e USING (session_id)
                        WHERE r.session_id = j.session_id AND r.state = 'active' AND r.active_generation = j.generation
                            AND e.request_id = j.request_id AND e.generation = j.generation AND e.state = 'complete'))",
            [],
            'Unable to retire old profile work.'
        );
    }

    /** Ids of up to $limit profile jobs that are due (pending, or running with an expired lease), oldest first. @return list<int> */
    public function dueProfileJobIds(int $limit = 5): array
    {
        $ids = $this->connection()->fetchFirstColumn(
            "SELECT id FROM public.core_worker_job WHERE job_type = 'profile' AND payload->>'schema' = 'synth.profile.job.v1'
                AND available_at <= clock_timestamp() AND (state = 'pending' OR (state = 'running' AND lease_until <= clock_timestamp()))
             ORDER BY available_at, id LIMIT ".max(1, $limit)
        );

        return array_map('intval', $ids);
    }

    /** Marks one unclaimable profile job stale (its origin is gone); returns false when the statement fails. */
    public function markProfileStale(int $jobId): bool
    {
        try {
            $this->connection()->executeStatement(
                "UPDATE public.core_worker_job SET state = 'stale', last_error = 'origin_unavailable', lease_token = NULL, lease_until = NULL, updated_at = clock_timestamp()
                 WHERE id = ? AND job_type = 'profile' AND payload->>'schema' = 'synth.profile.job.v1'
                    AND (state = 'pending' OR (state = 'running' AND lease_until <= clock_timestamp()))",
                [$jobId]
            );
        } catch (\Throwable $error) {
            return false;
        }

        return true;
    }

    /** @param list<mixed> $parameters */
    private function profileStatement(string $sql, array $parameters, string $failure): void
    {
        try {
            $this->connection()->executeStatement($sql, $parameters);
        } catch (\Throwable $error) {
            throw new \RuntimeException($failure, 0, $error);
        }
    }
}
