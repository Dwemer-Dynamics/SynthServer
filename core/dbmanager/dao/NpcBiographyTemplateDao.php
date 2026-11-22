<?php

// Reads core_npc_biography_template (and the combined_bio_templates view for the admin list). Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractNpcBiographyTemplate;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception as DbalException;

/** Finds rows of core_npc_biography_template. */
class NpcBiographyTemplateDao
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

    /** The World Knowledge tags of the combined (standard plus custom) template of one NPC name; null when absent or unreadable. @return array<string, mixed>|null */
    public function worldknowledgeTagsByName(string $npcName): ?array
    {
        try {
            $row = $this->connection()->fetchAssociative(
                'SELECT worldknowledge_tags FROM synth.combined_bio_templates WHERE lower(npc_name)=lower(?) LIMIT 1',
                [$npcName]
            );
        } catch (DbalException|DriverException $error) {
            return null;
        }

        return $row === false ? null : $row;
    }

    /**
     * Inserts a standard template unless one with that name exists. The keys of $fields are column names from the
     * checked-in content pack and must be plain identifiers. False when the statement fails.
     *
     * @param array<string, mixed> $fields npc_name first, then the content columns
     */
    public function insertIfMissing(array $fields): bool
    {
        foreach (array_keys($fields) as $column) {
            if (!is_string($column) || preg_match('/^[a-z_][a-z0-9_]*$/', $column) !== 1) {
                return false;
            }
        }
        try {
            $this->connection()->executeStatement(
                'INSERT INTO public.core_npc_biography_template ('.implode(',', array_keys($fields)).') VALUES ('
                .implode(',', array_fill(0, count($fields), '?')).') ON CONFLICT(npc_name) DO NOTHING',
                array_values($fields)
            );

            return true;
        } catch (DbalException|DriverException $error) {
            return false;
        }
    }

    /**
     * Updates the World Knowledge tags of the named templates in one statement and returns how many rows changed;
     * null when the statement fails.
     *
     * @param list<array{0: string, 1: string}> $nameAndTags
     */
    public function updateWorldknowledgeTags(array $nameAndTags): ?int
    {
        if ($nameAndTags === []) {
            return 0;
        }
        $parameters = [];
        foreach ($nameAndTags as [$name, $tags]) {
            array_push($parameters, $name, $tags);
        }
        try {
            $row = $this->connection()->fetchAssociative(
                'WITH seed(npc_name,tags) AS (VALUES '.implode(',', array_fill(0, count($nameAndTags), '(CAST(? AS text),CAST(? AS text))')).'), updated AS ('
                .'UPDATE public.core_npc_biography_template AS template SET worldknowledge_tags=seed.tags FROM seed'
                .' WHERE template.npc_name=seed.npc_name RETURNING 1) SELECT count(*) AS updated FROM updated',
                $parameters
            );
        } catch (DbalException|DriverException $error) {
            return null;
        }

        return $row === false ? null : intval($row['updated'] ?? 0);
    }

    /**
     * Existing templates among the seed rows with their stored and seed tags and prior seed hash. Null when the read fails.
     *
     * @param list<array{npc_name: string, tags: string, prior_seed_sha256: string}> $seedRows
     * @return list<array<string, mixed>>|null
     */
    public function worldknowledgeTagsForSeed(array $seedRows): ?array
    {
        if ($seedRows === []) {
            return [];
        }
        $parameters = [];
        foreach ($seedRows as $row) {
            array_push($parameters, $row['npc_name'], $row['tags'], $row['prior_seed_sha256'] === '' ? null : $row['prior_seed_sha256']);
        }
        try {
            return $this->connection()->fetchAllAssociative(
                'WITH seed(npc_name,tags,prior_seed_sha256) AS (VALUES '
                .implode(',', array_fill(0, count($seedRows), '(CAST(? AS text),CAST(? AS text),CAST(? AS text))')).')'
                .' SELECT template.npc_name, template.worldknowledge_tags, seed.tags AS seed_tags, seed.prior_seed_sha256'
                .' FROM public.core_npc_biography_template AS template JOIN seed ON template.npc_name=seed.npc_name',
                $parameters
            );
        } catch (DbalException|DriverException $error) {
            return null;
        }
    }
}
