<?php declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Synth\Core\Doctrine\SchemaCheck;

require_once __DIR__.'/DatabaseTestCase.php';
require_once dirname(__DIR__, 2).'/vendor/autoload.php';
require_once dirname(__DIR__, 2).'/core/dbmanager/SchemaCheck.php';

// The read-only drift check inspects public relations named core_* (the redesign's entity tables) beside the synth and
// synth_meta schemas, ignores every other public relation, and still matches a clean fresh install.
final class SchemaCheckCoreTablesTest extends DatabaseTestCase
{
    private function inspect(): array
    {
        $connection = DriverManager::getConnection(['driver' => 'pgsql', 'host' => 'localhost', 'dbname' => self::$testDatabaseName,
            'user' => 'dwemer', 'password' => 'dwemer']);
        try {
            return (new SchemaCheck(dirname(__DIR__, 2).'/core/dbmanager/migrations'))->inspect($connection)['structure'];
        } finally {
            $connection->close();
        }
    }

    public function testAPublicCoreTableIsInspectedWithItsColumnsConstraintsAndIndexes(): void
    {
        $pg = pg_connect('host=localhost dbname='.self::$testDatabaseName.' user=dwemer password=dwemer', PGSQL_CONNECT_FORCE_NEW);
        self::assertNotFalse(pg_query($pg, 'CREATE TABLE public.core_scaffold_probe (id integer PRIMARY KEY, label text NOT NULL, embedding vector(3))'));
        self::assertNotFalse(pg_query($pg, 'CREATE INDEX core_scaffold_probe_label_idx ON public.core_scaffold_probe (label)'));
        self::assertNotFalse(pg_query($pg, 'CREATE TABLE public.scaffold_other (id integer)'));
        pg_close($pg);

        $structure = $this->inspect();
        self::assertContains('table|public|core_scaffold_probe|BASE TABLE', $structure);
        self::assertContains('column|public|core_scaffold_probe|label|text|text|NO||', $structure);
        self::assertContains('typmod|public|core_scaffold_probe|embedding|vector(3)', $structure);
        self::assertNotEmpty(array_filter($structure, static fn(string $line): bool => str_starts_with($line, 'constraint|public|core_scaffold_probe|') && str_contains($line, 'PRIMARY KEY')));
        self::assertNotEmpty(array_filter($structure, static fn(string $line): bool => str_starts_with($line, 'index|public|core_scaffold_probe|core_scaffold_probe_label_idx|')));
        self::assertEmpty(array_filter($structure, static fn(string $line): bool => str_contains($line, '|scaffold_other|')), 'Only core_* relations are inspected in public.');
    }

    public function testAFreshInstallStillMatchesTheManifest(): void
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2).'/core/dbmanager/migrations/schema-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $expected = [];
        foreach ($manifest['migrations'] as $entry) {
            $lines = file(dirname(__DIR__, 2).'/core/dbmanager/migrations/'.$entry['structure']['file'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $expected = [...$expected, ...array_values(array_filter($lines, static fn(string $line): bool => $line[0] !== '#'))];
        }
        $result = SchemaCheck::compare($expected, $this->inspect());
        self::assertSame([], $result['changes']);
    }
}
