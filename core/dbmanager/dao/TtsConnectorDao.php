<?php

// Reads core_tts_connector. Writes belong to the edit API, which owns the key-store binding rules and the reference checks; this DAO
// only reads, so it holds no lock and never flushes.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractTtsConnector;


/** Finds rows of core_tts_connector. */
final class TtsConnectorDao
{
    use UsesEntityManager;

    /** One row by id, or null. */
    public function find(int $id): ?AbstractTtsConnector
    {
        return $this->fresh()->find(AbstractTtsConnector::class, $id);
    }

    /** Every row, ordered by id. @return list<AbstractTtsConnector> */
    public function findAll(): array
    {
        return array_values($this->fresh()->getRepository(AbstractTtsConnector::class)->findBy([], ['id' => 'ASC']));
    }
}
