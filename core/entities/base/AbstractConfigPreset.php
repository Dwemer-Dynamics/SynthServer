<?php

// The mapped row of public.core_config_preset. Read-only mapping: reads go through ConfigPresetDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_config_preset', schema: 'public')]
class AbstractConfigPreset
{
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    protected DateTimeImmutable $createdAt;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    protected int $id;

    #[ORM\Column(type: 'string', length: 60)]
    protected string $name;

    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    protected mixed $snapshot = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    protected DateTimeImmutable $updatedAt;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'created_at' => $this->createdAt,
            'id' => $this->id,
            'name' => $this->name,
            'snapshot' => $this->snapshot,
            'updated_at' => $this->updatedAt,
        ];
    }
}
