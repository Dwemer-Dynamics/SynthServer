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

    /** Appends one core_log row. False when the insert failed. */
    public function insertEntry(int $localts, string $prompt, string $response, string $url): bool
    {
        try {
            $this->connection()->executeStatement('INSERT INTO core_log (localts, prompt, response, url) VALUES (?,?,?,?)', [$localts, $prompt, $response, $url]);
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractLog> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractLog::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
    }

    /** Appends one core_log row (localts, prompt, response, url); false when the statement fails. */
    public function insert(int $localts, string $prompt, string $response, string $url): bool
    {
        try {
            $this->connection()->executeStatement(
                'INSERT INTO public.core_log (localts, prompt, response, url) VALUES (?, ?, ?, ?)',
                [$localts, $prompt, $response, $url]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /** Number of rows. */
    public function countAll(): int
    {
        return intval($this->connection()->fetchOne('SELECT COUNT(*) FROM public.core_log'));
    }

    /**
     * Every column of each row plus rowid, newest first; limit null returns all rows.
     *
     * @return list<array<string,mixed>>
     */
    public function newestFirst(?int $limit = null, int $offset = 0): array
    {
        $sql = 'SELECT a.*, rowid FROM public.core_log a ORDER BY localts DESC, rowid DESC';
        $params = [];
        if ($limit !== null) {
            $sql .= ' LIMIT ? OFFSET ?';
            $params = [$limit, $offset];
        }

        return $this->connection()->fetchAllAssociative($sql, $params);
    }

    /** Response, url, prompt and rowid of every row, newest first (the CSV export). @return list<array<string,mixed>> */
    public function exportColumns(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT response, url, prompt, rowid FROM public.core_log ORDER BY rowid DESC');
    }

    /** Deletes every row. */
    public function deleteAll(): void
    {
        $this->connection()->executeStatement('DELETE FROM public.core_log');
    }
}
