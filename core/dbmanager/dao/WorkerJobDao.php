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
}
