<?php

// The mapped row of public.core_catalog_version. Read-only mapping: reads go through CatalogVersionDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_catalog_version', schema: 'public')]
class AbstractCatalogVersion
{
    #[ORM\Id]
    #[ORM\Column(name: 'catalog_key', type: 'text')]
    protected string $catalogKey;

    #[ORM\Column(type: 'bigint')]
    protected int|string $version;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'catalog_key' => $this->catalogKey,
            'version' => $this->version,
        ];
    }
}
