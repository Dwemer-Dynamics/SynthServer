<?php

// The mapped row of public.core_dialogue_route. Read-only mapping: reads go through DialogueRouteDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_dialogue_route', schema: 'public')]
class AbstractDialogueRoute
{
    #[ORM\Column(name: 'context_sequence', type: 'bigint')]
    protected int|string $contextSequence;

    #[ORM\Column(name: 'created_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Id]
    #[ORM\Column(name: 'line_id', type: 'text')]
    protected string $lineId;

    #[ORM\Column(name: 'line_sha256', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $lineSha256;

    #[ORM\Column(name: 'listener_state', type: 'text')]
    protected string $listenerState;

    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    protected mixed $listener = null;

    #[ORM\Id]
    #[ORM\Column(name: 'request_id', type: 'text')]
    protected string $requestId;

    #[ORM\Id]
    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    protected mixed $speaker = null;

    #[ORM\Column(name: 'utterance_id', type: 'text', nullable: true)]
    protected ?string $utteranceId = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'context_sequence' => $this->contextSequence,
            'created_at' => $this->createdAt,
            'generation' => $this->generation,
            'line_id' => $this->lineId,
            'line_sha256' => $this->lineSha256,
            'listener_state' => $this->listenerState,
            'listener' => $this->listener,
            'request_id' => $this->requestId,
            'session_id' => $this->sessionId,
            'speaker' => $this->speaker,
            'utterance_id' => $this->utteranceId,
        ];
    }
}
