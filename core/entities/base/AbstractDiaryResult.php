<?php

// The mapped row of public.core_diary_result. Read-only mapping: reads go through DiaryResultDao; writes stay in the
// statements that own them, so Doctrine never flushes it.
// Not mapped (DAO-only or unsupported types): embedding.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_diary_result', schema: 'public')]
class AbstractDiaryResult
{
    #[ORM\Column(name: 'embedding_sha256', type: 'string', nullable: true, length: 64, options: ['fixed' => true])]
    protected ?string $embeddingSha256 = null;

    #[ORM\Column(name: 'embedding_text_sha256', type: 'string', nullable: true, length: 64, options: ['fixed' => true])]
    protected ?string $embeddingTextSha256 = null;

    #[ORM\Id]
    #[ORM\Column(name: 'history_event_id', type: 'bigint')]
    protected int|string $historyEventId;

    #[ORM\Column(name: 'input_id', type: 'bigint')]
    protected int|string $inputId;

    #[ORM\Column(name: 'result_payload', type: 'json', options: ['jsonb' => true])]
    protected mixed $resultPayload = null;

    #[ORM\Column(name: 'result_sha256', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $resultSha256;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'embedding_sha256' => $this->embeddingSha256,
            'embedding_text_sha256' => $this->embeddingTextSha256,
            'history_event_id' => $this->historyEventId,
            'input_id' => $this->inputId,
            'result_payload' => $this->resultPayload,
            'result_sha256' => $this->resultSha256,
        ];
    }
}
