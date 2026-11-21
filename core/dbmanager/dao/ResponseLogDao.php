<?php

// Reads core_response_log. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Exception as DbalException;
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
