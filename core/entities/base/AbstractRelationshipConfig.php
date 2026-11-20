<?php

// The mapped row of public.core_relationship_config: authored relationship configuration for one directed pair in one playthrough. Read-only mapping: RelationshipConfigDao writes it with parameterized statements,
// so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_relationship_config', schema: 'public')]
class AbstractRelationshipConfig
{
    #[ORM\Id]
    #[ORM\Column(name: 'playthrough_id', type: 'text')]
    protected string $playthroughId;

    #[ORM\Id]
    #[ORM\Column(name: 'source_character_id', type: 'integer')]
    protected int $sourceCharacterId;

    #[ORM\Id]
    #[ORM\Column(name: 'target_character_id', type: 'integer')]
    protected int $targetCharacterId;

    #[ORM\Column(name: 'relation_detail', type: 'text', nullable: true)]
    protected ?string $relationDetail = null;

    #[ORM\Column(name: 'custom_info', type: 'text', nullable: true)]
    protected ?string $customInfo = null;

    #[ORM\Column(name: 'private_notes', type: 'text', nullable: true)]
    protected ?string $privateNotes = null;

    #[ORM\Column(name: 'automatic_updates_locked', type: 'boolean')]
    protected bool $automaticUpdatesLocked;

    #[ORM\Column(name: 'baseline_affinity', type: 'smallint', nullable: true)]
    protected ?int $baselineAffinity = null;

    #[ORM\Column(name: 'baseline_type', type: 'string', nullable: true, length: 50)]
    protected ?string $baselineType = null;

    #[ORM\Column(type: 'bigint')]
    protected int $revision;

    #[ORM\Column(name: 'affinity_baseline_revision', type: 'bigint')]
    protected int $affinityBaselineRevision;

    #[ORM\Column(name: 'type_baseline_revision', type: 'bigint')]
    protected int $typeBaselineRevision;

    #[ORM\Column(name: 'created_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $updatedAt;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'playthrough_id' => $this->playthroughId,
            'source_character_id' => $this->sourceCharacterId,
            'target_character_id' => $this->targetCharacterId,
            'relation_detail' => $this->relationDetail,
            'custom_info' => $this->customInfo,
            'private_notes' => $this->privateNotes,
            'automatic_updates_locked' => $this->automaticUpdatesLocked,
            'baseline_affinity' => $this->baselineAffinity,
            'baseline_type' => $this->baselineType,
            'revision' => $this->revision,
            'affinity_baseline_revision' => $this->affinityBaselineRevision,
            'type_baseline_revision' => $this->typeBaselineRevision,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
