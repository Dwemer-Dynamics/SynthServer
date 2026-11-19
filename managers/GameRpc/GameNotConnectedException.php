<?php

declare(strict_types=1);

namespace Synth\Managers\GameRpc;

/** No game is connected, or no call path to it exists in this server. */
final class GameNotConnectedException extends GameRpcException
{
}
