<?php

// The mapped row of public.core_narrator_context_state. Read-only mapping: reads go through NarratorContextStateDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_narrator_context_state', schema: 'public')]
class AbstractNarratorContextState
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Column(name: 'playthrough_id', type: 'text')]
    protected string $playthroughId;

    #[ORM\Id]
    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    #[ORM\Id]
    #[ORM\Column(name: 'snapshot_sequence', type: 'bigint')]
    protected int|string $snapshotSequence;

    #[ORM\Column(name: 'state_id', type: 'bigint', nullable: true)]
    protected int|string|null $stateId = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'generation' => $this->generation,
            'playthrough_id' => $this->playthroughId,
            'session_id' => $this->sessionId,
            'snapshot_sequence' => $this->snapshotSequence,
            'state_id' => $this->stateId,
        ];
    }
}
