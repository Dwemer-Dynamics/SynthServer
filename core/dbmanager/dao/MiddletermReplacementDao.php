<?php

// Reads core_middleterm_replacement. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMiddletermReplacement;

/** Finds rows of core_middleterm_replacement. */
final class MiddletermReplacementDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $historyEventId): ?AbstractMiddletermReplacement
    {
        return $this->fresh()->find(AbstractMiddletermReplacement::class, $historyEventId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMiddletermReplacement> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMiddletermReplacement::class)->findBy([], ['historyEventId' => 'ASC'], max(1, $limit)));
    }
}
