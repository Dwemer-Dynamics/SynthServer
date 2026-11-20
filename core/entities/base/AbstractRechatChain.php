<?php

// The mapped row of public.core_rechat_chain. Read-only mapping: reads go through RechatChainDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_rechat_chain', schema: 'public')]
class AbstractRechatChain
{
    #[ORM\Column(type: 'bigint')]
    protected int|string $budget;

    #[ORM\Column(name: 'context_sequence', type: 'bigint')]
    protected int|string $contextSequence;

    #[ORM\Column(name: 'created_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $id;

    #[ORM\Column(type: 'text')]
    protected string $mode;

    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    protected mixed $policy = null;

    #[ORM\Column(name: 'root_request_id', type: 'text')]
    protected string $rootRequestId;

    #[ORM\Column(name: 'runtime_variant', type: 'text')]
    protected string $runtimeVariant;

    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    #[ORM\Column(name: 'terminal_reason', type: 'text', nullable: true)]
    protected ?string $terminalReason = null;

    #[ORM\Column(name: 'turn_id', type: 'text')]
    protected string $turnId;

    #[ORM\Column(name: 'used_rounds', type: 'bigint')]
    protected int|string $usedRounds;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'budget' => $this->budget,
            'context_sequence' => $this->contextSequence,
            'created_at' => $this->createdAt,
            'generation' => $this->generation,
            'id' => $this->id,
            'mode' => $this->mode,
            'policy' => $this->policy,
            'root_request_id' => $this->rootRequestId,
            'runtime_variant' => $this->runtimeVariant,
            'session_id' => $this->sessionId,
            'terminal_reason' => $this->terminalReason,
            'turn_id' => $this->turnId,
            'used_rounds' => $this->usedRounds,
        ];
    }
}
