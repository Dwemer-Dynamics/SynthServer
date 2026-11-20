<?php

// Reads core_narrator_state. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractNarratorState;

/** Finds rows of core_narrator_state. */
final class NarratorStateDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $stateId): ?AbstractNarratorState
    {
        return $this->fresh()->find(AbstractNarratorState::class, $stateId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractNarratorState> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractNarratorState::class)->findBy([], ['stateId' => 'ASC'], max(1, $limit)));
    }
}
