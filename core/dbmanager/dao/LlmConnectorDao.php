<?php

// Reads core_llm_connector. Writes belong to the edit API, which owns the key-store binding rules and the reference checks; this DAO
// only reads, so it holds no lock and never flushes.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractLlmConnector;


/** Finds rows of core_llm_connector. */
final class LlmConnectorDao
{
    use UsesEntityManager;

    /** One row by id, or null. */
    public function find(int $id): ?AbstractLlmConnector
    {
        return $this->entityManager->find(AbstractLlmConnector::class, $id);
    }

    /** Every row, ordered by id. @return list<AbstractLlmConnector> */
    public function findAll(): array
    {
        return array_values($this->entityManager->getRepository(AbstractLlmConnector::class)->findBy([], ['id' => 'ASC']));
    }
}
