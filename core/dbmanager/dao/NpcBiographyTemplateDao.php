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

    /** Number of installed Fallout 4 character templates, creatures excluded (home dashboard). */
    public function dashboardFo4CharacterCount(): int
    {
        return (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM public.core_npc_biography_template WHERE strpos(npc_name, 'fo4_')=1 AND strpos(npc_name, 'fo4_creature_')<>1");
    }

    /** The template fields of the combined biography catalogue for this exact name (all matching rows). @return list<array<string, mixed>> */
    public function combinedFieldsByName(string $npcName): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT npc_static_bio, personality, appearance, relationships, occupation, skills, speechstyle, goals, worldknowledge_tags, voiceid, gender, race, refid
             FROM combined_bio_templates WHERE npc_name = ?',
            [$npcName]
        );
    }

    /** The core text rows of the combined biography catalogue for this exact name. @return list<array<string, mixed>> */
    public function combinedCoreByName(string $npcName): array
    {
        return $this->connection()->fetchAllAssociative('SELECT core FROM combined_bio_templates WHERE npc_name = ?', [$npcName]);
    }

    private const TEMPLATE_FIELDS = 'npc_name, core, npc_static_bio, worldknowledge_tags, personality, relationships, occupation, appearance, skills, speechstyle, goals, voiceid, gender, race, refid';

    /**
     * The combined template an actor mapping points to (matching the plugin and local form id of its reference or its base,
     * case-insensitively), with the mapping's is_nonverbal_creature flag; null when none.
     *
     * @return array<string, mixed>|null
     */
    public function mappedTemplate(string $plugin, string $localFormid, bool $reference): ?array
    {
        $pluginColumn = $reference ? 'reference_plugin' : 'base_plugin';
        $formidColumn = $reference ? 'reference_local_formid' : 'base_local_formid';
        $row = $this->connection()->fetchAssociative(
            "SELECT t.*, m.is_nonverbal_creature FROM public.core_npc_biography_template_actor m
             JOIN synth.combined_bio_templates t ON lower(t.npc_name) = lower(m.template_name)
             WHERE lower(m.{$pluginColumn}) = lower(?) AND upper(m.{$formidColumn}) = upper(?) ORDER BY m.id ASC LIMIT 1",
            [$plugin, $localFormid]
        );

        return $row === false ? null : $row;
    }

    /** The unmapped combined template whose normalized refid equals this one, or null. @return array<string, mixed>|null */
    public function unmappedByRefid(string $normalizedRefid): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT '.self::TEMPLATE_FIELDS.", FALSE AS is_nonverbal_creature FROM synth.combined_bio_templates
             WHERE regexp_replace(lower(COALESCE(refid, '')), '[^0-9a-fx]+', '', 'g') = ?
               AND NOT EXISTS (SELECT 1 FROM public.core_npc_biography_template_actor mapped WHERE lower(mapped.template_name) = lower(synth.combined_bio_templates.npc_name))
             LIMIT 1",
            [$normalizedRefid]
        );

        return $row === false ? null : $row;
    }

    /** The unmapped combined template named like the codename or the name (the codename match first), or null. @return array<string, mixed>|null */
    public function unmappedByCodenameOrName(string $codename, string $name): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT '.self::TEMPLATE_FIELDS.", FALSE AS is_nonverbal_creature FROM synth.combined_bio_templates
             WHERE (lower(npc_name) = lower(?) OR lower(npc_name) = lower(?))
               AND NOT EXISTS (SELECT 1 FROM public.core_npc_biography_template_actor mapped WHERE lower(mapped.template_name) = lower(synth.combined_bio_templates.npc_name))
             ORDER BY CASE WHEN lower(npc_name) = lower(?) THEN 0 ELSE 1 END LIMIT 1",
            [$codename, $name, $codename]
        );

        return $row === false ? null : $row;
    }

    /** The shortest unmapped combined template whose name starts with the codename and an underscore, or null. @return array<string, mixed>|null */
    public function unmappedByCodenamePrefix(string $codename): ?array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT t.*, FALSE AS is_nonverbal_creature FROM synth.combined_bio_templates t
             WHERE lower(t.npc_name) LIKE lower(?) || '\\_%'
               AND NOT EXISTS (SELECT 1 FROM public.core_npc_biography_template_actor m WHERE lower(m.template_name) = lower(t.npc_name))
             ORDER BY length(t.npc_name) ASC, t.npc_name ASC LIMIT 1",
            [$codename]
        );

        return $row === false ? null : $row;
    }
}
