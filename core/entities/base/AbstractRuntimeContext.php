<?php

// The mapped row of public.core_runtime_context. Read-only mapping: reads go through RuntimeContextDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_runtime_context', schema: 'public')]
class AbstractRuntimeContext
{
    #[ORM\Column(name: 'audience_identity_ids', type: 'json', options: ['jsonb' => true])]
    protected mixed $audienceIdentityIds = null;

    #[ORM\Column(name: 'context_payload', type: 'json', options: ['jsonb' => true])]
    protected mixed $contextPayload = null;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Column(name: 'history_event_id', type: 'bigint', nullable: true)]
    protected int|string|null $historyEventId = null;

    #[ORM\Column(name: 'player_identity_id', type: 'bigint')]
    protected int|string $playerIdentityId;

    #[ORM\Column(name: 'playthrough_id', type: 'text')]
    protected string $playthroughId;

    #[ORM\Column(name: 'received_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $receivedAt;

    #[ORM\Id]
    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    #[ORM\Id]
    #[ORM\Column(name: 'snapshot_sequence', type: 'bigint')]
    protected int|string $snapshotSequence;

    #[ORM\Column(name: 'target_identity_id', type: 'bigint', nullable: true)]
    protected int|string|null $targetIdentityId = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'audience_identity_ids' => $this->audienceIdentityIds,
            'context_payload' => $this->contextPayload,
            'generation' => $this->generation,
            'history_event_id' => $this->historyEventId,
            'player_identity_id' => $this->playerIdentityId,
            'playthrough_id' => $this->playthroughId,
            'received_at' => $this->receivedAt,
            'session_id' => $this->sessionId,
            'snapshot_sequence' => $this->snapshotSequence,
            'target_identity_id' => $this->targetIdentityId,
        ];
    }
}
