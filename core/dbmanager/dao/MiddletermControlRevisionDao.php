<?php

// Reads core_middleterm_control_revision. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractMiddletermControlRevision;

/** Finds rows of core_middleterm_control_revision. */
final class MiddletermControlRevisionDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $historyEventId): ?AbstractMiddletermControlRevision
    {
        return $this->fresh()->find(AbstractMiddletermControlRevision::class, $historyEventId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractMiddletermControlRevision> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractMiddletermControlRevision::class)->findBy([], ['historyEventId' => 'ASC'], max(1, $limit)));
    }
}
