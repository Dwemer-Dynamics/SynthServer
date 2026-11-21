<?php

// The mapped row of public.core_location_legacy. Read-only mapping: reads go through LocationLegacyDao; writes stay in the
// statements that own them, so Doctrine never flushes it.
// Not mapped (DAO-only or unsupported types): coords.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_location_legacy', schema: 'public')]
class AbstractLocationLegacy
{
    #[ORM\Column(type: 'boolean', nullable: true)]
    protected ?bool $cleared = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    protected int|string|null $formid = null;

    #[ORM\Column(name: 'is_interior', type: 'integer', nullable: true)]
    protected ?int $isInterior = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $name = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $refs = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $tags = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeImmutable $updatedAt = null;

    #[ORM\Column(name: 'vanilla_location', type: 'boolean', nullable: true)]
    protected ?bool $vanillaLocation = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $worldspace = null;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $rowid;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'cleared' => $this->cleared,
            'formid' => $this->formid,
            'is_interior' => $this->isInterior,
            'name' => $this->name,
            'refs' => $this->refs,
            'tags' => $this->tags,
            'updated_at' => $this->updatedAt,
            'vanilla_location' => $this->vanillaLocation,
            'worldspace' => $this->worldspace,
            'rowid' => $this->rowid,
        ];
    }
}
