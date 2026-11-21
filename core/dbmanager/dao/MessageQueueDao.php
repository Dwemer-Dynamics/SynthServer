<?php

// The one place that reads and changes core_message_queue. Every state change is one guarded statement
// (UPDATE ... WHERE status = <expected> ... RETURNING), so concurrent callers cannot both win a transition: the shared
// session refuses transactions and needs none. Statuses only move forward (docs/CHEVO-PARITY.md, MessageHandler):
// pending -> completed -> delivered, and pending or completed -> cancelled or expired. Terminal rows never change.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMessageQueueEntry;

/** Admits, completes, delivers, cancels, expires and purges queued messages. */
final class MessageQueueDao
{
    use UsesEntityManager;

    /** The columns a delivery returns, in one place so the statements and the reader agree. */
    private const COLUMNS = 'id, message_type, session_id, request_id, generation, status, payload, completed_at, delivered_at, expires_at';

    /** One row by id, or null. */
    public function find(int $id): ?AbstractMessageQueueEntry
    {
        return $this->fresh()->find(AbstractMessageQueueEntry::class, $id);
    }

    /** Rows ordered by id, at most $limit. @return list<AbstractMessageQueueEntry> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMessageQueueEntry::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }

    /**
     * Admits one message as pending and returns its id. Admitting the same (session, request, dedup key) again returns the
     * existing id and changes nothing, so a retried admission never creates a second message.
     */
    public function admit(string $type, string $sessionId, string $requestId, int $generation, string $dedupKey, int $lifetimeSeconds): int
    {
        $row = $this->connection()->fetchAssociative(
            'INSERT INTO public.core_message_queue (message_type, session_id, request_id, generation, dedup_key, expires_at)'
            ." VALUES (?, ?, ?, ?, ?, now() + CAST(? AS integer) * interval '1 second')"
            .' ON CONFLICT (session_id, request_id, dedup_key) DO UPDATE SET dedup_key = EXCLUDED.dedup_key RETURNING id',
            [$type, $sessionId, $requestId, $generation, $dedupKey, $lifetimeSeconds]
        );

        return is_array($row) ? intval($row['id']) : 0;
    }

    /** The id of an admitted message, or null. */
    public function idFor(string $sessionId, string $requestId, string $dedupKey): ?int
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT id FROM public.core_message_queue WHERE session_id = ? AND request_id = ? AND dedup_key = ?',
            [$sessionId, $requestId, $dedupKey]
        );

        return is_array($row) ? intval($row['id']) : null;
    }

    /** pending -> completed with the final payload. False when the message is not pending or already past its expiry. */
    public function complete(int $id, string $payloadJson): bool
    {
        $row = $this->connection()->fetchAssociative(
            "UPDATE public.core_message_queue SET status = 'completed', payload = CAST(? AS jsonb), completed_at = now(), last_error = NULL"
            ." WHERE id = ? AND status = 'pending' AND expires_at > now() RETURNING id",
            [$payloadJson, $id]
        );

        return is_array($row);
    }

    /**
     * completed -> delivered, returning the row, or null when the message is not completed (pending, delivered, cancelled,
     * expired, past its expiry or unknown). The status change and the read are one statement: of any number of concurrent
     * callers exactly one gets the row.
     *
     * @return array<string,mixed>|null
     */
    public function deliver(int $id): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "UPDATE public.core_message_queue SET status = 'delivered', delivered_at = now()"
            ." WHERE id = ? AND status = 'completed' AND expires_at > now() RETURNING ".self::COLUMNS,
            [$id]
        );

        return is_array($row) ? $row : null;
    }

    /**
     * The status a caller should see for one message: expiry applies even before the maintenance run has recorded it.
     * Null when the message does not exist.
     */
    public function visibleStatus(int $id): ?string
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT CASE WHEN status IN ('pending', 'completed') AND expires_at <= now() THEN 'expired' ELSE status END AS status"
            .' FROM public.core_message_queue WHERE id = ?',
            [$id]
        );

        return is_array($row) ? strval($row['status']) : null;
    }

    /** pending or completed -> cancelled for one request of a session; returns the number of messages cancelled. */
    public function cancelRequest(string $sessionId, string $requestId): int
    {
        return $this->connection()->executeStatement(
            "UPDATE public.core_message_queue SET status = 'cancelled', cancelled_at = now()"
            ." WHERE session_id = ? AND request_id = ? AND status IN ('pending', 'completed')",
            [$sessionId, $requestId]
        );
    }

    /** pending or completed -> cancelled for every message of the session from an older generation; returns the count. */
    public function cancelOlderGenerations(string $sessionId, int $generation): int
    {
        return $this->connection()->executeStatement(
            "UPDATE public.core_message_queue SET status = 'cancelled', cancelled_at = now()"
            ." WHERE session_id = ? AND generation < ? AND status IN ('pending', 'completed')",
            [$sessionId, $generation]
        );
    }

    /**
     * pending or completed -> cancelled for messages whose speech job was cancelled (a cancelled turn or a halt), for one
     * session or, with null, for all; returns the count.
     */
    public function cancelWhereJobCancelled(?string $sessionId): int
    {
        return $this->connection()->executeStatement(
            "UPDATE public.core_message_queue q SET status = 'cancelled', cancelled_at = now()"
            ." WHERE q.status IN ('pending', 'completed') AND (CAST(? AS text) IS NULL OR q.session_id = CAST(? AS text))"
            ." AND EXISTS (SELECT 1 FROM public.core_worker_job j WHERE j.job_type = 'tts' AND j.state IN ('cancelled', 'stale')"
            .' AND j.session_id = q.session_id AND j.request_id = q.request_id AND j.utterance_id = q.dedup_key)',
            [$sessionId, $sessionId]
        );
    }

    /** pending or completed -> expired once past the expiry time; returns the count. */
    public function expire(): int
    {
        return $this->connection()->executeStatement(
            "UPDATE public.core_message_queue SET status = 'expired'"
            ." WHERE status IN ('pending', 'completed') AND expires_at <= now()"
        );
    }

    /** Deletes terminal messages older than $days; active work is never deleted. Returns the count. */
    public function purgeTerminal(int $days): int
    {
        return $this->connection()->executeStatement(
            "DELETE FROM public.core_message_queue WHERE status IN ('delivered', 'cancelled', 'expired')"
            ." AND created_at < now() - CAST(? AS integer) * interval '1 day'",
            [$days]
        );
    }

    /** How many terminal messages are older than $days (what purgeTerminal would delete). */
    public function terminalOlderThan(int $days): int
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT count(*) AS n FROM public.core_message_queue WHERE status IN ('delivered', 'cancelled', 'expired')"
            ." AND created_at < now() - CAST(? AS integer) * interval '1 day'",
            [$days]
        );

        return is_array($row) ? intval($row['n']) : 0;
    }

    /** Records that the message was announced to the notification channel. */
    public function markNotified(int $id): void
    {
        $this->connection()->executeStatement(
            'UPDATE public.core_message_queue SET notified_at = now(), notify_attempts = notify_attempts + 1 WHERE id = ?',
            [$id]
        );
    }

    /** Records a failed announcement (the channel absent or no client connected). */
    public function markNotifyFailed(int $id, string $reason): void
    {
        $this->connection()->executeStatement(
            'UPDATE public.core_message_queue SET notify_attempts = notify_attempts + 1, last_error = ? WHERE id = ?',
            [substr($reason, 0, 200), $id]
        );
    }

    /**
     * Completed, undelivered messages whose announcement has not succeeded and has attempts left, oldest first.
     *
     * @return list<int>
     */
    public function unannounced(int $maximumAttempts, int $limit): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            "SELECT id FROM public.core_message_queue WHERE status = 'completed' AND expires_at > now() AND notified_at IS NULL"
            .' AND notify_attempts < ? ORDER BY id LIMIT ?',
            [$maximumAttempts, $limit]
        );

        return array_map(static fn(array $row): int => intval($row['id']), $rows);
    }

    /** @return array<string,int> row count per status */
    public function counts(): array
    {
        $counts = ['pending' => 0, 'completed' => 0, 'delivered' => 0, 'cancelled' => 0, 'expired' => 0];
        foreach ($this->connection()->fetchAllAssociative('SELECT status, count(*) AS n FROM public.core_message_queue GROUP BY status') as $row) {
            $counts[strval($row['status'])] = intval($row['n']);
        }

        return $counts;
    }

    /** Seconds the oldest pending message has waited, or null when none is pending. */
    public function oldestPendingSeconds(): ?int
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT floor(extract(epoch FROM now() - min(created_at))) AS age FROM public.core_message_queue WHERE status = 'pending'"
        );

        return is_array($row) && $row['age'] !== null ? intval($row['age']) : null;
    }
}
