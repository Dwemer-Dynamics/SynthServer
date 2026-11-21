<?php

// Reads core_memory. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMemory;

/** Finds rows of core_memory. */
final class MemoryDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int $rowid): ?AbstractMemory
    {
        return $this->fresh()->find(AbstractMemory::class, $rowid);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMemory> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMemory::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
    }

    /** Number of memory rows of one source type, such as diary or summary (home dashboard). */
    public function dashboardCountBySource(string $sourceType): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM core_memory WHERE source_type = ?', [$sourceType]);
    }

    /** The newest diary memory, as a list of at most one row (home dashboard). @return list<array<string, mixed>> */
    public function dashboardLatestDiary(): array
    {
        return $this->connection()->fetchAllAssociative("SELECT rowid, topic, summary AS content, companions AS people, location, localts, gamets FROM core_memory WHERE source_type='diary' ORDER BY gamets DESC, rowid DESC LIMIT 1");
    }

    /**
     * The newest sent letters and journal notes written for this actor after a game time (content, gamets, topic), at most $limit.
     *
     * @return list<array<string, mixed>>
     */
    public function recentLettersAndNotes(string $actor, int $afterGamets, int $limit): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT summary AS content, gamets, topic FROM public.core_memory WHERE source_type='diary' AND companions = ? AND gamets > ? AND (topic='Sent Letter' OR topic='Journal Note') ORDER BY gamets DESC, ts DESC LIMIT ".max(1, $limit),
            [$actor, $afterGamets]
        );
    }
}
