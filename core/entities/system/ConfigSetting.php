<?php

// A stored setting as pages and managers see it: the scope, the key, the raw text value and its description. The mapped
// class stays inside the DAO; top-level code only ever holds this one.

declare(strict_types=1);

namespace Synth\Core\Entities\System;

use Synth\Core\Entities\Base\AbstractConfigSetting;

require_once __DIR__.'/CopiesMappedEntity.php';
require_once dirname(__DIR__).'/base/AbstractConfigSetting.php';

/** One row of core_config_setting, copied out of the loaded entity so changing it never touches the database. */
final class ConfigSetting extends AbstractConfigSetting
{
    use CopiesMappedEntity;

    /** Copies a loaded row into an untracked system object. */
    public static function fromMapped(AbstractConfigSetting $mapped): self
    {
        return self::copyOf($mapped);
    }

    /** The setting's scope. */
    public function scope(): string
    {
        return $this->scope;
    }

    /** The setting's key within its scope. */
    public function key(): string
    {
        return $this->key;
    }

    /** The raw stored text, or null when the value is NULL. */
    public function value(): ?string
    {
        return $this->value;
    }

    /** The stored description ('' when none). */
    public function description(): string
    {
        return $this->description;
    }
}
