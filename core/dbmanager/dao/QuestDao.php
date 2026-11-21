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

    /** Name, id, briefings and data of every quest. @return list<array<string,mixed>> */
    public function listAll(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT name, id_quest, briefing, briefing2, data FROM public.core_quest');
    }
}
