<?php

// The mapped row of public.core_action_intent. Read-only mapping: reads go through ActionIntentDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_action_intent', schema: 'public')]
class AbstractActionIntent
{
    #[ORM\Id]
    #[ORM\Column(name: 'action_id', type: 'text')]
    protected string $actionId;

    #[ORM\Column(name: 'action_name', type: 'text')]
    protected string $actionName;

    #[ORM\Column(name: 'actor_identity_id', type: 'bigint')]
    protected int|string $actorIdentityId;

    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    protected mixed $arguments = null;

    #[ORM\Column(type: 'text')]
    protected string $capability;

    #[ORM\Column(name: 'created_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'deadline_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $deadlineAt;

    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Column(name: 'idempotency_key', type: 'text')]
    protected string $idempotencyKey;

    #[ORM\Column(name: 'request_id', type: 'text')]
    protected string $requestId;

    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    #[ORM\Column(type: 'text')]
    protected string $state;

    #[ORM\Column(name: 'target_identity_id', type: 'bigint', nullable: true)]
    protected int|string|null $targetIdentityId = null;

    #[ORM\Column(name: 'turn_id', type: 'text')]
    protected string $turnId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action_id' => $this->actionId,
            'action_name' => $this->actionName,
            'actor_identity_id' => $this->actorIdentityId,
            'arguments' => $this->arguments,
            'capability' => $this->capability,
            'created_at' => $this->createdAt,
            'deadline_at' => $this->deadlineAt,
            'generation' => $this->generation,
            'idempotency_key' => $this->idempotencyKey,
            'request_id' => $this->requestId,
            'session_id' => $this->sessionId,
            'state' => $this->state,
            'target_identity_id' => $this->targetIdentityId,
            'turn_id' => $this->turnId,
        ];
    }
}
