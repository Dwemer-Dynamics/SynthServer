<?php

// The mapped row of public.core_social_task_claim. Read-only mapping: reads go through SocialTaskClaimDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_social_task_claim', schema: 'public')]
class AbstractSocialTaskClaim
{
    #[ORM\Column(name: 'deadline_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $deadlineAt;

    #[ORM\Column(name: 'execution_id', type: 'text')]
    protected string $executionId;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Id]
    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    #[ORM\Id]
    #[ORM\Column(name: 'task_id', type: 'text')]
    protected string $taskId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'deadline_at' => $this->deadlineAt,
            'execution_id' => $this->executionId,
            'generation' => $this->generation,
            'session_id' => $this->sessionId,
            'task_id' => $this->taskId,
        ];
    }
}
