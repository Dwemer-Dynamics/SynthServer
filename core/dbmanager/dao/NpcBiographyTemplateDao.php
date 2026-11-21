<?php

// Reads core_npc_biography_template. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

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

    /** Number of installed Fallout 4 character templates, creatures excluded (home dashboard). */
    public function dashboardFo4CharacterCount(): int
    {
        return (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM public.core_npc_biography_template WHERE strpos(npc_name, 'fo4_')=1 AND strpos(npc_name, 'fo4_creature_')<>1");
    }
}
