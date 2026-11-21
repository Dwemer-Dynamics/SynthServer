<?php

// Reads core_memory_summary. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMemorySummary;

/** Finds rows of core_memory_summary. */
final class MemorySummaryDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractMemorySummary
    {
        return $this->fresh()->find(AbstractMemorySummary::class, $id);
    }

    // Native memory summaries and recall. They run inside the caller's native request transaction and throw a Doctrine exception when
    // a statement fails. $visibleCte is the fixed WITH list built by synth_memory_visible_batch_cte().

    /**
     * Publishes a memory job's summary and marks the job ready, in one statement, only while the job still runs under its live lease
     * for this exact request and context sequence. Returns the new summary id, or null when the lease was lost.
     */
    public function publishFromJob(int $jobId, string $sessionId, string $requestId, int|string $generation, string $token, string $summary, string $contextSequence, ?string $embeddingJson): ?int
    {
        $id = $this->connection()->fetchOne(
            "WITH accepted AS (
                UPDATE public.core_worker_job SET state='ready',lease_until=NULL,updated_at=clock_timestamp()
                WHERE id=:job AND job_type='memory' AND session_id=:session AND request_id=:request AND generation=:generation
                    AND state='running' AND lease_token=:token AND lease_until>clock_timestamp() AND deadline_at>clock_timestamp()
                    AND payload->>'context_sequence'=:sequence
                RETURNING id,memory_batch_id
             ) INSERT INTO public.core_memory_summary (batch_id,job_id,summary,embedding)
               SELECT memory_batch_id,id,CAST(:summary AS text),CAST(:embedding AS public.vector) FROM accepted RETURNING id",
            ['job' => $jobId, 'session' => $sessionId, 'request' => $requestId, 'generation' => $generation, 'token' => $token,
                'summary' => $summary, 'sequence' => $contextSequence, 'embedding' => $embeddingJson]
        );

        return $id === false ? null : (int) $id;
    }

    /** How many summaries are visible to the current reader. */
    public function countVisible(string $visibleCte): int
    {
        $visibleCte = JsonbOperatorSql::plain($visibleCte);
        return (int) $this->connection()->fetchOne($visibleCte.'SELECT count(*) AS n FROM public.core_memory_summary s JOIN native_memory_batches b ON b.id=s.batch_id');
    }

    /**
     * Runs the composed dialogue-memory recall query. $query is built by the manager from fixed SQL fragments (visible-batch CTE, the
     * recall CTEs and one candidate selection) and uses only the named parameters :floor, :any, :all and :vector.
     *
     * @return list<array<string, mixed>>
     */
    public function recallCandidates(string $query, int $timeFloor, string $anyTerms, string $allTerms, ?string $vectorJson): array
    {
        $query = JsonbOperatorSql::plain($query);
        return $this->connection()->fetchAllAssociative($query, ['floor' => $timeFloor, 'any' => $anyTerms, 'all' => $allTerms, 'vector' => $vectorJson]);
    }

    /**
     * Runs an optional diary recall query inside a savepoint so a timeout cannot abort the caller's transaction. $query uses the
     * positional placeholders $1..$4 (floor, any terms, all terms, vector). A statement cancelled by a timeout or by the user (SQLSTATE
     * 57014) returns null and leaves the transaction usable; any other failure throws. This is the one place that reads the SQLSTATE of
     * the failed result, so it keeps the native connection calls of the statement.
     *
     * @return list<array<string, mixed>>|null
     */
    public function recallDiaries(string $query, array $parameters): ?array
    {
        $connection = $this->connection()->getNativeConnection();
        if (@pg_query($connection, 'SAVEPOINT synth_diary_recall') === false) throw new \RuntimeException('Unable to isolate diary recall');
        if (!@pg_send_query_params($connection, $query, $parameters)) throw new \RuntimeException('Unable to query diary recall');
        $result = pg_get_result($connection);
        // Consume the query result before issuing savepoint recovery commands on this connection.
        while (pg_get_result($connection) !== false) {}
        $success = $result !== false && pg_result_status($result) === PGSQL_TUPLES_OK;
        $state = $result === false ? null : pg_result_error_field($result, PGSQL_DIAG_SQLSTATE);
        if (!$success && @pg_query($connection, 'ROLLBACK TO SAVEPOINT synth_diary_recall') === false) {
            throw new \RuntimeException('Unable to recover diary recall');
        }
        if (@pg_query($connection, 'RELEASE SAVEPOINT synth_diary_recall') === false) throw new \RuntimeException('Unable to finish diary recall');
        if ($success) return pg_fetch_all($result) ?: [];
        if ($state === '57014') return null;
        throw new \RuntimeException('Unable to read native diary memory');
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMemorySummary> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMemorySummary::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }
}
