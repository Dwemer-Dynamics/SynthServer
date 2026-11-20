<?php

// The mapped row of public.core_social_task_event. Read-only mapping: reads go through SocialTaskEventDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_social_task_event', schema: 'public')]
class AbstractSocialTaskEvent
{
    #[ORM\Column(name: 'event_payload', type: 'json', options: ['jsonb' => true])]
    protected mixed $eventPayload = null;

    #[ORM\Column(name: 'event_sha256', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $eventSha256;

    #[ORM\Column(name: 'execution_id', type: 'text', nullable: true)]
    protected ?string $executionId = null;

    #[ORM\Id]
    #[ORM\Column(name: 'history_event_id', type: 'bigint')]
    protected int|string $historyEventId;

    #[ORM\Column(name: 'prior_revision', type: 'bigint', nullable: true)]
    protected int|string|null $priorRevision = null;

    #[ORM\Column(type: 'text')]
    protected string $status;

    #[ORM\Column(name: 'task_id', type: 'text')]
    protected string $taskId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event_payload' => $this->eventPayload,
            'event_sha256' => $this->eventSha256,
            'execution_id' => $this->executionId,
            'history_event_id' => $this->historyEventId,
            'prior_revision' => $this->priorRevision,
            'status' => $this->status,
            'task_id' => $this->taskId,
        ];
    }
}
