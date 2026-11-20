<?php

// Reads core_middleterm_source. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMiddletermSource;

/** Finds rows of core_middleterm_source. */
final class MiddletermSourceDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $inputId, int $position): ?AbstractMiddletermSource
    {
        return $this->fresh()->find(AbstractMiddletermSource::class, ['inputId' => $inputId, 'position' => $position]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMiddletermSource> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMiddletermSource::class)->findBy([], ['inputId' => 'ASC', 'position' => 'ASC'], max(1, $limit)));
    }
}
