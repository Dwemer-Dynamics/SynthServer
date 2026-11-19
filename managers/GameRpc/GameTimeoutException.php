<?php

declare(strict_types=1);

namespace Synth\Managers\GameRpc;

/** The game did not answer within the call's time limit. */
final class GameTimeoutException extends GameRpcException
{
}
