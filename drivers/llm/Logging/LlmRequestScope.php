<?php

declare(strict_types=1);

namespace Synth\Drivers\Llm\Logging;

/**
 * What the provider HTTP layer saw of the request being logged.
 *
 * The drivers build the provider body themselves, so the log learns the exact URL, body and status from the one place
 * every provider call goes through (lib/provider_stream.php) instead of from each driver. Only a call between begin() and
 * take() is captured; other users of the same helpers (memory embeddings, for example) leave no trace here. Headers are
 * never captured, so the Authorization header cannot reach the log.
 */
final class LlmRequestScope
{
    private static bool $active = false;
    /** @var array{url: ?string, body: ?string, status: ?int}|null */
    private static ?array $seen = null;
    private static ?int $replayOf = null;
    private static ?int $lastLogId = null;

    public static function begin(): void
    {
        self::$active = true;
        self::$seen = null;
    }

    /** Called by the provider helpers for each request they send; a no-op outside a logged call. */
    public static function note(string $url, ?string $body, ?int $status = null): void
    {
        if (!self::$active) {
            return;
        }
        self::$seen = ['url' => $url, 'body' => $body, 'status' => $status];
    }

    /** Called by the provider helpers when a response status becomes known. */
    public static function noteStatus(int $status): void
    {
        if (self::$active && self::$seen !== null && $status > 0) {
            self::$seen['status'] = $status;
        }
    }

    /** @return array{url: ?string, body: ?string, status: ?int} what the provider layer saw, empty when nothing was sent */
    public static function take(): array
    {
        $seen = self::$seen ?? ['url' => null, 'body' => null, 'status' => null];
        self::$active = false;
        self::$seen = null;

        return $seen;
    }

    /** Marks calls started from now on as replays of a logged request (null to clear). */
    public static function replayOf(?int $logId): void
    {
        self::$replayOf = $logId;
    }

    public static function replayedLogId(): ?int
    {
        return self::$replayOf;
    }

    /** Remembers the log id of the call that just began (LlmCallLog), so the replay tool can find its own row. */
    public static function setLastLogId(?int $id): void
    {
        self::$lastLogId = $id;
    }

    public static function lastLogId(): ?int
    {
        return self::$lastLogId;
    }
}
