<?php

// Reads core_item_description (and the combined_descriptions view for the admin list). Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractItemDescription;

/** Finds rows of core_item_description. */
class ItemDescriptionDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $plugin, string $baseid): ?AbstractItemDescription
    {
        return $this->fresh()->find(AbstractItemDescription::class, ['plugin' => $plugin, 'baseid' => $baseid]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractItemDescription> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractItemDescription::class)->findBy([], ['plugin' => 'ASC', 'baseid' => 'ASC'], max(1, $limit)));
    }

    /**
     * Rows of the combined_descriptions view for the admin list, by name. $letter (first letter of the name) and $search (matched
     * on plugin, baseid or name) are both optional. Throws on a database error. @return list<array<string, mixed>>
     */
    public function searchCombined(string $letter = '', string $search = ''): array
    {
        $sql = 'SELECT * FROM synth.combined_descriptions';
        $params = [];
        if ($letter !== '') {
            $sql .= ' WHERE LOWER(name) LIKE LOWER(?)';
            $params[] = $letter.'%';
            if ($search !== '') {
                $sql .= ' AND (LOWER(plugin) LIKE LOWER(?) OR LOWER(baseid) LIKE LOWER(?) OR LOWER(name) LIKE LOWER(?))';
                array_push($params, '%'.$search.'%', '%'.$search.'%', '%'.$search.'%');
            }
        } elseif ($search !== '') {
            $sql .= ' WHERE LOWER(plugin) LIKE LOWER(?) OR LOWER(baseid) LIKE LOWER(?) OR LOWER(name) LIKE LOWER(?)';
            array_push($params, '%'.$search.'%', '%'.$search.'%', '%'.$search.'%');
        }

        return $this->connection()->fetchAllAssociative($sql.' ORDER BY name ASC', $params);
    }

    /** Plugin, base id, name and description of one item, or null. @return array<string, mixed>|null */
    public function recordByKey(string $plugin, string $baseid): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT plugin, baseid, name, description FROM public.core_item_description WHERE plugin = ? AND baseid = ? LIMIT 1', [$plugin, $baseid]);

        return $row === false ? null : $row;
    }

    /** The non-empty description of one item, or null. */
    public function descriptionByKey(string $plugin, string $baseid): ?string
    {
        $value = $this->connection()->fetchOne("SELECT description FROM public.core_item_description WHERE plugin = ? AND baseid = ? AND description <> '' LIMIT 1", [$plugin, $baseid]);

        return is_string($value) ? $value : null;
    }

    /** The description of the first item of the combined catalogue whose name matches case-insensitively, or null. */
    public function combinedDescriptionByName(string $name): ?string
    {
        $value = $this->connection()->fetchOne('SELECT description FROM combined_descriptions WHERE LOWER(name) = LOWER(?) LIMIT 1', [$name]);

        return is_string($value) ? $value : null;
    }

    /**
     * The two best name matches in the combined catalogue (exact case-insensitive match first, then trigram similarity) as
     * plugin, baseid, name, score and exact_match.
     *
     * @return list<array<string, mixed>>
     */
    public function similarByName(string $name): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT plugin, baseid, name,
                CASE WHEN lower(COALESCE(name, '')) = lower(?) THEN 1.0 ELSE similarity(COALESCE(name, ''), ?) END AS score,
                CASE WHEN lower(COALESCE(name, '')) = lower(?) THEN 1 ELSE 0 END AS exact_match
             FROM synth.combined_descriptions WHERE COALESCE(name, '') <> '' ORDER BY exact_match DESC, score DESC, name ASC LIMIT 2",
            [$name, $name, $name]
        );
    }
}
