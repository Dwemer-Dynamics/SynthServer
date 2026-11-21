<?php

// Reads core_response_log. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractResponseLog;
use Doctrine\DBAL\Exception as DbalException;

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

    /** How many rows are older than the cutoff (a unix time compared with localts), or null when the count fails. */
    public function countOlderThan(int $cutoff): ?int
    {
        try {
            return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM public.core_response_log WHERE localts < ?', [$cutoff]);
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }
    }

    /** How many rows there are, or null when the count fails. */
    public function countRows(): ?int
    {
        try {
            return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM public.core_response_log');
        } catch (\Doctrine\DBAL\Exception $error) {
            return null;
        }
    }

    /** Deletes the rows older than the cutoff; false when the statement fails. */
    public function deleteOlderThan(int $cutoff): bool
    {
        try {
            $this->connection()->executeStatement('DELETE FROM public.core_response_log WHERE localts < ?', [$cutoff]);
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /** Marks every sent dialogue response as unsent again (after a wipe or a rollback, so it can be replayed). */
    public function markDialogueResponsesUnsent(): bool
    {
        try {
            $this->connection()->executeStatement("UPDATE public.core_response_log SET sent = 0 WHERE sent = 1 AND action = 'synth_dialogue_response'");
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }
}
