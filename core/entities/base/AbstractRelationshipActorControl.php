<?php

// The mapped row of public.core_relationship_actor_control. Read-only mapping: reads go through RelationshipActorControlDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_relationship_actor_control', schema: 'public')]
class AbstractRelationshipActorControl
{
    #[ORM\Id]
    #[ORM\Column(name: 'actor_identity_id', type: 'bigint')]
    protected int|string $actorIdentityId;

    #[ORM\Column(name: 'barrier_event_id', type: 'bigint')]
    protected int|string $barrierEventId;

    #[ORM\Column(name: 'changed_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $changedAt;

    #[ORM\Column(type: 'boolean')]
    protected bool $locked;

    #[ORM\Column(type: 'bigint')]
    protected int|string $revision;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'actor_identity_id' => $this->actorIdentityId,
            'barrier_event_id' => $this->barrierEventId,
            'changed_at' => $this->changedAt,
            'locked' => $this->locked,
            'revision' => $this->revision,
        ];
    }
}
