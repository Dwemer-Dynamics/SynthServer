<?php declare(strict_types=1);
require_once __DIR__.'/DatabaseTestCase.php';

// The schema migration owner through the real tools/bootstrap-database.php and the debug/db_updates.php compatibility
// entry, each run in a child process against the disposable testdb so no per-process cache hides a step: fresh install
// and recognized legacy upgrade converge, reruns change nothing, drifted or partial schemas are never baselined or
// repaired by the legacy chain, unknown records stop everything first, the baseline reverts without touching the
// legacy schema, and concurrent, direct, transactional or killed runs stay consistent.
final class SchemaMigrationOwnerTest extends DatabaseTestCase
{
    private const BASELINE = 'Synth\\Migrations\\Version20261115000000';
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
    }

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
        $result = pg_query_params($this->pg(), $sql, $params);
        self::assertNotFalse($result, pg_last_error($this->pg()));
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
        self::assertStringContainsString('schema migrations recorded: 1 (latest '.self::BASELINE.')', $result['output']);
        self::assertSame([self::BASELINE], array_keys($this->recorded()));
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
        self::assertSame([self::BASELINE, self::LATER], array_keys($this->recorded()));
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
        self::assertSame([self::BASELINE], array_keys($this->recorded()));

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
        self::assertSame([self::BASELINE], array_keys($this->recorded()));

        // Two fresh-install bootstraps at once: one runs the legacy chain and records the baseline, the other waits
        // and then finds nothing to do. Unserialized, the chain's check-then-insert version writes would race.
        $this->recreateFreshDatabase();
        $first = $this->startBootstrap();
        $second = $this->startBootstrap();
        foreach ([$this->finish($first), $this->finish($second)] as $result) {
            self::assertSame(0, $result['exit'], $result['output']);
        }
        self::assertSame([self::BASELINE], array_keys($this->recorded()));
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
}
