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

    /** How many legacy summary memories there are. */
    public function countSummaries(): int
    {
        return (int) $this->connection()->fetchOne("SELECT count(*) AS n FROM public.core_memory WHERE source_type='summary'");
    }

    /** The newest legacy diary entry (topic, content) written for one companion name, or null. @return array<string, mixed>|null */
    public function latestDiaryEntry(string $companion): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT topic, summary AS content
                 FROM public.core_memory
                 WHERE source_type = 'diary' AND lower(trim(companions)) = lower(?)
                 ORDER BY gamets DESC, localts DESC, rowid DESC
                 LIMIT 1",
            [$companion]
        );

        return $row === false ? null : $row;
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMemory> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMemory::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
    }
}
