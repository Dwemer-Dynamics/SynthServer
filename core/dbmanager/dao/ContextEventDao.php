<?php

// Reads core_context_event. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractContextEvent;

/** Finds rows of core_context_event. */
final class ContextEventDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $rowid): ?AbstractContextEvent
    {
        return $this->fresh()->find(AbstractContextEvent::class, $rowid);
    }

    /** The newest $limit event texts of the given types, newest first. @param list<string> $types @return list<array<string, mixed>> */
    public function recentDataByType(array $types, int $limit): array
    {
        if ($types === []) return [];
        $marks = implode(',', array_fill(0, count($types), '?'));

        return $this->connection()->fetchAllAssociative(
            "SELECT data from core_context_event where type in ({$marks}) order by gamets desc limit {$limit} offset 0",
            array_values($types)
        );
    }

    /**
     * The in-game hours spanned by the newest $limit visible chat events that mention an actor, as a row with hour_threshold (NULL when
     * there are none). $visibleChatSql is the fixed delivery-state condition built by synthBuildChatDeliveryStateSql().
     *
     * @return array<string, mixed>|null
     */
    public function chatSpanForActor(string $actor, string $visibleChatSql, int $limit): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT
            (MAX(gamets) - MIN(gamets)) * 0.0000024 AS hour_threshold
        FROM (
            SELECT gamets
            FROM core_context_event
            WHERE type='chat'
            AND {$visibleChatSql}
            and people LIKE '%'||?||'%'
            ORDER BY gamets DESC
            LIMIT {$limit}
        ) AS recent_events",
            [$actor]
        );

        return $row === false ? null : $row;
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractContextEvent> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractContextEvent::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
    }
}
