<?php

namespace Synth;

use Synth\Core\CharacterRepository;
use Synth\Core\NarratorOutput;
use Synth\Core\PrivateNarratorRepository;
use Synth\Core\SaveRepository;
use Synth\Core\SettingsRepository;
use Synth\Core\TurnOutput;
use Synth\Core\UtteranceRepository;
use Synth\Drivers\Llm\LlmWrapper;
use Synth\Drivers\Llm\OpenAiCompatibleDriver;
use Synth\Drivers\Tts\NarratorSpeechDriver;
use Synth\Drivers\Tts\ProfileSpeechDriver;
use Synth\Drivers\Tts\TtsWrapper;
use Synth\Managers\CharacterManager;
use Synth\Managers\ContextManager;
use Synth\Managers\DialogueManager;
use Synth\Managers\PrivateNarratorManager;
use Synth\Managers\PromptManager;
use Synth\Managers\ReceiptManager;
use Synth\Managers\SaveState;
use Synth\Managers\SessionManager;
use Synth\Managers\SpeechManager;
use Synth\Managers\WorldKnowledgeManager;

require_once __DIR__.'/lib/autoload.php';

// The one composition root: server configuration, the shared PostgreSQL connection and the dialogue services.
// boot() (native dialogue API) keeps configuration in this object and writes no runtime globals; runtime() (legacy
// pages, CLI and workers, via lib/runtime_bootstrap.php) also installs the compatibility globals legacy files read.
final class Bootstrap
{
    private static ?self $current = null;
    private static bool $loaded = false;

    private function __construct(public readonly string $root, public readonly array $config, public readonly \sql $db) {}

    // Legacy files the dialogue services still need and the autoloader cannot find; loaded on first use so the
    // legacy runtime session (lib/runtime_bootstrap.php) can start through this class without pulling them in.
    private static function load(): void
    {
        if (self::$loaded) return;
        self::$loaded = true;
        require_once __DIR__.'/lib/postgresql.class.php';
        require_once __DIR__.'/lib/settings.php';
        require_once __DIR__.'/lib/core/llm_connector.class.php';
        require_once __DIR__.'/core/dbmanager/repositories/CharacterRepository.php';
        require_once __DIR__.'/core/dbmanager/repositories/SettingsRepository.php';
    }

    /** The instance the legacy runtime session started (Bootstrap::runtime), or null before it ran. */
    public static function current(): ?self
    {
        return self::$current;
    }

    public static function boot(string $root): self
    {
        self::load();
        $config = self::readConfiguration($root);
        if (($config['DBDRIVER'] ?? '') !== 'postgresql') throw new \RuntimeException('PostgreSQL is not configured');
        return new self($root, $config, new \sql($config));
    }

    /**
     * The shared database handle, or null before the runtime session started. The compatibility global $db is
     * the source of truth while legacy code (and tests) still assign it; the runtime instance is the fallback.
     */
    public static function db(): ?object
    {
        $db = $GLOBALS['db'] ?? null;
        if (is_object($db)) return $db;
        return self::$current?->db;
    }

    /** The engine root with a trailing separator (the legacy ENGINE_PATH), or null before the runtime session started. */
    public static function enginePath(): ?string
    {
        $path = $GLOBALS['ENGINE_PATH'] ?? null;
        if (is_string($path) && $path !== '') return $path;
        return self::$current?->root;
    }

    /**
     * One configuration value. A legacy global wins (settings loaded from the database are written there) unless it is
     * null, as `$GLOBALS[$name] ?? $default` did; then the values read from conf.sample.php / conf.php; then $default.
     */
    public static function config(string $name, mixed $default = null): mixed
    {
        $value = $GLOBALS[$name] ?? null;
        if ($value !== null) return $value;
        $config = self::$current?->config ?? [];
        return $config[$name] ?? $default;
    }

    /** The legacy ENGINE_ROOT (set by service entries; the engine path when they did not set it), or null. */
    public static function engineRoot(): ?string
    {
        $root = $GLOBALS['ENGINE_ROOT'] ?? null;
        if (is_string($root) && $root !== '') return $root;
        return self::enginePath();
    }

    /** The validated request event of this request (legacy SYNTH_REQUEST_EVENT), or null outside a request. */
    public static function requestEvent(): mixed
    {
        return $GLOBALS['SYNTH_REQUEST_EVENT'] ?? null;
    }

    /** The claimed protocol request this process owns (legacy SYNTH_PROTOCOL_REQUEST_CLAIMED), or null. */
    public static function claimedRequest(): mixed
    {
        return $GLOBALS['SYNTH_PROTOCOL_REQUEST_CLAIMED'] ?? null;
    }

    /** The decoded game request of the legacy pipeline (legacy gameRequest), or null. */
    public static function gameRequest(): mixed
    {
        return $GLOBALS['gameRequest'] ?? null;
    }

    // conf.sample.php then conf.php in one isolated scope, without writing runtime globals. Only upper-case
    // configuration names are kept, and anything the files print is discarded so it cannot corrupt a JSON answer.
    public static function readConfiguration(string $root): array
    {
        $files = array_values(array_filter([$root.'/conf/conf.sample.php', $root.'/conf/conf.php'], 'is_file'));
        ob_start();
        try {
            $config = (static function (array $__files): array {
                foreach ($__files as $__file) require $__file;
                unset($__files, $__file);
                return get_defined_vars();
            })($files);
        } finally {
            ob_end_clean();
        }
        return array_filter($config, static fn($name) => is_string($name) && preg_match('/^[A-Z0-9_]+$/', $name) === 1,
            ARRAY_FILTER_USE_KEY);
    }

    /**
     * The legacy runtime session (the one composition root behind lib/runtime_bootstrap.php): configuration, the
     * compatibility globals legacy files still read, the shared database handle, then the requested settings loads.
     * $options as documented on synthRuntimeBootstrap().
     */
    public static function runtime(string $enginePath, array $options = []): void
    {
        $enginePath = rtrim($enginePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $GLOBALS['ENGINE_PATH'] = $enginePath;
        \Logger::bootstrapRequestId('rt');
        // rotate_logs (default true, false for web GET/HEAD): viewing a page never renames logs; an explicit option wins.
        if ($options['rotate_logs'] ?? !synthRuntimeIsReadOnlyWebRequest()) {
            \Logger::rotateKnownLogs($enginePath);
        }

        $config = self::readConfiguration($enginePath);
        synthRuntimeImportConfigVariables($config);
        if (empty($GLOBALS['DBDRIVER'])) {
            throw new \RuntimeException('DBDRIVER is not configured during runtime bootstrap.');
        }

        require_once $enginePath.'lib'.DIRECTORY_SEPARATOR."{$GLOBALS['DBDRIVER']}.class.php";
        $needsDbConnection = !isset($GLOBALS['db']) || !($GLOBALS['db'] instanceof \sql);
        if (!$needsDbConnection) {
            try {
                $needsDbConnection = !$GLOBALS['db']->query('SELECT 1');
            } catch (\Throwable $e) {
                $needsDbConnection = true;
            }
        }
        if ($needsDbConnection && !empty($options['throw_db_connection_errors'])) {
            // Opt-in for setup entries: connect with only the SYNTH_DB_* values so an unreachable PostgreSQL throws
            // instead of the legacy constructor's plaintext exit.
            if ($GLOBALS['DBDRIVER'] !== 'postgresql') {
                throw new \RuntimeException('PostgreSQL is not configured.');
            }
            $settings = [];
            foreach ($GLOBALS as $name => $value) {
                if (is_string($name) && str_starts_with($name, 'SYNTH_DB_')) {
                    $settings[$name] = $value;
                }
            }
            $GLOBALS['db'] = new \sql($settings);
        } elseif ($needsDbConnection) {
            $GLOBALS['db'] = new \sql();
        }
        if ($GLOBALS['db'] instanceof \sql) {
            self::$current = new self($enginePath, $config, $GLOBALS['db']);
        }

        synthRuntimeApplyBootstrapOptions($enginePath, $options);
    }

    /** @param \Closure $legacy scoped legacy adapter call (see synth_api_v2_legacy_scope) */
    public function dialogue(TurnOutput $output, \SYNTHClaimedRequest $owner, \Closure $legacy): DialogueManager
    {
        self::load();
        $saves = new SaveState(new SaveRepository($this->db));
        // Shared connector normalization and API badge lookup; credentials stay inside the driver call.
        $connectors = new \LLMConnector();
        return new DialogueManager(new SessionManager($saves), new CharacterManager(new CharacterRepository($this->db)),
            new ContextManager($saves, $this->db, $owner), new PromptManager(),
            new LlmWrapper([new OpenAiCompatibleDriver($output->assertActive(...),
                static fn(array $row): array => $legacy(fn() => $connectors->dialogueProviderConfig($row)))]),
            new SpeechManager(new TtsWrapper(new ProfileSpeechDriver($this->db, $this->root, $this->speechConfig(), $owner, $legacy))),
            new UtteranceRepository($this->db, $owner, UtteranceRepository::DELIVERY_RECEIPTS), $output,
            new WorldKnowledgeManager($this->db, $this->config, new SettingsRepository($this->db)));
    }

    /** Speech receipts: only the claimed snapshot binding and the owned receipt write; no turn services. */
    public function sessions(): SessionManager
    {
        self::load();
        return new SessionManager(new SaveState(new SaveRepository($this->db)));
    }

    public function receipts(\SYNTHClaimedRequest $owner): ReceiptManager
    {
        self::load();
        return new ReceiptManager(new SessionManager(new SaveState(new SaveRepository($this->db))),
            new UtteranceRepository($this->db, $owner, UtteranceRepository::DELIVERY_RECEIPTS));
    }

    /** Private narrator requests: the same claimed owner, LLM driver family and legacy adapter scope. */
    public function privateNarrator(NarratorOutput $output, \SYNTHClaimedRequest $owner, \Closure $legacy): PrivateNarratorManager
    {
        self::load();
        $connectors = new \LLMConnector();
        $event = $owner->event;
        return new PrivateNarratorManager(new PrivateNarratorRepository($this->db, $owner),
            new LlmWrapper([new OpenAiCompatibleDriver($output->assertActive(...),
                static fn(array $row): array => $legacy(fn() => $connectors->dialogueProviderConfig($row)))]),
            new TtsWrapper(new NarratorSpeechDriver($this->db, $this->root, $this->speechConfig(), $legacy)),
            $output, fn(array $media): string => $legacy(fn() => $this->transcribe($media)),
            fn(): array => synth_protocol_original_context($this->db, $event) ?? []);
    }

    // The configured STT connector reads its legacy globals; they are installed for this one call and restored.
    private function transcribe(array $media): string
    {
        require_once __DIR__.'/lib/synth_stt_pipeline.php';
        $keys = ['STTFUNCTION', 'STT'];
        $previous = array_intersect_key($GLOBALS, array_flip($keys));
        try {
            foreach ($keys as $key) {
                unset($GLOBALS[$key]);
                if (array_key_exists($key, $this->config)) $GLOBALS[$key] = $this->config[$key];
            }
            synthLoadActiveSttConnectorIntoGlobals();
            return synth_protocol_transcribe_media($media);
        } finally {
            foreach ($keys as $key) {
                unset($GLOBALS[$key]);
                if (array_key_exists($key, $previous)) $GLOBALS[$key] = $previous[$key];
            }
        }
    }

    // Server-wide speech language and filter inputs only. A stored general setting overrides configuration, as
    // in the legacy runtime; a turn's audio filters always start empty.
    private function speechConfig(): array
    {
        $config = ['TTS_FFMPEG_FILTERS' => []];
        $language = (new SettingsRepository($this->db))->value('LANG_LLM_XTTS');
        if ($language !== null) {
            $config['LANG_LLM_XTTS'] = synthSettingsNormalizeScalar($language, synthGetSchemaDefinition('LANG_LLM_XTTS'));
        } elseif (array_key_exists('LANG_LLM_XTTS', $this->config)) {
            $config['LANG_LLM_XTTS'] = $this->config['LANG_LLM_XTTS'];
        }
        return $config;
    }
}
