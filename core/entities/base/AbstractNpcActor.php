<?php

// A placed actor, identified by its plugin and plugin-local reference id (never the load-order prefix), pointing at the
// shared character (profile, biography, voice). Read-only mapping: NpcActorDao writes it with parameterized statements.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_npc_actor', schema: 'public')]
#[ORM\Index(name: 'core_npc_actor_character_idx', columns: ['character_id'])]
class AbstractNpcActor
{
    #[ORM\Id]
    #[ORM\Column(name: 'refid', type: 'string', length: 16)]
    protected string $refId;

    #[ORM\Id]
    #[ORM\Column(name: 'plugin', type: 'string', length: 255)]
    protected string $plugin;

    #[ORM\Column(name: 'character_id', type: 'integer')]
    protected int $characterId;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $name = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $gender = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $race = null;

    #[ORM\Column(name: 'actor_class', type: 'text', nullable: true)]
    protected ?string $actorClass = null;

    #[ORM\Column(name: 'base_plugin', type: 'text', nullable: true)]
    protected ?string $basePlugin = null;

    #[ORM\Column(name: 'base_formid', type: 'string', length: 16, nullable: true)]
    protected ?string $baseFormid = null;

    #[ORM\Column(name: 'template_plugin', type: 'text', nullable: true)]
    protected ?string $templatePlugin = null;

    #[ORM\Column(name: 'template_formid', type: 'string', length: 16, nullable: true)]
    protected ?string $templateFormid = null;

    #[ORM\Column(name: 'replaced_character_id', type: 'integer', nullable: true)]
    protected ?int $replacedCharacterId = null;

    #[ORM\Column(name: 'replaced_name', type: 'text', nullable: true)]
    protected ?string $replacedName = null;

    public function getRefId(): string
    {
        return $this->refId;
    }

    public function getPlugin(): string
    {
        return $this->plugin;
    }

    public function getCharacterId(): int
    {
        return $this->characterId;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getGender(): ?string
    {
        return $this->gender;
    }

    public function getRace(): ?string
    {
        return $this->race;
    }

    public function getActorClass(): ?string
    {
        return $this->actorClass;
    }

    public function getBasePlugin(): ?string
    {
        return $this->basePlugin;
    }

    public function getBaseFormid(): ?string
    {
        return $this->baseFormid;
    }

    public function getTemplatePlugin(): ?string
    {
        return $this->templatePlugin;
    }

    public function getTemplateFormid(): ?string
    {
        return $this->templateFormid;
    }

    public function getReplacedCharacterId(): ?int
    {
        return $this->replacedCharacterId;
    }

    public function getReplacedName(): ?string
    {
        return $this->replacedName;
    }
}
