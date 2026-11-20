<?php

// Reads core_middleterm_input. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMiddletermInput;

/** Finds rows of core_middleterm_input. */
final class MiddletermInputDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractMiddletermInput
    {
        return $this->fresh()->find(AbstractMiddletermInput::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMiddletermInput> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMiddletermInput::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }
}
