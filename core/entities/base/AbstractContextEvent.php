<?php

// The mapped row of public.core_context_event. Read-only mapping: reads go through ContextEventDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_context_event', schema: 'public')]
class AbstractContextEvent
{
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $data = null;

    #[ORM\Column(name: 'delivery_state', type: 'text', nullable: true)]
    protected ?string $deliveryState = null;

    #[ORM\Column(type: 'bigint')]
    protected int|string $gamets;

    #[ORM\Column(type: 'bigint')]
    protected int|string $localts;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $location = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $party = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $people = null;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $rowid;

    #[ORM\Column(type: 'string', nullable: true, length: 1024)]
    protected ?string $sess = null;

    #[ORM\Column(name: 'synth_context_sequence', type: 'bigint', nullable: true)]
    protected int|string|null $synthContextSequence = null;

    #[ORM\Column(name: 'synth_generation', type: 'bigint', nullable: true)]
    protected int|string|null $synthGeneration = null;

    #[ORM\Column(name: 'synth_request_id', type: 'text', nullable: true)]
    protected ?string $synthRequestId = null;

    #[ORM\Column(name: 'synth_session_id', type: 'text', nullable: true)]
    protected ?string $synthSessionId = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    protected int|string|null $ts = null;

    #[ORM\Column(type: 'string', nullable: true, length: 128)]
    protected ?string $type = null;

    #[ORM\Column(name: 'utterance_id', type: 'text', nullable: true)]
    protected ?string $utteranceId = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'delivery_state' => $this->deliveryState,
            'gamets' => $this->gamets,
            'localts' => $this->localts,
            'location' => $this->location,
            'party' => $this->party,
            'people' => $this->people,
            'rowid' => $this->rowid,
            'sess' => $this->sess,
            'synth_context_sequence' => $this->synthContextSequence,
            'synth_generation' => $this->synthGeneration,
            'synth_request_id' => $this->synthRequestId,
            'synth_session_id' => $this->synthSessionId,
            'ts' => $this->ts,
            'type' => $this->type,
            'utterance_id' => $this->utteranceId,
        ];
    }
}
