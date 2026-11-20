<?php

// The mapped row of public.core_location_state: what one playthrough has observed about a location. Read-only mapping: LocationStateDao writes it with parameterized statements,
// so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_location_state', schema: 'public')]
class AbstractLocationState
{
    #[ORM\Id]
    #[ORM\Column(name: 'playthrough_id', type: 'text')]
    protected string $playthroughId;

    #[ORM\Id]
    #[ORM\Column(name: 'location_id', type: 'bigint')]
    protected int $locationId;

    #[ORM\Column(type: 'boolean', nullable: true)]
    protected ?bool $cleared = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    protected ?bool $discovered = null;

    #[ORM\Column(name: 'updated_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $updatedAt;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'playthrough_id' => $this->playthroughId,
            'location_id' => $this->locationId,
            'cleared' => $this->cleared,
            'discovered' => $this->discovered,
            'updated_at' => $this->updatedAt,
        ];
    }
}
