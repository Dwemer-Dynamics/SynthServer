<?php

// Reads core_worldknowledge_audit. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractWorldknowledgeAudit;

/** Finds rows of core_worldknowledge_audit. */
final class WorldknowledgeAuditDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $auditId): ?AbstractWorldknowledgeAudit
    {
        return $this->fresh()->find(AbstractWorldknowledgeAudit::class, $auditId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractWorldknowledgeAudit> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractWorldknowledgeAudit::class)->findBy([], ['auditId' => 'ASC'], max(1, $limit)));
    }
}
