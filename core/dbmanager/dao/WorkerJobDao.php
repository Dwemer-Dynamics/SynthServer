<?php

// Reads core_worker_job. Writes stay with the statements that own them. The lease checks are plain reads (no lock).

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractWorkerJob;

/** Finds rows of core_worker_job. */
class WorkerJobDao
{
    use UsesEntityManager;
    use TextRows;

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

    /** True while a pending or running middleterm job of this actor has an unexpired deadline. Throws when the statement fails. */
    public function hasLiveMiddletermJob(int $actorId): bool
    {
        return $this->connection()->fetchOne(
            "SELECT 1 FROM public.core_worker_job WHERE job_type = 'middleterm'
                AND actor_identity_id = ? AND state IN ('pending','running') AND deadline_at > clock_timestamp() LIMIT 1",
            [$actorId]
        ) !== false;
    }

    /**
     * The attempt budget of one regeneration approval: summed attempts, first deadline, failed/expired flags and whether a
     * replacement was published. Null when there is no row or the statement fails.
     *
     * @return array<string, mixed>|null
     */
    public function middletermBudget(int $approvalId): ?array
    {
        try {
            $row = $this->connection()->fetchAssociative(
                "SELECT COALESCE(SUM(j.attempts),0) AS attempts,MIN(j.deadline_at) AS deadline_at,
                    COALESCE(bool_or(j.state='failed'),false) AS failed,
                    COALESCE(MIN(j.deadline_at)<=clock_timestamp(),false) AS expired,
                    EXISTS(SELECT 1 FROM public.core_middleterm_replacement WHERE approval_id = :approval) AS published
                 FROM public.core_middleterm_input i JOIN public.core_worker_job j ON j.middleterm_input_id=i.id AND j.job_type='middleterm'
                 WHERE i.regeneration_approval_id = :approval",
                ['approval' => $approvalId]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }

        return $row === false ? null : $row;
    }

    /** Extends the live middleterm lease of the exact attempt; false when it is not the current unexpired attempt (or the statement fails). */
    public function renewMiddletermLease(int $jobId, string $token, string $sessionId, string $requestId, int $generation, string $turnId, int $sequence): bool
    {
        try {
            return $this->connection()->fetchOne(
                "UPDATE public.core_worker_job SET lease_until=LEAST(deadline_at,clock_timestamp()+INTERVAL '15 seconds')
                 WHERE id = ? AND job_type='middleterm' AND payload->>'schema'='synth.middleterm.job.v1'
                    AND session_id = ? AND request_id = ? AND generation = ? AND turn_id = ?
                    AND payload->>'context_sequence' = ? AND state='running' AND lease_token = ?
                    AND lease_until>clock_timestamp() AND deadline_at>clock_timestamp() RETURNING id",
                [$jobId, $sessionId, $requestId, $generation, $turnId, (string) $sequence, $token]
            ) !== false;
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }
    }

    /**
     * A runnable middleterm job joined to its completed request execution and canonical ingress event; null when none or the
     * statement fails.
     *
     * @return array<string, mixed>|null
     */
    public function middletermRunnable(int $jobId): ?array
    {
        try {
            $row = $this->connection()->fetchAssociative(
                "SELECT j.*,p.canonical_event,p.event_sha256,e.event_type,j.payload->>'context_sequence' AS context_sequence
                 FROM public.core_worker_job j JOIN public.core_request_execution e USING(session_id,request_id,generation,turn_id)
                 JOIN public.core_protocol_event p USING(session_id,request_id,generation,turn_id,event_type)
                 JOIN public.core_middleterm_input i ON i.id=j.middleterm_input_id AND i.actor_identity_id=j.actor_identity_id
                    AND i.session_id=j.session_id AND i.request_id=j.request_id AND i.generation=j.generation
                    AND i.context_sequence::text=j.payload->>'context_sequence'
                 WHERE j.id = ? AND j.job_type='middleterm' AND j.middleterm_input_id IS NOT NULL AND j.state IN ('pending','running')
                    AND j.payload->>'schema'='synth.middleterm.job.v1' AND p.event_sha256=e.request_sha256 AND e.state='complete'",
                [$jobId]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }

        return $row === false ? null : $row;
    }

    /** Fails a claimable job whose regeneration budget is closed. The result is not needed, so a failure changes nothing. */
    public function failMiddletermBudgetClosed(int $jobId): void
    {
        try {
            $this->connection()->executeStatement(
                "UPDATE public.core_worker_job SET state='failed',last_error='regeneration_budget_closed'
                 WHERE id = ? AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp()))",
                [$jobId]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
        }
    }

    /** Claims a pending or expired middleterm attempt with a new lease token; false when it was not claimable. Throws when the statement fails. */
    public function claimMiddleterm(int $jobId, string $token): bool
    {
        return $this->connection()->fetchOne(
            "UPDATE public.core_worker_job SET state='running',attempts=attempts+1,
                lease_token = :token,lease_until=LEAST(deadline_at,clock_timestamp()+INTERVAL '15 seconds'),updated_at=clock_timestamp()
             WHERE id = :id AND attempts<3 AND available_at<=clock_timestamp() AND deadline_at>clock_timestamp()
                AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp())) RETURNING id",
            ['id' => $jobId, 'token' => $token]
        ) !== false;
    }

    /**
     * Settles a failed running attempt of this lease: stale when it may be readmitted before its deadline, failed when terminal,
     * out of attempts or past its deadline, else pending for a retry in ten seconds. A statement failure changes nothing.
     */
    public function settleMiddletermAttempt(int $jobId, string $token, bool $readmit, bool $terminal): void
    {
        try {
            $this->connection()->executeStatement(
                "UPDATE public.core_worker_job SET state=CASE WHEN CAST(:readmit AS boolean) AND deadline_at>clock_timestamp() THEN 'stale'
                    WHEN CAST(:terminal AS boolean) OR attempts>=3
                    OR deadline_at<=clock_timestamp() THEN 'failed' ELSE 'pending' END,lease_token=NULL,lease_until=NULL,
                    available_at=clock_timestamp()+INTERVAL '10 seconds',last_error='middleterm_attempt_failed',updated_at=clock_timestamp()
                 WHERE id = :id AND state='running' AND lease_token = :token",
                ['readmit' => $readmit ? 't' : 'f', 'terminal' => $terminal ? 't' : 'f', 'id' => $jobId, 'token' => $token]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
        }
    }

    /** Creates (or re-reads) the one job of a frozen middleterm input and returns its id, or null when no row came back. Throws when the statement fails. */
    public function enqueueMiddleterm(int $inputId, string $turnId, string $payloadJson): ?int
    {
        $id = $this->connection()->fetchOne(
            "INSERT INTO public.core_worker_job(job_type,session_id,request_id,turn_id,generation,
                actor_identity_id,middleterm_input_id,payload,deadline_at)
             SELECT 'middleterm',i.session_id,i.request_id,CAST(:turn AS text),i.generation,i.actor_identity_id,i.id,CAST(:payload AS jsonb),
                COALESCE((SELECT MIN(prior.deadline_at) FROM public.core_worker_job prior JOIN public.core_middleterm_input previous ON previous.id=prior.middleterm_input_id
                    WHERE previous.regeneration_approval_id=i.regeneration_approval_id),clock_timestamp()+INTERVAL '120 seconds')
             FROM public.core_middleterm_input i WHERE i.id = :input ON CONFLICT(middleterm_input_id) WHERE middleterm_input_id IS NOT NULL
             DO UPDATE SET middleterm_input_id=EXCLUDED.middleterm_input_id RETURNING id",
            ['input' => $inputId, 'turn' => $turnId, 'payload' => $payloadJson]
        );

        return $id === false ? null : (int) $id;
    }

    /** Retires middleterm jobs that are past their deadline, out of attempts or no longer owned by a live completed turn. False when the statement fails. */
    public function retireMiddletermJobs(): bool
    {
        try {
            $this->connection()->executeStatement(
                "UPDATE public.core_worker_job j SET state=CASE WHEN deadline_at<=clock_timestamp() OR attempts>=3
                    THEN 'failed' ELSE 'stale' END,lease_token=NULL,lease_until=NULL,last_error='middleterm_owner_expired'
                 WHERE job_type='middleterm' AND state IN ('pending','running') AND (deadline_at<=clock_timestamp()
                    OR (attempts>=3 AND (state='pending' OR lease_until<=clock_timestamp())) OR NOT EXISTS(
                        SELECT 1 FROM public.core_runtime_session r JOIN public.core_request_execution e USING(session_id)
                        WHERE r.session_id=j.session_id AND r.state='active' AND r.active_generation=j.generation
                            AND r.active_turn_id=j.turn_id AND r.active_turn_sequence::text=j.payload->>'context_sequence'
                            AND e.request_id=j.request_id AND e.generation=j.generation AND e.state='complete'))"
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /** The id of the next due middleterm job, or null when none (or the statement fails). */
    public function nextDueMiddleterm(): ?int
    {
        try {
            $id = $this->connection()->fetchOne(
                "SELECT id FROM public.core_worker_job WHERE job_type='middleterm' AND available_at<=clock_timestamp()
                    AND deadline_at>clock_timestamp() AND attempts<3 AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp()))
                 ORDER BY available_at,id LIMIT 1"
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }

        return $id === false ? null : (int) $id;
    }

    /**
     * Picks one active session whose latest completed turn may own a narrative, locking its runtime row (FOR UPDATE SKIP LOCKED) and
     * reserving the next discovery slot in the same statement. Runs inside the caller's open transaction. Null when none; throws
     * when the statement fails.
     *
     * @return array<string, mixed>|null
     */
    public function discoverMiddletermOwner(): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "WITH candidate AS (
                SELECT r.session_id,o.request_id,o.turn_id,o.generation,o.event_type,o.canonical_event,o.event_sha256,
                    r.active_turn_sequence AS context_sequence
                FROM public.core_runtime_session r CROSS JOIN LATERAL (
                    SELECT e.*,p.canonical_event,p.event_sha256 FROM public.core_request_execution e
                    JOIN public.core_protocol_event p USING(session_id,request_id,generation,turn_id,event_type)
                    WHERE e.session_id=r.session_id AND e.generation=r.active_generation AND e.turn_id=r.active_turn_id
                        AND e.state='complete' AND p.event_sha256=e.request_sha256
                        AND p.canonical_event->>'protocol_version'='2'
                        AND p.canonical_event->>'runtime_variant'=r.runtime_variant
                        AND p.canonical_event->>'context_sequence'=r.active_turn_sequence::text
                        AND jsonb_exists(p.canonical_event->'capabilities','dialogue.turn_ownership')
                        AND (e.event_type IN ('input_text','input_audio','trigger','action_result')
                            OR (e.event_type='activate' AND p.canonical_event#>>'{payload,source}'='manual'))
                    ORDER BY e.claimed_at DESC,e.request_id DESC LIMIT 1
                ) o WHERE r.state='active' AND (r.middleterm_dispatch_after IS NULL OR r.middleterm_dispatch_after<=clock_timestamp())
                ORDER BY r.middleterm_dispatch_after NULLS FIRST,r.session_id LIMIT 1 FOR UPDATE OF r SKIP LOCKED
            ), reserved AS (
                UPDATE public.core_runtime_session r SET middleterm_dispatch_after=clock_timestamp()+INTERVAL '30 seconds'
                FROM candidate c WHERE r.session_id=c.session_id RETURNING r.session_id
            ) SELECT c.* FROM candidate c JOIN reserved USING(session_id)"
        );

        return $row === false ? null : $row;
    }

    /** Fails (or, for a cancelled attempt of a regeneration, stales) a job whose frozen input proved invalid, unless another live attempt holds it. A statement failure changes nothing. */
    public function failInvalidMiddletermInput(int $jobId, bool $cancelled): void
    {
        try {
            $this->connection()->executeStatement(
                "UPDATE public.core_worker_job j SET state=CASE WHEN CAST(:cancelled AS boolean)
                    AND EXISTS(SELECT 1 FROM public.core_middleterm_input i WHERE i.id=j.middleterm_input_id AND i.regeneration_approval_id IS NOT NULL)
                    THEN 'stale' ELSE 'failed' END,last_error='middleterm_input_invalid'
                 WHERE id = :id AND job_type='middleterm' AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp()))",
                ['cancelled' => $cancelled ? 't' : 'f', 'id' => $jobId]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
        }
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

    /** The queue scope shared by the capacity UPDATE and count: this actor's pending or running relationship work (:p1 is the actor). */
    private const RELATIONSHIP_QUEUE_SCOPE = "job_type='relationship' AND (relationship_evaluation_id IS NOT NULL OR relationship_interaction_id IS NOT NULL)
                AND state IN ('pending','running') AND (actor_identity_id=:p1 OR relationship_interaction_id IN (
                    SELECT id FROM public.core_relationship_interaction_evaluation WHERE listener_identity_id=:p1))";

    /** Serializes the queue-capacity decision for one actor until the caller's transaction ends. */
    public function lockRelationshipQueue(int $actorId): void
    {
        $this->connection()->executeQuery(
            "SELECT pg_advisory_xact_lock(hashtextextended('synth-relationship-jobs:'||:p1,0))",
            ['p1' => $actorId]
        );
    }

    /** Fails this actor's relationship work whose deadline or retry budget is spent. */
    public function retireExpiredRelationshipQueue(int $actorId): void
    {
        $this->connection()->executeQuery(
            "UPDATE public.core_worker_job SET state='failed',lease_token=NULL,lease_until=NULL,
            last_error='lease_expired',updated_at=clock_timestamp() WHERE ".self::RELATIONSHIP_QUEUE_SCOPE."
            AND (deadline_at<=clock_timestamp() OR (attempts>=3 AND lease_until<=clock_timestamp()))",
            ['p1' => $actorId]
        );
    }

    /** How many relationship jobs of either kind are pending or running for this actor. */
    public function relationshipQueueDepth(int $actorId): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT count(*) AS n FROM public.core_worker_job WHERE '.self::RELATIONSHIP_QUEUE_SCOPE,
            ['p1' => $actorId]
        );
    }

    /** Records a terminal stale job for an evaluation whose frozen input is unavailable, unless it already has a job. */
    public function retireInvalidEvaluation(int $evaluationId, string $sessionId, string $requestId, string $turnId, int $generation,
        int $contextSequence, string $canonicalOwner): void
    {
        $this->connection()->executeQuery(
            "INSERT INTO public.core_worker_job
                (job_type,relationship_evaluation_id,session_id,request_id,turn_id,generation,actor_identity_id,payload,state,last_error,deadline_at)
                SELECT 'relationship',i.id,i.session_id,i.request_id,:p4,i.generation,i.actor_identity_id,
                    jsonb_build_object('context_sequence',i.context_sequence),'stale','input_unavailable',clock_timestamp()
                FROM public.core_relationship_evaluation i
                WHERE i.id=:p1 AND i.session_id=:p2 AND i.request_id=:p3 AND i.generation=:p5 AND i.context_sequence=:p6
                    AND i.input_payload->'owner'=:p7::jsonb
                    AND NOT EXISTS(SELECT 1 FROM public.core_worker_job j WHERE j.relationship_evaluation_id=i.id)",
            ['p1' => $evaluationId, 'p2' => $sessionId, 'p3' => $requestId, 'p4' => $turnId, 'p5' => $generation,
                'p6' => $contextSequence, 'p7' => $canonicalOwner]
        );
    }

    /** Enqueues the evaluation unless it already ended or the actor has no capacity. Returns the new job id, or null. */
    public function enqueueEvaluation(int $evaluationId, string $sessionId, string $requestId, string $turnId, int $generation,
        int|string $actorId, string $payload, bool $hasCapacity): ?int
    {
        $id = $this->connection()->fetchOne(
            "INSERT INTO public.core_worker_job
            (job_type,relationship_evaluation_id,session_id,request_id,turn_id,generation,actor_identity_id,payload,deadline_at)
            SELECT 'relationship',:p1,:p2,:p3,:p4,:p5,:p6,:p7::jsonb,clock_timestamp()+INTERVAL '10 minutes'
            WHERE NOT EXISTS(SELECT 1 FROM public.core_worker_job WHERE relationship_evaluation_id=:p1 AND state IN ('ready','failed','cancelled','stale'))
                AND :p8::boolean
            ON CONFLICT DO NOTHING RETURNING id",
            ['p1' => $evaluationId, 'p2' => $sessionId, 'p3' => $requestId, 'p4' => $turnId, 'p5' => $generation,
                'p6' => $actorId, 'p7' => $payload, 'p8' => $hasCapacity ? 't' : 'f']
        );

        return $id === false ? null : (int) $id;
    }

    /** The pending or running job of this evaluation, or null. */
    public function activeJobForEvaluation(int $evaluationId): ?int
    {
        $id = $this->connection()->fetchOne(
            "SELECT id FROM public.core_worker_job WHERE relationship_evaluation_id=:p1 AND state IN ('pending','running')",
            ['p1' => $evaluationId]
        );

        return $id === false ? null : (int) $id;
    }

    /** The evaluation (as text) this relationship job belongs to in exactly this request, generation, turn and context sequence, or null. */
    public function evaluationOfAttempt(int $jobId, string $sessionId, string $requestId, int $generation, string $turnId, string $contextSequence): ?string
    {
        $id = $this->connection()->fetchOne(
            "SELECT relationship_evaluation_id FROM public.core_worker_job
            WHERE id=:p1 AND job_type='relationship' AND relationship_evaluation_id IS NOT NULL
                AND session_id=:p2 AND request_id=:p3 AND generation=:p4 AND turn_id=:p5 AND payload->>'context_sequence'=:p6",
            ['p1' => $jobId, 'p2' => $sessionId, 'p3' => $requestId, 'p4' => $generation, 'p5' => $turnId, 'p6' => $contextSequence]
        );

        return $id === false ? null : (string) $id;
    }

    /** Marks a claimable evaluation job stale because its frozen input no longer verifies. */
    public function retireInvalidEvaluationJob(int $jobId, string $canonicalOwner): void
    {
        $this->connection()->executeQuery(
            "UPDATE public.core_worker_job j SET state='stale',lease_token=NULL,lease_until=NULL,
                last_error='input_unavailable',updated_at=clock_timestamp() FROM public.core_relationship_evaluation i
                WHERE j.id=:p1 AND j.relationship_evaluation_id=i.id AND i.input_payload->'owner'=:p2::jsonb
                    AND (j.state='pending' OR (j.state='running' AND j.lease_until<=clock_timestamp()))",
            ['p1' => $jobId, 'p2' => $canonicalOwner]
        );
    }

    /** Marks a claimable interaction job stale because its frozen input no longer verifies. */
    public function retireInvalidInteractionJob(int $jobId): void
    {
        $this->connection()->executeQuery(
            "UPDATE public.core_worker_job SET state='stale',lease_token=NULL,lease_until=NULL,
                last_error='input_unavailable',updated_at=clock_timestamp() WHERE id=:p1
                AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp()))",
            ['p1' => $jobId]
        );
    }

    /** Fails this job if its deadline passed or its retries are spent. */
    public function failIfExpired(int $jobId): void
    {
        $this->connection()->executeQuery(
            "UPDATE public.core_worker_job SET state='failed',lease_token=NULL,lease_until=NULL,
            last_error='lease_expired',updated_at=clock_timestamp() WHERE id=:p1 AND state IN ('pending','running')
                AND (deadline_at<=clock_timestamp() OR (attempts>=3 AND lease_until<=clock_timestamp()))",
            ['p1' => $jobId]
        );
    }

    /**
     * Takes a fresh lease on this job if it is claimable; null when it is not.
     *
     * @return array{id: string, lease_token: string, attempts: string}|null
     */
    public function claimAttempt(int $jobId, string $leaseToken): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "UPDATE public.core_worker_job SET state='running',attempts=attempts+1,lease_token=:p2,
            lease_until=LEAST(deadline_at,clock_timestamp()+INTERVAL '2 minutes'),updated_at=clock_timestamp()
            WHERE id=:p1 AND attempts<3 AND deadline_at>clock_timestamp() AND available_at<=clock_timestamp()
                AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp())) RETURNING id,lease_token,attempts",
            ['p1' => $jobId, 'p2' => $leaseToken]
        );

        return self::textRow($row);
    }

    /** Schedules a retry (or fails the job once its budget is spent) for a provider failure under this exact lease. True if it applied. */
    public function failEvaluationAttempt(int $jobId, string $sessionId, string $requestId, int $generation, string $contextSequence, string $leaseToken): bool
    {
        return $this->connection()->fetchOne(
            "UPDATE public.core_worker_job SET state=CASE WHEN attempts>=3 OR deadline_at<=clock_timestamp()
            THEN 'failed' ELSE 'pending' END,lease_token=NULL,lease_until=NULL,available_at=clock_timestamp()+INTERVAL '10 seconds',
            last_error='provider_failed',updated_at=clock_timestamp() WHERE id=:p1 AND job_type='relationship' AND relationship_evaluation_id IS NOT NULL
                AND session_id=:p2 AND request_id=:p3 AND generation=:p4 AND payload->>'context_sequence'=:p5
                AND state='running' AND lease_token=:p6 AND lease_until>clock_timestamp() RETURNING id",
            ['p1' => $jobId, 'p2' => $sessionId, 'p3' => $requestId, 'p4' => $generation, 'p5' => $contextSequence, 'p6' => $leaseToken]
        ) !== false;
    }

    /**
     * The job's evaluation, state, lease token and whether its lease is still live, for exactly this request and turn.
     *
     * @return array{relationship_evaluation_id: string, state: string, lease_token: string|null, live: string}|null
     */
    public function evaluationCompletionState(int $jobId, string $sessionId, string $requestId, int $generation, string $turnId, string $contextSequence): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT relationship_evaluation_id,state,lease_token,
            (lease_until>clock_timestamp() AND deadline_at>clock_timestamp()) AS live FROM public.core_worker_job
            WHERE id=:p1 AND job_type='relationship' AND relationship_evaluation_id IS NOT NULL AND session_id=:p2
                AND request_id=:p3 AND generation=:p4 AND turn_id=:p5 AND payload->>'context_sequence'=:p6",
            ['p1' => $jobId, 'p2' => $sessionId, 'p3' => $requestId, 'p4' => $generation, 'p5' => $turnId, 'p6' => $contextSequence]
        );

        return self::textRow($row);
    }

    /** Marks the job ready if it still runs under this live lease. True when it did. */
    public function markReady(int $jobId, string $leaseToken): bool
    {
        return $this->connection()->fetchOne(
            "UPDATE public.core_worker_job SET state='ready',lease_until=NULL,updated_at=clock_timestamp()
            WHERE id=:p1 AND state='running' AND lease_token=:p2 AND lease_until>clock_timestamp() AND deadline_at>clock_timestamp() RETURNING id",
            ['p1' => $jobId, 'p2' => $leaseToken]
        ) !== false;
    }

    /**
     * Enqueues the frozen NPC interaction as pending, or as stale with its error when its input is unavailable; nothing is
     * enqueued without capacity.
     */
    public function enqueueInteraction(int $interactionId, string $sessionId, string $requestId, string $turnId, int $generation,
        int $contextSequence, string $canonicalOwner, string $state, ?string $lastError, bool $hasCapacity): void
    {
        $this->connection()->executeQuery(
            "INSERT INTO public.core_worker_job
            (job_type,relationship_interaction_id,session_id,request_id,turn_id,generation,actor_identity_id,payload,state,last_error,deadline_at)
            SELECT 'relationship',i.id,i.session_id,i.request_id,:p4,i.generation,i.speaker_identity_id,
                jsonb_build_object('context_sequence',i.context_sequence),:p8,:p9,clock_timestamp()+INTERVAL '10 minutes'
            FROM public.core_relationship_interaction_evaluation i
            WHERE i.id=:p1 AND i.session_id=:p2 AND i.request_id=:p3 AND i.generation=:p5 AND i.context_sequence=:p6
                AND i.input_payload->'owner'=:p7::jsonb AND :p10::boolean
            ON CONFLICT DO NOTHING RETURNING id",
            ['p1' => $interactionId, 'p2' => $sessionId, 'p3' => $requestId, 'p4' => $turnId, 'p5' => $generation,
                'p6' => $contextSequence, 'p7' => $canonicalOwner, 'p8' => $state, 'p9' => $lastError, 'p10' => $hasCapacity ? 't' : 'f']
        );
    }

    /** The pending or running job of this interaction, or null. */
    public function activeJobForInteraction(int $interactionId): ?int
    {
        $id = $this->connection()->fetchOne(
            "SELECT id FROM public.core_worker_job WHERE relationship_interaction_id=:p1 AND state IN ('pending','running')",
            ['p1' => $interactionId]
        );

        return $id === false ? null : (int) $id;
    }

    /** The interaction (as text) this job belongs to in exactly this request, turn and context sequence and owner, or null. */
    public function interactionOfAttempt(int $jobId, string $sessionId, string $requestId, int $generation, string $turnId,
        string $contextSequence, string $canonicalOwner): ?string
    {
        $id = $this->connection()->fetchOne(
            "SELECT j.relationship_interaction_id FROM public.core_worker_job j
            JOIN public.core_relationship_interaction_evaluation i ON i.id=j.relationship_interaction_id
                AND i.session_id=j.session_id AND i.request_id=j.request_id AND i.generation=j.generation
                AND i.context_sequence::text=j.payload->>'context_sequence' AND i.speaker_identity_id=j.actor_identity_id
            WHERE j.id=:p1 AND j.job_type='relationship' AND j.relationship_evaluation_id IS NULL AND j.memory_batch_id IS NULL
                AND j.session_id=:p2 AND j.request_id=:p3 AND j.generation=:p4 AND j.turn_id=:p5 AND j.payload->>'context_sequence'=:p6
                AND i.input_payload->'owner'=:p7::jsonb",
            ['p1' => $jobId, 'p2' => $sessionId, 'p3' => $requestId, 'p4' => $generation, 'p5' => $turnId,
                'p6' => $contextSequence, 'p7' => $canonicalOwner]
        );

        return $id === false ? null : (string) $id;
    }

    /** Schedules a retry (or fails the job once its budget is spent) for an interaction provider failure under this exact lease. True if it applied. */
    public function failInteractionAttempt(int $jobId, string $sessionId, string $requestId, int $generation, string $turnId,
        string $contextSequence, string $leaseToken): bool
    {
        return $this->connection()->fetchOne(
            "UPDATE public.core_worker_job SET state=CASE WHEN attempts>=3 OR deadline_at<=clock_timestamp()
            THEN 'failed' ELSE 'pending' END,lease_token=NULL,lease_until=NULL,available_at=clock_timestamp()+INTERVAL '10 seconds',
            last_error='provider_failed',updated_at=clock_timestamp() WHERE id=:p1 AND job_type='relationship'
            AND relationship_interaction_id IS NOT NULL AND relationship_evaluation_id IS NULL AND memory_batch_id IS NULL
            AND session_id=:p2 AND request_id=:p3 AND generation=:p4 AND turn_id=:p5 AND payload->>'context_sequence'=:p6
            AND state='running' AND lease_token=:p7 AND lease_until>clock_timestamp() RETURNING id",
            ['p1' => $jobId, 'p2' => $sessionId, 'p3' => $requestId, 'p4' => $generation, 'p5' => $turnId,
                'p6' => $contextSequence, 'p7' => $leaseToken]
        ) !== false;
    }

    /**
     * The interaction job's state, lease and whether it is live, joined to its frozen evaluation row, for exactly this
     * request, turn and owner; null when nothing matches.
     */
    public function interactionCompletionState(int $jobId, string $sessionId, string $requestId, int $generation, string $turnId,
        string $contextSequence, string $canonicalOwner): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT j.state,j.lease_token,
                (j.lease_until>clock_timestamp() AND j.deadline_at>clock_timestamp()) AS live,i.*
            FROM public.core_worker_job j JOIN public.core_relationship_interaction_evaluation i ON i.id=j.relationship_interaction_id
                AND i.session_id=j.session_id AND i.request_id=j.request_id AND i.generation=j.generation
                AND i.context_sequence::text=j.payload->>'context_sequence' AND i.speaker_identity_id=j.actor_identity_id
            WHERE j.id=:p1 AND j.job_type='relationship' AND j.relationship_evaluation_id IS NULL AND j.memory_batch_id IS NULL
                AND j.session_id=:p2 AND j.request_id=:p3 AND j.generation=:p4 AND j.turn_id=:p5 AND j.payload->>'context_sequence'=:p6
                AND i.input_payload->'owner'=:p7::jsonb
                AND i.input_sha256=encode(sha256(convert_to(i.input_payload::text,'UTF8')),'hex')",
            ['p1' => $jobId, 'p2' => $sessionId, 'p3' => $requestId, 'p4' => $generation, 'p5' => $turnId,
                'p6' => $contextSequence, 'p7' => $canonicalOwner]
        );

        return self::textRow($row);
    }

    /** Cancels this session's pending evaluation work that no longer belongs to the active owner (gift jobs are kept). */
    public function cancelObsoleteEvaluationWork(string $sessionId, int $generation, string $turnId, string $contextSequence): void
    {
        $this->connection()->executeQuery(
            "UPDATE public.core_worker_job j SET state='cancelled',lease_token=NULL,lease_until=NULL,
                last_error='owner_unavailable',updated_at=clock_timestamp() WHERE job_type='relationship' AND relationship_evaluation_id IS NOT NULL
                    AND session_id=:p1 AND state IN ('pending','running')
                    AND payload->>'schema' IS DISTINCT FROM 'synth.relationship.gift-job.v1'
                    AND (generation<>:p2 OR turn_id<>:p3 OR payload->>'context_sequence' IS DISTINCT FROM :p4
                        OR memory_batch_id IS NOT NULL OR actor_identity_id IS DISTINCT FROM(SELECT target_identity_id FROM public.core_runtime_context
                            WHERE session_id=:p1 AND generation=:p2 AND snapshot_sequence::text=:p4)
                        OR NOT EXISTS(SELECT 1 FROM public.core_request_execution e
                            JOIN public.core_protocol_event p ON p.session_id=e.session_id AND p.request_id=e.request_id AND p.event_sha256=e.request_sha256
                            JOIN public.core_relationship_evaluation i ON i.id=j.relationship_evaluation_id AND i.input_payload->'owner'=p.canonical_event
                            WHERE e.session_id=j.session_id AND e.request_id=j.request_id
                                AND e.generation=j.generation AND e.turn_id=j.turn_id AND e.state='complete'))",
            ['p1' => $sessionId, 'p2' => $generation, 'p3' => $turnId, 'p4' => $contextSequence]
        );
    }

    /** Fails this session's pending or running evaluation jobs whose deadline or retry budget is spent. */
    public function failExpiredEvaluationWork(string $sessionId): void
    {
        $this->connection()->executeQuery(
            "UPDATE public.core_worker_job SET state='failed',lease_token=NULL,lease_until=NULL,
                last_error='lease_expired',updated_at=clock_timestamp() WHERE job_type='relationship' AND relationship_evaluation_id IS NOT NULL
                    AND session_id=:p1 AND state IN ('pending','running')
                    AND (deadline_at<=clock_timestamp() OR (attempts>=3 AND lease_until<=clock_timestamp()))",
            ['p1' => $sessionId]
        );
    }

    /** The next evaluation job of this turn that is ready to run or retry, or null (the player lane only sees player evaluations). */
    public function nextEvaluationRetry(string $sessionId, int $generation, string $turnId, string $contextSequence, bool $playerOnly): ?int
    {
        $inputScope = $playerOnly ? "AND relationship_evaluation_id IN (SELECT id FROM public.core_relationship_evaluation
                WHERE input_payload->>'schema'='synth.relationship.evaluation.v2')" : '';
        $id = $this->connection()->fetchOne(
            "SELECT id FROM public.core_worker_job
                WHERE job_type='relationship' AND relationship_evaluation_id IS NOT NULL AND memory_batch_id IS NULL
                    AND session_id=:p1 AND generation=:p2 AND turn_id=:p3 AND payload->>'context_sequence'=:p4 $inputScope
                    AND available_at<=clock_timestamp() AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp()))
                ORDER BY available_at,id LIMIT 1",
            ['p1' => $sessionId, 'p2' => $generation, 'p3' => $turnId, 'p4' => $contextSequence]
        );

        return $id === false ? null : (int) $id;
    }

    /**
     * Cancels this session's pending interaction work that no longer belongs to the active owner. $responderPredicate is the
     * reader-supplied SQL fragment (never client text) that proves the speaker still answers for the frozen request.
     */
    public function cancelObsoleteInteractionWork(string $sessionId, int $generation, string $turnId, string $contextSequence, string $responderPredicate): void
    {
        $this->connection()->executeQuery(
            "UPDATE public.core_worker_job j SET state='cancelled',lease_token=NULL,lease_until=NULL,
                last_error='owner_unavailable',updated_at=clock_timestamp() WHERE job_type='relationship' AND relationship_interaction_id IS NOT NULL
                    AND session_id=:p1 AND state IN ('pending','running') AND (generation<>:p2 OR turn_id<>:p3 OR payload->>'context_sequence' IS DISTINCT FROM :p4
                        OR relationship_evaluation_id IS NOT NULL OR memory_batch_id IS NOT NULL
                        OR NOT EXISTS(SELECT 1 FROM public.core_relationship_interaction_evaluation i
                            JOIN public.core_request_execution e ON e.session_id=i.session_id AND e.request_id=i.request_id
                                AND e.generation=i.generation AND e.turn_id=j.turn_id AND e.state='complete'
                            JOIN public.core_protocol_event p ON p.session_id=e.session_id AND p.request_id=e.request_id
                                AND p.generation=e.generation AND p.turn_id=e.turn_id AND p.event_type=e.event_type AND p.event_sha256=e.request_sha256
                            WHERE i.id=j.relationship_interaction_id AND i.session_id=j.session_id AND i.request_id=j.request_id
                                AND i.generation=j.generation AND i.context_sequence::text=j.payload->>'context_sequence'
                                AND i.speaker_identity_id=j.actor_identity_id AND i.input_payload->'owner'=p.canonical_event
                                AND ".$responderPredicate."))",
            ['p1' => $sessionId, 'p2' => $generation, 'p3' => $turnId, 'p4' => $contextSequence]
        );
    }

    /** Fails this session's pending or running interaction jobs whose deadline or retry budget is spent. */
    public function failExpiredInteractionWork(string $sessionId): void
    {
        $this->connection()->executeQuery(
            "UPDATE public.core_worker_job SET state='failed',lease_token=NULL,lease_until=NULL,
                last_error='lease_expired',updated_at=clock_timestamp() WHERE relationship_interaction_id IS NOT NULL
                    AND session_id=:p1 AND state IN ('pending','running')
                    AND (deadline_at<=clock_timestamp() OR (attempts>=3 AND lease_until<=clock_timestamp()))",
            ['p1' => $sessionId]
        );
    }

    /** The next interaction job of this turn that is ready to run or retry, or null. */
    public function nextInteractionRetry(string $sessionId, int $generation, string $turnId, string $contextSequence): ?int
    {
        $id = $this->connection()->fetchOne(
            "SELECT id FROM public.core_worker_job WHERE job_type='relationship'
                AND relationship_interaction_id IS NOT NULL AND relationship_evaluation_id IS NULL AND memory_batch_id IS NULL
                AND session_id=:p1 AND generation=:p2 AND turn_id=:p3 AND payload->>'context_sequence'=:p4
                AND available_at<=clock_timestamp() AND (state='pending' OR (state='running' AND lease_until<=clock_timestamp()))
                ORDER BY available_at,id LIMIT 1",
            ['p1' => $sessionId, 'p2' => $generation, 'p3' => $turnId, 'p4' => $contextSequence]
        );

        return $id === false ? null : (int) $id;
    }

    /**
     * The origin of a pending or running persisted evaluation job whose owner request is still the active completed one;
     * null when the job is not runnable.
     *
     * @return array<string, string|null>|null
     */
    public function persistedEvaluationOrigin(int $jobId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT j.session_id,j.request_id,j.turn_id,j.generation,
            j.payload->>'context_sequence' AS context_sequence,p.event_type,p.canonical_event,p.event_sha256,
            (j.payload->>'schema'='synth.relationship.gift-job.v1') AS retained_gift
        FROM public.core_worker_job j
        JOIN public.core_request_execution e ON e.session_id=j.session_id AND e.request_id=j.request_id
            AND e.generation=j.generation AND e.turn_id=j.turn_id AND e.state='complete'
        JOIN public.core_protocol_event p ON p.session_id=e.session_id AND p.request_id=e.request_id
            AND p.generation=e.generation AND p.turn_id=e.turn_id AND p.event_type=e.event_type AND p.event_sha256=e.request_sha256
        JOIN public.core_runtime_session r ON r.session_id=j.session_id AND r.active_generation=j.generation
            AND r.state='active' AND p.canonical_event->>'runtime_variant'=r.runtime_variant
        JOIN public.core_runtime_context c ON c.session_id=j.session_id AND c.generation=j.generation
            AND c.snapshot_sequence::text=j.payload->>'context_sequence' AND c.playthrough_id=r.playthrough_id
            AND c.target_identity_id=j.actor_identity_id
        WHERE j.id=:p1 AND j.job_type='relationship' AND j.relationship_evaluation_id IS NOT NULL
            AND j.memory_batch_id IS NULL AND j.state IN ('pending','running')",
            ['p1' => $jobId]
        );

        return self::textRow($row);
    }

    /**
     * The origin of a pending or running persisted NPC interaction job whose owner request is still the active completed one.
     * $responderPredicate is the reader-supplied SQL fragment (never client text) that proves the speaker answers for it.
     *
     * @return array<string, string|null>|null
     */
    public function persistedInteractionOrigin(int $jobId, string $responderPredicate): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT j.session_id,j.request_id,j.turn_id,j.generation,
            j.payload->>'context_sequence' AS context_sequence,p.event_type,p.canonical_event,p.event_sha256
        FROM public.core_worker_job j JOIN public.core_relationship_interaction_evaluation i ON i.id=j.relationship_interaction_id
            AND i.session_id=j.session_id AND i.request_id=j.request_id AND i.generation=j.generation
            AND i.context_sequence::text=j.payload->>'context_sequence' AND i.speaker_identity_id=j.actor_identity_id
        JOIN public.core_request_execution e ON e.session_id=j.session_id AND e.request_id=j.request_id
            AND e.generation=j.generation AND e.turn_id=j.turn_id AND e.state='complete'
        JOIN public.core_protocol_event p ON p.session_id=e.session_id AND p.request_id=e.request_id
            AND p.generation=e.generation AND p.turn_id=e.turn_id AND p.event_type=e.event_type AND p.event_sha256=e.request_sha256
            AND p.canonical_event=i.input_payload->'owner'
        JOIN public.core_runtime_session r ON r.session_id=j.session_id AND r.active_generation=j.generation
            AND r.state='active' AND p.canonical_event->>'runtime_variant'=r.runtime_variant
        JOIN public.core_runtime_context c ON c.session_id=j.session_id AND c.generation=j.generation
            AND c.snapshot_sequence=i.context_sequence AND c.playthrough_id=r.playthrough_id
        WHERE j.id=:p1 AND j.job_type='relationship' AND j.relationship_evaluation_id IS NULL
            AND j.memory_batch_id IS NULL AND j.state IN ('pending','running')
            AND ".$responderPredicate,
            ['p1' => $jobId]
        );

        return self::textRow($row);
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
