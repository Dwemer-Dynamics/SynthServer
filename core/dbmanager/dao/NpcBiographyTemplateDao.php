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
