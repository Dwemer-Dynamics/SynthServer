<?php

// The mapped row of public.core_plugins. Read-only mapping: reads go through PluginsDao; writes stay in the
// statements that own them, so Doctrine never flushes it.

declare(strict_types=1);

namespace Synth\Core\Entities\Base;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'core_plugins', schema: 'public')]
class AbstractPlugins
{
    #[ORM\Column(name: 'compile_index', type: 'integer')]
    protected int $compileIndex;

    #[ORM\Column(name: 'formid_prefix', type: 'text')]
    protected string $formidPrefix;

    #[ORM\Column(name: 'is_light', type: 'boolean')]
    protected bool $isLight;

    #[ORM\Column(name: 'partial_index', type: 'integer')]
    protected int $partialIndex;

    #[ORM\Id]
    #[ORM\Column(name: 'plugin_name', type: 'text')]
    protected string $pluginName;

    #[ORM\Column(name: 'small_file_compile_index', type: 'integer')]
    protected int $smallFileCompileIndex;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    protected DateTimeImmutable $updatedAt;

    /** The row as an array keyed by column name, for pages and tests. @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'compile_index' => $this->compileIndex,
            'formid_prefix' => $this->formidPrefix,
            'is_light' => $this->isLight,
            'partial_index' => $this->partialIndex,
            'plugin_name' => $this->pluginName,
            'small_file_compile_index' => $this->smallFileCompileIndex,
            'updated_at' => $this->updatedAt,
        ];
    }
}
