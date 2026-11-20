<?php

// The mapped row of public.core_rechat_continuation. Read-only mapping: reads go through RechatContinuationDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_rechat_continuation', schema: 'public')]
class AbstractRechatContinuation
{
    #[ORM\Column(name: 'chain_id', type: 'bigint')]
    protected int|string $chainId;

    #[ORM\Column(name: 'created_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'parent_line_id', type: 'text')]
    protected string $parentLineId;

    #[ORM\Column(name: 'parent_request_id', type: 'text')]
    protected string $parentRequestId;

    #[ORM\Column(name: 'parent_response_sha256', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $parentResponseSha256;

    #[ORM\Id]
    #[ORM\Column(name: 'request_id', type: 'text')]
    protected string $requestId;

    #[ORM\Column(name: 'round_number', type: 'bigint', nullable: true)]
    protected int|string|null $roundNumber = null;

    #[ORM\Id]
    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    #[ORM\Column(type: 'text')]
    protected string $status;

    #[ORM\Column(name: 'terminal_reason', type: 'text', nullable: true)]
    protected ?string $terminalReason = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'chain_id' => $this->chainId,
            'created_at' => $this->createdAt,
            'parent_line_id' => $this->parentLineId,
            'parent_request_id' => $this->parentRequestId,
            'parent_response_sha256' => $this->parentResponseSha256,
            'request_id' => $this->requestId,
            'round_number' => $this->roundNumber,
            'session_id' => $this->sessionId,
            'status' => $this->status,
            'terminal_reason' => $this->terminalReason,
        ];
    }
}
