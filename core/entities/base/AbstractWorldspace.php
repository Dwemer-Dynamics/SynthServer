<?php

// The mapped row of public.core_worldspace: a worldspace (the coordinate space of a map). Read-only mapping: WorldspaceDao writes it with parameterized statements,
// so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_worldspace', schema: 'public')]
class AbstractWorldspace
{
    #[ORM\Id]
    #[ORM\Column(name: 'worldspace_id', type: 'integer')]
    protected int $worldspaceId;

    #[ORM\Column(type: 'text')]
    protected string $plugin;

    #[ORM\Column(type: 'integer')]
    protected int $baseid;

    #[ORM\Column(name: 'editor_id', type: 'text', nullable: true)]
    protected ?string $editorId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $name = null;

    #[ORM\Column(name: 'parent_worldspace_id', type: 'integer', nullable: true)]
    protected ?int $parentWorldspaceId = null;

    #[ORM\Column(name: 'map_worldspace_id', type: 'integer', nullable: true)]
    protected ?int $mapWorldspaceId = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'worldspace_id' => $this->worldspaceId,
            'plugin' => $this->plugin,
            'baseid' => $this->baseid,
            'editor_id' => $this->editorId,
            'name' => $this->name,
            'parent_worldspace_id' => $this->parentWorldspaceId,
            'map_worldspace_id' => $this->mapWorldspaceId,
        ];
    }
}
