<?php

// The mapped row of public.core_config_setting: one stored setting of one scope ('general', 'conf', 'player', 'narrator',
// 'meta'). It is read-only: every write stays in lib/settings.php and the other SQL writers, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_config_setting', schema: 'public')]
class AbstractConfigSetting
{
    #[ORM\Id]
    #[ORM\Column(type: 'text')]
    protected string $scope;

    #[ORM\Id]
    #[ORM\Column(name: 'config_key', type: 'text')]
    protected string $key;

    #[ORM\Column(name: 'config_value', type: 'text', nullable: true)]
    protected ?string $value = null;

    #[ORM\Column(type: 'text')]
    protected string $description = '';

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeImmutable $updatedAt = null;

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
