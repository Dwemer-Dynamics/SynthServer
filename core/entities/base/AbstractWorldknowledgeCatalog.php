<?php

// The mapped row of public.core_worldknowledge_catalog. Read-only mapping: reads go through WorldknowledgeCatalogDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_worldknowledge_catalog', schema: 'public')]
class AbstractWorldknowledgeCatalog
{
    #[ORM\Column(name: 'activated_at', type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeImmutable $activatedAt = null;

    #[ORM\Id]
    #[ORM\Column(name: 'catalog_id', type: 'text')]
    protected string $catalogId;

    #[ORM\Id]
    #[ORM\Column(name: 'catalog_version', type: 'text')]
    protected string $catalogVersion;

    #[ORM\Column(name: 'checksum_sha256', type: 'string', length: 64)]
    protected string $checksumSha256;

    #[ORM\Column(name: 'display_name', type: 'text')]
    protected string $displayName;

    #[ORM\Column(name: 'installed_at', type: 'datetime_immutable')]
    protected DateTimeImmutable $installedAt;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    protected bool $isActive;

    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    protected mixed $manifest = null;

    #[ORM\Column(name: 'row_count', type: 'integer')]
    protected int $rowCount;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'activated_at' => $this->activatedAt,
            'catalog_id' => $this->catalogId,
            'catalog_version' => $this->catalogVersion,
            'checksum_sha256' => $this->checksumSha256,
            'display_name' => $this->displayName,
            'installed_at' => $this->installedAt,
            'is_active' => $this->isActive,
            'manifest' => $this->manifest,
            'row_count' => $this->rowCount,
        ];
    }
}
