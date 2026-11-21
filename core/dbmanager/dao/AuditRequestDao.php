<?php

// Reads core_audit_request. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractAuditRequest;

/** Finds rows of core_audit_request. */
final class AuditRequestDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $rowid): ?AbstractAuditRequest
    {
        return $this->fresh()->find(AbstractAuditRequest::class, $rowid);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractAuditRequest> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractAuditRequest::class)->findBy([], ['rowid' => 'ASC'], max(1, $limit)));
    }
}
