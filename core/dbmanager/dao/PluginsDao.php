<?php

// Reads core_plugins. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractPlugins;

/** Finds rows of core_plugins. */
final class PluginsDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $pluginName): ?AbstractPlugins
    {
        return $this->fresh()->find(AbstractPlugins::class, $pluginName);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractPlugins> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractPlugins::class)->findBy([], ['pluginName' => 'ASC'], max(1, $limit)));
    }
}
