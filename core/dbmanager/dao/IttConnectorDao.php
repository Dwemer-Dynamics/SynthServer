<?php

// Reads core_itt_connector. Writes belong to the edit API, which owns the key-store binding rules and the reference checks; this DAO
// only reads, so it holds no lock and never flushes.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractIttConnector;


/** Finds rows of core_itt_connector. */
final class IttConnectorDao
{
    use UsesEntityManager;

    /** One row by id, or null. */
    public function find(int $id): ?AbstractIttConnector
    {
        return $this->fresh()->find(AbstractIttConnector::class, $id);
    }

    /** Every row, ordered by id. @return list<AbstractIttConnector> */
    public function findAll(): array
    {
        return array_values($this->fresh()->getRepository(AbstractIttConnector::class)->findBy([], ['id' => 'ASC']));
    }

    /** Id and option_label (the label, else the driver, else the id) of every connector, for a settings select list. @return list<array<string, mixed>> */
    public function settingOptions(): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT id, COALESCE(NULLIF(label, ''), NULLIF(driver, ''), CAST(id AS text)) AS option_label FROM public.core_itt_connector ORDER BY id ASC"
        );
    }
}
