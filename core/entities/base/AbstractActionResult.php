<?php

// The mapped row of public.core_action_result. Read-only mapping: reads go through ActionResultDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_action_result', schema: 'public')]
class AbstractActionResult
{
    #[ORM\Id]
    #[ORM\Column(name: 'action_id', type: 'text')]
    protected string $actionId;

    #[ORM\Column(name: 'completed_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $completedAt;

    #[ORM\Column(name: 'idempotency_key', type: 'text')]
    protected string $idempotencyKey;

    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    protected mixed $result = null;

    #[ORM\Column(type: 'text')]
    protected string $status;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action_id' => $this->actionId,
            'completed_at' => $this->completedAt,
            'idempotency_key' => $this->idempotencyKey,
            'result' => $this->result,
            'status' => $this->status,
        ];
    }
}
