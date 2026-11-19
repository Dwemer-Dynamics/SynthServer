<?php

// A runtime (0xFF) actor of one playthrough, identified by the opaque id the game client mints and persists in its co-save
// (docs/RUNTIME-ACTORS.md). The form id is only a hint; the name is display data. Read-only mapping: NpcRuntimeActorDao writes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_npc_runtime_actor', schema: 'public')]
class AbstractNpcRuntimeActor
{
    #[ORM\Id]
    #[ORM\Column(name: 'playthrough_id', type: 'text')]
    protected string $playthroughId;

    #[ORM\Id]
    #[ORM\Column(name: 'runtime_actor_id', type: 'guid')]
    protected string $runtimeActorId;

    #[ORM\Column(name: 'character_id', type: 'integer')]
    protected int $characterId;

    #[ORM\Column(name: 'last_form_id', type: 'string', length: 8, options: ['fixed' => true])]
    protected string $lastFormId;

    #[ORM\Column(type: 'string', length: 255)]
    protected string $name;

    #[ORM\Column(name: 'race_plugin', type: 'text', nullable: true)]
    protected ?string $racePlugin = null;

    #[ORM\Column(name: 'race_local_id', type: 'string', length: 8, nullable: true)]
    protected ?string $raceLocalId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $gender = null;

    #[ORM\Column(name: 'actor_class', type: 'text', nullable: true)]
    protected ?string $actorClass = null;

    #[ORM\Column(name: 'base_plugin', type: 'text', nullable: true)]
    protected ?string $basePlugin = null;

    #[ORM\Column(name: 'base_local_id', type: 'string', length: 8, nullable: true)]
    protected ?string $baseLocalId = null;

    #[ORM\Column(name: 'template_plugin', type: 'text', nullable: true)]
    protected ?string $templatePlugin = null;

    #[ORM\Column(name: 'template_local_id', type: 'string', length: 8, nullable: true)]
    protected ?string $templateLocalId = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true, options: ['fixed' => true])]
    protected ?string $fingerprint = null;

    #[ORM\Column(name: 'spawn_kind', type: 'string', length: 32)]
    protected string $spawnKind = 'unknown';

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'spawn_context', type: 'json', options: ['jsonb' => true])]
    protected array $spawnContext = [];

    #[ORM\Column(type: 'string', length: 16)]
    protected string $status = 'active';

    #[ORM\Column(name: 'replaced_by_runtime_actor_id', type: 'guid', nullable: true)]
    protected ?string $replacedByRuntimeActorId = null;

    #[ORM\Column(name: 'first_seen_gamets', type: 'bigint', nullable: true)]
    protected ?int $firstSeenGamets = null;

    #[ORM\Column(name: 'last_seen_gamets', type: 'bigint', nullable: true)]
    protected ?int $lastSeenGamets = null;

    #[ORM\Column(name: 'created_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $updatedAt;

    public function getPlaythroughId(): string
    {
        return $this->playthroughId;
    }

    public function getRuntimeActorId(): string
    {
        return $this->runtimeActorId;
    }

    public function getCharacterId(): int
    {
        return $this->characterId;
    }

    public function getLastFormId(): string
    {
        return $this->lastFormId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getFingerprint(): ?string
    {
        return $this->fingerprint;
    }

    public function getSpawnKind(): string
    {
        return $this->spawnKind;
    }

    /** @return array<string, mixed> */
    public function getSpawnContext(): array
    {
        return $this->spawnContext;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getReplacedByRuntimeActorId(): ?string
    {
        return $this->replacedByRuntimeActorId;
    }
}
