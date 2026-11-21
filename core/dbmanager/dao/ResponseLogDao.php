<?php

// Reads core_response_log. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractResponseLog;

/** Finds rows of core_response_log. */
final class ResponseLogDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $rowid): ?AbstractResponseLog
    {
        return $this->fresh()->find(AbstractResponseLog::class, $rowid);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractResponseLog> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractResponseLog::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
    }

    /**
     * Marks every unsent response as sent, up to the local time when one is given, and returns the rows it marked in row order
     * (one atomic statement, so concurrent requests never take the same line twice). A failed statement reads as no rows.
     *
     * @return list<array<string, mixed>>
     */
    public function claimUnsent(int $untilLocalTime = 0): array
    {
        $clause = $untilLocalTime !== 0 ? "AND localts <= {$untilLocalTime} " : '';
        try {
            return $this->connection()->fetchAllAssociative(
                "UPDATE core_response_log SET sent = 1
                 WHERE rowid IN (SELECT rowid FROM core_response_log WHERE sent = 0 {$clause} ORDER BY rowid ASC)
                 RETURNING *, rowid"
            );
        } catch (\Doctrine\DBAL\Exception) {
            return [];
        }
    }
}
