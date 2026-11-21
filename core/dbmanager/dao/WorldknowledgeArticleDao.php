<?php

// Reads core_worldknowledge_article. Writes stay with the statements that own them, so this DAO only reads and holds no lock.

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception as DbalException;
use Synth\Core\Entities\Base\AbstractWorldknowledgeArticle;

/** Finds rows of core_worldknowledge_article. */
class WorldknowledgeArticleDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $entryId): ?AbstractWorldknowledgeArticle
    {
        return $this->fresh()->find(AbstractWorldknowledgeArticle::class, $entryId);
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractWorldknowledgeArticle> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractWorldknowledgeArticle::class)->findBy([], ['entryId' => 'ASC'], max(1, $limit)));
    }

    /** The effective World Knowledge projection. Throws when the view is unreadable so the caller can fall back to the article table. @return list<array<string, mixed>> */
    public function effectiveCatalog(): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT entry_id, topic, aliases, canonical_topic, topic_desc, knowledge_class, topic_desc_basic,
                    knowledge_class_basic, tags, category, source_kind, catalog_id, catalog_version, is_active
               FROM synth.worldknowledge_effective
              ORDER BY canonical_topic"
        );
    }

    /** The article table read as custom rows, for installs whose projection view is missing. @return list<array<string, mixed>> */
    public function fallbackCatalog(): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT topic, ''::text AS aliases, lower(btrim(split_part(topic, ',', 1))) AS canonical_topic,
                    topic_desc, knowledge_class, topic_desc_basic, knowledge_class_basic,
                    tags, category, 'custom'::text AS source_kind, NULL::text AS catalog_id,
                    NULL::text AS catalog_version, TRUE AS is_active
               FROM public.core_worldknowledge_article
              ORDER BY lower(btrim(split_part(topic, ',', 1)))"
        );
    }

    /**
     * Effective rows whose topic or an alias normalizes to one of the signals. Throws when the view is unreadable.
     *
     * @param list<string> $signals
     * @return list<array<string, mixed>>
     */
    public function effectiveRowsForSignals(array $signals): array
    {
        $in = implode(',', array_fill(0, count($signals), '?'));

        return $this->connection()->fetchAllAssociative(
            "SELECT topic, aliases, canonical_topic, topic_desc, knowledge_class, topic_desc_basic,
                        knowledge_class_basic, category, source_kind, catalog_id, catalog_version
                   FROM synth.worldknowledge_effective
                  WHERE EXISTS (
                        SELECT 1
                          FROM regexp_split_to_table(concat_ws(',', topic, aliases), E'\\\\s*,\\\\s*') AS topic_alias
                         WHERE btrim(regexp_replace(replace(lower(topic_alias), '_', ' '), '[^a-z0-9]+', ' ', 'g'))
                               IN (".$in.")
                  )",
            array_values($signals)
        );
    }

    /**
     * The article-table form of effectiveRowsForSignals(), for installs whose projection view is missing.
     *
     * @param list<string> $signals
     * @return list<array<string, mixed>>
     */
    public function articleRowsForSignals(array $signals): array
    {
        $in = implode(',', array_fill(0, count($signals), '?'));

        return $this->connection()->fetchAllAssociative(
            "SELECT topic, ''::text AS aliases, topic_desc, knowledge_class, topic_desc_basic, knowledge_class_basic
                   FROM public.core_worldknowledge_article
                  WHERE EXISTS (
                        SELECT 1
                          FROM regexp_split_to_table(topic, E'\\\\s*,\\\\s*') AS topic_alias
                         WHERE btrim(regexp_replace(replace(lower(topic_alias), '_', ' '), '[^a-z0-9]+', ' ', 'g'))
                               IN (".$in.")
                  )",
            array_values($signals)
        );
    }

    /** Whether an active custom row is byte-equal to a legacy seed row. $row holds the trimmed seed fields. */
    public function customSeedRowExists(string $canonical, array $row): bool
    {
        [$predicate, $parameters] = $this->customSeedPredicate($canonical, $row);
        try {
            return $this->connection()->fetchAssociative('SELECT entry_id FROM public.core_worldknowledge_article'.$predicate.' LIMIT 1', $parameters) !== false;
        } catch (DbalException|DriverException $error) {
            return false;
        }
    }

    /** Deletes the rows customSeedRowExists() matches; false when the statement fails. */
    public function deleteCustomSeedRow(string $canonical, array $row): bool
    {
        [$predicate, $parameters] = $this->customSeedPredicate($canonical, $row);

        return $this->run('DELETE FROM public.core_worldknowledge_article'.$predicate, $parameters);
    }

    /** @return array{0: string, 1: list<string>} */
    private function customSeedPredicate(string $canonical, array $row): array
    {
        $predicate = " WHERE source_kind='custom' AND is_active AND canonical_topic=? AND topic=?"
            ." AND coalesce(aliases,'')=? AND coalesce(topic_desc,'')=? AND coalesce(knowledge_class,'')=?"
            ." AND coalesce(topic_desc_basic,'')=? AND coalesce(knowledge_class_basic,'')=?"
            ." AND coalesce(tags,'')=? AND coalesce(category,'')=?";

        return [$predicate, [$canonical, strval($row['topic'] ?? ''), strval($row['aliases'] ?? ''), strval($row['topic_desc'] ?? ''),
            strval($row['knowledge_class'] ?? ''), strval($row['topic_desc_basic'] ?? ''), strval($row['knowledge_class_basic'] ?? ''),
            strval($row['tags'] ?? ''), strval($row['category'] ?? '')]];
    }

    /** Deletes every factory article; false when the statement fails. */
    public function deleteFactoryRows(): bool
    {
        return $this->run("DELETE FROM public.core_worldknowledge_article WHERE source_kind='factory'", []);
    }

    /** Clears factory catalogue metadata from non-factory rows; false when the statement fails. */
    public function detachCustomRowsFromCatalog(): bool
    {
        return $this->run("UPDATE public.core_worldknowledge_article SET catalog_id=NULL, catalog_version=NULL WHERE source_kind<>'factory'"
            .' AND (catalog_id IS NOT NULL OR catalog_version IS NOT NULL)', []);
    }

    /**
     * Inserts one factory article with its weighted search vector. Empty topic_desc, knowledge_class and
     * knowledge_class_basic values are stored as NULL. False when the statement fails.
     *
     * @param array<string, string> $row topic, canonical_topic, aliases, topic_desc, knowledge_class, topic_desc_basic,
     *                                   knowledge_class_basic, tags, category, content_hash
     */
    public function insertFactoryRow(array $row, string $catalogId, string $catalogVersion): bool
    {
        $nullable = static fn(mixed $value): ?string => trim(strval($value)) === '' ? null : trim(strval($value));

        return $this->run(
            'INSERT INTO public.core_worldknowledge_article ('
            .'topic,canonical_topic,aliases,topic_desc,knowledge_class,topic_desc_basic,knowledge_class_basic,'
            .'tags,category,source_kind,catalog_id,catalog_version,content_hash,native_vector'
            .') VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'
            ."setweight(to_tsvector('simple',coalesce(CAST(? AS text),'')),'A')"
            ."||setweight(to_tsvector('simple',coalesce(CAST(? AS text),'')),'A')"
            ."||setweight(to_tsvector('simple',coalesce(CAST(? AS text),'')),'B')"
            ."||setweight(to_tsvector('simple',coalesce(CAST(? AS text),'')),'C'))",
            [$row['topic'], $row['canonical_topic'], $row['aliases'], $nullable($row['topic_desc']), $nullable($row['knowledge_class']),
                $row['topic_desc_basic'], $nullable($row['knowledge_class_basic']), $row['tags'], $row['category'], 'factory',
                $catalogId, $catalogVersion, $row['content_hash'],
                $row['topic'], $row['aliases'], $nullable($row['topic_desc']), $row['topic_desc_basic']]
        );
    }

    /** @param list<mixed> $parameters */
    private function run(string $sql, array $parameters): bool
    {
        try {
            $this->connection()->executeStatement($sql, $parameters);

            return true;
        } catch (DbalException|DriverException $error) {
            return false;
        }
    }
}
