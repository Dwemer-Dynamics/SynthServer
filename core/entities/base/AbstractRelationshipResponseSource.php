<?php

// The mapped row of public.core_relationship_response_source. Read-only mapping: reads go through RelationshipResponseSourceDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_relationship_response_source', schema: 'public')]
class AbstractRelationshipResponseSource
{
    #[ORM\Id]
    #[ORM\Column(name: 'response_history_event_id', type: 'bigint')]
    protected int|string $responseHistoryEventId;

    #[ORM\Id]
    #[ORM\Column(name: 'source_history_event_id', type: 'bigint')]
    protected int|string $sourceHistoryEventId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'response_history_event_id' => $this->responseHistoryEventId,
            'source_history_event_id' => $this->sourceHistoryEventId,
        ];
    }
}
