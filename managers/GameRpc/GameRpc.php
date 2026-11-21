<?php

// GameRpc: picks the GameRpcClient for a session. The real client is used only when the session's game is connected on the
// channel right now; otherwise callers get the unwired client, whose calls fail with GameNotConnectedException, so code
// written against GameRpcClient never needs to know whether the channel exists.

declare(strict_types=1);

namespace Synth\Managers\GameRpc;

/** Factory for the default GameRpcClient. */
final class GameRpc
{
    /** The client for a session: over the channel while its game is connected, else UnwiredGameRpcClient. */
    public static function forSession(string $sessionId): GameRpcClient
    {
        return $sessionId !== '' && RpcLoopback::isConnected($sessionId) ? new NotifyGameRpcClient($sessionId) : new UnwiredGameRpcClient();
    }
}
