<?php

// Reads core_config_preset. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractConfigPreset;

/** Finds rows of core_config_preset. */
final class ConfigPresetDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int $id): ?AbstractConfigPreset
    {
        return $this->fresh()->find(AbstractConfigPreset::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractConfigPreset> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractConfigPreset::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }
}
