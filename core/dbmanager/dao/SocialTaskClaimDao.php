<?php

// Reads core_social_task_claim. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractSocialTaskClaim;

/** Finds rows of core_social_task_claim. */
final class SocialTaskClaimDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $taskId, string $sessionId, int|string $generation): ?AbstractSocialTaskClaim
    {
        return $this->fresh()->find(AbstractSocialTaskClaim::class, ['taskId' => $taskId, 'sessionId' => $sessionId, 'generation' => $generation]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractSocialTaskClaim> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractSocialTaskClaim::class)->findBy([], ['taskId' => 'ASC', 'sessionId' => 'ASC', 'generation' => 'ASC'], max(1, $limit)));
    }
}
