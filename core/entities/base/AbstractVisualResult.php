<?php

// The mapped row of public.core_visual_result. Read-only mapping: reads go through VisualResultDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_visual_result', schema: 'public')]
class AbstractVisualResult
{
    #[ORM\Column(name: 'actor_identity_id', type: 'bigint')]
    protected int|string $actorIdentityId;

    #[ORM\Column(name: 'capture_id', type: 'text')]
    protected string $captureId;

    #[ORM\Column(name: 'context_sequence', type: 'bigint')]
    protected int|string $contextSequence;

    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Id]
    #[ORM\Column(name: 'history_event_id', type: 'bigint')]
    protected int|string $historyEventId;

    #[ORM\Column(name: 'request_id', type: 'text')]
    protected string $requestId;

    #[ORM\Column(name: 'result_payload', type: 'json', options: ['jsonb' => true])]
    protected mixed $resultPayload = null;

    #[ORM\Column(name: 'result_sha256', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $resultSha256;

    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'actor_identity_id' => $this->actorIdentityId,
            'capture_id' => $this->captureId,
            'context_sequence' => $this->contextSequence,
            'generation' => $this->generation,
            'history_event_id' => $this->historyEventId,
            'request_id' => $this->requestId,
            'result_payload' => $this->resultPayload,
            'result_sha256' => $this->resultSha256,
            'session_id' => $this->sessionId,
        ];
    }
}
