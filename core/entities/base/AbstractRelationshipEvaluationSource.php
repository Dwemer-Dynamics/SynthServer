<?php

// The mapped row of public.core_relationship_evaluation_source. Read-only mapping: reads go through RelationshipEvaluationSourceDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_relationship_evaluation_source', schema: 'public')]
class AbstractRelationshipEvaluationSource
{
    #[ORM\Id]
    #[ORM\Column(name: 'evaluation_id', type: 'bigint')]
    protected int|string $evaluationId;

    #[ORM\Id]
    #[ORM\Column(name: 'history_event_id', type: 'bigint')]
    protected int|string $historyEventId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'evaluation_id' => $this->evaluationId,
            'history_event_id' => $this->historyEventId,
        ];
    }
}
