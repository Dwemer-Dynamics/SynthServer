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

    /** True when this actor and role already have a live diary job or an unacknowledged result for this snapshot (automatic reservation check). */
    public function diaryAdmissionPending(string $sessionId, int $generation, int $sequence, int $actorIdentityId, string $role): bool
    {
        return $this->connection()->fetchOne(
            "SELECT 1 FROM public.core_diary_input i JOIN public.core_worker_job j ON j.diary_input_id=i.id
             LEFT JOIN public.core_diary_result r ON r.input_id=i.id
             JOIN public.core_runtime_context c ON c.session_id=i.session_id AND c.generation=i.generation
             WHERE i.session_id=? AND i.generation=? AND c.snapshot_sequence=? AND i.actor_identity_id=?
                AND i.diary_role=? AND ((j.state IN ('pending','running') AND j.deadline_at>clock_timestamp()
                    AND (j.attempts<3 OR (j.state='running' AND j.lease_until>clock_timestamp()))) OR r.history_event_id>c.history_event_id) LIMIT 1",
            [$sessionId, $generation, $sequence, $actorIdentityId, $role]
        ) !== false;
    }

    /** Inserts the diary job of one frozen input unless it exists; throws on statement failure. */
    public function admitDiary(int $inputId, string $turnId): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO public.core_worker_job
                (job_type,session_id,request_id,turn_id,generation,actor_identity_id,diary_input_id,payload,deadline_at)
             SELECT 'diary',session_id,request_id,?,generation,actor_identity_id,id,
                jsonb_build_object('schema','synth.diary.job.v1','context_sequence',context_sequence),clock_timestamp()+INTERVAL '30 minutes'
             FROM public.core_diary_input WHERE id=? ON CONFLICT DO NOTHING",
            [$turnId, $inputId]
        );
    }

    /** The id of the job of one diary input, or null. */
    public function idForDiaryInput(int $inputId): ?int
    {
        $id = $this->connection()->fetchOne('SELECT id FROM public.core_worker_job WHERE diary_input_id=?', [$inputId]);

        return $id === false ? null : (int) $id;
    }

    /** One pending or running diary job with its frozen input and whether the input hash still matches; null when none. @return array<string, mixed>|null */
    public function diaryJobWithInput(int $jobId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT j.*,i.input_payload,i.input_sha256,i.input_sha256=encode(sha256(convert_to(i.input_payload::text,'UTF8')),'hex') AS valid
             FROM public.core_worker_job j JOIN public.core_diary_input i ON i.id=j.diary_input_id
             WHERE j.id=? AND j.job_type='diary' AND j.payload->>'schema'='synth.diary.job.v1' AND j.state IN ('pending','running')",
            [$jobId]
        );

        return $row === false ? null : $row;
    }

    /** Takes the transaction-scoped advisory lock of one key (hashtext); it is held until the caller's open transaction ends. */
    public function lockAdvisoryKey(string $key): bool
    {
        try {
            return $this->connection()->fetchAssociative('SELECT pg_advisory_xact_lock(hashtext(?))', [$key]) !== false;
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }
    }

    /**
     * Claims one diary job for a new two-minute lease unless the actor already has another live running diary job; returns the
     * lease token, or null when nothing was claimed. Throws on statement failure.
     */
    public function claimDiary(int $jobId, string $token): ?string
    {
        $claimed = $this->connection()->fetchOne(
            "UPDATE public.core_worker_job j SET state='running',attempts=attempts+1,lease_token=?,
                lease_until=LEAST(deadline_at,clock_timestamp()+INTERVAL '2 minutes'),updated_at=clock_timestamp()
             WHERE j.id=? AND attempts<3 AND deadline_at>clock_timestamp() AND available_at<=clock_timestamp()
                AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp()))
                AND NOT EXISTS(SELECT 1 FROM public.core_worker_job other WHERE other.id<>j.id AND other.job_type='diary'
                    AND other.actor_identity_id=j.actor_identity_id AND other.state='running'
                    AND other.lease_until>clock_timestamp() AND other.deadline_at>clock_timestamp()) RETURNING lease_token",
            [$token, $jobId]
        );

        return $claimed === false ? null : (string) $claimed;
    }

    /** Marks a diary job ready and clears its lease. False when the statement fails. */
    public function markDiaryReady(int $jobId): bool
    {
        try {
            $this->connection()->executeStatement(
                "UPDATE public.core_worker_job SET state='ready',last_error=NULL,
                    lease_token=NULL,lease_until=NULL,updated_at=clock_timestamp() WHERE id=?",
                [$jobId]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /** Releases a running diary attempt for retry (or fails it when terminal, out of attempts or past its deadline). False when the statement fails. */
    public function failDiaryAttempt(int $jobId, string $token, bool $terminal, string $lastError): bool
    {
        try {
            $this->connection()->executeStatement(
                "UPDATE public.core_worker_job SET state=CASE WHEN CAST(? AS boolean) OR attempts>=3 OR deadline_at<=clock_timestamp()
                    THEN 'failed' ELSE 'pending' END,lease_token=NULL,lease_until=NULL,available_at=clock_timestamp()+INTERVAL '10 seconds',
                    last_error=?,updated_at=clock_timestamp() WHERE id=? AND state='running' AND lease_token=?",
                [$terminal ? 't' : 'f', $lastError, $jobId, $token]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /** Retires diary jobs past their deadline or attempts or whose owner request/generation is gone. False when the statement fails. */
    public function retireDiaryWork(): bool
    {
        try {
            $this->connection()->executeStatement(
                "UPDATE public.core_worker_job j SET state=CASE WHEN deadline_at<=clock_timestamp() OR attempts>=3 THEN 'failed' ELSE 'stale' END,
                    lease_token=NULL,lease_until=NULL,last_error='owner_or_attempt_expired',updated_at=clock_timestamp()
                 WHERE j.job_type='diary' AND j.payload->>'schema'='synth.diary.job.v1' AND j.state IN ('pending','running')
                    AND (deadline_at<=clock_timestamp() OR (attempts>=3 AND (state='pending' OR lease_until<=clock_timestamp()))
                        OR NOT EXISTS(SELECT 1 FROM public.core_runtime_session r JOIN public.core_request_execution e USING(session_id)
                            WHERE r.session_id=j.session_id AND r.state='active' AND r.active_generation=j.generation
                                AND e.request_id=j.request_id AND e.generation=j.generation AND e.state IN ('claimed','streaming','complete')))"
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /** Ids of up to five dispatchable diary jobs whose request completed. @return list<array<string, mixed>> */
    public function dispatchableDiaryJobs(): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT j.id FROM public.core_worker_job j JOIN public.core_request_execution e
                ON e.session_id=j.session_id AND e.request_id=j.request_id AND e.generation=j.generation AND e.state='complete'
             WHERE j.job_type='diary' AND j.payload->>'schema'='synth.diary.job.v1' AND j.available_at<=clock_timestamp()
                AND (j.state='pending' OR (j.state='running' AND j.lease_until<=clock_timestamp())) ORDER BY j.available_at,j.id LIMIT 5"
        );
    }

    /** Fails a diary job whose input or owner is invalid unless it is under a live lease. False when the statement fails. */
    public function failInvalidDiary(int $jobId): bool
    {
        try {
            $this->connection()->executeStatement(
                "UPDATE public.core_worker_job SET state='failed',last_error='input_or_owner_invalid',
                    lease_token=NULL,lease_until=NULL,updated_at=clock_timestamp() WHERE id=?
                    AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp()))",
                [$jobId]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /**
     * The diary jobs, frozen inputs and (when written) results of one request, with their integrity flags, for the batch status read.
     *
     * @return list<array<string, mixed>>
     */
    public function diaryStatusRows(string $sessionId, string $requestId, int $generation, string $turnId, int $sequence): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT j.state,j.deadline_at<=clock_timestamp() AS expired,
                j.attempts>=3 AND (j.state='pending' OR j.lease_until<=clock_timestamp()) AS exhausted,
                j.lease_until>clock_timestamp() AS live_lease,i.id,i.actor_identity_id,i.diary_role,i.input_payload,i.input_sha256,
                i.input_sha256=encode(sha256(convert_to(i.input_payload::text,'UTF8')),'hex') AS valid_input,
                r.history_event_id,r.result_payload,
                r.result_sha256=encode(sha256(convert_to(r.result_payload::text,'UTF8')),'hex') AS valid_result,
                h.session_id=i.session_id AND h.request_id=i.request_id AND h.generation=i.generation
                    AND h.context_sequence=i.context_sequence AND h.playthrough_id=c.playthrough_id
                    AND h.event_payload->>'type'='diary_result' AND h.event_payload->>'delivery_state'='derived'
                    AND h.event_payload->'diary_result'=r.result_payload
                    AND h.event_payload->>'diary_result_sha256'=r.result_sha256
                    AND h.event_payload->>'diary_input_sha256'=i.input_sha256 AS valid_history
            FROM public.core_diary_input i JOIN public.core_worker_job j ON j.diary_input_id=i.id
                AND j.session_id=i.session_id AND j.request_id=i.request_id AND j.generation=i.generation
                AND j.actor_identity_id=i.actor_identity_id AND j.turn_id=? AND j.job_type='diary'
                AND j.payload->>'schema'='synth.diary.job.v1' AND j.payload->>'context_sequence'=i.context_sequence::text
            JOIN public.core_runtime_context c ON c.session_id=i.session_id AND c.generation=i.generation
                AND c.snapshot_sequence=i.context_sequence
            LEFT JOIN public.core_diary_result r ON r.input_id=i.id
            LEFT JOIN public.core_history_event h ON h.id=r.history_event_id
            WHERE i.session_id=? AND i.request_id=? AND i.generation=? AND i.context_sequence=? AND i.diary_role IN ('npc','narrator','player')
                AND i.input_payload->>'actor_identity_id'=i.actor_identity_id::text
                AND i.input_payload->>'diary_role'=i.diary_role AND i.input_payload->'context'=c.context_payload
                AND i.input_payload->'prior_revision'=COALESCE(to_jsonb(i.prior_history_event_id),'null'::jsonb)
                AND i.input_payload->'profile_revision'=COALESCE(to_jsonb(i.profile_revision_id),'null'::jsonb)",
            [$turnId, $sessionId, $requestId, $generation, $sequence]
        );
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
}
