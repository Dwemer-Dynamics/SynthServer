<?php

// The mapped row of public.core_narrator_transcript_entry. Read-only mapping: reads go through NarratorTranscriptEntryDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_narrator_transcript_entry', schema: 'public')]
class AbstractNarratorTranscriptEntry
{
    #[ORM\Column(name: 'created_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'definition_id', type: 'text')]
    protected string $definitionId;

    #[ORM\Id]
    #[ORM\Column(name: 'entry_id', type: 'bigint')]
    protected int|string $entryId;

    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Column(type: 'text')]
    protected string $kind;

    #[ORM\Column(name: 'parent_entry_id', type: 'bigint', nullable: true)]
    protected int|string|null $parentEntryId = null;

    #[ORM\Column(name: 'parent_persona_entry_id', type: 'bigint', nullable: true)]
    protected int|string|null $parentPersonaEntryId = null;

    #[ORM\Column(name: 'playthrough_id', type: 'text')]
    protected string $playthroughId;

    #[ORM\Column(name: 'request_id', type: 'text')]
    protected string $requestId;

    #[ORM\Column(name: 'revision_id', type: 'bigint')]
    protected int|string $revisionId;

    #[ORM\Column(type: 'text')]
    protected string $role;

    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    #[ORM\Column(name: 'speaker_name', type: 'text')]
    protected string $speakerName;

    #[ORM\Column(type: 'text')]
    protected string $text;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'created_at' => $this->createdAt,
            'definition_id' => $this->definitionId,
            'entry_id' => $this->entryId,
            'generation' => $this->generation,
            'kind' => $this->kind,
            'parent_entry_id' => $this->parentEntryId,
            'parent_persona_entry_id' => $this->parentPersonaEntryId,
            'playthrough_id' => $this->playthroughId,
            'request_id' => $this->requestId,
            'revision_id' => $this->revisionId,
            'role' => $this->role,
            'session_id' => $this->sessionId,
            'speaker_name' => $this->speakerName,
            'text' => $this->text,
        ];
    }
}
