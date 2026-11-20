<?php

// The mapped row of public.core_request_execution. Read-only mapping: reads go through RequestExecutionDao; writes stay in the
// statements that own them, so Doctrine never flushes it.
// Not mapped (DAO-only or unsupported types): response_body.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_request_execution', schema: 'public')]
class AbstractRequestExecution
{
    #[ORM\Column(name: 'claimed_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $claimedAt;

    #[ORM\Column(name: 'completed_at', type: PreciseDateTimeType::NAME, nullable: true)]
    protected ?DateTimeImmutable $completedAt = null;

    #[ORM\Column(name: 'error_code', type: 'text', nullable: true)]
    protected ?string $errorCode = null;

    #[ORM\Column(name: 'event_type', type: 'text')]
    protected string $eventType;

    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Id]
    #[ORM\Column(name: 'request_id', type: 'text')]
    protected string $requestId;

    #[ORM\Column(name: 'request_sha256', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $requestSha256;

    #[ORM\Column(name: 'response_content_type', type: 'text', nullable: true)]
    protected ?string $responseContentType = null;

    #[ORM\Id]
    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    #[ORM\Column(type: 'text')]
    protected string $state;

    #[ORM\Column(name: 'turn_id', type: 'text')]
    protected string $turnId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'claimed_at' => $this->claimedAt,
            'completed_at' => $this->completedAt,
            'error_code' => $this->errorCode,
            'event_type' => $this->eventType,
            'generation' => $this->generation,
            'request_id' => $this->requestId,
            'request_sha256' => $this->requestSha256,
            'response_content_type' => $this->responseContentType,
            'session_id' => $this->sessionId,
            'state' => $this->state,
            'turn_id' => $this->turnId,
        ];
    }
}
