<?php

// The mapped row of public.core_narrator_state. Read-only mapping: reads go through NarratorStateDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_narrator_state', schema: 'public')]
class AbstractNarratorState
{
    #[ORM\Column(name: 'applied_revisions', type: 'json', options: ['jsonb' => true])]
    protected mixed $appliedRevisions = null;

    #[ORM\Column(type: 'text')]
    protected string $cause;

    #[ORM\Column(name: 'created_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Column(name: 'parent_state_id', type: 'bigint', nullable: true)]
    protected int|string|null $parentStateId = null;

    #[ORM\Column(name: 'persona_heads', type: 'json', options: ['jsonb' => true])]
    protected mixed $personaHeads = null;

    #[ORM\Column(name: 'playthrough_id', type: 'text')]
    protected string $playthroughId;

    #[ORM\Column(name: 'request_id', type: 'text')]
    protected string $requestId;

    #[ORM\Column(name: 'selected_definition_id', type: 'text', nullable: true)]
    protected ?string $selectedDefinitionId = null;

    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    #[ORM\Id]
    #[ORM\Column(name: 'state_id', type: 'bigint')]
    protected int|string $stateId;

    #[ORM\Column(name: 'transcript_head_entry_id', type: 'bigint', nullable: true)]
    protected int|string|null $transcriptHeadEntryId = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'applied_revisions' => $this->appliedRevisions,
            'cause' => $this->cause,
            'created_at' => $this->createdAt,
            'generation' => $this->generation,
            'parent_state_id' => $this->parentStateId,
            'persona_heads' => $this->personaHeads,
            'playthrough_id' => $this->playthroughId,
            'request_id' => $this->requestId,
            'selected_definition_id' => $this->selectedDefinitionId,
            'session_id' => $this->sessionId,
            'state_id' => $this->stateId,
            'transcript_head_entry_id' => $this->transcriptHeadEntryId,
        ];
    }
}
