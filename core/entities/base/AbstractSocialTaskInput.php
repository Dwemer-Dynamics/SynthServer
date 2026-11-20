<?php

// The mapped row of public.core_social_task_input. Read-only mapping: reads go through SocialTaskInputDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_social_task_input', schema: 'public')]
class AbstractSocialTaskInput
{
    #[ORM\Column(name: 'history_event_id', type: 'bigint')]
    protected int|string $historyEventId;

    #[ORM\Column(name: 'input_payload', type: 'json', options: ['jsonb' => true])]
    protected mixed $inputPayload = null;

    #[ORM\Column(name: 'input_sha256', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $inputSha256;

    #[ORM\Column(name: 'performer_identity_id', type: 'bigint')]
    protected int|string $performerIdentityId;

    #[ORM\Column(name: 'player_identity_id', type: 'bigint')]
    protected int|string $playerIdentityId;

    #[ORM\Column(name: 'recipient_identity_id', type: 'bigint')]
    protected int|string $recipientIdentityId;

    #[ORM\Id]
    #[ORM\Column(name: 'task_id', type: 'text')]
    protected string $taskId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'history_event_id' => $this->historyEventId,
            'input_payload' => $this->inputPayload,
            'input_sha256' => $this->inputSha256,
            'performer_identity_id' => $this->performerIdentityId,
            'player_identity_id' => $this->playerIdentityId,
            'recipient_identity_id' => $this->recipientIdentityId,
            'task_id' => $this->taskId,
        ];
    }
}
