<?php

// The mapped row of public.core_runtime_session. Read-only mapping: reads go through RuntimeSessionDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_runtime_session', schema: 'public')]
class AbstractRuntimeSession
{
    #[ORM\Column(name: 'active_generation', type: 'bigint')]
    protected int|string $activeGeneration;

    #[ORM\Column(name: 'active_turn_id', type: 'text', nullable: true)]
    protected ?string $activeTurnId = null;

    #[ORM\Column(name: 'active_turn_sequence', type: 'bigint')]
    protected int|string $activeTurnSequence;

    #[ORM\Column(name: 'audience_intent', type: 'text')]
    protected string $audienceIntent;

    #[ORM\Column(name: 'audience_reset_epoch', type: 'bigint')]
    protected int|string $audienceResetEpoch;

    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    protected mixed $capabilities = null;

    #[ORM\Column(name: 'client_version', type: 'text')]
    protected string $clientVersion;

    #[ORM\Column(name: 'created_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'text')]
    protected string $game;

    #[ORM\Column(name: 'interaction_dispatch_after', type: PreciseDateTimeType::NAME, nullable: true)]
    protected ?DateTimeImmutable $interactionDispatchAfter = null;

    #[ORM\Column(name: 'memory_dispatch_after', type: PreciseDateTimeType::NAME, nullable: true)]
    protected ?DateTimeImmutable $memoryDispatchAfter = null;

    #[ORM\Column(name: 'middleterm_dispatch_after', type: PreciseDateTimeType::NAME, nullable: true)]
    protected ?DateTimeImmutable $middletermDispatchAfter = null;

    #[ORM\Column(name: 'playthrough_id', type: 'text')]
    protected string $playthroughId;

    #[ORM\Column(name: 'relationship_dispatch_after', type: PreciseDateTimeType::NAME, nullable: true)]
    protected ?DateTimeImmutable $relationshipDispatchAfter = null;

    #[ORM\Column(name: 'runtime_variant', type: 'text')]
    protected string $runtimeVariant;

    #[ORM\Column(name: 'runtime_version', type: 'text')]
    protected string $runtimeVersion;

    #[ORM\Id]
    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    #[ORM\Column(type: 'text')]
    protected string $state;

    #[ORM\Column(name: 'updated_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $updatedAt;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'active_generation' => $this->activeGeneration,
            'active_turn_id' => $this->activeTurnId,
            'active_turn_sequence' => $this->activeTurnSequence,
            'audience_intent' => $this->audienceIntent,
            'audience_reset_epoch' => $this->audienceResetEpoch,
            'capabilities' => $this->capabilities,
            'client_version' => $this->clientVersion,
            'created_at' => $this->createdAt,
            'game' => $this->game,
            'interaction_dispatch_after' => $this->interactionDispatchAfter,
            'memory_dispatch_after' => $this->memoryDispatchAfter,
            'middleterm_dispatch_after' => $this->middletermDispatchAfter,
            'playthrough_id' => $this->playthroughId,
            'relationship_dispatch_after' => $this->relationshipDispatchAfter,
            'runtime_variant' => $this->runtimeVariant,
            'runtime_version' => $this->runtimeVersion,
            'session_id' => $this->sessionId,
            'state' => $this->state,
            'updated_at' => $this->updatedAt,
        ];
    }
}
