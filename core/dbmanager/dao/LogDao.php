<?php

// Reads core_log. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractLog;

/** Finds rows of core_log. */
final class LogDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $rowid): ?AbstractLog
    {
        return $this->fresh()->find(AbstractLog::class, $rowid);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractLog> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractLog::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
    }
}
