<?php

// Reads core_social_task_event. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractSocialTaskEvent;

/** Finds rows of core_social_task_event. */
final class SocialTaskEventDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $historyEventId): ?AbstractSocialTaskEvent
    {
        return $this->fresh()->find(AbstractSocialTaskEvent::class, $historyEventId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractSocialTaskEvent> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractSocialTaskEvent::class)->findBy([], ['historyEventId' => 'ASC'], max(1, $limit)));
    }
}
