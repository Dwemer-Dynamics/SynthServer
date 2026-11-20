<?php

// The mapped row of public.core_relationship_init_queue. Read-only mapping: reads go through RelationshipInitQueueDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_relationship_init_queue', schema: 'public')]
class AbstractRelationshipInitQueue
{
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeImmutable $createdAt = null;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    protected int $id;

    #[ORM\Column(name: 'init_data', type: 'json', options: ['jsonb' => true])]
    protected mixed $initData = null;

    #[ORM\Column(name: 'last_error', type: 'text', nullable: true)]
    protected ?string $lastError = null;

    #[ORM\Column(name: 'npc_id', type: 'integer')]
    protected int $npcId;

    #[ORM\Column(name: 'retry_count', type: 'integer', nullable: true)]
    protected ?int $retryCount = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'created_at' => $this->createdAt,
            'id' => $this->id,
            'init_data' => $this->initData,
            'last_error' => $this->lastError,
            'npc_id' => $this->npcId,
            'retry_count' => $this->retryCount,
        ];
    }
}
