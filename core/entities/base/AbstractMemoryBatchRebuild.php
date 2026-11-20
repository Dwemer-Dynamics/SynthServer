<?php

// The mapped row of public.core_memory_batch_rebuild. Read-only mapping: reads go through MemoryBatchRebuildDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_memory_batch_rebuild', schema: 'public')]
class AbstractMemoryBatchRebuild
{
    #[ORM\Id]
    #[ORM\Column(name: 'batch_id', type: 'bigint')]
    protected int|string $batchId;

    #[ORM\Column(name: 'root_batch_id', type: 'bigint')]
    protected int|string $rootBatchId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'root_batch_id' => $this->rootBatchId,
        ];
    }
}
