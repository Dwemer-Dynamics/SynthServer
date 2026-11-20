<?php

// The mapped row of public.core_relationship_interaction_result. Read-only mapping: reads go through RelationshipInteractionResultDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_relationship_interaction_result', schema: 'public')]
class AbstractRelationshipInteractionResult
{
    #[ORM\Id]
    #[ORM\Column(name: 'history_event_id', type: 'bigint')]
    protected int|string $historyEventId;

    #[ORM\Column(name: 'interaction_id', type: 'bigint')]
    protected int|string $interactionId;

    #[ORM\Column(name: 'job_id', type: 'bigint')]
    protected int|string $jobId;

    #[ORM\Column(name: 'result_payload', type: 'json', options: ['jsonb' => true])]
    protected mixed $resultPayload = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'history_event_id' => $this->historyEventId,
            'interaction_id' => $this->interactionId,
            'job_id' => $this->jobId,
            'result_payload' => $this->resultPayload,
        ];
    }
}
