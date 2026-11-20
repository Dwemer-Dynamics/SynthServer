<?php

// The mapped row of public.core_visual_description_revision. Read-only mapping: reads go through VisualDescriptionRevisionDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_visual_description_revision', schema: 'public')]
class AbstractVisualDescriptionRevision
{
    #[ORM\Column(name: 'actor_identity_id', type: 'bigint')]
    protected int|string $actorIdentityId;

    #[ORM\Column(name: 'capture_id', type: 'text')]
    protected string $captureId;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $description = null;

    #[ORM\Id]
    #[ORM\Column(name: 'history_event_id', type: 'bigint')]
    protected int|string $historyEventId;

    #[ORM\Column(name: 'input_hash', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $inputHash;

    #[ORM\Column(name: 'input_payload', type: 'json', options: ['jsonb' => true])]
    protected mixed $inputPayload = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'actor_identity_id' => $this->actorIdentityId,
            'capture_id' => $this->captureId,
            'description' => $this->description,
            'history_event_id' => $this->historyEventId,
            'input_hash' => $this->inputHash,
            'input_payload' => $this->inputPayload,
        ];
    }
}
