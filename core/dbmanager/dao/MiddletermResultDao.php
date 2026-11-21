<?php

// Reads core_middleterm_result. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMiddletermResult;

/** Finds rows of core_middleterm_result. */
final class MiddletermResultDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $historyEventId): ?AbstractMiddletermResult
    {
        return $this->fresh()->find(AbstractMiddletermResult::class, $historyEventId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMiddletermResult> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMiddletermResult::class)->findBy([], ['historyEventId' => 'ASC'], max(1, $limit)));
    }

    /**
     * The latest visible narrative (id, effective text, content revision), read through the caller's middleterm history CTE
     * ($cte ends with a space). Null when there is none. Throws when the statement fails.
     *
     * @return array<string, mixed>|null
     */
    public function latestNarrative(string $cte): ?array
    {
        $cte = JsonbOperatorSql::plain($cte);
        $row = $this->connection()->fetchAssociative(
            $cte.' SELECT history_event_id,effective_narrative AS narrative,content_revision FROM middleterm_latest'
        );

        return $row === false ? null : $row;
    }

    /** True when a result of this actor in this session and generation was published after the given history event. Throws when the statement fails. */
    public function publishedAfter(string $sessionId, int $generation, int $actorId, int $historyEventId): bool
    {
        return $this->connection()->fetchOne(
            'SELECT 1 FROM public.core_middleterm_input i JOIN public.core_middleterm_result r ON r.input_id=i.id
             WHERE i.session_id = ? AND i.generation = ? AND i.actor_identity_id = ? AND r.history_event_id > ? LIMIT 1',
            [$sessionId, $generation, $actorId, $historyEventId]
        ) !== false;
    }

    /** True when the saved narrative is owned and visible through the caller's middleterm history CTE (false when the statement fails). */
    public function ownedVisible(string $cte, int $historyEventId): bool
    {
        $cte = JsonbOperatorSql::plain($cte);
        try {
            return $this->connection()->fetchOne(
                $cte.' SELECT history_event_id FROM middleterm_owned WHERE history_event_id = ?',
                [$historyEventId]
            ) !== false;
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }
    }

    /**
     * Publishes the narrative of a running attempt in one statement: marks its job ready (only under the live lease), writes the
     * context event, history event, result and, for a regeneration, the replacement. Returns the new history event id, or null when
     * the lease was lost. Throws when the statement fails.
     */
    public function publish(int $jobId, string $token, string $playthroughId, int $gameTime, int $now, string $sessionId, int $generation, int $sequence, string $requestId, string $inputSha256, string $narrative, int $inputId, ?int $approvalId): ?int
    {
        $id = $this->connection()->fetchOne(
            "WITH accepted AS (
                UPDATE public.core_worker_job SET state='ready',lease_token=NULL,lease_until=NULL,updated_at=clock_timestamp()
                WHERE id = :job AND state='running' AND lease_token = :token AND lease_until>clock_timestamp() AND deadline_at>clock_timestamp() RETURNING id
            ), publication AS (
                INSERT INTO public.core_context_event(type,data,sess,gamets,localts,ts,people,location,party,delivery_state,synth_session_id,synth_generation,synth_context_sequence,synth_request_id)
                SELECT 'middleterm_result','Accepted native narrative memory',CAST(:playthrough AS text),:gametime,:now,:now,'','','','derived',CAST(:session AS text),:generation,:sequence,CAST(:request AS text) FROM accepted RETURNING *
            ), history AS (
                INSERT INTO public.core_history_event(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
                SELECT synth_session_id,synth_generation,synth_context_sequence,synth_request_id,CAST(:playthrough AS text),rowid,to_jsonb(publication)
                    ||jsonb_build_object('middleterm_input_sha256',CAST(:inputsha AS text),'middleterm_narrative',CAST(:narrative AS text),
                        'middleterm_result_sha256',encode(sha256(convert_to(CAST(:narrative AS text),'UTF8')),'hex')) FROM publication RETURNING id
            ), result AS (
                INSERT INTO public.core_middleterm_result(history_event_id,input_id,narrative,result_sha256)
                SELECT id,:input,CAST(:narrative AS text),encode(sha256(convert_to(CAST(:narrative AS text),'UTF8')),'hex') FROM history RETURNING history_event_id
            ), replacement AS (
                INSERT INTO public.core_middleterm_replacement(history_event_id,approval_id,target_history_event_id,session_id,generation)
                SELECT result.history_event_id,approval.history_event_id,approval.narrative_id,CAST(:session AS text),:generation
                FROM result JOIN public.core_middleterm_regeneration_approval approval ON approval.history_event_id=CAST(:approval AS bigint)
                RETURNING history_event_id
            ) SELECT result.history_event_id FROM result WHERE CAST(:approval AS bigint) IS NULL
                OR EXISTS(SELECT 1 FROM replacement WHERE replacement.history_event_id=result.history_event_id)",
            ['job' => $jobId, 'token' => $token, 'playthrough' => $playthroughId, 'gametime' => $gameTime, 'now' => $now, 'session' => $sessionId,
                'generation' => $generation, 'sequence' => $sequence, 'request' => $requestId, 'inputsha' => $inputSha256,
                'narrative' => $narrative, 'input' => $inputId, 'approval' => $approvalId]
        );

        return $id === false ? null : (int) $id;
    }
}
