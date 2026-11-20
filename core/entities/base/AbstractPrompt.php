<?php

// The mapped row of public.core_prompt. Read-only mapping: reads go through PromptDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_prompt', schema: 'public')]
class AbstractPrompt
{
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'custom_prompt', type: 'text', nullable: true)]
    protected ?string $customPrompt = null;

    #[ORM\Column(name: 'default_prompt', type: 'text')]
    protected string $defaultPrompt;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $description = null;

    #[ORM\Id]
    #[ORM\Column(name: 'prompt_key', type: 'string', length: 128)]
    protected string $promptKey;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeImmutable $updatedAt = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'created_at' => $this->createdAt,
            'custom_prompt' => $this->customPrompt,
            'default_prompt' => $this->defaultPrompt,
            'description' => $this->description,
            'prompt_key' => $this->promptKey,
            'updated_at' => $this->updatedAt,
        ];
    }
}
