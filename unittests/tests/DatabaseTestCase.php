<?php

use PHPUnit\Framework\TestCase;

require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lib".DIRECTORY_SEPARATOR."logger.php");

abstract class DatabaseTestCase extends TestCase
{
    protected static string $testDatabaseName = "testdb";
    protected static string $testDatabaseBkpName = "testdb_bkp";
    protected string $testNPCName = "Unit Test";

    protected static function connectToAdminDatabase()
    {
        $connection = pg_connect(
            "host=localhost dbname=postgres user=dwemer password=dwemer",
            PGSQL_CONNECT_FORCE_NEW
        );
        if (!$connection) {
            self::fail("Failed to connect to the PostgreSQL administrative database.");
        }
        return $connection;
    }

    // Prevent DROP DATABASE from terminating a stale process-shared fixture handle.
    protected static function resetSharedTestConnection(): void
    {
        if (class_exists('sql') && method_exists('sql', 'resetConnection')) {
            sql::resetConnection();
        }
    }

    // Keep every disposable connection on the same canonical schema order as production.
    private static function configureTestDatabase($connection, string $databaseName): void
    {
        $result = pg_query(
            $connection,
            "ALTER DATABASE {$databaseName} SET search_path TO synth, synth_meta, public"
        );
        if (!$result) {
            self::fail("Failed to configure test database search path: " . pg_last_error($connection));
        }
    }

    public static function setUpBeforeClass(): void
    {
        self::createTestDB();
    }

    public static function tearDownAfterClass(): void
    {
        self::tearDownDB();
    }

    public function setUp(): void
    {
        $this->copyTestDB();
        $GLOBALS['SEMAPHORES_TIMEOUT'] = 1;
        // main.php is required from test-method scope, so initialize the stream
        // collections explicitly in the global scope used by the pipeline.
        $GLOBALS['talkedSoFar'] = [];
        $GLOBALS['alreadysent'] = [];
        $this->setUpDatabaseDefaults();
        $this->setUpDefaultMinimeMocks();
        $this->setUpDefaultConnectorMocks();
        $this->setUpConfFile();
    }

    public function tearDown(): void
    {
        if (class_exists('SemaphoreManager')) {
            SemaphoreManager::release('MAIN');
            SemaphoreManager::release('VSX');
        }
        if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof sql) {
            $GLOBALS['db']->close();
            unset($GLOBALS['db']);
        }
        unset($GLOBALS["SYNTH_TEST_JSON_BODY"]);
        unset($GLOBALS["SYNTH_TEST_NORMALIZED_EVENT"]);
        unset($GLOBALS['SYNTH_CLIENT_CAPABILITIES']);
        unset($GLOBALS['SYNTH_JSON_RESPONSE_LINES'], $GLOBALS['SYNTH_JSON_RESPONSE_CLOSED']);
        unset($GLOBALS['mockConnectorSend'], $GLOBALS['mockConnectorResponseMetaData']);
        unset($GLOBALS['talkedSoFar'], $GLOBALS['alreadysent']);
        unset($GLOBALS['SEMAPHORES_TIMEOUT']);
        unset($_GET["data"], $_GET["DATA"], $_SERVER["QUERY_STRING"]);
        $this->tearDownConfFile();
    }

    // API keys live in the shared dwemer store, which has no place in the unit database. The fixture is a disposable
    // PostgreSQL database holding just the columns of api_keys SYNTH uses (the real schema is the CHIM 4 server's shared
    // migrations), emptied for every test and holding the one OpenRouter test key the connector tests expect.
    private function useSharedKeyStoreFixture($db): void
    {
        require_once dirname(__DIR__, 2).'/core/dbmanager/repositories/ApiBadgeRepository.php';
        $admin = pg_connect('host=localhost dbname=postgres user=dwemer password=dwemer', PGSQL_CONNECT_FORCE_NEW);
        if (!pg_fetch_row(pg_query($admin, "SELECT 1 FROM pg_database WHERE datname = 'dwemer_unit_keys'"))) {
            pg_query($admin, 'CREATE DATABASE dwemer_unit_keys');
        }
        pg_close($admin);
        $shared = pg_connect('host=localhost dbname=dwemer_unit_keys user=dwemer password=dwemer', PGSQL_CONNECT_FORCE_NEW);
        pg_query($shared, "CREATE TABLE IF NOT EXISTS public.api_keys (key_id uuid PRIMARY KEY, provider varchar(32) NOT NULL, label varchar(64) NOT NULL,
            origin varchar(300) NOT NULL, status varchar(8) NOT NULL DEFAULT 'active', nonce bytea, ciphertext bytea, revision bigint NOT NULL DEFAULT 1,
            created_at timestamptz NOT NULL DEFAULT clock_timestamp(), updated_at timestamptz NOT NULL DEFAULT clock_timestamp(), deleted_at timestamptz, secret text)");
        pg_query($shared, 'TRUNCATE public.api_keys');
        pg_close($shared);
        \Synth\Core\ApiBadgeRepository::useSharedStore(\Synth\Core\SharedApiKeyStore::forSettings(
            ['host' => 'localhost', 'port' => '5432', 'dbname' => 'dwemer_unit_keys', 'user' => 'dwemer', 'password' => 'dwemer']));
        (new \Synth\Core\ApiBadgeRepository($db))->add('OpenRouter', 'openrouterjson_key');
    }

    private function setUpDatabaseDefaults(): void
    {
        $db = new sql();
        $this->useSharedKeyStoreFixture($db);
        $db->execQuery('UPDATE public.core_profiles SET tts_connector_id = NULL');
        $db->upsertRowOnConflict('general_settings', [
            'id' => 'SCENE_CLASSIFIER_ENABLED',
            'value' => 'false',
            'description' => 'Disabled in automated tests to prevent external LLM requests.',
        ], 'id');
        $db->close();
    }

    // The shared fixture is the install a new deployment gets: an empty database installed by the baseline migration
    // through the real tools/bootstrap-database.php, run in a child process so no per-process cache hides a step.
    public static function createTestDB(): void
    {
        self::recreateEmptyTestDatabase();
        self::resetSharedTestConnection();
        self::bootstrapTestDatabase();
        self::loadTestRuntime();
        self::snapshotTestDatabaseAsFixture();
    }

    // Runs tools/bootstrap-database.php against testdb (and nothing else) and fails the test on any error.
    protected static function bootstrapTestDatabase(): void
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2).'/tools/bootstrap-database.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null,
            array_merge(getenv(), ['SYNTH_DB_HOST' => 'localhost', 'SYNTH_DB_PORT' => '5432', 'SYNTH_DB_NAME' => self::$testDatabaseName,
                'SYNTH_DB_USER' => 'dwemer', 'SYNTH_DB_PASSWORD' => 'dwemer']));
        if (!is_resource($process)) {
            self::fail('Failed to start the test database bootstrap.');
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);
        if ($exit !== 0 || str_contains($output, '[error]')) {
            self::fail("The test database bootstrap failed (exit {$exit}): {$output}");
        }
    }

    // The test sql adapter and the sample configuration the tests run with.
    protected static function loadTestRuntime(): void
    {
        $path = __DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..";
        require_once($path.DIRECTORY_SEPARATOR."lib".DIRECTORY_SEPARATOR."runtime_bootstrap.php");
        require_once($path.DIRECTORY_SEPARATOR."lib".DIRECTORY_SEPARATOR."phpunit.class.php");

        // Mirror runtime bootstrap: load and import defaults before managed-setting seeds run.
        require($path.DIRECTORY_SEPARATOR."conf".DIRECTORY_SEPARATOR."conf.sample.php");
        synthRuntimeImportConfigVariables(get_defined_vars());
    }

    // Replaces the per-test fixture with a clone of testdb.
    protected static function snapshotTestDatabaseAsFixture(): void
    {
        self::resetSharedTestConnection();
        // Clone from an administrative connection. PostgreSQL cannot clone the
        // database used by the current connection.
        $mainConnection = self::connectToAdminDatabase();
        $dropResult = pg_query($mainConnection, "DROP DATABASE IF EXISTS ".self::$testDatabaseBkpName." WITH (FORCE)");
        if (!$dropResult) {
            self::fail("Failed to drop test database: " . pg_last_error($mainConnection));
        }
        $createResult = pg_query($mainConnection, "CREATE DATABASE ".self::$testDatabaseBkpName." WITH TEMPLATE ".self::$testDatabaseName);
        if (!$createResult) {
            self::fail("Failed to create test database fixture: " . pg_last_error($mainConnection));
        }
        self::configureTestDatabase($mainConnection, self::$testDatabaseBkpName);
        pg_close($mainConnection);
    }

    // A new, empty UTF-8 testdb: no schema besides public, no extension, no object.
    protected static function recreateEmptyTestDatabase(): void
    {
        self::resetSharedTestConnection();
        // Connect to the main database
        $mainConnection = self::connectToAdminDatabase();

        // Drop the test database if it already exists
        $dropResult = pg_query($mainConnection, "DROP DATABASE IF EXISTS ".self::$testDatabaseName." WITH (FORCE)");
        if (!$dropResult) {
            self::fail("Failed to drop test database: " . pg_last_error($mainConnection));
        }

        // Create the test database
        $createResult = pg_query(
            $mainConnection,
            "CREATE DATABASE ".self::$testDatabaseName." WITH TEMPLATE template0 ENCODING 'UTF8' LC_COLLATE 'C' LC_CTYPE 'C'"
        );
        if (!$createResult) {
            self::fail("Failed to create test database: " . pg_last_error($mainConnection));
        }
        self::configureTestDatabase($mainConnection, self::$testDatabaseName);

        pg_close($mainConnection);
    }

    // A pre-ledger legacy install before the legacy chain: an empty UTF-8 testdb with only data/database_default.sql
    // imported. (An empty database is installed by the baseline migration instead; see recreateEmptyTestDatabase.)
    protected static function recreateTestDatabaseFromDefaultSql(): void
    {
        self::recreateEmptyTestDatabase();

        // Connect to the new test database
        $connString = "host=localhost dbname=".self::$testDatabaseName." user=dwemer password=dwemer";
        $testConnection = pg_connect($connString, PGSQL_CONNECT_FORCE_NEW);
        if (!$testConnection) {
            self::fail("Failed to connect to the newly created test database.");
        }
        // Drop and recreate database
        $Q[]="DROP SCHEMA IF EXISTS public CASCADE";
        $Q[]="DROP EXTENSION IF EXISTS vector CASCADE";
        $Q[]="CREATE SCHEMA public";
        $Q[]="CREATE EXTENSION vector";
        foreach ($Q as $QS) {
            $r = pg_query($testConnection, $QS);
            if (!$r) {
                self::fail("Failed to initialize test database schema: " . pg_last_error($testConnection));
            }
        }

        // Import the base SQL through the active PostgreSQL connection so tests do not
        // depend on a platform-specific psql executable being available on PATH.
        $path = __DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..";
        $sqlFile = $path.DIRECTORY_SEPARATOR."data".DIRECTORY_SEPARATOR."database_default.sql";
        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            self::fail("Failed to read base SQL file: $sqlFile");
        }
        $importResult = pg_query($testConnection, $sql);
        if (!$importResult) {
            self::fail("Failed to import base SQL file: " . pg_last_error($testConnection));
        }
        pg_close($testConnection);
    }

    public function copyTestDB(): void
    {
        self::resetSharedTestConnection();
        $mainConnection = self::connectToAdminDatabase();

        // Drop the test database if it already exists
        $dropResult = pg_query($mainConnection, "DROP DATABASE IF EXISTS ".self::$testDatabaseName." WITH (FORCE)");
        if (!$dropResult) {
            $this->fail("Failed to drop test database: " . pg_last_error($mainConnection));
        }

        // Create the test database
        $createResult = pg_query($mainConnection, "CREATE DATABASE ".self::$testDatabaseName." TEMPLATE ".self::$testDatabaseBkpName);
        if (!$createResult) {
            $this->fail("Failed to restore test database fixture: " . pg_last_error($mainConnection));
        }
        self::configureTestDatabase($mainConnection, self::$testDatabaseName);

        pg_close($mainConnection);
    }

    public function setUpDefaultMinimeMocks() {
        // mock minime
        $GLOBALS["mockMinimeExtract"] = function($text) {
            return '{"is_memory_recall": "No", "elapsed_time": "0.05 seconds"}';
        };
        $GLOBALS["mockMinimePostTopic"] = function($text) {
            return "null";
        };
        $GLOBALS["mockMinimeTask"] = function($text) {
            return "null";
        };
        $GLOBALS["mockMinimeTopic"] = function($text) {
            return '{"input_text": "'.$text.'", "generated_tags": "'.$text.'", "elapsed_time": "0.05 seconds"}';
        };
    }

    public function setUpDefaultConnectorMocks() {
        // mock connector response
        $GLOBALS["mockConnectorSend"] = function($url, $context) {
            $response = 'data: {"choices":[{"delta":{"content": "{\"character\": \"The Narrator\", \"listener\": \"Prisoner\", \"message\": \"Unit test message\", \"mood\": \"default\", \"action\": \"Talk\", \"target\": \"Prisoner\"}"}}]}';
            $resourceMock = fopen('php://temp', 'r+');
            fwrite($resourceMock, $response);
            rewind($resourceMock);
            return $resourceMock;
        };
        $GLOBALS["mockConnectorResponseMetaData"] = function() {
            return ["wrapper_data" => ["HTTP/1.1 200 OK"]];
        };
    }

    public function setUpConfFile() {
        $md5name = md5($this->testNPCName);
        $this->tearDownConfFile();
        copy(__DIR__.DIRECTORY_SEPARATOR."conf_empty.php", __DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."conf".DIRECTORY_SEPARATOR."conf_{$md5name}.php");
    }

    public static function tearDownDB(): void
    {
        if (isset($GLOBALS["db"])) {
            $GLOBALS["db"]->close();
            unset($GLOBALS["db"]);
        }
        self::resetSharedTestConnection();
        // Connect back to main to drop the database
        $mainConnection = self::connectToAdminDatabase();

        // Drop the database
        $dropResult = pg_query($mainConnection, "DROP DATABASE IF EXISTS ".self::$testDatabaseName." WITH (FORCE)");
        if (!$dropResult) {
            Logger::error("Failed to drop test database: " . pg_last_error($mainConnection));
        }
        $dropResult = pg_query($mainConnection, "DROP DATABASE IF EXISTS ".self::$testDatabaseBkpName." WITH (FORCE)");
        if (!$dropResult) {
            Logger::error("Failed to drop test database: " . pg_last_error($mainConnection));
        }

        pg_close($mainConnection);
    }

    public function tearDownConfFile() {
        $md5name = md5($this->testNPCName);
        @unlink(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."conf".DIRECTORY_SEPARATOR."conf_{$md5name}.php");
        foreach (glob(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "conf" . DIRECTORY_SEPARATOR . ".conf_{$md5name}*") as $file) {
            @unlink($file);
        }
    }

    protected function setJsonRequest(string $type, int|string $ts, int|string $gamets, mixed $payload = "", array $extra = []): void
    {
        unset($_GET["data"], $_GET["DATA"]);
        $_SERVER["REQUEST_METHOD"] = "POST";
        $_SERVER["CONTENT_TYPE"] = "application/json";
        $_SERVER["HTTP_ACCEPT"] = "application/json";
        $_SERVER["QUERY_STRING"] = "";

        $event = array_merge([
            "schema" => "synth.event.v1",
            "type" => $type,
            "ts" => $ts,
            "gamets" => $gamets,
            "game" => "fo4",
            "response_format" => "json",
        ], $extra);

        if (in_array($type, ["inputtext", "inputtext_s", "narrator_inputtext"], true)) {
            $text = is_scalar($payload) ? (string)$payload : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $playerName = $GLOBALS["PLAYER_NAME"] ?? "Sole Survivor";
            if (preg_match('/^([^:]{1,80}):(.*)$/s', $text, $matches)) {
                $playerName = trim($matches[1]);
                $text = trim($matches[2]);
            }

            $targetName = $type === "narrator_inputtext"
                ? "The Narrator"
                : ($GLOBALS["SYNTH_NAME"] ?? "The Narrator");

            $event["schema"] = "synth.input.v1";
            $event["player"] = $event["player"] ?? ["name" => $playerName];
            $event["target"] = $event["target"] ?? ["name" => $targetName];
            $event["text"] = $event["text"] ?? $text;

            $structuredPayload = [
                "schema" => "synth.input.v1",
                "npc" => $targetName,
                "player" => $playerName,
                "text" => $text,
                "game" => "fo4",
            ];
            $normalizedPayload = json_encode(
                $structuredPayload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } else {
            $event["payload"] = $payload;
            $normalizedPayload = is_string($payload)
                ? $payload
                : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        $GLOBALS["SYNTH_TEST_JSON_BODY"] = $event;
        // Legacy pipeline tests exercise processors directly; strict ingress has dedicated contract coverage.
        $GLOBALS["SYNTH_TEST_NORMALIZED_EVENT"] = [
            "type" => $type,
            "ts" => strval($ts),
            "gamets" => strval($gamets),
            "payload" => $normalizedPayload,
            "json" => $event,
            "source" => "json",
            "request_id" => "unit-" . str_replace('_', '-', $type),
            "turn_id" => "unit-turn",
            "generation" => 1,
            "runtime_variant" => "flat",
            "capabilities" => [],
            "wire_type" => $type,
            "response_format" => "json",
        ];
    }

}
