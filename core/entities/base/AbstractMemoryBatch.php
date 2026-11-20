<?php

// The mapped row of public.core_memory_batch. Read-only mapping: reads go through MemoryBatchDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_memory_batch', schema: 'public')]
class AbstractMemoryBatch
{
    #[ORM\Column(name: 'actor_identity_id', type: 'bigint')]
    protected int|string $actorIdentityId;

    #[ORM\Column(name: 'context_sequence', type: 'bigint')]
    protected int|string $contextSequence;

    #[ORM\Column(name: 'created_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'first_gamets', type: 'bigint')]
    protected int|string $firstGamets;

    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $id;

    #[ORM\Column(name: 'input_payload', type: 'json', options: ['jsonb' => true])]
    protected mixed $inputPayload = null;

    #[ORM\Column(name: 'last_gamets', type: 'bigint')]
    protected int|string $lastGamets;

    #[ORM\Column(name: 'packed_message', type: 'text')]
    protected string $packedMessage;

    #[ORM\Column(name: 'playthrough_id', type: 'text')]
    protected string $playthroughId;

    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    #[ORM\Column(name: 'source_hash', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $sourceHash;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'actor_identity_id' => $this->actorIdentityId,
            'context_sequence' => $this->contextSequence,
            'created_at' => $this->createdAt,
            'first_gamets' => $this->firstGamets,
            'generation' => $this->generation,
            'id' => $this->id,
            'input_payload' => $this->inputPayload,
            'last_gamets' => $this->lastGamets,
            'packed_message' => $this->packedMessage,
            'playthrough_id' => $this->playthroughId,
            'session_id' => $this->sessionId,
            'source_hash' => $this->sourceHash,
        ];
    }
}
