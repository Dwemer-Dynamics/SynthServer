<?php

// Reads and writes core_npc_biography_template_custom: reads by key, and the upsert, truncate and export the NPC biography page uses.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractNpcBiographyTemplateCustom;

/** Finds rows of core_npc_biography_template_custom. */
final class NpcBiographyTemplateCustomDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(string $npcName): ?AbstractNpcBiographyTemplateCustom
    {
        return $this->fresh()->find(AbstractNpcBiographyTemplateCustom::class, $npcName);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractNpcBiographyTemplateCustom> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractNpcBiographyTemplateCustom::class)->findBy([], ['npcName' => 'ASC'], max(1, $limit)));
    }

    /**
     * Inserts or updates one custom biography template keyed by npc_name. $fields holds npc_name, core, worldknowledge_tags,
     * npc_static_bio, personality, appearance, relationships, occupation, skills, speechstyle, goals, voiceid, gender, race and refid.
     * Throws on a database error. @param array<string, mixed> $fields
     */
    public function upsert(array $fields): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO public.core_npc_biography_template_custom
             (npc_name, core, worldknowledge_tags, npc_static_bio, personality, appearance, relationships, occupation, skills, speechstyle, goals, voiceid, gender, race, refid)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (npc_name)
             DO UPDATE SET
              core = EXCLUDED.core, worldknowledge_tags = EXCLUDED.worldknowledge_tags, npc_static_bio = EXCLUDED.npc_static_bio,
              personality = EXCLUDED.personality, appearance = EXCLUDED.appearance, relationships = EXCLUDED.relationships,
              occupation = EXCLUDED.occupation, skills = EXCLUDED.skills, speechstyle = EXCLUDED.speechstyle, goals = EXCLUDED.goals,
              voiceid = EXCLUDED.voiceid, gender = EXCLUDED.gender, race = EXCLUDED.race, refid = EXCLUDED.refid',
            [
                $fields['npc_name'], $fields['core'], $fields['worldknowledge_tags'], $fields['npc_static_bio'], $fields['personality'],
                $fields['appearance'], $fields['relationships'], $fields['occupation'], $fields['skills'], $fields['speechstyle'],
                $fields['goals'], $fields['voiceid'], $fields['gender'], $fields['race'], $fields['refid'],
            ]
        );
    }

    /** Empties the custom templates table and resets its identity (TRUNCATE ... RESTART IDENTITY CASCADE). Throws on a database error. */
    public function truncate(): void
    {
        $this->connection()->executeStatement('TRUNCATE TABLE public.core_npc_biography_template_custom RESTART IDENTITY CASCADE');
    }

    /** Every custom template for the CSV export, ordered by npc_name. @return list<array<string, mixed>> */
    public function exportRows(): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT npc_name, core, worldknowledge_tags, npc_static_bio, personality, appearance, relationships, occupation, skills,
                    speechstyle, goals, voiceid, gender, race, refid
             FROM public.core_npc_biography_template_custom ORDER BY npc_name ASC'
        );
    }
}
