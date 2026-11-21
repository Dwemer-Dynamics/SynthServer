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

    /** How many finished (ready, failed, cancelled or stale) jobs of one type were last updated more than $days days ago; null when the count fails. */
    public function countFinishedOlderThan(string $jobType, int $days): ?int
    {
        try {
            return (int) $this->connection()->fetchOne(
                "SELECT COUNT(*) FROM public.core_worker_job WHERE job_type = ? AND state IN ('ready','failed','cancelled','stale') AND updated_at < now() - make_interval(days => ?)",
                [$jobType, $days]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }
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

    // Memory jobs. These statements run inside the caller's native request transaction (its advisory locks are already held) and
    // throw a Doctrine exception when a statement fails, so the caller keeps its own failure message.

    /** Fails one actor's pending or running memory jobs whose deadline has passed or whose third lease has expired. */
    public function retireExpiredMemoryJobsForActor(int|string $actorId): void
    {
        $this->connection()->executeStatement(
            "UPDATE public.core_worker_job SET state='failed',lease_token=NULL,lease_until=NULL,
                last_error='lease_expired',updated_at=clock_timestamp()
             WHERE actor_identity_id=? AND job_type='memory' AND memory_batch_id IS NOT NULL AND state IN ('pending','running')
                AND (deadline_at<=clock_timestamp() OR (attempts>=3 AND lease_until<=clock_timestamp()))",
            [$actorId]
        );
    }

    /**
     * Queues one memory job for a frozen batch unless the batch already has a summary or a failed job for this request, or the actor
     * already has two live memory jobs. Returns the new job id, or null when nothing was inserted.
     */
    public function enqueueMemoryJob(string $sessionId, string $requestId, string $turnId, int|string $generation, int|string $actorId, int $batchId, int|string $contextSequence): ?int
    {
        $id = $this->connection()->fetchOne(
            "INSERT INTO public.core_worker_job (job_type,session_id,request_id,turn_id,generation,actor_identity_id,
                memory_batch_id,payload,deadline_at)
             SELECT 'memory',:session,:request,:turn,:generation,:actor,:batch,jsonb_build_object('context_sequence',CAST(:sequence AS bigint)),clock_timestamp()+INTERVAL '10 minutes'
             WHERE NOT EXISTS (SELECT 1 FROM public.core_memory_summary WHERE batch_id=:batch)
               AND NOT EXISTS (SELECT 1 FROM public.core_worker_job WHERE memory_batch_id=:batch
                    AND session_id=:session AND request_id=:request AND state='failed')
               AND (SELECT count(*) FROM public.core_worker_job WHERE actor_identity_id=:actor
                    AND job_type='memory' AND memory_batch_id IS NOT NULL AND state IN ('pending','running'))<2
             ON CONFLICT DO NOTHING RETURNING id",
            ['session' => $sessionId, 'request' => $requestId, 'turn' => $turnId, 'generation' => $generation,
                'actor' => $actorId, 'batch' => $batchId, 'sequence' => $contextSequence]
        );

        return $id === false ? null : (int) $id;
    }

    /** The pending or running memory job already queued for this batch, request and generation, or null. */
    public function findLiveMemoryJobId(int $batchId, string $sessionId, string $requestId, int|string $generation): ?int
    {
        $id = $this->connection()->fetchOne(
            "SELECT id FROM public.core_worker_job WHERE memory_batch_id=? AND session_id=? AND request_id=?
             AND generation=? AND job_type='memory' AND state IN ('pending','running')",
            [$batchId, $sessionId, $requestId, $generation]
        );

        return $id === false ? null : (int) $id;
    }

    /** The actor and frozen batch of one request's memory job (actor_identity_id, memory_batch_id), or null. @return array<string, mixed>|null */
    public function memoryJobOwner(int $jobId, string $sessionId, string $requestId, int|string $generation): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT actor_identity_id,memory_batch_id FROM public.core_worker_job
             WHERE id=? AND session_id=? AND request_id=? AND generation=? AND job_type='memory' AND memory_batch_id IS NOT NULL",
            [$jobId, $sessionId, $requestId, $generation]
        );

        return $row === false ? null : $row;
    }

    /** Fails one memory job whose deadline passed or whose third lease expired, before it can be claimed again. */
    public function retireExpiredMemoryAttempt(int $jobId, string $sessionId, string $requestId, int|string $generation): void
    {
        $this->connection()->executeStatement(
            "UPDATE public.core_worker_job SET state='failed',lease_token=NULL,lease_until=NULL,
                last_error='lease_expired',updated_at=clock_timestamp()
             WHERE id=? AND session_id=? AND request_id=? AND generation=? AND job_type='memory'
                AND memory_batch_id IS NOT NULL AND state IN ('pending','running')
                AND (deadline_at<=clock_timestamp() OR (attempts>=3 AND lease_until<=clock_timestamp()))",
            [$jobId, $sessionId, $requestId, $generation]
        );
    }

    /** Claims one memory attempt under a fresh lease token; the claimed row (id, memory_batch_id, lease_token, attempts) or null. @return array<string, mixed>|null */
    public function claimMemoryAttempt(string $token, int $jobId, string $sessionId, string $requestId, int|string $generation, string $contextSequence): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "UPDATE public.core_worker_job SET state='running',attempts=attempts+1,lease_token=?,
                lease_until=LEAST(deadline_at,clock_timestamp()+INTERVAL '2 minutes'),updated_at=clock_timestamp()
             WHERE id=? AND job_type='memory' AND memory_batch_id IS NOT NULL
                AND session_id=? AND request_id=? AND generation=?
                AND payload->>'context_sequence'=? AND deadline_at>clock_timestamp() AND available_at<=clock_timestamp() AND attempts<3
                AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp()))
             RETURNING id,memory_batch_id,lease_token,attempts",
            [$token, $jobId, $sessionId, $requestId, $generation, $contextSequence]
        );

        return $row === false ? null : $row;
    }

    /**
     * The running memory job's checkpoint payload, batch and frozen input hash, read FOR UPDATE OF the job row under its live lease
     * (it stays locked until the caller's open transaction ends); null when the lease is lost. @return array<string, mixed>|null
     */
    public function lockMemoryCheckpoint(int $jobId, string $sessionId, string $requestId, int|string $generation, string $turnId, string $contextSequence, string $token): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT j.payload,j.memory_batch_id,
                encode(sha256(convert_to(b.input_payload::text,'UTF8')),'hex') AS input_sha256
            FROM public.core_worker_job j JOIN public.core_memory_batch b ON b.id=j.memory_batch_id
            WHERE j.id=? AND j.job_type='memory' AND j.session_id=? AND j.request_id=? AND j.generation=?
                AND j.turn_id=? AND j.payload->>'context_sequence'=? AND j.state='running' AND j.lease_token=?
                AND j.lease_until>clock_timestamp() AND j.deadline_at>clock_timestamp() FOR UPDATE OF j",
            [$jobId, $sessionId, $requestId, $generation, $turnId, $contextSequence, $token]
        );

        return $row === false ? null : $row;
    }

    /** Stores the accepted-summary checkpoint once under a live lease; false when no live, unwritten row matched. */
    public function retainMemoryCheckpoint(int $jobId, string $checkpointJson, string $token): bool
    {
        return $this->connection()->fetchOne(
            "UPDATE public.core_worker_job SET
                payload=jsonb_set(payload,'{accepted_summary}',CAST(:checkpoint AS jsonb)),updated_at=clock_timestamp()
             WHERE id=:job AND state='running' AND lease_token=:token AND lease_until>clock_timestamp()
                AND deadline_at>clock_timestamp() AND NOT jsonb_exists(payload,'accepted_summary') RETURNING id",
            ['job' => $jobId, 'checkpoint' => $checkpointJson, 'token' => $token]
        ) !== false;
    }

    /** Records a provider failure for the running attempt (retry in ten seconds, or failed when out of attempts or time). True when a row matched. */
    public function failMemoryAttempt(int $jobId, string $sessionId, string $requestId, int|string $generation, string $token, string $contextSequence): bool
    {
        return $this->connection()->fetchOne(
            "UPDATE public.core_worker_job SET state=CASE WHEN attempts>=3 OR deadline_at<=clock_timestamp() THEN 'failed' ELSE 'pending' END,
                lease_token=NULL,lease_until=NULL,available_at=clock_timestamp()+INTERVAL '10 seconds',
                last_error='provider_failed',updated_at=clock_timestamp()
             WHERE id=? AND job_type='memory' AND session_id=? AND request_id=? AND generation=?
                AND state='running' AND lease_token=? AND lease_until>clock_timestamp()
                AND payload->>'context_sequence'=? RETURNING id",
            [$jobId, $sessionId, $requestId, $generation, $token, $contextSequence]
        ) !== false;
    }

    /** Cancels the pending or running memory jobs of a batch that was rebuilt. */
    public function cancelRebuiltMemoryJobs(int $batchId): void
    {
        $this->connection()->executeStatement(
            "UPDATE public.core_worker_job SET state='cancelled',lease_token=NULL,lease_until=NULL,
            last_error='memory_rebuilt',updated_at=clock_timestamp() WHERE job_type='memory' AND state IN ('pending','running')
                AND memory_batch_id=?",
            [$batchId]
        );
    }

    /** The oldest due pending or expired-lease memory job of this turn and context sequence, or null. */
    public function nextRetryableMemoryJobId(string $sessionId, string $turnId, int|string $generation, string $contextSequence): ?int
    {
        $id = $this->connection()->fetchOne(
            "SELECT id FROM public.core_worker_job
                WHERE job_type='memory' AND memory_batch_id IS NOT NULL AND session_id=? AND turn_id=?
                    AND generation=? AND payload->>'context_sequence'=? AND state IN ('pending','running')
                    AND (deadline_at<=clock_timestamp() OR available_at<=clock_timestamp())
                    AND (state='pending' OR lease_until<=clock_timestamp())
                ORDER BY available_at,id LIMIT 1",
            [$sessionId, $turnId, $generation, $contextSequence]
        );

        return $id === false ? null : (int) $id;
    }

    /** How many live (pending or running, unexpired) memory jobs one actor has. */
    public function countLiveMemoryJobsForActor(int|string $actorId): int
    {
        return (int) $this->connection()->fetchOne(
            "SELECT count(*) AS n FROM public.core_worker_job
                WHERE job_type='memory' AND memory_batch_id IS NOT NULL AND actor_identity_id=?
                    AND state IN ('pending','running') AND deadline_at>clock_timestamp()",
            [$actorId]
        );
    }

    /**
     * The stored ingress event that owns one pending or running memory job, joined to its request, the active session and the job's
     * context snapshot; null when any link is missing. @return array<string, mixed>|null
     */
    public function persistedMemoryJobOrigin(int $jobId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT j.session_id,j.request_id,j.turn_id,j.generation,j.actor_identity_id,
                j.payload->>'context_sequence' AS context_sequence,p.event_type,p.canonical_event,p.event_sha256
            FROM public.core_worker_job j
            JOIN public.core_request_execution e ON e.session_id=j.session_id AND e.request_id=j.request_id
                AND e.generation=j.generation AND e.turn_id=j.turn_id
            JOIN public.core_protocol_event p ON p.session_id=e.session_id AND p.request_id=e.request_id
                AND p.generation=e.generation AND p.turn_id=e.turn_id AND p.event_type=e.event_type
                AND p.event_sha256=e.request_sha256
            JOIN public.core_runtime_session r ON r.session_id=j.session_id AND r.active_generation=j.generation
                AND r.state='active' AND p.canonical_event->>'runtime_variant'=r.runtime_variant
            JOIN public.core_runtime_context c ON c.session_id=j.session_id AND c.generation=j.generation
                AND c.snapshot_sequence::text=j.payload->>'context_sequence' AND c.playthrough_id=r.playthrough_id
            WHERE j.id=? AND j.job_type='memory' AND j.memory_batch_id IS NOT NULL
                AND j.state IN ('pending','running')",
            [$jobId]
        );

        return $row === false ? null : $row;
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
