<?php

// The mapped row of public.core_narrator_transcript_cursor. Read-only mapping: reads go through NarratorTranscriptCursorDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_narrator_transcript_cursor', schema: 'public')]
class AbstractNarratorTranscriptCursor
{
    #[ORM\Id]
    #[ORM\Column(name: 'cursor_id', type: 'text')]
    protected string $cursorId;

    #[ORM\Column(name: 'next_entry_id', type: 'bigint')]
    protected int|string $nextEntryId;

    #[ORM\Column(name: 'state_id', type: 'bigint')]
    protected int|string $stateId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'cursor_id' => $this->cursorId,
            'next_entry_id' => $this->nextEntryId,
            'state_id' => $this->stateId,
        ];
    }
}
