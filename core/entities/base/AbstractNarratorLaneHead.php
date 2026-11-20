<?php

// The mapped row of public.core_narrator_lane_head. Read-only mapping: reads go through NarratorLaneHeadDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_narrator_lane_head', schema: 'public')]
class AbstractNarratorLaneHead
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Column(name: 'playthrough_id', type: 'text')]
    protected string $playthroughId;

    #[ORM\Id]
    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    #[ORM\Column(name: 'state_id', type: 'bigint', nullable: true)]
    protected int|string|null $stateId = null;

    #[ORM\Column(name: 'updated_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $updatedAt;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'generation' => $this->generation,
            'playthrough_id' => $this->playthroughId,
            'session_id' => $this->sessionId,
            'state_id' => $this->stateId,
            'updated_at' => $this->updatedAt,
        ];
    }
}
