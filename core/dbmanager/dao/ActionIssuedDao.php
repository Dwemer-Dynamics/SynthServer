<?php

// Reads core_action_issued. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractActionIssued;

/** Finds rows of core_action_issued. */
final class ActionIssuedDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int $rowid): ?AbstractActionIssued
    {
        return $this->fresh()->find(AbstractActionIssued::class, $rowid);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractActionIssued> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractActionIssued::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
    }
}
