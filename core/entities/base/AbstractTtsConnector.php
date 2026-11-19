<?php

// The mapped row of public.core_tts_connector: one TTS connector (a voice service). Read-only mapping: reads go through TtsConnectorDao;
// the edit API owns writes, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_tts_connector', schema: 'public')]
class AbstractTtsConnector
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    protected int $id;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $driver = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $label = null;

    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    /** @var array<string, mixed>|null */
    protected ?array $metadata = null;

    #[ORM\Column(name: 'local_api_key_id', type: 'integer', nullable: true)]
    protected ?int $localApiKeyId = null;

    #[ORM\Column(name: 'api_key_id', type: 'guid', nullable: true)]
    protected ?string $apiKeyId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $url = null;

    #[ORM\Column(name: 'voice_field', type: 'text', nullable: true)]
    protected ?string $voiceField = null;

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
            'voice_field' => $this->voiceField,
        ];
    }
}
