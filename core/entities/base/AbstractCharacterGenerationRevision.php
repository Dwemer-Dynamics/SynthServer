<?php

// The mapped row of public.core_character_generation_revision. Read-only mapping: reads go through CharacterGenerationRevisionDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_character_generation_revision', schema: 'public')]
class AbstractCharacterGenerationRevision
{
    #[ORM\Column(name: 'actor_identity_id', type: 'bigint')]
    protected int|string $actorIdentityId;

    #[ORM\Column(name: 'context_sequence', type: 'bigint')]
    protected int|string $contextSequence;

    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Id]
    #[ORM\Column(name: 'history_event_id', type: 'bigint')]
    protected int|string $historyEventId;

    #[ORM\Column(name: 'input_payload', type: 'json', options: ['jsonb' => true])]
    protected mixed $inputPayload = null;

    #[ORM\Column(name: 'input_sha256', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $inputSha256;

    #[ORM\Column(name: 'prior_history_event_id', type: 'bigint', nullable: true)]
    protected int|string|null $priorHistoryEventId = null;

    #[ORM\Column(name: 'profile_role', type: 'text')]
    protected string $profileRole;

    #[ORM\Column(name: 'request_id', type: 'text')]
    protected string $requestId;

    #[ORM\Column(name: 'result_fields', type: 'json', options: ['jsonb' => true])]
    protected mixed $resultFields = null;

    #[ORM\Column(name: 'result_sha256', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $resultSha256;

    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'actor_identity_id' => $this->actorIdentityId,
            'context_sequence' => $this->contextSequence,
            'generation' => $this->generation,
            'history_event_id' => $this->historyEventId,
            'input_payload' => $this->inputPayload,
            'input_sha256' => $this->inputSha256,
            'prior_history_event_id' => $this->priorHistoryEventId,
            'profile_role' => $this->profileRole,
            'request_id' => $this->requestId,
            'result_fields' => $this->resultFields,
            'result_sha256' => $this->resultSha256,
            'session_id' => $this->sessionId,
        ];
    }
}
