<?php

// Reads core_log. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Exception as DbalException;
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

    /** Appends one debug log row of a request (what was sent, what came back, and the request line). False when the statement fails. */
    public function record(int $localts, string $prompt, string $response, string $url): bool
    {
        try {
            $this->connection()->executeStatement(
                'INSERT INTO public.core_log (localts, prompt, response, url) VALUES (?, ?, ?, ?)',
                [$localts, $prompt, $response, $url]
            );
        } catch (DbalException $error) {
            return false;
        }

        return true;
    }
}
