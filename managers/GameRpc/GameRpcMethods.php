<?php

// The closed list of calls the server may make to the running game, and the bounds of their parameters. The same list is the
// `method` enum of protocol/v2/schemas/rpc-frame.schema.json. A method here is only a request the game may answer: the client
// answers `method_unavailable` for one whose game feature it does not have, so nothing in this list is guaranteed to succeed.

declare(strict_types=1);

namespace Synth\Managers\GameRpc;

/** Allowlist and parameter validation for game calls. */
final class GameRpcMethods
{
    public const PING = 'ping';
    public const SESSION_INFO = 'session.info';
    public const INVENTORY_PLAYER = 'inventory.player';
    public const NEARBY_ITEMS = 'world.nearby_items';
    public const METHODS = [self::PING, self::SESSION_INFO, self::INVENTORY_PLAYER, self::NEARBY_ITEMS];
    /** The `error` codes a game may answer with; no free text crosses the wire. */
    public const ERRORS = ['method_unavailable', 'invalid_params', 'busy', 'failed'];
    public const MIN_TIMEOUT_MS = 100;
    public const MAX_TIMEOUT_MS = 10000;

    /** Whether the method is on the allowlist. */
    public static function isAllowed(string $method): bool
    {
        return in_array($method, self::METHODS, true);
    }

    /**
     * The parameters a method accepts, or null when they are not acceptable. Unknown keys are refused, not dropped.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public static function cleanParams(string $method, array $params): ?array
    {
        if ($method === self::NEARBY_ITEMS) {
            $clean = [];
            foreach ($params as $key => $value) {
                if ($key === 'radius' && is_int($value) && $value >= 1 && $value <= 5000) {
                    $clean['radius'] = $value;
                } elseif ($key === 'limit' && is_int($value) && $value >= 1 && $value <= 50) {
                    $clean['limit'] = $value;
                } else {
                    return null;
                }
            }

            return $clean;
        }

        return self::isAllowed($method) && $params === [] ? [] : null;
    }

    /** A timeout inside the bounds the protocol allows. */
    public static function clampTimeout(int $milliseconds): int
    {
        return max(self::MIN_TIMEOUT_MS, min(self::MAX_TIMEOUT_MS, $milliseconds));
    }
}
