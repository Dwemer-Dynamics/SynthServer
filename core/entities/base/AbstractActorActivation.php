<?php

// The mapped row of public.core_actor_activation. Read-only mapping: reads go through ActorActivationDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_actor_activation', schema: 'public')]
class AbstractActorActivation
{
    #[ORM\Column(name: 'activated_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $activatedAt;

    #[ORM\Id]
    #[ORM\Column(name: 'actor_identity_id', type: 'bigint')]
    protected int|string $actorIdentityId;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Column(name: 'last_seen_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $lastSeenAt;

    #[ORM\Id]
    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    #[ORM\Column(type: 'text')]
    protected string $source;

    #[ORM\Column(type: 'text')]
    protected string $state;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'activated_at' => $this->activatedAt,
            'actor_identity_id' => $this->actorIdentityId,
            'generation' => $this->generation,
            'last_seen_at' => $this->lastSeenAt,
            'session_id' => $this->sessionId,
            'source' => $this->source,
            'state' => $this->state,
        ];
    }
}
