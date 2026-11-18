<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SchemaCloneIdentityTest extends TestCase
{
    public function testSchemaClonePreservesGeneratedIdentityValues(): void
    {
        $sql = file_get_contents(
            __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..'
            . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'schema_clone_function.sql'
        );

        $this->assertIsString($sql);
        $this->assertStringContainsString(
            'INSERT INTO %I.%I OVERRIDING SYSTEM VALUE SELECT * FROM %I.%I ON CONFLICT DO NOTHING',
            $sql
        );
    }

    // Snapshot create, restore and delete are paused. The shared helpers refuse before using the connection, so a
    // direct caller cannot mutate even with a live one (the database-backed check is in SchemaMigrationOwnerTest).
    public function testSnapshotMutationHelpersRefuseWithoutUsingTheConnection(): void
    {
        // Loading the logger installs its error handler. Keep the test runner's handler, removing only one this load added.
        $loggerHandlerInstalled = !class_exists('Logger', false);
        require_once dirname(__DIR__, 2) . '/lib/playthrough_snapshot.php';
        if ($loggerHandlerInstalled) restore_error_handler();
        $refused = ['success' => false, 'error' => PTS_SNAPSHOTS_UNAVAILABLE];
        $this->assertSame($refused, pts_clone_schema(null, 'synth', 'synth_profile_new'));
        $this->assertSame($refused, pts_clone_schema(null, 'synth_profile_kept', 'public'));
        $this->assertSame($refused, pts_drop_schema(null, 'synth_profile_kept'));
        $this->assertFalse(pts_recreate_public_schema(null));

        $schemaSource = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/playthrough_schema.php');
        foreach (['DROP SCHEMA', 'CREATE SCHEMA', 'CREATE EXTENSION', 'schema_clone_function.sql',
                     'synth_meta.clone_schema(', 'synth_meta.drop_schema_safe('] as $mutation) {
            $this->assertStringNotContainsString($mutation, $schemaSource);
        }
    }

    // Every request other than GET or HEAD is refused before configuration, the session or the database is loaded.
    public function testPlaythroughManagerRefusesEveryWriteBeforeLoadingAnything(): void
    {
        $page = dirname(__DIR__, 2) . '/ui/playthrough_manager.php';
        $requests = [['POST', 'create'], ['POST', 'switch'], ['POST', 'delete'], ['POST', 'unknown'], ['POST', ''],
            ['PUT', 'switch'], ['DELETE', 'delete']];
        foreach ($requests as [$method, $action]) {
            $code = '$_SERVER["REQUEST_METHOD"]=' . var_export($method, true) . ';'
                . '$_POST=["action"=>' . var_export($action, true) . ',"profile_id"=>"1","name"=>"forged","notes"=>""];'
                . 'require ' . var_export($page, true) . '; echo "not refused";';
            // An unreachable database: a request that got past the refusal could not reach any server.
            $env = array_merge(getenv(), ['SYNTH_DB_HOST' => '127.0.0.1', 'SYNTH_DB_PORT' => '1', 'SYNTH_DB_NAME' => 'refused']);
            $process = proc_open([PHP_BINARY, '-r', $code],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $env);
            $this->assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $this->assertSame(0, proc_close($process), "{$method} {$action}: {$output}");
            $this->assertSame("Playthrough snapshots are unavailable. Nothing was changed.\n", $output, "{$method} {$action}");
        }

        $source = (string) file_get_contents($page);
        foreach (['<form', '$_POST', 'pts_clone_schema', 'pts_drop_schema', 'pts_recreate_public_schema',
                     'core_catalog_version', 'INSERT INTO', 'UPDATE synth_meta', 'DELETE FROM'] as $mutation) {
            $this->assertStringNotContainsString($mutation, $source);
        }
    }
}
