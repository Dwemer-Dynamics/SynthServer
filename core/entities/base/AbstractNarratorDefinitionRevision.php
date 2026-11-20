<?php

// The mapped row of public.core_narrator_definition_revision. Read-only mapping: reads go through NarratorDefinitionRevisionDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_narrator_definition_revision', schema: 'public')]
class AbstractNarratorDefinitionRevision
{
    #[ORM\Column(type: 'text')]
    protected string $background;

    #[ORM\Column(name: 'created_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'definition_id', type: 'text')]
    protected string $definitionId;

    #[ORM\Column(type: 'text')]
    protected string $name;

    #[ORM\Column(type: 'text')]
    protected string $personality;

    #[ORM\Column(name: 'prior_revision_id', type: 'bigint', nullable: true)]
    protected int|string|null $priorRevisionId = null;

    #[ORM\Column(name: 'profile_id', type: 'bigint', nullable: true)]
    protected int|string|null $profileId = null;

    #[ORM\Id]
    #[ORM\Column(name: 'revision_id', type: 'bigint')]
    protected int|string $revisionId;

    #[ORM\Column(type: 'text')]
    protected string $style;

    #[ORM\Column(type: 'text')]
    protected string $voice;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'background' => $this->background,
            'created_at' => $this->createdAt,
            'definition_id' => $this->definitionId,
            'name' => $this->name,
            'personality' => $this->personality,
            'prior_revision_id' => $this->priorRevisionId,
            'profile_id' => $this->profileId,
            'revision_id' => $this->revisionId,
            'style' => $this->style,
            'voice' => $this->voice,
        ];
    }
}
