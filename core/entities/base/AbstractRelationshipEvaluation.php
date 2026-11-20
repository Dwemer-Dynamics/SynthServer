<?php

// The mapped row of public.core_relationship_evaluation. Read-only mapping: reads go through RelationshipEvaluationDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_relationship_evaluation', schema: 'public')]
class AbstractRelationshipEvaluation
{
    #[ORM\Column(name: 'actor_identity_id', type: 'bigint')]
    protected int|string $actorIdentityId;

    #[ORM\Column(name: 'context_sequence', type: 'bigint')]
    protected int|string $contextSequence;

    #[ORM\Column(name: 'created_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $id;

    #[ORM\Column(name: 'input_payload', type: 'json', options: ['jsonb' => true])]
    protected mixed $inputPayload = null;

    #[ORM\Column(name: 'input_sha256', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $inputSha256;

    #[ORM\Column(name: 'request_id', type: 'text')]
    protected string $requestId;

    #[ORM\Column(name: 'response_history_event_id', type: 'bigint')]
    protected int|string $responseHistoryEventId;

    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'actor_identity_id' => $this->actorIdentityId,
            'context_sequence' => $this->contextSequence,
            'created_at' => $this->createdAt,
            'generation' => $this->generation,
            'id' => $this->id,
            'input_payload' => $this->inputPayload,
            'input_sha256' => $this->inputSha256,
            'request_id' => $this->requestId,
            'response_history_event_id' => $this->responseHistoryEventId,
            'session_id' => $this->sessionId,
        ];
    }
}
