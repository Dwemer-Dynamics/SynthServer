<?php

declare(strict_types=1);

namespace Synth\Managers\ExtensionManager;

foreach (['Hooks', 'HookResults', 'ExtensionInterface', 'DeferredEventSink', 'ExtensionStoreInterface', 'ExtensionStore',
    'DatabaseExtensionStore', 'MigrationChecker', 'ExtensionDatabase', 'ExtensionMigrator', 'ExtensionContext', 'ExtensionRegistrar',
    'ExtensionRoute', 'GuardedWorker', 'ManifestValidator', 'ExtensionState', 'FileEventQueue'] as $__file) require_once __DIR__.'/'.$__file.'.php';
unset($__file);
require_once dirname(__DIR__, 2).'/workers/WorkerInterface.php';
require_once dirname(__DIR__, 2).'/workers/WorkerLimits.php';
require_once dirname(__DIR__, 2).'/workers/WorkerReport.php';

// Discovers extensions/<id>/extension.json, validates and enables them, and runs their hooks with error isolation.
// Nothing here may break a dialogue turn: the static entry points used by legacy call sites never throw, and with
// no extension enabled they return the input after one is_file() check.
final class ExtensionManager
{
    public const MAX_SECTION_LINES = 20;
    public const MAX_SECTION_CHARS = 500;
    public const MAX_SECTIONS = 16;
    // Consecutive failures after which an enabled extension is switched off; a success resets the count.
    public const FAILURE_LIMIT = 5;
    public const ID_PATTERN = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/D';

    private static ?self $shared = null;

    private bool $booted = false;
    /** @var array<string, array<string, list<callable>>> extension id => hook => handlers */
    private array $handlers = [];
    /** @var array<string, list<array{method: string, pattern: string, handler: callable}>> extension id => routes */
    private array $routes = [];
    /** @var array<string, array<string, \Synth\Workers\WorkerInterface>> extension id => worker name => worker */
    private array $workers = [];
    /** @var array<string, string> extension id => reason, for extensions enabled but not loaded */
    private array $failed = [];
    /** @var array<string, int> consecutive failures known in this process, so a clean run does not rewrite the state file */
    private array $failureCounts = [];
    private ExtensionState $state;
    /** @var callable */
    private $logger;

    public function __construct(private string $root, ?string $stateFile = null, private ?DeferredEventSink $sink = null,
        ?callable $logger = null)
    {
        $this->root = rtrim($root, '/\\');
        $this->state = new ExtensionState($stateFile ?? $this->root.'/conf/extensions.json');
        $this->logger = $logger ?? static function (string $message): void {
            require_once dirname(__DIR__, 2).'/lib/classes/Logger.php';
            \Synth\Lib\Logger::log(\Synth\Lib\Logger::WARN, 'ExtensionManager', $message);
        };
    }

    // ---- Entry points for the frozen legacy call sites. Never throw; no-op without enabled extensions. ----

    public static function instance(): self
    {
        return self::$shared ??= self::forRoot(dirname(__DIR__, 2));
    }

    // A manager for a server root. Events are deferred to the ExtensionEvents worker only when conf/extensions.json
    // sets "deferred_events": true, because nothing delivers them unless that worker is running.
    public static function forRoot(string $root, ?callable $logger = null): self
    {
        $root = rtrim($root, '/\\');
        $state = new ExtensionState($root.'/conf/extensions.json');
        $sink = $state->exists() && $state->read()['deferred_events'] ? new FileEventQueue($root.'/data/extensions/events') : null;
        return new self($root, null, $sink, $logger);
    }

    public static function useInstance(?self $manager): void
    {
        self::$shared = $manager;
    }

    // Runs the section and prompt filters over a built message list; returns it unchanged on any failure.
    // $context: character_key, npc_name, player_name, world (the turn's bounded situation values).
    public static function dialoguePrompt(array $messages, array $context): array
    {
        try { return self::instance()->applyPrompt($messages, $context); }
        catch (\Throwable) { return $messages; }
    }

    public static function publish(string $hook, array $payload): void
    {
        try { self::instance()->emit($hook, $payload); }
        catch (\Throwable) {}
    }

    // Runs a 1.1 filter hook over a payload with that hook's result rule (HookResults); never throws, and returns the
    // payload unchanged when nothing is enabled, nothing listens, or a result is refused.
    public static function filterHook(string $hook, array $payload): array
    {
        try {
            $manager = self::instance();
            if (!$manager->hasHandlers($hook)) return $payload;
            return $manager->filter($hook, $payload, HookResults::ruleFor($hook));
        } catch (\Throwable) { return $payload; }
    }

    // True when an enabled extension listens to the hook (one is_file() check when none is enabled).
    public static function wants(string $hook): bool
    {
        try { return self::instance()->hasHandlers($hook); }
        catch (\Throwable) { return false; }
    }

    // ---- Hook points ----

    // Extension sections are appended to the system message as escaped XML-style blocks, then the final message
    // list goes through dialogue.prompt.messages. Each result is checked; an invalid one is discarded.
    public function applyPrompt(array $messages, array $context): array
    {
        if (!$this->hasHandlers(Hooks::DIALOGUE_CONTEXT_SECTIONS) && !$this->hasHandlers(Hooks::DIALOGUE_PROMPT_MESSAGES)) return $messages;
        $sections = $this->filter(Hooks::DIALOGUE_CONTEXT_SECTIONS, $context + ['sections' => []], self::validSections(...))['sections'] ?? [];
        $block = '';
        foreach ($sections as $name => $lines) {
            $block .= "\n<{$name}>\n".implode("\n", array_map(static fn(string $line): string => htmlspecialchars($line,
                ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8'), $lines))."\n</{$name}>";
        }
        if ($block !== '' && ($messages[0]['role'] ?? '') === 'system') $messages[0]['content'] = strval($messages[0]['content']).$block;
        $filtered = $this->filter(Hooks::DIALOGUE_PROMPT_MESSAGES, ['messages' => $messages] + $context, self::validMessages(...));
        return $filtered['messages'] ?? $messages;
    }

    // Runs one filter through every enabled extension in id order. A handler that throws, returns a non-array or
    // returns something $accept rejects is skipped and the value continues unchanged.
    public function filter(string $hook, array $payload, ?callable $accept = null): array
    {
        foreach ($this->handlersFor($hook) as $id => $handlers) {
            foreach ($handlers as $handler) {
                try {
                    $result = $handler($payload);
                    if (!is_array($result) || ($accept !== null && !$accept($result, $payload))) {
                        $this->fail($id, $hook, 'invalid_filter_result');
                        continue;
                    }
                    $payload = $result;
                    $this->succeeded($id);
                } catch (\Throwable $error) { $this->fail($id, $hook, $error->getMessage()); }
            }
        }
        return $payload;
    }

    // Fires an event: deferred when a sink is installed, inline otherwise. Handler failures are logged and dropped.
    public function emit(string $hook, array $payload): void
    {
        if (!in_array($hook, Hooks::EVENTS, true)) return;
        foreach ($this->handlersFor($hook) as $id => $_) {
            if ($this->sink === null || !in_array($hook, Hooks::DEFERRABLE, true)) { $this->deliver($id, $hook, $payload); continue; }
            try { $this->sink->defer($id, $hook, $payload); }
            catch (\Throwable $error) { $this->fail($id, $hook, 'defer_failed: '.$error->getMessage()); }
        }
    }

    // Runs one extension's handlers for an event; also the entry point the ExtensionEvents worker calls. Returns
    // false when the extension has no enabled handler for the hook (disabled or removed since the event was queued).
    public function deliver(string $extensionId, string $hook, array $payload): bool
    {
        $handlers = $this->handlersFor($hook)[$extensionId] ?? [];
        foreach ($handlers as $handler) {
            if (!isset($this->handlers[$extensionId])) break; // switched off by an earlier failure of this event
            try { $handler($payload); $this->succeeded($extensionId); }
            catch (\Throwable $error) { $this->fail($extensionId, $hook, $error->getMessage()); }
        }
        return $handlers !== [];
    }

    // ---- Discovery and state (used by bin/extensions.php) ----

    /** @return array<string, array{manifest: ?array, errors: list<string>, enabled: bool}> */
    public function discover(): array
    {
        $found = [];
        $enabled = $this->state->read()['enabled'];
        foreach (glob($this->root.'/extensions/*/extension.json') ?: [] as $file) {
            $id = basename(dirname($file));
            $manifest = json_decode(strval(file_get_contents($file)), true);
            $errors = json_last_error() === JSON_ERROR_NONE ? ManifestValidator::validate($manifest, $id)
                : ['extension.json is not valid JSON: '.json_last_error_msg()];
            if ($errors === []) $errors = $this->migrationProblems($id, $manifest);
            $found[$id] = ['manifest' => $errors === [] ? $manifest : null, 'errors' => $errors, 'enabled' => in_array($id, $enabled, true)];
        }
        ksort($found);
        return $found;
    }

    /** @return list<string> ids the management surfaces may act on: installed folders plus ids still listed as enabled */
    public function knownIds(): array
    {
        $ids = array_merge(array_keys($this->discover()), $this->state->read()['enabled']);
        $ids = array_filter(array_map('strval', $ids), static fn(string $id): bool => preg_match(self::ID_PATTERN, $id) === 1);
        return array_values(array_unique($ids));
    }

    /**
     * One row per installed extension for the API and UI. It carries a status code, never an error message.
     *
     * @return list<array{id: string, name: string, version: string, description: string, enabled: bool, status: string, failures: int}>
     */
    public function summary(): array
    {
        $state = $this->state->read();
        $failed = $this->failures();
        $rows = [];
        foreach ($this->discover() as $id => $info) {
            $manifest = $info['manifest'] ?? [];
            $status = match (true) {
                $info['errors'] !== [] => 'invalid',
                isset($failed[$id]) => 'load_failed',
                $info['enabled'] => 'enabled',
                isset($state['auto_disabled'][$id]) => 'auto_disabled',
                default => 'disabled',
            };
            $rows[] = ['id' => (string) $id, 'name' => strval($manifest['name'] ?? $id), 'version' => strval($manifest['version'] ?? ''),
                'description' => strval($manifest['description'] ?? ''), 'enabled' => $info['enabled'], 'status' => $status,
                'failures' => $state['failures'][$id] ?? 0];
        }
        return $rows;
    }

    // Only a valid, installed extension can be enabled.
    public function enable(string $id): void
    {
        $found = $this->discover()[$id] ?? throw new \InvalidArgumentException("unknown_extension:{$id}");
        if ($found['errors'] !== []) throw new \InvalidArgumentException('invalid_manifest: '.implode('; ', $found['errors']));
        // Its own tables first: an extension whose migrations cannot be applied is not enabled.
        if ((new ExtensionMigrator($this->root))->hasMigrations($id)) {
            try { (new ExtensionMigrator($this->root))->migrate($id); }
            catch (\Throwable $error) { throw new \RuntimeException('migration_failed: '.$error->getMessage()); }
        }
        $this->state->setEnabled($id, true);
        unset($this->failureCounts[$id]);
        $this->booted = false;
    }

    public function disable(string $id): void
    {
        $this->state->setEnabled($id, false);
        $this->booted = false;
    }

    /** @return array<string, string> enabled extensions that failed to load, with the reason */
    public function failures(): array
    {
        $this->boot();
        return $this->failed;
    }

    // ---- Routes and workers (extension-provided) ----

    /**
     * Answers one request for /api/v1/ext/<id>/<path>. The id must be an enabled, loaded extension (the registry in
     * conf/extensions.json), never a name taken from the URL alone. Handler failures count against the extension.
     *
     * @param array<string, string> $query
     * @param array<string, mixed>|null $body
     * @return array{status: int, body: array<string, mixed>, allow?: list<string>}
     */
    public function handleRoute(string $id, string $method, string $path, array $query = [], ?array $body = null): array
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1 || !in_array($id, $this->state->read()['enabled'], true)) {
            return ['status' => 404, 'body' => ['ok' => false, 'error' => 'Route not found']];
        }
        $this->boot();
        $segments = $path === '' ? [] : explode('/', trim($path, '/'));
        $allowed = [];
        foreach ($this->routes[$id] ?? [] as $route) {
            $parameters = self::matchPattern($route['pattern'], $segments);
            if ($parameters === null) continue;
            if ($route['method'] !== strtoupper($method)) { $allowed[] = $route['method']; continue; }
            try {
                $result = ($route['handler'])(new ExtensionRoute(strtoupper($method), implode('/', $segments), $parameters, $query, $body));
                $answer = self::routeAnswer($result);
                if ($answer === null) throw new \UnexpectedValueException('invalid_route_result');
                $this->succeeded($id);
                return $answer;
            } catch (\Throwable $error) {
                $this->fail($id, 'route', $error->getMessage());
                return ['status' => 500, 'body' => ['ok' => false, 'error' => 'Extension route failed']];
            }
        }
        if ($allowed !== []) return ['status' => 405, 'body' => ['ok' => false, 'error' => 'Method not allowed'], 'allow' => array_values(array_unique($allowed))];
        return ['status' => 404, 'body' => ['ok' => false, 'error' => 'Route not found']];
    }

    /** @return list<string> "<id>:<name>" of every worker the enabled, valid extensions declare (read from manifests; nothing is loaded) */
    public function workerNames(): array
    {
        $names = [];
        foreach ($this->state->read()['enabled'] as $id) {
            if (preg_match(self::ID_PATTERN, $id) !== 1) continue;
            $file = $this->root.'/extensions/'.$id.'/extension.json';
            $manifest = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            if (!is_array($manifest) || ManifestValidator::validate($manifest, $id) !== []) continue;
            foreach ($manifest['provides']['workers'] ?? [] as $worker) $names[] = $id.':'.$worker;
        }
        sort($names);
        return $names;
    }

    /** The guarded worker for "<id>:<name>"; loads that extension only when called. */
    public function createWorker(string $full): \Synth\Workers\WorkerInterface
    {
        if (preg_match('/^([a-z][a-z0-9]*(?:-[a-z0-9]+)*):([a-z][a-z0-9]*(?:-[a-z0-9]+)*)$/D', $full, $m) !== 1 || !in_array($full, $this->workerNames(), true)) {
            throw new \InvalidArgumentException('unknown_extension_worker');
        }
        $this->boot();
        $worker = $this->workers[$m[1]][$m[2]] ?? throw new \InvalidArgumentException('unknown_extension_worker');
        return new GuardedWorker($m[1], $worker, fn(string $class) => $this->fail($m[1], 'worker', $class), fn() => $this->succeeded($m[1]));
    }

    // ---- Internals ----

    /** @return list<string> */
    private function migrationProblems(string $id, array $manifest): array
    {
        $migrator = new ExtensionMigrator($this->root);
        if (!$migrator->hasMigrations($id)) return [];
        $problems = $migrator->fileProblems($id);
        if (!in_array('storage.tables', $manifest['permissions'] ?? [], true)) $problems[] = 'migrations need the storage.tables permission';
        return $problems;
    }

    /** @return array<string, string>|null the {name} values when the segments match the pattern */
    private static function matchPattern(string $pattern, array $segments): ?array
    {
        $parts = explode('/', $pattern);
        if (count($parts) !== count($segments)) return null;
        $values = [];
        foreach ($parts as $i => $part) {
            if (preg_match('/^\{([a-z][a-z0-9_]*)\}$/D', $part, $m) === 1) {
                if (preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', $segments[$i]) !== 1) return null;
                $values[$m[1]] = $segments[$i];
            } elseif ($part !== $segments[$i]) return null;
        }
        return $values;
    }

    /** @return array{status: int, body: array}|null */
    private static function routeAnswer(mixed $result): ?array
    {
        if (!is_array($result)) return null;
        if (array_key_exists('body', $result) && is_array($result['body']) && array_diff(array_keys($result), ['status', 'body']) === []) {
            $status = $result['status'] ?? 200;
            $body = $result['body'];
        } else { $status = 200; $body = $result; }
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_int($status) || $status < 200 || $status > 599 || !is_string($json) || strlen($json) > HookResults::MAX_BODY_BYTES) return null;
        return ['status' => $status, 'body' => $body];
    }

    private function hasHandlers(string $hook): bool
    {
        return $this->handlersFor($hook) !== [];
    }

    /** @return array<string, list<callable>> */
    private function handlersFor(string $hook): array
    {
        $this->boot();
        $out = [];
        foreach ($this->handlers as $id => $byHook) if (!empty($byHook[$hook])) $out[$id] = $byHook[$hook];
        return $out;
    }

    private function boot(): void
    {
        if ($this->booted) return;
        $this->booted = true;
        $this->handlers = [];
        $this->routes = [];
        $this->workers = [];
        $this->failed = [];
        if (!$this->state->exists()) return;
        $state = $this->state->read();
        $this->failureCounts = $state['failures'];
        $ids = $state['enabled'];
        sort($ids);
        foreach ($ids as $id) {
            try {
                $loaded = $this->load($id, $state['settings'][$id] ?? []);
                $this->handlers[$id] = $loaded['handlers'];
                $this->routes[$id] = $loaded['routes'];
                $this->workers[$id] = $loaded['workers'];
            }
            catch (\Throwable $error) {
                $this->failed[$id] = $error->getMessage();
                $this->fail($id, 'load', $error->getMessage());
            }
        }
    }

    private function load(string $id, array $values): array
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) throw new \InvalidArgumentException('invalid_id');
        $dir = $this->root.'/extensions/'.$id;
        $json = is_file($dir.'/extension.json') ? file_get_contents($dir.'/extension.json') : false;
        if ($json === false) throw new \RuntimeException('extension_missing');
        $manifest = json_decode($json, true);
        $errors = ManifestValidator::validate($manifest, $id);
        if ($errors !== []) throw new \InvalidArgumentException('invalid_manifest: '.implode('; ', $errors));
        foreach ($manifest['autoload']['psr-4'] as $prefix => $folder) self::autoload($prefix, $dir.'/'.trim($folder, '/'));
        $entry = $manifest['entry'];
        if (!class_exists($entry) || !is_subclass_of($entry, ExtensionInterface::class)) throw new \RuntimeException('entry_class_invalid');
        $legacyFile = $this->root.'/data/extensions/'.$id.'.json';
        // The store connects on first use: the database store when a session exists, else the old file store.
        // Only the real server root uses the shared database; a manager over another root (tests, tools) keeps its files.
        $realRoot = realpath($this->root) === realpath(dirname(__DIR__, 2));
        $store = static function () use ($id, $legacyFile, $realRoot): ExtensionStoreInterface {
            try {
                if (!$realRoot) return new ExtensionStore($legacyFile);
                return new DatabaseExtensionStore($id, \Synth\Core\DbManager\Dao\ExtensionDataDao::current(), $legacyFile);
            }
            catch (\Throwable) { return new ExtensionStore($legacyFile); }
        };
        $context = new ExtensionContext($id, $manifest['settings'] ?? [], $values, $store, $manifest['permissions'] ?? []);
        $registrar = new ExtensionRegistrar($context, $manifest['hooks'] ?? [], $manifest['provides'] ?? []);
        (new $entry())->register($registrar);
        return ['handlers' => $registrar->handlers(), 'routes' => $registrar->routes(), 'workers' => $registrar->workers()];
    }

    private static function autoload(string $prefix, string $folder): void
    {
        spl_autoload_register(static function (string $class) use ($prefix, $folder): void {
            if (!str_starts_with($class, $prefix)) return;
            $file = $folder.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) require_once $file;
        });
    }

    private function fail(string $id, string $hook, string $reason): void
    {
        try { ($this->logger)("[extensions] {$id} failed at {$hook}: ".mb_substr($reason, 0, 300)); }
        catch (\Throwable) {}
        try {
            $count = $this->state->recordFailure($id, "{$hook}: {$reason}", self::FAILURE_LIMIT);
            $this->failureCounts[$id] = $count;
            if ($count >= self::FAILURE_LIMIT) {
                unset($this->handlers[$id], $this->routes[$id], $this->workers[$id], $this->failureCounts[$id]);
                ($this->logger)("[extensions] {$id} disabled after ".self::FAILURE_LIMIT.' failures');
            }
        } catch (\Throwable) {}
    }

    // A clean run resets the extension's failure count; the file is only touched when a count was recorded.
    private function succeeded(string $id): void
    {
        if (!isset($this->failureCounts[$id])) return;
        try { $this->state->clearFailures($id); unset($this->failureCounts[$id]); }
        catch (\Throwable) {}
    }

    // Accepts a result whose `sections` are name => bounded list of strings; the surrounding context is kept as sent.
    private static function validSections(array $result): bool
    {
        $sections = $result['sections'] ?? null;
        if (!is_array($sections) || count($sections) > self::MAX_SECTIONS) return false;
        foreach ($sections as $name => $lines) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,40}$/D', $name) !== 1
                || !is_array($lines) || !array_is_list($lines) || count($lines) > self::MAX_SECTION_LINES) return false;
            foreach ($lines as $line) if (!is_string($line) || mb_strlen($line) > self::MAX_SECTION_CHARS) return false;
        }
        return true;
    }

    // Accepts a non-empty list of role/content messages with only chat roles.
    private static function validMessages(array $result): bool
    {
        $messages = $result['messages'] ?? null;
        if (!is_array($messages) || $messages === [] || !array_is_list($messages)) return false;
        foreach ($messages as $message) {
            if (!is_array($message) || !in_array($message['role'] ?? null, ['system', 'user', 'assistant'], true)
                || !is_string($message['content'] ?? null)) return false;
        }
        return true;
    }
}
