<?php

// Reads and writes core_worldknowledge_article for the World Knowledge manager page (custom rows only; factory rows are owned by the catalog).

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractWorldknowledgeArticle;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception as DbalException;

/** Finds rows of core_worldknowledge_article. */
class WorldknowledgeArticleDao
{
    use UsesEntityManager;

    /** One row by its primary key, or null. */
    public function find(int|string $entryId): ?AbstractWorldknowledgeArticle
    {
        return $this->fresh()->find(AbstractWorldknowledgeArticle::class, $entryId);
    }

    /** Stores an embedding (a '[1,2,...]' vector text) on the article whose first topic part matches (any case). */
    public function setVectorByTopic(string $canonicalTopic, string $vectorText): void
    {
        $this->connection()->executeStatement(
            "update core_worldknowledge_article set vector384=CAST(? AS public.vector) where lower(split_part(topic, ',', 1))=lower(?)",
            [$vectorText, $canonicalTopic]
        );
    }

    /**
     * The five articles closest to four query embeddings (topic fields and the five distance columns). Each vector is a '[1,2,...]'
     * text, in the order of the weighting the caller expects.
     *
     * @param list<string> $vectors four vector texts
     * @return list<array<string, mixed>>
     */
    public function nearestToVectors(array $vectors): array
    {
        [$v1, $v2, $v3, $v4] = array_values($vectors);

        return $this->connection()->fetchAllAssociative(
            "SELECT  topic_desc,
                                topic,
                                knowledge_class,
                                knowledge_class_basic,
                                topic_desc_basic,
                    vector384 <-> CAST(:v1 AS public.vector) as distance1,
                    vector384 <-> CAST(:v2 AS public.vector) as distance2,
                    vector384 <-> CAST(:v3 AS public.vector) as distance3,
                    vector384 <-> CAST(:v4 AS public.vector) as distance4,
                    ((vector384 <-> CAST(:v1 AS public.vector)) + (vector384 <-> CAST(:v2 AS public.vector))/4 + (vector384 <-> CAST(:v3 AS public.vector))/2 + (vector384 <-> CAST(:v4 AS public.vector))/2 )/2 as distance
                FROM public.core_worldknowledge_article
                WHERE vector384 IS NOT NULL
                ORDER BY ((vector384 <-> CAST(:v1 AS public.vector)) + (vector384 <-> CAST(:v2 AS public.vector))/4 + (vector384 <-> CAST(:v3 AS public.vector))/2 + (vector384 <-> CAST(:v4 AS public.vector))/2 )/4 ASC
                LIMIT 5 OFFSET 0",
            ['v1' => $v1, 'v2' => $v2, 'v3' => $v3, 'v4' => $v4]
        );
    }

    /** Rows ordered by primary key, at most $limit. @return list<AbstractWorldknowledgeArticle> */
    public function findAll(int $limit = 1000): array
    {
        return array_values($this->fresh()->getRepository(AbstractWorldknowledgeArticle::class)->findBy([], ['entryId' => 'ASC'], max(1, $limit)));
    }

    /** The topic and aliases of every effective article (custom overrides replace factory rows), aliases never null. @return list<array<string, mixed>> */
    public function effectiveAliasRows(): array
    {
        return $this->connection()->fetchAllAssociative("SELECT topic, coalesce(aliases, '') AS aliases FROM synth.worldknowledge_effective");
    }

    /** topic, canonical_topic and source_kind of the effective article with that canonical topic, or null. @return array<string, mixed>|null */
    public function effectiveOrigin(string $canonicalTopic): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT topic, canonical_topic, source_kind FROM synth.worldknowledge_effective WHERE canonical_topic = ? LIMIT 1',
            [$canonicalTopic]
        );

        return $row === false ? null : $row;
    }

    /** The distinct non-empty categories of the effective articles, sorted. @return list<string> */
    public function effectiveCategories(): array
    {
        $rows = $this->connection()->fetchFirstColumn("SELECT DISTINCT category FROM synth.worldknowledge_effective WHERE category IS NOT NULL AND category <> '' ORDER BY category");

        return array_map('strval', $rows);
    }

    /**
     * The effective articles for the admin list, filtered by category, topic first letter and a search over topic, aliases and tags,
     * ordered by topic ($descending flips it). Throws on a database error. @return list<array<string, mixed>>
     */
    public function searchEffective(string $category, string $letter, string $search, bool $descending): array
    {
        $where = [];
        $params = [];
        if ($category !== '') {
            $params[] = $category;
            $where[] = 'category = ?';
        }
        if ($letter !== '') {
            $params[] = $letter.'%';
            $where[] = 'topic ILIKE ?';
        }
        if ($search !== '') {
            $like = '%'.$search.'%';
            array_push($params, $like, $like, $like);
            $where[] = "(topic ILIKE ? OR coalesce(aliases, '') ILIKE ? OR coalesce(tags, '') ILIKE ?)";
        }

        return $this->connection()->fetchAllAssociative(
            'SELECT topic, aliases, topic_desc, knowledge_class, topic_desc_basic, knowledge_class_basic, tags, category, source_kind
               FROM synth.worldknowledge_effective'.($where === [] ? '' : ' WHERE '.implode(' AND ', $where)).' ORDER BY topic '.($descending ? 'DESC' : 'ASC'),
            $params
        );
    }

    /**
     * Inserts or updates the active custom article with that canonical topic. $f holds topic, aliases, topic_desc, knowledge_class,
     * topic_desc_basic, knowledge_class_basic, tags, category and canonical_topic. Throws on a database error. @param array<string, mixed> $f
     */
    public function upsertCustom(array $f): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO public.core_worldknowledge_article (
                topic, aliases, topic_desc, knowledge_class, topic_desc_basic, knowledge_class_basic, tags, category, canonical_topic, source_kind, is_active
             )
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'custom', TRUE)
             ON CONFLICT (canonical_topic) WHERE source_kind='custom' AND is_active
             DO UPDATE SET
                topic = EXCLUDED.topic, aliases = EXCLUDED.aliases, topic_desc = EXCLUDED.topic_desc,
                knowledge_class = EXCLUDED.knowledge_class, topic_desc_basic = EXCLUDED.topic_desc_basic,
                knowledge_class_basic = EXCLUDED.knowledge_class_basic, tags = EXCLUDED.tags, category = EXCLUDED.category,
                updated_at = CURRENT_TIMESTAMP",
            [$f['topic'], $f['aliases'], $f['topic_desc'], $f['knowledge_class'], $f['topic_desc_basic'], $f['knowledge_class_basic'], $f['tags'], $f['category'], $f['canonical_topic']]
        );
    }

    /**
     * Rewrites the custom article stored under $originalCanonicalTopic with $f (same keys as upsertCustom), including its canonical topic.
     * Returns the number of rows changed. Throws on a database error. @param array<string, mixed> $f
     */
    public function updateCustom(string $originalCanonicalTopic, array $f): int
    {
        return (int) $this->connection()->executeStatement(
            "UPDATE public.core_worldknowledge_article
                SET topic = ?, aliases = ?, topic_desc = ?, knowledge_class = ?, topic_desc_basic = ?, knowledge_class_basic = ?, tags = ?,
                    category = ?, canonical_topic = ?, updated_at = CURRENT_TIMESTAMP
              WHERE canonical_topic = ? AND source_kind = 'custom'",
            [$f['topic'], $f['aliases'], $f['topic_desc'], $f['knowledge_class'], $f['topic_desc_basic'], $f['knowledge_class_basic'], $f['tags'], $f['category'], $f['canonical_topic'], $originalCanonicalTopic]
        );
    }

    /** Recomputes the search vector of the custom article with that canonical topic. Throws on a database error. */
    public function refreshCustomVector(string $canonicalTopic): void
    {
        $this->connection()->executeStatement(
            "UPDATE public.core_worldknowledge_article
                SET native_vector =
                      setweight(to_tsvector(coalesce(topic, '')), 'A')
                    || setweight(to_tsvector(coalesce(aliases, '')), 'A')
                    || setweight(to_tsvector(coalesce(topic_desc, '')), 'B')
                    || setweight(to_tsvector(coalesce(topic_desc_basic, '')), 'C')
              WHERE canonical_topic = ? AND source_kind = 'custom'",
            [$canonicalTopic]
        );
    }

    /** The active custom articles for the CSV export (eight importer columns, ordered by canonical topic). Throws on a database error. @return list<array<string, mixed>> */
    public function exportCustom(): array
    {
        return $this->connection()->fetchAllAssociative(
            "SELECT topic, aliases, topic_desc, knowledge_class, topic_desc_basic, knowledge_class_basic, tags, category
               FROM public.core_worldknowledge_article WHERE source_kind = 'custom' AND is_active ORDER BY canonical_topic"
        );
    }

    /** Deletes every custom article; factory rows stay. Throws on a database error. */
    public function deleteAllCustom(): void
    {
        $this->connection()->executeStatement("DELETE FROM public.core_worldknowledge_article WHERE source_kind = 'custom'");
    }

    /** Deletes the custom article(s) with that topic; factory rows stay. Throws on a database error. */
    public function deleteCustomByTopic(string $topic): void
    {
        $this->connection()->executeStatement("DELETE FROM public.core_worldknowledge_article WHERE topic = ? AND source_kind = 'custom'", [$topic]);
    }

    /** Topic and description of every article that has no 384-dimension vector yet. @return list<array<string, mixed>> */
    public function withoutVector384(): array
    {
        return $this->connection()->fetchAllAssociative('SELECT topic, topic_desc FROM core_worldknowledge_article WHERE vector384 IS NULL');
    }

    /** Number of active factory articles in active catalogs (home dashboard). */
    public function dashboardFactoryCount(): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM public.core_worldknowledge_article AS article JOIN public.core_worldknowledge_catalog AS catalog'
            .' ON article.catalog_id=catalog.catalog_id AND article.catalog_version=catalog.catalog_version'
            ." WHERE article.source_kind='factory' AND article.is_active AND catalog.is_active"
        );
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
