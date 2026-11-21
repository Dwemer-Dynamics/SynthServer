<?php

// NotifyGameRpcClient: GameRpcClient over the notify-server's game RPC bridge. It calls the running game of one session through
// the loopback listener; the bridge forwards the call on the session's bound plugin connection and waits for the answer.

declare(strict_types=1);

namespace Synth\Managers\GameRpc;

/** A synchronous call to the game of one session. */
final class NotifyGameRpcClient implements GameRpcClient
{
    public function __construct(private readonly string $sessionId)
    {
    }

    public function call(string $method, array $params = [], int $timeoutMs = 3000): array
    {
        $clean = GameRpcMethods::cleanParams($method, $params);
        if ($clean === null) {
            throw new GameRpcException('The method or its parameters are not allowed.');
        }
        $timeout = GameRpcMethods::clampTimeout($timeoutMs);
        $reply = RpcLoopback::post('/rpc/call', ['session_id' => $this->sessionId, 'method' => $method, 'params' => (object) $clean,
            'timeout_ms' => $timeout], $timeout / 1000 + 0.75);
        if ($reply === null) {
            throw new GameNotConnectedException('The game RPC channel is not reachable.');
        }
        if ($reply['status'] !== 200) {
            throw new GameRpcException('The game RPC channel refused the call.');
        }
        $body = $reply['body'];
        if (($body['ok'] ?? false) === true && is_array($body['result'] ?? null)) {
            return $body['result'];
        }
        $error = is_string($body['error'] ?? null) ? $body['error'] : 'failed';
        if ($error === 'not_connected') {
            throw new GameNotConnectedException('No game is connected for this session.');
        }
        if ($error === 'timeout') {
            throw new GameTimeoutException('The game did not answer in time.');
        }
        if ($error === 'busy' || $error === 'invalid') {
            throw new GameRpcException('The game RPC channel could not take the call ('.$error.').');
        }

        throw new GameCallFailedException(in_array($error, GameRpcMethods::ERRORS, true) ? $error : 'failed');
    }
}
