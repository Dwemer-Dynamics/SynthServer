<?php

// Reads core_item_description. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception as DbalException;
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

    /** Inserts a standard item description unless one exists for the plugin and base id. False when the statement fails. */
    public function insertIfMissing(string $plugin, string $baseid, string $name, string $description): bool
    {
        try {
            $this->connection()->executeStatement(
                'INSERT INTO public.core_item_description(plugin,baseid,name,description) VALUES (?,?,?,?) ON CONFLICT(plugin,baseid) DO NOTHING',
                [$plugin, $baseid, $name, $description]
            );

            return true;
        } catch (DbalException|DriverException $error) {
            return false;
        }
    }
}
