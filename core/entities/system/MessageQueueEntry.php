<?php

// A queued message as managers see it: its id, type, owner (session, request, generation), status and, once completed, its
// payload. The mapped class stays inside the DAO.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use DateTimeImmutable;
use Synth\Core\Entities\Base\AbstractMessageQueueEntry;


/** One row of core_message_queue, copied out of the loaded entity so changing it never touches the database. */
final class MessageQueueEntry extends AbstractMessageQueueEntry
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractMessageQueueEntry $mapped): self
    {
        return self::copyOf($mapped);
    }

    public function id(): int
    {
        return intval($this->id);
    }

    public function messageType(): string
    {
        return $this->messageType;
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function generation(): int
    {
        return intval($this->generation);
    }

    /** 'pending', 'completed', 'delivered', 'cancelled' or 'expired'. */
    public function status(): string
    {
        return $this->status;
    }

    /** The final payload object, or null while pending. */
    public function payload(): ?array
    {
        return is_array($this->payload) ? $this->payload : null;
    }

    public function completedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function deliveredAt(): ?DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function notifyAttempts(): int
    {
        return $this->notifyAttempts;
    }

    public function notifiedAt(): ?DateTimeImmutable
    {
        return $this->notifiedAt;
    }
}
