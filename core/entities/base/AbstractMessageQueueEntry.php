<?php

// The mapped row of public.core_message_queue: one outbound result the client fetches by id. Read-only mapping: reads go
// through MessageQueueDao, and every state change is a guarded single statement there, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_message_queue', schema: 'public')]
class AbstractMessageQueueEntry
{
    #[ORM\Column(name: 'cancelled_at', type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(name: 'completed_at', type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeImmutable $completedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'dedup_key', type: 'text')]
    protected string $dedupKey;

    #[ORM\Column(name: 'delivered_at', type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    protected DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $id;

    #[ORM\Column(name: 'last_error', type: 'text', nullable: true)]
    protected ?string $lastError = null;

    #[ORM\Column(name: 'message_type', type: 'text')]
    protected string $messageType;

    #[ORM\Column(name: 'notified_at', type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeImmutable $notifiedAt = null;

    #[ORM\Column(name: 'notify_attempts', type: 'integer')]
    protected int $notifyAttempts = 0;

    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    protected mixed $payload = null;

    #[ORM\Column(name: 'request_id', type: 'text')]
    protected string $requestId;

    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    #[ORM\Column(type: 'text')]
    protected string $status = 'pending';
}
