<?php

// Reads core_worldknowledge_audit. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception as DbalException;
use Synth\Core\Entities\Base\AbstractWorldknowledgeAudit;

/** Finds rows of core_worldknowledge_audit. */
class WorldknowledgeAuditDao
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

    /**
     * Appends one retrieval audit row. The JSON columns arrive already encoded. False when the statement fails.
     *
     * @param array<string, mixed> $values keyed by column name
     */
    public function record(array $values): bool
    {
        $columns = ['algorithm_version', 'status', 'request_type', 'npc_name', 'input_text', 'normalized_input', 'catalog_id',
            'catalog_version', 'grounded_matches', 'rejected_candidates', 'tag_decisions', 'context_tags', 'fallback',
            'forced_signals', 'access_decisions', 'selected_articles', 'settings', 'catalog_checksum', 'prompt_hash',
            'retrieval_elapsed_ms', 'elapsed_ms'];
        $json = ['grounded_matches', 'rejected_candidates', 'tag_decisions', 'context_tags', 'fallback', 'forced_signals',
            'access_decisions', 'selected_articles', 'settings'];
        $placeholders = array_map(static fn(string $column): string => in_array($column, $json, true) ? 'CAST(? AS jsonb)' : '?', $columns);
        try {
            $this->connection()->executeStatement(
                'INSERT INTO public.core_worldknowledge_audit ('.implode(',', $columns).') VALUES ('.implode(',', $placeholders).')',
                array_map(static fn(string $column): mixed => $values[$column] ?? null, $columns)
            );

            return true;
        } catch (DbalException|DriverException $error) {
            return false;
        }
    }
}
