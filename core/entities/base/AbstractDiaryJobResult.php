<?php

// The mapped row of public.core_diary_job_result. Read-only mapping: reads go through DiaryJobResultDao; writes stay in the
// statements that own them, so Doctrine never flushes it.
// Not mapped (DAO-only or unsupported types): embedding.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_diary_job_result', schema: 'public')]
class AbstractDiaryJobResult
{
    #[ORM\Column(name: 'embedding_attempted', type: 'boolean')]
    protected bool $embeddingAttempted;

    #[ORM\Column(name: 'embedding_sha256', type: 'string', nullable: true, length: 64, options: ['fixed' => true])]
    protected ?string $embeddingSha256 = null;

    #[ORM\Column(name: 'embedding_text_sha256', type: 'string', nullable: true, length: 64, options: ['fixed' => true])]
    protected ?string $embeddingTextSha256 = null;

    #[ORM\Column(name: 'input_sha256', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $inputSha256;

    #[ORM\Id]
    #[ORM\Column(name: 'job_id', type: 'bigint')]
    protected int|string $jobId;

    #[ORM\Column(name: 'result_payload', type: 'json', options: ['jsonb' => true])]
    protected mixed $resultPayload = null;

    #[ORM\Column(name: 'result_sha256', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $resultSha256;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'embedding_attempted' => $this->embeddingAttempted,
            'embedding_sha256' => $this->embeddingSha256,
            'embedding_text_sha256' => $this->embeddingTextSha256,
            'input_sha256' => $this->inputSha256,
            'job_id' => $this->jobId,
            'result_payload' => $this->resultPayload,
            'result_sha256' => $this->resultSha256,
        ];
    }
}
