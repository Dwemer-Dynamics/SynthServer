<?php

namespace Synth\Core;

// Admission refusal. The reason is reported as a terminal ineligible turn; there is no legacy fallback.
final class TurnRejected extends \DomainException {}

// Output and liveness for one claimed turn. assertActive throws the protocol cancellation when the request
// is stale or cancelled; it is checked at every side-effect boundary.
interface TurnOutput
{
    public function assertActive(): void;
    /** Allocates the next dialogue line suffix ("dialogue:N"). */
    public function allocateLine(): string;
    public function dialogue(string $suffix, string $lineId, array $payload): void;
    public function complete(): void;
    public function fail(string $code, string $detail): void;
    public function reject(string $reason): void;
}

// Plain text deltas from one stored LLM connector. Credentials and transport stay inside the driver.
interface LlmDriver
{
    public function supports(array $connector): bool;
    /** @param callable(string):void $onText */
    public function stream(array $connector, array $messages, callable $onText): void;
}

// Speech owned by the NPC profile: explicit inputs, then one durable job per line.
interface SpeechDriver
{
    /** Null when the profile has no portable configured speech. */
    public function settings(array $npcRow, array $profile): ?array;
    /** Queues one line and returns its cache key; throws when the line cannot be voiced. */
    public function queue(array $speaker, string $lineId, string $text, array $settings): string;
}
