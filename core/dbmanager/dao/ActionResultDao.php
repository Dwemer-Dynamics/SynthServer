<?php

// Reads core_action_result. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractActionResult;

/** Finds rows of core_action_result. */
final class ActionResultDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $actionId): ?AbstractActionResult
    {
        return $this->fresh()->find(AbstractActionResult::class, $actionId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractActionResult> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractActionResult::class)->findBy([], ['actionId' => 'ASC'], max(1, $limit)));
    }

    /** Whether this JSON text has no duplicate object keys (the database's own check). */
    public function detailHasUniqueKeys(string $json): bool
    {
        return in_array($this->connection()->fetchOne('SELECT synth_meta.json_object_has_unique_keys(?)', [$json]), [true, 't', 1, '1'], true);
    }
}
