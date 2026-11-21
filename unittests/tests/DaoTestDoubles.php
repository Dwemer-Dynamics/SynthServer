<?php

// Test doubles that let a test keep answering a DAO read from its own scripted fake `$db`. The double builds the legacy
// SQL text and asks the fake, so the fake's rows are what the DAO returns. Call reset() when the test is done.

use Synth\Core\DbManager\Dao\NpcBiographyTemplateDao;
use Synth\Core\DbManager\Dao\RequestExecutionDao;
use Synth\Core\DbManager\Dao\WorldknowledgeArticleDao;
use Synth\Core\DbManager\Dao\WorldknowledgeAuditDao;

final class DaoTestDoubles
{
    /** RequestExecutionDao::ownerState() answered by $fake->fetchOne() with the legacy owner query. */
    public static function ownerFrom(object $fake): void
    {
        RequestExecutionDao::inject(new class($fake) extends RequestExecutionDao {
            public function __construct(private object $fake) {}

            public function ownerState(string $sessionId, string $requestId, int $generation): ?array
            {
                $session = $this->fake->escapeLiteral($sessionId);
                $request = $this->fake->escapeLiteral($requestId);
                $row = $this->fake->fetchOne("SELECT e.state, e.error_code, r.state AS runtime_state, r.active_generation,
            r.active_turn_id, r.active_turn_sequence
        FROM public.core_request_execution e JOIN public.core_runtime_session r USING (session_id)
        WHERE e.session_id={$session} AND e.request_id={$request} AND e.generation={$generation}");

                return is_array($row) && $row !== [] ? $row : null;
            }
        });
    }

    /**
     * The World Knowledge reads and the audit write answered by a test's own fake `$db`: each double rebuilds the legacy
     * SQL text and asks the fake (fetchAll, fetchOne, escape, execQuery), so what the test scripts is what the DAO returns.
     */
    public static function worldKnowledgeFrom(object $fake): void
    {
        WorldknowledgeArticleDao::inject(new class($fake) extends WorldknowledgeArticleDao {
            public function __construct(private object $fake) {}

            public function effectiveCatalog(): array
            {
                return $this->fake->fetchAll("SELECT entry_id, topic, aliases, canonical_topic, topic_desc, knowledge_class, topic_desc_basic,
                    knowledge_class_basic, tags, category, source_kind, catalog_id, catalog_version, is_active
               FROM synth.worldknowledge_effective
              ORDER BY canonical_topic");
            }

            public function fallbackCatalog(): array
            {
                return $this->fake->fetchAll("SELECT topic, ''::text AS aliases, lower(btrim(split_part(topic, ',', 1))) AS canonical_topic,
                    topic_desc, knowledge_class, topic_desc_basic, knowledge_class_basic,
                    tags, category, 'custom'::text AS source_kind, NULL::text AS catalog_id,
                    NULL::text AS catalog_version, TRUE AS is_active
               FROM public.core_worldknowledge_article
              ORDER BY lower(btrim(split_part(topic, ',', 1)))");
            }

            public function effectiveRowsForSignals(array $signals): array
            {
                return $this->fake->fetchAll('SELECT topic, aliases, canonical_topic FROM synth.worldknowledge_effective WHERE signals IN ('.$this->quoted($signals).')');
            }

            public function articleRowsForSignals(array $signals): array
            {
                return $this->fake->fetchAll('SELECT topic FROM public.core_worldknowledge_article WHERE signals IN ('.$this->quoted($signals).')');
            }

            private function quoted(array $signals): string
            {
                return implode(',', array_map(fn($signal): string => "'".$this->fake->escape($signal)."'", $signals));
            }
        });
        NpcBiographyTemplateDao::inject(new class($fake) extends NpcBiographyTemplateDao {
            public function __construct(private object $fake) {}

            public function worldknowledgeTagsByName(string $npcName): ?array
            {
                $row = $this->fake->fetchOne("SELECT worldknowledge_tags FROM synth.combined_bio_templates"
                    ." WHERE lower(npc_name)=lower('".$this->fake->escape($npcName)."') LIMIT 1");

                return is_array($row) && $row !== [] ? $row : null;
            }
        });
        WorldknowledgeAuditDao::inject(new class($fake) extends WorldknowledgeAuditDao {
            public function __construct(private object $fake) {}

            public function record(array $values): bool
            {
                $literals = array_map(fn($value): string => $value === null ? 'NULL' : $this->fake->escapeLiteral(strval($value)), $values);

                return (bool) $this->fake->execQuery('INSERT INTO public.core_worldknowledge_audit ('.implode(',', array_keys($values))
                    .') VALUES ('.implode(',', $literals).')');
            }
        });
    }

    public static function reset(): void
    {
        RequestExecutionDao::inject(null);
        WorldknowledgeArticleDao::inject(null);
        NpcBiographyTemplateDao::inject(null);
        WorldknowledgeAuditDao::inject(null);
    }
}
