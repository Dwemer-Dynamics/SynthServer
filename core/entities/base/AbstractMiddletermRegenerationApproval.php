<?php

// The mapped row of public.core_middleterm_regeneration_approval. Read-only mapping: reads go through MiddletermRegenerationApprovalDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_middleterm_regeneration_approval', schema: 'public')]
class AbstractMiddletermRegenerationApproval
{
    #[ORM\Column(name: 'actor_identity_id', type: 'bigint')]
    protected int|string $actorIdentityId;

    #[ORM\Column(name: 'approval_payload', type: 'json', options: ['jsonb' => true])]
    protected mixed $approvalPayload = null;

    #[ORM\Column(name: 'approval_sha256', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $approvalSha256;

    #[ORM\Column(name: 'approval_token', type: 'string', length: 32, options: ['fixed' => true])]
    protected string $approvalToken;

    #[ORM\Column(name: 'context_sequence', type: 'bigint')]
    protected int|string $contextSequence;

    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Id]
    #[ORM\Column(name: 'history_event_id', type: 'bigint')]
    protected int|string $historyEventId;

    #[ORM\Column(name: 'last_considered_at', type: PreciseDateTimeType::NAME, nullable: true)]
    protected ?DateTimeImmutable $lastConsideredAt = null;

    #[ORM\Column(name: 'narrative_id', type: 'bigint')]
    protected int|string $narrativeId;

    #[ORM\Column(name: 'playthrough_id', type: 'text')]
    protected string $playthroughId;

    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'actor_identity_id' => $this->actorIdentityId,
            'approval_payload' => $this->approvalPayload,
            'approval_sha256' => $this->approvalSha256,
            'approval_token' => $this->approvalToken,
            'context_sequence' => $this->contextSequence,
            'generation' => $this->generation,
            'history_event_id' => $this->historyEventId,
            'last_considered_at' => $this->lastConsideredAt,
            'narrative_id' => $this->narrativeId,
            'playthrough_id' => $this->playthroughId,
            'session_id' => $this->sessionId,
        ];
    }
}
