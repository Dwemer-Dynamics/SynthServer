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

    /** The loaded plugin with this name (case-insensitive), or null. @return array<string, mixed>|null */
    public function findRowByName(string $pluginName): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT plugin_name, is_light, compile_index, small_file_compile_index, partial_index, formid_prefix, updated_at
             FROM public.core_plugins WHERE LOWER(plugin_name) = LOWER(?) LIMIT 1',
            [$pluginName]
        );

        return $row === false ? null : $row;
    }

    /** The loaded plugin with this form id prefix, or null. @return array<string, mixed>|null */
    public function findRowByFormIdPrefix(string $formIdPrefix): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT plugin_name, is_light, compile_index, small_file_compile_index, partial_index, formid_prefix, updated_at
             FROM public.core_plugins WHERE formid_prefix = ? LIMIT 1',
            [$formIdPrefix]
        );

        return $row === false ? null : $row;
    }

    /** Every loaded plugin (without updated_at), ordered by lower-case name. @return list<array<string, mixed>> */
    public function signatureRows(): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT plugin_name, is_light, compile_index, small_file_compile_index, partial_index, formid_prefix FROM public.core_plugins ORDER BY LOWER(plugin_name)'
        );
    }

    /**
     * Deletes every loaded plugin, then upserts each given one (two separate steps, as the legacy writer ran them).
     *
     * @param list<array{plugin_name: string, is_light: bool, compile_index: int, small_file_compile_index: int, partial_index: int, formid_prefix: string}> $rows
     */
    public function replaceAll(array $rows): void
    {
        $this->connection()->executeStatement('DELETE FROM public.core_plugins');
        foreach ($rows as $row) {
            $this->connection()->executeStatement(
                'INSERT INTO public.core_plugins (plugin_name, is_light, compile_index, small_file_compile_index, partial_index, formid_prefix, updated_at)
                 VALUES (?, CAST(? AS boolean), ?, ?, ?, ?, CURRENT_TIMESTAMP)
                 ON CONFLICT (plugin_name) DO UPDATE SET is_light = EXCLUDED.is_light, compile_index = EXCLUDED.compile_index,
                    small_file_compile_index = EXCLUDED.small_file_compile_index, partial_index = EXCLUDED.partial_index,
                    formid_prefix = EXCLUDED.formid_prefix, updated_at = CURRENT_TIMESTAMP',
                [$row['plugin_name'], $row['is_light'] ? 'true' : 'false', (int) $row['compile_index'], (int) $row['small_file_compile_index'],
                    (int) $row['partial_index'], $row['formid_prefix']]
            );
        }
    }
}
