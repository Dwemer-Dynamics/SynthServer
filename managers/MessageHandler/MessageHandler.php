<?php

declare(strict_types=1);

namespace Synth\Managers\MessageHandler;

use Closure;
use InvalidArgumentException;
use Synth\Core\DbManager\Dao\MessageQueueDao;
use Synth\Managers\Notification\NotifyPublisher;

/**
 * The durable outbound queue (core_message_queue), the way the CHIM 4 server keeps it: work is admitted pending, finished
 * work is completed with its final payload and then announced, and the client fetches it by id exactly once.
 *
 *   admit()     a speech job is admitted: pending, owned by a session, request and generation
 *   complete()  the payload is stored first; only after that is the id announced (persist, then notify)
 *   deliver()   completed -> delivered in one statement: the first fetch gets the row, every later one gets nothing
 *   cancelRequest(), cancelOlderGenerations(), expire()  retire work that must no longer be returned
 *
 * A failed announcement never loses the message: the row stays completed and retryNotifications() (bin/message-maintenance.php)
 * announces it again, a bounded number of times. The client also polls, so a stopped channel only costs latency.
 * The payload is opaque: speech results are the `synth.tts_status.v1` document, but nothing here reads it.
 */
final class MessageHandler
{
    public const TYPE_SPEECH = 'speech';
    public const LIFETIME_SECONDS = 600;
    public const MAXIMUM_NOTIFY_ATTEMPTS = 5;
    public const MAXIMUM_PAYLOAD_BYTES = 1048576;
    /** Whether a build without an explicit SYNTH_MESSAGE_QUEUE setting uses the queue path. */
    public const DEFAULT_ENABLED = true;

    /** @var Closure(int): bool */
    private Closure $notifier;

    /** @param (callable(int): bool)|null $notifier announces one message id; defaults to the notification channel */
    public function __construct(private MessageQueueDao $dao, ?callable $notifier = null)
    {
        $this->notifier = $notifier !== null
            ? Closure::fromCallable($notifier)
            : static fn(int $messageId): bool => NotifyPublisher::publishMessage($messageId);
    }

    /** The handler over the request's own database session. */
    public static function current(): self
    {
        return new self(MessageQueueDao::current());
    }

    /**
     * Whether the server admits queue messages (SYNTH_MESSAGE_QUEUE=1 or 0; unset uses DEFAULT_ENABLED). The legacy response
     * path, polling GET /api/v1/messages/<cache key>, is always served beside it.
     */
    public static function enabled(): bool
    {
        $value = getenv('SYNTH_MESSAGE_QUEUE');
        return match (is_string($value) ? trim($value) : '') {
            '1' => true,
            '0' => false,
            default => self::DEFAULT_ENABLED,
        };
    }

    /**
     * Admits one message as pending and returns its id; admitting the same utterance of the same request again returns the
     * same id. A new generation first cancels the session's work from older generations, so a stale result is never returned.
     */
    public function admit(string $type, string $sessionId, string $requestId, int $generation, string $dedupKey, ?int $lifetimeSeconds = null): int
    {
        if ($type === '' || strlen($type) > 64 || $sessionId === '' || $requestId === '' || $dedupKey === '' || $generation < 1) {
            throw new InvalidArgumentException('A queued message needs a type, session, request, generation and key.');
        }
        $this->dao->cancelOlderGenerations($sessionId, $generation);
        $id = $this->dao->admit($type, $sessionId, $requestId, $generation, $dedupKey, max(1, $lifetimeSeconds ?? self::LIFETIME_SECONDS));
        if ($id < 1) {
            throw new \RuntimeException('Unable to admit the queued message.');
        }
        return $id;
    }

    /** The id of an admitted message, or null. */
    public function idFor(string $sessionId, string $requestId, string $dedupKey): ?int
    {
        return $this->dao->idFor($sessionId, $requestId, $dedupKey);
    }

    /**
     * pending -> completed with the final payload, then announces the id. Returns false when the message was not pending
     * (cancelled, expired or already completed): nothing is stored and nothing is announced. A failed announcement is recorded
     * and does not undo the completion.
     *
     * @param array<string,mixed> $payload a non-empty JSON object of at most MAXIMUM_PAYLOAD_BYTES
     */
    public function complete(int $id, array $payload): bool
    {
        $json = self::encodePayload($payload);
        if (!$this->dao->complete($id, $json)) {
            return false;
        }
        $this->announce($id);
        return true;
    }

    /**
     * The first call for a completed message returns its row and marks it delivered; every later call returns null, as does a
     * message that is pending, cancelled, expired or unknown (see status()).
     *
     * @return array<string,mixed>|null
     */
    public function deliver(int $id): ?array
    {
        return $this->dao->deliver($id);
    }

    /** 'pending', 'completed', 'delivered', 'cancelled' or 'expired' (expiry applied at once), or null when unknown. */
    public function status(int $id): ?string
    {
        return $this->dao->visibleStatus($id);
    }

    /** Cancels the unfinished or undelivered messages of one request; returns how many. */
    public function cancelRequest(string $sessionId, string $requestId): int
    {
        return $this->dao->cancelRequest($sessionId, $requestId);
    }

    /** Cancels the unfinished or undelivered messages of generations older than $generation; returns how many. */
    public function cancelOlderGenerations(string $sessionId, int $generation): int
    {
        return $this->dao->cancelOlderGenerations($sessionId, $generation);
    }

    /** Cancels the messages whose speech job was cancelled (a cancelled turn or a halt), for one session or all; returns how many. */
    public function cancelCancelledWork(?string $sessionId = null): int
    {
        return $this->dao->cancelWhereJobCancelled($sessionId);
    }

    /** Marks pending and completed messages past their expiry as expired; returns how many. */
    public function expire(): int
    {
        return $this->dao->expire();
    }

    /** Announces again every completed message whose announcement has not succeeded; returns how many reached a client. */
    public function retryNotifications(int $limit = 100): int
    {
        $reached = 0;
        foreach ($this->dao->unannounced(self::MAXIMUM_NOTIFY_ATTEMPTS, max(1, $limit)) as $id) {
            if ($this->announce($id)) {
                $reached++;
            }
        }
        return $reached;
    }

    /** Deletes delivered, cancelled and expired messages older than $days; active work is never deleted. */
    public function purge(int $days): int
    {
        return $this->dao->purgeTerminal($days);
    }

    /** @return array<string,mixed> counts per status, the oldest pending age and the retention candidates */
    public function report(int $days): array
    {
        return [
            'counts' => $this->dao->counts(),
            'oldest_pending_seconds' => $this->dao->oldestPendingSeconds(),
            'terminal_older_than_retention' => $this->dao->terminalOlderThan($days),
        ];
    }

    private function announce(int $id): bool
    {
        try {
            $reached = ($this->notifier)($id);
        } catch (\Throwable) {
            $reached = false;
        }
        if ($reached) {
            $this->dao->markNotified($id);
        } else {
            $this->dao->markNotifyFailed($id, 'no_client_reached');
        }
        return $reached;
    }

    /** @param array<string,mixed> $payload */
    private static function encodePayload(array $payload): string
    {
        if ($payload === [] || array_is_list($payload)) {
            throw new InvalidArgumentException('A queued payload must be a non-empty JSON object.');
        }
        try {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR, 32);
        } catch (\JsonException) {
            throw new InvalidArgumentException('A queued payload must be valid JSON within 32 levels.');
        }
        if (strlen($json) > self::MAXIMUM_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('A queued payload is larger than its bound.');
        }
        return $json;
    }
}
