<?php

namespace Synth\Core;

require_once __DIR__.'/dialogue.php';

// Private narrator output: the turn output plus typed state/reply/status lines and terminal refusals.
interface NarratorOutput extends TurnOutput
{
    public function emit(string $type, string $suffix, array $payload): void;
    public function terminal(string $code, string $detail = ''): void;
}

// A private narrator refusal. The message is the terminal status code sent to the client.
final class NarratorRejected extends \DomainException {}
