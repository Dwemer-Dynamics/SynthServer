<?php

// The mapped row of public.core_itt_connector: one ITT connector (an image-to-text service). Read-only mapping: reads go through IttConnectorDao;
// the edit API owns writes, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_itt_connector', schema: 'public')]
class AbstractIttConnector
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    protected int $id;

    #[ORM\Column(type: 'text')]
    protected string $driver;

    #[ORM\Column(type: 'text')]
    protected string $label;

    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    /** @var array<string, mixed> */
    protected array $metadata = [];

    #[ORM\Column(name: 'local_api_key_id', type: 'integer', nullable: true)]
    protected ?int $localApiKeyId = null;

    #[ORM\Column(name: 'api_key_id', type: 'guid', nullable: true)]
    protected ?string $apiKeyId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $url = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'driver' => $this->driver,
            'label' => $this->label,
            'metadata' => $this->metadata,
            'local_api_key_id' => $this->localApiKeyId,
            'api_key_id' => $this->apiKeyId,
            'url' => $this->url,
        ];
    }
}
