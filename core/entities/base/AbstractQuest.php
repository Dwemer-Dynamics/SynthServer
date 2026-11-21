<?php

// The mapped row of public.core_quest. Read-only mapping: reads go through QuestDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_quest', schema: 'public')]
class AbstractQuest
{
    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $briefing2 = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $briefing = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $data = null;

    #[ORM\Column(name: 'editor_id', type: 'text', nullable: true)]
    protected ?string $editorId = null;

    #[ORM\Column(type: 'bigint')]
    protected int|string $gamets;

    #[ORM\Column(name: 'giver_actor_id', type: 'text', nullable: true)]
    protected ?string $giverActorId = null;

    #[ORM\Column(name: 'id_quest', type: 'string', length: 1024)]
    protected string $idQuest;

    #[ORM\Column(name: 'is_unique', type: 'boolean', nullable: true)]
    protected ?bool $isUnique = null;

    #[ORM\Column(type: 'bigint')]
    protected int|string $localts;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $mod = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $name = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $reward = null;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $rowid;

    #[ORM\Column(type: 'string', nullable: true, length: 1024)]
    protected ?string $sess = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $stage = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $status = null;

    #[ORM\Column(name: 'target_id', type: 'text', nullable: true)]
    protected ?string $targetId = null;

    #[ORM\Column(type: 'text')]
    protected string $ts;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'briefing2' => $this->briefing2,
            'briefing' => $this->briefing,
            'data' => $this->data,
            'editor_id' => $this->editorId,
            'gamets' => $this->gamets,
            'giver_actor_id' => $this->giverActorId,
            'id_quest' => $this->idQuest,
            'is_unique' => $this->isUnique,
            'localts' => $this->localts,
            'mod' => $this->mod,
            'name' => $this->name,
            'reward' => $this->reward,
            'rowid' => $this->rowid,
            'sess' => $this->sess,
            'stage' => $this->stage,
            'status' => $this->status,
            'target_id' => $this->targetId,
            'ts' => $this->ts,
        ];
    }
}
