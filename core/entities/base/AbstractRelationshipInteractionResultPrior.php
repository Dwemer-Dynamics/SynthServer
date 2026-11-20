<?php

// The mapped row of public.core_relationship_interaction_result_prior. Read-only mapping: reads go through RelationshipInteractionResultPriorDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_relationship_interaction_result_prior', schema: 'public')]
class AbstractRelationshipInteractionResultPrior
{
    #[ORM\Id]
    #[ORM\Column(name: 'actor_identity_id', type: 'bigint')]
    protected int|string $actorIdentityId;

    #[ORM\Column(name: 'prior_history_event_id', type: 'bigint')]
    protected int|string $priorHistoryEventId;

    #[ORM\Id]
    #[ORM\Column(name: 'result_history_event_id', type: 'bigint')]
    protected int|string $resultHistoryEventId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'actor_identity_id' => $this->actorIdentityId,
            'prior_history_event_id' => $this->priorHistoryEventId,
            'result_history_event_id' => $this->resultHistoryEventId,
        ];
    }
}
