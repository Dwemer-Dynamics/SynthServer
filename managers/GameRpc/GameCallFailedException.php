<?php

declare(strict_types=1);

namespace Synth\Managers\GameRpc;

/** The game answered with an error; the code is the game's own. */
final class GameCallFailedException extends GameRpcException
{
    public function __construct(public readonly string $errorCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
