<?php

// The mapped row of public.core_actor_event_batch. Read-only mapping: reads go through ActorEventBatchDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_actor_event_batch', schema: 'public')]
class AbstractActorEventBatch
{
    #[ORM\Id]
    #[ORM\Column(name: 'batch_id', type: 'text')]
    protected string $batchId;

    #[ORM\Column(name: 'capture_age_ms', type: 'bigint')]
    protected int|string $captureAgeMs;

    #[ORM\Column(name: 'context_sequence', type: 'bigint')]
    protected int|string $contextSequence;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Column(name: 'input_payload', type: 'json', options: ['jsonb' => true])]
    protected mixed $inputPayload = null;

    #[ORM\Column(name: 'player_payload', type: 'json', options: ['jsonb' => true])]
    protected mixed $playerPayload = null;

    #[ORM\Id]
    #[ORM\Column(name: 'playthrough_id', type: 'text')]
    protected string $playthroughId;

    #[ORM\Column(name: 'recorded_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $recordedAt;

    #[ORM\Column(name: 'request_id', type: 'text')]
    protected string $requestId;

    #[ORM\Id]
    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'capture_age_ms' => $this->captureAgeMs,
            'context_sequence' => $this->contextSequence,
            'generation' => $this->generation,
            'input_payload' => $this->inputPayload,
            'player_payload' => $this->playerPayload,
            'playthrough_id' => $this->playthroughId,
            'recorded_at' => $this->recordedAt,
            'request_id' => $this->requestId,
            'session_id' => $this->sessionId,
        ];
    }
}
