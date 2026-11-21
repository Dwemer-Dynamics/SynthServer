<?php

// Reads core_middleterm_regeneration_approval. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMiddletermRegenerationApproval;

/** Finds rows of core_middleterm_regeneration_approval. */
final class MiddletermRegenerationApprovalDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $historyEventId): ?AbstractMiddletermRegenerationApproval
    {
        return $this->fresh()->find(AbstractMiddletermRegenerationApproval::class, $historyEventId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMiddletermRegenerationApproval> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMiddletermRegenerationApproval::class)->findBy([], ['historyEventId' => 'ASC'], max(1, $limit)));
    }

    /** Takes the transaction-scoped advisory lock of one session (shared with claim, cancel and outcome). False when the statement fails. */
    public function lockSession(string $sessionId): bool
    {
        try {
            $this->connection()->fetchAssociative('SELECT pg_advisory_xact_lock(hashtext(?))', [$sessionId]);
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /**
     * Up to twenty approvals of this actor that are neither superseded nor already replaced, least recently considered first.
     * Throws when the statement fails.
     *
     * @return list<array<string, mixed>>
     */
    public function discoverCandidates(string $sessionId, int $generation, int $actorId): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT q.history_event_id
             FROM public.core_middleterm_regeneration_approval q
             WHERE q.session_id = :session AND q.generation = :generation
                AND q.actor_identity_id = :actor AND NOT EXISTS(SELECT 1 FROM public.core_middleterm_regeneration_approval newer
                    WHERE newer.session_id=q.session_id AND newer.generation=q.generation AND newer.narrative_id=q.narrative_id
                        AND newer.actor_identity_id=q.actor_identity_id AND newer.history_event_id>q.history_event_id)
                AND NOT EXISTS(SELECT 1 FROM public.core_middleterm_replacement done WHERE done.approval_id=q.history_event_id)
             ORDER BY q.last_considered_at NULLS FIRST,q.history_event_id LIMIT 20',
            ['session' => $sessionId, 'generation' => $generation, 'actor' => $actorId]
        );
    }

    /** Stamps the approvals just considered so bounded discovery rotates. False when the statement fails. @param list<int> $historyEventIds */
    public function markConsidered(array $historyEventIds): bool
    {
        if ($historyEventIds === []) {
            return true;
        }
        $marks = implode(',', array_fill(0, count($historyEventIds), '?'));
        try {
            $this->connection()->executeStatement(
                'UPDATE public.core_middleterm_regeneration_approval SET last_considered_at=clock_timestamp() WHERE history_event_id IN ('.$marks.')',
                array_map('intval', array_values($historyEventIds))
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /**
     * The saved approval of this confirmation token in this scope, with whether its stored scope equals $scopeJson, read through the
     * caller's approval-receipts CTE ($cte ends with a space). Null when none. Throws when the statement fails.
     *
     * @return array<string, mixed>|null
     */
    public function findReplay(string $cte, string $sessionId, int|string $generation, int|string $actorId, string $scopeJson, string $token): ?array
    {
        $cte = JsonbOperatorSql::plain($cte);
        $row = $this->connection()->fetchAssociative(
            $cte." SELECT q.history_event_id,
                q.approval_payload->'scope' = CAST(:scope AS jsonb) AS same_scope FROM regeneration_receipts q
                WHERE q.session_id = :session AND q.generation = :generation AND q.actor_identity_id = :actor AND q.approval_token = :token",
            ['scope' => $scopeJson, 'session' => $sessionId, 'generation' => $generation, 'actor' => $actorId, 'token' => $token]
        );

        return $row === false ? null : $row;
    }

    /**
     * Appends the approval, its context event and its history event in one statement and returns the new history event id (null when
     * no row came back). Throws when the statement fails.
     */
    public function record(string $payloadJson, string $playthroughId, int $now, string $sessionId, int|string $generation, int|string $sequence, int|string $actorId, int|string $narrativeId, string $token): ?string
    {
        $id = $this->connection()->fetchOne(
            "WITH value AS (SELECT CAST(:payload AS jsonb) AS payload), publication AS (
                INSERT INTO public.core_context_event(type,data,sess,gamets,localts,ts,people,location,party,delivery_state,
                    synth_session_id,synth_generation,synth_context_sequence,synth_request_id)
                VALUES('middleterm_regeneration','Confirmed native narrative regeneration',CAST(:playthrough AS text),0,:now,:now,'','','','derived',CAST(:session AS text),:generation,:sequence,NULL) RETURNING *
            ), history AS (
                INSERT INTO public.core_history_event(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
                SELECT synth_session_id,synth_generation,synth_context_sequence,NULL,CAST(:playthrough AS text),rowid,to_jsonb(publication)
                    ||jsonb_build_object('middleterm_regeneration',value.payload,'middleterm_regeneration_sha256',
                        encode(sha256(convert_to(value.payload::text,'UTF8')),'hex')) FROM publication CROSS JOIN value RETURNING id
            ) INSERT INTO public.core_middleterm_regeneration_approval(history_event_id,session_id,generation,context_sequence,
                actor_identity_id,playthrough_id,narrative_id,approval_token,approval_payload,approval_sha256)
                SELECT history.id,CAST(:session AS text),:generation,:sequence,:actor,CAST(:playthrough AS text),:narrative,:token,value.payload,encode(sha256(convert_to(value.payload::text,'UTF8')),'hex')
                FROM history CROSS JOIN value RETURNING history_event_id",
            ['payload' => $payloadJson, 'playthrough' => $playthroughId, 'now' => $now, 'session' => $sessionId, 'generation' => $generation,
                'sequence' => $sequence, 'actor' => $actorId, 'narrative' => $narrativeId, 'token' => $token]
        );

        return $id === false ? null : (string) $id;
    }

    /**
     * One valid approval receipt of this actor with its context cutoff and superseded flag, restricted to the active session of this
     * runtime variant at the given snapshot, read through the caller's approval-receipts CTE. Null when none (or the statement fails).
     *
     * @return array<string, mixed>|null
     */
    public function approvedReceipt(string $cte, int $approvalId, int $actorId, string $runtimeVariant, string $sessionId, int $generation, int $sequence): ?array
    {
        $cte = JsonbOperatorSql::plain($cte);
        try {
            $row = $this->connection()->fetchAssociative(
                $cte." SELECT q.*,c.history_event_id AS cutoff,
                    EXISTS(SELECT 1 FROM public.core_middleterm_regeneration_approval newer
                        WHERE newer.session_id=q.session_id AND newer.generation=q.generation AND newer.actor_identity_id=q.actor_identity_id
                            AND newer.narrative_id=q.narrative_id AND newer.history_event_id>q.history_event_id) AS superseded
                    FROM regeneration_receipts q JOIN public.core_runtime_context c ON c.session_id=q.session_id
                        AND c.generation=q.generation AND c.playthrough_id=q.playthrough_id
                    JOIN public.core_runtime_session r ON r.session_id=c.session_id AND r.active_generation=c.generation AND r.state='active'
                        AND r.playthrough_id=c.playthrough_id AND r.runtime_variant = :variant
                    WHERE q.history_event_id = :approval AND q.actor_identity_id = :actor
                        AND q.session_id = :session AND q.generation = :generation AND c.snapshot_sequence = :sequence",
                ['variant' => $runtimeVariant, 'approval' => $approvalId, 'actor' => $actorId, 'session' => $sessionId,
                    'generation' => $generation, 'sequence' => $sequence]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }

        return $row === false ? null : $row;
    }

    /** Whether the plan stored in an approval payload equals the freshly built plan (jsonb equality); null when the statement fails. */
    public function planMatches(string $approvalPayloadJson, string $planJson): ?bool
    {
        try {
            $row = $this->connection()->fetchAssociative(
                "SELECT CAST(? AS jsonb)->'plan' = CAST(? AS jsonb) AS matches",
                [$approvalPayloadJson, $planJson]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }

        return $row === false ? null : $row['matches'] === 't';
    }

    /**
     * The latest approval of this narrative with its validity, replacement, job and owner state, read through the caller's
     * approval-receipts CTE. Null when none (or the statement fails).
     *
     * @return array<string, mixed>|null
     */
    public function statusRow(string $cte, string $sessionId, int $generation, int $actorId, int $narrativeId): ?array
    {
        $cte = JsonbOperatorSql::plain($cte);
        try {
            $row = $this->connection()->fetchAssociative(
                $cte." SELECT raw.history_event_id AS approval_id,
                    valid.history_event_id IS NOT NULL AS valid,valid.context_sequence,valid.approval_token,
                    valid.approval_payload#>>'{plan,plan_sha256}' AS plan_sha256,
                    replacement.history_event_id AS publication_id,
                    replacement.target_history_event_id=raw.narrative_id AND replacement.session_id=raw.session_id
                        AND replacement.generation=raw.generation AND job.state='ready'
                        AND EXISTS(SELECT 1 FROM public.core_middleterm_result result JOIN public.core_middleterm_input input ON input.id=result.input_id
                            JOIN public.core_history_event history ON history.id=result.history_event_id AND history.session_id=input.session_id
                                AND history.generation=input.generation AND history.request_id=input.request_id AND history.context_sequence=input.context_sequence
                                AND history.playthrough_id=input.playthrough_id
                            WHERE result.history_event_id=replacement.history_event_id AND input.id=job.middleterm_input_id
                                AND input.regeneration_approval_id=raw.history_event_id AND input.actor_identity_id=raw.actor_identity_id
                                AND input.session_id=raw.session_id AND input.generation=raw.generation AND input.playthrough_id=raw.playthrough_id
                                AND history.event_payload->>'middleterm_narrative'=result.narrative
                                AND history.event_payload->>'middleterm_result_sha256'=result.result_sha256
                                AND result.result_sha256=encode(sha256(convert_to(result.narrative,'UTF8')),'hex')) AS publication_recorded,
                    job.state AS job_state,job.attempts AS job_attempts,job.lease_until>clock_timestamp() AS lease_live,
                    job.deadline_at>clock_timestamp() AS deadline_live,
                    session.active_turn_id=job.turn_id AND session.active_turn_sequence::text=job.payload->>'context_sequence'
                        AND execution.state='complete' AS owner_live
                    FROM public.core_middleterm_regeneration_approval raw LEFT JOIN regeneration_receipts valid ON valid.history_event_id=raw.history_event_id
                    LEFT JOIN public.core_middleterm_replacement replacement ON replacement.approval_id=raw.history_event_id
                    LEFT JOIN LATERAL (SELECT j.* FROM public.core_middleterm_input i JOIN public.core_worker_job j ON j.middleterm_input_id=i.id
                        WHERE i.regeneration_approval_id=raw.history_event_id AND j.job_type='middleterm' ORDER BY j.id DESC LIMIT 1) job ON true
                    LEFT JOIN public.core_runtime_session session ON session.session_id=job.session_id AND session.active_generation=job.generation AND session.state='active'
                    LEFT JOIN public.core_request_execution execution ON execution.session_id=job.session_id AND execution.request_id=job.request_id
                        AND execution.generation=job.generation AND execution.turn_id=job.turn_id
                    WHERE raw.session_id = :session AND raw.generation = :generation
                        AND raw.actor_identity_id = :actor AND raw.narrative_id = :narrative ORDER BY raw.history_event_id DESC LIMIT 1",
                ['session' => $sessionId, 'generation' => $generation, 'actor' => $actorId, 'narrative' => $narrativeId]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }

        return $row === false ? null : $row;
    }
}
