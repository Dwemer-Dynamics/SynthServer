<?php

// The outcome of one registration: a status from the runtime-actor vocabulary (docs/RUNTIME-ACTORS.md, section 5.2), a reason
// when it was rejected, and the character the actor belongs to.

declare(strict_types=1);

namespace Synth\Managers\NpcRegistration;

/** Immutable result of NpcRegistration. */
final class RegistrationResult
{
    public const REGISTERED = 'registered';
    public const EXISTING = 'existing';
    public const RE_ADOPTED = 're_adopted';
    public const REJECTED = 'rejected';

    private function __construct(
        public readonly string $status,
        public readonly ?string $reason,
        public readonly ?int $characterId,
    ) {
    }

    /** An accepted registration. */
    public static function accepted(string $status, int $characterId): self
    {
        return new self($status, null, $characterId);
    }

    /** A refused registration; nothing was stored. */
    public static function rejected(string $reason): self
    {
        return new self(self::REJECTED, $reason, null);
    }

    /** Whether the actor is now stored. */
    public function isAccepted(): bool
    {
        return $this->status !== self::REJECTED;
    }

    /** @return array{status: string, reason: ?string, character_id: ?int} */
    public function toArray(): array
    {
        return ['status' => $this->status, 'reason' => $this->reason, 'character_id' => $this->characterId];
    }
}
