<?php

// The mapped row of public.core_media_object. Read-only mapping: reads go through MediaObjectDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_media_object', schema: 'public')]
class AbstractMediaObject
{
    #[ORM\Column(name: 'byte_length', type: 'bigint')]
    protected int|string $byteLength;

    #[ORM\Column(name: 'cache_key', type: 'text', nullable: true)]
    protected ?string $cacheKey = null;

    #[ORM\Column(name: 'content_type', type: 'text')]
    protected string $contentType;

    #[ORM\Column(name: 'created_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'expires_at', type: PreciseDateTimeType::NAME, nullable: true)]
    protected ?DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'failure_code', type: 'text', nullable: true)]
    protected ?string $failureCode = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    protected int|string|null $generation = null;

    #[ORM\Id]
    #[ORM\Column(name: 'media_id', type: 'text')]
    protected string $mediaId;

    #[ORM\Column(name: 'published_at', type: PreciseDateTimeType::NAME, nullable: true)]
    protected ?DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'request_id', type: 'text', nullable: true)]
    protected ?string $requestId = null;

    #[ORM\Column(type: 'string', length: 64, options: ['fixed' => true])]
    protected string $sha256;

    #[ORM\Column(name: 'speaker_identity_id', type: 'bigint', nullable: true)]
    protected int|string|null $speakerIdentityId = null;

    #[ORM\Column(type: 'text')]
    protected string $state;

    #[ORM\Column(name: 'storage_key', type: 'text')]
    protected string $storageKey;

    #[ORM\Column(name: 'turn_id', type: 'text', nullable: true)]
    protected ?string $turnId = null;

    #[ORM\Column(name: 'utterance_id', type: 'text', nullable: true)]
    protected ?string $utteranceId = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'byte_length' => $this->byteLength,
            'cache_key' => $this->cacheKey,
            'content_type' => $this->contentType,
            'created_at' => $this->createdAt,
            'expires_at' => $this->expiresAt,
            'failure_code' => $this->failureCode,
            'generation' => $this->generation,
            'media_id' => $this->mediaId,
            'published_at' => $this->publishedAt,
            'request_id' => $this->requestId,
            'sha256' => $this->sha256,
            'speaker_identity_id' => $this->speakerIdentityId,
            'state' => $this->state,
            'storage_key' => $this->storageKey,
            'turn_id' => $this->turnId,
            'utterance_id' => $this->utteranceId,
        ];
    }
}
