<?php

// The mapped row of public.core_narrator_definition. Read-only mapping: reads go through NarratorDefinitionDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_narrator_definition', schema: 'public')]
class AbstractNarratorDefinition
{
    #[ORM\Column(name: 'created_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Id]
    #[ORM\Column(name: 'definition_id', type: 'text')]
    protected string $definitionId;

    #[ORM\Column(name: 'edit_generation', type: 'bigint')]
    protected int|string $editGeneration;

    #[ORM\Column(name: 'retired_at', type: PreciseDateTimeType::NAME, nullable: true)]
    protected ?DateTimeImmutable $retiredAt = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'created_at' => $this->createdAt,
            'definition_id' => $this->definitionId,
            'edit_generation' => $this->editGeneration,
            'retired_at' => $this->retiredAt,
        ];
    }
}
