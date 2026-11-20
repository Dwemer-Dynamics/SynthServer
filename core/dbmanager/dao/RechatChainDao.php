<?php

// Reads core_rechat_chain. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRechatChain;

/** Finds rows of core_rechat_chain. */
final class RechatChainDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractRechatChain
    {
        return $this->fresh()->find(AbstractRechatChain::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRechatChain> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRechatChain::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }
}
