<?php

// The mapped row of public.core_visual_context. Read-only mapping: reads go through VisualContextDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_visual_context', schema: 'public')]
class AbstractVisualContext
{
    #[ORM\Column(type: 'boolean')]
    protected bool $active;

    #[ORM\Column(name: 'actor_identity_id', type: 'bigint', nullable: true)]
    protected int|string|null $actorIdentityId = null;

    #[ORM\Column(type: 'text')]
    protected string $baseid;

    #[ORM\Column(name: 'capture_id', type: 'text')]
    protected string $captureId;

    #[ORM\Column(name: 'captured_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $capturedAt;

    #[ORM\Column(name: 'cell_id', type: 'text')]
    protected string $cellId;

    #[ORM\Column(type: 'text')]
    protected string $description;

    #[ORM\Column(type: 'bigint', nullable: true)]
    protected int|string|null $generation = null;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $id;

    #[ORM\Column(name: 'image_path', type: 'text')]
    protected string $imagePath;

    #[ORM\Column(name: 'image_sha256', type: 'text')]
    protected string $imageSha256;

    #[ORM\Column(name: 'interaction_mode', type: 'text')]
    protected string $interactionMode;

    #[ORM\Column(name: 'location_name', type: 'text')]
    protected string $locationName;

    #[ORM\Column(type: 'boolean')]
    protected bool $locked;

    #[ORM\Column(name: 'media_id', type: 'text', nullable: true)]
    protected ?string $mediaId = null;

    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    protected mixed $metadata = null;

    #[ORM\Column(type: 'text')]
    protected string $model;

    #[ORM\Column(type: 'text')]
    protected string $perspective;

    #[ORM\Column(type: 'text')]
    protected string $plugin;

    #[ORM\Column(type: 'text')]
    protected string $provider;

    #[ORM\Column(type: 'text')]
    protected string $refid;

    #[ORM\Column(name: 'request_id', type: 'text')]
    protected string $requestId;

    #[ORM\Column(name: 'request_sha256', type: 'string', nullable: true, length: 64, options: ['fixed' => true])]
    protected ?string $requestSha256 = null;

    #[ORM\Column(name: 'response_payload', type: 'json', options: ['jsonb' => true])]
    protected mixed $responsePayload = null;

    #[ORM\Column(name: 'runtime_variant', type: 'text')]
    protected string $runtimeVariant;

    #[ORM\Column(name: 'session_id', type: 'text', nullable: true)]
    protected ?string $sessionId = null;

    #[ORM\Column(name: 'subject_key', type: 'text')]
    protected string $subjectKey;

    #[ORM\Column(name: 'subject_name', type: 'text')]
    protected string $subjectName;

    #[ORM\Column(name: 'subject_type', type: 'text')]
    protected string $subjectType;

    #[ORM\Column(name: 'turn_id', type: 'text')]
    protected string $turnId;

    #[ORM\Column(name: 'updated_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'user_edited', type: 'boolean')]
    protected bool $userEdited;

    #[ORM\Column(name: 'worldspace_id', type: 'text')]
    protected string $worldspaceId;

    #[ORM\Column(name: 'worldspace_name', type: 'text')]
    protected string $worldspaceName;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'active' => $this->active,
            'actor_identity_id' => $this->actorIdentityId,
            'baseid' => $this->baseid,
            'capture_id' => $this->captureId,
            'captured_at' => $this->capturedAt,
            'cell_id' => $this->cellId,
            'description' => $this->description,
            'generation' => $this->generation,
            'id' => $this->id,
            'image_path' => $this->imagePath,
            'image_sha256' => $this->imageSha256,
            'interaction_mode' => $this->interactionMode,
            'location_name' => $this->locationName,
            'locked' => $this->locked,
            'media_id' => $this->mediaId,
            'metadata' => $this->metadata,
            'model' => $this->model,
            'perspective' => $this->perspective,
            'plugin' => $this->plugin,
            'provider' => $this->provider,
            'refid' => $this->refid,
            'request_id' => $this->requestId,
            'request_sha256' => $this->requestSha256,
            'response_payload' => $this->responsePayload,
            'runtime_variant' => $this->runtimeVariant,
            'session_id' => $this->sessionId,
            'subject_key' => $this->subjectKey,
            'subject_name' => $this->subjectName,
            'subject_type' => $this->subjectType,
            'turn_id' => $this->turnId,
            'updated_at' => $this->updatedAt,
            'user_edited' => $this->userEdited,
            'worldspace_id' => $this->worldspaceId,
            'worldspace_name' => $this->worldspaceName,
        ];
    }
}
