<?php

// Reads core_memory_log. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Exception as DbalException;
use Synth\Core\Entities\Base\AbstractMemoryLog;

/** Finds rows of core_memory_log. */
final class MemoryLogDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $rowid): ?AbstractMemoryLog
    {
        return $this->fresh()->find(AbstractMemoryLog::class, $rowid);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMemoryLog> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMemoryLog::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
    }

    /** Removes every memory log row (the wipe command). */
    public function deleteAll(): bool
    {
        try {
            $this->connection()->executeStatement('DELETE FROM public.core_memory_log');
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }
}
