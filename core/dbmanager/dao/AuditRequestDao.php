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

    /**
     * Successful and total requests in a period: 24h, 72h, 7d or lifetime (home dashboard).
     *
     * @return array<string, mixed>|null
     */
    public function dashboardOutcomeCounts(string $period): ?array
    {
        $conditions = [
            '24h' => "created_at >= NOW() - INTERVAL '24 HOURS'",
            '72h' => "created_at >= NOW() - INTERVAL '72 HOURS'",
            '7d' => "created_at >= NOW() - INTERVAL '7 DAYS'",
            'lifetime' => 'TRUE',
        ];
        if (!isset($conditions[$period])) {
            throw new \InvalidArgumentException('Unknown audit period.');
        }
        $row = $this->connection()->fetchAssociative("SELECT COUNT(*) FILTER (WHERE result='Ok') AS success, COUNT(*) AS total FROM core_audit_request WHERE {$conditions[$period]}");

        return $row === false ? null : $row;
    }

    /** Result, url and connector of the newest audited request, or null. @return array<string, mixed>|null */
    public function latest(): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT result, url, connector FROM core_audit_request ORDER BY id DESC LIMIT 1');

        return $row === false ? null : $row;
    }
}
