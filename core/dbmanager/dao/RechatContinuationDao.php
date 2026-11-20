<?php

// Reads core_rechat_continuation. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRechatContinuation;

/** Finds rows of core_rechat_continuation. */
final class RechatContinuationDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $sessionId, string $requestId): ?AbstractRechatContinuation
    {
        return $this->fresh()->find(AbstractRechatContinuation::class, ['sessionId' => $sessionId, 'requestId' => $requestId]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRechatContinuation> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRechatContinuation::class)->findBy([], ['sessionId' => 'ASC', 'requestId' => 'ASC'], max(1, $limit)));
    }
}
