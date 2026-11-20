<?php

// The mapped row of public.core_middleterm_control_revision. Read-only mapping: reads go through MiddletermControlRevisionDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_middleterm_control_revision', schema: 'public')]
class AbstractMiddletermControlRevision
{
    #[ORM\Column(name: 'control_payload', type: 'json', options: ['jsonb' => true])]
    protected mixed $controlPayload = null;

    #[ORM\Column(name: 'control_sha256', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $controlSha256;

    #[ORM\Id]
    #[ORM\Column(name: 'history_event_id', type: 'bigint')]
    protected int|string $historyEventId;

    #[ORM\Column(name: 'narrative_id', type: 'bigint')]
    protected int|string $narrativeId;

    #[ORM\Column(name: 'prior_revision_id', type: 'bigint', nullable: true)]
    protected int|string|null $priorRevisionId = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'control_payload' => $this->controlPayload,
            'control_sha256' => $this->controlSha256,
            'history_event_id' => $this->historyEventId,
            'narrative_id' => $this->narrativeId,
            'prior_revision_id' => $this->priorRevisionId,
        ];
    }
}
