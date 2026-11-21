<?php

// Reads core_memory. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Exception as DbalException;
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

    /** Uids of the summary memories whose truncated gamets is after $gametsTruncated. @return list<string> */
    public function summaryUidsAfter(int $gametsTruncated): array
    {
        return array_map('strval', $this->connection()->fetchFirstColumn(
            "SELECT uid FROM public.core_memory WHERE source_type = 'summary' AND gamets_truncated > ?",
            [$gametsTruncated]
        ));
    }

    /** Removes every memory (the wipe command). */
    public function deleteAll(): bool
    {
        try {
            $this->connection()->executeStatement('DELETE FROM public.core_memory');
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }
}
