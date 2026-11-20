<?php

// The mapped row of public.core_memory_summary. Read-only mapping: reads go through MemorySummaryDao; writes stay in the
// statements that own them, so Doctrine never flushes it.
// Not mapped (DAO-only or unsupported types): embedding, native_vec.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_memory_summary', schema: 'public')]
class AbstractMemorySummary
{
    #[ORM\Column(name: 'batch_id', type: 'bigint')]
    protected int|string $batchId;

    #[ORM\Column(name: 'created_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $id;

    #[ORM\Column(name: 'job_id', type: 'bigint')]
    protected int|string $jobId;

    #[ORM\Column(type: 'text')]
    protected string $summary;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'created_at' => $this->createdAt,
            'id' => $this->id,
            'job_id' => $this->jobId,
            'summary' => $this->summary,
        ];
    }
}
