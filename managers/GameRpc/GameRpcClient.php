<?php

declare(strict_types=1);

namespace Synth\Managers\GameRpc;

/** A synchronous call from the server to the running game. */
interface GameRpcClient
{
    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed> the game's result
     * @throws GameNotConnectedException when no game can be reached
     * @throws GameTimeoutException when the game does not answer within $timeoutMs
     * @throws GameCallFailedException when the game answers with an error
     * @throws GameRpcException for any other transport failure
     */
    public function call(string $method, array $params = [], int $timeoutMs = 3000): array;
}
