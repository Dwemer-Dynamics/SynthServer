<?php

// The mapped row of public.core_character_generation_field. Read-only mapping: reads go through CharacterGenerationFieldDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_character_generation_field', schema: 'public')]
class AbstractCharacterGenerationField
{
    #[ORM\Id]
    #[ORM\Column(type: 'text')]
    protected string $field;

    #[ORM\Id]
    #[ORM\Column(name: 'job_id', type: 'bigint')]
    protected int|string $jobId;

    #[ORM\Column(name: 'result_sha256', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $resultSha256;

    #[ORM\Column(name: 'result_text', type: 'text')]
    protected string $resultText;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'job_id' => $this->jobId,
            'result_sha256' => $this->resultSha256,
            'result_text' => $this->resultText,
        ];
    }
}
