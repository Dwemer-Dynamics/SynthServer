<?php

// Reads and writes core_worldspace: the worldspaces the location markers name, filled from the markers (no seed list). A
// worldspace uses its own coordinates for the map unless it is told to use another's.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use InvalidArgumentException;
use Synth\Core\Entities\Base\AbstractWorldspace;

require_once __DIR__.'/UsesEntityManager.php';
require_once dirname(__DIR__, 2).'/entities/base/AbstractWorldspace.php';

/** Finds and stores worldspaces. */
final class WorldspaceDao
{
    use UsesEntityManager;

    /** One worldspace by id, or null. */
    public function find(int $worldspaceId): ?AbstractWorldspace
    {
        return $this->fresh()->find(AbstractWorldspace::class, $worldspaceId);
    }

    /** One worldspace by its plugin-local identity, or null. */
    public function findByRef(string $plugin, int $baseid): ?AbstractWorldspace
    {
        return $this->fresh()->getRepository(AbstractWorldspace::class)->findOneBy(['plugin' => strtolower($plugin), 'baseid' => $baseid]);
    }

    /**
     * Inserts or updates a worldspace by plugin and base id and returns its id. Without a map worldspace it maps to itself.
     *
     * @param array<string, mixed> $row plugin and baseid required; editor_id, name, parent_worldspace_id, map_worldspace_id optional
     */
    public function upsert(array $row): int
    {
        $plugin = strtolower((string) ($row['plugin'] ?? ''));
        $baseid = (int) ($row['baseid'] ?? -1);
        if ($plugin === '' || $baseid < 0 || $baseid > 0xFFFFFF) {
            throw new InvalidArgumentException('A worldspace needs a plugin and a 24-bit base id.');
        }
        $connection = $this->connection();
        $id = (int) $connection->fetchOne(
            'INSERT INTO public.core_worldspace (plugin, baseid, editor_id, name, parent_worldspace_id, map_worldspace_id)
             VALUES (:plugin, :baseid, :editor_id, :name, :parent, :map)
             ON CONFLICT (plugin, baseid) DO UPDATE SET editor_id = COALESCE(EXCLUDED.editor_id, core_worldspace.editor_id),
               name = COALESCE(EXCLUDED.name, core_worldspace.name),
               parent_worldspace_id = COALESCE(EXCLUDED.parent_worldspace_id, core_worldspace.parent_worldspace_id),
               map_worldspace_id = COALESCE(EXCLUDED.map_worldspace_id, core_worldspace.map_worldspace_id)
             RETURNING worldspace_id',
            ['plugin' => $plugin, 'baseid' => $baseid, 'editor_id' => $row['editor_id'] ?? null, 'name' => $row['name'] ?? null,
                'parent' => $row['parent_worldspace_id'] ?? null, 'map' => $row['map_worldspace_id'] ?? null]
        );
        $connection->executeStatement('UPDATE public.core_worldspace SET map_worldspace_id = worldspace_id WHERE worldspace_id = :id AND map_worldspace_id IS NULL', ['id' => $id]);

        return $id;
    }
}
