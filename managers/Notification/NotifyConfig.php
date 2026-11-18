<?php

declare(strict_types=1);

namespace Synth\Managers\Notification;

/**
 * Where the wake-up channel listens. Read from the environment so a service launcher can set it without a schema change.
 *
 *   SYNTH_NOTIFY_HOST           host the plugin-facing WebSocket binds (default 127.0.0.1)
 *   SYNTH_NOTIFY_PORT           plugin-facing WebSocket port (default 8091)
 *   SYNTH_NOTIFY_INTERNAL_PORT  loopback-only publish port used by the server's own workers (default 8092)
 *
 * The plugin-facing host defaults to loopback. A remote installation binds it to the address the remote-hosting
 * guide uses for the server (typically 0.0.0.0 inside WSL behind a Windows port rule) and opens only that port.
 */
final class NotifyConfig
{
    public const DEFAULT_HOST = '127.0.0.1';
    public const DEFAULT_PORT = 8091;
    public const DEFAULT_INTERNAL_PORT = 8092;
    public const INTERNAL_HOST = '127.0.0.1';

    public static function host(): string
    {
        $value = getenv('SYNTH_NOTIFY_HOST');
        $host = is_string($value) ? trim($value) : '';
        // An IP literal only: the value ends up in a socket address, never in a shell or URL.
        return $host !== '' && filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : self::DEFAULT_HOST;
    }

    public static function port(): int
    {
        return self::portFrom('SYNTH_NOTIFY_PORT', self::DEFAULT_PORT);
    }

    public static function internalPort(): int
    {
        return self::portFrom('SYNTH_NOTIFY_INTERNAL_PORT', self::DEFAULT_INTERNAL_PORT);
    }

    public static function webSocketAddress(): string
    {
        $host = self::host();
        return 'tcp://' . (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . self::port();
    }

    public static function internalAddress(): string
    {
        return 'tcp://' . self::INTERNAL_HOST . ':' . self::internalPort();
    }

    private static function portFrom(string $name, int $default): int
    {
        $value = getenv($name);
        if (!is_string($value) || preg_match('/^[0-9]{4,5}$/D', $value) !== 1) {
            return $default;
        }
        $port = (int) $value;
        return $port >= 1024 && $port <= 65535 ? $port : $default;
    }
}
