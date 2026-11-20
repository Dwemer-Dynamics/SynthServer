<?php

// The mapped row of public.core_protocol_event. Read-only mapping: reads go through ProtocolEventDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_protocol_event', schema: 'public')]
class AbstractProtocolEvent
{
    #[ORM\Column(name: 'canonical_event', type: 'json', options: ['jsonb' => true])]
    protected mixed $canonicalEvent = null;

    #[ORM\Column(name: 'event_sha256', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $eventSha256;

    #[ORM\Column(name: 'event_type', type: 'text')]
    protected string $eventType;

    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $id;

    #[ORM\Column(name: 'received_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $receivedAt;

    #[ORM\Column(name: 'request_id', type: 'text')]
    protected string $requestId;

    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    #[ORM\Column(name: 'turn_id', type: 'text')]
    protected string $turnId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'canonical_event' => $this->canonicalEvent,
            'event_sha256' => $this->eventSha256,
            'event_type' => $this->eventType,
            'generation' => $this->generation,
            'id' => $this->id,
            'received_at' => $this->receivedAt,
            'request_id' => $this->requestId,
            'session_id' => $this->sessionId,
            'turn_id' => $this->turnId,
        ];
    }
}
