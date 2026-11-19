<?php

/**
 * Gives API scripts the body of the current HTTP request, bounded by the route's limit.
 *
 * `api/index.php` sets the limit before a handler runs. A handler that reads
 * through here can never pull more than one byte past it from the connection,
 * so the caller tells an oversized body by its length.
 */
declare(strict_types=1);

namespace Synth\Lib;

final class RequestBody
{
    private static ?int $maxBytes = null;

    /**
     * Returns the request body, or false when it cannot be read.
     */
    public static function read(): string|false
    {
        return self::$maxBytes === null
            ? file_get_contents('php://input')
            : file_get_contents('php://input', false, null, 0, self::$maxBytes + 1);
    }

    /**
     * Bounds every later read of the body, for a route whose body may be large binary data.
     */
    public static function limit(int $maxBytes): void
    {
        self::$maxBytes = max(0, $maxBytes);
    }

    /**
     * Returns the status a declared Content-Length earns against a limit: 411 when it is missing or not a
     * number, 413 when it is above the limit, null when the body may be read.
     *
     * @param array<string, mixed> $server The request's $_SERVER values.
     */
    public static function refusalStatus(array $server, int $maxBytes): ?int
    {
        $declared = $server['CONTENT_LENGTH'] ?? null;
        if (!is_string($declared) || !ctype_digit($declared)) {
            return 411;
        }

        return strlen(ltrim($declared, '0')) > 18 || (int) $declared > $maxBytes ? 413 : null;
    }

    /**
     * Forgets the limit; used by tests.
     */
    public static function reset(): void
    {
        self::$maxBytes = null;
    }
}
