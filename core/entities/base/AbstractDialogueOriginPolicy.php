<?php

// The mapped row of public.core_dialogue_origin_policy. Read-only mapping: reads go through DialogueOriginPolicyDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_dialogue_origin_policy', schema: 'public')]
class AbstractDialogueOriginPolicy
{
    #[ORM\Column(name: 'created_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'execution_mode', type: 'text')]
    protected string $executionMode;

    #[ORM\Column(name: 'input_text', type: 'text', nullable: true)]
    protected ?string $inputText = null;

    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    protected mixed $policy = null;

    #[ORM\Id]
    #[ORM\Column(name: 'request_id', type: 'text')]
    protected string $requestId;

    #[ORM\Id]
    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'created_at' => $this->createdAt,
            'execution_mode' => $this->executionMode,
            'input_text' => $this->inputText,
            'policy' => $this->policy,
            'request_id' => $this->requestId,
            'session_id' => $this->sessionId,
        ];
    }
}
