<?php

// The mapped row of public.core_middleterm_replacement. Read-only mapping: reads go through MiddletermReplacementDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_middleterm_replacement', schema: 'public')]
class AbstractMiddletermReplacement
{
    #[ORM\Column(name: 'approval_id', type: 'bigint')]
    protected int|string $approvalId;

    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Id]
    #[ORM\Column(name: 'history_event_id', type: 'bigint')]
    protected int|string $historyEventId;

    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    #[ORM\Column(name: 'target_history_event_id', type: 'bigint')]
    protected int|string $targetHistoryEventId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'approval_id' => $this->approvalId,
            'generation' => $this->generation,
            'history_event_id' => $this->historyEventId,
            'session_id' => $this->sessionId,
            'target_history_event_id' => $this->targetHistoryEventId,
        ];
    }
}
