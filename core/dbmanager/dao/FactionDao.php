<?php

// Reads core_faction. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractFaction;

/** Finds rows of core_faction. */
final class FactionDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $formid): ?AbstractFaction
    {
        return $this->fresh()->find(AbstractFaction::class, $formid);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractFaction> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractFaction::class)->findBy([], ['formid' => 'ASC'], max(1, $limit)));
    }
}
