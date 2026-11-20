<?php

// The mapped row of public.core_character_generation. Read-only mapping: reads go through CharacterGenerationDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_character_generation', schema: 'public')]
class AbstractCharacterGeneration
{
    #[ORM\Column(name: 'input_payload', type: 'json', options: ['jsonb' => true])]
    protected mixed $inputPayload = null;

    #[ORM\Column(name: 'input_sha256', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $inputSha256;

    #[ORM\Id]
    #[ORM\Column(name: 'job_id', type: 'bigint')]
    protected int|string $jobId;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'input_payload' => $this->inputPayload,
            'input_sha256' => $this->inputSha256,
            'job_id' => $this->jobId,
        ];
    }
}
