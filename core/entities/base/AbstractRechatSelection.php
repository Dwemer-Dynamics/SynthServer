<?php

// The mapped row of public.core_rechat_selection. Read-only mapping: reads go through RechatSelectionDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_rechat_selection', schema: 'public')]
class AbstractRechatSelection
{
    #[ORM\Column(name: 'actor_identity_id', type: 'bigint', nullable: true)]
    protected int|string|null $actorIdentityId = null;

    #[ORM\Column(name: 'created_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    protected mixed $identity = null;

    #[ORM\Column(name: 'npc_id', type: 'integer', nullable: true)]
    protected ?int $npcId = null;

    #[ORM\Column(name: 'prepared_at', type: PreciseDateTimeType::NAME, nullable: true)]
    protected ?DateTimeImmutable $preparedAt = null;

    #[ORM\Column(name: 'profile_id', type: 'integer', nullable: true)]
    protected ?int $profileId = null;

    #[ORM\Id]
    #[ORM\Column(name: 'request_id', type: 'text')]
    protected string $requestId;

    #[ORM\Column(type: 'text')]
    protected string $role;

    #[ORM\Id]
    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    #[ORM\Column(name: 'terminal_reason', type: 'text', nullable: true)]
    protected ?string $terminalReason = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'actor_identity_id' => $this->actorIdentityId,
            'created_at' => $this->createdAt,
            'identity' => $this->identity,
            'npc_id' => $this->npcId,
            'prepared_at' => $this->preparedAt,
            'profile_id' => $this->profileId,
            'request_id' => $this->requestId,
            'role' => $this->role,
            'session_id' => $this->sessionId,
            'terminal_reason' => $this->terminalReason,
        ];
    }
}
