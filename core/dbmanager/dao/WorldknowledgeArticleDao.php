<?php

// Reads and writes core_worldknowledge_article for the World Knowledge manager page (custom rows only; factory rows are owned by the catalog).

declare(strict_types=1);

namespace Synth\Core\DbManager\Dao;

use Synth\Core\Entities\Base\AbstractWorldknowledgeArticle;

/** Finds rows of core_worldknowledge_article. */
final class WorldknowledgeArticleDao
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
}
