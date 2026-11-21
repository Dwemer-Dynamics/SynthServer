<?php

declare(strict_types=1);

namespace Synth\Managers\Notification;


/**
 * Tells the running notification channel that the plugin should look again. It is a best-effort hint: every call
 * returns quickly and never throws, and nothing changes when the channel is not running (the plugin polls anyway).
 */
final class NotifyPublisher
{
    private const CONNECT_SECONDS = 0.15;

    /** Whether a notification server answers on the internal port right now. */
    public static function isRunning(): bool
    {
        $socket = self::connect();
        if ($socket === null) {
            return false;
        }
        fclose($socket);
        return true;
    }

    /** The plugin-facing port to acknowledge in init, or null when the channel is not running. */
    public static function acknowledgedPort(): ?int
    {
        return self::isRunning() ? NotifyConfig::port() : null;
    }

    public static function publish(string $topic): bool
    {
        if (!in_array($topic, NotifyHint::TOPICS, true)) {
            return false;
        }
        return self::post(['topic' => $topic]);
    }

    /**
     * Announces one completed queued message by id. True only when the channel is running and at least one client received
     * the frame, so a false result means the announcement should be retried (the message itself is already stored).
     */
    public static function publishMessage(int $messageId): bool
    {
        return $messageId >= 1 && self::post(['topic' => 'message', 'message_id' => $messageId]);
    }

    /** @param array<string,mixed> $document */
    private static function post(array $document): bool
    {
        $socket = self::connect();
        if ($socket === null) {
            return false;
        }
        $body = json_encode($document, JSON_THROW_ON_ERROR);
        $request = "POST /notify HTTP/1.1\r\nHost: " . NotifyConfig::INTERNAL_HOST
            . "\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body)
            . "\r\nConnection: close\r\n\r\n" . $body;
        stream_set_timeout($socket, 1);
        $written = @fwrite($socket, $request);
        $reply = $written === strlen($request) ? @fgets($socket, 64) : false;
        fclose($socket);
        return is_string($reply) && str_starts_with($reply, 'HTTP/1.1 204');
    }

    /** @return resource|null */
    private static function connect()
    {
        $socket = @stream_socket_client(
            NotifyConfig::internalAddress(),
            $errorNumber,
            $errorMessage,
            self::CONNECT_SECONDS
        );
        return is_resource($socket) ? $socket : null;
    }
}
