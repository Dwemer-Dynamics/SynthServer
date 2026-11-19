<?php

// The mapped row of public.core_location: a game location of the catalog. Read-only mapping: LocationDao writes it with parameterized statements,
// so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_location', schema: 'public')]
class AbstractLocation
{
    #[ORM\Id]
    #[ORM\Column(name: 'location_id', type: 'bigint')]
    protected int $locationId;

    #[ORM\Column(type: 'text')]
    protected string $kind;

    #[ORM\Column(type: 'text')]
    protected string $plugin;

    #[ORM\Column(type: 'integer')]
    protected int $baseid;

    #[ORM\Column(type: 'text')]
    protected string $name;

    #[ORM\Column(name: 'editor_id', type: 'text', nullable: true)]
    protected ?string $editorId = null;

    #[ORM\Column(name: 'parent_plugin', type: 'text', nullable: true)]
    protected ?string $parentPlugin = null;

    #[ORM\Column(name: 'parent_baseid', type: 'integer', nullable: true)]
    protected ?int $parentBaseid = null;

    #[ORM\Column(name: 'parent_location_id', type: 'bigint', nullable: true)]
    protected ?int $parentLocationId = null;

    #[ORM\Column(name: 'region_location_id', type: 'bigint', nullable: true)]
    protected ?int $regionLocationId = null;

    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    /** @var list<mixed> */
    protected array $keywords = [];

    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    /** @var list<mixed> */
    protected array $tags = [];

    #[ORM\Column(name: 'map_worldspace_id', type: 'integer', nullable: true)]
    protected ?int $mapWorldspaceId = null;

    #[ORM\Column(name: 'map_x', type: 'float', nullable: true)]
    protected ?float $mapX = null;

    #[ORM\Column(name: 'map_y', type: 'float', nullable: true)]
    protected ?float $mapY = null;

    #[ORM\Column(name: 'position_source', type: 'text')]
    protected string $positionSource;

    #[ORM\Column(name: 'last_catalog_sha256', type: 'string', nullable: true, length: 64, options: ['fixed' => true])]
    protected ?string $lastCatalogSha256 = null;

    #[ORM\Column(name: 'created_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $updatedAt;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'location_id' => $this->locationId,
            'kind' => $this->kind,
            'plugin' => $this->plugin,
            'baseid' => $this->baseid,
            'name' => $this->name,
            'editor_id' => $this->editorId,
            'parent_plugin' => $this->parentPlugin,
            'parent_baseid' => $this->parentBaseid,
            'parent_location_id' => $this->parentLocationId,
            'region_location_id' => $this->regionLocationId,
            'keywords' => $this->keywords,
            'tags' => $this->tags,
            'map_worldspace_id' => $this->mapWorldspaceId,
            'map_x' => $this->mapX,
            'map_y' => $this->mapY,
            'position_source' => $this->positionSource,
            'last_catalog_sha256' => $this->lastCatalogSha256,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
