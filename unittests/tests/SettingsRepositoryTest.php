<?php declare(strict_types=1);

require_once 'DatabaseTestCase.php';
require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'core'.DIRECTORY_SEPARATOR.'dbmanager'.DIRECTORY_SEPARATOR
    .'repositories'.DIRECTORY_SEPARATOR.'SettingsRepository.php';

use Synth\Core\Doctrine\EntityManagerFactory;
use Synth\Core\SettingsRepository;

// The Doctrine pilot behind SettingsRepository: same results, failures and session as the legacy SQL reads.
final class SettingsRepositoryTest extends DatabaseTestCase
{
    private function seed(sql $db): void
    {
        $db->execQuery("INSERT INTO public.core_config_setting (scope, config_key, config_value) VALUES
            ('general', 'SYNTH_TEST_ALPHA', 'alpha'), ('general', 'SYNTH_TEST_NULL', NULL), ('general', 'SYNTH_TEST_\"Q\\', 'quoted'), ('general', '123', 'numeric')");
    }

    public function testValuesKeepStoredMissingAndNullSemantics(): void
    {
        $db = new sql();
        try {
            $this->seed($db);
            $settings = new SettingsRepository($db);
            $this->assertSame('alpha', $settings->value('SYNTH_TEST_ALPHA'));
            $this->assertSame('', $settings->value('SYNTH_TEST_NULL'));
            $this->assertSame('quoted', $settings->value('SYNTH_TEST_"Q\\'));
            $this->assertNull($settings->value('SYNTH_TEST_MISSING'));
            $this->assertNull($settings->value('synth_test_alpha'), 'keys compare exactly, as the text primary key does');

            $values = $settings->values(['SYNTH_TEST_ALPHA', 'SYNTH_TEST_NULL', 'SYNTH_TEST_"Q\\', 'SYNTH_TEST_MISSING', 123]);
            ksort($values);
            $this->assertSame([123 => 'numeric', 'SYNTH_TEST_"Q\\' => 'quoted', 'SYNTH_TEST_ALPHA' => 'alpha',
                'SYNTH_TEST_NULL' => ''], $values);
            $this->assertSame([], $settings->values([]));
            $this->assertSame([], $settings->values(['SYNTH_TEST_MISSING']));
        } finally {
            $db->close();
        }
    }

    // One bound text[] for any key count, as the legacy ANY($1::text[]) read: one parameter per key would fail past
    // PostgreSQL's 65535 limit.
    public function testValuesReadsMoreKeysThanPostgresParametersInOneStatement(): void
    {
        $db = new sql();
        try {
            $this->seed($db);
            $ids = array_map(static fn(int $n): string => "SYNTH_TEST_BULK_$n", range(1, 65535));
            $ids[] = 'SYNTH_TEST_ALPHA';
            $this->assertCount(65536, array_unique($ids));
            $this->assertSame(['SYNTH_TEST_ALPHA' => 'alpha'], (new SettingsRepository($db))->values($ids));
        } finally {
            $db->close();
        }
    }

    // Reads run on the legacy session itself: they see a write still uncommitted in the caller's transaction,
    // leave that transaction, its locks and the session exactly as they found them, and never cache a row.
    public function testReadsShareTheLegacySessionAndSeeDirectWrites(): void
    {
        $db = new sql();
        $other = null;
        try {
            $this->seed($db);
            $settings = new SettingsRepository($db);
            $link = $db->getCatalogueSqlConnection();
            $pid = pg_get_pid($link);
            $this->assertSame('alpha', $settings->value('SYNTH_TEST_ALPHA'));

            $db->execQuery('BEGIN');
            $db->execQuery("UPDATE public.core_config_setting SET config_value = 'uncommitted' WHERE scope = 'general' AND config_key = 'SYNTH_TEST_ALPHA'");
            $db->execQuery("INSERT INTO public.core_config_setting (scope, config_key, config_value) VALUES ('general', 'SYNTH_TEST_NEW', 'new')");
            $this->assertSame('uncommitted', $settings->value('SYNTH_TEST_ALPHA'));
            $this->assertSame(['SYNTH_TEST_NEW' => 'new'], $settings->values(['SYNTH_TEST_NEW']));
            $this->assertSame(PGSQL_TRANSACTION_INTRANS, pg_transaction_status($link));
            $this->assertSame($pid, pg_get_pid($link));
            $locks = pg_fetch_all(pg_query($link, "SELECT locktype, mode, relation::regclass::text AS relation
                FROM pg_locks WHERE pid = pg_backend_pid() AND (locktype = 'advisory'
                OR (relation = 'public.core_config_setting'::regclass AND mode NOT IN ('AccessShareLock', 'RowExclusiveLock')))"));
            $this->assertSame([], $locks ?: [], 'reads add no advisory or row-share locks');
            $db->execQuery('ROLLBACK');

            $this->assertSame('alpha', $settings->value('SYNTH_TEST_ALPHA'));
            $this->assertNull($settings->value('SYNTH_TEST_NEW'));

            $other = pg_connect('host=localhost dbname=testdb user=dwemer password=dwemer', PGSQL_CONNECT_FORCE_NEW);
            pg_query($other, "UPDATE public.core_config_setting SET config_value = 'committed elsewhere' WHERE scope = 'general' AND config_key = 'SYNTH_TEST_ALPHA'");
            $this->assertSame('committed elsewhere', $settings->value('SYNTH_TEST_ALPHA'));

            $this->assertSame('0', pg_fetch_result(pg_query($link, 'SELECT count(*) FROM pg_prepared_statements'), 0, 0));
            unset($settings);
            gc_collect_cycles();
            $this->assertSame(PGSQL_CONNECTION_OK, pg_connection_status($link), 'Doctrine never closes the shared link');
            $this->assertSame($pid, pg_get_pid($db->getCatalogueSqlConnection()));
            $this->assertSame(PGSQL_TRANSACTION_IDLE, pg_transaction_status($link));
        } finally {
            if ($other) pg_close($other);
            $db->close();
        }
    }

    public function testUnreadableSettingsKeepTheirLegacyFailureBehavior(): void
    {
        $db = new sql();
        try {
            $settings = new SettingsRepository($db);
            $db->execQuery('BEGIN');
            $db->execQuery('ALTER TABLE public.core_config_setting RENAME TO core_config_setting_hidden');
            $db->execQuery('SAVEPOINT unreadable');
            $this->assertNull($settings->value('SYNTH_TEST_ALPHA'), 'an unreadable single setting reads as absent');
            $db->execQuery('ROLLBACK TO SAVEPOINT unreadable');
            foreach ([['SYNTH_TEST_ALPHA'], []] as $ids) {
                try {
                    $settings->values($ids);
                    $this->fail('values() must refuse an unreadable settings table, even for no keys');
                } catch (RuntimeException $error) {
                    $this->assertSame('Unable to read general settings', $error->getMessage());
                }
                $db->execQuery('ROLLBACK TO SAVEPOINT unreadable');
            }
        } finally {
            if (!$db->isTransactionIdle()) $db->execQuery('ROLLBACK');
            $db->close();
        }
    }

    public function testDoctrineOwnsNoTransactionOrWriteOnTheSharedSession(): void
    {
        $db = new sql();
        try {
            $connection = EntityManagerFactory::forSharedSession($db)->getConnection();
            foreach (['beginTransaction', 'lastInsertId'] as $method) {
                try {
                    $connection->$method();
                    $this->fail("$method must be refused");
                } catch (\Doctrine\DBAL\Exception $error) {
                    $this->assertTrue($db->isTransactionIdle());
                }
            }
        } finally {
            $db->close();
        }
    }
}
