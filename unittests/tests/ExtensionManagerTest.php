<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Synth\Managers\ExtensionManager\ExtensionManager;
use Synth\Managers\ExtensionManager\Hooks;
use Synth\Managers\ExtensionManager\ManifestValidator;

require_once dirname(__DIR__, 2) . '/managers/ExtensionManager/ExtensionManager.php';

// Extension host: manifest validation, error-isolated hooks, and the no-op path with nothing installed.
final class ExtensionManagerTest extends TestCase
{
    private string $root;
    private array $log = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/synth-ext-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/extensions', 0777, true);
        $this->log = [];
    }

    protected function tearDown(): void
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    private function manager(): ExtensionManager
    {
        return new ExtensionManager($this->root, null, null, function (string $m): void { $this->log[] = $m; });
    }

    private function install(string $id, string $class, string $body, array $manifest = []): void
    {
        $dir = "{$this->root}/extensions/{$id}";
        mkdir("{$dir}/src", 0777, true);
        $ns = 'Synth\\Extensions\\T' . md5($id . $class);
        file_put_contents("{$dir}/extension.json", json_encode($manifest + ['id' => $id, 'name' => $id, 'version' => '1.0.0', 'api' => '^1.0',
            'entry' => "{$ns}\\{$class}", 'autoload' => ['psr-4' => ["{$ns}\\" => 'src/']],
            'hooks' => [Hooks::DIALOGUE_CONTEXT_SECTIONS, Hooks::DIALOGUE_PROMPT_MESSAGES, Hooks::DIALOGUE_REPLY_COMPLETED]]));
        file_put_contents("{$dir}/src/{$class}.php", "<?php declare(strict_types=1);\nnamespace {$ns};\n"
            . "use Synth\\Managers\\ExtensionManager\\ExtensionInterface;\nuse Synth\\Managers\\ExtensionManager\\ExtensionRegistrar;\nuse Synth\\Managers\\ExtensionManager\\Hooks;\n"
            . "final class {$class} implements ExtensionInterface { public function register(ExtensionRegistrar \$r): void { {$body} } }\n");
    }

    public function testValidManifestAndEveryProblemReportedAtOnce(): void
    {
        $ok = ['id' => 'a-b', 'name' => 'A', 'version' => '1.0.0', 'api' => '^1.0', 'entry' => 'Synth\\Extensions\\X\\E',
            'autoload' => ['psr-4' => ['Synth\\Extensions\\X\\' => 'src/']], 'hooks' => [Hooks::GAME_CONTEXT_RECEIVED],
            'settings' => ['n' => ['type' => 'integer', 'default' => 1]]];
        $this->assertSame([], ManifestValidator::validate($ok, 'a-b'));
        $bad = ['id' => 'Bad', 'name' => '', 'version' => '1', 'api' => '^2.0', 'entry' => 'x', 'autoload' => ['psr-4' => ['Other\\' => '../x']],
            'hooks' => ['nope'], 'extra' => 1, 'settings' => ['n' => ['type' => 'integer', 'default' => 'x']]];
        $errors = implode("\n", ManifestValidator::validate($bad, 'bad'));
        foreach (['unknown field: extra', 'id must be', 'name must be', 'SemVer', 'api must', 'entry must', 'autoload prefix', 'autoload folder', 'unknown hook: nope', 'default is missing'] as $needle) {
            $this->assertStringContainsString($needle, $errors);
        }
        $this->assertContains('id must equal the folder name (other)', ManifestValidator::validate($ok, 'other'));
        $this->assertNotSame([], ManifestValidator::validate(['id' => 'a'], 'a'));
    }

    public function testNothingInstalledIsANoOp(): void
    {
        $manager = $this->manager();
        $messages = [['role' => 'system', 'content' => 's'], ['role' => 'user', 'content' => 'hi']];
        $this->assertSame($messages, $manager->applyPrompt($messages, ['npc_name' => 'N']));
        $manager->emit(Hooks::DIALOGUE_REPLY_COMPLETED, ['x' => 1]);
        $this->assertSame([], $manager->discover());
        $this->assertSame([], $manager->failures());
        $this->assertFalse(file_exists($this->root . '/conf/extensions.json'), 'running hooks must not create state');
        $this->assertSame([], $this->log);
    }

    public function testInstalledButDisabledDoesNothing(): void
    {
        $this->install('quiet', 'Quiet', '$r->filter(Hooks::DIALOGUE_PROMPT_MESSAGES, fn(array $p): array => throw new \\LogicException("ran"));');
        $manager = $this->manager();
        $messages = [['role' => 'user', 'content' => 'u']];
        $this->assertSame($messages, $manager->applyPrompt($messages, []));
        $this->assertFalse($manager->discover()['quiet']['enabled']);
    }

    public function testEnabledExtensionAddsSectionAndFiltersMessages(): void
    {
        $this->install('good', 'Good', '$r->filter(Hooks::DIALOGUE_CONTEXT_SECTIONS, function (array $p): array { $p["sections"]["extra"] = ["a <b> line"]; return $p; });'
            . '$r->filter(Hooks::DIALOGUE_PROMPT_MESSAGES, function (array $p): array { $p["messages"][] = ["role" => "assistant", "content" => "x"]; return $p; });');
        $manager = $this->manager();
        $manager->enable('good');
        $out = $manager->applyPrompt([['role' => 'system', 'content' => 'S'], ['role' => 'user', 'content' => 'u']], ['npc_name' => 'N']);
        $this->assertSame("S\n<extra>\na &lt;b&gt; line\n</extra>", $out[0]['content']);
        $this->assertSame('assistant', $out[2]['role']);
        $manager->disable('good');
        $messages = [['role' => 'user', 'content' => 'u']];
        $this->assertSame($messages, $manager->applyPrompt($messages, []));
    }

    public function testFailingAndInvalidExtensionsAreIsolated(): void
    {
        $this->install('boom', 'Boom', '$r->filter(Hooks::DIALOGUE_PROMPT_MESSAGES, fn(array $p): array => throw new \\RuntimeException("kaboom"));'
            . '$r->on(Hooks::DIALOGUE_REPLY_COMPLETED, fn(array $p) => throw new \\RuntimeException("event boom"));');
        $this->install('junk', 'Junk', '$r->filter(Hooks::DIALOGUE_PROMPT_MESSAGES, fn(array $p): array => ["messages" => "not a list"]);');
        $this->install('okay', 'Okay', '$r->on(Hooks::DIALOGUE_REPLY_COMPLETED, function (array $p): void { file_put_contents(' . var_export($this->root . '/seen', true) . ', $p["x"]); });');
        $this->install('undeclared', 'Undeclared', '$r->on(Hooks::GAME_CONTEXT_RECEIVED, fn(array $p) => null);');
        $manager = $this->manager();
        foreach (['boom', 'junk', 'okay', 'undeclared'] as $id) $manager->enable($id);
        $messages = [['role' => 'user', 'content' => 'u']];
        $this->assertSame($messages, $manager->applyPrompt($messages, []));
        $manager->emit(Hooks::DIALOGUE_REPLY_COMPLETED, ['x' => 'delivered']);
        $this->assertSame('delivered', file_get_contents($this->root . '/seen'), 'a failing sibling must not stop other extensions');
        $this->assertArrayHasKey('undeclared', $manager->failures());
        $this->assertStringContainsString('undeclared_hook', $manager->failures()['undeclared']);
        $log = implode("\n", $this->log);
        $this->assertStringContainsString('kaboom', $log);
        $this->assertStringContainsString('invalid_filter_result', $log);
        // The static legacy entry points never throw, even for an unusable root.
        ExtensionManager::useInstance(new ExtensionManager('/nonexistent/' . bin2hex(random_bytes(3))));
        $this->assertSame($messages, ExtensionManager::dialoguePrompt($messages, []));
        ExtensionManager::publish(Hooks::GAME_CONTEXT_RECEIVED, []);
        ExtensionManager::useInstance(null);
    }

    public function testInvalidManifestCannotBeEnabled(): void
    {
        $this->install('wrong', 'Wrong', '', ['api' => '^9.0']);
        $this->expectException(InvalidArgumentException::class);
        $this->manager()->enable('wrong');
    }

    public function testReferenceExtensionRecordsTalkAndAddsSection(): void
    {
        $real = new ExtensionManager(dirname(__DIR__, 2), $this->root . '/state.json');
        $this->assertArrayHasKey('talk-awareness', $real->discover());
        $this->assertSame([], $real->discover()['talk-awareness']['errors']);
        // Run a temp copy so the repository's data/ stays untouched.
        $copy = $this->root . '/extensions/talk-awareness';
        mkdir($copy . '/src', 0777, true);
        foreach (['extension.json', 'src/TalkAwarenessExtension.php'] as $f) copy(dirname(__DIR__, 2) . '/extensions/talk-awareness/' . $f, "{$copy}/{$f}");
        $manager = $this->manager();
        $manager->enable('talk-awareness');
        $context = ['character_key' => 'chr:1', 'player_name' => 'Nate', 'npc_name' => 'Piper', 'world' => ['location' => 'Diamond City']];
        $first = $manager->applyPrompt([['role' => 'system', 'content' => 'S']], $context);
        $this->assertStringContainsString('first time you have spoken with Nate', $first[0]['content']);
        $manager->emit(Hooks::DIALOGUE_REPLY_COMPLETED, $context);
        $again = $manager->applyPrompt([['role' => 'system', 'content' => 'S']], $context);
        $this->assertStringContainsString('most recently at Diamond City', $again[0]['content']);
        $this->assertSame([], $this->log);
    }
}
