<?php

// Reads and writes the location catalog (core_location and core_location_marker; docs/LOCATION-CATALOG.md). This DAO stores and
// finds catalog rows. The computed fields (parent, region, position, tags) and the batch handling belong to the catalog
// protocol handler, which calls upsert() and replaceMarkers(); both are plain statements, so they run inside the caller's transaction.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use InvalidArgumentException;
use Synth\Core\Entities\Base\AbstractLocation;

require_once __DIR__.'/UsesEntityManager.php';
require_once dirname(__DIR__, 2).'/entities/base/AbstractLocation.php';

/** Finds and stores catalog locations and their markers. */
final class LocationDao
{
    use UsesEntityManager;

    /** The longest ancestor walk, as the catalog design bounds it. */
    public const MAX_ANCESTRY = 16;

    /** One location by id, or null. */
    public function find(int $locationId): ?AbstractLocation
    {
        return $this->fresh()->find(AbstractLocation::class, $locationId);
    }

    /** One location by its plugin-local identity, or null. The plugin is lower-cased. */
    public function findByRef(string $plugin, int $baseid): ?AbstractLocation
    {
        return $this->fresh()->getRepository(AbstractLocation::class)->findOneBy(['plugin' => strtolower($plugin), 'baseid' => $baseid]);
    }

    /**
     * The location and its ancestors, nearest first, following parent_location_id; stops at the limit or a cycle.
     *
     * @return list<array<string, mixed>> location rows as arrays (location_id, name, parent_location_id, tags, ...)
     */
    public function ancestry(int $locationId, int $limit = self::MAX_ANCESTRY): array
    {
        $limit = max(1, min($limit, self::MAX_ANCESTRY));

        return $this->connection()->fetchAllAssociative(
            'WITH RECURSIVE chain AS (
                SELECT l.*, 1 AS depth, ARRAY[l.location_id] AS seen FROM public.core_location l WHERE l.location_id = :id
                UNION ALL
                SELECT p.*, c.depth + 1, c.seen || p.location_id
                  FROM chain c JOIN public.core_location p ON p.location_id = c.parent_location_id
                 WHERE c.depth < :limit AND NOT (p.location_id = ANY(c.seen)))
             SELECT location_id, name, plugin, baseid, parent_location_id, region_location_id, tags, depth FROM chain ORDER BY depth',
            ['id' => $locationId, 'limit' => $limit]
        );
    }

    /**
     * Inserts or updates one catalog record by plugin and base id and returns its location id. The computed columns (parent link,
     * region, map position) are not touched here.
     *
     * @param array<string, mixed> $record plugin, baseid, name required; editor_id, parent_plugin, parent_baseid, keywords, tags,
     *     last_catalog_sha256 optional
     */
    public function upsert(array $record): int
    {
        $plugin = strtolower((string) ($record['plugin'] ?? ''));
        $baseid = (int) ($record['baseid'] ?? -1);
        $name = (string) ($record['name'] ?? '');
        if ($plugin === '' || $baseid < 0 || $baseid > 0xFFFFFF || $name === '') {
            throw new InvalidArgumentException('A location needs a plugin, a 24-bit base id and a name.');
        }

        return (int) $this->connection()->fetchOne(
            'INSERT INTO public.core_location (plugin, baseid, name, editor_id, parent_plugin, parent_baseid, keywords, tags, last_catalog_sha256)
             VALUES (:plugin, :baseid, :name, :editor_id, :parent_plugin, :parent_baseid, CAST(:keywords AS jsonb), COALESCE(CAST(:tags AS jsonb), CAST(:empty AS jsonb)), :sha)
             ON CONFLICT (plugin, baseid) DO UPDATE SET name = EXCLUDED.name, editor_id = EXCLUDED.editor_id,
               parent_plugin = EXCLUDED.parent_plugin, parent_baseid = EXCLUDED.parent_baseid, keywords = EXCLUDED.keywords,
               tags = COALESCE(CAST(:tags AS jsonb), core_location.tags), last_catalog_sha256 = EXCLUDED.last_catalog_sha256, updated_at = now()
             RETURNING location_id',
            ['plugin' => $plugin, 'baseid' => $baseid, 'name' => $name, 'editor_id' => $record['editor_id'] ?? null,
                'parent_plugin' => isset($record['parent_plugin']) ? strtolower((string) $record['parent_plugin']) : null,
                'parent_baseid' => $record['parent_baseid'] ?? null,
                'keywords' => json_encode(array_values((array) ($record['keywords'] ?? [])), JSON_THROW_ON_ERROR),
                'tags' => isset($record['tags']) ? json_encode(array_values((array) $record['tags']), JSON_THROW_ON_ERROR) : null,
                'empty' => '[]', 'sha' => $record['last_catalog_sha256'] ?? null]
        );
    }

    /**
     * Replaces every marker of a location with the given list: delete, then insert, as plain statements.
     *
     * @param list<array<string, mixed>> $markers marker_type, plugin, baseid required; cell_plugin, cell_baseid, interior, worldspace_id, x, y, z, disabled optional
     */
    public function replaceMarkers(int $locationId, array $markers): int
    {
        $connection = $this->connection();
        $connection->executeStatement('DELETE FROM public.core_location_marker WHERE location_id = :id', ['id' => $locationId]);
        $count = 0;
        foreach ($markers as $marker) {
            $count += $connection->executeStatement(
                'INSERT INTO public.core_location_marker (location_id, marker_type, plugin, baseid, cell_plugin, cell_baseid, interior, worldspace_id, x, y, z, disabled)
                 VALUES (:location_id, :marker_type, :plugin, :baseid, :cell_plugin, :cell_baseid, :interior, :worldspace_id, :x, :y, :z, :disabled)
                 ON CONFLICT (location_id, marker_type, plugin, baseid) DO NOTHING',
                ['location_id' => $locationId, 'marker_type' => (string) $marker['marker_type'], 'plugin' => strtolower((string) $marker['plugin']),
                    'baseid' => (int) $marker['baseid'], 'cell_plugin' => isset($marker['cell_plugin']) ? strtolower((string) $marker['cell_plugin']) : null,
                    'cell_baseid' => $marker['cell_baseid'] ?? null, 'interior' => (bool) ($marker['interior'] ?? false),
                    'worldspace_id' => $marker['worldspace_id'] ?? null, 'x' => $marker['x'] ?? null, 'y' => $marker['y'] ?? null,
                    'z' => $marker['z'] ?? null, 'disabled' => (bool) ($marker['disabled'] ?? false)],
                ['interior' => \Doctrine\DBAL\ParameterType::BOOLEAN, 'disabled' => \Doctrine\DBAL\ParameterType::BOOLEAN]
            );
        }

        return $count;
    }
}
