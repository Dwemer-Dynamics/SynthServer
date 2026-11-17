<?php declare(strict_types=1);
require_once __DIR__.'/DatabaseTestCase.php';

// The schema migration owner through the real tools/bootstrap-database.php and the debug/db_updates.php compatibility
// entry, each run in a child process against the disposable testdb so no per-process cache hides a step: an empty
// database is installed by the baseline migration in one transaction and matches the legacy fresh install (default SQL,
// then the chain), a failed or killed install leaves it empty, the legacy fresh install and recognized legacy upgrade
// converge, reruns change nothing, drifted or partial schemas are never baselined or repaired by the legacy chain, unknown records stop everything first, the baseline reverts without touching the
// legacy schema, and concurrent, direct, transactional or killed runs stay consistent.
final class SchemaMigrationOwnerTest extends DatabaseTestCase
{
    private const BASELINE = 'Synth\\Migrations\\Version20261115000000';
    // The first migration after the baseline: the shared API key binding column (docs/SCHEMA-MIGRATIONS.md, "Shared API keys").
    private const SHIPPED = 'Synth\\Migrations\\Version20261116100000';
    private const LATER = 'Synth\\Migrations\\Version29990101000001';
    private const LOCK = "SELECT pg_advisory_lock(hashtextextended('synth-schema-migrations', 0))";

    private ?\PgSql\Connection $pg = null;

    // The shared fixture runs the legacy chain inside the PHPUnit process, where per-process caches left by earlier
    // classes (synth_ensure_synthnpcs_view's static flag) skip steps. These tests compare the fixture with what a
    // fresh bootstrap process produces, so this class builds it the same way: the chain in a fresh child process.
    public static function setUpBeforeClass(): void
    {
        self::recreateTestDatabaseFromDefaultSql();
        $code = <<<'PHP'
            $root = $argv[1];
            require_once $root.'/lib/logger.php'; require_once $root.'/lib/runtime_bootstrap.php';
            require_once $root.'/lib/phpunit.class.php';
            require $root.'/conf/conf.sample.php'; synthRuntimeImportConfigVariables(get_defined_vars());
            require_once $root.'/lib/db_connection_settings.php'; require_once $root.'/core/dbmanager/SchemaMigrations.php';
            $GLOBALS['db'] = $db = new sql();
            $owner = \Synth\Core\Doctrine\SchemaMigrations::connect(
                \Synth\Core\Doctrine\SchemaMigrations::settingsForLegacySession($db, synthDbConnectionSettings('testdb')));
            try { $owner->applyLegacyChainWithoutLedger(); } finally { $owner->close(); }
            $db->close();
            PHP;
        $process = proc_open([PHP_BINARY, '-r', $code, '--', dirname(__DIR__, 2)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);
        self::assertSame(0, $exit, "The legacy fixture chain failed: {$output}");
        self::assertStringNotContainsString('[error]', $output, 'The legacy fixture chain logged an error.');
        self::loadTestRuntime();
        self::snapshotTestDatabaseAsFixture();

        // The reference for the fresh path: this legacy fresh install after one real bootstrap (which also loads the
        // narrator settings, seeding their missing keys), before setUp() adjusts test rows. setUp() replaces this copy.
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2).'/tools/bootstrap-database.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null,
            array_merge(getenv(), ['SYNTH_DB_HOST' => 'localhost', 'SYNTH_DB_PORT' => '5432', 'SYNTH_DB_NAME' => 'testdb',
                'SYNTH_DB_USER' => 'dwemer', 'SYNTH_DB_PASSWORD' => 'dwemer']));
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        self::assertSame(0, proc_close($process), "The legacy fixture bootstrap failed: {$output}");
        $pg = pg_connect('host=localhost dbname=testdb user=dwemer password=dwemer', PGSQL_CONNECT_FORCE_NEW);
        self::$legacyInstall = self::installFingerprint($pg);
        self::$legacyWriters = count(self::installWriters($pg, false));
        pg_close($pg);
    }

    private static array $legacyInstall = [];
    private static int $legacyWriters = 0;

    public function tearDown(): void
    {
        if ($this->pg !== null) pg_close($this->pg);
        $this->pg = null;
        parent::tearDown();
    }

    private function pg(): \PgSql\Connection
    {
        return $this->pg ??= pg_connect('host=localhost dbname=testdb user=dwemer password=dwemer', PGSQL_CONNECT_FORCE_NEW);
    }

    private function rows(string $sql, array $params = []): array
    {
        return self::rowsOn($this->pg(), $sql, $params);
    }

    private static function rowsOn(\PgSql\Connection $pg, string $sql, array $params = []): array
    {
        $result = pg_query_params($pg, $sql, $params);
        self::assertNotFalse($result, pg_last_error($pg));
        return pg_fetch_all($result) ?: [];
    }

    private function value(string $sql, array $params = []): ?string
    {
        $rows = $this->rows($sql, $params);
        return $rows === [] ? null : array_values($rows[0])[0];
    }

    private function recorded(): array
    {
        if ($this->value("SELECT to_regclass('synth_meta.doctrine_migration_versions') IS NOT NULL") !== 't') return [];
        return array_column($this->rows('SELECT version, executed_at FROM synth_meta.doctrine_migration_versions ORDER BY version'),
            'executed_at', 'version');
    }

    // Drop every test-owned connection before DROP DATABASE ... WITH (FORCE) would terminate it.
    private function recreateFreshDatabase(): void
    {
        if ($this->pg !== null) pg_close($this->pg);
        $this->pg = null;
        self::recreateTestDatabaseFromDefaultSql();
    }

    /** @return array{0:resource,1:string,2:string} */
    private function startBootstrap(): array
    {
        return $this->start([PHP_BINARY, dirname(__DIR__, 2).'/tools/bootstrap-database.php']);
    }

    // A legacy caller that requires debug/db_updates.php directly, as the UI pages do, after $before on its session.
    private function startDirectUpdates(string $before = ''): array
    {
        $code = '$root='.var_export(dirname(__DIR__, 2), true).';'
            .'require_once $root."/lib/runtime_bootstrap.php"; require_once $root."/lib/phpunit.class.php";'
            .'require $root."/conf/conf.sample.php"; synthRuntimeImportConfigVariables(get_defined_vars());'
            .'$GLOBALS["db"] = $db = new sql();'.$before
            .'try { require $root."/debug/db_updates.php"; } catch (\Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(3); }'
            .'echo "direct entry finished\n";';
        return $this->start([PHP_BINARY, '-r', $code]);
    }

    // A later PR's runtime in a child process: its readiness gate requires a table only its own pending migration
    // creates, and that migration (declared from a scratch file, never shipped) reaches the owner through the test
    // seam. $fail breaks the migration after its DDL has run.
    private function startLaterMigration(bool $fail): array
    {
        $file = tempnam(sys_get_temp_dir(), 'synth-later-migration');
        $break = $fail ? "\$this->addSql('INSERT INTO synth_meta.schema_owner_later VALUES (1), (1)');" : '';
        file_put_contents($file, <<<PHP
            <?php
            namespace Synth\Migrations;
            final class Version29990101000001 extends \Doctrine\Migrations\AbstractMigration
            {
                public function up(\Doctrine\DBAL\Schema\Schema \$schema): void
                {
                    \$this->addSql('CREATE TABLE synth_meta.schema_owner_later (id integer PRIMARY KEY)');
                    {$break}
                }
                public function down(\Doctrine\DBAL\Schema\Schema \$schema): void
                {
                    \$this->addSql('DROP TABLE synth_meta.schema_owner_later');
                }
            }
            PHP);
        $code = <<<'PHP'
            function synthRuntimeNeedsDbUpdates(): bool
            {
                return $GLOBALS['db']->fetchOne("SELECT CASE WHEN to_regclass('synth_meta.schema_owner_later') IS NULL
                    THEN 1 ELSE 0 END AS unmet")['unmet'] === '1';
            }
            [$root, $file, $later] = array_slice($argv, 1);
            require_once $root.'/lib/runtime_bootstrap.php'; require_once $root.'/lib/phpunit.class.php';
            require $root.'/conf/conf.sample.php'; synthRuntimeImportConfigVariables(get_defined_vars());
            require_once $root.'/lib/db_connection_settings.php'; require_once $root.'/core/dbmanager/SchemaMigrations.php';
            $GLOBALS['db'] = $db = new sql();
            echo 'gate unmet before: '.(synthRuntimeNeedsDbUpdates() ? 'yes' : 'no')."\n";
            try {
                $owner = \Synth\Core\Doctrine\SchemaMigrations::connect(
                    \Synth\Core\Doctrine\SchemaMigrations::settingsForLegacySession($db, synthDbConnectionSettings('synth')), 1800, [$later]);
                require $file;
                try { $owner->migrate(); } finally { $owner->close(); }
            } catch (\Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(3); }
            echo 'gate unmet after: '.(synthRuntimeNeedsDbUpdates() ? 'yes' : 'no')."\n";
            PHP;
        $run = $this->start([PHP_BINARY, '-r', $code, '--', dirname(__DIR__, 2), $file, self::LATER]);
        $run[] = $file;
        return $run;
    }

    /** @return array{0:resource,1:string,2:string} */
    private function start(array $command): array
    {
        $out = tempnam(sys_get_temp_dir(), 'synth-boot-out');
        $err = tempnam(sys_get_temp_dir(), 'synth-boot-err');
        $env = array_merge(getenv(), ['SYNTH_DB_HOST' => 'localhost', 'SYNTH_DB_PORT' => '5432',
            'SYNTH_DB_NAME' => 'testdb', 'SYNTH_DB_USER' => 'dwemer', 'SYNTH_DB_PASSWORD' => 'dwemer']);
        $process = proc_open($command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, null, $env);
        self::assertIsResource($process);
        return [$process, $out, $err];
    }

    /** @return array{exit:int,output:string} */
    private function finish(array $run, int $timeoutSeconds = 900): array
    {
        [$process, $out, $err] = $run;
        $scratch = $run[3] ?? null;
        $deadline = time() + $timeoutSeconds;
        while (($status = proc_get_status($process))['running']) {
            if (time() > $deadline) { proc_terminate($process, 9); self::fail('bootstrap did not finish'); }
            usleep(100000);
        }
        proc_close($process);
        $output = file_get_contents($out).file_get_contents($err);
        unlink($out); unlink($err);
        if ($scratch !== null) unlink($scratch);
        return ['exit' => $status['exitcode'], 'output' => $output];
    }

    private function bootstrap(): array
    {
        return $this->finish($this->startBootstrap());
    }

    private function assertBootstrapSucceeds(): void
    {
        $result = $this->bootstrap();
        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('schema migrations recorded: 2 (latest '.self::SHIPPED.')', $result['output']);
        self::assertSame([self::BASELINE, self::SHIPPED], array_keys($this->recorded()));
    }

    // Structure of both application schemas apart from the Doctrine version table: relations, columns with types,
    // nullability and defaults, constraints, indexes, functions and extensions.
    private function schemaFingerprint(): string
    {
        return (string) $this->value(<<<'SQL'
            SELECT md5(string_agg(line, E'\n' ORDER BY line)) FROM (
              SELECT format('rel %s.%s %s', n.nspname, c.relname, c.relkind) AS line
                FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
               WHERE n.nspname IN ('synth', 'synth_meta') AND c.relkind IN ('r', 'p', 'v', 'm', 'S')
              UNION ALL
              SELECT format('col %s.%s.%s %s %s %s', table_schema, table_name, column_name, udt_name, is_nullable,
                            coalesce(column_default, '')) FROM information_schema.columns
               WHERE table_schema IN ('synth', 'synth_meta')
              UNION ALL
              SELECT format('con %s %s %s', conrelid::regclass, conname, pg_get_constraintdef(oid)) FROM pg_constraint
               WHERE connamespace IN ('synth'::regnamespace, 'synth_meta'::regnamespace)
              UNION ALL
              SELECT format('idx %s %s', schemaname, indexdef) FROM pg_indexes WHERE schemaname IN ('synth', 'synth_meta')
              UNION ALL
              SELECT format('fn %s.%s(%s)', n.nspname, p.proname, pg_get_function_identity_arguments(p.oid))
                FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname IN ('synth', 'synth_meta')
              UNION ALL
              SELECT format('ext %s', extname) FROM pg_extension
            ) entries WHERE line NOT LIKE '%doctrine_migration_versions%'
               -- the first migration after the baseline owns these (checked by tools/schema-check.php): it removes core_api_badge and
               -- replaces the connectors' api_badge_id with api_key_id, so the fingerprint leaves them out
               AND line NOT LIKE '%core_api_badge%' AND line NOT LIKE '%api_badge_id%' AND line NOT LIKE '%api_key_id%'
            SQL);
    }

    private function dataChecksum(): string
    {
        $parts = [];
        foreach (['synth.database_versioning', 'synth.general_settings', 'synth.core_profiles', 'synth.core_npc_master',
                     'synth.prompts', 'synth.core_action', 'synth.eventlog', 'synth.bio_templates'] as $table) {
            $parts[] = $table.'='.$this->value("SELECT md5(coalesce(string_agg(t::text, E'\\n' ORDER BY t::text), '')) FROM {$table} t");
        }
        return implode(' ', $parts);
    }

    private function populateLegacyInstall(): void
    {
        $this->rows("INSERT INTO synth.general_settings (id, value, description) VALUES ('SCHEMA_OWNER_FIXTURE', 'kept', 'test row')");
        $this->rows("INSERT INTO synth.eventlog (type, data, sess, gamets, localts, ts) VALUES ('inputtext', 'Sole Survivor: hello', 'pending', 7, 8, 9)");
        $this->rows("INSERT INTO synth.core_npc_master (npc_name, npc_static_bio) VALUES ('schema owner fixture', 'Kept verbatim.')");
        $this->rows("UPDATE synth.prompts SET custom_prompt = 'Kept local prompt.' WHERE prompt_key = 'synth_system_prompt'");
    }

    public function testPopulatedLegacyInstallIsRecognizedUnchangedAndConvergesWithFreshInstall(): void
    {
        $this->populateLegacyInstall();
        self::assertSame([], $this->recorded());
        $schemaBefore = $this->schemaFingerprint();
        $dataBefore = $this->dataChecksum();

        $this->assertBootstrapSucceeds();
        self::assertSame($schemaBefore, $this->schemaFingerprint(), 'Recognition must not change the legacy schema.');
        self::assertSame($dataBefore, $this->dataChecksum(), 'Recognition must not change any existing row.');

        $recordedAt = $this->recorded();
        $this->assertBootstrapSucceeds();
        self::assertSame($recordedAt, $this->recorded(), 'A rerun must not record the baseline again.');
        self::assertSame($schemaBefore, $this->schemaFingerprint());
        self::assertSame($dataBefore, $this->dataChecksum());

        $this->recreateFreshDatabase();
        self::assertSame([], $this->recorded());
        $this->assertBootstrapSucceeds();
        self::assertSame($schemaBefore, $this->schemaFingerprint(), 'A fresh install must converge on the upgraded schema.');
        self::assertSame([], $this->rows('SELECT tablename FROM synth.database_versioning GROUP BY tablename HAVING count(*) > 1'));
    }

    public static function baselineDrift(): array
    {
        $claims = 'ALTER TABLE synth_meta.social_task_claims ';
        $deadline = 'column|synth_meta|social_task_claims|deadline_at|timestamp with time zone|timestamptz|NO||';
        return [
            'dropped table' => ['DROP TABLE synth_meta.social_task_claims', 'column|synth_meta|social_task_claims|deadline_at|'],
            'column type' => [$claims.'ALTER COLUMN generation TYPE integer', 'column|synth_meta|social_task_claims|generation|bigint|int8|NO||'],
            'nullability' => [$claims.'ALTER COLUMN deadline_at DROP NOT NULL', $deadline],
            'default' => [$claims.'ALTER COLUMN deadline_at SET DEFAULT now()', $deadline],
            'primary key' => [$claims.'DROP CONSTRAINT social_task_claims_pkey', 'constraint|synth_meta|social_task_claims|social_task_claims_pkey|p|t|PRIMARY KEY'],
            'foreign key' => [$claims.'DROP CONSTRAINT social_task_claims_task_id_fkey', 'constraint|synth_meta|social_task_claims|social_task_claims_task_id_fkey|f|t|'],
            'changed check' => [$claims.'DROP CONSTRAINT social_task_claims_generation_check, ADD CONSTRAINT social_task_claims_generation_check CHECK (generation >= 0)',
                'constraint|synth_meta|social_task_claims|social_task_claims_generation_check|c|t|CHECK ((generation > 0))'],
            'unique' => [$claims.'DROP CONSTRAINT social_task_claims_session_id_generation_execution_id_key',
                'constraint|synth_meta|social_task_claims|social_task_claims_session_id_generation_execution_id_key|u|t|'],
            'index' => ['DROP INDEX synth_meta.memory_summaries_batch', 'index|synth_meta|memory_summaries|memory_summaries_batch|'],
            'vector dimension' => ['ALTER TABLE synth_meta.diary_job_results ALTER COLUMN embedding TYPE public.vector(768) USING NULL',
                'typmod|synth_meta|diary_job_results|embedding|vector(384)'],
        ];
    }

    // A pre-ledger install whose legacy versions are current is verified as it stands: drift is refused, and the legacy
    // chain does not run to half-repair it.
    #[\PHPUnit\Framework\Attributes\DataProvider('baselineDrift')]
    public function testDriftedCurrentLegacySchemaIsRefusedWithoutLegacyRepair(string $drift, string $expected): void
    {
        $this->rows($drift);
        $schema = $this->schemaFingerprint();
        $data = $this->dataChecksum();
        $result = $this->bootstrap();
        self::assertNotSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('baseline structure entries are missing or changed', $result['output']);
        self::assertStringContainsString($expected, $result['output']);
        self::assertSame([], $this->recorded());
        self::assertSame($schema, $this->schemaFingerprint(), 'The legacy chain must not run on a claimed-current schema.');
        self::assertSame($data, $this->dataChecksum());
    }

    public function testUnrelatedExtraObjectsDoNotBlockTheBaseline(): void
    {
        $this->rows('CREATE TABLE synth.schema_owner_extra (id integer PRIMARY KEY)');
        $this->rows('ALTER TABLE synth_meta.social_task_claims ADD COLUMN extra_note text');
        $this->rows('CREATE INDEX social_task_claims_extra ON synth_meta.social_task_claims (extra_note)');
        $this->assertBootstrapSucceeds();
    }

    public function testDamageAfterTheRecordedBaselineIsRefusedNotRepaired(): void
    {
        $this->assertBootstrapSucceeds();
        // Baseline checks apply only while the baseline is the latest record (later migrations own the schema).
        $this->rows('DELETE FROM synth_meta.doctrine_migration_versions WHERE version = $1', [self::SHIPPED]);
        $recorded = $this->recorded();
        $this->rows('DROP INDEX synth_meta.memory_summaries_batch');
        $this->rows("UPDATE synth.database_versioning SET version = 20260719001 WHERE tablename = 'core_action'");
        $result = $this->bootstrap();
        self::assertNotSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('no longer matches its recorded legacy baseline', $result['output']);
        self::assertStringContainsString('index|synth_meta|memory_summaries|memory_summaries_batch|', $result['output']);
        self::assertSame($recorded, $this->recorded());
        self::assertNull($this->value("SELECT to_regclass('synth_meta.memory_summaries_batch')"));
        self::assertSame('20260719001', $this->value("SELECT version FROM synth.database_versioning WHERE tablename = 'core_action'"));
    }

    public function testPartialLegacySchemaIsRefusedThenRecognizedOnceRepaired(): void
    {
        $this->rows("DELETE FROM synth.database_versioning WHERE tablename = 'social_tasks_schema'");
        $this->rows('DROP TABLE synth_meta.social_task_claims, synth_meta.social_task_events, synth_meta.social_task_inputs');
        // A stray table of the same name makes the legacy social task schema file fail as one statement batch.
        $this->rows('CREATE TABLE synth_meta.social_task_inputs (unexpected integer)');

        $result = $this->bootstrap();
        self::assertNotSame(0, $result['exit'], $result['output']);
        self::assertSame([], $this->recorded());
        self::assertNull($this->value("SELECT version FROM synth.database_versioning WHERE tablename = 'social_tasks_schema'"));
        self::assertNull($this->value("SELECT to_regclass('synth_meta.social_task_events')"));

        $this->rows('DROP TABLE synth_meta.social_task_inputs');
        $this->assertBootstrapSucceeds();
        self::assertSame('20261102001', $this->value("SELECT version FROM synth.database_versioning WHERE tablename = 'social_tasks_schema'"));
    }

    public function testBaselineRevertsWithoutTouchingLegacySchemaAndUnknownRecordsAreRefused(): void
    {
        $this->populateLegacyInstall();
        $this->assertBootstrapSucceeds();
        $schema = $this->schemaFingerprint();
        $data = $this->dataChecksum();

        require_once dirname(__DIR__, 2).'/core/dbmanager/SchemaMigrations.php';
        $owner = \Synth\Core\Doctrine\SchemaMigrations::connect(['host' => 'localhost', 'port' => '5432',
            'dbname' => 'testdb', 'username' => 'dwemer', 'password' => 'dwemer']);
        try {
            self::assertSame([], $owner->revertTo('first'));
        } finally {
            $owner->close();
        }
        self::assertSame([], $this->recorded());
        self::assertSame($schema, $this->schemaFingerprint());
        self::assertSame($data, $this->dataChecksum());

        $this->assertBootstrapSucceeds();

        // Code older than the database: a record this tree does not contain stops every entry before any migration,
        // and before the legacy chain could act on an older legacy version.
        $this->rows('DELETE FROM synth_meta.doctrine_migration_versions');
        $this->rows("UPDATE synth.database_versioning SET version = 20260719001 WHERE tablename = 'core_action'");
        $this->rows("INSERT INTO synth_meta.doctrine_migration_versions (version) VALUES ('Synth\\Migrations\\Version29990101000000')");
        $data = $this->dataChecksum();
        foreach ([$this->bootstrap(), $this->finish($this->startDirectUpdates())] as $result) {
            self::assertNotSame(0, $result['exit'], $result['output']);
            self::assertStringContainsString('does not contain: Synth\\Migrations\\Version29990101000000', $result['output']);
        }
        self::assertSame($data, $this->dataChecksum());
        self::assertSame(['Synth\\Migrations\\Version29990101000000'], array_keys($this->recorded()));
    }

    // The recorded baseline is re-verified against its frozen contract only, so a later migration is not refused
    // because the runtime shipped with it already expects that migration's work. A failure inside it rolls back its
    // DDL and leaves no version record.
    public function testLaterMigrationRunsBeforeTheRuntimeGateItSatisfiesAndRollsBackWhole(): void
    {
        $this->populateLegacyInstall();
        $this->assertBootstrapSucceeds();
        $baseline = $this->recorded();
        $data = $this->dataChecksum();

        $failed = $this->finish($this->startLaterMigration(true));
        self::assertSame(3, $failed['exit'], $failed['output']);
        self::assertStringContainsString('gate unmet before: yes', $failed['output']);
        self::assertStringContainsString('duplicate key', $failed['output']);
        self::assertSame($baseline, $this->recorded(), 'A failed later migration must leave no version record.');
        self::assertNull($this->value("SELECT to_regclass('synth_meta.schema_owner_later')"));

        $result = $this->finish($this->startLaterMigration(false));
        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString("gate unmet before: yes\ngate unmet after: no", $result['output']);
        self::assertSame([self::BASELINE, self::SHIPPED, self::LATER], array_keys($this->recorded()));
        self::assertSame($baseline[self::BASELINE], $this->recorded()[self::BASELINE]);
        self::assertSame($data, $this->dataChecksum(), 'A later migration must not change legacy rows it does not own.');
    }

    public function testDirectLegacyEntryUsesTheOwnerLockAndRefusesAnOpenTransaction(): void
    {
        $holder = pg_connect('host=localhost dbname=testdb user=dwemer password=dwemer', PGSQL_CONNECT_FORCE_NEW);
        self::assertNotFalse(pg_query($holder, self::LOCK));
        $waiting = $this->startDirectUpdates();
        sleep(3);
        self::assertTrue(proc_get_status($waiting[0])['running'], 'A direct db_updates.php caller must wait for the lock.');
        self::assertSame([], $this->recorded());
        pg_close($holder);
        $result = $this->finish($waiting);
        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('direct entry finished', $result['output']);
        self::assertSame([self::BASELINE, self::SHIPPED], array_keys($this->recorded()));

        // Inside a caller's transaction the entry refuses before the legacy chain or any migration, so it cannot
        // commit or end that work.
        $this->rows('DELETE FROM synth_meta.doctrine_migration_versions');
        $result = $this->finish($this->startDirectUpdates('$db->execQuery("BEGIN");'));
        self::assertSame(3, $result['exit'], $result['output']);
        self::assertStringContainsString('open transaction', $result['output']);
        self::assertSame([], $this->recorded());
    }

    public function testConcurrentBootstrapsSerializeOnTheMigrationLock(): void
    {
        // A held lock: the bootstrap waits without recording anything, and a bounded waiter gives up explicitly.
        $holder = pg_connect('host=localhost dbname=testdb user=dwemer password=dwemer', PGSQL_CONNECT_FORCE_NEW);
        self::assertNotFalse(pg_query($holder, self::LOCK));
        $waiting = $this->startBootstrap();
        sleep(3);
        self::assertTrue(proc_get_status($waiting[0])['running'], 'The bootstrap must wait for the lock.');
        self::assertSame([], $this->recorded());

        require_once dirname(__DIR__, 2).'/core/dbmanager/SchemaMigrations.php';
        $bounded = \Synth\Core\Doctrine\SchemaMigrations::connect(['host' => 'localhost', 'port' => '5432',
            'dbname' => 'testdb', 'username' => 'dwemer', 'password' => 'dwemer'], 1);
        try {
            $bounded->revertTo('first');
            self::fail('A bounded waiter must not run while the lock is held.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('still running', $error->getMessage());
        } finally {
            $bounded->close();
        }

        pg_close($holder);
        $result = $this->finish($waiting);
        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame([self::BASELINE, self::SHIPPED], array_keys($this->recorded()));

        // Two fresh-install bootstraps at once: one runs the legacy chain and records the baseline, the other waits
        // and then finds nothing to do. Unserialized, the chain's check-then-insert version writes would race.
        $this->recreateFreshDatabase();
        $first = $this->startBootstrap();
        $second = $this->startBootstrap();
        foreach ([$this->finish($first), $this->finish($second)] as $result) {
            self::assertSame(0, $result['exit'], $result['output']);
        }
        self::assertSame([self::BASELINE, self::SHIPPED], array_keys($this->recorded()));
        self::assertSame([], $this->rows('SELECT tablename FROM synth.database_versioning GROUP BY tablename HAVING count(*) > 1'));
    }

    public function testKilledFreshBootstrapIsNeverFalselyBaselinedAndRerunConverges(): void
    {
        $legacySchema = $this->schemaFingerprint();
        $this->recreateFreshDatabase();
        $run = $this->startBootstrap();
        // The kill point is not controlled; whatever step it lands on, no baseline may exist without a complete schema.
        usleep(1500000);
        proc_terminate($run[0], 9);
        $this->finish($run);
        if ($this->recorded() !== []) {
            self::assertSame($legacySchema, $this->schemaFingerprint(), 'A recorded baseline must describe a complete schema.');
        }

        $this->assertBootstrapSucceeds();
        self::assertSame($legacySchema, $this->schemaFingerprint());
    }

    // A new, empty UTF-8 database: what the WSL provisioner hands the bootstrap, minus the extensions it pre-creates.
    private function recreateEmptyDatabase(): void
    {
        if ($this->pg !== null) pg_close($this->pg);
        $this->pg = null;
        self::recreateEmptyTestDatabase();
    }

    private function assertDatabaseIsEmpty(): void
    {
        self::assertSame([], $this->rows("SELECT nspname FROM pg_namespace
            WHERE nspname NOT LIKE 'pg\\_%' AND nspname NOT IN ('public', 'information_schema')"));
        self::assertSame([['extname' => 'plpgsql']], $this->rows('SELECT extname FROM pg_extension ORDER BY 1'));
        self::assertSame('0', $this->value("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'"));
    }

    // What schemaFingerprint() leaves out, by object: every row of every application table except the version table
    // (install-time timestamps excluded), sequence positions, view, function and trigger definitions, comments, and
    // the extensions with their schemas. Equal on two databases means the same install, apart from when it ran.
    private static function installFingerprint(\PgSql\Connection $pg): array
    {
        $fingerprint = [];
        foreach (self::rowsOn($pg, "SELECT table_schema, table_name FROM information_schema.tables
                WHERE table_schema IN ('synth', 'synth_meta') AND table_type = 'BASE TABLE'
                  AND table_name <> 'doctrine_migration_versions' AND table_name <> 'core_api_badge' ORDER BY 1, 2") as ['table_schema' => $schema, 'table_name' => $table]) {
            $columns = array_column(self::rowsOn($pg, "SELECT quote_ident(column_name) AS name FROM information_schema.columns
                WHERE table_schema = $1 AND table_name = $2 AND data_type NOT LIKE 'timestamp%'
                  AND column_name NOT IN ('api_badge_id', 'api_key_id') ORDER BY ordinal_position",
                [$schema, $table]), 'name');
            $row = 'ROW('.implode(', ', $columns).')::text';
            $relation = pg_escape_identifier($pg, $schema).'.'.pg_escape_identifier($pg, $table);
            $fingerprint["rows {$schema}.{$table}"] = self::rowsOn($pg, "SELECT count(*) || ' ' || md5(coalesce(string_agg({$row}, E'\\n'
                ORDER BY {$row}), '')) AS rows FROM {$relation}")[0]['rows'];
        }
        foreach (self::rowsOn($pg, <<<'SQL'
                SELECT 'sequence ' || schemaname || '.' || sequencename AS name, coalesce(last_value::text, 'unused') AS definition
                  FROM pg_sequences WHERE schemaname IN ('synth', 'synth_meta')
                UNION ALL
                SELECT 'view ' || c.oid::regclass, pg_get_viewdef(c.oid) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname IN ('synth', 'synth_meta') AND c.relkind IN ('v', 'm')
                UNION ALL
                SELECT 'function ' || p.oid::regprocedure, pg_get_functiondef(p.oid) FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                 WHERE n.nspname IN ('synth', 'synth_meta') AND p.prokind IN ('f', 'p')
                   AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_proc'::regclass AND d.objid = p.oid AND d.deptype = 'e')
                UNION ALL
                SELECT 'trigger ' || t.tgrelid::regclass || ' ' || t.tgname, pg_get_triggerdef(t.oid) FROM pg_trigger t
                  JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE NOT t.tgisinternal AND n.nspname IN ('synth', 'synth_meta')
                UNION ALL
                SELECT 'comment ' || n.nspname, obj_description(n.oid, 'pg_namespace') FROM pg_namespace n
                 WHERE n.nspname IN ('synth', 'synth_meta', 'plugins')
                UNION ALL
                SELECT 'comment ' || c.oid::regclass || coalesce('.' || a.attname, ''),
                       CASE WHEN a.attnum IS NULL THEN obj_description(c.oid, 'pg_class') ELSE col_description(c.oid, a.attnum) END
                  FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                  LEFT JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped
                 WHERE n.nspname IN ('synth', 'synth_meta') AND c.relkind IN ('r', 'p', 'v', 'm')
                   AND c.relname <> 'doctrine_migration_versions'
                UNION ALL
                SELECT 'extension ' || e.extname, n.nspname FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace
                SQL) as ['name' => $name, 'definition' => $definition]) {
            if ($definition !== null) $fingerprint[$name] = md5($definition);
        }
        ksort($fingerprint, SORT_STRING);
        return $fingerprint;
    }

    // The writing transaction (xmin) of the stock rows, the synth and plugins schemas and the application relations,
    // with the version record when $withLedger: one writer means one transaction created them all.
    private static function installWriters(\PgSql\Connection $pg, bool $withLedger): array
    {
        // Only the baseline's record and objects: the later migration (it alters the four connector tables) is its own savepoint.
        $ledger = $withLedger ? "UNION ALL SELECT xmin::text FROM synth_meta.doctrine_migration_versions WHERE version LIKE '%Version20261115000000'" : '';
        return array_column(self::rowsOn($pg, <<<SQL
            SELECT writer, count(*) AS objects FROM (
                SELECT xmin::text AS writer FROM synth.database_versioning
                UNION ALL SELECT xmin::text FROM synth.prompts
                UNION ALL SELECT xmin::text FROM synth.general_settings
                UNION ALL SELECT xmin::text FROM synth.core_action
                UNION ALL SELECT xmin::text FROM synth.core_profiles
                UNION ALL SELECT xmin::text FROM synth.worldknowledge
                UNION ALL SELECT xmin::text FROM pg_namespace WHERE nspname IN ('synth', 'plugins')
                UNION ALL SELECT c.xmin::text FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname IN ('synth', 'synth_meta')
                   AND c.relname NOT IN ('doctrine_migration_versions', 'doctrine_migration_versions_pkey', 'core_api_badge', 'core_llm_connector', 'core_tts_connector', 'core_stt_connector', 'core_itt_connector')
                {$ledger}
            ) writers GROUP BY writer
            SQL), 'objects', 'writer');
    }

    // An empty database: the baseline migration installs the frozen schema and stock seeds itself, with no legacy chain
    // and no separate SQL import, in the same transaction as its version record. The result is the legacy fresh install
    // (default SQL, then the chain) apart from install-time timestamps. Two bootstraps at once install it once, a rerun
    // changes nothing, and reverting keeps the install, which the next run verifies and records again.
    public function testEmptyDatabaseIsInstalledByTheBaselineMigrationInOneTransaction(): void
    {
        $legacySchema = $this->schemaFingerprint();
        self::assertGreaterThan(1, self::$legacyWriters, 'The legacy chain writes in many transactions; the check must tell them apart.');

        $this->recreateEmptyDatabase();
        $this->assertDatabaseIsEmpty();
        $first = $this->startBootstrap();
        $second = $this->startBootstrap();
        foreach ([$this->finish($first), $this->finish($second)] as $result) {
            self::assertSame(0, $result['exit'], $result['output']);
            self::assertStringContainsString('schema migrations recorded: 2 (latest '.self::SHIPPED.')', $result['output']);
        }
        $recorded = $this->recorded();
        self::assertSame([self::BASELINE, self::SHIPPED], array_keys($recorded));
        self::assertSame($legacySchema, $this->schemaFingerprint(), 'A fresh install must match the legacy fresh install.');
        self::assertSame(self::$legacyInstall, self::installFingerprint($this->pg()));
        self::assertCount(1, self::installWriters($this->pg(), true), 'Schema, seeds and version record must commit together.');
        self::assertSame([], $this->rows('SELECT tablename FROM synth.database_versioning GROUP BY tablename HAVING count(*) > 1'));

        $this->assertBootstrapSucceeds();
        self::assertSame($recorded, $this->recorded(), 'A rerun must not record the baseline again.');
        self::assertSame(self::$legacyInstall, self::installFingerprint($this->pg()));

        $owner = $this->owner();
        try {
            self::assertSame([], $owner->revertTo('first'));
        } finally {
            $owner->close();
        }
        self::assertSame([], $this->recorded());
        self::assertSame($legacySchema, $this->schemaFingerprint(), 'Reverting must keep the installed schema.');
        self::assertSame(self::$legacyInstall, self::installFingerprint($this->pg()));
        $this->assertBootstrapSucceeds();
        self::assertSame($legacySchema, $this->schemaFingerprint());
        self::assertSame(self::$legacyInstall, self::installFingerprint($this->pg()));
    }

    // The WSL provisioner creates both extensions in public before the bootstrap, as postgres. That database still counts
    // as empty, and the install uses the existing extensions: it differs from the reference only in where pg_trgm lives.
    public function testFreshInstallUsesExtensionsTheProvisionerCreatedInPublic(): void
    {
        $this->recreateEmptyDatabase();
        $this->rows('CREATE EXTENSION vector WITH SCHEMA public');
        $this->rows('CREATE EXTENSION pg_trgm WITH SCHEMA public');
        $this->assertBootstrapSucceeds();
        $install = self::installFingerprint($this->pg());
        self::assertSame(md5('public'), $install['extension pg_trgm']);
        self::assertSame(['extension pg_trgm' => md5('synth')], array_diff_assoc(self::$legacyInstall, $install));
        self::assertSame(['extension pg_trgm' => md5('public')], array_diff_assoc($install, self::$legacyInstall));
        self::assertCount(4, $this->rows("SELECT indexname FROM pg_indexes WHERE schemaname = 'synth' AND indexdef LIKE '%gin_trgm_ops%'"));
    }

    // A failure anywhere in the plan (here a later migration after the baseline) rolls the whole install back, including
    // the synth_meta schema, the version table and the extensions the run created, so a retry starts from an empty
    // database again.
    public function testFailedFreshInstallLeavesTheDatabaseEmptyAndARetryInstallsIt(): void
    {
        $legacySchema = $this->schemaFingerprint();
        $this->recreateEmptyDatabase();
        $failed = $this->finish($this->startLaterMigration(true));
        self::assertSame(3, $failed['exit'], $failed['output']);
        self::assertStringContainsString('duplicate key', $failed['output']);
        $this->assertDatabaseIsEmpty();

        $this->assertBootstrapSucceeds();
        self::assertSame($legacySchema, $this->schemaFingerprint());
        self::assertSame(self::$legacyInstall, self::installFingerprint($this->pg()));
    }

    // A bootstrap killed inside its migration transaction (seen from another session once that transaction has written)
    // leaves nothing behind once its orphaned backend ends, and a rerun installs normally.
    public function testFreshInstallKilledInsideItsTransactionLeavesTheDatabaseEmpty(): void
    {
        $this->recreateEmptyDatabase();
        $run = $this->startBootstrap();
        $writing = "SELECT count(*) FROM pg_stat_activity WHERE datname = 'testdb'
            AND application_name = 'synth-schema-migrations' AND backend_xid IS NOT NULL";
        $deadline = microtime(true) + 120;
        while ($this->value($writing) === '0') {
            self::assertTrue(proc_get_status($run[0])['running'], 'The bootstrap ended before its transaction was seen.');
            self::assertLessThan($deadline, microtime(true), 'The migration transaction never started.');
            usleep(20000);
        }
        proc_terminate($run[0], 9);
        $this->finish($run);
        $deadline = microtime(true) + 120;
        while ($this->value("SELECT count(*) FROM pg_stat_activity WHERE datname = 'testdb'
                AND application_name = 'synth-schema-migrations'") !== '0') {
            self::assertLessThan($deadline, microtime(true), 'The orphaned migration backend did not end.');
            usleep(100000);
        }
        self::assertSame([], $this->recorded());
        $this->assertDatabaseIsEmpty();

        $this->assertBootstrapSucceeds();
        self::assertSame(self::$legacyInstall, self::installFingerprint($this->pg()));
    }

    // Only a database with nothing in it is installed fresh. Extensions and the version table (which the owner creates
    // first, in the same transaction) do not count; any other schema or object keeps the legacy path, which never
    // replaces what is there. The generated statements are intact and one per chunk.
    public function testOnlyADatabaseWithNothingInItIsInstalledFresh(): void
    {
        require_once dirname(__DIR__, 2).'/vendor/autoload.php';
        require_once dirname(__DIR__, 2).'/core/dbmanager/migrations/Version20261115000000.php';
        $connect = static fn (): \Doctrine\DBAL\Connection => \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pgsql',
            'host' => 'localhost', 'port' => 5432, 'dbname' => 'testdb', 'user' => 'dwemer', 'password' => 'dwemer']);
        $connection = $connect();
        try {
            self::assertFalse(\Synth\Migrations\Version20261115000000::isEmptyDatabase($connection), 'A legacy install is not empty.');
        } finally {
            $connection->close();
        }

        $this->recreateEmptyDatabase();
        $connection = $connect();
        try {
            self::assertTrue(\Synth\Migrations\Version20261115000000::isEmptyDatabase($connection));
            $connection->beginTransaction();
            foreach (['CREATE EXTENSION vector', 'CREATE EXTENSION pg_trgm', 'CREATE SCHEMA synth_meta',
                         'CREATE TABLE synth_meta.doctrine_migration_versions (version varchar(191) PRIMARY KEY, executed_at timestamp(0), execution_time integer)']
                     as $sql) {
                $connection->executeStatement($sql);
            }
            self::assertTrue(\Synth\Migrations\Version20261115000000::isEmptyDatabase($connection));
            foreach ([
                'table' => 'CREATE TABLE public.stray (id integer)',
                'view' => 'CREATE VIEW public.stray AS SELECT 1 AS id',
                'sequence' => 'CREATE SEQUENCE public.stray',
                'function' => 'CREATE FUNCTION public.stray() RETURNS integer LANGUAGE sql AS $$SELECT 1$$',
                'type' => "CREATE TYPE public.stray AS ENUM ('stray')",
                'synth schema' => 'CREATE SCHEMA synth',
                'plugins schema' => 'CREATE SCHEMA plugins',
                'other schema' => 'CREATE SCHEMA stray',
                'other synth_meta table' => 'CREATE TABLE synth_meta.stray (id integer)',
            ] as $case => $sql) {
                $connection->executeStatement('SAVEPOINT stray');
                $connection->executeStatement($sql);
                self::assertFalse(\Synth\Migrations\Version20261115000000::isEmptyDatabase($connection), $case);
                $connection->executeStatement('ROLLBACK TO SAVEPOINT stray');
            }
            $connection->rollBack();
        } finally {
            $connection->close();
        }
        $this->assertDatabaseIsEmpty();

        $statements = \Synth\Migrations\Version20261115000000::freshInstallStatements();
        self::assertCount(783, $statements);
        foreach ($statements as $statement) {
            self::assertStringEndsWith(';', rtrim($statement));
            self::assertStringNotContainsString('-- end-statement', $statement);
        }
    }

    // Everything the legacy snapshot helpers could touch: both application schemas, the ledgers, the profile rows,
    // every non-system schema with its relations, and every vector column in the database.
    private function snapshotState(): array
    {
        return [$this->schemaFingerprint(), $this->dataChecksum(), $this->recorded(),
            $this->rows('SELECT * FROM synth_meta.playthrough_profiles ORDER BY id'),
            $this->rows("SELECT n.nspname, c.relname, c.relkind FROM pg_namespace n LEFT JOIN pg_class c ON c.relnamespace = n.oid
                WHERE n.nspname NOT LIKE 'pg\\_%' AND n.nspname <> 'information_schema' ORDER BY 1, 2"),
            $this->rows("SELECT table_schema, table_name, column_name FROM information_schema.columns WHERE udt_name = 'vector' ORDER BY 1, 2, 3"),
            $this->rows('SELECT * FROM synth_profile_kept.eventlog')];
    }

    // Snapshot create, restore and delete are paused: the shared helpers and the Timeline Break autosnapshot refuse
    // without touching the database, even on a writable session, and readiness is read-only. An existing snapshot
    // schema and its unversioned profile row stay exactly as they were, and the owner keeps bootstrapping.
    public function testLegacyPlaythroughSnapshotHelpersCannotMutateAndExistingSnapshotsAreKept(): void
    {
        require_once dirname(__DIR__, 2).'/lib/playthrough_snapshot.php';
        $this->populateLegacyInstall();
        $this->assertBootstrapSucceeds();
        $this->rows('CREATE SCHEMA synth_profile_kept');
        $this->rows('CREATE TABLE synth_profile_kept.eventlog (gamets bigint)');
        $this->rows('INSERT INTO synth_profile_kept.eventlog VALUES (7)');
        $this->rows("INSERT INTO synth_meta.playthrough_profiles (name, size_bytes, notes, is_active, schema_name, storage_type, last_gamets)
            VALUES ('kept', 0, 'existing', true, 'synth_profile_kept', 'schema', 7)");
        $before = $this->snapshotState();

        $readOnly = pg_connect('host=localhost dbname=testdb user=dwemer password=dwemer', PGSQL_CONNECT_FORCE_NEW);
        try {
            self::assertNotFalse(pg_query($readOnly, 'SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY'));
            self::assertTrue(pts_metadata_schema_ready($readOnly), pg_last_error($readOnly));
            self::assertTrue(pts_ensure_functions($readOnly));
        } finally {
            pg_close($readOnly);
        }

        $refused = ['success' => false, 'error' => PTS_SNAPSHOTS_UNAVAILABLE];
        foreach ([['synth', 'synth_profile_new'], ['public', 'synth_profile_kept'], ['synth_profile_kept', 'public'],
                     ['synth_profile_kept', 'synth']] as [$source, $destination]) {
            self::assertSame($refused, pts_clone_schema($this->pg(), $source, $destination));
        }
        self::assertSame($refused, pts_drop_schema($this->pg(), 'synth_profile_kept'));
        self::assertFalse(pts_recreate_public_schema($this->pg()));
        self::assertSame(0, timeline_break_create_snapshot('Timeline Break (test)', 'forged'));
        self::assertSame(0, timeline_break_snapshot_if_needed(100000000, 1000));
        self::assertSame($before, $this->snapshotState());

        $this->assertBootstrapSucceeds();
        self::assertSame($before, $this->snapshotState());
    }

    // An in-process owner on testdb. With $later, the later fixture migration (startLaterMigration's class, empty
    // here) is declared and registered, so a ledger holding it counts as known.
    private function owner(bool $later = false): \Synth\Core\Doctrine\SchemaMigrations
    {
        require_once dirname(__DIR__, 2).'/core/dbmanager/SchemaMigrations.php';
        $owner = \Synth\Core\Doctrine\SchemaMigrations::connect(['host' => 'localhost', 'port' => '5432',
            'dbname' => 'testdb', 'username' => 'dwemer', 'password' => 'dwemer'], 1, $later ? [self::LATER] : []);
        if ($later && !class_exists(self::LATER, false)) {
            eval('namespace Synth\Migrations; final class Version29990101000001 extends \Doctrine\Migrations\AbstractMigration
                { public function up(\Doctrine\DBAL\Schema\Schema $schema): void {} }');
        }
        return $owner;
    }

    // Whether the owner's lock is free, seen from another session (taken and dropped again when it is).
    private function migrationLockIsFree(): bool
    {
        $probe = pg_connect('host=localhost dbname=testdb user=dwemer password=dwemer', PGSQL_CONNECT_FORCE_NEW);
        try {
            $free = pg_fetch_result(pg_query($probe, "SELECT pg_try_advisory_lock(hashtextextended('synth-schema-migrations', 0))"), 0, 0) === 't';
            if ($free) pg_query($probe, "SELECT pg_advisory_unlock(hashtextextended('synth-schema-migrations', 0))");
            return $free;
        } finally {
            pg_close($probe);
        }
    }

    // $call($work) is refused with $message, $work never runs, the lock is free and nothing changed.
    private function assertSnapshotRefused(callable $call, string $message): void
    {
        $before = $this->snapshotState();
        $ran = false;
        try {
            $call(function () use (&$ran): void { $ran = true; });
            self::fail("Snapshot entry must be refused: {$message}");
        } catch (\RuntimeException $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
        self::assertFalse($ran, 'Guarded snapshot work must not run after a refusal.');
        self::assertTrue($this->migrationLockIsFree());
        self::assertSame($before, $this->snapshotState());
    }

    private function markers(): array
    {
        return array_column($this->rows('SELECT gamets FROM synth_profile_kept.eventlog ORDER BY 1'), 'gamets');
    }

    // The compatibility guard only: capture takes the synth source and exposes the exact ledger; restore reads its
    // target under the lock in the owner's transaction and refuses anything but the exact live metadata before its
    // work; the owner commits or rolls back. No snapshot is written or restored, and the legacy pause stays.
    public function testSnapshotGuardMatchesTheExactLedgerInsideOneLockedTransaction(): void
    {
        require_once dirname(__DIR__, 2).'/lib/playthrough_snapshot.php';
        $this->rows('CREATE SCHEMA synth_profile_kept');
        $this->rows('CREATE TABLE synth_profile_kept.eventlog (gamets bigint)');
        $this->rows('INSERT INTO synth_profile_kept.eventlog VALUES (7)');
        $metadata = static fn (array $versions): array =>
            ['format' => 'synth-schema-snapshot.v1', 'schema' => 'synth', 'migration_versions' => $versions];
        $owner = $this->owner(true);
        $capture = static fn (string $source = 'synth'): \Closure =>
            static fn (callable $work): mixed => $owner->captureSnapshot($source, $work);
        $restore = static fn (callable $readTarget): \Closure =>
            static fn (callable $work): mixed => $owner->restoreSnapshot($readTarget, $work);
        $unread = static fn (): never => self::fail('The target must not be read before the live ledger is checked.');
        try {
            // Missing ledger: no version table yet, then an empty one. The target is never read.
            self::assertSame([], $this->recorded());
            $this->assertSnapshotRefused($capture(), 'database records no schema migrations');
            $this->assertBootstrapSucceeds();
            $this->rows('DELETE FROM synth_meta.doctrine_migration_versions');
            $this->assertSnapshotRefused($capture(), 'database records no schema migrations');
            $this->assertSnapshotRefused($restore($unread), 'database records no schema migrations');
            $this->assertBootstrapSucceeds();
            $state = $this->snapshotState();
            foreach (['public', 'synth_profile_kept', 'synth_meta', 'SYNTH', ''] as $source) {
                $this->assertSnapshotRefused($capture($source), 'captured only from the synth schema');
            }

            $captured = $owner->captureSnapshot('synth', function (array $metadata, \Doctrine\DBAL\Connection $c): array {
                self::assertFalse($this->migrationLockIsFree(), 'Capture work must run under the lock.');
                self::assertTrue($c->isTransactionActive(), 'Capture work must run in the owner transaction.');
                return $metadata;
            });
            self::assertSame($metadata([self::BASELINE, self::SHIPPED]), $captured);
            $target = ['id' => 7, 'metadata' => $captured];
            self::assertSame($target, $owner->restoreSnapshot(function (\Doctrine\DBAL\Connection $c) use ($target): array {
                self::assertFalse($this->migrationLockIsFree(), 'The target must be read under the lock.');
                self::assertTrue($c->isTransactionActive(), 'The target must be read in the owner transaction.');
                return $target;
            }, static fn (array $checked): array => $checked));
            self::assertTrue($this->migrationLockIsFree());

            // A two-version ledger: only its exact canonical metadata restores. [LATER] alone shares its newest member.
            $this->rows('INSERT INTO synth_meta.doctrine_migration_versions (version) VALUES ($1)', [self::LATER]);
            $both = $owner->captureSnapshot('synth', static fn (array $metadata): array => $metadata);
            self::assertSame($metadata([self::BASELINE, self::SHIPPED, self::LATER]), $both);
            // Key order of the metadata object is irrelevant: reordered keys and a JSONB round trip both restore.
            $jsonb = json_decode($this->value('SELECT $1::jsonb::text', [json_encode($both)]), true, 512, JSON_THROW_ON_ERROR);
            foreach (['reordered keys' => array_reverse($both, true), 'jsonb round trip' => $jsonb] as $stored) {
                $checked = ['id' => 7, 'metadata' => $stored];
                self::assertSame($checked, $owner->restoreSnapshot(static fn (): array => $checked,
                    static fn (array $target): array => $target));
            }
            self::assertSame(['migration_versions', 'schema', 'format'], array_keys(array_reverse($both, true)));
            foreach ([
                'not an array' => json_encode(['metadata' => $both]),
                'no metadata' => ['id' => 7],
                'null metadata' => ['metadata' => null],
                'same newest version' => ['metadata' => $metadata([self::LATER])],
                'older set' => ['metadata' => $captured],
                'superset' => ['metadata' => $metadata([self::BASELINE, 'Synth\\Migrations\\Version20200101000000', self::SHIPPED, self::LATER])],
                'unsorted' => ['metadata' => $metadata([self::LATER, self::SHIPPED, self::BASELINE])],
                'repeated' => ['metadata' => $metadata([self::BASELINE, self::SHIPPED, self::LATER, self::LATER])],
                'other format' => ['metadata' => array_merge($both, ['format' => 'synth-schema-snapshot.v2'])],
                'other schema' => ['metadata' => array_merge($both, ['schema' => 'public'])],
                'extra key' => ['metadata' => array_merge($both, ['note' => 'x'])],
                'missing key' => ['metadata' => array_diff_key($both, ['schema' => true])],
                'reordered, unsorted list' => ['metadata' => array_reverse($metadata([self::LATER, self::SHIPPED, self::BASELINE]), true)],
                'reordered, list keys reordered' => ['metadata' => array_reverse(array_merge($both,
                    ['migration_versions' => array_reverse($both['migration_versions'], true)]), true)],
                'list instead of object' => ['metadata' => array_values($both)],
            ] as $stored) {
                $this->assertSnapshotRefused($restore(static fn (): mixed => $stored), 'does not match the schema migrations');
            }

            // The owner commits returned work and rolls back thrown work, releasing the lock either way.
            $owner->captureSnapshot('synth', static fn (array $m, \Doctrine\DBAL\Connection $c): int =>
                $c->executeStatement('INSERT INTO synth_profile_kept.eventlog VALUES (8)'));
            self::assertSame(['7', '8'], $this->markers());
            $this->rows('DELETE FROM synth_profile_kept.eventlog WHERE gamets = 8');
            $failing = static function (mixed $unused, \Doctrine\DBAL\Connection $c): never {
                $c->executeStatement('INSERT INTO synth_profile_kept.eventlog VALUES (9)');
                throw new \RuntimeException('snapshot work failed');
            };
            foreach ([$capture(), $restore(static fn (): array => ['metadata' => $both])] as $call) {
                try {
                    $call($failing);
                    self::fail('Failing snapshot work must throw.');
                } catch (\RuntimeException $error) {
                    self::assertSame('snapshot work failed', $error->getMessage());
                }
                self::assertTrue($this->migrationLockIsFree());
                self::assertSame(['7'], $this->markers());
            }
            $this->assertSnapshotRefused(static fn (callable $work): mixed =>
                $owner->captureSnapshot('synth', static function () use ($owner, $work): void { $owner->revertTo('first'); $work(); }),
                'cannot be nested');

            // Unknown live ledger: refused before the target is read or work runs, like every other entry.
            $this->rows("INSERT INTO synth_meta.doctrine_migration_versions (version) VALUES ('Synth\\Migrations\\Version29990101000000')");
            $this->assertSnapshotRefused($capture(), 'does not contain: Synth\\Migrations\\Version29990101000000');
            $this->assertSnapshotRefused($restore($unread), 'does not contain: Synth\\Migrations\\Version29990101000000');
            $this->rows('DELETE FROM synth_meta.doctrine_migration_versions WHERE version NOT IN ($1, $2)', [self::BASELINE, self::SHIPPED]);
        } finally {
            $owner->close();
        }
        self::assertSame($state, $this->snapshotState());
        self::assertSame(['success' => false, 'error' => PTS_SNAPSHOTS_UNAVAILABLE],
            pts_clone_schema($this->pg(), 'synth', 'synth_profile_new'));
        self::assertSame(0, timeline_break_create_snapshot('Timeline Break (test)', 'forged'));
    }
}
