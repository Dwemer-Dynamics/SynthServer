<?php

// The mapped row of public.core_npc_biography_template_actor. Read-only mapping: reads go through NpcBiographyTemplateActorDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_npc_biography_template_actor', schema: 'public')]
class AbstractNpcBiographyTemplateActor
{
    #[ORM\Column(name: 'base_local_formid', type: 'string', nullable: true, length: 8)]
    protected ?string $baseLocalFormid = null;

    #[ORM\Column(name: 'base_plugin', type: 'text', nullable: true)]
    protected ?string $basePlugin = null;

    #[ORM\Column(name: 'exact_name', type: 'text', nullable: true)]
    protected ?string $exactName = null;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $id;

    #[ORM\Column(name: 'is_nonverbal_creature', type: 'boolean')]
    protected bool $isNonverbalCreature;

    #[ORM\Column(name: 'reference_local_formid', type: 'string', nullable: true, length: 8)]
    protected ?string $referenceLocalFormid = null;

    #[ORM\Column(name: 'reference_plugin', type: 'text', nullable: true)]
    protected ?string $referencePlugin = null;

    #[ORM\Column(name: 'template_name', type: 'string', length: 128)]
    protected string $templateName;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'base_local_formid' => $this->baseLocalFormid,
            'base_plugin' => $this->basePlugin,
            'exact_name' => $this->exactName,
            'id' => $this->id,
            'is_nonverbal_creature' => $this->isNonverbalCreature,
            'reference_local_formid' => $this->referenceLocalFormid,
            'reference_plugin' => $this->referencePlugin,
            'template_name' => $this->templateName,
        ];
    }
}
