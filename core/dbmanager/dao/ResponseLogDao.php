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

    /**
     * Queue totals: total, pending (sent = 0), delivered (sent <> 0) and the latest localts.
     *
     * @return array<string,mixed>
     */
    public function queueSummary(): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT COUNT(*) AS total, SUM(CASE WHEN sent = 0 THEN 1 ELSE 0 END) AS pending,'
            .' SUM(CASE WHEN sent <> 0 THEN 1 ELSE 0 END) AS delivered, MAX(localts) AS latest_localts FROM public.core_response_log'
        );

        return is_array($row) ? $row : [];
    }

    /** The newest rows for the queue page. @return list<array<string,mixed>> */
    public function newest(int $limit): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT rowid, localts, sent, actor, action, tag, text FROM public.core_response_log ORDER BY rowid DESC LIMIT ?',
            [$limit]
        );
    }

    /** Every row with all columns, oldest first (the legacy event-log tab). @return list<array<string,mixed>> */
    public function allOldestFirst(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT a.*, rowid FROM public.core_response_log a ORDER BY rowid ASC');
    }

    /** Queues an operator action (a command or an animation) for the game to pick up. */
    public function enqueueOperatorAction(int $localts, string $actor, string $text, string $action): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO public.core_response_log (localts, sent, text, actor, action) VALUES (?, 0, ?, ?, ?)',
            [$localts, $text, $actor, $action]
        );
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
