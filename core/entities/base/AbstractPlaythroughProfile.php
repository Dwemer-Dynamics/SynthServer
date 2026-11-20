<?php

// The mapped row of public.core_playthrough_profile. Read-only mapping: reads go through PlaythroughProfileDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_playthrough_profile', schema: 'public')]
class AbstractPlaythroughProfile
{
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'eventlog_count', type: 'bigint', nullable: true)]
    protected int|string|null $eventlogCount = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $game = null;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    protected int $id;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    protected bool $isActive;

    #[ORM\Column(name: 'last_gamets', type: 'bigint', nullable: true)]
    protected int|string|null $lastGamets = null;

    #[ORM\Column(type: 'text')]
    protected string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $notes = null;

    #[ORM\Column(name: 'player_name', type: 'text', nullable: true)]
    protected ?string $playerName = null;

    #[ORM\Column(name: 'schema_name', type: 'text', nullable: true)]
    protected ?string $schemaName = null;

    #[ORM\Column(name: 'size_bytes', type: 'bigint')]
    protected int|string $sizeBytes;

    #[ORM\Column(name: 'storage_type', type: 'text', nullable: true)]
    protected ?string $storageType = null;

    #[ORM\Column(name: 'worldknowledge_count', type: 'bigint', nullable: true)]
    protected int|string|null $worldknowledgeCount = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'created_at' => $this->createdAt,
            'eventlog_count' => $this->eventlogCount,
            'game' => $this->game,
            'id' => $this->id,
            'is_active' => $this->isActive,
            'last_gamets' => $this->lastGamets,
            'name' => $this->name,
            'notes' => $this->notes,
            'player_name' => $this->playerName,
            'schema_name' => $this->schemaName,
            'size_bytes' => $this->sizeBytes,
            'storage_type' => $this->storageType,
            'worldknowledge_count' => $this->worldknowledgeCount,
        ];
    }
}
