<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Synth\Managers\ExtensionManager\DeferredEventSink;
use Synth\Managers\ExtensionManager\ExtensionManager;
use Synth\Managers\ExtensionManager\GuardedWorker;
use Synth\Managers\ExtensionManager\HookResults;
use Synth\Managers\ExtensionManager\Hooks;
use Synth\Managers\ExtensionManager\ManifestValidator;
use Synth\Managers\ExtensionManager\MigrationChecker;
use Synth\Workers\WorkerLimits;
use Synth\Workers\WorkerRegistry;

require_once dirname(__DIR__, 2) . '/managers/ExtensionManager/ExtensionManager.php';
require_once dirname(__DIR__, 2) . '/workers/WorkerRegistry.php';

// Extension host parity: the 1.1 hook set and what each filter may change, routes, workers, manifest capabilities and the
// SQL rule. No database here (see ExtensionDataTest for the tables).
final class ExtensionHostTest extends TestCase
{
    private string $root;
    private array $log = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/synth-exthost-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/extensions', 0777, true);
        mkdir($this->root . '/conf', 0777, true);
        $this->log = [];
    }

    protected function tearDown(): void
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    private function manager(?DeferredEventSink $sink = null): ExtensionManager
    {
        return new ExtensionManager($this->root, null, $sink, function (string $m): void { $this->log[] = $m; });
    }

    /** Installs and enables an extension whose register() body is $body; $manifest overrides manifest fields. */
    private function install(string $id, string $body, array $manifest = [], string $class = 'Ext'): void
    {
        $dir = "{$this->root}/extensions/{$id}";
        mkdir("{$dir}/src", 0777, true);
        $ns = 'Synth\\Extensions\\H' . md5($id . $this->root);
        file_put_contents("{$dir}/extension.json", json_encode($manifest + ['id' => $id, 'name' => $id, 'version' => '1.0.0', 'api' => '^1.1',
            'entry' => "{$ns}\\{$class}", 'autoload' => ['psr-4' => ["{$ns}\\" => 'src/']], 'hooks' => Hooks::all()]));
        file_put_contents("{$dir}/src/{$class}.php", "<?php declare(strict_types=1);\nnamespace {$ns};\n"
            . "use Synth\\Managers\\ExtensionManager\\ExtensionInterface;\nuse Synth\\Managers\\ExtensionManager\\ExtensionRegistrar;\n"
            . "use Synth\\Managers\\ExtensionManager\\ExtensionRoute;\nuse Synth\\Managers\\ExtensionManager\\Hooks;\n"
            . "use Synth\\Workers\\WorkerInterface;\nuse Synth\\Workers\\WorkerLimits;\nuse Synth\\Workers\\WorkerReport;\n"
            . "final class {$class} implements ExtensionInterface { public function register(ExtensionRegistrar \$r): void { {$body} } }\n");
        $state = is_file("{$this->root}/conf/extensions.json") ? json_decode((string) file_get_contents("{$this->root}/conf/extensions.json"), true) : ['enabled' => []];
        $state['enabled'][] = $id;
        file_put_contents("{$this->root}/conf/extensions.json", json_encode($state));
    }

    public function testTheHookSetCoversChevoAndKeepsTheOldNames(): void
    {
        $this->assertSame('1.1', Hooks::API_VERSION);
        foreach (['app.boot', 'api.request', 'api.response', 'game.event.received', 'dialogue.request.received', 'dialogue.listener.resolved',
            'dialogue.context.sections', 'dialogue.prompt.messages', 'dialogue.response.schema', 'dialogue.reply.parsed', 'tts.text',
            'save.created', 'save.loaded', 'dialogue.reply.completed', 'game.context.received'] as $hook) {
            $this->assertContains($hook, Hooks::all(), $hook);
        }
        $this->assertSame(['game.context.received', 'dialogue.reply.completed'], Hooks::DEFERRABLE, 'only the 1.0 events may be deferred');
        $base = ['id' => 'a', 'name' => 'A', 'version' => '1.0.0', 'entry' => 'Synth\\Extensions\\X\\E', 'autoload' => ['psr-4' => ['Synth\\Extensions\\X\\' => 'src/']]];
        $this->assertSame([], ManifestValidator::validate($base + ['api' => '^1.0'], 'a'), 'a 1.0 extension still loads');
        $this->assertSame([], ManifestValidator::validate($base + ['api' => '^1.1'], 'a'));
        $this->assertNotSame([], ManifestValidator::validate($base + ['api' => '^1.2'], 'a'));
    }

    public function testEveryFilterIsCheckedAgainstItsRule(): void
    {
        $this->install('f', '$r->filter(Hooks::TTS_TEXT, fn(array $p) => $p + []);');
        $rule = static fn(string $hook) => HookResults::ruleFor($hook);
        // tts.text: text changes, nothing else, non-empty and bounded.
        $in = ['character_key' => 'k', 'line_id' => 'l', 'text' => 'hi'];
        $this->assertTrue($rule(Hooks::TTS_TEXT)(['text' => 'hello'] + $in, $in));
        $this->assertFalse($rule(Hooks::TTS_TEXT)(['text' => ''] + $in, $in));
        $this->assertFalse($rule(Hooks::TTS_TEXT)(['text' => str_repeat('x', 1001)] + $in, $in));
        $this->assertFalse($rule(Hooks::TTS_TEXT)(['line_id' => 'other'] + $in, $in), 'ids are fixed');
        $this->assertFalse($rule(Hooks::TTS_TEXT)($in + ['extra' => 1], $in), 'no new keys');
        // dialogue.reply.parsed: an empty text drops the line.
        $line = ['request_id' => 'r', 'character_key' => 'k', 'npc_name' => 'N', 'line_index' => 0, 'text' => 'a'];
        $this->assertTrue($rule(Hooks::DIALOGUE_REPLY_PARSED)(['text' => ''] + $line, $line));
        $this->assertFalse($rule(Hooks::DIALOGUE_REPLY_PARSED)(['line_index' => 5] + $line, $line));
        // listeners
        $listen = ['listener' => 'A, B', 'listeners' => ['A', 'B']];
        $this->assertTrue($rule(Hooks::DIALOGUE_LISTENER_RESOLVED)(['listeners' => ['A']] + $listen, $listen));
        $this->assertFalse($rule(Hooks::DIALOGUE_LISTENER_RESOLVED)(['listeners' => [' ']] + $listen, $listen));
        $this->assertFalse($rule(Hooks::DIALOGUE_LISTENER_RESOLVED)(['listeners' => array_fill(0, 13, 'x')] + $listen, $listen));
        $this->assertFalse($rule(Hooks::DIALOGUE_LISTENER_RESOLVED)(['listener' => 'Z'] + $listen, $listen));
        // schema: add only, snake_case, bounded.
        $schema = ['fields' => ['message' => 'text']];
        $this->assertTrue($rule(Hooks::DIALOGUE_RESPONSE_SCHEMA)(['fields' => ['message' => 'text', 'rumor' => 'a rumor']], $schema));
        $this->assertFalse($rule(Hooks::DIALOGUE_RESPONSE_SCHEMA)(['fields' => ['message' => 'changed']], $schema));
        $this->assertFalse($rule(Hooks::DIALOGUE_RESPONSE_SCHEMA)(['fields' => ['message' => 'text', 'Bad Name' => 'x']], $schema));
        $this->assertFalse($rule(Hooks::DIALOGUE_RESPONSE_SCHEMA)(['fields' => []], $schema));
        // game event: identity fixed.
        $event = ['request_id' => 'r1', 'session_id' => 's', 'turn_id' => 't', 'type' => 'context', 'schema' => 'synth.event.v2', 'protocol_version' => 2,
            'generation' => 1, 'payload' => ['a' => 1]];
        $this->assertTrue($rule(Hooks::GAME_EVENT_RECEIVED)(['event' => ['payload' => ['a' => 2]] + $event], ['event' => $event]));
        $this->assertFalse($rule(Hooks::GAME_EVENT_RECEIVED)(['event' => ['request_id' => 'r2'] + $event], ['event' => $event]));
        $this->assertFalse($rule(Hooks::GAME_EVENT_RECEIVED)(['event' => ['type' => 'init'] + $event], ['event' => $event]));
        // api request / response
        $request = ['method' => 'GET', 'route' => 'health', 'respond' => null];
        $this->assertTrue($rule(Hooks::API_REQUEST)(['respond' => ['status' => 503, 'body' => ['ok' => false]]] + $request, $request));
        $this->assertFalse($rule(Hooks::API_REQUEST)(['route' => 'other'] + $request, $request));
        $this->assertFalse($rule(Hooks::API_REQUEST)(['respond' => ['status' => 99, 'body' => []]] + $request, $request));
        $response = ['method' => 'GET', 'route' => 'health', 'status' => 200, 'body' => ['ok' => true]];
        $this->assertTrue($rule(Hooks::API_RESPONSE)(['body' => ['ok' => true, 'x' => 1]] + $response, $response));
        $this->assertFalse($rule(Hooks::API_RESPONSE)(['status' => 100] + $response, $response));
        $this->assertFalse($rule(Hooks::API_RESPONSE)(['route' => 'x'] + $response, $response));
    }

    public function testAFilterThatBreaksItsRuleIsDiscardedAndCounted(): void
    {
        $this->install('rude', '$r->filter(Hooks::TTS_TEXT, fn(array $p) => [\'text\' => \'\'] + $p); $r->filter(Hooks::DIALOGUE_REPLY_PARSED, fn(array $p) => [\'text\' => \'shout\'] + $p);');
        $manager = $this->manager();
        $line = ['request_id' => 'r', 'character_key' => 'k', 'npc_name' => 'N', 'line_index' => 0, 'text' => 'a'];
        $this->assertSame('shout', $manager->filter(Hooks::DIALOGUE_REPLY_PARSED, $line, HookResults::ruleFor(Hooks::DIALOGUE_REPLY_PARSED))['text']);
        $tts = ['character_key' => 'k', 'line_id' => 'l', 'text' => 'hi'];
        $this->assertSame($tts, $manager->filter(Hooks::TTS_TEXT, $tts, HookResults::ruleFor(Hooks::TTS_TEXT)), 'the value continues unchanged');
        $this->assertSame(1, (new Synth\Managers\ExtensionManager\ExtensionState($this->root . '/conf/extensions.json'))->read()['failures']['rude'] ?? 0);
    }

    public function testOnlyTheOldEventsAreDeferredTheOthersRunInline(): void
    {
        $sink = new class implements DeferredEventSink {
            public array $deferred = [];
            public function defer(string $extensionId, string $hook, array $payload): void { $this->deferred[] = $hook; }
        };
        $this->install('e', '$r->on(Hooks::SAVE_CREATED, function (array $p): void { $GLOBALS[\'exthost_seen\'][] = \'created\'; }); $r->on(Hooks::DIALOGUE_REPLY_COMPLETED, function (array $p): void { $GLOBALS[\'exthost_seen\'][] = \'completed\'; });');
        $GLOBALS['exthost_seen'] = [];
        $manager = $this->manager($sink);
        $manager->emit(Hooks::SAVE_CREATED, ['playthrough_id' => 'p']);
        $manager->emit(Hooks::DIALOGUE_REPLY_COMPLETED, ['request_id' => 'r']);
        $this->assertSame(['created'], $GLOBALS['exthost_seen'], 'save.created ran inline');
        $this->assertSame([Hooks::DIALOGUE_REPLY_COMPLETED], $sink->deferred, 'reply.completed went to the sink');
        unset($GLOBALS['exthost_seen']);
    }

    public function testRoutesAreServedFromTheRegistryAndIsolated(): void
    {
        $body = '$r->route(\'GET\', \'notes/{name}\', fn(ExtensionRoute $x) => [\'name\' => $x->parameters[\'name\'], \'q\' => $x->query]);'
            . '$r->route(\'POST\', \'notes/{name}\', fn(ExtensionRoute $x) => [\'status\' => 201, \'body\' => [\'got\' => $x->body]]);'
            . '$r->route(\'GET\', \'boom\', function (ExtensionRoute $x) { throw new \\RuntimeException(\'secret detail\'); });'
            . '$r->route(\'GET\', \'bad\', fn(ExtensionRoute $x) => \'not an array\');';
        $this->install('notes', $body, ['permissions' => ['http.routes'], 'provides' => ['routes' => ['GET notes/{name}', 'POST notes/{name}', 'GET boom', 'GET bad']]]);
        $m = $this->manager();
        $ok = $m->handleRoute('notes', 'GET', 'notes/abc', ['a' => '1']);
        $this->assertSame([200, ['name' => 'abc', 'q' => ['a' => '1']]], [$ok['status'], $ok['body']]);
        $created = $m->handleRoute('notes', 'POST', 'notes/abc', [], ['x' => 1]);
        $this->assertSame([201, ['got' => ['x' => 1]]], [$created['status'], $created['body']]);
        $this->assertSame(404, $m->handleRoute('notes', 'GET', 'nothing/here')['status']);
        $this->assertSame(404, $m->handleRoute('missing', 'GET', 'notes/abc')['status'], 'an id outside the registry never loads');
        $this->assertSame(404, $m->handleRoute('../etc', 'GET', 'x')['status']);
        $this->assertSame(404, $m->handleRoute('notes', 'GET', 'notes/a b')['status'], 'a segment must be a plain name');
        $wrong = $m->handleRoute('notes', 'DELETE', 'notes/abc');
        $this->assertSame([405, ['GET', 'POST']], [$wrong['status'], $wrong['allow']]);
        $boom = $m->handleRoute('notes', 'GET', 'boom');
        $this->assertSame(500, $boom['status']);
        $this->assertStringNotContainsString('secret', json_encode($boom), 'exception text never reaches the client');
        $this->assertSame(500, $m->handleRoute('notes', 'GET', 'bad')['status'], 'a handler must return an array');
        $this->assertSame(2, (new Synth\Managers\ExtensionManager\ExtensionState($this->root . '/conf/extensions.json'))->read()['failures']['notes']);
    }

    public function testRoutesRepeatedFailuresDisableTheExtensionLikeHooks(): void
    {
        $this->install('flaky', '$r->route(\'GET\', \'x\', function (ExtensionRoute $x) { throw new \\RuntimeException(\'no\'); });',
            ['permissions' => ['http.routes'], 'provides' => ['routes' => ['GET x']]]);
        $m = $this->manager();
        for ($i = 0; $i < ExtensionManager::FAILURE_LIMIT; $i++) $m->handleRoute('flaky', 'GET', 'x');
        $this->assertSame(404, $m->handleRoute('flaky', 'GET', 'x')['status']);
        $this->assertSame([], (new Synth\Managers\ExtensionManager\ExtensionState($this->root . '/conf/extensions.json'))->read()['enabled']);
    }

    public function testAnUndeclaredRouteOrWorkerStopsTheExtensionFromLoading(): void
    {
        $this->install('sneaky', '$r->route(\'GET\', \'x\', fn($x) => []);', ['permissions' => ['http.routes']]);
        $this->install('sneaky2', '$r->worker(new class implements WorkerInterface { public function name(): string { return \'w\'; } public function description(): string { return \'\'; } public function pendingUnits(): int { return 0; } public function run(WorkerLimits $l): WorkerReport { return new WorkerReport(\'w\'); } });');
        $m = $this->manager();
        $failures = $m->failures();
        $this->assertStringContainsString('undeclared_route:GET x', $failures['sneaky']);
        $this->assertStringContainsString('undeclared_worker:w', $failures['sneaky2']);
    }

    public function testWorkersAreListedFromManifestsAndRunGuarded(): void
    {
        $worker = '$r->worker(new class implements WorkerInterface { public function name(): string { return \'tick\'; } public function description(): string { return \'ticks\'; }'
            . ' public function pendingUnits(): int { return 3; } public function run(WorkerLimits $l): WorkerReport { throw new \\RuntimeException(\'x\'); } });';
        $this->install('ticker', $worker, ['provides' => ['workers' => ['tick']]]);
        $m = $this->manager();
        $this->assertSame(['ticker:tick'], $m->workerNames());
        $this->assertSame(['ticker:tick'], WorkerRegistry::extensionWorkers($this->root));
        $guarded = $m->createWorker('ticker:tick');
        $this->assertInstanceOf(GuardedWorker::class, $guarded);
        $this->assertSame(['ticker:tick', 3], [$guarded->name(), $guarded->pendingUnits()]);
        $report = $guarded->run(new WorkerLimits(1, 5));
        $this->assertSame('failed', $report->status(), 'an exception becomes a failed report');
        $this->assertSame(1, (new Synth\Managers\ExtensionManager\ExtensionState($this->root . '/conf/extensions.json'))->read()['failures']['ticker']);
        foreach (['ticker:other', 'nope:tick', 'ticker', '../x:tick'] as $bad) {
            try { $m->createWorker($bad); $this->fail($bad); } catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $this->assertNotContains('ticker:tick', WorkerRegistry::names(), 'the built-in registry reads the real server root, not this one');
    }

    public function testManifestCapabilitiesAreValidated(): void
    {
        $base = ['id' => 'a', 'name' => 'A', 'version' => '1.0.0', 'api' => '^1.1', 'entry' => 'Synth\\Extensions\\X\\E', 'autoload' => ['psr-4' => ['Synth\\Extensions\\X\\' => 'src/']]];
        $this->assertSame([], ManifestValidator::validate($base + ['permissions' => ['http.routes', 'storage.tables'],
            'provides' => ['routes' => ['GET a/{b}'], 'workers' => ['sync-things']]], 'a'));
        $errors = implode("\n", ManifestValidator::validate($base + ['permissions' => ['root'], 'provides' => ['routes' => ['GET /x', 'FETCH y'], 'workers' => ['Bad'], 'tables' => []]], 'a'));
        foreach (['unknown permission: root', 'provides.routes: bad entry GET /x', 'provides.routes: bad entry FETCH y', 'provides.workers: bad name', 'provides: unknown field tables'] as $needle) {
            $this->assertStringContainsString($needle, $errors);
        }
        $this->assertContains('provides.routes needs the http.routes permission', ManifestValidator::validate($base + ['provides' => ['routes' => ['GET x']]], 'a'));
    }

    public function testTheSqlRuleKeepsAnExtensionInsideItsOwnTables(): void
    {
        $ok = [
            'CREATE TABLE ext_my_ext_notes (id bigint PRIMARY KEY, note text)',
            'CREATE TABLE IF NOT EXISTS ext_my_ext_n (id int REFERENCES ext_my_ext_notes (id))',
            'ALTER TABLE ext_my_ext_notes ADD COLUMN x int',
            'CREATE UNIQUE INDEX ext_my_ext_notes_x ON ext_my_ext_notes (x)',
            "INSERT INTO ext_my_ext_notes (id, note) VALUES (1, 'a; DROP TABLE core_npc') ON CONFLICT DO NOTHING",
            "UPDATE ext_my_ext_notes SET note = 'x' WHERE id = :id",
            'DELETE FROM ext_my_ext_notes WHERE id = 1',
            'DROP TABLE IF EXISTS ext_my_ext_notes',
        ];
        foreach ($ok as $sql) $this->assertNull(MigrationChecker::checkStatement($sql, 'my-ext', null), $sql);
        $refused = [
            'CREATE TABLE core_npc (id int)', 'CREATE TABLE ext_other_x (id int)', 'DROP TABLE core_npc', 'DROP TABLE ext_my_ext_a, core_npc',
            'CREATE FUNCTION ext_my_ext_f() RETURNS int AS $$ SELECT 1 $$ LANGUAGE sql', 'CREATE TRIGGER t BEFORE INSERT ON ext_my_ext_a EXECUTE FUNCTION f()',
            'CREATE VIEW ext_my_ext_v AS SELECT 1', 'COPY ext_my_ext_a FROM PROGRAM \'id\'', 'GRANT ALL ON ext_my_ext_a TO x', 'DO $$ BEGIN END $$',
            'TRUNCATE ext_my_ext_a', 'INSERT INTO ext_my_ext_a SELECT * FROM core_npc', 'UPDATE ext_my_ext_a SET x = (SELECT 1 FROM core_npc)',
            'DELETE FROM ext_my_ext_a USING core_npc', 'SELECT * FROM ext_my_ext_a', 'DROP TABLE public.core_npc', 'CREATE TABLE "ext_my_ext_a" (id int)',
            'DROP TABLE ext_my_ext_a; DROP TABLE core_npc', 'CREATE INDEX i ON ext_my_ext_a (x)', 'ALTER TABLE ext_my_ext_a RENAME TO core_x',
            'CREATE SCHEMA ext_my_ext_s', 'INSERT INTO pg_catalog.pg_class VALUES (1)',
        ];
        foreach ($refused as $sql) $this->assertNotNull(MigrationChecker::checkStatement($sql, 'my-ext', null), $sql);
        // Runtime calls are limited to their verbs.
        $this->assertNull(MigrationChecker::checkStatement('SELECT * FROM ext_my_ext_notes WHERE id = :id', 'my-ext', ['SELECT']));
        $this->assertNotNull(MigrationChecker::checkStatement('DELETE FROM ext_my_ext_notes', 'my-ext', ['SELECT']));
        $this->assertNotNull(MigrationChecker::checkStatement('SELECT * FROM ext_my_ext_notes n, core_npc c', 'my-ext', ['SELECT']));
        // A file is split and checked statement by statement; comments never hide anything.
        $file = MigrationChecker::checkFile("-- note\nCREATE TABLE ext_my_ext_a (id int); /* x */ CREATE TABLE core_b (id int);", 'my-ext');
        $this->assertCount(2, $file['statements']);
        $this->assertStringContainsString('statement 2', $file['problems'][0]);
        $this->assertNotSame([], MigrationChecker::checkFile("CREATE TABLE ext_my_ext_a (id int) /* unclosed", 'my-ext')['problems']);
    }

    public function testManifestWithABadMigrationFileCannotBeEnabled(): void
    {
        $this->install('mig', '', ['permissions' => ['storage.tables']]);
        mkdir("{$this->root}/extensions/mig/migrations");
        file_put_contents("{$this->root}/extensions/mig/migrations/0001_bad.sql", 'CREATE TABLE core_npc_copy (id int);');
        file_put_contents("{$this->root}/extensions/mig/migrations/notes.txt.sql", 'CREATE TABLE ext_mig_a (id int);');
        $found = $this->manager()->discover()['mig'];
        $this->assertNotSame([], $found['errors']);
        $this->assertStringContainsString('0001_bad', implode("\n", $found['errors']));
        $this->assertStringContainsString('migration file name not allowed', implode("\n", $found['errors']));
        try { $this->manager()->enable('mig'); $this->fail('enable must refuse'); } catch (InvalidArgumentException $error) { $this->assertStringContainsString('invalid_manifest', $error->getMessage()); }
    }
}
