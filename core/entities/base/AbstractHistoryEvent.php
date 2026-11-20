<?php

// The mapped row of public.core_history_event. Read-only mapping: reads go through HistoryEventDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_history_event', schema: 'public')]
class AbstractHistoryEvent
{
    #[ORM\Column(name: 'context_sequence', type: 'bigint')]
    protected int|string $contextSequence;

    #[ORM\Column(name: 'event_payload', type: 'json', options: ['jsonb' => true])]
    protected mixed $eventPayload = null;

    #[ORM\Column(name: 'eventlog_rowid', type: 'bigint')]
    protected int|string $eventlogRowid;

    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $id;

    #[ORM\Column(name: 'playthrough_id', type: 'text')]
    protected string $playthroughId;

    #[ORM\Column(name: 'recorded_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $recordedAt;

    #[ORM\Column(name: 'request_id', type: 'text', nullable: true)]
    protected ?string $requestId = null;

    #[ORM\Column(name: 'scene_context_sequence', type: 'bigint', nullable: true)]
    protected int|string|null $sceneContextSequence = null;

    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'context_sequence' => $this->contextSequence,
            'event_payload' => $this->eventPayload,
            'eventlog_rowid' => $this->eventlogRowid,
            'generation' => $this->generation,
            'id' => $this->id,
            'playthrough_id' => $this->playthroughId,
            'recorded_at' => $this->recordedAt,
            'request_id' => $this->requestId,
            'scene_context_sequence' => $this->sceneContextSequence,
            'session_id' => $this->sessionId,
        ];
    }
}
