<?php

// Reads core_rechat_selection. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractRechatSelection;

/** Finds rows of core_rechat_selection. */
final class RechatSelectionDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $sessionId, string $requestId): ?AbstractRechatSelection
    {
        return $this->fresh()->find(AbstractRechatSelection::class, ['sessionId' => $sessionId, 'requestId' => $requestId]);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractRechatSelection> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractRechatSelection::class)->findBy([], ['sessionId' => 'ASC', 'requestId' => 'ASC'], max(1, $limit)));
    }
}
