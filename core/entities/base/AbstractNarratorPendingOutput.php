<?php

// The mapped row of public.core_narrator_pending_output. Read-only mapping: reads go through NarratorPendingOutputDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_narrator_pending_output', schema: 'public')]
class AbstractNarratorPendingOutput
{
    #[ORM\Column(name: 'context_sequence', type: 'bigint')]
    protected int|string $contextSequence;

    #[ORM\Column(name: 'created_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'definition_edit_generation', type: 'bigint')]
    protected int|string $definitionEditGeneration;

    #[ORM\Column(name: 'definition_id', type: 'text')]
    protected string $definitionId;

    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Column(name: 'input_entry_id', type: 'bigint')]
    protected int|string $inputEntryId;

    #[ORM\Column(name: 'line_id', type: 'text')]
    protected string $lineId;

    #[ORM\Column(name: 'parent_state_id', type: 'bigint')]
    protected int|string $parentStateId;

    #[ORM\Column(name: 'playthrough_id', type: 'text')]
    protected string $playthroughId;

    #[ORM\Column(name: 'receipt_request_id', type: 'text', nullable: true)]
    protected ?string $receiptRequestId = null;

    #[ORM\Id]
    #[ORM\Column(name: 'request_id', type: 'text')]
    protected string $requestId;

    #[ORM\Column(name: 'revision_id', type: 'bigint')]
    protected int|string $revisionId;

    #[ORM\Id]
    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    #[ORM\Column(type: 'text')]
    protected string $state;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $text = null;

    #[ORM\Column(name: 'turn_id', type: 'text')]
    protected string $turnId;

    #[ORM\Column(name: 'updated_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $updatedAt;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'context_sequence' => $this->contextSequence,
            'created_at' => $this->createdAt,
            'definition_edit_generation' => $this->definitionEditGeneration,
            'definition_id' => $this->definitionId,
            'generation' => $this->generation,
            'input_entry_id' => $this->inputEntryId,
            'line_id' => $this->lineId,
            'parent_state_id' => $this->parentStateId,
            'playthrough_id' => $this->playthroughId,
            'receipt_request_id' => $this->receiptRequestId,
            'request_id' => $this->requestId,
            'revision_id' => $this->revisionId,
            'session_id' => $this->sessionId,
            'state' => $this->state,
            'text' => $this->text,
            'turn_id' => $this->turnId,
            'updated_at' => $this->updatedAt,
        ];
    }
}
