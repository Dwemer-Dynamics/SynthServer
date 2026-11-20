<?php

// The mapped row of public.core_npc_biography_template. Read-only mapping: reads go through NpcBiographyTemplateDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_npc_biography_template', schema: 'public')]
class AbstractNpcBiographyTemplate
{
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $appearance = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $core = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $gender = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $goals = null;

    #[ORM\Id]
    #[ORM\Column(name: 'npc_name', type: 'string', length: 128)]
    protected string $npcName;

    #[ORM\Column(name: 'npc_static_bio', type: 'text', nullable: true)]
    protected ?string $npcStaticBio = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $occupation = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $personality = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $race = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $refid = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $relationships = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $skills = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $speechstyle = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $voiceid = null;

    #[ORM\Column(name: 'worldknowledge_tags', type: 'text', nullable: true)]
    protected ?string $worldknowledgeTags = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'appearance' => $this->appearance,
            'core' => $this->core,
            'gender' => $this->gender,
            'goals' => $this->goals,
            'npc_name' => $this->npcName,
            'npc_static_bio' => $this->npcStaticBio,
            'occupation' => $this->occupation,
            'personality' => $this->personality,
            'race' => $this->race,
            'refid' => $this->refid,
            'relationships' => $this->relationships,
            'skills' => $this->skills,
            'speechstyle' => $this->speechstyle,
            'voiceid' => $this->voiceid,
            'worldknowledge_tags' => $this->worldknowledgeTags,
        ];
    }
}
