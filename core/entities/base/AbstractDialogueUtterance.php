<?php

// The mapped row of public.core_dialogue_utterance. Read-only mapping: reads go through DialogueUtteranceDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_dialogue_utterance', schema: 'public')]
class AbstractDialogueUtterance
{
    #[ORM\Column(name: 'character_id', type: 'text')]
    protected string $characterId;

    #[ORM\Column(name: 'context_sequence', type: 'bigint')]
    protected int|string $contextSequence;

    #[ORM\Column(name: 'created_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'bigint')]
    protected int|string $generation;

    #[ORM\Id]
    #[ORM\Column(name: 'line_id', type: 'text')]
    protected string $lineId;

    #[ORM\Column(name: 'receipt_at', type: PreciseDateTimeType::NAME, nullable: true)]
    protected ?DateTimeImmutable $receiptAt = null;

    #[ORM\Column(name: 'receipt_request_id', type: 'text', nullable: true)]
    protected ?string $receiptRequestId = null;

    #[ORM\Id]
    #[ORM\Column(name: 'request_id', type: 'text')]
    protected string $requestId;

    #[ORM\Id]
    #[ORM\Column(name: 'session_id', type: 'text')]
    protected string $sessionId;

    #[ORM\Column(name: 'speaker_identity_id', type: 'bigint')]
    protected int|string $speakerIdentityId;

    #[ORM\Column(type: 'text')]
    protected string $state;

    #[ORM\Column(name: 'text_sha256', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $textSha256;

    #[ORM\Column(type: 'text')]
    protected string $text;

    #[ORM\Column(name: 'turn_id', type: 'text')]
    protected string $turnId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'character_id' => $this->characterId,
            'context_sequence' => $this->contextSequence,
            'created_at' => $this->createdAt,
            'generation' => $this->generation,
            'line_id' => $this->lineId,
            'receipt_at' => $this->receiptAt,
            'receipt_request_id' => $this->receiptRequestId,
            'request_id' => $this->requestId,
            'session_id' => $this->sessionId,
            'speaker_identity_id' => $this->speakerIdentityId,
            'state' => $this->state,
            'text_sha256' => $this->textSha256,
            'text' => $this->text,
            'turn_id' => $this->turnId,
        ];
    }
}
