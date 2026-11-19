<?php

// The mapped row of public.core_profiles: one connector profile (the connectors one NPC or the narrator uses). Read-only mapping: reads go through ProfileDao;
// the edit API owns writes, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_profiles', schema: 'public')]
class AbstractProfile
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    protected int $id;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $label = null;

    #[ORM\Column(name: 'default_npc', type: 'text', nullable: true)]
    protected ?string $defaultNpc = null;

    #[ORM\Column(name: 'default_narrator', type: 'text', nullable: true)]
    protected ?string $defaultNarrator = null;

    #[ORM\Column(name: 'tts_connector_id', type: 'integer', nullable: true)]
    protected ?int $ttsConnectorId = null;

    #[ORM\Column(name: 'llm_primary_id', type: 'integer', nullable: true)]
    protected ?int $llmPrimaryId = null;

    #[ORM\Column(name: 'llm_secondary_id', type: 'integer', nullable: true)]
    protected ?int $llmSecondaryId = null;

    #[ORM\Column(name: 'llm_tertiary_id', type: 'integer', nullable: true)]
    protected ?int $llmTertiaryId = null;

    #[ORM\Column(name: 'llm_quaternary_id', type: 'integer', nullable: true)]
    protected ?int $llmQuaternaryId = null;

    #[ORM\Column(name: 'llm_formatter_id', type: 'integer', nullable: true)]
    protected ?int $llmFormatterId = null;

    #[ORM\Column(name: 'llm_fallback_id', type: 'integer', nullable: true)]
    protected ?int $llmFallbackId = null;

    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    /** @var array<string, mixed>|null */
    protected ?array $metadata = null;

    #[ORM\Column(name: 'diary_connector_id', type: 'integer', nullable: true)]
    protected ?int $diaryConnectorId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $slot = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $prompt = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'default_npc' => $this->defaultNpc,
            'default_narrator' => $this->defaultNarrator,
            'tts_connector_id' => $this->ttsConnectorId,
            'llm_primary_id' => $this->llmPrimaryId,
            'llm_secondary_id' => $this->llmSecondaryId,
            'llm_tertiary_id' => $this->llmTertiaryId,
            'llm_quaternary_id' => $this->llmQuaternaryId,
            'llm_formatter_id' => $this->llmFormatterId,
            'llm_fallback_id' => $this->llmFallbackId,
            'metadata' => $this->metadata,
            'diary_connector_id' => $this->diaryConnectorId,
            'slot' => $this->slot,
            'prompt' => $this->prompt,
        ];
    }
}
