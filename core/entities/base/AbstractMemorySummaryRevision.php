<?php

// The mapped row of public.core_memory_summary_revision. Read-only mapping: reads go through MemorySummaryRevisionDao; writes stay in the
// statements that own them, so Doctrine never flushes it.
// Not mapped (DAO-only or unsupported types): native_vec.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_memory_summary_revision', schema: 'public')]
class AbstractMemorySummaryRevision
{
    #[ORM\Column(name: 'created_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $revision;

    #[ORM\Id]
    #[ORM\Column(name: 'summary_id', type: 'bigint')]
    protected int|string $summaryId;

    #[ORM\Column(type: 'text')]
    protected string $summary;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'created_at' => $this->createdAt,
            'revision' => $this->revision,
            'summary_id' => $this->summaryId,
            'summary' => $this->summary,
        ];
    }
}
