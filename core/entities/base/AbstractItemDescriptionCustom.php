<?php

// The mapped row of public.core_item_description_custom. Read-only mapping: reads go through ItemDescriptionCustomDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_item_description_custom', schema: 'public')]
class AbstractItemDescriptionCustom
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 128)]
    protected string $baseid;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $description = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $name = null;

    #[ORM\Id]
    #[ORM\Column(type: 'text')]
    protected string $plugin;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'baseid' => $this->baseid,
            'description' => $this->description,
            'name' => $this->name,
            'plugin' => $this->plugin,
        ];
    }
}
