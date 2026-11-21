<?php

// Reads core_action_result. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractActionResult;

/** Finds rows of core_action_result. */
class ActionResultDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $actionId): ?AbstractActionResult
    {
        return $this->fresh()->find(AbstractActionResult::class, $actionId);
    }

    /** The stored result JSON of an action, locked FOR UPDATE until the caller's open transaction ends; null when there is none. */
    public function lockResult(string $actionId): ?string
    {
        $value = $this->connection()->fetchOne('SELECT result FROM public.core_action_result WHERE action_id = ? FOR UPDATE', [$actionId]);

        return $value === false || $value === null ? null : (string) $value;
    }

    /** Stores the terminal result of an action. False when the statement fails. */
    public function insertResult(string $actionId, string $idempotencyKey, string $status, string $resultJson): bool
    {
        return $this->tryStatement(
            'INSERT INTO public.core_action_result (action_id, idempotency_key, status, result) VALUES (?, ?, ?, CAST(? AS jsonb))',
            [$actionId, $idempotencyKey, $status, $resultJson]
        );
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractActionResult> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractActionResult::class)->findBy([], ['actionId' => 'ASC'], max(1, $limit)));
    }
}
