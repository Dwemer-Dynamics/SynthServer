<?php

// Reads core_quest. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractQuest;

/** Finds rows of core_quest. */
final class QuestDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $rowid): ?AbstractQuest
    {
        return $this->fresh()->find(AbstractQuest::class, $rowid);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractQuest> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractQuest::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
    }

    /**
     * The eight most relevant quests (the selected one first, then newest): name, description, gamets, status.
     *
     * @return list<array<string, mixed>>
     */
    public function topActiveQuests(): array
    {
        return $this->rows("SELECT name, briefing AS description, gamets, status FROM core_quest ORDER BY CASE WHEN status = 'selected' THEN 0 ELSE 1 END, gamets DESC LIMIT 8", []);
    }

    /**
     * Every quest (the selected one first, then newest): name, id_quest, briefing, notes, status.
     *
     * @return list<array<string, mixed>>
     */
    public function allQuestsSelectedFirst(): array
    {
        return $this->rows("SELECT name, id_quest, briefing, briefing2 AS notes, status FROM core_quest ORDER BY CASE WHEN status = 'selected' THEN 0 ELSE 1 END, gamets DESC", []);
    }

    /**
     * The quests whose id or name equals this text, ignoring case: name, id_quest, briefing, data.
     *
     * @return list<array<string, mixed>>
     */
    public function findByIdOrName(string $quest): array
    {
        return $this->rows('SELECT name, id_quest, briefing, data FROM core_quest WHERE lower(id_quest) = lower(:quest) OR lower(name) = lower(:quest)', ['quest' => $quest]);
    }
    /**
     * Reads as the legacy fetchAll did: a failed statement reads as no rows.
     *
     * @param array<string, mixed> $parameters
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $parameters): array
    {
        try {
            return $this->connection()->fetchAllAssociative($sql, $parameters);
        } catch (\Doctrine\DBAL\Exception) {
            return [];
        }
    }
}
