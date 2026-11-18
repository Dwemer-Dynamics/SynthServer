<?php

declare(strict_types=1);

namespace Synth\Migrations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\AbortMigration;

// The frozen legacy bootstrap baseline: the schema data/database_default.sql plus debug/db_updates.php produce at
// 42c7e0e. On an existing install it only recognizes that baseline and changes no schema and no row. On an empty
// database it installs it: up() adds the statements of Version20261117000000.fresh.sql (generated data, see
// docs/SCHEMA-MIGRATIONS.md), so the complete schema and stock seeds are created in the owner's all-or-nothing
// transaction without the legacy chain or a separate data/database_default.sql import. Either way postUp() verifies
// inside that transaction, after the statements and before Doctrine records this version, so a failed, partial or
// drifted install aborts and records nothing. Later schema changes are new migrations here, never new
// debug/db_updates.php blocks.
//
// The structure check compares against Version20261117000000.structure.txt, generated from a disposable PostgreSQL 15
// inventory of that commit: every baseline table and view by name, every column (type, nullability, default,
// length), vector dimensions, and every primary key, foreign key, check and unique constraint and index by name and
// definition. Objects not in the baseline are allowed. View and function bodies are not compared; the frozen seed check
// only checks that the playthrough functions exist.
//
// Migrations in this directory use addSql() only and never read the Schema argument: building it would make DBAL
// introspect the whole database, and DBAL has no mapping for pgvector or tsvector columns.
final class Version20261117000000 extends AbstractMigration
{
    // The frozen contract, copied from the readiness gate and settings list at 42c7e0e and never read from them: the
    // runtime gate states what the current code needs and will grow with later migrations, so the baseline must not
    // call it. Minimum version per core_catalog_version entry (the legacy database_versioning rows).
    private const FROZEN_VERSIONS = [
        'fallout4_content_pack' => 20261008003, 'conf_opts' => 20260626001, 'core_action' => 20261106001,
        'core_player' => 20260707001, 'general_settings' => 20260722001, 'global_settings_presets' => 20260828001,
        'import_rules' => 20260730001, 'core_stt_connector' => 20260502002, 'core_itt_connector' => 20260731001,
        'visual_context' => 20260731001, 'pipvision_general_settings' => 20260731001,
        'itt_connector_defaults' => 20260731002, 'descriptions_defaults' => 20260811002, 'prompts' => 20260627001,
        'core_profiles' => 20260717001, 'moods_issued_sequence' => 20260626001,
        'core_tts_connector_metadata' => 20260626001, 'core_tts_connector_omnivoice' => 20260708001,
        'core_tts_connector_removed_drivers' => 20260712001, 'core_tts_pronunciation' => 20261008003,
        'tts_gender_fallback_defaults' => 20260715001, 'legacy_translation_tables_cleanup' => 20260628001,
        'legacy_currentmission_cleanup' => 20260713003, 'general_settings_seed_repair' => 20260713004,
        'legacy_quest_tables_cleanup' => 20260628001, 'memory_v' => 20260713001,
        'prompt_manager_defaults' => 20260713002, 'synthnpcs_view' => 20260713002, 'profile_defaults' => 20260713002,
        'playthrough_metadata_schema' => 20260730001, 'relationship_async_queues' => 20260713002,
        'fallout_bio_templates_seed' => 20260811002, 'fallout4_catalogue_sql' => 20261009002,
        'fallout_worldknowledge_seed' => 20260811002, 'worldknowledge_parity' => 20260813001,
        'worldknowledge_access' => 20260813001, 'worldknowledge_canonical_tags' => 20260813003,
        'worldknowledge_oghma_parity' => 20260814001, 'worldknowledge_herika_v1' => 20260814002,
        'worldknowledge_npc_common_cleanup' => 20260814004, 'worldknowledge_npc_class_cleanup' => 20260814005,
        'worldknowledge_catalog_integrity' => 20260814006, 'synth_protocol_schema' => 20261113002,
        'synth_relationship_prompt' => 20260811003, 'synth_fo4_prompt_examples' => 20260811004,
        'social_tasks_schema' => 20261102001, 'private_narrator_schema' => 20261113001,
    ];

    // The fresh-install statements: generated data, checked by SHA-256 over its LF bytes before any statement is used.
    private const FRESH_INSTALL_FILE = 'Version20261117000000.fresh.sql';
    private const FRESH_INSTALL_SHA256 = '539a2685abe9c76b86daca26021b02332227835fca6b90b71633753242a583c4';
    private const FRESH_INSTALL_STATEMENT_END = "\n-- end-statement\n";

    // Mandatory seeds: the five SYNTH prompt rows and the general settings the runtime managed at the baseline.
    private const FROZEN_PROMPT_KEYS = ['synth_system_prompt', 'synth_response_rules', 'synth_world_prompt',
        'synth_scene_prompt', 'synth_memory_prompt'];
    private const FROZEN_GENERAL_SETTINGS = [
        'AUTO_LOCK_PROFILE', 'AUTOFILL_CUSTOM_PROFILES', 'AUTOFILL_CUSTOM_PROFILES_TRIGGER',
        'CLEAN_CONTEXT_FOCUS_CHAT_HISTORY', 'FEATURES@MEMORY_EMBEDDING@ENABLED', 'FEATURES@MEMORY_EMBEDDING@USE_TEXT2VEC',
        'FEATURES@MEMORY_EMBEDDING@AUTO_CREATE_SUMMARY_INTERVAL', 'FEATURES@MEMORY_EMBEDDING@AUTO_CREATE_SUMMARYS', 'PROMPT_HEAD',
        'EMOTEMOODS', 'LOCATION_BLACKLIST', 'ITEM_BLACKLIST',
        'SHORTER_NEARBY_ITEM_LIST', 'EVENT_TYPE_FILTER', 'GROUND_ITEMS_DESCRIPTIONS_ONLY',
        'INVENTORY_ITEMS_DESCRIPTIONS_ONLY', 'HIDE_AMBIENT_COMBAT', 'PROMPT_TIMESTAMP',
        'COMPACT_NPC_CONTEXT_HISTORY', 'SHORT_TERM_MEMORY_IN_COMPACT_CHAT', 'PROMPT_HEAD_MARKDOWN_ENABLED',
        'PROMPT_CONTEXT_OPTIONS', 'RECHAT_MODE', 'ENFORCE_STRICT_RECHAT_RESPONSE',
        'CORE_CONNECTOR_PLAYER', 'CORE_CONNECTOR_SUMMARY', 'CORE_CONNECTOR_MEDIUMTERM',
        'CORE_CONNECTOR_SCENECLASSIFIER', 'JEV_ACTIONS_ENABLED', 'CORE_CONNECTOR_DECISION',
        'JEV_ACTION_TIMEOUT_MS', 'JEV_ACTION_MIN_CONFIDENCE', 'CORE_CONNECTOR_PROFILES',
        'CORE_CONNECTOR_DIRECTOR', 'RELLLM_CONNECTOR', 'NEVER_CLEAR_RELATIONSHIP_DATA',
        'CORE_CONNECTOR_WORLDKNOWLEDGE_CUSTOM', 'RELATIONSHIP_SYSTEM_ENABLED', 'SCENE_CLASSIFIER_ENABLED',
        'POWER_AWARENESS_ENABLED', 'WORLDKNOWLEDGE_CUSTOM', 'WORLDKNOWLEDGE_INFINIUM',
        'WORLDKNOWLEDGE_AMOUNT', 'WORLDKNOWLEDGE_RESULT_LIMIT', 'LOCATION_WORLDKNOWLEDGE',
        'RACE_WORLDKNOWLEDGE', 'FACTION_WORLDKNOWLEDGE', 'WORLDKNOWLEDGE_EXTRACTOR_FALLBACK',
        'WORLDKNOWLEDGE_EXTRACTOR_TIMEOUT_MS', 'GLOBAL_ITT_CONNECTOR_ID', 'VISUAL_CONTEXT_SCENE_TTL_MINUTES',
        'VISUAL_CONTEXT_PROMPT_MAX_CHARS', 'PIPVISION_IMAGE_QUALITY', 'PIPVISION_REQUEST_TIMEOUT_SECONDS',
    ];

    public function getDescription(): string
    {
        return 'Recognize the verified frozen debug/db_updates.php baseline';
    }

    // An empty database gets the frozen baseline, one statement at a time (DBAL's pgsql driver reads only the first
    // result of a multi-statement query, so a later failing statement would go unnoticed). Anything else is verified
    // as it stands; the migration owner has already run the legacy chain on a pre-ledger install that needed it.
    public function up(Schema $schema): void
    {
        if (!self::isEmptyDatabase($this->connection)) return;
        try {
            $statements = self::freshInstallStatements();
        } catch (\RuntimeException $error) {
            throw new AbortMigration($error->getMessage());
        }
        foreach ($statements as $statement) $this->addSql($statement);
    }

    // After up()'s statements and before Doctrine records the version, in the same transaction.
    public function postUp(Schema $schema): void
    {
        $problems = self::problems($this->connection);
        $this->abortIf($problems !== [], 'Legacy baseline verification failed: '.implode('; ', $problems));
    }

    // Reverting removes this record and leaves every object and row in place, whether the baseline was recognized or
    // installed; the next run then finds a non-empty database and verifies it again.
    public function down(Schema $schema): void
    {
    }

    /**
     * True when the database holds nothing a SYNTH install or anything else created: no schema besides public and
     * synth_meta, and no relation, function or standalone type in either that is not an extension member, apart from
     * the Doctrine version table (the owner creates synth_meta and that table first, in the same transaction). Only
     * such a database is installed fresh; every other one keeps the legacy path, so nothing existing is replaced.
     */
    public static function isEmptyDatabase(Connection $connection): bool
    {
        $state = $connection->fetchAssociative(<<<'SQL'
            WITH ledger AS (SELECT to_regclass('synth_meta.doctrine_migration_versions') AS oid),
            member AS (SELECT classid, objid FROM pg_depend WHERE deptype = 'e')
            SELECT (SELECT count(*) FROM pg_namespace n
                     WHERE n.nspname NOT IN ('public', 'synth_meta', 'information_schema') AND n.nspname NOT LIKE 'pg\_%') AS schemas,
                   (SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace, ledger
                     WHERE n.nspname IN ('public', 'synth_meta') AND c.oid IS DISTINCT FROM ledger.oid
                       AND NOT EXISTS (SELECT 1 FROM pg_index i WHERE i.indexrelid = c.oid AND i.indrelid = ledger.oid)
                       AND NOT EXISTS (SELECT 1 FROM member m WHERE m.classid = 'pg_class'::regclass AND m.objid = c.oid)) AS relations,
                   (SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                     WHERE n.nspname IN ('public', 'synth_meta')
                       AND NOT EXISTS (SELECT 1 FROM member m WHERE m.classid = 'pg_proc'::regclass AND m.objid = p.oid)) AS functions,
                   (SELECT count(*) FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
                     WHERE n.nspname IN ('public', 'synth_meta') AND t.typrelid = 0 AND t.typcategory <> 'A'
                       AND NOT EXISTS (SELECT 1 FROM member m WHERE m.classid = 'pg_type'::regclass AND m.objid = t.oid)) AS types
            SQL) ?: [];
        foreach (['schemas', 'relations', 'functions', 'types'] as $key) {
            if ((int) ($state[$key] ?? 1) !== 0) return false;
        }
        return true;
    }

    /**
     * The fresh-install statements, in order. Refused unless the file matches its recorded SHA-256 (after CRLF to LF,
     * which a checkout may apply; the generated file holds no CR) and ends with a statement end.
     *
     * @return list<string>
     */
    public static function freshInstallStatements(): array
    {
        $sql = @file_get_contents(__DIR__.'/'.self::FRESH_INSTALL_FILE);
        if ($sql === false) throw new \RuntimeException('The fresh-install baseline schema is unreadable');
        $sql = str_replace("\r\n", "\n", $sql);
        if (!hash_equals(self::FRESH_INSTALL_SHA256, hash('sha256', $sql))) {
            throw new \RuntimeException('The fresh-install baseline schema does not match its recorded checksum');
        }
        $statements = explode(self::FRESH_INSTALL_STATEMENT_END, $sql);
        if (array_pop($statements) !== '' || $statements === []) {
            throw new \RuntimeException('The fresh-install baseline schema does not end with a statement end');
        }
        return $statements;
    }

    /**
     * True when core_catalog_version already claims every frozen legacy version. Such an install gets no legacy chain:
     * it is verified as it stands, so damage is refused instead of being half-repaired by version-gated steps.
     */
    public static function legacyVersionsClaimCurrent(Connection $connection): bool
    {
        return self::relationExists($connection, 'public', 'core_catalog_version') && self::versionShortfalls($connection) === [];
    }

    /**
     * Every reason the database is not the frozen baseline; empty when it is. Only the frozen contract in this file
     * is checked, so a later migration's new runtime requirement cannot block the database it is about to migrate.
     *
     * @return list<string>
     */
    public static function problems(Connection $connection): array
    {
        $problems = [];
        try {
            $encoding = strtoupper(trim(strval($connection->fetchOne('SHOW server_encoding'))));
            if ($encoding !== 'UTF8') $problems[] = "database encoding is {$encoding}, not UTF8";

            $extensions = array_map('strval', $connection->fetchFirstColumn(
                "SELECT extname FROM pg_extension WHERE extname IN ('vector', 'pg_trgm')"));
            foreach (['vector', 'pg_trgm'] as $extension) {
                if (!in_array($extension, $extensions, true)) $problems[] = "extension {$extension} is missing";
            }

            $differences = self::structureDifferences($connection);
            if ($differences !== []) {
                $problems[] = count($differences).' baseline structure entries are missing or changed: '
                    .implode('; ', array_slice($differences, 0, 5)).(count($differences) > 5 ? '; ...' : '');
            } else {
                // Only on the verified structure, so a missing table is reported above instead of aborting the
                // migration transaction here.
                $problems = [...$problems, ...self::versionShortfalls($connection), ...self::seedProblems($connection)];
            }
        } catch (\Throwable $error) {
            $problems[] = 'the schema could not be inspected: '.$error->getMessage();
        }
        return $problems;
    }

    /** @return list<string> */
    private static function versionShortfalls(Connection $connection): array
    {
        $rows = $connection->fetchAllKeyValue('SELECT catalog_key, max(version) FROM public.core_catalog_version
            WHERE catalog_key IN (?) GROUP BY catalog_key', [array_keys(self::FROZEN_VERSIONS)], [ArrayParameterType::STRING]);
        $shortfalls = [];
        foreach (self::FROZEN_VERSIONS as $name => $floor) {
            if (!isset($rows[$name]) || (int) $rows[$name] < $floor) $shortfalls[] = "legacy version {$name} is below {$floor}";
        }
        return $shortfalls;
    }

    /** @return list<string> */
    private static function seedProblems(Connection $connection): array
    {
        $state = $connection->fetchAssociative(<<<'SQL'
            SELECT (SELECT count(DISTINCT prompt_key) FROM synth.prompts WHERE prompt_key IN (?)) AS prompts,
                   (SELECT count(*) FROM synth.core_profiles WHERE default_npc = '1') AS npc_defaults,
                   (SELECT count(*) FROM synth.core_profiles WHERE default_narrator = '1') AS narrator_defaults,
                   (SELECT count(DISTINCT id) FROM synth.general_settings WHERE id IN (?)) AS general_settings,
                   (SELECT count(DISTINCT p.proname) FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                     WHERE n.nspname = 'synth_meta' AND p.proname IN ('clone_schema', 'drop_schema_safe', 'get_schema_size'))
                       AS playthrough_functions,
                   CASE WHEN to_regclass('synth.currentmission') IS NULL THEN 0 ELSE 1 END AS currentmission
            SQL, [self::FROZEN_PROMPT_KEYS, self::FROZEN_GENERAL_SETTINGS],
            [ArrayParameterType::STRING, ArrayParameterType::STRING]) ?: [];
        $problems = [];
        foreach ([
            'prompts' => [count(self::FROZEN_PROMPT_KEYS), 'SYNTH prompt seeds'],
            'npc_defaults' => [1, 'default NPC profiles'],
            'narrator_defaults' => [1, 'default narrator profiles'],
            'general_settings' => [count(self::FROZEN_GENERAL_SETTINGS), 'managed general settings'],
            'playthrough_functions' => [3, 'playthrough functions'],
        ] as $key => [$expected, $label]) {
            $found = (int) ($state[$key] ?? -1);
            if ($found !== $expected) $problems[] = "{$label}: {$found} of {$expected} present";
        }
        if ((int) ($state['currentmission'] ?? 1) !== 0) $problems[] = 'the retired synth.currentmission table is present';
        return $problems;
    }

    /** @return list<string> baseline manifest entries absent from the database, sorted */
    private static function structureDifferences(Connection $connection): array
    {
        $manifest = file(__DIR__.'/Version20261117000000.structure.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($manifest === false) return ['the baseline structure manifest is unreadable'];
        // A CRLF checkout must not change the entries.
        $manifest = array_map(static fn (string $line): string => rtrim($line, "
"), $manifest);
        $required = array_values(array_filter($manifest, static fn (string $line): bool => $line !== '' && $line[0] !== '#'));
        // The entries are rendered exactly as the inventory queries rendered them, under the same search_path (names
        // in defaults and foreign keys print relative to it), so the comparison is plain string equality. The owner's
        // connection is its own session, so setting the path here affects nothing else.
        $connection->executeStatement('SET search_path TO synth, synth_meta, public');
        $present = array_flip(array_map('strval', $connection->fetchFirstColumn(<<<'SQL'
            SELECT concat_ws('|', 'table', table_schema, table_name, table_type)
              FROM information_schema.tables WHERE table_schema IN ('synth', 'synth_meta')
            UNION ALL
            SELECT concat_ws('|', 'column', table_schema, table_name, column_name, data_type, udt_name, is_nullable,
                             coalesce(column_default, ''), coalesce(character_maximum_length::text, ''))
              FROM information_schema.columns WHERE table_schema IN ('synth', 'synth_meta')
            UNION ALL
            SELECT concat_ws('|', 'constraint', n.nspname, t.relname, c.conname, c.contype,
                             CASE WHEN c.convalidated THEN 't' ELSE 'f' END, pg_get_constraintdef(c.oid))
              FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace
             WHERE n.nspname IN ('synth', 'synth_meta')
            UNION ALL
            SELECT concat_ws('|', 'index', schemaname, tablename, indexname, indexdef)
              FROM pg_indexes WHERE schemaname IN ('synth', 'synth_meta')
            UNION ALL
            SELECT concat_ws('|', 'typmod', n.nspname, c.relname, a.attname, format_type(a.atttypid, a.atttypmod))
              FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname IN ('synth', 'synth_meta') AND a.attnum > 0 AND NOT a.attisdropped
               AND a.atttypid = 'public.vector'::regtype
            SQL)));
        return array_values(array_filter($required, static fn (string $line): bool => !isset($present[$line])));
    }

    private static function relationExists(Connection $connection, string $schema, string $name): bool
    {
        return (int) $connection->fetchOne("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = ? AND c.relname = ? AND c.relkind IN ('r', 'p')", [$schema, $name]) === 1;
    }
}
