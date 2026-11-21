<?php

// Reads core_worker_job. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractWorkerJob;

/** Finds rows of core_worker_job. */
final class WorkerJobDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractWorkerJob
    {
        return $this->fresh()->find(AbstractWorkerJob::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractWorkerJob> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractWorkerJob::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }
}
