<?php

// Reads core_worldknowledge_audit (including the admin list's filtered count and page). Writes stay with the statements that own them, so this DAO only reads and holds no lock.

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

    /** Number of audit rows, optionally only the matched ones (grounded / fallback_succeeded) and/or one status. */
    public function countFiltered(bool $matchedOnly, string $status = ''): int
    {
        [$where, $params] = $this->where($matchedOnly, $status);

        return (int) $this->connection()->fetchOne('SELECT COUNT(*) AS total FROM public.core_worldknowledge_audit '.$where, $params);
    }

    /** One page of audit rows for the admin list, newest first. @return list<array<string, mixed>> */
    public function pageFiltered(int $limit, int $offset, bool $matchedOnly, string $status = ''): array
    {
        [$where, $params] = $this->where($matchedOnly, $status);

        return $this->connection()->fetchAllAssociative(
            'SELECT audit_id, created_at, algorithm_version, status, request_type, npc_name,
                    input_text, normalized_input, catalog_id, catalog_version, catalog_checksum,
                    grounded_matches, rejected_candidates, tag_decisions, context_tags, fallback,
                    forced_signals, access_decisions, selected_articles, settings, prompt_hash,
                    retrieval_elapsed_ms, elapsed_ms
             FROM public.core_worldknowledge_audit '.$where.'
             ORDER BY created_at DESC LIMIT '.max(0, $limit).' OFFSET '.max(0, $offset),
            $params
        );
    }

    /** @return array{0: string, 1: list<string>} */
    private function where(bool $matchedOnly, string $status): array
    {
        $clauses = [];
        $params = [];
        if ($matchedOnly) {
            $clauses[] = "status IN ('grounded', 'fallback_succeeded')";
        }
        if ($status !== '') {
            $clauses[] = 'status = ?';
            $params[] = $status;
        }

        return [$clauses === [] ? '' : 'WHERE '.implode(' AND ', $clauses), $params];
    }
}
