<?php

// The mapped row of public.core_memory_batch_source. Read-only mapping: reads go through MemoryBatchSourceDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_memory_batch_source', schema: 'public')]
class AbstractMemoryBatchSource
{
    #[ORM\Id]
    #[ORM\Column(name: 'batch_id', type: 'bigint')]
    protected int|string $batchId;

    #[ORM\Id]
    #[ORM\Column(name: 'history_event_id', type: 'bigint')]
    protected int|string $historyEventId;

    #[ORM\Column(type: 'integer')]
    protected int $position;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'history_event_id' => $this->historyEventId,
            'position' => $this->position,
        ];
    }
}
