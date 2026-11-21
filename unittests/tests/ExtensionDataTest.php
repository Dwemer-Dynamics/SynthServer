<?php declare(strict_types=1);

require_once 'DatabaseTestCase.php';
require_once dirname(__DIR__, 2) . '/managers/ExtensionManager/ExtensionManager.php';

use Synth\Core\DbManager\Dao\ExtensionDataDao;
use Synth\Core\Doctrine\EntityManagerFactory;
use Synth\Managers\ExtensionManager\DatabaseExtensionStore;
use Synth\Managers\ExtensionManager\ExtensionContext;
use Synth\Managers\ExtensionManager\ExtensionDatabase;
use Synth\Managers\ExtensionManager\ExtensionMigrator;

// The extension host's tables: the key/value store, per-extension migrations with their ledger, and own-table access.
final class ExtensionDataTest extends DatabaseTestCase
{
    private string $root;

    public function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/synth-extdata-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/extensions/ledger/migrations', 0777, true);
    }

    public function tearDown(): void
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    private function boot(sql $db): ExtensionDataDao
    {
        $entities = EntityManagerFactory::forSharedSession($db);
        require_once dirname(__DIR__, 2) . '/core/dbmanager/Database.php';
        \Synth\Core\DbManager\Database::bootWith($entities);
        return ExtensionDataDao::shared();
    }

    public function testTheStoreKeepsValuesPerExtensionAndBoundsThem(): void
    {
        $db = new sql();
        try {
            $dao = $this->boot($db);
            $a = new DatabaseExtensionStore('ext-a', $dao);
            $b = new DatabaseExtensionStore('ext-b', $dao);
            $a->set('talks/1', ['n' => 1, 'text' => 'é']);
            $a->set('talks/2', 'two');
            $b->set('talks/1', 'other');
            $this->assertSame(['n' => 1, 'text' => 'é'], $a->get('talks/1'));
            $this->assertSame('other', $b->get('talks/1'), 'stores are separate');
            $this->assertSame(['talks/1', 'talks/2'], $a->keys('talks/'));
            $this->assertSame([], $a->keys('t%'), 'a prefix is literal');
            $this->assertSame('dflt', $a->get('missing', 'dflt'));
            $a->set('talks/2', 'changed');
            $this->assertSame('changed', $a->get('talks/2'));
            $a->delete('talks/2');
            $this->assertNull($a->get('talks/2'));
            $this->assertFalse($dao->storeHas('ext-a', 'talks/2'));
            $a->set('nothing', null);
            $this->assertTrue($dao->storeHas('ext-a', 'nothing'), 'a stored null is a value');
            try { $a->set('big', str_repeat('x', ExtensionDataDao::MAX_VALUE_BYTES)); $this->fail('too large'); } catch (LengthException) { $this->addToAssertionCount(1); }
            try { $a->set(str_repeat('k', 201), 1); $this->fail('key too long'); } catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
        } finally { $db->close(); }
    }

    public function testTheOldFileStoreIsImportedOnce(): void
    {
        $db = new sql();
        try {
            $dao = $this->boot($db);
            $file = $this->root . '/legacy.json';
            file_put_contents($file, json_encode(['last' => 'Diamond City', 'count' => 3]));
            $dao->storeSet('ledger', 'count', 9);
            $store = new DatabaseExtensionStore('ledger', $dao, $file);
            $this->assertSame('Diamond City', $store->get('last'));
            $this->assertSame(9, $store->get('count'), 'the database value wins');
            $this->assertFileDoesNotExist($file);
            $this->assertFileExists($file . '.imported');
        } finally { $db->close(); }
    }

    public function testMigrationsRunOnceAreCheckedAndRefuseChangedFiles(): void
    {
        $db = new sql();
        try {
            $dao = $this->boot($db);
            $dir = $this->root . '/extensions/ledger/migrations';
            file_put_contents($dir . '/0001_create.sql', "CREATE TABLE IF NOT EXISTS ext_ledger_entries (id bigint PRIMARY KEY, note text NOT NULL);\nCREATE INDEX IF NOT EXISTS ext_ledger_entries_note ON ext_ledger_entries (note);");
            file_put_contents($dir . '/0001_create.down.sql', 'DROP TABLE ext_ledger_entries;');
            $migrator = new ExtensionMigrator($this->root, $dao);
            $this->assertSame(['0001_create'], $migrator->pending('ledger'));
            $this->assertSame(['0001_create'], $migrator->migrate('ledger'));
            $this->assertSame([], $migrator->migrate('ledger'), 'applied once');
            $this->assertSame(['0001_create' => hash('sha256', (string) file_get_contents($dir . '/0001_create.sql'))], $dao->appliedMigrations('ledger'));

            // Own-table access: names are built, statements are checked, parameters are bound.
            $own = new ExtensionDatabase('ledger', $dao);
            $this->assertSame('ext_ledger_entries', $own->table('entries'));
            $this->assertSame(1, $own->execute('INSERT INTO ext_ledger_entries (id, note) VALUES (:id, :note)', ['id' => 1, 'note' => "o'reilly; DROP TABLE x"]));
            $this->assertSame("o'reilly; DROP TABLE x", $own->fetchOne('SELECT note FROM ext_ledger_entries WHERE id = :id', ['id' => 1])['note']);
            $this->assertCount(1, $own->fetchAll('SELECT * FROM ext_ledger_entries'));
            foreach (['SELECT * FROM core_npc', 'SELECT * FROM ext_other_entries', 'SELECT 1; SELECT 2'] as $sql) {
                try { $own->fetchAll($sql); $this->fail($sql); } catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
            }
            try { $own->execute('DROP TABLE ext_ledger_entries'); $this->fail('execute is rows only'); } catch (InvalidArgumentException) { $this->addToAssertionCount(1); }

            // A changed applied file is refused, and so is a refused new file; nothing half-applies.
            file_put_contents($dir . '/0001_create.sql', 'CREATE TABLE ext_ledger_other (id int);');
            try { $migrator->migrate('ledger'); $this->fail('changed file'); } catch (RuntimeException $error) { $this->assertStringContainsString('migration_changed', $error->getMessage()); }
            file_put_contents($dir . '/0001_create.sql', "CREATE TABLE IF NOT EXISTS ext_ledger_entries (id bigint PRIMARY KEY, note text NOT NULL);\nCREATE INDEX IF NOT EXISTS ext_ledger_entries_note ON ext_ledger_entries (note);");
            file_put_contents($dir . '/0002_bad.sql', "CREATE TABLE ext_ledger_ok (id int);\nDROP TABLE core_npc;");
            try { $migrator->migrate('ledger'); $this->fail('bad file'); } catch (RuntimeException $error) { $this->assertStringContainsString('migration_refused', $error->getMessage()); }
            $this->assertNull($db->fetchOne("SELECT to_regclass('public.ext_ledger_ok') AS t")['t'], 'the refused file ran nothing');
            unlink($dir . '/0002_bad.sql');

            // A statement that fails at run time rolls its file back and records nothing.
            file_put_contents($dir . '/0003_fails.sql', "CREATE TABLE ext_ledger_second (id int);\nINSERT INTO ext_ledger_second (nope) VALUES (1);");
            try { $migrator->migrate('ledger'); $this->fail('failing file'); } catch (RuntimeException $error) { $this->assertStringContainsString('migration_failed: 0003_fails', $error->getMessage()); }
            $this->assertNull($db->fetchOne("SELECT to_regclass('public.ext_ledger_second') AS t")['t'], 'the file rolled back');
            $this->assertArrayNotHasKey('0003_fails', $dao->appliedMigrations('ledger'));
        } finally { $db->close(); }
    }

    public function testContextGivesTablesOnlyWithThePermission(): void
    {
        $db = new sql();
        try {
            $dao = $this->boot($db);
            $store = new DatabaseExtensionStore('ledger', $dao);
            $without = new ExtensionContext('ledger', [], [], $store, []);
            try { $without->database(); $this->fail('permission'); } catch (LogicException $error) { $this->assertSame('permission_missing:storage.tables', $error->getMessage()); }
            $with = new ExtensionContext('ledger', [], [], static fn() => $store, ['storage.tables'], static fn() => $dao);
            $this->assertSame('ext_ledger_x', $with->database()->table('x'));
            $this->assertSame($store, $with->store());
        } finally { $db->close(); }
    }
}
