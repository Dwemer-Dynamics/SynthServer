<?php

// Reads core_diary_result. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractDiaryResult;

/** Finds rows of core_diary_result. */
final class DiaryResultDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $historyEventId): ?AbstractDiaryResult
    {
        return $this->fresh()->find(AbstractDiaryResult::class, $historyEventId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractDiaryResult> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractDiaryResult::class)->findBy([], ['historyEventId' => 'ASC'], max(1, $limit)));
    }

    /**
     * The newest valid diary entry of a visible chain: the latest automatic-reason one ($automaticOnly, with content_revision 0) or the
     * latest recallable one. $diaryCte is synth_diary_history_cte() (ending in a space). Null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public function latestVisible(string $diaryCte, bool $automaticOnly): ?array
    {
        $diaryCte = JsonbOperatorSql::plain($diaryCte);
        $row = $this->connection()->fetchAssociative(
            $diaryCte.' SELECT history_event_id,result_payload,published_at,'.($automaticOnly ? '0' : 'content_revision').' AS content_revision FROM '
            .($automaticOnly ? 'diary_chain' : 'native_diaries').($automaticOnly ? " WHERE reason IN ('sleep','wait')" : '').' ORDER BY history_event_id DESC LIMIT 1'
        );

        return $row === false ? null : $row;
    }

    /** The accepted result of one input with whether its hash still matches; null when none. @return array<string, mixed>|null */
    public function resultForInput(int $inputId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT history_event_id,result_payload,
                result_sha256=encode(sha256(convert_to(result_payload::text,'UTF8')),'hex') AS valid
             FROM public.core_diary_result WHERE input_id=?",
            [$inputId]
        );

        return $row === false ? null : $row;
    }

    /** True when a diary result of this actor and role is newer than the snapshot's acknowledged history (it awaits a new snapshot). */
    public function resultAwaitsSnapshot(string $sessionId, int $generation, int $contextSequence, int $actorIdentityId, string $role): bool
    {
        return $this->connection()->fetchOne(
            'SELECT 1 FROM public.core_diary_input i JOIN public.core_diary_result r ON r.input_id=i.id
             JOIN public.core_runtime_context c ON c.session_id=i.session_id AND c.generation=i.generation
             WHERE i.session_id=? AND i.generation=? AND c.snapshot_sequence=? AND i.actor_identity_id=?
                AND i.diary_role=? AND r.history_event_id>c.history_event_id',
            [$sessionId, $generation, $contextSequence, $actorIdentityId, $role]
        ) !== false;
    }

    /**
     * Appends the derived context event, its history event and the diary result in one statement and returns the history event id;
     * null when the statement fails or inserts nothing.
     */
    public function publish(string $playthroughId, int $gamets, int $localTime, string $sessionId, int $generation, int $contextSequence, string $requestId, string $inputSha256, string $resultJson, int $inputId): ?int
    {
        try {
            $id = $this->connection()->fetchOne(
                "WITH publication AS (
                    INSERT INTO public.core_context_event(type,data,sess,gamets,localts,ts,people,location,party,delivery_state,synth_session_id,synth_generation,synth_context_sequence,synth_request_id)
                    VALUES('diary_result','Accepted native diary reflection',:playthrough,:gamets,:localts,:localts,'','','','derived',:session,:generation,:sequence,:request) RETURNING *
                ), history AS (
                    INSERT INTO public.core_history_event(session_id,generation,context_sequence,request_id,playthrough_id,eventlog_rowid,event_payload)
                    SELECT synth_session_id,synth_generation,synth_context_sequence,synth_request_id,:playthrough,rowid,to_jsonb(publication)
                        ||jsonb_build_object('diary_input_sha256',CAST(:inputsha AS text),'diary_result',CAST(:result AS jsonb),
                            'diary_result_sha256',encode(sha256(convert_to((CAST(:result AS jsonb))::text,'UTF8')),'hex')) FROM publication RETURNING id
                ) INSERT INTO public.core_diary_result(history_event_id,input_id,result_payload,result_sha256)
                    SELECT id,:input,CAST(:result AS jsonb),encode(sha256(convert_to((CAST(:result AS jsonb))::text,'UTF8')),'hex') FROM history RETURNING history_event_id",
                ['playthrough' => $playthroughId, 'gamets' => $gamets, 'localts' => $localTime, 'session' => $sessionId, 'generation' => $generation,
                    'sequence' => $contextSequence, 'request' => $requestId, 'inputsha' => $inputSha256, 'result' => $resultJson, 'input' => $inputId]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }

        return $id === false ? null : (int) $id;
    }

    /**
     * Copies the checkpointed embedding (only with its text provenance) onto a published result whose hashes match; true when exactly
     * that one result changed. False when the statement fails.
     */
    public function attachCheckpointEmbedding(int $historyEventId, int $jobId, int $inputId): bool
    {
        try {
            $changed = $this->connection()->executeStatement(
                "UPDATE public.core_diary_result r SET
                        embedding=CASE WHEN c.embedding_text_sha256 IS NOT NULL THEN c.embedding ELSE NULL END,
                        embedding_sha256=CASE WHEN c.embedding_text_sha256 IS NOT NULL THEN c.embedding_sha256 ELSE NULL END,
                        embedding_text_sha256=c.embedding_text_sha256
                    FROM public.core_diary_job_result c WHERE r.history_event_id=? AND c.job_id=?
                        AND r.result_sha256=c.result_sha256 AND r.input_id=? AND c.embedding_attempted
                        AND ((c.embedding IS NULL AND c.embedding_sha256 IS NULL AND c.embedding_text_sha256 IS NULL) OR
                            (c.embedding_sha256=encode(sha256(convert_to(c.embedding::text,'UTF8')),'hex')
                                AND (c.embedding_text_sha256 IS NULL OR c.embedding_text_sha256=encode(sha256(convert_to(r.result_payload->>'content','UTF8')),'hex'))))",
                [$historyEventId, $jobId, $inputId]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return $changed === 1;
    }

    /** The row set the archive reads: every verified published diary. Shared by the browser queries below. */
    public const BROWSER_CTE = <<<'SQL'
WITH written_diaries AS (
    SELECT r.history_event_id AS id, i.actor_identity_id, i.diary_role AS role,
        i.diary_role||':'||i.actor_identity_id::text AS author_key,
        COALESCE(NULLIF(i.input_payload#>>'{binding,name}',''),a.display_name,'Unknown author') AS author,
        r.result_payload->>'topic' AS topic, r.result_payload->>'content' AS content,
        h.recorded_at AS written_at, h.event_payload->>'gamets' AS gamets,
        COALESCE(i.input_payload#>>'{context,world,location}','') AS location,
        COALESCE(i.input_payload#>>'{context,player,display_name}','Sole Survivor') AS player_name,
        substr(encode(sha256(convert_to(h.playthrough_id,'UTF8')),'hex'),1,12) AS save_key
    FROM public.core_diary_result r
    JOIN public.core_diary_input i ON i.id=r.input_id
    JOIN public.core_history_event h ON h.id=r.history_event_id
        AND h.session_id=i.session_id AND h.request_id=i.request_id
        AND h.generation=i.generation AND h.context_sequence=i.context_sequence
    JOIN public.core_actor_identity a ON a.id=i.actor_identity_id
        AND a.playthrough_id=h.playthrough_id AND a.game='fo4'
    WHERE h.event_payload->>'type'='diary_result'
        AND h.event_payload->>'delivery_state'='derived'
        AND r.result_sha256=encode(sha256(convert_to(r.result_payload::text,'UTF8')),'hex')
        AND i.input_sha256=encode(sha256(convert_to(i.input_payload::text,'UTF8')),'hex')
        AND h.event_payload->'diary_result'=r.result_payload
        AND h.event_payload->>'diary_result_sha256'=r.result_sha256
        AND h.event_payload->>'diary_input_sha256'=i.input_sha256
        AND i.input_payload->>'actor_identity_id'=i.actor_identity_id::text
        AND i.input_payload->>'diary_role'=i.diary_role
        AND octet_length(r.result_payload->>'topic') BETWEEN 1 AND 512
        AND octet_length(r.result_payload->>'content') BETWEEN 1 AND 65536
)
SQL;

    /**
     * The WHERE clause and parameters of the archive filters (author_key, role, save_key and a case-insensitive text search); every
     * value is a bound parameter. @param array<string, mixed> $filters @return array{0: string, 1: list<mixed>}
     */
    private static function browserWhere(array $filters): array
    {
        $conditions = [];
        $parameters = [];
        foreach (['author' => 'author_key', 'role' => 'role', 'save' => 'save_key'] as $key => $column) {
            if (($filters[$key] ?? '') !== '') {
                $parameters[] = $filters[$key];
                $conditions[] = $column.'=?';
            }
        }
        if (($filters['search'] ?? '') !== '') {
            $parameters[] = $filters['search'];
            $conditions[] = "strpos(lower(topic||' '||content),lower(CAST(? AS text)))>0";
        }

        return [$conditions ? ' WHERE '.implode(' AND ', $conditions) : '', $parameters];
    }

    /** The newest published diary with the total count in column total; null when there are none. @return array<string, mixed>|null */
    public function browserLatest(): ?array
    {
        $row = $this->connection()->fetchAssociative(
            self::BROWSER_CTE.' SELECT *,count(*) OVER() AS total FROM written_diaries ORDER BY written_at DESC,id DESC LIMIT 1'
        );

        return $row === false ? null : $row;
    }

    /** How many published diaries match the archive filters. @param array<string, mixed> $filters */
    public function browserCount(array $filters): int
    {
        [$where, $parameters] = self::browserWhere($filters);

        return (int) $this->connection()->fetchOne(self::BROWSER_CTE.' SELECT count(*) AS total FROM written_diaries'.$where, $parameters);
    }

    /** One page of the diaries matching the archive filters, newest first. @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function browserEntries(array $filters, int $limit, int $offset): array
    {
        [$where, $parameters] = self::browserWhere($filters);
        $parameters[] = $limit;
        $parameters[] = $offset;

        return $this->connection()->fetchAllAssociative(
            self::BROWSER_CTE.' SELECT * FROM written_diaries'.$where.' ORDER BY written_at DESC,id DESC LIMIT ? OFFSET ?',
            $parameters
        );
    }

    /** Every author with a published diary and how many. @return list<array<string, mixed>> */
    public function browserAuthors(): array
    {
        return $this->connection()->fetchAllAssociative(
            self::BROWSER_CTE.' SELECT author_key,author,role,count(*) AS total FROM written_diaries GROUP BY author_key,author,role ORDER BY author,role LIMIT 1000'
        );
    }

    /** Every save (hashed playthrough) with a published diary. @return list<array<string, mixed>> */
    public function browserSaves(): array
    {
        return $this->connection()->fetchAllAssociative(
            self::BROWSER_CTE.' SELECT save_key,max(player_name) AS player_name,max(written_at) AS written_at FROM written_diaries GROUP BY save_key ORDER BY written_at DESC LIMIT 1000'
        );
    }
}
