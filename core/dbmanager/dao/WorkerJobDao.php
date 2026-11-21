<?php

// Reads core_worker_job. Writes stay with the statements that own them. The lease checks are plain reads (no lock).

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractWorkerJob;

/** Finds rows of core_worker_job. */
final class WorkerJobDao
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

    /** Rows ordered by primary key, at most $limit. @return list<AbstractWorkerJob> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractWorkerJob::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
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
}
