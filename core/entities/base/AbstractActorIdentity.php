<?php

// The mapped row of public.core_actor_identity. Read-only mapping: reads go through ActorIdentityDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_actor_identity', schema: 'public')]
class AbstractActorIdentity
{
    #[ORM\Column(name: 'display_name', type: 'text', nullable: true)]
    protected ?string $displayName = null;

    #[ORM\Column(name: 'first_seen_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $firstSeenAt;

    #[ORM\Column(name: 'full_form_id', type: 'string', length: 10)]
    protected string $fullFormId;

    #[ORM\Column(type: 'text')]
    protected string $game;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $id;

    #[ORM\Column(name: 'last_seen_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $lastSeenAt;

    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    protected mixed $metadata = null;

    #[ORM\Column(name: 'origin_plugin', type: 'text')]
    protected string $originPlugin;

    #[ORM\Column(name: 'playthrough_id', type: 'text')]
    protected string $playthroughId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'display_name' => $this->displayName,
            'first_seen_at' => $this->firstSeenAt,
            'full_form_id' => $this->fullFormId,
            'game' => $this->game,
            'id' => $this->id,
            'last_seen_at' => $this->lastSeenAt,
            'metadata' => $this->metadata,
            'origin_plugin' => $this->originPlugin,
            'playthrough_id' => $this->playthroughId,
        ];
    }
}
