<?php

// Reads core_item_description_custom. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractItemDescriptionCustom;

/** Finds rows of core_item_description_custom. */
final class ItemDescriptionCustomDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $plugin, string $baseid): ?AbstractItemDescriptionCustom
    {
        return $this->fresh()->find(AbstractItemDescriptionCustom::class, ['plugin' => $plugin, 'baseid' => $baseid]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractItemDescriptionCustom> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractItemDescriptionCustom::class)->findBy([], ['plugin' => 'ASC', 'baseid' => 'ASC'], max(1, $limit)));
    }

    /** Plugin, base id, name and description of one item, or null. @return array<string, mixed>|null */
    public function recordByKey(string $plugin, string $baseid): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT plugin, baseid, name, description FROM public.core_item_description_custom WHERE plugin = ? AND baseid = ? LIMIT 1', [$plugin, $baseid]);

        return $row === false ? null : $row;
    }

    /** The non-empty description of one item, or null. */
    public function descriptionByKey(string $plugin, string $baseid): ?string
    {
        $value = $this->connection()->fetchOne("SELECT description FROM public.core_item_description_custom WHERE plugin = ? AND baseid = ? AND description <> '' LIMIT 1", [$plugin, $baseid]);

        return is_string($value) ? $value : null;
    }
}
