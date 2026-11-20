<?php

// Reads core_social_task_input. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractSocialTaskInput;

/** Finds rows of core_social_task_input. */
final class SocialTaskInputDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $taskId): ?AbstractSocialTaskInput
    {
        return $this->fresh()->find(AbstractSocialTaskInput::class, $taskId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractSocialTaskInput> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractSocialTaskInput::class)->findBy([], ['taskId' => 'ASC'], max(1, $limit)));
    }
}
