<?php

declare(strict_types=1);

namespace Synth\Managers\GameRpc;

/**
 * The client SYNTH uses today. The server has no channel on which the game answers a call (the notify-server carries
 * wake-up hints only), so every call reports that no game is reachable. Callers written against GameRpcClient keep
 * working unchanged when a real client replaces this one.
 */
final class UnwiredGameRpcClient implements GameRpcClient
{
    public function call(string $method, array $params = [], int $timeoutMs = 3000): array
    {
        throw new GameNotConnectedException('No game RPC channel is wired in this server.');
    }
}
