<?php

// The mapped row of public.core_npc_profile_rule. Read-only mapping: reads go through NpcProfileRuleDao; writes stay in the
// statements that own them, so Doctrine never flushes it.
// Not mapped (DAO-only or unsupported types): match_mods.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_npc_profile_rule', schema: 'public')]
class AbstractNpcProfileRule
{
    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    protected mixed $action = null;

    #[ORM\Column(type: 'text')]
    protected string $description;

    #[ORM\Column(type: 'boolean', nullable: true)]
    protected ?bool $enabled = null;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    protected int $id;

    #[ORM\Column(name: 'match_base', type: 'text', nullable: true)]
    protected ?string $matchBase = null;

    #[ORM\Column(name: 'match_faction', type: 'text', nullable: true)]
    protected ?string $matchFaction = null;

    #[ORM\Column(name: 'match_gender', type: 'text', nullable: true)]
    protected ?string $matchGender = null;

    #[ORM\Column(name: 'match_name', type: 'text', nullable: true)]
    protected ?string $matchName = null;

    #[ORM\Column(name: 'match_race', type: 'text', nullable: true)]
    protected ?string $matchRace = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $priority = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $profile = null;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'description' => $this->description,
            'enabled' => $this->enabled,
            'id' => $this->id,
            'match_base' => $this->matchBase,
            'match_faction' => $this->matchFaction,
            'match_gender' => $this->matchGender,
            'match_name' => $this->matchName,
            'match_race' => $this->matchRace,
            'priority' => $this->priority,
            'profile' => $this->profile,
        ];
    }
}
