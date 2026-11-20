<?php

// Reads core_visual_context. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractVisualContext;

/** Finds rows of core_visual_context. */
final class VisualContextDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $id): ?AbstractVisualContext
    {
        return $this->fresh()->find(AbstractVisualContext::class, $id);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractVisualContext> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractVisualContext::class)->findBy([], ['id' => 'ASC'], max(1, $limit)));
    }
}
