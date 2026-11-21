<?php

// The mapped row of public.core_tts_pronunciation. Read-only mapping: reads go through TtsPronunciationDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_tts_pronunciation', schema: 'public')]
class AbstractTtsPronunciation
{
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'boolean')]
    protected bool $enabled;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    protected int $id;

    #[ORM\Column(name: 'is_builtin', type: 'boolean')]
    protected bool $isBuiltin;

    #[ORM\Column(name: 'npc_names', type: 'string', length: 512)]
    protected string $npcNames;

    #[ORM\Column(name: 'oghma_tags', type: 'string', length: 512)]
    protected string $oghmaTags;

    #[ORM\Column(type: 'string', length: 512)]
    protected string $races;

    #[ORM\Column(name: 'source_text', type: 'string', length: 120)]
    protected string $sourceText;

    #[ORM\Column(name: 'spoken_text', type: 'string', length: 240)]
    protected string $spokenText;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    protected DateTimeImmutable $updatedAt;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'created_at' => $this->createdAt,
            'enabled' => $this->enabled,
            'id' => $this->id,
            'is_builtin' => $this->isBuiltin,
            'npc_names' => $this->npcNames,
            'oghma_tags' => $this->oghmaTags,
            'races' => $this->races,
            'source_text' => $this->sourceText,
            'spoken_text' => $this->spokenText,
            'updated_at' => $this->updatedAt,
        ];
    }
}
