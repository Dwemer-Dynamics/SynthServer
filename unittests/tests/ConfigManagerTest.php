<?php declare(strict_types=1);

require_once 'DatabaseTestCase.php';
require_once dirname(__DIR__, 2).'/managers/ConfigManager/ConfigManager.php';
require_once dirname(__DIR__, 2).'/managers/ConfigManager/ConfigFile.php';

use Synth\Managers\ConfigManager\ConfigFile;
use Synth\Managers\ConfigManager\ConfigManager;

// ConfigManager over the shared legacy session: storage scopes, the validated edit path and override explanation.
final class ConfigManagerTest extends DatabaseTestCase
{
    public function testScopesStoreReadAndDeleteWithoutCrossTalk(): void
    {
        $db = new sql();
        try {
            $config = ConfigManager::forSession($db);
            self::assertTrue($config->set('conf', 'SYNTH_TEST_KEY', 'conf-value', 'a note'));
            self::assertTrue($config->set('player', 'SYNTH_TEST_KEY', true));
            self::assertSame('conf-value', $config->get('conf', 'SYNTH_TEST_KEY'));
            self::assertTrue($config->getBool('player', 'SYNTH_TEST_KEY'));
            self::assertNull($config->get('meta', 'SYNTH_TEST_KEY'));
            self::assertSame('fallback', $config->get('meta', 'SYNTH_TEST_KEY', 'fallback'));

            // A null description keeps the stored one.
            $config->set('conf', 'SYNTH_TEST_KEY', 'second');
            $rows = array_values(array_filter($config->all('conf'), static fn(array $row): bool => $row['id'] === 'SYNTH_TEST_KEY'));
            self::assertSame(['second', 'a note'], [$rows[0]['value'], $rows[0]['description']]);

            self::assertTrue($config->delete('conf', 'SYNTH_TEST_KEY'));
            self::assertNull($config->get('conf', 'SYNTH_TEST_KEY'));
            self::assertSame(['SYNTH_TEST_KEY' => 'true'], $config->getMany('player', ['SYNTH_TEST_KEY']), 'the player row survives a conf delete');
        } finally {
            $db->close();
        }
    }

    // A default (the log trim's AUTO_TRIM_LOGS_ENABLED) is insert-only: a caller that read the setting as missing, stale or
    // unreadable never replaces a row that is there now, whatever its value.
    public function testSetDefaultInsertsOnceAndKeepsStoredValues(): void
    {
        $db = new sql();
        try {
            $config = ConfigManager::forSession($db);
            self::assertTrue($config->setDefault('meta', 'SYNTH_TEST_DEFAULT', 'true'));
            self::assertSame('true', $config->get('meta', 'SYNTH_TEST_DEFAULT'));
            self::assertTrue($config->set('meta', 'SYNTH_TEST_DEFAULT', 'false'));
            self::assertTrue($config->setDefault('meta', 'SYNTH_TEST_DEFAULT', 'true'), 'a conflict is not a failure');
            self::assertSame('false', $config->get('meta', 'SYNTH_TEST_DEFAULT'));

            $db->execQuery("INSERT INTO public.core_config_setting (scope, config_key, config_value) VALUES
                ('meta', 'SYNTH_TEST_ZERO', '0'), ('meta', 'SYNTH_TEST_EMPTY', ''), ('meta', 'SYNTH_TEST_NULL', NULL)");
            foreach (['SYNTH_TEST_ZERO', 'SYNTH_TEST_EMPTY', 'SYNTH_TEST_NULL'] as $key) {
                self::assertTrue($config->setDefault('meta', $key, 'true'));
                self::assertTrue($config->setDefault('meta', $key, 'true'));
            }
            $rows = pg_fetch_all(pg_query($db->getCatalogueSqlConnection(), "SELECT config_key, config_value IS NULL AS is_null, config_value
                FROM public.core_config_setting WHERE scope = 'meta' AND config_key LIKE 'SYNTH\\_TEST\\_%' ORDER BY config_key"));
            self::assertSame([
                ['config_key' => 'SYNTH_TEST_DEFAULT', 'is_null' => 'f', 'config_value' => 'false'],
                ['config_key' => 'SYNTH_TEST_EMPTY', 'is_null' => 'f', 'config_value' => ''],
                ['config_key' => 'SYNTH_TEST_NULL', 'is_null' => 't', 'config_value' => null],
                ['config_key' => 'SYNTH_TEST_ZERO', 'is_null' => 'f', 'config_value' => '0'],
            ], $rows);
            self::assertNull($config->get('conf', 'SYNTH_TEST_DEFAULT'), 'the default lands only in its scope');
        } finally {
            $db->close();
        }
    }

    public function testUnknownScopeIsRefused(): void
    {
        $db = new sql();
        try {
            $this->expectException(InvalidArgumentException::class);
            ConfigManager::forSession($db)->get('secrets', 'x');
        } finally {
            $db->close();
        }
    }

    public function testEditPathValidatesKeyReadonlyAndValue(): void
    {
        $db = new sql();
        try {
            $config = ConfigManager::forSession($db);
            self::assertSame(7, $config->setEditable('CONTEXT_HISTORY', '7'));
            self::assertSame('7', $config->get('general', 'CONTEXT_HISTORY'));
            self::assertTrue($config->setEditable('PROMPT_TIMESTAMP', 'true'));

            foreach ([['NOT_A_SETTING', 1], ['CONTEXT_HISTORY', 'abc'], ['PROMPT_TIMESTAMP', 'maybe'], ['SYNTH_DB_PASSWORD', 'x'], ['DBDRIVER', 'x']] as [$key, $value]) {
                try {
                    $config->setEditable($key, $value);
                    self::fail("$key must be refused");
                } catch (InvalidArgumentException $refused) {
                    self::assertNotSame('', $refused->getMessage());
                }
            }
            self::assertNull($config->get('general', 'NOT_A_SETTING'));
        } finally {
            $db->close();
        }
    }

    public function testExplainResolvesNpcOverProfileOverGlobalAndSkipsGlobalOnlyKeys(): void
    {
        $db = new sql();
        try {
            $config = ConfigManager::forSession($db);
            $config->setEditable('CONTEXT_HISTORY', 30);
            self::assertSame('global', $config->explain('CONTEXT_HISTORY')['source']);
            self::assertSame('profile', $config->explain('CONTEXT_HISTORY', ['CONTEXT_HISTORY' => 20])['source']);
            $npc = $config->explain('CONTEXT_HISTORY', ['CONTEXT_HISTORY' => 20], ['CONTEXT_HISTORY' => 0]);
            self::assertSame(['npc', 0], [$npc['source'], $npc['value']], 'an explicit 0 is a real override');
            self::assertSame('global', $config->explain('CONTEXT_HISTORY', ['CONTEXT_HISTORY' => null])['source']);

            $globalOnly = $config->explain('NEVER_CLEAR_RELATIONSHIP_DATA', ['NEVER_CLEAR_RELATIONSHIP_DATA' => true]);
            self::assertSame(['profile'], $globalOnly['ignored']);
            self::assertNotSame('profile', $globalOnly['source']);
        } finally {
            $db->close();
        }
    }

    public function testConfigFileReadsOnlyKnownScalarKeysAndNamesThemAsLegacy(): void
    {
        $root = sys_get_temp_dir().'/synth-config-'.bin2hex(random_bytes(4));
        mkdir($root.'/config', 0777, true);
        try {
            self::assertSame([], ConfigFile::legacyVariables($root));
            file_put_contents($root.'/config/config.inc.php', "<?php return ['host' => 'db.local', 'port' => 5433, 'unknown' => 'x', 'password' => ['no']];");
            self::assertSame(['SYNTH_DB_HOST' => 'db.local', 'SYNTH_DB_PORT' => '5433'], ConfigFile::legacyVariables($root));
        } finally {
            @unlink($root.'/config/config.inc.php');
            @rmdir($root.'/config');
            @rmdir($root);
        }
    }
}
