<?php

// The mapped row of public.core_worker_job. Read-only mapping: reads go through WorkerJobDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_worker_job', schema: 'public')]
class AbstractWorkerJob
{
    #[ORM\Column(name: 'actor_identity_id', type: 'bigint', nullable: true)]
    protected int|string|null $actorIdentityId = null;

    #[ORM\Column(type: 'integer')]
    protected int $attempts;

    #[ORM\Column(name: 'available_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $availableAt;

    #[ORM\Column(name: 'cache_key', type: 'text', nullable: true)]
    protected ?string $cacheKey = null;

    #[ORM\Column(name: 'created_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'deadline_at', type: PreciseDateTimeType::NAME, nullable: true)]
    protected ?DateTimeImmutable $deadlineAt = null;

    #[ORM\Column(name: 'diary_input_id', type: 'bigint', nullable: true)]
    protected int|string|null $diaryInputId = null;

    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $id;

    #[ORM\Column(name: 'job_type', type: 'text')]
    protected string $jobType;

    #[ORM\Column(name: 'last_error', type: 'text', nullable: true)]
    protected ?string $lastError = null;

    #[ORM\Column(name: 'lease_token', type: 'text', nullable: true)]
    protected ?string $leaseToken = null;

    #[ORM\Column(name: 'lease_until', type: PreciseDateTimeType::NAME, nullable: true)]
    protected ?DateTimeImmutable $leaseUntil = null;

    #[ORM\Column(name: 'memory_batch_id', type: 'bigint', nullable: true)]
    protected int|string|null $memoryBatchId = null;

    #[ORM\Column(name: 'middleterm_input_id', type: 'bigint', nullable: true)]
    protected int|string|null $middletermInputId = null;

    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    protected mixed $payload = null;

    #[ORM\Column(name: 'relationship_evaluation_id', type: 'bigint', nullable: true)]
    protected int|string|null $relationshipEvaluationId = null;

    #[ORM\Column(name: 'relationship_interaction_id', type: 'bigint', nullable: true)]
    protected int|string|null $relationshipInteractionId = null;

    #[ORM\Column(name: 'request_id', type: 'text')]
    protected string $requestId;

    #[ORM\Column(name: 'session_id', type: 'text', nullable: true)]
    protected ?string $sessionId = null;

    #[ORM\Column(type: 'text')]
    protected string $state;

    #[ORM\Column(name: 'turn_id', type: 'text')]
    protected string $turnId;

    #[ORM\Column(name: 'updated_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'utterance_id', type: 'text', nullable: true)]
    protected ?string $utteranceId = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'actor_identity_id' => $this->actorIdentityId,
            'attempts' => $this->attempts,
            'available_at' => $this->availableAt,
            'cache_key' => $this->cacheKey,
            'created_at' => $this->createdAt,
            'deadline_at' => $this->deadlineAt,
            'diary_input_id' => $this->diaryInputId,
            'generation' => $this->generation,
            'id' => $this->id,
            'job_type' => $this->jobType,
            'last_error' => $this->lastError,
            'lease_token' => $this->leaseToken,
            'lease_until' => $this->leaseUntil,
            'memory_batch_id' => $this->memoryBatchId,
            'middleterm_input_id' => $this->middletermInputId,
            'payload' => $this->payload,
            'relationship_evaluation_id' => $this->relationshipEvaluationId,
            'relationship_interaction_id' => $this->relationshipInteractionId,
            'request_id' => $this->requestId,
            'session_id' => $this->sessionId,
            'state' => $this->state,
            'turn_id' => $this->turnId,
            'updated_at' => $this->updatedAt,
            'utterance_id' => $this->utteranceId,
        ];
    }
}
