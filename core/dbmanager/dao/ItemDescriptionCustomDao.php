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
}
