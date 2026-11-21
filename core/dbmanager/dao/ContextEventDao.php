<?php

// Reads core_context_event. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractContextEvent;

/** Finds rows of core_context_event. */
final class ContextEventDao
{
    use UsesEntityManager;
    use TextRows;

    /** One row by its primary key, or null. */
    public function find(int|string $rowid): ?AbstractContextEvent
    {
        return $this->fresh()->find(AbstractContextEvent::class, $rowid);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractContextEvent> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractContextEvent::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
    }

    /**
     * The newest context events (by rowid, descending) other than prompts, settings and inputs whose people or data mention
     * this name without regard to case.
     *
     * @return list<array{rowid: string, type: string, data: string|null, gamets: string|null, people: string|null, location: string|null}>
     */
    public function recentMentioning(string $name, int $limit): array
    {
        return self::textRows($this->connection()->fetchAllAssociative(
            "SELECT rowid, type, data, gamets, people, location
             FROM core_context_event
             WHERE type NOT IN ('prechat','setconf','status_msg','user_input','npc_snapshot','playerinfo')
               AND (
                    POSITION(LOWER(:p1) IN LOWER(COALESCE(people, ''))) > 0
                    OR POSITION(LOWER(:p1) IN LOWER(COALESCE(data, ''))) > 0
               )
             ORDER BY rowid DESC
             LIMIT :p2",
            ['p1' => $name, 'p2' => $limit]
        ));
    }
}
