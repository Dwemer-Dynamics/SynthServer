<?php

// The result of resolving one typed reference: the wire vocabulary of docs/RUNTIME-ACTORS.md section 4.1 (resolved,
// unresolved or rejected, with a reason) plus what was found. An unresolved reference never rejects its request.

declare(strict_types=1);

namespace Synth\Managers\ReferenceResolver;

/** Immutable outcome of ReferenceResolver::resolve(). */
final class Resolution
{
    public const RESOLVED = 'resolved';
    public const UNRESOLVED = 'unresolved';
    public const REJECTED = 'rejected';

    private function __construct(
        public readonly string $status,
        public readonly ?string $reason,
        public readonly ?int $characterId = null,
        public readonly ?string $plugin = null,
        public readonly ?string $localId = null,
        public readonly ?string $runtimeActorId = null,
    ) {
    }

    /** A reference that names a stored actor. */
    public static function resolved(int $characterId, ?string $plugin, ?string $localId, ?string $runtimeActorId): self
    {
        return new self(self::RESOLVED, null, $characterId, $plugin, $localId, $runtimeActorId);
    }

    /** A reference that may resolve later (deferred). */
    public static function unresolved(string $reason, ?string $plugin = null, ?string $localId = null, ?string $runtimeActorId = null): self
    {
        return new self(self::UNRESOLVED, $reason, null, $plugin, $localId, $runtimeActorId);
    }

    /** A reference that is wrong and must be corrected and resent. */
    public static function rejected(string $reason): self
    {
        return new self(self::REJECTED, $reason);
    }

    /** @return array{status: string, reason: ?string, character_id: ?int} the fields echoed on a returned typed reference */
    public function toWire(): array
    {
        return ['status' => $this->status, 'reason' => $this->reason, 'character_id' => $this->characterId];
    }
}
