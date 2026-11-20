<?php

// The mapped row of public.core_memory_batch_control_event. Read-only mapping: reads go through MemoryBatchControlEventDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_memory_batch_control_event', schema: 'public')]
class AbstractMemoryBatchControlEvent
{
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $action = null;

    #[ORM\Column(type: 'boolean')]
    protected bool $archived;

    #[ORM\Id]
    #[ORM\Column(name: 'batch_id', type: 'bigint')]
    protected int|string $batchId;

    #[ORM\Column(name: 'changed_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $changedAt;

    #[ORM\Column(name: 'context_sequence', type: 'bigint')]
    protected int|string $contextSequence;

    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $revision;

    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    #[ORM\Column(name: 'subject_batch_id', type: 'bigint', nullable: true)]
    protected int|string|null $subjectBatchId = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'archived' => $this->archived,
            'batch_id' => $this->batchId,
            'changed_at' => $this->changedAt,
            'context_sequence' => $this->contextSequence,
            'generation' => $this->generation,
            'revision' => $this->revision,
            'session_id' => $this->sessionId,
            'subject_batch_id' => $this->subjectBatchId,
        ];
    }
}
