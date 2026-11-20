<?php

// The mapped row of public.core_relationship_response_input. Read-only mapping: reads go through RelationshipResponseInputDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_relationship_response_input', schema: 'public')]
class AbstractRelationshipResponseInput
{
    #[ORM\Column(name: 'actor_identity_id', type: 'bigint')]
    protected int|string $actorIdentityId;

    #[ORM\Id]
    #[ORM\Column(name: 'history_event_id', type: 'bigint')]
    protected int|string $historyEventId;

    #[ORM\Column(name: 'request_id', type: 'text')]
    protected string $requestId;

    #[ORM\Column(name: 'response_payload', type: 'json', options: ['jsonb' => true])]
    protected mixed $responsePayload = null;

    #[ORM\Column(name: 'response_sha256', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $responseSha256;

    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'actor_identity_id' => $this->actorIdentityId,
            'history_event_id' => $this->historyEventId,
            'request_id' => $this->requestId,
            'response_payload' => $this->responsePayload,
            'response_sha256' => $this->responseSha256,
            'session_id' => $this->sessionId,
        ];
    }
}
