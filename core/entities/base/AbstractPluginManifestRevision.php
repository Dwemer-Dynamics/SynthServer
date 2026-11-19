<?php

// One load-order manifest a playthrough was played under, kept so stored references can be decoded under the manifest they
// were sent with. Append-only: read-only mapping, PluginManifestRevisionDao inserts it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Synth\Core\DbManager\Types\PreciseDateTimeType;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_plugin_manifest_revision', schema: 'public')]
class AbstractPluginManifestRevision
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    protected int|string $id;

    #[ORM\Column(name: 'playthrough_id', type: 'text')]
    protected string $playthroughId;

    #[ORM\Column(name: 'manifest_sha256', type: 'string', length: 64, options: ['fixed' => true])]
    protected string $manifestSha256;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    protected array $plugins = [];

    #[ORM\Column(name: 'runtime_variant', type: 'text', nullable: true)]
    protected ?string $runtimeVariant = null;

    #[ORM\Column(name: 'game_version', type: 'text', nullable: true)]
    protected ?string $gameVersion = null;

    #[ORM\Column(name: 'first_seen_at', type: PreciseDateTimeType::NAME)]
    protected DateTimeImmutable $firstSeenAt;

    public function getId(): int
    {
        return (int) $this->id;
    }

    public function getPlaythroughId(): string
    {
        return $this->playthroughId;
    }

    public function getManifestSha256(): string
    {
        return $this->manifestSha256;
    }

    /** @return list<array<string, mixed>> */
    public function getPlugins(): array
    {
        return $this->plugins;
    }

    public function getRuntimeVariant(): ?string
    {
        return $this->runtimeVariant;
    }

    public function getGameVersion(): ?string
    {
        return $this->gameVersion;
    }

    public function getFirstSeenAt(): DateTimeImmutable
    {
        return $this->firstSeenAt;
    }
}
