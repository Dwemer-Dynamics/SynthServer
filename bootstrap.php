<?php

namespace Synth;

use Synth\Core\CharacterRepository;
use Synth\Core\NarratorOutput;
use Synth\Core\PrivateNarratorRepository;
use Synth\Core\SaveRepository;
use Synth\Core\SettingsRepository;
use Synth\Core\TurnOutput;
use Synth\Core\UtteranceRepository;
use Synth\Drivers\Llm\OpenAiCompatibleDriver;
use Synth\Drivers\Tts\NarratorSpeechDriver;
use Synth\Drivers\Tts\ProfileSpeechDriver;
use Synth\Managers\CharacterManager;
use Synth\Managers\ContextManager;
use Synth\Managers\DialogueManager;
use Synth\Managers\PrivateNarratorManager;
use Synth\Managers\PromptManager;
use Synth\Managers\SaveState;
use Synth\Managers\SessionManager;
use Synth\Managers\SpeechManager;

require_once __DIR__.'/lib/postgresql.class.php';
require_once __DIR__.'/lib/settings.php';
require_once __DIR__.'/lib/core/llm_connector.class.php';
require_once __DIR__.'/managers/DialogueManager.php';
require_once __DIR__.'/managers/PrivateNarratorManager.php';
require_once __DIR__.'/core/dbmanager/repositories/CharacterRepository.php';
require_once __DIR__.'/core/dbmanager/repositories/SettingsRepository.php';
require_once __DIR__.'/drivers/llm/OpenAiCompatibleDriver.php';
require_once __DIR__.'/drivers/tts/ProfileSpeechDriver.php';

// Composition root of the native dialogue API: server configuration, the shared PostgreSQL connection and the
// dialogue services. Configuration is read into this object; it writes no runtime globals.
final class Bootstrap
{
    private function __construct(public readonly string $root, public readonly array $config, public readonly \sql $db) {}

    public static function boot(string $root): self
    {
        $config = self::configuration($root);
        if (($config['DBDRIVER'] ?? '') !== 'postgresql') throw new \RuntimeException('PostgreSQL is not configured');
        return new self($root, $config, new \sql($config));
    }

    // conf.sample.php then conf.php in one isolated scope; only upper-case configuration names are kept.
    private static function configuration(string $root): array
    {
        $files = array_values(array_filter([$root.'/conf/conf.sample.php', $root.'/conf/conf.php'], 'is_file'));
        $config = (static function (array $__files): array {
            foreach ($__files as $__file) require $__file;
            unset($__files, $__file);
            return get_defined_vars();
        })($files);
        return array_filter($config, static fn($name) => is_string($name) && preg_match('/^[A-Z0-9_]+$/', $name) === 1,
            ARRAY_FILTER_USE_KEY);
    }

    /** @param \Closure $legacy scoped legacy adapter call (see synth_api_v2_legacy_scope) */
    public function dialogue(TurnOutput $output, \SYNTHClaimedRequest $owner, \Closure $legacy): DialogueManager
    {
        $saves = new SaveState(new SaveRepository($this->db));
        // Shared connector normalization and API badge lookup; credentials stay inside the driver call.
        $connectors = new \LLMConnector();
        return new DialogueManager(new SessionManager($saves), new CharacterManager(new CharacterRepository($this->db)),
            new ContextManager($saves), new PromptManager(),
            new OpenAiCompatibleDriver($output->assertActive(...),
                static fn(array $row): array => $legacy(fn() => $connectors->dialogueProviderConfig($row))),
            new SpeechManager(new ProfileSpeechDriver($this->db, $this->root, $this->speechConfig(), $owner, $legacy)),
            new UtteranceRepository($this->db, $owner), $output);
    }

    /** Private narrator requests: the same claimed owner, LLM driver family and legacy adapter scope. */
    public function privateNarrator(NarratorOutput $output, \SYNTHClaimedRequest $owner, \Closure $legacy): PrivateNarratorManager
    {
        $connectors = new \LLMConnector();
        $event = $owner->event;
        return new PrivateNarratorManager(new PrivateNarratorRepository($this->db, $owner),
            new OpenAiCompatibleDriver($output->assertActive(...),
                static fn(array $row): array => $legacy(fn() => $connectors->dialogueProviderConfig($row))),
            new NarratorSpeechDriver($this->db, $this->root, $this->speechConfig(), $legacy),
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
