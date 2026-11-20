<?php

// The mapped row of public.core_memory_batch_summary_source. Read-only mapping: reads go through MemoryBatchSummarySourceDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_memory_batch_summary_source', schema: 'public')]
class AbstractMemoryBatchSummarySource
{
    #[ORM\Id]
    #[ORM\Column(name: 'batch_id', type: 'bigint')]
    protected int|string $batchId;

    #[ORM\Column(name: 'summary_id', type: 'bigint')]
    protected int|string $summaryId;

    #[ORM\Column(name: 'summary_revision', type: 'bigint')]
    protected int|string $summaryRevision;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'summary_id' => $this->summaryId,
            'summary_revision' => $this->summaryRevision,
        ];
    }
}
