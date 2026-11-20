<?php

// The mapped row of public.core_location_marker: a reference the game associates with a location. Read-only mapping: LocationMarkerDao writes it with parameterized statements,
// so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_location_marker', schema: 'public')]
class AbstractLocationMarker
{
    #[ORM\Id]
    #[ORM\Column(name: 'marker_id', type: 'bigint')]
    protected int $markerId;

    #[ORM\Column(name: 'location_id', type: 'bigint')]
    protected int $locationId;

    #[ORM\Column(name: 'marker_type', type: 'text')]
    protected string $markerType;

    #[ORM\Column(type: 'text')]
    protected string $plugin;

    #[ORM\Column(type: 'integer')]
    protected int $baseid;

    #[ORM\Column(name: 'cell_plugin', type: 'text', nullable: true)]
    protected ?string $cellPlugin = null;

    #[ORM\Column(name: 'cell_baseid', type: 'integer', nullable: true)]
    protected ?int $cellBaseid = null;

    #[ORM\Column(type: 'boolean')]
    protected bool $interior;

    #[ORM\Column(name: 'worldspace_id', type: 'integer', nullable: true)]
    protected ?int $worldspaceId = null;

    #[ORM\Column(type: 'float', nullable: true)]
    protected ?float $x = null;

    #[ORM\Column(type: 'float', nullable: true)]
    protected ?float $y = null;

    #[ORM\Column(type: 'float', nullable: true)]
    protected ?float $z = null;

    #[ORM\Column(type: 'boolean')]
    protected bool $disabled;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'marker_id' => $this->markerId,
            'location_id' => $this->locationId,
            'marker_type' => $this->markerType,
            'plugin' => $this->plugin,
            'baseid' => $this->baseid,
            'cell_plugin' => $this->cellPlugin,
            'cell_baseid' => $this->cellBaseid,
            'interior' => $this->interior,
            'worldspace_id' => $this->worldspaceId,
            'x' => $this->x,
            'y' => $this->y,
            'z' => $this->z,
            'disabled' => $this->disabled,
        ];
    }
}
