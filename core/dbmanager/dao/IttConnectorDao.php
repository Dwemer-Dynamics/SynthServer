<?php

// Reads core_itt_connector. Writes belong to the edit API, which owns the key-store binding rules and the reference checks; this DAO
// only reads, so it holds no lock and never flushes.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractIttConnector;

require_once __DIR__.'/UsesEntityManager.php';
require_once dirname(__DIR__, 2).'/entities/base/AbstractIttConnector.php';

/** Finds rows of core_itt_connector. */
final class IttConnectorDao
{
    use UsesEntityManager;

    /** One row by id, or null. */
    public function find(int $id): ?AbstractIttConnector
    {
        return $this->entityManager->find(AbstractIttConnector::class, $id);
    }

    /** Every row, ordered by id. @return list<AbstractIttConnector> */
    public function findAll(): array
    {
        return array_values($this->entityManager->getRepository(AbstractIttConnector::class)->findBy([], ['id' => 'ASC']));
    }
}
