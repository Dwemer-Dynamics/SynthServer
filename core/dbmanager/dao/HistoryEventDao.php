<?php

// Reads core_history_event. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractHistoryEvent;

/** Finds rows of core_history_event. */
final class HistoryEventDao
{
    use UsesEntityManager;
    use TextRows;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractHistoryEvent
    {
        return $this->fresh()->find(AbstractHistoryEvent::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractHistoryEvent> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractHistoryEvent::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }

    /**
     * The processed text inputs of one request at one context sequence, oldest first, at most two (a third would already be
     * ambiguous to the caller).
     *
     * @return list<array{id: string, event_payload: string}>
     */
    public function processedInputs(string $sessionId, int $generation, string $requestId, int $contextSequence, string $playthroughId): array
    {
        return self::textRows($this->connection()->fetchAllAssociative(
            "SELECT id,event_payload FROM public.core_history_event
                    WHERE session_id=:p1 AND generation=:p2 AND request_id=:p3 AND context_sequence=:p4
                        AND playthrough_id=:p5 AND event_payload->>'type' IN ('inputtext','inputtext_s')
                    ORDER BY id LIMIT 2",
            ['p1' => $sessionId, 'p2' => $generation, 'p3' => $requestId, 'p4' => $contextSequence, 'p5' => $playthroughId]
        ));
    }

    /** True when this event still carries exactly this payload type and data. */
    public function hasTypeAndData(int $id, ?string $type, ?string $data): bool
    {
        return $this->connection()->fetchOne(
            "SELECT 1 FROM public.core_history_event WHERE id=:p1 AND event_payload->>'type'=:p2 AND event_payload->>'data'=:p3",
            ['p1' => $id, 'p2' => $type, 'p3' => $data]
        ) !== false;
    }

    /**
     * How many of $sourceIds are visible relationship sources. $visibleCte is the reader's visibility CTE chain ending in
     * `relationship_visible_sources` (built by the relationship manager from the saved frontier; it contains the jsonb `?` operator, so
     * this statement binds no parameters and the ids are validated integers).
     *
     * @param list<int> $sourceIds
     */
    public function visibleSourceCount(string $visibleCte, array $sourceIds): int
    {
        return (int) $this->connection()->fetchOne(
            $visibleCte.'SELECT count(*) AS n FROM relationship_visible_sources WHERE history_event_id IN ('.implode(',', array_map('intval', $sourceIds)).')'
        );
    }

    /**
     * The newest ten visible dialogue events (oldest first) the reader's frozen relationship evaluation may quote. $visibleCte is the
     * reader's visibility CTE chain; the statement binds no parameters (see visibleSourceCount()).
     *
     * @return list<array{history_event_id: string, type: string|null, data: string|null}>
     */
    public function recentVisibleEvents(string $visibleCte): array
    {
        $rows = self::textRows($this->connection()->fetchAllAssociative(
            $visibleCte."SELECT h.history_event_id,h.type,h.data FROM native_history h JOIN relationship_visible_sources s USING(history_event_id)
                    ORDER BY h.gamets DESC NULLS LAST,h.ts DESC NULLS LAST,h.history_event_id DESC LIMIT 10"
        ));

        return array_reverse($rows);
    }

    /**
     * The reader's own frontier row (depth 1 of the saved ancestry) for the request's saved context, or null. $historyCte is the
     * history visibility CTE chain ending in `native_ancestry`; the statement binds no parameters (see visibleSourceCount()).
     *
     * @return array<string, string|null>|null
     */
    public function readerFrontier(string $historyCte): ?array
    {
        return self::textRow($this->connection()->fetchAssociative($historyCte.'SELECT * FROM native_ancestry WHERE depth=1'));
    }
}
