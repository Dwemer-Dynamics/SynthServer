<?php

// Reads core_item_description (and the combined_descriptions view for the admin list). Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractItemDescription;

/** Finds rows of core_item_description. */
final class ItemDescriptionDao
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

    /** The description of the combined (base and custom) item whose name equals this one, ignoring case, or null. */
    public function combinedDescriptionByName(string $name): ?string
    {
        try {
            $row = $this->connection()->fetchAssociative(
                'SELECT description FROM combined_descriptions WHERE LOWER(name) = LOWER(:name) LIMIT 1',
                ['name' => $name]
            );
        } catch (\Doctrine\DBAL\Exception) {
            return null;
        }

        return $row === false || $row['description'] === null ? null : (string) $row['description'];
    }
}
