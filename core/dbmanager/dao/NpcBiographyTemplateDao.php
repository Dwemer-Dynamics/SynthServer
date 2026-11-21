<?php

// Reads core_npc_biography_template (and the combined_bio_templates view for the admin list). Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractNpcBiographyTemplate;

/** Finds rows of core_npc_biography_template. */
final class NpcBiographyTemplateDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $npcName): ?AbstractNpcBiographyTemplate
    {
        return $this->fresh()->find(AbstractNpcBiographyTemplate::class, $npcName);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractNpcBiographyTemplate> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractNpcBiographyTemplate::class)->findBy([], ['npcName' => 'ASC'], max(1, $limit)));
    }

    /**
     * Rows of the combined_bio_templates view for the admin list, by npc_name. $letter (name starts with) and $search (name contains)
     * are both optional. Throws on a database error. @return list<array<string, mixed>>
     */
    public function searchCombined(string $letter = '', string $search = ''): array
    {
        $clauses = [];
        $params = [];
        if ($letter !== '') {
            $clauses[] = 'LOWER(npc_name) LIKE LOWER(?)';
            $params[] = $letter.'%';
        }
        if ($search !== '') {
            $clauses[] = 'LOWER(npc_name) LIKE LOWER(?)';
            $params[] = '%'.$search.'%';
        }

        return $this->connection()->fetchAllAssociative(
            'SELECT * FROM synth.combined_bio_templates'.($clauses === [] ? '' : ' WHERE '.implode(' AND ', $clauses)).' ORDER BY npc_name ASC',
            $params
        );
    }
}
