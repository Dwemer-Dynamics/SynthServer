<?php

// Reads core_memory_log. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

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

    /** Appends one memory log row. False when the insert failed. */
    public function insertEntry(int $localts, string $speaker, string $listener, string $message, int|string $gamets, string $session, string $momentum, string $event, int|string $ts): bool
    {
        try {
            $this->connection()->executeStatement(
                'INSERT INTO core_memory_log (localts, speaker, listener, message, gamets, session, momentum, event, ts) VALUES (?,?,?,?,?,?,?,?,?)',
                [$localts, $speaker, $listener, $message, $gamets, $session, $momentum, $event, $ts]
            );
        } catch (\Doctrine\DBAL\Exception $error) {
            return false;
        }

        return true;
    }

    /** The oldest first-met record between two names (either direction) with a positive game time, or null. @return array<string, mixed>|null */
    public function firstMet(string $a, string $b): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT speaker,listener,
			message,gamets,momentum,rowid
			FROM core_memory_log
			WHERE event = 'first_met' AND gamets > 0 AND
			((speaker = ? AND listener = ?) OR
			(listener = ? AND speaker = ?))
			ORDER BY rowid ASC LIMIT 1",
            [$a, $b, $a, $b]
        );

        return $row === false ? null : $row;
    }

    /** The messages of the newest $limit memory log rows. @return list<array<string, mixed>> */
    public function newestMessages(int $limit): array
    {
        return $this->connection()->fetchAllAssociative("SELECT message from core_memory_log order by gamets desc limit {$limit} offset 0");
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMemoryLog> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMemoryLog::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
    }

    /** The newest rows by game time, with every column. @return list<array<string,mixed>> */
    public function newest(int $limit): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT a.*, rowid AS rowid FROM public.core_memory_log a ORDER BY gamets DESC, rowid DESC LIMIT ?',
            [$limit]
        );
    }
}
