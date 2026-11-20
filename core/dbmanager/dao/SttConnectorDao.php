<?php

// Reads core_stt_connector. Writes belong to the edit API, which owns the key-store binding rules and the reference checks; this DAO
// only reads, so it holds no lock and never flushes.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractSttConnector;


/** Finds rows of core_stt_connector. */
final class SttConnectorDao
{
    use UsesEntityManager;

    /** One row by id, or null. */
    public function find(int $id): ?AbstractSttConnector
    {
        return $this->fresh()->find(AbstractSttConnector::class, $id);
    }

    /** Every row, ordered by id. @return list<AbstractSttConnector> */
    public function findAll(): array
    {
        return array_values($this->fresh()->getRepository(AbstractSttConnector::class)->findBy([], ['id' => 'ASC']));
    }
}
