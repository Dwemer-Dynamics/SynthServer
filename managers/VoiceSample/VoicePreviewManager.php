<?php

declare(strict_types=1);

// Speaks one short line with an NPC's saved voice so the dashboard can check a voice without the game.
// Nothing is saved: no speech row, memory, event or NPC change. Every preview is one provider call that may be
// charged, so one runs at a time and the text is bounded.
//
// NPC, profile and connector rows are read through the existing legacy classes (NpcMaster, CoreProfile,
// TTSConnector) until the system entity classes exist; this manager is the single place that knows that.

namespace Synth\Managers\VoiceSample;

use CoreProfile;
use NpcMaster;
use Throwable;
use TTSConnector;

final class VoicePreviewManager
{
    public const MAX_TEXT_CHARACTERS = 300;
    public const DEFAULT_TEXT = 'Patrolling the Commonwealth almost makes you wish for a Nuclear Winter.';
    private const MAX_AUDIO_BYTES = 8388608;
    private const SOCKET_TIMEOUT_SECONDS = '20';

    public function __construct(private readonly string $enginePath)
    {
    }

    /**
     * Validates preview text: 1 to 300 characters of UTF-8 without control characters other than tab and line break.
     *
     * @throws VoicePreviewException 400 when the text is not acceptable.
     */
    public function cleanText(string $text): string
    {
        $text = trim($text);
        if ($text === '' || preg_match('//u', $text) !== 1 || mb_strlen($text, 'UTF-8') > self::MAX_TEXT_CHARACTERS
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text)) {
            throw new VoicePreviewException(400, 'invalid_input', 'The text must have 1 to ' . self::MAX_TEXT_CHARACTERS . ' characters.');
        }
        return $text;
    }

    /**
     * Finds an NPC row by id, or null when there is none.
     *
     * @return array<string,mixed>|null
     */
    public function findNpc(int $npcId): ?array
    {
        $this->loadLegacyClasses();
        $npc = (new NpcMaster())->getById($npcId);
        return is_array($npc) && !empty($npc['id']) ? $npc : null;
    }

    /**
     * Lists the NPCs that have a saved voice, for the Studio picker.
     *
     * This is the one query of the feature; it moves into the NPC system entity with the schema redesign.
     *
     * @return list<array{id:int,npc_name:string,voiceid:string}>
     */
    public function listNpcsWithVoice(int $limit = 500): array
    {
        try {
            $rows = $GLOBALS['db']->fetchAll(
                "SELECT id, npc_name, voiceid FROM core_npc_master WHERE coalesce(voiceid, '') <> '' ORDER BY lower(npc_name) LIMIT "
                . max(1, min(1000, $limit))
            );
        } catch (Throwable) {
            return [];
        }
        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * Speaks $text with the saved voice of an NPC through the connector dialogue would use.
     *
     * The audio is written under a random name in soundcache, read back and deleted with the driver's request log.
     *
     * @return array{audio:string,usedFallback:bool} WAV bytes, and whether the connector's fallback voice spoke.
     * @throws VoicePreviewException with the status and fixed text the route sends.
     */
    public function preview(int $npcId, string $text): array
    {
        $text = $this->cleanText($text);
        $lock = $this->takeLock();
        $audioPath = '';
        try {
            @set_time_limit(45);
            @ini_set('default_socket_timeout', self::SOCKET_TIMEOUT_SECONDS);
            $npc = $this->findNpc($npcId) ?? throw new VoicePreviewException(404, 'unknown_npc', 'Unknown NPC.');
            $connector = $this->connectorFor($npc);
            [$voice, $usedFallback] = $this->voiceFor($npc, $connector);
            $audioPath = $this->speak($npc, $voice, $text);
            return ['audio' => $this->readAudio($audioPath), 'usedFallback' => $usedFallback];
        } catch (VoicePreviewException $failure) {
            throw $failure;
        } catch (Throwable $error) {
            // Details go to the server log without the request text or settings; the client gets fixed text.
            \Logger::error('[SYNTH voice preview] ' . get_class($error) . ': ' . substr($error->getMessage(), 0, 200));
            throw new VoicePreviewException(502, 'provider_failed', 'The voice preview failed. Check the connector in TTS Connectors.');
        } finally {
            $this->removeFiles($audioPath);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Takes the one-preview-at-a-time lock without waiting.
     *
     * @return resource
     * @throws VoicePreviewException 429 while another preview runs.
     */
    private function takeLock()
    {
        $directory = $this->enginePath . 'data' . DIRECTORY_SEPARATOR . 'tmp';
        $directory = is_dir($directory) && is_writable($directory) ? $directory : sys_get_temp_dir();
        $lock = @fopen($directory . DIRECTORY_SEPARATOR . 'synth-voice-preview.lock', 'c');
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new VoicePreviewException(429, 'busy', 'Another voice preview is running. Try again in a moment.');
        }
        return $lock;
    }

    /**
     * Selects the connector dialogue would use (the NPC's profile first, then the preferred default) and applies it.
     *
     * @param array<string,mixed> $npc
     * @return array<string,mixed> the connector row.
     * @throws VoicePreviewException 422 when no usable TTS connector exists.
     */
    private function connectorFor(array $npc): array
    {
        $connectors = new TTSConnector();
        $connector = null;
        $profileId = (int) ($npc['profile_id'] ?? 0);
        if ($profileId > 0) {
            $profile = (new CoreProfile())->getById($profileId);
            $connectorId = is_array($profile) ? (int) ($profile['tts_connector_id'] ?? 0) : 0;
            $connector = $connectorId > 0 ? $connectors->getById($connectorId) : null;
        }
        if (!is_array($connector)) {
            $connector = synthResolvePreferredTtsConnectorRow();
        }
        $driver = is_array($connector) ? strtolower(trim((string) ($connector['driver'] ?? ''))) : '';
        if ($driver === '' || $driver === 'none') {
            throw new VoicePreviewException(422, 'tts_not_configured', 'No TTS connector is configured for this NPC. Set one up in TTS Connectors first.');
        }
        $connectors->setOldGlobals($connector);
        $function = (string) ($GLOBALS['TTSFUNCTION'] ?? '');
        if (preg_match('/^[a-z0-9_-]+$/Di', $function) !== 1 || !is_file($this->enginePath . 'tts' . DIRECTORY_SEPARATOR . 'tts-' . $function . '.php')) {
            throw new VoicePreviewException(422, 'tts_not_configured', 'The TTS connector for this NPC is not available.');
        }
        return $connector;
    }

    /**
     * Resolves the voice to speak: the NPC's saved voice, or the connector's fallback voice as dialogue does.
     *
     * @param array<string,mixed> $npc
     * @param array<string,mixed> $connector
     * @return array{0:string,1:bool} the voice id and whether it is the fallback voice.
     * @throws VoicePreviewException 422 when there is no voice, or only a silent-marker voice.
     */
    private function voiceFor(array $npc, array $connector): array
    {
        $resolved = (new TTSConnector())->resolveNpcVoiceForConnector($npc, $connector);
        $voice = trim((string) ($resolved['resolved_voice'] ?? ''));
        if ($voice === '' || synth_tts_is_temporary_silent_voice($voice)) {
            throw new VoicePreviewException(422, 'no_voice', 'This NPC has no saved voice to preview.');
        }
        return [$voice, !empty($resolved['used_fallback'])];
    }

    /**
     * Calls the configured TTS driver once and returns the path the driver wrote the audio to.
     *
     * @param array<string,mixed> $npc
     */
    private function speak(array $npc, string $voice, string $text): string
    {
        $name = (string) ($npc['npc_name'] ?? 'NPC');
        $GLOBALS['SYNTH_NAME'] = $name;
        $GLOBALS['PATCH_OVERRIDE_VOICE'] = synth_resolve_tts_voice_reference($voice, [
            'root' => rtrim($this->enginePath, DIRECTORY_SEPARATOR), 'actor_name' => $name, 'sync_sample' => false,
        ]);
        $GLOBALS['AVOID_TTS_CACHE'] = true;
        $GLOBALS['TTS_FFMPEG_FILTERS'] = [];
        $GLOBALS['PATCH_DONT_STORE_SPEECH_ON_DB'] = true;
        $GLOBALS['FEATURES']['MISC']['TTS_RANDOM_PITCH'] = false;
        $GLOBALS['TRACK'] = [];

        $directory = $this->enginePath . 'soundcache';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new VoicePreviewException(503, 'unavailable', 'The speech cache directory is unavailable.');
        }
        // The random seed names the one soundcache file this preview may create, read back and delete.
        $seed = 'voice-preview|' . bin2hex(random_bytes(16));
        $path = $directory . DIRECTORY_SEPARATOR . md5(trim($seed)) . '.wav';
        ob_start();
        try {
            callConfiguredTts($text, 'default', $seed);
        } finally {
            ob_end_clean();
        }
        return $path;
    }

    /**
     * Reads the generated audio back, refusing anything that is not a WAV of sane size.
     *
     * @throws VoicePreviewException 502 when the provider produced no usable audio.
     */
    private function readAudio(string $path): string
    {
        $size = is_file($path) ? filesize($path) : 0;
        $audio = $size > 44 && $size <= self::MAX_AUDIO_BYTES ? file_get_contents($path) : false;
        if (!is_string($audio) || !str_starts_with($audio, 'RIFF') || substr($audio, 8, 4) !== 'WAVE') {
            throw new VoicePreviewException(502, 'provider_failed', 'The TTS provider did not return audio. Check the connector in TTS Connectors.');
        }
        return $audio;
    }

    /**
     * Deletes the audio and the driver's request log (.txt, or .err on failure) named by the same hash.
     */
    private function removeFiles(string $audioPath): void
    {
        if ($audioPath === '') {
            return;
        }
        foreach ([$audioPath, substr($audioPath, 0, -4) . '.txt', substr($audioPath, 0, -4) . '.err'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Loads the legacy classes and helpers this manager calls; the entry point has already bootstrapped the runtime.
     */
    private function loadLegacyClasses(): void
    {
        $lib = $this->enginePath . 'lib' . DIRECTORY_SEPARATOR;
        foreach (['logger', 'model_dynmodel', 'data_functions', 'chat_helper_functions', 'voice_clone_resolver', 'synth_tts'] as $file) {
            require_once $lib . $file . '.php';
        }
        foreach (['tts_connector', 'npc_master', 'core_profiles'] as $file) {
            require_once $lib . 'core' . DIRECTORY_SEPARATOR . $file . '.class.php';
        }
    }
}
