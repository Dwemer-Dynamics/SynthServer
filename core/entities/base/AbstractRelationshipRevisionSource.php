<?php

// The mapped row of public.core_relationship_revision_source. Read-only mapping: reads go through RelationshipRevisionSourceDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_relationship_revision_source', schema: 'public')]
class AbstractRelationshipRevisionSource
{
    #[ORM\Id]
    #[ORM\Column(name: 'relationship_history_event_id', type: 'bigint')]
    protected int|string $relationshipHistoryEventId;

    #[ORM\Id]
    #[ORM\Column(name: 'source_history_event_id', type: 'bigint')]
    protected int|string $sourceHistoryEventId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'relationship_history_event_id' => $this->relationshipHistoryEventId,
            'source_history_event_id' => $this->sourceHistoryEventId,
        ];
    }
}
