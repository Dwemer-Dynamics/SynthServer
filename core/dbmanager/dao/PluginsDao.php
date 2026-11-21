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

    /** Number of game plugins (home dashboard). */
    public function dashboardCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM core_plugins');
    }

    /**
     * Game plugins, at most 250, with the given columns (all columns when none is requested), by name when plugin_name is
     * selected (home dashboard).
     *
     * @param list<string> $columns from plugin_name, is_light, compile_index, small_file_compile_index, formid_prefix
     * @return list<array<string, mixed>>
     */
    public function dashboardRows(array $columns): array
    {
        $columns = array_values(array_intersect($columns, ['plugin_name', 'is_light', 'compile_index', 'small_file_compile_index', 'formid_prefix']));
        $select = $columns === [] ? '*' : implode(', ', $columns);
        $order = in_array('plugin_name', $columns, true) ? ' ORDER BY plugin_name ASC' : '';

        return $this->connection()->fetchAllAssociative('SELECT '.$select.' FROM core_plugins'.$order.' LIMIT 250');
    }
}
