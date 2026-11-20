<?php

// The mapped row of public.core_relationship_revision. Read-only mapping: reads go through RelationshipRevisionDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_relationship_revision', schema: 'public')]
class AbstractRelationshipRevision
{
    #[ORM\Column(name: 'actor_identity_id', type: 'bigint')]
    protected int|string $actorIdentityId;

    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $affinity = null;

    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    protected mixed $details = null;

    #[ORM\Id]
    #[ORM\Column(name: 'history_event_id', type: 'bigint')]
    protected int|string $historyEventId;

    #[ORM\Column(name: 'input_hash', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $inputHash;

    #[ORM\Column(name: 'input_payload', type: 'json', options: ['jsonb' => true])]
    protected mixed $inputPayload = null;

    #[ORM\Column(name: 'interaction_result_history_event_id', type: 'bigint', nullable: true)]
    protected int|string|null $interactionResultHistoryEventId = null;

    #[ORM\Column(name: 'prior_history_event_id', type: 'bigint', nullable: true)]
    protected int|string|null $priorHistoryEventId = null;

    #[ORM\Column(name: 'relationship_type', type: 'text', nullable: true)]
    protected ?string $relationshipType = null;

    #[ORM\Column(name: 'result_history_event_id', type: 'bigint', nullable: true)]
    protected int|string|null $resultHistoryEventId = null;

    #[ORM\Column(name: 'subject_identity_id', type: 'bigint')]
    protected int|string $subjectIdentityId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'actor_identity_id' => $this->actorIdentityId,
            'affinity' => $this->affinity,
            'details' => $this->details,
            'history_event_id' => $this->historyEventId,
            'input_hash' => $this->inputHash,
            'input_payload' => $this->inputPayload,
            'interaction_result_history_event_id' => $this->interactionResultHistoryEventId,
            'prior_history_event_id' => $this->priorHistoryEventId,
            'relationship_type' => $this->relationshipType,
            'result_history_event_id' => $this->resultHistoryEventId,
            'subject_identity_id' => $this->subjectIdentityId,
        ];
    }
}
